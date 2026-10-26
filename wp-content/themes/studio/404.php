<?php declare( strict_types=1 );
get_header(); ?>
<section class="empty"><p class="eyebrow">404</p><h1>Page not found</h1><p>The address may have changed or the content may no longer be public.</p><a href="<?php echo esc_url( wp_home_url() ); ?>">Return home</a></section>
<?php get_footer();
