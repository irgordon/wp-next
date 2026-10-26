<?php
/** Fixed job handlers; no class name from writable queue data is autoloaded. */
declare( strict_types=1 );
namespace WordPress\Jobs;
use WordPress\Database\WP_SQLite_DB;
use WordPress\Queue\WP_Job_Queue;
use RuntimeException;
use Throwable;
class Render_Post_Job {
	public function prepare( WP_SQLite_DB $db, array $payload ): array
	{
		$post = $db->get_row( 'SELECT * FROM posts WHERE id=:id AND revision=:revision', [
			':id'       => (int) ( $payload['post_id'] ?? 0 ),
			':revision' => (int) ( $payload['target_revision'] ?? 0 )
		] );
		if ( null === $post ) {
			return [];
		}
		$metadata = json_decode( (string) ( $post['metadata'] ?? '' ), true );
		return [
			'id'           => $post['id'],
			'revision'     => $post['revision'],
			// Render generation: a visibility change bumps the epoch, so HTML prepared before it can never commit after it.
			'render_epoch' => is_array( $metadata ) ? (int) ( $metadata['render_epoch'] ?? 0 ) : 0,
			'html'         => wp_render_content( $post['content_raw'] )
		];
	}
	public function commit( WP_SQLite_DB $db, array $prepared, string $uuid ): void
	{
		if ( [] === $prepared ) {
			return;
		}
		$db->query( "UPDATE posts SET content_rendered=:html,rendered_revision=:revision WHERE id=:id AND revision=:revision AND COALESCE(json_extract(metadata,'\$.render_epoch'),0)=CAST(:epoch AS INTEGER)", [
			':html'     => $prepared['html'],
			':revision' => $prepared['revision'],
			':id'       => $prepared['id'],
			':epoch'    => (int) ( $prepared['render_epoch'] ?? 0 )
		] );
	}
}
class Post_Transition_Job {
	public function prepare( WP_SQLite_DB $db, array $payload ): array
	{
		return $payload;
	}
	public function commit( WP_SQLite_DB $db, array $prepared, string $uuid ): void
	{
		$db->query( "INSERT OR IGNORE INTO audit_log(actor_id,action,subject_id,payload,event_uuid) VALUES(:actor,'content_status',:id,:payload,:uuid)", [
			':actor'   => (int) ( $prepared['actor_id'] ?? 0 ),
			':id'      => (int) ( $prepared['post_id'] ?? 0 ),
			':payload' => wp_json_encode( $prepared ),
			':uuid'    => $uuid
		] );
	}
}
class WP_Job_Worker {
	public function __construct( private WP_SQLite_DB $db, private WP_Job_Queue $queue )
	{
	}
	public function run_once(): bool
	{
		$job = $this->queue->claim_job();
		if ( null === $job ) {
			return false;
		}
		try {
			$handler = match ( $job['task'] ) {
				Render_Post_Job::class => new Render_Post_Job(),
				Post_Transition_Job::class => new Post_Transition_Job(),
				default => throw new RuntimeException( 'Unregistered job task.' ),
			};
			$payload = json_decode( $job['payload'], true, 32, JSON_THROW_ON_ERROR );
			$prepared = $handler->prepare( $this->db, $payload );
			$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $job, $handler, $prepared ): void {
				if ( ! $this->queue->owns_lease( (int) $job['id'], $job['lease_token'] ) ) {
					return;
				}
				$handler->commit( $db, $prepared, $job['uuid'] );
				if ( ! $this->queue->complete_job( (int) $job['id'], $job['lease_token'] ) ) {
					throw new RuntimeException( 'Worker lease expired during completion.' );
				}
			} );
		} catch ( Throwable $error ) {
			error_log( 'Queue job ' . $job['id'] . ': ' . $error->getMessage() );
			$this->queue->release_or_fail_job( (int) $job['id'], $job['lease_token'] );
		}
		return true;
	}
	public function run( int $limit = 50 ): int
	{
		$this->queue->sweep_expired_reservations();
		$count = 0;
		$deadline = microtime( true ) + 45;
		while ( $count < $limit && microtime( true ) < $deadline && $this->run_once() ) {
			++$count;
		}
		return $count;
	}
}
