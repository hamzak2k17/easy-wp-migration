<?php
/**
 * Migration token management.
 *
 * Generates and validates HMAC-signed, time-limited tokens for public
 * file serving. Tokens bind to a specific backup filename and expire
 * after a configurable TTL. Revocation is tracked via a registry in
 * wp_options.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Migration_Tokens
 *
 * HMAC-signed migration link token management.
 */
class EWPM_Migration_Tokens {

	/**
	 * wp_options key for the HMAC secret.
	 */
	private const SECRET_OPTION = 'ewpm_migration_secret';

	/**
	 * wp_options key for the active links registry.
	 */
	private const REGISTRY_OPTION = 'ewpm_migration_links';

	/**
	 * Get the site HMAC secret. Auto-generates on first call.
	 *
	 * @return string 64-character hex string (256-bit key).
	 */
	public function get_secret(): string {
		$secret = get_option( self::SECRET_OPTION, '' );

		if ( empty( $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Regenerate the HMAC secret, instantly invalidating all existing links.
	 *
	 * @return string The new secret.
	 */
	public function regenerate_secret(): string {
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	/**
	 * Generate a new migration token for a backup file.
	 *
	 * @param string $filename    Backup filename (basename only).
	 * @param int    $ttl_seconds Time-to-live in seconds.
	 * @return array{token: string, tid: string, expires_at: int, url_pretty: string, url_fallback: string}
	 * @throws EWPM_Migration_Tokens_Exception If filename is invalid.
	 */
	public function generate( string $filename, int $ttl_seconds ): array {
		$filename = sanitize_file_name( $filename );

		if ( empty( $filename ) ) {
			throw new EWPM_Migration_Tokens_Exception(
				__( 'Invalid filename.', 'easy-wp-migration' )
			);
		}

		// Verify file exists in backups/.
		$path = ewpm_get_backups_dir() . $filename;

		if ( ! file_exists( $path ) ) {
			throw new EWPM_Migration_Tokens_Exception(
				__( 'Backup file not found.', 'easy-wp-migration' )
			);
		}

		$tid        = 'link_' . bin2hex( random_bytes( 6 ) );
		$expires_at = time() + max( 60, $ttl_seconds );

		$payload = [
			'v'    => 1,
			'tid'  => $tid,
			'file' => $filename,
			'exp'  => $expires_at,
		];

		$payload_b64 = $this->base64url_encode( wp_json_encode( $payload ) );
		$signature   = hash_hmac( 'sha256', $payload_b64, $this->get_secret() );
		$token       = $payload_b64 . '.' . $signature;

		// Register in the active links log.
		$registry   = $this->get_registry();
		$registry[] = [
			'tid'              => $tid,
			'filename'         => $filename,
			'created_at'       => time(),
			'expires_at'       => $expires_at,
			'revoked'          => false,
			'revoked_at'       => null,
			'access_count'     => 0,
			'last_accessed_at' => null,
			'last_access_ip'   => null,
		];

		update_option( self::REGISTRY_OPTION, wp_json_encode( $registry ), false );

		$url_pretty   = home_url( '/ewpm-migrate/' . urlencode( $token ) . '/' );
		$url_fallback = add_query_arg( 'ewpm_migrate', urlencode( $token ), home_url( '/' ) );

		return [
			'token'        => $token,
			'tid'          => $tid,
			'expires_at'   => $expires_at,
			'url_pretty'   => $url_pretty,
			'url_fallback' => $url_fallback,
		];
	}

	/**
	 * Validate a migration token.
	 *
	 * Uses hash_equals() for timing-safe signature comparison.
	 *
	 * @param string $token The full token string (payload.signature).
	 * @return array{valid: bool, tid: string|null, filename: string|null, error: string|null}
	 */
	public function validate( string $token ): array {
		$fail = fn( string $reason ) => [ 'valid' => false, 'tid' => null, 'filename' => null, 'error' => $reason ];

		// Split token.
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) ) {
			return $fail( 'Malformed token: missing separator' );
		}

		[ $payload_b64, $signature ] = $parts;

		// Verify signature using hash_equals (timing-safe).
		$expected_sig = hash_hmac( 'sha256', $payload_b64, $this->get_secret() );

		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return $fail( 'Invalid signature' );
		}

		// Decode payload.
		$json = $this->base64url_decode( $payload_b64 );
		$payload = json_decode( $json, true );

		if ( ! is_array( $payload ) ) {
			return $fail( 'Malformed payload' );
		}

		// Check format version.
		if ( ( $payload['v'] ?? 0 ) !== 1 ) {
			return $fail( 'Unsupported token version' );
		}

		$tid      = $payload['tid'] ?? '';
		$filename = $payload['file'] ?? '';
		$exp      = (int) ( $payload['exp'] ?? 0 );

		// Check expiry.
		if ( $exp < time() ) {
			return $fail( 'Token expired' );
		}

		// Check registry for revocation.
		$registry = $this->get_registry();

		foreach ( $registry as $entry ) {
			if ( $entry['tid'] === $tid && ! empty( $entry['revoked'] ) ) {
				return $fail( 'Token revoked' );
			}
		}

		// Check file exists.
		$path = ewpm_get_backups_dir() . $filename;

		if ( ! file_exists( $path ) ) {
			return $fail( 'Backup file not found' );
		}

		return [
			'valid'    => true,
			'tid'      => $tid,
			'filename' => $filename,
			'error'    => null,
		];
	}

	/**
	 * Revoke a single token by its TID.
	 *
	 * @param string $tid The token ID.
	 * @return bool True if found and revoked.
	 */
	public function revoke( string $tid ): bool {
		$registry = $this->get_registry();
		$found    = false;

		foreach ( $registry as &$entry ) {
			if ( $entry['tid'] === $tid && empty( $entry['revoked'] ) ) {
				$entry['revoked']    = true;
				$entry['revoked_at'] = time();
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( $found ) {
			update_option( self::REGISTRY_OPTION, wp_json_encode( $registry ), false );
		}

		return $found;
	}

	/**
	 * Revoke all links by regenerating the HMAC secret.
	 *
	 * @return int Number of previously active links invalidated.
	 */
	public function revoke_all(): int {
		$registry = $this->get_registry();
		$count    = 0;

		foreach ( $registry as $entry ) {
			if ( empty( $entry['revoked'] ) && ( $entry['expires_at'] ?? 0 ) >= time() ) {
				++$count;
			}
		}

		$this->regenerate_secret();
		return $count;
	}

	/**
	 * Record an access to a token (for tracking).
	 *
	 * @param string $tid       The token ID.
	 * @param string $client_ip The client's IP address.
	 */
	public function record_access( string $tid, string $client_ip ): void {
		$registry = $this->get_registry();

		foreach ( $registry as &$entry ) {
			if ( $entry['tid'] === $tid ) {
				$entry['access_count']     = ( $entry['access_count'] ?? 0 ) + 1;
				$entry['last_accessed_at'] = time();
				$entry['last_access_ip']   = $client_ip;
				break;
			}
		}
		unset( $entry );

		update_option( self::REGISTRY_OPTION, wp_json_encode( $registry ), false );
	}

	/**
	 * Get the full registry of migration links.
	 *
	 * @return array<int, array>
	 */
	public function get_registry(): array {
		$raw = get_option( self::REGISTRY_OPTION, '[]' );
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Prune registry entries older than N days past expiry.
	 *
	 * @param int $max_age_days Days past expiry to keep entries.
	 * @return int Number of entries pruned.
	 */
	public function prune_registry( int $max_age_days = 30 ): int {
		$registry = $this->get_registry();
		$cutoff   = time() - ( $max_age_days * 86400 );
		$before   = count( $registry );

		$registry = array_values( array_filter(
			$registry,
			fn( $e ) => ( $e['expires_at'] ?? 0 ) >= $cutoff
		) );

		$pruned = $before - count( $registry );

		if ( $pruned > 0 ) {
			update_option( self::REGISTRY_OPTION, wp_json_encode( $registry ), false );
		}

		return $pruned;
	}

	/**
	 * Base64url encode (URL-safe base64 without padding).
	 *
	 * @param string $data Data to encode.
	 * @return string Encoded string.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Encoded string.
	 * @return string Decoded data.
	 */
	private function base64url_decode( string $data ): string {
		return base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
