<?php
/**
 * Repeated Failures rule.
 *
 * Detects subscriptions with two or more failed
 * `woocommerce_scheduled_subscription_payment` actions in the last 30
 * days. Often means a gateway hiccup, a card on file declining, or a
 * transient store-side issue that cleared up.
 *
 * Fix: enqueue a single fresh retry action. Does NOT loop. If the retry
 * fails too, the merchant sees another failed action on next scan and
 * can contact the customer / update the card. The point of this rule
 * is to recover the cases where a one-time retry succeeds without
 * needing merchant action.
 *
 * Bucket: 'risk' by default because "2 failures" may or may not be
 * genuinely broken. Elevates to 'broken' at 4+ failures in window.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repeated AS failure detection + one-shot retry.
 *
 * @since 2.0.0
 */
class DR_Subs_Rule_Repeated_Failures implements DR_Subs_Rule_Interface {

	/**
	 * AS group tag for retries we enqueue.
	 */
	const AS_GROUP = 'doctor-subs';

	/**
	 * WCS payment hook.
	 */
	const PAYMENT_HOOK = 'woocommerce_scheduled_subscription_payment';

	/**
	 * Minimum failed-action count that qualifies as a match. Two is the
	 * design-locked threshold: one failure is noise (transient gateway
	 * blip). Two or more suggests a pattern.
	 */
	const MIN_FAILURES = 2;

	/**
	 * Failure count at which bucket escalates from 'risk' to 'broken'.
	 */
	const BROKEN_THRESHOLD = 4;

	/**
	 * Retry scheduling delay (seconds from now).
	 */
	const RETRY_DELAY = 60;

	/** {@inheritDoc} */
	public function id(): string {
		return 'repeated_failures';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Repeated fails', 'doctor-subs' );
	}

	/** {@inheritDoc} */
	public function bucket(): string {
		return 'risk';
	}

	/** {@inheritDoc} */
	public function tracked_fields(): array {
		// Retry safety: if the status or next_payment changed since
		// detection, the context is different and a retry might
		// double-charge. Guard on both.
		return array( 'status', 'next_payment' );
	}

	/** {@inheritDoc} */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array {
		$matches = array();

		foreach ( $sub_ids as $sub_id ) {
			$sub_id = (int) $sub_id;
			if ( $sub_id <= 0 ) {
				continue;
			}

			$failed_count = $context->failed_as_count_for( $sub_id );
			if ( $failed_count < self::MIN_FAILURES ) {
				continue;
			}

			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
			if ( ! $sub ) {
				continue;
			}

			// Skip terminal statuses - a cancelled sub with old failures
			// isn't broken, it's archived.
			$status = $sub->get_status();
			if ( in_array( $status, array( 'cancelled', 'expired', 'trash' ), true ) ) {
				continue;
			}

			$bucket = ( $failed_count >= self::BROKEN_THRESHOLD ) ? 'broken' : 'risk';

			$matches[] = new DR_Subs_Rule_Match(
				$this->id(),
				$sub_id,
				$bucket,
				array(
					'failed_count'            => (int) $failed_count,
					'failed_as_ids'           => array_values( $context->failed_as_ids_for( $sub_id ) ),
					'window_days'             => (int) DR_Subs_Scan_Context::FAILED_WINDOW_DAYS,
					'tracked_fields_snapshot' => $this->snapshot_fields( $sub ),
				)
			);
		}

		return $matches;
	}

	/** {@inheritDoc} */
	public function preview_fix( DR_Subs_Rule_Match $match ): array {
		$retry_ts  = time() + self::RETRY_DELAY;
		$retry_str = wp_date( 'M j H:i', $retry_ts );
		$failed    = (int) ( $match->context['failed_count'] ?? 0 );

		$diff = array(
			array(
				'field'  => __( 'Retry payment', 'doctor-subs' ),
				'before' => sprintf( /* translators: %d: count */ _n( '%d failed attempt', '%d failed attempts', $failed, 'doctor-subs' ), $failed ),
				'after'  => sprintf( /* translators: %s: time */ __( 'one retry at %s (UTC)', 'doctor-subs' ), $retry_str ),
				'emph'   => true,
			),
			array(
				'field'     => __( 'Sub status', 'doctor-subs' ),
				'before'    => __( 'unchanged', 'doctor-subs' ),
				'after'     => __( 'unchanged', 'doctor-subs' ),
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
			throw new RuntimeException( 'Subscription not found for repeated-failures apply_fix.' );
		}

		$before_state = $this->snapshot_fields( $sub );
		$snapshot     = (array) ( $match->context['tracked_fields_snapshot'] ?? array() );
		if ( ! empty( $snapshot ) && $before_state !== $snapshot ) {
			throw new RuntimeException( 'State drift: subscription changed since detection. Re-scan and try again.' );
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			throw new RuntimeException( 'Action Scheduler not available.' );
		}

		$retry_ts = time() + self::RETRY_DELAY;
		$action_id = as_schedule_single_action(
			$retry_ts,
			self::PAYMENT_HOOK,
			array( (int) $match->sub_id ),
			self::AS_GROUP
		);
		if ( ! $action_id || $action_id <= 0 ) {
			throw new RuntimeException( 'Failed to schedule retry action.' );
		}

		$side_effects = array(
			array(
				'type'          => 'as_action',
				'hook'          => self::PAYMENT_HOOK,
				'args'          => array( (int) $match->sub_id ),
				'group'         => self::AS_GROUP,
				'id'            => (int) $action_id,
				'scheduled_for' => gmdate( 'Y-m-d H:i:s', $retry_ts ),
				'purpose'       => 'retry_after_repeated_failures',
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

	/**
	 * Revert semantics are identical to Ghost Sub's AS-action revert.
	 * We delegate to the same logic for consistency + less duplication.
	 *
	 * @param object $entry
	 * @return array
	 */
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

			if ( $action_id > 0 && class_exists( 'ActionScheduler_Store' ) ) {
				$store  = ActionScheduler_Store::instance();
				$status = null;
				try {
					$status = $store->get_status( $action_id );
				} catch ( \Throwable $t ) {
					$messages[] = sprintf( 'AS action %d no longer in store.', $action_id );
					continue;
				}
				if ( ActionScheduler_Store::STATUS_COMPLETE === $status ) {
					$already_executed = true;
					$messages[]       = sprintf( 'Retry %d already executed; revert cannot undo the charge.', $action_id );
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
					// swallow
				}
			}
			$messages[] = sprintf( 'Unscheduled retry %d.', $action_id );
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

		$count  = (int) ( $match->context['failed_count'] ?? 0 );
		$window = (int) ( $match->context['window_days'] ?? DR_Subs_Scan_Context::FAILED_WINDOW_DAYS );

		if ( $count >= self::BROKEN_THRESHOLD ) {
			return sprintf(
				/* translators: 1: first name, 2: count, 3: window days */
				__( "Something has been failing to process %1\$s's renewal for a while - %2\$d failed attempts in the last %3\$d days. Worth looking at the card on file or the gateway log.", 'doctor-subs' ),
				$first,
				$count,
				$window
			);
		}

		return sprintf(
			/* translators: 1: first name, 2: count, 3: window days */
			__( "%1\$s's renewal has failed %2\$d times in the last %3\$d days. Often a gateway blip that clears on retry, but worth another look if this retry fails too.", 'doctor-subs' ),
			$first,
			$count,
			$window
		);
	}

	/**
	 * Tracked-field snapshot.
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
