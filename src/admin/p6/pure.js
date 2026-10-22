/**
 * Pure helpers of the "Move from another SEO plugin" screen (unit-tested).
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The name of one of our per-page fields, in words.
 *
 * @param {string} field Field name.
 * @return {string} Words.
 */
export function fieldLabel( field ) {
	return (
		{
			title: __( 'SEO titles', 'zinn-seo' ),
			desc: __( 'Meta descriptions', 'zinn-seo' ),
			canonical: __( 'Canonical addresses', 'zinn-seo' ),
			robots: __(
				'Search engine choices (noindex, nofollow…)',
				'zinn-seo'
			),
			max_snippet: __( 'Snippet length limits', 'zinn-seo' ),
			max_image: __( 'Image preview limits', 'zinn-seo' ),
			max_video: __( 'Video preview limits', 'zinn-seo' ),
			focus: __( 'Focus phrases', 'zinn-seo' ),
			cornerstone: __( 'Cornerstone marks', 'zinn-seo' ),
			og_title: __( 'Titles when shared', 'zinn-seo' ),
			og_desc: __( 'Texts when shared', 'zinn-seo' ),
			og_image: __( 'Images when shared', 'zinn-seo' ),
			x_title: __( 'Titles on X', 'zinn-seo' ),
			x_desc: __( 'Texts on X', 'zinn-seo' ),
			x_image: __( 'Images on X', 'zinn-seo' ),
			schema_page: __( 'Page types (structured data)', 'zinn-seo' ),
			schema_article: __( 'Article types (structured data)', 'zinn-seo' ),
			primary_term: __( 'Primary categories', 'zinn-seo' ),
			crumb: __( 'Names in breadcrumbs', 'zinn-seo' ),
			schema_import: __(
				'Structured data of other kinds (recipes, events, products…)',
				'zinn-seo'
			),
			x: __( 'Authors’ X accounts', 'zinn-seo' ),
			facebook: __( 'Authors’ Facebook pages', 'zinn-seo' ),
			fediverse: __( 'Authors’ fediverse accounts', 'zinn-seo' ),
			same_as: __( 'Authors’ other profiles', 'zinn-seo' ),
			job_title: __( 'Authors’ job titles', 'zinn-seo' ),
		}[ field ] || field
	);
}

/**
 * The name of a compared part of a page's head, in words.
 *
 * @param {string} part Part.
 * @return {string} Words.
 */
export function partLabel( part ) {
	return (
		{
			title: __( 'Title', 'zinn-seo' ),
			description: __( 'Meta description', 'zinn-seo' ),
			canonical: __( 'Canonical address', 'zinn-seo' ),
			robots: __( 'Search engine directives', 'zinn-seo' ),
			og_title: __( 'Title when shared', 'zinn-seo' ),
			og_description: __( 'Text when shared', 'zinn-seo' ),
			og_image: __( 'Image when shared', 'zinn-seo' ),
			x_title: __( 'Title on X', 'zinn-seo' ),
			x_description: __( 'Text on X', 'zinn-seo' ),
			x_image: __( 'Image on X', 'zinn-seo' ),
			types: __( 'Structured data types missing here', 'zinn-seo' ),
			redirected: __( 'It redirects this address', 'zinn-seo' ),
			redirect: __( 'Redirect', 'zinn-seo' ),
			doubled: __( 'Printed twice', 'zinn-seo' ),
			page: __( 'The page itself', 'zinn-seo' ),
			hreflang: __( 'Language links (hreflang)', 'zinn-seo' ),
			product: __( 'Product structured data', 'zinn-seo' ),
			robots_txt: __( 'robots.txt rules', 'zinn-seo' ),
			robots_sitemap: __( 'Sitemap line in robots.txt', 'zinn-seo' ),
			sitemap: __( 'Sitemap index', 'zinn-seo' ),
		}[ part ] || part
	);
}

/**
 * A compared value as text: lists joined, nothing said as "(none)".
 *
 * @param {string|Array|null} value Value.
 * @return {string} Text.
 */
export function showValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.length ? value.join( ', ' ) : __( '(none)', 'zinn-seo' );
	}
	if ( value === null || value === undefined || value === '' ) {
		return __( '(none)', 'zinn-seo' );
	}
	return String( value );
}

/**
 * The rows of a count: what would come over, largest first.
 *
 * @param {Object|null} counts Counts of a count or a copy.
 * @return {Array<{id: string, label: string, n: number}>} Rows.
 */
export function countRows( counts ) {
	if ( ! counts ) {
		return [];
	}
	const rows = Object.keys( counts.fields || {} )
		.map( ( field ) => ( {
			id: field,
			label: fieldLabel( field ),
			n: Number( counts.fields[ field ] ) || 0,
		} ) )
		.sort( ( a, b ) => b.n - a.n || a.label.localeCompare( b.label ) );
	if ( counts.settings ) {
		rows.push( {
			id: '_settings',
			label: __( 'Site settings and templates', 'zinn-seo' ),
			n: Number( counts.settings ),
		} );
	}
	const r = counts.redirects || {};
	const redirects = ( Number( r.new ) || 0 ) + ( Number( r.replace ) || 0 );
	if ( redirects ) {
		rows.push( {
			id: '_redirects',
			label: __( 'Redirects', 'zinn-seo' ),
			n: redirects,
		} );
	}
	return rows;
}

/**
 * How many values a count or a copy holds (fields + settings + redirects).
 *
 * @param {Object|null} counts Counts.
 * @return {number} Total.
 */
export function total( counts ) {
	return countRows( counts ).reduce( ( sum, row ) => sum + row.n, 0 );
}

/**
 * Do a count and a copy say the same? (They are the same code path; the screen shows it.)
 *
 * @param {Object|null} dry     The count.
 * @param {Object|null} applied The copy.
 * @return {boolean} Same.
 */
export function sameCounts( dry, applied ) {
	if ( ! dry || ! applied ) {
		return false;
	}
	const flat = ( c ) =>
		JSON.stringify(
			countRows( c )
				.map( ( row ) => [ row.id, row.n ] )
				.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
		);
	return (
		flat( dry ) === flat( applied ) &&
		( Number( dry.users ) || 0 ) === ( Number( applied.users ) || 0 ) &&
		Number( dry.posts ) === Number( applied.posts ) &&
		Number( dry.terms ) === Number( applied.terms )
	);
}

/**
 * Where the move stands: which step is next.
 *
 * @param {Object} status `GET /import`.
 * @param {Object} source The chosen source (`{id, active, redirects}`).
 * @return {string} count|copy|check|switch|done|off.
 */
export function stage( status, source ) {
	const s = status.state || {};
	const mine = s.source === source.id && ! s.undone;
	if ( mine && s.switched && ! s.switched.verifying ) {
		return 'done';
	}
	if ( ! mine || ! s.applied ) {
		// A count changes nothing and so does not start a move; it only has to be of this plugin.
		return s.dry && s.dry.source === source.id ? 'copy' : 'count';
	}
	if ( ! source.active ) {
		return 'off';
	}
	const c = s.check;
	if ( ! c || ! c.finished ) {
		return 'check';
	}
	return 'switch';
}

/**
 * Differences still open after a comparison.
 *
 * @param {Object|null} check The comparison's result.
 * @return {number} Count.
 */
export function openDifferences( check ) {
	if ( ! check ) {
		return 0;
	}
	return (
		( Number( check.different ) || 0 ) +
		( Number( ( check.redirects || {} ).different ) || 0 ) +
		( Number( check.failed ) || 0 )
	);
}

/**
 * One line that says what a comparison found.
 *
 * @param {Object} check The comparison's result.
 * @return {string} Sentence.
 */
export function checkSummary( check ) {
	const pages = Number( check.pages ) || 0;
	const rules = Number( ( check.redirects || {} ).rules ) || 0;
	const parts = [];
	if ( pages ) {
		parts.push(
			sprintf(
				/* translators: 1: pages compared, 2: pages that say the same. */
				__(
					'%1$d pages compared, %2$d say exactly the same.',
					'zinn-seo'
				),
				pages,
				Number( check.equal ) || 0
			)
		);
	}
	if ( rules ) {
		parts.push(
			sprintf(
				/* translators: 1: redirects compared, 2: redirects that are the same. */
				__( '%1$d redirects compared, %2$d the same.', 'zinn-seo' ),
				rules,
				Number( check.redirects.equal ) || 0
			)
		);
	}
	if ( Number( check.kept ) ) {
		parts.push(
			sprintf(
				/* translators: %d: number of values. */
				__(
					'%d values were stored exactly as the other plugin showed them.',
					'zinn-seo'
				),
				Number( check.kept )
			)
		);
	}
	return parts.join( ' ' );
}

/**
 * The preflight items in the order a person reads them: what stops the move first.
 *
 * @param {Object|null} preflight The preflight (`items`).
 * @return {Array} Items.
 */
export function preflightItems( preflight ) {
	const rank = { stop: 0, note: 1, ok: 2 };
	return [ ...( ( preflight && preflight.items ) || [] ) ].sort(
		( a, b ) => ( rank[ a.level ] ?? 3 ) - ( rank[ b.level ] ?? 3 )
	);
}

/**
 * Has the copy now in place been read back and found stored?
 *
 * @param {Object} state The move's state.
 * @return {boolean} Proven.
 */
export function proven( state ) {
	const p = state && state.proof;
	return !! (
		p &&
		p.ok &&
		p.finished &&
		state.applied &&
		p.batch === state.applied.batch
	);
}
