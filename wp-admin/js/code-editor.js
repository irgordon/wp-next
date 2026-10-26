/** Editable code surfaces retain the original textarea, form and input events. */
import { tokenizeCode, codeTokenLines, codePosition, formatJsonCode, codeMetrics } from './code-model.js';
const editors = new WeakMap();
const INPUT_SELECTOR = 'textarea.raw-content,textarea[data-code-language]';
const MAX_HIGHLIGHT_CHARACTERS = 200000;
const MAX_HIGHLIGHT_LINES = 3000;
let editorSerial = 0;
const WINDOW_MARKUP = [
	'<section class="code-window"><header class="code-window-toolbar"><div class="code-file"><span aria-hidden="true" class="code-file-icon">⌘</span><strong class="code-filename"></strong><span class="code-language"></span><span class="code-edit-state">Ready</span></div>',
	'<div class="code-tools"><button type="button" data-code-action="format">Format JSON</button><button type="button" data-code-action="wrap" aria-pressed="false">Wrap</button><button type="button" data-code-action="copy">Copy</button><button type="button" data-code-action="expand">Expand</button></div></header>',
	'<div class="code-viewport"><div class="code-gutter" aria-hidden="true"></div><div class="code-text-plane"><pre class="code-highlight" aria-hidden="true"><code></code></pre></div></div>',
	'<footer class="code-window-footer"><span class="code-position">Ln 1, Col 1</span><span class="code-metrics"></span><span class="code-message" role="status" aria-live="polite"></span></footer></section>'
].join( '' );

export function enhanceCodeEditors( root = document ) {
	codeInputsIn( root ).forEach( input => { if ( ! editors.has( input ) ) { mountCodeEditor( input ); } } );
}
export function refreshCodeField( input ) {
	const context = editors.get( input );
	if ( context ) { renderCodeEditor( context ); }
}
export function observeCodeEditors() {
	const observer = new MutationObserver( records => records.forEach( record => record.addedNodes.forEach( node => {
		if ( node.nodeType === Node.ELEMENT_NODE ) { enhanceCodeEditors( node ); }
	} ) ) );
	observer.observe( document.body, { childList: true, subtree: true } );
}
function mountCodeEditor( input ) {
	const context = createCodeContext( input );
	editors.set( input, context );
	presentCodeEditor( context );
	connectCodeEditor( context );
	renderCodeEditor( context );
}
function createCodeContext( input ) {
	return { input, element: elementFromMarkup( WINDOW_MARKUP ), baseline: input.value, language: languageFor( input ), wrap: false, activeLine: null, serial: ++editorSerial, expanded: false };
}
function presentCodeEditor( context ) {
	const host = document.createElement( 'div' );
	host.className = 'code-editor-host'; host.dataset.codeFor = context.input.id;
	context.input.before( host ); host.append( context.element );
	context.host = host;
	context.element.querySelector( '.code-text-plane' ).append( context.input );
	context.input.classList.add( 'code-input' ); context.input.wrap = 'off';
	context.input.spellcheck = false; context.input.setAttribute( 'autocapitalize', 'off' );
	context.input.setAttribute( 'autocorrect', 'off' );
	context.title = context.element.querySelector( '.code-filename' ); context.title.id = 'code-editor-title-' + context.serial;
	prepareCodeDialog( context );
}
function connectCodeEditor( context ) {
	context.input.addEventListener( 'input', () => { codeNotice( context, '' ); renderCodeEditor( context ); } );
	context.input.addEventListener( 'scroll', () => synchronizeCodeScroll( context ) );
	context.input.addEventListener( 'select', () => updateCodePosition( context ) );
	context.input.addEventListener( 'keyup', () => updateCodePosition( context ) );
	context.input.addEventListener( 'click', () => updateCodePosition( context ) );
	context.input.addEventListener( 'keydown', event => { if ( ( event.metaKey || event.ctrlKey ) && event.key.toLowerCase() === 'z' ) { event.stopPropagation(); } } );
	context.element.addEventListener( 'click', event => codeToolAction( context, event ) );
	new ResizeObserver( () => synchronizeGutterHeights( context ) ).observe( context.element.querySelector( '.code-text-plane' ) );
}
function renderCodeEditor( context ) {
	context.language = languageFor( context.input );
	const text = context.input.value, metrics = codeMetrics( text );
	const plain = metrics.characters > MAX_HIGHLIGHT_CHARACTERS || metrics.lines > MAX_HIGHLIGHT_LINES;
	context.element.classList.toggle( 'code-plain', plain );
	updateCodeHeader( context, metrics );
	if ( ! plain ) { renderCodeLines( context, codeTokenLines( tokenizeCode( text, context.language ) ) ); }
	updateCodePosition( context ); synchronizeCodeScroll( context );
	requestAnimationFrame( () => synchronizeGutterHeights( context ) );
}
function updateCodeHeader( context, metrics ) {
	context.title.textContent = filenameFor( context.input, context.language );
	context.element.querySelector( '.code-language' ).textContent = context.language === 'javascript' ? 'JS' : context.language.toUpperCase();
	context.element.querySelector( '.code-edit-state' ).textContent = context.input.value === context.baseline ? 'Ready' : 'Editing';
	context.element.querySelector( '.code-metrics' ).textContent = metrics.lines + ( metrics.lines === 1 ? ' line' : ' lines' );
	context.element.querySelector( '[data-code-action=format]' ).hidden = context.language !== 'json';
	context.element.dataset.modified = String( context.input.value !== context.baseline );
}
function renderCodeLines( context, lines ) {
	const code = context.element.querySelector( '.code-highlight code' ), gutter = context.element.querySelector( '.code-gutter' );
	code.replaceChildren(); gutter.replaceChildren();
	lines.forEach( ( tokens, index ) => {
		const line = createCodeLine( tokens, index ); code.append( line );
		const number = document.createElement( 'span' ); number.className = 'code-line-number'; number.textContent = index + 1; gutter.append( number );
	} );
	context.activeLine = null;
}
function createCodeLine( tokens, index ) {
	const line = document.createElement( 'span' );
	line.className = 'code-line'; line.dataset.line = index + 1;
	tokens.forEach( token => {
		const span = document.createElement( 'span' ); span.className = 'code-token-' + token.type; span.textContent = token.text; line.append( span );
	} );
	if ( ! line.textContent ) { line.append( document.createTextNode( ' ' ) ); }
	return line;
}
function updateCodePosition( context ) {
	const position = codePosition( context.input.value, context.input.selectionStart );
	context.element.querySelector( '.code-position' ).textContent = 'Ln ' + position.line + ', Col ' + position.column;
	highlightCodeLine( context, position.line );
}
function highlightCodeLine( context, line ) {
	if ( context.activeLine === line ) { return; }
	context.element.querySelectorAll( '.code-line.is-active,.code-line-number.is-active' ).forEach( node => node.classList.remove( 'is-active' ) );
	context.element.querySelector( '.code-line[data-line="' + line + '"]' )?.classList.add( 'is-active' );
	context.element.querySelectorAll( '.code-line-number' )[ line - 1 ]?.classList.add( 'is-active' );
	context.activeLine = line;
}
function synchronizeCodeScroll( context ) {
	const input = context.input, pre = context.element.querySelector( '.code-highlight' );
	pre.scrollTop = input.scrollTop; pre.scrollLeft = input.scrollLeft;
	context.element.querySelector( '.code-gutter' ).scrollTop = input.scrollTop;
}
function synchronizeGutterHeights( context ) {
	if ( ! context.input.isConnected ) { return; }
	const lines = context.element.querySelectorAll( '.code-line' ), numbers = context.element.querySelectorAll( '.code-line-number' );
	lines.forEach( ( line, index ) => { if ( numbers[ index ] ) { numbers[ index ].style.height = line.getBoundingClientRect().height + 'px'; } } );
	synchronizeCodeScroll( context );
}
function codeToolAction( context, event ) {
	const button = event.target.closest( '[data-code-action]' );
	if ( ! button ) { return; }
	const actions = { format: formatEditorJson, wrap: toggleCodeWrap, copy: copyEditorCode, expand: toggleCodeDialog };
	actions[ button.dataset.codeAction ]?.( context );
}
function formatEditorJson( context ) {
	const result = formatJsonCode( context.input.value );
	if ( ! result.ok ) { codeNotice( context, result.message, true ); return; }
	context.input.value = result.value;
	context.input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	codeNotice( context, 'JSON formatted.' );
}
function toggleCodeWrap( context ) {
	context.wrap = ! context.wrap;
	context.input.wrap = context.wrap ? 'soft' : 'off';
	context.element.dataset.wrap = String( context.wrap );
	context.element.querySelector( '[data-code-action=wrap]' ).setAttribute( 'aria-pressed', String( context.wrap ) );
	synchronizeGutterHeights( context );
}
async function copyEditorCode( context ) {
	try {
		await navigator.clipboard.writeText( context.input.value );
		const button = context.element.querySelector( '[data-code-action=copy]' );
		button.textContent = 'Copied'; codeNotice( context, 'Code copied.' );
		setTimeout( () => { button.textContent = 'Copy'; }, 1400 );
	} catch ( error ) { codeNotice( context, 'Clipboard unavailable. Select the code and copy with your keyboard.', true ); }
}
function prepareCodeDialog( context ) {
	const dialog = document.createElement( 'dialog' );
	dialog.className = 'code-dialog'; dialog.setAttribute( 'aria-labelledby', context.title.id );
	context.host.append( dialog ); context.dialog = dialog;
	dialog.addEventListener( 'close', () => restoreCodeWindow( context ) );
}
function toggleCodeDialog( context ) {
	if ( context.expanded ) { context.dialog.close(); return; }
	context.expanded = true; context.dialog.append( context.element );
	context.element.querySelector( '[data-code-action=expand]' ).textContent = 'Done';
	context.dialog.showModal(); context.input.focus();
	synchronizeGutterHeights( context );
}
function restoreCodeWindow( context ) {
	context.host.prepend( context.element ); context.expanded = false;
	const button = context.element.querySelector( '[data-code-action=expand]' ); button.textContent = 'Expand'; button.focus();
	synchronizeGutterHeights( context );
}
function codeNotice( context, text, error = false ) {
	const message = context.element.querySelector( '.code-message' );
	message.textContent = text; message.classList.toggle( 'is-error', error );
}
function languageFor( input ) {
	if ( input.dataset.codeLanguage ) { return input.dataset.codeLanguage; }
	if ( input.id === 'post_content' ) { return input.value.trim().startsWith( '<' ) ? 'html' : 'json'; }
	return 'text';
}
function filenameFor( input, language ) {
	return input.dataset.codeName || ( input.id === 'post_content' ? 'content.' + language : 'block.' + ( language === 'text' ? 'txt' : language ) );
}
function codeInputsIn( root ) {
	const inputs = Array.from( root.querySelectorAll( INPUT_SELECTOR ) );
	if ( root.matches?.( INPUT_SELECTOR ) ) { inputs.unshift( root ); }
	return inputs;
}
function elementFromMarkup( markup ) {
	const template = document.createElement( 'template' ); template.innerHTML = markup; return template.content.firstElementChild;
}
