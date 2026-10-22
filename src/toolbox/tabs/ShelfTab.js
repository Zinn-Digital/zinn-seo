/**
 * Google Shopping (zs-522) and Amazon (zs-526): where your products come on the shelf, your
 * price against the cheapest seller, and who else sells them.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { TextControl, TextareaControl } from '@wordpress/components';

import { Table } from '../../tools/parts';
import { readerLocale } from '../../tools/pure';
import { lines, shelfPrice, unitsOf } from '../pure';
import {
	History,
	RunMoney,
	RunNow,
	RunState,
	Schedule,
	useRuns,
} from '../shared';

/**
 * Products from a text box: one per line, `title` or (Amazon) `title | ASIN`.
 *
 * @param {string}  text   Text.
 * @param {boolean} amazon Read an ASIN.
 * @return {Array<Object>} Products.
 */
export function productsFrom( text, amazon ) {
	return lines( text, 100 ).map( ( line ) => {
		const [ title, asin ] = line.split( '|' ).map( ( s ) => s.trim() );
		if ( amazon && /^[A-Za-z0-9]{10}$/.test( asin || '' ) ) {
			return { title, asin: asin.toUpperCase() };
		}
		if ( amazon && /^[A-Za-z0-9]{10}$/.test( title ) && ! asin ) {
			return { title: '', asin: title.toUpperCase() };
		}
		return { title: line };
	} );
}

/**
 * The tab.
 *
 * @param {Object}  props
 * @param {Object}  props.ctx    Shared state.
 * @param {boolean} props.amazon Amazon (else Google Shopping).
 * @return {Element} Tab.
 */
export default function ShelfTab( { ctx, amazon } ) {
	const product = amazon ? 'amazon' : 'shopping';
	const runs = useRuns( product );
	const [ text, setText ] = useState(
		( ctx.shopProducts || [] ).join( '\n' )
	);
	const [ sellers, setSellers ] = useState( ctx.siteName || '' );
	const params = {
		products: productsFrom( text, amazon ),
		sellers: lines( sellers.replace( /,/g, '\n' ), 10 ),
		location: ctx.market.location || '',
		language: ctx.market.language || '',
	};
	const units = unitsOf( product, params );
	const why = units ? '' : __( 'Add at least one product.', 'zinn-seo' );
	const run = runs.run;
	const rows = ( run && run.result && run.result.products ) || [];
	const locale = readerLocale();
	return (
		<div className="zseo-stack">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Your products', 'zinn-seo' ) }
				help={
					amazon
						? __(
								'One per line, as a shopper would search for it. Add your ASIN after a bar to follow that exact listing: “Blue widget | B08N5WRWNW”.',
								'zinn-seo'
							)
						: __(
								'One per line, as a shopper would search for it.',
								'zinn-seo'
							)
				}
				value={ text }
				onChange={ setText }
				rows={ 5 }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Your seller name', 'zinn-seo' ) }
				help={ __(
					'How the shelf names your shop, so we can tell which offer is yours. Separate several with commas.',
					'zinn-seo'
				) }
				value={ sellers }
				onChange={ setSellers }
			/>
			<RunNow
				product={ product }
				params={ params }
				units={ units }
				ctx={ ctx }
				label={
					amazon
						? __( 'Check Amazon', 'zinn-seo' )
						: __( 'Check Google Shopping', 'zinn-seo' )
				}
				why={ why }
				running={ !! run && 'queued' === run.status }
				onStarted={ runs.add }
			/>
			<Schedule
				product={ product }
				params={ params }
				units={ units }
				ctx={ ctx }
				why={ why }
				label={ __( 'Check it automatically', 'zinn-seo' ) }
			/>
			<History runs={ runs.runs } run={ run } show={ runs.show } />
			<RunState run={ run } />
			{ rows.length > 0 && (
				<Table
					label={ __( 'Your products on the shelf', 'zinn-seo' ) }
					rowKey={ ( r ) => r.asin || r.title }
					rows={ rows }
					columns={ [
						{
							id: 'p',
							label: __( 'Product', 'zinn-seo' ),
							render: ( r ) => r.title || r.asin,
						},
						{
							id: 'pos',
							label: __( 'Your place', 'zinn-seo' ),
							numeric: true,
							render: ( r ) => {
								if ( ! r.answered ) {
									return __(
										'not checked (refunded)',
										'zinn-seo'
									);
								}
								return (
									r.our_position ??
									__( 'not listed', 'zinn-seo' )
								);
							},
						},
						{
							id: 'price',
							label: __( 'Your price', 'zinn-seo' ),
							numeric: true,
							render: ( r ) =>
								shelfPrice(
									r.our_price_minor,
									r.currency,
									locale
								),
						},
						{
							id: 'cheap',
							label: __( 'Cheapest', 'zinn-seo' ),
							numeric: true,
							render: ( r ) =>
								r.cheapest_price_minor === null ||
								r.cheapest_price_minor === undefined
									? '—'
									: sprintf(
											/* translators: 1: a price, 2: a seller. */
											__( '%1$s (%2$s)', 'zinn-seo' ),
											shelfPrice(
												r.cheapest_price_minor,
												r.currency,
												locale
											),
											r.cheapest_seller
										),
						},
						{
							id: 'top',
							label: __( 'Top sellers', 'zinn-seo' ),
							render: ( r ) =>
								( r.offers || [] )
									.slice( 0, 3 )
									.map( ( o ) => o.seller )
									.join( ', ' ),
						},
					] }
				/>
			) }
			<RunMoney run={ run } />
		</div>
	);
}
