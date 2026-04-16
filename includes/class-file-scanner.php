<?php
/**
 * File scanner.
 *
 * Enumerates files under component root paths with exclusion support.
 * Returns a flat file list with metadata, chunked by time budget.
 * Uses RecursiveDirectoryIterator for traversal.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_File_Scanner
 *
 * Enumerates files for export with exclusion pattern matching.
 */
class EWPM_File_Scanner {

	/**
	 * Component roots to scan.
	 *
	 * @var array<int, array{path: string, archive_prefix: string}>
	 */
	private array $roots = [];

	/**
	 * Exclusion patterns (glob-style).
	 *
	 * @var string[]
	 */
	private array $exclusions = [];

	/**
	 * Warnings accumulated during scanning.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Visited inodes for symlink loop detection.
	 *
	 * @var array<int, bool>
	 */
	private array $visited_inodes = [];

	/**
	 * Maximum file size in bytes. Files larger than this are skipped.
	 */
	private int $max_file_size;

	/**
	 * Constructor.
	 *
	 * @param int $max_file_size Maximum file size to include (default from constant).
	 */
	public function __construct( int $max_file_size = 0 ) {
		$this->max_file_size = $max_file_size > 0
			? $max_file_size
			: ( defined( 'EWPM_MAX_FILE_SIZE' ) ? EWPM_MAX_FILE_SIZE : 2 * 1024 * 1024 * 1024 );
	}

	/**
	 * Set the component roots to scan.
	 *
	 * @param array<int, array{path: string, archive_prefix: string}> $roots Root definitions.
	 */
	public function set_component_roots( array $roots ): void {
		$this->roots = $roots;
	}

	/**
	 * Set exclusion patterns.
	 *
	 * Merges with the hardcoded forbidden patterns from EWPM_Export_Presets.
	 *
	 * @param string[] $patterns Glob-style patterns.
	 */
	public function set_exclusions( array $patterns ): void {
		$this->exclusions = array_unique(
			array_merge( EWPM_Export_Presets::get_forbidden_patterns(), $patterns )
		);
	}

	/**
	 * Get warnings accumulated during scanning.
	 *
	 * @return string[]
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}

	/**
	 * Scan files in chunks, respecting time budget.
	 *
	 * Returns file entries and an updated cursor for resuming on the next tick.
	 * The cursor tracks which root index and how many files to skip within that root.
	 *
	 * @param int   $time_budget_seconds Maximum seconds to spend scanning.
	 * @param array $cursor              Current cursor: root_index, skip_count.
	 * @return array{files: array, cursor: array, done: bool, warnings: string[]}
	 */
	public function scan( int $time_budget_seconds, array $cursor ): array {
		$deadline   = microtime( true ) + $time_budget_seconds;
		$root_index = (int) ( $cursor['root_index'] ?? 0 );
		$skip_count = (int) ( $cursor['skip_count'] ?? 0 );
		$files      = [];

		$this->warnings       = [];
		$this->visited_inodes = [];

		while ( $root_index < count( $this->roots ) ) {
			$root           = $this->roots[ $root_index ];
			$root_path      = rtrim( $root['path'], '/\\' );
			$archive_prefix = $root['archive_prefix'];

			if ( ! is_dir( $root_path ) ) {
				$this->warnings[] = sprintf( 'Directory not found, skipping: %s', $root_path );
				++$root_index;
				$skip_count = 0;
				continue;
			}

			if ( ! is_readable( $root_path ) ) {
				$this->warnings[] = sprintf( 'Directory not readable, skipping: %s', $root_path );
				++$root_index;
				$skip_count = 0;
				continue;
			}

			try {
				$dir_iterator = new \RecursiveDirectoryIterator(
					$root_path,
					\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
				);

				$iterator = new \RecursiveIteratorIterator(
					$dir_iterator,
					\RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( \Throwable $e ) {
				$this->warnings[] = sprintf( 'Cannot iterate directory %s: %s', $root_path, $e->getMessage() );
				++$root_index;
				$skip_count = 0;
				continue;
			}

			$scanned = 0;

			foreach ( $iterator as $file_info ) {
				// Skip entries before the cursor position.
				if ( $scanned < $skip_count ) {
					++$scanned;
					continue;
				}

				// Handle unreadable entries gracefully.
				try {
					$source_path = $file_info->getPathname();
					$is_file     = $file_info->isFile();
				} catch ( \Throwable $e ) {
					++$scanned;
					continue;
				}

				if ( ! $is_file ) {
					++$scanned;
					continue;
				}

				// Symlink loop detection.
				if ( $file_info->isLink() ) {
					try {
						$inode = fileinode( $source_path );

						if ( false !== $inode && isset( $this->visited_inodes[ $inode ] ) ) {
							$this->warnings[] = sprintf( 'Symlink loop detected, skipping: %s', $source_path );
							++$scanned;
							continue;
						}

						if ( false !== $inode ) {
							$this->visited_inodes[ $inode ] = true;
						}
					} catch ( \Throwable $e ) {
						// Ignore inode detection failures.
					}
				}

				// Build relative path within the root.
				$relative = ltrim( str_replace( '\\', '/', substr( $source_path, strlen( $root_path ) ) ), '/' );

				// Apply exclusion patterns.
				$archive_path = $archive_prefix . '/' . $relative;

				if ( self::matches_exclusion( $archive_path, $this->exclusions ) ) {
					++$scanned;
					continue;
				}

				// Skip files larger than the size cap.
				try {
					$size = $file_info->getSize();
				} catch ( \Throwable $e ) {
					$this->warnings[] = sprintf( 'Cannot read file size, skipping: %s', $source_path );
					++$scanned;
					continue;
				}

				if ( $size > $this->max_file_size ) {
					$this->warnings[] = sprintf(
						'File exceeds size limit (%s), skipping: %s',
						size_format( $this->max_file_size ),
						$source_path
					);
					++$scanned;
					continue;
				}

				// Skip unreadable files.
				if ( ! is_readable( $source_path ) ) {
					$this->warnings[] = sprintf( 'File not readable, skipping: %s', $source_path );
					++$scanned;
					continue;
				}

				$files[] = [
					'source_path'  => $source_path,
					'archive_path' => $archive_path,
					'size'         => (int) $size,
					'mtime'        => (int) $file_info->getMTime(),
				];

				++$scanned;

				// Check time budget periodically (every 500 files).
				if ( 0 === $scanned % 500 && microtime( true ) >= $deadline ) {
					return [
						'files'    => $files,
						'cursor'   => [ 'root_index' => $root_index, 'skip_count' => $scanned ],
						'done'     => false,
						'warnings' => $this->warnings,
					];
				}
			}

			// Root complete — advance to next.
			++$root_index;
			$skip_count = 0;

			// Check time budget between roots.
			if ( microtime( true ) >= $deadline ) {
				return [
					'files'    => $files,
					'cursor'   => [ 'root_index' => $root_index, 'skip_count' => 0 ],
					'done'     => $root_index >= count( $this->roots ),
					'warnings' => $this->warnings,
				];
			}
		}

		return [
			'files'    => $files,
			'cursor'   => [ 'root_index' => $root_index, 'skip_count' => 0 ],
			'done'     => true,
			'warnings' => $this->warnings,
		];
	}

	/**
	 * Check whether a path matches any exclusion pattern.
	 *
	 * Supports glob-style patterns with *, **, and ?.
	 * Matching is case-insensitive on Windows.
	 *
	 * @param string   $path     The archive-relative path to test.
	 * @param string[] $patterns Glob patterns.
	 * @return bool True if the path matches any pattern.
	 */
	public static function matches_exclusion( string $path, array $patterns ): bool {
		$path = str_replace( '\\', '/', $path );

		foreach ( $patterns as $pattern ) {
			$pattern = str_replace( '\\', '/', $pattern );

			// Convert glob pattern to regex.
			$regex = self::glob_to_regex( $pattern );

			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a glob pattern to a PCRE regex.
	 *
	 * Supports *, **, and ? wildcards.
	 *
	 * @param string $pattern Glob pattern.
	 * @return string PCRE regex.
	 */
	private static function glob_to_regex( string $pattern ): string {
		$regex  = '';
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( '*' === $char ) {
				if ( $i + 1 < $length && '*' === $pattern[ $i + 1 ] ) {
					// ** matches any number of path segments.
					$regex .= '.*';
					++$i; // Skip the second *.

					// Skip trailing / after **.
					if ( $i + 1 < $length && '/' === $pattern[ $i + 1 ] ) {
						++$i;
					}
				} else {
					// * matches anything except /.
					$regex .= '[^/]*';
				}
			} elseif ( '?' === $char ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $char, '#' );
			}
		}

		$flags = ( PHP_OS_FAMILY === 'Windows' ) ? 'i' : '';

		return '#^' . $regex . '$#' . $flags;
	}
}
