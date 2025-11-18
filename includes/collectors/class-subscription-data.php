<?php
declare( strict_types=1 );
/**
 * Subscription Data Collector
 *
 * @package Dr_Subs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects subscription data for analysis.
 *
 * @since 1.0.0
 */
class WCST_Subscription_Data {

	/**
	 * Safely format a date that might be a DateTime object or string (HPOS compatibility).
	 *
	 * @since 1.0.0
	 * @param mixed $date Date object or string.
	 * @return string Formatted date or null.
	 */
	private function safe_format_date( $date ) {
		if ( empty( $date ) ) {
			return null;
		}

		if ( is_object( $date ) && method_exists( $date, 'format' ) ) {
			return $date->format( 'Y-m-d H:i:s' );
		}

		if ( is_string( $date ) ) {
			return $date;
		}

		return null;
	}

	/**
	 * Search for subscriptions.
	 *
	 * @since 1.0.0
	 * @param string $search_term Search term (subscription ID or customer email).
	 * @return array Search results.
	 */
	public function search_subscriptions( $search_term ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return array();
		}

		$results = array();

		// Search by subscription ID.
		if ( is_numeric( $search_term ) ) {
			$subscription = wcs_get_subscription( $search_term );
			if ( $subscription ) {
				$results[] = array(
					'id'           => $subscription->get_id(),
					'title'        => sprintf(
						/* translators: %d: subscription ID */
						__( 'Subscription #%d', 'doctor-subs' ),
						$subscription->get_id()
					),
					'status'       => $subscription->get_status(),
					'customer'     => $subscription->get_formatted_billing_full_name(),
					'customer_id'  => $subscription->get_customer_id(),
					'email'        => $subscription->get_billing_email(),
					'total'        => $subscription->get_formatted_order_total(),
					'next_payment' => $this->safe_format_date( $subscription->get_date( 'next_payment' ) ),
				);
			}
		}

		// Search by customer email.
		if ( is_email( $search_term ) ) {
			$email_results = $this->search_by_email( $search_term );
			$results       = array_merge( $results, $email_results );
		}

		// Remove duplicates based on subscription ID.
		$unique_results = array();
		$seen_ids       = array();

		foreach ( $results as $result ) {
			if ( ! in_array( $result['id'], $seen_ids, true ) ) {
				$unique_results[] = $result;
				$seen_ids[]       = $result['id'];
			}
		}

		return $unique_results;
	}

	/**
	 * Search subscriptions by customer email.
	 *
	 * @since 1.0.0
	 * @param string $email Customer email address.
	 * @return array Search results.
	 */
	private function search_by_email( $email ) {
		global $wpdb;

		$results = array();

		// Search in postmeta for billing email.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary for subscription search functionality.
		$subscription_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID 
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_subscription'
				AND pm.meta_key = '_billing_email'
				AND pm.meta_value = %s
				ORDER BY p.post_date DESC
				LIMIT 10",
				$email
			)
		);

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( $subscription ) {
				$results[] = array(
					'id'           => $subscription->get_id(),
					'title'        => sprintf(
						/* translators: %d: subscription ID */
						__( 'Subscription #%d', 'doctor-subs' ),
						$subscription->get_id()
					),
					'status'       => $subscription->get_status(),
					'customer'     => $subscription->get_formatted_billing_full_name(),
					'customer_id'  => $subscription->get_customer_id(),
					'email'        => $subscription->get_billing_email(),
					'total'        => $subscription->get_formatted_order_total(),
					'next_payment' => $this->safe_format_date( $subscription->get_date( 'next_payment' ) ),
				);
			}
		}

		return $results;
	}

	/**
	 * Get basic subscription information.
	 *
	 * @since 1.0.0
	 * @param int $subscription_id Subscription ID.
	 * @return array|false Basic subscription info or false if not found.
	 */
	public function get_basic_info( $subscription_id ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return false;
		}

		return array(
			'id'             => $subscription->get_id(),
			'status'         => $subscription->get_status(),
			'date_created'   => $this->safe_format_date( $subscription->get_date( 'date_created' ) ),
			'customer_id'    => $subscription->get_customer_id(),
			'customer_name'  => $subscription->get_formatted_billing_full_name(),
			'customer_email' => $subscription->get_billing_email(),
			'total'          => $subscription->get_total(),
			'currency'       => $subscription->get_currency(),
			'payment_method' => $subscription->get_payment_method(),
			'next_payment'   => $this->safe_format_date( $subscription->get_date( 'next_payment' ) ),
		);
	}
}
