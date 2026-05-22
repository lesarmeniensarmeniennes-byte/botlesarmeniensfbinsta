<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Centralized settings manager.
 * All plugin options are defined here with defaults and sanitizers.
 */
class ANA_Settings {

    /**
     * Default values for all options.
     */
    public static function defaults(): array {
        return [
            // AI provider
            'ana_api_provider'        => 'claude',
            'ana_api_key'             => '',

            // Writing
            'ana_site_name'           => get_bloginfo( 'name' ),
            'ana_target_language'     => 'français',
            'ana_writing_style'       => 'AFP (journalistique, factuel, sobre)',
            'ana_custom_prompt'       => '',   // overrides built-in prompt if non-empty
            'ana_categories'          => 'Politique,Economie,Societe,Culture,Sport,International,Science,Sante',
            'ana_min_words'           => 250,
            'ana_max_words'           => 500,

            // Relevance filter
            'ana_filter_prompt'       => "Ne retenir QUE les articles qui concernent directement l'Arménie, les Arméniens, le Haut-Karabagh, la diaspora arménienne ou les relations diplomatiques impliquant l'Arménie. Ignorer tout article qui ne mentionne pas l'Arménie ou les Arméniens. Score 0 pour tout article hors sujet.",

            // Branding / images
            'ana_logo_ai_id'          => 0,    // media library ID — round logo top-left
            'ana_logo_banner_id'      => 0,    // media library ID — banner bottom
            'ana_brand_color_bg'      => '#1a1a2e',
            'ana_brand_color_text'    => '#ffffff',
            'ana_brand_color_accent'  => '#e94560',

            // Publication
            'ana_wp_category'         => 0,
            'ana_post_status'         => 'draft',

            // Sources (JSON array)
            'ana_sources'             => [
                [ 'url' => 'https://www.rfi.fr/fr/rss',                      'name' => 'RFI',      'lang' => 'fr', 'type' => 'rss' ],
                [ 'url' => 'https://www.france24.com/fr/rss',                 'name' => 'France 24','lang' => 'fr', 'type' => 'rss' ],
                [ 'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml',     'name' => 'BBC World','lang' => 'en', 'type' => 'rss' ],
            ],

            // Automation
            'ana_cron_hour'           => 4,
            'ana_cron_articles_count' => 10,
            'ana_rest_key'            => wp_generate_password( 32, false ),

            // Internal state
            'ana_queue'               => [],
            'ana_queue_status'        => 'idle',
            'ana_queue_log'           => [],
            'ana_history'             => [],
            'ana_total_generated'     => 0,
        ];
    }

    /**
     * Fields that can be saved via the settings AJAX handler.
     * Maps option key → sanitizer callable.
     */
    public static function saveable_fields(): array {
        return [
            'ana_api_provider'        => 'sanitize_text_field',
            'ana_api_key'             => 'sanitize_text_field',
            'ana_site_name'           => 'sanitize_text_field',
            'ana_target_language'     => 'sanitize_text_field',
            'ana_writing_style'       => 'sanitize_text_field',
            'ana_custom_prompt'       => 'sanitize_textarea_field',
            'ana_categories'          => 'sanitize_text_field',
            'ana_min_words'           => 'intval',
            'ana_max_words'           => 'intval',
            'ana_filter_prompt'       => 'sanitize_textarea_field',
            'ana_logo_ai_id'          => 'intval',
            'ana_logo_banner_id'      => 'intval',
            'ana_brand_color_bg'      => 'sanitize_hex_color',
            'ana_brand_color_text'    => 'sanitize_hex_color',
            'ana_brand_color_accent'  => 'sanitize_hex_color',
            'ana_wp_category'         => 'intval',
            'ana_post_status'         => 'sanitize_text_field',
            'ana_cron_hour'           => 'intval',
            'ana_cron_articles_count' => 'intval',
            'ana_rest_key'            => 'sanitize_text_field',
        ];
    }

    /**
     * Helper: get a single option with its default fallback.
     */
    public static function get( string $key ) {
        $defaults = self::defaults();
        return get_option( $key, $defaults[ $key ] ?? null );
    }

    /**
     * Return the list of categories as an array.
     */
    public static function categories(): array {
        $raw = (string) get_option( 'ana_categories', 'Politique,Economie,Societe,Culture,Sport,International' );
        return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    }
}
