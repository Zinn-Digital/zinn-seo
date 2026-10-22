/**
 * The model an AI feature uses (owner, 2026-10-13: "select the model from the latest versions").
 * The list is the current models of every connected own-key provider, read from the engine's
 * signed catalogue by the server (`GET /ai/models`): a model added there appears here, one it
 * retires disappears. On Zinn® credits Zinn® chooses the model for each job, and this says so.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';

import { errorText, seoFetch } from '../api';
import { useRoute } from '../p4/shared';
import { ErrorLine } from './shared';

/**
 * The select's options: the default first, the catalogue's best for this work marked.
 *
 * @param {Array} options Server options `{value, label, recommended}`.
 * @return {Array<{value: string, label: string}>} Select options.
 */
export function modelOptions( options = [] ) {
	return [
		{
			value: '',
			label: __( 'Default (your AI settings)', 'zinn-seo' ),
		},
		...options.map( ( o ) => ( {
			value: o.value,
			label: o.recommended
				? o.label + ' ' + __( '(recommended)', 'zinn-seo' )
				: o.label,
		} ) ),
	];
}

/**
 * The picker.
 *
 * @param {Object}   props
 * @param {string}   props.feature  writer | proofread | image.
 * @param {string}   props.value    For a per-run picker: the chosen value (controlled).
 * @param {Function} props.onChange For a per-run picker: called with the value. Without it the
 *                                  choice is saved as the feature's own.
 * @return {Element|null} Picker.
 */
export default function ModelPicker( { feature, value, onChange } ) {
	const route = useRoute( '/ai/models?feature=' + feature );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const d = route.data;
	if ( ! d ) {
		return null;
	}
	if ( d.on_credits ) {
		return (
			<p className="zseo-muted">
				{ __(
					'On Zinn® credits the model is chosen for you for each job. Add your own AI key in the AI settings to choose the model yourself.',
					'zinn-seo'
				) }
			</p>
		);
	}
	if ( ! d.options || ! d.options.length ) {
		return null;
	}
	const save = ( choice ) => {
		setBusy( true );
		setError( '' );
		seoFetch( '/ai/models', {
			method: 'PUT',
			data: { feature, choice },
		} )
			.then( route.setData )
			.catch( ( e ) => setError( errorText( e ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="zseo-x1c-model">
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'AI model', 'zinn-seo' ) }
				help={ __(
					'Only the current models of your connected AI providers are listed; when a provider releases a new model it appears here and the oldest one goes.',
					'zinn-seo'
				) }
				value={ onChange ? value || '' : d.choice || '' }
				options={ modelOptions( d.options ) }
				disabled={ busy }
				onChange={ onChange || save }
			/>
			{ d.moved && d.moved.from && d.moved.to ? (
				<p className="zseo-muted">
					{ sprintf(
						/* translators: 1: the retired model, 2: the model now used. */
						__(
							'The model you chose, %1$s, has been retired by its provider, so this now uses %2$s, the newest model of the same family.',
							'zinn-seo'
						),
						d.moved.from,
						d.moved.to
					) }
				</p>
			) : null }
			<ErrorLine text={ error } />
		</div>
	);
}
