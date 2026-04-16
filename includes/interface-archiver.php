<?php
/**
 * Archiver interface.
 *
 * Defines the contract that every archive format implementation must satisfy.
 * Exporters and importers depend on this interface, never on a concrete class,
 * so the underlying format (zip today, custom streaming in v2) can be swapped
 * without rewriting consumers.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface EWPM_Archiver_Interface
 */
interface EWPM_Archiver_Interface {

	/**
	 * Open an archive for writing.
	 *
	 * Creates a new archive file at the given path. If the file already exists
	 * it will be overwritten.
	 *
	 * @param string $path Absolute path for the new archive file.
	 * @throws EWPM_Archiver_Exception On failure to create or open the archive.
	 */
	public function open_for_write( string $path ): void;

	/**
	 * Open an existing archive for reading.
	 *
	 * @param string $path Absolute path to an existing archive file.
	 * @throws EWPM_Archiver_Exception If the file is missing, unreadable, or invalid.
	 */
	public function open_for_read( string $path ): void;

	/**
	 * Add a file from disk into the archive.
	 *
	 * @param string $archive_path Path inside the archive (e.g. "plugins/my-plugin/file.php").
	 * @param string $source_path  Absolute path to the source file on disk.
	 * @throws EWPM_Archiver_Exception If the source file is missing or cannot be added.
	 */
	public function add_file( string $archive_path, string $source_path ): void;

	/**
	 * Add content directly from a string into the archive.
	 *
	 * Used for generated content like database.sql and metadata.json that
	 * doesn't originate from an on-disk file.
	 *
	 * @param string $archive_path Path inside the archive.
	 * @param string $content      The string content to store.
	 * @throws EWPM_Archiver_Exception If the content cannot be added.
	 */
	public function add_string( string $archive_path, string $content ): void;

	/**
	 * List all entries in the archive.
	 *
	 * @return array<int, array{path: string, size: int, mtime: int}> Indexed array of entry info.
	 * @throws EWPM_Archiver_Exception If the archive is not open for reading.
	 */
	public function list_entries(): array;

	/**
	 * Extract a single file from the archive to a destination on disk.
	 *
	 * @param string $archive_path  Path inside the archive.
	 * @param string $destination   Absolute path where the file should be written.
	 * @throws EWPM_Archiver_Exception If the entry does not exist or extraction fails.
	 */
	public function extract_file( string $archive_path, string $destination ): void;

	/**
	 * Read a file's contents from the archive into memory.
	 *
	 * Intended for small files like metadata.json. Do not use for large files.
	 *
	 * @param string $archive_path Path inside the archive.
	 * @return string The file contents.
	 * @throws EWPM_Archiver_Exception If the entry does not exist or cannot be read.
	 */
	public function extract_string( string $archive_path ): string;

	/**
	 * Close the archive cleanly.
	 *
	 * For write mode, this finalizes the archive (updating metadata, flushing
	 * buffers). For read mode, it releases the file handle.
	 *
	 * @throws EWPM_Archiver_Exception If closing fails.
	 */
	public function close(): void;

	/**
	 * Return the archive's metadata header.
	 *
	 * Decodes and returns the metadata.json stored inside the archive.
	 *
	 * @return array<string, mixed> The decoded metadata structure.
	 * @throws EWPM_Archiver_Exception If metadata cannot be read or is invalid.
	 */
	public function get_metadata(): array;
}
