<?php
/**
 * Serialization-aware search-replace.
 *
 * Safely replaces strings in data that may contain PHP-serialized strings.
 * Correctly updates the s:N:"..." byte-length prefix when replacement
 * changes string length. Handles nested serialized data, objects, and
 * multibyte strings.
 *
 * @package EasyWPMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class EWPM_Serializer_Fix
 *
 * Static utility for serialization-safe string replacement.
 */
class EWPM_Serializer_Fix {

	/**
	 * Replace strings in data that may contain PHP-serialized values.
	 *
	 * Processes serialized s:N:"..." patterns and updates byte-lengths.
	 * Falls back to plain str_replace for non-serialized content.
	 *
	 * @param string              $haystack     The data to search in.
	 * @param array<string,string> $replacements Old => new pairs.
	 * @return string The data with replacements applied.
	 */
	public static function replace( string $haystack, array $replacements ): string {
		if ( empty( $replacements ) || '' === $haystack ) {
			return $haystack;
		}

		// Check if the string contains any serialized data markers.
		if ( self::looks_serialized( $haystack ) ) {
			return self::replace_in_serialized( $haystack, $replacements );
		}

		// Plain string — direct replacement.
		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$haystack
		);
	}

	/**
	 * Check if a string looks like it contains PHP serialized data.
	 *
	 * @param string $data The data to check.
	 * @return bool True if serialized patterns are detected.
	 */
	public static function looks_serialized( string $data ): bool {
		return (bool) preg_match( '/(?:^|[;{])s:\d+:"/', $data );
	}

	/**
	 * Replace strings within serialized data, updating byte-lengths.
	 *
	 * Uses a regex-based approach to find s:N:"..." tokens and update
	 * the length prefix after replacement.
	 *
	 * @param string              $data         The serialized data.
	 * @param array<string,string> $replacements Old => new pairs.
	 * @return string Updated serialized data.
	 */
	private static function replace_in_serialized( string $data, array $replacements ): string {
		// Process s:N:"..." patterns from right to left to preserve offsets.
		$result = self::process_serialized_strings( $data, $replacements );

		return $result;
	}

	/**
	 * Find and process all serialized string tokens (s:N:"...").
	 *
	 * Works by scanning for s:\d+:" patterns, extracting the string value
	 * using the declared byte-length, performing replacements, then
	 * rewriting the token with the updated length.
	 *
	 * @param string              $data         Full serialized data string.
	 * @param array<string,string> $replacements Old => new pairs.
	 * @return string Updated data.
	 */
	private static function process_serialized_strings( string $data, array $replacements ): string {
		$offset = 0;
		$length = strlen( $data );
		$result = '';

		while ( $offset < $length ) {
			// Find next s:N:" pattern.
			$match_pos = self::find_serialized_string( $data, $offset );

			if ( false === $match_pos ) {
				// No more serialized strings — append rest and do plain replace.
				$remainder = substr( $data, $offset );
				$result   .= str_replace(
					array_keys( $replacements ),
					array_values( $replacements ),
					$remainder
				);
				break;
			}

			// Append everything before this match (with plain replacement).
			if ( $match_pos > $offset ) {
				$before  = substr( $data, $offset, $match_pos - $offset );
				$result .= str_replace(
					array_keys( $replacements ),
					array_values( $replacements ),
					$before
				);
			}

			// Parse the s:N:"..." token.
			$parsed = self::parse_serialized_string( $data, $match_pos );

			if ( null === $parsed ) {
				// Can't parse — copy character and advance.
				$result .= $data[ $match_pos ];
				$offset  = $match_pos + 1;
				continue;
			}

			// Apply replacements to the extracted string value.
			$old_value = $parsed['value'];
			$new_value = $old_value;

			// Recursively process if the value itself contains serialized data.
			if ( self::looks_serialized( $old_value ) ) {
				$new_value = self::process_serialized_strings( $old_value, $replacements );
			} else {
				$new_value = str_replace(
					array_keys( $replacements ),
					array_values( $replacements ),
					$old_value
				);
			}

			// Rewrite with correct byte-length.
			$new_byte_len = strlen( $new_value );
			$result      .= 's:' . $new_byte_len . ':"' . $new_value . '";';

			$offset = $parsed['end_offset'];
		}

		return $result;
	}

	/**
	 * Find the next s:\d+:" pattern starting from an offset.
	 *
	 * @param string $data   The data to search.
	 * @param int    $offset Starting byte offset.
	 * @return int|false Position of the 's' character, or false if not found.
	 */
	private static function find_serialized_string( string $data, int $offset ): int|false {
		$pos = $offset;

		while ( true ) {
			$pos = strpos( $data, 's:', $pos );

			if ( false === $pos ) {
				return false;
			}

			// Verify this is a valid s:N:" pattern — the character before must be
			// start of string, or one of: ; { (serialized delimiters).
			if ( $pos > 0 ) {
				$prev = $data[ $pos - 1 ];

				if ( ';' !== $prev && '{' !== $prev && "\n" !== $prev && "\r" !== $prev ) {
					// Could be part of a value like "keys:value" — skip.
					// But only skip if what follows s: is not a digit.
					if ( $pos + 2 < strlen( $data ) && ctype_digit( $data[ $pos + 2 ] ) ) {
						// Check if it looks like s:N:"
						if ( preg_match( '/^s:(\d+):"/', substr( $data, $pos, 20 ) ) ) {
							// Accept it — some contexts don't have delimiters before.
						} else {
							++$pos;
							continue;
						}
					} else {
						++$pos;
						continue;
					}
				}
			}

			// Verify s:\d+:" pattern.
			if ( preg_match( '/^s:(\d+):"/', substr( $data, $pos, 30 ) ) ) {
				return $pos;
			}

			++$pos;
		}
	}

	/**
	 * Parse a serialized string token starting at the given position.
	 *
	 * Reads s:N:"...(N bytes)..."; and returns the extracted value and
	 * the byte offset after the closing ";.
	 *
	 * @param string $data The full data string.
	 * @param int    $pos  Position of 's' in 's:N:"...'.
	 * @return array{value: string, end_offset: int}|null Parsed result or null on failure.
	 */
	private static function parse_serialized_string( string $data, int $pos ): ?array {
		// Match s:N:"
		if ( ! preg_match( '/^s:(\d+):"/', substr( $data, $pos, 30 ), $m ) ) {
			return null;
		}

		$byte_len     = (int) $m[1];
		$header_len   = strlen( $m[0] ); // Length of 's:N:"'
		$value_start  = $pos + $header_len;
		$data_len     = strlen( $data );

		// Verify we have enough bytes.
		if ( $value_start + $byte_len + 2 > $data_len ) {
			return null; // Not enough data.
		}

		$value = substr( $data, $value_start, $byte_len );

		// Verify closing ";
		$close_pos = $value_start + $byte_len;

		if ( '"' !== ( $data[ $close_pos ] ?? '' ) || ';' !== ( $data[ $close_pos + 1 ] ?? '' ) ) {
			return null; // Malformed — no closing ";
		}

		return [
			'value'      => $value,
			'end_offset' => $close_pos + 2, // After ";
		];
	}
}
