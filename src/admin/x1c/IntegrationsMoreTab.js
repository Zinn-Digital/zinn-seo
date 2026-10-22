/**
 * Works with → More (stage X1C): site search plugins (zs-702), listings (zs-735), WooCommerce local
 * pickup (zs-705) and LMS quizzes (zs-738).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	PanelBody,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { Ready, SaveBar, useDraft } from '../x1a/shared';

/**
 * What can happen to an ended listing.
 *
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function endedChoices() {
	return [
		{ value: 'keep', label: __( 'Keep the page as it is', 'zinn-seo' ) },
		{
			value: 'noindex',
			label: __( 'Keep it, out of search results', 'zinn-seo' ),
		},
		{ value: 'gone', label: __( 'Answer "gone" (410)', 'zinn-seo' ) },
		{
			value: 'redirect',
			label: __( 'Send visitors to the listings', 'zinn-seo' ),
		},
	];
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function IntegrationsMoreTab( { data } ) {
	const form = useDraft();
	const items = ( ( data.integrations || {} ).items || [] ).filter(
		( i ) => i.detected
	);
	const found = ( id ) => items.some( ( i ) => i.id === id );
	const [ note, setNote ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const sync = () =>
		seoFetch( '/integrations/pickup', { method: 'POST' } )
			.then( ( r ) =>
				setNote(
					sprintf(
						/* translators: 1: points added, 2: points updated. */
						__(
							'Pickup points: %1$d added, %2$d brought up to date.',
							'zinn-seo'
						),
						r.added,
						r.updated
					)
				)
			)
			.catch( ( e ) => setError( errorText( e ) ) );
	const searchPlugins = [
		'relevanssi',
		'searchwp',
		'elasticpress',
		'algolia',
	].filter( found );
	const listings = [
		'houzez',
		'estatik',
		'classified-listing',
		'motopress-hotel-booking',
		'vikbooking',
	].filter( found );
	return (
		<Ready form={ form }>
			<div className="zseo-stack zseo-x1c">
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				{ note && (
					<Notice status="success" onRemove={ () => setNote( '' ) }>
						{ note }
					</Notice>
				) }
				<PanelBody title={ __( 'Site search', 'zinn-seo' ) }>
					<p className="zseo-muted">
						{ searchPlugins.length
							? sprintf(
									/* translators: %s: plugin names. */
									__(
										'Your search plugin (%s) also finds pages by their SEO title and description.',
										'zinn-seo'
									),
									searchPlugins.join( ', ' )
								)
							: __(
									"With Relevanssi, SearchWP, ElasticPress or WP Search with Algolia, your site's search also finds pages by their SEO title and description.",
									'zinn-seo'
								) }{ ' ' }
						<GuideLink data={ data } id="search-plugins" />
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							"Leave pages hidden from search engines out of the site's own search",
							'zinn-seo'
						) }
						checked={ 'keep' !== form.value.search_feed_noindex }
						onChange={ ( on ) =>
							form.change( {
								search_feed_noindex: on ? 'exclude' : 'keep',
							} )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Property, classifieds and rentals',
						'zinn-seo'
					) }
					initialOpen={ listings.length > 0 }
				>
					<p className="zseo-muted">
						{ __(
							'Listings of Houzez, Estatik, Classified Listing, MotoPress Hotel Booking and VikBooking show in search with their price, rooms and place.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="listings" />
					</p>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'A sold or let listing', 'zinn-seo' ) }
						value={ form.value.listing_sold || 'keep' }
						options={ endedChoices() }
						onChange={ ( v ) => form.change( { listing_sold: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'An expired listing', 'zinn-seo' ) }
						value={ form.value.listing_expired || 'gone' }
						options={ endedChoices() }
						onChange={ ( v ) =>
							form.change( { listing_expired: v } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Your rooms and rentals are', 'zinn-seo' ) }
						value={ form.value.listing_rental_kind || 'vacation' }
						options={ [
							{
								value: 'vacation',
								label: __(
									'Holiday homes (vacation rentals)',
									'zinn-seo'
								),
							},
							{
								value: 'hotel',
								label: __( 'Hotel rooms', 'zinn-seo' ),
							},
						] }
						onChange={ ( v ) =>
							form.change( { listing_rental_kind: v } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'WooCommerce local pickup', 'zinn-seo' ) }
					initialOpen={ found( 'woo-pickup' ) }
				>
					<p className="zseo-muted">
						{ __(
							'Each of your business locations becomes a pickup point at checkout, with its address and opening hours. Pickup points you added yourself stay as they are.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="pickup" />
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Offer local pickup from my locations',
							'zinn-seo'
						) }
						checked={ !! form.value.shop_pickup_locations }
						onChange={ ( on ) =>
							form.change( { shop_pickup_locations: on } )
						}
					/>
					{ form.settings && form.settings.shop_pickup_locations && (
						<Button variant="secondary" onClick={ sync }>
							{ __( 'Update the pickup points now', 'zinn-seo' ) }
						</Button>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Quizzes in search', 'zinn-seo' ) }
					initialOpen={ false }
				>
					<p className="zseo-muted">
						{ __(
							"A quiz page anyone can open can show its questions and answers in Google's education results. This publishes the correct answers, so it is off until you choose it.",
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="lms-quiz" />
					</p>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show quiz questions and answers in search',
							'zinn-seo'
						) }
						checked={ !! form.value.lms_quiz_schema }
						onChange={ ( on ) =>
							form.change( { lms_quiz_schema: on } )
						}
					/>
				</PanelBody>
				<SaveBar form={ form } />
				<p className="zseo-muted">
					{ sprintf(
						/* translators: %d: a number of plugins. */
						_n(
							'%d plugin found on this site.',
							'%d plugins found on this site.',
							items.length,
							'zinn-seo'
						),
						items.length
					) }
				</p>
			</div>
		</Ready>
	);
}
