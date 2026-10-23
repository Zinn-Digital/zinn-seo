/**
 * The AI copywriter (zs-782): pure helpers shared by the admin screen, the block editor and
 * Elementor. No React here, so every piece is unit-tested.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The tool groups, in the order the picker shows them.
 *
 * @return {Array<{id: string, label: string}>} Groups.
 */
export function groups() {
	return [
		{ id: 'blog', label: __( 'Blog', 'zinn-seo' ) },
		{ id: 'seo', label: __( 'SEO', 'zinn-seo' ) },
		{ id: 'shop', label: __( 'Shop', 'zinn-seo' ) },
		{ id: 'social', label: __( 'Social media', 'zinn-seo' ) },
		{ id: 'email', label: __( 'E-mail', 'zinn-seo' ) },
		{ id: 'landing', label: __( 'Landing pages', 'zinn-seo' ) },
		{ id: 'about', label: __( 'About and jobs', 'zinn-seo' ) },
		{ id: 'edit', label: __( 'Editing', 'zinn-seo' ) },
		{ id: 'other', label: __( 'Other', 'zinn-seo' ) },
		{ id: 'own', label: __( 'Your own tools', 'zinn-seo' ) },
	];
}

/**
 * The input fields a tool can ask for, with their words.
 *
 * @return {Object<string, {label: string, help: string, long: boolean}>} Inputs.
 */
export function inputFields() {
	return {
		topic: {
			label: __( 'What is it about?', 'zinn-seo' ),
			help: __(
				'A topic, a product, a question or an instruction.',
				'zinn-seo'
			),
			long: false,
		},
		text: {
			label: __( 'Your text', 'zinn-seo' ),
			help: __( 'The text to work on.', 'zinn-seo' ),
			long: true,
		},
		audience: {
			label: __( 'Who is it for?', 'zinn-seo' ),
			help: __(
				'For example: first-time buyers, busy parents.',
				'zinn-seo'
			),
			long: false,
		},
		details: {
			label: __( 'Facts and points to include', 'zinn-seo' ),
			help: __(
				'Prices, features, dates, an offer: it uses only the facts you give.',
				'zinn-seo'
			),
			long: true,
		},
		reply_to: {
			label: __( 'What are you answering?', 'zinn-seo' ),
			help: __(
				'Paste the comment, review, e-mail or post.',
				'zinn-seo'
			),
			long: true,
		},
	};
}

/**
 * Tools filtered by group and words in their name.
 *
 * @param {Array}  tools  Tools from GET /copywriter.
 * @param {string} group  Group id ('' all).
 * @param {string} search Words.
 * @return {Array} Tools.
 */
export function filterTools( tools, group, search ) {
	const words = String( search || '' )
		.toLowerCase()
		.split( /\s+/ )
		.filter( Boolean );
	return ( tools || [] ).filter(
		( t ) =>
			( ! group || t.group === group ) &&
			words.every( ( w ) =>
				String( t.label ).toLowerCase().includes( w )
			)
	);
}

/**
 * Fill a library prompt's {placeholders} from the page: {phrase} the focus phrase, {title} the
 * page title, {site} the site's name. The rest stay for the person to replace.
 *
 * @param {string} prompt Prompt.
 * @param {Object} ctx    {phrase, title, site}.
 * @return {string} Prompt.
 */
export function fillPrompt( prompt, ctx = {} ) {
	return String( prompt || '' ).replace(
		/\{(phrase|title|site)\}/g,
		( m, k ) => ( ctx[ k ] ? String( ctx[ k ] ) : m )
	);
}

/**
 * Placeholders still to replace in a prompt.
 *
 * @param {string} prompt Prompt.
 * @return {Array<string>} Names in braces.
 */
export function openSlots( prompt ) {
	return Array.from(
		new Set(
			( String( prompt || '' ).match( /\{[^{}]{1,40}\}/g ) || [] ).map(
				( s ) => s.slice( 1, -1 )
			)
		)
	);
}

/**
 * A variant as blocks: a line starting "H2: " is a heading, a numbered or "- " list is a list,
 * blank lines separate paragraphs.
 *
 * @param {string} text Variant.
 * @return {Array<{name: string, attributes: Object, inner: Array|undefined}>} Block specs (core/heading, core/list with core/list-item, core/paragraph).
 */
export function toBlocks( text ) {
	const out = [];
	const esc = ( s ) =>
		String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	let list = null;
	let para = [];
	const flushPara = () => {
		if ( para.length ) {
			out.push( {
				name: 'core/paragraph',
				attributes: { content: para.map( esc ).join( '<br>' ) },
			} );
			para = [];
		}
	};
	const flushList = () => {
		if ( list ) {
			// A list is a list block with one list-item block per item (WordPress 6.1+).
			out.push( {
				name: 'core/list',
				attributes: { ordered: list.ordered },
				inner: list.items.map( ( i ) => ( {
					name: 'core/list-item',
					attributes: { content: esc( i ) },
				} ) ),
			} );
			list = null;
		}
	};
	String( text || '' )
		.split( /\r?\n/ )
		.forEach( ( raw ) => {
			const line = raw.trim();
			if ( ! line ) {
				flushPara();
				flushList();
				return;
			}
			const heading = line.match( /^H([23]):\s*(.+)$/ );
			if ( heading ) {
				flushPara();
				flushList();
				out.push( {
					name: 'core/heading',
					attributes: {
						level: Number( heading[ 1 ] ),
						content: esc( heading[ 2 ] ),
					},
				} );
				return;
			}
			const ordered = line.match( /^\d+[.)/]\s+(.+)$/ );
			const bullet = line.match( /^[-•*+]\s+(.+)$/ );
			if ( ordered || bullet ) {
				flushPara();
				const isOrdered = !! ordered;
				if ( ! list || list.ordered !== isOrdered ) {
					flushList();
					list = { ordered: isOrdered, items: [] };
				}
				list.items.push( ( ordered || bullet )[ 1 ] );
				return;
			}
			flushList();
			para.push( line );
		} );
	flushPara();
	flushList();
	return out;
}

/**
 * The same as simple HTML (for Elementor's text widget and the classic editor).
 *
 * @param {string} text Variant.
 * @return {string} HTML.
 */
export function toHtml( text ) {
	return toBlocks( text )
		.map( ( b ) => {
			if ( b.name === 'core/heading' ) {
				return `<h${ b.attributes.level }>${ b.attributes.content }</h${ b.attributes.level }>`;
			}
			if ( b.name === 'core/list' ) {
				const tag = b.attributes.ordered ? 'ol' : 'ul';
				const items = b.inner
					.map( ( i ) => `<li>${ i.attributes.content }</li>` )
					.join( '' );
				return `<${ tag }>${ items }</${ tag }>`;
			}
			return `<p>${ b.attributes.content }</p>`;
		} )
		.join( '\n' );
}

/**
 * A language's name in the screen's own language (the browser knows every language's name).
 *
 * @param {string} code   Locale (de_DE, pt_BR, en_US).
 * @param {string} screen The screen's locale.
 * @return {string} Name.
 */
export function languageName( code, screen = 'en' ) {
	const tag = String( code || '' ).replace( '_', '-' );
	try {
		const names = new window.Intl.DisplayNames(
			[ String( screen || 'en' ).replace( '_', '-' ) ],
			{ type: 'language' }
		);
		return names.of( tag ) || tag;
	} catch {
		return tag;
	}
}

/**
 * What a variant's check means, as short lines with a level (good, warning, bad).
 *
 * @param {Object} check The server's check of one variant.
 * @return {Array<{level: string, text: string}>} Lines.
 */
export function checkLines( check ) {
	if ( ! check ) {
		return [];
	}
	const out = [];
	if ( check.limit > 0 ) {
		out.push(
			check.over
				? {
						level: 'bad',
						text: sprintf(
							/* translators: %d: characters allowed. */
							__( 'Too long: over %d characters', 'zinn-seo' ),
							check.limit
						),
					}
				: {
						level: 'good',
						text: sprintf(
							/* translators: 1: characters used, 2: characters allowed. */
							__( '%1$d of %2$d characters', 'zinn-seo' ),
							check.length,
							check.limit
						),
					}
		);
	}
	if ( check.focus === true ) {
		out.push( {
			level: 'good',
			text: __( 'Uses your focus phrase', 'zinn-seo' ),
		} );
	} else if ( check.focus === false ) {
		out.push( {
			level: 'warning',
			text: __( 'Does not use your focus phrase', 'zinn-seo' ),
		} );
	}
	let ease;
	if ( check.ease >= 60 ) {
		ease = {
			level: 'good',
			text: sprintf(
				/* translators: %d: a score out of 100. */
				__( 'Easy to read (%d/100)', 'zinn-seo' ),
				check.ease
			),
		};
	} else if ( check.ease >= 40 ) {
		ease = {
			level: 'warning',
			text: sprintf(
				/* translators: %d: a score out of 100. */
				__(
					'Fairly hard to read (%d/100): shorter sentences help',
					'zinn-seo'
				),
				check.ease
			),
		};
	} else {
		ease = {
			level: 'bad',
			text: sprintf(
				/* translators: %d: a score out of 100. */
				__(
					'Hard to read (%d/100): shorter sentences and words help',
					'zinn-seo'
				),
				check.ease
			),
		};
	}
	out.push( ease );
	const kinds = {
		guarantee: __( 'a guarantee', 'zinn-seo' ),
		superlative: __( 'a "best" claim', 'zinn-seo' ),
		health: __( 'a health claim', 'zinn-seo' ),
		money: __( 'an earnings claim', 'zinn-seo' ),
		urgency: __( 'urgency', 'zinn-seo' ),
	};
	Object.keys( check.claims || {} ).forEach( ( k ) => {
		out.push( {
			level: 'warning',
			text: sprintf(
				/* translators: 1: a kind of claim, 2: the words found. */
				__( 'Check before publishing: %1$s ("%2$s")', 'zinn-seo' ),
				kinds[ k ] || k,
				( check.claims[ k ] || [] ).join( '", "' )
			),
		} );
	} );
	return out;
}
