<?php
/**
 * Manual test script for the database exporter.
 *
 * Run from CLI inside a WordPress installation:
 *   php tests/test-db-exporter.php
 *
 * Requires WordPress loaded (needs $wpdb).
 *
 * @package EasyWPMigration
 */

// ──────────────────────────────────────────────────────────────────────
// Bootstrap WordPress.
// ──────────────────────────────────────────────────────────────────────

$wp_load_paths = [
	dirname( __DIR__, 4 ) . '/wp-load.php',
	dirname( __DIR__, 3 ) . '/wp-load.php',
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
	echo "ERROR: Could not find wp-load.php. Run this script from within a WordPress installation.\n";
	echo "Expected paths:\n";
	foreach ( $wp_load_paths as $p ) {
		echo "  {$p}\n";
	}
	exit( 1 );
}

// ──────────────────────────────────────────────────────────────────────
// Test helpers.
// ──────────────────────────────────────────────────────────────────────

$test_count  = 0;
$pass_count  = 0;
$fail_reason = '';

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

function assert_equal( mixed $expected, mixed $actual, string $label ): void {
	assert_true(
		$expected === $actual,
		$label . ' (expected: ' . var_export( $expected, true ) . ', got: ' . var_export( $actual, true ) . ')'
	);
}

// ──────────────────────────────────────────────────────────────────────
// Setup: create test tables.
// ──────────────────────────────────────────────────────────────────────

global $wpdb;

$prefix     = $wpdb->prefix;
$table_pk   = $prefix . 'ewpm_test_pk';
$table_nopk = $prefix . 'ewpm_test_nopk';
$table_comp = $prefix . 'ewpm_test_composite';

echo "\n=== Easy WP Migration — DB Exporter Test Suite ===\n\n";
echo "[Setup] Creating test tables...\n";

// Table with numeric PK.
$wpdb->query( "DROP TABLE IF EXISTS `{$table_pk}`" ); // phpcs:ignore
$wpdb->query( "CREATE TABLE `{$table_pk}` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(255) NOT NULL DEFAULT '',
	`value` TEXT,
	`score` FLOAT DEFAULT NULL,
	`data` BLOB,
	`created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore

// Table with no PK.
$wpdb->query( "DROP TABLE IF EXISTS `{$table_nopk}`" ); // phpcs:ignore
$wpdb->query( "CREATE TABLE `{$table_nopk}` (
	`key_name` VARCHAR(100) NOT NULL,
	`key_value` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore

// Table with composite PK.
$wpdb->query( "DROP TABLE IF EXISTS `{$table_comp}`" ); // phpcs:ignore
$wpdb->query( "CREATE TABLE `{$table_comp}` (
	`group_id` INT UNSIGNED NOT NULL,
	`item_id` INT UNSIGNED NOT NULL,
	`label` VARCHAR(100) DEFAULT '',
	PRIMARY KEY (`group_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ); // phpcs:ignore

// Insert test data including edge cases.
$wpdb->query( "INSERT INTO `{$table_pk}` (`name`, `value`, `score`, `data`) VALUES
	('normal', 'hello world', 3.14, NULL),
	('with_null', NULL, NULL, NULL),
	('multibyte', '日本語テスト 🎉', 0, NULL),
	('quotes', 'it\\'s a \"test\"', -1.5, NULL),
	('backslash', 'path\\\\to\\\\file', 0, 0x48656C6C6F),
	('newlines', 'line1\\nline2\\ttab', 999.999, NULL),
	('empty', '', 0, '')" ); // phpcs:ignore

// Large text row.
$large_text = str_repeat( 'A', 100000 );
$wpdb->insert( $table_pk, [
	'name'  => 'large_text',
	'value' => $large_text,
	'score' => 42.0,
	'data'  => null,
] ); // phpcs:ignore

$wpdb->query( "INSERT INTO `{$table_nopk}` (`key_name`, `key_value`) VALUES
	('setting_a', 'value_a'),
	('setting_b', NULL),
	('setting_c', '')" ); // phpcs:ignore

$wpdb->query( "INSERT INTO `{$table_comp}` (`group_id`, `item_id`, `label`) VALUES
	(1, 1, 'first'),
	(1, 2, 'second'),
	(2, 1, 'third')" ); // phpcs:ignore

echo "  Test tables created with sample data.\n\n";

// ──────────────────────────────────────────────────────────────────────
// Tests.
// ──────────────────────────────────────────────────────────────────────

$output_dir  = ewpm_get_tmp_dir();
$output_path = $output_dir . 'test-db-export-' . uniqid() . '.sql';

if ( ! is_dir( $output_dir ) ) {
	wp_mkdir_p( $output_dir );
}

// ── Test 1: Table list ──────────────────────────────────────────────

echo "[1] Table list detection\n";
$exporter = new EWPM_DB_Exporter( $wpdb, $output_path );
$result   = $exporter->get_table_list();
$tables   = $result['tables'];

assert_true( count( $tables ) >= 3, 'At least 3 tables found (test + WP core)' );

$test_table_names = array_column( $tables, 'name' );
assert_true( in_array( $table_pk, $test_table_names, true ), 'Test PK table found' );
assert_true( in_array( $table_nopk, $test_table_names, true ), 'Test no-PK table found' );
assert_true( in_array( $table_comp, $test_table_names, true ), 'Test composite PK table found' );

// Check PK detection.
$pk_table_info = null;
$nopk_table_info = null;
$comp_table_info = null;

foreach ( $tables as $t ) {
	if ( $t['name'] === $table_pk ) {
		$pk_table_info = $t;
	}
	if ( $t['name'] === $table_nopk ) {
		$nopk_table_info = $t;
	}
	if ( $t['name'] === $table_comp ) {
		$comp_table_info = $t;
	}
}

assert_true( true === $pk_table_info['has_numeric_pk'], 'PK table detected as has_numeric_pk' );
assert_equal( 'id', $pk_table_info['pk_column'], 'PK column is "id"' );
assert_true( false === $nopk_table_info['has_numeric_pk'], 'No-PK table detected correctly' );
assert_true( false === $comp_table_info['has_numeric_pk'], 'Composite PK not treated as numeric PK' );

// ── Test 2: Value escaping ──────────────────────────────────────────

echo "\n[2] Value escaping\n";
assert_equal( 'NULL', $exporter->escape_value( null ), 'NULL escaping' );
assert_equal( '1', $exporter->escape_value( true ), 'Boolean true' );
assert_equal( '0', $exporter->escape_value( false ), 'Boolean false' );
assert_equal( '42', $exporter->escape_value( 42 ), 'Integer' );
assert_equal( '3.14', $exporter->escape_value( 3.14 ), 'Float' );
assert_equal( 'NULL', $exporter->escape_value( NAN ), 'NaN → NULL' );
assert_equal( 'NULL', $exporter->escape_value( INF ), 'INF → NULL' );
assert_true( str_starts_with( $exporter->escape_value( "hello", 'blob' ), '0x' ), 'Binary → hex' );
assert_true( str_starts_with( $exporter->escape_value( "test" ), "'" ), 'String quoted' );

// ── Test 3: Full export ─────────────────────────────────────────────

echo "\n[3] Full export to SQL file\n";
$exporter->write_header();

// Export just our test tables.
foreach ( [ $table_pk, $table_nopk, $table_comp ] as $tbl ) {
	$exporter->write_table_structure( $tbl );

	$cursor = [ 'last_id' => null, 'offset' => 0, 'rows_written' => 0 ];

	while ( true ) {
		$chunk_result = $exporter->write_table_rows( $tbl, $cursor, 3, 30 );
		$cursor       = $chunk_result;

		if ( $chunk_result['done'] ) {
			break;
		}
	}
}

$exporter->write_footer();

assert_true( file_exists( $output_path ), 'SQL file created' );

$sql_content = file_get_contents( $output_path ); // phpcs:ignore
$sql_size    = strlen( $sql_content );

echo "  SQL file size: {$sql_size} bytes\n";

assert_true( $sql_size > 0, 'SQL file is not empty' );
assert_true( str_contains( $sql_content, 'SET NAMES' ), 'Header contains SET NAMES' );
assert_true( str_contains( $sql_content, 'SET FOREIGN_KEY_CHECKS = 0' ), 'FK checks disabled at start' );
assert_true( str_contains( $sql_content, 'SET FOREIGN_KEY_CHECKS = 1' ), 'FK checks re-enabled at end' );
assert_true( str_contains( $sql_content, "DROP TABLE IF EXISTS `{$table_pk}`" ), 'DROP TABLE for PK table' );
assert_true( str_contains( $sql_content, "CREATE TABLE `{$table_pk}`" ), 'CREATE TABLE for PK table' );
assert_true( str_contains( $sql_content, "INSERT INTO `{$table_pk}`" ), 'INSERT for PK table' );
assert_true( str_contains( $sql_content, "INSERT INTO `{$table_nopk}`" ), 'INSERT for no-PK table' );
assert_true( str_contains( $sql_content, "INSERT INTO `{$table_comp}`" ), 'INSERT for composite PK table' );

// Verify row counts.
$pk_inserts   = substr_count( $sql_content, "INSERT INTO `{$table_pk}`" );
$nopk_inserts = substr_count( $sql_content, "INSERT INTO `{$table_nopk}`" );
$comp_inserts = substr_count( $sql_content, "INSERT INTO `{$table_comp}`" );

assert_equal( 8, $pk_inserts, 'PK table has 8 INSERT statements' );
assert_equal( 3, $nopk_inserts, 'No-PK table has 3 INSERT statements' );
assert_equal( 3, $comp_inserts, 'Composite PK table has 3 INSERT statements' );

// Verify edge cases in output.
assert_true( str_contains( $sql_content, 'NULL' ), 'NULL values present in output' );
assert_true( str_contains( $sql_content, '日本語テスト' ), 'Multibyte UTF-8 preserved' );

// ── Test 4: Resume correctness ──────────────────────────────────────

echo "\n[4] Resume correctness (chunked with small chunk size)\n";
$output_path2 = $output_dir . 'test-db-export-resume-' . uniqid() . '.sql';
$exporter2    = new EWPM_DB_Exporter( $wpdb, $output_path2 );
$exporter2->write_header();
$exporter2->write_table_structure( $table_pk );

// Export with chunk_size=2 to force multiple chunks.
$cursor2     = [ 'last_id' => null, 'offset' => 0, 'rows_written' => 0 ];
$chunk_count = 0;

while ( true ) {
	$chunk_result2 = $exporter2->write_table_rows( $table_pk, $cursor2, 2, 30 );
	$cursor2       = $chunk_result2;
	++$chunk_count;

	if ( $chunk_result2['done'] ) {
		break;
	}
}

$exporter2->write_footer();

$sql2       = file_get_contents( $output_path2 ); // phpcs:ignore
$pk_inserts2 = substr_count( $sql2, "INSERT INTO `{$table_pk}`" );

echo "  Chunks needed: {$chunk_count}\n";
assert_true( $chunk_count >= 4, 'Multiple chunks needed with chunk_size=2 for 8 rows' );
assert_equal( 8, $pk_inserts2, 'Chunked export produced exactly 8 rows (no duplicates, no skips)' );

// ── Test 5: Large row handling ──────────────────────────────────────

echo "\n[5] Large row handling\n";
assert_true( str_contains( $sql_content, str_repeat( 'A', 1000 ) ), 'Large text row present in output (spot check)' );

// ──────────────────────────────────────────────────────────────────────
// Cleanup.
// ──────────────────────────────────────────────────────────────────────

echo "\n[Cleanup] Dropping test tables and removing output files...\n";

$wpdb->query( "DROP TABLE IF EXISTS `{$table_pk}`" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS `{$table_nopk}`" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS `{$table_comp}`" ); // phpcs:ignore

@unlink( $output_path );
@unlink( $output_path2 );

// ──────────────────────────────────────────────────────────────────────
// Summary.
// ──────────────────────────────────────────────────────────────────────

echo "\n──────────────────────────────────────\n";
echo "Results: {$pass_count}/{$test_count} assertions passed.\n";

if ( $pass_count === $test_count ) {
	echo "\nALL TESTS PASSED\n\n";
	exit( 0 );
} else {
	echo "\nTEST FAILED: {$fail_reason}\n\n";
	exit( 1 );
}
