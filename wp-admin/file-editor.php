<?php
/** Controlled public CSS/text files. No filesystem paths are accepted from the request. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$file = wp_input( $_GET, 'file', 'custom.css' );
if ( ! isset( wp_managed_files()[ $file ] ) ) {
	wp_die( 'File not found.', 'Not found', [ 'response' => 404 ] ); 
}
$error = '';
$message = '';
$content = wp_managed_file_content( $file );
$expected = wp_managed_file_version( $file );
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
    wp_require_post( 'file-edit:' . $file );
	$content = wp_input( $_POST, 'content' );
    try {
        if ( 'custom.css' === $file && 'draft' === wp_input( $_POST, 'file_action' ) ) {
            wp_managed_file_save_draft( $file, $content );
			wp_safe_redirect( 'file-editor.php?file=' . rawurlencode( $file ) . '&draft_saved=1' );
        }
        if ( 'custom.css' === $file && 'discard_draft' === wp_input( $_POST, 'file_action' ) ) {
            wp_managed_file_discard_draft( $file );
			wp_safe_redirect( 'file-editor.php?file=' . rawurlencode( $file ) . '&draft_discarded=1' );
        }
        if ( 'custom.css' === $file && 'publish_draft' === wp_input( $_POST, 'file_action' ) ) {
            $version = filter_var( wp_input( $_POST, 'expected_version' ), FILTER_VALIDATE_INT );
			if ( false === $version ) {
				throw new RuntimeException( 'Reload before publishing.' ); 
			}
            wp_managed_file_publish_draft( $file, $version );
			wp_safe_redirect( 'file-editor.php?file=' . rawurlencode( $file ) . '&saved=1' );
        }
        $version = filter_var( wp_input( $_POST, 'expected_version' ), FILTER_VALIDATE_INT );
		if ( false === $version ) {
			throw new RuntimeException( 'Reload before publishing.' ); 
		}
        if ( 'restore' === wp_input( $_POST, 'file_action' ) ) {
            $row = $wpdb->get_row( 'SELECT content FROM managed_file_versions WHERE id=:id AND filename=:file', [
				':id'   => (int) wp_input( $_POST, 'revision_id' ),
				':file' => $file
			] );
            if ( null === $row ) {
				throw new RuntimeException( 'File revision not found.' );

			} $content = $row['content'];
        }
        wp_managed_file_publish( $file, $content, $version );
		wp_safe_redirect( 'file-editor.php?file=' . rawurlencode( $file ) . '&saved=1' );
    } catch ( Throwable $exception ) {
		$error = $exception->getMessage();
		$expected = $version ?? -1;
		if ( 'publish_draft' === wp_input( $_POST, 'file_action' ) ) {
			$kept = wp_managed_file_draft( $file );
			if ( null !== $kept ) {
				$content = (string) $kept['content'];
				$error .= ' Your draft is unchanged and its text is shown below.';
			}
		}
	}
}
$admin_page_title = 'Code & files';
$admin_section = 'files';
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
if ( isset( $_GET['saved'] ) ) {
	wp_admin_notice( 'File published as a new version.' ); 
}
if ( isset( $_GET['draft_saved'] ) ) {
	wp_admin_notice( 'Draft saved. It is private until you publish it.' ); 
}
if ( isset( $_GET['draft_discarded'] ) ) {
	wp_admin_notice( 'Draft discarded.' ); 
}
$draft = 'custom.css' === $file ? wp_managed_file_draft( $file ) : null;
?>
<nav class="actions" aria-label="Approved files"><?php foreach ( wp_managed_files() as $name => $label ) :
	?><a href="file-editor.php?file=<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></a><?php 
                                                 endforeach; ?></nav>
<section class="panel"><h2><?php echo esc_html($file); ?></h2><p>These managed files are public when published. Their versions are stored with the site database and included in backups. Theme PHP, configuration and uploaded files cannot be edited here.</p>
<?php if ( 'custom.css' === $file ) :
	?><p>Supported selectors: body, .entry-title, .entry-content, .site-header, .site-footer, .site-navigation, .shared-section. Use colors, font size/weight, line height, letter spacing, text alignment, margin and padding. Imports, external URLs, escapes and scripts are rejected.</p><?php 
endif; ?>
<form method="post"><?php wp_nonce_field('file-edit:'.$file); ?><input type="hidden" name="expected_version" value="<?php echo (int)$expected; ?>" /><label for="file-content">File contents</label><textarea class="raw-content" data-code-language="<?php echo 'custom.css' === $file ? 'css' : 'text'; ?>" data-code-name="<?php echo esc_attr( $file ); ?>" id="file-content" name="content" rows="18"><?php echo esc_textarea($content); ?></textarea><button type="submit" name="file_action" value="publish">Publish file</button><?php if ( 'custom.css' === $file ) :
	?> <button type="submit" name="file_action" value="draft" class="secondary">Save as draft</button><?php 
                    endif; ?></form>
<?php if ( 'custom.css' === $file && null !== $draft ) : ?>
<section class="panel"><h2>Stylesheet draft</h2><p>Saved <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $draft['updated_at'] ) ); ?> UTC. The draft is private: the public site keeps using the published stylesheet until this draft is published.</p><p><a href="<?php echo esc_url( wp_home_url( '/?css_preview=1&_wpnonce=' . wp_nonce( 'css-preview' ) ) ); ?>" target="_blank" rel="noopener">Preview draft on the site ↗</a></p><form class="inline-form" method="post"><?php wp_nonce_field('file-edit:'.$file); ?><input type="hidden" name="expected_version" value="<?php echo (int) wp_managed_file_version( $file ); ?>" /><button type="submit" name="file_action" value="publish_draft">Publish draft</button></form> <form class="inline-form" method="post"><?php wp_nonce_field('file-edit:'.$file); ?><button type="submit" name="file_action" value="discard_draft">Discard draft</button></form></section>
<?php endif; ?>
<p><a href="<?php echo esc_url(wp_home_url('/site-assets/'.$file)); ?>">View published file</a></p></section>
<section class="panel"><h2>File history</h2><p>Restore creates another version and never deletes history. File history covers only this file and is independent of design history: restoring a file never changes the design, and restoring a design never changes a published file.</p><?php foreach ( $wpdb->get_results('SELECT id,created_at FROM managed_file_versions WHERE filename=:file ORDER BY id DESC LIMIT 30',[':file' => $file]) as $revision ) :
	?><form class="inline-form" method="post"><?php wp_nonce_field('file-edit:'.$file); ?><input type="hidden" name="expected_version" value="<?php echo wp_managed_file_version($file); ?>" /><input type="hidden" name="revision_id" value="<?php echo (int)$revision['id']; ?>" /><span>Revision <?php echo (int)$revision['id']; ?></span><button type="submit" name="file_action" value="restore">Restore file revision</button></form><?php 
                                                                                                                                                                                                                                                                                        endforeach; ?></section>
<?php require __DIR__ . '/admin-footer.php';
