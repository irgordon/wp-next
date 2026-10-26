<?php
declare( strict_types=1 );
namespace WordPress\Security;
use RuntimeException;
class WP_Admission_Gate {
	protected string $admission_lock_path;
	protected string $maintenance_lock_path;
	protected string $barrier_flag_path;
	protected mixed $admission_handle = null;
	protected mixed $maintenance_handle = null;
	protected ?string $active_operation_token = null;
	public function __construct( string $var_dir )
	{
		$locks_dir = rtrim( $var_dir, '/' ) . '/locks';
		$this->admission_lock_path = $locks_dir . '/admission.lock';
		$this->maintenance_lock_path = $locks_dir . '/maintenance.lock';
		$this->barrier_flag_path = rtrim( $var_dir, '/' ) . '/maintenance.json';
		if ( ! is_dir( $locks_dir ) ) {
			mkdir( $locks_dir, 0755, true );
		}
	}
	public function admit(): bool
	{
		if ( file_exists( $this->barrier_flag_path ) ) {
			return false;
		}
		$this->admission_handle = fopen( $this->admission_lock_path, 'c+' );
		if ( false === $this->admission_handle ) {
			return false;
		}
		if ( ! flock( $this->admission_handle, LOCK_SH | LOCK_NB ) ) {
			fclose( $this->admission_handle );
			$this->admission_handle = null;
			return false;
		}
		if ( file_exists( $this->barrier_flag_path ) ) {
			$this->release();
			return false;
		}
		register_shutdown_function( [ $this, 'release' ] );
		return true;
	}
	public function release(): void
	{
		if ( is_resource( $this->admission_handle ) ) {
			flock( $this->admission_handle, LOCK_UN );
			fclose( $this->admission_handle );
			$this->admission_handle = null;
		}
	}
	public function enter_maintenance( int $timeout_seconds = 10 ): void
	{
		$this->maintenance_handle = fopen( $this->maintenance_lock_path, 'c+' );
		if ( false === $this->maintenance_handle ) {
			throw new RuntimeException( 'Failed to open maintenance coordinator lock.' );
		}
		$start_time = time();
		while ( ! flock( $this->maintenance_handle, LOCK_EX | LOCK_NB ) ) {
			if ( ( time() - $start_time ) >= $timeout_seconds ) {
				fclose( $this->maintenance_handle );
				$this->maintenance_handle = null;
				throw new RuntimeException( 'Timed out waiting for competing maintenance coordinator to exit.' );
			}
			usleep( 25000 );
		}
		$this->release();
		$this->active_operation_token = wp_generate_uuid4();
		$payload = [
			'token'      => $this->active_operation_token,
			'pid'        => getmypid(),
			'created_at' => time()
		];
		$encoded = json_encode( $payload, JSON_PRETTY_PRINT );
		if ( false === $encoded || false === file_put_contents( $this->barrier_flag_path, $encoded, LOCK_EX ) ) {
			$this->release_maintenance_locks();
			throw new RuntimeException( 'Failed to write maintenance barrier flag.' );
		}
		$this->admission_handle = fopen( $this->admission_lock_path, 'c+' );
		if ( false === $this->admission_handle ) {
			$this->cleanup_barrier_if_owned();
			$this->release_maintenance_locks();
			throw new RuntimeException( 'Failed to open admission lock file for draining.' );
		}
		$drain_start = time();
		while ( ! flock( $this->admission_handle, LOCK_EX | LOCK_NB ) ) {
			if ( ( time() - $drain_start ) >= $timeout_seconds ) {
				$this->cleanup_barrier_if_owned();
				$this->release_maintenance_locks();
				throw new RuntimeException( 'Timed out waiting for existing requests to drain.' );
			}
			usleep( 25000 );
		}
	}
	public function exit_maintenance(): void
	{
		$this->cleanup_barrier_if_owned();
		$this->release_maintenance_locks();
	}
	public function abandon_maintenance_sealed(): void
	{
		$this->active_operation_token = null;
		$this->release_maintenance_locks();
	}
	protected function cleanup_barrier_if_owned(): void
	{
		if ( null !== $this->active_operation_token && file_exists( $this->barrier_flag_path ) ) {
			$content = file_get_contents( $this->barrier_flag_path );
			$data = json_decode( (string) $content, true );
			if ( is_array( $data ) && ( $data['token'] ?? '' ) === $this->active_operation_token ) {
				unlink( $this->barrier_flag_path );
			}
		}
		$this->active_operation_token = null;
	}
	protected function release_maintenance_locks(): void
	{
		if ( is_resource( $this->admission_handle ) ) {
			flock( $this->admission_handle, LOCK_UN );
			fclose( $this->admission_handle );
			$this->admission_handle = null;
		}
		if ( is_resource( $this->maintenance_handle ) ) {
			flock( $this->maintenance_handle, LOCK_UN );
			fclose( $this->maintenance_handle );
			$this->maintenance_handle = null;
		}
	}
	public function is_in_maintenance(): bool
	{
		return file_exists( $this->barrier_flag_path );
	}
}
