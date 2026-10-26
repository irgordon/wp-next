/** Delivery and text-preservation contracts without a browser or server. */
import assert from 'node:assert/strict';
import { createUploadEntry, startTransfer, applyUploadProgress, markTransferSent, finishTransfer, retryTransfer, uploadSummary } from '../wp-admin/js/upload-model.js';
import { tokenizeCode, codeTokenLines, codePosition, formatJsonCode } from '../wp-admin/js/code-model.js';

let count = 0;
function check( label, test ) {
	test(); ++count; console.log( 'PASS: ' + label );
}
const metadata = { id: 'a', name: 'image.png', size: 12000, type: 'image/png' };
const queued = [ createUploadEntry( metadata, 'images' ) ];
const sending = startTransfer( queued, 'a' );
const attempt = sending[ 0 ].attempt;
const accepted = { kind: 'load', status: 200, body: '{"ok":true,"id":7}', url: 'https://site.test/wp-admin/media.php' };
check( 'Selected images start queued, with no implied server success', () => assert.equal( queued[ 0 ].status, 'queued' ) );
check( 'Empty files produce a visible rejection reason', () => assert.match( createUploadEntry( { ...metadata, size: 0 }, 'images' ).message, /empty/ ) );
check( 'Images over the real ten-MiB limit are rejected before transfer', () => assert.equal( createUploadEntry( { ...metadata, size: 10485761 }, 'images' ).status, 'rejected' ) );
check( 'Unsupported file kinds cannot enter the image queue', () => assert.equal( createUploadEntry( { ...metadata, name: 'archive.zip', type: 'application/zip' }, 'images' ).status, 'rejected' ) );
check( 'A supported document extension permits an unspecified browser MIME', () => assert.equal( createUploadEntry( { ...metadata, name: 'notes.md', type: '' }, 'documents' ).status, 'queued' ) );
check( 'The exact document ceiling remains accepted', () => assert.equal( createUploadEntry( { ...metadata, name: 'report.pdf', size: 26214400 }, 'documents' ).status, 'queued' ) );
check( 'One byte over the document ceiling is rejected', () => assert.equal( createUploadEntry( { ...metadata, name: 'report.pdf', size: 26214401 }, 'documents' ).status, 'rejected' ) );
check( 'Percentage comes from the actual byte counts', () => assert.equal( applyUploadProgress( sending, 'a', { loaded: 37, total: 100, computable: true }, attempt )[ 0 ].progress, 37 ) );
check( 'Unknown transfer lengths remain indeterminate', () => assert.equal( applyUploadProgress( sending, 'a', { loaded: 30, total: 0, computable: false }, attempt )[ 0 ].progress, null ) );
check( 'Reaching 100 percent does not mark a file uploaded', () => assert.equal( applyUploadProgress( sending, 'a', { loaded: 100, total: 100, computable: true }, attempt )[ 0 ].status, 'uploading' ) );
check( 'Completed transfer waits in processing for server acknowledgement', () => assert.equal( markTransferSent( sending, 'a', attempt )[ 0 ].status, 'processing' ) );
check( 'Only a successful acknowledgement with an asset ID completes an upload', () => assert.equal( finishTransfer( sending, 'a', accepted, attempt )[ 0 ].status, 'complete' ) );
check( 'Success-shaped data without an ID never becomes uploaded', () => assert.equal( finishTransfer( sending, 'a', { ...accepted, body: '{"ok":true}' }, attempt )[ 0 ].status, 'failed' ) );
check( 'A server refusal remains visible after all bytes have transferred', () => assert.match( finishTransfer( markTransferSent( sending, 'a', attempt ), 'a', { ...accepted, status: 422, body: '{"ok":false,"error":"Invalid image bytes"}' }, attempt )[ 0 ].message, /Invalid image/ ) );
check( 'Login redirects produce actionable session feedback', () => assert.match( finishTransfer( sending, 'a', { ...accepted, body: '<html>Login</html>', url: 'https://site.test/wp-admin/login.php' }, attempt )[ 0 ].message, /session/ ) );
check( 'Malformed responses never produce false completion', () => assert.equal( finishTransfer( sending, 'a', { ...accepted, body: '<html>Unavailable</html>' }, attempt )[ 0 ].status, 'failed' ) );
check( 'Network failures remain retryable with an honest error', () => assert.match( finishTransfer( sending, 'a', { kind: 'error', status: 0, body: '' }, attempt )[ 0 ].message, /connection/ ) );
check( 'Canceling stops the request without claiming deletion', () => assert.equal( finishTransfer( sending, 'a', { kind: 'abort', status: 0, body: '' }, attempt )[ 0 ].status, 'canceled' ) );
check( 'Retry starts a new attempt and resets the transfer percentage', () => {
	const failed = finishTransfer( sending, 'a', { kind: 'error', status: 0, body: '' }, attempt );
	const retry = startTransfer( retryTransfer( failed, 'a' ), 'a' );
	assert.equal( retry[ 0 ].attempt, attempt + 1 ); assert.equal( retry[ 0 ].progress, 0 );
} );
check( 'Late progress from a canceled attempt cannot change a new attempt', () => {
	const canceled = finishTransfer( sending, 'a', { kind: 'abort', status: 0, body: '' }, attempt );
	const retry = startTransfer( retryTransfer( canceled, 'a' ), 'a' );
	assert.equal( applyUploadProgress( retry, 'a', { loaded: 100, total: 100, computable: true }, attempt ), retry );
	assert.equal( finishTransfer( retry, 'a', accepted, attempt ), retry );
} );
check( 'Late transfer events cannot regress a completed upload', () => {
	const complete = finishTransfer( sending, 'a', accepted, attempt );
	assert.equal( markTransferSent( complete, 'a', attempt ), complete );
	assert.equal( applyUploadProgress( complete, 'a', { loaded: 1, total: 100, computable: true }, attempt ), complete );
} );
check( 'Partial batch success is counted independently', () => {
	const other = createUploadEntry( { ...metadata, id: 'b', size: 0 }, 'images' );
	const summary = uploadSummary( [ ...finishTransfer( sending, 'a', accepted, attempt ), other ] );
	assert.equal( summary.complete, 1 ); assert.equal( summary.failed, 1 ); assert.equal( summary.total, 2 );
} );
for ( const [ language, text ] of [
	[ 'json', '{"value":"<script>alert(1)</script>","unicode":"日本語","ok":true}\n' ],
	[ 'html', '<!-- a\ncomment -->\n<img src=x onerror="alert(1)">\n' ],
	[ 'css', '/* a\ncomment */\nbody { color: #3858e9; margin: 12px; }\n' ],
	[ 'javascript', 'const value = "<script>";\n// comment\nreturn 12;\n' ],
	[ 'text', '<untrusted>plain text\n\n' ]
] ) {
	check( language + ' tokens preserve every source character without evaluating it', () => assert.equal( tokenizeCode( text, language ).map( token => token.text ).join( '' ), text ) );
	check( language + ' token lines retain trailing and empty lines', () => assert.equal( codeTokenLines( tokenizeCode( text, language ) ).map( line => line.map( token => token.text ).join( '' ) ).join( '\n' ), text ) );
}
check( 'Code position tracks a trailing newline correctly', () => assert.deepEqual( codePosition( 'a\n', 2 ), { line: 2, column: 1 } ) );
check( 'JSON formatting retains unknown document content for normal validation', () => {
	const input = '{"blocks":[{"type":"unknown","html":"keep me"}]}';
	assert.deepEqual( JSON.parse( formatJsonCode( input ).value ), JSON.parse( input ) );
} );
check( 'Invalid JSON is kept byte-for-byte when formatting is refused', () => {
	const input = '{"unfinished":';
	const result = formatJsonCode( input );
	assert.equal( result.ok, false ); assert.equal( result.value, input );
} );
console.log( count + ' administration widget model checks passed.' );
