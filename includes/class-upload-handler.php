<?php
/**
 * Chunked file upload handler.
 *
 * Handles chunked uploads of .ezmig archives, supporting files larger
 * than PHP's upload_max_filesize. JS slices the file, sends chunks,
 * this class reassembles them in tmp/.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Upload_Handler
 *
 * Manages chunked file uploads to tmp/.
 */
class EWPM_Upload_Handler {

	/**
	 * Maximum chunk size in bytes (5 MB).
	 */
	private const MAX_CHUNK_SIZE = 5 * 1024 * 1024;

	/**
	 * Recommended chunk size in bytes (1 MB).
	 */
	private const RECOMMENDED_CHUNK_SIZE = 1024 * 1024;

	/**
	 * Maximum total upload size in bytes (50 GB).
	 */
	private const MAX_TOTAL_SIZE = 50 * 1024 * 1024 * 1024;

	/**
	 * Start a new chunked upload session.
	 *
	 * Validates the filename extension, generates an upload_id, creates
	 * a placeholder .part file in tmp/.
	 *
	 * @param string $filename    Original filename.
	 * @param int    $total_size  Total file size in bytes.
	 * @param string $client_hash Optional SHA-256 hash for verification.
	 * @return array{upload_id: string, chunk_size_recommended: int, max_chunk_size: int}
	 * @throws EWPM_Upload_Handler_Exception On validation failure.
	 */
	public function start_upload( string $filename, int $total_size, string $client_hash = '' ): array {
		// Validate extension.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( EWPM_ARCHIVE_EXTENSION !== $ext ) {
			throw new EWPM_Upload_Handler_Exception(
				sprintf(
					/* translators: %s: expected extension */
					__( 'Invalid file type. Only .%s files are accepted.', 'easy-wp-migration' ),
					EWPM_ARCHIVE_EXTENSION
				)
			);
		}

		// Validate size.
		if ( $total_size <= 0 || $total_size > self::MAX_TOTAL_SIZE ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'File size is invalid or exceeds the 50 GB limit.', 'easy-wp-migration' )
			);
		}

		// Clean up stale .part files (older than 1 hour).
		$this->cleanup_stale_parts();

		$upload_id = bin2hex( random_bytes( 8 ) );
		$part_path = $this->get_part_path( $upload_id );

		// Create empty placeholder.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $part_path, '' ) ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'Failed to create upload placeholder file.', 'easy-wp-migration' )
			);
		}

		return [
			'upload_id'              => $upload_id,
			'chunk_size_recommended' => self::RECOMMENDED_CHUNK_SIZE,
			'max_chunk_size'         => self::MAX_CHUNK_SIZE,
		];
	}

	/**
	 * Receive and append a chunk to the upload.
	 *
	 * @param string $upload_id   The upload session ID.
	 * @param int    $chunk_index Zero-based chunk index.
	 * @param int    $total_chunks Total number of chunks expected.
	 * @param string $chunk_data  Raw binary chunk data.
	 * @return array{received_bytes: int, percent_complete: int}
	 * @throws EWPM_Upload_Handler_Exception On I/O errors.
	 */
	public function receive_chunk( string $upload_id, int $chunk_index, int $total_chunks, string $chunk_data ): array {
		$part_path = $this->get_part_path( $upload_id );

		if ( ! file_exists( $part_path ) ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'Upload session not found. It may have expired.', 'easy-wp-migration' )
			);
		}

		$this->assert_path_in_tmp( $part_path );

		if ( strlen( $chunk_data ) > self::MAX_CHUNK_SIZE ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'Chunk exceeds maximum size.', 'easy-wp-migration' )
			);
		}

		$fh = fopen( $part_path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fh ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'Failed to open upload file for writing.', 'easy-wp-migration' )
			);
		}

		fwrite( $fh, $chunk_data );
		fflush( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$received = (int) filesize( $part_path );
		$percent  = $total_chunks > 0 ? (int) ( ( ( $chunk_index + 1 ) / $total_chunks ) * 100 ) : 0;

		return [
			'received_bytes'   => $received,
			'percent_complete' => min( 99, $percent ),
		];
	}

	/**
	 * Finalize the upload: verify and rename to .ezmig.
	 *
	 * @param string $upload_id     The upload session ID.
	 * @param string $expected_sha256 Optional SHA-256 for verification.
	 * @return array{path: string, size: int, sha256: string}
	 * @throws EWPM_Upload_Handler_Exception On verification failure.
	 */
	public function finalize_upload( string $upload_id, string $expected_sha256 = '' ): array {
		$part_path  = $this->get_part_path( $upload_id );
		$final_path = ewpm_get_tmp_dir() . "upload-{$upload_id}." . EWPM_ARCHIVE_EXTENSION;

		if ( ! file_exists( $part_path ) ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'Upload file not found.', 'easy-wp-migration' )
			);
		}

		$this->assert_path_in_tmp( $part_path );

		$size   = (int) filesize( $part_path );
		$sha256 = hash_file( 'sha256', $part_path );

		// Verify hash if provided.
		if ( '' !== $expected_sha256 && $sha256 !== $expected_sha256 ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'File hash mismatch. The upload may be corrupted. Please try again.', 'easy-wp-migration' )
			);
		}

		if ( ! rename( $part_path, $final_path ) ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'Failed to finalize upload file.', 'easy-wp-migration' )
			);
		}

		return [
			'path'   => $final_path,
			'size'   => $size,
			'sha256' => $sha256,
		];
	}

	/**
	 * Abort an upload and delete the partial file.
	 *
	 * @param string $upload_id The upload session ID.
	 */
	public function abort_upload( string $upload_id ): void {
		$part_path = $this->get_part_path( $upload_id );

		if ( file_exists( $part_path ) ) {
			$this->assert_path_in_tmp( $part_path );
			@unlink( $part_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Clean up stale .part files older than 1 hour.
	 */
	private function cleanup_stale_parts(): void {
		$pattern = ewpm_get_tmp_dir() . 'upload-*.part';
		$files   = glob( $pattern );

		if ( ! $files ) {
			return;
		}

		$cutoff = time() - 3600;

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Get the .part file path for an upload.
	 *
	 * @param string $upload_id The upload session ID.
	 * @return string Absolute path.
	 */
	private function get_part_path( string $upload_id ): string {
		return ewpm_get_tmp_dir() . "upload-{$upload_id}.part";
	}

	/**
	 * Assert a path resolves inside tmp/.
	 *
	 * @param string $path File path to check.
	 * @throws EWPM_Upload_Handler_Exception If path is outside tmp/.
	 */
	private function assert_path_in_tmp( string $path ): void {
		$real_path = realpath( $path );
		$real_tmp  = realpath( ewpm_get_tmp_dir() );

		if ( ! $real_path || ! $real_tmp || ! str_starts_with( $real_path, $real_tmp ) ) {
			throw new EWPM_Upload_Handler_Exception(
				__( 'File path is outside the allowed directory.', 'easy-wp-migration' )
			);
		}
	}
}
