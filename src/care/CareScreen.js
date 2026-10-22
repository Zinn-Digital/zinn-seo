/**
 * Site care with AI (Pro, zs-772 … zs-776): find and clean up malware, read the error logs and
 * fix what they show, clean up the database, and approve small code fixes — every change backed
 * up first, nothing deleted (only quarantined), and every change undoable, one at a time or a
 * whole run at once.
 *
 * ⭐ The screen uses exactly the REST routes an AI agent uses (MCP), so they cannot disagree. The
 * only thing an agent cannot do here is approve code: approving a code fix is a person's job.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	TabPanel,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import ProTeaser from '../admin/ProTeaser';
import GuideLink from '../admin/p7/GuideLink';
import { seoFetch, useSettings } from '../admin/api';
import { undoRun } from './undo';
import { viewHref } from '../admin-kit/router';
import { Loaded, Refusal, Table, useAction, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';
import {
	actionName,
	bytes,
	choices,
	fixKindName,
	fixState,
	kindName,
	openLabel,
	problemText,
	progressText,
	recommended,
	runCounts,
	running,
	severity,
	sourceName,
	stepName,
	sumTotals,
	verdictName,
	verdictTone,
	diffClass,
} from './pure';
import './care.scss';

/**
 * The tabs (exported for the command palette and the inventory).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function careTabs() {
	return [
		{ name: 'malware', title: __( 'Malware', 'zinn-seo' ) },
		{ name: 'errors', title: __( 'Error logs', 'zinn-seo' ) },
		{ name: 'database', title: __( 'Database', 'zinn-seo' ) },
		{ name: 'fixes', title: __( 'Code fixes', 'zinn-seo' ) },
		{ name: 'history', title: __( 'History and undo', 'zinn-seo' ) },
		{ name: 'schedule', title: __( 'Schedules and alerts', 'zinn-seo' ) },
	];
}

/**
 * Follow a background job: while it runs, ask again every few seconds; when it ends, say so.
 *
 * @param {Object}   job    The job as last read (`{status}`).
 * @param {Function} reload Reload the overview (returns a promise of it).
 * @param {Function} pick   Overview => this job.
 * @param {Function} onEnd  Called once when the job is no longer running.
 */
function useFollow( job, reload, pick, onEnd ) {
	const was = useRef( running( job ) );
	useEffect( () => {
		if ( ! running( job ) ) {
			if ( was.current ) {
				was.current = false;
				onEnd();
			}
			return undefined;
		}
		was.current = true;
		const t = window.setTimeout( () => {
			reload().then( ( next ) => next && pick( next ) );
		}, 3000 );
		return () => window.clearTimeout( t );
	}, [ job, reload, pick, onEnd ] );
}

/**
 * A badge.
 *
 * @param {Object} props
 * @param {string} props.tone     success|warning|error|info.
 * @param {*}      props.children Words.
 * @return {Element} Badge.
 */
function Badge( { tone, children } ) {
	return <span className={ `zseo-badge is-${ tone }` }>{ children }</span>;
}

/**
 * A time as the reader reads it.
 *
 * @param {number} at Unix time.
 * @return {string} Date and time.
 */
function when( at ) {
	return at ? day( at, true ) : '—';
}

/**
 * The note shown above every tab: what is safe about it, and the paid escalation.
 *
 * @param {Object} props
 * @param {Object} props.care The overview.
 * @return {Element} Note.
 */
function SafetyNote( { care } ) {
	return (
		<p className="zseo-muted">
			{ __(
				'Every change is backed up first and can be undone from History and undo. Nothing is deleted: removed files and rows are kept in quarantine.',
				'zinn-seo'
			) }{ ' ' }
			<a href={ care.services }>
				{ __(
					'Rather have our team do it? Order a clean-up.',
					'zinn-seo'
				) }
			</a>
		</p>
	);
}

/**
 * Undo a whole run (one button after a clean-up).
 *
 * @param {Object}   props
 * @param {string}   props.run    Run id.
 * @param {Function} props.onDone After undoing.
 * @return {Element|null} Button.
 */
function UndoRun( { run, onDone } ) {
	const act = useAction();
	if ( ! run ) {
		return null;
	}
	return (
		<>
			<Button
				variant="secondary"
				isBusy={ act.busy === 'undo' }
				disabled={ !! act.busy }
				onClick={ () =>
					act
						.run(
							'undo',
							() => undoRun( run ),
							__(
								'Undone: everything in that run is back as it was.',
								'zinn-seo'
							)
						)
						.then( ( r ) => r && onDone && onDone( r ) )
				}
			>
				{ __( 'Undo this whole run', 'zinn-seo' ) }
			</Button>
			{ act.done && (
				<Notice status="success" onRemove={ act.clear }>
					{ act.done }
				</Notice>
			) }
			<Refusal error={ act.error } onRemove={ act.clear } />
		</>
	);
}

/**
 * A list of findings (malware or errors) with keyset paging.
 *
 * @param {string} area   malware|errors.
 * @param {string} status open|fixed|ignored.
 * @return {Object} `{rows, counts, more, loadMore, reload, error, loading}`.
 */
function useFindings( area, status ) {
	const base = area === 'malware' ? '/care/malware/findings' : '/care/errors';
	const [ state, setState ] = useState( {
		rows: [],
		next: 0,
		counts: null,
		loading: true,
		error: null,
	} );
	const reload = useCallback(
		() =>
			seoFetch( `${ base }?status=${ status }` )
				.then( ( r ) =>
					setState( {
						rows: r.rows,
						next: r.next,
						counts: r.counts,
						loading: false,
						error: null,
					} )
				)
				.catch( ( error ) =>
					setState( ( s ) => ( { ...s, loading: false, error } ) )
				),
		[ base, status ]
	);
	useEffect( () => {
		reload();
	}, [ reload ] );
	const loadMore = () =>
		seoFetch( `${ base }?status=${ status }&after=${ state.next }` ).then(
			( r ) =>
				setState( ( s ) => ( {
					...s,
					rows: [ ...s.rows, ...r.rows ],
					next: r.next,
					counts: r.counts,
				} ) )
		);
	return {
		...state,
		data: state.counts ? state : null,
		more: state.next > 0,
		loadMore,
		reload,
	};
}

/**
 * Status filter for a findings list.
 *
 * @param {Object}   props
 * @param {string}   props.value    Status.
 * @param {Function} props.onChange Change.
 * @return {Element} Select.
 */
function StatusFilter( { value, onChange } ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ __( 'Show', 'zinn-seo' ) }
			value={ value }
			options={ [
				{ value: 'open', label: __( 'Open problems', 'zinn-seo' ) },
				{ value: 'fixed', label: __( 'Fixed', 'zinn-seo' ) },
				{ value: 'ignored', label: __( 'Marked as safe', 'zinn-seo' ) },
			] }
			onChange={ onChange }
		/>
	);
}

/**
 * zs-772: the malware scan and clean-up.
 *
 * @param {Object}   props
 * @param {Object}   props.data   Boot data.
 * @param {Object}   props.care   The overview.
 * @param {Function} props.reload Reload the overview.
 * @return {Element} Tab.
 */
function MalwareTab( { data, care, reload } ) {
	const [ status, setStatus ] = useState( 'open' );
	const list = useFindings( 'malware', status );
	const [ job, setJob ] = useState( care.malware.job );
	const [ picked, setPicked ] = useState( {} );
	const [ result, setResult ] = useState( null );
	const [ ai, setAi ] = useState( {} );
	const act = useAction();
	const pick = useCallback( ( next ) => setJob( next.malware.job ), [] );
	const again = list.reload;
	const ended = useCallback( () => again(), [ again ] );
	useFollow( job, reload, pick, ended );
	const last = care.malware.last || {};
	const scan = () =>
		act
			.run( 'scan', () =>
				seoFetch( '/care/malware/scan', { method: 'POST' } )
			)
			.then( ( r ) => r && setJob( r.job ) );
	const chosen = list.rows.filter( ( f ) => picked[ f.id ] );
	const apply = ( items ) =>
		act
			.run( 'fix', () =>
				seoFetch( '/care/malware/fix', {
					method: 'POST',
					data: { items },
				} )
			)
			.then( ( r ) => {
				if ( r ) {
					setResult( r );
					setPicked( {} );
					list.reload();
					reload();
				}
			} );
	const review = () =>
		act
			.run( 'review', () =>
				seoFetch( '/care/malware/review', {
					method: 'POST',
					data: { ids: chosen.map( ( f ) => f.id ).slice( 0, 20 ) },
				} )
			)
			.then( ( rows ) => {
				if ( rows ) {
					const next = { ...ai };
					rows.forEach( ( r ) => ( next[ r.id ] = r ) );
					setAi( next );
				}
			} );
	const counts = runCounts( result && result.done );
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<h2>{ __( 'Malware scan', 'zinn-seo' ) }</h2>
					<p>
						{ __(
							'Checks every WordPress, plugin and theme file against the official copies, looks for known malware code and program files hidden among your uploads, and checks the database for injected scripts, spam links, hidden or new administrators and malicious settings. The SEO spam check runs with it.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="care" />
					</p>
					{ last.at ? (
						<p>
							{ sprintf(
								/* translators: 1: date, 2: files checked, 3: problems open. */
								__(
									'Last scan %1$s: %2$s files checked, %3$s problems open.',
									'zinn-seo'
								),
								when( last.at ),
								count( last.files ),
								count(
									( care.malware.counts &&
										care.malware.counts.open ) ||
										0
								)
							) }
						</p>
					) : (
						<p>
							{ __(
								'Your site has not been scanned yet.',
								'zinn-seo'
							) }
						</p>
					) }
					{ ( last.notes || [] ).map( ( n ) => (
						<Notice key={ n } status="info" isDismissible={ false }>
							{ n }
						</Notice>
					) ) }
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="primary"
							isBusy={ running( job ) || act.busy === 'scan' }
							disabled={ running( job ) || !! act.busy }
							onClick={ scan }
						>
							{ running( job )
								? progressText( job )
								: __( 'Scan now', 'zinn-seo' ) }
						</Button>
					</div>
					<SafetyNote care={ care } />
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-row zseo-row--top">
						<h2>{ __( 'What the scan found', 'zinn-seo' ) }</h2>
						<StatusFilter
							value={ status }
							onChange={ ( v ) => {
								setStatus( v );
								setPicked( {} );
							} }
						/>
					</div>
					<Refusal error={ act.error } onRemove={ act.clear } />
					{ result && (
						<Notice
							status={ counts.failed ? 'warning' : 'success' }
							onRemove={ () => setResult( null ) }
						>
							<p>
								{ sprintf(
									/* translators: 1: fixed and checked again, 2: not fixed. */
									__(
										'%1$d fixed and checked again; %2$d not fixed.',
										'zinn-seo'
									),
									counts.ok,
									counts.failed
								) }
							</p>
							<ul>
								{ result.done
									.filter( ( d ) => ! d.ok )
									.map( ( d ) => (
										<li key={ d.id }>{ d.message }</li>
									) ) }
							</ul>
							<UndoRun
								run={ result.run }
								onDone={ () => {
									list.reload();
									reload();
								} }
							/>
						</Notice>
					) }
					{ status === 'open' && list.rows.length > 0 && (
						<div className="zseo-actions zseo-actions--start">
							<Button
								variant="primary"
								disabled={ ! chosen.length || !! act.busy }
								isBusy={ act.busy === 'fix' }
								onClick={ () =>
									apply(
										chosen
											.map( ( f ) => ( {
												id: f.id,
												action: recommended( f ),
											} ) )
											.filter( ( i ) => i.action )
									)
								}
							>
								{ sprintf(
									/* translators: %d: number chosen. */
									__(
										'Fix the %d chosen (recommended action)',
										'zinn-seo'
									),
									chosen.length
								) }
							</Button>
							<Button
								variant="secondary"
								disabled={ ! chosen.length || !! act.busy }
								isBusy={ act.busy === 'review' }
								onClick={ review }
							>
								{ __(
									'Ask the AI about the chosen',
									'zinn-seo'
								) }
							</Button>
						</div>
					) }
					<Loaded load={ list }>
						{ () =>
							list.rows.length ? (
								<Table
									label={ __( 'Findings', 'zinn-seo' ) }
									rowKey={ ( f ) => f.id }
									rows={ list.rows }
									columns={ [
										...( status === 'open'
											? [
													{
														id: 'pick',
														label: __(
															'Choose',
															'zinn-seo'
														),
														render: ( f ) => (
															<CheckboxControl
																__nextHasNoMarginBottom
																label={ __(
																	'Choose',
																	'zinn-seo'
																) }
																hideLabelFromVision
																checked={
																	!! picked[
																		f.id
																	]
																}
																onChange={ (
																	on
																) =>
																	setPicked( {
																		...picked,
																		[ f.id ]:
																			on,
																	} )
																}
															/>
														),
													},
												]
											: [] ),
										{
											id: 'what',
											label: __( 'What', 'zinn-seo' ),
											render: ( f ) => (
												<>
													<Badge
														tone={
															severity(
																f.severity
															).tone
														}
													>
														{
															severity(
																f.severity
															).label
														}
													</Badge>{ ' ' }
													<strong>
														{ kindName( f.kind ) }
													</strong>
													<br />
													<bdi className="zseo-break">
														{ f.target }
													</bdi>
												</>
											),
										},
										{
											id: 'why',
											label: __(
												'In plain words',
												'zinn-seo'
											),
											render: ( f ) => (
												<FindingWords
													f={ f }
													ai={ ai[ f.id ] }
												/>
											),
										},
										...( status === 'open'
											? [
													{
														id: 'do',
														label: __(
															'Fix it',
															'zinn-seo'
														),
														render: ( f ) => (
															<RowFix
																f={ f }
																busy={
																	!! act.busy
																}
																onApply={ (
																	action
																) =>
																	apply( [
																		{
																			id: f.id,
																			action,
																		},
																	] )
																}
															/>
														),
													},
												]
											: [] ),
									] }
								/>
							) : (
								<p>
									{ status === 'open'
										? __(
												'Nothing open. Your site looks clean.',
												'zinn-seo'
											)
										: __( 'Nothing here.', 'zinn-seo' ) }
								</p>
							)
						}
					</Loaded>
					{ list.more && (
						<Button variant="link" onClick={ list.loadMore }>
							{ __( 'Show more', 'zinn-seo' ) }
						</Button>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * A finding's plain words, with the AI's second opinion when there is one.
 *
 * @param {Object} props
 * @param {Object} props.f  Finding.
 * @param {Object} props.ai The AI's answer from this session.
 * @return {Element} Words.
 */
function FindingWords( { f, ai } ) {
	const saved = f.data && f.data.ai;
	const said = ai && ai.ok ? ai.ai : saved;
	return (
		<div className="zseo-stack zseo-care-words">
			<span>{ f.detail }</span>
			{ ai && ! ai.ok && (
				<span className="zseo-muted">
					{ ( ai.failure && ai.failure.message ) || ai.message }
				</span>
			) }
			{ said && said.verdict && (
				<span>
					<Badge tone={ verdictTone( said.verdict ) }>
						{ verdictName( said.verdict ) }
					</Badge>{ ' ' }
					{ said.what } { said.advice }
				</span>
			) }
		</div>
	);
}

/**
 * The fix choices for one finding.
 *
 * @param {Object}   props
 * @param {Object}   props.f       Finding.
 * @param {boolean}  props.busy    Something runs.
 * @param {Function} props.onApply Apply an action.
 * @return {Element} Choice and button.
 */
function RowFix( { f, busy, onApply } ) {
	const options = choices( f );
	const [ action, setAction ] = useState( recommended( f ) || options[ 0 ] );
	return (
		<div className="zseo-stack">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'What to do', 'zinn-seo' ) }
				hideLabelFromVision
				value={ action }
				options={ options.map( ( a ) => ( {
					value: a,
					label: actionName( a ),
				} ) ) }
				onChange={ setAction }
			/>
			<Button
				variant="secondary"
				disabled={ busy || ! action }
				onClick={ () => onApply( action ) }
			>
				{ __( 'Do it', 'zinn-seo' ) }
			</Button>
		</div>
	);
}

/**
 * zs-773: the error logs, grouped, explained and fixed.
 *
 * @param {Object}   props
 * @param {Object}   props.data   Boot data.
 * @param {Object}   props.care   The overview.
 * @param {Function} props.reload Reload the overview.
 * @param {Function} props.goTo   Open another tab.
 * @return {Element} Tab.
 */
function ErrorsTab( { data, care, reload, goTo } ) {
	const [ status, setStatus ] = useState( 'open' );
	const list = useFindings( 'errors', status );
	const [ job, setJob ] = useState( care.errors.job );
	const [ ai, setAi ] = useState( {} );
	const act = useAction();
	const pick = useCallback( ( next ) => setJob( next.errors.job ), [] );
	const again = list.reload;
	const ended = useCallback( () => again(), [ again ] );
	useFollow( job, reload, pick, ended );
	const read = () =>
		act
			.run( 'read', () =>
				seoFetch( '/care/errors/read', { method: 'POST' } )
			)
			.then( ( r ) => r && setJob( r.job ) );
	const explain = ( ids ) =>
		act
			.run( 'explain', () =>
				seoFetch( '/care/errors/explain', {
					method: 'POST',
					data: { ids },
				} )
			)
			.then( ( rows ) => {
				if ( rows ) {
					const next = { ...ai };
					rows.forEach( ( r ) => ( next[ r.id ] = r ) );
					setAi( next );
				}
			} );
	const off = ( id ) =>
		act
			.run(
				'off',
				() =>
					seoFetch( '/care/errors/deactivate', {
						method: 'POST',
						data: { id },
					} ),
				__(
					'The plugin is switched off. Undo it from History and undo if you need it back.',
					'zinn-seo'
				)
			)
			.then( ( r ) => r && list.reload() );
	const ignore = ( id ) =>
		act
			.run( 'ignore', () =>
				seoFetch( '/care/ignore', {
					method: 'POST',
					data: { ids: [ id ] },
				} )
			)
			.then( ( r ) => r && list.reload() );
	const e = care.errors;
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<h2>{ __( 'Error logs', 'zinn-seo' ) }</h2>
					<p>
						{ __(
							'Reads your PHP error log, debug.log and the fatal errors WordPress recorded, groups the same error together, names the plugin or theme behind it and explains it in plain words. Ask the AI for the fix: an update, going back a version, a setting, switching something off, or a small code fix you approve.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="care-errors" />
					</p>
					{ e.logs.length ? (
						<p>
							{ __( 'Logs read:', 'zinn-seo' ) }{ ' ' }
							{ e.logs.map( ( l ) => (
								<bdi key={ l } className="zseo-break">
									{ l }{ ' ' }
								</bdi>
							) ) }
						</p>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'No error log was found. Fatal errors are still recorded by Zinn® SEO. To log every error, ask your host to turn on the PHP error log.',
								'zinn-seo'
							) }
						</Notice>
					) }
					{ e.debug && e.display && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Errors are shown to your visitors (WP_DEBUG_DISPLAY is on). Turn it off in wp-config.php, or ask your host: it can show private details.',
								'zinn-seo'
							) }
						</Notice>
					) }
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="primary"
							isBusy={ running( job ) || act.busy === 'read' }
							disabled={ running( job ) || !! act.busy }
							onClick={ read }
						>
							{ running( job )
								? __( 'Reading…', 'zinn-seo' )
								: __( 'Read the logs now', 'zinn-seo' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-row zseo-row--top">
						<h2>{ __( 'Errors', 'zinn-seo' ) }</h2>
						<StatusFilter value={ status } onChange={ setStatus } />
					</div>
					{ act.done && (
						<Notice status="success" onRemove={ act.clear }>
							{ act.done }
						</Notice>
					) }
					<Refusal error={ act.error } onRemove={ act.clear } />
					<Loaded load={ list }>
						{ () =>
							list.rows.length ? (
								<ul className="zseo-care-list">
									{ list.rows.map( ( f ) => (
										<ErrorRow
											key={ f.id }
											f={ f }
											ai={ ai[ f.id ] }
											open={ status === 'open' }
											busy={ act.busy }
											onExplain={ () =>
												explain( [ f.id ] )
											}
											onOff={ () => off( f.id ) }
											onIgnore={ () => ignore( f.id ) }
											onFix={ () => goTo( 'fixes' ) }
											updatesUrl={ `${ data.adminUrl }update-core.php` }
										/>
									) ) }
								</ul>
							) : (
								<p>
									{ status === 'open'
										? __( 'No errors to fix.', 'zinn-seo' )
										: __( 'Nothing here.', 'zinn-seo' ) }
								</p>
							)
						}
					</Loaded>
					{ list.more && (
						<Button variant="link" onClick={ list.loadMore }>
							{ __( 'Show more', 'zinn-seo' ) }
						</Button>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * One error group.
 *
 * @param {Object}   props
 * @param {Object}   props.f          Finding.
 * @param {Object}   props.ai         The AI's answer from this session.
 * @param {boolean}  props.open       Open list.
 * @param {string}   props.busy       Busy action.
 * @param {Function} props.onExplain  Ask the AI.
 * @param {Function} props.onOff      Switch the plugin off.
 * @param {Function} props.onIgnore   Mark as harmless.
 * @param {Function} props.onFix      Open the code fixes.
 * @param {string}   props.updatesUrl WordPress updates screen.
 * @return {Element} Row.
 */
function ErrorRow( {
	f,
	ai,
	open,
	busy,
	onExplain,
	onOff,
	onIgnore,
	onFix,
	updatesUrl,
} ) {
	const d = f.data || {};
	const src = d.source || {};
	const said = ai && ai.ok ? ai.ai : d.ai;
	const acts = d.actions || [];
	return (
		<li className="zseo-care-item zseo-stack">
			<div>
				<Badge tone={ severity( f.severity ).tone }>
					{ severity( f.severity ).label }
				</Badge>{ ' ' }
				<strong>
					<bdi>{ src.name || kindName( f.kind ) }</bdi>
				</strong>{ ' ' }
				<span className="zseo-muted">
					{ sprintf(
						/* translators: 1: times seen, 2: last seen date. */
						_n(
							'seen %1$d time, last %2$s',
							'seen %1$d times, last %2$s',
							Number( d.count ) || 1,
							'zinn-seo'
						),
						Number( d.count ) || 1,
						when( d.last )
					) }
				</span>
			</div>
			<p>{ d.plain || f.detail }</p>
			<details>
				<summary>
					{ __( 'The error as PHP wrote it', 'zinn-seo' ) }
				</summary>
				<pre className="zseo-care-code" dir="ltr">
					{ `${ d.level || '' }: ${ d.message || '' }\n${
						d.file || ''
					}:${ d.line || '' }` }
				</pre>
			</details>
			{ ai && ! ai.ok && (
				<Notice status="warning" isDismissible={ false }>
					{ ( ai.failure && ai.failure.message ) || ai.message }
				</Notice>
			) }
			{ said && said.meaning && (
				<div className="zseo-care-ai zseo-stack">
					<strong>{ fixKindName( said.fix ) }</strong>
					<span>{ said.meaning }</span>
					<span>{ said.cause }</span>
					<span>{ said.steps }</span>
					{ said.draft && (
						<span>
							<Button variant="link" onClick={ onFix }>
								{ __(
									'Read the code fix the AI wrote',
									'zinn-seo'
								) }
							</Button>
						</span>
					) }
				</div>
			) }
			{ open && (
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="secondary"
						isBusy={ busy === 'explain' }
						disabled={ !! busy }
						onClick={ onExplain }
					>
						{ __( 'Explain it and propose a fix', 'zinn-seo' ) }
					</Button>
					{ acts.includes( 'update' ) && (
						<Button variant="secondary" href={ updatesUrl }>
							{ sprintf(
								/* translators: %s: version. */
								__( 'Update to %s', 'zinn-seo' ),
								src.update
							) }
						</Button>
					) }
					{ acts.includes( 'deactivate' ) && (
						<Button
							variant="secondary"
							isDestructive
							disabled={ !! busy }
							onClick={ onOff }
						>
							{ actionName( 'deactivate' ) }
						</Button>
					) }
					<Button
						variant="tertiary"
						disabled={ !! busy }
						onClick={ onIgnore }
					>
						{ __( 'Harmless: ignore it', 'zinn-seo' ) }
					</Button>
				</div>
			) }
		</li>
	);
}

/**
 * zs-774: the database clean-up.
 *
 * @param {Object}   props
 * @param {Object}   props.data   Boot data.
 * @param {Object}   props.care   The overview.
 * @param {Function} props.reload Reload the overview.
 * @return {Element} Tab.
 */
function DatabaseTab( { data, care, reload } ) {
	const [ job, setJob ] = useState( care.database.job );
	const [ picked, setPicked ] = useState( {} );
	const [ sure, setSure ] = useState( false );
	const act = useAction();
	const pick = useCallback( ( next ) => setJob( next.database.job ), [] );
	const ended = useCallback( () => setPicked( {} ), [] );
	useFollow( job, reload, pick, ended );
	const auto = useLoad( '/care/database/autoload' );
	const last = care.database.last || {};
	const preview = last.preview || null;
	const clean = last.clean || null;
	const kinds = care.database.kinds;
	const chosen = Object.keys( picked ).filter( ( k ) => picked[ k ] );
	const start = ( path, payload ) =>
		act
			.run( path, () =>
				seoFetch( `/care/database/${ path }`, {
					method: 'POST',
					data: payload,
				} )
			)
			.then( ( r ) => {
				if ( r ) {
					setJob( r.job );
					setSure( false );
				}
			} );
	const sum = sumTotals( preview && preview.totals );
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<h2>{ __( 'Database clean-up', 'zinn-seo' ) }</h2>
					<p>
						{ __(
							'First measure what can go, with the size of each kind. Then choose what to clean. Every row removed is backed up first and can be put back; with Zinn® Backups installed, a full database backup is made before the clean-up too. It works in small batches, so even very large sites stay fast.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="care-database" />
					</p>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="secondary"
							isBusy={ running( job ) || act.busy === 'preview' }
							disabled={ running( job ) || !! act.busy }
							onClick={ () => start( 'preview', {} ) }
						>
							{ running( job )
								? progressText( job )
								: __(
										'Measure what can be cleaned',
										'zinn-seo'
									) }
						</Button>
					</div>
					<Refusal error={ act.error } onRemove={ act.clear } />
					{ preview && (
						<p>
							{ sprintf(
								/* translators: 1: date, 2: rows, 3: size. */
								__(
									'Measured %1$s: %2$s rows, about %3$s, can be cleaned.',
									'zinn-seo'
								),
								when( preview.at ),
								count( sum.rows ),
								bytes( sum.bytes )
							) }
						</p>
					) }
					<Table
						label={ __( 'What can be cleaned', 'zinn-seo' ) }
						rowKey={ ( k ) => k }
						rows={ Object.keys( kinds ) }
						columns={ [
							{
								id: 'pick',
								label: __( 'Clean', 'zinn-seo' ),
								render: ( k ) => (
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ kinds[ k ].label }
										hideLabelFromVision
										checked={ !! picked[ k ] }
										onChange={ ( on ) =>
											setPicked( {
												...picked,
												[ k ]: on,
											} )
										}
									/>
								),
							},
							{
								id: 'what',
								label: __( 'What', 'zinn-seo' ),
								render: ( k ) => (
									<>
										<strong>{ kinds[ k ].label }</strong>
										<br />
										<span className="zseo-muted">
											{ kinds[ k ].about }
										</span>
									</>
								),
							},
							{
								id: 'size',
								label: __( 'Found', 'zinn-seo' ),
								render: ( k ) => {
									const t =
										preview &&
										preview.totals &&
										preview.totals[ k ];
									return t
										? sprintf(
												/* translators: 1: rows, 2: size. */
												__(
													'%1$s rows, %2$s',
													'zinn-seo'
												),
												count( t.rows ),
												bytes( t.bytes )
											)
										: '—';
								},
							},
						] }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'I have looked at the sizes. Back up and clean the chosen kinds.',
							'zinn-seo'
						) }
						checked={ sure }
						onChange={ setSure }
					/>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="primary"
							disabled={
								! sure ||
								! chosen.length ||
								running( job ) ||
								!! act.busy
							}
							isBusy={ act.busy === 'clean' }
							onClick={ () =>
								start( 'clean', { kinds: chosen } )
							}
						>
							{ sprintf(
								/* translators: %d: kinds chosen. */
								_n(
									'Clean up %d kind',
									'Clean up %d kinds',
									chosen.length,
									'zinn-seo'
								),
								chosen.length
							) }
						</Button>
					</div>
					{ clean && (
						<Notice
							status={ clean.failed ? 'warning' : 'success' }
							isDismissible={ false }
						>
							<p>
								{ sprintf(
									/* translators: 1: date, 2: rows removed, 3: size. */
									__(
										'Cleaned %1$s: %2$s rows removed (about %3$s), all backed up first.',
										'zinn-seo'
									),
									when( clean.at ),
									count( sumTotals( clean.totals ).rows ),
									bytes( sumTotals( clean.totals ).bytes )
								) }
							</p>
							{ clean.failed && <p>{ clean.failed }</p> }
							<UndoRun run={ clean.run } onDone={ reload } />
						</Notice>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2>
						{ __( 'Settings loaded on every page', 'zinn-seo' ) }
					</h2>
					<p>
						{ __(
							'WordPress loads these settings on every page view (autoload). Large ones slow every page down. Each is named with the plugin or theme it belongs to; stop loading one that a plugin you removed left behind.',
							'zinn-seo'
						) }
					</p>
					<Loaded load={ auto }>
						{ ( a ) => (
							<>
								<p>
									{ sprintf(
										/* translators: 1: settings, 2: size. */
										__(
											'%1$s settings, %2$s in all.',
											'zinn-seo'
										),
										count( a.count ),
										bytes( a.total )
									) }
								</p>
								<AutoloadTable
									rows={ a.rows }
									onChanged={ auto.reload }
								/>
							</>
						) }
					</Loaded>
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * The biggest autoloaded settings, each switchable.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.rows      Rows.
 * @param {Function}      props.onChanged After a change.
 * @return {Element} Table.
 */
function AutoloadTable( { rows, onChanged } ) {
	const act = useAction();
	const set = ( name, on ) =>
		act
			.run( name, () =>
				seoFetch( '/care/database/autoload', {
					method: 'POST',
					data: { name, on },
				} )
			)
			.then( ( r ) => r && onChanged() );
	return (
		<>
			<Refusal error={ act.error } onRemove={ act.clear } />
			<Table
				label={ __( 'Biggest settings', 'zinn-seo' ) }
				rowKey={ ( r ) => r.name }
				rows={ rows }
				columns={ [
					{
						id: 'n',
						label: __( 'Setting', 'zinn-seo' ),
						render: ( r ) => (
							<bdi className="zseo-break" dir="ltr">
								{ r.name }
							</bdi>
						),
					},
					{
						id: 'o',
						label: __( 'Belongs to', 'zinn-seo' ),
						render: ( r ) => <bdi>{ r.owner }</bdi>,
					},
					{
						id: 's',
						label: __( 'Size', 'zinn-seo' ),
						render: ( r ) => bytes( r.bytes ),
					},
					{
						id: 'a',
						label: __( 'Load on every page', 'zinn-seo' ),
						render: ( r ) =>
							r.core ? (
								<span className="zseo-muted">
									{ __( 'WordPress needs it', 'zinn-seo' ) }
								</span>
							) : (
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Load on every page',
										'zinn-seo'
									) }
									hideLabelFromVision
									checked
									disabled={ !! act.busy }
									onChange={ ( on ) => set( r.name, on ) }
								/>
							),
					},
				] }
			/>
		</>
	);
}

/**
 * zs-776: code fixes as must-use plugins.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @param {Object} props.care The overview.
 * @return {Element} Tab.
 */
function FixesTab( { data, care } ) {
	const load = useLoad( '/care/fixes' );
	const [ open, setOpen ] = useState( '' );
	const [ draft, setDraft ] = useState( { title: '', why: '', code: '' } );
	const act = useAction();
	const step = ( fix, name, extra = {} ) =>
		act
			.run(
				`${ fix.id }-${ name }`,
				() =>
					seoFetch( `/care/fixes/${ name }`, {
						method: 'POST',
						data: { id: fix.id, ...extra },
					} ),
				name === 'trial'
					? __(
							'The test run finished: see the result below.',
							'zinn-seo'
						)
					: ''
			)
			.then( ( r ) => r && load.reload() );
	const write = () =>
		act
			.run( 'write', () =>
				seoFetch( '/care/fixes', { method: 'POST', data: draft } )
			)
			.then( ( r ) => {
				if ( r ) {
					setDraft( { title: '', why: '', code: '' } );
					setOpen( r.id );
					load.reload();
				}
			} );
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<h2>{ __( 'Code fixes', 'zinn-seo' ) }</h2>
					<p>
						{ __(
							'When a problem needs a little code, the AI writes a small must-use plugin. It is checked against PHP and WordPress safety rules, shown to you line by line, tested where your visitors never see it, and only then switched on. If it ever causes an error it switches itself off at once, and you can remove it in one click.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="care-fixes" />
					</p>
					{ ! care.fixes.writable && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The must-use plugins folder cannot be written on this site, so code fixes cannot be switched on. Ask your host to make wp-content/mu-plugins writable.',
								'zinn-seo'
							) }
						</Notice>
					) }
					{ act.done && (
						<Notice status="success" onRemove={ act.clear }>
							{ act.done }
						</Notice>
					) }
					<Refusal error={ act.error } onRemove={ act.clear } />
					<Loaded load={ load }>
						{ ( r ) => (
							<FixesList
								r={ r }
								open={ open }
								setOpen={ setOpen }
								busy={ act.busy }
								step={ step }
							/>
						) }
					</Loaded>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2>{ __( 'Add a code fix yourself', 'zinn-seo' ) }</h2>
					<p>
						{ __(
							'Paste a snippet you were given (no opening PHP tag). It goes through the same checks, test and approval as the AI’s.',
							'zinn-seo'
						) }
					</p>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'What it fixes', 'zinn-seo' ) }
						value={ draft.title }
						onChange={ ( title ) =>
							setDraft( { ...draft, title } )
						}
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Why (optional)', 'zinn-seo' ) }
						value={ draft.why }
						onChange={ ( why ) => setDraft( { ...draft, why } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						className="zseo-care-code-input"
						label={ __( 'The code', 'zinn-seo' ) }
						rows={ 8 }
						dir="ltr"
						value={ draft.code }
						onChange={ ( code ) => setDraft( { ...draft, code } ) }
					/>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="secondary"
							disabled={
								! draft.title.trim() ||
								! draft.code.trim() ||
								!! act.busy
							}
							isBusy={ act.busy === 'write' }
							onClick={ write }
						>
							{ __( 'Check it', 'zinn-seo' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * The code fixes, or why there are none.
 *
 * @param {Object}   props
 * @param {Object}   props.r       `{fixes, can_code}`.
 * @param {string}   props.open    The fix shown open.
 * @param {Function} props.setOpen Open one.
 * @param {string}   props.busy    Busy action.
 * @param {Function} props.step    Take a step on a fix.
 * @return {Element} List.
 */
function FixesList( { r, open, setOpen, busy, step } ) {
	if ( ! r.can_code ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'This site does not allow editing code from wp-admin (DISALLOW_FILE_EDIT), so code fixes are off. Everything else in site care works.',
					'zinn-seo'
				) }
			</Notice>
		);
	}
	if ( ! r.fixes.length ) {
		return (
			<p>
				{ __(
					'No code fixes yet. The AI proposes one when it explains an error that needs code.',
					'zinn-seo'
				) }
			</p>
		);
	}
	return (
		<ul className="zseo-care-list">
			{ r.fixes.map( ( fix ) => (
				<FixRow
					key={ fix.id }
					fix={ fix }
					open={ open === fix.id }
					onToggle={ () => setOpen( open === fix.id ? '' : fix.id ) }
					busy={ busy }
					onStep={ ( name, extra ) => step( fix, name, extra ) }
				/>
			) ) }
		</ul>
	);
}

/**
 * One code fix with its diff, checks, test result and next step.
 *
 * @param {Object}   props
 * @param {Object}   props.fix      Fix.
 * @param {boolean}  props.open     Shown open.
 * @param {Function} props.onToggle Open/close.
 * @param {string}   props.busy     Busy action.
 * @param {Function} props.onStep   Take a step.
 * @return {Element} Row.
 */
function FixRow( { fix, open, onToggle, busy, onStep } ) {
	const state = fixState( fix );
	const [ diff, setDiff ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		if ( ! open || diff ) {
			return;
		}
		seoFetch( `/care/fixes/diff?id=${ fix.id }` )
			.then( setDiff )
			.catch( setError );
	}, [ open, diff, fix.id ] );
	return (
		<li className="zseo-care-item zseo-stack">
			<div>
				<Badge tone={ state.tone }>{ state.label }</Badge>{ ' ' }
				<strong>{ fix.title }</strong>{ ' ' }
				<span className="zseo-muted">
					{ sourceName( fix.source ) } · { when( fix.created ) }
				</span>
			</div>
			{ fix.why && <p>{ fix.why }</p> }
			{ fix.off_why && (
				<Notice status="warning" isDismissible={ false }>
					{ fix.off_why }
				</Notice>
			) }
			{ ( fix.checks || [] ).length > 0 && (
				<ul className="zseo-care-problems">
					{ fix.checks.map( ( p, i ) => (
						<li key={ i }>{ problemText( p ) }</li>
					) ) }
				</ul>
			) }
			{ fix.trial && (
				<p>
					{ fix.trial.ok
						? sprintf(
								/* translators: %s: date. */
								__(
									'Tested %s: every page answered as before, no errors.',
									'zinn-seo'
								),
								when( fix.trial.at )
							)
						: sprintf(
								/* translators: %s: date. */
								__(
									'Tested %s: it failed, so it was not switched on.',
									'zinn-seo'
								),
								when( fix.trial.at )
							) }
				</p>
			) }
			{ fix.trial && ! fix.trial.ok && (
				<ul className="zseo-care-problems">
					{ ( fix.trial.errors || [] ).map( ( e, i ) => (
						<li key={ i }>{ e.message }</li>
					) ) }
				</ul>
			) }
			<div>
				<Button variant="link" onClick={ onToggle }>
					{ open
						? __( 'Hide the code', 'zinn-seo' )
						: __( 'Read the code, line by line', 'zinn-seo' ) }
				</Button>
			</div>
			{ open && (
				<>
					<Refusal
						error={ error }
						onRemove={ () => setError( null ) }
					/>
					{ diff && (
						<pre className="zseo-care-diff" dir="ltr">
							{ diff.diff.map( ( l, i ) => (
								<span key={ i } className={ diffClass( l.op ) }>
									{ `${ l.op } ${ l.text }\n` }
								</span>
							) ) }
						</pre>
					) }
				</>
			) }
			{ state.next.length > 0 && (
				<div className="zseo-actions zseo-actions--start">
					{ state.next.map( ( name ) => (
						<Button
							key={ name }
							variant={
								name === 'remove' ? 'tertiary' : 'secondary'
							}
							isDestructive={ name === 'remove' }
							isBusy={ busy === `${ fix.id }-${ name }` }
							disabled={
								!! busy || ( name === 'approve' && ! diff )
							}
							onClick={ () =>
								onStep(
									name,
									name === 'approve' && diff
										? { hash: diff.hash }
										: {}
								)
							}
						>
							{ stepName( name ) }
						</Button>
					) ) }
				</div>
			) }
			{ state.next.includes( 'approve' ) && ! diff && (
				<p className="zseo-muted">
					{ __(
						'Read the code first: approving is possible once you have opened it.',
						'zinn-seo'
					) }
				</p>
			) }
		</li>
	);
}

/**
 * Everything site care changed, each undoable.
 *
 * @param {Object}   props
 * @param {Object}   props.data   Boot data.
 * @param {Function} props.reload Reload the overview.
 * @return {Element} Tab.
 */
function HistoryTab( { data, reload } ) {
	const [ area, setArea ] = useState( '' );
	const load = useLoad( `/care/history?area=${ area }` );
	const [ sure, setSure ] = useState( false );
	const act = useAction();
	const undo = ( payload ) =>
		act
			.run(
				'undo',
				() =>
					payload.run
						? undoRun( payload.run )
						: seoFetch( '/care/history/undo', {
								method: 'POST',
								data: payload,
							} ),
				__( 'Undone.', 'zinn-seo' )
			)
			.then( ( r ) => {
				if ( r ) {
					load.reload();
					reload();
				}
			} );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'History and undo', 'zinn-seo' ) }</h2>
				<p>
					{ __(
						'Every change site care made, newest first. Undo one change, or a whole run at once. Backups are kept for the number of days you chose under Schedules and alerts.',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="care" />
				</p>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show', 'zinn-seo' ) }
					value={ area }
					options={ [
						{ value: '', label: __( 'Everything', 'zinn-seo' ) },
						{
							value: 'malware',
							label: __( 'Malware', 'zinn-seo' ),
						},
						{
							value: 'errors',
							label: __( 'Error logs', 'zinn-seo' ),
						},
						{
							value: 'database',
							label: __( 'Database', 'zinn-seo' ),
						},
						{
							value: 'fixes',
							label: __( 'Code fixes', 'zinn-seo' ),
						},
						{
							value: 'guard',
							label: __( 'Update Guard', 'zinn-seo' ),
						},
					] }
					onChange={ setArea }
				/>
				{ act.done && (
					<Notice status="success" onRemove={ act.clear }>
						{ act.done }
					</Notice>
				) }
				<Refusal error={ act.error } onRemove={ act.clear } />
				<Loaded load={ load }>
					{ ( h ) => (
						<>
							<p>
								{ sprintf(
									/* translators: 1: backups kept, 2: their size. */
									__(
										'%1$s backups kept, %2$s.',
										'zinn-seo'
									),
									count( h.vault.items ),
									bytes( h.vault.bytes )
								) }
							</p>
							<Table
								label={ __( 'Changes', 'zinn-seo' ) }
								rowKey={ ( r ) => r.id }
								rows={ h.rows }
								columns={ [
									{
										id: 'at',
										label: __( 'When', 'zinn-seo' ),
										render: ( r ) => when( r.at ),
									},
									{
										id: 'what',
										label: __( 'What', 'zinn-seo' ),
										render: ( r ) => (
											<>
												{ r.summary }
												<br />
												<bdi className="zseo-break zseo-muted">
													{ r.target }
												</bdi>
											</>
										),
									},
									{
										id: 'by',
										label: __( 'By', 'zinn-seo' ),
										render: ( r ) => <bdi>{ r.by }</bdi>,
									},
									{
										id: 'undo',
										label: __( 'Undo', 'zinn-seo' ),
										render: ( r ) =>
											r.status === 'undone' ? (
												<span className="zseo-muted">
													{ __(
														'Undone',
														'zinn-seo'
													) }
												</span>
											) : (
												<div className="zseo-actions zseo-actions--start">
													{ r.undoable && (
														<Button
															variant="secondary"
															disabled={
																!! act.busy
															}
															onClick={ () =>
																undo( {
																	id: r.id,
																} )
															}
														>
															{ __(
																'Undo',
																'zinn-seo'
															) }
														</Button>
													) }
													{ r.undoable && r.run && (
														<Button
															variant="tertiary"
															disabled={
																!! act.busy
															}
															onClick={ () =>
																undo( {
																	run: r.run,
																} )
															}
														>
															{ __(
																'Undo the whole run',
																'zinn-seo'
															) }
														</Button>
													) }
												</div>
											),
									},
								] }
							/>
						</>
					) }
				</Loaded>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'I understand: emptying the backups means these changes can no longer be undone.',
						'zinn-seo'
					) }
					checked={ sure }
					onChange={ setSure }
				/>
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="secondary"
						isDestructive
						disabled={ ! sure || !! act.busy }
						onClick={ () =>
							act
								.run(
									'empty',
									() =>
										seoFetch( '/care/history/empty', {
											method: 'POST',
										} ),
									__(
										'The backups were emptied.',
										'zinn-seo'
									)
								)
								.then( ( r ) => {
									if ( r ) {
										setSure( false );
										load.reload();
									}
								} )
						}
					>
						{ __( 'Empty the backups now', 'zinn-seo' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * Schedules and alerts (the module's settings).
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function ScheduleTab( { data } ) {
	const { settings, save, error } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const act = useAction();
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! settings ) {
		return null;
	}
	const v = { ...settings, ...draft };
	const change = ( part ) => setDraft( { ...draft, ...part } );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2>{ __( 'Schedules and alerts', 'zinn-seo' ) }</h2>
				<p>
					{ __(
						'Scheduled work runs in the background, a little at a time, never on a visitor’s page view.',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="care" />
				</p>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Scan for malware', 'zinn-seo' ) }
					value={ v.care_scan_schedule }
					options={ [
						{
							value: 'off',
							label: __( 'Only when I ask', 'zinn-seo' ),
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
					onChange={ ( value ) =>
						change( { care_scan_schedule: value } )
					}
					help={ __(
						'You get an e-mail (and a message on the alert channels you switched on) when a scan finds something new.',
						'zinn-seo'
					) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Tell me about a new fatal error at once',
						'zinn-seo'
					) }
					checked={ !! v.care_alert_fatal }
					onChange={ ( value ) =>
						change( { care_alert_fatal: value } )
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Clean up the database', 'zinn-seo' ) }
					value={ v.care_db_schedule }
					options={ [
						{
							value: 'off',
							label: __( 'Only when I ask', 'zinn-seo' ),
						},
						{
							value: 'weekly',
							label: __( 'Every week', 'zinn-seo' ),
						},
						{
							value: 'monthly',
							label: __( 'Every month', 'zinn-seo' ),
						},
					] }
					onChange={ ( value ) =>
						change( { care_db_schedule: value } )
					}
					help={ __(
						'A scheduled clean-up removes only the safe kinds (never the trash) and backs everything up first.',
						'zinn-seo'
					) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					max={ 100 }
					label={ __(
						'Revisions to keep for each page',
						'zinn-seo'
					) }
					value={ String( v.care_revisions_keep ) }
					onChange={ ( n ) =>
						change( {
							care_revisions_keep: Math.max(
								0,
								Math.min( 100, parseInt( n, 10 ) || 0 )
							),
						} )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Make a full database backup with Zinn® Backups before a clean-up (when it is installed)',
						'zinn-seo'
					) }
					checked={ !! v.care_full_backup }
					onChange={ ( value ) =>
						change( { care_full_backup: value } )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					max={ 365 }
					label={ __(
						'Days to keep backups of changes',
						'zinn-seo'
					) }
					help={ __(
						'After this, a change can no longer be undone.',
						'zinn-seo'
					) }
					value={ String( v.care_keep_days ) }
					onChange={ ( n ) =>
						change( {
							care_keep_days: Math.max(
								1,
								Math.min( 365, parseInt( n, 10 ) || 1 )
							),
						} )
					}
				/>
				{ act.done && (
					<Notice status="success" onRemove={ act.clear }>
						{ act.done }
					</Notice>
				) }
				<Refusal error={ act.error } onRemove={ act.clear } />
				<div className="zseo-actions zseo-actions--start">
					<Button
						variant="primary"
						disabled={
							! Object.keys( draft ).length || !! act.busy
						}
						isBusy={ act.busy === 'save' }
						onClick={ () =>
							act
								.run(
									'save',
									() => save( draft ),
									__( 'Saved.', 'zinn-seo' )
								)
								.then( ( r ) => r && setDraft( {} ) )
						}
					>
						{ __( 'Save', 'zinn-seo' ) }
					</Button>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function CareScreen( { data } ) {
	const mods = data.modules || {};
	if ( ! data.care ) {
		return data.plan === 'free' || mods[ 'site-care' ] === undefined ? (
			<div className="zseo-admin">
				<ProTeaser
					title={ __( 'Site care with AI', 'zinn-seo' ) }
					points={ [
						__(
							'Find malware: every file checked against the official copies, known malware code, hidden administrators, injected scripts and spam links.',
							'zinn-seo'
						),
						__(
							'Clean it up in one click: backed up first, moved to quarantine, never deleted, undoable.',
							'zinn-seo'
						),
						__(
							'Your error logs read for you, explained in plain words, with the fix.',
							'zinn-seo'
						),
						__(
							'A safe database clean-up with sizes first and a backup before anything goes.',
							'zinn-seo'
						),
						__(
							'Small code fixes written by the AI, tested and switched on only when you approve.',
							'zinn-seo'
						),
					] }
				/>
			</div>
		) : (
			<div className="zseo-admin">
				<Notice status="info" isDismissible={ false }>
					{ __( 'Site care is switched off.', 'zinn-seo' ) }{ ' ' }
					<a href={ `${ viewHref( 'settings' ) }#/modules` }>
						{ __( 'Switch it on', 'zinn-seo' ) }
					</a>
				</Notice>
			</div>
		);
	}
	return <CareTabs data={ data } />;
}

/**
 * The tabs, once site care is on.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tabs.
 */
function CareTabs( { data } ) {
	const load = useLoad( '/care' );
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	const [ tab, setTab ] = useState(
		careTabs().some( ( t ) => t.name === initial ) ? initial : 'malware'
	);
	const goTo = ( name ) => {
		window.history.replaceState( null, '', `#/${ name }` );
		setTab( name );
	};
	const reload = load.reload;
	return (
		<div className="zseo-admin zseo-care">
			<Loaded load={ load }>
				{ ( care ) => (
					<TabPanel
						key={ tab }
						className="zseo-tabs"
						tabs={ careTabs().map( ( t ) => {
							const n =
								t.name === 'malware' || t.name === 'errors'
									? openLabel( care[ t.name ].counts )
									: '';
							return n
								? { ...t, title: `${ t.title } · ${ n }` }
								: t;
						} ) }
						initialTabName={ tab }
						onSelect={ ( name ) =>
							window.history.replaceState(
								null,
								'',
								`#/${ name }`
							)
						}
					>
						{ ( t ) => {
							const props = { data, care, reload, goTo };
							switch ( t.name ) {
								case 'errors':
									return <ErrorsTab { ...props } />;
								case 'database':
									return <DatabaseTab { ...props } />;
								case 'fixes':
									return <FixesTab { ...props } />;
								case 'history':
									return <HistoryTab { ...props } />;
								case 'schedule':
									return <ScheduleTab { ...props } />;
								default:
									return <MalwareTab { ...props } />;
							}
						} }
					</TabPanel>
				) }
			</Loaded>
		</div>
	);
}
