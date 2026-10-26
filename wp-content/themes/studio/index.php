<?php
/** Archive fallback. */
declare( strict_types=1 );
get_header();
if ( isset( $GLOBALS['wp_author_profile'] ) ) {
	wp_author_card( $GLOBALS['wp_author_profile'] ); 
} elseif ( isset( $GLOBALS['wp_archive_title'] ) ) {
	echo '<h1>' . esc_html( $GLOBALS['wp_archive_title'] ) . '</h1><p>' . esc_html( $GLOBALS['wp_archive_description'] ?? '' ) . '</p>'; 
}
if ( '' !== ( $GLOBALS['wp_search_term'] ?? '' ) ) {
	echo '<h1>Search results for “' . esc_html( $GLOBALS['wp_search_term'] ) . '”</h1>';
}
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post(); ?>
<article class="archive-entry"><?php wp_post_labels(); ?><p class="entry-meta"><?php echo esc_html( gmdate( 'F j, Y', (int) $GLOBALS['wp_current_post']['published_at'] ) ); ?></p><h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php the_featured_image(); ?><div class="entry-summary"><?php if ( '' !== $GLOBALS['wp_current_post']['excerpt'] ) {
	echo '<p>' . esc_html( $GLOBALS['wp_current_post']['excerpt'] ) . '</p>';
							   } else {
								   the_content();
							   } ?></div><a class="read-more" href="<?php the_permalink(); ?>">Read more →</a></article>
	<?php endwhile;
else :
	?><section class="empty"><h1>No posts found</h1><p>Try another search, or return to the home page.</p></section><?php
endif;
wp_pagination();
get_footer();
