/**
 * The headline analyser (zs-723): a click score for any title, what to change, and AI rewrites
 * (each scored the same way) when AI is set up.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { ErrorLine, costLine, runTask } from './shared';

/**
 * The score's word.
 *
 * @param {string} grade good|fair|poor.
 * @return {string} Word.
 */
export function gradeWord( grade ) {
	return (
		{
			good: __( 'Good', 'zinn-seo' ),
			fair: __( 'Could be better', 'zinn-seo' ),
			poor: __( 'Weak', 'zinn-seo' ),
		}[ grade ] || ''
	);
}

/**
 * One title's analysis.
 *
 * @param {Object} props
 * @param {Object} props.result `POST /headline` item.
 * @return {Element} Card.
 */
export function HeadlineResult( { result } ) {
	return (
		<div className={ `zseo-x1c-score is-${ result.grade }` }>
			<p className="zseo-x1c-score__line">
				<span className="zseo-x1c-score__num">{ result.score }</span>
				<span>
					{ gradeWord( result.grade ) } ·{ ' ' }
					{ sprintf(
						/* translators: %d: characters. */
						__( '%d characters', 'zinn-seo' ),
						result.chars
					) }
				</span>
			</p>
			{ ! result.lists && (
				<p className="zseo-muted">
					{ __(
						'Scored on length, numbers and form: word balance is judged in English, German, French, Spanish, Italian, Portuguese and Dutch.',
						'zinn-seo'
					) }
				</p>
			) }
			<ul className="zseo-x1c-checks">
				{ ( result.checks || [] ).map( ( c ) => (
					<li key={ c.id } className={ c.ok ? 'is-ok' : 'is-todo' }>
						<span aria-hidden="true">{ c.ok ? '✓' : '•' }</span>{ ' ' }
						{ c.tip }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function HeadlineTab( { data } ) {
	const [ title, setTitle ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ rewrites, setRewrites ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ cost, setCost ] = useState( '' );
	const ai = data.aiState || {};
	const language = ( data.headline || {} ).language || '';

	const analyse = () => {
		setBusy( 'score' );
		setError( '' );
		seoFetch( '/headline', {
			method: 'POST',
			data: { titles: [ title ], language },
		} )
			.then( ( r ) => setResult( r.items[ 0 ] ) )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const rewrite = () => {
		setBusy( 'ai' );
		setError( '' );
		runTask( 'headline', { title, language } )
			.then( ( { fields, run } ) => {
				setRewrites( fields.titles || [] );
				setCost( costLine( run ) );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					"See how likely people are to click a title before you publish it: its length, its balance of everyday, emotional and power words, numbers and its form. The editor shows the same score under every post's title.",
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="headline" />
			</p>
			<ErrorLine text={ error } />
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Title', 'zinn-seo' ) }
				value={ title }
				onChange={ setTitle }
			/>
			<div className="zseo-x1c-row">
				<Button
					variant="primary"
					isBusy={ 'score' === busy }
					disabled={ !! busy || '' === title.trim() }
					onClick={ analyse }
				>
					{ __( 'Score this title', 'zinn-seo' ) }
				</Button>
				{ ai.ready && (
					<Button
						variant="secondary"
						isBusy={ 'ai' === busy }
						disabled={ !! busy || '' === title.trim() }
						onClick={ rewrite }
					>
						{ __( 'Suggest better titles', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			{ result && <HeadlineResult result={ result } /> }
			{ cost && <p className="zseo-muted">{ cost }</p> }
			{ rewrites.length > 0 && (
				<ul className="zseo-x1c-ideas">
					{ rewrites.map( ( r ) => (
						<li key={ r.title }>
							<span className="zseo-x1c-score__num is-small">
								{ r.score }
							</span>{ ' ' }
							{ r.title }{ ' ' }
							<Button
								variant="link"
								onClick={ () => {
									setTitle( r.title );
									setResult( null );
								} }
							>
								{ __( 'Use', 'zinn-seo' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
