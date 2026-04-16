<?php
/**
 * Import job.
 *
 * Reads an .ezmig archive, replays the database with serialization-aware
 * URL replacement, extracts files to their WordPress paths. Driven by the
 * tick-based job framework.
 *
 * Phases: validate_archive → extract_database_sql → replay_database →
 *         extract_files → post_import_fixup → done
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job_Import
 *
 * Import engine job, testable via dev tools. UI comes in Phase 7.
 */
class EWPM_Job_Import extends EWPM_Job {

	/**
	 * Return the job type identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'import';
	}

	/**
	 * Return initial state for the import job.
	 *
	 * @param array<string,mixed> $init_params Params: archive_path, conflict_strategy,
	 *                                         replace_paths, stop_on_db_error.
	 * @return array<string,mixed>
	 */
	protected function get_default_state( array $init_params ): array {
		return [
			'phase'              => 'validate_archive',
			'phase_label'        => __( 'Validating archive', 'easy-wp-migration' ),
			'progress_percent'   => 0,
			'progress_label'     => __( 'Opening archive...', 'easy-wp-migration' ),
			'params'             => [
				'archive_path'      => $init_params['archive_path'] ?? '',
				'conflict_strategy' => $init_params['conflict_strategy'] ?? 'overwrite',
				'replace_paths'     => ! empty( $init_params['replace_paths'] ),
				'stop_on_db_error'  => ! empty( $init_params['stop_on_db_error'] ),
			],
			'metadata'           => null,
			'replacements'       => [],
			'db_sql_path'        => '',
			'db_sql_size'        => 0,
			'db_cursor_bytes'    => 0,
			'db_stats'           => [ 'statements_executed' => 0, 'errors' => [] ],
			'file_plan'          => [],
			'file_cursor'        => 0,
			'file_stats'         => [ 'files_extracted' => 0, 'bytes_extracted' => 0, 'warnings' => [] ],
			'warnings'           => [],
		];
	}

	/**
	 * Execute one tick of work.
	 *
	 * @param array<string,mixed> $state               Current state.
	 * @param int                 $time_budget_seconds  Max seconds of work.
	 * @return array<string,mixed> Updated state.
	 */
	protected function run_tick( array $state, int $time_budget_seconds ): array {
		$deadline = microtime( true ) + $time_budget_seconds;

		if ( $this->state->has_cancel_flag( $state['job_id'] ) ) {
			$state['cancelled']      = true;
			$state['phase_label']    = __( 'Cancelled', 'easy-wp-migration' );
			$state['progress_label'] = __( 'Import was cancelled. Your site may be in an inconsistent state.', 'easy-wp-migration' );
			return $state;
		}

		return match ( $state['phase'] ) {
			'validate_archive'     => $this->phase_validate( $state ),
			'extract_database_sql' => $this->phase_extract_db( $state ),
			'replay_database'      => $this->phase_replay_db( $state, $deadline ),
			'extract_files'        => $this->phase_extract_files( $state, $deadline ),
			'post_import_fixup'    => $this->phase_fixup( $state ),
			default                => $state,
		};
	}

	/**
	 * Return final result after job is done.
	 *
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed> Result payload.
	 */
	protected function finalize( array $state ): array {
		$meta = $state['metadata'] ?? [];

		return [
			'source_url'           => $meta['source']['site_url'] ?? '',
			'destination_url'      => get_option( 'siteurl', '' ),
			'replacements_applied' => $state['replacements'],
			'db_statements'        => $state['db_stats']['statements_executed'],
			'db_errors'            => $state['db_stats']['errors'],
			'files_extracted'      => $state['file_stats']['files_extracted'],
			'bytes_extracted'      => $state['file_stats']['bytes_extracted'],
			'warnings'             => $state['warnings'],
			'note'                 => __( 'Please log in with the source site\'s admin credentials.', 'easy-wp-migration' ),
		];
	}

	/**
	 * Clean up on cancel or error.
	 *
	 * Deletes temp DB SQL file. Does NOT rollback applied SQL or extracted files.
	 *
	 * @param array<string,mixed> $state Current state.
	 */
	protected function cleanup( array $state ): void {
		$paths = [ $state['db_sql_path'] ?? '' ];

		$real_tmp = realpath( ewpm_get_tmp_dir() );
		if ( ! $real_tmp ) {
			return;
		}

		foreach ( $paths as $path ) {
			if ( empty( $path ) || ! file_exists( $path ) ) {
				continue;
			}
			$real = realpath( $path );
			if ( $real && str_starts_with( $real, $real_tmp ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Validate archive phase: read metadata, build replacements, prepare plan.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_validate( array $state ): array {
		$archive_path = $state['params']['archive_path'];

		if ( empty( $archive_path ) || ! file_exists( $archive_path ) ) {
			$state['error'] = __( 'Archive file not found.', 'easy-wp-migration' );
			return $state;
		}

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_read( $archive_path );
			$metadata = $archiver->get_metadata();
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		$state['metadata'] = $metadata;

		// Build URL replacements.
		$source_url = rtrim( $metadata['source']['site_url'] ?? '', '/' );
		$source_home = rtrim( $metadata['source']['home_url'] ?? '', '/' );
		$dest_url   = rtrim( get_option( 'siteurl', '' ), '/' );
		$dest_home  = rtrim( get_home_url(), '/' );

		$replacements = [];

		if ( $source_url && $source_url !== $dest_url ) {
			$replacements[ $source_url ] = $dest_url;
			// URL-encoded variant.
			$replacements[ rawurlencode( $source_url ) ] = rawurlencode( $dest_url );
		}

		if ( $source_home && $source_home !== $dest_home && $source_home !== $source_url ) {
			$replacements[ $source_home ] = $dest_home;
			$replacements[ rawurlencode( $source_home ) ] = rawurlencode( $dest_home );
		}

		// Optional path replacement.
		if ( ! empty( $state['params']['replace_paths'] ) ) {
			$source_abspath = rtrim( $metadata['source']['abspath'] ?? '', '/' );
			$dest_abspath   = rtrim( ABSPATH, '/' );

			if ( $source_abspath && $source_abspath !== $dest_abspath ) {
				$replacements[ $source_abspath ] = $dest_abspath;
			}
		}

		$state['replacements'] = $replacements;

		// Build file extraction plan.
		try {
			$file_importer = new EWPM_File_Importer( $archiver, ABSPATH );
			$plan          = $file_importer->get_extraction_plan();

			$state['file_plan']  = $plan['entries'];
			$state['warnings']   = array_merge( $state['warnings'], $plan['warnings'] );
		} catch ( \Throwable $e ) {
			$state['warnings'][] = sprintf( 'File plan error: %s', $e->getMessage() );
			$state['file_plan']  = [];
		}

		$archiver->close();

		// Determine next phase.
		$has_db = ! empty( $metadata['components']['database'] );

		if ( $has_db ) {
			$state['phase']       = 'extract_database_sql';
			$state['phase_label'] = __( 'Extracting database', 'easy-wp-migration' );
		} elseif ( ! empty( $state['file_plan'] ) ) {
			$state['phase']       = 'extract_files';
			$state['phase_label'] = __( 'Extracting files', 'easy-wp-migration' );
		} else {
			$state['phase']       = 'post_import_fixup';
			$state['phase_label'] = __( 'Finishing', 'easy-wp-migration' );
		}

		$state['progress_percent'] = 2;
		$state['progress_label']   = sprintf(
			/* translators: 1: source URL */
			__( 'Archive validated. Source: %1$s', 'easy-wp-migration' ),
			$source_url
		);

		return $state;
	}

	/**
	 * Extract database.sql from the archive to a temp file.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_extract_db( array $state ): array {
		$archive_path = $state['params']['archive_path'];
		$job_id       = $state['job_id'];
		$sql_path     = ewpm_get_tmp_dir() . "import-{$job_id}-db.sql";

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_read( $archive_path );
			$archiver->extract_file( 'database.sql', $sql_path );
			$archiver->close();
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = sprintf( 'Failed to extract database.sql: %s', $e->getMessage() );
			return $state;
		}

		$state['db_sql_path'] = $sql_path;
		$state['db_sql_size'] = file_exists( $sql_path ) ? (int) filesize( $sql_path ) : 0;
		$state['phase']       = 'replay_database';
		$state['phase_label'] = __( 'Replaying database', 'easy-wp-migration' );
		$state['progress_percent'] = 5;
		$state['progress_label']   = sprintf(
			/* translators: %s: SQL file size */
			__( 'Database extracted (%s). Starting replay...', 'easy-wp-migration' ),
			size_format( $state['db_sql_size'] )
		);

		return $state;
	}

	/**
	 * Replay database phase: execute SQL statements in chunks.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_replay_db( array $state, float $deadline ): array {
		global $wpdb;

		$metadata      = $state['metadata'];
		$source_prefix = $metadata['source']['table_prefix'] ?? 'wp_';
		$dest_prefix   = $wpdb->prefix;

		$importer = new EWPM_DB_Importer(
			$wpdb,
			$state['db_sql_path'],
			$source_prefix,
			$dest_prefix
		);

		$remaining = max( 1, (int) ( $deadline - microtime( true ) ) );

		$result = $importer->replay( $state['db_cursor_bytes'], $remaining, [
			'disable_foreign_keys' => true,
			'set_names_charset'    => 'utf8mb4',
			'stop_on_error'        => $state['params']['stop_on_db_error'],
			'replacements'         => $state['replacements'],
		] );

		$state['db_cursor_bytes'] = $result['cursor'];
		$state['db_stats']['statements_executed'] += $result['statements'];
		$state['db_stats']['errors'] = array_merge(
			$state['db_stats']['errors'],
			$result['errors']
		);
		$state['warnings'] = array_merge( $state['warnings'], $result['warnings'] );

		if ( $result['done'] ) {
			// Delete temp SQL file.
			$real_tmp = realpath( ewpm_get_tmp_dir() );
			$real_sql = realpath( $state['db_sql_path'] );
			if ( $real_sql && $real_tmp && str_starts_with( $real_sql, $real_tmp ) ) {
				@unlink( $state['db_sql_path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( ! empty( $state['file_plan'] ) ) {
				$state['phase']       = 'extract_files';
				$state['phase_label'] = __( 'Extracting files', 'easy-wp-migration' );
			} else {
				$state['phase']       = 'post_import_fixup';
				$state['phase_label'] = __( 'Finishing', 'easy-wp-migration' );
			}

			$state['progress_percent'] = 45;
			$state['progress_label']   = sprintf(
				/* translators: %s: statement count */
				__( 'Database replayed: %s statements', 'easy-wp-migration' ),
				number_format( $state['db_stats']['statements_executed'] )
			);
		} else {
			// Update progress within replay_database (5-45%).
			$total = max( 1, $state['db_sql_size'] );
			$pct   = $state['db_cursor_bytes'] / $total;
			$state['progress_percent'] = 5 + (int) ( $pct * 40 );
			$state['progress_label']   = sprintf(
				/* translators: 1: statement count, 2: bytes processed, 3: total bytes */
				__( 'Replayed %1$s statements (%2$s / %3$s)', 'easy-wp-migration' ),
				number_format( $state['db_stats']['statements_executed'] ),
				size_format( $state['db_cursor_bytes'] ),
				size_format( $total )
			);
		}

		return $state;
	}

	/**
	 * Extract files phase: extract wp-content files from the archive.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_extract_files( array $state, float $deadline ): array {
		$archive_path = $state['params']['archive_path'];

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_read( $archive_path );
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		$file_importer = new EWPM_File_Importer( $archiver, ABSPATH );
		$remaining     = max( 1, (int) ( $deadline - microtime( true ) ) );

		$result = $file_importer->extract_next(
			$state['file_plan'],
			$state['file_cursor'],
			$remaining,
			[ 'conflict_strategy' => $state['params']['conflict_strategy'] ]
		);

		$archiver->close();

		$state['file_cursor'] = $result['cursor'];
		$state['file_stats']['files_extracted'] += $result['extracted'];
		$state['file_stats']['bytes_extracted'] += $result['bytes'];
		$state['file_stats']['warnings'] = array_merge(
			$state['file_stats']['warnings'],
			$result['warnings']
		);
		$state['warnings'] = array_merge( $state['warnings'], $result['warnings'] );

		if ( $result['done'] ) {
			$state['phase']       = 'post_import_fixup';
			$state['phase_label'] = __( 'Finishing', 'easy-wp-migration' );
			$state['progress_percent'] = 95;
		} else {
			$total = count( $state['file_plan'] );
			$done  = $state['file_stats']['files_extracted'];
			$pct   = $total > 0 ? ( $done / $total ) : 0;
			$state['progress_percent'] = 45 + (int) ( $pct * 50 );
			$state['progress_label']   = sprintf(
				/* translators: 1: files extracted, 2: total files */
				__( 'Extracted %1$s / %2$s files', 'easy-wp-migration' ),
				number_format( $done ),
				number_format( $total )
			);
		}

		return $state;
	}

	/**
	 * Post-import fixup phase: flush caches, update siteurl/home.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_fixup( array $state ): array {
		global $wpdb;

		$dest_url  = site_url();
		$dest_home = home_url();

		// Force siteurl and home to current values (SQL replay may have
		// overwritten them with the source site's values).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => $dest_url ],
			[ 'option_name' => 'siteurl' ]
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => $dest_home ],
			[ 'option_name' => 'home' ]
		);

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Clear object cache.
		wp_cache_flush();

		$state['done']             = true;
		$state['progress_percent'] = 100;
		$state['phase_label']      = __( 'Complete', 'easy-wp-migration' );
		$state['progress_label']   = sprintf(
			/* translators: 1: statements, 2: files */
			__( 'Import complete: %1$s DB statements, %2$s files extracted', 'easy-wp-migration' ),
			number_format( $state['db_stats']['statements_executed'] ),
			number_format( $state['file_stats']['files_extracted'] )
		);

		return $state;
	}
}
