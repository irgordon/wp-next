<?php
/** Server-rendered block previews for the visual editor. The public renderer is the only renderer. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
wp_require_post( 'block-preview' );
if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) && ! current_user_can( 'administrator' ) ) {
	http_response_code( 403 );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'error' => 'Preview requires an editing role.' ] );
	exit;
}
$normalized = wp_normalize_content( wp_input( $_POST, 'content' ), true );
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
if ( is_wp_error( $normalized ) ) {
	http_response_code( 422 );
	echo wp_json_encode( [ 'error' => $normalized->get_error_message() ] );
	exit;
}
$document = json_decode( (string) $normalized, true );
echo wp_json_encode( [ 'blocks' => wp_preview_block_tree( $document['blocks'] ) ] );

/** Serialize the public renderer's output at every nested block boundary. */
function wp_preview_block_tree( array $blocks ): array
{
	$output = [];
	foreach ( $blocks as $block ) {
		$output[] = [
			'html'    => wp_render_blocks( [ $block ] ),
			'columns' => array_map( 'wp_preview_block_tree', $block['columns'] ?? [] )
		];
	}
	return $output;
}
