/**
 * Redirects and 404: the redirect manager, the 404 monitor, import and export, address changes
 * and their settings (stage P5).
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, useSettings } from '../api';
import RedirectsTab from './RedirectsTab';
import NotfoundTab from './NotfoundTab';
import ImportTab from './ImportTab';
import AddressTab from './AddressTab';
import './p5.scss';

/**
 * The tabs (exported for the command palette).
 *
 * @param {Object} data Boot data.
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function redirectsTabs( data = {} ) {
	const mods = data.modules || {};
	return [
		{ name: 'redirects', title: __( 'Redirects', 'zinn-seo' ) },
		...( false !== mods.notfound
			? [ { name: 'notfound', title: __( '404 monitor', 'zinn-seo' ) } ]
			: [] ),
		{ name: 'import', title: __( 'Import and export', 'zinn-seo' ) },
		...( false !== mods[ 'migrate-safety' ]
			? [
					{
						name: 'address',
						title: __( 'Address changes', 'zinn-seo' ),
					},
				]
			: [] ),
		{ name: 'settings', title: __( 'Settings', 'zinn-seo' ) },
	];
}

/**
 * The redirect settings.
 *
 * @return {Element} Tab.
 */
function SettingsTab() {
	const { settings, save, error } = useSettings();
	const [ fail, setFail ] = useState( '' );
	const [ target, setTarget ] = useState( null );
	if ( ! settings ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const put = ( changes ) =>
		save( changes ).catch( ( e ) => setFail( errorText( e ) ) );
	return (
		<Card>
			<CardBody className="zseo-stack">
				{ fail && (
					<Notice status="error" onRemove={ () => setFail( '' ) }>
						{ fail }
					</Notice>
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Redirect automatically when a page’s address changes',
						'zinn-seo'
					) }
					help={ __(
						'A changed slug, parent or category writes a permanent redirect from the old address; a page with children gets one rule for everything below it.',
						'zinn-seo'
					) }
					checked={ !! settings.redirects_auto }
					onChange={ ( v ) => put( { redirects_auto: v } ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'When a page is deleted', 'zinn-seo' ) }
					value={ settings.redirects_on_trash }
					options={ [
						{
							value: 'parent',
							label: __(
								'Send visitors to its parent page or category',
								'zinn-seo'
							),
						},
						{
							value: '410',
							label: __( 'Answer “gone” (410)', 'zinn-seo' ),
						},
						{
							value: 'none',
							label: __( 'Do nothing', 'zinn-seo' ),
						},
					] }
					help={ __(
						'A published page, post or product. Publishing it again removes the redirect.',
						'zinn-seo'
					) }
					onChange={ ( v ) => put( { redirects_on_trash: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Point older redirects at the new address (no chains)',
						'zinn-seo'
					) }
					checked={ !! settings.redirects_collapse }
					onChange={ ( v ) => put( { redirects_collapse: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Keep the query string (?utm_source=…) when redirecting',
						'zinn-seo'
					) }
					checked={ !! settings.redirects_pass_query }
					onChange={ ( v ) => put( { redirects_pass_query: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Match capital letters exactly', 'zinn-seo' ) }
					help={ __(
						'Off: /About and /about are the same address.',
						'zinn-seo'
					) }
					checked={ !! settings.redirects_case_sensitive }
					onChange={ ( v ) => put( { redirects_case_sensitive: v } ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Other missing pages', 'zinn-seo' ) }
					value={ settings.redirects_404_fallback }
					options={ [
						{
							value: '',
							label: __(
								'Show the not-found page (recommended)',
								'zinn-seo'
							),
						},
						{
							value: 'home',
							label: __(
								'Send visitors to the home page',
								'zinn-seo'
							),
						},
						{
							value: 'url',
							label: __(
								'Send visitors to an address',
								'zinn-seo'
							),
						},
					] }
					help={ __(
						'Addresses no redirect answers. Search engines treat a missing page sent to the home page as “soft 404”: keep the not-found page unless you have a reason.',
						'zinn-seo'
					) }
					onChange={ ( v ) => put( { redirects_404_fallback: v } ) }
				/>
				{ 'url' === settings.redirects_404_fallback && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Address', 'zinn-seo' ) }
						type="url"
						className="zseo-address"
						value={
							null === target
								? settings.redirects_404_target
								: target
						}
						onChange={ setTarget }
						onBlur={ () =>
							null !== target &&
							put( { redirects_404_target: target } ).then( () =>
								setTarget( null )
							)
						}
					/>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function RedirectsScreen( { data } ) {
	const tabs = redirectsTabs( data );
	const initial = ( window.location.hash || '' )
		.replace( '#/', '' )
		.split( '?' )[ 0 ];
	return (
		<div className="zseo-admin zseo-p5">
			<TabPanel
				className="zseo-tabs"
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === initial )
						? initial
						: 'redirects'
				}
				onSelect={ ( name ) => {
					if (
						! ( window.location.hash || '' ).startsWith(
							`#/${ name }`
						)
					) {
						window.history.replaceState( null, '', `#/${ name }` );
					}
				} }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'notfound':
							return <NotfoundTab data={ data } />;
						case 'import':
							return <ImportTab data={ data } />;
						case 'address':
							return <AddressTab />;
						case 'settings':
							return data.canManage ? (
								<SettingsTab />
							) : (
								<Notice status="info" isDismissible={ false }>
									{ __(
										'Only people who may change site-wide SEO settings can change these.',
										'zinn-seo'
									) }
								</Notice>
							);
						default:
							return <RedirectsTab data={ data } />;
					}
				} }
			</TabPanel>
		</div>
	);
}
