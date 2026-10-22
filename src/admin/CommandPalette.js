import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

import { errorText, seoFetch } from './api';

/**
 * Does a command match what was typed? Every word typed must appear in its label or keywords.
 *
 * @param {Object} command `{ label, keywords }`.
 * @param {string} query   What was typed.
 * @return {boolean} Match.
 */
export function matches( command, query ) {
	const haystack =
		`${ command.label } ${ command.keywords || '' }`.toLowerCase();
	return query
		.toLowerCase()
		.split( /\s+/ )
		.filter( Boolean )
		.every( ( word ) => haystack.includes( word ) );
}

/**
 * Is this key press the palette's shortcut (Ctrl+K, or Cmd+K on a Mac)?
 *
 * @param {KeyboardEvent} event Event.
 * @return {boolean} Shortcut.
 */
export function isShortcut( event ) {
	return (
		!! ( event.ctrlKey || event.metaKey ) &&
		! event.altKey &&
		'k' === String( event.key ).toLowerCase()
	);
}

/**
 * The robots choices a page can carry.
 *
 * @return {Array<{value: string, label: string}>} Choices.
 */
function robotsChoices() {
	return [
		{
			value: 'noindex',
			label: __( 'Keep out of search results (noindex)', 'zinn-seo' ),
		},
		{
			value: 'nofollow',
			label: __( 'Do not follow its links (nofollow)', 'zinn-seo' ),
		},
		{
			value: 'noarchive',
			label: __( 'No cached copy (noarchive)', 'zinn-seo' ),
		},
		{
			value: 'nosnippet',
			label: __( 'No text snippet (nosnippet)', 'zinn-seo' ),
		},
		{
			value: 'noimageindex',
			label: __( 'Do not index its images (noimageindex)', 'zinn-seo' ),
		},
		{
			value: 'nocache',
			label: __(
				'Bing Copilot uses only the title, link and snippet (nocache)',
				'zinn-seo'
			),
		},
	];
}

/**
 * Edit one post's SEO fields (the same fields the REST API and WP-CLI read and write).
 *
 * @param {Object}   props
 * @param {Object}   props.post    `{ id, title, url }`.
 * @param {Function} props.onClose Close.
 * @return {Element} The dialog.
 */
export function FieldsModal( { post, onClose } ) {
	const [ fields, setFields ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		seoFetch( `/fields/post/${ post.id }` )
			.then( setFields )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [ post.id ] );

	const save = () => {
		setBusy( true );
		setNotice( null );
		seoFetch( `/fields/post/${ post.id }`, {
			method: 'PUT',
			data: { fields },
		} )
			.then( ( result ) => {
				setFields( result );
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

	const robots = ( fields && fields.robots ) || [];

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: a post title. */
				__( 'SEO of “%s”', 'zinn-seo' ),
				post.title
			) }
			onRequestClose={ onClose }
			// A form: a click or a focus change outside it must never throw away what was typed
			// (WordPress 6.4's Modal closed it when the palette handed focus back, 2026-10-08).
			shouldCloseOnClickOutside={ false }
			className="zseo-fields-modal"
		>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			{ ! fields && ! notice && <Spinner /> }
			{ fields && (
				<div className="zseo-stack">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'SEO title', 'zinn-seo' ) }
						help={ __(
							'Leave empty to use the template.',
							'zinn-seo'
						) }
						value={ fields.title || '' }
						onChange={ ( title ) =>
							setFields( { ...fields, title } )
						}
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Meta description', 'zinn-seo' ) }
						help={ __(
							'Leave empty to use the template.',
							'zinn-seo'
						) }
						value={ fields.desc || '' }
						onChange={ ( desc ) =>
							setFields( { ...fields, desc } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="url"
						label={ __( 'Canonical address', 'zinn-seo' ) }
						help={ __(
							"Leave empty for this page's own address.",
							'zinn-seo'
						) }
						value={ fields.canonical || '' }
						onChange={ ( canonical ) =>
							setFields( { ...fields, canonical } )
						}
					/>
					<fieldset className="zak-fieldset">
						<legend>{ __( 'Robots', 'zinn-seo' ) }</legend>
						{ robotsChoices().map( ( choice ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ choice.value }
								label={ choice.label }
								checked={ robots.includes( choice.value ) }
								onChange={ ( on ) =>
									setFields( {
										...fields,
										robots: on
											? [ ...robots, choice.value ]
											: robots.filter(
													( r ) => r !== choice.value
												),
									} )
								}
							/>
						) ) }
					</fieldset>
					<p className="zak-actions">
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ save }
						>
							{ __( 'Save', 'zinn-seo' ) }
						</Button>
						{ post.url && (
							<a
								className="components-button is-tertiary"
								href={ post.url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'View the page', 'zinn-seo' ) }
							</a>
						) }
					</p>
				</div>
			) }
		</Modal>
	);
}

/**
 * The command palette (zs-243): Ctrl+K / Cmd+K on the plugin's screen opens it; type to jump to
 * any screen or settings tab, or to find a post or page and edit its SEO.
 *
 * @param {Object} props
 * @param {Array}  props.commands `{ id, label, keywords, run }` for screens and tabs.
 * @return {Element|null} The palette.
 */
export default function CommandPalette( { commands } ) {
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ posts, setPosts ] = useState( [] );
	const [ searching, setSearching ] = useState( false );
	const [ editing, setEditing ] = useState( null );
	const [ active, setActive ] = useState( 0 );

	useEffect( () => {
		const onKey = ( event ) => {
			if ( isShortcut( event ) ) {
				event.preventDefault();
				setOpen( true );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [] );

	useEffect( () => {
		if ( ! open || query.trim().length < 2 ) {
			setPosts( [] );
			return undefined;
		}
		setSearching( true );
		const timer = window.setTimeout( () => {
			apiFetch( {
				path: `/wp/v2/search?type=post&subtype=any&per_page=8&search=${ encodeURIComponent( query.trim() ) }`,
			} )
				.then( ( rows ) =>
					setPosts(
						rows.map( ( r ) => ( {
							id: r.id,
							title: r.title,
							url: r.url,
						} ) )
					)
				)
				.catch( () => setPosts( [] ) )
				.finally( () => setSearching( false ) );
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ open, query ] );

	const found = useMemo(
		() => commands.filter( ( c ) => matches( c, query ) ),
		[ commands, query ]
	);
	const items = [
		...found.map( ( c ) => ( {
			key: `c-${ c.id }`,
			label: c.label,
			run: c.run,
		} ) ),
		...posts.map( ( p ) => ( {
			key: `p-${ p.id }`,
			label: sprintf(
				/* translators: %s: a post title. */
				__( 'Edit the SEO of “%s”', 'zinn-seo' ),
				p.title
			),
			run: () => setEditing( p ),
		} ) ),
	];

	const close = () => {
		setOpen( false );
		setQuery( '' );
		setActive( 0 );
	};
	const choose = ( item ) => {
		close();
		// After the palette has gone, so the next dialog does not open while focus is moving.
		window.setTimeout( item.run, 0 );
	};

	return (
		<>
			{ open && (
				<Modal
					title={ __( 'Go to…', 'zinn-seo' ) }
					onRequestClose={ close }
					className="zseo-palette"
				>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Search screens, settings and posts',
							'zinn-seo'
						) }
						hideLabelFromVision
						placeholder={ __(
							'Type a setting, a screen or a post title',
							'zinn-seo'
						) }
						value={ query }
						onChange={ ( q ) => {
							setQuery( q );
							setActive( 0 );
						} }
						onKeyDown={ ( event ) => {
							if ( 'ArrowDown' === event.key ) {
								event.preventDefault();
								setActive(
									Math.min( active + 1, items.length - 1 )
								);
							} else if ( 'ArrowUp' === event.key ) {
								event.preventDefault();
								setActive( Math.max( active - 1, 0 ) );
							} else if (
								'Enter' === event.key &&
								items[ active ]
							) {
								event.preventDefault();
								choose( items[ active ] );
							}
						} }
						// eslint-disable-next-line jsx-a11y/no-autofocus -- a palette exists to be typed into the moment it opens.
						autoFocus
					/>
					<ul
						className="zseo-palette__list"
						role="listbox"
						aria-label={ __( 'Results', 'zinn-seo' ) }
					>
						{ items.map( ( item, i ) => (
							<li
								key={ item.key }
								role="option"
								aria-selected={ i === active }
							>
								<Button
									className={
										i === active ? 'is-active' : ''
									}
									variant="tertiary"
									onClick={ () => choose( item ) }
								>
									{ item.label }
								</Button>
							</li>
						) ) }
					</ul>
					{ searching && <Spinner /> }
					{ ! searching && items.length === 0 && (
						<p className="zseo-muted">
							{ __( 'Nothing matches.', 'zinn-seo' ) }
						</p>
					) }
					<p className="zseo-muted zseo-palette__hint">
						{ __(
							'Ctrl+K (Cmd+K on a Mac) opens this from anywhere on the Zinn® SEO screen.',
							'zinn-seo'
						) }
					</p>
				</Modal>
			) }
			{ editing && (
				<FieldsModal
					post={ editing }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</>
	);
}
