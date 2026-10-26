<?php
/** Point-in-time database snapshots plus the immutable assets they reference. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
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
		'restore_note'      => 'Restore database, immutable uploads and protected document assets (assets/ restores to the configured assets directory) while requests are sealed. Restore the matching site secret, run upgrade, and revoke all sessions before reopening.'
	], JSON_PRETTY_PRINT ) );
	unset( $archive, $snapshot );
	return $base . '.tar';
}
