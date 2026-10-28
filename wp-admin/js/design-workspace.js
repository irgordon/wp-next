/** Progressive section navigation keeps the original native design form intact. */
const PANEL_SELECTOR = '[data-design-panel]';
const DEFAULT_PANEL = 'theme';
const STATE_LABELS = Object.freeze( { published: 'Published design', draft: 'Saved draft', unsaved: 'Unsaved changes' } );

initializeDesignWorkspace();

function initializeDesignWorkspace() {
	const context = readDesignWorkspace();
	if ( ! context ) { return; }
	prepareDesignPanels( context );
	connectDesignNavigation( context );
	connectDesignForm( context );
	showDesignLocation( context );
}
function prepareDesignPanels( context ) {
	context.panels.forEach( panel => panel.tabIndex = -1 );
	document.body.classList.add( 'design-workspace-ready' );
}
function connectDesignNavigation( context ) {
	context.links.forEach( link => link.addEventListener( 'click', event => {
		if ( event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) { return; }
		showDesignPanel( context, link.dataset.designTarget );
	} ) );
	window.addEventListener( 'hashchange', () => showDesignLocation( context ) );
}
function connectDesignForm( context ) {
	context.form.addEventListener( 'input', () => updateDesignState( context ) );
	context.form.addEventListener( 'change', () => updateDesignState( context ) );
	context.form.addEventListener( 'invalid', event => revealInvalidPanel( context, event.target ), true );
	context.form.addEventListener( 'submit', () => { context.status.textContent = 'Saving design…'; } );
	document.addEventListener( 'click', event => scheduleDesignState( context, event ) );
}
function scheduleDesignState( context, event ) {
	if ( event.target.closest?.( 'button[type=button]' ) ) { requestAnimationFrame( () => updateDesignState( context ) ); }
}
function revealInvalidPanel( context, input ) {
	if ( input !== context.form.querySelector( 'input:invalid,select:invalid,textarea:invalid' ) ) { return; }
	const panel = input.closest( PANEL_SELECTOR );
	if ( panel ) { showDesignPanel( context, panel.dataset.designPanel ); history.replaceState( null, '', '#' + panel.id ); }
}
function showDesignLocation( context ) {
	const target = context.panels.find( panel => '#' + panel.id === location.hash );
	showDesignPanel( context, target?.dataset.designPanel || DEFAULT_PANEL );
}
function showDesignPanel( context, selected ) {
	context.panels.forEach( panel => panel.hidden = panel.dataset.designPanel !== selected );
	context.links.forEach( link => {
		if ( link.dataset.designTarget === selected ) { link.setAttribute( 'aria-current', 'location' ); }
		else { link.removeAttribute( 'aria-current' ); }
	} );
}
function updateDesignState( context ) {
	const dirty = context.initialState === 'unsaved' || serializeDesignForm( context.form ) !== context.baseline;
	context.status.dataset.dirty = String( dirty );
	context.status.textContent = dirty ? STATE_LABELS.unsaved : STATE_LABELS[ context.initialState ];
}

function readDesignWorkspace() {
	const form = document.getElementById( 'design-form' );
	const status = document.querySelector( '[data-design-saved-state]' );
	if ( ! form || ! status ) { return null; }
	return { form, status, baseline: serializeDesignForm( form ), initialState: status.dataset.designSavedState, panels: Array.from( document.querySelectorAll( PANEL_SELECTOR ) ), links: Array.from( document.querySelectorAll( '[data-design-target]' ) ) };
}
function serializeDesignForm( form ) {
	return new URLSearchParams( new FormData( form ) ).toString();
}
