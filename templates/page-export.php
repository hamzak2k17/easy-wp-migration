<?php
/**
 * Export admin page template.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

$components       = EWPM_Export_Presets::get_components();
$exclusion_presets = EWPM_Export_Presets::get_exclusion_presets();
?>

<div class="wrap ewpm-wrap">
	<h1><?php esc_html_e( 'Export', 'easy-wp-migration' ); ?></h1>

	<nav class="ewpm-tabs">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-export' ) ); ?>" class="ewpm-tab ewpm-tab--active">
			<?php esc_html_e( 'Export', 'easy-wp-migration' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-import' ) ); ?>" class="ewpm-tab">
			<?php esc_html_e( 'Import', 'easy-wp-migration' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ewpm-backups' ) ); ?>" class="ewpm-tab">
			<?php esc_html_e( 'Backups', 'easy-wp-migration' ); ?>
		</a>
	</nav>

	<div class="ewpm-content">

		<div id="ewpm-export-form">
			<p><?php esc_html_e( 'Create a complete export of your WordPress site. Choose which components to include and click Export.', 'easy-wp-migration' ); ?></p>

			<!-- Components -->
			<div class="ewpm-export-section">
				<h3><?php esc_html_e( 'What to include', 'easy-wp-migration' ); ?></h3>
				<div class="ewpm-export-components">
					<?php foreach ( $components as $comp ) : ?>
						<?php
						$exists   = 'database' === $comp['id'] || ( ! empty( $comp['path'] ) && is_dir( $comp['path'] ) );
						$disabled = ! $exists ? 'disabled' : '';
						$checked  = $comp['default'] && $exists ? 'checked' : '';
						?>
						<label class="ewpm-export-component <?php echo $exists ? '' : 'ewpm-export-component--disabled'; ?>">
							<input type="checkbox"
								name="ewpm_component_<?php echo esc_attr( $comp['id'] ); ?>"
								data-component="<?php echo esc_attr( $comp['id'] ); ?>"
								class="ewpm-export-component__checkbox"
								<?php echo esc_attr( $checked ); ?>
								<?php echo esc_attr( $disabled ); ?>>
							<span><?php echo esc_html( $comp['label'] ); ?></span>
							<?php if ( ! $exists ) : ?>
								<em class="ewpm-export-component__note">
									<?php esc_html_e( '(not found)', 'easy-wp-migration' ); ?>
								</em>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Exclusions -->
			<div class="ewpm-export-section">
				<h3><?php esc_html_e( 'Exclusions', 'easy-wp-migration' ); ?></h3>
				<div class="ewpm-export-components">
					<?php foreach ( $exclusion_presets as $preset ) : ?>
						<label class="ewpm-export-component">
							<input type="checkbox"
								name="ewpm_exclusion_<?php echo esc_attr( $preset['id'] ); ?>"
								data-exclusion-preset="<?php echo esc_attr( $preset['id'] ); ?>"
								class="ewpm-export-exclusion__checkbox"
								<?php echo $preset['default'] ? 'checked' : ''; ?>>
							<span><?php echo esc_html( $preset['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<details class="ewpm-export-advanced">
					<summary><?php esc_html_e( 'Advanced -- custom exclusion patterns', 'easy-wp-migration' ); ?></summary>
					<p class="description">
						<?php esc_html_e( 'One glob pattern per line. Use ** for any path depth, * for any filename. Example: **/cache/**', 'easy-wp-migration' ); ?>
					</p>
					<textarea id="ewpm-custom-exclusions" rows="4" class="large-text code" placeholder="**/cache/**&#10;**/*.log"></textarea>
				</details>
			</div>

			<!-- Output -->
			<div class="ewpm-export-section">
				<h3><?php esc_html_e( 'Output', 'easy-wp-migration' ); ?></h3>
				<label class="ewpm-export-component">
					<input type="radio" name="ewpm_output" value="download" checked>
					<span><?php esc_html_e( 'Download when ready', 'easy-wp-migration' ); ?></span>
				</label>
				<label class="ewpm-export-component">
					<input type="radio" name="ewpm_output" value="backup">
					<span><?php esc_html_e( 'Save as server backup', 'easy-wp-migration' ); ?></span>
				</label>

				<div id="ewpm-backup-name-wrap" style="display: none; margin-top: 10px;">
					<label for="ewpm-backup-name">
						<?php esc_html_e( 'Backup name (optional):', 'easy-wp-migration' ); ?>
					</label>
					<input type="text" id="ewpm-backup-name" class="regular-text"
						placeholder="<?php echo esc_attr( ewpm_generate_backup_filename() ); ?>">
				</div>
			</div>

			<!-- Actions -->
			<div class="ewpm-export-actions">
				<button type="button" class="button button-primary button-hero" id="ewpm-export-start">
					<?php esc_html_e( 'Create Export', 'easy-wp-migration' ); ?>
				</button>

				<button type="button" class="button ewpm-cancel-btn" id="ewpm-export-cancel" style="display: none;">
					<?php esc_html_e( 'Cancel', 'easy-wp-migration' ); ?>
				</button>
			</div>
		</div>

		<!-- Progress (hidden until export starts) -->
		<div id="ewpm-export-progress" style="display: none;"></div>

		<!-- Result (hidden until done) -->
		<div id="ewpm-export-result" style="display: none;"></div>

	</div>
</div>
