/**
 * The bulk editor's structured data details for one page (zs-764): FAQ, how-to steps, product
 * details and article details, each checked against schema.org and Google's rules before it is
 * kept. Saving happens with the rest of the row ("Save changes"), as one undoable batch.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	TabPanel,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import {
	cleanRecord,
	FIELDS,
	googleNote,
	KINDS,
	kindLabel,
	parseRecord,
	recordValue,
} from './fill-pure';

/**
 * A list of {a, b} rows with add and remove.
 *
 * @param {Object}   props
 * @param {Array}    props.rows     Rows.
 * @param {Object}   props.labels   { a, b, item, add }.
 * @param {Array}    props.keys     [ keyA, keyB ].
 * @param {Function} props.onChange New rows.
 * @return {Element} Rows.
 */
function Pairs( { rows, labels, keys, onChange } ) {
	const list = rows.length ? rows : [];
	const set = ( i, key, v ) =>
		onChange(
			list.map( ( r, n ) => ( n === i ? { ...r, [ key ]: v } : r ) )
		);
	return (
		<div className="zseo-stack">
			{ list.map( ( row, i ) => (
				<fieldset key={ i } className="zseo-fill__pair">
					<legend>
						{ sprintf(
							/* translators: 1: e.g. "Question", 2: its number. */
							__( '%1$s %2$d', 'zinn-seo' ),
							labels.item,
							i + 1
						) }
					</legend>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ labels.a }
						value={ row[ keys[ 0 ] ] || '' }
						onChange={ ( v ) => set( i, keys[ 0 ], v ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ labels.b }
						rows={ 2 }
						value={ row[ keys[ 1 ] ] || '' }
						onChange={ ( v ) => set( i, keys[ 1 ], v ) }
					/>
					<Button
						variant="link"
						isDestructive
						onClick={ () =>
							onChange( list.filter( ( r, n ) => n !== i ) )
						}
					>
						{ __( 'Remove', 'zinn-seo' ) }
					</Button>
				</fieldset>
			) ) }
			<p>
				<Button
					variant="secondary"
					onClick={ () =>
						onChange( [
							...list,
							{ [ keys[ 0 ] ]: '', [ keys[ 1 ] ]: '' },
						] )
					}
				>
					{ labels.add }
				</Button>
			</p>
		</div>
	);
}

/**
 * The form of one kind.
 *
 * @param {Object}   props
 * @param {string}   props.kind     Kind.
 * @param {Object}   props.record   Record.
 * @param {Function} props.onChange New record.
 * @return {Element} Form.
 */
function KindForm( { kind, record, onChange } ) {
	const r = record || {};
	const field = ( key, label, multi = false ) => {
		const Control = multi ? TextareaControl : TextControl;
		const value = Array.isArray( r[ key ] )
			? r[ key ].join( '\n' )
			: ( r[ key ] ?? '' );
		return (
			<Control
				__nextHasNoMarginBottom
				{ ...( multi ? { rows: 3 } : { __next40pxDefaultSize: true } ) }
				label={ label }
				value={ String( value ) }
				onChange={ ( v ) => onChange( { ...r, [ key ]: v } ) }
			/>
		);
	};
	if ( 'faq' === kind ) {
		return (
			<Pairs
				rows={ r.items || [] }
				keys={ [ 'question', 'answer' ] }
				labels={ {
					a: __( 'Question', 'zinn-seo' ),
					b: __( 'Answer', 'zinn-seo' ),
					item: __( 'Question', 'zinn-seo' ),
					add: __( 'Add a question', 'zinn-seo' ),
				} }
				onChange={ ( items ) => onChange( { ...r, items } ) }
			/>
		);
	}
	if ( 'howto' === kind ) {
		return (
			<div className="zseo-stack">
				{ field( 'name', __( 'What it shows how to do', 'zinn-seo' ) ) }
				{ field(
					'description',
					__( 'Introduction', 'zinn-seo' ),
					true
				) }
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					label={ __( 'Total time (minutes)', 'zinn-seo' ) }
					value={ String( r.minutes || '' ) }
					onChange={ ( v ) =>
						onChange( { ...r, minutes: parseInt( v, 10 ) || 0 } )
					}
				/>
				{ field(
					'supplies',
					__( 'Supplies (one per line)', 'zinn-seo' ),
					true
				) }
				{ field(
					'tools',
					__( 'Tools (one per line)', 'zinn-seo' ),
					true
				) }
				<Pairs
					rows={ r.steps || [] }
					keys={ [ 'name', 'text' ] }
					labels={ {
						a: __( 'Step title', 'zinn-seo' ),
						b: __( 'What to do', 'zinn-seo' ),
						item: __( 'Step', 'zinn-seo' ),
						add: __( 'Add a step', 'zinn-seo' ),
					} }
					onChange={ ( steps ) => onChange( { ...r, steps } ) }
				/>
			</div>
		);
	}
	if ( 'product' === kind ) {
		return (
			<div className="zseo-stack">
				{ field( 'brand', __( 'Brand', 'zinn-seo' ) ) }
				{ field( 'model', __( 'Model', 'zinn-seo' ) ) }
				{ field( 'material', __( 'Material', 'zinn-seo' ) ) }
				{ field( 'color', __( 'Colour', 'zinn-seo' ) ) }
				{ field( 'size', __( 'Size', 'zinn-seo' ) ) }
			</div>
		);
	}
	return (
		<div className="zseo-stack">
			{ field( 'description', __( 'Summary', 'zinn-seo' ), true ) }
			{ field(
				'keywords',
				__( 'Keywords (one per line)', 'zinn-seo' ),
				true
			) }
			{ field(
				'about',
				__( 'Main topics (one per line)', 'zinn-seo' ),
				true
			) }
		</div>
	);
}

/**
 * The dialog.
 *
 * @param {Object}   props
 * @param {Object}   props.row       The bulk row.
 * @param {Object}   props.values    The row's current field values (with unsaved edits).
 * @param {boolean}  props.isProduct The page is a WooCommerce product.
 * @param {Object}   props.data      Boot data (the guide link).
 * @param {Function} props.onDone    Called with the changed fields.
 * @param {Function} props.onClose   Close without keeping.
 * @return {Element} The dialog.
 */
export default function SchemaDetails( {
	row,
	values,
	isProduct,
	data,
	onDone,
	onClose,
} ) {
	const kinds = KINDS.filter( ( k ) => 'product' !== k || isProduct );
	const [ records, setRecords ] = useState( () =>
		Object.fromEntries(
			kinds.map( ( k ) => {
				const rec = parseRecord( values[ FIELDS[ k ] ] );
				// Lists edit as one line each.
				[ 'supplies', 'tools', 'keywords', 'about' ].forEach(
					( key ) => {
						if ( Array.isArray( rec[ key ] ) ) {
							rec[ key ] = rec[ key ].join( '\n' );
						}
					}
				);
				return [ k, rec ];
			} )
		)
	);
	const [ checks, setChecks ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const checkAll = () => {
		setBusy( true );
		setError( '' );
		return Promise.all(
			kinds.map( ( k ) => {
				const record = cleanRecord( k, records[ k ] );
				if ( ! Object.keys( record ).length ) {
					return Promise.resolve( [ k, null ] );
				}
				return seoFetch( '/schema/fill/check', {
					method: 'POST',
					data: { post: row.id, kind: k, record },
				} ).then( ( r ) => [ k, r ] );
			} )
		)
			.then( ( pairs ) => {
				const out = Object.fromEntries( pairs );
				setChecks( out );
				return out;
			} )
			.catch( ( e ) => {
				setError( errorText( e ) );
				return null;
			} )
			.finally( () => setBusy( false ) );
	};
	const keep = () =>
		checkAll().then( ( out ) => {
			if ( ! out ) {
				return;
			}
			if ( Object.values( out ).some( ( c ) => c && ! c.ok ) ) {
				setError(
					__(
						'Fix the problems marked below first: they would make the structured data wrong.',
						'zinn-seo'
					)
				);
				return;
			}
			const changed = {};
			kinds.forEach( ( k ) => {
				const next = recordValue( k, records[ k ] );
				// Compared as the same canonical record, so an untouched one is no change.
				if (
					next !==
					recordValue( k, parseRecord( values[ FIELDS[ k ] ] ) )
				) {
					changed[ FIELDS[ k ] ] = next;
				}
			} );
			onDone( changed );
		} );

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: a page title. */
				__( 'Structured data details: %s', 'zinn-seo' ),
				row.label
			) }
			onRequestClose={ onClose }
			className="zseo-modal zseo-modal--wide"
		>
			<div className="zseo-stack">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TabPanel
					className="zseo-tabs"
					tabs={ kinds.map( ( k ) => ( {
						name: k,
						title: kindLabel( k ),
					} ) ) }
				>
					{ ( tab ) => {
						const k = tab.name;
						const c = checks[ k ];
						return (
							<div className="zseo-stack">
								<p className="zseo-muted">
									{ googleNote( k ) }
								</p>
								<KindForm
									kind={ k }
									record={ records[ k ] }
									onChange={ ( rec ) =>
										setRecords( { ...records, [ k ]: rec } )
									}
								/>
								{ c && ( c.errors || [] ).length > 0 && (
									<Notice
										status="error"
										isDismissible={ false }
									>
										{ c.errors.join( ' ' ) }
									</Notice>
								) }
								{ c && ( c.warnings || [] ).length > 0 && (
									<ul className="zseo-fill__warnings">
										{ c.warnings.map( ( w, i ) => (
											<li key={ i }>{ w }</li>
										) ) }
									</ul>
								) }
								{ c && c.ok && (
									<p className="zseo-fill__ok">
										{ __(
											'Checked: valid schema.org, nothing Google would reject.',
											'zinn-seo'
										) }
									</p>
								) }
							</div>
						);
					} }
				</TabPanel>
				<p className="zseo-muted">
					{ __(
						'Kept with the row: select Save changes to store it. You can undo it afterwards under History and undo.',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="bulk-schema" />
				</p>
				<div className="zseo-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'zinn-seo' ) }
					</Button>
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ checkAll }
					>
						{ __( 'Check', 'zinn-seo' ) }
					</Button>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ keep }
					>
						{ __( 'Keep these details', 'zinn-seo' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
