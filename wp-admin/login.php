<?php
/** Login, logout, invitations, password recovery and optional magic sign-in links. */
declare( strict_types=1 );
require_once dirname( __DIR__ ) . '/wp-includes/bootstrap.php';
require_once __DIR__ . '/admin-functions.php';
header( 'Cache-Control: no-store, private' );
global $wpdb;
$action = wp_input( $_REQUEST, 'action', 'login' );
if ( 'logout' === $action ) {
	wp_require_post( 'logout' );
	wp_logout();
	wp_safe_redirect( 'login.php?loggedout=1' );
}
$redirect = wp_validate_redirect( wp_input( $_REQUEST, 'redirect_to', 'index.php' ), 'index.php' );
$error = '';
$notice = '';
if ( get_current_user_id() > 0 && ! in_array( $action, [ 'rp', 'ml', 'invite' ], true ) ) {
	wp_safe_redirect( $redirect );
}
if ( 'lostpassword' === $action || 'magic' === $action ) {
	$purpose = 'lostpassword' === $action ? 'reset' : 'magic';
	if ( 'magic' === $purpose && '1' !== (string) get_option( 'magic_links_enabled', '0' ) ) {
		wp_admin_auth_header( 'Sign-In Links' );
		wp_admin_notice( 'Email sign-in links are not enabled on this site.', true );
		echo '<p><a href="login.php">Back to sign in</a></p>';
		wp_admin_auth_footer();
		exit;
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_require_post( $action );
		$email = strtolower( trim( wp_input( $_POST, 'email' ) ) );
		$user = $wpdb->get_row( 'SELECT id,email FROM users WHERE LOWER(email)=:email AND disabled=0', [ ':email' => $email ] );
		if ( null !== $user ) {
			wp_account_send_link( $purpose, (int) $user['id'], (string) $user['email'] );
		}
		$notice = 'If an account exists for that email address, a link is on its way.';
	}
	$form_nonce = wp_nonce( $action );
	wp_admin_auth_header( 'reset' === $purpose ? 'Reset Password' : 'Sign-In Link' );
	wp_admin_notice( $error, true );
	wp_admin_notice( $notice );
	?>
<form id="<?php echo esc_attr( $action ); ?>" action="login.php" method="post"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $form_nonce ); ?>" /><input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
<div class="form-field"><label for="email">Email address</label><input type="email" id="email" name="email" autocomplete="email" required /></div>
<button type="submit"><?php echo 'reset' === $purpose ? 'Email me a reset link' : 'Email me a sign-in link'; ?></button></form>
<p><a href="login.php">Back to sign in</a></p>
	<?php
	wp_admin_auth_footer();
	exit;
}
if ( 'rp' === $action || 'ml' === $action || 'invite' === $action ) {
	$purpose = [
		'rp'     => 'reset',
		'ml'     => 'magic',
		'invite' => 'invite'
	][ $action ];
	$row = wp_account_token_lookup( wp_input( $_REQUEST, 'token' ), $purpose );
	if ( 'magic' === $purpose && '1' !== (string) get_option( 'magic_links_enabled', '0' ) ) {
		$row = null;
	}
	if ( null !== $row && 'invite' !== $purpose ) {
		$token_user = $wpdb->get_row( 'SELECT disabled FROM users WHERE id=:id', [ ':id' => (int) $row['user_id'] ] );
		if ( null === $token_user || 0 !== (int) $token_user['disabled'] ) {
			$row = null;
		}
	}
	if ( null === $row ) {
		wp_admin_auth_header( 'Link Unavailable' );
		wp_admin_notice( 'This link is invalid, expired, or already used. Request a new one from the sign-in page.', true );
		echo '<p><a href="login.php">Back to sign in</a></p>';
		wp_admin_auth_footer();
		exit;
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_require_post( $action );
		try {
			if ( 'reset' === $purpose ) {
				$password = wp_input( $_POST, 'pwd' );
				if ( strlen( $password ) < 12 || wp_input( $_POST, 'pwd_confirm' ) !== $password ) {
					throw new RuntimeException( 'Enter the same password twice, at least 12 characters.' );
				}
				$wpdb->transaction( static function ( $db ) use ( $row, $password ): void {
					if ( ! wp_account_token_consume( $db, (int) $row['id'] ) ) {
						throw new RuntimeException( 'This link is unavailable. Request a new one from the sign-in page.' );
					}
					$db->query( 'UPDATE users SET password_hash=:hash WHERE id=:id', [
						':hash' => password_hash( $password, PASSWORD_DEFAULT ),
						':id'   => (int) $row['user_id']
					] );
					$db->query( 'DELETE FROM user_sessions WHERE user_id=:id', [ ':id' => (int) $row['user_id'] ] );
					if ( wp_api_tables_exist( $db ) ) {
						wp_api_revoke_user_tokens( $db, (int) $row['user_id'], 'account_change' );
					}
					wp_audit( 'password_reset_completed', (int) $row['user_id'] );
				} );
				wp_safe_redirect( 'login.php?reset=1' );
			}
			if ( 'magic' === $purpose ) {
				$wpdb->transaction( static function ( $db ) use ( $row ): void {
					if ( ! wp_account_token_consume( $db, (int) $row['id'] ) ) {
						throw new RuntimeException( 'This link is unavailable. Request a new one from the sign-in page.' );
					}
					wp_audit( 'magic_link_used', (int) $row['user_id'] );
					wp_login_user( (int) $row['user_id'] );
				} );
				wp_safe_redirect( 'index.php' );
			}
			$username = trim( wp_input( $_POST, 'username' ) );
			$password = wp_input( $_POST, 'pwd' );
			$display = trim( wp_input( $_POST, 'display_name' ) );
			if ( ! preg_match( '/^[\pL\pN_.-]{2,60}$/u', $username ) || strlen( $password ) < 12 ) {
				throw new RuntimeException( 'Choose a valid username and a password of at least 12 characters.' );
			}
			$new_id = $wpdb->transaction( static function ( $db ) use ( $row, $username, $password, $display ): int {
				if ( ! wp_account_token_consume( $db, (int) $row['id'] ) ) {
					throw new RuntimeException( 'This link is unavailable. Request a new one from the sign-in page.' );
				}
				if ( null !== $db->get_row( 'SELECT id FROM users WHERE username=:name COLLATE NOCASE OR email=:email COLLATE NOCASE', [
					':name'  => $username,
					':email' => $row['email']
				] ) ) {
					throw new RuntimeException( 'That username or email is already registered. Sign in or reset your password instead.' );
				}
				$roles = wp_roles();
				$caps = $roles[ (string) $row['role'] ] ?? $roles['author'];
				$db->query( 'INSERT INTO users(username,email,password_hash,capabilities,display_name) VALUES(:username,:email,:hash,:caps,:display)', [
					':username' => $username,
					':email'    => $row['email'],
					':hash'     => password_hash( $password, PASSWORD_DEFAULT ),
					':caps'     => wp_json_encode( $caps ),
					':display'  => '' !== $display ? $display : $username,
				] );
				$id = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
				wp_audit( 'user_created_from_invite', $id );
				return $id;
			} );
			wp_login_user( $new_id );
			wp_safe_redirect( 'index.php?invited=1' );
		} catch ( Throwable $exception ) {
			$error = $exception->getMessage();
		}
	}
	$form_nonce = wp_nonce( $action );
	wp_admin_auth_header( [
		'reset'  => 'Set a new password',
		'magic'  => 'Sign in',
		'invite' => 'Accept invitation'
	][ $purpose ] );
	wp_admin_notice( $error, true );
	$token = wp_input( $_REQUEST, 'token' );
	if ( 'reset' === $purpose ) :
		?>
<form id="rp" action="login.php" method="post"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $form_nonce ); ?>" /><input type="hidden" name="action" value="rp" /><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
<div class="form-field"><label for="pwd">New password</label><input type="password" id="pwd" name="pwd" autocomplete="new-password" minlength="12" required /></div>
<div class="form-field"><label for="pwd_confirm">Repeat new password</label><input type="password" id="pwd_confirm" name="pwd_confirm" autocomplete="new-password" minlength="12" required /></div>
<button type="submit">Set password</button></form>
	<?php elseif ( 'magic' === $purpose ) : ?>
<p>This sign-in link works once. Confirm to sign in as <?php echo esc_html( (string) $row['email'] ); ?>.</p>
<form id="ml" action="login.php" method="post"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $form_nonce ); ?>" /><input type="hidden" name="action" value="ml" /><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
<button type="submit">Sign in</button></form>
	<?php else : ?>
<form id="invite" action="login.php" method="post"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $form_nonce ); ?>" /><input type="hidden" name="action" value="invite" /><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
<div class="form-field"><label for="username">Username</label><input type="text" id="username" name="username" autocomplete="username" value="<?php echo esc_attr( wp_input( $_POST, 'username', wp_derive_username( (string) $row['email'] ) ) ); ?>" required /></div>
<div class="form-field"><label for="display_name">Display name</label><input type="text" id="display_name" name="display_name" value="<?php echo esc_attr( wp_input( $_POST, 'display_name' ) ); ?>" /></div>
<div class="form-field"><label for="pwd">Password</label><input type="password" id="pwd" name="pwd" autocomplete="new-password" minlength="12" required /></div>
<button type="submit">Create account and sign in</button></form>
		<?php
	endif;
	wp_admin_auth_footer();
	exit;
}
if ( get_current_user_id() > 0 ) {
	wp_safe_redirect( $redirect );
}
$nonce = wp_nonce( 'login' );
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'login' );
	$user = wp_authenticate( wp_input( $_POST, 'log' ), wp_input( $_POST, 'pwd' ) );
	if ( is_wp_error( $user ) ) {
		$error = $user->get_error_message();
		if ( 'login_throttled' === $user->get_error_code() ) {
			http_response_code( 429 );
			header( 'Retry-After: ' . (int) $user->get_error_data() );
		}
	} else {
		wp_login_user( (int) $user['id'] );
		wp_safe_redirect( $redirect );
	}
}
wp_admin_auth_header( 'Log in' );
wp_admin_notice( $error, true );
if ( isset( $_GET['loggedout'] ) ) {
	wp_admin_notice( 'You have been logged out.' );
}
if ( isset( $_GET['reset'] ) ) {
	wp_admin_notice( 'Password changed. Sign in with your new password.' );
}
?>
<form action="login.php" method="post"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" /><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>" />
<div class="form-field"><label for="log">Username or email</label><input type="text" id="log" name="log" autocomplete="username" value="<?php echo esc_attr( wp_input( $_POST, 'log' ) ); ?>" required /></div>
<div class="form-field"><label for="pwd">Password</label><input type="password" id="pwd" name="pwd" autocomplete="current-password" required /></div>
<button type="submit">Log in</button></form>
<p><a href="login.php?action=lostpassword">Forgot your password?</a><?php if ( '1' === (string) get_option( 'magic_links_enabled', '0' ) ) :
	?> · <a href="login.php?action=magic">Email me a sign-in link</a><?php 
                                                                    endif; ?></p>
<?php wp_admin_auth_footer();
