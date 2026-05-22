<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Publishes articles as WordPress posts (draft by default).
 */
class ANA_Article_Publisher {

    private int    $category_id;
    private string $post_status;

    public function __construct() {
        $this->category_id = (int)    get_option( 'ana_wp_category', 0 );
        $this->post_status = (string) get_option( 'ana_post_status', 'draft' );
    }

    /**
     * Check if a post with this title already exists (any status).
     */
    public function exists( string $title ): bool {
        if ( empty( $title ) ) return false;

        // Exact title match
        $existing = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'draft', 'publish', 'pending', 'future' ],
            'title'          => $title,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );
        if ( ! empty( $existing ) ) return true;

        // Fuzzy match via meta
        $existing2 = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'draft', 'publish', 'pending' ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [
                'key'     => '_ana_source_title',
                'value'   => mb_substr( $title, 0, 100 ),
                'compare' => 'LIKE',
            ] ],
        ] );
        if ( ! empty( $existing2 ) ) return true;

        // Normalized title similarity check (last 200 published)
        $recent = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'draft', 'publish', 'pending' ],
            'posts_per_page' => 200,
            'fields'         => 'post_title',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        $norm_candidate = $this->norm( $title );
        foreach ( $recent as $p ) {
            $norm_existing = $this->norm( $p->post_title );
            if ( $norm_candidate === $norm_existing ) return true;
            similar_text( $norm_candidate, $norm_existing, $pct );
            if ( $pct >= 85 ) return true;
        }

        return false;
    }

    /**
     * Publish an article as a WordPress post.
     *
     * @param array  $article   { title_fr, content, category, score, word_count, source, source_url }
     * @param string $image_path  Absolute path to the featured image (may be empty)
     * @return int   Post ID
     */
    public function publish( array $article, string $image_path = '' ): int {
        $title   = sanitize_text_field( (string) ( $article['title_fr'] ?? '' ) );
        $content = wp_kses_post( $this->format_content( (string) ( $article['content'] ?? '' ) ) );

        $excerpt = sanitize_text_field( (string) ( $article['excerpt'] ?? '' ) );

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $this->post_status,
            'post_type'    => 'post',
            'post_author'  => 1,
        ];

        // Resolve category
        $cat_id = $this->resolve_category( (string) ( $article['category'] ?? '' ) );
        if ( $cat_id > 0 ) {
            $post_data['post_category'] = [ $cat_id ];
        } elseif ( $this->category_id > 0 ) {
            $post_data['post_category'] = [ $this->category_id ];
        }

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) || ! $post_id ) return 0;

        // Meta
        update_post_meta( $post_id, '_ana_source',       sanitize_text_field( (string) ( $article['source'] ?? '' ) ) );
        update_post_meta( $post_id, '_ana_source_url',   esc_url_raw( (string) ( $article['source_url'] ?? '' ) ) );
        update_post_meta( $post_id, '_ana_source_title', sanitize_text_field( (string) ( $article['source_title'] ?? '' ) ) );
        update_post_meta( $post_id, '_ana_score',        (int) ( $article['score'] ?? 0 ) );
        update_post_meta( $post_id, '_ana_word_count',   (int) ( $article['word_count'] ?? 0 ) );
        update_post_meta( $post_id, '_ana_generated_at', current_time( 'mysql' ) );

        // Tags
        $tags = $article['tags'] ?? [];
        if ( ! empty( $tags ) && is_array( $tags ) ) {
            wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $tags ), false );
        }

        // Featured image
        if ( ! empty( $image_path ) && file_exists( $image_path ) ) {
            $attachment_id = $this->attach_image( $image_path, $post_id, $title );
            if ( $attachment_id ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }

        // Update total counter
        $total = (int) get_option( 'ana_total_generated', 0 );
        update_option( 'ana_total_generated', $total + 1 );

        return $post_id;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function format_content( string $content ): string {
        // Convert double newlines to paragraphs
        $paragraphs = preg_split( '/\n{2,}/', trim( $content ) );
        $html       = '';
        foreach ( $paragraphs as $p ) {
            $p = trim( $p );
            if ( $p !== '' ) $html .= '<p>' . nl2br( esc_html( $p ) ) . '</p>' . "\n";
        }
        return $html ?: '<p>' . esc_html( $content ) . '</p>';
    }

    private function resolve_category( string $cat_name ): int {
        if ( empty( $cat_name ) ) return $this->category_id;

        // Try exact match
        $term = get_term_by( 'name', $cat_name, 'category' );
        if ( $term ) return $term->term_id;

        // Try slug match
        $slug = sanitize_title( $cat_name );
        $term = get_term_by( 'slug', $slug, 'category' );
        if ( $term ) return $term->term_id;

        return $this->category_id;
    }

    private function attach_image( string $path, int $post_id, string $title ): int {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload_dir = wp_upload_dir();
        $filename   = 'ana-' . $post_id . '-' . basename( $path );
        $new_path   = $upload_dir['path'] . '/' . $filename;

        if ( ! copy( $path, $new_path ) ) return 0;

        $wp_filetype = wp_check_filetype( $new_path );
        $attachment  = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name( $title ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment( $attachment, $new_path, $post_id );
        if ( is_wp_error( $attach_id ) ) return 0;

        $attach_data = wp_generate_attachment_metadata( $attach_id, $new_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        @unlink( $path );
        return $attach_id;
    }

    private function norm( string $t ): string {
        $t = mb_strtolower( $t );
        $t = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $t );
        return trim( (string) preg_replace( '/\s+/', ' ', $t ) );
    }
}
