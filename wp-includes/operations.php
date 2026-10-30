<?php
/** Point-in-time database snapshots plus the immutable assets they reference. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
use WordPress\Security\WP_Admission_Gate;
function wp_create_backup( WP_SQLite_DB $db, string $label = 'site' ): string
{
    return wp_asset_storage_lock( static fn(): string => wp_create_asset_backup( $db, $label ) );
}
function wp_create_asset_backup( WP_SQLite_DB $db, string $label ): string
{
	if ( ! class_exists( 'PharData' ) ) {
		throw new RuntimeException( 'The PHP CLI Phar extension is required for backup archives.' );
	}
	$directory = WP_VAR_DIR . '/backups';
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
		throw new RuntimeException( 'Cannot create backup directory.' );
	}
	$base = $directory . '/' . $label . '-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
	$snapshot_path = $base . '.sqlite';
	$db->query( 'VACUUM INTO :path', [ ':path' => $snapshot_path ] );
	chmod( $snapshot_path, 0600 );
	$snapshot = new WP_SQLite_DB( $snapshot_path );
	// Credential-inert snapshots: API tokens are revoked and the API state is
	// disabled and unbound inside the snapshot before it is archived, so a
	// restored backup can never resurrect a live credential. The live
	// database is untouched; snapshot changes are verified before archiving.
	$api_inert = false;
	if ( null !== $snapshot->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='api_security_state'" ) ) {
		$snapshot->transaction( static function ( WP_SQLite_DB $db ): void {
			$db->query( "UPDATE api_tokens SET revoked_at=unixepoch(),revoke_reason='backup_snapshot' WHERE revoked_at IS NULL" );
			$db->query( 'UPDATE api_security_state SET enabled=0,bound_epoch=NULL WHERE singleton=1' );
			$db->query( 'DELETE FROM api_rate_windows' );
		} );
		$api_check = $snapshot->get_row( 'SELECT enabled,bound_epoch FROM api_security_state WHERE singleton=1' );
		$api_active = (int) $snapshot->get_row( 'SELECT COUNT(*) AS n FROM api_tokens WHERE revoked_at IS NULL' )['n'];
		if ( null === $api_check || 1 === (int) $api_check['enabled'] || null !== $api_check['bound_epoch'] || $api_active > 0 ) {
			throw new RuntimeException( 'Backup snapshot could not be made credential-inert.' );
		}
		$api_inert = true;
	}
	$media = [];
	if ( null !== $snapshot->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='media'" ) ) {
		// Pre-v5 snapshots lack the asset-model columns; their rows are public ready images by construction.
		$columns = array_column( $snapshot->get_results( 'PRAGMA table_info(media)' ), 'name' );
		$asset_columns = in_array( 'kind', $columns, true ) && in_array( 'format', $columns, true ) && in_array( 'visibility', $columns, true ) && in_array( 'state', $columns, true );
		$media = $snapshot->get_results( $asset_columns ? 'SELECT id,filename,sha256,kind,format,visibility,state FROM media' : 'SELECT id,filename,sha256 FROM media' );
		foreach ( $media as &$row ) {
			$row += [
				'kind'       => 'image',
				'format'     => strtolower( pathinfo( (string) $row['filename'], PATHINFO_EXTENSION ) ),
				'visibility' => 'public',
				'state'      => 'ready'
			];
		}
		unset( $row );
	}
	// The archive stores the snapshot file itself: fold the write-ahead log
	// back into it first, or snapshot writes (the credential inerting above)
	// would ride in a sidecar the archive never sees.
	$snapshot->query( 'PRAGMA wal_checkpoint(TRUNCATE)' );
	$archive = new PharData( $base . '.tar' );
	$archive->addFile( $snapshot_path, 'site.sqlite' );
	chmod( $base . '.tar', 0600 );
	$archived = 0;
	$incomplete = [];
	foreach ( $media as $asset ) {
		if ( 'ready' !== $asset['state'] ) {
			// Incomplete registrations carry no deliverable bytes; the record travels in the database snapshot and recovery reconciles it after restore.
			$incomplete[] = [
				'id'    => (int) $asset['id'],
				'state' => $asset['state']
			];
			continue;
		}
		if ( 'document' === $asset['kind'] ) {
			try {
				// One canonical validator for backup and delivery: a symlinked root, prefix or file refuses both.
				$path = wp_asset_blob_path( $asset );
			} catch ( Throwable ) {
				throw new RuntimeException( 'Asset ' . (int) $asset['id'] . ' is missing or has changed.' );
			}
			$entry = 'assets/' . $asset['filename'];
		} else {
			if ( ! preg_match( '~^[a-f0-9]{2}/[a-f0-9]{64}\.(jpg|png|webp|gif)$~', $asset['filename'] ) ) {
				throw new RuntimeException( 'Unexpected asset path in backup.' );
			}
			$path = WP_ROOT_DIR . '/wp-content/uploads/' . $asset['filename'];
			$entry = 'uploads/' . $asset['filename'];
		}
		if ( is_link( $path ) || ! is_file( $path ) || ! hash_equals( $asset['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Asset ' . (int) $asset['id'] . ' is missing or has changed.' );
		}
		$archive->addFile( $path, $entry );
		++$archived;
	}
	$key_path = WP_VAR_DIR . '/auth.key';
	$uses_file_key = is_file( $key_path ) && hash_equals( wp_auth_salt(), trim( (string) file_get_contents( $key_path ) ) );
	if ( $uses_file_key ) {
		$archive->addFile( $key_path, 'auth.key' );
	}
	$archive->addFromString( 'manifest.json', wp_json_encode( [
		'created_at'        => gmdate( DATE_ATOM ),
		'schema_version'    => wp_schema_version( $snapshot ),
		'media'             => $media,
		'recoverable_files' => $archived,
		'incomplete_assets' => $incomplete,
		'secret_source'     => $uses_file_key ? 'auth.key' : 'operator environment',
		'api_credentials'   => $api_inert ? 'revoked in snapshot; API disabled and unbound' : 'not present in snapshot',
		'restore_note'      => 'Restore with bin/wp restore <archive> while requests are sealed: it rotates the API epoch before swapping the database, restores uploads and protected document assets, runs the upgrade, revokes all sessions and API credentials, and reopens with the API disabled.'
	], JSON_PRETTY_PRINT ) );
	unset( $archive, $snapshot );
	return $base . '.tar';
}

/**
 * Restore coordinator. Runs sealed under the admission gate, in an order
 * chosen so no intermediate state can serve a restored credential:
 * 1) back up the current database, 2) validate the archive and its snapshot,
 * 3) rotate the external API epoch BEFORE any database swap (a crash then
 * leaves a mismatch that refuses credentials), 4) swap the database and the
 * immutable files, 5) upgrade, revoke every session and API credential on the
 * restored database, bind the fresh epoch and leave the API disabled.
 * Any failure leaves the site sealed for operator recovery.
 */
function wp_restore_backup( WP_SQLite_DB $current, string $archive_path, WP_Admission_Gate $gate ): array
{
	if ( ! is_file( $archive_path ) ) {
		throw new RuntimeException( 'Backup archive not found.' );
	}
	$gate->enter_maintenance();
	$db_staging = null;
	try {
		wp_create_backup( $current, 'before-restore' );
		$archive = new PharData( (string) realpath( $archive_path ) );
		$prefix = 'phar://' . realpath( $archive_path ) . '/';
		$allowed = static fn( string $name ): bool => in_array( $name, [ 'site.sqlite', 'manifest.json', 'auth.key' ], true )
			|| 1 === preg_match( '~^uploads/[a-f0-9]{2}/[a-f0-9]{64}\.(jpg|png|webp|gif)$~', $name )
			|| 1 === preg_match( '~^assets/[a-f0-9]{2}/[a-f0-9]{64}$~', $name );
		$entries = [];
		foreach ( new RecursiveIteratorIterator( $archive ) as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$name = substr( $file->getPathname(), strlen( $prefix ) );
			if ( ! $allowed( $name ) ) {
				throw new RuntimeException( 'Unexpected archive entry: ' . $name );
			}
			$entries[ $name ] = $file->getPathname();
		}
		foreach ( [ 'site.sqlite', 'manifest.json' ] as $required ) {
			if ( ! isset( $entries[ $required ] ) ) {
				throw new RuntimeException( 'Backup archive is missing ' . $required . '.' );
			}
		}
		$manifest = json_decode( (string) file_get_contents( $entries['manifest.json'] ), true );
		if ( ! is_array( $manifest ) || (int) ( $manifest['schema_version'] ?? PHP_INT_MAX ) > WP_SCHEMA_VERSION ) {
			throw new RuntimeException( 'Backup schema is newer than this application. Refusing to restore.' );
		}
		$db_staging = dirname( WP_DB_PATH ) . '/.restore-' . bin2hex( random_bytes( 6 ) ) . '.sqlite';
		if ( ! copy( $entries['site.sqlite'], $db_staging ) ) {
			throw new RuntimeException( 'The backup database could not be staged.' );
		}
		chmod( $db_staging, 0600 );
		$probe = new WP_SQLite_DB( $db_staging );
		if ( 'ok' !== (string) $probe->query( 'PRAGMA integrity_check' )->fetchColumn() || [] !== $probe->get_results( 'PRAGMA foreign_key_check' ) ) {
			throw new RuntimeException( 'Backup database failed its integrity check.' );
		}
		wp_restore_verify_assets( $probe, $entries );
		if ( 'auth.key' === ( $manifest['secret_source'] ?? null ) && ! isset( $entries['auth.key'] ) ) {
			throw new RuntimeException( 'Backup is missing its required site secret.' );
		}
		unset( $probe );
		// Epoch rotation precedes the swap; the restored database is bound to
		// the fresh epoch only after it is in place.
		$new_epoch = null;
		if ( '' !== wp_api_epoch_path() ) {
			$lock = wp_api_epoch_lock( true );
			if ( false === $lock ) {
				throw new RuntimeException( 'The API epoch lock could not be acquired.' );
			}
			try {
				$new_epoch = wp_api_epoch_generate();
				if ( ! wp_api_epoch_write( $new_epoch ) ) {
					throw new RuntimeException( 'The API epoch file could not be rotated.' );
				}
			} finally {
				wp_api_epoch_unlock( $lock );
			}
		}
		foreach ( [ '-wal', '-shm' ] as $suffix ) {
			if ( is_file( WP_DB_PATH . $suffix ) ) {
				unlink( WP_DB_PATH . $suffix );
			}
		}
		if ( ! rename( $db_staging, WP_DB_PATH ) ) {
			throw new RuntimeException( 'The restored database could not be moved into place.' );
		}
		chmod( WP_DB_PATH, 0600 );
		$files = 0;
		$restored_entries = [];
		foreach ( $entries as $name => $source ) {
			if ( str_starts_with( $name, 'uploads/' ) ) {
				$target = WP_ROOT_DIR . '/wp-content/uploads/' . substr( $name, strlen( 'uploads/' ) );
			} elseif ( str_starts_with( $name, 'assets/' ) ) {
				$target = rtrim( WP_ASSETS_DIR, '/' ) . '/' . substr( $name, strlen( 'assets/' ) );
			} elseif ( 'auth.key' === $name ) {
				$target = WP_VAR_DIR . '/auth.key';
			} else {
				continue;
			}
			if ( is_link( $target ) || is_link( dirname( $target ) ) || ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0755, true ) ) ) {
				throw new RuntimeException( 'The restore destination is unsafe or unavailable.' );
			}
			if ( ! copy( $source, $target ) || ! chmod( $target, 'auth.key' === $name ? 0600 : 0644 ) ) {
				throw new RuntimeException( 'Restore bytes could not be written completely.' );
			}
			$restored_entries[ $name ] = $target;
			++$files;
		}
		$restored = new WP_SQLite_DB( WP_DB_PATH );
		wp_restore_verify_assets( $restored, $restored_entries );
		wp_upgrade_schema( $restored );
		$restored->transaction( static function ( WP_SQLite_DB $db ) use ( $new_epoch ): void {
			$db->query( 'DELETE FROM user_sessions' );
			if ( wp_api_tables_exist( $db ) ) {
				wp_api_revoke_all_tokens( $db, 'restore' );
				$db->query( 'UPDATE api_security_state SET enabled=0,bound_epoch=:epoch WHERE singleton=1', [ ':epoch' => $new_epoch ] );
			}
		} );
		if ( 'ok' !== (string) $restored->query( 'PRAGMA integrity_check' )->fetchColumn() || [] !== $restored->get_results( 'PRAGMA foreign_key_check' ) ) {
			throw new RuntimeException( 'Restored database failed its post-restore integrity check.' );
		}
		$gate->exit_maintenance();
		return [
			'schema_version' => wp_schema_version( $restored ),
			'files_restored' => $files,
			'epoch_rotated'  => null !== $new_epoch,
			'api'            => 'disabled; all API credentials revoked'
		];
	} catch ( Throwable $error ) {
		$gate->abandon_maintenance_sealed();
		throw $error;
	} finally {
		unset( $probe );
		foreach ( null === $db_staging ? [] : [ '', '-wal', '-shm' ] as $suffix ) {
			if ( is_file( $db_staging . $suffix ) ) {
				unlink( $db_staging . $suffix );
			}
		}
	}
}

/** Verify every ready registration against archive bytes, then copied destinations. */
function wp_restore_verify_assets( WP_SQLite_DB $snapshot, array $entries ): void
{
	clearstatcache();
	if ( null === $snapshot->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='media'" ) ) {
		return;
	}
	foreach ( $snapshot->get_results( 'SELECT * FROM media' ) as $asset ) {
		$asset += [
			'kind'   => 'image',
			'format' => strtolower( pathinfo( $asset['filename'], PATHINFO_EXTENSION ) ),
			'state'  => 'ready'
		];
		if ( 'ready' !== $asset['state'] ) {
			continue;
		}
		wp_asset_blob_path( $asset );
		$name = ( 'document' === $asset['kind'] ? 'assets/' : 'uploads/' ) . $asset['filename'];
		$path = $entries[ $name ] ?? null;
		if ( null === $path || ! is_file( $path ) || is_link( $path ) || ! hash_equals( $asset['sha256'], (string) hash_file( 'sha256', $path ) ) || filesize( $path ) !== (int) $asset['size'] ) {
			throw new RuntimeException( 'Backup asset ' . (int) $asset['id'] . ' is missing or has changed.' );
		}
	}
}
