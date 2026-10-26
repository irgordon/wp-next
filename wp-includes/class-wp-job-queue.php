<?php
/** Transactional outbox and expiring worker leases. */
declare( strict_types=1 );
namespace WordPress\Queue;
use WordPress\Database\WP_SQLite_DB;
use WordPress\Database\Transaction_Exception;
class WP_Job_Queue {
	public function __construct( protected WP_SQLite_DB $db )
	{
	}
	public function enqueue_in_transaction( string $task_class, array $payload, string $queue = 'default' ): string
	{
		if ( ! $this->db->is_in_transaction() ) {
			throw new Transaction_Exception( 'Outbox writes require the content transaction.' );
		}
		$uuid = wp_generate_uuid4();
		$this->db->query( 'INSERT INTO job_queue(uuid,queue,task,payload) VALUES(:uuid,:queue,:task,:payload)', [
			':uuid'    => $uuid,
			':queue'   => $queue,
			':task'    => $task_class,
			':payload' => wp_json_encode( $payload )
		] );
		return $uuid;
	}
	public function claim_job( string $queue = 'default', int $lease_duration = 60 ): ?array
	{
		$token = bin2hex( random_bytes( 16 ) );
		$stmt = $this->db->query( "UPDATE job_queue SET status='reserved',attempts=attempts+1,lease_token=:token,reserved_at=unixepoch(),lease_expires_at=unixepoch()+:duration WHERE id=(SELECT id FROM job_queue WHERE queue=:queue AND ((status='pending' AND available_at<=unixepoch()) OR (status='reserved' AND lease_expires_at<=unixepoch())) AND attempts<max_attempts ORDER BY available_at,id LIMIT 1) RETURNING id,uuid,task,payload,attempts,lease_token", [
			':token'    => $token,
			':duration' => max( 1, $lease_duration ),
			':queue'    => $queue
		] );
		try {
			$row = $stmt->fetch();
			return false === $row ? null : $row;
		} finally {
			$stmt->closeCursor();
		}
	}
	public function owns_lease( int $id, string $token ): bool
	{
		return null !== $this->db->get_row( "SELECT id FROM job_queue WHERE id=:id AND status='reserved' AND lease_token=:token AND lease_expires_at>unixepoch()", [
			':id'    => $id,
			':token' => $token
		] );
	}
	public function complete_job( int $job_id, string $worker_token ): bool
	{
		return 1 === $this->db->query( "UPDATE job_queue SET status='completed',lease_token=NULL,lease_expires_at=NULL WHERE id=:id AND status='reserved' AND lease_token=:token AND lease_expires_at>unixepoch()", [
			':id'    => $job_id,
			':token' => $worker_token
		] )->rowCount();
	}
	public function release_or_fail_job( int $job_id, string $worker_token, int $delay_sec = 30 ): bool
	{
		return 1 === $this->db->query( "UPDATE job_queue SET status=CASE WHEN attempts>=max_attempts THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_expires_at=NULL,available_at=unixepoch()+:delay WHERE id=:id AND status='reserved' AND lease_token=:token AND lease_expires_at>unixepoch()", [
			':id'    => $job_id,
			':token' => $worker_token,
			':delay' => max( 0, $delay_sec )
		] )->rowCount();
	}
	public function sweep_expired_reservations(): int
	{
		return $this->db->query( "UPDATE job_queue SET status=CASE WHEN attempts>=max_attempts THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_expires_at=NULL,available_at=unixepoch()+30 WHERE status='reserved' AND lease_expires_at<=unixepoch()" )->rowCount();
	}
}
