<?php
/**
 * Skipped Cycle Detector
 *
 * Detects when subscription payments have skipped expected billing cycles.
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes subscriptions for skipped payment cycles.
 *
 * @since 1.0.0
 */
class WCST_Skipped_Cycle_Detector {

	/**
	 * Safely get timestamp from date that might be a DateTime object or string (HPOS compatibility).
	 *
	 * @since 1.0.0
	 * @param mixed $date Date object or string.
	 * @return int|false Timestamp or false on failure.
	 */
	private function safe_get_timestamp( $date ) {
		if ( empty( $date ) ) {
			return false;
		}

		if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
			return $date->getTimestamp();
		}

		if ( is_string( $date ) ) {
			return strtotime( $date );
		}

		return false;
	}

	/**
	 * Analyze subscription for skipped cycles.
	 *
	 * @since 1.0.0
	 * @param int $subscription_id Subscription ID to analyze.
	 * @return array Skipped cycle analysis results.
	 * @throws Exception If subscription is not found or analysis fails.
	 */
	public function analyze( $subscription_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			throw new Exception( esc_html__( 'WooCommerce Subscriptions is not active or properly loaded.', 'doctor-subs' ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			throw new Exception( esc_html__( 'Subscription not found.', 'doctor-subs' ) );
		}

		return array(
			'skipped_cycles'     => $this->detect_skipped_cycles( $subscription ),
			'manual_completions' => $this->detect_manual_completions( $subscription ),
			'status_mismatches'  => $this->detect_status_mismatches( $subscription ),
			'action_scheduler'   => $this->audit_action_scheduler( $subscription ),
			'year_over_year'     => $this->year_over_year_analysis( $subscription ),
		);
	}

	/**
	 * Detect skipped payment cycles.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Skipped cycle details.
	 */
	private function detect_skipped_cycles( $subscription ) {
		$skipped_cycles = array();

		$billing_period   = $subscription->get_billing_period();
		$billing_interval = $subscription->get_billing_interval();
		$start_date       = $subscription->get_date( 'start' );
		$next_payment     = $subscription->get_date( 'next_payment' );

		if ( ! $start_date ) {
			return $skipped_cycles;
		}

		$start_timestamp = $this->safe_get_timestamp( $start_date );
		if ( ! $start_timestamp ) {
			return $skipped_cycles;
		}

		// Get related orders with limit to prevent performance issues.
		$related_orders = $subscription->get_related_orders();

		// Limit to last 24 orders to prevent infinite loops.
		if ( count( $related_orders ) > 24 ) {
			$related_orders = array_slice( $related_orders, -24 );
		}

		$order_dates = array();

		foreach ( $related_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && 'completed' === $order->get_status() ) {
				$order_date = $order->get_date_created();
				if ( $order_date ) {
					$order_dates[] = $order_date->getTimestamp();
				}
			}
		}

		// Sort order dates chronologically.
		sort( $order_dates );

		// If no completed orders, check if we should have had payments by now.
		if ( empty( $order_dates ) ) {
			$current_time           = current_time( 'timestamp' );
			$expected_first_payment = $this->calculate_expected_next_payment( $start_timestamp, $billing_period, $billing_interval );

			if ( $expected_first_payment && $current_time > strtotime( $expected_first_payment ) ) {
				$skipped_cycles[] = array(
					'type'           => 'no_payments',
					'severity'       => 'warning',
					'description'    => sprintf(
						/* translators: %s: start date */
						__( 'No payments received since start date %s', 'doctor-subs' ),
						gmdate( 'Y-m-d', $start_timestamp )
					),
					'details'        => array(
						'start_date'             => gmdate( 'Y-m-d', $start_timestamp ),
						'expected_first_payment' => $expected_first_payment,
						'billing_period'         => $billing_period,
						'billing_interval'       => $billing_interval,
					),
					'recommendation' => __( 'Check if payments are being processed correctly.', 'doctor-subs' ),
					'correctable'    => false,
				);
			}
			return $skipped_cycles;
		}

		// Check for gaps between payments.
		$billing_period_days = $this->get_billing_period_days( $billing_period, $billing_interval );

		// Safety check: ensure we have valid order dates.
		if ( empty( $order_dates ) || count( $order_dates ) < 2 ) {
			return $skipped_cycles;
		}

		// Limit the number of comparisons to prevent performance issues.
		$max_comparisons = min( count( $order_dates ) - 1, 20 );

		for ( $i = 0; $i < $max_comparisons; $i++ ) {
			$current_payment = $order_dates[ $i ];
			$next_payment    = $order_dates[ $i + 1 ];

			// Safety check: ensure timestamps are valid.
			if ( ! is_numeric( $current_payment ) || ! is_numeric( $next_payment ) ) {
				continue;
			}

			$expected_next      = $this->calculate_expected_next_payment( $current_payment, $billing_period, $billing_interval );
			$expected_timestamp = $this->safe_get_timestamp( $expected_next );

			if ( $expected_timestamp ) {
				$days_difference = ( $next_payment - $expected_timestamp ) / DAY_IN_SECONDS;

				// If the gap is more than one billing period, it's a skipped cycle.
				if ( $days_difference > $billing_period_days ) {
					$skipped_cycles[] = array(
						'type'                => 'skipped_cycle',
						'severity'            => 'warning',
						'description'         => sprintf(
							/* translators: 1: expected payment date, 2: actual next payment date */
							__( 'Payment cycle skipped — expected payment around %1$s, next payment was %2$s', 'doctor-subs' ),
							gmdate( 'Y-m-d', $expected_timestamp ),
							gmdate( 'Y-m-d', $next_payment )
						),
						'details'             => array(
							'last_payment_date'  => gmdate( 'Y-m-d', $current_payment ),
							'expected_next_date' => gmdate( 'Y-m-d', $expected_timestamp ),
							'actual_next_date'   => gmdate( 'Y-m-d', $next_payment ),
							'days_skipped'       => $days_difference,
							'billing_period'     => $billing_period,
							'billing_interval'   => $billing_interval,
						),
						'recommendation'      => __( 'Review what happened during this period and consider if payment should be collected.', 'doctor-subs' ),
						'correctable'         => true,
						'suggested_next_date' => gmdate( 'Y-m-d', $expected_timestamp ),
					);
				}
			}
		}

		// Check if the last payment was too long ago.
		$last_payment_timestamp = end( $order_dates );

		// Safety check: ensure last payment timestamp is valid.
		if ( ! $last_payment_timestamp || ! is_numeric( $last_payment_timestamp ) ) {
			return $skipped_cycles;
		}

		$current_time             = current_time( 'timestamp' );
		$expected_next_after_last = $this->calculate_expected_next_payment( $last_payment_timestamp, $billing_period, $billing_interval );

		if ( $expected_next_after_last ) {
			$expected_timestamp = $this->safe_get_timestamp( $expected_next_after_last );
			if ( $expected_timestamp && $current_time > $expected_timestamp ) {
				$days_since_expected = ( $current_time - $expected_timestamp ) / DAY_IN_SECONDS;

				if ( $days_since_expected > $billing_period_days ) {
					$skipped_cycles[] = array(
						'type'                => 'overdue_payment',
						'severity'            => 'warning',
						'description'         => sprintf(
							/* translators: 1: expected payment date, 2: current date */
							__( 'Payment overdue — expected payment around %1$s, now %2$s', 'doctor-subs' ),
							gmdate( 'Y-m-d', $expected_timestamp ),
							gmdate( 'Y-m-d', $current_time )
						),
						'details'             => array(
							'last_payment_date'  => gmdate( 'Y-m-d', $last_payment_timestamp ),
							'expected_next_date' => gmdate( 'Y-m-d', $expected_timestamp ),
							'days_overdue'       => $days_since_expected,
							'billing_period'     => $billing_period,
							'billing_interval'   => $billing_interval,
						),
						'recommendation'      => __( 'Check payment processing and customer status.', 'doctor-subs' ),
						'correctable'         => true,
						'suggested_next_date' => gmdate( 'Y-m-d', $expected_timestamp ),
					);
				}
			}
		}

		return $skipped_cycles;
	}

	/**
	 * Detect manual payment completions.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Manual completion details.
	 */
	private function detect_manual_completions( $subscription ) {
		$manual_completions = array();

		$related_orders = $subscription->get_related_orders();

		// Limit to last 24 orders to prevent performance issues.
		if ( count( $related_orders ) > 24 ) {
			$related_orders = array_slice( $related_orders, -24 );
		}

		foreach ( $related_orders as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$payment_method = $order->get_payment_method();
			$transaction_id = $order->get_transaction_id();

			// Check for manual payment methods.
			$manual_methods = array( 'cheque', 'bacs', 'cod', 'bank_transfer' );

			if ( in_array( $payment_method, $manual_methods, true ) ) {
				$manual_completions[] = array(
					'type'           => 'manual_payment',
					'severity'       => 'info',
					'description'    => sprintf(
						/* translators: %d: order ID */
						__( 'Manual payment completion detected for order #%d', 'doctor-subs' ),
						$order_id
					),
					'details'        => array(
						'order_id'       => $order_id,
						'payment_method' => $payment_method,
						'order_date'     => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
						'order_status'   => $order->get_status(),
						'transaction_id' => $transaction_id,
					),
					'recommendation' => __( 'Verify manual payment was properly recorded.', 'doctor-subs' ),
				);
			}

			// Check for orders marked complete without transaction ID.
			if ( 'completed' === $order->get_status() && empty( $transaction_id ) && ! in_array( $payment_method, $manual_methods, true ) ) {
				$manual_completions[] = array(
					'type'           => 'manual_completion',
					'severity'       => 'warning',
					'description'    => sprintf(
						/* translators: %d: order ID */
						__( 'Order #%d marked complete without transaction ID', 'doctor-subs' ),
						$order_id
					),
					'details'        => array(
						'order_id'       => $order_id,
						'payment_method' => $payment_method,
						'order_date'     => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
						'transaction_id' => $transaction_id,
					),
					'recommendation' => __( 'Verify payment was actually received and properly recorded.', 'doctor-subs' ),
				);
			}
		}

		return $manual_completions;
	}

	/**
	 * Detect status mismatches.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Status mismatch details.
	 */
	private function detect_status_mismatches( $subscription ) {
		$status_mismatches = array();

		$status       = $subscription->get_status();
		$next_payment = $subscription->get_date( 'next_payment' );
		$end_date     = $subscription->get_date( 'end' );

		// Check for expired status with future next payment.
		if ( 'expired' === $status && $next_payment ) {
			$next_timestamp = $this->safe_get_timestamp( $next_payment );
			$now            = current_time( 'timestamp' );

			if ( $next_timestamp && $next_timestamp > $now ) {
				$status_mismatches[] = array(
					'type'           => 'status_mismatch',
					'severity'       => 'error',
					'description'    => __( 'Subscription shows Expired but has a valid future next payment date', 'doctor-subs' ),
					'details'        => array(
						'current_status'    => $status,
						'next_payment_date' => $next_payment,
						'days_until_next'   => ceil( ( $next_timestamp - $now ) / DAY_IN_SECONDS ),
					),
					'recommendation' => __( 'Review subscription status and payment schedule for consistency.', 'doctor-subs' ),
				);
			}
		}

		// Check for active status with past end date.
		if ( 'active' === $status && $end_date ) {
			$end_timestamp = $this->safe_get_timestamp( $end_date );
			$now           = current_time( 'timestamp' );

			if ( $end_timestamp && $end_timestamp < $now ) {
				$status_mismatches[] = array(
					'type'           => 'status_mismatch',
					'severity'       => 'error',
					'description'    => __( 'Subscription shows Active but has passed its end date', 'doctor-subs' ),
					'details'        => array(
						'current_status' => $status,
						'end_date'       => $end_date,
						'days_past_end'  => ceil( ( $now - $end_timestamp ) / DAY_IN_SECONDS ),
					),
					'recommendation' => __( 'Consider updating subscription status to reflect actual state.', 'doctor-subs' ),
				);
			}
		}

		return $status_mismatches;
	}

	/**
	 * Audit Action Scheduler events.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Action Scheduler audit results.
	 */
	private function audit_action_scheduler( $subscription ) {
		$audit_results = array();

		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return $audit_results;
		}

		$subscription_id = $subscription->get_id();
		$store           = ActionScheduler_Store::instance();

		// Check for scheduled subscription payments.
		$payment_actions = $store->query_actions(
			array(
				'hook'     => 'woocommerce_scheduled_subscription_payment',
				'args'     => array( $subscription_id ),
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 10,
			)
		);

		if ( empty( $payment_actions ) ) {
			$audit_results[] = array(
				'type'           => 'missing_action',
				'severity'       => 'warning',
				'description'    => __( 'No scheduled subscription payment actions found', 'doctor-subs' ),
				'details'        => array(
					'action_type'     => 'woocommerce_scheduled_subscription_payment',
					'subscription_id' => $subscription_id,
				),
				'recommendation' => __( 'Check if subscription payments are properly scheduled.', 'doctor-subs' ),
			);
		}

		// Check for failed actions.
		$failed_actions = $store->query_actions(
			array(
				'hook'     => array( 'woocommerce_scheduled_subscription_payment', 'woocommerce_scheduled_subscription_expiration' ),
				'args'     => array( $subscription_id ),
				'status'   => ActionScheduler_Store::STATUS_FAILED,
				'per_page' => 10,
			)
		);

		foreach ( $failed_actions as $action_id ) {
			$action          = $store->fetch_action( $action_id );
			$audit_results[] = array(
				'type'           => 'failed_action',
				'severity'       => 'error',
				'description'    => sprintf(
					/* translators: %s: action hook name */
					__( 'Failed Action Scheduler event: %s', 'doctor-subs' ),
					$action->get_hook()
				),
				'details'        => array(
					'action_id'      => $action_id,
					'action_hook'    => $action->get_hook(),
					'scheduled_date' => $action->get_schedule()->get_gmdate()->format( 'Y-m-d H:i:s' ),
					'last_attempt'   => $action->get_last_attempt_gmdate() ? $action->get_last_attempt_gmdate()->format( 'Y-m-d H:i:s' ) : 'Never',
					'retry_count'    => $action->get_retry_count(),
				),
				'recommendation' => __( 'Review failed action and consider manual intervention.', 'doctor-subs' ),
			);
		}

		return $audit_results;
	}

	/**
	 * Perform year-over-year analysis.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Year-over-year analysis results.
	 */
	private function year_over_year_analysis( $subscription ) {
		$analysis = array();

		$related_orders = $subscription->get_related_orders();

		// Limit to last 24 orders to prevent performance issues.
		if ( count( $related_orders ) > 24 ) {
			$related_orders = array_slice( $related_orders, -24 );
		}

		$renewals_by_year = array();

		foreach ( $related_orders as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			// Only count renewal orders.
			if ( 'renewal' === $order->get_meta( '_subscription_renewal' ) ) {
				$year = $order->get_date_created()->format( 'Y' );
				if ( ! isset( $renewals_by_year[ $year ] ) ) {
					$renewals_by_year[ $year ] = 0;
				}
				++$renewals_by_year[ $year ];
			}
		}

		// Sort years.
		ksort( $renewals_by_year );

		// Check for missing years.
		$years = array_keys( $renewals_by_year );
		if ( count( $years ) > 1 ) {
			$min_year = min( $years );
			$max_year = max( $years );

			for ( $year = $min_year; $year <= $max_year; $year++ ) {
				if ( ! isset( $renewals_by_year[ $year ] ) ) {
					$analysis[] = array(
						'type'           => 'missing_year',
						'severity'       => 'warning',
						/* translators: %d: year */
						'description'    => sprintf( __( 'No renewals found for year %d', 'doctor-subs' ), $year ),
						'details'        => array(
							'year'            => $year,
							'analysis_period' => $min_year . ' - ' . $max_year,
						),
						'recommendation' => __( 'Investigate why no renewals occurred during this period.', 'doctor-subs' ),
					);
				}
			}
		}

		$analysis['yearly_summary'] = $renewals_by_year;

		return $analysis;
	}

	/**
	 * Calculate expected next payment date.
	 *
	 * @since 1.0.0
	 * @param int    $last_timestamp Last payment timestamp.
	 * @param string $billing_period Billing period (day, week, month, year).
	 * @param int    $billing_interval Billing interval.
	 * @return string|false Expected next payment date or false on failure.
	 */
	private function calculate_expected_next_payment( $last_timestamp, $billing_period, $billing_interval ) {
		$period_days = $this->get_billing_period_days( $billing_period, $billing_interval );

		if ( ! $period_days ) {
			return false;
		}

		$expected_timestamp = $last_timestamp + ( $period_days * DAY_IN_SECONDS );
		return gmdate( 'Y-m-d H:i:s', $expected_timestamp );
	}

	/**
	 * Get billing period in days.
	 *
	 * @since 1.0.0
	 * @param string $billing_period Billing period.
	 * @param int    $billing_interval Billing interval.
	 * @return int|false Days in billing period or false on failure.
	 */
	private function get_billing_period_days( $billing_period, $billing_interval ) {
		$period_multipliers = array(
			'day'   => 1,
			'week'  => 7,
			'month' => 30,
			'year'  => 365,
		);

		if ( ! isset( $period_multipliers[ $billing_period ] ) ) {
			return false;
		}

		return $period_multipliers[ $billing_period ] * $billing_interval;
	}
}
