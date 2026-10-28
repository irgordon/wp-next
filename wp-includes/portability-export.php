<?php
/** Export bounds mirror the version-1 importer; archive writing is an infrastructure adapter. */
declare( strict_types=1 );
const WP_PORTABILITY_EXPORT_LIMIT_MESSAGE = 'The export exceeds the native portability format limits and cannot be re-imported. Use a site backup for this data set.';

function wp_portability_export_count_error(): ?WP_Error
{
	return wp_portability_export_record_error( wp_portability_export_counts() );
}
function wp_portability_export_record_error( array $counts ): ?WP_Error
{
	foreach ( $counts as $count ) {
		if ( $count > WP_PORTABILITY_MAX_ITEMS ) {
			return new WP_Error( 'export_limits', WP_PORTABILITY_EXPORT_LIMIT_MESSAGE );
		}
	}
	return null;
}
function wp_portability_export_size_error( string $manifest, array $files ): ?WP_Error
{
	$sizes = wp_portability_export_sizes( $manifest, $files );
	return wp_portability_export_bounds_error( $sizes );
}
function wp_portability_export_bounds_error( array $sizes ): ?WP_Error
{
	if ( $sizes['manifest'] > WP_PORTABILITY_MAX_MANIFEST_BYTES || $sizes['entries'] > WP_PORTABILITY_MAX_ENTRIES ) {
		return new WP_Error( 'export_limits', WP_PORTABILITY_EXPORT_LIMIT_MESSAGE );
	}
	if ( $sizes['largest'] > WP_PORTABILITY_MAX_ENTRY_BYTES || $sizes['total'] > WP_PORTABILITY_MAX_TOTAL_BYTES ) {
		return new WP_Error( 'export_limits', WP_PORTABILITY_EXPORT_LIMIT_MESSAGE );
	}
	return null;
}
function wp_portability_export_counts(): array
{
	global $wpdb;
	return $wpdb->get_row( "SELECT (SELECT COUNT(*) FROM users) AS authors,(SELECT COUNT(*) FROM terms) AS terms,(SELECT COUNT(*) FROM media WHERE state='ready' AND variant='') AS media,(SELECT COUNT(*) FROM posts WHERE status<>'trashed') AS posts" ) ?? [];
}
function wp_portability_export_sizes( string $manifest, array $files ): array
{
	$sizes = array_map( 'filesize', array_values( $files ) );
	$sizes[] = strlen( $manifest );
	return [
		'manifest' => strlen( $manifest ),
		'entries'  => count( $sizes ),
		'largest'  => max( $sizes ),
		'total'    => array_sum( $sizes )
	];
}
function wp_portability_write_archive( string $manifest, array $files ): string
{
	$path = wp_portability_workdir() . '/export-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	$zip = new ZipArchive();
	try {
		wp_portability_fill_archive( $zip, $path, $manifest, $files );
	} catch ( Throwable $error ) {
		wp_portability_discard_archive( $zip, $path );
		throw $error;
	}
	return $path;
}
function wp_portability_fill_archive( ZipArchive $zip, string $path, string $manifest, array $files ): void
{
	wp_portability_open_archive( $zip, $path );
	wp_portability_write_manifest( $zip, $manifest );
	wp_portability_write_files( $zip, $files );
	wp_portability_close_archive( $zip );
}
function wp_portability_discard_archive( ZipArchive $zip, string $path ): void
{
	try {
		$zip->close();
	} catch ( Throwable $error ) {
		/* Opening may have failed before the handle initialized. */
	}
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}
function wp_portability_open_archive( ZipArchive $zip, string $path ): void
{
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'The export archive could not be created.' );
	}
}
function wp_portability_write_manifest( ZipArchive $zip, string $manifest ): void
{
	if ( ! $zip->addFromString( 'manifest.json', $manifest ) ) {
		throw new RuntimeException( 'The export manifest could not be written.' );
	}
}
function wp_portability_write_files( ZipArchive $zip, array $files ): void
{
	foreach ( $files as $entry => $path ) {
		if ( ! $zip->addFile( $path, $entry ) ) {
			throw new RuntimeException( 'A media file could not be archived.' );
		}
	}
}
function wp_portability_close_archive( ZipArchive $zip ): void
{
	if ( ! $zip->close() ) {
		throw new RuntimeException( 'The export archive could not be completed.' );
	}
}
