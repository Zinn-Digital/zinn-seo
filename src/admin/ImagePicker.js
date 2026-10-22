import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button } from '@wordpress/components';

/**
 * Pick one image from the media library.
 *
 * @param {Object}   props
 * @param {string}   props.id       Control id.
 * @param {string}   props.label    Label.
 * @param {string}   props.help     Help text.
 * @param {number}   props.value    Attachment ID (0 for none).
 * @param {Function} props.onChange Called with the new ID (0 when removed).
 * @return {Element} The control.
 */
export default function ImagePicker( { id, label, help, value, onChange } ) {
	const [ media, setMedia ] = useState( null );

	useEffect( () => {
		if ( ! value ) {
			setMedia( null );
			return;
		}
		apiFetch( {
			path: `/wp/v2/media/${ value }?_fields=source_url,media_details`,
		} )
			.then( ( m ) =>
				setMedia( {
					url: m.source_url || '',
					width: ( m.media_details && m.media_details.width ) || 0,
					height: ( m.media_details && m.media_details.height ) || 0,
				} )
			)
			.catch( () => setMedia( null ) );
	}, [ value ] );

	const open = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: label,
			library: { type: 'image' },
			button: { text: __( 'Use this image', 'zinn-seo' ) },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const picked = frame.state().get( 'selection' ).first().toJSON();
			onChange( picked.id );
		} );
		frame.open();
	};

	return (
		<BaseControl
			__nextHasNoMarginBottom
			id={ id }
			label={ label }
			help={ help }
		>
			<div className="zseo-image-pick">
				{ media && media.url && (
					<img
						src={ media.url }
						alt=""
						className="zseo-image-pick__img"
					/>
				) }
				<div className="zseo-image-pick__actions">
					<Button id={ id } variant="secondary" onClick={ open }>
						{ value
							? __( 'Change image', 'zinn-seo' )
							: __( 'Choose image', 'zinn-seo' ) }
					</Button>
					{ !! value && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => onChange( 0 ) }
						>
							{ __( 'Remove', 'zinn-seo' ) }
						</Button>
					) }
					{ media && media.width > 0 && (
						<span className="zseo-muted">
							{ media.width } × { media.height }
						</span>
					) }
				</div>
			</div>
		</BaseControl>
	);
}
