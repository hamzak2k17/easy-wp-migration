<?php
/**
 * Backups admin page template.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap ewpm-wrap">
	<h1><?php esc_html_e( 'Backups', 'easy-wp-migration' ); ?></h1>

	<nav class="ewpm-tabs">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-export' ) ); ?>" class="ewpm-tab">
			<?php esc_html_e( 'Export', 'easy-wp-migration' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-import' ) ); ?>" class="ewpm-tab">
			<?php esc_html_e( 'Import', 'easy-wp-migration' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-backups' ) ); ?>" class="ewpm-tab ewpm-tab--active">
			<?php esc_html_e( 'Backups', 'easy-wp-migration' ); ?>
		</a>
	</nav>

	<div class="ewpm-content" id="ewpm-backups-page">

		<!-- Top bar -->
		<div class="ewpm-backups-header">
			<p>
				<?php esc_html_e( 'Server-side backups of your site. User backups are kept indefinitely. Auto-snapshots (created before imports) are cleaned up after 30 days.', 'easy-wp-migration' ); ?>
			</p>
			<div class="ewpm-backups-header__actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-export' ) ); ?>" class="button">
					<?php esc_html_e( 'Create new backup via Export', 'easy-wp-migration' ); ?>
				</a>
				<span id="ewpm-backups-storage-summary"></span>
			</div>
		</div>

		<!-- Filter bar -->
		<div class="ewpm-backups-filters">
			<label class="ewpm-import-source-tab">
				<input type="radio" name="ewpm_backup_filter" value="all" checked>
				<?php esc_html_e( 'All', 'easy-wp-migration' ); ?>
			</label>
			<label class="ewpm-import-source-tab">
				<input type="radio" name="ewpm_backup_filter" value="user">
				<?php esc_html_e( 'User backups', 'easy-wp-migration' ); ?>
			</label>
			<label class="ewpm-import-source-tab">
				<input type="radio" name="ewpm_backup_filter" value="auto">
				<?php esc_html_e( 'Auto-snapshots', 'easy-wp-migration' ); ?>
			</label>

			<input type="text" id="ewpm-backups-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by filename...', 'easy-wp-migration' ); ?>" style="margin-left: auto;">
		</div>

		<!-- Bulk actions -->
		<div class="ewpm-backups-bulk" style="display:none;">
			<button type="button" class="button" id="ewpm-backups-bulk-delete">
				<?php esc_html_e( 'Delete selected', 'easy-wp-migration' ); ?>
			</button>
			<span id="ewpm-backups-selected-count"></span>
		</div>

		<!-- Backup list -->
		<div id="ewpm-backups-list">
			<p><?php esc_html_e( 'Loading backups...', 'easy-wp-migration' ); ?></p>
		</div>

		<!-- Consent modal (reused from import) -->
		<div id="ewpm-restore-modal" class="ewpm-modal" style="display:none;">
			<div class="ewpm-modal__backdrop"></div>
			<div class="ewpm-modal__content">
				<h2 class="ewpm-modal__title"><?php esc_html_e( 'Restore will overwrite your entire site', 'easy-wp-migration' ); ?></h2>

				<div class="ewpm-modal__checklist">
					<label><input type="checkbox" class="ewpm-restore-check"> <?php esc_html_e( 'I understand the current database will be replaced', 'easy-wp-migration' ); ?></label>
					<label><input type="checkbox" class="ewpm-restore-check"> <?php esc_html_e( 'I understand new content created after this backup will be lost', 'easy-wp-migration' ); ?></label>
					<label><input type="checkbox" class="ewpm-restore-check"> <?php esc_html_e( 'I have exported any recent data I need to preserve', 'easy-wp-migration' ); ?></label>
					<label><input type="checkbox" class="ewpm-restore-check"> <?php esc_html_e( 'I understand the admin login may change', 'easy-wp-migration' ); ?></label>
				</div>

				<label class="ewpm-export-component" style="margin: 12px 0;">
					<input type="checkbox" id="ewpm-restore-auto-snapshot" checked>
					<span><?php esc_html_e( 'Create safety snapshot before restoring (recommended)', 'easy-wp-migration' ); ?></span>
				</label>

				<div class="ewpm-modal__confirm">
					<label for="ewpm-restore-confirm-input"><?php esc_html_e( 'Type IMPORT to confirm:', 'easy-wp-migration' ); ?></label>
					<input type="text" id="ewpm-restore-confirm-input" placeholder="IMPORT" autocomplete="off">
				</div>

				<div class="ewpm-modal__actions">
					<button type="button" class="button" id="ewpm-restore-modal-cancel"><?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?></button>
					<button type="button" class="button button-primary" id="ewpm-restore-modal-confirm" disabled><?php esc_html_e( 'Restore', 'easy-wp-migration' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Delete confirmation modal -->
		<div id="ewpm-delete-modal" class="ewpm-modal" style="display:none;">
			<div class="ewpm-modal__backdrop"></div>
			<div class="ewpm-modal__content">
				<h2 class="ewpm-modal__title" style="color: #d63638;"><?php esc_html_e( 'Delete backup permanently?', 'easy-wp-migration' ); ?></h2>
				<p id="ewpm-delete-modal-message"></p>
				<div class="ewpm-modal__actions">
					<button type="button" class="button" id="ewpm-delete-modal-cancel"><?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?></button>
					<button type="button" class="button ewpm-cancel-btn" id="ewpm-delete-modal-confirm"><?php esc_html_e( 'Delete permanently', 'easy-wp-migration' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Generate Migration Link modal -->
		<div id="ewpm-miglink-modal" class="ewpm-modal" style="display:none;">
			<div class="ewpm-modal__backdrop"></div>
			<div class="ewpm-modal__content">
				<h2 class="ewpm-modal__title" style="color: #1d2327;"><?php esc_html_e( 'Generate Migration Link', 'easy-wp-migration' ); ?></h2>

				<div id="ewpm-miglink-form">
					<p><strong id="ewpm-miglink-filename"></strong></p>

					<label for="ewpm-miglink-expiry"><?php esc_html_e( 'Link expires in:', 'easy-wp-migration' ); ?></label>
					<select id="ewpm-miglink-expiry">
						<option value="3600"><?php esc_html_e( '1 hour', 'easy-wp-migration' ); ?></option>
						<option value="86400" selected><?php esc_html_e( '24 hours', 'easy-wp-migration' ); ?></option>
						<option value="604800"><?php esc_html_e( '7 days', 'easy-wp-migration' ); ?></option>
						<option value="custom"><?php esc_html_e( 'Custom', 'easy-wp-migration' ); ?></option>
					</select>

					<span id="ewpm-miglink-custom-wrap" style="display:none;">
						<input type="number" id="ewpm-miglink-custom-val" value="48" min="1" max="720" style="width:70px;">
						<select id="ewpm-miglink-custom-unit">
							<option value="3600"><?php esc_html_e( 'hours', 'easy-wp-migration' ); ?></option>
							<option value="86400"><?php esc_html_e( 'days', 'easy-wp-migration' ); ?></option>
						</select>
					</span>

					<p class="description" id="ewpm-miglink-long-warning" style="display:none; color: #dba617;">
						<?php esc_html_e( 'Longer expiry means a larger window for unauthorized access if the link leaks.', 'easy-wp-migration' ); ?>
					</p>

					<div class="ewpm-modal__actions" style="margin-top: 16px;">
						<button type="button" class="button" id="ewpm-miglink-modal-cancel"><?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?></button>
						<button type="button" class="button button-primary" id="ewpm-miglink-generate"><?php esc_html_e( 'Generate', 'easy-wp-migration' ); ?></button>
					</div>
				</div>

				<div id="ewpm-miglink-result" style="display:none;">
					<p><?php esc_html_e( 'Migration link generated:', 'easy-wp-migration' ); ?></p>
					<div style="display:flex; gap:8px; align-items:center;">
						<input type="text" id="ewpm-miglink-url" class="large-text code" readonly>
						<button type="button" class="button" id="ewpm-miglink-copy"><?php esc_html_e( 'Copy', 'easy-wp-migration' ); ?></button>
					</div>
					<p id="ewpm-miglink-expiry-countdown" style="margin-top: 8px; font-size: 13px; color: #50575e;"></p>
					<p class="description" style="margin-top: 8px;">
						<?php esc_html_e( 'This link grants unauthenticated access to a full site export including database and user data. Share only over secure channels.', 'easy-wp-migration' ); ?>
					</p>
					<details style="margin-top: 8px;">
						<summary><?php esc_html_e( 'Alternative URL (for hosts without pretty permalinks)', 'easy-wp-migration' ); ?></summary>
						<input type="text" id="ewpm-miglink-url-fallback" class="large-text code" readonly style="margin-top: 6px;">
					</details>
					<div class="ewpm-modal__actions" style="margin-top: 16px;">
						<button type="button" class="button" id="ewpm-miglink-done"><?php esc_html_e( 'Close', 'easy-wp-migration' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Migration Links section -->
		<div class="ewpm-import-section" id="ewpm-migration-links-section" style="margin-top: 24px;">
			<details id="ewpm-miglinks-details">
				<summary>
					<strong><?php esc_html_e( 'Migration Links', 'easy-wp-migration' ); ?></strong>
					<span id="ewpm-miglinks-badge" style="display:none;"></span>
				</summary>

				<div style="margin-top: 10px;">
					<p class="description">
						<?php esc_html_e( 'Migration links let other sites pull backups from this server. They expire automatically but can be revoked earlier.', 'easy-wp-migration' ); ?>
					</p>

					<div style="margin: 10px 0;">
						<button type="button" class="button ewpm-cancel-btn" id="ewpm-revoke-all-links">
							<?php esc_html_e( 'Revoke all links', 'easy-wp-migration' ); ?>
						</button>
					</div>

					<div id="ewpm-miglinks-table-container">
						<p><?php esc_html_e( 'Loading...', 'easy-wp-migration' ); ?></p>
					</div>
				</div>
			</details>
		</div>

		<!-- Advanced section -->
		<details class="ewpm-export-advanced" style="margin-top: 30px;">
			<summary><?php esc_html_e( 'Advanced: Auto-snapshot cleanup', 'easy-wp-migration' ); ?></summary>
			<div style="margin-top: 10px;">
				<p>
					<?php
					printf(
						/* translators: %d: retention days */
						esc_html__( 'Auto-snapshots older than %d days are automatically cleaned up daily.', 'easy-wp-migration' ),
						EWPM_AUTO_SNAPSHOT_RETENTION_DAYS
					);
					?>
				</p>
				<?php
				$last_cleanup = get_option( 'ewpm_last_auto_cleanup', '' );
				if ( $last_cleanup ) {
					printf(
						'<p>%s: %s</p>',
						esc_html__( 'Last auto-cleanup', 'easy-wp-migration' ),
						esc_html( $last_cleanup )
					);
				}
				?>
				<button type="button" class="button" id="ewpm-run-cleanup-now">
					<?php esc_html_e( 'Run cleanup now', 'easy-wp-migration' ); ?>
				</button>
				<span id="ewpm-cleanup-result"></span>
			</div>
		</details>

	</div>
</div>
