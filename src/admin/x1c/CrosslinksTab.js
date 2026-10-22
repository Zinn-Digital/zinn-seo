/**
 * Links between your own sites (zs-733, Agency): the sites, their reading, and the footprint
 * warning on a no-trace site.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { useRoute } from '../p4/shared';
import { ErrorLine } from './shared';

/**
 * The footprint warning.
 *
 * @return {Element} Notice.
 */
export function FootprintWarning() {
	return (
		<Notice status="warning" isDismissible={ false }>
			{ __(
				'This site is in no-trace mode. Links between sites you own are a pattern search engines look for to tie a network together. Link between these sites only where a visitor would really want to go.',
				'zinn-seo'
			) }
		</Notice>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function CrosslinksTab( { data } ) {
	const on = data.modules && data.modules.crosslinks;
	const state = useRoute( on ? '/crosslinks/sites' : '/settings' );
	const [ url, setUrl ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	if ( ! on ) {
		return (
			<ProTeaser
				title={ __( 'Links between your sites', 'zinn-seo' ) }
				points={ [
					__(
						'Add your other websites and see which of their pages each post could link to.',
						'zinn-seo'
					),
					__(
						'The words to link from are picked from your own text.',
						'zinn-seo'
					),
					__( 'Part of the Agency plan.', 'zinn-seo' ),
				] }
			/>
		);
	}
	const add = () =>
		seoFetch( '/crosslinks/sites', {
			method: 'POST',
			data: { url, label },
		} )
			.then( () => {
				setUrl( '' );
				setLabel( '' );
				state.reload();
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	const read = () =>
		seoFetch( '/crosslinks/read', { method: 'POST' } )
			.then( () =>
				setNote(
					__(
						'Reading your sites in the background. Suggestions appear in the editor when it is done.',
						'zinn-seo'
					)
				)
			)
			.catch( ( e ) => setError( errorText( e ) ) );
	const remove = ( id ) =>
		seoFetch( `/crosslinks/sites/${ id }`, { method: 'DELETE' } )
			.then( state.reload )
			.catch( ( e ) => setError( errorText( e ) ) );
	const sites = ( state.data && state.data.sites ) || [];
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Add your other websites. Their published posts and pages are read from their public list, and each post you edit here is offered the pages on them it could link to.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="crosslinks" />
			</p>
			{ state.data && state.data.footprint && <FootprintWarning /> }
			<ErrorLine text={ error || state.error } />
			{ note && (
				<Notice status="success" onRemove={ () => setNote( '' ) }>
					{ note }
				</Notice>
			) }
			<div className="zseo-x1c-row">
				<TextControl
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Site address', 'zinn-seo' ) }
					value={ url }
					onChange={ setUrl }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name (optional)', 'zinn-seo' ) }
					value={ label }
					onChange={ setLabel }
				/>
			</div>
			<div className="zseo-x1c-row">
				<Button variant="secondary" disabled={ ! url } onClick={ add }>
					{ __( 'Add the site', 'zinn-seo' ) }
				</Button>
				<Button
					variant="primary"
					disabled={ ! sites.length }
					onClick={ read }
				>
					{ __( 'Read the sites now', 'zinn-seo' ) }
				</Button>
			</div>
			{ ! state.data && ! state.error && <Spinner /> }
			<ul className="zseo-x1c-posts">
				{ sites.map( ( s ) => (
					<li key={ s.id }>
						<strong>{ s.label }</strong>{ ' ' }
						<code dir="ltr">{ s.url }</code>{ ' ' }
						<span className="zseo-muted">
							{ s.read
								? sprintf(
										/* translators: %d: pages read. */
										_n(
											'%d page read',
											'%d pages read',
											s.posts,
											'zinn-seo'
										),
										s.posts
									)
								: __( 'Not read yet', 'zinn-seo' ) }
						</span>
						{ s.error && (
							<span className="zseo-x1c-err"> { s.error }</span>
						) }{ ' ' }
						<Button
							variant="link"
							isDestructive
							onClick={ () => remove( s.id ) }
						>
							{ __( 'Remove', 'zinn-seo' ) }
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
