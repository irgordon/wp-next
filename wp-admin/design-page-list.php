<?php
/** Page selection does not alter the design form's homepage value. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
$design_page_search = wp_input( $_GET, 'search' );
$design_page_status = wp_input( $_GET, 'status', 'all' );
$design_page_result = wp_design_pages_inventory( $design_page_search, $design_page_status, (int) wp_input( $_GET, 'page', '1' ) );
?>
<section class="panel design-pages" id="design-pages" data-design-panel="pages" data-design-pages data-page="<?php echo (int) $design_page_result['page']; ?>" data-nonce="<?php echo esc_attr( wp_nonce( 'design-pages' ) ); ?>">
<h2>Pages</h2><p>Select a Page to preview it with your current design.</p>
<form id="design-pages-search" class="design-pages-search" action="themes.php#design-pages" method="get">
<label for="design-page-search">Search Pages</label>
<input type="search" id="design-page-search" name="search" value="<?php echo esc_attr( $design_page_search ); ?>" />
<label for="design-page-status">Page status</label>
<select id="design-page-status" name="status">
<?php foreach ( [
	'all'       => 'All Pages',
	'published' => 'Published',
	'draft'     => 'Draft',
	'archived'  => 'Archived'
] as $design_status_value => $design_status_label ) : ?>
<option value="<?php echo esc_attr( $design_status_value ); ?>" <?php echo $design_status_value === $design_page_status ? 'selected' : ''; ?>><?php echo esc_html( $design_status_label ); ?></option>
<?php endforeach; ?>
</select>
<button type="submit">Search Pages</button>
</form>
<p class="design-pages-feedback" role="status" aria-live="polite"><?php echo (int) $design_page_result['total']; ?> <?php echo 1 === $design_page_result['total'] ? 'Page' : 'Pages'; ?></p><p class="design-pages-error" role="alert" hidden></p>
<div class="design-pages-items">
<?php if ( [] === $design_page_result['items'] ) : ?>
<p>No Pages found. Clear or change the search and status filter.</p>
<?php endif; ?>
<?php foreach ( $design_page_result['items'] as $design_page_item ) : ?>
<article class="design-page-row" data-page-id="<?php echo (int) $design_page_item['id']; ?>" data-title="<?php echo esc_attr( $design_page_item['title'] ); ?>" data-status="<?php echo esc_attr( $design_page_item['status'] ); ?>" data-editor-url="<?php echo esc_attr( $design_page_item['editorUrl'] ); ?>"><button type="button" class="design-page-select" aria-pressed="false"><?php echo esc_html( $design_page_item['title'] ); ?></button><span class="design-page-status"><?php echo esc_html( ucfirst( $design_page_item['status'] ) ); ?></span><a class="design-page-fallback" href="post.php?post=<?php echo (int) $design_page_item['id']; ?>&amp;editor=visual">Open visual editor</a></article>
<?php endforeach; ?>
</div><div class="design-pages-pagination"><button type="submit" form="design-pages-search" name="page" value="<?php echo (int) $design_page_result['page'] - 1; ?>" data-pages-previous <?php echo $design_page_result['page'] <= 1 ? 'disabled' : ''; ?>>Previous Pages</button><span data-pages-position>Page <?php echo (int) $design_page_result['page']; ?> of <?php echo (int) $design_page_result['pages']; ?></span><button type="submit" form="design-pages-search" name="page" value="<?php echo (int) $design_page_result['page'] + 1; ?>" data-pages-next <?php echo $design_page_result['page'] >= $design_page_result['pages'] ? 'disabled' : ''; ?>>Next Pages</button></div>
<p class="description">Page selection is preview-only. Page saves follow their selected publication status; site design is published separately.</p>
</section>
