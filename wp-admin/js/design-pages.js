/** Page selection and trusted editor lifecycle; the design form stays mounted. */
const CHANNEL = 'wp-next-design-editor';
let context;

initializeDesignPages();

function initializeDesignPages() {
	context = readPagesContext();
	if ( ! context ) { return; }
	document.body.classList.add( 'design-pages-enabled' );
	connectPageList(); connectPageEditor();
	readPageRows();
}
function connectPageList() {
	context.search.addEventListener( 'submit', event => { event.preventDefault(); loadPages( 1 ); } );
	context.search.addEventListener( 'input', () => { clearTimeout( context.timer ); context.timer = setTimeout( () => loadPages( 1 ), 250 ); } );
	context.search.addEventListener( 'change', () => loadPages( 1 ) );
	context.element.addEventListener( 'click', event => { const button = event.target.closest( '[data-page-select]' ); if ( button ) { selectPage( context.items.get( Number( button.dataset.pageSelect ) ) ); } } );
	context.element.querySelector( '[data-pages-previous]' ).addEventListener( 'click', event => { event.preventDefault(); loadPages( context.page - 1 ); } );
	context.element.querySelector( '[data-pages-next]' ).addEventListener( 'click', event => { event.preventDefault(); loadPages( context.page + 1 ); } );
	document.querySelector( '[data-page-edit]' ).addEventListener( 'click', () => guardedEditorAction( openPageEditor ) );
	document.querySelector( '[data-preview-homepage]' ).addEventListener( 'click', () => selectPage( null ) );
}
function connectPageEditor() {
	document.querySelector( '[data-page-editor-close]' ).addEventListener( 'click', () => guardedEditorAction( returnToPageList ) );
	context.dialog.querySelector( '[data-page-keep-editing]' ).addEventListener( 'click', () => context.dialog.close() );
	context.dialog.querySelector( '[data-page-discard-edits]' ).addEventListener( 'click', discardPageEdits );
	context.dialog.addEventListener( 'close', () => { context.pending = null; } );
	window.addEventListener( 'message', receivePageEditorMessage );
	document.addEventListener( 'design-preview-rendered', event => { context.previewHtml = event.detail.html; sendEditorStyles(); } );
	document.addEventListener( 'click', guardWorkspaceLink, true );
	context.designForm.addEventListener( 'submit', guardDesignSubmission, true );
	window.addEventListener( 'beforeunload', event => { if ( context.editorData?.dirty ) { event.preventDefault(); event.returnValue = ''; } } );
}
function receivePageEditorMessage( event ) {
	if ( event.origin !== location.origin || event.source !== context.frame?.contentWindow || event.data?.channel !== CHANNEL ) { return; }
	const data = event.data;
	if ( data.id !== context.selected?.id ) { return; }
	if ( data.type === 'close' ) { guardedEditorAction( returnToPageList ); return; }
	context.editorData = data;
	context.editor.querySelector( '[data-page-editor-title]' ).textContent = 'Editing ' + data.title;
	context.editor.querySelector( '[data-page-editor-status]' ).textContent = 'Page status: ' + data.status + '. Save Page applies that status; site design is published separately.';
	if ( data.type === 'ready' ) { sendEditorStyles(); if ( data.saved ) { pageEditorSaved( data ); } }
}
function pageEditorSaved( data ) {
	context.selected = { ...context.selected, title: data.title, status: data.status }; selectPage( context.selected );
	loadPages( context.page ); document.dispatchEvent( new Event( 'design-page-saved' ) );
}
async function loadPages( page ) {
	clearTimeout( context.timer ); context.controller?.abort();
	const sequence = ++context.sequence; context.controller = new AbortController();
	context.feedback.textContent = 'Loading Pages…'; context.error.hidden = true;
	try {
		const data = new FormData( context.search ); data.set( '_wpnonce', context.element.dataset.nonce ); data.set( 'design_action', 'list_pages' ); data.set( 'page', String( page ) );
		const response = await fetch( new URL( 'themes.php', location.href ), { method: 'POST', credentials: 'same-origin', redirect: 'error', body: data, signal: context.controller.signal } );
		if ( ! response.ok ) { throw new Error( 'The Page list could not load. Try searching again, or reload Site design if your session expired.' ); }
		const result = await response.json();
		if ( sequence === context.sequence ) { renderPages( result ); }
	} catch ( error ) { if ( sequence === context.sequence && error.name !== 'AbortError' ) { context.feedback.textContent = 'Page list unavailable'; context.error.textContent = error.message; context.error.hidden = false; } }
}
function renderPages( result ) {
	if ( ! Array.isArray( result.items ) ) { throw new Error( 'The server returned an invalid Page list.' ); }
	context.items = new Map( result.items.map( item => [ item.id, item ] ) );
	context.rows.replaceChildren( ...result.items.map( createPageRow ) );
	if ( ! result.items.length ) { const message = document.createElement( 'p' ); message.textContent = 'No Pages found. Clear or change the search and status filter.'; context.rows.append( message ); }
	context.page = result.page; context.feedback.textContent = formatPageCount( result.total );
	context.element.querySelector( '[data-pages-position]' ).textContent = 'Page ' + result.page + ' of ' + result.pages;
	context.element.querySelector( '[data-pages-previous]' ).disabled = result.page <= 1;
	context.element.querySelector( '[data-pages-next]' ).disabled = result.page >= result.pages;
	markPageSelection();
}
function createPageRow( item ) {
	const row = document.createElement( 'article' ); row.className = 'design-page-row'; row.dataset.pageId = item.id;
	const button = document.createElement( 'button' ); button.type = 'button'; button.className = 'design-page-select'; button.dataset.pageSelect = item.id; button.textContent = item.title;
	const status = document.createElement( 'span' ); status.className = 'design-page-status'; status.textContent = item.status;
	row.append( button, status ); return row;
}
function readPageRows() {
	context.element.querySelectorAll( '[data-page-id]' ).forEach( row => {
		const item = { id: Number( row.dataset.pageId ), title: row.dataset.title, status: row.dataset.status, editorUrl: row.dataset.editorUrl };
		context.items.set( item.id, item ); row.querySelector( 'button' ).dataset.pageSelect = item.id;
	} );
}
function selectPage( item ) {
	context.selected = item; markPageSelection();
	document.querySelector( '[data-preview-page-title]' ).textContent = item ? item.title + ' · ' + item.status : 'Site homepage';
	document.querySelector( '[data-page-edit]' ).hidden = ! item;
	document.querySelector( '[data-preview-homepage]' ).hidden = ! item;
	document.dispatchEvent( new CustomEvent( 'design-page-selected', { detail: item } ) );
}
function markPageSelection() {
	context.rows.querySelectorAll( '[data-page-id]' ).forEach( row => {
		const selected = Number( row.dataset.pageId ) === context.selected?.id;
		row.dataset.selected = String( selected ); row.querySelector( 'button' )?.setAttribute( 'aria-pressed', String( selected ) );
	} );
}
function openPageEditor() {
	if ( ! context.selected ) { return; }
	closePageEditor();
	const address = new URL( context.selected.editorUrl, location.href );
	if ( address.origin !== location.origin || ! address.pathname.endsWith( '/wp-admin/post.php' ) ) { return; }
	context.editor.querySelector( '.design-page-editor-error' ).hidden = true;
	context.frame = document.createElement( 'iframe' ); context.frame.title = 'Visual editor for ' + context.selected.title;
	context.frame.src = address.href; context.editor.hidden = false;
	document.body.classList.add( 'design-page-editing' );
	context.editor.querySelector( '[data-page-editor-title]' ).textContent = 'Editing ' + context.selected.title;
	context.editor.querySelector( '.design-page-editor-host' ).append( context.frame );
	context.frame.addEventListener( 'load', checkEditorLoad );
	context.editor.querySelector( '[data-page-editor-close]' ).focus();
}
function returnToPageList() {
	closePageEditor();
	document.querySelector( '[data-design-target=pages]' ).click();
	context.rows.querySelector( '[data-page-id="' + context.selected?.id + '"] button' )?.focus();
}
function checkEditorLoad() {
	try { if ( ! context.frame?.contentDocument?.querySelector( '[data-design-embed="1"]' ) ) { throw new Error( 'Editor unavailable.' ); }
	} catch ( error ) { const notice = context.editor.querySelector( '.design-page-editor-error' ); notice.textContent = 'The Page editor could not load. Return to the list and retry, or reload Site design to restore access.'; notice.hidden = false; }
}
function sendEditorStyles() {
	if ( context.frame && context.previewHtml ) { context.frame.contentWindow.postMessage( { channel: CHANNEL, type: 'styles', html: context.previewHtml }, location.origin ); }
}
function discardPageEdits() {
	try { if ( context.editorData?.draftKey ) { sessionStorage.removeItem( context.editorData.draftKey ); } }
	catch ( error ) { showPageDiscardError(); return; }
	const action = context.pending; context.editorData = null;
	context.dialog.close(); closePageEditor(); action?.();
}
function showPageDiscardError() {
	const message = context.dialog.querySelector( '[data-page-discard-error]' );
	message.hidden = false;
	message.textContent = 'Browser draft storage is unavailable. Keep editing and save this Page before leaving.';
}
function guardWorkspaceLink( event ) {
	if ( ! context.frame || ! event.target.closest?.( '[data-design-target],.design-exit,.design-code-link' ) ) { return; }
	const link = event.target.closest( 'a' ); event.preventDefault(); event.stopImmediatePropagation();
	guardedEditorAction( () => { closePageEditor(); link.click(); } );
}
function guardDesignSubmission( event ) {
	if ( ! context.editorData?.dirty ) { return; }
	event.preventDefault(); event.stopImmediatePropagation();
	const submitter = event.submitter;
	guardedEditorAction( () => { closePageEditor(); context.designForm.requestSubmit( submitter ); } );
}
function guardedEditorAction( action ) {
	if ( ! context.editorData?.dirty ) { action(); return; }
	context.pending = action; context.dialog.querySelector( '[data-page-discard-error]' ).hidden = true; context.dialog.showModal();
}
function closePageEditor() {
	context.frame?.remove(); context.frame = null; context.editorData = null;
	context.editor.hidden = true; document.body.classList.remove( 'design-page-editing' );
}
function readPagesContext() {
	const element = document.querySelector( '[data-design-pages]' );
	if ( ! element ) { return null; }
	return { element, search: element.querySelector( 'form' ), rows: element.querySelector( '.design-pages-items' ), feedback: element.querySelector( '.design-pages-feedback' ), error: element.querySelector( '.design-pages-error' ), designForm: document.getElementById( 'design-form' ), editor: document.querySelector( '.design-page-editor-region' ), dialog: document.querySelector( '.design-page-discard' ), items: new Map(), page: Number( element.dataset.page ), sequence: 0, selected: null, frame: null, editorData: null, pending: null, previewHtml: document.querySelector( '.design-preview-viewport iframe' )?.srcdoc || '', controller: null, timer: null };
}
function formatPageCount( total ) {
	return total + ( total === 1 ? ' Page' : ' Pages' );
}
