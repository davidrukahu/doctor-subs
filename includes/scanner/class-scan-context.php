<?php
/**
 * Shared scan context - pre-built indexes so rules' detect_batch()
 * never needs to do N+1 per-sub queries.
 *
 * Built once per scanner run. Holds:
 *  - pending_as_by_sub:      sub_id => action_id (latest pending
 *                            woocommerce_scheduled_subscription_payment)
 *  - failed_as_ids_by_sub:   sub_id => [action_id, ...] (failed payment
 *                            actions in the last 30 days)
 *  - failed_as_count_by_sub: sub_id => int
 *  - renewal_orders_by_sub:  sub_id => [order_id, ...] (newest first)
 *
 * Each build_* method makes exactly one Action Scheduler / WC query,
 * then buckets results in PHP. This replaces the v1 N+1 pattern
 * (args LIKE '%subscription_id%') which also had a false-positive bug
 * (sub 1 matching sub 11/111).
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared scan context with pre-built indexes.
 *
 * @since 2.0.0
 */
class DR_Subs_Scan_Context {

	/**
	 * Look-back window for "recent" failed actions, in days.
	 */
	const FAILED_WINDOW_DAYS = 30;

	/**
	 * Subscription ID => pending payment action ID.
	 *
	 * @var array<int, int>
	 */
	public array $pending_as_by_sub = array();

	/**
	 * Subscription ID => list of failed payment action IDs (in the
	 * FAILED_WINDOW_DAYS window).
	 *
	 * @var array<int, array<int, int>>
	 */
	public array $failed_as_ids_by_sub = array();

	/**
	 * Subscription ID => failed-action count in the window.
	 *
	 * @var array<int, int>
	 */
	public array $failed_as_count_by_sub = array();

	/**
	 * Subscription ID => list of renewal order IDs, newest first.
	 * Populated lazily on first access per sub; rules that don't care
	 * about renewal orders don't pay the cost.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $renewal_orders_cache = array();

	/**
	 * Constructor - builds all non-lazy indexes.
	 *
	 * @param bool $auto_build When false, caller must invoke build()
	 *                         manually (used by tests that want to
	 *                         populate indexes directly).
	 */
	public function __construct( bool $auto_build = true ) {
		if ( $auto_build ) {
			$this->build();
		}
	}

	/**
	 * Build all indexes.
	 *
	 * @return void
	 */
	public function build(): void {
		$this->build_pending_as_index();
		$this->build_failed_as_index();
	}

	/**
	 * One query for all pending `woocommerce_scheduled_subscription_payment`
	 * actions, bucketed by sub_id in PHP.
	 *
	 * @return void
	 */
	private function build_pending_as_index(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}
		$store = ActionScheduler_Store::instance();
		$ids   = $store->query_actions(
			array(
				'hook'     => 'woocommerce_scheduled_subscription_payment',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		foreach ( (array) $ids as $action_id ) {
			$action = $store->fetch_action( $action_id );
			if ( ! $action ) {
				continue;
			}
			$args   = $action->get_args();
			$sub_id = (int) ( $args[0] ?? 0 );
			if ( $sub_id > 0 ) {
				// First one wins (AS query returns ordered; keep earliest-pending).
				if ( ! isset( $this->pending_as_by_sub[ $sub_id ] ) ) {
					$this->pending_as_by_sub[ $sub_id ] = (int) $action_id;
				}
			}
		}
	}

	/**
	 * One query for all failed payment actions in the window, bucketed
	 * by sub_id in PHP.
	 *
	 * @return void
	 */
	private function build_failed_as_index(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}
		$store  = ActionScheduler_Store::instance();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::FAILED_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$ids = $store->query_actions(
			array(
				'hook'         => 'woocommerce_scheduled_subscription_payment',
				'status'       => ActionScheduler_Store::STATUS_FAILED,
				'date'         => $cutoff,
				'date_compare' => '>',
				'per_page'     => -1,
			)
		);
		foreach ( (array) $ids as $action_id ) {
			$action = $store->fetch_action( $action_id );
			if ( ! $action ) {
				continue;
			}
			$args   = $action->get_args();
			$sub_id = (int) ( $args[0] ?? 0 );
			if ( $sub_id > 0 ) {
				$this->failed_as_ids_by_sub[ $sub_id ][]    = (int) $action_id;
				$this->failed_as_count_by_sub[ $sub_id ] = ( $this->failed_as_count_by_sub[ $sub_id ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * Get the pending payment action ID for a sub, if any.
	 *
	 * @param int $sub_id
	 * @return int|null
	 */
	public function pending_as_for( int $sub_id ): ?int {
		return $this->pending_as_by_sub[ $sub_id ] ?? null;
	}

	/**
	 * Does this sub have a pending payment action scheduled?
	 *
	 * @param int $sub_id
	 * @return bool
	 */
	public function has_pending_as( int $sub_id ): bool {
		return isset( $this->pending_as_by_sub[ $sub_id ] );
	}

	/**
	 * Count of failed payment actions for a sub in the window.
	 *
	 * @param int $sub_id
	 * @return int
	 */
	public function failed_as_count_for( int $sub_id ): int {
		return (int) ( $this->failed_as_count_by_sub[ $sub_id ] ?? 0 );
	}

	/**
	 * Failed payment action IDs for a sub in the window.
	 *
	 * @param int $sub_id
	 * @return array<int, int>
	 */
	public function failed_as_ids_for( int $sub_id ): array {
		return (array) ( $this->failed_as_ids_by_sub[ $sub_id ] ?? array() );
	}

	/**
	 * Renewal orders for a sub. Fetched lazily on first request.
	 *
	 * @param int $sub_id
	 * @return array<int, int>  Order IDs, newest first.
	 */
	public function renewal_orders_for( int $sub_id ): array {
		if ( ! isset( $this->renewal_orders_cache[ $sub_id ] ) ) {
			$this->renewal_orders_cache[ $sub_id ] = array();
			if ( function_exists( 'wcs_get_subscription' ) ) {
				$sub = wcs_get_subscription( $sub_id );
				if ( $sub ) {
					$ids = $sub->get_related_orders( 'ids', 'renewal' );
					if ( is_array( $ids ) ) {
						rsort( $ids, SORT_NUMERIC );
						$this->renewal_orders_cache[ $sub_id ] = array_map( 'intval', $ids );
					}
				}
			}
		}
		return $this->renewal_orders_cache[ $sub_id ];
	}

	/**
	 * Most recent renewal order for a sub.
	 *
	 * @param int $sub_id
	 * @return int|null
	 */
	public function latest_renewal_order_for( int $sub_id ): ?int {
		$orders = $this->renewal_orders_for( $sub_id );
		return $orders[0] ?? null;
	}
}
