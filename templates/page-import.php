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

	<div class="ewpm-content">
		<p><?php esc_html_e( 'Coming in Phase 3.', 'easy-wp-migration' ); ?></p>
	</div>
</div>
