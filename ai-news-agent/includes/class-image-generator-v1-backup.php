<?php
/**
 * BACKUP — version bitmap originale (avant amélioration TTF).
 * Pour revenir en arrière :
 *   1. Supprimer class-image-generator.php
 *   2. Renommer ce fichier en class-image-generator.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ANA_Image_Generator {

    private string $site_name;
    private string $color_bg;
    private string $color_text;
    private string $color_accent;
    private int    $logo_ai_id;
    private int    $logo_banner_id;

    public function __construct() {
        $this->site_name      = (string) get_option( 'ana_site_name', get_bloginfo( 'name' ) );
        $this->color_bg       = (string) get_option( 'ana_brand_color_bg',     '#1a1a2e' );
        $this->color_text     = (string) get_option( 'ana_brand_color_text',   '#ffffff' );
        $this->color_accent   = (string) get_option( 'ana_brand_color_accent', '#e94560' );
        $this->logo_ai_id     = (int)    get_option( 'ana_logo_ai_id',     0 );
        $this->logo_banner_id = (int)    get_option( 'ana_logo_banner_id', 0 );
    }

    public function generate( array $article, string $photo_url = '' ): string {
        if ( ! extension_loaded( 'gd' ) ) return '';

        $width  = 1200;
        $height = 630;
        $img    = imagecreatetruecolor( $width, $height );
        if ( ! $img ) return '';

        [ $r, $g, $b ] = $this->hex2rgb( $this->color_bg );
        $bg_color       = imagecolorallocate( $img, $r, $g, $b );
        imagefill( $img, 0, 0, $bg_color );

        if ( ! empty( $photo_url ) ) {
            $this->apply_photo_background( $img, $photo_url, $width, $height, $bg_color );
        }

        $this->apply_overlay( $img, $width, $height );

        [ $ar, $ag, $ab ] = $this->hex2rgb( $this->color_accent );
        $accent            = imagecolorallocate( $img, $ar, $ag, $ab );
        imagefilledrectangle( $img, 0, $height - 8, $width, $height, $accent );

        if ( $this->logo_ai_id > 0 ) {
            $this->overlay_logo( $img, $this->logo_ai_id, 30, 30, 80, 80 );
        }

        $category = (string) ( $article['category'] ?? '' );
        if ( $category ) {
            imagefilledrectangle( $img, 30, 130, 30 + 14 * strlen( $category ) + 20, 162, $accent );
            [ $tr, $tg, $tb ] = $this->hex2rgb( $this->color_text );
            $text_color        = imagecolorallocate( $img, $tr, $tg, $tb );
            $this->draw_text( $img, strtoupper( $category ), 40, 139, 14, $text_color );
        }

        [ $tr, $tg, $tb ] = $this->hex2rgb( $this->color_text );
        $text_color        = imagecolorallocate( $img, $tr, $tg, $tb );
        $title             = (string) ( $article['title_fr'] ?? '' );
        $this->draw_wrapped_text( $img, $title, 30, 185, $width - 60, 32, $text_color );

        $source     = (string) ( $article['source'] ?? '' );
        $gray_color = imagecolorallocate( $img, 180, 180, 180 );
        if ( $source ) {
            $this->draw_text( $img, $source, 30, $height - 40, 16, $gray_color );
        }

        if ( $this->site_name ) {
            $sw = imagefontwidth( 4 ) * strlen( $this->site_name );
            $this->draw_text( $img, $this->site_name, $width - $sw - 30, $height - 40, 16, $text_color );
        }

        if ( $this->logo_banner_id > 0 ) {
            $this->overlay_logo( $img, $this->logo_banner_id, (int) ( $width / 2 ) - 100, $height - 90, 200, 60 );
        }

        $tmp = get_temp_dir() . 'ana_img_' . uniqid() . '.jpg';
        $ok  = imagejpeg( $img, $tmp, 88 );
        imagedestroy( $img );

        return $ok ? $tmp : '';
    }

    private function apply_photo_background( $img, string $url, int $w, int $h, $fallback ): void {
        $r = wp_remote_get( $url, [ 'timeout' => 8, 'sslverify' => false ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return;
        $data   = wp_remote_retrieve_body( $r );
        $source = @imagecreatefromstring( $data );
        if ( ! $source ) return;
        $sw = imagesx( $source ); $sh = imagesy( $source );
        $ratio_src = $sw / $sh; $ratio_dst = $w / $h;
        if ( $ratio_src > $ratio_dst ) { $new_h = $h; $new_w = (int) round( $h * $ratio_src ); }
        else { $new_w = $w; $new_h = (int) round( $w / $ratio_src ); }
        $ox = (int) round( ( $new_w - $w ) / 2 ); $oy = (int) round( ( $new_h - $h ) / 2 );
        imagecopyresampled( $img, $source, 0, 0, (int) ( $ox * $sw / $new_w ), (int) ( $oy * $sh / $new_h ), $w, $h, (int) ( $w * $sw / $new_w ), (int) ( $h * $sh / $new_h ) );
        imagedestroy( $source );
    }

    private function apply_overlay( $img, int $w, int $h ): void {
        $dark = imagecolorallocatealpha( $img, 0, 0, 0, 40 );
        imagefilledrectangle( $img, 0, 0, $w, $h, $dark );
        for ( $y = $h - 300; $y < $h; $y++ ) {
            $alpha = (int) ( 40 * ( $y - ( $h - 300 ) ) / 300 );
            $c     = imagecolorallocatealpha( $img, 0, 0, 0, max( 0, 80 - $alpha ) );
            imageline( $img, 0, $y, $w, $y, $c );
        }
    }

    private function overlay_logo( $img, int $media_id, int $x, int $y, int $max_w, int $max_h ): void {
        $path = get_attached_file( $media_id );
        if ( ! $path || ! file_exists( $path ) ) return;
        $ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $source = match ( $ext ) { 'jpg', 'jpeg' => @imagecreatefromjpeg( $path ), 'png' => @imagecreatefrompng( $path ), 'gif' => @imagecreatefromgif( $path ), default => false };
        if ( ! $source ) return;
        $sw = imagesx( $source ); $sh = imagesy( $source );
        $scale = min( $max_w / $sw, $max_h / $sh );
        $dw = (int) round( $sw * $scale ); $dh = (int) round( $sh * $scale );
        imagecopyresampled( $img, $source, $x, $y, 0, 0, $dw, $dh, $sw, $sh );
        imagedestroy( $source );
    }

    private function draw_text( $img, string $text, int $x, int $y, int $size, $color ): void {
        $font = match ( true ) { $size >= 30 => 5, $size >= 20 => 4, $size >= 14 => 3, default => 2 };
        imagestring( $img, $font, $x, $y, $text, $color );
    }

    private function draw_wrapped_text( $img, string $text, int $x, int $y, int $max_width, int $size, $color ): void {
        $font = match ( true ) { $size >= 30 => 5, $size >= 20 => 4, $size >= 14 => 3, default => 2 };
        $char_width = imagefontwidth( $font ); $line_h = imagefontheight( $font ) + 8;
        $max_chars  = max( 10, (int) floor( $max_width / $char_width ) );
        $words = explode( ' ', $text ); $line = ''; $cy = $y;
        foreach ( $words as $word ) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            if ( strlen( $test ) > $max_chars && $line !== '' ) {
                imagestring( $img, $font, $x, $cy, $line, $color );
                $cy += $line_h; $line = $word;
                if ( $cy > 520 ) break;
            } else { $line = $test; }
        }
        if ( $line !== '' && $cy <= 520 ) imagestring( $img, $font, $x, $cy, $line, $color );
    }

    private function hex2rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
    }
}
