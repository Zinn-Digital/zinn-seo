/**
 * Shared pieces of the stage X1A screens: a settings draft with one Save button, and a switch row
 * that shows what it changes.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, ToggleControl } from '@wordpress/components';

import { errorText, useSettings } from '../api';

/**
 * Settings edited as a draft, saved together.
 *
 * @return {Object} { value, change, dirty, save, busy, notice, setNotice, ready, error, settings }.
 */
export function useDraft() {
	const { settings, error, save } = useSettings();
	const [ draft, setDraft ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const value = { ...( settings || {} ), ...draft };
	const change = ( part ) => {
		setDraft( { ...draft, ...part } );
		setNotice( null );
	};
	const store = ( extra = {} ) => {
		setBusy( true );
		return save( { ...draft, ...extra } )
			.then( ( saved ) => {
				setDraft( {} );
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'zinn-seo' ),
				} );
				return saved;
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	return {
		value,
		change,
		dirty: Object.keys( draft ).length > 0,
		save: store,
		busy,
		notice,
		setNotice,
		ready: !! settings,
		error,
		settings,
	};
}

/**
 * The loading and error states of a screen that needs the settings.
 *
 * @param {Object}  props
 * @param {Object}  props.form     useDraft() result.
 * @param {Element} props.children The screen.
 * @return {Element} The screen, a spinner or the error.
 */
export function Ready( { form, children } ) {
	if ( form.error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ form.error }
			</Notice>
		);
	}
	if ( ! form.ready ) {
		return <Spinner />;
	}
	return children;
}

/**
 * The form's notice and its Save button.
 *
 * @param {Object} props
 * @param {Object} props.form useDraft() result.
 * @return {Element} Bar.
 */
export function SaveBar( { form } ) {
	return (
		<div className="zseo-x1a-save">
			{ form.notice && (
				<Notice
					status={ form.notice.status }
					onRemove={ () => form.setNotice( null ) }
				>
					{ form.notice.text }
				</Notice>
			) }
			<Button
				variant="primary"
				isBusy={ form.busy }
				disabled={ form.busy || ! form.dirty }
				onClick={ () => form.save() }
			>
				{ __( 'Save changes', 'zinn-seo' ) }
			</Button>
		</div>
	);
}

/**
 * A switch with what it does, and (when known) the exact lines it removes from this site.
 *
 * @param {Object} props
 * @param {Object} props.form  useDraft() result.
 * @param {string} props.name  Setting.
 * @param {string} props.label Label.
 * @param {string} props.help  What it does, in plain words.
 * @param {Array}  props.lines Lines found on the home page (null: not read).
 * @return {Element} Row.
 */
export function Switch( { form, name, label, help, lines } ) {
	return (
		<div className="zseo-x1a-switch">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				checked={ !! form.value[ name ] }
				onChange={ ( on ) => form.change( { [ name ]: on } ) }
			/>
			{ Array.isArray( lines ) && (
				<div className="zseo-x1a-found">
					{ lines.length ? (
						<>
							<p className="zseo-x1a-found__title">
								{ __( 'On your home page now:', 'zinn-seo' ) }
							</p>
							<ul>
								{ lines.map( ( l ) => (
									<li key={ l }>
										<code dir="ltr">{ l }</code>
									</li>
								) ) }
							</ul>
						</>
					) : (
						<p className="zseo-muted">
							{ __( 'Not on your home page now.', 'zinn-seo' ) }
						</p>
					) }
				</div>
			) }
		</div>
	);
}
