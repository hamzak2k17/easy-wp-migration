<?php
/**
 * URL pull job.
 *
 * Downloads a remote .ezmig archive via chunked HTTP Range requests.
 * Resumable across ticks. After download, verifies the file is a valid
 * archive. The Import tab JS chains this into the import flow.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job_URL_Pull
 *
 * Chunked, resumable URL pull driven by the tick-based job framework.
 */
class EWPM_Job_URL_Pull extends EWPM_Job {

	/**
	 * Transient error codes that can be retried.
	 */
	private const TRANSIENT_ERRORS = [ 'unreachable', 'rate_limited', 'server_error' ];

	/**
	 * Maximum retries for transient errors.
	 */
	private const MAX_RETRIES = 5;

	/**
	 * Return the job type identifier.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'url_pull';
	}

	/**
	 * Return initial state.
	 *
	 * @param array<string,mixed> $init_params Accepts 'url'.
	 * @return array<string,mixed>
	 */
	protected function get_default_state( array $init_params ): array {
		return [
			'phase'            => 'probe',
			'phase_label'      => __( 'Probing source', 'easy-wp-migration' ),
			'progress_percent' => 0,
			'progress_label'   => __( 'Checking migration link...', 'easy-wp-migration' ),
			'params'           => [
				'url' => esc_url_raw( trim( $init_params['url'] ?? '' ) ),
			],
			'destination_path' => '',
			'expected_size'    => 0,
			'downloaded_bytes' => 0,
			'supports_range'   => false,
			'filename_hint'    => null,
			'retries_used'     => 0,
			'last_error_code'  => null,
		];
	}

	/**
	 * Execute one tick of work.
	 *
	 * @param array<string,mixed> $state               Current state.
	 * @param int                 $time_budget_seconds  Max seconds.
	 * @return array<string,mixed> Updated state.
	 */
	protected function run_tick( array $state, int $time_budget_seconds ): array {
		if ( $this->state->has_cancel_flag( $state['job_id'] ) ) {
			$state['cancelled']      = true;
			$state['phase_label']    = __( 'Cancelled', 'easy-wp-migration' );
			$state['progress_label'] = __( 'Download cancelled.', 'easy-wp-migration' );
			return $state;
		}

		return match ( $state['phase'] ) {
			'probe'    => $this->phase_probe( $state ),
			'download' => $this->phase_download( $state, $time_budget_seconds ),
			'verify'   => $this->phase_verify( $state ),
			default    => $state,
		};
	}

	/**
	 * Return final result.
	 *
	 * @param array<string,mixed> $state Final state.
	 * @return array<string,mixed>
	 */
	protected function finalize( array $state ): array {
		return [
			'pulled_path' => $state['destination_path'],
			'filename'    => $state['filename_hint'] ?? basename( $state['destination_path'] ),
			'size_bytes'  => $state['downloaded_bytes'],
			'size_human'  => size_format( $state['downloaded_bytes'] ),
		];
	}

	/**
	 * Clean up partial file.
	 *
	 * @param array<string,mixed> $state Current state.
	 */
	protected function cleanup( array $state ): void {
		$path = $state['destination_path'] ?? '';

		if ( empty( $path ) || ! file_exists( $path ) ) {
			return;
		}

		$real     = realpath( $path );
		$real_tmp = realpath( ewpm_get_tmp_dir() );

		if ( $real && $real_tmp && str_starts_with( $real, $real_tmp ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Probe phase: discover size, Range support, filename.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_probe( array $state ): array {
		$url    = $state['params']['url'];
		$puller = new EWPM_URL_Puller( $url, '' );
		$result = $puller->probe();

		if ( ! $result['reachable'] ) {
			$state['error']      = $result['error'];
			$state['error_code'] = $result['error_code'];
			return $state;
		}

		$job_id = $state['job_id'];
		$dest   = ewpm_get_tmp_dir() . "pulled-{$job_id}." . EWPM_ARCHIVE_EXTENSION;

		// Create empty destination file.
		file_put_contents( $dest, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$state['destination_path'] = $dest;
		$state['expected_size']    = $result['size_bytes'];
		$state['supports_range']   = $result['supports_range'];
		$state['filename_hint']    = $result['filename_hint'];
		$state['phase']            = 'download';
		$state['phase_label']      = __( 'Downloading', 'easy-wp-migration' );
		$state['progress_percent'] = 2;
		$state['progress_label']   = sprintf(
			/* translators: %s: file size */
			__( 'Starting download (%s)...', 'easy-wp-migration' ),
			size_format( $result['size_bytes'] )
		);

		return $state;
	}

	/**
	 * Download phase: chunked pull with retries.
	 *
	 * @param array<string,mixed> $state               Current state.
	 * @param int                 $time_budget_seconds  Max seconds.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_download( array $state, int $time_budget_seconds ): array {
		$url        = $state['params']['url'];
		$dest       = $state['destination_path'];
		$cursor     = (int) $state['downloaded_bytes'];
		$chunk_size = defined( 'EWPM_PULL_CHUNK_SIZE' ) ? (int) EWPM_PULL_CHUNK_SIZE : 5 * 1024 * 1024;

		// Check if source doesn't support Range and file is large.
		if ( ! $state['supports_range'] && $state['expected_size'] > ( defined( 'EWPM_PULL_NO_RANGE_MAX_BYTES' ) ? EWPM_PULL_NO_RANGE_MAX_BYTES : 50 * 1024 * 1024 ) ) {
			$state['error']      = __( 'Source does not support resumable downloads and file is too large for a single request.', 'easy-wp-migration' );
			$state['error_code'] = 'invalid_content';
			return $state;
		}

		$puller = new EWPM_URL_Puller( $url, $dest );
		$result = $puller->pull_chunk( $cursor, $chunk_size, $time_budget_seconds );

		$state['downloaded_bytes'] = $result['cursor'];

		if ( $result['error'] ) {
			$code = $result['error_code'] ?? 'generic_error';
			$state['last_error_code'] = $code;

			// Retry transient errors.
			if ( in_array( $code, self::TRANSIENT_ERRORS, true ) && $state['retries_used'] < self::MAX_RETRIES ) {
				$state['retries_used']++;
				$state['progress_label'] = sprintf(
					/* translators: 1: error, 2: retry count */
					__( 'Retrying (%1$s, attempt %2$d/%3$d)...', 'easy-wp-migration' ),
					$result['error'],
					$state['retries_used'],
					self::MAX_RETRIES
				);
				return $state; // Next tick will retry from same cursor.
			}

			$state['error']      = $result['error'];
			$state['error_code'] = $code;
			return $state;
		}

		// Reset retry counter on success.
		$state['retries_used']    = 0;
		$state['last_error_code'] = null;

		// Update progress.
		$total = max( 1, $state['expected_size'] );
		$pct   = min( 97, (int) ( ( $state['downloaded_bytes'] / $total ) * 100 ) );
		$state['progress_percent'] = 2 + (int) ( $pct * 0.96 );
		$state['progress_label']   = sprintf(
			/* translators: 1: downloaded, 2: total, 3: percent */
			__( '%1$s / %2$s (%3$d%%)', 'easy-wp-migration' ),
			size_format( $state['downloaded_bytes'] ),
			size_format( $total ),
			$pct
		);

		if ( $result['done'] ) {
			$state['phase']       = 'verify';
			$state['phase_label'] = __( 'Verifying', 'easy-wp-migration' );
			$state['progress_percent'] = 98;
			$state['progress_label']   = __( 'Verifying downloaded archive...', 'easy-wp-migration' );
		}

		return $state;
	}

	/**
	 * Verify phase: check file is valid.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed> Updated state.
	 */
	private function phase_verify( array $state ): array {
		$puller = new EWPM_URL_Puller( $state['params']['url'], $state['destination_path'] );
		$result = $puller->verify();

		if ( ! $result['valid'] ) {
			$state['error']      = $result['error'];
			$state['error_code'] = 'invalid_content';
			return $state;
		}

		$state['done']             = true;
		$state['downloaded_bytes'] = $result['size'];
		$state['progress_percent'] = 100;
		$state['phase_label']      = __( 'Complete', 'easy-wp-migration' );
		$state['progress_label']   = sprintf(
			/* translators: %s: file size */
			__( 'Downloaded and verified (%s)', 'easy-wp-migration' ),
			size_format( $result['size'] )
		);

		return $state;
	}
}
