<?php
declare( strict_types=1 );
/**
 * Subscription Anatomy Analyzer
 *
 * Implements Step 1 of the WooCommerce Subscriptions troubleshooting framework:
 * "Understand the Anatomy of a Subscription"
 *
 * @package Dr_Subs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes the anatomy and structure of a subscription.
 *
 * @since 1.0.0
 */
class WCST_Subscription_Anatomy {

	/**
	 * Safely format a date that might be a DateTime object or string (HPOS compatibility).
	 *
	 * @since 1.0.0
	 * @param mixed $date Date object or string.
	 * @return mixed Formatted date or original value.
	 */
	private function safe_format_date( $date ) {
		if ( empty( $date ) ) {
			return $date;
		}

		if ( is_object( $date ) && method_exists( $date, 'format' ) ) {
			return $date->format( 'Y-m-d H:i:s' );
		}

		return $date;
	}

	/**
	 * Analyze subscription anatomy.
	 *
	 * @since 1.0.0
	 * @param int $subscription_id Subscription ID to analyze.
	 * @return array Comprehensive subscription anatomy data.
	 * @throws Exception If subscription is not found or analysis fails.
	 */
	public function analyze( $subscription_id ) {
		// Validate subscription exists and is accessible.
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			throw new Exception( esc_html__( 'WooCommerce Subscriptions is not active or properly loaded.', 'doctor-subs' ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			throw new Exception( esc_html__( 'Subscription not found.', 'doctor-subs' ) );
		}

		return array(
			'basic_info'         => $this->get_basic_info( $subscription ),
			'payment_method'     => $this->get_payment_method_info( $subscription ),
			'billing_schedule'   => $this->get_billing_schedule( $subscription ),
			'subscription_notes' => $this->get_subscription_notes( $subscription ),
			'related_orders'     => $this->get_related_orders( $subscription ),
			'scheduled_actions'  => $this->get_scheduled_actions( $subscription ),
			'subscription_type'  => $this->determine_subscription_type( $subscription ),
			'meta_data'          => $this->get_relevant_meta_data( $subscription ),
		);
	}

	/**
	 * Get basic subscription information.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Basic subscription information.
	 */
	private function get_basic_info( $subscription ) {
		return array(
			'id'            => $subscription->get_id(),
			'status'        => $subscription->get_status(),
			'date_created'  => $this->safe_format_date( $subscription->get_date( 'date_created' ) ),
			'date_modified' => $this->safe_format_date( $subscription->get_date( 'date_modified' ) ),
			'total'         => $subscription->get_total(),
			'currency'      => $subscription->get_currency(),
			'customer_id'   => $subscription->get_customer_id(),
			'parent_id'     => $subscription->get_parent_id(),
			'product_ids'   => $this->get_subscription_product_ids( $subscription ),
			'order_key'     => $subscription->get_order_key(),
		);
	}

	/**
	 * Get payment method information.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Payment method information and status.
	 */
	private function get_payment_method_info( $subscription ) {
		$payment_method       = $subscription->get_payment_method();
		$payment_method_title = $subscription->get_payment_method_title();
		$requires_manual      = $subscription->is_manual();

		// Get payment gateway instance if available.
		$gateway = null;
		if ( $payment_method && function_exists( 'WC' ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = isset( $gateways[ $payment_method ] ) ? $gateways[ $payment_method ] : null;
		}

		// Analyze payment method status.
		$status = $this->analyze_payment_method_status( $subscription, $gateway );

		return array(
			'gateway_id'             => $payment_method,
			'title'                  => $payment_method_title,
			'requires_manual'        => $requires_manual,
			'gateway_instance'       => $gateway ? get_class( $gateway ) : null,
			'gateway_enabled'        => $gateway ? $gateway->is_available() : false,
			'supports_subscriptions' => $gateway ? $this->gateway_supports_subscriptions( $gateway ) : false,
			'token_info'             => $this->get_payment_token_info( $subscription ),
			'status'                 => $status,
		);
	}

	/**
	 * Get billing schedule information.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Billing schedule information.
	 */
	private function get_billing_schedule( $subscription ) {
		return array(
			'period'           => $subscription->get_billing_period(),
			'interval'         => $subscription->get_billing_interval(),
			'start_date'       => $this->safe_format_date( $subscription->get_date( 'start' ) ),
			'trial_end'        => $this->safe_format_date( $subscription->get_date( 'trial_end' ) ),
			'next_payment'     => $this->safe_format_date( $subscription->get_date( 'next_payment' ) ),
			'last_payment'     => $this->safe_format_date( $subscription->get_date( 'last_payment' ) ),
			'end_date'         => $this->safe_format_date( $subscription->get_date( 'end' ) ),
			'cancelled_date'   => $this->safe_format_date( $subscription->get_date( 'cancelled' ) ),
			'suspension_count' => $subscription->get_suspension_count(),
			'is_editable'      => $this->is_billing_schedule_editable( $subscription ),
		);
	}

	/**
	 * Get subscription notes.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Formatted subscription notes.
	 */
	private function get_subscription_notes( $subscription ) {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $subscription->get_id(),
				'order_by' => 'date_created',
				'order'    => 'DESC',
				'limit'    => 50, // Limit to last 50 notes for performance.
			)
		);

		$formatted_notes = array();
		foreach ( $notes as $note ) {
			$formatted_notes[] = array(
				'id'            => $note->id,
				'content'       => $note->content,
				'date_created'  => $note->date_created,
				'note_type'     => $note->note_type,
				'customer_note' => (bool) $note->customer_note,
				'added_by'      => $note->added_by,
			);
		}

		return $formatted_notes;
	}

	/**
	 * Get related orders information.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Related orders information.
	 */
	private function get_related_orders( $subscription ) {
		$parent_order       = $subscription->get_parent();
		$renewal_orders     = $subscription->get_related_orders( 'ids', 'renewal' );
		$resubscribe_orders = $subscription->get_related_orders( 'ids', 'resubscribe' );
		$switch_orders      = $subscription->get_related_orders( 'ids', 'switch' );

		return array(
			'parent_order'       => array(
				'id'     => $parent_order ? $parent_order->get_id() : 0,
				'status' => $parent_order ? $parent_order->get_status() : '',
				'total'  => $parent_order ? $parent_order->get_total() : 0,
				'date'   => $parent_order ? $this->safe_format_date( $parent_order->get_date_created() ) : null,
			),
			'renewal_orders'     => $this->format_order_list( $renewal_orders ),
			'resubscribe_orders' => $this->format_order_list( $resubscribe_orders ),
			'switch_orders'      => $this->format_order_list( $switch_orders ),
			'counts'             => array(
				'total'        => count( $renewal_orders ) + count( $resubscribe_orders ) + count( $switch_orders ) + ( $parent_order ? 1 : 0 ),
				'renewals'     => count( $renewal_orders ),
				'resubscribes' => count( $resubscribe_orders ),
				'switches'     => count( $switch_orders ),
			),
		);
	}

	/**
	 * Get scheduled actions related to the subscription.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Scheduled actions data.
	 */
	private function get_scheduled_actions( $subscription ) {
		global $wpdb;

		// Check if Action Scheduler tables exist.
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary for Action Scheduler table check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) !== $actions_table ) {
			return array(
				'error' => __( 'Action Scheduler tables not found.', 'doctor-subs' ),
			);
		}

		$subscription_id = $subscription->get_id();

		// Get all actions related to this subscription.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are safe (wpdb prefix), necessary for Action Scheduler queries.
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT a.*, ag.slug as group_slug
				FROM {$actions_table} a
				LEFT JOIN {$groups_table} ag ON a.group_id = ag.group_id
				WHERE a.args LIKE %s
				ORDER BY a.scheduled_date_gmt ASC
			",
				'%' . $wpdb->esc_like( (string) $subscription_id ) . '%'
			)
		);
		// phpcs:enable

		$formatted_actions = array(
			'pending'     => array(),
			'in-progress' => array(),
			'complete'    => array(),
			'failed'      => array(),
			'canceled'    => array(),
		);

		foreach ( $actions as $action ) {
			$status = $action->status;
			if ( ! isset( $formatted_actions[ $status ] ) ) {
				$formatted_actions[ $status ] = array();
			}

			$formatted_actions[ $status ][] = array(
				'id'             => $action->action_id,
				'hook'           => $action->hook,
				'status'         => $action->status,
				'scheduled_date' => $action->scheduled_date_gmt,
				'args'           => maybe_unserialize( $action->args ),
				'group_slug'     => $action->group_slug,
				'extended_args'  => maybe_unserialize( $action->extended_args ),
			);
		}

		return $formatted_actions;
	}

	/**
	 * Determine subscription type based on payment method and configuration.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Subscription type information.
	 */
	private function determine_subscription_type( $subscription ) {
		$payment_method = $subscription->get_payment_method();
		$is_manual      = $subscription->is_manual();

		// Determine if this is an Action Scheduler-powered or Gateway-controlled subscription.
		$is_action_scheduler_powered = ! $is_manual && $this->is_billing_schedule_editable( $subscription );

		return array(
			'is_manual'                   => $is_manual,
			'is_action_scheduler_powered' => $is_action_scheduler_powered,
			'is_gateway_controlled'       => ! $is_manual && ! $is_action_scheduler_powered,
			'billing_control'             => $is_manual ? 'manual' : ( $is_action_scheduler_powered ? 'action_scheduler' : 'gateway' ),
			'payment_method'              => $payment_method,
		);
	}

	/**
	 * Get relevant subscription meta data.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Relevant meta data.
	 */
	private function get_relevant_meta_data( $subscription ) {
		$relevant_keys = array(
			'_payment_method',
			'_payment_method_title',
			'_requires_manual_renewal',
			'_billing_period',
			'_billing_interval',
			'_subscription_renewal_payment_complete',
			'_subscription_switch_data',
			'_subscription_resubscribe',
		);

		$meta_data = array();

		foreach ( $relevant_keys as $key ) {
			$value = $subscription->get_meta( $key );
			if ( '' !== $value ) {
				$meta_data[ $key ] = $value;
			}
		}

		return $meta_data;
	}

	/**
	 * Get subscription product IDs.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array Product IDs in the subscription.
	 */
	private function get_subscription_product_ids( $subscription ) {
		$product_ids = array();
		$items       = $subscription->get_items();

		if ( $items ) {
			foreach ( $items as $item ) {
				if ( $item instanceof WC_Order_Item_Product ) {
					$product_ids[] = $item->get_product_id();
				}
			}
		}

		return $product_ids;
	}

	/**
	 * Analyze payment method status.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @param object|null     $gateway Payment gateway instance.
	 * @return array Payment method status analysis.
	 */
	private function analyze_payment_method_status( $subscription, $gateway ) {
		$warnings = array();
		$is_valid = true;

		// Check if gateway exists and is enabled.
		if ( ! $gateway ) {
			$warnings[] = __( 'Payment gateway not found or not available.', 'doctor-subs' );
			$is_valid   = false;
		} elseif ( ! $gateway->is_available() ) {
			$warnings[] = __( 'Payment gateway is disabled or not available.', 'doctor-subs' );
			$is_valid   = false;
		}

		// Check if subscription requires manual renewal when it shouldn't.
		if ( $subscription->is_manual() && $gateway && $this->gateway_supports_subscriptions( $gateway ) ) {
			$warnings[] = __( 'Subscription is set to manual renewal but payment gateway supports automatic renewals.', 'doctor-subs' );
		}

		// Check payment token if applicable.
		$token_info = $this->get_payment_token_info( $subscription );
		if ( $token_info && ! $token_info['is_valid'] ) {
			$warnings[] = __( 'Payment token appears to be invalid or expired.', 'doctor-subs' );
			$is_valid   = false;
		}

		return array(
			'is_valid' => $is_valid,
			'warnings' => $warnings,
		);
	}

	/**
	 * Get payment token information.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array|null Payment token information or null if no token.
	 */
	private function get_payment_token_info( $subscription ) {
		$token_id = $subscription->get_meta( '_payment_tokens' );

		if ( ! $token_id ) {
			return null;
		}

		if ( class_exists( 'WC_Payment_Tokens' ) ) {
			$token = WC_Payment_Tokens::get( $token_id );

			if ( $token ) {
				return array(
					'id'       => $token->get_id(),
					'type'     => $token->get_type(),
					'last4'    => method_exists( $token, 'get_last4' ) ? $token->get_last4() : '',
					'expiry'   => method_exists( $token, 'get_expiry_month' ) ? $token->get_expiry_month() . '/' . $token->get_expiry_year() : '',
					'is_valid' => $token->validate(),
				);
			}
		}

		return array(
			'id'       => $token_id,
			'is_valid' => false,
			'error'    => __( 'Token not found or invalid.', 'doctor-subs' ),
		);
	}

	/**
	 * Check if gateway supports subscriptions.
	 *
	 * @since 1.0.0
	 * @param object $gateway Payment gateway instance.
	 * @return bool True if gateway supports subscriptions.
	 */
	private function gateway_supports_subscriptions( $gateway ) {
		return isset( $gateway->supports ) &&
				is_array( $gateway->supports ) &&
				in_array( 'subscriptions', $gateway->supports, true );
	}

	/**
	 * Check if billing schedule is editable.
	 *
	 * @since 1.0.0
	 * @param WC_Subscription $subscription Subscription object.
	 * @return bool True if billing schedule can be edited.
	 */
	private function is_billing_schedule_editable( $subscription ) {
		// Manual subscriptions are always editable.
		if ( $subscription->is_manual() ) {
			return true;
		}

		// Check if payment method allows schedule editing.
		$payment_method = $subscription->get_payment_method();

		if ( function_exists( 'WC' ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$gateway  = isset( $gateways[ $payment_method ] ) ? $gateways[ $payment_method ] : null;

			if ( $gateway ) {
				// Most tokenized gateways allow schedule editing.
				return $this->gateway_supports_subscriptions( $gateway );
			}
		}

		return false;
	}

	/**
	 * Format order list with basic information.
	 *
	 * @since 1.0.0
	 * @param array $order_ids Array of order IDs.
	 * @return array Formatted order information.
	 */
	private function format_order_list( $order_ids ) {
		$orders = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				$orders[] = array(
					'id'     => $order->get_id(),
					'status' => $order->get_status(),
					'total'  => $order->get_total(),
					'date'   => $this->safe_format_date( $order->get_date_created() ),
				);
			}
		}

		return $orders;
	}
}
