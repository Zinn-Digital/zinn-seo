/**
 * Affiliate links (zs-724, Pro): short /go/ addresses marked sponsored, the posts using each,
 * broken ones, and clicks per day.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { Table, useRoute } from '../p4/shared';
import { ErrorLine, MoreButton, usePaged } from './shared';

/**
 * A row of clicks per day as a small bar list (the last 14 days).
 *
 * @param {Object}        props
 * @param {Array<Object>} props.days `{day, clicks}`.
 * @return {Element} Bars.
 */
export function ClickBars( { days } ) {
	const last = ( days || [] ).slice( -14 );
	const max = Math.max( 1, ...last.map( ( d ) => d.clicks ) );
	const total = last.reduce( ( n, d ) => n + d.clicks, 0 );
	return (
		<figure className="zseo-x1c-bars">
			<figcaption>
				{ sprintf(
					/* translators: %d: a number of clicks. */
					_n(
						'%d click in the last 14 days',
						'%d clicks in the last 14 days',
						total,
						'zinn-seo'
					),
					total
				) }
			</figcaption>
			<ol>
				{ last.map( ( d ) => (
					<li key={ d.day } title={ `${ d.day }: ${ d.clicks }` }>
						<span
							style={ {
								blockSize: `${ Math.round( ( 100 * d.clicks ) / max ) }%`,
							} }
						/>
					</li>
				) ) }
			</ol>
		</figure>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function AffiliatesTab( { data } ) {
	const on = data.modules && data.modules.affiliates;
	const [ problem, setProblem ] = useState( '' );
	const state = usePaged(
		on ? `/affiliates?problem=${ problem }` : '/settings'
	);
	const clicks = useRoute( on ? '/affiliates/clicks?days=14' : '/settings' );
	const [ form, setForm ] = useState( {
		target: '',
		label: '',
		slug: '',
		nofollow: true,
	} );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ posts, setPosts ] = useState( null );
	if ( ! on ) {
		return (
			<ProTeaser
				title={ __( 'Affiliate links', 'zinn-seo' ) }
				points={ [
					__(
						'Short, tidy /go/ links marked as sponsored for search engines.',
						'zinn-seo'
					),
					__(
						'See which posts use each link, and which links are broken.',
						'zinn-seo'
					),
					__(
						'Clicks per day, without storing anything about who clicked.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	const add = () => {
		setBusy( 'add' );
		setError( '' );
		seoFetch( '/affiliates', { method: 'POST', data: form } )
			.then( ( link ) => {
				setNote(
					sprintf(
						/* translators: %s: a short address. */
						__( 'Added. Link to %s in your posts.', 'zinn-seo' ),
						link.short
					)
				);
				setForm( { target: '', label: '', slug: '', nofollow: true } );
				state.reload();
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const job = ( which ) =>
		seoFetch( `/affiliates/jobs/${ which }`, { method: 'POST' } )
			.then( () =>
				setNote(
					'check' === which
						? __(
								'Checking every link in the background. Come back in a few minutes.',
								'zinn-seo'
							)
						: __(
								'Finding affiliate links in every post, in the background.',
								'zinn-seo'
							)
				)
			)
			.catch( ( e ) => setError( errorText( e ) ) );
	const remove = ( id ) =>
		seoFetch( `/affiliates/${ id }`, { method: 'DELETE' } )
			.then( state.reload )
			.catch( ( e ) => setError( errorText( e ) ) );
	const showPosts = ( link ) =>
		seoFetch( `/affiliates/${ link.id }/posts` )
			.then( ( r ) => setPosts( { link, items: r.items } ) )
			.catch( ( e ) => setError( errorText( e ) ) );
	const rows = state.items;
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Turn long affiliate links into short addresses on your own site, marked as sponsored so search engines are happy. Every visitor is sent straight on; only a count per day is kept.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="affiliates" />
			</p>
			<ErrorLine text={ error } />
			{ note && (
				<Notice status="success" onRemove={ () => setNote( '' ) }>
					{ note }
				</Notice>
			) }
			<section className="zseo-x1c-box" aria-labelledby="zseo-x1c-addaff">
				<h3 id="zseo-x1c-addaff">{ __( 'Add a link', 'zinn-seo' ) }</h3>
				<TextControl
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Affiliate address', 'zinn-seo' ) }
					value={ form.target }
					onChange={ ( v ) => setForm( { ...form, target: v } ) }
				/>
				<div className="zseo-x1c-row">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Name (for you)', 'zinn-seo' ) }
						value={ form.label }
						onChange={ ( v ) => setForm( { ...form, label: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Short name (optional)', 'zinn-seo' ) }
						help={ __( 'Empty: made from the name.', 'zinn-seo' ) }
						value={ form.slug }
						onChange={ ( v ) => setForm( { ...form, slug: v } ) }
					/>
				</div>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Also nofollow', 'zinn-seo' ) }
					checked={ form.nofollow }
					onChange={ ( v ) => setForm( { ...form, nofollow: v } ) }
				/>
				<Button
					variant="primary"
					isBusy={ 'add' === busy }
					disabled={ !! busy || ! form.target }
					onClick={ add }
				>
					{ __( 'Add the link', 'zinn-seo' ) }
				</Button>
			</section>
			{ clicks.data && <ClickBars days={ clicks.data.days } /> }
			<div className="zseo-x1c-row">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Show', 'zinn-seo' ) }
					value={ problem }
					options={ [
						{ value: '', label: __( 'All links', 'zinn-seo' ) },
						{
							value: 'broken',
							label: __( 'Broken links', 'zinn-seo' ),
						},
						{
							value: 'blocked',
							label: __( 'Links that refuse checks', 'zinn-seo' ),
						},
					] }
					onChange={ setProblem }
				/>
				<Button variant="secondary" onClick={ () => job( 'check' ) }>
					{ __( 'Check every link now', 'zinn-seo' ) }
				</Button>
				<Button variant="secondary" onClick={ () => job( 'index' ) }>
					{ __( 'Find links in all posts', 'zinn-seo' ) }
				</Button>
			</div>
			{ state.loading && <Spinner /> }
			<Table
				label={ __( 'Affiliate links', 'zinn-seo' ) }
				empty={ __( 'No affiliate links yet.', 'zinn-seo' ) }
				rows={ rows }
				columns={ [
					{
						id: 'name',
						label: __( 'Link', 'zinn-seo' ),
						render: ( r ) => (
							<>
								<strong>{ r.label }</strong>
								<br />
								<code dir="ltr">{ r.short }</code>
							</>
						),
					},
					{
						id: 'clicks',
						label: __( 'Clicks (30 days)', 'zinn-seo' ),
						render: ( r ) => r.clicks,
					},
					{
						id: 'posts',
						label: __( 'Posts', 'zinn-seo' ),
						render: ( r ) => (
							<Button
								variant="link"
								onClick={ () => showPosts( r ) }
							>
								{ r.posts }
							</Button>
						),
					},
					{
						id: 'state',
						label: __( 'Check', 'zinn-seo' ),
						render: ( r ) => {
							if ( 'broken' === r.problem ) {
								return __( 'Broken', 'zinn-seo' );
							}
							if ( 'blocked' === r.problem ) {
								return __( 'Refuses checks', 'zinn-seo' );
							}
							return r.checked
								? __( 'Works', 'zinn-seo' )
								: __( 'Not checked yet', 'zinn-seo' );
						},
					},
					{
						id: 'act',
						label: __( 'Actions', 'zinn-seo' ),
						render: ( r ) => (
							<Button
								variant="link"
								isDestructive
								onClick={ () => remove( r.id ) }
							>
								{ __( 'Delete', 'zinn-seo' ) }
							</Button>
						),
					},
				] }
			/>
			<MoreButton list={ state } />
			{ posts && (
				<section className="zseo-x1c-box" aria-live="polite">
					<h3>
						{ sprintf(
							/* translators: %s: a link's name. */
							__( 'Posts using %s', 'zinn-seo' ),
							posts.link.label
						) }
					</h3>
					{ posts.items.length ? (
						<ul className="zseo-x1c-posts">
							{ posts.items.map( ( p ) => (
								<li key={ p.id }>
									<a href={ p.edit }>
										{ p.title ||
											__( '(no title)', 'zinn-seo' ) }
									</a>{ ' ' }
									<span className="zseo-muted">
										× { p.uses }
									</span>
								</li>
							) ) }
						</ul>
					) : (
						<p className="zseo-muted">
							{ __( 'No post uses it yet.', 'zinn-seo' ) }
						</p>
					) }
				</section>
			) }
		</div>
	);
}
