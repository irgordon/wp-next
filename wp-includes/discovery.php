<?php
/**
 * H1 public discovery: RSS feed, XML sitemap, robots.txt and social
 * metadata. Everything emitted here is limited to published, public
 * content: drafts, previews, private documents and account contact data
 * never enter a feed, the sitemap or metadata output. The feed is
 * excerpt-only (the latest 20 published Posts), and the indexing
 * control is a single site-wide option — there is no per-entry noindex.
 */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;

/** Escapes a value for XML element or attribute content, dropping characters XML 1.0 forbids. */
function wp_discovery_escape( string $text ): string
{
	$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text ) ?? '';
	return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

function wp_discovery_feed_url(): string
{
	return wp_home_url( '/feed' );
}

function wp_discovery_sitemap_url(): string
{
	return wp_home_url( '/sitemap.xml' );
}

/** True when the site-wide "discourage search engines" option is on. */
function wp_discovery_discouraged(): bool
{
	return '1' === (string) get_option( 'discourage_search_engines', '0' );
}

/** @return array<int,array<string,mixed>> The latest published Posts with their author display data. */
function wp_discovery_feed_posts(): array
{
	global $wpdb;
	return $wpdb->get_results(
		"SELECT posts.*,users.display_name,users.username FROM posts LEFT JOIN users ON users.id=posts.author_id WHERE posts.post_type='post' AND posts.status='published' ORDER BY posts.published_at DESC,posts.id DESC LIMIT 20"
	);
}

function wp_discovery_render_feed(): string
{
	$home = wp_home_url();
	$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$out .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n";
	$out .= '<title>' . wp_discovery_escape( (string) get_option( 'site_title', '' ) ) . "</title>\n";
	$out .= '<link>' . wp_discovery_escape( $home ) . "</link>\n";
	$out .= '<description>' . wp_discovery_escape( (string) get_option( 'site_tagline', '' ) ) . "</description>\n";
	$out .= '<atom:link href="' . wp_discovery_escape( wp_discovery_feed_url() ) . '" rel="self" type="application/rss+xml" />' . "\n";
	$posts = wp_discovery_feed_posts();
	if ( [] !== $posts ) {
		$out .= '<lastBuildDate>' . gmdate( 'D, d M Y H:i:s', (int) ( $posts[0]['published_at'] ?? time() ) ) . " +0000</lastBuildDate>\n";
	}
	foreach ( $posts as $post ) {
		$url = wp_post_url( $post );
		$author = (string) ( $post['display_name'] ?: $post['username'] ?: '' );
		$out .= "<item>\n";
		$out .= '<title>' . wp_discovery_escape( (string) $post['title'] ) . "</title>\n";
		$out .= '<link>' . wp_discovery_escape( $url ) . "</link>\n";
		$out .= '<guid isPermaLink="true">' . wp_discovery_escape( $url ) . "</guid>\n";
		if ( (int) ( $post['published_at'] ?? 0 ) > 0 ) {
			$out .= '<pubDate>' . gmdate( 'D, d M Y H:i:s', (int) $post['published_at'] ) . " +0000</pubDate>\n";
		}
		if ( '' !== $author ) {
			$out .= '<dc:creator>' . wp_discovery_escape( $author ) . "</dc:creator>\n";
		}
		$out .= '<description>' . wp_discovery_escape( (string) ( $post['excerpt'] ?? '' ) ) . "</description>\n";
		$out .= "</item>\n";
	}
	$out .= "</channel>\n</rss>\n";
	return $out;
}

function wp_discovery_render_sitemap(): string
{
	global $wpdb;
	$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	$out .= '<url><loc>' . wp_discovery_escape( wp_home_url() ) . "</loc></url>\n";
	$homepage_id = (int) get_option( 'homepage_id', 0 );
	$posts = $wpdb->get_results( "SELECT * FROM posts WHERE status='published' AND post_type IN ('post','page') ORDER BY updated_at DESC,id DESC" );
	foreach ( $posts as $post ) {
		if ( (int) $post['id'] === $homepage_id ) {
			// The configured homepage is already listed at the home address.
			continue;
		}
		$out .= '<url><loc>' . wp_discovery_escape( wp_post_url( $post ) ) . '</loc>';
		if ( (int) ( $post['updated_at'] ?? 0 ) > 0 ) {
			$out .= '<lastmod>' . gmdate( 'Y-m-d', (int) $post['updated_at'] ) . '</lastmod>';
		}
		$out .= "</url>\n";
	}
	$out .= "</urlset>\n";
	return $out;
}

function wp_discovery_render_robots(): string
{
	if ( wp_discovery_discouraged() ) {
		return "User-agent: *\nDisallow: /\n";
	}
	return "User-agent: *\nDisallow:\n\nSitemap: " . wp_discovery_sitemap_url() . "\n";
}

/**
 * Resolves the social image for one post: the per-entry override first,
 * then the featured image. Only ready image records can resolve; a
 * document or a missing record never produces a URL.
 */
function wp_discovery_social_image_url( array $post ): string
{
	global $wpdb;
	$metadata = json_decode( (string) ( $post['metadata'] ?? '' ), true );
	$candidates = [];
	if ( is_array( $metadata ) && (int) ( $metadata['social_image_id'] ?? 0 ) > 0 ) {
		$candidates[] = (int) $metadata['social_image_id'];
	}
	if ( (int) ( $post['featured_media_id'] ?? 0 ) > 0 ) {
		$candidates[] = (int) $post['featured_media_id'];
	}
	foreach ( $candidates as $id ) {
		$media = $wpdb->get_row( "SELECT * FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $id ] );
		if ( null !== $media ) {
			return wp_media_url( $media );
		}
	}
	return '';
}

function wp_discovery_serve( string $route ): void
{
	if ( 'feed' === $route ) {
		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		echo wp_discovery_render_feed();
		return;
	}
	if ( 'sitemap.xml' === $route ) {
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo wp_discovery_render_sitemap();
		return;
	}
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo wp_discovery_render_robots();
}
