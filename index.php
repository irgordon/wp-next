<?php
/** Bounded routes with real status codes and a small template hierarchy. */
declare( strict_types=1 );
require_once __DIR__ . '/wp-includes/bootstrap.php';
wp_managed_file_serve();
wp_asset_serve_document();
global $wpdb;
$base_path = rtrim( (string) parse_url( wp_home_url(), PHP_URL_PATH ), '/' );
$path = wp_normalize_request_path( $_SERVER['REQUEST_URI'] ?? '/' );
if ( is_wp_error( $path ) ) {
	wp_die( 'Invalid address.', 'Not found', [ 'response' => 404 ] );
}
if ( '' !== $base_path && str_starts_with( $path, $base_path . '/' ) ) {
	$path = substr( $path, strlen( $base_path ) );
}
$path = trim( rawurldecode( $path ), '/' );
if ( 'index.php' === $path ) {
	$path = '';
}
if ( in_array( $path, [ 'feed', 'sitemap.xml', 'robots.txt' ], true ) ) {
	wp_discovery_serve( $path );
	exit;
}
$pagination = $_GET['page'] ?? '1';
if ( ! is_string( $pagination ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', $pagination ) ) {
	http_response_code( 404 );
	wp_template( '404' );
	exit;
}
$archive = null;
if ( preg_match( '~^(category|tag)/(.+)$~u', $path, $archive_match ) ) {
	$taxonomy = 'category' === $archive_match[1] ? 'category' : 'post_tag';
	$parts = explode( '/', $archive_match[2] );
	$term_row = $wpdb->get_row( 'SELECT * FROM terms WHERE taxonomy=:taxonomy AND slug=:slug', [
		':taxonomy' => $taxonomy,
		':slug'     => end( $parts )
	] );
	if ( null !== $term_row && ( 'category' === $taxonomy ? wp_category_path( $term_row ) : $term_row['slug'] ) === $archive_match[2] ) {
		$archive = [
			'kind'             => 'term',
			'include_children' => 'category' === $taxonomy,
			'id'               => (int) $term_row['id'],
			'url'              => wp_term_url( $term_row )
		];
		$GLOBALS['wp_archive_title'] = $term_row['name'];
		$GLOBALS['wp_archive_description'] = $term_row['description'];
	}
} elseif ( preg_match( '~^author/(.+)-([1-9][0-9]*)$~u', $path, $archive_match ) ) {
	$author_row = wp_public_author( (int) $archive_match[2] );
	if ( null !== $author_row && wp_author_slug( $author_row ) === $archive_match[1] . '-' . $archive_match[2] ) {
		$archive = [
			'kind' => 'author',
			'id'   => (int) $author_row['id'],
			'url'  => wp_author_url( $author_row )
		];
		$GLOBALS['wp_archive_title'] = $author_row['display_name'] ?: $author_row['username'];
		$GLOBALS['wp_author_profile'] = $author_row;
		$GLOBALS['wp_archive_description'] = $author_row['biography'];
	}
}
$slug = wp_input( $_GET, 'slug' );
$has_identity = isset( $_GET['slug'] ) || isset( $_GET['p'] ) || isset( $_GET['page_id'] ) || isset( $_GET['document'] );
$preview = preg_match( '~^preview/(\d+)$~', $path, $matches ) ? (int) $matches[1] : 0;
$post = null;
$is_homepage = false;
if ( $preview > 0 ) {
	if ( ! current_user_can( 'edit_post', $preview ) ) {
		wp_die( 'You cannot preview this content.', 'Access denied', [ 'response' => 403 ] );
	}
	$post = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $preview ] );
	$GLOBALS['wp_is_preview'] = true;
	header( 'Cache-Control: no-store, private' );
} elseif ( $has_identity ) {
	// Identity query routes belong at the site root; never override a wrong date path.
	$keys = array_intersect_key( $_GET, array_flip( [ 'slug', 'p', 'page_id', 'document' ] ) );
	if ( '' === $path && isset( $keys['document'] ) && 1 === count( $keys ) ) {
		$document_id = wp_input( $_GET, 'document' );
		if ( preg_match( '/^[1-9][0-9]*$/D', $document_id ) ) {
			wp_asset_serve_document( (int) $document_id );
		}
		http_response_code( 404 );
		wp_template( '404' );
		exit;
	}
	if ( '' === $path && 1 === count( $keys ) ) {
		if ( '' !== $slug ) {
			$post = $wpdb->get_row( "SELECT * FROM posts WHERE slug=:slug AND status='published' LIMIT 1", [ ':slug' => $slug ] );
		} else {
			$key = isset( $_GET['p'] ) ? 'p' : 'page_id';
			$id = wp_input( $_GET, $key );
			if ( preg_match( '/^[1-9][0-9]*$/D', $id ) ) {
				$post = $wpdb->get_row( "SELECT * FROM posts WHERE id=:id AND post_type=:type AND status='published' LIMIT 1", [
					':id'   => $id,
					':type' => 'p' === $key ? 'post' : 'page'
				] );
			}
		}
	}
} elseif ( null === $archive && '' !== $path && ! in_array( $path, [ 'blog', 'search' ], true ) ) {
	$post = wp_permalink_lookup_post( $path );
	if ( null === $post ) {
		$post = $wpdb->get_row( "SELECT * FROM posts WHERE slug=:slug AND post_type='page' AND status='published' LIMIT 1", [ ':slug' => $path ] );
	}
} elseif ( '' === $path && ! isset( $_GET['s'] ) && (int) get_option( 'homepage_id', 0 ) > 0 ) {
	$post = $wpdb->get_row( "SELECT * FROM posts WHERE id=:id AND post_type='page' AND status='published'", [ ':id' => (int) get_option( 'homepage_id' ) ] );
	$is_homepage = true;
}
if ( null !== $post ) {
	wp_set_loop_posts( [ $post ] );
	$GLOBALS['wp_current_post'] = $post;
	if ( 0 === $preview ) {
		if ( $is_homepage && '1' !== $pagination ) {
			http_response_code( 404 );
			wp_template( '404' );
			exit;
		}
		wp_canonical_redirect( $is_homepage ? wp_home_url() : wp_post_url( $post ), [ 'slug', 'p', 'page_id', 'page' ] );
	}
	$GLOBALS['wp_canonical'] = $is_homepage ? wp_home_url() : wp_post_url( $post );
	wp_template( 'page' === $post['post_type'] ? 'page' : 'single' );
	exit;
}
if ( $preview > 0 || $has_identity || ( null === $archive && ! in_array( $path, [ '', 'blog', 'search' ], true ) ) ) {
	http_response_code( 404 );
	wp_template( '404' );
	exit;
}
$term = trim( wp_input( $_GET, 's' ) );
$page = (int) $pagination;
$where = "post_type='post' AND status='published'";
$parameters = [];
if ( null !== $archive ) {
	if ( 'author' === $archive['kind'] ) {
		$where .= ' AND author_id=:archive_id';
	} elseif ( $archive['include_children'] ) {
		$where .= " AND EXISTS (SELECT 1 FROM post_terms pt WHERE pt.post_id=posts.id AND pt.term_id IN (WITH RECURSIVE descendants(id) AS (SELECT :archive_id UNION SELECT t.id FROM terms t JOIN descendants d ON t.parent_id=d.id WHERE t.taxonomy='category') SELECT id FROM descendants))";
	} else {
		$where .= ' AND EXISTS (SELECT 1 FROM post_terms pt WHERE pt.post_id=posts.id AND pt.term_id=:archive_id)';
	}
	$parameters[':archive_id'] = $archive['id'];
}

if ( '' !== $term ) {
	$where .= " AND (title LIKE :search ESCAPE '\\' OR content_raw LIKE :search ESCAPE '\\')";
	$parameters[':search'] = '%' . str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $term ) . '%';
}
$total = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM posts WHERE ' . $where, $parameters )['n'];
$pages = max( 1, (int) ceil( $total / 10 ) );
if ( $page > $pages ) {
	http_response_code( 404 );
	wp_template( '404' );
	exit;
}
$posts = $wpdb->get_results( 'SELECT * FROM posts WHERE ' . $where . ' ORDER BY published_at DESC,id DESC LIMIT :limit OFFSET :offset', $parameters + [
	':limit'  => 10,
	':offset' => ( $page - 1 ) * 10
] );
wp_set_loop_posts( $posts );
$GLOBALS['wp_page_number'] = $page;
$GLOBALS['wp_total_pages'] = $pages;
$GLOBALS['wp_search_term'] = $term;
$GLOBALS['wp_archive_url'] = $archive['url'] ?? ( 'blog' === $path ? wp_home_url( '/blog/' ) : wp_home_url() );
$canonical_query = [];
if ( '' !== $term ) {
	$canonical_query['s'] = $term;
}
if ( $page > 1 ) {
	$canonical_query['page'] = $page;
}
$GLOBALS['wp_canonical'] = $GLOBALS['wp_archive_url'] . ( [] !== $canonical_query ? '?' . http_build_query( $canonical_query ) : '' );
wp_canonical_redirect( $GLOBALS['wp_canonical'], [ 's', 'page' ] );
wp_template( '' !== $term || 'search' === $path ? 'search' : 'archive' );
