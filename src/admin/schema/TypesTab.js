import { __, sprintf } from '@wordpress/i18n';
import { PanelBody, SelectControl } from '@wordpress/components';

/**
 * Names people know for the schema.org page and article types.
 *
 * @return {Object} Type => label.
 */
export function typeLabels() {
	return {
		WebPage: __( 'Ordinary page', 'zinn-seo' ),
		AboutPage: __( 'About page', 'zinn-seo' ),
		ContactPage: __( 'Contact page', 'zinn-seo' ),
		CheckoutPage: __( 'Checkout page', 'zinn-seo' ),
		CollectionPage: __( 'List of things', 'zinn-seo' ),
		ItemPage: __( 'Page about one thing', 'zinn-seo' ),
		MedicalWebPage: __( 'Medical page', 'zinn-seo' ),
		ProfilePage: __( 'Profile page', 'zinn-seo' ),
		QAPage: __( 'Question and answers', 'zinn-seo' ),
		FAQPage: __( 'Questions page', 'zinn-seo' ),
		RealEstateListing: __( 'Property listing', 'zinn-seo' ),
		SearchResultsPage: __( 'Search results', 'zinn-seo' ),
		Article: __( 'Article', 'zinn-seo' ),
		BlogPosting: __( 'Blog post', 'zinn-seo' ),
		NewsArticle: __( 'News article', 'zinn-seo' ),
		TechArticle: __( 'Technical article', 'zinn-seo' ),
		ScholarlyArticle: __( 'Scholarly article', 'zinn-seo' ),
		Report: __( 'Report', 'zinn-seo' ),
		OpinionNewsArticle: __( 'Opinion piece', 'zinn-seo' ),
		AnalysisNewsArticle: __( 'News analysis', 'zinn-seo' ),
	};
}

/**
 * Options for a type select: a label people know, with the schema.org name after it.
 *
 * @param {Array<string>} types Types.
 * @return {Array<Object>} Options.
 */
export function typeOptions( types ) {
	const labels = typeLabels();
	return types.map( ( t ) => ( {
		value: t,
		label: labels[ t ] ? `${ labels[ t ] } (${ t })` : t,
	} ) );
}

/**
 * What each content type is, for search engines (zs-032).
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
export default function TypesTab( { value, onChange, data } ) {
	const s = data.schema || { postTypes: [], pageTypes: [], articleTypes: [] };
	const set = value.schema_types || {};
	const change = ( type, part ) => {
		const key = `post:${ type }`;
		const record = { ...( set[ key ] || {} ), ...part };
		Object.keys( record ).forEach( ( k ) => {
			if ( '' === record[ k ] ) {
				delete record[ k ];
			}
		} );
		const next = { ...set, [ key ]: record };
		if ( ! Object.keys( record ).length ) {
			delete next[ key ];
		}
		onChange( { schema_types: next } );
	};

	return (
		<PanelBody title={ __( 'What each kind of content is', 'zinn-seo' ) }>
			<p className="zseo-muted">
				{ __(
					'The page type tells search engines what a page is; the article type adds an article (with its author, dates and publisher) to posts. A single post can still choose its own in the editor.',
					'zinn-seo'
				) }
			</p>
			<div className="zseo-types">
				{ s.postTypes.map( ( t ) => {
					const own = set[ `post:${ t.name }` ] || {};
					const defArticle = t.defaults.article
						? sprintf(
								/* translators: %s: the default type. */
								__( 'Default (%s)', 'zinn-seo' ),
								typeLabels()[ t.defaults.article ] ||
									t.defaults.article
							)
						: __( 'Default (no article)', 'zinn-seo' );
					return (
						<fieldset key={ t.name } className="zseo-types__row">
							<legend className="zseo-legend">{ t.label }</legend>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Page type', 'zinn-seo' ) }
								value={ own.page || '' }
								options={ [
									{
										value: '',
										label: __(
											'Default (ordinary page)',
											'zinn-seo'
										),
									},
									...typeOptions( s.pageTypes ),
								] }
								onChange={ ( v ) =>
									change( t.name, { page: v } )
								}
							/>
							<SelectControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Article type', 'zinn-seo' ) }
								value={ own.article || '' }
								options={ [
									{ value: '', label: defArticle },
									{
										value: 'none',
										label: __( 'No article', 'zinn-seo' ),
									},
									...typeOptions( s.articleTypes ),
								] }
								onChange={ ( v ) =>
									change( t.name, { article: v } )
								}
							/>
						</fieldset>
					);
				} ) }
			</div>
		</PanelBody>
	);
}
