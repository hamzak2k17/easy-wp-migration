<?php
/**
 * AJAX endpoint handler.
 *
 * Registers all wp_ajax_ endpoints for the job framework. Every endpoint
 * validates nonce and capability, sanitizes input, dispatches to the
 * appropriate job method, and returns JSON.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Ajax
 *
 * Routes AJAX requests to the job framework.
 */
class EWPM_Ajax {

	/**
	 * Constructor — registers AJAX action hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_ewpm_job_start', [ $this, 'handle_job_start' ] );
		add_action( 'wp_ajax_ewpm_job_tick', [ $this, 'handle_job_tick' ] );
		add_action( 'wp_ajax_ewpm_job_cancel', [ $this, 'handle_job_cancel' ] );
		add_action( 'wp_ajax_ewpm_job_progress', [ $this, 'handle_job_progress' ] );
		add_action( 'wp_ajax_ewpm_job_finalize', [ $this, 'handle_job_finalize' ] );

		// Dev-only endpoints — registered conditionally.
		if ( true === EWPM_DEV_MODE ) {
			add_action( 'wp_ajax_ewpm_dev_delete_state', [ $this, 'handle_dev_delete_state' ] );
			add_action( 'wp_ajax_ewpm_dev_cleanup', [ $this, 'handle_dev_cleanup' ] );
		}
	}

	/**
	 * Start a new job.
	 *
	 * POST params: job_type (string), params (JSON string, optional).
	 * Returns: { job_id: string }
	 */
	public function handle_job_start(): void {
		$this->verify_request();

		// Clean up stale jobs on every start — cheap housekeeping.
		EWPM_State::cleanup_stale();

		$job_type   = sanitize_text_field( wp_unslash( $_POST['job_type'] ?? '' ) );
		$params_raw = isset( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$params     = json_decode( $params_raw, true );

		if ( ! is_array( $params ) ) {
			$params = [];
		}

		try {
			$job    = EWPM_Job_Registry::instance()->get( $job_type );
			$job_id = $job->start( $params );

			wp_send_json_success( [ 'job_id' => $job_id ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Execute one tick of work on an existing job.
	 *
	 * POST params: job_id (string).
	 * Returns: progress payload.
	 */
	public function handle_job_tick(): void {
		$this->verify_request();

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );

		try {
			$state = ( new EWPM_State() )->load( $job_id );
			$job   = EWPM_Job_Registry::instance()->get( $state['type'] );

			wp_send_json_success( $job->tick( $job_id ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Request cancellation of a running job.
	 *
	 * POST params: job_id (string).
	 * Returns: { cancelled: true }
	 */
	public function handle_job_cancel(): void {
		$this->verify_request();

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );

		try {
			$state = ( new EWPM_State() )->load( $job_id );
			$job   = EWPM_Job_Registry::instance()->get( $state['type'] );
			$job->cancel( $job_id );

			wp_send_json_success( [ 'cancelled' => true ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Read-only progress check without running work.
	 *
	 * POST params: job_id (string).
	 * Returns: progress payload.
	 */
	public function handle_job_progress(): void {
		$this->verify_request();

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );

		try {
			$state = ( new EWPM_State() )->load( $job_id );
			$job   = EWPM_Job_Registry::instance()->get( $state['type'] );

			wp_send_json_success( $job->get_progress( $job_id ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Run finalization on a completed job.
	 *
	 * POST params: job_id (string).
	 * Returns: final result payload.
	 */
	public function handle_job_finalize(): void {
		$this->verify_request();

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );

		try {
			$state = ( new EWPM_State() )->load( $job_id );
			$job   = EWPM_Job_Registry::instance()->get( $state['type'] );

			wp_send_json_success( $job->do_finalize( $job_id ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Dev-only: delete a single job's state file.
	 *
	 * POST params: job_id (string).
	 */
	public function handle_dev_delete_state(): void {
		if ( true !== EWPM_DEV_MODE ) {
			wp_send_json_error( [ 'error' => 'Dev mode is not enabled.' ], 403 );
		}

		$this->verify_request();

		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );

		try {
			$state_manager = new EWPM_State();
			$state_manager->delete( $job_id );

			wp_send_json_success( [ 'deleted' => true ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Dev-only: run stale cleanup immediately.
	 *
	 * Returns: { deleted: int }
	 */
	public function handle_dev_cleanup(): void {
		if ( true !== EWPM_DEV_MODE ) {
			wp_send_json_error( [ 'error' => 'Dev mode is not enabled.' ], 403 );
		}

		$this->verify_request();

		try {
			$deleted = EWPM_State::cleanup_stale( 0 );
			wp_send_json_success( [ 'deleted' => $deleted ] );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'error' => $e->getMessage() ], 400 );
		}
	}

	/**
	 * Verify nonce and capability for the current request.
	 *
	 * Sends a JSON error response and dies if verification fails.
	 */
	private function verify_request(): void {
		check_ajax_referer( 'ewpm_job', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'error' => 'Insufficient permissions.' ], 403 );
		}
	}
}
