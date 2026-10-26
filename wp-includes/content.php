<?php
/** Canonical nested block document with a lossless legacy HTML escape hatch. */
declare( strict_types=1 );
function wp_normalize_content( string $raw, bool $rendering = false ): string|WP_Error
{
	if ( strlen( $raw ) > 2000000 ) {
		return new WP_Error( 'content_too_large', 'Content exceeds the 2 MB document limit.' );
	}
	if ( ! str_starts_with( ltrim( $raw ), '{' ) ) {
		return wp_json_encode( [
			'version' => 1,
			'blocks'  => [
				[
					'type' => 'html',
					'html' => wp_kses_post( (string) preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $raw ) )
				]
			]
		] );
	}
	try {
		$document = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $document ) || ( $document['version'] ?? 0 ) !== 1 || ! is_array( $document['blocks'] ?? null ) ) {
			throw new RuntimeException( 'Use a version 1 block document.' );
		}
		$count = 0;
		$document = [
			'version' => 1,
			'blocks'  => wp_validate_blocks( $document['blocks'], 0, $count, $rendering )
		];
		return wp_json_encode( $document );
	} catch ( Throwable $error ) {
		return new WP_Error( 'invalid_blocks', 'The block document could not be saved: ' . $error->getMessage() );
	}
}
function wp_validate_blocks( array $blocks, int $depth, int &$count, bool $rendering = false ): array
{
	if ( $depth > 8 ) {
		throw new RuntimeException( 'At most eight nested layouts are supported.' );
	}
	$output = [];
	foreach ( $blocks as $block ) {
		if ( ++$count > 500 || ! is_array( $block ) ) {
			throw new RuntimeException( 'The document has too many blocks or an invalid block.' );
		}
		$type = $block['type'] ?? '';
		if ( ! in_array( $type, [ 'paragraph', 'heading', 'list', 'quote', 'code', 'image', 'file', 'columns', 'html', 'divider' ], true ) ) {
			throw new RuntimeException( 'Unsupported block type.' );
		}
		$item = [ 'type' => $type ];
		if ( 'columns' === $type ) {
			if ( ! is_array( $block['columns'] ?? null ) || count( $block['columns'] ) < 2 || count( $block['columns'] ) > 3 ) {
				throw new RuntimeException( 'A columns block needs two or three columns.' );
			}
			$item['columns'] = [];
			foreach ( $block['columns'] as $children ) {
				if ( ! is_array( $children ) ) {
					throw new RuntimeException( 'Invalid column.' );
				}
				$item['columns'][] = wp_validate_blocks( $children, $depth + 1, $count, $rendering );
			}
		} elseif ( 'image' === $type ) {
			global $wpdb;
			$id = (int) ( $block['media_id'] ?? 0 );
			if ( null === $wpdb->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $id ] ) ) {
				throw new RuntimeException( 'Select an existing library image.' );
			}
			$item['media_id'] = $id;
			$item['alt'] = sanitize_text_field( is_string( $block['alt'] ?? null ) ? $block['alt'] : '' );
			$item['caption'] = sanitize_text_field( is_string( $block['caption'] ?? null ) ? $block['caption'] : '' );
		} elseif ( 'file' === $type ) {
			$id = (int) ( $block['media_id'] ?? 0 );
			if ( ! $rendering ) {
				// Save-time authorization: the author must be able to access the referenced document.
				global $wpdb;
				$asset = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='document' AND state='ready'", [ ':id' => $id ] );
				if ( null === $asset || ! wp_asset_can_access( $asset ) ) {
					throw new RuntimeException( 'Select a document you can access.' );
				}
			}
			// Rendering never re-runs save authorization: the renderer omits a block the viewer cannot access and keeps the rest.
			$item['media_id'] = $id;
			$item['label'] = sanitize_text_field( is_string( $block['label'] ?? null ) ? $block['label'] : '' );
		} elseif ( 'code' === $type ) {
			$item['text'] = is_string( $block['text'] ?? null ) ? $block['text'] : '';
		} elseif ( 'divider' !== $type ) {
			$item['html'] = wp_kses_post( is_string( $block['html'] ?? null ) ? $block['html'] : '' );
			if ( 'heading' === $type ) {
				$item['level'] = min( 6, max( 2, (int) ( $block['level'] ?? 2 ) ) );
			}
			if ( 'list' === $type ) {
				$item['ordered'] = true === ( $block['ordered'] ?? false );
			}
		}
		$item['align'] = in_array( $block['align'] ?? '', [ 'left', 'center', 'right' ], true ) ? $block['align'] : 'left';
		$output[] = $item;
	}
	return $output;
}
function wp_render_content( string $raw ): string
{
	$normalized = wp_normalize_content( $raw, true );
	if ( is_wp_error( $normalized ) ) {
		return '<p>Content could not be rendered.</p>';
	}
	$document = json_decode( $normalized, true );
	return wp_kses_post( wp_render_blocks( $document['blocks'] ) );
}
function wp_render_blocks( array $blocks ): string
{
	global $wpdb;
	$output = '';
	foreach ( $blocks as $block ) {
		$body = $block['html'] ?? '';
		$type = $block['type'];
		$align = ' class="align-' . esc_attr( $block['align'] ?? 'left' ) . '"';
		switch ( $type ) {
			case 'paragraph': $output .= '<p' . $align . '>' . $body . '</p>';
break;
			case 'heading': $level = (int) $block['level'];
				$output .= '<h' . $level . $align . '>' . $body . '</h' . $level . '>';
break;
			case 'list': $tag = $block['ordered'] ? 'ol' : 'ul';
				$output .= '<' . $tag . '>' . $body . '</' . $tag . '>';
break;
			case 'quote': $output .= '<blockquote>' . $body . '</blockquote>';
break;
			case 'code': $output .= '<pre><code>' . esc_html( $block['text'] ) . '</code></pre>';
break;
			case 'divider': $output .= '<hr />';
break;
			case 'html': $output .= $body;
break;
			case 'columns':
				$output .= '<div class="wp-block-columns">';
				foreach ( $block['columns'] as $children ) {
					$output .= '<div class="wp-block-column">' . wp_render_blocks( $children ) . '</div>';
				}
				$output .= '</div>';
break;
			case 'image':
				$media = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $block['media_id'] ] );
				if ( null !== $media ) {
					$output .= '<figure' . $align . '>' . wp_image_tag( $media, (string) $block['alt'], ' loading="lazy"' ) . '';
					if ( '' !== $block['caption'] ) {
						$output .= '<figcaption>' . esc_html( $block['caption'] ) . '</figcaption>';
					}
					$output .= '</figure>';
				}
break;
			case 'file':
				$asset = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='document' AND state='ready'", [ ':id' => $block['media_id'] ] );
				if ( null !== $asset && wp_asset_can_access( $asset ) ) {
					$label = '' !== ( $block['label'] ?? '' ) ? $block['label'] : $asset['original_name'];
					$output .= '<p class="wp-block-file"><a href="' . esc_url( wp_document_url( $asset ) ) . '">' . esc_html( $label ) . '</a></p>';
				}
break;
		}
	}
	return $output;
}
function wp_sanitize_slug( string $slug ): string
{
	$slug = strtolower( trim( (string) preg_replace( '/[^\pL\pN_-]+/u', '-', $slug ), '-' ) );
	$characters = preg_split( '//u', $slug, -1, PREG_SPLIT_NO_EMPTY );
	return implode( '', array_slice( false === $characters ? [] : $characters, 0, 120 ) );
}
function wp_unique_slug( string $requested, int $exclude, \WordPress\Database\WP_SQLite_DB $db ): string
{
	$base = wp_sanitize_slug( $requested );
	if ( '' === $base ) {
		$base = 'content';
	}
	// Keep application routes reserved, including future media and worker endpoints.
	if ( in_array( $base, [ 'blog', 'search', 'preview', 'category', 'tag', 'author', 'site-assets', 'wp-admin', 'wp-content', 'wp-includes', 'index.php' ], true ) ) {
		$base .= '-page';
	}
	$slug = $base;
	$suffix = 2;
	while ( null !== $db->get_row( 'SELECT id FROM posts WHERE slug=:slug AND id<>:id', [
		':slug' => $slug,
		':id'   => $exclude
	] ) ) {
		$slug = $base . '-' . $suffix++;
	}
	return $slug;
}
