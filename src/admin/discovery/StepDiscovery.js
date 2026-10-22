import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { SelectControl, ToggleControl } from '@wordpress/components';

import { seoFetch } from '../api';
import { Address } from './shared';
import { presetOf } from './CrawlersTab';

/**
 * Setup: the sitemap address, the AI crawler choice and IndexNow.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The fields.
 */
export default function DiscoveryFields( { value, onChange } ) {
	const [ crawlers, setCrawlers ] = useState( null );
	const [ sitemap, setSitemap ] = useState( '' );

	useEffect( () => {
		seoFetch( '/crawlers' )
			.then( setCrawlers )
			.catch( () => setCrawlers( null ) );
		seoFetch( '/sitemaps' )
			.then( ( s ) => setSitemap( s.url ) )
			.catch( () => setSitemap( '' ) );
	}, [] );

	const choices = value.ai_crawlers || {};
	const rows = crawlers
		? crawlers.crawlers.map( ( c ) => ( {
				...c,
				allowed: c.id in choices ? !! choices[ c.id ] : true,
			} ) )
		: [];
	const preset = crawlers ? presetOf( crawlers.presets, rows ) : 'allow_all';
	const mods = value.modules || {};

	return (
		<div className="zseo-stack">
			<p>
				{ __(
					'Your XML sitemap lists every page search engines may index, and updates by itself when a page changes. Add it once in Google Search Console and Bing Webmaster Tools:',
					'zinn-seo'
				) }
			</p>
			{ sitemap && <Address href={ sitemap } /> }
			{ crawlers && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'AI crawlers', 'zinn-seo' ) }
					help={ __(
						'Assistants such as ChatGPT, Claude and Perplexity can only recommend pages they may read. Training crawlers collect pages for future models.',
						'zinn-seo'
					) }
					value={ 'custom' === preset ? 'allow_all' : preset }
					options={ [
						{
							value: 'allow_all',
							label: __(
								'Allow every AI crawler (best for AI visibility)',
								'zinn-seo'
							),
						},
						{
							value: 'block_train',
							label: __(
								'Allow AI answers, block training',
								'zinn-seo'
							),
						},
						{
							value: 'block_all',
							label: __( 'Block every AI crawler', 'zinn-seo' ),
						},
					] }
					onChange={ ( p ) => {
						const map = {};
						crawlers.presets[ p ].forEach( ( id ) => {
							map[ id ] = false;
						} );
						onChange( { ai_crawlers: map } );
					} }
				/>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Tell Bing and other IndexNow search engines about changes at once',
					'zinn-seo'
				) }
				help={ __(
					'Sends the public address of each page you publish, change or remove to IndexNow. Nothing else is sent.',
					'zinn-seo'
				) }
				checked={ !! mods.indexnow }
				onChange={ ( on ) =>
					onChange( { modules: { ...mods, indexnow: on } } )
				}
			/>
		</div>
	);
}
