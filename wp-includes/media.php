<?php
/** Immutable raster uploads. Uploaded bytes never become executable PHP. */
declare( strict_types=1 );
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/assets.php';
function wp_media_url( array $media ): string
{
	return wp_home_url( '/wp-content/uploads/' . $media['filename'] );
}
function wp_handle_upload( array $file ): int|WP_Error
{
    try {
		return wp_asset_storage_lock( static fn(): int|WP_Error => wp_handle_image_upload( $file ) );

	} catch ( Throwable $error ) {
		return wp_failure( 'upload_failed', $error, 'Storage is busy. Retry the upload.' ); 
	}
}
function wp_handle_image_upload( array $file ): int|WP_Error
{
	global $wpdb;
	if ( ! current_user_can( 'upload_media' ) ) {
		return new WP_Error( 'unauthorized', 'You cannot upload media.' );
	}
	$path = $file['tmp_name'] ?? '';
	if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_string( $path ) || ! is_uploaded_file( $path ) ) {
		return new WP_Error( 'upload_failed', 'Select a valid uploaded image.' );
	}
	$normalized = wp_normalize_image( $path );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}
	$path = $normalized['path'];
	$mime = $normalized['mime'];
	$dimensions = [ $normalized['width'], $normalized['height'] ];
	$size = $normalized['size'];
	$hash = hash_file( 'sha256', $path );
	$existing = $wpdb->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='ready' AND variant=''", [ ':hash' => $hash ] );
	if ( null !== $existing ) {
		unlink( $path );
		wp_image_generate_variants( (int) $existing['id'] );
		return (int) $existing['id'];
	}
	$relative = substr( $hash, 0, 2 ) . '/' . $hash . '.' . $normalized['extension'];
	$destination = WP_ROOT_DIR . '/wp-content/uploads/' . $relative;
	$directory = dirname( $destination );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) ) {
		unlink( $path );
		return new WP_Error( 'upload_failed', 'The uploads directory is not writable.' );
	}
	if ( ! rename( $path, $destination ) ) {
		unlink( $path );
		return new WP_Error( 'upload_failed', 'The image could not be stored.' );
	}
	chmod( $destination, 0644 );
	try {
		$id = $wpdb->transaction( static function ( $db ) use ( $relative, $hash, $mime, $dimensions, $size, $file, $normalized ): int {
			if ( ! current_user_can( 'upload_media' ) ) {
				throw new RuntimeException( 'Upload permission was revoked.' );
			}
			wp_asset_check_quota( $size, $db );
            $db->query( 'INSERT INTO media(author_id,sha256,filename,mime,original_name,width,height,size,format) VALUES(:author,:hash,:filename,:mime,:name,:width,:height,:size,:format)', [
				':author'   => get_current_user_id(),
				':hash'     => $hash,
				':filename' => $relative,
				':mime'     => $mime,
				':name'     => sanitize_text_field( basename( (string) ( $file['name'] ?? 'image' ) ) ),
				':width'    => $dimensions[0],
				':height'   => $dimensions[1],
				':size'     => $size,
                ':format'   => $normalized['extension']
			] );
			$id = (int) $db->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='ready' AND variant=''", [ ':hash' => $hash ] )['id'];
			wp_audit( 'media_uploaded', $id );
			return $id;
		} );
	} catch ( Throwable $error ) {
		return wp_failure( 'upload_failed', $error, 'The image could not be registered. Check storage and retry.' );
	}
	wp_image_generate_variants( $id );
	return $id;
}
/** Live posts whose block content references an asset id (used to invalidate cached renders). */
function wp_asset_referencing_post_ids( int $asset_id ): array
{
	global $wpdb;
	$contains = static function ( mixed $data ) use ( &$contains, $asset_id ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( (int) ( $data['media_id'] ?? 0 ) === $asset_id ) {
			return true;
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) && $contains( $value ) ) {
				return true;
			}
		}
		return false;
	};
	$ids = [];
	foreach ( $wpdb->get_results( 'SELECT id,content_raw FROM posts' ) as $post ) {
		if ( $contains( json_decode( $post['content_raw'], true ) ) ) {
			$ids[] = (int) $post['id'];
		}
	}
	return $ids;
}
function wp_media_in_use( array $media ): bool
{
	global $wpdb;
	if ( (int) get_option( 'site_logo_id', 0 ) === (int) $media['id'] ) {
		return true;
	}
	$contains = static function ( mixed $data ) use ( &$contains, $media ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( (int) ( $data['media_id'] ?? 0 ) === (int) $media['id'] ) {
			return true;
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) && $contains( $value ) ) {
				return true;
			}
		}
		return false;
	};
	if ( null !== $wpdb->get_row( 'SELECT id FROM users WHERE avatar_media_id=:id LIMIT 1', [ ':id' => $media['id'] ] ) ) {
		return true; 
	}
	foreach ( $wpdb->get_results( 'SELECT content_raw,content_rendered,featured_media_id FROM posts' ) as $post ) {
		if ( (int) $post['featured_media_id'] === (int) $media['id'] || $contains( json_decode( $post['content_raw'], true ) ) || str_contains( $post['content_raw'] . ( $post['content_rendered'] ?? '' ), $media['filename'] ) ) {
			return true;
		}
	}
	foreach ( $wpdb->get_results( 'SELECT snapshot FROM post_revisions' ) as $row ) {
		$post = json_decode( $row['snapshot'], true );
		if ( (int) ( $post['featured_media_id'] ?? 0 ) === (int) $media['id'] || $contains( json_decode( $post['content_raw'] ?? '', true ) ) || str_contains( $row['snapshot'], $media['filename'] ) ) {
			return true;
		}
	}
    foreach ( $wpdb->get_results('SELECT config FROM design_revisions') as $row ) {
        $config = json_decode($row['config'],true) ?: [];
        if ( (int)($config['site_logo_id'] ?? 0) === (int)$media['id'] || str_contains($row['config'],$media['filename']) ) {
			return true;
		}
        foreach ( $config['sections'] ?? [] as $section ) {
			if ( $contains(json_decode($section,true)) ) {
				return true;
			}
		}
    }

	return false;
}
/** Ready derived rows for a master, keyed by variant name. */
function wp_image_variants( int $master_id ): array
{
	global $wpdb;
	$variants = [];
	foreach ( $wpdb->get_results( "SELECT * FROM media WHERE parent_id=:parent AND variant<>'' AND state='ready' ORDER BY width", [ ':parent' => $master_id ] ) as $row ) {
		$variants[ (string) $row['variant'] ] = $row;
	}
	return $variants;
}
/** srcset candidates from the ready variants plus the master itself, ascending by width. */
function wp_image_srcset( array $media ): string
{
	if ( '' !== ( $media['variant'] ?? '' ) || 'image' !== $media['kind'] ) {
		return '';
	}
	$candidates = [ (int) $media['width'] => wp_media_url( $media ) ];
	foreach ( wp_image_variants( (int) $media['id'] ) as $variant ) {
		$candidates[ (int) $variant['width'] ] = wp_media_url( $variant );
	}
	ksort( $candidates );
	$parts = [];
	foreach ( $candidates as $width => $url ) {
		$parts[] = $url . ' ' . $width . 'w';
	}
	return implode( ', ', $parts );
}
function wp_image_sizes_attr(): string
{
	return '(max-width: 1200px) 100vw, 1200px';
}
/** An <img> tag for a master with its responsive candidates, used by every renderer. */
function wp_image_tag( array $media, string $alt, string $extra = '' ): string
{
	$srcset = wp_image_srcset( $media );
	$set = '' !== $srcset ? ' srcset="' . esc_attr( $srcset ) . '" sizes="' . esc_attr( wp_image_sizes_attr() ) . '"' : '';
	return '<img src="' . esc_url( wp_media_url( $media ) ) . '"' . $set . ' alt="' . esc_attr( $alt ) . '" width="' . (int) $media['width'] . '" height="' . (int) $media['height'] . '"' . $extra . ' />';
}
/**
 * Generates (or completes) the derived sizes for a ready master. Each variant is
 * registered pending with its source and transformation recorded (parent + variant
 * name), its bytes placed at the canonical path and verified, then flipped ready.
 * A failure never fails the master; the pending row is left for maintenance, which
 * regenerates from the master or removes the registration and releases its quota.
 */
function wp_image_generate_variants( int $master_id, ?string $only = null ): void
{
	global $wpdb;
	try {
		wp_asset_storage_lock( static function () use ( $master_id, $only, $wpdb ): void {
			$master = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='image' AND variant='' AND state='ready'", [ ':id' => $master_id ] );
			if ( null === $master ) {
				return;
			}
			$source = WP_ROOT_DIR . '/wp-content/uploads/' . $master['filename'];
			if ( ! is_file( $source ) ) {
				wp_audit( 'image_variants_failed', $master_id );
				return;
			}
			$fx = null !== $master['focal_x'] ? (float) $master['focal_x'] : 0.5;
			$fy = null !== $master['focal_y'] ? (float) $master['focal_y'] : 0.5;
			foreach ( wp_image_variant_sizes( (int) $master['width'], (int) $master['height'] ) as $name => [ $tw, $th ] ) {
				if ( null !== $only && $only !== $name ) {
					continue;
				}
				$existing = $wpdb->get_row( 'SELECT * FROM media WHERE parent_id=:parent AND variant=:variant', [
					':parent'  => $master_id,
					':variant' => $name
				] );
				if ( null !== $existing && 'ready' === $existing['state'] ) {
					continue;
				}
				$ops = [ 'resize' => [ $tw, $th ] ];
				if ( 'thumbnail' === $name ) {
					$ops['focal'] = [ $fx, $fy ];
				}
				$result = wp_image_transform( $source, $ops );
				if ( is_wp_error( $result ) ) {
					wp_audit( 'image_variant_failed', $master_id );
					continue;
				}
				$hash = (string) hash_file( 'sha256', $result['path'] );
				$relative = substr( $hash, 0, 2 ) . '/' . $hash . '.' . $result['extension'];
				$destination = WP_ROOT_DIR . '/wp-content/uploads/' . $relative;
				try {
					$id = null !== $existing ? (int) $existing['id'] : null;
					$wpdb->transaction( static function ( $db ) use ( &$id, $master, $name, $hash, $relative, $result ): void {
						if ( null === $id ) {
							wp_asset_check_quota( (int) $result['size'], $db );
							$db->query( "INSERT INTO media(author_id,sha256,filename,mime,original_name,width,height,size,kind,format,visibility,state,parent_id,variant) VALUES(:author,:hash,:filename,:mime,:name,:width,:height,:size,'image',:format,'public','pending',:parent,:variant)", [
								':author'   => (int) $master['author_id'],
								':hash'     => $hash,
								':filename' => $relative,
								':mime'     => $result['mime'],
								':name'     => $master['original_name'] . ' (' . $name . ')',
								':width'    => $result['width'],
								':height'   => $result['height'],
								':size'     => $result['size'],
								':format'   => $result['extension'],
								':parent'   => (int) $master['id'],
								':variant'  => $name,
							] );
							$id = (int) $db->get_row( 'SELECT id FROM media WHERE parent_id=:parent AND variant=:variant', [
								':parent'  => (int) $master['id'],
								':variant' => $name
							] )['id'];
						} else {
							$db->query( 'UPDATE media SET sha256=:hash,filename=:filename,mime=:mime,width=:width,height=:height,size=:size,format=:format WHERE id=:id', [
								':hash'     => $hash,
								':filename' => $relative,
								':mime'     => $result['mime'],
								':width'    => $result['width'],
								':height'   => $result['height'],
								':size'     => $result['size'],
								':format'   => $result['extension'],
								':id'       => $id,
							] );
						}
					} );
					if ( is_file( $destination ) ) {
						if ( ! hash_equals( $hash, (string) hash_file( 'sha256', $destination ) ) ) {
							wp_audit( 'image_variant_blocked', (int) $id );
							unlink( $result['path'] );
							continue;
						}
						unlink( $result['path'] );
					} else {
						$directory = dirname( $destination );
						if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) ) {
							throw new RuntimeException( 'The uploads directory is not writable.' );
						}
						if ( ! rename( $result['path'], $destination ) ) {
							throw new RuntimeException( 'The variant could not be stored.' );
						}
						chmod( $destination, 0644 );
					}
					$wpdb->transaction( static function ( $db ) use ( $id ): void {
						$db->query( "UPDATE media SET state='ready' WHERE id=:id AND state='pending'", [ ':id' => $id ] );
						wp_audit( 'asset_ready', (int) $id );
					} );
				} catch ( Throwable $error ) {
					if ( is_file( $result['path'] ) ) {
						unlink( $result['path'] );
					}
					wp_audit( 'image_variant_failed', $master_id );
				}
			}
		} );
	} catch ( Throwable $error ) {
		// Variant generation is best-effort beside a valid master; maintenance reconciles.
	}
}
/** Sets the focal point on a master and regenerates its thumbnail around it. */
function wp_image_set_focal( int $master_id, float $fx, float $fy ): bool|WP_Error
{
	global $wpdb;
	if ( ! current_user_can( 'upload_media' ) ) {
		return new WP_Error( 'unauthorized', 'You cannot edit media.' );
	}
	if ( $fx < 0.0 || $fx > 1.0 || $fy < 0.0 || $fy > 1.0 ) {
		return new WP_Error( 'invalid_focal', 'The focal point must lie inside the image.' );
	}
	$master = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='image' AND variant='' AND state='ready'", [ ':id' => $master_id ] );
	if ( null === $master ) {
		return new WP_Error( 'not_found', 'Image not found.' );
	}
	$wpdb->transaction( static function ( $db ) use ( $master_id, $fx, $fy ): void {
		$db->query( 'UPDATE media SET focal_x=:fx,focal_y=:fy WHERE id=:id', [
			':fx' => $fx,
			':fy' => $fy,
			':id' => $master_id
		] );
		$db->query( "DELETE FROM media WHERE parent_id=:parent AND variant='thumbnail'", [ ':parent' => $master_id ] );
	} );
	wp_image_generate_variants( $master_id, 'thumbnail' );
	wp_audit( 'image_focal_set', $master_id );
	return true;
}
/**
 * Creates an edited copy of a master (crop and/or rotate). The copy is a new
 * master whose parent names the source; the source and every reference to it
 * are untouched. Identical output bytes return the existing master, as uploads do.
 */
function wp_image_create_edit( int $master_id, array $ops ): int|WP_Error
{
	global $wpdb;
	if ( ! current_user_can( 'upload_media' ) ) {
		return new WP_Error( 'unauthorized', 'You cannot edit media.' );
	}
	$master = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='image' AND variant='' AND state='ready'", [ ':id' => $master_id ] );
	if ( null === $master ) {
		return new WP_Error( 'not_found', 'Image not found.' );
	}
	$transform = [];
	if ( isset( $ops['crop'] ) ) {
		$crop = array_map( 'intval', (array) $ops['crop'] );
		if ( 4 !== count( $crop ) ) {
			return new WP_Error( 'invalid_crop', 'Crop needs x, y, width and height.' );
		}
		$transform['crop'] = $crop;
	}
	if ( isset( $ops['rotate'] ) && 0 !== (int) $ops['rotate'] ) {
		if ( ! in_array( (int) $ops['rotate'], [ 90, 180, 270 ], true ) ) {
			return new WP_Error( 'invalid_rotate', 'Rotate by 90, 180 or 270 degrees.' );
		}
		$transform['rotate'] = (int) $ops['rotate'];
	}
	if ( [] === $transform ) {
		return new WP_Error( 'no_change', 'Choose a crop or a rotation.' );
	}
	$source = WP_ROOT_DIR . '/wp-content/uploads/' . $master['filename'];
	$result = wp_image_transform( $source, $transform );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$hash = (string) hash_file( 'sha256', $result['path'] );
	$existing = $wpdb->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='ready' AND variant=''", [ ':hash' => $hash ] );
	if ( null !== $existing ) {
		unlink( $result['path'] );
		return (int) $existing['id'];
	}
	$relative = substr( $hash, 0, 2 ) . '/' . $hash . '.' . $result['extension'];
	$destination = WP_ROOT_DIR . '/wp-content/uploads/' . $relative;
	$id = null;
	try {
		$wpdb->transaction( static function ( $db ) use ( &$id, $master, $hash, $relative, $result ): void {
			if ( ! current_user_can( 'upload_media' ) ) {
				throw new RuntimeException( 'Edit permission was revoked.' );
			}
			wp_asset_check_quota( (int) $result['size'], $db );
			$db->query( "INSERT INTO media(author_id,sha256,filename,mime,original_name,width,height,size,kind,format,visibility,state,parent_id,variant,focal_x,focal_y,alt_text,caption) VALUES(:author,:hash,:filename,:mime,:name,:width,:height,:size,'image',:format,'public','pending',:parent,'',:fx,:fy,:alt,:caption)", [
				':author'   => get_current_user_id(),
				':hash'     => $hash,
				':filename' => $relative,
				':mime'     => $result['mime'],
				':name'     => $master['original_name'],
				':width'    => $result['width'],
				':height'   => $result['height'],
				':size'     => $result['size'],
				':format'   => $result['extension'],
				':parent'   => (int) $master['id'],
				':fx'       => $master['focal_x'],
				':fy'       => $master['focal_y'],
				':alt'      => $master['alt_text'],
				':caption'  => $master['caption'],
			] );
			$id = (int) $db->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='pending' AND variant=''", [ ':hash' => $hash ] )['id'];
		} );
		if ( is_file( $destination ) ) {
			if ( ! hash_equals( $hash, (string) hash_file( 'sha256', $destination ) ) ) {
				throw new RuntimeException( 'Existing asset bytes are corrupt.' );
			}
			unlink( $result['path'] );
		} else {
			$directory = dirname( $destination );
			if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) ) {
				throw new RuntimeException( 'The uploads directory is not writable.' );
			}
			if ( ! rename( $result['path'], $destination ) ) {
				throw new RuntimeException( 'The edited image could not be stored.' );
			}
			chmod( $destination, 0644 );
		}
		$wpdb->transaction( static function ( $db ) use ( $id ): void {
			$db->query( "UPDATE media SET state='ready' WHERE id=:id AND state='pending'", [ ':id' => $id ] );
			wp_audit( 'image_edited', (int) $id );
		} );
	} catch ( Throwable $error ) {
		if ( is_file( $result['path'] ) ) {
			unlink( $result['path'] );
		}
		if ( null !== $id ) {
			wp_asset_fail( $id );
		}
		return wp_failure( 'edit_failed', $error, 'The edited copy could not be stored. Check storage and retry.' );
	}
	wp_image_generate_variants( (int) $id );
	return (int) $id;
}
/** Counts image blocks referencing an asset inside decoded block data (any nesting). */
function wp_image_block_count( mixed $data, int $asset_id ): int
{
	if ( ! is_array( $data ) ) {
		return 0;
	}
	$count = ( ( $data['type'] ?? '' ) === 'image' && (int) ( $data['media_id'] ?? 0 ) === $asset_id ) ? 1 : 0;
	foreach ( $data as $value ) {
		if ( is_array( $value ) ) {
			$count += wp_image_block_count( $value, $asset_id );
		}
	}
	return $count;
}
/** Returns block data with image-block references moved from one master to another. */
function wp_image_block_rewrite( mixed $data, int $old_id, int $new_id ): mixed
{
	if ( ! is_array( $data ) ) {
		return $data;
	}
	if ( ( $data['type'] ?? '' ) === 'image' && (int) ( $data['media_id'] ?? 0 ) === $old_id ) {
		$data['media_id'] = $new_id;
	}
	foreach ( $data as $key => $value ) {
		if ( is_array( $value ) ) {
			$data[ $key ] = wp_image_block_rewrite( $value, $old_id, $new_id );
		}
	}
	return $data;
}
/**
 * The reviewable impact of replacing one master image with another: every current
 * featured-image and image-block reference in posts, and every shared-section
 * reference in the live design. Historical revisions are never counted or touched.
 */
function wp_image_replacement_impact( int $old_id, int $new_id ): array|WP_Error
{
	global $wpdb;
	foreach ( [ $old_id, $new_id ] as $id ) {
		$row = $wpdb->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND variant='' AND state='ready'", [ ':id' => $id ] );
		if ( null === $row ) {
			return new WP_Error( 'not_found', 'Both images must be ready library images.' );
		}
	}
	if ( $old_id === $new_id ) {
		return new WP_Error( 'no_change', 'Choose a different image as the replacement.' );
	}
	$posts = [];
	foreach ( $wpdb->get_results( 'SELECT id,title,post_type,status,featured_media_id,content_raw FROM posts ORDER BY id' ) as $post ) {
		$featured = (int) $post['featured_media_id'] === $old_id;
		$blocks = wp_image_block_count( json_decode( (string) $post['content_raw'], true ), $old_id );
		if ( $featured || $blocks > 0 ) {
			$posts[] = [
				'id'        => (int) $post['id'],
				'title'     => (string) $post['title'],
				'post_type' => (string) $post['post_type'],
				'status'    => (string) $post['status'],
				'featured'  => $featured,
				'blocks'    => $blocks
			];
		}
	}
	$sections = [];
	$live = wp_design_live();
	foreach ( [ 'header', 'footer' ] as $location ) {
		$blocks = wp_image_block_count( json_decode( (string) ( $live['sections'][ $location ] ?? '' ), true ), $old_id );
		if ( $blocks > 0 ) {
			$sections[] = [
				'location' => $location,
				'blocks'   => $blocks
			];
		}
	}
	return [
		'posts'    => $posts,
		'sections' => $sections
	];
}
/**
 * Applies a replacement after a full permission pre-check: every affected post must
 * be editable by the acting user under the same rule the mutation service enforces,
 * and shared sections require an administrator. Any unauthorized or trashed target
 * rejects the whole operation before anything changes. Posts are updated through
 * the mutation service (history is preserved); shared sections are staged as an
 * unpublished design draft that takes effect only through design publication.
 */
function wp_image_apply_replacement( int $old_id, int $new_id ): array|WP_Error
{
	global $wpdb;
	$impact = wp_image_replacement_impact( $old_id, $new_id );
	if ( is_wp_error( $impact ) ) {
		return $impact;
	}
	$actor = get_current_user_id();
	$blocked = [];
	foreach ( $impact['posts'] as $item ) {
		$post = $wpdb->get_row( 'SELECT author_id,status,post_type FROM posts WHERE id=:id', [ ':id' => $item['id'] ] );
		if ( null === $post || 'trashed' === $post['status'] ) {
			$blocked[] = $item['title'] . ' (restore it from the Trash first)';
			continue;
		}
		$plural = 'page' === $post['post_type'] ? 'pages' : 'posts';
		$required = [ 'edit_' . $plural ];
		if ( (int) $post['author_id'] !== $actor ) {
			$required[] = 'edit_others_' . $plural;
		}
		if ( 'published' === $post['status'] ) {
			$required[] = 'edit_published_' . $plural;
		}
		foreach ( $required as $capability ) {
			if ( ! user_can( $actor, $capability ) ) {
				$blocked[] = $item['title'];
				break;
			}
		}
	}
	if ( [] !== $impact['sections'] && ! user_can( $actor, 'administrator' ) ) {
		$blocked[] = 'shared sections (administrator required)';
	}
	if ( [] !== $blocked ) {
		return new WP_Error( 'replacement_rejected', 'Replacement was not applied. You cannot change: ' . implode( ', ', array_unique( $blocked ) ) . '.' );
	}
	$draft_id = null;
	if ( [] !== $impact['sections'] ) {
		try {
			$config = wp_design_live();
			foreach ( $impact['sections'] as $section ) {
				$decoded = json_decode( (string) $config['sections'][ $section['location'] ], true );
				$config['sections'][ $section['location'] ] = wp_json_encode( wp_image_block_rewrite( $decoded, $old_id, $new_id ) );
			}
			$draft_id = wp_design_draft( $config, wp_design_version() );
		} catch ( Throwable $error ) {
			return wp_failure( 'replacement_failed', $error, 'The shared-section draft could not be staged. Nothing was changed.' );
		}
	}
	$updated = 0;
	foreach ( $impact['posts'] as $item ) {
		$post = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $item['id'] ] );
		if ( null === $post ) {
			continue;
		}
		$content = (string) $post['content_raw'];
		if ( $item['blocks'] > 0 ) {
			$content = wp_json_encode( wp_image_block_rewrite( json_decode( $content, true ), $old_id, $new_id ) );
		}
		$result = wp_update_post( [
			'ID'                => $item['id'],
			'post_title'        => (string) $post['title'],
			'post_name'         => (string) $post['slug'],
			'post_content'      => $content,
			'post_excerpt'      => (string) $post['excerpt'],
			'post_status'       => (string) $post['status'],
			'featured_media_id' => $item['featured'] ? (string) $new_id : (string) ( $post['featured_media_id'] ?? '' ),
			'expected_revision' => (string) $post['revision'],
		] );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'replacement_partial', 'Replacement stopped at "' . $item['title'] . '": ' . $result->get_error_message() . ' ' . $updated . ' item(s) were already updated' . ( null !== $draft_id ? ' and the shared-section draft was staged' : '' ) . '. Review the impact and retry.' );
		}
		++$updated;
	}
	wp_audit( 'image_replaced', $old_id );
	return [
		'posts_updated'    => $updated,
		'section_draft_id' => $draft_id
	];
}
