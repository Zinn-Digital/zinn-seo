/**
 * The .htaccess and Nginx rules editor (zs-716, Pro).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { useRoute } from '../p4/shared';

/**
 * The outcome of a save, in words.
 *
 * @param {Object} r The result.
 * @return {Object} {status, text}.
 */
function said( r ) {
	switch ( r.outcome ) {
		case 'saved':
			return {
				status: 'success',
				text: __(
					'Saved. Your site answered normally with the new rules.',
					'zinn-seo'
				),
			};
		case 'saved-watching':
			return {
				status: 'info',
				text: __(
					'Saved. Nginx reads the file when your host reloads it; your site is checked every minute for the next ten minutes and the previous rules come back by themselves if it stops answering.',
					'zinn-seo'
				),
			};
		case 'restored':
			return {
				status: 'warning',
				text: sprintf(
					/* translators: %s: what went wrong. */
					__(
						'Your site stopped answering with the new rules (%s), so the previous rules were put back at once. Nothing else changed.',
						'zinn-seo'
					),
					r.why
				),
			};
		default:
			return {
				status: 'error',
				text: sprintf(
					/* translators: %s: what went wrong. */
					__(
						'The new rules stopped your site (%s) and putting the old ones back did not bring it back. Ask your host to restore the file from the backup listed below.',
						'zinn-seo'
					),
					r.why
				),
			};
	}
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function ServerRulesTab( { data } ) {
	const isPro = !! ( data.modules || {} )[ 'server-rules' ];
	const state = useRoute( isPro ? '/server-rules' : '/settings' );
	const [ text, setText ] = useState( null );
	const [ check, setCheck ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ backup, setBackup ] = useState( '' );
	useEffect( () => {
		if ( isPro && state.data && null === text ) {
			setText( state.data.text || '' );
		}
	}, [ isPro, state.data, text ] );
	if ( ! isPro ) {
		return (
			<ProTeaser
				title={ __( 'Edit .htaccess safely', 'zinn-seo' ) }
				points={ [
					__( 'Every line checked before it is saved.', 'zinn-seo' ),
					__( 'A backup kept of every version.', 'zinn-seo' ),
					__(
						'The old rules come back by themselves if your site stops answering.',
						'zinn-seo'
					),
				] }
			/>
		);
	}
	if ( ! state.data || null === text ) {
		return <Spinner />;
	}
	const s = state.data;
	const save = ( accept ) => {
		setBusy( true );
		setNotice( null );
		seoFetch( '/server-rules', {
			method: 'PUT',
			data: { text, accept_warnings: accept },
		} )
			.then( ( r ) => {
				setNotice( said( r ) );
				setCheck( null );
				state.setData( r.state );
				setText( r.state.text );
			} )
			.catch( ( e ) => {
				setCheck(
					e.data && ( e.data.errors || e.data.warnings )
						? e.data
						: null
				);
				setNotice( { status: 'error', text: errorText( e ) } );
			} )
			.finally( () => setBusy( false ) );
	};
	const restore = () => {
		setBusy( true );
		seoFetch( '/server-rules/restore', {
			method: 'POST',
			data: { backup },
		} )
			.then( ( r ) => {
				setNotice( said( r ) );
				state.setData( r.state );
				setText( r.state.text );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const lines = ( list ) => (
		<ul className="zseo-x1a-list">
			{ list.map( ( p, i ) => (
				<li key={ i }>
					{ p.line > 0 &&
						sprintf(
							/* translators: %d: a line number. */
							__( 'Line %d:', 'zinn-seo' ),
							p.line
						) }{ ' ' }
					{ p.message }
				</li>
			) ) }
		</ul>
	);
	const onlyWarnings =
		check &&
		! ( check.errors || [] ).length &&
		( check.warnings || [] ).length;
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ 'nginx' === s.server
					? __(
							'Your server is Nginx. Edit the rules file your host includes for this site.',
							'zinn-seo'
						)
					: __(
							'One wrong line in .htaccess takes a whole site down. Here every line is checked before saving, the old file is kept, and if your site stops answering the old rules come back at once.',
							'zinn-seo'
						) }{ ' ' }
				<GuideLink data={ data } id="server-rules" />
			</p>
			{ ! s.file && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'This server reads no rules file WordPress may change. Ask your host which file to use; they can name it with the ZSEO_NGINX_RULES_FILE setting.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ s.file && ! s.allowed && (
				<Notice status="info" isDismissible={ false }>
					{ s.network
						? __(
								'On a network this file serves every site: only a network administrator can change it, from the main site.',
								'zinn-seo'
							)
						: __(
								'Only an administrator can change this file.',
								'zinn-seo'
							) }
				</Notice>
			) }
			{ s.file && ! s.writable && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WordPress may not write this file on this server, so it can be read here but not saved.',
						'zinn-seo'
					) }
				</Notice>
			) }
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ check && ( check.errors || [] ).length > 0 && (
				<Notice status="error" isDismissible={ false }>
					<p>{ __( 'Fix these lines first:', 'zinn-seo' ) }</p>
					{ lines( check.errors ) }
				</Notice>
			) }
			{ check && ( check.warnings || [] ).length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<p>{ __( 'Look at these before saving:', 'zinn-seo' ) }</p>
					{ lines( check.warnings ) }
				</Notice>
			) }
			{ s.file && (
				<>
					<TextareaControl
						__nextHasNoMarginBottom
						className="zseo-x1a-code"
						label={ s.file }
						value={ text }
						rows={ 18 }
						dir="ltr"
						spellCheck={ false }
						disabled={ ! s.allowed || ! s.writable }
						onChange={ ( v ) => {
							setText( v );
							setCheck( null );
						} }
					/>
					<div className="zseo-actions zseo-actions--start">
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () =>
								seoFetch( '/server-rules/check', {
									method: 'POST',
									data: { text },
								} )
									.then( setCheck )
									.catch( ( e ) =>
										setNotice( {
											status: 'error',
											text: errorText( e ),
										} )
									)
							}
						>
							{ __( 'Check the rules', 'zinn-seo' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={
								busy ||
								! s.allowed ||
								! s.writable ||
								text === s.text
							}
							onClick={ () => save( !! onlyWarnings ) }
						>
							{ onlyWarnings
								? __( 'Save anyway', 'zinn-seo' )
								: __( 'Save the rules', 'zinn-seo' ) }
						</Button>
					</div>
				</>
			) }
			{ ( s.backups || [] ).length > 0 && (
				<div className="zseo-row zseo-row--top">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Backups', 'zinn-seo' ) }
						value={ backup }
						options={ [
							{
								value: '',
								label: __( 'Choose a backup…', 'zinn-seo' ),
							},
							...s.backups.map( ( b ) => ( {
								value: b.name,
								label: new Date( b.at * 1000 ).toLocaleString(),
							} ) ),
						] }
						onChange={ setBackup }
					/>
					<Button
						variant="secondary"
						disabled={ ! backup || busy || ! s.allowed }
						onClick={ restore }
					>
						{ __( 'Put this backup back', 'zinn-seo' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
