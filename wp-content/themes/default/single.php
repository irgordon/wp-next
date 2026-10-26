<?php declare( strict_types=1 );
get_header();
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post(); ?>
<article class="single-entry"><?php wp_post_labels(); ?><p class="entry-meta"><?php echo null === $GLOBALS['wp_current_post']['published_at'] ? 'Draft preview' : esc_html( gmdate( 'F j, Y', (int) $GLOBALS['wp_current_post']['published_at'] ) ); ?></p><h1 class="entry-title"><?php the_title(); ?></h1><?php the_featured_image(); ?><div class="entry-content"><?php the_content(); ?></div></article>
	<?php endwhile;
endif;
get_footer();
