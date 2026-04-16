<?php
/**
 * Plugin Name: Easy WP Migration
 * Plugin URI:  https://wordpress.org/plugins/easy-wp-migration/
 * Description: Lightweight site migration and backup tool. Export, import, pull from URL, and manage server-side backups.
 * Version:     0.4.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author:      DotClick LLC
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-wp-migration
 * Domain Path: /languages
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'EWPM_VERSION', '0.4.0' );
define( 'EWPM_PLUGIN_FILE', __FILE__ );
define( 'EWPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EWPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EWPM_ARCHIVE_EXTENSION', 'ezmig' );

/**
 * Dev-only constants. Users may override in wp-config.php:
 *   define( 'EWPM_DEV_MODE', true );
 *   define( 'EWPM_TICK_BUDGET_SECONDS', 10 );
 */
if ( ! defined( 'EWPM_DEV_MODE' ) ) {
	define( 'EWPM_DEV_MODE', false );
}
if ( ! defined( 'EWPM_TICK_BUDGET_SECONDS' ) ) {
	define( 'EWPM_TICK_BUDGET_SECONDS', 20 );
}

/**
 * PSR-4-ish autoloader for EWPM_ prefixed classes, interfaces, and abstracts.
 *
 * Maps EWPM_Plugin             → includes/class-plugin.php
 * Maps EWPM_Archiver_Interface → includes/interface-archiver.php
 * Maps EWPM_Job                → includes/abstract-job.php
 */
spl_autoload_register( function ( string $class ): void {
	$prefix = 'EWPM_';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );

	// Interface convention: EWPM_Foo_Interface → interface-foo.php.
	if ( str_ends_with( $relative, '_Interface' ) ) {
		$name     = substr( $relative, 0, -10 );
		$filename = 'interface-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$filepath = EWPM_PLUGIN_DIR . 'includes/' . $filename;
		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
		return;
	}

	// Try class- prefix first, then abstract- as fallback.
	$slug = strtolower( str_replace( '_', '-', $relative ) );

	foreach ( [ 'class-', 'abstract-' ] as $file_prefix ) {
		$filepath = EWPM_PLUGIN_DIR . 'includes/' . $file_prefix . $slug . '.php';
		if ( file_exists( $filepath ) ) {
			require_once $filepath;
			return;
		}
	}
} );

/**
 * Load helper functions.
 */
require_once EWPM_PLUGIN_DIR . 'includes/functions.php';

/**
 * Activation hook: create storage directories and protective files.
 */
register_activation_hook( __FILE__, function (): void {
	$storage_dir = ewpm_get_storage_dir();
	$backups_dir = ewpm_get_backups_dir();
	$tmp_dir     = ewpm_get_tmp_dir();

	foreach ( [ $storage_dir, $backups_dir, $tmp_dir ] as $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	// Protect storage root with .htaccess.
	$htaccess = $storage_dir . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	// Silent index.php in each subfolder.
	foreach ( [ $backups_dir, $tmp_dir ] as $dir ) {
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
} );

/**
 * Deactivation hook: stub only — no cleanup so users can safely reactivate.
 */
register_deactivation_hook( __FILE__, function (): void {
	// Intentionally empty. Users may reactivate; data is preserved.
} );

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function (): void {
	EWPM_Plugin::instance();
} );
