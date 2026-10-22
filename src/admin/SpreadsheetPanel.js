import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	FormFileUpload,
	Notice,
	PanelBody,
	Spinner,
} from '@wordpress/components';

import { errorText, seoFetch } from './api';

/**
 * The download address for the chosen kinds. Exported for the tests.
 *
 * @param {string}        base  Nonce-signed export address.
 * @param {Array<string>} kinds `post:<type>` / `term:<taxonomy>`.
 * @return {string} Address.
 */
export function exportHref( base, kinds ) {
	const url = new window.URL( base, window.location.href );
	kinds.forEach( ( k ) => url.searchParams.append( 'kinds[]', k ) );
	return url.toString();
}

/**
 * Pro: export every SEO field to a CSV file, and import one back with a preview.
 *
 * @param {Object}   props
 * @param {Function} props.onBatch Called after an import (refreshes the history).
 * @return {Element} The panel.
 */
export default function SpreadsheetPanel( { onBatch } ) {
	const [ status, setStatus ] = useState( null );
	const [ kinds, setKinds ] = useState( [] );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	// The latest onBatch, read when a background import finishes (the parent passes a new
	// function on every render).
	const batchRef = useRef( onBatch );
	batchRef.current = onBatch;

	const load = useCallback(
		() =>
			seoFetch( '/csv' )
				.then( ( r ) => {
					setStatus( r );
					setKinds( ( old ) =>
						old.length ? old : r.kinds.map( ( k ) => k.value )
					);
				} )
				.catch( ( e ) =>
					setNotice( { status: 'error', text: errorText( e ) } )
				),
		[]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		if ( ! status || 'pending' !== status.job.status ) {
			return undefined;
		}
		const t = window.setTimeout( () => {
			load().then( () => batchRef.current() );
		}, 3000 );
		return () => window.clearTimeout( t );
	}, [ status, load ] );

	if ( ! status ) {
		return notice ? (
			<Notice status="error">{ notice.text }</Notice>
		) : (
			<Spinner />
		);
	}

	const upload = ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const body = new window.FormData();
		body.append( 'file', file );
		setBusy( true );
		setPreview( null );
		setNotice( null );
		seoFetch( '/csv/preview', { method: 'POST', body } )
			.then( setPreview )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => {
				setBusy( false );
				event.target.value = '';
			} );
	};
	const run = () => {
		setBusy( true );
		seoFetch( '/csv/import', {
			method: 'POST',
			data: { token: preview.token },
		} )
			.then( () => {
				setPreview( null );
				setNotice( {
					status: 'info',
					text: __(
						'Importing in the background. When it finishes, the change can be undone under History and undo.',
						'zinn-seo'
					),
				} );
				return load();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const job = status.job;

	return (
		<div className="zseo-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<PanelBody title={ __( 'Export to a spreadsheet', 'zinn-seo' ) }>
				<fieldset>
					<legend className="zseo-legend">
						{ __( 'What to include', 'zinn-seo' ) }
					</legend>
					<div className="zseo-checks">
						{ status.kinds.map( ( k ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ k.value }
								label={ k.label }
								checked={ kinds.includes( k.value ) }
								onChange={ ( on ) =>
									setKinds(
										on
											? [ ...kinds, k.value ]
											: kinds.filter(
													( x ) => x !== k.value
												)
									)
								}
							/>
						) ) }
					</div>
				</fieldset>
				<p>
					<Button
						variant="primary"
						href={
							kinds.length
								? exportHref( status.exportUrl, kinds )
								: undefined
						}
						disabled={ ! kinds.length }
					>
						{ __( 'Download CSV', 'zinn-seo' ) }
					</Button>
				</p>
				<p className="zseo-muted">
					{ __( 'Columns:', 'zinn-seo' ) }{ ' ' }
					<code className="zseo-columns">
						{ status.columns.join( ', ' ) }
					</code>
				</p>
				<p className="zseo-muted">
					{ __(
						'A very large site whose download stops part way can export from the command line: wp zinn-seo csv export seo.csv',
						'zinn-seo'
					) }
				</p>
			</PanelBody>
			<PanelBody title={ __( 'Import from a spreadsheet', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<p className="zseo-muted">
						{ __(
							'Save the sheet as CSV. Each row is matched by its id, or by its address when the id does not exist here. What a cell says becomes the value, so an empty cell clears that field; a column you delete from the sheet is left alone.',
							'zinn-seo'
						) }
					</p>
					{ job && 'pending' === job.status ? (
						<p aria-live="polite">
							<Spinner />{ ' ' }
							{ sprintf(
								/* translators: %d: percentage done. */
								__( 'Importing: %d%%', 'zinn-seo' ),
								job.progress && job.progress.total
									? Math.round(
											( 100 * job.progress.done ) /
												job.progress.total
										)
									: 0
							) }
						</p>
					) : (
						<FormFileUpload
							accept=".csv,.tsv,.txt,text/csv"
							onChange={ upload }
							render={ ( { openFileDialog } ) => (
								<Button
									variant="secondary"
									isBusy={ busy }
									disabled={ busy }
									onClick={ openFileDialog }
								>
									{ __( 'Choose a CSV file…', 'zinn-seo' ) }
								</Button>
							) }
						/>
					) }
					{ preview && (
						<div className="zseo-preview-import">
							<h3>
								{ __( 'Before anything changes', 'zinn-seo' ) }
							</h3>
							<ul className="zseo-changes">
								<li>
									{ sprintf(
										/* translators: 1: rows read, 2: rows matched. */
										__(
											'%1$d rows read, %2$d matched a post or term.',
											'zinn-seo'
										),
										preview.rows,
										preview.matched
									) }
								</li>
								<li>
									<strong>
										{ sprintf(
											/* translators: %d: number of fields. */
											_n(
												'%d field will change.',
												'%d fields will change.',
												preview.changes,
												'zinn-seo'
											),
											preview.changes
										) }
									</strong>{ ' ' }
									{ Object.entries( preview.fields || {} )
										.map( ( [ f, n ] ) => `${ f }: ${ n }` )
										.join( ', ' ) }
								</li>
								{ ! preview.complete && (
									<li>
										{ __(
											'The file is large: these numbers cover the first part only. The import goes through all of it.',
											'zinn-seo'
										) }
									</li>
								) }
							</ul>
							{ preview.unknown.length > 0 && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ sprintf(
										/* translators: %s: column names. */
										__(
											'These columns are not SEO fields and will be ignored: %s',
											'zinn-seo'
										),
										preview.unknown.join( ', ' )
									) }
								</Notice>
							) }
							{ preview.unmatched.length > 0 && (
								<details className="zseo-details">
									<summary>
										{ sprintf(
											/* translators: %d: number of rows. */
											_n(
												'%d row matched nothing',
												'%d rows matched nothing',
												preview.unmatched.length,
												'zinn-seo'
											),
											preview.unmatched.length
										) }
									</summary>
									<ul>
										{ preview.unmatched.map(
											( [ line, what ] ) => (
												<li key={ line }>
													{ sprintf(
														/* translators: 1: a line number, 2: what is wrong, or the address or ID that matched nothing. */
														__(
															'Line %1$d: %2$s',
															'zinn-seo'
														),
														line,
														what
													) }
												</li>
											)
										) }
									</ul>
								</details>
							) }
							{ preview.errors.length > 0 && (
								<Notice status="error" isDismissible={ false }>
									<ul>
										{ preview.errors.map(
											( [ line, text ] ) => (
												<li
													key={ `${ line }-${ text }` }
												>
													{ sprintf(
														/* translators: 1: a line number, 2: what is wrong, or the address or ID that matched nothing. */
														__(
															'Line %1$d: %2$s',
															'zinn-seo'
														),
														line,
														text
													) }
												</li>
											)
										) }
									</ul>
								</Notice>
							) }
							<div className="zseo-actions">
								<Button
									variant="tertiary"
									onClick={ () => setPreview( null ) }
								>
									{ __( 'Cancel', 'zinn-seo' ) }
								</Button>
								<Button
									variant="primary"
									isBusy={ busy }
									disabled={
										busy ||
										( ! preview.changes &&
											preview.complete )
									}
									onClick={ run }
								>
									{ __( 'Import these changes', 'zinn-seo' ) }
								</Button>
							</div>
						</div>
					) }
				</div>
			</PanelBody>
		</div>
	);
}
