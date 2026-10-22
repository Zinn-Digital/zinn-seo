/**
 * Click a suggested title or description to edit it (zs-757): an empty field whose placeholder
 * is the generated text gets that text as its value when it is focused, ready to change. Left
 * unchanged, it is emptied again on the way out, so the page keeps following its template.
 * Pure, shared by the classic, term and profile screens and the block editor panel.
 */

/**
 * The value a field takes when it is focused.
 *
 * @param {string}  value       What the field holds.
 * @param {string}  placeholder The suggested text.
 * @param {boolean} on          Whether click-to-edit is switched on.
 * @return {string|null} The text to put in, or null to leave the field alone.
 */
export function fillOnFocus( value, placeholder, on ) {
	if ( ! on || ( value || '' ) !== '' ) {
		return null;
	}
	const text = ( placeholder || '' ).trim();
	return text ? text : null;
}

/**
 * The value a field takes when it loses focus.
 *
 * @param {string}      value  What the field holds now.
 * @param {string|null} filled The text put in on focus (null when nothing was).
 * @return {string|null} '' to empty it again (unchanged suggestion), or null to keep it.
 */
export function revertOnBlur( value, filled ) {
	return filled !== null && filled !== undefined && value === filled
		? ''
		: null;
}
