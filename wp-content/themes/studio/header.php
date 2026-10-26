<?php
/** Public header. Theme assets and links use the configured site base URL. */
declare( strict_types=1 );
$post = $GLOBALS['wp_current_post'];
$title = null !== $post ? $post['title'] . ' · ' . get_option( 'site_title' ) : ( isset( $GLOBALS['wp_archive_title'] ) ? $GLOBALS['wp_archive_title'] . ' · ' : '' ) . get_option( 'site_title' );
?>
<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /><title><?php echo esc_html( $title ); ?></title><?php wp_head(); ?></head><body>
<a class="skip-link" href="#main-content">Skip to content</a><div class="site-container"><header class="site-header"><div><a class="site-brand" href="<?php echo esc_url( wp_home_url() ); ?>"><?php wp_site_logo(); ?><span><?php echo esc_html( get_option( 'site_title', 'Your site' ) ); ?></span></a><p class="site-tagline"><?php echo esc_html( get_option( 'site_tagline', '' ) ); ?></p></div><nav class="site-navigation" aria-label="Main navigation"><?php wp_navigation(); ?></nav></header><main id="main-content">

<?php wp_shared_section( 'header' ); ?>
