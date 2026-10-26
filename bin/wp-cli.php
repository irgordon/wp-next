<?php
/** Operator commands. All maintenance commands use the same gate instance. */
declare( strict_types=1 );
global $wpdb, $wp_job_queue, $wp_admission_gate;
$command = $argv[1] ?? 'help';
if ( 'help' === $command ) {
	echo "Commands: upgrade, queue:work [limit], queue:retry, backup, maintain, sessions:revoke, status\n";
	exit;
}
if ( in_array( $command, [ 'upgrade', 'sessions:revoke' ], true ) ) {
	define( 'WP_MAINTENANCE_COMMAND', true );
}
require_once dirname( __DIR__ ) . '/wp-includes/bootstrap.php';
use WordPress\Jobs\WP_Job_Worker;
try {
	switch ( $command ) {
		case 'upgrade':
			$wp_admission_gate->enter_maintenance();
			try {
				wp_create_backup( $wpdb, 'before-upgrade' );
				wp_upgrade_schema( $wpdb );
				$wpdb->query( 'DELETE FROM user_sessions' );
				$wp_admission_gate->exit_maintenance();
			} catch ( Throwable $error ) {
				$wp_admission_gate->abandon_maintenance_sealed();
				throw $error;
			}
			echo "Schema upgraded. Existing sessions were revoked.\n";
break;
		case 'queue:work': echo ( new WP_Job_Worker( $wpdb, $wp_job_queue ) )->run( min( 1000, max( 1, (int) ( $argv[2] ?? 50 ) ) ) ) . " jobs processed.\n";
break;
		case 'queue:retry':
			$wpdb->query( "UPDATE job_queue SET status='pending',attempts=0,lease_token=NULL,lease_expires_at=NULL,available_at=unixepoch() WHERE status='failed'" );
			echo "Failed jobs queued for retry.\n";
break;
		case 'sessions:revoke':
			$wp_admission_gate->enter_maintenance();
			$wpdb->query( 'DELETE FROM user_sessions' );
			$wp_admission_gate->exit_maintenance();
			echo "All sessions revoked.\n";
break;
		case 'user:reset-link':
			$who = (string) ( $argv[2] ?? '' );
			$user = $wpdb->get_row( 'SELECT id,email FROM users WHERE (username=:who COLLATE NOCASE OR LOWER(email)=LOWER(:who)) AND disabled=0', [ ':who' => $who ] );
			if ( null === $user ) {
				echo "No enabled account matches.\n";
				break; 
			}
			$token = wp_account_token_create( 'reset', (string) $user['email'], (int) $user['id'], '', 3600, true );
			if ( is_wp_error( $token ) ) {
				echo 'Could not create a reset link: ' . $token->get_error_message() . "\n";
				break; 
			}
			echo 'One-time reset link (expires in an hour, works once): ' . wp_home_url( '/wp-admin/login.php?action=rp&token=' . $token ) . "\n";
break;
		case 'backup': echo 'Backup: ' . wp_create_backup( $wpdb ) . "\n";
break;
		case 'maintain':
			$wpdb->query( 'DELETE FROM user_sessions WHERE expires_at<=unixepoch()' );
			$wpdb->query( 'DELETE FROM login_attempts WHERE expires_at<=unixepoch()' );
			$wpdb->query( "DELETE FROM job_queue WHERE status='completed' AND created_at<unixepoch()-2592000" );
			$checkpoint = $wpdb->get_row( 'PRAGMA wal_checkpoint(PASSIVE)' );
			echo wp_json_encode( [
				'checkpoint'      => $checkpoint,
				'expired_records' => 'removed',
				'audit_records'   => 'retained',
                'assets'          => wp_asset_reconcile()
			] ) . "\n";
break;
		case 'status': echo wp_json_encode( [
			'schema_version' => wp_schema_version( $wpdb ),
			'jobs'           => $wpdb->get_results( 'SELECT status,COUNT(*) AS count FROM job_queue GROUP BY status' ),
			'maintenance'    => $wp_admission_gate->is_in_maintenance(),
            'assets'         => wp_asset_usage()
		], JSON_PRETTY_PRINT ) . "\n";
break;
		default:
throw new RuntimeException( 'Unknown command.' );
	}
} catch ( Throwable $error ) {
	error_log( 'CLI: ' . $error->getMessage() );
	fwrite( STDERR, "Command failed. Check the operator log.\n" );
	exit( 1 );
}
