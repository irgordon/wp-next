<?php
/** Validated permalink structures shared by generation and indexed route lookup. */
declare( strict_types=1 );
function wp_permalink_presets(): array
{
	return [
		'plain'   => [ 'Plain', '' ],
		'day'     => [ 'Day and name', '/%year%/%monthnum%/%day%/%postname%/' ],
		'month'   => [ 'Month and name', '/%year%/%monthnum%/%postname%/' ],
		'numeric' => [ 'Numeric', '/archives/%post_id%' ],
		'name'    => [ 'Post name', '/%postname%/' ]
	];
}
function wp_permalink_tags(): array
{
	return [ 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'post_id', 'postname', 'author', 'category' ];
}
function wp_validate_permalink_structure( string $structure, bool $check_reserved = true ): string|WP_Error
{
	if ( '' === $structure ) {
		return '';
	}
	if ( strlen( $structure ) > 240 || ! str_starts_with( $structure, '/' ) || str_contains( $structure, '//' ) ) {
		return new WP_Error( 'invalid_permalink', 'Use a local path beginning with one slash, up to 240 characters.' );
	}
	$body = str_starts_with( $structure, '/index.php/' ) ? substr( $structure, 10 ) : $structure;
	preg_match_all( '/%([a-z_]+)%/', $body, $found );
	$tags = $found[1];
	$literal = preg_replace( '/%[a-z_]+%/', 'token', $body );
	if ( [] !== array_diff( $tags, wp_permalink_tags() ) || count( $tags ) !== count( array_unique( $tags ) ) || ! preg_match( '~^/[a-z0-9_/-]+(?:\.html?)?/?$~D', $literal ) || ! preg_match( '~%(?:postname|post_id)%(?:\.html?)?/?$~D', $body ) ) {
		return new WP_Error( 'invalid_permalink', 'Use supported tags once each, and end with %postname% or %post_id% (optionally .html). Use date, time, author, category, ID or post-name tags.' );
	}
	$first = explode( '/', trim( $body, '/' ) )[0];
	$reserved = [ 'blog', 'search', 'preview', 'category', 'tag', 'author', 'site-assets', 'wp-admin', 'wp-content', 'wp-includes', 'var', 'docs', 'vendor', 'tests', 'tools', 'bin' ];
	if ( $check_reserved ) {
		// New settings may not claim the document route; structures stored before the reservation are honored on read instead.
		$reserved[] = 'documents';
	}
	if ( in_array( $first, $reserved, true ) || str_starts_with( $first, 'wp-' ) ) {
		return new WP_Error( 'reserved_permalink', 'That prefix is reserved for an application route.' );
	}
	return $structure;
}
/** A stored structure that predates a route reservation stays in force; only malformed structures fall back. */
function wp_permalink_structure_wellformed( string $structure ): bool
{
	return ! is_wp_error( wp_validate_permalink_structure( $structure, false ) );
}
/** True when the effective permalink structure already claims the /documents prefix for posts. */
function wp_documents_route_conflict(): bool
{
	$structure = wp_permalink_structure();
	if ( '' === $structure ) {
		return false;
	}
	$body = str_starts_with( $structure, '/index.php/' ) ? substr( $structure, 10 ) : $structure;
	return 'documents' === explode( '/', trim( $body, '/' ) )[0];
}
/** @phpstan-impure */
function wp_permalink_structure(): string
{
	$value = (string) get_option( 'permalink_structure', '/%postname%/' );
	return wp_permalink_structure_wellformed( $value ) ? $value : '/%postname%/';
}
/** @phpstan-impure */
function wp_permalink_structures(): array
{
	$history = json_decode( (string) get_option( 'permalink_history', '[]' ), true );
	$structures = [ wp_permalink_structure(), '/%postname%/', '/%monthnum%/%day%/%postname%/' ];
	foreach ( is_array( $history ) ? array_slice( $history, 0, 50 ) : [] as $item ) {
		if ( is_string( $item ) && wp_permalink_structure_wellformed( $item ) ) {
			$structures[] = $item;
		}
	}
	return array_values( array_unique( array_filter( $structures, static fn( $value ) => '' !== $value ) ) );
}
function wp_permalink_values( array $post ): array
{
	global $wpdb;
	$time = (int) ( $post['published_at'] ?? $post['created_at'] ?? 0 );
	$author = $wpdb->get_row( 'SELECT username FROM users WHERE id=:id', [ ':id' => (int) ( $post['author_id'] ?? 0 ) ] );
	return [
		'year'     => gmdate( 'Y', $time ),
		'monthnum' => gmdate( 'm', $time ),
		'day'      => gmdate( 'd', $time ),
		'hour'     => gmdate( 'H', $time ),
		'minute'   => gmdate( 'i', $time ),
		'second'   => gmdate( 's', $time ),
		'category' => wp_category_path( wp_post_terms( (int) ( $post['id'] ?? 0 ), 'category' )[0] ?? $wpdb->get_row( "SELECT * FROM terms WHERE taxonomy='category' AND slug='uncategorized'" ) ),
		'post_id'  => (string) ( $post['id'] ?? 0 ),
		'postname' => (string) $post['slug'],
		'author'   => wp_sanitize_slug( $author['username'] ?? '' ) ?: 'author-' . (int) ( $post['author_id'] ?? 0 )
	];
}
function wp_permalink_path( array $post, string $structure ): string
{
	$replace = [];
	foreach ( wp_permalink_values( $post ) as $tag => $value ) {
		$replace[ '%' . $tag . '%' ] = 'category' === $tag ? implode( '/', array_map( 'rawurlencode', explode( '/', $value ) ) ) : rawurlencode( $value );
	}
	return strtr( $structure, $replace );
}
/** Looks up by unique slug or primary key, then verifies every captured tag. */
function wp_permalink_lookup_post( string $path ): ?array
{
	global $wpdb;
	foreach ( wp_permalink_structures() as $structure ) {
		$pattern = preg_quote( trim( $structure, '/' ), '~' );
		foreach ( wp_permalink_tags() as $tag ) {
			$part = 'category' === $tag ? '.+?' : ( in_array( $tag, [ 'postname', 'author' ], true ) ? '[^/]+' : ( 'year' === $tag ? '[0-9]{4}' : ( 'post_id' === $tag ? '[1-9][0-9]*' : '[0-9]{1,2}' ) ) );
			$pattern = str_replace( '%' . $tag . '%', '(?P<' . $tag . '>' . $part . ')', $pattern );
		}
		if ( ! preg_match( '~^' . $pattern . '$~uD', $path, $matches ) ) {
			continue;
		}
		$by_slug = isset( $matches['postname'] );
		$post = $wpdb->get_row( "SELECT * FROM posts WHERE " . ( $by_slug ? 'slug' : 'id' ) . "=:key AND post_type='post' AND status='published' LIMIT 1", [ ':key' => $by_slug ? $matches['postname'] : $matches['post_id'] ] );
		if ( null === $post ) {
			continue;
		}
		$values = wp_permalink_values( $post );
		if ( isset( $matches['category'] ) ) {
			$paths = array_map( 'wp_category_path', wp_post_terms( (int) $post['id'], 'category' ) );
			if ( ! in_array( $matches['category'], $paths ?: [ $values['category'] ], true ) ) {
				continue; 
			}
			$values['category'] = $matches['category'];
		}

		$valid = true;
		foreach ( wp_permalink_tags() as $tag ) {
			if ( isset( $matches[ $tag ] ) && ( in_array( $tag, [ 'postname', 'author', 'category' ], true ) ? $matches[ $tag ] !== $values[ $tag ] : (int) $matches[ $tag ] !== (int) $values[ $tag ] ) ) {
				$valid = false;
				break;
			}
		}
		if ( $valid ) {
			return $post;
		}
	}
	return null;
}
/** Redirect aliases and slash/padding variants without trusting the request host. */
function wp_canonical_redirect( string $url, array $query_keys ): void
{
    if ( isset( $GLOBALS['wp_design_preview'] ) ) {
		return; 
	}
	$expected_path = (string) parse_url( $url, PHP_URL_PATH );
	$actual_path = explode( '?', $_SERVER['REQUEST_URI'] ?? '/', 2 )[0];
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
	$identity = array_intersect_key( $_GET, array_flip( $query_keys ) );
	if ( $actual_path !== $expected_path || $query != $identity ) {
		header( 'Location: ' . $url, true, 301 );
		exit;
	}
}
