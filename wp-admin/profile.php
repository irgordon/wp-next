<?php
/** Own profile and administrator profile editing; contact/security changes require reauthentication. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
$actor = get_current_user_id();
$id = (int) wp_input( $_GET, 'user', (string) $actor );
if ( $id !== $actor && ! current_user_can( 'administrator' ) ) {
	wp_die( 'You cannot edit this profile.', 'Access denied', [ 'response' => 403 ] ); 
}
$user = $wpdb->get_row( 'SELECT * FROM users WHERE id=:id', [ ':id' => $id ] );
if ( null === $user ) {
	wp_die( 'Profile not found.', 'Not found', [ 'response' => 404 ] ); 
}
$admin_page_title = $id === $actor ? 'Your Profile' : 'Edit Profile';
$admin_section = 'profile';
$error = '';
$message = '';
$action = $id === $actor ? 'profile' : 'profile:' . $id;
$form = array_intersect_key( $user, array_flip( [ 'email', 'first_name', 'last_name', 'display_name', 'biography', 'website', 'avatar_media_id', 'public_profile', 'profile_revision' ] ) );
$form['display_name'] = $form['display_name'] ?: $user['username'];
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( $action );
	try {
		if ( 'revoke' === wp_input( $_POST, 'action' ) ) {
			$wpdb->transaction( static function ( $db ) use ( $actor, $id ): void {
				if ( get_current_user_id() !== $actor || ( $id !== $actor && ! current_user_can( 'administrator' ) ) ) {
					throw new RuntimeException( 'Permission revoked.' ); 
				}
				$db->query( 'DELETE FROM user_sessions WHERE user_id=:id AND token_hash<>:hash', [
					':id'   => $id,
					':hash' => $id === $actor ? hash( 'sha256', wp_input( $_COOKIE, 'wp_session_token' ) ) : ''
				] );
				wp_audit( 'sessions_revoked', $id );
			} );
			$message = 'Other sessions were revoked.';
		} else {
			foreach ( $form as $key => $value ) {
				if ( array_key_exists( $key, $_POST ) ) {
					$form[ $key ] = wp_input( $_POST, $key ); 
				} 
			}
			if ( isset( $_POST['profile_fields'] ) ) {
				$form['public_profile'] = '1' === wp_input( $_POST, 'public_profile' ) ? 1 : 0; 
			}
			$form['public_profile'] = 1 === (int) $form['public_profile'] ? 1 : 0;
			$password = wp_input( $_POST, 'password' );
			$sensitive = '' !== $password || $form['email'] !== $user['email'];
			$actor_row = $wpdb->get_row( 'SELECT password_hash FROM users WHERE id=:id', [ ':id' => $actor ] );
			if ( ! filter_var( $form['email'], FILTER_VALIDATE_EMAIL ) || ( $sensitive && ! password_verify( wp_input( $_POST, 'current_password' ), $actor_row['password_hash'] ) ) || ( '' !== $password && strlen( $password ) < 12 ) ) {
				throw new RuntimeException( 'Email/password validation failed.' ); 
			}
			foreach ( [ 'first_name', 'last_name', 'display_name' ] as $field ) {
				$form[ $field ] = sanitize_text_field( $form[ $field ] );
				if ( strlen( $form[ $field ] ) > 200 ) {
					throw new RuntimeException( 'Name too long.' ); 
				} 
			}
			$form['display_name'] = trim( $form['display_name'] ) ?: $user['username'];
			$form['biography'] = trim( strip_tags( $form['biography'] ) );
			if ( strlen( $form['biography'] ) > 4000 ) {
				throw new RuntimeException( 'Biography too long.' ); 
			}
			$form['website'] = trim( $form['website'] );
			if ( strlen( $form['website'] ) > 2048 || ( '' !== $form['website'] && ( ! filter_var( $form['website'], FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) parse_url( $form['website'], PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) || parse_url( $form['website'], PHP_URL_USER ) !== null ) ) ) {
				throw new RuntimeException( 'Invalid website.' ); 
			}
			$hash = '' === $password ? $user['password_hash'] : password_hash( $password, PASSWORD_DEFAULT );
			$wpdb->transaction( static function ( $db ) use ( $form, $user, $hash, $actor, $id, $sensitive, $actor_row ): void {
				$live_actor = $db->get_row( 'SELECT password_hash,disabled FROM users WHERE id=:id', [ ':id' => $actor ] );
				if ( null === $live_actor || (int) $live_actor['disabled'] || ( $sensitive && $live_actor['password_hash'] !== $actor_row['password_hash'] ) || ( $id !== $actor && ! current_user_can( 'administrator' ) ) ) {
					throw new RuntimeException( 'Permission changed.' ); 
				}
				$avatar = (int) $form['avatar_media_id'];
				if ( $avatar > 0 && null === $db->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $avatar ] ) ) {
					throw new RuntimeException( 'Avatar missing.' ); 
				}
				$stmt = $db->query( 'UPDATE users SET email=:email,first_name=:first,last_name=:last,display_name=:display,biography=:bio,website=:website,avatar_media_id=:avatar,public_profile=:public,password_hash=:hash,profile_revision=profile_revision+1 WHERE id=:id AND profile_revision=:revision AND password_hash=:previous', [
					':email'    => $form['email'],
					':first'    => $form['first_name'],
					':last'     => $form['last_name'],
					':display'  => $form['display_name'],
					':bio'      => $form['biography'],
					':website'  => $form['website'],
					':avatar'   => $avatar ?: null,
					':public'   => (int) $form['public_profile'],
					':hash'     => $hash,
					':id'       => $id,
					':revision' => (int) $form['profile_revision'],
					':previous' => $user['password_hash']
				] );
				if ( 1 !== $stmt->rowCount() ) {
					throw new RuntimeException( 'Profile changed concurrently.' ); 
				}
				if ( $hash !== $user['password_hash'] ) {
					$db->query( 'DELETE FROM user_sessions WHERE user_id=:id', [ ':id' => $id ] ); 
				}
				wp_audit( 'profile_updated', $id );
			} );
			if ( '' !== $password && $id === $actor ) {
				wp_logout();
				wp_safe_redirect( 'login.php?loggedout=1' ); 
			}
			$form['profile_revision'] = (int) $form['profile_revision'] + 1;
			$message = 'Profile saved.';
		}
	} catch ( Throwable $exception ) {
		$error = wp_failure( 'profile_failed', $exception, 'Profile could not be saved. Check the current revision, website, email, and current password for contact/security changes.' )->get_error_message(); 
	}
}
$media = $wpdb->get_results( "SELECT id,original_name FROM media WHERE kind='image' AND state='ready' AND (id=:selected OR id IN (SELECT id FROM media ORDER BY id DESC LIMIT 200)) ORDER BY id DESC", [ ':selected' => (int) $form['avatar_media_id'] ] );
$sessions = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM user_sessions WHERE user_id=:id AND expires_at>unixepoch()', [ ':id' => $id ] )['n'];
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( $message );
?>
<section class="panel"><p>Account: <strong><?php echo esc_html( $user['username'] ); ?></strong>. Username and permissions are managed separately.</p>
<form method="post"><?php wp_nonce_field( $action ); ?><input type="hidden" name="profile_fields" value="1" /><input type="hidden" name="profile_revision" value="<?php echo (int) $form['profile_revision']; ?>" />
<?php foreach ( [
	'display_name' => 'Public display name',
	'first_name'   => 'First name',
	'last_name'    => 'Last name'
] as $key => $label ) {
	wp_admin_field( $key, $label, (string) $form[ $key ] ); 
} ?>
<div class="form-field"><label for="biography">Biography</label><textarea id="biography" name="biography" maxlength="4000"><?php echo esc_textarea( $form['biography'] ); ?></textarea></div>
<?php wp_admin_field( 'website', 'Website', (string) $form['website'], 'url' ); ?>
<div class="form-field"><label for="avatar_media_id">Profile image</label><select id="avatar_media_id" name="avatar_media_id"><option value="0">No image</option><?php foreach ( $media as $item ) :
	?><option value="<?php echo (int) $item['id']; ?>" <?php echo (int) $form['avatar_media_id'] === (int) $item['id'] ? 'selected' : ''; ?>><?php echo esc_html( $item['original_name'] ); ?></option><?php 
                                                                                                                                                                 endforeach; ?></select></div>
<label class="inline-checkbox"><input type="checkbox" name="public_profile" value="1" <?php echo $form['public_profile'] ? 'checked' : ''; ?> /> Publish an author profile</label><p class="description">Publishes your display name, biography, website, profile image and published Posts. Email and first/last name fields are not published separately. No external avatar service is used.</p>
<?php wp_admin_field( 'email', 'Email', (string) $form['email'], 'email' ); ?>
<div class="form-field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" /><p class="description">Your password is required only when changing email or a password.</p></div>
<div class="form-field"><label for="password">New password (optional)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" /></div><button type="submit">Save profile</button></form></section>
<section class="panel"><h2>Sessions</h2><p><?php echo $sessions; ?> active session(s).</p><form method="post"><?php wp_nonce_field( $action ); ?><button type="submit" name="action" value="revoke">Sign out other sessions</button></form></section>
<?php require __DIR__ . '/admin-footer.php';
