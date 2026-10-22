import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	PanelBody,
	RangeControl,
	SearchControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

import { errorText, seoFetch } from '../api';

/**
 * The glossary: terms, its page, and links to it from first mentions (zs-308).
 *
 * @param {Object}   props
 * @param {Object}   props.value    Settings draft.
 * @param {Function} props.onChange Partial change.
 * @return {Element} The tab.
 */
export default function GlossaryTab( { value, onChange } ) {
	const [ state, setState ] = useState( null );
	const [ terms, setTerms ] = useState( [] );
	const [ filter, setFilter ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		seoFetch( '/glossary' )
			.then( ( r ) => {
				setState( r );
				setTerms( r.terms );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			);
	}, [] );
	if ( ! state ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.text }
			</Notice>
		) : (
			<Spinner />
		);
	}
	const set = ( i, part ) =>
		setTerms(
			terms.map( ( t, j ) => ( j === i ? { ...t, ...part } : t ) )
		);
	const save = () => {
		setBusy( true );
		seoFetch( '/glossary', { method: 'PUT', data: { terms } } )
			.then( ( r ) => {
				setTerms( r.terms );
				setNotice( {
					status: 'success',
					text: sprintf(
						/* translators: %d: number of terms. */
						__( 'Saved %d terms.', 'zinn-seo' ),
						r.terms.length
					),
				} );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const makePage = () => {
		setBusy( true );
		seoFetch( '/glossary/page', { method: 'POST' } )
			.then( ( r ) =>
				setState( { ...state, page: r.page, page_url: r.page_url } )
			)
			.catch( ( e ) =>
				setNotice( { status: 'error', text: errorText( e ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const q = filter.trim().toLowerCase();
	const shown = terms
		.map( ( t, i ) => ( { t, i } ) )
		.filter(
			( { t } ) => ! q || ( t.term || '' ).toLowerCase().includes( q )
		);

	return (
		<div className="zseo-stack">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<PanelBody title={ __( 'The glossary page', 'zinn-seo' ) }>
				{ state.page_url ? (
					<p>
						<ExternalLink href={ state.page_url }>
							{ state.page_url }
						</ExternalLink>
					</p>
				) : (
					<>
						<p className="zseo-muted">
							{ __(
								'A page listing every term A to Z, with defined-term structured data. Made for you, editable like any page.',
								'zinn-seo'
							) }
						</p>
						<Button
							variant="secondary"
							isBusy={ busy }
							onClick={ makePage }
						>
							{ __( 'Make the glossary page', 'zinn-seo' ) }
						</Button>
					</>
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Link the first mention of each term in posts',
						'zinn-seo'
					) }
					help={ __(
						'Never inside links, headings, code or buttons, and never on the glossary page itself. Saved with the settings below.',
						'zinn-seo'
					) }
					checked={ !! value.glossary_autolink }
					onChange={ ( v ) => onChange( { glossary_autolink: v } ) }
				/>
				{ value.glossary_autolink && (
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'At most this many links in a post',
							'zinn-seo'
						) }
						min={ 1 }
						max={ 50 }
						value={ value.glossary_link_max || 5 }
						onChange={ ( v ) =>
							onChange( { glossary_link_max: v } )
						}
					/>
				) }
			</PanelBody>
			<PanelBody
				title={ sprintf(
					/* translators: %d: number of terms. */
					__( 'Terms (%d)', 'zinn-seo' ),
					terms.length
				) }
			>
				{ terms.length > 8 && (
					<SearchControl
						__nextHasNoMarginBottom
						label={ __( 'Find a term', 'zinn-seo' ) }
						value={ filter }
						onChange={ setFilter }
					/>
				) }
				{ shown.map( ( { t, i } ) => (
					<fieldset key={ i } className="zseo-card-row">
						<div className="zseo-grid-2">
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Term', 'zinn-seo' ) }
								value={ t.term || '' }
								onChange={ ( v ) => set( i, { term: v } ) }
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __(
									'Other names (comma-separated)',
									'zinn-seo'
								) }
								value={ ( t.synonyms || [] ).join( ', ' ) }
								onChange={ ( v ) =>
									set( i, {
										synonyms: v
											.split( ',' )
											.map( ( s ) => s.trim() ),
									} )
								}
							/>
						</div>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Definition', 'zinn-seo' ) }
							rows={ 3 }
							value={ t.definition || '' }
							onChange={ ( v ) => set( i, { definition: v } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="url"
							label={ __(
								'Read more at (optional)',
								'zinn-seo'
							) }
							value={ t.link || '' }
							onChange={ ( v ) => set( i, { link: v } ) }
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								setTerms( terms.filter( ( x, j ) => j !== i ) )
							}
						>
							{ __( 'Remove', 'zinn-seo' ) }
						</Button>
					</fieldset>
				) ) }
				<div className="zseo-row">
					<Button
						variant="secondary"
						icon="plus"
						onClick={ () =>
							setTerms( [
								...terms,
								{
									term: '',
									definition: '',
									synonyms: [],
									link: '',
								},
							] )
						}
					>
						{ __( 'Add a term', 'zinn-seo' ) }
					</Button>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ save }
					>
						{ __( 'Save terms', 'zinn-seo' ) }
					</Button>
				</div>
			</PanelBody>
		</div>
	);
}
