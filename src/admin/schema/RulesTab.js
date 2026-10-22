import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../api';

/**
 * Rules: which custom type every matching post gets, with no editing (zs-036).
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function RulesTab( { value, onChange, data } ) {
	const [ templates, setTemplates ] = useState( null );
	useEffect( () => {
		seoFetch( '/schema/templates' )
			.then( ( r ) => setTemplates( r.templates ) )
			.catch( () => setTemplates( [] ) );
	}, [] );
	const rules = value.schema_rules || {};
	const ids = Object.keys( rules );
	const types = ( data.schema && data.schema.postTypes ) || [];
	const set = ( id, part ) =>
		onChange( {
			schema_rules: { ...rules, [ id ]: { ...rules[ id ], ...part } },
		} );
	const remove = ( id ) => {
		const next = { ...rules };
		delete next[ id ];
		onChange( { schema_rules: next } );
	};
	const add = () => {
		let n = ids.length + 1;
		while ( rules[ `r${ n }` ] ) {
			n++;
		}
		onChange( {
			schema_rules: {
				...rules,
				[ `r${ n }` ]: {
					template:
						( templates && templates[ 0 ] && templates[ 0 ].id ) ||
						'',
					post_type: 'post',
				},
			},
		} );
	};

	if ( templates && ! templates.length ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Make a custom type first (the Custom types tab); a rule then gives it to every matching post.',
					'zinn-seo'
				) }
			</Notice>
		);
	}

	return (
		<PanelBody title={ __( 'Give a type to matching posts', 'zinn-seo' ) }>
			<p className="zseo-muted">
				{ __(
					'Every post that matches ALL of a rule’s conditions gets its type, filled from that post. An empty condition matches everything.',
					'zinn-seo'
				) }
			</p>
			{ ids.map( ( id ) => (
				<fieldset key={ id } className="zseo-card-row">
					<div className="zseo-grid-2">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Custom type', 'zinn-seo' ) }
							value={ rules[ id ].template || '' }
							options={ ( templates || [] ).map( ( t ) => ( {
								value: t.id,
								label: `${ t.name } (${ t.type })`,
							} ) ) }
							onChange={ ( v ) => set( id, { template: v } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Content type', 'zinn-seo' ) }
							value={ rules[ id ].post_type || '' }
							options={ [
								{ value: '', label: __( 'Any', 'zinn-seo' ) },
								...types.map( ( t ) => ( {
									value: t.name,
									label: t.label,
								} ) ),
							] }
							onChange={ ( v ) => set( id, { post_type: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'In this term (taxonomy:slug)',
								'zinn-seo'
							) }
							placeholder="category:recipes"
							dir="ltr"
							value={ rules[ id ].term || '' }
							onChange={ ( v ) => set( id, { term: v.trim() } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Has this custom field', 'zinn-seo' ) }
							dir="ltr"
							value={ rules[ id ].meta || '' }
							onChange={ ( v ) => set( id, { meta: v.trim() } ) }
						/>
					</div>
					<Button
						variant="link"
						isDestructive
						onClick={ () => remove( id ) }
					>
						{ __( 'Remove rule', 'zinn-seo' ) }
					</Button>
				</fieldset>
			) ) }
			<Button variant="secondary" icon="plus" onClick={ add }>
				{ __( 'Add a rule', 'zinn-seo' ) }
			</Button>
		</PanelBody>
	);
}
