/**
 * Search result preview, desktop and mobile, cut by pixel width as results are (zs-006).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Button, ButtonGroup } from '@wordpress/components';

import { fitText } from './pure';

/** Widths results show, in pixels, and the fonts they use (measured from Google's result pages). */
const LAYOUT = {
	desktop: {
		title: 600,
		titleFont: '20px Arial',
		desc: 1240,
		descFont: '14px Arial',
	},
	mobile: {
		title: 540,
		titleFont: '18px Arial',
		desc: 920,
		descFont: '14px Arial',
	},
};

let canvas = null;

/**
 * Width of a text in a font.
 *
 * @param {string} font Font.
 * @return {Function} text -> pixels.
 */
function measurer( font ) {
	if ( ! canvas && typeof document !== 'undefined' ) {
		canvas = document.createElement( 'canvas' );
	}
	const ctx = canvas && canvas.getContext ? canvas.getContext( '2d' ) : null;
	if ( ! ctx ) {
		return ( t ) => t.length * 9;
	}
	return ( t ) => {
		ctx.font = font;
		return ctx.measureText( t ).width;
	};
}

/**
 * The address the way results show it: site › path › parts.
 *
 * @param {string} url Address.
 * @return {string} Shown address.
 */
export function crumbUrl( url ) {
	try {
		const u = new URL( url );
		const parts = u.pathname
			.split( '/' )
			.filter( Boolean )
			.map( ( p ) => decodeURIComponent( p ) );
		return [ u.host, ...parts ].join( ' › ' );
	} catch {
		return url;
	}
}

/**
 * The preview.
 *
 * @param {Object} props
 * @param {string} props.title    Title.
 * @param {string} props.desc     Description.
 * @param {string} props.url      Address.
 * @param {string} props.siteName Site name.
 * @param {string} props.icon     Site icon.
 * @return {Element} Preview.
 */
export default function SerpPreview( { title, desc, url, siteName, icon } ) {
	const [ device, setDevice ] = useState( 'mobile' );
	const l = LAYOUT[ device ];
	const shown = useMemo(
		() => ( {
			title: fitText( title, l.title, measurer( l.titleFont ) ),
			desc: fitText( desc, l.desc, measurer( l.descFont ) ),
		} ),
		[ title, desc, device ] // eslint-disable-line react-hooks/exhaustive-deps
	);
	return (
		<div className="zseo-serp-wrap">
			<ButtonGroup
				className="zseo-serp-switch"
				aria-label={ __( 'Preview on', 'zinn-seo' ) }
			>
				<Button
					variant={ device === 'mobile' ? 'primary' : 'secondary' }
					size="compact"
					aria-pressed={ device === 'mobile' }
					onClick={ () => setDevice( 'mobile' ) }
				>
					{ __( 'Mobile', 'zinn-seo' ) }
				</Button>
				<Button
					variant={ device === 'desktop' ? 'primary' : 'secondary' }
					size="compact"
					aria-pressed={ device === 'desktop' }
					onClick={ () => setDevice( 'desktop' ) }
				>
					{ __( 'Desktop', 'zinn-seo' ) }
				</Button>
			</ButtonGroup>
			<div className={ `zseo-serp is-${ device }` } dir="auto">
				<div className="zseo-serp__site">
					{ icon ? (
						<img className="zseo-serp__icon" src={ icon } alt="" />
					) : (
						<span
							className="zseo-serp__icon is-empty"
							aria-hidden="true"
						/>
					) }
					<span className="zseo-serp__name">
						<span className="zseo-serp__sitename">
							{ siteName }
						</span>
						<span className="zseo-serp__url" dir="ltr">
							{ crumbUrl( url ) }
						</span>
					</span>
				</div>
				<div className="zseo-serp__title">{ shown.title.text }</div>
				<div className="zseo-serp__desc">
					{ shown.desc.text ||
						__(
							'No description: the search engine picks text from the page.',
							'zinn-seo'
						) }
				</div>
			</div>
			{ ( shown.title.cut || shown.desc.cut ) && (
				<p className="zseo-an-note">
					{ shown.title.cut &&
						sprintf(
							/* translators: %s: device (Mobile or Desktop). */
							__( 'The title is cut on %s.', 'zinn-seo' ),
							device === 'mobile'
								? __( 'Mobile', 'zinn-seo' )
								: __( 'Desktop', 'zinn-seo' )
						) }{ ' ' }
					{ shown.desc.cut &&
						__(
							'The description is cut; put what matters first.',
							'zinn-seo'
						) }
				</p>
			) }
		</div>
	);
}
