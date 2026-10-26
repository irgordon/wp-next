/** Two-step installer. Without JavaScript both steps stay visible and the form submits as one page. */
'use strict';
( function() {
  const wizard = document.querySelector( '[data-wizard]' );
  if ( ! wizard ) { return; }
  const steps = Array.from( wizard.querySelectorAll( '[data-wizard-step]' ) );
  const bar = wizard.querySelector( '[data-wizard-bar]' );
  const current = wizard.querySelector( '[data-wizard-current]' );
  let index = 0;
  function show( next ) {
    index = Math.max( 0, Math.min( steps.length - 1, next ) );
    steps.forEach( ( step, position ) => { step.hidden = position !== index; } );
    if ( bar ) { bar.style.width = String( ( ( index + 1 ) / steps.length ) * 100 ) + '%'; }
    if ( current ) { current.textContent = String( index + 1 ); }
    const focusTarget = steps[ index ].querySelector( 'input, button' );
    if ( focusTarget ) { focusTarget.focus(); }
  }
  wizard.querySelectorAll( '[data-wizard-next]' ).forEach( button => button.addEventListener( 'click', () => show( index + 1 ) ) );
  wizard.querySelectorAll( '[data-wizard-back]' ).forEach( button => button.addEventListener( 'click', () => show( index - 1 ) ) );
  wizard.classList.add( 'wizard-on' );
  show( 0 );
} )();
