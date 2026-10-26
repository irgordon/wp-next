<?php
/** Serialized asset storage primitives, document intake and routed document delivery. */
declare( strict_types=1 );
/** Storage changes and backup file reads share one lock, acquired before database write locks. */
function wp_asset_storage_lock( callable $operation ): mixed
{
    static $depth = 0;
    if ( $depth > 0 ) {
        ++$depth;
        try {
			return $operation();

		} finally {
			--$depth; 
		}
    }
    $directory = WP_VAR_DIR . '/locks';
    if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Cannot create storage lock directory.' ); 
	}
    $handle = fopen( $directory . '/assets.lock', 'c+' );
    if ( false === $handle ) {
		throw new RuntimeException( 'Cannot open storage lock.' ); 
	}
    $deadline = microtime( true ) + 3;
    while ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
        if ( microtime( true ) >= $deadline ) {
			fclose( $handle );
			throw new RuntimeException( 'Asset storage is busy.' ); 
		}
        usleep( 25000 );
    }
    $depth = 1;
    try {
		return $operation();

	} finally {
		$depth = 0;
		flock( $handle, LOCK_UN );
		fclose( $handle ); 
	}
}
function wp_assets_dir(): string
{
    return defined( 'WP_ASSETS_DIR' ) ? WP_ASSETS_DIR : WP_VAR_DIR . '/assets';
}
function wp_asset_staging_dir(): string
{
	return WP_VAR_DIR . '/staging'; 
}
function wp_asset_staged_path( string $sha256 ): string
{
    if ( ! preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
		throw new RuntimeException( 'Invalid asset digest.' ); 
	}
    return wp_asset_staging_dir() . '/' . $sha256 . '.part';
}
/** Canonical format and storage path validation never trust a supplied filename. */
function wp_asset_blob_path( array $asset ): string
{
    $hash = (string) $asset['sha256'];
    wp_asset_staged_path( $hash );
    $prefix = substr( $hash, 0, 2 ) . '/' . $hash;
    $formats = 'image' === $asset['kind'] ? [ 'jpg', 'png', 'webp', 'gif' ] : [ 'pdf', 'txt', 'md', 'csv' ];
    if ( ! in_array( $asset['kind'], [ 'image', 'document' ], true ) || ! in_array( $asset['format'], $formats, true ) ) {
		throw new RuntimeException( 'Invalid asset format.' ); 
	}
    $expected = $prefix . ( 'image' === $asset['kind'] ? '.' . $asset['format'] : '' );
    if ( $asset['filename'] !== $expected ) {
		throw new RuntimeException( 'Unexpected asset filename.' ); 
	}
    $root = 'document' === $asset['kind'] ? wp_assets_dir() : WP_ROOT_DIR . '/wp-content/uploads';
    $path = $root . '/' . $expected;
    if ( is_link( $root ) || is_link( dirname( $path ) ) || is_link( $path ) ) {
		throw new RuntimeException( 'Asset paths cannot be symbolic links.' ); 
	}
    return $path;
}
function wp_asset_is_ready( array $asset ): bool
{
	return 'ready' === $asset['state']; 
}
function wp_asset_can_access( array $asset ): bool
{
    if ( wp_asset_is_ready( $asset ) && 'public' === $asset['visibility'] ) {
		return true; 
	}
    $actor = get_current_user_id();
    return $actor > 0 && ( $actor === (int) $asset['author_id'] || current_user_can( 'manage_media' ) );
}
/** Access for an explicit actor, independent of the request's current user (used inside write transactions). */
function wp_asset_can_access_as( array $asset, int $actor_id ): bool
{
    if ( wp_asset_is_ready( $asset ) && 'public' === $asset['visibility'] ) {
		return true;
	}
    return $actor_id > 0 && ( $actor_id === (int) $asset['author_id'] || user_can( $actor_id, 'manage_media' ) );
}
/**
 * Document URLs are baked into cached renders, so any change that can move a /documents path
 * between a document and a post — permalink settings, publication, author or category changes —
 * invalidates every render embedding a File block. The generation bump also fences results a
 * worker prepared before the change. Call inside the changing write transaction.
 */
function wp_invalidate_file_link_renders( \WordPress\Database\WP_SQLite_DB $db ): void
{
    $queue = new \WordPress\Queue\WP_Job_Queue( $db );
    // The generation bump fences every File-block post, rendered or not: a post whose
    // cache was already invalidated can still hold an in-flight render prepared under
    // the old routing, and a second routing change must fence that render too. Only
    // posts with a cached render need a re-render enqueued.
    foreach ( $db->get_results( "SELECT id,revision,metadata,rendered_revision FROM posts WHERE content_raw LIKE '%\"type\":\"file\"%'" ) as $post ) {
        $metadata = json_decode( (string) ( $post['metadata'] ?? '' ), true );
        if ( ! is_array( $metadata ) ) {
			$metadata = [];
        }
        $metadata['render_epoch'] = (int) ( $metadata['render_epoch'] ?? 0 ) + 1;
        $db->query( 'UPDATE posts SET rendered_revision=0,metadata=:metadata WHERE id=:id', [
			':metadata' => wp_json_encode( $metadata ),
			':id'       => (int) $post['id']
        ] );
        if ( (int) $post['rendered_revision'] > 0 ) {
            $queue->enqueue_in_transaction( 'WordPress\Jobs\Render_Post_Job', [
				'post_id'         => (int) $post['id'],
				'target_revision' => (int) $post['revision']
            ] );
        }
    }
}
function wp_asset_stage( string $source, string $sha256 ): string|WP_Error
{
    try {
        return wp_asset_storage_lock( static function () use ( $source, $sha256 ): string {
            $staged = wp_asset_staged_path( $sha256 );
            if ( ! is_file( $source ) || is_link( $source ) || ! hash_equals( $sha256, (string) hash_file( 'sha256', $source ) ) ) {
				throw new RuntimeException( 'Intake hash mismatch.' ); 
			}
            $directory = wp_asset_staging_dir();
            if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) || is_link( $staged ) ) {
				throw new RuntimeException( 'Unsafe staging path.' ); 
			}
            if ( $source === $staged ) {
				return $staged; 
			}
            if ( is_file( $staged ) ) {
                if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $staged ) ) ) {
					throw new RuntimeException( 'Existing staging file is corrupt.' ); 
				}
                unlink( $source );
                touch( $staged );
				return $staged;
            }
            if ( ! rename( $source, $staged ) ) {
				throw new RuntimeException( 'Could not stage file.' ); 
			}
            chmod( $staged, 0600 );
			return $staged;
        } );
    } catch ( Throwable $error ) {
		return wp_failure( 'staging_failed', $error, 'The file could not be staged.' ); 
	}
}
function wp_asset_check_quota( int $size, \WordPress\Database\WP_SQLite_DB $db ): void
{
    $quota = (int) get_option( 'assets_quota_bytes', 0 );
    $used = (int) $db->get_row( 'SELECT COALESCE(SUM(size),0) AS total FROM media' )['total'];
    if ( $size < 0 || ( $quota > 0 && $size > $quota - $used ) ) {
		throw new RuntimeException( 'Storage quota exceeded.' ); 
	}
}
function wp_asset_register( array $data ): int|WP_Error
{
    global $wpdb;
    try {
        return wp_asset_storage_lock( static function () use ( $data, $wpdb ): int {
            wp_asset_blob_path( $data );
            return $wpdb->transaction( static function ( $db ) use ( $data ): int {
                if ( ! current_user_can( 'upload_media' ) || get_current_user_id() !== (int) $data['author_id'] ) {
					throw new RuntimeException( 'Asset registration is not authorized.' ); 
				}
                wp_asset_check_quota( (int) $data['size'], $db );
                $staged = wp_asset_staged_path( (string) $data['sha256'] );
                $destination = wp_asset_blob_path( $data );
                $source = is_file( $staged ) ? $staged : $destination;
                if ( ! is_file( $source ) || ! hash_equals( (string) $data['sha256'], (string) hash_file( 'sha256', $source ) ) || filesize( $source ) !== (int) $data['size'] ) {
					throw new RuntimeException( 'Validated asset bytes are unavailable.' ); 
				}
                $db->query( 'INSERT INTO media(author_id,sha256,filename,mime,original_name,width,height,size,kind,format,visibility,state) VALUES(:author,:hash,:filename,:mime,:name,:width,:height,:size,:kind,:format,:visibility,\'pending\')', [
                    ':author'     => get_current_user_id(),
					':hash'       => $data['sha256'],
					':filename'   => $data['filename'],
					':mime'       => $data['mime'],
					':name'       => sanitize_text_field( $data['original_name'] ),
					':width'      => $data['width'] ?? null,
					':height'     => $data['height'] ?? null,
					':size'       => (int) $data['size'],
					':kind'       => $data['kind'],
					':format'     => $data['format'],
					':visibility' => $data['visibility']
                ] );
                $id = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
				wp_audit( 'asset_registered', $id );
				return $id;
            } );
        } );
    } catch ( Throwable $error ) {
		return wp_failure( 'registration_failed', $error, 'The asset could not be registered. Check permission, quota and staged bytes.' ); 
	}
}
function wp_asset_promote( int $id ): bool|WP_Error
{
    global $wpdb;
    try {
        return wp_asset_storage_lock( static function () use ( $id, $wpdb ): bool {
            $asset = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => $id ] );
            if ( null === $asset ) {
				throw new RuntimeException( 'Asset not found.' ); 
			}
            $destination = wp_asset_blob_path( $asset );
            $hash = (string) $asset['sha256'];
            if ( is_file( $destination ) ) {
                if ( ! hash_equals( $hash, (string) hash_file( 'sha256', $destination ) ) || filesize( $destination ) !== (int) $asset['size'] ) {
					throw new RuntimeException( 'Existing asset bytes are corrupt.' ); 
				}
            } else {
                $staged = wp_asset_staged_path( $hash );
                if ( is_link( $staged ) || ! is_file( $staged ) || ! hash_equals( $hash, (string) hash_file( 'sha256', $staged ) ) || filesize( $staged ) !== (int) $asset['size'] ) {
					throw new RuntimeException( 'Staged bytes are missing or corrupt.' ); 
				}
                if ( ! is_dir( dirname( $destination ) ) && ! mkdir( dirname( $destination ), 'document' === $asset['kind'] ? 0700 : 0755, true ) ) {
					throw new RuntimeException( 'Could not create asset directory.' ); 
				}
                if ( ! rename( $staged, $destination ) ) {
					throw new RuntimeException( 'Could not promote asset.' ); 
				}
                chmod( $destination, 'document' === $asset['kind'] ? 0600 : 0644 );
            }
            $wpdb->transaction( static function ( $db ) use ( $id ): void {
                $db->query( "UPDATE media SET state='ready' WHERE id=:id AND state='pending'", [ ':id' => $id ] );
                wp_audit( 'asset_ready', $id );
            } );
            return true;
        } );
    } catch ( Throwable $error ) {
		return wp_failure( 'promotion_failed', $error, 'The asset could not be promoted. Recovery will retry.' ); 
	}
}
function wp_asset_fail( int $id ): void
{
    global $wpdb;
    wp_asset_storage_lock( static function () use ( $wpdb, $id ): void {
        $wpdb->transaction( static function ( $db ) use ( $id ): void {
            $stmt = $db->query( "DELETE FROM media WHERE id=:id AND state='pending'", [ ':id' => $id ] );
            if ( $stmt->rowCount() > 0 ) {
				wp_audit( 'asset_registration_failed', $id ); 
			}
        } );
    } );
}
/** Enumerate only regular, canonical blobs. Unknown files and symbolic links are never cleanup candidates. */
function wp_asset_files( string $root, bool $images = false ): array
{
    if ( ! is_dir( $root ) || is_link( $root ) ) {
		return []; 
	}
    $files = [];
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
    foreach ( $iterator as $file ) {
        $relative = substr( $file->getPathname(), strlen( $root ) + 1 );
        $pattern = $images ? '~^([a-f0-9]{2})/([a-f0-9]{64})\.(jpg|png|webp|gif)$~D' : '~^([a-f0-9]{2})/([a-f0-9]{64})$~D';
        if ( ! $file->isLink() && $file->isFile() && preg_match( $pattern, $relative, $matches ) && str_starts_with( $matches[2], $matches[1] ) ) {
			$files[ $relative ] = $file->getPathname(); 
		}
    }
    return $files;
}
function wp_asset_usage(): array
{
    global $wpdb;
    return wp_asset_storage_lock( static function () use ( $wpdb ): array {
        $physical = 0;
        foreach ( array_merge( array_values( wp_asset_files( wp_assets_dir() ) ), array_values( wp_asset_files( WP_ROOT_DIR . '/wp-content/uploads', true ) ), glob( wp_asset_staging_dir() . '/*.part' ) ?: [] ) as $file ) {
            if ( ! is_link( $file ) && is_file( $file ) ) {
				$physical += (int) filesize( $file ); 
			}
        }
        return [
			'registered_bytes'           => (int) $wpdb->get_row( 'SELECT COALESCE(SUM(size),0) AS total FROM media' )['total'],
			'registered_masters_bytes'   => (int) $wpdb->get_row( "SELECT COALESCE(SUM(size),0) AS total FROM media WHERE kind='image' AND variant=''" )['total'],
			'registered_variants_bytes'  => (int) $wpdb->get_row( "SELECT COALESCE(SUM(size),0) AS total FROM media WHERE kind='image' AND variant<>''" )['total'],
			'registered_documents_bytes' => (int) $wpdb->get_row( "SELECT COALESCE(SUM(size),0) AS total FROM media WHERE kind='document'" )['total'],
			'physical_bytes'             => $physical,
			'pending'                    => (int) $wpdb->get_row( "SELECT COUNT(*) AS total FROM media WHERE state='pending'" )['total']
		];
    } );
}
/** Canonical document formats mapped to their download media types. Detected MIME is intake evidence only. */
function wp_document_formats(): array
{
	return [
		'pdf' => 'application/pdf',
		'txt' => 'text/plain',
		'md'  => 'text/markdown',
		'csv' => 'text/csv'
	];
}
function wp_document_url( array $asset ): string
{
	// A permalink structure that claims /documents for posts — literally, historically, or through a dynamic tag — keeps it; delivery uses the root query form there.
	$id = (int) $asset['id'];
	return wp_documents_route_conflict() || null !== wp_permalink_lookup_post( 'documents/' . $id ) ? wp_home_url( '/?document=' . $id ) : wp_home_url( '/documents/' . $id );
}
/** Packet acceptance matrix: signature/finfo evidence plus content rules per canonical format. */
function wp_document_validate_bytes( string $path, string $format ): string
{
    $size = (int) filesize( $path );
    if ( $size > 26214400 ) {
		throw new RuntimeException( 'Documents are limited to 25 MB.' ); 
	}
    $mime = (string) mime_content_type( $path );
    if ( 'pdf' === $format ) {
        $head = (string) file_get_contents( $path, false, null, 0, 5 );
        if ( '%PDF-' !== $head || 'application/pdf' !== $mime ) {
			throw new RuntimeException( 'The file is not a valid PDF document.' ); 
		}
		return $mime; 
	}
    $bytes = (string) file_get_contents( $path );
    if ( str_contains( $bytes, "\0" ) || 1 !== preg_match( '//u', $bytes ) ) {
		throw new RuntimeException( 'Text documents must be valid UTF-8 without NUL bytes.' ); 
	}
    if ( $size > 0 && ! in_array( $mime, [ 'text/plain', 'text/csv' ], true ) ) {
		throw new RuntimeException( 'The file content does not match a text document.' ); 
	}
	return $mime;
}
function wp_handle_document_upload( array $file, string $visibility = 'private' ): int|WP_Error
{
    global $wpdb;
    if ( ! current_user_can( 'upload_media' ) ) {
		return new WP_Error( 'unauthorized', 'You cannot upload media.' ); 
	}
    if ( ! in_array( $visibility, [ 'public', 'private' ], true ) ) {
		$visibility = 'private'; 
	}
    $path = $file['tmp_name'] ?? '';
    if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_string( $path ) || ! is_uploaded_file( $path ) ) {
		return new WP_Error( 'upload_failed', 'Select a valid document file.' ); 
	}
    $name = sanitize_text_field( basename( (string) ( $file['name'] ?? 'document' ) ) );
    $format = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
    if ( ! isset( wp_document_formats()[ $format ] ) ) {
		return new WP_Error( 'unsupported_format', 'Upload a PDF, text, Markdown or CSV document.' ); 
	}
    try {
		$mime = wp_document_validate_bytes( $path, $format );
    } catch ( Throwable $error ) {
		return wp_failure( 'invalid_document', $error, $error->getMessage() ); 
	}
    $hash = (string) hash_file( 'sha256', $path );
    $size = (int) filesize( $path );
    // Record reuse respects the requested format and visibility, and only ever returns a record the uploader can access whose bytes are verifiably in storage.
    foreach ( $wpdb->get_results( "SELECT * FROM media WHERE sha256=:hash AND kind='document' AND format=:format AND visibility=:visibility AND state='ready'", [
		':hash'       => $hash,
		':format'     => $format,
		':visibility' => $visibility
    ] ) as $existing ) {
        if ( ! wp_asset_can_access( $existing ) ) {
			continue; 
		}
        try {
			$blob = wp_asset_blob_path( $existing );
        } catch ( Throwable ) {
			return new WP_Error( 'asset_integrity', 'The stored copy of this document cannot be verified, so the upload was refused. Restore the stored bytes or remove the existing record, then upload again.' );
        }
        if ( is_file( $blob ) ) {
			if ( hash_equals( $hash, (string) hash_file( 'sha256', $blob ) ) ) {
				return (int) $existing['id'];
			}
			// Corrupt bytes at a content-addressed path are never overwritten or reported as usable; the refusal is explicit so the operator can repair storage first.
			return new WP_Error( 'asset_integrity', 'The stored copy of this document is corrupt, so the upload was refused. Restore the stored bytes or remove the existing record, then upload again.' );
        }
		// Missing bytes fall through: the normal lifecycle below re-registers and promotes the uploaded bytes, repairing the record set.
    }
    $staged = wp_asset_stage( $path, $hash );
    if ( is_wp_error( $staged ) ) {
		return $staged; 
	}
    $id = wp_asset_register( [
		'author_id'     => get_current_user_id(),
		'sha256'        => $hash,
		'filename'      => substr( $hash, 0, 2 ) . '/' . $hash,
		'mime'          => $mime,
		'original_name' => $name,
		'size'          => $size,
		'kind'          => 'document',
		'format'        => $format,
		'visibility'    => $visibility
    ] );
    if ( is_wp_error( $id ) ) {
		return $id; 
	}
    $promoted = wp_asset_promote( $id );
    if ( is_wp_error( $promoted ) ) {
		// The pending record is retained; maintenance recovery finishes or unwinds it. The upload is not reported as usable.
		return $promoted; 
	}
    wp_audit( 'document_uploaded', $id );
	return $id;
}
/** Routed document delivery: authorization is rechecked on every request; refusals are indistinguishable 404s. */
function wp_asset_serve_document( ?int $id = null ): void
{
    global $wpdb;
    if ( null === $id ) {
        if ( wp_documents_route_conflict() ) {
			// The stored permalink structure owns /documents/*; posts resolve there and documents use the query form.
			return; 
		}
        $path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        $prefix = rtrim( (string) parse_url( wp_home_url(), PHP_URL_PATH ), '/' );
        if ( '' !== $prefix && str_starts_with( $path, $prefix . '/' ) ) {
			$path = substr( $path, strlen( $prefix ) ); 
		}
        if ( ! preg_match( '~^/documents/([1-9][0-9]*)$~D', $path, $match ) ) {
			return; 
		}
        if ( null !== wp_permalink_lookup_post( 'documents/' . (int) $match[1] ) ) {
			// A post owns this URL under the current structure, a historical one, or a dynamic tag expansion (e.g. an author named "documents").
			return;
		}
        $id = (int) $match[1];
    }
    header( 'Cache-Control: no-store' );
	header( 'X-Content-Type-Options: nosniff' );
    $asset = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => $id ] );
    $blob = null;
    if ( null !== $asset && 'document' === $asset['kind'] && wp_asset_is_ready( $asset ) && wp_asset_can_access( $asset ) ) {
        try {
			$candidate = wp_asset_blob_path( $asset );
			$blob = is_file( $candidate ) ? $candidate : null; 
		} catch ( Throwable $error ) {
			$blob = null; 
		}
    }
    if ( null === $blob ) {
        http_response_code( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found.';
		exit; 
	}
    $fallback = trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '_', (string) $asset['original_name'] ), '._' );
    if ( '' === $fallback ) {
		$fallback = 'document.' . $asset['format']; 
	}
    header( 'Content-Type: ' . wp_document_formats()[ $asset['format'] ] );
    header( 'Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( (string) $asset['original_name'] ) );
	header( 'Content-Length: ' . (int) filesize( $blob ) );
    if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		readfile( $blob ); 
	}
    exit;
}
function wp_asset_reconcile(): array
{
    global $wpdb;
    return wp_asset_storage_lock( static function () use ( $wpdb ): array {
        $summary = [
			'promoted'       => 0,
			'failed'         => 0,
			'staged_removed' => 0,
			'blobs_removed'  => 0,
			'missing'        => 0,
			'corrupt'        => 0,
            'deferred'       => 0
		];
        foreach ( $wpdb->get_results( "SELECT * FROM media WHERE state='pending' ORDER BY id" ) as $asset ) {
            if ( 'image' === $asset['kind'] && '' !== ( $asset['variant'] ?? '' ) && null !== $asset['parent_id'] && function_exists( 'wp_image_generate_variants' ) ) {
                wp_image_generate_variants( (int) $asset['parent_id'], (string) $asset['variant'] );
                $fresh = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => (int) $asset['id'] ] );
                if ( null === $fresh ) {
					++$summary['failed'];
					continue; 
				}
                if ( 'ready' === $fresh['state'] ) {
					++$summary['promoted'];
					continue; 
				}
                $asset = $fresh;
            }
            $result = wp_asset_promote( (int) $asset['id'] );
            if ( true === $result ) {
				++$summary['promoted'];
				continue; 
			}
            $staged = wp_asset_staged_path( (string) $asset['sha256'] );
            $destination = wp_asset_blob_path( $asset );
            if ( ! is_file( $staged ) && ! is_file( $destination ) ) {
				wp_asset_fail( (int) $asset['id'] );
				++$summary['failed']; 
			} else {
                ++$summary['deferred'];
                wp_audit( 'asset_recovery_deferred', (int) $asset['id'] );
            }
        }
        $pending = array_column( $wpdb->get_results( "SELECT sha256 FROM media WHERE state='pending'" ), 'sha256' );
        foreach ( glob( wp_asset_staging_dir() . '/*.part' ) ?: [] as $file ) {
            $hash = basename( $file, '.part' );
            if ( ! is_link( $file ) && preg_match( '/^[a-f0-9]{64}$/D', $hash ) && ! in_array( $hash, $pending, true ) && filemtime( $file ) < time() - 86400 ) {
				unlink( $file );
				++$summary['staged_removed']; 
			}
        }
        foreach ( [
			'document' => wp_asset_files( wp_assets_dir() ),
			'image'    => wp_asset_files( WP_ROOT_DIR . '/wp-content/uploads', true )
		] as $kind => $files ) {
            $references = array_column( $wpdb->get_results( 'SELECT filename FROM media WHERE kind=:kind', [ ':kind' => $kind ] ), 'filename' );
            foreach ( $files as $relative => $path ) {
                if ( ! in_array( $relative, $references, true ) && filemtime( $path ) < time() - 86400 ) {
					unlink( $path );
					++$summary['blobs_removed']; 
				}
            }
        }
        foreach ( $wpdb->get_results( "SELECT * FROM media WHERE state='ready'" ) as $asset ) {
            $path = wp_asset_blob_path( $asset );
            $problem = ! is_file( $path ) ? 'missing' : ( ! hash_equals( $asset['sha256'], (string) hash_file( 'sha256', $path ) ) ? 'corrupt' : '' );
            if ( '' !== $problem ) {
				wp_audit( 'asset_' . $problem, (int) $asset['id'] );
				++$summary[ $problem ]; 
			}
        }
        return $summary;
    } );
}
