/** DOM adapters for the design-system presentation of native form controls. */
const CONTROL_SELECTOR = 'input[type=search],input[name=s],input[type=number]';
const ASSET_ROOT = new URL( '../assets/wordpress/', import.meta.url );

export function enhanceAdminControls( root = document ) {
	controlInputs( root ).forEach( mountNativeControl );
}
export function observeAdminControls() {
	const observer = new MutationObserver( records => records.forEach( record => record.addedNodes.forEach( node => {
		if ( node.nodeType === Node.ELEMENT_NODE ) { enhanceAdminControls( node ); }
	} ) ) );
	observer.observe( document.body, { childList: true, subtree: true } );
}
function mountNativeControl( input ) {
	if ( input.closest( '.wp-search-control,.wp-number-control' ) ) { return; }
	if ( input.type === 'number' ) { mountNumberControl( input ); }
	else { mountSearchControl( input ); }
}
function mountSearchControl( input ) {
	const wrapper = wrapInput( input, 'wp-search-control' );
	wrapper.prepend( controlIcon( 'search' ) );
}
function mountNumberControl( input ) {
	if ( input.step === 'any' ) { return; }
	const wrapper = wrapInput( input, 'wp-number-control' );
	const buttons = [ numberButton( input, 1 ), numberButton( input, -1 ) ];
	wrapper.append( ...buttons );
	input.addEventListener( 'input', () => syncNumberButtons( input, buttons ) );
	new MutationObserver( () => syncNumberButtons( input, buttons ) ).observe( input, { attributes: true, attributeFilter: [ 'disabled', 'readonly', 'min', 'max', 'step' ] } );
	syncNumberButtons( input, buttons );
}
function numberButton( input, direction ) {
	const button = document.createElement( 'button' );
	button.type = 'button'; button.dataset.stepDirection = direction;
	button.setAttribute( 'aria-label', ( direction > 0 ? 'Increase ' : 'Decrease ' ) + inputLabel( input ) );
	button.append( controlIcon( direction > 0 ? 'plus' : 'minus' ) );
	button.addEventListener( 'click', () => stepNativeInput( input, direction ) );
	return button;
}
function syncNumberButtons( input, buttons ) {
	buttons.forEach( button => { button.disabled = input.matches( ':disabled,[readonly],[step=any]' ); } );
}
function stepNativeInput( input, direction ) {
	if ( input.matches( ':disabled,[readonly],[step=any]' ) ) { return; }
	const previous = input.value;
	input.stepUp( direction );
	if ( previous === input.value ) { return; }
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}
function wrapInput( input, className ) {
	const wrapper = document.createElement( 'span' );
	wrapper.className = className;
	input.before( wrapper ); wrapper.append( input );
	return wrapper;
}
function controlInputs( root ) {
	const inputs = Array.from( root.querySelectorAll( CONTROL_SELECTOR ) );
	if ( root.matches?.( CONTROL_SELECTOR ) ) { inputs.unshift( root ); }
	return inputs;
}
function inputLabel( input ) {
	return ( input.labels?.[ 0 ]?.textContent || input.getAttribute( 'aria-label' ) || input.name || 'value' ).trim();
}
function controlIcon( name ) {
	const icon = document.createElement( 'img' );
	icon.src = new URL( name + '.svg', ASSET_ROOT ).href; icon.alt = '';
	return icon;
}
