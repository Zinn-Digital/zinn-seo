import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import ContentTypesTab from './types/ContentTypesTab';
import { errorText, seoFetch, useSettings } from './api';
import TemplateInput, { SnippetPreview, variablesFor } from './TemplateInput';
import GuideLink from './p7/GuideLink';
import ImagePicker from './ImagePicker';

/**
 * The tabs, in order (exported for the command palette).
 *
 * @return {Array<{name: string, title: string}>} Tabs.
 */
export function appearanceTabs() {
	return [
		{ name: 'titles', title: __( 'Titles and descriptions', 'zinn-seo' ) },
		{ name: 'robots', title: __( 'Robots and previews', 'zinn-seo' ) },
		{ name: 'types', title: __( 'Content types', 'zinn-seo' ) },
		{ name: 'verify', title: __( 'Site verification', 'zinn-seo' ) },
	];
}

/**
 * The webmaster tools, in the order people use them: setting, name, where to get the code.
 *
 * @return {Array<{key: string, label: string, url: string}>} Tools.
 */
export function verificationTools() {
	return [
		{
			key: 'verify_google',
			label: __( 'Google Search Console', 'zinn-seo' ),
			url: 'https://search.google.com/search-console',
		},
		{
			key: 'verify_bing',
			label: __( 'Bing Webmaster Tools', 'zinn-seo' ),
			url: 'https://www.bing.com/webmasters',
		},
		{
			key: 'verify_yandex',
			label: __( 'Yandex Webmaster', 'zinn-seo' ),
			url: 'https://webmaster.yandex.com',
		},
		{
			key: 'verify_baidu',
			label: __( 'Baidu Webmaster Tools', 'zinn-seo' ),
			url: 'https://ziyuan.baidu.com',
		},
		{
			key: 'verify_pinterest',
			label: __( 'Pinterest', 'zinn-seo' ),
			url: 'https://www.pinterest.com/settings/claim',
		},
		{
			key: 'verify_naver',
			label: __( 'Naver Search Advisor', 'zinn-seo' ),
			url: 'https://searchadvisor.naver.com',
		},
	];
}

/**
 * The code inside a pasted verification tag (`<meta … content="CODE">` gives CODE). Exported for the tests.
 *
 * @param {string} value What was pasted.
 * @return {string} The code.
 */
export function verificationCode( value ) {
	const text = ( value || '' ).trim();
	const match = text.match( /content\s*=\s*["']([^"']*)["']/i );
	return ( match ? match[ 1 ] : text ).trim();
}

/**
 * The label of a context group.
 *
 * @return {Object<string, string>} Group => heading.
 */
function groupHeadings() {
	return {
		home: __( 'Home page', 'zinn-seo' ),
		post: __( 'Content types', 'zinn-seo' ),
		archive: __( 'Content type archives', 'zinn-seo' ),
		tax: __( 'Categories, tags and other groupings', 'zinn-seo' ),
		author: __( 'Other pages', 'zinn-seo' ),
	};
}

/**
 * The search engines choice of a record: '' (default), 'index' or 'noindex'.
 *
 * @param {Object}   props
 * @param {Object}   props.record   Stored record.
 * @param {Object}   props.defaults The context's defaults.
 * @param {Function} props.onChange Called with a partial record.
 * @return {Element} The controls.
 */
function RobotsChoice( { record, defaults, onChange } ) {
	const hiddenByDefault = 'noindex' === defaults.index;
	return (
		<div className="zseo-row">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'In search results', 'zinn-seo' ) }
				value={ record.index || '' }
				options={ [
					{
						value: '',
						label: hiddenByDefault
							? __( 'Hidden (recommended)', 'zinn-seo' )
							: __( 'Shown (recommended)', 'zinn-seo' ),
					},
					{ value: 'index', label: __( 'Shown', 'zinn-seo' ) },
					{ value: 'noindex', label: __( 'Hidden', 'zinn-seo' ) },
				].filter(
					( o ) =>
						! o.value ||
						o.value !== ( hiddenByDefault ? 'noindex' : 'index' )
				) }
				onChange={ ( index ) => onChange( { index } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Ask search engines not to follow links',
					'zinn-seo'
				) }
				checked={ 'nofollow' === record.follow }
				onChange={ ( on ) =>
					onChange( { follow: on ? 'nofollow' : '' } )
				}
			/>
		</div>
	);
}

/**
 * One kind of page: its templates, robots and preview.
 *
 * @param {Object}        props
 * @param {Object}        props.context   `{ key, label, group, defaults }`.
 * @param {Object}        props.record    The site's record for it (draft).
 * @param {string}        props.sep       Separator (draft).
 * @param {Array<Object>} props.variables Variables.
 * @param {Function}      props.onChange  Called with a partial record.
 * @return {Element} The panel.
 */
function ContextPanel( { context, record, sep, variables, onChange } ) {
	const [ open, setOpen ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const vars = variablesFor( variables, context.group );
	const timer = useRef( 0 );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => {
			seoFetch( '/templates/preview', {
				method: 'POST',
				data: {
					key: context.key,
					title: record.title || context.defaults.title,
					desc: record.desc || context.defaults.desc,
					sep,
				},
			} )
				.then( setPreview )
				.catch( () => setPreview( null ) );
		}, 350 );
		return () => window.clearTimeout( timer.current );
	}, [ open, record.title, record.desc, sep, context ] );

	const changed = Object.keys( record ).length > 0;
	return (
		<PanelBody
			title={ context.label }
			initialOpen={ false }
			onToggle={ ( next ) => setOpen( next ) }
			className="zseo-context"
			icon={
				changed ? (
					<span className="zseo-badge">
						{ __( 'Changed', 'zinn-seo' ) }
					</span>
				) : undefined
			}
		>
			<div className="zseo-stack">
				<TemplateInput
					label={ __( 'Title', 'zinn-seo' ) }
					value={ record.title || '' }
					placeholder={ context.defaults.title }
					variables={ vars }
					onChange={ ( title ) => onChange( { title } ) }
				/>
				<TemplateInput
					label={ __( 'Meta description', 'zinn-seo' ) }
					value={ record.desc || '' }
					placeholder={
						context.defaults.desc ||
						__( 'No description by default', 'zinn-seo' )
					}
					variables={ vars }
					multiline
					onChange={ ( desc ) => onChange( { desc } ) }
				/>
				{ preview && (
					<>
						<SnippetPreview
							title={ preview.title }
							url={ preview.url }
							desc={ preview.desc }
						/>
						{ preview.example && (
							<p className="zseo-muted">
								{ sprintf(
									/* translators: %s: the title of the post or term used for the preview. */
									__( 'Shown for: %s', 'zinn-seo' ),
									preview.example
								) }
							</p>
						) }
						{ preview.unknown && preview.unknown.length > 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %s: variable names. */
									__(
										'These variables have no value on this kind of page and print nothing: %s',
										'zinn-seo'
									),
									preview.unknown
										.map( ( n ) => `%${ n }%` )
										.join( ', ' )
								) }
							</Notice>
						) }
					</>
				) }
				<RobotsChoice
					record={ record }
					defaults={ context.defaults }
					onChange={ onChange }
				/>
				{ [ 'post', 'tax', 'archive', 'home' ].includes(
					context.group
				) && (
					<details className="zseo-details">
						<summary>
							{ __(
								'When shared on social networks',
								'zinn-seo'
							) }
						</summary>
						<div className="zseo-stack">
							<TemplateInput
								label={ __( 'Shared title', 'zinn-seo' ) }
								value={ record.og_title || '' }
								placeholder={ __(
									'Same as the title',
									'zinn-seo'
								) }
								variables={ vars }
								onChange={ ( v ) =>
									onChange( { og_title: v } )
								}
							/>
							<TemplateInput
								label={ __( 'Shared text', 'zinn-seo' ) }
								value={ record.og_desc || '' }
								placeholder={ __(
									'Same as the meta description',
									'zinn-seo'
								) }
								variables={ vars }
								multiline
								onChange={ ( v ) => onChange( { og_desc: v } ) }
							/>
							<ImagePicker
								id={ `zseo-og-image-${ context.key.replace(
									/[^a-z0-9-]/g,
									'-'
								) }` }
								label={ __( 'Default image', 'zinn-seo' ) }
								help={ __(
									'Used when a page of this kind has no image of its own.',
									'zinn-seo'
								) }
								value={ record.og_image || 0 }
								onChange={ ( v ) =>
									onChange( { og_image: v } )
								}
							/>
						</div>
					</details>
				) }
				{ changed && (
					<p>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => onChange( null ) }
						>
							{ __( 'Back to the defaults', 'zinn-seo' ) }
						</Button>
					</p>
				) }
			</div>
		</PanelBody>
	);
}

/**
 * Titles and descriptions of every kind of page.
 *
 * @param {Object}   props
 * @param {Object}   props.meta     GET /templates.
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial settings change.
 * @return {Element} The tab.
 */
function TitlesTab( { meta, value, onChange } ) {
	const templates = value.templates || {};
	const setRecord = ( key, part ) => {
		const next = { ...templates };
		if ( null === part ) {
			delete next[ key ];
		} else {
			const record = { ...( next[ key ] || {} ), ...part };
			Object.keys( record ).forEach( ( k ) => {
				if (
					record[ k ] === '' ||
					record[ k ] === 0 ||
					( Array.isArray( record[ k ] ) && ! record[ k ].length )
				) {
					delete record[ k ];
				}
			} );
			if ( Object.keys( record ).length ) {
				next[ key ] = record;
			} else {
				delete next[ key ];
			}
		}
		onChange( { templates: next } );
	};
	const headings = groupHeadings();
	const groups = [ 'home', 'post', 'archive', 'tax', 'author' ];
	const groupOf = ( c ) =>
		[ 'author', 'date', 'search', '404' ].includes( c.group )
			? 'author'
			: c.group;

	return (
		<div className="zseo-stack">
			<fieldset className="zseo-sep">
				<legend>{ __( 'Separator', 'zinn-seo' ) }</legend>
				<p className="zseo-muted">
					{ __(
						'The separator goes between the parts of a title, for example between the page title and the site title.',
						'zinn-seo'
					) }
				</p>
				<div className="zseo-sep__choices" role="radiogroup">
					{ meta.separators.map( ( s ) => (
						<Button
							key={ s }
							variant={
								value.sep === s ? 'primary' : 'secondary'
							}
							role="radio"
							aria-checked={ value.sep === s }
							onClick={ () => onChange( { sep: s } ) }
						>
							{ s }
						</Button>
					) ) }
				</div>
			</fieldset>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Click a suggested title or description to edit it',
					'zinn-seo'
				) }
				help={ __(
					'An empty field shows the text your template writes. With this on, clicking it puts that text in the field, ready to change, instead of typing it again. Left unchanged, the field stays empty and keeps following the template.',
					'zinn-seo'
				) }
				checked={ !! value.placeholder_edit }
				onChange={ ( on ) => onChange( { placeholder_edit: on } ) }
			/>
			<p className="zseo-muted">
				<GuideLink
					data={ window.zinnSeoAdmin || {} }
					id="placeholder-edit"
				/>
			</p>
			{ groups.map( ( group ) => {
				const list = meta.contexts.filter(
					( c ) => groupOf( c ) === group
				);
				if ( ! list.length ) {
					return null;
				}
				return (
					<section key={ group } className="zseo-group">
						<h2 className="zseo-group__title">
							{ headings[ group ] }
						</h2>
						{ list.map( ( c ) => (
							<ContextPanel
								key={ c.key }
								context={ c }
								record={ templates[ c.key ] || {} }
								sep={ value.sep }
								variables={ meta.variables }
								onChange={ ( part ) =>
									setRecord( c.key, part )
								}
							/>
						) ) }
					</section>
				);
			} ) }
		</div>
	);
}

/**
 * Site-wide robots and preview limits, and the "other SEO plugin" switch.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @param {Object}   props.data     Boot data.
 * @return {Element} The tab.
 */
function RobotsTab( { value, onChange, data } ) {
	const others =
		( data.setup && data.setup.found && data.setup.found.seo_plugins ) ||
		[];
	const limit = ( key, label, help, unit ) => (
		<div className="zseo-limit">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				checked={ [ '-1', '', 'off' ].includes( value[ key ] ) }
				onChange={ ( on ) =>
					onChange( { [ key ]: on ? '-1' : '160' } )
				}
			/>
			{ ! [ '-1', '', 'off' ].includes( value[ key ] ) && (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					min="0"
					max="9999"
					label={ unit }
					value={ value[ key ] }
					onChange={ ( v ) =>
						onChange( {
							[ key ]: String(
								Math.max( 0, parseInt( v, 10 ) || 0 )
							),
						} )
					}
				/>
			) }
		</div>
	);

	return (
		<div className="zseo-stack">
			<PanelBody
				title={ __( 'What search engines may show', 'zinn-seo' ) }
			>
				<div className="zseo-stack">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Image previews', 'zinn-seo' ) }
						help={ __(
							'"Large" lets Google show your images large in results and in Discover. Each page can choose differently.',
							'zinn-seo'
						) }
						value={ value.robots_max_image }
						options={ [
							{
								value: 'large',
								label: __( 'Large (recommended)', 'zinn-seo' ),
							},
							{
								value: 'standard',
								label: __( 'Standard', 'zinn-seo' ),
							},
							{ value: 'none', label: __( 'None', 'zinn-seo' ) },
							{
								value: 'off',
								label: __(
									'Say nothing (not even WordPress’s own "large")',
									'zinn-seo'
								),
							},
						] }
						onChange={ ( v ) =>
							onChange( { robots_max_image: v } )
						}
					/>
					{ limit(
						'robots_max_snippet',
						__(
							'No limit on text snippets (recommended)',
							'zinn-seo'
						),
						__(
							'Off: search engines may show at most the number of characters you set.',
							'zinn-seo'
						),
						__( 'Longest snippet, in characters', 'zinn-seo' )
					) }
					{ limit(
						'robots_max_video',
						__(
							'No limit on video previews (recommended)',
							'zinn-seo'
						),
						__(
							'Off: video previews are limited to the number of seconds you set.',
							'zinn-seo'
						),
						__( 'Longest video preview, in seconds', 'zinn-seo' )
					) }
				</div>
			</PanelBody>
			<PanelBody
				title={ __( 'Descriptions and page 2 and on', 'zinn-seo' ) }
				initialOpen={ false }
			>
				<div className="zseo-stack">
					<GuideLink data={ data } id="pagination" />
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Automatic descriptions', 'zinn-seo' ) }
						help={ __(
							'How a description written from the page text is shortened. Keep "As Rank Math did" after a move from it, so no description changes.',
							'zinn-seo'
						) }
						value={ value.desc_rules || 'zinn' }
						options={ [
							{
								value: 'zinn',
								label: __(
									'Cut near 155 characters, ending with …',
									'zinn-seo'
								),
							},
							{
								value: 'rankmath',
								label: __(
									'As Rank Math did: the excerpt in full, else cut at 160 characters',
									'zinn-seo'
								),
							},
						] }
						onChange={ ( v ) => onChange( { desc_rules: v } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Page number after a description on page 2 and on',
							'zinn-seo'
						) }
						help={ sprintf(
							/* translators: %s: a template variable, %page(-n)%. */
							__(
								'For example %s adds “- Page 2”. Empty: only the title shows the page number.',
								'zinn-seo'
							),
							'%page(-n)%'
						) }
						value={ value.desc_paged || '' }
						onChange={ ( v ) => onChange( { desc_paged: v } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Keep page 2 and on of archives out of search',
							'zinn-seo'
						) }
						checked={ !! value.robots_noindex_archive_pages }
						onChange={ ( v ) =>
							onChange( { robots_noindex_archive_pages: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Ask search engines not to follow the links on page 2 and on of archives',
							'zinn-seo'
						) }
						checked={ !! value.robots_nofollow_archive_pages }
						onChange={ ( v ) =>
							onChange( { robots_nofollow_archive_pages: v } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Page 2 and on of the home page’s list of posts',
							'zinn-seo'
						) }
						value={ value.robots_noindex_home_pages || '' }
						options={ [
							{
								value: '',
								label: __( 'As other archives', 'zinn-seo' ),
							},
							{
								value: 'yes',
								label: __( 'Keep out of search', 'zinn-seo' ),
							},
							{
								value: 'no',
								label: __(
									'Let search engines show',
									'zinn-seo'
								),
							},
						] }
						onChange={ ( v ) =>
							onChange( { robots_noindex_home_pages: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Link the pages of a list to each other (rel=prev / next)',
							'zinn-seo'
						) }
						help={ __(
							'Bing and other search engines read them to find every page of a long list.',
							'zinn-seo'
						) }
						checked={ false !== value.adjacent_links }
						onChange={ ( v ) => onChange( { adjacent_links: v } ) }
					/>
					{ false !== value.adjacent_links && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'…on pages kept out of search too',
								'zinn-seo'
							) }
							checked={ !! value.adjacent_noindex }
							onChange={ ( v ) =>
								onChange( { adjacent_noindex: v } )
							}
						/>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Keep page 2 and on of posts split into pages out of search',
							'zinn-seo'
						) }
						checked={ !! value.robots_noindex_post_pages }
						onChange={ ( v ) =>
							onChange( { robots_noindex_post_pages: v } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Keep products hidden from the shop out of search',
							'zinn-seo'
						) }
						help={ __(
							'WooCommerce products whose catalogue visibility is "hidden".',
							'zinn-seo'
						) }
						checked={ !! value.robots_noindex_hidden_products }
						onChange={ ( v ) =>
							onChange( { robots_noindex_hidden_products: v } )
						}
					/>
				</div>
			</PanelBody>
			<PanelBody title={ __( 'Other SEO plugins', 'zinn-seo' ) }>
				{ others.length > 0 ? (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: names of SEO plugins. */
							__(
								'Also active on this site: %s. While it is, Zinn® SEO leaves the page head to it, so search engines never get two titles or two canonical addresses.',
								'zinn-seo'
							),
							others.map( ( p ) => p.name ).join( ', ' )
						) }
					</Notice>
				) : (
					<p className="zseo-muted">
						{ __(
							'No other SEO plugin is active. Zinn® SEO writes the page head.',
							'zinn-seo'
						) }
					</p>
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Write the page head even when another SEO plugin is active',
						'zinn-seo'
					) }
					help={ __(
						'Only for testing side by side: both plugins then print their tags.',
						'zinn-seo'
					) }
					checked={ !! value.run_with_others }
					onChange={ ( on ) => onChange( { run_with_others: on } ) }
				/>
			</PanelBody>
		</div>
	);
}

/**
 * Webmaster verification codes.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The tab.
 */
function VerifyTab( { value, onChange } ) {
	return (
		<PanelBody title={ __( 'Prove you own this site', 'zinn-seo' ) }>
			<p className="zseo-muted">
				{ __(
					'Paste the code (or the whole meta tag) each service gives you. The tags are added to your home page only.',
					'zinn-seo'
				) }
			</p>
			<div className="zseo-stack">
				{ verificationTools().map( ( tool ) => (
					<div key={ tool.key }>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ tool.label }
							value={ value[ tool.key ] || '' }
							onChange={ ( v ) =>
								onChange( {
									[ tool.key ]: verificationCode( v ),
								} )
							}
						/>
						<ExternalLink href={ tool.url }>
							{ sprintf(
								/* translators: %s: a service's name. */
								__( 'Open %s', 'zinn-seo' ),
								tool.label
							) }
						</ExternalLink>
					</div>
				) ) }
			</div>
		</PanelBody>
	);
}

/**
 * The Search appearance screen.
 *
 * @param {Object} props
 * @param {Object} props.data Boot data.
 * @return {Element} The screen.
 */
export default function AppearanceScreen( { data } ) {
	const { settings, error, save } = useSettings();
	const [ meta, setMeta ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ loadError, setLoadError ] = useState( '' );
	const [ shown, setShown ] = useState(
		( window.location.hash || '' ).replace( '#/', '' )
	);

	useEffect( () => {
		seoFetch( '/templates' )
			.then( setMeta )
			.catch( ( e ) => setLoadError( errorText( e ) ) );
	}, [] );

	if ( error || loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error || loadError }
			</Notice>
		);
	}
	if ( ! settings || ! meta ) {
		return <Spinner />;
	}
	const value = { ...settings, ...draft };
	const change = ( part ) => {
		setDraft( { ...draft, ...part } );
		setNotice( null );
	};
	const dirty = Object.keys( draft ).length > 0;
	const store = () => {
		setBusy( true );
		save( draft )
			.then( () => {
				setDraft( {} );
				setNotice( {
					status: 'success',
					text: __( 'Saved.', 'zinn-seo' ),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const initial = ( window.location.hash || '' ).replace( '#/', '' );
	// Content types saves each switch at once: no "Save changes" bar there.
	const savebar = 'types' !== shown;

	return (
		<div className="zseo-admin">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<TabPanel
				className="zseo-tabs"
				tabs={ appearanceTabs() }
				initialTabName={
					appearanceTabs().some( ( t ) => t.name === initial )
						? initial
						: 'titles'
				}
				onSelect={ ( name ) => {
					setShown( name );
					window.history.replaceState( null, '', `#/${ name }` );
				} }
			>
				{ ( tab ) => {
					if ( 'robots' === tab.name ) {
						return (
							<RobotsTab
								value={ value }
								onChange={ change }
								data={ data }
							/>
						);
					}
					if ( 'types' === tab.name ) {
						return <ContentTypesTab data={ data } />;
					}
					if ( 'verify' === tab.name ) {
						return (
							<VerifyTab value={ value } onChange={ change } />
						);
					}
					return (
						<TitlesTab
							meta={ meta }
							value={ value }
							onChange={ change }
						/>
					);
				} }
			</TabPanel>
			{ savebar && (
				<div className="zseo-savebar">
					<span className="zseo-muted" aria-live="polite">
						{ dirty
							? __( 'You have unsaved changes.', 'zinn-seo' )
							: '' }
					</span>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! dirty }
						onClick={ store }
					>
						{ __( 'Save changes', 'zinn-seo' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
