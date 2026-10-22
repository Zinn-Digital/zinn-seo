import { __ } from '@wordpress/i18n';
import { CheckboxControl, PanelBody } from '@wordpress/components';

/**
 * The article index on every post of chosen types, with no editing (zs-301).
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function IndexTab( { value, onChange, data } ) {
	const types = ( data.schema && data.schema.postTypes ) || [];
	const on = value.article_index_types || [];
	return (
		<PanelBody
			title={ __( 'Add the article index automatically', 'zinn-seo' ) }
		>
			<p className="zseo-muted">
				{ __(
					'An "In this article" box at the top of each post: reading time, when it was last reviewed, its key takeaways and contents. It opens and closes with no script, and its jump links match the headings in every language. A post that has the block placed by hand keeps that one.',
					'zinn-seo'
				) }
			</p>
			<fieldset className="zseo-includes">
				<legend className="zseo-legend">
					{ __( 'On these kinds of content', 'zinn-seo' ) }
				</legend>
				{ types.map( ( t ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ t.name }
						label={ t.label }
						checked={ on.includes( t.name ) }
						onChange={ ( yes ) =>
							onChange( {
								article_index_types: yes
									? [ ...on, t.name ]
									: on.filter( ( n ) => n !== t.name ),
							} )
						}
					/>
				) ) }
			</fieldset>
			<p className="zseo-muted">
				{ __(
					'Each post’s takeaways and review date are in its editor, under Structured data.',
					'zinn-seo'
				) }
			</p>
		</PanelBody>
	);
}
