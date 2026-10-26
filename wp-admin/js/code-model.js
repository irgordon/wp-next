/** Text-only tokenization and formatting. Code is never evaluated. */
const PATTERNS = {
	json: /(?<string>"(?:\\.|[^"\\\n])*")|(?<number>-?\b\d+(?:\.\d+)?(?:e[+-]?\d+)?\b)|(?<keyword>\b(?:true|false|null)\b)|(?<punctuation>[{}\[\],:])/gi,
	html: /(?<comment><!--[\s\S]*?-->)|(?<tag><\/?[\w:-]+)|(?<property>\b[\w:-]+(?=\s*=))|(?<string>"[^"]*"|'[^']*')|(?<punctuation>\/?>|=)/g,
	css: /(?<comment>\/\*[\s\S]*?\*\/)|(?<string>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(?<number>#[\da-fA-F]{3,8}\b|\b\d+(?:\.\d+)?(?:px|rem|em|vh|vw|%|ms|s)?\b)|(?<property>--?[\w-]+(?=\s*:)|\b[a-z-]+(?=\s*:))|(?<punctuation>[{}:;,()])/g,
	javascript: /(?<comment>\/\/[^\n]*|\/\*[\s\S]*?\*\/)|(?<string>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\x60(?:\\.|[^\x60\\])*\x60)|(?<keyword>\b(?:const|let|var|function|return|if|else|await|async|new|class|try|catch|export|import|from|throw|true|false|null|undefined)\b)|(?<number>\b\d+(?:\.\d+)?\b)|(?<punctuation>[{}[\]();,:])/g
};
export function tokenizeCode( text, language ) {
	const pattern = PATTERNS[ language ];
	return pattern ? scanCodeTokens( text, new RegExp( pattern ) ) : [ { type: 'plain', text } ];
}
export function codeTokenLines( tokens ) {
	const lines = [ [] ];
	tokens.forEach( token => token.text.split( '\n' ).forEach( ( text, index ) => {
		if ( index ) { lines.push( [] ); }
		lines.at( -1 ).push( { type: token.type, text } );
	} ) );
	return lines;
}
export function codePosition( text, offset ) {
	const prefix = text.slice( 0, offset || 0 );
	return { line: prefix.split( '\n' ).length, column: prefix.length - prefix.lastIndexOf( '\n' ) };
}
export function formatJsonCode( text ) {
	try { return { ok: true, value: JSON.stringify( JSON.parse( text ), null, 2 ) }; }
	catch ( error ) { return { ok: false, value: text, message: 'JSON is not valid yet. Correct it before formatting.' }; }
}
export function codeMetrics( text ) {
	return { lines: text.split( '\n' ).length, characters: text.length };
}
function scanCodeTokens( text, pattern ) {
	const tokens = [];
	let offset = 0, match;
	while ( ( match = pattern.exec( text ) ) ) {
		tokens.push( { type: 'plain', text: text.slice( offset, match.index ) } );
		tokens.push( { type: tokenClass( match.groups ), text: match[ 0 ] } );
		offset = pattern.lastIndex;
	}
	tokens.push( { type: 'plain', text: text.slice( offset ) } );
	return tokens;
}
function tokenClass( groups ) {
	return Object.keys( groups ).find( name => groups[ name ] !== undefined ) || 'plain';
}
