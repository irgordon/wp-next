<?php
/** Native submitters stay associated with the existing Page form. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
global $post;
$design_page_save = wp_design_page_save_button( $post['status'] );
?>
<button type="submit" name="action" value="<?php echo esc_attr( $design_page_save['value'] ); ?>" class="<?php echo esc_attr( $design_page_save['class'] ); ?>" data-page-save><?php echo esc_html( $design_page_save['label'] ); ?></button>
<?php if ( wp_design_page_show_publish( $post['status'], current_user_can( 'publish_pages' ) ) ) : ?>
<button type="submit" name="action" value="publish_page" data-page-publish>Publish Page</button>
<?php endif; ?>
