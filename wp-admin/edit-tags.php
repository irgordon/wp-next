<?php
/** Category hierarchy and flat tag administration. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'edit_others_posts' );
$taxonomy = 'post_tag' === wp_input( $_GET, 'taxonomy' ) ? 'post_tag' : 'category';
$admin_page_title = 'category' === $taxonomy ? 'Categories' : 'Tags';
$admin_section = $taxonomy;
$singular = 'category' === $taxonomy ? 'category' : 'tag';
$error = '';
$message = '';
$form = [
	'id'                => '',
	'name'              => '',
	'slug'              => '',
	'description'       => '',
	'parent_id'         => '',
	'expected_revision' => ''
];
$id = (int) wp_input( $_GET, 'term' );
if ( $id > 0 ) {
	$term = $wpdb->get_row( 'SELECT * FROM terms WHERE id=:id AND taxonomy=:taxonomy', [
		':id'       => $id,
		':taxonomy' => $taxonomy
	] );
	if ( null === $term ) {
		wp_die( 'Term not found.', 'Not found', [ 'response' => 404 ] ); 
	}
	foreach ( $form as $key => $value ) {
		$form[ $key ] = (string) ( 'expected_revision' === $key ? $term['revision'] : $term[ $key ] ); 
	}
}
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'terms:' . $taxonomy );
	foreach ( $form as $key => $value ) {
		$form[ $key ] = wp_input( $_POST, $key ); 
	}
	$target = (int) $form['id'] > 0 ? $wpdb->get_row( 'SELECT taxonomy FROM terms WHERE id=:id', [ ':id' => (int) $form['id'] ] ) : null;
	if ( null !== $target && $target['taxonomy'] !== $taxonomy ) {
		wp_die( 'Taxonomy mismatch.', 'Request rejected', [ 'response' => 400 ] ); 
	}
	if ( 'delete' === wp_input( $_POST, 'action' ) ) {
		$result = 'yes' === wp_input( $_POST, 'confirm_delete' ) ? wp_delete_term( (int) $form['id'], (int) $form['expected_revision'] ) : new WP_Error( 'confirmation_required', 'Confirm deletion before continuing.' );
	} else {
		$result = wp_save_term( $form + [ 'taxonomy' => $taxonomy ] ); 
	}
	if ( is_wp_error( $result ) ) {
		$error = $result->get_error_message(); 
	} else {
		wp_safe_redirect( 'edit-tags.php?taxonomy=' . $taxonomy . '&saved=1' ); 
	}
}
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( isset( $_GET['saved'] ) ? ucfirst( $singular ) . ' saved.' : '' );
?>
<div class="settings-grid"><section class="panel"><h2><?php echo ( $id > 0 ? 'Edit ' : 'Add ' ) . $singular; ?></h2><form method="post"><?php wp_nonce_field( 'terms:' . $taxonomy ); ?><input type="hidden" name="id" value="<?php echo esc_attr( $form['id'] ); ?>" /><input type="hidden" name="expected_revision" value="<?php echo esc_attr( $form['expected_revision'] ); ?>" />
<?php wp_admin_field( 'name', 'Name', $form['name'] );
wp_admin_field( 'slug', 'Slug', $form['slug'] ); ?>
<div class="form-field"><label for="description">Description</label><textarea name="description" id="description"><?php echo esc_textarea( $form['description'] ); ?></textarea></div>
<?php if ( 'category' === $taxonomy ) :
	?><div class="form-field"><label for="parent_id">Parent category</label><select name="parent_id" id="parent_id"><option value="0">None</option><?php foreach ( wp_get_terms( 'category' ) as $parent ) :
	if ( (int) $form['id'] === (int) $parent['id'] ) {
		continue; 
	} ?><option value="<?php echo (int) $parent['id']; ?>" <?php echo (int) $form['parent_id'] === (int) $parent['id'] ? 'selected' : ''; ?>><?php echo esc_html( $parent['name'] ); ?></option><?php 
    endforeach; ?></select></div><?php 
endif; ?>
<button type="submit">Save <?php echo esc_html( $singular ); ?></button> <a href="edit-tags.php?taxonomy=<?php echo esc_attr( $taxonomy ); ?>">Add another</a></form></section>
<section class="panel"><h2>Existing <?php echo esc_html( strtolower( $admin_page_title ) ); ?></h2><p><?php echo 'category' === $taxonomy ? 'Counts include directly assigned published Posts. Deleting a category moves children to the root; Posts without another category receive Uncategorized. Posts are never deleted.' : 'Tags group related Posts. Counts include published Posts only. Deleting a tag removes assignments and keeps the Posts.'; ?></p>
<?php foreach ( wp_get_terms( $taxonomy ) as $term ) :
	?><article class="term-row"><h3><a href="edit-tags.php?taxonomy=<?php echo esc_attr( $taxonomy ); ?>&amp;term=<?php echo (int) $term['id']; ?>"><?php echo esc_html( $term['name'] ); ?></a></h3><p><?php echo esc_html( $term['slug'] ); ?> · <?php echo (int) $term['post_count']; ?> published Posts</p><a href="<?php echo esc_url( wp_term_url( $term ) ); ?>">View archive</a>
	<?php if ( 'category' !== $taxonomy || 'uncategorized' !== $term['slug'] ) :
		?><details><summary>Delete <?php echo esc_html( $term['name'] ); ?></summary><form method="post"><?php wp_nonce_field( 'terms:' . $taxonomy ); ?><input type="hidden" name="action" value="delete" /><input type="hidden" name="id" value="<?php echo (int) $term['id']; ?>" /><input type="hidden" name="expected_revision" value="<?php echo (int) $term['revision']; ?>" /><label class="inline-checkbox"><input type="checkbox" name="confirm_delete" value="yes" required /> Delete this <?php echo esc_html( $singular ); ?> and its assignments</label><button type="submit" class="button-danger">Delete <?php echo esc_html( $singular ); ?></button></form></details><?php 
    endif; ?></article><?php 
endforeach; ?></section></div>
<?php require __DIR__ . '/admin-footer.php';
