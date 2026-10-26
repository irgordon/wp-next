<?php
/** Approved public CSS/text assets, edited as versioned database-backed files. */
declare( strict_types=1 );
function wp_managed_files(): array
{
	return [
		'custom.css'      => 'Custom stylesheet',
		'site-notice.txt' => 'Public site notice'
	]; 
}
function wp_managed_file_version( string $file ): int
{
    if ( ! isset( wp_managed_files()[ $file ] ) ) {
		throw new RuntimeException( 'File is not editable.' ); 
	}
    return (int) get_option( 'managed_file:' . $file, 0 );
}
function wp_managed_file_content( string $file ): string
{
    global $wpdb;
	$id = wp_managed_file_version( $file );
    $row = $wpdb->get_row( 'SELECT content FROM managed_file_versions WHERE id=:id AND filename=:file', [
		':id'   => $id,
		':file' => $file
	] );
	return $row['content'] ?? '';
}
function wp_managed_file_validate( string $file, string $text ): string
{
    if ( ! isset( wp_managed_files()[ $file ] ) || strlen( $text ) > 131072 || str_contains( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
		throw new RuntimeException( 'Choose an approved UTF-8 file, at most 128 KB.' ); 
	}
    if ( 'custom.css' !== $file ) {
		return $text; 
	}
    $clean = preg_replace( '~/\*.*?\*/~s', '', $text );
    if ( null === $clean || preg_match( '/[\\\\@<>]|\/\*|\*\//', $clean ) ) {
		throw new RuntimeException( 'Stylesheets cannot contain imports, escapes, HTML or unfinished comments.' ); 
	}
    $remaining = preg_replace_callback( '/([^{}]+)\{([^{}]*)\}/', static function ( array $match ): string {
        foreach ( explode( ',', trim( $match[1] ) ) as $selector ) {
			if ( ! preg_match( '/^(body|\.entry-title|\.entry-content|\.site-header|\.site-footer|\.site-navigation|\.shared-section)(:(hover|focus-visible))?$/D', trim( $selector ) ) ) {
				throw new RuntimeException( 'Use an approved public-site selector.' ); 
			} 
		}
        foreach ( explode( ';', $match[2] ) as $declaration ) {
            if ( '' === trim( $declaration ) ) {
				continue; 
			}
            $parts = explode( ':', $declaration, 2 );
            if ( 2 !== count( $parts ) ) {
				throw new RuntimeException( 'Styles require property: value declarations.' ); 
			}
            $property = trim( $parts[0] );
			$value = trim( $parts[1] );
            if ( in_array( $property, [ 'color', 'background-color' ], true ) ) {
				$ok = 1 === preg_match( '/^(#[a-f0-9]{3}|#[a-f0-9]{6}|transparent|inherit)$/iD', $value ); 
			} elseif ( in_array( $property, [ 'font-size', 'line-height', 'letter-spacing', 'margin', 'padding', 'margin-top', 'margin-bottom', 'padding-top', 'padding-bottom' ], true ) ) {
				$ok = 1 === preg_match( '/^(0|[0-9]{1,3}(\.[0-9]{1,2})?(px|rem|em|%))([ ]+(0|[0-9]{1,3}(\.[0-9]{1,2})?(px|rem|em|%))){0,3}$/D', $value ); 
			} elseif ( 'font-weight' === $property ) {
				$ok = 1 === preg_match( '/^[1-9]00$/D', $value ); 
			} elseif ( 'text-align' === $property ) {
				$ok = in_array( $value, [ 'left', 'right', 'center', 'start', 'end' ], true ); 
			} else {
				$ok = false; 
			}
            if ( ! $ok ) {
				throw new RuntimeException( 'Unsupported stylesheet property or value.' ); 
			}
        }
        return '';
    }, $clean );
    if ( null === $remaining || '' !== trim( $remaining ) ) {
		throw new RuntimeException( 'Use selector { property: value; } rules.' ); 
	}
    return $text;
}
function wp_managed_file_publish( string $file, string $text, int $expected ): int
{
    global $wpdb;
	$text = wp_managed_file_validate( $file, $text );
    return $wpdb->transaction( static function ( $db ) use ( $file, $text, $expected ): int {
        if ( ! current_user_can( 'administrator' ) || wp_managed_file_version( $file ) !== $expected ) {
			throw new RuntimeException( 'File changed or permission was revoked. Reload before publishing.' ); 
		}
        $db->query( 'INSERT INTO managed_file_versions(filename,content,actor_id) VALUES(:file,:content,:actor)', [
			':file'    => $file,
			':content' => $text,
			':actor'   => get_current_user_id()
		] );
        $id = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
        $db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:id) ON CONFLICT(option_name) DO UPDATE SET option_value=:id', [
			':key' => 'managed_file:' . $file,
			':id'  => (string) $id
		] );
		wp_audit( 'managed_file_published', $id );
		return $id;
    } );
}
function wp_managed_file_serve(): void
{
    $path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    $prefix = rtrim( (string)parse_url(wp_home_url(),PHP_URL_PATH),'/');
	if ( '' !== $prefix && str_starts_with($path,$prefix.'/') ) {
		$path = substr($path,strlen($prefix));
	}
    if ( ! preg_match( '~^/site-assets/([^/]+)$~D', $path, $match ) ) {
		return; 
	}
    if ( ! isset( wp_managed_files()[ $match[1] ] ) ) {
		return; 
	}
    $content = wp_managed_file_content( $match[1] );
    if ( 'custom.css' === $match[1] && isset( $_GET['css_preview'] ) ) {
        $draft = wp_managed_file_draft( 'custom.css' );
        if ( ! current_user_can( 'administrator' ) || ! wp_verify_nonce( wp_input( $_GET, '_wpnonce' ), 'css-preview' ) || null === $draft ) {
            http_response_code( 404 );
            exit;
        }
        $content = (string) $draft['content'];
    }
    header( 'Content-Type: ' . ( 'custom.css' === $match[1] ? 'text/css' : 'text/plain' ) . '; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'X-Content-Type-Options: nosniff' );
    if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		echo $content;

	} exit;
}
/** The saved stylesheet draft, if any. Drafts are private working state, never live output. */
function wp_managed_file_draft( string $file ): ?array
{
	if ( 'custom.css' !== $file ) {
		return null;
	}
	$draft = json_decode( (string) get_option( 'file_draft:' . $file, '' ), true );
	return is_array( $draft ) && isset( $draft['content'] ) ? $draft : null;
}
function wp_managed_file_save_draft( string $file, string $text ): void
{
	global $wpdb;
	if ( ! current_user_can( 'administrator' ) ) {
		throw new RuntimeException( 'Administrator access is required.' );
	}
	$text = wp_managed_file_validate( $file, $text );
	$wpdb->transaction( static function ( $db ) use ( $file, $text ): void {
		$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
			':key'   => 'file_draft:' . $file,
			':value' => wp_json_encode( [
				'content'    => $text,
				'actor_id'   => get_current_user_id(),
				'updated_at' => time()
			] ),
		] );
		wp_audit( 'managed_file_draft_saved', 0 );
	} );
}
function wp_managed_file_discard_draft( string $file ): void
{
	global $wpdb;
	if ( ! current_user_can( 'administrator' ) ) {
		throw new RuntimeException( 'Administrator access is required.' );
	}
	$wpdb->query( "DELETE FROM options WHERE option_name=:key", [ ':key' => 'file_draft:' . $file ] );
	wp_audit( 'managed_file_draft_discarded', 0 );
}
/** Publishes the saved draft through the normal version-checked path, then clears it. A conflict keeps the draft. */
function wp_managed_file_publish_draft( string $file, int $expected ): int
{
	$draft = wp_managed_file_draft( $file );
	if ( null === $draft ) {
		throw new RuntimeException( 'There is no saved draft to publish.' );
	}
	$id = wp_managed_file_publish( $file, (string) $draft['content'], $expected );
	wp_managed_file_discard_draft( $file );
	return $id;
}
/**
 * Private draft preview: an administrator session plus an action-bound nonce sees
 * the draft stylesheet on the public site. Anonymous output never changes.
 */
function wp_css_preview_init(): void
{
	if ( ! isset( $_GET['css_preview'] ) ) {
		return;
	}
	if ( ! current_user_can( 'administrator' ) || ! wp_verify_nonce( wp_input( $_GET, '_wpnonce' ), 'css-preview' ) || null === wp_managed_file_draft( 'custom.css' ) ) {
		wp_die( 'Preview not found.', 'Not found', [ 'response' => 404 ] );
	}
	$GLOBALS['wp_css_preview'] = true;
	header( 'Cache-Control: private, no-store' );
	header( 'X-Robots-Tag: noindex, nofollow' );
}
function wp_css_preview_active(): bool
{
	return ! empty( $GLOBALS['wp_css_preview'] );
}
