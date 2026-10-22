/* Generated from wp/packages/zinn-admin-kit/src/js/DataList.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	SearchControl,
	SelectControl,
} from '@wordpress/components';

/**
 * Filter a list by search text and filter values. Exported for the tests.
 *
 * @param {Array<Object>}          items   Rows.
 * @param {string}                 search  Search text.
 * @param {Function}               getText Row -> the text the search looks in.
 * @param {Array<Object>}          filters Filter definitions (`id`, `test( row, value )`).
 * @param {Object<string, string>} values  Filter id -> chosen value ('' = any).
 * @return {Array<Object>} Matching rows, in their original order.
 */
export function filterItems( items, search, getText, filters, values ) {
	const needle = ( search || '' ).trim().toLocaleLowerCase();
	return items.filter( ( item ) => {
		if (
			needle &&
			! ( getText( item ) || '' ).toLocaleLowerCase().includes( needle )
		) {
			return false;
		}
		return filters.every(
			( filter ) =>
				! values[ filter.id ] ||
				filter.test( item, values[ filter.id ] )
		);
	} );
}

/**
 * A searchable, filterable list with bulk actions and pages (feature adm-1), built from the
 * WordPress components every admin screen already loads.
 *
 * ⭐ Not @wordpress/dataviews, measured 2026-09-27: its WordPress build (`/wp`) bundles its own
 * 2 MB copy of the component library, and its own words ("Search", "Filter", the page controls)
 * are in WordPress's `default` text domain, which WordPress core does not ship for a plugin's
 * script, so they would show in English on a site in any other language. Every word here goes
 * through this plugin's catalogue instead.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.items       Rows.
 * @param {Array<Object>} props.fields      Columns: `id`, `label`, `render( row )`.
 * @param {Function}      props.getItemId   Row -> unique id.
 * @param {Function}      props.getText     Row -> searchable text.
 * @param {string}        props.searchLabel The search box's label.
 * @param {Array<Object>} props.filters     `id`, `label`, `options` [{value,label}], `test`.
 * @param {Array<Object>} props.actions     Bulk actions: `id`, `label`, `isDestructive`, `callback( rows )`.
 * @param {string}        props.layout      `table` or `grid`.
 * @param {Function}      props.renderCard  Grid layout: row -> element.
 * @param {number}        props.perPage     Rows per page.
 * @param {string}        props.emptyText   Shown when nothing matches.
 * @param {string}        props.label       Accessible name of the table.
 * @return {Element} The list.
 */
export default function DataList( {
	items,
	fields = [],
	getItemId,
	getText,
	searchLabel,
	filters = [],
	actions = [],
	layout = 'table',
	renderCard,
	perPage = 20,
	emptyText,
	label,
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ values, setValues ] = useState( {} );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState( [] );
	const [ busy, setBusy ] = useState( false );

	const matching = useMemo(
		() => filterItems( items, search, getText, filters, values ),
		[ items, search, getText, filters, values ]
	);
	const pages = Math.max( 1, Math.ceil( matching.length / perPage ) );
	const current = Math.min( page, pages );
	const shown = matching.slice(
		( current - 1 ) * perPage,
		current * perPage
	);
	const shownIds = shown.map( getItemId );
	const selectedRows = items.filter( ( item ) =>
		selected.includes( getItemId( item ) )
	);
	const allShownSelected =
		shownIds.length > 0 &&
		shownIds.every( ( id ) => selected.includes( id ) );

	const toggle = ( id, on ) =>
		setSelected( ( now ) =>
			on ? [ ...now, id ] : now.filter( ( x ) => x !== id )
		);
	const toggleAll = ( on ) =>
		setSelected( ( now ) =>
			on
				? Array.from( new Set( [ ...now, ...shownIds ] ) )
				: now.filter( ( x ) => ! shownIds.includes( x ) )
		);
	const run = async ( action ) => {
		setBusy( true );
		try {
			await action.callback( selectedRows );
			setSelected( [] );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="zak-list">
			<div className="zak-list__toolbar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ searchLabel }
					placeholder={ searchLabel }
					value={ search }
					onChange={ ( next ) => {
						setSearch( next );
						setPage( 1 );
					} }
				/>
				{ filters.map( ( filter ) => (
					<SelectControl
						key={ filter.id }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ filter.label }
						value={ values[ filter.id ] || '' }
						options={ [
							{ value: '', label: __( 'All', 'zinn-seo' ) },
							...filter.options,
						] }
						onChange={ ( next ) => {
							setValues( ( now ) => ( {
								...now,
								[ filter.id ]: next,
							} ) );
							setPage( 1 );
						} }
					/>
				) ) }
				{ actions.length > 0 && selectedRows.length > 0 && (
					<div className="zak-list__bulk" role="group">
						<span>
							{ sprintf(
								/* translators: %d: number of selected rows. */
								_n(
									'%d selected',
									'%d selected',
									selectedRows.length,
									'zinn-seo'
								),
								selectedRows.length
							) }
						</span>
						{ actions.map( ( action ) => (
							<Button
								key={ action.id }
								variant="secondary"
								isDestructive={ action.isDestructive }
								isBusy={ busy }
								disabled={ busy }
								onClick={ () => run( action ) }
							>
								{ action.label }
							</Button>
						) ) }
					</div>
				) }
			</div>

			{ 0 === shown.length && (
				<p className="zak-list__empty">
					{ emptyText || __( 'Nothing matches.', 'zinn-seo' ) }
				</p>
			) }

			{ shown.length > 0 && 'grid' === layout && (
				<div className="zak-grid">
					{ shown.map( ( item ) => (
						<div key={ getItemId( item ) }>
							{ renderCard( item ) }
						</div>
					) ) }
				</div>
			) }

			{ shown.length > 0 && 'table' === layout && (
				<div className="zak-table-wrap">
					<table className="zak-table" aria-label={ label }>
						<thead>
							<tr>
								{ actions.length > 0 && (
									<th className="zak-table__check">
										<CheckboxControl
											__nextHasNoMarginBottom
											label={ __(
												'Select all on this page',
												'zinn-seo'
											) }
											checked={ allShownSelected }
											onChange={ toggleAll }
										/>
									</th>
								) }
								{ fields.map( ( field ) => (
									<th key={ field.id } scope="col">
										{ field.label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ shown.map( ( item ) => {
								const id = getItemId( item );
								return (
									<tr key={ id }>
										{ actions.length > 0 && (
											<td className="zak-table__check">
												<CheckboxControl
													__nextHasNoMarginBottom
													label={ __(
														'Select',
														'zinn-seo'
													) }
													checked={ selected.includes(
														id
													) }
													onChange={ ( on ) =>
														toggle( id, on )
													}
												/>
											</td>
										) }
										{ fields.map( ( field ) => (
											<td
												key={ field.id }
												data-label={ field.label }
											>
												{ field.render( item ) }
											</td>
										) ) }
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ pages > 1 && (
				<nav
					className="zak-list__pages"
					aria-label={ __( 'Pages', 'zinn-seo' ) }
				>
					<Button
						variant="tertiary"
						disabled={ current <= 1 }
						onClick={ () => setPage( current - 1 ) }
					>
						{ __( 'Previous', 'zinn-seo' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages. */
							__( 'Page %1$d of %2$d', 'zinn-seo' ),
							current,
							pages
						) }
					</span>
					<Button
						variant="tertiary"
						disabled={ current >= pages }
						onClick={ () => setPage( current + 1 ) }
					>
						{ __( 'Next', 'zinn-seo' ) }
					</Button>
				</nav>
			) }
		</div>
	);
}
