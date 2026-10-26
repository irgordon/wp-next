/** File-queue presentation; the model owns states and the transport owns XHR. */
import { createUploadEntry, startTransfer, applyUploadProgress, markTransferSent, finishTransfer, retryTransfer, nextQueuedEntry, uploadSummary, isRetryable, isTransferActive, removeQueueEntry, UPLOAD_PHASE } from './upload-model.js';
import { createUploadRequest } from './upload-transport.js';

const PHASE_LABEL = Object.freeze( { queued: 'Ready', rejected: 'Invalid file', uploading: 'Uploading', processing: 'Processing…', complete: 'Uploaded', failed: 'Upload failed', canceled: 'Canceled' } );
const CLOUD_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M8 18H6a4 4 0 0 1-.6-7.96A6.5 6.5 0 0 1 18 8.5a4.8 4.8 0 0 1 .4 9.5H16"/><path d="m8.5 13 3.5-3.5 3.5 3.5M12 10v10"/></svg>';
const CARD_MARKUP = [
	'<div class="upload-card"><div class="upload-card-heading"><strong class="upload-card-label">Upload files</strong><span class="upload-summary" role="status" aria-live="polite">No files selected</span></div>',
	'<div class="upload-dropzone" tabindex="0" role="group" aria-label="File drop zone"><span class="upload-cloud">' + CLOUD_ICON + '</span><strong>Drag files here</strong><span>or <button class="upload-browse" type="button">browse files</button></span><small class="upload-accepted"></small></div>',
	'<p class="upload-feedback" role="alert" hidden></p><ul class="upload-queue" aria-label="Upload queue"></ul>',
	'<div class="upload-controls"><div class="upload-options"></div><div class="upload-submit"></div></div><p class="upload-refresh-message" role="status" aria-live="polite"></p></div>'
].join( '' );
const ROW_MARKUP = '<span class="upload-file-icon" aria-hidden="true">▤</span><div class="upload-file-main"><div class="upload-file-heading"><strong class="upload-file-name"></strong><span class="upload-percent" aria-hidden="true"><span class="upload-percent-number">0</span><span>%</span></span></div><div class="upload-file-meta"><span class="upload-file-size"></span><span class="upload-file-state"></span></div><progress max="100" value="0"></progress><p class="upload-file-error" hidden></p></div><div class="upload-file-actions"><button type="button" data-upload-action="retry">↻</button><button type="button" data-upload-action="remove">×</button></div>';
let uploadSerial = 0;

export function enhanceUploadForms( root = document ) {
	root.querySelectorAll( 'form[data-xhr-upload]:not([data-upload-enhanced])' ).forEach( mountUploadForm );
}
function mountUploadForm( form ) {
	const context = createUploadContext( form );
	presentUploadForm( context );
	connectUploadForm( context );
}
function createUploadContext( form ) {
	return { form, input: form.querySelector( 'input[type=file]' ), submit: form.querySelector( 'button[type=submit]' ), kind: form.dataset.uploadKind || 'images', element: fragmentElement( CARD_MARKUP ), entries: [], files: new Map(), rows: new Map(), running: false, request: null, librarySequence: 0 };
}
function presentUploadForm( context ) {
	context.form.dataset.uploadEnhanced = 'true';
	context.form.classList.add( 'upload-enhanced' ); context.form.noValidate = true;
	context.input.classList.add( 'upload-native-input' ); context.input.tabIndex = -1;
	context.form.querySelector( 'label[for="' + context.input.id + '"]' )?.classList.add( 'upload-native-label' );
	context.form.append( context.element );
	presentUploadControls( context );
	const documents = context.kind === 'documents';
	find( context, '.upload-card-label' ).textContent = documents ? 'Document upload' : 'Image upload';
	find( context, '.upload-accepted' ).textContent = documents ? 'PDF, TXT, Markdown or CSV · up to 25 MB each' : 'JPEG, PNG, WebP or GIF · up to 10 MB each';
}
function presentUploadControls( context ) {
	find( context, '.upload-submit' ).append( context.submit );
	const visibility = context.form.querySelector( '[name=visibility]' );
	if ( visibility ) {
		find( context, '.upload-options' ).append( context.form.querySelector( 'label[for="' + visibility.id + '"]' ), visibility );
	}
}
function connectUploadForm( context ) {
	context.input.addEventListener( 'change', () => { addSelectedFiles( context, Array.from( context.input.files ) ); context.input.value = ''; } );
	context.form.addEventListener( 'submit', event => { event.preventDefault(); processUploadQueue( context ); } );
	find( context, '.upload-browse' ).addEventListener( 'click', () => context.input.click() );
	wireFileDrop( context );
	context.element.addEventListener( 'click', event => handleQueueAction( context, event ) );
	context.form.closest( '.upload-panel' ).querySelectorAll( '[data-browse-upload]' ).forEach( button => button.addEventListener( 'click', () => context.input.click() ) );
}
function addSelectedFiles( context, files ) {
	for ( const file of files ) {
		const id = 'upload-' + ++uploadSerial;
		const entry = createUploadEntry( { id, name: file.name, size: file.size, type: file.type }, context.kind );
		context.files.set( id, file ); context.entries.push( entry );
	}
	setFeedback( context, files.length ? '' : 'Choose at least one file to upload.' );
	renderUploadQueue( context );
}
async function processUploadQueue( context ) {
	const batch = prepareUploadBatch( context );
	await drainUploadBatch( context, batch );
	await completeUploadBatch( context, batch );
}
function prepareUploadBatch( context ) {
	if ( context.running ) { return null; }
	if ( ! nextQueuedEntry( context.entries ) ) { setFeedback( context, context.entries.length ? 'Choose a ready file or retry a failed upload.' : 'Choose at least one file to upload.' ); return null; }
	context.running = true;
	const batch = { url: uploadEndpoint( context.form ), fields: captureFormFields( context.form ) };
	context.form.closest( '.wrap' )?.querySelector( '.notice-success' )?.remove();
	setFeedback( context, '' ); setUploadControls( context, true );
	renderUploadQueue( context );
	return batch;
}
async function drainUploadBatch( context, batch ) {
	if ( ! batch ) { return; }
	let entry = nextQueuedEntry( context.entries );
	while ( entry ) {
		await transferOneFile( context, batch, entry );
		entry = nextQueuedEntry( context.entries );
	}
}
async function completeUploadBatch( context, batch ) {
	if ( ! batch ) { return; }
	context.running = false; setUploadControls( context, false );
	renderUploadQueue( context );
	await refreshMediaLibrary( context );
}
async function transferOneFile( context, batch, entry ) {
	context.entries = startTransfer( context.entries, entry.id );
	const attempt = context.entries.find( item => item.id === entry.id ).attempt;
	renderUploadQueue( context );
	const callbacks = {
		progress: progress => { context.entries = applyUploadProgress( context.entries, entry.id, progress, attempt ); renderUploadQueue( context ); },
		sent: () => { context.entries = markTransferSent( context.entries, entry.id, attempt ); renderUploadQueue( context ); }
	};
	const request = createUploadRequest( batch.url, multipartForFile( batch.fields, context.input.name, context.files.get( entry.id ) ), callbacks );
	context.request = { id: entry.id, abort: request.abort };
	const outcome = await request.result;
	context.entries = finishTransfer( context.entries, entry.id, outcome, attempt );
	context.request = null; renderUploadQueue( context );
}
function handleQueueAction( context, event ) {
	const button = event.target.closest( '[data-upload-action]' );
	if ( ! button ) { return; }
	const id = button.closest( '.upload-file-row' ).dataset.uploadId;
	if ( button.dataset.uploadAction === 'retry' ) { retryFile( context, id ); }
	else { removeOrCancelFile( context, id ); }
}
function retryFile( context, id ) {
	context.entries = retryTransfer( context.entries, id );
	renderUploadQueue( context );
	processUploadQueue( context );
}
function removeOrCancelFile( context, id ) {
	const entry = context.entries.find( item => item.id === id );
	if ( isTransferActive( entry ) ) { context.request?.abort(); return; }
	context.entries = removeQueueEntry( context.entries, id );
	context.files.delete( id ); renderUploadQueue( context );
}
function renderUploadQueue( context ) {
	const list = find( context, '.upload-queue' );
	for ( const entry of context.entries ) {
		let row = context.rows.get( entry.id );
		if ( ! row ) { row = createUploadRow( entry ); context.rows.set( entry.id, row ); list.append( row ); }
		updateUploadRow( row, entry );
	}
	removeDetachedRows( context );
	updateUploadSummary( context );
}
function updateUploadSummary( context ) {
	const summary = uploadSummary( context.entries );
	const label = summary.total ? summary.complete + ' of ' + summary.total + ' files uploaded' + ( summary.failed ? ' · ' + summary.failed + ' need attention' : '' ) : 'No files selected';
	setText( find( context, '.upload-summary' ), label );
	context.submit.disabled = context.running || ( summary.total > 0 && summary.queued === 0 );
	context.form.setAttribute( 'aria-busy', String( context.running ) );
}
function createUploadRow( entry ) {
	const row = document.createElement( 'li' );
	row.className = 'upload-file-row'; row.dataset.uploadId = entry.id; row.innerHTML = ROW_MARKUP;
	row.querySelector( '.upload-file-name' ).textContent = entry.name;
	row.querySelector( '.upload-file-name' ).title = entry.name;
	row.querySelector( '.upload-file-size' ).textContent = readableBytes( entry.size );
	row.querySelector( 'progress' ).setAttribute( 'aria-label', entry.name + ' upload progress' );
	row.querySelector( '[data-upload-action=retry]' ).setAttribute( 'aria-label', 'Retry ' + entry.name );
	return row;
}
function updateUploadRow( row, entry ) {
	row.dataset.uploadState = entry.status;
	setText( row.querySelector( '.upload-file-state' ), PHASE_LABEL[ entry.status ] );
	const error = row.querySelector( '.upload-file-error' );
	setText( error, entry.message ); error.hidden = ! entry.message;
	row.querySelector( '[data-upload-action=retry]' ).hidden = ! isRetryable( entry );
	updateRemoveControl( row, entry );
	updateProgress( row, entry );
}
function updateRemoveControl( row, entry ) {
	const button = row.querySelector( '[data-upload-action=remove]' );
	const active = entry.status === UPLOAD_PHASE.uploading;
	button.textContent = active ? '■' : '×';
	button.disabled = entry.status === UPLOAD_PHASE.processing;
	button.setAttribute( 'aria-label', active ? 'Cancel ' + entry.name + ' transfer' : 'Remove ' + entry.name + ' from queue' );
	button.title = active ? 'Cancel transfer' : 'Remove from queue';
}
function updateProgress( row, entry ) {
	const progress = row.querySelector( 'progress' );
	if ( entry.progress === null ) { progress.removeAttribute( 'value' ); }
	else { progress.value = entry.progress; }
	progress.setAttribute( 'aria-valuetext', entry.progress === null ? PHASE_LABEL[ entry.status ] : entry.progress + '% transferred · ' + PHASE_LABEL[ entry.status ] );
	const counter = row.querySelector( '.upload-percent-number' );
	const text = entry.progress === null ? '…' : String( entry.progress );
	if ( counter.textContent !== text ) { counter.textContent = text; animatePercent( counter ); }
	row.querySelector( '.upload-percent > span:last-child' ).hidden = entry.progress === null;
	row.querySelector( '.upload-percent' ).hidden = [ UPLOAD_PHASE.failed, UPLOAD_PHASE.rejected, UPLOAD_PHASE.canceled ].includes( entry.status );
}
function removeDetachedRows( context ) {
	const ids = new Set( context.entries.map( entry => entry.id ) );
	for ( const [ id, row ] of context.rows ) {
		if ( ! ids.has( id ) ) { row.remove(); context.rows.delete( id ); }
	}
}
function wireFileDrop( context ) {
	const zone = find( context, '.upload-dropzone' );
	zone.addEventListener( 'keydown', event => { if ( event.target === zone && [ 'Enter', ' ' ].includes( event.key ) ) { event.preventDefault(); context.input.click(); } } );
	zone.addEventListener( 'dragover', event => { event.preventDefault(); zone.classList.add( 'is-dragging' ); } );
	zone.addEventListener( 'dragleave', event => { if ( ! zone.contains( event.relatedTarget ) ) { zone.classList.remove( 'is-dragging' ); } } );
	zone.addEventListener( 'drop', event => { event.preventDefault(); zone.classList.remove( 'is-dragging' ); addSelectedFiles( context, Array.from( event.dataTransfer.files ) ); } );
}
async function refreshMediaLibrary( context ) {
	const sequence = ++context.librarySequence;
	try {
		const response = await fetch( location.href, { credentials: 'same-origin' } );
		if ( ! response.ok ) { throw new Error( 'Library unavailable.' ); }
		const parsed = new DOMParser().parseFromString( await response.text(), 'text/html' );
		if ( sequence !== context.librarySequence ) { return; }
		replaceLibraryRegions( parsed );
		setText( find( context, '.upload-refresh-message' ), 'Library updated. Successful files are available below.' );
	} catch ( error ) { if ( sequence === context.librarySequence ) { setText( find( context, '.upload-refresh-message' ), 'Refresh this page to confirm the latest library contents. Files already marked uploaded remain saved.' ); } }
}
function replaceLibraryRegions( parsed ) {
	if ( ! parsed.querySelector( '[data-media-library]' ) ) { throw new Error( 'Library unavailable.' ); }
	for ( const selector of [ '[data-media-library]', '.storage-summary' ] ) {
		const replacement = parsed.querySelector( selector );
		if ( replacement ) { document.querySelector( selector )?.replaceWith( document.importNode( replacement, true ) ); }
	}
}
function setUploadControls( context, busy ) {
	context.submit.disabled = busy;
	context.form.querySelectorAll( 'select' ).forEach( select => { select.disabled = busy; } );
}
function setFeedback( context, message ) {
	const feedback = find( context, '.upload-feedback' );
	setText( feedback, message ); feedback.hidden = ! message;
}
function captureFormFields( form ) {
	return Array.from( new FormData( form ) ).filter( ( [ , value ] ) => typeof value === 'string' );
}
function multipartForFile( fields, name, file ) {
	const data = new FormData();
	fields.forEach( ( [ key, value ] ) => data.append( key, value ) );
	data.set( 'ajax', '1' ); data.append( name, file, file.name );
	return data;
}
function animatePercent( element ) {
	if ( matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) { return; }
	element.getAnimations().forEach( animation => animation.cancel() );
	element.animate( [ { transform: 'translateY(6px)', opacity: 0.4 }, { transform: 'translateY(0)', opacity: 1 } ], { duration: 180, easing: 'cubic-bezier(.22,1,.36,1)' } );
}
function readableBytes( value ) {
	return value >= 1048576 ? ( value / 1048576 ).toFixed( 1 ) + ' MB' : Math.ceil( value / 1024 ) + ' KB';
}
function setText( element, value ) {
	if ( element.textContent !== value ) { element.textContent = value; }
}
function fragmentElement( markup ) {
	const template = document.createElement( 'template' ); template.innerHTML = markup; return template.content.firstElementChild;
}
function find( context, selector ) {
	return context.element.querySelector( selector );
}
function uploadEndpoint( form ) {
	return new URL( form.getAttribute( 'action' ) || location.href, location.href ).href;
}
