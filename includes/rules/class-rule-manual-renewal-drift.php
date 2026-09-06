<?php
/**
 * Manual Renewal Drift rule (Stripe-only in v2.1).
 *
 * Detects active subs that have silently been marked
 * `_requires_manual_renewal = true` despite having a stored Stripe
 * payment method. WCS's renewal scheduler skips these subs, so renewals
 * never fire and customers churn invisibly. The pattern is documented
 * in the Sybre Waaijer X disclosure (April 2026): four root causes in
 * subscriptions-core (stale dates cache, HPOS↔postmeta sync gap,
 * wcs_create_subscription state discard, same-gateway switch) all
 * surface as the same broken state.
 *
 * Detection requires a stored Stripe customer/source so we know the
 * sub is genuinely auto-renewable; without that signal, the manual
 * flag may be legitimate.
 *
 * Fix: clear the flag (via CRUD setter AND direct postmeta as
 * belt-and-braces against bug #2's HPOS sync hole), re-stamp
 * `next_payment` if past-due, and schedule a single AS payment event.
 *
 * Revert: restore the flag, restore the date, unschedule the AS event.
 *
 * @package Dr_Subs
 * @since   2.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual-renewal flag drift detection + fix.
 *
 * @since 2.1.0
 */
class DR_Subs_Rule_Manual_Renewal_Drift implements DR_Subs_Rule_Interface {

	const AS_GROUP         = 'doctor-subs';
	const PAYMENT_HOOK     = 'woocommerce_scheduled_subscription_payment';
	const RESCHEDULE_DELAY = 60;

	/**
	 * Skip subs created within this many days. Recent subs are still
	 * landing through checkout; the flag may not have stabilised yet.
	 */
	const RECENT_CREATE_DAYS = 7;

	/** {@inheritDoc} */
	public function id(): string {
		return 'manual_renewal_drift';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Manual renewal drift', 'doctor-subs' );
	}

	/** {@inheritDoc} */
	public function bucket(): string {
		return 'broken';
	}

	/** {@inheritDoc} */
	public function tracked_fields(): array {
		return array( 'status', 'requires_manual_renewal', 'next_payment' );
	}

	/** {@inheritDoc} */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array {
		$matches = array();
		$cutoff  = time() - ( self::RECENT_CREATE_DAYS * DAY_IN_SECONDS );

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = (int) $sub_id;
			if ( $sub_id <= 0 ) {
				continue;
			}

			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( ! $sub || 'active' !== $sub->get_status() ) {
				continue;
			}

			if ( ! $sub->get_requires_manual_renewal() ) {
				continue;
			}

			// Skip very recent subs - checkout may still be settling.
			$created = $sub->get_date_created();
			if ( $created && $created->getTimestamp() >= $cutoff ) {
				continue;
			}

			if ( ! $this->is_stripe_with_payment_method( $sub ) ) {
				continue;
			}

			$matches[] = new DR_Subs_Rule_Match(
				$this->id(),
				$sub_id,
				$this->bucket(),
				array(
					'payment_method'          => (string) $sub->get_payment_method(),
					'stripe_customer_id'      => (string) $sub->get_meta( '_stripe_customer_id' ),
					'next_payment'            => (string) $sub->get_date( 'next_payment' ),
					'tracked_fields_snapshot' => $this->snapshot_fields( $sub ),
				)
			);
		}

		return $matches;
	}

	/** {@inheritDoc} */
	public function preview_fix( DR_Subs_Rule_Match $match ): array {
		$next_after = $this->planned_next_payment( $match );

		$diff = array(
			array(
				'field'  => __( 'Auto-renewal', 'doctor-subs' ),
				'before' => __( 'off (manual)', 'doctor-subs' ),
				'after'  => __( 'on', 'doctor-subs' ),
				'emph'   => true,
			),
			array(
				'field'  => __( 'Next payment', 'doctor-subs' ),
				'before' => $this->format_date( (string) ( $match->context['next_payment'] ?? '' ) ),
				'after'  => $this->format_date( gmdate( 'Y-m-d H:i:s', $next_after ) ),
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
			'already_executed' => false,
		);
	}

	/** {@inheritDoc} */
	public function apply_fix( DR_Subs_Rule_Match $match ): array {
		$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $match->sub_id ) : null;
		if ( ! $sub ) {
			throw new RuntimeException( 'Subscription not found for manual-renewal-drift apply_fix.' );
		}

		$before_state = $this->snapshot_fields( $sub );
		$snapshot     = (array) ( $match->context['tracked_fields_snapshot'] ?? array() );
		if ( ! empty( $snapshot ) && $before_state !== $snapshot ) {
			throw new RuntimeException( 'State drift: subscription changed since detection. Re-scan and try again.' );
		}

		// 1 and 2. Clear the flag through the CRUD, then mirror it to postmeta
		// to cover the HPOS backfill gap. Both writes go through one helper so
		// they can never disagree about how the value is spelled.
		$this->write_manual_renewal_flag( $sub, false );

		// 3. Re-stamp next_payment if past-due or unset.
		$old_next     = (string) $sub->get_date( 'next_payment' );
		$next_ts      = $this->planned_next_payment( $match );
		$next_str     = gmdate( 'Y-m-d H:i:s', $next_ts );
		$date_changed = false;

		$old_ts = $old_next ? (int) strtotime( $old_next . ' UTC' ) : 0;
		if ( $old_ts <= 0 || $old_ts < time() ) {
			$sub->update_dates( array( 'next_payment' => $next_str ) );
			$date_changed = true;
		} else {
			$next_ts  = $old_ts;
			$next_str = $old_next;
		}

		// 4. Schedule the AS payment event so WCS's handler runs and (now
		// that the flag is cleared) processes auto-renewal.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			throw new RuntimeException( 'Action Scheduler not available.' );
		}
		$action_id = as_schedule_single_action(
			$next_ts,
			self::PAYMENT_HOOK,
			array( (int) $match->sub_id ),
			self::AS_GROUP
		);
		if ( ! $action_id || $action_id <= 0 ) {
			throw new RuntimeException( 'Failed to schedule payment action.' );
		}

		$sub->add_order_note(
			sprintf(
				/* translators: 1: scheduled time, 2: AS action id */
				__( 'Doctor Subs: cleared the manual-renewal flag and scheduled the next renewal for %1$s (AS action #%2$d).', 'doctor-subs' ),
				wp_date( 'M j H:i', $next_ts ),
				(int) $action_id
			)
		);

		$side_effects = array(
			array(
				'type'   => 'sub_meta',
				'sub_id' => (int) $match->sub_id,
				'key'    => '_requires_manual_renewal',
				'from'   => 'yes',
				'to'     => 'no',
			),
		);

		if ( $date_changed ) {
			$side_effects[] = array(
				'type'      => 'sub_date',
				'sub_id'    => (int) $match->sub_id,
				'date_type' => 'next_payment',
				'from'      => $old_next,
				'to'        => $next_str,
			);
		}

		$side_effects[] = array(
			'type'          => 'as_action',
			'hook'          => self::PAYMENT_HOOK,
			'args'          => array( (int) $match->sub_id ),
			'group'         => self::AS_GROUP,
			'id'            => (int) $action_id,
			'scheduled_for' => $next_str,
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
		$sub_id           = (int) $entry->sub_id;
		$sub              = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;

		// Reverse order: undo AS action, then date, then flag.
		foreach ( array_reverse( $side_effects ) as $effect ) {
			if ( ! is_array( $effect ) ) {
				continue;
			}
			$type = (string) ( $effect['type'] ?? '' );

			if ( 'as_action' === $type ) {
				$action_id = (int) ( $effect['id'] ?? 0 );
				$hook      = (string) ( $effect['hook'] ?? '' );
				$args      = (array) ( $effect['args'] ?? array() );
				$group     = (string) ( $effect['group'] ?? '' );

				if ( $action_id > 0 && class_exists( 'ActionScheduler_Store' ) ) {
					try {
						$status = ActionScheduler_Store::instance()->get_status( $action_id );
						if ( ActionScheduler_Store::STATUS_COMPLETE === $status ) {
							$already_executed = true;
							$messages[]       = sprintf( 'AS action %d already executed; revert cannot undo the charge.', $action_id );
							continue;
						}
					} catch ( \Throwable $t ) {
						$messages[] = sprintf( 'AS action %d no longer in store.', $action_id );
						continue;
					}
				}
				if ( function_exists( 'as_unschedule_action' ) ) {
					as_unschedule_action( $hook, $args, $group );
				}
				if ( $action_id > 0 && class_exists( 'ActionScheduler_Store' ) ) {
					try {
						ActionScheduler_Store::instance()->cancel_action( $action_id );
					} catch ( \Throwable $t ) {
						// Already canceled / purged - non-fatal.
					}
				}
				$messages[] = sprintf( 'Unscheduled AS action %d.', $action_id );
			} elseif ( 'sub_date' === $type && $sub ) {
				$date_type = (string) ( $effect['date_type'] ?? '' );
				$from      = (string) ( $effect['from'] ?? '' );
				if ( '' !== $date_type && '' !== $from ) {
					$sub->update_dates( array( $date_type => $from ) );
					$messages[] = sprintf( 'Restored %s to %s.', $date_type, $from );
				}
			} elseif ( 'sub_meta' === $type && $sub ) {
				$key  = (string) ( $effect['key'] ?? '' );
				$from = $effect['from'] ?? '';
				if ( '_requires_manual_renewal' === $key ) {
					$this->write_manual_renewal_flag( $sub, $this->flag_was_set( $from ) );
					$messages[] = 'Restored manual-renewal flag.';
				}
			}
		}

		if ( $sub ) {
			$note = $already_executed
				? __( 'Doctor Subs: revert requested but the renewal payment had already executed; nothing to unschedule.', 'doctor-subs' )
				: __( 'Doctor Subs: reverted manual-renewal-drift fix - restored the flag and unscheduled the renewal.', 'doctor-subs' );
			$sub->add_order_note( $note );
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

		return sprintf(
			/* translators: %s: first name */
			__( "%1\$s's subscription is marked as <em>manual renewal</em>, but their Stripe card is on file and the gateway supports auto-renewals - so this is almost certainly the silent-manual-flag bug, not an intentional setting. WCS's scheduler is skipping them, no renewal will fire, and the customer will churn quietly. Clearing the flag and rescheduling restores auto-billing.", 'doctor-subs' ),
			$first
		);
	}

	/**
	 * Does this sub use Stripe and have a stored payment method?
	 *
	 * @param WC_Subscription $sub
	 * @return bool
	 */
	private function is_stripe_with_payment_method( $sub ): bool {
		$method = (string) $sub->get_payment_method();
		if ( 0 !== strpos( $method, 'stripe' ) ) {
			return false;
		}

		$customer = (string) $sub->get_meta( '_stripe_customer_id' );
		$source   = (string) $sub->get_meta( '_stripe_source_id' );
		return ! empty( $customer ) || ! empty( $source );
	}

	/**
	 * Decide what next_payment timestamp to use after the fix.
	 *
	 * If the existing next_payment is past or missing, schedule shortly
	 * after now (the customer is overdue). If it is in the future, leave
	 * it alone - apply_fix only schedules the AS event.
	 *
	 * @param DR_Subs_Rule_Match $match
	 * @return int Unix timestamp.
	 */
	private function planned_next_payment( DR_Subs_Rule_Match $match ): int {
		$now      = time();
		$existing = (string) ( $match->context['next_payment'] ?? '' );
		$ts       = $existing ? (int) strtotime( $existing . ' UTC' ) : 0;

		if ( $ts > $now ) {
			return $ts;
		}
		return $now + self::RESCHEDULE_DELAY;
	}

	/**
	 * Pretty date string for the diff.
	 *
	 * @param string $mysql_dt UTC mysql datetime, or empty.
	 * @return string
	 */
	private function format_date( string $mysql_dt ): string {
		if ( '' === $mysql_dt ) {
			return __( '- (not scheduled)', 'doctor-subs' );
		}
		$ts = strtotime( $mysql_dt . ' UTC' );
		return $ts ? wp_date( 'M j H:i', $ts ) . ' ' . __( '(UTC)', 'doctor-subs' ) : $mysql_dt;
	}

	/**
	 * Write the manual-renewal flag through the CRUD and mirror it to postmeta.
	 *
	 * WooCommerce Subscriptions does not use WooCommerce's usual 'yes'/'no'
	 * convention for this one property. WC_Subscription::set_requires_manual_
	 * renewal() treats only the exact string 'false' and the empty string as
	 * false and, in its own comment, defaults "to require manual renewal for
	 * all other values". Writing 'no' therefore reads back as TRUE, which
	 * silently re-broke the subscription this rule had just repaired on any
	 * store where postmeta is canonical.
	 *
	 * The postmeta mirror is still worth doing: under HPOS the orders table is
	 * canonical and the backfill to postmeta is broken in some WooCommerce
	 * versions, which is one of the bugs this rule exists to clean up after.
	 * It just has to be spelled the way Subscriptions spells it.
	 *
	 * @param WC_Subscription $sub    Subscription to write to.
	 * @param bool            $manual Whether manual renewal should be on.
	 */
	private function write_manual_renewal_flag( $sub, bool $manual ): void {
		$sub->set_requires_manual_renewal( $manual );
		$sub->save();

		update_post_meta(
			(int) $sub->get_id(),
			'_requires_manual_renewal',
			$manual ? 'true' : 'false'
		);
	}

	/**
	 * Read a recorded before-value for the manual-renewal flag as a boolean.
	 *
	 * Entries written by earlier versions recorded the flag as 'yes'/'no', so
	 * both spellings have to be understood on the way back in or an old
	 * journal entry would revert to the wrong state.
	 *
	 * @param mixed $from Recorded value from the side effect.
	 * @return bool
	 */
	private function flag_was_set( $from ): bool {
		if ( is_bool( $from ) ) {
			return $from;
		}

		return in_array( strtolower( trim( (string) $from ) ), array( 'yes', 'true', '1' ), true );
	}

	/**
	 * Tracked-field snapshot.
	 *
	 * @param WC_Subscription $sub
	 * @return array<string, string>
	 */
	private function snapshot_fields( $sub ): array {
		return array(
			'status'                  => (string) $sub->get_status(),
			'requires_manual_renewal' => $sub->get_requires_manual_renewal() ? 'yes' : 'no',
			'next_payment'            => (string) $sub->get_date( 'next_payment' ),
		);
	}
}
