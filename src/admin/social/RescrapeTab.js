/**
 * Refresh shared links (zs-744): when a page's sharing title, text or image changes, Facebook is
 * asked to read it again so the next share shows the new card. Opt-in, with the site owner's own
 * Facebook app; LinkedIn has no way to ask, so each page links to LinkedIn's Post Inspector.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { Loaded, NewTab, Refusal, useLoad } from '../../tools/parts';
import { count } from '../../tools/pure';

/**
 * LinkedIn's Post Inspector for a page.
 *
 * @param {string} url Page.
 * @return {string} Address.
 */
export function linkedinInspector( url ) {
	return `https://www.linkedin.com/post-inspector/inspect/${ encodeURIComponent(
		url
	) }`;
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function RescrapeTab( { data } ) {
	const load = useLoad( '/social/rescrape' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ urls, setUrls ] = useState( '' );
	const [ said, setSaid ] = useState( '' );
	return (
		<Loaded load={ load }>
			{ ( state ) => {
				const v = draft || {
					on: state.on,
					app_id: state.app_id,
					secret: '',
				};
				const save = () => {
					setBusy( true );
					setError( null );
					seoFetch( '/social/rescrape', { method: 'POST', data: v } )
						.then( ( next ) => {
							load.setData( next );
							setDraft( null );
							setSaid( __( 'Saved.', 'zinn-seo' ) );
						} )
						.catch( setError )
						.finally( () => setBusy( false ) );
				};
				const now = () => {
					setBusy( true );
					setError( null );
					seoFetch( '/social/rescrape/now', {
						method: 'POST',
						data: {
							urls: urls
								.split( /\r?\n/ )
								.map( ( u ) => u.trim() )
								.filter( Boolean ),
						},
					} )
						.then( ( next ) => {
							load.setData( next );
							setSaid(
								sprintf(
									/* translators: %s: a number of pages. */
									__(
										'%s pages will be refreshed on Facebook in a moment.',
										'zinn-seo'
									),
									count( next.queued )
								)
							);
						} )
						.catch( setError )
						.finally( () => setBusy( false ) );
				};
				return (
					<div className="zseo-stack">
						<p>
							{ __(
								'Changed a page’s share title, text or image? Facebook keeps the old card until it reads the page again. Switch this on and Zinn® SEO asks Facebook to read it again for you, as soon as you save.',
								'zinn-seo'
							) }{ ' ' }
							<GuideLink data={ data } id="social-refresh" />
						</p>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Refresh shared links on Facebook when a page changes',
								'zinn-seo'
							) }
							checked={ !! v.on }
							onChange={ ( on ) => setDraft( { ...v, on } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Your Facebook app ID', 'zinn-seo' ) }
							value={ v.app_id }
							onChange={ ( appId ) =>
								setDraft( { ...v, app_id: appId } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="password"
							autoComplete="off"
							label={ __(
								'Your Facebook app secret',
								'zinn-seo'
							) }
							help={
								state.has_secret
									? __(
											'Saved. Leave empty to keep it.',
											'zinn-seo'
										)
									: __(
											'From your app’s Settings → Basic on developers.facebook.com. It stays on this site.',
											'zinn-seo'
										)
							}
							value={ v.secret }
							onChange={ ( secret ) =>
								setDraft( { ...v, secret } )
							}
						/>
						<p>
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy || ! draft }
								onClick={ save }
							>
								{ __( 'Save', 'zinn-seo' ) }
							</Button>
						</p>
						{ state.on && (
							<>
								<TextareaControl
									__nextHasNoMarginBottom
									label={ __(
										'Refresh these pages now',
										'zinn-seo'
									) }
									help={ __(
										'One page address per line, from this site.',
										'zinn-seo'
									) }
									value={ urls }
									onChange={ setUrls }
									rows={ 3 }
								/>
								<p>
									<Button
										variant="secondary"
										disabled={ busy || ! urls.trim() }
										onClick={ now }
									>
										{ __( 'Refresh now', 'zinn-seo' ) }
									</Button>
								</p>
							</>
						) }
						{ said && (
							<Notice
								status="success"
								onRemove={ () => setSaid( '' ) }
							>
								{ said }
							</Notice>
						) }
						<Refusal
							error={ error }
							onRemove={ () => setError( null ) }
						/>
						{ state.waiting > 0 && (
							<p className="zseo-muted">
								{ sprintf(
									/* translators: %s: a number of pages. */
									__(
										'%s pages are waiting to be refreshed.',
										'zinn-seo'
									),
									count( state.waiting )
								) }
							</p>
						) }
						{ ( state.log || [] ).length > 0 && (
							<ul className="zseo-log">
								{ state.log.map( ( r, i ) => (
									<li key={ `${ r.url }-${ i }` }>
										<bdi className="zseo-address">
											{ r.url }
										</bdi>
										<span
											className={
												r.ok
													? 'zseo-badge is-success'
													: 'zseo-badge is-error'
											}
										>
											{ r.ok
												? __( 'Refreshed', 'zinn-seo' )
												: __(
														'Not refreshed',
														'zinn-seo'
													) }
										</span>
										<span className="zseo-muted">
											{ r.note }
										</span>
										<NewTab
											href={ linkedinInspector( r.url ) }
										>
											{ __(
												'Check on LinkedIn',
												'zinn-seo'
											) }
										</NewTab>
									</li>
								) ) }
							</ul>
						) }
						<p className="zseo-muted">
							{ __(
								'LinkedIn does not let other tools refresh its cards. Use “Check on LinkedIn” beside a page: LinkedIn reads it again when you open its Post Inspector.',
								'zinn-seo'
							) }
						</p>
					</div>
				);
			} }
		</Loaded>
	);
}
