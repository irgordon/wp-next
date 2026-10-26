<?php
/** Local development only. Real deployments use the documented server rules. */
declare( strict_types=1 );
$path = preg_replace( '~/+~', '/', rawurldecode( explode( '?', $_SERVER['REQUEST_URI'] ?? '/', 2 )[0] ) );
if ( preg_match( '~(^|/)\.|^/(var|docs|vendor|tests|tools|bin|wp-includes)(/|$)|^/wp-content/.*\.(?:php|phtml|phar)(?:/|$)|^/wp-admin/.*\.php/|^/(?:wp-config|wp-cron)\.php(?:/|$)|\.(sqlite|sqlite-wal|sqlite-shm|db|key|log|md|lock)$|^/(composer\.(json|lock)|phpstan\.neon|phpcs\.xml)$~i', $path ) ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
if ( is_file( $root . $path ) ) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
