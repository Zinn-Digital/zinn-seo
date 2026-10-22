<?php
/**
 * People in the graph: authors, reviewers and fact-checkers with their expertise (E-E-A-T, zs-032,
 * zs-306).
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One `Person` node per user, with ONE `@id` everywhere it is used (the article's author, the
 * author box, a review's author, "reviewed by"), so the graph says "this same person" instead of
 * describing three. The expertise fields live on the user's profile (Users → Profile,
 * "Expertise"); the social accounts the social module already asks for become `sameAs`.
 */
final class People {

	/** User meta keys (removed on uninstall with the `_zseo_` family). */
	public const JOB_TITLE   = '_zseo_job_title';
	public const CREDENTIALS = '_zseo_credentials';
	public const KNOWS_ABOUT = '_zseo_knows_about';
	public const SAME_AS     = '_zseo_same_as';
	public const ALUMNI_OF   = '_zseo_alumni_of';
	public const AWARDS      = '_zseo_awards';

	/**
	 * The profile fields: key => [label, multi-line].
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function fields(): array {
		return array(
			self::JOB_TITLE   => array( __( 'Job title', 'zinn-seo' ), false ),
			self::CREDENTIALS => array( __( 'Qualifications and credentials (one per line)', 'zinn-seo' ), true ),
			self::KNOWS_ABOUT => array( __( 'Topics of expertise (one per line)', 'zinn-seo' ), true ),
			self::ALUMNI_OF   => array( __( 'Schools and universities (one per line)', 'zinn-seo' ), true ),
			self::AWARDS      => array( __( 'Awards (one per line)', 'zinn-seo' ), true ),
			self::SAME_AS     => array( __( 'Profiles elsewhere: LinkedIn, Wikipedia, a university page (one address per line)', 'zinn-seo' ), true ),
		);
	}

	/**
	 * The `@id` of a user's Person node.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function id( int $user_id ): string {
		$user = get_userdata( $user_id );

		return home_url( '/' ) . '#person-' . substr( md5( $user ? (string) $user->user_login : (string) $user_id ), 0, 10 );
	}

	/**
	 * A user's lines from a multi-line field.
	 *
	 * @param int    $user_id User.
	 * @param string $key     Meta key.
	 * @return array<int, string>
	 */
	public static function lines( int $user_id, string $key ): array {
		$raw = (string) get_user_meta( $user_id, $key, true );
		$out = array();
		foreach ( Html::split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * The addresses of a user's profiles elsewhere (theirs, plus the social accounts).
	 *
	 * @param int $user_id User.
	 * @return array<int, string>
	 */
	public static function same_as( int $user_id ): array {
		$out = array();
		foreach ( self::lines( $user_id, self::SAME_AS ) as $url ) {
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}
		$x = ltrim( trim( (string) get_user_meta( $user_id, '_zseo_x', true ) ), '@' );
		if ( 1 === preg_match( '/^[A-Za-z0-9_]{1,15}$/', $x ) ) {
			$out[] = 'https://x.com/' . $x;
		}
		$fb = esc_url_raw( trim( (string) get_user_meta( $user_id, '_zseo_facebook', true ) ), array( 'https' ) );
		if ( '' !== $fb ) {
			$out[] = $fb;
		}
		$tiktok = self::tiktok_url( (string) get_user_meta( $user_id, '_zseo_tiktok', true ) );
		if ( '' !== $tiktok ) {
			$out[] = $tiktok;
		}
		$fedi = trim( (string) get_user_meta( $user_id, '_zseo_fediverse', true ) );
		if ( 1 === preg_match( '/^@?([A-Za-z0-9_.]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$/', $fedi, $m ) ) {
			$out[] = 'https://' . $m[2] . '/@' . $m[1];
		}
		$web = esc_url_raw( (string) ( get_userdata( $user_id )->user_url ?? '' ), array( 'http', 'https' ) );
		if ( '' !== $web ) {
			$out[] = $web;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * A TikTok profile address from a username ("@name", "name") or an address someone pasted
	 * (zs-760); '' when it is neither. TikTok usernames: letters, digits, "_" and ".", up to 24.
	 *
	 * @param string $value What was typed.
	 * @return string
	 */
	public static function tiktok_url( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '~^(?:https?://)?(?:www\.|m\.)?tiktok\.com/@([A-Za-z0-9_.]{1,24})/?(?:[?#].*)?$~i', $value, $m ) ) {
			$value = $m[1];
		}
		$value = ltrim( $value, '@' );

		return 1 === preg_match( '/^[A-Za-z0-9_.]{1,24}$/', $value ) && '.' !== substr( $value, -1 ) ? 'https://www.tiktok.com/@' . $value : '';
	}

	/**
	 * The full Person node of a user (empty for an unknown user).
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>
	 */
	public static function node( int $user_id ): array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return array();
		}
		$node = array(
			'@type' => 'Person',
			'@id'   => self::id( $user_id ),
			'name'  => Html::plain( $user->display_name ),
			'url'   => get_author_posts_url( $user_id ),
		);
		$bio  = Html::plain( (string) get_user_meta( $user_id, 'description', true ) );
		if ( '' !== $bio ) {
			$node['description'] = $bio;
		}
		$image = get_avatar_url( $user_id, array( 'size' => 256 ) );
		if ( is_string( $image ) && '' !== $image ) {
			$node['image'] = $image;
		}
		$job = Html::plain( (string) get_user_meta( $user_id, self::JOB_TITLE, true ) );
		if ( '' !== $job ) {
			$node['jobTitle'] = $job;
		}
		$knows = self::lines( $user_id, self::KNOWS_ABOUT );
		if ( array() !== $knows ) {
			$node['knowsAbout'] = $knows;
		}
		$creds = self::lines( $user_id, self::CREDENTIALS );
		if ( array() !== $creds ) {
			$node['hasCredential'] = array_map(
				static fn( string $c ): array => array(
					'@type' => 'EducationalOccupationalCredential',
					'name'  => $c,
				),
				$creds
			);
		}
		$alumni = self::lines( $user_id, self::ALUMNI_OF );
		if ( array() !== $alumni ) {
			$node['alumniOf'] = array_map(
				static fn( string $s ): array => array(
					'@type' => 'EducationalOrganization',
					'name'  => $s,
				),
				$alumni
			);
		}
		$awards = self::lines( $user_id, self::AWARDS );
		if ( array() !== $awards ) {
			$node['award'] = $awards;
		}
		$same = self::same_as( $user_id );
		if ( array() !== $same ) {
			$node['sameAs'] = $same;
		}

		/**
		 * Filters a person's node (author, reviewer, fact-checker).
		 *
		 * @param array<string, mixed> $node    Node.
		 * @param int                  $user_id User.
		 */
		return (array) apply_filters( 'zinn_seo_person', $node, $user_id );
	}
}
