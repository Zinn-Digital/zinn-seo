/* Generated from wp/packages/zinn-admin-kit/src/js/tours.js by wp/bin/build-admin-kit.php. Edit the package, never this copy. */
import { __ } from '@wordpress/i18n';

/**
 * The guided tour for each of the kit's own screens (feature adm-3). A host route brings its own
 * `tour` array; a route with no tour hides the "Take the tour" item.
 *
 * @return {Object<string, Array<Object>>} Route id -> steps.
 */
export default function kitTours() {
	const nav = {
		target: '[data-zak-tour="nav"]',
		title: __( 'Everything in one place', 'zinn-seo' ),
		content: __(
			'These tabs lead to every screen of the plugin: setup, settings, your licence, help and more.',
			'zinn-seo'
		),
	};
	const help = {
		target: '[data-zak-tour="help"]',
		title: __( 'Help on every screen', 'zinn-seo' ),
		content: __(
			'Open this menu for the guide to the screen you are on, this tour, or to ask us for help.',
			'zinn-seo'
		),
	};
	const theme = {
		target: '[data-zak-tour="theme"]',
		title: __( 'Light or dark', 'zinn-seo' ),
		content: __(
			'Choose light, dark, or the same as your computer. Your choice is remembered for you only.',
			'zinn-seo'
		),
	};

	return {
		overview: [
			{
				title: __( 'Welcome', 'zinn-seo' ),
				content: __(
					'This short tour shows where things are. You can leave it at any time with Escape.',
					'zinn-seo'
				),
			},
			nav,
			{
				target: '[data-zak-tour="status"]',
				title: __( 'Your site at a glance', 'zinn-seo' ),
				content: __(
					'Setup progress and your plan are shown here, with the next thing worth doing.',
					'zinn-seo'
				),
			},
			help,
			theme,
		],
		plans: [
			{
				target: '[data-zak-tour="licence"]',
				title: __( 'Your licence', 'zinn-seo' ),
				content: __(
					'Your plan, how many sites it covers, and whether this copy of the site counts toward that limit.',
					'zinn-seo'
				),
			},
			{
				target: '[data-zak-tour="offers"]',
				title: __( 'Try Pro or upgrade', 'zinn-seo' ),
				content: __(
					'Start a free trial without a card, upgrade, or renew, all from here.',
					'zinn-seo'
				),
			},
			{
				target: '[data-zak-tour="compare"]',
				title: __( 'Compare the plans', 'zinn-seo' ),
				content: __(
					'Every feature and the plan that includes it. Search for a feature or show one area at a time.',
					'zinn-seo'
				),
			},
		],
		addons: [
			{
				target: '[data-zak-tour="addons"]',
				title: __( 'More from Zinn Digital®', 'zinn-seo' ),
				content: __(
					'Plugins that work with this one, and services from the same team. Cards you hid on other screens can be shown again here.',
					'zinn-seo'
				),
			},
		],
		help: [
			{
				target: '[data-zak-tour="connection"]',
				title: __( 'Connect for faster help', 'zinn-seo' ),
				content: __(
					'Connecting is optional. It lets you send requests without typing your e-mail each time, and you can disconnect whenever you like.',
					'zinn-seo'
				),
			},
			{
				target: '[data-zak-tour="ticket"]',
				title: __( 'Ask for help or report a bug', 'zinn-seo' ),
				content: __(
					'Describe what happened. You choose whether to attach site information, and you can see exactly what it contains first.',
					'zinn-seo'
				),
			},
		],
		feedback: [
			{
				target: '[data-zak-tour="ticket"]',
				title: __( 'Tell us what to build', 'zinn-seo' ),
				content: __(
					'Send an idea, a feature request or any feedback. It goes straight to the team.',
					'zinn-seo'
				),
			},
		],
	};
}
