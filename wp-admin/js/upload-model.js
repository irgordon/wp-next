/** Upload decisions are independent of the DOM and HTTP transport. */
export const UPLOAD_PHASE = Object.freeze( { queued: 'queued', rejected: 'rejected', uploading: 'uploading', processing: 'processing', complete: 'complete', failed: 'failed', canceled: 'canceled' } );
const EMPTY = '';
const RULES = Object.freeze( {
	images: { limit: 10485760, extensions: [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], mimes: [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], sizeError: 'Images must be 10 MB or smaller.', typeError: 'Choose a JPEG, PNG, WebP or GIF image.' },
	documents: { limit: 26214400, extensions: [ 'pdf', 'txt', 'md', 'csv' ], sizeError: 'Documents must be 25 MB or smaller.', typeError: 'Choose a PDF, text, Markdown or CSV file.' }
} );
const MESSAGE = Object.freeze( { empty: 'This file is empty. Choose a file with content.', network: 'The connection was interrupted. Try this file again.', timeout: 'The upload took too long. Try this file again.', abort: 'Transfer canceled. Check the library before retrying if processing had started.', session: 'Your session may have expired. Reload this page, then retry.', response: 'The server did not confirm this upload. Refresh the library before retrying.' } );
const TRANSPORT_FAILURES = Object.freeze( { error: MESSAGE.network, timeout: MESSAGE.timeout, abort: MESSAGE.abort } );

export function createUploadEntry( metadata, kind ) {
	const error = validateSelection( metadata, kind );
	return { ...metadata, status: error ? UPLOAD_PHASE.rejected : UPLOAD_PHASE.queued, progress: 0, loaded: 0, total: 0, message: error, assetId: null, attempt: 0 };
}
export function startTransfer( entries, id ) {
	const entry = findEntry( entries, id );
	if ( ! entry || entry.status !== UPLOAD_PHASE.queued ) { return entries; }
	return replaceEntry( entries, { ...entry, status: UPLOAD_PHASE.uploading, progress: 0, loaded: 0, total: 0, message: EMPTY, attempt: entry.attempt + 1 } );
}
export function applyUploadProgress( entries, id, progress, attempt ) {
	const entry = findAttempt( entries, id, attempt );
	if ( ! entry || entry.status !== UPLOAD_PHASE.uploading ) { return entries; }
	const percent = transferPercent( progress.loaded, progress.total, progress.computable );
	return replaceEntry( entries, { ...entry, progress: percent, loaded: progress.loaded, total: progress.total } );
}
export function markTransferSent( entries, id, attempt ) {
	const entry = findAttempt( entries, id, attempt );
	if ( ! entry || entry.status !== UPLOAD_PHASE.uploading ) { return entries; }
	return replaceEntry( entries, { ...entry, status: UPLOAD_PHASE.processing, progress: 100 } );
}
export function finishTransfer( entries, id, transport, attempt ) {
	const result = interpretUploadResult( transport );
	return applyUploadResult( entries, id, result, attempt );
}
export function retryTransfer( entries, id ) {
	const entry = findEntry( entries, id );
	if ( ! isRetryable( entry ) ) { return entries; }
	return replaceEntry( entries, { ...entry, status: UPLOAD_PHASE.queued, progress: 0, message: EMPTY, assetId: null } );
}
export function validateSelection( file, kind ) {
	const rule = RULES[ kind ] || RULES.images;
	if ( ! positiveFileSize( file.size ) ) { return MESSAGE.empty; }
	if ( file.size > rule.limit ) { return rule.sizeError; }
	if ( ! acceptedType( file, rule ) ) { return rule.typeError; }
	return EMPTY;
}
function interpretUploadResult( transport ) {
	return { failure: TRANSPORT_FAILURES[ transport.kind ] || EMPTY, canceled: transport.kind === 'abort', payload: parseResponse( transport.body ), status: transport.status, login: looksLikeLogin( transport ) };
}
function applyUploadResult( entries, id, result, attempt ) {
	const entry = findAttempt( entries, id, attempt );
	if ( ! isTransferActive( entry ) ) { return entries; }
	const outcome = chooseUploadOutcome( result );
	return replaceEntry( entries, { ...entry, ...outcome } );
}
function chooseUploadOutcome( result ) {
	if ( result.failure ) { return { status: result.canceled ? UPLOAD_PHASE.canceled : UPLOAD_PHASE.failed, message: result.failure }; }
	if ( acceptedResponse( result ) ) { return { status: UPLOAD_PHASE.complete, progress: 100, assetId: result.payload.id, message: EMPTY }; }
	return { status: UPLOAD_PHASE.failed, message: responseMessage( result ) };
}
export function nextQueuedEntry( entries ) {
	return entries.find( entry => entry.status === UPLOAD_PHASE.queued ) || null;
}
export function uploadSummary( entries ) {
	return {
		total: entries.length,
		complete: entries.filter( entry => entry.status === UPLOAD_PHASE.complete ).length,
		failed: entries.filter( entry => entry.status === UPLOAD_PHASE.failed || entry.status === UPLOAD_PHASE.rejected ).length,
		queued: entries.filter( entry => entry.status === UPLOAD_PHASE.queued ).length,
		active: entries.some( isTransferActive )
	};
}
export function isRetryable( entry ) {
	return Boolean( entry && [ UPLOAD_PHASE.failed, UPLOAD_PHASE.canceled ].includes( entry.status ) );
}
export function isTransferActive( entry ) {
	return Boolean( entry && [ UPLOAD_PHASE.uploading, UPLOAD_PHASE.processing ].includes( entry.status ) );
}
export function removeQueueEntry( entries, id ) {
	return entries.filter( entry => entry.id !== id );
}
function responseMessage( result ) {
	if ( result.login ) { return MESSAGE.session; }
	return typeof result.payload?.error === 'string' ? result.payload.error : MESSAGE.response;
}
function acceptedResponse( result ) {
	return result.status >= 200 && result.status < 300 && result.payload?.ok === true && Number.isInteger( result.payload.id ) && result.payload.id > 0;
}
function acceptedType( file, rule ) {
	if ( rule.mimes?.includes( file.type ) ) { return true; }
	return rule.extensions.includes( filenameExtension( file.name ) );
}
function positiveFileSize( value ) {
	return Number.isFinite( value ) && value > 0;
}
function filenameExtension( value ) {
	return value.slice( value.lastIndexOf( '.' ) + 1 ).toLowerCase();
}
function transferPercent( loaded, total, computable ) {
	if ( ! computable || total <= 0 ) { return null; }
	return Math.min( 100, Math.max( 0, Math.floor( loaded * 100 / total ) ) );
}
function looksLikeLogin( result ) {
	return [ 401, 403 ].includes( result.status ) || String( result.url || EMPTY ).includes( '/login.php' );
}
function parseResponse( text ) {
	try { return JSON.parse( text ); } catch ( error ) { return null; }
}
function findEntry( entries, id ) {
	return entries.find( entry => entry.id === id );
}
function findAttempt( entries, id, attempt ) {
	return entries.find( entry => entry.id === id && entry.attempt === attempt );
}
function replaceEntry( entries, replacement ) {
	return entries.map( entry => entry.id === replacement.id ? replacement : entry );
}
