<?php
/**
 * On-Hold with Paid Renewal rule (Stripe-only in v1).
 *
 * Detects the "stuck on-hold" pattern: subscription status is 'on-hold'
 * but the latest renewal order has evidence that the Stripe charge
 * actually captured successfully. Symptom: customer paid, plugin shows
 * them as delinquent, merchant may double-charge or dunning-cancel
 * them needlessly.
 *
 * Scope in v1: Stripe only. PayPal Standard, Authorize.net, Square, and
 * the rest each have their own "renewal order paid" fingerprints (IPN
 * timestamps, CIM/ACH markers, etc) and land in v1.1 as variant rules.
 *
 * Fix: flip the renewal order to 'completed' (or 'processing' if the
 * sub has shipping) and flip the sub back to 'active'. Both changes
 * recorded as side_effects so revert is trivial.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-hold + Stripe-paid detection and fix.
 *
 * @since 2.0.0
 */
class DR_Subs_Rule_On_Hold_Paid implements DR_Subs_Rule_Interface {

	/**
	 * Order meta key WC Stripe Gateway sets when a charge is captured.
	 */
	const STRIPE_CAPTURED_META = '_stripe_charge_captured';

	/**
	 * Order meta key holding the Stripe charge/intent id.
	 */
	const STRIPE_CHARGE_META = '_stripe_charge_id';

	/**
	 * Fallback Stripe transaction id meta (older WC Stripe versions used
	 * _transaction_id for the charge id).
	 */
	const TXN_ID_META = '_transaction_id';

	/** {@inheritDoc} */
	public function id(): string {
		return 'onhold_paid';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Stuck on-hold', 'doctor-subs' );
	}

	/** {@inheritDoc} */
	public function bucket(): string {
		return 'broken';
	}

	/** {@inheritDoc} */
	public function tracked_fields(): array {
		// 'renewal_order_status' is a derived field computed in the snapshot.
		return array( 'status', 'renewal_order_id', 'renewal_order_status' );
	}

	/** {@inheritDoc} */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array {
		$matches = array();

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = (int) $sub_id;
			if ( $sub_id <= 0 ) {
				continue;
			}

			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( ! $sub || 'on-hold' !== $sub->get_status() ) {
				continue;
			}

			$renewal_order_id = $context->latest_renewal_order_for( $sub_id );
			if ( ! $renewal_order_id ) {
				// No renewal orders yet - this isn't the on-hold-paid pattern.
				continue;
			}

			$renewal_order = wc_get_order( $renewal_order_id );
			if ( ! $renewal_order ) {
				continue;
			}

			// Already completed? Then it's not stuck on-hold from paid-but-not-flipped.
			// The sub itself is still on-hold for a different reason; skip.
			$order_status = $renewal_order->get_status();
			if ( 'completed' === $order_status ) {
				continue;
			}

			// Look for Stripe capture evidence on the renewal order.
			$captured  = $this->is_stripe_captured( $renewal_order );
			$charge_id = $this->stripe_charge_id( $renewal_order );
			if ( ! $captured || empty( $charge_id ) ) {
				continue;
			}

			$matches[] = new DR_Subs_Rule_Match(
				$this->id(),
				$sub_id,
				$this->bucket(),
				array(
					'renewal_order_id'        => (int) $renewal_order_id,
					'renewal_order_status'    => $order_status,
					'stripe_charge_id'        => $charge_id,
					'renewal_total'           => $renewal_order->get_formatted_order_total(),
					'renewal_date'            => $renewal_order->get_date_created() ? $renewal_order->get_date_created()->date( 'Y-m-d' ) : '',
					'tracked_fields_snapshot' => $this->snapshot_fields( $sub, $renewal_order ),
				)
			);
		}

		return $matches;
	}

	/** {@inheritDoc} */
	public function preview_fix( DR_Subs_Rule_Match $match ): array {
		$renewal_id        = (int) ( $match->context['renewal_order_id'] ?? 0 );
		$old_order_status  = (string) ( $match->context['renewal_order_status'] ?? 'on-hold' );
		$next_order_status = $this->target_order_status( $renewal_id );

		$diff = array(
			array(
				'field'  => __( 'Sub status', 'doctor-subs' ),
				'before' => __( 'on-hold', 'doctor-subs' ),
				'after'  => __( 'active', 'doctor-subs' ),
				'emph'   => true,
			),
			array(
				'field'  => sprintf( /* translators: %d: renewal order id */ __( 'Renewal #%d', 'doctor-subs' ), $renewal_id ),
				'before' => $old_order_status,
				'after'  => $next_order_status,
				'emph'   => true,
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
			throw new RuntimeException( 'Subscription not found for on-hold-paid apply_fix.' );
		}

		$renewal_id = (int) ( $match->context['renewal_order_id'] ?? 0 );
		if ( $renewal_id <= 0 ) {
			throw new RuntimeException( 'Renewal order id missing from match context.' );
		}

		$renewal_order = wc_get_order( $renewal_id );
		if ( ! $renewal_order ) {
			throw new RuntimeException( 'Renewal order not found.' );
		}

		// State guard.
		$before_state = $this->snapshot_fields( $sub, $renewal_order );
		$snapshot     = (array) ( $match->context['tracked_fields_snapshot'] ?? array() );
		if ( ! empty( $snapshot ) && $before_state !== $snapshot ) {
			throw new RuntimeException( 'State drift: subscription or renewal changed since detection. Re-scan and try again.' );
		}

		$old_sub_status   = (string) $sub->get_status();
		$old_order_status = (string) $renewal_order->get_status();
		$new_order_status = $this->target_order_status( $renewal_id );

		// Apply order status change first - if this fails, the sub is still on-hold.
		$renewal_order->update_status(
			$new_order_status,
			/* translators: used as a note on the order when Doctor Subs flips it. */
			__( 'Doctor Subs: marked complete after matching Stripe charge capture on on-hold subscription.', 'doctor-subs' )
		);

		// Now the sub.
		$sub->update_status(
			'active',
			/* translators: order note on the subscription when Doctor Subs activates it. */
			__( 'Doctor Subs: flipped to active after matching Stripe-paid renewal.', 'doctor-subs' )
		);

		$side_effects = array(
			array(
				'type'     => 'order_status',
				'order_id' => $renewal_id,
				'from'     => $old_order_status,
				'to'       => $new_order_status,
			),
			array(
				'type'   => 'sub_status',
				'sub_id' => (int) $match->sub_id,
				'from'   => $old_sub_status,
				'to'     => 'active',
			),
		);

		$after_state = $this->snapshot_fields( $sub, $renewal_order );

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
		$success      = true;

		// Reverse order: undo sub_status before undoing order_status so the
		// sub doesn't briefly show inconsistent state.
		foreach ( array_reverse( $side_effects ) as $effect ) {
			$type = $effect['type'] ?? '';

			if ( 'sub_status' === $type ) {
				$sub_id = (int) ( $effect['sub_id'] ?? 0 );
				$from   = (string) ( $effect['from'] ?? '' );
				$sub    = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
				if ( $sub && ! empty( $from ) ) {
					$sub->update_status(
						$from,
						__( 'Doctor Subs: reverted status change.', 'doctor-subs' )
					);
					$messages[] = sprintf( 'Sub %d reverted to %s.', $sub_id, $from );
				}
			} elseif ( 'order_status' === $type ) {
				$order_id = (int) ( $effect['order_id'] ?? 0 );
				$from     = (string) ( $effect['from'] ?? '' );
				$order    = wc_get_order( $order_id );
				if ( $order && ! empty( $from ) ) {
					$order->update_status(
						$from,
						__( 'Doctor Subs: reverted status change.', 'doctor-subs' )
					);
					$messages[] = sprintf( 'Order %d reverted to %s.', $order_id, $from );
				}
			}
		}

		return array(
			'success'          => $success,
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

		$total = (string) ( $match->context['renewal_total'] ?? '' );
		$date  = (string) ( $match->context['renewal_date'] ?? '' );

		if ( ! empty( $total ) && ! empty( $date ) ) {
			return sprintf(
				/* translators: 1: first name, 2: amount, 3: date */
				__( "%1\$s's last renewal payment went through in Stripe on <em>%3\$s</em> - they were charged %2\$s. But the subscription status stayed \"on-hold\" instead of switching back to active.", 'doctor-subs' ),
				$first,
				$total,
				$date
			);
		}

		return sprintf(
			/* translators: %s: first name */
			__( "%s's last renewal payment went through in Stripe, but the subscription stayed on-hold instead of flipping back to active.", 'doctor-subs' ),
			$first
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 *
	 * This rule's tracked state spans the subscription and its latest renewal
	 * order, so the order has to be looked up again rather than passed in.
	 */
	public function current_state( int $sub_id ): array {
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
		if ( ! $sub ) {
			return array();
		}

		$context          = new DR_Subs_Scan_Context();
		$renewal_order_id = $context->latest_renewal_order_for( $sub_id );
		$renewal_order    = $renewal_order_id ? wc_get_order( $renewal_order_id ) : null;

		return $this->snapshot_fields( $sub, $renewal_order ?: null );
	}

	/**
	 * Read the tracked-field snapshot off the live sub + renewal order.
	 *
	 * @param WC_Subscription $sub
	 * @param WC_Order|null   $renewal_order
	 * @return array<string, string|int>
	 */
	private function snapshot_fields( $sub, $renewal_order ): array {
		return array(
			'status'               => (string) $sub->get_status(),
			'renewal_order_id'     => $renewal_order ? (int) $renewal_order->get_id() : 0,
			'renewal_order_status' => $renewal_order ? (string) $renewal_order->get_status() : '',
		);
	}

	/**
	 * Check the order's Stripe meta to see if the charge captured.
	 *
	 * Multiple WC Stripe Gateway versions set different metas; this checks
	 * all the common ones.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function is_stripe_captured( $order ): bool {
		$captured = $order->get_meta( self::STRIPE_CAPTURED_META );
		if ( 'yes' === $captured || 1 === (int) $captured || true === $captured ) {
			return true;
		}

		// If the gateway is WC Stripe and there's a charge id, the capture
		// almost certainly happened (WC Stripe only writes the charge id
		// after a successful charge).
		$payment_method = (string) $order->get_payment_method();
		if ( 0 === strpos( $payment_method, 'stripe' ) ) {
			return ! empty( $this->stripe_charge_id( $order ) );
		}

		return false;
	}

	/**
	 * Pull the Stripe charge / payment-intent id off the order.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private function stripe_charge_id( $order ): string {
		$charge = (string) $order->get_meta( self::STRIPE_CHARGE_META );
		if ( '' !== $charge ) {
			return $charge;
		}
		return (string) $order->get_transaction_id();
	}

	/**
	 * Decide whether the renewal order should become 'completed' or
	 * 'processing' on fix. Physical subs keep 'processing' so the
	 * merchant's fulfilment flow runs; everything else completes.
	 *
	 * @param int $order_id
	 * @return string
	 */
	private function target_order_status( int $order_id ): string {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 'completed';
		}
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->needs_shipping() ) {
				return 'processing';
			}
		}
		return 'completed';
	}
}
