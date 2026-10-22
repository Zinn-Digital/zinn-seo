/**
 * Pro: the block editor's "SEO lock" panel, and the confirmation a locked page's protected change
 * needs (zs-331).
 *
 * ⭐ The save is refused by the server BEFORE anything is stored (409 `zinn_seo_locked`); an
 * apiFetch middleware catches that one answer, asks the person in a modal, and resends the same
 * request with `X-Zseo-Confirm-Lock: 1` only when they confirm. Every other request passes through
 * untouched.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

const listeners = new Set();
let pending = null;

/**
 * Ask the person (the modal component answers).
 *
 * @param {string} message The server's sentence.
 * @return {Promise<boolean>} Confirmed.
 */
export function askConfirm( message ) {
	return new Promise( ( resolve ) => {
		pending = { message, resolve };
		listeners.forEach( ( fn ) => fn( pending ) );
	} );
}

/**
 * Listen for questions (the modal does; tests answer directly).
 *
 * @param {Function} fn Called with {message, resolve}.
 * @return {Function} Unsubscribe.
 */
export function subscribe( fn ) {
	listeners.add( fn );
	return () => listeners.delete( fn );
}

/**
 * The middleware: a locked save is asked about, then resent with the confirm header.
 *
 * @param {string} header The confirm header's name.
 * @return {Function} Middleware.
 */
export function lockMiddleware( header ) {
	return ( options, next ) =>
		next( options ).catch( ( error ) => {
			if ( ! error || 'zinn_seo_locked' !== error.code ) {
				throw error;
			}
			return askConfirm( error.message ).then( ( yes ) => {
				if ( ! yes ) {
					throw error;
				}
				return next( {
					...options,
					headers: { ...( options.headers || {} ), [ header ]: '1' },
				} );
			} );
		} );
}

/**
 * The modal that answers askConfirm().
 *
 * @return {Element|null} Modal.
 */
export function LockConfirm() {
	const [ ask, setAsk ] = useState( null );
	useEffect( () => {
		const off = subscribe( ( p ) => setAsk( p ) );
		if ( pending ) {
			setAsk( pending );
		}
		return off;
	}, [] );
	if ( ! ask ) {
		return null;
	}
	const answer = ( yes ) => {
		ask.resolve( yes );
		pending = null;
		setAsk( null );
	};
	return (
		<Modal
			title={ __( 'Change a locked page?', 'zinn-seo' ) }
			onRequestClose={ () => answer( false ) }
			className="zseo-modal"
		>
			<p>{ ask.message }</p>
			<div className="zseo-actions">
				<Button variant="tertiary" onClick={ () => answer( false ) }>
					{ __( 'Keep it as it was', 'zinn-seo' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					onClick={ () => answer( true ) }
				>
					{ __( 'Change it anyway', 'zinn-seo' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * The document panel: lock state and switch.
 *
 * @param {Object}  props
 * @param {Object}  props.boot    Editor boot data.
 * @param {boolean} props.canLock May lock and unlock.
 * @return {Element} Panel body.
 */
export function LockPanel( { boot, canLock } ) {
	const id = useSelect( ( s ) => s( 'core/editor' ).getCurrentPostId(), [] );
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( '' );
	const base = boot.restPath || '/zinn-seo/v1';
	useEffect( () => {
		if ( ! id ) {
			return;
		}
		apiFetch( { path: `${ base }/lock/${ id }` } )
			.then( setState )
			.catch( ( e ) => setError( e.message || '' ) );
	}, [ id, base ] );
	if ( ! state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	return (
		<div className="zseo-lock">
			{ canLock ? (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Lock the address, indexing and canonical',
						'zinn-seo'
					) }
					help={ __(
						'Changing them then needs a confirmation, and every change is logged.',
						'zinn-seo'
					) }
					checked={ state.locked }
					onChange={ ( locked ) =>
						apiFetch( {
							path: `${ base }/lock/${ id }`,
							method: 'POST',
							data: { locked },
						} )
							.then( setState )
							.catch( ( e ) => setError( e.message || '' ) )
					}
				/>
			) : (
				<p>
					{ state.locked
						? __(
								'This page is SEO-locked: its address, indexing and canonical change only with a confirmation.',
								'zinn-seo'
							)
						: __( 'This page is not locked.', 'zinn-seo' ) }
				</p>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
		</div>
	);
}
