/** Dedicated visual workspace. Document rules live in visual-builder-model.js. */
import { BLOCK_DEFINITIONS, createBlock, insertBlock, moveBlock, duplicateBlock, removeBlock, updateBlockField, selectedCollection, flattenBlocks, locateBlock, previewDocument } from './visual-builder-model.js';

const REFRESH_DELAY = 300;
const HISTORY_LIMIT = 80;
const BLOCK_MIME = 'application/x-wordpress-next-block';
const CONTEXT_DEFAULTS = Object.freeze( { selection: null, active: null, range: null, historyIndex: 0, replaying: false, timer: null, sequence: 0, frame: null, frameDocument: null } );
const FIELD_SPEC = {
	paragraph: { selector: 'p', field: 'html' },
	heading: { selector: 'h2,h3,h4,h5,h6', field: 'html' },
	list: { selector: 'ul,ol', field: 'html' },
	quote: { selector: 'blockquote', field: 'html' },
	code: { selector: 'pre code', field: 'text', plain: true },
	file: { selector: 'a', field: 'label', plain: true },
	image: { selector: 'figcaption', field: 'caption', plain: true },
	html: { field: 'html', legacy: true }
};
const WORKSPACE_MARKUP = [
	'<section class="builder-workspace" aria-label="Visual page builder">',
	'<header class="builder-topbar"><div class="builder-back"></div><div class="builder-page-label"><strong>Visual Builder</strong><span id="builder-page-name"></span></div>',
	'<div class="builder-history"><button type="button" id="builder-undo" aria-label="Undo change" title="Undo (⌘/Ctrl Z)">↶</button><button type="button" id="builder-redo" aria-label="Redo change" title="Redo (⌘/Ctrl Shift Z)">↷</button></div>',
	'<div class="builder-devices"></div><div class="builder-save"></div></header>',
	'<div class="builder-mobile-tools"><button type="button" data-drawer="blocks" aria-expanded="false">Blocks</button><button type="button" data-drawer="settings" aria-expanded="false">Settings</button></div>',
	'<aside class="builder-palette" aria-label="Building blocks"><div class="builder-panel-tabs"><button type="button" data-panel="blocks" aria-pressed="true">Blocks</button><button type="button" data-panel="layers" aria-pressed="false">Layers</button></div>',
	'<div id="builder-blocks"><p class="builder-help">Click to add. Drag onto the page to place.</p><div class="builder-block-grid"></div></div><div id="builder-layers" hidden><p class="builder-help">Select a block to edit its settings.</p><ol class="builder-layer-list"></ol></div></aside>',
	'<section class="builder-stage" aria-label="Page canvas"><div class="builder-formatbar" role="toolbar" aria-label="Canvas editing tools">',
	'<button type="button" id="builder-bold" aria-label="Bold selected text" title="Bold selected text"><img src="assets/wordpress/bold.svg" alt=""></button><button type="button" id="builder-italic" aria-label="Italic selected text" title="Italic selected text"><img src="assets/wordpress/italic.svg" alt=""></button>',
	'<input type="text" id="builder-link-url" aria-label="Canvas link URL" placeholder="Link URL"><button type="button" id="builder-link">Apply link</button>',
	'<span class="builder-tools-divider"></span><button type="button" id="builder-duplicate">Duplicate block</button><button type="button" id="builder-remove">Delete block</button></div>',
	'<div class="builder-scroll"><div class="builder-page-shell"></div></div></section>',
	'<aside class="builder-inspector" aria-label="Builder settings"><div class="builder-panel-tabs"><button type="button" data-inspector="block" aria-pressed="true">Block</button><button type="button" data-inspector="page" aria-pressed="false">Page</button></div>',
	'<div class="builder-block-settings"><div class="builder-selection-help"><strong>Your page, in place.</strong><p>Click text on the page to edit it. Select a block for more settings.</p></div></div><div class="builder-page-settings" hidden></div></aside>',
	'<footer class="builder-footer"><span class="builder-save-state"></span><span class="builder-feedback"></span><span class="builder-viewport-label">Desktop · 960 px</span></footer></section>'
].join( '' );

export function mountVisualWorkspace( api ) {
	const context = createWorkspaceContext( api );
	arrangeWorkspace( context );
	connectWorkspace( context );
	initializeCanvas( context );
	return workspaceLifecycle( context );
}
function createWorkspaceContext( api ) {
	const snapshot = captureEditorSnapshot( api );
	return { ...CONTEXT_DEFAULTS, api, element: elementFromMarkup( WORKSPACE_MARKUP ), snapshot, history: initialHistory( snapshot ), mobile: hasMobileViewport() };
}
function arrangeWorkspace( context ) {
	assembleWorkspace( context );
	installBuilderBreadcrumb( context );
	buildPalette( context );
	renderLayers( context );
	updateHistoryButtons( context );
}
function connectWorkspace( context ) {
	wireToolbar( context );
	wirePanels( context );
	wireFormatting( context );
	connectDocumentInputs( context );
	connectWorkspaceShortcuts( context );
	observeCanvasContainer( context );
}
function initializeCanvas( context ) {
	context.frame = createCanvasFrame( () => prepareCanvasDocument( context ) );
	appendCanvasFrame( context.api.canvas, context.frame );
}
function createCanvasFrame( onLoad ) {
	const frame = document.createElement( 'iframe' );
	frame.id = 'builder-canvas-frame';
	frame.title = 'Editable page canvas';
	frame.setAttribute( 'sandbox', 'allow-same-origin' );
	frame.addEventListener( 'load', onLoad );
	frame.srcdoc = '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body><main class="site-container"><article class="entry"><h1 id="canvas-page-title" class="entry-title"></h1><div id="canvas-blocks" class="entry-content"></div></article></main></body></html>';
	return frame;
}
function workspaceLifecycle( context ) {
	return { documentChanged: () => documentChanged( context ), select: path => selectCanvasBlock( context, path ), showRawEditor: () => showRawEditor( context ) };
}
function showRawEditor( context ) {
	selectInspectorPanel( context, 'page' );
	context.element.dataset.drawer = 'settings';
	queryAll( context, '[data-drawer]' ).forEach( button => button.setAttribute( 'aria-expanded', String( button.dataset.drawer === 'settings' ) ) );
	context.api.raw.closest( 'details' ).open = true;
	context.api.raw.focus();
}
function documentChanged( context ) {
	const snapshot = captureEditorSnapshot( context.api );
	if ( snapshot === context.snapshot ) { return; }
	context.snapshot = snapshot;
	if ( ! context.replaying ) { recordHistory( context, snapshot ); }
	query( context, '#builder-page-name' ).textContent = context.api.title() || 'Untitled page';
	renderLayers( context );
	queuePreview( context );
}
function addCanvasBlock( context, type ) {
	performChange( context, () => insertBlock( context.api.read(), createBlock( type ), selectedCollection( context.selection ) ) );
}
function performChange( context, operation ) {
	try {
		const change = operation();
		context.selection = change.selection;
		context.api.apply( change );
		paintSelection( context );
	} catch ( error ) { context.api.announce( error.message ); }
}
function selectCanvasBlock( context, path ) {
	context.selection = path;
	context.api.select( path );
	paintSelection( context );
	selectInspectorPanel( context, 'block' );
	query( context, '.builder-selection-help' ).hidden = true;
}
function selectInspectorPanel( context, panel ) {
	query( context, '.builder-block-settings' ).hidden = panel !== 'block';
	query( context, '.builder-page-settings' ).hidden = panel !== 'page';
	queryAll( context, '[data-inspector]' ).forEach( button => button.setAttribute( 'aria-pressed', String( button.dataset.inspector === panel ) ) );
}
function selectPalettePanel( context, panel ) {
	query( context, '#builder-blocks' ).hidden = panel !== 'blocks';
	query( context, '#builder-layers' ).hidden = panel !== 'layers';
	queryAll( context, '[data-panel]' ).forEach( button => button.setAttribute( 'aria-pressed', String( button.dataset.panel === panel ) ) );
}
function changeDevice( context, mobile ) {
	context.mobile = mobile;
	context.api.canvas.classList.toggle( 'canvas-mobile', mobile );
	document.getElementById( 'width-mobile' ).setAttribute( 'aria-pressed', String( mobile ) );
	document.getElementById( 'width-desktop' ).setAttribute( 'aria-pressed', String( ! mobile ) );
	query( context, '.builder-viewport-label' ).textContent = mobile ? 'Mobile · 390 px' : 'Desktop · 960 px';
	resizeCanvas( context );
}
function replayHistory( context, offset ) {
	const next = context.historyIndex + offset;
	if ( next < 0 || next >= context.history.length ) { return; }
	context.frameDocument?.activeElement?.blur();
	context.historyIndex = next;
	context.replaying = true;
	const entry = context.history[ next ];
	const snapshot = JSON.parse( entry.snapshot );
	context.selection = entry.selection;
	context.api.setTitle( snapshot.title );
	context.api.apply( { document: snapshot.document, selection: context.selection } );
	context.replaying = false;
	updateHistoryButtons( context );
	queuePreview( context );
}
function recordHistory( context, snapshot ) {
	context.history = context.history.slice( 0, context.historyIndex + 1 );
	context.history.push( { snapshot, selection: context.selection ? [ ...context.selection ] : null } );
	if ( context.history.length > HISTORY_LIMIT ) { context.history.shift(); }
	context.historyIndex = context.history.length - 1;
	updateHistoryButtons( context );
}
function workspaceShortcut( context, event ) {
	if ( event.target.id === 'builder-link-url' ) { return; }
	if ( ! ( event.metaKey || event.ctrlKey ) || event.altKey ) { return; }
	if ( event.key.toLowerCase() === 's' ) { event.preventDefault(); context.api.form.requestSubmit( context.saveButton ); }
	if ( event.key.toLowerCase() === 'z' ) { event.preventDefault(); replayHistory( context, event.shiftKey ? 1 : -1 ); }
}
function queuePreview( context ) {
	clearTimeout( context.timer );
	context.timer = setTimeout( () => refreshPreview( context ), REFRESH_DELAY );
}
async function refreshPreview( context ) {
	if ( ! context.frameDocument || ! context.api.isValid() || isEditingCanvas( context ) ) { return; }
	const sequence = ++context.sequence;
	const snapshot = context.snapshot;
	try {
		const blocks = await requestBlockPreview( context );
		if ( sequence !== context.sequence || snapshot !== context.snapshot || isEditingCanvas( context ) ) { return; }
		renderCanvas( context, blocks );
	} catch ( error ) { context.api.announce( 'The page preview could not refresh. Your edits are kept. ' + error.message ); }
}
function prepareCanvasDocument( context ) {
	context.frameDocument = context.frame.contentDocument;
	if ( ! context.frameDocument?.getElementById( 'canvas-page-title' ) ) { return; }
	installCanvasStyles( context );
	wireCanvasTitle( context );
	context.frameDocument.addEventListener( 'click', event => { if ( event.target.closest( 'a' ) ) { event.preventDefault(); } } );
	context.frameDocument.addEventListener( 'keydown', event => workspaceShortcut( context, event ) );
	new ResizeObserver( () => resizeCanvas( context ) ).observe( context.frameDocument.body );
	changeDevice( context, context.mobile );
	refreshPreview( context );
}
function renderCanvas( context, rendered ) {
	const container = context.frameDocument.getElementById( 'canvas-blocks' );
	context.active = null; context.range = null;
	container.replaceChildren();
	renderCollection( context, container, context.api.read().blocks, rendered, [] );
	context.frameDocument.getElementById( 'canvas-page-title' ).textContent = context.api.title() || 'Untitled page';
	paintSelection( context );
	resizeCanvas( context );
}
function renderCollection( context, container, blocks, rendered, prefix ) {
	blocks.forEach( ( block, index ) => container.append( createCanvasBlock( context, block, [ ...prefix, index ], rendered[ index ] ) ) );
	container.append( createDropZone( context, prefix, blocks.length ) );
	container.dataset.dropLength = String( blocks.length );
	wireDropTarget( context, container, prefix, () => Number( container.dataset.dropLength ) );
}
function createCanvasBlock( context, block, path, rendered ) {
	const frame = createElement( context.frameDocument, 'section', undefined, 'canvas-frame' );
	frame.dataset.path = JSON.stringify( path );
	frame.tabIndex = 0;
	frame.setAttribute( 'aria-label', 'Select ' + block.type + ' block' );
	frame.append( createFrameToolbar( context, block, path ) );
	frame.append( createBlockBody( context, block, path, rendered ) );
	wireBlockSelection( context, frame, path );
	wireFrameDrop( context, frame, path );
	return frame;
}
function createBlockBody( context, block, path, rendered ) {
	const body = createElement( context.frameDocument, 'div', undefined, 'canvas-block' );
	if ( block.type === 'columns' ) {
		const columns = createElement( context.frameDocument, 'div', undefined, 'wp-block-columns' );
		block.columns.forEach( ( children, column ) => columns.append( createCanvasColumn( context, children, rendered?.columns?.[ column ] || [], [ ...path, column ] ) ) );
		body.append( columns );
	} else {
		body.innerHTML = rendered?.html || '';
		wireBlockContent( context, body, block, path );
	}
	return body;
}
function createCanvasColumn( context, blocks, rendered, prefix ) {
	const column = createElement( context.frameDocument, 'div', undefined, 'wp-block-column canvas-column' );
	renderCollection( context, column, blocks, rendered, prefix );
	return column;
}
function wireBlockContent( context, body, block, path ) {
	if ( ( block.type === 'image' || block.type === 'file' ) && ! Number( block.media_id ) ) {
		body.replaceChildren( createAssetPlaceholder( context, block, path ) ); return;
	}
	const spec = FIELD_SPEC[ block.type ];
	if ( spec ) { installEditableField( context, body, block, path, spec ); }
	body.querySelectorAll( 'img' ).forEach( image => image.addEventListener( 'dblclick', () => context.api.pick( path, 'image' ) ) );
}
function installEditableField( context, body, block, path, spec ) {
	let editable = spec.selector ? body.querySelector( spec.selector ) : body;
	if ( ! editable && block.type === 'image' && body.querySelector( 'figure' ) ) {
		editable = createElement( context.frameDocument, 'figcaption' ); body.querySelector( 'figure' ).append( editable );
	}
	if ( ! editable ) { return; }
	editable.contentEditable = 'true'; editable.spellcheck = ! spec.plain;
	editable.setAttribute( 'role', 'textbox' ); editable.setAttribute( 'aria-label', 'Edit ' + block.type + ' on page' );
	editable.setAttribute( 'aria-multiline', 'true' ); editable.dataset.placeholder = block.type === 'image' ? 'Add a caption…' : 'Type here…';
	wireEditableEvents( context, editable, { path, spec, element: editable } );
}
function wireEditableEvents( context, editable, field ) {
	editable.addEventListener( 'focus', () => { context.active = field; selectCanvasBlock( context, field.path ); } );
	editable.addEventListener( 'input', () => captureCanvasField( context, field ) );
	editable.addEventListener( 'keyup', () => rememberCanvasSelection( context, field ) );
	editable.addEventListener( 'mouseup', () => rememberCanvasSelection( context, field ) );
	editable.addEventListener( 'paste', event => { insertPlainClipboard( event, editable ); captureCanvasField( context, field ); } );
	editable.addEventListener( 'blur', () => queuePreview( context ) );
}
function captureCanvasField( context, field ) {
	const value = field.spec.plain ? field.element.textContent : field.spec.legacy ? field.element.innerHTML : context.api.sanitizeInline( field.element.innerHTML );
	performChange( context, () => updateBlockField( context.api.read(), field.path, field.spec.field, value ) );
	rememberCanvasSelection( context, field );
}
function formatCanvasSelection( context, tag, href ) {
	const field = context.active, range = context.range;
	if ( ! field || field.spec.plain || ! range || range.collapsed || ! field.element.contains( range.commonAncestorContainer ) ) { context.api.announce( 'Select some text on the page first.' ); return; }
	if ( href && ! allowedLink( href ) ) { context.api.announce( 'Use an HTTP, HTTPS, mail, telephone, or relative link.' ); return; }
	const wrapper = createElement( context.frameDocument, tag );
	if ( href ) { wrapper.setAttribute( 'href', href ); }
	wrapper.append( range.extractContents() ); range.insertNode( wrapper );
	field.element.focus(); captureCanvasField( context, field );
}
function rememberCanvasSelection( context, field ) {
	const selection = context.frameDocument.getSelection();
	if ( selection?.rangeCount && field.element.contains( selection.anchorNode ) ) { context.range = selection.getRangeAt( 0 ).cloneRange(); }
}
function wireCanvasTitle( context ) {
	const title = context.frameDocument.getElementById( 'canvas-page-title' );
	title.contentEditable = 'plaintext-only';
	title.setAttribute( 'role', 'textbox' ); title.setAttribute( 'aria-label', 'Page title on canvas' );
	title.textContent = context.api.title() || 'Untitled page';
	title.addEventListener( 'input', () => { context.api.setTitle( title.textContent ); documentChanged( context ); } );
	title.addEventListener( 'blur', () => queuePreview( context ) );
}
function wireFrameDrop( context, frame, path ) {
	frame.addEventListener( 'dragover', event => markDrop( event, frame ) );
	frame.addEventListener( 'dragleave', () => frame.classList.remove( 'drop-target' ) );
	frame.addEventListener( 'drop', event => {
		const after = event.clientY > frame.getBoundingClientRect().top + frame.getBoundingClientRect().height / 2;
		acceptDrop( context, event, selectedCollection( path ), path.at( -1 ) + Number( after ) );
		frame.classList.remove( 'drop-target' );
	} );
}
function wireDropTarget( context, element, prefix, position ) {
	if ( element.dataset.dropBound ) { return; }
	element.dataset.dropBound = 'true';
	element.addEventListener( 'dragover', event => markDrop( event, element ) );
	element.addEventListener( 'dragleave', () => element.classList.remove( 'drop-target' ) );
	element.addEventListener( 'drop', event => {
		acceptDrop( context, event, prefix, position() ); element.classList.remove( 'drop-target' );
	} );
}
function acceptDrop( context, event, prefix, position ) {
	event.preventDefault(); event.stopPropagation();
	try {
		const payload = JSON.parse( event.dataTransfer.getData( BLOCK_MIME ) );
		if ( payload.type ) { performChange( context, () => insertAtPosition( context, payload.type, prefix, position ) ); }
		else { performChange( context, () => moveBlock( context.api.read(), payload.path, prefix, position ) ); }
	} catch ( error ) { context.api.announce( 'Drop a block from this builder onto the page.' ); }
}
function insertAtPosition( context, type, prefix, position ) {
	const change = insertBlock( context.api.read(), createBlock( type ), prefix );
	return moveBlock( change.document, change.selection, prefix, position );
}
function markDrop( event, element ) {
	if ( ! Array.from( event.dataTransfer.types ).includes( BLOCK_MIME ) ) { return; }
	event.preventDefault(); event.stopPropagation(); event.dataTransfer.dropEffect = 'move'; element.classList.add( 'drop-target' );
}
function createAssetPlaceholder( context, block, path ) {
	const button = createElement( context.frameDocument, 'button', block.type === 'image' ? 'Choose an image' : 'Choose a document', 'canvas-asset-placeholder' );
	button.type = 'button'; button.addEventListener( 'click', () => context.api.pick( path, block.type ) );
	return button;
}
function createFrameToolbar( context, block, path ) {
	const toolbar = createElement( context.frameDocument, 'div', undefined, 'canvas-frame-bar' );
	const handle = frameButton( context, '⠿ ' + block.type, 'Drag ' + block.type + ' block', () => selectCanvasBlock( context, path ) );
	handle.draggable = true;
	handle.addEventListener( 'dragstart', event => startDrag( event, { path } ) );
	toolbar.append( handle, frameButton( context, '↑', 'Move ' + block.type + ' block up', () => moveByOffset( context, path, -1 ) ), frameButton( context, '↓', 'Move ' + block.type + ' block down', () => moveByOffset( context, path, 1 ) ) );
	return toolbar;
}
function moveByOffset( context, path, offset ) {
	const record = locateBlock( context.api.read(), path );
	const position = record.index + ( offset > 0 ? 2 : -1 );
	if ( position < 0 || position > record.collection.length ) { return; }
	performChange( context, () => moveBlock( context.api.read(), path, selectedCollection( path ), position ) );
}
function createDropZone( context, prefix, position ) {
	const zone = createElement( context.frameDocument, 'div', '＋ Drop a block here', 'canvas-drop-zone' );
	wireDropTarget( context, zone, prefix, () => position );
	return zone;
}
function buildPalette( context ) {
	const grid = query( context, '.builder-block-grid' );
	BLOCK_DEFINITIONS.forEach( definition => {
		const button = createPaletteButton( context, definition );
		grid.append( button );
	} );
}
function createPaletteButton( context, definition ) {
	const button = createElement( document, 'button', undefined, 'builder-block-tile' );
	button.type = 'button'; button.draggable = true; button.dataset.builderType = definition.type;
	button.append( createElement( document, 'span', definition.icon, 'builder-block-icon' ), createElement( document, 'span', definition.label ) );
	button.setAttribute( 'aria-label', 'Add ' + definition.label.toLowerCase() + ' block' );
	button.addEventListener( 'click', () => addCanvasBlock( context, definition.type ) );
	button.addEventListener( 'dragstart', event => startDrag( event, { type: definition.type } ) );
	return button;
}
function renderLayers( context ) {
	const list = query( context, '.builder-layer-list' );
	list.replaceChildren();
	flattenBlocks( context.api.read().blocks ).forEach( record => list.append( createLayerItem( context, record ) ) );
	if ( ! list.children.length ) { list.append( createElement( document, 'li', 'Your page is empty. Add a block to begin.', 'builder-help' ) ); }
}
function createLayerItem( context, record ) {
	const item = createElement( document, 'li' );
	const button = createElement( document, 'button', layerLabel( record.block ), 'builder-layer' );
	button.type = 'button'; button.dataset.layerPath = JSON.stringify( record.path );
	button.style.setProperty( '--layer-depth', String( ( record.path.length - 1 ) / 2 ) );
	button.addEventListener( 'click', () => { selectCanvasBlock( context, record.path ); selectedFrame( context )?.scrollIntoView( { block: 'nearest' } ); } );
	item.append( button );
	return item;
}
function layerLabel( block ) {
	const parser = new DOMParser().parseFromString( block.html || block.text || block.caption || block.label || '', 'text/html' );
	const text = parser.body.textContent.trim().slice( 0, 26 );
	const label = BLOCK_DEFINITIONS.find( definition => definition.type === block.type )?.label || block.type;
	return text ? label + ' · ' + text : label;
}
function paintSelection( context ) {
	const path = JSON.stringify( context.selection );
	context.frameDocument?.querySelectorAll( '.canvas-frame' ).forEach( frame => frame.classList.toggle( 'selected', frame.dataset.path === path ) );
	queryAll( context, '[data-layer-path]' ).forEach( button => button.setAttribute( 'aria-pressed', String( button.dataset.layerPath === path ) ) );
	query( context, '#builder-duplicate' ).disabled = ! context.selection;
	query( context, '#builder-remove' ).disabled = ! context.selection;
	query( context, '.builder-selection-help' ).hidden = Boolean( context.selection );
}
function selectedFrame( context ) {
	const path = JSON.stringify( context.selection );
	return Array.from( context.frameDocument?.querySelectorAll( '.canvas-frame' ) || [] ).find( frame => frame.dataset.path === path );
}
function updateHistoryButtons( context ) {
	query( context, '#builder-undo' ).disabled = context.historyIndex === 0;
	query( context, '#builder-redo' ).disabled = context.historyIndex === context.history.length - 1;
}
function wireToolbar( context ) {
	query( context, '#builder-undo' ).addEventListener( 'click', () => replayHistory( context, -1 ) );
	query( context, '#builder-redo' ).addEventListener( 'click', () => replayHistory( context, 1 ) );
	query( context, '#builder-duplicate' ).addEventListener( 'click', () => performChange( context, () => duplicateBlock( context.api.read(), context.selection ) ) );
	query( context, '#builder-remove' ).addEventListener( 'click', () => performChange( context, () => removeBlock( context.api.read(), context.selection ) ) );
	document.getElementById( 'width-desktop' ).addEventListener( 'click', () => changeDevice( context, false ) );
	document.getElementById( 'width-mobile' ).addEventListener( 'click', () => changeDevice( context, true ) );
}
function wirePanels( context ) {
	queryAll( context, '[data-panel]' ).forEach( button => button.addEventListener( 'click', () => selectPalettePanel( context, button.dataset.panel ) ) );
	queryAll( context, '[data-inspector]' ).forEach( button => button.addEventListener( 'click', () => selectInspectorPanel( context, button.dataset.inspector ) ) );
	queryAll( context, '[data-drawer]' ).forEach( button => button.addEventListener( 'click', () => {
		const active = context.element.dataset.drawer === button.dataset.drawer ? '' : button.dataset.drawer;
		context.element.dataset.drawer = active;
		queryAll( context, '[data-drawer]' ).forEach( toggle => toggle.setAttribute( 'aria-expanded', String( toggle.dataset.drawer === active ) ) );
	} ) );
}
function wireFormatting( context ) {
	[ [ '#builder-bold', 'strong' ], [ '#builder-italic', 'em' ] ].forEach( ( [ selector, tag ] ) => {
		const button = query( context, selector );
		button.addEventListener( 'mousedown', event => event.preventDefault() );
		button.addEventListener( 'click', () => formatCanvasSelection( context, tag ) );
	} );
	query( context, '#builder-link' ).addEventListener( 'click', () => formatCanvasSelection( context, 'a', query( context, '#builder-link-url' ).value ) );
}
function wireBlockSelection( context, frame, path ) {
	frame.addEventListener( 'click', event => { if ( event.target.closest( '.canvas-frame' ) === frame ) { selectCanvasBlock( context, path ); } } );
	frame.addEventListener( 'keydown', event => {
		if ( event.target === frame && ( event.key === 'Enter' || event.key === ' ' ) ) { event.preventDefault(); selectCanvasBlock( context, path ); }
	} );
}
function resizeCanvas( context ) {
	if ( ! context.frameDocument ) { return; }
	const width = context.mobile ? 390 : 960;
	const available = Math.max( 1, query( context, '.builder-scroll' ).clientWidth - 40 );
	const scale = Math.min( 1, available / width );
	const height = Math.max( 620, Math.ceil( context.frameDocument.body.getBoundingClientRect().height ) );
	context.frame.style.width = width + 'px'; context.frame.style.height = height + 'px';
	context.frame.style.transform = 'scale(' + scale + ')';
	context.api.canvas.style.width = width * scale + 'px'; context.api.canvas.style.height = height * scale + 'px';
}
function installCanvasStyles( context ) {
	const document = context.frameDocument;
	const template = window.document.getElementById( 'builder-canvas-head' );
	if ( template ) { document.head.append( document.importNode( template.content, true ) ); }
	const stylesheet = createElement( document, 'link' );
	stylesheet.rel = 'stylesheet'; stylesheet.href = new URL( 'css/visual-builder-canvas.css', location.href ).href;
	document.head.append( stylesheet );
}
function assembleWorkspace( context ) {
	const form = context.api.form;
	const sidebar = form.querySelector( '.editor-side' );
	const settings = query( context, '.builder-page-settings' );
	form.append( context.element );
	moveInto( context, '.builder-back', document.getElementById( 'mode-form' ) );
	moveInto( context, '.builder-devices', document.getElementById( 'canvas-width' ) );
	moveInto( context, '.builder-page-shell', context.api.canvas );
	moveInto( context, '.builder-block-settings', document.getElementById( 'wp-visual-inspector-panel' ) );
	settings.append( ...form.querySelectorAll( '.editor-main .form-field.wide' ), ...sidebar.children, context.api.raw.closest( 'details' ) );
	finishWorkspaceAssembly( context, sidebar );
}
function finishWorkspaceAssembly( context, sidebar ) {
	placeWorkspaceActions( context );
	moveInto( context, '.builder-save-state', document.getElementById( 'editor-state' ) );
	moveInto( context, '.builder-feedback', document.getElementById( 'builder-status' ) );
	document.getElementById( 'mode-form' ).textContent = '← Form editor';
	context.api.canvas.hidden = false;
	document.getElementById( 'canvas-width' ).hidden = false;
	document.getElementById( 'wp-visual-inspector-panel' ).hidden = false;
	context.api.form.querySelector( '.editor-main' ).classList.add( 'hidden' ); sidebar.classList.add( 'hidden' );
	document.body.classList.add( 'builder-ready' );
	query( context, '#builder-page-name' ).textContent = context.api.title() || 'Untitled page';
}
function placeWorkspaceActions( context ) {
	context.saveButton = context.api.form.querySelector( '[data-page-save],button[type=submit][value=update]' );
	placeWorkspacePreview( context );
	moveInto( context, '.builder-save', context.saveButton );
	placeWorkspacePublish( context );
	placeWorkspaceView( context );
}
function placeWorkspacePreview( context ) {
	const preview = context.api.form.querySelector( '.builder-page-settings .actions a:not([data-page-view])' );
	if ( preview ) { preview.className = 'button button-secondary'; moveInto( context, '.builder-save', preview ); }
}
function placeWorkspacePublish( context ) {
	const publish = context.api.form.querySelector( '[data-page-publish]' );
	if ( publish ) { moveInto( context, '.builder-save', publish ); }
}
function placeWorkspaceView( context ) {
	const link = context.api.form.querySelector( '[data-page-view]' );
	if ( link ) { moveInto( context, '.builder-save', link ); }
}
async function requestBlockPreview( context ) {
	const payload = new URLSearchParams( { _wpnonce: document.getElementById( 'block-preview-nonce' ).value, content: JSON.stringify( previewDocument( context.api.read() ) ) } );
	const response = await fetch( 'block-preview.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload } );
	const data = await response.json();
	if ( ! response.ok ) { throw new Error( data.error || 'Preview unavailable.' ); }
	return data.blocks;
}
function insertPlainClipboard( event, editable ) {
	event.preventDefault();
	const selection = editable.ownerDocument.getSelection();
	if ( ! selection?.rangeCount ) { return; }
	const range = selection.getRangeAt( 0 );
	const text = editable.ownerDocument.createTextNode( event.clipboardData.getData( 'text/plain' ) );
	range.deleteContents(); range.insertNode( text ); range.setStartAfter( text ); range.collapse( true );
	selection.removeAllRanges(); selection.addRange( range );
}
function allowedLink( href ) {
	try { return [ 'http:', 'https:', 'mailto:', 'tel:', 'sms:' ].includes( new URL( href, location.href ).protocol ); }
	catch ( error ) { return false; }
}
function isEditingCanvas( context ) {
	return Boolean( context.frameDocument.activeElement?.isContentEditable );
}
function startDrag( event, payload ) {
	event.dataTransfer.setData( BLOCK_MIME, JSON.stringify( payload ) ); event.dataTransfer.effectAllowed = 'copyMove';
}
function frameButton( context, label, accessibleLabel, action ) {
	const button = createElement( context.frameDocument, 'button', label );
	button.type = 'button'; button.setAttribute( 'aria-label', accessibleLabel ); button.addEventListener( 'click', action );
	return button;
}
function moveInto( context, selector, element ) {
	if ( element ) { query( context, selector ).append( element ); }
}
function createElement( document, tag, text, className ) {
	const element = document.createElement( tag );
	if ( text !== undefined ) { element.textContent = text; }
	if ( className ) { element.className = className; }
	return element;
}
function elementFromMarkup( markup ) {
	const template = document.createElement( 'template' ); template.innerHTML = markup; return template.content.firstElementChild;
}
function query( context, selector ) {
	return context.element.querySelector( selector );
}
function queryAll( context, selector ) {
	return Array.from( context.element.querySelectorAll( selector ) );
}
function captureEditorSnapshot( api ) {
	return JSON.stringify( { document: api.read(), title: api.title() } );
}
function initialHistory( snapshot ) {
	return [ { snapshot, selection: null } ];
}
function hasMobileViewport() {
	return matchMedia( '(max-width: 760px)' ).matches;
}
function connectDocumentInputs( context ) {
	context.api.form.addEventListener( 'input', () => documentChanged( context ) );
	context.api.form.addEventListener( 'change', () => documentChanged( context ) );
}
function connectWorkspaceShortcuts( context ) {
	document.addEventListener( 'keydown', event => workspaceShortcut( context, event ) );
}
function observeCanvasContainer( context ) {
	new ResizeObserver( () => resizeCanvas( context ) ).observe( query( context, '.builder-scroll' ) );
}
function appendCanvasFrame( container, frame ) {
	container.append( frame );
}
function installBuilderBreadcrumb( context ) {
	const breadcrumb = document.querySelector( '.admin-breadcrumbs' );
	if ( ! breadcrumb ) { return; }
	query( context, '.builder-page-label' ).prepend( breadcrumb );
	query( context, '.builder-page-label > strong' ).hidden = true;
}
