<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates branded featured images using GD.
 * Layout: photo top ~60%, solid dark band bottom ~40%, large centered title.
 */
class ANA_Image_Generator {

    private string $site_name;
    private string $color_bg;
    private string $color_text;
    private string $color_accent;
    private int    $logo_ai_id;
    private int    $logo_banner_id;

    private string $ttf_font_bold    = '';
    private string $ttf_font_regular = '';

    public function __construct() {
        $this->site_name      = (string) get_option( 'ana_site_name', get_bloginfo( 'name' ) );
        $this->color_bg       = (string) get_option( 'ana_brand_color_bg',     '#1a1a2e' );
        $this->color_text     = (string) get_option( 'ana_brand_color_text',   '#ffffff' );
        $this->color_accent   = (string) get_option( 'ana_brand_color_accent', '#e94560' );
        $this->logo_ai_id     = (int)    get_option( 'ana_logo_ai_id',     0 );
        $this->logo_banner_id = (int)    get_option( 'ana_logo_banner_id', 0 );

        $this->maybe_download_fonts();
        $this->ttf_font_bold    = $this->find_ttf_font( true );
        $this->ttf_font_regular = $this->find_ttf_font( false );
    }

    public function generate( array $article, string $photo_url = '' ): string {
        if ( ! extension_loaded( 'gd' ) ) return '';

        $width  = 1200;
        $height = 630;
        $band_y = 368; // photo fills 0–band_y, black band fills band_y–height

        $img = imagecreatetruecolor( $width, $height );
        if ( ! $img ) return '';
        imagealphablending( $img, true );

        // ── Allocate colors ───────────────────────────────────────────────────
        [ $r,  $g,  $b  ] = $this->hex2rgb( $this->color_bg );
        $bg_color           = imagecolorallocate( $img, $r, $g, $b );
        [ $tr, $tg, $tb ] = $this->hex2rgb( $this->color_text );
        $text_color         = imagecolorallocate( $img, $tr, $tg, $tb );
        [ $ar, $ag, $ab ] = $this->hex2rgb( $this->color_accent );
        $accent             = imagecolorallocate( $img, $ar, $ag, $ab );
        $black              = imagecolorallocate( $img, 10, 10, 14 );
        $gray               = imagecolorallocate( $img, 160, 160, 160 );

        // ── Photo zone (top) ─────────────────────────────────────────────────
        imagefill( $img, 0, 0, $bg_color );
        if ( ! empty( $photo_url ) ) {
            $this->apply_photo_background( $img, $photo_url, $width, $band_y, $bg_color );
        }

        // ── Dark band — gradient from photo tone (top) to solid dark (bottom) ──
        [ $br, $bg_ch, $bb ] = ! empty( $photo_url )
            ? $this->sample_dominant_dark( $img, $width, $band_y )
            : [ 10, 10, 14 ];
        $band_color = imagecolorallocate( $img, $br, $bg_ch, $bb );
        imagefilledrectangle( $img, 0, $band_y, $width, $height, $band_color );
        // Gradient : photo → bande sur 120px (60px dans la photo, 60px dans la bande)
        $grad_h     = 120;
        $grad_start = $band_y - 60;
        for ( $dy = 0; $dy < $grad_h; $dy++ ) {
            $alpha = (int) round( 127 - ( 127 * $dy / $grad_h ) );
            $fade  = imagecolorallocatealpha( $img, $br, $bg_ch, $bb, $alpha );
            imagefilledrectangle( $img, 0, $grad_start + $dy, $width, $grad_start + $dy, $fade );
        }

        // ── Accent bar (very bottom) ──────────────────────────────────────────
        imagefilledrectangle( $img, 0, $height - 6, $width, $height, $accent );

        // ── Logo AI (top-left, on photo) ──────────────────────────────────────
        if ( $this->logo_ai_id > 0 ) {
            $this->overlay_logo( $img, $this->logo_ai_id, 24, 24, 80, 80 );
        }

        // ── Category badge (centered, top of black band) ──────────────────────
        $category = strtoupper( (string) ( $article['category'] ?? '' ) );
        if ( $category ) {
            $this->draw_category_badge( $img, $category, $width, $band_y + 20, $accent, $text_color );
        }

        // ── Title (large, centered, uppercase, 2nd line in accent) ────────────
        $title   = strtoupper( (string) ( $article['title_fr'] ?? '' ) );
        $title_y = $band_y + ( $category ? 74 : 44 );
        $this->draw_centered_title( $img, $title, $width, $title_y, 58, $text_color, $accent );

        // ── Banner logo or site name (bottom center) ───────────────────────────
        if ( $this->logo_banner_id > 0 ) {
            $this->overlay_logo_centered( $img, $this->logo_banner_id, $width, $height - 56, 220, 48 );
        } elseif ( $this->site_name ) {
            $this->draw_text_centered( $img, $this->site_name, $width, $height - 52, 18, $text_color, true );
        }

        // ── Save ──────────────────────────────────────────────────────────────
        $tmp = get_temp_dir() . 'ana_img_' . uniqid() . '.jpg';
        $ok  = imagejpeg( $img, $tmp, 90 );
        imagedestroy( $img );

        return $ok ? $tmp : '';
    }

    // ── Drawing helpers ───────────────────────────────────────────────────────

    /**
     * Centered category badge with decorative lines on each side.
     */
    private function draw_category_badge( $img, string $text, int $canvas_w, int $y, $accent, $text_color ): void {
        $font      = $this->ttf_font_bold ?: $this->ttf_font_regular;
        $font_size = 14;
        $pad_h     = 16;
        $pad_v     = 8;
        $badge_h   = $font_size + $pad_v * 2;

        if ( $font ) {
            $bbox    = imagettfbbox( $font_size, 0, $font, $text );
            $text_w  = abs( $bbox[2] - $bbox[0] );
        } else {
            $text_w = imagefontwidth( 3 ) * strlen( $text );
        }

        $badge_w = $text_w + $pad_h * 2;
        $bx      = (int) ( ( $canvas_w - $badge_w ) / 2 );
        $line_cy = $y + (int) ( $badge_h / 2 );
        $margin  = 44;
        $gap     = 14;

        // Horizontal lines left and right
        imagefilledrectangle( $img, $margin, $line_cy - 1, $bx - $gap, $line_cy + 1, $accent );
        imagefilledrectangle( $img, $bx + $badge_w + $gap, $line_cy - 1, $canvas_w - $margin, $line_cy + 1, $accent );

        // Badge fill
        imagefilledrectangle( $img, $bx, $y, $bx + $badge_w, $y + $badge_h, $accent );

        // Badge text
        if ( $font ) {
            $tx       = $bx + $pad_h;
            $baseline = $y + $pad_v + (int) round( $font_size * 0.88 );
            imagettftext( $img, $font_size, 0, $tx, $baseline, $text_color, $font, $text );
        } else {
            imagestring( $img, 3, $bx + $pad_h, $y + $pad_v, $text, $text_color );
        }
    }

    /**
     * Multi-line centered title. Second line (index 1) is drawn in accent color.
     */
    private function draw_centered_title( $img, string $text, int $canvas_w, int $y, int $size, $color, $accent ): void {
        $font   = $this->ttf_font_bold ?: $this->ttf_font_regular;
        $margin = 60;

        if ( $font && function_exists( 'imagettftext' ) ) {
            $lines    = $this->wrap_text_ttf( $text, $font, $size, $canvas_w - $margin * 2 );
            $line_h   = (int) round( $size * 1.15 );
            $stroke   = imagecolorallocate( $img, 0, 0, 0 );
            $baseline = $y + (int) round( $size * 0.88 );
            // 3 couleurs logo : rouge, bleu foncé, orange
            $logo_accents = [
                imagecolorallocate( $img, 230, 51, 41 ),
                imagecolorallocate( $img, 22, 58, 130 ),
                imagecolorallocate( $img, 245, 148, 29 ),
            ];
            // Trouver les 3 mots les plus importants (les plus longs hors mots vides)
            $stop = [ 'DE','DU','LA','LE','LES','UN','UNE','DES','ET','EN','AU','AUX',
                      'PAR','SUR','DANS','AVEC','POUR','QUE','QUI','EST','SE','SA',
                      'SON','SES','IL','ELLE','ILS','ELLES','CE','CES','ON','NE',
                      'PAS','PLUS','MAIS','OU','SI','CAR','NI','DONT','A','À','L' ];
            $scored = [];
            foreach ( explode( ' ', $text ) as $w ) {
                $clean = rtrim( $w, "',." );
                if ( ! in_array( $clean, $stop, true ) && mb_strlen( $clean ) >= 4 ) {
                    $scored[ $w ] = mb_strlen( $clean );
                }
            }
            arsort( $scored );
            $highlights = array_keys( array_slice( $scored, 0, 3, true ) );
            // Assigner une couleur à chacun dans l'ordre d'apparition dans le titre
            $word_colors = [];
            $ci = 0;
            foreach ( explode( ' ', $text ) as $w ) {
                if ( in_array( $w, $highlights, true ) && ! isset( $word_colors[ $w ] ) ) {
                    $word_colors[ $w ] = $logo_accents[ $ci % 3 ];
                    $ci++;
                }
            }

            foreach ( $lines as $line ) {
                $bbox = imagettfbbox( $size, 0, $font, $line );
                $tw   = abs( $bbox[2] - $bbox[0] );
                $wx   = (int) ( ( $canvas_w - $tw ) / 2 );
                $words_in_line = explode( ' ', $line );

                foreach ( $words_in_line as $j => $w ) {
                    $c    = $word_colors[ $w ] ?? $color;
                    $draw = $w . ( $j < count( $words_in_line ) - 1 ? ' ' : '' );
                    foreach ( [ [-1,0],[1,0],[0,-1],[0,1] ] as [$dx,$dy] ) {
                        imagettftext( $img, $size, 0, $wx + $dx, $baseline + $dy, $stroke, $font, $draw );
                    }
                    imagettftext( $img, $size, 0, $wx, $baseline, $c, $font, $draw );
                    $wb  = imagettfbbox( $size, 0, $font, $draw );
                    $wx += abs( $wb[2] - $wb[0] );
                }
                $baseline += $line_h;
            }
            return;
        }

        // Bitmap fallback
        $gd     = 5;
        $cw     = imagefontwidth( $gd );
        $lh     = imagefontheight( $gd ) + 10;
        $max    = max( 10, (int) floor( ( $canvas_w - $margin * 2 ) / $cw ) );
        $words  = explode( ' ', $text );
        $line   = ''; $lines = [];
        foreach ( $words as $word ) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            if ( strlen( $test ) > $max && $line !== '' ) { $lines[] = $line; $line = $word; }
            else { $line = $test; }
        }
        if ( $line !== '' ) $lines[] = $line;

        $cy = $y;
        foreach ( $lines as $l ) {
            $tw = strlen( $l ) * $cw;
            $x  = (int) ( ( $canvas_w - $tw ) / 2 );
            imagestring( $img, $gd, $x, $cy, $l, $color );
            $cy += $lh;
        }
    }

    /**
     * Single centered line with optional shadow.
     */
    private function draw_text_centered( $img, string $text, int $canvas_w, int $y, int $size, $color, bool $bold = false ): void {
        $font = $bold ? ( $this->ttf_font_bold ?: $this->ttf_font_regular ) : $this->ttf_font_regular;

        if ( $font && function_exists( 'imagettftext' ) ) {
            $bbox     = imagettfbbox( $size, 0, $font, $text );
            $tw       = abs( $bbox[2] - $bbox[0] );
            $x        = (int) ( ( $canvas_w - $tw ) / 2 );
            $baseline = $y + (int) round( $size * 0.88 );
            $shadow   = imagecolorallocatealpha( $img, 0, 0, 0, 55 );
            imagettftext( $img, $size, 0, $x + 2, $baseline + 2, $shadow, $font, $text );
            imagettftext( $img, $size, 0, $x,     $baseline,     $color,  $font, $text );
            return;
        }

        $gd = match ( true ) { $size >= 20 => 4, $size >= 14 => 3, default => 2 };
        $tw = imagefontwidth( $gd ) * strlen( $text );
        $x  = (int) ( ( $canvas_w - $tw ) / 2 );
        imagestring( $img, $gd, $x, $y, $text, $color );
    }

    // ── Internal utilities ────────────────────────────────────────────────────

    /**
     * Sample average color from the photo zone and return a heavily darkened version
     * suitable for the band background.
     */
    private function sample_dominant_dark( $img, int $w, int $h ): array {
        $r = $g = $b = $n = 0;
        $step = 28;
        for ( $y = 0; $y < $h; $y += $step ) {
            for ( $x = 0; $x < $w; $x += $step ) {
                $rgb  = imagecolorat( $img, $x, $y );
                $r   += ( $rgb >> 16 ) & 0xFF;
                $g   += ( $rgb >>  8 ) & 0xFF;
                $b   +=   $rgb         & 0xFF;
                $n++;
            }
        }
        if ( $n === 0 ) return [ 10, 10, 14 ];
        // Darken to ~20% brightness — dark enough for white text, tinted from photo
        return [
            max( 8, (int) ( ( $r / $n ) * 0.20 ) ),
            max( 8, (int) ( ( $g / $n ) * 0.20 ) ),
            max( 8, (int) ( ( $b / $n ) * 0.20 ) ),
        ];
    }

    private function wrap_text_ttf( string $text, string $font, float $size, int $max_w, int $max_lines = 4 ): array {
        $words = explode( ' ', $text );
        $lines = []; $line = '';
        foreach ( $words as $word ) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            $bbox = imagettfbbox( $size, 0, $font, $test );
            if ( abs( $bbox[2] - $bbox[0] ) > $max_w && $line !== '' ) {
                $lines[] = $line; $line = $word;
                if ( count( $lines ) >= $max_lines - 1 ) { $lines[] = $line; $line = ''; break; }
            } else {
                $line = $test;
            }
        }
        if ( $line !== '' ) $lines[] = $line;
        return array_slice( $lines, 0, $max_lines );
    }

    private function apply_photo_background( $img, string $url, int $w, int $h, $fallback ): void {
        $r = wp_remote_get( $url, [ 'timeout' => 8, 'sslverify' => false ] );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return;

        $data   = wp_remote_retrieve_body( $r );
        $source = @imagecreatefromstring( $data );
        if ( ! $source ) return;

        $sw = imagesx( $source ); $sh = imagesy( $source );
        $ratio_src = $sw / $sh;
        $ratio_dst = $w / $h;

        if ( $ratio_src > $ratio_dst ) { $new_h = $h; $new_w = (int) round( $h * $ratio_src ); }
        else { $new_w = $w; $new_h = (int) round( $w / $ratio_src ); }

        $ox = (int) round( ( $new_w - $w ) / 2 );
        $oy = (int) round( ( $new_h - $h ) / 2 );

        imagecopyresampled(
            $img, $source,
            0, 0,
            (int) ( $ox * $sw / $new_w ), (int) ( $oy * $sh / $new_h ),
            $w, $h,
            (int) ( $w * $sw / $new_w ), (int) ( $h * $sh / $new_h )
        );
        imagedestroy( $source );
    }

    private function overlay_logo( $img, int $media_id, int $x, int $y, int $max_w, int $max_h ): void {
        $path = get_attached_file( $media_id );
        if ( ! $path || ! file_exists( $path ) ) return;

        $ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $source = match ( $ext ) {
            'jpg', 'jpeg' => @imagecreatefromjpeg( $path ),
            'png'         => @imagecreatefrompng( $path ),
            'gif'         => @imagecreatefromgif( $path ),
            default       => false,
        };
        if ( ! $source ) return;

        $sw = imagesx( $source ); $sh = imagesy( $source );
        $scale = min( $max_w / $sw, $max_h / $sh );
        $dw = (int) round( $sw * $scale ); $dh = (int) round( $sh * $scale );
        imagecopyresampled( $img, $source, $x, $y, 0, 0, $dw, $dh, $sw, $sh );
        imagedestroy( $source );
    }

    private function overlay_logo_centered( $img, int $media_id, int $canvas_w, int $y, int $max_w, int $max_h ): void {
        $path = get_attached_file( $media_id );
        if ( ! $path || ! file_exists( $path ) ) return;

        $ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $source = match ( $ext ) {
            'jpg', 'jpeg' => @imagecreatefromjpeg( $path ),
            'png'         => @imagecreatefrompng( $path ),
            'gif'         => @imagecreatefromgif( $path ),
            default       => false,
        };
        if ( ! $source ) return;

        $sw = imagesx( $source ); $sh = imagesy( $source );
        $scale = min( $max_w / $sw, $max_h / $sh );
        $dw = (int) round( $sw * $scale ); $dh = (int) round( $sh * $scale );
        $x  = (int) ( ( $canvas_w - $dw ) / 2 );
        imagecopyresampled( $img, $source, $x, $y, 0, 0, $dw, $dh, $sw, $sh );
        imagedestroy( $source );
    }

    private function maybe_download_fonts(): void {
        $dir = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/fonts/';
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );

        $fonts = [
            'font-bold.ttf' => 'https://github.com/dejavu-fonts/dejavu-fonts/raw/master/ttf/DejaVuSans-Bold.ttf',
            'font.ttf'      => 'https://github.com/dejavu-fonts/dejavu-fonts/raw/master/ttf/DejaVuSans.ttf',
        ];

        foreach ( $fonts as $filename => $url ) {
            $dest = $dir . $filename;
            if ( file_exists( $dest ) ) continue;
            $response = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => true ] );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) continue;
            file_put_contents( $dest, wp_remote_retrieve_body( $response ) );
        }
    }

    private function find_ttf_font( bool $bold ): string {
        $plugin_dir = plugin_dir_path( dirname( __FILE__ ) );

        $candidates = $bold ? [
            $plugin_dir . 'assets/fonts/font-bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/truetype/lato/Lato-Bold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
        ] : [
            $plugin_dir . 'assets/fonts/font.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-R.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/Library/Fonts/Arial.ttf',
        ];

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) ) return $path;
        }
        return '';
    }

    private function hex2rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
    }
}
