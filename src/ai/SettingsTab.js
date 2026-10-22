/**
 * How AI is paid, which model answers, and what it has cost (zs-197, zs-198, zs-201).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	RadioControl,
} from '@wordpress/components';

import { seoFetch, errorText } from '../admin/api';
import { viewHref } from '../admin-kit/router';
import GuideLink from '../admin/p7/GuideLink';
import ProTeaser from '../admin/ProTeaser';
import { NewTab, Table } from '../tools/parts';
import { money } from '../tools/pure';

/**
 * The tab.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Boot data.
 * @param {Function} props.onChange Called with the new AI state.
 * @return {Element} Tab.
 */
export default function SettingsTab( { data, onChange } ) {
	const ai = data.ai;
	const [ funding, setFunding ] = useState( ai.funding );
	const [ note, setNote ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const usage = ai.usage;
	const save = ( value ) => {
		setFunding( value );
		setError( '' );
		seoFetch( '/ai', { method: 'PUT', data: { funding: value } } )
			.then( ( state ) => {
				onChange( state );
				setNote( __( 'Saved.', 'zinn-seo' ) );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	};
	return (
		<div className="zseo-stack">
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-cardhead">
						<h2 className="zseo-card-title">
							{ __( 'How AI is paid for', 'zinn-seo' ) }
						</h2>
						<GuideLink data={ data } id="ai-settings" />
					</div>
					<RadioControl
						label={ __( 'Pay with', 'zinn-seo' ) }
						selected={ funding }
						onChange={ save }
						disabled={ ! data.canManage }
						options={ [
							{
								value: 'auto',
								label: __(
									'Automatic: my own key when I have one, otherwise Zinn® credits',
									'zinn-seo'
								),
							},
							{
								value: 'byo',
								label: __( 'My own AI key only', 'zinn-seo' ),
							},
							{
								value: 'credits',
								label: __( 'Zinn® credits only', 'zinn-seo' ),
							},
						] }
					/>
					{ note && (
						<Notice
							status="success"
							onRemove={ () => setNote( '' ) }
						>
							{ note }
						</Notice>
					) }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<div className="zseo-grid-2">
						<div className="zseo-paybox">
							<h3>{ __( 'Your own key', 'zinn-seo' ) }</h3>
							<p>
								{ ai.own_key
									? __(
											'Connected. You pay your AI provider directly; Zinn® charges nothing.',
											'zinn-seo'
										)
									: __(
											'Not added yet. OpenAI, Anthropic (Claude), Google Gemini, Mistral, DeepSeek, OpenRouter or a model on your own server.',
											'zinn-seo'
										) }
							</p>
							<p className="zseo-actions zseo-actions--start">
								<a href={ ai.settings_url }>
									{ ai.own_key
										? __(
												'Change keys and models',
												'zinn-seo'
											)
										: __( 'Add a key', 'zinn-seo' ) }
								</a>
								<a
									href={ `${ ai.settings_url }&provider=custom` }
								>
									{ __(
										'Use a model on your own server',
										'zinn-seo'
									) }
								</a>
							</p>
							<p className="zseo-muted">
								{ ai.reads_images
									? __(
											'Your key can look at images, so alt text describes what each picture shows.',
											'zinn-seo'
										)
									: __(
											'With a key from OpenAI, Anthropic or Google Gemini, alt text describes what each picture shows.',
											'zinn-seo'
										) }
							</p>
						</div>
						<div className="zseo-paybox">
							<h3>{ __( 'Zinn® credits', 'zinn-seo' ) }</h3>
							<p>
								{ ai.credits.connected
									? sprintf(
											/* translators: 1: a Zinn account's name, 2: an amount of money. */
											__(
												'Connected to %1$s. Credit: %2$s. No key needed — pay only for what you use.',
												'zinn-seo'
											),
											ai.credits.account,
											null === ai.credits.balance_micros
												? '—'
												: money(
														ai.credits
															.balance_micros
													)
										)
									: __(
											'No key needed: connect this site to a Zinn® account and pay only for what you use. We pick a good, inexpensive model for each job.',
											'zinn-seo'
										) }
							</p>
							<p className="zseo-actions zseo-actions--start">
								{ ai.credits.connected ? (
									<NewTab href={ ai.credits.buy_url }>
										{ __( 'Add credit', 'zinn-seo' ) }
									</NewTab>
								) : (
									<a href={ viewHref( 'credits' ) }>
										{ __( 'Connect to Zinn®', 'zinn-seo' ) }
									</a>
								) }
								<a href={ viewHref( 'credits' ) }>
									{ __(
										'Low-credit warnings and automatic top-up',
										'zinn-seo'
									) }
								</a>
							</p>
							{ ai.credits.connected &&
								! ai.credits.can_spend && (
									<p className="zseo-muted">
										{ __(
											"This site is connected for viewing only, so it cannot spend credit. The account's owner can allow it.",
											'zinn-seo'
										) }
									</p>
								) }
						</div>
					</div>
					<p className="zseo-muted">
						{ __(
							'AI never changes your site on its own: every title, description, alt text, redirect or link it writes waits for you to use it.',
							'zinn-seo'
						) }
					</p>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-card-title">
						{ __( 'What AI has cost this month', 'zinn-seo' ) }
					</h2>
					{ ! usage && (
						<ProTeaser
							title={ __(
								'See every AI request and set limits',
								'zinn-seo'
							) }
							points={ [
								__(
									'A log of every request: who, what for, which model and what it cost — on your key and on Zinn® credits.',
									'zinn-seo'
								),
								__(
									'A monthly spending limit and request limit, and which user roles may use AI.',
									'zinn-seo'
								),
							] }
						/>
					) }
					{ usage && (
						<>
							<dl className="zseo-stats">
								<div className="zseo-stats__item">
									<dt>{ __( 'Requests', 'zinn-seo' ) }</dt>
									<dd>{ usage.month.requests }</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>
										{ __( 'On Zinn® credits', 'zinn-seo' ) }
									</dt>
									<dd>
										{ money( usage.credits.micro_usd ) }
									</dd>
								</div>
								<div className="zseo-stats__item">
									<dt>
										{ __(
											'On your own key (estimated)',
											'zinn-seo'
										) }
									</dt>
									<dd>{ money( usage.own.micro_usd ) }</dd>
								</div>
							</dl>
							<p className="zseo-muted">
								{ usage.limits.cap_cents > 0 ||
								usage.limits.cap_requests > 0
									? sprintf(
											/* translators: 1: an amount of money, 2: a number of requests. */
											__(
												'Monthly limits: %1$s and %2$s requests.',
												'zinn-seo'
											),
											usage.limits.cap_cents > 0
												? money(
														usage.limits.cap_cents *
															10000
													)
												: __(
														'no spending limit',
														'zinn-seo'
													),
											usage.limits.cap_requests > 0
												? String(
														usage.limits
															.cap_requests
													)
												: __(
														'no limit on',
														'zinn-seo'
													)
										)
									: __(
											'No monthly limit is set.',
											'zinn-seo'
										) }{ ' ' }
								<a href={ ai.settings_url }>
									{ __(
										'Set limits and who may use AI',
										'zinn-seo'
									) }
								</a>
							</p>
							{ usage.recent.length > 0 && (
								<Table
									label={ __(
										'Recent AI requests',
										'zinn-seo'
									) }
									rows={ usage.recent }
									columns={ [
										{
											id: 'at',
											label: __( 'When', 'zinn-seo' ),
											render: ( r ) => r.at,
										},
										{
											id: 'who',
											label: __( 'Who', 'zinn-seo' ),
											render: ( r ) => r.who || '—',
										},
										{
											id: 'for',
											label: __( 'For', 'zinn-seo' ),
											render: ( r ) => r.purpose,
										},
										{
											id: 'how',
											label: __( 'Paid by', 'zinn-seo' ),
											render: ( r ) =>
												'zinn' === r.provider
													? __(
															'Zinn® credits',
															'zinn-seo'
														)
													: r.provider,
										},
										{
											id: 'cost',
											label: __( 'Cost', 'zinn-seo' ),
											numeric: true,
											render: ( r ) => money( r.micro ),
										},
										{
											id: 'ok',
											label: __( 'Result', 'zinn-seo' ),
											render: ( r ) =>
												'ok' === r.outcome
													? __( 'Done', 'zinn-seo' )
													: __(
															'Failed',
															'zinn-seo'
														),
										},
									] }
								/>
							) }
						</>
					) }
				</CardBody>
			</Card>
			{ ! ai.ready && (
				<p>
					<Button variant="primary" href={ ai.settings_url }>
						{ __( 'Set up AI', 'zinn-seo' ) }
					</Button>
				</p>
			) }
		</div>
	);
}
