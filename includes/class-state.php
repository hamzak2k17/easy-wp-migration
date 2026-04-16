<?php
/**
 * Job state persistence.
 *
 * Reads and writes JSON state files in tmp/. Provides atomic writes
 * (write-to-tmp then rename) and advisory file locking so concurrent
 * AJAX ticks on the same job are serialized.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_State
 *
 * Manages job state files and advisory locks.
 */
class EWPM_State {

	/**
	 * Open lock file handles keyed by job_id.
	 *
	 * @var array<string, resource>
	 */
	private array $locks = [];

	/**
	 * Save state data atomically.
	 *
	 * Writes to a .tmp file first, then renames over the target so a crash
	 * mid-write never corrupts the state file.
	 *
	 * @param string              $job_id The job identifier.
	 * @param array<string,mixed> $data   The state data to persist.
	 * @throws EWPM_State_Exception On write or rename failure.
	 */
	public function save( string $job_id, array $data ): void {
		$path     = $this->get_state_path( $job_id );
		$tmp_path = $path . '.tmp';
		$encoded  = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $encoded ) {
			throw new EWPM_State_Exception( "Failed to JSON-encode state for job {$job_id}." );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp_path, $encoded ) ) {
			throw new EWPM_State_Exception( "Failed to write temporary state file for job {$job_id}." );
		}

		if ( ! rename( $tmp_path, $path ) ) {
			@unlink( $tmp_path );
			throw new EWPM_State_Exception( "Failed to finalize state file for job {$job_id}." );
		}
	}

	/**
	 * Load state data for a job.
	 *
	 * @param string $job_id The job identifier.
	 * @return array<string,mixed> The decoded state.
	 * @throws EWPM_State_Exception If the file is missing or corrupt.
	 */
	public function load( string $job_id ): array {
		$path = $this->get_state_path( $job_id );

		if ( ! file_exists( $path ) ) {
			throw new EWPM_State_Exception( "State file not found for job {$job_id}." );
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( false === $raw ) {
			throw new EWPM_State_Exception( "Failed to read state file for job {$job_id}." );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			throw new EWPM_State_Exception( "Corrupt state file for job {$job_id}: invalid JSON." );
		}

		return $data;
	}

	/**
	 * Check whether a state file exists for a job.
	 *
	 * @param string $job_id The job identifier.
	 * @return bool True if the state file exists.
	 */
	public function exists( string $job_id ): bool {
		return file_exists( $this->get_state_path( $job_id ) );
	}

	/**
	 * Delete a job's state file and its companion lock file.
	 *
	 * @param string $job_id The job identifier.
	 */
	public function delete( string $job_id ): void {
		$state_path  = $this->get_state_path( $job_id );
		$lock_path   = $this->get_lock_path( $job_id );
		$cancel_path = $this->get_cancel_path( $job_id );

		$this->safe_unlink( $state_path );
		$this->safe_unlink( $lock_path );
		$this->safe_unlink( $cancel_path );
		$this->safe_unlink( $state_path . '.tmp' );
	}

	/**
	 * List all job state filenames in tmp/.
	 *
	 * @return string[] Array of job IDs with active state files.
	 */
	public function list_all(): array {
		$pattern = ewpm_get_tmp_dir() . 'job-*.json';
		$files   = glob( $pattern );

		if ( ! $files ) {
			return [];
		}

		$job_ids = [];

		foreach ( $files as $file ) {
			$basename = basename( $file, '.json' );

			if ( str_starts_with( $basename, 'job-' ) ) {
				$job_ids[] = substr( $basename, 4 );
			}
		}

		return $job_ids;
	}

	/**
	 * Acquire an advisory lock for a job.
	 *
	 * Uses flock() with LOCK_EX. Retries for up to 5 seconds before
	 * throwing. The lock handle is held until release_lock() is called.
	 *
	 * @param string $job_id The job identifier.
	 * @return bool True if the lock was acquired.
	 * @throws EWPM_State_Exception If the lock cannot be acquired within 5 seconds.
	 */
	public function acquire_lock( string $job_id ): bool {
		$lock_path = $this->get_lock_path( $job_id );

		$handle = fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			throw new EWPM_State_Exception( "Failed to open lock file for job {$job_id}." );
		}

		$deadline = microtime( true ) + 5.0;

		while ( microtime( true ) < $deadline ) {
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				$this->locks[ $job_id ] = $handle;
				return true;
			}

			usleep( 50000 ); // 50ms.
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		throw new EWPM_State_Exception(
			"Could not acquire lock for job {$job_id} within 5 seconds. Another process may be working on this job."
		);
	}

	/**
	 * Release an advisory lock for a job.
	 *
	 * @param string $job_id The job identifier.
	 */
	public function release_lock( string $job_id ): void {
		if ( ! isset( $this->locks[ $job_id ] ) ) {
			return;
		}

		flock( $this->locks[ $job_id ], LOCK_UN );
		fclose( $this->locks[ $job_id ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		unset( $this->locks[ $job_id ] );

		// Best-effort removal of lock file.
		@unlink( $this->get_lock_path( $job_id ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Write a cancel flag file for a job.
	 *
	 * This does NOT require the state lock, so it can be called while a
	 * tick is running. The tick checks for this file to detect cancellation.
	 *
	 * @param string $job_id The job identifier.
	 */
	public function set_cancel_flag( string $job_id ): void {
		$path = $this->get_cancel_path( $job_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, (string) time() );
	}

	/**
	 * Check whether a cancel flag file exists for a job.
	 *
	 * @param string $job_id The job identifier.
	 * @return bool True if cancellation was requested.
	 */
	public function has_cancel_flag( string $job_id ): bool {
		return file_exists( $this->get_cancel_path( $job_id ) );
	}

	/**
	 * Remove the cancel flag file for a job.
	 *
	 * @param string $job_id The job identifier.
	 */
	public function clear_cancel_flag( string $job_id ): void {
		$path = $this->get_cancel_path( $job_id );

		if ( file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Clean up stale state files older than the given age.
	 *
	 * Scans tmp/ for state files older than $max_age_hours, deletes them
	 * along with any companion partial-archive files referenced in the
	 * state. Only deletes files that resolve inside tmp/ — never touches
	 * anything in backups/.
	 *
	 * @param int $max_age_hours Maximum age in hours. Files older than this are deleted.
	 * @return int Number of stale state files deleted.
	 * @throws EWPM_State_Exception If a file resolves outside tmp/.
	 */
	public static function cleanup_stale( int $max_age_hours = 24 ): int {
		$tmp_dir         = ewpm_get_tmp_dir();
		$max_age_seconds = $max_age_hours * 3600;
		$now             = time();
		$deleted         = 0;

		$files = glob( $tmp_dir . 'job-*.json' );

		if ( ! $files ) {
			return 0;
		}

		$real_tmp = realpath( $tmp_dir );

		if ( ! $real_tmp ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( ( $now - filemtime( $file ) ) < $max_age_seconds ) {
				continue;
			}

			self::assert_path_in_tmp( $file, $real_tmp );

			// Load state to find partial archive files to clean up.
			$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

			// Delete companion files referenced in state (partial archives, SQL dumps).
			foreach ( [ 'archive_path', 'output_path' ] as $path_key ) {
				if ( is_array( $data ) && ! empty( $data[ $path_key ] ) ) {
					$companion = (string) $data[ $path_key ];

					if ( file_exists( $companion ) ) {
						self::assert_path_in_tmp( $companion, $real_tmp );
						@unlink( $companion ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}

			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			// Clean companion lock file.
			$lock = str_replace( '.json', '.lock', $file );

			if ( file_exists( $lock ) ) {
				self::assert_path_in_tmp( $lock, $real_tmp );
				@unlink( $lock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			++$deleted;
		}

		return $deleted;
	}

	/**
	 * Get the state file path for a job.
	 *
	 * @param string $job_id The job identifier.
	 * @return string Absolute path.
	 */
	private function get_state_path( string $job_id ): string {
		return ewpm_get_tmp_dir() . "job-{$job_id}.json";
	}

	/**
	 * Get the lock file path for a job.
	 *
	 * @param string $job_id The job identifier.
	 * @return string Absolute path.
	 */
	private function get_lock_path( string $job_id ): string {
		return ewpm_get_tmp_dir() . "job-{$job_id}.lock";
	}

	/**
	 * Get the cancel flag file path for a job.
	 *
	 * @param string $job_id The job identifier.
	 * @return string Absolute path.
	 */
	private function get_cancel_path( string $job_id ): string {
		return ewpm_get_tmp_dir() . "job-{$job_id}.cancel";
	}

	/**
	 * Safely unlink a file only if it exists and resolves inside tmp/.
	 *
	 * @param string $path File path to delete.
	 */
	private function safe_unlink( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$real_tmp = realpath( ewpm_get_tmp_dir() );

		if ( $real_tmp ) {
			self::assert_path_in_tmp( $path, $real_tmp );
		}

		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Assert that a file path resolves inside tmp/.
	 *
	 * @param string $path     The path to check.
	 * @param string $real_tmp The resolved tmp/ directory path.
	 * @throws EWPM_State_Exception If the path is outside tmp/.
	 */
	private static function assert_path_in_tmp( string $path, string $real_tmp ): void {
		$real_path = realpath( $path );

		if ( ! $real_path ) {
			// File may not exist yet — check parent directory instead.
			$real_parent = realpath( dirname( $path ) );

			if ( $real_parent && ! str_starts_with( $real_parent, $real_tmp ) ) {
				throw new EWPM_State_Exception(
					"Refusing to delete file outside tmp/: {$path}"
				);
			}

			return;
		}

		if ( ! str_starts_with( $real_path, $real_tmp ) ) {
			throw new EWPM_State_Exception(
				"Refusing to delete file outside tmp/: {$path}"
			);
		}
	}
}
