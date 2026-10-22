/**
 * The side navigation's map (UI1, queue Q269): every screen of the admin shell in one of nine
 * sections, named the way people look for them. Route ids are the screens' addresses
 * (`?page=zinn-seo&view=<id>#/<tab>`), so grouping them changes no link: a bookmark, a guide link
 * or a help link written before the side navigation opens the same screen and tab.
 *
 * ⛔ A screen that is not listed here is still shown, under "Settings and tools", so a new screen can
 * never vanish from the navigation; `__tests__/nav.test.js` fails until it is placed on purpose.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The sections, in the order they are shown.
 *
 * @return {Array<Object>} `{ id, label }`.
 */
export function navSections() {
	return [
		{ id: 'start', label: __( 'Start', 'zinn-seo' ) },
		{
			id: 'content',
			label: __( 'Content and writing', 'zinn-seo' ),
		},
		{
			id: 'appearance',
			label: __( 'Search appearance', 'zinn-seo' ),
		},
		{
			id: 'technical',
			label: __( 'Technical SEO', 'zinn-seo' ),
		},
		{
			id: 'insights',
			label: __( 'Insights and rankings', 'zinn-seo' ),
		},
		{ id: 'ai', label: __( 'AI and credit', 'zinn-seo' ) },
		{
			id: 'agency',
			label: __( 'Agency and clients', 'zinn-seo' ),
		},
		{
			id: 'settings',
			label: __( 'Settings and tools', 'zinn-seo' ),
		},
		{
			id: 'account',
			label: __( 'Account and help', 'zinn-seo' ),
		},
	];
}

/**
 * Screen (route id) → its section and its icon (a WordPress dashicon, so nothing extra is loaded).
 * The order inside a section is the order here.
 */
export const SCREENS = {
	overview: [ 'start', 'dashboard' ],
	fixit: [ 'start', 'yes-alt' ],
	autopilot: [ 'start', 'controls-repeat' ],
	setup: [ 'start', 'flag' ],
	writing: [ 'content', 'edit-page' ],
	content: [ 'content', 'media-text' ],
	links: [ 'content', 'admin-links' ],
	linkplan: [ 'content', 'admin-site-alt3' ],
	blocks: [ 'content', 'block-default' ],
	bulk: [ 'content', 'list-view' ],
	pages: [ 'content', 'media-spreadsheet' ],
	appearance: [ 'appearance', 'search' ],
	social: [ 'appearance', 'share' ],
	schema: [ 'appearance', 'editor-code' ],
	richresults: [ 'appearance', 'star-filled' ],
	discovery: [ 'appearance', 'networking' ],
	redirects: [ 'technical', 'randomize' ],
	indexing: [ 'technical', 'upload' ],
	cleanup: [ 'technical', 'filter' ],
	protection: [ 'technical', 'shield' ],
	guard: [ 'technical', 'update' ],
	care: [ 'technical', 'shield-alt' ],
	cdn: [ 'technical', 'cloud' ],
	search: [ 'insights', 'chart-area' ],
	checks: [ 'insights', 'chart-bar' ],
	authority: [ 'insights', 'awards' ],
	toolbox: [ 'insights', 'portfolio' ],
	referrals: [ 'insights', 'money-alt' ],
	ai: [ 'ai', 'lightbulb' ],
	credits: [ 'ai', 'tickets-alt' ],
	services: [ 'ai', 'cart' ],
	catalogue: [ 'ai', 'tag' ],
	agency: [ 'agency', 'building' ],
	reports: [ 'agency', 'chart-pie' ],
	tasks: [ 'agency', 'clipboard' ],
	settings: [ 'settings', 'admin-settings' ],
	transfer: [ 'settings', 'database-export' ],
	roles: [ 'settings', 'admin-users' ],
	works: [ 'settings', 'admin-plugins' ],
	import: [ 'settings', 'migrate' ],
	tools: [ 'settings', 'admin-tools' ],
	plans: [ 'account', 'id-alt' ],
	addons: [ 'account', 'plus-alt' ],
	help: [ 'account', 'sos' ],
	feedback: [ 'account', 'format-chat' ],
};

/** The screens only the Pro plugin has (marked "Pro" in the navigation). */
export const PRO_SCREENS = [
	'autopilot',
	'linkplan',
	'guard',
	'care',
	'referrals',
	'pages',
	'reports',
	'tasks',
	'agency',
];

/**
 * The admin kit's own screens, in its order, with the names the kit gives them (the same strings,
 * so they are already translated).
 *
 * @param {Object} kit The kit's boot data (`hidden`: screens a white label or client login hides).
 * @return {Array<Object>} `{ id, label }`.
 */
export function kitScreens( kit = {} ) {
	const hidden = new Set( kit.hidden || [] );
	return [
		{ id: 'overview', label: __( 'Overview', 'zinn-seo' ) },
		{ id: 'setup', label: __( 'Setup', 'zinn-seo' ) },
		{ id: 'plans', label: __( 'Plans and licence', 'zinn-seo' ) },
		{ id: 'addons', label: __( 'Add-ons', 'zinn-seo' ) },
		{ id: 'help', label: __( 'Get help', 'zinn-seo' ) },
		{ id: 'feedback', label: __( 'Feedback', 'zinn-seo' ) },
	].filter( ( r ) => ! hidden.has( r.id ) );
}

/**
 * What the Pro screens do, for the free plugin's "Pro" card (shown in place of the screen; the
 * screen itself, its data and its routes are not in the free plugin). The Pro modules' own
 * descriptions, word for word, so they are already translated in every language.
 *
 * @return {Object<string, Array<string>>} Screen id → points.
 */
export function proPoints() {
	return {
		autopilot: [
			__(
				'Fix the whole Fix it list in one go: meta descriptions, titles that repeat, alt text, structured data, internal links and the one-click fixes.',
				'zinn-seo'
			),
			__(
				'The whole site, some kinds of fix, or one section; see what it will fix and what it costs before it starts.',
				'zinn-seo'
			),
			__(
				'Approve everything or review each fix; every change keeps the value it replaced, and a whole run undoes in one click.',
				'zinn-seo'
			),
			__(
				'Runs weekly in the background if you like, with an e-mail summary, and across every site of a network.',
				'zinn-seo'
			),
		],
		linkplan: [
			__(
				'One plan for every page: hub pages for each topic, pages linked up to their hub and across to the pages they are closest to.',
				'zinn-seo'
			),
			__(
				'Every page nothing links to gets a link, and each page gets the number of links its length carries: nothing stuffed.',
				'zinn-seo'
			),
			__(
				'Natural, varied link words from your own sentences, in the best sentence, never in headings or to hidden or redirected pages.',
				'zinn-seo'
			),
			__(
				'Approve all, by topic or one by one; applied in the background with a revision per page, and a whole run undone in one click.',
				'zinn-seo'
			),
			__(
				'New pages get their links as you publish, and a link health score shows how the site and each page are doing.',
				'zinn-seo'
			),
		],
		guard: [
			__(
				'Know before you update whether each plugin and theme suits your site, and find out at once if an update changed your titles, descriptions or structured data.',
				'zinn-seo'
			),
		],
		care: [
			__(
				'Find and clean up malware, read your error logs and fix what they show, clean up the database, and let the AI write small code fixes you approve — every change backed up first and undoable in one click.',
				'zinn-seo'
			),
		],
		referrals: [
			__(
				'See which pages ChatGPT, Perplexity, Gemini, Copilot and Claude send visitors to, and what each page earns from ads next to its Google clicks — with no tracking script on your site.',
				'zinn-seo'
			),
		],
		pages: [
			__(
				'Build hundreds of landing pages — one per town, product or service — from one template and a spreadsheet or Google Sheet, each with its own SEO title and description. Pages that would be thin or duplicates are held back and listed, so you only publish pages worth ranking.',
				'zinn-seo'
			),
		],
		reports: [
			__(
				'Send each client a report with your logo and theirs: rankings, pages in Google, links and the work you did, opened by a short summary in plain words, e-mailed on a schedule, kept up to date in a Google Sheet, and on a client page that needs no login.',
				'zinn-seo'
			),
		],
		tasks: [
			__(
				'Hand any problem to a colleague or a client with a due date: they get an e-mail, can comment and tick it off, and you see what is open and overdue in the Fix it inbox.',
				'zinn-seo'
			),
		],
		agency: [
			__(
				"Put your agency's name, logo and colours on the plugin, hide our branding from your clients, and give each client a login that shows only the screens you choose — never prices, credit, keys or connections.",
				'zinn-seo'
			),
			__(
				'Get the same alerts you get by e-mail — a ranking that fell, a lost link, a check paused for credit — in Slack, Microsoft Teams, Discord, Telegram or your own webhook, so your team sees them where it already talks.',
				'zinn-seo'
			),
		],
	};
}

/**
 * The screens arranged in their sections.
 *
 * @param {Array<Object>} routes Every screen the shell has (`{ id, label }`), in the shell's order.
 * @param {Object}        badges Screen id → `{ count, label }`.
 * @return {Array<Object>} Sections that have at least one screen: `{ id, label, items }`,
 *                         each item `{ id, label, icon, pro, badge }`.
 */
export function arrange( routes, badges = {} ) {
	const order = Object.keys( SCREENS );
	const sections = navSections().map( ( s ) => ( { ...s, items: [] } ) );
	const byId = Object.fromEntries( sections.map( ( s ) => [ s.id, s ] ) );
	routes.forEach( ( route ) => {
		const [ section, icon ] = SCREENS[ route.id ] || [
			'settings',
			'admin-generic',
		];
		byId[ section ].items.push( {
			id: route.id,
			label: route.label,
			icon,
			pro: PRO_SCREENS.includes( route.id ),
			badge: badges[ route.id ] || null,
		} );
	} );
	const rank = ( id ) =>
		order.includes( id ) ? order.indexOf( id ) : order.length;
	sections.forEach( ( s ) =>
		s.items.sort( ( a, b ) => rank( a.id ) - rank( b.id ) )
	);
	return sections.filter( ( s ) => s.items.length );
}

/**
 * The counts shown beside a screen's name, from what the screen's data already holds (nothing is
 * worked out for them).
 *
 * @param {Object} data The admin app's boot data.
 * @return {Object} Screen id → `{ count, label }`.
 */
export function navBadges( data = {} ) {
	const out = {};
	const open = Number( data.fixitOpen );
	if ( Number.isInteger( open ) && open > 0 ) {
		out.fixit = {
			count: open,
			label: sprintf(
				/* translators: %s: how many problems are waiting to be fixed. */
				_n( '%s issue to fix', '%s issues to fix', open, 'zinn-seo' ),
				open.toLocaleString()
			),
		};
	}
	const care = Number( data.careOpen );
	if ( Number.isInteger( care ) && care > 0 ) {
		out.care = {
			count: care,
			label: sprintf(
				/* translators: %s: how many site care problems (malware, errors) are open. */
				_n(
					'%s site problem open',
					'%s site problems open',
					care,
					'zinn-seo'
				),
				care.toLocaleString()
			),
		};
	}
	return out;
}

/**
 * Find a screen's section.
 *
 * @param {Array<Object>} sections From arrange().
 * @param {string}        id       Screen id.
 * @return {Object|null} `{ section, item }`.
 */
export function locate( sections, id ) {
	for ( const section of sections ) {
		const item = section.items.find( ( i ) => i.id === id );
		if ( item ) {
			return { section, item };
		}
	}
	return null;
}
