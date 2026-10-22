/**
 * Pure helpers for structured data in bulk (zs-764, zs-765): shared by the bulk editor's
 * structured data column and the "Fill with AI" tab, and tested on their own.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/** The kinds, in the order shown. */
export const KINDS = [ 'faq', 'howto', 'product', 'article' ];

/** Each kind's field in the bulk editor (the server's Fill::FIELDS). */
export const FIELDS = {
	faq: 'schema_faq',
	howto: 'schema_howto',
	product: 'schema_product',
	article: 'schema_article_data',
};

/**
 * A kind's name.
 *
 * @param {string} kind Kind.
 * @return {string} Name.
 */
export function kindLabel( kind ) {
	return (
		{
			faq: __( 'FAQ', 'zinn-seo' ),
			howto: __( 'How-to steps', 'zinn-seo' ),
			product: __( 'Product details', 'zinn-seo' ),
			article: __( 'Article details', 'zinn-seo' ),
		}[ kind ] || kind
	);
}

/**
 * What a kind is worth today, honestly (Google's rules, read 2026-10-09: Rich_Rules::CHECKED).
 *
 * @param {string} kind Kind.
 * @return {string} One sentence.
 */
export function googleNote( kind ) {
	switch ( kind ) {
		case 'faq':
			return __(
				'Shown on the page under its text. Google stopped showing FAQ rich results in May 2026; Bing and AI assistants such as ChatGPT still read FAQ structured data to answer questions about your pages.',
				'zinn-seo'
			);
		case 'howto':
			return __(
				'Shown on the page under its text. Google stopped showing how-to rich results in 2023; other search engines and AI assistants still read the steps.',
				'zinn-seo'
			);
		case 'product':
			return __(
				'Added to your WooCommerce products’ structured data: brand, model, material, colour and size, only where the page states them. Price, stock and reviews still come from your shop.',
				'zinn-seo'
			);
	}
	return __(
		'Added to each article’s structured data: a short summary, keywords and the main topics, so search engines and AI assistants understand what it is about.',
		'zinn-seo'
	);
}

/**
 * A stored field (JSON text) as a record.
 *
 * @param {string|Object} value Field value.
 * @return {Object} Record ({} when empty or unreadable).
 */
export function parseRecord( value ) {
	if ( value && 'object' === typeof value ) {
		return value;
	}
	if ( ! value ) {
		return {};
	}
	try {
		const parsed = JSON.parse( value );
		return parsed && 'object' === typeof parsed ? parsed : {};
	} catch {
		return {};
	}
}

/**
 * A record as the field value the bulk editor sends ('' when empty).
 *
 * @param {string} kind   Kind.
 * @param {Object} record Record.
 * @return {string} JSON text.
 */
export function recordValue( kind, record ) {
	const clean = cleanRecord( kind, record );
	return Object.keys( clean ).length ? JSON.stringify( clean ) : '';
}

/**
 * Text trimmed.
 *
 * @param {*} v Value.
 * @return {string} Text.
 */
const text = ( v ) => String( v ?? '' ).trim();

/**
 * A list of lines from a list or from text with one per line (or comma separated).
 *
 * @param {*} v Value.
 * @return {Array<string>} Lines.
 */
export function lineList( v ) {
	const list = Array.isArray( v ) ? v : String( v ?? '' ).split( /\r?\n|,/ );
	return list.map( text ).filter( Boolean );
}

/**
 * Drop what is empty, as the server's Fill::clean does (the server cleans again; this keeps
 * "nothing" from reading as a change). An FAQ without questions and a how-to without steps are
 * nothing.
 *
 * @param {string} kind   Kind.
 * @param {Object} record Record.
 * @return {Object} Record.
 */
export function cleanRecord( kind, record ) {
	const r = record || {};
	const keep = ( obj ) =>
		Object.fromEntries(
			Object.entries( obj ).filter( ( [ , v ] ) =>
				Array.isArray( v ) ? v.length > 0 : '' !== v && 0 !== v
			)
		);
	if ( 'faq' === kind ) {
		const items = ( r.items || [] )
			.map( ( q ) => ( {
				question: text( q && q.question ),
				answer: text( q && q.answer ),
			} ) )
			.filter( ( q ) => q.question && q.answer );
		return items.length ? { items } : {};
	}
	if ( 'howto' === kind ) {
		const steps = ( r.steps || [] )
			.map( ( s ) => ( {
				name: text( s && s.name ),
				text: text( s && s.text ),
			} ) )
			.filter( ( s ) => s.text );
		if ( ! steps.length ) {
			return {};
		}
		return keep( {
			name: text( r.name ),
			description: text( r.description ),
			minutes: Math.max( 0, parseInt( r.minutes, 10 ) || 0 ),
			supplies: lineList( r.supplies ),
			tools: lineList( r.tools ),
			steps,
		} );
	}
	if ( 'product' === kind ) {
		return keep( {
			brand: text( r.brand ),
			model: text( r.model ),
			material: text( r.material ),
			color: text( r.color ),
			size: text( r.size ),
		} );
	}
	return keep( {
		description: text( r.description ),
		keywords: lineList( r.keywords ),
		about: lineList( r.about ),
	} );
}

/**
 * A short summary of a record ("3 questions", "5 steps", "brand, colour").
 *
 * @param {string} kind   Kind.
 * @param {Object} record Record.
 * @return {string} Summary ('' when empty).
 */
export function summary( kind, record ) {
	const r = record || {};
	if ( 'faq' === kind ) {
		const n = ( r.items || [] ).length;
		return n
			? sprintf(
					/* translators: %d: number of questions. */
					_n( '%d question', '%d questions', n, 'zinn-seo' ),
					n
				)
			: '';
	}
	if ( 'howto' === kind ) {
		const n = ( r.steps || [] ).length;
		return n
			? sprintf(
					/* translators: %d: number of steps. */
					_n( '%d step', '%d steps', n, 'zinn-seo' ),
					n
				)
			: '';
	}
	return recordLines( kind, r )
		.map( ( [ term ] ) => term )
		.join( ', ' );
}

/**
 * A record as term/value lines for a preview.
 *
 * @param {string} kind   Kind.
 * @param {Object} record Record.
 * @return {Array<Array<string>>} Lines.
 */
export function recordLines( kind, record ) {
	const r = record || {};
	const out = [];
	if ( 'faq' === kind ) {
		( r.items || [] ).forEach( ( q ) =>
			out.push( [ q.question, q.answer ] )
		);
		return out;
	}
	if ( 'howto' === kind ) {
		if ( r.name ) {
			out.push( [ __( 'Shows how to', 'zinn-seo' ), r.name ] );
		}
		if ( r.minutes ) {
			out.push( [
				__( 'Total time', 'zinn-seo' ),
				sprintf(
					/* translators: %d: minutes. */
					_n( '%d minute', '%d minutes', r.minutes, 'zinn-seo' ),
					r.minutes
				),
			] );
		}
		if ( ( r.supplies || [] ).length ) {
			out.push( [
				__( 'Supplies', 'zinn-seo' ),
				r.supplies.join( ', ' ),
			] );
		}
		if ( ( r.tools || [] ).length ) {
			out.push( [ __( 'Tools', 'zinn-seo' ), r.tools.join( ', ' ) ] );
		}
		( r.steps || [] ).forEach( ( s, i ) =>
			out.push( [
				sprintf(
					/* translators: %d: step number. */
					__( 'Step %d', 'zinn-seo' ),
					i + 1
				),
				s.name ? `${ s.name }: ${ s.text }` : s.text,
			] )
		);
		return out;
	}
	const names = {
		brand: __( 'Brand', 'zinn-seo' ),
		model: __( 'Model', 'zinn-seo' ),
		material: __( 'Material', 'zinn-seo' ),
		color: __( 'Colour', 'zinn-seo' ),
		size: __( 'Size', 'zinn-seo' ),
		description: __( 'Summary', 'zinn-seo' ),
		keywords: __( 'Keywords', 'zinn-seo' ),
		about: __( 'Main topics', 'zinn-seo' ),
	};
	Object.keys( names ).forEach( ( key ) => {
		const value = r[ key ];
		if ( Array.isArray( value ) ? value.length : value ) {
			out.push( [
				names[ key ],
				Array.isArray( value ) ? value.join( ', ' ) : String( value ),
			] );
		}
	} );
	return out;
}

/**
 * The cost line shown before a run.
 *
 * @param {Object}   price Count + estimate from POST /schema/fill (mode count).
 * @param {Function} money Formats micros.
 * @return {string} Sentence.
 */
export function costLine( price, money ) {
	const pages = sprintf(
		/* translators: %d: number of pages. */
		_n(
			'%d page would get a suggestion.',
			'%d pages would get a suggestion.',
			price.count,
			'zinn-seo'
		),
		price.count
	);
	if ( ! price.credits ) {
		return `${ pages } ${ __(
			'Paid by your own AI key, at your provider’s price.',
			'zinn-seo'
		) }`;
	}
	if ( null === price.total_micros || undefined === price.total_micros ) {
		return `${ pages } ${ __(
			'Paid from your Zinn® credit at the measured price per page.',
			'zinn-seo'
		) }`;
	}
	const cost = sprintf(
		/* translators: 1: an amount of money, 2: the price per 100 pages. */
		__( 'About %1$s in Zinn® credit (%2$s per 100 pages).', 'zinn-seo' ),
		money( price.total_micros ),
		money( price.per_page_micros * 100 )
	);
	const balance =
		null === price.balance_micros || undefined === price.balance_micros
			? ''
			: ' ' +
				sprintf(
					/* translators: %s: an amount of money. */
					__( 'Your balance: %s.', 'zinn-seo' ),
					money( price.balance_micros )
				);
	return `${ pages } ${ cost }${ balance }`;
}
