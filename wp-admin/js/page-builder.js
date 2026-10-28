/** Canonical nested block editor. Server validation remains authoritative. */
'use strict';
import { mountVisualWorkspace } from './visual-builder.js?v=bf00e4b01eab';
import { refreshCodeField } from './code-editor.js';
import { createBlock as makeBlock, locateBlock } from './visual-builder-model.js';
( function() {
	const sharedPicker = { loadMedia: null, loadDocuments: null, moreMedia: null, moreDocuments: null, searchMedia: null, searchDocuments: null };
	function initBuilder( root, raw, form, options ) {
	const status = options.status;
	const dialog = document.getElementById( 'media-picker' );
	const docDialog = document.getElementById( 'document-picker' );
	const docItems = document.getElementById( 'document-picker-items' );
	let fileTarget = null;
	let docCache = [];
	let docSearch = '';
	let nextDocPage = null;
	let documentRequestSequence = 0;
	let imageRequestSequence = 0;
	const mediaItems = document.getElementById( 'media-picker-items' );
	const types = [ 'paragraph', 'heading', 'list', 'quote', 'code', 'image', 'file', 'columns', 'html', 'divider' ];
	const ids = new WeakMap();
	let serial = 0;
	let documentState = { version: 1, blocks: [] };
	let selectedRange = null;
	
	let imageTarget = null;
	let nextMediaPage = null;
	let mediaSearch = '';
	let valid = true;
	let mediaCache = Array.from( document.querySelectorAll( '#featured_media_id option[data-url]' ) ).map( option => ( { id: Number( option.value ), url: option.dataset.url, alt_text: option.dataset.alt, original_name: option.textContent, caption: '' } ) );
	const key = options.draftKey ? 'wp-draft:' + options.draftKey : null;
	function node( tag, text, className ) {
		const element = document.createElement( tag );
		if ( text !== undefined ) { element.textContent = text; }
		if ( className ) { element.className = className; }
		return element;
	}
	function button( label, action ) {
		const element = node( 'button', label );
		element.type = 'button';
		element.addEventListener( 'click', action );
		return element;
	}
	function announce( message ) { status.textContent = message; }
	function safeInline( html ) {
		const parsed = new DOMParser().parseFromString( '<div>' + html + '</div>', 'text/html' );
		const allowed = [ 'STRONG', 'B', 'EM', 'I', 'A', 'CODE', 'BR', 'LI', 'U', 'SUP', 'SUB' ];
		function clean( parent ) {
			Array.from( parent.children ).forEach( function( child ) {
				clean( child );
				if ( ! allowed.includes( child.tagName ) ) {
					if ( [ 'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'SVG', 'MATH', 'TEMPLATE', 'META', 'LINK' ].includes( child.tagName ) ) { child.remove(); }
					else { child.replaceWith( ...child.childNodes ); }
					return;
				}
				const href = child.getAttribute( 'href' );
				Array.from( child.attributes ).forEach( function( attribute ) { child.removeAttribute( attribute.name ); } );
				if ( child.tagName === 'A' && href ) {
					try {
						const url = new URL( href, location.href );
						if ( [ 'http:', 'https:', 'mailto:', 'tel:', 'sms:' ].includes( url.protocol ) ) { child.setAttribute( 'href', href ); }
					} catch ( error ) { /* Invalid links become text links without a URL. */ }
				}
			} );
		}
		clean( parsed.body );
		return parsed.body.innerHTML;
	}
	function cacheDraft() {
		if ( ! key ) { return true; }
		try {
			const data = {};
			data[ 'term_ids[]' ] = Array.from( form.querySelectorAll( 'input[name="term_ids[]"]:checked' ) ).map( input => input.value );
			for ( const [ name, value ] of new FormData( form ) ) {
				if ( name !== '_wpnonce' && name !== 'term_ids[]' && typeof value === 'string' ) { data[ name ] = value; }
			}
			sessionStorage.setItem( key, JSON.stringify( data ) );
			return true;
		} catch ( error ) { announce( 'Browser draft storage is unavailable. Save changes to keep your work.' ); return false; }
	}
	function sync() {
		raw.value = JSON.stringify( documentState );
		refreshCodeField( raw );
		cacheDraft();
		updateState();
		workspace?.documentChanged();
	}
	function parse() {
		try {
			const value = raw.value.trim();
			const parsed = value.startsWith( '{' ) ? JSON.parse( value ) : { version: 1, blocks: value ? [ { type: 'html', html: value } ] : [] };
			if ( parsed.version !== 1 || ! Array.isArray( parsed.blocks ) ) { throw new Error( 'Use a version 1 block document.' ); }
			function validate( blocks, depth ) {
				if ( depth > 8 ) { throw new Error( 'Too many nested layouts.' ); }
				blocks.forEach( function( block ) {
					if ( ! block || ! types.includes( block.type ) ) { throw new Error( 'Unsupported block type. Your raw document is preserved.' ); }
					if ( block.type === 'columns' ) {
						if ( ! Array.isArray( block.columns ) ) { throw new Error( 'Invalid columns.' ); }
						block.columns.forEach( children => validate( children, depth + 1 ) );
					}
				} );
			}
			validate( parsed.blocks, 0 );
			documentState = parsed;
			valid = true;
			render();
			announce( '' );
		} catch ( error ) {
			valid = false;
			root.replaceChildren( node( 'p', 'The raw document needs correction before visual editing can resume.', 'notice notice-error' ) );
			announce( error.message );
		}
		refreshCodeField( raw );
	}
	function addToolbar( collection, depth ) {
		const toolbar = node( 'div', undefined, 'builder-toolbar' );
		const select = node( 'select', undefined, 'block-select' );
		select.setAttribute( 'aria-label', depth > 0 ? 'Block type for this column' : 'Block type' );
		types.filter( type => depth < 6 || type !== 'columns' ).forEach( function( type ) { const option = node( 'option', type.charAt( 0 ).toUpperCase() + type.slice( 1 ) ); option.value = type; select.append( option ); } );
		toolbar.append( select, button( 'Add block', function() { const block = makeBlock( select.value ); collection.push( block ); sync(); render(); focusBlock( block ); announce( 'Block added.' ); } ) );
		return toolbar;
	}
	function focusBlock( block ) {
		const card = root.querySelector( '[data-block-id="' + ids.get( block ) + '"]' );
		const target = card && ( card.querySelector( '[contenteditable], textarea, input' ) || card.querySelector( 'button:not(:disabled)' ) );
		if ( target ) { target.focus(); }
	}
	function field( label, value, changed, multiline, language ) {
		const wrapper = node( 'div', undefined, 'form-field' );
		const id = 'block-field-' + ++serial;
		const input = node( multiline ? 'textarea' : 'input' );
		input.id = id;
		input.value = value || '';
		if ( language ) { input.dataset.codeLanguage = language; input.dataset.codeName = 'block.' + ( language === 'text' ? 'txt' : language ); }
		const labelNode = node( 'label', label ); labelNode.htmlFor = id;
		input.addEventListener( 'input', function() { changed( input.value ); sync(); } );
		wrapper.append( labelNode, input );
		return wrapper;
	}
	function rememberSelection( editable ) {
		const selection = window.getSelection();
		if ( selection && selection.rangeCount && editable.contains( selection.anchorNode ) ) { selectedRange = selection.getRangeAt( 0 ).cloneRange(); }
	}
	function richToolbar( editable, block ) {
		const toolbar = node( 'div', undefined, 'block-toolbar' );
		function format( tag, href ) {
			if ( ! selectedRange || ! editable.contains( selectedRange.commonAncestorContainer ) || selectedRange.collapsed ) { announce( 'Select text in this block first.' ); return; }
			const wrapper = node( tag );
			if ( href ) {
				try { const url = new URL( href, location.href ); if ( ! [ 'http:', 'https:', 'mailto:', 'tel:', 'sms:' ].includes( url.protocol ) ) { throw new Error(); } }
				catch ( error ) { announce( 'Use an HTTP, HTTPS, mail, telephone, or relative link.' ); return; }
				wrapper.setAttribute( 'href', href );
			}
			wrapper.append( selectedRange.extractContents() ); selectedRange.insertNode( wrapper );
			block.html = safeInline( editable.innerHTML ); sync(); editable.focus(); announce( 'Formatting applied.' );
		}
		[ [ 'Bold', 'strong' ], [ 'Italic', 'em' ] ].forEach( function( [ label, tag ] ) {
			const control = button( label, () => format( tag ) ); control.addEventListener( 'mousedown', event => event.preventDefault() ); toolbar.append( control );
		} );
		const url = node( 'input' ); url.type = 'text'; url.placeholder = 'Link URL'; url.setAttribute( 'aria-label', 'Link URL' );
		toolbar.append( url, button( 'Apply link', () => format( 'a', url.value ) ) );
		return toolbar;
	}
	function renderBlock( block, collection, index, depth ) {
		if ( ! ids.has( block ) ) { ids.set( block, 'b' + ++serial ); }
		const card = node( 'section', undefined, 'block-card' ); card.dataset.blockId = ids.get( block ); card.dataset.blockType = block.type;
		const header = node( 'header' ); const actions = node( 'div', undefined, 'block-toolbar' );
		const up = button( '↑', function() { [ collection[ index - 1 ], collection[ index ] ] = [ block, collection[ index - 1 ] ]; sync(); render(); focusBlock( block ); announce( 'Block moved up.' ); } );
		up.disabled = index === 0; up.setAttribute( 'aria-label', 'Move ' + block.type + ' block up' );
		const down = button( '↓', function() { [ collection[ index + 1 ], collection[ index ] ] = [ block, collection[ index + 1 ] ]; sync(); render(); focusBlock( block ); announce( 'Block moved down.' ); } );
		down.disabled = index === collection.length - 1; down.setAttribute( 'aria-label', 'Move ' + block.type + ' block down' );
		const remove = button( 'Remove', function() { collection.splice( index, 1 ); sync(); render(); root.querySelector( 'button' ).focus(); announce( 'Block removed.' ); } );
		actions.append( up, down, remove ); header.append( node( 'strong', block.type.charAt( 0 ).toUpperCase() + block.type.slice( 1 ) ), actions ); card.append( header );
		if ( block.type === 'columns' ) {
			const columns = node( 'div', undefined, 'block-columns' );
			block.columns.forEach( function( children ) { const column = node( 'div' ); column.append( addToolbar( children, depth + 1 ) ); children.forEach( ( child, i ) => column.append( renderBlock( child, children, i, depth + 1 ) ) ); columns.append( column ); } ); card.append( columns );
		} else if ( block.type === 'image' ) {
			const media = mediaCache.find( item => item.id === Number( block.media_id ) );
			if ( media ) { const image = node( 'img', undefined, 'featured-preview' ); image.src = media.url; image.alt = block.alt || ''; card.append( image ); }
			card.append( button( Number( block.media_id ) > 0 ? 'Change image' : 'Choose image', function() { imageTarget = block; activatePicker(); if ( ! dialog.open ) { dialog.showModal(); } loadMedia(); } ), field( 'Alternative text', block.alt, value => { block.alt = value; } ), field( 'Caption', block.caption, value => { block.caption = value; } ) );
		} else if ( block.type === 'file' ) {
			const doc = docCache.find( item => item.id === Number( block.media_id ) );
			card.append( node( 'p', Number( block.media_id ) > 0 ? ( doc ? doc.original_name : 'Document #' + block.media_id ) : 'No document selected.', 'description' ), button( Number( block.media_id ) > 0 ? 'Change document' : 'Choose document', function() { fileTarget = block; activatePicker(); if ( ! docDialog.open ) { docDialog.showModal(); } loadDocuments(); } ), field( 'Link label', block.label || '', value => { block.label = value; } ) );
		} else if ( block.type === 'html' || block.type === 'code' ) {
			card.append( field( block.type === 'code' ? 'Code (displayed as text)' : 'HTML (sanitized on save and display)', block.type === 'code' ? block.text : block.html, value => { block[ block.type === 'code' ? 'text' : 'html' ] = value; }, true, block.type === 'code' ? 'text' : 'html' ) );
		} else if ( block.type === 'divider' ) { card.append( node( 'hr' ) ); }
		else {
			if ( block.type === 'heading' ) {
				const level = node( 'select' ); level.setAttribute( 'aria-label', 'Heading level' );
				for ( let n = 2; n <= 6; n++ ) { const option = node( 'option', 'Heading ' + n ); option.value = n; option.selected = n === Number( block.level || 2 ); level.append( option ); }
				level.addEventListener( 'change', () => { block.level = Number( level.value ); sync(); } ); card.append( level );
			}
			if ( block.type === 'list' ) {
				const ordered = node( 'select' ); ordered.setAttribute( 'aria-label', 'List style' );
				[ [ 'Bulleted', '0' ], [ 'Numbered', '1' ] ].forEach( ( [ label, value ] ) => { const option = node( 'option', label ); option.value = value; option.selected = ( block.ordered ? '1' : '0' ) === value; ordered.append( option ); } );
				ordered.addEventListener( 'change', () => { block.ordered = ordered.value === '1'; sync(); render(); focusBlock( block ); } ); card.append( ordered );
			}
			const editable = node( block.type === 'list' ? ( block.ordered ? 'ol' : 'ul' ) : 'div' );
			editable.contentEditable = 'true'; editable.setAttribute( 'role', 'textbox' ); editable.setAttribute( 'aria-label', block.type + ' content' ); editable.setAttribute( 'aria-multiline', 'true' ); editable.dataset.placeholder = 'Start writing…';
			editable.innerHTML = safeInline( block.html || '' );
			editable.style.textAlign = block.align || 'left';
			editable.addEventListener( 'input', () => { block.html = safeInline( editable.innerHTML ); sync(); } );
			editable.addEventListener( 'keyup', () => rememberSelection( editable ) ); editable.addEventListener( 'mouseup', () => rememberSelection( editable ) );
			editable.addEventListener( 'paste', function( event ) { event.preventDefault(); const text = event.clipboardData.getData( 'text/plain' ); const selection = window.getSelection(); if ( selection && selection.rangeCount ) { const range = selection.getRangeAt( 0 ); range.deleteContents(); const inserted = document.createTextNode( text ); range.insertNode( inserted ); range.setStartAfter( inserted ); range.collapse( true ); selection.removeAllRanges(); selection.addRange( range ); } block.html = safeInline( editable.innerHTML ); sync(); } );
			card.append( richToolbar( editable, block ), editable );
			const align = node( 'select' ); align.setAttribute( 'aria-label', 'Text alignment' ); [ 'left', 'center', 'right' ].forEach( value => { const option = node( 'option', value ); option.value = value; option.selected = ( block.align || 'left' ) === value; align.append( option ); } ); align.addEventListener( 'change', () => { block.align = align.value; editable.style.textAlign = align.value; sync(); } ); card.append( align );
		}
		return card;
	}
	function render() {
		selectedRange = null;
		root.replaceChildren( node( 'h2', options.heading || 'Content' ), addToolbar( documentState.blocks, 0 ) );
		if ( documentState.blocks.length === 0 ) { root.append( node( 'p', 'Add your first block to start building.', 'empty' ) ); }
		documentState.blocks.forEach( ( block, index ) => root.append( renderBlock( block, documentState.blocks, index, 0 ) ) );
		visualAfterRender();
	}
	async function loadMedia( page = 1 ) {
		const sequence = ++imageRequestSequence;
		if ( page === 1 ) { pickerFeedback( mediaItems, 'images', mediaSearch, 'loading', () => loadMedia() ); }
		try {
			const mediaUrl = new URL( root.dataset.mediaUrl, location.href ); mediaUrl.searchParams.set( 'page', page ); mediaUrl.searchParams.set( 's', mediaSearch );
			const response = await fetch( mediaUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } } );
			if ( ! response.ok ) { throw new Error( 'Media could not be loaded.' ); }
			const data = await response.json();
			if ( sequence !== imageRequestSequence ) { return; }
			mediaCache = Array.from( new Map( [ ...mediaCache, ...data.items ].map( item => [ Number( item.id ), item ] ) ).values() );
			nextMediaPage = data.next; document.getElementById( 'media-picker-more' ).classList.toggle( 'hidden', ! nextMediaPage );
			if ( page === 1 ) { mediaItems.replaceChildren(); }
			data.items.forEach( function( media ) { const card = node( 'div', undefined, 'media-card' ); const image = node( 'img' ); image.src = media.url; image.alt = media.alt_text || ''; card.append( image, button( media.original_name, function() { if ( ! imageTarget ) { return; } if ( imageTarget === 'featured' ) { const select = document.getElementById( 'featured_media_id' ); let option = Array.from( select.options ).find( item => Number( item.value ) === Number( media.id ) ); if ( ! option ) { option = new Option( media.original_name, media.id ); select.add( option ); } select.value = String( media.id ); cacheDraft(); dialog.close(); document.getElementById( 'choose-featured-image' ).focus(); return; } imageTarget.media_id = media.id; imageTarget.alt = media.alt_text; imageTarget.caption = media.caption; sync(); dialog.close(); render(); focusBlock( imageTarget ); } ) ); mediaItems.append( card ); } );
			if ( data.items.length === 0 && page === 1 ) { pickerFeedback( mediaItems, 'images', mediaSearch, 'empty', () => loadMedia(), clearImageSearch ); }
		} catch ( error ) { if ( sequence === imageRequestSequence ) { pickerFeedback( mediaItems, 'images', mediaSearch, 'error', () => loadMedia() ); } }
	}
	async function loadDocuments( page = 1 ) {
		const sequence = ++documentRequestSequence;
		if ( page === 1 ) { pickerFeedback( docItems, 'documents', docSearch, 'loading', () => loadDocuments() ); }
		try {
			const docUrl = new URL( root.dataset.documentsUrl, location.href ); docUrl.searchParams.set( 'page', page ); docUrl.searchParams.set( 's', docSearch );
			const response = await fetch( docUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } } );
			if ( ! response.ok ) { throw new Error( 'Documents could not be loaded.' ); }
			const data = await response.json();
			if ( sequence !== documentRequestSequence ) { return; }
			docCache = Array.from( new Map( [ ...docCache, ...data.items ].map( item => [ Number( item.id ), item ] ) ).values() );
			nextDocPage = data.next; document.getElementById( 'document-picker-more' ).classList.toggle( 'hidden', ! nextDocPage );
			if ( page === 1 ) { docItems.replaceChildren(); }
			data.items.forEach( function( doc ) { const card = node( 'div', undefined, 'media-card' ); card.append( node( 'p', doc.original_name ), node( 'p', String( doc.format ).toUpperCase() + ' · ' + Math.ceil( doc.size / 1024 ) + ' KB · ' + doc.visibility, 'muted' ), button( 'Use this document', function() { if ( ! fileTarget ) { return; } fileTarget.media_id = doc.id; if ( ! fileTarget.label ) { fileTarget.label = doc.original_name; } sync(); docDialog.close(); render(); focusBlock( fileTarget ); } ) ); docItems.append( card ); } );
			if ( data.items.length === 0 && page === 1 ) { pickerFeedback( docItems, 'documents', docSearch, 'empty', () => loadDocuments(), clearDocumentSearch ); }
		} catch ( error ) { if ( sequence === documentRequestSequence ) { pickerFeedback( docItems, 'documents', docSearch, 'error', () => loadDocuments() ); } }
	}
	function pickerFeedback( container, kind, search, state, retry, clear ) {
		const panel = node( 'section', undefined, 'picker-state picker-state-' + state );
		panel.setAttribute( 'role', state === 'error' ? 'alert' : 'status' );
		const title = state === 'loading' ? 'Loading ' + kind + '…' : state === 'error' ? 'The library could not be loaded' : search ? 'No matching ' + kind + ' found' : kind === 'images' ? 'Your image library is empty' : 'No documents are available';
		const message = state === 'loading' ? 'Searching your library.' : state === 'error' ? 'Check your connection and try again. You may need to sign in again.' : search ? 'Try another name or clear your search.' : 'Add a file to the media library, then refresh this list.';
		panel.append( node( 'span', state === 'error' ? '!' : '▧', 'picker-state-icon' ), node( 'h3', title ), node( 'p', message ) );
		if ( state !== 'loading' ) { panel.append( button( search && clear ? 'Clear search' : state === 'error' ? 'Try again' : 'Refresh library', search && clear ? clear : retry ) ); }
		container.replaceChildren( panel );
	}
	function clearImageSearch() {
		mediaSearch = ''; document.getElementById( 'media-picker-search' ).value = ''; loadMedia();
	}
	function clearDocumentSearch() {
		docSearch = ''; document.getElementById( 'document-picker-search' ).value = ''; loadDocuments();
	}
	const chooseFeatured = document.getElementById( 'choose-featured-image' );
	if ( chooseFeatured ) { chooseFeatured.addEventListener( 'click', () => { imageTarget = 'featured'; activatePicker(); if ( ! dialog.open ) { dialog.showModal(); } loadMedia(); } ); }
	function activatePicker() {
		sharedPicker.loadMedia = page => loadMedia( page );
		sharedPicker.loadDocuments = page => loadDocuments( page );
		sharedPicker.moreMedia = () => { if ( nextMediaPage ) { loadMedia( nextMediaPage ); } };
		sharedPicker.moreDocuments = () => { if ( nextDocPage ) { loadDocuments( nextDocPage ); } };
		sharedPicker.searchMedia = value => { mediaSearch = value; loadMedia(); };
		sharedPicker.searchDocuments = value => { docSearch = value; loadDocuments(); };
	}
	if ( dialog && ! dialog.dataset.bound ) {
		dialog.dataset.bound = '1';
		document.getElementById( 'document-picker-search-button' ).addEventListener( 'click', () => { if ( sharedPicker.searchDocuments ) { sharedPicker.searchDocuments( document.getElementById( 'document-picker-search' ).value ); } } );
		document.getElementById( 'document-picker-more' ).addEventListener( 'click', () => { if ( sharedPicker.moreDocuments ) { sharedPicker.moreDocuments(); } } );
		document.getElementById( 'close-document-picker' ).addEventListener( 'click', () => docDialog.close() );
		document.getElementById( 'media-picker-search-button' ).addEventListener( 'click', () => { if ( sharedPicker.searchMedia ) { sharedPicker.searchMedia( document.getElementById( 'media-picker-search' ).value ); } } );
		document.getElementById( 'media-picker-more' ).addEventListener( 'click', () => { if ( sharedPicker.moreMedia ) { sharedPicker.moreMedia(); } } );
		document.getElementById( 'close-media-picker' ).addEventListener( 'click', () => dialog.close() );
	}
	raw.addEventListener( 'input', () => { parse(); cacheDraft(); } );
	form.addEventListener( 'input', cacheDraft );
	form.addEventListener( 'submit', function( event ) { if ( ! valid ) { event.preventDefault(); announce( 'Correct the raw block document before saving.' ); workspace?.showRawEditor(); raw.closest( 'details' ).open = true; raw.focus(); } else { cacheDraft(); if ( key ) { try { sessionStorage.setItem( 'wp-pending-draft', key ); } catch ( error ) { /* Server saving remains available. */ } } } } );

	/* The form adapter owns persistence; the visual workspace owns canvas interactions. */
	const canvas = document.getElementById( 'wp-visual-canvas' );
	const inspector = document.getElementById( 'wp-visual-inspector' );
	const stateLine = document.getElementById( 'editor-state' );
	let workspace = null;
	let selectedPath = null;
	const savedSnapshot = editorSnapshot();
	function visualAfterRender() {
		updateState();
		showInspector();
		workspace?.documentChanged();
	}
	function updateState() {
		if ( ! stateLine ) { return; }
		const statusSelect = form.elements.namedItem( 'post_status' );
		const status = form.dataset.designEmbed === '1' ? form.dataset.persistedStatus : statusSelect?.value;
		const label = status === 'published' ? 'Published' : status === 'archived' ? 'Archived' : 'Draft';
		stateLine.textContent = label + ' · ' + ( editorSnapshot() !== savedSnapshot ? 'Unsaved changes' : 'All changes saved' );
	}
	function showInspector() {
		if ( ! inspector ) { return; }
		inspector.replaceChildren();
		if ( ! selectedPath ) { return; }
		const target = locateBlock( documentState, selectedPath );
		if ( ! target.block ) { return; }
		const card = root.querySelector( '[data-block-id="' + ids.get( target.block ) + '"]' );
		if ( card ) { inspector.append( card ); }
	}
	function applyWorkspaceChange( change ) {
		documentState = change.document;
		selectedPath = change.selection;
		valid = true;
		sync();
		render();
	}
	function selectWorkspaceBlock( path ) {
		selectedPath = path;
		render();
	}
	function pickWorkspaceAsset( path, type ) {
		selectWorkspaceBlock( path );
		activatePicker();
		if ( type === 'image' ) {
			imageTarget = locateBlock( documentState, path ).block;
			if ( ! dialog.open ) { dialog.showModal(); } loadMedia();
		} else {
			fileTarget = locateBlock( documentState, path ).block;
			if ( ! docDialog.open ) { docDialog.showModal(); } loadDocuments();
		}
	}
	function switchEditorView( visual ) {
		if ( ! cacheDraft() ) { return; }
		try {
			sessionStorage.setItem( 'wp-editor-handoff', key );
			location.assign( editorViewAddress( visual ) );
		} catch ( error ) { announce( 'Save your changes before switching editors; browser draft storage is unavailable.' ); }
	}
	function editorViewAddress( visual ) {
		const address = new URL( location.href );
		address.searchParams.delete( 'updated' );
		address.searchParams.delete( 'created' );
		if ( visual ) { address.searchParams.set( 'editor', 'visual' ); }
		else { address.searchParams.delete( 'editor' ); }
		return address.href;
	}
	function editorSnapshot() {
		return JSON.stringify( Array.from( new FormData( form ) ).filter( ( [ name ] ) => name !== '_wpnonce' ) );
	}
	function restoreEditorDraft( data ) {
		Object.entries( data ).forEach( restoreDraftField );
		parse();
		document.getElementById( 'draft-recovery' ).classList.add( 'hidden' );
		updateState();
	}
	function restoreDraftField( [ name, value ] ) {
		if ( name === 'term_ids[]' ) {
			const values = Array.isArray( value ) ? value : [ value ];
			form.querySelectorAll( 'input[name="term_ids[]"]' ).forEach( input => { input.checked = values.includes( input.value ); } );
			return;
		}
		const control = form.elements.namedItem( name );
		if ( control && 'value' in control ) { control.value = value; }
	}
	if ( canvas && options.postEditor ) {
		document.getElementById( 'mode-visual' ).addEventListener( 'click', () => switchEditorView( true ) );
		document.getElementById( 'mode-form' ).addEventListener( 'click', () => switchEditorView( false ) );
		form.addEventListener( 'input', updateState );
		form.addEventListener( 'change', updateState );
	}
	parse();
	if ( ! options.postEditor ) { return; }
	try {
		if ( form.dataset.saved === '1' ) { const previous = sessionStorage.getItem( 'wp-pending-draft' ); if ( previous ) { sessionStorage.removeItem( previous ); } sessionStorage.removeItem( key ); sessionStorage.removeItem( 'wp-pending-draft' ); }
		const saved = sessionStorage.getItem( key );
		if ( saved ) {
			const data = JSON.parse( saved );
			if ( data.post_content !== raw.value || data.post_title !== form.elements.post_title.value ) { document.getElementById( 'draft-recovery' ).classList.remove( 'hidden' ); }
			document.getElementById( 'restore-draft' ).addEventListener( 'click', () => { restoreEditorDraft( data ); announce( 'Browser draft restored. Review it before saving.' ); } );
			if ( sessionStorage.getItem( 'wp-editor-handoff' ) === key ) { restoreEditorDraft( data ); sessionStorage.removeItem( 'wp-editor-handoff' ); }
		}
		document.getElementById( 'discard-draft' ).addEventListener( 'click', function() { sessionStorage.removeItem( key ); document.getElementById( 'draft-recovery' ).classList.add( 'hidden' ); } );
	} catch ( error ) { /* A storage failure cannot prevent editing or server saves. */ }
	if ( form.dataset.editorView === 'visual' ) {
		workspace = mountVisualWorkspace( {
			form, root, raw, canvas, read: () => documentState, isValid: () => valid,
			title: () => form.elements.post_title.value,
			setTitle: value => { form.elements.post_title.value = value; form.elements.post_title.dispatchEvent( new Event( 'input', { bubbles: true } ) ); },
			apply: applyWorkspaceChange, select: selectWorkspaceBlock, pick: pickWorkspaceAsset,
			announce, sanitizeInline: safeInline
		} );
		if ( ! valid ) { workspace.showRawEditor(); }
	}
	}
	const postForm = document.getElementById( 'post-editor' );
	if ( postForm ) {
		initBuilder( document.getElementById( 'wp-page-builder-root' ), document.getElementById( 'post_content' ), postForm, { status: document.getElementById( 'builder-status' ), draftKey: postForm.dataset.draftKey, postEditor: true } );
	}
	const designForm = document.getElementById( 'design-form' );
	if ( designForm ) {
		document.querySelectorAll( '[data-section-editor]' ).forEach( function( section ) {
			initBuilder( section.querySelector( '.section-builder-root' ), document.getElementById( section.dataset.target ), designForm, { status: section.querySelector( '.builder-status' ), draftKey: null, postEditor: false, heading: 'Shared section content' } );
		} );
	}
} )();
