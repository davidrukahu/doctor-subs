<?php
/**
 * Subscription status transition log.
 *
 * Listens on `woocommerce_subscription_status_updated` and appends a row
 * to `dr_subs_status_transitions` for every status change. The Mass Hold
 * cascade rule reads this log to detect spikes (>= N transitions to
 * on-hold within a short window sharing the same product).
 *
 * Append-only. Pruned daily on a 30-day TTL via a recurring AS action.
 *
 * @package Dr_Subs
 * @since   2.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status transition observer + log writer.
 *
 * @since 2.1.0
 */
class DR_Subs_Status_Transition_Log {

	/**
	 * AS hook for the recurring prune action.
	 */
	const PRUNE_HOOK = 'dr_subs_status_transitions_prune';

	/**
	 * AS group tag.
	 */
	const AS_GROUP = 'doctor-subs';

	/**
	 * Retention window for transition rows, in days. Cascade detection
	 * looks back at most a few days, so a 30-day buffer is generous.
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Wire up the observer hook. Called once from DR_Subs_Plugin.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'woocommerce_subscription_status_updated',
			array( __CLASS__, 'on_status_updated' ),
			10,
			3
		);
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'run_prune' ) );
	}

	/**
	 * Handler for `woocommerce_subscription_status_updated`.
	 *
	 * Captures: sub_id, from_status, to_status, plus the first sub item's
	 * product_id and variation_id (used as the cascade grouping key - a
	 * single product change typically affects all that product's subs).
	 *
	 * @param WC_Subscription $subscription
	 * @param string          $new_status
	 * @param string          $old_status
	 * @return void
	 */
	public static function on_status_updated( $subscription, $new_status, $old_status ): void {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return;
		}

		$sub_id = (int) $subscription->get_id();
		if ( $sub_id <= 0 ) {
			return;
		}

		list( $product_id, $variation_id ) = self::extract_product_keys( $subscription );

		global $wpdb;
		$table = DR_Subs_Migration::status_transitions_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transition log write.
		$wpdb->insert(
			$table,
			array(
				'sub_id'          => $sub_id,
				'from_status'     => (string) $old_status,
				'to_status'       => (string) $new_status,
				'product_id'      => $product_id,
				'variation_id'    => $variation_id,
				'transitioned_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable
	}

	/**
	 * Pull the first subscription item's product + variation IDs.
	 *
	 * Returns [0, 0] if the sub has no items (extremely rare; degenerate
	 * data). Cascade grouping for such subs falls back to product_id 0
	 * which the rule treats as ungroupable.
	 *
	 * @param WC_Subscription $subscription
	 * @return array{0:int,1:int}
	 */
	private static function extract_product_keys( $subscription ): array {
		if ( ! method_exists( $subscription, 'get_items' ) ) {
			return array( 0, 0 );
		}

		foreach ( $subscription->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			return array( $product_id, $variation_id );
		}

		return array( 0, 0 );
	}

	/**
	 * Recurring prune. Deletes transitions older than RETENTION_DAYS.
	 *
	 * @return void
	 */
	public static function run_prune(): void {
		global $wpdb;
		$table  = DR_Subs_Migration::status_transitions_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- prune.
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE transitioned_at < %s', $table, $cutoff )
		);
		// phpcs:enable
	}

	/**
	 * Schedule the recurring prune. Idempotent.
	 *
	 * @return void
	 */
	public static function schedule_prune(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::PRUNE_HOOK, array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				self::PRUNE_HOOK,
				array(),
				self::AS_GROUP
			);
		}
	}

	/**
	 * Unschedule the prune action. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_prune(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::PRUNE_HOOK, array(), self::AS_GROUP );
		}
	}
}
