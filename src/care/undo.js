/**
 * Site care: undoing a whole run, however large.
 */
import { seoFetch } from '../admin/api';

/**
 * Undo a whole run: a large run (a big database clean-up) is undone over several calls, each
 * answering where to go on from (`next`), until there is nothing left.
 *
 * @param {string}   run  Run id.
 * @param {Function} send The REST call (the plugin's own; a test passes a stand-in).
 * @return {Promise<Object>} `{undone, kept, failed}` over every call.
 */
export async function undoRun( run, send = seoFetch ) {
	const sum = { undone: 0, kept: 0, failed: [] };
	let before = 0;
	for ( let i = 0; i < 1000; i++ ) {
		const r = await send( '/care/history/undo', {
			method: 'POST',
			data: { run, before },
		} );
		sum.undone += r.undone || 0;
		sum.kept += r.kept || 0;
		sum.failed = [ ...sum.failed, ...( r.failed || [] ) ];
		if ( ! r.next ) {
			break;
		}
		before = r.next;
	}
	return sum;
}
