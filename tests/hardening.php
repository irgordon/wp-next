<?php
/** Concrete image, content policy and address regression fixtures. */
declare( strict_types=1 );
require_once __DIR__ . '/../wp-includes/class-wp-error.php';
require_once __DIR__ . '/../wp-includes/formatting.php';
require_once __DIR__ . '/../wp-includes/theme.php';
require_once __DIR__ . '/../wp-includes/content.php';
require_once __DIR__ . '/../wp-includes/image.php';
$count = 0;
function check( bool $passed, string $label ): void { global $count; if ( ! $passed ) { throw new RuntimeException( $label ); } ++$count; echo 'PASS: ' . $label . "\n"; }
$temp = sys_get_temp_dir() . '/wp-image-' . bin2hex( random_bytes( 8 ) );
mkdir( $temp, 0700 );
define( 'WP_VAR_DIR', $temp );
try {
	$local = '/wp-content/uploads/aa/' . str_repeat( 'a', 64 ) . '.png';
	$html = wp_kses_post( '<p><img src="https://tracker.example/pixel.png" /><img src="//tracker.example/pixel.png" /><img src="data:image/png;base64,AAAA" /><img src="/wp-admin/login.php" /><img src="' . $local . '" alt="Local" /></p>' );
	check( 1 === substr_count( $html, '<img' ) && str_contains( $html, $local ), 'Remote, inline and arbitrary application images are removed; local uploads survive' );
	$html = wp_kses_post( '<a href="https://example.test" target="_BLANK" rel="opener ugc">Example</a>' );
	check( str_contains( $html, 'rel="ugc noopener"' ) && ! str_contains( $html, 'rel="opener' ), 'New-window links cannot opt into opener access' );
	foreach ( [ '/bad%escape', '/x%2fy', '/x%5cy', '/x/../y', '/%00x', 'https://evil.example/x' ] as $path ) {
		check( is_wp_error( wp_normalize_request_path( $path ) ), 'Ambiguous or unsafe path rejected: ' . $path );
	}
	check( '/hello-world/' === wp_normalize_request_path( '//%68ello-world/' ), 'Duplicate slashes and unreserved encoding retain the intended path' );
	check( '/hello-world' === wp_normalize_request_path( '/hello-world/.' ), 'Terminal dot segment normalizes to the intended resource' );
	$image = imagecreatetruecolor( 2, 3 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 255, 128, 0 ) );
	imagepng( $image, $temp . '/source.png' );
	$png = file_get_contents( $temp . '/source.png' );
	$private = 'Description' . "\0" . 'PRIVATE_GPS_NOTE_<?php echo 1; ?>';
	$chunk = 'tEXt' . $private;
	file_put_contents( $temp . '/metadata.png', substr( $png, 0, 33 ) . pack( 'N', strlen( $private ) ) . $chunk . pack( 'N', crc32( $chunk ) ) . substr( $png, 33 ) );
	$result = wp_normalize_image( $temp . '/metadata.png' );
	check( is_array( $result ) && ! str_contains( file_get_contents( $result['path'] ), 'PRIVATE_GPS_NOTE' ) && ! str_contains( file_get_contents( $result['path'] ), '<?php' ), 'PNG ancillary metadata and embedded PHP text are removed from stored output' );
	check( is_array( $result ) && false !== imagecreatefrompng( $result['path'] ) && 2 === $result['width'] && 3 === $result['height'], 'Re-encoded PNG fully decodes with its dimensions intact' );
	file_put_contents( $temp . '/truncated.png', substr( $png, 0, 33 ) );
	check( is_wp_error( wp_normalize_image( $temp . '/truncated.png' ) ), 'Header-only PNG is rejected rather than stored' );
	imagejpeg( $image, $temp . '/source.jpg', 90 );
	$secret = 'PRIVATE_GPS_AND_ARTIST';
	$tiff = "II\x2a\0" . pack( 'Vv', 8, 2 ) . pack( 'vvVv', 274, 3, 1, 6 ) . "\0\0" . pack( 'vvVVV', 315, 2, strlen( $secret ) + 1, 38, 0 ) . $secret . "\0";
	$exif = "Exif\0\0" . $tiff;
	$jpeg = file_get_contents( $temp . '/source.jpg' );
	file_put_contents( $temp . '/metadata.jpg', substr( $jpeg, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg, 2 ) );
	check( 6 === exif_read_data( $temp . '/metadata.jpg' )['Orientation'], 'JPEG fixture contains a real EXIF orientation tag' );
	$result = wp_normalize_image( $temp . '/metadata.jpg' );
	check( is_array( $result ) && 3 === $result['width'] && 2 === $result['height'] && ! str_contains( file_get_contents( $result['path'] ), $secret ) && ! isset( exif_read_data( $result['path'] )['Orientation'] ), 'JPEG metadata is removed after orientation is baked into the pixels' );
	imagegif( $image, $temp . '/source.gif' );
	$result = wp_normalize_image( $temp . '/source.gif' );
	check( is_array( $result ) && false !== imagecreatefromgif( $result['path'] ), 'Static GIF remains supported' );
	$gif = file_get_contents( $temp . '/source.gif' );
	$offset = 13 + ( ( ord( $gif[10] ) & 128 ) ? 3 * 2 ** ( ( ord( $gif[10] ) & 7 ) + 1 ) : 0 );
	$frame = substr( $gif, $offset, -1 );
	file_put_contents( $temp . '/animated.gif', substr( $gif, 0, -1 ) . $frame . ';' );
	check( is_wp_error( wp_normalize_image( $temp . '/animated.gif' ) ), 'Multi-frame GIF is rejected instead of silently flattened' );
	imagewebp( $image, $temp . '/source.webp', IMG_WEBP_LOSSLESS );
	$result = wp_normalize_image( $temp . '/source.webp' );
	check( is_array( $result ) && false !== imagecreatefromwebp( $result['path'] ), 'Static WebP round-trips through lossless normalization' );
	echo $count . " hardening checks passed.\n";
} finally {
	foreach ( glob( $temp . '/*' ) ?: [] as $file ) { unlink( $file ); }
	rmdir( $temp );
}
