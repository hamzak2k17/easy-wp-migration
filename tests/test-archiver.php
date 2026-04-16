<?php
/**
 * Manual test script for the archiver layer.
 *
 * Run from CLI:   php tests/test-archiver.php
 *
 * Attempts to load WordPress. If WP is not available (e.g. running outside
 * a WP install), defines minimal stubs so the archiver classes can be
 * exercised standalone.
 *
 * @package EasyWPMigration
 */

// ──────────────────────────────────────────────────────────────────────
// Bootstrap: try WP, fall back to stubs.
// ──────────────────────────────────────────────────────────────────────

$wp_load_paths = [
	dirname( __DIR__, 4 ) . '/wp-load.php',   // Standard: wp-content/plugins/easy-wp-migration/tests/
	dirname( __DIR__, 3 ) . '/wp-load.php',   // One level up.
];

$wp_loaded = false;

foreach ( $wp_load_paths as $wp_load ) {
	if ( file_exists( $wp_load ) ) {
		require_once $wp_load;
		$wp_loaded = true;
		break;
	}
}

if ( ! $wp_loaded ) {
	// Define minimal stubs for standalone execution.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/fake-wp/' );
	}

	if ( ! defined( 'EWPM_VERSION' ) ) {
		define( 'EWPM_VERSION', '1.0.0' );
	}

	if ( ! defined( 'EWPM_PLUGIN_DIR' ) ) {
		define( 'EWPM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}

	if ( ! defined( 'EWPM_ARCHIVE_EXTENSION' ) ) {
		define( 'EWPM_ARCHIVE_EXTENSION', 'ezmig' );
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $key, $default = false ) {
			return match ( $key ) {
				'siteurl' => 'http://localhost',
				default   => $default,
			};
		}
	}

	if ( ! function_exists( 'get_home_url' ) ) {
		function get_home_url() {
			return 'http://localhost';
		}
	}

	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( string $show ) {
			return match ( $show ) {
				'version' => '6.5.0',
				default   => '',
			};
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $target ): bool {
			return mkdir( $target, 0755, true );
		}
	}

	// Minimal $wpdb stub.
	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';

			public function db_version(): string {
				return '8.0.0';
			}
		};
	}

	// Load plugin files manually.
	require_once EWPM_PLUGIN_DIR . 'includes/functions.php';
	require_once EWPM_PLUGIN_DIR . 'includes/interface-archiver.php';
	require_once EWPM_PLUGIN_DIR . 'includes/class-archiver-exception.php';
	require_once EWPM_PLUGIN_DIR . 'includes/class-archiver-metadata.php';
	require_once EWPM_PLUGIN_DIR . 'includes/class-archiver-zip.php';
	require_once EWPM_PLUGIN_DIR . 'includes/class-archiver-factory.php';
}

// ──────────────────────────────────────────────────────────────────────
// Test helpers.
// ──────────────────────────────────────────────────────────────────────

$test_count  = 0;
$pass_count  = 0;
$fail_reason = '';

/**
 * Assert a condition is true.
 */
function assert_true( bool $condition, string $label ): void {
	global $test_count, $pass_count, $fail_reason;
	++$test_count;

	if ( $condition ) {
		++$pass_count;
		echo "  PASS: {$label}\n";
	} else {
		$fail_reason = $label;
		echo "  FAIL: {$label}\n";
	}
}

/**
 * Assert two values are equal.
 */
function assert_equal( mixed $expected, mixed $actual, string $label ): void {
	assert_true( $expected === $actual, $label . " (expected: " . var_export( $expected, true ) . ", got: " . var_export( $actual, true ) . ")" );
}

// ──────────────────────────────────────────────────────────────────────
// Tests.
// ──────────────────────────────────────────────────────────────────────

$tmp_dir      = sys_get_temp_dir() . '/ewpm-test-' . uniqid();
$archive_path = $tmp_dir . '/test-archive.' . EWPM_ARCHIVE_EXTENSION;

mkdir( $tmp_dir, 0755, true );

echo "\n=== Easy WP Migration — Archiver Test Suite ===\n\n";

// ── Test 1: Factory creates a zip archiver ──────────────────────────

echo "[1] Factory creates EWPM_Archiver_Zip\n";
$archiver = EWPM_Archiver_Factory::create( 'zip' );
assert_true( $archiver instanceof EWPM_Archiver_Interface, 'Factory returns EWPM_Archiver_Interface' );
assert_true( $archiver instanceof EWPM_Archiver_Zip, 'Factory returns EWPM_Archiver_Zip' );

// ── Test 2: Factory rejects unknown format ──────────────────────────

echo "\n[2] Factory rejects unknown format\n";
try {
	EWPM_Archiver_Factory::create( 'rar' );
	assert_true( false, 'Should have thrown EWPM_Archiver_Exception' );
} catch ( EWPM_Archiver_Exception $e ) {
	assert_true( str_contains( $e->getMessage(), 'Unsupported archive format' ), 'Throws for unsupported format' );
}

// ── Test 3: Write an archive ────────────────────────────────────────

echo "\n[3] Write archive with string and file entries\n";
$archiver = EWPM_Archiver_Factory::create();
$archiver->open_for_write( $archive_path );

$archiver->add_string( 'database.sql', "CREATE TABLE test;\nINSERT INTO test VALUES (1);\n" );
$archiver->add_string( 'config/settings.json', '{"key": "value"}' );
$archiver->add_file( 'files/test-archiver.php', __FILE__ );

$write_metadata = $archiver->get_metadata();
assert_true( is_array( $write_metadata ), 'get_metadata() returns array during write' );
assert_equal( '1.0', $write_metadata['format_version'], 'format_version is 1.0' );

$archiver->close();
assert_true( file_exists( $archive_path ), 'Archive file exists on disk' );

// ── Test 4: Read the archive back ───────────────────────────────────

echo "\n[4] Read archive and list entries\n";
$reader = EWPM_Archiver_Factory::create();
$reader->open_for_read( $archive_path );

$entries = $reader->list_entries();
echo "  Entries found: " . count( $entries ) . "\n";

foreach ( $entries as $entry ) {
	echo "    - {$entry['path']} ({$entry['size']} bytes)\n";
}

// 4 entries: metadata.json, database.sql, config/settings.json, files/test-archiver.php.
assert_equal( 4, count( $entries ), 'Archive contains 4 entries' );

$entry_paths = array_column( $entries, 'path' );
assert_true( in_array( 'metadata.json', $entry_paths, true ), 'metadata.json present' );
assert_true( in_array( 'database.sql', $entry_paths, true ), 'database.sql present' );
assert_true( in_array( 'config/settings.json', $entry_paths, true ), 'config/settings.json present' );
assert_true( in_array( 'files/test-archiver.php', $entry_paths, true ), 'files/test-archiver.php present' );

// ── Test 5: Extract and verify metadata ─────────────────────────────

echo "\n[5] Extract and verify metadata\n";
$metadata = $reader->get_metadata();
echo "  Metadata:\n";
echo "    format_version: {$metadata['format_version']}\n";
echo "    plugin_version: {$metadata['plugin_version']}\n";
echo "    created_at:     {$metadata['created_at']}\n";
echo "    source.site_url: {$metadata['source']['site_url']}\n";
echo "    stats.total_files: {$metadata['stats']['total_files']}\n";
echo "    stats.total_bytes: {$metadata['stats']['total_bytes']}\n";

assert_true( EWPM_Archiver_Metadata::validate( $metadata ), 'Metadata passes validation' );
assert_equal( '1.0', $metadata['format_version'], 'format_version correct' );
assert_equal( EWPM_VERSION, $metadata['plugin_version'], 'plugin_version correct' );
assert_equal( 3, $metadata['stats']['total_files'], 'stats.total_files is 3 (excludes metadata.json itself)' );
assert_true( $metadata['stats']['total_bytes'] > 0, 'stats.total_bytes > 0' );

// ── Test 6: Extract string content ──────────────────────────────────

echo "\n[6] Extract string content\n";
$sql = $reader->extract_string( 'database.sql' );
assert_true( str_contains( $sql, 'CREATE TABLE test' ), 'database.sql content matches' );

$settings = $reader->extract_string( 'config/settings.json' );
assert_equal( '{"key": "value"}', $settings, 'config/settings.json content matches' );

// ── Test 7: Extract file to disk ────────────────────────────────────

echo "\n[7] Extract file to disk and verify\n";
$extract_dest = $tmp_dir . '/extracted-test.php';
$reader->extract_file( 'files/test-archiver.php', $extract_dest );

assert_true( file_exists( $extract_dest ), 'Extracted file exists on disk' );
assert_equal(
	file_get_contents( __FILE__ ),
	file_get_contents( $extract_dest ),
	'Extracted file content matches original'
);

$reader->close();

// ── Test 8: Opening invalid archive ─────────────────────────────────

echo "\n[8] Reject archive without metadata.json\n";
$bad_path = $tmp_dir . '/bad-archive.zip';
$bad_zip  = new \ZipArchive();
$bad_zip->open( $bad_path, \ZipArchive::CREATE );
$bad_zip->addFromString( 'dummy.txt', 'hello' );
$bad_zip->close();

try {
	$bad_reader = EWPM_Archiver_Factory::create();
	$bad_reader->open_for_read( $bad_path );
	assert_true( false, 'Should have thrown for missing metadata.json' );
} catch ( EWPM_Archiver_Exception $e ) {
	assert_true( str_contains( $e->getMessage(), 'Invalid or corrupted' ), 'Throws for missing metadata.json' );
}

// ── Test 9: Mode enforcement ────────────────────────────────────────

echo "\n[9] Mode enforcement\n";
$mode_archiver = EWPM_Archiver_Factory::create();
$mode_archiver->open_for_read( $archive_path );

try {
	$mode_archiver->add_string( 'test.txt', 'data' );
	assert_true( false, 'Should have thrown for write on read-mode archive' );
} catch ( EWPM_Archiver_Exception $e ) {
	assert_true( str_contains( $e->getMessage(), 'not write' ), 'Throws when writing to read-mode archive' );
}

$mode_archiver->close();

// ── Test 10: Metadata validation ────────────────────────────────────

echo "\n[10] Metadata validation\n";
assert_true( EWPM_Archiver_Metadata::validate( $metadata ), 'Valid metadata passes' );
assert_true( ! EWPM_Archiver_Metadata::validate( [] ), 'Empty array fails validation' );
assert_true( ! EWPM_Archiver_Metadata::validate( [ 'format_version' => '1.0' ] ), 'Incomplete metadata fails' );

$bad_meta = $metadata;
unset( $bad_meta['source'] );
assert_true( ! EWPM_Archiver_Metadata::validate( $bad_meta ), 'Missing source fails validation' );

// ──────────────────────────────────────────────────────────────────────
// Cleanup and summary.
// ──────────────────────────────────────────────────────────────────────

// Clean up temp files.
array_map( 'unlink', glob( $tmp_dir . '/*' ) );

// Also clean nested dirs if any.
$nested = glob( $tmp_dir . '/*/*' );
if ( $nested ) {
	array_map( 'unlink', $nested );
	array_map( 'rmdir', glob( $tmp_dir . '/*', GLOB_ONLYDIR ) );
}

rmdir( $tmp_dir );

echo "\n──────────────────────────────────────\n";
echo "Results: {$pass_count}/{$test_count} assertions passed.\n";

if ( $pass_count === $test_count ) {
	echo "\nALL TESTS PASSED\n\n";
	exit( 0 );
} else {
	echo "\nTEST FAILED: {$fail_reason}\n\n";
	exit( 1 );
}
