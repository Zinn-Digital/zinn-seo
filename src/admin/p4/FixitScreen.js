/**
 * "Fix it": one prioritised list of what to fix across the site (zs-242, zs-inn-10).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { RunButton } from './shared';
import { AiFailure, firstFields, useRun } from '../../ai/parts';
import TasksScreen, { TaskButton } from '../../agency/Tasks';
import OfferSlot from '../../offers/OfferSlot';

/**
 * Words for an area.
 *
 * @param {string} area Area id.
 * @return {string} Words.
 */
function areaText( area ) {
	return (
		{
			site: __( 'Whole site', 'zinn-seo' ),
			technical: __( 'Technical', 'zinn-seo' ),
			content: __( 'Content', 'zinn-seo' ),
			links: __( 'Links', 'zinn-seo' ),
		}[ area ] || area
	);
}

/**
 * A priority's words.
 *
 * @param {number} p Priority 1-100.
 * @return {Array<string>} [class, words].
 */
function level( p ) {
	if ( p >= 75 ) {
		return [ 'is-high', __( 'Fix first', 'zinn-seo' ) ];
	}
	if ( p >= 50 ) {
		return [ 'is-mid', __( 'Important', 'zinn-seo' ) ];
	}
	return [ 'is-low', __( 'When you can', 'zinn-seo' ) ];
}

/**
 * Minutes as words.
 *
 * @param {number} m Minutes.
 * @return {string} Words.
 */
function minutes( m ) {
	if ( ! m ) {
		return '';
	}
	if ( m < 60 ) {
		return sprintf(
			/* translators: %d: minutes. */
			_n( 'about %d minute', 'about %d minutes', m, 'zinn-seo' ),
			m
		);
	}
	const h = Math.round( m / 60 );
	return sprintf(
		/* translators: %d: hours. */
		_n( 'about %d hour', 'about %d hours', h, 'zinn-seo' ),
		h
	);
}

/**
 * One issue.
 *
 * @param {Object}   props
 * @param {Object}   props.issue   Issue.
 * @param {boolean}  props.manage  The user may run fixes.
 * @param {Function} props.onData  New inbox data.
 * @param {Function} props.onError Error.
 * @param {Object}   props.ai      The AI state (null: no AI for this person).
 * @param {Object}   props.data    Boot data.
 * @param {Function} props.onTask  Called after a task is made.
 * @return {Element} Card.
 */
function Issue( { issue, manage, onData, onError, ai, data, onTask } ) {
	const [ cls, words ] = level( issue.priority );
	const fix = issue.fix;
	const time = minutes( issue.minutes );
	const explain = useRun();
	const plain = firstFields( explain.result );
	return (
		<Card className={ `zseo-fix ${ cls }` }>
			<CardBody>
				<div className="zseo-fix__head">
					<span className="zseo-fix__level">{ words }</span>
					<span className="zseo-fix__area">
						{ areaText( issue.area ) }
					</span>
					{ time && <span className="zseo-fix__time">{ time }</span> }
				</div>
				<h3 className="zseo-fix__title">{ issue.title }</h3>
				{ issue.detail && (
					<p className="zseo-fix__detail">{ issue.detail }</p>
				) }
				{ issue.translations && issue.translations.note && (
					<p className="zseo-fix__detail">
						{ issue.translations.note }
					</p>
				) }
				{ ( issue.items || [] ).length > 0 && (
					<ul className="zseo-fix__items">
						{ issue.items.map( ( it ) => (
							<li key={ it.id }>
								<a href={ it.edit }>
									{ it.title || `#${ it.id }` }
								</a>
							</li>
						) ) }
					</ul>
				) }
				{ plain && (
					<div className="zseo-fix__plain" aria-live="polite">
						<p>{ plain.what }</p>
						<p>{ plain.why }</p>
						{ plain.steps.length > 0 && (
							<ol>
								{ plain.steps.map( ( st ) => (
									<li key={ st }>{ st }</li>
								) ) }
							</ol>
						) }
					</div>
				) }
				{ ai && (
					<AiFailure
						failure={ explain.failure }
						buyUrl={ ( ai.credits || {} ).buy_url }
						onRemove={ explain.clear }
					/>
				) }
				<div className="zseo-fix__actions">
					{ fix && fix.kind === 'action' && manage && (
						<RunButton
							variant="primary"
							onError={ onError }
							run={ () =>
								seoFetch( '/fixit/run', {
									method: 'POST',
									data: { action: fix.id },
								} ).then( onData )
							}
						>
							{ fix.label }
						</RunButton>
					) }
					{ fix && fix.kind === 'link' && (
						<Button variant="primary" href={ fix.url }>
							{ fix.label }
						</Button>
					) }
					{ issue.more && issue.more.url && (
						<Button variant="secondary" href={ issue.more.url }>
							{ issue.more.label }
						</Button>
					) }
					{ issue.auto && issue.auto.url && (
						<Button variant="secondary" href={ issue.auto.url }>
							{ issue.auto.label }
						</Button>
					) }
					{ ai && ai.ready && ! plain && (
						<Button
							variant="secondary"
							isBusy={ !! explain.busy }
							disabled={ !! explain.busy }
							onClick={ () =>
								explain.run( 'explain', '/ai/run', {
									task: 'explain',
									ids: [ 0 ],
									options: { issue: issue.id },
								} )
							}
						>
							{ __( 'Explain in plain words', 'zinn-seo' ) }
						</Button>
					) }
					{ data && data.tasks && (
						<TaskButton
							issue={ issue }
							data={ data }
							onMade={ onTask }
						/>
					) }
					{ manage && (
						<>
							<RunButton
								variant="tertiary"
								onError={ onError }
								run={ () =>
									seoFetch( '/fixit/dismiss', {
										method: 'POST',
										data: { id: issue.id, days: 7 },
									} ).then( onData )
								}
							>
								{ __( 'Snooze a week', 'zinn-seo' ) }
							</RunButton>
							<RunButton
								variant="tertiary"
								onError={ onError }
								run={ () =>
									seoFetch( '/fixit/dismiss', {
										method: 'POST',
										data: { id: issue.id, days: 0 },
									} ).then( onData )
								}
							>
								{ __( 'Dismiss', 'zinn-seo' ) }
							</RunButton>
						</>
					) }
				</div>
				{ ( issue.offers || [] ).length > 0 && (
					<OfferSlot offers={ issue.offers } data={ data } />
				) }
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
export default function FixitScreen( { data } ) {
	const [ inbox, setInbox ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ tasks, setTasks ] = useState( 0 );
	const manage = !! data.canManage;
	const load = ( fresh ) => {
		setBusy( true );
		seoFetch( `/fixit${ fresh ? '?fresh=1' : '' }` )
			.then( setInbox )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	if ( ! inbox && ! busy && ! error ) {
		load( false );
	}
	const issues = inbox ? inbox.issues : [];
	const total = issues.reduce( ( n, i ) => n + ( i.minutes || 0 ), 0 );
	return (
		<div className="zseo-admin zseo-fixit">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<div className="zseo-fixit__bar">
				<p className="zseo-fixit__summary">
					{ inbox &&
						( issues.length
							? sprintf(
									/* translators: 1: number of things to fix, 2: time estimate. */
									_n(
										'%1$d thing to fix, %2$s in all, most important first.',
										'%1$d things to fix, %2$s in all, most important first.',
										issues.length,
										'zinn-seo'
									),
									issues.length,
									minutes( total ) ||
										__( 'a few minutes', 'zinn-seo' )
								)
							: __( 'Nothing to fix. Well done.', 'zinn-seo' ) ) }
				</p>
				{ inbox && inbox.autopilot && inbox.autopilot.url && (
					<Button
						variant={
							inbox.autopilot.pro ? 'secondary' : 'primary'
						}
						href={ inbox.autopilot.url }
					>
						{ inbox.autopilot.pro
							? __(
									'Fix the whole list on autopilot with Pro',
									'zinn-seo'
								)
							: inbox.autopilot.label }
					</Button>
				) }
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => load( true ) }
				>
					{ __( 'Check again', 'zinn-seo' ) }
				</Button>
				{ inbox && inbox.dismissed > 0 && manage && (
					<RunButton
						variant="tertiary"
						onError={ setError }
						run={ () =>
							seoFetch( '/fixit/restore', {
								method: 'POST',
							} ).then( setInbox )
						}
					>
						{ sprintf(
							/* translators: %d: number of hidden issues. */
							_n(
								'Show %d dismissed',
								'Show %d dismissed',
								inbox.dismissed,
								'zinn-seo'
							),
							inbox.dismissed
						) }
					</RunButton>
				) }
			</div>
			{ data.tasks && (
				<TasksScreen data={ data } compact version={ tasks } />
			) }
			{ ! inbox && busy && <Spinner /> }
			<div className="zseo-fixit__list">
				{ issues.map( ( issue ) => (
					<Issue
						key={ issue.id }
						issue={ issue }
						manage={ manage }
						onData={ setInbox }
						onError={ setError }
						ai={ data.aiState }
						data={ data }
						onTask={ () => setTasks( tasks + 1 ) }
					/>
				) ) }
			</div>
		</div>
	);
}
