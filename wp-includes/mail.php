<?php
/** Outbound mail: SMTP when configured, PHP mail() otherwise. Delivery results are recorded, never assumed. */
declare( strict_types=1 );
function wp_mail_config(): array
{
	$get = static fn( string $env, string $option, string $default = '' ): string => (string) ( wp_get_env( $env ) ?: get_option( $option, $default ) );
	return [
		'host'       => $get( 'WP_MAIL_SMTP_HOST', 'mail_smtp_host' ),
		'port'       => (int) $get( 'WP_MAIL_SMTP_PORT', 'mail_smtp_port', '587' ),
		'encryption' => $get( 'WP_MAIL_SMTP_ENCRYPTION', 'mail_smtp_encryption', 'tls' ),
		'username'   => $get( 'WP_MAIL_SMTP_USERNAME', 'mail_smtp_username' ),
		'password'   => (string) ( wp_get_env( 'WP_MAIL_SMTP_PASSWORD' ) ?: get_option( 'mail_smtp_password', '' ) ),
		'from_email' => $get( 'WP_MAIL_FROM', 'mail_from_email', (string) get_option( 'admin_email', '' ) ),
		'from_name'  => $get( 'WP_MAIL_FROM_NAME', 'mail_from_name', (string) get_option( 'site_title', 'WordPress Next' ) ),
	];
}
function wp_mail_record( bool $ok, string $detail ): void
{
	global $wpdb;
	if ( null === $wpdb ) {
		return;
	}
	try {
		$wpdb->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
			':key'   => 'mail_last_status',
			':value' => wp_json_encode( [
				'ok'     => $ok,
				'detail' => $detail,
				'at'     => time()
			] ),
		] );
	} catch ( Throwable ) {
	}
}
/** Reads one SMTP response, following multiline replies. Returns [code, text]. */
function wp_smtp_read( $socket ): array
{
	$text = '';
	while ( false !== ( $line = fgets( $socket, 515 ) ) ) {
		$text .= $line;
		if ( strlen( $line ) < 4 || ' ' === $line[3] ) {
			break;
		}
	}
	return [ (int) substr( $text, 0, 3 ), trim( $text ) ];
}
function wp_smtp_expect( $socket, array $codes, string $stage ): array
{
	[ $code, $text ] = wp_smtp_read( $socket );
	if ( ! in_array( $code, $codes, true ) ) {
		throw new RuntimeException( "SMTP $stage failed: $text" );
	}
	return [ $code, $text ];
}
function wp_mail_smtp( array $config, string $to, string $subject, string $body ): bool
{
	$host = $config['host'];
	$port = $config['port'] > 0 ? $config['port'] : 587;
	$remote = ( 'ssl' === $config['encryption'] ? 'ssl://' : 'tcp://' ) . $host . ':' . $port;
	$socket = @stream_socket_client( $remote, $errno, $errstr, 10 );
	if ( false === $socket ) {
		throw new RuntimeException( "SMTP connection failed: $errstr" );
	}
	stream_set_timeout( $socket, 10 );
	try {
		wp_smtp_expect( $socket, [ 220 ], 'greeting' );
		$hello = 'EHLO ' . ( parse_url( (string) get_option( 'site_url', 'http://localhost' ), PHP_URL_HOST ) ?: 'localhost' );
		fwrite( $socket, $hello . "\r\n" );
		wp_smtp_expect( $socket, [ 250 ], 'EHLO' );
		if ( 'tls' === $config['encryption'] ) {
			fwrite( $socket, "STARTTLS\r\n" );
			wp_smtp_expect( $socket, [ 220 ], 'STARTTLS' );
			if ( true !== @stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				throw new RuntimeException( 'SMTP STARTTLS negotiation failed.' );
			}
			fwrite( $socket, $hello . "\r\n" );
			wp_smtp_expect( $socket, [ 250 ], 'EHLO after STARTTLS' );
		}
		if ( '' !== $config['username'] ) {
			fwrite( $socket, "AUTH LOGIN\r\n" );
			wp_smtp_expect( $socket, [ 334 ], 'AUTH' );
			fwrite( $socket, base64_encode( $config['username'] ) . "\r\n" );
			wp_smtp_expect( $socket, [ 334 ], 'AUTH username' );
			fwrite( $socket, base64_encode( $config['password'] ) . "\r\n" );
			wp_smtp_expect( $socket, [ 235 ], 'AUTH password' );
		}
		$from = $config['from_email'];
		fwrite( $socket, "MAIL FROM:<$from>\r\n" );
		wp_smtp_expect( $socket, [ 250 ], 'MAIL FROM' );
		fwrite( $socket, "RCPT TO:<$to>\r\n" );
		wp_smtp_expect( $socket, [ 250, 251 ], 'RCPT TO' );
		fwrite( $socket, "DATA\r\n" );
		wp_smtp_expect( $socket, [ 354 ], 'DATA' );
		$headers = 'From: ' . str_replace( [ "\r", "\n" ], '', $config['from_name'] ) . " <$from>\r\nTo: <$to>\r\nSubject: " . str_replace( [ "\r", "\n" ], '', $subject ) . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n";
		$payload = preg_replace( '/^\./m', '..', str_replace( [ "\r\n", "\r" ], "\n", $body ) );
		fwrite( $socket, $headers . str_replace( "\n", "\r\n", (string) $payload ) . "\r\n.\r\n" );
		wp_smtp_expect( $socket, [ 250 ], 'message' );
		fwrite( $socket, "QUIT\r\n" );
	} finally {
		fclose( $socket );
	}
	return true;
}
/**
 * Sends one plain-text message. Returns true only when the transport accepted
 * it; failures come back as WP_Error and are recorded for operators.
 */
function wp_mail( string $to, string $subject, string $body ): bool|WP_Error
{
	if ( ! filter_var( $to, FILTER_VALIDATE_EMAIL ) ) {
		return new WP_Error( 'mail_address', 'The recipient address is not valid.' );
	}
	$config = wp_mail_config();
	try {
		if ( '' !== $config['host'] ) {
			wp_mail_smtp( $config, $to, $subject, $body );
		} else {
			$headers = 'From: ' . str_replace( [ "\r", "\n" ], '', $config['from_name'] ) . ' <' . $config['from_email'] . '>';
			if ( true !== @mail( $to, $subject, $body, $headers ) ) {
				throw new RuntimeException( 'The PHP mail transport refused the message.' );
			}
		}
	} catch ( Throwable $exception ) {
		wp_mail_record( false, $exception->getMessage() );
		return new WP_Error( 'mail_failed', $exception->getMessage() );
	}
	wp_mail_record( true, 'Accepted by the mail transport.' );
	return true;
}
