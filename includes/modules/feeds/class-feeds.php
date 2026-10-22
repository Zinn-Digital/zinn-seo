<?php
/**
 * RSS feed SEO: a credit line linking back on every item, the featured image, and a delay
 * before a new post reaches the feed (zs-717).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Feeds;

use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scraper sites copy feeds word for word. A line before or after each item ("This post first
 * appeared on %site_link%") links every copy back to the original, which tells search engines
 * which one came first. A short delay gives search engines time to find the post on this site
 * before it appears in the feed at all. (The feed's "noindex, follow" header is one of the smart
 * robots defaults, zs-713.)
 */
final class Feeds {

	/**
	 * Front-end hooks.
	 *
	 * @return void
	 */
	public static function boot_front(): void {
		if ( '' !== trim( (string) Settings::get( 'feed_before', '' ) ) || '' !== trim( (string) Settings::get( 'feed_after', '' ) ) || (bool) Settings::get( 'feed_image', false ) ) {
			add_filter( 'the_content_feed', array( self::class, 'item' ), 20 );
			add_filter( 'the_excerpt_rss', array( self::class, 'item' ), 20 );
		}
		if ( (int) Settings::get( 'feed_delay', 0 ) > 0 ) {
			add_filter( 'posts_where', array( self::class, 'delay' ), 10, 2 );
		}
	}

	/**
	 * The variables a credit line may use, for one post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string> Name => HTML.
	 */
	public static function variables( \WP_Post $post ): array {
		$url   = (string) get_permalink( $post );
		$title = (string) get_the_title( $post );
		$site  = (string) get_bloginfo( 'name' );
		$home  = home_url( '/' );
		$user  = get_userdata( (int) $post->post_author );

		return array(
			'%post_link%' => '<a href="' . esc_url( $url ) . '">' . esc_html( wp_strip_all_tags( $title ) ) . '</a>',
			'%post_url%'  => esc_url( $url ),
			'%title%'     => esc_html( wp_strip_all_tags( $title ) ),
			'%site_link%' => '<a href="' . esc_url( $home ) . '">' . esc_html( wp_specialchars_decode( $site, ENT_QUOTES ) ) . '</a>',
			'%site_url%'  => esc_url( $home ),
			'%sitename%'  => esc_html( wp_specialchars_decode( $site, ENT_QUOTES ) ),
			'%author%'    => $user instanceof \WP_User ? esc_html( $user->display_name ) : '',
		);
	}

	/**
	 * A credit line with its variables filled in (the text itself escaped).
	 *
	 * @param string                $template The line.
	 * @param array<string, string> $vars     Name => HTML.
	 * @return string HTML.
	 */
	public static function line( string $template, array $vars ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return '';
		}
		$parts = preg_split( '/(%[a-z_]+%)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( (array) $parts as $part ) {
			$out .= isset( $vars[ $part ] ) ? $vars[ $part ] : esc_html( (string) $part );
		}

		return '<p>' . $out . '</p>';
	}

	/**
	 * One feed item: the image, the lines before and after.
	 *
	 * @param string $content Item content.
	 * @return string
	 */
	public static function item( $content ): string {
		$content = (string) $content;
		$post    = get_post();
		if ( ! is_feed() || ! $post instanceof \WP_Post ) {
			return $content;
		}
		$vars   = self::variables( $post );
		$before = self::line( (string) Settings::get( 'feed_before', '' ), $vars );
		$after  = self::line( (string) Settings::get( 'feed_after', '' ), $vars );
		$image  = '';
		if ( (bool) Settings::get( 'feed_image', false ) && has_post_thumbnail( $post ) && false === strpos( $content, 'wp-post-image' ) ) {
			$image = '<p>' . get_the_post_thumbnail( $post, 'large' ) . '</p>';
		}

		return $image . $before . $content . $after;
	}

	/**
	 * The feed's main query skips posts newer than the delay.
	 *
	 * @param string    $where SQL WHERE.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public static function delay( $where, $query ): string {
		global $wpdb;
		$where = (string) $where;
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_feed() || $query->is_comment_feed() ) {
			return $where;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 60 * (int) Settings::get( 'feed_delay', 0 ) );

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_date_gmt <= %s", $cutoff );
	}
}
