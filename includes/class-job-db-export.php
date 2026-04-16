<?php
/**
 * Database export job.
 *
 * Wraps EWPM_DB_Exporter in the Phase 3 job framework. Produces a single
 * database.sql file in tmp/. This is a sub-job for testing Phase 4 in
 * isolation; Phase 5 will compose this into the full export job.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job_DB_Export
 *
 * Chunked, resumable database export driven by the tick-based job framework.
 */
class EWPM_Job_DB_Export extends EWPM_Job {

	/**
	 * Return the job type identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'db_export';
	}

	/**
	 * Return initial state for the database export job.
	 *
	 * @param array<string,mixed> $init_params Accepts 'chunk_size' (default 1000).
	 * @return array<string,mixed>
	 */
	protected function get_default_state( array $init_params ): array {
		$chunk_size = max( 100, min( 10000, (int) ( $init_params['chunk_size'] ?? 1000 ) ) );

		return [
			'phase'                => 'init',
			'phase_label'          => 'Initializing',
			'progress_percent'     => 0,
			'progress_label'       => 'Preparing database export...',
			'output_path'          => '',
			'tables'               => [],
			'current_table_index'  => 0,
			'current_table_cursor' => [ 'last_id' => null, 'offset' => 0, 'rows_written' => 0 ],
			'totals'               => [
				'tables_done'  => 0,
				'rows_written' => 0,
				'bytes_written' => 0,
			],
			'total_bytes_estimate' => 0,
			'warnings'             => [],
			'chunk_size'           => $chunk_size,
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

		return match ( $state['phase'] ) {
			'init'         => $this->phase_init( $state ),
			'dump_tables'  => $this->phase_dump_tables( $state, $deadline ),
			'finalize_sql' => $this->phase_finalize_sql( $state ),
			default        => $state,
		};
	}

	/**
	 * Return final result after job is done.
	 *
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed> Result payload.
	 */
	protected function finalize( array $state ): array {
		return [
			'output_path'  => $state['output_path'],
			'tables_count' => count( $state['tables'] ),
			'rows_count'   => $state['totals']['rows_written'],
			'bytes'        => $state['totals']['bytes_written'],
			'warnings'     => $state['warnings'],
		];
	}

	/**
	 * Clean up partial output on cancel or error.
	 *
	 * Deletes the partial SQL file from tmp/. Guards with realpath check.
	 *
	 * @param array<string,mixed> $state Current state.
	 */
	protected function cleanup( array $state ): void {
		$path = $state['output_path'] ?? '';

		if ( empty( $path ) || ! file_exists( $path ) ) {
			return;
		}

		$real_path = realpath( $path );
		$real_tmp  = realpath( ewpm_get_tmp_dir() );

		if ( $real_path && $real_tmp && str_starts_with( $real_path, $real_tmp ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Init phase: enumerate tables, write SQL header.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_init( array $state ): array {
		global $wpdb;

		$job_id     = $state['job_id'];
		$output_path = ewpm_get_tmp_dir() . "db-export-{$job_id}.sql";

		$state['output_path'] = $output_path;

		$exporter = new EWPM_DB_Exporter( $wpdb, $output_path );

		try {
			$result = $exporter->get_table_list();
		} catch ( EWPM_DB_Exporter_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		$state['tables']   = $result['tables'];
		$state['warnings'] = array_merge( $state['warnings'], $result['warnings'] );

		if ( empty( $result['tables'] ) ) {
			$state['error'] = 'No tables found matching the WordPress prefix.';
			return $state;
		}

		// Calculate total bytes estimate for progress.
		$total_bytes = 0;

		foreach ( $result['tables'] as $table ) {
			$total_bytes += $table['size_bytes'];
		}

		$state['total_bytes_estimate'] = max( 1, $total_bytes );

		try {
			$exporter->write_header();
		} catch ( EWPM_DB_Exporter_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		$state['phase']       = 'dump_tables';
		$state['phase_label'] = 'Dumping tables';

		$table_count = count( $result['tables'] );
		$first_table = $result['tables'][0]['name'] ?? '';
		$state['progress_label'] = sprintf(
			'Table 1 of %d (%s): starting...',
			$table_count,
			$first_table
		);

		return $state;
	}

	/**
	 * Dump tables phase: iterate through tables, write structure + rows.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_dump_tables( array $state, float $deadline ): array {
		global $wpdb;

		$tables       = $state['tables'];
		$table_count  = count( $tables );
		$index        = (int) $state['current_table_index'];
		$cursor       = $state['current_table_cursor'];
		$chunk_size   = (int) $state['chunk_size'];
		$exporter     = new EWPM_DB_Exporter( $wpdb, $state['output_path'] );

		while ( $index < $table_count ) {
			// Check cancel flag between tables.
			if ( $this->state->has_cancel_flag( $state['job_id'] ) ) {
				$state['cancelled']      = true;
				$state['phase_label']    = 'Cancelled';
				$state['progress_label'] = sprintf( 'Cancelled during table %s', $tables[ $index ]['name'] );
				return $state;
			}

			$table    = $tables[ $index ];
			$is_fresh = 0 === ( $cursor['rows_written'] ?? 0 );

			// Write table structure on first visit.
			if ( $is_fresh ) {
				try {
					$exporter->write_table_structure( $table['name'] );
				} catch ( EWPM_DB_Exporter_Exception $e ) {
					$state['error'] = $e->getMessage();
					return $state;
				}
			}

			// Calculate remaining time budget for this chunk.
			$remaining = max( 1, (int) ( $deadline - microtime( true ) ) );

			if ( $remaining <= 0 ) {
				$state['current_table_index']  = $index;
				$state['current_table_cursor'] = $cursor;
				$this->update_progress( $state );
				return $state;
			}

			try {
				$result = $exporter->write_table_rows(
					$table['name'],
					$cursor,
					$chunk_size,
					$remaining
				);
			} catch ( EWPM_DB_Exporter_Exception $e ) {
				$state['error'] = $e->getMessage();
				return $state;
			}

			// Update totals with this chunk's work.
			$state['totals']['rows_written'] += $result['rows_written'] - ( $cursor['rows_written'] ?? 0 );

			// Update bytes written estimate.
			if ( file_exists( $state['output_path'] ) ) {
				$state['totals']['bytes_written'] = (int) filesize( $state['output_path'] );
			}

			if ( $result['stopped_for_budget'] ) {
				// Save cursor and return — resume on next tick.
				$state['current_table_index']  = $index;
				$state['current_table_cursor'] = $result;
				$this->update_progress( $state );
				return $state;
			}

			// Table done — advance to next.
			++$index;
			$state['totals']['tables_done'] = $index;
			$cursor = [ 'last_id' => null, 'offset' => 0, 'rows_written' => 0 ];
		}

		// All tables done — move to finalize.
		$state['current_table_index']  = $index;
		$state['current_table_cursor'] = $cursor;
		$state['phase']                = 'finalize_sql';
		$state['phase_label']          = 'Finalizing';
		$state['progress_percent']     = 99;
		$state['progress_label']       = 'Writing SQL footer...';

		return $state;
	}

	/**
	 * Finalize phase: write SQL footer, mark done.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_finalize_sql( array $state ): array {
		global $wpdb;

		$exporter = new EWPM_DB_Exporter( $wpdb, $state['output_path'] );

		try {
			$exporter->write_footer();
		} catch ( EWPM_DB_Exporter_Exception $e ) {
			$state['error'] = $e->getMessage();
			return $state;
		}

		// Final bytes.
		if ( file_exists( $state['output_path'] ) ) {
			$state['totals']['bytes_written'] = (int) filesize( $state['output_path'] );
		}

		$state['done']             = true;
		$state['progress_percent'] = 100;
		$state['phase_label']      = 'Complete';
		$state['progress_label']   = sprintf(
			'Exported %d tables, %s rows, %s',
			count( $state['tables'] ),
			number_format( $state['totals']['rows_written'] ),
			size_format( $state['totals']['bytes_written'] )
		);

		return $state;
	}

	/**
	 * Update progress percentage and label in state.
	 *
	 * @param array<string,mixed> &$state State to update (by reference).
	 */
	private function update_progress( array &$state ): void {
		$tables      = $state['tables'];
		$table_count = count( $tables );
		$index       = (int) $state['current_table_index'];
		$cursor      = $state['current_table_cursor'];
		$estimate    = (int) $state['total_bytes_estimate'];

		// Progress based on bytes written vs estimate.
		if ( $estimate > 0 && $state['totals']['bytes_written'] > 0 ) {
			$state['progress_percent'] = min( 98, (int) ( ( $state['totals']['bytes_written'] / $estimate ) * 100 ) );
		} else {
			$state['progress_percent'] = $table_count > 0
				? min( 98, (int) ( ( $state['totals']['tables_done'] / $table_count ) * 100 ) )
				: 0;
		}

		// Progress label.
		if ( $index < $table_count ) {
			$table     = $tables[ $index ];
			$row_count = $table['row_count'];
			$written   = $cursor['rows_written'] ?? 0;

			$state['progress_label'] = sprintf(
				'Table %d of %d (%s): %s / %s rows',
				$index + 1,
				$table_count,
				$table['name'],
				number_format( $written ),
				number_format( $row_count )
			);
		}
	}
}
