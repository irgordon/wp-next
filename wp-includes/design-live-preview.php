<?php
/** Request-local site rendering; this adapter never persists a design. */
declare( strict_types=1 );
const WP_DESIGN_LIVE_PREVIEW_CSP = "default-src 'none'; img-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'self'; object-src 'none'; frame-src 'none'";
class WP_Design_Preview_Exception extends RuntimeException {
}

function wp_design_live_preview_request(): bool
{
	return defined( 'WP_DESIGN_LIVE_PREVIEW' ) && WP_DESIGN_LIVE_PREVIEW;
}
function wp_design_live_preview_init(): void
{
	try {
		wp_design_preview_authorize();
		wp_design_preview_initialize();
	} catch ( WP_Design_Preview_Exception $error ) {
		wp_design_preview_failure( $error->getMessage(), $error->getCode() );
	} catch ( Throwable $error ) {
		wp_design_preview_technical_failure( $error );
	}
}
function wp_design_live_preview_response(): never
{
	try {
		$payload = wp_design_preview_payload();
		wp_design_preview_clear_output();
		wp_design_preview_reply( $payload, 200 );
	} catch ( WP_Design_Preview_Exception $error ) {
		wp_design_preview_failure( $error->getMessage(), $error->getCode() );
	} catch ( Throwable $error ) {
		wp_design_preview_technical_failure( $error );
	}
}
function wp_design_preview_initialize(): void
{
	$expected = wp_design_preview_expected_version();
	wp_design_preview_check_version( $expected );
	$config = wp_design_preview_validate( wp_design_submitted_config( $_POST, wp_design_live() ) );
	$GLOBALS['wp_design_override'] = wp_design_override( $config );
	$GLOBALS['wp_design_preview'] = $config;
	$GLOBALS['wp_live_design_preview'] = [
		'version'      => $expected,
        'page_id'      => wp_design_preview_page_id(),
		'theme'        => $config['active_theme'],
		'id'           => wp_generate_uuid4(),
		'buffer_level' => ob_get_level()
	];
	ob_start();
}
function wp_design_preview_payload(): array
{
    $context = $GLOBALS['wp_live_design_preview'];
    wp_design_preview_check_output();
    $html = wp_design_preview_capture();
    wp_design_preview_verify_render( $context );
    return [
		'ok'       => true,
		'html'     => wp_design_preview_document( $html, $context['id'] ),
		'renderId' => $context['id'],
		'version'  => $context['version'],
		'theme'    => $context['theme']
	];
}
function wp_design_preview_check_output(): void
{
    if ( '' !== (string) ob_get_contents() ) {
        throw new WP_Design_Preview_Exception( 'The theme emitted unexpected output. Your last valid preview is retained.', 422 );
    }
}
function wp_design_preview_verify_render( array $context ): void
{
    wp_design_preview_check_version( $context['version'] );
    if ( ! current_user_can( 'administrator' ) ) {
		throw new WP_Design_Preview_Exception( 'Preview access is no longer available.', 403 );
	}
    if ( wp_theme_slug() !== $context['theme'] ) {
		throw new WP_Design_Preview_Exception( 'The selected theme could not render. Your last valid preview is retained.', 422 );
	}
}
function wp_design_preview_authorize(): void
{
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'administrator' ) || ! wp_verify_nonce( wp_input( $_POST, '_wpnonce' ), 'design-live-preview' ) ) {
		throw new WP_Design_Preview_Exception( 'Reload Site design to restore private preview access. Previewing does not publish changes.', 403 );
	}
	if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 524288 ) {
		throw new WP_Design_Preview_Exception( 'The design preview request is too large.', 413 );
	}
}
function wp_design_preview_expected_version(): int
{
	$value = filter_var( wp_input( $_POST, 'expected_version' ), FILTER_VALIDATE_INT );
	if ( false === $value || $value < 0 ) {
		throw new WP_Design_Preview_Exception( 'Reload Site design before updating the preview.', 422 );
	}
	return $value;
}
/** @phpstan-impure */
function wp_design_preview_check_version( int $expected ): void
{
	if ( wp_design_version() !== $expected ) {
		throw new WP_Design_Preview_Exception( 'The published design changed elsewhere. Reload before continuing; previewing does not publish changes.', 409 );
	}
}
function wp_design_preview_validate( array $config ): array
{
	try {
		return wp_design_validate( $config );
	} catch ( PDOException $error ) {
		throw $error;
	} catch ( RuntimeException $error ) {
		throw new WP_Design_Preview_Exception( $error->getMessage(), 422, $error );
	}
}
function wp_design_preview_capture(): string
{
	$level = ob_get_level();
	ob_start();
	try {
		wp_design_preview_homepage();
		return (string) ob_get_clean();
	} catch ( Throwable $error ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		throw $error;
	}
}
function wp_design_preview_homepage(): void
{
	$selected = (int) ( $GLOBALS['wp_live_design_preview']['page_id'] ?? 0 );
	$home = $selected > 0 ? wp_design_preview_page_row( $selected ) : wp_design_preview_home_row( (int) get_option( 'homepage_id', 0 ) );
	wp_set_loop_posts( null !== $home ? [ $home ] : wp_design_preview_posts() );
	$GLOBALS['wp_current_post'] = $home;
	$GLOBALS['wp_canonical'] = wp_home_url();
	$GLOBALS['wp_archive_url'] = wp_home_url();
	$GLOBALS['wp_page_number'] = 1;
	$GLOBALS['wp_total_pages'] = null !== $home ? 1 : wp_design_preview_total_pages();
	wp_template( null !== $home ? 'page' : 'archive' );
}
function wp_design_preview_document( string $html, string $id ): string
{
	if ( strlen( $html ) > 2097152 ) {
		throw new WP_Design_Preview_Exception( 'The rendered preview is too large. Choose a smaller homepage.', 422 );
	}
	$document = wp_design_preview_parse_html( $html );
	wp_design_preview_remove_active_content( $document );
	wp_design_preview_insert_head( $document, $id );
	return (string) $document->saveHTML();
}
function wp_design_preview_page_id(): int
{
    $id = filter_var( wp_input( $_POST, 'preview_page', '0' ), FILTER_VALIDATE_INT );
    if ( false === $id || $id < 0 ) {
		throw new WP_Design_Preview_Exception( 'Choose an available Page.', 422 );
	}
    if ( $id > 0 ) {
		wp_design_preview_page_row( $id );
	}
    return $id;
}
function wp_design_preview_page_row( int $id ): array
{
    global $wpdb;
    $row = $wpdb->get_row( "SELECT * FROM posts WHERE id=:id AND post_type='page' AND status<>'trashed'", [ ':id' => $id ] );
    if ( null === $row || ! current_user_can( 'edit_post', $id ) ) {
		throw new WP_Design_Preview_Exception( 'This Page is unavailable for preview.', 404 );
	}
    return $row;
}
function wp_design_preview_home_row( int $id ): ?array
{
	global $wpdb;
	if ( $id <= 0 ) {
		return null;
	}
	$row = $wpdb->get_row( "SELECT * FROM posts WHERE id=:id AND post_type='page' AND status='published'", [ ':id' => $id ] );
	if ( null === $row ) {
		throw new WP_Design_Preview_Exception( 'The selected homepage is no longer available.', 422 );
	}
	return $row;
}
function wp_design_preview_posts(): array
{
	global $wpdb;
	return $wpdb->get_results( "SELECT * FROM posts WHERE post_type='post' AND status='published' ORDER BY published_at DESC,id DESC LIMIT 10" );
}
function wp_design_preview_total_pages(): int
{
	global $wpdb;
	$total = (int) $wpdb->get_row( "SELECT COUNT(*) AS n FROM posts WHERE post_type='post' AND status='published'" )['n'];
	return max( 1, (int) ceil( $total / 10 ) );
}
function wp_design_preview_parse_html( string $html ): DOMDocument
{
	$document = new DOMDocument( '1.0', 'UTF-8' );
	$previous = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	return $document;
}
function wp_design_preview_remove_active_content( DOMDocument $document ): void
{
	$xpath = new DOMXPath( $document );
	$nodes = $xpath->query( '//script|//iframe|//object|//embed|//base|//meta[@http-equiv]|//processing-instruction()' );
	foreach ( false !== $nodes ? iterator_to_array( $nodes ) : [] as $node ) {
		$node->parentNode?->removeChild( $node );
	}
	foreach ( $xpath->query( '//@*' ) ?: [] as $attribute ) {
		if ( str_starts_with( strtolower( $attribute->nodeName ), 'on' ) && $attribute instanceof DOMAttr && $attribute->ownerElement instanceof DOMElement ) {
			$attribute->ownerElement->removeAttribute( $attribute->nodeName );
		}
	}
}
function wp_design_preview_insert_head( DOMDocument $document, string $id ): void
{
    $head = wp_design_preview_head( $document );
    wp_design_preview_prepend_base( $document, $head );
    wp_design_preview_prepend_meta( $document, $head, 'name', 'wp-design-preview-id', $id );
    wp_design_preview_prepend_meta( $document, $head, 'name', 'robots', 'noindex,nofollow' );
    wp_design_preview_prepend_meta( $document, $head, 'http-equiv', 'Content-Security-Policy', WP_DESIGN_LIVE_PREVIEW_CSP );
}
function wp_design_preview_head( DOMDocument $document ): DOMElement
{
    $head = $document->getElementsByTagName( 'head' )->item( 0 );
    if ( ! $head instanceof DOMElement ) {
		throw new RuntimeException( 'Preview document has no head.' );
	}
    return $head;
}
function wp_design_preview_prepend_base( DOMDocument $document, DOMElement $head ): void
{
    $base = $document->createElement( 'base' );
    $base->setAttribute( 'href', wp_home_url( '/' ) );
    $head->insertBefore( $base, $head->firstChild );
}
function wp_design_preview_prepend_meta( DOMDocument $document, DOMElement $head, string $attribute, string $name, string $content ): void
{
    $meta = $document->createElement( 'meta' );
    $meta->setAttribute( $attribute, $name );
    $meta->setAttribute( 'content', $content );
    $head->insertBefore( $meta, $head->firstChild );
}
function wp_design_preview_technical_failure( Throwable $error ): never
{
	error_log( 'Live design preview: ' . $error->getMessage() );
	wp_design_preview_failure( 'The private preview is temporarily unavailable. Previewing does not publish changes.', 500 );
}
function wp_design_preview_failure( string $message, int $status ): never
{
	wp_design_preview_clear_output();
	wp_design_preview_reply( [
		'ok'    => false,
		'error' => $message
	], $status );
}
function wp_design_preview_clear_output(): void
{
	$level = (int) ( $GLOBALS['wp_live_design_preview']['buffer_level'] ?? ob_get_level() );
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
}
function wp_design_preview_reply( array $payload, int $status ): never
{
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'Vary: Cookie' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo wp_json_encode( $payload );
	exit;
}
