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
