<?php
/**
 * Comprehensive diagnostic for migration endpoint HTTP behavior.
 *
 * Run on test2 (destination) with ?url=MIGRATION_URL
 * Run on test1 (source) with ?url=MIGRATION_URL&self=1 for localhost test
 * Run on test1 with ?handler_test=1 for handler-in-isolation test
 *
 * Prints raw headers and response data for every test case.
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

header( 'Content-Type: text/plain; charset=utf-8' );

// Filesystem checker.
if ( ! empty( $_GET['check_fs'] ) ) {
	echo "=== FILESYSTEM STATE: " . ( defined( 'ABSPATH' ) ? ABSPATH : 'unknown' ) . " ===\n\n";

	$wpc = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( __DIR__, 2 );

	echo "--- wp-content/themes/ ---\n";
	$td = $wpc . '/themes/';
	if ( is_dir( $td ) ) {
		foreach ( scandir( $td ) as $i ) { if ( $i[0] !== '.' ) echo "  " . ( is_dir( $td . $i ) ? '[DIR]' : '[FILE]' ) . " {$i}\n"; }
	}

	echo "\n--- wp-content/plugins/ ---\n";
	$pd = $wpc . '/plugins/';
	if ( is_dir( $pd ) ) {
		foreach ( scandir( $pd ) as $i ) { if ( $i[0] !== '.' ) echo "  " . ( is_dir( $pd . $i ) ? '[DIR]' : '[FILE]' ) . " {$i}\n"; }
	}

	echo "\n--- wp-content/uploads/ ---\n";
	$ud = $wpc . '/uploads/';
	if ( is_dir( $ud ) ) {
		$c = 0;
		$ri = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ud, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $ri as $f ) { if ( $f->isFile() ) $c++; }
		echo "  Total files: {$c}\n";
		foreach ( scandir( $ud ) as $i ) { if ( $i[0] !== '.' ) echo "  " . ( is_dir( $ud . $i ) ? '[DIR]' : '[FILE]' ) . " {$i}\n"; }
	} else {
		echo "  NOT PRESENT\n";
	}

	echo "\n--- Job state files ---\n";
	$tmp = $wpc . '/easy-wp-migration-storage/tmp/';
	$jobs = glob( $tmp . 'job-*.json' );
	if ( $jobs ) {
		foreach ( $jobs as $jf ) {
			$d = json_decode( file_get_contents( $jf ), true );
			$n = basename( $jf );
			echo "  {$n}: type={$d['type']}, phase={$d['phase']}, done=" . ( ! empty( $d['done'] ) ? 'Y' : 'N' ) . ", cancelled=" . ( ! empty( $d['cancelled'] ) ? 'Y' : 'N' ) . ", error=" . ( $d['error'] ?? 'none' ) . "\n";
			if ( isset( $d['file_stats'] ) ) echo "    file_stats: " . json_encode( $d['file_stats'] ) . "\n";
			if ( isset( $d['file_cursor'] ) ) echo "    file_cursor: " . $d['file_cursor'] . "\n";
			if ( isset( $d['file_plan'] ) ) echo "    file_plan entries: " . count( $d['file_plan'] ) . "\n";
		}
	} else {
		echo "  No job state files.\n";
	}

	echo "\n--- Backups ---\n";
	$bd = $wpc . '/easy-wp-migration-storage/backups/';
	$bk = glob( $bd . '*.ezmig' );
	if ( $bk ) { foreach ( $bk as $b ) echo "  " . basename( $b ) . " (" . round( filesize( $b ) / 1048576, 1 ) . " MB)\n"; }
	else echo "  None.\n";

	echo "\n--- DB state ---\n";
	if ( function_exists( 'get_option' ) ) {
		echo "  stylesheet: " . get_option( 'stylesheet', '?' ) . "\n";
		echo "  active_plugins: " . json_encode( get_option( 'active_plugins', [] ) ) . "\n";
		echo "  siteurl: " . get_option( 'siteurl' ) . "\n";
		echo "  home: " . get_option( 'home' ) . "\n";
	}

	exit;
}

// Quick log reader.
if ( ! empty( $_GET['read_log'] ) ) {
	$log = WP_CONTENT_DIR . '/easy-wp-migration-storage/tmp/pull-debug.log';

	if ( file_exists( $log ) ) {
		echo file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	} else {
		echo "Log file not found at: {$log}\n";
	}

	exit;
}

// ──────────────────────────────────────────────────────────────────
// TEST BLOCK 3: Handler in isolation (run on test1 with ?handler_test=1)
// ──────────────────────────────────────────────────────────────────

if ( ! empty( $_GET['handler_test'] ) ) {
	echo "=== TEST BLOCK 3: Handler in isolation (on source) ===\n\n";

	// We need a valid token. Get from query.
	$token = $_GET['token'] ?? '';

	if ( empty( $token ) ) {
		echo "Usage: ?handler_test=1&token=TOKEN\n";
		exit;
	}

	$tokens  = new EWPM_Migration_Tokens();
	$result  = $tokens->validate( $token );

	echo "Token validation: " . ( $result['valid'] ? 'VALID' : 'INVALID: ' . $result['error'] ) . "\n";

	if ( ! $result['valid'] ) {
		exit;
	}

	$filename = $result['filename'];
	$backups  = new EWPM_Backups();

	try {
		$path = $backups->get_download_path( $filename );
	} catch ( \Throwable $e ) {
		echo "File error: " . $e->getMessage() . "\n";
		exit;
	}

	$file_size = (int) filesize( $path );
	echo "File: {$filename}, Size: {$file_size} bytes\n\n";

	$range_tests = [
		'No Range'                  => '',
		'Range: bytes=0-1023'       => 'bytes=0-1023',
		'Range: bytes=0-'           => 'bytes=0-',
		'Range: bytes=0-' . ( $file_size - 1 ) => 'bytes=0-' . ( $file_size - 1 ),
		'Range: bytes=1024-'        => 'bytes=1024-',
		'Range: bytes=0-5242879'    => 'bytes=0-5242879',
		'Range: bytes=9999999999-'  => 'bytes=9999999999-', // Beyond file size
	];

	foreach ( $range_tests as $label => $range_val ) {
		echo "--- {$label} ---\n";

		// Set up fake server state.
		if ( $range_val ) {
			$_SERVER['HTTP_RANGE'] = $range_val;
		} else {
			unset( $_SERVER['HTTP_RANGE'] );
		}

		// Capture output.
		ob_start();
		$headers_list_before = headers_list();

		// We can't easily call serve_file since it exits.
		// Instead, replicate the Range parsing logic inline.
		$range_header = $_SERVER['HTTP_RANGE'] ?? '';
		$start        = 0;
		$end          = $file_size - 1;
		$is_range     = false;

		if ( ! empty( $range_header ) && preg_match( '/^bytes=(\d*)-(\d*)$/', $range_header, $m ) ) {
			$start    = '' !== $m[1] ? (int) $m[1] : 0;
			$end      = '' !== $m[2] ? (int) $m[2] : $file_size - 1;
			$is_range = true;
		}

		$would_416 = $is_range && ( $start > $end || $start >= $file_size || $end >= $file_size );

		ob_end_clean();

		echo "  Parsed: start={$start}, end={$end}, is_range=" . ( $is_range ? 'yes' : 'no' ) . "\n";
		echo "  File size: {$file_size}\n";
		echo "  Would return: " . ( $would_416 ? '416 Range Not Satisfiable' : ( $is_range ? '206 Partial Content' : '200 OK' ) ) . "\n";

		if ( $is_range && ! $would_416 ) {
			$length = $end - $start + 1;
			echo "  Content-Range: bytes {$start}-{$end}/{$file_size}\n";
			echo "  Content-Length: {$length}\n";
		} elseif ( ! $is_range ) {
			echo "  Content-Length: {$file_size}\n";
		}

		echo "\n";
	}

	// Check the regex against edge cases.
	echo "=== Regex match tests ===\n";
	$regex_tests = [
		'bytes=0-1023'       => 'bytes=0-1023',
		'bytes=0-'           => 'bytes=0-',
		'bytes=1024-2047'    => 'bytes=1024-2047',
		'bytes=-1024'        => 'bytes=-1024',
		'bytes=0-0'          => 'bytes=0-0',
	];

	foreach ( $regex_tests as $label => $val ) {
		$matches = preg_match( '/^bytes=(\d*)-(\d*)$/', $val, $m );
		echo "  '{$val}': match=" . ( $matches ? 'yes' : 'no' );

		if ( $matches ) {
			echo ", m[1]='" . $m[1] . "', m[2]='" . $m[2] . "'";
			$s = '' !== $m[1] ? (int) $m[1] : 0;
			$e = '' !== $m[2] ? (int) $m[2] : $file_size - 1;
			echo ", start={$s}, end={$e}";
		}

		echo "\n";
	}

	exit;
}

// ──────────────────────────────────────────────────────────────────
// TEST BLOCKS 1 & 2: HTTP requests (run with ?url=MIGRATION_URL)
// ──────────────────────────────────────────────────────────────────

$url = $_GET['url'] ?? '';

if ( empty( $url ) ) {
	echo "Usage:\n";
	echo "  Test block 1 (cross-server): ?url=MIGRATION_URL\n";
	echo "  Test block 2 (self/localhost): ?url=MIGRATION_URL&self=1\n";
	echo "  Test block 3 (handler isolation): ?handler_test=1&token=TOKEN\n";
	exit;
}

$block = ! empty( $_GET['self'] ) ? 'TEST BLOCK 2 (self/localhost)' : 'TEST BLOCK 1 (cross-server)';
echo "=== {$block} ===\n";
echo "Target URL: {$url}\n\n";

/**
 * Make a request and print full diagnostics.
 */
function debug_request( string $label, string $url, string $method, array $extra_headers = [] ): void {
	echo "--- {$label} ---\n";
	echo "  Method: {$method}\n";
	echo "  URL: " . substr( $url, 0, 120 ) . ( strlen( $url ) > 120 ? '...' : '' ) . "\n";

	$headers = array_merge( [
		'Accept-Encoding' => 'identity',
		'User-Agent'      => 'EasyWPMigration/debug',
	], $extra_headers );

	echo "  Request headers:\n";
	foreach ( $headers as $k => $v ) {
		echo "    {$k}: {$v}\n";
	}

	$args = [
		'method'     => $method,
		'timeout'    => 30,
		'sslverify'  => true,
		'user-agent' => 'EasyWPMigration/debug',
		'headers'    => $extra_headers,
		'decompress' => false,
	];

	// For HEAD, don't try to read body.
	if ( 'HEAD' === $method ) {
		$args['method'] = 'HEAD';
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		echo "  WP_Error: " . $response->get_error_message() . "\n\n";
		return;
	}

	$status  = wp_remote_retrieve_response_code( $response );
	$body    = wp_remote_retrieve_body( $response );
	$r_headers = wp_remote_retrieve_headers( $response );

	echo "  Response status: {$status}\n";
	echo "  Response headers:\n";

	if ( $r_headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || $r_headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
		foreach ( $r_headers->getAll() as $name => $values ) {
			foreach ( (array) $values as $val ) {
				echo "    {$name}: {$val}\n";
			}
		}
	} elseif ( is_array( $r_headers ) ) {
		foreach ( $r_headers as $name => $val ) {
			echo "    {$name}: {$val}\n";
		}
	}

	echo "  Body length: " . strlen( $body ) . " bytes\n";

	if ( strlen( $body ) > 0 && strlen( $body ) <= 200 ) {
		// Short body — might be error message.
		echo "  Body (text): " . $body . "\n";
	} elseif ( strlen( $body ) > 0 ) {
		echo "  Body first 40 bytes (hex): " . bin2hex( substr( $body, 0, 40 ) ) . "\n";
	}

	echo "\n";
}

// Add unique cache-busters to each request.
$sep = str_contains( $url, '?' ) ? '&' : '?';

// 1. HEAD
debug_request( 'Test 1: HEAD request', $url . $sep . '_d=head_' . time(), 'HEAD' );

// 2. GET no Range
debug_request( 'Test 2: GET no Range', $url . $sep . '_d=norange_' . time(), 'GET' );

// 3. GET Range 0-1023
debug_request( 'Test 3: GET Range bytes=0-1023', $url . $sep . '_d=r1k_' . time(), 'GET', [ 'Range' => 'bytes=0-1023' ] );

// 4. GET Range 0-
debug_request( 'Test 4: GET Range bytes=0-', $url . $sep . '_d=r0_' . time(), 'GET', [ 'Range' => 'bytes=0-' ] );

// Get total size from test 1/2 for test 5.
$head_resp = wp_remote_head( $url . $sep . '_d=size_' . time(), [
	'timeout'   => 15,
	'sslverify' => true,
] );
$total_size = 0;

if ( ! is_wp_error( $head_resp ) ) {
	$cl = wp_remote_retrieve_header( $head_resp, 'content-length' );
	$cr = wp_remote_retrieve_header( $head_resp, 'content-range' );

	if ( $cr && preg_match( '/\/(\d+)$/', $cr, $m ) ) {
		$total_size = (int) $m[1];
	} elseif ( $cl ) {
		$total_size = (int) $cl;
	}
}

echo "Detected total size: {$total_size}\n\n";

// 5. GET Range 0-(total-1)
if ( $total_size > 0 ) {
	debug_request( 'Test 5: GET Range bytes=0-' . ( $total_size - 1 ), $url . $sep . '_d=rfull_' . time(), 'GET', [ 'Range' => 'bytes=0-' . ( $total_size - 1 ) ] );
} else {
	echo "--- Test 5: SKIPPED (total size unknown) ---\n\n";
}

// 6. GET Range 1024- (resume simulation)
debug_request( 'Test 6: GET Range bytes=1024-', $url . $sep . '_d=resume_' . time(), 'GET', [ 'Range' => 'bytes=1024-' ] );

// 7. Repeat test 3 after 2s pause (cache test)
sleep( 2 );
debug_request( 'Test 7: GET Range bytes=0-1023 (repeat after 2s)', $url . $sep . '_d=r1k_repeat_' . time(), 'GET', [ 'Range' => 'bytes=0-1023' ] );

echo "=== DONE ===\n";
