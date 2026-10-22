import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	CheckboxControl,
	Notice,
	PanelBody,
	Spinner,
} from '@wordpress/components';

import { errorText, seoFetch } from './api';

/**
 * The SEO capabilities, in the order the table shows them.
 *
 * @return {Array<{cap: string, label: string}>} Capabilities.
 */
export function capabilityColumns() {
	return [
		{ cap: 'zseo_manage', label: __( 'Settings', 'zinn-seo' ) },
		{ cap: 'zseo_edit', label: __( 'Page SEO', 'zinn-seo' ) },
		{ cap: 'zseo_view', label: __( 'Reports', 'zinn-seo' ) },
		{ cap: 'zseo_redirects', label: __( 'Redirects', 'zinn-seo' ) },
		{ cap: 'zseo_ai', label: __( 'AI', 'zinn-seo' ) },
		{ cap: 'zseo_spend', label: __( 'Credits', 'zinn-seo' ) },
	];
}

/**
 * Pro: give any role any SEO capability.
 *
 * @return {Element} The editor.
 */
function RoleEditor() {
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	useEffect( () => {
		seoFetch( '/roles' )
			.then( setState )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [] );

	const toggle = ( role, cap, grant ) => {
		setBusy( `${ role }:${ cap }` );
		setError( '' );
		seoFetch( '/roles', {
			method: 'PUT',
			data: { role, caps: { [ cap ]: grant } },
		} )
			.then( setState )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const columns = capabilityColumns();

	return (
		<>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<div className="zak-table-wrap">
				<table className="zak-table zseo-roles">
					<thead>
						<tr>
							<th scope="col">{ __( 'Role', 'zinn-seo' ) }</th>
							{ columns.map( ( c ) => (
								<th
									scope="col"
									key={ c.cap }
									title={ state.capabilities[ c.cap ] }
								>
									{ c.label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ state.roles.map( ( r ) => (
							<tr key={ r.role }>
								<th scope="row">{ r.name }</th>
								{ columns.map( ( c ) => (
									<td key={ c.cap }>
										<CheckboxControl
											__nextHasNoMarginBottom
											label={ `${ r.name }: ${ c.label }` }
											hideLabelFromVision
											checked={ !! r.caps[ c.cap ] }
											disabled={ '' !== busy }
											onChange={ ( grant ) =>
												toggle( r.role, c.cap, grant )
											}
										/>
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</>
	);
}

/**
 * The Roles screen: what the two SEO roles may do, and (Pro) every role's SEO permissions.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function RolesScreen( { data } ) {
	return (
		<div className="zseo-admin zseo-stack">
			<PanelBody title={ __( 'SEO roles', 'zinn-seo' ) }>
				<p>
					{ __(
						'Give colleagues a role in Users so they can look after SEO without being administrators. Administrators can always do everything.',
						'zinn-seo'
					) }
				</p>
				<dl className="zseo-roles-list">
					<dt>{ __( 'SEO Manager', 'zinn-seo' ) }</dt>
					<dd>
						{ __(
							'Everything an Editor can do, plus every SEO setting, page SEO, reports, redirects and AI features. Not spending credits.',
							'zinn-seo'
						) }
					</dd>
					<dt>{ __( 'SEO Editor', 'zinn-seo' ) }</dt>
					<dd>
						{ __(
							'Everything an Editor can do, plus the SEO fields of pages and SEO reports.',
							'zinn-seo'
						) }
					</dd>
				</dl>
				<p>
					<a href={ data.usersUrl }>
						{ __( 'Go to Users', 'zinn-seo' ) }
					</a>
				</p>
			</PanelBody>
			<PanelBody title={ __( 'Permissions for every role', 'zinn-seo' ) }>
				{ data.roles ? (
					<RoleEditor />
				) : (
					<p>
						{ __(
							'With Zinn® SEO Pro you can give any role exactly the SEO permissions it needs, for example letting authors edit the SEO of their own posts.',
							'zinn-seo'
						) }{ ' ' }
						<a
							href={ `${ data.adminUrl }admin.php?page=zinn-seo&view=plans` }
						>
							{ __( 'Compare plans', 'zinn-seo' ) }
						</a>
					</p>
				) }
			</PanelBody>
		</div>
	);
}
