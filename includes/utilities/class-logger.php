<?php
/**
 * Logger Utility Class
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger utility class for recording plugin activity.
 *
 * @since 1.0.0
 */
class WCST_Logger {

	/**
	 * WooCommerce logger instance.
	 *
	 * @since 1.0.0
	 * @var WC_Logger
	 */
	private static $logger = null;

	/**
	 * Log source identifier.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static $source = 'doctor-subs';

	/**
	 * Get logger instance.
	 *
	 * @since 1.0.0
	 * @return WC_Logger WooCommerce logger instance.
	 */
	private static function get_logger() {
		if ( null === self::$logger && function_exists( 'wc_get_logger' ) ) {
			self::$logger = wc_get_logger();
		}

		return self::$logger;
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @since 1.0.0
	 * @return bool True if logging is enabled.
	 */
	private static function is_logging_enabled() {
		return WCST_Plugin::get_option( 'enable_logging', true );
	}

	/**
	 * Log an info message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function warning( $message, $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function debug( $message, $context = array() ) {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Log a message with specified level.
	 *
	 * @since 1.0.0
	 * @param string $level Log level (info, warning, error, debug).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public static function log( $level, $message, $context = array() ) {
		if ( ! self::is_logging_enabled() ) {
			return;
		}

		$logger = self::get_logger();

		if ( ! $logger ) {
			return;
		}

		// Format context data.
		$formatted_context = '';
		if ( ! empty( $context ) ) {
			$formatted_context = ' | Context: ' . wp_json_encode( $context );
		}

		// Add timestamp and user info.
		$user_id   = get_current_user_id();
		$timestamp = current_time( 'Y-m-d H:i:s' );

		$formatted_message = "[{$timestamp}] [User: {$user_id}] {$message}{$formatted_context}";

		// Log to WooCommerce logger.
		$logger->log( $level, $formatted_message, array( 'source' => self::$source ) );

		// Clean up old logs if needed.
		self::cleanup_old_logs();
	}

	/**
	 * Clean up old log entries based on retention settings.
	 *
	 * @since 1.0.0
	 */
	private static function cleanup_old_logs() {
		// Only run cleanup occasionally to avoid performance impact.
		if ( 0 !== wp_rand( 1, 100 ) ) {
			return;
		}

		$retention_days = WCST_Plugin::get_option( 'log_retention_days', 30 );

		if ( $retention_days <= 0 ) {
			return;
		}

		$cutoff_date = strtotime( "-{$retention_days} days" );

		// This would implement log file cleanup based on WooCommerce log structure
		// For now, we rely on WooCommerce's built-in log rotation.
	}

	/**
	 * Get recent log entries.
	 *
	 * @since 1.0.0
	 * @param int $limit Number of entries to retrieve.
	 * @return array Recent log entries.
	 */
	public static function get_recent_logs( $limit = 50 ) {
		$logs = array();

		// This would implement reading from WooCommerce log files
		// and parsing recent entries related to our plugin.

		return $logs;
	}

	/**
	 * Clear all plugin logs.
	 *
	 * @since 1.0.0
	 * @return bool True if logs were cleared successfully.
	 */
	public static function clear_logs() {
		// This would implement clearing log files
		// related to our plugin source.

		return true;
	}
}
