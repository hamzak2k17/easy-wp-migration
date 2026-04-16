<?php
/**
 * Zip-based archiver implementation.
 *
 * Wraps PHP's ZipArchive to produce .ezmig files. This is the v1 format;
 * the factory can swap in a streaming archiver for v2 without touching
 * exporters or importers.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Archiver_Zip
 *
 * Concrete EWPM_Archiver_Interface implementation backed by ZipArchive.
 */
class EWPM_Archiver_Zip implements EWPM_Archiver_Interface {

	/**
	 * The underlying ZipArchive instance.
	 */
	private ?\ZipArchive $zip = null;

	/**
	 * Whether the archive is currently open.
	 */
	private bool $is_open = false;

	/**
	 * Current mode: 'read' or 'write'.
	 */
	private string $mode = '';

	/**
	 * Absolute path to the archive file.
	 */
	private string $path = '';

	/**
	 * In-memory metadata, updated during write operations.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata = [];

	/**
	 * Running count of files added during write mode.
	 */
	private int $files_added = 0;

	/**
	 * Running total of bytes added during write mode.
	 */
	private int $bytes_added = 0;

	/**
	 * Open an archive for writing.
	 *
	 * Creates a new zip file and writes a placeholder metadata.json that
	 * will be updated with final stats on close().
	 *
	 * @param string $path Absolute path for the new archive file.
	 * @throws EWPM_Archiver_Exception If ZipArchive is unavailable or creation fails.
	 */
	public function open_for_write( string $path ): void {
		$this->assert_zip_available();
		$this->assert_not_open();

		$this->zip = new \ZipArchive();
		$result    = $this->zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		if ( true !== $result ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Failed to create archive at "%s" (ZipArchive error code: %d).', $path, $result )
			);
		}

		$this->is_open = true;
		$this->mode    = 'write';
		$this->path    = $path;

		// Build initial metadata and write a placeholder.
		$this->metadata = EWPM_Archiver_Metadata::build_for_export();

		$this->zip->addFromString( 'metadata.json', wp_json_encode( $this->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Open an existing archive for reading.
	 *
	 * Validates that metadata.json exists and is structurally valid.
	 *
	 * @param string $path Absolute path to an existing archive file.
	 * @throws EWPM_Archiver_Exception If the file is missing, unreadable, or invalid.
	 */
	public function open_for_read( string $path ): void {
		$this->assert_zip_available();
		$this->assert_not_open();

		if ( ! file_exists( $path ) ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Archive file not found: "%s".', $path )
			);
		}

		$this->zip = new \ZipArchive();
		$result    = $this->zip->open( $path, \ZipArchive::RDONLY );

		if ( true !== $result ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Failed to open archive "%s" (ZipArchive error code: %d).', $path, $result )
			);
		}

		// Validate metadata.json presence and structure.
		$raw = $this->zip->getFromName( 'metadata.json' );

		if ( false === $raw ) {
			$this->zip->close();
			throw new EWPM_Archiver_Exception( 'Invalid or corrupted .ezmig file: metadata.json not found inside archive.' );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! EWPM_Archiver_Metadata::validate( $decoded ) ) {
			$this->zip->close();
			throw new EWPM_Archiver_Exception( 'Invalid or corrupted .ezmig file: metadata.json is malformed or missing required fields.' );
		}

		$this->metadata = $decoded;
		$this->is_open  = true;
		$this->mode     = 'read';
		$this->path     = $path;
	}

	/**
	 * Add a file from disk into the archive.
	 *
	 * @param string $archive_path Path inside the archive.
	 * @param string $source_path  Absolute path to the source file on disk.
	 * @throws EWPM_Archiver_Exception If the archive is not open for writing or the file cannot be added.
	 */
	public function add_file( string $archive_path, string $source_path ): void {
		$this->assert_open_for( 'write' );

		if ( ! file_exists( $source_path ) ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Source file not found: "%s".', $source_path )
			);
		}

		if ( ! is_readable( $source_path ) ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Source file is not readable: "%s".', $source_path )
			);
		}

		if ( ! $this->zip->addFile( $source_path, $archive_path ) ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Failed to add file "%s" to archive as "%s".', $source_path, $archive_path )
			);
		}

		++$this->files_added;
		$this->bytes_added += filesize( $source_path );
	}

	/**
	 * Add content directly from a string into the archive.
	 *
	 * @param string $archive_path Path inside the archive.
	 * @param string $content      The string content to store.
	 * @throws EWPM_Archiver_Exception If the archive is not open for writing or the content cannot be added.
	 */
	public function add_string( string $archive_path, string $content ): void {
		$this->assert_open_for( 'write' );

		if ( ! $this->zip->addFromString( $archive_path, $content ) ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Failed to add string content to archive as "%s".', $archive_path )
			);
		}

		++$this->files_added;
		$this->bytes_added += strlen( $content );
	}

	/**
	 * List all entries in the archive.
	 *
	 * @return array<int, array{path: string, size: int, mtime: int}> Indexed array of entry info.
	 * @throws EWPM_Archiver_Exception If the archive is not open for reading.
	 */
	public function list_entries(): array {
		$this->assert_open_for( 'read' );

		$entries = [];

		for ( $i = 0; $i < $this->zip->numFiles; $i++ ) {
			$stat = $this->zip->statIndex( $i );

			if ( false === $stat ) {
				continue;
			}

			$entries[] = [
				'path'  => $stat['name'],
				'size'  => $stat['size'],
				'mtime' => $stat['mtime'],
			];
		}

		return $entries;
	}

	/**
	 * Extract a single file from the archive to a destination on disk.
	 *
	 * @param string $archive_path  Path inside the archive.
	 * @param string $destination   Absolute path where the file should be written.
	 * @throws EWPM_Archiver_Exception If the entry does not exist or extraction fails.
	 */
	public function extract_file( string $archive_path, string $destination ): void {
		$this->assert_open_for( 'read' );

		$content = $this->zip->getFromName( $archive_path );

		if ( false === $content ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Entry "%s" not found in archive.', $archive_path )
			);
		}

		$dir = dirname( $destination );

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new EWPM_Archiver_Exception(
					sprintf( 'Failed to create directory "%s" for extraction.', $dir )
				);
			}
		}

		$written = file_put_contents( $destination, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $written ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Failed to write extracted file to "%s".', $destination )
			);
		}
	}

	/**
	 * Read a file's contents from the archive into memory.
	 *
	 * @param string $archive_path Path inside the archive.
	 * @return string The file contents.
	 * @throws EWPM_Archiver_Exception If the entry does not exist or cannot be read.
	 */
	public function extract_string( string $archive_path ): string {
		$this->assert_open_for( 'read' );

		$content = $this->zip->getFromName( $archive_path );

		if ( false === $content ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Entry "%s" not found in archive.', $archive_path )
			);
		}

		return $content;
	}

	/**
	 * Close the archive cleanly.
	 *
	 * In write mode, updates metadata.json with final stats before closing.
	 *
	 * @throws EWPM_Archiver_Exception If the archive is not open or closing fails.
	 */
	public function close(): void {
		if ( ! $this->is_open || null === $this->zip ) {
			throw new EWPM_Archiver_Exception( 'Cannot close: no archive is currently open.' );
		}

		// In write mode, update metadata with final stats before closing.
		if ( 'write' === $this->mode ) {
			$this->metadata['stats']['total_files'] = $this->files_added;
			$this->metadata['stats']['total_bytes'] = $this->bytes_added;

			// Delete the placeholder and re-add with final data.
			$this->zip->deleteName( 'metadata.json' );
			$this->zip->addFromString(
				'metadata.json',
				wp_json_encode( $this->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			);
		}

		if ( ! $this->zip->close() ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Failed to close archive "%s".', $this->path )
			);
		}

		$this->zip         = null;
		$this->is_open     = false;
		$this->mode        = '';
		$this->files_added = 0;
		$this->bytes_added = 0;
	}

	/**
	 * Return the archive's metadata header.
	 *
	 * In write mode, returns the in-memory metadata (may not yet have final stats).
	 * In read mode, returns the metadata decoded from the archive.
	 *
	 * @return array<string, mixed> The decoded metadata structure.
	 * @throws EWPM_Archiver_Exception If no archive is open.
	 */
	public function get_metadata(): array {
		if ( ! $this->is_open ) {
			throw new EWPM_Archiver_Exception( 'Cannot read metadata: no archive is currently open.' );
		}

		return $this->metadata;
	}

	/**
	 * Assert that the ZipArchive extension is available.
	 *
	 * @throws EWPM_Archiver_Exception If the extension is not loaded.
	 */
	private function assert_zip_available(): void {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new EWPM_Archiver_Exception(
				'The PHP ZipArchive extension is required but not available. '
				. 'Please ask your hosting provider to enable the "zip" PHP extension.'
			);
		}
	}

	/**
	 * Assert that no archive is currently open.
	 *
	 * @throws EWPM_Archiver_Exception If an archive is already open.
	 */
	private function assert_not_open(): void {
		if ( $this->is_open ) {
			throw new EWPM_Archiver_Exception(
				'An archive is already open. Close it before opening another.'
			);
		}
	}

	/**
	 * Assert that the archive is open in the expected mode.
	 *
	 * @param string $expected_mode Either 'read' or 'write'.
	 * @throws EWPM_Archiver_Exception If the archive is not open or is in the wrong mode.
	 */
	private function assert_open_for( string $expected_mode ): void {
		if ( ! $this->is_open ) {
			throw new EWPM_Archiver_Exception( 'No archive is currently open.' );
		}

		if ( $this->mode !== $expected_mode ) {
			throw new EWPM_Archiver_Exception(
				sprintf( 'Archive is open for %s, not %s.', $this->mode, $expected_mode )
			);
		}
	}
}
