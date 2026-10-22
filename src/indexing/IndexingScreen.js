/**
 * The indexing console (zs-742): send pages to the search engines by hand, keep pages out of
 * automatic sending with simple rules, and see every engine's answer per page — retry what
 * failed, download the lot.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Notice,
	SelectControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import GuideLink from '../admin/p7/GuideLink';
import { Loaded, Refusal, Table, useLoad } from '../tools/parts';
import { count } from '../tools/pure';
import { badge, engineLabel, query, stateLabel, urlsFrom } from './pure';

/**
 * Send by hand.
 *
 * @param {Object}   props
 * @param {Object}   props.engines Which engines are ready.
 * @param {Function} props.done    Reload the log.
 * @return {Element} Form.
 */
function Send( { engines, done } ) {
	const [ text, setText ] = useState( '' );
	const [ chosen, setChosen ] = useState(
		Object.keys( engines ).filter( ( e ) => engines[ e ].ready )
	);
	const [ busy, setBusy ] = useState( false );
	const [ said, setSaid ] = useState( null );
	const [ error, setError ] = useState( null );
	const urls = urlsFrom( text );
	const send = () => {
		setBusy( true );
		setSaid( null );
		setError( null );
		seoFetch( '/indexing/console/submit', {
			method: 'POST',
			data: { urls, engines: chosen },
		} )
			.then( ( out ) => {
				setSaid( out );
				done();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<section className="zseo-stack">
			<h3>{ __( 'Send pages now', 'zinn-seo' ) }</h3>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Page addresses', 'zinn-seo' ) }
				help={ __(
					'One per line, from this site. Up to 10,000 at a time.',
					'zinn-seo'
				) }
				value={ text }
				onChange={ setText }
				rows={ 5 }
			/>
			<fieldset className="zseo-stack">
				<legend>{ __( 'Send to', 'zinn-seo' ) }</legend>
				{ Object.keys( engines ).map( ( e ) => (
					<div key={ e }>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ engineLabel( e ) }
							checked={ chosen.includes( e ) }
							disabled={ ! engines[ e ].ready }
							onChange={ ( on ) =>
								setChosen(
									on
										? [ ...chosen, e ]
										: chosen.filter( ( x ) => x !== e )
								)
							}
						/>
						{ ! engines[ e ].ready && (
							<p className="zseo-muted">{ engines[ e ].why }</p>
						) }
					</div>
				) ) }
			</fieldset>
			<p>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! urls.length || ! chosen.length }
					onClick={ send }
				>
					{ sprintf(
						/* translators: %s: a number of pages. */
						__( 'Send %s pages', 'zinn-seo' ),
						count( urls.length )
					) }
				</Button>
			</p>
			<Refusal error={ error } onRemove={ () => setError( null ) } />
			{ said && (
				<Notice status="success" onRemove={ () => setSaid( null ) }>
					{ __(
						'Sent. Each search engine’s answer appears in the log below as it arrives; Bing’s list is sent in the background within its daily allowance.',
						'zinn-seo'
					) }
					{ said.other > 0 &&
						' ' +
							sprintf(
								/* translators: %s: a number of addresses. */
								__(
									'%s addresses were left out because they are not on this site.',
									'zinn-seo'
								),
								count( said.other )
							) }
				</Notice>
			) }
		</section>
	);
}

/**
 * The rules that keep pages out of automatic sending.
 *
 * @param {Object}   props
 * @param {Array}    props.rules Saved rules.
 * @param {Function} props.done  Reload.
 * @return {Element} Form.
 */
function Rules( { rules, done } ) {
	const [ text, setText ] = useState( ( rules || [] ).join( '\n' ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	const save = () => {
		setBusy( true );
		setError( null );
		seoFetch( '/indexing/console/rules', {
			method: 'POST',
			data: { rules: text },
		} )
			.then( () => {
				setSaved( true );
				done();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<section className="zseo-stack">
			<h3>{ __( 'Pages never sent automatically', 'zinn-seo' ) }</h3>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Rules, one per line', 'zinn-seo' ) }
				help={ __(
					'Use * for “anything”. /tag/* keeps every tag page out; *?replytocom=* keeps comment-reply links out. Pages you send by hand still go.',
					'zinn-seo'
				) }
				value={ text }
				onChange={ ( v ) => {
					setText( v );
					setSaved( false );
				} }
				rows={ 4 }
			/>
			<p>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ save }
				>
					{ saved
						? __( 'Saved', 'zinn-seo' )
						: __( 'Save rules', 'zinn-seo' ) }
				</Button>
			</p>
			<Refusal error={ error } onRemove={ () => setError( null ) } />
		</section>
	);
}

/**
 * The screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Screen.
 */
export default function IndexingScreen( { data } ) {
	const [ filter, setFilter ] = useState( {
		engine: '',
		state: '',
		search: '',
		page: 1,
	} );
	const load = useLoad( `/indexing/console?${ query( filter ) }` );
	const [ retrying, setRetrying ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( change ) => setFilter( { ...filter, page: 1, ...change } );
	const retry = ( ids ) => {
		setRetrying( true );
		setError( null );
		seoFetch( '/indexing/console/retry', {
			method: 'POST',
			data: { ids },
		} )
			.then( () => load.reload() )
			.catch( setError )
			.finally( () => setRetrying( false ) );
	};
	const download = () =>
		seoFetch( '/indexing/console/export', { parse: false } )
			.then( ( res ) => res.blob() )
			.then( ( blob ) => {
				const a = document.createElement( 'a' );
				a.href = URL.createObjectURL( blob );
				a.download = 'indexing-log.csv';
				a.click();
				URL.revokeObjectURL( a.href );
			} )
			.catch( setError );
	return (
		<div className="zseo-admin zseo-indexing">
			<Card>
				<CardBody className="zseo-stack">
					<p>
						{ __(
							'Tell the search engines about pages the moment you want them found, and see exactly what each one answered.',
							'zinn-seo'
						) }{ ' ' }
						<GuideLink data={ data } id="indexing" />
					</p>
					<Loaded load={ load }>
						{ ( log ) => {
							const failed = ( log.counts || {} ).failed || 0;
							const pages = Math.max(
								1,
								Math.ceil( ( log.total || 0 ) / 50 )
							);
							return (
								<>
									<Send
										engines={
											log.engines || data.indexing || {}
										}
										done={ () => load.reload() }
									/>
									<Rules
										rules={ log.rules }
										done={ () => load.reload() }
									/>
									<section className="zseo-stack">
										<h3>
											{ __(
												'What the search engines answered',
												'zinn-seo'
											) }
										</h3>
										{ log.waiting > 0 && (
											<p className="zseo-muted">
												{ sprintf(
													/* translators: %s: a number of pages. */
													__(
														'%s pages are waiting to be sent to Bing (within its daily allowance).',
														'zinn-seo'
													),
													count( log.waiting )
												) }
											</p>
										) }
										<div className="zseo-row">
											<SelectControl
												__nextHasNoMarginBottom
												__next40pxDefaultSize
												label={ __(
													'Search engine',
													'zinn-seo'
												) }
												value={ filter.engine }
												onChange={ ( engine ) =>
													set( { engine } )
												}
												options={ [
													{
														value: '',
														label: __(
															'All',
															'zinn-seo'
														),
													},
													...[
														'indexnow',
														'bing',
														'google',
													].map( ( e ) => ( {
														value: e,
														label: engineLabel( e ),
													} ) ),
												] }
											/>
											<SelectControl
												__nextHasNoMarginBottom
												__next40pxDefaultSize
												label={ __(
													'Result',
													'zinn-seo'
												) }
												value={ filter.state }
												onChange={ ( state ) =>
													set( { state } )
												}
												options={ [
													{
														value: '',
														label: __(
															'All',
															'zinn-seo'
														),
													},
													...[
														'sent',
														'failed',
														'skipped',
														'retried',
													].map( ( s ) => ( {
														value: s,
														label: stateLabel( s ),
													} ) ),
												] }
											/>
											<TextControl
												__nextHasNoMarginBottom
												__next40pxDefaultSize
												label={ __(
													'Address contains',
													'zinn-seo'
												) }
												value={ filter.search }
												onChange={ ( search ) =>
													set( { search } )
												}
											/>
										</div>
										<div className="zseo-row">
											<Button
												variant="secondary"
												disabled={
													! failed || retrying
												}
												isBusy={ retrying }
												onClick={ () => retry( [] ) }
											>
												{ sprintf(
													/* translators: %s: a number of failed sends. */
													__(
														'Send the %s failed again',
														'zinn-seo'
													),
													count( failed )
												) }
											</Button>
											<Button
												variant="tertiary"
												disabled={ ! log.total }
												onClick={ download }
											>
												{ __(
													'Download the log (CSV)',
													'zinn-seo'
												) }
											</Button>
										</div>
										<Refusal
											error={ error }
											onRemove={ () => setError( null ) }
										/>
										<Table
											label={ __( 'Log', 'zinn-seo' ) }
											rowKey={ ( r ) => r.id }
											rows={ log.rows || [] }
											columns={ [
												{
													id: 'u',
													label: __(
														'Page',
														'zinn-seo'
													),
													render: ( r ) => (
														<bdi className="zseo-break">
															{ r.url.replace(
																/^https?:\/\/(www\.)?/,
																''
															) }
														</bdi>
													),
												},
												{
													id: 'e',
													label: __(
														'Search engine',
														'zinn-seo'
													),
													render: ( r ) =>
														engineLabel( r.engine ),
												},
												{
													id: 's',
													label: __(
														'Result',
														'zinn-seo'
													),
													render: ( r ) => (
														<span
															className={ `zseo-badge is-${ badge( r.state ) }` }
														>
															{ stateLabel(
																r.state
															) }
														</span>
													),
												},
												{
													id: 'm',
													label: __(
														'Answer',
														'zinn-seo'
													),
													render: ( r ) =>
														r.message ||
														( r.code
															? sprintf(
																	/* translators: %d: an HTTP status. */
																	__(
																		'Accepted (%d)',
																		'zinn-seo'
																	),
																	r.code
																)
															: '' ),
												},
												{
													id: 'w',
													label: __(
														'When',
														'zinn-seo'
													),
													render: ( r ) =>
														new Date(
															r.at * 1000
														).toLocaleString(
															document
																.documentElement
																.lang ||
																undefined
														),
												},
												{
													id: 'a',
													label: __(
														'Action',
														'zinn-seo'
													),
													render: ( r ) =>
														r.state === 'failed' ? (
															<Button
																variant="link"
																disabled={
																	retrying
																}
																onClick={ () =>
																	retry( [
																		r.id,
																	] )
																}
															>
																{ __(
																	'Send again',
																	'zinn-seo'
																) }
															</Button>
														) : null,
												},
											] }
										/>
										{ pages > 1 && (
											<div className="zseo-row">
												<Button
													variant="tertiary"
													disabled={
														filter.page <= 1
													}
													onClick={ () =>
														setFilter( {
															...filter,
															page:
																filter.page - 1,
														} )
													}
												>
													{ __(
														'Newer',
														'zinn-seo'
													) }
												</Button>
												<span>
													{ sprintf(
														/* translators: 1: page, 2: pages. */
														__(
															'Page %1$s of %2$s',
															'zinn-seo'
														),
														count( filter.page ),
														count( pages )
													) }
												</span>
												<Button
													variant="tertiary"
													disabled={
														filter.page >= pages
													}
													onClick={ () =>
														setFilter( {
															...filter,
															page:
																filter.page + 1,
														} )
													}
												>
													{ __(
														'Older',
														'zinn-seo'
													) }
												</Button>
											</div>
										) }
									</section>
								</>
							);
						} }
					</Loaded>
				</CardBody>
			</Card>
		</div>
	);
}
