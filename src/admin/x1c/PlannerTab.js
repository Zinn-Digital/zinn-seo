/**
 * The content planner (zs-700, Pro): ideas with a date and the search each targets, and drafts
 * written for the ones chosen, in the background.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { ErrorLine, MoreButton, usePaged } from './shared';
import ModelPicker from './ModelPicker';

/**
 * A plan row's status in words.
 *
 * @param {string} status idea|queued|drafted|failed.
 * @return {string} Words.
 */
export function planStatus( status ) {
	return (
		{
			idea: __( 'Idea', 'zinn-seo' ),
			queued: __( 'Being written', 'zinn-seo' ),
			drafted: __( 'Draft ready', 'zinn-seo' ),
			failed: __( 'Not written', 'zinn-seo' ),
		}[ status ] || status
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function PlannerTab( { data } ) {
	const on = data.modules && data.modules[ 'writer-pro' ];
	const plan = usePaged( on ? '/writer/plan' : '/settings' );
	const [ chosen, setChosen ] = useState( [] );
	const [ title, setTitle ] = useState( '' );
	const [ phrase, setPhrase ] = useState( '' );
	const [ due, setDue ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	if ( ! on ) {
		return (
			<ProTeaser
				title={ __( 'Content planner', 'zinn-seo' ) }
				points={ [
					__(
						'Keep post ideas in a plan with a date and the search each one targets.',
						'zinn-seo'
					),
					__(
						'Have drafts written for as many as you choose, in the background.',
						'zinn-seo'
					),
					__(
						'Every draft waits for you to review it — nothing is published by itself.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	const add = () =>
		seoFetch( '/writer/plan', {
			method: 'POST',
			data: { ideas: [ { title, phrase, due } ] },
		} )
			.then( () => {
				setTitle( '' );
				setPhrase( '' );
				setDue( '' );
				plan.reload();
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	const draft = () =>
		seoFetch( '/writer/plan/draft', {
			method: 'POST',
			data: { ids: chosen },
		} )
			.then( ( r ) => {
				setNote(
					sprintf(
						/* translators: %d: a number of drafts. */
						_n(
							'%d draft is being written. It appears in your posts as a draft when ready.',
							'%d drafts are being written. They appear in your posts as drafts when ready.',
							r.queued,
							'zinn-seo'
						),
						r.queued
					)
				);
				setChosen( [] );
				plan.reload();
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	const remove = ( id ) =>
		seoFetch( `/writer/plan/${ id }`, { method: 'DELETE' } )
			.then( plan.reload )
			.catch( ( e ) => setError( errorText( e ) ) );
	const toggle = ( id, on2 ) =>
		setChosen(
			on2 ? [ ...chosen, id ] : chosen.filter( ( c ) => c !== id )
		);
	const job = plan.extra.job || {};
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Plan what to write and when. Choose ideas and have their drafts written in the background — each one waits as a draft for you to edit and publish.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="planner" />
			</p>
			<ModelPicker feature="writer" />
			<ErrorLine text={ error } />
			{ note && (
				<Notice status="success" onRemove={ () => setNote( '' ) }>
					{ note }
				</Notice>
			) }
			{ job.status && 'pending' === job.status && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Drafts are being written now.', 'zinn-seo' ) }
				</Notice>
			) }
			{ job.status && 'paused' === job.status && (
				<Notice status="warning" isDismissible={ false }>
					{ job.error || __( 'Writing is paused.', 'zinn-seo' ) }
				</Notice>
			) }
			<section
				className="zseo-x1c-box"
				aria-labelledby="zseo-x1c-addidea"
			>
				<h3 id="zseo-x1c-addidea">
					{ __( 'Add an idea', 'zinn-seo' ) }
				</h3>
				<div className="zseo-x1c-row">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Working title', 'zinn-seo' ) }
						value={ title }
						onChange={ setTitle }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Search it targets', 'zinn-seo' ) }
						value={ phrase }
						onChange={ setPhrase }
					/>
					<TextControl
						__nextHasNoMarginBottom
						type="date"
						label={ __( 'Planned for', 'zinn-seo' ) }
						value={ due }
						onChange={ setDue }
					/>
				</div>
				<Button
					variant="secondary"
					disabled={ ! title.trim() }
					onClick={ add }
				>
					{ __( 'Add to the plan', 'zinn-seo' ) }
				</Button>
			</section>
			{ plan.loading && <Spinner /> }
			{ ! plan.loading && ! plan.items.length && (
				<p className="zseo-muted">
					{ __(
						'The plan is empty. Add ideas here, or from the Writer tab.',
						'zinn-seo'
					) }
				</p>
			) }
			<ul className="zseo-x1c-plan">
				{ plan.items.map( ( row ) => (
					<li key={ row.id } className={ `is-${ row.status }` }>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ row.title }
							disabled={
								'drafted' === row.status ||
								'queued' === row.status
							}
							checked={ chosen.includes( row.id ) }
							onChange={ ( v ) => toggle( row.id, v ) }
						/>
						<span className="zseo-muted">
							{ planStatus( row.status ) }
							{ row.due ? ` · ${ row.due }` : '' }
							{ row.phrase ? ` · ${ row.phrase }` : '' }
						</span>
						{ row.error && (
							<span className="zseo-x1c-err">{ row.error }</span>
						) }
						<span className="zseo-x1c-actions">
							{ row.edit && (
								<a href={ row.edit }>
									{ __( 'Edit the draft', 'zinn-seo' ) }
								</a>
							) }
							<Button
								variant="link"
								isDestructive
								onClick={ () => remove( row.id ) }
							>
								{ __( 'Remove', 'zinn-seo' ) }
							</Button>
						</span>
					</li>
				) ) }
			</ul>
			<MoreButton list={ plan } />
			<Button
				variant="primary"
				disabled={ ! chosen.length }
				onClick={ draft }
			>
				{ sprintf(
					/* translators: %d: a number of ideas. */
					_n(
						'Write %d draft',
						'Write %d drafts',
						chosen.length,
						'zinn-seo'
					),
					chosen.length
				) }
			</Button>
		</div>
	);
}
