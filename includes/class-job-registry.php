<?php
/**
 * Job registry.
 *
 * Maps job type strings to their concrete EWPM_Job subclasses. This is
 * the single lookup table used by the AJAX layer to resolve which job
 * class handles a given type.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Job_Registry
 *
 * Singleton registry of job type → class mappings.
 */
class EWPM_Job_Registry {

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	/**
	 * Registered job types.
	 *
	 * @var array<string, string> Type string → fully-qualified class name.
	 */
	private array $types = [];

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
	}

	/**
	 * Register a job type.
	 *
	 * @param string $type       The type identifier (e.g. "export", "dummy").
	 * @param string $class_name Fully-qualified class name extending EWPM_Job.
	 * @throws \InvalidArgumentException If the class does not extend EWPM_Job.
	 */
	public function register( string $type, string $class_name ): void {
		if ( ! is_subclass_of( $class_name, EWPM_Job::class ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Job class "%s" must extend EWPM_Job.', $class_name )
			);
		}

		$this->types[ $type ] = $class_name;
	}

	/**
	 * Get a new instance of the job class for a given type.
	 *
	 * @param string $type The job type identifier.
	 * @return EWPM_Job A fresh job instance.
	 * @throws EWPM_State_Exception If the type is not registered.
	 */
	public function get( string $type ): EWPM_Job {
		if ( ! isset( $this->types[ $type ] ) ) {
			throw new EWPM_State_Exception(
				sprintf( 'Unknown job type: "%s". Registered types: %s.', $type, implode( ', ', array_keys( $this->types ) ) )
			);
		}

		$class = $this->types[ $type ];
		return new $class();
	}

	/**
	 * Get all registered type identifiers.
	 *
	 * @return string[]
	 */
	public function get_registered_types(): array {
		return array_keys( $this->types );
	}
}
