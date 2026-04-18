<?php
/**
 * Debug script: test wp_remote_get from this server to a migration URL.
 * Run on test2 to diagnose why pull_chunk gets 416.
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

header( 'Content-Type: text/plain' );

$url = $_GET['url'] ?? '';

if ( empty( $url ) ) {
	echo "Usage: ?url=MIGRATION_URL\n";
	exit;
}

echo "=== Test 1: Full download (no Range) ===\n";
$r1 = wp_remote_get( $url . '&_t=' . time() . '_norange', [
	'timeout'    => 30,
	'user-agent' => 'EasyWPMigration/debug',
	'headers'    => [ 'Accept-Encoding' => 'identity' ],
	'decompress' => false,
] );

if ( is_wp_error( $r1 ) ) {
	echo "ERROR: " . $r1->get_error_message() . "\n";
} else {
	echo "Status: " . wp_remote_retrieve_response_code( $r1 ) . "\n";
	echo "Content-Type: " . wp_remote_retrieve_header( $r1, 'content-type' ) . "\n";
	echo "Content-Length: " . wp_remote_retrieve_header( $r1, 'content-length' ) . "\n";
	echo "Content-Range: " . wp_remote_retrieve_header( $r1, 'content-range' ) . "\n";
	echo "Accept-Ranges: " . wp_remote_retrieve_header( $r1, 'accept-ranges' ) . "\n";
	echo "Body length: " . strlen( wp_remote_retrieve_body( $r1 ) ) . "\n";
	echo "Body first 20 bytes (hex): " . bin2hex( substr( wp_remote_retrieve_body( $r1 ), 0, 20 ) ) . "\n";
}

echo "\n=== Test 2: Range bytes=0-1023 ===\n";
$r2 = wp_remote_get( $url . '&_t=' . time() . '_range1k', [
	'timeout'    => 30,
	'user-agent' => 'EasyWPMigration/debug',
	'headers'    => [
		'Range'           => 'bytes=0-1023',
		'Accept-Encoding' => 'identity',
	],
	'decompress' => false,
] );

if ( is_wp_error( $r2 ) ) {
	echo "ERROR: " . $r2->get_error_message() . "\n";
} else {
	echo "Status: " . wp_remote_retrieve_response_code( $r2 ) . "\n";
	echo "Content-Type: " . wp_remote_retrieve_header( $r2, 'content-type' ) . "\n";
	echo "Content-Length: " . wp_remote_retrieve_header( $r2, 'content-length' ) . "\n";
	echo "Content-Range: " . wp_remote_retrieve_header( $r2, 'content-range' ) . "\n";
	echo "Body length: " . strlen( wp_remote_retrieve_body( $r2 ) ) . "\n";
}

echo "\n=== Test 3: Range bytes=0-5242879 (5MB) ===\n";
$r3 = wp_remote_get( $url . '&_t=' . time() . '_range5m', [
	'timeout'    => 30,
	'user-agent' => 'EasyWPMigration/debug',
	'headers'    => [
		'Range'           => 'bytes=0-5242879',
		'Accept-Encoding' => 'identity',
	],
	'decompress' => false,
] );

if ( is_wp_error( $r3 ) ) {
	echo "ERROR: " . $r3->get_error_message() . "\n";
} else {
	echo "Status: " . wp_remote_retrieve_response_code( $r3 ) . "\n";
	echo "Content-Type: " . wp_remote_retrieve_header( $r3, 'content-type' ) . "\n";
	echo "Content-Length: " . wp_remote_retrieve_header( $r3, 'content-length' ) . "\n";
	echo "Content-Range: " . wp_remote_retrieve_header( $r3, 'content-range' ) . "\n";
	echo "Body length: " . strlen( wp_remote_retrieve_body( $r3 ) ) . "\n";
}

echo "\nDone.\n";
