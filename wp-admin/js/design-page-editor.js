/** Bridge for the explicitly authorized same-origin admin editor, never the public preview. */
const CHANNEL = 'wp-next-design-editor';
const form = document.getElementById( 'post-editor' );
let previewHtml = '';

initializeEditorBridge();

function initializeEditorBridge() {
	if ( form?.dataset.designEmbed !== '1' || parent === window ) { return; }
	document.body.classList.add( 'design-embedded-editor' );
	new MutationObserver( () => notifyEditor( 'state' ) ).observe( document.getElementById( 'editor-state' ), { childList: true, subtree: true } );
	new MutationObserver( bindEditorCanvas ).observe( document.body, { childList: true, subtree: true } );
	window.addEventListener( 'message', receiveEditorMessage );
	document.addEventListener( 'click', interceptEditorLinks, true );
	bindEditorCanvas();
}
function receiveEditorMessage( event ) {
	if ( event.origin !== location.origin || event.source !== parent || event.data?.channel !== CHANNEL ) { return; }
	if ( event.data.type === 'styles' && typeof event.data.html === 'string' ) { previewHtml = event.data.html; applyEditorStyles(); }
}
function interceptEditorLinks( event ) {
	const link = event.target.closest?.( 'a' );
	if ( ! link || link.target === '_blank' ) { return; }
	event.preventDefault(); event.stopImmediatePropagation(); notifyEditor( 'close' );
}
function bindEditorCanvas() {
	const canvas = document.getElementById( 'builder-canvas-frame' );
	if ( ! canvas || canvas.dataset.designBound ) { return; }
	canvas.dataset.designBound = '1'; canvas.addEventListener( 'load', applyEditorStyles );
	document.querySelector( '.builder-save button[type=submit]' )?.replaceChildren( document.createTextNode( 'Save Page' ) );
	requestAnimationFrame( () => notifyEditor( 'ready' ) );
	applyEditorStyles();
}
function applyEditorStyles() {
	const canvas = document.getElementById( 'builder-canvas-frame' )?.contentDocument;
	if ( ! previewHtml || ! canvas?.querySelector( '#canvas-blocks' ) ) { return; }
	const source = new DOMParser().parseFromString( previewHtml, 'text/html' );
	canvas.head.querySelectorAll( 'style,link[rel=stylesheet]' ).forEach( node => { if ( ! node.href?.includes( '/wp-admin/css/visual-builder-canvas.css' ) ) { node.remove(); } } );
	const boundary = canvas.head.querySelector( 'link[rel=stylesheet]' );
	source.head.querySelectorAll( 'style,link[rel=stylesheet]' ).forEach( node => {
		if ( node.tagName === 'STYLE' || new URL( node.href, location.href ).origin === location.origin ) { canvas.head.insertBefore( canvas.importNode( node, true ), boundary ); }
	} );
	canvas.body.className = source.body.className;
}
function notifyEditor( type ) {
	const dirty = document.getElementById( 'editor-state' )?.textContent.includes( 'Unsaved changes' );
	parent.postMessage( { channel: CHANNEL, type, id: Number( form.elements.ID.value ), title: form.elements.post_title.value, status: form.elements.post_status.value, dirty: Boolean( dirty || document.querySelector( '.notice-error' ) ), listPage: Number( form.dataset.designListPage ), saved: form.dataset.saved === '1', draftKey: 'wp-draft:' + form.dataset.draftKey }, location.origin );
}
