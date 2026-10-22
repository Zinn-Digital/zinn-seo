import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	BaseControl,
	Button,
	CheckboxControl,
	Notice,
	PanelBody,
	RadioControl,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from './api';
import ImagePicker from './ImagePicker';
import GuideLink from './p7/GuideLink';
import ProTeaser from './ProTeaser';
import RescrapeTab from './social/RescrapeTab';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function socialTabs() {
	return [
		{ name: 'sharing', title: __( 'Sharing', 'zinn-seo' ) },
		{ name: 'cards', title: __( 'Generated cards', 'zinn-seo' ) },
		{ name: 'refresh', title: __( 'Refresh shared links', 'zinn-seo' ) },
	];
}

/**
 * An X username without the @ (or the address of a profile). Exported for the tests.
 *
 * @param {string} value What was typed.
 * @return {string} The username.
 */
export function xHandle( value ) {
	const text = ( value || '' ).trim();
	const match = text.match( /(?:twitter|x)\.com\/([A-Za-z0-9_]{1,15})/i );
	return ( match ? match[ 1 ] : text ).replace( /^@/, '' );
}

/**
 * The extra sharing tags a site can switch on (`social_extras`).
 *
 * @return {Array<Array<string>>} [ key, label ] pairs.
 */
export function socialExtras() {
	return [
		[
			'publisher',
			__(
				'The Facebook page as publisher of posts, pages and products',
				'zinn-seo'
			),
		],
		[ 'publisher_all', __( '…of every page, lists too', 'zinn-seo' ) ],
		[ 'image_type', __( 'The shared image’s file type', 'zinn-seo' ) ],
		[ 'updated_time', __( 'When a page last changed', 'zinn-seo' ) ],
		[ 'article_tags', __( 'A post’s tags', 'zinn-seo' ) ],
		[ 'product', __( 'A product’s price and stock', 'zinn-seo' ) ],
		[
			'product_labels',
			__(
				'A product’s price and stock under a shared link on X',
				'zinn-seo'
			),
		],
		[
			'written_by',
			__( '"Written by" under a shared post on X', 'zinn-seo' ),
		],
		[ 'written_by_pages', __( '…under shared pages too', 'zinn-seo' ) ],
	];
}

/**
 * Open Graph, X, fediverse and Pinterest.
 *
 * @param {Object}   props
 * @param {Object}   props.data     Boot data (the guide links).
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The tab.
 */
function SharingTab( { value, onChange, data } ) {
	return (
		<div className="zseo-stack">
			<PanelBody
				title={ __(
					'Facebook, LinkedIn, WhatsApp, Slack and others',
					'zinn-seo'
				) }
			>
				<div className="zseo-stack">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Add Open Graph tags', 'zinn-seo' ) }
						help={ __(
							'The title, text and image a link shows when someone shares it. Almost every network and messenger reads them.',
							'zinn-seo'
						) }
						checked={ !! value.og }
						onChange={ ( og ) => onChange( { og } ) }
					/>
					<ImagePicker
						id="zseo-og-default"
						label={ __( 'Default image', 'zinn-seo' ) }
						help={ __(
							'Shown when a page has no image of its own. Best at 1200 × 630 pixels.',
							'zinn-seo'
						) }
						value={ value.og_default_image || 0 }
						onChange={ ( v ) =>
							onChange( { og_default_image: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							"Use a post's featured image when it has no sharing image of its own",
							'zinn-seo'
						) }
						checked={ value.og_featured_image !== false }
						onChange={ ( v ) =>
							onChange( { og_featured_image: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Use the first image in a post when it has no featured image',
							'zinn-seo'
						) }
						checked={ !! value.og_content_image }
						onChange={ ( v ) =>
							onChange( { og_content_image: v } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Facebook app ID (optional)', 'zinn-seo' ) }
						help={ __(
							"Only needed for Facebook's sharing statistics.",
							'zinn-seo'
						) }
						value={ value.fb_app_id || '' }
						onChange={ ( v ) =>
							onChange( { fb_app_id: v.replace( /\D/g, '' ) } )
						}
					/>
				</div>
			</PanelBody>
			<PanelBody title={ __( 'X (Twitter)', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Add X card tags', 'zinn-seo' ) }
						checked={ !! value.x_cards }
						onChange={ ( v ) => onChange( { x_cards: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Card style', 'zinn-seo' ) }
						value={ value.x_card }
						options={ [
							{
								value: 'summary_large_image',
								label: __(
									'Large image (recommended)',
									'zinn-seo'
								),
							},
							{
								value: 'summary',
								label: __( 'Small square image', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) => onChange( { x_card: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( "The site's X username", 'zinn-seo' ) }
						help={ __(
							'Authors add their own under Users, Profile.',
							'zinn-seo'
						) }
						value={ value.x_site || '' }
						onChange={ ( v ) =>
							onChange( { x_site: xHandle( v ) } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Full set of sharing tags', 'zinn-seo' ) }
						help={ __(
							'Repeats the title, text and image for X, and adds the image type, the price and stock of a product and "Written by" / "Time to read" under a shared link. On after a move from Rank Math, so shared links look as before.',
							'zinn-seo'
						) }
						checked={ !! value.social_rank_math }
						onChange={ ( v ) =>
							onChange( { social_rank_math: v } )
						}
					/>
					<GuideLink data={ data } id="sharing-extras" />
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'X shows the page’s search title and description',
							'zinn-seo'
						) }
						help={ __(
							'For a page with no X text of its own. Off: X shows the shared (Open Graph) title and text.',
							'zinn-seo'
						) }
						checked={ !! value.x_page_text }
						onChange={ ( v ) => onChange( { x_page_text: v } ) }
					/>
					<ImagePicker
						id="zseo-social-x-default"
						label={ __( 'Default picture for X', 'zinn-seo' ) }
						help={ __(
							'Shown on X for pages with no picture of their own, when it should differ from the default shared picture.',
							'zinn-seo'
						) }
						value={ value.x_default_image || 0 }
						onChange={ ( id ) =>
							onChange( { x_default_image: id } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Site name on shared links', 'zinn-seo' ) }
						help={
							__(
								'Empty: the site title. Template variables work here:',
								'zinn-seo'
							) + ' %sitename%, %tagline%'
						}
						value={ value.og_site_name || '' }
						onChange={ ( v ) => onChange( { og_site_name: v } ) }
					/>
					<BaseControl
						__nextHasNoMarginBottom
						id="zseo-social-extras"
						label={ __( 'Extra sharing tags', 'zinn-seo' ) }
						help={ __(
							'Tags some SEO plugins print beyond the usual set. A move from one of them ticks what it printed.',
							'zinn-seo'
						) }
					>
						{ socialExtras().map( ( [ key, text ] ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ key }
								label={ text }
								checked={ (
									value.social_extras || []
								).includes( key ) }
								onChange={ ( on ) =>
									onChange( {
										social_extras: on
											? [
													...( value.social_extras ||
														[] ),
													key,
												]
											: (
													value.social_extras || []
												).filter( ( k ) => k !== key ),
									} )
								}
							/>
						) ) }
					</BaseControl>
				</div>
			</PanelBody>
			<PanelBody title={ __( 'Mastodon and the fediverse', 'zinn-seo' ) }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Default author handle', 'zinn-seo' ) }
					help={ __(
						'Written as @name@server. Mastodon then credits the author under every shared link. Authors can add their own under Users, Profile.',
						'zinn-seo'
					) }
					value={ value.fediverse_creator || '' }
					onChange={ ( v ) =>
						onChange( { fediverse_creator: v.trim() } )
					}
				/>
			</PanelBody>
			<PanelBody title={ __( 'Pinterest', 'zinn-seo' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						"Ask Pinterest not to let visitors pin this site's pages",
						'zinn-seo'
					) }
					help={ __(
						'Each page can also choose this for itself. Rich pins read the Open Graph tags; claim your site under Search appearance, Site verification.',
						'zinn-seo'
					) }
					checked={ !! value.nopin }
					onChange={ ( v ) => onChange( { nopin: v } ) }
				/>
			</PanelBody>
		</div>
	);
}

/**
 * A colour setting (the browser's own colour picker: no extra script).
 *
 * @param {Object}   props
 * @param {string}   props.id       Id.
 * @param {string}   props.label    Label.
 * @param {string}   props.value    `#rrggbb`.
 * @param {Function} props.onChange Called with the colour.
 * @return {Element} The control.
 */
function Colour( { id, label, value, onChange } ) {
	return (
		<BaseControl __nextHasNoMarginBottom id={ id } label={ label }>
			<input
				id={ id }
				type="color"
				className="zseo-colour"
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		</BaseControl>
	);
}

/**
 * Pro: generated cards.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.saved    Saved settings.
 * @return {Element} The tab.
 */
function CardsTab( { value, onChange, saved } ) {
	const [ status, setStatus ] = useState( null );
	const [ types, setTypes ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const load = () =>
		seoFetch( '/social-cards' )
			.then( setStatus )
			.catch( ( e ) => setError( errorText( e ) ) );

	useEffect( () => {
		load();
		seoFetch( '/bulk/choices' )
			.then( ( r ) => setTypes( ( r.types && r.types.post ) || [] ) )
			.catch( () => setTypes( [] ) );
	}, [] );

	useEffect( () => {
		if ( ! status || ! status.job || 'pending' !== status.job.status ) {
			return undefined;
		}
		const timer = window.setTimeout( load, 4000 );
		return () => window.clearTimeout( timer );
	}, [ status ] );

	const draw = () => {
		setBusy( true );
		setError( '' );
		const keys = [
			'cards_style',
			'cards_bg',
			'cards_fg',
			'cards_accent',
			'cards_logo',
			'cards_author',
		];
		const settings = {};
		keys.forEach( ( k ) => {
			settings[ k ] = value[ k ];
		} );
		seoFetch( '/social-cards/preview', {
			method: 'POST',
			data: { settings },
		} )
			.then( setPreview )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const runAll = () =>
		seoFetch( '/social-cards', { method: 'POST' } )
			.then( setStatus )
			.catch( ( e ) => setError( errorText( e ) ) );

	const chosen = value.cards_types || [];
	const job = status && status.job;
	return (
		<div className="zseo-stack">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ status && ! status.supported && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"This server's PHP cannot draw images with text (it needs GD with FreeType). Ask your host to switch it on; until then, pages use their own images.",
						'zinn-seo'
					) }
				</Notice>
			) }
			<PanelBody title={ __( 'Badge over shared images', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<ImagePicker
						id="zseo-social-overlay"
						label={ __(
							'Badge or watermark (a PNG with a transparent background)',
							'zinn-seo'
						) }
						help={ __(
							'Laid over the image every shared link shows. The image is made once and kept.',
							'zinn-seo'
						) }
						value={ value.social_overlay_id || 0 }
						onChange={ ( id ) =>
							onChange( { social_overlay_id: id } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Where on the image', 'zinn-seo' ) }
						value={
							value.social_overlay_position || 'bottom_center'
						}
						options={ [
							[ 'top_left', __( 'Top left', 'zinn-seo' ) ],
							[ 'top_center', __( 'Top centre', 'zinn-seo' ) ],
							[ 'top_right', __( 'Top right', 'zinn-seo' ) ],
							[ 'middle_left', __( 'Middle left', 'zinn-seo' ) ],
							[ 'middle_center', __( 'Middle', 'zinn-seo' ) ],
							[
								'middle_right',
								__( 'Middle right', 'zinn-seo' ),
							],
							[ 'bottom_left', __( 'Bottom left', 'zinn-seo' ) ],
							[
								'bottom_center',
								__( 'Bottom centre', 'zinn-seo' ),
							],
							[
								'bottom_right',
								__( 'Bottom right', 'zinn-seo' ),
							],
						].map( ( [ v, l ] ) => ( { value: v, label: l } ) ) }
						onChange={ ( v ) =>
							onChange( { social_overlay_position: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'On every page', 'zinn-seo' ) }
						help={ __(
							'Off: only the kinds of page it was set for (a move from Rank Math keeps its choices).',
							'zinn-seo'
						) }
						checked={ ( value.social_overlay_where || [] ).includes(
							'*'
						) }
						onChange={ ( on ) =>
							onChange( {
								social_overlay_where: on
									? [
											'*',
											...(
												value.social_overlay_where || []
											).filter( ( k ) => '*' !== k ),
										]
									: (
											value.social_overlay_where || []
										).filter( ( k ) => '*' !== k ),
							} )
						}
					/>
				</div>
			</PanelBody>
			<PanelBody title={ __( 'When to use a card', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Make social cards', 'zinn-seo' ) }
						checked={ !! value.cards }
						onChange={ ( v ) => onChange( { cards: v } ) }
					/>
					<RadioControl
						label={ __( 'Which pages get one', 'zinn-seo' ) }
						selected={ value.cards_mode }
						options={ [
							{
								value: 'missing',
								label: __(
									'Pages with no image of their own (recommended)',
									'zinn-seo'
								),
							},
							{
								value: 'always',
								label: __(
									'Every page, so every share looks the same (an image chosen for sharing on a page still wins)',
									'zinn-seo'
								),
							},
						] }
						onChange={ ( v ) => onChange( { cards_mode: v } ) }
					/>
					<fieldset>
						<legend className="zseo-legend">
							{ __( 'Content types', 'zinn-seo' ) }
						</legend>
						{ types.map( ( t ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ t.value }
								label={ t.label }
								checked={ chosen.includes( t.value ) }
								onChange={ ( on ) =>
									onChange( {
										cards_types: on
											? [ ...chosen, t.value ]
											: chosen.filter(
													( x ) => x !== t.value
												),
									} )
								}
							/>
						) ) }
					</fieldset>
				</div>
			</PanelBody>
			<PanelBody title={ __( 'How cards look', 'zinn-seo' ) }>
				<div className="zseo-cards-grid">
					<div className="zseo-stack">
						<RadioControl
							label={ __( 'Background', 'zinn-seo' ) }
							selected={ value.cards_style }
							options={ [
								{
									value: 'image',
									label: __(
										"The post's featured image, darkened under the title",
										'zinn-seo'
									),
								},
								{
									value: 'brand',
									label: __(
										'Your brand colour',
										'zinn-seo'
									),
								},
							] }
							onChange={ ( v ) => onChange( { cards_style: v } ) }
						/>
						<div className="zseo-row">
							<Colour
								id="zseo-cards-bg"
								label={ __( 'Background colour', 'zinn-seo' ) }
								value={ value.cards_bg }
								onChange={ ( v ) =>
									onChange( { cards_bg: v } )
								}
							/>
							<Colour
								id="zseo-cards-fg"
								label={ __( 'Text colour', 'zinn-seo' ) }
								value={ value.cards_fg }
								onChange={ ( v ) =>
									onChange( { cards_fg: v } )
								}
							/>
							<Colour
								id="zseo-cards-accent"
								label={ __( 'Accent colour', 'zinn-seo' ) }
								value={ value.cards_accent }
								onChange={ ( v ) =>
									onChange( { cards_accent: v } )
								}
							/>
						</div>
						<ImagePicker
							id="zseo-cards-logo"
							label={ __( 'Logo on the card', 'zinn-seo' ) }
							help={ __(
								'Empty: the logo from Settings, Who is behind it, or the site logo. Without any logo, the site name is written instead.',
								'zinn-seo'
							) }
							value={ value.cards_logo || 0 }
							onChange={ ( v ) => onChange( { cards_logo: v } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( "Show the author's name", 'zinn-seo' ) }
							checked={ !! value.cards_author }
							onChange={ ( v ) =>
								onChange( { cards_author: v } )
							}
						/>
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ draw }
						>
							{ __( 'Preview a card', 'zinn-seo' ) }
						</Button>
					</div>
					<div className="zseo-cards-preview">
						{ preview ? (
							<>
								<img
									src={ preview.image }
									alt={ sprintf(
										/* translators: %s: a post title. */
										__( 'Card for "%s"', 'zinn-seo' ),
										preview.title
									) }
								/>
								{ ! preview.title_drawn && (
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ __(
											'This title is in a writing system the card fonts do not cover, so the card shows your logo or name without the title.',
											'zinn-seo'
										) }
									</Notice>
								) }
							</>
						) : (
							<p className="zseo-muted">
								{ __(
									'Preview a card to see it here, drawn from your newest post.',
									'zinn-seo'
								) }
							</p>
						) }
					</div>
				</div>
			</PanelBody>
			<PanelBody title={ __( 'Cards for existing posts', 'zinn-seo' ) }>
				<p className="zseo-muted">
					{ __(
						'New and updated posts get their card in the background a moment after they are saved. Changing how cards look redraws all of them.',
						'zinn-seo'
					) }
				</p>
				{ job && 'pending' === job.status ? (
					<p aria-live="polite">
						<Spinner />{ ' ' }
						{ sprintf(
							/* translators: 1: posts done, 2: posts in all. */
							__(
								'Drawing cards: %1$d of %2$d posts…',
								'zinn-seo'
							),
							( job.progress && job.progress.done ) || 0,
							( job.progress && job.progress.total ) ||
								( status && status.total ) ||
								0
						) }
					</p>
				) : (
					<>
						{ job && 'done' === job.status && (
							<p className="zseo-ok">
								{ sprintf(
									/* translators: %d: number of cards drawn. */
									__(
										'Finished: %d cards drawn or already current.',
										'zinn-seo'
									),
									( job.state && job.state.done ) || 0
								) }
							</p>
						) }
						<Button
							variant="secondary"
							disabled={
								! saved.cards || ! status || ! status.supported
							}
							onClick={ runAll }
						>
							{ __(
								'Make cards for every post now',
								'zinn-seo'
							) }
						</Button>
					</>
				) }
			</PanelBody>
		</div>
	);
}

/**
 * The Social screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function SocialScreen( { data } ) {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const isPro = 'free' !== data.plan;

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! settings ) {
		return <Spinner />;
	}
	const value = { ...settings, ...draft };
	const change = ( part ) => {
		setDraft( { ...draft, ...part } );
		setNotice( null );
	};
	const dirty = Object.keys( draft ).length > 0;
	const store = () => {
		setBusy( true );
		save( draft )
			.then( () => {
				setDraft( {} );
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'zinn-seo' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const initial = ( window.location.hash || '' ).replace( '#/', '' );

	return (
		<div className="zseo-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<TabPanel
				className="zseo-tabs"
				tabs={ socialTabs() }
				initialTabName={
					socialTabs().some( ( t ) => t.name === initial )
						? initial
						: 'sharing'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					if ( 'refresh' === tab.name ) {
						return <RescrapeTab data={ data } />;
					}
					if ( 'cards' === tab.name ) {
						return isPro && 'cards_style' in settings ? (
							<CardsTab
								value={ value }
								onChange={ change }
								saved={ settings }
							/>
						) : (
							<ProTeaser
								title={ __(
									'Generated social cards',
									'zinn-seo'
								) }
								points={ [
									__(
										'Every post gets a share image with its title, your logo and colours, drawn on your own server once and kept as a file.',
										'zinn-seo'
									),
									__(
										'Posts without an image stop sharing as a bare link; or give every share the same branded look.',
										'zinn-seo'
									),
									__(
										'Arabic, Persian, Urdu and Hebrew titles are drawn right to left, correctly joined.',
										'zinn-seo'
									),
								] }
							/>
						);
					}
					return (
						<SharingTab
							value={ value }
							onChange={ change }
							data={ data }
						/>
					);
				} }
			</TabPanel>
			<div className="zseo-savebar">
				<span className="zseo-muted" aria-live="polite">
					{ dirty
						? __( 'You have unsaved changes.', 'zinn-seo' )
						: '' }
				</span>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! dirty }
					onClick={ store }
				>
					{ __( 'Save changes', 'zinn-seo' ) }
				</Button>
			</div>
		</div>
	);
}
