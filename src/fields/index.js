/**
 * The classic editor's meta box and the term screens: the image picker and character counts.
 * Plain DOM, no framework (these screens load nothing else from this plugin).
 */
import { __, sprintf } from '@wordpress/i18n';

import { fillOnFocus, revertOnBlur } from './fill';
import './fields.scss';

/**
 * Wire one image picker.
 *
 * @param {HTMLElement} box The `[data-zseo-image]` element.
 */
function picker( box ) {
	const input = box.querySelector( 'input[type="hidden"]' );
	const pick = box.querySelector( '[data-zseo-pick]' );
	if ( ! input || ! pick ) {
		return;
	}
	const img = box.querySelector( 'img' );
	const clear = box.querySelector( '[data-zseo-clear]' );
	pick.addEventListener( 'click', () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Choose an image', 'zinn-seo' ),
			library: { type: 'image' },
			button: { text: __( 'Use this image', 'zinn-seo' ) },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const chosen = frame.state().get( 'selection' ).first().toJSON();
			input.value = String( chosen.id );
			if ( img ) {
				img.src =
					( chosen.sizes &&
						chosen.sizes.medium &&
						chosen.sizes.medium.url ) ||
					chosen.url;
				img.hidden = false;
			}
			if ( clear ) {
				clear.hidden = false;
			}
		} );
		frame.open();
	} );
	if ( clear ) {
		clear.addEventListener( 'click', () => {
			input.value = '';
			if ( img ) {
				img.hidden = true;
				img.removeAttribute( 'src' );
			}
			clear.hidden = true;
		} );
	}
}

/**
 * Wire one counted field.
 *
 * @param {HTMLInputElement|HTMLTextAreaElement} field Field with `data-zseo-count`.
 */
function counter( field ) {
	const limit = parseInt( field.getAttribute( 'data-zseo-count' ), 10 );
	if ( ! limit ) {
		return;
	}
	const out = document.createElement( 'span' );
	out.className = 'zseo-count';
	out.setAttribute( 'aria-live', 'polite' );
	field.after( out );
	const update = () => {
		const length = ( field.value || field.placeholder || '' ).length;
		out.textContent = sprintf(
			/* translators: 1: characters used, 2: characters search engines usually show. */
			__( '%1$d of about %2$d characters', 'zinn-seo' ),
			length,
			limit
		);
		out.classList.toggle( 'is-over', length > limit );
	};
	field.addEventListener( 'input', update );
	update();
}

/**
 * Wire click-to-edit of the suggested text on one field (zs-757).
 *
 * @param {HTMLInputElement|HTMLTextAreaElement} field Field with `data-zseo-fill`.
 */
function fill( field ) {
	let filled = null;
	field.addEventListener( 'focus', () => {
		const text = fillOnFocus( field.value, field.placeholder, true );
		if ( null !== text ) {
			field.value = text;
			filled = text;
			field.dispatchEvent( new Event( 'input' ) );
		}
	} );
	field.addEventListener( 'blur', () => {
		if ( '' === revertOnBlur( field.value, filled ) ) {
			field.value = '';
			field.dispatchEvent( new Event( 'input' ) );
		}
		filled = null;
	} );
}

document.querySelectorAll( '[data-zseo-image]' ).forEach( picker );
document.querySelectorAll( '[data-zseo-fill]' ).forEach( fill );
document.querySelectorAll( '[data-zseo-count]' ).forEach( counter );
