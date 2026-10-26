/** Syntax-check every self-hosted administration script. */
import { readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const directory = new URL( '../wp-admin/js/', import.meta.url );
const scripts = readdirSync( directory ).filter( name => name.endsWith( '.js' ) ).sort();
for ( const name of scripts ) {
	const path = fileURLToPath( new URL( name, directory ) );
	const result = spawnSync( process.execPath, [ '--check', path ], { stdio: 'inherit' } );
	if ( result.status !== 0 ) { process.exit( result.status || 1 ); }
}
console.log( scripts.length + ' administration scripts passed syntax checks.' );
