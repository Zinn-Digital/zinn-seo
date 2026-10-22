/**
 * Version rollback (zs-718).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { Table, useRoute } from '../p4/shared';

/**
 * What the last rollback did, in words.
 *
 * @param {Object} last The record.
 * @return {Object|null} {status, text}.
 */
function outcome( last ) {
	if ( ! last ) {
		return null;
	}
	switch ( last.outcome ) {
		case 'done':
			return {
				status: 'success',
				text: sprintf(
					/* translators: 1: old version, 2: new version. */
					__(
						'Went back from %1$s to %2$s. Your site answered normally afterwards.',
						'zinn-seo'
					),
					last.from,
					last.to
				),
			};
		case 'restored':
			return {
				status: 'warning',
				text: sprintf(
					/* translators: 1: version tried, 2: version kept, 3: the reason. */
					__(
						'Version %1$s was tried and put back: %3$s Your site runs %2$s, as before.',
						'zinn-seo'
					),
					last.to,
					last.from,
					last.why
				),
			};
		case 'not-started':
			return { status: 'error', text: last.why };
		default:
			return {
				status: 'error',
				text: sprintf(
					/* translators: %s: the reason. */
					__(
						'The earlier version did not work and putting the current one back also failed: %s Upload the plugin again under Plugins, Add New.',
						'zinn-seo'
					),
					last.why
				),
			};
	}
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function VersionTab( { data } ) {
	const state = useRoute( '/rollback' );
	const [ ask, setAsk ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	if ( ! state.data ) {
		return <Spinner />;
	}
	const s = state.data;
	const run = ( version ) => {
		setBusy( true );
		setError( '' );
		seoFetch( '/rollback', { method: 'POST', data: { version } } )
			.then( () => window.location.reload() )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => {
				setBusy( false );
				setAsk( null );
			} );
	};
	const last = outcome( s.last );
	const rows = ( s.versions || [] ).filter( ( v ) => ! v.current );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ sprintf(
					/* translators: %s: a version number. */
					__(
						'You run version %s. If an update ever causes a problem, go back to an earlier version in one click: it is checked first, your current version is kept aside, and if your site stops answering it is put straight back.',
						'zinn-seo'
					),
					s.current
				) }{ ' ' }
				<GuideLink data={ data } id="version" />
			</p>
			{ last && (
				<Notice status={ last.status } isDismissible={ false }>
					{ last.text }
				</Notice>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ s.premium ? (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Zinn® SEO Pro is updated through your licence: to go back, download the earlier version from your account and upload it under Plugins, Add New.',
						'zinn-seo'
					) }
				</Notice>
			) : (
				<Table
					label={ __( 'Earlier versions', 'zinn-seo' ) }
					empty={ __(
						'There is no earlier version to go back to yet.',
						'zinn-seo'
					) }
					columns={ [
						{
							id: 'version',
							label: __( 'Version', 'zinn-seo' ),
							render: ( r ) => (
								<strong dir="ltr">{ r.version }</strong>
							),
						},
						{
							id: 'when',
							label: __( 'Released', 'zinn-seo' ),
							render: ( r ) =>
								r.published
									? new Date(
											r.published
										).toLocaleDateString()
									: '—',
						},
						{
							id: 'go',
							label: __( 'Go back', 'zinn-seo' ),
							render: ( r ) =>
								r.ok ? (
									<Button
										variant="secondary"
										disabled={ ! s.allowed || busy }
										onClick={ () => setAsk( r.version ) }
									>
										{ sprintf(
											/* translators: %s: a version number. */
											__( 'Go back to %s', 'zinn-seo' ),
											r.version
										) }
									</Button>
								) : (
									<span className="zseo-muted">
										{ r.why }
									</span>
								),
						},
					] }
					rows={ rows.map( ( r ) => ( { ...r, id: r.version } ) ) }
				/>
			) }
			{ ! s.allowed && ! s.premium && (
				<p className="zseo-muted">
					{ s.network
						? __(
								'On a network, a network administrator does this from the main site.',
								'zinn-seo'
							)
						: __(
								'Only an administrator who can install plugins can go back.',
								'zinn-seo'
							) }
				</p>
			) }
			{ ask && (
				<Modal
					title={ sprintf(
						/* translators: %s: a version number. */
						__( 'Go back to version %s?', 'zinn-seo' ),
						ask
					) }
					onRequestClose={ () => ! busy && setAsk( null ) }
				>
					<p>
						{ __(
							'Your settings and SEO data stay as they are. It takes up to a minute; your site is checked straight afterwards and the current version comes back by itself if anything is wrong.',
							'zinn-seo'
						) }
					</p>
					<div className="zseo-actions">
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () => setAsk( null ) }
						>
							{ __( 'Cancel', 'zinn-seo' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => run( ask ) }
						>
							{ __( 'Go back', 'zinn-seo' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
