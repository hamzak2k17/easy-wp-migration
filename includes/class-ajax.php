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
		add_action( 'wp_ajax_ewpm_download_archive', [ $this, 'handle_download_archive' ] );

		// Dev-only endpoints — registered conditionally.
		if ( true === EWPM_DEV_MODE ) {
			add_action( 'wp_ajax_ewpm_dev_delete_state', [ $this, 'handle_dev_delete_state' ] );
			add_action( 'wp_ajax_ewpm_dev_cleanup', [ $this, 'handle_dev_cleanup' ] );
			add_action( 'wp_ajax_ewpm_dev_download_sql', [ $this, 'handle_dev_download_sql' ] );
			add_action( 'wp_ajax_ewpm_dev_list_backups', [ $this, 'handle_dev_list_backups' ] );
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
	 * Stream a completed archive file for download.
	 *
	 * Accepts job_id (for tmp-stored archives) or backup_filename (for
	 * backups-stored archives). Validates path is inside tmp/ or backups/.
	 */
	public function handle_download_archive(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'easy-wp-migration' ), 403 );
		}

		check_admin_referer( 'ewpm_download_archive' );

		$job_id   = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) );
		$filename = sanitize_file_name( wp_unslash( $_GET['backup_filename'] ?? '' ) );

		$file_path = '';
		$file_name = '';

		if ( ! empty( $job_id ) ) {
			try {
				$state = ( new EWPM_State() )->load( $job_id );
			} catch ( EWPM_State_Exception $e ) {
				wp_die( esc_html( $e->getMessage() ), 400 );
			}

			$file_path = $state['final_path'] ?? $state['archive_path'] ?? '';
			$file_name = $state['archive_filename'] ?? basename( $file_path );
		} elseif ( ! empty( $filename ) ) {
			$file_path = ewpm_get_backups_dir() . $filename;
			$file_name = $filename;
		}

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Archive file not found.', 'easy-wp-migration' ), 404 );
		}

		// Verify path is inside tmp/ or backups/.
		$real_path    = realpath( $file_path );
		$real_tmp     = realpath( ewpm_get_tmp_dir() );
		$real_backups = realpath( ewpm_get_backups_dir() );
		$allowed      = false;

		if ( $real_path && $real_tmp && str_starts_with( $real_path, $real_tmp ) ) {
			$allowed = true;
		}

		if ( $real_path && $real_backups && str_starts_with( $real_path, $real_backups ) ) {
			$allowed = true;
		}

		if ( ! $allowed ) {
			wp_die( esc_html__( 'File path is outside the allowed directory.', 'easy-wp-migration' ), 403 );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . filesize( $real_path ) );

		readfile( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Dev-only: list backup archives in the backups/ folder.
	 *
	 * Returns filename, size, date for each .ezmig file.
	 */
	public function handle_dev_list_backups(): void {
		if ( true !== EWPM_DEV_MODE || ! defined( 'WP_DEBUG' ) || true !== WP_DEBUG ) {
			wp_send_json_error( [ 'error' => 'Dev mode is not enabled.' ], 403 );
		}

		$this->verify_request();

		$backups_dir = ewpm_get_backups_dir();
		$result      = [];

		if ( is_dir( $backups_dir ) ) {
			$files = glob( $backups_dir . '*.' . EWPM_ARCHIVE_EXTENSION );

			if ( $files ) {
				foreach ( $files as $file ) {
					$result[] = [
						'filename'   => basename( $file ),
						'path'       => $file,
						'size'       => (int) filesize( $file ),
						'size_human' => size_format( filesize( $file ) ),
						'date'       => gmdate( 'Y-m-d H:i:s', filemtime( $file ) ),
					];
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * Dev-only: stream a completed DB export SQL file for download.
	 *
	 * Uses GET params: job_id, _wpnonce.
	 * Streams with readfile() — does not load file into memory.
	 */
	public function handle_dev_download_sql(): void {
		if ( true !== EWPM_DEV_MODE || ! defined( 'WP_DEBUG' ) || true !== WP_DEBUG ) {
			wp_die( esc_html__( 'Dev mode is not enabled.', 'easy-wp-migration' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'easy-wp-migration' ), 403 );
		}

		check_admin_referer( 'ewpm_dev_download_sql' );

		$job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) );

		if ( empty( $job_id ) ) {
			wp_die( esc_html__( 'Missing job_id.', 'easy-wp-migration' ), 400 );
		}

		try {
			$state_manager = new EWPM_State();
			$state         = $state_manager->load( $job_id );
		} catch ( EWPM_State_Exception $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}

		if ( 'db_export' !== ( $state['type'] ?? '' ) ) {
			wp_die( esc_html__( 'Job is not a database export.', 'easy-wp-migration' ), 400 );
		}

		if ( empty( $state['done'] ) ) {
			wp_die( esc_html__( 'Job is not yet complete.', 'easy-wp-migration' ), 400 );
		}

		$output_path = $state['output_path'] ?? '';

		if ( empty( $output_path ) || ! file_exists( $output_path ) ) {
			wp_die( esc_html__( 'SQL file not found.', 'easy-wp-migration' ), 404 );
		}

		// Path safety check.
		$real_path = realpath( $output_path );
		$real_tmp  = realpath( ewpm_get_tmp_dir() );

		if ( ! $real_path || ! $real_tmp || ! str_starts_with( $real_path, $real_tmp ) ) {
			wp_die( esc_html__( 'File path is outside the allowed directory.', 'easy-wp-migration' ), 403 );
		}

		$filename = sprintf( 'db-export-%s.sql', $job_id );

		// Stream the file.
		nocache_headers();
		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $real_path ) );

		readfile( $real_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
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
