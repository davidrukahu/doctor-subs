<?php
/**
 * Mass Hold Cascade rule.
 *
 * Detects spikes of subscriptions flipping to `on-hold` within a short
 * window that share the same product. Real-world signal: a merchant
 * changes a simple product to a variable product (or runs faulty bulk
 * code) and 50-1,300 subs land on Hold simultaneously. Without this
 * rule the merchant only finds out customer-by-customer.
 *
 * Detection source: `dr_subs_status_transitions` log written by
 * DR_Subs_Status_Transition_Log on every status change. Cascade =
 * >= MIN_CASCADE_SIZE transitions to `on-hold` for the same product
 * within WINDOW_SECONDS, looking back LOOKBACK_DAYS.
 *
 * Match shape: one match per affected sub, all sharing a `cascade_id`
 * so the bulk-fix UI can group them. Fix flips a single sub from
 * `on-hold` back to its prior status (typically `active`); revert flips
 * it back. Bulk fix == standard "fix all matches of this rule".
 *
 * @package Dr_Subs
 * @since   2.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mass Hold cascade detection + per-sub reactivate.
 *
 * @since 2.1.0
 */
class DR_Subs_Rule_Mass_Hold implements DR_Subs_Rule_Interface {

	/**
	 * Minimum number of same-product on-hold transitions in the window
	 * required to qualify as a cascade. Below this threshold the spike
	 * is treated as ordinary churn.
	 */
	const MIN_CASCADE_SIZE = 20;

	/**
	 * Cascade window length in seconds (transitions clustered tighter
	 * than this share a cascade). 1 hour covers the realistic burst
	 * shape of a product-change cascade.
	 */
	const WINDOW_SECONDS = 3600;

	/**
	 * How far back to scan transitions for cascade detection.
	 */
	const LOOKBACK_DAYS = 7;

	/**
	 * Per-instance cache: spl_object_hash(scan_context) => map of
	 * sub_id => cascade meta. Rebuilt on first detect_batch call when a
	 * fresh scan_context is observed.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $cascade_index_cache = array();

	/** {@inheritDoc} */
	public function id(): string {
		return 'mass_hold';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Mass hold', 'doctor-subs' );
	}

	/** {@inheritDoc} */
	public function bucket(): string {
		return 'broken';
	}

	/** {@inheritDoc} */
	public function tracked_fields(): array {
		return array( 'status' );
	}

	/** {@inheritDoc} */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array {
		$matches = array();
		$index   = $this->cascade_index_for( $context );
		if ( empty( $index ) ) {
			return $matches;
		}

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = (int) $sub_id;
			if ( $sub_id <= 0 || ! isset( $index[ $sub_id ] ) ) {
				continue;
			}

			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( ! $sub || 'on-hold' !== $sub->get_status() ) {
				// Cascade member already recovered (status no longer on-hold).
				// Drop it from the match set; nothing to fix.
				continue;
			}

			$meta = $index[ $sub_id ];

			$matches[] = new DR_Subs_Rule_Match(
				$this->id(),
				$sub_id,
				$this->bucket(),
				array(
					'cascade_id'              => (string) $meta['cascade_id'],
					'cascade_size'            => (int) $meta['cascade_size'],
					'cascade_product_id'      => (int) $meta['product_id'],
					'cascade_window_start'    => (string) $meta['window_start'],
					'cascade_window_end'      => (string) $meta['window_end'],
					'from_status'             => (string) $meta['from_status'],
					'tracked_fields_snapshot' => $this->snapshot_fields( $sub ),
				)
			);
		}

		return $matches;
	}

	/** {@inheritDoc} */
	public function preview_fix( DR_Subs_Rule_Match $match ): array {
		$from = (string) ( $match->context['from_status'] ?? 'active' );
		// Empty / unknown prior status: default reactivation target is 'active'.
		$target = ( '' === $from || 'on-hold' === $from ) ? 'active' : $from;

		$diff = array(
			array(
				'field'  => __( 'Sub status', 'doctor-subs' ),
				'before' => __( 'on-hold', 'doctor-subs' ),
				'after'  => $target,
				'emph'   => true,
			),
			array(
				'field'     => __( 'Cascade', 'doctor-subs' ),
				'before'    => sprintf(
					/* translators: %d: cascade size */
					_n( '%d sub flipped to on-hold together', '%d subs flipped to on-hold together', (int) ( $match->context['cascade_size'] ?? 0 ), 'doctor-subs' ),
					(int) ( $match->context['cascade_size'] ?? 0 )
				),
				'after'     => __( 'fixing this one; bulk-fix recovers the rest', 'doctor-subs' ),
				'unchanged' => true,
			),
		);

		return array(
			'narrative'        => $this->narrate( $match ),
			'diff'             => $diff,
			'already_executed' => false,
		);
	}

	/** {@inheritDoc} */
	public function apply_fix( DR_Subs_Rule_Match $match ): array {
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $match->sub_id ) : null;
		if ( ! $sub ) {
			throw new RuntimeException( 'Subscription not found for mass-hold apply_fix.' );
		}

		$before_state = $this->snapshot_fields( $sub );
		$snapshot     = (array) ( $match->context['tracked_fields_snapshot'] ?? array() );
		if ( ! empty( $snapshot ) && $before_state !== $snapshot ) {
			throw new RuntimeException( 'State drift: subscription changed since detection. Re-scan and try again.' );
		}

		$old_status = (string) $sub->get_status();
		if ( 'on-hold' !== $old_status ) {
			throw new RuntimeException( 'Subscription is no longer on-hold.' );
		}

		$from       = (string) ( $match->context['from_status'] ?? '' );
		$new_status = ( '' === $from || 'on-hold' === $from ) ? 'active' : $from;

		$sub->update_status(
			$new_status,
			sprintf(
				/* translators: 1: cascade size, 2: target status */
				__( 'Doctor Subs: reactivated as part of a %1$d-sub mass-hold cascade (restoring to %2$s).', 'doctor-subs' ),
				(int) ( $match->context['cascade_size'] ?? 0 ),
				$new_status
			)
		);

		$side_effects = array(
			array(
				'type'   => 'sub_status',
				'sub_id' => (int) $match->sub_id,
				'from'   => $old_status,
				'to'     => $new_status,
			),
		);

		$after_state = $this->snapshot_fields( $sub );

		return array(
			'before_state'      => $before_state,
			'before_state_hash' => DR_Subs_Rule_Match::hash_state( $before_state ),
			'after_state'       => $after_state,
			'side_effects'      => $side_effects,
		);
	}

	/** {@inheritDoc} */
	public function revert_fix( $entry ): array {
		$side_effects = json_decode( (string) $entry->side_effects, true );
		$side_effects = is_array( $side_effects ) ? $side_effects : array();
		$messages     = array();

		foreach ( array_reverse( $side_effects ) as $effect ) {
			if ( ! is_array( $effect ) || 'sub_status' !== ( $effect['type'] ?? '' ) ) {
				continue;
			}
			$sub_id = (int) ( $effect['sub_id'] ?? 0 );
			$from   = (string) ( $effect['from'] ?? '' );
			$sub    = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( $sub && '' !== $from ) {
				$sub->update_status(
					$from,
					__( 'Doctor Subs: reverted mass-hold reactivation.', 'doctor-subs' )
				);
				$messages[] = sprintf( 'Sub %d reverted to %s.', $sub_id, $from );
			}
		}

		return array(
			'success'          => true,
			'message'          => implode( ' ', $messages ),
			'already_executed' => false,
			'drift'            => array(),
		);
	}

	/** {@inheritDoc} */
	public function narrate( DR_Subs_Rule_Match $match ): string {
		$sub   = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $match->sub_id ) : null;
		$first = $sub ? $sub->get_billing_first_name() : '';
		if ( empty( $first ) ) {
			$first = __( 'This customer', 'doctor-subs' );
		}

		$size         = (int) ( $match->context['cascade_size'] ?? 0 );
		$product_id   = (int) ( $match->context['cascade_product_id'] ?? 0 );
		$product      = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$product_name = $product ? $product->get_name() : '';

		if ( $product_name && $size >= self::MIN_CASCADE_SIZE ) {
			return sprintf(
				/* translators: 1: first name, 2: cascade size, 3: product name */
				__( "%1\$s's subscription went on-hold as part of a wave - %2\$d subs for <em>%3\$s</em> all flipped to on-hold within an hour. Looks like a product change or bulk operation cascaded into the live subscriptions. Reactivating restores the customer's billing schedule.", 'doctor-subs' ),
				$first,
				$size,
				$product_name
			);
		}

		return sprintf(
			/* translators: 1: first name, 2: cascade size */
			__( "%1\$s's subscription went on-hold as part of a wave - %2\$d subs flipped to on-hold within an hour, sharing the same product. Likely a product change or bulk operation that cascaded into live subscriptions.", 'doctor-subs' ),
			$first,
			$size
		);
	}

	/**
	 * Build / fetch the cascade index for a given scan context.
	 *
	 * Returns map: sub_id => [
	 *   'cascade_id'   => string,
	 *   'cascade_size' => int,
	 *   'product_id'   => int,
	 *   'window_start' => 'Y-m-d H:i:s' (UTC),
	 *   'window_end'   => 'Y-m-d H:i:s' (UTC),
	 *   'from_status'  => string,
	 * ]
	 *
	 * Algorithm: query all transitions to `on-hold` in the lookback
	 * window; sort by (product_id, transitioned_at); slide a 1-hour
	 * window per product; each window with >= MIN_CASCADE_SIZE rows
	 * becomes a cascade.
	 *
	 * @param DR_Subs_Scan_Context $context
	 * @return array<int, array<string, mixed>>
	 */
	private function cascade_index_for( DR_Subs_Scan_Context $context ): array {
		$key = spl_object_hash( $context );
		if ( isset( $this->cascade_index_cache[ $key ] ) ) {
			return $this->cascade_index_cache[ $key ];
		}

		$this->cascade_index_cache[ $key ] = $this->build_cascade_index();
		return $this->cascade_index_cache[ $key ];
	}

	/**
	 * Build cascade index from the transitions table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_cascade_index(): array {
		global $wpdb;
		$table = DR_Subs_Migration::status_transitions_table();
		if ( empty( $table ) ) {
			return array();
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::LOOKBACK_DAYS * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cascade scan; results memoized per scan context.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sub_id, from_status, product_id, transitioned_at
					FROM %i
					WHERE to_status = %s
					  AND transitioned_at >= %s
					  AND product_id > 0
					ORDER BY product_id ASC, transitioned_at ASC',
				$table,
				'on-hold',
				$cutoff
			)
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return array();
		}

		$by_product = array();
		foreach ( $rows as $row ) {
			$pid                  = (int) $row->product_id;
			$by_product[ $pid ][] = array(
				'sub_id'      => (int) $row->sub_id,
				'from_status' => (string) $row->from_status,
				'ts'          => (int) strtotime( $row->transitioned_at . ' UTC' ),
				'at'          => (string) $row->transitioned_at,
			);
		}

		$index = array();

		foreach ( $by_product as $product_id => $events ) {
			// Sliding window per product. Each cascade is the maximal window
			// of length WINDOW_SECONDS containing >= MIN_CASCADE_SIZE events.
			// We collapse overlapping windows by greedy expansion.
			$n     = count( $events );
			$start = 0;
			while ( $start < $n ) {
				$end = $start;
				while ( $end + 1 < $n && ( $events[ $end + 1 ]['ts'] - $events[ $start ]['ts'] ) <= self::WINDOW_SECONDS ) {
					++$end;
				}
				$cluster_size = ( $end - $start ) + 1;

				if ( $cluster_size >= self::MIN_CASCADE_SIZE ) {
					$cascade_id   = $this->build_cascade_id( (int) $product_id, $events[ $start ]['ts'] );
					$window_start = $events[ $start ]['at'];
					$window_end   = $events[ $end ]['at'];

					for ( $i = $start; $i <= $end; $i++ ) {
						$sid = (int) $events[ $i ]['sub_id'];
						// First cascade wins if a sub appears in overlapping clusters.
						if ( ! isset( $index[ $sid ] ) ) {
							$index[ $sid ] = array(
								'cascade_id'   => $cascade_id,
								'cascade_size' => $cluster_size,
								'product_id'   => (int) $product_id,
								'window_start' => $window_start,
								'window_end'   => $window_end,
								'from_status'  => (string) $events[ $i ]['from_status'],
							);
						}
					}

					// Advance past this cluster.
					$start = $end + 1;
				} else {
					++$start;
				}
			}
		}

		return $index;
	}

	/**
	 * Stable cascade identifier (product + window-start). Same product
	 * cascading twice on different days produces different ids.
	 *
	 * @param int $product_id
	 * @param int $window_start_ts
	 * @return string
	 */
	private function build_cascade_id( int $product_id, int $window_start_ts ): string {
		return substr( hash( 'sha256', $product_id . ':' . $window_start_ts ), 0, 16 );
	}

	/**
	 * Tracked-field snapshot for the state guard.
	 *
	 * @param WC_Subscription $sub
	 * @return array<string, string>
	 */
	private function snapshot_fields( $sub ): array {
		return array(
			'status' => (string) $sub->get_status(),
		);
	}
}
