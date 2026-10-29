<?php
/** Media library: raster images on the public uploads tree, documents in protected storage. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'upload_media' );
$error = '';
$message = '';
$replace_preview = null;
$kind = 'documents' === wp_input( $_GET, 'kind' ) || 'upload_document' === wp_input( $_POST, 'action' ) ? 'documents' : 'images';
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'media' );
	$action = wp_input( $_POST, 'action', 'upload' );
	$ajax = '1' === wp_input( $_POST, 'ajax' );
	if ( 'upload' === $action || 'upload_document' === $action ) {
		// Per-file processing with no batch atomicity: the XHR client sends one file per request; the classic form may carry several.
		$field = 'upload' === $action ? 'image' : 'document';
		$entry = $_FILES[ $field ] ?? [];
		$files = [];
		if ( isset( $entry['name'] ) && is_array( $entry['name'] ) ) {
			foreach ( $entry['name'] as $index => $name ) {
				$files[] = [
					'name'     => $name,
					'type'     => $entry['type'][ $index ] ?? '',
					'tmp_name' => $entry['tmp_name'][ $index ] ?? '',
					'error'    => $entry['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
					'size'     => $entry['size'][ $index ] ?? 0
				];
			}
		} elseif ( [] !== $entry ) {
			$files[] = $entry;
		}
		$results = [];
		foreach ( $files as $one ) {
			$results[] = 'upload' === $action ? wp_handle_upload( $one ) : wp_handle_document_upload( $one, wp_input( $_POST, 'visibility', 'private' ) );
		}
		if ( $ajax ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			$result = $results[0] ?? new WP_Error( 'upload_failed', 'Select a valid file.' );
			if ( is_wp_error( $result ) ) {
				http_response_code( 422 );
				echo wp_json_encode( [
					'ok'    => false,
					'error' => $result->get_error_message()
				] );
			} else {
				echo wp_json_encode( [
					'ok' => true,
					'id' => $result
				] );
			}
			exit;
		}
		$errors = [];
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}
		if ( [] === $errors && [] !== $results ) {
			wp_safe_redirect( 'upload_document' === $action ? 'media.php?kind=documents&uploaded=1' : 'media.php?uploaded=1' );
		}
		$error = [] === $results ? 'Choose at least one file to upload.' : implode( ' ', $errors );
	} elseif ( in_array( $action, [ 'focal', 'edit_image', 'replace_preview', 'replace_apply' ], true ) ) {
		$media_id = (int) wp_input( $_POST, 'id' );
		$media = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='image' AND variant='' AND state='ready'", [ ':id' => $media_id ] );
		if ( null === $media || ( get_current_user_id() !== (int) $media['author_id'] && ! current_user_can( 'manage_media' ) ) ) {
			$error = 'Media permission rejected.';
		} elseif ( 'focal' === $action ) {
			$result = wp_image_set_focal( $media_id, (float) wp_input( $_POST, 'focal_x', '50' ) / 100, (float) wp_input( $_POST, 'focal_y', '50' ) / 100 );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$message = 'Focal point saved; the thumbnail was regenerated around it.';
			}
		} elseif ( 'edit_image' === $action ) {
			$ops = [];
			$crop = [ wp_input( $_POST, 'crop_x' ), wp_input( $_POST, 'crop_y' ), wp_input( $_POST, 'crop_w' ), wp_input( $_POST, 'crop_h' ) ];
			if ( [ '', '', '', '' ] !== $crop && ! in_array( '', $crop, true ) ) {
				$ops['crop'] = array_map( 'intval', $crop );
			}
			$rotate = (int) wp_input( $_POST, 'rotate', '0' );
			if ( 0 !== $rotate ) {
				$ops['rotate'] = $rotate;
			}
			$result = wp_asset_storage_lock( static fn(): int|WP_Error => wp_image_create_edit( $media_id, $ops ) );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$message = 'Edited copy created as a new image (#' . $result . '). The original and its uses are unchanged; use Replace on the original to move references.';
			}
		} elseif ( 'replace_preview' === $action ) {
			$new_id = (int) wp_input( $_POST, 'replacement_id' );
			$impact = wp_image_replacement_impact( $media_id, $new_id );
			if ( is_wp_error( $impact ) ) {
				$error = $impact->get_error_message();
			} else {
				$replace_preview = [
					'old'    => $media_id,
					'new'    => $new_id,
					'impact' => $impact
				];
				$message = 'Review the replacement impact below, then apply it.';
			}
		} else {
			$new_id = (int) wp_input( $_POST, 'replacement_id' );
			$result = wp_image_apply_replacement( $media_id, $new_id );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$message = 'Replacement applied to ' . $result['posts_updated'] . ' content item(s).' . ( null !== $result['section_draft_id'] ? ' Shared sections were staged as design draft #' . $result['section_draft_id'] . '; publish it from Design for them to take effect.' : '' );
			}
		}
	} else {
		try {
			wp_asset_storage_lock( function () use ( $wpdb, $action ): void {
				$wpdb->transaction( function ( $db ) use ( $action ): void {
					$media = $db->get_row( "SELECT * FROM media WHERE id=:id AND state='ready'", [ ':id' => (int) wp_input( $_POST, 'id' ) ] );
					if ( null === $media || ( get_current_user_id() !== (int) $media['author_id'] && ! current_user_can( 'manage_media' ) ) ) {
						throw new RuntimeException( 'Media permission rejected.' );
					}
					if ( '' !== ( $media['variant'] ?? '' ) ) {
						throw new RuntimeException( 'Derived sizes are managed through their master image.' );
					}
					if ( 'delete' === $action ) {
						if ( 'yes' !== wp_input( $_POST, 'confirm_delete' ) ) {
							throw new RuntimeException( 'Deletion was not confirmed.' );
						}
						if ( wp_media_in_use( $media ) ) {
							throw new RuntimeException( 'Media is referenced by content or history.' );
						}
						$db->query( 'DELETE FROM media WHERE id=:id', [ ':id' => $media['id'] ] );
						if ( 'image' === $media['kind'] ) {
							$db->query( "DELETE FROM media WHERE parent_id=:id AND variant<>''", [ ':id' => $media['id'] ] );
						}
						// Physical bytes remain immutable;
						// Maintenance reclaims canonical unreferenced files after its 24-hour grace period.
						wp_audit( 'media_removed', (int) $media['id'] );
					} elseif ( 'visibility' === $action ) {
						if ( 'document' !== $media['kind'] ) {
							throw new RuntimeException( 'Only documents carry a visibility setting.' );
						}
						$target = wp_input( $_POST, 'visibility' );
						if ( ! in_array( $target, [ 'public', 'private' ], true ) || $target === $media['visibility'] ) {
							throw new RuntimeException( 'Choose the other visibility setting.' );
						}
						$db->query( 'UPDATE media SET visibility=:visibility WHERE id=:id', [
							':visibility' => $target,
							':id'         => $media['id']
						] );
						// Cached renders were produced under the old visibility; invalidate and re-render every referencing post.
						$queue = new \WordPress\Queue\WP_Job_Queue( $db );
						foreach ( wp_asset_referencing_post_ids( (int) $media['id'] ) as $post_id ) {
							$post = $db->get_row( 'SELECT revision,metadata FROM posts WHERE id=:id', [ ':id' => $post_id ] );
							if ( null !== $post ) {
								// Bump the render generation with the invalidation: results prepared before this change can no longer commit.
								$metadata = json_decode( (string) ( $post['metadata'] ?? '' ), true );
								if ( ! is_array( $metadata ) ) {
									$metadata = [];
								}
								$metadata['render_epoch'] = (int) ( $metadata['render_epoch'] ?? 0 ) + 1;
								$db->query( 'UPDATE posts SET rendered_revision=0,metadata=:metadata WHERE id=:id', [
									':metadata' => wp_json_encode( $metadata ),
									':id'       => $post_id
								] );
							}
							if ( null !== $post ) {
								$queue->enqueue_in_transaction( 'WordPress\Jobs\Render_Post_Job', [
									'post_id'         => $post_id,
									'target_revision' => (int) $post['revision']
								] );
							}
						}
						wp_audit( 'asset_visibility_changed', (int) $media['id'] );
					} else {
						if ( 'image' !== $media['kind'] ) {
							throw new RuntimeException( 'Only images carry alt text and captions.' );
						}
						$db->query( 'UPDATE media SET alt_text=:alt,caption=:caption WHERE id=:id', [
							':alt'     => sanitize_text_field( wp_input( $_POST, 'alt_text' ) ),
							':caption' => sanitize_text_field( wp_input( $_POST, 'caption' ) ),
							':id'      => $media['id']
						] );
						wp_audit( 'media_updated', (int) $media['id'] );
					}
				} );
            } );
			$message = 'Media library updated.';
		} catch ( Throwable $exception ) {
			error_log( 'Media update: ' . $exception->getMessage() );
			$error = 'The media could not be changed. Confirm deletion and ensure it is not used in content or revision history.';
		}
	}
}
$term = wp_input( $_GET, 's' );
$page = max( 1, (int) wp_input( $_GET, 'page', '1' ) );
$params = [ ':search' => '%' . $term . '%' ];
if ( 'documents' === $kind ) {
	$params[':me'] = get_current_user_id();
	$params[':manager'] = current_user_can( 'manage_media' ) ? 1 : 0;
	$where = "kind='document' AND state='ready' AND (visibility='public' OR author_id=:me OR 1=:manager) AND original_name LIKE :search";
} else {
	$where = "kind='image' AND state='ready' AND variant='' AND original_name LIKE :search";
}
$total = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM media WHERE ' . $where, $params )['n'];
$limit = 'json' === wp_input( $_GET, 'format' ) ? 60 : 20;
$items = $wpdb->get_results( 'SELECT * FROM media WHERE ' . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset', $params + [
	':limit'  => $limit,
	':offset' => ( $page - 1 ) * $limit
] );
if ( 'json' === wp_input( $_GET, 'format' ) ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	foreach ( $items as &$item ) {
		$item['url'] = 'document' === $item['kind'] ? wp_document_url( $item ) : wp_media_url( $item );
	}
	unset( $item );
	echo wp_json_encode( [
		'items' => $items,
		'next'  => $page * $limit < $total ? $page + 1 : null
	] );
	exit;
}
$admin_page_title = 'documents' === $kind ? 'Documents' : 'Images';
$admin_section = 'documents' === $kind ? 'documents' : 'images';
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( isset( $_GET['uploaded'] ) ? ( 'documents' === $kind ? 'Document uploaded.' : 'Image uploaded.' ) : $message );
?>
<nav class="tabs" aria-label="Media kinds"><a href="media.php"<?php echo 'images' === $kind ? ' aria-current="page"' : ''; ?>>Images</a> <a href="media.php?kind=documents"<?php echo 'documents' === $kind ? ' aria-current="page"' : ''; ?>>Documents</a></nav>
<?php
$usage = wp_asset_usage();
$fmt_bytes = static fn( int $bytes ): string => $bytes >= 1048576 ? round( $bytes / 1048576, 1 ) . ' MB' : (int) ceil( $bytes / 1024 ) . ' KB';
?>
<section class="storage-summary"><h2 class="screen-reader-text">Storage</h2><p>Registered: <?php echo $fmt_bytes( $usage['registered_bytes'] ); ?> (images <?php echo $fmt_bytes( $usage['registered_masters_bytes'] ); ?> · derived sizes <?php echo $fmt_bytes( $usage['registered_variants_bytes'] ); ?> · documents <?php echo $fmt_bytes( $usage['registered_documents_bytes'] ); ?>) · Stored files: <?php echo $fmt_bytes( $usage['physical_bytes'] ); ?><?php echo $usage['pending'] > 0 ? ' · ' . $usage['pending'] . ' registration(s) incomplete' : ''; ?></p><p class="description">Registered bytes are what the library accounts for, including derived sizes. Stored files are the bytes on disk, counted once per file; the difference is shared files and unreferenced bytes kept for 24 hours before cleanup.</p></section>

<?php
if ( 'documents' === $kind ) :
	?>
<section class="panel upload-panel"><h2>Upload a Document</h2><p>PDF, text, Markdown, or CSV, up to 25 MB. Documents are stored unmodified in protected storage and delivered only through the checked download route. They are <strong>not scanned for malware</strong>; treat every download as unscanned. Private documents are visible only to you and media managers; public documents can be linked from published content.</p><form class="inline-form" method="post" enctype="multipart/form-data" data-xhr-upload="1" data-upload-kind="<?php echo esc_attr( $kind ); ?>">
	<?php
	wp_nonce_field( 'media' );
	?>
<input type="hidden" name="action" value="upload_document" /><label for="document">Document file</label><input id="document" name="document[]" type="file" accept=".pdf,.txt,.md,.csv,application/pdf,text/plain,text/csv" required multiple /><label for="document-visibility">Visibility</label><select id="document-visibility" name="visibility"><option value="private" selected>Private</option><option value="public">Public</option></select><button type="submit">Upload document</button></form><div class="upload-results" role="status" aria-live="polite"></div></section>
<?php else : ?>
<section class="panel upload-panel"><h2>Upload an Image</h2><p>Nonanimated JPEG, PNG, WebP, or GIF, up to 10 MB and 12 megapixels. Images are decoded and re-encoded to remove metadata. JPEG orientation is preserved. Published content uses local uploads only. Uploaded images are publicly accessible.</p><form class="inline-form" method="post" enctype="multipart/form-data" data-xhr-upload="1" data-upload-kind="<?php echo esc_attr( $kind ); ?>">
	<?php
	wp_nonce_field( 'media' );
	?>
<input type="hidden" name="action" value="upload" /><label for="image">Image file</label><input id="image" name="image[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required multiple /><button type="submit">Upload image</button></form><div class="upload-results" role="status" aria-live="polite"></div></section>
<?php endif; ?>
<section class="media-library" data-media-library><form class="inline-form toolbar" method="get"><?php
if ( 'documents' === $kind ) :
	?><input type="hidden" name="kind" value="documents" /><?php
endif;
?><label for="media-search">Search library</label><input id="media-search" name="s" value="<?php echo esc_attr( $term ); ?>" /><button type="submit">Search</button></form>
<?php if ( [] === $items ) : ?>
<section class="media-empty-state" role="status"><span class="media-empty-icon" aria-hidden="true">▧</span>
<h2><?php echo '' !== $term ? 'No matching files found' : ( 'documents' === $kind ? 'Your document library is empty' : 'Your image library is empty' ); ?></h2>
<p><?php echo '' !== $term ? 'Try a different search or clear the filter to see all available files.' : ( 'documents' === $kind ? 'Upload your first document to keep your files organized here.' : 'Upload your first image to start building your media library.' ); ?></p>
	<?php if ( '' !== $term ) : ?>
<a class="button button-secondary" href="<?php echo 'documents' === $kind ? 'media.php?kind=documents' : 'media.php'; ?>">Clear search</a>
    <?php else : ?>
<button type="button" data-browse-upload>Browse files</button>
    <?php endif; ?>
</section>
<?php endif; ?>
<div class="media-grid">
<?php
foreach ( $items as $media ) :
	if ( 'document' === $media['kind'] ) :
		?>

<article class="media-card"><h2><?php echo esc_html( $media['original_name'] ); ?></h2><p class="muted"><?php echo esc_html( strtoupper( (string) $media['format'] ) ); ?> ·<?php echo (int) ceil( $media['size'] / 1024 ); ?> KB ·<?php echo 'public' === $media['visibility'] ? 'Public' : 'Private'; ?></p><p><a href="<?php echo esc_url( wp_document_url( $media ) ); ?>">Download (unscanned) ↗</a></p>

		<?php
		if ( get_current_user_id() === (int) $media['author_id'] || current_user_can( 'manage_media' ) ) :
			?>
<form method="post">
			<?php
			wp_nonce_field( 'media' );
			?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" /><input type="hidden" name="visibility" value="<?php echo 'public' === $media['visibility'] ? 'private' : 'public'; ?>" /><button type="submit" name="action" value="visibility">Make <?php echo 'public' === $media['visibility'] ? 'private' : 'public'; ?></button></form>
<form method="post">
			<?php
			wp_nonce_field( 'media' );
			?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" />
			<?php
			if ( ! wp_media_in_use( $media ) ) :
				?>
<p><label class="inline-checkbox"><input type="checkbox" name="confirm_delete" value="yes" /> Confirm removal from library</label></p><button class="button-danger" type="submit" name="action" value="delete">Remove from library</button>
				<?php
			else :
				?>
<p class="description">Used by content or history. Removal is disabled.</p>
				<?php
			endif;
			?>
</form>
			<?php
		endif;
		?>
</article>

		<?php
	else :
		?>

<article class="media-card"><img src="<?php echo esc_url( wp_media_url( $media ) ); ?>" alt="<?php echo esc_attr( $media['alt_text'] ); ?>" loading="lazy" /><h2><?php echo esc_html( $media['original_name'] ); ?></h2><p class="muted"><?php echo (int) $media['width']; ?> ×<?php echo (int) $media['height']; ?> ·<?php echo (int) ceil( $media['size'] / 1024 ); ?> KB</p><p><a href="<?php echo esc_url( wp_media_url( $media ) ); ?>" target="_blank" rel="noopener">Open image ↗</a></p>
		<?php
		$variants = wp_image_variants( (int) $media['id'] );
		if ( [] !== $variants ) :
			echo '<p class="muted">Derived sizes: ';
			$parts = [];
			foreach ( $variants as $name => $variant ) {
				$parts[] = esc_html( $name ) . ' ' . (int) $variant['width'] . '×' . (int) $variant['height'] . ' (' . (int) ceil( $variant['size'] / 1024 ) . ' KB)';
			}
			echo implode( ' · ', $parts ) . '</p>';
        endif;
		if ( null !== $media['parent_id'] ) :
			echo '<p class="muted">Edited copy of image #' . (int) $media['parent_id'] . '.</p>';
        endif;
		$can_edit_media = get_current_user_id() === (int) $media['author_id'] || current_user_can( 'manage_media' );
		if ( $can_edit_media ) :
			?>
<details class="image-tools"><summary>Image tools</summary>
<form method="post">
			<?php wp_nonce_field( 'media' ); ?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" />
<p><label>Focal point X % <input type="number" name="focal_x" min="0" max="100" step="1" value="<?php echo null !== $media['focal_x'] ? (int) round( (float) $media['focal_x'] * 100 ) : 50; ?>" /></label> <label>Y % <input type="number" name="focal_y" min="0" max="100" step="1" value="<?php echo null !== $media['focal_y'] ? (int) round( (float) $media['focal_y'] * 100 ) : 50; ?>" /></label> <button type="submit" name="action" value="focal">Save focal point</button></p>
</form>
<form method="post">
			<?php wp_nonce_field( 'media' ); ?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" />
<p>Crop (pixels, optional): <label>X <input type="number" name="crop_x" min="0" size="5" /></label> <label>Y <input type="number" name="crop_y" min="0" size="5" /></label> <label>W <input type="number" name="crop_w" min="1" size="5" /></label> <label>H <input type="number" name="crop_h" min="1" size="5" /></label></p>
<p><label>Rotate <select name="rotate"><option value="0">None</option><option value="90">90°</option><option value="180">180°</option><option value="270">270°</option></select></label> <button type="submit" name="action" value="edit_image">Create edited copy</button></p>
<p class="description">Editing creates a new image. This image and everything using it stay unchanged.</p>
</form>
<form method="post">
			<?php wp_nonce_field( 'media' ); ?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" />
<p><label>Replace uses of this image with <select name="replacement_id">
			<?php
			foreach ( $wpdb->get_results( "SELECT id,original_name FROM media WHERE kind='image' AND variant='' AND state='ready' AND id<>:id ORDER BY id DESC", [ ':id' => (int) $media['id'] ] ) as $other ) :
				?>
<option value="<?php echo (int) $other['id']; ?>"<?php echo null !== $replace_preview && $replace_preview['old'] === (int) $media['id'] && $replace_preview['new'] === (int) $other['id'] ? ' selected' : ''; ?>>#<?php echo (int) $other['id']; ?> <?php echo esc_html( $other['original_name'] ); ?></option>
				<?php
            endforeach;
			?>
</select></label> <button type="submit" name="action" value="replace_preview">Review replacement</button></p>
</form>
			<?php
			if ( null !== $replace_preview && $replace_preview['old'] === (int) $media['id'] ) :
				$impact = $replace_preview['impact'];
				?>
<div class="replace-impact"><p><strong>Replacement impact.</strong> Current references that would move to the replacement:</p>
<ul>
				<?php
				foreach ( $impact['posts'] as $item ) :
					?>
<li><?php echo esc_html( $item['title'] ); ?> (<?php echo esc_html( $item['post_type'] ); ?>, <?php echo esc_html( $item['status'] ); ?>) — <?php echo $item['featured'] ? 'featured image' : ''; ?><?php echo $item['featured'] && $item['blocks'] > 0 ? ' and ' : ''; ?><?php echo $item['blocks'] > 0 ? $item['blocks'] . ' image block(s)' : ''; ?></li>
					<?php
	            endforeach;
				foreach ( $impact['sections'] as $section ) :
					?>
<li>Shared <?php echo esc_html( $section['location'] ); ?> section — <?php echo $section['blocks']; ?> image block(s), staged as a design draft for publication</li>
					<?php
	            endforeach;
				if ( [] === $impact['posts'] && [] === $impact['sections'] ) :
					?>
<li>Nothing currently references this image.</li>
					<?php
	            endif;
				?>
</ul>
<form method="post">
				<?php wp_nonce_field( 'media' ); ?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" /><input type="hidden" name="replacement_id" value="<?php echo (int) $replace_preview['new']; ?>" /><button type="submit" name="action" value="replace_apply">Apply replacement to the listed items</button>
</form>
<p class="description">Avatars and the site logo are never changed by replacement. Post and design history keeps the original references.</p>
</div>
				<?php
            endif;
			?>
</details>
			<?php
        endif;
		?>

		<?php
		if ( get_current_user_id() === (int) $media['author_id'] || current_user_can( 'manage_media' ) ) :
			?>

<form method="post">
			<?php
			wp_nonce_field( 'media' );
			?>
<input type="hidden" name="id" value="<?php echo (int) $media['id']; ?>" /><label for="alt-<?php echo (int) $media['id']; ?>">Alternative text</label><input id="alt-<?php echo (int) $media['id']; ?>" name="alt_text" value="<?php echo esc_attr( $media['alt_text'] ); ?>" /><label for="caption-<?php echo (int) $media['id']; ?>">Default caption</label><input id="caption-<?php echo (int) $media['id']; ?>" name="caption" value="<?php echo esc_attr( $media['caption'] ); ?>" /><button type="submit" name="action" value="update">Save details</button>

			<?php
			if ( ! wp_media_in_use( $media ) ) :
				?>
<p><label class="inline-checkbox"><input type="checkbox" name="confirm_delete" value="yes" /> Confirm removal from library</label></p><button class="button-danger" type="submit" name="action" value="delete">Remove from library</button>
				<?php
			else :
				?>
<p class="description">Used by content or history. Removal is disabled.</p>
				<?php
			endif;
			?>
</form>
			<?php
		endif;
		?>
</article>

		<?php
	endif;
endforeach;
?>
</div>
<nav class="pagination" aria-label="Media pages">
<?php
foreach ( [
	$page - 1 => 'Previous',
	$page + 1 => 'Next'
] as $number => $label ) :
	if ( $number < 1 || ( $number - 1 ) * 20 >= $total ) {
		continue;
	}
	?>
<a href="<?php echo esc_url( 'media.php?' . http_build_query( [
	'kind' => $kind,
	's'    => $term,
	'page' => $number
] ) ); ?>"><?php echo esc_html( $label ); ?></a>
	<?php
endforeach;
?>
</nav>
</section>
<?php require __DIR__ . '/admin-footer.php';
