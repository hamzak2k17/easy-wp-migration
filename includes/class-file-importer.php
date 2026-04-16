<?php
/**
 * File importer.
 *
 * Extracts files from an .ezmig archive to their WordPress destinations.
 * Validates all paths for safety — no extraction outside WP_CONTENT_DIR.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_File_Importer
 *
 * Extracts archive files to the WordPress filesystem.
 */
class EWPM_File_Importer {

	/**
	 * Archiver instance opened for reading.
	 */
	private EWPM_Archiver_Interface $archiver;

	/**
	 * WordPress root path (ABSPATH).
	 */
	private string $wp_root;

	/**
	 * Constructor.
	 *
	 * @param EWPM_Archiver_Interface $archiver Archiver opened for reading.
	 * @param string                  $wp_root  WordPress root path.
	 */
	public function __construct( EWPM_Archiver_Interface $archiver, string $wp_root ) {
		$this->archiver = $archiver;
		$this->wp_root  = rtrim( $wp_root, '/\\' ) . '/';
	}

	/**
	 * Get the extraction plan from the archive.
	 *
	 * Filters entries to only wp-content/ paths, excludes our storage folder,
	 * validates path safety.
	 *
	 * @return array{entries: array, total_files: int, total_bytes: int, warnings: string[]}
	 */
	public function get_extraction_plan(): array {
		$all_entries = $this->archiver->list_entries();
		$entries     = [];
		$warnings    = [];
		$total_bytes = 0;

		foreach ( $all_entries as $entry ) {
			$archive_path = $entry['path'];

			// Skip non-wp-content paths and metadata/database files.
			if ( ! str_starts_with( $archive_path, 'wp-content/' ) ) {
				continue;
			}

			// Skip our own storage folder (defense in depth).
			if ( str_contains( $archive_path, 'easy-wp-migration-storage' ) ) {
				$warnings[] = sprintf( 'Skipped self-storage path: %s', $archive_path );
				continue;
			}

			// Skip directory entries.
			if ( str_ends_with( $archive_path, '/' ) ) {
				continue;
			}

			// Build destination path.
			$dest_path = $this->wp_root . $archive_path;

			// Path traversal check — reject any path containing ..
			if ( str_contains( $archive_path, '..' ) ) {
				$warnings[] = sprintf( 'Path traversal rejected: %s', $archive_path );
				continue;
			}

			$entries[] = [
				'archive_path' => $archive_path,
				'dest_path'    => $dest_path,
				'size'         => $entry['size'],
			];

			$total_bytes += $entry['size'];
		}

		return [
			'entries'     => $entries,
			'total_files' => count( $entries ),
			'total_bytes' => $total_bytes,
			'warnings'    => $warnings,
		];
	}

	/**
	 * Extract files starting at cursor index, respecting time budget.
	 *
	 * @param array $plan                The extraction plan entries.
	 * @param int   $cursor_index        Starting index in the plan.
	 * @param int   $time_budget_seconds Max seconds to spend.
	 * @param array $options             Extraction options.
	 * @return array{cursor: int, extracted: int, bytes: int, done: bool, warnings: string[]}
	 */
	public function extract_next( array $plan, int $cursor_index, int $time_budget_seconds, array $options = [] ): array {
		$deadline          = microtime( true ) + $time_budget_seconds;
		$conflict_strategy = $options['conflict_strategy'] ?? 'overwrite';
		$extracted         = 0;
		$bytes             = 0;
		$warnings          = [];
		$total             = count( $plan );

		for ( $i = $cursor_index; $i < $total; $i++ ) {
			if ( microtime( true ) >= $deadline ) {
				return [
					'cursor'    => $i,
					'extracted' => $extracted,
					'bytes'     => $bytes,
					'done'      => false,
					'warnings'  => $warnings,
				];
			}

			$entry = $plan[ $i ];

			// Final path safety check with realpath on parent dir.
			$dest_dir = dirname( $entry['dest_path'] );

			if ( ! is_dir( $dest_dir ) ) {
				if ( ! wp_mkdir_p( $dest_dir ) ) {
					$warnings[] = sprintf( 'Cannot create directory: %s', $dest_dir );
					continue;
				}
			}

			// Verify the destination resolves inside WP root.
			$real_dir  = realpath( $dest_dir );
			$real_root = realpath( $this->wp_root );

			if ( ! $real_dir || ! $real_root || ! str_starts_with( $real_dir, $real_root ) ) {
				$warnings[] = sprintf( 'Path outside WP root rejected: %s', $entry['dest_path'] );
				continue;
			}

			// Handle conflicts with existing files.
			if ( file_exists( $entry['dest_path'] ) ) {
				if ( 'skip' === $conflict_strategy ) {
					continue;
				}

				if ( 'rename-old' === $conflict_strategy ) {
					$backup_name = $entry['dest_path'] . '.backup-' . time();
					@rename( $entry['dest_path'], $backup_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				// 'overwrite' — just proceed, extract_file overwrites.
			}

			try {
				$this->archiver->extract_file( $entry['archive_path'], $entry['dest_path'] );
				++$extracted;
				$bytes += $entry['size'];
			} catch ( EWPM_Archiver_Exception $e ) {
				$warnings[] = sprintf( 'Failed to extract %s: %s', $entry['archive_path'], $e->getMessage() );
			}
		}

		return [
			'cursor'    => $total,
			'extracted' => $extracted,
			'bytes'     => $bytes,
			'done'      => true,
			'warnings'  => $warnings,
		];
	}
}
