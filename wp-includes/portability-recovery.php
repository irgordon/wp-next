<?php
/** Import provenance survives a progress-checkpoint failure. Locks serialize one run key. */
declare( strict_types=1 );
const WP_PORTABILITY_BUSY_MESSAGE = 'An import of this archive and author mapping is already running. Retry after it finishes.';

function wp_portability_run( array $parsed, array $mapping = [] ): array
{
	$key = wp_portability_state_key( (string) $parsed['sha256'], $mapping );
	return wp_portability_run_serialized( $parsed, $mapping, $key );
}
function wp_portability_run_serialized( array $parsed, array $mapping, string $key ): array
{
	$lock = wp_portability_acquire_run_lock( $key );
	if ( null === $lock ) {
		return wp_portability_busy_result( $key );
	}
	try {
		return wp_portability_apply_run( $parsed, $mapping );
	} finally {
		wp_portability_release_run_lock( $lock );
	}
}
function wp_portability_busy_result( string $key ): array
{
	return [
		'state_key' => $key,
		'results'   => [
			[
				'key'       => 'run',
				'kind'      => 'run',
				'label'     => 'Import',
				'status'    => 'failed',
				'detail'    => WP_PORTABILITY_BUSY_MESSAGE,
				'target_id' => 0
			]
		],
		'maps'      => [
			'authors' => [],
			'media'   => [],
			'terms'   => []
		]
	];
}
function wp_portability_complete_post( int $id, array $post, array $authors ): void
{
	if ( ! current_user_can( 'administrator' ) ) {
		throw new RuntimeException( 'Import permission was revoked. The item can be resumed by an administrator.' );
	}
	$saved = wp_portability_completion_snapshot( $id );
	if ( (int) $saved['revision'] > 1 ) {
		return;
	}
	$fields = wp_portability_source_post_fields( $post, $authors, $saved );
	wp_portability_write_source_fields( $id, $fields );
}
function wp_portability_source_post_fields( array $post, array $authors, array $saved ): array
{
	return [
		'author'    => $authors[ $post['author_source_id'] ] ?? get_current_user_id(),
		'published' => 'published' === $post['status'] ? wp_portability_positive_date( $post['published_at'], $saved['published_at'] ) : null,
		'created'   => wp_portability_positive_date( $post['created_at'], $saved['created_at'] ),
		'updated'   => wp_portability_positive_date( $post['updated_at'], $saved['updated_at'] )
	];
}

/** @return resource|null */
function wp_portability_acquire_run_lock( string $key )
{
	$lock = fopen( wp_portability_workdir() . '/' . $key . '.lock', 'c' );
	if ( false === $lock ) {
		throw new RuntimeException( 'The import lock could not be opened.' );
	}
	if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
		fclose( $lock );
		return null;
	}
	return $lock;
}
/** @param resource $lock */
function wp_portability_release_run_lock( $lock ): void
{
	flock( $lock, LOCK_UN );
	fclose( $lock );
}
function wp_portability_find_receipt( string $key, int $source ): ?int
{
	global $wpdb;
	$row = $wpdb->get_row( 'SELECT id FROM posts WHERE json_extract(metadata,\'$._wpnext_import.key\')=:key AND json_extract(metadata,\'$._wpnext_import.source_id\')=CAST(:source AS INTEGER) ORDER BY id LIMIT 1', [
		':key'    => $key,
		':source' => $source
	] );
	return null !== $row ? (int) $row['id'] : null;
}
function wp_portability_completion_snapshot( int $id ): array
{
	global $wpdb;
	return $wpdb->get_row( 'SELECT revision,published_at,created_at,updated_at FROM posts WHERE id=:id', [ ':id' => $id ] ) ?? throw new RuntimeException( 'The committed import row is no longer available.' );
}
function wp_portability_positive_date( int $source, ?int $fallback ): ?int
{
	return $source > 0 ? $source : $fallback;
}
function wp_portability_write_source_fields( int $id, array $fields ): void
{
	global $wpdb;
	$wpdb->query( 'UPDATE posts SET author_id=:author,published_at=:published,created_at=:created,updated_at=:updated WHERE id=:id', [
		':author'    => $fields['author'],
		':published' => $fields['published'],
		':created'   => $fields['created'],
		':updated'   => $fields['updated'],
		':id'        => $id
	] );
}
