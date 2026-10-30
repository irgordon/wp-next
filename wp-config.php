<?php
/** Runtime and per-install configuration. */
declare( strict_types=1 );
if ( PHP_VERSION_ID < 80400 ) {
	if ( 'cli' === PHP_SAPI ) {
		fwrite( STDERR, "WordPress Next requires PHP 8.4 or newer.\n" );
		exit( 1 );
	}
	http_response_code( 503 );
	exit( 'WordPress Next requires PHP 8.4 or newer.' );
}
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	define( 'WP_ROOT_DIR', __DIR__ );
}
foreach ( is_readable( WP_ROOT_DIR . '/.env' ) ? file( WP_ROOT_DIR . '/.env', FILE_IGNORE_NEW_LINES ) : [] as $line ) {
	$line = trim( $line );
	if ( '' === $line || str_starts_with( $line, '#' ) || ! str_contains( $line, '=' ) ) {
		continue;
	}
	[ $key, $value ] = explode( '=', $line, 2 );
	$key = trim( $key );
	if ( preg_match( '/^[A-Z][A-Z0-9_]*$/', $key ) && false === getenv( $key ) && ! isset( $_ENV[ $key ] ) ) {
		putenv( $key . '=' . trim( $value, " \t\"'" ) );
	}
}
function wp_get_env( string $key, mixed $default = null ): mixed
{
	$value = getenv( $key );
	return false === $value ? ( $_ENV[ $key ] ?? $default ) : $value;
}
define( 'WP_ENV', (string) wp_get_env( 'WP_ENV', 'production' ) );
define( 'WP_DEBUG', 'development' === WP_ENV );
define( 'WP_VAR_DIR', (string) wp_get_env( 'WP_VAR_DIR', WP_ROOT_DIR . '/var' ) );
define( 'WP_ASSETS_DIR', (string) wp_get_env( 'WP_ASSETS_DIR', WP_VAR_DIR . '/assets' ) );
define( 'WP_DB_PATH', (string) wp_get_env( 'WP_DB_PATH', WP_VAR_DIR . '/data/site.sqlite' ) );
define( 'WP_DEFAULT_THEME', (string) wp_get_env( 'WP_DEFAULT_THEME', 'default' ) );
define( 'WP_HTTPS', '1' === (string) wp_get_env( 'WP_HTTPS', '0' ) );
define( 'WP_API_BASE_PATH', (string) wp_get_env( 'WP_API_BASE_PATH', '' ) );
define( 'WP_API_EPOCH_PATH', (string) wp_get_env( 'WP_API_EPOCH_PATH', '' ) );
// First-run secret creation is atomic. There is no shared or hard-coded salt.
try {
	if ( ! is_dir( WP_VAR_DIR ) && ! mkdir( WP_VAR_DIR, 0700, true ) ) {
		throw new RuntimeException( 'Cannot create runtime directory.' );
	}
	$salt = (string) wp_get_env( 'WP_AUTH_SALT', '' );
	if ( '' === $salt ) {
		$key_path = WP_VAR_DIR . '/auth.key';
		if ( ! is_file( $key_path ) ) {
			$handle = @fopen( $key_path, 'x' );
			if ( false !== $handle ) {
				chmod( $key_path, 0600 );
				fwrite( $handle, bin2hex( random_bytes( 32 ) ) );
				fflush( $handle );
				fclose( $handle );
			}
		}
		$salt = trim( (string) @file_get_contents( $key_path ) );
	}
	if ( strlen( $salt ) < 32 ) {
		throw new RuntimeException( 'A site secret of at least 32 characters is required.' );
	}
} catch ( Throwable $error ) {
	error_log( 'WordPress Next configuration: ' . $error->getMessage() );
	http_response_code( 503 );
	exit( 'Site configuration is unavailable. Check runtime directory ownership and WP_AUTH_SALT.' );
}
define( 'WP_AUTH_SALT', $salt );
unset( $salt, $handle, $key_path );
