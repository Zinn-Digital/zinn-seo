/**
 * Safe updates — the AI Update Guard (zs-655 + zs-775): each waiting plugin, theme and WordPress
 * update checked for this site, tried on a copy visitors never see, installed only on a pass,
 * watched afterwards and put back by itself if the site breaks; the tests' reports in plain
 * words, the AI's explanation and fix, the schedule, and the SEO comparison after every update.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	SelectControl,
	TabPanel,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { Loaded, Refusal, Table, useAction, useLoad } from '../tools/parts';
import {
	actions,
	busy,
	issue,
	pageName,
	reason,
	runState,
	tone,
	verdictName,
} from './pure';

import './guard.scss';

/**
 * A small coloured word.
 *
 * @param {Object} props
 * @param {string} props.v    Verdict or status.
 * @param {string} props.text Words.
 * @return {Element} Badge.
 */
function Badge( { v, text } ) {
	return <span className={ `zseo-badge is-${ tone( v ) }` }>{ text }</span>;
}

/**
 * The screen's tabs (the plugin's inventory and the screen guide read them from here).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function GuardTabs() {
	return [
		{ name: 'updates', title: __( 'Updates', 'zinn-seo' ) },
		{ name: 'tests', title: __( 'Test reports', 'zinn-seo' ) },
		{ name: 'seo', title: __( 'SEO after updates', 'zinn-seo' ) },
		{ name: 'settings', title: __( 'Schedule', 'zinn-seo' ) },
	];
}

/**
 * The tab named in the address (`#/tests`), so a link or a guide can open it.
 *
 * @return {string} Tab name.
 */
function startTab() {
	const want = ( window.location.hash || '' ).replace( /^#\/?/, '' );
	return GuardTabs().some( ( t ) => t.name === want ) ? want : 'updates';
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function GuardScreen( { data } ) {
	const load = useLoad( '/guard' );
	const act = useAction();
	const g = load.data;
	const working = busy( g && g.job );
	// While a test runs in the background, read its progress every few seconds.
	useEffect( () => {
		if ( ! working ) {
			return undefined;
		}
		const t = setInterval( () => load.reload(), 4000 );
		return () => clearInterval( t );
	}, [ working, load ] );
	const post = ( name, path, body, done ) =>
		act
			.run(
				name,
				() =>
					seoFetch( path, {
						method: 'POST',
						data: body || {},
					} ),
				done
			)
			.then( ( next ) => {
				if ( next && next.updates ) {
					load.setData( next );
				} else if ( next ) {
					load.reload();
				}
				return next;
			} );
	return (
		<div className="zseo-admin zseo-guard">
			<Loaded load={ load }>
				{ ( view ) => (
					<>
						<p>
							{ __(
								'Every update is tried on a copy of your site first. Only what passes is installed, and an update that breaks your site afterwards is put back by itself.',
								'zinn-seo'
							) }{ ' ' }
							<GuideLink data={ data } id="guard" />
						</p>
						{ act.done && (
							<Notice status="success" onRemove={ act.clear }>
								{ act.done }
							</Notice>
						) }
						<Refusal error={ act.error } onRemove={ act.clear } />
						{ working && (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Testing on a copy of your site. You can leave this screen; the test carries on.',
									'zinn-seo'
								) }{ ' ' }
								<Button
									variant="link"
									onClick={ () =>
										post(
											'stop',
											'/guard/stop',
											{},
											__(
												'Stopped. Nothing was changed on your site.',
												'zinn-seo'
											)
										)
									}
								>
									{ __( 'Stop', 'zinn-seo' ) }
								</Button>
							</Notice>
						) }
						<TabPanel
							className="zseo-tabs"
							tabs={ GuardTabs() }
							initialTabName={ startTab() }
							onSelect={ ( name ) =>
								window.history.replaceState(
									null,
									'',
									`#/${ name }`
								)
							}
						>
							{ ( tab ) => {
								if ( tab.name === 'tests' ) {
									return (
										<Runs
											view={ view }
											act={ act }
											post={ post }
											data={ data }
										/>
									);
								}
								if ( tab.name === 'seo' ) {
									return (
										<Seo
											view={ view }
											act={ act }
											post={ post }
										/>
									);
								}
								if ( tab.name === 'settings' ) {
									return (
										<Schedule
											view={ view }
											act={ act }
											post={ post }
										/>
									);
								}
								return (
									<Updates
										view={ view }
										act={ act }
										post={ post }
										working={ working }
									/>
								);
							} }
						</TabPanel>
					</>
				) }
			</Loaded>
		</div>
	);
}

/**
 * The waiting updates, with their checks and the buttons to test them.
 *
 * @param {Object}   props
 * @param {Object}   props.view    The screen's data.
 * @param {Object}   props.act     useAction().
 * @param {Function} props.post    POST and refresh.
 * @param {boolean}  props.working A test is running.
 * @return {Element} Card.
 */
function Updates( { view, act, post, working } ) {
	const test = ( u, apply ) =>
		post(
			`${ apply ? 'apply' : 'test' }-${ u.kind }-${ u.id }`,
			'/guard/test',
			{ items: [ { kind: u.kind, id: u.id } ], apply },
			apply
				? __(
						'Testing on a copy; it is installed only if it passes.',
						'zinn-seo'
					)
				: __(
						'Testing on a copy. The report appears under Test reports.',
						'zinn-seo'
					)
		);
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'Updates waiting', 'zinn-seo' ) }</h2>
				<p>
					{ sprintf(
						/* translators: 1: WordPress version, 2: PHP version. */
						__(
							'Each update is checked against your WordPress %1$s and PHP %2$s, your other plugins, and what failed on your site before. Automatic updates that cannot run here are held back; you can still update by hand.',
							'zinn-seo'
						),
						view.wp,
						view.php
					) }
				</p>
				{ ! view.staging && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Updates cannot be tried on a copy on this site (a multisite network, or folders WordPress cannot write to). They are checked for compatibility only.',
							'zinn-seo'
						) }
					</Notice>
				) }
				{ ! view.updates.length ? (
					<p>{ __( 'No updates are waiting.', 'zinn-seo' ) }</p>
				) : (
					<>
						<p>
							<Button
								variant="secondary"
								disabled={ working || !! act.busy }
								isBusy={ act.busy === 'round' }
								onClick={ () =>
									post(
										'round',
										'/guard/round',
										{},
										__(
											'Testing every update that can run here, one after another.',
											'zinn-seo'
										)
									)
								}
							>
								{ __( 'Test them all', 'zinn-seo' ) }
							</Button>
						</p>
						<Table
							label={ __( 'Updates', 'zinn-seo' ) }
							rowKey={ ( r ) => `${ r.kind }-${ r.id }` }
							rows={ view.updates }
							columns={ [
								{
									id: 'n',
									label: __(
										'Plugin, theme or WordPress',
										'zinn-seo'
									),
									render: ( r ) => (
										<bdi>{ `${ r.title } ${ r.current } → ${ r.version }` }</bdi>
									),
								},
								{
									id: 'v',
									label: __( 'Checks', 'zinn-seo' ),
									render: ( r ) => (
										<Badge
											v={ r.verdict }
											text={ verdictName( r.verdict ) }
										/>
									),
								},
								{
									id: 'w',
									label: __( 'Why', 'zinn-seo' ),
									render: ( r ) =>
										r.reasons.map( reason ).join( ' ' ),
								},
								{
									id: 't',
									label: __( 'Last test', 'zinn-seo' ),
									render: ( r ) =>
										r.run ? (
											<Badge
												v={
													r.run.verdict ||
													r.run.status
												}
												text={ runState( r.run ) }
											/>
										) : (
											'—'
										),
								},
								{
									id: 'a',
									label: __( 'Actions', 'zinn-seo' ),
									render: ( r ) => (
										<span className="zseo-row-actions">
											{ r.kind !== 'core' &&
												view.staging && (
													<Button
														variant="secondary"
														size="small"
														disabled={
															working ||
															!! act.busy
														}
														isBusy={
															act.busy ===
															`test-${ r.kind }-${ r.id }`
														}
														onClick={ () =>
															test( r, false )
														}
													>
														{ __(
															'Test on a copy',
															'zinn-seo'
														) }
													</Button>
												) }{ ' ' }
											{ r.verdict !== 'block' && (
												<Button
													variant="primary"
													size="small"
													disabled={
														working || !! act.busy
													}
													isBusy={
														act.busy ===
														`apply-${ r.kind }-${ r.id }`
													}
													onClick={ () =>
														test( r, true )
													}
												>
													{ r.kind === 'core'
														? __(
																'Update and check',
																'zinn-seo'
															)
														: __(
																'Test and update',
																'zinn-seo'
															) }
												</Button>
											) }
										</span>
									),
								},
							] }
						/>
					</>
				) }
				{ view.watching > 0 && (
					<p>
						{ sprintf(
							/* translators: %d: number of updates. */
							_n(
								'%d recent update is being watched: it is put back by itself if it breaks your site.',
								'%d recent updates are being watched: each is put back by itself if it breaks your site.',
								view.watching,
								'zinn-seo'
							),
							view.watching
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The test reports.
 *
 * @param {Object}   props
 * @param {Object}   props.view The screen's data.
 * @param {Object}   props.act  useAction().
 * @param {Function} props.post POST and refresh.
 * @param {Object}   props.data Boot data.
 * @return {Element} Card.
 */
function Runs( { view, act, post, data } ) {
	const [ open, setOpen ] = useState( '' );
	if ( ! view.runs.length ) {
		return (
			<Card>
				<CardBody>
					<p>
						{ __(
							'No update has been tested yet. Press "Test on a copy" beside an update.',
							'zinn-seo'
						) }
					</p>
				</CardBody>
			</Card>
		);
	}
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'Test reports', 'zinn-seo' ) }</h2>
				<ul className="zseo-care-list">
					{ view.runs.map( ( run ) => {
						const can = actions( run );
						const shown = open === run.id;
						return (
							<li
								key={ run.id }
								className="zseo-care-item zseo-stack"
							>
								<p>
									<Badge
										v={
											run.status === 'done'
												? run.verdict
												: run.status
										}
										text={ runState( run ) }
									/>{ ' ' }
									<strong>
										<bdi>{ run.headline }</bdi>
									</strong>{ ' ' }
									<small>
										{ new Date(
											( run.finished || run.updated ) *
												1000
										).toLocaleString(
											document.documentElement.lang ||
												undefined
										) }
									</small>
								</p>
								<p className="zseo-row-actions">
									<Button
										variant="link"
										aria-expanded={ shown }
										onClick={ () =>
											setOpen( shown ? '' : run.id )
										}
									>
										{ shown
											? __(
													'Hide the details',
													'zinn-seo'
												)
											: __(
													'Show the details',
													'zinn-seo'
												) }
									</Button>{ ' ' }
									{ can.install && (
										<Button
											variant="primary"
											size="small"
											disabled={ !! act.busy }
											isBusy={
												act.busy ===
												`install-${ run.id }`
											}
											onClick={ () => {
												if (
													can.confirm &&
													// eslint-disable-next-line no-alert
													! window.confirm(
														__(
															'Something looks different with this update (see the details). Install it anyway?',
															'zinn-seo'
														)
													)
												) {
													return;
												}
												post(
													`install-${ run.id }`,
													'/guard/apply',
													{
														run: run.id,
														accept: can.confirm,
													},
													__(
														'Done. See the report for what happened.',
														'zinn-seo'
													)
												);
											} }
										>
											{ __( 'Update now', 'zinn-seo' ) }
										</Button>
									) }{ ' ' }
									{ can.putBack && (
										<Button
											variant="secondary"
											size="small"
											disabled={ !! act.busy }
											isBusy={
												act.busy === `back-${ run.id }`
											}
											onClick={ () =>
												post(
													`back-${ run.id }`,
													'/guard/rollback',
													{ run: run.id },
													__(
														'The version before is back.',
														'zinn-seo'
													)
												)
											}
										>
											{ __(
												'Put the version before back',
												'zinn-seo'
											) }
										</Button>
									) }{ ' ' }
									{ can.explain && (
										<Button
											variant="secondary"
											size="small"
											disabled={
												! view.ai || !! act.busy
											}
											isBusy={
												act.busy === `ai-${ run.id }`
											}
											onClick={ () =>
												post(
													`ai-${ run.id }`,
													'/guard/explain',
													{ runs: [ run.id ] },
													__(
														'The AI answered: see the details.',
														'zinn-seo'
													)
												).then( () =>
													setOpen( run.id )
												)
											}
										>
											{ __(
												'Explain and fix with AI',
												'zinn-seo'
											) }
										</Button>
									) }
								</p>
								{ shown && (
									<RunDetails run={ run } data={ data } />
								) }
							</li>
						);
					} ) }
				</ul>
			</CardBody>
		</Card>
	);
}

/**
 * One run's details: the story, the checks, each page, the PHP errors and the AI's answer.
 *
 * @param {Object} props
 * @param {Object} props.run  Run.
 * @param {Object} props.data Boot data.
 * @return {Element} Details.
 */
function RunDetails( { run, data } ) {
	const pages = ( run.pages || [] ).filter( ( p ) => p.base || p.new );
	return (
		<div className="zseo-stack">
			{ run.ai && run.ai.meaning && (
				<Notice status="info" isDismissible={ false }>
					<p>
						<strong>{ __( 'What happened', 'zinn-seo' ) }: </strong>
						{ run.ai.meaning }
					</p>
					<p>
						<strong>{ __( 'Why', 'zinn-seo' ) }: </strong>
						{ run.ai.cause }
					</p>
					<p>
						<strong>{ __( 'What to do', 'zinn-seo' ) }: </strong>
						{ run.ai.steps }
					</p>
					{ run.ai.draft && (
						<p>
							{ __(
								'The AI wrote a small code fix for this. Read and approve it, then test the update again with it.',
								'zinn-seo'
							) }{ ' ' }
							<a href={ `${ viewHref( 'care' ) }#/fixes` }>
								{ __( 'Read the code fix', 'zinn-seo' ) }
							</a>{ ' ' }
							<GuideLink data={ data } id="guard-howto" />
						</p>
					) }
				</Notice>
			) }
			<ol className="zseo-guard-story">
				{ ( run.story || [] ).map( ( [ at, line ], i ) => (
					<li key={ `${ at }-${ i }` }>
						<bdi>{ line }</bdi>
					</li>
				) ) }
			</ol>
			{ run.reasons && run.reasons.length > 0 && (
				<>
					<h3>{ __( 'Checks', 'zinn-seo' ) }</h3>
					<ul>
						{ run.reasons.map( ( r, i ) => (
							<li key={ i }>{ reason( r ) }</li>
						) ) }
					</ul>
				</>
			) }
			{ pages.length > 0 && (
				<Table
					label={ __( 'Pages read on the copy', 'zinn-seo' ) }
					rowKey={ ( p ) => p.name }
					rows={ pages }
					columns={ [
						{
							id: 'p',
							label: __( 'Page', 'zinn-seo' ),
							render: ( p ) => pageName( p.name ),
						},
						{
							id: 'c',
							label: __( 'Now / new', 'zinn-seo' ),
							render: ( p ) => (
								<bdi>{ `${ p.base } / ${ p.new }` }</bdi>
							),
						},
						{
							id: 'r',
							label: __( 'Result', 'zinn-seo' ),
							render: ( p ) =>
								p.issues.length ? (
									p.issues.map( issue ).join( ' ' )
								) : (
									<Badge
										v="pass"
										text={ __( 'The same', 'zinn-seo' ) }
									/>
								),
						},
					] }
				/>
			) }
			{ run.errors && run.errors.length > 0 && (
				<>
					<h3>
						{ __(
							'PHP errors that came with the new version',
							'zinn-seo'
						) }
					</h3>
					<ul>
						{ run.errors.map( ( e, i ) => (
							<li key={ i }>
								<Badge
									v={ e.fatal ? 'fail' : 'warn' }
									text={
										e.fatal
											? __( 'Fatal error', 'zinn-seo' )
											: __( 'Warning', 'zinn-seo' )
									}
								/>{ ' ' }
								<bdi className="zseo-break">{ `${ e.message } — ${ e.file }:${ e.line }` }</bdi>
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}

/**
 * The SEO comparison after updates (zs-655).
 *
 * @param {Object}   props
 * @param {Object}   props.view The screen's data.
 * @param {Object}   props.act  useAction().
 * @param {Function} props.post POST and refresh.
 * @return {Element} Card.
 */
function Seo( { view, act, post } ) {
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'Your pages after each update', 'zinn-seo' ) }</h2>
				<p>
					{ __(
						'After every update, your home page and newest posts and pages are read again. If an update changed a title, a description, a canonical address, the robots rules or your structured data, it shows here.',
						'zinn-seo'
					) }
				</p>
				<p>
					<Button
						variant="secondary"
						isBusy={ act.busy === 'check' }
						disabled={ !! act.busy }
						onClick={ () =>
							post( 'check', '/guard/check', {}, '' ).then(
								( next ) => {
									if ( next ) {
										// eslint-disable-next-line no-alert
										window.alert(
											next.changed
												? __(
														'Something changed: see below.',
														'zinn-seo'
													)
												: __(
														'Your pages say the same to search engines as before.',
														'zinn-seo'
													)
										);
									}
								}
							)
						}
					>
						{ __( 'Check my pages now', 'zinn-seo' ) }
					</Button>
				</p>
				{ ( view.reports || [] ).map( ( rep ) => (
					<section key={ rep.at } className="zseo-stack">
						<h3>
							{ new Date( rep.at * 1000 ).toLocaleString(
								document.documentElement.lang || undefined
							) }
						</h3>
						{ rep.lost > 0 && (
							<Notice status="error" isDismissible={ false }>
								{ __(
									'Something your pages had is gone. If it came with this update, put the version before back from Test reports.',
									'zinn-seo'
								) }
							</Notice>
						) }
						<Table
							label={ __( 'What changed', 'zinn-seo' ) }
							rowKey={ ( c ) => `${ c.url }-${ c.field }` }
							rows={ rep.changes }
							columns={ [
								{
									id: 'u',
									label: __( 'Page', 'zinn-seo' ),
									render: ( c ) => (
										<bdi className="zseo-break">
											{ c.url }
										</bdi>
									),
								},
								{
									id: 'f',
									label: __( 'What', 'zinn-seo' ),
									render: ( c ) => c.field,
								},
								{
									id: 'b',
									label: __( 'Before', 'zinn-seo' ),
									render: ( c ) => <bdi>{ c.before }</bdi>,
								},
								{
									id: 'a',
									label: __( 'After', 'zinn-seo' ),
									render: ( c ) => (
										<bdi>{ c.after || '—' }</bdi>
									),
								},
							] }
						/>
					</section>
				) ) }
			</CardBody>
		</Card>
	);
}

/**
 * The schedule and the safety settings.
 *
 * @param {Object}   props
 * @param {Object}   props.view The screen's data.
 * @param {Object}   props.act  useAction().
 * @param {Function} props.post POST and refresh.
 * @return {Element} Card.
 */
function Schedule( { view, act, post } ) {
	const [ s, setS ] = useState( view.settings );
	const save = () =>
		post( 'settings', '/guard/settings', s, __( 'Saved.', 'zinn-seo' ) );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'Test updates by themselves', 'zinn-seo' ) }</h2>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Test the waiting updates', 'zinn-seo' ) }
					value={ s.schedule }
					options={ [
						{
							value: 'off',
							label: __(
								'Only when I press a button',
								'zinn-seo'
							),
						},
						{
							value: 'daily',
							label: __( 'Every day', 'zinn-seo' ),
						},
						{
							value: 'weekly',
							label: __( 'Every week', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => setS( { ...s, schedule: v } ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Then', 'zinn-seo' ) }
					value={ s.auto }
					disabled={ s.schedule === 'off' }
					options={ [
						{
							value: 'off',
							label: __( 'Only tell me the results', 'zinn-seo' ),
						},
						{
							value: 'passed',
							label: __(
								'Install the ones that pass (and tell me)',
								'zinn-seo'
							),
						},
					] }
					onChange={ ( v ) => setS( { ...s, auto: v } ) }
					help={ __(
						"An update that failed or changed something is never installed by itself. While this is on, WordPress's own automatic plugin and theme updates wait for the test.",
						'zinn-seo'
					) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'WordPress itself', 'zinn-seo' ) }
					value={ s.core }
					options={ [
						{
							value: 'off',
							label: __( 'Leave it out', 'zinn-seo' ),
						},
						{
							value: 'minor',
							label: __(
								'Security and fix releases only',
								'zinn-seo'
							),
						},
						{
							value: 'all',
							label: __( 'Every release', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => setS( { ...s, core: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Watch every update, also the ones made outside Safe updates',
						'zinn-seo'
					) }
					help={ __(
						'The version before is kept, and put back by itself if the update causes a fatal error.',
						'zinn-seo'
					) }
					checked={ !! s.watch_all }
					onChange={ ( v ) => setS( { ...s, watch_all: v } ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 1 }
					max={ 168 }
					label={ __(
						'Hours an installed update is watched',
						'zinn-seo'
					) }
					value={ String( s.hours ) }
					onChange={ ( v ) =>
						setS( { ...s, hours: Number( v ) || 24 } )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 100 }
					label={ __(
						'Largest database copied for a test (MB)',
						'zinn-seo'
					) }
					value={ String( s.limit_mb ) }
					onChange={ ( v ) =>
						setS( { ...s, limit_mb: Number( v ) || 4096 } )
					}
					help={ __(
						'The copy is removed as soon as the test ends.',
						'zinn-seo'
					) }
				/>
				<p>
					<Button
						variant="primary"
						isBusy={ act.busy === 'settings' }
						disabled={ !! act.busy }
						onClick={ save }
					>
						{ __( 'Save', 'zinn-seo' ) }
					</Button>
				</p>
				<h2>{ __( 'Let Zinn® do it for you', 'zinn-seo' ) }</h2>
				<p>
					{ __(
						"When an update needs more than a small fix, Zinn®'s team can update and repair your site for you.",
						'zinn-seo'
					) }{ ' ' }
					<a href={ `${ viewHref( 'catalogue' ) }#/update_guard` }>
						{ __( 'See the managed AI Update Guard', 'zinn-seo' ) }
					</a>
				</p>
			</CardBody>
		</Card>
	);
}
