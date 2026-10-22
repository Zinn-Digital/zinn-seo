import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from './api';
import { viewHref } from '../admin-kit/router';

/**
 * Whether search engines may index the site (WordPress's own Reading setting, shown here because
 * a site that discourages them cannot rank, whatever else is set).
 *
 * @param {Object}   props
 * @param {boolean}  props.discouraged The site discourages search engines now.
 * @param {boolean}  props.isAdmin     The user may change it.
 * @param {string}   props.readingUrl  Settings → Reading.
 * @param {Function} props.onChange    Called with the new `discouraged` value.
 * @return {Element} The panel.
 */
export function VisibilityPanel( {
	discouraged,
	isAdmin,
	readingUrl,
	onChange,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const set = ( open ) => {
		setBusy( true );
		setError( '' );
		seoFetch( '/visibility', { method: 'PUT', data: { public: open } } )
			.then( ( result ) => onChange( ! result.public ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="zseo-stack">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ discouraged ? (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This site asks search engines not to index it, so it will not appear in search results. That is right for a site still being built, and wrong for a live one.',
						'zinn-seo'
					) }
				</Notice>
			) : (
				<p className="zseo-ok">
					{ __( 'Search engines may index this site.', 'zinn-seo' ) }
				</p>
			) }
			{ isAdmin ? (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Let search engines index this site',
						'zinn-seo'
					) }
					checked={ ! discouraged }
					disabled={ busy }
					onChange={ set }
				/>
			) : (
				<p>
					{ __(
						'Only an administrator can change this, in Settings → Reading.',
						'zinn-seo'
					) }{ ' ' }
					<a href={ readingUrl }>
						{ __( 'Open Settings → Reading', 'zinn-seo' ) }
					</a>
				</p>
			) }
		</div>
	);
}

/**
 * The no-trace ("footprint") switch. ⛔ Never silent: the changes a switch would make are fetched
 * first (dry run) and shown in a confirmation, because some of them move addresses search engines
 * already know.
 *
 * @param {Object}   props
 * @param {Function} props.onSwitched Called with the new state after a switch.
 * @return {Element} The panel.
 */
export function FootprintPanel( { onSwitched } ) {
	const [ state, setState ] = useState( null );
	const [ plan, setPlan ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		seoFetch( '/notrace' )
			.then( setState )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );

	const preview = ( on ) => {
		setBusy( true );
		seoFetch( '/notrace', {
			method: 'PUT',
			data: { enabled: on, dry_run: true },
		} )
			.then( ( result ) =>
				setPlan( { on, changes: result.changes || [] } )
			)
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const apply = () => {
		setBusy( true );
		seoFetch( '/notrace', { method: 'PUT', data: { enabled: plan.on } } )
			.then( ( result ) => {
				setState( { ...state, enabled: result.enabled } );
				const moved = ( result.changes || [] ).some(
					( c ) => c.resubmit
				);
				setNotice( {
					status: 'success',
					text: result.enabled
						? __( 'No-trace mode is on.', 'zinn-seo' )
						: __( 'No-trace mode is off.', 'zinn-seo' ),
					// The sitemap moved: the old address redirects, and the new one should be
					// added in Search Console and Bing Webmaster Tools.
					link: moved ? viewHref( 'discovery' ) + '#/indexing' : '',
				} );
				setPlan( null );
				if ( onSwitched ) {
					onSwitched( result.enabled );
				}
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	if ( ! state ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}

	return (
		<div className="zseo-stack">
			<p>
				{ __(
					'No-trace mode hides that this site uses an SEO plugin: no plugin names in addresses, no stylesheet on the sitemap, neutral ids in structured data. Search engines get exactly the same information.',
					'zinn-seo'
				) }
			</p>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
					{ notice.link && (
						<>
							{ ' ' }
							<a href={ notice.link }>
								{ __(
									'Submit the new sitemap address to Google and Bing',
									'zinn-seo'
								) }
							</a>
						</>
					) }
				</Notice>
			) }
			{ state.forced ? (
				<Notice status="info" isDismissible={ false }>
					{ state.enabled
						? __(
								'Your hosting keeps no-trace mode on for this site.',
								'zinn-seo'
							)
						: __(
								'Your hosting keeps no-trace mode off for this site.',
								'zinn-seo'
							) }
				</Notice>
			) : (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'No-trace mode', 'zinn-seo' ) }
					checked={ !! state.enabled }
					disabled={ busy }
					onChange={ preview }
				/>
			) }
			{ plan && (
				<Modal
					title={
						plan.on
							? __( 'Turn no-trace mode on?', 'zinn-seo' )
							: __( 'Turn no-trace mode off?', 'zinn-seo' )
					}
					onRequestClose={ () => setPlan( null ) }
				>
					{ plan.changes.length === 0 ? (
						<p>
							{ __(
								'No address on this site changes. Nothing needs to be sent to search engines.',
								'zinn-seo'
							) }
						</p>
					) : (
						<>
							<p>
								{ __(
									'These change. Each old address is redirected to the new one.',
									'zinn-seo'
								) }
							</p>
							<ul className="zseo-changes">
								{ plan.changes.map( ( c ) => (
									<li key={ c.what }>
										<strong>{ c.what }</strong>
										{ ': ' }
										{ sprintf(
											/* translators: 1: old value, 2: new value. */
											__(
												'%1$s becomes %2$s',
												'zinn-seo'
											),
											c.from,
											c.to
										) }
										{ c.resubmit && (
											<span className="zseo-muted">
												{ ' ' }
												{ __(
													'(submit it again in Google Search Console and Bing Webmaster Tools)',
													'zinn-seo'
												) }
											</span>
										) }
									</li>
								) ) }
							</ul>
						</>
					) }
					<p className="zak-actions">
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ apply }
						>
							{ plan.on
								? __( 'Turn it on', 'zinn-seo' )
								: __( 'Turn it off', 'zinn-seo' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setPlan( null ) }
						>
							{ __( 'Cancel', 'zinn-seo' ) }
						</Button>
					</p>
				</Modal>
			) }
		</div>
	);
}

/**
 * Other SEO plugins the setup found.
 *
 * @param {Object} props
 * @param {Array}  props.plugins `{ file, name }` rows.
 * @return {Element} The note.
 */
export function OtherPlugins( { plugins } ) {
	if ( ! plugins || plugins.length === 0 ) {
		return (
			<p className="zseo-ok">
				{ __(
					'No other SEO plugin is active on this site, so nothing will print a second set of titles or tags.',
					'zinn-seo'
				) }
			</p>
		);
	}
	return (
		<div className="zseo-stack">
			<p>{ __( 'Active on this site:', 'zinn-seo' ) }</p>
			<ul className="zseo-found-list">
				{ plugins.map( ( p ) => (
					<li key={ p.file }>{ p.name }</li>
				) ) }
			</ul>
			<p>
				{ __(
					'Two SEO plugins on one site both print titles, descriptions and structured data, which confuses search engines. Keep it active until its data has been brought into Zinn® SEO, then switch it off.',
					'zinn-seo'
				) }
			</p>
			<p>
				<a
					className="components-button is-secondary"
					href={ viewHref( 'import' ) }
				>
					{ __( 'Move its data into Zinn® SEO', 'zinn-seo' ) }
				</a>
			</p>
		</div>
	);
}
