<?php
/**
 * Rule catalog - single source of truth for human-readable rule
 * descriptions used by the settings page (full description + bucket
 * caption) and the dashboard chip/pill tooltips (one-line summary).
 *
 * Kept separate from the rule classes so adding/changing copy doesn't
 * require touching detection logic.
 *
 * @package Dr_Subs
 * @since   2.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static catalog: rule_id => label, summary, detects, fixes, bucket.
 *
 * @since 2.1.0
 */
class DR_Subs_Rule_Catalog {

	/**
	 * Fetch the full catalog.
	 *
	 * @return array<string, array{
	 *     label: string,
	 *     summary: string,
	 *     detects: string,
	 *     fixes: string,
	 *     bucket: string,
	 * }>
	 */
	public static function all(): array {
		return array(
			'manual_renewal_drift' => array(
				'label'   => __( 'Manual-renewal drift', 'doctor-subs' ),
				'summary' => __( 'Active subs silently flipped to manual renewal despite a working Stripe card on file.', 'doctor-subs' ),
				'detects' => __( 'Active subscriptions where the manual-renewal flag is true even though the gateway is Stripe and a customer or source ID is on file. Maps directly to the four subscriptions-core bugs disclosed in April 2026 (stale dates cache, HPOS↔postmeta sync gap, wcs_create_subscription state discard, same-gateway switch).', 'doctor-subs' ),
				'fixes'   => __( 'Clears the manual-renewal flag in both the orders table and postmeta, re-stamps next_payment if past-due, and schedules a fresh renewal so WCS bills automatically again.', 'doctor-subs' ),
				'bucket'  => 'broken',
			),
			'ghost_sub'            => array(
				'label'   => __( 'Ghost', 'doctor-subs' ),
				'summary' => __( 'Active subscription with no scheduled renewal - silent revenue loss.', 'doctor-subs' ),
				'detects' => __( 'Active subscriptions whose next_payment is in the past (or unset) and have no pending Action Scheduler payment event queued. The sub looks fine in the admin but renewals never fire.', 'doctor-subs' ),
				'fixes'   => __( 'Re-enqueues a single woocommerce_scheduled_subscription_payment AS action so WCS processes the renewal on the next pass.', 'doctor-subs' ),
				'bucket'  => 'broken',
			),
			'mass_hold'            => array(
				'label'   => __( 'Mass hold', 'doctor-subs' ),
				'summary' => __( 'Many subs for the same product flipped to on-hold within an hour - a cascade.', 'doctor-subs' ),
				'detects' => __( 'Twenty or more subscriptions sharing the same product transitioning to on-hold within a single hour. Symptom of a product-edit cascade or faulty bulk operation.', 'doctor-subs' ),
				'fixes'   => __( 'Reactivates each cascade member back to its prior status. Designed for bulk-fix - fixing the rule recovers the whole cascade in one click.', 'doctor-subs' ),
				'bucket'  => 'broken',
			),
			'onhold_paid'          => array(
				'label'   => __( 'Stuck on-hold', 'doctor-subs' ),
				'summary' => __( 'Customer was charged in Stripe but the subscription is still on-hold.', 'doctor-subs' ),
				'detects' => __( 'On-hold subscriptions whose latest renewal order has Stripe-capture meta confirming the charge succeeded. The merchant may double-charge or dunning-cancel the customer needlessly.', 'doctor-subs' ),
				'fixes'   => __( 'Marks the renewal order complete (or processing for shippable goods) and flips the subscription back to active.', 'doctor-subs' ),
				'bucket'  => 'broken',
			),
			'repeated_failures'    => array(
				'label'   => __( 'Repeated fails', 'doctor-subs' ),
				'summary' => __( 'Two or more failed renewal attempts in 30 days - gateway hiccup or expired card.', 'doctor-subs' ),
				'detects' => __( 'Subscriptions with two or more failed scheduled-payment AS actions in the last 30 days. Often a transient gateway blip; sometimes a card on file that is genuinely bad.', 'doctor-subs' ),
				'fixes'   => __( 'Schedules one fresh retry. Does not loop - if the retry fails too, the merchant sees the next failure and can update the card or contact the customer.', 'doctor-subs' ),
				'bucket'  => 'risk',
			),
			'total_drift'          => array(
				'label'   => __( 'Total drift', 'doctor-subs' ),
				'summary' => __( 'Stored subscription total no longer matches the line items - flagged for manual review.', 'doctor-subs' ),
				'detects' => __( 'Subscriptions whose stored total differs from the sum of their line items, tax, shipping, and fees by more than $0.50, ignoring subs modified in the last 7 days. Drift can be intentional (tax change) or a faulty bulk recalculation that silently changes the recurring charge.', 'doctor-subs' ),
				'fixes'   => __( 'Flag-only - Doctor Subs surfaces the discrepancy and links to the subscription for manual review. Drift causes are too varied to safely auto-correct.', 'doctor-subs' ),
				'bucket'  => 'risk',
			),
		);
	}

	/**
	 * Fetch a single rule's catalog entry (or null).
	 *
	 * @param string $rule_id
	 * @return array|null
	 */
	public static function get( string $rule_id ): ?array {
		$all = self::all();
		return $all[ $rule_id ] ?? null;
	}

	/**
	 * Quick lookup of one-line summaries, keyed by rule id.
	 *
	 * @return array<string, string>
	 */
	public static function summaries(): array {
		$out = array();
		foreach ( self::all() as $rid => $info ) {
			$out[ $rid ] = (string) $info['summary'];
		}
		return $out;
	}

	/**
	 * Plain-English past-tense summary for the fix-history list.
	 * Used in place of the raw `key: value` after_state dump so the
	 * history reads like a story instead of a debug log.
	 *
	 * @param string $rule_id
	 * @return string
	 */
	public static function journal_summary( string $rule_id ): string {
		$map = array(
			'ghost_sub'            => __( 'Rescheduled the missed renewal payment.', 'doctor-subs' ),
			'repeated_failures'    => __( 'Scheduled a one-shot retry after repeated failures.', 'doctor-subs' ),
			'onhold_paid'          => __( 'Reactivated subscription after the Stripe charge was confirmed.', 'doctor-subs' ),
			'mass_hold'            => __( 'Reactivated as part of a mass-hold cascade recovery.', 'doctor-subs' ),
			'manual_renewal_drift' => __( 'Re-enabled automatic renewal and queued the next charge.', 'doctor-subs' ),
			'total_drift'          => __( 'Flagged for manual review.', 'doctor-subs' ),
		);
		return $map[ $rule_id ] ?? '';
	}

	/**
	 * Read enabled-rules state from settings. Missing entries default to true.
	 *
	 * @return array<string, bool>
	 */
	public static function enabled_map(): array {
		$settings = get_option( 'dr_subs_settings', array() );
		$saved    = isset( $settings['rules'] ) && is_array( $settings['rules'] ) ? $settings['rules'] : array();

		$out = array();
		foreach ( array_keys( self::all() ) as $rid ) {
			$out[ $rid ] = isset( $saved[ $rid ] ) ? (bool) $saved[ $rid ] : true;
		}
		return $out;
	}

	/**
	 * Is a specific rule enabled?
	 *
	 * @param string $rule_id
	 * @return bool
	 */
	public static function is_enabled( string $rule_id ): bool {
		$map = self::enabled_map();
		return ! isset( $map[ $rule_id ] ) || (bool) $map[ $rule_id ];
	}
}
