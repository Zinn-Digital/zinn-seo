/**
 * Site search insights: what visitors search for, and what finds nothing (zs-414).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { Table } from '../p4/shared';
import { when } from './pure';
import './p5.scss';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function SiteSearchTab( { data } ) {
	const [ kind, setKind ] = useState( 'search0' );
	const [ page, setPage ] = useState( null );
	const [ rows, setRows ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const load = ( after, replace ) =>
		seoFetch(
			`/site-search?kind=${ kind }&after=${ encodeURIComponent( after ) }`
		)
			.then( ( r ) => {
				setPage( r );
				setRows( replace ? r.items : [ ...rows, ...r.items ] );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	useEffect( () => {
		load( '', true );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind ] );
	const draft = ( row ) =>
		seoFetch( '/site-search/draft', {
			method: 'POST',
			data: { phrase: row.key },
		} )
			.then( () => load( '', true ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'What visitors type into your own search box. A search that finds nothing is a page your visitors want and you have not written yet. One counter per phrase; who searched is never kept.',
					'zinn-seo'
				) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ page && (
				<dl className="zseo-totals">
					<div>
						<dt>
							{ __( 'Phrases that found nothing', 'zinn-seo' ) }
						</dt>
						<dd>{ page.counts.search0 }</dd>
					</div>
					<div>
						<dt>
							{ __( 'Phrases that found something', 'zinn-seo' ) }
						</dt>
						<dd>{ page.counts.search }</dd>
					</div>
				</dl>
			) }
			<div className="zseo-filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show', 'zinn-seo' ) }
					value={ kind }
					options={ [
						{
							value: 'search0',
							label: __(
								'Searches that found nothing',
								'zinn-seo'
							),
						},
						{
							value: 'search',
							label: __(
								'Searches that found something',
								'zinn-seo'
							),
						},
					] }
					onChange={ setKind }
				/>
			</div>
			{ ! page ? (
				<Spinner />
			) : (
				<Table
					columns={ [
						{
							id: 'key',
							label: __( 'Phrase', 'zinn-seo' ),
							render: ( r ) => <strong>{ r.key }</strong>,
						},
						{
							id: 'hits',
							label: __( 'Searches', 'zinn-seo' ),
							render: ( r ) => String( r.hits ),
						},
						...( 'search' === kind
							? [
									{
										id: 'results',
										label: __(
											'Results last time',
											'zinn-seo'
										),
										render: ( r ) => r.sample,
									},
								]
							: [] ),
						{
							id: 'seen',
							label: __( 'Last searched', 'zinn-seo' ),
							render: ( r ) => when( r.last_seen ),
						},
						{
							id: 'do',
							label: __( 'Actions', 'zinn-seo' ),
							render: ( r ) => (
								<span className="zseo-actions zseo-actions--start">
									{ r.draft ? (
										<a href={ r.draft.edit }>
											{ __(
												'Open the draft',
												'zinn-seo'
											) }
										</a>
									) : (
										<Button
											variant="secondary"
											size="compact"
											onClick={ () => draft( r ) }
										>
											{ __(
												'Start a page about it',
												'zinn-seo'
											) }
										</Button>
									) }
									{ data.canManage && (
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												seoFetch( '/site-search', {
													method: 'DELETE',
													data: {
														kind,
														hash: r.hash,
													},
												} )
													.then( () =>
														load( '', true )
													)
													.catch( ( e ) =>
														setError(
															errorText( e )
														)
													)
											}
										>
											{ __( 'Forget', 'zinn-seo' ) }
										</Button>
									) }
								</span>
							),
						},
					] }
					rows={ rows.map( ( r ) => ( { ...r, id: r.hash } ) ) }
					label={ __( 'Site searches', 'zinn-seo' ) }
					empty={ sprintf(
						/* translators: %s: a search address, e.g. /?s=shoes */
						__(
							'No search counted yet. Searches on your site’s own search (%s) appear here.',
							'zinn-seo'
						),
						'/?s=…'
					) }
				/>
			) }
			{ page && page.next && (
				<p>
					<Button
						variant="secondary"
						onClick={ () => load( page.next, false ) }
					>
						{ __( 'Show more', 'zinn-seo' ) }
					</Button>
				</p>
			) }
		</div>
	);
}
