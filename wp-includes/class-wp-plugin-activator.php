<?php
declare( strict_types=1 );
namespace WordPress\Plugin;
use WordPress\Database\WP_SQLite_DB;
use WordPress\Security\WP_Admission_Gate;
use RuntimeException;
use Throwable;
use WP_Error;
interface Plugin_Interface {
	public function get_slug(): string;
	public function get_version(): string;
	public function up( WP_SQLite_DB $db ): void;
	public function get_listeners(): array;
}
class WP_Plugin_Activator {
	public function __construct( protected WP_SQLite_DB $db, protected WP_Admission_Gate $gate, protected string $cache_dir )
	{
		$this->ensure_journal_schema();
	}
	protected function ensure_journal_schema(): void
	{
		$this->db->query( "CREATE TABLE IF NOT EXISTS plugin_activations (plugin_slug TEXT PRIMARY KEY, version TEXT NOT NULL, state TEXT CHECK(state IN ('migrating', 'migrated', 'active', 'failed_migration', 'failed_compilation')) NOT NULL, error_message TEXT, created_at INTEGER NOT NULL DEFAULT (unixepoch()), updated_at INTEGER NOT NULL DEFAULT (unixepoch())) STRICT;" );
	}
	public function activate( Plugin_Interface $plugin, array $all_installed ): true|WP_Error
	{
		$slug = $plugin->get_slug();
		$version = $plugin->get_version();
		$this->gate->enter_maintenance();
		try {
			$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $slug, $version ) {
				$db->query( 'INSERT INTO plugin_activations (plugin_slug, version, state, updated_at) VALUES (:slug, :version, \'migrating\', unixepoch()) ON CONFLICT(plugin_slug) DO UPDATE SET version = :version, state = \'migrating\', error_message = NULL, updated_at = unixepoch();', [
					':slug'    => $slug,
					':version' => $version
				] );
			} );
		} catch ( Throwable $e ) {
			$this->gate->exit_maintenance();
			return new WP_Error( 'pre_migration_failed', 'Failed to record migration intent: ' . $e->getMessage() );
		}
		try {
			$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $plugin, $slug ) {
				$plugin->up( $db );
				$db->query( 'UPDATE plugin_activations SET state = \'migrated\', updated_at = unixepoch() WHERE plugin_slug = :slug;', [ ':slug' => $slug ] );
			} );
		} catch ( Throwable $e ) {
			$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $slug, $e ) {
				$db->query( 'UPDATE plugin_activations SET state = \'failed_migration\', error_message = :err, updated_at = unixepoch() WHERE plugin_slug = :slug;', [
					':err'  => $e->getMessage(),
					':slug' => $slug
				] );
			} );
			$this->gate->exit_maintenance();
			return new WP_Error( 'migration_failed', $e->getMessage() );
		}
		try {
			$this->compile_eligible_registry( $all_installed );
			$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $slug ) {
				$db->query( 'UPDATE plugin_activations SET state = \'active\', updated_at = unixepoch() WHERE plugin_slug = :slug;', [ ':slug' => $slug ] );
			} );
		} catch ( Throwable $e ) {
			$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $slug, $e ) {
				$db->query( 'UPDATE plugin_activations SET state = \'failed_compilation\', error_message = :err, updated_at = unixepoch() WHERE plugin_slug = :slug;', [
					':err'  => $e->getMessage(),
					':slug' => $slug
				] );
			} );
			$this->gate->abandon_maintenance_sealed();
			return new WP_Error( 'compilation_failed', 'Compilation failed. Site remains sealed in maintenance mode: ' . $e->getMessage() );
		}
		$this->gate->exit_maintenance();
		return true;
	}
	public function recover( array $all_installed ): array|WP_Error
	{
		$this->gate->enter_maintenance();
		$failed_crashes = [];
		$recovered = [];
		try {
			$migrating_stmt = $this->db->query( "SELECT plugin_slug FROM plugin_activations WHERE state = 'migrating';" );
			$crashed_rows = $migrating_stmt->fetchAll();
			if ( ! empty( $crashed_rows ) ) {
				$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $crashed_rows, &$failed_crashes ) {
					foreach ( $crashed_rows as $row ) {
						$slug = $row['plugin_slug'];
						$failed_crashes[] = $slug;
						$db->query( 'UPDATE plugin_activations SET state = \'failed_migration\', error_message = \'Interrupted by process crash prior to commit; transaction rolled back by SQLite.\', updated_at = unixepoch() WHERE plugin_slug = :slug;', [ ':slug' => $slug ] );
					}
				} );
			}
			$eligible_stmt = $this->db->query( "SELECT plugin_slug FROM plugin_activations WHERE state IN ('active', 'migrated', 'failed_compilation');" );
			$eligible_rows = $eligible_stmt->fetchAll();
			if ( ! empty( $eligible_rows ) ) {
				$this->compile_eligible_registry( $all_installed );
				$this->db->transaction( function ( WP_SQLite_DB $db ) use ( $eligible_rows, &$recovered ) {
					foreach ( $eligible_rows as $row ) {
						$slug = $row['plugin_slug'];
						$recovered[] = $slug;
						$db->query( 'UPDATE plugin_activations SET state = \'active\', error_message = NULL, updated_at = unixepoch() WHERE plugin_slug = :slug;', [ ':slug' => $slug ] );
					}
				} );
			}
		} catch ( Throwable $e ) {
			$this->gate->abandon_maintenance_sealed();
			return new WP_Error( 'recovery_failed', 'Recovery failed: ' . $e->getMessage() );
		}
		$this->gate->exit_maintenance();
		return [
			'success'        => true,
			'recovered'      => $recovered,
			'failed_crashes' => $failed_crashes
		];
	}
	protected function compile_eligible_registry( array $installed ): void
	{
		$stmt = $this->db->query( "SELECT plugin_slug FROM plugin_activations WHERE state IN ('active', 'migrated', 'failed_compilation');" );
		$rows = $stmt->fetchAll();
		$unified_listeners = [];
		foreach ( $rows as $row ) {
			$slug = $row['plugin_slug'];
			if ( ! isset( $installed[ $slug ] ) ) {
				throw new RuntimeException( "Source files missing for plugin: {$slug}" );
			}
			foreach ( $installed[ $slug ]->get_listeners() as $event => $listeners ) {
				if ( ! isset( $unified_listeners[ $event ] ) ) {
					$unified_listeners[ $event ] = [];
				}
				foreach ( $listeners as $listener ) {
					$unified_listeners[ $event ][] = $listener;
				}
			}
		}
		$target_file = rtrim( $this->cache_dir, '/' ) . '/listeners.json';
		$tmp_file = $target_file . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		$code = wp_json_encode( $unified_listeners );
		if ( ! is_dir( $this->cache_dir ) && ! mkdir( $this->cache_dir, 0700, true ) ) {
			throw new RuntimeException( 'Cannot create registry directory.' );
		}
		if ( false === file_put_contents( $tmp_file, $code, LOCK_EX ) ) {
			throw new RuntimeException( 'Cannot stage registry.' );
		}
		json_decode( (string) file_get_contents( $tmp_file ), true, 64, JSON_THROW_ON_ERROR );
		if ( ! rename( $tmp_file, $target_file ) ) {
			unlink( $tmp_file );
			throw new RuntimeException( 'Atomic promotion of listener registry failed.' );
		}

	}
}
