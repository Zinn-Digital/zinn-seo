<?php
/**
 * Core Web Vitals from real visitors (Chrome UX Report) per page and per template, with the cause
 * named (zs-322), and a lab test of one page on Zinn®'s own Lighthouse runner (zs-411).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Cwv;

use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field data is what Google ranks on: real Chrome users over the last 28 days, read through the
 * site's Zinn® connection (free; Zinn® keeps each answer a day, this site keeps it a day too). A
 * page with too few visits has no page-level record — that is an answer, not an error, and the
 * screen then shows the whole site's record instead and says so (never a page borrowing numbers).
 *
 * A "template" is a kind of page that shares its layout: the home page, single posts, pages,
 * products, category archives… Its record is read from its most-visited addresses, because one
 * slow template slows every page built on it, and the fix is made once, in the template.
 *
 * The lab test (Pro) is a Lighthouse run on Zinn®'s own runner: free when the site is hosted by
 * Zinn Digital®, otherwise one audit unit of Zinn® credit, refunded when no reading comes back.
 */
final class Cwv {

	/** Option prefix: kept records. */
	public const CACHE = 'zseo_cwv_';

	/** Option: lab tests started (newest first, 20). */
	public const LAB = 'zseo_cwv_lab';

	/** Seconds a record is kept. */
	public const TTL = DAY_IN_SECONDS;

	/** Pages read per template. */
	public const PER_TEMPLATE = 3;

	/**
	 * One record: the site (`origin`) or one page (`url`), on phones or computers.
	 *
	 * @param string $url   A page of this site ('' for the home page).
	 * @param string $scope origin|url.
	 * @param string $form  phone|desktop.
	 * @param bool   $fresh Ask again.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function record( string $url, string $scope = 'url', string $form = 'phone', bool $fresh = false ) {
		$url   = '' === $url ? home_url( '/' ) : $url;
		$scope = 'origin' === $scope ? 'origin' : 'url';
		$form  = 'desktop' === $form ? 'desktop' : 'phone';
		$opt   = self::CACHE . substr( md5( $url . '|' . $scope . '|' . $form ), 0, 16 );
		$kept  = get_option( $opt );
		if ( ! $fresh && is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) > time() - self::TTL ) {
			return (array) $kept['r'];
		}
		$res = Connection::call( 'GET', '/v1/seo-connection/crux?' . http_build_query( array( 'url' => $url, 'scope' => $scope, 'form_factor' => $form ), '', '&', PHP_QUERY_RFC3986 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one query string.
		if ( ! $res['ok'] ) {
			return Client::as_error( $res );
		}
		$r = self::clean( (array) $res['body'] );
		update_option(
			$opt,
			array(
				'at' => time(),
				'r'  => $r,
			),
			false
		);
		$index         = (array) get_option( self::CACHE . 'index', array() );
		$index[ $opt ] = time();
		update_option( self::CACHE . 'index', $index, false );

		return $r;
	}

	/**
	 * Keep the fields the screen reads. Pure.
	 *
	 * @param array<string, mixed> $body The API's answer.
	 * @return array<string, mixed>
	 */
	public static function clean( array $body ): array {
		$metrics = array();
		foreach ( array( 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ) as $m ) {
			$x = $body['metrics'][ $m ] ?? null;
			if ( ! is_array( $x ) ) {
				continue;
			}
			$metrics[ $m ] = array(
				'p75'               => null === ( $x['p75'] ?? null ) ? null : (float) $x['p75'],
				'rating'            => in_array( $x['rating'] ?? '', array( 'good', 'needs_improvement', 'poor' ), true ) ? (string) $x['rating'] : null,
				'good'              => (float) ( $x['good'] ?? 0 ),
				'needs_improvement' => (float) ( $x['needs_improvement'] ?? 0 ),
				'poor'              => (float) ( $x['poor'] ?? 0 ),
			);
		}

		return array(
			'url'      => esc_url_raw( (string) ( $body['url'] ?? '' ) ),
			'scope'    => 'origin' === ( $body['scope'] ?? '' ) ? 'origin' : 'url',
			'form'     => 'desktop' === ( $body['form_factor'] ?? '' ) ? 'desktop' : 'phone',
			'has_data' => ! empty( $body['has_data'] ),
			'metrics'  => $metrics,
			'overall'  => in_array( $body['overall'] ?? '', array( 'good', 'needs_improvement', 'poor' ), true ) ? (string) $body['overall'] : null,
			'period'   => is_array( $body['collection_period'] ?? null ) ? array(
				'from' => sanitize_text_field( (string) ( $body['collection_period']['first_date'] ?? '' ) ),
				'to'   => sanitize_text_field( (string) ( $body['collection_period']['last_date'] ?? '' ) ),
			) : null,
		);
	}

	/**
	 * What is most likely slowing a page, from its field record (and its lab test when there is
	 * one), in plain words with the fix. Pure.
	 *
	 * @param array<string, mixed>      $record A cleaned record.
	 * @param array<string, mixed>|null $lab    A finished lab test's result.
	 * @return array<int, array{metric: string, cause: string, fix: string}>
	 */
	public static function causes( array $record, ?array $lab = null ): array {
		$m   = (array) ( $record['metrics'] ?? array() );
		$bad = static fn( string $k ): bool => in_array( $m[ $k ]['rating'] ?? null, array( 'needs_improvement', 'poor' ), true );
		$out = array();
		if ( $bad( 'ttfb' ) || ( $bad( 'lcp' ) && (float) ( $m['ttfb']['p75'] ?? 0 ) > 800 ) ) {
			$out[] = array(
				'metric' => 'ttfb',
				'cause'  => __( 'The server takes too long to start sending the page.', 'zinn-seo' ),
				'fix'    => __( 'Turn on a page cache (Zinn® Cache does this in one click), and check the hosting is not overloaded.', 'zinn-seo' ),
			);
		}
		if ( $bad( 'lcp' ) ) {
			$out[] = array(
				'metric' => 'lcp',
				'cause'  => __( 'The biggest image or heading at the top of the page appears late.', 'zinn-seo' ),
				'fix'    => __( 'Make the top image smaller (WebP or AVIF), do not lazy-load it, and load it first.', 'zinn-seo' ),
			);
		}
		if ( $bad( 'inp' ) ) {
			$out[] = array(
				'metric' => 'inp',
				'cause'  => __( 'The page is slow to react to taps and clicks: too much JavaScript runs at once.', 'zinn-seo' ),
				'fix'    => __( 'Remove plugins and theme features this page does not need, and delay chat widgets, trackers and sliders.', 'zinn-seo' ),
			);
		}
		if ( $bad( 'cls' ) ) {
			$out[] = array(
				'metric' => 'cls',
				'cause'  => __( 'Things move while the page loads: images, adverts or embeds without a reserved space.', 'zinn-seo' ),
				'fix'    => __( 'Give every image and embed its width and height, and reserve space for adverts and banners.', 'zinn-seo' ),
			);
		}
		if ( $bad( 'fcp' ) && ! $bad( 'ttfb' ) ) {
			$out[] = array(
				'metric' => 'fcp',
				'cause'  => __( 'Nothing shows for a while: stylesheets and fonts block the first paint.', 'zinn-seo' ),
				'fix'    => __( 'Load fonts with font-display: swap and load only the CSS this page uses.', 'zinn-seo' ),
			);
		}
		foreach ( array_slice( (array) ( $lab['opportunities'] ?? array() ), 0, 3 ) as $o ) {
			$title = is_array( $o ) ? sanitize_text_field( (string) ( $o['title'] ?? '' ) ) : sanitize_text_field( (string) $o );
			if ( '' !== $title ) {
				$out[] = array(
					'metric' => 'lab',
					'cause'  => $title,
					'fix'    => is_array( $o ) && isset( $o['savings_ms'] )
						/* translators: %s: milliseconds, e.g. "1,200". */
						? sprintf( __( 'Could save about %s ms.', 'zinn-seo' ), number_format_i18n( (float) $o['savings_ms'] ) )
						: '',
				);
			}
		}

		return $out;
	}

	/**
	 * The templates of this site and the addresses that stand for each (most-visited first when
	 * Search Console is connected, else the newest).
	 *
	 * @return array<int, array{id: string, label: string, urls: array<int, string>}>
	 */
	public static function templates(): array {
		$visits = array();
		if ( class_exists( '\ZinnDigital\ZinnSEO\Gsc\Google' ) && \ZinnDigital\ZinnSEO\Gsc\Google::has( 'search_console' ) ) {
			list( $from, $to ) = \ZinnDigital\ZinnSEO\Gsc\Data::window( 28 );
			$set               = \ZinnDigital\ZinnSEO\Gsc\Data::set(
				array(
					'startDate'  => $from,
					'endDate'    => $to,
					'dimensions' => array( 'page' ),
				)
			);
			if ( ! is_wp_error( $set ) ) {
				foreach ( \ZinnDigital\ZinnSEO\Gsc\Data::named( $set['rows'], array( 'page' ) ) as $r ) {
					$visits[ (string) $r['page'] ] = (int) $r['clicks'] + (int) $r['impressions'];
				}
				arsort( $visits );
			}
		}
		$out   = array(
			array(
				'id'    => 'home',
				'label' => __( 'Home page', 'zinn-seo' ),
				'urls'  => array( home_url( '/' ) ),
			),
		);
		$types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$urls = self::by_visits( $visits, static fn( int $id ): bool => get_post_type( $id ) === $type->name );
			if ( count( $urls ) < self::PER_TEMPLATE ) {
				$ids = get_posts(
					array(
						'post_type'      => $type->name,
						'post_status'    => 'publish',
						'posts_per_page' => self::PER_TEMPLATE,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				foreach ( $ids as $id ) {
					$u = (string) get_permalink( (int) $id );
					if ( '' !== $u && ! in_array( $u, $urls, true ) ) {
						$urls[] = $u;
					}
				}
			}
			$urls = array_slice( $urls, 0, self::PER_TEMPLATE );
			if ( array() !== $urls ) {
				$out[] = array(
					'id'    => 'post:' . $type->name,
					'label' => (string) $type->labels->singular_name,
					'urls'  => $urls,
				);
			}
		}

		return $out;
	}

	/**
	 * The most-visited addresses that pass a test on their post id.
	 *
	 * @param array<string, int>  $visits Address => visits, most first.
	 * @param callable(int): bool $test   Post id test.
	 * @return array<int, string>
	 */
	private static function by_visits( array $visits, callable $test ): array {
		$out = array();
		foreach ( array_slice( array_keys( $visits ), 0, 200 ) as $url ) {
			$id = \ZinnDigital\ZinnSEO\Url_Post::id( (string) $url );
			if ( $id > 0 && $test( $id ) ) {
				$out[] = (string) $url;
				if ( count( $out ) >= self::PER_TEMPLATE ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * A template's record: its first address that has page-level data; else the whole site's.
	 *
	 * @param array{id: string, label: string, urls: array<int, string>} $template Template.
	 * @param string                                                     $form     phone|desktop.
	 * @return array<string, mixed>
	 */
	public static function template_record( array $template, string $form ): array {
		foreach ( $template['urls'] as $url ) {
			$r = self::record( $url, 'url', $form );
			if ( is_wp_error( $r ) ) {
				return $template + array(
					'error' => $r->get_error_message(),
				);
			}
			if ( ! empty( $r['has_data'] ) ) {
				return $template + array(
					'record' => $r,
					'from'   => 'page',
					'causes' => self::causes( $r ),
				);
			}
		}
		$origin = self::record( '', 'origin', $form );
		if ( is_wp_error( $origin ) ) {
			return $template + array( 'error' => $origin->get_error_message() );
		}

		return $template + array(
			'record' => $origin,
			'from'   => 'site',
			'causes' => self::causes( $origin ),
		);
	}

	/**
	 * Start a lab test (Pro).
	 *
	 * @param string $url A page of this site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function lab( string $url ) {
		if ( ! Plugin::is_pro() ) {
			return new \WP_Error( 'zinn_seo_pro', __( 'Lab speed tests are part of Zinn® SEO Pro.', 'zinn-seo' ), array( 'status' => 402 ) );
		}
		$res = Connection::call( 'POST', '/v1/seo-connection/lab-tests', array( 'url' => $url ) );
		if ( ! $res['ok'] ) {
			return Client::as_error( $res );
		}
		$check = (array) $res['body'];
		$log   = (array) get_option( self::LAB, array() );
		array_unshift(
			$log,
			array(
				'id'  => sanitize_text_field( (string) ( $check['id'] ?? '' ) ),
				'url' => $url,
				'at'  => time(),
			)
		);
		update_option( self::LAB, array_slice( $log, 0, 20 ), false );

		return $check;
	}

	/**
	 * A lab test's state and result.
	 *
	 * @param string $id Check id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function lab_result( string $id ) {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return new \WP_Error( 'zinn_seo_lab', __( 'That test was not found.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$res = Connection::call( 'GET', '/v1/seo-connection/site-checks/' . $id );
		if ( ! $res['ok'] ) {
			return Client::as_error( $res );
		}
		$check = (array) $res['body'];
		if ( 'complete' === ( $check['status'] ?? '' ) ) {
			$check['causes'] = self::causes( array( 'metrics' => array() ), (array) ( $check['result'] ?? array() ) );
		}

		return $check;
	}

	/**
	 * Forget kept records.
	 *
	 * @return void
	 */
	public static function forget(): void {
		foreach ( array_keys( (array) get_option( self::CACHE . 'index', array() ) ) as $opt ) {
			delete_option( (string) $opt );
		}
		delete_option( self::CACHE . 'index' );
	}
}
