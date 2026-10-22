import { __ } from '@wordpress/i18n';
import {
	Button,
	ExternalLink,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { IdentityFields } from '../SiteFields';
import GuideLink from '../p7/GuideLink';

/**
 * The contact kinds Google lists, as the screen names them.
 *
 * @return {Object} Key => label.
 */
function contactLabels() {
	return {
		'customer-support': __( 'Customer support', 'zinn-seo' ),
		'technical-support': __( 'Technical support', 'zinn-seo' ),
		'billing-support': __( 'Billing support', 'zinn-seo' ),
		'bill-payment': __( 'Bill payment', 'zinn-seo' ),
		sales: __( 'Sales', 'zinn-seo' ),
		reservations: __( 'Reservations', 'zinn-seo' ),
		'credit-card-support': __( 'Credit card support', 'zinn-seo' ),
		emergency: __( 'Emergency', 'zinn-seo' ),
		'baggage-tracking': __( 'Baggage tracking', 'zinn-seo' ),
		'roadside-assistance': __( 'Roadside assistance', 'zinn-seo' ),
		'package-tracking': __( 'Package tracking', 'zinn-seo' ),
	};
}

/**
 * Who runs the site and how to reach them: the site's knowledge graph (zs-030).
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function GraphTab( { value, onChange, data } ) {
	const found = ( data.setup && data.setup.found ) || {};
	const contacts = value.identity_contacts || {};
	const labels = contactLabels();
	const unused = Object.keys( labels ).filter( ( k ) => ! contacts[ k ] );
	const setContact = ( kind, part ) =>
		onChange( {
			identity_contacts: {
				...contacts,
				[ kind ]: { ...( contacts[ kind ] || {} ), ...part },
			},
		} );
	const removeContact = ( kind ) => {
		const next = { ...contacts };
		delete next[ kind ];
		onChange( { identity_contacts: next } );
	};
	const isOrg = 'person' !== ( value.identity_type || 'organization' );

	return (
		<div className="zseo-stack">
			<PanelBody
				title={ __( 'The site’s name in search results', 'zinn-seo' ) }
			>
				<p className="zseo-muted">
					{ __(
						'Google shows a site name above each result. Empty: the site title from Settings, General.',
						'zinn-seo'
					) }
				</p>
				<GuideLink data={ data } id="knowledge-graph" />
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Site name', 'zinn-seo' ) }
					value={ value.website_name || '' }
					onChange={ ( v ) => onChange( { website_name: v } ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Other name for the site', 'zinn-seo' ) }
					help={ __(
						'An abbreviation or another name Google may show instead.',
						'zinn-seo'
					) }
					value={ value.website_alt_name || '' }
					onChange={ ( v ) => onChange( { website_alt_name: v } ) }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Who runs the site', 'zinn-seo' ) }>
				<p className="zseo-muted">
					{ __(
						'Search engines and AI assistants build their picture of a site from this: the name, logo and profiles become the knowledge panel and the "publisher" of every article.',
						'zinn-seo'
					) }
				</p>
				<IdentityFields
					value={ value }
					onChange={ onChange }
					found={ found }
				/>
				{ isOrg && (
					<div className="zseo-stack">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Kind of organisation', 'zinn-seo' ) }
							help={ __(
								'A schema.org type, for example NewsMediaOrganization, Corporation or EducationalOrganization. Empty: Organization.',
								'zinn-seo'
							) }
							dir="ltr"
							value={ value.identity_org_type || '' }
							onChange={ ( v ) =>
								onChange( {
									identity_org_type: v.replace(
										/[^A-Za-z]/g,
										''
									),
								} )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Other name or abbreviation',
								'zinn-seo'
							) }
							value={ value.identity_alt_name || '' }
							onChange={ ( v ) =>
								onChange( { identity_alt_name: v } )
							}
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'What the organisation does (one or two sentences)',
								'zinn-seo'
							) }
							rows={ 3 }
							value={ value.identity_description || '' }
							onChange={ ( v ) =>
								onChange( { identity_description: v } )
							}
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Founded (year, or YYYY-MM-DD)',
								'zinn-seo'
							) }
							value={ value.identity_founded || '' }
							onChange={ ( v ) =>
								onChange( { identity_founded: v } )
							}
						/>
						{ [
							[
								'legalName',
								__( 'Registered company name', 'zinn-seo' ),
							],
							[ 'taxID', __( 'Tax number', 'zinn-seo' ) ],
							[ 'vatID', __( 'VAT number', 'zinn-seo' ) ],
							[
								'iso6523Code',
								__(
									'Company register code (ISO 6523)',
									'zinn-seo'
								),
							],
							[ 'duns', __( 'DUNS number', 'zinn-seo' ) ],
							[
								'numberOfEmployees',
								__(
									'Number of staff (a number, or a range such as 11–50)',
									'zinn-seo'
								),
							],
							[ 'email', __( 'Company e-mail', 'zinn-seo' ) ],
							[
								'telephone',
								__( 'Main telephone number', 'zinn-seo' ),
							],
							[
								'streetAddress',
								__( 'Street address', 'zinn-seo' ),
							],
							[
								'addressLocality',
								__( 'Town or city', 'zinn-seo' ),
							],
							[
								'addressRegion',
								__( 'Region or county', 'zinn-seo' ),
							],
							[ 'postalCode', __( 'Postcode', 'zinn-seo' ) ],
							[
								'addressCountry',
								__(
									'Country (two-letter code, such as GB)',
									'zinn-seo'
								),
							],
						].map( ( [ key, label ] ) => (
							<TextControl
								key={ key }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ label }
								dir={
									[
										'legalName',
										'streetAddress',
										'addressLocality',
										'addressRegion',
									].includes( key )
										? undefined
										: 'ltr'
								}
								value={
									( value.identity_legal?.org || {} )[
										key
									] || ''
								}
								onChange={ ( v ) =>
									onChange( {
										identity_legal: {
											...( value.identity_legal || {} ),
											org: {
												...( value.identity_legal
													?.org || {} ),
												[ key ]: v,
											},
										},
									} )
								}
							/>
						) ) }
					</div>
				) }
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					id="zseo-identity-tiktok"
					label={ __( 'TikTok username', 'zinn-seo' ) }
					help={ __(
						'For example @yourbrand. Search engines and AI assistants then know the TikTok account is yours.',
						'zinn-seo'
					) }
					dir="ltr"
					value={ value.identity_tiktok || '' }
					onChange={ ( v ) => onChange( { identity_tiktok: v } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'Profiles elsewhere (one address per line: LinkedIn, Facebook, X, Instagram, YouTube, Wikipedia, Wikidata…)',
						'zinn-seo'
					) }
					rows={ 5 }
					dir="ltr"
					value={ ( value.identity_same_as || [] ).join( '\n' ) }
					onChange={ ( v ) =>
						onChange( {
							identity_same_as: v
								.split( /\r\n|\r|\n/ )
								.map( ( l ) => l.trim() )
								.filter( ( l, i, all ) =>
									l ? true : i === all.length - 1
								),
						} )
					}
				/>
			</PanelBody>
			{ isOrg && (
				<PanelBody
					title={ __( 'How to reach you', 'zinn-seo' ) }
					initialOpen={ Object.keys( contacts ).length > 0 }
				>
					{ Object.keys( contacts ).map( ( kind ) => (
						<fieldset key={ kind } className="zseo-card-row">
							<legend className="zseo-legend">
								{ labels[ kind ] || kind }
							</legend>
							<div className="zseo-grid-2">
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Phone', 'zinn-seo' ) }
									type="tel"
									value={ contacts[ kind ].telephone || '' }
									onChange={ ( v ) =>
										setContact( kind, { telephone: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Email', 'zinn-seo' ) }
									type="email"
									value={ contacts[ kind ].email || '' }
									onChange={ ( v ) =>
										setContact( kind, { email: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'Contact page', 'zinn-seo' ) }
									type="url"
									value={ contacts[ kind ].url || '' }
									onChange={ ( v ) =>
										setContact( kind, { url: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Countries served (e.g. GB, IE)',
										'zinn-seo'
									) }
									value={ contacts[ kind ].area || '' }
									onChange={ ( v ) =>
										setContact( kind, { area: v } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Languages (e.g. English, Arabic)',
										'zinn-seo'
									) }
									value={ contacts[ kind ].languages || '' }
									onChange={ ( v ) =>
										setContact( kind, { languages: v } )
									}
								/>
							</div>
							<Button
								variant="link"
								isDestructive
								onClick={ () => removeContact( kind ) }
							>
								{ __( 'Remove', 'zinn-seo' ) }
							</Button>
						</fieldset>
					) ) }
					{ unused.length > 0 && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Add a way to reach you', 'zinn-seo' ) }
							value=""
							options={ [
								{
									value: '',
									label: __( 'Choose…', 'zinn-seo' ),
								},
								...unused.map( ( k ) => ( {
									value: k,
									label: labels[ k ],
								} ) ),
							] }
							onChange={ ( k ) => k && setContact( k, {} ) }
						/>
					) }
				</PanelBody>
			) }
			<PanelBody title={ __( 'Across the site', 'zinn-seo' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Breadcrumb trail in the structured data',
						'zinn-seo'
					) }
					help={ __(
						'Lets search engines show the path to a page (Home › Category › Page) under its result.',
						'zinn-seo'
					) }
					checked={ value.schema_breadcrumbs !== false }
					onChange={ ( v ) => onChange( { schema_breadcrumbs: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'List the products on shop pages',
						'zinn-seo'
					) }
					help={ __(
						'Shop, category and tag pages tell search engines which products they show, with price and stock.',
						'zinn-seo'
					) }
					checked={ !! value.schema_product_lists }
					onChange={ ( v ) =>
						onChange( { schema_product_lists: v } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Give an article’s picture in three shapes',
						'zinn-seo'
					) }
					help={ __(
						'The featured image cut to wide (16:9), 4:3 and square copies at least 1,200 pixels wide, plus the pictures in the text, as Google’s article guide suggests.',
						'zinn-seo'
					) }
					checked={ !! value.schema_article_images }
					onChange={ ( v ) =>
						onChange( { schema_article_images: v } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Describe each article with its excerpt',
						'zinn-seo'
					) }
					help={ __(
						'The excerpt, or the first 55 words of the text, on the article itself as well as on its page.',
						'zinn-seo'
					) }
					checked={ !! value.schema_article_desc }
					onChange={ ( v ) => onChange( { schema_article_desc: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Describe the site search', 'zinn-seo' ) }
					help={ __(
						'Tells search engines how to search this site. Google stopped showing its search box under results in November 2024; other engines and assistants still read it.',
						'zinn-seo'
					) }
					checked={ value.search_action !== false }
					onChange={ ( v ) => onChange( { search_action: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Join other plugins’ structured data to this graph',
						'zinn-seo'
					) }
					help={ __(
						'WooCommerce products and Page Builder Sandwich blocks are printed inside this one graph instead of in second and third scripts.',
						'zinn-seo'
					) }
					checked={ value.schema_absorb !== false }
					onChange={ ( v ) => onChange( { schema_absorb: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Publish the site graph for AI agents',
						'zinn-seo'
					) }
					help={ __(
						'One address where AI agents read every page’s type, title, summary and dates as schema.org data, and any page’s full graph — instead of crawling every page. Listed in llms.txt.',
						'zinn-seo'
					) }
					checked={ value.schema_feed !== false }
					onChange={ ( v ) => onChange( { schema_feed: v } ) }
				/>
				{ value.schema_feed !== false && data.schema && (
					<p>
						<ExternalLink href={ data.schema.feedUrl }>
							{ data.schema.feedUrl }
						</ExternalLink>
					</p>
				) }
			</PanelBody>
		</div>
	);
}
