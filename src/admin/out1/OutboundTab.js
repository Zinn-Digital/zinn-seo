/**
 * Links › Outbound links (Pro, zs-779): every site the pages link to, with the links, the pages,
 * the words and rel; filter and search; change or remove one link in place, or every link to a
 * site at once, as a Fix it on autopilot run (review, one revision a page, undo in one click).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { JobLine, RunButton, useJob } from '../p4/shared';
import GuideLink from '../p7/GuideLink';
import { viewHref } from '../../admin-kit/router';
import './out1.scss';

/** The rel words a person chooses from. */
export const RELS = [ 'nofollow', 'sponsored', 'ugc' ];

/**
 * A linked site's status in plain words (pure).
 *
 * @param {string} status Status ('' = not checked).
 * @return {string} Words.
 */
export function statusText( status ) {
	switch ( status ) {
		case 'live':
			return __( 'Live', 'zinn-seo' );
		case 'redirect':
			return __( 'Redirects elsewhere', 'zinn-seo' );
		case 'broken':
			return __( 'Broken', 'zinn-seo' );
		case 'blocked':
			return __( 'Refuses checks', 'zinn-seo' );
		default:
			return __( 'Not checked yet', 'zinn-seo' );
	}
}

/**
 * A flag in plain words (pure).
 *
 * @param {string} flag `spam` or `expired`.
 * @return {string} Words.
 */
export function flagText( flag ) {
	return flag === 'spam'
		? __( 'Looks like spam', 'zinn-seo' )
		: __( 'Expired or parked domain', 'zinn-seo' );
}

/**
 * A link's rel in plain words (pure).
 *
 * @param {Array<string>} rel Rel words.
 * @return {string} Words.
 */
export function relText( rel ) {
	return rel && rel.length ? rel.join( ' ' ) : __( 'followed', 'zinn-seo' );
}

/**
 * The query string of the sites list (pure).
 *
 * @param {Object} f     Filters `{search, status, rel, flag, sort}`.
 * @param {string} after Cursor.
 * @return {string} Path.
 */
export function sitesPath( f, after = '' ) {
	const q = new URLSearchParams();
	[ 'search', 'status', 'rel', 'flag', 'sort' ].forEach( ( k ) => {
		if ( f[ k ] ) {
			q.set( k, f[ k ] );
		}
	} );
	if ( after ) {
		q.set( 'after', after );
	}
	const s = q.toString();
	return `/links/outbound${ s ? `?${ s }` : '' }`;
}

/**
 * Why a link cannot be changed from here, in plain words (pure).
 *
 * @param {string} reason Reason code.
 * @return {string} Words ('' none).
 */
export function reasonText( reason ) {
	switch ( reason ) {
		case 'elsewhere':
			return __(
				'This link is not in the page’s own text: a block, a menu, a widget or the theme adds it. Change it there.',
				'zinn-seo'
			);
		case 'same':
			return __( 'The link already is like that.', 'zinn-seo' );
		case 'locked':
			return __(
				'This page has the SEO lock on, so nothing changes it automatically.',
				'zinn-seo'
			);
		case 'excluded':
			return __(
				'This content type is switched out of the SEO tools under Content types.',
				'zinn-seo'
			);
		case 'kept':
			return __( 'Someone chose “keep mine” for this page.', 'zinn-seo' );
		case 'gone':
			return __( 'The page no longer exists.', 'zinn-seo' );
		default:
			return '';
	}
}

/**
 * The address of an autopilot run's review.
 *
 * @param {number} run Run.
 * @return {string} Address.
 */
function runHref( run ) {
	return `${ viewHref( 'autopilot' ) }#/run/${ run }`;
}

/**
 * Change one link: what to do, a preview of the page's link before and after, then apply.
 *
 * @param {Object}   props
 * @param {string}   props.host    Site.
 * @param {Object}   props.link    Link `{post, url, rel, anchor}`.
 * @param {Function} props.onDone  After a change.
 * @param {Function} props.onClose Close.
 * @return {Element} Editor.
 */
function LinkEditor( { host, link, onDone, onClose } ) {
	const [ action, setAction ] = useState( 'rel' );
	const [ rel, setRel ] = useState( link.rel || [] );
	const [ to, setTo ] = useState( link.url );
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( null );
	const target = {
		host,
		url: link.url,
		post: link.post,
		action,
		rel,
		to: action === 'url' ? to : '',
	};
	const relKey = rel.join( ' ' );
	useEffect( () => setPreview( null ), [ action, relKey, to ] );
	return (
		<div
			className="zseo-out__editor"
			role="group"
			aria-label={ __( 'Change this link', 'zinn-seo' ) }
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<RadioControl
				label={ __( 'What to do', 'zinn-seo' ) }
				selected={ action }
				onChange={ setAction }
				options={ [
					{
						value: 'rel',
						label: __(
							'Change the link type (followed, nofollow, sponsored, ugc)',
							'zinn-seo'
						),
					},
					{
						value: 'url',
						label: __( 'Change the address', 'zinn-seo' ),
					},
					{
						value: 'remove',
						label: __(
							'Remove the link (the words stay)',
							'zinn-seo'
						),
					},
				] }
			/>
			{ action === 'rel' && (
				<fieldset className="zseo-out__rels">
					<legend>
						{ __(
							'Link type (none ticked: a followed link)',
							'zinn-seo'
						) }
					</legend>
					{ RELS.map( ( w ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ w }
							label={ w }
							checked={ rel.includes( w ) }
							onChange={ ( on ) =>
								setRel(
									on
										? [ ...rel, w ]
										: rel.filter( ( x ) => x !== w )
								)
							}
						/>
					) ) }
				</fieldset>
			) }
			{ action === 'url' && (
				<TextControl
					__nextHasNoMarginBottom
					type="url"
					dir="ltr"
					label={ __( 'New address', 'zinn-seo' ) }
					value={ to }
					onChange={ setTo }
				/>
			) }
			<div className="zseo-out__do">
				<RunButton
					onError={ setError }
					run={ () =>
						seoFetch( '/links/outbound/preview', {
							method: 'POST',
							data: target,
						} ).then( setPreview )
					}
				>
					{ __( 'Preview', 'zinn-seo' ) }
				</RunButton>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'zinn-seo' ) }
				</Button>
			</div>
			{ preview && preview.items.length === 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ reasonText( preview.reason ) }
				</Notice>
			) }
			{ preview && preview.items.length > 0 && (
				<div className="zseo-out__preview">
					{ preview.items.map( ( p, i ) => (
						<dl key={ i }>
							<dt>{ __( 'Now', 'zinn-seo' ) }</dt>
							<dd>
								<code dir="ltr">{ p.before }</code>
							</dd>
							<dt>{ __( 'After', 'zinn-seo' ) }</dt>
							<dd>
								<code dir="ltr">
									{ p.after ||
										__(
											'(the address changes in the block’s settings)',
											'zinn-seo'
										) }
								</code>
							</dd>
							{ p.n > 1 && (
								<dd className="zseo-muted">
									{ sprintf(
										/* translators: %d: number of links. */
										__(
											'The page has %d links like this one; each changes.',
											'zinn-seo'
										),
										p.n
									) }
								</dd>
							) }
						</dl>
					) ) }
					{ ! done && (
						<RunButton
							variant="primary"
							onError={ setError }
							run={ () =>
								seoFetch( '/links/outbound/change', {
									method: 'POST',
									data: target,
								} ).then( ( r ) => {
									setDone( r );
									onDone();
								} )
							}
						>
							{ __( 'Apply this change', 'zinn-seo' ) }
						</RunButton>
					) }
				</div>
			) }
			{ done && (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'Changing it now. The page keeps a revision, and you can undo it in one click.',
						'zinn-seo'
					) }{ ' ' }
					<a href={ runHref( done.run ) }>
						{ __( 'See the change or undo it', 'zinn-seo' ) }
					</a>
				</Notice>
			) }
		</div>
	);
}

/**
 * Change or remove every link to a site: proposed in a run to review first.
 *
 * @param {Object}   props
 * @param {Object}   props.site    Site.
 * @param {Function} props.onError Error.
 * @return {Element} Form.
 */
function SiteChange( { site, onError } ) {
	const [ action, setAction ] = useState( 'rel' );
	const [ rel, setRel ] = useState( [ 'nofollow' ] );
	return (
		<div className="zseo-out__bulk">
			<SelectControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: a site, e.g. example.com. */
					__( 'Every link to %s', 'zinn-seo' ),
					site.host
				) }
				value={ action }
				onChange={ setAction }
				options={ [
					{
						value: 'rel',
						label: __( 'Change the link type', 'zinn-seo' ),
					},
					{
						value: 'remove',
						label: __(
							'Remove the links (the words stay)',
							'zinn-seo'
						),
					},
				] }
			/>
			{ action === 'rel' && (
				<fieldset className="zseo-out__rels">
					<legend className="screen-reader-text">
						{ __( 'Link type', 'zinn-seo' ) }
					</legend>
					{ RELS.map( ( w ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ w }
							label={ w }
							checked={ rel.includes( w ) }
							onChange={ ( on ) =>
								setRel(
									on
										? [ ...rel, w ]
										: rel.filter( ( x ) => x !== w )
								)
							}
						/>
					) ) }
				</fieldset>
			) }
			<RunButton
				onError={ onError }
				run={ () =>
					seoFetch( '/links/outbound/change', {
						method: 'POST',
						data: { host: site.host, action, rel },
					} ).then( ( r ) => {
						window.location.href = runHref( r.run );
					} )
				}
			>
				{ __( 'Review the changes', 'zinn-seo' ) }
			</RunButton>
		</div>
	);
}

/**
 * The links to one site.
 *
 * @param {Object}   props
 * @param {Object}   props.site      Site.
 * @param {boolean}  props.canChange May change links.
 * @param {Function} props.onChanged After a change.
 * @return {Element} List.
 */
function SiteLinks( { site, canChange, onChanged } ) {
	const [ items, setItems ] = useState( null );
	const [ next, setNext ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ editing, setEditing ] = useState( 0 );
	const load = ( after = '' ) =>
		seoFetch(
			`/links/outbound/links?host=${ encodeURIComponent(
				site.host
			) }&search=${ encodeURIComponent( search ) }${
				after ? `&after=${ encodeURIComponent( after ) }` : ''
			}`
		)
			.then( ( r ) => {
				setItems( ( prev ) =>
					after ? [ ...( prev || [] ), ...r.items ] : r.items
				);
				setNext( r.next );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ site.host, search ] );
	return (
		<div className="zseo-out__links">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<TextControl
				__nextHasNoMarginBottom
				type="search"
				label={ __(
					'Find a link by its words or address',
					'zinn-seo'
				) }
				value={ search }
				onChange={ setSearch }
			/>
			{ ! items && <Spinner /> }
			{ items && items.length === 0 && (
				<p className="zseo-muted">
					{ __( 'No link matches.', 'zinn-seo' ) }
				</p>
			) }
			<ul className="zseo-out__linklist">
				{ ( items || [] ).map( ( l ) => (
					<li key={ l.id } className="zseo-out__link">
						<p>
							<a href={ l.edit || l.view }>
								{ l.title || `#${ l.post }` }
							</a>{ ' ' }
							<span className="zseo-tag">
								{ relText( l.rel ) }
							</span>
							{ l.image && (
								<span className="zseo-tag">
									{ __( 'Image', 'zinn-seo' ) }
								</span>
							) }
						</p>
						<p className="zseo-out__anchor">
							<span className="screen-reader-text">
								{ __( 'Words:', 'zinn-seo' ) }{ ' ' }
							</span>
							{ l.anchor || __( '(no words)', 'zinn-seo' ) }
						</p>
						<p className="zseo-out__url" dir="ltr">
							{ l.url }
						</p>
						{ canChange && editing !== l.id && (
							<Button
								variant="secondary"
								size="compact"
								onClick={ () => setEditing( l.id ) }
							>
								{ __( 'Change or remove', 'zinn-seo' ) }
							</Button>
						) }
						{ editing === l.id && (
							<LinkEditor
								host={ site.host }
								link={ l }
								onDone={ onChanged }
								onClose={ () => setEditing( 0 ) }
							/>
						) }
					</li>
				) ) }
			</ul>
			{ next && (
				<Button variant="secondary" onClick={ () => load( next ) }>
					{ __( 'Show more links', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * One linked site.
 *
 * @param {Object}   props
 * @param {Object}   props.site      Site.
 * @param {boolean}  props.canManage May manage.
 * @param {boolean}  props.canEdit   Changing links is available.
 * @param {Function} props.onError   Error.
 * @param {Function} props.onChanged After a change.
 * @return {Element} Item.
 */
function Site( { site, canManage, canEdit, onError, onChanged } ) {
	const [ open, setOpen ] = useState( false );
	const [ now, setNow ] = useState( site );
	const mark = ( m ) =>
		seoFetch( '/links/outbound/mark', {
			method: 'POST',
			data: { host: now.host, mark: m },
		} ).then( setNow );
	return (
		<li className="zseo-out__site">
			<p className="zseo-out__host">
				<strong dir="ltr">{ now.host }</strong>
				<span
					className={ `zseo-tag zseo-out__status is-${
						now.status || 'none'
					}` }
				>
					{ statusText( now.status ) }
				</span>
				{ now.flags.map( ( f ) => (
					<span key={ f } className="zseo-tag is-warn">
						{ flagText( f ) }
					</span>
				) ) }
				{ now.broken > 0 && (
					<span className="zseo-tag is-warn">
						{ sprintf(
							/* translators: %d: number of broken links. */
							_n(
								'%d broken link',
								'%d broken links',
								now.broken,
								'zinn-seo'
							),
							now.broken
						) }
					</span>
				) }
			</p>
			<p className="zseo-muted">
				{ sprintf(
					/* translators: %d: number of links. */
					_n( '%d link', '%d links', now.links, 'zinn-seo' ),
					now.links
				) }{ ' ' }
				{ sprintf(
					/* translators: %d: number of pages. */
					_n( 'on %d page', 'on %d pages', now.pages, 'zinn-seo' ),
					now.pages
				) }
				{ ' · ' }
				{ [
					now.follow &&
						sprintf(
							/* translators: %d: number of links. */
							__( '%d followed', 'zinn-seo' ),
							now.follow
						),
					now.nofollow && `${ now.nofollow } nofollow`,
					now.sponsored && `${ now.sponsored } sponsored`,
					now.ugc && `${ now.ugc } ugc`,
				]
					.filter( Boolean )
					.join( ', ' ) }
			</p>
			{ now.status === 'redirect' && now.location && (
				<p className="zseo-muted">
					{ __( 'Sends visitors to:', 'zinn-seo' ) }{ ' ' }
					<span dir="ltr">{ now.location }</span>
				</p>
			) }
			<div className="zseo-out__do">
				<Button
					variant="secondary"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					{ open
						? __( 'Hide the links', 'zinn-seo' )
						: __( 'Show the links', 'zinn-seo' ) }
				</Button>
				{ canManage && (
					<Button
						variant="tertiary"
						onClick={ () =>
							mark(
								now.flags.includes( 'spam' ) ? 'fine' : 'spam'
							).catch( ( e ) => onError( errorText( e ) ) )
						}
					>
						{ now.flags.includes( 'spam' )
							? __( 'Not spam', 'zinn-seo' )
							: __( 'Mark as spam', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			{ open && canManage && canEdit && (
				<SiteChange site={ now } onError={ onError } />
			) }
			{ open && (
				<SiteLinks
					site={ now }
					canChange={ canManage && canEdit }
					onChanged={ onChanged }
				/>
			) }
		</li>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function OutboundTab( { data } ) {
	const [ filters, setFilters ] = useState( {
		search: '',
		status: '',
		rel: '',
		flag: '',
		sort: 'links',
	} );
	const [ list, setList ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ n, setN ] = useState( 0 );
	const reload = () => setN( n + 1 );
	const [ job, setJob ] = useJob(
		() => seoFetch( sitesPath( filters ) ).then( ( r ) => r.job ),
		reload
	);
	const [ check, setCheck ] = useJob(
		() => seoFetch( sitesPath( filters ) ).then( ( r ) => r.check ),
		reload
	);
	useEffect( () => {
		let live = true;
		seoFetch( sitesPath( filters ) )
			.then( ( r ) => {
				if ( ! live ) {
					return;
				}
				setList( r );
				setJob( r.job );
				setCheck( r.check );
			} )
			.catch( ( e ) => live && setError( errorText( e ) ) );
		return () => {
			live = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ filters, n ] );
	const more = () =>
		seoFetch( sitesPath( filters, list.next ) )
			.then( ( r ) =>
				setList( { ...r, items: [ ...list.items, ...r.items ] } )
			)
			.catch( ( e ) => setError( errorText( e ) ) );
	const set = ( k ) => ( v ) => setFilters( { ...filters, [ k ]: v } );
	const totals = list ? list.totals : null;
	const busy = job && job.status === 'pending';
	return (
		<div className="zseo-stack zseo-out">
			<p className="zseo-muted">
				{ __(
					'Every site your pages link to: how many links, on which pages, with which words, and whether each is followed, nofollow, sponsored or ugc. Change or remove one link where it is, or every link to a site at once; you review it first, each page keeps a revision, and a whole change undoes in one click.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="outbound" />
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ totals && ( totals.built || totals.sites > 0 ) && (
				<ul className="zseo-out__totals">
					<li>
						<strong>{ totals.sites }</strong>{ ' ' }
						{ __( 'sites', 'zinn-seo' ) }
					</li>
					<li>
						<strong>{ totals.links }</strong>{ ' ' }
						{ __( 'links', 'zinn-seo' ) }
					</li>
					<li>
						<strong>{ totals.follow }</strong>{ ' ' }
						{ __( 'followed', 'zinn-seo' ) }
					</li>
					<li>
						<strong>{ totals.broken }</strong>{ ' ' }
						{ __( 'with broken links', 'zinn-seo' ) }
					</li>
					<li>
						<strong>{ totals.spam + totals.expired }</strong>{ ' ' }
						{ __( 'flagged', 'zinn-seo' ) }
					</li>
				</ul>
			) }
			{ totals && ! totals.built && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Build the report once: every published page is read in the background (the same read as the internal-link report, so both are fresh). After that it stays up to date as pages are saved.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ data.canManage && (
				<div className="zseo-out__do">
					<RunButton
						variant={
							totals && ! totals.built ? 'primary' : 'secondary'
						}
						disabled={ busy }
						onError={ setError }
						run={ () =>
							seoFetch( '/links/outbound/build', {
								method: 'POST',
							} ).then( setJob )
						}
					>
						{ totals && totals.built
							? __( 'Read every page again', 'zinn-seo' )
							: __( 'Build the report', 'zinn-seo' ) }
					</RunButton>
					<RunButton
						disabled={
							! totals ||
							! totals.sites ||
							( check && check.status === 'pending' )
						}
						onError={ setError }
						run={ () =>
							seoFetch( '/links/outbound/check', {
								method: 'POST',
							} ).then( setCheck )
						}
					>
						{ __( 'Check every linked site', 'zinn-seo' ) }
					</RunButton>
				</div>
			) }
			<JobLine job={ job } />
			<JobLine job={ check } />
			{ list && ! list.canEdit && data.canManage && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'To change links from here, switch on Fix it on autopilot under Modules: it keeps the review, a revision of each page and the one-click undo.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<div className="zseo-filters zseo-out__filters">
				<TextControl
					__nextHasNoMarginBottom
					type="search"
					label={ __( 'Find a site', 'zinn-seo' ) }
					value={ filters.search }
					onChange={ set( 'search' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'zinn-seo' ) }
					value={ filters.status }
					onChange={ set( 'status' ) }
					options={ [
						{ value: '', label: __( 'Any', 'zinn-seo' ) },
						{ value: 'live', label: statusText( 'live' ) },
						{ value: 'redirect', label: statusText( 'redirect' ) },
						{ value: 'broken', label: statusText( 'broken' ) },
						{ value: 'blocked', label: statusText( 'blocked' ) },
						{ value: 'unchecked', label: statusText( '' ) },
					] }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Link type', 'zinn-seo' ) }
					value={ filters.rel }
					onChange={ set( 'rel' ) }
					options={ [
						{ value: '', label: __( 'Any', 'zinn-seo' ) },
						{
							value: 'followed',
							label: __( 'Has followed links', 'zinn-seo' ),
						},
						{ value: 'nofollow', label: 'nofollow' },
						{ value: 'sponsored', label: 'sponsored' },
						{ value: 'ugc', label: 'ugc' },
					] }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Flag', 'zinn-seo' ) }
					value={ filters.flag }
					onChange={ set( 'flag' ) }
					options={ [
						{ value: '', label: __( 'Any', 'zinn-seo' ) },
						{ value: 'spam', label: flagText( 'spam' ) },
						{ value: 'expired', label: flagText( 'expired' ) },
						{
							value: 'broken',
							label: __( 'Broken links', 'zinn-seo' ),
						},
					] }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Order', 'zinn-seo' ) }
					value={ filters.sort }
					onChange={ set( 'sort' ) }
					options={ [
						{
							value: 'links',
							label: __( 'Most links first', 'zinn-seo' ),
						},
						{ value: 'host', label: __( 'A to Z', 'zinn-seo' ) },
					] }
				/>
			</div>
			{ ! list && <Spinner /> }
			{ list && list.items.length === 0 && totals && totals.built && (
				<p className="zseo-ok">
					{ __( 'No linked site matches.', 'zinn-seo' ) }
				</p>
			) }
			<ul className="zseo-out__sites">
				{ ( list ? list.items : [] ).map( ( s ) => (
					<Site
						key={ s.host }
						site={ s }
						canManage={ !! data.canManage }
						canEdit={ !! ( list && list.canEdit ) }
						onError={ setError }
						onChanged={ () => {} }
					/>
				) ) }
			</ul>
			{ list && list.next && (
				<Button variant="secondary" onClick={ more }>
					{ __( 'Show more sites', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}
