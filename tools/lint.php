<?php
/** Syntax-check project PHP without loading application entrypoints. */
declare( strict_types=1 );
$root = dirname( __DIR__ );
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$path = $file->getPathname();
	$relative = substr( $path, strlen( $root ) + 1 );
	if ( str_starts_with( $relative, 'vendor/' ) || str_starts_with( $relative, 'var/' ) || ( 'php' !== $file->getExtension() && 'bin/wp' !== $relative ) ) { continue; }
	passthru( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path ), $status );
	if ( 0 !== $status ) { exit( $status ); }
}
