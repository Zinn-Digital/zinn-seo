import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import ProTeaser from '../ProTeaser';
import { Address, IncludeList, Stats, num, size, when } from './shared';

/**
 * A health problem in words.
 *
 * @param {Object} e `{ code, file, detail }`.
 * @return {string} Sentence.
 */
export function problemText( e ) {
	switch ( e.code ) {
		case 'too_many':
			return sprintf(
				/* translators: 1: a sitemap file name, 2: a number of addresses. */
				__(
					'%1$s lists %2$s addresses; search engines read at most 50,000 per file. Lower "Addresses per file".',
					'zinn-seo'
				),
				e.file,
				num( e.detail )
			);
		case 'too_big':
			return sprintf(
				/* translators: %s: a sitemap file name. */
				__(
					'%s is larger than the 50 MB search engines read. Lower "Addresses per file" or the images per page.',
					'zinn-seo'
				),
				e.file
			);
		case 'foreign_host':
			return sprintf(
				/* translators: %s: an address. */
				__(
					"This address is on another host and may be a staging copy leaking into the live sitemap: %s. Check the page's canonical address.",
					'zinn-seo'
				),
				e.detail
			);
		case 'runaway': {
			const [ times, , , minutes ] = String( e.detail ).split( '|' );
			return (
				sprintf(
					/* translators: 1: a sitemap file name, 2: a number of times, 3: a number of minutes. */
					_n(
						'The sitemap file %1$s was rebuilt %2$s times in %3$s minute.',
						'The sitemap file %1$s was rebuilt %2$s times in %3$s minutes.',
						parseInt( minutes, 10 ) || 0,
						'zinn-seo'
					),
					e.file,
					num( times ),
					num( minutes )
				) +
				' ' +
				__(
					'The refresh is resting for a while and then carries on. If nothing is editing that many pages (an import, a bulk edit), another plugin is changing pages every time the sitemap changes.',
					'zinn-seo'
				)
			);
		}
	}
	return e.code;
}

/**
 * The XML sitemaps: health, rebuild, and what they list.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {boolean}  props.isPro    A paid plan is active.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function SitemapsTab( { value, onChange, isPro, data } ) {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		seoFetch( '/sitemaps' )
			.then( setStatus )
			.catch( ( e ) => setError( errorText( e ) ) );

	useEffect( () => {
		load();
	}, [] );

	useEffect( () => {
		if ( ! status || ! status.job || 'pending' !== status.job.status ) {
			return undefined;
		}
		// A job that rests (its own guard) is not worth asking about every three seconds.
		const timer = window.setTimeout(
			load,
			status.job.wait > 0 ? 60000 : 3000
		);
		return () => window.clearTimeout( timer );
	}, [ status ] );

	const rebuild = () => {
		setBusy( true );
		setError( '' );
		seoFetch( '/sitemaps/rebuild', { method: 'POST' } )
			.then( setStatus )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! status ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const building = status.job && 'pending' === status.job.status;
	const progress = status.job ? status.job.progress || {} : {};

	return (
		<div className="zseo-stack">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ ! status.active && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Another SEO plugin is active, so it keeps answering for the sitemap. Bring its data into Zinn® SEO and switch it off, or choose to run both under Search appearance.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<Card>
				<CardBody className="zseo-stack">
					<div className="zseo-row zseo-row--top">
						<div>
							<p className="zseo-legend">
								{ __( 'Your sitemap', 'zinn-seo' ) }
							</p>
							<Address href={ status.url } />
						</div>
						<div className="zseo-actions">
							<Button
								variant="secondary"
								isBusy={ busy }
								disabled={ busy || building }
								onClick={ rebuild }
							>
								{ __( 'Rebuild now', 'zinn-seo' ) }
							</Button>
						</div>
					</div>
					<p className="zseo-muted" aria-live="polite">
						{ building &&
							'files' === progress.unit &&
							sprintf(
								/* translators: 1: sitemap files rebuilt so far, 2: sitemap files to rebuild. */
								__(
									'Refreshing: %1$s of %2$s files rebuilt.',
									'zinn-seo'
								),
								num( progress.done ),
								num( progress.total )
							) }
						{ building &&
							'files' !== progress.unit &&
							sprintf(
								/* translators: 1: pages read so far, 2: pages to read. */
								__(
									'Building: %1$s of about %2$s pages read.',
									'zinn-seo'
								),
								num( progress.done ),
								null === progress.total ||
									undefined === progress.total
									? '…'
									: num( progress.total )
							) }
						{ ! building &&
							status.complete &&
							__(
								'Up to date. Files are rebuilt in the background, only where a page changed.',
								'zinn-seo'
							) }
						{ ! building &&
							! status.complete &&
							__( 'Not built yet.', 'zinn-seo' ) }
					</p>
					<Stats
						items={ [
							{
								label: __( 'Addresses', 'zinn-seo' ),
								value: num( status.urls ),
							},
							{
								label: __( 'Files', 'zinn-seo' ),
								value: num( status.files ),
							},
							{
								label: __( 'Last full build', 'zinn-seo' ),
								value: when( status.built ),
							},
							{
								label: __( 'Build time', 'zinn-seo' ),
								value: sprintf(
									/* translators: %s: seconds. */
									__( '%s s', 'zinn-seo' ),
									Number( status.took || 0 ).toFixed( 1 )
								),
							},
							{
								label: __( 'Memory used', 'zinn-seo' ),
								value: size( status.peak ),
							},
							{
								label: __( 'Waiting changes', 'zinn-seo' ),
								value: num( status.pending ),
							},
						] }
					/>
					{ status.errors && status.errors.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<ul className="zseo-changes">
								{ status.errors.map( ( e, i ) => (
									<li key={ i }>{ problemText( e ) }</li>
								) ) }
							</ul>
						</Notice>
					) }
					{ status.units && status.units.length > 0 && (
						<ul className="zseo-units">
							{ status.units.map( ( u ) => (
								<li key={ `${ u.unit }|${ u.lang }` }>
									<strong>{ u.label }</strong>
									{ u.lang && (
										<span className="zseo-badge">
											{ u.lang }
										</span>
									) }
									<span className="zseo-muted">
										{ [
											sprintf(
												/* translators: %s: a number of addresses. */
												_n(
													'%s address',
													'%s addresses',
													u.urls,
													'zinn-seo'
												),
												num( u.urls )
											),
											sprintf(
												/* translators: %s: a number of sitemap files. */
												_n(
													'%s file',
													'%s files',
													u.files,
													'zinn-seo'
												),
												num( u.files )
											),
											sprintf(
												/* translators: %s: a number of images. */
												_n(
													'%s image',
													'%s images',
													u.images,
													'zinn-seo'
												),
												num( u.images )
											),
										].join( ' · ' ) }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</CardBody>
			</Card>
			<PanelBody title={ __( 'What the sitemap lists', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<IncludeList
						legend={ __( 'Content types', 'zinn-seo' ) }
						choices={ status.choices.types }
						excluded={ value.sitemap_exclude_types }
						onChange={ ( list ) =>
							onChange( { sitemap_exclude_types: list } )
						}
					/>
					<IncludeList
						legend={ __(
							'Categories, tags and taxonomies',
							'zinn-seo'
						) }
						choices={ status.choices.taxonomies }
						excluded={ value.sitemap_exclude_taxonomies }
						onChange={ ( list ) =>
							onChange( { sitemap_exclude_taxonomies: list } )
						}
					/>
					<IncludeList
						legend={ __(
							'Also list terms with nothing in them yet (usually best left off: an empty archive is a thin page)',
							'zinn-seo'
						) }
						choices={ status.choices.taxonomies.filter(
							( c ) => ! c.why
						) }
						excluded={ status.choices.taxonomies
							.map( ( c ) => c.name )
							.filter(
								( n ) =>
									! (
										value.sitemap_empty_taxonomies || []
									).includes( n )
							) }
						onChange={ ( excluded ) =>
							onChange( {
								sitemap_empty_taxonomies:
									status.choices.taxonomies
										.map( ( c ) => c.name )
										.filter(
											( n ) => ! excluded.includes( n )
										),
							} )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Author pages', 'zinn-seo' ) }
						checked={ !! value.sitemap_authors }
						onChange={ ( v ) => onChange( { sitemap_authors: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Leave these pages out', 'zinn-seo' ) }
						help={ __(
							'Post IDs separated by commas; a term ID with a t in front (t12). Pages set to "do not index", and pages naming another page as the one to index, are left out by themselves.',
							'zinn-seo'
						) }
						value={ value.sitemap_exclude_ids || '' }
						onChange={ ( v ) =>
							onChange( {
								sitemap_exclude_ids: v.replace(
									/[^\dtT,\s]/g,
									''
								),
							} )
						}
					/>
					{ status.woo && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'List products that are out of stock',
								'zinn-seo'
							) }
							help={ __(
								'Hidden products are never listed, and product variations never appear as pages of their own.',
								'zinn-seo'
							) }
							checked={ !! value.sitemap_woo_outofstock }
							onChange={ ( v ) =>
								onChange( { sitemap_woo_outofstock: v } )
							}
						/>
					) }
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'Images, languages and files', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<div className="zseo-stack">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( "List each page's images", 'zinn-seo' ) }
						checked={ !! value.sitemap_images }
						onChange={ ( v ) => onChange( { sitemap_images: v } ) }
					/>
					{ value.sitemap_images && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 1 }
							max={ 1000 }
							label={ __(
								'Images per page, at most',
								'zinn-seo'
							) }
							value={ String( value.sitemap_images_max ) }
							onChange={ ( v ) =>
								onChange( {
									sitemap_images_max: Math.max(
										1,
										Math.min( 1000, parseInt( v, 10 ) || 1 )
									),
								} )
							}
						/>
					) }
					{ status.languages && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								"List every page's translations (hreflang)",
								'zinn-seo'
							) }
							help={ __(
								'From Tranzly: each language has its own files, and each page names its translations so search engines show the right language.',
								'zinn-seo'
							) }
							checked={ !! value.sitemap_alternates }
							onChange={ ( v ) =>
								onChange( { sitemap_alternates: v } )
							}
						/>
					) }
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 100 }
						max={ 50000 }
						label={ __( 'Addresses per file', 'zinn-seo' ) }
						help={ __(
							'Smaller files are quicker for search engines to fetch; 1,000 suits most sites.',
							'zinn-seo'
						) }
						value={ String( value.sitemap_per_file ) }
						onChange={ ( v ) =>
							onChange( {
								sitemap_per_file: Math.max(
									100,
									Math.min( 50000, parseInt( v, 10 ) || 100 )
								),
							} )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Readable view in a browser', 'zinn-seo' ) }
						help={ __(
							'Shows the sitemap as a table when a person opens it. Search engines ignore it. Never shown in no-trace mode.',
							'zinn-seo'
						) }
						checked={ !! value.sitemap_xsl }
						onChange={ ( v ) => onChange( { sitemap_xsl: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Address of the sitemap index',
							'zinn-seo'
						) }
						help={ __(
							'Keep the address search engines already have. The other one sends visitors to it.',
							'zinn-seo'
						) }
						value={ value.sitemap_index_name || 'sitemap.xml' }
						options={ [
							{ value: 'sitemap.xml', label: '/sitemap.xml' },
							{
								value: 'sitemap_index.xml',
								label: '/sitemap_index.xml',
							},
						] }
						onChange={ ( v ) =>
							onChange( { sitemap_index_name: v } )
						}
					/>
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'A page of links for people', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<p>
					{ __(
						'Put this shortcode on any page to list your pages, newest first, with a link to more:',
						'zinn-seo'
					) }
				</p>
				<code className="zseo-code">[zinn_seo_sitemap]</code>
				<p className="zseo-muted">
					{ __(
						'Options: types="post,page", per_type="50", taxonomies="category". No stylesheet or script is added; your theme styles it.',
						'zinn-seo'
					) }
				</p>
			</PanelBody>
			{ isPro && 'sitemap_video' in value ? (
				<PanelBody
					title={ __(
						'Video, news and network sitemaps',
						'zinn-seo'
					) }
					initialOpen={ false }
				>
					<div className="zseo-stack">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'List the videos in each page',
								'zinn-seo'
							) }
							help={ __(
								'YouTube, Vimeo and your own video files, with their thumbnails.',
								'zinn-seo'
							) }
							checked={ !! value.sitemap_video }
							onChange={ ( v ) =>
								onChange( { sitemap_video: v } )
							}
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Google News sitemap', 'zinn-seo' ) }
							help={ __(
								'The posts of the last two days, for sites in Google News.',
								'zinn-seo'
							) }
							checked={ !! value.sitemap_news }
							onChange={ ( v ) =>
								onChange( { sitemap_news: v } )
							}
						/>
						{ value.sitemap_news && (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Publication name', 'zinn-seo' ) }
								help={ __(
									'As registered with Google News. Empty: the site name.',
									'zinn-seo'
								) }
								value={ value.sitemap_news_name || '' }
								onChange={ ( v ) =>
									onChange( { sitemap_news_name: v } )
								}
							/>
						) }
						{ data.multisite && (
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'One index for the whole network',
									'zinn-seo'
								) }
								help={ __(
									"On the main site, sitemap-network.xml lists every site's files on the same address.",
									'zinn-seo'
								) }
								checked={ !! value.sitemap_network }
								onChange={ ( v ) =>
									onChange( { sitemap_network: v } )
								}
							/>
						) }
					</div>
				</PanelBody>
			) : (
				<ProTeaser
					title={ __(
						'Video, news and network sitemaps',
						'zinn-seo'
					) }
					points={ [
						__(
							'The videos in your posts listed with their thumbnails, so they can appear in video results.',
							'zinn-seo'
						),
						__(
							'A Google News sitemap of the last two days, kept current by itself.',
							'zinn-seo'
						),
						__(
							'On multisite, one sitemap index for every site in the network.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}
