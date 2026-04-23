<?php
/**
 * Health scanner.
 *
 * Walks all active subscriptions in batches, runs every registered
 * rule's detect_batch against the batch (sharing the same
 * DR_Subs_Scan_Context across rules so each rule pays O(1) per sub),
 * and upserts results into dr_subs_sub_health.
 *
 * Scheduled via Action Scheduler's recurring action
 * `dr_subs_daily_health_scan`. A WP-Cron watchdog (`dr_subs_cron_watchdog`)
 * catches the case where AS hasn't fired in over 36 hours and kicks a
 * one-shot scan.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run-the-scan engine.
 *
 * @since 2.0.0
 */
class DR_Subs_Health_Scanner {

	/**
	 * AS hook name for the recurring daily scan.
	 */
	const RECURRING_HOOK = 'dr_subs_daily_health_scan';

	/**
	 * WP-Cron hook used as AS watchdog.
	 */
	const WATCHDOG_HOOK = 'dr_subs_cron_watchdog';

	/**
	 * AS group tag for the recurring + watchdog-triggered scans.
	 */
	const AS_GROUP = 'doctor-subs';

	/**
	 * Option key storing the last completed scan timestamp (UTC unix).
	 */
	const LAST_SCAN_OPTION = 'dr_subs_last_scan_ts';

	/**
	 * Transient key used to lock concurrent scans. 10-minute TTL.
	 */
	const SCAN_LOCK_TRANSIENT = 'dr_subs_scan_lock';

	/**
	 * How stale the last-scan can get before the WP-Cron watchdog fires
	 * a catch-up scan.
	 */
	const WATCHDOG_STALE_HOURS = 36;

	/**
	 * Maximum number of pages the scanner will walk in one run. Protects
	 * against a runaway loop on very large stores. With a batch size of
	 * 100, this is a 50,000-sub ceiling per run.
	 */
	const PAGE_CAP = 500;

	/**
	 * Register the AS recurring action + WP-Cron watchdog. Idempotent.
	 *
	 * @return void
	 */
	public static function schedule_recurring(): void {
		// Action Scheduler recurring action (primary).
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false === as_next_scheduled_action( self::RECURRING_HOOK, array(), self::AS_GROUP ) ) {
				as_schedule_recurring_action(
					time() + MINUTE_IN_SECONDS, // First run in 1 minute after activation.
					DAY_IN_SECONDS,
					self::RECURRING_HOOK,
					array(),
					self::AS_GROUP
				);
			}
		}

		// WP-Cron watchdog (secondary, catches AS misses).
		if ( ! wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::WATCHDOG_HOOK );
		}
	}

	/**
	 * Unschedule both hooks. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RECURRING_HOOK, array(), self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
	}

	/**
	 * AS hook handler. Static so AS can call it directly.
	 *
	 * @return void
	 */
	public static function run_recurring(): void {
		$scanner = new self();
		$scanner->run();
	}

	/**
	 * WP-Cron watchdog handler. If the last scan was longer ago than
	 * WATCHDOG_STALE_HOURS, kick off a one-shot scan.
	 *
	 * @return void
	 */
	public static function run_watchdog(): void {
		$last = (int) get_option( self::LAST_SCAN_OPTION, 0 );
		if ( $last > 0 && ( time() - $last ) < ( self::WATCHDOG_STALE_HOURS * HOUR_IN_SECONDS ) ) {
			return;
		}
		self::run_recurring();
	}

	/**
	 * Do the scan.
	 *
	 * Respects a transient-based concurrency lock so a simultaneous
	 * invocation (e.g., AS fires while a manual Refresh click is also
	 * running) doesn't double-scan.
	 *
	 * @return array  Summary: total_processed, broken, at_risk, healthy.
	 */
	public function run(): array {
		$summary = array(
			'total_processed'      => 0,
			'broken'               => 0,
			'at_risk'              => 0,
			'healthy'              => 0,
			'newly_broken_sub_ids' => array(),
		);

		// Concurrency lock.
		if ( false !== get_transient( self::SCAN_LOCK_TRANSIENT ) ) {
			return $summary;
		}
		set_transient( self::SCAN_LOCK_TRANSIENT, time(), 10 * MINUTE_IN_SECONDS );

		try {
			if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
				return $summary;
			}

			// Fire the before_scan hook so third parties can add indexes
			// to the context before any rule runs.
			$context = new DR_Subs_Scan_Context();
			do_action( 'dr_subs_before_scan', $context );

			DR_Subs_Rules_Registry::bootstrap();
			$rules = DR_Subs_Rules_Registry::all();

			// Snapshot which subs are currently 'broken' so we can compute
			// newly_broken_sub_ids (used by the alert dispatcher).
			$previously_broken = $this->fetch_previously_broken_sub_ids();
			$currently_broken  = array();

			$batch_size = defined( 'DR_SUBS_SCAN_BATCH_SIZE' ) ? (int) DR_SUBS_SCAN_BATCH_SIZE : 100;
			$page       = 1;

			while ( $page <= self::PAGE_CAP ) {
				$subs = wcs_get_subscriptions(
					array(
						'subscription_status'    => 'active',
						'subscriptions_per_page' => $batch_size,
						'paged'                  => $page,
					)
				);
				if ( empty( $subs ) ) {
					break;
				}

				$sub_ids = array_map(
					static function ( $s ) {
						return (int) $s->get_id();
					},
					$subs
				);

				// Run every rule's detect_batch against this batch, sharing
				// the context. One rule failing shouldn't break the scan.
				$matches_by_sub = array();
				foreach ( $rules as $rule_id => $rule ) {
					try {
						$matches = $rule->detect_batch( $sub_ids, $context );
					} catch ( \Throwable $t ) {
						DR_Subs_Logger::error(
							"Rule {$rule_id} detect_batch failed",
							array(
								'error'     => $t->getMessage(),
								'rule_id'   => $rule_id,
								'batch'     => $sub_ids,
							)
						);
						continue;
					}
					foreach ( $matches as $match ) {
						$match->narration                  = DR_Subs_Narrator::for_match( $rule, $match );
						$matches_by_sub[ $match->sub_id ][] = $match;
					}
				}

				// Upsert health rows for every sub in the batch, including
				// those with no matches (they're healthy).
				foreach ( $sub_ids as $sub_id ) {
					$matches = $matches_by_sub[ $sub_id ] ?? array();
					$bucket  = $this->upsert_health_row( $sub_id, $matches );
					$summary[ 'healthy' === $bucket ? 'healthy' : ( 'risk' === $bucket ? 'at_risk' : 'broken' ) ]++;
					if ( 'broken' === $bucket ) {
						$currently_broken[] = $sub_id;
					}
				}

				$summary['total_processed'] += count( $subs );
				$page++;
			}

			// Find newly-broken subs (in currently_broken but not in
			// previously_broken). Passed to alert dispatcher.
			$summary['newly_broken_sub_ids'] = array_values(
				array_diff( $currently_broken, $previously_broken )
			);

			update_option( self::LAST_SCAN_OPTION, time(), false );

			/**
			 * Fires after the scanner has finished writing to sub_health.
			 *
			 * @since 2.0.0
			 * @param array $summary Totals + newly_broken_sub_ids.
			 */
			do_action( 'dr_subs_after_scan', $summary );

		} finally {
			delete_transient( self::SCAN_LOCK_TRANSIENT );
		}

		return $summary;
	}

	/**
	 * Upsert one row into dr_subs_sub_health.
	 *
	 * Picks the worst bucket across matches (broken > risk > healthy),
	 * concatenates narration from the first match, serialises
	 * matched_rules as JSON.
	 *
	 * @param int   $sub_id
	 * @param array $matches  Zero or more DR_Subs_Rule_Match.
	 * @return string  Final bucket ('healthy'|'risk'|'broken').
	 */
	private function upsert_health_row( int $sub_id, array $matches ): string {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();

		$bucket        = 'healthy';
		$matched_rules = array();
		$narration     = null;

		foreach ( $matches as $match ) {
			if ( ! ( $match instanceof DR_Subs_Rule_Match ) ) {
				continue;
			}
			$matched_rules[] = array(
				'rule_id' => $match->rule_id,
				'bucket'  => $match->bucket,
			);
			if ( 'broken' === $match->bucket ) {
				$bucket = 'broken';
			} elseif ( 'risk' === $match->bucket && 'broken' !== $bucket ) {
				$bucket = 'risk';
			}
			if ( null === $narration && ! empty( $match->narration ) ) {
				$narration = (string) $match->narration;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional scanner write.
		$wpdb->replace(
			$table,
			array(
				'sub_id'          => $sub_id,
				'bucket'          => $bucket,
				'matched_rules'   => (string) wp_json_encode( $matched_rules ),
				'narration'       => $narration,
				'last_scanned_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable

		return $bucket;
	}

	/**
	 * Return sub_ids currently marked 'broken' in sub_health.
	 *
	 * @return array<int, int>
	 */
	private function fetch_previously_broken_sub_ids(): array {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- diff query.
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT sub_id FROM %i WHERE bucket = 'broken'", $table )
		);
		// phpcs:enable
		return array_map( 'intval', (array) $rows );
	}
}
