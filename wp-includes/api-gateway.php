<?php
/** Pre-bootstrap boundary for the editorial API mount: route detection, the trusted-HTTPS decision and problem-JSON refusals that must not create a database, secret or session. Kept free of application includes on purpose; wp-config.php is only loaded for requests that pass this gate. */
declare( strict_types=1 );
function wp_api_gateway_env( string $key, string $default = '' ): string
{
	$value = getenv( $key );
	if ( false !== $value ) {
		return (string) $value;
	}
	if ( isset( $_ENV[ $key ] ) ) {
		return (string) $_ENV[ $key ];
	}
	static $file_env = null;
	if ( null === $file_env ) {
		$file_env = [];
		$path = dirname( __DIR__ ) . '/.env';
		foreach ( is_readable( $path ) ? (array) file( $path, FILE_IGNORE_NEW_LINES ) : [] as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
				continue;
			}
			[ $name, $entry ] = explode( '=', $line, 2 );
			$name = trim( $name );
			if ( preg_match( '/^[A-Z][A-Z0-9_]*$/', $name ) ) {
				$file_env[ $name ] = trim( $entry, " \t\"'" );
			}
		}
	}
	return $file_env[ $key ] ?? $default;
}

/** Operator mount prefix: empty at the host root, otherwise a normalized leading-slash path without a trailing slash. Anything else disables the mount (fail closed). */
function wp_api_gateway_base_path(): ?string
{
	$base = wp_api_gateway_env( 'WP_API_BASE_PATH', '' );
	if ( '' === $base ) {
		return '';
	}
	if ( ! preg_match( '#^/[A-Za-z0-9._~/-]*[A-Za-z0-9._~-]$#D', $base ) || str_contains( $base, '//' ) || str_contains( $base, '..' ) ) {
		return null;
	}
	return $base;
}

function wp_api_gateway_uuid(): string
{
	$bytes = random_bytes( 16 );
	$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
	$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
}

/** Emit an RFC 9457 problem response and stop. Used before bootstrap exists and for bootstrap refusals on API requests. */
function wp_api_problem_exit( int $status, string $code, string $title, ?string $detail = null, array $extra_headers = [] ): never
{
	$request_id = $GLOBALS['wp_api_request_id'] ?? wp_api_gateway_uuid();
	$GLOBALS['wp_api_request_id'] = $request_id;
	$body = [
		'type'       => 'about:blank',
		'title'      => $title,
		'status'     => $status,
		'code'       => $code,
		'request_id' => $request_id
	];
	if ( null !== $detail && '' !== $detail ) {
		$body['detail'] = $detail;
	}
	if ( ! headers_sent() ) {
		http_response_code( $status );
		header( 'Content-Type: application/problem+json' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Request-Id: ' . $request_id );
		foreach ( $extra_headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}
	echo json_encode( $body );
	exit;
}

/** The shared trusted-HTTPS decision: actual server TLS state or the operator WP_HTTPS assertion. Forwarding headers never count. */
function wp_api_gateway_https_accepted(): bool
{
	return '1' === wp_api_gateway_env( 'WP_HTTPS', '0' )
	|| 'on' === ( $_SERVER['HTTPS'] ?? '' )
	|| '443' === (string) ( $_SERVER['SERVER_PORT'] ?? '' );
}

/**
 * Detect an API request from the raw request target. Returns null outside the
 * mount; inside the mount returns the route segments after /wp-next/v1.
 * Matching is on the full segment boundary under the trusted base path only.
 */
function wp_api_gateway_detect(): ?array
{
	$base = wp_api_gateway_base_path();
	if ( null === $base ) {
		return null;
	}
	$raw = explode( '?', (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), 2 )[0];
	if ( ! str_starts_with( $raw, '/' ) || strlen( $raw ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\#]|%(?![a-f0-9]{2})|%(?:2f|5c)/i', $raw ) ) {
		return null;
	}
	$decoded = rawurldecode( $raw );
	if ( ! preg_match( '//u', $decoded ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) ) {
		return null;
	}
	$segments = [];
	foreach ( explode( '/', $decoded ) as $part ) {
		if ( '..' === $part ) {
			return null;
		}
		if ( '' !== $part && '.' !== $part ) {
			$segments[] = $part;
		}
	}
	$mount = '' === $base ? [] : explode( '/', trim( $base, '/' ) );
	$mount[] = 'wp-next';
	$mount[] = 'v1';
	if ( array_slice( $segments, 0, count( $mount ) ) !== $mount ) {
		return null;
	}
	return array_slice( $segments, count( $mount ) );
}

/** The maintenance barrier flag check, readable before the admission gate exists. */
function wp_api_gateway_in_maintenance(): bool
{
	$var_dir = wp_api_gateway_env( 'WP_VAR_DIR', dirname( __DIR__ ) . '/var' );
	return is_file( rtrim( $var_dir, '/' ) . '/maintenance.json' );
}
