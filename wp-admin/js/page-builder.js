/** Canonical nested block editor. Server validation remains authoritative. */
'use strict';
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
		if ( ! key ) { return; }
		try {
			const data = {};
			data[ 'term_ids[]' ] = Array.from( form.querySelectorAll( 'input[name="term_ids[]"]:checked' ) ).map( input => input.value );
			for ( const [ name, value ] of new FormData( form ) ) {
				if ( name !== '_wpnonce' && name !== 'term_ids[]' && typeof value === 'string' ) { data[ name ] = value; }
			}
			sessionStorage.setItem( key, JSON.stringify( data ) );
		} catch ( error ) { announce( 'Browser draft storage is unavailable. Save changes to keep your work.' ); }
	}
	function sync() {
		raw.value = JSON.stringify( documentState );
		cacheDraft();
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
	}
	function makeBlock( type ) {
		if ( type === 'columns' ) { return { type, columns: [ [ makeBlock( 'paragraph' ) ], [ makeBlock( 'paragraph' ) ] ] }; }
		if ( type === 'image' ) { return { type, media_id: 0, alt: '', caption: '' }; }
		if ( type === 'file' ) { return { type, media_id: 0, label: '' }; }
		if ( type === 'code' ) { return { type, text: '' }; }
		if ( type === 'heading' ) { return { type, level: 2, html: '' }; }
		return { type, html: type === 'list' ? '<li>List item</li>' : '' };
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
	function field( label, value, changed, multiline ) {
		const wrapper = node( 'div', undefined, 'form-field' );
		const id = 'block-field-' + ++serial;
		const input = node( multiline ? 'textarea' : 'input' );
		input.id = id;
		input.value = value || '';
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
			card.append( button( Number( block.media_id ) > 0 ? 'Change image' : 'Choose image', function() { imageTarget = block; activatePicker(); loadMedia().then( () => dialog.showModal() ); } ), field( 'Alternative text', block.alt, value => { block.alt = value; } ), field( 'Caption', block.caption, value => { block.caption = value; } ) );
		} else if ( block.type === 'file' ) {
			const doc = docCache.find( item => item.id === Number( block.media_id ) );
			card.append( node( 'p', Number( block.media_id ) > 0 ? ( doc ? doc.original_name : 'Document #' + block.media_id ) : 'No document selected.', 'description' ), button( Number( block.media_id ) > 0 ? 'Change document' : 'Choose document', function() { fileTarget = block; activatePicker(); loadDocuments().then( () => docDialog.showModal() ); } ), field( 'Link label', block.label || '', value => { block.label = value; } ) );
		} else if ( block.type === 'html' || block.type === 'code' ) {
			card.append( field( block.type === 'code' ? 'Code (displayed as text)' : 'HTML (sanitized on save and display)', block.type === 'code' ? block.text : block.html, value => { block[ block.type === 'code' ? 'text' : 'html' ] = value; }, true ) );
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
		try {
			const mediaUrl = new URL( root.dataset.mediaUrl, location.href ); mediaUrl.searchParams.set( 'page', page ); mediaUrl.searchParams.set( 's', mediaSearch );
			const response = await fetch( mediaUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } } );
			if ( ! response.ok ) { throw new Error( 'Media could not be loaded.' ); }
			const data = await response.json();
			mediaCache = Array.from( new Map( [ ...mediaCache, ...data.items ].map( item => [ Number( item.id ), item ] ) ).values() );
			nextMediaPage = data.next; document.getElementById( 'media-picker-more' ).classList.toggle( 'hidden', ! nextMediaPage );
			if ( page === 1 ) { mediaItems.replaceChildren(); }
			data.items.forEach( function( media ) { const card = node( 'div', undefined, 'media-card' ); const image = node( 'img' ); image.src = media.url; image.alt = media.alt_text || ''; card.append( image, button( media.original_name, function() { if ( ! imageTarget ) { return; } if ( imageTarget === 'featured' ) { const select = document.getElementById( 'featured_media_id' ); let option = Array.from( select.options ).find( item => Number( item.value ) === Number( media.id ) ); if ( ! option ) { option = new Option( media.original_name, media.id ); select.add( option ); } select.value = String( media.id ); cacheDraft(); dialog.close(); document.getElementById( 'choose-featured-image' ).focus(); return; } imageTarget.media_id = media.id; imageTarget.alt = media.alt_text; imageTarget.caption = media.caption; sync(); dialog.close(); render(); focusBlock( imageTarget ); } ) ); mediaItems.append( card ); } );
			if ( data.items.length === 0 && page === 1 ) { mediaItems.append( node( 'p', 'Upload an image to the media library first.' ) ); }
		} catch ( error ) { mediaItems.replaceChildren( node( 'p', error.message, 'notice notice-error' ) ); }
	}
	async function loadDocuments( page = 1 ) {
		try {
			const docUrl = new URL( root.dataset.documentsUrl, location.href ); docUrl.searchParams.set( 'page', page ); docUrl.searchParams.set( 's', docSearch );
			const response = await fetch( docUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } } );
			if ( ! response.ok ) { throw new Error( 'Documents could not be loaded.' ); }
			const data = await response.json();
			docCache = Array.from( new Map( [ ...docCache, ...data.items ].map( item => [ Number( item.id ), item ] ) ).values() );
			nextDocPage = data.next; document.getElementById( 'document-picker-more' ).classList.toggle( 'hidden', ! nextDocPage );
			if ( page === 1 ) { docItems.replaceChildren(); }
			data.items.forEach( function( doc ) { const card = node( 'div', undefined, 'media-card' ); card.append( node( 'p', doc.original_name ), node( 'p', String( doc.format ).toUpperCase() + ' · ' + Math.ceil( doc.size / 1024 ) + ' KB · ' + doc.visibility, 'muted' ), button( 'Use this document', function() { if ( ! fileTarget ) { return; } fileTarget.media_id = doc.id; if ( ! fileTarget.label ) { fileTarget.label = doc.original_name; } sync(); docDialog.close(); render(); focusBlock( fileTarget ); } ) ); docItems.append( card ); } );
			if ( data.items.length === 0 && page === 1 ) { docItems.append( node( 'p', 'Upload a document to the media library first.' ) ); }
		} catch ( error ) { docItems.replaceChildren( node( 'p', error.message, 'notice notice-error' ) ); }
	}
	const chooseFeatured = document.getElementById( 'choose-featured-image' );
	if ( chooseFeatured ) { chooseFeatured.addEventListener( 'click', () => { imageTarget = 'featured'; activatePicker(); loadMedia().then( () => dialog.showModal() ); } ); }
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
	form.addEventListener( 'submit', function( event ) { if ( ! valid ) { event.preventDefault(); announce( 'Correct the raw block document before saving.' ); raw.closest( 'details' ).open = true; raw.focus(); } else { cacheDraft(); if ( key ) { try { sessionStorage.setItem( 'wp-pending-draft', key ); } catch ( error ) { /* Server saving remains available. */ } } } } );

	/* Visual canvas mode: the same document model, rendered by the public renderer. */
	const canvas = document.getElementById( 'wp-visual-canvas' );
	const inspector = document.getElementById( 'wp-visual-inspector' );
	const inspectorPanel = document.getElementById( 'wp-visual-inspector-panel' );
	const stateLine = document.getElementById( 'editor-state' );
	let visualMode = false;
	let selectedPath = null;
	let savedSnapshot = raw.value;
	let refreshSeq = 0;
	let refreshTimer = 0;
	function visualAfterRender() {
		if ( visualMode && selectedPath ) { showInspector(); }
	}
	function pathBlock( path ) {
		let blocks = documentState.blocks, block = null, collection = null, index = -1;
		for ( let i = 0; i < path.length; i++ ) {
			index = path[ i ]; block = blocks[ index ]; collection = blocks;
			if ( i < path.length - 1 ) { blocks = block.columns[ path[ ++i ] ]; }
		}
		return { block, collection, index };
	}
	function updateState() {
		if ( ! stateLine ) { return; }
		const statusSelect = form.elements.namedItem( 'post_status' );
		const label = ! statusSelect ? 'Draft' : 'published' === statusSelect.value ? 'Published' : 'archived' === statusSelect.value ? 'Archived' : 'Draft';
		stateLine.textContent = label + ' · ' + ( raw.value !== savedSnapshot ? 'Unsaved changes' : 'All changes saved' );
	}
	async function refreshCanvas() {
		if ( ! visualMode || ! canvas ) { return; }
		const seq = ++refreshSeq;
		const body = new URLSearchParams();
		body.set( '_wpnonce', document.getElementById( 'block-preview-nonce' ).value );
		body.set( 'content', raw.value );
		try {
			const response = await fetch( 'block-preview.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body } );
			if ( ! response.ok ) { throw new Error( 'Preview failed.' ); }
			const data = await response.json();
			if ( seq !== refreshSeq ) { return; }
			buildCanvas( data.blocks );
		} catch ( error ) {
			if ( seq === refreshSeq ) { canvas.replaceChildren( node( 'p', 'The visual preview could not be loaded. Your content is unchanged and the form editor still works.', 'notice notice-error' ) ); }
		}
	}
	function scheduleRefresh() { clearTimeout( refreshTimer ); refreshTimer = setTimeout( refreshCanvas, 350 ); }
	function canvasToolbar( collection, depth, prefix ) {
		const toolbar = node( 'div', undefined, 'builder-toolbar' );
		const selectBox = node( 'select', undefined, 'block-select' );
		selectBox.setAttribute( 'aria-label', 'Block type to add' );
		types.filter( type => depth < 6 || type !== 'columns' ).forEach( function( type ) { const option = node( 'option', type.charAt( 0 ).toUpperCase() + type.slice( 1 ) ); option.value = type; selectBox.append( option ); } );
		toolbar.append( selectBox, button( 'Add block', function() { const block = makeBlock( selectBox.value ); collection.push( block ); sync(); render(); refreshCanvas(); selectPath( [ ...prefix, collection.length - 1 ] ); announce( 'Block added.' ); } ) );
		return toolbar;
	}
	function frameFor( block, path, rendered, collection, depth ) {
		const frame = node( 'section', undefined, 'canvas-frame' );
		frame.dataset.path = path.join( '.' );
		frame.tabIndex = 0;
		frame.setAttribute( 'role', 'button' );
		frame.setAttribute( 'aria-label', 'Select ' + block.type + ' block' );
		if ( selectedPath && selectedPath.join( '.' ) === path.join( '.' ) ) { frame.classList.add( 'selected' ); }
		const index = collection.indexOf( block );
		const bar = node( 'div', undefined, 'canvas-frame-bar' );
		const up = button( '↑', function() { [ collection[ index - 1 ], collection[ index ] ] = [ block, collection[ index - 1 ] ]; selectedPath = [ ...path.slice( 0, -1 ), index - 1 ]; sync(); render(); refreshCanvas(); announce( 'Block moved up.' ); } );
		up.disabled = index === 0; up.setAttribute( 'aria-label', 'Move ' + block.type + ' block up' );
		const down = button( '↓', function() { [ collection[ index + 1 ], collection[ index ] ] = [ block, collection[ index + 1 ] ]; selectedPath = [ ...path.slice( 0, -1 ), index + 1 ]; sync(); render(); refreshCanvas(); announce( 'Block moved down.' ); } );
		down.disabled = index === collection.length - 1; down.setAttribute( 'aria-label', 'Move ' + block.type + ' block down' );
		const remove = button( 'Remove', function() { collection.splice( index, 1 ); selectedPath = null; inspector.replaceChildren(); sync(); render(); refreshCanvas(); announce( 'Block removed.' ); } );
		bar.append( node( 'strong', block.type.charAt( 0 ).toUpperCase() + block.type.slice( 1 ) ), up, down, remove );
		frame.append( bar );
		const bodyDiv = node( 'div', undefined, 'canvas-block' );
		if ( 'columns' === block.type && rendered && rendered.columns ) {
			const wrap = node( 'div', undefined, 'canvas-columns' );
			block.columns.forEach( function( children, columnIndex ) {
				const column = node( 'div', undefined, 'canvas-column' );
				children.forEach( function( child, childIndex ) { column.append( frameFor( child, [ ...path, columnIndex, childIndex ], { html: rendered.columns[ columnIndex ][ childIndex ] }, children, depth + 1 ) ); } );
				column.append( canvasToolbar( children, depth + 1, [ ...path, columnIndex ] ) );
				wrap.append( column );
			} );
			bodyDiv.append( wrap );
		} else {
			bodyDiv.innerHTML = rendered ? rendered.html : '';
		}
		frame.append( bodyDiv );
		frame.addEventListener( 'click', function( event ) { if ( event.target.closest( '.canvas-frame' ) === frame && ! event.target.closest( 'button, select, a, input' ) ) { selectPath( path ); } } );
		frame.addEventListener( 'keydown', function( event ) { if ( ( 'Enter' === event.key || ' ' === event.key ) && event.target === frame ) { event.preventDefault(); selectPath( path ); } } );
		return frame;
	}
	function buildCanvas( rendered ) {
		canvas.replaceChildren();
		documentState.blocks.forEach( function( block, index ) { canvas.append( frameFor( block, [ index ], rendered[ index ], documentState.blocks, 0 ) ); } );
		if ( 0 === documentState.blocks.length ) { canvas.append( node( 'p', 'Add your first block to start building.', 'empty' ) ); }
		canvas.append( canvasToolbar( documentState.blocks, 0, [] ) );
	}
	function showInspector() {
		if ( ! selectedPath || ! inspector ) { return; }
		const target = pathBlock( selectedPath );
		if ( ! target.block ) { return; }
		const card = root.querySelector( '[data-block-id="' + ids.get( target.block ) + '"]' );
		if ( card ) { inspector.replaceChildren( card ); }
	}
	function selectPath( path ) {
		selectedPath = path;
		canvas.querySelectorAll( '.canvas-frame' ).forEach( function( frame ) { frame.classList.toggle( 'selected', frame.dataset.path === path.join( '.' ) ); } );
		showInspector();
	}
	function setMode( visual ) {
		visualMode = visual;
		document.getElementById( 'mode-form' ).setAttribute( 'aria-pressed', String( ! visual ) );
		document.getElementById( 'mode-visual' ).setAttribute( 'aria-pressed', String( visual ) );
		document.getElementById( 'canvas-width' ).hidden = ! visual;
		root.hidden = visual;
		canvas.hidden = ! visual;
		inspectorPanel.hidden = ! visual;
		if ( visual ) { render(); refreshCanvas(); }
		else { inspector.replaceChildren(); render(); }
	}
	if ( canvas ) {
		document.getElementById( 'mode-form' ).addEventListener( 'click', () => setMode( false ) );
		document.getElementById( 'mode-visual' ).addEventListener( 'click', () => setMode( true ) );
		document.getElementById( 'width-desktop' ).addEventListener( 'click', function() { canvas.classList.remove( 'canvas-mobile' ); this.setAttribute( 'aria-pressed', 'true' ); document.getElementById( 'width-mobile' ).setAttribute( 'aria-pressed', 'false' ); } );
		document.getElementById( 'width-mobile' ).addEventListener( 'click', function() { canvas.classList.add( 'canvas-mobile' ); this.setAttribute( 'aria-pressed', 'true' ); document.getElementById( 'width-desktop' ).setAttribute( 'aria-pressed', 'false' ); } );
		form.addEventListener( 'input', function() { updateState(); if ( visualMode ) { scheduleRefresh(); } } );
		form.addEventListener( 'change', updateState );
		updateState();
	}
	parse();
	if ( ! options.postEditor ) { return; }
	try {
		if ( form.dataset.saved === '1' ) { const previous = sessionStorage.getItem( 'wp-pending-draft' ); if ( previous ) { sessionStorage.removeItem( previous ); } sessionStorage.removeItem( key ); sessionStorage.removeItem( 'wp-pending-draft' ); }
		const saved = sessionStorage.getItem( key );
		if ( saved ) {
			const data = JSON.parse( saved );
			if ( data.post_content !== raw.value || data.post_title !== form.elements.post_title.value ) { document.getElementById( 'draft-recovery' ).classList.remove( 'hidden' ); }
			document.getElementById( 'restore-draft' ).addEventListener( 'click', function() { Object.entries( data ).forEach( ( [ name, value ] ) => { if ( name === 'term_ids[]' ) { const values = Array.isArray( value ) ? value : [ value ]; form.querySelectorAll( 'input[name="term_ids[]"]' ).forEach( input => { input.checked = values.includes( input.value ); } ); return; } const control = form.elements.namedItem( name ); if ( control && 'value' in control ) { control.value = value; } } ); parse(); document.getElementById( 'draft-recovery' ).classList.add( 'hidden' ); announce( 'Browser draft restored. Review it before saving.' ); } );
		}
		document.getElementById( 'discard-draft' ).addEventListener( 'click', function() { sessionStorage.removeItem( key ); document.getElementById( 'draft-recovery' ).classList.add( 'hidden' ); } );
	} catch ( error ) { /* A storage failure cannot prevent editing or server saves. */ }
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
