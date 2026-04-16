# Changelog

All notable changes to Easy WP Migration will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] — 2026-04-17

### Added

- `EWPM_DB_Exporter` — pure-PHP database dump engine using `$wpdb`, no shell commands
- Chunked row export with numeric PK cursor pagination (falls back to OFFSET for composite/no PK)
- Single-row INSERT statements to avoid max_allowed_packet issues on constrained hosts
- SQL value escaping: NULL, boolean, integer, float (NaN/Inf → NULL), binary (hex literals), multibyte strings
- Table structure via `SHOW CREATE TABLE` preserving original collation, engine, charset
- SQL file header/footer with `SET NAMES`, `FOREIGN_KEY_CHECKS` management, charset detection
- Views, triggers, stored procedures, and events skipped with warnings surfaced in export summary
- `EWPM_DB_Exporter_Exception` for type-specific database export errors
- `EWPM_Job_DB_Export` — job framework wrapper for isolated DB export testing (type: `db_export`)
- Job phases: init (enumerate tables, write header) → dump_tables (chunked row export with time budget) → finalize_sql (write footer) → done
- Progress reporting: bytes-written vs estimated total, per-table row counters
- Cancel flag check between tables for responsive mid-export cancellation
- Cleanup deletes partial SQL file on cancel/error with realpath guard (tmp/ only)
- Dev Tools: "Database Export Test" section with chunk size control, live progress, cancel, and SQL download
- Dev-only `ewpm_dev_download_sql` AJAX endpoint streaming completed SQL files via `readfile()`
- `EWPM_State::cleanup_stale()` now cleans both `archive_path` and `output_path` from stale jobs

## [0.3.0] — 2026-04-16

### Added

- Plugin skeleton with standard WordPress plugin headers (PHP 8.1+, WP 6.2+, GPLv2+)
- PSR-4-ish class autoloader supporting classes, interfaces, and abstract classes
- Activation hook creating `wp-content/easy-wp-migration-storage/` with `backups/`, `tmp/`, `.htaccess` protection, and silent `index.php` files
- Deactivation hook stub (preserves data for reactivation)
- `EWPM_Plugin` singleton as the main entry point with admin menu registration
- Top-level admin menu "Easy WP Migration" with Export, Import, and Backups submenu tabs
- Tab navigation UI with active-state styling across all admin pages
- Asset enqueue system gated to plugin pages only (`admin.css`, `admin.js`)
- Helper functions: `ewpm_get_storage_dir()`, `ewpm_get_backups_dir()`, `ewpm_get_tmp_dir()`, `ewpm_is_plugin_page()`
- Template missing error: `render_template()` calls `wp_die()` instead of failing silently
- `EWPM_Archiver_Interface` defining the contract for all archive format implementations
- `EWPM_Archiver_Zip` wrapping PHP ZipArchive for `.ezmig` files (create, read, add files/strings, extract, list entries)
- `EWPM_Archiver_Metadata` building and validating `metadata.json` inside archives (format version, source environment, component flags, stats)
- `EWPM_Archiver_Exception` for type-specific archive error handling
- `EWPM_Archiver_Factory` as the single point of instantiation for archivers (swap-ready for v2 format)
- `EWPM_ARCHIVE_EXTENSION` constant (`ezmig`)
- CLI test script `tests/test-archiver.php` for standalone archiver verification
- `EWPM_State` class for job state persistence with atomic writes (write-to-tmp, rename) and advisory file locking via `flock()`
- `EWPM_Job` abstract base class for resumable, chunked long-running operations with time-budget enforcement and cancellation support
- `EWPM_Job_Registry` singleton mapping job types to concrete classes
- `EWPM_Job_Dummy` test job simulating a 3-phase workflow (init, counting, finalize) with configurable delay
- `EWPM_Ajax` handler with five job endpoints: start, tick, cancel, progress, finalize — all nonce-verified and capability-checked
- `EWPM.Job.start()` frontend polling loop driving the server-side job framework via sequential fetch calls
- `EWPM.UI.renderProgress()` reusable progress bar component with phase label, percentage, and state-based styling
- Stale job cleanup (`EWPM_State::cleanup_stale()`) with realpath safety guard — only deletes inside `tmp/`, never touches `backups/`
- Dev Tools admin page (gated by `EWPM_DEV_MODE` + `WP_DEBUG`) with dummy job runner, active jobs list, and manual cleanup trigger
- `EWPM_DEV_MODE` and `EWPM_TICK_BUDGET_SECONDS` configurable constants
