/**
 * Low credit and automatic top-up, inside the plugin (P10 / Q127), and the credit card on the
 * Overview screen.
 *
 * ⛔ The settings themselves live in the Zinn® account, on purpose: switching on automatic top-up
 * charges the owner's card, so it is changed where the owner signs in — never from a website that
 * could be compromised. The plugin shows every value as it is now and opens that exact page, on
 * the same organisation this site spends from (`?org=` in the address, P9).
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';

import { viewHref } from '../admin-kit/router';
import { NewTab, useLoad } from './parts';
import { day, money } from './pure';

/**
 * The account page that holds these settings.
 *
 * @param {string} buyUrl The account's credit page (names the organisation).
 * @return {string} Address.
 */
export function settingsUrl( buyUrl ) {
	return buyUrl ? `${ buyUrl.split( '#' )[ 0 ] }#ai-credit-auto-topup` : '';
}

/**
 * The card body: when the owner is warned, and how credit is topped up.
 *
 * @param {Object} props
 * @param {Object} props.account The account (`/credits`).
 * @return {Element} Settings.
 */
export function TopUp( { account } ) {
	const auto = account.auto_topup || {};
	const pack = ( account.packs || [] ).find( ( p ) => p.code === auto.pack );
	const rows = [
		[
			__( 'Low-credit warning', 'zinn-seo' ),
			sprintf(
				/* translators: %s: an amount of money. */
				__(
					'When credit falls below a fifth of your last top-up (and always below %s): a notice here, and an e-mail if you chose e-mails below.',
					'zinn-seo'
				),
				money( 2200000 )
			),
		],
		[
			__( 'Automatic top-up', 'zinn-seo' ),
			auto.on
				? sprintf(
						/* translators: 1: a credit pack, 2: an amount of money. */
						__(
							'On: adds %1$s when credit falls below %2$s, so nothing pauses.',
							'zinn-seo'
						),
						pack ? pack.name : __( 'a credit pack', 'zinn-seo' ),
						money( auto.threshold_micros )
					)
				: __(
						'Off. Switch it on and credit is added before it runs out, so your checks and AI never pause.',
						'zinn-seo'
					),
		],
	];
	if ( auto.on && auto.cap_micros ) {
		rows.push( [
			__( 'Monthly limit', 'zinn-seo' ),
			sprintf(
				/* translators: 1: an amount of money, 2: an amount of money. */
				__(
					'Up to %1$s a month (%2$s added so far this month).',
					'zinn-seo'
				),
				money( auto.cap_micros ),
				money( auto.spent_micros || 0 )
			),
		] );
	}
	return (
		<div className="zseo-stack">
			{ auto.on && ( auto.paused || auto.failed ) && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Automatic top-up is paused: the last payment did not go through. Check your card in your Zinn® account.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<dl className="zseo-settings-list">
				{ rows.map( ( [ term, value ] ) => (
					<div key={ term }>
						<dt>{ term }</dt>
						<dd>{ value }</dd>
					</div>
				) ) }
			</dl>
			<p className="zseo-muted">
				{ __(
					'These are kept in your Zinn® account, because a top-up charges your card: change them there, where only you can sign in.',
					'zinn-seo'
				) }{ ' ' }
				<NewTab href={ settingsUrl( account.buy_url ) }>
					{ __( 'Change these settings', 'zinn-seo' ) }
				</NewTab>
			</p>
		</div>
	);
}

/**
 * The credit card on the Overview screen.
 *
 * @param {Object}   props
 * @param {Function} props.navigate Go to a screen.
 * @return {Element} Card.
 */
export function OverviewCredit( { navigate } ) {
	const load = useLoad( '/credits' );
	const account = load.data;
	return (
		<Card className="zak-card zseo-ov-credit">
			<CardBody className="zseo-stack">
				<h2 className="zak-card__title">
					{ __( 'Zinn® credit', 'zinn-seo' ) }
				</h2>
				{ ! account && load.loading && <Spinner /> }
				{ ! account && ! load.loading && (
					<p className="zseo-muted">
						{ __(
							'The credit could not be read just now.',
							'zinn-seo'
						) }
					</p>
				) }
				{ account && ! account.connected && (
					<>
						<p>
							{ __(
								'Rank tracking, Google index checks, AI answers, backlinks and AI writing — pay only for what you use. No subscription.',
								'zinn-seo'
							) }
						</p>
						<div>
							<Button
								variant="primary"
								onClick={ () => navigate( 'credits' ) }
							>
								{ __( 'Connect this site', 'zinn-seo' ) }
							</Button>
						</div>
					</>
				) }
				{ account && account.connected && (
					<>
						<p
							className={ `zseo-ov-credit__amount is-${ account.state }` }
						>
							{ money( account.balance_micros ) }
						</p>
						<p className="zseo-muted">
							{ account.forecast && account.forecast.runs_out_on
								? sprintf(
										/* translators: %s: a date. */
										__(
											'Lasts until about %s at your current pace.',
											'zinn-seo'
										),
										day( account.forecast.runs_out_on )
									)
								: {
										held: __(
											'Some checks are paused until you add credit.',
											'zinn-seo'
										),
										empty: __(
											'Out of credit: scheduled checks wait for a top-up.',
											'zinn-seo'
										),
										low: __( 'Running low.', 'zinn-seo' ),
									}[ account.state ] ||
									__(
										'Enough for the next 30 days of scheduled checks.',
										'zinn-seo'
									) }
						</p>
						<p className="zseo-muted">
							{ account.auto_topup && account.auto_topup.on
								? __( 'Automatic top-up is on.', 'zinn-seo' )
								: __( 'Automatic top-up is off.', 'zinn-seo' ) }
						</p>
						<div className="zseo-actions zseo-actions--start">
							<NewTab
								className="components-button is-primary"
								href={ account.buy_url }
							>
								{ __( 'Add credit', 'zinn-seo' ) }
							</NewTab>
							<a href={ viewHref( 'credits' ) }>
								{ __( 'Credit settings', 'zinn-seo' ) }
							</a>
						</div>
					</>
				) }
			</CardBody>
		</Card>
	);
}
