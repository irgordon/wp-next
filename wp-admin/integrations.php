<?php
/** Editorial API credentials and activation state. Sealed operator work (epoch initialization and rotation, restore) stays on the CLI; this page issues and revokes credentials and enables or disables the API. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$admin_page_title = 'Integrations';
$admin_section = 'settings';
$error = '';
$message = '';
$issued_token = '';
$https = wp_is_https();
$state = wp_api_state( $wpdb );
$epoch = wp_api_epoch_read();
$bound = null !== $state && '' !== (string) ( $state['bound_epoch'] ?? '' );
$epoch_ok = ! is_wp_error( $epoch ) && $bound && (string) $state['bound_epoch'] === $epoch;
$enabled = null !== $state && 1 === (int) $state['enabled'];
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'integrations' );
	try {
		$action = wp_input( $_POST, 'api_action' );
		if ( 'enable' === $action ) {
			if ( ! $https ) {
				throw new RuntimeException( 'The API can only be enabled from an HTTPS session.' );
			}
			$result = wp_api_set_enabled( $wpdb, true, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			$message = 'Editorial API enabled.';
			$enabled = true;
		} elseif ( 'disable' === $action ) {
			wp_api_set_enabled( $wpdb, false, get_current_user_id() );
			$message = 'Editorial API disabled. Every API credential was revoked.';
			$enabled = false;
		} elseif ( 'issue' === $action ) {
			if ( ! $https ) {
				throw new RuntimeException( 'Credentials can only be issued and displayed from an HTTPS session.' );
			}
			$scopes = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_map( 'strval', $_POST['scopes'] ) : [];
			$issued = wp_api_token_issue( $wpdb, (int) wp_input( $_POST, 'user_id' ), get_current_user_id(), wp_input( $_POST, 'token_name' ), $scopes, (int) wp_input( $_POST, 'days', '30' ) );
			if ( is_wp_error( $issued ) ) {
				throw new RuntimeException( $issued->get_error_message() );
			}
			$issued_token = (string) $issued['token'];
			$message = 'Credential issued. Copy it now — it is shown once and cannot be recovered.';
		} elseif ( 'revoke' === $action ) {
			if ( ! wp_api_revoke_token( $wpdb, wp_input( $_POST, 'token_id' ), 'manual', get_current_user_id() ) ) {
				throw new RuntimeException( 'No active credential with that ID.' );
			}
			$message = 'Credential revoked.';
		}
		$state = wp_api_state( $wpdb );
	} catch ( Throwable $exception ) {
		$error = $exception->getMessage();
	}
}
$users = $wpdb->get_results( 'SELECT id,username FROM users WHERE disabled=0 ORDER BY username' );
$tokens = wp_api_tables_exist( $wpdb ) ? $wpdb->get_results( 'SELECT t.*,u.username FROM api_tokens t JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC LIMIT 200' ) : [];
$conflicts = $enabled || $bound ? wp_api_route_conflicts( $wpdb ) : [];
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( $message );
?>
<nav class="actions settings-navigation" aria-label="Settings"><a href="options-general.php">General</a><a href="options-permalink.php">Permalinks</a><a href="integrations.php" aria-current="page">Integrations</a></nav>
<section class="panel"><h2>Editorial API</h2>
<p>The editorial API at <code><?php echo esc_html( wp_home_url( wp_api_mount_path() . '/' ) ); ?></code> serves authenticated server-to-server integrations. It is not a public browsing API: every request needs a named Bearer credential, and browser cookies never authorize it.</p>
<?php if ( '' !== $issued_token ) : ?>
<div class="notice notice-success"><p><strong>New credential (shown once):</strong></p><p><code><?php echo esc_html( $issued_token ); ?></code></p><p>Store it in the integration's secret store. It will not be displayed again.</p></div>
<?php endif; ?>
<dl>
<dt>Status</dt><dd><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></dd>
<dt>Epoch</dt><dd><?php
if ( '' === wp_api_epoch_path() ) {
	echo 'Not configured. Set WP_API_EPOCH_PATH to an absolute path outside the application and backup trees, then run <code>bin/wp api:init</code>.';
} elseif ( ! $bound ) {
	echo 'Not initialized. Run <code>bin/wp api:init</code> while the site is sealed, then enable the API here or with <code>bin/wp api:enable</code>.';
} elseif ( is_wp_error( $epoch ) ) {
	echo 'Unavailable: ' . esc_html( $epoch->get_error_message() ) . ' API requests are refused while the epoch file cannot be verified.';
} elseif ( ! $epoch_ok ) {
	echo 'Mismatch between the epoch file and the database binding. API requests are refused; investigate before rotating with <code>bin/wp api:rotate</code>.';
} else {
	echo 'Initialized and bound.';
}
?></dd>
<dt>Base path</dt><dd><?php echo wp_api_base_path_consistent() ? 'WP_API_BASE_PATH matches the site base path.' : 'WP_API_BASE_PATH does not match the configured site base path; activation is blocked.'; ?></dd>
<?php if ( [] !== $conflicts ) :
	?><dt>Route conflicts</dt><dd><?php echo esc_html( implode( ' ', $conflicts ) ); ?></dd><?php 
endif; ?>
</dl>
<?php if ( ! $https ) :
	?><p class="description">This session is not over HTTPS. Enabling the API and issuing credentials are unavailable here; token issue and display require HTTPS.</p><?php 
endif; ?>
<form method="post"><?php wp_nonce_field( 'integrations' ); ?>
<?php if ( $enabled ) :
	?><input type="hidden" name="api_action" value="disable" /><button type="submit">Disable API and revoke all credentials</button>
<?php else :
	?><input type="hidden" name="api_action" value="enable" /><button type="submit" <?php echo $https ? '' : 'disabled'; ?>>Enable API</button><?php 
endif; ?>
</form>
</section>
<section class="panel"><h2>Issue a credential</h2>
<p>Credentials belong to an existing enabled account and act with the intersection of their scopes and that account's current capabilities. Password changes, resets, role changes, disable and deletion revoke them. Default expiry is 30 days; the maximum is 90.</p>
<form method="post"><?php wp_nonce_field( 'integrations' ); ?><input type="hidden" name="api_action" value="issue" />
<div class="form-field"><label for="token-user">Account</label><select id="token-user" name="user_id"><?php foreach ( $users as $user ) :
	?><option value="<?php echo (int) $user['id']; ?>"><?php echo esc_html( $user['username'] ); ?></option><?php 
                                                                                                      endforeach; ?></select></div>
<?php wp_admin_field( 'token_name', 'Credential name', '', 'text', 'A label the operators will recognize, such as the integrating service.' ); ?>
<fieldset><legend>Scopes</legend>
<?php foreach ( WP_API_TOKEN_SCOPES as $scope ) :
	?><label class="inline-checkbox"><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>" /> <?php echo esc_html( $scope ); ?></label>
<?php endforeach; ?></fieldset>
<p class="description">Publication requires both the write and the publish scope for the same resource type, plus the account's publish capability at commit time.</p>
<div class="form-field"><label for="token-days">Expires in (days)</label><input id="token-days" name="days" type="number" min="1" max="90" value="30" /></div>
<button type="submit" <?php echo $https ? '' : 'disabled'; ?>>Issue credential</button></form>
</section>
<section class="panel"><h2>Credentials</h2>
<?php if ( [] === $tokens ) :
	?><p>No credentials have been issued.</p><?php 
else : ?>
<table class="widefat"><thead><tr><th>Name</th><th>Account</th><th>Scopes</th><th>Created</th><th>Expires</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>
	<?php foreach ( $tokens as $token_row ) :
		$token_status = null !== $token_row['revoked_at'] ? 'Revoked (' . esc_html( (string) $token_row['revoke_reason'] ) . ')' : ( (int) $token_row['expires_at'] <= time() ? 'Expired' : 'Active' );
		?>
<tr><td><?php echo esc_html( (string) $token_row['name'] ); ?><br /><code><?php echo esc_html( (string) $token_row['id'] ); ?></code></td>
<td><?php echo esc_html( (string) $token_row['username'] ); ?></td>
<td><?php echo esc_html( implode( ', ', wp_api_token_scopes( $wpdb, (string) $token_row['id'] ) ) ); ?></td>
<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $token_row['created_at'] ) ); ?></td>
<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $token_row['expires_at'] ) ); ?></td>
<td><?php echo null === $token_row['last_used_at'] ? 'Never' : esc_html( gmdate( 'Y-m-d H:i', (int) $token_row['last_used_at'] ) ); ?></td>
<td><?php echo $token_status; ?></td>
<td><?php if ( 'Active' === $token_status ) :
	?><form method="post"><?php wp_nonce_field( 'integrations' ); ?><input type="hidden" name="api_action" value="revoke" /><input type="hidden" name="token_id" value="<?php echo esc_attr( (string) $token_row['id'] ); ?>" /><button type="submit">Revoke</button></form><?php 
    endif; ?></td></tr>
    <?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</section>
<?php require __DIR__ . '/admin-footer.php';