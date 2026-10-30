<?php
/** Built-in roles and account administration without a public registration surface. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$admin_page_title = 'People';
$admin_section = 'users';
$error = '';
$message = '';
$roles = wp_roles();
$delete_id = (int) wp_input( $_GET, 'delete' );
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'invite' === wp_input( $_POST, 'action' ) ) {
	wp_require_post( 'users' );
	try {
		$invite_email = strtolower( trim( wp_input( $_POST, 'invite_email' ) ) );
		$invite_role = wp_input( $_POST, 'invite_role', 'author' );
		if ( ! filter_var( $invite_email, FILTER_VALIDATE_EMAIL ) || ! isset( $roles[ $invite_role ] ) ) {
			throw new RuntimeException( 'Enter a valid email and role.' );
		}
		if ( null !== $wpdb->get_row( 'SELECT id FROM users WHERE LOWER(email)=:email', [ ':email' => $invite_email ] ) ) {
			throw new RuntimeException( 'That email already belongs to an account.' );
		}
		$invite_sent = wp_account_send_link( 'invite', null, $invite_email, $invite_role );
		if ( is_wp_error( $invite_sent ) ) {
			if ( 'mail_failed' === $invite_sent->get_error_code() ) {
				$wpdb->query( 'DELETE FROM account_tokens WHERE email=:email AND purpose=\'invite\' AND consumed_at IS NULL', [ ':email' => $invite_email ] );
				throw new RuntimeException( 'The invitation email could not be sent, so no invitation was created. Check the mail settings and the recorded delivery status.' );
			}
			throw new RuntimeException( $invite_sent->get_error_message() );
		}
		$message = 'Invitation sent to ' . $invite_email . '. The link works once and expires in an hour.';
	} catch ( Throwable $exception ) {
		$error = $exception->getMessage();
	}
}
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'delete_user' === wp_input( $_POST, 'action' ) ) {
	$delete_id = (int) wp_input( $_POST, 'id' );
	wp_require_post( 'delete-user:' . $delete_id );
	if ( '1' !== wp_input( $_POST, 'confirm_delete' ) ) {
		$error = 'Confirm the deletion before it runs.';
	} else {
		$result = wp_delete_user( $delete_id, (int) wp_input( $_POST, 'reassign_to' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
		} else {
			wp_safe_redirect( 'users.php?deleted=1' );
		}
	}
}
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && '' === $error && ! in_array( wp_input( $_POST, 'action' ), [ 'invite', 'delete_user' ], true ) ) {
	wp_require_post( 'users' );
	try {
		$role = wp_input( $_POST, 'role', 'author' );
		$password = wp_input( $_POST, 'password' );
		if ( ( ! isset( $roles[ $role ] ) && ! ( 'custom' === $role && (int) wp_input( $_POST, 'id' ) > 0 ) ) ) {
			throw new RuntimeException( 'Invalid role.' );
		}
		if ( '' !== $password && strlen( $password ) < 12 ) {
			throw new RuntimeException( 'Password needs at least 12 characters.' );
		}
		$id = (int) wp_input( $_POST, 'id' );
		$username = trim( wp_input( $_POST, 'username' ) );
		$email = trim( wp_input( $_POST, 'email' ) );
		if ( $id <= 0 && ( ! preg_match( '/^[\pL\pN_.-]{2,60}$/u', $username ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $password ) < 12 ) ) {
			throw new RuntimeException( 'Enter a valid username, email, and password.' );
		}
		$hash = '' !== $password ? password_hash( $password, PASSWORD_DEFAULT ) : '';
		$wpdb->transaction( function ( $db ) use ( $role, $roles, $hash, $id, $username, $email ): void {
			$api_routes = wp_api_routes_snapshot( $db );
			if ( ! current_user_can( 'administrator' ) ) {
				throw new RuntimeException( 'Account permission revoked.' );
			}
			if ( $id <= 0 ) {
				$db->query( 'INSERT INTO users(username,email,password_hash,capabilities,display_name) VALUES(:username,:email,:hash,:caps,:username)', [
					':username' => $username,
					':email'    => $email,
					':hash'     => $hash,
					':caps'     => wp_json_encode( $roles[ $role ] )
				] );
				wp_audit( 'user_created', (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'] );
			} else {
				$user = $db->get_row( 'SELECT * FROM users WHERE id=:id', [ ':id' => $id ] );
				if ( null === $user ) {
					throw new RuntimeException( 'Account not found.' );
				}
				$disabled = '1' === wp_input( $_POST, 'disabled' ) ? 1 : 0;
				$caps = json_decode( $user['capabilities'], true ) ?: [];
				if ( in_array( 'administrator', $caps, true ) && ( $disabled || 'administrator' !== $role ) && (int) $db->get_row( "SELECT COUNT(*) AS n FROM users WHERE disabled=0 AND capabilities LIKE '%\"administrator\"%'" )['n'] <= 1 ) {
					throw new RuntimeException( 'The last administrator must remain enabled.' );
				}
				$db->query( 'UPDATE users SET capabilities=:caps,disabled=:disabled,password_hash=:hash,profile_revision=profile_revision+1 WHERE id=:id', [
					':caps'     => wp_json_encode( 'custom' === $role ? $caps : $roles[ $role ] ),
					':disabled' => $disabled,
					':hash'     => '' !== $hash ? $hash : $user['password_hash'],
					':id'       => $id
				] );
				$db->query( 'DELETE FROM user_sessions WHERE user_id=:id', [ ':id' => $id ] );
				if ( wp_api_tables_exist( $db ) && ( 1 === $disabled || '' !== $hash || wp_json_encode( 'custom' === $role ? $caps : $roles[ $role ] ) !== (string) $user['capabilities'] ) ) {
					// Disable, capability and password changes revoke API credentials in the same transaction.
					wp_api_revoke_user_tokens( $db, $id, 'account_change' );
				}
				wp_audit( 'user_changed', $id, [
					'role'     => $role,
					'disabled' => $disabled
				] );
			}
			wp_api_routes_assert( $db, $api_routes );

		} );
		if ( 0 < $id && get_current_user_id() === $id ) {
			wp_logout();
			wp_safe_redirect( 'login.php?loggedout=1' );
		} $message = 'Account saved. Role, password, and disable changes revoke existing sessions.';
	} catch ( Throwable $exception ) {
		error_log( 'Account update: ' . $exception->getMessage() );
		$error = 'Account could not be saved. Check for an existing username/email, use a 12-character password, and keep one administrator enabled.';
	}
}
$users = $wpdb->get_results( 'SELECT id,username,email,capabilities,disabled FROM users ORDER BY username LIMIT 200' );
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( $message );
if ( isset( $_GET['deleted'] ) ) {
	wp_admin_notice( 'Account deleted. Its content and media were reassigned, and the deletion is recorded in the activity log.' );
}
?>
<details class="panel" <?php echo '' !== $error ? 'open' : ''; ?>><summary>Create an account</summary><form method="post"><?php wp_nonce_field( 'users' ); ?><?php wp_admin_field( 'username', 'Username', wp_input( $_POST, 'username' ) );
wp_admin_field( 'email', 'Email', wp_input( $_POST, 'email' ), 'email' ); ?><div class="form-field"><label for="new-password">Initial password</label><input id="new-password" name="password" type="password" autocomplete="new-password" minlength="12" required /></div><div class="form-field"><label for="new-role">Role</label><select id="new-role" name="role"><?php foreach ( $roles as $key => $capabilities ) :
	?><option value="<?php echo esc_attr( $key ); ?>" <?php echo 'author' === $key ? 'selected' : ''; ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></option><?php
endforeach; ?></select></div><button type="submit">Create account</button></form></details>
<details class="panel"><summary>Invite by email</summary><form method="post"><?php wp_nonce_field( 'users' ); ?><input type="hidden" name="action" value="invite" /><?php wp_admin_field( 'invite_email', 'Email', wp_input( $_POST, 'invite_email' ), 'email', 'The invitation link works once, expires in an hour, and asks the recipient to choose a username and password. The username is suggested from the email address and can be changed while accepting.' ); ?><div class="form-field"><label for="invite-role">Role</label><select id="invite-role" name="invite_role"><?php foreach ( $roles as $key => $capabilities ) :
	?><option value="<?php echo esc_attr( $key ); ?>" <?php echo 'author' === $key ? 'selected' : ''; ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></option><?php 
                                                                             endforeach; ?></select></div><button type="submit">Send invitation</button></form></details>
<p>Authors manage their own Posts. Page editors manage their own Pages. Editors manage both content types. Administrators also manage site settings, design, and accounts.</p>
<div class="table-scroll"><table><thead><tr><th scope="col">Account</th><th scope="col">Role and access</th></tr></thead><tbody><?php foreach ( $users as $user ) :
	$caps = json_decode( $user['capabilities'], true );
	$current_role = array_search( $caps, $roles, true );
	if ( false === $current_role ) {
		$current_role = in_array( 'administrator', $caps, true ) ? 'administrator' : 'custom';
	} ?>
<tr><td><strong><?php echo esc_html( $user['username'] ); ?></strong><br /><?php echo esc_html( $user['email'] ); ?><br /><a href="profile.php?user=<?php echo (int) $user['id']; ?>">Edit profile</a><?php if ( get_current_user_id() !== (int) $user['id'] ) :
	?> · <a href="users.php?delete=<?php echo (int) $user['id']; ?>">Delete account</a><?php 
                endif; ?></td><td><form method="post"><?php wp_nonce_field( 'users' ); ?><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>" /><label for="role-<?php echo (int) $user['id']; ?>">Role</label><select id="role-<?php echo (int) $user['id']; ?>" name="role"><?php if ( 'custom' === $current_role ) :
	?><option value="custom" selected>Keep custom permissions</option><?php
                endif; ?><?php foreach ( $roles as $key => $capabilities ) :
	?><option value="<?php echo esc_attr( $key ); ?>" <?php echo $current_role === $key ? 'selected' : ''; ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?></option><?php
                endforeach; ?></select><label class="inline-checkbox"><input type="checkbox" name="disabled" value="1" <?php echo 1 === (int) $user['disabled'] ? 'checked' : ''; ?> /> Disable account</label><label for="password-<?php echo (int) $user['id']; ?>">New password (leave blank to keep)</label><input id="password-<?php echo (int) $user['id']; ?>" name="password" type="password" autocomplete="new-password" minlength="12" /><p><button type="submit">Save account</button></p></form></td></tr>
																																<?php endforeach; ?></tbody></table></div>
<?php if ( $delete_id > 0 ) :
	$victim = $wpdb->get_row( 'SELECT id,username,email FROM users WHERE id=:id', [ ':id' => $delete_id ] );
	if ( null === $victim ) :
		wp_admin_notice( 'That account no longer exists.', true );
	else :
		$counts = wp_user_content_counts( $wpdb, $delete_id );
		$targets = $wpdb->get_results( 'SELECT id,username FROM users WHERE disabled=0 AND id<>:id ORDER BY username', [ ':id' => $delete_id ] );
		?>
<section class="panel" aria-labelledby="delete-account-title"><h2 id="delete-account-title">Delete account: <?php echo esc_html( $victim['username'] ); ?></h2>
<p>Deletion is permanent and separate from disabling. Nothing the account made is deleted with it:</p>
<ul><li><?php echo (int) $counts['posts']; ?> posts and <?php echo (int) $counts['pages']; ?> pages stay published under the account you choose below.</li><li><?php echo (int) $counts['media']; ?> media items (including derived sizes and documents) are reassigned; no stored files are removed.</li><li><?php echo (int) $counts['drafts']; ?> unpublished design drafts are discarded.</li><li><?php echo (int) $counts['sessions']; ?> active sessions are revoked immediately.</li></ul>
<p>A minimal record of the deletion (account id, username, and the reassignment) stays in the activity log. Backups taken before the deletion may still contain the account until those backups expire; deleting an account here does not edit existing backups.</p>
<form method="post"><?php wp_nonce_field( 'delete-user:' . $delete_id ); ?><input type="hidden" name="action" value="delete_user" /><input type="hidden" name="id" value="<?php echo (int) $delete_id; ?>" />
<div class="form-field"><label for="reassign_to">Reassign content and media to</label><select id="reassign_to" name="reassign_to" required><option value="">Choose an enabled account</option><?php foreach ( $targets as $target ) :
	?><option value="<?php echo (int) $target['id']; ?>"><?php echo esc_html( $target['username'] ); ?></option><?php 
                                                                                                                                                                                              endforeach; ?></select></div>
<label class="inline-checkbox"><input type="checkbox" name="confirm_delete" value="1" required /> I understand this permanently deletes the account <?php echo esc_html( $victim['username'] ); ?> (<?php echo esc_html( $victim['email'] ); ?>).</label>
<p><button type="submit">Delete account permanently</button> <a href="users.php">Cancel</a></p></form></section>
	<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/admin-footer.php';
