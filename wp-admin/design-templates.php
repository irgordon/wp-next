<?php
/** Read-only inventory of the existing published-theme template allowlist. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
?>
<section class="panel" id="design-templates" data-design-panel="templates"><h2>Curated Page Templates</h2>
<p>These choices belong to the current published theme. Assign a template in each Page's editing settings.</p>
<ul class="design-template-list">
<?php foreach ( wp_curated_templates() as $design_template_id => $design_template_file ) : ?>
<li data-curated-template="<?php echo esc_attr( $design_template_id ); ?>"><?php echo esc_html( ucfirst( $design_template_id ) ); ?></li>
<?php endforeach; ?>
</ul><p class="description">Template creation and source editing remain outside this workspace.</p>
<p><a href="edit.php?post_type=page">Choose a Page to assign a template →</a></p></section>
