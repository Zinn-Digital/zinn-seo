/**
 * Protection: the hacked-site spam scan (zs-400) and, in Pro, the SEO lock (zs-331).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TabPanel,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import ProTeaser from '../ProTeaser';
import { JobLine, RunButton, Table, useJob, useRoute } from '../p4/shared';
import './p5.scss';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function protectionTabs() {
	return [
		{ name: 'scan', title: __( 'Hacked-site scan', 'zinn-seo' ) },
		{ name: 'lock', title: __( 'SEO lock', 'zinn-seo' ) },
	];
}

/**
 * The scan tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function ScanTab( { data } ) {
	const state = useRoute( '/spam' );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/spam' ).then( ( r ) => r.job ),
		() => state.reload()
	);
	useEffect( () => {
		if ( state.data ) {
			setJob( state.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ state.data ] );
	const r = state.data ? state.data.result : null;
	const kinds = r ? Object.keys( r.findings || {} ) : [];
	const ignore = ( body ) =>
		seoFetch( '/spam/ignore', { method: 'POST', data: body } )
			.then( () => state.reload() )
			.catch( ( e ) => setError( errorText( e ) ) );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'SEO spam hacks hide pages, links and redirects that visitors never see but search engines do — and they cost a site its rankings for months before anyone notices. The scan reads every published page, your widgets and settings, the site’s folder, and fetches your newest pages both as a visitor and as Googlebot.',
					'zinn-seo'
				) }
			</p>
			{ ( error || state.error ) && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error || state.error }
				</Notice>
			) }
			{ ! state.data && <Spinner /> }
			{ state.data && (
				<div className="zseo-actions zseo-actions--start">
					{ data.canManage && (
						<RunButton
							variant="primary"
							onError={ setError }
							disabled={ job && job.status === 'pending' }
							run={ () =>
								seoFetch( '/spam/scan', {
									method: 'POST',
								} ).then( setJob )
							}
						>
							{ r
								? __( 'Scan again', 'zinn-seo' )
								: __( 'Scan the site', 'zinn-seo' ) }
						</RunButton>
					) }
					{ state.data.watch && (
						<span className="zseo-chip">
							{ __(
								'Scanned every week; anything new is e-mailed to you.',
								'zinn-seo'
							) }
						</span>
					) }
				</div>
			) }
			<JobLine job={ job } />
			{ r && kinds.length === 0 && (
				<Notice status="success" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: number of pages, 2: date. */
						__(
							'Nothing found: %1$d pages, the widgets and settings, the site’s folder and the newest pages as Googlebot sees them were checked (%2$s).',
							'zinn-seo'
						),
						r.pages,
						new Date( r.at * 1000 ).toLocaleString()
					) }
				</Notice>
			) }
			{ r && kinds.length > 0 && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: number of kinds of finding, 2: date. */
							__(
								'%1$d kinds of SEO spam found (%2$s). Nothing was changed: look at each one below.',
								'zinn-seo'
							),
							kinds.length,
							new Date( r.at * 1000 ).toLocaleString()
						) }
					</Notice>
					{ kinds.map( ( k ) => {
						const f = r.findings[ k ];
						return (
							<Card key={ k } className="zseo-fix is-high">
								<CardHeader>
									<h3 className="zseo-card-title">
										{ f.name } ({ f.count })
									</h3>
								</CardHeader>
								<CardBody className="zseo-stack">
									<p>{ f.advice }</p>
									<ul className="zseo-found-list">
										{ f.items.map( ( item, i ) => (
											<li key={ i }>
												{ item.edit ? (
													<a href={ item.edit }>
														{ item.title }
													</a>
												) : (
													<span className="zseo-address">
														{ item.title }
													</span>
												) }
												{ item.view && (
													<>
														{ ' · ' }
														<a
															href={ item.view }
															target="_blank"
															rel="noreferrer"
														>
															{ __(
																'View',
																'zinn-seo'
															) }
														</a>
													</>
												) }
												{ item.detail && (
													<span className="zseo-muted zseo-address">
														{ ' — ' }
														{ item.detail }
													</span>
												) }
												{ data.canManage &&
													item.key && (
														<>
															{ ' ' }
															<Button
																variant="link"
																onClick={ () =>
																	ignore( {
																		key: item.key,
																	} )
																}
															>
																{ __(
																	'This is normal',
																	'zinn-seo'
																) }
															</Button>
														</>
													) }
											</li>
										) ) }
									</ul>
									{ f.count > f.items.length && (
										<p className="zseo-muted">
											{ sprintf(
												/* translators: 1: shown, 2: all. */
												__(
													'The first %1$d of %2$d.',
													'zinn-seo'
												),
												f.items.length,
												f.count
											) }
										</p>
									) }
								</CardBody>
							</Card>
						);
					} ) }
				</>
			) }
			{ r && r.ignored > 0 && data.canManage && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %d: number of findings marked as normal. */
						__(
							'%d findings you marked as normal are not shown or e-mailed.',
							'zinn-seo'
						),
						r.ignored
					) }{ ' ' }
					<Button
						variant="link"
						onClick={ () => ignore( { restore: true } ) }
					>
						{ __( 'Show them again', 'zinn-seo' ) }
					</Button>
				</p>
			) }
			{ 'free' === data.plan && (
				<ProTeaser
					title={ __( 'Weekly spam watch', 'zinn-seo' ) }
					points={ [
						__( 'The scan runs every week by itself.', 'zinn-seo' ),
						__(
							'Anything new is e-mailed to you the same day.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}

/**
 * The SEO lock tab (Pro): the locked pages and their history.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function LockTab( { data } ) {
	const [ rows, setRows ] = useState( null );
	const [ next, setNext ] = useState( 0 );
	const [ open, setOpen ] = useState( null );
	const [ log, setLog ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const load = ( after, replace ) =>
		seoFetch( `/lock?after=${ after }` )
			.then( ( r ) => {
				setRows(
					replace ? r.items : [ ...( rows || [] ), ...r.items ]
				);
				setNext( r.next );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		load( 0, true );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	const fields = {
		lock: __( 'Lock', 'zinn-seo' ),
		'lock:slug': __( 'Address', 'zinn-seo' ),
		'lock:robots': __( 'Indexing', 'zinn-seo' ),
		'lock:canonical': __( 'Canonical', 'zinn-seo' ),
	};
	const show = ( v ) => {
		if ( Array.isArray( v ) ) {
			return v.join( ', ' ) || '–';
		}
		if ( '1' === v ) {
			return __( 'on', 'zinn-seo' );
		}
		return v ? String( v ) : '–';
	};
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'A locked page keeps its address, whether search engines may index it, and its canonical address, unless whoever saves confirms the change. Every confirmed change is logged with who and when. Lock a page from its editor (SEO lock, in the document settings) or the classic editor’s box.',
					'zinn-seo'
				) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ null === rows && <Spinner /> }
			{ null !== rows && (
				<Table
					columns={ [
						{
							id: 'title',
							label: __( 'Page', 'zinn-seo' ),
							render: ( r ) => (
								<span className="zseo-cell-title">
									<a href={ r.edit }>{ r.title }</a>
									<span className="zseo-muted zseo-address">
										{ r.url }
									</span>
								</span>
							),
						},
						{
							id: 'index',
							label: __( 'Indexing', 'zinn-seo' ),
							render: ( r ) =>
								r.noindex
									? __( 'Hidden (noindex)', 'zinn-seo' )
									: __( 'Indexed', 'zinn-seo' ),
						},
						{
							id: 'canonical',
							label: __( 'Canonical', 'zinn-seo' ),
							render: ( r ) => (
								<span className="zseo-address">
									{ r.canonical ||
										__( 'Itself', 'zinn-seo' ) }
								</span>
							),
						},
						{
							id: 'do',
							label: __( 'Actions', 'zinn-seo' ),
							render: ( r ) => (
								<span className="zseo-actions zseo-actions--start">
									<Button
										variant="link"
										onClick={ () =>
											seoFetch( `/lock/${ r.id }` )
												.then( ( l ) => {
													setOpen( r.id );
													setLog( l.log );
												} )
												.catch( ( e ) =>
													setError( errorText( e ) )
												)
										}
									>
										{ __( 'History', 'zinn-seo' ) }
									</Button>
									{ data.canManage && (
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												seoFetch( `/lock/${ r.id }`, {
													method: 'POST',
													data: { locked: false },
												} )
													.then( () =>
														load( 0, true )
													)
													.catch( ( e ) =>
														setError(
															errorText( e )
														)
													)
											}
										>
											{ __( 'Unlock', 'zinn-seo' ) }
										</Button>
									) }
								</span>
							),
						},
					] }
					rows={ rows }
					label={ __( 'Locked pages', 'zinn-seo' ) }
					empty={ __( 'No page is locked yet.', 'zinn-seo' ) }
				/>
			) }
			{ next > 0 && (
				<p>
					<Button
						variant="secondary"
						onClick={ () => load( next, false ) }
					>
						{ __( 'Show more', 'zinn-seo' ) }
					</Button>
				</p>
			) }
			{ open && (
				<Card>
					<CardHeader>
						<h3 className="zseo-card-title">
							{ __( 'History', 'zinn-seo' ) }
						</h3>
					</CardHeader>
					<CardBody>
						<Table
							columns={ [
								{
									id: 'at',
									label: __( 'When', 'zinn-seo' ),
									render: ( l ) =>
										new Date(
											l.at * 1000
										).toLocaleString(),
								},
								{
									id: 'field',
									label: __( 'What', 'zinn-seo' ),
									render: ( l ) =>
										fields[ l.field ] || l.field,
								},
								{
									id: 'change',
									label: __( 'Change', 'zinn-seo' ),
									render: ( l ) => (
										<span className="zseo-address">
											{ show( l.old ) } →{ ' ' }
											{ show( l.new ) }
										</span>
									),
								},
								{
									id: 'by',
									label: __( 'Who', 'zinn-seo' ),
									render: ( l ) =>
										l.by ||
										__( 'Command line', 'zinn-seo' ),
								},
							] }
							rows={ log.map( ( l, i ) => ( { ...l, id: i } ) ) }
							label={ __( 'Lock history', 'zinn-seo' ) }
							empty={ __( 'Nothing logged yet.', 'zinn-seo' ) }
						/>
					</CardBody>
				</Card>
			) }
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
export default function ProtectionScreen( { data } ) {
	const mods = data.modules || {};
	const tabs = protectionTabs().filter(
		( t ) => 'scan' !== t.name || false !== mods[ 'spam-scan' ]
	);
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	return (
		<div className="zseo-admin zseo-p5">
			<TabPanel
				className="zseo-tabs"
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === initial )
						? initial
						: tabs[ 0 ].name
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
					if ( 'lock' === tab.name ) {
						return mods.lock && 'free' !== data.plan ? (
							<LockTab data={ data } />
						) : (
							<ProTeaser
								title={ __( 'SEO lock', 'zinn-seo' ) }
								points={ [
									__(
										'Protect your most important pages from an accidental change of address, noindex or canonical.',
										'zinn-seo'
									),
									__(
										'A change needs a confirmation, and every change is logged with who and when.',
										'zinn-seo'
									),
								] }
							/>
						);
					}
					return <ScanTab data={ data } />;
				} }
			</TabPanel>
		</div>
	);
}
