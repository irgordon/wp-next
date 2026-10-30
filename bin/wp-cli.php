<?php
/** Operator commands. All maintenance commands use the same gate instance. */
declare( strict_types=1 );
global $wpdb, $wp_job_queue, $wp_admission_gate;
$command = $argv[1] ?? 'help';
if ( 'help' === $command ) {
	echo "Commands: upgrade, queue:work [limit], queue:retry, backup, restore <archive>, maintain, sessions:revoke, status, export [path], import <archive> [--commit] [--media=<zip>], api:status, api:init, api:rotate, api:enable, api:disable, api:issue <user> <name> <scopes> [days], api:revoke <token-id>, api:tokens\n";
	exit;
}
if ( in_array( $command, [ 'upgrade', 'sessions:revoke', 'restore', 'api:init', 'api:rotate' ], true ) ) {
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
		case 'restore':
			$summary = wp_restore_backup( $wpdb, (string) ( $argv[2] ?? '' ), $wp_admission_gate );
			echo 'Restore complete: ' . wp_json_encode( $summary ) . "\n";
break;
		case 'api:init':
			$wp_admission_gate->enter_maintenance();
			try {
				$init = wp_api_initialize( $wpdb );
				if ( is_wp_error( $init ) ) {
					throw new RuntimeException( $init->get_error_message() );
				}
				$wp_admission_gate->exit_maintenance();
			} catch ( Throwable $error ) {
				$wp_admission_gate->abandon_maintenance_sealed();
				throw $error;
			}
			echo "API epoch initialized and bound. The API remains disabled; enable it with api:enable after activation checks.\n";
break;
		case 'api:rotate':
			$wp_admission_gate->enter_maintenance();
			try {
				$rotated = wp_api_rotate_epoch( $wpdb );
				if ( is_wp_error( $rotated ) ) {
					throw new RuntimeException( $rotated->get_error_message() );
				}
				$wp_admission_gate->exit_maintenance();
			} catch ( Throwable $error ) {
				$wp_admission_gate->abandon_maintenance_sealed();
				throw $error;
			}
			echo "API epoch rotated. Every API credential was revoked.\n";
break;
		case 'api:enable':
			$enabled = wp_api_set_enabled( $wpdb, true, 0 );
			echo is_wp_error( $enabled ) ? 'Enable failed: ' . $enabled->get_error_message() . "\n" : "Editorial API enabled.\n";
break;
		case 'api:disable':
			wp_api_set_enabled( $wpdb, false, 0 );
			echo "Editorial API disabled. Every API credential was revoked.\n";
break;
		case 'api:issue':
			$who = (string) ( $argv[2] ?? '' );
			$owner = $wpdb->get_row( 'SELECT id FROM users WHERE (username=:who COLLATE NOCASE OR LOWER(email)=LOWER(:who)) AND disabled=0', [ ':who' => $who ] );
			if ( null === $owner ) {
				echo "No enabled account matches.\n";
				break;
			}
			$issued = wp_api_token_issue( $wpdb, (int) $owner['id'], 0, (string) ( $argv[3] ?? '' ), explode( ',', (string) ( $argv[4] ?? '' ) ), (int) ( $argv[5] ?? 30 ) );
			if ( is_wp_error( $issued ) ) {
				echo 'Issue failed: ' . $issued->get_error_message() . "\n";
				break;
			}
			echo "Token (shown once, store it in a secret store): " . $issued['token'] . "\nExpires: " . gmdate( DATE_ATOM, (int) $issued['expires_at'] ) . "\n";
break;
		case 'api:revoke':
			echo wp_api_revoke_token( $wpdb, (string) ( $argv[2] ?? '' ), 'manual', 0 ) ? "Credential revoked.\n" : "No active credential with that ID.\n";
break;
		case 'api:tokens':
			foreach ( $wpdb->get_results( 'SELECT t.id,t.name,t.expires_at,t.revoked_at,t.revoke_reason,t.last_used_at,u.username FROM api_tokens t JOIN users u ON u.id=t.user_id ORDER BY t.created_at' ) as $token_row ) {
				$token_scopes = implode( ',', wp_api_token_scopes( $wpdb, (string) $token_row['id'] ) );
				$token_status = null !== $token_row['revoked_at'] ? 'revoked (' . $token_row['revoke_reason'] . ')' : ( (int) $token_row['expires_at'] <= time() ? 'expired' : 'active' );
				echo $token_row['id'] . '  ' . $token_row['username'] . '  "' . $token_row['name'] . '"  [' . $token_scopes . ']  ' . $token_status . '  expires ' . gmdate( DATE_ATOM, (int) $token_row['expires_at'] ) . "\n";
			}
break;
		case 'api:status':
			$api_state = wp_api_state( $wpdb );
			$api_epoch = wp_api_epoch_read();
			echo wp_json_encode( [
				'enabled'       => null !== $api_state && 1 === (int) $api_state['enabled'],
				'bound'         => null !== $api_state && '' !== (string) ( $api_state['bound_epoch'] ?? '' ),
				'epoch_file'    => is_wp_error( $api_epoch ) ? $api_epoch->get_error_code() : 'ok',
				'epoch_match'   => ! is_wp_error( $api_epoch ) && null !== $api_state && (string) ( $api_state['bound_epoch'] ?? '' ) === $api_epoch,
				'base_path_ok'  => wp_api_base_path_consistent(),
				'active_tokens' => wp_api_tables_exist( $wpdb ) ? wp_api_token_active_count( $wpdb ) : 0,
				'conflicts'     => wp_api_tables_exist( $wpdb ) ? wp_api_route_conflicts( $wpdb ) : []
			], JSON_PRETTY_PRINT ) . "\n";
break;
		case 'export':
		case 'import':
			$admin = $wpdb->get_row( "SELECT id FROM users WHERE disabled=0 AND capabilities LIKE '%\"administrator\"%' ORDER BY id LIMIT 1" );
			if ( null === $admin || null === wp_set_current_user( (int) $admin['id'] ) ) {
				echo "No enabled administrator account is available.\n";
				break;
			}
			if ( 'export' === $command ) {
				$export = wp_portability_build_export();
				if ( is_wp_error( $export ) ) {
					echo 'Export failed: ' . $export->get_error_message() . "\n";
					break;
				}
				$target = (string) ( $argv[2] ?? $export['filename'] );
				if ( ! copy( $export['path'], $target ) ) {
					echo "Export failed: the archive could not be written to the target path.\n";
					break;
				}
				unlink( $export['path'] );
				echo 'Export: ' . $target . "\n";
				break;
			}
			$archive = (string) ( $argv[2] ?? '' );
			$commit = in_array( '--commit', $argv, true );
			$media_zip = null;
			foreach ( $argv as $argument ) {
				if ( str_starts_with( (string) $argument, '--media=' ) ) {
					$media_zip = substr( (string) $argument, 8 );
				}
			}
			$parsed = str_ends_with( strtolower( $archive ), '.xml' ) || str_ends_with( strtolower( $archive ), '.wxr' ) ? wp_portability_parse_wxr( $archive, $media_zip ) : wp_portability_parse_native( $archive );
			if ( is_wp_error( $parsed ) ) {
				echo 'Import failed: ' . $parsed->get_error_message() . "\n";
				break;
			}
			if ( ! $commit ) {
				echo wp_json_encode( wp_portability_preflight( $parsed['model'] ), JSON_PRETTY_PRINT ) . "\n";
				echo "Preflight only. Re-run with --commit to import.\n";
			} else {
				$run = wp_portability_run( $parsed );
				foreach ( $run['results'] as $result ) {
					echo $result['kind'] . ' "' . $result['label'] . '": ' . $result['status'] . ' — ' . $result['detail'] . "\n";
				}
			}
			wp_portability_remove_tree( (string) $parsed['directory'] );
break;
		case 'maintain':
			$wpdb->query( 'DELETE FROM user_sessions WHERE expires_at<=unixepoch()' );
			$wpdb->query( 'DELETE FROM login_attempts WHERE expires_at<=unixepoch()' );
			if ( wp_api_tables_exist( $wpdb ) ) {
				// Receipts become body-less tombstones when the replay window
				// closes, and are dropped once their credential has expired.
				$wpdb->query( "UPDATE api_mutation_receipts SET state='expired',response_json=NULL,location=NULL WHERE state='replayable' AND replay_until<=unixepoch()" );
				$wpdb->query( "DELETE FROM api_mutation_receipts WHERE state='expired' AND token_id IN (SELECT id FROM api_tokens WHERE expires_at<=unixepoch())" );
				$wpdb->query( 'DELETE FROM api_rate_windows WHERE expires_at<=unixepoch()' );
			}
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
