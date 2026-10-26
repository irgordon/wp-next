/** Pure document operations shared by the form and visual editors. */
const EMPTY_TEXT = '';
const FIRST_POSITION = 0;
const LAST_POSITION = -1;
const NEXT_POSITION = 1;
const MAX_BLOCKS = 500;
const MAX_DEPTH = 8;
const TYPE_IMAGE = 'image';
const TYPE_PARAGRAPH = 'paragraph';
const MODEL_ERROR = 'BlockDocumentError';
const MESSAGE = Object.freeze( {
	type: 'Choose a supported block.', missing: 'That block is no longer available.', field: 'That block setting cannot be changed.',
	collection: 'Choose an available column or page.', source: 'The dragged block is no longer available.',
	destination: 'Drop the block onto the page or a column.', position: 'Choose a valid block position.',
	cycle: 'A layout cannot be moved inside itself.', duplicate: 'Choose a block to duplicate.',
	count: 'A page can contain at most 500 blocks.', depth: 'At most eight nested layouts are supported.'
} );
const FIELD_NAMES = new Set( [ 'html', 'text', 'alt', 'caption', 'label', 'align', 'level', 'ordered' ] );
const DEFINITIONS = {
	paragraph: { label: 'Paragraph', icon: '¶', defaults: { html: EMPTY_TEXT } },
	heading: { label: 'Heading', icon: 'H', defaults: { level: 2, html: EMPTY_TEXT } },
	list: { label: 'List', icon: '☷', defaults: { html: '<li>List item</li>' } },
	quote: { label: 'Quote', icon: '“', defaults: { html: EMPTY_TEXT } },
	code: { label: 'Code', icon: '</>', defaults: { text: EMPTY_TEXT } },
	image: { label: 'Image', icon: '▧', defaults: { media_id: 0, alt: EMPTY_TEXT, caption: EMPTY_TEXT } },
	file: { label: 'Document', icon: '▤', defaults: { media_id: 0, label: EMPTY_TEXT } },
	columns: { label: 'Columns', icon: '▥', defaults: { columns: [ [ { type: 'paragraph', html: EMPTY_TEXT } ], [ { type: 'paragraph', html: EMPTY_TEXT } ] ] } },
	html: { label: 'HTML', icon: '〈〉', defaults: { html: EMPTY_TEXT } },
	divider: { label: 'Divider', icon: '―', defaults: { html: EMPTY_TEXT } }
};
export const BLOCK_DEFINITIONS = Object.entries( DEFINITIONS ).map( ( [ type, definition ] ) => ( { type, label: definition.label, icon: definition.icon } ) );

export function insertBlock( document, block, prefix ) {
	const change = prepareInsertion( document, block, prefix );
	validateStructure( change.document );
	return change;
}
export function moveBlock( document, source, destination, position ) {
	const change = prepareMove( document, source, destination, position );
	validateMoveLocation( change );
	validateMoveHierarchy( change );
	validateMovePosition( change );
	commitMove( change );
	validateStructure( change.document );
	return change;
}
export function duplicateBlock( document, path ) {
	const change = prepareDuplicate( document, path );
	validateStructure( change.document );
	return change;
}
export function createBlock( type ) {
	const definition = definitionFor( type );
	if ( ! definition ) { throw namedError( MODEL_ERROR, MESSAGE.type ); }
	return { type, ...cloneValue( definition.defaults ) };
}
export function removeBlock( document, path ) {
	const copy = cloneValue( document );
	const record = locateBlock( copy, path );
	if ( ! record.block ) { throw namedError( MODEL_ERROR, MESSAGE.missing ); }
	removeAt( record.collection, record.index );
	return { document: copy, selection: null };
}
export function updateBlockField( document, path, field, value ) {
	const copy = cloneValue( document );
	const record = locateBlock( copy, path );
	if ( ! record.block ) { throw namedError( MODEL_ERROR, MESSAGE.missing ); }
	if ( ! fieldSupported( field ) ) { throw namedError( MODEL_ERROR, MESSAGE.field ); }
	record.block[ field ] = value;
	return { document: copy, selection: path };
}
export function selectedCollection( selection ) {
	return selection ? parentPath( selection ) : [];
}
function prepareInsertion( document, block, prefix ) {
	const copy = cloneValue( document );
	const collection = collectionAt( copy, prefix );
	if ( ! collection ) { throw namedError( MODEL_ERROR, MESSAGE.collection ); }
	const index = appendValue( collection, cloneValue( block ) );
	return { document: copy, selection: [ ...prefix, index ] };
}
function prepareMove( document, source, destination, position ) {
	const copy = cloneValue( document );
	return { document: copy, source: locateBlock( copy, source ), destination: collectionAt( copy, destination ), sourcePath: source, destinationPath: destination, position };
}
function validateMoveLocation( change ) {
	if ( ! change.source.block ) { throw namedError( MODEL_ERROR, MESSAGE.source ); }
	if ( ! change.destination ) { throw namedError( MODEL_ERROR, MESSAGE.destination ); }
}
function validateMoveHierarchy( change ) {
	if ( pathContains( change.sourcePath, change.destinationPath ) ) { throw namedError( MODEL_ERROR, MESSAGE.cycle ); }
}
function validateMovePosition( change ) {
	if ( ! isInsertionPosition( change.position, change.destination ) ) { throw namedError( MODEL_ERROR, MESSAGE.position ); }
}
function commitMove( change ) {
	const block = change.source.block;
	const offset = arrayMoveOffset( change.source, change.destination, change.position );
	removeAt( change.source.collection, change.source.index );
	insertAt( change.destination, change.position - offset, block );
	change.selection = findBlockPath( change.document.blocks, block );
}
function prepareDuplicate( document, path ) {
	const copy = cloneValue( document );
	const record = locateBlock( copy, path );
	if ( ! record.block ) { throw namedError( MODEL_ERROR, MESSAGE.duplicate ); }
	insertAt( record.collection, record.index + NEXT_POSITION, cloneValue( record.block ) );
	return { document: copy, selection: [ ...parentPath( path ), record.index + NEXT_POSITION ] };
}
function validateStructure( document ) {
	const metrics = treeMetrics( document.blocks );
	if ( metrics.count > MAX_BLOCKS ) { throw namedError( MODEL_ERROR, MESSAGE.count ); }
	if ( metrics.depth > MAX_DEPTH ) { throw namedError( MODEL_ERROR, MESSAGE.depth ); }
}
export function previewDocument( document ) {
	const copy = cloneValue( document );
	for ( const record of flattenBlocks( copy.blocks ) ) {
		if ( emptyImageReference( record.block ) ) {
			record.collection[ record.index ] = { type: TYPE_PARAGRAPH, html: EMPTY_TEXT };
		}
	}
	return copy;
}
export function flattenBlocks( blocks, prefix = [] ) {
	const output = [];
	blocks.forEach( ( block, index ) => {
		const path = [ ...prefix, index ];
		output.push( { block, path, collection: blocks, index } );
		( block.columns || [] ).forEach( ( children, column ) => output.push( ...flattenBlocks( children, [ ...path, column ] ) ) );
	} );
	return output;
}
export function locateBlock( document, path ) {
	if ( ! isBlockPath( path ) ) { return { collection: null, index: -1, block: null }; }
	const collection = collectionAt( document, parentPath( path ) );
	const index = path.at( LAST_POSITION );
	return { collection, index, block: collection && collection[ index ] };
}
function collectionAt( document, path ) {
	if ( ! isCollectionPath( path ) ) { return null; }
	let collection = document.blocks;
	for ( let index = FIRST_POSITION; index < path.length; index += 2 ) {
		collection = collection?.[ path[ index ] ]?.columns?.[ path[ index + NEXT_POSITION ] ];
	}
	return collection;
}
function treeMetrics( blocks, depth = FIRST_POSITION ) {
	let count = blocks.length;
	let deepest = depth;
	blocks.forEach( block => ( block.columns || [] ).forEach( children => {
		const metrics = treeMetrics( children, depth + NEXT_POSITION );
		count += metrics.count;
		deepest = Math.max( deepest, metrics.depth );
	} ) );
	return { count, depth: deepest };
}
function findBlockPath( blocks, target ) {
	return flattenBlocks( blocks ).find( record => record.block === target )?.path || null;
}
function arrayMoveOffset( source, destination, position ) {
	return Number( source.collection === destination && source.index < position );
}
function pathContains( parent, child ) {
	return child.length >= parent.length && parent.every( ( part, index ) => child[ index ] === part );
}
function parentPath( path ) {
	return path.slice( FIRST_POSITION, LAST_POSITION );
}
function appendValue( collection, value ) {
	return collection.push( value ) - NEXT_POSITION;
}
function removeAt( collection, index ) {
	return collection.splice( index, NEXT_POSITION )[ FIRST_POSITION ];
}
function insertAt( collection, index, value ) {
	collection.splice( index, FIRST_POSITION, value );
}
function cloneValue( value ) {
	return structuredClone( value );
}
function isInsertionPosition( index, collection ) {
	return Number.isInteger( index ) && index >= 0 && index <= collection.length;
}
function isBlockPath( path ) {
	return Array.isArray( path ) && path.length % 2 === 1 && path.every( isNonnegativeInteger );
}
function isCollectionPath( path ) {
	return Array.isArray( path ) && path.length % 2 === 0 && path.every( isNonnegativeInteger );
}
function isNonnegativeInteger( value ) {
	return Number.isInteger( value ) && value >= 0;
}
function definitionFor( type ) {
	return Object.hasOwn( DEFINITIONS, type ) ? DEFINITIONS[ type ] : null;
}
function fieldSupported( field ) {
	return FIELD_NAMES.has( field );
}
function emptyImageReference( block ) {
	return block.type === TYPE_IMAGE && ! Number( block.media_id );
}
function namedError( name, message ) {
	const error = new Error( message ); error.name = name; return error;
}
