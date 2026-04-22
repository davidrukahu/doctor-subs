<?php
/**
 * Ghost Sub rule.
 *
 * Detects active subscriptions whose `next_payment` is in the past but
 * which have no pending `woocommerce_scheduled_subscription_payment`
 * Action Scheduler event queued. Symptom: sub looks active in the admin
 * but renewals never fire; merchant is losing revenue silently.
 *
 * Fix: re-enqueue the missing AS action at T+60s so WCS's handler
 * processes the renewal on the next AS pass.
 *
 * Revert: unschedule the AS action (if it has not already executed).
 * If it has executed, the revert flags `already_executed = true` so
 * the UX can show the explicit "the payment ran, reverting cannot
 * refund" warning.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ghost Sub detection + fix + revert.
 *
 * @since 2.0.0
 */
class DR_Subs_Rule_Ghost_Sub implements DR_Subs_Rule_Interface {

	/**
	 * AS group tag for actions we enqueue. Distinct from WCS's own group
	 * so Doctor Subs actions show up clearly in the AS admin filter.
	 */
	const AS_GROUP = 'doctor-subs';

	/**
	 * The WCS payment hook we schedule + revert.
	 */
	const PAYMENT_HOOK = 'woocommerce_scheduled_subscription_payment';

	/**
	 * Fix rescheduling delay (seconds from now).
	 *
	 * Short enough that the merchant sees the effect within 1-2 minutes
	 * (AS default poll = 1 min), long enough that the AS queue has time
	 * to register the action before polling sweeps.
	 */
	const RESCHEDULE_DELAY = 60;

	/** {@inheritDoc} */
	public function id(): string {
		return 'ghost_sub';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Ghost', 'doctor-subs' );
	}

	/** {@inheritDoc} */
	public function bucket(): string {
		return 'broken';
	}

	/** {@inheritDoc} */
	public function tracked_fields(): array {
		return array( 'status', 'next_payment' );
	}

	/** {@inheritDoc} */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array {
		$matches = array();
		$now     = time();

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = (int) $sub_id;
			if ( $sub_id <= 0 ) {
				continue;
			}

			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( ! $sub || 'active' !== $sub->get_status() ) {
				continue;
			}

			$next_payment = $sub->get_date( 'next_payment' );
			if ( empty( $next_payment ) ) {
				continue;
			}

			$next_ts = strtotime( $next_payment . ' UTC' );
			if ( ! $next_ts || $next_ts >= $now ) {
				// next_payment is in the future - this is a healthy sub.
				continue;
			}

			if ( $context->has_pending_as( $sub_id ) ) {
				// There's a scheduled payment event - WCS is on track.
				continue;
			}

			$matches[] = new DR_Subs_Rule_Match(
				$this->id(),
				$sub_id,
				$this->bucket(),
				array(
					'expected_payment_ts'    => $next_ts,
					'expected_payment_date'  => $next_payment,
					'days_overdue'           => (int) floor( ( $now - $next_ts ) / DAY_IN_SECONDS ),
					'tracked_fields_snapshot' => $this->snapshot_fields( $sub ),
				)
			);
		}

		return $matches;
	}

	/** {@inheritDoc} */
	public function preview_fix( DR_Subs_Rule_Match $match ): array {
		$reschedule_ts  = time() + self::RESCHEDULE_DELAY;
		$reschedule_str = wp_date( 'M j H:i', $reschedule_ts );

		$diff = array(
			array(
				'field'  => __( 'Next payment', 'doctor-subs' ),
				'before' => __( '— (not scheduled)', 'doctor-subs' ),
				'after'  => $reschedule_str . ' ' . __( '(UTC)', 'doctor-subs' ),
				'emph'   => true,
			),
			array(
				'field'  => __( 'AS event', 'doctor-subs' ),
				'before' => __( 'missing', 'doctor-subs' ),
				'after'  => self::PAYMENT_HOOK,
				'emph'   => true,
			),
			array(
				'field'     => __( 'Sub status', 'doctor-subs' ),
				'before'    => __( 'active', 'doctor-subs' ),
				'after'     => __( 'active', 'doctor-subs' ),
				'unchanged' => true,
			),
		);

		return array(
			'narrative'        => $this->narrate( $match ),
			'diff'             => $diff,
			// For a fresh ghost-sub apply the scheduled action hasn't run yet.
			// The executed-payment warning becomes relevant on REVERT when
			// the AS action may have executed between apply and revert.
			'already_executed' => false,
		);
	}

	/** {@inheritDoc} */
	public function apply_fix( DR_Subs_Rule_Match $match ): array {
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $match->sub_id ) : null;
		if ( ! $sub ) {
			throw new RuntimeException( 'Subscription not found for ghost-sub apply_fix.' );
		}

		// State guard: verify tracked fields haven't drifted since detection.
		$before_state = $this->snapshot_fields( $sub );
		$snapshot     = (array) ( $match->context['tracked_fields_snapshot'] ?? array() );
		if ( ! empty( $snapshot ) && $before_state !== $snapshot ) {
			throw new RuntimeException( 'State drift: subscription changed since detection. Re-scan and try again.' );
		}

		$reschedule_ts = time() + self::RESCHEDULE_DELAY;

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			throw new RuntimeException( 'Action Scheduler not available.' );
		}

		$action_id = as_schedule_single_action(
			$reschedule_ts,
			self::PAYMENT_HOOK,
			array( (int) $match->sub_id ),
			self::AS_GROUP
		);

		if ( ! $action_id || $action_id <= 0 ) {
			throw new RuntimeException( 'Failed to schedule payment action.' );
		}

		$side_effects = array(
			array(
				'type'          => 'as_action',
				'hook'          => self::PAYMENT_HOOK,
				'args'          => array( (int) $match->sub_id ),
				'group'         => self::AS_GROUP,
				'id'            => (int) $action_id,
				'scheduled_for' => gmdate( 'Y-m-d H:i:s', $reschedule_ts ),
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
		$side_effects     = json_decode( (string) $entry->side_effects, true );
		$side_effects     = is_array( $side_effects ) ? $side_effects : array();
		$already_executed = false;
		$messages         = array();

		foreach ( array_reverse( $side_effects ) as $effect ) {
			if ( ! is_array( $effect ) || 'as_action' !== ( $effect['type'] ?? '' ) ) {
				continue;
			}

			$action_id = (int) ( $effect['id'] ?? 0 );
			$hook      = (string) ( $effect['hook'] ?? '' );
			$args      = (array) ( $effect['args'] ?? array() );
			$group     = (string) ( $effect['group'] ?? '' );

			// Check whether the AS action has already executed. If so, flag
			// it for the UX warning and move on - there's nothing to
			// unschedule but we don't treat it as a failure.
			if ( $action_id > 0 && class_exists( 'ActionScheduler_Store' ) ) {
				$store  = ActionScheduler_Store::instance();
				$status = null;
				try {
					$status = $store->get_status( $action_id );
				} catch ( \Throwable $t ) {
					// Action doesn't exist (already purged). Nothing to do.
					$messages[] = sprintf( 'AS action %d no longer in store.', $action_id );
					continue;
				}

				if ( ActionScheduler_Store::STATUS_COMPLETE === $status ) {
					$already_executed = true;
					$messages[]       = sprintf( 'AS action %d already executed; revert cannot undo the charge.', $action_id );
					continue;
				}
			}

			// Try the public API first (hook + args + group identifier).
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( $hook, $args, $group );
			}

			// Fallback: cancel by ID (semi-private, more direct).
			if ( $action_id > 0 && class_exists( 'ActionScheduler_Store' ) ) {
				try {
					ActionScheduler_Store::instance()->cancel_action( $action_id );
				} catch ( \Throwable $t ) {
					// Already canceled / already executed / purged - non-fatal.
				}
			}

			$messages[] = sprintf( 'Unscheduled AS action %d.', $action_id );
		}

		return array(
			'success'          => true,
			'message'          => implode( ' ', $messages ),
			'already_executed' => $already_executed,
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

		$expected_date = '';
		if ( ! empty( $match->context['expected_payment_ts'] ) ) {
			$expected_date = wp_date( 'M j \a\t H:i', (int) $match->context['expected_payment_ts'] );
		}

		$days_overdue = (int) ( $match->context['days_overdue'] ?? 0 );

		// Template variants: select by days_overdue bucket.
		if ( $days_overdue < 1 ) {
			$template = __(
				"%1\$s's subscription was supposed to renew <em>today</em>. WordPress didn't schedule the payment event, so nothing was charged.",
				'doctor-subs'
			);
		} elseif ( $days_overdue < 7 ) {
			$template = __(
				"%1\$s's subscription was supposed to renew on <em>%2\$s</em> (%3\$d days ago). WordPress didn't schedule the payment event, so nothing was charged.",
				'doctor-subs'
			);
		} elseif ( $days_overdue < 30 ) {
			$template = __(
				"%1\$s's subscription was supposed to renew on <em>%2\$s</em>, about %3\$d days ago. WordPress didn't schedule the payment event, so %1\$s hasn't been billed for any cycles since.",
				'doctor-subs'
			);
		} else {
			$template = __(
				"%1\$s's subscription was supposed to renew on <em>%2\$s</em>, over a month ago. WordPress didn't schedule the payment event, so %1\$s hasn't been billed for any cycles since.",
				'doctor-subs'
			);
		}

		return sprintf( $template, $first, $expected_date, $days_overdue );
	}

	/**
	 * Read the tracked-field snapshot off a live sub.
	 *
	 * @param WC_Subscription $sub
	 * @return array<string, string>
	 */
	private function snapshot_fields( $sub ): array {
		return array(
			'status'       => (string) $sub->get_status(),
			'next_payment' => (string) $sub->get_date( 'next_payment' ),
		);
	}
}
