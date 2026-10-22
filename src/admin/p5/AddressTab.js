/**
 * Address changes: plan a permalink change, see every address that moves, write the redirects
 * first, then switch; old domain names (zs-333).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch, useSettings } from '../api';
import { JobLine, RunButton, Table, useJob, useRoute } from '../p4/shared';
import { hosts, structures } from './pure';

/**
 * Plan and run a permalink change.
 *
 * @param {Object}   props
 * @param {Object}   props.info   GET /address.
 * @param {Function} props.reload Reload it.
 * @return {Element} Card.
 */
function Planner( { info, reload } ) {
	const [ plan, setPlan ] = useState( {
		structure: info.current.structure,
		category_base: info.current.category_base,
		tag_base: info.current.tag_base,
	} );
	const [ preview, setPreview ] = useState( null );
	const [ rows, setRows ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ confirm, setConfirm ] = useState( false );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/address' ).then( ( r ) => r.job ),
		() => {
			reload();
			setPreview( null );
			setRows( [] );
		}
	);
	useEffect( () => {
		setJob( info.job );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ info ] );
	const same =
		plan.structure === info.current.structure &&
		plan.category_base === info.current.category_base &&
		plan.tag_base === info.current.tag_base;
	const look = ( after, replace ) =>
		seoFetch( '/address/preview', {
			method: 'POST',
			data: { ...plan, after, per: 200 },
		} ).then( ( p ) => {
			setPreview( p );
			setRows( replace ? p.items : [ ...rows, ...p.items ] );
		} );
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __(
						'Change the permalink structure safely',
						'zinn-seo'
					) }
				</h3>
			</CardHeader>
			<CardBody className="zseo-stack">
				<p className="zseo-muted">
					{ __(
						'A new structure moves the address of every post at once, and WordPress redirects none of them. Plan it here: see every address that will move, then the redirects are written first and the new structure is switched on only when the last one is in place.',
						'zinn-seo'
					) }
				</p>
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				<p>
					{ __( 'Now:', 'zinn-seo' ) }{ ' ' }
					<code className="zseo-code">
						{ info.current.structure ||
							__( 'plain (?p=123)', 'zinn-seo' ) }
					</code>
				</p>
				<div
					className="zseo-actions zseo-actions--start"
					role="group"
					aria-label={ __( 'Common structures', 'zinn-seo' ) }
				>
					{ structures().map( ( s ) => (
						<Button
							key={ s.value }
							variant={
								plan.structure === s.value
									? 'primary'
									: 'secondary'
							}
							size="compact"
							onClick={ () =>
								setPlan( { ...plan, structure: s.value } )
							}
						>
							{ s.label }
						</Button>
					) ) }
				</div>
				<div className="zseo-row zseo-row--top">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'New structure', 'zinn-seo' ) }
						className="zseo-address"
						value={ plan.structure }
						help={ sprintf(
							/* translators: %s: the tags, e.g. %year% %postname%. */
							__( 'Tags: %s', 'zinn-seo' ),
							info.tags.join( ' ' )
						) }
						onChange={ ( v ) =>
							setPlan( { ...plan, structure: v } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Category base', 'zinn-seo' ) }
						value={ plan.category_base }
						placeholder="category"
						onChange={ ( v ) =>
							setPlan( { ...plan, category_base: v } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Tag base', 'zinn-seo' ) }
						value={ plan.tag_base }
						placeholder="tag"
						onChange={ ( v ) =>
							setPlan( { ...plan, tag_base: v } )
						}
					/>
				</div>
				<div className="zseo-actions zseo-actions--start">
					<RunButton
						disabled={ same || ( job && job.status === 'pending' ) }
						onError={ setError }
						run={ () => look( '', true ) }
					>
						{ __( 'Show every address that moves', 'zinn-seo' ) }
					</RunButton>
				</div>
				{ preview && (
					<div className="zseo-stack" role="status">
						<p>
							{ sprintf(
								/* translators: 1: published posts and pages, 2: terms. */
								__(
									'The site has %1$d published posts and pages and %2$d categories, tags and other terms. Addresses that move:',
									'zinn-seo'
								),
								preview.totals.posts,
								preview.totals.terms
							) }
						</p>
						<Table
							columns={ [
								{
									id: 'from',
									label: __( 'Now', 'zinn-seo' ),
									render: ( r ) => (
										<span className="zseo-address">
											{ r.from }
										</span>
									),
								},
								{
									id: 'to',
									label: __( 'After the change', 'zinn-seo' ),
									render: ( r ) => (
										<span className="zseo-address">
											{ r.to }
										</span>
									),
								},
							] }
							rows={ rows.map( ( r ) => ( {
								...r,
								id: `${ r.kind }${ r.id }`,
							} ) ) }
							label={ __( 'Addresses that move', 'zinn-seo' ) }
							empty={ __(
								'None of the addresses looked at so far moves.',
								'zinn-seo'
							) }
						/>
						{ preview.next && (
							<p>
								<RunButton
									onError={ setError }
									run={ () => look( preview.next, false ) }
								>
									{ __( 'Show more', 'zinn-seo' ) }
								</RunButton>
							</p>
						) }
						{ ! confirm ? (
							<p>
								<Button
									variant="primary"
									onClick={ () => setConfirm( true ) }
								>
									{ __(
										'Write the redirects, then switch',
										'zinn-seo'
									) }
								</Button>
							</p>
						) : (
							<Notice status="warning" isDismissible={ false }>
								<p>
									{ __(
										'Every moved address gets a permanent redirect; then the new structure is saved. This runs in the background and can take a few minutes on a big site.',
										'zinn-seo'
									) }
								</p>
								<div className="zseo-actions zseo-actions--start">
									<RunButton
										variant="primary"
										onError={ setError }
										run={ () =>
											seoFetch( '/address/start', {
												method: 'POST',
												data: plan,
											} ).then( ( j ) => {
												setJob( j );
												setConfirm( false );
											} )
										}
									>
										{ __( 'Yes, change it', 'zinn-seo' ) }
									</RunButton>
									<Button
										variant="tertiary"
										onClick={ () => setConfirm( false ) }
									>
										{ __( 'Cancel', 'zinn-seo' ) }
									</Button>
								</div>
							</Notice>
						) }
					</div>
				) }
				<JobLine job={ job } />
				{ info.last && (
					<p className="zseo-muted">
						{ sprintf(
							/* translators: 1: number of redirects, 2: old structure, 3: new structure, 4: date. */
							__(
								'Last change: %1$d redirects written, from %2$s to %3$s (%4$s).',
								'zinn-seo'
							),
							info.last.written,
							info.last.from.structure,
							info.last.to.structure,
							new Date( info.last.at * 1000 ).toLocaleString()
						) }
					</p>
				) }
				<p className="zseo-muted">
					{ __(
						'Changed it on Settings → Permalinks or from the command line instead? The redirects are written for you straight after, from the old structure.',
						'zinn-seo'
					) }
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * Old domain names, and rules for the old server.
 *
 * @param {Object} props
 * @param {Object} props.info GET /address.
 * @return {Element} Card.
 */
function Domains( { info } ) {
	const { settings, save } = useSettings();
	const [ text, setText ] = useState( null );
	const [ old, setOld ] = useState( '' );
	const [ rules, setRules ] = useState( null );
	const [ msg, setMsg ] = useState( '' );
	const [ error, setError ] = useState( '' );
	if ( ! settings ) {
		return <Spinner />;
	}
	const value =
		null === text ? ( settings.redirect_aliases || [] ).join( '\n' ) : text;
	return (
		<Card>
			<CardHeader>
				<h3 className="zseo-card-title">
					{ __( 'Moving to a new domain', 'zinn-seo' ) }
				</h3>
			</CardHeader>
			<CardBody className="zseo-stack">
				{ ( msg || error ) && (
					<Notice
						status={ error ? 'error' : 'success' }
						onRemove={ () => {
							setMsg( '' );
							setError( '' );
						} }
					>
						{ error || msg }
					</Notice>
				) }
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'Old domain names that still reach this site',
						'zinn-seo'
					) }
					help={ sprintf(
						/* translators: %s: this site's address. */
						__(
							'One per line. A visitor arriving under one of these names is sent to the same page on %s before anything else loads. When you change the site address, the old name is added here for you.',
							'zinn-seo'
						),
						info.home
					) }
					className="zseo-code-input"
					rows={ 3 }
					value={ value }
					onChange={ setText }
				/>
				<p>
					<Button
						variant="secondary"
						disabled={ null === text }
						onClick={ () =>
							save( { redirect_aliases: hosts( text ) } )
								.then( () => {
									setText( null );
									setMsg( __( 'Saved.', 'zinn-seo' ) );
								} )
								.catch( ( e ) => setError( errorText( e ) ) )
						}
					>
						{ __( 'Save the names', 'zinn-seo' ) }
					</Button>
				</p>
				<p className="zseo-muted">
					{ __(
						'If the old domain points at another server, that server must send visitors here. Enter the old domain to get the rules for it:',
						'zinn-seo'
					) }
				</p>
				<div className="zseo-row">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Old domain', 'zinn-seo' ) }
						className="zseo-address"
						value={ old }
						placeholder="old-example.com"
						onChange={ setOld }
					/>
					<div>
						<RunButton
							disabled={ ! hosts( old ).length }
							onError={ setError }
							run={ () =>
								seoFetch(
									`/address/domain?from=${ encodeURIComponent( hosts( old )[ 0 ] ) }`
								).then( setRules )
							}
						>
							{ __( 'Show the rules', 'zinn-seo' ) }
						</RunButton>
					</div>
				</div>
				{ rules &&
					[
						[ 'nginx', 'Nginx' ],
						[ 'apache', 'Apache' ],
						[ 'caddy', 'Caddy' ],
					].map( ( [ k, name ] ) => (
						<div key={ k } className="zseo-stack">
							<h4>{ name }</h4>
							<pre className="zseo-pre" tabIndex={ 0 }>
								{ rules[ k ] }
							</pre>
						</div>
					) ) }
			</CardBody>
		</Card>
	);
}

/**
 * The address-changes tab.
 *
 * @return {Element} Tab.
 */
export default function AddressTab() {
	const info = useRoute( '/address' );
	if ( info.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ info.error }
			</Notice>
		);
	}
	if ( ! info.data ) {
		return <Spinner />;
	}
	return (
		<div className="zseo-stack">
			<Planner info={ info.data } reload={ info.reload } />
			<Domains info={ info.data } />
		</div>
	);
}
