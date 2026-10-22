/**
 * Settings → AI agents (zs-210…215): the MCP switch and connection (the shared kit's panel), what
 * happens to an agent's changes, the changes waiting for approval, and what agents did.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	PanelBody,
	RadioControl,
	Spinner,
} from '@wordpress/components';

import McpPanel from '../../mcp-kit/McpPanel';
import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import { Table, useRoute } from '../p4/shared';
import { outcomeText, writeModes } from './pure';
import './mcp.scss';

/**
 * A time, in the viewer's own words.
 *
 * @param {number} seconds Unix time.
 * @return {string} Local date and time.
 */
function when( seconds ) {
	return new Date( seconds * 1000 ).toLocaleString();
}

/**
 * What happens to an agent's change.
 *
 * @param {Object}                    props
 * @param {string}                    props.value    apply|approve|off.
 * @param {(payload: Object) => void} props.onSaved  Called with the new settings payload.
 * @param {boolean}                   props.disabled The panel is not usable yet.
 * @return {Element} The control.
 */
function WriteMode( { value, onSaved, disabled } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const change = ( writes ) => {
		setBusy( true );
		setError( '' );
		seoFetch( '/mcp-settings', { method: 'POST', data: { writes } } )
			.then( onSaved )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<RadioControl
				label={ __( 'When an AI agent changes something', 'zinn-seo' ) }
				help={ __(
					'Whatever you choose, an agent can always ask to try a change first: it sees what would change and nothing is saved.',
					'zinn-seo'
				) }
				selected={ value }
				options={ writeModes() }
				onChange={ change }
				disabled={ busy || disabled }
			/>
		</>
	);
}

/**
 * The changes waiting for a person.
 *
 * @param {Object}     props
 * @param {() => void} props.onDecided Called after a decision.
 * @return {Element} The list.
 */
function Approvals( { onDecided } ) {
	const { data, error, reload } = useRoute( '/agents/approvals' );
	const [ busy, setBusy ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const decide = ( id, decision ) => {
		setBusy( id );
		setNotice( null );
		seoFetch( `/agents/approvals/${ id }`, {
			method: 'POST',
			data: { decision },
		} )
			.then( () => {
				setNotice( {
					status: 'success',
					text:
						'approve' === decision
							? __( 'Approved: the change is made.', 'zinn-seo' )
							: __(
									'Rejected: nothing was changed.',
									'zinn-seo'
								),
				} );
				reload();
				onDecided();
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( '' ) );
	};
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	return (
		<>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<Table
				label={ __( 'Changes waiting for approval', 'zinn-seo' ) }
				empty={ __( 'Nothing is waiting for you.', 'zinn-seo' ) }
				rows={ data.items || [] }
				columns={ [
					{
						id: 'time',
						label: __( 'Asked', 'zinn-seo' ),
						render: ( r ) => when( r.time ),
					},
					{
						id: 'who',
						label: __( 'Agent signed in as', 'zinn-seo' ),
						render: ( r ) => r.login,
					},
					{
						id: 'what',
						label: __( 'Change', 'zinn-seo' ),
						render: ( r ) => (
							<>
								<code dir="ltr">{ r.ability }</code>
								<details>
									<summary>
										{ __( 'Values', 'zinn-seo' ) }
									</summary>
									<pre className="zseo-pre" dir="ltr">
										{ JSON.stringify( r.params, null, 2 ) }
									</pre>
								</details>
							</>
						),
					},
					{
						id: 'act',
						label: __( 'Decision', 'zinn-seo' ),
						render: ( r ) => (
							<span className="zseo-row-actions">
								<Button
									variant="primary"
									isBusy={ busy === r.id }
									disabled={ !! busy }
									onClick={ () => decide( r.id, 'approve' ) }
								>
									{ __( 'Approve', 'zinn-seo' ) }
								</Button>
								<Button
									variant="secondary"
									disabled={ !! busy }
									onClick={ () => decide( r.id, 'reject' ) }
								>
									{ __( 'Reject', 'zinn-seo' ) }
								</Button>
							</span>
						),
					},
				] }
			/>
		</>
	);
}

/**
 * What agents did.
 *
 * @return {Element} The list.
 */
function Activity() {
	const { data, error, reload } = useRoute( '/agents/activity?limit=50' );
	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}
	return (
		<>
			<Table
				label={ __( 'What AI agents did', 'zinn-seo' ) }
				empty={ __(
					'No AI agent has used this site yet.',
					'zinn-seo'
				) }
				rows={ data.items || [] }
				columns={ [
					{
						id: 'time',
						label: __( 'When', 'zinn-seo' ),
						render: ( r ) => when( r.time ),
					},
					{
						id: 'who',
						label: __( 'Signed in as', 'zinn-seo' ),
						render: ( r ) =>
							sprintf(
								/* translators: 1: a WordPress user name, 2: the app that connected (an application password's name, "WP-CLI" or "MCP"). */
								__( '%1$s (%2$s)', 'zinn-seo' ),
								r.login,
								r.client
							),
					},
					{
						id: 'tool',
						label: __( 'Tool', 'zinn-seo' ),
						render: ( r ) => <code dir="ltr">{ r.ability }</code>,
					},
					{
						id: 'outcome',
						label: __( 'Result', 'zinn-seo' ),
						render: ( r ) => outcomeText( r.outcome ),
					},
				] }
			/>
			<p>
				<Button variant="secondary" onClick={ reload }>
					{ __( 'Refresh', 'zinn-seo' ) }
				</Button>
			</p>
		</>
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data printed by Admin::data().
 * @return {Element} The tab.
 */
export default function AgentsTab( { data } ) {
	const base =
		( window.zinnSeoAdmin && window.zinnSeoAdmin.restPath ) ||
		'/zinn-seo/v1';
	const settings = useRoute( '/mcp-settings' );
	const mcp = settings.data && settings.data.mcp;
	return (
		<div className="zseo-stack zseo-agents">
			<p>
				{ __(
					'Let an AI assistant check and improve your SEO for you: it can read every report and change anything you can change on these screens, signed in as you or another user, and only with that user’s permissions. In Claude, pick the prompt “Optimise this site” to have it audit the site, propose a plan, try each change first and report back.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="agents" />
			</p>
			<McpPanel path={ `${ base }/mcp-settings` } />
			<PanelBody title={ __( 'Changes made by AI agents', 'zinn-seo' ) }>
				{ settings.error && (
					<Notice status="error" isDismissible={ false }>
						{ settings.error }
					</Notice>
				) }
				{ ! mcp && ! settings.error && <Spinner /> }
				{ mcp && (
					<WriteMode
						value={ mcp.writes }
						disabled={ false }
						onSaved={ ( d ) => settings.setData( d ) }
					/>
				) }
				<p className="zseo-muted">
					{ __(
						'Every change an agent makes is recorded in the page’s SEO change history as made by an AI agent, and can be undone there.',
						'zinn-seo'
					) }
				</p>
			</PanelBody>
			<PanelBody
				title={
					mcp && mcp.pending
						? sprintf(
								/* translators: %d: how many changes wait for approval. */
								__(
									'Waiting for your approval (%d)',
									'zinn-seo'
								),
								mcp.pending
							)
						: __( 'Waiting for your approval', 'zinn-seo' )
				}
			>
				<Approvals onDecided={ () => settings.reload() } />
			</PanelBody>
			<PanelBody
				title={ __( 'What AI agents did', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<Activity />
			</PanelBody>
		</div>
	);
}
