<?php
/**
 * Export presets — component definitions and exclusion patterns.
 *
 * Defines what directories map to which archive paths, and reusable
 * exclusion preset bundles for common patterns (cache, backups, logs).
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Export_Presets
 *
 * Static utility for component and exclusion definitions.
 */
class EWPM_Export_Presets {

	/**
	 * Get the export component definitions.
	 *
	 * Each component maps to a directory path and an archive prefix.
	 * The 'other_wp_content' component covers everything in wp-content
	 * not already handled by themes/plugins/media.
	 *
	 * @return array<int, array{id: string, label: string, path: string, archive_prefix: string, default: bool, exclude_siblings: string[]}>
	 */
	public static function get_components(): array {
		return [
			[
				'id'               => 'database',
				'label'            => __( 'Database', 'easy-wp-migration' ),
				'path'             => '',
				'archive_prefix'   => '',
				'default'          => true,
				'exclude_siblings' => [],
			],
			[
				'id'               => 'themes',
				'label'            => __( 'Themes', 'easy-wp-migration' ),
				'path'             => WP_CONTENT_DIR . '/themes',
				'archive_prefix'   => 'wp-content/themes',
				'default'          => true,
				'exclude_siblings' => [],
			],
			[
				'id'               => 'plugins',
				'label'            => __( 'Plugins', 'easy-wp-migration' ),
				'path'             => WP_CONTENT_DIR . '/plugins',
				'archive_prefix'   => 'wp-content/plugins',
				'default'          => true,
				'exclude_siblings' => [],
			],
			[
				'id'               => 'media',
				'label'            => __( 'Media uploads', 'easy-wp-migration' ),
				'path'             => WP_CONTENT_DIR . '/uploads',
				'archive_prefix'   => 'wp-content/uploads',
				'default'          => true,
				'exclude_siblings' => [],
			],
			[
				'id'               => 'other_wp_content',
				'label'            => __( 'Other wp-content files', 'easy-wp-migration' ),
				'path'             => WP_CONTENT_DIR,
				'archive_prefix'   => 'wp-content',
				'default'          => true,
				'exclude_siblings' => [ 'themes', 'plugins', 'uploads' ],
			],
		];
	}

	/**
	 * Get exclusion preset groups.
	 *
	 * Each preset is a named group of glob patterns the user can toggle.
	 *
	 * @return array<int, array{id: string, label: string, patterns: string[], default: bool}>
	 */
	public static function get_exclusion_presets(): array {
		return [
			[
				'id'       => 'cache',
				'label'    => __( 'Cache folders', 'easy-wp-migration' ),
				'patterns' => [
					'**/cache/**',
					'**/wp-content/cache/**',
					'**/w3tc-cache/**',
					'**/litespeed/**',
					'**/wp-rocket-cache/**',
					'**/autoptimize_cache/**',
				],
				'default'  => true,
			],
			[
				'id'       => 'other_backups',
				'label'    => __( 'Other backup plugin folders', 'easy-wp-migration' ),
				'patterns' => [
					'**/updraft/**',
					'**/backwpup-*/**',
					'**/ai1wm-backups/**',
					'**/wpvivid-uploads/**',
					'**/wp-content/backups/**',
					'**/duplicator_pro/**',
					'**/duplicator/**',
				],
				'default'  => true,
			],
			[
				'id'       => 'logs',
				'label'    => __( 'Log files', 'easy-wp-migration' ),
				'patterns' => [
					'**/*.log',
					'**/debug.log',
					'**/error_log',
				],
				'default'  => false,
			],
			[
				'id'       => 'dev_files',
				'label'    => __( 'Development files', 'easy-wp-migration' ),
				'patterns' => [
					'**/node_modules/**',
					'**/.git/**',
					'**/.svn/**',
					'**/bower_components/**',
					'**/vendor/**/tests/**',
				],
				'default'  => false,
			],
		];
	}

	/**
	 * Get hardcoded forbidden exclusion patterns.
	 *
	 * These are always applied regardless of user settings.
	 *
	 * @return string[]
	 */
	public static function get_forbidden_patterns(): array {
		return [
			'**/easy-wp-migration-storage/**',
			'**/.git/**',
			'**/node_modules/**',
			'**/.svn/**',
			'**/.hg/**',
			'**/.DS_Store',
			'**/Thumbs.db',
			'**/*.swp',
			'**/.idea/**',
			'**/.vscode/**',
		];
	}

	/**
	 * Build the component roots list for the file scanner.
	 *
	 * Filters by selected components and builds exclusions for
	 * the other_wp_content component's sibling directories.
	 *
	 * @param array<string, bool> $selected Components map (id => bool).
	 * @return array{roots: array, sibling_exclusions: string[]}
	 */
	public static function build_scan_roots( array $selected ): array {
		$components         = self::get_components();
		$roots              = [];
		$sibling_exclusions = [];

		foreach ( $components as $comp ) {
			if ( 'database' === $comp['id'] ) {
				continue; // Database is not a file component.
			}

			if ( empty( $selected[ $comp['id'] ] ) ) {
				continue;
			}

			if ( empty( $comp['path'] ) || ! is_dir( $comp['path'] ) ) {
				continue;
			}

			$roots[] = [
				'path'           => $comp['path'],
				'archive_prefix' => $comp['archive_prefix'],
			];

			// Build exclusion patterns for other_wp_content siblings.
			if ( ! empty( $comp['exclude_siblings'] ) ) {
				foreach ( $comp['exclude_siblings'] as $sibling ) {
					// Only exclude siblings that are selected as their own component.
					if ( ! empty( $selected[ self::sibling_to_component_id( $sibling ) ] ) ) {
						$sibling_exclusions[] = $sibling . '/**';
					}
				}
			}
		}

		return [
			'roots'              => $roots,
			'sibling_exclusions' => $sibling_exclusions,
		];
	}

	/**
	 * Map a sibling directory name to its component ID.
	 *
	 * @param string $sibling Directory name (e.g. 'themes', 'plugins', 'uploads').
	 * @return string Component ID.
	 */
	private static function sibling_to_component_id( string $sibling ): string {
		return match ( $sibling ) {
			'uploads' => 'media',
			default   => $sibling,
		};
	}
}
