/* Generated from wp/packages/zinn-admin-kit/src/js/Tour.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Popover } from '@wordpress/components';

/**
 * The step to show, skipping steps whose target is not on the screen. Exported for the tests.
 *
 * @param {Array<Object>} steps   Steps: `target` (a CSS selector, optional), `title`, `content`.
 * @param {number}        from    Index to start from.
 * @param {number}        dir     +1 forward, -1 back.
 * @param {Function}      present Selector -> is it on the screen?
 * @return {number} The index to show, or -1 when there is none that way.
 */
export function nextStep( steps, from, dir, present ) {
	for ( let i = from; i >= 0 && i < steps.length; i += dir ) {
		if ( ! steps[ i ].target || present( steps[ i ].target ) ) {
			return i;
		}
	}
	return -1;
}

/**
 * A short guided tour of one screen (feature adm-3). Built in, on purpose: the house rule is ZDS
 * guided tours and never Shepherd.js (AGPL since v14, docs/adr/0027).
 *
 * Each step points at an element marked `data-zak-tour="<name>"`; a step whose element is not on
 * the screen is skipped rather than shown pointing at nothing. Escape or "Close" ends the tour.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.steps   Steps.
 * @param {Function}      props.onClose Called when the tour ends (finished or closed).
 * @return {Element|null} The tour.
 */
export default function Tour( { steps, onClose } ) {
	const present = ( selector ) => !! document.querySelector( selector );
	const [ index, setIndex ] = useState( () =>
		nextStep( steps, 0, 1, present )
	);
	const [ anchor, setAnchor ] = useState( null );
	const closeRef = useRef( onClose );
	closeRef.current = onClose;

	const step = index >= 0 ? steps[ index ] : null;

	useEffect( () => {
		if ( ! step ) {
			closeRef.current();
			return undefined;
		}
		const element = step.target
			? document.querySelector( step.target )
			: null;
		setAnchor( element );
		if ( element ) {
			element.classList.add( 'zak-tour-target' );
			element.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}
		const onKey = ( event ) => {
			if ( 'Escape' === event.key ) {
				closeRef.current();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => {
			document.removeEventListener( 'keydown', onKey );
			if ( element ) {
				element.classList.remove( 'zak-tour-target' );
			}
		};
	}, [ step ] );

	if ( ! step ) {
		return null;
	}

	const previous = nextStep( steps, index - 1, -1, present );
	const following = nextStep( steps, index + 1, 1, present );
	const total = steps.filter(
		( s ) => ! s.target || present( s.target )
	).length;
	const position =
		steps
			.slice( 0, index + 1 )
			.filter( ( s ) => ! s.target || present( s.target ) ).length || 1;

	const body = (
		<div
			className="zak-tour"
			role="dialog"
			aria-modal="false"
			aria-labelledby="zak-tour-title"
		>
			<p className="zak-tour__count">
				{ sprintf(
					/* translators: 1: this step, 2: number of steps. */
					__( 'Step %1$d of %2$d', 'zinn-seo' ),
					position,
					total
				) }
			</p>
			<h2 id="zak-tour-title" className="zak-tour__title">
				{ step.title }
			</h2>
			<p>{ step.content }</p>
			<div className="zak-tour__actions">
				<Button variant="tertiary" onClick={ () => onClose() }>
					{ __( 'Close the tour', 'zinn-seo' ) }
				</Button>
				{ previous >= 0 && (
					<Button
						variant="secondary"
						onClick={ () => setIndex( previous ) }
					>
						{ __( 'Back', 'zinn-seo' ) }
					</Button>
				) }
				{ following >= 0 ? (
					<Button
						variant="primary"
						onClick={ () => setIndex( following ) }
					>
						{ __( 'Next', 'zinn-seo' ) }
					</Button>
				) : (
					<Button variant="primary" onClick={ () => onClose() }>
						{ __( 'Done', 'zinn-seo' ) }
					</Button>
				) }
			</div>
		</div>
	);

	if ( ! anchor ) {
		return <div className="zak-tour-float">{ body }</div>;
	}

	return (
		<Popover
			anchor={ anchor }
			placement="bottom-start"
			focusOnMount="firstElement"
			className="zak-tour-popover"
			noArrow={ false }
		>
			{ body }
		</Popover>
	);
}
