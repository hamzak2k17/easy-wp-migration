<?php
/**
 * Archiver exception class.
 *
 * Provides a type-specific exception so callers can distinguish archive
 * errors from other exceptions when catching.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Archiver_Exception
 *
 * Thrown by archiver implementations for format, I/O, or validation errors.
 */
class EWPM_Archiver_Exception extends \Exception {
}
