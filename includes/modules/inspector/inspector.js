/* Zinn SEO inspector: reads the live page's head and outline for an editor (zs-430). Loaded only for logged-in people who may see SEO data. */
( function () {
	'use strict';
	const data = window.zinnSeoInspector || {};
	const s = data.strings || {};
	let dialog = null;

	function el( tag, cls, text ) {
		const n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = String( text );
		}
		return n;
	}
	function fmt( str, n ) {
		return String( str || '' ).replace( '%d', String( n ) );
	}
	function metas( sel ) {
		return Array.prototype.slice.call(
			document.head.querySelectorAll( sel )
		);
	}
	function row( list, label, value, extra ) {
		const dt = el( 'dt', '', label );
		const dd = el( 'dd', extra || '', value );
		list.appendChild( dt );
		list.appendChild( dd );
		return dd;
	}
	function warn( parent, text ) {
		parent.appendChild( el( 'p', 'zseo-in__warn', text ) );
	}
	function types( node, out ) {
		if ( Array.isArray( node ) ) {
			node.forEach( function ( n ) {
				types( n, out );
			} );
			return;
		}
		if ( node && typeof node === 'object' ) {
			const t = node[ '@type' ];
			( Array.isArray( t ) ? t : t ? [ t ] : [] ).forEach(
				function ( x ) {
					out[ x ] = ( out[ x ] || 0 ) + 1;
				}
			);
			Object.keys( node ).forEach( function ( k ) {
				if ( k !== '@type' ) {
					types( node[ k ], out );
				}
			} );
		}
	}

	function build() {
		const box = el( 'div', 'zseo-in__body' );
		const text = [];
		const head = el( 'section', 'zseo-in__sec' );
		head.appendChild( el( 'h3', '', s.head ) );
		const dl = el( 'dl', 'zseo-in__dl' );
		head.appendChild( dl );
		const titles = document.querySelectorAll( 'title' );
		const title = document.title || '';
		row( dl, s.pageTitle, title || s.none ).appendChild(
			el( 'small', '', ' · ' + fmt( s.chars, title.length ) )
		);
		text.push( s.pageTitle + ': ' + title );
		if ( titles.length > 1 ) {
			warn( head, fmt( s.double, titles.length ) );
		}
		const d = metas( 'meta[name="description"]' );
		const desc = d.length ? d[ 0 ].getAttribute( 'content' ) || '' : '';
		row( dl, s.desc, desc || s.none ).appendChild(
			el( 'small', '', desc ? ' · ' + fmt( s.chars, desc.length ) : '' )
		);
		text.push( s.desc + ': ' + desc );
		if ( d.length > 1 ) {
			warn( head, fmt( s.double, d.length ) );
		}
		const c = metas( 'link[rel="canonical"]' );
		const canon = c.length ? c[ 0 ].getAttribute( 'href' ) || '' : '';
		row( dl, s.canonical, canon || s.none, 'zseo-in__ltr' );
		text.push( s.canonical + ': ' + canon );
		if ( c.length > 1 ) {
			warn( head, fmt( s.double, c.length ) );
		}
		const r = metas( 'meta[name="robots"]' );
		const robots = r
			.map( function ( m ) {
				return m.getAttribute( 'content' ) || '';
			} )
			.join( ' | ' );
		row( dl, s.robots, robots || s.indexable );
		text.push( s.robots + ': ' + ( robots || s.indexable ) );
		if ( r.length > 1 ) {
			warn( head, fmt( s.double, r.length ) );
		}
		box.appendChild( head );

		const sch = el( 'section', 'zseo-in__sec' );
		sch.appendChild( el( 'h3', '', s.schema ) );
		const scripts = document.querySelectorAll(
			'script[type="application/ld+json"]'
		);
		const found = {};
		let broken = 0;
		Array.prototype.forEach.call( scripts, function ( sc ) {
			try {
				types( JSON.parse( sc.textContent || '' ), found );
			} catch {
				broken++;
			}
		} );
		const tl = el( 'ul', 'zseo-in__tags' );
		Object.keys( found ).forEach( function ( k ) {
			tl.appendChild(
				el( 'li', '', found[ k ] > 1 ? k + ' ×' + found[ k ] : k )
			);
		} );
		sch.appendChild(
			Object.keys( found ).length ? tl : el( 'p', '', s.none )
		);
		text.push( s.schema + ': ' + Object.keys( found ).join( ', ' ) );
		if ( scripts.length > 1 ) {
			warn( sch, fmt( s.double, scripts.length ) );
		}
		if ( broken ) {
			warn( sch, s.schemaBad );
		}
		if ( data.check ) {
			const a = el( 'a', 'zseo-in__link', s.check );
			a.href = data.check;
			sch.appendChild( a );
		}
		box.appendChild( sch );

		const hl = metas( 'link[rel="alternate"][hreflang]' );
		if ( hl.length ) {
			const hs = el( 'section', 'zseo-in__sec' );
			hs.appendChild( el( 'h3', '', s.hreflang ) );
			const hd = el( 'dl', 'zseo-in__dl' );
			hl.forEach( function ( l ) {
				row(
					hd,
					l.getAttribute( 'hreflang' ),
					l.getAttribute( 'href' ),
					'zseo-in__ltr'
				);
			} );
			hs.appendChild( hd );
			box.appendChild( hs );
		}

		const og = metas( 'meta[property^="og:"], meta[name^="twitter:"]' );
		if ( og.length ) {
			const os = el( 'section', 'zseo-in__sec' );
			os.appendChild( el( 'h3', '', s.social ) );
			const od = el( 'dl', 'zseo-in__dl' );
			og.slice( 0, 12 ).forEach( function ( m ) {
				row(
					od,
					m.getAttribute( 'property' ) || m.getAttribute( 'name' ),
					m.getAttribute( 'content' )
				);
			} );
			os.appendChild( od );
			box.appendChild( os );
		}

		const outline = el( 'section', 'zseo-in__sec' );
		outline.appendChild( el( 'h3', '', s.headings ) );
		const ol = el( 'ol', 'zseo-in__outline' );
		let prev = 0;
		Array.prototype.forEach.call(
			document.querySelectorAll( 'h1,h2,h3,h4,h5,h6' ),
			function ( h ) {
				if ( h.closest( '#wpadminbar, .zseo-in' ) ) {
					return;
				}
				const level = Number( h.tagName.slice( 1 ) );
				const li = el(
					'li',
					'zseo-in__h zseo-in__h' + level,
					'H' + level + ' ' + ( h.textContent || '' ).trim()
				);
				if ( prev && level > prev + 1 ) {
					li.appendChild(
						el( 'span', 'zseo-in__skip', ' — ' + s.skip )
					);
					li.className += ' is-skip';
				}
				prev = level;
				ol.appendChild( li );
			}
		);
		outline.appendChild( ol );
		box.appendChild( outline );

		const noalt = Array.prototype.filter.call(
			document.querySelectorAll(
				'main img, article img, .entry-content img, body img'
			),
			function ( i ) {
				return (
					! i.hasAttribute( 'alt' ) && ! i.closest( '#wpadminbar' )
				);
			}
		);
		if ( noalt.length ) {
			const is = el( 'section', 'zseo-in__sec' );
			is.appendChild(
				el( 'h3', '', s.images + ' (' + noalt.length + ')' )
			);
			const il = el( 'ul', 'zseo-in__ltr zseo-in__list' );
			noalt.slice( 0, 10 ).forEach( function ( i ) {
				il.appendChild(
					el(
						'li',
						'',
						( i.currentSrc || i.src || '' ).split( '/' ).pop()
					)
				);
			} );
			is.appendChild( il );
			box.appendChild( is );
		}

		if ( data.page ) {
			const sc = el( 'section', 'zseo-in__sec' );
			sc.appendChild( el( 'h3', '', s.scores ) );
			const sd = el( 'dl', 'zseo-in__dl' );
			[
				[ s.seo, data.page.score ],
				[ s.readability, data.page.readability ],
			].forEach( function ( p ) {
				const v = p[ 1 ];
				const rating =
					v === null ? '' : v >= 75 ? 'good' : v >= 50 ? 'ok' : 'bad';
				row(
					sd,
					p[ 0 ],
					v === null ? s.notScored : String( v ),
					rating ? 'zseo-in__score is-' + rating : ''
				);
			} );
			if ( data.page.focus ) {
				row( sd, s.focus, data.page.focus.split( '|' )[ 0 ] );
			}
			sc.appendChild( sd );
			if ( data.page.edit ) {
				const e = el( 'a', 'zseo-in__link', s.edit );
				e.href = data.page.edit;
				sc.appendChild( e );
			}
			box.appendChild( sc );
		}

		const copy = el( 'button', 'zseo-in__btn', s.copy );
		copy.type = 'button';
		copy.addEventListener( 'click', function () {
			const done = function () {
				copy.textContent = s.copied;
			};
			if ( navigator.clipboard ) {
				navigator.clipboard
					.writeText( text.join( '\n' ) )
					.then( done, function () {} );
			}
		} );
		box.appendChild( copy );
		return box;
	}

	function open( ev ) {
		if ( ev ) {
			ev.preventDefault();
		}
		if ( ! dialog ) {
			dialog = el( 'dialog', 'zseo-in' );
			dialog.setAttribute( 'aria-labelledby', 'zseo-in-title' );
			dialog.setAttribute( 'dir', data.rtl ? 'rtl' : 'ltr' );
			const bar = el( 'div', 'zseo-in__bar' );
			const h = el( 'h2', '', s.title );
			h.id = 'zseo-in-title';
			const x = el( 'button', 'zseo-in__close', '×' );
			x.type = 'button';
			x.setAttribute( 'aria-label', s.close );
			x.addEventListener( 'click', function () {
				dialog.close();
			} );
			bar.appendChild( h );
			bar.appendChild( x );
			dialog.appendChild( bar );
			document.body.appendChild( dialog );
		}
		const old = dialog.querySelector( '.zseo-in__body' );
		if ( old ) {
			old.remove();
		}
		dialog.appendChild( build() );
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
	}

	function init() {
		const node = document.querySelector(
			'#wp-admin-bar-zinn-seo-inspector > a'
		);
		if ( node ) {
			node.setAttribute( 'role', 'button' );
			node.setAttribute( 'aria-haspopup', 'dialog' );
			node.addEventListener( 'click', open );
		}
		if ( window.location.hash === '#zinn-seo-inspector' ) {
			open();
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
