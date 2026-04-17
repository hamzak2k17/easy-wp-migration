# Changelog

All notable changes to Easy WP Migration will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0] — 2026-04-17

### Added

- `EWPM_Migration_Tokens` — HMAC-SHA256 signed migration link generation with base64url-encoded JSON payloads, timing-safe signature validation via `hash_equals()`, per-link revocation, and secret regeneration for bulk revoke
- `EWPM_Rate_Limiter` — transient-based sliding window rate limiting (20 requests/token/60s) applied before token validation
- `EWPM_Migration_Endpoint` — public unauthenticated endpoint for serving backup files with HTTP Range support (206 Partial Content), chunked streaming, output buffer bypass, no cookies/sessions
- Pretty URL route `ewpm-migrate/{token}` via rewrite rules with query-string fallback for broken rewrite hosts
- Active migration links registry in `wp_options` with access tracking (count, IP, timestamp), revocation status, and automatic pruning of 30-day-old entries via daily cron
- Backups tab: "Migration Link" button per backup row with expiry dropdown (1h / 24h / 7d / Custom), copy-to-clipboard, countdown timer, fallback URL display
- Backups tab: "Migration Links" collapsible section showing all links with status badges (Active/Expired/Revoked/File Missing), access stats, and per-link Revoke action
- "Revoke all links" button regenerating HMAC secret to instantly invalidate all existing links
- AJAX endpoints: `ewpm_generate_migration_link`, `ewpm_list_migration_links`, `ewpm_revoke_migration_link`, `ewpm_revoke_all_migration_links`
- Rewrite rules flushed on activation/deactivation and on plugin version change

## [0.8.0] — 2026-04-17

### Added

- `EWPM_Backups` service class: listing, metadata reading (cached), deletion with realpath guard, auto-snapshot cleanup with 7-day minimum retention clamp
- Backups tab UI replacing Phase 1 placeholder: backup list table with type labels, source URL, size, date, and details expander
- Filter bar: All / User backups / Auto-snapshots radio filters + filename search
- Per-backup actions: Restore (full consent modal + inline progress), Download (streaming), Delete (confirmation modal)
- Bulk delete with per-file error handling (partial success supported)
- Restore-from-backup flow: consent modal (4 checkboxes + IMPORT), optional safety snapshot, inline progress in backup row
- Auto-snapshot cleanup via daily WP cron (`ewpm_daily_cleanup`): deletes `auto-before-import-*` files older than 30 days (configurable via `EWPM_AUTO_SNAPSHOT_RETENTION_DAYS`, min 7)
- Manual "Run cleanup now" button in Advanced section with freed space reporting
- Activation hook schedules cron; deactivation hook unschedules it
- Storage usage summary display
- Corrupt archive handling: list shows metadata_error when archive is unreadable, other actions still work
- AJAX endpoints: `ewpm_delete_backup`, `ewpm_delete_backups_bulk`, `ewpm_run_cleanup_now`
- Enhanced `ewpm_list_backups` to include metadata, source URL, and auto-snapshot flag

## [0.7.0] — 2026-04-17

### Added

- Import tab UI replacing Phase 1 placeholder with full import workflow
- Chunked file upload (`EWPM_Upload_Handler`) supporting files larger than PHP's `upload_max_filesize`: 1MB chunks, SHA-256 verification, stale .part cleanup
- Drag-and-drop upload zone with fallback file input
- Server backup picker dropdown via `ewpm_list_backups` endpoint
- Pre-import preview via `ewpm_import_preview` endpoint: source URL, WP/PHP version, component breakdown, compatibility warnings
- Consent modal with 4-checkbox data-loss acknowledgement + type-to-confirm IMPORT challenge
- Auto-snapshot before import: triggers full site export to backups/ (reuses Phase 5 export job) with `auto-before-import-` prefix
- Two-stage progress: snapshot phase (0-30%) then import phase (30-100%)
- Post-import result screen with rollback instructions, snapshot filename, DB/file stats, warnings, and housekeeping checklist
- Enhanced `post_import_fixup`: permalink structure change detection, theme change detection, plugin activation diff, opcache flush
- Pre-import state snapshot capture in validate_archive phase for accurate post-import comparison
- Page reload resume for import jobs via sessionStorage
- Upload AJAX endpoints: `ewpm_upload_start`, `ewpm_upload_chunk`, `ewpm_upload_finalize`, `ewpm_upload_abort`

## [0.6.0] — 2026-04-17

### Added

- `EWPM_Serializer_Fix` — serialization-aware search-replace with byte-length re-encoding for PHP's `s:N:"..."` format. Handles nested serialized arrays/objects, multibyte strings, and mixed plain+serialized content.
- `EWPM_DB_Importer` — SQL replay engine with chunked statement parsing, table prefix rewriting, connection retry on MySQL gone away, NO_BACKSLASH_ESCAPES sql_mode handling, and prefix-scoped DROP safety.
- `EWPM_File_Importer` — archive file extraction to WordPress paths with conflict strategies (overwrite/skip/rename-old), path traversal defense, WP root boundary enforcement, and self-storage folder protection.
- `EWPM_Job_Import` — import job with phases: validate_archive, extract_database_sql, replay_database (with serialization-aware URL replacement), extract_files, post_import_fixup (flush rewrite rules, cache, force siteurl/home to current values).
- Serialization-aware URL replacement applied to INSERT statements during DB replay: handles site_url, home_url, URL-encoded variants, optional filesystem path replacement.
- Dev Tools: "Import Test" section with backup archive picker, conflict strategy, path replacement toggle, stop-on-error toggle, and IMPORT confirmation challenge.
- `ewpm_dev_list_backups` AJAX endpoint listing backups/ folder contents.
- CLI test suite `tests/test-serializer-fix.php` with 12 test groups covering plain strings, length-changing replacements, multibyte, nested serialization, objects, mixed content, malformed data, and false-positive resistance.

## [0.5.0] — 2026-04-17

### Added

- `EWPM_File_Scanner` — recursive file enumeration with glob-based exclusion matching, symlink loop detection, size cap enforcement, chunked scanning with time budget
- `EWPM_Export_Presets` — component definitions (database, themes, plugins, media, other wp-content) and exclusion presets (cache, other backups, logs, dev files) with hardcoded forbidden patterns
- `EWPM_Job_Export` — the full site export job composing file scanner, DB exporter, and archiver into 6 phases: init, scan_files, dump_database, archive_database, archive_files, finalize_archive
- Export tab UI with component checkboxes, exclusion presets, custom pattern textarea, download/backup output options, progress bar, cancel, and result display
- `EWPM_Archiver_Interface::open_for_append()` — reopen existing archive for multi-tick file addition without overwriting
- `EWPM_Archiver_Interface::update_metadata()` — set cumulative metadata before final close
- `EWPM.Job.resume()` — resume polling an existing job by ID (for page reload recovery)
- `EWPM.Export` — export page JS with form collection, session storage resume, and result rendering
- `ewpm_download_archive` AJAX endpoint streaming completed archives via `readfile()` with path validation (tmp/ and backups/ only)
- `ewpm_format_bytes()` and `ewpm_generate_backup_filename()` helper functions
- `EWPM_MAX_FILE_SIZE` constant (default 2 GB, overridable in wp-config.php)
- NDJSON file list in tmp/ for memory-efficient handling of 100k+ file inventories
- Atomic move (rename with copy+verify fallback) for save-as-backup flow
- Self-exclusion: plugin storage folder always excluded from exports to prevent archive-includes-itself loops

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
