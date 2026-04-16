<?php
/**
 * Archiver metadata helper.
 *
 * Builds and validates the metadata.json structure stored inside every
 * .ezmig archive. This metadata describes the source environment and
 * what components are included in the export.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Archiver_Metadata
 *
 * Static helper for building and validating archive metadata.
 */
class EWPM_Archiver_Metadata {

	/**
	 * Default components included in an export.
	 */
	private const DEFAULT_COMPONENTS = [
		'database'         => true,
		'themes'           => true,
		'plugins'          => true,
		'media'            => true,
		'other_wp_content' => true,
	];

	/**
	 * Required top-level keys in a valid metadata structure.
	 */
	private const REQUIRED_KEYS = [
		'format_version',
		'plugin_version',
		'created_at',
		'source',
		'components',
		'stats',
	];

	/**
	 * Required keys inside the source block.
	 */
	private const REQUIRED_SOURCE_KEYS = [
		'site_url',
		'home_url',
		'wp_version',
		'php_version',
		'mysql_version',
		'abspath',
		'table_prefix',
	];

	/**
	 * Build a metadata array for a new export.
	 *
	 * @param array<string, bool> $components Which components to include. Keys not
	 *                                        present default to true.
	 * @return array<string, mixed> The complete metadata structure.
	 */
	public static function build_for_export( array $components = [] ): array {
		global $wpdb;

		$merged = array_merge( self::DEFAULT_COMPONENTS, $components );

		return [
			'format_version' => '1.0',
			'plugin_version' => EWPM_VERSION,
			'created_at'     => gmdate( 'c' ),
			'source'         => [
				'site_url'     => get_option( 'siteurl', '' ),
				'home_url'     => get_home_url(),
				'wp_version'   => get_bloginfo( 'version' ),
				'php_version'  => PHP_VERSION,
				'mysql_version' => $wpdb->db_version(),
				'abspath'      => ABSPATH,
				'table_prefix' => $wpdb->prefix,
			],
			'components'     => [
				'database'         => ! empty( $merged['database'] ),
				'themes'           => ! empty( $merged['themes'] ),
				'plugins'          => ! empty( $merged['plugins'] ),
				'media'            => ! empty( $merged['media'] ),
				'other_wp_content' => ! empty( $merged['other_wp_content'] ),
			],
			'stats'          => [
				'total_files' => 0,
				'total_bytes' => 0,
				'db_tables'   => 0,
				'db_rows'     => 0,
			],
		];
	}

	/**
	 * Validate that a metadata array has the required structure.
	 *
	 * Does not validate values, only that the expected keys exist and are
	 * of the correct type.
	 *
	 * @param array<string, mixed> $metadata The metadata to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate( array $metadata ): bool {
		// Check top-level keys.
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $metadata ) ) {
				return false;
			}
		}

		// format_version must be a string.
		if ( ! is_string( $metadata['format_version'] ) ) {
			return false;
		}

		// source must be an array with required keys.
		if ( ! is_array( $metadata['source'] ) ) {
			return false;
		}

		foreach ( self::REQUIRED_SOURCE_KEYS as $key ) {
			if ( ! array_key_exists( $key, $metadata['source'] ) ) {
				return false;
			}
		}

		// components must be an array.
		if ( ! is_array( $metadata['components'] ) ) {
			return false;
		}

		// stats must be an array.
		if ( ! is_array( $metadata['stats'] ) ) {
			return false;
		}

		return true;
	}
}
