<?php
/** Import reference policy and its in-memory HTML adapters. No remote reads. */
declare( strict_types=1 );
const WP_PORTABILITY_IMPORT_RECEIPT = '_wpnext_import';
const WP_PORTABILITY_SOCIAL_FALLBACK_NOTE = 'The social image did not import as a ready image; the featured-image fallback will be used.';

function wp_portability_remap_legacy( string $html, array $targets ): array
{
	$fragment = wp_portability_legacy_fragment( $html );
	$plan = wp_portability_legacy_plan( $fragment->references, $targets );
	$dropped = wp_portability_legacy_missing( $plan );
	return wp_portability_legacy_result( $fragment->document, $plan, $dropped );
}
function wp_portability_legacy_fragment( string $html ): stdClass
{
	$document = wp_portability_parse_html( $html );
	return (object) [
		'document'   => $document,
		'references' => wp_portability_html_references( $document )
	];
}
function wp_portability_legacy_plan( array $references, array $targets ): array
{
	$lookup = wp_portability_reference_lookup( $targets );
	$plan = [];
	foreach ( $references as $reference ) {
		$target = wp_portability_lookup_reference( $reference, $lookup );
		if ( null !== $target ) {
			$plan[] = [
				'reference' => $reference,
				'target'    => $target
			];
		}
	}
	return $plan;
}
function wp_portability_legacy_missing( array $plan ): array
{
	$dropped = [];
	foreach ( $plan as $change ) {
		if ( 0 === $change['target']['id'] ) {
			$dropped[] = $change['target']['source_id'];
		}
	}
	return array_values( array_unique( $dropped ) );
}
function wp_portability_legacy_result( DOMDocument $document, array $plan, array $dropped ): array
{
	wp_portability_apply_html_plan( $plan );
	return [ wp_portability_html_body( $document ), $dropped ];
}
function wp_portability_import_metadata( mixed $metadata, array $targets, string $key, int $source ): array
{
	$metadata = wp_portability_public_metadata( $metadata );
	$social = wp_portability_int( $metadata['social_image_id'] ?? null );
	unset( $metadata['social_image_id'] );
	if ( isset( $targets[ $social ] ) && 'image' === $targets[ $social ]['kind'] && $targets[ $social ]['id'] > 0 ) {
		$metadata['social_image_id'] = $targets[ $social ]['id'];
	}
	$metadata[ WP_PORTABILITY_IMPORT_RECEIPT ] = [
		'key'       => $key,
		'source_id' => $source
	];
	return $metadata;
}
function wp_portability_public_metadata( mixed $metadata ): array
{
	$metadata = is_array( $metadata ) ? $metadata : [];
	unset( $metadata['render_epoch'], $metadata[ WP_PORTABILITY_IMPORT_RECEIPT ] );
	return $metadata;
}
function wp_portability_metadata_notes( mixed $source, array $mapped ): array
{
	$social = is_array( $source ) ? wp_portability_int( $source['social_image_id'] ?? null ) : 0;
	return $social > 0 && ! isset( $mapped['social_image_id'] ) ? [ WP_PORTABILITY_SOCIAL_FALLBACK_NOTE ] : [];
}

/** Snapshot only ready target assets; absent mappings remain explicit failures. */
function wp_portability_native_source_url( array $media, string $home ): string
{
	$declared = wp_portability_string( $media['source_url'] ?? null );
	if ( '' !== $declared ) {
		return $declared;
	}
	if ( 'image' !== $media['kind'] || '' === $home ) {
		return '';
	}
	return wp_portability_legacy_image_url( $home, wp_portability_string( $media['sha256'] ?? null ), wp_portability_string( $media['format'] ?? null ) );
}
function wp_portability_legacy_image_url( string $home, string $hash, string $format ): string
{
	return rtrim( $home, '/' ) . '/wp-content/uploads/' . substr( $hash, 0, 2 ) . '/' . $hash . '.' . $format;
}
function wp_portability_media_targets( array $media, array $mapping ): array
{
	$targets = [];
	foreach ( $media as $source ) {
		$target = wp_portability_target_asset( (int) ( $mapping[ $source['source_id'] ] ?? 0 ) );
		$targets[ $source['source_id'] ] = [
			'source_id'  => $source['source_id'],
			'source_url' => $source['source_url'] ?? '',
			'id'         => (int) ( $target['id'] ?? 0 ),
			'kind'       => $target['kind'] ?? $source['kind'],
			'url'        => null !== $target ? wp_portability_asset_url( $target ) : ''
		];
	}
	return $targets;
}
function wp_portability_target_asset( int $id ): ?array
{
	global $wpdb;
	return $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND state='ready'", [ ':id' => $id ] );
}
function wp_portability_asset_url( array $asset ): string
{
	return 'image' === $asset['kind'] ? wp_media_url( $asset ) : wp_document_url( $asset );
}
function wp_portability_reference_lookup( array $targets ): array
{
	$urls = [];
	foreach ( $targets as $target ) {
		if ( '' !== $target['source_url'] ) {
			$urls[ wp_portability_reference_key( $target['source_url'] ) ] = $target;
		}
	}
	return [
		'ids'  => $targets,
		'urls' => $urls
	];
}
function wp_portability_lookup_reference( array $reference, array $lookup ): ?array
{
	return $lookup['ids'][ $reference['source_id'] ] ?? $lookup['urls'][ wp_portability_reference_key( $reference['url'] ) ] ?? null;
}
function wp_portability_reference_key( string $url ): string
{
	return explode( '#', html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 2 )[0];
}
function wp_portability_parse_html( string $html ): DOMDocument
{
	$document = new DOMDocument( '1.0', 'UTF-8' );
	$previous = libxml_use_internal_errors( true );
	try {
		$document->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}
	return $document;
}
function wp_portability_html_references( DOMDocument $document ): array
{
	$references = [];
	foreach ( ( new DOMXPath( $document ) )->query( '//img[@src]|//a[@href]' ) ?: [] as $node ) {
		if ( $node instanceof DOMElement ) {
			$attribute = 'img' === $node->tagName ? 'src' : 'href';
			$references[] = [
				'node'      => $node,
				'attribute' => $attribute,
				'url'       => $node->getAttribute( $attribute ),
				'source_id' => wp_portability_html_media_id( $node )
			];
		}
	}
	return $references;
}
function wp_portability_html_media_id( DOMElement $node ): int
{
	preg_match( '/(?:^|\s)wp-image-([0-9]+)(?:\s|$)/', $node->getAttribute( 'class' ), $matches );
	return (int) ( $matches[1] ?? 0 );
}
function wp_portability_apply_html_plan( array $plan ): void
{
	foreach ( $plan as $change ) {
		wp_portability_apply_html_change( $change['reference'], $change['target'] );
	}
}
function wp_portability_apply_html_change( array $reference, array $target ): void
{
	$node = $reference['node'];
	if ( '' === $target['url'] ) {
		wp_portability_remove_html_reference( $node, $reference['attribute'] );
		return;
	}
	$node->setAttribute( $reference['attribute'], $target['url'] );
	$node->removeAttribute( 'srcset' );
	$node->removeAttribute( 'sizes' );
	wp_portability_rewrite_html_class( $node, $target['id'] );
}
function wp_portability_rewrite_html_class( DOMElement $node, int $id ): void
{
	if ( $node->hasAttribute( 'class' ) ) {
		$node->setAttribute( 'class', (string) preg_replace( '/(^|\s)wp-image-[0-9]+(?=\s|$)/', '$1wp-image-' . $id, $node->getAttribute( 'class' ) ) );
	}
}
function wp_portability_remove_html_reference( DOMElement $node, string $attribute ): void
{
	if ( 'href' === $attribute ) {
		$node->removeAttribute( $attribute );
		return;
	}
	$node->parentNode?->removeChild( $node );
}
function wp_portability_html_body( DOMDocument $document ): string
{
	$html = '';
	foreach ( $document->getElementsByTagName( 'body' )->item( 0 )->childNodes ?? [] as $node ) {
		$html .= $document->saveHTML( $node );
	}
	return $html;
}
