<?php
/** Read authorization precedes both editor rendering and mutation handling. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
$admin_builder_mode = 'visual' === wp_input( $_GET, 'editor' );
global $wpdb;
$id = (int) wp_input( $_GET, 'post', wp_input( $_POST, 'ID' ) );
$post = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $id ] );
if ( null === $post ) {
	wp_die( 'The requested content does not exist.', 'Not found', [ 'response' => 404 ] );
}
if ( ! current_user_can( 'edit_post', $id ) ) {
	wp_die( 'You cannot read or edit this draft.', 'Access denied', [ 'response' => 403 ] );
}
require_once __DIR__ . '/design-pages-functions.php';
require_once __DIR__ . '/design-page-publication.php';
$admin_design_editor = wp_design_editor_authorize( $id, $post['post_type'], $admin_builder_mode, $post['status'] );
$post_type = $post['post_type'];
$plural = 'page' === $post_type ? 'pages' : 'posts';
$error = '';
$form = [
    'page_template'     => (json_decode($post['metadata'] ?? '{}',true)['page_template'] ?? 'standard'),
	'social_image_id'   => (string) ( json_decode($post['metadata'] ?? '{}',true)['social_image_id'] ?? '' ),
	'term_ids'          => wp_post_term_ids( $id, $wpdb ),
	'post_title'        => $post['title'],
	'post_name'         => $post['slug'],
	'post_content'      => $post['content_raw'],
	'post_excerpt'      => $post['excerpt'],
	'post_status'       => $post['status'],
	'featured_media_id' => (string) ( $post['featured_media_id'] ?? '' ),
	'expected_revision' => (string) $post['revision']
];
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'post:' . $id );
	$action = wp_input( $_POST, 'action', 'update' );
	if ( 'restore' === $action ) {
		$revision = $wpdb->get_row( 'SELECT snapshot FROM post_revisions WHERE id=:revision AND post_id=:id', [
			':revision' => (int) wp_input( $_POST, 'revision_id' ),
			':id'       => $id
		] );
		if ( null === $revision ) {
			$error = 'The selected revision does not exist.';
		} else {
			$snapshot = json_decode( $revision['snapshot'], true );
			$form = [
                'page_template'     => (json_decode($snapshot['metadata'] ?? '{}',true)['page_template'] ?? 'standard'),
				'social_image_id'   => (string) ( json_decode($snapshot['metadata'] ?? '{}',true)['social_image_id'] ?? '' ),
				'term_ids'          => $snapshot['term_ids'] ?? wp_post_term_ids( $id, $wpdb ),
				'post_title'        => $snapshot['title'],
				'post_name'         => $snapshot['slug'],
				'post_content'      => $snapshot['content_raw'],
				'post_excerpt'      => $snapshot['excerpt'] ?? '',
				'post_status'       => $snapshot['status'],
				'featured_media_id' => (string) ( $snapshot['featured_media_id'] ?? '' ),
				'expected_revision' => wp_input( $_POST, 'expected_revision' )
			];
		}
	} else {
		foreach ( $form as $key => $value ) {
			if ( 'term_ids' === $key ) {
				continue; 
			}
			$form[ $key ] = 'page_template' === $key ? wp_input( $_POST, $key, $value ) : wp_input( $_POST, $key );
		}
		$form['term_ids'] = isset( $_POST['terms_present'] ) ? ( is_array( $_POST['term_ids'] ?? [] ) ? $_POST['term_ids'] ?? [] : [ 'invalid' ] ) : $form['term_ids'];
		if ( 'archive' === $action ) {
			$form['post_status'] = 'archived';
		}
		$form['post_status'] = wp_design_page_submission_status( $admin_design_editor, $action, $form['post_status'] );
	}
	if ( 'trashed' === $post['status'] && 'trashed' === $form['post_status'] ) {
		$error = 'This content is in the Trash. Restore it from the list before editing it.';
	}
	if ( '' === $error ) {
		$result = wp_update_post( $form + [ 'ID' => $id ] );
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
			$form['post_status'] = wp_design_page_failed_status( $admin_design_editor, $post['status'], $form['post_status'] );
		} else {
			wp_safe_redirect( 'post.php?post=' . $id . '&updated=1' . ( $admin_builder_mode ? '&editor=visual' : '' ) . ( $admin_design_editor ? wp_design_editor_query( $id ) . wp_design_page_result_query( $action ) : '' ) );
		}
	}
}
$admin_page_title = 'Edit ' . ( 'page' === $post_type ? 'page' : 'post' );
$admin_page_title = $admin_builder_mode ? 'Visual builder' : $admin_page_title;
$admin_section = $post_type;
require __DIR__ . '/admin-header.php';
require __DIR__ . '/post-editor.php';
if ( $admin_design_editor ) {
	echo '<script type="module" src="js/design-page-editor.js?v=' . (int) filemtime( __DIR__ . '/js/design-page-editor.js' ) . '"></script>';
}
require __DIR__ . '/admin-footer.php';
