/**
 * Import and export: other plugins, spreadsheets, pasted server rules; Pro server rules (zs-099,
 * zs-094, zs-095).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormFileUpload,
	Notice,
	Spinner,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import ProTeaser from '../ProTeaser';
import { JobLine, Table, useJob, useRoute } from '../p4/shared';

/**
 * A dry run's counts and first refusals.
 *
 * @param {Object}   props
 * @param {Object}   props.preview  Preview.
 * @param {Function} props.onImport Start the import.
 * @param {Function} props.onCancel Close.
 * @return {Element} Panel.
 */
function Preview( { preview, onImport, onCancel } ) {
	const c = preview.counts;
	const usable = c.new + c.replace;
	return (
		<div className="zseo-stack zseo-preview-import" role="status">
			<h3>{ preview.name }</h3>
			<p>
				{ sprintf(
					/* translators: 1: new rules, 2: replacing, 3: unchanged, 4: refused. */
					__(
						'%1$d new, %2$d replace a rule for the same address, %3$d are already here unchanged, %4$d cannot be imported.',
						'zinn-seo'
					),
					c.new,
					c.replace,
					c.same,
					c.refused
				) }
			</p>
			{ preview.sample.length > 0 && (
				<Table
					columns={ [
						{
							id: 'source',
							label: __( 'From', 'zinn-seo' ),
							render: ( r ) => (
								<span className="zseo-address">
									{ r.source }
								</span>
							),
						},
						{
							id: 'target',
							label: __( 'To', 'zinn-seo' ),
							render: ( r ) => (
								<span className="zseo-address">
									{ r.target || String( r.code ) }
								</span>
							),
						},
						{
							id: 'code',
							label: __( 'Type', 'zinn-seo' ),
							render: ( r ) => String( r.code ),
						},
					] }
					rows={ preview.sample.map( ( r, i ) => ( {
						...r,
						id: i,
					} ) ) }
					label={ __( 'Examples', 'zinn-seo' ) }
					empty=""
				/>
			) }
			{ preview.refused.length > 0 && (
				<details className="zseo-details">
					<summary>
						{ __( 'Why some cannot be imported', 'zinn-seo' ) }
					</summary>
					<ul>
						{ preview.refused.map( ( r, i ) => (
							<li key={ i }>
								<span className="zseo-address">
									{ r.source }
								</span>
								: { r.reason }
							</li>
						) ) }
					</ul>
				</details>
			) }
			<div className="zseo-actions zseo-actions--start">
				<Button
					variant="primary"
					disabled={ usable < 1 }
					onClick={ onImport }
				>
					{ sprintf(
						/* translators: %d: number of redirects. */
						__( 'Import %d redirects', 'zinn-seo' ),
						usable
					) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'zinn-seo' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Pro: answering redirects earlier — the must-use file, .htaccess, server rules.
 *
 * @return {Element} Card.
 */
function ServerRules() {
	const status = useRoute( '/redirects/server' );
	const { settings, save } = useSettings();
	const [ shown, setShown ] = useState( null );
	const [ error, setError ] = useState( '' );
	const st = status.data;
	const show = ( kind ) =>
		seoFetch( `/redirects/server/${ kind }` )
			.then( ( r ) => setShown( { kind, text: r.text } ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'Answer redirects even faster', 'zinn-seo' ) }
				</h3>
			</CardHeader>
			<CardBody className="zseo-stack">
				{ ( error || status.error ) && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error || status.error }
					</Notice>
				) }
				{ ( ! st || ! settings ) && <Spinner /> }
				{ st && settings && (
					<>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Answer redirects before any plugin loads',
								'zinn-seo'
							) }
							help={
								st.dropin
									? __(
											'On: a small must-use file answers each redirect while WordPress has loaded only its core.',
											'zinn-seo'
										)
									: __(
											'Writes a small must-use file (wp-content/mu-plugins/zd-redirects.php) that answers redirects while WordPress has loaded only its core. It is removed when you switch this off or deactivate the plugin.',
											'zinn-seo'
										)
							}
							checked={ !! settings.redirects_early }
							onChange={ ( v ) =>
								save( { redirects_early: v } )
									.then( status.reload )
									.catch( ( e ) =>
										setError( errorText( e ) )
									)
							}
						/>
						{ st.apache && (
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Keep the redirects in .htaccess (Apache)',
									'zinn-seo'
								) }
								help={ __(
									'Apache then answers them without starting PHP; the block is updated on every change and placed before WordPress’s own rules.',
									'zinn-seo'
								) }
								checked={ !! settings.redirects_htaccess }
								onChange={ ( v ) =>
									save( { redirects_htaccess: v } ).catch(
										( e ) => setError( errorText( e ) )
									)
								}
							/>
						) }
						<p className="zseo-muted">
							{ __(
								'Or give the rules to your web server so redirects never start WordPress at all. Plain redirects become a hash table (as fast with 100,000 redirects as with ten). WordPress keeps answering anything the server file does not have yet.',
								'zinn-seo'
							) }
						</p>
						<div className="zseo-actions zseo-actions--start">
							{ [
								[ 'nginx', 'Nginx' ],
								[ 'apache', 'Apache .htaccess' ],
								[ 'apache-map', 'Apache RewriteMap' ],
								[ 'caddy', 'Caddy' ],
							].map( ( [ kind, name ] ) => (
								<span key={ kind } className="zseo-actions">
									<Button
										variant="secondary"
										onClick={ () => show( kind ) }
									>
										{ sprintf(
											/* translators: %s: a web server's name. */
											__( 'Show for %s', 'zinn-seo' ),
											name
										) }
									</Button>
									<Button
										variant="link"
										href={ st.downloads[ kind ] }
									>
										{ __( 'Download', 'zinn-seo' ) }
									</Button>
								</span>
							) ) }
						</div>
						{ shown && (
							<pre
								className="zseo-pre"
								aria-label={ shown.kind }
								tabIndex={ 0 }
							>
								{ shown.text }
							</pre>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The import / export tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function ImportTab( { data } ) {
	const conf = data.redirects || {};
	const sources = useRoute( '/redirects/import' );
	const [ preview, setPreview ] = useState( null );
	const [ pasted, setPasted ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/redirects/import' ).then( ( r ) => r.job ),
		() => sources.reload()
	);
	useEffect( () => {
		if ( sources.data ) {
			setJob( sources.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ sources.data ] );
	const ask = ( source, name, text = '' ) => {
		setBusy( source );
		setError( '' );
		return seoFetch( '/redirects/import/preview', {
			method: 'POST',
			data: { source, text },
		} )
			.then( ( p ) => setPreview( { ...p, source, name, text } ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const start = () =>
		seoFetch( '/redirects/import', {
			method: 'POST',
			data: { source: preview.source, text: preview.text || '' },
		} )
			.then( ( j ) => {
				setJob( j );
				setPreview( null );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	const last = sources.data ? sources.data.last : null;
	const found = sources.data
		? sources.data.sources.filter( ( s ) => s.count > 0 )
		: [];
	return (
		<div className="zseo-stack">
			{ ( error || sources.error ) && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error || sources.error }
				</Notice>
			) }
			<Card>
				<CardHeader>
					<h3 className="zseo-card-title">
						{ __( 'Export', 'zinn-seo' ) }
					</h3>
				</CardHeader>
				<CardBody className="zseo-stack">
					<p className="zseo-muted">
						{ __(
							'Every redirect as a spreadsheet (CSV) that opens in Excel, Numbers or Google Sheets and imports back here unchanged.',
							'zinn-seo'
						) }
					</p>
					<p>
						<Button variant="secondary" href={ conf.exportUrl }>
							{ __( 'Download the spreadsheet', 'zinn-seo' ) }
						</Button>
					</p>
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h3 className="zseo-card-title">
						{ __( 'Import', 'zinn-seo' ) }
					</h3>
				</CardHeader>
				<CardBody className="zseo-stack">
					<p className="zseo-muted">
						{ __(
							'Bring in the redirects another plugin made — even one you already deactivated, its data is still there — or a spreadsheet. You see what will happen first; importing again later is safe.',
							'zinn-seo'
						) }
					</p>
					{ ! sources.data && <Spinner /> }
					{ sources.data && found.length === 0 && (
						<p>
							{ __(
								'No other redirect plugin has left redirects on this site.',
								'zinn-seo'
							) }
						</p>
					) }
					{ found.length > 0 && (
						<ul className="zseo-sources">
							{ found.map( ( s ) => (
								<li key={ s.id }>
									<span>
										<strong>{ s.name }</strong>{ ' ' }
										<span className="zseo-muted">
											{ sprintf(
												/* translators: %d: number of rules. */
												__( '%d rules', 'zinn-seo' ),
												s.count
											) }
										</span>
									</span>
									<Button
										variant="secondary"
										isBusy={ busy === s.id }
										disabled={
											!! busy ||
											( job && job.status === 'pending' )
										}
										onClick={ () => ask( s.id, s.name ) }
									>
										{ __( 'Check and import', 'zinn-seo' ) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
					<FormFileUpload
						accept=".csv,text/csv,text/plain"
						onChange={ ( e ) => {
							const file = e.target.files && e.target.files[ 0 ];
							if ( ! file ) {
								return;
							}
							const body = new window.FormData();
							body.append( 'file', file );
							setBusy( 'csv' );
							seoFetch( '/redirects/import/upload', {
								method: 'POST',
								body,
							} )
								.then( ( r ) => ask( r.source, file.name ) )
								.catch( ( er ) => {
									setError( errorText( er ) );
									setBusy( '' );
								} );
						} }
						render={ ( { openFileDialog } ) => (
							<p>
								<Button
									variant="secondary"
									isBusy={ busy === 'csv' }
									disabled={ !! busy }
									onClick={ openFileDialog }
								>
									{ __(
										'Import a spreadsheet (CSV)…',
										'zinn-seo'
									) }
								</Button>
							</p>
						) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Or paste server rules', 'zinn-seo' ) }
						help={ __(
							'Lines such as Redirect 301 /old /new, RedirectMatch and RewriteRule … [R=301] from an .htaccess file.',
							'zinn-seo'
						) }
						className="zseo-code-input"
						rows={ 4 }
						value={ pasted }
						onChange={ setPasted }
					/>
					<p>
						<Button
							variant="secondary"
							disabled={ ! pasted.trim() || !! busy }
							onClick={ () =>
								ask(
									'server',
									__( 'Pasted rules', 'zinn-seo' ),
									pasted
								)
							}
						>
							{ __( 'Check the pasted rules', 'zinn-seo' ) }
						</Button>
					</p>
					{ preview && (
						<Preview
							preview={ preview }
							onImport={ start }
							onCancel={ () => setPreview( null ) }
						/>
					) }
					<JobLine job={ job } />
					{ last && job && job.status !== 'pending' && (
						<p role="status">
							{ sprintf(
								/* translators: 1: new, 2: replaced, 3: unchanged, 4: refused. */
								__(
									'Last import: %1$d new, %2$d replaced, %3$d unchanged, %4$d could not be imported.',
									'zinn-seo'
								),
								last.counts.new || 0,
								last.counts.replace || 0,
								last.counts.same || 0,
								last.counts.refused || 0
							) }
						</p>
					) }
				</CardBody>
			</Card>
			{ data.redirectsPro && 'free' !== data.plan ? (
				<ServerRules />
			) : (
				<ProTeaser
					title={ __( 'Fast redirects', 'zinn-seo' ) }
					points={ [
						__(
							'Answer redirects before any plugin loads.',
							'zinn-seo'
						),
						__(
							'Keep them in .htaccess on Apache, updated on every change.',
							'zinn-seo'
						),
						__(
							'Export them as Nginx, Apache and Caddy rules, so 100,000 redirects never start WordPress.',
							'zinn-seo'
						),
					] }
				/>
			) }
		</div>
	);
}
