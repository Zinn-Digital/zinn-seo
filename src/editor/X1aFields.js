/**
 * Stage X1A's parts of the "Search and social" panel: the main category (zs-745) and the snippet
 * limits with what the search result will show (zs-741).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { SelectControl, TextControl } from '@wordpress/components';

import GuideAnchor, { editorGuide } from '../admin/x1a/GuideAnchor';

/**
 * The description as the search result will show it: nothing with nosnippet, cut at the
 * snippet limit, else as written. Exported for the tests.
 *
 * @param {string}        desc   The description.
 * @param {Array<string>} robots The page's robots flags.
 * @param {string}        max    The snippet limit ('' none, '-1' no limit).
 * @return {{text: string, note: string}} What shows, and why.
 */
export function snippetPreview( desc, robots, max ) {
	if ( ( robots || [] ).includes( 'nosnippet' ) ) {
		return {
			text: '',
			note: __(
				'No text snippet: search results show only the title and the address.',
				'zinn-seo'
			),
		};
	}
	const n = parseInt( max, 10 );
	if ( ! isNaN( n ) && n >= 0 && ( desc || '' ).length > n ) {
		return {
			text: 0 === n ? '' : desc.slice( 0, n ).trimEnd() + '…',
			note: sprintf(
				/* translators: %d: characters. */
				__(
					'Search engines may quote at most %d characters.',
					'zinn-seo'
				),
				n
			),
		};
	}
	return { text: desc || '', note: '' };
}

/**
 * The main category picker: a choice among the post's own categories (shown with two or more).
 *
 * @param {Object}   props
 * @param {number}   props.value    The main term id (0 = chosen by WordPress).
 * @param {Function} props.onChange Called with an id.
 * @return {Element|null} The control.
 */
export function PrimaryTermField( { value, onChange } ) {
	const map = ( window.zinnSeoEditor || {} ).primaryTerm || {};
	const { type, ids, terms } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const postType = editor.getCurrentPostType();
		const def = map[ postType ];
		if ( ! def ) {
			return { type: null, ids: [], terms: [] };
		}
		const chosen = editor.getEditedPostAttribute( def.rest ) || [];
		return {
			type: def,
			ids: chosen,
			terms: chosen.length
				? select( 'core' ).getEntityRecords( 'taxonomy', def.taxonomy, {
						include: chosen,
						per_page: 100,
						context: 'view',
					} ) || []
				: [],
		};
	}, [] );
	if ( ! type || ids.length < 2 ) {
		return null;
	}
	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: a taxonomy's singular name, e.g. Category. */
					__( 'Main %s', 'zinn-seo' ),
					type.label.toLowerCase()
				) }
				help={ __(
					'Used in the address, the breadcrumb trail and the structured data.',
					'zinn-seo'
				) }
				value={ String( value || 0 ) }
				options={ [
					{
						value: '0',
						label: __( 'Chosen by WordPress', 'zinn-seo' ),
					},
					...terms.map( ( t ) => ( {
						value: String( t.id ),
						label: t.name,
					} ) ),
				] }
				onChange={ ( v ) => onChange( parseInt( v, 10 ) || 0 ) }
			/>
			<p className="zseo-ed-hint">
				<GuideAnchor href={ editorGuide( 'primary-term' ) } />
			</p>
		</>
	);
}

/**
 * The page's snippet limits.
 *
 * @param {Object}   props
 * @param {Object}   props.value Fields.
 * @param {Function} props.set   Change fields.
 * @return {Element} The fields.
 */
export function SnippetFields( { value, set } ) {
	const number = ( name, label, help ) => (
		<TextControl
			__nextHasNoMarginBottom
			type="number"
			min={ -1 }
			max={ 9999 }
			label={ label }
			help={ help }
			value={ value[ name ] ?? '' }
			onChange={ ( v ) =>
				set( {
					[ name ]:
						'' === v
							? ''
							: String( Math.max( -1, parseInt( v, 10 ) || 0 ) ),
				} )
			}
		/>
	);
	return (
		<fieldset className="zseo-ed-limits">
			<legend className="zseo-ed-label">
				{ __( 'What search results may show', 'zinn-seo' ) }
			</legend>
			{ number(
				'max_snippet',
				__( 'Longest text snippet (characters)', 'zinn-seo' ),
				__(
					'Empty: the site default. -1: no limit. 0: no text.',
					'zinn-seo'
				)
			) }
			{ number(
				'max_video',
				__( 'Longest video preview (seconds)', 'zinn-seo' ),
				__( 'Empty: the site default. -1: no limit.', 'zinn-seo' )
			) }
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Largest image preview', 'zinn-seo' ) }
				value={ value.max_image || '' }
				options={ [
					{ value: '', label: __( 'Site default', 'zinn-seo' ) },
					{ value: 'none', label: __( 'None', 'zinn-seo' ) },
					{ value: 'standard', label: __( 'Standard', 'zinn-seo' ) },
					{ value: 'large', label: __( 'Large', 'zinn-seo' ) },
				] }
				onChange={ ( v ) => set( { max_image: v } ) }
			/>
			<p className="zseo-ed-hint">
				{ __(
					'To keep one block out of the snippet, select it and switch on "Hide from search result snippets" in its Advanced settings.',
					'zinn-seo'
				) }{ ' ' }
				<GuideAnchor href={ editorGuide( 'snippets' ) } />
			</p>
		</fieldset>
	);
}
