<?php
/** Public author data is opt-in and excludes account contact/security fields. */
declare( strict_types=1 );
function wp_author_slug( array $user ): string
{
	return ( wp_sanitize_slug( $user['username'] ) ?: 'author' ) . '-' . (int) $user['id'];
}
function wp_author_url( array $user ): string
{
	return wp_home_url( '/author/' . rawurlencode( wp_author_slug( $user ) ) . '/' );
}
function wp_public_author( int $id ): ?array
{
	global $wpdb;
	return $wpdb->get_row( 'SELECT id,username,display_name,biography,website,avatar_media_id FROM users WHERE id=:id AND public_profile=1', [ ':id' => $id ] );
}
function wp_author_card( array $user ): void
{
	global $wpdb;
	$avatar = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => (int) ( $user['avatar_media_id'] ?? 0 ) ] );
	echo '<section class="author-card">';
	if ( null !== $avatar ) {
		echo '<img class="author-avatar" src="' . esc_url( wp_media_url( $avatar ) ) . '" alt="" width="80" height="80" />'; 
	}
	echo '<h1>' . esc_html( $user['display_name'] ?: $user['username'] ) . '</h1><p>' . nl2br( esc_html( $user['biography'] ) ) . '</p>';
	if ( '' !== $user['website'] ) {
		echo '<a href="' . esc_url( $user['website'] ) . '" rel="nofollow noopener">Website</a>'; 
	}
	echo '</section>';
}
function wp_post_labels(): void
{
	global $wpdb;
	$post = $GLOBALS['wp_current_post'] ?? null;
	if ( null === $post || 'post' !== $post['post_type'] ) {
		return; 
	}
	$user = $wpdb->get_row( 'SELECT id,username,display_name,public_profile FROM users WHERE id=:id', [ ':id' => $post['author_id'] ] );
	echo '<div class="post-labels">';
	if ( null !== $user ) {
		$name = esc_html( $user['display_name'] ?: $user['username'] );
		echo '<span>By ' . ( $user['public_profile'] ? '<a href="' . esc_url( wp_author_url( $user ) ) . '">' . $name . '</a>' : $name ) . '</span> ';
	}
	foreach ( [
		'category' => 'Categories',
		'post_tag' => 'Tags'
	] as $taxonomy => $label ) {
		$terms = wp_post_terms( (int) $post['id'], $taxonomy );
		if ( [] === $terms ) {
			continue; 
		}
		echo '<span>' . $label . ': ';
		foreach ( $terms as $term ) {
			echo '<a href="' . esc_url( wp_term_url( $term ) ) . '">' . esc_html( $term['name'] ) . '</a> '; 
		}
		echo '</span> ';
	}
	echo '</div>';
}
