/**
 * Pure helpers for the block editor's generic SEO block form (no WordPress imports, so they are
 * unit-tested on their own).
 */

/**
 * The words in a text (letters and digits of any script; CJK characters count one each), the
 * same count the server's Html::words() makes.
 *
 * @param {string} text Text.
 * @return {number} Words.
 */
export function countWords( text ) {
	const plain = String( text || '' ).trim();
	if ( ! plain ) {
		return 0;
	}
	const cjk = /[぀-ヿ㐀-䶿一-鿿가-힯]/gu;
	const cjkCount = ( plain.match( cjk ) || [] ).length;
	const rest = plain.replace( cjk, ' ' );
	const words = rest.match( /[\p{L}\p{N}][\p{L}\p{N}\p{M}'’-]*/gu ) || [];
	return cjkCount + words.length;
}

/**
 * How a text's length compares with an advised range of words.
 *
 * @param {string}        text  Text.
 * @param {Array<number>} range [min, max].
 * @return {'short'|'ideal'|'long'|''} Verdict ('' when there is no text).
 */
export function lengthVerdict( text, range ) {
	const n = countWords( text );
	if ( ! n || ! Array.isArray( range ) ) {
		return '';
	}
	if ( n < range[ 0 ] ) {
		return 'short';
	}
	return n > range[ 1 ] ? 'long' : 'ideal';
}

/**
 * A list field's value from the textarea text (one item per line, blank lines dropped).
 *
 * @param {string}  text      Text.
 * @param {boolean} keepEmpty Keep empty lines (positions matter, as in a comparison's cells).
 * @return {Array<string>} Items.
 */
export function linesToList( text, keepEmpty = false ) {
	const lines = String( text || '' )
		.split( /\r\n|\r|\n/ )
		.map( ( l ) => l.trim() );
	return keepEmpty ? lines : lines.filter( Boolean );
}

/**
 * The default value of a field (as the server describes it).
 *
 * @param {Object} field Field.
 * @return {*} Default.
 */
export function defaultOf( field ) {
	if ( field.default !== undefined && field.default !== null ) {
		return field.default;
	}
	switch ( field.type ) {
		case 'number':
		case 'image':
		case 'user':
			return 0;
		case 'bool':
			return false;
		case 'list':
		case 'repeater':
			return [];
		default:
			return '';
	}
}

/**
 * A new, empty repeater row.
 *
 * @param {Object} field The repeater field.
 * @return {Object} Row.
 */
export function newRow( field ) {
	const row = {};
	( field.fields || [] ).forEach( ( f ) => {
		row[ f.key ] = defaultOf( f );
	} );
	return row;
}

/**
 * Move an item in a list (a new list; the old is untouched).
 *
 * @param {Array}  list List.
 * @param {number} from Index.
 * @param {number} to   Index.
 * @return {Array} New list.
 */
export function move( list, from, to ) {
	const out = [ ...( list || [] ) ];
	if ( to < 0 || to >= out.length || from === to ) {
		return out;
	}
	const [ item ] = out.splice( from, 1 );
	out.splice( to, 0, item );
	return out;
}
