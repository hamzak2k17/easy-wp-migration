<?php
/**
 * Full site export job.
 *
 * Composes the file scanner, database exporter, and archiver into a
 * complete site export producing a single .ezmig file. Driven by the
 * tick-based job framework.
 *
 * Phases: init → scan_files → dump_database → archive_database →
 *         archive_files → finalize_archive → done
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job_Export
 *
 * The real export job that users trigger from the Export tab.
 */
class EWPM_Job_Export extends EWPM_Job {

	/**
	 * Return the job type identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'export';
	}

	/**
	 * Return initial state for the export job.
	 *
	 * @param array<string,mixed> $init_params User params: components, exclusion_patterns,
	 *                                         exclusion_presets, save_as_backup, backup_name.
	 * @return array<string,mixed>
	 */
	protected function get_default_state( array $init_params ): array {
		$components = [];

		foreach ( EWPM_Export_Presets::get_components() as $comp ) {
			$components[ $comp['id'] ] = ! empty( $init_params['components'][ $comp['id'] ] ?? $comp['default'] );
		}

		// Build exclusion patterns from presets + custom.
		$exclusion_patterns = [];
		$presets            = EWPM_Export_Presets::get_exclusion_presets();
		$selected_presets   = $init_params['exclusion_presets'] ?? [];

		foreach ( $presets as $preset ) {
			if ( ! empty( $selected_presets[ $preset['id'] ] ) || ( ! isset( $selected_presets[ $preset['id'] ] ) && $preset['default'] ) ) {
				$exclusion_patterns = array_merge( $exclusion_patterns, $preset['patterns'] );
			}
		}

		// Custom patterns from textarea.
		if ( ! empty( $init_params['custom_exclusions'] ) ) {
			$custom = array_filter( array_map( 'trim', explode( "\n", $init_params['custom_exclusions'] ) ) );
			$exclusion_patterns = array_merge( $exclusion_patterns, $custom );
		}

		$save_as_backup = ! empty( $init_params['save_as_backup'] );

		return [
			'phase'              => 'init',
			'phase_label'        => __( 'Initializing', 'easy-wp-migration' ),
			'progress_percent'   => 0,
			'progress_label'     => __( 'Preparing export...', 'easy-wp-migration' ),
			'params'             => [
				'components'         => $components,
				'exclusion_patterns' => array_unique( $exclusion_patterns ),
				'save_as_backup'     => $save_as_backup,
				'backup_name'        => sanitize_file_name( $init_params['backup_name'] ?? '' ),
			],
			'archive_path'       => '',
			'archive_filename'   => '',
			'final_path'         => null,
			'scan_cursor'        => [ 'root_index' => 0, 'skip_count' => 0 ],
			'files_list_path'    => '',
			'db_cursor'          => null,
			'db_temp_sql_path'   => '',
			'db_tables'          => [],
			'file_archive_index' => 0,
			'stats'              => [
				'total_files'        => 0,
				'total_bytes_estimate' => 0,
				'files_archived'     => 0,
				'bytes_archived'     => 0,
				'db_rows'            => 0,
				'db_bytes'           => 0,
				'db_tables_done'     => 0,
			],
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

		// Check cancel flag.
		if ( $this->state->has_cancel_flag( $state['job_id'] ) ) {
			$state['cancelled']      = true;
			$state['phase_label']    = __( 'Cancelled', 'easy-wp-migration' );
			$state['progress_label'] = __( 'Export was cancelled.', 'easy-wp-migration' );
			return $state;
		}

		return match ( $state['phase'] ) {
			'init'             => $this->phase_init( $state ),
			'scan_files'       => $this->phase_scan_files( $state, $deadline ),
			'dump_database'    => $this->phase_dump_database( $state, $deadline ),
			'archive_database' => $this->phase_archive_database( $state ),
			'archive_files'    => $this->phase_archive_files( $state, $deadline ),
			'finalize_archive' => $this->phase_finalize_archive( $state ),
			default            => $state,
		};
	}

	/**
	 * Return final result after job is done.
	 *
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed> Result payload.
	 */
	protected function finalize( array $state ): array {
		$path = $state['final_path'] ?? $state['archive_path'];
		$size = file_exists( $path ) ? (int) filesize( $path ) : 0;

		$download_url = add_query_arg( [
			'action'   => 'ewpm_download_archive',
			'job_id'   => $state['job_id'],
			'_wpnonce' => wp_create_nonce( 'ewpm_download_archive' ),
		], admin_url( 'admin-ajax.php' ) );

		return [
			'download_url'    => $download_url,
			'filename'        => $state['archive_filename'],
			'size_bytes'      => $size,
			'size_human'      => size_format( $size ),
			'stats'           => $state['stats'],
			'warnings'        => $state['warnings'],
			'saved_as_backup' => $state['params']['save_as_backup'],
			'backup_path'     => $state['final_path'],
		];
	}

	/**
	 * Clean up partial output on cancel or error.
	 *
	 * Deletes the partial archive, temp SQL, and file list JSON.
	 * All paths verified to be inside tmp/ before unlink.
	 *
	 * @param array<string,mixed> $state Current state.
	 */
	protected function cleanup( array $state ): void {
		$paths = [
			$state['archive_path'] ?? '',
			$state['db_temp_sql_path'] ?? '',
			$state['files_list_path'] ?? '',
		];

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
	 * Init phase: create archive, write metadata placeholder, build roots.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_init( array $state ): array {
		$job_id     = $state['job_id'];
		$components = $state['params']['components'];
		$filename   = ewpm_generate_backup_filename( $state['params']['backup_name'] );

		$archive_path    = ewpm_get_tmp_dir() . "export-{$job_id}." . EWPM_ARCHIVE_EXTENSION;
		$files_list_path = ewpm_get_tmp_dir() . "files-{$job_id}.ndjson";
		$db_sql_path     = ewpm_get_tmp_dir() . "db-{$job_id}.sql";

		$state['archive_path']    = $archive_path;
		$state['archive_filename'] = $filename;
		$state['files_list_path'] = $files_list_path;
		$state['db_temp_sql_path'] = $db_sql_path;

		// Create archive and write metadata placeholder.
		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_write( $archive_path );

			// Update metadata with actual component selection.
			$metadata = $archiver->get_metadata();
			$metadata['components'] = [
				'database'         => ! empty( $components['database'] ),
				'themes'           => ! empty( $components['themes'] ),
				'plugins'          => ! empty( $components['plugins'] ),
				'media'            => ! empty( $components['media'] ),
				'other_wp_content' => ! empty( $components['other_wp_content'] ),
			];
			$archiver->update_metadata( $metadata );
			$archiver->close();
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		// Determine next phase based on selected components.
		$has_files = ! empty( $components['themes'] ) || ! empty( $components['plugins'] )
			|| ! empty( $components['media'] ) || ! empty( $components['other_wp_content'] );

		if ( $has_files ) {
			$state['phase']       = 'scan_files';
			$state['phase_label'] = __( 'Scanning files', 'easy-wp-migration' );
		} elseif ( ! empty( $components['database'] ) ) {
			$state['phase']       = 'dump_database';
			$state['phase_label'] = __( 'Dumping database', 'easy-wp-migration' );
		} else {
			$state['phase']       = 'finalize_archive';
			$state['phase_label'] = __( 'Finalizing', 'easy-wp-migration' );
		}

		$state['progress_percent'] = 1;
		$state['progress_label']   = __( 'Export initialized.', 'easy-wp-migration' );

		return $state;
	}

	/**
	 * Scan files phase: enumerate files via EWPM_File_Scanner.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_scan_files( array $state, float $deadline ): array {
		$components = $state['params']['components'];
		$scan_build = EWPM_Export_Presets::build_scan_roots( $components );

		$scanner = new EWPM_File_Scanner();
		$scanner->set_component_roots( $scan_build['roots'] );

		// Merge all exclusion patterns.
		$all_exclusions = array_merge(
			$state['params']['exclusion_patterns'],
			$scan_build['sibling_exclusions']
		);
		$scanner->set_exclusions( $all_exclusions );

		$remaining = max( 1, (int) ( $deadline - microtime( true ) ) );
		$result    = $scanner->scan( $remaining, $state['scan_cursor'] );

		// Append files to NDJSON file list.
		if ( ! empty( $result['files'] ) ) {
			$fh = fopen( $state['files_list_path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( $fh ) {
				foreach ( $result['files'] as $file ) {
					fwrite( $fh, wp_json_encode( $file ) . "\n" );
					$state['stats']['total_files']++;
					$state['stats']['total_bytes_estimate'] += $file['size'];
				}
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		$state['scan_cursor'] = $result['cursor'];
		$state['warnings']    = array_merge( $state['warnings'], $result['warnings'] );

		$state['progress_percent'] = $result['done'] ? 5 : 3;
		$state['progress_label']   = sprintf(
			/* translators: %s: number of files found */
			__( 'Scanned %s files...', 'easy-wp-migration' ),
			number_format( $state['stats']['total_files'] )
		);

		if ( $result['done'] ) {
			if ( ! empty( $components['database'] ) ) {
				$state['phase']       = 'dump_database';
				$state['phase_label'] = __( 'Dumping database', 'easy-wp-migration' );
			} elseif ( $state['stats']['total_files'] > 0 ) {
				$state['phase']       = 'archive_files';
				$state['phase_label'] = __( 'Archiving files', 'easy-wp-migration' );
			} else {
				$state['phase']       = 'finalize_archive';
				$state['phase_label'] = __( 'Finalizing', 'easy-wp-migration' );
			}
		}

		return $state;
	}

	/**
	 * Dump database phase: run the DB exporter into a temp SQL file.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_dump_database( array $state, float $deadline ): array {
		global $wpdb;

		$exporter = new EWPM_DB_Exporter( $wpdb, $state['db_temp_sql_path'] );

		// First tick of this phase: enumerate tables and write header.
		if ( null === $state['db_cursor'] ) {
			try {
				$table_result     = $exporter->get_table_list();
				$state['db_tables'] = $table_result['tables'];
				$state['warnings']  = array_merge( $state['warnings'], $table_result['warnings'] );

				$exporter->write_header();
			} catch ( EWPM_DB_Exporter_Exception $e ) {
				$state['error'] = $e->getMessage();
				return $state;
			}

			$state['db_cursor'] = [
				'table_index'   => 0,
				'table_cursor'  => [ 'last_id' => null, 'offset' => 0, 'rows_written' => 0 ],
			];
		}

		$tables      = $state['db_tables'];
		$table_count = count( $tables );
		$table_index = (int) $state['db_cursor']['table_index'];
		$table_cursor = $state['db_cursor']['table_cursor'];
		$chunk_size  = 1000;

		while ( $table_index < $table_count ) {
			// Check cancel flag.
			if ( $this->state->has_cancel_flag( $state['job_id'] ) ) {
				$state['cancelled']      = true;
				$state['phase_label']    = __( 'Cancelled', 'easy-wp-migration' );
				$state['progress_label'] = __( 'Export was cancelled.', 'easy-wp-migration' );
				return $state;
			}

			$table    = $tables[ $table_index ];
			$is_fresh = 0 === ( $table_cursor['rows_written'] ?? 0 );

			if ( $is_fresh ) {
				try {
					$exporter->write_table_structure( $table['name'] );
				} catch ( EWPM_DB_Exporter_Exception $e ) {
					$state['error'] = $e->getMessage();
					return $state;
				}
			}

			$remaining = max( 1, (int) ( $deadline - microtime( true ) ) );

			if ( $remaining <= 0 ) {
				$state['db_cursor'] = [ 'table_index' => $table_index, 'table_cursor' => $table_cursor ];
				$this->update_db_progress( $state );
				return $state;
			}

			try {
				$result = $exporter->write_table_rows( $table['name'], $table_cursor, $chunk_size, $remaining );
			} catch ( EWPM_DB_Exporter_Exception $e ) {
				$state['error'] = $e->getMessage();
				return $state;
			}

			$state['stats']['db_rows'] += $result['rows_written'] - ( $table_cursor['rows_written'] ?? 0 );

			if ( $result['stopped_for_budget'] ) {
				$state['db_cursor'] = [ 'table_index' => $table_index, 'table_cursor' => $result ];
				$this->update_db_progress( $state );
				return $state;
			}

			// Table done.
			++$table_index;
			$state['stats']['db_tables_done'] = $table_index;
			$table_cursor = [ 'last_id' => null, 'offset' => 0, 'rows_written' => 0 ];
		}

		// All tables done — write footer.
		try {
			$exporter->write_footer();
		} catch ( EWPM_DB_Exporter_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		if ( file_exists( $state['db_temp_sql_path'] ) ) {
			$state['stats']['db_bytes'] = (int) filesize( $state['db_temp_sql_path'] );
		}

		$state['db_cursor'] = [ 'table_index' => $table_index, 'table_cursor' => $table_cursor ];
		$state['phase']       = 'archive_database';
		$state['phase_label'] = __( 'Archiving database', 'easy-wp-migration' );
		$state['progress_percent'] = 25;
		$state['progress_label']   = sprintf(
			/* translators: 1: table count, 2: row count */
			__( 'Database dumped: %1$d tables, %2$s rows', 'easy-wp-migration' ),
			$table_count,
			number_format( $state['stats']['db_rows'] )
		);

		return $state;
	}

	/**
	 * Archive database phase: add the SQL file to the archive.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_archive_database( array $state ): array {
		$sql_path = $state['db_temp_sql_path'];

		if ( ! file_exists( $sql_path ) ) {
			$state['error'] = __( 'Database SQL file not found.', 'easy-wp-migration' );
			return $state;
		}

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_append( $state['archive_path'] );
			$archiver->add_file( 'database.sql', $sql_path );
			$archiver->close();
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		// Delete temp SQL file — it's now in the archive.
		$real_tmp = realpath( ewpm_get_tmp_dir() );
		$real_sql = realpath( $sql_path );

		if ( $real_sql && $real_tmp && str_starts_with( $real_sql, $real_tmp ) ) {
			@unlink( $sql_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Determine next phase.
		if ( $state['stats']['total_files'] > 0 ) {
			$state['phase']       = 'archive_files';
			$state['phase_label'] = __( 'Archiving files', 'easy-wp-migration' );
		} else {
			$state['phase']       = 'finalize_archive';
			$state['phase_label'] = __( 'Finalizing', 'easy-wp-migration' );
		}

		$state['progress_percent'] = 30;
		$state['progress_label']   = __( 'Database added to archive.', 'easy-wp-migration' );

		return $state;
	}

	/**
	 * Archive files phase: add scanned files to the archive in chunks.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_archive_files( array $state, float $deadline ): array {
		$file_list_path = $state['files_list_path'];
		$total_files    = $state['stats']['total_files'];
		$index          = (int) $state['file_archive_index'];

		if ( ! file_exists( $file_list_path ) || 0 === $total_files ) {
			$state['phase']       = 'finalize_archive';
			$state['phase_label'] = __( 'Finalizing', 'easy-wp-migration' );
			return $state;
		}

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_append( $state['archive_path'] );
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		$fh = new \SplFileObject( $file_list_path, 'r' );

		if ( $index > 0 ) {
			$fh->seek( $index );
		}

		try {
			while ( ! $fh->eof() ) {
				// Check cancel flag periodically.
				if ( 0 === $state['stats']['files_archived'] % 200 && $state['stats']['files_archived'] > 0 ) {
					if ( $this->state->has_cancel_flag( $state['job_id'] ) ) {
						$archiver->close();
						$state['cancelled']      = true;
						$state['phase_label']    = __( 'Cancelled', 'easy-wp-migration' );
						$state['progress_label'] = __( 'Export was cancelled.', 'easy-wp-migration' );
						return $state;
					}
				}

				$line = trim( $fh->current() );
				$fh->next();

				if ( empty( $line ) ) {
					++$index;
					continue;
				}

				$entry = json_decode( $line, true );

				if ( ! is_array( $entry ) || empty( $entry['source_path'] ) ) {
					++$index;
					continue;
				}

				// Skip files that no longer exist (may have been deleted since scan).
				if ( ! file_exists( $entry['source_path'] ) || ! is_readable( $entry['source_path'] ) ) {
					$state['warnings'][] = sprintf( 'File missing at archive time, skipped: %s', $entry['source_path'] );
					++$index;
					continue;
				}

				try {
					$archiver->add_file( $entry['archive_path'], $entry['source_path'] );
				} catch ( EWPM_Archiver_Exception $e ) {
					$state['warnings'][] = sprintf( 'Failed to archive: %s (%s)', $entry['source_path'], $e->getMessage() );
					++$index;
					continue;
				}

				$state['stats']['files_archived']++;
				$state['stats']['bytes_archived'] += $entry['size'] ?? 0;
				++$index;

				// Check time budget.
				if ( microtime( true ) >= $deadline ) {
					$archiver->close();
					$state['file_archive_index'] = $index;
					$this->update_archive_progress( $state );
					return $state;
				}
			}
		} catch ( \Throwable $e ) {
			try {
				$archiver->close();
			} catch ( \Throwable $close_err ) {
				// Ignore close errors during error handling.
			}
			$state['error'] = sprintf( 'Error archiving files: %s', $e->getMessage() );
			return $state;
		}

		$archiver->close();
		$state['file_archive_index'] = $index;
		$state['phase']              = 'finalize_archive';
		$state['phase_label']        = __( 'Finalizing', 'easy-wp-migration' );
		$state['progress_percent']   = 99;
		$state['progress_label']     = __( 'Finalizing archive...', 'easy-wp-migration' );

		return $state;
	}

	/**
	 * Finalize phase: update metadata, close archive, move to backups if requested.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_finalize_archive( array $state ): array {
		// Update metadata with final stats.
		$metadata = EWPM_Archiver_Metadata::build_for_export( $state['params']['components'] );

		$metadata['stats'] = [
			'total_files' => $state['stats']['files_archived'],
			'total_bytes' => $state['stats']['bytes_archived'],
			'db_tables'   => count( $state['db_tables'] ),
			'db_rows'     => $state['stats']['db_rows'],
		];

		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_append( $state['archive_path'] );
			$archiver->update_metadata( $metadata );
			$archiver->close();
		} catch ( EWPM_Archiver_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		// Delete the file list JSON — no longer needed.
		$this->safe_unlink_in_tmp( $state['files_list_path'] ?? '' );

		// Move to backups/ if requested.
		$state['final_path'] = $state['archive_path'];

		if ( ! empty( $state['params']['save_as_backup'] ) ) {
			$dest = ewpm_get_backups_dir() . $state['archive_filename'];

			if ( ! is_dir( ewpm_get_backups_dir() ) ) {
				wp_mkdir_p( ewpm_get_backups_dir() );
			}

			// Try atomic rename first, fall back to copy+verify+delete.
			if ( @rename( $state['archive_path'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$state['final_path'] = $dest;
			} else {
				// Cross-filesystem fallback.
				if ( copy( $state['archive_path'], $dest ) ) {
					$orig_size = filesize( $state['archive_path'] );
					$dest_size = filesize( $dest );

					if ( $orig_size === $dest_size ) {
						$this->safe_unlink_in_tmp( $state['archive_path'] );
						$state['final_path'] = $dest;
					} else {
						@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$state['warnings'][] = __( 'Failed to move archive to backups — kept in temporary storage.', 'easy-wp-migration' );
					}
				} else {
					$state['warnings'][] = __( 'Failed to copy archive to backups — kept in temporary storage.', 'easy-wp-migration' );
				}
			}
		}

		$final_size = file_exists( $state['final_path'] ) ? (int) filesize( $state['final_path'] ) : 0;

		$state['done']             = true;
		$state['progress_percent'] = 100;
		$state['phase_label']      = __( 'Complete', 'easy-wp-migration' );
		$state['progress_label']   = sprintf(
			/* translators: 1: filename, 2: file size */
			__( 'Export complete: %1$s (%2$s)', 'easy-wp-migration' ),
			$state['archive_filename'],
			size_format( $final_size )
		);

		return $state;
	}

	/**
	 * Update progress during the dump_database phase.
	 *
	 * @param array<string,mixed> &$state State to update.
	 */
	private function update_db_progress( array &$state ): void {
		$tables = $state['db_tables'];
		$total  = count( $tables );
		$done   = (int) ( $state['stats']['db_tables_done'] ?? 0 );

		// DB dump is 5-25% of total progress.
		$db_progress = $total > 0 ? ( $done / $total ) : 0;
		$state['progress_percent'] = 5 + (int) ( $db_progress * 20 );

		if ( isset( $state['db_cursor']['table_index'] ) ) {
			$idx = (int) $state['db_cursor']['table_index'];

			if ( $idx < $total ) {
				$table  = $tables[ $idx ];
				$cursor = $state['db_cursor']['table_cursor'] ?? [];

				$state['progress_label'] = sprintf(
					/* translators: 1: current table number, 2: total tables, 3: table name, 4: rows written, 5: total rows */
					__( 'Table %1$d of %2$d (%3$s): %4$s / %5$s rows', 'easy-wp-migration' ),
					$idx + 1,
					$total,
					$table['name'],
					number_format( $cursor['rows_written'] ?? 0 ),
					number_format( $table['row_count'] )
				);
			}
		}
	}

	/**
	 * Update progress during the archive_files phase.
	 *
	 * @param array<string,mixed> &$state State to update.
	 */
	private function update_archive_progress( array &$state ): void {
		$archived = $state['stats']['files_archived'];
		$total    = $state['stats']['total_files'];

		// archive_files is 30-99% of total progress.
		$file_progress = $total > 0 ? ( $archived / $total ) : 0;
		$state['progress_percent'] = 30 + (int) ( $file_progress * 69 );

		$state['progress_label'] = sprintf(
			/* translators: 1: files archived, 2: total files, 3: bytes archived */
			__( '%1$s / %2$s files (%3$s archived)', 'easy-wp-migration' ),
			number_format( $archived ),
			number_format( $total ),
			size_format( $state['stats']['bytes_archived'] )
		);
	}

	/**
	 * Safely unlink a file only if it's inside tmp/.
	 *
	 * @param string $path File path to delete.
	 */
	private function safe_unlink_in_tmp( string $path ): void {
		if ( empty( $path ) || ! file_exists( $path ) ) {
			return;
		}

		$real_tmp  = realpath( ewpm_get_tmp_dir() );
		$real_path = realpath( $path );

		if ( $real_path && $real_tmp && str_starts_with( $real_path, $real_tmp ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
