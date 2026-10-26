<?php
/** Request boundaries, CSRF tokens, cookies, and deployment headers. */
declare( strict_types=1 );
function wp_security_headers(): void
{
	if ( 'cli' === PHP_SAPI ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: DENY' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( "Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'" );
	if ( wp_is_https() ) {
		header( 'Strict-Transport-Security: max-age=31536000' );
	}
	if ( 'production' === WP_ENV && ! wp_is_https() ) {
		http_response_code( 503 );
		exit( 'HTTPS is required. Configure TLS and the trusted server HTTPS parameter. Use WP_ENV=development only for local HTTP testing.' );
	}
}
function wp_cookie_options( int $expires ): array
{
	return [
		'expires'  => $expires,
		'path'     => '/',
		'secure'   => wp_is_https() || 'production' === WP_ENV,
		'httponly' => true,
		'samesite' => 'Lax'
	];
}
function wp_nonce( string $action, ?int $tick = null ): string
{
	$token = wp_input( $_COOKIE, 'wp_session_token' );
	if ( '' === $token ) {
		$token = wp_input( $_COOKIE, 'wp_csrf' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$token = bin2hex( random_bytes( 32 ) );
			$_COOKIE['wp_csrf'] = $token;
			setcookie( 'wp_csrf', $token, wp_cookie_options( time() + 3600 ) );
		}
	}
	$tick ??= (int) floor( time() / 900 );
	return hash_hmac( 'sha256', $action . '|' . $token . '|' . $tick, wp_auth_salt() );
}
function wp_nonce_field( string $action ): void
{
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_nonce( $action ) ) . '" />';
}
function wp_verify_nonce( string $nonce, string $action ): bool
{
	if ( '' === $nonce ) {
		return false;
	}
	$tick = (int) floor( time() / 900 );
	return hash_equals( wp_nonce( $action, $tick ), $nonce ) || hash_equals( wp_nonce( $action, $tick - 1 ), $nonce );
}
function wp_require_post( string $action ): void
{
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( 'This action requires a form submission.', 'Method not allowed', [ 'response' => 405 ] );
	}
	if ( ! wp_verify_nonce( wp_input( $_POST, '_wpnonce' ), $action ) ) {
		wp_die( 'The form expired or could not be verified. Reload it and try again.', 'Request rejected', [ 'response' => 403 ] );
	}
}
function wp_audit( string $action, ?int $subject = null, array $payload = [], int $actor = 0, ?string $uuid = null ): void
{
	global $wpdb;
	$wpdb->query( 'INSERT OR IGNORE INTO audit_log(actor_id,action,subject_id,payload,event_uuid) VALUES(:actor,:action,:subject,:payload,:uuid)', [
		':actor'   => $actor ?: get_current_user_id(),
		':action'  => $action,
		':subject' => $subject,
		':payload' => wp_json_encode( $payload ),
		':uuid'    => $uuid
	] );
}

function wp_auth_salt(): string
{
	if ( ! defined( 'WP_AUTH_SALT' ) ) {
		throw new RuntimeException( 'The site secret is not configured.' );
	}
	$value = constant( 'WP_AUTH_SALT' );
	if ( ! is_string( $value ) || strlen( $value ) < 32 ) {
		throw new RuntimeException( 'The site secret is invalid.' );
	}
	return $value;
}
