/**
 * Spelling, grammar and tone (zs-721): who checks (a LanguageTool server or AI), the voice the site
 * wants, and a box to try it.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { Ready, SaveBar, useDraft } from '../x1a/shared';
import ModelPicker from './ModelPicker';
import { ErrorLine } from './shared';

/**
 * The voices a site may want.
 *
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function toneChoices() {
	return [
		{ value: '', label: __( 'No tone check', 'zinn-seo' ) },
		{ value: 'friendly', label: __( 'Friendly', 'zinn-seo' ) },
		{ value: 'formal', label: __( 'Formal', 'zinn-seo' ) },
		{ value: 'confident', label: __( 'Confident', 'zinn-seo' ) },
		{ value: 'casual', label: __( 'Casual', 'zinn-seo' ) },
		{ value: 'professional', label: __( 'Professional', 'zinn-seo' ) },
		{ value: 'enthusiastic', label: __( 'Enthusiastic', 'zinn-seo' ) },
		{ value: 'empathetic', label: __( 'Empathetic', 'zinn-seo' ) },
		{ value: 'neutral', label: __( 'Neutral', 'zinn-seo' ) },
	];
}

/**
 * What a mistake is, in words.
 *
 * @param {string} kind spelling|grammar|punctuation|style.
 * @return {string} Word.
 */
export function kindWord( kind ) {
	return (
		{
			spelling: __( 'Spelling', 'zinn-seo' ),
			grammar: __( 'Grammar', 'zinn-seo' ),
			punctuation: __( 'Punctuation', 'zinn-seo' ),
			style: __( 'Style', 'zinn-seo' ),
		}[ kind ] || kind
	);
}

/**
 * The mistakes and the tone of a check.
 *
 * @param {Object} props
 * @param {Object} props.result `POST /proofread`.
 * @return {Element} List.
 */
export function ProofResult( { result } ) {
	const n = ( result.issues || [] ).length;
	return (
		<div className="zseo-x1c-proof">
			<p>
				<strong>
					{ n
						? sprintf(
								/* translators: %d: a number of mistakes. */
								_n(
									'%d thing to look at',
									'%d things to look at',
									n,
									'zinn-seo'
								),
								n
							)
						: __( 'No mistakes found.', 'zinn-seo' ) }
				</strong>
			</p>
			<ul className="zseo-x1c-checks">
				{ ( result.issues || [] ).map( ( i ) => (
					<li key={ `${ i.offset }-${ i.length }` }>
						<span className="zseo-x1c-tag">
							{ kindWord( i.kind ) }
						</span>{ ' ' }
						<mark>{ i.found }</mark>
						{ i.suggestions && i.suggestions.length > 0 && (
							<>
								{ ' → ' }
								<strong>{ i.suggestions[ 0 ] }</strong>
							</>
						) }
						{ i.message && (
							<span className="zseo-muted"> — { i.message }</span>
						) }
					</li>
				) ) }
			</ul>
			{ result.tone && ! result.tone.error && (
				<div className="zseo-x1c-box">
					<p>
						{ sprintf(
							/* translators: 1: how the text sounds, 2: a score out of 100. */
							__(
								'It sounds %1$s — %2$d out of 100 for the voice you chose.',
								'zinn-seo'
							),
							result.tone.heard,
							result.tone.match
						) }
					</p>
					<p className="zseo-muted">{ result.tone.summary }</p>
					<ul className="zseo-x1c-checks">
						{ ( result.tone.sentences || [] ).map( ( s ) => (
							<li key={ s.found }>
								<mark>{ s.found }</mark> → { s.fix }
							</li>
						) ) }
					</ul>
				</div>
			) }
			{ result.tone && result.tone.error && (
				<Notice status="warning" isDismissible={ false }>
					{ result.tone.error }
				</Notice>
			) }
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
export default function ProofreadTab( { data } ) {
	const form = useDraft();
	const state = data.proofread || {};
	const [ key, setKey ] = useState( '' );
	const [ hasKey, setHasKey ] = useState( !! state.hasKey );
	const [ sample, setSample ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const saveKey = () => {
		setBusy( 'key' );
		seoFetch( '/proofread/key', { method: 'PUT', data: { key } } )
			.then( ( s ) => {
				setHasKey( !! s.hasKey );
				setKey( '' );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const check = () => {
		setBusy( 'check' );
		setError( '' );
		seoFetch( '/proofread', { method: 'POST', data: { text: sample } } )
			.then( setResult )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	return (
		<Ready form={ form }>
			<div className="zseo-stack zseo-x1c">
				<p className="zseo-muted">
					{ __(
						'Check spelling and grammar in any language before you publish, and whether your text sounds the way you want. The editor shows the same check on every post.',
						'zinn-seo'
					) }{ ' ' }
					<GuideLink data={ data } id="proofread" />
				</p>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Who checks', 'zinn-seo' ) }
					value={ form.value.proof_engine || 'languagetool' }
					options={ [
						{
							value: 'languagetool',
							label: __( 'A LanguageTool server', 'zinn-seo' ),
						},
						{
							value: 'ai',
							label: __(
								'AI (your own key, or Zinn® credits with Pro)',
								'zinn-seo'
							),
						},
					] }
					onChange={ ( v ) => form.change( { proof_engine: v } ) }
				/>
				{ 'ai' === form.value.proof_engine && (
					<ModelPicker feature="proofread" />
				) }
				{ 'ai' !== form.value.proof_engine && (
					<>
						<TextControl
							__nextHasNoMarginBottom
							type="url"
							label={ __( 'Server address', 'zinn-seo' ) }
							help={ __(
								'Your own LanguageTool server, a LanguageTool Premium account (https://api.languagetoolplus.com) or the free public server (https://api.languagetool.org, up to 20 checks a minute). Your text is sent only there.',
								'zinn-seo'
							) }
							value={ form.value.proof_lt_url || '' }
							onChange={ ( v ) =>
								form.change( { proof_lt_url: v } )
							}
						/>
						<div className="zseo-x1c-row">
							<TextControl
								__nextHasNoMarginBottom
								label={ __(
									'Account email (Premium only)',
									'zinn-seo'
								) }
								value={ form.value.proof_lt_user || '' }
								onChange={ ( v ) =>
									form.change( { proof_lt_user: v } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								type="password"
								autoComplete="off"
								label={
									hasKey
										? __(
												'API key (saved — type to replace)',
												'zinn-seo'
											)
										: __(
												'API key (Premium only)',
												'zinn-seo'
											)
								}
								value={ key }
								onChange={ setKey }
							/>
						</div>
						<p>
							<Button
								variant="secondary"
								isBusy={ 'key' === busy }
								disabled={ !! busy }
								onClick={ saveKey }
							>
								{ '' === key && hasKey
									? __( 'Forget the key', 'zinn-seo' )
									: __( 'Save the key', 'zinn-seo' ) }
							</Button>
						</p>
					</>
				) }
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'The voice your site wants', 'zinn-seo' ) }
					help={ __( 'The tone check uses AI.', 'zinn-seo' ) }
					value={ form.value.proof_tone || '' }
					options={ toneChoices() }
					onChange={ ( v ) => form.change( { proof_tone: v } ) }
				/>
				<SaveBar form={ form } />
				<section
					className="zseo-x1c-box"
					aria-labelledby="zseo-x1c-try"
				>
					<h3 id="zseo-x1c-try">{ __( 'Try it', 'zinn-seo' ) }</h3>
					<ErrorLine text={ error } />
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Text to check', 'zinn-seo' ) }
						rows={ 5 }
						value={ sample }
						onChange={ setSample }
					/>
					<Button
						variant="primary"
						isBusy={ 'check' === busy }
						disabled={ !! busy || ! sample.trim() }
						onClick={ check }
					>
						{ __( 'Check', 'zinn-seo' ) }
					</Button>
					{ result && <ProofResult result={ result } /> }
				</section>
			</div>
		</Ready>
	);
}
