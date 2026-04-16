<?php
/**
 * Database importer engine.
 *
 * Replays a database.sql file chunk-by-chunk via $wpdb->query().
 * Supports table prefix rewriting and serialization-aware URL replacement
 * via EWPM_Serializer_Fix.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_DB_Importer
 *
 * Replays SQL dump files with prefix rewriting and URL replacement.
 */
class EWPM_DB_Importer {

	/**
	 * WordPress database access object.
	 */
	private \wpdb $wpdb;

	/**
	 * Path to the SQL file being replayed.
	 */
	private string $sql_path;

	/**
	 * Source table prefix from the archive.
	 */
	private string $source_prefix;

	/**
	 * Destination table prefix (current site).
	 */
	private string $dest_prefix;

	/**
	 * Whether prefix rewriting is enabled.
	 */
	private bool $prefix_rewrite = false;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb        WordPress database instance.
	 * @param string $sql_path    Path to the SQL dump file.
	 * @param string $source_prefix Source table prefix.
	 * @param string $dest_prefix   Destination table prefix.
	 */
	public function __construct( \wpdb $wpdb, string $sql_path, string $source_prefix, string $dest_prefix ) {
		$this->wpdb          = $wpdb;
		$this->sql_path      = $sql_path;
		$this->source_prefix = $source_prefix;
		$this->dest_prefix   = $dest_prefix;
		$this->prefix_rewrite = ( $source_prefix !== $dest_prefix );
	}

	/**
	 * Enable or disable prefix rewriting.
	 *
	 * @param bool $enabled Whether to rewrite table prefixes.
	 */
	public function set_prefix_rewrite( bool $enabled ): void {
		$this->prefix_rewrite = $enabled;
	}

	/**
	 * Replay the SQL file in chunks, respecting time budget.
	 *
	 * @param int   $cursor_byte_offset Starting byte offset in the SQL file.
	 * @param int   $time_budget_seconds Max seconds to run.
	 * @param array $options             Replay options.
	 * @return array{cursor: int, statements: int, errors: array, done: bool, warnings: array}
	 */
	public function replay( int $cursor_byte_offset, int $time_budget_seconds, array $options = [] ): array {
		$deadline           = microtime( true ) + $time_budget_seconds;
		$disable_fk         = $options['disable_foreign_keys'] ?? true;
		$charset            = $options['set_names_charset'] ?? 'utf8mb4';
		$stop_on_error      = $options['stop_on_error'] ?? false;
		$replacements       = $options['replacements'] ?? [];
		$max_statement_bytes = $options['max_statement_bytes'] ?? 16 * 1024 * 1024; // 16MB.

		$statements = 0;
		$errors     = [];
		$warnings   = [];

		$fh = fopen( $this->sql_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fh ) {
			throw new EWPM_DB_Importer_Exception(
				sprintf( 'Failed to open SQL file: %s', $this->sql_path )
			);
		}

		// Initial setup on first chunk.
		if ( 0 === $cursor_byte_offset ) {
			$this->setup_connection( $charset, $disable_fk );
		}

		fseek( $fh, $cursor_byte_offset );

		try {
			while ( ! feof( $fh ) ) {
				if ( microtime( true ) >= $deadline ) {
					$cursor = ftell( $fh );
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return [
						'cursor'     => $cursor,
						'statements' => $statements,
						'errors'     => $errors,
						'done'       => false,
						'warnings'   => $warnings,
					];
				}

				$statement = $this->parse_next_statement( $fh, $max_statement_bytes );

				if ( null === $statement ) {
					break; // EOF.
				}

				if ( '' === trim( $statement ) ) {
					continue;
				}

				// Apply prefix rewriting.
				if ( $this->prefix_rewrite ) {
					$statement = $this->rewrite_prefix( $statement );
				}

				// Apply serialization-aware replacements on INSERT statements.
				if ( ! empty( $replacements ) && $this->is_insert_statement( $statement ) ) {
					$statement = EWPM_Serializer_Fix::replace( $statement, $replacements );
				}

				// Safety: skip DROP for tables outside our prefix scope.
				if ( $this->is_drop_statement( $statement ) && ! $this->is_in_prefix_scope( $statement ) ) {
					$warnings[] = sprintf( 'Skipped DROP for table outside prefix scope: %s', substr( $statement, 0, 100 ) );
					continue;
				}

				$result = $this->execute_statement( $statement );

				if ( ! $result['success'] ) {
					$errors[] = [
						'statement' => substr( $statement, 0, 200 ),
						'error'     => $result['error'],
					];

					if ( $stop_on_error ) {
						$cursor = ftell( $fh );
						fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return [
							'cursor'     => $cursor,
							'statements' => $statements,
							'errors'     => $errors,
							'done'       => false,
							'warnings'   => $warnings,
						];
					}
				}

				++$statements;
			}
		} finally {
			if ( is_resource( $fh ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		// Restore FK checks if we disabled them.
		if ( $disable_fk ) {
			$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return [
			'cursor'     => -1, // EOF marker.
			'statements' => $statements,
			'errors'     => $errors,
			'done'       => true,
			'warnings'   => $warnings,
		];
	}

	/**
	 * Parse the next SQL statement from the file handle.
	 *
	 * Handles multi-line statements, string literals with semicolons,
	 * and comment lines (-- and /*!).
	 *
	 * @param resource $fh                  Open file handle.
	 * @param int      $max_statement_bytes Max bytes per statement.
	 * @return string|null The statement, or null at EOF.
	 */
	public function parse_next_statement( $fh, int $max_statement_bytes = 16777216 ): ?string {
		$statement    = '';
		$in_string    = false;
		$string_char  = '';
		$escaped      = false;

		while ( ! feof( $fh ) ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				break;
			}

			$trimmed = ltrim( $line );

			// Skip comment-only lines and empty lines (when not mid-statement).
			if ( '' === $statement && ( '' === $trimmed || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '#' ) ) ) {
				continue;
			}

			// Handle conditional comments /*!...*/ — these are valid SQL.
			$statement .= $line;

			// Check for statement terminator (;) outside string literals.
			$len = strlen( $statement );

			for ( $i = 0; $i < $len; $i++ ) {
				$char = $statement[ $i ];

				if ( $escaped ) {
					$escaped = false;
					continue;
				}

				if ( '\\' === $char && $in_string ) {
					$escaped = true;
					continue;
				}

				if ( $in_string ) {
					if ( $char === $string_char ) {
						// Check for escaped quote ('' in SQL).
						if ( $i + 1 < $len && $statement[ $i + 1 ] === $string_char ) {
							++$i; // Skip doubled quote.
							continue;
						}
						$in_string = false;
					}
					continue;
				}

				if ( "'" === $char || '"' === $char ) {
					$in_string  = true;
					$string_char = $char;
					continue;
				}

				if ( ';' === $char ) {
					// Found end of statement.
					return trim( substr( $statement, 0, $i ) );
				}
			}

			// Safety: abort if statement is too large.
			if ( strlen( $statement ) > $max_statement_bytes ) {
				return trim( $statement );
			}
		}

		// EOF — return remaining if any.
		$statement = trim( $statement );
		return '' !== $statement ? $statement : null;
	}

	/**
	 * Execute a single SQL statement.
	 *
	 * Handles connection retry on "MySQL server has gone away" errors.
	 *
	 * @param string $statement The SQL statement to execute.
	 * @return array{success: bool, affected_rows: int, error: string|null}
	 */
	public function execute_statement( string $statement ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query( $statement );

		if ( false === $result ) {
			$error = $this->wpdb->last_error;

			// Retry on connection lost.
			if ( str_contains( $error, 'gone away' ) || str_contains( $error, 'Lost connection' ) ) {
				$this->wpdb->check_connection( false );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$result = $this->wpdb->query( $statement );

				if ( false !== $result ) {
					return [
						'success'       => true,
						'affected_rows' => (int) $result,
						'error'         => null,
					];
				}
			}

			return [
				'success'       => false,
				'affected_rows' => 0,
				'error'         => $this->wpdb->last_error,
			];
		}

		return [
			'success'       => true,
			'affected_rows' => (int) $result,
			'error'         => null,
		];
	}

	/**
	 * Rewrite table prefix in a SQL statement.
	 *
	 * Only rewrites tables that start with the source prefix.
	 *
	 * @param string $statement The SQL statement.
	 * @return string Statement with prefix rewritten.
	 */
	private function rewrite_prefix( string $statement ): string {
		$src = preg_quote( $this->source_prefix, '/' );
		$dst = $this->dest_prefix;

		// Rewrite backtick-quoted table names.
		return preg_replace(
			'/`' . $src . '([a-zA-Z0-9_]+)`/',
			'`' . $dst . '$1`',
			$statement
		);
	}

	/**
	 * Check if a statement is an INSERT.
	 *
	 * @param string $statement The SQL statement.
	 * @return bool True if it's an INSERT.
	 */
	private function is_insert_statement( string $statement ): bool {
		return (bool) preg_match( '/^\s*INSERT\s+INTO\s/i', $statement );
	}

	/**
	 * Check if a statement is a DROP TABLE.
	 *
	 * @param string $statement The SQL statement.
	 * @return bool True if it's a DROP.
	 */
	private function is_drop_statement( string $statement ): bool {
		return (bool) preg_match( '/^\s*DROP\s+TABLE\s/i', $statement );
	}

	/**
	 * Check if a DROP/CREATE statement targets a table in our prefix scope.
	 *
	 * @param string $statement The SQL statement.
	 * @return bool True if the table is in scope.
	 */
	private function is_in_prefix_scope( string $statement ): bool {
		if ( preg_match( '/`([^`]+)`/', $statement, $m ) ) {
			$table = $m[1];
			return str_starts_with( $table, $this->source_prefix )
				|| str_starts_with( $table, $this->dest_prefix );
		}
		return true; // Can't determine — allow.
	}

	/**
	 * Set up the database connection for replay.
	 *
	 * Sets charset, disables FK checks if requested, handles sql_mode.
	 *
	 * @param string $charset    Character set to use.
	 * @param bool   $disable_fk Whether to disable FK checks.
	 */
	private function setup_connection( string $charset, bool $disable_fk ): void {
		// Set charset.
		$this->wpdb->query( "SET NAMES '{$charset}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		// Disable FK checks.
		if ( $disable_fk ) {
			$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Handle NO_BACKSLASH_ESCAPES — disable it for replay since our
		// exports use backslash escaping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mode = $this->wpdb->get_var( "SELECT @@SESSION.sql_mode" );

		if ( $mode && str_contains( $mode, 'NO_BACKSLASH_ESCAPES' ) ) {
			$new_mode = implode( ',', array_filter(
				explode( ',', $mode ),
				fn( $m ) => 'NO_BACKSLASH_ESCAPES' !== trim( $m )
			) );
			$this->wpdb->query( "SET SESSION sql_mode = '{$new_mode}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}
	}
}
