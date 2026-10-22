/**
 * Site authority (stage C2, zs-500 … zs-509): connect your own Ahrefs (free Domain Rating key),
 * Open PageRank — and with Pro Majestic, Moz, Semrush, SE Ranking and Ahrefs Site Explorer — or use
 * Zinn Authority on credit; choose what to show and track, see the history beside your
 * competitors, and get told when a score goes up or down.
 *
 * ⛔ A key typed here is sent once to Zinn®, which keeps it in its vault; this screen never shows it
 * again (only its last four characters) and the site's database never holds it.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
} from '@wordpress/components';

import ProTeaser from '../admin/ProTeaser';
import { viewHref } from '../admin-kit/router';
import { seoFetch } from '../admin/api';
import { NewTab, Refusal, useAction, useLoad } from '../tools/parts';
import { count, day, guideUrl, money } from '../tools/pure';
import { toTsv } from '../metrics/markers';
import HistoryChart from './HistoryChart';
import {
	attributionText,
	brandName,
	metricName,
	planNote,
	sourceName,
	unitName,
} from './labels';
import {
	bareDomain,
	cards,
	moved,
	plausibleKey,
	score,
	toCsv,
	trackedOptions,
} from './pure';
import './authority.scss';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function authorityTabs() {
	return [
		{ name: 'scores', title: __( 'Scores', 'zinn-seo' ) },
		{ name: 'sources', title: __( 'Connect and choose', 'zinn-seo' ) },
		{ name: 'history', title: __( 'History', 'zinn-seo' ) },
		{ name: 'competitors', title: __( 'Competitors', 'zinn-seo' ) },
	];
}

const CADENCES = () => [
	{ value: '1', label: __( 'Every day', 'zinn-seo' ) },
	{ value: '7', label: __( 'Every week', 'zinn-seo' ) },
	{ value: '30', label: __( 'Every month', 'zinn-seo' ) },
];

const WHEN = () => [
	{ value: 'drops', label: __( 'drops by', 'zinn-seo' ) },
	{ value: 'rises', label: __( 'rises by', 'zinn-seo' ) },
	{ value: 'above', label: __( 'goes above', 'zinn-seo' ) },
	{ value: 'below', label: __( 'goes below', 'zinn-seo' ) },
];

/**
 * How to get a key, step by step, for each source (vendor names and their own menu names stay).
 *
 * @param {string} key Source.
 * @return {Array<string>} Steps.
 */
function steps( key ) {
	switch ( key ) {
		case 'ahrefs_free':
			return [
				__(
					'Sign in to Ahrefs, or create a free account — no paid plan needed.',
					'zinn-seo'
				),
				__(
					'Open Account settings, then API keys, and create a new key.',
					'zinn-seo'
				),
				__(
					'Copy the key and paste it below. Your Domain Rating is checked for free.',
					'zinn-seo'
				),
			];
		case 'openpagerank':
			return [
				__(
					'Open the Open PageRank dashboard and sign up — the free plan needs no card.',
					'zinn-seo'
				),
				__( 'Create an API key there.', 'zinn-seo' ),
				__( 'Copy the key and paste it below.', 'zinn-seo' ),
			];
		case 'moz':
			return [
				__(
					'Sign in at moz.com and open the API dashboard (the free API plan works).',
					'zinn-seo'
				),
				__(
					'Press Add Token, name it Zinn® SEO, and copy the token.',
					'zinn-seo'
				),
				__(
					'Paste it below. An older Access ID and Secret Key also work.',
					'zinn-seo'
				),
			];
		default:
			return [
				__(
					'Open your account with this company and find its API key.',
					'zinn-seo'
				),
				__( 'Copy the key and paste it below.', 'zinn-seo' ),
			];
	}
}

/**
 * The tag beside a source's name.
 *
 * @param {Object} source Source.
 * @return {string} Words.
 */
function tierTag( source ) {
	if ( source.tier === 'credits' ) {
		return __( 'Pay as you go with Zinn® credit', 'zinn-seo' );
	}
	if ( source.free_key ) {
		return __( 'Free key', 'zinn-seo' );
	}
	return __( 'Your paid account', 'zinn-seo' );
}

/**
 * The state of a feed in words.
 *
 * @param {Object} feed Feed.
 * @return {{text: string, tone: string}} Status.
 */
function feedStatus( feed ) {
	if ( ! feed ) {
		return { text: __( 'Not tracking yet', 'zinn-seo' ), tone: 'none' };
	}
	switch ( feed.state ) {
		case 'paused_quota':
			return {
				text: feed.resume_at
					? sprintf(
							/* translators: %s: a date. */
							__(
								'Paused until your allowance renews on %s — nothing is spent meanwhile',
								'zinn-seo'
							),
							day( feed.resume_at )
						)
					: __(
							'Paused until your allowance renews — nothing is spent meanwhile',
							'zinn-seo'
						),
				tone: 'warning',
			};
		case 'paused_key':
			return {
				text: __(
					'Paused: the key was not accepted. Paste a fresh key to carry on.',
					'zinn-seo'
				),
				tone: 'error',
			};
		case 'paused_credit':
			return {
				text: __(
					'Paused for want of credit. It carries on by itself when you add credit.',
					'zinn-seo'
				),
				tone: 'warning',
			};
		case 'paused_user':
			return {
				text: __( 'Scheduled checks are off', 'zinn-seo' ),
				tone: 'none',
			};
		default:
			return {
				text: feed.last_succeeded_at
					? sprintf(
							/* translators: %s: a date. */
							__( 'Tracking — last checked %s', 'zinn-seo' ),
							day( feed.last_succeeded_at, true )
						)
					: __(
							'Tracking — the first check runs shortly',
							'zinn-seo'
						),
				tone: 'success',
			};
	}
}

/**
 * The vendor's attribution: printed beside every number from it (a licence term for Ahrefs).
 *
 * @param {Object} props
 * @param {string} props.text Attribution.
 * @param {string} props.url  Vendor address.
 * @return {Element} Line.
 */
function Attribution( { text, url } ) {
	const line = <bdi>{ text }</bdi>;
	return url ? (
		<NewTab className="zseo-authority__by" href={ url }>
			{ line }
		</NewTab>
	) : (
		<span className="zseo-authority__by">{ line }</span>
	);
}

/**
 * The score cards.
 *
 * @param {Object}   props
 * @param {Object}   props.state The `/authority` answer.
 * @param {Function} props.go    Switch tab.
 * @return {Element} Cards.
 */
function ScoresTab( { state, go } ) {
	const list = cards( state );
	if ( ! list.length ) {
		return (
			<Card>
				<CardBody className="zseo-stack">
					<h3 className="zseo-authority__title">
						{ __(
							'See the authority scores search engines and buyers judge your site by',
							'zinn-seo'
						) }
					</h3>
					<p>
						{ __(
							'Connect a free Ahrefs key to watch your Domain Rating, use Open PageRank, or let Zinn® measure your site from its own backlink data. Then pick the scores to show here.',
							'zinn-seo'
						) }
					</p>
					<div>
						<Button
							variant="primary"
							onClick={ () => go( 'sources' ) }
						>
							{ __( 'Choose a source', 'zinn-seo' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		);
	}
	return (
		<ul className="zseo-stats zseo-authority__cards">
			{ list.map( ( c ) => {
				const m = moved( c.value, c.previous, c.better );
				return (
					<li
						key={ `${ c.source }-${ c.metric }` }
						className="zseo-stats__item"
					>
						<span className="zseo-stats__label">
							{ metricName( c.metric, c.name ) }
						</span>
						<strong className="zseo-stats__value">
							{ score( c.value ) }
						</strong>
						{ m.dir !== 'flat' && (
							<span
								className={ `zseo-delta is-${
									m.good ? 'up' : 'down'
								}` }
							>
								{ sprintf(
									/* translators: 1: an arrow, 2: how much a score moved. */
									__(
										'%1$s %2$s since the last check',
										'zinn-seo'
									),
									m.dir === 'up' ? '↑' : '↓',
									score( m.by )
								) }
							</span>
						) }
						{ c.value === null && (
							<span className="zseo-rating">
								{ __(
									'Waiting for the first check',
									'zinn-seo'
								) }
							</span>
						) }
						<Attribution
							text={ attributionText( c.source, c.attribution ) }
							url={ c.attributionUrl }
						/>
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * Paste a key.
 *
 * @param {Object}   props
 * @param {Object}   props.source Source.
 * @param {Object}   props.act    `useAction()`.
 * @param {Function} props.done   Reload.
 * @return {Element} Form.
 */
function KeyForm( { source, act, done } ) {
	const [ key, setKey ] = useState( '' );
	const [ pair, setPair ] = useState( false );
	const [ accessId, setAccessId ] = useState( '' );
	const [ secret, setSecret ] = useState( '' );
	const [ database, setDatabase ] = useState( 'us' );
	const usePair = source.key === 'moz' && pair;
	const ready = usePair
		? plausibleKey( accessId ) && plausibleKey( secret )
		: plausibleKey( key );
	const submit = ( e ) => {
		e.preventDefault();
		const data = usePair
			? { access_id: accessId.trim(), access_secret: secret.trim() }
			: { api_key: key.trim() };
		if ( source.key === 'semrush' ) {
			data.database = database;
		}
		act.run(
			`key-${ source.key }`,
			() =>
				seoFetch( `/authority/sources/${ source.key }/key`, {
					method: 'PUT',
					data,
				} ),
			__( 'Key checked and connected.', 'zinn-seo' )
		).then( ( ok ) => {
			if ( ok ) {
				// ⛔ Forget the typed key the moment it has been sent.
				setKey( '' );
				setAccessId( '' );
				setSecret( '' );
				done();
			}
		} );
	};
	return (
		<form className="zseo-stack" onSubmit={ submit } autoComplete="off">
			<ol className="zseo-authority__steps">
				{ steps( source.key ).map( ( s ) => (
					<li key={ s }>{ s }</li>
				) ) }
			</ol>
			{ source.key_url && (
				<p>
					<NewTab href={ source.key_url }>
						{ sprintf(
							/* translators: %s: a company name, e.g. "Ahrefs". */
							__( 'Get your key from %s', 'zinn-seo' ),
							brandName( source.key, source.name )
						) }
					</NewTab>
				</p>
			) }
			{ planNote( source.key ) && (
				<p className="zseo-muted">{ planNote( source.key ) }</p>
			) }
			{ source.key === 'moz' && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'I have an Access ID and Secret Key instead of a token',
						'zinn-seo'
					) }
					checked={ pair }
					onChange={ setPair }
				/>
			) }
			{ usePair ? (
				<>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Access ID', 'zinn-seo' ) }
						value={ accessId }
						onChange={ setAccessId }
						autoComplete="off"
						spellCheck={ false }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="password"
						label={ __( 'Secret Key', 'zinn-seo' ) }
						value={ secret }
						onChange={ setSecret }
						autoComplete="new-password"
					/>
				</>
			) : (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="password"
					label={ sprintf(
						/* translators: %s: a company name. */
						__( '%s API key', 'zinn-seo' ),
						brandName( source.key, source.name )
					) }
					help={ __(
						'Kept safely by Zinn® — never stored in this site and never shown again.',
						'zinn-seo'
					) }
					value={ key }
					onChange={ setKey }
					autoComplete="new-password"
					spellCheck={ false }
				/>
			) }
			{ source.key === 'semrush' && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Semrush country database', 'zinn-seo' ) }
					value={ database }
					options={ [
						'us',
						'uk',
						'ca',
						'au',
						'de',
						'fr',
						'es',
						'it',
						'nl',
						'in',
						'br',
					].map( ( v ) => ( { value: v, label: v.toUpperCase() } ) ) }
					onChange={ setDatabase }
				/>
			) }
			<div>
				<Button
					variant="primary"
					type="submit"
					disabled={ ! ready || !! act.busy }
					isBusy={ act.busy === `key-${ source.key }` }
				>
					{ __( 'Check and connect', 'zinn-seo' ) }
				</Button>
			</div>
		</form>
	);
}

/**
 * Choose what to show, track and be told about, and how often — with the cost shown first.
 *
 * @param {Object}   props
 * @param {Object}   props.state  The `/authority` answer.
 * @param {Object}   props.source Source.
 * @param {Object}   props.feed   Its feed, if any.
 * @param {Object}   props.act    `useAction()`.
 * @param {Function} props.done   Reload.
 * @return {Element} Form.
 */
function FeedForm( { state, source, feed, act, done } ) {
	const metrics = source.metrics || [];
	const [ show, setShow ] = useState(
		feed ? feed.show : metrics.slice( 0, 1 ).map( ( m ) => m.key )
	);
	const [ track, setTrack ] = useState(
		feed ? feed.track : metrics.slice( 0, 1 ).map( ( m ) => m.key )
	);
	const [ cadence, setCadence ] = useState(
		String( feed ? feed.cadence_days : 30 )
	);
	const [ alerts, setAlerts ] = useState(
		feed
			? feed.alerts
			: metrics.slice( 0, 1 ).map( ( m ) => ( {
					metric: m.key,
					when: m.better === 'lower' ? 'rises' : 'drops',
					by: m.kind === 'score' ? '3' : '10',
				} ) )
	);
	const [ enabled, setEnabled ] = useState(
		! feed || feed.state !== 'paused_user'
	);
	const wanted = [ ...new Set( [ ...show, ...track ] ) ];
	const [ cost, setCost ] = useState( null );
	const toggle = ( list, setList, key, on ) =>
		setList(
			on
				? [ ...new Set( [ ...list, key ] ) ]
				: list.filter( ( k ) => k !== key )
		);
	const price = () =>
		seoFetch( `/authority/sources/${ source.key }/estimate`, {
			method: 'POST',
			data: { metrics: wanted, cadence_days: Number( cadence ) },
		} )
			.then( setCost )
			.catch( () => setCost( null ) );
	const save = () =>
		act
			.run(
				`feed-${ source.key }`,
				() =>
					seoFetch( `/authority/sources/${ source.key }/feed`, {
						method: 'PUT',
						data: {
							show,
							track,
							alerts: alerts.filter( ( a ) =>
								track.includes( a.metric )
							),
							cadence_days: Number( cadence ),
							enabled,
						},
					} ),
				__( 'Saved. The first check runs shortly.', 'zinn-seo' )
			)
			.then( ( ok ) => ok && done() );
	/**
	 * What the schedule uses, in words ('' until asked).
	 *
	 * @return {string} Line.
	 */
	const usage = () => {
		if ( ! cost ) {
			return '';
		}
		if ( source.tier === 'credits' ) {
			return sprintf(
				/* translators: 1: an amount of money per check, 2: an amount per month. */
				__(
					'Each check costs %1$s of Zinn® credit — about %2$s a month.',
					'zinn-seo'
				),
				money( cost.credit_micros_per_run || 0 ),
				money( cost.credit_micros_per_month || 0 )
			);
		}
		const used = sprintf(
			/* translators: 1: a number, 2: the unit a company counts in ("API units"), 3: a number per month. */
			__(
				'Each check uses %1$s %2$s of your own allowance — about %3$s a month.',
				'zinn-seo'
			),
			count( cost.units_per_run ),
			unitName( source.key ),
			count( cost.units_per_month )
		);
		if ( cost.units_left === null || cost.units_left === undefined ) {
			return used;
		}
		return (
			used +
			' ' +
			sprintf(
				/* translators: 1: a number, 2: a unit. */
				__( 'You have %1$s %2$s left.', 'zinn-seo' ),
				count( cost.units_left ),
				unitName( source.key )
			)
		);
	};
	const unitLine = usage();
	return (
		<div className="zseo-stack">
			<table className="zak-table zseo-t zseo-authority__pick">
				<thead>
					<tr>
						<th scope="col">{ __( 'Score', 'zinn-seo' ) }</th>
						<th scope="col">
							{ __( 'Show on the Scores tab', 'zinn-seo' ) }
						</th>
						<th scope="col">
							{ __( 'Keep history and alerts', 'zinn-seo' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ metrics.map( ( m ) => (
						<tr key={ m.key }>
							<th scope="row">{ metricName( m.key, m.name ) }</th>
							<td
								data-label={ __(
									'Show on the Scores tab',
									'zinn-seo'
								) }
							>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: %s: a score's name, e.g. "Domain Rating". */
										__( 'Show %s', 'zinn-seo' ),
										metricName( m.key, m.name )
									) }
									hideLabelFromVision
									checked={ show.includes( m.key ) }
									onChange={ ( on ) =>
										toggle( show, setShow, m.key, on )
									}
								/>
							</td>
							<td
								data-label={ __(
									'Keep history and alerts',
									'zinn-seo'
								) }
							>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ sprintf(
										/* translators: %s: a score's name. */
										__( 'Track %s', 'zinn-seo' ),
										metricName( m.key, m.name )
									) }
									hideLabelFromVision
									checked={ track.includes( m.key ) }
									onChange={ ( on ) =>
										toggle( track, setTrack, m.key, on )
									}
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'How often to check', 'zinn-seo' ) }
				value={ cadence }
				options={ CADENCES() }
				onChange={ ( v ) => {
					setCadence( v );
					setCost( null );
				} }
			/>
			<fieldset className="zseo-stack">
				<legend className="zseo-authority__legend">
					{ __( 'Tell me when', 'zinn-seo' ) }
				</legend>
				{ alerts.map( ( a, i ) => (
					<div className="zseo-authority__rule" key={ i }>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Score', 'zinn-seo' ) }
							value={ a.metric }
							options={ metrics
								.filter( ( m ) => track.includes( m.key ) )
								.map( ( m ) => ( {
									value: m.key,
									label: metricName( m.key, m.name ),
								} ) ) }
							onChange={ ( v ) =>
								setAlerts(
									alerts.map( ( r, j ) =>
										j === i ? { ...r, metric: v } : r
									)
								)
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'When it', 'zinn-seo' ) }
							value={ a.when }
							options={ WHEN() }
							onChange={ ( v ) =>
								setAlerts(
									alerts.map( ( r, j ) =>
										j === i ? { ...r, when: v } : r
									)
								)
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min="0"
							step="any"
							label={ __( 'Amount', 'zinn-seo' ) }
							value={ a.by }
							onChange={ ( v ) =>
								setAlerts(
									alerts.map( ( r, j ) =>
										j === i ? { ...r, by: v } : r
									)
								)
							}
						/>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								setAlerts(
									alerts.filter( ( _r, j ) => j !== i )
								)
							}
						>
							{ __( 'Remove', 'zinn-seo' ) }
						</Button>
					</div>
				) ) }
				{ track.length > 0 && alerts.length < 20 && (
					<div>
						<Button
							variant="secondary"
							onClick={ () =>
								setAlerts( [
									...alerts,
									{
										metric: track[ 0 ],
										when: 'drops',
										by: '3',
									},
								] )
							}
						>
							{ __( 'Add an alert', 'zinn-seo' ) }
						</Button>
					</div>
				) }
				<p className="zseo-muted">
					{ __(
						'Alerts arrive by e-mail from Zinn®, in the notices here, and from your site if you switch that on under Credit.',
						'zinn-seo'
					) }
				</p>
			</fieldset>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Check on a schedule', 'zinn-seo' ) }
				checked={ enabled }
				onChange={ setEnabled }
			/>
			<div className="zseo-authority__cost">
				{ unitLine ? (
					<p>{ unitLine }</p>
				) : (
					<Button
						variant="link"
						onClick={ price }
						disabled={ ! wanted.length }
					>
						{ __(
							'See what this schedule uses before saving',
							'zinn-seo'
						) }
					</Button>
				) }
			</div>
			<div className="zseo-row">
				<Button
					variant="primary"
					onClick={ save }
					disabled={ ! wanted.length || !! act.busy }
					isBusy={ act.busy === `feed-${ source.key }` }
				>
					{ __( 'Save', 'zinn-seo' ) }
				</Button>
				{ feed && (
					<Button
						variant="secondary"
						disabled={ !! act.busy }
						isBusy={ act.busy === `run-${ source.key }` }
						onClick={ () =>
							act
								.run(
									`run-${ source.key }`,
									() =>
										seoFetch(
											`/authority/sources/${ source.key }/run`,
											{ method: 'POST' }
										),
									__(
										'Checking now — the new figures appear here in about a minute.',
										'zinn-seo'
									)
								)
								.then( ( ok ) => ok && done() )
						}
					>
						{ __( 'Check now', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			{ state.canSpend === false && source.tier === 'credits' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This site is not allowed to spend Zinn® credit. The owner of the Zinn® account can allow it.',
						'zinn-seo'
					) }
				</Notice>
			) }
		</div>
	);
}

/**
 * One source.
 *
 * @param {Object}   props
 * @param {Object}   props.state  The `/authority` answer.
 * @param {Object}   props.source Source.
 * @param {Function} props.done   Reload.
 * @return {Element} Card.
 */
function SourceCard( { state, source, done } ) {
	const act = useAction();
	const account = ( state.accounts || [] ).find(
		( a ) => a.vendor === source.key
	);
	const feed = ( state.feeds || [] ).find( ( f ) => f.vendor === source.key );
	const keyed = ( source.key_fields || [] ).length > 0;
	const needsKey = keyed && ( ! account || account.status === 'rejected' );
	const status = feedStatus( feed );
	return (
		<Card className="zseo-authority__source">
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h3 className="zseo-authority__title">
						<bdi>{ sourceName( source.key, source.name ) }</bdi>{ ' ' }
						<span className="zseo-authority__tag">
							{ tierTag( source ) }
						</span>
					</h3>
					<span
						className={ `zseo-authority__status is-${ status.tone }` }
					>
						{ status.text }
					</span>
				</div>
				<p className="zseo-muted">
					{ ( source.metrics || [] )
						.map( ( m ) => metricName( m.key, m.name ) )
						.join( ' · ' ) }
				</p>
				{ account && (
					<p className="zseo-authority__key">
						{ sprintf(
							/* translators: %s: the last four characters of a key. */
							__(
								'Connected with the key ending %s',
								'zinn-seo'
							),
							account.key_hint
						) }
						{ account.allowance &&
							account.allowance.units_left !== null &&
							account.allowance.units_left !== undefined &&
							' · ' +
								sprintf(
									/* translators: 1: a number, 2: a unit, 3: a date. */
									__(
										'%1$s %2$s left (as of %3$s)',
										'zinn-seo'
									),
									count( account.allowance.units_left ),
									unitName( source.key ),
									day( account.checked_at, true )
								) }{ ' ' }
						<Button
							variant="link"
							isDestructive
							disabled={ !! act.busy }
							onClick={ () =>
								act
									.run(
										`remove-${ source.key }`,
										() =>
											seoFetch(
												`/authority/sources/${ source.key }/key`,
												{ method: 'DELETE' }
											),
										__(
											'Key removed from Zinn®.',
											'zinn-seo'
										)
									)
									.then( ( ok ) => ok && done() )
							}
						>
							{ __( 'Remove key', 'zinn-seo' ) }
						</Button>
					</p>
				) }
				{ act.error && (
					<Refusal error={ act.error } onRemove={ act.clear } />
				) }
				{ act.done && (
					<Notice status="success" onRemove={ act.clear }>
						{ act.done }
					</Notice>
				) }
				{ needsKey ? (
					<KeyForm source={ source } act={ act } done={ done } />
				) : (
					<FeedForm
						key={ feed ? feed.last_succeeded_at || 'f' : 'new' }
						state={ state }
						source={ source }
						feed={ feed }
						act={ act }
						done={ done }
					/>
				) }
				<p className="zseo-authority__attribution">
					<Attribution
						text={ attributionText(
							source.key,
							source.attribution
						) }
						url={ source.attribution_url }
					/>
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * Every source this site can use, and what Pro adds.
 *
 * @param {Object}   props
 * @param {Object}   props.state The `/authority` answer.
 * @param {Function} props.done  Reload.
 * @return {Element} Sources.
 */
function SourcesTab( { state, done } ) {
	const sources = state.sources || [];
	const available = sources.filter( ( s ) => s.available );
	const more = sources.filter( ( s ) => ! s.available );
	return (
		<div className="zseo-stack">
			{ available.map( ( s ) => (
				<SourceCard
					key={ s.key }
					state={ state }
					source={ s }
					done={ done }
				/>
			) ) }
			{ more.length > 0 && (
				<ProTeaser
					title={ __(
						'Bring the SEO tools you already pay for',
						'zinn-seo'
					) }
					points={ [
						sprintf(
							/* translators: %s: a list of company names. */
							__(
								'Connect your own %s account and see their scores for your site right here.',
								'zinn-seo'
							),
							more.map( ( s ) => s.name ).join( ', ' )
						),
						__(
							'Track up to ten competitors on the same scores, side by side.',
							'zinn-seo'
						),
						__(
							'Every score in your white-label client reports, with history.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}

/**
 * History, with competitors, as a chart and a download.
 *
 * @param {Object}  props
 * @param {Object}  props.state       The `/authority` answer.
 * @param {boolean} props.withMarkers Show the days of SEO changes and alerts (Pro, zs-651).
 * @return {Element} History.
 */
function HistoryTab( { state, withMarkers } ) {
	const options = useMemo(
		() =>
			trackedOptions( state ).map( ( o ) => {
				const source = ( state.sources || [] ).find(
					( s ) => s.key === o.source
				);
				const spec = ( ( source && source.metrics ) || [] ).find(
					( m ) => m.key === o.metric
				);
				return {
					...o,
					label: `${ metricName( o.metric, spec ? spec.name : '' ) } — ${ sourceName(
						o.source,
						source ? source.name : o.source
					) }`,
				};
			} ),
		[ state ]
	);
	const [ pick, setPick ] = useState(
		options[ 0 ] ? options[ 0 ].value : ''
	);
	const [ days, setDays ] = useState( '365' );
	const [ copied, setCopied ] = useState( false );
	const chosen = options.find( ( o ) => o.value === pick ) || options[ 0 ];
	// zs-651: the days of SEO changes and alerts, on the same chart (Pro).
	const markers = useLoad(
		withMarkers && chosen ? `/metrics/markers?days=${ days }` : null
	);
	const load = useLoad(
		chosen
			? `/authority/history?source=${ chosen.source }&metric=${ chosen.metric }&days=${ days }`
			: null
	);
	if ( ! chosen ) {
		return (
			<p>
				{ __(
					'Choose at least one score to keep history for, under Connect and choose.',
					'zinn-seo'
				) }
			</p>
		);
	}
	const series = ( load.data && load.data.series ) || {};
	const marks = markers.data || null;
	const copySheets = () => {
		const done = () => setCopied( true );
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard
				.writeText( toTsv( series ) )
				.then( done );
		}
	};
	const download = () => {
		const blob = new Blob( [ toCsv( series ) ], { type: 'text/csv' } );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = `${ chosen.source }-${ chosen.metric }-${ state.site }.csv`;
		a.click();
		URL.revokeObjectURL( a.href );
	};
	return (
		<div className="zseo-stack">
			<div className="zseo-row zseo-authority__filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Score', 'zinn-seo' ) }
					value={ chosen.value }
					options={ options.map( ( o ) => ( {
						value: o.value,
						label: o.label,
					} ) ) }
					onChange={ setPick }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Period', 'zinn-seo' ) }
					value={ days }
					options={ [
						{
							value: '30',
							label: __( 'Last 30 days', 'zinn-seo' ),
						},
						{
							value: '90',
							label: __( 'Last 3 months', 'zinn-seo' ),
						},
						{
							value: '365',
							label: __( 'Last 12 months', 'zinn-seo' ),
						},
						{
							value: '1095',
							label: __( 'All history (3 years)', 'zinn-seo' ),
						},
					] }
					onChange={ setDays }
				/>
			</div>
			{ load.error && <Refusal error={ load.error } /> }
			{ ! load.data && ! load.error && <Spinner /> }
			{ load.data && Object.keys( series ).length === 0 && (
				<p>
					{ __(
						'No readings yet. The first check runs shortly after you save.',
						'zinn-seo'
					) }
				</p>
			) }
			{ load.data && Object.keys( series ).length > 0 && (
				<>
					<HistoryChart
						series={ series }
						own={ state.site }
						label={ chosen.label }
						markers={ marks }
					/>
					<div className="zseo-row">
						<Button variant="secondary" onClick={ download }>
							{ __(
								'Download as a spreadsheet (CSV)',
								'zinn-seo'
							) }
						</Button>
						<Button variant="secondary" onClick={ copySheets }>
							{ copied
								? __(
										'Copied — paste into Google Sheets',
										'zinn-seo'
									)
								: __( 'Copy for Google Sheets', 'zinn-seo' ) }
						</Button>
						<Attribution
							text={ attributionText(
								chosen.source,
								load.data.attribution
							) }
							url={ load.data.attribution_url }
						/>
					</div>
				</>
			) }
		</div>
	);
}

/**
 * Competitors measured beside this site.
 *
 * @param {Object}   props
 * @param {Object}   props.state The `/authority` answer.
 * @param {Function} props.done  Reload.
 * @return {Element} Competitors.
 */
function CompetitorsTab( { state, done } ) {
	const act = useAction();
	const max = state.max_competitors || 10;
	const [ rows, setRows ] = useState(
		( state.targets || [] )
			.filter( ( t ) => t.role === 'competitor' )
			.map( ( t ) => ( { domain: t.domain, label: t.label } ) )
	);
	if ( ! state.canCompete ) {
		return (
			<ProTeaser
				title={ __( 'See how you compare', 'zinn-seo' ) }
				points={ [
					__(
						'Track up to ten competitors on every score you track for yourself.',
						'zinn-seo'
					),
					__(
						'Their history on the same chart as yours, so you see who is gaining.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	const bad = rows.some( ( r ) => r.domain && ! bareDomain( r.domain ) );
	return (
		<div className="zseo-stack">
			<p>
				{ sprintf(
					/* translators: %d: how many competitors may be tracked. */
					_n(
						'Add up to %d competitor. Every source you track measures them on its next check, from your own allowance.',
						'Add up to %d competitors. Every source you track measures them on its next check, from your own allowance.',
						max,
						'zinn-seo'
					),
					max
				) }
			</p>
			{ rows.map( ( r, i ) => (
				<div className="zseo-authority__rule" key={ i }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Website', 'zinn-seo' ) }
						value={ r.domain }
						onChange={ ( v ) =>
							setRows(
								rows.map( ( x, j ) =>
									j === i ? { ...x, domain: v } : x
								)
							)
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Name (optional)', 'zinn-seo' ) }
						value={ r.label }
						onChange={ ( v ) =>
							setRows(
								rows.map( ( x, j ) =>
									j === i ? { ...x, label: v } : x
								)
							)
						}
					/>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () =>
							setRows( rows.filter( ( _x, j ) => j !== i ) )
						}
					>
						{ __( 'Remove', 'zinn-seo' ) }
					</Button>
				</div>
			) ) }
			{ bad && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Type a website address, for example example.com.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ act.error && (
				<Refusal error={ act.error } onRemove={ act.clear } />
			) }
			{ act.done && (
				<Notice status="success" onRemove={ act.clear }>
					{ act.done }
				</Notice>
			) }
			<div className="zseo-row">
				{ rows.length < max && (
					<Button
						variant="secondary"
						onClick={ () =>
							setRows( [ ...rows, { domain: '', label: '' } ] )
						}
					>
						{ __( 'Add a competitor', 'zinn-seo' ) }
					</Button>
				) }
				<Button
					variant="primary"
					disabled={ bad || !! act.busy }
					isBusy={ !! act.busy }
					onClick={ () =>
						act
							.run(
								'competitors',
								() =>
									seoFetch( '/authority/competitors', {
										method: 'PUT',
										data: {
											competitors: rows
												.map( ( r ) => ( {
													domain: bareDomain(
														r.domain
													),
													label: r.label,
												} ) )
												.filter( ( r ) => r.domain ),
										},
									} ),
								__( 'Competitors saved.', 'zinn-seo' )
							)
							.then( ( ok ) => ok && done() )
					}
				>
					{ __( 'Save competitors', 'zinn-seo' ) }
				</Button>
			</div>
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
export default function AuthorityScreen( { data } ) {
	const load = useLoad( '/authority' );
	const tabs = authorityTabs();
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	const [ current, setCurrent ] = useState(
		tabs.some( ( t ) => t.name === initial ) ? initial : 'scores'
	);
	const state = load.data || {};
	const done = () => load.reload( '/authority?fresh=1' );
	const go = ( name ) => {
		setCurrent( name );
		window.history.replaceState( null, '', `#/${ name }` );
	};
	return (
		<div className="zseo-admin zseo-tools zseo-authority">
			<div className="zseo-cardhead">
				<h2 className="zseo-hero">
					{ __(
						'Your site’s authority — Domain Rating, Trust Flow and more, watched for you',
						'zinn-seo'
					) }
				</h2>
				<NewTab href={ guideUrl( data, 'authority' ) }>
					{ __( 'Step-by-step guide', 'zinn-seo' ) }
				</NewTab>
			</div>
			{ load.error && ! load.data && <Refusal error={ load.error } /> }
			{ ! load.data && ! load.error && <Spinner /> }
			{ load.data && ! state.connected && (
				<Card>
					<CardBody className="zseo-stack">
						<p>
							{ __(
								'Connect this site to your free Zinn® account first. Your keys are then kept safely by Zinn®, never in this site.',
								'zinn-seo'
							) }
						</p>
						<div>
							<Button
								variant="primary"
								href={ viewHref( 'credits' ) }
							>
								{ __( 'Connect this site', 'zinn-seo' ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }
			{ load.data && state.connected && (
				<TabPanel
					key={ current }
					className="zseo-tabs"
					tabs={ tabs }
					initialTabName={ current }
					onSelect={ ( name ) => {
						if ( name !== current ) {
							go( name );
						}
					} }
				>
					{ ( tab ) => {
						switch ( tab.name ) {
							case 'sources':
								return (
									<SourcesTab state={ state } done={ done } />
								);
							case 'history':
								return (
									<HistoryTab
										state={ state }
										withMarkers={ !! data.metrics }
									/>
								);
							case 'competitors':
								return (
									<CompetitorsTab
										state={ state }
										done={ done }
									/>
								);
							default:
								return <ScoresTab state={ state } go={ go } />;
						}
					} }
				</TabPanel>
			) }
		</div>
	);
}
