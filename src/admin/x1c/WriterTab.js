/**
 * The AI writer (zs-700): post ideas for this site, and a draft or a full article from a brief.
 * Nothing is published: a reviewed draft becomes a DRAFT post when the person presses the button.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { useRoute } from '../p4/shared';
import { ErrorLine, costLine, runTask } from './shared';
import ModelPicker from './ModelPicker';

/**
 * Lengths and voices a draft may take.
 *
 * @return {{lengths: Array, voices: Array}} Choices.
 */
export function writerChoices() {
	return {
		lengths: [ 300, 600, 1000, 1500, 2000 ].map( ( n ) => ( {
			value: String( n ),
			label: sprintf(
				/* translators: %d: a number of words. */
				__( 'About %d words', 'zinn-seo' ),
				n
			),
		} ) ),
		voices: [
			{ value: 'friendly', label: __( 'Friendly', 'zinn-seo' ) },
			{ value: 'professional', label: __( 'Professional', 'zinn-seo' ) },
			{ value: 'expert', label: __( 'Expert', 'zinn-seo' ) },
			{ value: 'casual', label: __( 'Casual', 'zinn-seo' ) },
			{ value: 'persuasive', label: __( 'Persuasive', 'zinn-seo' ) },
			{ value: 'neutral', label: __( 'Neutral', 'zinn-seo' ) },
		],
	};
}

/**
 * The tab.
 *
 * @param {Object}   props
 * @param {Object}   props.data   Boot data.
 * @param {Function} props.onPlan Add ideas to the planner (Pro), or null.
 * @return {Element} Tab.
 */
export default function WriterTab( { data, onPlan } ) {
	const lists = useRoute(
		data.modules && data.modules.keywords
			? '/keyword-lists?limit=200'
			: '/writer/site'
	);
	const [ topic, setTopic ] = useState( '' );
	const [ kwlist, setKwlist ] = useState( '0' );
	const [ ideas, setIdeas ] = useState( [] );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ cost, setCost ] = useState( '' );
	const [ brief, setBrief ] = useState( {
		topic: '',
		phrase: '',
		notes: '',
		words: '1000',
		voice: 'friendly',
		full: true,
	} );
	const [ draft, setDraft ] = useState( null );
	const [ made, setMade ] = useState( null );
	const { lengths, voices } = writerChoices();
	const listRows =
		lists.data && Array.isArray( lists.data.items ) ? lists.data.items : [];
	const ai = data.aiState || {};

	const askIdeas = () => {
		setBusy( 'ideas' );
		setError( '' );
		runTask( 'ideas', {
			topic,
			kwlist: parseInt( kwlist, 10 ) || 0,
			count: 10,
		} )
			.then( ( { fields, run } ) => {
				setIdeas( fields.ideas || [] );
				setCost( costLine( run ) );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const askDraft = () => {
		setBusy( 'draft' );
		setError( '' );
		setMade( null );
		runTask( 'draft', {
			...brief,
			words: parseInt( brief.words, 10 ),
			kwlist: parseInt( kwlist, 10 ) || 0,
		} )
			.then( ( { fields, run } ) => {
				setDraft( fields );
				setCost( costLine( run ) );
			} )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const create = () => {
		setBusy( 'create' );
		setError( '' );
		seoFetch( '/writer/drafts', {
			method: 'POST',
			data: {
				draft,
				options: {
					phrase: brief.phrase,
					kwlist: parseInt( kwlist, 10 ) || 0,
				},
			},
		} )
			.then( setMade )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const pickIdea = ( idea ) =>
		setBrief( {
			...brief,
			topic: idea.title,
			phrase: idea.phrase,
			notes: idea.angle,
		} );

	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Get post ideas that fit your site and the searches you want to win, then turn one into a starter draft or a full article. Every draft is saved as a draft for you to edit — nothing is ever published by itself.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="writer" />
			</p>
			{ ! ai.ready && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Set up AI first: add your own AI key, or use Zinn® credits, under AI.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<ModelPicker feature="writer" />
			<ErrorLine text={ error } />
			{ cost && <p className="zseo-muted">{ cost }</p> }
			<section className="zseo-x1c-box" aria-labelledby="zseo-x1c-ideas">
				<h3 id="zseo-x1c-ideas">{ __( 'Post ideas', 'zinn-seo' ) }</h3>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Around a topic (optional)', 'zinn-seo' ) }
					value={ topic }
					onChange={ setTopic }
				/>
				{ listRows.length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __(
							'Use the phrases of a keyword list',
							'zinn-seo'
						) }
						value={ kwlist }
						options={ [
							{ value: '0', label: __( 'None', 'zinn-seo' ) },
							...listRows.map( ( l ) => ( {
								value: String( l.id ),
								label: l.label,
							} ) ),
						] }
						onChange={ setKwlist }
					/>
				) }
				<Button
					variant="secondary"
					isBusy={ 'ideas' === busy }
					disabled={ !! busy }
					onClick={ askIdeas }
				>
					{ __( 'Suggest ideas', 'zinn-seo' ) }
				</Button>
				{ ideas.length > 0 && (
					<ul className="zseo-x1c-ideas">
						{ ideas.map( ( idea ) => (
							<li key={ idea.title }>
								<strong>{ idea.title }</strong>
								<span className="zseo-muted">
									{ ' ' }
									{ idea.angle }{ ' ' }
									{ sprintf(
										/* translators: %s: a search phrase. */
										__( '(targets "%s")', 'zinn-seo' ),
										idea.phrase
									) }
								</span>
								<span className="zseo-x1c-actions">
									<Button
										variant="link"
										onClick={ () => pickIdea( idea ) }
									>
										{ __( 'Write this', 'zinn-seo' ) }
									</Button>
									{ onPlan && (
										<Button
											variant="link"
											onClick={ () => onPlan( [ idea ] ) }
										>
											{ __(
												'Add to the plan',
												'zinn-seo'
											) }
										</Button>
									) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</section>
			<section className="zseo-x1c-box" aria-labelledby="zseo-x1c-brief">
				<h3 id="zseo-x1c-brief">
					{ __( 'Draft from a brief', 'zinn-seo' ) }
				</h3>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'What the post is about', 'zinn-seo' ) }
					value={ brief.topic }
					onChange={ ( v ) => setBrief( { ...brief, topic: v } ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'The search it should rank for', 'zinn-seo' ) }
					value={ brief.phrase }
					onChange={ ( v ) => setBrief( { ...brief, phrase: v } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __(
						'Notes for the writer (optional)',
						'zinn-seo'
					) }
					help={ __(
						'What to cover, what to leave out, facts to include.',
						'zinn-seo'
					) }
					value={ brief.notes }
					onChange={ ( v ) => setBrief( { ...brief, notes: v } ) }
				/>
				<div className="zseo-x1c-row">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Length', 'zinn-seo' ) }
						value={ brief.words }
						options={ lengths }
						onChange={ ( v ) => setBrief( { ...brief, words: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Voice', 'zinn-seo' ) }
						value={ brief.voice }
						options={ voices }
						onChange={ ( v ) => setBrief( { ...brief, voice: v } ) }
					/>
				</div>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Write the full article (off: a starter draft with every section begun)',
						'zinn-seo'
					) }
					checked={ brief.full }
					onChange={ ( v ) => setBrief( { ...brief, full: v } ) }
				/>
				<Button
					variant="primary"
					isBusy={ 'draft' === busy }
					disabled={ !! busy || '' === brief.topic.trim() }
					onClick={ askDraft }
				>
					{ __( 'Write the draft', 'zinn-seo' ) }
				</Button>
			</section>
			{ draft && (
				<section
					className="zseo-x1c-box zseo-x1c-draft"
					aria-labelledby="zseo-x1c-preview"
				>
					<h3 id="zseo-x1c-preview">
						{ __( 'The draft', 'zinn-seo' ) }
					</h3>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'zinn-seo' ) }
						value={ draft.title || '' }
						onChange={ ( v ) => setDraft( { ...draft, title: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'SEO title', 'zinn-seo' ) }
						value={ draft.seo_title || '' }
						onChange={ ( v ) =>
							setDraft( { ...draft, seo_title: v } )
						}
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Meta description', 'zinn-seo' ) }
						value={ draft.seo_desc || '' }
						onChange={ ( v ) =>
							setDraft( { ...draft, seo_desc: v } )
						}
					/>
					<div
						className="zseo-x1c-text"
						tabIndex={ 0 }
						role="region"
						aria-label={ __( "The draft's text", 'zinn-seo' ) }
					>
						{ ( draft.intro || [] ).map( ( p ) => (
							<p key={ p }>{ p }</p>
						) ) }
						{ ( draft.sections || [] ).map( ( s ) => (
							<div key={ s.heading }>
								<h4>{ s.heading }</h4>
								{ ( s.paragraphs || [] ).map( ( p ) => (
									<p key={ p }>{ p }</p>
								) ) }
							</div>
						) ) }
					</div>
					{ made ? (
						<Notice status="success" isDismissible={ false }>
							{ __( 'Saved as a draft.', 'zinn-seo' ) }{ ' ' }
							<a href={ made.edit }>
								{ __( 'Open it in the editor', 'zinn-seo' ) }
							</a>
						</Notice>
					) : (
						<Button
							variant="primary"
							isBusy={ 'create' === busy }
							disabled={ !! busy }
							onClick={ create }
						>
							{ __( 'Save as a draft post', 'zinn-seo' ) }
						</Button>
					) }
				</section>
			) }
		</div>
	);
}
