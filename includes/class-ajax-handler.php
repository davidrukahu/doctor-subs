<?php
declare( strict_types=1 );
/**
 * AJAX Request Handler
 *
 * @package Dr_Subs
 */

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
	 */
	public function analyze_subscription() {
		try {
			// Security checks.
			WCST_Security::verify_nonce( $_POST['nonce'] ?? '', 'wcst_nonce' );
			WCST_Security::check_permissions( 'manage_woocommerce' );

			// Validate and sanitize input.
			$subscription_id = WCST_Security::validate_subscription_id( $_POST['subscription_id'] ?? '' );

					// Initialize analyzers.
			$anatomy_analyzer       = new WCST_Subscription_Anatomy();
			$expected_analyzer      = new WCST_Expected_Behavior();
			$timeline_builder       = new WCST_Timeline_Builder();
			$skipped_cycle_detector = new WCST_Skipped_Cycle_Detector();

			// Step 1: Analyze anatomy.
			$anatomy_data = $anatomy_analyzer->analyze( $subscription_id );

			// Step 2: Determine expected behavior.
			$expected_data = $expected_analyzer->analyze( $subscription_id );

			// Step 3: Build timeline.
			$timeline_data = $timeline_builder->build( $subscription_id );

			// Enhanced Detection: Analyze skipped cycles and issues.
			$enhanced_data = $skipped_cycle_detector->analyze( $subscription_id );

			// Create summary with findings.
			$summary_data = $this->create_summary( $anatomy_data, $expected_data, $timeline_data, $enhanced_data );

			// Prepare response data.
			$response_data = array(
				'subscription_id' => $subscription_id,
				'anatomy'         => $anatomy_data,
				'expected'        => $expected_data,
				'timeline'        => $timeline_data,
				'enhanced'        => $enhanced_data,
				'summary'         => $summary_data,
				'timestamp'       => current_time( 'Y-m-d H:i:s' ),
			);

			wp_send_json_success( $response_data );

		} catch ( Exception $e ) {
			WCST_Logger::log( 'error', 'Subscription analysis failed: ' . $e->getMessage() );
			wp_send_json_error( $e->getMessage() );
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
			WCST_Security::verify_nonce( $_POST['nonce'] ?? '', 'wcst_nonce' );
			WCST_Security::check_permissions( 'manage_woocommerce' );

			// Validate and sanitize input.
			$search_term = sanitize_text_field( $_POST['search_term'] ?? '' );

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
	 * @return array Summary data.
	 */
	private function create_summary( $anatomy_data, $expected_data, $timeline_data, $enhanced_data = array() ) {
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
							return 'critical' === $issue['severity']; }
					)
				),
				'warnings'     => count(
					array_filter(
						$issues,
						function ( $issue ) {
							return 'warning' === $issue['severity']; }
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
