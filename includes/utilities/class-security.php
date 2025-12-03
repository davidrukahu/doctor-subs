<?php
/**
 * Security Utility Class
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security utility class for input validation and sanitization.
 *
 * @since 1.0.0
 */
class WCST_Security {

	/**
	 * Verify nonce for AJAX requests.
	 *
	 * @since 1.0.0
	 * @param string $nonce Nonce value to verify.
	 * @param string $action Nonce action name.
	 * @throws Exception If nonce verification fails.
	 */
	public static function verify_nonce( $nonce, $action ) {
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			throw new Exception( esc_html__( 'Security check failed. Please refresh the page and try again.', 'doctor-subs' ) );
		}
	}

	/**
	 * Check user permissions.
	 *
	 * @since 1.0.0
	 * @param string $capability Required capability.
	 * @throws Exception If user doesn't have required permission.
	 */
	public static function check_permissions( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			throw new Exception( esc_html__( 'You do not have permission to perform this action.', 'doctor-subs' ) );
		}
	}

	/**
	 * Validate subscription ID.
	 *
	 * @since 1.0.0
	 * @param mixed $subscription_id Subscription ID to validate.
	 * @return int Valid subscription ID.
	 * @throws Exception If subscription ID is invalid.
	 */
	public static function validate_subscription_id( $subscription_id ) {
		$id = absint( $subscription_id );

		if ( 0 === $id ) {
			throw new Exception( esc_html__( 'Invalid subscription ID provided.', 'doctor-subs' ) );
		}

		return $id;
	}

	/**
	 * Sanitize text input.
	 *
	 * @since 1.0.0
	 * @param string $input Text input to sanitize.
	 * @return string Sanitized text.
	 */
	public static function sanitize_text( $input ) {
		return sanitize_text_field( $input );
	}

	/**
	 * Escape output for display.
	 *
	 * @since 1.0.0
	 * @param string $output Output to escape.
	 * @return string Escaped output.
	 */
	public static function escape_html( $output ) {
		return esc_html( $output );
	}

	/**
	 * Check rate limiting for actions.
	 *
	 * @since 1.0.0
	 * @param string $action Action being performed.
	 * @param int    $limit Number of requests allowed per time period.
	 * @param int    $time_window Time window in seconds (default: 60).
	 * @throws Exception If rate limit is exceeded.
	 */
	public static function check_rate_limit( $action, $limit = 10, $time_window = 60 ) {
		$user_id   = get_current_user_id();
		$cache_key = "wcst_rate_limit_{$action}_{$user_id}";

		$current_count = get_transient( $cache_key );

		if ( false === $current_count ) {
			set_transient( $cache_key, 1, $time_window );
		} else {
			$current_count = (int) $current_count;

			if ( $current_count >= $limit ) {
				throw new Exception(
					esc_html(
						sprintf(
						/* translators: 1: action name, 2: time window in seconds */
							__( 'Rate limit exceeded for %1$s. Please wait %2$d seconds before trying again.', 'doctor-subs' ),
							$action,
							$time_window
						)
					)
				);
			}

			set_transient( $cache_key, $current_count + 1, $time_window );
		}
	}

	/**
	 * Verify HMAC signature for webhook.
	 *
	 * @since 1.3.0
	 * @param string $signature Signature to verify.
	 * @param array  $payload   Payload data.
	 * @param string $secret    Secret key for HMAC.
	 * @return bool True if signature is valid, false otherwise.
	 */
	public static function verify_webhook_signature( $signature, $payload, $secret ) {
		if ( empty( $signature ) || empty( $secret ) ) {
			return false;
		}

		$message  = wp_json_encode( $payload );
		$expected = hash_hmac( 'sha256', $message, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Validate timestamp to prevent replay attacks.
	 *
	 * @since 1.3.0
	 * @param int $timestamp Timestamp to validate.
	 * @param int $window    Time window in seconds (default: 300 = 5 minutes).
	 * @return bool True if timestamp is valid, false otherwise.
	 */
	public static function validate_timestamp( $timestamp, $window = 300 ) {
		$current_time = time();
		$diff         = abs( $current_time - $timestamp );

		return $diff <= $window;
	}
}
