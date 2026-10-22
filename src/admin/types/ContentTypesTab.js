import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Modal,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';

/**
 * Who registered a type, in words.
 *
 * @param {Object} by `{ kind, name }`.
 * @return {string} Text.
 */
export function registeredBy( by = {} ) {
	switch ( by.kind ) {
		case 'core':
			return __( 'WordPress', 'zinn-seo' );
		case 'plugin':
		case 'mu-plugin':
			/* translators: %s: a plugin's name. */
			return sprintf( __( 'Plugin: %s', 'zinn-seo' ), by.name );
		case 'theme':
			/* translators: %s: a theme's name. */
			return sprintf( __( 'Theme: %s', 'zinn-seo' ), by.name );
		default:
			return __( 'Not known', 'zinn-seo' );
	}
}

/**
 * The settings change one switch makes. Pure, for the tests.
 *
 * @param {Object}  settings The current settings.
 * @param {Object}  row      The type's row (`key`, `name`).
 * @param {string}  what     `index`, `sitemap` or `scores`.
 * @param {boolean} on       The switch's new state.
 * @return {Object} The settings to save.
 */
export function switchChange( settings, row, what, on ) {
	if ( 'index' === what ) {
		const templates = { ...( settings.templates || {} ) };
		templates[ row.key ] = {
			...( templates[ row.key ] || {} ),
			index: on ? 'index' : 'noindex',
		};
		return { templates };
	}
	if ( 'sitemap' === what ) {
		const field = row.key.startsWith( 'tax:' )
			? 'sitemap_exclude_taxonomies'
			: 'sitemap_exclude_types';
		const list = ( settings[ field ] || [] ).filter(
			( t ) => t !== row.name
		);
		return { [ field ]: on ? list : [ ...list, row.name ] };
	}
	const types = { ...( settings.content_types || {} ) };
	types[ row.key ] = { scores: on ? 'on' : 'off' };
	return { content_types: types };
}

/**
 * The rows "Hide all suggested" would change: suggested and still in search, the sitemap or scores.
 *
 * @param {Array<Object>} rows Rows.
 * @return {Array<Object>} Rows.
 */
export function suggestedRows( rows = [] ) {
	return rows.filter(
		( r ) => r.suggested && ( r.index || r.sitemap || r.scores )
	);
}

/**
 * Search appearance › Content types (Q277, zs-768): every content type and taxonomy, who
 * registered it, and whether search engines, the sitemap and the content scores see it.
 *
 * @param {Object} props
 * @param {Object} props.data The admin app's boot data (for the guide link).
 * @return {Element} The tab.
 */
export default function ContentTypesTab( { data: boot = {} } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ plan, setPlan ] = useState( null );

	const load = () =>
		seoFetch( '/content-types' )
			.then( setData )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		load();
	}, [] );

	const flip = ( row, what, on ) => {
		setBusy( `${ row.key }:${ what }` );
		setNotice( null );
		seoFetch( '/settings' )
			.then( ( r ) =>
				seoFetch( '/settings', {
					method: 'PUT',
					data: {
						settings: switchChange( r.settings, row, what, on ),
					},
				} )
			)
			.then( load )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};

	const preview = ( keys ) => {
		setBusy( 'plan' );
		seoFetch( '/content-types/hide', {
			method: 'POST',
			data: { keys, dry_run: true },
		} )
			.then( ( r ) => setPlan( { keys, types: r.types } ) )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};

	const apply = () => {
		setBusy( 'apply' );
		seoFetch( '/content-types/hide', {
			method: 'POST',
			data: { keys: plan.keys, dry_run: false },
		} )
			.then( ( r ) => {
				setPlan( null );
				setNotice( {
					status: 'success',
					undo: true,
					text: sprintf(
						/* translators: %d: how many content types. */
						_n(
							'%d content type is hidden from search, the sitemap and the content scores.',
							'%d content types are hidden from search, the sitemap and the content scores.',
							r.types.length,
							'zinn-seo'
						),
						r.types.length
					),
				} );
				return load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};

	const undo = () => {
		setBusy( 'undo' );
		seoFetch( '/content-types/undo', { method: 'POST' } )
			.then( () => {
				setNotice( {
					status: 'success',
					text: __(
						'Undone: the content types are back as they were.',
						'zinn-seo'
					),
				} );
				return load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	const suggested = suggestedRows( [ ...data.types, ...data.taxonomies ] );

	const toggle = ( r, what, on, label, disabled ) => (
		<ToggleControl
			__nextHasNoMarginBottom
			label={
				<>
					<span aria-hidden="true">
						{ on
							? __( 'Yes', 'zinn-seo' )
							: __( 'No', 'zinn-seo' ) }
					</span>
					<span className="screen-reader-text">
						{ sprintf(
							/* translators: 1: a switch's name, e.g. "Shown in search", 2: a content type's name. */
							__( '%1$s: %2$s', 'zinn-seo' ),
							label,
							r.label
						) }
					</span>
				</>
			}
			checked={ on }
			disabled={ disabled || !! busy }
			onChange={ ( next ) => flip( r, what, next ) }
		/>
	);

	const table = ( rows, withScores, caption ) => (
		<div className="zak-table-wrap">
			<table className="zak-table zseo-ctypes">
				<caption className="screen-reader-text">{ caption }</caption>
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Content type', 'zinn-seo' ) }
						</th>
						<th scope="col">
							{ __( 'Shown in search', 'zinn-seo' ) }
						</th>
						<th scope="col">
							{ __( 'In the sitemap', 'zinn-seo' ) }
						</th>
						{ withScores && (
							<th scope="col">
								{ __(
									'Include in content scores and analysis',
									'zinn-seo'
								) }
							</th>
						) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r ) => (
						<tr key={ r.key } data-zseo-type={ r.key }>
							<td data-label={ __( 'Content type', 'zinn-seo' ) }>
								<strong>{ r.label }</strong>{ ' ' }
								<code className="zseo-muted">{ r.name }</code>
								<br />
								<span className="zseo-muted">
									{ registeredBy( r.by ) }
									{ ' · ' }
									{ sprintf(
										/* translators: %s: how many items. */
										_n(
											'%s item',
											'%s items',
											r.items,
											'zinn-seo'
										),
										Number( r.items ).toLocaleString()
									) }
								</span>
								{ ! r.own_page && (
									<span className="zseo-chip zseo-ctypes__flag">
										{ __(
											'No page of its own',
											'zinn-seo'
										) }
									</span>
								) }
								{ r.own_page && r.suggested && (
									<span className="zseo-chip zseo-ctypes__flag">
										{ __(
											'Probably shown inside other pages',
											'zinn-seo'
										) }
									</span>
								) }
								{ ( r.index || r.sitemap || r.scores ) && (
									<span className="zseo-ctypes__hide">
										<Button
											variant="link"
											disabled={ !! busy }
											onClick={ () =>
												preview( [ r.key ] )
											}
										>
											{ __(
												'Hide this type from search',
												'zinn-seo'
											) }
										</Button>
									</span>
								) }
							</td>
							<td
								data-label={ __(
									'Shown in search',
									'zinn-seo'
								) }
							>
								{ toggle(
									r,
									'index',
									r.index,
									__( 'Shown in search', 'zinn-seo' ),
									! r.own_page
								) }
							</td>
							<td
								data-label={ __(
									'In the sitemap',
									'zinn-seo'
								) }
							>
								{ toggle(
									r,
									'sitemap',
									r.sitemap,
									__( 'In the sitemap', 'zinn-seo' ),
									! r.own_page || ! r.public || ! r.index
								) }
							</td>
							{ withScores && (
								<td
									data-label={ __(
										'Include in content scores and analysis',
										'zinn-seo'
									) }
								>
									{ toggle(
										r,
										'scores',
										r.scores,
										__(
											'Include in content scores and analysis',
											'zinn-seo'
										),
										// Attachments are never scored (they are files).
										'attachment' === r.name
									) }
								</td>
							) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);

	return (
		<div className="zseo-stack zseo-ctypes-tab">
			<p>
				{ __(
					'Every kind of content on this site, including the ones other plugins and your theme add. Choose for each one whether search engines show it, whether it is in your sitemap and whether its items get content scores. Kinds with no page of their own, such as FAQ or review items shown inside other pages, are left out of scores and the sitemap from the start.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ boot } id="content-types" />
			</p>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					actions={
						notice.undo && data.undo
							? [
									{
										label: __( 'Undo', 'zinn-seo' ),
										onClick: undo,
									},
								]
							: []
					}
				>
					{ notice.text }
				</Notice>
			) }
			{ suggested.length > 0 && (
				<Card className="zseo-ctypes__suggest">
					<CardBody>
						<p>
							{ sprintf(
								/* translators: %d: how many content types. */
								_n(
									'%d content type has no page of its own or is probably shown inside other pages, and is still in search, the sitemap or the content scores.',
									'%d content types have no page of their own or are probably shown inside other pages, and are still in search, the sitemap or the content scores.',
									suggested.length,
									'zinn-seo'
								),
								suggested.length
							) }
						</p>
						<Button
							variant="primary"
							isBusy={ 'plan' === busy }
							disabled={ !! busy }
							onClick={ () =>
								preview( suggested.map( ( r ) => r.key ) )
							}
						>
							{ __(
								'Hide the suggested types from search',
								'zinn-seo'
							) }
						</Button>
					</CardBody>
				</Card>
			) }
			{ ! notice && data.undo && (
				<p>
					<Button
						variant="link"
						onClick={ undo }
						disabled={ !! busy }
					>
						{ __( 'Undo the last "Hide from search"', 'zinn-seo' ) }
					</Button>
				</p>
			) }
			<h3>{ __( 'Content types', 'zinn-seo' ) }</h3>
			{ table( data.types, true, __( 'Content types', 'zinn-seo' ) ) }
			<h3>
				{ __( 'Categories, tags and other taxonomies', 'zinn-seo' ) }
			</h3>
			{ table(
				data.taxonomies,
				false,
				__( 'Categories, tags and other taxonomies', 'zinn-seo' )
			) }
			{ plan && (
				<Modal
					title={ __( 'Hide from search', 'zinn-seo' ) }
					onRequestClose={ () => setPlan( null ) }
				>
					<p>
						{ __(
							'These content types will be kept out of search results and the sitemap, and their items will no longer be scored. Nothing is deleted, and you can undo it.',
							'zinn-seo'
						) }
					</p>
					<ul className="zseo-ctypes__plan">
						{ plan.types.map( ( t ) => (
							<li key={ t.key }>
								{ sprintf(
									/* translators: 1: a content type's name, 2: how many items it has. */
									_n(
										'%1$s: %2$s item',
										'%1$s: %2$s items',
										t.items,
										'zinn-seo'
									),
									t.label,
									Number( t.items ).toLocaleString()
								) }
							</li>
						) ) }
					</ul>
					<p className="zak-actions">
						<Button
							variant="primary"
							isBusy={ 'apply' === busy }
							disabled={ !! busy }
							onClick={ apply }
						>
							{ __( 'Hide from search', 'zinn-seo' ) }
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
