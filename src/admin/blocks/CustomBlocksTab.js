import { __ } from '@wordpress/i18n';
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
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * Field kinds a custom block may have.
 *
 * @return {Object} Type => label.
 */
function fieldTypes() {
	return {
		text: __( 'Short text', 'zinn-seo' ),
		textarea: __( 'Long text', 'zinn-seo' ),
		number: __( 'Number', 'zinn-seo' ),
		url: __( 'Web address', 'zinn-seo' ),
		image: __( 'Picture', 'zinn-seo' ),
		date: __( 'Date', 'zinn-seo' ),
		select: __( 'Choice', 'zinn-seo' ),
		list: __( 'List (one per line)', 'zinn-seo' ),
		bool: __( 'Yes or no', 'zinn-seo' ),
	};
}

/**
 * One custom block's designer.
 *
 * @param {Object}   props
 * @param {Object}   props.block    Definition.
 * @param {Function} props.onChange Change.
 * @param {Function} props.onRemove Remove.
 * @return {Element} Designer.
 */
function Designer( { block, onChange, onRemove } ) {
	const fields = block.fields || [];
	const props = ( block.schema && block.schema.props ) || [];
	const setField = ( i, part ) =>
		onChange( {
			...block,
			fields: fields.map( ( f, j ) =>
				j === i ? { ...f, ...part } : f
			),
		} );
	const setProp = ( i, part ) =>
		onChange( {
			...block,
			schema: {
				...( block.schema || {} ),
				props: props.map( ( p, j ) =>
					j === i ? { ...p, ...part } : p
				),
			},
		} );
	const types = fieldTypes();
	return (
		<Card size="small" className="zseo-template">
			<CardHeader>
				<strong>
					{ block.title || __( 'New block', 'zinn-seo' ) }
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
						value={ block.title || '' }
						onChange={ ( v ) => onChange( { ...block, title: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Look', 'zinn-seo' ) }
						value={ block.layout || 'card' }
						options={ [
							{
								value: 'card',
								label: __( 'Card with a border', 'zinn-seo' ),
							},
							{
								value: 'box',
								label: __( 'Tinted box', 'zinn-seo' ),
							},
							{
								value: 'plain',
								label: __( 'Plain', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) =>
							onChange( { ...block, layout: v } )
						}
					/>
				</div>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'What it is for (shown in the inserter)',
						'zinn-seo'
					) }
					value={ block.description || '' }
					onChange={ ( v ) =>
						onChange( { ...block, description: v } )
					}
				/>
				<fieldset className="zseo-stack">
					<legend className="zseo-legend">
						{ __( 'Fields', 'zinn-seo' ) }
					</legend>
					{ fields.map( ( f, i ) => (
						<div key={ i } className="zseo-grid-3">
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Label', 'zinn-seo' ) }
								value={ f.label || '' }
								onChange={ ( v ) =>
									setField( i, {
										label: v,
										key:
											f.key ||
											v
												.toLowerCase()
												.replace( /[^a-z0-9]+/g, '_' )
												.replace( /^_+|_+$/g, '' ),
									} )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Tag in the template',
									'zinn-seo'
								) }
								dir="ltr"
								value={ f.key || '' }
								onChange={ ( v ) => setField( i, { key: v } ) }
							/>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Kind', 'zinn-seo' ) }
								value={ f.type || 'text' }
								options={ Object.keys( types ).map( ( k ) => ( {
									value: k,
									label: types[ k ],
								} ) ) }
								onChange={ ( v ) => setField( i, { type: v } ) }
							/>
							{ 'select' === f.type && (
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Choices (comma-separated)',
										'zinn-seo'
									) }
									value={ ( f.choices || [] ).join( ', ' ) }
									onChange={ ( v ) =>
										setField( i, {
											choices: v
												.split( ',' )
												.map( ( c ) => c.trim() ),
										} )
									}
								/>
							) }
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									onChange( {
										...block,
										fields: fields.filter(
											( x, j ) => j !== i
										),
									} )
								}
							>
								{ __( 'Remove field', 'zinn-seo' ) }
							</Button>
						</div>
					) ) }
					<Button
						variant="secondary"
						icon="plus"
						onClick={ () =>
							onChange( {
								...block,
								fields: [
									...fields,
									{ key: '', label: '', type: 'text' },
								],
							} )
						}
					>
						{ __( 'Add a field', 'zinn-seo' ) }
					</Button>
				</fieldset>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Template (optional)', 'zinn-seo' ) }
					help={ __(
						'HTML with {{tag}} where a field goes, and {{#tag}} … {{/tag}} around anything shown only when that field is filled. Empty: the fields are shown in order.',
						'zinn-seo'
					) }
					rows={ 5 }
					dir="ltr"
					value={ block.template || '' }
					onChange={ ( v ) => onChange( { ...block, template: v } ) }
				/>
				<fieldset className="zseo-stack">
					<legend className="zseo-legend">
						{ __( 'Structured data', 'zinn-seo' ) }
					</legend>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'schema.org type (empty: none)',
							'zinn-seo'
						) }
						dir="ltr"
						value={ ( block.schema && block.schema.type ) || '' }
						onChange={ ( v ) =>
							onChange( {
								...block,
								schema: {
									...( block.schema || {} ),
									type: v,
									props,
								},
							} )
						}
					/>
					{ props.map( ( p, i ) => (
						<div key={ i } className="zseo-grid-3">
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Property', 'zinn-seo' ) }
								dir="ltr"
								value={ p.prop || '' }
								onChange={ ( v ) => setProp( i, { prop: v } ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Value: {{tag}} or fixed text',
									'zinn-seo'
								) }
								dir="ltr"
								value={ p.value || '' }
								onChange={ ( v ) => setProp( i, { value: v } ) }
							/>
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									onChange( {
										...block,
										schema: {
											...( block.schema || {} ),
											props: props.filter(
												( x, j ) => j !== i
											),
										},
									} )
								}
							>
								{ __( 'Remove', 'zinn-seo' ) }
							</Button>
						</div>
					) ) }
					<Button
						variant="secondary"
						icon="plus"
						onClick={ () =>
							onChange( {
								...block,
								schema: {
									...( block.schema || {} ),
									props: [
										...props,
										{ prop: '', value: '' },
									],
								},
							} )
						}
					>
						{ __( 'Add a property', 'zinn-seo' ) }
					</Button>
				</fieldset>
			</CardBody>
		</Card>
	);
}

/**
 * Custom SEO blocks (zs-312): design a block once, use it in every builder.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The tab.
 */
export default function CustomBlocksTab( { data } ) {
	const [ list, setList ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ sentence, setSentence ] = useState( '' );
	useEffect( () => {
		seoFetch( '/custom-blocks' )
			.then( setList )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );
	if ( ! list ) {
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
		seoFetch( '/custom-blocks', { method: 'PUT', data: { blocks: list } } )
			.then( ( saved ) => {
				setList( saved );
				setNotice( {
					status: 'success',
					text: __(
						'Saved. The blocks are in the block editor, Page Builder Sandwich and Elementor (category SEO), and work as shortcodes.',
						'zinn-seo'
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const draft = () => {
		setBusy( true );
		seoFetch( '/custom-blocks/draft', {
			method: 'POST',
			data: { sentence },
		} )
			.then( ( d ) => setList( [ ...list, d ] ) )
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
					'Design your own block: its fields, how it looks and the structured data it adds. It appears in every page builder with no code.',
					'zinn-seo'
				) }
			</p>
			{ data.ai && (
				<Card size="small">
					<CardBody className="zseo-row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Describe a block and let AI draft it',
								'zinn-seo'
							) }
							placeholder={ __(
								'e.g. A box for a tour: duration, price, meeting point and a map link',
								'zinn-seo'
							) }
							value={ sentence }
							onChange={ setSentence }
						/>
						<Button
							variant="secondary"
							disabled={ ! sentence || busy }
							onClick={ draft }
						>
							{ __( 'Draft it', 'zinn-seo' ) }
						</Button>
					</CardBody>
				</Card>
			) }
			{ list.map( ( b, i ) => (
				<Designer
					key={ b.slug || i }
					block={ b }
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
							{
								title: '',
								layout: 'card',
								fields: [
									{
										key: 'title',
										label: __( 'Title', 'zinn-seo' ),
										type: 'text',
									},
								],
								template: '',
								schema: { type: '', props: [] },
							},
						] )
					}
				>
					{ __( 'New block', 'zinn-seo' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ save }
				>
					{ __( 'Save blocks', 'zinn-seo' ) }
				</Button>
			</div>
		</div>
	);
}
