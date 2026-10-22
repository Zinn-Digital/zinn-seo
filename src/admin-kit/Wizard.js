/* Generated from wp/packages/zinn-admin-kit/src/js/Wizard.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';

/**
 * The first-run setup wizard (feature adm-2). The kit supplies the welcome and the finish; the
 * host plugin supplies the steps that actually configure it (`{ id, title, render }`), each of
 * which is one of its own screens, so a setting changed here is the same setting as on its screen.
 * A step may also carry `done` (the wizard then resumes at the first step not done), `onNext` and
 * `onSkip` (each returns a promise, run before the wizard moves on; `onSkip` adds a "Skip this
 * step" button).
 *
 * @param {Object}        props
 * @param {Object}        props.kit      Boot data.
 * @param {Array<Object>} props.steps    Host steps.
 * @param {Function}      props.onDone   Mark setup finished.
 * @param {Function}      props.onSkip   Mark setup skipped.
 * @param {Function}      props.navigate Go to a route.
 * @return {Element} The wizard.
 */
/**
 * @param {number} i     A step's position.
 * @param {number} index The current step's position.
 * @return {string} Its class.
 */
function stepClass( i, index ) {
	if ( i === index ) {
		return 'is-current';
	}
	return i < index ? 'is-done' : '';
}

/**
 * Where to open the wizard: at the first host step that is not `done` once any of them is, so a
 * setup left half-way continues where it stopped; at the welcome otherwise.
 *
 * @param {Array<Object>} steps Host steps (`done` is optional).
 * @return {number} The index in welcome + steps + finish.
 */
export function resumeAt( steps = [] ) {
	if ( ! steps.some( ( s ) => s.done ) ) {
		return 0;
	}
	const open = steps.findIndex( ( s ) => ! s.done );
	return -1 === open ? steps.length + 1 : open + 1;
}

export default function Wizard( {
	kit,
	steps = [],
	onDone,
	onSkip,
	navigate,
} ) {
	const all = [
		{
			id: 'welcome',
			title: __( 'Welcome', 'zinn-seo' ),
			render: () => (
				<p>
					{ sprintf(
						/* translators: %s: plugin name. */
						__(
							'This takes a few minutes and sets up %s so it works on this site straight away. You can change everything later.',
							'zinn-seo'
						),
						kit.name
					) }
				</p>
			),
		},
		...steps,
		{
			id: 'finish',
			title: __( 'Done', 'zinn-seo' ),
			render: () => (
				<>
					<p>
						{ __(
							'You are ready. If anything does not work as expected, we are one click away.',
							'zinn-seo'
						) }
					</p>
					<p>
						<Button
							variant="link"
							onClick={ () => navigate( 'help' ) }
						>
							{ __( 'Get help', 'zinn-seo' ) }
						</Button>
					</p>
				</>
			),
		},
	];
	const [ index, setIndex ] = useState( () => resumeAt( steps ) );
	const [ busy, setBusy ] = useState( false );
	const step = all[ index ];
	const last = index === all.length - 1;

	// A host step may save itself before the wizard moves on (`onNext`, `onSkip`: return a
	// promise; a rejection keeps the person on the step, where the host shows why).
	const advance = ( handler ) => {
		if ( ! handler ) {
			setIndex( index + 1 );
			return;
		}
		setBusy( true );
		Promise.resolve()
			.then( handler )
			.then( () => setIndex( index + 1 ) )
			.catch( () => {} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="zak-card zak-wizard">
			<CardHeader>
				<h2 className="zak-card__title">
					{ sprintf(
						/* translators: 1: step number, 2: number of steps, 3: step title. */
						__( 'Step %1$d of %2$d: %3$s', 'zinn-seo' ),
						index + 1,
						all.length,
						step.title
					) }
				</h2>
			</CardHeader>
			<CardBody>
				<ol className="zak-steps" aria-hidden="true">
					{ all.map( ( s, i ) => (
						<li key={ s.id } className={ stepClass( i, index ) }>
							{ s.title }
						</li>
					) ) }
				</ol>
				<div className="zak-wizard__body">{ step.render() }</div>
				<p className="zak-actions">
					{ index > 0 && (
						<Button
							variant="secondary"
							onClick={ () => setIndex( index - 1 ) }
						>
							{ __( 'Back', 'zinn-seo' ) }
						</Button>
					) }
					{ last ? (
						<Button variant="primary" onClick={ onDone }>
							{ __( 'Finish', 'zinn-seo' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => advance( step.onNext ) }
						>
							{ __( 'Next', 'zinn-seo' ) }
						</Button>
					) }
					{ ! last && step.onSkip && (
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () => advance( step.onSkip ) }
						>
							{ __( 'Skip this step', 'zinn-seo' ) }
						</Button>
					) }
					{ ! last && (
						<Button variant="tertiary" onClick={ onSkip }>
							{ __( 'Skip setup', 'zinn-seo' ) }
						</Button>
					) }
				</p>
			</CardBody>
		</Card>
	);
}
