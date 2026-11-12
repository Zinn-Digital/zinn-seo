/**
 * MAILQUOTA (owner ruling 2026-11-11): the address's inbox is FULL.
 *
 * Zinn® never refuses a full inbox: the account is made and the site owner is told, because the
 * e-mail with the set-password link may bounce. They clear the inbox and carry on waiting, or use
 * a different address (which cancels this request and goes back to the form).
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

/**
 * A translated sentence with each value bidi-isolated in a `<bdi>`.
 *
 * In Arabic and other right-to-left languages an e-mail address dropped into the sentence as
 * plain text is reordered by the bidi algorithm when it starts with a number: "1111@example.com"
 * shows as "example.com@1111" (rendered on 0.17.89). The translated string is unchanged: each value is swapped for a marker,
 * the sentence is formatted, and the markers become `<bdi>` elements, so a translation that
 * reorders `%1$s` / `%2$s` still puts each value in its place.
 *
 * @param {string}    template A translated sprintf template (`%s` or `%1$s`, `%2$s`).
 * @param {...string} values   The values, in argument order.
 * @return {Array} Strings and `<bdi>` elements, ready to render.
 */
export function withIsolated( template, ...values ) {
	const filled = sprintf(
		template,
		...values.map( ( _, i ) => `\uE000${ i }\uE000` )
	);
	return filled
		.split( /\uE000(\d+)\uE000/ )
		.map( ( part, i ) =>
			i % 2 ? <bdi key={ i }>{ values[ Number( part ) ] }</bdi> : part
		);
}

/**
 * @param {Object}   props
 * @param {Object}   props.warning      `{ code: 'mailbox_full', provider }` from the connection.
 * @param {string}   props.email        The address the account was made for.
 * @param {Function} props.onUseAnother Cancel this request and show the form again.
 */
export default function MailboxFull( { warning, email, onUseAnother } ) {
	if ( ! warning || 'mailbox_full' !== warning.code ) {
		return null;
	}
	const text = warning.provider
		? withIsolated(
				/* translators: 1: a mailbox provider such as Gmail, 2: an e-mail address. */
				__(
					'Your %1$s inbox for %2$s is full, so the e-mail with your link may not arrive. Make some space in it, then wait here, or use a different e-mail address.',
					'zinn-seo'
				),
				warning.provider,
				email
			)
		: withIsolated(
				/* translators: %s: an e-mail address. */
				__(
					'The inbox for %s is full, so the e-mail with your link may not arrive. Make some space in it, then wait here, or use a different e-mail address.',
					'zinn-seo'
				),
				email
			);

	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="zseo-mailbox-full"
		>
			<p>{ text }</p>
			<Button variant="secondary" onClick={ onUseAnother }>
				{ __( 'Use a different e-mail address', 'zinn-seo' ) }
			</Button>
		</Notice>
	);
}
