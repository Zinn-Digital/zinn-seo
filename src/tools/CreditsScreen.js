/**
 * Credit: connect the site to a Zinn® account (or make one), see the balance, how long it lasts
 * and what is paused, add credit in a new tab, read notices, choose the site's own e-mails.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { seoFetch, useSettings } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { Loaded, NewTab, Refusal, Table, useAction, useLoad } from './parts';
import { count, day, money, price } from './pure';
import { TopUp } from './CreditSettings';
import { noticeKind, toolName, unitName } from './labels';
import './tools.scss';

/**
 * What connecting sends, in a sentence.
 *
 * @param {Object} sends `{ site_url, plugin_version, wp_version, locale }`.
 * @return {string} Sentence.
 */
function sendsText( sends ) {
	return sprintf(
		/* translators: 1: the site's address, 2: plugin version, 3: WordPress version, 4: language code. */
		__(
			'Connecting sends this site’s address (%1$s), the plugin version (%2$s), the WordPress version (%3$s) and its language (%4$s) to Zinn Digital®. Nothing about your visitors or your content is sent, and nothing at all is sent until you press a button below.',
			'zinn-seo'
		),
		sends.site_url,
		sends.plugin_version,
		sends.wp_version,
		sends.locale
	);
}

/**
 * Not connected: what you get, what is sent, and the two doors.
 *
 * @param {Object}   props
 * @param {Object}   props.data       Boot data.
 * @param {Object}   props.connection The connection's state.
 * @param {Function} props.onChange   Called with the new state.
 * @return {Element} Card.
 */
function ConnectCard( { data, connection, onChange } ) {
	const [ agreed, setAgreed ] = useState( false );
	const [ fresh, setFresh ] = useState( false );
	const [ email, setEmail ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const action = useAction();
	const canSpend = !! data.canSpend;

	const start = ( path, body ) => {
		// The approval page opens in a new tab. The tab is opened here, in the click, so the
		// browser does not block it, and pointed at the page once Zinn® has answered.
		const tab = fresh ? null : window.open( 'about:blank', '_blank' );
		action
			.run( 'connect', () =>
				seoFetch( path, { method: 'POST', data: body } )
			)
			.then( ( next ) => {
				if ( ! next ) {
					if ( tab ) {
						tab.close();
					}
					return;
				}
				if ( tab && next.pending && next.pending.verify_url ) {
					tab.opener = null;
					tab.location.replace( next.pending.verify_url );
				} else if ( tab ) {
					tab.close();
				}
				onChange( next );
			} );
	};

	return (
		<Card className="zseo-connect">
			<CardBody className="zseo-stack">
				<h2 className="zseo-hero">
					{ __(
						'See where you rank, what Google has indexed and whether AI answers mention you',
						'zinn-seo'
					) }
				</h2>
				<p className="zseo-lead">
					{ __(
						'Connect this site to a Zinn® account and run the checks from right here. There is no subscription: you add credit and pay only for what you run, from a fraction of a cent per check.',
						'zinn-seo'
					) }
				</p>
				<ul className="zseo-benefits">
					<li>
						{ __(
							'Track your Google positions for the phrases that bring you customers',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'Find the pages Google has not indexed, before they cost you visitors',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'Know whether ChatGPT, Perplexity, Gemini and Claude recommend you',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'Hear the day a backlink you earned disappears',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'Audit the whole site and jump straight to the page that needs fixing',
							'zinn-seo'
						) }
					</li>
				</ul>
				{ 'revoked' === connection.status && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This site was disconnected from its Zinn® account. Connect it again to carry on — your results and your credit are safe.',
							'zinn-seo'
						) }
					</Notice>
				) }
				<Refusal error={ action.error } onRemove={ action.clear } />
				{ ! canSpend ? (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Only an administrator of this site can connect it, because connecting decides who pays for the checks.',
							'zinn-seo'
						) }
					</Notice>
				) : (
					<>
						<p className="zseo-muted zseo-sends">
							{ sendsText( connection.sends || {} ) }{ ' ' }
							<NewTab href="https://zinndigital.com/legal/privacy">
								{ __( 'Privacy policy', 'zinn-seo' ) }
							</NewTab>
						</p>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'I agree to connect this site to Zinn Digital®',
								'zinn-seo'
							) }
							checked={ agreed }
							onChange={ setAgreed }
						/>
						{ ! fresh ? (
							<div className="zseo-actions zseo-actions--start">
								<Button
									variant="primary"
									disabled={ ! agreed || !! action.busy }
									isBusy={ 'connect' === action.busy }
									onClick={ () =>
										start( '/connect', { consent: true } )
									}
								>
									{ __(
										'Connect my Zinn® account',
										'zinn-seo'
									) }
								</Button>
								<Button
									variant="secondary"
									disabled={ !! action.busy }
									onClick={ () => setFresh( true ) }
								>
									{ __(
										'I am new — create a free account',
										'zinn-seo'
									) }
								</Button>
							</div>
						) : (
							<div className="zseo-stack zseo-signup">
								<div className="zseo-row">
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										type="email"
										label={ __(
											'Your e-mail address',
											'zinn-seo'
										) }
										help={ __(
											'We e-mail you a link to choose your password. No card is needed to create the account.',
											'zinn-seo'
										) }
										value={ email }
										onChange={ setEmail }
									/>
									<TextControl
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										label={ __(
											'Your name (optional)',
											'zinn-seo'
										) }
										value={ name }
										onChange={ setName }
									/>
								</div>
								<div className="zseo-actions zseo-actions--start">
									<Button
										variant="primary"
										disabled={
											! agreed ||
											! /.+@.+\..+/.test( email ) ||
											!! action.busy
										}
										isBusy={ 'connect' === action.busy }
										onClick={ () =>
											start( '/connect/signup', {
												consent: true,
												email,
												name,
											} )
										}
									>
										{ __(
											'Create my account and connect',
											'zinn-seo'
										) }
									</Button>
									<Button
										variant="tertiary"
										disabled={ !! action.busy }
										onClick={ () => setFresh( false ) }
									>
										{ __(
											'I already have an account',
											'zinn-seo'
										) }
									</Button>
								</div>
							</div>
						) }
					</>
				) }
				<GuideLink data={ data } id="credits-connect" />
			</CardBody>
		</Card>
	);
}

/**
 * Waiting for the owner to approve in the other tab.
 *
 * @param {Object}   props
 * @param {Object}   props.connection The connection's state (with `pending`).
 * @param {Function} props.onChange   Called with the new state.
 * @return {Element} Card.
 */
function PendingCard( { connection, onChange } ) {
	const pending = connection.pending || {};
	const [ outcome, setOutcome ] = useState( '' );
	const [ error, setError ] = useState( null );
	const timer = useRef( null );

	useEffect( () => {
		let stopped = false;
		const ask = () => {
			seoFetch( '/connect/poll', { method: 'POST' } )
				.then( ( next ) => {
					if ( stopped ) {
						return;
					}
					if ( 'waiting' === next.outcome ) {
						timer.current = window.setTimeout(
							ask,
							( ( next.pending && next.pending.interval ) || 5 ) *
								1000
						);
						return;
					}
					setOutcome( next.outcome );
					onChange( next );
				} )
				.catch( ( e ) => {
					if ( ! stopped ) {
						setError( e );
						timer.current = window.setTimeout( ask, 15000 );
					}
				} );
		};
		timer.current = window.setTimeout(
			ask,
			( pending.interval || 5 ) * 1000
		);
		return () => {
			stopped = true;
			window.clearTimeout( timer.current );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ pending.user_code ] );

	const cancel = () =>
		seoFetch( '/connect?pending=1', { method: 'DELETE' } ).then( onChange );

	return (
		<Card className="zseo-connect is-pending">
			<CardBody className="zseo-stack">
				<h2 className="zseo-hero">
					{ 'created' === pending.account
						? __( 'Check your e-mail to finish', 'zinn-seo' )
						: __(
								'Approve this site in the tab that just opened',
								'zinn-seo'
							) }
				</h2>
				{ 'created' === pending.account ? (
					<p className="zseo-lead">
						{ sprintf(
							/* translators: %s: an e-mail address. */
							__(
								'We sent a link to %s. Open it, choose your password, and this site connects by itself — you can leave this page open.',
								'zinn-seo'
							),
							pending.email
						) }
					</p>
				) : (
					<p className="zseo-lead">
						{ 'exists' === pending.account
							? __(
									'There is already a Zinn® account for that address. Sign in there and approve this site — no password is ever typed into WordPress.',
									'zinn-seo'
								)
							: __(
									'Sign in to Zinn Digital®, check the code matches and choose which account pays. No password is ever typed into WordPress.',
									'zinn-seo'
								) }
					</p>
				) }
				<p className="zseo-code-line">
					<span className="zseo-muted">
						{ __( 'Your code', 'zinn-seo' ) }
					</span>
					<code className="zseo-usercode" dir="ltr">
						{ pending.user_code }
					</code>
				</p>
				<div className="zseo-actions zseo-actions--start">
					<NewTab
						className="components-button is-primary"
						href={ pending.verify_url }
					>
						{ __( 'Open the approval page', 'zinn-seo' ) }
					</NewTab>
					<Button variant="tertiary" onClick={ cancel }>
						{ __( 'Cancel', 'zinn-seo' ) }
					</Button>
				</div>
				<p className="zseo-waiting" role="status">
					<Spinner />
					{ __( 'Waiting for your approval…', 'zinn-seo' ) }{ ' ' }
					<span className="zseo-muted">
						{ sprintf(
							/* translators: %s: a number of minutes. */
							_n(
								'The code works for %s more minute.',
								'The code works for %s more minutes.',
								Math.max(
									1,
									Math.round(
										( pending.expires_in || 0 ) / 60
									)
								),
								'zinn-seo'
							),
							count(
								Math.max(
									1,
									Math.round(
										( pending.expires_in || 0 ) / 60
									)
								)
							)
						) }
					</span>
				</p>
				{ outcome && 'connected' !== outcome && (
					<Notice status="warning" isDismissible={ false }>
						{ 'denied' === outcome
							? __(
									'The request was declined. Nothing was connected.',
									'zinn-seo'
								)
							: __(
									'The code ran out before it was approved. Start again when you are ready.',
									'zinn-seo'
								) }
					</Notice>
				) }
				<Refusal error={ error } onRemove={ () => setError( null ) } />
			</CardBody>
		</Card>
	);
}

/**
 * The sentence for the account's credit state.
 *
 * @param {Object} account The account.
 * @return {{status: string, text: string}} Notice.
 */
function stateLine( account ) {
	switch ( account.state ) {
		case 'held':
			return {
				status: 'warning',
				text: __(
					'Some checks are paused because the credit ran out. Nothing is lost: add credit and they carry on by themselves, oldest first.',
					'zinn-seo'
				),
			};
		case 'empty':
			return {
				status: 'warning',
				text: __(
					'You are out of credit. Scheduled checks pause until you add more, then carry on by themselves.',
					'zinn-seo'
				),
			};
		case 'short':
			return {
				status: 'warning',
				text: account.forecast.runs_out_on
					? sprintf(
							/* translators: %s: a date. */
							__(
								'At your current pace your credit runs out on %s. Add credit before then and nothing pauses.',
								'zinn-seo'
							),
							day( account.forecast.runs_out_on )
						)
					: __(
							'Your credit does not cover the next 30 days of scheduled checks. Add credit and nothing pauses.',
							'zinn-seo'
						),
			};
		case 'low':
			return {
				status: 'info',
				text: __(
					'Your credit is running low. Add some now and your checks keep running without a gap.',
					'zinn-seo'
				),
			};
		default:
			return {
				status: 'success',
				text: __(
					'Your credit covers the next 30 days of scheduled checks.',
					'zinn-seo'
				),
			};
	}
}

/**
 * Notices from Zinn®, with dismiss.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Card.
 */
function NoticesCard( { data } ) {
	// Read now, not from the copy kept for the menu's count: this is where someone comes to
	// see what just happened. An unchanged list costs an empty answer.
	const load = useLoad( '/notices?fresh=1' );
	const action = useAction();
	const dismiss = ( body ) =>
		action
			.run( 'dismiss', () =>
				seoFetch( '/notices/dismiss', { method: 'POST', data: body } )
			)
			.then( ( next ) => next && load.setData( next ) );
	return (
		<Card className="zseo-notices">
			<CardBody className="zseo-stack">
				<div className="zseo-cardhead">
					<h2 className="zseo-card-title">
						{ __( 'Notices', 'zinn-seo' ) }
					</h2>
					<GuideLink data={ data } id="credits-notices" />
				</div>
				<Refusal error={ action.error } onRemove={ action.clear } />
				<Loaded load={ load }>
					{ ( feed ) => {
						const open = feed.items.filter( ( n ) => ! n.acked );
						if ( ! feed.items.length ) {
							return (
								<p className="zseo-muted">
									{ __(
										'Nothing to report. You will hear here — and by e-mail — when credit runs low, a check pauses or resumes, a ranking falls or a link is lost.',
										'zinn-seo'
									) }
								</p>
							);
						}
						return (
							<>
								<ul className="zseo-noticelist">
									{ feed.items.map( ( n ) => (
										<li
											key={ n.id }
											className={ `is-${ n.severity }${
												n.acked ? ' is-read' : ''
											}` }
										>
											<div className="zseo-noticelist__top">
												<span
													className={ `zseo-tag is-${ n.severity }` }
												>
													{ noticeKind( n.kind ) }
												</span>
												<span className="zseo-muted zseo-small">
													{ day( n.at, true ) }
												</span>
											</div>
											<strong>{ n.title }</strong>
											<p>{ n.message }</p>
											{ ! n.acked && (
												<Button
													variant="link"
													disabled={ !! action.busy }
													onClick={ () =>
														dismiss( {
															ids: [ n.id ],
														} )
													}
												>
													{ __(
														'Dismiss',
														'zinn-seo'
													) }
												</Button>
											) }
										</li>
									) ) }
								</ul>
								{ open.length > 1 && (
									<div>
										<Button
											variant="secondary"
											disabled={ !! action.busy }
											onClick={ () =>
												dismiss( { all: true } )
											}
										>
											{ __( 'Dismiss all', 'zinn-seo' ) }
										</Button>
									</div>
								) }
							</>
						);
					} }
				</Loaded>
			</CardBody>
		</Card>
	);
}

/**
 * Which notices this site e-mails, and to whom.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element|null} Card.
 */
function MailCard( { data } ) {
	const { settings, save, error } = useSettings();
	const [ to, setTo ] = useState( null );
	const [ fail, setFail ] = useState( '' );
	if ( ! data.canManage ) {
		return null;
	}
	if ( ! settings ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const put = ( changes ) =>
		save( changes ).catch( ( e ) => setFail( e.message || '' ) );
	return (
		<Card>
			<CardBody className="zseo-stack">
				<h2 className="zseo-card-title">
					{ __( 'E-mails from this site', 'zinn-seo' ) }
				</h2>
				<p className="zseo-muted">
					{ __(
						'Zinn® e-mails the owner of the account. This site can also e-mail whoever looks after it, so they hear straight away when a check pauses or a problem is found — even when the account belongs to your agency.',
						'zinn-seo'
					) }
				</p>
				{ fail && (
					<Notice status="error" onRemove={ () => setFail( '' ) }>
						{ fail }
					</Notice>
				) }
				<div className="zseo-row">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Send', 'zinn-seo' ) }
						value={ settings.notices_email || 'important' }
						options={ [
							{
								value: 'important',
								label: __(
									'The important ones (paused checks, problems found)',
									'zinn-seo'
								),
							},
							{
								value: 'all',
								label: __( 'Every notice', 'zinn-seo' ),
							},
							{
								value: 'off',
								label: __( 'Nothing', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) => put( { notices_email: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="email"
						label={ __( 'To', 'zinn-seo' ) }
						placeholder={ data.adminEmail || '' }
						help={ __(
							'Leave empty for the site’s admin address.',
							'zinn-seo'
						) }
						value={
							null === to ? settings.notices_email_to || '' : to
						}
						onChange={ setTo }
						onBlur={ () =>
							null !== to &&
							put( { notices_email_to: to } ).then( () =>
								setTo( null )
							)
						}
					/>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * Connected: the balance, the forecast, what is paused, buying, prices.
 *
 * @param {Object}   props
 * @param {Object}   props.data       Boot data.
 * @param {Object}   props.connection The connection's state.
 * @param {Function} props.onChange   Called with the new connection state.
 * @return {Element} Screen.
 */
function Account( { data, connection, onChange } ) {
	// Asked fresh each time this screen opens: it is where people look right after paying.
	const load = useLoad( '/credits?fresh=1' );
	const action = useAction();
	const [ sure, setSure ] = useState( false );

	return (
		<div className="zseo-stack">
			<Loaded load={ load }>
				{ ( account ) => {
					const line = stateLine( account );
					const held = Object.entries( account.holds.site || {} );
					return (
						<>
							<Card
								className={ `zseo-balance is-${ account.state }` }
							>
								<CardBody className="zseo-stack">
									<div className="zseo-cardhead">
										<h2 className="zseo-card-title">
											{ sprintf(
												/* translators: %s: the name of a Zinn account. */
												__(
													'Credit of %s',
													'zinn-seo'
												),
												connection.org_name
											) }
										</h2>
										<GuideLink data={ data } id="credits" />
									</div>
									<Notice
										status={ line.status }
										isDismissible={ false }
										className="zseo-state"
									>
										{ line.text }
									</Notice>
									{ held.length > 0 && (
										<ul className="zseo-held">
											{ held.map( ( [ kind, since ] ) => (
												<li key={ kind }>
													{ sprintf(
														/* translators: 1: the name of a check, 2: a date. */
														__(
															'%1$s — paused since %2$s',
															'zinn-seo'
														),
														toolName( kind ),
														day( since, true )
													) }
												</li>
											) ) }
										</ul>
									) }
									<dl className="zseo-stats">
										<div className="zseo-stats__item">
											<dt>
												{ __( 'Credit', 'zinn-seo' ) }
											</dt>
											<dd className="zseo-balance__amount">
												{ money(
													account.balance_micros
												) }
											</dd>
										</div>
										<div className="zseo-stats__item">
											<dt>
												{ __(
													'Scheduled checks, next 30 days',
													'zinn-seo'
												) }
											</dt>
											<dd>
												{ money(
													account.forecast
														.spend_micros
												) }
											</dd>
										</div>
										<div className="zseo-stats__item">
											<dt>
												{ __(
													'This site, this month',
													'zinn-seo'
												) }
											</dt>
											<dd>
												{ money(
													account.site.spent_micros
												) }
											</dd>
										</div>
										{ null !== account.site.cap_micros && (
											<div className="zseo-stats__item">
												<dt>
													{ __(
														'Monthly limit for this site',
														'zinn-seo'
													) }
												</dt>
												<dd>
													{ money(
														account.site.cap_micros
													) }
												</dd>
											</div>
										) }
									</dl>
									<div className="zseo-actions zseo-actions--start">
										<NewTab
											className="components-button is-primary zseo-buy"
											href={ account.buy_url }
										>
											{ __( 'Add credit', 'zinn-seo' ) }
										</NewTab>
										<Button
											variant="secondary"
											isBusy={ load.loading }
											disabled={ load.loading }
											onClick={ () =>
												load.reload(
													'/credits?fresh=1'
												)
											}
										>
											{ __(
												'I have added credit — refresh',
												'zinn-seo'
											) }
										</Button>
									</div>
									<p className="zseo-muted zseo-small">
										{ __(
											'Adding credit opens your Zinn® account in a new tab; your card is never typed into WordPress. Checks that were paused start again by themselves a few minutes after the payment.',
											'zinn-seo'
										) }
									</p>
									{ account.packs.length > 0 && (
										<ul className="zseo-packs">
											{ account.packs.map( ( p ) => (
												<li key={ p.code }>
													<strong>
														{ price(
															p.price_minor,
															p.currency
														) }
													</strong>
													<span>
														{ sprintf(
															/* translators: %s: an amount of money. */
															__(
																'%s of credit',
																'zinn-seo'
															),
															money(
																p.grant_micros
															)
														) }
													</span>
													{ p.bonus_micros > 0 && (
														<span className="zseo-tag is-success">
															{ sprintf(
																/* translators: %s: an amount of money. */
																__(
																	'includes %s extra',
																	'zinn-seo'
																),
																money(
																	p.bonus_micros
																)
															) }
														</span>
													) }
												</li>
											) ) }
										</ul>
									) }
									<TopUp account={ account } />
								</CardBody>
							</Card>
							<Card>
								<CardBody className="zseo-stack">
									<div className="zseo-cardhead">
										<h2 className="zseo-card-title">
											{ __(
												'What each check costs',
												'zinn-seo'
											) }
										</h2>
										<a href={ viewHref( 'checks' ) }>
											{ __(
												'Go to the checks',
												'zinn-seo'
											) }
										</a>
									</div>
									<p className="zseo-muted">
										{ __(
											'You always see the price before anything runs, and a check that cannot give an answer is refunded.',
											'zinn-seo'
										) }
									</p>
									<Table
										label={ __(
											'What each check costs',
											'zinn-seo'
										) }
										rowKey={ ( r ) => r[ 0 ] }
										rows={ Object.entries(
											account.prices || {}
										) }
										columns={ [
											{
												id: 'what',
												label: __(
													'Check',
													'zinn-seo'
												),
												render: ( r ) =>
													unitName( r[ 0 ] ),
											},
											{
												id: 'price',
												label: __(
													'Price',
													'zinn-seo'
												),
												numeric: true,
												render: ( r ) =>
													money( r[ 1 ] ),
											},
										] }
									/>
								</CardBody>
							</Card>
						</>
					);
				} }
			</Loaded>
			<NoticesCard data={ data } />
			<MailCard data={ data } />
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'Connection', 'zinn-seo' ) }
					</h2>
					<p>
						{ sprintf(
							/* translators: 1: the site's address, 2: the name of a Zinn account, 3: a date. */
							__(
								'%1$s is connected to the Zinn® account %2$s since %3$s.',
								'zinn-seo'
							),
							connection.site_url,
							connection.org_name,
							day( connection.connected_at )
						) }
					</p>
					{ ! connection.can_spend && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'This site is connected for viewing only: it shows results but cannot start paid checks. The owner of the Zinn® account can allow it.',
								'zinn-seo'
							) }
						</Notice>
					) }
					<Refusal error={ action.error } onRemove={ action.clear } />
					{ data.canSpend && (
						<div className="zseo-actions zseo-actions--start">
							{ ! sure ? (
								<Button
									variant="secondary"
									isDestructive
									onClick={ () => setSure( true ) }
								>
									{ __( 'Disconnect this site', 'zinn-seo' ) }
								</Button>
							) : (
								<>
									<span>
										{ __(
											'Scheduled checks keep their history in your Zinn® account. Disconnect?',
											'zinn-seo'
										) }
									</span>
									<Button
										variant="primary"
										isDestructive
										isBusy={ 'off' === action.busy }
										onClick={ () =>
											action
												.run( 'off', () =>
													seoFetch( '/connect', {
														method: 'DELETE',
													} )
												)
												.then(
													( next ) =>
														next && onChange( next )
												)
										}
									>
										{ __( 'Yes, disconnect', 'zinn-seo' ) }
									</Button>
									<Button
										variant="tertiary"
										onClick={ () => setSure( false ) }
									>
										{ __(
											'Keep it connected',
											'zinn-seo'
										) }
									</Button>
								</>
							) }
						</div>
					) }
				</CardBody>
			</Card>
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
export default function CreditsScreen( { data } ) {
	const load = useLoad( '/connect' );
	return (
		<div className="zseo-admin zseo-tools zseo-credits">
			<Loaded load={ load }>
				{ ( connection ) => {
					if ( 'active' === connection.status ) {
						return (
							<Account
								data={ data }
								connection={ connection }
								onChange={ load.setData }
							/>
						);
					}
					if ( 'pending' === connection.status ) {
						return (
							<PendingCard
								connection={ connection }
								onChange={ load.setData }
							/>
						);
					}
					return (
						<ConnectCard
							data={ data }
							connection={ connection }
							onChange={ load.setData }
						/>
					);
				} }
			</Loaded>
		</div>
	);
}
