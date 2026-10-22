/**
 * The conflict detector (zs-703).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';

import { seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { RunButton, useRoute } from '../p4/shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function ConflictsTab( { data } ) {
	const state = useRoute( '/conflicts' );
	const [ error, setError ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	if ( ! state.data ) {
		return <Spinner />;
	}
	const r = state.data;
	const scan = () =>
		seoFetch( '/conflicts/scan', { method: 'POST' } ).then( state.setData );
	const act = ( verb, id ) =>
		seoFetch( '/conflicts/' + verb, { method: 'POST', data: { id } } ).then(
			state.setData
		);
	const previewable = ( id ) =>
		/^(join-entities|microdata:|jsonld:)/.test( id );
	const look = ( id ) =>
		seoFetch( '/conflicts/preview?id=' + encodeURIComponent( id ) ).then(
			setPreview
		);
	const problemsText = ( p, tag ) => {
		const n = ( p || {} )[ tag ] || 0;
		return n
			? sprintf(
					/* translators: %d: how many times the problem shows on a page. */
					__( '%d to fix', 'zinn-seo' ),
					n
				)
			: __( 'nothing to fix', 'zinn-seo' );
	};
	const pages = {
		home: __( 'home page', 'zinn-seo' ),
		post: __( 'newest post', 'zinn-seo' ),
		category: __( 'a category', 'zinn-seo' ),
	};
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Two titles or two descriptions on one page leave search engines guessing which is right. This reads your pages exactly as visitors get them and names whatever prints a second one.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-conflicts" />
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<p>
				<RunButton variant="primary" run={ scan } onError={ setError }>
					{ r.at
						? __( 'Check my pages again', 'zinn-seo' )
						: __( 'Check my pages', 'zinn-seo' ) }
				</RunButton>{ ' ' }
				{ r.at && (
					<span className="zseo-muted">
						{ sprintf(
							/* translators: %s: a date and time. */
							__( 'Last checked %s.', 'zinn-seo' ),
							new Date( r.at * 1000 ).toLocaleString()
						) }
					</span>
				) }
			</p>
			{ r.at && 0 === ( r.issues || [] ).length && (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'No conflicts: every page carries one title, one description and one set of sharing tags.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ ( r.issues || [] ).map( ( i ) => (
				<Card key={ i.id } className="zseo-x1a-issue">
					<CardBody>
						<h3 className="zseo-x1a-issue__title">{ i.title }</h3>
						<p>{ i.detail }</p>
						{ i.pages && i.pages.length > 0 && (
							<p className="zseo-muted">
								{ __( 'Seen on:', 'zinn-seo' ) }{ ' ' }
								{ i.pages
									.map( ( p ) => pages[ p ] || p )
									.join( ', ' ) }
							</p>
						) }
						{ i.fix && ! previewable( i.fix ) && (
							<RunButton
								variant="secondary"
								onError={ setError }
								run={ () => act( 'fix', i.fix ) }
							>
								{ __( 'Fix it now', 'zinn-seo' ) }
							</RunButton>
						) }
						{ i.fix && previewable( i.fix ) && (
							<RunButton
								variant="secondary"
								onError={ setError }
								run={ () => look( i.fix ) }
							>
								{ __( 'Fix it for me', 'zinn-seo' ) }
							</RunButton>
						) }
						{ ! i.fix &&
							[ 'jsonld', 'microdata' ].includes( i.tag ) &&
							r.tell && (
								<Button variant="link" href={ r.tell }>
									{ __(
										'Tell us which plugin or theme it is',
										'zinn-seo'
									) }
								</Button>
							) }
						{ preview && preview.id === i.fix && (
							<div className="zseo-stack">
								<p>
									{ __(
										'What this changes on the pages checked, before anything is switched on:',
										'zinn-seo'
									) }
								</p>
								<ul>
									{ Object.keys( preview.pages || {} ).map(
										( k ) => {
											const p = preview.pages[ k ];
											return (
												<li key={ k }>
													{ pages[ k ] || k }:{ ' ' }
													{ p.read
														? sprintf(
																/* translators: 1: problems now, 2: problems after the fix, 3: whether the visible text stays the same. */
																__(
																	'now %1$s, after %2$s; %3$s',
																	'zinn-seo'
																),
																problemsText(
																	p.before,
																	i.tag
																),
																problemsText(
																	p.after,
																	i.tag
																),
																p.text_same
																	? __(
																			'what visitors read stays the same',
																			'zinn-seo'
																		)
																	: __(
																			'what visitors read would change',
																			'zinn-seo'
																		)
															)
														: __(
																'could not be read',
																'zinn-seo'
															) }
												</li>
											);
										}
									) }
								</ul>
								<p>
									<RunButton
										variant="primary"
										onError={ setError }
										run={ () =>
											act( 'fix', i.fix ).then( () =>
												setPreview( null )
											)
										}
									>
										{ __(
											'Switch it on and check again',
											'zinn-seo'
										) }
									</RunButton>{ ' ' }
									<Button
										variant="tertiary"
										onClick={ () => setPreview( null ) }
									>
										{ __( 'Cancel', 'zinn-seo' ) }
									</Button>
								</p>
							</div>
						) }
					</CardBody>
				</Card>
			) ) }
			{ ( r.fixes || [] ).length > 0 && (
				<div className="zseo-stack">
					<h3>{ __( 'Fixes switched on', 'zinn-seo' ) }</h3>
					{ r.fixes.map( ( f ) => (
						<p key={ f }>
							{ ( r.labels || {} )[ f ] || f }{ ' ' }
							<Button
								variant="link"
								onClick={ () =>
									act( 'undo', f ).catch( () => {} )
								}
							>
								{ __( 'Undo', 'zinn-seo' ) }
							</Button>
						</p>
					) ) }
				</div>
			) }
		</div>
	);
}
