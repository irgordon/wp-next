<?php
/** Shared form for creating and updating content. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
global $wpdb, $post, $post_type, $plural, $error, $form;
$is_new = null === $post;
$nonce_action = $is_new ? 'create:' . $post_type : 'post:' . $post['id'];
$action_url = $is_new ? 'post-new.php?post_type=' . $post_type : 'post.php?post=' . $post['id'];
$action_url .= ! empty( $admin_builder_mode ) ? '&editor=visual' : '';
$action_url .= ! empty( $admin_design_editor ) ? wp_design_editor_query( (int) $post['id'] ) : '';
wp_admin_notice( $error, true );
if ( isset( $_GET['updated'] ) || isset( $_GET['created'] ) ) {
	wp_admin_notice( 'Content saved.' );
}
$media_items = $wpdb->get_results( "SELECT * FROM media WHERE kind='image' AND state='ready' AND (id=:selected OR id IN (SELECT id FROM media ORDER BY id DESC LIMIT 100)) ORDER BY id DESC", [ ':selected' => (int) $form['featured_media_id'] ] );
?>
<div class="editor-grid"><form id="post-editor" class="editor-form" method="post" action="<?php echo esc_url( $action_url ); ?>" data-design-embed="<?php echo ! empty( $admin_design_editor ) ? '1' : '0'; ?>" data-editor-view="<?php echo ! empty( $admin_builder_mode ) ? 'visual' : 'form'; ?>" data-draft-key="<?php echo esc_attr( get_current_user_id() . ':' . ( $post['id'] ?? 'new-' . $post_type ) ); ?>" data-saved="<?php echo isset( $_GET['updated'] ) || isset( $_GET['created'] ) ? '1' : '0'; ?>">
<?php wp_nonce_field( $nonce_action ); ?><input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
<?php if ( ! $is_new ) :
	?><input type="hidden" name="ID" value="<?php echo (int) $post['id']; ?>" /><input type="hidden" name="expected_revision" value="<?php echo esc_attr( $form['expected_revision'] ); ?>" /><?php
endif; ?>
<div id="draft-recovery" class="notice hidden" role="status"><p>A draft from this browser tab is available.</p><button type="button" id="restore-draft">Restore draft</button> <button type="button" id="discard-draft">Discard draft</button></div>
<div class="editor-main"><section class="panel">
<div class="form-field wide"><label for="post_title">Title</label><input id="post_title" name="post_title" value="<?php echo esc_attr( $form['post_title'] ); ?>" required /></div>
<div class="form-field wide"><label for="post_name">Slug</label><input id="post_name" aria-describedby="slug-help" name="post_name" value="<?php echo esc_attr( $form['post_name'] ); ?>" /><p id="slug-help" class="description">Letters, numbers, underscores, and hyphens. Unicode titles are supported. Duplicate slugs receive a numeric suffix.</p></div>
<div class="editor-mode" role="group" aria-label="Editor mode"><button type="button" id="mode-form" aria-pressed="true">Form editor</button><button type="button" id="mode-visual" aria-pressed="false">Visual editor</button><span class="canvas-width" id="canvas-width" hidden><button type="button" id="width-desktop" aria-pressed="true">Desktop</button><button type="button" id="width-mobile" aria-pressed="false">Mobile</button></span><p id="editor-state" class="description" role="status"></p></div><input type="hidden" id="block-preview-nonce" value="<?php echo esc_attr( wp_nonce( 'block-preview' ) ); ?>" />
<div id="wp-page-builder-root" data-media-url="media.php?format=json" data-documents-url="media.php?format=json&kind=documents"></div><div id="wp-visual-canvas" class="visual-canvas" hidden></div><p id="builder-status" class="description" role="status" aria-live="polite"></p>
<details><summary>Raw block document</summary><label for="post_content">Version 1 block document or legacy HTML</label><textarea id="post_content" name="post_content" class="raw-content"><?php echo esc_textarea( $form['post_content'] ); ?></textarea><p class="description">Legacy content remains an HTML block. Unsupported JSON block types are rejected rather than discarded.</p></details>
</section></div>
<aside class="editor-side"><section class="panel"><h2>Publish</h2><?php if ( 'page' === $post_type ) :
	?><label for="page-template">Page template</label><select id="page-template" name="page_template"><?php foreach ( wp_curated_templates() as $template_id => $template_file ) :
	?><option value="<?php echo esc_attr($template_id); ?>" <?php echo ($form['page_template'] ?? 'standard') === $template_id ? 'selected' : ''; ?>><?php echo esc_html(ucfirst($template_id)); ?></option><?php 
    endforeach; ?></select><?php 
                                                                  endif; ?><div class="form-field"><label for="post_status">Status</label><select id="post_status" name="post_status">
<?php $status_options = [
	'draft'     => 'Draft',
	'published' => 'Published',
	'archived'  => 'Archived'
];
if ( 'trashed' === $form['post_status'] ) {
	$status_options['trashed'] = 'Trashed';
}
foreach ( $status_options as $value => $label ) :
	if ( 'published' === $value && ! current_user_can( 'publish_' . $plural ) && 'published' !== $form['post_status'] ) {
		continue;
	} ?>
<option value="<?php echo esc_attr( $value ); ?>" <?php echo $form['post_status'] === $value ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option><?php
endforeach; ?></select></div>
<div class="form-field"><label for="featured_media_id">Featured image</label><select id="featured_media_id" name="featured_media_id"><option value="">None</option><?php foreach ( $media_items as $media ) :
	?><option data-url="<?php echo esc_url( wp_media_url( $media ) ); ?>" data-alt="<?php echo esc_attr( $media['alt_text'] ); ?>" value="<?php echo (int) $media['id']; ?>" <?php echo (string) $media['id'] === $form['featured_media_id'] ? 'selected' : ''; ?>><?php echo esc_html( $media['original_name'] ); ?></option><?php
																																								   endforeach; ?></select><button type="button" id="choose-featured-image">Choose from full library</button></div>
<?php wp_editor_term_fields( $post_type, $form['term_ids'] ); ?>
<div class="actions"><button type="submit" name="action" value="update">Save changes</button><?php if ( ! $is_new ) :
	?><a href="<?php echo esc_url( wp_home_url( '/preview/' . $post['id'] ) ); ?>" target="_blank" rel="noopener">Preview ↗</a><?php
																							 endif; ?></div>
<?php if ( ! $is_new ) :
	?><hr /><p class="description">Revision <?php echo (int) $post['revision']; ?> · <?php echo esc_html( $post_type ); ?></p><p><a href="<?php echo esc_url( 'post.php?post=' . $post['id'] ); ?>">Reload current version</a></p><p><a href="<?php echo esc_url( wp_post_url( $post ) ); ?>" target="_blank" rel="noopener">View public page ↗</a></p><?php
endif; ?>
<p><a href="edit.php?post_type=<?php echo esc_attr( $post_type ); ?>">Back to <?php echo esc_html( $plural ); ?></a></p></section><section class="panel"><h2>Summary</h2><label for="post_excerpt">Excerpt and search description</label><textarea id="post_excerpt" name="post_excerpt" rows="3"><?php echo esc_textarea( $form['post_excerpt'] ); ?></textarea></section><section class="panel" id="wp-visual-inspector-panel" hidden><h2>Block settings</h2><p class="description">Select a block on the canvas to change it here. The same settings appear in the form editor.</p><div id="wp-visual-inspector"></div></section></aside>
</form>
<?php if ( ! $is_new ) :
	$revisions = $wpdb->get_results( 'SELECT id,revision,created_at FROM post_revisions WHERE post_id=:id ORDER BY revision DESC LIMIT 30', [ ':id' => $post['id'] ] ); ?>
<section class="panel editor-revisions"><h2>Revision history</h2><p>Restoring creates a new revision. It never removes the current history.</p><?php foreach ( $revisions as $revision ) : ?>
<form class="inline-form" method="post" action="<?php echo esc_url( $action_url ); ?>"><?php wp_nonce_field( $nonce_action ); ?><input type="hidden" name="ID" value="<?php echo (int) $post['id']; ?>" /><input type="hidden" name="action" value="restore" /><input type="hidden" name="expected_revision" value="<?php echo (int) $post['revision']; ?>" /><input type="hidden" name="revision_id" value="<?php echo (int) $revision['id']; ?>" /><span>Revision <?php echo (int) $revision['revision']; ?> · <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $revision['created_at'] ) ); ?> UTC</span><button type="submit" class="secondary">Restore this revision</button></form><?php
																															                   endforeach;
																																			   if ( [] === $revisions ) {
																																				   echo '<p class="muted">History starts with the first saved change.</p>';
																																			   } ?></section>
<?php endif; ?>
</div>
<dialog id="media-picker" aria-labelledby="media-picker-title"><div class="toolbar"><h2 id="media-picker-title">Choose an image</h2><button type="button" id="close-media-picker">Close</button></div><div class="inline-form"><label for="media-picker-search">Search images</label><input id="media-picker-search" type="search" /><button id="media-picker-search-button" type="button">Search images</button></div><div id="media-picker-items" class="media-grid"></div><button id="media-picker-more" type="button" class="hidden">Load more images</button><p><a href="media.php" target="_blank" rel="noopener">Upload or manage media ↗</a></p></dialog>
<dialog id="document-picker" aria-labelledby="document-picker-title"><div class="toolbar"><h2 id="document-picker-title">Choose a document</h2><button type="button" id="close-document-picker">Close</button></div><div class="inline-form"><label for="document-picker-search">Search documents</label><input id="document-picker-search" type="search" /><button id="document-picker-search-button" type="button">Search documents</button></div><div id="document-picker-items" class="media-grid"></div><button id="document-picker-more" type="button" class="hidden">Load more documents</button><p><a href="media.php?kind=documents" target="_blank" rel="noopener">Upload or manage documents ↗</a></p></dialog>
<?php if ( ! empty( $admin_builder_mode ) ) : ?>
<template id="builder-canvas-head"><?php wp_head(); ?></template>
<?php endif; ?>
<script type="module" src="js/page-builder.js"></script>
