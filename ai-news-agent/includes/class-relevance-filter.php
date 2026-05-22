<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI-powered relevance filter.
 * Uses the configured AI provider to score and select the best articles.
 * The filter criteria are fully configurable via admin settings.
 */
class ANA_Relevance_Filter {

    private string $api_key;
    private string $provider;
    private string $custom_filter_prompt;

    public function __construct() {
        $this->api_key              = (string) get_option( 'ana_api_key', '' );
        $this->provider             = (string) get_option( 'ana_api_provider', 'claude' );
        $this->custom_filter_prompt = (string) get_option( 'ana_filter_prompt', '' );
    }

    /**
     * Filter a list of articles and return the top $count most relevant ones.
     *
     * @param array $items     Raw articles from RSS fetcher.
     * @param int   $count     How many to keep.
     * @return array           Filtered articles (may contain 'relevance_score' key).
     */
    public function filter( array $items, int $count ): array {
        if ( empty( $items ) ) return [];
        if ( empty( $this->api_key ) ) {
            // No API key: return first $count items unfiltered
            return array_slice( $items, 0, $count );
        }

        // Batch the items into groups of 20 to avoid token limits
        $batches = array_chunk( $items, 20 );
        $scored  = [];

        foreach ( $batches as $batch ) {
            $batch_scored = $this->score_batch( $batch );
            $scored       = array_merge( $scored, $batch_scored );
        }

        // Trending boost : +2 points si 3+ sources couvrent la même histoire
        $scored = $this->apply_trending_boost( $scored );

        // Sort by score descending
        usort( $scored, fn( $a, $b ) => ( $b['relevance_score'] ?? 0 ) <=> ( $a['relevance_score'] ?? 0 ) );

        // Return top $count items with score >= threshold (5/10 minimum)
        $threshold = 5;
        $filtered  = array_filter( $scored, fn( $i ) => ( $i['relevance_score'] ?? 0 ) >= $threshold );
        $result    = array_slice( array_values( $filtered ), 0, $count );

        // If not enough high-quality items, fill up with the rest
        if ( count( $result ) < $count ) {
            $low_quality = array_filter( $scored, fn( $i ) => ( $i['relevance_score'] ?? 0 ) < $threshold );
            $result      = array_merge( $result, array_slice( array_values( $low_quality ), 0, $count - count( $result ) ) );
        }

        return array_slice( $result, 0, $count );
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function apply_trending_boost( array $items ): array {
        $n = count( $items );
        // Count how many other items share the same story (title similarity >= 70%)
        $coverage = array_fill( 0, $n, 1 );
        for ( $i = 0; $i < $n; $i++ ) {
            $ti = mb_strtolower( (string) ( $items[ $i ]['title'] ?? '' ) );
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $tj = mb_strtolower( (string) ( $items[ $j ]['title'] ?? '' ) );
                similar_text( $ti, $tj, $pct );
                if ( $pct >= 70 ) {
                    $coverage[ $i ]++;
                    $coverage[ $j ]++;
                }
            }
        }
        // Apply +2 boost to stories covered by 3+ sources, cap at 10
        foreach ( $items as $i => &$item ) {
            if ( $coverage[ $i ] >= 3 ) {
                $item['relevance_score'] = min( 10, ( $item['relevance_score'] ?? 5 ) + 2 );
                $item['trending']        = true;
            }
        }
        return $items;
    }

    private function score_batch( array $items ): array {
        $site_name = (string) get_option( 'ana_site_name', get_bloginfo( 'name' ) );
        $language  = (string) get_option( 'ana_target_language', 'français' );
        $categories = ANA_Settings::categories();
        $cat_list   = implode( ', ', $categories );

        // Build the filter prompt
        if ( ! empty( $this->custom_filter_prompt ) ) {
            $criteria = $this->custom_filter_prompt;
        } else {
            $criteria = "Tu es l'éditeur en chef de {$site_name}. Évalue la pertinence éditoriale de chaque article pour ton site. Langue de publication : {$language}. Catégories disponibles : {$cat_list}.";
        }

        // Build article list
        $list = '';
        foreach ( $items as $idx => $item ) {
            $n     = $idx + 1;
            $title = mb_substr( (string) ( $item['title'] ?? '' ), 0, 120 );
            $desc  = mb_substr( (string) ( $item['description'] ?? '' ), 0, 200 );
            $src   = (string) ( $item['source'] ?? '' );
            $list .= "{$n}. [{$src}] {$title}\n   {$desc}\n";
        }

        $prompt = "{$criteria}\n\n"
            . "Pour chaque article ci-dessous, donne un score de pertinence de 0 à 10 et une catégorie suggérée.\n"
            . "Réponds UNIQUEMENT en JSON valide : [{\"index\":1,\"score\":8,\"category\":\"Politique\"},{\"index\":2,\"score\":3,\"category\":\"Societe\"},…]\n\n"
            . "ARTICLES :\n{$list}";

        try {
            $raw    = $this->call_api( $prompt );
            $parsed = $this->parse_scores( $raw );
        } catch ( \Throwable $e ) {
            error_log( '[ANA] Filter API error: ' . $e->getMessage() );
            $parsed = [];
        }

        // Merge scores back
        foreach ( $items as $idx => &$item ) {
            $n    = $idx + 1;
            $item['relevance_score'] = $parsed[ $n ]['score'] ?? 5;
            $item['ai_category']     = $parsed[ $n ]['category'] ?? '';
        }
        return $items;
    }

    private function parse_scores( string $raw ): array {
        // Extract JSON array from response
        if ( preg_match( '/\[[\s\S]*\]/', $raw, $m ) ) {
            $data = json_decode( $m[0], true );
            if ( is_array( $data ) ) {
                $result = [];
                foreach ( $data as $entry ) {
                    $idx = (int) ( $entry['index'] ?? 0 );
                    if ( $idx > 0 ) {
                        $result[ $idx ] = [
                            'score'    => min( 10, max( 0, (int) ( $entry['score'] ?? 5 ) ) ),
                            'category' => sanitize_text_field( (string) ( $entry['category'] ?? '' ) ),
                        ];
                    }
                }
                return $result;
            }
        }
        return [];
    }

    private function call_api( string $prompt ): string {
        return $this->provider === 'openai'
            ? $this->call_openai( $prompt )
            : $this->call_claude( $prompt );
    }

    private function call_claude( string $prompt ): string {
        $r = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-sonnet-4-5',
                'max_tokens' => 1024,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );
        if ( is_wp_error( $r ) ) throw new \RuntimeException( $r->get_error_message() );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        return (string) ( $body['content'][0]['text'] ?? '' );
    }

    private function call_openai( string $prompt ): string {
        $r = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'    => 'gpt-4o',
                'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );
        if ( is_wp_error( $r ) ) throw new \RuntimeException( $r->get_error_message() );
        $body = json_decode( wp_remote_retrieve_body( $r ), true );
        return (string) ( $body['choices'][0]['message']['content'] ?? '' );
    }
}
