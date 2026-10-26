/** Responsive navigation. Without JavaScript, all navigation remains available. */
'use strict';
( function() {
  const toggle = document.querySelector( '.menu-toggle' );
  const navigation = document.getElementById( 'admin-navigation' );
  if ( ! toggle || ! navigation ) { return; }
  const mobile = window.matchMedia( '(max-width: 760px)' );
  function setExpanded( expanded ) {
    navigation.hidden = ! expanded;
    toggle.setAttribute( 'aria-expanded', String( expanded ) );
  }
  function adapt() {
    // Never hide a link while it holds keyboard focus after a viewport change.
    setExpanded( ! mobile.matches || navigation.contains( document.activeElement ) );
  }
  toggle.addEventListener( 'click', () => setExpanded( toggle.getAttribute( 'aria-expanded' ) !== 'true' ) );
  navigation.addEventListener( 'keydown', event => {
    if ( event.key === 'Escape' && mobile.matches ) {
      setExpanded( false );
      toggle.focus();
    }
  } );
  mobile.addEventListener( 'change', adapt );
  adapt();
} )();
( function() {
  // Inline list panels (quick edit, permanent-delete confirmation). The
  // panels are ordinary table rows; without JavaScript the full editor
  // remains the path for the same changes.
  function setOpen( button, row, open ) {
    row.hidden = ! open;
    button.setAttribute( 'aria-expanded', String( open ) );
    if ( open ) {
      const field = row.querySelector( 'input:not([type=hidden]), select, button[type=submit]' );
      if ( field ) { field.focus(); }
    }
  }
  document.querySelectorAll( '[data-toggle-row]' ).forEach( button => {
    const row = document.getElementById( button.getAttribute( 'data-toggle-row' ) );
    if ( ! row ) { return; }
    button.addEventListener( 'click', () => setOpen( button, row, row.hidden ) );
  } );
  document.querySelectorAll( '[data-close-row]' ).forEach( button => {
    const row = document.getElementById( button.getAttribute( 'data-close-row' ) );
    if ( ! row ) { return; }
    button.addEventListener( 'click', () => {
      row.hidden = true;
      const toggle = document.querySelector( '[data-toggle-row="' + row.id + '"]' );
      if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); toggle.focus(); }
    } );
  } );
} )();
( function() {
  // Per-file uploads for the media library: one request per file, no batch
  // atomicity. Without JavaScript the same forms submit classically.
  document.querySelectorAll( 'form[data-xhr-upload]' ).forEach( function( form ) {
    form.addEventListener( 'submit', async function( event ) {
      const input = form.querySelector( 'input[type="file"]' );
      if ( ! input || input.files.length === 0 ) { return; }
      event.preventDefault();
      const results = form.parentElement.querySelector( '.upload-results' );
      const list = document.createElement( 'ul' );
      if ( results ) { results.replaceChildren( list ); }
      const action = form.querySelector( 'input[name="action"]' ).value;
      let failures = 0;
      for ( const file of input.files ) {
        const item = document.createElement( 'li' );
        item.textContent = file.name + ': uploading…';
        list.append( item );
        const data = new FormData();
        new FormData( form ).forEach( function( value, name ) { if ( name !== input.name ) { data.append( name, value ); } } );
        data.append( input.name, file, file.name );
        data.append( 'ajax', '1' );
        try {
          const response = await fetch( location.href, { method: 'POST', body: data, credentials: 'same-origin' } );
          const payload = await response.json();
          if ( ! response.ok || ! payload.ok ) { throw new Error( payload.error || 'Upload failed.' ); }
          item.textContent = file.name + ': uploaded.';
        } catch ( error ) { failures++; item.textContent = file.name + ': ' + error.message; }
      }
      if ( failures === 0 ) {
        location.assign( action === 'upload_document' ? 'media.php?kind=documents&uploaded=1' : 'media.php?uploaded=1' );
      }
    } );
  } );
} )();
/** Form-based menu rows: add, remove and reorder without line syntax. */
( function() {
	function renumber( editor ) {
		editor.querySelectorAll( '.menu-row' ).forEach( function( row, index ) {
			const order = row.querySelector( 'input[type="number"]' );
			if ( order ) { order.value = ( index + 1 ) * 10; }
		} );
	}
	document.addEventListener( 'click', function( event ) {
		const add = event.target.closest( '[data-menu-add]' );
		const remove = event.target.closest( '[data-menu-remove]' );
		const move = event.target.closest( '[data-menu-move]' );
		if ( add ) {
			const editor = add.closest( '[data-menu-editor]' );
			const rows = editor.querySelectorAll( '.menu-row' );
			const clone = rows[ rows.length - 1 ].cloneNode( true );
			clone.querySelectorAll( 'input' ).forEach( function( input ) { input.value = input.type === 'number' ? '' : ''; } );
			const location = editor.dataset.menuEditor;
			const index = rows.length;
			clone.querySelectorAll( '[name]' ).forEach( function( field ) { field.name = field.name.replace( /\[\d+\]/, '[' + index + ']' ); } );
			rows[ rows.length - 1 ].after( clone );
			renumber( editor );
			clone.querySelector( 'input' ).focus();
		}
		if ( remove ) {
			const row = remove.closest( '.menu-row' );
			const editor = row.closest( '[data-menu-editor]' );
			if ( editor.querySelectorAll( '.menu-row' ).length > 1 ) { row.remove(); renumber( editor ); }
		}
		if ( move ) {
			const row = move.closest( '.menu-row' );
			const editor = row.closest( '[data-menu-editor]' );
			const sibling = Number( move.dataset.menuMove ) < 0 ? row.previousElementSibling : row.nextElementSibling;
			if ( sibling && sibling.classList.contains( 'menu-row' ) ) {
				if ( Number( move.dataset.menuMove ) < 0 ) { sibling.before( row ); } else { sibling.after( row ); }
				renumber( editor );
				move.focus();
			}
		}
	} );
} )();
