/**
 * A warning in the block editor when the address this post is about to be published at is
 * already the source of a redirect (SEOPress 10.3 parity): visitors and search engines would be
 * sent elsewhere and never see the page there.
 *
 * Asks GET /redirects/editor-check (allowed to whoever may edit the post) when the permalink
 * changes, at most once per address, and shows one dismissible editor notice.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

export const NOTICE_ID = 'zinn-seo-redirected';

/**
 * The warning for an editor-check answer, or null when the address is not redirected.
 * Exported for the tests.
 *
 * @param {Object|null} answer The answer of /redirects/editor-check.
 * @return {string|null} Warning text.
 */
export function redirectWarning( answer ) {
	if ( ! answer || ! answer.redirected ) {
		return null;
	}
	if ( ! answer.location ) {
		return sprintf(
			/* translators: %d: HTTP status code, for example 410. */
			__(
				'This address already has a redirect rule (%d), so visitors and search engines will not see this page here. Change the URL, or edit the rule under SEO > Redirects and 404.',
				'zinn-seo'
			),
			answer.code
		);
	}
	return sprintf(
		/* translators: 1: HTTP status code, for example 301, 2: the address visitors are sent to. */
		__(
			'This address is already redirected (%1$d) to %2$s, so visitors and search engines will not see this page here. Change the URL, or edit the redirect under SEO > Redirects and 404.',
			'zinn-seo'
		),
		answer.code,
		answer.location
	);
}

/**
 * The request for one post and address. Exported for the tests.
 *
 * @param {string} base REST base path.
 * @param {number} post Post ID.
 * @param {string} url  Address to check.
 * @return {string} apiFetch path.
 */
export function checkPath( base, post, url ) {
	return `${ base }/redirects/editor-check?post=${ encodeURIComponent(
		post
	) }&url=${ encodeURIComponent( url ) }`;
}

/**
 * Renders nothing; keeps the editor notice in step with the permalink.
 *
 * @param {Object} props      Props.
 * @param {string} props.base REST base path.
 * @return {null} Nothing.
 */
export default function RedirectedWarning( { base } ) {
	const { post, link } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			post: editor.getCurrentPostId(),
			link: editor.getPermalink ? editor.getPermalink() : '',
		};
	}, [] );
	const { createWarningNotice, removeNotice } = useDispatch( 'core/notices' );
	const asked = useRef( '' );

	useEffect( () => {
		if ( ! post || ! link || asked.current === link ) {
			return undefined;
		}
		asked.current = link;
		let live = true;
		const timer = setTimeout( () => {
			apiFetch( { path: checkPath( base, post, link ) } )
				.then( ( answer ) => {
					if ( ! live ) {
						return;
					}
					const text = redirectWarning( answer );
					if ( text ) {
						createWarningNotice( text, {
							id: NOTICE_ID,
							isDismissible: true,
						} );
					} else {
						removeNotice( NOTICE_ID );
					}
				} )
				.catch( () => {} );
		}, 600 );
		return () => {
			live = false;
			clearTimeout( timer );
		};
	}, [ base, post, link, createWarningNotice, removeNotice ] );

	return null;
}
