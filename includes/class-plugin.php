<?php
/**
 * Main plugin class.
 *
 * Singleton entry point that registers the admin menu, enqueues assets,
 * boots the job framework, and routes to the correct template for each
 * admin page.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Plugin
 *
 * Core orchestrator for Easy WP Migration.
 */
class EWPM_Plugin {

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	/**
	 * The top-level menu hook suffix.
	 */
	private string $hook_suffix = '';

	/**
	 * Sub-page hook suffixes keyed by slug.
	 *
	 * @var array<string, string>
	 */
	private array $sub_hooks = [];

	/**
	 * Return the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor — wires up WordPress hooks and boots subsystems.
	 */
	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		$this->register_jobs();
		new EWPM_Ajax();
	}

	/**
	 * Register built-in job types with the job registry.
	 */
	private function register_jobs(): void {
		$registry = EWPM_Job_Registry::instance();
		$registry->register( 'dummy', EWPM_Job_Dummy::class );
	}

	/**
	 * Register the top-level menu and submenu pages.
	 */
	public function register_menus(): void {
		$this->hook_suffix = add_menu_page(
			__( 'Easy WP Migration', 'easy-wp-migration' ),
			__( 'Easy WP Migration', 'easy-wp-migration' ),
			'manage_options',
			'ewpm-export',
			[ $this, 'render_export_page' ],
			'dashicons-migrate',
			76
		);

		$this->sub_hooks['export'] = add_submenu_page(
			'ewpm-export',
			__( 'Export', 'easy-wp-migration' ),
			__( 'Export', 'easy-wp-migration' ),
			'manage_options',
			'ewpm-export',
			[ $this, 'render_export_page' ]
		);

		$this->sub_hooks['import'] = add_submenu_page(
			'ewpm-export',
			__( 'Import', 'easy-wp-migration' ),
			__( 'Import', 'easy-wp-migration' ),
			'manage_options',
			'ewpm-import',
			[ $this, 'render_import_page' ]
		);

		$this->sub_hooks['backups'] = add_submenu_page(
			'ewpm-export',
			__( 'Backups', 'easy-wp-migration' ),
			__( 'Backups', 'easy-wp-migration' ),
			'manage_options',
			'ewpm-backups',
			[ $this, 'render_backups_page' ]
		);

		// Dev Tools — only when both EWPM_DEV_MODE and WP_DEBUG are true.
		if ( true === EWPM_DEV_MODE && defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
			$this->sub_hooks['dev'] = add_submenu_page(
				'ewpm-export',
				__( 'Dev Tools', 'easy-wp-migration' ),
				__( 'Dev Tools', 'easy-wp-migration' ),
				'manage_options',
				'ewpm-dev',
				[ $this, 'render_dev_page' ]
			);
		}
	}

	/**
	 * Enqueue admin CSS and JS only on our plugin pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! ewpm_is_plugin_page( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'ewpm-admin',
			EWPM_PLUGIN_URL . 'assets/admin.css',
			[],
			EWPM_VERSION
		);

		wp_enqueue_script(
			'ewpm-admin',
			EWPM_PLUGIN_URL . 'assets/admin.js',
			[],
			EWPM_VERSION,
			true
		);

		wp_localize_script( 'ewpm-admin', 'ewpmData', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'ewpm_job' ),
			'tickBudget' => EWPM_TICK_BUDGET_SECONDS,
		] );
	}

	/**
	 * Render the Export admin page.
	 */
	public function render_export_page(): void {
		$this->render_template( 'page-export' );
	}

	/**
	 * Render the Import admin page.
	 */
	public function render_import_page(): void {
		$this->render_template( 'page-import' );
	}

	/**
	 * Render the Backups admin page.
	 */
	public function render_backups_page(): void {
		$this->render_template( 'page-backups' );
	}

	/**
	 * Render the Dev Tools admin page.
	 */
	public function render_dev_page(): void {
		$this->render_template( 'page-dev' );
	}

	/**
	 * Load a template file from the templates/ directory.
	 *
	 * @param string $template Template name without .php extension.
	 */
	private function render_template( string $template ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'easy-wp-migration' ) );
		}

		$file = EWPM_PLUGIN_DIR . 'templates/' . $template . '.php';

		if ( ! file_exists( $file ) ) {
			wp_die(
				sprintf(
					/* translators: %s: template file path */
					esc_html__( 'Easy WP Migration: template file not found: %s', 'easy-wp-migration' ),
					esc_html( $file )
				)
			);
		}

		include $file;
	}

	/**
	 * Get all registered plugin hook suffixes.
	 *
	 * @return string[]
	 */
	public function get_hook_suffixes(): array {
		return array_values( $this->sub_hooks );
	}
}
