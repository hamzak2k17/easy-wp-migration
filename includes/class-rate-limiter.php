<?php
/**
 * Simple rate limiter using transients.
 *
 * Provides sliding-window request throttling per key. Used to defend
 * the migration endpoint against brute-force and DoS.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Rate_Limiter
 *
 * Transient-based sliding window rate limiter.
 */
class EWPM_Rate_Limiter {

	/**
	 * Check if a key is under the rate limit.
	 *
	 * @param string $key            Unique key for rate limiting.
	 * @param int    $max_requests   Maximum requests allowed in the window.
	 * @param int    $window_seconds Window duration in seconds.
	 * @return bool True if under limit, false if over.
	 */
	public static function check( string $key, int $max_requests, int $window_seconds ): bool {
		$transient_key = 'ewpm_rl_' . md5( $key );
		$count         = (int) get_transient( $transient_key );

		return $count < $max_requests;
	}

	/**
	 * Record a request against a key.
	 *
	 * @param string $key            Unique key for rate limiting.
	 * @param int    $window_seconds Window duration in seconds.
	 */
	public static function record( string $key, int $window_seconds ): void {
		$transient_key = 'ewpm_rl_' . md5( $key );
		$count         = (int) get_transient( $transient_key );

		set_transient( $transient_key, $count + 1, $window_seconds );
	}
}
