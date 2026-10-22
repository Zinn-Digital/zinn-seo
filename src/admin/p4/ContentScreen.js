/**
 * Content: every page's SEO and readability score (zs-011), duplicate titles, descriptions,
 * headings and focus phrases (zs-431, zs-009), and what the readability checks measure in each of
 * the site's languages (zs-003).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
} from '@wordpress/components';

import { viewHref } from '../../admin-kit/router';
import { errorText, seoFetch } from '../api';
import SiteSearchTab from '../p5/SiteSearchTab';
import ScheduledTab from '../x1a/ScheduledTab';
import {
	hashParams,
	JobLine,
	Pill,
	RunButton,
	Table,
	useJob,
	useRoute,
} from './shared';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function contentTabs() {
	return [
		{ name: 'scores', title: __( 'Scores', 'zinn-seo' ) },
		{ name: 'duplicates', title: __( 'Duplicates', 'zinn-seo' ) },
		{ name: 'languages', title: __( 'Languages', 'zinn-seo' ) },
		{ name: 'search', title: __( 'Site search', 'zinn-seo' ) },
		{ name: 'scheduled', title: __( 'Scheduled changes', 'zinn-seo' ) },
	];
}

/**
 * Words for a check id the inbox links to.
 *
 * @return {Object<string, string>} Id => words.
 */
function issueNames() {
	return {
		alt_missing: __( 'Images without alt text', 'zinn-seo' ),
		desc_length: __( 'No meta description', 'zinn-seo' ),
		heading_order: __( 'Headings skip a level', 'zinn-seo' ),
		internal_links: __( 'No internal link', 'zinn-seo' ),
		empty_links: __( 'Links with no text', 'zinn-seo' ),
		phrase_used: __( 'Focus phrase used by another page', 'zinn-seo' ),
		text_length: __( 'Too little text', 'zinn-seo' ),
		phrase_title: __( 'Focus phrase not in the title', 'zinn-seo' ),
		noindex: __( 'Hidden from search', 'zinn-seo' ),
		phrase_desc: __( 'Focus phrase not in the description', 'zinn-seo' ),
		phrase_intro: __(
			'Focus phrase not in the first paragraph',
			'zinn-seo'
		),
		phrase_density: __(
			'Focus phrase used too little or too much',
			'zinn-seo'
		),
		title_length: __( 'No SEO title', 'zinn-seo' ),
		alt_quality: __( 'Alt text is a file name', 'zinn-seo' ),
		frame_titles: __( 'Frames without a title', 'zinn-seo' ),
		empty_headings: __( 'Empty headings', 'zinn-seo' ),
		sentence_length: __( 'Long sentences', 'zinn-seo' ),
		paragraph_length: __( 'Long paragraphs', 'zinn-seo' ),
		subheadings: __( 'Too few subheadings', 'zinn-seo' ),
		transitions: __( 'Few transition words', 'zinn-seo' ),
		passive: __( 'Much passive voice', 'zinn-seo' ),
		sentence_starts: __( 'Sentences start the same way', 'zinn-seo' ),
		reading_ease: __( 'Hard to read', 'zinn-seo' ),
		cornerstone_inbound: __(
			'Cornerstone with few links to it',
			'zinn-seo'
		),
		inclusive: __( 'Words that may exclude readers', 'zinn-seo' ),
	};
}

/**
 * The scores tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function Scores( { data } ) {
	const params = hashParams();
	const [ rating, setRating ] = useState( params.get( 'rating' ) || '' );
	const [ issue, setIssue ] = useState( params.get( 'issue' ) || '' );
	const [ type, setType ] = useState( params.get( 'type' ) || '' );
	const [ types, setTypes ] = useState( [] );
	const [ hidden, setHidden ] = useState( 0 );
	const [ rows, setRows ] = useState( [] );
	const [ next, setNext ] = useState( 0 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/analysis/list?per=1' ).then( ( r ) => r.job ),
		() => load( 0, true )
	);
	const load = ( before, replace ) => {
		setLoading( true );
		const q = new URLSearchParams( {
			per: '50',
			before: String( before ),
			rating,
			issue,
			type,
		} );
		seoFetch( `/analysis/list?${ q.toString() }` )
			.then( ( r ) => {
				setRows( replace ? r.items : [ ...rows, ...r.items ] );
				setNext( r.next );
				setJob( r.job );
				setTypes( r.types || [] );
				setHidden( r.hidden || 0 );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setLoading( false ) );
	};
	useEffect( () => {
		load( 0, true );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ rating, issue, type ] );
	const names = issueNames();
	return (
		<div className="zseo-stack">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<div className="zseo-filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Show', 'zinn-seo' ) }
					value={ rating }
					onChange={ setRating }
					options={ [
						{ value: '', label: __( 'Every page', 'zinn-seo' ) },
						{
							value: 'bad',
							label: __( 'SEO: needs work', 'zinn-seo' ),
						},
						{
							value: 'ok',
							label: __( 'SEO: could be better', 'zinn-seo' ),
						},
						{ value: 'good', label: __( 'SEO: good', 'zinn-seo' ) },
						{
							value: 'none',
							label: __( 'Not analysed yet', 'zinn-seo' ),
						},
						{
							value: 'nofocus',
							label: __( 'No focus phrase', 'zinn-seo' ),
						},
						{
							value: 'cornerstone',
							label: __( 'Cornerstone content', 'zinn-seo' ),
						},
					] }
				/>
				{ types.length > 1 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Content type', 'zinn-seo' ) }
						value={ type }
						onChange={ setType }
						options={ [
							{
								value: '',
								label: __( 'Every type', 'zinn-seo' ),
							},
							...types,
						] }
					/>
				) }
				{ issue && (
					<p className="zseo-chip">
						{ names[ issue ] || issue }{ ' ' }
						<Button variant="link" onClick={ () => setIssue( '' ) }>
							{ __( 'Clear', 'zinn-seo' ) }
						</Button>
					</p>
				) }
				{ data.canManage && (
					<RunButton
						onError={ setError }
						disabled={ job && job.status === 'pending' }
						run={ () =>
							seoFetch( '/analysis/score-all', {
								method: 'POST',
							} ).then( setJob )
						}
					>
						{ __( 'Score every page', 'zinn-seo' ) }
					</RunButton>
				) }
			</div>
			<JobLine job={ job } />
			{ hidden > 0 && (
				<p className="zseo-muted zseo-hidden-types">
					{ sprintf(
						/* translators: %d: how many content types are not scored. */
						_n(
							'Hidden types: %d content type is not scored.',
							'Hidden types: %d content types are not scored.',
							hidden,
							'zinn-seo'
						),
						hidden
					) }{ ' ' }
					<a href={ `${ viewHref( 'appearance' ) }#/types` }>
						{ __( 'Choose which types are scored', 'zinn-seo' ) }
					</a>
				</p>
			) }
			<Table
				label={ __( 'Pages and their scores', 'zinn-seo' ) }
				empty={ loading ? '' : __( 'No page matches.', 'zinn-seo' ) }
				rows={ rows }
				columns={ [
					{
						id: 'title',
						label: __( 'Page', 'zinn-seo' ),
						render: ( r ) => (
							<span className="zseo-cell-title">
								<a href={ r.edit }>{ r.title }</a>
								{ r.cornerstone && (
									<span className="zseo-tag">
										{ __( 'Cornerstone', 'zinn-seo' ) }
									</span>
								) }
								<span className="zseo-muted">
									{ r.status === 'publish'
										? r.type
										: `${ r.type } · ${ r.status }` }
								</span>
							</span>
						),
					},
					{
						id: 'seo',
						label: __( 'SEO', 'zinn-seo' ),
						render: ( r ) => (
							<Pill
								score={ r.score }
								label={ __( 'SEO', 'zinn-seo' ) }
							/>
						),
					},
					{
						id: 'read',
						label: __( 'Readability', 'zinn-seo' ),
						render: ( r ) => (
							<Pill
								score={ r.readability }
								label={ __( 'Readability', 'zinn-seo' ) }
							/>
						),
					},
					{
						id: 'focus',
						label: __( 'Focus phrase', 'zinn-seo' ),
						render: ( r ) =>
							r.focus || <span className="zseo-muted">—</span>,
					},
					{
						id: 'links',
						label: __( 'Links in', 'zinn-seo' ),
						render: ( r ) =>
							r.inbound === null ? '–' : r.inbound,
					},
					{
						id: 'issues',
						label: __( 'To fix', 'zinn-seo' ),
						render: ( r ) =>
							r.issues.length ? (
								<span className="zseo-issues">
									{ r.issues
										.slice( 0, 3 )
										.map( ( i ) => names[ i ] || i )
										.join( ', ' ) }
									{ r.issues.length > 3
										? ` +${ r.issues.length - 3 }`
										: '' }
								</span>
							) : (
								<span className="zseo-muted">—</span>
							),
					},
				] }
			/>
			{ loading && <Spinner /> }
			{ next > 0 && ! loading && (
				<Button
					variant="secondary"
					onClick={ () => load( next, false ) }
				>
					{ __( 'Show more', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * The duplicates tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function Duplicates( { data } ) {
	const [ kind, setKind ] = useState( hashParams().get( 'kind' ) || 'title' );
	const [ groups, setGroups ] = useState( [] );
	const [ next, setNext ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const summary = useRoute( '/duplicates/summary' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/duplicates/summary' ).then( ( r ) => r.job ),
		() => {
			summary.reload();
			load( '', true );
		}
	);
	useEffect( () => {
		if ( summary.data ) {
			setJob( summary.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ summary.data ] );
	const load = ( after, replace ) =>
		seoFetch(
			`/duplicates?kind=${ kind }&after=${ encodeURIComponent( after ) }`
		)
			.then( ( r ) => {
				setGroups( replace ? r.groups : [ ...groups, ...r.groups ] );
				setNext( r.next );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		load( '', true );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind ] );
	const counts = ( summary.data && summary.data.counts ) || {};
	const labels = {
		title: __( 'SEO titles', 'zinn-seo' ),
		desc: __( 'Meta descriptions', 'zinn-seo' ),
		h1: __( 'Main headings', 'zinn-seo' ),
		focus: __( 'Focus phrases', 'zinn-seo' ),
	};
	return (
		<div className="zseo-stack">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'Pages that share a title, description or main heading look the same to search engines, which may show only one of them. Pages with the same focus phrase compete for the same searches.',
					'zinn-seo'
				) }
			</p>
			<div className="zseo-filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Duplicates of', 'zinn-seo' ) }
					value={ kind }
					onChange={ setKind }
					options={ Object.keys( labels ).map( ( k ) => ( {
						value: k,
						label:
							counts[ k ] !== undefined
								? `${ labels[ k ] } (${ counts[ k ] })`
								: labels[ k ],
					} ) ) }
				/>
				{ data.canManage && (
					<RunButton
						onError={ setError }
						disabled={ job && job.status === 'pending' }
						run={ () =>
							seoFetch( '/duplicates/scan', {
								method: 'POST',
							} ).then( setJob )
						}
					>
						{ __( 'Check the whole site again', 'zinn-seo' ) }
					</RunButton>
				) }
			</div>
			<JobLine job={ job } />
			{ groups.length === 0 && (
				<p className="zseo-ok">
					{ __( 'No duplicates found.', 'zinn-seo' ) }
				</p>
			) }
			<ol className="zseo-dups">
				{ groups.map( ( g ) => (
					<li key={ g.hash } className="zseo-dup">
						<p className="zseo-dup__value">
							{ sprintf(
								/* translators: 1: the shared text, 2: number of pages. */
								__( '"%1$s" — %2$d pages', 'zinn-seo' ),
								g.value,
								g.count
							) }
						</p>
						<ul>
							{ g.pages.map( ( p ) => (
								<li key={ p.id }>
									<a href={ p.edit }>{ p.title }</a>{ ' ' }
									<a
										className="zseo-muted"
										href={ p.view }
										target="_blank"
										rel="noreferrer"
									>
										{ __( 'View', 'zinn-seo' ) }
									</a>
								</li>
							) ) }
						</ul>
					</li>
				) ) }
			</ol>
			{ next && (
				<Button
					variant="secondary"
					onClick={ () => load( next, false ) }
				>
					{ __( 'Show more', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * The languages tab.
 *
 * @return {Element} Tab.
 */
function Languages() {
	const { data, error } = useRoute( '/analysis/languages' );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	const yes = __( 'Yes', 'zinn-seo' );
	const no = __( 'Not in this language', 'zinn-seo' );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					"Sentence and paragraph length, subheadings, sentence variety and every SEO check work in every language WordPress ships (in characters for languages written without spaces). Transition words, passive voice, reading ease and inclusive language need each language's own rules:",
					'zinn-seo'
				) }
			</p>
			<Table
				label={ __( 'What is measured in each language', 'zinn-seo' ) }
				empty=""
				rows={ data.languages.map( ( l ) => ( {
					...l,
					id: l.locale,
				} ) ) }
				columns={ [
					{
						id: 'locale',
						label: __( 'Language', 'zinn-seo' ),
						render: ( l ) => <code>{ l.locale }</code>,
					},
					{
						id: 'unit',
						label: __( 'Measured in', 'zinn-seo' ),
						render: ( l ) =>
							l.unit === 'char'
								? __( 'characters', 'zinn-seo' )
								: __( 'words', 'zinn-seo' ),
					},
					{
						id: 'transitions',
						label: __( 'Transition words', 'zinn-seo' ),
						render: ( l ) =>
							( {
								curated: yes,
								translation: __(
									'Yes (from the translation)',
									'zinn-seo'
								),
							} )[ l.transitions ] || no,
					},
					{
						id: 'passive',
						label: __( 'Passive voice', 'zinn-seo' ),
						render: ( l ) => ( l.passive ? yes : no ),
					},
					{
						id: 'ease',
						label: __( 'Reading ease', 'zinn-seo' ),
						render: ( l ) => ( l.ease ? yes : no ),
					},
					{
						id: 'inclusive',
						label: __( 'Inclusive language', 'zinn-seo' ),
						render: ( l ) => ( l.inclusive ? yes : no ),
					},
				] }
			/>
			<p className="zseo-muted">
				{ sprintf(
					/* translators: %s: language codes. */
					__(
						'Languages with their own word lists and rules: %s.',
						'zinn-seo'
					),
					data.curated.join( ', ' )
				) }
			</p>
		</div>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function ContentScreen( { data } ) {
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	return (
		<div className="zseo-admin">
			<TabPanel
				className="zseo-tabs"
				tabs={ contentTabs() }
				initialTabName={
					contentTabs().some( ( t ) => t.name === initial )
						? initial
						: 'scores'
				}
				onSelect={ ( name ) => {
					if (
						! ( window.location.hash || '' ).startsWith(
							`#/${ name }`
						)
					) {
						window.history.replaceState( null, '', `#/${ name }` );
					}
				} }
			>
				{ ( tab ) => {
					if ( 'scheduled' === tab.name ) {
						return <ScheduledTab data={ data } />;
					}
					if ( 'duplicates' === tab.name ) {
						return <Duplicates data={ data } />;
					}
					if ( 'languages' === tab.name ) {
						return <Languages />;
					}
					if ( 'search' === tab.name ) {
						return false ===
							( data.modules || {} )[ 'site-search' ] ? (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Site search insights are switched off (Settings → Modules).',
									'zinn-seo'
								) }
							</Notice>
						) : (
							<SiteSearchTab data={ data } />
						);
					}
					return <Scores data={ data } />;
				} }
			</TabPanel>
		</div>
	);
}
