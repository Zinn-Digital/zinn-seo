import { __, sprintf } from '@wordpress/i18n';

/**
 * The words for a rich result's status in Google Search.
 *
 * @param {string} status active, limited, retired or none.
 * @return {string} Label.
 */
export function statusLabel( status ) {
	switch ( status ) {
		case 'active':
			return __( 'Shown by Google', 'zinn-seo' );
		case 'limited':
			return __( 'Shown by Google in some cases', 'zinn-seo' );
		case 'retired':
			return __( 'No longer shown by Google', 'zinn-seo' );
		default:
			return __( 'Read by search engines and AI', 'zinn-seo' );
	}
}

/**
 * The validator's answer: one card per node with its rich result, problems and advice.
 *
 * @param {Object} props
 * @param {Object} props.result The validator's result.
 * @return {Element} Results.
 */
export default function Results( { result } ) {
	if ( ! result ) {
		return null;
	}
	return (
		<div className="zseo-check">
			<p
				className={ `zseo-check__summary ${
					result.errors ? 'is-bad' : 'is-good'
				}` }
				role="status"
			>
				{ result.errors
					? sprintf(
							/* translators: 1: problems, 2: suggestions. */
							__(
								'%1$d problems to fix and %2$d suggestions.',
								'zinn-seo'
							),
							result.errors,
							result.warnings
						)
					: sprintf(
							/* translators: %d: suggestions. */
							__(
								'No problems. %d suggestions to make it richer.',
								'zinn-seo'
							),
							result.warnings
						) }
			</p>
			<ul className="zseo-check__nodes">
				{ result.nodes.map( ( n, i ) => (
					<li key={ i } className="zseo-check__node">
						<div className="zseo-check__head">
							<strong dir="ltr">{ n.type }</strong>
							{ n.name && (
								<span className="zseo-muted">{ n.name }</span>
							) }
							<span className={ `zseo-badge is-${ n.status }` }>
								{ n.feature
									? `${ n.feature }: ${ statusLabel(
											n.status
										) }`
									: statusLabel( n.status ) }
							</span>
						</div>
						{ n.note && <p className="zseo-muted">{ n.note }</p> }
						{ n.errors.length > 0 && (
							<ul className="zseo-check__errors">
								{ n.errors.map( ( e ) => (
									<li key={ e }>{ e }</li>
								) ) }
							</ul>
						) }
						{ n.warnings.length > 0 && (
							<details className="zseo-check__warnings">
								<summary>
									{ sprintf(
										/* translators: %d: suggestions. */
										__( '%d suggestions', 'zinn-seo' ),
										n.warnings.length
									) }
								</summary>
								<ul>
									{ n.warnings.map( ( w ) => (
										<li key={ w }>{ w }</li>
									) ) }
								</ul>
							</details>
						) }
					</li>
				) ) }
			</ul>
			<p className="zseo-muted">
				{ sprintf(
					/* translators: 1: a schema.org release number, 2: a date. */
					__(
						'Checked on this site, offline: schema.org %1$s and Google’s rich-result rules as of %2$s.',
						'zinn-seo'
					),
					result.vocabulary,
					result.rules
				) }
			</p>
		</div>
	);
}
