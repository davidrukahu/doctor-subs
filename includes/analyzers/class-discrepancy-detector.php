<?php
/**
 * Discrepancy Detector
 *
 * @package Dr_Subs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCST_Discrepancy_Detector {

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
	 * Analyze discrepancies
	 */
	public function analyze_discrepancies( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			throw new Exception( __( 'Subscription not found.', 'doctor-subs' ) );
		}

		$discrepancies = array();

		// Check payment timing discrepancies
		$discrepancies = array_merge( $discrepancies, $this->check_payment_timing( $subscription ) );

		// Check missing actions
		$discrepancies = array_merge( $discrepancies, $this->check_missing_actions( $subscription ) );

		// Check status transition issues
		$discrepancies = array_merge( $discrepancies, $this->check_status_transitions( $subscription ) );

		// Check gateway communication failures
		$discrepancies = array_merge( $discrepancies, $this->check_gateway_communications( $subscription ) );

		// Check email notification gaps
		$discrepancies = array_merge( $discrepancies, $this->check_notifications( $subscription ) );

		// Check payment method issues
		$discrepancies = array_merge( $discrepancies, $this->check_payment_method_issues( $subscription ) );

		// Check configuration issues
		$discrepancies = array_merge( $discrepancies, $this->check_configuration_issues( $subscription ) );

		return $this->prioritize_discrepancies( $discrepancies );
	}

	/**
	 * Check payment timing discrepancies
	 */
	private function check_payment_timing( $subscription ) {
		$discrepancies = array();

		$next_payment = $subscription->get_date( 'next_payment' );
		$last_payment = $subscription->get_date( 'last_payment' );
		$now          = current_time( 'timestamp' );

		if ( $next_payment ) {
			$next_payment_timestamp = $this->safe_get_timestamp( $next_payment );
			$days_until_next        = ceil( ( $next_payment_timestamp - $now ) / DAY_IN_SECONDS );

			// Check for overdue payments
			if ( $days_until_next < 0 ) {
				$discrepancies[] = array(
					'type'           => 'payment_overdue',
					'category'       => 'payment_timing',
					'severity'       => 'critical',
					'description'    => sprintf( __( 'Payment is %d days overdue', 'doctor-subs' ), abs( $days_until_next ) ),
					'details'        => array(
						'expected_date'       => $next_payment,
						'days_overdue'        => abs( $days_until_next ),
						'subscription_status' => $subscription->get_status(),
					),
					'recommendation' => __( 'Check payment method and retry payment or contact customer.', 'doctor-subs' ),
				);
			}

			// Check for payments due soon
			if ( $days_until_next >= 0 && $days_until_next <= 3 ) {
				$discrepancies[] = array(
					'type'           => 'payment_due_soon',
					'category'       => 'payment_timing',
					'severity'       => 'warning',
					'description'    => sprintf( __( 'Payment due in %d days', 'doctor-subs' ), $days_until_next ),
					'details'        => array(
						'due_date'       => $next_payment,
						'days_until_due' => $days_until_next,
					),
					'recommendation' => __( 'Monitor payment processing and ensure payment method is valid.', 'doctor-subs' ),
				);
			}
		}

		// Check for irregular payment intervals
		if ( $last_payment && $next_payment ) {
			$expected_interval   = $this->calculate_expected_interval( $subscription );
			$actual_interval     = $this->safe_get_timestamp( $next_payment ) - $this->safe_get_timestamp( $last_payment );
			$interval_difference = abs( $actual_interval - $expected_interval );

			if ( $interval_difference > DAY_IN_SECONDS ) {
				$discrepancies[] = array(
					'type'           => 'irregular_payment_interval',
					'category'       => 'payment_timing',
					'severity'       => 'medium',
					'description'    => __( 'Payment interval differs from expected schedule', 'doctor-subs' ),
					'details'        => array(
						'expected_interval' => $expected_interval,
						'actual_interval'   => $actual_interval,
						'difference_days'   => round( $interval_difference / DAY_IN_SECONDS ),
					),
					'recommendation' => __( 'Review subscription schedule and payment processing.', 'doctor-subs' ),
				);
			}
		}

		return $discrepancies;
	}

	/**
	 * Check missing actions
	 */
	private function check_missing_actions( $subscription ) {
		$discrepancies = array();

		global $wpdb;

		$actions_table   = $wpdb->prefix . 'actionscheduler_actions';
		$subscription_id = $subscription->get_id();

		// Check for missing renewal actions
		$expected_renewal_date = $subscription->get_date( 'next_payment' );
		if ( $expected_renewal_date ) {
			$renewal_actions = $wpdb->get_var(
				$wpdb->prepare(
					"
				SELECT COUNT(*) FROM {$actions_table}
				WHERE hook LIKE '%renewal%'
				AND args LIKE %s
				AND scheduled_date >= %s
				AND status IN ('pending', 'completed')
			",
					'%' . $wpdb->esc_like( $subscription_id ) . '%',
					$expected_renewal_date
				)
			);

			if ( $renewal_actions == 0 ) {
				$discrepancies[] = array(
					'type'           => 'missing_renewal_action',
					'category'       => 'scheduler_issue',
					'severity'       => 'critical',
					'description'    => __( 'No renewal action scheduled for next payment', 'doctor-subs' ),
					'details'        => array(
						'expected_renewal_date' => $expected_renewal_date,
						'subscription_id'       => $subscription_id,
					),
					'recommendation' => __( 'Manually schedule renewal action or check Action Scheduler configuration.', 'doctor-subs' ),
				);
			}
		}

		// Check for failed actions
		$failed_actions = $wpdb->get_var(
			$wpdb->prepare(
				"
			SELECT COUNT(*) FROM {$actions_table}
			WHERE args LIKE %s
			AND status = 'failed'
		",
				'%' . $wpdb->esc_like( $subscription_id ) . '%'
			)
		);

		if ( $failed_actions > 0 ) {
			$discrepancies[] = array(
				'type'           => 'failed_actions',
				'category'       => 'scheduler_issue',
				'severity'       => 'high',
				'description'    => sprintf( __( '%d failed actions detected', 'doctor-subs' ), $failed_actions ),
				'details'        => array(
					'failed_count'    => $failed_actions,
					'subscription_id' => $subscription_id,
				),
				'recommendation' => __( 'Review failed actions in Action Scheduler and resolve underlying issues.', 'doctor-subs' ),
			);
		}

		return $discrepancies;
	}

	/**
	 * Check status transition issues
	 */
	private function check_status_transitions( $subscription ) {
		$discrepancies  = array();
		$current_status = $subscription->get_status();

		// Check for unexpected status
		$unexpected_statuses = array( 'pending', 'on-hold' );
		if ( in_array( $current_status, $unexpected_statuses ) ) {
			$discrepancies[] = array(
				'type'           => 'unexpected_status',
				'category'       => 'status_issue',
				'severity'       => $current_status === 'on-hold' ? 'high' : 'medium',
				'description'    => sprintf( __( 'Subscription in unexpected status: %s', 'doctor-subs' ), $current_status ),
				'details'        => array(
					'current_status'  => $current_status,
					'subscription_id' => $subscription->get_id(),
				),
				'recommendation' => __( 'Review subscription status and take appropriate action.', 'doctor-subs' ),
			);
		}

		// Check for stuck status
		$last_modified = $subscription->get_date( 'date_modified' );
		if ( $last_modified ) {
			$days_since_modification = ( current_time( 'timestamp' ) - $this->safe_get_timestamp( $last_modified ) ) / DAY_IN_SECONDS;

			if ( $days_since_modification > 7 && in_array( $current_status, array( 'pending', 'on-hold' ) ) ) {
				$discrepancies[] = array(
					'type'           => 'stuck_status',
					'category'       => 'status_issue',
					'severity'       => 'high',
					'description'    => sprintf( __( 'Subscription stuck in %1$s status for %2$d days', 'doctor-subs' ), $current_status, round( $days_since_modification ) ),
					'details'        => array(
						'status'        => $current_status,
						'days_stuck'    => round( $days_since_modification ),
						'last_modified' => $last_modified,
					),
					'recommendation' => __( 'Investigate why subscription is stuck and take corrective action.', 'doctor-subs' ),
				);
			}
		}

		return $discrepancies;
	}

	/**
	 * Check gateway communication failures
	 */
	private function check_gateway_communications( $subscription ) {
		$discrepancies  = array();
		$payment_method = $subscription->get_payment_method();

		// Check for missing gateway tokens
		$token_id = $subscription->get_meta( '_payment_token_id' );
		if ( empty( $token_id ) && ! in_array( $payment_method, array( 'cheque', 'bacs', 'cod' ) ) ) {
			$discrepancies[] = array(
				'type'           => 'missing_payment_token',
				'category'       => 'gateway_communication',
				'severity'       => 'critical',
				'description'    => __( 'No payment token found for subscription', 'doctor-subs' ),
				'details'        => array(
					'payment_method'  => $payment_method,
					'subscription_id' => $subscription->get_id(),
				),
				'recommendation' => __( 'Check payment method configuration and ensure tokenization is working.', 'doctor-subs' ),
			);
		}

		// Check for expired payment methods
		$expiry_date = $subscription->get_meta( '_payment_token_expiry' );
		if ( $expiry_date ) {
			$expiry_timestamp  = $this->safe_get_timestamp( $expiry_date );
			$days_until_expiry = ceil( ( $expiry_timestamp - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );

			if ( $days_until_expiry < 0 ) {
				$discrepancies[] = array(
					'type'           => 'expired_payment_method',
					'category'       => 'gateway_communication',
					'severity'       => 'critical',
					'description'    => __( 'Payment method has expired', 'doctor-subs' ),
					'details'        => array(
						'expiry_date'  => $expiry_date,
						'days_expired' => abs( $days_until_expiry ),
					),
					'recommendation' => __( 'Contact customer to update payment method.', 'doctor-subs' ),
				);
			} elseif ( $days_until_expiry <= 30 ) {
				$discrepancies[] = array(
					'type'           => 'expiring_payment_method',
					'category'       => 'gateway_communication',
					'severity'       => 'warning',
					'description'    => sprintf( __( 'Payment method expires in %d days', 'doctor-subs' ), $days_until_expiry ),
					'details'        => array(
						'expiry_date'       => $expiry_date,
						'days_until_expiry' => $days_until_expiry,
					),
					'recommendation' => __( 'Notify customer to update payment method before expiry.', 'doctor-subs' ),
				);
			}
		}

		// Check for gateway-specific issues
		switch ( $payment_method ) {
			case 'stripe':
				$discrepancies = array_merge( $discrepancies, $this->check_stripe_issues( $subscription ) );
				break;
			case 'paypal':
				$discrepancies = array_merge( $discrepancies, $this->check_paypal_issues( $subscription ) );
				break;
		}

		return $discrepancies;
	}

	/**
	 * Check notifications
	 */
	private function check_notifications( $subscription ) {
		$discrepancies = array();

		// Check for missing email notifications
		$email_settings = get_option( 'woocommerce_email_settings', array() );

		// Check renewal reminder emails
		if ( ! isset( $email_settings['woocommerce_subscription_renewal_reminder_enabled'] ) ||
			$email_settings['woocommerce_subscription_renewal_reminder_enabled'] !== 'yes' ) {
			$discrepancies[] = array(
				'type'           => 'missing_renewal_reminders',
				'category'       => 'notification_gap',
				'severity'       => 'medium',
				'description'    => __( 'Renewal reminder emails are disabled', 'doctor-subs' ),
				'details'        => array(
					'setting' => 'woocommerce_subscription_renewal_reminder_enabled',
				),
				'recommendation' => __( 'Enable renewal reminder emails to improve customer experience.', 'doctor-subs' ),
			);
		}

		// Check payment failed emails
		if ( ! isset( $email_settings['woocommerce_subscription_payment_failed_enabled'] ) ||
			$email_settings['woocommerce_subscription_payment_failed_enabled'] !== 'yes' ) {
			$discrepancies[] = array(
				'type'           => 'missing_payment_failed_emails',
				'category'       => 'notification_gap',
				'severity'       => 'high',
				'description'    => __( 'Payment failed emails are disabled', 'doctor-subs' ),
				'details'        => array(
					'setting' => 'woocommerce_subscription_payment_failed_enabled',
				),
				'recommendation' => __( 'Enable payment failed emails to notify customers of payment issues.', 'doctor-subs' ),
			);
		}

		return $discrepancies;
	}

	/**
	 * Check payment method issues
	 */
	private function check_payment_method_issues( $subscription ) {
		$discrepancies  = array();
		$payment_method = $subscription->get_payment_method();

		// Check for manual renewal requirement
		if ( in_array( $payment_method, array( 'cheque', 'bacs', 'cod' ) ) ) {
			$discrepancies[] = array(
				'type'           => 'manual_renewal_required',
				'category'       => 'payment_method',
				'severity'       => 'info',
				'description'    => __( 'Subscription requires manual renewal', 'doctor-subs' ),
				'details'        => array(
					'payment_method' => $payment_method,
				),
				'recommendation' => __( 'Monitor subscription and process payments manually.', 'doctor-subs' ),
			);
		}

		// Check for high retry count
		$retry_count = $subscription->get_meta( '_payment_retry_count' );
		if ( $retry_count && $retry_count > 3 ) {
			$discrepancies[] = array(
				'type'           => 'high_payment_retry_count',
				'category'       => 'payment_method',
				'severity'       => 'high',
				'description'    => sprintf( __( 'High payment retry count: %d attempts', 'doctor-subs' ), $retry_count ),
				'details'        => array(
					'retry_count' => $retry_count,
				),
				'recommendation' => __( 'Contact customer to resolve payment method issues.', 'doctor-subs' ),
			);
		}

		return $discrepancies;
	}

	/**
	 * Check configuration issues
	 */
	private function check_configuration_issues( $subscription ) {
		$discrepancies = array();

		// Check for missing product configuration
		$items = $subscription->get_items();
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( $product && ! $product->is_type( 'subscription' ) ) {
				$discrepancies[] = array(
					'type'           => 'non_subscription_product',
					'category'       => 'configuration',
					'severity'       => 'critical',
					'description'    => __( 'Subscription contains non-subscription product', 'doctor-subs' ),
					'details'        => array(
						'product_id'   => $product->get_id(),
						'product_type' => $product->get_type(),
					),
					'recommendation' => __( 'Review subscription products and ensure all are subscription products.', 'doctor-subs' ),
				);
			}
		}

		return $discrepancies;
	}

	/**
	 * Check Stripe-specific issues
	 */
	private function check_stripe_issues( $subscription ) {
		$discrepancies = array();

		// Check for missing Stripe customer ID
		$stripe_customer_id = $subscription->get_meta( '_stripe_customer_id' );
		if ( empty( $stripe_customer_id ) ) {
			$discrepancies[] = array(
				'type'           => 'missing_stripe_customer',
				'category'       => 'gateway_communication',
				'severity'       => 'high',
				'description'    => __( 'No Stripe customer ID found', 'doctor-subs' ),
				'details'        => array(
					'gateway' => 'stripe',
				),
				'recommendation' => __( 'Check Stripe integration and customer creation process.', 'doctor-subs' ),
			);
		}

		return $discrepancies;
	}

	/**
	 * Check PayPal-specific issues
	 */
	private function check_paypal_issues( $subscription ) {
		$discrepancies = array();

		// Check for missing PayPal subscription ID
		$paypal_subscription_id = $subscription->get_meta( '_paypal_subscription_id' );
		if ( empty( $paypal_subscription_id ) ) {
			$discrepancies[] = array(
				'type'           => 'missing_paypal_subscription',
				'category'       => 'gateway_communication',
				'severity'       => 'high',
				'description'    => __( 'No PayPal subscription ID found', 'doctor-subs' ),
				'details'        => array(
					'gateway' => 'paypal',
				),
				'recommendation' => __( 'Check PayPal integration and subscription creation process.', 'doctor-subs' ),
			);
		}

		return $discrepancies;
	}

	/**
	 * Prioritize discrepancies by severity
	 */
	private function prioritize_discrepancies( $discrepancies ) {
		$severity_order = array( 'critical', 'high', 'medium', 'warning', 'info' );

		usort(
			$discrepancies,
			function ( $a, $b ) use ( $severity_order ) {
				$a_index = array_search( $a['severity'], $severity_order );
				$b_index = array_search( $b['severity'], $severity_order );

				if ( $a_index === $b_index ) {
					return 0;
				}

				return $a_index < $b_index ? -1 : 1;
			}
		);

		return $discrepancies;
	}

	/**
	 * Helper methods
	 */
	private function calculate_expected_interval( $subscription ) {
		$interval = $subscription->get_billing_interval();
		$period   = $subscription->get_billing_period();

		switch ( $period ) {
			case 'day':
				return $interval * DAY_IN_SECONDS;
			case 'week':
				return $interval * WEEK_IN_SECONDS;
			case 'month':
				return $interval * MONTH_IN_SECONDS;
			case 'year':
				return $interval * YEAR_IN_SECONDS;
			default:
				return 0;
		}
	}
}
