<?php
/** Focused design navigation; native form actions work without JavaScript. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
$design_draft_id = (int) ( $draft_id ?? 0 );
$design_error = (string) ( $error ?? '' );
$design_sections = [
	'theme'      => 'Theme',
	'styles'     => 'Styles',
	'navigation' => 'Navigation',
	'header'     => 'Header',
	'footer'     => 'Footer',
	'templates'  => 'Templates',
	'history'    => 'History'
];
$design_saved_state = '' !== $design_error ? 'unsaved' : ( $design_draft_id > 0 ? 'draft' : 'published' );
$design_state_labels = [
	'unsaved'   => 'Unsaved changes',
	'draft'     => 'Saved draft',
	'published' => 'Published design'
];
?>
<aside class="sidebar design-sidebar"><div class="sidebar-stick">
<div class="sidebar-header"><a class="brand" href="index.php">WordPress <strong>Next</strong></a><button type="button" class="menu-toggle" aria-controls="admin-navigation" aria-expanded="true">Menu</button></div>
<div class="design-sidebar-intro"><a href="index.php" class="design-exit">← Back to admin</a><h2>Site design</h2><p>Shape your site's appearance.</p></div>
<nav id="admin-navigation" class="design-context-nav" aria-label="Design workspace"><p class="design-nav-label">Design</p><ul>
<?php foreach ( $design_sections as $design_key => $design_label ) : ?>
<li><a href="#design-<?php echo esc_attr( $design_key ); ?>" data-design-target="<?php echo esc_attr( $design_key ); ?>"><?php echo esc_html( $design_label ); ?></a></li>
<?php endforeach; ?>
</ul><a class="design-code-link" href="file-editor.php">Code &amp; files ↗</a></nav>
<div class="design-primary-actions"><p class="design-save-state" role="status" aria-live="polite" data-design-saved-state="<?php echo esc_attr( $design_saved_state ); ?>"><?php echo esc_html( $design_state_labels[ $design_saved_state ] ); ?></p>
<button type="submit" class="secondary" form="design-form" name="design_action" value="draft">Save draft</button>
<button type="submit" form="design-form" name="design_action" value="publish">Publish design</button>
<?php if ( $design_draft_id > 0 ) : ?>
<a class="design-preview-link" href="<?php echo esc_url( wp_home_url( '/?design_preview=' . $design_draft_id . '&_wpnonce=' . wp_nonce( 'design-preview:' . $design_draft_id ) ) ); ?>" target="_blank" rel="noopener">Preview design <span aria-hidden="true">↗</span></a>
<?php else : ?>
<a class="design-preview-link" href="<?php echo esc_url( wp_home_url() ); ?>" target="_blank" rel="noopener">View published site ↗</a>
<?php endif; ?>
</div></div></aside>
