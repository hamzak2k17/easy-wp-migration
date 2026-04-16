<?php
/**
 * Dummy job for testing the state machine.
 *
 * Simulates a 3-phase workflow: init → counting → finalize. Proves the
 * job framework works end-to-end without needing Phase 4+ to exist.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job_Dummy
 *
 * Test/dev job that counts from 0 to 100 with configurable delay.
 */
class EWPM_Job_Dummy extends EWPM_Job {

	/**
	 * Return the job type identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'dummy';
	}

	/**
	 * Return initial state for the dummy job.
	 *
	 * @param array<string,mixed> $init_params Accepts 'delay_ms' (default 50).
	 * @return array<string,mixed>
	 */
	protected function get_default_state( array $init_params ): array {
		$delay_ms = max( 0, (int) ( $init_params['delay_ms'] ?? 50 ) );

		return [
			'phase'            => 'init',
			'phase_label'      => 'Initializing',
			'progress_percent' => 0,
			'progress_label'   => 'Starting dummy job...',
			'counter'          => 0,
			'target'           => 100,
			'delay_us'         => $delay_ms * 1000,
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
			'init'     => $this->phase_init( $state ),
			'counting' => $this->phase_counting( $state, $deadline ),
			'finalize' => $this->phase_finalize( $state ),
			default    => $state,
		};
	}

	/**
	 * Init phase: transition to counting.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_init( array $state ): array {
		$state['phase']            = 'counting';
		$state['phase_label']      = 'Counting';
		$state['progress_percent'] = 0;
		$state['progress_label']   = 'Count: 0 / ' . $state['target'];

		return $state;
	}

	/**
	 * Counting phase: increment counter respecting time budget and cancellation.
	 *
	 * @param array<string,mixed> $state    Current state.
	 * @param float               $deadline Microtime deadline.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_counting( array $state, float $deadline ): array {
		$counter = (int) $state['counter'];
		$target  = (int) $state['target'];
		$delay   = (int) $state['delay_us'];

		while ( $counter < $target ) {
			// Check cancellation: in-memory flag OR lock-free cancel file.
			if ( ! empty( $state['cancel_requested'] ) || $this->state->has_cancel_flag( $state['job_id'] ) ) {
				$state['cancelled']      = true;
				$state['phase_label']    = 'Cancelled';
				$state['progress_label'] = "Cancelled at count {$counter}";
				$state['counter']        = $counter;
				return $state;
			}

			// Check time budget.
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			usleep( $delay );
			++$counter;
		}

		$state['counter']          = $counter;
		$state['progress_percent'] = (int) ( ( $counter / $target ) * 100 );
		$state['progress_label']   = "Count: {$counter} / {$target}";

		// Transition to finalize phase when counting is complete.
		if ( $counter >= $target ) {
			$state['phase']       = 'finalize';
			$state['phase_label'] = 'Finalizing';
		}

		return $state;
	}

	/**
	 * Finalize phase: mark the job as done.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_finalize( array $state ): array {
		$state['done']             = true;
		$state['progress_percent'] = 100;
		$state['phase_label']      = 'Complete';
		$state['progress_label']   = 'Dummy job finished.';

		return $state;
	}

	/**
	 * Return final result after job is done.
	 *
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed> Result payload.
	 */
	protected function finalize( array $state ): array {
		return [
			'message'       => 'Dummy job completed successfully.',
			'total_counted' => $state['counter'] ?? 0,
		];
	}

	/**
	 * Clean up partial output. Dummy job has no files to clean up.
	 *
	 * @param array<string,mixed> $state Current state.
	 */
	protected function cleanup( array $state ): void {
		// Nothing to clean up for the dummy job.
	}
}
