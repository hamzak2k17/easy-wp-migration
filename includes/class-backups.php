<?php
/**
 * Backups service.
 *
 * Manages the backups/ directory: listing, metadata reading, deletion,
 * and auto-snapshot cleanup.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Backups
 *
 * Thin wrapper around the backups/ directory for listing and management.
 */
class EWPM_Backups {

	/**
	 * Per-request metadata cache keyed by path+mtime.
	 *
	 * @var array<string, array|null>
	 */
	private static array $meta_cache = [];

	/**
	 * List all backup archives in the backups/ directory.
	 *
	 * Sorted by mtime DESC (newest first). Metadata read failures are
	 * non-fatal — the entry is returned with metadata_error set.
	 *
	 * @return array<int, array>
	 */
	public function list(): array {
		$dir   = ewpm_get_backups_dir();
		$files = glob( $dir . '*.' . EWPM_ARCHIVE_EXTENSION );
		$list  = [];

		if ( ! $files ) {
			return [];
		}

		foreach ( $files as $file ) {
			$filename = basename( $file );
			$mtime    = (int) filemtime( $file );
			$size     = (int) filesize( $file );

			$meta       = null;
			$meta_error = null;

			try {
				$meta = $this->read_metadata( $file );
			} catch ( \Throwable $e ) {
				$meta_error = $e->getMessage();
			}

			$list[] = [
				'filename'        => $filename,
				'absolute_path'   => $file,
				'size_bytes'      => $size,
				'size_human'      => size_format( $size ),
				'mtime'           => $mtime,
				'created_human'   => human_time_diff( $mtime, time() ) . ' ' . __( 'ago', 'easy-wp-migration' ),
				'is_auto_snapshot' => self::is_auto_snapshot( $filename ),
				'metadata'        => $meta,
				'metadata_error'  => $meta_error,
			];
		}

		// Sort newest first.
		usort( $list, fn( $a, $b ) => $b['mtime'] - $a['mtime'] );

		return $list;
	}

	/**
	 * Check if a filename is an auto-snapshot.
	 *
	 * @param string $filename The backup filename.
	 * @return bool True if auto-snapshot.
	 */
	public static function is_auto_snapshot( string $filename ): bool {
		return str_starts_with( $filename, 'auto-before-import-' );
	}

	/**
	 * Read metadata.json from inside an archive.
	 *
	 * Cached per-request by path + filemtime.
	 *
	 * @param string $absolute_path Absolute path to the .ezmig file.
	 * @return array|null Parsed metadata or null.
	 */
	public function read_metadata( string $absolute_path ): ?array {
		$key = $absolute_path . ':' . filemtime( $absolute_path );

		if ( array_key_exists( $key, self::$meta_cache ) ) {
			return self::$meta_cache[ $key ];
		}

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_read( $absolute_path );
			$meta = $archiver->get_metadata();
			$archiver->close();
		} catch ( \Throwable $e ) {
			self::$meta_cache[ $key ] = null;
			throw $e;
		}

		self::$meta_cache[ $key ] = $meta;
		return $meta;
	}

	/**
	 * Delete a backup file.
	 *
	 * @param string $filename The backup filename (basename only).
	 * @throws EWPM_Backups_Exception If path is outside backups/ or delete fails.
	 */
	public function delete( string $filename ): void {
		$path = ewpm_get_backups_dir() . $filename;

		$this->assert_path_in_backups( $path );

		if ( ! file_exists( $path ) ) {
			throw new EWPM_Backups_Exception(
				sprintf( __( 'Backup not found: %s', 'easy-wp-migration' ), $filename )
			);
		}

		if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new EWPM_Backups_Exception(
				sprintf( __( 'Failed to delete backup: %s', 'easy-wp-migration' ), $filename )
			);
		}
	}

	/**
	 * Get the verified absolute path for a backup filename.
	 *
	 * @param string $filename The backup filename.
	 * @return string Verified absolute path.
	 * @throws EWPM_Backups_Exception If path is invalid.
	 */
	public function get_download_path( string $filename ): string {
		$path = ewpm_get_backups_dir() . $filename;

		$this->assert_path_in_backups( $path );

		if ( ! file_exists( $path ) ) {
			throw new EWPM_Backups_Exception(
				sprintf( __( 'Backup not found: %s', 'easy-wp-migration' ), $filename )
			);
		}

		return $path;
	}

	/**
	 * Clean up expired auto-snapshots.
	 *
	 * Only deletes files that match the auto-before-import- prefix AND
	 * are older than the retention period. User backups are never touched.
	 * Minimum retention clamped to 7 days.
	 *
	 * @param int $max_age_days Maximum age in days. Clamped to minimum 7.
	 * @return array{deleted: string[], freed_bytes: int}
	 */
	public function cleanup_expired_auto_snapshots( int $max_age_days = 30 ): array {
		// Clamp minimum to 7 days.
		$max_age_days = max( 7, $max_age_days );

		$cutoff      = time() - ( $max_age_days * 86400 );
		$dir         = ewpm_get_backups_dir();
		$files       = glob( $dir . '*.' . EWPM_ARCHIVE_EXTENSION );
		$deleted     = [];
		$freed_bytes = 0;

		if ( ! $files ) {
			return [ 'deleted' => [], 'freed_bytes' => 0 ];
		}

		foreach ( $files as $file ) {
			$filename = basename( $file );

			// Only auto-snapshots — strict prefix check.
			if ( ! self::is_auto_snapshot( $filename ) ) {
				continue;
			}

			// Only files older than retention period.
			if ( filemtime( $file ) >= $cutoff ) {
				continue;
			}

			// Realpath safety.
			try {
				$this->assert_path_in_backups( $file );
			} catch ( EWPM_Backups_Exception $e ) {
				continue;
			}

			$size = (int) filesize( $file );

			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$deleted[]    = $filename;
				$freed_bytes += $size;
			}
		}

		return [
			'deleted'     => $deleted,
			'freed_bytes' => $freed_bytes,
		];
	}

	/**
	 * Assert that a path resolves inside the backups/ directory.
	 *
	 * @param string $path File path to check.
	 * @throws EWPM_Backups_Exception If path is outside backups/.
	 */
	private function assert_path_in_backups( string $path ): void {
		$real_path    = realpath( $path );
		$real_backups = realpath( ewpm_get_backups_dir() );

		if ( ! $real_path || ! $real_backups || ! str_starts_with( $real_path, $real_backups ) ) {
			throw new EWPM_Backups_Exception(
				__( 'File path is outside the backups directory.', 'easy-wp-migration' )
			);
		}
	}
}
