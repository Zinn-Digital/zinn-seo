<?php
/**
 * The research toolbox: more checks on Zinn® credit, run at Zinn® for the connected site.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Extra;

use ZinnDigital\ZinnSEO\AdminKit\Engine;
use ZinnDigital\ZinnSEO\Connect\Client;
use ZinnDigital\ZinnSEO\Connect\Connection;
use ZinnDigital\ZinnSEO\Credits\Credits;
use ZinnDigital\ZinnSEO\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each tool runs at Zinn® on the site's connection: this class checks what the screen sends,
 * passes it on, and hands the answer back. ⛔ Money: Zinn® prices a run before it starts, debits
 * it, and refunds whatever the data supplier did not answer; a refusal (not enough credit, the
 * site's monthly limit, an account on hold) comes back BEFORE anything is charged and is passed
 * on in plain words. Nothing here is loaded on a visitor's page.
 */
final class Extra {

	/** The tools list Zinn® sent (prices), kept an hour. Not autoloaded. */
	public const OPTION = 'zseo_toolbox';

	/** How long the tools list is kept. */
	public const TTL = HOUR_IN_SECONDS;

	/** Meta key on a saved results-page picture: `<run id>:<phrase index>`. */
	public const PICTURE_META = '_zseo_snapshot';

	/** The tools in the free plugin; Pro adds its own through `zinn_seo_toolbox_products`. */
	public const FREE = array( 'geo_grid', 'reviews', 'traffic', 'questions', 'domain_overview', 'trends' );

	/** Every tool Zinn® offers (the Pro ones show what they do, and run with Pro). */
	public const ALL = array( 'geo_grid', 'reviews', 'shopping', 'amazon', 'youtube', 'traffic', 'snapshot', 'sentiment', 'technology', 'questions', 'brief', 'domain_overview', 'visibility', 'trends' );

	/**
	 * The tools this site may run.
	 *
	 * @return array<int, string>
	 */
	public static function products(): array {
		/**
		 * The research toolbox's tools this edition runs (Pro adds its own).
		 *
		 * @param array<int, string> $products Tool codes.
		 */
		$list = (array) apply_filters( 'zinn_seo_toolbox_products', self::FREE );

		return array_values( array_intersect( self::ALL, array_map( 'strval', $list ) ) );
	}

	/**
	 * Whether this site may run a tool.
	 *
	 * @param string $product     Tool code.
	 * @param bool   $any_edition A free feature of this plugin runs the tool for its own use (the
	 *                            live content optimiser's top-10 read, zs-766), in any edition.
	 * @return true|\WP_Error
	 */
	public static function allowed( string $product, bool $any_edition = false ) {
		if ( ! in_array( $product, self::ALL, true ) ) {
			return new \WP_Error( 'zinn_seo_toolbox_unknown', __( 'There is no tool with that name.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		if ( ! $any_edition && ! in_array( $product, self::products(), true ) ) {
			return new \WP_Error( 'zinn_seo_toolbox_pro', __( 'This tool is part of Zinn® SEO Pro.', 'zinn-seo' ), array( 'status' => 403 ) );
		}
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * What the screen needs on load.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_data(): array {
		return array(
			'products'  => self::products(),
			'free'      => self::FREE,
			'all'       => self::ALL,
			'connected' => Connection::connected(),
			'canSpend'  => Connection::can_spend() && current_user_can( \ZinnDigital\ZinnSEO\Capabilities::SPEND ),
		);
	}

	/**
	 * The tools list with each unit's price, kept an hour.
	 *
	 * @param bool $fresh Ask Zinn® again.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function catalogue( bool $fresh = false ) {
		$kept = get_option( self::OPTION );
		if ( ! $fresh && is_array( $kept ) && (int) ( $kept['at'] ?? 0 ) > time() - self::TTL && is_array( $kept['data'] ?? null ) ) {
			return $kept['data'];
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/products' );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$data = (array) $result['body'];
		update_option(
			self::OPTION,
			array(
				'at'   => time(),
				'data' => $data,
			),
			false
		);

		return $data;
	}

	/**
	 * What the screen shows first: the tools, their prices, what this edition runs, the credit.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function overview() {
		if ( ! Connection::connected() ) {
			return array(
				'connected' => false,
				'products'  => self::products(),
			);
		}
		$catalogue = self::catalogue();
		if ( is_wp_error( $catalogue ) ) {
			return $catalogue;
		}
		$account = Credits::account();

		return array(
			'connected' => true,
			'products'  => self::products(),
			'catalogue' => $catalogue,
			'balance'   => is_array( $account ) ? (int) ( $account['balance_micros'] ?? 0 ) : null,
			'buy_url'   => is_array( $account ) ? Client::https( $account['buy_url'] ?? '' ) : '',
			'can_spend' => Connection::can_spend(),
		);
	}

	/**
	 * A tool's parameters as the screen sent them, cleaned: text is text, numbers are numbers,
	 * lists are short. Zinn® checks every rule again before anything is spent.
	 *
	 * @param mixed $value Parameters.
	 * @param int   $depth How deep.
	 * @return mixed
	 */
	public static function clean( $value, int $depth = 0 ) {
		if ( $depth > 3 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 200, true ) as $key => $item ) {
				$key = is_int( $key ) ? $key : substr( sanitize_key( (string) $key ), 0, 40 );
				if ( '' === $key ) {
					continue;
				}
				$out[ $key ] = self::clean( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		$text = sanitize_text_field( (string) $value );

		return substr( $text, 0, 2000 );
	}

	/**
	 * What one run would cost (spends nothing).
	 *
	 * @param string               $product Tool.
	 * @param array<string, mixed> $params  Parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function quote( string $product, array $params ) {
		$ok = self::allowed( $product );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/products/' . rawurlencode( $product ) . '/quote', array( 'params' => (object) self::clean( $params ) ) );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * Run a tool now. Spends credit.
	 *
	 * @param string               $product     Tool.
	 * @param array<string, mixed> $params      Parameters.
	 * @param bool                 $any_edition See allowed().
	 * @return array<string, mixed>|\WP_Error The run.
	 */
	public static function run( string $product, array $params, bool $any_edition = false ) {
		$ok = self::allowed( $product, $any_edition );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/products/' . rawurlencode( $product ) . '/runs', array( 'params' => (object) self::clean( $params ) ) );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		Credits::forget();
		/**
		 * Fires after a research toolbox run was paid for.
		 *
		 * @param string               $product Tool.
		 * @param array<string, mixed> $run     The run as Zinn® answered it.
		 */
		do_action( 'zinn_seo_toolbox_run', $product, (array) $result['body'] );

		return (array) $result['body'];
	}

	/**
	 * One run (reading costs nothing). A finished snapshot's pictures are saved on this site.
	 *
	 * @param string $id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run_get( string $id ) {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return new \WP_Error( 'zinn_seo_toolbox_run', __( 'That run does not exist.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/products/runs/' . rawurlencode( $id ) );
		if ( ! $result['ok'] ) {
			return Client::as_error( $result );
		}
		$run = (array) $result['body'];
		if ( 'snapshot' === ( $run['product'] ?? '' ) ) {
			$run = self::with_pictures( $run );
		}

		return $run;
	}

	/**
	 * Recent runs of a tool (or of one scheduled tool).
	 *
	 * @param string $product Tool, or '' for all.
	 * @param string $watch   Scheduled tool id, or ''.
	 * @return array<string, mixed>|\WP_Error `{runs: [...]}`.
	 */
	public static function runs( string $product = '', string $watch = '' ) {
		$query = array( 'limit' => 20 );
		if ( '' !== $product ) {
			$ok = in_array( $product, self::ALL, true );
			if ( ! $ok ) {
				return new \WP_Error( 'zinn_seo_toolbox_unknown', __( 'There is no tool with that name.', 'zinn-seo' ), array( 'status' => 404 ) );
			}
			$query['product'] = $product;
		}
		if ( 1 === preg_match( '/^[0-9a-f-]{36}$/i', $watch ) ) {
			$query['watch'] = $watch;
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/products/runs?' . http_build_query( $query ) );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * Find a business on Google Maps (free to the site).
	 *
	 * @param array<string, mixed> $args `query`, `lat`, `lng`, `location`, `language`.
	 * @return array<string, mixed>|\WP_Error `{places: [...]}`.
	 */
	public static function places( array $args ) {
		if ( ! Connection::connected() ) {
			return new \WP_Error( 'zinn_seo_not_connected', __( 'Connect this site to a Zinn® account first.', 'zinn-seo' ), array( 'status' => 409 ) );
		}
		$query = substr( sanitize_text_field( (string) ( $args['query'] ?? '' ) ), 0, 200 );
		if ( '' === $query ) {
			return new \WP_Error( 'zinn_seo_toolbox_place', __( 'Type your business name.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$body = array(
			'query'    => $query,
			'location' => substr( sanitize_text_field( (string) ( $args['location'] ?? '' ) ), 0, 120 ),
			'language' => substr( sanitize_key( (string) ( $args['language'] ?? '' ) ), 0, 8 ),
		);
		if ( is_numeric( $args['lat'] ?? null ) && is_numeric( $args['lng'] ?? null ) ) {
			$body['lat'] = (float) $args['lat'];
			$body['lng'] = (float) $args['lng'];
		}
		$result = Connection::call( 'POST', '/v1/seo-connection/products/places', $body );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * The tools this site runs on a schedule.
	 *
	 * @return array<string, mixed>|\WP_Error `{watches: [...]}`.
	 */
	public static function watches() {
		if ( ! Connection::connected() ) {
			return array( 'watches' => array() );
		}
		$result = Connection::call( 'GET', '/v1/seo-connection/products/watches' );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * Put a tool on a schedule (each run spends credit).
	 *
	 * @param string               $product Tool.
	 * @param array<string, mixed> $params  Parameters.
	 * @param int                  $every   Days between runs: 1, 7 or 30.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function watch( string $product, array $params, int $every = 7 ) {
		$ok = self::allowed( $product );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$result = Connection::call(
			'POST',
			'/v1/seo-connection/products/watches',
			array(
				'product'      => $product,
				'params'       => (object) self::clean( $params ),
				'cadence_days' => in_array( $every, array( 1, 7, 30 ), true ) ? $every : 7,
			)
		);

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * Change, pause or restart a scheduled tool.
	 *
	 * @param string               $id     Scheduled tool id.
	 * @param array<string, mixed> $fields `cadence_days`, `active`, `params`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function watch_change( string $id, array $fields ) {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return new \WP_Error( 'zinn_seo_toolbox_watch', __( 'That schedule does not exist.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$body = array();
		if ( isset( $fields['cadence_days'] ) && in_array( (int) $fields['cadence_days'], array( 1, 7, 30 ), true ) ) {
			$body['cadence_days'] = (int) $fields['cadence_days'];
		}
		if ( isset( $fields['active'] ) ) {
			$body['active'] = (bool) $fields['active'];
		}
		if ( isset( $fields['params'] ) && is_array( $fields['params'] ) ) {
			$body['params'] = (object) self::clean( $fields['params'] );
		}
		if ( array() === $body ) {
			return new \WP_Error( 'zinn_seo_toolbox_watch', __( 'Nothing to change.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		$result = Connection::call( 'PATCH', '/v1/seo-connection/products/watches/' . rawurlencode( $id ), $body );

		return $result['ok'] ? (array) $result['body'] : Client::as_error( $result );
	}

	/**
	 * Take a tool off the schedule (its past runs are kept).
	 *
	 * @param string $id Scheduled tool id.
	 * @return array{removed: bool}|\WP_Error
	 */
	public static function unwatch( string $id ) {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return new \WP_Error( 'zinn_seo_toolbox_watch', __( 'That schedule does not exist.', 'zinn-seo' ), array( 'status' => 404 ) );
		}
		$result = Connection::call( 'DELETE', '/v1/seo-connection/products/watches/' . rawurlencode( $id ) );

		return $result['ok'] ? array( 'removed' => true ) : Client::as_error( $result );
	}

	// ── results-page pictures (snapshots) ────────────────────────────────────────────────

	/**
	 * A snapshot run with each picture as an address on THIS site, saving any not saved yet.
	 *
	 * @param array<string, mixed> $run The run.
	 * @return array<string, mixed>
	 */
	public static function with_pictures( array $run ): array {
		if ( ! in_array( $run['status'] ?? '', array( 'complete', 'partial' ), true ) ) {
			return $run;
		}
		$phrases = (array) ( $run['result']['phrases'] ?? array() );
		foreach ( $phrases as $n => $row ) {
			if ( ! is_array( $row ) || empty( $row['has_screenshot'] ) ) {
				continue;
			}
			$id = self::picture( (string) $run['id'], (int) $n );
			if ( $id > 0 ) {
				$phrases[ $n ]['picture']    = (string) wp_get_attachment_url( $id );
				$phrases[ $n ]['picture_id'] = $id;
			}
		}
		$run['result']['phrases'] = $phrases;

		return $run;
	}

	/**
	 * The media-library picture of one results page, saved on first use.
	 *
	 * @param string $run_id Run id.
	 * @param int    $n      Phrase index.
	 * @return int Attachment id, or 0 when the picture is not available.
	 */
	public static function picture( string $run_id, int $n ): int {
		$key   = $run_id . ':' . $n;
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen, one indexed key/value pair.
				'meta_query'     => array(
					array(
						'key'   => self::PICTURE_META,
						'value' => $key,
					),
				),
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		$bytes = self::fetch_picture( $run_id, $n );
		if ( '' === $bytes ) {
			return 0;
		}
		$upload = wp_upload_bits( 'search-results-' . substr( $run_id, 0, 8 ) . '-' . $n . '.png', null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => __( 'Search results snapshot', 'zinn-seo' ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( (int) $id, self::PICTURE_META, $key );

		return (int) $id;
	}

	/**
	 * The picture's bytes from Zinn® (a PNG), or '' when it is gone.
	 *
	 * @param string $run_id Run id.
	 * @param int    $n      Phrase index.
	 * @return string
	 */
	public static function fetch_picture( string $run_id, int $n ): string {
		$base  = Engine::base();
		$token = Connection::token();
		if ( '' === $base || '' === $token ) {
			return '';
		}
		$response = wp_safe_remote_get(
			$base . '/v1/seo-connection/products/runs/' . rawurlencode( $run_id ) . '/screenshot?phrase=' . $n,
			array(
				'timeout'     => 25,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'User-Agent'    => 'zinn-seo/' . ZINN_SEO_VERSION . '; ' . home_url(),
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = (string) wp_remote_retrieve_body( $response );

		return 0 === strpos( $body, "\x89PNG\r\n\x1a\n" ) ? $body : '';
	}

	// ── People also ask → an FAQ ─────────────────────────────────────────────────────────

	/**
	 * The FAQ block for a list of questions and answers.
	 *
	 * @param array<int, array{question: string, answer: string}> $items Questions.
	 * @return string Block markup.
	 */
	public static function faq_block( array $items ): string {
		$rows = array();
		foreach ( array_slice( $items, 0, 30 ) as $item ) {
			$question = substr( sanitize_text_field( (string) ( $item['question'] ?? '' ) ), 0, 300 );
			if ( '' === $question ) {
				continue;
			}
			$rows[] = array(
				'question' => $question,
				'answer'   => substr( sanitize_textarea_field( (string) ( $item['answer'] ?? '' ) ), 0, 5000 ),
			);
		}

		return serialize_block(
			array(
				'blockName'    => 'zinn-seo/faq',
				'attrs'        => array( 'items' => $rows ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Put questions on a page: a new draft, or the end of a page the person can edit.
	 *
	 * @param array<int, array<string, string>> $items   Questions and answers.
	 * @param int                               $post_id Page to add to (0: a new draft).
	 * @param string                            $title   The new draft's title.
	 * @return array{id: int, edit: string}|\WP_Error
	 */
	public static function faq_to_page( array $items, int $post_id = 0, string $title = '' ) {
		$block = self::faq_block( $items );
		if ( false === strpos( $block, '"question"' ) ) {
			return new \WP_Error( 'zinn_seo_faq_empty', __( 'Choose at least one question.', 'zinn-seo' ), array( 'status' => 400 ) );
		}
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error( 'zinn_seo_faq_cap', __( 'Sorry, you are not allowed to edit that page.', 'zinn-seo' ), array( 'status' => 403 ) );
			}
			if ( '' !== \ZinnDigital\ZinnSEO\Content\Writer::builder( $post_id ) ) {
				// A builder page renders from its own layout: a block added to the content box would
				// never show (Q328). The FAQ goes in with the builder's "SEO block" element instead.
				return new \WP_Error( 'zinn_seo_faq_builder', __( 'That page is built with a page builder, so a FAQ added here would not show on it. Open it in its builder and add the FAQ with the "SEO block" element, or add it to a new draft.', 'zinn-seo' ), array( 'status' => 409 ) );
			}
			$saved = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => rtrim( (string) $post->post_content ) . "\n\n" . $block,
				),
				true
			);
		} else {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new \WP_Error( 'zinn_seo_faq_cap', __( 'Sorry, you are not allowed to create pages.', 'zinn-seo' ), array( 'status' => 403 ) );
			}
			$saved = wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => '' !== trim( $title ) ? sanitize_text_field( $title ) : __( 'Frequently asked questions', 'zinn-seo' ),
					'post_content' => $block,
				),
				true
			);
		}
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'id'   => (int) $saved,
			'edit' => (string) get_edit_post_link( (int) $saved, 'raw' ),
		);
	}

	/**
	 * Is the site running Pro (the Pro tools and AI answers)?
	 *
	 * @return bool
	 */
	public static function is_pro(): bool {
		return Plugin::is_pro();
	}
}
