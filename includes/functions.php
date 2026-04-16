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

/**
 * Format bytes into a human-readable string.
 *
 * @param int $bytes Byte count.
 * @return string Formatted string (e.g. "4.2 GB", "127 MB").
 */
function ewpm_format_bytes( int $bytes ): string {
	if ( 0 === $bytes ) {
		return '0 B';
	}

	$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	$power = (int) floor( log( $bytes, 1024 ) );
	$power = min( $power, count( $units ) - 1 );

	return round( $bytes / pow( 1024, $power ), 2 ) . ' ' . $units[ $power ];
}

/**
 * Generate a sanitized backup filename.
 *
 * Produces a filename like "sitename-20260417-143022.ezmig".
 *
 * @param string $base Optional custom base name. Defaults to the site name.
 * @return string Sanitized filename with extension.
 */
function ewpm_generate_backup_filename( string $base = '' ): string {
	if ( empty( $base ) ) {
		$base = sanitize_title( get_bloginfo( 'name' ) );

		if ( empty( $base ) ) {
			$base = 'wordpress';
		}
	}

	$base = sanitize_file_name( $base );
	$date = gmdate( 'Ymd-His' );

	return $base . '-' . $date . '.' . EWPM_ARCHIVE_EXTENSION;
}
