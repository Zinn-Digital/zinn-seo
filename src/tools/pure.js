/**
 * Logic of the credit and checks screens that has no React in it (tested on its own).
 */

/** Millionths of a US dollar in one dollar. */
export const MICROS = 1000000;

/**
 * The reader's language tag for numbers and dates (`ar`, `de-DE`).
 *
 * @return {string|undefined} Tag, or undefined for the browser's own.
 */
export function readerLocale() {
	const lang =
		typeof document !== 'undefined' &&
		document.documentElement &&
		document.documentElement.lang;
	return lang ? String( lang ).replace( '_', '-' ) : undefined;
}

/**
 * An amount of credit as money. Whole numbers in, a string out: nothing is a float until it is
 * printed. Amounts under ten cents keep four decimals, so a price of $0.0185 is not "$0.02".
 *
 * @param {number|null} micros Millionths of a US dollar; null or undefined is "not known".
 * @param {string}      locale Language tag (default: the reader's).
 * @return {string} Money, or an em dash when the amount is not known.
 */
export function money( micros, locale = readerLocale() ) {
	if ( micros === null || micros === undefined || Number.isNaN( micros ) ) {
		return '—';
	}
	const small = Math.abs( micros ) > 0 && Math.abs( micros ) < MICROS / 10;
	const digits = small ? 4 : 2;
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency: 'USD',
			minimumFractionDigits: digits,
			maximumFractionDigits: digits,
		} ).format( micros / MICROS );
	} catch {
		return `$${ ( micros / MICROS ).toFixed( digits ) }`;
	}
}

/**
 * A pack's price (cents) as money.
 *
 * @param {number} minor    Cents.
 * @param {string} currency ISO code.
 * @param {string} locale   Language tag.
 * @return {string} Money.
 */
export function price( minor, currency = 'USD', locale = readerLocale() ) {
	try {
		return new Intl.NumberFormat( locale, {
			style: 'currency',
			currency,
		} ).format( ( minor || 0 ) / 100 );
	} catch {
		return `${ ( ( minor || 0 ) / 100 ).toFixed( 2 ) } ${ currency }`;
	}
}

/**
 * A whole number in the reader's notation; an em dash when nobody measured it (never 0).
 *
 * @param {number|null} n      Number.
 * @param {string}      locale Language tag.
 * @return {string} Text.
 */
export function count( n, locale = readerLocale() ) {
	if ( n === null || n === undefined || Number.isNaN( n ) ) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( locale ).format( n );
	} catch {
		return String( n );
	}
}

/**
 * A date (and optionally the time) in the reader's notation.
 *
 * @param {string|number} when     ISO text, a `YYYY-MM-DD` day, or seconds since 1970.
 * @param {boolean}       withTime Show the time too.
 * @param {string}        locale   Language tag.
 * @return {string} Text ('' when there is no date).
 */
export function day( when, withTime = false, locale = readerLocale() ) {
	if ( ! when ) {
		return '';
	}
	const date =
		typeof when === 'number' ? new Date( when * 1000 ) : new Date( when );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	const options = withTime
		? { dateStyle: 'medium', timeStyle: 'short' }
		: { dateStyle: 'medium' };
	// A bare day is a calendar day, not a moment: shown as written, whatever the time zone.
	if ( typeof when === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( when ) ) {
		options.timeZone = 'UTC';
	}
	try {
		return new Intl.DateTimeFormat( locale, options ).format( date );
	} catch {
		return date.toISOString().slice( 0, withTime ? 16 : 10 );
	}
}

/**
 * What a run costs: units × the unit's price. Null when the price is unknown — an unknown
 * price is never shown as free.
 *
 * @param {number|null} unitPrice Micros per unit.
 * @param {number}      units     How many.
 * @return {number|null} Micros.
 */
export function runCost( unitPrice, units ) {
	if ( unitPrice === null || unitPrice === undefined || units < 0 ) {
		return null;
	}
	return unitPrice * units;
}

/**
 * Whether the balance covers a cost. Unknown cost or balance: not known (null), so the screen
 * neither blocks nor promises.
 *
 * @param {number|null} balance Micros.
 * @param {number|null} cost    Micros.
 * @return {boolean|null} Covered.
 */
export function covers( balance, cost ) {
	if (
		balance === null ||
		balance === undefined ||
		cost === null ||
		cost === undefined
	) {
		return null;
	}
	return balance >= cost;
}

/**
 * What a failed call says about itself (the plugin's REST answers carry `data.zinn`).
 *
 * @param {*} error What apiFetch rejected with.
 * @return {{class: string, code: string, message: string, vendor: string, statusUrl: string, fixUrl: string}} The refusal.
 */
export function refusal( error ) {
	const zinn = ( error && error.data && error.data.zinn ) || {};
	return {
		class: String( zinn.class || '' ),
		code: String( zinn.code || '' ),
		message:
			( error && typeof error.message === 'string' && error.message ) ||
			'',
		vendor: String( zinn.vendor || '' ),
		statusUrl: /^https:\/\//.test( zinn.status_url || '' )
			? zinn.status_url
			: '',
		fixUrl: /^https:\/\//.test( zinn.fix_url || '' ) ? zinn.fix_url : '',
		fixKind: [ 'keys', 'billing' ].includes( zinn.fix_kind )
			? zinn.fix_kind
			: '',
	};
}

/**
 * Lines of a text box as a list: trimmed, no empty line, no duplicate.
 *
 * @param {string} text Text.
 * @return {Array<string>} Lines.
 */
export function lines( text ) {
	const seen = new Set();
	return String( text || '' )
		.split( /\r?\n/ )
		.map( ( l ) => l.trim().replace( /\s+/g, ' ' ) )
		.filter( ( l ) => {
			const key = l.toLocaleLowerCase();
			if ( ! l || seen.has( key ) ) {
				return false;
			}
			seen.add( key );
			return true;
		} );
}

/**
 * How a tracked phrase moved: up (better), down (worse), same, new (no reading before), out
 * (not found this time), wait (paid for, the answer is on its way).
 *
 * @param {Object} row A rank row (`position`, `previous`, `delta`, `pending`).
 * @return {string} up|down|same|new|out|wait.
 */
export function movement( row ) {
	if ( row.pending ) {
		return 'wait';
	}
	if ( row.position === null || row.position === undefined ) {
		return 'out';
	}
	if ( row.previous === null || row.previous === undefined ) {
		return 'new';
	}
	if ( row.delta > 0 ) {
		return 'up';
	}
	return row.delta < 0 ? 'down' : 'same';
}

/**
 * The newest finished run of a list, and whether one is still running.
 *
 * @param {Array<Object>} audits Runs, newest first.
 * @return {{latest: Object|null, running: boolean}} State.
 */
export function latestRun( audits ) {
	const list = Array.isArray( audits ) ? audits : [];
	return {
		latest: list.find( ( a ) => a.status !== 'running' ) || null,
		running: list.some( ( a ) => a.status === 'running' ),
	};
}

/**
 * The share of AI answers that mention the site, per engine, from one run.
 *
 * @param {Object} summary An AI-answers run's summary (`answers`).
 * @return {Array<{engine: string, asked: number, mentioned: number, linked: number}>} Per engine.
 */
export function shareOfVoice( summary ) {
	const by = new Map();
	( ( summary && summary.answers ) || [] ).forEach( ( a ) => {
		const row = by.get( a.engine ) || {
			engine: a.engine,
			asked: 0,
			mentioned: 0,
			linked: 0,
		};
		row.asked += 1;
		row.mentioned += a.mentioned ? 1 : 0;
		row.linked += a.linked ? 1 : 0;
		by.set( a.engine, row );
	} );
	return [ ...by.values() ];
}

/**
 * The address of a guide page (the same map every screen is sent).
 *
 * @param {Object} data Boot data (`guide: { index, links }`).
 * @param {string} id   Screen or section id.
 * @return {string} Address.
 */
export function guideUrl( data, id ) {
	const guide = ( data && data.guide ) || {};
	const links = guide.links || {};
	return String( links[ id ] || guide.index || '' );
}

/**
 * The page in the Zinn® app where a product the plan lacks can be added.
 *
 * @param {string} buyUrl The account's "buy credits" address.
 * @param {string} kind   The product.
 * @return {string} Address ('' when there is no https address to build on).
 */
export function productUrl( buyUrl, kind ) {
	try {
		const url = new URL( buyUrl );
		if ( url.protocol !== 'https:' ) {
			return '';
		}
		return `${ url.origin }/visibility?tab=${ encodeURIComponent( kind ) }`;
	} catch {
		return '';
	}
}

/**
 * The lines of a disavow selection, to show how many domains and pages a file will hold.
 *
 * @param {Array<Object>} links   Links with `domain_from`, `url_from`.
 * @param {Set<string>}   domains Chosen domains.
 * @param {Set<string>}   urls    Chosen pages.
 * @return {{domains: Array<string>, urls: Array<string>}} What the file will hold.
 */
export function disavowLines( links, domains, urls ) {
	const out = { domains: [ ...domains ].sort(), urls: [] };
	( links || [] ).forEach( ( l ) => {
		if ( urls.has( l.url_from ) && ! domains.has( l.domain_from ) ) {
			out.urls.push( l.url_from );
		}
	} );
	out.urls = [ ...new Set( out.urls ) ].sort();
	return out;
}

/**
 * An AI engine's name (proper nouns: not translated).
 *
 * @param {string} family Engine family code.
 * @return {string} Name.
 */
export function engineName( family ) {
	const names = {
		openai: 'ChatGPT',
		chatgpt: 'ChatGPT',
		perplexity: 'Perplexity',
		gemini: 'Gemini',
		google: 'Gemini',
		anthropic: 'Claude',
		claude: 'Claude',
		copilot: 'Copilot',
		grok: 'Grok',
		google_ai_overview: 'Google AI Overview',
		google_ai_mode: 'Google AI Mode',
	};
	const key = String( family || '' ).toLowerCase();
	// A family added later still reads as a name, never as a code with underscores.
	return (
		names[ key ] ||
		key.replace( /_/g, ' ' ).replace( /^./, ( c ) => c.toUpperCase() )
	);
}
