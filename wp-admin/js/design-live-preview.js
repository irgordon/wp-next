/** Preview transport and frame presentation; it never submits a persistence action. */
const DEBOUNCE_MS = 300;
const REQUEST_TIMEOUT_MS = 15000;
const PREVIEW_WIDTH = Object.freeze( { desktop: 1440, mobile: 390 } );
const READ_ONLY_EVENTS = [ 'click', 'submit', 'dragstart', 'dragover', 'drop' ];

initializeLiveDesignPreview();

function initializeLiveDesignPreview() {
	const context = readPreviewContext();
	if ( ! context ) { return; }
	presentPreview( context );
	connectPreview( context );
	queuePreview( context, true );
}
function presentPreview( context ) {
	context.element.hidden = false;
	document.body.classList.add( 'design-live-enabled' );
	context.frame.title = 'Private live site preview';
	context.frame.setAttribute( 'sandbox', 'allow-same-origin' );
	context.frame.tabIndex = -1;
	context.frame.addEventListener( 'load', () => loadPreviewFrame( context ) );
}
function connectPreview( context ) {
	context.form.addEventListener( 'design-form-updated', () => queuePreview( context ) );
	context.form.addEventListener( 'submit', () => cancelPreviewRequest( context ) );
	context.element.querySelector( '[data-preview-refresh]' ).addEventListener( 'click', () => queuePreview( context, true ) );
	context.element.querySelectorAll( '[data-preview-device]' ).forEach( button => button.addEventListener( 'click', () => selectPreviewDevice( context, button ) ) );
	document.querySelectorAll( '[data-preview-mode-button]' ).forEach( button => button.addEventListener( 'click', () => selectPreviewMode( context, button ) ) );
	document.querySelectorAll( '[data-design-target]' ).forEach( link => link.addEventListener( 'click', () => selectPreviewMode( context, document.querySelector( '[data-preview-mode-button=controls]' ) ) ) );
	new ResizeObserver( () => resizePreviewFrame( context ) ).observe( context.viewport );
}
function queuePreview( context, force = false ) {
	const signature = previewFormSignature( context.form );
	if ( ! force && signature === context.signature ) { return; }
	context.signature = signature;
	context.target = previewTarget( context.form );
	cancelPreviewRequest( context );
	const sequence = ++context.sequence;
	setPreviewPending( context );
	context.timer = setTimeout( () => requestPreview( context, sequence ), DEBOUNCE_MS );
}
async function requestPreview( context, sequence ) {
	context.controller = new AbortController();
	const controller = context.controller;
	const timeout = setTimeout( () => { controller.timedOut = true; controller.abort(); }, REQUEST_TIMEOUT_MS );
	try {
		const payload = await fetchPreviewPayload( context, controller.signal );
		if ( sequence === context.sequence ) { applyPreviewPayload( context, payload, sequence ); }
	} catch ( error ) {
		if ( sequence === context.sequence && ( ! controller.signal.aborted || controller.timedOut ) ) { setPreviewError( context, controller.timedOut ? 'The preview timed out. Try again.' : error.message ); }
	} finally { clearTimeout( timeout ); if ( sequence === context.sequence ) { context.controller = null; } }
}
async function fetchPreviewPayload( context, signal ) {
	const data = new FormData( context.form );
	data.set( 'design_action', 'live_preview' ); data.set( '_wpnonce', context.element.dataset.nonce );
	const response = await fetch( new URL( context.element.dataset.endpoint, location.href ), { method: 'POST', credentials: 'same-origin', redirect: 'error', headers: { Accept: 'application/json' }, body: data, signal } );
	const payload = await readPreviewJson( response );
	if ( ! response.ok || payload.ok !== true ) { throw new Error( typeof payload.error === 'string' ? payload.error : 'The private preview could not update. Reload Site design if your session expired.' ); }
	if ( typeof payload.html !== 'string' || typeof payload.renderId !== 'string' || ! payload.renderId || ! payload.html ) { throw new Error( 'The server did not return a valid private preview.' ); }
	return payload;
}
async function readPreviewJson( response ) {
	try {
		const payload = await response.json();
		if ( ! payload || typeof payload !== 'object' || Array.isArray( payload ) ) { throw new Error( 'Invalid preview envelope.' ); }
		return payload;
	}
	catch ( error ) { throw new Error( 'The private preview could not load. Your edits remain in the controls; reload Site design if your session expired.' ); }
}
function applyPreviewPayload( context, payload, sequence ) {
	context.scrollTop = context.frameTarget === context.target ? ( context.frame.contentDocument?.scrollingElement?.scrollTop ?? context.scrollTop ) : 0;
	context.frameTarget = context.target;
	context.renderId = payload.renderId; context.frameSequence = sequence;
	context.frame.srcdoc = payload.html;
	if ( ! context.frame.isConnected ) { context.viewport.append( context.frame ); }
	context.frame.dataset.theme = payload.theme;
}
function loadPreviewFrame( context ) {
	const document = context.frame.contentDocument;
	if ( document?.querySelector( 'meta[name=wp-design-preview-id]' )?.content !== context.renderId ) { return; }
	lockPreviewInteractions( document );
	resizePreviewFrame( context );
	completePreviewFrame( context, document, context.renderId, context.frameSequence );
}
async function completePreviewFrame( context, document, renderId, sequence ) {
	await document.fonts.ready;
	await new Promise( resolve => requestAnimationFrame( resolve ) );
	if ( renderId !== context.renderId || sequence !== context.sequence ) { return; }
	context.frame.contentWindow.scrollTo( 0, context.scrollTop );
	markPreviewReady( context );
}
function markPreviewReady( context ) {
	context.element.dataset.current = 'true'; context.viewport.setAttribute( 'aria-busy', 'false' );
	context.status.textContent = 'Private preview · up to date';
	context.error.hidden = true;
}
function lockPreviewInteractions( document ) {
	READ_ONLY_EVENTS.forEach( name => document.addEventListener( name, event => event.preventDefault(), true ) );
	document.querySelectorAll( 'input,textarea' ).forEach( input => input.readOnly = true );
	document.querySelectorAll( 'a,button,input,select,textarea,summary,[tabindex],[contenteditable]' ).forEach( element => element.setAttribute( 'tabindex', '-1' ) );
}
function selectPreviewDevice( context, button ) {
	context.width = Number( button.dataset.previewDevice );
	context.element.querySelectorAll( '[data-preview-device]' ).forEach( item => item.setAttribute( 'aria-pressed', String( item === button ) ) );
	resizePreviewFrame( context );
}
function selectPreviewMode( context, button ) {
	context.layout.dataset.previewMode = button.dataset.previewModeButton;
	document.querySelectorAll( '[data-preview-mode-button]' ).forEach( item => item.setAttribute( 'aria-pressed', String( item === button ) ) );
	resizePreviewFrame( context );
}
function resizePreviewFrame( context ) {
	const available = context.viewport.clientWidth;
	if ( ! available ) { return; }
	const scale = Math.min( 1, available / context.width );
	context.frame.style.width = context.width + 'px';
	context.frame.style.height = Math.ceil( context.viewport.clientHeight / scale ) + 'px';
	context.frame.style.left = ( available - context.width * scale ) / 2 + 'px';
	context.frame.style.transform = 'scale(' + scale + ')';
}
function setPreviewPending( context ) {
	context.element.dataset.current = 'false'; context.viewport.setAttribute( 'aria-busy', 'true' );
	context.status.textContent = 'Updating private preview…'; context.error.hidden = true;
}
function setPreviewError( context, message ) {
	context.viewport.setAttribute( 'aria-busy', 'false' ); context.element.dataset.current = 'false';
	context.status.textContent = context.frame.isConnected ? 'Last valid preview · not current' : 'Private preview unavailable';
	context.error.textContent = message; context.error.hidden = false;
}
function cancelPreviewRequest( context ) {
	clearTimeout( context.timer ); context.controller?.abort();
	context.sequence++;
}
function readPreviewContext() {
	const element = document.querySelector( '[data-design-preview]' ), form = document.getElementById( 'design-form' );
	if ( ! element || ! form ) { return null; }
	return { element, form, viewport: element.querySelector( '.design-preview-viewport' ), layout: document.querySelector( '.design-live-layout' ), status: element.querySelector( '.design-preview-status' ), error: element.querySelector( '.design-preview-error' ), frame: document.createElement( 'iframe' ), width: PREVIEW_WIDTH.desktop, sequence: 0, frameSequence: -1, signature: null, controller: null, timer: null, renderId: null, scrollTop: 0, target: null, frameTarget: null };
}
function previewFormSignature( form ) {
	return new URLSearchParams( new FormData( form ) ).toString();
}

function previewTarget( form ) {
	return ( form.querySelector( '[name=active_theme]:checked' )?.value || '' ) + '|' + ( form.elements.namedItem( 'homepage_id' )?.value || '0' );
}
