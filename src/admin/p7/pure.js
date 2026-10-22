/**
 * Logic of the "Works with" screens that has no React in it (tested on its own).
 */

/**
 * The address of a guide page.
 *
 * @param {Object} data Boot data (`guide: { index, links }`).
 * @param {string} id   Screen or section id.
 * @return {string} Address: the page for that id, else the guide's index, else ''.
 */
export function guideUrl( data, id ) {
	const guide = ( data && data.guide ) || {};
	const links = guide.links || {};
	return String( links[ id ] || guide.index || '' );
}

/**
 * The order groups are shown in.
 */
export const GROUPS = [
	'local',
	'shop',
	'marketplace',
	'community',
	'courses',
	'members',
	'listings',
	'media',
	'languages',
	'fields',
	'builders',
	'network',
	'site',
];

/**
 * Integrations by group: those found on this site, and the rest.
 *
 * @param {Array<Object>} items From the REST route.
 * @return {{found: Array<{group: string, items: Array}>, rest: Array<{group: string, items: Array}>, counts: {found: number, on: number, total: number}}} Sorted.
 */
export function arrange( items ) {
	const list = Array.isArray( items ) ? items : [];
	const by = ( wanted ) => {
		const out = [];
		const groups = [
			...GROUPS,
			...list
				.map( ( i ) => i.group )
				.filter( ( g ) => ! GROUPS.includes( g ) ),
		];
		[ ...new Set( groups ) ].forEach( ( group ) => {
			const inGroup = list.filter(
				( i ) => i.group === group && !! i.detected === wanted
			);
			if ( inGroup.length ) {
				out.push( { group, items: inGroup } );
			}
		} );
		return out;
	};
	return {
		found: by( true ),
		rest: by( false ),
		counts: {
			found: list.filter( ( i ) => i.detected ).length,
			on: list.filter( ( i ) => i.on ).length,
			total: list.length,
		},
	};
}

/**
 * Is any integration of a group found on this site (and not switched off)?
 *
 * @param {Array<Object>} items Integrations.
 * @param {string}        group Group.
 * @return {boolean} Whether one is.
 */
export function hasGroup( items, group ) {
	return ( Array.isArray( items ) ? items : [] ).some(
		( i ) => i.group === group && i.detected && ! i.off && ! i.core
	);
}

const DAY = /^(mo|tu|we|th|fr|sa|su)$/;

/**
 * Which lines of the opening hours cannot be read (the same rules the server applies:
 * `Mo-Fr 09:00-17:30`, `Sa 10:00-14:00`, `Su closed`, `24/7`).
 *
 * @param {string} text The lines.
 * @return {Array<string>} The lines that would be left out.
 */
export function badHours( text ) {
	const bad = [];
	String( text || '' )
		.split( /\r\n|\r|\n/ )
		.forEach( ( raw ) => {
			const line = raw.trim().toLowerCase();
			if ( ! line || line === '24/7' ) {
				return;
			}
			const m = line.match( /^([a-z,\- ]+?)\s+(closed|[0-9:.\- ]+)$/ );
			if ( ! m ) {
				bad.push( raw.trim() );
				return;
			}
			const days = m[ 1 ]
				.split( ',' )
				.map( ( d ) => d.trim() )
				.filter( Boolean );
			const daysOk =
				days.length > 0 &&
				days.every( ( d ) => {
					const ends = d.split( '-' ).map( ( x ) => x.trim() );
					return (
						ends.length <= 2 && ends.every( ( e ) => DAY.test( e ) )
					);
				} );
			const timesOk =
				m[ 2 ] === 'closed' ||
				m[ 2 ]
					.trim()
					.split( /\s+/ )
					.every( ( t ) =>
						/^([01]?\d|2[0-3])[:.][0-5]\d-([01]?\d|2[0-4])[:.][0-5]\d$/.test(
							t
						)
					);
			if ( ! daysOk || ! timesOk ) {
				bad.push( raw.trim() );
			}
		} );
	return bad;
}

/**
 * Is a latitude / longitude pair usable? Both empty is fine (no map position given).
 *
 * @param {string} lat Latitude.
 * @param {string} lng Longitude.
 * @return {boolean} Whether it is.
 */
export function goodPosition( lat, lng ) {
	const a = String( lat || '' ).trim();
	const b = String( lng || '' ).trim();
	if ( ! a && ! b ) {
		return true;
	}
	const num = /^-?\d{1,3}(\.\d+)?$/;
	return (
		num.test( a ) &&
		num.test( b ) &&
		Math.abs( Number( a ) ) <= 90 &&
		Math.abs( Number( b ) ) <= 180
	);
}

/**
 * Only the settings that differ from what is saved.
 *
 * @param {Object} saved What the site has.
 * @param {Object} draft What the form holds.
 * @return {Object} The changes.
 */
export function changed( saved, draft ) {
	const out = {};
	Object.keys( draft || {} ).forEach( ( key ) => {
		if (
			JSON.stringify( ( saved || {} )[ key ] ) !==
			JSON.stringify( draft[ key ] )
		) {
			out[ key ] = draft[ key ];
		}
	} );
	return out;
}

/**
 * Share of products ready for Merchant listings, as a whole percentage.
 *
 * @param {number} ready   Ready.
 * @param {number} checked Checked.
 * @return {number} 0–100.
 */
export function readyShare( ready, checked ) {
	if ( ! checked || checked < 1 ) {
		return 0;
	}
	const share = Math.floor( ( 100 * ready ) / checked );
	return Math.max( 0, Math.min( 100, share ) );
}

/**
 * Sellers with the "same description as another store" problem added: it can only be seen
 * across the whole list, so it is worked out here once every page of the list has arrived.
 *
 * @param {Array<Object>} items Sellers (`desc_hash`, `problems`).
 * @return {Array<Object>} The same sellers, each with its full list of problems.
 */
export function withDuplicates( items ) {
	const list = Array.isArray( items ) ? items : [];
	const seen = {};
	list.forEach( ( s ) => {
		if ( s.desc_hash ) {
			seen[ s.desc_hash ] = ( seen[ s.desc_hash ] || 0 ) + 1;
		}
	} );
	return list.map( ( s ) => {
		const problems = ( s.problems || [] ).filter(
			( p ) => p !== 'duplicate'
		);
		if ( s.desc_hash && seen[ s.desc_hash ] > 1 ) {
			problems.push( 'duplicate' );
		}
		return { ...s, problems };
	} );
}

/**
 * The numbers above the sellers' list.
 *
 * @param {Array<Object>} items Sellers (after `withDuplicates`).
 * @return {{stores: number, inSearch: number, toFix: number}} Counts.
 */
export function sellerCounts( items ) {
	const list = Array.isArray( items ) ? items : [];
	return {
		stores: list.length,
		inSearch: list.filter( ( s ) => s.in_search ).length,
		toFix: list.filter( ( s ) => ( s.problems || [] ).length > 0 ).length,
	};
}

/**
 * One CSV cell: quoted when needed, and never read as a formula by a spreadsheet.
 *
 * @param {*} value Value.
 * @return {string} Cell.
 */
function cell( value ) {
	let text = String( value === null || value === undefined ? '' : value );
	if ( /^[=+\-@\t\r]/.test( text ) ) {
		text = "'" + text;
	}
	return /[",\n\r]/.test( text )
		? '"' + text.replace( /"/g, '""' ) + '"'
		: text;
}

/**
 * The sellers' report as CSV text (one row per store), for the owner to send to sellers.
 *
 * @param {Array<Object>} items  Sellers.
 * @param {Object}        labels Problem code => words.
 * @param {Array<string>} head   The column names, in the reader's language (six of them).
 * @return {string} CSV.
 */
export function sellersCsv( items, labels, head ) {
	const rows = [ head.map( cell ).join( ',' ) ];
	( Array.isArray( items ) ? items : [] ).forEach( ( s ) => {
		rows.push(
			[
				s.name,
				s.url,
				s.products,
				s.in_search ? 'yes' : 'no',
				( s.problems || [] )
					.map( ( p ) => ( labels || {} )[ p ] || p )
					.join( '; ' ),
				s.title,
			]
				.map( cell )
				.join( ',' )
		);
	} );
	return rows.join( '\r\n' ) + '\r\n';
}
