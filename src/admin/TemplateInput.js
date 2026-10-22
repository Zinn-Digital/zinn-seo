import { __, sprintf } from '@wordpress/i18n';
import { useRef } from '@wordpress/element';
import {
	Button,
	Dropdown,
	MenuGroup,
	MenuItem,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

/**
 * Insert `%name%` into a text at the cursor (or at the end). Exported for the tests.
 *
 * @param {string} text  Current text.
 * @param {string} name  Variable name.
 * @param {number} start Selection start (or -1 for the end).
 * @param {number} end   Selection end.
 * @return {{text: string, cursor: number}} New text and where the cursor goes.
 */
export function insertVariable( text, name, start = -1, end = -1 ) {
	const token = `%${ name }%`;
	const value = text || '';
	const from = start >= 0 ? start : value.length;
	const to = end >= 0 ? end : from;
	const before = value.slice( 0, from );
	const after = value.slice( to );
	const padBefore = before && ! /\s$/.test( before ) ? ' ' : '';
	const padAfter = after && ! /^\s/.test( after ) ? ' ' : '';
	const next = `${ before }${ padBefore }${ token }${ padAfter }${ after }`;
	return {
		text: next,
		cursor: ( before + padBefore + token ).length,
	};
}

/**
 * The variables that have a value on a kind of page.
 *
 * @param {Array<Object>} variables `{ name, label, where }` from GET /templates.
 * @param {string}        group     Context group (home, post, archive, tax, author, date, search, 404).
 * @return {Array<Object>} Variables.
 */
export function variablesFor( variables, group ) {
	return ( variables || [] ).filter( ( v ) =>
		( v.where || [] ).includes( group )
	);
}

/**
 * A template field: text with an "Insert variable" menu and a length count of what it gives.
 *
 * @param {Object}        props
 * @param {string}        props.label       Label.
 * @param {string}        props.value       Template.
 * @param {string}        props.placeholder What it is when empty (the default template).
 * @param {Function}      props.onChange    Called with the new template.
 * @param {Array<Object>} props.variables   Variables to offer.
 * @param {boolean}       props.multiline   A textarea.
 * @param {string}        props.help        Help text.
 * @return {Element} The field.
 */
export default function TemplateInput( {
	label,
	value,
	placeholder,
	onChange,
	variables,
	multiline = false,
	help = '',
} ) {
	const ref = useRef( null );
	const Control = multiline ? TextareaControl : TextControl;

	const insert = ( name, close ) => {
		const field = ref.current
			? ref.current.querySelector( 'input, textarea' )
			: null;
		const start = field ? field.selectionStart : -1;
		const end = field ? field.selectionEnd : -1;
		const result = insertVariable( value, name, start, end );
		onChange( result.text );
		close();
		window.requestAnimationFrame( () => {
			if ( field ) {
				field.focus();
				field.setSelectionRange( result.cursor, result.cursor );
			}
		} );
	};

	return (
		<div className="zseo-template" ref={ ref }>
			<Control
				__nextHasNoMarginBottom
				__next40pxDefaultSize={ ! multiline }
				label={ label }
				value={ value || '' }
				placeholder={ placeholder }
				help={ help }
				rows={ multiline ? 3 : undefined }
				onChange={ onChange }
			/>
			<Dropdown
				className="zseo-template__vars"
				popoverProps={ { placement: 'bottom-end' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						variant="tertiary"
						size="small"
						onClick={ onToggle }
						aria-expanded={ isOpen }
						aria-label={ sprintf(
							/* translators: %s: the field's label, e.g. "Title". */
							__( 'Insert a variable into %s', 'zinn-seo' ),
							label
						) }
					>
						{ __( 'Insert variable', 'zinn-seo' ) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<MenuGroup
						className="zseo-template__menu"
						label={ __( 'Variables', 'zinn-seo' ) }
					>
						{ variables.map( ( v ) => (
							<MenuItem
								key={ v.name }
								info={ v.label }
								onClick={ () => insert( v.name, onClose ) }
							>
								{ `%${ v.name }%` }
							</MenuItem>
						) ) }
					</MenuGroup>
				) }
			/>
		</div>
	);
}

/**
 * How the page could look in search results: title, address, description, with lengths.
 *
 * @param {Object} props
 * @param {string} props.title Title.
 * @param {string} props.url   Address.
 * @param {string} props.desc  Description.
 * @return {Element} The preview.
 */
export function SnippetPreview( { title, url, desc } ) {
	let shown = url || '';
	try {
		const u = new window.URL( url );
		shown = u.host + decodeURI( u.pathname ).replace( /\/$/, '' );
	} catch {
		// Not an absolute address: show it as it is.
	}
	const titleLong = ( title || '' ).length > 60;
	const descLong = ( desc || '' ).length > 160;
	return (
		<div
			className="zseo-snippet"
			role="group"
			aria-label={ __( 'Search result preview', 'zinn-seo' ) }
		>
			<div className="zseo-snippet__url" dir="ltr">
				{ shown }
			</div>
			<div className="zseo-snippet__title">
				{ title || __( '(no title)', 'zinn-seo' ) }
			</div>
			<div className="zseo-snippet__desc">
				{ desc ||
					__(
						'No description: search engines will pick some text from the page.',
						'zinn-seo'
					) }
			</div>
			<div className="zseo-snippet__counts zseo-muted">
				<span className={ titleLong ? 'zseo-warn' : '' }>
					{ sprintf(
						/* translators: %d: number of characters. */
						__( 'Title: %d characters', 'zinn-seo' ),
						( title || '' ).length
					) }
				</span>{ ' ' }
				<span className={ descLong ? 'zseo-warn' : '' }>
					{ sprintf(
						/* translators: %d: number of characters. */
						__( 'Description: %d characters', 'zinn-seo' ),
						( desc || '' ).length
					) }
				</span>
			</div>
			{ ( titleLong || descLong ) && (
				<p className="zseo-warn">
					{ __(
						'Search engines usually cut titles after about 60 characters and descriptions after about 160.',
						'zinn-seo'
					) }
				</p>
			) }
		</div>
	);
}
