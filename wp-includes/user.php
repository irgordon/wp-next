<?php
/** Expiring, revocable database sessions and finite built-in roles. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
$GLOBALS['current_user'] = null;
function wp_roles(): array
{
	return [
		'administrator' => [ 'administrator' ],
		'editor'        => [ 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'upload_media', 'manage_media' ],
		'author'        => [ 'edit_posts', 'edit_published_posts', 'publish_posts', 'upload_media' ],
		'page_editor'   => [ 'edit_pages', 'edit_published_pages', 'publish_pages', 'upload_media' ],
	];
}
function wp_get_current_user(): ?array
{
	global $current_user, $wpdb;
	if ( null !== $current_user ) {
		return $current_user;
	}
	$token = wp_input( $_COOKIE, 'wp_session_token' );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) || null === $wpdb ) {
		return null;
	}
	$row = $wpdb->get_row( 'SELECT u.id,u.username,u.display_name,u.email,u.capabilities FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=:hash AND s.expires_at>unixepoch() AND u.disabled=0', [ ':hash' => hash( 'sha256', $token ) ] );
	if ( null === $row ) {
		return null;
	}
	$row['id'] = (int) $row['id'];
	$row['capabilities'] = json_decode( $row['capabilities'], true ) ?: [];
	$current_user = $row;
	return $current_user;
}
function get_current_user_id(): int
{
	return (int) ( wp_get_current_user()['id'] ?? 0 );
}
/** Trusted CLI/domain context helper; never bound directly to submitted author IDs. */
function wp_set_current_user( int $user_id ): ?array
{
	global $wpdb, $current_user;
	$row = $wpdb->get_row( 'SELECT id,username,email,capabilities FROM users WHERE id=:id AND disabled=0', [ ':id' => $user_id ] );
	if ( null !== $row ) {
		$row['id'] = (int) $row['id'];
		$row['capabilities'] = json_decode( $row['capabilities'], true ) ?: [];
	}
	$current_user = $row;
	return $row;
}
function user_can( int $user_id, string $capability, mixed ...$args ): bool
{
	global $wpdb, $wp_auth_service;
	if ( $user_id <= 0 || null === $wpdb ) {
		return false;
	}
	if ( 'edit_post' === $capability && isset( $args[0] ) ) {
		$post = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => (int) $args[0] ] );
		return null !== $post && true === $wp_auth_service->validate_mutation_permissions( $user_id, $post, $post, $wpdb );
	}
	return $wp_auth_service->has_capability( $user_id, $capability, $wpdb );
}
function current_user_can( string $capability, mixed ...$args ): bool
{
	return user_can( get_current_user_id(), $capability, ...$args );
}
/** Reserve before password work; one fixed-window budget shared by all usernames. */
function wp_reserve_login_ip(): true|WP_Error
{
	global $wpdb;
	$address = $_SERVER['REMOTE_ADDR'] ?? 'cli';
	$packed = @inet_pton( $address );
	$key = hash( 'sha256', 'ip|' . ( false === $packed ? $address : bin2hex( $packed ) ) );
	// RETURNING reserves capacity atomically, including simultaneous requests.
	$row = $wpdb->get_row( 'INSERT INTO login_attempts(attempt_key,failures,expires_at) VALUES(:key,1,unixepoch()+60) ON CONFLICT(attempt_key) DO UPDATE SET failures=CASE WHEN expires_at<=unixepoch() THEN 1 ELSE failures+1 END,expires_at=CASE WHEN expires_at<=unixepoch() THEN unixepoch()+60 ELSE expires_at END WHERE expires_at<=unixepoch() OR failures<30 RETURNING failures', [ ':key' => $key ] );
	if ( null !== $row && 1 === (int) $row['failures'] ) {
		$wpdb->query( 'DELETE FROM login_attempts WHERE attempt_key IN (SELECT attempt_key FROM login_attempts WHERE expires_at<=unixepoch() LIMIT 100)' );
	}
	return null === $row ? new WP_Error( 'login_throttled', 'Too many login attempts from this address. Try again in one minute.', 60 ) : true;
}
function wp_authenticate( string $username, string $password ): array|WP_Error
{
	global $wpdb;
	$budget = wp_reserve_login_ip();
	if ( is_wp_error( $budget ) ) {
		return $budget;
	}
	$username = trim( $username );
	$key = hash( 'sha256', strtolower( $username ) . '|' . ( $_SERVER['REMOTE_ADDR'] ?? 'cli' ) );
	$attempt = $wpdb->get_row( 'SELECT * FROM login_attempts WHERE attempt_key=:key AND expires_at>unixepoch()', [ ':key' => $key ] );
	if ( null !== $attempt && (int) $attempt['failures'] >= 5 ) {
		return new WP_Error( 'login_throttled', 'Too many login attempts. Try again in 15 minutes.', 900 );
	}
	$user = $wpdb->get_row( 'SELECT * FROM users WHERE username=:name COLLATE NOCASE OR email=:name COLLATE NOCASE LIMIT 1', [ ':name' => $username ] );
	// Always perform password verification, including missing/disabled accounts.
	$dummy = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
	$verified = password_verify( $password, $user['password_hash'] ?? $dummy );
	if ( ! $verified || null === $user || 0 !== (int) $user['disabled'] ) {
		$wpdb->query( 'INSERT INTO login_attempts(attempt_key,failures,expires_at) VALUES(:key,1,unixepoch()+900) ON CONFLICT(attempt_key) DO UPDATE SET failures=CASE WHEN expires_at<=unixepoch() THEN 1 ELSE failures+1 END,expires_at=unixepoch()+900', [ ':key' => $key ] );
		return new WP_Error( 'invalid_credentials', 'Invalid username or password.' );
	}
	$wpdb->query( 'DELETE FROM login_attempts WHERE attempt_key=:key OR expires_at<=unixepoch()', [ ':key' => $key ] );
	return $user;
}
function wp_login_user( int $user_id ): void
{
	global $wpdb;
	$token = bin2hex( random_bytes( 32 ) );
	$expires = time() + 86400 * 14;
	$wpdb->query( 'DELETE FROM user_sessions WHERE expires_at<=unixepoch()' );
	$wpdb->query( 'INSERT INTO user_sessions(token_hash,user_id,expires_at) VALUES(:hash,:id,:expires)', [
		':hash'    => hash( 'sha256', $token ),
		':id'      => $user_id,
		':expires' => $expires
	] );
	setcookie( 'wp_session_token', $token, wp_cookie_options( $expires ) );
	$_COOKIE['wp_session_token'] = $token;
	wp_audit( 'login', $user_id, [], $user_id );
}
function wp_logout(): void
{
	global $wpdb, $current_user;
	$actor = get_current_user_id();
	$wpdb->query( 'DELETE FROM user_sessions WHERE token_hash=:hash', [ ':hash' => hash( 'sha256', wp_input( $_COOKIE, 'wp_session_token' ) ) ] );
	setcookie( 'wp_session_token', '', wp_cookie_options( time() - 3600 ) );
	unset( $_COOKIE['wp_session_token'] );
	$current_user = null;
	wp_audit( 'logout', $actor, [], $actor );
}
/** Content and working-state counts shown before an account is deleted. */
function wp_user_content_counts( WP_SQLite_DB $db, int $user_id ): array
{
	$count = static fn( string $sql ): int => (int) $db->get_row( $sql, [ ':id' => $user_id ] )['n'];
	return [
		'posts'    => $count( "SELECT COUNT(*) AS n FROM posts WHERE author_id=:id AND post_type='post'" ),
		'pages'    => $count( "SELECT COUNT(*) AS n FROM posts WHERE author_id=:id AND post_type='page'" ),
		'media'    => $count( 'SELECT COUNT(*) AS n FROM media WHERE author_id=:id' ),
		'drafts'   => $count( 'SELECT COUNT(*) AS n FROM design_revisions WHERE actor_id=:id AND published=0' ),
		'sessions' => $count( 'SELECT COUNT(*) AS n FROM user_sessions WHERE user_id=:id' ),
	];
}
/**
 * Permanently deletes an account (F1): content and media are reassigned to an
 * existing enabled account, never cascade-deleted; sessions and tokens die in
 * the same transaction; a minimal audit tombstone keeps the stable identity
 * (user id + username) and the reassignment event, with no profile or
 * contact fields. Every protection is rechecked inside the transaction.
 */
function wp_delete_user( int $user_id, int $reassign_to, int $actor_id ): array|WP_Error
{
	global $wpdb;
	try {
		return $wpdb->transaction( static function ( $db ) use ( $user_id, $reassign_to, $actor_id ): array {
			$actor = $db->get_row( 'SELECT capabilities,disabled FROM users WHERE id=:id', [ ':id' => $actor_id ] );
			$actor_caps = null !== $actor ? ( json_decode( (string) $actor['capabilities'], true ) ?: [] ) : [];
			if ( null === $actor || 0 !== (int) $actor['disabled'] || ! in_array( 'administrator', $actor_caps, true ) ) {
				throw new RuntimeException( 'Account permission revoked.' );
			}
			if ( $actor_id === $user_id ) {
				throw new RuntimeException( 'You cannot delete your own account.' );
			}
			$user = $db->get_row( 'SELECT * FROM users WHERE id=:id', [ ':id' => $user_id ] );
			$target = $db->get_row( 'SELECT id,disabled FROM users WHERE id=:id', [ ':id' => $reassign_to ] );
			if ( null === $user ) {
				throw new RuntimeException( 'Account not found.' );
			}
			if ( null === $target || 0 !== (int) $target['disabled'] || $reassign_to === $user_id ) {
				throw new RuntimeException( 'Choose an existing enabled account to receive the content.' );
			}
			$caps = json_decode( (string) $user['capabilities'], true ) ?: [];
			if ( in_array( 'administrator', $caps, true ) && 0 === (int) $user['disabled'] ) {
				$enabled_admins = (int) $db->get_row( "SELECT COUNT(*) AS n FROM users WHERE disabled=0 AND capabilities LIKE '%\"administrator\"%'" )['n'];
				if ( $enabled_admins <= 1 ) {
					throw new RuntimeException( 'The last enabled administrator cannot be deleted.' );
				}
			}
			$counts = wp_user_content_counts( $db, $user_id );
			$db->query( 'UPDATE posts SET author_id=:target WHERE author_id=:id', [
				':target' => $reassign_to,
				':id'     => $user_id
			] );
			$db->query( 'UPDATE media SET author_id=:target WHERE author_id=:id', [
				':target' => $reassign_to,
				':id'     => $user_id
			] );
			wp_invalidate_file_link_renders( $db );
			$db->query( 'DELETE FROM design_revisions WHERE actor_id=:id AND published=0', [ ':id' => $user_id ] );
			$db->query( 'DELETE FROM user_sessions WHERE user_id=:id', [ ':id' => $user_id ] );
			$db->query( 'DELETE FROM account_tokens WHERE user_id=:id OR LOWER(email)=LOWER(:email)', [
				':id'    => $user_id,
				':email' => $user['email']
			] );
			$db->query( 'DELETE FROM users WHERE id=:id', [ ':id' => $user_id ] );
			wp_audit( 'user_deleted', $user_id, [
				'username'      => (string) $user['username'],
				'reassigned_to' => $reassign_to,
				'counts'        => $counts,
			] );
			return $counts;
		} );
	} catch ( Throwable $exception ) {
		return new WP_Error( 'user_delete_failed', $exception->getMessage() );
	}
}
