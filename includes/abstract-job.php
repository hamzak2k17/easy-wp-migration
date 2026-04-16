<?php
/**
 * Abstract base class for all jobs.
 *
 * Every long-running operation (export, import, URL pull, restore) extends
 * this class. A Job has phases and a cursor. Each AJAX "tick" gives the
 * job a time budget; the job saves its state to a JSON file in tmp/ so it
 * can resume on the next tick.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job
 *
 * Abstract base for resumable, chunked jobs.
 */
abstract class EWPM_Job {

	/**
	 * State persistence layer.
	 */
	protected readonly EWPM_State $state;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->state = new EWPM_State();
	}

	/**
	 * Return the job type identifier (e.g. "export", "import", "dummy").
	 *
	 * @return string
	 */
	abstract public function get_type(): string;

	/**
	 * Return the initial state structure for this job type.
	 *
	 * Must include at minimum: phase, phase_label, progress_percent,
	 * progress_label. The base class merges in standard fields.
	 *
	 * @param array<string,mixed> $init_params User-supplied parameters.
	 * @return array<string,mixed>
	 */
	abstract protected function get_default_state( array $init_params ): array;

	/**
	 * Do work for up to $time_budget_seconds.
	 *
	 * Must return the updated state array. The state MUST include:
	 * phase, phase_label, progress_percent, progress_label, done,
	 * cancelled, error.
	 *
	 * @param array<string,mixed> $state               Current state.
	 * @param int                 $time_budget_seconds  Max seconds of work.
	 * @return array<string,mixed> Updated state.
	 */
	abstract protected function run_tick( array $state, int $time_budget_seconds ): array;

	/**
	 * Called once after done is true. Return final result for the user.
	 *
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed> Result payload (e.g. download URL, stats).
	 * @throws EWPM_State_Exception If cleanup or finalization fails.
	 */
	abstract protected function finalize( array $state ): array;

	/**
	 * Clean up partial output on cancel or error.
	 *
	 * CRITICAL: cleanup only touches files in tmp/ or partial archive files
	 * the job created. Cleanup MUST NEVER touch anything in backups/.
	 *
	 * @param array<string,mixed> $state Current state at time of cleanup.
	 */
	abstract protected function cleanup( array $state ): void;

	/**
	 * Start a new job. Generates a job_id, saves initial state.
	 *
	 * @param array<string,mixed> $init_params User-supplied parameters.
	 * @return string The generated job_id.
	 */
	public function start( array $init_params ): string {
		$job_id = bin2hex( random_bytes( 8 ) );

		$base = [
			'job_id'           => $job_id,
			'type'             => $this->get_type(),
			'created_at'       => gmdate( 'c' ),
			'updated_at'       => gmdate( 'c' ),
			'phase'            => '',
			'phase_label'      => '',
			'progress_percent' => 0,
			'progress_label'   => '',
			'done'             => false,
			'cancelled'        => false,
			'error'            => null,
			'cancel_requested' => false,
		];

		$default = $this->get_default_state( $init_params );
		$state   = array_merge( $base, $default );

		$this->state->save( $job_id, $state );

		return $job_id;
	}

	/**
	 * Execute one tick of work. Loads state with lock, runs work, saves.
	 *
	 * @param string $job_id The job identifier.
	 * @return array<string,mixed> Public progress payload.
	 * @throws EWPM_State_Exception On state I/O errors.
	 */
	public function tick( string $job_id ): array {
		$this->state->acquire_lock( $job_id );

		try {
			$state = $this->state->load( $job_id );

			// Already terminal — nothing to do.
			if ( $state['done'] || $state['cancelled'] ) {
				return $this->get_public_progress( $state );
			}

			// Handle cancellation before doing work. Check both the
			// in-state flag and the separate cancel flag file (which
			// can be written without the lock by a concurrent request).
			if ( ! empty( $state['cancel_requested'] ) || $this->state->has_cancel_flag( $job_id ) ) {
				$this->cleanup( $state );
				$this->state->clear_cancel_flag( $job_id );
				$state['cancelled']      = true;
				$state['cancel_requested'] = true;
				$state['phase_label']    = 'Cancelled';
				$state['progress_label'] = 'Job was cancelled.';
				$state['updated_at']     = gmdate( 'c' );
				$this->state->save( $job_id, $state );
				return $this->get_public_progress( $state );
			}

			$budget = (int) EWPM_TICK_BUDGET_SECONDS;
			$state  = $this->run_tick( $state, $budget );
			$state['updated_at'] = gmdate( 'c' );

			// If run_tick flagged an error, clean up partial output.
			if ( ! empty( $state['error'] ) ) {
				$this->cleanup( $state );
			}

			// If run_tick detected mid-tick cancellation, clean up.
			if ( ! empty( $state['cancelled'] ) ) {
				$this->cleanup( $state );
			}

			$this->state->save( $job_id, $state );
			return $this->get_public_progress( $state );
		} finally {
			$this->state->release_lock( $job_id );
		}
	}

	/**
	 * Request cancellation via a flag file.
	 *
	 * Uses a separate cancel flag file that does NOT require the state lock,
	 * so cancellation can be requested even while a tick is running. The tick
	 * checks for this flag at the top of each iteration.
	 *
	 * @param string $job_id The job identifier.
	 */
	public function cancel( string $job_id ): void {
		$this->state->set_cancel_flag( $job_id );

	}

	/**
	 * Read-only progress check. Returns the public payload without running work.
	 *
	 * @param string $job_id The job identifier.
	 * @return array<string,mixed> Public progress payload.
	 */
	public function get_progress( string $job_id ): array {
		$state = $this->state->load( $job_id );
		return $this->get_public_progress( $state );
	}

	/**
	 * Run finalization after the job is done.
	 *
	 * @param string $job_id The job identifier.
	 * @return array<string,mixed> Final result payload.
	 * @throws EWPM_State_Exception If the job is not yet done.
	 */
	public function do_finalize( string $job_id ): array {
		$this->state->acquire_lock( $job_id );

		try {
			$state = $this->state->load( $job_id );

			if ( ! $state['done'] ) {
				throw new EWPM_State_Exception(
					"Cannot finalize job {$job_id}: job is not yet done."
				);
			}

			return $this->finalize( $state );
		} finally {
			$this->state->release_lock( $job_id );
		}
	}

	/**
	 * Extract public-facing progress fields from internal state.
	 *
	 * Strips internal fields (cursors, file lists, etc.) that concrete
	 * jobs may store in state.
	 *
	 * @param array<string,mixed> $state Full internal state.
	 * @return array<string,mixed> Public progress payload.
	 */
	protected function get_public_progress( array $state ): array {
		return [
			'job_id'           => $state['job_id'] ?? '',
			'type'             => $state['type'] ?? '',
			'phase'            => $state['phase'] ?? '',
			'phase_label'      => $state['phase_label'] ?? '',
			'progress_percent' => $state['progress_percent'] ?? 0,
			'progress_label'   => $state['progress_label'] ?? '',
			'done'             => $state['done'] ?? false,
			'cancelled'        => $state['cancelled'] ?? false,
			'error'            => $state['error'] ?? null,
		];
	}
}
