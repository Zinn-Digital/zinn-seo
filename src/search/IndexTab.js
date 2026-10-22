/**
 * Indexing: Google's own verdict for each page (URL Inspection, zs-083), the sitemaps Google and
 * Bing know and what was sent (zs-092), and the Indexing API for job and livestream pages
 * (zs-081).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import {
	Loaded,
	NewTab,
	Refusal,
	Table,
	useAction,
	useLoad,
} from '../tools/parts';
import { count, day } from '../tools/pure';
import { NeedsGoogle } from './OverviewTab';

/**
 * Google's verdict in words.
 *
 * @param {Object} r Answer.
 * @return {string} Text.
 */
function verdictText( r ) {
	if ( r.indexed ) {
		return __( 'In Google', 'zinn-seo' );
	}
	return r.coverage || __( 'Not in Google', 'zinn-seo' );
}

/**
 * The whole-site check: running (with progress and Pause), paused (with why and Carry on), or a
 * button to start it.
 *
 * @param {Object}   props
 * @param {Object}   props.job       The job.
 * @param {boolean}  props.canManage May start it.
 * @param {boolean}  props.busy      An action is running.
 * @param {Function} props.onAct     Called with start|pause|resume.
 * @return {Element} Line.
 */
function JobLine( { job, canManage, busy, onAct } ) {
	if ( job.status === 'pending' ) {
		return (
			<>
				<span>
					{ sprintf(
						/* translators: 1: pages done, 2: pages in all. */
						__(
							'Checking the whole site: %1$s of %2$s',
							'zinn-seo'
						),
						count( job.progress.done || 0 ),
						count( job.progress.total || 0 )
					) }
				</span>
				<Button variant="secondary" onClick={ () => onAct( 'pause' ) }>
					{ __( 'Pause', 'zinn-seo' ) }
				</Button>
			</>
		);
	}
	if ( job.status === 'paused' ) {
		return (
			<>
				<span>{ job.error || __( 'Paused.', 'zinn-seo' ) }</span>
				<Button variant="secondary" onClick={ () => onAct( 'resume' ) }>
					{ __( 'Carry on', 'zinn-seo' ) }
				</Button>
			</>
		);
	}
	return (
		<Button
			variant="primary"
			disabled={ ! canManage || busy }
			onClick={ () => onAct( 'start' ) }
		>
			{ __( 'Check the whole site', 'zinn-seo' ) }
		</Button>
	);
}

/**
 * One page, checked now.
 *
 * @param {Object}   props
 * @param {Function} props.onDone Called after.
 * @param {string}   props.home   Home address.
 * @return {Element} Form.
 */
function CheckOne( { onDone, home } ) {
	const act = useAction();
	const [ url, setUrl ] = useState( home );
	const [ out, setOut ] = useState( null );
	return (
		<div className="zseo-stack">
			<form
				className="zseo-row zseo-row--top"
				onSubmit={ ( e ) => {
					e.preventDefault();
					act.run( 'check', () =>
						seoFetch( '/search/index/check', {
							method: 'POST',
							data: { url },
						} ).then( ( r ) => {
							setOut( r );
							onDone();
						} )
					);
				} }
			>
				<TextControl
					label={ __( 'A page of this site', 'zinn-seo' ) }
					value={ url }
					type="url"
					onChange={ setUrl }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<Button
					variant="secondary"
					type="submit"
					isBusy={ act.busy === 'check' }
					disabled={ !! act.busy }
				>
					{ __( 'Ask Google', 'zinn-seo' ) }
				</Button>
			</form>
			<Refusal error={ act.error } onRemove={ act.clear } />
			{ out && (
				<dl className="zseo-stats">
					<div className="zseo-stats__item">
						<dt>{ __( 'Google says', 'zinn-seo' ) }</dt>
						<dd className={ out.indexed ? 'zseo-ok' : '' }>
							{ verdictText( out ) }
						</dd>
					</div>
					<div className="zseo-stats__item">
						<dt>{ __( 'Last read by Google', 'zinn-seo' ) }</dt>
						<dd>
							{ out.last_crawl
								? day( out.last_crawl, true )
								: '—' }
						</dd>
					</div>
					<div className="zseo-stats__item">
						<dt>{ __( 'Google’s chosen address', 'zinn-seo' ) }</dt>
						<dd className="zseo-address">
							{ out.google_canonical || '—' }
						</dd>
					</div>
					<div className="zseo-stats__item">
						<dt>{ __( 'Rich results', 'zinn-seo' ) }</dt>
						<dd>{ out.rich_results || '—' }</dd>
					</div>
				</dl>
			) }
			{ out && out.canonical_differs && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Google chose a different address for this page than the one the page names. Pages Google sees as copies are left out of search: make the content different, or point both to one page.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ out && out.link && (
				<NewTab href={ out.link }>
					{ __( 'Open in Search Console', 'zinn-seo' ) }
				</NewTab>
			) }
		</div>
	);
}

/**
 * The tab.
 *
 * @param {Object}  props
 * @param {Object}  props.data   Boot data.
 * @param {Object}  props.google Link.
 * @param {boolean} props.ready  Ready.
 * @return {Element} Tab.
 */
export default function IndexTab( { data, google, ready } ) {
	const report = useLoad( ready ? '/search/index' : null );
	const maps = useLoad( '/search/sitemaps' );
	const jobs = useLoad( '/search/indexing' );
	const act = useAction();
	const [ types, setTypes ] = useState( null );
	if ( ! ready ) {
		return <NeedsGoogle google={ google } />;
	}
	const site = ( action ) =>
		act.run( action, () =>
			seoFetch( '/search/index/site', {
				method: 'POST',
				data: { action },
			} ).then( ( r ) => report.setData( r ) )
		);
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<h3 className="zseo-card-title">
						{ __( 'Is each page in Google?', 'zinn-seo' ) }
					</h3>
					<p className="zseo-small zseo-muted">
						{ __(
							'Google’s own answer for each page, from your Search Console. Google allows 2,000 checks a day; the whole-site check spends them on your most-seen pages first and carries on by itself each day until every page is checked.',
							'zinn-seo'
						) }
					</p>
					<Loaded load={ report }>
						{ ( r ) => (
							<>
								<dl className="zseo-stats">
									<div className="zseo-stats__item">
										<dt>
											{ __(
												'Pages checked',
												'zinn-seo'
											) }
										</dt>
										<dd>{ count( r.checked ) }</dd>
									</div>
									<div className="zseo-stats__item">
										<dt>
											{ __( 'In Google', 'zinn-seo' ) }
										</dt>
										<dd className="zseo-ok">
											{ count( r.counts.PASS || 0 ) }
										</dd>
									</div>
									<div className="zseo-stats__item">
										<dt>
											{ __(
												'Not in Google',
												'zinn-seo'
											) }
										</dt>
										<dd>
											{ count(
												r.checked -
													( r.counts.PASS || 0 )
											) }
										</dd>
									</div>
									<div className="zseo-stats__item">
										<dt>
											{ __(
												'Checks left today',
												'zinn-seo'
											) }
										</dt>
										<dd>{ count( r.left ) }</dd>
									</div>
								</dl>
								<div className="zseo-row">
									<JobLine
										job={ r.job }
										canManage={ data.canManage }
										busy={ !! act.busy }
										onAct={ site }
									/>
								</div>
								{ r.out.length > 0 && (
									<Table
										label={ __(
											'Pages not in Google',
											'zinn-seo'
										) }
										rowKey={ ( p ) => p.post_id }
										rows={ r.out }
										columns={ [
											{
												id: 't',
												label: __( 'Page', 'zinn-seo' ),
												render: ( p ) =>
													p.edit ? (
														<NewTab href={ p.edit }>
															{ p.title }
														</NewTab>
													) : (
														p.title
													),
											},
											{
												id: 'w',
												label: __(
													'Why, in Google’s words',
													'zinn-seo'
												),
												render: ( p ) =>
													p.coverage || p.verdict,
											},
											{
												id: 'c',
												label: __(
													'Last read',
													'zinn-seo'
												),
												render: ( p ) =>
													p.last_crawl
														? day( p.last_crawl )
														: __(
																'never',
																'zinn-seo'
															),
											},
										] }
									/>
								) }
							</>
						) }
					</Loaded>
					<CheckOne
						onDone={ () => report.reload() }
						home={ data.homeUrl }
					/>
					<Refusal error={ act.error } onRemove={ act.clear } />
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h3 className="zseo-card-title">
						{ __( 'Sitemaps Google and Bing know', 'zinn-seo' ) }
					</h3>
					<Loaded load={ maps }>
						{ ( m ) => (
							<>
								<p className="zseo-small">
									{ __( 'Your sitemap:', 'zinn-seo' ) }{ ' ' }
									<span className="zseo-address">
										{ m.sitemap || '—' }
									</span>
								</p>
								<ul className="zseo-log">
									{ Object.entries( m.submitted || {} ).map(
										( [ engine, s ] ) => (
											<li key={ engine }>
												<strong>
													{ engine === 'google'
														? 'Google'
														: 'Bing' }
												</strong>
												<span
													className={
														s.ok ? 'zseo-ok' : ''
													}
												>
													{ s.ok
														? __(
																'sent',
																'zinn-seo'
															)
														: s.message }
												</span>
												<span className="zseo-muted">
													{ day( s.at, true ) }
												</span>
											</li>
										)
									) }
								</ul>
								{ m.google.length > 0 && (
									<Table
										label={ __(
											'Sitemaps in Search Console',
											'zinn-seo'
										) }
										rowKey={ ( s ) => s.path }
										rows={ m.google }
										columns={ [
											{
												id: 'p',
												label: __(
													'Sitemap',
													'zinn-seo'
												),
												render: ( s ) => (
													<span className="zseo-address">
														{ s.path }
													</span>
												),
											},
											{
												id: 's',
												numeric: true,
												label: __(
													'Addresses read',
													'zinn-seo'
												),
												render: ( s ) =>
													count( s.submitted ),
											},
											{
												id: 'e',
												numeric: true,
												label: __(
													'Errors',
													'zinn-seo'
												),
												render: ( s ) =>
													count( s.errors ),
											},
											{
												id: 'd',
												label: __(
													'Last read',
													'zinn-seo'
												),
												render: ( s ) =>
													day( s.last_downloaded ) ||
													'—',
											},
										] }
									/>
								) }
								{ m.error && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ m.error }
									</Notice>
								) }
								{ data.canManage && (
									<div>
										<Button
											variant="secondary"
											isBusy={ act.busy === 'send' }
											disabled={ !! act.busy }
											onClick={ () =>
												act.run( 'send', () =>
													seoFetch(
														'/search/sitemaps',
														{ method: 'POST' }
													).then( ( r ) =>
														maps.setData( r )
													)
												)
											}
										>
											{ __(
												'Send the sitemap again',
												'zinn-seo'
											) }
										</Button>
									</div>
								) }
							</>
						) }
					</Loaded>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h3 className="zseo-card-title">
						{ __(
							'Job and livestream pages: tell Google at once',
							'zinn-seo'
						) }
					</h3>
					<p className="zseo-small zseo-muted">
						{ __(
							'Google lets sites tell it the moment a job posting or a livestream page is published, changed or taken down, so it appears in Google for Jobs while it is still open. Google allows this for those pages only — not for ordinary posts.',
							'zinn-seo'
						) }
					</p>
					<Loaded load={ jobs }>
						{ ( j ) => {
							const chosen = types || j.types;
							const save = ( on, list ) =>
								act.run( 'jobs', () =>
									seoFetch( '/search/indexing', {
										method: 'PUT',
										data: { on, types: list },
									} ).then( ( r ) => {
										jobs.setData( r );
										setTypes( null );
									} )
								);
							return (
								<>
									{ ! j.granted && (
										<Notice
											status="info"
											isDismissible={ false }
										>
											{ __(
												'Tick “Job and livestream pages” and press Add these on the Google card above to allow this.',
												'zinn-seo'
											) }
										</Notice>
									) }
									<ToggleControl
										label={ __(
											'Tell Google when these pages change',
											'zinn-seo'
										) }
										checked={ j.on }
										disabled={
											! data.canManage || ! j.granted
										}
										onChange={ ( on ) =>
											save( on, chosen )
										}
										__nextHasNoMarginBottom
									/>
									<fieldset className="zseo-boxes">
										<legend className="zseo-small">
											{ __(
												'Content types that are job or livestream pages:',
												'zinn-seo'
											) }
										</legend>
										{ j.available.map( ( t ) => (
											<CheckboxControl
												key={ t.name }
												label={ t.label }
												checked={ chosen.includes(
													t.name
												) }
												disabled={ ! data.canManage }
												onChange={ ( on ) => {
													const next = on
														? [ ...chosen, t.name ]
														: chosen.filter(
																( x ) =>
																	x !== t.name
															);
													setTypes( next );
													save( j.on, next );
												} }
												__nextHasNoMarginBottom
											/>
										) ) }
									</fieldset>
									{ j.paused && (
										<Notice
											status="warning"
											isDismissible={ false }
										>
											{ j.paused }
										</Notice>
									) }
									{ j.waiting > 0 && (
										<p className="zseo-small">
											{ sprintf(
												/* translators: %s: number of pages. */
												__(
													'%s pages waiting to be sent.',
													'zinn-seo'
												),
												count( j.waiting )
											) }
										</p>
									) }
									{ j.log.length > 0 && (
										<ul className="zseo-log">
											{ j.log
												.slice( 0, 10 )
												.map( ( l, i ) => (
													<li
														key={ `${ l.url }-${ i }` }
													>
														<span className="zseo-address">
															{ l.url }
														</span>
														<span>
															{ 'URL_DELETED' ===
															l.kind
																? __(
																		'taken down',
																		'zinn-seo'
																	)
																: __(
																		'published or changed',
																		'zinn-seo'
																	) }
														</span>
														<span
															className={
																l.ok
																	? 'zseo-ok'
																	: ''
															}
														>
															{ l.ok
																? __(
																		'Google told',
																		'zinn-seo'
																	)
																: l.note }
														</span>
													</li>
												) ) }
										</ul>
									) }
								</>
							);
						} }
					</Loaded>
				</CardBody>
			</Card>
		</div>
	);
}
