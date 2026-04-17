<?php
/**
 * Seeds test backup files for Phase 8 testing.
 * Run via web, then delete.
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

$backups_dir = WP_CONTENT_DIR . '/easy-wp-migration-storage/backups/';
$action      = $_GET['action'] ?? 'seed';

if ( 'seed' === $action ) {
	// 1. Old auto-snapshot (60 days ago).
	$f1 = $backups_dir . 'auto-before-import-old-20260101-000000.ezmig';
	file_put_contents( $f1, 'dummy-old' );
	touch( $f1, time() - ( 60 * 86400 ) );

	// 2. Fresh auto-snapshot (2 days ago).
	$f2 = $backups_dir . 'auto-before-import-fresh-20260415-000000.ezmig';
	file_put_contents( $f2, 'dummy-new' );
	touch( $f2, time() - ( 2 * 86400 ) );

	// 3. Old USER backup (60 days ago) — must NOT be deleted.
	$f3 = $backups_dir . 'user-backup-old-20260101-000000.ezmig';
	file_put_contents( $f3, 'dummy-user' );
	touch( $f3, time() - ( 60 * 86400 ) );

	echo "Seeded 3 test files.\n";

} elseif ( 'seed_3day' === $action ) {
	// 3-day-old auto-snapshot for retention clamp test.
	$f = $backups_dir . 'auto-before-import-3days-20260414-000000.ezmig';
	file_put_contents( $f, 'dummy-3day' );
	touch( $f, time() - ( 3 * 86400 ) );
	echo "Seeded 3-day-old auto-snapshot.\n";

} elseif ( 'corrupt' === $action ) {
	$f = $backups_dir . 'corrupt-test.ezmig';
	file_put_contents( $f, 'this is not a valid zip file' );
	echo "Created corrupt archive.\n";

} elseif ( 'list' === $action ) {
	$files = glob( $backups_dir . '*.ezmig' );
	echo "Files in backups/:\n";
	foreach ( $files as $file ) {
		$age_days = round( ( time() - filemtime( $file ) ) / 86400, 1 );
		$is_auto  = str_starts_with( basename( $file ), 'auto-before-import-' ) ? 'AUTO' : 'USER';
		echo sprintf( "  [%s] %s — %d bytes, %.1f days old\n", $is_auto, basename( $file ), filesize( $file ), $age_days );
	}

} elseif ( 'cron' === $action ) {
	$event = wp_get_scheduled_event( 'ewpm_daily_cleanup' );
	if ( $event ) {
		echo "Cron scheduled: YES\n";
		echo "Next run: " . gmdate( 'Y-m-d H:i:s', $event->timestamp ) . " UTC\n";
		echo "Schedule: " . $event->schedule . "\n";
	} else {
		echo "Cron scheduled: NO\n";
	}

} elseif ( 'cleanup' === $action ) {
	// Remove test files.
	$test_files = [
		'auto-before-import-old-20260101-000000.ezmig',
		'auto-before-import-fresh-20260415-000000.ezmig',
		'user-backup-old-20260101-000000.ezmig',
		'auto-before-import-3days-20260414-000000.ezmig',
		'corrupt-test.ezmig',
	];
	foreach ( $test_files as $name ) {
		$path = $backups_dir . $name;
		if ( file_exists( $path ) ) {
			unlink( $path );
			echo "Deleted: {$name}\n";
		}
	}
	echo "Cleanup done.\n";
}
