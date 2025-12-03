<?php
/**
 * AJAX Request Handler
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler class for processing troubleshooting requests.
 *
 * @since 1.0.0
 */
class WCST_Ajax_Handler {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Analysis actions.
		add_action( 'wp_ajax_wcst_analyze_subscription', array( $this, 'analyze_subscription' ) );
		add_action( 'wp_ajax_wcst_search_subscriptions', array( $this, 'search_subscriptions' ) );
	}

	/**
	 * Perform complete subscription analysis.
	 *
	 * @since 1.0.0
	 * @throws Exception If analysis fails.
	 */
	public function analyze_subscription() {
		try {
			// Check if required classes exist.
			if ( ! class_exists( 'WCST_Security' ) ) {
				throw new Exception( 'WCST_Security class not found' );
			}
			if ( ! class_exists( 'WCST_Logger' ) ) {
				throw new Exception( 'WCST_Logger class not found' );
			}

			// Security checks.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash() and sanitize_text_field() are applied below.
			WCST_Security::verify_nonce( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', 'wcst_nonce' );
			WCST_Security::check_permissions( 'manage_woocommerce' );

			// Validate and sanitize input.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_unslash() and sanitize_text_field() are applied below.
			$raw_subscription_id = isset( $_POST['subscription_id'] ) ? wp_unslash( $_POST['subscription_id'] ) : '';
			// phpcs:enable

			// Log raw input for debugging (only in debug mode).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Only used when WP_DEBUG and WP_DEBUG_LOG are enabled.
				WCST_Logger::log( 'debug', 'Raw subscription ID received: ' . print_r( $raw_subscription_id, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() and absint() handle sanitization.
			$subscription_id = WCST_Security::validate_subscription_id( sanitize_text_field( $raw_subscription_id ) );

			// Verify subscription exists.
			if ( ! function_exists( 'wcs_get_subscription' ) ) {
				throw new Exception( 'WooCommerce Subscriptions is not active or not loaded.' );
			}

			$subscription = wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				throw new Exception( sprintf( 'Subscription #%d not found.', $subscription_id ) );
			}

			// Keep logs minimal: only errors elsewhere.

			// Initialize analyzers with error checking.
			if ( ! class_exists( 'WCST_Subscription_Anatomy' ) ) {
				throw new Exception( 'WCST_Subscription_Anatomy class not found' );
			}
			$anatomy_analyzer = new WCST_Subscription_Anatomy();

			if ( ! class_exists( 'WCST_Expected_Behavior' ) ) {
				throw new Exception( 'WCST_Expected_Behavior class not found' );
			}
			$expected_analyzer = new WCST_Expected_Behavior();

			if ( ! class_exists( 'WCST_Timeline_Builder' ) ) {
				throw new Exception( 'WCST_Timeline_Builder class not found' );
			}
			$timeline_builder = new WCST_Timeline_Builder();

			if ( ! class_exists( 'WCST_Skipped_Cycle_Detector' ) ) {
				throw new Exception( 'WCST_Skipped_Cycle_Detector class not found' );
			}
			$skipped_cycle_detector = new WCST_Skipped_Cycle_Detector();

			if ( ! class_exists( 'WCST_Discrepancy_Detector' ) ) {
				throw new Exception( 'WCST_Discrepancy_Detector class not found' );
			}
			$discrepancy_detector = new WCST_Discrepancy_Detector();

			// Step 1: Analyze anatomy.
			// Anatomy analysis.
			try {
				$anatomy_data = $anatomy_analyzer->analyze( $subscription_id );
			} catch ( \Throwable $e ) {
				throw new Exception( 'Step 1 (Anatomy) failed: ' . $e->getMessage(), 0, $e );
			}

			// Step 2: Determine expected behavior.
			// Expected behavior analysis.
			try {
				$expected_data = $expected_analyzer->analyze( $subscription_id );
			} catch ( \Throwable $e ) {
				throw new Exception( 'Step 2 (Expected Behavior) failed: ' . $e->getMessage(), 0, $e );
			}

			// Step 3: Build timeline.
			// Timeline analysis.
			try {
				$timeline_data = $timeline_builder->build( $subscription_id );
			} catch ( \Throwable $e ) {
				throw new Exception( 'Step 3 (Timeline) failed: ' . $e->getMessage(), 0, $e );
			}

			// Discrepancy Detection: Check for Stripe and other gateway issues.
			$discrepancy_data = array();
			try {
				$discrepancy_data = $discrepancy_detector->analyze_discrepancies( $subscription_id );
			} catch ( \Throwable $t ) {
				WCST_Logger::log( 'error', 'Discrepancy detection failed: ' . $t->getMessage() );
				// Keep empty discrepancy data on failure.
			}

			// Enhanced Detection: Analyze skipped cycles and issues.
			// Enhanced detection analysis.

			// Set a timeout for the enhanced analysis to prevent hanging.
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Necessary for long-running analysis operations.
			set_time_limit( 30 ); // 30 seconds max.

			// Default enhanced data structure.
			$enhanced_data = array(
				'skipped_cycles'     => array(),
				'manual_completions' => array(),
				'status_mismatches'  => array(),
				'action_scheduler'   => array(),
				'year_over_year'     => array(),
			);

			// Use the public analyzer entrypoint to avoid calling private methods.
			try {
				$enhanced_data = $skipped_cycle_detector->analyze( $subscription_id );
			} catch ( \Throwable $t ) {
				WCST_Logger::log( 'error', 'Enhanced detection failed: ' . $t->getMessage() );
				// Keep default empty enhanced data on failure.
			}

			// Create summary with findings.
			$summary_data = $this->create_summary( $anatomy_data, $expected_data, $timeline_data, $enhanced_data, $discrepancy_data );

			// Prepare response data.
			$response_data = array(
				'subscription_id' => $subscription_id,
				'anatomy'         => $anatomy_data,
				'expected'        => $expected_data,
				'timeline'        => $timeline_data,
				'enhanced'        => $enhanced_data,
				'discrepancies'   => $discrepancy_data,
				'summary'         => $summary_data,
				'timestamp'       => current_time( 'Y-m-d H:i:s' ),
			);

			wp_send_json_success( $response_data );

		} catch ( \Throwable $e ) {
			$error_message = $e->getMessage();
			$error_details = array(
				'message' => $error_message,
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
				'trace'   => $e->getTraceAsString(),
			);

			WCST_Logger::log( 'error', 'Subscription analysis failed: ' . $error_message, $error_details );
			WCST_Logger::log( 'error', 'Stack trace: ' . $e->getTraceAsString() );

			// Provide more helpful error message to user (sanitized).
			$user_message = 'Analysis failed. ';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Show detailed error in debug mode.
				$user_message .= esc_html( $error_message );
			} else {
				// Generic message for production.
				$user_message .= 'Please check WooCommerce logs (WooCommerce > Status > Logs > doctor-subs) for details.';
			}

			// Include error details in response for debugging.
			$error_response = array(
				'message' => $user_message,
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$error_response['debug'] = array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				);
			}

			wp_send_json_error( $error_response );
		}
	}

	/**
	 * Search for subscriptions.
	 *
	 * @since 1.0.0
	 */
	public function search_subscriptions() {
		try {
			// Security checks.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash() and sanitize_text_field() are applied below.
			WCST_Security::verify_nonce( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', 'wcst_nonce' );
			WCST_Security::check_permissions( 'manage_woocommerce' );

			// Validate and sanitize input.
			$search_term = isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : '';
			// phpcs:enable

			if ( strlen( $search_term ) < 2 ) {
				wp_send_json_success( array() );
			}

			// Initialize data collector.
			$data_collector = new WCST_Subscription_Data();

			// Search for subscriptions.
			$results = $data_collector->search_subscriptions( $search_term );

			wp_send_json_success( $results );

		} catch ( Exception $e ) {
			WCST_Logger::log( 'error', 'Subscription search failed: ' . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
		}
	}



	/**
	 * Create summary of analysis findings.
	 *
	 * @since 1.0.0
	 * @param array $anatomy_data  Anatomy analysis data.
	 * @param array $expected_data Expected behavior data.
	 * @param array $timeline_data Timeline data.
	 * @param array $enhanced_data Enhanced detection data.
	 * @param array $discrepancy_data Discrepancy detection data.
	 * @return array Summary data.
	 */
	private function create_summary( $anatomy_data, $expected_data, $timeline_data, $enhanced_data = array(), $discrepancy_data = array() ) {
		$issues     = array();
		$status     = 'healthy';
		$next_steps = array();

		// Analyze for common issues.

		// Check payment method issues.
		if ( isset( $anatomy_data['payment_method']['status'] ) && ! $anatomy_data['payment_method']['status']['is_valid'] ) {
			$issues[] = array(
				'severity'    => 'critical',
				'type'        => 'payment_method',
				'title'       => __( 'Payment Method Issue', 'doctor-subs' ),
				'description' => __( 'The payment method appears to have issues that may affect renewals.', 'doctor-subs' ),
			);
			$status   = 'issues_found';
		}

		// Check for failed scheduled actions.
		if ( isset( $anatomy_data['scheduled_actions']['failed'] ) && ! empty( $anatomy_data['scheduled_actions']['failed'] ) ) {
			$issues[] = array(
				'severity'    => 'warning',
				'type'        => 'scheduled_actions',
				'title'       => __( 'Failed Scheduled Actions', 'doctor-subs' ),
				'description' => sprintf(
					/* translators: %d: number of failed actions */
					__( '%d scheduled actions have failed. This may affect automatic renewals.', 'doctor-subs' ),
					count( $anatomy_data['scheduled_actions']['failed'] )
				),
			);
			if ( 'healthy' === $status ) {
				$status = 'warnings';
			}
		}

		// Check timeline for discrepancies.
		if ( isset( $timeline_data['discrepancies'] ) && ! empty( $timeline_data['discrepancies'] ) ) {
			foreach ( $timeline_data['discrepancies'] as $discrepancy ) {
				$issues[] = array(
					'severity'    => $discrepancy['severity'] ?? 'warning',
					'type'        => 'timeline_discrepancy',
					'title'       => $discrepancy['title'] ?? __( 'Timeline Discrepancy', 'doctor-subs' ),
					'description' => $discrepancy['description'] ?? '',
				);
			}
			$status = 'issues_found';
		}

		// Check enhanced detection for skipped cycles and issues.
		if ( ! empty( $enhanced_data ) ) {
			// Check for skipped cycles.
			if ( isset( $enhanced_data['skipped_cycles'] ) && ! empty( $enhanced_data['skipped_cycles'] ) ) {
				foreach ( $enhanced_data['skipped_cycles'] as $cycle ) {
					$issues[] = array(
						'severity'    => 'warning',
						'type'        => 'skipped_cycle',
						'title'       => __( 'Skipped Payment Cycle', 'doctor-subs' ),
						'description' => $cycle['description'] ?? __( 'A payment cycle was skipped.', 'doctor-subs' ),
					);
				}
				$status = 'issues_found';
			}

			// Check for manual completions.
			if ( isset( $enhanced_data['manual_completions'] ) && ! empty( $enhanced_data['manual_completions'] ) ) {
				foreach ( $enhanced_data['manual_completions'] as $completion ) {
					$issues[] = array(
						'severity'    => 'info',
						'type'        => 'manual_completion',
						'title'       => __( 'Manual Completion Detected', 'doctor-subs' ),
						'description' => $completion['description'] ?? __( 'Payment was completed manually.', 'doctor-subs' ),
					);
				}
			}

			// Check for status mismatches.
			if ( isset( $enhanced_data['status_mismatches'] ) && ! empty( $enhanced_data['status_mismatches'] ) ) {
				foreach ( $enhanced_data['status_mismatches'] as $mismatch ) {
					$issues[] = array(
						'severity'    => 'warning',
						'type'        => 'status_mismatch',
						'title'       => __( 'Status Mismatch', 'doctor-subs' ),
						'description' => $mismatch['description'] ?? __( 'Subscription status appears inconsistent.', 'doctor-subs' ),
					);
				}
				$status = 'issues_found';
			}
		}

		// Check discrepancy detector for Stripe and gateway issues.
		if ( ! empty( $discrepancy_data ) && is_array( $discrepancy_data ) ) {
			foreach ( $discrepancy_data as $discrepancy ) {
				$issues[] = array(
					'severity'    => $discrepancy['severity'] ?? 'warning',
					'type'        => $discrepancy['type'] ?? 'unknown',
					'title'       => $discrepancy['description'] ?? __( 'Issue Detected', 'doctor-subs' ),
					'description' => $discrepancy['recommendation'] ?? '',
					'details'     => $discrepancy['details'] ?? array(),
				);

				// Update status based on severity.
				$severity = $discrepancy['severity'] ?? 'warning';
				if ( in_array( $severity, array( 'critical', 'high' ), true ) ) {
					$status = 'issues_found';
				} elseif ( 'warning' === $severity && 'healthy' === $status ) {
					$status = 'warnings';
				}
			}
		}

		// Generate next steps based on findings.
		if ( empty( $issues ) ) {
			$next_steps[] = __( 'No issues detected. The subscription appears to be functioning normally.', 'doctor-subs' );
		} else {
			$next_steps[] = __( 'Review the identified issues above and take appropriate action.', 'doctor-subs' );
			$next_steps[] = __( 'Consider contacting WooCommerce support if issues persist.', 'doctor-subs' );
		}

		return array(
			'status'     => $status,
			'issues'     => $issues,
			'next_steps' => $next_steps,
			'statistics' => array(
				'total_issues' => count( $issues ),
				'critical'     => count(
					array_filter(
						$issues,
						function ( $issue ) {
							return in_array( $issue['severity'], array( 'critical', 'error', 'high' ), true );
						}
					)
				),
				'warnings'     => count(
					array_filter(
						$issues,
						function ( $issue ) {
							return in_array( $issue['severity'], array( 'warning', 'medium' ), true );
						}
					)
				),
			),
		);
	}





	/**
	 * Get cached analysis data for a subscription.
	 *
	 * @since 1.0.0
	 * @param int $subscription_id Subscription ID.
	 * @return array|false Analysis data or false if not found.
	 */
	private function get_cached_analysis( $subscription_id ) {
		// In a future version, this could retrieve cached analysis data.
		// For now, we'll re-run the analysis.
		return false;
	}
}
