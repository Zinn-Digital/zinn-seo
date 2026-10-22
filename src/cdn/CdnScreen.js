/**
 * Zinn® CDN: switch the site's images, CSS, scripts and fonts onto a global CDN in one click,
 * see this month's traffic and cost, empty its cache, switch it off.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Card, CardBody, Notice } from '@wordpress/components';

import { seoFetch } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { viewHref } from '../admin-kit/router';
import { Loaded, Refusal, useAction, useLoad } from '../tools/parts';
import { money } from '../tools/pure';
import { gigabytes, stateWords } from './pure';
import './cdn.scss';

/**
 * The price in a sentence.
 *
 * @param {Object} price `{ minimum_micros, per_gb_micros }`.
 * @return {string} Sentence.
 */
function priceText( price ) {
	const rates = ( price && price.per_gb_micros ) || {};
	return sprintf(
		/* translators: 1: price per GB in Europe and North America, 2: price per GB in Asia and Oceania, 3: the monthly minimum. */
		__(
			'%1$s per GB served in Europe and North America, %2$s in Asia and Oceania, more in South America, the Middle East and Africa. A %3$s monthly minimum, which your traffic counts towards. Paid from your Zinn® credit; nothing is charged when you switch it on.',
			'zinn-seo'
		),
		money( rates.eu_na ),
		money( rates.asia_oceania ),
		money( price ? price.minimum_micros : null )
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function CdnScreen( { data } ) {
	const load = useLoad( '/cdn' );
	const action = useAction();
	const canSpend = !! data.canSpend;

	const act = ( name, method, path ) =>
		action
			.run( name, () => seoFetch( path, { method } ) )
			.then( ( next ) => ( next ? load.reload() : null ) );

	return (
		<div className="zseo-stack zseo-cdn">
			<p className="zseo-lede">
				{ __(
					'Your images, CSS, scripts and fonts load from servers close to each visitor, so pages appear faster. Your domain, your hosting and your pages stay exactly as they are, and switching it off puts everything back at once.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cdn" />
			</p>
			<Refusal error={ action.error } />
			<Loaded load={ load }>
				{ ( cdn ) => {
					if ( ! cdn.connected ) {
						return (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Connect this site to a Zinn® account to use the CDN.',
									'zinn-seo'
								) }{ ' ' }
								<a href={ viewHref( 'credits' ) }>
									{ __( 'Connect', 'zinn-seo' ) }
								</a>
							</Notice>
						);
					}
					if ( cdn.hosted ) {
						return (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'This site is on Zinn® hosting, so it already has our CDN. Turn on Premium CDN for it in your Zinn® dashboard.',
									'zinn-seo'
								) }
							</Notice>
						);
					}
					const zone = cdn.zone;
					const on =
						zone &&
						[
							'active',
							'paused_credit',
							'paused_standing',
						].includes( zone.state );
					return (
						<Card>
							<CardBody className="zseo-stack">
								<p
									className="zseo-cdn__state"
									data-state={ zone ? zone.state : 'none' }
								>
									<strong>
										{ cdn.local && cdn.local.warming
											? __(
													'Getting ready: the CDN is reaching its locations around the world. Your files move to it by themselves within a few minutes.',
													'zinn-seo'
												)
											: stateWords(
													zone ? zone.state : ''
												) }
									</strong>
								</p>
								{ on && zone.hostname && (
									<p>
										{ __(
											'Files are served from:',
											'zinn-seo'
										) }{ ' ' }
										<code dir="ltr">{ zone.hostname }</code>
									</p>
								) }
								{ zone && (
									<dl className="zseo-cdn__month">
										<dt>
											{ __(
												'Downloaded this month',
												'zinn-seo'
											) }
										</dt>
										<dd>
											{ gigabytes( zone.month.bytes ) }
										</dd>
										<dt>
											{ __(
												'Charged this month',
												'zinn-seo'
											) }
										</dt>
										<dd>
											{ money(
												zone.month.charged_micros
											) }
										</dd>
										{ zone.month.owed_micros > 0 && (
											<>
												<dt>
													{ __(
														'Waiting for credit',
														'zinn-seo'
													) }
												</dt>
												<dd>
													{ money(
														zone.month.owed_micros
													) }
												</dd>
											</>
										) }
									</dl>
								) }
								{ ! cdn.https && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ __(
											'The CDN needs your site to use https://. Change the site address to https:// in Settings → General.',
											'zinn-seo'
										) }
									</Notice>
								) }
								<p className="zseo-small">
									{ priceText( cdn.price ) }
								</p>
								<div className="zseo-cdn__actions">
									{ ! on && cdn.offered && (
										<Button
											variant="primary"
											disabled={
												! canSpend || !! action.busy
											}
											isBusy={ action.busy === 'on' }
											onClick={ () =>
												act( 'on', 'POST', '/cdn' )
											}
										>
											{ __(
												'Turn on the CDN',
												'zinn-seo'
											) }
										</Button>
									) }
									{ on && (
										<Button
											variant="secondary"
											disabled={ !! action.busy }
											isBusy={ action.busy === 'purge' }
											onClick={ () =>
												act(
													'purge',
													'POST',
													'/cdn/purge'
												)
											}
										>
											{ __(
												'Clear the CDN cache',
												'zinn-seo'
											) }
										</Button>
									) }
									{ on && (
										<Button
											variant="tertiary"
											isDestructive
											disabled={ !! action.busy }
											isBusy={ action.busy === 'off' }
											onClick={ () =>
												act( 'off', 'DELETE', '/cdn' )
											}
										>
											{ __( 'Turn off', 'zinn-seo' ) }
										</Button>
									) }
								</div>
								{ ! on && cdn.offered && ! canSpend && (
									<p className="zseo-small">
										{ __(
											'Ask the site owner to turn it on: it is paid from the account’s credit.',
											'zinn-seo'
										) }
									</p>
								) }
							</CardBody>
						</Card>
					);
				} }
			</Loaded>
		</div>
	);
}
