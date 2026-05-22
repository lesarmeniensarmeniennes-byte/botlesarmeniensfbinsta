<?php
/**
 * Plugin Name:  AI News Agent
 * Description:  Generates articles from RSS feeds using AI (OpenAI / Claude). Fully configurable: sources, branding, writing style, language, automation. Zero WP-Cron. Anti-timeout. Anti-duplicates.
 * Version:      1.0.0
 * Author:       Your Name
 * License:      GPL-2.0+
 * Text Domain:  ai-news-agent
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ANA_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ANA_URL',     plugin_dir_url( __FILE__ ) );
define( 'ANA_VERSION', '1.0.0' );
define( 'ANA_PREFIX',  'ana_' );

require_once ANA_DIR . 'includes/class-settings.php';
require_once ANA_DIR . 'includes/class-rss-fetcher.php';
require_once ANA_DIR . 'includes/class-relevance-filter.php';
require_once ANA_DIR . 'includes/class-ai-writer.php';
require_once ANA_DIR . 'includes/class-image-generator.php';
require_once ANA_DIR . 'includes/class-article-publisher.php';
require_once ANA_DIR . 'includes/class-queue-manager.php';
require_once ANA_DIR . 'admin/admin-page.php';

// ── Activation ──────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'ana_activate' );
function ana_activate() {
    $defaults = ANA_Settings::defaults();
    foreach ( $defaults as $key => $value ) {
        if ( get_option( $key ) === false ) {
            update_option( $key, $value );
        }
    }
    ana_maybe_schedule_cron();
}

// ── Deactivation ─────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'ana_deactivate' );
function ana_deactivate() {
    $ts = wp_next_scheduled( 'ana_daily_auto_run' );
    if ( $ts ) wp_unschedule_event( $ts, 'ana_daily_auto_run' );
    $ts = wp_next_scheduled( 'ana_midday_auto_run' );
    if ( $ts ) wp_unschedule_event( $ts, 'ana_midday_auto_run' );
}

// ── Cron schedule ────────────────────────────────────────────────────────────
add_action( 'init', 'ana_maybe_schedule_cron' );
function ana_maybe_schedule_cron() {
    // Cron matin
    if ( ! wp_next_scheduled( 'ana_daily_auto_run' ) ) {
        $hour = (int) get_option( 'ana_cron_hour', 6 );
        $ts   = strtotime( 'today ' . sprintf( '%02d:00:00', $hour ) . ' UTC' );
        if ( $ts <= time() ) $ts = strtotime( 'tomorrow ' . sprintf( '%02d:00:00', $hour ) . ' UTC' );
        wp_schedule_event( $ts, 'daily', 'ana_daily_auto_run' );
    }
    // Cron midi — 4 articles
    if ( ! wp_next_scheduled( 'ana_midday_auto_run' ) ) {
        $ts = strtotime( 'today 12:00:00 UTC' );
        if ( $ts <= time() ) $ts = strtotime( 'tomorrow 12:00:00 UTC' );
        wp_schedule_event( $ts, 'daily', 'ana_midday_auto_run' );
    }
}

add_action( 'ana_daily_auto_run',   'ana_cron_generate_drafts' );
add_action( 'ana_midday_auto_run', 'ana_cron_generate_midday' );

function ana_cron_generate_midday(): void {
    set_transient( 'ana_cron_running', 1, 300 );
    ana_prepare_queue( 4 );
    $done = 0;
    while ( $done < 4 ) {
        $queue = (array) get_option( 'ana_queue', [] );
        if ( empty( $queue ) ) break;
        ana_process_next();
        $done++;
        if ( $done < 4 ) usleep( 500000 );
    }
    delete_transient( 'ana_cron_running' );
}
function ana_cron_generate_drafts() {
    set_transient( 'ana_cron_running', 1, 300 );
    $count = (int) get_option( 'ana_cron_articles_count', 10 );
    ana_prepare_queue( $count );
    $done = 0;
    while ( $done < $count ) {
        $queue = (array) get_option( 'ana_queue', [] );
        if ( empty( $queue ) ) break;
        ana_process_next();
        $done++;
        if ( $done < $count ) usleep( 500000 );
    }
    delete_transient( 'ana_cron_running' );
}

// ── REST API endpoint (cPanel cron) ──────────────────────────────────────────
add_action( 'rest_api_init', function () {
    if ( ! defined( 'ANA_ENABLE_REST' ) || ! ANA_ENABLE_REST ) return;
    register_rest_route( 'ana/v1', '/run', [
        'methods'             => 'GET',
        'callback'            => 'ana_rest_run',
        'permission_callback' => '__return_true',
    ] );
} );

function ana_rest_run( WP_REST_Request $request ) {
    $key = sanitize_text_field( $request->get_param( 'key' ) );
    if ( $key !== get_option( 'ana_rest_key', '' ) ) {
        return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 403 );
    }
    $count = (int) get_option( 'ana_cron_articles_count', 10 );
    ana_prepare_queue( $count );
    $done = 0;
    while ( $done < $count ) {
        $queue = (array) get_option( 'ana_queue', [] );
        if ( empty( $queue ) ) break;
        ana_process_next();
        $done++;
    }
    return new WP_REST_Response( [ 'generated' => $done ], 200 );
}

// ── Core pipeline ─────────────────────────────────────────────────────────────

/**
 * Phase 1: Fetch RSS + AI filter → fill queue. Returns immediately.
 */
function ana_prepare_queue( int $count ): array {
    $count = max( 1, min( 50, $count ) );
    update_option( 'ana_queue_status', 'running' );
    update_option( 'ana_queue', [] );

    $log   = [];
    $log[] = date( 'd/m/Y H:i:s' ) . ' — Préparation de ' . $count . ' article(s)';

    $fetcher      = new ANA_RSS_Fetcher();
    $per_source   = max( 2, min( 5, $count ) );
    $all_items    = $fetcher->fetch_all( $per_source );
    $log[]        = '📡 ' . count( $all_items ) . ' articles récupérés (' . count( $fetcher->get_sources() ) . ' sources testées)';

    // AI relevance filter
    $filter       = new ANA_Relevance_Filter();
    $filtered     = $filter->filter( $all_items, $count );
    $log[]        = '🎯 ' . count( $filtered ) . ' retenus après filtre éditorial IA';

    // Anti-duplicate check
    $publisher    = new ANA_Article_Publisher();
    $queue        = [];
    $skipped      = 0;
    foreach ( $filtered as $item ) {
        if ( $publisher->exists( (string)( $item['title'] ?? '' ) ) ) {
            $log[]   = '⏭️ Doublon ignoré : ' . mb_substr( (string)( $item['title'] ?? '' ), 0, 60 );
            $skipped++;
            continue;
        }
        if ( ana_queue_contains_similar( $queue, $item ) ) {
            $log[]   = '⏭️ Similaire ignoré : ' . mb_substr( (string)( $item['title'] ?? '' ), 0, 60 );
            $skipped++;
            continue;
        }
        $queue[] = $item;
        if ( count( $queue ) >= $count ) break;
    }

    // Complement if not enough unique articles
    if ( count( $queue ) < $count ) {
        $extra_needed = $count - count( $queue );
        $extra_items  = $fetcher->fetch_all( $extra_needed + 2 );
        $added        = 0;
        foreach ( $extra_items as $item ) {
            if ( $added >= $extra_needed ) break;
            if ( $publisher->exists( (string)( $item['title'] ?? '' ) ) ) continue;
            if ( ana_queue_contains_similar( $queue, $item ) ) continue;
            $queue[] = $item;
            $added++;
        }
        if ( $added > 0 ) $log[] = '➕ Complément automatique : ' . $added . ' article(s) ajouté(s) pour approcher le quota';
    }

    $log[] = '🔍 ' . count( $queue ) . ' nouveaux articles (doublons exclus)';

    update_option( 'ana_queue', $queue );

    // Save to history
    $history   = (array) get_option( 'ana_history', [] );
    array_unshift( $history, $log );
    $history   = array_slice( $history, 0, 7 );
    update_option( 'ana_history', $history );

    return $queue;
}

/**
 * Phase 2: Process next item in queue → write + image + publish as draft.
 */
function ana_process_next(): array {
    $queue = (array) get_option( 'ana_queue', [] );
    if ( empty( $queue ) ) {
        return [ 'status' => 'empty', 'message' => 'File vide' ];
    }

    $item  = array_shift( $queue );
    update_option( 'ana_queue', $queue );

    $writer    = new ANA_AI_Writer();
    $imggen    = new ANA_Image_Generator();
    $publisher = new ANA_Article_Publisher();

    $article = $writer->write( $item );
    if ( ! $article ) {
        return [
            'status'    => 'next',
            'message'   => '⚠️ Erreur rédaction : ' . mb_substr( (string)( $item['title'] ?? '' ), 0, 50 ) . '…',
            'remaining' => count( $queue ),
        ];
    }

    $article['source_title'] = $item['title'] ?? '';
    $article['source_photo'] = $item['photo_url'] ?? '';

    $image_path = $imggen->generate( $article, $item['photo_url'] ?? '' );

    if ( empty( $image_path ) || ! file_exists( $image_path ) ) {
        $image_path = '';
        $log        = (array) get_option( 'ana_queue_log', [] );
        $log[]      = '🖼️ Visuel indisponible, publication sans image : ' . mb_substr( (string)( $article['title_fr'] ?? ( $item['title'] ?? '' ) ), 0, 80 );
        update_option( 'ana_queue_log', $log );
    }

    $post_id = $publisher->publish( $article, $image_path );

    $log     = (array) get_option( 'ana_queue_log', [] );
    $log[]   = '✓ Brouillon #' . $post_id . ' : « ' . mb_substr( (string)( $article['title_fr'] ?? '' ), 0, 80 ) . ' »';
    update_option( 'ana_queue_log', $log );

    // Update history with created drafts
    $history = (array) get_option( 'ana_history', [] );
    if ( ! empty( $history ) ) {
        $history[0][] = '✓ Brouillon #' . $post_id . ' : « ' . mb_substr( (string)( $article['title_fr'] ?? '' ), 0, 80 ) . ' »';
        $ts           = date( 'd/m/Y H:i' );
        $count_line   = $ts . ' — ' . ( isset( $history[0]['_count'] ) ? $history[0]['_count'] + 1 : 1 ) . ' article(s) créé(s)';
        $history[0]['_count'] = ( isset( $history[0]['_count'] ) ? $history[0]['_count'] + 1 : 1 );
        update_option( 'ana_history', $history );
    }

    gc_collect_cycles();

    return [
        'status'    => count( $queue ) > 0 ? 'next' : 'done',
        'post_id'   => $post_id,
        'title'     => $article['title_fr'] ?? '',
        'remaining' => count( $queue ),
        'message'   => '✓ Brouillon #' . $post_id . ' créé',
    ];
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────

add_action( 'wp_ajax_ana_prepare',      'ana_ajax_prepare' );
add_action( 'wp_ajax_ana_process_next', 'ana_ajax_process_next' );
add_action( 'wp_ajax_ana_save_settings','ana_ajax_save_settings' );
add_action( 'wp_ajax_ana_test_api',     'ana_ajax_test_api' );
add_action( 'wp_ajax_ana_test_sources', 'ana_ajax_test_sources' );
add_action( 'wp_ajax_ana_reset_queue',  'ana_ajax_reset_queue' );
add_action( 'wp_ajax_ana_save_sources', 'ana_ajax_save_sources' );

function ana_ajax_prepare() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
    $count = max( 1, min( 50, (int) ( $_POST['count'] ?? 6 ) ) );
    $queue = ana_prepare_queue( $count );
    wp_send_json_success( [ 'count' => count( $queue ) ] );
}

function ana_ajax_process_next() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
    $result = ana_process_next();
    wp_send_json_success( $result );
}

function ana_ajax_save_settings() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );

    $fields = ANA_Settings::saveable_fields();
    foreach ( $fields as $key => $sanitize ) {
        if ( isset( $_POST[ $key ] ) ) {
            $value = call_user_func( $sanitize, wp_unslash( $_POST[ $key ] ) );
            update_option( $key, $value );
        }
    }
    wp_send_json_success( [ 'msg' => '✓ Réglages sauvegardés' ] );
}

function ana_ajax_test_api() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
    $writer = new ANA_AI_Writer();
    $ok     = $writer->test_connection();
    wp_send_json_success( [ 'ok' => $ok ] );
}

function ana_ajax_test_sources() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
    $fetcher = new ANA_RSS_Fetcher();
    $results = $fetcher->test_all_sources();
    $items   = $fetcher->fetch_all( 2 );
    $groups  = [];
    foreach ( $results as $row ) {
        $groups[] = [
            'name'  => $row['name'],
            'type'  => $row['type'],
            'ok'    => ! empty( $row['ok'] ),
            'found' => (int) ( $row['found'] ?? 0 ),
        ];
    }
    wp_send_json_success( [ 'count' => count( $items ), 'items' => array_slice( $items, 0, 20 ), 'sources' => $groups ] );
}

function ana_ajax_reset_queue() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
    update_option( 'ana_queue', [] );
    update_option( 'ana_queue_status', 'idle' );
    update_option( 'ana_queue_log', [] );
    wp_send_json_success( [ 'msg' => 'File réinitialisée' ] );
}

function ana_ajax_save_sources() {
    check_ajax_referer( 'ana_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Accès refusé' );
    $raw     = wp_unslash( $_POST['sources'] ?? '[]' );
    $sources = json_decode( $raw, true );
    if ( ! is_array( $sources ) ) $sources = [];
    // Sanitize each source
    $clean = [];
    foreach ( $sources as $s ) {
        $url  = esc_url_raw( trim( (string) ( $s['url'] ?? '' ) ) );
        $name = sanitize_text_field( (string) ( $s['name'] ?? '' ) );
        $lang = sanitize_text_field( (string) ( $s['lang'] ?? 'en' ) );
        $type = in_array( $s['type'] ?? 'rss', [ 'rss', 'scrape' ] ) ? $s['type'] : 'rss';
        if ( $url && $name ) {
            $entry = [ 'url' => $url, 'name' => $name, 'lang' => $lang, 'type' => $type ];
            if ( $type === 'scrape' ) {
                $entry['base']    = esc_url_raw( trim( (string) ( $s['base'] ?? '' ) ) );
                $entry['pattern'] = sanitize_text_field( (string) ( $s['pattern'] ?? '' ) );
            }
            $clean[] = $entry;
        }
    }
    update_option( 'ana_sources', $clean );
    wp_send_json_success( [ 'msg' => '✓ Sources sauvegardées (' . count( $clean ) . ')', 'count' => count( $clean ) ] );
}

// ── Schema.org NewsArticle ────────────────────────────────────────────────────
add_action( 'wp_head', 'ana_output_news_schema' );
function ana_output_news_schema(): void {
    if ( ! is_single() ) return;

    $post = get_queried_object();
    if ( ! ( $post instanceof WP_Post ) ) return;
    if ( get_post_type( $post ) !== 'post' ) return;

    // Only for articles generated by this plugin
    $source_url = get_post_meta( $post->ID, '_ana_source_url', true );
    if ( empty( $source_url ) ) return;

    $site_name  = get_bloginfo( 'name' );
    $site_url   = get_home_url();
    $logo_url   = '';
    $logo_id    = (int) get_option( 'ana_logo_ai_id', 0 );
    if ( $logo_id > 0 ) {
        $logo_url = (string) wp_get_attachment_url( $logo_id );
    }

    $image_url = '';
    $thumb_id  = get_post_thumbnail_id( $post->ID );
    if ( $thumb_id ) {
        $img       = wp_get_attachment_image_src( $thumb_id, 'full' );
        $image_url = $img ? (string) $img[0] : '';
    }

    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => get_the_title( $post ),
        'description'      => get_the_excerpt( $post ),
        'url'              => get_permalink( $post ),
        'datePublished'    => get_the_date( 'c', $post ),
        'dateModified'     => get_the_modified_date( 'c', $post ),
        'inLanguage'       => get_bloginfo( 'language' ),
        'author'           => [
            '@type' => 'Organization',
            'name'  => $site_name,
            'url'   => $site_url,
        ],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => $site_name,
            'url'   => $site_url,
            'logo'  => $logo_url ? [ '@type' => 'ImageObject', 'url' => $logo_url ] : new stdClass(),
        ],
        'isAccessibleForFree' => true,
    ];

    if ( $image_url ) {
        $schema['image'] = [ '@type' => 'ImageObject', 'url' => $image_url ];
    }

    echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function ana_norm_title( string $t ): string {
    $t = mb_strtolower( $t );
    $t = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $t );
    return trim( (string) preg_replace( '/\s+/', ' ', $t ) );
}

function ana_queue_contains_similar( array $existing, array $candidate ): bool {
    $c_link = trim( (string) ( $candidate['link'] ?? '' ) );
    $c_norm = ana_norm_title( (string) ( $candidate['title'] ?? '' ) );
    foreach ( $existing as $item ) {
        if ( $c_link !== '' && ! empty( $item['link'] ) && $c_link === trim( (string) $item['link'] ) ) return true;
        $norm = ana_norm_title( (string) ( $item['title'] ?? '' ) );
        if ( $c_norm === '' || $norm === '' ) continue;
        if ( $c_norm === $norm ) return true;
        similar_text( $c_norm, $norm, $pct );
        if ( $pct >= 82 ) return true;
    }
    return false;
}
