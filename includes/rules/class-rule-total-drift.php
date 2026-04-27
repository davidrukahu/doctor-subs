<?php
/**
 * Total Drift rule - flag-only.
 *
 * Detects subscriptions whose stored `total` no longer matches the sum
 * of their line items (subtotal + tax + shipping + fees - discounts).
 * Real-world signal: faulty bulk recalculation code or a botched manual
 * edit silently changed the recurring charge amount, and the merchant
 * doesn't notice until renewals start charging the wrong figure.
 *
 * Flag-only by design (v2.1): there are too many legitimate causes of
 * drift (tax rate change, coupon expiry, currency rounding, intentional
 * proration) for an auto-fix to be safe. The match surfaces the
 * discrepancy in the dashboard with a manual-review prompt; apply_fix
 * throws "manual review required". A safe auto-fix may land in a later
 * version once we have ticket data on which drift shapes are routinely
 * fixable.
 *
 * @package Dr_Subs
 * @since   2.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal-consistency check: sub total vs recomputed line items.
 *
 * @since 2.1.0
 */
class DR_Subs_Rule_Total_Drift implements DR_Subs_Rule_Interface {

	/**
	 * Tolerance for total comparison, in the store currency's smallest
	 * unit. 0.50 absorbs penny rounding + small tax recalcs without
	 * masking the multi-dollar discrepancies the rule cares about.
	 */
	const TOLERANCE = 0.50;

	/**
	 * Skip subs modified within this many days. Recent edits, switches,
	 * and prorations are usually intentional and noisy.
	 */
	const RECENT_CHANGE_DAYS = 7;

	/** {@inheritDoc} */
	public function id(): string {
		return 'total_drift';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Total drift', 'doctor-subs' );
	}

	/** {@inheritDoc} */
	public function bucket(): string {
		// 'risk' rather than 'broken' - drift is suspicious but may be
		// intentional. Flag for review, don't auto-fix.
		return 'risk';
	}

	/** {@inheritDoc} */
	public function tracked_fields(): array {
		return array( 'status', 'total' );
	}

	/** {@inheritDoc} */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array {
		$matches = array();
		$now     = time();
		$cutoff  = $now - ( self::RECENT_CHANGE_DAYS * DAY_IN_SECONDS );

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = (int) $sub_id;
			if ( $sub_id <= 0 ) {
				continue;
			}

			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( ! $sub ) {
				continue;
			}

			$status = $sub->get_status();
			if ( in_array( $status, array( 'cancelled', 'expired', 'trash', 'pending-cancel' ), true ) ) {
				continue;
			}

			// Skip recently modified subs (likely intentional change).
			$modified = $sub->get_date_modified();
			if ( $modified && $modified->getTimestamp() >= $cutoff ) {
				continue;
			}

			$stored_total = (float) $sub->get_total();
			$expected     = $this->expected_total( $sub );
			$delta        = abs( $stored_total - $expected );

			if ( $delta <= self::TOLERANCE ) {
				continue;
			}

			$matches[] = new DR_Subs_Rule_Match(
				$this->id(),
				$sub_id,
				$this->bucket(),
				array(
					'stored_total'            => $stored_total,
					'expected_total'          => $expected,
					'delta'                   => $delta,
					'currency'                => (string) $sub->get_currency(),
					'tracked_fields_snapshot' => $this->snapshot_fields( $sub ),
				)
			);
		}

		return $matches;
	}

	/** {@inheritDoc} */
	public function preview_fix( DR_Subs_Rule_Match $match ): array {
		$stored   = (float) ( $match->context['stored_total'] ?? 0 );
		$expected = (float) ( $match->context['expected_total'] ?? 0 );
		$currency = (string) ( $match->context['currency'] ?? '' );

		$diff = array(
			array(
				'field'     => __( 'Stored total', 'doctor-subs' ),
				'before'    => $this->format_money( $stored, $currency ),
				'after'     => $this->format_money( $stored, $currency ),
				'unchanged' => true,
			),
			array(
				'field'     => __( 'Expected from line items', 'doctor-subs' ),
				'before'    => $this->format_money( $expected, $currency ),
				'after'     => $this->format_money( $expected, $currency ),
				'unchanged' => true,
			),
			array(
				'field'  => __( 'Action', 'doctor-subs' ),
				'before' => __( 'discrepancy detected', 'doctor-subs' ),
				'after'  => __( 'manual review required', 'doctor-subs' ),
				'emph'   => true,
			),
		);

		return array(
			'narrative'        => $this->narrate( $match ),
			'diff'             => $diff,
			'already_executed' => false,
			// UI hint that this rule has no automatic apply path.
			'manual_only'      => true,
		);
	}

	/** {@inheritDoc} */
	public function apply_fix( DR_Subs_Rule_Match $match ): array {
		throw new RuntimeException(
			'Total Drift is flag-only: automatic correction is not safe because legitimate drift causes (tax recalc, coupon, intentional adjustment) are indistinguishable from broken drift. Edit the subscription manually after reviewing the line items.'
		);
	}

	/** {@inheritDoc} */
	public function revert_fix( $entry ): array {
		// Nothing was applied, so nothing to revert. Return success-with-noop
		// so the journal UI doesn't error if it ever sees a stale entry.
		return array(
			'success'          => true,
			'message'          => 'Total Drift is flag-only; no fix was applied, so revert is a no-op.',
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

		$stored   = (float) ( $match->context['stored_total'] ?? 0 );
		$expected = (float) ( $match->context['expected_total'] ?? 0 );
		$currency = (string) ( $match->context['currency'] ?? '' );
		$delta    = (float) ( $match->context['delta'] ?? 0 );

		return sprintf(
			/* translators: 1: first name, 2: stored total, 3: expected total, 4: delta */
			__( "%1\$s's subscription total is stored as <em>%2\$s</em>, but the line items add up to <em>%3\$s</em> - a %4\$s gap. Could be an intentional adjustment, a faulty bulk update, or a tax-rate change that didn't propagate. Worth opening the sub and confirming the recurring charge is what you expect before the next renewal.", 'doctor-subs' ),
			$first,
			$this->format_money( $stored, $currency ),
			$this->format_money( $expected, $currency ),
			$this->format_money( $delta, $currency )
		);
	}

	/**
	 * Recompute the expected total from current line items.
	 *
	 * Mirrors WC's standard total formula: line item totals + tax +
	 * shipping totals + fee totals - discount total. Reads stored values
	 * off the line items rather than re-pricing from products (so we
	 * compare against what the sub itself believes the items cost).
	 *
	 * @param WC_Subscription $sub
	 * @return float
	 */
	private function expected_total( $sub ): float {
		$items_total    = 0.0;
		$items_tax      = 0.0;
		$shipping_total = (float) $sub->get_shipping_total();
		$shipping_tax   = (float) $sub->get_shipping_tax();
		$fee_total      = 0.0;
		$fee_tax        = 0.0;
		$discount       = (float) $sub->get_discount_total();
		$discount_tax   = (float) $sub->get_discount_tax();

		foreach ( $sub->get_items( 'line_item' ) as $item ) {
			if ( method_exists( $item, 'get_total' ) ) {
				$items_total += (float) $item->get_total();
			}
			if ( method_exists( $item, 'get_total_tax' ) ) {
				$items_tax += (float) $item->get_total_tax();
			}
		}

		foreach ( $sub->get_items( 'fee' ) as $fee ) {
			if ( method_exists( $fee, 'get_total' ) ) {
				$fee_total += (float) $fee->get_total();
			}
			if ( method_exists( $fee, 'get_total_tax' ) ) {
				$fee_tax += (float) $fee->get_total_tax();
			}
		}

		// Discounts are stored already-applied to item totals in modern WC,
		// so don't subtract again - but keep $discount/$discount_tax in
		// scope for future-proofing in case a sub uses legacy discount
		// accounting.
		unset( $discount, $discount_tax );

		$expected = $items_total + $items_tax + $shipping_total + $shipping_tax + $fee_total + $fee_tax;

		return (float) wc_format_decimal( $expected, wc_get_price_decimals() );
	}

	/**
	 * Format an amount in the sub's currency for narration / diff.
	 *
	 * @param float  $amount
	 * @param string $currency
	 * @return string
	 */
	private function format_money( float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			$formatted = wc_price( $amount, array( 'currency' => $currency ) );
			return wp_strip_all_tags( $formatted );
		}
		return number_format( $amount, 2 ) . ( $currency ? ' ' . $currency : '' );
	}

	/**
	 * Tracked-field snapshot.
	 *
	 * @param WC_Subscription $sub
	 * @return array<string, string>
	 */
	private function snapshot_fields( $sub ): array {
		return array(
			'status' => (string) $sub->get_status(),
			'total'  => (string) $sub->get_total(),
		);
	}
}
