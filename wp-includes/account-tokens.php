<?php
/** Purpose-bound, hashed, single-use account tokens and username derivation. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
const WP_ACCOUNT_TOKEN_LIMIT = 5;
/**
 * Creates a token and returns its plaintext (the only time it exists).
 * Issuing a token invalidates earlier unconsumed tokens for the same
 * account and purpose. Rate-limited per email + purpose per hour.
 */
function wp_account_token_create( string $purpose, string $email, ?int $user_id = null, string $role = '', int $ttl = 3600, bool $operator = false ): string|WP_Error
{
	global $wpdb;
	$email = strtolower( trim( $email ) );
	if ( ! in_array( $purpose, [ 'invite', 'reset', 'magic' ], true ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		return new WP_Error( 'token_invalid', 'The token request is not valid.' );
	}
	$token = bin2hex( random_bytes( 32 ) );
	return $wpdb->transaction( static function ( $db ) use ( $purpose, $email, $user_id, $role, $ttl, $token, $operator ): string|WP_Error {
		if ( ! $operator ) {
			$recent = (int) $db->get_row( 'SELECT COUNT(*) AS n FROM account_tokens WHERE email=:email AND purpose=:purpose AND created_at>unixepoch()-3600', [
				':email'   => $email,
				':purpose' => $purpose
			] )['n'];
			if ( $recent >= WP_ACCOUNT_TOKEN_LIMIT ) {
				return new WP_Error( 'token_rate_limited', 'Too many links were requested. Wait before requesting another.' );
			}
		}
		$db->query( 'UPDATE account_tokens SET consumed_at=unixepoch() WHERE email=:email AND purpose=:purpose AND consumed_at IS NULL', [
			':email'   => $email,
			':purpose' => $purpose
		] );
		$db->query( 'INSERT INTO account_tokens(user_id,email,purpose,token_hash,role,expires_at) VALUES(:user,:email,:purpose,:hash,:role,unixepoch()+:ttl)', [
			':user'    => $user_id,
			':email'   => $email,
			':purpose' => $purpose,
			':hash'    => hash( 'sha256', $token ),
			':role'    => $role,
			':ttl'     => $ttl,
		] );
		return $token;
	} );
}
/** Looks a token up without consuming it. A link scanner's GET can never burn a token. */
function wp_account_token_lookup( string $token, string $purpose ): ?array
{
	global $wpdb;
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		return null;
	}
	$row = $wpdb->get_row( 'SELECT * FROM account_tokens WHERE token_hash=:hash AND purpose=:purpose AND consumed_at IS NULL AND expires_at>unixepoch()', [
		':hash'    => hash( 'sha256', $token ),
		':purpose' => $purpose
	] );
	return $row ?: null;
}
/** Rechecks expiry, account status and sign-in policy inside the caller's transaction. */
function wp_account_token_consume( WP_SQLite_DB $db, int $id ): bool
{
	$db->query( "UPDATE account_tokens SET consumed_at=unixepoch() WHERE id=:id AND consumed_at IS NULL AND expires_at>unixepoch() AND (purpose='invite' OR EXISTS(SELECT 1 FROM users WHERE users.id=account_tokens.user_id AND disabled=0)) AND (purpose<>'magic' OR EXISTS(SELECT 1 FROM options WHERE option_name='magic_links_enabled' AND option_value='1'))", [ ':id' => $id ] );
	return 1 === (int) $db->get_row( 'SELECT changes() AS n' )['n'];
}
/**
 * Derives a username from an email address: the local part, normalized to
 * lowercase letters, digits, dots, underscores and hyphens, with a numeric
 * suffix when taken. The full email address is never the default.
 */
function wp_derive_username( string $email, string $desired = '' ): string
{
	global $wpdb;
	$candidate = '' !== trim( $desired ) ? trim( $desired ) : strtolower( strstr( $email, '@', true ) ?: $email );
	$candidate = preg_replace( '/[^a-z0-9._-]+/', '-', strtolower( $candidate ) ) ?? '';
	$candidate = trim( $candidate, '.-_' );
	if ( strlen( $candidate ) < 2 ) {
		$candidate = 'user';
	}
	$candidate = substr( $candidate, 0, 55 );
	$base = $candidate;
	$suffix = 2;
	while ( null !== $wpdb->get_row( 'SELECT id FROM users WHERE username=:name', [ ':name' => $candidate ] ) ) {
		$candidate = $base . '-' . $suffix;
		++$suffix;
	}
	return $candidate;
}
/**
 * Issues a token and emails its link. Returns true on delivery, or the WP_Error from issuance
 * or delivery; failures are audited, never disguised.
 */
function wp_account_send_link( string $purpose, ?int $user_id, string $email, string $role = '' ): bool|WP_Error
{
	$token = wp_account_token_create( $purpose, $email, $user_id, $role, 'magic' === $purpose ? 1800 : 3600 );
	$site = (string) get_option( 'site_title', 'WordPress Next' );
	if ( is_wp_error( $token ) ) {
		wp_audit( 'account_link_rate_limited', $user_id ?? 0, [
			'purpose' => $purpose,
			'email'   => $email
		] );
		return $token;
	}
	$action = [
		'reset'  => 'rp',
		'magic'  => 'ml',
		'invite' => 'invite'
	][ $purpose ];
	$subject = [
		'reset'  => 'Reset your password — ',
		'magic'  => 'Your sign-in link — ',
		'invite' => 'You are invited to join '
	][ $purpose ] . $site;
	$intro = [
		'reset'  => 'A password reset was requested for this account.',
		'magic'  => 'Use this link to sign in. It works once and expires soon.',
		'invite' => 'You have been invited to create an account.'
	][ $purpose ];
	$sent = wp_mail( $email, $subject, $intro . "\n\n" . wp_home_url( '/wp-admin/login.php?action=' . $action . '&token=' . $token ) . "\n\nIf you did not request this, you can ignore this message." );
	if ( is_wp_error( $sent ) ) {
		wp_audit( 'account_link_delivery_failed', $user_id ?? 0, [
			'purpose' => $purpose,
			'error'   => $sent->get_error_message()
		] );
		return $sent;
	}
	return true;
}
