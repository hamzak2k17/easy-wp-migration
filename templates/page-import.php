<?php
/**
 * Import admin page template.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap ewpm-wrap">
	<h1><?php esc_html_e( 'Import', 'easy-wp-migration' ); ?></h1>

	<nav class="ewpm-tabs">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-export' ) ); ?>" class="ewpm-tab">
			<?php esc_html_e( 'Export', 'easy-wp-migration' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-import' ) ); ?>" class="ewpm-tab ewpm-tab--active">
			<?php esc_html_e( 'Import', 'easy-wp-migration' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-backups' ) ); ?>" class="ewpm-tab">
			<?php esc_html_e( 'Backups', 'easy-wp-migration' ); ?>
		</a>
	</nav>

	<div class="ewpm-content" id="ewpm-import-page">

		<!-- Step 1: Choose archive -->
		<div class="ewpm-import-section" id="ewpm-import-step1">
			<h3><?php esc_html_e( 'Choose archive', 'easy-wp-migration' ); ?></h3>

			<div class="ewpm-import-source-tabs">
				<label class="ewpm-import-source-tab">
					<input type="radio" name="ewpm_import_source" value="upload" checked>
					<?php esc_html_e( 'Upload file', 'easy-wp-migration' ); ?>
				</label>
				<label class="ewpm-import-source-tab">
					<input type="radio" name="ewpm_import_source" value="backup">
					<?php esc_html_e( 'Pick from server backups', 'easy-wp-migration' ); ?>
				</label>
			</div>

			<!-- Upload panel -->
			<div id="ewpm-import-upload-panel">
				<div class="ewpm-dropzone" id="ewpm-dropzone">
					<p><?php esc_html_e( 'Drag and drop your .ezmig file here, or click to browse.', 'easy-wp-migration' ); ?></p>
					<input type="file" id="ewpm-import-file" accept=".ezmig" style="display:none;">
				</div>
				<div id="ewpm-upload-info" style="display:none;">
					<p><strong id="ewpm-upload-filename"></strong> (<span id="ewpm-upload-filesize"></span>)</p>
					<div id="ewpm-upload-progress"></div>
				</div>
			</div>

			<!-- Backup picker panel -->
			<div id="ewpm-import-backup-panel" style="display:none;">
				<select id="ewpm-import-backup-select" class="regular-text">
					<option value=""><?php esc_html_e( 'Loading backups...', 'easy-wp-migration' ); ?></option>
				</select>
			</div>
		</div>

		<!-- Step 2: Preview (hidden until archive chosen) -->
		<div class="ewpm-import-section" id="ewpm-import-step2" style="display:none;">
			<h3><?php esc_html_e( 'Pre-import preview', 'easy-wp-migration' ); ?></h3>
			<div id="ewpm-import-preview-content"></div>
		</div>

		<!-- Step 3: Safety options (hidden until preview loaded) -->
		<div class="ewpm-import-section" id="ewpm-import-step3" style="display:none;">
			<h3><?php esc_html_e( 'Safety options', 'easy-wp-migration' ); ?></h3>

			<label class="ewpm-export-component">
				<input type="checkbox" id="ewpm-import-auto-snapshot" checked>
				<span><?php esc_html_e( 'Create safety backup before import (recommended)', 'easy-wp-migration' ); ?></span>
			</label>

			<label class="ewpm-export-component">
				<input type="checkbox" id="ewpm-import-flush-caches" checked>
				<span><?php esc_html_e( 'Flush caches after import', 'easy-wp-migration' ); ?></span>
			</label>

			<details class="ewpm-export-advanced">
				<summary><?php esc_html_e( 'Advanced', 'easy-wp-migration' ); ?></summary>

				<div style="margin-top: 10px;">
					<p><strong><?php esc_html_e( 'File conflict strategy:', 'easy-wp-migration' ); ?></strong></p>
					<label class="ewpm-export-component">
						<input type="radio" name="ewpm_import_conflict" value="overwrite" checked>
						<span><?php esc_html_e( 'Overwrite existing files', 'easy-wp-migration' ); ?></span>
					</label>
					<label class="ewpm-export-component">
						<input type="radio" name="ewpm_import_conflict" value="skip">
						<span><?php esc_html_e( 'Skip existing files', 'easy-wp-migration' ); ?></span>
					</label>
					<label class="ewpm-export-component">
						<input type="radio" name="ewpm_import_conflict" value="rename-old">
						<span><?php esc_html_e( 'Backup existing files (rename with .backup suffix)', 'easy-wp-migration' ); ?></span>
					</label>
				</div>

				<label class="ewpm-export-component" style="margin-top: 10px;">
					<input type="checkbox" id="ewpm-import-replace-paths">
					<span><?php esc_html_e( 'Also replace filesystem paths (advanced)', 'easy-wp-migration' ); ?></span>
				</label>

				<label class="ewpm-export-component">
					<input type="checkbox" id="ewpm-import-stop-error">
					<span><?php esc_html_e( 'Stop on first database error', 'easy-wp-migration' ); ?></span>
				</label>
			</details>

			<div class="ewpm-export-actions" style="margin-top: 16px;">
				<button type="button" class="button button-primary button-hero" id="ewpm-import-start">
					<?php esc_html_e( 'Start Import', 'easy-wp-migration' ); ?>
				</button>
			</div>
		</div>

		<!-- Consent Modal (hidden) -->
		<div id="ewpm-import-modal" class="ewpm-modal" style="display:none;">
			<div class="ewpm-modal__backdrop"></div>
			<div class="ewpm-modal__content">
				<h2 class="ewpm-modal__title"><?php esc_html_e( 'This will overwrite your entire site', 'easy-wp-migration' ); ?></h2>

				<div class="ewpm-modal__checklist">
					<label><input type="checkbox" class="ewpm-consent-check"> <?php esc_html_e( 'I understand the current site\'s database will be replaced', 'easy-wp-migration' ); ?></label>
					<label><input type="checkbox" class="ewpm-consent-check"> <?php esc_html_e( 'I understand any new orders/users/posts/comments created AFTER the backup will be permanently lost', 'easy-wp-migration' ); ?></label>
					<label><input type="checkbox" class="ewpm-consent-check"> <?php esc_html_e( 'I have exported any recent live data I need to preserve (if applicable)', 'easy-wp-migration' ); ?></label>
					<label><input type="checkbox" class="ewpm-consent-check"> <?php esc_html_e( 'I understand the admin login will change to the source site\'s credentials', 'easy-wp-migration' ); ?></label>
				</div>

				<div class="ewpm-modal__confirm">
					<label for="ewpm-import-confirm-input">
						<?php esc_html_e( 'Type IMPORT to confirm:', 'easy-wp-migration' ); ?>
					</label>
					<input type="text" id="ewpm-import-confirm-input" placeholder="IMPORT" autocomplete="off">
				</div>

				<div class="ewpm-modal__actions">
					<button type="button" class="button" id="ewpm-import-modal-cancel">
						<?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?>
					</button>
					<button type="button" class="button button-primary" id="ewpm-import-modal-confirm" disabled>
						<?php esc_html_e( 'Start Import', 'easy-wp-migration' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Step 5: Progress (hidden until import starts) -->
		<div id="ewpm-import-progress-area" style="display:none;">
			<div id="ewpm-import-progress"></div>
			<button type="button" class="button ewpm-cancel-btn" id="ewpm-import-cancel" style="display:none;">
				<?php esc_html_e( 'Cancel Import', 'easy-wp-migration' ); ?>
			</button>
		</div>

		<!-- Step 6: Result (hidden until done) -->
		<div id="ewpm-import-result" style="display:none;"></div>

	</div>
</div>
