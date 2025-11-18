<?php
declare( strict_types=1 );
/**
 * Expected Behavior Analyzer
 *
 * Implements Step 2 of the WooCommerce Subscriptions troubleshooting framework:
 * "Determine What Should Happen"
 *
 * @package Dr_Subs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes what should happen with a subscription based on its configuration.
 *
 * @since 1.0.0
 */
class WCST_Expected_Behavior {

	/**
	 * Safely format a date that might be a DateTime object or string (HPOS compatibility).
	 *
	 * @since 1.0.0
	 * @param mixed $date Date object or string.
	 * @return string Formatted date or 'unknown'.
	 */
	private function safe_format_date( $date ) {
		if ( empty( $date ) ) {
			return __( 'unknown', 'doctor-subs' );
		}

		if ( is_object( $date ) && method_exists( $date, 'format' ) ) {
			return $date->format( 'Y-m-d H:i:s' );
		}

		if ( is_string( $date ) ) {
			return $date;
		}

		return __( 'unknown', 'doctor-subs' );
	}

	/**
	 * Analyze expected subscription behavior.
	 *
	 * @since 1.0.0
	 * @param int $subscription_id Subscription ID to analyze.
	 * @return array Expected behavior analysis.
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
			'product_configuration'    => $this->analyze_product_configuration( $subscription ),
			'payment_gateway_behavior' => $this->analyze_payment_gateway_behavior( $subscription ),
			'renewal_expectations'     => $this->analyze_renewal_expectations( $subscription ),
			'lifecycle_expectations'   => $this->analyze_lifecycle_expectations( $subscription ),
			'creation_process'         => $this->analyze_creation_process( $subscription ),
			'switching_expectations'   => $this->analyze_switching_expectations( $subscription ),
		);
	}

	/**
	 * Analyze product configuration and its implications.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Product configuration analysis.
	 */
	private function analyze_product_configuration( $subscription ) {
		$config = array();
		$items  = $subscription->get_items();

		foreach ( $items as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$product = $item->get_product();

				if ( $product && function_exists( 'WC_Subscriptions_Product' ) ) {
					$product_config = array(
						'product_id'       => $product->get_id(),
						'name'             => $product->get_name(),
						'price'            => WC_Subscriptions_Product::get_price( $product ),
						'billing_period'   => WC_Subscriptions_Product::get_period( $product ),
						'billing_interval' => WC_Subscriptions_Product::get_interval( $product ),
						'trial_length'     => WC_Subscriptions_Product::get_trial_length( $product ),
						'trial_period'     => WC_Subscriptions_Product::get_trial_period( $product ),
						'sign_up_fee'      => WC_Subscriptions_Product::get_sign_up_fee( $product ),
						'length'           => WC_Subscriptions_Product::get_length( $product ),
						'synchronization'  => $this->get_synchronization_settings( $product ),
					);

					$config[] = $product_config;
				}
			}
		}

		return $config;
	}

	/**
	 * Analyze payment gateway behavior and capabilities.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Payment gateway behavior analysis.
	 */
	private function analyze_payment_gateway_behavior( $subscription ) {
		$payment_method = $subscription->get_payment_method();

		if ( ! $payment_method || ! function_exists( 'WC' ) ) {
			return array(
				'error' => __( 'Payment method not found or WooCommerce not available.', 'doctor-subs' ),
			);
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = isset( $gateways[ $payment_method ] ) ? $gateways[ $payment_method ] : null;

		if ( ! $gateway ) {
			return array(
				'error'      => __( 'Payment gateway not found.', 'doctor-subs' ),
				'gateway_id' => $payment_method,
			);
		}

		$supports = isset( $gateway->supports ) ? $gateway->supports : array();

		return array(
			'gateway_id'                                  => $payment_method,
			'gateway_title'                               => $gateway->get_title(),
			'gateway_enabled'                             => $gateway->is_available(),
			'gateway_mode'                                => $this->detect_gateway_mode( $gateway ),
			'supports_subscriptions'                      => in_array( 'subscriptions', $supports, true ),
			'supports_subscription_cancellation'          => in_array( 'subscription_cancellation', $supports, true ),
			'supports_subscription_suspension'            => in_array( 'subscription_suspension', $supports, true ),
			'supports_subscription_reactivation'          => in_array( 'subscription_reactivation', $supports, true ),
			'supports_subscription_amount_changes'        => in_array( 'subscription_amount_changes', $supports, true ),
			'supports_subscription_date_changes'          => in_array( 'subscription_date_changes', $supports, true ),
			'supports_subscription_payment_method_change' => in_array( 'subscription_payment_method_change', $supports, true ),
			'supports_subscription_payment_method_change_customer' => in_array( 'subscription_payment_method_change_customer', $supports, true ),
			'supports_subscription_payment_method_change_admin' => in_array( 'subscription_payment_method_change_admin', $supports, true ),
			'requires_manual_renewal'                     => $subscription->is_manual(),
			'webhook_configured'                          => $this->check_webhook_configuration( $gateway ),
			'billing_schedule_control'                    => $this->determine_billing_schedule_control( $subscription, $gateway ),
		);
	}

	/**
	 * Analyze renewal process expectations.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Renewal expectations analysis.
	 */
	private function analyze_renewal_expectations( $subscription ) {
		$is_manual        = $subscription->is_manual();
		$next_payment     = $subscription->get_date( 'next_payment' );
		$billing_interval = $subscription->get_billing_interval();
		$billing_period   = $subscription->get_billing_period();

		if ( $is_manual ) {
			return array(
				'type'              => 'manual',
				'description'       => __( 'Manual renewals require customer action to complete payments.', 'doctor-subs' ),
				'next_action'       => __( 'Customer must manually renew the subscription.', 'doctor-subs' ),
				'automated_actions' => false,
				'next_payment_date' => $next_payment,
			);
		}

		$payment_method  = $subscription->get_payment_method();
		$billing_control = $this->determine_billing_schedule_control( $subscription );

		if ( 'action_scheduler' === $billing_control ) {
			return array(
				'type'              => 'action_scheduler',
				'description'       => __( 'Renewals are handled by WordPress Action Scheduler.', 'doctor-subs' ),
				'next_action'       => sprintf(
					/* translators: %s: next payment date */
					__( 'Automated renewal scheduled for %s', 'doctor-subs' ),
					$this->safe_format_date( $next_payment )
				),
				'automated_actions' => true,
				'next_payment_date' => $next_payment,
				'billing_interval'  => $billing_interval,
				'billing_period'    => $billing_period,
				'renewal_process'   => $this->describe_action_scheduler_renewal_process(),
			);
		}

		return array(
			'type'               => 'gateway_controlled',
			'description'        => __( 'Renewals are controlled by the payment gateway.', 'doctor-subs' ),
			'next_action'        => __( 'Gateway will notify site when payment is processed.', 'doctor-subs' ),
			'automated_actions'  => true,
			'next_payment_date'  => $next_payment,
			'gateway_control'    => true,
			'webhook_dependency' => true,
			'renewal_process'    => $this->describe_gateway_controlled_renewal_process( $payment_method ),
		);
	}

	/**
	 * Analyze subscription lifecycle expectations.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Lifecycle expectations analysis.
	 */
	private function analyze_lifecycle_expectations( $subscription ) {
		$status       = $subscription->get_status();
		$end_date     = $subscription->get_date( 'end' );
		$trial_end    = $subscription->get_date( 'trial_end' );
		$next_payment = $subscription->get_date( 'next_payment' );

		$expectations = array(
			'current_status'       => $status,
			'status_description'   => $this->get_status_description( $status ),
			'possible_transitions' => $this->get_possible_status_transitions( $status ),
			'end_date'             => $end_date,
			'trial_end'            => $trial_end,
			'next_payment'         => $next_payment,
		);

		// Add specific expectations based on current status.
		switch ( $status ) {
			case 'active':
				$expectations['next_events'] = $this->get_active_subscription_expectations( $subscription );
				break;
			case 'on-hold':
				$expectations['next_events'] = $this->get_on_hold_subscription_expectations( $subscription );
				break;
			case 'pending-cancel':
				$expectations['next_events'] = $this->get_pending_cancel_expectations( $subscription );
				break;
			case 'cancelled':
			case 'expired':
				$expectations['next_events'] = array( __( 'No further automatic actions expected.', 'doctor-subs' ) );
				break;
		}

		return $expectations;
	}

	/**
	 * Analyze subscription creation process expectations.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Creation process analysis.
	 */
	private function analyze_creation_process( $subscription ) {
		$parent_order = $subscription->get_parent();

		if ( ! $parent_order ) {
			return array(
				'error' => __( 'No parent order found for this subscription.', 'doctor-subs' ),
			);
		}

		return array(
			'parent_order_id'       => $parent_order->get_id(),
			'parent_order_status'   => $parent_order->get_status(),
			'parent_order_date'     => $parent_order->get_date_created(),
			'subscription_date'     => $subscription->get_date( 'date_created' ),
			'creation_relationship' => $this->analyze_creation_relationship( $subscription, $parent_order ),
			'expected_data_match'   => $this->analyze_expected_data_match( $subscription, $parent_order ),
		);
	}

	/**
	 * Analyze subscription switching expectations.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Switching expectations analysis.
	 */
	private function analyze_switching_expectations( $subscription ) {
		$switch_data = $subscription->get_meta( '_subscription_switch_data' );

		if ( empty( $switch_data ) ) {
			return array(
				'has_switches' => false,
				'description'  => __( 'No subscription switches detected.', 'doctor-subs' ),
			);
		}

		return array(
			'has_switches'         => true,
			'switch_data'          => $switch_data,
			'switch_logs_location' => 'WooCommerce > Status > Logs > wcs-switch-cart-items',
			'description'          => __( 'This subscription has been modified through subscription switching.', 'doctor-subs' ),
			'expectations'         => array(
				__( 'Switch logs should contain detailed information about the changes.', 'doctor-subs' ),
				__( 'Prorated amounts should be calculated based on timing and pricing.', 'doctor-subs' ),
				__( 'New billing schedule should reflect the switched product configuration.', 'doctor-subs' ),
			),
		);
	}

	/**
	 * Get synchronization settings for a product.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product Product object.
	 * @return array Synchronization settings.
	 */
	private function get_synchronization_settings( $product ) {
		if ( ! function_exists( 'WC_Subscriptions_Synchroniser' ) ) {
			return array( 'enabled' => false );
		}

		$sync_enabled = WC_Subscriptions_Synchroniser::is_syncing_enabled();

		if ( ! $sync_enabled ) {
			return array( 'enabled' => false );
		}

		return array(
			'enabled'         => true,
			'sync_date'       => WC_Subscriptions_Synchroniser::get_products_payment_day( $product ),
			'prorate_enabled' => WC_Subscriptions_Synchroniser::is_proration_enabled_for_product( $product ),
		);
	}

	/**
	 * Check if webhook configuration exists for a gateway.
	 *
	 * @since 1.0.0
	 * @param object $gateway Payment gateway instance.
	 * @return bool True if webhook appears to be configured.
	 */
	private function check_webhook_configuration( $gateway ) {
		// This is a basic check - specific gateways would need specific implementation.
		$gateway_id = $gateway->id;

		// Check common webhook settings.
		$webhook_settings = array(
			'webhook_url',
			'webhook_secret',
			'webhook_endpoint',
		);

		foreach ( $webhook_settings as $setting ) {
			if ( isset( $gateway->settings[ $setting ] ) && ! empty( $gateway->settings[ $setting ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine who controls the billing schedule.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @param object|null     $gateway Payment gateway instance.
	 * @return string 'manual', 'action_scheduler', or 'gateway'.
	 */
	private function determine_billing_schedule_control( $subscription, $gateway = null ) {
		if ( $subscription->is_manual() ) {
			return 'manual';
		}

		if ( ! $gateway ) {
			$payment_method = $subscription->get_payment_method();
			$gateways       = WC()->payment_gateways()->payment_gateways();
			$gateway        = isset( $gateways[ $payment_method ] ) ? $gateways[ $payment_method ] : null;
		}

		if ( ! $gateway ) {
			return 'unknown';
		}

		// Check if gateway supports date changes (indicates Action Scheduler control).
		$supports = isset( $gateway->supports ) ? $gateway->supports : array();

		if ( in_array( 'subscription_date_changes', $supports, true ) ) {
			return 'action_scheduler';
		}

		return 'gateway';
	}

	/**
	 * Describe Action Scheduler renewal process.
	 *
	 * @since 1.0.0
	 * @return array Process description.
	 */
	private function describe_action_scheduler_renewal_process() {
		return array(
			'steps'        => array(
				__( 'WordPress cron triggers Action Scheduler', 'doctor-subs' ),
				__( 'Action Scheduler executes scheduled renewal action', 'doctor-subs' ),
				__( 'New renewal order is created', 'doctor-subs' ),
				__( 'Payment is processed via the payment gateway', 'doctor-subs' ),
				__( 'Subscription dates are updated', 'doctor-subs' ),
				__( 'Next renewal action is scheduled', 'doctor-subs' ),
			),
			'dependencies' => array(
				__( 'WordPress cron must be functioning', 'doctor-subs' ),
				__( 'Action Scheduler must be operational', 'doctor-subs' ),
				__( 'Payment gateway must be available', 'doctor-subs' ),
			),
		);
	}

	/**
	 * Describe gateway-controlled renewal process.
	 *
	 * @since 1.0.0
	 * @param string $payment_method Payment method ID.
	 * @return array Process description.
	 */
	private function describe_gateway_controlled_renewal_process( $payment_method ) {
		return array(
			'steps'          => array(
				__( 'Payment gateway processes payment on their schedule', 'doctor-subs' ),
				__( 'Gateway sends webhook notification to your site', 'doctor-subs' ),
				__( 'Site receives and processes webhook', 'doctor-subs' ),
				__( 'New renewal order is created', 'doctor-subs' ),
				__( 'Subscription is updated with new payment information', 'doctor-subs' ),
			),
			'dependencies'   => array(
				__( 'Gateway subscription must be active', 'doctor-subs' ),
				__( 'Webhook endpoint must be accessible', 'doctor-subs' ),
				__( 'Webhook authentication must be valid', 'doctor-subs' ),
				__( 'Site must be able to receive external requests', 'doctor-subs' ),
			),
			'payment_method' => $payment_method,
		);
	}

	/**
	 * Get description for subscription status.
	 *
	 * @since 1.0.0
	 * @param string $status Subscription status.
	 * @return string Status description.
	 */
	private function get_status_description( $status ) {
		$descriptions = array(
			'active'         => __( 'Subscription is active and should process renewals automatically.', 'doctor-subs' ),
			'on-hold'        => __( 'Subscription is suspended and will not process renewals until reactivated.', 'doctor-subs' ),
			'cancelled'      => __( 'Subscription has been cancelled and will not renew.', 'doctor-subs' ),
			'expired'        => __( 'Subscription has reached its natural end date.', 'doctor-subs' ),
			'pending-cancel' => __( 'Subscription is set to cancel at the end of the current billing period.', 'doctor-subs' ),
			'pending'        => __( 'Subscription is awaiting initial payment or activation.', 'doctor-subs' ),
		);

		return isset( $descriptions[ $status ] ) ? $descriptions[ $status ] : __( 'Unknown status.', 'doctor-subs' );
	}

	/**
	 * Get possible status transitions from current status.
	 *
	 * @since 1.0.0
	 * @param string $status Current subscription status.
	 * @return array Possible next statuses.
	 */
	private function get_possible_status_transitions( $status ) {
		$transitions = array(
			'active'         => array( 'on-hold', 'cancelled', 'expired', 'pending-cancel' ),
			'on-hold'        => array( 'active', 'cancelled', 'expired' ),
			'cancelled'      => array(), // Terminal status.
			'expired'        => array(), // Terminal status.
			'pending-cancel' => array( 'cancelled', 'active' ),
			'pending'        => array( 'active', 'cancelled', 'on-hold' ),
		);

		return isset( $transitions[ $status ] ) ? $transitions[ $status ] : array();
	}

	/**
	 * Get expectations for active subscriptions.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Expected events.
	 */
	private function get_active_subscription_expectations( $subscription ) {
		$events       = array();
		$next_payment = $subscription->get_date( 'next_payment' );
		$end_date     = $subscription->get_date( 'end' );

		if ( $next_payment ) {
			$events[] = sprintf(
				/* translators: %s: next payment date */
					__( 'Next renewal payment due: %s', 'doctor-subs' ),
				$this->safe_format_date( $next_payment )
			);
		}

		if ( $end_date ) {
			$events[] = sprintf(
				/* translators: %s: end date */
					__( 'Subscription will expire on: %s', 'doctor-subs' ),
				$this->safe_format_date( $end_date )
			);
		}

		if ( empty( $events ) ) {
			$events[] = __( 'Subscription will continue indefinitely until cancelled.', 'doctor-subs' );
		}

		return $events;
	}

	/**
	 * Get expectations for on-hold subscriptions.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Expected events.
	 */
	private function get_on_hold_subscription_expectations( $subscription ) {
		return array(
			__( 'No automatic renewals will occur while subscription is on hold.', 'doctor-subs' ),
			__( 'Subscription must be reactivated to resume billing.', 'doctor-subs' ),
			__( 'Customer access to subscription content may be restricted.', 'doctor-subs' ),
		);
	}

	/**
	 * Get expectations for pending-cancel subscriptions.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Expected events.
	 */
	private function get_pending_cancel_expectations( $subscription ) {
		$next_payment = $subscription->get_date( 'next_payment' );

		if ( $next_payment ) {
			return array(
				sprintf(
					/* translators: %s: next payment date */
					__( 'Subscription will be cancelled after the next payment on: %s', 'doctor-subs' ),
					$this->safe_format_date( $next_payment )
				),
				__( 'One final renewal payment will be processed.', 'doctor-subs' ),
				__( 'After final payment, status will change to cancelled.', 'doctor-subs' ),
			);
		}

		return array(
			__( 'Subscription will be cancelled immediately as no next payment is scheduled.', 'doctor-subs' ),
		);
	}

	/**
	 * Analyze creation relationship between subscription and parent order.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @param WC_Order        $parent_order Parent order object.
	 * @return array Creation relationship analysis.
	 */
	private function analyze_creation_relationship( $subscription, $parent_order ) {
		$sub_date   = $subscription->get_date( 'date_created' );
		$order_date = $parent_order->get_date_created();

		$time_diff = null;
		if ( $sub_date && $order_date ) {
			// Handle both DateTime objects and timestamp strings (HPOS compatibility)
			$sub_timestamp   = is_object( $sub_date ) ? $sub_date->getTimestamp() : strtotime( $sub_date );
			$order_timestamp = is_object( $order_date ) ? $order_date->getTimestamp() : strtotime( $order_date );
			$time_diff       = $sub_timestamp - $order_timestamp;
		}

		return array(
			'subscription_created_after_order' => $time_diff > 0,
			'time_difference_seconds'          => $time_diff,
			'expected_process'                 => array(
				__( 'Customer completes checkout with subscription product', 'doctor-subs' ),
				__( 'Parent order is created first', 'doctor-subs' ),
				__( 'Subscription is created as a copy of the parent order', 'doctor-subs' ),
				__( 'Future renewals are created as copies of the subscription', 'doctor-subs' ),
			),
		);
	}

	/**
	 * Analyze if subscription data matches parent order as expected.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @param WC_Order        $parent_order Parent order object.
	 * @return array Data match analysis.
	 */
	private function analyze_expected_data_match( $subscription, $parent_order ) {
		$matches    = array();
		$mismatches = array();

		// Compare basic data.
		if ( $subscription->get_currency() === $parent_order->get_currency() ) {
			$matches[] = __( 'Currency matches', 'doctor-subs' );
		} else {
			$mismatches[] = __( 'Currency does not match parent order', 'doctor-subs' );
		}

		if ( $subscription->get_customer_id() === $parent_order->get_customer_id() ) {
			$matches[] = __( 'Customer ID matches', 'doctor-subs' );
		} else {
			$mismatches[] = __( 'Customer ID does not match parent order', 'doctor-subs' );
		}

		// Compare payment method.
		if ( $subscription->get_payment_method() === $parent_order->get_payment_method() ) {
			$matches[] = __( 'Payment method matches', 'doctor-subs' );
		} else {
			$mismatches[] = __( 'Payment method does not match parent order', 'doctor-subs' );
		}

		return array(
			'matches'       => $matches,
			'mismatches'    => $mismatches,
			'overall_match' => empty( $mismatches ),
			'note'          => __( 'Some differences may be expected due to subscription switching, admin edits, or customizations.', 'doctor-subs' ),
		);
	}

	/**
	 * Detect gateway mode (live/sandbox/test).
	 *
	 * @since 1.0.0
	 * @param object $gateway Payment gateway instance.
	 * @return array Gateway mode information.
	 */
	private function detect_gateway_mode( $gateway ) {
		$gateway_id = $gateway->id;
		$mode_info  = array(
			'mode'        => 'unknown',
			'description' => __( 'Mode could not be determined', 'doctor-subs' ),
			'is_test'     => false,
		);

		// Common mode detection patterns
		$mode_patterns = array(
			'test_mode',
			'sandbox',
			'live_mode',
			'production',
			'environment',
			'debug_mode',
		);

		// Check gateway settings for mode indicators
		if ( isset( $gateway->settings ) && is_array( $gateway->settings ) ) {
			foreach ( $mode_patterns as $pattern ) {
				if ( isset( $gateway->settings[ $pattern ] ) ) {
					$value = $gateway->settings[ $pattern ];

					// Check for boolean values
					if ( is_bool( $value ) ) {
						if ( in_array( $pattern, array( 'test_mode', 'sandbox', 'debug_mode' ) ) ) {
							$mode_info['mode']        = $value ? 'sandbox' : 'live';
							$mode_info['is_test']     = $value;
							$mode_info['description'] = $value ? __( 'Sandbox/Test Mode', 'doctor-subs' ) : __( 'Live/Production Mode', 'doctor-subs' );
							break;
						}
					}

					// Check for string values
					if ( is_string( $value ) ) {
						$value_lower = strtolower( $value );
						if ( in_array( $value_lower, array( 'test', 'sandbox', 'false' ) ) ) {
							$mode_info['mode']        = 'sandbox';
							$mode_info['is_test']     = true;
							$mode_info['description'] = __( 'Sandbox/Test Mode', 'doctor-subs' );
							break;
						} elseif ( in_array( $value_lower, array( 'live', 'production', 'true' ) ) ) {
							$mode_info['mode']        = 'live';
							$mode_info['is_test']     = false;
							$mode_info['description'] = __( 'Live/Production Mode', 'doctor-subs' );
							break;
						}
					}
				}
			}
		}

		// Gateway-specific detection
		switch ( $gateway_id ) {
			case 'stripe':
				$mode_info = $this->detect_stripe_mode( $gateway );
				break;
			case 'paypal':
				$mode_info = $this->detect_paypal_mode( $gateway );
				break;
			case 'square':
				$mode_info = $this->detect_square_mode( $gateway );
				break;
			case 'braintree':
				$mode_info = $this->detect_braintree_mode( $gateway );
				break;
			case 'authorize_net':
				$mode_info = $this->detect_authorize_net_mode( $gateway );
				break;
		}

		return $mode_info;
	}

	/**
	 * Detect Stripe gateway mode.
	 *
	 * @since 1.0.0
	 * @param object $gateway Stripe gateway instance.
	 * @return array Mode information.
	 */
	private function detect_stripe_mode( $gateway ) {
		$mode_info = array(
			'mode'        => 'unknown',
			'description' => __( 'Mode could not be determined', 'doctor-subs' ),
			'is_test'     => false,
		);

		if ( isset( $gateway->settings['testmode'] ) ) {
			$is_test                  = $gateway->settings['testmode'] === 'yes';
			$mode_info['mode']        = $is_test ? 'sandbox' : 'live';
			$mode_info['is_test']     = $is_test;
			$mode_info['description'] = $is_test ? __( 'Stripe Test Mode', 'doctor-subs' ) : __( 'Stripe Live Mode', 'doctor-subs' );
		}

		return $mode_info;
	}

	/**
	 * Detect PayPal gateway mode.
	 *
	 * @since 1.0.0
	 * @param object $gateway PayPal gateway instance.
	 * @return array Mode information.
	 */
	private function detect_paypal_mode( $gateway ) {
		$mode_info = array(
			'mode'        => 'unknown',
			'description' => __( 'Mode could not be determined', 'doctor-subs' ),
			'is_test'     => false,
		);

		if ( isset( $gateway->settings['testmode'] ) ) {
			$is_test                  = $gateway->settings['testmode'] === 'yes';
			$mode_info['mode']        = $is_test ? 'sandbox' : 'live';
			$mode_info['is_test']     = $is_test;
			$mode_info['description'] = $is_test ? __( 'PayPal Sandbox Mode', 'doctor-subs' ) : __( 'PayPal Live Mode', 'doctor-subs' );
		}

		return $mode_info;
	}

	/**
	 * Detect Square gateway mode.
	 *
	 * @since 1.0.0
	 * @param object $gateway Square gateway instance.
	 * @return array Mode information.
	 */
	private function detect_square_mode( $gateway ) {
		$mode_info = array(
			'mode'        => 'unknown',
			'description' => __( 'Mode could not be determined', 'doctor-subs' ),
			'is_test'     => false,
		);

		if ( isset( $gateway->settings['sandbox'] ) ) {
			$is_test                  = $gateway->settings['sandbox'] === 'yes';
			$mode_info['mode']        = $is_test ? 'sandbox' : 'live';
			$mode_info['is_test']     = $is_test;
			$mode_info['description'] = $is_test ? __( 'Square Sandbox Mode', 'doctor-subs' ) : __( 'Square Live Mode', 'doctor-subs' );
		}

		return $mode_info;
	}

	/**
	 * Detect Braintree gateway mode.
	 *
	 * @since 1.0.0
	 * @param object $gateway Braintree gateway instance.
	 * @return array Mode information.
	 */
	private function detect_braintree_mode( $gateway ) {
		$mode_info = array(
			'mode'        => 'unknown',
			'description' => __( 'Mode could not be determined', 'doctor-subs' ),
			'is_test'     => false,
		);

		if ( isset( $gateway->settings['sandbox'] ) ) {
			$is_test                  = $gateway->settings['sandbox'] === 'yes';
			$mode_info['mode']        = $is_test ? 'sandbox' : 'live';
			$mode_info['is_test']     = $is_test;
			$mode_info['description'] = $is_test ? __( 'Braintree Sandbox Mode', 'doctor-subs' ) : __( 'Braintree Live Mode', 'doctor-subs' );
		}

		return $mode_info;
	}

	/**
	 * Detect Authorize.net gateway mode.
	 *
	 * @since 1.0.0
	 * @param object $gateway Authorize.net gateway instance.
	 * @return array Mode information.
	 */
	private function detect_authorize_net_mode( $gateway ) {
		$mode_info = array(
			'mode'        => 'unknown',
			'description' => __( 'Mode could not be determined', 'doctor-subs' ),
			'is_test'     => false,
		);

		if ( isset( $gateway->settings['testmode'] ) ) {
			$is_test                  = $gateway->settings['testmode'] === 'yes';
			$mode_info['mode']        = $is_test ? 'sandbox' : 'live';
			$mode_info['is_test']     = $is_test;
			$mode_info['description'] = $is_test ? __( 'Authorize.net Test Mode', 'doctor-subs' ) : __( 'Authorize.net Live Mode', 'doctor-subs' );
		}

		return $mode_info;
	}
}
