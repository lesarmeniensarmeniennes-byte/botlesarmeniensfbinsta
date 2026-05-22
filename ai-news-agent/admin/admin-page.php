<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register menu ─────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'ana_add_menu' );
function ana_add_menu() {
    add_menu_page(
        __( 'AI News Agent', 'ai-news-agent' ),
        __( 'AI News Agent', 'ai-news-agent' ),
        'manage_options',
        'ai-news-agent',
        'ana_render_page',
        'dashicons-rss',
        30
    );
}

// ── Enqueue scripts & styles ──────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'ana_enqueue_assets' );
function ana_enqueue_assets( string $hook ) {
    if ( $hook !== 'toplevel_page_ai-news-agent' ) return;

    wp_enqueue_media();
    wp_enqueue_style(  'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
    wp_enqueue_script(
        'ana-admin',
        ANA_URL . 'admin/assets/admin.js',
        [ 'jquery', 'wp-color-picker' ],
        ANA_VERSION,
        true
    );
    wp_localize_script( 'ana-admin', 'ANA', [
        'nonce'    => wp_create_nonce( 'ana_nonce' ),
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'rest_url' => rest_url( 'ana/v1/run' ),
        'rest_key' => get_option( 'ana_rest_key', '' ),
        'site_url' => home_url(),
    ] );
    wp_add_inline_style( 'wp-admin', ana_inline_css() );
}

// ── Main page ─────────────────────────────────────────────────────────────────
function ana_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $total    = (int)   get_option( 'ana_total_generated', 0 );
    $queue    = (array) get_option( 'ana_queue', [] );
    $sources  = (array) get_option( 'ana_sources', [] );
    $history  = (array) get_option( 'ana_history', [] );
    $drafts   = get_posts( [ 'post_type' => 'post', 'post_status' => 'draft', 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC' ] );
    ?>
    <div class="wrap ana-wrap">
        <h1>🤖 <?php esc_html_e( 'AI News Agent', 'ai-news-agent' ); ?> <span class="ana-version">v<?php echo ANA_VERSION; ?></span></h1>

        <!-- Stats bar -->
        <div class="ana-stats">
            <div class="ana-stat"><strong><?php echo esc_html( $total ); ?></strong><span><?php esc_html_e( 'Articles générés', 'ai-news-agent' ); ?></span></div>
            <div class="ana-stat"><strong><?php echo esc_html( count( $queue ) ); ?></strong><span><?php esc_html_e( 'En file d\'attente', 'ai-news-agent' ); ?></span></div>
            <div class="ana-stat"><strong><?php echo esc_html( count( $sources ) ); ?></strong><span><?php esc_html_e( 'Sources RSS', 'ai-news-agent' ); ?></span></div>
        </div>

        <!-- Tabs -->
        <nav class="ana-tabs">
            <a href="#" class="ana-tab active" data-tab="generate"><?php esc_html_e( '▶ Générer', 'ai-news-agent' ); ?></a>
            <a href="#" class="ana-tab" data-tab="sources"><?php esc_html_e( '📡 Sources RSS', 'ai-news-agent' ); ?></a>
            <a href="#" class="ana-tab" data-tab="ai"><?php esc_html_e( '🤖 IA & Rédaction', 'ai-news-agent' ); ?></a>
            <a href="#" class="ana-tab" data-tab="branding"><?php esc_html_e( '🎨 Branding', 'ai-news-agent' ); ?></a>
            <a href="#" class="ana-tab" data-tab="automation"><?php esc_html_e( '⚙ Automatisation', 'ai-news-agent' ); ?></a>
        </nav>

        <!-- TAB: Generate -->
        <div id="ana-tab-generate" class="ana-tab-content active">
            <div class="ana-card">
                <h2><?php esc_html_e( '▶ Générer des articles', 'ai-news-agent' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Traitement article par article — aucun risque de timeout.', 'ai-news-agent' ); ?></p>
                <div class="ana-count-row">
                    <button id="ana-count-minus" class="button">−</button>
                    <input id="ana-count" type="number" value="6" min="1" max="50">
                    <button id="ana-count-plus" class="button">+</button>
                    <span class="description">/ 50 max</span>
                </div>
                <div id="ana-progress" style="display:none;">
                    <div class="ana-progress-bar"><div id="ana-progress-fill"></div></div>
                    <p id="ana-phase-1">⏳ <?php esc_html_e( 'Phase 1 : Lecture RSS + Filtre IA…', 'ai-news-agent' ); ?></p>
                    <p id="ana-phase-2" style="display:none;">✍️ <?php esc_html_e( 'Phase 2 : Rédaction article par article', 'ai-news-agent' ); ?></p>
                </div>
                <p id="ana-status-msg" class="ana-status"></p>
                <div class="ana-btn-row">
                    <button id="ana-btn-generate" class="button button-primary button-large">
                        ▶ <?php esc_html_e( 'Générer les articles', 'ai-news-agent' ); ?>
                    </button>
                    <button id="ana-btn-reset" class="button">↺ <?php esc_html_e( 'Réinitialiser', 'ai-news-agent' ); ?></button>
                </div>
            </div>

            <!-- Drafts list -->
            <?php if ( $drafts ) : ?>
            <div class="ana-card">
                <h2><?php esc_html_e( '📝 Brouillons récents', 'ai-news-agent' ); ?> — <a href="<?php echo esc_url( admin_url( 'edit.php?post_status=draft&post_type=post' ) ); ?>"><?php esc_html_e( 'Voir tous →', 'ai-news-agent' ); ?></a></h2>
                <table class="widefat fixed striped ana-drafts-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Titre', 'ai-news-agent' ); ?></th>
                        <th><?php esc_html_e( 'Catégorie', 'ai-news-agent' ); ?></th>
                        <th><?php esc_html_e( 'Score', 'ai-news-agent' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'ai-news-agent' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'ai-news-agent' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'ai-news-agent' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $drafts as $post ) :
                        $score  = get_post_meta( $post->ID, '_ana_score', true );
                        $source = get_post_meta( $post->ID, '_ana_source', true );
                        $words  = get_post_meta( $post->ID, '_ana_word_count', true );
                        $cats   = get_the_category( $post->ID );
                        $cat    = $cats ? $cats[0]->name : '—';
                        $pub_url = wp_nonce_url( get_edit_post_link( $post->ID ) . '&action=publish', 'publish-post_' . $post->ID );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( mb_substr( $post->post_title, 0, 60 ) ); ?>…</a></td>
                        <td><?php echo esc_html( $cat ); ?></td>
                        <td><?php echo $score ? esc_html( $score . '/10' ) : '—'; ?> <?php echo $words ? '<small>(' . esc_html( $words ) . ' mots)</small>' : ''; ?></td>
                        <td><?php echo esc_html( $source ?: '—' ); ?></td>
                        <td><?php echo esc_html( get_the_date( 'd/m H:i', $post ) ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="button button-small"><?php esc_html_e( 'Éditer', 'ai-news-agent' ); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- History -->
            <?php if ( $history ) : ?>
            <div class="ana-card">
                <h2><?php esc_html_e( '📅 Historique', 'ai-news-agent' ); ?></h2>
                <?php foreach ( array_slice( $history, 0, 7 ) as $run ) :
                    $run = (array) $run;
                    $created = array_filter( $run, fn( $l ) => strpos( (string)$l, 'article(s) créé(s)' ) !== false );
                ?>
                <details class="ana-history-item">
                    <summary><?php echo esc_html( implode( ' ', $created ) ?: ( $run[0] ?? '' ) ); ?></summary>
                    <ul><?php foreach ( $run as $line ) : if ( is_string( $line ) ) : ?>
                        <li><?php echo esc_html( $line ); ?></li>
                    <?php endif; endforeach; ?></ul>
                </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- TAB: Sources RSS -->
        <div id="ana-tab-sources" class="ana-tab-content">
            <div class="ana-card">
                <h2><?php esc_html_e( '📡 Sources RSS', 'ai-news-agent' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Ajoutez vos flux RSS ou pages à scraper. Testez-les avant de sauvegarder.', 'ai-news-agent' ); ?></p>

                <div id="ana-sources-list">
                <?php foreach ( $sources as $i => $src ) : ?>
                    <?php ana_render_source_row( $i, $src ); ?>
                <?php endforeach; ?>
                </div>

                <div class="ana-btn-row" style="margin-top:16px;">
                    <button id="ana-add-source" class="button"><?php esc_html_e( '+ Ajouter une source', 'ai-news-agent' ); ?></button>
                    <button id="ana-save-sources" class="button button-primary"><?php esc_html_e( '💾 Sauvegarder les sources', 'ai-news-agent' ); ?></button>
                    <button id="ana-test-sources" class="button"><?php esc_html_e( '🔍 Tester tous les flux', 'ai-news-agent' ); ?></button>
                </div>
                <div id="ana-sources-result" class="ana-result" style="display:none;"></div>
            </div>
        </div>

        <!-- TAB: AI & Writing -->
        <div id="ana-tab-ai" class="ana-tab-content">
            <div class="ana-card">
                <h2><?php esc_html_e( '🤖 IA & Rédaction', 'ai-news-agent' ); ?></h2>
                <form id="ana-form-ai">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Fournisseur IA', 'ai-news-agent' ); ?></th>
                            <td>
                                <select name="ana_api_provider" id="ana_api_provider">
                                    <option value="claude" <?php selected( get_option( 'ana_api_provider', 'claude' ), 'claude' ); ?>>Claude Sonnet (Anthropic)</option>
                                    <option value="openai" <?php selected( get_option( 'ana_api_provider', 'claude' ), 'openai' ); ?>>GPT-4o (OpenAI)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Clé API', 'ai-news-agent' ); ?></th>
                            <td>
                                <input type="password" name="ana_api_key" value="<?php echo esc_attr( (string) get_option( 'ana_api_key', '' ) ); ?>" class="regular-text">
                                <button type="button" id="ana-test-api" class="button"><?php esc_html_e( 'Tester', 'ai-news-agent' ); ?></button>
                                <span id="ana-api-status"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Nom du site', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_site_name" value="<?php echo esc_attr( (string) get_option( 'ana_site_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Utilisé dans le prompt IA. Ex : "MonSite.fr"', 'ai-news-agent' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Langue cible', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_target_language" value="<?php echo esc_attr( (string) get_option( 'ana_target_language', 'français' ) ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Ex : français, anglais, espagnol, arabe', 'ai-news-agent' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Style de rédaction', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_writing_style" value="<?php echo esc_attr( (string) get_option( 'ana_writing_style', 'AFP (journalistique, factuel, sobre)' ) ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Ex : AFP, blog décontracté, académique, storytelling', 'ai-news-agent' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Catégories', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_categories" value="<?php echo esc_attr( (string) get_option( 'ana_categories', 'Politique,Economie,Societe,Culture,Sport,International' ) ); ?>" class="large-text">
                            <p class="description"><?php esc_html_e( 'Séparées par des virgules. Ex : Politique,Economie,Culture,Sport', 'ai-news-agent' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Longueur article', 'ai-news-agent' ); ?></th>
                            <td>
                                <label><?php esc_html_e( 'Min :', 'ai-news-agent' ); ?> <input type="number" name="ana_min_words" value="<?php echo esc_attr( (string) get_option( 'ana_min_words', 250 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'mots', 'ai-news-agent' ); ?></label>
                                &nbsp;
                                <label><?php esc_html_e( 'Max :', 'ai-news-agent' ); ?> <input type="number" name="ana_max_words" value="<?php echo esc_attr( (string) get_option( 'ana_max_words', 500 ) ); ?>" style="width:80px;"> <?php esc_html_e( 'mots', 'ai-news-agent' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Prompt personnalisé', 'ai-news-agent' ); ?><br><small><?php esc_html_e( '(optionnel)', 'ai-news-agent' ); ?></small></th>
                            <td>
                                <textarea name="ana_custom_prompt" rows="8" class="large-text"><?php echo esc_textarea( (string) get_option( 'ana_custom_prompt', '' ) ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Remplace le prompt système par défaut. Laissez vide pour utiliser le prompt automatique basé sur les paramètres ci-dessus.', 'ai-news-agent' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Critères de filtre IA', 'ai-news-agent' ); ?><br><small><?php esc_html_e( '(optionnel)', 'ai-news-agent' ); ?></small></th>
                            <td>
                                <textarea name="ana_filter_prompt" rows="4" class="large-text"><?php echo esc_textarea( (string) get_option( 'ana_filter_prompt', '' ) ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Instructions pour le filtre éditorial IA. Ex : "Sélectionne uniquement les articles sur l\'économie européenne et la tech." Laissez vide pour utiliser le filtre automatique.', 'ai-news-agent' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Catégorie WordPress', 'ai-news-agent' ); ?></th>
                            <td>
                                <?php wp_dropdown_categories( [
                                    'show_option_none' => '— ' . __( 'Aucune', 'ai-news-agent' ) . ' —',
                                    'name'             => 'ana_wp_category',
                                    'selected'         => get_option( 'ana_wp_category', 0 ),
                                    'hierarchical'     => true,
                                ] ); ?>
                            </td>
                        </tr>
                    </table>
                    <p><button type="button" id="ana-save-ai" class="button button-primary button-large">💾 <?php esc_html_e( 'Sauvegarder', 'ai-news-agent' ); ?></button>
                    <span id="ana-ai-save-status" class="ana-status"></span></p>
                </form>
            </div>
        </div>

        <!-- TAB: Branding -->
        <div id="ana-tab-branding" class="ana-tab-content">
            <div class="ana-card">
                <h2><?php esc_html_e( '🎨 Branding & Visuels', 'ai-news-agent' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Personnalisez l\'apparence des images générées automatiquement.', 'ai-news-agent' ); ?></p>
                <form id="ana-form-branding">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Logo principal', 'ai-news-agent' ); ?><br><small><?php esc_html_e( '(rond, haut gauche)', 'ai-news-agent' ); ?></small></th>
                            <td><?php ana_media_uploader_field( 'ana_logo_ai_id', get_option( 'ana_logo_ai_id', 0 ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Logo bannière', 'ai-news-agent' ); ?><br><small><?php esc_html_e( '(bas de l\'image)', 'ai-news-agent' ); ?></small></th>
                            <td><?php ana_media_uploader_field( 'ana_logo_banner_id', get_option( 'ana_logo_banner_id', 0 ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Couleur de fond', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_brand_color_bg" value="<?php echo esc_attr( (string) get_option( 'ana_brand_color_bg', '#1a1a2e' ) ); ?>" class="ana-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Couleur du texte', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_brand_color_text" value="<?php echo esc_attr( (string) get_option( 'ana_brand_color_text', '#ffffff' ) ); ?>" class="ana-color-picker"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Couleur d\'accentuation', 'ai-news-agent' ); ?></th>
                            <td><input type="text" name="ana_brand_color_accent" value="<?php echo esc_attr( (string) get_option( 'ana_brand_color_accent', '#e94560' ) ); ?>" class="ana-color-picker"></td>
                        </tr>
                    </table>
                    <p><button type="button" id="ana-save-branding" class="button button-primary button-large">💾 <?php esc_html_e( 'Sauvegarder', 'ai-news-agent' ); ?></button>
                    <span id="ana-branding-save-status" class="ana-status"></span></p>
                </form>
            </div>
        </div>

        <!-- TAB: Automation -->
        <div id="ana-tab-automation" class="ana-tab-content">
            <div class="ana-card">
                <h2><?php esc_html_e( '⚙ Automatisation', 'ai-news-agent' ); ?></h2>
                <form id="ana-form-automation">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Heure de génération automatique', 'ai-news-agent' ); ?></th>
                            <td>
                                <select name="ana_cron_hour">
                                    <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                                    <option value="<?php echo $h; ?>" <?php selected( get_option( 'ana_cron_hour', 4 ), $h ); ?>><?php printf( '%02d:00 UTC', $h ); ?></option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Heure en UTC (WP-Cron). Pour ignorer WP-Cron, utilisez l\'endpoint REST ci-dessous.', 'ai-news-agent' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Articles par génération automatique', 'ai-news-agent' ); ?></th>
                            <td><input type="number" name="ana_cron_articles_count" value="<?php echo esc_attr( (string) get_option( 'ana_cron_articles_count', 10 ) ); ?>" min="1" max="50" style="width:80px;"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Clé REST sécurisée', 'ai-news-agent' ); ?></th>
                            <td>
                                <input type="text" name="ana_rest_key" value="<?php echo esc_attr( (string) get_option( 'ana_rest_key', '' ) ); ?>" class="regular-text" id="ana-rest-key">
                                <button type="button" id="ana-regen-key" class="button"><?php esc_html_e( '↺ Régénérer', 'ai-news-agent' ); ?></button>
                                <p class="description"><?php esc_html_e( 'Clé secrète pour l\'endpoint REST. Ne la partagez pas.', 'ai-news-agent' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="button" id="ana-save-automation" class="button button-primary button-large">💾 <?php esc_html_e( 'Sauvegarder', 'ai-news-agent' ); ?></button>
                    <span id="ana-automation-save-status" class="ana-status"></span></p>
                </form>
                <hr>
                <h3><?php esc_html_e( '🕐 Endpoint REST (cPanel cron)', 'ai-news-agent' ); ?></h3>
                <p><?php esc_html_e( 'Pour une génération automatique sans WP-Cron, ajoutez cette commande dans votre cPanel cron :', 'ai-news-agent' ); ?></p>
                <pre id="ana-cron-cmd" style="background:#1e1e1e;color:#9cdcfe;padding:12px;border-radius:4px;overflow-x:auto;font-size:12px;">wget -q -O /dev/null "<?php echo esc_url( rest_url( 'ana/v1/run' ) ); ?>?key=<?php echo esc_html( get_option( 'ana_rest_key', '' ) ); ?>"</pre>
                <p class="description">
                    <?php esc_html_e( 'Activez d\'abord dans wp-config.php :', 'ai-news-agent' ); ?>
                    <code>define('ANA_ENABLE_REST', true);</code>
                </p>
            </div>
        </div>
    </div>
    <?php
}

// ── Helper: media uploader field ──────────────────────────────────────────────
function ana_media_uploader_field( string $name, int $current_id ) {
    $preview = $current_id ? wp_get_attachment_image_url( $current_id, 'thumbnail' ) : '';
    ?>
    <div class="ana-media-field" data-field="<?php echo esc_attr( $name ); ?>">
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" class="ana-media-id" value="<?php echo esc_attr( (string) $current_id ); ?>">
        <div class="ana-media-preview" style="<?php echo $preview ? '' : 'display:none;'; ?>">
            <img src="<?php echo esc_url( $preview ); ?>" style="max-width:150px;max-height:80px;border-radius:4px;">
        </div>
        <button type="button" class="button ana-media-choose">📎 <?php esc_html_e( 'Choisir', 'ai-news-agent' ); ?></button>
        <button type="button" class="button ana-media-remove" style="<?php echo $current_id ? '' : 'display:none;'; ?>">✕</button>
    </div>
    <?php
}

// ── Helper: single source row ─────────────────────────────────────────────────
function ana_render_source_row( int $i, array $src ) {
    $type = $src['type'] ?? 'rss';
    ?>
    <div class="ana-source-row" data-index="<?php echo $i; ?>">
        <div class="ana-source-fields">
            <input type="text" class="ana-src-name regular-text" placeholder="<?php esc_attr_e( 'Nom (ex: BBC News)', 'ai-news-agent' ); ?>" value="<?php echo esc_attr( $src['name'] ?? '' ); ?>">
            <input type="url" class="ana-src-url regular-text" placeholder="<?php esc_attr_e( 'URL du flux RSS', 'ai-news-agent' ); ?>" value="<?php echo esc_attr( $src['url'] ?? '' ); ?>">
            <select class="ana-src-lang">
                <?php foreach ( [ 'en' => 'English', 'fr' => 'Français', 'hy' => 'Հայերեն', 'ru' => 'Русский', 'es' => 'Español', 'de' => 'Deutsch', 'ar' => 'عربي', 'zh' => '中文' ] as $code => $label ) : ?>
                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $src['lang'] ?? 'en', $code ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="ana-src-type">
                <option value="rss" <?php selected( $type, 'rss' ); ?>><?php esc_html_e( 'RSS', 'ai-news-agent' ); ?></option>
                <option value="scrape" <?php selected( $type, 'scrape' ); ?>><?php esc_html_e( 'Scrape', 'ai-news-agent' ); ?></option>
            </select>
            <button type="button" class="button ana-remove-source">✕</button>
        </div>
        <?php if ( $type === 'scrape' ) : ?>
        <div class="ana-scrape-extra">
            <input type="url" class="ana-src-base regular-text" placeholder="<?php esc_attr_e( 'Base URL (ex: https://exemple.com)', 'ai-news-agent' ); ?>" value="<?php echo esc_attr( $src['base'] ?? '' ); ?>">
            <input type="text" class="ana-src-pattern regular-text" placeholder="<?php esc_attr_e( 'Pattern regex (ex: #/article/[0-9]+#)', 'ai-news-agent' ); ?>" value="<?php echo esc_attr( $src['pattern'] ?? '' ); ?>">
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// ── Inline CSS ─────────────────────────────────────────────────────────────────
function ana_inline_css(): string {
    return '
    .ana-wrap { max-width: 1100px; }
    .ana-version { font-size: 13px; color: #999; font-weight: normal; margin-left: 8px; }
    .ana-stats { display: flex; gap: 16px; margin: 16px 0; }
    .ana-stat { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 16px 24px; text-align: center; min-width: 120px; }
    .ana-stat strong { display: block; font-size: 28px; color: #2271b1; }
    .ana-stat span { font-size: 12px; color: #666; }
    .ana-tabs { display: flex; gap: 0; border-bottom: 2px solid #2271b1; margin-bottom: 0; }
    .ana-tab { padding: 10px 20px; background: #f0f0f1; border: 1px solid #c3c4c7; border-bottom: none; text-decoration: none; color: #333; border-radius: 4px 4px 0 0; margin-right: 4px; }
    .ana-tab.active { background: #fff; border-bottom-color: #fff; font-weight: 600; color: #2271b1; }
    .ana-tab-content { display: none; background: #fff; border: 1px solid #c3c4c7; border-top: none; padding: 24px; }
    .ana-tab-content.active { display: block; }
    .ana-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 20px; margin-bottom: 20px; }
    .ana-card h2 { margin-top: 0; padding-top: 0; }
    .ana-count-row { display: flex; align-items: center; gap: 8px; margin: 12px 0; }
    .ana-count-row input { width: 60px; text-align: center; }
    .ana-btn-row { display: flex; gap: 8px; margin-top: 16px; align-items: center; }
    .ana-progress-bar { background: #f0f0f1; border-radius: 4px; height: 8px; margin: 12px 0; }
    #ana-progress-fill { background: #2271b1; height: 100%; border-radius: 4px; width: 0%; transition: width .3s; }
    .ana-status { margin-left: 12px; font-style: italic; color: #555; }
    .ana-drafts-table th { font-weight: 600; }
    .ana-history-item { margin: 8px 0; border: 1px solid #eee; border-radius: 4px; }
    .ana-history-item summary { padding: 8px 12px; cursor: pointer; font-weight: 500; background: #f9f9f9; }
    .ana-history-item ul { padding: 8px 24px; margin: 0; }
    .ana-history-item li { font-size: 12px; margin: 2px 0; color: #555; }
    .ana-source-row { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; padding: 12px; margin-bottom: 8px; }
    .ana-source-fields { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .ana-source-fields input, .ana-source-fields select { flex: 1; min-width: 120px; }
    .ana-scrape-extra { display: flex; gap: 8px; margin-top: 8px; }
    .ana-scrape-extra input { flex: 1; }
    .ana-result { margin-top: 16px; padding: 12px; border-radius: 4px; background: #f0f6fc; border: 1px solid #c5d9f0; }
    .ana-media-field { display: flex; align-items: center; gap: 10px; }
    .ana-media-preview img { display: block; border-radius: 4px; border: 1px solid #ddd; }
    ';
}
