<?php
/** Theme helpers, template hierarchy, and public sanitization. */
declare( strict_types=1 );
require_once __DIR__ . '/permalinks.php';
require_once __DIR__ . '/taxonomy.php';
require_once __DIR__ . '/profiles.php';
require_once __DIR__ . '/design.php';
require_once __DIR__ . '/managed-files.php';
/** Returns true when a link uses a forbidden URI scheme. */
function wp_kses_bad_protocol( string $uri ): bool
{
	$decoded = html_entity_decode( $uri, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$cleaned = preg_replace( '/[\x00-\x20\x7F]+/', '', $decoded );
	if ( null === $cleaned || str_starts_with( $cleaned, '//' ) ) {
		return true;
	}
	if ( preg_match( '/^([a-z0-9+.-]+):/i', $cleaned, $matches ) ) {
		return ! in_array( strtolower( $matches[1] ), [ 'http', 'https', 'mailto', 'tel', 'sms' ], true );
	}
	return false;
}

/** Only immutable, site-local upload URLs may appear in published content. */
function wp_is_local_image_url( string $url ): bool
{
	$base = wp_home_url( '/wp-content/uploads/' );
	$relative = (string) parse_url( $base, PHP_URL_PATH );
	$tail = str_starts_with( $url, $base ) ? substr( $url, strlen( $base ) ) : ( str_starts_with( $url, $relative ) ? substr( $url, strlen( $relative ) ) : '' );
	return 1 === preg_match( '~^[a-f0-9]{2}/[a-f0-9]{64}\.(?:jpg|png|webp|gif)$~D', $tail );
}
/** Sanitizes both live and materialized post HTML. */
function wp_kses_post( string $content ): string
{
	if ( '' === trim( $content ) ) {
		return '';
	}
	if ( ! class_exists( 'DOMDocument' ) ) {
		return esc_html( $content );
	}
	$allowed_tags = [
		'img'        => [
			'src'     => true,
			'srcset'  => true,
			'sizes'   => true,
			'alt'     => true,
			'width'   => true,
			'height'  => true,
			'loading' => true,
			'class'   => true
		],
		'figure'     => [ 'class' => true ],
		'figcaption' => [],
		'table'      => [ 'class' => true ],
		'thead'      => [],
		'tbody'      => [],
		'tfoot'      => [],
		'tr'         => [],
		'th'         => [
			'scope'   => true,
			'colspan' => true,
			'rowspan' => true
		],
		'td'         => [
			'colspan' => true,
			'rowspan' => true
		],
		'u'          => [],
		'sup'        => [],
		'sub'        => [],
		'p'          => [
			'class' => true,
		],
		'br'         => [],
		'hr'         => [
			'class' => true,
		],
		'h1'         => [
			'class' => true,
		],
		'h2'         => [
			'class' => true,
		],
		'h3'         => [
			'class' => true,
		],
		'h4'         => [
			'class' => true,
		],
		'h5'         => [
			'class' => true,
		],
		'h6'         => [
			'class' => true,
		],
		'a'          => [
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
			'class'  => true,
		],
		'strong'     => [
			'class' => true,
		],
		'b'          => [
			'class' => true,
		],
		'em'         => [
			'class' => true,
		],
		'i'          => [
			'class' => true,
		],
		'code'       => [
			'class' => true,
		],
		'pre'        => [
			'class' => true,
		],
		'ul'         => [
			'class' => true,
		],
		'ol'         => [
			'class' => true,
		],
		'li'         => [
			'class' => true,
		],
		'blockquote' => [
			'cite'  => true,
			'class' => true,
		],
		'div'        => [
			'class' => true,
		],
		'span'       => [
			'class' => true,
		],
	];
	$dom = new DOMDocument( '1.0', 'UTF-8' );
	$internal_errors = libxml_use_internal_errors( true );
	try {
		$html_wrapper = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="wp-kses-root">' . $content . '</div></body></html>';
		$loaded = $dom->loadHTML( $html_wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );
	}
	if ( ! $loaded ) {
		return esc_html( $content );
	}
	$xpath = new DOMXPath( $dom );
	$root_nodes = $xpath->query( '//div[@id="wp-kses-root"]' );
	$root = false !== $root_nodes ? $root_nodes->item( 0 ) : null;
	if ( ! ( $root instanceof DOMElement ) ) {
		return esc_html( $content );
	}
	// The actual wrapper is trusted by object identity. Author-supplied IDs confer no exemption.
	$nodes = $xpath->query( './/*', $root );
	if ( false === $nodes ) {
		return esc_html( $content );
	}
	$nodes_to_remove = [];
	foreach ( $nodes as $node ) {
		if ( ! ( $node instanceof DOMElement ) ) {
			continue;
		}
		$tag_name = strtolower( $node->tagName );
		if ( ! isset( $allowed_tags[ $tag_name ] ) ) {
			$nodes_to_remove[] = $node;
			continue;
		}
		if ( 'img' === $tag_name && ! wp_is_local_image_url( $node->getAttribute( 'src' ) ) ) {
			$nodes_to_remove[] = $node;
			continue;
		}
		if ( 'a' === $tag_name ) {
			$relations = array_intersect( preg_split( '/\s+/', strtolower( $node->getAttribute( 'rel' ) ) ) ?: [], [ 'nofollow', 'ugc', 'sponsored', 'noreferrer', 'noopener' ] );
			if ( '_blank' === strtolower( $node->getAttribute( 'target' ) ) ) {
				$relations[] = 'noopener';
				$node->setAttribute( 'target', '_blank' );
			} elseif ( '_self' !== $node->getAttribute( 'target' ) ) {
				$node->removeAttribute( 'target' );
			}
			$node->setAttribute( 'rel', implode( ' ', array_unique( $relations ) ) );
		}
		$attrs_to_remove = [];
		foreach ( $node->attributes as $attr ) {
			$attr_name = strtolower( $attr->name );
			if ( str_starts_with( $attr_name, 'on' ) || ! isset( $allowed_tags[ $tag_name ][ $attr_name ] ) ) {
				$attrs_to_remove[] = $attr->name;
				continue;
			}
			if ( in_array( $attr_name, [ 'href', 'src', 'cite' ], true ) && wp_kses_bad_protocol( $attr->value ) ) {
				$attrs_to_remove[] = $attr->name;
			}
			if ( 'srcset' === $attr_name ) {
				foreach ( explode( ',', $attr->value ) as $candidate ) {
					$url = trim( preg_split( '/\s+/', trim( $candidate ) )[0] ?? '' );
					if ( '' === $url || ! wp_is_local_image_url( $url ) ) {
						$attrs_to_remove[] = $attr->name;
						break;
					}
				}
			}
		}
		foreach ( $attrs_to_remove as $attr_name ) {
			$node->removeAttribute( $attr_name );
		}
	}
	foreach ( $nodes_to_remove as $node ) {
		$parent = $node->parentNode;
		if ( null === $parent ) {
			continue;
		}
		// Active containers are removed with their contents; other wrappers retain sanitized children.
		if ( ! in_array( strtolower( $node->tagName ), [ 'script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'meta', 'link', 'base' ], true ) ) {
			while ( null !== $node->firstChild ) {
				$parent->insertBefore( $node->firstChild, $node );
			}
		}
		$parent->removeChild( $node );
	}
	$output = '';
	foreach ( $root->childNodes as $child ) {
		$output .= $dom->saveHTML( $child );
	}
	return $output;
}
$GLOBALS['wp_query_posts'] = [];
$GLOBALS['wp_query_index'] = -1;
$GLOBALS['wp_current_post'] = null;
function wp_set_loop_posts( array $posts ): void
{
	$GLOBALS['wp_query_posts'] = array_values( $posts );
	$GLOBALS['wp_query_index'] = -1;
	$GLOBALS['wp_current_post'] = null;
}
/** @phpstan-impure */
function have_posts(): bool
{
	return isset( $GLOBALS['wp_query_posts'][ $GLOBALS['wp_query_index'] + 1 ] );
}
function the_post(): void
{
	++$GLOBALS['wp_query_index'];
	$GLOBALS['wp_current_post'] = $GLOBALS['wp_query_posts'][ $GLOBALS['wp_query_index'] ] ?? null;
}
function the_title(): void
{
	echo esc_html( $GLOBALS['wp_current_post']['title'] ?? '' );
}
function the_content(): void
{
	$post = $GLOBALS['wp_current_post'];
	if ( null === $post ) {
		return;
	}
	if ( ! empty( $post['content_rendered'] ) && (int) $post['revision'] > 0 && (int) $post['rendered_revision'] === (int) $post['revision'] ) {
		echo wp_kses_post( $post['content_rendered'] );
	} else {
		echo wp_render_content( $post['content_raw'] );
	}
}
/** Reads mutable database configuration. @phpstan-impure */
function get_option( string $name, mixed $default = '' ): mixed
{
	global $wpdb;
    if ( isset( $GLOBALS['wp_design_override'] ) && array_key_exists( $name, $GLOBALS['wp_design_override'] ) ) {
		return $GLOBALS['wp_design_override'][ $name ]; 
	}
	if ( null === $wpdb ) {
		return $default;
	}
	$row = $wpdb->get_row( 'SELECT option_value FROM options WHERE option_name=:name', [ ':name' => $name ] );
	return null === $row ? $default : $row['option_value'];
}
function wp_home_url( string $path = '' ): string
{
	$base = rtrim( (string) get_option( 'site_url', 'http://localhost' ), '/' );
	return '' === $path ? $base . '/' : $base . '/' . ltrim( $path, '/' );
}
function wp_post_url( array $post ): string
{
	$structure = wp_permalink_structure();
	if ( 'page' === ( $post['post_type'] ?? 'post' ) ) {
		// A numeric/custom post route can share a Page slug. Keep both addressable.
		if ( '' === $structure || null !== wp_permalink_lookup_post( $post['slug'] ) ) {
			return wp_home_url( '?page_id=' . (int) $post['id'] );
		}
		return wp_home_url( '/' . rawurlencode( $post['slug'] ) . '/' );
	}
	return '' === $structure ? wp_home_url( '?p=' . (int) $post['id'] ) : wp_home_url( wp_permalink_path( $post, $structure ) );
}
function the_permalink(): void
{
	echo esc_url( wp_post_url( $GLOBALS['wp_current_post'] ?? [ 'slug' => '' ] ) );
}
function wp_available_themes(): array
{
    $themes = [];
    foreach ( glob(WP_ROOT_DIR . '/wp-content/themes/*', GLOB_ONLYDIR) ?: [] as $directory ) {
		$slug = basename($directory);
		$manifest = wp_theme_manifest($slug);
		if ( null !== $manifest ) {
			$themes[ $slug ] = $manifest['name'];
		} 
	}
    return $themes;
}
function wp_theme_slug(): string
{
	$themes = wp_available_themes();
	$default = defined( 'WP_DEFAULT_THEME' ) ? WP_DEFAULT_THEME : 'default';
	$active = (string) get_option( 'active_theme', $default );
	return isset( $themes[ $active ] ) ? $active : ( isset( $themes[ $default ] ) ? $default : 'default' );
}
function wp_theme_directory(): string
{
	return WP_ROOT_DIR . '/wp-content/themes/' . wp_theme_slug();
}
function wp_template( string $kind ): void
{
    if ( null === wp_theme_manifest(wp_theme_slug()) ) {
		echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>'.esc_html( (string)get_option('site_title')).'</title><main><h1>'.esc_html( (string)get_option('site_title')).'</h1>';
		while ( have_posts() ) {
			the_post();
			echo '<article><h2>';
			the_title();
			echo '</h2>';
			the_content();
			echo '</article>';

		}echo '</main></html>';
		return; 
	}
	$directory = wp_theme_directory();
	$allowed = [ 'page', 'single', 'archive', 'search', '404', 'index' ];
	$kind = in_array( $kind, $allowed, true ) && is_file( $directory . '/' . $kind . '.php' ) ? $kind : 'index';
    $file = $kind . '.php';
    if ( 'page' === $kind ) {
		$metadata = json_decode($GLOBALS['wp_current_post']['metadata'] ?? '{}',true) ?: [];
		$id = $metadata['page_template'] ?? 'standard';
		$file = wp_curated_templates()[ $id ] ?? 'page.php'; 
	}
    $level = ob_get_level();
	ob_start();
    try {
		require $directory . '/' . $file;
		echo ob_get_clean(); 
	} catch ( Throwable $error ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();

		} error_log('Theme render failed: '.$error->getMessage());
		if ( str_ends_with($directory,'/default') ) {
			wp_die('Theme unavailable.','Unavailable',['response' => 503]);

		} $GLOBALS['wp_design_override']['active_theme'] = 'default';
		require WP_ROOT_DIR . '/wp-content/themes/default/' . $kind . '.php'; 
	}

}
function get_header(): void
{
	require wp_theme_directory() . '/header.php';
}
function get_footer(): void
{
	require wp_theme_directory() . '/footer.php';
}
function wp_head(): void
{
	$post = $GLOBALS['wp_current_post'];
	$description = $post['excerpt'] ?? $GLOBALS['wp_archive_description'] ?? get_option( 'site_tagline', '' );
	$canonical = $GLOBALS['wp_canonical'] ?? wp_home_url();
	echo '<meta name="description" content="' . esc_attr( $description ) . '" /><link rel="canonical" href="' . esc_url( $canonical ) . '" />';
	echo '<link rel="stylesheet" href="' . esc_url( wp_home_url( '/wp-content/themes/' . wp_theme_slug() . '/style.css' ) ) . '" />';
	$design = json_decode( (string) get_option( 'design_tokens', '{}' ), true ) ?: [];
	$accent = preg_match( '/^#[a-fA-F0-9]{6}$/', $design['accent'] ?? '' ) ? $design['accent'] : '#3858e9';
	$background = preg_match( '/^#[a-fA-F0-9]{6}$/', $design['background'] ?? '' ) ? $design['background'] : '#ffffff';
	$width = min( 1400, max( 640, (int) ( $design['width'] ?? 960 ) ) );
	$dark = wp_color_luminance( $background ) < 0.35;
	$accent_ink = wp_color_luminance( $accent ) > 0.179 ? '#111111' : '#ffffff';
	$ink = $dark ? '#f4f6f7' : '#1e1e1e';
	$muted = $dark ? '#d7e0e4' : '#656a71';
	$ink = wp_design_ink($background,$ink);
	$muted = wp_design_ink($background,$muted);
	$accent_ink = wp_design_ink($accent,$accent_ink);
	$heading_font = 'serif' === ( $design['font'] ?? '' ) ? 'var(--font-editorial)' : 'var(--font-ui)';
	echo '<style>:root{--accent:' . esc_html( $accent ) . ';--accent-ink:' . $accent_ink . ';--ink:' . $ink . ';--muted:' . $muted . ';--surface:' . esc_html( $background ) . ';--content-width:' . $width . 'px;--heading-font:' . $heading_font . '}</style>';
    $tokens = wp_design_current()['tokens'];
	$size = min(24,max(14, (int)($tokens['body_size'] ?? 16)));
	$spacing = min(80,max(12, (int)($tokens['spacing'] ?? 32)));
    if ( isset($tokens['body_size']) || isset($tokens['spacing']) ) {
		echo '<style>body{font-size:'.$size.'px}.site-header,.site-footer,.shared-section{padding-block:'.$spacing.'px}</style>'; 
	}
    if ( wp_managed_file_version('custom.css') > 0 || wp_css_preview_active() ) {
		$href = wp_home_url('/site-assets/custom.css');
		if ( wp_css_preview_active() ) {
			$href .= '?css_preview=1&_wpnonce=' . wp_nonce( 'css-preview' );
		}
		echo '<link rel="stylesheet" href="'.esc_url($href).'" />'; 
	}
}function wp_footer(): void
{
}
function wp_navigation( string $location = 'header' ): void
{
	global $wpdb;
    if ( wp_design_menu( $location ) ) {
		return; 
	}
    if ( 'header' !== $location ) {
		return; 
	}
	echo '<a href="' . esc_url( wp_home_url() ) . '">Home</a>';
	if ( (int) get_option( 'homepage_id', 0 ) > 0 ) {
		echo '<a href="' . esc_url( wp_home_url( '/blog/' ) ) . '">Journal</a>';
	}
	$ids = json_decode( (string) get_option( 'navigation_pages', '[]' ), true ) ?: [];
	foreach ( array_slice( $ids, 0, 20 ) as $id ) {
		$page = $wpdb->get_row( "SELECT id,slug,title FROM posts WHERE id=:id AND post_type='page' AND status='published'", [ ':id' => (int) $id ] );
		if ( null !== $page ) {
			echo '<a href="' . esc_url( wp_post_url( $page ) ) . '"' . ( (int) ( $GLOBALS['wp_current_post']['id'] ?? 0 ) === (int) $page['id'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $page['title'] ) . '</a>';
		}
	}
}
function wp_pagination(): void
{
	$page = (int) ( $GLOBALS['wp_page_number'] ?? 1 );
	$total = (int) ( $GLOBALS['wp_total_pages'] ?? 1 );
	if ( $total <= 1 ) {
		return;
	}
	$base = $GLOBALS['wp_archive_url'] ?? wp_home_url();
	$search = $GLOBALS['wp_search_term'] ?? '';
	echo '<nav class="pagination" aria-label="Pagination">';
	foreach ( [
		$page - 1 => 'Previous',
		$page + 1 => 'Next'
	] as $number => $label ) {
		if ( $number >= 1 && $number <= $total ) {
			echo '<a href="' . esc_url( $base . '?' . http_build_query( [
				'page' => $number,
				's'    => $search
			] ) ) . '">' . esc_html( $label ) . '</a>';
		}
	}
	echo '<span>Page ' . $page . ' of ' . $total . '</span></nav>';
}
function the_featured_image(): void
{
	global $wpdb;
	$id = (int) ( $GLOBALS['wp_current_post']['featured_media_id'] ?? 0 );
	if ( $id <= 0 ) {
		return;
	}
	$media = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => $id ] );
	if ( null !== $media ) {
		echo '<figure class="featured">' . wp_image_tag( $media, (string) $media['alt_text'] ) . '</figure>';
	}
}
function wp_site_logo(): void
{
	global $wpdb;
	$media = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => (int) get_option( 'site_logo_id', 0 ) ] );
	if ( null !== $media ) {
		echo '<img class="site-logo" src="' . esc_url( wp_media_url( $media ) ) . '" alt="' . esc_attr( get_option( 'site_title', '' ) ) . '" />';
	}
}

function wp_color_luminance( string $hex ): float
{
	$sum = 0.0;
	foreach ( [ 0.2126, 0.7152, 0.0722 ] as $index => $weight ) {
		$value = hexdec( substr( $hex, 1 + 2 * $index, 2 ) ) / 255;
		$sum += ( $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 ) ) * $weight;
	}
	return $sum;
}
