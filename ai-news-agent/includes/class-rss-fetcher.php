<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fetches articles from user-configured RSS feeds and scrape sources.
 * Sources are stored in WP option 'ana_sources' as a JSON array.
 */
class ANA_RSS_Fetcher {

    const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    private array $sources = [];

    public function __construct() {
        $this->sources = $this->load_sources();
    }

    // ── Public ──────────────────────────────────────────────────────────────

    public function get_sources(): array {
        return $this->sources;
    }

    /**
     * Fetch articles from all active sources.
     * @param int $per_source Max items per source.
     */
    public function fetch_all( int $per_source = 5 ): array {
        $all  = [];
        $seen = [];

        foreach ( $this->sources as $src ) {
            $type  = $src['type'] ?? 'rss';
            $limit = max( 2, min( 10, $per_source ) );

            if ( $type === 'rss' ) {
                $items = $this->fetch_rss( $src['url'], $src['name'], $src['lang'] ?? 'en', $limit );
            } elseif ( $type === 'scrape' ) {
                $items = $this->fetch_scrape( $src, $limit );
            } else {
                continue;
            }

            foreach ( $items as $item ) {
                $key = $this->unique_key( $item );
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $all[]        = $item;
            }
        }

        // Sort: most recent first (if pub_date available)
        usort( $all, function ( $a, $b ) {
            $ta = strtotime( $a['pub_date'] ?? '' ) ?: 0;
            $tb = strtotime( $b['pub_date'] ?? '' ) ?: 0;
            return $tb - $ta;
        } );

        return $all;
    }

    /**
     * Test all sources and return status for each.
     */
    public function test_all_sources(): array {
        $results = [];
        foreach ( $this->sources as $src ) {
            $type  = $src['type'] ?? 'rss';
            $items = [];
            try {
                if ( $type === 'rss' ) {
                    $items = $this->fetch_rss( $src['url'], $src['name'], $src['lang'] ?? 'en', 3 );
                } elseif ( $type === 'scrape' ) {
                    $items = $this->fetch_scrape( $src, 3 );
                }
                $results[] = [
                    'name'  => $src['name'],
                    'type'  => $type,
                    'ok'    => ! empty( $items ),
                    'found' => count( $items ),
                    'url'   => $src['url'],
                ];
            } catch ( \Throwable $e ) {
                $results[] = [
                    'name'  => $src['name'],
                    'type'  => $type,
                    'ok'    => false,
                    'found' => 0,
                    'url'   => $src['url'],
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function load_sources(): array {
        $saved = get_option( 'ana_sources', [] );
        if ( is_string( $saved ) ) {
            $saved = json_decode( $saved, true ) ?: [];
        }
        $saved = is_array( $saved ) ? $saved : [];
        if ( empty( $saved ) ) {
            $saved = ANA_Settings::defaults()['ana_sources'];
        }
        return $saved;
    }

    private function unique_key( array $item ): string {
        $link = trim( (string) ( $item['link'] ?? '' ) );
        if ( $link !== '' ) return 'link:' . $link;
        return 'title:' . mb_strtolower( mb_substr( trim( (string) ( $item['title'] ?? '' ) ), 0, 80 ) );
    }

    private function fetch_rss( string $url, string $name, string $lang, int $limit ): array {
        $r = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false, 'user-agent' => self::UA ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return [];

        $body = wp_remote_retrieve_body( $r );
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
        if ( ! $xml ) return [];

        $items  = [];
        $count  = 0;
        $nodes  = isset( $xml->channel->item ) ? $xml->channel->item : ( isset( $xml->entry ) ? $xml->entry : [] );

        foreach ( $nodes as $node ) {
            if ( $count >= $limit ) break;

            $title     = trim( (string) ( isset( $node->title ) ? $node->title : '' ) );
            $link      = $this->extract_rss_link( $node );
            $desc_html = (string) ( isset( $node->description ) ? $node->description : ( isset( $node->summary ) ? $node->summary : '' ) );
            $desc      = trim( wp_strip_all_tags( $desc_html ) );
            $photo     = $this->extract_media_url( $node, $desc_html );
            $date      = (string) ( isset( $node->pubDate ) ? $node->pubDate : ( isset( $node->published ) ? $node->published : '' ) );

            if ( empty( $title ) ) continue;

            $items[] = [
                'title'       => $title,
                'description' => mb_substr( $desc, 0, 400 ),
                'link'        => $link,
                'photo_url'   => $photo,
                'pub_date'    => $date ?: current_time( 'D, d M Y H:i:s O' ),
                'source'      => $name,
                'lang'        => $lang,
            ];
            $count++;
        }
        return $items;
    }

    private function fetch_scrape( array $src, int $limit ): array {
        $url     = $src['url'];
        $base    = $src['base'] ?? '';
        $pattern = $src['pattern'] ?? '';
        $name    = $src['name'];
        $lang    = $src['lang'] ?? 'en';

        $r = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => false, 'user-agent' => self::UA ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return [];

        $html  = wp_remote_retrieve_body( $r );
        $links = $this->extract_article_links( $html, $src );

        $items = [];
        $count = 0;
        foreach ( array_slice( $links, 0, $limit * 3 ) as $link ) {
            if ( $count >= $limit ) break;
            $article = $this->scrape_article( $link, $name, $lang );
            if ( $article ) { $items[] = $article; $count++; }
        }
        return $items;
    }

    private function extract_article_links( string $html, array $src ): array {
        $base    = $src['base'] ?? '';
        $pattern = $src['pattern'] ?? '';
        $links   = [];

        if ( class_exists( 'DOMDocument' ) ) {
            $dom = new DOMDocument();
            @$dom->loadHTML( $html );
            $xpath = new DOMXPath( $dom );
            foreach ( $xpath->query( '//a[@href]' ) as $a ) {
                $href = trim( (string) $a->getAttribute( 'href' ) );
                if ( $href === '' ) continue;
                $abs = $this->abs_url( $href, $base );
                if ( $this->is_valid_article_url( $abs, $src, $pattern ) ) $links[] = $abs;
            }
        } elseif ( preg_match_all( '/href=["\'](([^"\']+))["\']/', $html, $m ) ) {
            foreach ( $m[1] as $href ) {
                $abs = $this->abs_url( trim( $href ), $base );
                if ( $this->is_valid_article_url( $abs, $src, $pattern ) ) $links[] = $abs;
            }
        }
        return array_values( array_unique( $links ) );
    }

    private function is_valid_article_url( string $url, array $src, string $pattern ): bool {
        $base = $src['base'] ?? '';
        if ( $base && strpos( $url, $base ) !== 0 ) return false;
        if ( $pattern && ! preg_match( $pattern, $url ) ) return false;
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) return false;
        return true;
    }

    private function scrape_article( string $url, string $name, string $lang ): ?array {
        $r = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false, 'user-agent' => self::UA ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return null;

        $html  = wp_remote_retrieve_body( $r );
        $title = '';
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $m ) ) {
            $title = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
        }
        $desc = '';
        if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/', $html, $m ) ) {
            $desc = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
        }
        $photo = '';
        if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/', $html, $m ) ) {
            $photo = $m[1];
        }
        if ( empty( $title ) ) return null;

        return [
            'title'       => trim( $title ),
            'description' => trim( $desc ),
            'link'        => $url,
            'photo_url'   => $photo,
            'pub_date'    => current_time( 'D, d M Y H:i:s O' ),
            'source'      => $name,
            'lang'        => $lang,
        ];
    }

    private function extract_rss_link( $node ): string {
        if ( isset( $node->link ) ) {
            $link = trim( (string) $node->link );
            if ( $link !== '' ) return $link;
        }
        // Atom <link href="...">
        foreach ( $node->link ?? [] as $l ) {
            $attrs = $l->attributes();
            if ( isset( $attrs['href'] ) ) return trim( (string) $attrs['href'] );
        }
        return '';
    }

    private function extract_media_url( $node, string $desc_html ): string {
        // Try <media:content>
        $media = $node->children( 'http://search.yahoo.com/mrss/' );
        if ( isset( $media->content ) ) {
            $attrs = $media->content->attributes();
            if ( isset( $attrs['url'] ) ) return (string) $attrs['url'];
        }
        // Try <enclosure>
        if ( isset( $node->enclosure ) ) {
            $attrs = $node->enclosure->attributes();
            if ( isset( $attrs['url'] ) && strpos( (string) $attrs['type'], 'image' ) !== false ) {
                return (string) $attrs['url'];
            }
        }
        // Try og:image in description
        if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $desc_html, $m ) ) {
            return $m[1];
        }
        return '';
    }

    private function abs_url( string $href, string $base ): string {
        if ( strpos( $href, 'http' ) === 0 ) return $href;
        if ( strpos( $href, '//' ) === 0 ) {
            $scheme = parse_url( $base, PHP_URL_SCHEME ) ?: 'https';
            return $scheme . ':' . $href;
        }
        if ( strpos( $href, '/' ) === 0 ) {
            $parsed = parse_url( $base );
            return ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . $href;
        }
        return rtrim( $base, '/' ) . '/' . $href;
    }
}
