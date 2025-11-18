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
			throw new Exception( esc_html__( 'Subscription not found.', 'doctor-subs' ) );
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
					/* translators: %d: number of days overdue */
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
					/* translators: %d: number of days until payment is due */
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
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are safe (wpdb prefix), necessary for Action Scheduler queries.
			$renewal_actions = $wpdb->get_var(
				$wpdb->prepare(
					"
				SELECT COUNT(*) FROM {$actions_table}
				WHERE hook LIKE %s
				AND args LIKE %s
				AND scheduled_date >= %s
				AND status IN ('pending', 'completed')
			",
					'%renewal%',
					'%' . $wpdb->esc_like( $subscription_id ) . '%',
					$expected_renewal_date
				)
			);
			// phpcs:enable

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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are safe (wpdb prefix), necessary for Action Scheduler queries.
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
		// phpcs:enable

		if ( $failed_actions > 0 ) {
			$discrepancies[] = array(
				'type'           => 'failed_actions',
				'category'       => 'scheduler_issue',
				'severity'       => 'high',
				/* translators: %d: number of failed actions */
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
				/* translators: %s: subscription status */
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
					/* translators: 1: subscription status, 2: number of days */
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
					/* translators: %d: number of days until payment method expires */
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
				/* translators: %d: number of retry attempts */
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

		// Check for detached payment method error (cloned site issue)
		$detached_payment_method_issue = $this->check_detached_payment_method( $subscription );
		if ( ! empty( $detached_payment_method_issue ) ) {
			$discrepancies[] = $detached_payment_method_issue;
		}

		// Check for cloned/staging site indicators
		$cloned_site_issue = $this->check_cloned_site_indicators( $subscription );
		if ( ! empty( $cloned_site_issue ) ) {
			$discrepancies[] = $cloned_site_issue;
		}

		// Check renewal orders for Stripe API errors
		$renewal_errors = $this->check_stripe_renewal_errors( $subscription );
		$discrepancies   = array_merge( $discrepancies, $renewal_errors );

		return $discrepancies;
	}

	/**
	 * Check for detached payment method issues (cloned site bug)
	 *
	 * @since 1.1.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array|null Detached payment method discrepancy or null.
	 */
	private function check_detached_payment_method( $subscription ) {
		// Check subscription notes for the specific Stripe error message
		$notes = wc_get_order_notes(
			array(
				'order_id' => $subscription->get_id(),
				'limit'    => 100,
			)
		);

		$detached_error_patterns = array(
			'The provided PaymentMethod was previously used',
			'To use a PaymentMethod multiple times, you must attach it to a Customer first',
			'payment_method_attached_to_another_customer',
			'This PaymentMethod was previously used with a PaymentIntent',
		);

		$found_errors = array();
		foreach ( $notes as $note ) {
			$note_content = strtolower( $note->content );
			foreach ( $detached_error_patterns as $pattern ) {
				if ( false !== strpos( $note_content, strtolower( $pattern ) ) ) {
					$found_errors[] = array(
						'note_id'      => $note->id,
						'date'         => $note->date_created,
						'error_text'   => $pattern,
						'note_content' => substr( $note->content, 0, 200 ),
					);
					break; // Found error in this note, move to next note
				}
			}
		}

		if ( ! empty( $found_errors ) ) {
			return array(
				'type'           => 'detached_payment_method',
				'category'       => 'gateway_communication',
				'severity'       => 'critical',
				'description'    => __( 'Stripe payment method detachment detected - likely caused by cloned/staging site', 'doctor-subs' ),
				'details'        => array(
					'gateway'           => 'stripe',
					'error_count'       => count( $found_errors ),
					'errors'            => $found_errors,
					'subscription_id'   => $subscription->get_id(),
					'stripe_customer_id' => $subscription->get_meta( '_stripe_customer_id' ),
				),
				'recommendation' => __( 'This is a known issue when cloning sites with WooCommerce Subscriptions. Update to WooCommerce Stripe Gateway 7.x+ which includes fixes. If already updated, re-attach the payment method to the Stripe customer or contact Stripe support.', 'doctor-subs' ),
			);
		}

		// Check if payment token exists but might be detached
		$payment_token_id = $subscription->get_meta( '_payment_token_id' );
		$stripe_customer  = $subscription->get_meta( '_stripe_customer_id' );
		$stripe_source_id = $subscription->get_meta( '_stripe_source_id' );

		if ( ! empty( $payment_token_id ) && ! empty( $stripe_customer ) ) {
			// Check if there are failed renewal orders that might indicate detachment
			$renewal_orders = $subscription->get_related_orders( 'ids', 'renewal' );
			$failed_renewals = 0;
			foreach ( $renewal_orders as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order && in_array( $order->get_status(), array( 'failed', 'cancelled' ), true ) ) {
					$order_notes = wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 10 ) );
					foreach ( $order_notes as $order_note ) {
						$note_lower = strtolower( $order_note->content );
						foreach ( $detached_error_patterns as $pattern ) {
							if ( false !== strpos( $note_lower, strtolower( $pattern ) ) ) {
								$failed_renewals++;
								break 2; // Break out of both loops
							}
						}
					}
				}
			}

			if ( $failed_renewals > 0 ) {
				return array(
					'type'           => 'potential_detached_payment_method',
					'category'       => 'gateway_communication',
					'severity'       => 'high',
					'description'    => sprintf(
						/* translators: %d: number of failed renewals */
						__( 'Potential payment method detachment: %d failed renewal(s) with Stripe errors', 'doctor-subs' ),
						$failed_renewals
					),
					'details'        => array(
						'gateway'          => 'stripe',
						'failed_renewals'  => $failed_renewals,
						'payment_token_id' => $payment_token_id,
						'stripe_customer_id' => $stripe_customer,
					),
					'recommendation' => __( 'Review failed renewal orders for Stripe payment method errors. This may indicate a detached payment method issue from cloned/staging sites.', 'doctor-subs' ),
				);
			}
		}

		return null;
	}

	/**
	 * Check for cloned/staging site indicators
	 *
	 * @since 1.1.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array|null Cloned site indicator discrepancy or null.
	 */
	private function check_cloned_site_indicators( $subscription ) {
		// Check if WooCommerce Subscriptions duplicate site filter is active
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce Subscriptions core filter.
		if ( has_filter( 'woocommerce_subscriptions_is_duplicate_site' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce Subscriptions core filter.
			$is_duplicate_site = apply_filters( 'woocommerce_subscriptions_is_duplicate_site', false );
			if ( $is_duplicate_site ) {
				return array(
					'type'           => 'cloned_site_detected',
					'category'       => 'configuration',
					'severity'       => 'warning',
					'description'    => __( 'WooCommerce Subscriptions duplicate site detected - payment methods may be detached', 'doctor-subs' ),
					'details'        => array(
						'filter_active' => true,
						'is_duplicate'  => $is_duplicate_site,
					),
					'recommendation' => __( 'Ensure WooCommerce Stripe Gateway 7.x+ is installed with fixes for cloned sites. Monitor subscription renewals closely.', 'doctor-subs' ),
				);
			}
		}

		// Check WordPress environment type
		$environment_type = wp_get_environment_type();
		if ( in_array( $environment_type, array( 'staging', 'development' ), true ) ) {
			// Check if there are Stripe payment methods that might be affected
			$stripe_customer = $subscription->get_meta( '_stripe_customer_id' );
			if ( ! empty( $stripe_customer ) ) {
				return array(
					'type'           => 'staging_environment_stripe',
					'category'       => 'configuration',
					'severity'       => 'info',
					'description'    => sprintf(
						/* translators: %s: environment type */
						__( 'Running in %s environment with Stripe - ensure payment methods are properly configured', 'doctor-subs' ),
						$environment_type
					),
					'details'        => array(
						'environment_type' => $environment_type,
						'stripe_customer_id' => $stripe_customer,
					),
					'recommendation' => __( 'Staging/development environments can cause payment method detachment issues. Ensure WooCommerce Stripe Gateway has proper safeguards enabled.', 'doctor-subs' ),
				);
			}
		}

		return null;
	}

	/**
	 * Check renewal orders for Stripe API errors
	 *
	 * @since 1.1.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Array of Stripe renewal error discrepancies.
	 */
	private function check_stripe_renewal_errors( $subscription ) {
		$discrepancies = array();
		$renewal_orders = $subscription->get_related_orders( 'ids', 'renewal' );

		$stripe_error_patterns = array(
			'card_declined',
			'insufficient_funds',
			'expired_card',
			'processing_error',
			'authentication_required',
			'payment_method_attached_to_another_customer',
		);

		$error_summary = array();
		foreach ( $renewal_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			// Check order status
			if ( in_array( $order->get_status(), array( 'failed', 'cancelled' ), true ) ) {
				// Check order notes for Stripe errors
				$order_notes = wc_get_order_notes(
					array(
						'order_id' => $order_id,
						'limit'    => 20,
					)
				);

				foreach ( $order_notes as $note ) {
					$note_lower = strtolower( $note->content );
					foreach ( $stripe_error_patterns as $pattern ) {
						if ( false !== strpos( $note_lower, strtolower( $pattern ) ) ) {
							if ( ! isset( $error_summary[ $pattern ] ) ) {
								$error_summary[ $pattern ] = array(
									'count'      => 0,
									'order_ids'  => array(),
									'last_seen'  => '',
								);
							}
							$error_summary[ $pattern ]['count']++;
							$error_summary[ $pattern ]['order_ids'][] = $order_id;
							if ( empty( $error_summary[ $pattern ]['last_seen'] ) || $note->date_created > $error_summary[ $pattern ]['last_seen'] ) {
								$error_summary[ $pattern ]['last_seen'] = $note->date_created;
							}
							break;
						}
					}
				}
			}
		}

		// Create discrepancies for detected errors
		foreach ( $error_summary as $error_type => $error_data ) {
			if ( 'payment_method_attached_to_another_customer' === $error_type ) {
				// This is handled separately in check_detached_payment_method
				continue;
			}

			$discrepancies[] = array(
				'type'           => 'stripe_renewal_error',
				'category'       => 'gateway_communication',
				'severity'       => 'high',
				'description'    => sprintf(
					/* translators: 1: error type, 2: count */
					__( 'Stripe renewal error detected: %1$s (%2$d occurrence(s))', 'doctor-subs' ),
					$error_type,
					$error_data['count']
				),
				'details'        => array(
					'error_type'  => $error_type,
					'count'       => $error_data['count'],
					'order_ids'   => $error_data['order_ids'],
					'last_seen'   => $error_data['last_seen'],
				),
				'recommendation' => __( 'Review failed renewal orders and contact customer to resolve payment method issues.', 'doctor-subs' ),
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
