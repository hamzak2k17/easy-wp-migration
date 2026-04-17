<?php
/**
 * Creates a malicious .ezmig archive for path traversal testing.
 * Run once via web, then delete.
 */

$wp_load_paths = [
	dirname( __DIR__, 4 ) . '/wp-load.php',
	dirname( __DIR__, 3 ) . '/wp-load.php',
];

foreach ( $wp_load_paths as $p ) {
	if ( file_exists( $p ) ) {
		require_once $p;
		break;
	}
}

if ( ! defined( 'EWPM_ARCHIVE_EXTENSION' ) ) {
	die( 'WP not loaded' );
}

$dest = WP_CONTENT_DIR . '/easy-wp-migration-storage/backups/evil-test-archive.' . EWPM_ARCHIVE_EXTENSION;

$z = new ZipArchive();
$z->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE );

// Valid metadata.json
$meta = json_encode( [
	'format_version' => '1.0',
	'plugin_version' => EWPM_VERSION,
	'created_at'     => gmdate( 'c' ),
	'source'         => [
		'site_url'      => get_option( 'siteurl' ),
		'home_url'      => get_home_url(),
		'wp_version'    => get_bloginfo( 'version' ),
		'php_version'   => PHP_VERSION,
		'mysql_version' => '8.0.0',
		'abspath'       => ABSPATH,
		'table_prefix'  => 'wp_',
	],
	'components'     => [
		'database'         => false,
		'themes'           => false,
		'plugins'          => false,
		'media'            => false,
		'other_wp_content' => true,
	],
	'stats'          => [
		'total_files' => 3,
		'total_bytes' => 15,
		'db_tables'   => 0,
		'db_rows'     => 0,
	],
], JSON_PRETTY_PRINT );

$z->addFromString( 'metadata.json', $meta );

// Malicious entries
$z->addFromString( '../../../evil-test.txt', 'PWNED' );
$z->addFromString( 'wp-content/../../../../evil-test-2.txt', 'PWNED2' );

// One valid entry for contrast
$z->addFromString( 'wp-content/test-safe-file.txt', 'SAFE' );

$z->close();

echo "Created: {$dest}\n";
echo "Size: " . filesize( $dest ) . " bytes\n";
echo "Done.\n";
