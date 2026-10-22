/**
 * "Move from another SEO plugin": count, copy, compare every page, switch the other plugin off
 * only when the pages say the same, undo (zs-260, zs-261).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { Table } from '../p4/shared';
import {
	checkSummary,
	countRows,
	openDifferences,
	partLabel,
	preflightItems,
	proven,
	sameCounts,
	showValue,
	stage,
	total,
} from './pure';
import GuideLink from '../p7/GuideLink';
import TeamMove from '../../offers/TeamMove';
import './p6.scss';

/**
 * The state of the move, re-read every two seconds while a job runs.
 *
 * @return {Object} {status, error, setStatus, post}.
 */
function useImport() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( '' );
	const timer = useRef( null );
	const running =
		status &&
		Object.values( status.jobs || {} ).some(
			( j ) => j.status === 'pending'
		);
	useEffect( () => {
		seoFetch( '/import' )
			.then( setStatus )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );
	useEffect( () => {
		if ( running ) {
			timer.current = setTimeout(
				() =>
					seoFetch( '/import' )
						.then( setStatus )
						.catch( () => {} ),
				2000
			);
		}
		return () => clearTimeout( timer.current );
	}, [ running, status ] );
	const post = ( path, data = {} ) => {
		setError( '' );
		return seoFetch( `/import/${ path }`, { method: 'POST', data } )
			.then( setStatus )
			.catch( ( e ) => {
				setError( errorText( e ) );
				// What the server refused may have changed the state all the same (a rollback).
				return seoFetch( '/import' )
					.then( setStatus )
					.catch( () => {} );
			} );
	};
	return { status, error, setError, post, running };
}

/**
 * A job's progress.
 *
 * @param {Object} props
 * @param {Object} props.job  Job (`status`, `progress`, `error`).
 * @param {string} props.text What it is doing.
 * @return {Element|null} Line.
 */
function Progress( { job, text } ) {
	if ( ! job || ( job.status !== 'pending' && job.status !== 'failed' ) ) {
		return null;
	}
	if ( job.status === 'failed' ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ job.error ||
					__(
						'The background job stopped with an error.',
						'zinn-seo'
					) }
			</Notice>
		);
	}
	const p = job.progress || {};
	const pct =
		p.total > 0
			? Math.min( 100, Math.round( ( 100 * ( p.done || 0 ) ) / p.total ) )
			: null;
	return (
		<div className="zseo-move-progress" role="status">
			<p className="zseo-job">
				<Spinner />
				{ text }{ ' ' }
				{ p.total > 0 &&
					sprintf(
						/* translators: 1: done, 2: total. */
						__( '%1$d of %2$d.', 'zinn-seo' ),
						p.done || 0,
						p.total
					) }{ ' ' }
				{ __( 'You can leave this screen.', 'zinn-seo' ) }
			</p>
			{ pct !== null && (
				<progress
					className="zseo-move-bar"
					max="100"
					value={ pct }
					aria-label={ text }
				/>
			) }
		</div>
	);
}

/**
 * A count's table.
 *
 * @param {Object} props
 * @param {Object} props.counts Counts.
 * @param {string} props.label  Accessible name.
 * @return {Element} Table and notes.
 */
function Counts( { counts, label } ) {
	const rows = countRows( counts );
	const r = counts.redirects || {};
	return (
		<div className="zseo-stack">
			<dl className="zseo-totals">
				<div>
					<dt>{ __( 'Posts and pages', 'zinn-seo' ) }</dt>
					<dd>{ Number( counts.posts ) || 0 }</dd>
				</div>
				<div>
					<dt>{ __( 'Categories and tags', 'zinn-seo' ) }</dt>
					<dd>{ Number( counts.terms ) || 0 }</dd>
				</div>
				<div>
					<dt>{ __( 'Authors', 'zinn-seo' ) }</dt>
					<dd>{ Number( counts.users ) || 0 }</dd>
				</div>
				<div>
					<dt>{ __( 'Values in all', 'zinn-seo' ) }</dt>
					<dd>{ total( counts ) }</dd>
				</div>
			</dl>
			<Table
				columns={ [
					{
						id: 'label',
						label: __( 'What', 'zinn-seo' ),
						render: ( row ) => row.label,
					},
					{
						id: 'n',
						label: __( 'How many', 'zinn-seo' ),
						render: ( row ) => String( row.n ),
					},
				] }
				rows={ rows }
				label={ label }
				empty={ __(
					'Nothing to bring over: every value is already here.',
					'zinn-seo'
				) }
			/>
			{ ( Number( counts.kept ) > 0 ||
				Number( counts.same ) > 0 ||
				Number( counts.refused ) > 0 ||
				Number( r.refused ) > 0 ) && (
				<ul className="zseo-changes zseo-muted">
					{ Number( counts.same ) > 0 && (
						<li>
							{ sprintf(
								/* translators: %d: number of values. */
								__(
									'%d values are already here with the same value.',
									'zinn-seo'
								),
								Number( counts.same )
							) }
						</li>
					) }
					{ Number( counts.kept ) > 0 && (
						<li>
							{ sprintf(
								/* translators: %d: number of values. */
								__(
									'%d values are already set here to something else and are left as they are.',
									'zinn-seo'
								),
								Number( counts.kept )
							) }
						</li>
					) }
					{ Number( counts.refused ) + Number( r.refused || 0 ) >
						0 && (
						<li>
							{ sprintf(
								/* translators: %d: number of values. */
								__(
									'%d values cannot be stored (too long, or not a valid address).',
									'zinn-seo'
								),
								Number( counts.refused ) +
									Number( r.refused || 0 )
							) }
						</li>
					) }
				</ul>
			) }
		</div>
	);
}

/**
 * The list of differences a comparison found.
 *
 * @param {Object} props
 * @param {string} props.name  The other plugin's name.
 * @param {number} props.count How many differences there are in all.
 * @return {Element} Table.
 */
function Differences( { name, count } ) {
	const [ rows, setRows ] = useState( null );
	useEffect( () => {
		seoFetch( '/import/differences' )
			.then( setRows )
			.catch( () => setRows( [] ) );
	}, [ count ] );
	if ( ! rows ) {
		return <Spinner />;
	}
	const flat = [];
	rows.forEach( ( row, i ) => {
		Object.keys( row.fields || {} ).forEach( ( part ) => {
			flat.push( {
				id: `${ i }-${ part }`,
				label: row.label || row.url,
				url: row.url,
				edit: row.edit,
				kind: row.kind,
				part,
				theirs: row.fields[ part ][ 0 ],
				ours: row.fields[ part ][ 1 ],
			} );
		} );
	} );
	return (
		<div className="zseo-stack zseo-move-diffs">
			<Table
				columns={ [
					{
						id: 'page',
						label: __( 'Page', 'zinn-seo' ),
						render: ( r ) => (
							<span className="zseo-move-page">
								{ r.edit ? (
									<a href={ r.edit }>{ r.label }</a>
								) : (
									r.label
								) }
								{ 'redirect' !== r.kind && (
									<a
										className="zseo-address"
										href={ r.url }
										target="_blank"
										rel="noreferrer"
									>
										{ r.url }
									</a>
								) }
							</span>
						),
					},
					{
						id: 'part',
						label: __( 'What differs', 'zinn-seo' ),
						render: ( r ) => partLabel( r.part ),
					},
					{
						id: 'theirs',
						label: name,
						render: ( r ) => (
							// A long value scrolls: reachable from the keyboard too (axe
							// scrollable-region-focusable).
							<span
								className="zseo-move-value"
								tabIndex={ 0 }
								aria-label={ name }
							>
								{ showValue( r.theirs ) }
							</span>
						),
					},
					{
						id: 'ours',
						label: __( 'Zinn® SEO', 'zinn-seo' ),
						render: ( r ) => (
							<span
								className="zseo-move-value"
								tabIndex={ 0 }
								aria-label={ __( 'Zinn® SEO', 'zinn-seo' ) }
							>
								{ showValue( r.ours ) }
							</span>
						),
					},
				] }
				rows={ flat }
				label={ __( 'Differences', 'zinn-seo' ) }
				empty=""
			/>
			{ count > rows.length && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: 1: pages listed, 2: pages that differ. */
						__(
							'The first %1$d of %2$d pages that differ are listed. Every page was compared.',
							'zinn-seo'
						),
						rows.length,
						count
					) }
				</p>
			) }
		</div>
	);
}

/**
 * What the preflight found, a line per item.
 *
 * @param {Object} props
 * @param {Object} props.preflight The preflight.
 * @return {Element} List.
 */
function Preflight( { preflight } ) {
	const mark = { ok: '✓', note: 'i', stop: '✕' };
	return (
		<ul className="zseo-move-checks">
			{ preflightItems( preflight ).map( ( item ) => (
				<li key={ item.id } className={ `is-${ item.level }` }>
					<span className="zseo-move-mark" aria-hidden="true">
						{ mark[ item.level ] || '•' }
					</span>
					<span>
						<span className="screen-reader-text">
							{ {
								ok: __( 'Fine:', 'zinn-seo' ),
								note: __( 'Note:', 'zinn-seo' ),
								stop: __( 'Stops the move:', 'zinn-seo' ),
							}[ item.level ] || '' }
						</span>{ ' ' }
						{ item.text }
					</span>
				</li>
			) ) }
		</ul>
	);
}

/**
 * The copy read back from the database.
 *
 * @param {Object} props
 * @param {Object} props.proof The read-back.
 * @return {Element|null} Result.
 */
function Proof( { proof } ) {
	if ( ! proof || ! proof.finished ) {
		return null;
	}
	const v = proof.values || {};
	const s = proof.settings || {};
	const r = proof.redirects || {};
	if ( proof.ok ) {
		return (
			<p className="zseo-ok">
				{ sprintf(
					/* translators: 1: values read back, 2: settings, 3: redirects. */
					__(
						'Everything copied is safely stored: %1$d values, %2$d settings and %3$d redirects were read back from your database.',
						'zinn-seo'
					),
					Number( v.stored ) || 0,
					Number( s.written ) || 0,
					Number( r.present ) || 0
				) }
				{ Number( v.changed ) > 0 &&
					' ' +
						sprintf(
							/* translators: %d: number of values. */
							__(
								'%d values were changed by you since, and that is fine.',
								'zinn-seo'
							),
							Number( v.changed )
						) }
			</p>
		);
	}
	return (
		<Notice status="error" isDismissible={ false }>
			<p>
				{ __(
					'Not everything copied is stored on this site, so nothing more will change. Your old plugin keeps serving your pages. Undo the import and copy again; if it happens again, contact support with this list.',
					'zinn-seo'
				) }
			</p>
			<ul className="zseo-changes">
				{ ( s.missing || [] ).length > 0 && (
					<li>
						{ sprintf(
							/* translators: %s: setting names. */
							__( 'Settings not stored: %s', 'zinn-seo' ),
							s.missing.join( ', ' )
						) }
					</li>
				) }
				{ Number( v.lost ) > 0 && (
					<li>
						{ sprintf(
							/* translators: %d: number of values. */
							__( '%d values are missing.', 'zinn-seo' ),
							Number( v.lost )
						) }{ ' ' }
						{ ( v.listed || [] )
							.map(
								( l ) => `${ l.kind } ${ l.id }: ${ l.field }`
							)
							.join( '; ' ) }
					</li>
				) }
				{ Number( r.expected ) > Number( r.present ) && (
					<li>
						{ sprintf(
							/* translators: 1: redirects found, 2: redirects copied. */
							__(
								'%1$d of %2$d copied redirects are in place.',
								'zinn-seo'
							),
							Number( r.present ) || 0,
							Number( r.expected ) || 0
						) }
					</li>
				) }
			</ul>
		</Notice>
	);
}

/**
 * Which caches were emptied after the switch, and which are left to the person.
 *
 * @param {Object} props
 * @param {Object} props.purge The purge (`purged`, `manual`).
 * @return {Element|null} Lines.
 */
function Caches( { purge } ) {
	if ( ! purge ) {
		return null;
	}
	const purged = purge.purged || [];
	const manual = purge.manual || [];
	return (
		<>
			{ purged.length > 0 && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %s: cache names. */
						__(
							'Emptied so visitors see the new pages at once: %s.',
							'zinn-seo'
						),
						purged.join( ', ' )
					) }
				</p>
			) }
			{ manual.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: cache names. */
						__(
							'Empty this cache yourself now: %s. Until you do, visitors and search engines may still get the pages as your old plugin printed them.',
							'zinn-seo'
						),
						manual.join( ', ' )
					) }
				</Notice>
			) }
			{ ! purged.length && ! manual.length && (
				<p className="zseo-muted">
					{ __(
						'No page cache was found. If your host or a CDN keeps copies of your pages, empty it now so visitors see the new pages at once.',
						'zinn-seo'
					) }
				</p>
			) }
		</>
	);
}

/**
 * One numbered step.
 *
 * @param {Object}  props
 * @param {number}  props.n        Number.
 * @param {string}  props.title    Title.
 * @param {string}  props.state    todo|now|done.
 * @param {Element} props.children Body.
 * @return {Element} Card.
 */
function Step( { n, title, state, children } ) {
	return (
		<Card className={ `zseo-move-step is-${ state }` }>
			<CardHeader>
				<h3 className="zseo-card-title">
					<span className="zseo-move-n" aria-hidden="true">
						{ 'done' === state ? '✓' : n }
					</span>
					{ title }
					{ 'done' === state && (
						<span className="screen-reader-text">
							{ __( '(done)', 'zinn-seo' ) }
						</span>
					) }
				</h3>
			</CardHeader>
			<CardBody className="zseo-stack">{ children }</CardBody>
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
export default function ImportScreen( { data } ) {
	const { status, error, setError, post, running } = useImport();
	const [ picked, setPicked ] = useState( '' );
	const [ overwrite, setOverwrite ] = useState( false );
	const [ keep, setKeep ] = useState( true );
	const [ confirm, setConfirm ] = useState( '' );
	const manage = !! data.canManage;

	if ( ! status ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const state = status.state || {};
	const inMove = state.source && ! state.undone && state.begun > 0;
	const sources = status.sources || [];
	const id =
		( inMove && state.source ) ||
		picked ||
		( sources[ 0 ] && sources[ 0 ].id ) ||
		'';
	const source = sources.find( ( s ) => s.id === id );
	const jobs = status.jobs || {};

	if ( ! sources.length ) {
		return (
			<div className="zseo-admin zseo-stack zseo-move">
				<h2>{ __( 'Move from another SEO plugin', 'zinn-seo' ) }</h2>
				<Card>
					<CardBody className="zseo-stack">
						<p className="zseo-ok">
							{ __(
								'No other SEO plugin, and nothing one left behind, was found on this site.',
								'zinn-seo'
							) }
						</p>
						<p className="zseo-muted">
							{ __(
								'Zinn® SEO can bring everything over from Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO, Squirrly SEO, SmartCrawl, Redirection and Safe Redirect Manager. Install or activate one and it will appear here.',
								'zinn-seo'
							) }
						</p>
					</CardBody>
				</Card>
			</div>
		);
	}

	const name = source ? source.name : '';
	const at = source ? stage( status, source ) : 'count';
	const mine = state.source === id && ! state.undone;
	// A count changes nothing, so it does not start a move: it is this plugin's when it names it.
	const dry = state.dry && state.dry.source === id ? state.dry : null;
	const applied = mine ? state.applied : null;
	const check = mine ? state.check : null;
	const proof = mine ? state.proof : null;
	const isProven = mine && proven( state );
	const preflight =
		state.preflight && state.preflight.source === id
			? state.preflight
			: null;
	const blocked = !! ( preflight && preflight.blocked );
	const open = openDifferences( check );
	const passed = check && check.finished && ( 0 === open || check.accepted );
	const done = 'done' === at;
	const off = 'off' === at;
	const order = [ 'count', 'copy', 'check', 'switch', 'done' ];
	const stepState = ( step ) => {
		const now = order.indexOf( off ? 'done' : at );
		const mineAt = order.indexOf( step );
		if ( mineAt < now ) {
			return 'done';
		}
		return mineAt === now ? 'now' : 'todo';
	};
	const busy = !! running;

	return (
		<div className="zseo-admin zseo-stack zseo-move">
			<div>
				<h2>{ __( 'Move from another SEO plugin', 'zinn-seo' ) }</h2>
				<p className="zseo-muted">
					{ __(
						'Bring everything over, check that every page still says the same to search engines, and only then switch the other plugin off. Nothing of the other plugin is changed or deleted, and every step can be undone.',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="import" />
				</p>
			</div>
			{ ! done && ! off && ! inMove && (
				<TeamMove data={ data } source={ id } />
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ status.both && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'"Print alongside other SEO plugins" is switched on, so both plugins are printing tags on every page. Switch it off under Search appearance before you compare.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ status.live && ! applied && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: the name of another SEO plugin. */
						__(
							'Zinn® SEO is reading what %s stored, page by page, so your titles and descriptions stay as they were. Copy the data in to finish, or stop reading it.',
							'zinn-seo'
						),
						name
					) }{ ' ' }
					{ manage && (
						<Button
							variant="link"
							disabled={ busy }
							onClick={ () => post( 'stop' ) }
						>
							{ __( 'Stop reading it', 'zinn-seo' ) }
						</Button>
					) }
				</Notice>
			) }
			{ state.rollback && ! state.switched && (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: %s: the name of another SEO plugin. */
							__(
								'After %s was switched off, something on your pages was not the same as before, so it was switched back on at once and the move was undone. Your site is as it was. Nothing was lost.',
								'zinn-seo'
							),
							name
						) }
					</p>
					<ul className="zseo-changes">
						{ ( state.rollback.failed || [] ).map( ( f, i ) => (
							<li key={ i }>
								<span className="zseo-address">{ f.url }</span>
								{ ': ' }
								{ Object.keys( f.fields || {} )
									.map(
										( part ) =>
											`${ partLabel(
												part
											) } — ${ showValue(
												f.fields[ part ][ 0 ]
											) } → ${ showValue(
												f.fields[ part ][ 1 ]
											) }`
									)
									.join( '; ' ) }
							</li>
						) ) }
					</ul>
				</Notice>
			) }

			{ state.switch_error && ! state.switched && (
				<Notice status="error" isDismissible={ false }>
					{ state.switch_error }
				</Notice>
			) }
			{ state.rollback && ! state.switched && (
				<Caches purge={ state.purge } />
			) }

			{ ! inMove && sources.length > 1 && (
				<fieldset className="zseo-move-sources">
					<legend className="zseo-card-title">
						{ __( 'Found on this site', 'zinn-seo' ) }
					</legend>
					{ sources.map( ( s ) => (
						<label
							key={ s.id }
							htmlFor={ `zseo-move-source-${ s.id }` }
							className={
								s.id === id
									? 'zseo-move-source is-picked'
									: 'zseo-move-source'
							}
						>
							<input
								type="radio"
								id={ `zseo-move-source-${ s.id }` }
								name="zseo-move-source"
								value={ s.id }
								checked={ s.id === id }
								onChange={ () => setPicked( s.id ) }
							/>
							<span className="zseo-move-source__name">
								{ s.name }
							</span>
							<span className="zseo-chip">
								{ s.active
									? __( 'Active', 'zinn-seo' )
									: __( 'Switched off', 'zinn-seo' ) }
							</span>
							{ s.redirects && (
								<span className="zseo-chip">
									{ __( 'Redirects only', 'zinn-seo' ) }
								</span>
							) }
						</label>
					) ) }
				</fieldset>
			) }
			{ ( inMove || sources.length === 1 ) && source && (
				<p className="zseo-move-from">
					<strong>{ name }</strong>{ ' ' }
					<span className="zseo-chip">
						{ source.active
							? __( 'Active', 'zinn-seo' )
							: __( 'Switched off', 'zinn-seo' ) }
					</span>
				</p>
			) }

			{ source && (
				<>
					<Card className="zseo-move-step zseo-move-preflight">
						<CardHeader>
							<h3 className="zseo-card-title">
								{ __( 'Before you start', 'zinn-seo' ) }
							</h3>
						</CardHeader>
						<CardBody className="zseo-stack">
							<p>
								{ __(
									'A quick look at your site: the plugin you move from, page caches, languages, and whether every step can be checked on your real pages. Nothing changes.',
									'zinn-seo'
								) }
							</p>
							{ preflight && (
								<Preflight preflight={ preflight } />
							) }
							{ blocked && (
								<Notice status="error" isDismissible={ false }>
									{ __(
										'Fix what is marked first. Until then nothing is copied and your old plugin keeps working as it does now.',
										'zinn-seo'
									) }
								</Notice>
							) }
							{ manage && (
								<div className="zseo-actions zseo-actions--start">
									<Button
										variant={
											preflight ? 'secondary' : 'primary'
										}
										disabled={ busy }
										onClick={ () =>
											post( 'preflight', { source: id } )
										}
									>
										{ preflight
											? __( 'Check again', 'zinn-seo' )
											: __(
													'Check this site',
													'zinn-seo'
												) }
									</Button>
								</div>
							) }
						</CardBody>
					</Card>
					<Step
						n={ 1 }
						title={ __( 'See what would come over', 'zinn-seo' ) }
						state={ stepState( 'count' ) }
					>
						<p>
							{ __(
								'A count of everything the copy would bring over. Nothing is written.',
								'zinn-seo'
							) }
						</p>
						{ status.running && status.running.dry && (
							<Progress
								job={ jobs.import }
								text={ __( 'Counting.', 'zinn-seo' ) }
							/>
						) }
						{ dry && (
							<Counts
								counts={ dry.counts }
								label={ __(
									'What would come over',
									'zinn-seo'
								) }
							/>
						) }
						{ ( source.notes || [] ).length > 0 && (
							<details className="zseo-details">
								<summary>
									{ __(
										'What is not brought over',
										'zinn-seo'
									) }
								</summary>
								<ul className="zseo-changes">
									{ source.notes.map( ( note, i ) => (
										<li key={ i }>{ note }</li>
									) ) }
								</ul>
							</details>
						) }
						{ manage && ! applied && (
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant={ dry ? 'secondary' : 'primary' }
									disabled={ busy }
									onClick={ () =>
										post( 'count', {
											source: id,
											overwrite,
										} )
									}
								>
									{ dry
										? __( 'Count again', 'zinn-seo' )
										: __( 'Count', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Step>

					<Step
						n={ 2 }
						title={ __( 'Copy it into Zinn® SEO', 'zinn-seo' ) }
						state={ stepState( 'copy' ) }
					>
						{ ! applied && (
							<>
								<p>
									{ __(
										"The copy runs in the background, a hundred pages at a time, and carries on by itself if it is interrupted. Until it reaches a page, that page reads the other plugin's data directly, so nothing changes for visitors while it runs.",
										'zinn-seo'
									) }
								</p>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Replace values already set in Zinn® SEO',
										'zinn-seo'
									) }
									help={ __(
										'Off: a page that already has a title or description here keeps it.',
										'zinn-seo'
									) }
									checked={ overwrite }
									disabled={ busy }
									onChange={ setOverwrite }
								/>
							</>
						) }
						{ status.running && ! status.running.dry && (
							<Progress
								job={ jobs.import }
								text={ __( 'Copying.', 'zinn-seo' ) }
							/>
						) }
						{ applied &&
							( dry &&
							sameCounts( dry.counts, applied.counts ) ? (
								<>
									<p className="zseo-ok">
										{ sprintf(
											/* translators: 1: number of values counted, 2: number of values copied. */
											__(
												'Counted %1$d, copied %2$d: the same numbers.',
												'zinn-seo'
											),
											total( dry.counts ),
											total( applied.counts )
										) }
									</p>
									<details className="zseo-details">
										<summary>
											{ __(
												'What was copied',
												'zinn-seo'
											) }
										</summary>
										<div className="zseo-stack">
											<Counts
												counts={ applied.counts }
												label={ __(
													'What was copied',
													'zinn-seo'
												) }
											/>
										</div>
									</details>
								</>
							) : (
								<Counts
									counts={ applied.counts }
									label={ __(
										'What was copied',
										'zinn-seo'
									) }
								/>
							) ) }
						{ applied && (
							<Progress
								job={ jobs[ 'import-proof' ] }
								text={ __(
									'Checking that everything copied is stored.',
									'zinn-seo'
								) }
							/>
						) }
						{ applied && <Proof proof={ proof } /> }
						{ manage &&
							applied &&
							! done &&
							! isProven &&
							! (
								jobs[ 'import-proof' ] &&
								jobs[ 'import-proof' ].status === 'pending'
							) && (
								<div className="zseo-actions zseo-actions--start">
									<Button
										variant="secondary"
										disabled={ busy }
										onClick={ () => post( 'verify' ) }
									>
										{ __( 'Check the copy', 'zinn-seo' ) }
									</Button>
								</div>
							) }
						{ manage && ! applied && (
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant={ dry ? 'primary' : 'secondary' }
									disabled={ busy || blocked }
									onClick={ () =>
										post( 'copy', {
											source: id,
											overwrite,
										} )
									}
								>
									{ dry
										? sprintf(
												/* translators: %d: number of values. */
												__(
													'Copy %d values',
													'zinn-seo'
												),
												total( dry.counts )
											)
										: __( 'Copy', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Step>

					<Step
						n={ 3 }
						title={ __( 'Compare every page', 'zinn-seo' ) }
						state={ stepState( 'check' ) }
					>
						{ off ? (
							<p>
								{ sprintf(
									/* translators: %s: the name of another SEO plugin. */
									__(
										'%s is switched off, so there is no page of its to compare with. Zinn® SEO is already writing every page from the data it copied.',
										'zinn-seo'
									),
									name
								) }
							</p>
						) : (
							<>
								<p>
									{ sprintf(
										/* translators: %s: the name of another SEO plugin. */
										__(
											'Every published page is fetched as %s shows it today and set beside what Zinn® SEO would print: title, description, canonical address, search engine directives, what a shared link shows, and structured data.',
											'zinn-seo'
										),
										name
									) }
								</p>
								{ ! done && (
									<ToggleControl
										__nextHasNoMarginBottom
										label={ sprintf(
											/* translators: %s: the name of another SEO plugin. */
											__(
												'Keep exactly what %s showed',
												'zinn-seo'
											),
											name
										) }
										help={ __(
											"Where it wrote something itself — a description cut from the page's text, say — store those exact words for that page, so nothing changes. You can let Zinn® SEO write them instead later with one undo in the bulk editor's history.",
											'zinn-seo'
										) }
										checked={ keep }
										disabled={ busy || ! applied }
										onChange={ setKeep }
									/>
								) }
								<Progress
									job={ jobs[ 'import-check' ] }
									text={ __( 'Comparing.', 'zinn-seo' ) }
								/>
								{ check && check.finished > 0 && (
									<>
										<p
											className={
												0 === open
													? 'zseo-ok'
													: undefined
											}
										>
											{ checkSummary( check ) }
										</p>
										{ open > 0 && (
											<>
												<Notice
													status="warning"
													isDismissible={ false }
												>
													{ check.accepted
														? sprintf(
																/* translators: %d: number of differences. */
																__(
																	'%d differences, which you accepted.',
																	'zinn-seo'
																),
																open
															)
														: sprintf(
																/* translators: %d: number of differences. */
																__(
																	'%d differences are still open. Change the page or the template and compare again, or accept them.',
																	'zinn-seo'
																),
																open
															) }
												</Notice>
												<Differences
													name={ name }
													count={ open }
												/>
											</>
										) }
									</>
								) }
								{ manage && applied && ! done && (
									<div className="zseo-actions zseo-actions--start">
										<Button
											variant={
												check && check.finished
													? 'secondary'
													: 'primary'
											}
											disabled={ busy || ! isProven }
											onClick={ () =>
												post( 'check', { keep } )
											}
										>
											{ check && check.finished
												? __(
														'Compare again',
														'zinn-seo'
													)
												: __(
														'Compare every page',
														'zinn-seo'
													) }
										</Button>
										{ open > 0 &&
											check &&
											! check.accepted && (
												<Button
													variant="tertiary"
													disabled={ busy }
													onClick={ () =>
														setConfirm( 'accept' )
													}
												>
													{ __(
														'Accept these differences',
														'zinn-seo'
													) }
												</Button>
											) }
									</div>
								) }
							</>
						) }
					</Step>

					<Step
						n={ 4 }
						title={ sprintf(
							/* translators: %s: the name of another SEO plugin. */
							__( 'Switch %s off', 'zinn-seo' ),
							name
						) }
						state={ done || off ? 'done' : stepState( 'switch' ) }
					>
						{ done && state.switched && (
							<p className="zseo-ok">
								{ sprintf(
									/* translators: 1: the other plugin's name, 2: number of pages. */
									__(
										'%1$s is switched off. %2$d pages were fetched again afterwards and every one says the same as before.',
										'zinn-seo'
									),
									name,
									Number( state.switched.verified ) || 0
								) }
							</p>
						) }
						{ done && state.switched && (
							<Caches purge={ state.purge } />
						) }
						{ off && ! done && (
							<p className="zseo-ok">
								{ sprintf(
									/* translators: %s: the name of another SEO plugin. */
									__(
										'%s is already switched off.',
										'zinn-seo'
									),
									name
								) }
							</p>
						) }
						{ ! done && ! off && (
							<>
								<p>
									{ sprintf(
										/* translators: %s: the name of another SEO plugin. */
										__(
											'Only possible once the comparison shows no open difference. Just before and right after the switch, your pages, robots.txt, sitemap, language links and product details are read; if anything is lost, %s is switched straight back on and the move is undone.',
											'zinn-seo'
										),
										name
									) }
								</p>
								<Progress
									job={ jobs[ 'import-verify' ] }
									text={ __(
										'Checking your pages before and after the switch.',
										'zinn-seo'
									) }
								/>
								{ manage && (
									<div className="zseo-actions zseo-actions--start">
										<Button
											variant="primary"
											disabled={
												busy || ! passed || ! isProven
											}
											onClick={ () =>
												setConfirm( 'switch' )
											}
										>
											{ sprintf(
												/* translators: %s: the name of another SEO plugin. */
												__(
													'Switch %s off',
													'zinn-seo'
												),
												name
											) }
										</Button>
									</div>
								) }
							</>
						) }
					</Step>

					{ manage && mine && ( state.begun > 0 || applied ) && (
						<Card className="zseo-move-undo">
							<CardBody className="zseo-stack">
								<h3 className="zseo-card-title">
									{ __( 'Undo the import', 'zinn-seo' ) }
								</h3>
								<p className="zseo-muted">
									{ sprintf(
										/* translators: %s: the name of another SEO plugin. */
										__(
											'Takes back everything this import wrote — values, settings, redirects — and switches %s back on if this screen switched it off. A value you changed since is left alone.',
											'zinn-seo'
										),
										name
									) }
								</p>
								<Progress
									job={ jobs[ 'import-undo' ] }
									text={ __( 'Undoing.', 'zinn-seo' ) }
								/>
								<div className="zseo-actions zseo-actions--start">
									<Button
										variant="secondary"
										isDestructive
										disabled={ busy }
										onClick={ () => setConfirm( 'undo' ) }
									>
										{ __( 'Undo the import', 'zinn-seo' ) }
									</Button>
								</div>
							</CardBody>
						</Card>
					) }
					{ state.undone > 0 &&
						state.undo &&
						state.undo.source === id && (
							<p className="zseo-muted" role="status">
								{ sprintf(
									/* translators: 1: values removed, 2: values left alone. */
									__(
										'The last import was undone: %1$d values taken back, %2$d left alone because they were changed since.',
										'zinn-seo'
									),
									Number( state.undo.restored ) || 0,
									Number( state.undo.kept ) || 0
								) }
							</p>
						) }
				</>
			) }

			{ confirm && (
				<Modal
					title={
						{
							accept: __( 'Accept the differences?', 'zinn-seo' ),
							switch: sprintf(
								/* translators: %s: the name of another SEO plugin. */
								__( 'Switch %s off?', 'zinn-seo' ),
								name
							),
							undo: __( 'Undo the import?', 'zinn-seo' ),
						}[ confirm ]
					}
					onRequestClose={ () => setConfirm( '' ) }
					size="small"
				>
					<div className="zseo-stack">
						<p>
							{
								{
									accept: __(
										'The listed pages will say something different to search engines once the other plugin is off. Accepting opens the switch-off.',
										'zinn-seo'
									),
									switch: sprintf(
										/* translators: %s: the name of another SEO plugin. */
										__(
											'%s will be deactivated (not deleted). Your pages and key files are read before and after; if anything is lost, it is switched back on and the move is undone by itself. Page caches Zinn® SEO knows are emptied.',
											'zinn-seo'
										),
										name
									),
									undo: sprintf(
										/* translators: %s: the name of another SEO plugin. */
										__(
											'Everything this import wrote is taken back, and %s is switched on again if this screen switched it off.',
											'zinn-seo'
										),
										name
									),
								}[ confirm ]
							}
						</p>
						<div className="zseo-actions">
							<Button
								variant="tertiary"
								onClick={ () => setConfirm( '' ) }
							>
								{ __( 'Cancel', 'zinn-seo' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive={ 'undo' === confirm }
								onClick={ () => {
									const what = confirm;
									setConfirm( '' );
									post( what );
								} }
							>
								{
									{
										accept: __( 'Accept', 'zinn-seo' ),
										switch: __(
											'Switch it off',
											'zinn-seo'
										),
										undo: __( 'Undo', 'zinn-seo' ),
									}[ confirm ]
								}
							</Button>
						</div>
					</div>
				</Modal>
			) }
		</div>
	);
}
