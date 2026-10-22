/**
 * Bundles: the admin screen, the block editor panel, the classic editor and term screens'
 * fields, the SEO blocks' editor (block editor and Page Builder Sandwich) and the SEO panel inside
 * Elementor's editor. A visitor's page loads nothing built here (zs-150), and each bundle loads only on the
 * screens that need it (zs-155).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		settings: path.resolve( __dirname, 'src/admin/index.js' ),
		editor: path.resolve( __dirname, 'src/editor/index.js' ),
		fields: path.resolve( __dirname, 'src/fields/index.js' ),
		blocks: path.resolve( __dirname, 'src/blocks/index.js' ),
		elementor: path.resolve( __dirname, 'src/elementor/index.js' ),
		classic: path.resolve( __dirname, 'src/classic/index.js' ),
	},
};
