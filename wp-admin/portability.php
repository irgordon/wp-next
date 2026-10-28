<?php
/** Export / Import: native archives and WordPress WXR, preflight first. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$admin_page_title = 'Export / Import';
$admin_section = 'portability';
$notice = '';
$error = '';
$preflight = null;
$parsed = null;
$archive_key = '';
$media_key = '';
$run_results = null;

/** Stores an uploaded file in the private portability folder, keyed by hash. */
function wp_portability_store_upload( string $field, string $suffix ): array
{
	$file = $_FILES[ $field ] ?? null;
	if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
		return [ '', '' ];
	}
	if ( ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return [ '', 'An uploaded file could not be received. Check the server upload limits and try again.' ];
	}
	$hash = (string) hash_file( 'sha256', $file['tmp_name'] );
	$target = wp_portability_workdir() . '/' . $hash . $suffix;
	if ( ! is_file( $target ) && ! move_uploaded_file( $file['tmp_name'], $target ) ) {
		return [ '', 'The uploaded file could not be stored for preflight.' ];
	}
	if ( is_file( $target ) ) {
		chmod( $target, 0600 );
	}
	return [ $hash, '' ];
}

/** Loads a previously stored upload by its hash key. */
function wp_portability_stored_path( string $key, string $suffix ): ?string
{
	if ( ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
		return null;
	}
	$path = wp_portability_workdir() . '/' . $key . $suffix;
	return is_file( $path ) ? $path : null;
}

/** Parses a stored archive pair into the shared model. */
function wp_portability_parse_stored( string $main_key, string $media_key_value ): array|WP_Error|null
{
	$native = wp_portability_stored_path( $main_key, '.zip' );
	if ( null !== $native ) {
		return wp_portability_parse_native( $native );
	}
	$xml = wp_portability_stored_path( $main_key, '.xml' );
	if ( null === $xml ) {
		return null;
	}
	$media = '' !== $media_key_value ? wp_portability_stored_path( $media_key_value, '.media.zip' ) : null;
	return wp_portability_parse_wxr( $xml, $media );
}

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	$action = wp_input( $_POST, 'portability_action' );
	if ( 'export' === $action ) {
		wp_require_post( 'portability-export' );
		$export = wp_portability_build_export();
		if ( is_wp_error( $export ) ) {
			$error = $export->get_error_message();
		} else {
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $export['filename'] . '"' );
			header( 'Content-Length: ' . (int) filesize( $export['path'] ) );
			readfile( $export['path'] );
			unlink( $export['path'] );
			exit;
		}
	} elseif ( 'preflight' === $action ) {
		wp_require_post( 'portability-preflight' );
		$file = $_FILES['archive'] ?? null;
		$file_name = is_array( $file ) ? strtolower( (string) ( $file['name'] ?? '' ) ) : '';
		$is_xml = str_ends_with( $file_name, '.xml' ) || str_ends_with( $file_name, '.wxr' );
		[ $archive_key, $upload_error ] = wp_portability_store_upload( 'archive', $is_xml ? '.xml' : '.zip' );
		[ $media_key, $media_error ] = wp_portability_store_upload( 'media_archive', '.media.zip' );
		if ( '' !== $upload_error || '' !== $media_error ) {
			$error = '' !== $upload_error ? $upload_error : $media_error;
		} elseif ( '' === $archive_key ) {
			$error = 'Choose an export archive (.zip) or a WordPress WXR file (.xml) to inspect.';
		} else {
			$parsed = wp_portability_parse_stored( $archive_key, $media_key );
			if ( is_wp_error( $parsed ) ) {
				$error = $parsed->get_error_message();
				$parsed = null;
			} elseif ( null === $parsed ) {
				$error = 'The uploaded archive could not be found.';
			} else {
				$preflight = wp_portability_preflight( $parsed['model'] );
				wp_portability_remove_tree( (string) $parsed['directory'] );
			}
		}
	} elseif ( 'import' === $action ) {
		wp_require_post( 'portability-import' );
		$archive_key = wp_input( $_POST, 'archive_key' );
		$media_key = wp_input( $_POST, 'media_key' );
		$parsed = wp_portability_parse_stored( $archive_key, $media_key );
		if ( is_wp_error( $parsed ) ) {
			$error = $parsed->get_error_message();
			$parsed = null;
		} elseif ( null === $parsed ) {
			$error = 'The uploaded archive is no longer available. Upload and preflight it again.';
		} else {
			$mapping = [];
			foreach ( (array) ( $_POST['author_map'] ?? [] ) as $source_id => $target_id ) {
				if ( is_numeric( $source_id ) && is_numeric( $target_id ) && (int) $target_id > 0 ) {
					$mapping[ (int) $source_id ] = (int) $target_id;
				}
			}
			$run = wp_portability_run( $parsed, $mapping );
			$run_results = $run['results'];
			$notice = 'Import run finished. Per-item results are listed below; running the same archive again resumes instead of duplicating.';
		}
		if ( null !== $parsed ) {
			wp_portability_remove_tree( (string) $parsed['directory'] );
		}
	} elseif ( '' !== $action ) {
		wp_die( 'Unknown portability action.', 'Request rejected', [ 'response' => 400 ] );
	}
}
$existing_users = $wpdb->get_results( 'SELECT id,username FROM users WHERE disabled=0 ORDER BY username' );
require __DIR__ . '/admin-header.php';
wp_admin_notice( $notice );
wp_admin_notice( $error, true );
?>
<section class="panel"><h2>Export this site</h2>
<p>Downloads one ZIP archive with a versioned JSON manifest and the media bytes it references (image masters only; derived sizes regenerate on import). Private document bytes <strong>are</strong> included: treat the archive like a backup. Export is administrator-only and changes nothing on this site.</p>
<form id="portability-export" method="post"><?php wp_nonce_field( 'portability-export' ); ?><input type="hidden" name="portability_action" value="export" /><button type="submit">Download export archive</button></form></section>
<section class="panel"><h2>Import</h2>
<p>Upload a WordPress Next export archive (.zip) or a WordPress WXR export (.xml). WXR attachment bytes are never fetched from the network: supply them in an optional media ZIP, matched by file name. Nothing is written until you review the preflight report and confirm the import on the next step. Uploaded archives are kept in the private site data folder so an interrupted import can be resumed by preflighting and confirming the same archive again.</p>
<form id="portability-preflight" method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'portability-preflight' ); ?><input type="hidden" name="portability_action" value="preflight" />
<div class="form-field"><label for="archive">Export archive or WXR file</label><input id="archive" name="archive" type="file" accept=".zip,.xml" required /></div>
<div class="form-field"><label for="media_archive">Media ZIP for WXR attachments (optional)</label><input id="media_archive" name="media_archive" type="file" accept=".zip" /></div>
<button type="submit">Run preflight</button></form></section>
<?php if ( null !== $preflight && null !== $parsed ) : ?>
<section class="panel"><h2>Preflight report</h2>
<p><?php echo esc_html( ucfirst( (string) $parsed['format'] ) ); ?> archive: <?php echo count( $parsed['model']['authors'] ); ?> author(s), <?php echo count( $parsed['model']['terms'] ); ?> term(s), <?php echo count( $parsed['model']['media'] ); ?> media item(s), <?php echo count( $parsed['model']['posts'] ); ?> content item(s). No changes have been made.</p>
	<?php if ( [] !== $preflight['unsupported'] ) :
		?><h3>Will not be imported</h3><ul><?php foreach ( $preflight['unsupported'] as $item ) :
	?><li><?php echo esc_html( $item ); ?></li><?php 
        endforeach; ?></ul><?php 
    endif; ?>
<h3>Authors</h3>
<form id="portability-import" method="post"><?php wp_nonce_field( 'portability-import' ); ?><input type="hidden" name="portability_action" value="import" /><input type="hidden" name="archive_key" value="<?php echo esc_attr( $archive_key ); ?>" /><input type="hidden" name="media_key" value="<?php echo esc_attr( $media_key ); ?>" />
<table><thead><tr><th scope="col">Source author</th><th scope="col">Plan</th><th scope="col">Map to existing account</th></tr></thead><tbody>
	<?php foreach ( $parsed['model']['authors'] as $author ) :
		$plan = $preflight['authors'][ $author['source_id'] ] ?? [ 'note' => '' ]; ?>
<tr><td><?php echo esc_html( '' !== $author['username'] ? $author['username'] : '#' . $author['source_id'] ); ?> (<?php echo esc_html( $author['email'] ); ?>)</td><td><?php echo esc_html( $plan['note'] ); ?></td><td><select name="author_map[<?php echo (int) $author['source_id']; ?>]"><option value="">Automatic (email match, else create)</option><?php foreach ( $existing_users as $user ) :
	?><option value="<?php echo (int) $user['id']; ?>"><?php echo esc_html( $user['username'] ); ?></option><?php 
        endforeach; ?></select></td></tr>
    <?php endforeach; ?>
</tbody></table>
<h3>Media</h3><ul>
	<?php foreach ( $parsed['model']['media'] as $media ) :
		$plan = $preflight['media'][ $media['source_id'] ] ?? [
			'action' => '',
			'note'   => ''
		]; ?>
<li><?php echo esc_html( $media['original_name'] ); ?> — <?php echo esc_html( $plan['action'] ); ?>: <?php echo esc_html( $plan['note'] ); ?></li>
    <?php endforeach; ?></ul>
<h3>Content</h3><ul>
	<?php foreach ( $parsed['model']['posts'] as $post ) :
		$plan = $preflight['posts'][ $post['source_id'] ] ?? [
			'action' => '',
			'note'   => ''
		]; ?>
<li><?php echo esc_html( $post['title'] ); ?> — <?php echo esc_html( $plan['action'] ); ?>: <?php echo esc_html( $plan['note'] ); ?><?php foreach ( $post['notes'] as $note ) :
	?> <?php echo esc_html( $note ); ?><?php 
    endforeach; ?></li>
    <?php endforeach; ?></ul>
<p>Storage quota: <?php echo (int) $preflight['quota']['used']; ?> bytes in use<?php echo $preflight['quota']['limit'] > 0 ? ', limit ' . (int) $preflight['quota']['limit'] . ' bytes, this import adds about ' . (int) $preflight['quota']['reserved'] . ' bytes' : ' (no limit set)'; ?>.</p>
<button type="submit">Confirm import</button></form></section>
<?php endif; ?>
<?php if ( null !== $run_results ) : ?>
<section class="panel"><h2>Import results</h2>
<table><thead><tr><th scope="col">Item</th><th scope="col">Kind</th><th scope="col">Result</th><th scope="col">Detail</th></tr></thead><tbody>
	<?php foreach ( $run_results as $result ) : ?>
<tr><td><?php echo esc_html( $result['label'] ); ?></td><td><?php echo esc_html( $result['kind'] ); ?></td><td><?php echo esc_html( $result['status'] ); ?></td><td><?php echo esc_html( $result['detail'] ); ?></td></tr>
    <?php endforeach; ?>
</tbody></table></section>
<?php endif; ?>
<?php require __DIR__ . '/admin-footer.php'; ?>
