<?php declare( strict_types=1 ); ?>
<?php wp_shared_section( 'footer' ); ?></main><footer class="site-footer"><?php if ( [] !== (wp_design_current()['menus']['footer'] ?? []) ) :
	?><nav aria-label="Footer navigation"><?php wp_navigation( 'footer' ); ?></nav><?php 
endif; ?><p>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_option( 'site_title', '' ) ); ?></p><form method="get" action="<?php echo esc_url( wp_home_url( '/search/' ) ); ?>"><label for="site-search">Search this site</label><div class="search-row"><input type="search" id="site-search" name="s" value="<?php echo esc_attr( $GLOBALS['wp_search_term'] ?? '' ); ?>" /><button type="submit">Search</button></div></form></footer></div><?php wp_footer(); ?></body></html>
