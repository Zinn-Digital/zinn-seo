/**
 * Clean-up: the head and feeds (zs-701), addresses (zs-714), robots defaults (zs-713), RSS feeds
 * (zs-717), link previews (zs-727), the comment trap (zs-728), images (zs-710) and conflicts
 * (zs-703). Each tab shows only while its feature is switched on.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TabPanel,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { useRoute, RunButton } from '../p4/shared';
import { Ready, SaveBar, Switch, useDraft } from './shared';
import RobotsDefaultsTab from './RobotsDefaultsTab';
import FeedsTab from './FeedsTab';
import CommentsTab from './CommentsTab';
import ImagesTab from './ImagesTab';
import ConflictsTab from './ConflictsTab';
import './x1a.scss';

/**
 * The archives a site can switch off: each is sent to the home page (301).
 *
 * @return {Array<Array<string>>} [ key, label ] pairs.
 */
export function archivesOff() {
	return [
		[ 'author', __( 'Send author archives to the home page', 'zinn-seo' ) ],
		[
			'author_single',
			__(
				'…only on a one-author site, or for an author with no posts',
				'zinn-seo'
			),
		],
		[ 'date', __( 'Send date archives to the home page', 'zinn-seo' ) ],
	];
}

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function cleanupTabs( data = {} ) {
	const mods = data.modules || {};
	const on = ( id ) => false !== mods[ id ];
	return [
		...( on( 'cleanup' )
			? [
					{ name: 'head', title: __( 'Head and feeds', 'zinn-seo' ) },
					{ name: 'addresses', title: __( 'Addresses', 'zinn-seo' ) },
					{
						name: 'previews',
						title: __( 'Link previews', 'zinn-seo' ),
					},
				]
			: [] ),
		...( on( 'smart-robots' )
			? [ { name: 'robots', title: __( 'Robots rules', 'zinn-seo' ) } ]
			: [] ),
		...( on( 'feeds' )
			? [ { name: 'feeds', title: __( 'RSS feeds', 'zinn-seo' ) } ]
			: [] ),
		...( on( 'honeypot' )
			? [
					{
						name: 'comments',
						title: __( 'Comment spam', 'zinn-seo' ),
					},
				]
			: [] ),
		...( on( 'images' )
			? [ { name: 'images', title: __( 'Images', 'zinn-seo' ) } ]
			: [] ),
		...( on( 'conflicts' )
			? [ { name: 'conflicts', title: __( 'Conflicts', 'zinn-seo' ) } ]
			: [] ),
	];
}

/**
 * Head links and feeds (zs-701).
 *
 * @param {Object} props
 * @param {Object} props.form  The settings draft.
 * @param {Object} props.state `GET /cleanup`.
 * @param {Object} props.data  Boot data.
 * @return {Element} Tab.
 */
function HeadTab( { form, state, data } ) {
	const found = ( state && state.found ) || {};
	const lines = ( key ) =>
		state && state.read ? found[ key ] || [] : null;
	const sw = ( name, label, help ) => (
		<Switch
			key={ name }
			form={ form }
			name={ name }
			label={ label }
			help={ help }
			lines={ name in found ? lines( name ) : undefined }
		/>
	);
	const recommend = () =>
		seoFetch( '/cleanup/recommended', { method: 'POST' } ).then( () =>
			window.location.reload()
		);
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'WordPress adds links, tags and feeds to every page that visitors never see and search engines waste time on. Switch off what you do not use: your pages get lighter and search engines spend their visits on the pages that matter.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-head" />
			</p>
			{ state && ! state.read && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Your home page could not be read just now, so the lines each switch removes are not shown. The switches work all the same.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<p>
				<RunButton variant="secondary" run={ recommend }>
					{ __( 'Switch on the recommended set', 'zinn-seo' ) }
				</RunButton>
			</p>
			<PanelBody title={ __( 'Links in every page head', 'zinn-seo' ) }>
				{ sw(
					'clean_shortlink',
					__( 'Remove the short link', 'zinn-seo' ),
					__(
						'The "?p=123" address of each post. Nobody needs it once your addresses have names.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_rsd',
					__( 'Remove the RSD link', 'zinn-seo' ),
					__( 'Only old desktop blogging apps read it.', 'zinn-seo' )
				) }
				{ sw(
					'clean_wlw',
					__( 'Remove the Windows Live Writer link', 'zinn-seo' ),
					__(
						'An app that no longer exists. Removed only where something still adds it.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_rest_links',
					__( 'Remove the REST API links', 'zinn-seo' ),
					__(
						'The API itself keeps working; only the pointers to it in each page and its headers go. Keep this off if an app or service finds your site through them.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_oembed_links',
					__( 'Remove the embed discovery links', 'zinn-seo' ),
					__(
						'Other WordPress sites use them to embed your posts as cards. Off: your links still work everywhere, as plain links.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_generator',
					__( 'Remove the software version tags', 'zinn-seo' ),
					__(
						'"WordPress 7.1", "WooCommerce 10.2"… tell attackers what to try and search engines nothing.',
						'zinn-seo'
					)
				) }
			</PanelBody>
			<PanelBody title={ __( 'Feeds', 'zinn-seo' ) }>
				<p className="zseo-muted">
					{ __(
						'A feed switched off answers with a redirect to its page, so nothing that knew it ends on an error. Your main feed always stays.',
						'zinn-seo'
					) }
				</p>
				{ sw(
					'clean_feed_comments',
					__( 'Comment feeds', 'zinn-seo' ),
					__(
						'The site-wide comments feed and one per post.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_feed_author',
					__( 'Author feeds', 'zinn-seo' ),
					''
				) }
				{ sw(
					'clean_feed_search',
					__( 'Search result feeds', 'zinn-seo' ),
					__(
						'Spammers use them to make your site publish their words.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_feed_terms',
					__( 'Category, tag and other archive feeds', 'zinn-seo' ),
					''
				) }
				{ sw(
					'clean_feed_types',
					__( 'Content type archive feeds', 'zinn-seo' ),
					''
				) }
			</PanelBody>
			<PanelBody title={ __( 'Unwanted addresses', 'zinn-seo' ) }>
				{ sw(
					'clean_params',
					__(
						'Remove unknown parameters from addresses',
						'zinn-seo'
					),
					__(
						'An address with "?sessionid=…" or another parameter your site does not use is sent to the clean address, so search engines see one copy of each page. Campaign and click ids (utm_, gclid, fbclid…) and the parameters shops and filters use are always kept.',
						'zinn-seo'
					)
				) }
				{ form.value.clean_params && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Parameters to keep as well', 'zinn-seo' ) }
						help={ __(
							'One per line. End a name with * to keep every parameter that starts with it.',
							'zinn-seo'
						) }
						value={ form.value.clean_params_allow || '' }
						onChange={ ( v ) =>
							form.change( { clean_params_allow: v } )
						}
						rows={ 3 }
					/>
				) }
				{ sw(
					'clean_search_spam',
					__( 'Refuse spam searches', 'zinn-seo' ),
					__(
						'Searches carrying a link, emoji or a wall of text are answered with "not found", so they never become pages of your site in search results.',
						'zinn-seo'
					)
				) }
				{ sw(
					'clean_search_robots',
					__(
						'Keep crawlers out of search results pages',
						'zinn-seo'
					),
					__(
						'Adds lines to robots.txt so search engines do not crawl your site search at all.',
						'zinn-seo'
					)
				) }
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * Addresses and titles (zs-714).
 *
 * @param {Object} props
 * @param {Object} props.form  The settings draft.
 * @param {Object} props.state `GET /cleanup`.
 * @param {Object} props.data  Boot data.
 * @return {Element} Tab.
 */
function AddressesTab( { form, state, data } ) {
	const clashes = ( state && state.collisions ) || {};
	const caseFor = ( code ) =>
		( ( form.value.title_case || {} )[ code ] || {} ).style || '';
	const setCase = ( code, style ) => {
		const next = { ...( form.value.title_case || {} ) };
		if ( style ) {
			next[ code ] = { style };
		} else {
			delete next[ code ];
		}
		form.change( { title_case: next } );
	};
	const ClashList = ( { list } ) =>
		list && list.length ? (
			<Notice status="warning" isDismissible={ false }>
				<p>
					{ __(
						'These pages have the same address a category would take. The category will win: rename one of them first.',
						'zinn-seo'
					) }
				</p>
				<ul>
					{ list.map( ( c ) => (
						<li key={ c.path }>
							<a href={ c.link } dir="ltr">
								{ c.link }
							</a>
						</li>
					) ) }
				</ul>
			</Notice>
		) : null;
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Short, clean and consistent addresses are easier to share, to remember and to rank. Every change here redirects the old addresses, so no link and no ranking is lost.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-addresses" />
			</p>
			{ state && ! state.pretty && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Your site uses plain "?p=123" addresses. Choose a name-based structure under Settings, Permalinks first.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<PanelBody title={ __( 'Categories', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="url_category_base"
					label={ sprintf(
						/* translators: %s: the category base, usually "category". */
						__(
							'Remove "/%s/" from category addresses',
							'zinn-seo'
						),
						( state && state.bases && state.bases.category ) ||
							'category'
					) }
					help={ __(
						'example.com/category/news/ becomes example.com/news/.',
						'zinn-seo'
					) }
				/>
				<ClashList list={ clashes.category } />
				{ state && state.shop && (
					<>
						<Switch
							form={ form }
							name="url_product_base"
							label={ sprintf(
								/* translators: %s: the shop category base. */
								__(
									'Remove "/%s/" from shop category addresses',
									'zinn-seo'
								),
								state.bases.product_cat || 'product-category'
							) }
							help={ __(
								'example.com/product-category/shoes/ becomes example.com/shoes/.',
								'zinn-seo'
							) }
						/>
						<ClashList list={ clashes.product_cat } />
					</>
				) }
			</PanelBody>
			<PanelBody
				title={ __( 'Pages WordPress makes for you', 'zinn-seo' ) }
			>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Attachment pages', 'zinn-seo' ) }
					help={ __(
						'WordPress gives every uploaded image a page of its own: thin pages search engines index for nothing.',
						'zinn-seo'
					) }
					value={ form.value.url_attachment || '' }
					options={ [
						{
							value: '',
							label: __( 'Leave as they are', 'zinn-seo' ),
						},
						{
							value: 'parent',
							label: __(
								'Send to the page they belong to (else the file)',
								'zinn-seo'
							),
						},
						{
							value: 'file',
							label: __( 'Send to the file itself', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => form.change( { url_attachment: v } ) }
				/>
				{ archivesOff().map( ( [ key, label ] ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ key }
						label={ label }
						checked={ ( form.value.archives_off || [] ).includes(
							key
						) }
						onChange={ ( on ) =>
							form.change( {
								archives_off: on
									? [
											...( form.value.archives_off ||
												[] ),
											key,
										]
									: ( form.value.archives_off || [] ).filter(
											( k ) => k !== key
										),
							} )
						}
					/>
				) ) }
				<Switch
					form={ form }
					name="url_replytocom"
					label={ __( 'Remove comment-reply copies', 'zinn-seo' ) }
					help={ __(
						'Every "Reply" link otherwise makes a copy of the page with its own address. The reply form still opens in place.',
						'zinn-seo'
					) }
				/>
			</PanelBody>
			<PanelBody title={ __( 'One address per page', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="url_host"
					label={ __(
						'Always use your main address (www or not)',
						'zinn-seo'
					) }
					help={ __(
						'WordPress usually does this already; switch it on when another plugin or your theme stops it.',
						'zinn-seo'
					) }
				/>
				<Switch
					form={ form }
					name="url_slash"
					label={ __(
						'Always use one trailing slash style',
						'zinn-seo'
					) }
					help={ __(
						'The style of your permalink settings. Files keep their names.',
						'zinn-seo'
					) }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Title capitals', 'zinn-seo' ) }>
				<p className="zseo-muted">
					{ __(
						'Capitalise the titles search engines show, in the way that suits each language. Words written with capitals inside them (iPhone, SEO) and numbers are left exactly as you wrote them.',
						'zinn-seo'
					) }
				</p>
				{ ( ( state && state.languages ) || [] ).map( ( l ) => (
					<SelectControl
						__nextHasNoMarginBottom
						key={ l.code }
						label={ l.name }
						value={ caseFor( l.code ) }
						options={ [
							{
								value: '',
								label: __( 'As written', 'zinn-seo' ),
							},
							{
								value: 'title',
								label: __(
									'Title Case (Small Words Kept Small)',
									'zinn-seo'
								),
							},
							{
								value: 'first',
								label: __( 'First letter only', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) => setCase( l.code, v ) }
					/>
				) ) }
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * Link previews in chat and social apps (zs-727).
 *
 * @param {Object} props
 * @param {Object} props.form The settings draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function PreviewsTab( { form, data } ) {
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					"When someone pastes a link to your page into Slack, Discord, LinkedIn or Mastodon, the preview they see comes partly from your site's embed information. Make it show your best title and image.",
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-previews" />
			</p>
			<PanelBody title={ __( 'Link previews', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="oembed_title"
					label={ __( 'Use the SEO title', 'zinn-seo' ) }
					help={ __(
						'The title you wrote for search results, not the plain post title.',
						'zinn-seo'
					) }
				/>
				<Switch
					form={ form }
					name="oembed_image"
					label={ __( 'Use the sharing image', 'zinn-seo' ) }
					help={ __(
						'The image you chose for sharing, else the featured image.',
						'zinn-seo'
					) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Author shown', 'zinn-seo' ) }
					help={ __(
						'By default the preview names the author and links to their archive, which shows their login name. Showing your site instead keeps that private.',
						'zinn-seo'
					) }
					value={ form.value.oembed_author || '' }
					options={ [
						{
							value: '',
							label: __( "The post's author", 'zinn-seo' ),
						},
						{
							value: 'site',
							label: __( 'Your site', 'zinn-seo' ),
						},
						{ value: 'hide', label: __( 'Nobody', 'zinn-seo' ) },
					] }
					onChange={ ( v ) => form.change( { oembed_author: v } ) }
				/>
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * The Clean-up screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function CleanupScreen( { data } ) {
	const form = useDraft();
	const tabs = cleanupTabs( data );
	const cleanupOn = false !== ( data.modules || {} ).cleanup;
	const state = useRoute( cleanupOn ? '/cleanup' : '/settings' );
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	if ( ! tabs.length ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Every clean-up feature is switched off. Switch them on under Settings, Features.',
					'zinn-seo'
				) }
			</Notice>
		);
	}
	return (
		<Ready form={ form }>
			<div className="zseo-admin zseo-x1a">
				{ state.error && (
					<Notice status="error" isDismissible={ false }>
						{ errorText( { message: state.error } ) }
					</Notice>
				) }
				<TabPanel
					className="zseo-tabs"
					tabs={ tabs }
					initialTabName={
						tabs.some( ( t ) => t.name === initial )
							? initial
							: tabs[ 0 ].name
					}
					onSelect={ ( name ) =>
						window.history.replaceState( null, '', `#/${ name }` )
					}
				>
					{ ( tab ) => {
						const s = cleanupOn ? state.data : null;
						switch ( tab.name ) {
							case 'head':
								return s ? (
									<HeadTab
										form={ form }
										state={ s }
										data={ data }
									/>
								) : (
									<Spinner />
								);
							case 'addresses':
								return s ? (
									<AddressesTab
										form={ form }
										state={ s }
										data={ data }
									/>
								) : (
									<Spinner />
								);
							case 'previews':
								return (
									<PreviewsTab form={ form } data={ data } />
								);
							case 'robots':
								return (
									<RobotsDefaultsTab
										form={ form }
										data={ data }
									/>
								);
							case 'feeds':
								return <FeedsTab form={ form } data={ data } />;
							case 'comments':
								return (
									<CommentsTab form={ form } data={ data } />
								);
							case 'images':
								return (
									<ImagesTab form={ form } data={ data } />
								);
							default:
								return <ConflictsTab data={ data } />;
						}
					} }
				</TabPanel>
			</div>
		</Ready>
	);
}
