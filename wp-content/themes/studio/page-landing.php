<?php declare( strict_types=1 );
get_header();
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post(); ?>
<article class="page-entry template-landing"><h1 class="entry-title"><?php the_title(); ?></h1><?php the_featured_image(); ?><div class="entry-content"><?php the_content(); ?></div></article>
	<?php endwhile;
endif;
get_footer();
