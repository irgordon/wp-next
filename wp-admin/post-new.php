<?php
/** New content controller. Failed input stays in the form. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
$post_type = wp_input( $_REQUEST, 'post_type', 'post' );
if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
	$post_type = 'post';
}
$plural = 'page' === $post_type ? 'pages' : 'posts';
wp_admin_require_cap( 'edit_' . $plural );
$error = '';
$post = null;
$form = [
    'page_template'     => 'standard',
	'term_ids'          => [],
	'post_title'        => '',
	'post_name'         => '',
	'post_content'      => '{"version":1,"blocks":[]}',
	'post_excerpt'      => '',
	'post_status'       => current_user_can( 'publish_' . $plural ) ? get_option( 'default_status', 'draft' ) : 'draft',
	'featured_media_id' => ''
];
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'create:' . $post_type );
	foreach ( $form as $key => $value ) {
		if ( 'term_ids' === $key ) {
			continue; 
		}
		$form[ $key ] = 'page_template' === $key ? wp_input( $_POST, $key, $value ) : wp_input( $_POST, $key );
	}
	$form['term_ids'] = is_array( $_POST['term_ids'] ?? [] ) ? $_POST['term_ids'] ?? [] : [ 'invalid' ];
	$result = wp_insert_post( $form + [ 'post_type' => $post_type ] );
	if ( is_wp_error( $result ) ) {
		$error = $result->get_error_message();
	} else {
		wp_safe_redirect( 'post.php?post=' . $result . '&created=1' );
	}
}
$admin_page_title = 'New ' . ( 'page' === $post_type ? 'page' : 'post' );
$admin_section = $post_type;
require __DIR__ . '/admin-header.php';
require __DIR__ . '/post-editor.php';
require __DIR__ . '/admin-footer.php';
