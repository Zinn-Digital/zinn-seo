/**
 * Client reports (zs-189, zs-421, zs-422, zs-541): set up this site's white-label report, send
 * one now, keep it in a Google Sheet or Drive folder, and give the client a page with no login.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { viewHref } from '../admin-kit/router';
import { errorText, seoFetch } from '../admin/api';
import { RunButton } from '../admin/p4/shared';
import './agency.scss';

/** Languages a report can be written in (the platform's 58). */
const LOCALES =
	'en,es,pt-BR,fr,de,it,nl,pl,ru,uk,ro,bg,el,cs,hu,sr,hr,sq,tr,az,hy,ka,uz,ja,zh,ko,id,vi,th,km,ms,fil,my,hi,bn,ta,te,mr,pa,kn,gu,ne,si,ml,sw,am,ar,ur,fa,he,ps,so,ha,yo,kk,tg,lo,mn'.split(
		','
	);

/**
 * A language's own name ("Deutsch"), falling back to its code.
 *
 * @param {string} code Language code.
 * @return {string} Name.
 */
function languageName( code ) {
	try {
		const name = new Intl.DisplayNames( [ code ], { type: 'language' } ).of(
			code
		);
		return name
			? name.charAt( 0 ).toLocaleUpperCase( code ) + name.slice( 1 )
			: code;
	} catch {
		return code;
	}
}

/**
 * The writing direction of a language.
 *
 * @param {string} code Language code.
 * @return {string} rtl or ltr.
 */
export function textDirection( code ) {
	return [ 'ar', 'he', 'fa', 'ur', 'ps' ].includes(
		String( code || '' ).split( '-' )[ 0 ]
	)
		? 'rtl'
		: 'ltr';
}

/**
 * Section key → its name.
 *
 * @return {Object<string, string>} Names.
 */
function sectionNames() {
	return {
		rank: __( 'Google rankings', 'zinn-seo' ),
		ai_visibility: __( 'AI assistants', 'zinn-seo' ),
		index: __( 'Google index', 'zinn-seo' ),
		onpage: __( 'Site health', 'zinn-seo' ),
		backlinks: __( 'Backlinks', 'zinn-seo' ),
		mentions: __( 'Brand mentions', 'zinn-seo' ),
		work: __( 'Work done on the site', 'zinn-seo' ),
	};
}

/**
 * A date or time from the API as the reader writes it.
 *
 * @param {string} iso ISO date.
 * @return {string} Text.
 */
function when( iso ) {
	if ( ! iso ) {
		return '';
	}
	const date = new Date( iso.length === 10 ? `${ iso }T12:00:00` : iso );
	return iso.length === 10
		? date.toLocaleDateString()
		: date.toLocaleString();
}

/**
 * Why a report has no summary in plain words (agency-facing).
 *
 * @param {string} note The API's note.
 * @return {string} Sentence.
 */
function narrativeNote( note ) {
	return (
		{
			noCredit: __(
				'No summary this time: the account had no Zinn® credit left. Top up and make the report again.',
				'zinn-seo'
			),
			noSite: __(
				'No summary: no connected site covers this report.',
				'zinn-seo'
			),
			notAllowed: __(
				'No summary: this site’s connection may not spend credit. Allow it on the Connected sites screen in the Zinn® app.',
				'zinn-seo'
			),
			failed: __(
				'No summary this time: the writer did not answer. Make the report again.',
				'zinn-seo'
			),
		}[ note ] || ''
	);
}

/**
 * The finished reports, for anyone who may see this screen.
 *
 * @param {Object}  props
 * @param {Array}   props.reports Reports.
 * @param {boolean} props.manage  Show the agency's details.
 * @return {Element} List.
 */
export function ReportList( { reports, manage } ) {
	if ( ! reports.length ) {
		return (
			<p className="zseo-muted">
				{ __(
					'No report yet. The first one appears here as soon as it is made.',
					'zinn-seo'
				) }
			</p>
		);
	}
	return (
		<ul className="zseo-report-list">
			{ reports.map( ( r ) => (
				<li key={ r.id } className="zseo-report-list__item">
					<div className="zseo-report-list__head">
						<strong>
							{ sprintf(
								/* translators: 1: start date, 2: end date. */
								__( '%1$s to %2$s', 'zinn-seo' ),
								when( r.period_start ),
								when( r.period_end )
							) }
						</strong>
						{ r.state === 'building' && (
							<span className="zseo-muted">
								<Spinner /> { __( 'Being made…', 'zinn-seo' ) }
							</span>
						) }
						{ r.state === 'failed' && (
							<span className="zseo-warn">
								{ ( r.failure && r.failure.message ) ||
									__( 'Not made', 'zinn-seo' ) }
							</span>
						) }
						{ r.share_url && (
							<ExternalLink href={ r.share_url }>
								{ __( 'Open the report', 'zinn-seo' ) }
							</ExternalLink>
						) }
						{ r.share_url && (
							<ExternalLink href={ `${ r.share_url }/pdf` }>
								{ __( 'PDF', 'zinn-seo' ) }
							</ExternalLink>
						) }
					</div>
					{ r.narrative && (
						// Written in the CLIENT's language, which may not be the reader's: marked
						// so a screen reader speaks it in that language and it runs the right way.
						<p
							className="zseo-report-list__summary"
							lang={ r.locale || undefined }
							dir={ textDirection( r.locale ) }
						>
							{ r.narrative }
						</p>
					) }
					{ manage &&
						r.narrative_note &&
						r.narrative_note !== 'written' && (
							<p className="zseo-muted">
								{ narrativeNote( r.narrative_note ) }
							</p>
						) }
					{ manage && r.emailed_to && r.emailed_to.length > 0 && (
						<p className="zseo-muted">
							{ sprintf(
								/* translators: %s: e-mail addresses. */
								__( 'E-mailed to %s', 'zinn-seo' ),
								r.emailed_to.join( ', ' )
							) }
						</p>
					) }
					{ manage && r.state === 'ready' && r.failure && (
						<p className="zseo-warn">{ r.failure.message }</p>
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * The client's read-only view.
 *
 * @param {Object} props
 * @param {Object} props.data The shaped overview.
 * @return {Element} Screen.
 */
function ClientView( { data } ) {
	return (
		<div className="zseo-admin zseo-reports">
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-h2">
						{ __( 'Your SEO reports', 'zinn-seo' ) }
					</h2>
					{ ( data.portal_links || [] ).map( ( l ) => (
						<p key={ l.id }>
							<ExternalLink href={ l.url }>
								{ __(
									'Your report page (no login needed)',
									'zinn-seo'
								) }
							</ExternalLink>
						</p>
					) ) }
					<ReportList
						reports={ data.reports || [] }
						manage={ false }
					/>
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * The agency's screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function ReportsScreen( { data } ) {
	const [ view, setView ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ form, setForm ] = useState( null );
	const poll = useRef( null );

	const load = () =>
		seoFetch( '/agency/reports' )
			.then( ( v ) => {
				setView( v );
				if ( v.client ) {
					setForm(
						( f ) =>
							f || {
								name: v.client.name,
								recipients: ( v.client.recipients || [] ).join(
									', '
								),
								locale: v.client.locale,
								cadence: v.client.cadence,
								send_email: v.client.send_email,
								narrative: v.client.narrative,
								sections: v.client.sections || [],
								contact_name: v.client.contact_name || '',
								brand: {},
							}
					);
				}
				return v;
			} )
			.catch( ( e ) => setError( errorText( e ) ) );

	const unconnected = !! data?.reports && false === data.reports.connected;

	useEffect( () => {
		if ( unconnected ) {
			return undefined;
		}
		load();
		const back = new URLSearchParams( window.location.search ).get(
			'zseo_sink'
		);
		if ( back === 'connected' ) {
			setNote(
				__(
					'Google is connected. The latest report is being written into it now.',
					'zinn-seo'
				)
			);
		} else if ( back ) {
			setError(
				__(
					'Google was not connected. Try again, and allow access to the file it makes.',
					'zinn-seo'
				)
			);
		}
		return () => clearTimeout( poll.current );
	}, [] );

	// While a report is being made, look again every few seconds.
	useEffect( () => {
		clearTimeout( poll.current );
		if (
			view &&
			( view.reports || [] ).some( ( r ) => r.state === 'building' )
		) {
			poll.current = setTimeout( load, 4000 );
		}
	}, [ view ] );

	if ( unconnected ) {
		return (
			<Notice status="info" isDismissible={ false }>
				<p>
					{ __(
						'Connect this site to a Zinn® account first.',
						'zinn-seo'
					) }
				</p>
				<Button variant="primary" href={ viewHref( 'credits' ) }>
					{ __( 'Connect this site', 'zinn-seo' ) }
				</Button>
			</Notice>
		);
	}
	if ( ! view ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	if ( view.readonly ) {
		return <ClientView data={ view } />;
	}

	const f = form || {
		name: data.siteName || '',
		recipients: '',
		locale: 'en',
		cadence: 'monthly',
		send_email: true,
		narrative: true,
		sections: [],
		contact_name: '',
		brand: {},
	};
	const set = ( patch ) => setForm( { ...f, ...patch } );
	const allowance = view.allowance || {};
	const sections = view.sections || [];
	const names = sectionNames();
	const withData = new Set( view.sections_with_data || [] );
	const chosen = new Set( f.sections || [] );
	const sheet = ( view.sinks || [] ).find(
		( s ) => s.kind === 'google_sheets'
	);
	const drive = ( view.sinks || [] ).find(
		( s ) => s.kind === 'google_drive'
	);
	const save = () =>
		seoFetch( '/agency/reports', {
			method: 'PUT',
			data: {
				name: f.name,
				recipients: f.recipients
					.split( /[\s,;]+/ )
					.map( ( r ) => r.trim() )
					.filter( Boolean ),
				locale: f.locale,
				cadence: f.cadence,
				send_email: !! f.send_email,
				narrative: !! f.narrative,
				sections: f.sections,
				contact_name: f.contact_name,
				brand: f.brand,
			},
		} ).then( ( v ) => {
			setView( v );
			setNote(
				__(
					'Saved. Your brand and the site’s logo are on the report.',
					'zinn-seo'
				)
			);
		} );

	return (
		<div className="zseo-admin zseo-reports">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ note && (
				<Notice status="success" onRemove={ () => setNote( '' ) }>
					{ note }
				</Notice>
			) }
			{ ! allowance.held && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Client reports need the Client Reports plan on your Zinn® account. Add it in the Zinn® app, then come back to set the report up.',
						'zinn-seo'
					) }{ ' ' }
					<ExternalLink href={ view.app_reports_url }>
						{ __(
							'Open Client reports in the Zinn® app',
							'zinn-seo'
						) }
					</ExternalLink>
				</Notice>
			) }
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-h2">
						{ view.client
							? __( 'The report for this site', 'zinn-seo' )
							: __(
									'Set up a report for this site',
									'zinn-seo'
								) }
					</h2>
					<p className="zseo-muted">
						{ __(
							'A branded report of this site’s rankings, pages in Google, links and the work you did — with your logo and theirs, in the client’s language, sent on your schedule.',
							'zinn-seo'
						) }
					</p>
					<div className="zseo-grid-2">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Client name', 'zinn-seo' ) }
							value={ f.name }
							onChange={ ( v ) => set( { name: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Who it is for (name)', 'zinn-seo' ) }
							help={ __(
								'Used in the greeting: “Hello Sam,”.',
								'zinn-seo'
							) }
							value={ f.contact_name }
							onChange={ ( v ) => set( { contact_name: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Send it to', 'zinn-seo' ) }
							help={ __(
								'E-mail addresses, separated by commas.',
								'zinn-seo'
							) }
							value={ f.recipients }
							onChange={ ( v ) => set( { recipients: v } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Language of the report', 'zinn-seo' ) }
							value={ f.locale }
							options={ LOCALES.map( ( c ) => ( {
								value: c,
								label: languageName( c ),
							} ) ) }
							onChange={ ( v ) => set( { locale: v } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'How often', 'zinn-seo' ) }
							value={ f.cadence }
							options={ [
								{
									value: 'weekly',
									label: __( 'Every week', 'zinn-seo' ),
								},
								{
									value: 'monthly',
									label: __( 'Every month', 'zinn-seo' ),
								},
								{
									value: 'quarterly',
									label: __(
										'Every three months',
										'zinn-seo'
									),
								},
								{
									value: 'manual',
									label: __(
										'Only when I press “Send now”',
										'zinn-seo'
									),
								},
							] }
							onChange={ ( v ) => set( { cadence: v } ) }
						/>
					</div>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'E-mail each report to the client',
							'zinn-seo'
						) }
						checked={ !! f.send_email }
						onChange={ ( v ) => set( { send_email: v } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Open with a short summary in plain words',
							'zinn-seo'
						) }
						help={ __(
							'Written by AI in the client’s language from the report’s own figures: what improved, what slipped and what comes next. Uses a little of your Zinn® credit (well under a cent per report).',
							'zinn-seo'
						) }
						checked={ !! f.narrative }
						onChange={ ( v ) => set( { narrative: v } ) }
					/>
					<fieldset className="zseo-card-row">
						<legend className="zseo-legend">
							{ __(
								'What the report covers (none ticked: everything with figures)',
								'zinn-seo'
							) }
						</legend>
						<div className="zseo-grid-2">
							{ sections.map( ( key ) => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ key }
									label={ names[ key ] || key }
									help={
										withData.has( key )
											? ''
											: __( 'No figures yet', 'zinn-seo' )
									}
									checked={ chosen.has( key ) }
									onChange={ ( on ) => {
										const next = new Set( chosen );
										if ( on ) {
											next.add( key );
										} else {
											next.delete( key );
										}
										set( {
											sections: sections.filter( ( k ) =>
												next.has( k )
											),
										} );
									} }
								/>
							) ) }
						</div>
					</fieldset>
					<details className="zseo-details">
						<summary>
							{ __(
								'Words on the report and its e-mail',
								'zinn-seo'
							) }
						</summary>
						<div className="zseo-stack">
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Introduction', 'zinn-seo' ) }
								value={
									( f.brand && f.brand.intro ) ??
									( view.brand ? view.brand.intro : '' )
								}
								onChange={ ( v ) =>
									set( { brand: { ...f.brand, intro: v } } )
								}
							/>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Footer', 'zinn-seo' ) }
								value={
									( f.brand && f.brand.footer ) ??
									( view.brand ? view.brand.footer : '' )
								}
								onChange={ ( v ) =>
									set( { brand: { ...f.brand, footer: v } } )
								}
							/>
						</div>
					</details>
					<div className="zseo-actions">
						<RunButton
							variant="primary"
							onError={ setError }
							run={ save }
						>
							{ view.client
								? __( 'Save', 'zinn-seo' )
								: __( 'Set it up', 'zinn-seo' ) }
						</RunButton>
					</div>
				</CardBody>
			</Card>

			{ view.client && (
				<Card>
					<CardBody className="zseo-stack">
						<div className="zseo-tasks__bar">
							<h2 className="zseo-h2">
								{ __( 'Reports', 'zinn-seo' ) }
							</h2>
							<RunButton
								onError={ setError }
								run={ () =>
									seoFetch( '/agency/reports/run', {
										method: 'POST',
										data: { send_email: false },
									} ).then( load )
								}
							>
								{ __( 'Make one now', 'zinn-seo' ) }
							</RunButton>
							<RunButton
								variant="primary"
								onError={ setError }
								disabled={
									! ( view.client.recipients || [] ).length
								}
								run={ () =>
									seoFetch( '/agency/reports/run', {
										method: 'POST',
										data: { send_email: true },
									} ).then( () => {
										setNote(
											__(
												'Making the report and sending it to the client.',
												'zinn-seo'
											)
										);
										return load();
									} )
								}
							>
								{ __( 'Send now', 'zinn-seo' ) }
							</RunButton>
						</div>
						{ allowance.reports &&
							allowance.reports.cap !== null && (
								<p className="zseo-muted">
									{ sprintf(
										/* translators: 1: reports left, 2: reports a month. */
										_n(
											'%1$d report left this month of %2$d.',
											'%1$d reports left this month of %2$d.',
											allowance.reports.remaining,
											'zinn-seo'
										),
										allowance.reports.remaining,
										allowance.reports.cap
									) }
								</p>
							) }
						{ view.client.next_run_at && (
							<p className="zseo-muted">
								{ sprintf(
									/* translators: %s: a date and time. */
									__( 'Next report: %s', 'zinn-seo' ),
									when( view.client.next_run_at )
								) }
							</p>
						) }
						<ReportList reports={ view.reports || [] } manage />
					</CardBody>
				</Card>
			) }

			{ view.client && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-h2">
							{ __( 'Google Sheets and Drive', 'zinn-seo' ) }
						</h2>
						<p className="zseo-muted">
							{ __(
								'Every report is written into a Google Sheet you own (a tab per section, the history on the first tab) or saved as a PDF in a Drive folder — kept up to date by itself.',
								'zinn-seo'
							) }
						</p>
						{ [
							[
								'google_sheets',
								sheet,
								__( 'Google Sheet', 'zinn-seo' ),
							],
							[
								'google_drive',
								drive,
								__( 'Google Drive folder', 'zinn-seo' ),
							],
						].map( ( [ kind, sink, label ] ) => (
							<div className="zseo-card-row" key={ kind }>
								<strong>{ label }</strong>
								{ sink && sink.status === 'active' ? (
									<>
										<span>
											{ sprintf(
												/* translators: %s: a Google account. */
												__(
													'Connected as %s.',
													'zinn-seo'
												),
												sink.account_email
											) }{ ' ' }
											{ sink.last_synced_at &&
												sprintf(
													/* translators: %s: a date and time. */
													__(
														'Last updated %s.',
														'zinn-seo'
													),
													when( sink.last_synced_at )
												) }
										</span>
										{ sink.failure && (
											<span className="zseo-warn">
												{ sink.failure.message }
											</span>
										) }
										<div className="zseo-actions">
											{ sink.external_url && (
												<ExternalLink
													href={ sink.external_url }
												>
													{ __(
														'Open it',
														'zinn-seo'
													) }
												</ExternalLink>
											) }
											<RunButton
												onError={ setError }
												run={ () =>
													seoFetch(
														`/agency/reports/sinks/${ sink.id }`,
														{ method: 'POST' }
													).then( () => {
														setNote(
															__(
																'Updating it with the latest report.',
																'zinn-seo'
															)
														);
														return load();
													} )
												}
											>
												{ __(
													'Update now',
													'zinn-seo'
												) }
											</RunButton>
											<RunButton
												variant="tertiary"
												onError={ setError }
												run={ () =>
													seoFetch(
														`/agency/reports/sinks/${ sink.id }`,
														{ method: 'DELETE' }
													).then( load )
												}
											>
												{ __(
													'Disconnect',
													'zinn-seo'
												) }
											</RunButton>
										</div>
									</>
								) : (
									<div className="zseo-actions">
										<RunButton
											onError={ setError }
											run={ () =>
												seoFetch(
													'/agency/reports/sinks',
													{
														method: 'POST',
														data: { kind },
													}
												).then( ( r ) => {
													// Google's page in a NEW tab: wp-admin stays put,
													// and Google sends that tab back to this screen.
													window.open(
														r.authorize_url,
														'_blank',
														'noopener'
													);
													setNote(
														__(
															'Finish on Google’s page in the new tab, then come back here.',
															'zinn-seo'
														)
													);
												} )
											}
										>
											{ __(
												'Connect Google',
												'zinn-seo'
											) }
										</RunButton>
									</div>
								) }
							</div>
						) ) }
					</CardBody>
				</Card>
			) }

			{ view.client && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-h2">
							{ __( 'A page for your client', 'zinn-seo' ) }
						</h2>
						<p className="zseo-muted">
							{ __(
								'A private web page with every report, in your brand, that the client opens without a login. Turn it off at any time.',
								'zinn-seo'
							) }
						</p>
						{ ( view.portal_links || [] ).map( ( l ) => (
							<div className="zseo-card-row" key={ l.id }>
								<ExternalLink href={ l.url }>
									{ l.label || l.url }
								</ExternalLink>
								<span className="zseo-muted">
									{ l.expires_at
										? sprintf(
												/* translators: %s: a date. */
												__(
													'Works until %s.',
													'zinn-seo'
												),
												when( l.expires_at )
											)
										: __(
												'No end date.',
												'zinn-seo'
											) }{ ' ' }
									{ sprintf(
										/* translators: %d: times opened. */
										_n(
											'Opened %d time.',
											'Opened %d times.',
											l.views,
											'zinn-seo'
										),
										l.views
									) }
								</span>
								<div className="zseo-actions">
									<Button
										variant="secondary"
										onClick={ () =>
											window.navigator.clipboard &&
											window.navigator.clipboard
												.writeText( l.url )
												.then( () =>
													setNote(
														__(
															'Link copied.',
															'zinn-seo'
														)
													)
												)
										}
									>
										{ __( 'Copy the link', 'zinn-seo' ) }
									</Button>
									<RunButton
										variant="tertiary"
										onError={ setError }
										run={ () =>
											seoFetch(
												`/agency/reports/portal-links/${ l.id }`,
												{
													method: 'DELETE',
												}
											).then( load )
										}
									>
										{ __( 'Turn off', 'zinn-seo' ) }
									</RunButton>
								</div>
							</div>
						) ) }
						<div className="zseo-actions">
							<RunButton
								onError={ setError }
								run={ () =>
									seoFetch( '/agency/reports/portal-links', {
										method: 'POST',
										data: { label: view.client.name },
									} ).then( load )
								}
							>
								{ __( 'Make a client page', 'zinn-seo' ) }
							</RunButton>
						</div>
					</CardBody>
				</Card>
			) }

			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-h2">
						{ __( 'All your client sites', 'zinn-seo' ) }
					</h2>
					<p className="zseo-muted">
						{ __(
							'Scores, alerts and credit for every site you look after, side by side, in the Zinn® app.',
							'zinn-seo'
						) }
					</p>
					<div>
						<ExternalLink href={ view.app_agency_url }>
							{ __( 'Open the agency overview', 'zinn-seo' ) }
						</ExternalLink>
					</div>
				</CardBody>
			</Card>
		</div>
	);
}
