/**
 * SEO tasks (zs-420): any finding becomes a task for a person, with a due date, comments and a
 * done state; shown in the Fix it inbox and on their own screen.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../admin/api';
import { RunButton } from '../admin/p4/shared';
import './agency.scss';

/**
 * A date as the reader's calendar writes it.
 *
 * @param {string} ymd `YYYY-MM-DD`.
 * @return {string} Date.
 */
function day( ymd ) {
	if ( ! ymd ) {
		return '';
	}
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return new Date( y, m - 1, d ).toLocaleDateString();
}

/**
 * The people a task can be given to (managers only).
 *
 * @param {boolean} manage May manage.
 * @return {Array<Object>} People.
 */
function usePeople( manage ) {
	const [ people, setPeople ] = useState( [] );
	useEffect( () => {
		if ( manage ) {
			seoFetch( '/agency/tasks/people' )
				.then( setPeople )
				.catch( () => {} );
		}
	}, [ manage ] );
	return people;
}

/**
 * The form that makes (or changes) a task.
 *
 * @param {Object}   props
 * @param {Object}   props.initial Initial fields.
 * @param {Array}    props.people  People.
 * @param {Function} props.onSave  Called with the saved task.
 * @param {Function} props.onClose Close.
 * @return {Element} Modal.
 */
export function TaskForm( { initial = {}, people, onSave, onClose } ) {
	const [ task, setTask ] = useState( {
		title: '',
		detail: '',
		assignee: 0,
		due: '',
		...initial,
	} );
	const [ error, setError ] = useState( '' );
	const set = ( patch ) => setTask( { ...task, ...patch } );
	return (
		<Modal
			title={
				task.id
					? __( 'Change the task', 'zinn-seo' )
					: __( 'Make it a task', 'zinn-seo' )
			}
			onRequestClose={ onClose }
			className="zseo-task-modal"
		>
			<div className="zseo-stack">
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'What needs doing', 'zinn-seo' ) }
					value={ task.title }
					onChange={ ( v ) => set( { title: v } ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Details', 'zinn-seo' ) }
					value={ task.detail }
					onChange={ ( v ) => set( { detail: v } ) }
				/>
				<div className="zseo-grid-2">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Who does it', 'zinn-seo' ) }
						value={ String( task.assignee || 0 ) }
						options={ [
							{
								value: '0',
								label: __( 'Nobody yet', 'zinn-seo' ),
							},
							...people.map( ( p ) => ( {
								value: String( p.id ),
								label: p.client
									? sprintf(
											/* translators: %s: a person's name. */
											__( '%s (client)', 'zinn-seo' ),
											p.name
										)
									: p.name,
							} ) ),
						] }
						onChange={ ( v ) =>
							set( { assignee: parseInt( v, 10 ) } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="date"
						label={ __( 'Due', 'zinn-seo' ) }
						value={ task.due }
						onChange={ ( v ) => set( { due: v } ) }
					/>
				</div>
				<div className="zseo-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'zinn-seo' ) }
					</Button>
					<RunButton
						variant="primary"
						onError={ setError }
						disabled={ ! task.title.trim() }
						run={ () =>
							seoFetch(
								task.id
									? `/agency/tasks/${ task.id }`
									: '/agency/tasks',
								{
									method: task.id ? 'PATCH' : 'POST',
									data: {
										title: task.title,
										detail: task.detail,
										assignee: task.assignee || 0,
										due: task.due || '',
										issue: task.issue || '',
										url: task.url || '',
									},
								}
							).then( onSave )
						}
					>
						{ __( 'Save the task', 'zinn-seo' ) }
					</RunButton>
				</div>
			</div>
		</Modal>
	);
}

/**
 * "Make it a task" on a Fix it finding.
 *
 * @param {Object}   props
 * @param {Object}   props.issue  The finding.
 * @param {Object}   props.data   Boot data.
 * @param {Function} props.onMade Called after a task is made.
 * @return {Element|null} Button.
 */
export function TaskButton( { issue, data, onMade = () => {} } ) {
	const [ open, setOpen ] = useState( false );
	const manage = !! ( data.tasks && data.tasks.manage );
	const people = usePeople( manage && open );
	if ( ! manage ) {
		return null;
	}
	return (
		<>
			<Button variant="tertiary" onClick={ () => setOpen( true ) }>
				{ __( 'Make it a task', 'zinn-seo' ) }
			</Button>
			{ open && (
				<TaskForm
					initial={ {
						title: issue.title,
						detail: issue.detail || '',
						issue: issue.id,
						url: issue.fix && issue.fix.url ? issue.fix.url : '',
					} }
					people={ people }
					onClose={ () => setOpen( false ) }
					onSave={ ( t ) => {
						setOpen( false );
						onMade( t );
					} }
				/>
			) }
		</>
	);
}

/**
 * One task.
 *
 * @param {Object}   props
 * @param {Object}   props.task     Task.
 * @param {boolean}  props.manage   May manage.
 * @param {number}   props.me       Current user id.
 * @param {Array}    props.people   People.
 * @param {Function} props.onChange Replace the task (null = removed).
 * @param {Function} props.onError  Error.
 * @return {Element} Card.
 */
function Task( { task, manage, me, people, onChange, onError } ) {
	const [ comment, setComment ] = useState( '' );
	const [ editing, setEditing ] = useState( false );
	const [ open, setOpen ] = useState( false );
	const mine = manage || task.assignee === me;
	return (
		<Card
			className={ `zseo-task${ task.done ? ' is-done' : '' }${
				task.overdue ? ' is-overdue' : ''
			}` }
		>
			<CardBody className="zseo-stack">
				<div className="zseo-task__head">
					<h3 className="zseo-task__title">{ task.title }</h3>
					<span className="zseo-task__meta">
						{ task.assignee_name ||
							__( 'Not given to anyone yet', 'zinn-seo' ) }
						{ task.due &&
							` · ${
								task.overdue
									? sprintf(
											/* translators: %s: a date. */
											__(
												'overdue since %s',
												'zinn-seo'
											),
											day( task.due )
										)
									: sprintf(
											/* translators: %s: a date. */
											__( 'due %s', 'zinn-seo' ),
											day( task.due )
										)
							}` }
						{ task.done ? ` · ${ __( 'done', 'zinn-seo' ) }` : '' }
					</span>
				</div>
				{ task.detail && (
					<p className="zseo-task__detail">{ task.detail }</p>
				) }
				{ task.url && (
					<p>
						<a href={ task.url }>
							{ __( 'Open where it is fixed', 'zinn-seo' ) }
						</a>
					</p>
				) }
				<div className="zseo-actions">
					{ mine && (
						<RunButton
							variant={ task.done ? 'secondary' : 'primary' }
							onError={ onError }
							run={ () =>
								seoFetch( `/agency/tasks/${ task.id }/done`, {
									method: 'POST',
									data: { done: ! task.done },
								} ).then( onChange )
							}
						>
							{ task.done
								? __( 'Open it again', 'zinn-seo' )
								: __( 'Mark as done', 'zinn-seo' ) }
						</RunButton>
					) }
					<Button
						variant="tertiary"
						onClick={ () => setOpen( ! open ) }
						aria-expanded={ open }
					>
						{ sprintf(
							/* translators: %d: number of comments. */
							_n(
								'%d comment',
								'%d comments',
								task.comments.length,
								'zinn-seo'
							),
							task.comments.length
						) }
					</Button>
					{ manage && (
						<Button
							variant="tertiary"
							onClick={ () => setEditing( true ) }
						>
							{ __( 'Change', 'zinn-seo' ) }
						</Button>
					) }
					{ manage && (
						<RunButton
							variant="tertiary"
							onError={ onError }
							run={ () =>
								seoFetch( `/agency/tasks/${ task.id }`, {
									method: 'DELETE',
								} ).then( () => onChange( null ) )
							}
						>
							{ __( 'Delete', 'zinn-seo' ) }
						</RunButton>
					) }
				</div>
				{ open && (
					<div className="zseo-task__comments">
						{ task.comments.map( ( c, i ) => (
							<p key={ i }>
								<strong>{ c.name }</strong>{ ' ' }
								<span className="zseo-muted">
									{ new Date( c.at * 1000 ).toLocaleString() }
								</span>
								<br />
								{ c.text }
							</p>
						) ) }
						{ mine && (
							<div className="zseo-row">
								<TextareaControl
									__nextHasNoMarginBottom
									label={ __( 'Add a comment', 'zinn-seo' ) }
									value={ comment }
									onChange={ setComment }
								/>
								<RunButton
									onError={ onError }
									disabled={ ! comment.trim() }
									run={ () =>
										seoFetch(
											`/agency/tasks/${ task.id }/comments`,
											{
												method: 'POST',
												data: { text: comment },
											}
										).then( ( t ) => {
											setComment( '' );
											onChange( t );
										} )
									}
								>
									{ __( 'Send', 'zinn-seo' ) }
								</RunButton>
							</div>
						) }
					</div>
				) }
				{ editing && (
					<TaskForm
						initial={ task }
						people={ people }
						onClose={ () => setEditing( false ) }
						onSave={ ( t ) => {
							setEditing( false );
							onChange( t );
						} }
					/>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The task list (its own screen, and inside Fix it).
 *
 * @param {Object}  props
 * @param {Object}  props.data    Boot data.
 * @param {boolean} props.compact Only open tasks, inside Fix it.
 * @param {number}  props.version Reload when this changes.
 * @return {Element} List.
 */
export default function TasksScreen( { data, compact = false, version = 0 } ) {
	const manage = !! ( data.tasks && data.tasks.manage );
	const me = ( data.tasks && data.tasks.me ) || 0;
	const [ status, setStatus ] = useState( 'open' );
	const [ list, setList ] = useState( null );
	const [ next, setNext ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const people = usePeople( manage );
	useEffect( () => {
		setList( null );
		seoFetch( `/agency/tasks?status=${ status }` )
			.then( ( r ) => {
				setList( r.items );
				setNext( r.next || 0 );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ status, version ] );
	const replace = ( id, task ) =>
		setList(
			task
				? list.map( ( t ) => ( t.id === id ? task : t ) )
				: list.filter( ( t ) => t.id !== id )
		);
	if ( compact && list && ! list.length ) {
		return null;
	}
	return (
		<div
			className={ `zseo-admin zseo-tasks${ compact ? ' is-compact' : '' }` }
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<div className="zseo-tasks__bar">
				<h2 className="zseo-h2">
					{ compact
						? __( 'Open tasks', 'zinn-seo' )
						: __( 'Tasks', 'zinn-seo' ) }
				</h2>
				{ ! compact && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Show', 'zinn-seo' ) }
						hideLabelFromVision
						value={ status }
						options={ [
							{ value: 'open', label: __( 'Open', 'zinn-seo' ) },
							{ value: 'done', label: __( 'Done', 'zinn-seo' ) },
							{ value: 'all', label: __( 'All', 'zinn-seo' ) },
						] }
						onChange={ setStatus }
					/>
				) }
				{ manage && ! compact && (
					<Button
						variant="primary"
						onClick={ () => setAdding( true ) }
					>
						{ __( 'New task', 'zinn-seo' ) }
					</Button>
				) }
			</div>
			{ ! list && <Spinner /> }
			{ list && ! list.length && (
				<p className="zseo-muted">
					{ status === 'open'
						? __(
								'No open tasks. Use “Make it a task” on anything in Fix it to hand it to someone.',
								'zinn-seo'
							)
						: __( 'Nothing here yet.', 'zinn-seo' ) }
				</p>
			) }
			<div className="zseo-tasks__list">
				{ ( list || [] ).map( ( t ) => (
					<Task
						key={ t.id }
						task={ t }
						manage={ manage }
						me={ me }
						people={ people }
						onError={ setError }
						onChange={ ( item ) => replace( t.id, item ) }
					/>
				) ) }
			</div>
			{ ! compact && next > 0 && (
				<div className="zseo-actions">
					<RunButton
						onError={ setError }
						run={ () =>
							seoFetch(
								`/agency/tasks?status=${ status }&after=${ next }`
							).then( ( r ) => {
								setList( [ ...( list || [] ), ...r.items ] );
								setNext( r.next || 0 );
							} )
						}
					>
						{ __( 'Show more', 'zinn-seo' ) }
					</RunButton>
				</div>
			) }
			{ adding && (
				<TaskForm
					people={ people }
					onClose={ () => setAdding( false ) }
					onSave={ ( t ) => {
						setAdding( false );
						setList( [ t, ...( list || [] ) ] );
					} }
				/>
			) }
		</div>
	);
}
