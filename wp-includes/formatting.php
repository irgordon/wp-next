<?php
declare( strict_types=1 );
function esc_html( string|int|float|null $text ): string
{
	if ( null === $text || '' === $text ) {
		return '';
	}
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function esc_attr( string|int|float|null $text ): string
{
	if ( null === $text || '' === $text ) {
		return '';
	}
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function esc_url( ?string $url ): string
{
	if ( null === $url || '' === trim( $url ) ) {
		return '';
	}
	$url = trim( $url );
	if ( preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
		return '';
	}
	if ( preg_match( '/^(javascript|data|vbscript):/i', $url ) ) {
		return '';
	}
	return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function esc_textarea( ?string $text ): string
{
	if ( null === $text || '' === $text ) {
		return '';
	}
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function sanitize_text_field( ?string $str ): string
{
	if ( null === $str || '' === $str ) {
		return '';
	}
	$filtered = strip_tags( (string) $str );
	$filtered = preg_replace( '/[\r\n\t]+/', ' ', $filtered );
	$filtered = preg_replace( '/\s+/', ' ', (string) $filtered );
	return trim( (string) $filtered );
}
function wp_unslash( mixed $value ): mixed
{
	if ( is_string( $value ) ) {
		return stripslashes( $value );
	}
	if ( is_array( $value ) ) {
		$unslashed = [];
		foreach ( $value as $key => $sub_value ) {
			$unslashed[ $key ] = wp_unslash( $sub_value );
		}
		return $unslashed;
	}
	return $value;
}
function wp_json_encode( mixed $data, int $options = 0 ): string
{
	return json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | $options );
}
function wp_generate_uuid4(): string
{
	$data = random_bytes( 16 );
	$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
	$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
}
function wp_validate_redirect( ?string $location, string $fallback_url = 'index.php' ): string
{
	$location = trim( $location ?? '' );
	if ( '' === $location || str_contains( $location, '\\' ) || preg_match( '/[\x00-\x20\x7F]/', $location ) || str_starts_with( $location, '//' ) ) {
		return $fallback_url;
	}
	$parts = parse_url( $location );
	if ( false === $parts ) {
		return $fallback_url;
	}
	if ( ! isset( $parts['scheme'], $parts['host'] ) && ( isset( $parts['scheme'] ) || isset( $parts['host'] ) ) ) {
		return $fallback_url;
	}
	if ( ! isset( $parts['scheme'] ) ) {
		return $location;
	}
	$scheme = strtolower( $parts['scheme'] );
	$current = parse_url( ( wp_is_https() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) );
	if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ( wp_is_https() ? 'https' : 'http' ) !== $scheme || false === $current || empty( $current['host'] ) || strtolower( $parts['host'] ) !== strtolower( $current['host'] ) || ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) ) !== ( $current['port'] ?? ( wp_is_https() ? 443 : 80 ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return $fallback_url;
	}
	return $location;
}
function wp_safe_redirect( string $location, int $status = 302 ): never
{
	$safe_location = wp_validate_redirect( $location, 'index.php' );
	header( 'Location: ' . $safe_location, true, $status );
	exit;
}

function wp_die( string|WP_Error $message = '', string $title = '', array $args = [] ): never
{
	$response_code = (int) ( $args['response'] ?? ( http_response_code() ?: 500 ) );
	if ( $response_code < 400 ) {
		$response_code = 500;
	}
	http_response_code( $response_code );
	$error_text = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;
	$page_title = '' !== $title ? $title : __( 'WordPress Error' );
	if ( 'cli' === php_sapi_name() ) {
		fwrite( STDERR, "Error [{$response_code}]: {$error_text}\n" );
		exit( 1 );
	}
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" /><title>' . esc_html( $page_title ) . '</title></head><body><main class="error-box" role="main"><h1>' . esc_html( $page_title ) . '</h1><p>' . esc_html( $error_text ) . '</p></main></body></html>';
	exit( 1 );
}

/** Reads a scalar form field without trusting PHP array coercions. */
function wp_input( array $input, string $key, string $default = '' ): string
{
	return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : $default;
}
/** Validates storage data. Escaping belongs only at the output boundary. */
function wp_validate_site_url( string $url ): string|WP_Error
{
	$url = rtrim( trim( $url ), '/' );
	$parts = parse_url( $url );
	if ( false === $parts || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) || empty( $parts['host'] ) || ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
		return new WP_Error( 'invalid_site_url', 'Enter an absolute HTTP or HTTPS site URL without credentials, a query, or a fragment.' );
	}
	return $url;
}
/** Logs internal details while returning a safe, stable user message. */
function wp_failure( string $code, Throwable $error, string $message = 'The operation could not complete. Please try again.' ): WP_Error
{
	error_log( $code . ': ' . $error->getMessage() );
	return new WP_Error( $code, $message );
}

function wp_is_https(): bool
{
	return ( defined( 'WP_HTTPS' ) && WP_HTTPS ) || 'on' === ( $_SERVER['HTTPS'] ?? '' ) || '443' === (string) ( $_SERVER['SERVER_PORT'] ?? '' );
}

/** Parse an origin-form request path without treating // as a remote host. */
function wp_normalize_request_path( string $target ): string|WP_Error
{
	$raw = explode( '?', $target, 2 )[0];
	if ( ! str_starts_with( $raw, '/' ) || strlen( $raw ) > 8192 || preg_match( '/[\x00-\x20\x7f\\\\#]|%(?![a-f0-9]{2})|%(?:2f|5c)/i', $raw ) ) {
		return new WP_Error( 'invalid_path', 'Invalid request path.' );
	}
	$decoded = rawurldecode( $raw );
	if ( ! preg_match( '//u', $decoded ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) ) {
		return new WP_Error( 'invalid_path', 'Invalid request path.' );
	}
	$segments = [];
	foreach ( explode( '/', $decoded ) as $part ) {
		if ( '..' === $part ) {
			return new WP_Error( 'invalid_path', 'Parent path segments are not accepted.' );
		}
		if ( '' !== $part && '.' !== $part ) {
			$segments[] = $part;
		}
	}
	$path = '/' . implode( '/', $segments );
	return $path . ( '/' !== $path && str_ends_with( $decoded, '/' ) ? '/' : '' );
}
