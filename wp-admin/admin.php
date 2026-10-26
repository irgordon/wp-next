<?php
/** Shared authenticated administration boundary. */
declare( strict_types=1 );
require_once dirname( __DIR__ ) . '/wp-includes/bootstrap.php';
global $wpdb;
header( 'Cache-Control: no-store, private' );
if ( get_current_user_id() <= 0 ) {
	wp_safe_redirect( 'login.php?redirect_to=' . rawurlencode( $_SERVER['REQUEST_URI'] ?? 'index.php' ) );
}
require_once __DIR__ . '/admin-functions.php';
