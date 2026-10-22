/**
 * Site care (zs-772 … zs-776) without React: the words for every kind of finding, action,
 * severity and code-fix state, and small formatting helpers. Kept apart so they can be tested
 * without a browser.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * A finding kind in plain words (a kind we do not know yet shows as itself).
 *
 * @param {string} kind Kind.
 * @return {string} Words.
 */
export function kindName( kind ) {
	switch ( kind ) {
		case 'core_modified':
			return __( 'Changed WordPress file', 'zinn-seo' );
		case 'core_unknown':
			return __( 'Extra file among WordPress files', 'zinn-seo' );
		case 'root_unknown':
			return __( 'Unknown file in your site folder', 'zinn-seo' );
		case 'plugin_modified':
			return __( 'Changed plugin or theme file', 'zinn-seo' );
		case 'plugin_unknown':
			return __( 'Extra file in a plugin or theme', 'zinn-seo' );
		case 'signature':
			return __( 'Known malware code', 'zinn-seo' );
		case 'uploads_php':
			return __( 'Program file among your uploads', 'zinn-seo' );
		case 'php_in_image':
			return __( 'Code hidden in an image', 'zinn-seo' );
		case 'htaccess':
			return __( 'Suspicious server rule', 'zinn-seo' );
		case 'admin_hidden':
			return __( 'Hidden administrator', 'zinn-seo' );
		case 'admin_new':
			return __( 'New administrator', 'zinn-seo' );
		case 'role_powers':
			return __( 'Role with too much power', 'zinn-seo' );
		case 'user_powers':
			return __( 'Account with extra powers', 'zinn-seo' );
		case 'site_address':
			return __( 'Changed site address', 'zinn-seo' );
		case 'bad_option':
			return __( 'Malicious setting', 'zinn-seo' );
		case 'seo_spam':
			return __( 'SEO spam', 'zinn-seo' );
		case 'php_error':
			return __( 'PHP error', 'zinn-seo' );
		default:
			return kind;
	}
}

/**
 * What one action does, as the button or choice says it.
 *
 * @param {string} action Action.
 * @return {string} Words.
 */
export function actionName( action ) {
	switch ( action ) {
		case 'quarantine':
			return __( 'Move to quarantine', 'zinn-seo' );
		case 'restore':
			return __( 'Put back the original file', 'zinn-seo' );
		case 'clean':
			return __( 'Remove the spam from the page', 'zinn-seo' );
		case 'lock':
			return __( 'Lock the account', 'zinn-seo' );
		case 'reset_role':
			return __( 'Give the role its normal powers back', 'zinn-seo' );
		case 'remove_option':
			return __( 'Remove the setting', 'zinn-seo' );
		case 'ignore':
			return __( 'It is safe: ignore it', 'zinn-seo' );
		case 'ai':
			return __( 'Ask the AI', 'zinn-seo' );
		case 'update':
			return __( 'Update it', 'zinn-seo' );
		case 'deactivate':
			return __( 'Switch the plugin off', 'zinn-seo' );
		default:
			return action;
	}
}

/**
 * The actions a person can pick for a finding (the AI's second opinion is a button of its own).
 *
 * @param {Object} finding A finding (`data.actions`).
 * @return {Array<string>} Actions, the most useful first.
 */
export function choices( finding ) {
	const list = ( ( finding && finding.data && finding.data.actions ) || [] )
		.filter( ( a ) => a !== 'ai' && a !== 'update' && a !== 'deactivate' )
		.filter( ( a, i, all ) => all.indexOf( a ) === i );
	return list.includes( 'ignore' ) ? list : [ ...list, 'ignore' ];
}

/**
 * The action a finding gets when a person applies "the recommended fix" to many at once: the
 * first real fix it allows, never "ignore" (marking something safe is always a person's choice).
 *
 * @param {Object} finding A finding.
 * @return {string} Action, or '' when only a person can decide.
 */
export function recommended( finding ) {
	return choices( finding ).find( ( a ) => a !== 'ignore' ) || '';
}

/**
 * A severity's words and its badge colour.
 *
 * @param {string} level info|review|warning|danger.
 * @return {{label: string, tone: string}} Words and tone.
 */
export function severity( level ) {
	switch ( level ) {
		case 'danger':
			return { label: __( 'Danger', 'zinn-seo' ), tone: 'error' };
		case 'warning':
			return { label: __( 'Warning', 'zinn-seo' ), tone: 'warning' };
		case 'review':
			return { label: __( 'Check it', 'zinn-seo' ), tone: 'info' };
		default:
			return { label: __( 'Note', 'zinn-seo' ), tone: 'info' };
	}
}

/**
 * The AI's verdict on a suspicious file.
 *
 * @param {string} verdict malicious|suspicious|safe.
 * @return {string} Words.
 */
export function verdictName( verdict ) {
	switch ( verdict ) {
		case 'malicious':
			return __( 'The AI says: malicious', 'zinn-seo' );
		case 'safe':
			return __( 'The AI says: safe', 'zinn-seo' );
		default:
			return __( 'The AI says: suspicious', 'zinn-seo' );
	}
}

/**
 * The fix the AI recommends for an error, in plain words.
 *
 * @param {string} kind update|rollback|setting|deactivate|host|code|none.
 * @return {string} Words.
 */
export function fixKindName( kind ) {
	switch ( kind ) {
		case 'update':
			return __( 'Update the plugin or theme', 'zinn-seo' );
		case 'rollback':
			return __( 'Go back to the version before', 'zinn-seo' );
		case 'setting':
			return __( 'Change a setting', 'zinn-seo' );
		case 'deactivate':
			return __( 'Switch the plugin off', 'zinn-seo' );
		case 'host':
			return __( 'Ask your hosting company', 'zinn-seo' );
		case 'code':
			return __( 'A small code fix (you approve it first)', 'zinn-seo' );
		default:
			return __( 'Harmless: nothing to do', 'zinn-seo' );
	}
}

/**
 * A code fix's state in plain words and what a person can do next.
 *
 * @param {Object} fix A fix (`status`, `approved`, `trial`).
 * @return {{label: string, tone: string, next: Array<string>}} State.
 */
export function fixState( fix ) {
	switch ( fix.status ) {
		case 'refused':
			return {
				label: __( 'Refused by the safety checks', 'zinn-seo' ),
				tone: 'error',
				next: [ 'remove' ],
			};
		case 'draft':
			return {
				label: __( 'Waiting for you to read it', 'zinn-seo' ),
				tone: 'info',
				next: [ 'approve', 'remove' ],
			};
		case 'approved':
			return {
				label: __( 'Approved: ready to test', 'zinn-seo' ),
				tone: 'info',
				next: [ 'trial', 'remove' ],
			};
		case 'tried':
			return {
				label: __( 'Passed its test: ready to switch on', 'zinn-seo' ),
				tone: 'success',
				next: [ 'enable', 'remove' ],
			};
		case 'failed':
			return {
				label: __( 'Failed its test', 'zinn-seo' ),
				tone: 'error',
				next: [ 'trial', 'remove' ],
			};
		case 'on':
			return {
				label: __( 'Switched on', 'zinn-seo' ),
				tone: 'success',
				next: [ 'disable', 'remove' ],
			};
		case 'off':
			return {
				label: __( 'Switched off', 'zinn-seo' ),
				tone: 'warning',
				next:
					fix.trial && fix.trial.ok
						? [ 'enable', 'remove' ]
						: [ 'trial', 'remove' ],
			};
		default:
			return {
				label: __( 'Removed (kept in quarantine)', 'zinn-seo' ),
				tone: 'info',
				next: [],
			};
	}
}

/**
 * A step a person can take on a code fix, as its button says it.
 *
 * @param {string} step approve|trial|enable|disable|remove.
 * @return {string} Words.
 */
export function stepName( step ) {
	switch ( step ) {
		case 'approve':
			return __( 'I have read it: approve for testing', 'zinn-seo' );
		case 'trial':
			return __( 'Test it safely', 'zinn-seo' );
		case 'enable':
			return __( 'Switch it on', 'zinn-seo' );
		case 'disable':
			return __( 'Switch it off', 'zinn-seo' );
		default:
			return __( 'Remove it', 'zinn-seo' );
	}
}

/**
 * Where a code fix came from.
 *
 * @param {string} source ai|guard|person.
 * @return {string} Words.
 */
export function sourceName( source ) {
	switch ( source ) {
		case 'ai':
			return __( 'Written by the AI', 'zinn-seo' );
		case 'guard':
			return __( 'Written by the Update Guard', 'zinn-seo' );
		default:
			return __( 'Written by a person', 'zinn-seo' );
	}
}

/**
 * One safety-check problem of a code fix in plain words.
 *
 * @param {Object} p `{rule, line, text}`.
 * @return {string} Words.
 */
export function problemText( p ) {
	return p.line
		? sprintf(
				/* translators: 1: line number, 2: what is wrong. */
				__( 'Line %1$d: %2$s', 'zinn-seo' ),
				p.line,
				p.text
			)
		: p.text;
}

/**
 * Bytes as a person reads them (B, KB, MB, GB).
 *
 * @param {number} n      Bytes.
 * @param {string} locale Locale for the number.
 * @return {string} Size.
 */
export function bytes( n, locale ) {
	const b = Math.max( 0, Number( n ) || 0 );
	const units = [
		/* translators: unit for bytes. */
		__( 'B', 'zinn-seo' ),
		/* translators: unit for kilobytes. */
		__( 'KB', 'zinn-seo' ),
		/* translators: unit for megabytes. */
		__( 'MB', 'zinn-seo' ),
		/* translators: unit for gigabytes. */
		__( 'GB', 'zinn-seo' ),
	];
	let i = 0;
	let v = b;
	while ( v >= 1024 && i < units.length - 1 ) {
		v /= 1024;
		i++;
	}
	const num = new Intl.NumberFormat( locale || undefined, {
		maximumFractionDigits: i === 0 || v >= 100 ? 0 : 1,
	} ).format( v );
	return `${ num } ${ units[ i ] }`;
}

/**
 * The sum of a preview's or clean-up's totals.
 *
 * @param {Object} totals Kind => `{rows, bytes}`.
 * @return {{rows: number, bytes: number}} Sum.
 */
export function sumTotals( totals ) {
	return Object.values( totals || {} ).reduce(
		( acc, t ) => ( {
			rows: acc.rows + ( Number( t && t.rows ) || 0 ),
			bytes: acc.bytes + ( Number( t && t.bytes ) || 0 ),
		} ),
		{ rows: 0, bytes: 0 }
	);
}

/**
 * The open findings of an area, counted for a tab's badge.
 *
 * @param {Object} counts `{open, by_severity}`.
 * @return {string} '' when nothing is open.
 */
export function openLabel( counts ) {
	const n = Number( counts && counts.open ) || 0;
	return n > 0
		? sprintf(
				/* translators: %d: number of problems. */
				_n( '%d problem open', '%d problems open', n, 'zinn-seo' ),
				n
			)
		: '';
}

/**
 * A job still running?
 *
 * @param {Object} job `{status}`.
 * @return {boolean} Running.
 */
export function running( job ) {
	return !! job && job.status === 'pending';
}

/**
 * A job's progress as one sentence: `{done, total}` from the job (the scan counts files and has
 * no total; the clean-up counts kinds of a known total).
 *
 * @param {Object} job `{progress: {done, total}}`.
 * @return {string} Words.
 */
export function progressText( job ) {
	const p = ( job && job.progress ) || {};
	const done = Number( p.done ) || 0;
	const total = Number( p.total ) || 0;
	if ( total > 0 ) {
		return sprintf(
			/* translators: 1: parts done, 2: parts in all. */
			__( '%1$d of %2$d done…', 'zinn-seo' ),
			Math.min( done, total ),
			total
		);
	}
	if ( done > 0 ) {
		return sprintf(
			/* translators: %d: files checked so far. */
			_n(
				'%d file checked so far…',
				'%d files checked so far…',
				done,
				'zinn-seo'
			),
			done
		);
	}
	return __( 'Working…', 'zinn-seo' );
}

/**
 * The result of a clean-up run, counted.
 *
 * @param {Array<Object>} done `[{ok, verified}]`.
 * @return {{ok: number, failed: number}} Counts.
 */
export function runCounts( done ) {
	const list = done || [];
	const ok = list.filter( ( d ) => d.ok ).length;
	return { ok, failed: list.length - ok };
}

/**
 * The badge colour of the AI's verdict.
 *
 * @param {string} verdict malicious|suspicious|safe.
 * @return {string} Tone.
 */
export function verdictTone( verdict ) {
	if ( verdict === 'malicious' ) {
		return 'error';
	}
	return verdict === 'safe' ? 'success' : 'warning';
}

/**
 * The class of a diff line.
 *
 * @param {string} op '+', '-' or ' '.
 * @return {string} Class.
 */
export function diffClass( op ) {
	if ( op === '+' ) {
		return 'is-add';
	}
	return op === '-' ? 'is-del' : 'is-same';
}
