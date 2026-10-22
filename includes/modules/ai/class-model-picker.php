<?php
/**
 * The model each AI feature uses, chosen per feature from the current models of each provider.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Ai;

use ZinnDigital\ZinnSEO\AiCore\Catalogue;
use ZinnDigital\ZinnSEO\AiCore\Models;
use ZinnDigital\ZinnSEO\AiCore\Registry;
use ZinnDigital\ZinnSEO\AiCore\Store;
use ZinnDigital\ZinnSEO\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ⚖️ Owner, 2026-10-13: *"in ALL of this where ai is used, they need to be able to select the model
 * from the latest versions … and keep up to date auto, so when a new model is released its added
 * and the oldest one removed"*.
 *
 * Each feature (the writer, spelling and tone, pictures) keeps its own choice, `provider:model`,
 * in the setting `ai_models`; empty means the site's own default for that kind of work (AI
 * settings). The list offered is read from the signed model catalogue the engine publishes, so a
 * model added there appears here with no plugin update and a model it retires disappears:
 *
 * - a model whose `status` is `retiring` is never offered, and a saved choice on one moves to the
 *   newest model of its family (`follow()`, with a notice in the picker);
 * - when the catalogue states `status` (AIM1: it keeps the newest two of each family), that is
 *   followed exactly; an older catalogue without it offers only the newest of each `family`;
 * - `capabilities` says what a model can do (`structured`, `vision`, `image_out`).
 *
 * ⭐ Every one of those fields is OPTIONAL, and the list degrades cleanly without it (the catalogue
 * gains them in stage AIM1): no `status` = every model is current; no `family` = no retire rule;
 * no `capabilities` = inferred from the older `structured` / `vision` flags. Image models, which
 * the catalogue does not list yet, come from the provider's own list of image models.
 *
 * Only the owner's OWN keys are listed: on Zinn® credits the model is chosen by Zinn® for each
 * job (one meter, one price), and the picker says so instead of offering a choice it cannot keep.
 */
final class Model_Picker {

	/** Features with a model of their own => the kind of work (catalogue task). */
	public const FEATURES = array(
		'writer'    => 'write',
		'proofread' => 'general',
		'image'     => 'image',
	);

	/**
	 * The current models of a list: retiring ones out, one per family (the newest).
	 *
	 * @param array<int, array<string, mixed>> $models Catalogue rows.
	 * @return array<int, array<string, mixed>> In the catalogue's order.
	 */
	public static function current( array $models ): array {
		// AIM1: a catalogue that states `status` has already decided which models of each family
		// stay on offer (the newest two); honour it exactly. Without it, keep the newest per family.
		foreach ( $models as $row ) {
			if ( isset( $row['status'] ) ) {
				return array_values( array_filter( $models, static fn( array $r ): bool => 'retiring' !== (string) ( $r['status'] ?? '' ) ) );
			}
		}
		$newest = array();
		foreach ( $models as $i => $row ) {
			if ( 'retiring' === (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$family = (string) ( $row['family'] ?? '' );
			if ( '' === $family ) {
				$newest[ 'solo:' . $i ] = $i;
				continue;
			}
			$held = $newest[ 'family:' . $family ] ?? null;
			if ( null === $held || strcmp( (string) ( $row['released'] ?? '' ), (string) ( $models[ $held ]['released'] ?? '' ) ) > 0 ) {
				$newest[ 'family:' . $family ] = $i;
			}
		}
		$keep = array_flip( array_values( $newest ) );

		return array_values( array_filter( $models, static fn( $row, $i ): bool => isset( $keep[ $i ] ), ARRAY_FILTER_USE_BOTH ) );
	}

	/**
	 * What a model can do: its `capabilities`, or inferred from the older flags.
	 *
	 * @param array<string, mixed> $row Catalogue row.
	 * @return array<int, string>
	 */
	public static function capabilities( array $row ): array {
		if ( isset( $row['capabilities'] ) && is_array( $row['capabilities'] ) ) {
			return array_values( array_map( 'strval', $row['capabilities'] ) );
		}
		$out = array( 'text' );
		if ( ! array_key_exists( 'structured', $row ) || ! empty( $row['structured'] ) ) {
			$out[] = 'structured';
		}
		if ( ! empty( $row['vision'] ) ) {
			$out[] = 'vision';
		}

		return $out;
	}

	/**
	 * The choices for a feature: `provider:model` values for every connected own-key provider.
	 *
	 * @param string $feature Feature id.
	 * @return array<int, array{value: string, provider: string, model: string, label: string, recommended: bool}>
	 */
	public static function options( string $feature ): array {
		if ( ! isset( self::FEATURES[ $feature ] ) || ! class_exists( Registry::class ) ) {
			return array();
		}
		$task = self::FEATURES[ $feature ];
		$out  = array();
		foreach ( array_keys( Registry::all() ) as $provider ) {
			if ( 'zinn' === $provider || ! Store::configured( $provider ) ) {
				continue;
			}
			$name = (string) ( Registry::get( $provider )['label'] ?? $provider );
			if ( 'image' === $task ) {
				foreach ( self::image_rows( $provider ) as $row ) {
					$out[] = self::option( $provider, $name, $row, false );
				}
				continue;
			}
			$best = Catalogue::recommended( $provider, $task )['best'] ?? '';
			foreach ( self::current( Catalogue::models( $provider ) ) as $row ) {
				if ( in_array( 'text', self::capabilities( $row ), true ) ) {
					$out[] = self::option( $provider, $name, $row, (string) ( $row['id'] ?? '' ) === $best );
				}
			}
			// Models the owner added by hand stay offered: they chose them on purpose.
			foreach ( Store::added_models( $provider ) as $row ) {
				$out[] = self::option( $provider, $name, $row, false );
			}
		}

		$seen = array();
		return array_values(
			array_filter(
				$out,
				static function ( array $o ) use ( &$seen ): bool {
					if ( '' === $o['model'] || isset( $seen[ $o['value'] ] ) ) {
						return false;
					}
					$seen[ $o['value'] ] = true;
					return true;
				}
			)
		);
	}

	/**
	 * Image models of a provider: the catalogue's `image_out` models when it has them, else the
	 * provider's own list.
	 *
	 * @param string $provider Provider id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function image_rows( string $provider ): array {
		$rows   = array_merge( Catalogue::image_models( $provider ), Catalogue::models( $provider ) );
		$listed = array_values( array_filter( self::current( $rows ), static fn( array $row ): bool => in_array( 'image_out', self::capabilities( $row ), true ) ) );
		if ( array() !== $listed ) {
			return $listed;
		}
		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter || ! $adapter->supports_images() ) {
			return array();
		}

		return Models::image_choices( $provider );
	}

	/**
	 * One choice.
	 *
	 * @param string               $provider    Provider id.
	 * @param string               $name        Provider name.
	 * @param array<string, mixed> $row         Model row.
	 * @param bool                 $recommended The catalogue's best for this work.
	 * @return array{value: string, provider: string, model: string, label: string, recommended: bool}
	 */
	private static function option( string $provider, string $name, array $row, bool $recommended ): array {
		$model = (string) ( $row['id'] ?? '' );

		return array(
			'value'       => $provider . ':' . $model,
			'provider'    => $provider,
			'model'       => $model,
			'label'       => $name . ' — ' . (string) ( $row['label'] ?? $model ),
			'recommended' => $recommended,
		);
	}

	/**
	 * The saved choice of a feature ('' = the site's default).
	 *
	 * @param string $feature Feature id.
	 * @return string `provider:model` or ''.
	 */
	public static function saved( string $feature ): string {
		$all = (array) Settings::get( 'ai_models', array() );

		return (string) ( $all[ $feature ]['choice'] ?? '' );
	}

	/**
	 * A saved choice on a model the catalogue retired moves to the newest model of the same
	 * family (owner, 2026-10-13: the oldest is removed, the choice is never just dropped). The
	 * move is remembered on the feature (`moved`) so the picker can tell the owner, until they
	 * choose again.
	 *
	 * @param string $feature Feature id.
	 * @return array{from: string, to: string}|null The move made now, or null.
	 */
	public static function follow( string $feature ): ?array {
		$choice = self::saved( $feature );
		if ( '' === $choice || false === strpos( $choice, ':' ) ) {
			return null;
		}
		list( $provider, $model ) = explode( ':', $choice, 2 );
		$next                     = Catalogue::successor( $provider, $model );
		if ( '' === $next ) {
			return null;
		}
		$all             = (array) Settings::get( 'ai_models', array() );
		$all[ $feature ] = array(
			'choice'     => $provider . ':' . $next,
			'moved_from' => $model,
			'moved_to'   => $next,
		);
		if ( is_wp_error( Settings::save( array( 'ai_models' => $all ) ) ) ) {
			return null;
		}
		return array(
			'from' => $model,
			'to'   => $next,
		);
	}

	/**
	 * The last automatic move of a feature's model, until the owner chooses again.
	 *
	 * @param string $feature Feature id.
	 * @return array{from: string, to: string}|null
	 */
	public static function moved( string $feature ): ?array {
		$all  = (array) Settings::get( 'ai_models', array() );
		$from = (string) ( $all[ $feature ]['moved_from'] ?? '' );
		$to   = (string) ( $all[ $feature ]['moved_to'] ?? '' );
		return '' !== $from && '' !== $to ? array(
			'from' => $from,
			'to'   => $to,
		) : null;
	}

	/**
	 * The provider and model to send a feature's request to: the person's choice for this run
	 * when it is one of the current options, else the feature's saved choice when it still is,
	 * else nothing (the site's default decides). A choice that dropped out of the catalogue falls
	 * back to the default rather than to a model that no longer exists.
	 *
	 * @param string $feature Feature id.
	 * @param string $wanted  This run's choice, `provider:model`, or ''.
	 * @return array{provider?: string, model?: string}
	 */
	public static function route( string $feature, string $wanted = '' ): array {
		self::follow( $feature );
		$options = null;
		foreach ( array( $wanted, self::saved( $feature ) ) as $choice ) {
			if ( '' === $choice ) {
				continue;
			}
			$options = $options ?? self::options( $feature );
			foreach ( $options as $o ) {
				if ( $o['value'] === $choice ) {
					return array(
						'provider' => $o['provider'],
						'model'    => $o['model'],
					);
				}
			}
		}

		return array();
	}
}
