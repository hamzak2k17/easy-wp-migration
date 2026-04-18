<?php
/**
 * URL puller — downloads remote files via resumable HTTP Range requests.
 *
 * Uses WordPress HTTP API (wp_remote_*) exclusively. Streams to disk,
 * never loads full file into PHP memory. Includes SSRF defense against
 * private network IPs.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_URL_Puller
 *
 * Pulls a remote URL to a local file via chunked HTTP Range requests.
 */
class EWPM_URL_Puller {

	/**
	 * Remote URL to pull from.
	 */
	private string $url;

	/**
	 * Local destination path (in tmp/).
	 */
	private string $destination;

	/**
	 * Constructor.
	 *
	 * @param string $url              Remote URL.
	 * @param string $destination_path Local file path (must be in tmp/).
	 */
	public function __construct( string $url, string $destination_path ) {
		$this->url         = $url;
		$this->destination = $destination_path;
	}

	/**
	 * Probe the remote URL — discover size, Range support, filename.
	 *
	 * Uses a small Range request (bytes=0-0) to probe capabilities.
	 *
	 * @return array{reachable: bool, size_bytes: int, supports_range: bool, filename_hint: string|null, error: string|null, error_code: string|null}
	 */
	public function probe(): array {
		$fail = fn( string $code, string $msg ) => [
			'reachable'      => false,
			'size_bytes'     => 0,
			'supports_range' => false,
			'filename_hint'  => null,
			'error'          => $msg,
			'error_code'     => $code,
		];

		// SSRF defense.
		$ssrf = self::check_ssrf( $this->url );

		if ( $ssrf ) {
			return $fail( 'ssrf_blocked', $ssrf );
		}

		// Probe with a tiny Range request.
		$response = wp_remote_get( $this->url, [
			'timeout'    => 15,
			'sslverify'  => ! ( defined( 'EWPM_PULL_ALLOW_INSECURE_SSL' ) && EWPM_PULL_ALLOW_INSECURE_SSL ),
			'user-agent' => 'EasyWPMigration/' . EWPM_VERSION,
			'headers'    => [
				'Range'           => 'bytes=0-0',
				'Accept-Encoding' => 'identity',
			],
			'decompress' => false,
		] );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();

			if ( str_contains( $msg, 'SSL' ) || str_contains( $msg, 'certificate' ) ) {
				return $fail( 'ssl_error', $msg );
			}

			return $fail( 'unreachable', $msg );
		}

		$code = wp_remote_retrieve_response_code( $response );

		return match ( true ) {
			401 === $code, 403 === $code => $fail( 'unauthorized', __( 'Migration link is expired, revoked, or invalid.', 'easy-wp-migration' ) ),
			404 === $code               => $fail( 'not_found', __( 'Backup file on source has been deleted.', 'easy-wp-migration' ) ),
			429 === $code               => $fail( 'rate_limited', __( 'Source site is rate limiting. Wait and retry.', 'easy-wp-migration' ) ),
			$code >= 500                => $fail( 'server_error', sprintf( __( 'Source server error (%d).', 'easy-wp-migration' ), $code ) ),
			206 !== $code && 200 !== $code => $fail( 'generic_error', sprintf( __( 'Unexpected response (%d).', 'easy-wp-migration' ), $code ) ),
			default => $this->parse_probe_response( $response, $code ),
		};
	}

	/**
	 * Pull a chunk from the remote URL and write to destination.
	 *
	 * @param int $cursor_byte_offset Starting byte offset.
	 * @param int $chunk_size         Bytes to request per chunk.
	 * @param int $time_budget_seconds Max seconds to spend.
	 * @return array{cursor: int, bytes_written: int, done: bool, error: string|null, error_code: string|null}
	 */
	public function pull_chunk( int $cursor_byte_offset, int $chunk_size, int $time_budget_seconds ): array {
		$deadline      = microtime( true ) + $time_budget_seconds;
		$total_written = 0;
		$cursor        = $cursor_byte_offset;

		while ( microtime( true ) < $deadline ) {
			$end = $cursor + $chunk_size - 1;

			// Stream directly to a temp chunk file, then append to destination.
			$chunk_tmp = $this->destination . '.chunk';

			$response = wp_remote_get( $this->url, [
				'timeout'    => min( 30, max( 5, (int) ( $deadline - microtime( true ) ) ) ),
				'sslverify'  => ! ( defined( 'EWPM_PULL_ALLOW_INSECURE_SSL' ) && EWPM_PULL_ALLOW_INSECURE_SSL ),
				'user-agent' => 'EasyWPMigration/' . EWPM_VERSION,
				'headers'    => [
					'Range'           => "bytes={$cursor}-{$end}",
					'Accept-Encoding' => 'identity',
				],
				'decompress' => false,
				'stream'     => true,
				'filename'   => $chunk_tmp,
			] );

			if ( is_wp_error( $response ) ) {
				@unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return [
					'cursor'        => $cursor,
					'bytes_written' => $total_written,
					'done'          => false,
					'error'         => $response->get_error_message(),
					'error_code'    => 'unreachable',
				];
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $code ) {
				@unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return [
					'cursor'        => $cursor,
					'bytes_written' => $total_written,
					'done'          => false,
					'error'         => __( 'Rate limited by source.', 'easy-wp-migration' ),
					'error_code'    => 'rate_limited',
				];
			}

			if ( $code >= 400 ) {
				@unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$error_code = match ( true ) {
					401 === $code, 403 === $code => 'unauthorized',
					404 === $code => 'not_found',
					$code >= 500  => 'server_error',
					default       => 'generic_error',
				};

				return [
					'cursor'        => $cursor,
					'bytes_written' => $total_written,
					'done'          => false,
					'error'         => sprintf( __( 'Source returned HTTP %d.', 'easy-wp-migration' ), $code ),
					'error_code'    => $error_code,
				];
			}

			// Read chunk from temp file, append to destination.
			$chunk_bytes = file_exists( $chunk_tmp ) ? (int) filesize( $chunk_tmp ) : 0;

			if ( 0 === $chunk_bytes ) {
				@unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return [
					'cursor'        => $cursor,
					'bytes_written' => $total_written,
					'done'          => true,
					'error'         => null,
					'error_code'    => null,
				];
			}

			// Append chunk to destination.
			$chunk_data = file_get_contents( $chunk_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			@unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$fh = fopen( $this->destination, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( ! $fh ) {
				return [
					'cursor'        => $cursor,
					'bytes_written' => $total_written,
					'done'          => false,
					'error'         => __( 'Failed to open destination file.', 'easy-wp-migration' ),
					'error_code'    => 'generic_error',
				];
			}

			fwrite( $fh, $chunk_data );
			fflush( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$cursor        += $chunk_bytes;
			$total_written += $chunk_bytes;
			unset( $chunk_data );

			// If we got fewer bytes than requested, we've reached EOF.
			if ( $chunk_bytes < $chunk_size ) {
				return [
					'cursor'        => $cursor,
					'bytes_written' => $total_written,
					'done'          => true,
					'error'         => null,
					'error_code'    => null,
				];
			}
		}

		return [
			'cursor'        => $cursor,
			'bytes_written' => $total_written,
			'done'          => false,
			'error'         => null,
			'error_code'    => null,
		];
	}

	/**
	 * Verify the downloaded file.
	 *
	 * @param string $expected_sha256 Optional SHA-256 for integrity check.
	 * @return array{valid: bool, size: int, error: string|null}
	 */
	public function verify( string $expected_sha256 = '' ): array {
		if ( ! file_exists( $this->destination ) ) {
			return [ 'valid' => false, 'size' => 0, 'error' => 'File not found.' ];
		}

		$size = (int) filesize( $this->destination );

		if ( '' !== $expected_sha256 ) {
			$actual = hash_file( 'sha256', $this->destination );

			if ( $actual !== $expected_sha256 ) {
				return [ 'valid' => false, 'size' => $size, 'error' => 'SHA-256 mismatch.' ];
			}
		}

		// Verify it's a valid zip with metadata.json.
		try {
			$archiver = EWPM_Archiver_Factory::create();
			$archiver->open_for_read( $this->destination );
			$archiver->get_metadata();
			$archiver->close();
		} catch ( \Throwable $e ) {
			return [ 'valid' => false, 'size' => $size, 'error' => 'Not a valid .ezmig archive: ' . $e->getMessage() ];
		}

		return [ 'valid' => true, 'size' => $size, 'error' => null ];
	}

	/**
	 * Delete the partial/complete downloaded file.
	 */
	public function cleanup(): void {
		if ( ! file_exists( $this->destination ) ) {
			return;
		}

		$real      = realpath( $this->destination );
		$real_tmp  = realpath( ewpm_get_tmp_dir() );

		if ( $real && $real_tmp && str_starts_with( $real, $real_tmp ) ) {
			@unlink( $this->destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Parse probe response into structured result.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param int            $code     HTTP status code.
	 * @return array Probe result.
	 */
	private function parse_probe_response( $response, int $code ): array {
		$headers        = wp_remote_retrieve_headers( $response );
		$supports_range = 206 === $code || ! empty( $headers['accept-ranges'] );
		$size           = 0;

		if ( 206 === $code && ! empty( $headers['content-range'] ) ) {
			// Parse Content-Range: bytes 0-0/TOTAL
			if ( preg_match( '/\/(\d+)$/', $headers['content-range'], $m ) ) {
				$size = (int) $m[1];
			}
		} elseif ( ! empty( $headers['content-length'] ) ) {
			$size = (int) $headers['content-length'];
		}

		$filename_hint = null;

		if ( ! empty( $headers['content-disposition'] ) ) {
			if ( preg_match( '/filename="?([^";\s]+)/', $headers['content-disposition'], $m ) ) {
				$filename_hint = sanitize_file_name( $m[1] );
			}
		}

		return [
			'reachable'      => true,
			'size_bytes'     => $size,
			'supports_range' => $supports_range,
			'filename_hint'  => $filename_hint,
			'error'          => null,
			'error_code'     => null,
		];
	}

	/**
	 * Check for SSRF — reject private/link-local IPs.
	 *
	 * @param string $url URL to check.
	 * @return string|null Error message if blocked, null if OK.
	 */
	public static function check_ssrf( string $url ): ?string {
		if ( defined( 'EWPM_ALLOW_PRIVATE_NETWORK_PULL' ) && EWPM_ALLOW_PRIVATE_NETWORK_PULL ) {
			return null;
		}

		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		if ( empty( $host ) ) {
			return __( 'Invalid URL: no host.', 'easy-wp-migration' );
		}

		$scheme = strtolower( $parsed['scheme'] ?? '' );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return sprintf( __( 'URL scheme "%s" is not allowed. Only http and https.', 'easy-wp-migration' ), $scheme );
		}

		// Resolve hostname to IP.
		$ip = gethostbyname( $host );

		if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// gethostbyname returns the hostname on failure (not an IP).
			return null; // Let wp_remote handle DNS failures.
		}

		// If host was already a numeric IP, use it directly.
		if ( $ip === $host ) {
			$ip = $host;
		}

		// Check against private/reserved ranges.
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return sprintf(
				/* translators: %s: IP address */
				__( 'Blocked: URL resolves to private/reserved IP %s. Migration links must point to public servers.', 'easy-wp-migration' ),
				$ip
			);
		}

		return null;
	}
}
