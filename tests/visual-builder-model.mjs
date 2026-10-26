/** Behavioral boundaries for document operations, without a DOM or server. */
import assert from 'node:assert/strict';
import { createBlock, insertBlock, moveBlock, duplicateBlock, removeBlock, updateBlockField, previewDocument } from '../wp-admin/js/visual-builder-model.js';

let count = 0;
function check( label, assertion ) {
	assertion();
	++count;
	console.log( 'PASS: ' + label );
}
const original = { version: 1, blocks: [
	{ type: 'paragraph', html: '<strong>Keep formatting</strong>' },
	{ type: 'columns', columns: [ [ { type: 'paragraph', html: 'Left' } ], [ { type: 'image', media_id: 7, alt: 'Image', caption: '' } ] ] },
	{ type: 'heading', level: 2, html: 'Last' }
] };
const snapshot = JSON.stringify( original );
check( 'Cross-column drag preserves rich content, media references and the original document', () => {
	const moved = moveBlock( original, [ 0 ], [ 1, 1 ], 1 );
	assert.equal( moved.document.blocks[ 0 ].columns[ 1 ][ 1 ].html, '<strong>Keep formatting</strong>' );
	assert.equal( moved.document.blocks[ 0 ].columns[ 1 ][ 0 ].media_id, 7 );
	assert.deepEqual( moved.selection, [ 0, 1, 1 ] );
	assert.equal( JSON.stringify( original ), snapshot );
} );
check( 'Forward sibling moves account for removal of the source slot', () => {
	const moved = moveBlock( original, [ 0 ], [], 3 );
	assert.equal( moved.document.blocks.at( -1 ).html, '<strong>Keep formatting</strong>' );
} );
check( 'Backward sibling moves preserve the remaining order', () => {
	const moved = moveBlock( original, [ 2 ], [], 0 );
	assert.equal( moved.document.blocks[ 0 ].html, 'Last' );
	assert.equal( moved.document.blocks[ 1 ].html, '<strong>Keep formatting</strong>' );
} );
check( 'A layout cannot be dropped into its own column', () => assert.throws( () => moveBlock( original, [ 1 ], [ 1, 0 ], 0 ), /inside itself/ ) );
check( 'Missing drag sources never delete an unrelated block', () => assert.throws( () => moveBlock( original, [ 99 ], [], 0 ), /no longer available/ ) );
check( 'Negative source positions are rejected', () => assert.throws( () => removeBlock( original, [ -1 ] ), /no longer available/ ) );
check( 'Out-of-range insertion positions are rejected', () => assert.throws( () => moveBlock( original, [ 0 ], [], 99 ), /valid block position/ ) );
check( 'Malformed collection paths are rejected', () => assert.throws( () => moveBlock( original, [ 0 ], [ 1 ], 0 ), /page or a column/ ) );
check( 'Unknown and prototype property names cannot create blocks', () => {
	assert.throws( () => createBlock( '__proto__' ), /supported block/ );
	assert.throws( () => createBlock( 'toString' ), /supported block/ );
} );
check( 'Duplicating a layout copies its children independently and retains media IDs', () => {
	const copied = duplicateBlock( original, [ 1 ] );
	copied.document.blocks[ 2 ].columns[ 0 ][ 0 ].html = 'Changed copy';
	assert.equal( copied.document.blocks[ 1 ].columns[ 0 ][ 0 ].html, 'Left' );
	assert.equal( copied.document.blocks[ 2 ].columns[ 1 ][ 0 ].media_id, 7 );
} );
check( 'Previewing an unassigned image does not alter the saved model', () => {
	const document = { version: 1, blocks: [ createBlock( 'image' ) ] };
	assert.equal( previewDocument( document ).blocks[ 0 ].type, 'paragraph' );
	assert.equal( document.blocks[ 0 ].type, 'image' );
} );
check( 'The client refuses a 501st block without mutating the original', () => {
	const document = { version: 1, blocks: Array.from( { length: 500 }, () => createBlock( 'paragraph' ) ) };
	assert.throws( () => insertBlock( document, createBlock( 'paragraph' ), [] ), /500 blocks/ );
	assert.equal( document.blocks.length, 500 );
} );
check( 'Over-deep drops are rejected without altering the source document', () => {
	const document = { version: 1, blocks: [] };
	let column = document.blocks;
	for ( let depth = 0; depth < 9; depth++ ) {
		const layout = { type: 'columns', columns: [ [], [] ] };
		column.push( layout ); column = layout.columns[ 0 ];
	}
	assert.throws( () => insertBlock( document, createBlock( 'paragraph' ), [] ), /eight nested/ );
	assert.equal( document.blocks.length, 1 );
} );
check( 'Field updates retain unrelated blocks and refuse arbitrary object properties', () => {
	const changed = updateBlockField( original, [ 0 ], 'html', 'New text' );
	assert.equal( changed.document.blocks[ 2 ].html, 'Last' );
	assert.equal( original.blocks[ 0 ].html, '<strong>Keep formatting</strong>' );
	assert.throws( () => updateBlockField( original, [ 0 ], '__proto__', {} ), /cannot be changed/ );
} );
console.log( count + ' visual document checks passed.' );
