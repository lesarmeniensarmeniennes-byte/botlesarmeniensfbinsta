<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI article writer.
 * Writing style, language, categories, and prompt are fully configurable.
 */
class ANA_AI_Writer {

    const MAX_RETRIES = 3;

    private string $api_key;
    private string $provider;
    private string $site_name;
    private string $target_language;
    private string $writing_style;
    private string $custom_prompt;
    private int    $min_words;
    private int    $max_words;
    private array  $categories;

    public function __construct() {
        $this->api_key         = (string) get_option( 'ana_api_key', '' );
        $this->provider        = (string) get_option( 'ana_api_provider', 'claude' );
        $this->site_name       = (string) get_option( 'ana_site_name', get_bloginfo( 'name' ) );
        $this->target_language = (string) get_option( 'ana_target_language', 'français' );
        $this->writing_style   = (string) get_option( 'ana_writing_style', 'journalistique, factuel, sobre' );
        $this->custom_prompt   = (string) get_option( 'ana_custom_prompt', '' );
        $this->min_words       = max( 100, (int) get_option( 'ana_min_words', 250 ) );
        $this->max_words       = max( $this->min_words + 50, (int) get_option( 'ana_max_words', 500 ) );
        $this->categories      = ANA_Settings::categories();
    }

    /**
     * Test the API connection.
     */
    public function test_connection(): bool {
        try {
            $r = $this->call_api( 'Réponds uniquement le mot: OK' );
            return strpos( $r, 'OK' ) !== false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Write a full article from a raw RSS item.
     *
     * @param array $item  { title, description, link, source, lang, photo_url, pub_date, relevance_score?, ai_category? }
     * @return array|null  { title_fr, content, category, score, word_count, source, source_url }
     */
    public function write( array $item ): ?array {
        if ( empty( $this->api_key ) ) return null;

        $lang_map  = [ 'hy' => 'arménien', 'ru' => 'russe', 'en' => 'anglais', 'fr' => 'français', 'de' => 'allemand', 'es' => 'espagnol', 'ar' => 'arabe', 'zh' => 'chinois', 'ja' => 'japonais' ];
        $src_lang  = $lang_map[ $item['lang'] ?? 'en' ] ?? ( $item['lang'] ?? 'en' );
        $today     = date( 'j F Y' );
        $cat_list  = implode( '|', $this->categories );
        $cat_hint  = ! empty( $item['ai_category'] ) ? "\nCatégorie suggérée : {$item['ai_category']}" : '';

        [ $system, $user ] = $this->build_prompt( $item, $src_lang, $today, $cat_list, $cat_hint );

        for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
            try {
                $raw    = $this->call_api( $system, $user );
                $parsed = $this->parse_json( $raw );

                if ( ! $parsed || empty( $parsed['title_fr'] ) || empty( $parsed['content'] ) ) {
                    if ( $attempt < self::MAX_RETRIES ) { sleep( 2 ); continue; }
                    return null;
                }

                // Validate category
                $cat = $parsed['category'] ?? '';
                if ( ! in_array( $cat, $this->categories, true ) ) {
                    $cat = ! empty( $item['ai_category'] ) ? $item['ai_category'] : ( $this->categories[0] ?? 'General' );
                }
                $parsed['category'] = $cat;

                // Validate word count
                $words = str_word_count( strip_tags( $parsed['content'] ) );
                if ( $words < $this->min_words && $attempt < self::MAX_RETRIES ) {
                    sleep( 2 );
                    continue;
                }

                return array_merge( $parsed, [
                    'source'      => $item['source'],
                    'source_url'  => $item['link'] ?? '',
                    'score'       => $parsed['score'] ?? ( $item['relevance_score'] ?? 7 ),
                    'word_count'  => $words,
                ] );

            } catch ( \Throwable $e ) {
                error_log( '[ANA] Writer attempt ' . $attempt . ': ' . $e->getMessage() );
                if ( $attempt < self::MAX_RETRIES ) sleep( 3 );
            }
        }
        return null;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function build_prompt( array $item, string $src_lang, string $today, string $cat_list, string $cat_hint ): array {
        $system = ! empty( $this->custom_prompt )
            ? $this->custom_prompt
            : "Tu es un rédacteur senior de {$this->site_name}. "
                . "Style d'écriture : {$this->writing_style}. "
                . "Tu rédiges en {$this->target_language}.\n\n"
                . "RÈGLES RÉDACTIONNELLES :\n"
                . "1. Écris dans un style {$this->writing_style}.\n"
                . "2. Commence directement par les faits essentiels. Pas d'introduction creuse.\n"
                . "3. STRICT : tu ne peux utiliser QUE les informations présentes dans le texte source. Pas une seule phrase de plus.\n"
                . "4. INTERDIT ABSOLU : ajouter du contexte géopolitique, historique ou régional absent de la source.\n"
                . "5. INTERDIT ABSOLU : inventer des faits, des citations, des précisions ou des nuances non présents dans la source.\n"
                . "6. Ne déduis rien, ne contextualise pas, n'amplifie pas.\n"
                . "7. Si le texte source est court, l'article sera court. Ne rembourre pas pour atteindre la longueur cible.\n"
                . "8. Longueur cible : {$this->min_words} à {$this->max_words} mots — uniquement si le contenu source le permet naturellement.\n"
                . "9. INTERDIT : 'intervient dans un contexte', 'dans ce contexte', 'il convient de noter', 'à noter que', 'rappelons que', 'cette situation s'inscrit dans'.\n"
                . "10. Réponds UNIQUEMENT en JSON valide, sans markdown, sans backticks.";

        $title = mb_substr( (string) ( $item['title'] ?? '' ), 0, 200 );
        $desc  = mb_substr( (string) ( $item['description'] ?? '' ), 0, 800 );

        $user = "Date : {$today}\n"
            . "Source : {$item['source']} (langue : {$src_lang}){$cat_hint}\n\n"
            . "ARTICLE SOURCE :\n"
            . "Titre : {$title}\n"
            . "Résumé : {$desc}\n"
            . "Lien : {$item['link']}\n\n"
            . 'Format de réponse JSON : {"title_fr":"Traduction fidèle du titre source, max 12 mots","category":"' . $cat_list . '","excerpt":"Résumé factuel 1-2 phrases max 30 mots","tags":["mot-clé1","mot-clé2","mot-clé3"],"content":"Article ' . $this->min_words . '-' . $this->max_words . ' mots paragraphes séparés par \\n\\n","score":8}';

        return [ $system, $user ];
    }

    private function parse_json( string $raw ): ?array {
        // Strip markdown code blocks
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
        $raw = preg_replace( '/\s*```$/', '', $raw );

        $data = json_decode( trim( $raw ), true );
        if ( is_array( $data ) && isset( $data['title_fr'] ) ) return $data;

        // Try to extract JSON from mixed content
        if ( preg_match( '/\{[\s\S]*"title_fr"[\s\S]*\}/', $raw, $m ) ) {
            $data = json_decode( $m[0], true );
            if ( is_array( $data ) && isset( $data['title_fr'] ) ) return $data;
        }
        return null;
    }

    private function call_api( string $system, string $user ): string {
        return $this->provider === 'openai'
            ? $this->call_openai( $system, $user )
            : $this->call_claude( $system, $user );
    }

    private function call_claude( string $system, string $user ): string {
        $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-sonnet-4-6',
                'max_tokens' => 2048,
                'system'     => $system,
                'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
            ] ),
        ] );
        if ( is_wp_error( $r ) ) throw new \RuntimeException( $r->get_error_message() );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( isset( $body['error'] ) ) throw new \RuntimeException( $body['error']['message'] ?? 'Claude API error' );
        return (string) ( $body['content'][0]['text'] ?? '' );
    }

    private function call_openai( string $system, string $user ): string {
        $r = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'    => 'gpt-4o',
                'messages' => [
                    [ 'role' => 'system', 'content' => $system ],
                    [ 'role' => 'user',   'content' => $user ],
                ],
            ] ),
        ] );
        if ( is_wp_error( $r ) ) throw new \RuntimeException( $r->get_error_message() );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( isset( $body['error'] ) ) throw new \RuntimeException( $body['error']['message'] ?? 'OpenAI API error' );
        return (string) ( $body['choices'][0]['message']['content'] ?? '' );
    }
}
