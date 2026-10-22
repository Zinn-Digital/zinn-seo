/**
 * Links: the internal-link index, orphans and dead ends (zs-102), link attribute rules (zs-106),
 * breadcrumbs for themes (zs-105) and, in Pro, automatic links (zs-103), the broken link
 * checker (zs-104) and the outbound links report (zs-779).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import ProTeaser from '../ProTeaser';
import { JobLine, RunButton, Table, useJob, useRoute } from './shared';
import LinkUpdatesTab from '../x1a/LinkUpdatesTab';
import OutboundTab from '../out1/OutboundTab';
import GuideLink from '../p7/GuideLink';
import { viewHref } from '../../admin-kit/router';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function linksTabs() {
	return [
		{ name: 'overview', title: __( 'Overview', 'zinn-seo' ) },
		{ name: 'orphans', title: __( 'Orphans', 'zinn-seo' ) },
		{ name: 'deadends', title: __( 'Dead ends', 'zinn-seo' ) },
		{ name: 'rules', title: __( 'Link rules', 'zinn-seo' ) },
		{ name: 'autolinks', title: __( 'Automatic links', 'zinn-seo' ) },
		{ name: 'broken', title: __( 'Broken links', 'zinn-seo' ) },
		{ name: 'outbound', title: __( 'Outbound links', 'zinn-seo' ) },
		{ name: 'breadcrumbs', title: __( 'Breadcrumbs', 'zinn-seo' ) },
		...( false !==
		( ( window.zinnSeoAdmin || {} ).modules || {} )[ 'link-updates' ]
			? [ { name: 'updates', title: __( 'Link updates', 'zinn-seo' ) } ]
			: [] ),
	];
}

/**
 * Overview: totals and the index.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function Overview( { data } ) {
	const summary = useRoute( '/links/summary' );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/links/summary' ).then( ( r ) => r.job ),
		() => summary.reload()
	);
	useEffect( () => {
		if ( summary.data ) {
			setJob( summary.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ summary.data ] );
	const t = summary.data ? summary.data.totals : null;
	return (
		<div className="zseo-stack">
			{ ( error || summary.error ) && (
				<Notice status="error" isDismissible={ false }>
					{ error || summary.error }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'The index records which page links to which, read from your pages as visitors see them. It is built once in the background and then kept current every time a page is saved.',
					'zinn-seo'
				) }
			</p>
			{ ! t && <Spinner /> }
			{ t && t.built && (
				<dl className="zseo-totals">
					<div>
						<dt>{ __( 'Internal links', 'zinn-seo' ) }</dt>
						<dd>{ t.links }</dd>
					</div>
					<div>
						<dt>{ __( 'Orphans', 'zinn-seo' ) }</dt>
						<dd>{ t.orphans }</dd>
					</div>
					<div>
						<dt>{ __( 'Dead ends', 'zinn-seo' ) }</dt>
						<dd>{ t.deadends }</dd>
					</div>
				</dl>
			) }
			{ t && ! t.built && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'The index has not been built yet.', 'zinn-seo' ) }
				</Notice>
			) }
			{ data.canManage && (
				<p>
					<RunButton
						variant={ t && t.built ? 'secondary' : 'primary' }
						onError={ setError }
						disabled={ job && job.status === 'pending' }
						run={ () =>
							seoFetch( '/links/build', { method: 'POST' } ).then(
								setJob
							)
						}
					>
						{ t && t.built
							? __( 'Rebuild the index', 'zinn-seo' )
							: __( 'Build the index', 'zinn-seo' ) }
					</RunButton>
				</p>
			) }
			<JobLine job={ job } />
			{ data.linksPro && 'free' !== data.plan && (
				<MeaningIndex data={ data } />
			) }
		</div>
	);
}

/**
 * Pro: the meaning index for AI link suggestions (zs-101).
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function MeaningIndex( { data } ) {
	const status = useRoute( '/links/semantic' );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/links/semantic' ).then( ( r ) => r.job ),
		() => status.reload()
	);
	useEffect( () => {
		if ( status.data ) {
			setJob( status.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status.data ] );
	const st = status.data;
	return (
		<div className="zseo-stack zseo-meaning">
			<h3>{ __( 'Suggestions by meaning (AI)', 'zinn-seo' ) }</h3>
			<p className="zseo-muted">
				{ __(
					"With your own AI key, the editor can also suggest pages that are about the same thing in other words. Each page is summarised once into a small set of numbers stored with it; only the page's title and opening text go to your AI provider.",
					'zinn-seo'
				) }
			</p>
			{ ( error || status.error ) && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error || status.error }
				</Notice>
			) }
			{ ! st && <Spinner /> }
			{ st && ! st.available && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Connect an AI provider that can build a search index (Google Gemini, OpenAI, Mistral or a compatible service).',
						'zinn-seo'
					) }{ ' ' }
					<a href={ st.settings }>
						{ __( 'Open Settings → AI', 'zinn-seo' ) }
					</a>
				</Notice>
			) }
			{ st && st.available && (
				<>
					<p>
						{ sprintf(
							/* translators: 1: pages in the index, 2: the AI model. */
							__( '%1$d pages indexed with %2$s.', 'zinn-seo' ),
							st.stored,
							st.built || st.model
						) }
					</p>
					{ st.built && st.built !== st.model && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The AI model changed since the index was built: rebuild it.',
								'zinn-seo'
							) }
						</Notice>
					) }
					{ data.canManage && (
						<p>
							<RunButton
								onError={ setError }
								disabled={ job && job.status === 'pending' }
								run={ () =>
									seoFetch( '/links/semantic/build', {
										method: 'POST',
									} ).then( setJob )
								}
							>
								{ st.built
									? __(
											'Rebuild the meaning index',
											'zinn-seo'
										)
									: __(
											'Build the meaning index',
											'zinn-seo'
										) }
							</RunButton>
						</p>
					) }
					<JobLine job={ job } />
				</>
			) }
		</div>
	);
}

/**
 * Orphans or dead ends.
 *
 * @param {Object} props
 * @param {string} props.kind orphan|deadend.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function Report( { kind, data } ) {
	const [ rows, setRows ] = useState( null );
	const [ next, setNext ] = useState( 0 );
	const [ built, setBuilt ] = useState( true );
	const [ error, setError ] = useState( '' );
	const load = ( before ) =>
		seoFetch( `/links/report?kind=${ kind }&before=${ before }` )
			.then( ( r ) => {
				setBuilt( r.built );
				setRows( before ? [ ...( rows || [] ), ...r.items ] : r.items );
				setNext( r.next );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		load( 0 );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind ] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! rows ) {
		return <Spinner />;
	}
	if ( ! built ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __( 'Build the link index first (Overview).', 'zinn-seo' ) }
			</Notice>
		);
	}
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ 'orphan' === kind
					? __(
							'Published pages no other page links to. Search engines find and value pages through links: link to each from a related page (open it and use "Pages that could link here").',
							'zinn-seo'
						)
					: __(
							'Published pages that link to no other page of the site. Point readers to what to read next.',
							'zinn-seo'
						) }
			</p>
			{ 'orphan' === kind && <WholeSite data={ data } /> }
			<Table
				label={
					'orphan' === kind
						? __( 'Orphans', 'zinn-seo' )
						: __( 'Dead ends', 'zinn-seo' )
				}
				empty={ __( 'None. Good.', 'zinn-seo' ) }
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
							</span>
						),
					},
					{
						id: 'type',
						label: __( 'Type', 'zinn-seo' ),
						render: ( r ) => r.type,
					},
					{
						id: 'in',
						label: __( 'Links in', 'zinn-seo' ),
						render: ( r ) => r.inbound,
					},
					{
						id: 'out',
						label: __( 'Links out', 'zinn-seo' ),
						render: ( r ) => r.outbound,
					},
					{
						id: 'view',
						label: __( 'View', 'zinn-seo' ),
						render: ( r ) => (
							<a href={ r.view } target="_blank" rel="noreferrer">
								{ __( 'View', 'zinn-seo' ) }
							</a>
						),
					},
				] }
			/>
			{ next > 0 && (
				<Button variant="secondary" onClick={ () => load( next ) }>
					{ __( 'Show more', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * "Do it for the whole site": on Free, the way to the Pro link plan; on Pro, the way to it.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Notice.
 */
export function WholeSite( { data } ) {
	const onPro = data && 'free' !== data.plan;
	const pro = onPro && data.linkPlan;
	if ( onPro && ! pro ) {
		// Pro with the link plan switched off: nothing to sell, nothing to open.
		return null;
	}
	return (
		<Notice status="info" isDismissible={ false }>
			{ pro
				? __(
						'Link every one of these in one go: the link plan finds the right page and the right words for each, you approve, and every change can be undone.',
						'zinn-seo'
					)
				: __(
						'Do it for the whole site with Pro: the AI link plan links every orphan, gives each page the right number of links with natural, varied words, and applies it all with one-click undo.',
						'zinn-seo'
					) }{ ' ' }
			{ pro ? (
				<a href={ viewHref( 'linkplan' ) }>
					{ __( 'Open the link plan', 'zinn-seo' ) }
				</a>
			) : (
				<a href={ viewHref( 'plans' ) }>
					{ __( 'See the Pro plans', 'zinn-seo' ) }
				</a>
			) }
		</Notice>
	);
}

/**
 * Link attribute rules (zs-106).
 *
 * @return {Element} Tab.
 */
function Rules() {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! settings ) {
		return <Spinner />;
	}
	const v = { ...settings, ...draft };
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
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Every link to another site', 'zinn-seo' ) }
				value={ v.links_external_rel || '' }
				onChange={ ( x ) =>
					setDraft( { ...draft, links_external_rel: x } )
				}
				options={ [
					{ value: '', label: __( 'Leave as written', 'zinn-seo' ) },
					{
						value: 'nofollow',
						label: __( 'Add nofollow', 'zinn-seo' ),
					},
					{
						value: 'sponsored',
						label: __( 'Add sponsored', 'zinn-seo' ),
					},
				] }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Open links to other sites in a new tab',
					'zinn-seo'
				) }
				checked={ !! v.links_external_newtab }
				onChange={ ( x ) =>
					setDraft( { ...draft, links_external_newtab: x } )
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'Rules for particular sites and paths',
					'zinn-seo'
				) }
				help={ __(
					'One rule per line: a domain or a /path/ of this site, then what to add — nofollow, sponsored, ugc, newtab — or follow to leave that site alone. Examples: "amazon.com sponsored nofollow newtab", "/go/ sponsored" (a cloaked affiliate path counts as a link out), "partner.example follow".',
					'zinn-seo'
				) }
				rows={ 6 }
				value={ v.links_rules || '' }
				onChange={ ( x ) => setDraft( { ...draft, links_rules: x } ) }
			/>
			<p className="zseo-muted">
				{ __(
					'Applied to the content of every page as it is shown; your posts are not changed.',
					'zinn-seo'
				) }
			</p>
			<p className="zak-actions">
				<RunButton
					variant="primary"
					disabled={ ! Object.keys( draft ).length }
					onError={ ( text ) =>
						setNotice( { status: 'error', text } )
					}
					run={ () =>
						save( draft ).then( () => {
							setDraft( {} );
							setNotice( {
								status: 'success',
								text: __( 'Saved.', 'zinn-seo' ),
							} );
						} )
					}
				>
					{ __( 'Save changes', 'zinn-seo' ) }
				</RunButton>
			</p>
		</div>
	);
}

/**
 * Pro: automatic links (zs-103).
 *
 * @return {Element} Tab.
 */
function Autolinks() {
	const [ rules, setRules ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( false );
	useEffect( () => {
		seoFetch( '/links/autolinks' )
			.then( ( r ) => setRules( r.rules ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	if ( error && ! rules ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! rules ) {
		return <Spinner />;
	}
	const set = ( i, part ) => {
		setSaved( false );
		setRules(
			rules.map( ( r, j ) => ( j === i ? { ...r, ...part } : r ) )
		);
	};
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'A phrase becomes a link wherever it appears in your content, once per page unless you say otherwise, never inside a heading or another link, never to the page itself. Applied as pages are shown; your posts are not changed.',
					'zinn-seo'
				) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ rules.map( ( r, i ) => (
				<fieldset key={ r.id || i } className="zseo-autolink">
					<legend className="screen-reader-text">
						{ sprintf(
							/* translators: %d: rule number. */
							__( 'Rule %d', 'zinn-seo' ),
							i + 1
						) }
					</legend>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Phrases (separate with commas)',
							'zinn-seo'
						) }
						value={
							Array.isArray( r.phrases )
								? r.phrases.join( ', ' )
								: r.phrases || ''
						}
						onChange={ ( x ) => set( i, { phrases: x } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="url"
						label={ __(
							'Link to (an address, or leave empty and give a page ID)',
							'zinn-seo'
						) }
						value={ r.post ? '' : r.url || '' }
						onChange={ ( x ) => set( i, { url: x, post: 0 } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __(
							'Or a page of this site (its ID)',
							'zinn-seo'
						) }
						help={ r.targetTitle ? r.targetTitle : undefined }
						value={ r.post || '' }
						onChange={ ( x ) =>
							set( i, { post: Number( x ) || 0 } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						max={ 10 }
						label={ __( 'Times per page', 'zinn-seo' ) }
						value={ r.max || 1 }
						onChange={ ( x ) =>
							set( i, { max: Number( x ) || 1 } )
						}
					/>
					<div className="zseo-checks-row">
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'nofollow', 'zinn-seo' ) }
							checked={ !! r.nofollow }
							onChange={ ( x ) => set( i, { nofollow: x } ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'sponsored', 'zinn-seo' ) }
							checked={ !! r.sponsored }
							onChange={ ( x ) => set( i, { sponsored: x } ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'New tab', 'zinn-seo' ) }
							checked={ !! r.newtab }
							onChange={ ( x ) => set( i, { newtab: x } ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __( 'Match case', 'zinn-seo' ) }
							checked={ !! r.case }
							onChange={ ( x ) => set( i, { case: x } ) }
						/>
					</div>
					<Button
						variant="link"
						isDestructive
						onClick={ () =>
							setRules( rules.filter( ( x, j ) => j !== i ) )
						}
					>
						{ __( 'Remove this rule', 'zinn-seo' ) }
					</Button>
				</fieldset>
			) ) }
			<p className="zak-actions">
				<Button
					variant="secondary"
					onClick={ () =>
						setRules( [
							...rules,
							{ phrases: '', url: '', post: 0, max: 1 },
						] )
					}
				>
					{ __( 'Add a rule', 'zinn-seo' ) }
				</Button>
				<RunButton
					variant="primary"
					onError={ setError }
					run={ () =>
						seoFetch( '/links/autolinks', {
							method: 'PUT',
							data: { rules },
						} ).then( ( r ) => {
							setRules( r.rules );
							setSaved( true );
						} )
					}
				>
					{ __( 'Save rules', 'zinn-seo' ) }
				</RunButton>
				{ saved && (
					<span className="zseo-ok">
						{ __( 'Saved.', 'zinn-seo' ) }
					</span>
				) }
			</p>
		</div>
	);
}

/**
 * "Fix every internal link in one click" (Pro, zs-778): one sweep of every page for links that
 * hit a redirect, a missing, trashed, unpublished or hidden page, or the wrong language, run as
 * Fix it on autopilot's link fix (its review, apply and one-click undo).
 *
 * @param {Object}   props
 * @param {Object}   props.data    Boot data.
 * @param {Function} props.onError Error.
 * @return {Element|null} Notice with the button.
 */
export function FixEveryLink( { data, onError } ) {
	if ( ! data || ! data.canManage || ! data.autopilot ) {
		return null;
	}
	return (
		<Notice status="info" isDismissible={ false }>
			{ __(
				'Fix every internal link in one click: links that hop through a redirect go straight to the live page, links to the wrong language go to the same page in the right one, and for a page that is gone the AI proposes the best replacement. You review it all first, each page changed keeps a revision, and the whole run undoes in one click.',
				'zinn-seo'
			) }{ ' ' }
			<GuideLink data={ data } id="linkfix" />{ ' ' }
			<RunButton
				variant="secondary"
				onError={ onError }
				run={ () =>
					seoFetch( '/autopilot', {
						method: 'POST',
						data: { scope: { remedies: [ 'links-fix' ] } },
					} ).then( ( r ) => {
						window.location.href = `${ viewHref(
							'autopilot'
						) }#/run/${ r.run }`;
					} )
				}
			>
				{ __( 'Fix every internal link', 'zinn-seo' ) }
			</RunButton>
		</Notice>
	);
}

/**
 * Pro: broken links (zs-104).
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function Broken( { data } ) {
	const [ verdict, setVerdict ] = useState( 'broken' );
	const list = useRoute( `/links/broken?verdict=${ verdict }` );
	const { settings, save } = useSettings();
	const [ error, setError ] = useState( '' );
	const [ replace, setReplace ] = useState( {} );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/links/broken' ).then( ( r ) => r.job ),
		() => list.reload()
	);
	useEffect( () => {
		if ( list.data ) {
			setJob( list.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ list.data ] );
	const act = ( hash, action, url = '' ) =>
		seoFetch( `/links/broken/${ hash }`, {
			method: 'POST',
			data: { action, url },
		} ).then( () => list.reload() );
	const items = list.data ? list.data.items : null;
	const run = list.data && list.data.run;
	return (
		<div className="zseo-stack">
			{ ( error || list.error ) && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error || list.error }
				</Notice>
			) }
			<div className="zseo-filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Show', 'zinn-seo' ) }
					value={ verdict }
					onChange={ setVerdict }
					options={ [
						{ value: 'broken', label: __( 'Broken', 'zinn-seo' ) },
						{
							value: 'blocked',
							label: __(
								'Refused to the checker (check by hand)',
								'zinn-seo'
							),
						},
					] }
				/>
				{ data.canManage && (
					<RunButton
						onError={ setError }
						disabled={ job && job.status === 'pending' }
						run={ () =>
							seoFetch( '/links/broken/check', {
								method: 'POST',
							} ).then( setJob )
						}
					>
						{ __( 'Check every link now', 'zinn-seo' ) }
					</RunButton>
				) }
			</div>
			<JobLine job={ job } />
			<FixEveryLink data={ data } onError={ setError } />
			{ run && run.at && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: 1: date, 2: pages, 3: addresses. */
						__(
							'Last check %1$s: %2$d pages, %3$d addresses.',
							'zinn-seo'
						),
						new Date( run.at * 1000 ).toLocaleString(),
						// A check still running (or cut short) has not counted yet.
						run.pages || 0,
						run.checked || 0
					) }
				</p>
			) }
			{ ! items && <Spinner /> }
			{ items && items.length === 0 && (
				<p className="zseo-ok">
					{ __( 'No broken link found.', 'zinn-seo' ) }
				</p>
			) }
			<ul className="zseo-broken">
				{ ( items || [] ).map( ( b ) => (
					<li key={ b.hash } className="zseo-broken__item">
						<p className="zseo-broken__url">
							<span dir="ltr">{ b.url }</span>
							<span className="zseo-tag">
								{ b.status
									? sprintf(
											/* translators: %d: HTTP status. */ __(
												'Status %d',
												'zinn-seo'
											),
											b.status
										)
									: __( 'No answer', 'zinn-seo' ) }
							</span>
							{ b.kind === 'image' && (
								<span className="zseo-tag">
									{ __( 'Image', 'zinn-seo' ) }
								</span>
							) }
						</p>
						<p className="zseo-muted">
							{ sprintf(
								/* translators: %d: number of pages. */
								_n(
									'On %d page:',
									'On %d pages:',
									b.count,
									'zinn-seo'
								),
								b.count
							) }{ ' ' }
							{ b.pages.map( ( p, i ) => (
								<span key={ p.id }>
									{ i > 0 ? ', ' : '' }
									<a href={ p.edit }>
										{ p.title || `#${ p.id }` }
									</a>
								</span>
							) ) }
						</p>
						{ data.canManage && (
							<div className="zseo-broken__do">
								<RunButton
									onError={ setError }
									run={ () => act( b.hash, 'recheck' ) }
								>
									{ __( 'Check again', 'zinn-seo' ) }
								</RunButton>
								<RunButton
									onError={ setError }
									run={ () => act( b.hash, 'unlink' ) }
								>
									{ __(
										'Unlink (keep the text)',
										'zinn-seo'
									) }
								</RunButton>
								<TextControl
									__nextHasNoMarginBottom
									type="url"
									label={ __( 'Replace with', 'zinn-seo' ) }
									value={ replace[ b.hash ] || '' }
									onChange={ ( x ) =>
										setReplace( {
											...replace,
											[ b.hash ]: x,
										} )
									}
								/>
								<RunButton
									disabled={ ! replace[ b.hash ] }
									onError={ setError }
									run={ () =>
										act(
											b.hash,
											'replace',
											replace[ b.hash ]
										)
									}
								>
									{ __( 'Replace', 'zinn-seo' ) }
								</RunButton>
								<RunButton
									variant="tertiary"
									onError={ setError }
									run={ () => act( b.hash, 'ignore' ) }
								>
									{ __( 'Ignore', 'zinn-seo' ) }
								</RunButton>
							</div>
						) }
					</li>
				) ) }
			</ul>
			{ settings && data.canManage && (
				<div className="zseo-stack">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Check every link once a week',
							'zinn-seo'
						) }
						checked={ !! settings.broken_weekly }
						onChange={ ( x ) =>
							save( { broken_weekly: x } ).catch( ( e ) =>
								setError( errorText( e ) )
							)
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'E-mail the site administrator when new broken links are found',
							'zinn-seo'
						) }
						checked={ !! settings.broken_email }
						onChange={ ( x ) =>
							save( { broken_email: x } ).catch( ( e ) =>
								setError( errorText( e ) )
							)
						}
					/>
				</div>
			) }
		</div>
	);
}

/**
 * Breadcrumbs for themes.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data (the guide links).
 *
 * @return {Element} Tab.
 */
function Breadcrumbs( { data } ) {
	return (
		<div className="zseo-stack">
			<BreadcrumbHelp />
			<GuideLink data={ data } id="breadcrumbs" />
			<BreadcrumbSettings />
		</div>
	);
}

/**
 * The plugins whose breadcrumb functions, shortcodes and blocks Zinn SEO can answer for.
 *
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function crumbCompatChoices() {
	return [
		{ value: 'yoast', label: 'Yoast SEO' },
		{ value: 'rankmath', label: 'Rank Math' },
		{ value: 'aioseo', label: 'All in One SEO' },
		{ value: 'seopress', label: 'SEOPress' },
		{ value: 'tsf', label: 'The SEO Framework' },
		{ value: 'slimseo', label: 'Slim SEO' },
		{ value: 'smartcrawl', label: 'SmartCrawl' },
	];
}

/**
 * `crumb_types` as text, one content type per line: "type | name of its archive | taxonomy".
 *
 * @param {Object} types Setting value.
 * @return {string} Text.
 */
export function crumbTypesText( types ) {
	return Object.keys( types || {} )
		.map( ( type ) =>
			[ type, types[ type ].label || '', types[ type ].tax || '' ]
				.join( ' | ' )
				.replace( /( \| )+$/, '' )
		)
		.join( '\n' );
}

/**
 * The text back to `crumb_types`.
 *
 * @param {string} text Text.
 * @return {Object} Setting value.
 */
export function crumbTypesValue( text ) {
	const out = {};
	( text || '' ).split( '\n' ).forEach( ( line ) => {
		const [ type = '', label = '', tax = '' ] = line
			.split( '|' )
			.map( ( part ) => part.trim() );
		if ( /^[a-z0-9_-]+$/.test( type ) ) {
			out[ type ] = {};
			if ( label ) {
				out[ type ].label = label;
			}
			if ( tax ) {
				out[ type ].tax = tax;
			}
		}
	} );
	return out;
}

/**
 * One field of one content type's breadcrumb rule, set (or removed when empty); a type left with
 * no rule is dropped, and every other type's rule — one an import wrote for a type this site
 * no longer has included — is kept as it is (zs-758).
 *
 * @param {Object} types Setting value (`crumb_types`).
 * @param {string} type  Content type.
 * @param {string} field `label`, `tax` or `archive`.
 * @param {string} value New value ('' to remove).
 * @return {Object} Setting value.
 */
export function setCrumbType( types, type, field, value ) {
	const out = { ...( types || {} ) };
	const record = { ...( out[ type ] || {} ) };
	if ( value ) {
		record[ field ] = value;
	} else {
		delete record[ field ];
	}
	if ( Object.keys( record ).length ) {
		out[ type ] = record;
	} else {
		delete out[ type ];
	}
	return out;
}

/**
 * Per content type: the name of its archive, the taxonomy its items' trail runs through and
 * whether that trail shows the archive (zs-758).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.value    `crumb_types`.
 * @param {Function} props.onChange New value.
 * @return {Element} Rows.
 */
function CrumbTypes( { value, onChange } ) {
	// A type with nothing to choose (pages: no categories, no archive) gets no row.
	const types = (
		( window.zinnSeoAdmin && window.zinnSeoAdmin.crumbTypes ) ||
		[]
	).filter( ( t ) => ! t.hierarchical || t.archive );
	if ( ! types.length ) {
		return (
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Per content type', 'zinn-seo' ) }
				help={ __(
					'One line per content type: its name, the name of its archive in the trail, and the taxonomy its items run through ("none" for none). For example "product | Shop | none".',
					'zinn-seo'
				) }
				rows={ 4 }
				value={ crumbTypesText( value ) }
				onChange={ ( x ) => onChange( crumbTypesValue( x ) ) }
			/>
		);
	}
	const set = ( type, field ) => ( x ) =>
		onChange( setCrumbType( value, type, field, x ) );
	return (
		<fieldset className="zseo-stack">
			<legend>{ __( 'Per content type', 'zinn-seo' ) }</legend>
			<p className="zseo-muted">
				{ __(
					'Choose which categories a product or post’s trail runs through, and whether it shows the shop or archive page.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink
					data={ window.zinnSeoAdmin || {} }
					id="crumb-types"
				/>
			</p>
			{ types.map( ( t ) => {
				const own = ( value || {} )[ t.name ] || {};
				return (
					<div
						key={ t.name }
						className="zseo-card-row"
						data-crumb-type={ t.name }
					>
						<strong className="zseo-legend">{ t.label }</strong>
						<div className="zseo-grid-2">
							{ ! t.hierarchical && (
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Categories shown in the trail',
										'zinn-seo'
									) }
									value={ own.tax || '' }
									onChange={ set( t.name, 'tax' ) }
									options={ [
										{
											value: '',
											label: __(
												'Its main category (automatic)',
												'zinn-seo'
											),
										},
										...t.taxonomies.map( ( x ) => ( {
											value: x.name,
											label: x.label,
										} ) ),
										{
											value: 'none',
											label: __(
												'No categories',
												'zinn-seo'
											),
										},
									] }
								/>
							) }
							{ t.archive && (
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Its archive page',
										'zinn-seo'
									) }
									value={ own.archive || '' }
									onChange={ set( t.name, 'archive' ) }
									options={ [
										{
											value: '',
											label: __(
												'As the switch above',
												'zinn-seo'
											),
										},
										{
											value: 'show',
											label: __(
												'Show it in the trail',
												'zinn-seo'
											),
										},
										{
											value: 'hide',
											label: __(
												'Leave it out of the trail',
												'zinn-seo'
											),
										},
									] }
								/>
							) }
							{ t.archive && (
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Name of its archive in the trail',
										'zinn-seo'
									) }
									value={ own.label || '' }
									onChange={ set( t.name, 'label' ) }
								/>
							) }
						</div>
					</div>
				);
			} ) }
		</fieldset>
	);
}

/**
 * The trail's words and rules (brought over from the SEO plugin a site moved from, queue Q254).
 *
 * @return {Element} Settings.
 */
function BreadcrumbSettings() {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! settings ) {
		return <Spinner />;
	}
	const v = { ...settings, ...draft };
	const set = ( key ) => ( x ) => setDraft( { ...draft, [ key ]: x } );
	const text = ( key, label, help ) => (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ v[ key ] || '' }
			onChange={ set( key ) }
		/>
	);
	/* translators: %s: typed as it is by the site owner, it is not replaced here. */
	const slot = __(
		'%s stands for the name (or the search words, or the page number).',
		'zinn-seo'
	);
	const compat = Array.isArray( v.crumb_compat ) ? v.crumb_compat : [];
	return (
		<div className="zseo-stack">
			<h3>{ __( 'Trail wording', 'zinn-seo' ) }</h3>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'Leave a box empty for the plain wording. A move from another SEO plugin fills these with the wording it used.',
					'zinn-seo'
				) }
			</p>
			{ text( 'crumb_home', __( 'Name of the home page', 'zinn-seo' ) ) }
			{ text( 'crumb_sep', __( 'Separator', 'zinn-seo' ) ) }
			{ text(
				'crumb_prefix',
				__( 'Words before the trail', 'zinn-seo' ),
				__( 'For example "You are here:".', 'zinn-seo' )
			) }
			{ text(
				'crumb_archive',
				__( 'An author or date archive', 'zinn-seo' ),
				slot
			) }
			{ text(
				'crumb_term',
				__( 'A category or tag', 'zinn-seo' ),
				slot
			) }
			{ text(
				'crumb_ptarchive',
				__( "A content type's archive", 'zinn-seo' ),
				slot
			) }
			{ text( 'crumb_search', __( 'Search results', 'zinn-seo' ), slot ) }
			{ text(
				'crumb_404',
				__( 'A page that is not found', 'zinn-seo' )
			) }
			{ text(
				'crumb_paged',
				__( 'Page 2 and on of a list', 'zinn-seo' ),
				/* translators: %s: typed as it is by the site owner, it is not replaced here. */
				__(
					'For example "Page %s". Empty: no step for the page number.',
					'zinn-seo'
				)
			) }
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Date archives', 'zinn-seo' ) }
				value={ v.crumb_date || '' }
				onChange={ set( 'crumb_date' ) }
				options={ [
					{
						value: '',
						label: __( 'Their archive title', 'zinn-seo' ),
					},
					{
						value: 'tree',
						label: __(
							'Year › month › day (2026 › March › 01)',
							'zinn-seo'
						),
					},
					{
						value: 'tree-numbers',
						label: __(
							'Year › month › day in numbers (2026 › 03 › 01)',
							'zinn-seo'
						),
					},
					{
						value: 'tree-archive',
						label: __(
							'Year › month › day, each in the archive wording (Archives for 2026 › Archives for March)',
							'zinn-seo'
						),
					},
				] }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Show a category’s parent categories',
					'zinn-seo'
				) }
				help={ __(
					'On: Home › News › Local. Off: Home › Local.',
					'zinn-seo'
				) }
				checked={ false !== v.crumb_ancestors }
				onChange={ set( 'crumb_ancestors' ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __(
					'Taxonomies whose name is a step of its own',
					'zinn-seo'
				) }
				help={ __(
					'Their names, separated by commas, for example "genre": Home › Genres › Mystery.',
					'zinn-seo'
				) }
				value={ ( v.crumb_tax_name || [] ).join( ', ' ) }
				onChange={ ( x ) =>
					setDraft( {
						...draft,
						crumb_tax_name: x
							.split( ',' )
							.map( ( t ) => t.trim() )
							.filter( Boolean ),
					} )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __(
					'Taxonomies whose trail runs through their content type’s archive',
					'zinn-seo'
				) }
				help={ __(
					'Their names, separated by commas, for example "genre, product_cat": Home › Books › Mystery.',
					'zinn-seo'
				) }
				value={ ( v.crumb_tax_archive || [] ).join( ', ' ) }
				onChange={ ( x ) =>
					setDraft( {
						...draft,
						crumb_tax_archive: x
							.split( ',' )
							.map( ( t ) => t.trim() )
							.filter( Boolean ),
					} )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Show a content type’s archive in its items’ trail',
					'zinn-seo'
				) }
				help={ __(
					'On: Home › Books › The River Book. Off: Home › Mystery › The River Book.',
					'zinn-seo'
				) }
				checked={ false !== v.crumb_singular_archive }
				onChange={ set( 'crumb_singular_archive' ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __(
					'Taxonomies whose terms keep their plain name',
					'zinn-seo'
				) }
				help={ __(
					'Their names, separated by commas, for example "product_cat, product_tag": "Mugs", not the category or tag wording above.',
					'zinn-seo'
				) }
				value={ ( v.crumb_term_plain || [] ).join( ', ' ) }
				onChange={ ( x ) =>
					setDraft( {
						...draft,
						crumb_term_plain: x
							.split( ',' )
							.map( ( t ) => t.trim() )
							.filter( Boolean ),
					} )
				}
			/>
			<CrumbTypes
				value={ v.crumb_types }
				onChange={ ( x ) => setDraft( { ...draft, crumb_types: x } ) }
			/>
			<fieldset>
				<legend>
					{ __(
						'Answer the breadcrumb functions of (Pro)',
						'zinn-seo'
					) }
				</legend>
				<p className="zseo-muted">
					{ __(
						"A theme that calls another SEO plugin's breadcrumb function, shortcode or block keeps showing a trail, Zinn® SEO's, after that plugin is switched off. Only while that plugin is not active.",
						'zinn-seo'
					) }
				</p>
				{ crumbCompatChoices().map( ( choice ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ choice.value }
						label={ choice.label }
						checked={ compat.includes( choice.value ) }
						onChange={ ( on ) =>
							setDraft( {
								...draft,
								crumb_compat: on
									? [ ...compat, choice.value ]
									: compat.filter(
											( x ) => x !== choice.value
										),
							} )
						}
					/>
				) ) }
			</fieldset>
			<p className="zak-actions">
				<RunButton
					variant="primary"
					disabled={ ! Object.keys( draft ).length }
					onError={ ( t ) =>
						setNotice( { status: 'error', text: t } )
					}
					run={ () =>
						save( draft ).then( () => {
							setDraft( {} );
							setNotice( {
								status: 'success',
								text: __( 'Saved.', 'zinn-seo' ),
							} );
						} )
					}
				>
					{ __( 'Save changes', 'zinn-seo' ) }
				</RunButton>
			</p>
		</div>
	);
}

/**
 * How to add the trail to a theme.
 *
 * @return {Element} Help.
 */
function BreadcrumbHelp() {
	return (
		<div className="zseo-stack zseo-help">
			<p>
				{ __(
					"The trail visitors see is the same one search engines read in each page's structured data, in every language with Tranzly. Add it with the Breadcrumbs block (block editor, Page Builder Sandwich, Elementor), or in a theme:",
					'zinn-seo'
				) }
			</p>
			<p>
				<strong>{ __( 'In a template file', 'zinn-seo' ) }</strong>
			</p>
			<pre dir="ltr">
				<code>
					{
						"<?php if ( function_exists( 'zinn_seo_breadcrumbs' ) ) { zinn_seo_breadcrumbs(); } ?>"
					}
				</code>
			</pre>
			<p>
				<strong>
					{ __( 'Anywhere shortcodes work', 'zinn-seo' ) }
				</strong>
			</p>
			<pre dir="ltr">
				<code>
					{
						'[zinn_seo_breadcrumbs home="Home" separator="›" show_last="1"]'
					}
				</code>
			</pre>
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
export default function LinksScreen( { data } ) {
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	const isPro = 'free' !== data.plan;
	return (
		<div className="zseo-admin">
			<TabPanel
				className="zseo-tabs"
				tabs={ linksTabs() }
				initialTabName={
					linksTabs().some( ( t ) => t.name === initial )
						? initial
						: 'overview'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'orphans':
							return <Report kind="orphan" data={ data } />;
						case 'deadends':
							return <Report kind="deadend" data={ data } />;
						case 'rules':
							return <Rules />;
						case 'breadcrumbs':
							return <Breadcrumbs data={ data } />;
						case 'updates':
							return <LinkUpdatesTab data={ data } />;
						case 'outbound':
							if ( ! isPro || ! data.outbound ) {
								return (
									<ProTeaser
										title={ __(
											'Outbound links report',
											'zinn-seo'
										) }
										points={ [
											__(
												'See every site your pages link to, with the number of links, the pages, the words and whether each link is followed, nofollow, sponsored or ugc.',
												'zinn-seo'
											),
											__(
												'Find a site, or filter by status, link type and flag: live, redirecting or broken sites, and ones that look like spam or an expired domain.',
												'zinn-seo'
											),
											__(
												'Change or remove one link where it is, or every link to a site at once: you review it first, each page keeps a revision, and a whole change undoes in one click.',
												'zinn-seo'
											),
										] }
									/>
								);
							}
							return <OutboundTab data={ data } />;
						case 'autolinks':
						case 'broken':
							if ( ! isPro || ! data.linksPro ) {
								return (
									<ProTeaser
										title={ __(
											'Automatic links and the broken link checker',
											'zinn-seo'
										) }
										points={ [
											__(
												'Turn phrases into links on every page they appear, with nofollow, sponsored and new-tab options, never in headings or existing links.',
												'zinn-seo'
											),
											__(
												'Check every link and image of the site in the background, weekly if you like, with an e-mail when something breaks.',
												'zinn-seo'
											),
											__(
												'Fix a broken link everywhere it is used in one click: unlink it, replace it or ignore it.',
												'zinn-seo'
											),
											__(
												'See every link suggestion, and the pages that should link to the one you are writing, with "Add link".',
												'zinn-seo'
											),
										] }
									/>
								);
							}
							return 'broken' === tab.name ? (
								<Broken data={ data } />
							) : (
								<Autolinks />
							);
						default:
							return <Overview data={ data } />;
					}
				} }
			</TabPanel>
		</div>
	);
}
