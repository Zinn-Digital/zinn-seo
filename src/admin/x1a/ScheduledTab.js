/**
 * Scheduled SEO changes (zs-707, Pro): everything still to happen on the site.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import GuideLink from '../p7/GuideLink';
import ProTeaser from '../ProTeaser';
import { Table, useRoute } from '../p4/shared';

/**
 * A field's name in words.
 *
 * @param {string} f Field.
 * @return {string} Words.
 */
export function scheduledFieldName( f ) {
	return (
		{
			title: __( 'SEO title', 'zinn-seo' ),
			desc: __( 'Meta description', 'zinn-seo' ),
			robots: __( 'Search engines', 'zinn-seo' ),
			schema_page: __( 'Page type', 'zinn-seo' ),
			schema_article: __( 'Article type', 'zinn-seo' ),
			og_title: __( 'Title when shared', 'zinn-seo' ),
			og_desc: __( 'Text when shared', 'zinn-seo' ),
		}[ f ] || f
	);
}

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function ScheduledTab( { data } ) {
	const isPro = !! data.schedule;
	const state = useRoute( isPro ? '/schedule' : '/settings' );
	const [ error, setError ] = useState( '' );
	if ( ! isPro ) {
		return (
			<ProTeaser
				title={ __( 'Scheduled SEO changes', 'zinn-seo' ) }
				points={ [
					__(
						'Set a sale title or a seasonal description to start on a date.',
						'zinn-seo'
					),
					__(
						'It changes back by itself on the date you choose.',
						'zinn-seo'
					),
					__(
						"Every step is kept in the page's SEO history.",
						'zinn-seo'
					),
				] }
			/>
		);
	}
	if ( ! state.data ) {
		return <Spinner />;
	}
	const cancel = ( r ) =>
		seoFetch( `/schedule/post/${ r.post }/${ r.id }`, { method: 'DELETE' } )
			.then( state.reload )
			.catch( ( e ) => setError( errorText( e ) ) );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Changes planned in the editor\'s "Schedule a change" box. Each starts on its date and, when you set an end, changes back by itself — unless someone edited that field in between, whose edit is kept.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="schedule" />
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<Table
				label={ __( 'Scheduled changes', 'zinn-seo' ) }
				empty={ __(
					"Nothing is scheduled. Plan a change from a post's editor.",
					'zinn-seo'
				) }
				columns={ [
					{
						id: 'post',
						label: __( 'Page', 'zinn-seo' ),
						render: ( r ) => (
							<a href={ r.edit }>
								{ r.post_title || '#' + r.post }
							</a>
						),
					},
					{
						id: 'what',
						label: __( 'Changes', 'zinn-seo' ),
						render: ( r ) =>
							Object.keys( r.fields || {} )
								.map( scheduledFieldName )
								.join( ', ' ),
					},
					{
						id: 'next',
						label: __( 'Next step', 'zinn-seo' ),
						render: ( r ) =>
							( 'waiting' === r.status
								? __( 'Starts', 'zinn-seo' )
								: __( 'Changes back', 'zinn-seo' ) ) +
							' ' +
							new Date( r.next * 1000 ).toLocaleString(),
					},
					{
						id: 'cancel',
						label: __( 'Cancel', 'zinn-seo' ),
						render: ( r ) => (
							<Button
								variant="link"
								isDestructive
								onClick={ () => cancel( r ) }
							>
								{ 'applied' === r.status
									? __( 'Change back now', 'zinn-seo' )
									: __( 'Cancel', 'zinn-seo' ) }
							</Button>
						),
					},
				] }
				rows={ state.data.items.map( ( r ) => ( { ...r, id: r.id } ) ) }
			/>
		</div>
	);
}
