/* global window, document, CustomEvent */
/**
 * Cookie consent banner (Zinn SEO zs-783): remembers the visitor's choice and sends it to Google
 * tags as a Consent Mode v2 update. Inlined by includes/modules/consent/class-consent.php only when
 * the module is switched on; the head already holds the all-denied default.
 */
( function () {
	const doc = document;
	const box = doc.getElementById( 'site-consent' );
	if ( ! box ) {
		return;
	}
	let cfg = {};
	try {
		cfg = JSON.parse( box.getAttribute( 'data-config' ) || '{}' );
	} catch {
		cfg = {};
	}
	const name = cfg.cookie || 'site_consent';
	const days = cfg.days || 180;
	const opener = doc.querySelector( '.site-consent-open' );
	const field = ( n ) => box.querySelector( 'input[name="' + n + '"]' );

	window.dataLayer = window.dataLayer || [];
	const gtag =
		typeof window.gtag === 'function'
			? window.gtag
			: function () {
					window.dataLayer.push( arguments );
				};

	const read = () => {
		const m = doc.cookie.match(
			new RegExp( '(?:^|;\\s*)' + name + '=(a[01])\\.(d[01])(?:;|$)' )
		);
		return m ? { a: m[ 1 ] === 'a1', d: cfg.ads && m[ 2 ] === 'd1' } : null;
	};

	const show = ( focus ) => {
		const c = read() || { a: false, d: false };
		if ( field( 'analytics' ) ) {
			field( 'analytics' ).checked = c.a;
		}
		if ( field( 'ads' ) ) {
			field( 'ads' ).checked = c.d;
		}
		box.hidden = false;
		if ( opener ) {
			opener.hidden = true;
		}
		const first = box.querySelector( 'button' );
		if ( focus && first ) {
			first.focus();
		}
	};

	const hide = () => {
		box.hidden = true;
		if ( opener && cfg.reopen ) {
			opener.hidden = false;
		}
	};

	const save = ( c ) => {
		const v = 'a' + ( c.a ? 1 : 0 ) + '.d' + ( c.d ? 1 : 0 );
		const secure = 'https:' === window.location.protocol ? ';Secure' : '';
		doc.cookie =
			name +
			'=' +
			v +
			';path=/;max-age=' +
			days * 86400 +
			';SameSite=Lax' +
			secure;
		const ad = c.d ? 'granted' : 'denied';
		gtag( 'consent', 'update', {
			analytics_storage: c.a ? 'granted' : 'denied',
			ad_storage: ad,
			ad_user_data: ad,
			ad_personalization: ad,
		} );
		window.dataLayer.push( {
			event: 'consent_update',
			consent_analytics: c.a,
			consent_ads: c.d,
		} );
		if ( typeof window.wp_set_consent === 'function' ) {
			window.wp_set_consent( 'statistics', c.a ? 'allow' : 'deny' );
			window.wp_set_consent( 'marketing', c.d ? 'allow' : 'deny' );
		}
		doc.dispatchEvent(
			new CustomEvent( 'site-consent', {
				detail: { analytics: c.a, ads: c.d },
			} )
		);
		hide();
	};

	box.addEventListener( 'click', ( ev ) => {
		const btn = ev.target.closest( 'button[data-act]' );
		if ( ! btn ) {
			return;
		}
		const act = btn.getAttribute( 'data-act' );
		if ( 'all' === act ) {
			save( { a: true, d: !! cfg.ads } );
		} else if ( 'none' === act ) {
			save( { a: false, d: false } );
		} else {
			save( {
				a: !! ( field( 'analytics' ) && field( 'analytics' ).checked ),
				d: !! ( cfg.ads && field( 'ads' ) && field( 'ads' ).checked ),
			} );
		}
	} );

	doc.addEventListener( 'click', ( ev ) => {
		const t = ev.target.closest(
			'[data-consent-open],a[href="#site-consent"]'
		);
		if ( t && ! box.contains( t ) ) {
			ev.preventDefault();
			show( true );
		}
	} );

	if ( read() ) {
		hide();
	} else {
		show( false );
	}
} )();
