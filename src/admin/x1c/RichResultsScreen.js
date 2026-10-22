/**
 * Rich results (stage X1C): carousels (zs-736), image licences (zs-739), news publishing (zs-706),
 * the shop's policies for the whole shop (zs-740), and where datasets, math solvers and accordion
 * questions are set (zs-737, zs-738, zs-712).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	PanelBody,
	SelectControl,
	TabPanel,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { Ready, SaveBar, useDraft } from '../x1a/shared';
import './x1c.scss';

/**
 * The newsroom policies, setting key => label.
 *
 * @return {Object} Labels.
 */
export function newsPolicies() {
	return {
		principles: __( 'Publishing principles', 'zinn-seo' ),
		ownership: __( 'Ownership and funding', 'zinn-seo' ),
		corrections: __( 'Corrections policy', 'zinn-seo' ),
		ethics: __( 'Ethics policy', 'zinn-seo' ),
		diversity: __( 'Diversity policy', 'zinn-seo' ),
		staffing: __( 'Diversity staffing report', 'zinn-seo' ),
		masthead: __( 'Masthead', 'zinn-seo' ),
		feedback: __( 'Feedback policy', 'zinn-seo' ),
		coverage: __( 'Mission and coverage priorities', 'zinn-seo' ),
		factcheck: __( 'Fact-checking policy', 'zinn-seo' ),
		unnamed: __( 'Unnamed sources policy', 'zinn-seo' ),
		nobylines: __( 'No-bylines policy', 'zinn-seo' ),
	};
}

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function richTabs( data = {} ) {
	const mods = data.modules || {};
	const known = ( id ) => id in mods;
	return [
		...( mods[ 'schema-extra' ]
			? [
					{ name: 'carousels', title: __( 'Carousels', 'zinn-seo' ) },
					{
						name: 'licences',
						title: __( 'Image licences', 'zinn-seo' ),
					},
				]
			: [] ),
		...( known( 'news' )
			? [ { name: 'news', title: __( 'News', 'zinn-seo' ) } ]
			: [] ),
		...( known( 'schema-extra-pro' )
			? [ { name: 'shop', title: __( 'Shop policies', 'zinn-seo' ) } ]
			: [] ),
		{
			name: 'pages',
			title: __( 'Datasets, solvers and FAQs', 'zinn-seo' ),
		},
	];
}

/**
 * Carousels.
 *
 * @param {Object} props
 * @param {Object} props.form Draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function CarouselsTab( { form, data } ) {
	const c = data.carousel || { types: [], kinds: [] };
	const set = form.value.carousel_types || {};
	const kindName = {
		auto: __( 'Decide for me', 'zinn-seo' ),
		off: __( 'No carousel', 'zinn-seo' ),
		Recipe: __( 'Recipes', 'zinn-seo' ),
		Course: __( 'Courses', 'zinn-seo' ),
		Movie: __( 'Films', 'zinn-seo' ),
		Restaurant: __( 'Restaurants', 'zinn-seo' ),
		Product: __( 'Products', 'zinn-seo' ),
	};
	const choose = ( type, item ) => {
		const next = { ...set };
		if ( 'auto' === item ) {
			delete next[ `post:${ type }` ];
		} else {
			next[ `post:${ type }` ] = { item };
		}
		form.change( { carousel_types: next } );
	};
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'A category or archive page that lists recipes, courses, films, restaurants or products can show in Google as a swipeable carousel. Say what each content type\'s entries are; "Decide for me" knows products, courses and posts with a recipe card in them.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="carousel" />
			</p>
			{ c.types.map( ( t ) => (
				<SelectControl
					key={ t.name }
					__nextHasNoMarginBottom
					label={ t.label }
					value={ ( set[ `post:${ t.name }` ] || {} ).item || 'auto' }
					options={ [ 'auto', 'off', ...c.kinds ].map( ( k ) => ( {
						value: k,
						label: kindName[ k ] || k,
					} ) ) }
					onChange={ ( v ) => choose( t.name, v ) }
				/>
			) ) }
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * Image licences.
 *
 * @param {Object} props
 * @param {Object} props.form Draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function LicencesTab( { form, data } ) {
	const field = ( name, label, type = 'text' ) => (
		<TextControl
			__nextHasNoMarginBottom
			type={ type }
			label={ label }
			value={ form.value[ name ] || '' }
			onChange={ ( v ) => form.change( { [ name ]: v } ) }
		/>
	);
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Google Images marks a picture "Licensable" when it knows who made it and where to license it. Set the defaults for your whole media library here; each image can say otherwise in its own details in the media library.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="image-licence" />
			</p>
			{ field( 'img_lic_creator', __( 'Creator', 'zinn-seo' ) ) }
			{ field( 'img_lic_credit', __( 'Credit line', 'zinn-seo' ) ) }
			{ field(
				'img_lic_copyright',
				__( 'Copyright notice', 'zinn-seo' )
			) }
			{ field(
				'img_lic_license',
				__( 'Licence address', 'zinn-seo' ),
				'url'
			) }
			{ field(
				'img_lic_acquire',
				__( 'Where to license an image', 'zinn-seo' ),
				'url'
			) }
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * News publishing (Pro).
 *
 * @param {Object} props
 * @param {Object} props.form Draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function NewsTab( { form, data } ) {
	if ( ! data.modules.news && 'free' === data.plan ) {
		return (
			<ProTeaser
				title={ __( 'News publishing', 'zinn-seo' ) }
				points={ [
					__(
						'Mark stories as news, reportage, analysis, opinion or a live blog.',
						'zinn-seo'
					),
					__(
						'Keep a story out of Google News, and tag companies by their stock ticker.',
						'zinn-seo'
					),
					__(
						"Publish your newsroom's policies and invite readers to prefer you on Google.",
						'zinn-seo'
					),
				] }
			/>
		);
	}
	if ( ! data.modules.news ) {
		return (
			<p className="zseo-muted">
				{ __(
					'Switch on News publishing under Settings, Features to use it.',
					'zinn-seo'
				) }
			</p>
		);
	}
	const policies = form.value.news_policies || {};
	const types = form.value.news_types || [ 'post' ];
	const all = ( data.carousel && data.carousel.types ) || [];
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Stories of the content types you choose are news articles; each story can say what kind it is, stay out of Google News or name the companies it is about, in its own Search and social panel.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="news" />
			</p>
			<PanelBody title={ __( 'What is news', 'zinn-seo' ) }>
				{ all.map( ( t ) => (
					<CheckboxControl
						key={ t.name }
						__nextHasNoMarginBottom
						label={ t.label }
						checked={ types.includes( t.name ) }
						onChange={ ( on ) =>
							form.change( {
								news_types: on
									? [ ...types, t.name ]
									: types.filter( ( x ) => x !== t.name ),
							} )
						}
					/>
				) ) }
			</PanelBody>
			<PanelBody
				title={ __( "Your newsroom's policies", 'zinn-seo' ) }
				initialOpen={ false }
			>
				<p className="zseo-muted">
					{ __(
						'The page of each policy you have. With at least one, your organisation is described as a news organisation.',
						'zinn-seo'
					) }
				</p>
				{ Object.entries( newsPolicies() ).map( ( [ key, label ] ) => (
					<TextControl
						key={ key }
						__nextHasNoMarginBottom
						type="url"
						label={ label }
						value={ ( policies[ key ] || {} ).url || '' }
						onChange={ ( v ) => {
							const next = { ...policies };
							if ( v ) {
								next[ key ] = { url: v };
							} else {
								delete next[ key ];
							}
							form.change( { news_policies: next } );
						} }
					/>
				) ) }
			</PanelBody>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Invite readers to add you as a preferred source on Google, after each story',
					'zinn-seo'
				) }
				help={ __(
					"A plain link to Google's own page for your site; no script. Or put [preferred_source] where you want it.",
					'zinn-seo'
				) }
				checked={ 'after' === form.value.news_source_link }
				onChange={ ( on ) =>
					form.change( { news_source_link: on ? 'after' : '' } )
				}
			/>
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * The shop's policies for the whole shop (Pro).
 *
 * @param {Object} props
 * @param {Object} props.form Draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function ShopTab( { form, data } ) {
	const merch = data.merch || {};
	const tiers = form.value.loyalty_tiers || {};
	const [ name, setName ] = useState( '' );
	if ( ! data.modules[ 'schema-extra-pro' ] ) {
		return (
			<ProTeaser
				title={ __( 'Shop policies in search', 'zinn-seo' ) }
				points={ [
					__(
						'Tell Google your returns, delivery and loyalty programme once, for the whole shop.',
						'zinn-seo'
					),
					__(
						'Read from your WooCommerce shipping zones and your returns settings.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	const setTier = ( key, part ) =>
		form.change( {
			loyalty_tiers: {
				...tiers,
				[ key ]: { ...( tiers[ key ] || {} ), ...part },
			},
		} );
	const dropTier = ( key ) => {
		const next = { ...tiers };
		delete next[ key ];
		form.change( { loyalty_tiers: next } );
	};
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					"Google reads a shop's returns, delivery and loyalty programme once for the whole shop. Delivery comes from your WooCommerce shipping zones and returns from Works with, Commerce; the loyalty programme from here.",
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="merchant-policies" />
			</p>
			{ ! merch.woo && (
				<p className="zseo-muted">
					{ __(
						'WooCommerce is not active: only the returns and loyalty programme can be said.',
						'zinn-seo'
					) }
				</p>
			) }
			{ merch.woo && (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %d: a number of shipping zones. */
						__( 'Shipping zones read: %d.', 'zinn-seo' ),
						merch.zones || 0
					) }
				</p>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					"Tell search engines the shop's policies",
					'zinn-seo'
				) }
				checked={ !! form.value.merchant_org }
				onChange={ ( on ) => form.change( { merchant_org: on } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __(
					'Your policies page (besides the home page)',
					'zinn-seo'
				) }
				value={ String( form.value.merchant_page || 0 ) }
				options={ [
					{ value: '0', label: __( 'Home page only', 'zinn-seo' ) },
					...( merch.pages || [] ).map( ( p ) => ( {
						value: String( p.id ),
						label: p.title,
					} ) ),
				] }
				onChange={ ( v ) =>
					form.change( { merchant_page: parseInt( v, 10 ) || 0 } )
				}
			/>
			<PanelBody
				title={ __( 'Loyalty programme', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'zinn-seo' ) }
					value={ form.value.loyalty_name || '' }
					onChange={ ( v ) => form.change( { loyalty_name: v } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'What members get', 'zinn-seo' ) }
					value={ form.value.loyalty_description || '' }
					onChange={ ( v ) =>
						form.change( { loyalty_description: v } )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Sign-up page', 'zinn-seo' ) }
					value={ form.value.loyalty_url || '' }
					onChange={ ( v ) => form.change( { loyalty_url: v } ) }
				/>
				{ Object.entries( tiers ).map( ( [ key, t ] ) => (
					<fieldset key={ key } className="zseo-x1c-box">
						<legend>{ t.name || key }</legend>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Tier name', 'zinn-seo' ) }
							value={ t.name || '' }
							onChange={ ( v ) => setTier( key, { name: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							label={ __(
								'Points per unit of money spent (empty: no points)',
								'zinn-seo'
							) }
							value={ t.points || '' }
							onChange={ ( v ) => setTier( key, { points: v } ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Members get special prices',
								'zinn-seo'
							) }
							checked={ !! t.price }
							onChange={ ( v ) => setTier( key, { price: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							label={ __(
								'Membership fee (empty: free)',
								'zinn-seo'
							) }
							value={ t.fee || '' }
							onChange={ ( v ) => setTier( key, { fee: v } ) }
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () => dropTier( key ) }
						>
							{ __( 'Remove the tier', 'zinn-seo' ) }
						</Button>
					</fieldset>
				) ) }
				<div className="zseo-x1c-row">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'New tier', 'zinn-seo' ) }
						value={ name }
						onChange={ setName }
					/>
					<Button
						variant="secondary"
						disabled={ ! name.trim() }
						onClick={ () => {
							const key =
								name
									.toLowerCase()
									.replace( /[^a-z0-9]+/g, '-' )
									.replace( /^-|-$/g, '' ) ||
								`tier-${ Object.keys( tiers ).length + 1 }`;
							setTier( key, { name } );
							setName( '' );
						} }
					>
						{ __( 'Add the tier', 'zinn-seo' ) }
					</Button>
				</div>
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}

/**
 * Where the per-page structured data is set.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
function PagesTab( { data } ) {
	return (
		<div className="zseo-stack zseo-x1c">
			<p>
				{ __(
					"Some structured data belongs to one page and is set in that page's editor, under Search and social:",
					'zinn-seo'
				) }
			</p>
			<ul className="zseo-x1c-checks">
				<li>
					{ __(
						'Dataset — the data a research page offers, with its downloads (Pro).',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="dataset" />
				</li>
				<li>
					{ __(
						'Math solver — a calculator or equation solver page (Pro).',
						'zinn-seo'
					) }
				</li>
				<li>
					{ __(
						'FAQ from accordions — switch "Show as FAQ in search results" on an accordion block, Elementor\'s "FAQ Schema", or the CSS class faq-schema on a Divi or Bricks accordion (Pro).',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="accordion-faq" />
				</li>
				<li>
					{ __(
						'Quizzes of LearnDash, Tutor LMS and LifterLMS — switch them on under Works with, More (Pro).',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="lms-quiz" />
				</li>
			</ul>
		</div>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function RichResultsScreen( { data } ) {
	const form = useDraft();
	const tabs = richTabs( data );
	// A link to one tab (`#/news`, from a guide or the command palette) opens that tab.
	const initial = String( window.location.hash || '' ).replace( /^#\/?/, '' );
	return (
		<Ready form={ form }>
			<div className="zseo-x1c-screen">
				<TabPanel
					className="zseo-tabs"
					tabs={ tabs }
					initialTabName={
						tabs.some( ( t ) => t.name === initial )
							? initial
							: undefined
					}
				>
					{ ( tab ) => {
						switch ( tab.name ) {
							case 'carousels':
								return (
									<CarouselsTab form={ form } data={ data } />
								);
							case 'licences':
								return (
									<LicencesTab form={ form } data={ data } />
								);
							case 'news':
								return <NewsTab form={ form } data={ data } />;
							case 'shop':
								return <ShopTab form={ form } data={ data } />;
							default:
								return <PagesTab data={ data } />;
						}
					} }
				</TabPanel>
			</div>
		</Ready>
	);
}
