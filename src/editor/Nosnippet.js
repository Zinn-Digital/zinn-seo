/**
 * "Hide from search result snippets" on every block (zs-741). The switch is stored in the
 * block's own settings; the page gets `data-nosnippet` on that block and nothing else.
 */
import { __ } from '@wordpress/i18n';
import { createHigherOrderComponent } from '@wordpress/compose';
import { ToggleControl } from '@wordpress/components';

// The block editor's own packages, from WordPress (`window.wp`), as the blocks bundle does.
const wp = window.wp || {};
const addFilter = ( wp.hooks || {} ).addFilter;
const { InspectorAdvancedControls } = wp.blockEditor || {};

export const ATTRIBUTE = 'zseoNosnippet';

/**
 * Add the attribute to a block type's settings. Exported for the tests.
 *
 * @param {Object} settings Block type settings.
 * @return {Object} Settings.
 */
export function withAttribute( settings ) {
	if ( ! settings || ! settings.attributes ) {
		return settings;
	}
	return {
		...settings,
		attributes: {
			...settings.attributes,
			[ ATTRIBUTE ]: { type: 'boolean', default: false },
		},
	};
}

const withControl = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => (
		<>
			<BlockEdit { ...props } />
			{ props.isSelected && (
				<InspectorAdvancedControls>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Hide from search result snippets',
							'zinn-seo'
						) }
						help={ __(
							'Search engines still read and index this block, but never quote it under your result (prices, disclaimers, spoilers).',
							'zinn-seo'
						) }
						checked={ !! props.attributes[ ATTRIBUTE ] }
						onChange={ ( on ) =>
							props.setAttributes( { [ ATTRIBUTE ]: on } )
						}
					/>
				</InspectorAdvancedControls>
			) }
		</>
	),
	'withZinnSeoNosnippet'
);

/**
 * Register the filters (only where the site switched the feature on).
 */
export default function registerNosnippet() {
	if ( ! addFilter || ! InspectorAdvancedControls ) {
		return;
	}
	addFilter(
		'blocks.registerBlockType',
		'zinn-seo/nosnippet-attribute',
		withAttribute
	);
	addFilter( 'editor.BlockEdit', 'zinn-seo/nosnippet-control', withControl );
}
