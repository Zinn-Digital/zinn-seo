/**
 * The block editor's "AI writing help" panel: every AI feature for the page being written, on the
 * site owner's own key or on Zinn® credits. Nothing changes until the person uses a suggestion,
 * and what changes the page is an edit in the editor that they save like any other (zs-200).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	Button,
	CheckboxControl,
	Notice,
	TextControl,
} from '@wordpress/components';

import { seoFetch } from '../admin/api';
import { AiFailure, PaidBy, firstFields, useRun } from '../ai/parts';
import { linkFirst, plainText } from './analysis/pure';

const boot = window.zinnSeoEditor || {};

/**
 * Every block, inner blocks included.
 *
 * @param {Array} blocks Blocks.
 * @return {Array} Flat list.
 */
function flatten( blocks ) {
	const out = [];
	( blocks || [] ).forEach( ( b ) => {
		out.push( b );
		out.push( ...flatten( b.innerBlocks ) );
	} );
	return out;
}

/**
 * Rich text as HTML (a string before WordPress 6.5, a RichTextData object after).
 *
 * @param {*} value Value.
 * @return {string} HTML.
 */
function html( value ) {
	if ( typeof value === 'string' ) {
		return value;
	}
	return value && typeof value.toHTMLString === 'function'
		? value.toHTMLString()
		: String( value || '' );
}

/**
 * One feature: its button, its failure, and what it found.
 *
 * @param {Object}   props
 * @param {string}   props.title    Name.
 * @param {string}   props.help     What it does.
 * @param {Object}   props.run      `useRun()`.
 * @param {Function} props.onAsk    Ask.
 * @param {string}   props.button   Button words.
 * @param {*}        props.children The answer.
 * @return {Element} Section.
 */
function Feature( { title, help, run, onAsk, button, children } ) {
	return (
		<section className="zseo-ai-ed__feature">
			<h3>{ title }</h3>
			<p className="zseo-ai-ed__help">{ help }</p>
			<Button
				variant="secondary"
				size="compact"
				isBusy={ !! run.busy }
				disabled={ !! run.busy }
				onClick={ onAsk }
			>
				{ button || __( 'Suggest', 'zinn-seo' ) }
			</Button>
			<AiFailure
				failure={ run.failure }
				buyUrl={ ( boot.aiState.credits || {} ).buy_url }
				onRetry={ onAsk }
				onRemove={ run.clear }
			/>
			{ children }
		</section>
	);
}

/**
 * The panel body.
 *
 * @return {Element} Panel.
 */
export function AiBody() {
	const ai = boot.aiState;
	const { postId, meta, blocks, selected, dirty } = useSelect( ( select ) => {
		const e = select( 'core/editor' );
		const be = select( 'core/block-editor' );
		return {
			postId: e.getCurrentPostId(),
			meta: e.getEditedPostAttribute( 'meta' ),
			blocks: be.getBlocks(),
			selected: be.getSelectedBlock(),
			dirty: e.isEditedPostDirty(),
		};
	}, [] );
	const { editPost } = useDispatch( 'core/editor' );
	const { insertBlocks, updateBlockAttributes } =
		useDispatch( 'core/block-editor' );
	const createBlock =
		window.wp && window.wp.blocks ? window.wp.blocks.createBlock : null;
	const metaMode =
		!! meta && Object.prototype.hasOwnProperty.call( meta, '_zseo_title' );
	const [ note, setNote ] = useState( '' );

	const meta1 = useRun();
	const quick = useRun();
	const faq = useRun();
	const howto = useRun();
	const schema = useRun();
	const anchors = useRun();
	const rewrite = useRun();
	const alt = useRun();
	const llms = useRun();
	const entities = useRun();
	const preview = useRun();
	const social = useRun();
	const [ picked, setPicked ] = useState( {} );
	const [ check, setCheck ] = useState( '' );
	const [ drawn, setDrawn ] = useState( null );

	const ask = ( run, task, options = {} ) =>
		run.run( task, '/ai/run', { task, ids: [ postId ], options } );
	const accept = ( task, fields, done, id = postId ) =>
		seoFetch( `/ai/suggestions/${ id }`, {
			method: 'POST',
			data: { task, action: 'accept', fields },
		} )
			.then( () => setNote( done ) )
			.catch( ( e ) => setNote( ( e && e.message ) || '' ) );
	// A page's own SEO field: in the editor's own state when the post type carries it (saved with
	// the post), else stored now.
	const setFields = ( task, fields, done, server = fields ) => {
		const keys = Object.keys( fields );
		if (
			metaMode &&
			keys.every( ( k ) =>
				Object.prototype.hasOwnProperty.call( meta, `_zseo_${ k }` )
			)
		) {
			const next = {};
			keys.forEach( ( k ) => ( next[ `_zseo_${ k }` ] = fields[ k ] ) );
			editPost( { meta: next } );
			setNote( __( 'Added. Save the page to keep it.', 'zinn-seo' ) );
			return;
		}
		accept( task, server, done );
	};
	const insert = ( name, attributes, atStart ) => {
		if ( ! createBlock ) {
			return;
		}
		insertBlocks(
			createBlock( `zinn-seo/${ name }`, attributes ),
			atStart ? 0 : undefined
		);
		setNote(
			__( 'Added to the page. Save the page to keep it.', 'zinn-seo' )
		);
	};
	const images = flatten( blocks ).filter(
		( b ) =>
			'core/image' === b.name && b.attributes.id && ! b.attributes.alt
	);
	const textBlock =
		selected &&
		[
			'core/paragraph',
			'core/heading',
			'core/list-item',
			'core/quote',
		].includes( selected.name )
			? selected
			: null;
	const pro = !! ai.pro;

	const q = firstFields( quick.result );
	const m = firstFields( meta1.result );
	const f = firstFields( faq.result );
	const h = firstFields( howto.result );
	const s = firstFields( schema.result );
	const a = firstFields( anchors.result );
	const r = firstFields( rewrite.result );
	const l = firstFields( llms.result );
	const ent = firstFields( entities.result );
	const pv = firstFields( preview.result );
	const so = firstFields( social.result );

	if ( ! ai.ready ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Add your own AI key, or connect to Zinn® to pay as you go with credits.',
					'zinn-seo'
				) }{ ' ' }
				<a href={ ai.settings_url }>
					{ __( 'Set up AI', 'zinn-seo' ) }
				</a>
			</Notice>
		);
	}
	return (
		<div className="zseo-ai-ed">
			<p className="zseo-ai-ed__help">
				<PaidBy ai={ ai } />
				{ dirty && (
					<>
						{ ' · ' }
						{ __(
							'AI reads the last saved version of the page.',
							'zinn-seo'
						) }
					</>
				) }
			</p>
			{ note && (
				<Notice status="success" onRemove={ () => setNote( '' ) }>
					{ note }
				</Notice>
			) }
			<Feature
				title={ __( 'Title and description', 'zinn-seo' ) }
				help={ __(
					'A title and description written to earn the click.',
					'zinn-seo'
				) }
				run={ meta1 }
				onAsk={ () => ask( meta1, 'meta' ) }
			>
				{ m && (
					<div className="zseo-ai-ed__answer">
						<p>
							<strong>{ m.title }</strong>
						</p>
						<p>{ m.desc }</p>
						<Button
							variant="primary"
							size="compact"
							onClick={ () =>
								setFields(
									'meta',
									{ title: m.title, desc: m.desc },
									__(
										'The title and description are saved.',
										'zinn-seo'
									)
								)
							}
						>
							{ __( 'Use these', 'zinn-seo' ) }
						</Button>
					</div>
				) }
			</Feature>
			<Feature
				title={ __( 'Image alt text', 'zinn-seo' ) }
				help={
					images.length > 0
						? sprintf(
								/* translators: %d: how many images have no alt text. */
								__(
									'%d images on this page have no alt text.',
									'zinn-seo'
								),
								images.length
							)
						: __(
								'Every image on this page has alt text.',
								'zinn-seo'
							)
				}
				run={ alt }
				onAsk={ () =>
					alt.run( 'alt', '/ai/run', {
						task: 'alt',
						ids: images
							.slice( 0, 50 )
							.map( ( b ) => b.attributes.id ),
					} )
				}
				button={ __( 'Write alt text', 'zinn-seo' ) }
			>
				{ alt.result && (
					<ul className="zseo-ai-ed__list">
						{ alt.result.items
							.filter( ( i ) => i.ok )
							.map( ( i ) => (
								<li key={ i.id }>
									{ i.fields.alt }{ ' ' }
									<Button
										variant="link"
										onClick={ () => {
											flatten( blocks )
												.filter(
													( b ) =>
														'core/image' ===
															b.name &&
														b.attributes.id === i.id
												)
												.forEach( ( b ) =>
													updateBlockAttributes(
														b.clientId,
														{ alt: i.fields.alt }
													)
												);
											accept(
												'alt',
												{ alt: i.fields.alt },
												__(
													'Alt text added.',
													'zinn-seo'
												),
												i.id
											);
										} }
									>
										{ __( 'Use', 'zinn-seo' ) }
									</Button>
								</li>
							) ) }
					</ul>
				) }
			</Feature>
			<Feature
				title={ __( 'Structured data', 'zinn-seo' ) }
				help={ __(
					"The rich-result types this page qualifies for, checked against Google's rules.",
					'zinn-seo'
				) }
				run={ schema }
				onAsk={ () => ask( schema, 'schema' ) }
			>
				{ s && (
					<div className="zseo-ai-ed__answer">
						<p>{ s.page }</p>
						<ul className="zseo-ai-ed__list">
							{ s.nodes.map( ( n ) => (
								<li key={ n.type }>
									<strong>{ n.type }</strong> — { n.why }
								</li>
							) ) }
						</ul>
						{ s.checks.errors > 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %d: number of problems. */
									__(
										'%d problems to fix before Google shows it.',
										'zinn-seo'
									),
									s.checks.errors
								) }
							</Notice>
						) }
						<Button
							variant="primary"
							size="compact"
							onClick={ () =>
								accept(
									'schema',
									s,
									__(
										'Structured data added. It shows in the Structured data panel.',
										'zinn-seo'
									)
								)
							}
						>
							{ __( 'Use this', 'zinn-seo' ) }
						</Button>
					</div>
				) }
			</Feature>
			{ ! pro && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Zinn® SEO Pro adds: quick answer and key takeaways, FAQ and how-to steps from the page, internal links with natural wording, rewrites that pass a failing check, entity links, AI-answer preview, llms.txt summaries in every language and social images.',
						'zinn-seo'
					) }{ ' ' }
					<a href={ boot.plansUrl }>
						{ __( 'See Pro', 'zinn-seo' ) }
					</a>
				</Notice>
			) }
			{ pro && (
				<>
					<Feature
						title={ __(
							'Quick answer and key takeaways',
							'zinn-seo'
						) }
						help={ __(
							'The direct answer AI and featured snippets quote, and the points a skimmer needs.',
							'zinn-seo'
						) }
						run={ quick }
						onAsk={ () => ask( quick, 'quick' ) }
					>
						{ q && (
							<div className="zseo-ai-ed__answer">
								<p>
									<strong>{ q.question }</strong>
								</p>
								<p>{ q.answer }</p>
								<ul className="zseo-ai-ed__list">
									{ q.takeaways.map( ( t ) => (
										<li key={ t }>{ t }</li>
									) ) }
								</ul>
								<Button
									variant="primary"
									size="compact"
									onClick={ () =>
										insert(
											'quick-answer',
											{
												question: q.question,
												answer: q.answer,
											},
											true
										)
									}
								>
									{ __(
										'Add the answer box at the top',
										'zinn-seo'
									) }
								</Button>{ ' ' }
								<Button
									variant="secondary"
									size="compact"
									onClick={ () =>
										setFields(
											'quick',
											{
												takeaways:
													q.takeaways.join( '\n' ),
											},
											__(
												'Takeaways saved.',
												'zinn-seo'
											),
											{ takeaways: q.takeaways }
										)
									}
								>
									{ __( 'Use the takeaways', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Feature>
					<Feature
						title={ __( 'FAQ from the page', 'zinn-seo' ) }
						help={ __(
							'Questions visitors ask, answered from your page, with FAQ structured data.',
							'zinn-seo'
						) }
						run={ faq }
						onAsk={ () => ask( faq, 'faq' ) }
					>
						{ f && (
							<div className="zseo-ai-ed__answer">
								<ul className="zseo-ai-ed__list">
									{ f.items.map( ( i ) => (
										<li key={ i.question }>
											<strong>{ i.question }</strong>{ ' ' }
											{ i.answer }
										</li>
									) ) }
								</ul>
								<Button
									variant="primary"
									size="compact"
									onClick={ () =>
										insert( 'faq', { items: f.items } )
									}
								>
									{ __( 'Add as an FAQ block', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Feature>
					<Feature
						title={ __( 'How-to steps', 'zinn-seo' ) }
						help={ __(
							"The page's instructions as steps with how-to structured data.",
							'zinn-seo'
						) }
						run={ howto }
						onAsk={ () => ask( howto, 'howto' ) }
					>
						{ h && h.steps.length > 0 && (
							<div className="zseo-ai-ed__answer">
								<ol className="zseo-ai-ed__list">
									{ h.steps.map( ( st ) => (
										<li key={ st.text }>
											{ st.name || st.text }
										</li>
									) ) }
								</ol>
								<Button
									variant="primary"
									size="compact"
									onClick={ () =>
										insert( 'howto', {
											name: h.name,
											description: h.description,
											minutes: h.minutes,
											supplies: h.supplies.join( '\n' ),
											tools: h.tools.join( '\n' ),
											steps: h.steps.map( ( st ) => ( {
												name: st.name,
												text: st.text,
											} ) ),
										} )
									}
								>
									{ __(
										'Add as a how-to block',
										'zinn-seo'
									) }
								</Button>
							</div>
						) }
						{ h && h.steps.length === 0 && (
							<p>
								{ __(
									'This page has no steps to turn into a how-to.',
									'zinn-seo'
								) }
							</p>
						) }
					</Feature>
					<Feature
						title={ __( 'Internal links', 'zinn-seo' ) }
						help={ __(
							'Links to your other pages, on words already in your text.',
							'zinn-seo'
						) }
						run={ anchors }
						onAsk={ () => ask( anchors, 'anchors' ) }
					>
						{ a && (
							<div className="zseo-ai-ed__answer">
								{ a.links.length === 0 && (
									<p>
										{ __(
											'No good place for a link was found.',
											'zinn-seo'
										) }
									</p>
								) }
								{ a.links.map( ( lk ) => (
									<CheckboxControl
										__nextHasNoMarginBottom
										key={ lk.target }
										checked={
											picked[ lk.target ] !== false
										}
										onChange={ ( on ) =>
											setPicked( {
												...picked,
												[ lk.target ]: on,
											} )
										}
										label={ sprintf(
											/* translators: 1: words to link, 2: page title. */
											__( '"%1$s" → %2$s', 'zinn-seo' ),
											lk.anchor,
											lk.title
										) }
									/>
								) ) }
								{ a.links.length > 0 && (
									<Button
										variant="primary"
										size="compact"
										onClick={ () => {
											let added = 0;
											a.links
												.filter(
													( lk ) =>
														picked[ lk.target ] !==
														false
												)
												.forEach( ( lk ) => {
													for ( const b of flatten(
														blocks
													) ) {
														if (
															[
																'core/paragraph',
																'core/list-item',
																'core/quote',
															].includes( b.name )
														) {
															const after =
																linkFirst(
																	html(
																		b
																			.attributes
																			.content
																	),
																	lk.anchor,
																	lk.url
																);
															if ( after ) {
																updateBlockAttributes(
																	b.clientId,
																	{
																		content:
																			after,
																	}
																);
																added++;
																break;
															}
														}
													}
												} );
											setNote(
												sprintf(
													/* translators: %d: number of links. */
													__(
														'%d links added. Save the page to keep them.',
														'zinn-seo'
													),
													added
												)
											);
										} }
									>
										{ __(
											'Add the ticked links',
											'zinn-seo'
										) }
									</Button>
								) }
							</div>
						) }
					</Feature>
					<Feature
						title={ __( 'Rewrite to pass a check', 'zinn-seo' ) }
						help={
							textBlock
								? __(
										'Rewrites the selected block so it passes the check you name, keeping its meaning.',
										'zinn-seo'
									)
								: __(
										'Select a paragraph or heading first.',
										'zinn-seo'
									)
						}
						run={ rewrite }
						onAsk={ () =>
							textBlock &&
							ask( rewrite, 'rewrite', {
								text: plainText(
									html( textBlock.attributes.content )
								),
								check:
									check ||
									__(
										'Make it clearer and easier to read',
										'zinn-seo'
									),
								phrase:
									meta && meta._zseo_focus
										? String( meta._zseo_focus ).split(
												/[\n|]/
											)[ 0 ]
										: '',
							} )
						}
						button={ __( 'Rewrite', 'zinn-seo' ) }
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'What should it fix?', 'zinn-seo' ) }
							placeholder={ __(
								'e.g. Mention the focus phrase in the intro',
								'zinn-seo'
							) }
							value={ check }
							onChange={ setCheck }
						/>
						{ r && textBlock && (
							<div className="zseo-ai-ed__answer">
								<p>
									<del>
										{ plainText(
											html( textBlock.attributes.content )
										) }
									</del>
								</p>
								<p>
									<ins>{ r.after }</ins>
								</p>
								<p className="zseo-ai-ed__help">{ r.why }</p>
								<Button
									variant="primary"
									size="compact"
									onClick={ () =>
										updateBlockAttributes(
											textBlock.clientId,
											{ content: r.after }
										)
									}
								>
									{ __( 'Replace the text', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Feature>
					<Feature
						title={ __(
							'What it is about (Wikidata)',
							'zinn-seo'
						) }
						help={ __(
							'Tells search engines and AI exactly which people, places and things the page means.',
							'zinn-seo'
						) }
						run={ entities }
						onAsk={ () => ask( entities, 'entities' ) }
					>
						{ ent && (
							<div className="zseo-ai-ed__answer">
								<ul className="zseo-ai-ed__list">
									{ ent.things.map( ( t ) => (
										<li key={ t.url }>
											<strong>{ t.name }</strong> (
											{ 'about' === t.role
												? __( 'about', 'zinn-seo' )
												: __(
														'mentioned',
														'zinn-seo'
													) }
											) — { t.description }
										</li>
									) ) }
								</ul>
								{ ent.things.length > 0 && (
									<Button
										variant="primary"
										size="compact"
										onClick={ () =>
											accept(
												'entities',
												ent,
												__(
													"Saved in the page's structured data.",
													'zinn-seo'
												)
											)
										}
									>
										{ __( 'Use these', 'zinn-seo' ) }
									</Button>
								) }
							</div>
						) }
					</Feature>
					<Feature
						title={ __( 'AI answer preview', 'zinn-seo' ) }
						help={ __(
							'How an AI answer would use this page — and what it needs to quote you first.',
							'zinn-seo'
						) }
						run={ preview }
						onAsk={ () => ask( preview, 'preview' ) }
						button={ __( 'Preview', 'zinn-seo' ) }
					>
						{ pv && (
							<div className="zseo-ai-ed__answer">
								<p>
									<strong>{ pv.question }</strong>
								</p>
								<p>{ pv.answer }</p>
								<p>
									{ pv.cited
										? __(
												'Your page would be cited.',
												'zinn-seo'
											)
										: __(
												'Your page would probably not be cited.',
												'zinn-seo'
											) }
								</p>
								{ pv.quoted && (
									<blockquote>{ pv.quoted }</blockquote>
								) }
								{ pv.missing.length > 0 && (
									<ul className="zseo-ai-ed__list">
										{ pv.missing.map( ( x ) => (
											<li key={ x }>{ x }</li>
										) ) }
									</ul>
								) }
							</div>
						) }
					</Feature>
					<Feature
						title={ __(
							'llms.txt summary in every language',
							'zinn-seo'
						) }
						help={ __(
							'The one line AI assistants read about this page, in each language of the site.',
							'zinn-seo'
						) }
						run={ llms }
						onAsk={ () => ask( llms, 'llms' ) }
					>
						{ l && (
							<div className="zseo-ai-ed__answer">
								<ul className="zseo-ai-ed__list">
									{ Object.entries( l.summaries ).map(
										( [ lang, t ] ) => (
											<li key={ lang }>
												<strong>{ lang }</strong> { t }
											</li>
										)
									) }
								</ul>
								<Button
									variant="primary"
									size="compact"
									onClick={ () =>
										accept(
											'llms',
											l,
											__(
												'Saved in llms.txt.',
												'zinn-seo'
											)
										)
									}
								>
									{ __( 'Use these', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Feature>
					<Feature
						title={ __( 'Social sharing', 'zinn-seo' ) }
						help={
							ai.draws
								? __(
										'A share headline and a picture drawn for this page.',
										'zinn-seo'
									)
								: __(
										'A share headline for this page. To have the picture drawn too, add an OpenAI or Google Gemini key.',
										'zinn-seo'
									)
						}
						run={ social }
						onAsk={ () => {
							setDrawn( null );
							ask( social, 'social' );
						} }
					>
						{ so && (
							<div className="zseo-ai-ed__answer">
								<p>
									<strong>{ so.headline }</strong>
								</p>
								{ drawn && drawn.fields && drawn.fields.url && (
									<img
										src={ drawn.fields.url }
										alt={ so.headline }
										className="zseo-ai-ed__img"
									/>
								) }
								{ ai.draws && ! drawn && (
									<Button
										variant="secondary"
										size="compact"
										onClick={ () =>
											seoFetch(
												`/ai/social/${ postId }`,
												{ method: 'POST' }
											)
												.then( setDrawn )
												.catch( ( e ) =>
													setNote(
														( e && e.message ) || ''
													)
												)
										}
									>
										{ __( 'Draw the picture', 'zinn-seo' ) }
									</Button>
								) }{ ' ' }
								<Button
									variant="primary"
									size="compact"
									onClick={ () =>
										accept(
											'social',
											drawn && drawn.fields
												? drawn.fields
												: so,
											__(
												"Saved as the page's share headline and image.",
												'zinn-seo'
											)
										)
									}
								>
									{ __( 'Use for sharing', 'zinn-seo' ) }
								</Button>
							</div>
						) }
					</Feature>
				</>
			) }
		</div>
	);
}

/**
 * Register the panel (only for a person who may use AI).
 *
 * @param {Object} Panel The document settings panel component.
 * @return {void}
 */
export default function registerAiPanel( Panel ) {
	const wp = window.wp || {};
	if ( ! boot.aiState || ! Panel || ! wp.plugins ) {
		return;
	}
	wp.plugins.registerPlugin( 'zinn-seo-ai', {
		render: () => (
			<Panel
				name="zinn-seo-ai"
				title={ __( 'AI writing help', 'zinn-seo' ) }
				className="zseo-ed-panel zseo-ed-panel--ai"
			>
				<AiBody />
			</Panel>
		),
		icon: null,
	} );
}
