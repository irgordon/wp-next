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
$blocks = [];
foreach ( $document['blocks'] as $block ) {
	$item = [ 'html' => wp_render_blocks( [ $block ] ) ];
	if ( 'columns' === $block['type'] ) {
		$item['columns'] = [];
		foreach ( $block['columns'] as $children ) {
			$column = [];
			foreach ( $children as $child ) {
				$column[] = wp_render_blocks( [ $child ] );
			}
			$item['columns'][] = $column;
		}
	}
	$blocks[] = $item;
}
echo wp_json_encode( [ 'blocks' => $blocks ] );
