import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	FormFileUpload,
	Notice,
	PanelBody,
	Spinner,
	TabPanel,
} from '@wordpress/components';

import { viewHref } from '../admin-kit/router';
import { errorText, seoFetch } from './api';
import ServerRulesTab from './x1a/ServerRulesTab';

/**
 * Show a value from a settings file in a sentence.
 *
 * @param {*} value Value.
 * @return {string} Text.
 */
export function show( value ) {
	if ( true === value ) {
		return __( 'on', 'zinn-seo' );
	}
	if ( false === value ) {
		return __( 'off', 'zinn-seo' );
	}
	if ( null === value || undefined === value || '' === value ) {
		return __( '(empty)', 'zinn-seo' );
	}
	return 'object' === typeof value
		? JSON.stringify( value )
		: String( value );
}

/**
 * What a settings file carries, what is adjusted and what never leaves the site, in plain words
 * (zs-249; owner 2026-10-15: the export must say so). Matches Transfer::export(): every setting
 * except those marked secret or `export => false`; a site address inside a value is moved to the
 * importing site's address; keys and connections are not settings, so they are never in it.
 *
 * @param {Object}  props
 * @param {boolean} props.bulk Whether this site has the Bulk editor (its spreadsheet export).
 * @return {Element} The explanation.
 */
export function TransferScope( { bulk = false } ) {
	return (
		<ul className="zseo-transfer-scope">
			<li>
				<strong>{ __( 'Copied:', 'zinn-seo' ) }</strong>{ ' ' }
				{ __(
					'your site-wide SEO settings — title and description templates, structured data and your organisation, sitemaps, robots rules, social sharing, breadcrumbs, and which modules are on.',
					'zinn-seo'
				) }
			</li>
			<li>
				<strong>{ __( 'Adjusted:', 'zinn-seo' ) }</strong>{ ' ' }
				{ __(
					"this site's address inside a setting becomes the address of the site you import into.",
					'zinn-seo'
				) }
			</li>
			<li>
				<strong>{ __( 'Never in the file:', 'zinn-seo' ) }</strong>{ ' ' }
				{ __(
					'API keys, connections (Google, your Zinn® account and others) and anything else secret. Connect those again on the new site.',
					'zinn-seo'
				) }
			</li>
			<li>
				{ bulk ? (
					<a href={ `${ viewHref( 'bulk' ) }#/sheet` }>
						{ __(
							"Each page's own SEO (its title, description and more) moves with the spreadsheet export in the Bulk editor.",
							'zinn-seo'
						) }
					</a>
				) : (
					__(
						"Each page's own SEO (its title, description and more) moves with the spreadsheet export in the Bulk editor.",
						'zinn-seo'
					)
				) }{ ' ' }
				{ __(
					'Redirects have their own import and export under Redirects and 404.',
					'zinn-seo'
				) }
			</li>
		</ul>
	);
}

/**
 * Export the settings to a file, and import a file from another site, with a preview first.
 * The same panel serves the Tools screen, the "Export / import settings" screen and the setup's
 * first step (`mode="import"`: only the import, no download), so there is one import path.
 *
 * @param {Object}   props
 * @param {string}   props.mode      `both` (default) or `import`.
 * @param {Function} props.onApplied Called after an import was applied.
 * @param {boolean}  props.bulk      Whether the Bulk editor is on (for the link to its spreadsheet).
 * @return {Element} The panel.
 */
export function TransferPanel( {
	mode = 'both',
	onApplied = () => {},
	bulk = false,
} = {} ) {
	const [ preview, setPreview ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const doc = useRef( null );

	const download = () => {
		setBusy( true );
		seoFetch( '/settings/export' )
			.then( ( result ) => {
				const blob = new window.Blob(
					[ JSON.stringify( result, null, 2 ) ],
					{
						type: 'application/json',
					}
				);
				const link = document.createElement( 'a' );
				link.href = window.URL.createObjectURL( blob );
				link.download = 'zinn-seo-settings.json';
				link.click();
				window.URL.revokeObjectURL( link.href );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const read = ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		setNotice( null );
		setPreview( null );
		file.text().then( ( text ) => {
			let parsed;
			try {
				parsed = JSON.parse( text );
			} catch {
				setNotice( {
					status: 'error',
					text: __(
						'This file is not a Zinn® SEO settings file.',
						'zinn-seo'
					),
				} );
				return;
			}
			doc.current = parsed;
			setBusy( true );
			seoFetch( '/settings/import', {
				method: 'POST',
				data: { document: parsed, dry_run: true },
			} )
				.then( setPreview )
				.catch( ( e ) =>
					setNotice( { status: 'error', text: errorText( e ) } )
				)
				.finally( () => setBusy( false ) );
		} );
	};

	const apply = () => {
		setBusy( true );
		seoFetch( '/settings/import', {
			method: 'POST',
			data: { document: doc.current, dry_run: false },
		} )
			.then( ( result ) => {
				setPreview( null );
				onApplied( result );
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %d: number of settings. */
						_n(
							'%d setting imported.',
							'%d settings imported.',
							result.changes.length,
							'zinn-seo'
						),
						result.changes.length
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};

	const importOnly = 'import' === mode;
	const body = (
		<>
			{ importOnly ? (
				<p>
					{ __(
						'Choose a settings file downloaded from another site with Zinn® SEO. You see every change before anything is applied.',
						'zinn-seo'
					) }
				</p>
			) : (
				<>
					<p>
						{ __(
							"Download this site's settings as a file, then import it on another site. Nothing secret is in the file. An import shows every change before anything is applied.",
							'zinn-seo'
						) }
					</p>
					<TransferScope bulk={ bulk } />
				</>
			) }
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<p className="zak-actions">
				{ ! importOnly && (
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ download }
					>
						{ __( 'Download settings', 'zinn-seo' ) }
					</Button>
				) }
				<FormFileUpload
					__next40pxDefaultSize
					accept="application/json,.json"
					onChange={ read }
					render={ ( { openFileDialog } ) => (
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ openFileDialog }
						>
							{ __( 'Import a settings file', 'zinn-seo' ) }
						</Button>
					) }
				/>
				{ busy && <Spinner /> }
			</p>
			{ preview && (
				<div className="zseo-preview">
					{ preview.changes.length === 0 ? (
						<p>
							{ __(
								'This file has the same settings as this site. Nothing would change.',
								'zinn-seo'
							) }
						</p>
					) : (
						<>
							<p>
								{ sprintf(
									/* translators: %d: number of settings. */
									_n(
										'Importing changes %d setting:',
										'Importing changes %d settings:',
										preview.changes.length,
										'zinn-seo'
									),
									preview.changes.length
								) }
							</p>
							<table className="zak-table">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'Setting', 'zinn-seo' ) }
										</th>
										<th scope="col">
											{ __( 'Now', 'zinn-seo' ) }
										</th>
										<th scope="col">
											{ __(
												'After importing',
												'zinn-seo'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ preview.changes.map( ( c ) => (
										<tr key={ c.key }>
											<td>
												<code>{ c.key }</code>
											</td>
											<td>{ show( c.from ) }</td>
											<td>{ show( c.to ) }</td>
										</tr>
									) ) }
								</tbody>
							</table>
							<p className="zak-actions">
								<Button
									variant="primary"
									isBusy={ busy }
									disabled={ busy }
									onClick={ apply }
								>
									{ __(
										'Import these settings',
										'zinn-seo'
									) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => setPreview( null ) }
								>
									{ __( 'Cancel', 'zinn-seo' ) }
								</Button>
							</p>
						</>
					) }
				</div>
			) }
		</>
	);
	return importOnly ? (
		<div className="zseo-transfer">{ body }</div>
	) : (
		<PanelBody title={ __( 'Copy settings to another site', 'zinn-seo' ) }>
			{ body }
		</PanelBody>
	);
}

/**
 * The "Export / import settings" screen: the settings file on a screen of its own, so it can be
 * found from the navigation (owner, 2026-10-15). The Tools screen keeps the same panel.
 *
 * @param {Object} props
 * @param {Object} props.data The admin boot data.
 * @return {Element} The screen.
 */
export function TransferScreen( { data = {} } ) {
	return (
		<div className="zseo-admin">
			<TransferPanel bulk={ !! data.bulk } />
		</div>
	);
}

/**
 * Background jobs: progress, pause, resume. Shown only when a feature has registered one.
 *
 * @return {Element|null} The panel.
 */
export function JobsPanel() {
	const [ jobs, setJobs ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let timer = 0;
		let stopped = false;
		// Poll only while a job is running; an idle screen makes no requests.
		const load = () =>
			seoFetch( '/jobs' )
				.then( ( result ) => {
					setJobs( result );
					if (
						! stopped &&
						result.some( ( j ) => 'pending' === j.status )
					) {
						timer = window.setTimeout( load, 5000 );
					}
				} )
				.catch( ( e ) => setError( errorText( e ) ) );
		load();
		return () => {
			stopped = true;
			window.clearTimeout( timer );
		};
	}, [] );

	const act = ( id, action ) =>
		seoFetch( `/jobs/${ id }`, { method: 'POST', data: { action } } )
			.then( setJobs )
			.catch( ( e ) => setError( errorText( e ) ) );

	if ( ! jobs || jobs.length === 0 ) {
		return null;
	}
	const statusText = {
		idle: __( 'Not started', 'zinn-seo' ),
		pending: __( 'Running', 'zinn-seo' ),
		paused: __( 'Paused', 'zinn-seo' ),
		done: __( 'Finished', 'zinn-seo' ),
		failed: __( 'Stopped with an error', 'zinn-seo' ),
		cancelled: __( 'Cancelled', 'zinn-seo' ),
	};

	return (
		<PanelBody title={ __( 'Background jobs', 'zinn-seo' ) }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<table className="zak-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Job', 'zinn-seo' ) }</th>
						<th scope="col">{ __( 'Status', 'zinn-seo' ) }</th>
						<th scope="col">{ __( 'Progress', 'zinn-seo' ) }</th>
						<th scope="col">
							<span className="screen-reader-text">
								{ __( 'Actions', 'zinn-seo' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ jobs.map( ( job ) => (
						<tr key={ job.id }>
							<td>{ job.label }</td>
							<td>
								{ statusText[ job.status ] || job.status }
								{ 'pending' === job.status &&
									job.paused_note && (
										<span className="zseo-muted">
											{ ' ' }
											{ job.paused_note }
										</span>
									) }
								{ 'pending' === job.status && job.waiting && (
									<span className="zseo-muted">
										{ ' ' }
										{ sprintf(
											/* translators: %s: the name of the background job running now. */
											__(
												'Waiting for “%s” to finish: one background job runs at a time. This one starts by itself.',
												'zinn-seo'
											),
											job.waiting
										) }
									</span>
								) }
								{ job.error && (
									<span className="zseo-muted">
										{ ' ' }
										{ job.error }
									</span>
								) }
							</td>
							<td>
								{ job.progress && job.progress.total
									? sprintf(
											/* translators: 1: items done, 2: items in all. */
											__( '%1$d of %2$d', 'zinn-seo' ),
											job.progress.done,
											job.progress.total
										)
									: String(
											( job.progress &&
												job.progress.done ) ||
												0
										) }
							</td>
							<td>
								{ 'pending' === job.status && (
									<Button
										variant="link"
										onClick={ () => act( job.id, 'pause' ) }
									>
										{ __( 'Pause', 'zinn-seo' ) }
									</Button>
								) }
								{ [ 'paused', 'failed' ].includes(
									job.status
								) && (
									<Button
										variant="link"
										onClick={ () =>
											act( job.id, 'resume' )
										}
									>
										{ __( 'Resume', 'zinn-seo' ) }
									</Button>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</PanelBody>
	);
}

/**
 * The Tools screen.
 *
 * @param {Object} props
 * @param {Object} props.data The admin boot data.
 * @return {Element} The screen.
 */
export default function ToolsScreen( { data = {} } ) {
	const tabs = toolsTabs();
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	return (
		<div className="zseo-admin">
			<TabPanel
				className="zseo-tabs"
				tabs={ tabs }
				initialTabName={
					tabs.some( ( t ) => t.name === initial )
						? initial
						: 'transfer'
				}
				onSelect={ ( name ) =>
					window.history.replaceState( null, '', `#/${ name }` )
				}
			>
				{ ( tab ) =>
					'server' === tab.name ? (
						<ServerRulesTab data={ data } />
					) : (
						<div className="zseo-stack">
							<TransferPanel bulk={ !! data.bulk } />
							<JobsPanel />
						</div>
					)
				}
			</TabPanel>
		</div>
	);
}

/**
 * The tabs (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function toolsTabs() {
	return [
		{
			name: 'transfer',
			title: __( 'Copy settings and background jobs', 'zinn-seo' ),
		},
		{ name: 'server', title: __( 'Server rules (.htaccess)', 'zinn-seo' ) },
	];
}
