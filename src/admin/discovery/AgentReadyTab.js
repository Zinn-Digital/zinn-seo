import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, PanelBody, Spinner } from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * The group headings, in the order the checks are shown.
 *
 * @return {Array<{id: string, title: string}>} Groups.
 */
export function agentReadyGroups() {
	return [
		{ id: 'access', title: __( 'Access', 'zinn-seo' ) },
		{ id: 'discovery', title: __( 'Discovery', 'zinn-seo' ) },
		{ id: 'understanding', title: __( 'Understanding', 'zinn-seo' ) },
		{ id: 'action', title: __( 'Action', 'zinn-seo' ) },
	];
}

/**
 * The sentence for a grade.
 *
 * @param {string} grade ready, nearly or not_ready.
 * @return {string} Sentence.
 */
export function gradeText( grade ) {
	if ( 'ready' === grade ) {
		return __(
			'Agent ready: AI assistants and answer engines can find, read and understand the site.',
			'zinn-seo'
		);
	}
	if ( 'nearly' === grade ) {
		return __(
			'Nearly there: fix the checks below to be fully agent ready.',
			'zinn-seo'
		);
	}
	return __(
		'Not agent ready yet: AI assistants will struggle to find or understand the site.',
		'zinn-seo'
	);
}

/**
 * Agent Ready (zs-784): one score for how ready the site is for AI agents and answer engines,
 * every check with its one-click fix or the screen that fixes it.
 *
 * @return {Element} The tab.
 */
export default function AgentReadyTab() {
	const [ report, setReport ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	const load = ( live ) => {
		setBusy( live ? 'live' : 'load' );
		setError( '' );
		return seoFetch( `/agent-ready${ live ? '?live=1' : '' }` )
			.then( setReport )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};

	useEffect( () => {
		load( false );
	}, [] );

	const fix = ( check ) => {
		setBusy( check );
		setError( '' );
		seoFetch( '/agent-ready/fix', { method: 'POST', data: { check } } )
			.then( setReport )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! report ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const tone = {
		ready: 'is-active',
		nearly: 'is-limited',
		not_ready: 'is-none',
	}[ report.grade ];

	return (
		<div className="zseo-stack">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<PanelBody title={ __( 'Agent Ready', 'zinn-seo' ) }>
				<div className="zseo-stack">
					<p className="zseo-agent-score">
						<strong>
							{ sprintf(
								/* translators: %d: a score from 0 to 100. */
								__( '%d out of 100', 'zinn-seo' ),
								report.score
							) }
						</strong>
						<span className={ `zseo-badge ${ tone }` }>
							{ 'ready' === report.grade &&
								__( 'Ready', 'zinn-seo' ) }
							{ 'nearly' === report.grade &&
								__( 'Nearly there', 'zinn-seo' ) }
							{ 'not_ready' === report.grade &&
								__( 'Not ready', 'zinn-seo' ) }
						</span>
					</p>
					<p>{ gradeText( report.grade ) }</p>
					<div>
						<Button
							variant="secondary"
							isBusy={ 'live' === busy }
							disabled={ '' !== busy }
							onClick={ () => load( true ) }
						>
							{ __(
								'Check what the site really serves',
								'zinn-seo'
							) }
						</Button>
					</div>
					{ report.live && (
						<p className="zseo-muted">
							{ __(
								'Checked over HTTP: robots.txt and llms.txt as a visitor gets them.',
								'zinn-seo'
							) }
						</p>
					) }
				</div>
			</PanelBody>
			{ agentReadyGroups().map( ( group ) => (
				<PanelBody key={ group.id } title={ group.title }>
					<ul className="zseo-stack zseo-agent-checks">
						{ report.checks
							.filter( ( c ) => c.group === group.id )
							.map( ( c ) => (
								<li key={ c.id } data-check={ c.id }>
									<span
										className={ `zseo-badge ${
											{
												pass: 'is-active',
												fail: 'is-none',
												pro: 'is-pro',
											}[ c.state ]
										}` }
									>
										{ 'pass' === c.state &&
											__( 'Passed', 'zinn-seo' ) }
										{ 'fail' === c.state &&
											__( 'To fix', 'zinn-seo' ) }
										{ 'pro' === c.state &&
											__( 'Pro', 'zinn-seo' ) }
									</span>{ ' ' }
									<strong>{ c.label }</strong>
									{ c.detail && <p>{ c.detail }</p> }
									{ c.fix && 'auto' === c.fix.type && (
										<Button
											variant="primary"
											isBusy={ busy === c.id }
											disabled={ '' !== busy }
											onClick={ () => fix( c.id ) }
										>
											{ c.fix.label }
										</Button>
									) }
									{ c.fix && 'auto' !== c.fix.type && (
										<Button
											variant="link"
											href={ c.fix.url }
										>
											{ c.fix.label }
										</Button>
									) }
								</li>
							) ) }
					</ul>
				</PanelBody>
			) ) }
		</div>
	);
}
