/**
 * The generic field controls of the SEO blocks' forms (block editor, Page Builder Sandwich) and of
 * the editor's Structured data panel: one control per field type the server describes.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';

import {
	countWords,
	defaultOf,
	lengthVerdict,
	linesToList,
	move,
	newRow,
} from './values';

/**
 * Advice under a text field with an advised length (the Quick Answer's 40-60 words).
 *
 * @param {Object} props
 * @param {string} props.value Text.
 * @param {Array}  props.range [min, max] words.
 * @return {Element|null} Advice.
 */
function WordAdvice( { value, range } ) {
	const verdict = lengthVerdict( value, range );
	if ( ! verdict ) {
		return null;
	}
	const n = countWords( value );
	let text;
	if ( 'short' === verdict ) {
		text = sprintf(
			/* translators: 1: words now, 2: fewest advised, 3: most advised. */
			__(
				'%1$d words: add a little — answers of %2$d to %3$d words are quoted most.',
				'zinn-seo'
			),
			n,
			range[ 0 ],
			range[ 1 ]
		);
	} else if ( 'long' === verdict ) {
		text = sprintf(
			/* translators: 1: words now, 2: fewest advised, 3: most advised. */
			__(
				'%1$d words: shorten it — answers of %2$d to %3$d words are quoted most.',
				'zinn-seo'
			),
			n,
			range[ 0 ],
			range[ 1 ]
		);
	} else {
		text = sprintf(
			/* translators: 1: words now, 2: fewest advised, 3: most advised. */
			__(
				'%1$d words: a length search engines and AI answers quote (%2$d-%3$d).',
				'zinn-seo'
			),
			n,
			range[ 0 ],
			range[ 1 ]
		);
	}
	return (
		<p className={ `zseo-blk-advice is-${ verdict }` } aria-live="polite">
			{ text }
		</p>
	);
}

/**
 * Pick an image from the media library.
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {number}   props.value    Attachment ID.
 * @param {Function} props.onChange Change.
 * @return {Element} Control.
 */
function ImageField( { label, value, onChange } ) {
	// From WordPress at render time (`window.wp`), so the module loads anywhere.
	const { MediaUpload, MediaUploadCheck } = window.wp.blockEditor;
	const url = useSelect(
		( select ) => {
			if ( ! value ) {
				return '';
			}
			const media = select( 'core' ).getMedia( value, {
				context: 'view',
			} );
			return media
				? media.media_details?.sizes?.thumbnail?.source_url ||
						media.source_url
				: '';
		},
		[ value ]
	);
	return (
		<div className="zseo-blk-field zseo-blk-image">
			<span className="zseo-blk-label">{ label }</span>
			{ url && <img src={ url } alt="" /> }
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ value }
					onSelect={ ( media ) => onChange( media.id ) }
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ value
								? __( 'Replace picture', 'zinn-seo' )
								: __( 'Choose a picture', 'zinn-seo' ) }
						</Button>
					) }
				/>
			</MediaUploadCheck>
			{ !! value && (
				<Button
					variant="link"
					isDestructive
					onClick={ () => onChange( 0 ) }
				>
					{ __( 'Remove', 'zinn-seo' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Pick a person who can write on the site.
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {number}   props.value    User ID.
 * @param {Function} props.onChange Change.
 * @return {Element} Control.
 */
function UserField( { label, value, onChange } ) {
	const users = useSelect(
		( select ) =>
			select( 'core' ).getUsers( {
				capabilities: [ 'edit_posts' ],
				per_page: 100,
				_fields: 'id,name',
				context: 'view',
			} ) || [],
		[]
	);
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ String( value || 0 ) }
			options={ [
				{ value: '0', label: __( 'Default', 'zinn-seo' ) },
				...users.map( ( u ) => ( {
					value: String( u.id ),
					label: u.name,
				} ) ),
			] }
			onChange={ ( v ) => onChange( parseInt( v, 10 ) || 0 ) }
		/>
	);
}

/**
 * One field's control.
 *
 * @param {Object}   props
 * @param {Object}   props.field    Field description from the server.
 * @param {*}        props.value    Value.
 * @param {Function} props.onChange Change.
 * @return {Element} Control.
 */
export function Field( { field, value, onChange } ) {
	const v = value === undefined ? defaultOf( field ) : value;
	const common = {
		label: field.label,
		help: field.help,
		__nextHasNoMarginBottom: true,
	};
	switch ( field.type ) {
		case 'textarea':
			return (
				<div className="zseo-blk-field">
					<TextareaControl
						{ ...common }
						rows={ 4 }
						value={ v }
						placeholder={ field.placeholder }
						onChange={ onChange }
					/>
					{ field.advise?.words && (
						<WordAdvice value={ v } range={ field.advise.words } />
					) }
				</div>
			);
		case 'number':
			return (
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					type="number"
					min={ field.min }
					max={ field.max }
					step={ field.step || 1 }
					value={ v === 0 ? '' : String( v ) }
					onChange={ ( n ) => onChange( n === '' ? 0 : Number( n ) ) }
				/>
			);
		case 'bool':
			return (
				<ToggleControl
					{ ...common }
					checked={ !! v }
					onChange={ onChange }
				/>
			);
		case 'select':
			return (
				<SelectControl
					{ ...common }
					__next40pxDefaultSize
					value={ String( v ) }
					options={ field.choices || [] }
					onChange={ onChange }
				/>
			);
		case 'url':
			return (
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					type="url"
					value={ v }
					placeholder="https://"
					onChange={ onChange }
				/>
			);
		case 'date':
			return (
				<TextControl
					{ ...common }
					__next40pxDefaultSize
					type="date"
					value={ String( v ).slice( 0, 10 ) }
					onChange={ onChange }
				/>
			);
		case 'image':
			return (
				<ImageField
					label={ field.label }
					value={ v }
					onChange={ onChange }
				/>
			);
		case 'user':
			return (
				<UserField
					label={ field.label }
					value={ v }
					onChange={ onChange }
				/>
			);
		case 'list':
			return (
				<TextareaControl
					{ ...common }
					rows={ 4 }
					value={ ( v || [] ).join( '\n' ) }
					onChange={ ( text ) =>
						onChange(
							text.endsWith( '\n' ) && ! field.keepEmpty
								? [ ...linesToList( text ), '' ]
								: linesToList( text, field.keepEmpty )
						)
					}
				/>
			);
		case 'repeater':
			return (
				<Repeater field={ field } value={ v } onChange={ onChange } />
			);
		default:
			return (
				<div className="zseo-blk-field">
					<TextControl
						{ ...common }
						__next40pxDefaultSize
						value={ v }
						placeholder={ field.placeholder }
						onChange={ onChange }
					/>
					{ field.advise?.words && (
						<WordAdvice value={ v } range={ field.advise.words } />
					) }
				</div>
			);
	}
}

/**
 * Rows of sub-fields: add, remove, move.
 *
 * @param {Object}   props
 * @param {Object}   props.field    The repeater field.
 * @param {Array}    props.value    Rows.
 * @param {Function} props.onChange Change.
 * @return {Element} Control.
 */
function Repeater( { field, value, onChange } ) {
	const rows = Array.isArray( value ) ? value : [];
	const set = ( i, key, v ) =>
		onChange(
			rows.map( ( r, j ) => ( j === i ? { ...r, [ key ]: v } : r ) )
		);
	return (
		<fieldset className="zseo-blk-repeater">
			<legend className="zseo-blk-label">{ field.label }</legend>
			{ rows.map( ( row, i ) => (
				<div className="zseo-blk-row" key={ i }>
					<div className="zseo-blk-row__head">
						<strong>
							{ sprintf(
								/* translators: 1: what a row is (Question, Step), 2: its number. */
								__( '%1$s %2$d', 'zinn-seo' ),
								field.item,
								i + 1
							) }
						</strong>
						<span>
							<Button
								size="small"
								icon="arrow-up-alt2"
								label={ __( 'Move up', 'zinn-seo' ) }
								disabled={ i === 0 }
								onClick={ () =>
									onChange( move( rows, i, i - 1 ) )
								}
							/>
							<Button
								size="small"
								icon="arrow-down-alt2"
								label={ __( 'Move down', 'zinn-seo' ) }
								disabled={ i === rows.length - 1 }
								onClick={ () =>
									onChange( move( rows, i, i + 1 ) )
								}
							/>
							<Button
								size="small"
								icon="trash"
								isDestructive
								label={ __( 'Remove', 'zinn-seo' ) }
								onClick={ () =>
									onChange(
										rows.filter( ( r, j ) => j !== i )
									)
								}
							/>
						</span>
					</div>
					{ ( field.fields || [] ).map( ( sub ) => (
						<Field
							key={ sub.key }
							field={ sub }
							value={ row[ sub.key ] }
							onChange={ ( v ) => set( i, sub.key, v ) }
						/>
					) ) }
				</div>
			) ) }
			<Button
				variant="secondary"
				icon="plus"
				onClick={ () => onChange( [ ...rows, newRow( field ) ] ) }
			>
				{ sprintf(
					/* translators: %s: what a row is (Question, Step). */
					__( 'Add %s', 'zinn-seo' ),
					String( field.item || '' ).toLowerCase()
				) }
			</Button>
		</fieldset>
	);
}
