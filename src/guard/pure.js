/**
 * Safe updates without React (zs-655, zs-775): every word the screen shows about a verdict, a
 * reason, a test's finding and a run, in one place the unit tests read.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * A verdict's words.
 *
 * @param {string} verdict ok|warn|block.
 * @return {string} Words.
 */
export function verdictName( verdict ) {
	switch ( verdict ) {
		case 'ok':
			return __( 'Suits your site', 'zinn-seo' );
		case 'block':
			return __( 'Will not run here', 'zinn-seo' );
		default:
			return __( 'Check first', 'zinn-seo' );
	}
}

/**
 * The badge colour of a verdict or a test result.
 *
 * @param {string} v ok|pass|warn|checked|block|fail.
 * @return {string} success|warning|error|info.
 */
export function tone( v ) {
	if ( v === 'ok' || v === 'pass' || v === 'applied' ) {
		return 'success';
	}
	if ( v === 'block' || v === 'fail' || v === 'rolled_back' ) {
		return 'error';
	}
	if ( v === 'checked' ) {
		return 'info';
	}
	return 'warning';
}

/**
 * One reason in plain words, naming the two versions compared.
 *
 * @param {Object} r `{code, need, have}`.
 * @return {string} Words.
 */
export function reason( r ) {
	switch ( r.code ) {
		case 'php':
			return sprintf(
				/* translators: 1: PHP version needed, 2: this site's PHP version. */
				__( 'Needs PHP %1$s; your site runs %2$s.', 'zinn-seo' ),
				r.need,
				r.have
			);
		case 'wp':
			return sprintf(
				/* translators: 1: WordPress version needed, 2: this site's WordPress version. */
				__( 'Needs WordPress %1$s; your site runs %2$s.', 'zinn-seo' ),
				r.need,
				r.have
			);
		case 'tested':
			return sprintf(
				/* translators: 1: last WordPress version it was tested with, 2: this site's version. */
				__(
					'Tested up to WordPress %1$s; your site runs %2$s.',
					'zinn-seo'
				),
				r.need,
				r.have
			);
		case 'failed_here':
			return sprintf(
				/* translators: %s: a date. */
				__(
					'This version already failed on your site (%s): its test report says why.',
					'zinn-seo'
				),
				r.need
			);
		case 'requires_plugins':
			return sprintf(
				/* translators: %s: plugin slugs. */
				__(
					'It needs a plugin your site does not run (%s), so WordPress would not load it.',
					'zinn-seo'
				),
				r.need
			);
		case 'wc':
			return sprintf(
				/* translators: 1: WooCommerce version needed, 2: this site's WooCommerce version. */
				__(
					'Needs WooCommerce %1$s; your shop runs %2$s.',
					'zinn-seo'
				),
				r.need,
				r.have
			);
		case 'wc_tested':
			return sprintf(
				/* translators: 1: last WooCommerce version it was tested with, 2: this shop's version. */
				__(
					'Tested up to WooCommerce %1$s; your shop runs %2$s.',
					'zinn-seo'
				),
				r.need,
				r.have
			);
		case 'php_removed':
			return sprintf(
				/* translators: 1: PHP function names, 2: this site's PHP version. */
				__(
					'Its code calls %1$s, which PHP %2$s no longer has: the page stops with an error when that line runs.',
					'zinn-seo'
				),
				r.need,
				r.have
			);
		case 'core_php':
			return sprintf(
				/* translators: 1: PHP version needed, 2: this site's PHP version. */
				__(
					'This WordPress needs PHP %1$s; your site runs %2$s.',
					'zinn-seo'
				),
				r.need,
				r.have
			);
		case 'core_mysql':
			return sprintf(
				/* translators: 1: database version needed, 2: this site's database version. */
				__(
					'This WordPress needs database server %1$s; yours is %2$s.',
					'zinn-seo'
				),
				r.need,
				r.have
			);
		case 'core_plugins':
			return sprintf(
				/* translators: 1: number of plugins, 2: their names. */
				_n(
					'%1$d active plugin was not tested with this WordPress yet: %2$s.',
					'%1$d active plugins were not tested with this WordPress yet: %2$s.',
					Number( r.have ) || 0,
					'zinn-seo'
				),
				Number( r.have ) || 0,
				r.need
			);
		default:
			return __(
				'Its author did not say which versions it needs.',
				'zinn-seo'
			);
	}
}

/**
 * A key page's name.
 *
 * @param {string} name Page id.
 * @return {string} Words.
 */
export function pageName( name ) {
	const names = {
		home: __( 'Home page', 'zinn-seo' ),
		post: __( 'Newest post', 'zinn-seo' ),
		page: __( 'Newest page', 'zinn-seo' ),
		search: __( 'Search results', 'zinn-seo' ),
		missing: __( 'A missing page (404)', 'zinn-seo' ),
		login: __( 'Login page', 'zinn-seo' ),
		rest: __( 'REST API', 'zinn-seo' ),
		dashboard: __( 'Dashboard (signed in)', 'zinn-seo' ),
		posts: __( 'Posts list (signed in)', 'zinn-seo' ),
		plugins: __( 'Plugins screen (signed in)', 'zinn-seo' ),
		editor: __( 'Post editor (signed in)', 'zinn-seo' ),
		shop: __( 'Shop', 'zinn-seo' ),
		product: __( 'Newest product', 'zinn-seo' ),
		cart: __( 'Cart (with a product added)', 'zinn-seo' ),
		checkout: __( 'Checkout', 'zinn-seo' ),
	};
	if ( names[ name ] ) {
		return names[ name ];
	}
	const form = /^form-(\d+)$/.exec( name || '' );
	return form
		? sprintf(
				/* translators: %d: number. */
				__( 'Page with a form %d', 'zinn-seo' ),
				Number( form[ 1 ] )
			)
		: name;
}

/**
 * One finding on a page, in plain words.
 *
 * @param {Object} x `{code, level, detail}`.
 * @return {string} Words.
 */
export function issue( x ) {
	switch ( x.code ) {
		case 'fatal':
			return __(
				'It stops with a PHP fatal error with the new version.',
				'zinn-seo'
			);
		case 'down':
			return sprintf(
				/* translators: %s: HTTP status code. */
				__(
					'It does not answer with the new version (code %s).',
					'zinn-seo'
				),
				x.detail
			);
		case 'status':
			return sprintf(
				/* translators: %s: status codes before and after, like 200→404. */
				__( 'It answers differently: %s.', 'zinn-seo' ),
				x.detail
			);
		case 'form':
			return __(
				'Its form is gone with the new version: visitors could not send it.',
				'zinn-seo'
			);
		case 'layout':
			return sprintf(
				/* translators: %s: parts of a page, like nav, img. */
				__(
					'Its layout changed: fewer or missing parts (%s).',
					'zinn-seo'
				),
				x.detail
			);
		case 'words':
			return sprintf(
				/* translators: %s: a percentage. */
				__(
					'Its text reads differently: only %s%% is the same.',
					'zinn-seo'
				),
				x.detail
			);
		case 'seo':
			return sprintf(
				/* translators: %s: title, description, canonical, robots or schema. */
				__( 'What it tells search engines changed (%s).', 'zinn-seo' ),
				x.detail
			);
		case 'slow':
			return sprintf(
				/* translators: %s: milliseconds before and after, like 300→1500. */
				__( 'It is much slower: %s ms.', 'zinn-seo' ),
				x.detail
			);
		default:
			return x.code;
	}
}

/**
 * A run's state, in a word or two.
 *
 * @param {Object} run Run.
 * @return {string} Words.
 */
export function runState( run ) {
	switch ( run.status ) {
		case 'queued':
			return __( 'Waiting', 'zinn-seo' );
		case 'testing':
			return __( 'Testing on a copy…', 'zinn-seo' );
		case 'applying':
			return __( 'Installing…', 'zinn-seo' );
		case 'applied':
			return __( 'Installed', 'zinn-seo' );
		case 'rolled_back':
			return __( 'Put back', 'zinn-seo' );
		case 'stopped':
			return __( 'Stopped', 'zinn-seo' );
	}
	switch ( run.verdict ) {
		case 'pass':
			return __( 'Passed', 'zinn-seo' );
		case 'warn':
			return __( 'Passed with changes', 'zinn-seo' );
		case 'fail':
			return __( 'Failed', 'zinn-seo' );
		case 'checked':
			return __( 'Checked', 'zinn-seo' );
	}
	return __( 'Done', 'zinn-seo' );
}

/**
 * What a person can do with a run now.
 *
 * @param {Object} run Run.
 * @return {{install: boolean, confirm: boolean, putBack: boolean, explain: boolean}} Actions.
 */
export function actions( run ) {
	const done = run.status === 'done';
	const core = run.kind === 'core';
	return {
		install:
			done &&
			run.offered !== false &&
			( core
				? run.verdict === 'checked' || run.verdict === 'pass'
				: run.verdict === 'pass' || run.verdict === 'warn' ),
		confirm: done && run.verdict === 'warn',
		putBack: run.status === 'applied' && ! core && !! run.undo,
		explain:
			[ 'done', 'rolled_back', 'applied' ].includes( run.status ) &&
			( run.verdict === 'fail' ||
				run.verdict === 'warn' ||
				run.status === 'rolled_back' ),
	};
}

/**
 * Is the background test still working?
 *
 * @param {Object} job `{status}`.
 * @return {boolean} Busy.
 */
export function busy( job ) {
	return !! job && job.status === 'pending';
}
