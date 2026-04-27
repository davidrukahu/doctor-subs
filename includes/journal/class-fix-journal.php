<?php
/**
 * Fix journal.
 *
 * Records every applied fix as a revertible row in dr_subs_fix_journal.
 * Delegates the actual revert work to the owning rule's revert_fix().
 * Handles the journal's retention-window pruning via a recurring AS
 * action.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix journal CRUD + revert dispatcher.
 *
 * @since 2.0.0
 */
class DR_Subs_Fix_Journal {

	/**
	 * AS hook for the recurring cleanup action.
	 */
	const CLEANUP_HOOK = 'dr_subs_journal_cleanup';

	/**
	 * AS group tag for the cleanup action.
	 */
	const AS_GROUP = 'doctor-subs';

	/**
	 * Insert a new journal entry from a rule's apply_fix payload.
	 *
	 * @param int         $sub_id
	 * @param string      $rule_id
	 * @param array       $payload  Output of DR_Subs_Rule_Interface::apply_fix().
	 *                              Must carry before_state, before_state_hash,
	 *                              after_state, side_effects.
	 * @param string|null $batch_id Optional grouping id for bulk fixes.
	 * @return int  Inserted entry_id, or 0 on failure.
	 */
	public static function record( int $sub_id, string $rule_id, array $payload, ?string $batch_id = null ): int {
		global $wpdb;
		$table = DR_Subs_Migration::fix_journal_table();

		$data = array(
			'batch_id'          => $batch_id,
			'sub_id'            => $sub_id,
			'rule_id'           => $rule_id,
			'before_state'      => (string) wp_json_encode( $payload['before_state'] ?? array() ),
			'before_state_hash' => (string) ( $payload['before_state_hash'] ?? '' ),
			'after_state'       => (string) wp_json_encode( $payload['after_state'] ?? array() ),
			'side_effects'      => (string) wp_json_encode( $payload['side_effects'] ?? array() ),
			'user_id'           => get_current_user_id(),
			'status'            => 'applied',
			'created_at'        => current_time( 'mysql', true ),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- journal write.
		$inserted = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		// phpcs:enable

		$entry_id = $inserted ? (int) $wpdb->insert_id : 0;

		if ( $entry_id > 0 ) {
			/**
			 * Fires after a fix is recorded in the journal.
			 *
			 * @since 2.0.0
			 * @param int   $entry_id
			 * @param int   $sub_id
			 * @param string $rule_id
			 * @param array $payload
			 */
			do_action( 'dr_subs_after_fix_apply', $entry_id, $sub_id, $rule_id, $payload );
		}

		return $entry_id;
	}

	/**
	 * Get a journal entry by ID.
	 *
	 * @param int $entry_id
	 * @return object|null Raw row (stdClass) or null.
	 */
	public static function get( int $entry_id ) {
		global $wpdb;
		$table = DR_Subs_Migration::fix_journal_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- journal read.
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE entry_id = %d', $table, $entry_id )
		);
		// phpcs:enable
	}

	/**
	 * All entries in a batch (for atomic bulk revert).
	 *
	 * @param string $batch_id
	 * @return array<int, object>
	 */
	public static function get_batch( string $batch_id ): array {
		global $wpdb;
		$table = DR_Subs_Migration::fix_journal_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- batch read.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE batch_id = %s ORDER BY entry_id ASC', $table, $batch_id )
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Revert a single entry by delegating to its rule's revert_fix().
	 *
	 * Returns the rule's result augmented with the journal entry id.
	 * On successful revert, marks the row status = 'reverted' and sets
	 * reverted_at.
	 *
	 * @param int $entry_id
	 * @return array Result from the rule + ['entry_id' => int, 'success' => bool]
	 */
	public static function revert( int $entry_id ): array {
		$entry = self::get( $entry_id );
		if ( ! $entry ) {
			return array(
				'entry_id' => $entry_id,
				'success'  => false,
				'message'  => __( 'Journal entry not found.', 'doctor-subs' ),
			);
		}
		if ( 'reverted' === $entry->status ) {
			return array(
				'entry_id' => $entry_id,
				'success'  => false,
				'message'  => __( 'Already reverted.', 'doctor-subs' ),
			);
		}

		DR_Subs_Rules_Registry::bootstrap();
		$rule = DR_Subs_Rules_Registry::get( (string) $entry->rule_id );
		if ( ! $rule ) {
			return array(
				'entry_id' => $entry_id,
				'success'  => false,
				'message'  => sprintf(
					/* translators: %s: rule_id */
					__( 'Rule "%s" is not registered; cannot revert.', 'doctor-subs' ),
					$entry->rule_id
				),
			);
		}

		try {
			$result = $rule->revert_fix( $entry );
		} catch ( \Throwable $t ) {
			DR_Subs_Logger::error( "Revert failed for entry {$entry_id}: " . $t->getMessage() );
			return array(
				'entry_id' => $entry_id,
				'success'  => false,
				'message'  => __( 'Revert raised an error; nothing was changed.', 'doctor-subs' ),
			);
		}

		$success = ! empty( $result['success'] );
		if ( $success ) {
			self::mark_reverted( $entry_id );

			/**
			 * Fires after a fix is successfully reverted.
			 *
			 * @since 2.0.0
			 * @param int    $entry_id
			 * @param int    $sub_id
			 * @param string $rule_id
			 */
			do_action( 'dr_subs_after_fix_revert', $entry_id, (int) $entry->sub_id, (string) $entry->rule_id );
		}

		return array_merge(
			array(
				'entry_id' => $entry_id,
				'success'  => $success,
			),
			(array) $result
		);
	}

	/**
	 * Revert all entries in a batch atomically.
	 *
	 * Order of revert matches journal insertion order so rule-level
	 * inverse dependencies are honoured (last applied is first
	 * reverted - stack semantics).
	 *
	 * @param string $batch_id
	 * @return array Summary of per-entry results.
	 */
	public static function revert_batch( string $batch_id ): array {
		$rows    = self::get_batch( $batch_id );
		$results = array();
		foreach ( array_reverse( $rows ) as $row ) {
			$results[] = self::revert( (int) $row->entry_id );
		}
		return array(
			'batch_id'      => $batch_id,
			'count'         => count( $results ),
			'success_count' => count(
				array_filter(
					$results,
					static function ( $r ) {
						return ! empty( $r['success'] ); }
				)
			),
			'results'       => $results,
		);
	}

	/**
	 * Delete entries created more than $days ago.
	 *
	 * Called by the recurring cleanup action. Returns count deleted.
	 * $days < 0 means "keep forever" - returns 0 without querying.
	 *
	 * @param int $days
	 * @return int
	 */
	public static function purge_older_than( int $days ): int {
		if ( $days < 0 ) {
			return 0;
		}
		global $wpdb;
		$table  = DR_Subs_Migration::fix_journal_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- pruning.
		$rows = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff )
		);
		// phpcs:enable

		return $rows;
	}

	/**
	 * Recurring cleanup-action handler. Reads retention from settings and
	 * calls purge_older_than.
	 *
	 * @return void
	 */
	public static function run_cleanup(): void {
		$settings = get_option( 'dr_subs_settings', DR_Subs_Migration::default_settings() );
		$days     = isset( $settings['journal_retention_days'] ) ? (int) $settings['journal_retention_days'] : 180;
		self::purge_older_than( $days );
	}

	/**
	 * Schedule the recurring cleanup action. Idempotent.
	 *
	 * @return void
	 */
	public static function schedule_cleanup(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::CLEANUP_HOOK, array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				self::CLEANUP_HOOK,
				array(),
				self::AS_GROUP
			);
		}
	}

	/**
	 * Unschedule the cleanup action. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_cleanup(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CLEANUP_HOOK, array(), self::AS_GROUP );
		}
	}

	/**
	 * Mark a row as reverted.
	 *
	 * @param int $entry_id
	 * @return void
	 */
	private static function mark_reverted( int $entry_id ): void {
		global $wpdb;
		$table = DR_Subs_Migration::fix_journal_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- revert mark.
		$wpdb->update(
			$table,
			array(
				'status'      => 'reverted',
				'reverted_at' => current_time( 'mysql', true ),
			),
			array( 'entry_id' => $entry_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable
	}
}
