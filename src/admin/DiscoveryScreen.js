import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, TabPanel } from '@wordpress/components';

import { errorText, useSettings } from './api';
import SitemapsTab from './discovery/SitemapsTab';
import RobotsTab from './discovery/RobotsTab';
import CrawlersTab from './discovery/CrawlersTab';
import LlmsTab from './discovery/LlmsTab';
import IndexingTab from './discovery/IndexingTab';
import { ModuleGate } from './discovery/shared';

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function discoveryTabs() {
	return [
		{ name: 'sitemaps', title: __( 'Sitemaps', 'zinn-seo' ) },
		{ name: 'robots', title: __( 'robots.txt', 'zinn-seo' ) },
		{ name: 'ai', title: __( 'AI crawlers', 'zinn-seo' ) },
		{ name: 'llms', title: __( 'llms.txt', 'zinn-seo' ) },
		{ name: 'indexing', title: __( 'Indexing', 'zinn-seo' ) },
	];
}

/**
 * Sitemaps, robots.txt, AI crawlers, llms.txt and indexing: how search engines and AI assistants
 * find the site. Settings are edited as a draft and saved together; the editors with their own
 * files (robots.txt) save on their own.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function DiscoveryScreen( { data } ) {
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
					text: __(
						'Saved. The sitemap and llms.txt update in the background.',
						'zinn-seo'
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	const props = { value, onChange: change, isPro, data, saved: settings };

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
				tabs={ discoveryTabs() }
				initialTabName={
					discoveryTabs().some( ( t ) => t.name === initial )
						? initial
						: 'sitemaps'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'robots':
							return (
								<ModuleGate
									id="robots"
									label={ __(
										'Manage robots.txt',
										'zinn-seo'
									) }
									{ ...props }
								>
									<RobotsTab { ...props } />
								</ModuleGate>
							);
						case 'ai':
							return (
								<ModuleGate
									id="crawlers"
									label={ __(
										'Choose which AI crawlers may read the site',
										'zinn-seo'
									) }
									{ ...props }
								>
									<CrawlersTab { ...props } />
								</ModuleGate>
							);
						case 'llms':
							return (
								<ModuleGate
									id="llms"
									label={ __(
										'Publish llms.txt',
										'zinn-seo'
									) }
									{ ...props }
								>
									<LlmsTab { ...props } />
								</ModuleGate>
							);
						case 'indexing':
							return (
								<ModuleGate
									id="robots"
									label={ __(
										'Manage robots.txt and indexing',
										'zinn-seo'
									) }
									{ ...props }
								>
									<IndexingTab { ...props } />
								</ModuleGate>
							);
					}
					return (
						<ModuleGate
							id="sitemaps"
							label={ __( 'Build XML sitemaps', 'zinn-seo' ) }
							{ ...props }
						>
							<SitemapsTab { ...props } />
						</ModuleGate>
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
