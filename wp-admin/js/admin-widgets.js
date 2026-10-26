/** Shared admin presentation enhancements; native forms remain the fallback. */
import { enhanceUploadForms } from './media-upload.js';
import { enhanceCodeEditors, observeCodeEditors } from './code-editor.js';

initializeAdminWidgets();

function initializeAdminWidgets() {
	enhanceUploadForms();
	enhanceCodeEditors();
	observeCodeEditors();
	connectBreadcrumbs();
	connectLibraryBrowse();
}
function connectBreadcrumbs() {
	document.querySelectorAll( '.breadcrumb-overflow details' ).forEach( connectBreadcrumbDetails );
	document.addEventListener( 'click', event => document.querySelectorAll( '.breadcrumb-overflow details[open]' ).forEach( details => { if ( ! details.contains( event.target ) ) { details.open = false; } } ) );
}
function connectBreadcrumbDetails( details ) {
	details.addEventListener( 'keydown', event => { if ( event.key === 'Escape' ) { details.open = false; delete details.dataset.hoverOpened; details.querySelector( 'summary' ).focus(); } } );
	details.addEventListener( 'pointerenter', () => hoverBreadcrumb( details ) );
	details.addEventListener( 'pointerleave', () => { if ( ! details.contains( document.activeElement ) ) { details.open = false; delete details.dataset.hoverOpened; } } );
	details.querySelector( 'summary' ).addEventListener( 'click', event => preserveHoveredMenu( details, event ) );
}
function hoverBreadcrumb( details ) {
	if ( matchMedia( '(hover: hover)' ).matches && ! details.open ) { details.open = true; details.dataset.hoverOpened = 'true'; }
}
function preserveHoveredMenu( details, event ) {
	if ( details.dataset.hoverOpened && details.open && event.detail > 0 ) { event.preventDefault(); }
	delete details.dataset.hoverOpened;
}
function connectLibraryBrowse() {
	document.addEventListener( 'click', event => {
		if ( event.target.closest( '[data-browse-upload]' ) ) { document.querySelector( 'form[data-xhr-upload] input[type=file]' )?.click(); }
	} );
}
