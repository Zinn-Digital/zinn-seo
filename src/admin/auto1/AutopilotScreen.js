/**
 * Fix it on autopilot (Pro, zs-767): apply every fix the Fix it list has an automatic or AI
 * remedy for, across the whole site or one section — cost first, approve all or one by one,
 * every change undoable and a whole run undone in one click; weekly, with an e-mail summary.
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
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import { JobLine, RunButton, hashParams, useJob } from '../p4/shared';
import GuideLink from '../p7/GuideLink';
import { confirmBulk } from '../bulk/confirmBulk';
import { dollars } from '../ail1/LinkPlanTab';
import './auto1.scss';

/**
 * The body of an estimate or start request from the screen's choices (pure).
 *
 * @param {Object} choice `{ what: 'all'|'some', remedies, type, term }`.
 * @return {Object} `{ scope }`.
 */
export function scopeBody( choice ) {
	const scope = {};
	if ( choice.what === 'some' ) {
		scope.remedies = ( choice.remedies || [] ).slice();
	}
	if ( choice.issues && choice.issues.length ) {
		scope.issues = choice.issues.slice();
	}
	if ( choice.type ) {
		scope.types = [ choice.type ];
	}
	if ( Number( choice.term ) > 0 ) {
		scope.term = Number( choice.term );
	}
	return { scope };
}

/**
 * The query string of an items request (pure).
 *
 * @param {number} run    Run id.
 * @param {Object} filter `{status, remedy}`.
 * @param {number} after  Keyset cursor.
 * @return {string} Path.
 */
export function itemsPath( run, filter, after = 0 ) {
	const q = new URLSearchParams();
	q.set( 'status', filter.status || '' );
	q.set( 'remedy', filter.remedy || '' );
	q.set( 'after', String( after || 0 ) );
	q.set( 'per', '50' );
	return `/autopilot/${ run }/items?${ q.toString() }`;
}

/**
 * A row's status in plain words (pure).
 *
 * @param {string} status Status.
 * @return {string} Words.
 */
export function statusText( status ) {
	switch ( status ) {
		case 'proposed':
			return __( 'To review', 'zinn-seo' );
		case 'approved':
			return __( 'Approved', 'zinn-seo' );
		case 'applied':
			return __( 'Applied', 'zinn-seo' );
		case 'rejected':
			return __( 'Rejected', 'zinn-seo' );
		case 'kept':
			return __( 'Kept as it is', 'zinn-seo' );
		case 'failed':
			return __( 'Not possible', 'zinn-seo' );
		case 'undone':
			return __( 'Undone', 'zinn-seo' );
		case 'changed':
			return __( 'Edited since: left as it is', 'zinn-seo' );
		default:
			return status;
	}
}

/**
 * Why a fix could not be applied, in plain words (pure).
 *
 * @param {string} note Note code (or a message).
 * @return {string} Words.
 */
export function noteText( note ) {
	switch ( note ) {
		case 'changed_since':
			return __(
				'Someone changed it after the fix was found, so their change was kept.',
				'zinn-seo'
			);
		case 'locked':
			return __( 'The page is under the SEO lock.', 'zinn-seo' );
		case 'kept':
			return __( 'Marked "keep mine".', 'zinn-seo' );
		case 'excluded':
			return __(
				'Its content type is now left out of SEO checks.',
				'zinn-seo'
			);
		case 'gone':
			return __( 'It no longer exists.', 'zinn-seo' );
		case 'remedy_off':
			return __( 'That kind of fix is switched off.', 'zinn-seo' );
		case 'kept_changed':
			return __(
				'Kept: someone edited it after it was applied.',
				'zinn-seo'
			);
		case 'link_gone':
			return __(
				'The link is no longer in the page (it was edited).',
				'zinn-seo'
			);
		case 'no_match':
			return __(
				'No page of the site is close enough to replace it: unlink it, or link it by hand.',
				'zinn-seo'
			);
		case 'ai':
			return __( 'Replacement chosen by the AI.', 'zinn-seo' );
		default:
			return note || '';
	}
}

/**
 * Why a link is proposed for a fix, in plain words (pure).
 *
 * @param {string} why Reason code.
 * @return {string} Words ('' none).
 */
export function whyText( why ) {
	switch ( why ) {
		case 'redirect':
			return __( 'Goes through a redirect', 'zinn-seo' );
		case 'chain':
			return __( 'Goes through several redirects', 'zinn-seo' );
		case 'loop':
			return __( 'Redirects in a loop', 'zinn-seo' );
		case 'language':
			return __( 'Points at another language', 'zinn-seo' );
		case 'missing':
			return __( 'The page does not exist', 'zinn-seo' );
		case 'trashed':
			return __( 'The page is in the bin', 'zinn-seo' );
		case 'draft':
			return __( 'The page is not published', 'zinn-seo' );
		case 'noindex':
			return __( 'The page is hidden from search', 'zinn-seo' );
		default:
			return '';
	}
}

/**
 * What a run is waiting for, in plain words (pure).
 *
 * @param {string} reason The job's reason code.
 * @return {string} Words ('' none).
 */
export function reasonText( reason ) {
	if ( ! reason ) {
		return '';
	}
	if ( reason.indexOf( 'waiting:' ) === 0 ) {
		switch ( reason.slice( 8 ) ) {
			case 'score':
				return __( 'Scoring the pages first…', 'zinn-seo' );
			case 'link-index':
			case 'links-index':
				return __( 'Building the internal-link index…', 'zinn-seo' );
			case 'dup-scan':
				return __( 'Checking for duplicates…', 'zinn-seo' );
			case 'link-plan-build':
				return __( 'Building the site link plan…', 'zinn-seo' );
			case 'link-plan-apply':
				return __( 'Adding the approved links…', 'zinn-seo' );
			default:
				return __( 'Waiting for another background job…', 'zinn-seo' );
		}
	}
	if ( reason === 'top_up' ) {
		return __(
			'Paused: the Zinn® credits ran out. It carries on by itself after a top-up.',
			'zinn-seo'
		);
	}
	return '';
}

/**
 * A row's change as pairs of before and after (pure).
 *
 * @param {Object} row Row (`proposed`, `before`, `after`, `status`).
 * @return {Array<Object>} `{ field, before, after }`.
 */
export function changes( row ) {
	if ( row.remedy === 'outbound' ) {
		const p = row.proposed || {};
		// The attribute as written in the page (code, not words).
		const rel = ( v ) =>
			v ? `rel="${ v }"` : __( 'followed', 'zinn-seo' );
		let after = rel( p.rel_to );
		if ( p.action === 'remove' ) {
			after = __( 'Unlink (the words stay)', 'zinn-seo' );
		} else if ( p.action === 'url' ) {
			after = String( p.to || '' );
		}
		return [
			{
				field: 'link',
				before: `${ String( p.from || '' ) } (${ rel( p.rel_from ) })`,
				after,
			},
		];
	}
	if ( row.remedy === 'links-fix' ) {
		const p = row.proposed || {};
		return [
			{
				field: 'link',
				before: String( p.from || '' ),
				after: p.to
					? String( p.to )
					: __( 'Unlink (the words stay)', 'zinn-seo' ),
			},
		];
	}
	const now =
		row.status === 'applied' || row.status === 'undone'
			? row.after
			: row.proposed;
	return Object.keys( now || {} ).map( ( field ) => {
		const show = ( v ) =>
			v && typeof v === 'object'
				? JSON.stringify( v )
				: String( v || '' );
		return {
			field,
			before: show( ( row.before || {} )[ field ] ),
			after: show( ( now || {} )[ field ] ),
		};
	} );
}

/**
 * A field's name in plain words (pure).
 *
 * @param {string} field Field.
 * @return {string} Words.
 */
export function fieldText( field ) {
	switch ( field ) {
		case 'title':
			return __( 'SEO title', 'zinn-seo' );
		case 'desc':
			return __( 'Meta description', 'zinn-seo' );
		case 'alt':
			return __( 'Alt text', 'zinn-seo' );
		case 'blog_public':
			return __( 'Search engines may index the site', 'zinn-seo' );
		case 'link':
			return __( 'Link', 'zinn-seo' );
		default:
			return __( 'Structured data', 'zinn-seo' );
	}
}

/**
 * The days of the week (0 = Sunday), in the site's language (pure apart from Intl).
 *
 * @return {Array<Object>} Options.
 */
export function dayOptions() {
	const base = new Date( Date.UTC( 2026, 0, 4 ) ); // A Sunday.
	return [ 0, 1, 2, 3, 4, 5, 6 ].map( ( d ) => ( {
		value: String( d ),
		label: new Date( base.getTime() + d * 86400000 ).toLocaleDateString(
			undefined,
			{ weekday: 'long', timeZone: 'UTC' }
		),
	} ) );
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function AutopilotScreen( { data } ) {
	const [ overview, setOverview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ runId, setRunId ] = useState( null );
	const [ n, setN ] = useState( 0 );
	const reload = () => setN( ( x ) => x + 1 );
	const [ propose, setPropose ] = useJob(
		() => seoFetch( '/autopilot' ).then( ( r ) => r.jobs.propose ),
		reload
	);
	const [ apply, setApply ] = useJob(
		() => seoFetch( '/autopilot' ).then( ( r ) => r.jobs.apply ),
		reload
	);
	useEffect( () => {
		seoFetch( '/autopilot' )
			.then( ( r ) => {
				setOverview( r );
				setPropose( r.jobs.propose );
				setApply( r.jobs.apply );
				const wanted = Number(
					( window.location.hash.match( /run\/(\d+)/ ) || [] )[ 1 ]
				);
				if ( runId === null || wanted ) {
					setRunId( wanted || ( r.latest ? r.latest.id : 0 ) );
				}
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ n ] );
	if ( error && ! overview ) {
		return (
			<div className="zseo-admin">
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</div>
		);
	}
	if ( ! overview ) {
		return (
			<div className="zseo-admin">
				<Spinner />
			</div>
		);
	}
	const working =
		( propose && [ 'pending', 'paused' ].includes( propose.status ) ) ||
		( apply && [ 'pending', 'paused' ].includes( apply.status ) );
	return (
		<div className="zseo-admin zseo-stack zseo-auto">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'Fix the whole Fix it list in one go: meta descriptions, titles that repeat, alt text, structured data, internal links and the one-click fixes. You see what it will fix and what it costs first; nothing a visitor sees changes until you approve it; every change keeps the value it replaced, and a whole run undoes in one click.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="autopilot" />
			</p>
			<Jobs
				propose={ propose }
				apply={ apply }
				canManage={ data.canManage }
				onJobs={ ( j ) => {
					setPropose( j.propose );
					setApply( j.apply );
				} }
				onError={ setError }
			/>
			{ data.canManage && (
				<Start
					overview={ overview }
					disabled={ working }
					onError={ setError }
					onStarted={ ( r ) => {
						setPropose( r.job );
						setRunId( r.run );
						reload();
					} }
				/>
			) }
			{ overview.fixit &&
				overview.fixit.manual &&
				overview.fixit.manual.length > 0 && (
					<Manual overview={ overview } />
				) }
			{ overview.runs.length > 0 && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Run', 'zinn-seo' ) }
					value={ String( runId ) }
					onChange={ ( v ) => setRunId( Number( v ) ) }
					options={ overview.runs.map( ( r ) => ( {
						value: String( r.id ),
						label: sprintf(
							/* translators: 1: run number, 2: date, 3: status. */
							__( 'Run %1$d, %2$s (%3$s)', 'zinn-seo' ),
							r.id,
							new Date( r.created * 1000 ).toLocaleString(),
							runStatusText( r.status )
						),
					} ) ) }
				/>
			) }
			{ runId > 0 && (
				<Run
					key={ `${ runId }-${ n }` }
					run={ runId }
					data={ data }
					busy={ working }
					onError={ setError }
					onJob={ ( j ) => setApply( j ) }
					onChange={ reload }
				/>
			) }
			{ data.canManage && (
				<ScheduleSettings remedies={ overview.remedies } />
			) }
			{ overview.network && (
				<p className="zseo-muted">
					<a href={ overview.network }>
						{ __(
							'Run it on every site of the network',
							'zinn-seo'
						) }
					</a>
				</p>
			) }
		</div>
	);
}

/**
 * A fix's cost in words: on the owner's own key, free, or dollars of credit (pure).
 *
 * @param {number|null} micros Micro-USD (null: the owner's own AI key pays).
 * @return {string} Words.
 */
export function costText( micros ) {
	if ( micros === null || micros === undefined ) {
		return __( 'Your own AI key', 'zinn-seo' );
	}
	return micros === 0 ? __( 'Free', 'zinn-seo' ) : dollars( micros );
}

/**
 * A row's cost in words, with what its AI step may add for each item it makes (ZH6-8: the links
 * fix read as free, then paid for AI replacements of gone pages) (pure).
 *
 * @param {Object}      row                Estimate row.
 * @param {number|null} row.micros         The row's cost.
 * @param {number|null} row.ai_item_micros Each AI step item's price (null: none on credit).
 * @return {string} Words.
 */
export function rowCostText( row ) {
	if ( ! row.ai_item_micros || row.ai_item_micros <= 0 ) {
		return costText( row.micros );
	}
	return sprintf(
		/* translators: 1: the fix's own cost ("Free" or an amount in US dollars), 2: an amount in US dollars. */
		__(
			'%1$s, plus %2$s for each gone page the AI finds a replacement for',
			'zinn-seo'
		),
		costText( row.micros ),
		dollars( row.ai_item_micros )
	);
}

/**
 * A run's status in plain words (pure).
 *
 * @param {string} status Status.
 * @return {string} Words.
 */
export function runStatusText( status ) {
	switch ( status ) {
		case 'proposing':
			return __( 'finding fixes', 'zinn-seo' );
		case 'review':
			return __( 'ready for review', 'zinn-seo' );
		case 'applying':
			return __( 'applying', 'zinn-seo' );
		case 'applied':
			return __( 'applied', 'zinn-seo' );
		case 'undoing':
			return __( 'undoing', 'zinn-seo' );
		case 'undone':
			return __( 'undone', 'zinn-seo' );
		case 'cancelled':
			return __( 'stopped', 'zinn-seo' );
		default:
			return status;
	}
}

/**
 * The two jobs, with pause, resume and stop.
 *
 * @param {Object}   props
 * @param {Object}   props.propose   Propose job.
 * @param {Object}   props.apply     Apply job.
 * @param {boolean}  props.canManage May control.
 * @param {Function} props.onJobs    New job records.
 * @param {Function} props.onError   Error.
 * @return {Element} Lines.
 */
function Jobs( { propose, apply, canManage, onJobs, onError } ) {
	const control = ( job, action ) =>
		seoFetch( '/autopilot/control', {
			method: 'POST',
			data: { job, action },
		} ).then( ( r ) => onJobs( r.jobs ) );
	return (
		<>
			{ [
				[ 'propose', propose ],
				[ 'apply', apply ],
			].map( ( [ id, job ] ) =>
				job && job.status !== 'idle' ? (
					<div key={ id } className="zseo-auto__job">
						<JobLine job={ job } />
						{ reasonText( job.reason ) && (
							<p className="zseo-muted" role="status">
								{ reasonText( job.reason ) }
							</p>
						) }
						{ job.status === 'paused' && job.error && (
							<Notice status="warning" isDismissible={ false }>
								{ job.error }
							</Notice>
						) }
						{ canManage &&
							[ 'pending', 'paused' ].includes( job.status ) && (
								<p className="zseo-auto__actions">
									{ job.status === 'pending' ? (
										<RunButton
											onError={ onError }
											run={ () => control( id, 'pause' ) }
										>
											{ __( 'Pause', 'zinn-seo' ) }
										</RunButton>
									) : (
										<RunButton
											variant="primary"
											onError={ onError }
											run={ () =>
												control( id, 'resume' )
											}
										>
											{ __( 'Resume', 'zinn-seo' ) }
										</RunButton>
									) }
									<RunButton
										variant="tertiary"
										onError={ onError }
										run={ () => control( id, 'cancel' ) }
									>
										{ __( 'Stop', 'zinn-seo' ) }
									</RunButton>
								</p>
							) }
					</div>
				) : null
			) }
		</>
	);
}

/**
 * Choosing what to fix, the estimate, and the start.
 *
 * @param {Object}   props
 * @param {Object}   props.overview  Overview.
 * @param {boolean}  props.disabled  A run is working.
 * @param {Function} props.onError   Error.
 * @param {Function} props.onStarted Started.
 * @return {Element} Card.
 */
function Start( { overview, disabled, onError, onStarted } ) {
	const fromHash = hashParams().get( 'issues' );
	const [ choice, setChoice ] = useState( {
		what: 'all',
		remedies: [],
		issues: fromHash ? fromHash.split( ',' ) : [],
		type: '',
		term: 0,
	} );
	const [ mode, setMode ] = useState( 'review' );
	const [ applyNow, setApplyNow ] = useState( false );
	const [ estimate, setEstimate ] = useState( null );
	const set = ( part ) => {
		setEstimate( null );
		setChoice( { ...choice, ...part } );
	};
	const labels = {};
	overview.remedies.forEach( ( r ) => {
		labels[ r.id ] = r.label;
	} );
	return (
		<div className="zseo-auto__start zseo-stack">
			<h2 className="zseo-auto__h">
				{ __( 'Fix the list on autopilot', 'zinn-seo' ) }
			</h2>
			{ choice.issues.length > 0 && (
				<Notice status="info" onRemove={ () => set( { issues: [] } ) }>
					{ __(
						'Only the issue you chose in Fix it. Remove this to fix the whole list.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<RadioControl
				label={ __( 'What to fix', 'zinn-seo' ) }
				selected={ choice.what }
				onChange={ ( v ) => set( { what: v } ) }
				options={ [
					{
						value: 'all',
						label: __(
							'Everything on the Fix it list that can be fixed automatically',
							'zinn-seo'
						),
					},
					{
						value: 'some',
						label: __( 'Only some kinds of fix', 'zinn-seo' ),
					},
				] }
			/>
			{ choice.what === 'some' && (
				<fieldset className="zseo-auto__kinds">
					<legend className="screen-reader-text">
						{ __( 'Kinds of fix', 'zinn-seo' ) }
					</legend>
					{ overview.remedies.map( ( r ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ r.id }
							label={ r.label }
							checked={ choice.remedies.includes( r.id ) }
							onChange={ ( on ) =>
								set( {
									remedies: on
										? [ ...choice.remedies, r.id ]
										: choice.remedies.filter(
												( x ) => x !== r.id
											),
								} )
							}
						/>
					) ) }
				</fieldset>
			) }
			<div className="zseo-auto__filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Section of the site', 'zinn-seo' ) }
					value={ choice.type }
					onChange={ ( v ) => set( { type: v } ) }
					options={ [
						{
							value: '',
							label: __( 'Every content type', 'zinn-seo' ),
						},
						...overview.types.map( ( t ) => ( {
							value: t.name,
							label: t.label,
						} ) ),
					] }
				/>
				{ ( overview.terms || [] ).length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Category', 'zinn-seo' ) }
						value={ String( choice.term || 0 ) }
						onChange={ ( v ) => set( { term: Number( v ) } ) }
						options={ [
							{
								value: '0',
								label: __( 'Every category', 'zinn-seo' ),
							},
							...overview.terms.map( ( t ) => ( {
								value: String( t.id ),
								label: t.name,
							} ) ),
						] }
					/>
				) }
			</div>
			<RadioControl
				label={ __( 'How', 'zinn-seo' ) }
				selected={ mode }
				onChange={ setMode }
				options={ [
					{
						value: 'review',
						label: __(
							'Show me every fix first, I approve them (all at once or one by one)',
							'zinn-seo'
						),
					},
					{
						value: 'approve',
						label: __(
							'Approve every fix as it is found',
							'zinn-seo'
						),
					},
				] }
			/>
			{ mode === 'approve' && (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Apply them straight away', 'zinn-seo' ) }
					help={ __(
						'Every change keeps the value it replaced, and the whole run can be undone in one click. Letting search engines index the site is never applied without you approving it.',
						'zinn-seo'
					) }
					checked={ applyNow }
					onChange={ setApplyNow }
				/>
			) }
			<p className="zseo-auto__actions">
				<RunButton
					onError={ onError }
					run={ () =>
						seoFetch( '/autopilot/estimate', {
							method: 'POST',
							data: scopeBody( choice ),
						} ).then( setEstimate )
					}
				>
					{ __( 'See what it will fix and cost', 'zinn-seo' ) }
				</RunButton>
				<RunButton
					variant="primary"
					disabled={ disabled || ! estimate }
					onError={ onError }
					run={ () =>
						seoFetch( '/autopilot', {
							method: 'POST',
							data: {
								...scopeBody( choice ),
								mode,
								apply: mode === 'approve' && applyNow,
							},
						} ).then( onStarted )
					}
				>
					{ __( 'Start', 'zinn-seo' ) }
				</RunButton>
			</p>
			{ estimate && <Estimate estimate={ estimate } labels={ labels } /> }
		</div>
	);
}

/**
 * The estimate: what each kind of fix touches and costs, against the credit.
 *
 * @param {Object} props
 * @param {Object} props.estimate Estimate.
 * @param {Object} props.labels   Remedy → label.
 * @return {Element} Table.
 */
function Estimate( { estimate, labels } ) {
	const rows = Object.entries( estimate.rows || {} );
	return (
		<div className="zseo-auto__estimate" aria-live="polite">
			<div className="zseo-table-wrap">
				<table className="widefat striped zseo-table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Fix', 'zinn-seo' ) }</th>
							<th scope="col">{ __( 'Items', 'zinn-seo' ) }</th>
							<th scope="col">{ __( 'Cost', 'zinn-seo' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( [ id, r ] ) => (
							<tr key={ id }>
								<td>{ labels[ id ] || id }</td>
								<td>
									{ r.count > 0
										? r.count
										: __( 'when needed', 'zinn-seo' ) }
								</td>
								<td>{ rowCostText( r ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ estimate.priced && (
				<p>
					{ rows.some( ( [ , r ] ) => r.ai_item_micros > 0 ) &&
						__(
							'Replacements the AI finds for gone pages are paid as they are made, at the price shown for each.',
							'zinn-seo'
						) }{ ' ' }
					{ sprintf(
						/* translators: %s: an amount in US dollars. */
						__( 'About %s from your Zinn® credits.', 'zinn-seo' ),
						dollars( estimate.total_micros )
					) }{ ' ' }
					{ estimate.balance_micros !== null &&
						sprintf(
							/* translators: %s: an amount in US dollars. */
							__( 'Your balance: %s.', 'zinn-seo' ),
							dollars( estimate.balance_micros )
						) }
				</p>
			) }
			{ estimate.enough === false && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Your credit will not cover the whole run. It pauses when the credit runs out and carries on by itself after a top-up.',
						'zinn-seo'
					) }{ ' ' }
					{ estimate.buy_url && (
						<a
							href={ estimate.buy_url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Top up', 'zinn-seo' ) }
						</a>
					) }
				</Notice>
			) }
		</div>
	);
}

/**
 * The issues the autopilot cannot fix: they stay with a person.
 *
 * @param {Object} props
 * @param {Object} props.overview Overview.
 * @return {Element} List.
 */
function Manual( { overview } ) {
	const manual = new Set( overview.fixit.manual );
	const issues = ( overview.fixit.issues || [] ).filter( ( i ) =>
		manual.has( i.id )
	);
	return (
		<details className="zseo-auto__manual">
			<summary>
				{ sprintf(
					/* translators: %d: number of issues. */
					_n(
						'%d issue needs a person (open it in Fix it)',
						'%d issues need a person (open them in Fix it)',
						issues.length,
						'zinn-seo'
					),
					issues.length
				) }
			</summary>
			<ul>
				{ issues.map( ( i ) => (
					<li key={ i.id }>{ i.title }</li>
				) ) }
			</ul>
		</details>
	);
}

/**
 * One run: its report, the review, apply and undo.
 *
 * @param {Object}   props
 * @param {number}   props.run      Run.
 * @param {Object}   props.data     Boot data.
 * @param {boolean}  props.busy     A job is working.
 * @param {Function} props.onError  Error.
 * @param {Function} props.onJob    The apply job started.
 * @param {Function} props.onChange Something changed.
 * @return {Element} Run.
 */
function Run( { run, data, busy, onError, onJob, onChange } ) {
	const [ view, setView ] = useState( null );
	const [ filter, setFilter ] = useState( {
		status: 'proposed',
		remedy: '',
	} );
	const [ k, setK ] = useState( 0 );
	const refresh = () => setK( ( x ) => x + 1 );
	useEffect( () => {
		seoFetch( `/autopilot/${ run }` )
			.then( setView )
			.catch( ( e ) => onError( errorText( e ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ run, k ] );
	if ( ! view ) {
		return <Spinner />;
	}
	const t = view.report.totals;
	const review = ( body ) =>
		seoFetch( `/autopilot/${ run }/review`, {
			method: 'POST',
			data: body,
		} ).then( () => {
			refresh();
			onChange();
		} );
	const start = ( mode ) =>
		seoFetch( `/autopilot/${ run }/${ mode }`, { method: 'POST' } ).then(
			( r ) => onJob( r.jobs.apply )
		);
	return (
		<div className="zseo-stack">
			<dl className="zseo-totals zseo-auto__counts">
				{ [
					'proposed',
					'approved',
					'applied',
					'failed',
					'rejected',
					'kept',
					'undone',
					'changed',
				].map(
					( s ) =>
						( t[ s ] > 0 ||
							[ 'proposed', 'approved', 'applied' ].includes(
								s
							) ) && (
							<div key={ s }>
								<dt>{ statusText( s ) }</dt>
								<dd>{ t[ s ] }</dd>
							</div>
						)
				) }
			</dl>
			<Report report={ view.report } />
			{ view.run.noted > 0 && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %d: number of links. */
						_n(
							'%d link points at a page kept out of search on purpose. It still works for visitors, so it is left as it is.',
							'%d links point at pages kept out of search on purpose. They still work for visitors, so they are left as they are.',
							view.run.noted,
							'zinn-seo'
						),
						view.run.noted
					) }
				</p>
			) }
			{ view.run.unchecked > 0 && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %d: number of links. */
						_n(
							'%d link could not be checked: its page did not answer in time. It is left as it is. Open it yourself to see whether it works.',
							'%d links could not be checked: their pages did not answer in time. They are left as they are. Open them yourself to see whether they work.',
							view.run.unchecked,
							'zinn-seo'
						),
						view.run.unchecked
					) }
				</p>
			) }
			{ view.run.linkRun > 0 && view.links.proposed > 0 && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %d: number of links. */
						_n(
							'%d internal link is waiting in the link plan.',
							'%d internal links are waiting in the link plan.',
							view.links.proposed,
							'zinn-seo'
						),
						view.links.proposed
					) }{ ' ' }
					<a href="admin.php?page=zinn-seo&view=linkplan">
						{ __(
							'Review them one by one on the Link plan screen',
							'zinn-seo'
						) }
					</a>
				</p>
			) }
			{ data.canManage && (
				<p className="zseo-auto__actions">
					<RunButton
						variant="secondary"
						disabled={ busy || t.proposed === 0 }
						onError={ onError }
						run={ () => review( { action: 'approve', all: true } ) }
					>
						{ sprintf(
							/* translators: %d: number of fixes. */
							__( 'Approve all %d', 'zinn-seo' ),
							t.proposed
						) }
					</RunButton>
					<RunButton
						variant="primary"
						disabled={ busy || t.approved === 0 }
						onError={ onError }
						run={ () =>
							confirmBulk( view.pages?.approved ?? 0 )
								? start( 'apply' )
								: undefined
						}
					>
						{ sprintf(
							/* translators: %d: number of fixes. */
							_n(
								'Apply %d approved fix',
								'Apply %d approved fixes',
								t.approved,
								'zinn-seo'
							),
							t.approved
						) }
					</RunButton>
					<RunButton
						variant="tertiary"
						disabled={ busy || t.applied === 0 }
						onError={ onError }
						run={ () => start( 'undo' ) }
					>
						{ sprintf(
							/* translators: %d: number of fixes. */
							_n(
								'Undo this run (%d fix)',
								'Undo this run (%d fixes)',
								t.applied,
								'zinn-seo'
							),
							t.applied
						) }
					</RunButton>
				</p>
			) }
			<Items
				run={ run }
				rows={ view.report.rows }
				filter={ filter }
				setFilter={ setFilter }
				canManage={ data.canManage }
				onReview={ ( body ) =>
					review( body ).catch( ( e ) => onError( errorText( e ) ) )
				}
				onError={ onError }
				version={ k }
			/>
		</div>
	);
}

/**
 * The run's report.
 *
 * @param {Object} props
 * @param {Object} props.report Report.
 * @return {Element} Table.
 */
function Report( { report } ) {
	return (
		<details className="zseo-auto__report" open>
			<summary>{ __( 'Report', 'zinn-seo' ) }</summary>
			<div className="zseo-table-wrap">
				<table className="widefat striped zseo-table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Fix', 'zinn-seo' ) }</th>
							<th scope="col">
								{ __( 'To review', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'Approved', 'zinn-seo' ) }
							</th>
							<th scope="col">{ __( 'Applied', 'zinn-seo' ) }</th>
							<th scope="col">
								{ __( 'Not possible', 'zinn-seo' ) }
							</th>
							<th scope="col">{ __( 'Undone', 'zinn-seo' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ report.rows.map( ( r ) => (
							<tr key={ r.remedy }>
								<td>{ r.label }</td>
								<td>{ r.proposed || 0 }</td>
								<td>{ r.approved || 0 }</td>
								<td>{ r.applied || 0 }</td>
								<td>{ r.failed || 0 }</td>
								<td>{ r.undone || 0 }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<p className="zseo-muted">
				{ report.cost > 0
					? sprintf(
							/* translators: %s: an amount in US dollars. */
							__( 'AI cost of this run: %s.', 'zinn-seo' ),
							dollars( report.cost )
						)
					: __( 'This run used no paid AI.', 'zinn-seo' ) }{ ' ' }
				{ report.same > 0 &&
					sprintf(
						/* translators: %d: number of items. */
						_n(
							'%d item already had what the AI would write, so it was left alone.',
							'%d items already had what the AI would write, so they were left alone.',
							report.same,
							'zinn-seo'
						),
						report.same
					) }
			</p>
		</details>
	);
}

/**
 * The fixes of a run, keyset-paged, to review one by one.
 *
 * @param {Object}   props
 * @param {number}   props.run       Run.
 * @param {Array}    props.rows      The report's rows (the kinds of fix in the run).
 * @param {Object}   props.filter    Filter.
 * @param {Function} props.setFilter Change it.
 * @param {boolean}  props.canManage May change.
 * @param {Function} props.onReview  Review a set.
 * @param {Function} props.onError   Error.
 * @param {number}   props.version   Reload when it changes.
 * @return {Element} List.
 */
function Items( {
	run,
	rows: kinds,
	filter,
	setFilter,
	canManage,
	onReview,
	onError,
	version,
} ) {
	const [ rows, setRows ] = useState( null );
	const [ next, setNext ] = useState( 0 );
	const [ editing, setEditing ] = useState( null );
	const load = ( after ) =>
		seoFetch( itemsPath( run, filter, after ) )
			.then( ( r ) => {
				setRows( after ? [ ...( rows || [] ), ...r.items ] : r.items );
				setNext( r.next );
			} )
			.catch( ( e ) => onError( errorText( e ) ) );
	useEffect( () => {
		load( 0 );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ run, filter.status, filter.remedy, version ] );
	return (
		<div className="zseo-stack">
			<div className="zseo-auto__filters">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Show', 'zinn-seo' ) }
					value={ filter.status || '' }
					onChange={ ( v ) => setFilter( { ...filter, status: v } ) }
					options={ [
						'proposed',
						'approved',
						'applied',
						'failed',
						'rejected',
						'kept',
						'undone',
						'changed',
					].map( ( s ) => ( { value: s, label: statusText( s ) } ) ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Fix', 'zinn-seo' ) }
					value={ filter.remedy || '' }
					onChange={ ( v ) => setFilter( { ...filter, remedy: v } ) }
					options={ [
						{ value: '', label: __( 'Every fix', 'zinn-seo' ) },
						...kinds
							.filter( ( r ) => r.remedy !== 'links' )
							.map( ( r ) => ( {
								value: r.remedy,
								label: r.label,
							} ) ),
					] }
				/>
				{ canManage &&
					filter.remedy &&
					filter.status === 'proposed' &&
					filter.remedy !== 'site-index' && (
						<RunButton
							onError={ onError }
							run={ () =>
								onReview( {
									action: 'approve',
									remedy: filter.remedy,
								} )
							}
						>
							{ __( 'Approve all of this kind', 'zinn-seo' ) }
						</RunButton>
					) }
			</div>
			{ ! rows && <Spinner /> }
			{ rows && rows.length === 0 && (
				<p className="zseo-muted">
					{ __( 'Nothing here.', 'zinn-seo' ) }
				</p>
			) }
			{ rows && rows.length > 0 && (
				<ul className="zseo-auto__items">
					{ rows.map( ( r ) => (
						<li key={ r.id } className="zseo-auto__item">
							<p className="zseo-auto__what">
								{ r.edit ? (
									<a href={ r.edit }>
										{ r.title || `#${ r.object }` }
									</a>
								) : (
									<strong>{ r.title }</strong>
								) }{ ' ' }
								<span className="zseo-tag">{ r.label }</span>{ ' ' }
								<span className="zseo-tag">
									{ statusText( r.status ) }
								</span>
								{ r.remedy === 'links-fix' &&
									whyText( ( r.proposed || {} ).why ) && (
										<span className="zseo-tag">
											{ whyText( r.proposed.why ) }
										</span>
									) }
							</p>
							<dl className="zseo-auto__change">
								{ changes( r ).map( ( c ) => (
									<div key={ c.field }>
										<dt>{ fieldText( c.field ) }</dt>
										<dd>
											<span className="zseo-auto__before">
												<span className="screen-reader-text">
													{ __(
														'Before:',
														'zinn-seo'
													) }{ ' ' }
												</span>
												{ c.before ||
													__(
														'(empty)',
														'zinn-seo'
													) }
											</span>
											<span aria-hidden="true"> → </span>
											<span className="zseo-auto__after">
												<span className="screen-reader-text">
													{ __(
														'After:',
														'zinn-seo'
													) }{ ' ' }
												</span>
												{ c.after }
											</span>
										</dd>
									</div>
								) ) }
							</dl>
							{ r.note && (
								<p className="zseo-muted">
									{ noteText( r.note ) }
								</p>
							) }
							{ editing && editing.id === r.id && (
								<Edit
									run={ run }
									row={ r }
									draft={ editing.draft }
									setDraft={ ( d ) =>
										setEditing( { id: r.id, draft: d } )
									}
									onDone={ () => {
										setEditing( null );
										load( 0 );
									} }
									onError={ onError }
								/>
							) }
							{ canManage &&
								[ 'proposed', 'approved' ].includes(
									r.status
								) && (
									<span className="zseo-auto__actions">
										{ r.status === 'proposed' && (
											<Button
												variant="secondary"
												size="compact"
												onClick={ () =>
													onReview( {
														action: 'approve',
														ids: [ r.id ],
													} )
												}
											>
												{ __( 'Approve', 'zinn-seo' ) }
											</Button>
										) }
										{ r.type !== 'site' &&
											r.remedy !== 'links-fix' &&
											r.remedy !== 'outbound' &&
											! (
												'proposed' in r &&
												r.proposed[ '*' ]
											) &&
											! editing && (
												<Button
													variant="tertiary"
													size="compact"
													onClick={ () =>
														setEditing( {
															id: r.id,
															draft: {
																...r.proposed,
															},
														} )
													}
												>
													{ __( 'Edit', 'zinn-seo' ) }
												</Button>
											) }
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () =>
												onReview( {
													action: 'reject',
													ids: [ r.id ],
												} )
											}
										>
											{ __( 'Reject', 'zinn-seo' ) }
										</Button>
										{ r.type !== 'site' && (
											<Button
												variant="tertiary"
												size="compact"
												onClick={ () =>
													onReview( {
														action: 'keep',
														ids: [ r.id ],
													} )
												}
											>
												{ __(
													'Keep mine',
													'zinn-seo'
												) }
											</Button>
										) }
									</span>
								) }
						</li>
					) ) }
				</ul>
			) }
			{ next > 0 && (
				<Button variant="secondary" onClick={ () => load( next ) }>
					{ __( 'Show more', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Change the words of one proposal.
 *
 * @param {Object}   props
 * @param {number}   props.run      Run.
 * @param {Object}   props.row      Row.
 * @param {Object}   props.draft    Words being edited.
 * @param {Function} props.setDraft Change them.
 * @param {Function} props.onDone   Saved or cancelled.
 * @param {Function} props.onError  Error.
 * @return {Element} Fields.
 */
function Edit( { run, row, draft, setDraft, onDone, onError } ) {
	return (
		<div className="zseo-auto__edit">
			{ Object.keys( draft ).map( ( field ) => (
				<TextControl
					__nextHasNoMarginBottom
					key={ field }
					label={ fieldText( field ) }
					value={ draft[ field ] || '' }
					onChange={ ( v ) => setDraft( { ...draft, [ field ]: v } ) }
				/>
			) ) }
			<p className="zseo-auto__actions">
				<RunButton
					variant="primary"
					onError={ onError }
					run={ () =>
						seoFetch( `/autopilot/${ run }/items/${ row.id }`, {
							method: 'POST',
							data: { proposed: draft },
						} ).then( onDone )
					}
				>
					{ __( 'Save', 'zinn-seo' ) }
				</RunButton>
				<Button variant="tertiary" onClick={ onDone }>
					{ __( 'Cancel', 'zinn-seo' ) }
				</Button>
			</p>
		</div>
	);
}

/**
 * The weekly autopilot.
 *
 * @param {Object} props
 * @param {Array}  props.remedies The fixes the site has.
 * @return {Element|null} Card.
 */
function ScheduleSettings( { remedies } ) {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	useEffect( () => {
		if ( settings && ! draft ) {
			setDraft( {
				autopilot_schedule: settings.autopilot_schedule || 'off',
				autopilot_day: settings.autopilot_day ?? 1,
				autopilot_hour: settings.autopilot_hour ?? 3,
				autopilot_auto_apply: !! settings.autopilot_auto_apply,
				autopilot_email: settings.autopilot_email || '',
				autopilot_remedies: settings.autopilot_remedies || [],
			} );
		}
	}, [ settings, draft ] );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! draft ) {
		return null;
	}
	const set = ( part ) => {
		setSaved( false );
		setDraft( { ...draft, ...part } );
	};
	const on = draft.autopilot_schedule === 'weekly';
	return (
		<details className="zseo-auto__schedule" open={ on }>
			<summary>{ __( 'Weekly autopilot', 'zinn-seo' ) }</summary>
			<div className="zseo-stack">
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Find the fixes every week', 'zinn-seo' ) }
					help={ __(
						'Runs in the background at the time you choose, as you. You get an e-mail when fixes are ready.',
						'zinn-seo'
					) }
					checked={ on }
					onChange={ ( v ) =>
						set( { autopilot_schedule: v ? 'weekly' : 'off' } )
					}
				/>
				{ on && (
					<>
						<div className="zseo-auto__filters">
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Day', 'zinn-seo' ) }
								value={ String( draft.autopilot_day ) }
								onChange={ ( v ) =>
									set( { autopilot_day: Number( v ) } )
								}
								options={ dayOptions() }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Hour (site time)', 'zinn-seo' ) }
								value={ String( draft.autopilot_hour ) }
								onChange={ ( v ) =>
									set( { autopilot_hour: Number( v ) } )
								}
								options={ Array.from(
									{ length: 24 },
									( _, h ) => ( {
										value: String( h ),
										label: `${ String( h ).padStart(
											2,
											'0'
										) }:00`,
									} )
								) }
							/>
						</div>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Apply the fixes without asking me',
								'zinn-seo'
							) }
							help={ __(
								'Off: the fixes wait for your review. On: they are applied, and the e-mail says what changed; the run can be undone in one click.',
								'zinn-seo'
							) }
							checked={ !! draft.autopilot_auto_apply }
							onChange={ ( v ) =>
								set( { autopilot_auto_apply: v } )
							}
						/>
						<fieldset className="zseo-auto__kinds">
							<legend>
								{ __(
									'Fixes to run (none ticked: all of them)',
									'zinn-seo'
								) }
							</legend>
							{ remedies
								.filter( ( r ) => r.id !== 'site-index' )
								.map( ( r ) => (
									<CheckboxControl
										__nextHasNoMarginBottom
										key={ r.id }
										label={ r.label }
										checked={ draft.autopilot_remedies.includes(
											r.id
										) }
										onChange={ ( v ) =>
											set( {
												autopilot_remedies: v
													? [
															...draft.autopilot_remedies,
															r.id,
														]
													: draft.autopilot_remedies.filter(
															( x ) => x !== r.id
														),
											} )
										}
									/>
								) ) }
						</fieldset>
					</>
				) }
				<TextControl
					__nextHasNoMarginBottom
					type="email"
					label={ __( 'Send the summary to', 'zinn-seo' ) }
					help={ __(
						"Empty: the site's admin e-mail. When set, you also get a summary of runs you start yourself.",
						'zinn-seo'
					) }
					value={ draft.autopilot_email }
					onChange={ ( v ) => set( { autopilot_email: v } ) }
				/>
				<p>
					<Button
						variant="primary"
						onClick={ () =>
							save( draft ).then( () => setSaved( true ) )
						}
					>
						{ __( 'Save', 'zinn-seo' ) }
					</Button>
					{ saved && (
						<span className="zseo-muted" role="status">
							{ ' ' }
							{ __( 'Saved.', 'zinn-seo' ) }
						</span>
					) }
				</p>
			</div>
		</details>
	);
}
