<?php
/**
 * The import module's per-object fields (data only: read whether the module is on or off, so
 * switching it off loads none of its code and keeps its fields — zs-715).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zinn_seo_user = static fn( string $type, callable $d, array $more = array() ): array => array(
	'type'        => $type,
	'objects'     => array( 'user' ),
	'description' => $d,
) + $more;

return array(
	// Plain strings on purpose: the profile screen stores what was typed ("@name", an
	// address) and the readers tidy it; a stricter type here would empty such a value.
	'x'             => $zinn_seo_user( 'string', static fn() => __( 'X (Twitter) username.', 'zinn-seo' ), array( 'max' => 200 ) ),
	'facebook'      => $zinn_seo_user( 'string', static fn() => __( 'Facebook profile address.', 'zinn-seo' ), array( 'max' => 300 ) ),
	'fediverse'     => $zinn_seo_user( 'string', static fn() => __( 'Fediverse or Mastodon handle (@name@server).', 'zinn-seo' ), array( 'max' => 120 ) ),
	'same_as'       => $zinn_seo_user( 'text', static fn() => __( 'Profiles elsewhere, one address per line.', 'zinn-seo' ), array( 'max' => 4000 ) ),
	'job_title'     => $zinn_seo_user( 'string', static fn() => __( 'Job title.', 'zinn-seo' ), array( 'max' => 200 ) ),
	'schema_import' => array(
		'type'        => 'text',
		'objects'     => array( 'post' ),
		'max'         => 60000,
		'description' => static fn() => __( 'Structured-data types carried over from another SEO plugin (JSON).', 'zinn-seo' ),
	),
);
