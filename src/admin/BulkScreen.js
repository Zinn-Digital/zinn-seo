import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from './api';
import ProTeaser from './ProTeaser';
import SpreadsheetPanel from './SpreadsheetPanel';
import SchemaDetails from './schema/SchemaDetails';
import GuideLink from './p7/GuideLink';
import {
	FIELDS,
	KINDS,
	kindLabel,
	parseRecord,
	summary,
} from './schema/fill-pure';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function bulkTabs() {
	return [
		{ name: 'edit', title: __( 'Edit in bulk', 'zinn-seo' ) },
		{ name: 'history', title: __( 'History and undo', 'zinn-seo' ) },
		{ name: 'sheet', title: __( 'Spreadsheet', 'zinn-seo' ) },
	];
}

/**
 * The "search engines" choice of a robots list: '' (the type's default), 'show' or 'hide'.
 * Exported for the tests.
 *
 * @param {Array<string>} robots Robots flags.
 * @return {string} Choice.
 */
export function visibilityOf( robots ) {
	const list = robots || [];
	if ( list.includes( 'noindex' ) ) {
		return 'hide';
	}
	return list.includes( 'index' ) ? 'show' : '';
}

/**
 * A robots list with a new "search engines" choice; the other flags are kept. Exported for the tests.
 *
 * @param {Array<string>} robots Robots flags.
 * @param {string}        choice '', 'show' or 'hide'.
 * @return {Array<string>} Flags.
 */
export function withVisibility( robots, choice ) {
	const rest = ( robots || [] ).filter(
		( f ) => 'index' !== f && 'noindex' !== f
	);
	if ( 'hide' === choice ) {
		return [ 'noindex', ...rest ];
	}
	if ( 'show' === choice ) {
		return [ 'index', ...rest ];
	}
	return rest;
}

/**
 * The changes of a row: only fields that differ from what was loaded. Exported for the tests.
 *
 * @param {Object} loaded Fields as loaded.
 * @param {Object} edited Fields as edited.
 * @return {Object} Changed fields.
 */
export function changedFields( loaded, edited ) {
	const out = {};
	Object.keys( edited || {} ).forEach( ( k ) => {
		if ( JSON.stringify( edited[ k ] ) !== JSON.stringify( loaded[ k ] ) ) {
			out[ k ] = edited[ k ];
		}
	} );
	return out;
}

/**
 * The filters.
 *
 * @param {Object}   props
 * @param {Object}   props.filter   Current filter.
 * @param {Object}   props.choices  GET /bulk/choices.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The bar.
 */
function Filters( { filter, choices, onChange } ) {
	const kinds = [
		...( choices.types.post || [] ).map( ( t ) => ( {
			value: `post:${ t.value }`,
			label: t.label,
		} ) ),
		...( choices.types.term || [] ).map( ( t ) => ( {
			value: `term:${ t.value }`,
			label: t.label,
		} ) ),
	];
	return (
		<div className="zseo-filters">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Content', 'zinn-seo' ) }
				value={ `${ filter.object }:${ filter.type }` }
				options={ kinds }
				onChange={ ( v ) => {
					const [ object, type ] = v.split( ':' );
					onChange( { object, type, status: 'any' } );
				} }
			/>
			{ 'post' === filter.object && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Status', 'zinn-seo' ) }
					value={ filter.status }
					options={ [
						{ value: 'any', label: __( 'Any', 'zinn-seo' ) },
						{
							value: 'publish',
							label: __( 'Published', 'zinn-seo' ),
						},
						{ value: 'draft', label: __( 'Draft', 'zinn-seo' ) },
						{
							value: 'future',
							label: __( 'Scheduled', 'zinn-seo' ),
						},
						{
							value: 'private',
							label: __( 'Private', 'zinn-seo' ),
						},
					] }
					onChange={ ( status ) => onChange( { status } ) }
				/>
			) }
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Show', 'zinn-seo' ) }
				value={ filter.show }
				options={ [
					{ value: '', label: __( 'Everything', 'zinn-seo' ) },
					{
						value: 'missing-title',
						label: __( 'No SEO title of its own', 'zinn-seo' ),
					},
					{
						value: 'missing-desc',
						label: __( 'No description of its own', 'zinn-seo' ),
					},
					{
						value: 'hidden',
						label: __( 'Hidden from search results', 'zinn-seo' ),
					},
					{
						value: 'shown',
						label: __( 'Shown in search results', 'zinn-seo' ),
					},
					...( 'post' === filter.object
						? [
								{
									value: 'discover',
									label: __(
										'Not ready for Google Discover',
										'zinn-seo'
									),
								},
							]
						: [] ),
				] }
				onChange={ ( show ) => onChange( { show } ) }
			/>
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Search titles', 'zinn-seo' ) }
				value={ filter.search }
				onChange={ ( search ) => onChange( { search } ) }
			/>
		</div>
	);
}

/**
 * Query arguments of a filter.
 *
 * @param {Object} f Filter.
 * @return {Object} Arguments for GET /bulk and POST /bulk/apply.
 */
export function filterArgs( f ) {
	return {
		object: f.object,
		type: f.type,
		status: f.status,
		search: f.search,
		missing:
			{ 'missing-title': 'title', 'missing-desc': 'desc' }[ f.show ] ||
			'',
		robots: { hidden: 'noindex', shown: 'index' }[ f.show ] || '',
		discover: 'discover' === f.show,
	};
}

/**
 * One editable row.
 *
 * @param {Object}   props
 * @param {Object}   props.row       Row from GET /bulk.
 * @param {Object}   props.edited    Edited fields (or undefined).
 * @param {boolean}  props.social    Show the social columns.
 * @param {?Object}  props.schema    Structured data choices (the column is shown), or null.
 * @param {boolean}  props.selected  Selected for "apply to selected".
 * @param {Function} props.onSelect  Called with true/false.
 * @param {Function} props.onEdit    Called with a partial field change.
 * @param {Function} props.onDetails Open the structured data details.
 * @return {Element} The row.
 */
function Row( {
	row,
	edited,
	social,
	schema,
	selected,
	onSelect,
	onEdit,
	onDetails,
} ) {
	const f = { ...row.fields, ...( edited || {} ) };
	const title = f.title || '';
	const desc = f.desc || '';
	const id = `zseo-row-${ row.id }`;
	const dirty =
		edited && Object.keys( changedFields( row.fields, edited ) ).length > 0;
	return (
		<tr className={ dirty ? 'is-dirty' : '' }>
			<td className="zseo-bulk__check">
				<input
					type="checkbox"
					aria-label={ sprintf(
						/* translators: %s: a post or term title. */
						__( 'Select %s', 'zinn-seo' ),
						row.label
					) }
					checked={ selected }
					disabled={ ! row.can_edit }
					onChange={ ( e ) => onSelect( e.target.checked ) }
				/>
			</td>
			<td
				className="zseo-bulk__item"
				data-label={ __( 'Item', 'zinn-seo' ) }
			>
				<strong>
					<a href={ row.url } target="_blank" rel="noreferrer">
						{ row.label }
					</a>
				</strong>
				<span className="zseo-muted">
					{ ' ' }
					{ row.status && 'publish' !== row.status
						? `(${ row.status })`
						: '' }
					{ row.hidden
						? ` ${ __( 'hidden from search', 'zinn-seo' ) }`
						: '' }
				</span>
				{ row.edit_url && (
					<div>
						<a href={ row.edit_url }>
							{ __( 'Edit', 'zinn-seo' ) }
						</a>
					</div>
				) }
			</td>
			<td data-label={ __( 'SEO title', 'zinn-seo' ) }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					id={ `${ id }-title` }
					label={ __( 'SEO title', 'zinn-seo' ) }
					hideLabelFromVision
					value={ title }
					placeholder={ row.auto.title }
					disabled={ ! row.can_edit }
					onChange={ ( v ) => onEdit( { title: v } ) }
				/>
				<span
					className={
						( title || row.auto.title ).length > 60
							? 'zseo-count zseo-warn'
							: 'zseo-count zseo-muted'
					}
				>
					{ ( title || row.auto.title ).length }
				</span>
			</td>
			<td data-label={ __( 'Meta description', 'zinn-seo' ) }>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Meta description', 'zinn-seo' ) }
					hideLabelFromVision
					rows={ 2 }
					value={ desc }
					placeholder={ row.auto.desc }
					disabled={ ! row.can_edit }
					onChange={ ( v ) => onEdit( { desc: v } ) }
				/>
				<span
					className={
						( desc || row.auto.desc ).length > 160
							? 'zseo-count zseo-warn'
							: 'zseo-count zseo-muted'
					}
				>
					{ ( desc || row.auto.desc ).length }
				</span>
			</td>
			<td data-label={ __( 'Search engines', 'zinn-seo' ) }>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Search engines', 'zinn-seo' ) }
					hideLabelFromVision
					value={ visibilityOf( f.robots ) }
					disabled={ ! row.can_edit }
					options={ [
						{ value: '', label: __( 'Default', 'zinn-seo' ) },
						{ value: 'show', label: __( 'Show', 'zinn-seo' ) },
						{ value: 'hide', label: __( 'Hide', 'zinn-seo' ) },
					] }
					onChange={ ( v ) =>
						onEdit( { robots: withVisibility( f.robots, v ) } )
					}
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'nofollow', 'zinn-seo' ) }
					checked={ ( f.robots || [] ).includes( 'nofollow' ) }
					disabled={ ! row.can_edit }
					onChange={ ( on ) =>
						onEdit( {
							robots: on
								? [
										...( f.robots || [] ).filter(
											( x ) => 'follow' !== x
										),
										'nofollow',
									]
								: ( f.robots || [] ).filter(
										( x ) => 'nofollow' !== x
									),
						} )
					}
				/>
			</td>
			<td data-label={ __( 'Canonical address', 'zinn-seo' ) }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="url"
					id={ `${ id }-canonical` }
					label={ __( 'Canonical address', 'zinn-seo' ) }
					hideLabelFromVision
					value={ f.canonical || '' }
					placeholder={ row.auto.canonical }
					disabled={ ! row.can_edit }
					onChange={ ( v ) => onEdit( { canonical: v } ) }
				/>
			</td>
			{ schema && (
				<SchemaCell
					row={ row }
					f={ f }
					choices={ schema }
					onEdit={ onEdit }
					onDetails={ onDetails }
				/>
			) }
			{ social && (
				<td data-label={ __( 'When shared', 'zinn-seo' ) }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						id={ `${ id }-og-title` }
						label={ __( 'Title when shared', 'zinn-seo' ) }
						value={ f.og_title || '' }
						disabled={ ! row.can_edit }
						onChange={ ( v ) => onEdit( { og_title: v } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Text when shared', 'zinn-seo' ) }
						rows={ 2 }
						value={ f.og_desc || '' }
						disabled={ ! row.can_edit }
						onChange={ ( v ) => onEdit( { og_desc: v } ) }
					/>
				</td>
			) }
		</tr>
	);
}

/**
 * The structured data column (zs-764): page type, article type and the details.
 *
 * @param {Object}   props
 * @param {Object}   props.row       Row.
 * @param {Object}   props.f         Field values with edits.
 * @param {Object}   props.choices   { page, article } types.
 * @param {Function} props.onEdit    Partial field change.
 * @param {Function} props.onDetails Open the details dialog.
 * @return {Element} The cell.
 */
function SchemaCell( { row, f, choices, onEdit, onDetails } ) {
	const auto = ( row.auto && row.auto.schema ) || {};
	const fallback = ( type ) =>
		type
			? sprintf(
					/* translators: %s: a schema.org type, e.g. BlogPosting. */
					__( 'Default (%s)', 'zinn-seo' ),
					type
				)
			: __( 'Default', 'zinn-seo' );
	const filled = KINDS.map( ( k ) => [
		k,
		summary( k, parseRecord( f[ FIELDS[ k ] ] ) ),
	] ).filter( ( [ , text ] ) => text );
	return (
		<td
			className="zseo-bulk__schema"
			data-label={ __( 'Structured data', 'zinn-seo' ) }
		>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Page type', 'zinn-seo' ) }
				value={ f.schema_page || '' }
				disabled={ ! row.can_edit }
				options={ [
					{ value: '', label: fallback( auto.page || 'WebPage' ) },
					...choices.page.map( ( t ) => ( { value: t, label: t } ) ),
				] }
				onChange={ ( v ) => onEdit( { schema_page: v } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Article type', 'zinn-seo' ) }
				value={ f.schema_article || '' }
				disabled={ ! row.can_edit }
				options={ [
					{ value: '', label: fallback( auto.article ) },
					...choices.article.map( ( t ) => ( {
						value: t,
						label: t,
					} ) ),
					{
						value: 'none',
						label: __( 'Not an article', 'zinn-seo' ),
					},
				] }
				onChange={ ( v ) => onEdit( { schema_article: v } ) }
			/>
			<p className="zseo-muted zseo-bulk__filled">
				{ filled.length
					? filled
							.map(
								( [ k, text ] ) =>
									`${ kindLabel( k ) }: ${ text }`
							)
							.join( ' · ' )
					: __( 'No FAQ, steps or details yet.', 'zinn-seo' ) }
			</p>
			<Button
				variant="secondary"
				size="compact"
				disabled={ ! row.can_edit }
				onClick={ onDetails }
			>
				{ __( 'FAQ, steps and details…', 'zinn-seo' ) }
			</Button>
		</td>
	);
}

/**
 * "Change every matching" dialog.
 *
 * @param {Object}   props
 * @param {Object}   props.filter  Filter.
 * @param {number}   props.total   How many match (null when unknown).
 * @param {Array}    props.ids     Selected IDs (apply to these only when non-empty).
 * @param {?Object}  props.schema  Structured data choices, or null.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onDone  Called with the result.
 * @return {Element} The dialog.
 */
function ApplyDialog( { filter, total, ids, schema, onClose, onDone } ) {
	const [ field, setField ] = useState( 'robots' );
	const [ value, setValue ] = useState( 'hide' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const selected = ids.length > 0;
	const isSchema = [ 'schema_page', 'schema_article' ].includes( field );

	const fieldValue = () => {
		if ( 'robots' === field ) {
			return withVisibility( [], value );
		}
		return value;
	};
	const go = () => {
		setBusy( true );
		const request = selected
			? seoFetch( '/bulk', {
					method: 'PUT',
					data: {
						object: filter.object,
						rows: ids.map( ( id ) => ( {
							id,
							fields: { [ field ]: fieldValue() },
						} ) ),
					},
				} )
			: seoFetch( '/bulk/apply', {
					method: 'POST',
					data: {
						...filterArgs( filter ),
						fields: { [ field ]: fieldValue() },
					},
				} );
		request
			.then( onDone )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	let scope;
	if ( selected ) {
		scope = sprintf(
			/* translators: %d: number of selected rows. */
			_n(
				'The %d selected row.',
				'The %d selected rows.',
				ids.length,
				'zinn-seo'
			),
			ids.length
		);
	} else if ( null !== total && undefined !== total ) {
		scope = sprintf(
			/* translators: %d: number of matching rows. */
			_n(
				'Every matching row: %d.',
				'Every matching row: %d.',
				total,
				'zinn-seo'
			),
			total
		);
	} else {
		scope = __(
			'Every row matching the filters, including those not loaded yet.',
			'zinn-seo'
		);
	}

	return (
		<Modal
			title={ __( 'Change many at once', 'zinn-seo' ) }
			onRequestClose={ onClose }
			className="zseo-modal"
		>
			<div className="zseo-stack">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<p>
					<strong>{ __( 'Applies to:', 'zinn-seo' ) }</strong>{ ' ' }
					{ scope }
				</p>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Change', 'zinn-seo' ) }
					value={ field }
					options={ [
						{
							value: 'robots',
							label: __( 'Search engines', 'zinn-seo' ),
						},
						{
							value: 'title',
							label: __( 'SEO title', 'zinn-seo' ),
						},
						{
							value: 'desc',
							label: __( 'Meta description', 'zinn-seo' ),
						},
						{
							value: 'canonical',
							label: __( 'Canonical address', 'zinn-seo' ),
						},
						...( schema && 'post' === filter.object
							? [
									{
										value: 'schema_page',
										label: __(
											'Page type (structured data)',
											'zinn-seo'
										),
									},
									{
										value: 'schema_article',
										label: __(
											'Article type (structured data)',
											'zinn-seo'
										),
									},
								]
							: [] ),
					] }
					onChange={ ( v ) => {
						setField( v );
						setValue( 'robots' === v ? 'hide' : '' );
					} }
				/>
				{ schema &&
					[ 'schema_page', 'schema_article' ].includes( field ) && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'To', 'zinn-seo' ) }
							value={ value }
							options={ [
								{
									value: '',
									label: __(
										'Back to the default of its type',
										'zinn-seo'
									),
								},
								...( 'schema_page' === field
									? schema.page
									: schema.article
								).map( ( t ) => ( { value: t, label: t } ) ),
								...( 'schema_article' === field
									? [
											{
												value: 'none',
												label: __(
													'Not an article',
													'zinn-seo'
												),
											},
										]
									: [] ),
							] }
							onChange={ setValue }
						/>
					) }
				{ ! isSchema && 'robots' === field && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'To', 'zinn-seo' ) }
						value={ value }
						options={ [
							{
								value: 'hide',
								label: __(
									'Hide from search results',
									'zinn-seo'
								),
							},
							{
								value: 'show',
								label: __(
									'Show in search results',
									'zinn-seo'
								),
							},
							{
								value: '',
								label: __(
									'Back to the default of its type',
									'zinn-seo'
								),
							},
						] }
						onChange={ setValue }
					/>
				) }
				{ ! isSchema && 'robots' !== field && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'To', 'zinn-seo' ) }
						help={ sprintf(
							/* translators: %s: an example template, e.g. "%title% %sep% %sitename%". */
							__(
								"Variables work here, for example %s. Leave it empty to remove each page's own value, so its template applies again.",
								'zinn-seo'
							),
							'%title% %sep% %sitename%'
						) }
						value={ value }
						onChange={ setValue }
					/>
				) }
				<p className="zseo-muted">
					{ __(
						'You can undo this afterwards under History and undo.',
						'zinn-seo'
					) }
				</p>
				<div className="zseo-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'zinn-seo' ) }
					</Button>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ go }
					>
						{ __( 'Change them', 'zinn-seo' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}

/**
 * The editor table.
 *
 * @param {Object}   props
 * @param {Object}   props.data      Boot data (the guide links).
 * @param {Object}   props.choices   GET /bulk/choices.
 * @param {Function} props.onBatch   Called after a change (to refresh history).
 * @param {boolean}  props.canManage May change every matching row.
 * @return {Element} The tab.
 */
function EditTab( { data, choices, onBatch, canManage } ) {
	const first = ( choices.types.post || [] )[ 0 ];
	const [ filter, setFilter ] = useState( {
		object: first ? 'post' : 'term',
		type: first
			? first.value
			: ( ( choices.types.term || [] )[ 0 ] || {} ).value,
		status: 'any',
		search: '',
		show: '',
	} );
	const [ rows, setRows ] = useState( [] );
	const [ next, setNext ] = useState( null );
	const [ total, setTotal ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ edits, setEdits ] = useState( {} );
	const [ selected, setSelected ] = useState( [] );
	const [ social, setSocial ] = useState( false );
	const [ showSchema, setShowSchema ] = useState( false );
	const [ details, setDetails ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ dialog, setDialog ] = useState( false );

	const fetchPage = useCallback(
		( before ) => {
			setLoading( true );
			const all = {
				...filterArgs( filter ),
				before: String( before || 0 ),
				per_page: '50',
			};
			// Only what is set: an empty `discover` is not a boolean to the REST API.
			const args = new window.URLSearchParams();
			Object.entries( all ).forEach( ( [ k, v ] ) => {
				if ( true === v ) {
					args.set( k, '1' );
				} else if ( v !== false && v !== '' ) {
					args.set( k, String( v ) );
				}
			} );
			return seoFetch( `/bulk?${ args.toString() }` )
				.then( ( r ) => {
					setRows( ( old ) =>
						before ? [ ...old, ...r.rows ] : r.rows
					);
					setNext( r.next );
					setTotal( r.total );
				} )
				.catch( ( e ) =>
					setNotice( { status: 'error', text: errorText( e ) } )
				)
				.finally( () => setLoading( false ) );
		},
		[ filter ]
	);

	useEffect( () => {
		const t = window.setTimeout( () => {
			setEdits( {} );
			setSelected( [] );
			fetchPage( 0 );
		}, 300 );
		return () => window.clearTimeout( t );
	}, [ fetchPage ] );

	const schemaOn =
		showSchema && !! choices.schema && 'post' === filter.object;
	const pending = rows
		.map( ( r ) => ( {
			id: r.id,
			fields: changedFields( r.fields, edits[ r.id ] ),
		} ) )
		.filter( ( r ) => Object.keys( r.fields ).length > 0 );

	const undo = ( batch ) =>
		seoFetch( '/bulk/undo', { method: 'POST', data: { batch } } )
			.then( ( r ) => {
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: 1: fields restored, 2: fields changed since and left alone. */
						__(
							'Undone: %1$d fields restored, %2$d changed since and left as they are.',
							'zinn-seo'
						),
						r.restored,
						r.kept
					),
				} );
				onBatch();
				fetchPage( 0 );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);

	const saveAll = () => {
		setSaving( true );
		seoFetch( '/bulk', {
			method: 'PUT',
			data: { object: filter.object, rows: pending },
		} )
			.then( ( r ) => {
				const byId = {};
				r.rows.forEach( ( row ) => {
					byId[ row.id ] = row;
				} );
				setRows( ( old ) =>
					old.map( ( row ) => byId[ row.id ] || row )
				);
				setEdits( {} );
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %d: number of fields changed. */
						_n(
							'Saved %d change.',
							'Saved %d changes.',
							r.changes,
							'zinn-seo'
						),
						r.changes
					),
					batch: r.batch,
				} );
				onBatch();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setSaving( false ) );
	};

	const applied = ( r ) => {
		setDialog( false );
		setSelected( [] );
		if ( r.job ) {
			setNotice( {
				status: 'info',
				text: __(
					'The change is running in the background. Follow it under History and undo; this list refreshes when you reload it.',
					'zinn-seo'
				),
			} );
		} else {
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: %d: number of fields changed. */
					_n(
						'Saved %d change.',
						'Saved %d changes.',
						r.changes || 0,
						'zinn-seo'
					),
					r.changes || 0
				),
				batch: r.batch,
			} );
			fetchPage( 0 );
		}
		onBatch();
	};

	return (
		<div className="zseo-stack">
			<Filters
				filter={ filter }
				choices={ choices }
				onChange={ ( part ) => setFilter( { ...filter, ...part } ) }
			/>
			{ /* spokenMessage: without it Notice renders its children (the Undo button, which has
			hooks) to a string inside its own render, which broke React's hook order. */ }
			{ notice && (
				<Notice
					status={ notice.status }
					spokenMessage={ notice.text }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }{ ' ' }
					{ notice.batch && (
						<Button
							variant="link"
							onClick={ () => undo( notice.batch ) }
						>
							{ __( 'Undo', 'zinn-seo' ) }
						</Button>
					) }
				</Notice>
			) }
			<div className="zseo-bulk__tools">
				<span className="zseo-muted" aria-live="polite">
					{ null !== total && undefined !== total
						? sprintf(
								/* translators: 1: rows shown, 2: rows in all. */
								__( 'Showing %1$d of %2$d', 'zinn-seo' ),
								rows.length,
								total
							)
						: sprintf(
								/* translators: %d: rows shown. */
								_n(
									'Showing %d row',
									'Showing %d rows',
									rows.length,
									'zinn-seo'
								),
								rows.length
							) }
				</span>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Show the social columns', 'zinn-seo' ) }
					checked={ social }
					onChange={ setSocial }
				/>
				{ choices.schema && 'post' === filter.object && (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Show the structured data column',
							'zinn-seo'
						) }
						checked={ showSchema }
						onChange={ setShowSchema }
					/>
				) }
				{ choices.schema && 'post' === filter.object && (
					<GuideLink data={ data } id="bulk-schema" />
				) }
				{ ( canManage || selected.length > 0 ) && (
					<Button
						variant="secondary"
						onClick={ () => setDialog( true ) }
						disabled={ ! rows.length }
					>
						{ selected.length
							? sprintf(
									/* translators: %d: number of selected rows. */
									__( 'Change the %d selected…', 'zinn-seo' ),
									selected.length
								)
							: __( 'Change every matching row…', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			<div className="zseo-bulk__wrap">
				<table className="zseo-bulk widefat striped">
					<caption className="screen-reader-text">
						{ __(
							'SEO fields, one row per post or term',
							'zinn-seo'
						) }
					</caption>
					<colgroup>
						<col className="zseo-col--check" />
						<col className="zseo-col--item" />
						<col className="zseo-col--title" />
						<col className="zseo-col--desc" />
						<col className="zseo-col--robots" />
						<col />
						{ schemaOn && <col className="zseo-col--schema" /> }
						{ social && <col /> }
					</colgroup>
					<thead>
						<tr>
							<th scope="col" className="zseo-bulk__check">
								<input
									type="checkbox"
									aria-label={ __(
										'Select all loaded rows',
										'zinn-seo'
									) }
									checked={
										rows.length > 0 &&
										selected.length ===
											rows.filter( ( r ) => r.can_edit )
												.length
									}
									onChange={ ( e ) =>
										setSelected(
											e.target.checked
												? rows
														.filter(
															( r ) => r.can_edit
														)
														.map( ( r ) => r.id )
												: []
										)
									}
								/>
							</th>
							<th scope="col">{ __( 'Item', 'zinn-seo' ) }</th>
							<th scope="col">
								{ __( 'SEO title', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'Meta description', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'Search engines', 'zinn-seo' ) }
							</th>
							<th scope="col">
								{ __( 'Canonical address', 'zinn-seo' ) }
							</th>
							{ schemaOn && (
								<th scope="col">
									{ __( 'Structured data', 'zinn-seo' ) }
								</th>
							) }
							{ social && (
								<th scope="col">
									{ __( 'When shared', 'zinn-seo' ) }
								</th>
							) }
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<Row
								key={ row.id }
								row={ row }
								edited={ edits[ row.id ] }
								social={ social }
								schema={ schemaOn ? choices.schema : null }
								onDetails={ () => setDetails( row ) }
								selected={ selected.includes( row.id ) }
								onSelect={ ( on ) =>
									setSelected(
										on
											? [ ...selected, row.id ]
											: selected.filter(
													( x ) => x !== row.id
												)
									)
								}
								onEdit={ ( part ) =>
									setEdits( {
										...edits,
										[ row.id ]: {
											...( edits[ row.id ] || {} ),
											...part,
										},
									} )
								}
							/>
						) ) }
						{ ! loading && 0 === rows.length && (
							<tr>
								<td
									colSpan={
										6 +
										( social ? 1 : 0 ) +
										( schemaOn ? 1 : 0 )
									}
								>
									{ __(
										'Nothing matches these filters.',
										'zinn-seo'
									) }
								</td>
							</tr>
						) }
					</tbody>
				</table>
			</div>
			<div className="zseo-actions">
				{ loading && <Spinner /> }
				{ ! loading && null !== next && (
					<Button
						variant="secondary"
						onClick={ () => fetchPage( next ) }
					>
						{ __( 'Load more', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			<div className="zseo-savebar">
				<span className="zseo-muted" aria-live="polite">
					{ pending.length
						? sprintf(
								/* translators: %d: number of rows with unsaved changes. */
								_n(
									'%d row changed',
									'%d rows changed',
									pending.length,
									'zinn-seo'
								),
								pending.length
							)
						: '' }
				</span>
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving || ! pending.length }
					onClick={ saveAll }
				>
					{ __( 'Save changes', 'zinn-seo' ) }
				</Button>
			</div>
			{ details && (
				<SchemaDetails
					row={ details }
					values={ {
						...details.fields,
						...( edits[ details.id ] || {} ),
					} }
					isProduct={ 'product' === filter.type }
					data={ data }
					onClose={ () => setDetails( null ) }
					onDone={ ( part ) => {
						if ( Object.keys( part ).length ) {
							setEdits( {
								...edits,
								[ details.id ]: {
									...( edits[ details.id ] || {} ),
									...part,
								},
							} );
						}
						setDetails( null );
					} }
				/>
			) }
			{ dialog && (
				<ApplyDialog
					filter={ filter }
					total={ total }
					ids={ selected }
					schema={ choices.schema }
					onClose={ () => setDialog( false ) }
					onDone={ applied }
				/>
			) }
		</div>
	);
}

/**
 * The history of bulk changes, with undo.
 *
 * @param {Object}  props
 * @param {number}  props.version   Bumped after each change (reloads the list).
 * @param {boolean} props.canManage May undo.
 * @return {Element} The tab.
 */
function HistoryTab( { version, canManage } ) {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ tick, setTick ] = useState( 0 );

	useEffect( () => {
		seoFetch( '/bulk/history' )
			.then( setData )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [ version, tick ] );

	useEffect( () => {
		if ( ! data ) {
			return undefined;
		}
		const running = Object.values( data.jobs || {} ).some(
			( j ) => 'pending' === j.status
		);
		if ( ! running ) {
			return undefined;
		}
		const t = window.setTimeout( () => setTick( ( n ) => n + 1 ), 3000 );
		return () => window.clearTimeout( t );
	}, [ data ] );

	if ( ! data ) {
		return notice ? (
			<Notice status="error">{ notice.text }</Notice>
		) : (
			<Spinner />
		);
	}
	const undo = ( batch ) =>
		seoFetch( '/bulk/undo', { method: 'POST', data: { batch } } )
			.then( ( r ) => {
				setNotice( {
					status: 'success',
					text: r.job
						? __( 'Undoing in the background…', 'zinn-seo' )
						: sprintf(
								/* translators: 1: fields restored, 2: fields changed since and left alone. */
								__(
									'Undone: %1$d fields restored, %2$d changed since and left as they are.',
									'zinn-seo'
								),
								r.restored,
								r.kept
							),
				} );
				setTick( tick + 1 );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	const jobs = Object.entries( data.jobs || {} ).filter(
		( [ , j ] ) => 'pending' === j.status
	);

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
			{ jobs.map( ( [ id, j ] ) => (
				<Notice
					key={ id }
					status="info"
					isDismissible={ false }
					spokenMessage={ __(
						'Working in the background.',
						'zinn-seo'
					) }
				>
					<Spinner />{ ' ' }
					{ sprintf(
						/* translators: %d: rows done so far. */
						__(
							'Working in the background: %d done so far.',
							'zinn-seo'
						),
						( j.progress && j.progress.done ) || 0
					) }
				</Notice>
			) ) }
			{ 0 === data.batches.length ? (
				<p className="zseo-muted">
					{ __( 'No bulk changes yet.', 'zinn-seo' ) }
				</p>
			) : (
				<table className="zak-table zseo-history">
					<thead>
						<tr>
							<th scope="col">{ __( 'When', 'zinn-seo' ) }</th>
							<th scope="col">{ __( 'What', 'zinn-seo' ) }</th>
							<th scope="col">{ __( 'Who', 'zinn-seo' ) }</th>
							<th scope="col">{ __( 'Fields', 'zinn-seo' ) }</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'zinn-seo' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ data.batches.map( ( b ) => (
							<tr key={ b.id }>
								<td>
									{ new Date(
										b.when * 1000
									).toLocaleString() }
								</td>
								<td>{ b.label }</td>
								<td>{ b.who || '—' }</td>
								<td>{ b.count }</td>
								<td>
									{ b.undone ? (
										<span className="zseo-muted">
											{ __( 'Undone', 'zinn-seo' ) }
										</span>
									) : (
										canManage && (
											<Button
												variant="link"
												onClick={ () => undo( b.id ) }
											>
												{ __( 'Undo', 'zinn-seo' ) }
											</Button>
										)
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

/**
 * The Bulk editor screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function BulkScreen( { data } ) {
	const [ choices, setChoices ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ version, setVersion ] = useState( 0 );
	const isPro = 'free' !== data.plan;

	useEffect( () => {
		seoFetch( '/bulk/choices' )
			.then( setChoices )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! choices ) {
		return <Spinner />;
	}
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	return (
		<div className="zseo-admin">
			<TabPanel
				className="zseo-tabs"
				tabs={ bulkTabs() }
				initialTabName={
					bulkTabs().some( ( t ) => t.name === initial )
						? initial
						: 'edit'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					if ( 'history' === tab.name ) {
						return (
							<HistoryTab
								version={ version }
								canManage={ !! data.canManage }
							/>
						);
					}
					if ( 'sheet' === tab.name ) {
						return isPro && data.csv ? (
							<SpreadsheetPanel
								onBatch={ () => setVersion( version + 1 ) }
							/>
						) : (
							<ProTeaser
								title={ __(
									'Spreadsheet import and export',
									'zinn-seo'
								) }
								points={ [
									__(
										'Every SEO field of every post and term in one CSV file: edit it in Excel, LibreOffice or Google Sheets.',
										'zinn-seo'
									),
									__(
										'Import it back with a preview of every change first, and undo the whole import with one click.',
										'zinn-seo'
									),
									__(
										'Move SEO work between a staging copy and the live site, matched by address.',
										'zinn-seo'
									),
								] }
							/>
						);
					}
					return (
						<EditTab
							data={ data }
							choices={ choices }
							canManage={ !! data.canManage }
							onBatch={ () => setVersion( version + 1 ) }
						/>
					);
				} }
			</TabPanel>
		</div>
	);
}
