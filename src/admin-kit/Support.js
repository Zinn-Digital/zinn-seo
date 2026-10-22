/* Generated from wp/packages/zinn-admin-kit/src/js/Support.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ExternalLink,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { kitFetch, errorMessage } from './api';

/**
 * Get help / Report a bug (feature sh-r6) and Feedback / Request a feature (sh-r3).
 *
 * Everything goes to ONE place: Zinn Digital's support inbox (owner, D17), from the SERVER of this
 * site (`/<ns>/kit/support/*`), never from the browser. Nothing is sent before the site owner has
 * agreed (WordPress.org guideline 7), diagnostics only when ticked, with the exact payload shown
 * first. ⛔ A request never carries a login: no user is created and no credentials are sent
 * (WordPress.org review 2026-10-01, CLAUDE.md §2.56). No link, token or endpoint logs anybody in.
 *
 * @param {Object} props
 * @param {Object} props.kit  Boot data.
 * @param {string} props.mode `help` or `feedback`.
 * @return {Element} The screen.
 */
export default function Support( { kit, mode } ) {
	const support = kit.support || {};
	const [ consented, setConsented ] = useState( !! support.consented );
	const [ notice, setNotice ] = useState( null );

	const agree = () =>
		kitFetch( kit, 'support/consent', { method: 'POST' } )
			.then( () => setConsented( true ) )
			.catch( ( error ) =>
				setNotice( {
					status: 'error',
					message: errorMessage(
						error,
						__( 'That could not be saved.', 'zinn-seo' )
					),
				} )
			);

	return (
		<div className="zak-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ ! consented ? (
				<Consent kit={ kit } onAgree={ agree } />
			) : (
				<>
					{ 'help' === mode && <ConnectionCard kit={ kit } /> }
					<TicketForm kit={ kit } mode={ mode } />
				</>
			) }
		</div>
	);
}

/**
 * What is sent, to whom, and the privacy link — shown before anything leaves the site.
 *
 * @param {Object}   props
 * @param {Object}   props.kit     Boot data.
 * @param {Function} props.onAgree Called when the owner agrees.
 * @return {Element} The panel.
 */
function Consent( { kit, onAgree } ) {
	return (
		<Card className="zak-card">
			<CardHeader>
				<h2 className="zak-card__title">
					{ __( 'Before you contact support', 'zinn-seo' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ sprintf(
						/* translators: %s: company name. */
						__(
							'Your request is sent from this site to %s, who make this plugin. We send:',
							'zinn-seo'
						),
						kit.support.recipient
					) }
				</p>
				<ul className="zak-list">
					<li>
						{ __(
							'your e-mail address and name, so we can reply;',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'the subject and message you write;',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'your licence ID, so paying customers get priority;',
							'zinn-seo'
						) }
					</li>
					<li>
						{ __(
							'only if you tick it: site details (WordPress and PHP versions, theme, plugins, an excerpt of the error log with passwords and e-mail addresses removed), which you can read before sending;',
							'zinn-seo'
						) }
					</li>
				</ul>
				<p>
					<ExternalLink href={ kit.privacyUrl }>
						{ __( 'Privacy policy', 'zinn-seo' ) }
					</ExternalLink>
				</p>
				<Button variant="primary" onClick={ onAgree }>
					{ __( 'I agree, continue', 'zinn-seo' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * Connect this site to Zinn Digital support, or disconnect it. A connected site files requests
 * without typing an e-mail address each time, and the connection can be revoked from either side.
 *
 * @param {Object} props
 * @param {Object} props.kit Boot data.
 * @return {Element} The card.
 */
function ConnectionCard( { kit } ) {
	const [ state, setState ] = useState( null );
	const [ email, setEmail ] = useState( kit.support.email || '' );
	const [ name, setName ] = useState( kit.support.name || '' );
	const [ agreed, setAgreed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		kitFetch( kit, 'support/connection?refresh=1' )
			.then( setState )
			.catch( ( e ) =>
				setError(
					errorMessage(
						e,
						__(
							'The connection could not be checked.',
							'zinn-seo'
						)
					)
				)
			);
	}, [ kit ] );

	const run = ( promise ) => {
		setBusy( true );
		setError( '' );
		promise
			.then( ( result ) => {
				setState( result.connection || result );
				if ( result.ok === false && result.message ) {
					setError( result.message );
				}
			} )
			.catch( ( e ) =>
				setError(
					errorMessage(
						e,
						__( 'That did not work. Try again.', 'zinn-seo' )
					)
				)
			)
			.finally( () => setBusy( false ) );
	};

	const reasons = {
		revoked_by_zinn: __(
			'Zinn Digital® support disconnected this site.',
			'zinn-seo'
		),
		unknown_to_zinn: __(
			'Zinn Digital® support no longer recognises this site.',
			'zinn-seo'
		),
		revoked_here: __( 'You disconnected this site.', 'zinn-seo' ),
	};

	return (
		<Card className="zak-card" data-zak-tour="connection">
			<CardHeader>
				<h2 className="zak-card__title">
					{ __( 'Connect this site to support', 'zinn-seo' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ null === state && ! error && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ state && state.connected && (
					<>
						<p className="zak-status is-ok">
							{ sprintf(
								/* translators: %s: e-mail address. */
								__(
									'Connected. Replies go to %s.',
									'zinn-seo'
								),
								state.email
							) }
						</p>
						<Button
							variant="secondary"
							isDestructive
							disabled={ busy }
							onClick={ () =>
								run(
									kitFetch( kit, 'support/connection', {
										method: 'DELETE',
									} )
								)
							}
						>
							{ __( 'Disconnect', 'zinn-seo' ) }
						</Button>
					</>
				) }
				{ state && ! state.connected && (
					<>
						<p className="zak-status">
							{ reasons[ state.reason ] ||
								__(
									'Not connected. You can still send a request with your e-mail address below; connecting saves typing it each time.',
									'zinn-seo'
								) }
						</p>
						<TextControl
							__next40pxDefaultSize
							type="email"
							label={ __( 'E-mail address', 'zinn-seo' ) }
							value={ email }
							onChange={ setEmail }
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Your name (optional)',
								'zinn-seo'
							) }
							value={ name }
							onChange={ setName }
						/>
						<CheckboxControl
							label={ __(
								'I agree to connect this site to Zinn Digital® support. It sends this site’s address, WordPress and PHP versions and the plugin version, and can be disconnected at any time from here or by Zinn Digital®.',
								'zinn-seo'
							) }
							checked={ agreed }
							onChange={ setAgreed }
						/>
						<Button
							variant="primary"
							disabled={ busy || ! agreed || ! email }
							isBusy={ busy }
							onClick={ () =>
								run(
									kitFetch( kit, 'support/connection', {
										method: 'POST',
										data: { email, name, consent: true },
									} )
								)
							}
						>
							{ __( 'Connect', 'zinn-seo' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The request itself.
 *
 * @param {Object} props
 * @param {Object} props.kit  Boot data.
 * @param {string} props.mode `help` or `feedback`.
 * @return {Element} The form.
 */
function TicketForm( { kit, mode } ) {
	const help = 'help' === mode;
	const kinds = help
		? [
				{ value: 'help', label: __( 'I need help', 'zinn-seo' ) },
				{ value: 'bug', label: __( 'Report a bug', 'zinn-seo' ) },
			]
		: [
				{
					value: 'feedback',
					label: __( 'Send feedback', 'zinn-seo' ),
				},
				{
					value: 'feature_request',
					label: __( 'Request a feature', 'zinn-seo' ),
				},
			];
	const [ kind, setKind ] = useState( kinds[ 0 ].value );
	const [ email, setEmail ] = useState( kit.support.email || '' );
	const [ name, setName ] = useState( kit.support.name || '' );
	const [ subject, setSubject ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ withDiagnostics, setWithDiagnostics ] = useState( help );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	const loadPreview = () =>
		kitFetch( kit, 'support/diagnostics' )
			.then( ( data ) => setPreview( JSON.stringify( data, null, 2 ) ) )
			.catch( ( e ) =>
				setPreview(
					errorMessage(
						e,
						__(
							'The site details could not be read.',
							'zinn-seo'
						)
					)
				)
			);

	const send = () => {
		setBusy( true );
		setResult( null );
		kitFetch( kit, 'support/ticket', {
			method: 'POST',
			data: {
				kind,
				email,
				name,
				subject,
				message,
				diagnostics: help && withDiagnostics,
			},
		} )
			.then( ( r ) => {
				setResult( r );
				setSubject( '' );
				setMessage( '' );
			} )
			.catch( ( e ) =>
				setResult( {
					ok: false,
					message: errorMessage(
						e,
						__(
							'Your request could not be sent. Try again in a few minutes.',
							'zinn-seo'
						)
					),
				} )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="zak-card" data-zak-tour="ticket">
			<CardHeader>
				<h2 className="zak-card__title">
					{ help
						? __( 'Get help or report a bug', 'zinn-seo' )
						: __(
								'Feedback and feature requests',
								'zinn-seo'
							) }
				</h2>
			</CardHeader>
			<CardBody>
				{ result && result.ok && (
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: request reference, e.g. ZT-ABC234. */
							__(
								'Sent. Your reference is %s, and our reply will come by e-mail.',
								'zinn-seo'
							),
							result.reference
						) }{ ' ' }
						{ 'high' === result.priority &&
							__(
								'Your plan includes priority support.',
								'zinn-seo'
							) }
					</Notice>
				) }
				{ result && ! result.ok && (
					<Notice status="error" isDismissible={ false }>
						{ result.message }
					</Notice>
				) }
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'What is it about?', 'zinn-seo' ) }
					value={ kind }
					options={ kinds }
					onChange={ setKind }
				/>
				<TextControl
					__next40pxDefaultSize
					type="email"
					label={ __( 'Reply to (e-mail)', 'zinn-seo' ) }
					value={ email }
					onChange={ setEmail }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Your name (optional)', 'zinn-seo' ) }
					value={ name }
					onChange={ setName }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Subject', 'zinn-seo' ) }
					value={ subject }
					onChange={ setSubject }
				/>
				<TextareaControl
					label={ __( 'Message', 'zinn-seo' ) }
					help={
						'bug' === kind
							? __(
									'What did you do, what did you expect, and what happened instead?',
									'zinn-seo'
								)
							: undefined
					}
					value={ message }
					onChange={ setMessage }
					rows={ 6 }
				/>
				{ help && (
					<>
						<CheckboxControl
							label={ __(
								'Include site details (versions, theme, plugins, error log excerpt)',
								'zinn-seo'
							) }
							checked={ withDiagnostics }
							onChange={ setWithDiagnostics }
						/>
						{ withDiagnostics && (
							<details
								className="zak-details"
								onToggle={ ( e ) =>
									e.target.open &&
									null === preview &&
									loadPreview()
								}
							>
								<summary>
									{ __(
										'See exactly what is sent',
										'zinn-seo'
									) }
								</summary>
								<pre className="zak-pre" dir="ltr">
									{ preview ||
										__( 'Loading…', 'zinn-seo' ) }
								</pre>
							</details>
						) }
					</>
				) }
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! subject.trim() || ! message.trim() }
					onClick={ send }
				>
					{ __( 'Send', 'zinn-seo' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
