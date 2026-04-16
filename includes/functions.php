<?php
/**
 * Helper functions for Easy WP Migration.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the root storage directory path.
 *
 * @return string Absolute path with trailing slash.
 */
function ewpm_get_storage_dir(): string {
	return WP_CONTENT_DIR . '/easy-wp-migration-storage/';
}

/**
 * Get the backups directory path.
 *
 * @return string Absolute path with trailing slash.
 */
function ewpm_get_backups_dir(): string {
	return ewpm_get_storage_dir() . 'backups/';
}

/**
 * Get the temporary working directory path.
 *
 * @return string Absolute path with trailing slash.
 */
function ewpm_get_tmp_dir(): string {
	return ewpm_get_storage_dir() . 'tmp/';
}

/**
 * Check whether the given hook suffix belongs to one of our plugin pages.
 *
 * @param string $hook_suffix The admin page hook suffix to test.
 * @return bool True if this is an Easy WP Migration page.
 */
function ewpm_is_plugin_page( string $hook_suffix ): bool {
	$plugin_pages = [
		'toplevel_page_ewpm-export',
		'easy-wp-migration_page_ewpm-import',
		'easy-wp-migration_page_ewpm-backups',
		'easy-wp-migration_page_ewpm-dev',
	];

	return in_array( $hook_suffix, $plugin_pages, true );
}
