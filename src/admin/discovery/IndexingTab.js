import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { Address, Stats, num, when } from './shared';

/**
 * Why nothing is sent, in words.
 *
 * @param {string} code Refusal.
 * @return {string} Sentence.
 */
export function refusalText( code ) {
	switch ( code ) {
		case 'staging':
			return __(
				'This looks like a staging copy, so nothing is sent to search engines from it.',
				'zinn-seo'
			);
		case 'discouraged':
			return __(
				'The site asks search engines not to index it (Settings, Reading), so nothing is sent.',
				'zinn-seo'
			);
		case 'unreachable':
			return __(
				'Search engines cannot reach this address (a local or private host), so nothing is sent.',
				'zinn-seo'
			);
	}
	return '';
}

/**
 * An engine's answer in words.
 *
 * @param {Object} row Log row.
 * @return {string} Sentence.
 */
export function answerText( row ) {
	if ( 0 === row.code && row.message ) {
		return refusalText( row.message ) || row.message;
	}
	if ( row.code >= 200 && row.code < 300 ) {
		return __( 'Accepted', 'zinn-seo' );
	}
	if ( 429 === row.code ) {
		return __( 'Too many requests: will retry', 'zinn-seo' );
	}
	if ( 403 === row.code ) {
		return __(
			'Key not accepted (the key file could not be read)',
			'zinn-seo'
		);
	}
	if ( 422 === row.code ) {
		return __( 'Addresses not on this site', 'zinn-seo' );
	}
	/* translators: %d: an HTTP status code. */
	return sprintf( __( 'Answer %d', 'zinn-seo' ), row.code );
}

/**
 * Indexing: IndexNow, request indexing, and submitting the sitemap.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.saved    Saved settings.
 * @return {Element} The tab.
 */
export default function IndexingTab( { value, onChange, saved } ) {
	const [ state, setState ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ urls, setUrls ] = useState( '' );
	const [ inspect, setInspect ] = useState( '' );
	const [ links, setLinks ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		seoFetch( '/indexing' )
			.then( setState )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);

	useEffect( () => {
		load();
	}, [ saved ] );

	if ( ! state ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const mods = value.modules || {};
	const enabled = 'indexnow' in mods ? !! mods.indexnow : false;

	const submit = () => {
		setBusy( true );
		seoFetch( '/indexing/submit', {
			method: 'POST',
			data: { urls: urls.split( /\s+/ ).filter( Boolean ) },
		} )
			.then( ( r ) => {
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %s: a number of addresses. */
						_n(
							'%s address queued; it is sent within seconds.',
							'%s addresses queued; they are sent within seconds.',
							r.queued,
							'zinn-seo'
						),
						num( r.queued )
					),
				} );
				setUrls( '' );
				load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const lookUp = () => {
		setLinks( null );
		seoFetch( `/indexing/inspect?url=${ encodeURIComponent( inspect ) }` )
			.then( setLinks )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	};

	return (
		<div className="zseo-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ state.refusal && (
				<Notice status="warning" isDismissible={ false }>
					{ refusalText( state.refusal ) }
				</Notice>
			) }
			<PanelBody title={ __( 'IndexNow', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<p>
						{ __(
							'Tells Bing, Yandex, Seznam, Naver and the other IndexNow search engines about a new or changed page the moment it changes, instead of waiting for them to crawl. Google does not take part.',
							'zinn-seo'
						) }
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Send new and changed pages to IndexNow',
							'zinn-seo'
						) }
						help={ __(
							'Sends the public address of each page you publish, change or remove to the search engine chosen below. Nothing else is sent.',
							'zinn-seo'
						) }
						checked={ enabled }
						onChange={ ( v ) =>
							onChange( { modules: { ...mods, indexnow: v } } )
						}
					/>
					{ enabled && (
						<>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Send to', 'zinn-seo' ) }
								help={ __(
									'Every IndexNow engine shares what it receives with the others.',
									'zinn-seo'
								) }
								value={ value.indexnow_engine }
								options={ [
									{
										value: 'indexnow',
										label: 'IndexNow (api.indexnow.org)',
									},
									{ value: 'bing', label: 'Bing' },
									{ value: 'yandex', label: 'Yandex' },
									{ value: 'seznam', label: 'Seznam' },
									{ value: 'naver', label: 'Naver' },
								] }
								onChange={ ( v ) =>
									onChange( { indexnow_engine: v } )
								}
							/>
							<Stats
								items={ [
									{
										label: __(
											'Waiting to be sent',
											'zinn-seo'
										),
										value: num( state.queue ),
									},
									{
										label: __( 'Next try', 'zinn-seo' ),
										value: state.retry_at
											? when( state.retry_at )
											: '—',
									},
								] }
							/>
							{ state.key_url && (
								<p>
									{ __( 'Key file:', 'zinn-seo' ) }{ ' ' }
									<Address href={ state.key_url } />
								</p>
							) }
						</>
					) }
				</div>
			</PanelBody>
			{ enabled && saved.modules && saved.modules.indexnow && (
				<PanelBody
					title={ __( 'Send addresses now', 'zinn-seo' ) }
					initialOpen={ false }
				>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Addresses on this site, one per line',
							'zinn-seo'
						) }
						rows={ 4 }
						dir="ltr"
						value={ urls }
						onChange={ setUrls }
					/>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={
								busy || ! urls.trim() || !! state.refusal
							}
							onClick={ submit }
						>
							{ __( 'Send', 'zinn-seo' ) }
						</Button>
					</div>
				</PanelBody>
			) }
			{ state.log && state.log.length > 0 && (
				<PanelBody
					title={ __( 'Recent answers', 'zinn-seo' ) }
					initialOpen={ false }
				>
					<ul className="zseo-log">
						{ state.log.map( ( row, i ) => (
							<li key={ i }>
								<span className="zseo-muted">
									{ when( row.at ) }
								</span>
								<span>
									{ sprintf(
										/* translators: %s: a number of addresses. */
										_n(
											'%s address',
											'%s addresses',
											row.count,
											'zinn-seo'
										),
										num( row.count )
									) }
								</span>
								<strong
									className={
										row.code >= 200 && row.code < 300
											? 'zseo-ok'
											: 'zseo-warn'
									}
								>
									{ answerText( row ) }
								</strong>
							</li>
						) ) }
					</ul>
				</PanelBody>
			) }
			<PanelBody
				title={ __(
					'Ask Google or Bing to look at a page',
					'zinn-seo'
				) }
			>
				<div className="zseo-stack">
					<p className="zseo-muted">
						{ __(
							'Opens the page in Google Search Console\'s URL inspection, where "Request indexing" is, or in Bing Webmaster Tools. The same links are under each post in your lists of posts.',
							'zinn-seo'
						) }
					</p>
					<div className="zseo-row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="url"
							dir="ltr"
							label={ __( 'Page address', 'zinn-seo' ) }
							value={ inspect }
							onChange={ setInspect }
						/>
						<div className="zseo-actions zseo-actions--start">
							<Button
								variant="secondary"
								disabled={ ! inspect.trim() }
								onClick={ lookUp }
							>
								{ __( 'Get the links', 'zinn-seo' ) }
							</Button>
						</div>
					</div>
					{ links && (
						<p className="zseo-actions zseo-actions--start">
							<ExternalLink href={ links.google }>
								{ __( 'Inspect in Google', 'zinn-seo' ) }
							</ExternalLink>
							<ExternalLink href={ links.bing }>
								{ __( 'Inspect in Bing', 'zinn-seo' ) }
							</ExternalLink>
						</p>
					) }
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						dir="ltr"
						label={ __(
							'Your Search Console property',
							'zinn-seo'
						) }
						help={ __(
							"As Search Console names it: https://example.com/ for an address property, sc-domain:example.com for a domain property. Empty: this site's address.",
							'zinn-seo'
						) }
						value={ value.gsc_property || '' }
						onChange={ ( v ) =>
							onChange( { gsc_property: v.trim() } )
						}
					/>
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'Submit your sitemap', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<p>
					{ __(
						'Add the sitemap address once in each tool. Both read it again by themselves after that, and robots.txt names it for every other search engine.',
						'zinn-seo'
					) }
				</p>
				<p className="zseo-actions zseo-actions--start">
					<ExternalLink href={ state.webmaster.google_sitemaps }>
						{ __( 'Google Search Console sitemaps', 'zinn-seo' ) }
					</ExternalLink>
					<ExternalLink href={ state.webmaster.bing_sitemaps }>
						{ __( 'Bing Webmaster Tools sitemaps', 'zinn-seo' ) }
					</ExternalLink>
				</p>
			</PanelBody>
		</div>
	);
}
