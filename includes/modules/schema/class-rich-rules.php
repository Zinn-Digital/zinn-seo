<?php
/**
 * Google's rich-result rules, offline (zs-035, zs-inn-08).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Google Search needs before it shows a rich result for a type, and whether it still shows
 * one at all. Other SEO plugins send you to Google's online test; this answers in the editor.
 *
 * ⛔ STATUS IS A MEASUREMENT WITH A DATE, NOT A BELIEF. Read 2026-10-09 from
 * https://developers.google.com/search/updates and the search gallery:
 * - FAQ rich results: no longer shown from 7 May 2026 (deprecation notice 8 May 2026).
 * - How-to rich results: removed in September 2023.
 * - Sitelinks search box (WebSite + SearchAction): removed in November 2024.
 * - Book actions, Course info, ClaimReview, Estimated salary, Learning video, Special announcement,
 *   Vehicle listing: phased out from June 2025 (Search Console reporting removed 9 Sept 2025).
 * - Practice problems: removed November 2025.
 * - Breadcrumbs: desktop results only since January 2025.
 * - Review snippets: Book, Recipe, Movie, Product, Software app and Local business; reviews a
 *   business writes about itself are not eligible.
 * A retired feature's markup still describes the page to Bing, AI assistants and Google's other
 * systems, so the validator says "not shown by Google" — it never calls valid markup wrong.
 * Re-check this list when Google's changelog moves (the date above is the evidence).
 */
final class Rich_Rules {

	/** When the statuses below were last read from Google's documentation. */
	public const CHECKED = '2026-10-09';

	/**
	 * Rules by type: `feature` (name), `status` (active, limited, retired), `note`, `required`
	 * and `recommended` (property paths; `a|b` = either), `types` (allowed nested types).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$article = array(
			'feature'     => __( 'Article', 'zinn-seo' ),
			'status'      => 'active',
			'required'    => array(),
			'recommended' => array( 'headline', 'image', 'datePublished', 'dateModified', 'author', 'author.name' ),
		);

		return array(
			'Article'             => $article,
			'NewsArticle'         => $article,
			'BlogPosting'         => $article,
			'TechArticle'         => $article,
			'BreadcrumbList'      => array(
				'feature'     => __( 'Breadcrumb', 'zinn-seo' ),
				'status'      => 'active',
				'note'        => __( 'Google shows breadcrumbs in desktop results only (since January 2025).', 'zinn-seo' ),
				'required'    => array( 'itemListElement', 'itemListElement.position', 'itemListElement.name' ),
				'recommended' => array(),
			),
			'FAQPage'             => array(
				'feature'     => __( 'FAQ', 'zinn-seo' ),
				'status'      => 'retired',
				'note'        => __( 'Google stopped showing FAQ rich results on 7 May 2026. Bing and AI assistants still read FAQ structured data, so it is still worth having.', 'zinn-seo' ),
				'required'    => array( 'mainEntity', 'mainEntity.name', 'mainEntity.acceptedAnswer', 'mainEntity.acceptedAnswer.text' ),
				'recommended' => array(),
			),
			'HowTo'               => array(
				'feature'     => __( 'How-to', 'zinn-seo' ),
				'status'      => 'retired',
				'note'        => __( 'Google stopped showing how-to rich results in September 2023. Other search engines and AI assistants still read the steps.', 'zinn-seo' ),
				'required'    => array( 'name', 'step', 'step.text' ),
				'recommended' => array( 'totalTime', 'image', 'supply', 'tool', 'estimatedCost' ),
			),
			'QAPage'              => array(
				'feature'     => __( 'Q&A', 'zinn-seo' ),
				'status'      => 'active',
				'note'        => __( 'Google shows Q&A results for pages where people answer a question (forums, support communities), not for a question the page itself answers.', 'zinn-seo' ),
				'required'    => array( 'mainEntity', 'mainEntity.name', 'mainEntity.answerCount', 'mainEntity.acceptedAnswer|mainEntity.suggestedAnswer' ),
				'recommended' => array( 'mainEntity.text', 'mainEntity.author', 'mainEntity.datePublished' ),
			),
			'Review'              => array(
				'feature'     => __( 'Review snippet', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'itemReviewed', 'itemReviewed.name', 'reviewRating', 'reviewRating.ratingValue', 'author', 'author.name' ),
				'recommended' => array( 'datePublished', 'reviewRating.bestRating', 'reviewRating.worstRating' ),
				'note'        => __( 'Google shows review snippets for books, recipes, films, products, software and local businesses — and not for reviews a business writes about itself.', 'zinn-seo' ),
				'eligible'    => array( 'Book', 'Recipe', 'Movie', 'Product', 'SoftwareApplication', 'LocalBusiness' ),
			),
			'Product'             => array(
				'feature'     => __( 'Product snippet', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'review|aggregateRating|offers' ),
				'recommended' => array( 'image', 'description', 'brand', 'offers.price', 'offers.priceCurrency', 'offers.availability' ),
			),
			'SoftwareApplication' => array(
				'feature'     => __( 'Software app', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'offers|offers.price', 'aggregateRating|review' ),
				'recommended' => array( 'applicationCategory', 'operatingSystem' ),
			),
			'Recipe'              => array(
				'feature'     => __( 'Recipe', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'image' ),
				'recommended' => array( 'author', 'datePublished', 'description', 'prepTime', 'cookTime', 'totalTime', 'recipeYield', 'recipeIngredient', 'recipeInstructions', 'recipeCategory', 'recipeCuisine', 'keywords', 'nutrition.calories', 'aggregateRating', 'video' ),
			),
			'Event'               => array(
				'feature'     => __( 'Event', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'startDate', 'location' ),
				'recommended' => array( 'description', 'endDate', 'eventStatus', 'eventAttendanceMode', 'image', 'offers', 'organizer', 'performer' ),
			),
			'JobPosting'          => array(
				'feature'     => __( 'Job posting', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation|applicantLocationRequirements' ),
				'recommended' => array( 'baseSalary', 'employmentType', 'validThrough', 'identifier', 'directApply' ),
			),
			'Course'              => array(
				'feature'     => __( 'Course', 'zinn-seo' ),
				'status'      => 'retired',
				'note'        => __( 'Google stopped showing course info results in 2025 (course lists in a carousel still show).', 'zinn-seo' ),
				'required'    => array( 'name', 'description', 'provider' ),
				'recommended' => array( 'offers', 'hasCourseInstance' ),
			),
			'Book'                => array(
				'feature'     => __( 'Book', 'zinn-seo' ),
				'status'      => 'retired',
				'note'        => __( 'Google stopped showing book actions in 2025; book reviews still show as review snippets.', 'zinn-seo' ),
				'required'    => array( 'name', 'author' ),
				'recommended' => array( 'isbn', 'workExample', 'url' ),
			),
			'Movie'               => array(
				'feature'     => __( 'Movie carousel', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'image' ),
				'recommended' => array( 'dateCreated', 'director', 'review|aggregateRating' ),
			),
			'VideoObject'         => array(
				'feature'     => __( 'Video', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'thumbnailUrl', 'uploadDate' ),
				'recommended' => array( 'description', 'duration', 'contentUrl|embedUrl', 'hasPart' ),
			),
			'Clip'                => array(
				'feature'     => __( 'Key moments', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'startOffset', 'url' ),
				'recommended' => array( 'endOffset' ),
			),
			'LocalBusiness'       => array(
				'feature'     => __( 'Local business', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name', 'address' ),
				'recommended' => array( 'telephone', 'url', 'geo', 'openingHoursSpecification', 'priceRange', 'image' ),
			),
			'Organization'        => array(
				'feature'     => __( 'Organization', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'name' ),
				'recommended' => array( 'url', 'logo', 'sameAs', 'contactPoint' ),
			),
			'ClaimReview'         => array(
				'feature'     => __( 'Fact check', 'zinn-seo' ),
				'status'      => 'retired',
				'note'        => __( 'Google stopped showing fact-check snippets in Search in 2025. Fact-checking tools and other search engines still read ClaimReview.', 'zinn-seo' ),
				'required'    => array( 'claimReviewed', 'reviewRating', 'reviewRating.ratingValue|reviewRating.alternateName', 'url', 'author' ),
				'recommended' => array( 'itemReviewed', 'datePublished' ),
			),
			'ItemList'            => array(
				'feature'     => __( 'Carousel', 'zinn-seo' ),
				'status'      => 'limited',
				'note'        => __( 'Google shows a carousel only for lists of recipes, courses, restaurants or films.', 'zinn-seo' ),
				'required'    => array( 'itemListElement', 'itemListElement.position' ),
				'recommended' => array(),
			),
			'ProfilePage'         => array(
				'feature'     => __( 'Profile page', 'zinn-seo' ),
				'status'      => 'active',
				'required'    => array( 'mainEntity', 'mainEntity.name' ),
				'recommended' => array( 'dateCreated', 'dateModified', 'mainEntity.image', 'mainEntity.sameAs' ),
			),
			'WebSite'             => array(
				'feature'     => __( 'Site name', 'zinn-seo' ),
				'status'      => 'active',
				'note'        => __( 'Google reads the site\'s name from here. The search box it used to show under results (SearchAction) was removed in November 2024; other engines may still use it.', 'zinn-seo' ),
				'required'    => array( 'name', 'url' ),
				'recommended' => array( 'alternateName' ),
			),
		);
	}

	/**
	 * The rule for a node's type (its first type that has one, or one of its ancestors').
	 *
	 * @param array<int, string> $types Node types.
	 * @return array<string, mixed>|null
	 */
	public static function for_types( array $types ): ?array {
		$all = self::all();
		foreach ( $types as $type ) {
			if ( isset( $all[ $type ] ) ) {
				return $all[ $type ] + array( 'type' => $type );
			}
		}
		foreach ( $types as $type ) {
			foreach ( Vocab::ancestors( $type ) as $anc ) {
				if ( isset( $all[ $anc ] ) && in_array( $anc, array( 'LocalBusiness', 'Organization', 'Article' ), true ) ) {
					return $all[ $anc ] + array( 'type' => $anc );
				}
			}
		}

		return null;
	}
}
