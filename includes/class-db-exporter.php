<?php
/**
 * Database exporter engine.
 *
 * Pure-PHP database dumper that produces a single database.sql file.
 * Stateless service — the Job class drives it and manages cursors.
 * Uses $wpdb for all queries. No shell commands, no mysqldump.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_DB_Exporter
 *
 * Dumps WordPress database tables to a SQL file in chunks.
 */
class EWPM_DB_Exporter {

	/**
	 * WordPress database access object.
	 */
	private \wpdb $wpdb;

	/**
	 * Absolute path to the output SQL file.
	 */
	private string $output_path;

	/**
	 * Cached column type info keyed by table name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $column_types_cache = [];

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb        WordPress database instance.
	 * @param string $output_path Absolute path to the output SQL file.
	 */
	public function __construct( \wpdb $wpdb, string $output_path ) {
		$this->wpdb        = $wpdb;
		$this->output_path = $output_path;
	}

	/**
	 * Get all tables matching the site's table prefix.
	 *
	 * Returns table metadata from SHOW TABLE STATUS. Views, triggers,
	 * stored procedures, and events are skipped and reported as warnings.
	 *
	 * @return array{tables: array<int, array{name: string, row_count: int, size_bytes: int, has_numeric_pk: bool, pk_column: string|null}>, warnings: string[]}
	 */
	public function get_table_list(): array {
		$prefix   = $this->wpdb->prefix;
		$tables   = [];
		$warnings = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $this->wpdb->esc_like( $prefix ) . '%' ),
			ARRAY_A
		);

		if ( ! $status_rows ) {
			return [ 'tables' => [], 'warnings' => $warnings ];
		}

		foreach ( $status_rows as $row ) {
			$name = $row['Name'] ?? '';

			// Skip views (Engine is NULL for views).
			if ( empty( $row['Engine'] ) ) {
				$warnings[] = sprintf( 'Skipped view: %s', $name );
				continue;
			}

			$pk_info = $this->detect_primary_key( $name );

			$tables[] = [
				'name'           => $name,
				'row_count'      => (int) ( $row['Rows'] ?? 0 ),
				'size_bytes'     => (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ),
				'has_numeric_pk' => $pk_info['has_numeric_pk'],
				'pk_column'      => $pk_info['pk_column'],
			];
		}

		// Check for triggers and skip them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$triggers = $this->wpdb->get_results( 'SHOW TRIGGERS', ARRAY_A );

		if ( $triggers ) {
			foreach ( $triggers as $trigger ) {
				$warnings[] = sprintf( 'Skipped trigger: %s', $trigger['Trigger'] ?? 'unknown' );
			}
		}

		return [ 'tables' => $tables, 'warnings' => $warnings ];
	}

	/**
	 * Write the SQL file header.
	 *
	 * Sets connection charset, disables foreign key checks, and writes
	 * a metadata comment block.
	 *
	 * @throws EWPM_DB_Exporter_Exception On write failure.
	 */
	public function write_header(): void {
		$charset = $this->get_db_charset();
		$now     = gmdate( 'Y-m-d H:i:s' );

		$header  = "-- Easy WP Migration Database Export\n";
		$header .= "-- Generated: {$now} UTC\n";
		$header .= "-- Plugin Version: " . EWPM_VERSION . "\n";
		$header .= "-- PHP Version: " . PHP_VERSION . "\n";
		$header .= "-- MySQL Version: " . $this->wpdb->db_version() . "\n";
		$header .= "-- Database Charset: {$charset}\n";
		$header .= "--\n\n";
		$header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
		$header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
		$header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
		$header .= "/*!40101 SET NAMES {$charset} */;\n";
		$header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

		$this->write_to_file( $header, 'wb' );
	}

	/**
	 * Write the CREATE TABLE statement for a table.
	 *
	 * Uses SHOW CREATE TABLE to preserve original collation, engine,
	 * and charset settings.
	 *
	 * @param string $table Table name.
	 * @throws EWPM_DB_Exporter_Exception On query or write failure.
	 */
	public function write_table_structure( string $table ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$create_row = $this->wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SHOW CREATE TABLE `{$this->esc_table( $table )}`",
			ARRAY_A
		);

		if ( ! $create_row || ! isset( $create_row['Create Table'] ) ) {
			throw new EWPM_DB_Exporter_Exception(
				sprintf( 'Failed to get CREATE TABLE for: %s', $table )
			);
		}

		$sql  = "\n-- --------------------------------------------------------\n";
		$sql .= sprintf( "-- Table structure for `%s`\n", $this->esc_table( $table ) );
		$sql .= "-- --------------------------------------------------------\n\n";
		$sql .= sprintf( "DROP TABLE IF EXISTS `%s`;\n", $this->esc_table( $table ) );
		$sql .= $create_row['Create Table'] . ";\n\n";

		$this->write_to_file( $sql );
	}

	/**
	 * Write table rows in chunks, respecting time budget.
	 *
	 * Uses numeric PK pagination (WHERE pk > last_id) when available,
	 * falls back to OFFSET pagination otherwise. Each INSERT is one row.
	 *
	 * @param string $table              Table name.
	 * @param array  $cursor             Current cursor state.
	 * @param int    $chunk_size          Rows per SELECT query.
	 * @param int    $time_budget_seconds Maximum seconds to spend.
	 * @return array{last_id: int|null, offset: int, rows_written: int, done: bool, stopped_for_budget: bool}
	 * @throws EWPM_DB_Exporter_Exception On query or write failure.
	 */
	public function write_table_rows( string $table, array $cursor, int $chunk_size, int $time_budget_seconds ): array {
		$deadline     = microtime( true ) + $time_budget_seconds;
		$table_esc    = $this->esc_table( $table );
		$pk_info      = $this->detect_primary_key( $table );
		$has_pk       = $pk_info['has_numeric_pk'];
		$pk_col       = $pk_info['pk_column'];
		$col_types    = $this->get_column_types( $table );

		$last_id      = $cursor['last_id'] ?? null;
		$offset       = (int) ( $cursor['offset'] ?? 0 );
		$rows_written = (int) ( $cursor['rows_written'] ?? 0 );

		$fh = fopen( $this->output_path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fh ) {
			throw new EWPM_DB_Exporter_Exception(
				sprintf( 'Failed to open SQL file for appending: %s', $this->output_path )
			);
		}

		// Write data header comment for this table on first chunk.
		if ( 0 === $rows_written ) {
			fwrite( $fh, sprintf( "-- Data for `%s`\n\n", $table_esc ) );
		}

		try {
			while ( true ) {
				// Check time budget before each query.
				if ( microtime( true ) >= $deadline ) {
					fflush( $fh );
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return [
						'last_id'            => $last_id,
						'offset'             => $offset,
						'rows_written'       => $rows_written,
						'done'               => false,
						'stopped_for_budget' => true,
					];
				}

				// Build query: PK-based or offset-based.
				if ( $has_pk && $pk_col ) {
					if ( null !== $last_id ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$query = $this->wpdb->prepare(
							"SELECT * FROM `{$table_esc}` WHERE `{$this->esc_table( $pk_col )}` > %d ORDER BY `{$this->esc_table( $pk_col )}` ASC LIMIT %d",
							$last_id,
							$chunk_size
						);
					} else {
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$query = $this->wpdb->prepare(
							"SELECT * FROM `{$table_esc}` ORDER BY `{$this->esc_table( $pk_col )}` ASC LIMIT %d",
							$chunk_size
						);
					}
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$query = $this->wpdb->prepare(
						"SELECT * FROM `{$table_esc}` LIMIT %d OFFSET %d",
						$chunk_size,
						$offset
					);
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $this->wpdb->get_results( $query, ARRAY_A );

				if ( empty( $rows ) ) {
					fwrite( $fh, "\n" );
					fflush( $fh );
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return [
						'last_id'            => $last_id,
						'offset'             => $offset,
						'rows_written'       => $rows_written,
						'done'               => true,
						'stopped_for_budget' => false,
					];
				}

				$row_count = count( $rows );

				foreach ( $rows as $row ) {
					$columns = [];
					$values  = [];

					foreach ( $row as $col => $val ) {
						$columns[] = '`' . $this->esc_table( $col ) . '`';
						$values[]  = $this->escape_value( $val, $col_types[ $col ] ?? '' );
					}

					$line = sprintf(
						"INSERT INTO `%s` (%s) VALUES (%s);\n",
						$table_esc,
						implode( ', ', $columns ),
						implode( ', ', $values )
					);

					fwrite( $fh, $line );
					++$rows_written;

					if ( $has_pk && $pk_col && isset( $row[ $pk_col ] ) ) {
						$last_id = (int) $row[ $pk_col ];
					}
				}

				$offset += $row_count;
				$done    = $row_count < $chunk_size;

				// Release memory.
				unset( $rows );

				if ( $done ) {
					fwrite( $fh, "\n" );
					fflush( $fh );
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return [
						'last_id'            => $last_id,
						'offset'             => $offset,
						'rows_written'       => $rows_written,
						'done'               => true,
						'stopped_for_budget' => false,
					];
				}
			}
		} catch ( \Throwable $e ) {
			fflush( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new EWPM_DB_Exporter_Exception(
				sprintf( 'Error dumping rows for table %s: %s', $table, $e->getMessage() ),
				0,
				$e
			);
		}
	}

	/**
	 * Write the SQL file footer.
	 *
	 * Re-enables foreign key checks and restores charset settings.
	 *
	 * @throws EWPM_DB_Exporter_Exception On write failure.
	 */
	public function write_footer(): void {
		$footer  = "\nSET FOREIGN_KEY_CHECKS = 1;\n";
		$footer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
		$footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
		$footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
		$footer .= "\n-- Export complete.\n";

		$this->write_to_file( $footer );
	}

	/**
	 * Escape a value for use in an INSERT statement.
	 *
	 * Handles NULL, boolean, integer, float (including NaN/Inf), binary
	 * data (as hex literals), and strings (with proper SQL escaping).
	 *
	 * @param mixed  $val      The value to escape.
	 * @param string $col_type The MySQL column type (e.g. 'varchar', 'blob').
	 * @return string SQL-safe literal.
	 */
	public function escape_value( mixed $val, string $col_type = '' ): string {
		if ( is_null( $val ) ) {
			return 'NULL';
		}

		if ( is_bool( $val ) ) {
			return $val ? '1' : '0';
		}

		if ( is_int( $val ) ) {
			return (string) $val;
		}

		if ( is_float( $val ) ) {
			if ( is_nan( $val ) || is_infinite( $val ) ) {
				return 'NULL';
			}
			return (string) $val;
		}

		// Binary columns → hex literal.
		if ( $this->is_binary_type( $col_type ) ) {
			if ( '' === $val ) {
				return "''";
			}
			return '0x' . bin2hex( $val );
		}

		// String — escape with wpdb.
		return "'" . $this->wpdb->_real_escape( (string) $val ) . "'";
	}

	/**
	 * Detect the primary key for a table.
	 *
	 * @param string $table Table name.
	 * @return array{has_numeric_pk: bool, pk_column: string|null}
	 */
	private function detect_primary_key( string $table ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $this->wpdb->get_results(
			"SHOW COLUMNS FROM `{$this->esc_table( $table )}`",
			ARRAY_A
		);

		if ( ! $columns ) {
			return [ 'has_numeric_pk' => false, 'pk_column' => null ];
		}

		$pk_cols = [];

		foreach ( $columns as $col ) {
			if ( 'PRI' === ( $col['Key'] ?? '' ) ) {
				$pk_cols[] = $col;
			}
		}

		// Only use single-column numeric PKs for cursor-based pagination.
		if ( 1 === count( $pk_cols ) ) {
			$type = strtolower( $pk_cols[0]['Type'] ?? '' );

			if ( preg_match( '/^(big|medium|small|tiny)?int/', $type ) ) {
				return [
					'has_numeric_pk' => true,
					'pk_column'      => $pk_cols[0]['Field'],
				];
			}
		}

		return [ 'has_numeric_pk' => false, 'pk_column' => null ];
	}

	/**
	 * Get column type info for a table.
	 *
	 * @param string $table Table name.
	 * @return array<string, string> Column name → type string.
	 */
	private function get_column_types( string $table ): array {
		if ( isset( $this->column_types_cache[ $table ] ) ) {
			return $this->column_types_cache[ $table ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $this->wpdb->get_results(
			"SHOW COLUMNS FROM `{$this->esc_table( $table )}`",
			ARRAY_A
		);

		$types = [];

		if ( $columns ) {
			foreach ( $columns as $col ) {
				$types[ $col['Field'] ] = strtolower( $col['Type'] ?? '' );
			}
		}

		$this->column_types_cache[ $table ] = $types;
		return $types;
	}

	/**
	 * Check whether a column type is binary.
	 *
	 * @param string $col_type The MySQL column type string.
	 * @return bool True if the column holds binary data.
	 */
	private function is_binary_type( string $col_type ): bool {
		$col_type = strtolower( $col_type );

		return (bool) preg_match( '/\b(binary|varbinary|blob|tinyblob|mediumblob|longblob)\b/', $col_type );
	}

	/**
	 * Get the database character set.
	 *
	 * Prefers utf8mb4 if available, falls back to the database default.
	 *
	 * @return string Character set name.
	 */
	private function get_db_charset(): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			"SHOW VARIABLES LIKE 'character_set_database'",
			ARRAY_A
		);

		$charset = $row['Value'] ?? 'utf8mb4';

		// Prefer utf8mb4 if the DB supports it.
		if ( str_starts_with( $charset, 'utf8' ) ) {
			return 'utf8mb4';
		}

		return $charset;
	}

	/**
	 * Escape a table or column name for safe interpolation.
	 *
	 * Strips backticks to prevent injection in backtick-quoted identifiers.
	 *
	 * @param string $name The identifier to escape.
	 * @return string Escaped identifier (without surrounding backticks).
	 */
	private function esc_table( string $name ): string {
		return str_replace( '`', '', $name );
	}

	/**
	 * Write content to the SQL file.
	 *
	 * @param string $content Data to write.
	 * @param string $mode    File open mode. Default 'ab' (append binary).
	 * @throws EWPM_DB_Exporter_Exception On write failure.
	 */
	private function write_to_file( string $content, string $mode = 'ab' ): void {
		$fh = fopen( $this->output_path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fh ) {
			throw new EWPM_DB_Exporter_Exception(
				sprintf( 'Failed to open SQL file: %s', $this->output_path )
			);
		}

		$written = fwrite( $fh, $content );
		fflush( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $written ) {
			throw new EWPM_DB_Exporter_Exception(
				sprintf( 'Failed to write to SQL file: %s', $this->output_path )
			);
		}
	}
}
