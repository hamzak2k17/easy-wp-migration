<?php
/**
 * Public migration endpoint.
 *
 * Handles inbound requests to migration URLs. Validates HMAC-signed
 * tokens, rate-limits requests, and streams backup files with HTTP
 * Range support for resumable downloads.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Migration_Endpoint
 *
 * Registers rewrite rules and handles migration file serving.
 */
class EWPM_Migration_Endpoint {

	/**
	 * Initialize: register hooks for rewrite rules and request handling.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
		add_action( 'parse_request', [ __CLASS__, 'handle_request' ] );

		// AJAX fallback for hosts where front-end rewrite/query vars are
		// intercepted (e.g. InstaWP splash pages). Unauthenticated via nopriv.
		add_action( 'wp_ajax_nopriv_ewpm_migrate', [ __CLASS__, 'handle_ajax_migrate' ] );
		add_action( 'wp_ajax_ewpm_migrate', [ __CLASS__, 'handle_ajax_migrate' ] );

		// Flush rewrites on version change.
		self::maybe_flush_rewrites();
	}

	/**
	 * Register the pretty URL rewrite rule.
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^ewpm-migrate/([^/]+)/?$',
			'index.php?ewpm_migrate=$matches[1]',
			'top'
		);
	}

	/**
	 * Register ewpm_migrate as a recognized query variable.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[] Modified query vars.
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'ewpm_migrate';
		return $vars;
	}

	/**
	 * Handle migration requests.
	 *
	 * Checks for the ewpm_migrate query var, validates the token,
	 * and streams the file.
	 *
	 * @param \WP $wp The WordPress request object.
	 */
	public static function handle_request( \WP $wp ): void {
		if ( empty( $wp->query_vars['ewpm_migrate'] ) ) {
			return;
		}

		$token = urldecode( $wp->query_vars['ewpm_migrate'] );

		// Disable output buffering — we're streaming raw binary.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Rate limit check BEFORE token validation.
		$rl_key = 'mig_' . md5( $token );

		if ( ! EWPM_Rate_Limiter::check( $rl_key, 20, 60 ) ) {
			status_header( 429 );
			header( 'Retry-After: 60' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Too many requests.';
			exit;
		}

		EWPM_Rate_Limiter::record( $rl_key, 60 );

		// Validate token.
		$tokens = new EWPM_Migration_Tokens();
		$result = $tokens->validate( $token );

		if ( ! $result['valid'] ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Invalid or expired migration link.';
			exit;
		}

		// Resolve file path.
		$filename = $result['filename'];

		try {
			$backups = new EWPM_Backups();
			$path    = $backups->get_download_path( $filename );
		} catch ( \Throwable $e ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Backup file not found.';
			exit;
		}

		// Record access.
		$client_ip = self::get_client_ip();
		$tokens->record_access( $result['tid'], $client_ip );

		// Serve the file.
		self::serve_file( $path, $filename );
		exit;
	}

	/**
	 * Handle migration requests via admin-ajax.php (nopriv).
	 *
	 * Used as fallback when front-end query vars are intercepted by
	 * hosting platform middleware (e.g. InstaWP splash pages).
	 * URL format: admin-ajax.php?action=ewpm_migrate&token={token}
	 */
	public static function handle_ajax_migrate(): void {
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? $_POST['token'] ?? '' ) );

		if ( empty( $token ) ) {
			status_header( 400 );
			echo 'Missing token.';
			exit;
		}

		// Disable output buffering.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Rate limit.
		$rl_key = 'mig_' . md5( $token );

		if ( ! EWPM_Rate_Limiter::check( $rl_key, 20, 60 ) ) {
			status_header( 429 );
			header( 'Retry-After: 60' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Too many requests.';
			exit;
		}

		EWPM_Rate_Limiter::record( $rl_key, 60 );

		// Validate.
		$tokens = new EWPM_Migration_Tokens();
		$result = $tokens->validate( $token );

		if ( ! $result['valid'] ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Invalid or expired migration link.';
			exit;
		}

		// Resolve file.
		try {
			$backups = new EWPM_Backups();
			$path    = $backups->get_download_path( $result['filename'] );
		} catch ( \Throwable $e ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Backup file not found.';
			exit;
		}

		// Record access.
		$tokens->record_access( $result['tid'], self::get_client_ip() );

		// Serve.
		self::serve_file( $path, $result['filename'] );
		exit;
	}

	/**
	 * Serve a file with HTTP Range support.
	 *
	 * @param string $path     Absolute path to the file.
	 * @param string $filename Filename for Content-Disposition.
	 */
	private static function serve_file( string $path, string $filename ): void {
		$file_size = (int) filesize( $path );

		// Common headers.
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		// Prevent LiteSpeed/nginx from caching or intercepting Range.
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
		header( 'X-LiteSpeed-Purge: *' );
		header( 'X-LiteSpeed-Tag: ewpm_migrate' );
		header( 'X-Accel-Buffering: no' );

		// Parse Range header.
		$range_header = $_SERVER['HTTP_RANGE'] ?? '';

		if ( ! empty( $range_header ) && preg_match( '/^bytes=(\d*)-(\d*)$/', $range_header, $m ) ) {
			$start = '' !== $m[1] ? (int) $m[1] : 0;
			$end   = '' !== $m[2] ? (int) $m[2] : $file_size - 1;

			// Clamp end to file boundary (RFC 7233: if end >= size, use size-1).
			if ( $end >= $file_size ) {
				$end = $file_size - 1;
			}

			// Validate range.
			if ( $start > $end || $start >= $file_size ) {
				status_header( 416 ); // Range Not Satisfiable.
				header( "Content-Range: bytes */{$file_size}" );
				exit;
			}

			$length = $end - $start + 1;

			status_header( 206 );
			header( "Content-Range: bytes {$start}-{$end}/{$file_size}" );
			header( "Content-Length: {$length}" );

			self::stream_range( $path, $start, $length );
		} else {
			// Full file response.
			status_header( 200 );
			header( "Content-Length: {$file_size}" );
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		}
	}

	/**
	 * Stream a byte range from a file.
	 *
	 * @param string $path   File path.
	 * @param int    $start  Start byte offset.
	 * @param int    $length Number of bytes to send.
	 */
	private static function stream_range( string $path, int $start, int $length ): void {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fh ) {
			status_header( 500 );
			echo 'Internal server error.';
			return;
		}

		fseek( $fh, $start );

		$chunk_size = 8192;
		$sent       = 0;

		while ( $sent < $length && ! feof( $fh ) ) {
			$read_size = min( $chunk_size, $length - $sent );
			$data      = fread( $fh, $read_size );

			if ( false === $data ) {
				break;
			}

			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
			$sent += strlen( $data );
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Get the client's IP address.
	 *
	 * @return string Client IP.
	 */
	private static function get_client_ip(): string {
		$headers = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				return trim( $ip[0] );
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Flush rewrite rules if plugin version has changed.
	 */
	private static function maybe_flush_rewrites(): void {
		$stored = get_option( 'ewpm_rewrite_version', '' );

		if ( $stored !== EWPM_VERSION ) {
			add_action( 'wp_loaded', function () {
				flush_rewrite_rules( false );
				update_option( 'ewpm_rewrite_version', EWPM_VERSION, false );
			} );
		}
	}
}
