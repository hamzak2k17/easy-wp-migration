<?php
/**
 * Check filesystem state after import — themes, plugins, uploads, job state.
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

echo "=== FILESYSTEM STATE: " . site_url() . " ===\n\n";

// 1. Themes
echo "--- wp-content/themes/ ---\n";
$themes_dir = WP_CONTENT_DIR . '/themes/';
if ( is_dir( $themes_dir ) ) {
	$items = scandir( $themes_dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$type = is_dir( $themes_dir . $item ) ? 'DIR' : 'FILE';
		echo "  [{$type}] {$item}\n";
	}
} else {
	echo "  MISSING\n";
}

// 2. Plugins
echo "\n--- wp-content/plugins/ ---\n";
$plugins_dir = WP_CONTENT_DIR . '/plugins/';
if ( is_dir( $plugins_dir ) ) {
	$items = scandir( $plugins_dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$type = is_dir( $plugins_dir . $item ) ? 'DIR' : 'FILE';
		echo "  [{$type}] {$item}\n";
	}
} else {
	echo "  MISSING\n";
}

// 3. Uploads
echo "\n--- wp-content/uploads/ ---\n";
$uploads_dir = WP_CONTENT_DIR . '/uploads/';
if ( is_dir( $uploads_dir ) ) {
	$count = 0;
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $uploads_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iter as $file ) {
		if ( $file->isFile() ) $count++;
	}
	echo "  Total files: {$count}\n";
	$items = scandir( $uploads_dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) continue;
		$type = is_dir( $uploads_dir . $item ) ? 'DIR' : 'FILE';
		echo "  [{$type}] {$item}\n";
	}
} else {
	echo "  NOT PRESENT\n";
}

// 4. Job state files
echo "\n--- easy-wp-migration-storage/tmp/ (job state files) ---\n";
$tmp_dir = WP_CONTENT_DIR . '/easy-wp-migration-storage/tmp/';
if ( is_dir( $tmp_dir ) ) {
	$files = glob( $tmp_dir . 'job-*.json' );
	if ( empty( $files ) ) {
		echo "  No job state files found.\n";
	}
	foreach ( $files as $file ) {
		$name = basename( $file );
		$size = filesize( $file );
		echo "  {$name} ({$size} bytes)\n";

		$data = json_decode( file_get_contents( $file ), true );
		if ( $data ) {
			echo "    type: " . ( $data['type'] ?? '?' ) . "\n";
			echo "    phase: " . ( $data['phase'] ?? '?' ) . "\n";
			echo "    phase_label: " . ( $data['phase_label'] ?? '?' ) . "\n";
			echo "    done: " . ( ! empty( $data['done'] ) ? 'yes' : 'no' ) . "\n";
			echo "    cancelled: " . ( ! empty( $data['cancelled'] ) ? 'yes' : 'no' ) . "\n";
			echo "    error: " . ( $data['error'] ?? 'none' ) . "\n";

			if ( isset( $data['file_cursor'] ) ) {
				echo "    file_cursor: " . $data['file_cursor'] . "\n";
			}
			if ( isset( $data['file_stats'] ) ) {
				echo "    file_stats: " . json_encode( $data['file_stats'] ) . "\n";
			}
			if ( isset( $data['db_stats'] ) ) {
				echo "    db_stats: " . json_encode( $data['db_stats'] ) . "\n";
			}

			// Count file_plan entries
			if ( isset( $data['file_plan'] ) ) {
				echo "    file_plan entries: " . count( $data['file_plan'] ) . "\n";
			}
		}
		echo "\n";
	}
} else {
	echo "  tmp/ NOT FOUND\n";
}

// 5. Backups
echo "\n--- easy-wp-migration-storage/backups/ ---\n";
$backups_dir = WP_CONTENT_DIR . '/easy-wp-migration-storage/backups/';
if ( is_dir( $backups_dir ) ) {
	$files = glob( $backups_dir . '*.ezmig' );
	foreach ( $files as $file ) {
		echo "  " . basename( $file ) . " (" . size_format( filesize( $file ) ) . ")\n";
	}
	if ( empty( $files ) ) echo "  No backups.\n";
}

// 6. Active theme + plugins from DB
echo "\n--- DB state ---\n";
echo "  Active theme: " . get_option( 'stylesheet', '?' ) . "\n";
echo "  Active plugins: " . json_encode( get_option( 'active_plugins', [] ) ) . "\n";
echo "  siteurl: " . get_option( 'siteurl' ) . "\n";
echo "  home: " . get_option( 'home' ) . "\n";

echo "\nDone.\n";
