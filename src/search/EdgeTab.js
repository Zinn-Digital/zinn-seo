/**
 * Pro: SEO at the Cloudflare edge — redirects as Bulk Redirects, the cache cleared when SEO
 * changes, AI crawlers blocked at the edge as robots.txt says; automatic on Zinn® hosting (zs-441).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { Loaded, NewTab, Refusal, useAction, useLoad } from '../tools/parts';
import { count, day } from '../tools/pure';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function EdgeTab( { data } ) {
	const load = useLoad( data.canManage ? '/search/edge' : null );
	const act = useAction();
	const [ token, setToken ] = useState( '' );
	if ( ! data.canManage ) {
		return (
			<p className="zseo-muted">
				{ __(
					'Only people who manage the plugin can see this.',
					'zinn-seo'
				) }
			</p>
		);
	}
	const run = ( what ) =>
		act.run(
			what,
			() =>
				seoFetch( `/search/edge/${ what }`, { method: 'POST' } ).then(
					load.setData
				),
			__( 'Done.', 'zinn-seo' )
		);
	return (
		<Loaded load={ load }>
			{ ( e ) => {
				const hosted = !! ( e.hosted && e.hosted.hosted );
				const last = e.last || {};
				return (
					<div className="zseo-stack">
						{ hosted && (
							<Notice status="success" isDismissible={ false }>
								{ __(
									'This site is hosted by Zinn Digital®: the edge is ours to look after. Pages are cleared from the cache the moment their SEO changes, and AI crawlers are treated at the edge exactly as your robots.txt says — nothing to set up.',
									'zinn-seo'
								) }
							</Notice>
						) }
						{ ! hosted && ! e.own && (
							<Card>
								<CardBody className="zseo-stack">
									<p className="zseo-lead">
										{ __(
											'On Cloudflare? Paste an API token and your redirects are answered by Cloudflare in milliseconds, old titles never linger in the cache, and AI crawlers are blocked at the edge exactly as your robots.txt says.',
											'zinn-seo'
										) }
									</p>
									<p className="zseo-small">
										{ __(
											'Create the token in Cloudflare → My Profile → API Tokens → Create Token → Custom token, with these permissions: Zone · Cache Purge · Edit; Zone · Bot Management · Edit; Account · Account Filter Lists · Edit; Account · Account Rulesets · Edit. It stays on this site only.',
											'zinn-seo'
										) }
									</p>
									<NewTab href="https://dash.cloudflare.com/profile/api-tokens">
										{ __(
											'Open Cloudflare API tokens',
											'zinn-seo'
										) }
									</NewTab>
									<form
										className="zseo-row zseo-row--top"
										onSubmit={ ( ev ) => {
											ev.preventDefault();
											act.run( 'connect', () =>
												seoFetch( '/search/edge', {
													method: 'POST',
													data: { token },
												} ).then( ( r ) => {
													setToken( '' );
													load.setData( r );
												} )
											);
										} }
									>
										<TextControl
											label={ __(
												'Cloudflare API token',
												'zinn-seo'
											) }
											type="password"
											autoComplete="off"
											value={ token }
											onChange={ setToken }
											__nextHasNoMarginBottom
											__next40pxDefaultSize
										/>
										<Button
											variant="primary"
											type="submit"
											isBusy={ act.busy === 'connect' }
											disabled={ ! token || !! act.busy }
										>
											{ __(
												'Connect Cloudflare',
												'zinn-seo'
											) }
										</Button>
									</form>
								</CardBody>
							</Card>
						) }
						{ ( hosted || e.own ) && (
							<Card>
								<CardBody className="zseo-stack">
									{ e.own && (
										<p>
											<span className="zseo-ok">
												{ __(
													'Cloudflare connected',
													'zinn-seo'
												) }
											</span>{ ' ' }
											<span className="zseo-address">
												{ e.zone }
											</span>
										</p>
									) }
									<ul className="zseo-log">
										{ e.own && (
											<li>
												<strong>
													{ __(
														'Redirects at the edge',
														'zinn-seo'
													) }
												</strong>
												<span>
													{ last.redirects
														? sprintf(
																/* translators: 1: redirects sent, 2: kept in WordPress, 3: date. */
																__(
																	'%1$s sent, %2$s kept in WordPress (patterns and 410), %3$s',
																	'zinn-seo'
																),
																count(
																	last
																		.redirects
																		.sent
																),
																count(
																	last
																		.redirects
																		.kept_in_wordpress
																),
																day(
																	last
																		.redirects
																		.at,
																	true
																)
															)
														: __(
																'not sent yet',
																'zinn-seo'
															) }
												</span>
												<Button
													variant="secondary"
													disabled={ !! act.busy }
													isBusy={
														act.busy === 'redirects'
													}
													onClick={ () =>
														run( 'redirects' )
													}
												>
													{ __(
														'Send redirects now',
														'zinn-seo'
													) }
												</Button>
											</li>
										) }
										<li>
											<strong>
												{ __(
													'AI crawlers at the edge',
													'zinn-seo'
												) }
											</strong>
											<span>
												{ e.ai === 'block'
													? __(
															'Blocked (your robots.txt blocks AI training crawlers)',
															'zinn-seo'
														)
													: __(
															'Allowed (your robots.txt allows them)',
															'zinn-seo'
														) }
												{ last.ai
													? ` · ${ day( last.ai.at, true ) }`
													: '' }
											</span>
											<Button
												variant="secondary"
												disabled={ !! act.busy }
												isBusy={ act.busy === 'ai' }
												onClick={ () => run( 'ai' ) }
											>
												{ __(
													'Apply now',
													'zinn-seo'
												) }
											</Button>
										</li>
										<li>
											<strong>
												{ __(
													'Cache cleared when SEO changes',
													'zinn-seo'
												) }
											</strong>
											<span>
												{ last.purge
													? sprintf(
															/* translators: 1: pages, 2: date. */
															__(
																'last: %1$s pages, %2$s',
																'zinn-seo'
															),
															count(
																last.purge.pages
															),
															day(
																last.purge.at,
																true
															)
														)
													: __(
															'automatic',
															'zinn-seo'
														) }
											</span>
											<Button
												variant="tertiary"
												disabled={ !! act.busy }
												onClick={ () => run( 'purge' ) }
											>
												{ __(
													'Clear the home page now',
													'zinn-seo'
												) }
											</Button>
										</li>
									</ul>
									{ e.own && (
										<div>
											<Button
												variant="tertiary"
												isDestructive
												onClick={ () =>
													act.run( 'off', () =>
														seoFetch(
															'/search/edge',
															{ method: 'DELETE' }
														).then( load.setData )
													)
												}
											>
												{ __(
													'Disconnect Cloudflare',
													'zinn-seo'
												) }
											</Button>
										</div>
									) }
								</CardBody>
							</Card>
						) }
						{ act.done && (
							<Notice status="success" isDismissible={ false }>
								{ act.done }
							</Notice>
						) }
						<Refusal error={ act.error } onRemove={ act.clear } />
					</div>
				);
			} }
		</Loaded>
	);
}
