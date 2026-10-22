import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { Address } from './shared';

/** The crawlers the tester offers (the product token each one reads robots.txt as). */
export const TEST_AGENTS = [
	'Googlebot',
	'Googlebot-Image',
	'Bingbot',
	'GPTBot',
	'OAI-SearchBot',
	'ClaudeBot',
	'PerplexityBot',
	'Google-Extended',
	'Applebot',
	'*',
];

/**
 * A robots.txt problem in words.
 *
 * @param {Object} c `{ level, line, code, detail }`.
 * @return {string} Sentence.
 */
export function checkText( c ) {
	switch ( c.code ) {
		case 'unknown':
			return sprintf(
				/* translators: %s: what was written. */
				__(
					'"%s" is not a robots.txt rule; search engines ignore the line. Check the spelling.',
					'zinn-seo'
				),
				c.detail
			);
		case 'no_agent':
			return __(
				'A rule before any "User-agent:" line applies to nobody.',
				'zinn-seo'
			);
		case 'empty_agent':
			return __( 'A "User-agent:" line without a name.', 'zinn-seo' );
		case 'relative_path':
			return sprintf(
				/* translators: %s: a path. */
				__( 'Paths start with / or *: "%s".', 'zinn-seo' ),
				c.detail
			);
		case 'blocks_all':
			return __(
				'This blocks the whole site for every search engine.',
				'zinn-seo'
			);
		case 'sitemap_relative':
			return __(
				'A sitemap must be given as a full address (https://…).',
				'zinn-seo'
			);
		case 'crawl_delay':
			return __(
				'Google ignores Crawl-delay; Bing and Yandex read it.',
				'zinn-seo'
			);
		case 'noindex_ignored':
			return __(
				'Google stopped reading Noindex in robots.txt in 2019. Use "do not index" on the page instead.',
				'zinn-seo'
			);
		case 'yandex_only':
			return __( 'Only Yandex reads this line.', 'zinn-seo' );
		case 'repeated_agent':
			return sprintf(
				/* translators: %s: a crawler name. */
				__(
					'%s has more than one group; crawlers merge them.',
					'zinn-seo'
				),
				c.detail
			);
		case 'too_big':
			return __(
				'Longer than the 500 KB search engines read; the rest is ignored.',
				'zinn-seo'
			);
	}
	return c.code;
}

/**
 * The checks as a list.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.checks Checks.
 * @return {Element} The list.
 */
function Checks( { checks } ) {
	if ( ! checks || checks.length === 0 ) {
		return (
			<p className="zseo-ok">
				{ __( 'No problems found.', 'zinn-seo' ) }
			</p>
		);
	}
	return (
		<ul className="zseo-checklist">
			{ checks.map( ( c, i ) => (
				<li key={ i } className={ `is-${ c.level }` }>
					{ c.line > 0 && (
						<span className="zseo-muted">
							{ sprintf(
								/* translators: %d: a line number. */
								__( 'Line %d:', 'zinn-seo' ),
								c.line
							) }{ ' ' }
						</span>
					) }
					{ checkText( c ) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * robots.txt: the editor, checks, the tester, a real file, and staging copies.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function RobotsTab( { value, onChange, data } ) {
	const [ state, setState ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ preview, setPreview ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ test, setTest ] = useState( { url: '/', agent: 'Googlebot' } );
	const [ result, setResult ] = useState( null );
	const timer = useRef( 0 );

	useEffect( () => {
		seoFetch( '/robots' )
			.then( ( r ) => {
				setState( r );
				setDraft( r.custom );
				setPreview( r );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );

	// Re-check the draft as it is typed (once typing pauses).
	useEffect( () => {
		if ( null === draft || ! state ) {
			return undefined;
		}
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => {
			seoFetch( '/robots/preview', {
				method: 'POST',
				data: { custom: draft },
			} )
				.then( setPreview )
				.catch( () => {} );
		}, 500 );
		return () => window.clearTimeout( timer.current );
	}, [ draft ] );

	if ( ! state ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const save = () => {
		setBusy( true );
		seoFetch( '/robots', { method: 'PUT', data: { custom: draft } } )
			.then( ( r ) => {
				setState( r );
				setDraft( r.custom );
				setPreview( r );
				setNotice( {
					status: 'success',
					text: __( 'robots.txt saved.', 'zinn-seo' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const runTest = () => {
		setResult( null );
		seoFetch( '/robots/test', {
			method: 'POST',
			data: { url: test.url, agent: test.agent, custom: draft },
		} )
			.then( setResult )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	};

	const file = ( action ) => {
		setBusy( true );
		seoFetch( '/robots/file', { method: 'POST', data: { action } } )
			.then( ( r ) => {
				if ( 'import' === action ) {
					setDraft( r.custom );
					setNotice( {
						status: 'info',
						text: __(
							"The file's rules are in the editor. Save them, then move the file aside so they are served.",
							'zinn-seo'
						),
					} );
					return;
				}
				setState( r );
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %s: a file name. */
						__(
							'The file was renamed to %s. WordPress now serves the text below.',
							'zinn-seo'
						),
						r.moved_to
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const shown = preview || state;

	return (
		<div className="zseo-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ state.physical && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							"A real robots.txt file is in your site's folder, so search engines read that file and nothing set here. Bring its rules in, save them, then move the file aside.",
							'zinn-seo'
						) }
					</p>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () => file( 'import' ) }
						>
							{ __( 'Bring its rules in', 'zinn-seo' ) }
						</Button>
						{ data.isAdmin && (
							<Button
								variant="secondary"
								disabled={ busy }
								onClick={ () => file( 'move' ) }
							>
								{ __( 'Move the file aside', 'zinn-seo' ) }
							</Button>
						) }
					</div>
				</Notice>
			) }
			{ state.discouraged && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The site asks search engines not to index it (Settings, Reading), so robots.txt blocks everything until that is switched off.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ state.subfolder && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'WordPress is installed in a folder, and search engines read robots.txt only at the root of the domain. Add these rules to the root robots.txt instead.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<Card>
				<CardBody className="zseo-stack">
					<div>
						<p className="zseo-legend">
							{ __( 'Your robots.txt', 'zinn-seo' ) }
						</p>
						<Address href={ state.url } />
					</div>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							"Keep WordPress's standard rules",
							'zinn-seo'
						) }
						help={ __(
							'Keeps crawlers out of the admin area while letting themes load content through admin-ajax.php.',
							'zinn-seo'
						) }
						checked={ !! value.robots_defaults }
						onChange={ ( v ) => onChange( { robots_defaults: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						className="zseo-code-input"
						label={ __( 'Your own rules', 'zinn-seo' ) }
						help={ __(
							'Added after the standard rules and the AI crawler choices. The sitemap line is added for you.',
							'zinn-seo'
						) }
						rows={ 8 }
						dir="ltr"
						value={ draft || '' }
						onChange={ setDraft }
					/>
					<Checks checks={ shown.own_checks } />
					<div className="zseo-actions">
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || draft === state.custom }
							onClick={ save }
						>
							{ __( 'Save robots.txt', 'zinn-seo' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
			<PanelBody
				title={ __( 'What search engines will read', 'zinn-seo' ) }
			>
				<pre className="zseo-pre" dir="ltr">
					{ shown.text }
				</pre>
				<Checks checks={ shown.checks } />
			</PanelBody>
			<PanelBody title={ __( 'Test an address', 'zinn-seo' ) }>
				<div className="zseo-row">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Address or path', 'zinn-seo' ) }
						dir="ltr"
						value={ test.url }
						onChange={ ( url ) => setTest( { ...test, url } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Crawler', 'zinn-seo' ) }
						value={ test.agent }
						options={ TEST_AGENTS.map( ( a ) => ( {
							value: a,
							label:
								'*' === a
									? __( 'Any other crawler (*)', 'zinn-seo' )
									: a,
						} ) ) }
						onChange={ ( agent ) => setTest( { ...test, agent } ) }
					/>
					<div className="zseo-actions zseo-actions--start">
						<Button variant="secondary" onClick={ runTest }>
							{ __( 'Test', 'zinn-seo' ) }
						</Button>
					</div>
				</div>
				{ result && (
					<p
						className={ result.allowed ? 'zseo-ok' : 'zseo-warn' }
						aria-live="polite"
					>
						{ result.allowed
							? __( 'Allowed.', 'zinn-seo' )
							: __( 'Blocked.', 'zinn-seo' ) }{ ' ' }
						<span className="zseo-muted">
							{ result.rule
								? sprintf(
										/* translators: 1: a rule, 2: a line number, 3: a crawler name. */
										__(
											'Rule "%1$s" on line %2$d, in the group for %3$s.',
											'zinn-seo'
										),
										result.rule,
										result.line,
										result.group || '*'
									)
								: __(
										'No rule matches this address.',
										'zinn-seo'
									) }
						</span>
					</p>
				) }
			</PanelBody>
			<PanelBody
				title={ __( 'Staging copies', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<div className="zseo-stack">
					<p>
						{ state.staging
							? __(
									'This site looks like a staging or development copy: every page asks search engines not to index it, robots.txt blocks everything, and nothing is sent to search engines.',
									'zinn-seo'
								)
							: __(
									'This site is treated as the live site.',
									'zinn-seo'
								) }
					</p>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Treat this site as', 'zinn-seo' ) }
						value={ value.staging_mode }
						options={ [
							{
								value: 'auto',
								label: __(
									'Decide by itself (recommended)',
									'zinn-seo'
								),
							},
							{
								value: 'on',
								label: __( 'A staging copy', 'zinn-seo' ),
							},
							{
								value: 'off',
								label: __( 'The live site', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) => onChange( { staging_mode: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'More staging addresses', 'zinn-seo' ) }
						help={ __(
							"One host per line; * matches anything (staging.*.example.com). Common hosts' staging addresses and WordPress's environment type are recognised already.",
							'zinn-seo'
						) }
						rows={ 3 }
						dir="ltr"
						value={ value.staging_hosts || '' }
						onChange={ ( v ) => onChange( { staging_hosts: v } ) }
					/>
				</div>
			</PanelBody>
		</div>
	);
}
