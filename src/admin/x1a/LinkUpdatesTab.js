/**
 * The internal link updates (zs-730): the runs, the queue, and a manual change.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	PanelBody,
	Spinner,
	TextControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { viewHref } from '../../admin-kit/router';
import GuideLink from '../p7/GuideLink';
import { JobLine, Table, useJob, useRoute } from '../p4/shared';
import { Switch, useDraft, SaveBar } from './shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function LinkUpdatesTab( { data } ) {
	const state = useRoute( '/link-updates' );
	const form = useDraft();
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ job, setJob ] = useJob(
		() => seoFetch( '/link-updates' ).then( ( r ) => r.job ),
		state.reload
	);
	useEffect( () => {
		if ( state.data ) {
			setJob( state.data.job );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ state.data ] );
	if ( ! state.data || ! form.ready ) {
		return <Spinner />;
	}
	const run = () =>
		seoFetch( '/link-updates', {
			method: 'POST',
			data: { old: from, new: to },
		} )
			.then( ( r ) => {
				state.setData( r );
				setJob( r.job );
				setFrom( '' );
				setTo( '' );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					"When you change a page's address, the old one redirects, but links inside your own site should point straight at the new one. They are updated for you, in the background, and every post that changes keeps a revision you can restore.",
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="link-updates" />
			</p>
			{ data && data.plan === 'free' && ! data.client && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Do it for the whole site with Pro: fix every internal link in one click, the ones that already hop through a redirect, land on a missing, trashed or hidden page or point at the wrong language, with a review first and one-click undo.',
						'zinn-seo'
					) }{ ' ' }
					<a href={ viewHref( 'plans' ) }>
						{ __( 'See the Pro plans', 'zinn-seo' ) }
					</a>
				</Notice>
			) }
			<PanelBody title={ __( 'Automatic', 'zinn-seo' ) }>
				<Switch
					form={ form }
					name="link_updater_auto"
					label={ __(
						'Update internal links when an address changes',
						'zinn-seo'
					) }
					help={ __(
						'Posts, pages, reusable blocks, navigation menus and other content types.',
						'zinn-seo'
					) }
				/>
				<SaveBar form={ form } />
			</PanelBody>
			<JobLine job={ job } />
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<PanelBody
				title={ __( 'Change links by hand', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<p className="zseo-muted">
					{ __(
						'For an address that moved outside WordPress, or an old domain: every internal link to it (and to the pages under it) changes to the new address.',
						'zinn-seo'
					) }
				</p>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Old address', 'zinn-seo' ) }
					placeholder="/old-page/"
					value={ from }
					onChange={ setFrom }
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'New address', 'zinn-seo' ) }
					placeholder="/new-page/"
					value={ to }
					onChange={ setTo }
				/>
				<Button
					variant="primary"
					disabled={ ! from || ! to }
					onClick={ run }
				>
					{ __( 'Update the links', 'zinn-seo' ) }
				</Button>
			</PanelBody>
			<h3>{ __( 'Recent updates', 'zinn-seo' ) }</h3>
			<Table
				label={ __( 'Recent updates', 'zinn-seo' ) }
				empty={ __( 'No address has changed yet.', 'zinn-seo' ) }
				columns={ [
					{
						id: 'change',
						label: __( 'Address', 'zinn-seo' ),
						render: ( r ) => (
							<span dir="ltr">
								{ r.old } <span aria-hidden="true">→</span>
								<span className="screen-reader-text">
									{ __( 'to', 'zinn-seo' ) }
								</span>{ ' ' }
								{ r.new }
							</span>
						),
					},
					{
						id: 'done',
						label: __( 'Updated', 'zinn-seo' ),
						render: ( r ) =>
							sprintf(
								/* translators: 1: links, 2: posts. */
								_n(
									'%1$d link in %2$d post',
									'%1$d links in %2$d posts',
									r.links,
									'zinn-seo'
								),
								r.links,
								r.posts
							) +
							( r.menus
								? ' · ' +
									sprintf(
										/* translators: %d: menu items. */
										_n(
											'%d menu item',
											'%d menu items',
											r.menus,
											'zinn-seo'
										),
										r.menus
									)
								: '' ),
					},
					{
						id: 'when',
						label: __( 'When', 'zinn-seo' ),
						render: ( r ) =>
							new Date( r.at * 1000 ).toLocaleString(),
					},
				] }
				rows={ ( state.data.log || [] ).map( ( r, i ) => ( {
					...r,
					id: String( i ),
				} ) ) }
			/>
		</div>
	);
}
