/**
 * Connect Google: the one card that says what is connected and lets the person connect, choose
 * the property this site reads, add Analytics / Business Profile / job pages, or disconnect.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
} from '@wordpress/components';

import { viewHref } from '../admin-kit/router';
import { seoFetch } from '../admin/api';
import { Refusal, useAction } from '../tools/parts';

const FEATURES = () => [
	{
		id: 'analytics',
		label: __(
			'Google Analytics — see revenue and conversions from search (Pro)',
			'zinn-seo'
		),
	},
	{
		id: 'business',
		label: __(
			'Google Business Profile — reply to reviews and post updates (Pro)',
			'zinn-seo'
		),
	},
	{
		id: 'indexing',
		label: __(
			'Job and livestream pages — tell Google the moment they change',
			'zinn-seo'
		),
	},
];

/**
 * @param {Object} props
 * @param {Object} props.data   Boot data.
 * @param {Object} props.link   `useLoad('/search/google')`.
 * @param {Object} props.google The link.
 * @return {Element} Card.
 */
export default function Connect( { data, link, google } ) {
	const act = useAction();
	const [ want, setWant ] = useState( [] );
	const [ opened, setOpened ] = useState( false );
	const [ open, setOpen ] = useState( false );
	const back = new URLSearchParams( window.location.search ).get(
		'zseo_google'
	);

	const connect = () =>
		act.run( 'connect', () =>
			seoFetch( '/search/google', {
				method: 'POST',
				data: { features: want },
			} ).then( ( r ) => {
				// A new tab (WordPress.org guideline 8): Google's page never replaces wp-admin.
				window.open( r.consent_url, '_blank', 'noopener,noreferrer' );
				setOpened( true );
			} )
		);
	const choose = ( field, value ) =>
		act.run( 'choose', () =>
			seoFetch( '/search/google', {
				method: 'PATCH',
				data: { [ field ]: value },
			} ).then( ( r ) => link.setData( r ) )
		);
	const choices = () =>
		act.run( 'choices', () =>
			seoFetch( '/search/google?choices=1' ).then( ( r ) =>
				link.setData( r )
			)
		);
	const disconnect = () =>
		act.run( 'disconnect', () =>
			seoFetch( '/search/google', { method: 'DELETE' } ).then( ( r ) =>
				link.setData( r )
			)
		);

	if ( ! google.zinn_connected ) {
		return (
			<Card className="zseo-connect">
				<CardBody className="zseo-stack">
					<p className="zseo-lead">
						{ __(
							'See the searches that bring people to every page, which pages Google has indexed and how fast your pages are for real visitors — free. First connect this site to your free Zinn® account (no card needed), then sign in to Google once.',
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
		);
	}

	const boxes = (
		<fieldset className="zseo-boxes">
			<legend className="zseo-small">
				{ __(
					'Search Console is always included. Also connect:',
					'zinn-seo'
				) }
			</legend>
			{ FEATURES().map( ( f ) => (
				<CheckboxControl
					key={ f.id }
					label={ f.label }
					checked={
						want.includes( f.id ) ||
						( google.features || [] ).includes( f.id )
					}
					disabled={ ( google.features || [] ).includes( f.id ) }
					onChange={ ( on ) =>
						setWant( ( w ) =>
							on
								? [ ...w, f.id ]
								: w.filter( ( x ) => x !== f.id )
						)
					}
					__nextHasNoMarginBottom
				/>
			) ) }
		</fieldset>
	);

	if ( ! google.connected ) {
		return (
			<Card className="zseo-connect">
				<CardBody className="zseo-stack">
					{ 'cancelled' === back && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Google sign-in was cancelled. Nothing was connected.',
								'zinn-seo'
							) }
						</Notice>
					) }
					{ 'scope' === back && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Google was not given permission to read Search Console. Connect again and leave that box ticked.',
								'zinn-seo'
							) }
						</Notice>
					) }
					{ 'needs_reconnect' === google.status && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Google stopped accepting this site’s sign-in. Connect again — nothing else is lost.',
								'zinn-seo'
							) }
						</Notice>
					) }
					<p className="zseo-lead">
						{ __(
							'Sign in to Google once with the account that owns this site in Search Console. You will see your real searches, clicks and positions here, and your sitemap is sent to Google for you.',
							'zinn-seo'
						) }
					</p>
					{ boxes }
					<div className="zseo-row">
						<Button
							variant="primary"
							isBusy={ act.busy === 'connect' }
							disabled={ !! act.busy }
							onClick={ connect }
						>
							{ __( 'Connect Google', 'zinn-seo' ) }
							<span className="screen-reader-text">
								{ __( '(opens in a new tab)', 'zinn-seo' ) }
							</span>
						</Button>
						{ opened && (
							<Button
								variant="secondary"
								onClick={ () =>
									link.reload( '/search/google?fresh=1' )
								}
							>
								{ __( 'I have signed in', 'zinn-seo' ) }
							</Button>
						) }
					</div>
					<p className="zseo-small zseo-muted">
						{ __(
							'Zinn® keeps the Google sign-in in its secure vault; this site never holds a Google password or key. You can disconnect at any time.',
							'zinn-seo'
						) }
					</p>
					<Refusal error={ act.error } onRemove={ act.clear } />
				</CardBody>
			</Card>
		);
	}

	const c = google.choices || null;
	const scOptions = c
		? c.search_console.map( ( p ) => ( {
				value: p.property,
				label: p.property.replace( /^sc-domain:/, '' ),
			} ) )
		: [];
	return (
		<Card className="zseo-connect is-on">
			<CardBody className="zseo-stack">
				{ 'connected' === back && (
					<Notice status="success" isDismissible={ false }>
						{ __(
							'Google is connected. Your sitemap was sent to Search Console.',
							'zinn-seo'
						) }
					</Notice>
				) }
				<div className="zseo-cardhead">
					<p className="zseo-connected">
						<span className="zseo-ok">
							{ __( 'Google connected', 'zinn-seo' ) }
						</span>{ ' ' }
						<span className="zseo-address">
							{ google.account_email }
						</span>
						{ google.search_console_property && (
							<>
								{ ' · ' }
								<span className="zseo-address">
									{ google.search_console_property.replace(
										/^sc-domain:/,
										''
									) }
								</span>
							</>
						) }
					</p>
					<Button
						variant="tertiary"
						aria-expanded={ open }
						onClick={ () => {
							setOpen( ! open );
							if ( ! open && ! c ) {
								choices();
							}
						} }
					>
						{ open
							? __( 'Close', 'zinn-seo' )
							: __( 'Change what is connected', 'zinn-seo' ) }
					</Button>
				</div>
				{ ! google.search_console_property && (
					<Notice status="warning" isDismissible={ false }>
						{ c && c.search_console.length === 0
							? sprintf(
									/* translators: %s: the site's address. */
									__(
										'The Google account %s has no Search Console property for this site. Add the site in Search Console (or ask its owner to add this account), then choose it here.',
										'zinn-seo'
									),
									google.account_email
								)
							: __(
									'Choose which Search Console property is this site.',
									'zinn-seo'
								) }
					</Notice>
				) }
				{ ( open || ! google.search_console_property ) && (
					<div className="zseo-stack">
						{ ! c && act.busy === 'choices' && <span>…</span> }
						{ c && (
							<SelectControl
								label={ __(
									'Search Console property',
									'zinn-seo'
								) }
								value={ google.search_console_property }
								options={ [
									{
										value: '',
										label: __( 'Choose…', 'zinn-seo' ),
									},
									...scOptions,
								] }
								onChange={ ( v ) =>
									choose( 'search_console_property', v )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
						{ c &&
							( google.features || [] ).includes(
								'analytics'
							) && (
								<SelectControl
									label={ __(
										'Google Analytics property',
										'zinn-seo'
									) }
									value={ google.analytics_property }
									options={ [
										{
											value: '',
											label: __( 'None', 'zinn-seo' ),
										},
										...c.analytics.map( ( p ) => ( {
											value: p.id,
											label: `${ p.name } (${ p.account })`,
										} ) ),
									] }
									onChange={ ( v ) =>
										choose( 'analytics_property', v )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							) }
						{ c &&
							( google.features || [] ).includes(
								'business'
							) && (
								<SelectControl
									label={ __(
										'Business Profile location',
										'zinn-seo'
									) }
									value={ google.business_location }
									options={ [
										{
											value: '',
											label: __( 'None', 'zinn-seo' ),
										},
										...c.business.map( ( p ) => ( {
											value: p.name,
											label: [ p.title, p.address ]
												.filter( Boolean )
												.join( ' — ' ),
										} ) ),
									] }
									onChange={ ( v ) =>
										choose( 'business_location', v )
									}
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
							) }
						{ data.canManage && (
							<>
								{ boxes }
								<div className="zseo-row">
									<Button
										variant="secondary"
										disabled={
											want.length === 0 || !! act.busy
										}
										onClick={ connect }
									>
										{ __( 'Add these', 'zinn-seo' ) }
										<span className="screen-reader-text">
											{ __(
												'(opens in a new tab)',
												'zinn-seo'
											) }
										</span>
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										disabled={ !! act.busy }
										onClick={ disconnect }
									>
										{ __(
											'Disconnect Google',
											'zinn-seo'
										) }
									</Button>
								</div>
							</>
						) }
					</div>
				) }
				<Refusal error={ act.error } onRemove={ act.clear } />
			</CardBody>
		</Card>
	);
}
