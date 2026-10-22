import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * Where a property's value comes from, in words.
 *
 * @return {Object} Source => label.
 */
export function sourceLabels() {
	return {
		text: __( 'Fixed text', 'zinn-seo' ),
		title: __( 'The post’s title', 'zinn-seo' ),
		summary: __( 'The post’s description', 'zinn-seo' ),
		content: __( 'The start of the post’s text', 'zinn-seo' ),
		url: __( 'The post’s address', 'zinn-seo' ),
		image: __( 'The post’s featured image', 'zinn-seo' ),
		published: __( 'Published date', 'zinn-seo' ),
		modified: __( 'Updated date', 'zinn-seo' ),
		author: __( 'The author', 'zinn-seo' ),
		author_url: __( 'The author’s page', 'zinn-seo' ),
		meta: __( 'A custom field', 'zinn-seo' ),
		term: __( 'A category or tag', 'zinn-seo' ),
		site: __( 'The site’s name', 'zinn-seo' ),
		json: __( 'Fixed JSON (nested values)', 'zinn-seo' ),
	};
}

/**
 * What the value box asks for, by source.
 *
 * @param {string} source Source.
 * @return {string} Label ('' = no value needed).
 */
function valueLabel( source ) {
	switch ( source ) {
		case 'text':
			return __( 'Text', 'zinn-seo' );
		case 'meta':
			return __( 'Custom field name', 'zinn-seo' );
		case 'term':
			return __( 'Taxonomy (e.g. category)', 'zinn-seo' );
		case 'json':
			return __( 'JSON', 'zinn-seo' );
		default:
			return '';
	}
}

/**
 * A schema.org type box with suggestions from the vocabulary.
 *
 * @param {Object}   props
 * @param {string}   props.value    Type.
 * @param {Function} props.onChange Change.
 * @return {Element} Control.
 */
function TypeInput( { value, onChange } ) {
	const [ list, setList ] = useState( [] );
	useEffect( () => {
		const t = setTimeout( () => {
			seoFetch(
				`/schema/vocab/types?q=${ encodeURIComponent( value || '' ) }`
			)
				.then( setList )
				.catch( () => setList( [] ) );
		}, 200 );
		return () => clearTimeout( t );
	}, [ value ] );
	const id = 'zseo-types-list';
	return (
		<>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'schema.org type', 'zinn-seo' ) }
				help={ __(
					'Any of the 900+ types, e.g. Recipe, Event, Dentist, Vehicle.',
					'zinn-seo'
				) }
				value={ value }
				list={ id }
				dir="ltr"
				onChange={ onChange }
			/>
			<datalist id={ id }>
				{ list.map( ( t ) => (
					<option key={ t } value={ t } />
				) ) }
			</datalist>
		</>
	);
}

/**
 * The visual builder for one custom type.
 *
 * @param {Object}   props
 * @param {Object}   props.template Template.
 * @param {Function} props.onChange Change.
 * @param {Function} props.onRemove Remove.
 * @return {Element} Editor.
 */
function TemplateEditor( { template, onChange, onRemove } ) {
	const [ props, setProps ] = useState( [] );
	useEffect( () => {
		if ( ! template.type ) {
			setProps( [] );
			return;
		}
		seoFetch(
			`/schema/vocab/properties?type=${ encodeURIComponent(
				template.type
			) }`
		)
			.then( ( r ) => setProps( r.properties.map( ( p ) => p.name ) ) )
			.catch( () => setProps( [] ) );
	}, [ template.type ] );
	const labels = sourceLabels();
	const rows = template.props || [];
	const setRow = ( i, part ) =>
		onChange( {
			...template,
			props: rows.map( ( r, j ) => ( j === i ? { ...r, ...part } : r ) ),
		} );
	const listId = `zseo-props-${ template.id || 'new' }`;
	return (
		<Card size="small" className="zseo-template">
			<CardHeader>
				<strong>
					{ template.name || __( 'New type', 'zinn-seo' ) }
				</strong>
				<Button variant="link" isDestructive onClick={ onRemove }>
					{ __( 'Delete', 'zinn-seo' ) }
				</Button>
			</CardHeader>
			<CardBody className="zseo-stack">
				<div className="zseo-grid-2">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Name', 'zinn-seo' ) }
						value={ template.name || '' }
						onChange={ ( v ) =>
							onChange( { ...template, name: v } )
						}
					/>
					<TypeInput
						value={ template.type || '' }
						onChange={ ( v ) =>
							onChange( { ...template, type: v } )
						}
					/>
				</div>
				<datalist id={ listId }>
					{ props.map( ( p ) => (
						<option key={ p } value={ p } />
					) ) }
				</datalist>
				<table className="zseo-props">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Property', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'Filled from', 'zinn-seo' ) }
							</th>
							<th scope="col">{ __( 'Value', 'zinn-seo' ) }</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Remove', 'zinn-seo' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( r, i ) => (
							<tr key={ i }>
								<td data-label={ __( 'Property', 'zinn-seo' ) }>
									<TextControl
										__nextHasNoMarginBottom
										hideLabelFromVision
										label={ __( 'Property', 'zinn-seo' ) }
										list={ listId }
										dir="ltr"
										value={ r.prop }
										onChange={ ( v ) =>
											setRow( i, { prop: v } )
										}
									/>
								</td>
								<td
									data-label={ __(
										'Filled from',
										'zinn-seo'
									) }
								>
									<SelectControl
										__nextHasNoMarginBottom
										hideLabelFromVision
										label={ __(
											'Filled from',
											'zinn-seo'
										) }
										value={ r.source }
										options={ Object.keys( labels ).map(
											( k ) => ( {
												value: k,
												label: labels[ k ],
											} )
										) }
										onChange={ ( v ) =>
											setRow( i, { source: v } )
										}
									/>
								</td>
								<td data-label={ __( 'Value', 'zinn-seo' ) }>
									{ valueLabel( r.source ) ? (
										<TextControl
											__nextHasNoMarginBottom
											hideLabelFromVision
											label={ valueLabel( r.source ) }
											placeholder={ valueLabel(
												r.source
											) }
											value={ r.value || '' }
											dir={
												'json' === r.source
													? 'ltr'
													: undefined
											}
											onChange={ ( v ) =>
												setRow( i, { value: v } )
											}
										/>
									) : (
										<span className="zseo-muted">—</span>
									) }
								</td>
								<td>
									<Button
										icon="trash"
										label={ __( 'Remove', 'zinn-seo' ) }
										isDestructive
										size="small"
										onClick={ () =>
											onChange( {
												...template,
												props: rows.filter(
													( x, j ) => j !== i
												),
											} )
										}
									/>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<Button
					variant="secondary"
					icon="plus"
					onClick={ () =>
						onChange( {
							...template,
							props: [
								...rows,
								{ prop: '', source: 'title', value: '' },
							],
						} )
					}
				>
					{ __( 'Add a property', 'zinn-seo' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * Custom types: build visually, start from a preset, or import from any page (zs-034).
 *
 * @return {Element} The tab.
 */
export default function CustomTab() {
	const [ state, setState ] = useState( null );
	const [ list, setList ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ url, setUrl ] = useState( '' );
	const [ imported, setImported ] = useState( null );

	useEffect( () => {
		seoFetch( '/schema/templates' )
			.then( ( r ) => {
				setState( r );
				setList( r.templates );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );

	if ( ! state ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const save = () => {
		setBusy( true );
		seoFetch( '/schema/templates', {
			method: 'PUT',
			data: { templates: list },
		} )
			.then( ( saved ) => {
				setList( saved );
				setNotice( {
					status: 'success',
					text: __(
						'Saved. Apply a type to pages with a rule, or to one page in its editor.',
						'zinn-seo'
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const importFrom = () => {
		setBusy( true );
		setImported( null );
		seoFetch( '/schema/import', { method: 'POST', data: { url } } )
			.then( setImported )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="zseo-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'Describe anything schema.org can: pick a type, then say where each property’s value comes from — the post’s title, image, dates, a custom field, a category, or fixed text.',
					'zinn-seo'
				) }
			</p>
			{ list.map( ( t, i ) => (
				<TemplateEditor
					key={ t.id || i }
					template={ t }
					onChange={ ( next ) =>
						setList(
							list.map( ( x, j ) => ( j === i ? next : x ) )
						)
					}
					onRemove={ () =>
						setList( list.filter( ( x, j ) => j !== i ) )
					}
				/>
			) ) }
			<div className="zseo-row">
				<Button
					variant="secondary"
					icon="plus"
					onClick={ () =>
						setList( [
							...list,
							{ id: '', name: '', type: 'Thing', props: [] },
						] )
					}
				>
					{ __( 'New type', 'zinn-seo' ) }
				</Button>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Start from', 'zinn-seo' ) }
					value=""
					options={ [
						{ value: '', label: __( 'Choose…', 'zinn-seo' ) },
						...state.presets.map( ( p ) => ( {
							value: p.id,
							label: p.name,
						} ) ),
					] }
					onChange={ ( id ) => {
						const p = state.presets.find( ( x ) => x.id === id );
						if ( p ) {
							setList( [ ...list, { ...p, id: '' } ] );
						}
					} }
				/>
			</div>
			<Card size="small">
				<CardHeader>
					<strong>
						{ __( 'Copy from another page', 'zinn-seo' ) }
					</strong>
				</CardHeader>
				<CardBody className="zseo-stack">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="url"
						dir="ltr"
						label={ __(
							'Address of a page with structured data',
							'zinn-seo'
						) }
						value={ url }
						onChange={ setUrl }
					/>
					<Button
						variant="secondary"
						disabled={ ! url || busy }
						isBusy={ busy }
						onClick={ importFrom }
					>
						{ __( 'Read its structured data', 'zinn-seo' ) }
					</Button>
					{ imported && (
						<ul className="zseo-picks">
							{ imported.templates.map( ( t, i ) => (
								<li key={ i }>
									<span dir="ltr">
										{ t.type }: { t.name }
									</span>{ ' ' }
									<Button
										variant="link"
										onClick={ () =>
											setList( [ ...list, t ] )
										}
									>
										{ sprintf(
											/* translators: %d: how many properties. */
											__(
												'Use it (%d properties)',
												'zinn-seo'
											),
											t.props.length
										) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</CardBody>
			</Card>
			<p className="zak-actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ save }
				>
					{ __( 'Save custom types', 'zinn-seo' ) }
				</Button>
			</p>
		</div>
	);
}
