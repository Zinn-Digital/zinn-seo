/**
 * Inside Elementor's editor: Page settings → SEO shows a live search-result preview, the checks
 * of the title and description, and the page's structured data with its status in Google (zs-313).
 *
 * Plain DOM (Elementor's panel is not React): the preview box is a RAW_HTML control the PHP side
 * prints; this fills it and refills it as the fields change.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { checks } from './checks';
import { mountElementorAnalysis } from './analysis';
import { mountCopywriter } from './copywriter';
import './elementor.scss';

const state = { resolved: null, schema: null, postId: 0 };

/**
 * The current values of the SEO controls.
 *
 * @return {Object} Values.
 */
function values() {
	const model = window.elementor?.settings?.page?.model;
	const get = ( k ) => ( model ? model.get( k ) || '' : '' );
	return {
		title: get( 'zseo_title' ),
		desc: get( 'zseo_desc' ),
		noindex: get( 'zseo_noindex' ) === 'yes',
	};
}

/**
 * Make an element.
 *
 * @param {string} tag       Tag.
 * @param {string} className Class.
 * @param {string} text      Text.
 * @return {HTMLElement} Element.
 */
function el( tag, className, text ) {
	const node = document.createElement( tag );
	if ( className ) {
		node.className = className;
	}
	if ( text !== undefined ) {
		node.textContent = text;
	}
	return node;
}

/**
 * Draw the preview into every preview box on the panel.
 *
 * @return {void}
 */
function draw() {
	const boxes = document.querySelectorAll( '.zseo-el-preview' );
	if ( ! boxes.length ) {
		return;
	}
	const v = values();
	const auto = state.resolved || {};
	const title = v.title || auto.title || '';
	const desc = v.desc || auto.desc || '';
	const url = auto.canonical || auto.address || '';
	boxes.forEach( ( box ) => {
		box.textContent = '';
		const snippet = el( 'div', 'zseo-el-snippet' );
		snippet.append(
			el( 'div', 'zseo-el-snippet__url', url ),
			el( 'div', 'zseo-el-snippet__title', title ),
			el( 'div', 'zseo-el-snippet__desc', desc )
		);
		box.append( snippet );
		const list = el( 'ul', 'zseo-el-checks' );
		checks( { title, desc, noindex: v.noindex } ).forEach( ( c ) => {
			const li = el( 'li', `is-${ c.level }`, c.text );
			list.append( li );
		} );
		box.append( list );
		if ( state.schema ) {
			box.append(
				el(
					'p',
					'zseo-el-heading',
					__( 'Structured data', 'zinn-seo' )
				)
			);
			const types = el( 'ul', 'zseo-el-schema' );
			state.schema.nodes.forEach( ( n ) => {
				let status = '';
				if ( n.errors.length ) {
					status = sprintf(
						/* translators: %d: a number of problems. */
						__( '%d to fix', 'zinn-seo' ),
						n.errors.length
					);
				} else if ( n.status === 'retired' ) {
					status = __( 'not shown by Google', 'zinn-seo' );
				} else if ( n.feature ) {
					status = __( 'ready', 'zinn-seo' );
				}
				let level = '';
				if ( n.errors.length ) {
					level = 'is-bad';
				} else if ( n.status === 'retired' || ! n.feature ) {
					level = 'is-info';
				}
				types.append(
					el(
						'li',
						level,
						status ? `${ n.type } — ${ status }` : n.type
					)
				);
			} );
			box.append( types );
		}
	} );
}

/**
 * Load the page's resolved values and structured data.
 *
 * @return {void}
 */
function load() {
	const id = window.elementor?.config?.document?.id || 0;
	if ( ! id ) {
		return;
	}
	state.postId = id;
	apiFetch( { path: `/zinn-seo/v1/resolved/post/${ id }` } )
		.then( ( r ) => {
			state.resolved = r || {};
			draw();
		} )
		.catch( () => {} );
	apiFetch( {
		path: '/zinn-seo/v1/schema/validate',
		method: 'POST',
		data: { post: id },
	} )
		.then( ( r ) => {
			state.schema = r;
			draw();
		} )
		.catch( () => {} );
}

/**
 * Start once Elementor's editor is ready.
 *
 * @return {void}
 */
function start() {
	const model = window.elementor?.settings?.page?.model;
	if ( model ) {
		model.on( 'change', draw );
	}
	// The panel re-renders its controls when it opens: draw into each new preview box.
	new window.MutationObserver( () => {
		if ( document.querySelector( '.zseo-el-preview:empty' ) ) {
			draw();
		}
		if (
			document.querySelector( '.zseo-el-analysis:not([data-mounted])' )
		) {
			mountElementorAnalysis();
		}
		if (
			document.querySelector( '.zseo-el-copywriter:not([data-mounted])' )
		) {
			mountCopywriter();
		}
	} ).observe( document.body, { childList: true, subtree: true } );
	load();
	if ( window.elementor?.channels?.editor ) {
		window.elementor.channels.editor.on( 'saved', load );
	}
}

if ( window.elementor && window.elementor.config ) {
	start();
} else {
	window.addEventListener( 'elementor/init', start );
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor:init', start );
	}
}
