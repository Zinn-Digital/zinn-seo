/**
 * Pages from a spreadsheet (zs-350): one template, one page per row, thin and duplicate pages
 * held back.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Notice,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../admin/api';
import { JobLine, RunButton, Table, useJob } from '../admin/p4/shared';
import './agency.scss';

/**
 * Why a row was held back.
 *
 * @param {string} reason Code.
 * @return {string} Words.
 */
function heldText( reason ) {
	return (
		{
			taken: __( 'A page already uses this address', 'zinn-seo' ),
			twice: __( 'Same address as an earlier row', 'zinn-seo' ),
			thin: __( 'Too few words', 'zinn-seo' ),
			copy: __( 'Too much like the other pages', 'zinn-seo' ),
			empty: __( 'No title or address', 'zinn-seo' ),
		}[ reason ] || reason
	);
}

/**
 * The screen.
 *
 * @return {Element} Screen.
 */
export default function PagesScreen() {
	const [ source, setSource ] = useState( { csv: '', url: '' } );
	const [ plan, setPlan ] = useState( {
		post_type: 'page',
		status: 'draft',
		title: '',
		slug: '',
		content: '',
		seo_title: '',
		seo_description: '',
		min_words: 300,
		min_unique: 20,
		update: false,
	} );
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/agency/pages' ).then( ( r ) => r.job ),
		() => {}
	);
	useEffect( () => {
		seoFetch( '/agency/pages' )
			.then( ( r ) => setJob( r.job ) )
			.catch( ( e ) => setError( errorText( e ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	const set = ( patch ) => {
		setPlan( { ...plan, ...patch } );
		setPreview( null );
	};
	const body = { ...source, plan };
	const counts = preview && preview.counts;
	const readFile = ( file ) => {
		if ( ! file ) {
			return;
		}
		const reader = new window.FileReader();
		reader.onload = () => {
			setSource( { csv: String( reader.result || '' ), url: '' } );
			setPreview( null );
		};
		reader.readAsText( file );
	};
	return (
		<div className="zseo-admin zseo-pages">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-h2">
						{ __( '1. Your spreadsheet', 'zinn-seo' ) }
					</h2>
					<p className="zseo-muted">
						{ __(
							'One row per page. The first row names the columns — for example town, service, phone — and {town} in the template becomes that row’s town.',
							'zinn-seo'
						) }
					</p>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="url"
						label={ __( 'Google Sheet address', 'zinn-seo' ) }
						help={ __(
							'Share it so anyone with the link can view it, or publish it to the web as CSV.',
							'zinn-seo'
						) }
						value={ source.url }
						onChange={ ( v ) => {
							setSource( { csv: '', url: v } );
							setPreview( null );
						} }
					/>
					<div className="zseo-file">
						<label htmlFor="zseo-pages-file">
							{ __( 'Or a CSV file', 'zinn-seo' ) }
						</label>
						<input
							id="zseo-pages-file"
							type="file"
							accept=".csv,text/csv"
							onChange={ ( e ) =>
								readFile(
									e.target.files && e.target.files[ 0 ]
								)
							}
						/>
						{ source.csv && (
							<span className="zseo-muted">
								{ __( 'File loaded.', 'zinn-seo' ) }
							</span>
						) }
					</div>
				</CardBody>
			</Card>
			<Card>
				<CardBody className="zseo-stack">
					<h2 className="zseo-h2">
						{ __( '2. The template', 'zinn-seo' ) }
					</h2>
					{ preview && preview.columns && (
						<p className="zseo-muted">
							{ sprintf(
								/* translators: %s: column placeholders. */
								__( 'Columns you can use: %s', 'zinn-seo' ),
								preview.columns
									.map( ( c ) => `{${ c }}` )
									.join( ' ' )
							) }
						</p>
					) }
					<div className="zseo-grid-2">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Page title', 'zinn-seo' ) }
							placeholder={ __(
								'Plumbers in {town}',
								'zinn-seo'
							) }
							value={ plan.title }
							onChange={ ( v ) => set( { title: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Address (optional)', 'zinn-seo' ) }
							placeholder="plumbers-{town}"
							value={ plan.slug }
							onChange={ ( v ) => set( { slug: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'SEO title', 'zinn-seo' ) }
							value={ plan.seo_title }
							onChange={ ( v ) => set( { seo_title: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Meta description', 'zinn-seo' ) }
							value={ plan.seo_description }
							onChange={ ( v ) => set( { seo_description: v } ) }
						/>
					</div>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Page content (HTML or blocks)',
							'zinn-seo'
						) }
						rows={ 10 }
						value={ plan.content }
						onChange={ ( v ) => set( { content: v } ) }
					/>
					<div className="zseo-grid-2">
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Make them as', 'zinn-seo' ) }
							value={ plan.post_type }
							options={ [
								{
									value: 'page',
									label: __( 'Pages', 'zinn-seo' ),
								},
								{
									value: 'post',
									label: __( 'Posts', 'zinn-seo' ),
								},
							] }
							onChange={ ( v ) => set( { post_type: v } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Status', 'zinn-seo' ) }
							value={ plan.status }
							options={ [
								{
									value: 'draft',
									label: __(
										'Drafts, to check first',
										'zinn-seo'
									),
								},
								{
									value: 'publish',
									label: __( 'Published', 'zinn-seo' ),
								},
							] }
							onChange={ ( v ) => set( { status: v } ) }
						/>
					</div>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __(
							'Hold back pages with fewer words than',
							'zinn-seo'
						) }
						value={ plan.min_words }
						min={ 0 }
						max={ 2000 }
						step={ 50 }
						onChange={ ( v ) => set( { min_words: v || 0 } ) }
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __(
							'Hold back pages whose own words are under (%)',
							'zinn-seo'
						) }
						help={ __(
							'The share of each page that comes from its row rather than the template. Pages that differ only by a name are what search engines treat as doorway pages.',
							'zinn-seo'
						) }
						value={ plan.min_unique }
						min={ 0 }
						max={ 90 }
						onChange={ ( v ) => set( { min_unique: v || 0 } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Update the pages an earlier run made',
							'zinn-seo'
						) }
						help={ __(
							'Pages made any other way are never touched.',
							'zinn-seo'
						) }
						checked={ !! plan.update }
						onChange={ ( v ) => set( { update: v } ) }
					/>
					<div className="zseo-actions">
						<RunButton
							onError={ setError }
							disabled={ ! source.csv && ! source.url }
							run={ () =>
								seoFetch( '/agency/pages/preview', {
									method: 'POST',
									data: body,
								} ).then( setPreview )
							}
						>
							{ __( 'Check the pages', 'zinn-seo' ) }
						</RunButton>
					</div>
				</CardBody>
			</Card>
			{ counts && (
				<Card>
					<CardBody className="zseo-stack">
						<h2 className="zseo-h2">
							{ __( '3. What will happen', 'zinn-seo' ) }
						</h2>
						<p>
							{ sprintf(
								/* translators: 1: rows, 2: pages made, 3: pages updated, 4: rows held back. */
								__(
									'%1$d rows: %2$d new pages, %3$d updated, %4$d held back.',
									'zinn-seo'
								),
								preview.rows,
								counts.make,
								counts.update,
								counts.taken +
									counts.twice +
									counts.thin +
									counts.copy +
									counts.empty
							) }
						</p>
						{ preview.sample.map( ( p ) => (
							<details key={ p.row } className="zseo-details">
								<summary>
									{ p.title }{ ' ' }
									<span className="zseo-muted">
										{ sprintf(
											/* translators: 1: words, 2: percent. */
											__(
												'%1$d words, %2$d%% its own',
												'zinn-seo'
											),
											p.words,
											p.own
										) }
									</span>
								</summary>
								<p className="zseo-muted">/{ p.slug }/</p>
								<p>{ p.seo_title }</p>
								<p className="zseo-muted">
									{ p.seo_description }
								</p>
							</details>
						) ) }
						{ preview.held.length > 0 && (
							<Table
								label={ __( 'Rows held back', 'zinn-seo' ) }
								rows={ preview.held.map( ( h ) => ( {
									...h,
									id: h.row,
								} ) ) }
								columns={ [
									{
										id: 'row',
										label: __( 'Row', 'zinn-seo' ),
										render: ( h ) => h.row,
									},
									{
										id: 'title',
										label: __( 'Title', 'zinn-seo' ),
										render: ( h ) => h.title || '—',
									},
									{
										id: 'why',
										label: __( 'Why', 'zinn-seo' ),
										render: ( h ) => heldText( h.reason ),
									},
								] }
							/>
						) }
						<div className="zseo-actions">
							<RunButton
								variant="primary"
								onError={ setError }
								disabled={ ! ( counts.make + counts.update ) }
								run={ () =>
									seoFetch( '/agency/pages/run', {
										method: 'POST',
										data: body,
									} ).then( setJob )
								}
							>
								{ __( 'Make the pages', 'zinn-seo' ) }
							</RunButton>
						</div>
					</CardBody>
				</Card>
			) }
			<JobLine job={ job } />
			{ job && job.status === 'done' && (
				<Card>
					<CardBody className="zseo-stack">
						<p>
							{ sprintf(
								/* translators: 1: pages made, 2: rows held back. */
								__(
									'Done: %1$d pages made or updated, %2$d rows held back.',
									'zinn-seo'
								),
								( job.state && job.state.made ) || 0,
								( job.state && job.state.held ) || 0
							) }
						</p>
						<div className="zseo-actions">
							<RunButton
								variant="tertiary"
								onError={ setError }
								run={ () =>
									seoFetch( '/agency/pages/undo', {
										method: 'POST',
									} ).then( () => setError( '' ) )
								}
							>
								{ __(
									'Remove the pages this run made',
									'zinn-seo'
								) }
							</RunButton>
							<Button
								variant="secondary"
								href="edit.php?post_type=page"
							>
								{ __( 'See the pages', 'zinn-seo' ) }
							</Button>
						</div>
					</CardBody>
				</Card>
			) }
		</div>
	);
}
