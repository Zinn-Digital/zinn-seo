/**
 * Keyword lists (zs-731): labelled sets of phrases, the posts each is given to, and the list sent
 * to rank tracking or held against the topical map.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { ErrorLine, MoreButton, usePaged } from './shared';

/**
 * The colours a list may have, by name.
 *
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function listColors() {
	return [
		{ value: 'blue', label: __( 'Blue', 'zinn-seo' ) },
		{ value: 'green', label: __( 'Green', 'zinn-seo' ) },
		{ value: 'amber', label: __( 'Amber', 'zinn-seo' ) },
		{ value: 'red', label: __( 'Red', 'zinn-seo' ) },
		{ value: 'purple', label: __( 'Purple', 'zinn-seo' ) },
		{ value: 'teal', label: __( 'Teal', 'zinn-seo' ) },
		{ value: 'grey', label: __( 'Grey', 'zinn-seo' ) },
	];
}

/**
 * Phrases typed one per line (or separated by commas), tidied as the server keeps them.
 *
 * @param {string} text Text.
 * @return {Array<string>} Phrases.
 */
export function splitPhrases( text ) {
	const seen = new Set();
	return String( text || '' )
		.split( /[\r\n,]+/ )
		.map( ( p ) => p.replace( /\s+/g, ' ' ).trim().toLowerCase() )
		.filter( ( p ) => p && ! seen.has( p ) && seen.add( p ) );
}

/**
 * The form for one list (new or existing).
 *
 * @param {Object}   props
 * @param {Object}   props.list     The list (or {}).
 * @param {Function} props.onDone   Called with the saved list.
 * @param {Function} props.onCancel Cancel.
 * @return {Element} Form.
 */
function ListForm( { list, onDone, onCancel } ) {
	const [ label, setLabel ] = useState( list.label || '' );
	const [ color, setColor ] = useState( list.color || 'blue' );
	const [ text, setText ] = useState( ( list.phrases || [] ).join( '\n' ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const save = () => {
		setBusy( true );
		setError( '' );
		seoFetch( list.id ? `/keyword-lists/${ list.id }` : '/keyword-lists', {
			method: list.id ? 'PUT' : 'POST',
			data: { label, color, phrases: splitPhrases( text ) },
		} )
			.then( onDone )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const count = splitPhrases( text ).length;
	return (
		<div className="zseo-x1c-box">
			<ErrorLine text={ error } />
			<div className="zseo-x1c-row">
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Name', 'zinn-seo' ) }
					value={ label }
					onChange={ setLabel }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Colour', 'zinn-seo' ) }
					value={ color }
					options={ listColors() }
					onChange={ setColor }
				/>
			</div>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Phrases, one per line', 'zinn-seo' ) }
				help={ sprintf(
					/* translators: %d: a number of phrases. */
					_n( '%d phrase', '%d phrases', count, 'zinn-seo' ),
					count
				) }
				rows={ 8 }
				value={ text }
				onChange={ setText }
			/>
			<div className="zseo-x1c-row">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! label.trim() }
					onClick={ save }
				>
					{ __( 'Save the list', 'zinn-seo' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'zinn-seo' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * One list: its phrases, posts, and what can be done with it.
 *
 * @param {Object}   props
 * @param {Object}   props.list   The list.
 * @param {Object}   props.data   Boot data.
 * @param {Function} props.onEdit Edit it.
 * @param {Function} props.onGone It was deleted.
 * @return {Element} Card.
 */
function ListCard( { list, data, onEdit, onGone } ) {
	const [ open, setOpen ] = useState( false );
	const [ posts, setPosts ] = useState( null );
	const [ note, setNote ] = useState( null );
	const [ cover, setCover ] = useState( null );
	const run = ( p, done ) =>
		p
			.then( done )
			.catch( ( e ) =>
				setNote( { status: 'error', text: errorText( e ) } )
			);
	const showPosts = () => {
		setOpen( ! open );
		if ( ! posts ) {
			run( seoFetch( `/keyword-lists/${ list.id }/posts` ), ( r ) =>
				setPosts( r.items )
			);
		}
	};
	const remove = () => {
		if (
			// eslint-disable-next-line no-alert -- a plain confirmation before a deletion.
			window.confirm(
				__(
					'Delete this list? The posts keep their content; only the list goes.',
					'zinn-seo'
				)
			)
		) {
			run(
				seoFetch( `/keyword-lists/${ list.id }`, { method: 'DELETE' } ),
				onGone
			);
		}
	};
	const track = () =>
		run(
			seoFetch( `/keyword-lists/${ list.id }/track`, { method: 'POST' } ),
			( r ) =>
				setNote( {
					status: 'success',
					text: sprintf(
						/* translators: %d: a number of phrases. */
						_n(
							'%d new phrase is now tracked.',
							'%d new phrases are now tracked.',
							r.added,
							'zinn-seo'
						),
						r.added
					),
				} )
		);
	const coverage = () =>
		run( seoFetch( `/keyword-lists/${ list.id }/coverage` ), setCover );
	return (
		<li className={ `zseo-x1c-list is-${ list.color }` }>
			<div className="zseo-x1c-list__head">
				<strong>{ list.label }</strong>
				<span className="zseo-muted">
					{ sprintf(
						/* translators: 1: phrases, 2: posts. */
						__( '%1$d phrases · %2$d posts', 'zinn-seo' ),
						list.phrases.length,
						list.posts
					) }
				</span>
			</div>
			<p className="zseo-x1c-list__phrases">
				{ list.phrases.slice( 0, 12 ).join( ', ' ) }
				{ list.phrases.length > 12 ? '…' : '' }
			</p>
			{ note && (
				<Notice
					status={ note.status }
					onRemove={ () => setNote( null ) }
				>
					{ note.text }
				</Notice>
			) }
			<div className="zseo-x1c-actions">
				<Button variant="link" onClick={ onEdit }>
					{ __( 'Edit', 'zinn-seo' ) }
				</Button>
				<Button
					variant="link"
					onClick={ showPosts }
					aria-expanded={ open }
				>
					{ __( 'Posts', 'zinn-seo' ) }
				</Button>
				{ data.modules && data.modules.tools && (
					<Button variant="link" onClick={ track }>
						{ __( 'Track in rankings', 'zinn-seo' ) }
					</Button>
				) }
				{ data.modules && data.modules[ 'search-insights' ] && (
					<Button variant="link" onClick={ coverage }>
						{ __( 'Compare with the topical map', 'zinn-seo' ) }
					</Button>
				) }
				<Button variant="link" isDestructive onClick={ remove }>
					{ __( 'Delete', 'zinn-seo' ) }
				</Button>
			</div>
			{ open && ! posts && <Spinner /> }
			{ open &&
				posts &&
				( posts.length ? (
					<ul className="zseo-x1c-posts">
						{ posts.map( ( p ) => (
							<li key={ p.id }>
								<a href={ p.edit }>
									{ p.title ||
										__( '(no title)', 'zinn-seo' ) }
								</a>
							</li>
						) ) }
					</ul>
				) : (
					<p className="zseo-muted">
						{ __(
							'No post has this list yet. Give posts their lists in the editor, under Search and social.',
							'zinn-seo'
						) }
					</p>
				) ) }
			{ cover && (
				<ul className="zseo-x1c-posts">
					{ cover.phrases.map( ( c ) => (
						<li key={ c.phrase }>
							{ c.phrase }:{ ' ' }
							{ c.topic
								? sprintf(
										/* translators: 1: a topic word, 2: impressions, 3: a position. */
										__(
											'in the "%1$s" topic, %2$d impressions, best position %3$s',
											'zinn-seo'
										),
										c.topic,
										c.impressions,
										c.best
									)
								: __(
										'not found in your search data yet — a page to write',
										'zinn-seo'
									) }
						</li>
					) ) }
				</ul>
			) }
		</li>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function KeywordListsTab( { data } ) {
	const [ search, setSearch ] = useState( '' );
	const state = usePaged(
		`/keyword-lists?limit=100&search=${ encodeURIComponent( search ) }`
	);
	const [ editing, setEditing ] = useState( null );
	const lists = state.items;
	return (
		<div className="zseo-stack zseo-x1c">
			<p className="zseo-muted">
				{ __(
					'Keep the phrases you want to rank for in labelled lists. Give each post the lists it should rank for; send a list to rank tracking, plan a draft from it, or hold it against the searches you already appear for.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="keyword-lists" />
			</p>
			<ErrorLine text={ state.error } />
			{ editing ? (
				<ListForm
					list={ editing }
					onCancel={ () => setEditing( null ) }
					onDone={ () => {
						setEditing( null );
						state.reload();
					} }
				/>
			) : (
				<div className="zseo-x1c-row">
					<Button
						variant="primary"
						onClick={ () => setEditing( {} ) }
					>
						{ __( 'New list', 'zinn-seo' ) }
					</Button>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Find a list or phrase', 'zinn-seo' ) }
						value={ search }
						onChange={ setSearch }
					/>
				</div>
			) }
			{ state.loading && <Spinner /> }
			{ ! state.loading && ! lists.length && (
				<p className="zseo-muted">
					{ __( 'No keyword lists yet.', 'zinn-seo' ) }
				</p>
			) }
			<ul className="zseo-x1c-lists">
				{ lists.map( ( l ) => (
					<ListCard
						key={ l.id }
						list={ l }
						data={ data }
						onEdit={ () => setEditing( l ) }
						onGone={ state.reload }
					/>
				) ) }
			</ul>
			<MoreButton list={ state } />
		</div>
	);
}
