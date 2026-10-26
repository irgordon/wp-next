<?php
/** Draft, preview, publish and restore site composition. */
declare(strict_types=1);
require_once __DIR__.'/admin.php';
global $wpdb;
wp_admin_require_cap('administrator');
$admin_page_title = 'Site design';
$admin_section = 'design';
$error = '';
$message = '';
$draft_id = 0;
$themes = wp_available_themes();
$form_version = wp_design_version();
$config = wp_design_live();
if ( isset($_GET['draft']) ) {
    $draft_id = (int)wp_input($_GET,'draft');
	$row = $wpdb->get_row('SELECT config,base_version FROM design_revisions WHERE id=:id AND actor_id=:actor AND published=0',[
		':id'    => $draft_id,
		':actor' => get_current_user_id()
	]);
    if ( null === $row ) {
		wp_die('Draft not found.','Not found',['response' => 404]);

	}$config = json_decode($row['config'],true);
	$form_version = (int)$row['base_version'];
}

$tokens = $config['tokens'];
$form = [
	'active_theme' => $config['active_theme'],
	'accent'       => $tokens['accent'] ?? '#3858e9',
	'background'   => $tokens['background'] ?? '#ffffff',
	'width'        => (string)($tokens['width'] ?? 960),
	'font'         => $tokens['font'] ?? 'sans',
	'body_size'    => (string)($tokens['body_size'] ?? 16),
	'spacing'      => (string)($tokens['spacing'] ?? 32),
	'homepage_id'  => (string)$config['homepage_id'],
	'site_logo_id' => (string)$config['site_logo_id']
];
$menu_blank_row = [
	'label' => '',
	'url'   => '',
	'level' => 1,
];
foreach ( ['header','footer'] as $location ) {
	$form[ 'menu_items_'.$location ] = array_values( $config['menus'][ $location ] ?? [] );
	$form[ 'section_'.$location ] = $config['sections'][ $location ] ?? '';
}
$navigation = $config['navigation_pages'];
$pages = $wpdb->get_results("SELECT id,title FROM posts WHERE post_type='page' AND status='published' ORDER BY title");
if ( 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '') ) {
    wp_require_post('design');
    foreach ( $form as $key => $value ) {
		if ( str_starts_with( $key, 'menu_items_' ) ) {
			continue;
		}
		$form[ $key ] = wp_input($_POST,$key,$value);
	}
    foreach ( ['header','footer'] as $location ) {
		$rows = is_array($_POST['menu'][ $location ] ?? null) ? $_POST['menu'][ $location ] : [];
		$items = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue; 
			}
			$label = trim( (string) ( $row['label'] ?? '' ) );
			$url = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' === $label && '' === $url ) {
				continue; 
			}
			$items[] = [
				'label' => $label,
				'url'   => $url,
				'level' => max( 1, min( 3, (int) ( $row['level'] ?? 1 ) ) ),
				'order' => (int) ( $row['order'] ?? 0 )
			];
		}
		usort( $items, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
		$form[ 'menu_items_'.$location ] = array_map( static fn( array $item ): array => [
			'label' => $item['label'],
			'url'   => $item['url'],
			'level' => $item['level']
		], $items );
	}
    $navigation = array_values(array_map('intval',is_array($_POST['navigation'] ?? null) ? $_POST['navigation'] : []));
    $orders = is_array($_POST['nav_order'] ?? null) ? $_POST['nav_order'] : [];
	usort($navigation,static fn(int $a,int $b):int=> (int)($orders[ $a ] ?? 0) <=> (int)($orders[ $b ] ?? 0));
    if ( 'discard' === wp_input($_POST,'design_action') ) {
        $wpdb->transaction(static function ($db):void {
			if ( ! current_user_can('administrator') ) {
				throw new RuntimeException('Permission revoked.');

			} $db->query('DELETE FROM design_revisions WHERE id=:id AND actor_id=:actor AND published=0',[
				':id'    => (int)wp_input($_POST,'draft_id'),
				':actor' => get_current_user_id()
			]);

		});
		wp_safe_redirect('themes.php');
    }
    try {
        $expected = filter_var(wp_input($_POST,'expected_version'),FILTER_VALIDATE_INT);
		$form_version = false === $expected ? -1 : $expected;
        if ( false === $expected ) {
			throw new RuntimeException('Reload the design screen before saving.');
		}
        if ( 'restore' === wp_input($_POST,'design_action') ) {
			wp_design_publish( (int)wp_input($_POST,'restore_revision'),$expected);
			wp_safe_redirect('themes.php?restored=1');
		}
        $config = [
			'active_theme'     => $form['active_theme'],
			'tokens'           => array_intersect_key($form,array_flip(['accent','background','width','font','body_size','spacing'])),
			'homepage_id'      => (int)$form['homepage_id'],
			'site_logo_id'     => (int)$form['site_logo_id'],
			'navigation_pages' => $navigation,
			'menus'            => [],
			'sections'         => []
		];
        foreach ( ['header','footer'] as $location ) {
			$config['menus'][ $location ] = $form[ 'menu_items_'.$location ];
			$config['sections'][ $location ] = $form[ 'section_'.$location ];
		}
        $draft_id = wp_design_draft($config,$expected);
        if ( 'publish' === wp_input($_POST,'design_action') ) {
			wp_design_publish($draft_id,$expected);
			$message = 'Site design published.';
			$draft_id = 0;
			$form_version = wp_design_version();

		} else {
			$message = 'Design draft saved. Live design is unchanged.';
		}
    } catch ( Throwable $exception ) {
		$error = $exception->getMessage();
	}
}
if ( isset($_GET['restored']) ) {
	$message = 'Design restored as a new live version.';
}
$logos = $wpdb->get_results( "SELECT id,original_name FROM media WHERE kind='image' AND state='ready' ORDER BY id DESC LIMIT 100" );
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( $message );
?>
<nav class="actions" aria-label="Design tools"><a href="themes.php">Site design</a><a href="file-editor.php">Approved file editor</a></nav>
<form method="post" id="design-form"><?php wp_nonce_field( 'design' ); ?><input type="hidden" name="expected_version" value="<?php echo $form_version; ?>" /><section class="panel"><h2>Theme</h2><div class="settings-grid"><?php foreach ( $themes as $slug => $label ) :
	?><label class="theme-card <?php echo $form['active_theme'] === $slug ? 'active' : ''; ?>"><span class="theme-swatch <?php echo esc_attr( $slug ); ?>" aria-hidden="true"></span><input type="radio" name="active_theme" value="<?php echo esc_attr( $slug ); ?>" <?php echo $form['active_theme'] === $slug ? 'checked' : ''; ?> /> <?php echo esc_html( $label ); ?></label><?php
					                 endforeach; ?></div></section>
<div class="settings-grid"><section class="panel"><h2>Global style</h2><div class="form-field"><label for="site_logo_id">Site logo</label><select id="site_logo_id" name="site_logo_id"><option value="0">Text brand only</option><?php foreach ( $logos as $logo ) :
	?><option value="<?php echo (int) $logo['id']; ?>" <?php echo (int) $form['site_logo_id'] === (int) $logo['id'] ? 'selected' : ''; ?>><?php echo esc_html( $logo['original_name'] ); ?></option><?php
																																																								  endforeach; ?></select></div><?php wp_admin_field( 'accent', 'Accent color', $form['accent'], 'color' );
wp_admin_field( 'background', 'Background color', $form['background'], 'color' );
wp_admin_field( 'width', 'Content width in pixels', $form['width'], 'number', 'Between 640 and 1400 pixels. Layouts adapt on smaller screens.' ); ?><div class="form-field"><label for="font">Type style</label><select id="font" name="font"><option value="sans" <?php echo 'sans' === $form['font'] ? 'selected' : ''; ?>>Inter headings</option><option value="serif" <?php echo 'serif' === $form['font'] ? 'selected' : ''; ?>>EB Garamond headings</option></select></div></section>
<section class="panel"><h2>Homepage and navigation</h2><div class="form-field"><label for="homepage_id">Homepage</label><select id="homepage_id" name="homepage_id"><option value="0">Latest posts</option><?php foreach ( $pages as $page ) :
	?><option value="<?php echo (int) $page['id']; ?>" <?php echo (int) $form['homepage_id'] === (int) $page['id'] ? 'selected' : ''; ?>><?php echo esc_html( $page['title'] ); ?></option><?php
																																																		   endforeach; ?></select></div><p>Show these published pages in the header. Set a position for each selected page to choose the navigation order.</p><?php foreach ( $pages as $page ) :
	?><label class="inline-checkbox"><input type="checkbox" name="navigation[]" value="<?php echo (int) $page['id']; ?>" <?php echo in_array( (int) $page['id'], array_map( 'intval', $navigation ), true ) ? 'checked' : ''; ?> /><?php echo esc_html( $page['title'] ); ?></label><label for="order-<?php echo (int) $page['id']; ?>">Position for <?php echo esc_html( $page['title'] ); ?></label><input id="order-<?php echo (int) $page['id']; ?>" type="number" name="nav_order[<?php echo (int) $page['id']; ?>]" value="<?php $position = array_search( (int) $page['id'], array_map( 'intval', $navigation ), true );
echo false === $position ? 20 : $position + 1; ?>" min="1" max="100" /><?php
																																																		   endforeach; ?></section></div><section class="panel"><h2>Typography and spacing</h2><?php wp_admin_field('body_size','Body text size',$form['body_size'],'number','14–24 pixels.');
wp_admin_field('spacing','Section spacing',$form['spacing'],'number','12–80 pixels.'); ?></section>
<?php foreach ( [
	'header' => 'Header',
	'footer' => 'Footer'
] as $location => $label ) :
	?><section class="panel"><h2><?php echo $label; ?> composition</h2><fieldset class="menu-editor" data-menu-editor="<?php echo $location; ?>"><legend><?php echo $label; ?> menu</legend><p class="description">Add one row per link. Level nests items (1 is top level; start at level 1). Order numbers sort the menu; the buttons move a row and renumber. Leave every row empty to use the page navigation instead.</p>
	<?php $menu_rows = array_merge( $form[ 'menu_items_'.$location ], [ $menu_blank_row ] );
	foreach ( $menu_rows as $index => $item ) : ?>
<p class="menu-row"><label>Label <input type="text" name="menu[<?php echo $location; ?>][<?php echo $index; ?>][label]" value="<?php echo esc_attr( $item['label'] ); ?>" /></label> <label>Address <input type="text" name="menu[<?php echo $location; ?>][<?php echo $index; ?>][url]" value="<?php echo esc_attr( $item['url'] ); ?>" /></label> <label>Level <select name="menu[<?php echo $location; ?>][<?php echo $index; ?>][level]"><?php for ( $level = 1; $level <= 3; ++$level ) :
	?><option value="<?php echo $level; ?>"<?php echo (int) $item['level'] === $level ? ' selected' : ''; ?>><?php echo $level; ?></option><?php 
                                                               endfor; ?></select></label> <label>Order <input type="number" name="menu[<?php echo $location; ?>][<?php echo $index; ?>][order]" value="<?php echo ( $index + 1 ) * 10; ?>" min="1" max="999" /></label> <button type="button" class="secondary" data-menu-move="-1" aria-label="Move this menu item up">↑</button> <button type="button" class="secondary" data-menu-move="1" aria-label="Move this menu item down">↓</button> <button type="button" class="secondary" data-menu-remove aria-label="Remove this menu item">Remove</button></p>
    <?php endforeach; ?>
<p><button type="button" class="secondary" data-menu-add>Add menu item</button></p></fieldset>
<div data-section-editor data-target="section-<?php echo $location; ?>"><div class="section-builder-root" data-media-url="media.php?format=json" data-documents-url="media.php?format=json&kind=documents"></div><p class="builder-status description" role="status" aria-live="polite"></p><details open><summary>Raw block document</summary><label for="section-<?php echo $location; ?>"><?php echo $label; ?> shared section</label><textarea id="section-<?php echo $location; ?>" name="section_<?php echo $location; ?>" rows="5"><?php echo esc_textarea($form[ 'section_'.$location ]); ?></textarea><p class="description">Supported HTML or a version 1 block document. Shared edits affect the site only when published. Page revision history stays unchanged.</p></details></div></section><?php 
endforeach; ?>
<div class="actions"><button type="submit" name="design_action" value="draft">Save draft</button><button type="submit" name="design_action" value="publish">Publish design</button><a href="<?php echo esc_url( wp_home_url() ); ?>" target="_blank" rel="noopener">View site ↗</a></div></form>
<?php if ( $draft_id > 0 ) :
	?><section class="panel"><h2>Preview saved draft</h2><p><a href="<?php echo esc_url(wp_home_url('/?design_preview='.$draft_id.'&_wpnonce='.wp_nonce('design-preview:'.$draft_id))); ?>" target="_blank" rel="noopener">Preview design</a></p><p>Only your signed-in session can open this saved draft. Live output changes only after publication.</p></section><?php 
endif; ?>
<section class="panel"><h2>Saved drafts</h2><?php foreach ( $wpdb->get_results('SELECT id,base_version FROM design_revisions WHERE actor_id=:actor AND published=0 ORDER BY id DESC LIMIT 30',[':actor' => get_current_user_id()]) as $draft ) :
	?><div class="toolbar"><a href="themes.php?draft=<?php echo (int)$draft['id']; ?>">Edit draft <?php echo (int)$draft['id']; ?></a><span>Based on live version <?php echo (int)$draft['base_version']; ?></span><form method="post"><?php wp_nonce_field('design'); ?><input type="hidden" name="draft_id" value="<?php echo (int)$draft['id']; ?>" /><button type="submit" name="design_action" value="discard">Discard draft</button></form></div><?php 
                                            endforeach; ?><p><a href="themes.php">Start from the current live design</a></p></section>
<section class="panel"><h2>Design history</h2><p>Restoring publishes an earlier configuration as a new live version. Design history is independent of file history: restoring a design never changes a published file, and restoring a file never changes the design.</p><?php foreach ( $wpdb->get_results('SELECT id,created_at FROM design_revisions WHERE published=1 ORDER BY id DESC LIMIT 30') as $revision ) :
	?><form method="post" class="inline-form"><?php wp_nonce_field('design'); ?><input type="hidden" name="expected_version" value="<?php echo wp_design_version(); ?>" /><input type="hidden" name="restore_revision" value="<?php echo (int)$revision['id']; ?>" /><span>Design revision <?php echo (int)$revision['id']; ?><?php echo (int)get_option('design_live_revision',0) === (int)$revision['id'] ? ' · Current live design' : ''; ?></span><button type="submit" name="design_action" value="restore">Restore design</button></form><?php 
                                                                                                                                                                                                                                                                         endforeach; ?></section>

<dialog id="media-picker" aria-labelledby="media-picker-title"><div class="toolbar"><h2 id="media-picker-title">Choose an image</h2><button type="button" id="close-media-picker">Close</button></div><div class="inline-form"><label for="media-picker-search">Search images</label><input id="media-picker-search" type="search" /><button id="media-picker-search-button" type="button">Search images</button></div><div id="media-picker-items" class="media-grid"></div><button id="media-picker-more" type="button" class="hidden">Load more images</button><p><a href="media.php" target="_blank" rel="noopener">Upload or manage media ↗</a></p></dialog>
<dialog id="document-picker" aria-labelledby="document-picker-title"><div class="toolbar"><h2 id="document-picker-title">Choose a document</h2><button type="button" id="close-document-picker">Close</button></div><div class="inline-form"><label for="document-picker-search">Search documents</label><input id="document-picker-search" type="search" /><button id="document-picker-search-button" type="button">Search documents</button></div><div id="document-picker-items" class="media-grid"></div><button id="document-picker-more" type="button" class="hidden">Load more documents</button><p><a href="media.php?kind=documents" target="_blank" rel="noopener">Upload or manage documents ↗</a></p></dialog>
<script src="js/page-builder.js" defer></script>
<?php require __DIR__ . '/admin-footer.php';
