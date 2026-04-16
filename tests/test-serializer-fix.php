<?php
/**
 * Unit tests for EWPM_Serializer_Fix.
 *
 * Run from CLI: php tests/test-serializer-fix.php
 * Does NOT require WordPress — standalone.
 *
 * @package EasyWPMigration
 */

// Bootstrap minimal stubs.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

require_once dirname( __DIR__ ) . '/includes/class-serializer-fix.php';

// ──────────────────────────────────────────────────────────────────────
// Test helpers.
// ──────────────────────────────────────────────────────────────────────

$test_count  = 0;
$pass_count  = 0;
$fail_reason = '';

function assert_equal( mixed $expected, mixed $actual, string $label ): void {
	global $test_count, $pass_count, $fail_reason;
	++$test_count;

	if ( $expected === $actual ) {
		++$pass_count;
		echo "  PASS: {$label}\n";
	} else {
		$fail_reason = $label;
		echo "  FAIL: {$label}\n";
		echo "    Expected: " . var_export( $expected, true ) . "\n";
		echo "    Got:      " . var_export( $actual, true ) . "\n";
	}
}

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

echo "\n=== EWPM_Serializer_Fix — Unit Test Suite ===\n\n";

$replacements = [ 'old.com' => 'newsite.com' ];

// ── Test 1: Simple scalar (plain string, no serialization) ──────────

echo "[1] Simple scalar replacement\n";
assert_equal(
	'Visit newsite.com today',
	EWPM_Serializer_Fix::replace( 'Visit old.com today', $replacements ),
	'Plain string replacement'
);

assert_equal(
	'',
	EWPM_Serializer_Fix::replace( '', $replacements ),
	'Empty string unchanged'
);

assert_equal(
	'no match here',
	EWPM_Serializer_Fix::replace( 'no match here', $replacements ),
	'No match unchanged'
);

// ── Test 2: Serialized string, same length ──────────────────────────

echo "\n[2] Serialized string — same length replacement\n";
$same_len = [ 'old.com' => 'new.com' ]; // Both 7 bytes.
$input    = 's:7:"old.com";';
$expected = 's:7:"new.com";';
assert_equal( $expected, EWPM_Serializer_Fix::replace( $input, $same_len ), 'Same length: s:7 stays s:7' );

// ── Test 3: Serialized string, length change ────────────────────────

echo "\n[3] Serialized string — length change\n";
$input    = 's:7:"old.com";';
$expected = 's:11:"newsite.com";';
assert_equal( $expected, EWPM_Serializer_Fix::replace( $input, $replacements ), 'Length change: s:7 → s:11' );

// ── Test 4: Serialized array ────────────────────────────────────────

echo "\n[4] Serialized array\n";
// a:2:{i:0;s:7:"old.com";i:1;s:5:"hello";}
$input    = 'a:2:{i:0;s:7:"old.com";i:1;s:5:"hello";}';
$result   = EWPM_Serializer_Fix::replace( $input, $replacements );
assert_true( str_contains( $result, 's:11:"newsite.com"' ), 'Array element replaced with correct length' );
assert_true( str_contains( $result, 's:5:"hello"' ), 'Non-matching element unchanged' );

// ── Test 5: Multibyte string ────────────────────────────────────────

echo "\n[5] Multibyte string\n";
// Ω is 2 bytes in UTF-8, so "Ωold.comΩ" = 2 + 7 + 2 = 11 bytes.
$mb_input = 's:11:"' . "\xCE\xA9" . 'old.com' . "\xCE\xA9" . '";';
$result   = EWPM_Serializer_Fix::replace( $mb_input, $replacements );
// "Ωnewsite.comΩ" = 2 + 11 + 2 = 15 bytes.
assert_equal( 's:15:"' . "\xCE\xA9" . 'newsite.com' . "\xCE\xA9" . '";', $result, 'Multibyte byte-length correct' );

// ── Test 6: Nested serialized data ──────────────────────────────────

echo "\n[6] Nested serialized data\n";
// Inner: s:7:"old.com" → should become s:11:"newsite.com"
// Outer wraps inner in a serialized string.
$inner    = 'a:1:{i:0;s:7:"old.com";}';
$inner_replaced = EWPM_Serializer_Fix::replace( $inner, $replacements );
// Verify inner replacement works.
assert_true( str_contains( $inner_replaced, 's:11:"newsite.com"' ), 'Inner replacement correct' );

// Now wrap inner in a serialized string: s:N:"<inner>";
$outer_len = strlen( $inner );
$outer     = 's:' . $outer_len . ':"' . $inner . '";';
$result    = EWPM_Serializer_Fix::replace( $outer, $replacements );
// The inner content should be replaced AND the outer length updated.
assert_true( str_contains( $result, 'newsite.com' ), 'Nested: replacement applied' );
// Verify the outer s:N matches the actual byte length of the new inner.
if ( preg_match( '/^s:(\d+):"/', $result, $m ) ) {
	$declared_len = (int) $m[1];
	$header_len   = strlen( $m[0] );
	$actual_value = substr( $result, $header_len, $declared_len );
	assert_equal( $declared_len, strlen( $actual_value ), 'Nested: outer byte-length matches content' );
} else {
	assert_true( false, 'Nested: could not parse outer s:N' );
}

// ── Test 7: Object serialization ────────────────────────────────────

echo "\n[7] Object serialization\n";
// O:8:"stdClass":1:{s:3:"url";s:7:"old.com";}
$input  = 'O:8:"stdClass":1:{s:3:"url";s:7:"old.com";}';
$result = EWPM_Serializer_Fix::replace( $input, $replacements );
assert_true( str_contains( $result, 's:11:"newsite.com"' ), 'Object property value replaced' );
assert_true( str_contains( $result, 's:3:"url"' ), 'Object property name unchanged' );

// ── Test 8: Mixed content ───────────────────────────────────────────

echo "\n[8] Mixed content (plain + serialized)\n";
// Simulates a WP option value that has serialized data embedded.
$input  = 'Visit old.com or see a:1:{i:0;s:7:"old.com";} for more at old.com';
$result = EWPM_Serializer_Fix::replace( $input, $replacements );
assert_true( str_contains( $result, 'Visit newsite.com' ), 'Plain text part replaced' );
assert_true( str_contains( $result, 's:11:"newsite.com"' ), 'Serialized part replaced with correct length' );
assert_true( str_contains( $result, 'more at newsite.com' ), 'Trailing plain text replaced' );

// ── Test 9: Multiple replacements ───────────────────────────────────

echo "\n[9] Multiple replacement pairs\n";
$multi = [
	'http://old.com'  => 'https://newsite.com',
	'http%3A%2F%2Fold.com' => 'https%3A%2F%2Fnewsite.com',
];
$input  = 's:14:"http://old.com";';
$result = EWPM_Serializer_Fix::replace( $input, $multi );
assert_equal( 's:19:"https://newsite.com";', $result, 'URL replacement with length update' );

$input2  = 's:20:"http%3A%2F%2Fold.com";';
$result2 = EWPM_Serializer_Fix::replace( $input2, $multi );
assert_equal( 's:25:"https%3A%2F%2Fnewsite.com";', $result2, 'URL-encoded replacement with length update' );

// ── Test 10: Real WP options-like value ─────────────────────────────

echo "\n[10] Real WP options-like serialized value\n";
// Simulates widget_text option.
$widget = serialize( [
	2 => [
		'title' => 'My Widget',
		'text'  => '<a href="http://old.com">Visit old.com</a>',
	],
	'_multiwidget' => 1,
] );
$result = EWPM_Serializer_Fix::replace( $widget, [ 'http://old.com' => 'https://newsite.com', 'old.com' => 'newsite.com' ] );
$decoded = @unserialize( $result );
assert_true( false !== $decoded, 'Result is valid serialized data' );
if ( is_array( $decoded ) ) {
	assert_true(
		str_contains( $decoded[2]['text'], 'https://newsite.com' ),
		'Widget text URL replaced'
	);
	assert_true(
		! str_contains( $decoded[2]['text'], 'old.com' ),
		'No old.com remnants in widget text'
	);
	assert_equal( 'My Widget', $decoded[2]['title'], 'Non-matching field unchanged' );
} else {
	assert_true( false, 'Decoded result is not an array' );
}

// ── Test 11: Malformed serialization ────────────────────────────────

echo "\n[11] Malformed serialization (safety)\n";
// Truncated serialized string — should not crash.
$input  = 's:100:"short";';
$result = EWPM_Serializer_Fix::replace( $input, $replacements );
// Should return something without crashing. Exact output depends on fallback.
assert_true( is_string( $result ), 'Malformed data does not crash' );

// ── Test 12: No false positives ─────────────────────────────────────

echo "\n[12] No false positives on non-serialized 's:' patterns\n";
$input  = 'This uses CSS: font-size: 12px; color: red;';
$result = EWPM_Serializer_Fix::replace( $input, $replacements );
assert_equal( $input, $result, 'CSS-like content unchanged (no match)' );

$input2  = 'keys:values are not serialized';
$result2 = EWPM_Serializer_Fix::replace( $input2, [ 'values' => 'REPLACED' ] );
assert_true( str_contains( $result2, 'REPLACED' ), 'Plain replacement still works in non-serialized' );

// ──────────────────────────────────────────────────────────────────────
// Summary.
// ──────────────────────────────────────────────────────────────────────

// ── Test 13: Live DB serialized option (WP-dependent) ───────────────

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

if ( $wp_loaded ) {
	echo "\n[13] Live DB serialized option (WP loaded)\n";
	global $wpdb;

	$site_url = get_option( 'siteurl' );
	echo "  Site URL: {$site_url}\n";

	// Find a serialized option containing the site URL.
	// phpcs:ignore
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE %s AND option_value LIKE %s LIMIT 1",
			'%s:%',
			'%' . $wpdb->esc_like( $site_url ) . '%'
		),
		ARRAY_A
	);

	if ( $row ) {
		$option_name = $row['option_name'];
		$raw_before  = $row['option_value'];
		echo "  Found option: {$option_name}\n";
		echo "  Raw value (first 300 chars): " . substr( $raw_before, 0, 300 ) . "\n";

		// Simulate cross-site replacement.
		$fake_new_url = 'https://new-destination-site.example';
		$result_raw   = EWPM_Serializer_Fix::replace( $raw_before, [ $site_url => $fake_new_url ] );

		echo "  After replacement (first 300 chars): " . substr( $result_raw, 0, 300 ) . "\n";

		// Verify URL was replaced.
		assert_true( str_contains( $result_raw, $fake_new_url ), 'Live DB: new URL present in result' );
		assert_true( ! str_contains( $result_raw, $site_url ), 'Live DB: old URL absent from result' );

		// Verify s:N: lengths are correct by unserializing.
		$decoded = @unserialize( $result_raw );
		assert_true( false !== $decoded, 'Live DB: unserialize() succeeds (not corrupted)' );

		if ( false !== $decoded ) {
			// Re-serialize and compare structure.
			$reserialized = serialize( $decoded );
			assert_true( strlen( $reserialized ) > 0, 'Live DB: re-serialization produces non-empty output' );
			echo "  unserialize() + serialize() roundtrip: OK\n";
		}
	} else {
		echo "  No serialized option with site URL found — skipping live DB test.\n";
	}
} else {
	echo "\n[13] Live DB test skipped (WordPress not available)\n";
}

echo "\n──────────────────────────────────────\n";
echo "Results: {$pass_count}/{$test_count} assertions passed.\n";

if ( $pass_count === $test_count ) {
	echo "\nALL TESTS PASSED\n\n";
	exit( 0 );
} else {
	echo "\nTEST FAILED: {$fail_reason}\n\n";
	exit( 1 );
}
