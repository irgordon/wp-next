<?php
/** Bounded CLI queue entrypoint; never an unauthenticated HTTP trigger. */
declare( strict_types=1 );
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}
$argv = [ __FILE__, 'queue:work', '50' ];
require __DIR__ . '/bin/wp';
