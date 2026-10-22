/**
 * The 404 monitor: missing addresses as counters, one-click redirect, ignore, settings (zs-093).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import { Table } from '../p4/shared';
import { codeChoices, moves, when } from './pure';

/**
 * "Redirect…" for one missing address.
 *
 * @param {Object}   props
 * @param {Object}   props.row     The 404 row.
 * @param {string}   props.kind    404|404b.
 * @param {Function} props.onDone  Called after.
 * @param {Function} props.onClose Close.
 * @return {Element} Modal.
 */
function RedirectModal( { row, kind, onDone, onClose } ) {
	const [ target, setTarget ] = useState(
		row.suggest ? row.suggest.url : ''
	);
	const [ code, setCode ] = useState( '301' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	return (
		<Modal
			title={ __( 'Redirect a missing address', 'zinn-seo' ) }
			onRequestClose={ onClose }
			className="zseo-modal"
		>
			<form
				className="zseo-stack"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setBusy( true );
					seoFetch( '/notfound/redirect', {
						method: 'POST',
						data: {
							kind,
							address: row.key,
							target: moves( code ) ? target : '',
							code: Number( code ),
						},
					} )
						.then( onDone )
						.catch( ( er ) => setError( errorText( er ) ) )
						.finally( () => setBusy( false ) );
				} }
			>
				<p className="zseo-address">{ row.key }</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Type', 'zinn-seo' ) }
					value={ code }
					options={ codeChoices() }
					onChange={ setCode }
				/>
				{ moves( code ) && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Send visitors to', 'zinn-seo' ) }
						className="zseo-address"
						value={ target }
						placeholder="/new-address/"
						onChange={ setTarget }
					/>
				) }
				<div className="zseo-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'zinn-seo' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={
							busy || ( moves( code ) && ! target.trim() )
						}
					>
						{ __( 'Add the redirect', 'zinn-seo' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * The 404 settings.
 *
 * @return {Element} Card.
 */
function NotfoundSettings() {
	const { settings, save, error } = useSettings();
	const [ ignore, setIgnore ] = useState( null );
	const [ saved, setSaved ] = useState( '' );
	const [ fail, setFail ] = useState( '' );
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
		save( changes )
			.then( () => setSaved( __( 'Saved.', 'zinn-seo' ) ) )
			.catch( ( e ) => setFail( errorText( e ) ) );
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'What is counted', 'zinn-seo' ) }
				</h3>
			</CardHeader>
			<CardBody className="zseo-stack">
				{ ( saved || fail ) && (
					<Notice
						status={ fail ? 'error' : 'success' }
						onRemove={ () => {
							setSaved( '' );
							setFail( '' );
						} }
					>
						{ fail || saved }
					</Notice>
				) }
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Robots and crawlers', 'zinn-seo' ) }
					value={ settings.notfound_bots }
					options={ [
						{
							value: 'apart',
							label: __(
								'Count them in their own list',
								'zinn-seo'
							),
						},
						{
							value: 'skip',
							label: __( 'Do not count them', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => put( { notfound_bots: v } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Never count these addresses', 'zinn-seo' ) }
					help={ __(
						'One per line; * stands for anything. The defaults leave out the PHP files and hidden files that scanners ask for on every site.',
						'zinn-seo'
					) }
					className="zseo-code-input"
					rows={ 5 }
					value={
						null === ignore ? settings.notfound_ignore : ignore
					}
					onChange={ setIgnore }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'E-mail me the new missing addresses every week',
						'zinn-seo'
					) }
					checked={ !! settings.notfound_email }
					onChange={ ( v ) => put( { notfound_email: v } ) }
				/>
				<p>
					<Button
						variant="secondary"
						disabled={ null === ignore }
						onClick={ () =>
							put( { notfound_ignore: ignore } ).then( () =>
								setIgnore( null )
							)
						}
					>
						{ __( 'Save the list', 'zinn-seo' ) }
					</Button>
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * The 404 tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function NotfoundTab( { data } ) {
	const canManage = !! ( data.redirects || {} ).canManage;
	const [ kind, setKind ] = useState( '404' );
	const [ order, setOrder ] = useState( 'hits' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( null );
	const [ rows, setRows ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ modal, setModal ] = useState( null );
	const load = ( after, replace ) => {
		const q = new URLSearchParams( { kind, order, after, search } );
		return seoFetch( `/notfound?${ q.toString() }` )
			.then( ( r ) => {
				setPage( r );
				setRows( replace ? r.items : [ ...rows, ...r.items ] );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	};
	useEffect( () => {
		const t = setTimeout( () => load( '', true ), 300 );
		return () => clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind, order, search ] );
	const forget = ( hash ) =>
		seoFetch( '/notfound', {
			method: 'DELETE',
			data: hash ? { kind, hash } : { kind, all: true },
		} )
			.then( () => load( '', true ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	const columns = [
		{
			id: 'key',
			label: __( 'Missing address', 'zinn-seo' ),
			render: ( r ) => (
				<span className="zseo-cell-title">
					<span className="zseo-address">{ r.key }</span>
					{ r.redirected && (
						<span className="zseo-tag">
							{ __( 'redirected now', 'zinn-seo' ) }
						</span>
					) }
				</span>
			),
		},
		{
			id: 'hits',
			label: __( 'Times asked', 'zinn-seo' ),
			render: ( r ) => String( r.hits ),
		},
		{
			id: 'seen',
			label: __( 'Last asked', 'zinn-seo' ),
			render: ( r ) => when( r.last_seen ),
		},
		{
			id: 'ref',
			label: __( 'Linked from', 'zinn-seo' ),
			render: ( r ) =>
				r.sample ? (
					<span className="zseo-address">{ r.sample }</span>
				) : (
					<span className="zseo-muted">
						{ __( 'Typed or bookmarked', 'zinn-seo' ) }
					</span>
				),
		},
		{
			id: 'suggest',
			label: __( 'Probably meant', 'zinn-seo' ),
			render: ( r ) =>
				r.suggest ? (
					<a href={ r.suggest.url } target="_blank" rel="noreferrer">
						{ r.suggest.title }
					</a>
				) : (
					<span className="zseo-muted">–</span>
				),
		},
		...( canManage
			? [
					{
						id: 'do',
						label: __( 'Actions', 'zinn-seo' ),
						render: ( r ) => (
							<span className="zseo-actions zseo-actions--start">
								{ page && page.redirects && (
									<Button
										variant="secondary"
										size="compact"
										onClick={ () => setModal( r ) }
									>
										{ __( 'Redirect…', 'zinn-seo' ) }
									</Button>
								) }
								<Button
									variant="link"
									onClick={ () =>
										seoFetch( '/notfound/ignore', {
											method: 'POST',
											data: { kind, address: r.key },
										} )
											.then( () => {
												setNotice(
													sprintf(
														/* translators: %s: an address. */
														__(
															'%s will not be counted again.',
															'zinn-seo'
														),
														r.key
													)
												);
												load( '', true );
											} )
											.catch( ( e ) =>
												setError( errorText( e ) )
											)
									}
								>
									{ __( 'Ignore', 'zinn-seo' ) }
								</Button>
								<Button
									variant="link"
									isDestructive
									onClick={ () => forget( r.hash ) }
								>
									{ __( 'Forget', 'zinn-seo' ) }
								</Button>
							</span>
						),
					},
				]
			: [] ),
	];
	return (
		<div className="zseo-stack">
			{ ( error || notice ) && (
				<Notice
					status={ error ? 'error' : 'success' }
					onRemove={ () => {
						setError( '' );
						setNotice( '' );
					} }
				>
					{ error || notice }
				</Notice>
			) }
			<p className="zseo-muted">
				{ __(
					'Each missing address is one counter — how often, when first and last, and the page that linked to it — never a row per visit, so the list cannot grow your database: it keeps the most recent 10,000 addresses.',
					'zinn-seo'
				) }
			</p>
			{ page && (
				<dl className="zseo-totals">
					<div>
						<dt>{ __( 'Asked for by people', 'zinn-seo' ) }</dt>
						<dd>{ page.counts[ '404' ] }</dd>
					</div>
					<div>
						<dt>{ __( 'Asked for by robots', 'zinn-seo' ) }</dt>
						<dd>{ page.counts[ '404b' ] }</dd>
					</div>
					<div>
						<dt>{ __( 'Kept at most', 'zinn-seo' ) }</dt>
						<dd>{ page.ceiling }</dd>
					</div>
				</dl>
			) }
			<div className="zseo-filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Asked for by', 'zinn-seo' ) }
					value={ kind }
					options={ [
						{ value: '404', label: __( 'People', 'zinn-seo' ) },
						{
							value: '404b',
							label: __( 'Robots and crawlers', 'zinn-seo' ),
						},
					] }
					onChange={ setKind }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Order', 'zinn-seo' ) }
					value={ order }
					options={ [
						{
							value: 'hits',
							label: __( 'Most asked', 'zinn-seo' ),
						},
						{
							value: 'last_seen',
							label: __( 'Most recent', 'zinn-seo' ),
						},
						{
							value: 'first_seen',
							label: __( 'Newest', 'zinn-seo' ),
						},
					] }
					onChange={ setOrder }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Search', 'zinn-seo' ) }
					type="search"
					value={ search }
					onChange={ setSearch }
				/>
			</div>
			{ ! page ? (
				<Spinner />
			) : (
				<Table
					columns={ columns }
					rows={ rows.map( ( r ) => ( { ...r, id: r.hash } ) ) }
					label={ __( 'Missing addresses', 'zinn-seo' ) }
					empty={ __(
						'No missing address has been asked for yet.',
						'zinn-seo'
					) }
				/>
			) }
			<div className="zseo-actions zseo-actions--start">
				{ page && page.next && (
					<Button
						variant="secondary"
						onClick={ () => load( page.next, false ) }
					>
						{ __( 'Show more', 'zinn-seo' ) }
					</Button>
				) }
				{ canManage && rows.length > 0 && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => forget( '' ) }
					>
						{ __( 'Forget this whole list', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			{ canManage && <NotfoundSettings /> }
			{ modal && (
				<RedirectModal
					row={ modal }
					kind={ kind }
					onClose={ () => setModal( null ) }
					onDone={ () => {
						setModal( null );
						setNotice(
							sprintf(
								/* translators: %s: an address. */
								__( 'Redirect added for %s.', 'zinn-seo' ),
								modal.key
							)
						);
						load( '', true );
					} }
				/>
			) }
		</div>
	);
}
