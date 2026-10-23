/**
 * Conditional redirects (zs-734, Pro): the conditions a rule answers under.
 */
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, TextControl } from '@wordpress/components';

import { seoFetch } from '../api';
import { viewHref } from '../../admin-kit/router';
import GuideAnchor from './GuideAnchor';

/**
 * Condition types with their words and what their value is.
 *
 * @return {Object<string, Object>} Type => {label, value, ops}.
 */
export function conditionTypes() {
	return {
		login: { label: __( 'Logged in or not', 'zinn-seo' ), choices: true },
		role: { label: __( 'Role', 'zinn-seo' ), hint: 'editor' },
		capability: { label: __( 'Can do', 'zinn-seo' ), hint: 'edit_posts' },
		agent: {
			label: __( 'Browser or device', 'zinn-seo' ),
			hint: __( 'mobile, bot, or words in the browser name', 'zinn-seo' ),
			ops: true,
		},
		referrer: {
			label: __( 'Came from', 'zinn-seo' ),
			hint: 'news.example.com',
			ops: true,
		},
		cookie: {
			label: __( 'Has a cookie', 'zinn-seo' ),
			hint: 'campaign=autumn',
		},
		header: {
			label: __( 'Sends a header', 'zinn-seo' ),
			hint: 'X-Partner: acme',
		},
		ip: {
			label: __( 'IP address or range', 'zinn-seo' ),
			hint: '203.0.113.0/24',
		},
		language: { label: __( 'Language', 'zinn-seo' ), hint: 'fr, de' },
		country: {
			label: __( 'Country the visitor is in', 'zinn-seo' ),
			hint: 'DE, AT, CH',
		},
		filter: {
			label: __( 'Your own check (a filter)', 'zinn-seo' ),
			hint: 'members_only',
		},
		notfound: {
			label: __( 'Only when the page does not exist', 'zinn-seo' ),
			none: true,
		},
	};
}

/**
 * Load a rule's conditions.
 *
 * @param {number} id Rule.
 * @return {Promise<Object>} Conditions.
 */
export function loadConditions( id ) {
	return id
		? seoFetch( `/redirects/${ id }/conditions` )
		: Promise.resolve( { match: 'all', rules: [] } );
}

/**
 * Store a rule's conditions.
 *
 * @param {number} id    Rule.
 * @param {Object} conds Conditions.
 * @return {Promise<Object>} Stored.
 */
export function saveConditions( id, conds ) {
	return seoFetch( `/redirects/${ id }/conditions`, {
		method: 'PUT',
		data: conds,
	} );
}

/**
 * What a country condition does, and Google's advice against it (zs-781, owner: "Build it, safely").
 *
 * @param {Object}  props
 * @param {boolean} props.known The host tells WordPress the visitor's country.
 * @return {Element} Notice.
 */
export function CountryNotice( { known } ) {
	return (
		<div className="notice notice-warning inline zseo-country-notice">
			<p>
				{ __(
					"Google advises against sending visitors to another page because of where they are: search engines crawl mostly from the United States and would not see your other countries' pages. Show a banner that suggests the other page, or use one page per language with hreflang, when you can.",
					'zinn-seo'
				) }
			</p>
			<p>
				{ __(
					"If you use it: search engines (checked by their address) are never redirected by country; a visitor's own choice is kept (link to any page with ?zseo_country=de, or ?zseo_country=auto to forget it); and pages under this rule are never kept by page caches, so each visitor gets the right answer.",
					'zinn-seo'
				) }
			</p>
			{ ! known && (
				<p>
					<strong>
						{ __(
							"Your host does not tell WordPress the visitor's country yet (for example Cloudflare's IP geolocation, or a GeoIP header). Until it does, this condition is never met and every visitor sees the page as usual.",
							'zinn-seo'
						) }
					</strong>
				</p>
			) }
		</div>
	);
}

/**
 * The editor.
 *
 * @param {Object}   props
 * @param {Object}   props.value    {match, rules}.
 * @param {Function} props.onChange Called with the new value.
 * @param {string}   props.guide    The guide's address ('' for none).
 * @return {Element} Fieldset.
 */
export default function ConditionsEditor( { value, onChange, guide } ) {
	const boot = window.zinnSeoAdmin || {};
	if ( ! boot.redirectConditions ) {
		return (
			<p className="zseo-muted">
				{ __(
					'Pro: send only some visitors elsewhere (members, phones, a campaign, a language) or only when a page no longer exists.',
					'zinn-seo'
				) }{ ' ' }
				<a href={ viewHref( 'plans' ) }>
					{ __( 'See the Pro plans', 'zinn-seo' ) }
				</a>
			</p>
		);
	}
	const types = conditionTypes();
	const v = value || { match: 'all', rules: [] };
	const set = ( i, part ) =>
		onChange( {
			...v,
			rules: v.rules.map( ( r, n ) =>
				n === i ? { ...r, ...part } : r
			),
		} );
	return (
		<fieldset className="zseo-x1a-conditions">
			<legend>
				{ __( 'Only for some visitors (optional)', 'zinn-seo' ) }
			</legend>
			{ guide && (
				<p className="zseo-muted">
					<GuideAnchor href={ guide } />
				</p>
			) }
			{ v.rules.length > 1 && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Redirect when', 'zinn-seo' ) }
					value={ v.match }
					options={ [
						{
							value: 'all',
							label: __( 'every condition holds', 'zinn-seo' ),
						},
						{
							value: 'any',
							label: __( 'any condition holds', 'zinn-seo' ),
						},
					] }
					onChange={ ( m ) => onChange( { ...v, match: m } ) }
				/>
			) }
			{ v.rules.map( ( r, i ) => {
				const t = types[ r.type ] || {};
				return (
					<div className="zseo-row zseo-row--top" key={ i }>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Condition', 'zinn-seo' ) }
							value={ r.type }
							options={ Object.keys( types ).map( ( k ) => ( {
								value: k,
								label: types[ k ].label,
							} ) ) }
							onChange={ ( type ) =>
								set( i, { type, value: '', op: 'is' } )
							}
						/>
						{ ! t.none && ! t.choices && (
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Test', 'zinn-seo' ) }
								value={ r.op || 'is' }
								options={ [
									{
										value: 'is',
										label: __( 'is', 'zinn-seo' ),
									},
									{
										value: 'not',
										label: __( 'is not', 'zinn-seo' ),
									},
									...( t.ops
										? [
												{
													value: 'contains',
													label: __(
														'contains',
														'zinn-seo'
													),
												},
												{
													value: 'regex',
													label: __(
														'matches the pattern',
														'zinn-seo'
													),
												},
											]
										: [] ),
								] }
								onChange={ ( op ) => set( i, { op } ) }
							/>
						) }
						{ t.choices && (
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Visitor', 'zinn-seo' ) }
								value={ r.value || 'in' }
								options={ [
									{
										value: 'in',
										label: __( 'logged in', 'zinn-seo' ),
									},
									{
										value: 'out',
										label: __(
											'not logged in',
											'zinn-seo'
										),
									},
								] }
								onChange={ ( val ) => set( i, { value: val } ) }
							/>
						) }
						{ ! t.none && ! t.choices && (
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Value', 'zinn-seo' ) }
								placeholder={ t.hint || '' }
								value={ r.value || '' }
								onChange={ ( val ) => set( i, { value: val } ) }
							/>
						) }
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								onChange( {
									...v,
									rules: v.rules.filter(
										( x, n ) => n !== i
									),
								} )
							}
						>
							{ __( 'Remove', 'zinn-seo' ) }
						</Button>
					</div>
				);
			} ) }
			<Button
				variant="secondary"
				onClick={ () =>
					onChange( {
						...v,
						rules: [
							...v.rules,
							{ type: 'login', op: 'is', value: 'in' },
						],
					} )
				}
			>
				{ __( 'Add a condition', 'zinn-seo' ) }
			</Button>
			{ v.rules.some( ( r ) => r.type === 'country' ) && (
				<CountryNotice known={ !! boot.countryKnown } />
			) }
			<p className="zseo-muted">
				{ __(
					'With conditions, the redirect is answered by WordPress itself (never by your server or Cloudflare), and visitors who do not match see the page as usual.',
					'zinn-seo'
				) }
			</p>
		</fieldset>
	);
}
