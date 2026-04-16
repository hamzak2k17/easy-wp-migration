<?php
/**
 * Archiver factory.
 *
 * Single point of instantiation for archiver implementations. Exporters
 * and importers always go through this factory — never `new` an archiver
 * directly. This is what allows us to swap from zip to a custom streaming
 * format in v2 without rewriting consumers.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Archiver_Factory
 *
 * Creates archiver instances by format name.
 */
class EWPM_Archiver_Factory {

	/**
	 * Create an archiver instance for the given format.
	 *
	 * @param string $format The archive format. Currently only 'zip' is supported.
	 * @return EWPM_Archiver_Interface A ready-to-use archiver instance.
	 * @throws EWPM_Archiver_Exception If the requested format is not supported.
	 */
	public static function create( string $format = 'zip' ): EWPM_Archiver_Interface {
		return match ( $format ) {
			'zip'   => new EWPM_Archiver_Zip(),
			default => throw new EWPM_Archiver_Exception(
				sprintf( 'Unsupported archive format: "%s". Supported formats: zip.', $format )
			),
		};
	}
}
