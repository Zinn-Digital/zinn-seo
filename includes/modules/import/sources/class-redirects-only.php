<?php
/**
 * A plugin that only holds redirects (Redirection, Safe Redirect Manager) as an import source.
 *
 * @package ZinnDigital\ZinnSEO
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnSEO\Import\Sources;

use ZinnDigital\ZinnSEO\Import\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules themselves are read by the redirects module's importer (its sources `redirection`
 * and `srm`); this class only lets the same move — count, copy, switch off, undo — cover a
 * redirects plugin, with no second reader of its tables.
 */
final class Redirects_Only extends Source {

	/**
	 * Id (also the redirects importer's source id).
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Main files.
	 *
	 * @var array<int, string>
	 */
	private array $plugins;

	/**
	 * Describe the plugin.
	 *
	 * @param string             $id      Id.
	 * @param string             $name    Name.
	 * @param array<int, string> $plugins Main files.
	 */
	public function __construct( string $id, string $name, array $plugins ) {
		$this->id      = $id;
		$this->name    = $name;
		$this->plugins = $plugins;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Main files.
	 *
	 * @return array<int, string>
	 */
	public function plugins(): array {
		return $this->plugins;
	}

	/**
	 * No page data.
	 *
	 * @return bool
	 */
	public function has_pages(): bool {
		return false;
	}

	/**
	 * Data on this site?
	 *
	 * @return bool
	 */
	public function present(): bool {
		$importer = '\ZinnDigital\ZinnSEO\Redirects\Importer';

		return class_exists( $importer ) && $importer::count( $this->id ) > 0;
	}

	/**
	 * Nothing per post.
	 *
	 * @param int $post_id Post.
	 * @return array<string, mixed>
	 */
	public function post( int $post_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- a redirects plugin holds nothing per post.
		return array();
	}

	/**
	 * The redirects importer's source.
	 *
	 * @return string
	 */
	public function redirects(): string {
		return $this->id;
	}

	/**
	 * It does not write robots.txt.
	 *
	 * @return bool
	 */
	protected function writes_robots(): bool {
		return false;
	}
}
