/**
 * The comment spam trap (zs-728).
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import GuideLink from '../p7/GuideLink';
import { useRoute } from '../p4/shared';
import { SaveBar } from './shared';

/**
 * The tab.
 *
 * @param {Object} props
 * @param {Object} props.form The settings draft.
 * @param {Object} props.data Boot data.
 * @return {Element} Tab.
 */
export default function CommentsTab( { form, data } ) {
	const state = useRoute( '/honeypot' );
	if ( ! state.data ) {
		return <Spinner />;
	}
	const c = state.data.caught || {};
	const total =
		( c.trap || 0 ) +
		( c.missing || 0 ) +
		( c.forged || 0 ) +
		( c.fast || 0 );
	return (
		<div className="zseo-stack">
			<p className="zseo-muted">
				{ __(
					'Spam comments fill your pages with junk links that search engines judge you by. The trap stops the bots that post them, with no captcha for your visitors and nothing for their browser to load. People who are logged in are never checked.',
					'zinn-seo'
				) }{ ' ' }
				<GuideLink data={ data } id="cleanup-comments" />
			</p>
			{ 'closed' === state.data.comments && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Comments are closed on new posts of this site; the trap still guards the posts where they are open.',
						'zinn-seo'
					) }
				</Notice>
			) }
			<PanelBody title={ __( 'Caught so far', 'zinn-seo' ) }>
				<p className="zseo-x1a-big">
					{ sprintf(
						/* translators: %d: number of spam comments. */
						__( '%d spam comments stopped', 'zinn-seo' ),
						total
					) }
				</p>
				<ul className="zseo-x1a-list">
					<li>
						{ sprintf(
							/* translators: %d: a number. */
							__( 'Filled in the hidden field: %d', 'zinn-seo' ),
							c.trap || 0
						) }
					</li>
					<li>
						{ sprintf(
							/* translators: %d: a number. */
							__(
								'Sent without loading the page: %d',
								'zinn-seo'
							),
							c.missing || 0
						) }
					</li>
					<li>
						{ sprintf(
							/* translators: %d: a number. */
							__(
								'Sent faster than a person can type: %d',
								'zinn-seo'
							),
							c.fast || 0
						) }
					</li>
					<li>
						{ sprintf(
							/* translators: %d: a number. */
							__( 'Forged: %d', 'zinn-seo' ),
							c.forged || 0
						) }
					</li>
				</ul>
			</PanelBody>
			<PanelBody title={ __( 'Settings', 'zinn-seo' ) }>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __(
						'What happens to a caught comment',
						'zinn-seo'
					) }
					value={ form.value.honeypot_action || 'spam' }
					options={ [
						{
							value: 'spam',
							label: __(
								'Goes to the spam folder (you can still rescue it)',
								'zinn-seo'
							),
						},
						{
							value: 'reject',
							label: __( 'Refused outright', 'zinn-seo' ),
						},
					] }
					onChange={ ( v ) => form.change( { honeypot_action: v } ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="number"
					min={ 0 }
					max={ 120 }
					label={ __(
						'Fewest seconds a person needs to write a comment',
						'zinn-seo'
					) }
					value={ String( form.value.honeypot_min_seconds ?? 3 ) }
					onChange={ ( v ) =>
						form.change( {
							honeypot_min_seconds: Math.min(
								120,
								Math.max( 0, parseInt( v || '0', 10 ) || 0 )
							),
						} )
					}
				/>
			</PanelBody>
			<SaveBar form={ form } />
		</div>
	);
}
