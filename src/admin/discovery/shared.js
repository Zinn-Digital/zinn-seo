import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	ExternalLink,
	Notice,
	ToggleControl,
} from '@wordpress/components';

/**
 * A number in the person's own way of writing numbers.
 *
 * @param {number} value Number.
 * @return {string} Text.
 */
export function num( value ) {
	return Number( value || 0 ).toLocaleString();
}

/**
 * A size in bytes for people (KB, MB).
 *
 * @param {number} bytes Bytes.
 * @return {string} Text.
 */
export function size( bytes ) {
	const b = Number( bytes || 0 );
	if ( b >= 1048576 ) {
		return sprintf(
			/* translators: %s: a number of megabytes. */
			__( '%s MB', 'zinn-seo' ),
			( b / 1048576 ).toFixed( 1 )
		);
	}
	return sprintf(
		/* translators: %s: a number of kilobytes. */
		__( '%s KB', 'zinn-seo' ),
		Math.max( 1, Math.round( b / 1024 ) )
	);
}

/**
 * A Unix time as a date and time in the person's locale; a dash for none.
 *
 * @param {number} ts Unix time.
 * @return {string} Text.
 */
export function when( ts ) {
	if ( ! ts ) {
		return '—';
	}
	return new Date( ts * 1000 ).toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} );
}

/**
 * Tiles of figures: label over value. Wraps from one column on a phone to several on a desktop.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.items `{ label, value }`.
 * @return {Element} Tiles.
 */
export function Stats( { items } ) {
	return (
		<dl className="zseo-stats">
			{ items.map( ( item ) => (
				<div key={ item.label } className="zseo-stats__item">
					<dt>{ item.label }</dt>
					<dd>{ item.value }</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * A list of checkboxes that edits an EXCLUSION list: ticked = included.
 *
 * @param {Object}        props
 * @param {string}        props.legend   Legend.
 * @param {Array<Object>} props.choices  `{ name, label, why }`.
 * @param {Array<string>} props.excluded Names left out.
 * @param {Function}      props.onChange Called with the new exclusion list.
 * @return {Element} The fieldset.
 */
export function IncludeList( { legend, choices, excluded, onChange } ) {
	const out = excluded || [];
	return (
		<fieldset className="zseo-includes">
			<legend className="zseo-legend">{ legend }</legend>
			{ choices.map( ( c ) => (
				<CheckboxControl
					__nextHasNoMarginBottom
					key={ c.name }
					label={ c.label }
					help={ whyText( c.why ) }
					disabled={ !! c.why }
					checked={ ! c.why && ! out.includes( c.name ) }
					onChange={ ( on ) =>
						onChange(
							on
								? out.filter( ( n ) => n !== c.name )
								: [ ...out, c.name ]
						)
					}
				/>
			) ) }
		</fieldset>
	);
}

/**
 * Why a content type cannot be listed.
 *
 * @param {string} why Code.
 * @return {string|undefined} Sentence.
 */
export function whyText( why ) {
	if ( 'noindex' === why ) {
		return __(
			'Set to "do not index" under Search appearance, so it is never offered to search engines.',
			'zinn-seo'
		);
	}
	if ( 'no_pages' === why ) {
		return __(
			'Attachment pages are switched off on this site (WordPress 6.4 and later).',
			'zinn-seo'
		);
	}
	return undefined;
}

/**
 * An address shown as a link that opens in a new tab.
 *
 * @param {Object} props
 * @param {string} props.href Address.
 * @return {Element} The link.
 */
export function Address( { href } ) {
	return (
		<ExternalLink className="zseo-address" href={ href }>
			{ href }
		</ExternalLink>
	);
}

/**
 * A tab whose module is switched off: say so and offer the switch, instead of a screen that
 * cannot load its data.
 *
 * @param {Object}   props
 * @param {string}   props.id       Module id.
 * @param {string}   props.label    What switching it on does.
 * @param {Object}   props.data     Boot data (`modules`: the saved state).
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Element}  props.children The tab, when the module is on.
 * @return {Element} The tab or the switch.
 */
export function ModuleGate( { id, label, data, value, onChange, children } ) {
	const saved = data.modules || {};
	if ( saved[ id ] ) {
		return children;
	}
	const mods = value.modules || {};
	return (
		<Notice status="info" isDismissible={ false }>
			<p>
				{ __( 'This part of Zinn® SEO is switched off.', 'zinn-seo' ) }
			</p>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ !! mods[ id ] }
				onChange={ ( on ) =>
					onChange( { modules: { ...mods, [ id ]: on } } )
				}
			/>
			{ mods[ id ] && (
				<p className="zseo-muted">
					{ __( 'Save, then reload the page.', 'zinn-seo' ) }
				</p>
			) }
		</Notice>
	);
}
