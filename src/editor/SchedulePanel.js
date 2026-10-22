/**
 * "Schedule an SEO change" (zs-707, Pro).
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import GuideAnchor, { editorGuide } from '../admin/x1a/GuideAnchor';

const boot = window.zinnSeoEditor || {};
const base = boot.restPath || '/zinn-seo/v1';

/**
 * A local date-time input value ("2026-11-25T09:30") from unix seconds in the site's zone.
 *
 * @param {number} ts Unix seconds.
 * @return {string} Value.
 */
function whenText( ts ) {
	return new Date( ts * 1000 ).toLocaleString();
}

/**
 * The panel.
 *
 * @return {Element} Panel.
 */
export default function SchedulePanel() {
	const postId = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostId(),
		[]
	);
	const [ list, setList ] = useState( null );
	const [ draft, setDraft ] = useState( {
		title: '',
		desc: '',
		noindex: false,
		at: '',
		revert_at: '',
	} );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		if ( postId ) {
			apiFetch( { path: `${ base }/schedule/post/${ postId }` } )
				.then( ( r ) => setList( r.items ) )
				.catch( ( e ) => setError( e.message || '' ) );
		}
	}, [ postId ] );
	if ( null === list ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const add = () => {
		const fields = {};
		if ( draft.title.trim() ) {
			fields.title = draft.title.trim();
		}
		if ( draft.desc.trim() ) {
			fields.desc = draft.desc.trim();
		}
		if ( draft.noindex ) {
			fields.robots = [ 'noindex' ];
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `${ base }/schedule/post/${ postId }`,
			method: 'POST',
			data: {
				fields,
				at: draft.at.replace( 'T', ' ' ),
				revert_at: draft.revert_at.replace( 'T', ' ' ),
			},
		} )
			.then( ( r ) => {
				setList( r.items );
				setDraft( {
					title: '',
					desc: '',
					noindex: false,
					at: '',
					revert_at: '',
				} );
			} )
			.catch( ( e ) =>
				setError(
					e.message ||
						__( 'The change was not scheduled.', 'zinn-seo' )
				)
			)
			.finally( () => setBusy( false ) );
	};
	const cancel = ( id ) =>
		apiFetch( {
			path: `${ base }/schedule/post/${ postId }/${ id }`,
			method: 'DELETE',
		} )
			.then( ( r ) => setList( r.items ) )
			.catch( ( e ) => setError( e.message || '' ) );
	const live = list.filter( ( e ) =>
		[ 'waiting', 'applied' ].includes( e.status )
	);
	return (
		<div className="zseo-ed">
			<p className="zseo-ed-hint">
				{ sprintf(
					/* translators: %s: the site's time zone. */
					__(
						"For a sale or a season: the change starts on its date and goes back on the end date. Times are in the site's time zone (%s).",
						'zinn-seo'
					),
					( boot.schedule || {} ).timezone || 'UTC'
				) }{ ' ' }
				<GuideAnchor href={ editorGuide( 'schedule' ) } />
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ live.map( ( e ) => (
				<div key={ e.id } className="zseo-ed-scheduled">
					<p>
						{ 'waiting' === e.status
							? sprintf(
									/* translators: %s: date and time. */
									__( 'Starts %s', 'zinn-seo' ),
									whenText( e.at )
								)
							: sprintf(
									/* translators: %s: date and time. */
									__(
										'Running; changes back %s',
										'zinn-seo'
									),
									whenText( e.revert_at )
								) }
					</p>
					<Button
						variant="link"
						isDestructive
						onClick={ () => cancel( e.id ) }
					>
						{ 'applied' === e.status
							? __( 'Change back now', 'zinn-seo' )
							: __( 'Cancel', 'zinn-seo' ) }
					</Button>
				</div>
			) ) }
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'New SEO title', 'zinn-seo' ) }
				value={ draft.title }
				onChange={ ( v ) => setDraft( { ...draft, title: v } ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'New meta description', 'zinn-seo' ) }
				value={ draft.desc }
				rows={ 2 }
				onChange={ ( v ) => setDraft( { ...draft, desc: v } ) }
			/>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Hide from search results meanwhile', 'zinn-seo' ) }
				checked={ draft.noindex }
				onChange={ ( v ) => setDraft( { ...draft, noindex: v } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				type="datetime-local"
				label={ __( 'Starts', 'zinn-seo' ) }
				value={ draft.at }
				onChange={ ( v ) => setDraft( { ...draft, at: v } ) }
			/>
			<TextControl
				__nextHasNoMarginBottom
				type="datetime-local"
				label={ __( 'Changes back (optional)', 'zinn-seo' ) }
				value={ draft.revert_at }
				onChange={ ( v ) => setDraft( { ...draft, revert_at: v } ) }
			/>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={
					busy ||
					! draft.at ||
					( ! draft.title && ! draft.desc && ! draft.noindex )
				}
				onClick={ add }
			>
				{ __( 'Schedule the change', 'zinn-seo' ) }
			</Button>
		</div>
	);
}
