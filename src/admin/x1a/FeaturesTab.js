/**
 * Module switches (zs-715): every feature, on or off in one click, settings kept.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Notice,
	SearchControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { useRoute } from '../p4/shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function FeaturesTab( { data } ) {
	const state = useRoute( '/modules' );
	const [ busy, setBusy ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ query, setQuery ] = useState( '' );
	if ( ! state.data ) {
		return state.error ? (
			<Notice status="error" isDismissible={ false }>
				{ state.error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const all = state.data;
	const byId = Object.fromEntries( all.map( ( m ) => [ m.id, m ] ) );
	const flip = ( m, on ) => {
		setBusy( m.id );
		setNotice( null );
		seoFetch( '/modules/' + m.id, { method: 'PUT', data: { enabled: on } } )
			.then( ( list ) => {
				state.setData( list );
				setNotice( {
					status: 'success',
					text: on
						? sprintf(
								/* translators: %s: a feature's name. */
								__(
									'%s is on. Reload the page to see its screens.',
									'zinn-seo'
								),
								m.name
							)
						: sprintf(
								/* translators: %s: a feature's name. */
								__(
									'%s is off. Its settings are kept for when you switch it back on.',
									'zinn-seo'
								),
								m.name
							),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};
	const q = query.trim().toLowerCase();
	const shown = all.filter(
		( m ) =>
			m.available &&
			( ! q ||
				( m.name + ' ' + m.description ).toLowerCase().includes( q ) )
	);
	return (
		<div className="zseo-stack zseo-x1a-features">
			<p className="zseo-muted">
				{ __(
					'Switch off any feature you do not need. A feature that is off does not run at all, so it costs your site nothing, and its settings wait for you if you switch it back on.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="features" />
			</p>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Find a feature', 'zinn-seo' ) }
				value={ query }
				onChange={ setQuery }
			/>
			<ul className="zseo-x1a-modules">
				{ shown.map( ( m ) => {
					const needs = ( m.requires || [] ).filter(
						( r ) => byId[ r ] && ! byId[ r ].enabled
					);
					return (
						<li
							key={ m.id }
							className={ m.enabled ? 'is-on' : 'is-off' }
						>
							<ToggleControl
								__nextHasNoMarginBottom
								label={
									<>
										{ m.name }
										{ m.premium && (
											<span className="zseo-x1a-tag">
												{ __( 'Pro', 'zinn-seo' ) }
											</span>
										) }
									</>
								}
								help={ m.description }
								checked={ !! m.enabled }
								disabled={ m.locked || busy === m.id }
								onChange={ ( on ) => flip( m, on ) }
							/>
							{ m.locked && (
								<p className="zseo-muted zseo-x1a-note">
									{ __(
										'Part of the core: always on.',
										'zinn-seo'
									) }
								</p>
							) }
							{ needs.length > 0 && (
								<p className="zseo-muted zseo-x1a-note">
									{ sprintf(
										/* translators: %s: names of other features. */
										__(
											'Needs %s, which is switched off.',
											'zinn-seo'
										),
										needs
											.map( ( r ) => byId[ r ].name )
											.join( ', ' )
									) }
								</p>
							) }
							{ ( m.needed_by || [] ).length > 0 && m.enabled && (
								<p className="zseo-muted zseo-x1a-note">
									{ sprintf(
										/* translators: %s: names of other features. */
										__(
											'Switching it off also stops: %s.',
											'zinn-seo'
										),
										m.needed_by
											.filter(
												( r ) =>
													byId[ r ] &&
													byId[ r ].available
											)
											.map( ( r ) => byId[ r ].name )
											.join( ', ' )
									) }
								</p>
							) }
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}
