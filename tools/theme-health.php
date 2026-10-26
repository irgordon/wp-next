<?php
/** Isolated trusted-theme rendering probe. Not a public endpoint. */
declare( strict_types=1 );
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
$config = json_decode( (string) stream_get_contents( STDIN ), true, 32, JSON_THROW_ON_ERROR );
$GLOBALS['wp_design_override'] = ['active_theme'=>$config['active_theme']];
require dirname( __DIR__ ) . '/wp-includes/bootstrap.php';
$config = wp_design_validate( $config );
$GLOBALS['wp_design_override'] = wp_design_override( $config ); $GLOBALS['wp_design_preview'] = $config;
$directory = wp_theme_directory(); $manifest = wp_theme_manifest( $config['active_theme'] );
foreach ( glob( $directory . '/*.php' ) ?: [] as $file ) { token_get_all( (string) file_get_contents( $file ), TOKEN_PARSE ); }
$probe = [ 'id' => 0, 'title' => 'Theme health probe', 'slug' => 'health', 'post_type' => 'page', 'content_raw' => '<p>Health check</p>', 'content_rendered' => '', 'revision' => 1, 'rendered_revision' => 0, 'excerpt' => '', 'featured_media_id' => null, 'metadata' => '{}' ];
foreach ( $manifest['templates'] as $template ) {
    wp_set_loop_posts( [ $probe ] ); $GLOBALS['wp_current_post'] = $probe;
    ob_start(); require $directory . '/' . $template; $html = ob_get_clean();
    if ( ! is_string( $html ) || strlen( $html ) > 131072 || ! str_contains( $html, 'Health check' ) ) { throw new RuntimeException( 'Template did not render content.' ); }
}
echo 'THEME_HEALTH_OK';
