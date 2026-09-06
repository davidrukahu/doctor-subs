<?php
/**
 * Chunked bulk repair.
 *
 * Bulk fix used to run synchronously inside one AJAX request: it collected
 * every matching subscription, applied every fix in a single PHP process, and
 * returned when it was done. That works on a demo store and fails on the store
 * size the product is aimed at. There was no progress, no resume, and a request
 * that timed out left the merchant with no idea how far it had got.
 *
 * This runs the same work as a chain of Action Scheduler jobs. Each job takes a
 * chunk, applies it, writes its position back, and schedules the next one. The
 * cursor lives in an option, so a timeout costs one chunk rather than the whole
 * run, and the UI can poll for progress.
 *
 * @package Dr_Subs
 * @since   2.3.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a bulk fix in chunks on Action Scheduler.
 */
class DR_Subs_Bulk_Runner {

	/**
	 * Action Scheduler hook for a single chunk.
	 */
	const CHUNK_HOOK = 'dr_subs_bulk_fix_chunk';

	/**
	 * Action Scheduler group, so these are findable in WooCommerce's UI.
	 */
	const AS_GROUP = 'doctor-subs';

	/**
	 * Subscriptions handled per chunk.
	 *
	 * Small enough that one chunk finishes well inside any sane PHP time limit,
	 * large enough that a big cohort does not become thousands of jobs.
	 */
	const CHUNK_SIZE = 20;

	/**
	 * Option name prefix for a run's state.
	 */
	const STATE_PREFIX = 'dr_subs_bulk_';

	/**
	 * How long a finished run's state is kept so the UI can read the result.
	 */
	const STATE_TTL = DAY_IN_SECONDS;

	/**
	 * Most per-subscription errors kept in state. Enough to diagnose, bounded
	 * so a cohort that fails wholesale cannot bloat the options table.
	 */
	const MAX_ERRORS = 50;

	/**
	 * Register the chunk handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CHUNK_HOOK, array( __CLASS__, 'run_chunk' ), 10, 1 );
	}

	/**
	 * Queue a bulk fix and return its batch id.
	 *
	 * @param string          $rule_id Rule to apply.
	 * @param array<int, int> $sub_ids Explicit subscriptions, or empty to use
	 *                                 every subscription currently matching.
	 * @return array{batch_id: string, total: int}
	 */
	public static function start( string $rule_id, array $sub_ids = array() ): array {
		$sub_ids = array_values( array_unique( array_filter( array_map( 'absint', $sub_ids ) ) ) );

		$total = empty( $sub_ids )
			? self::count_matching( $rule_id )
			: count( $sub_ids );

		$batch_id = self::generate_batch_id();

		$state = array(
			'batch_id'   => $batch_id,
			'rule_id'    => $rule_id,
			'status'     => 'running',
			'total'      => $total,
			'processed'  => 0,
			'applied'    => 0,
			'failed'     => 0,
			'errors'     => array(),
			// Cursor into the health table for an auto-collected cohort.
			'cursor'     => 0,
			// Explicit cohorts are consumed from the front of this list.
			'sub_ids'    => $sub_ids,
			'explicit'   => ! empty( $sub_ids ),
			'started_at' => time(),
			'updated_at' => time(),
		);

		self::save_state( $batch_id, $state );

		if ( 0 === $total ) {
			$state['status'] = 'done';
			self::save_state( $batch_id, $state );
			return array(
				'batch_id' => $batch_id,
				'total'    => 0,
			);
		}

		self::schedule_next( $batch_id );

		return array(
			'batch_id' => $batch_id,
			'total'    => $total,
		);
	}

	/**
	 * Apply one chunk, then queue the next if there is more to do.
	 *
	 * @param string $batch_id Run identifier.
	 * @return void
	 */
	public static function run_chunk( $batch_id ): void {
		$batch_id = (string) $batch_id;
		$state    = self::get_state( $batch_id );

		if ( empty( $state ) || 'running' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		DR_Subs_Rules_Registry::bootstrap();
		$rule = DR_Subs_Rules_Registry::get( (string) $state['rule_id'] );

		if ( ! $rule ) {
			$state['status']     = 'failed';
			$state['updated_at'] = time();
			$state['errors'][]   = array(
				'sub_id'  => 0,
				'message' => sprintf(
					/* translators: %s: rule id */
					__( 'Rule "%s" is no longer registered; the run was stopped.', 'doctor-subs' ),
					(string) $state['rule_id']
				),
			);
			self::save_state( $batch_id, $state );
			return;
		}

		list( $chunk, $state ) = self::next_chunk( $state );

		if ( empty( $chunk ) ) {
			$state['status']     = 'done';
			$state['updated_at'] = time();
			self::save_state( $batch_id, $state );
			self::announce_completion( $state );
			return;
		}

		$context  = new DR_Subs_Scan_Context();
		$affected = array();

		foreach ( $chunk as $sub_id ) {
			$sub_id = (int) $sub_id;
			++$state['processed'];

			// Idempotency. A chunk can run twice if Action Scheduler retries
			// after a timeout that happened between the writes and the state
			// save, and applying a fix twice is not harmless.
			if ( self::already_applied( $batch_id, $sub_id ) ) {
				continue;
			}

			$matches = $rule->detect_batch( array( $sub_id ), $context );
			$match   = $matches[0] ?? null;

			if ( ! $match ) {
				++$state['failed'];
				self::push_error( $state, $sub_id, __( 'No longer matches the rule; skipped.', 'doctor-subs' ) );
				continue;
			}

			try {
				$payload = $rule->apply_fix( $match );
				DR_Subs_Fix_Journal::record( $sub_id, (string) $state['rule_id'], $payload, $batch_id );
				++$state['applied'];
				$affected[] = $sub_id;
			} catch ( \Throwable $t ) {
				++$state['failed'];
				self::push_error( $state, $sub_id, $t->getMessage() );
				DR_Subs_Logger::error( 'bulk chunk entry failed: ' . $t->getMessage(), array( 'sub' => $sub_id ) );
			}
		}

		// Refresh health rows for what changed so the dashboard keeps up with
		// the run rather than waiting for the next scan.
		if ( ! empty( $affected ) ) {
			// One context for the whole chunk. Each rescan would otherwise
			// rebuild the entire Action Scheduler index from scratch, so a
			// chunk of 20 meant 20 full rebuilds. The fixes have just changed
			// the scheduled actions, so this is built fresh rather than reusing
			// the detection context above.
			$scanner       = new DR_Subs_Health_Scanner();
			$after_context = new DR_Subs_Scan_Context();
			foreach ( $affected as $fixed_sub_id ) {
				$scanner->rescan_sub( $fixed_sub_id, $after_context );
			}
		}

		$state['updated_at'] = time();

		$more = $state['explicit']
			? ! empty( $state['sub_ids'] )
			: count( $chunk ) === self::CHUNK_SIZE;

		if ( ! $more ) {
			$state['status'] = 'done';
			self::save_state( $batch_id, $state );
			self::announce_completion( $state );
			return;
		}

		self::save_state( $batch_id, $state );
		self::schedule_next( $batch_id );
	}

	/**
	 * Progress for the UI to poll.
	 *
	 * @param string $batch_id Run identifier.
	 * @return array<string, mixed>
	 */
	public static function progress( string $batch_id ): array {
		$state = self::get_state( $batch_id );

		if ( empty( $state ) ) {
			return array(
				'batch_id' => $batch_id,
				'status'   => 'unknown',
			);
		}

		$total     = max( 0, (int) $state['total'] );
		$processed = min( $total, (int) $state['processed'] );

		return array(
			'batch_id'  => $batch_id,
			'status'    => (string) $state['status'],
			'total'     => $total,
			'processed' => $processed,
			'applied'   => (int) $state['applied'],
			'failed'    => (int) $state['failed'],
			'percent'   => $total > 0 ? (int) floor( ( $processed / $total ) * 100 ) : 100,
			'errors'    => array_values( (array) $state['errors'] ),
		);
	}

	/**
	 * Stop a run. Work already applied stays applied and stays revertable as
	 * a batch; this only prevents further chunks.
	 *
	 * @param string $batch_id Run identifier.
	 * @return bool
	 */
	public static function cancel( string $batch_id ): bool {
		$state = self::get_state( $batch_id );
		if ( empty( $state ) ) {
			return false;
		}

		$state['status']     = 'cancelled';
		$state['updated_at'] = time();
		self::save_state( $batch_id, $state );

		as_unschedule_all_actions( self::CHUNK_HOOK, array( $batch_id ), self::AS_GROUP );

		return true;
	}

	/**
	 * Take the next chunk of subscription ids, advancing the cursor.
	 *
	 * @param array<string, mixed> $state Run state.
	 * @return array{0: array<int, int>, 1: array<string, mixed>}
	 */
	private static function next_chunk( array $state ): array {
		if ( ! empty( $state['explicit'] ) ) {
			$chunk            = array_slice( (array) $state['sub_ids'], 0, self::CHUNK_SIZE );
			$state['sub_ids'] = array_slice( (array) $state['sub_ids'], self::CHUNK_SIZE );
			return array( array_map( 'absint', $chunk ), $state );
		}

		$chunk = self::query_matching( (string) $state['rule_id'], (int) $state['cursor'], self::CHUNK_SIZE );

		if ( ! empty( $chunk ) ) {
			$state['cursor'] = (int) end( $chunk );
		}

		return array( $chunk, $state );
	}

	/**
	 * Subscription ids matching a rule, after a cursor, in id order.
	 *
	 * Ordering by sub_id rather than by scan time is what makes the cursor
	 * safe: rows are rescanned as the run progresses, so a time-ordered cursor
	 * would revisit rows it had already handled.
	 *
	 * @param string $rule_id Rule id.
	 * @param int    $after   Return ids greater than this.
	 * @param int    $limit   Maximum ids to return.
	 * @return array<int, int>
	 */
	private static function query_matching( string $rule_id, int $after, int $limit ): array {
		global $wpdb;

		$table = DR_Subs_Migration::sub_health_table();
		$like  = '%' . $wpdb->esc_like( '"rule_id":"' . $rule_id . '"' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cursor-paged cohort read.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sub_id FROM %i
					WHERE bucket IN ( 'broken', 'risk' )
					  AND matched_rules LIKE %s
					  AND sub_id > %d
					ORDER BY sub_id ASC
					LIMIT %d",
				$table,
				$like,
				$after,
				$limit
			)
		);
		// phpcs:enable

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * How many subscriptions currently match a rule.
	 *
	 * @param string $rule_id Rule id.
	 * @return int
	 */
	private static function count_matching( string $rule_id ): int {
		global $wpdb;

		$table = DR_Subs_Migration::sub_health_table();
		$like  = '%' . $wpdb->esc_like( '"rule_id":"' . $rule_id . '"' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cohort size for the progress bar.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE bucket IN ( 'broken', 'risk' ) AND matched_rules LIKE %s",
				$table,
				$like
			)
		);
		// phpcs:enable
	}

	/**
	 * Has this batch already written a journal entry for this subscription?
	 *
	 * @param string $batch_id Run identifier.
	 * @param int    $sub_id   Subscription id.
	 * @return bool
	 */
	private static function already_applied( string $batch_id, int $sub_id ): bool {
		global $wpdb;

		$table = DR_Subs_Migration::fix_journal_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- idempotency check.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT entry_id FROM %i WHERE batch_id = %s AND sub_id = %d LIMIT 1',
				$table,
				$batch_id,
				$sub_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Queue the next chunk.
	 *
	 * @param string $batch_id Run identifier.
	 * @return void
	 */
	private static function schedule_next( string $batch_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Without Action Scheduler there is nothing to queue onto. Run the
			// chunk inline so the work still happens, just without resume.
			self::run_chunk( $batch_id );
			return;
		}

		as_schedule_single_action( time(), self::CHUNK_HOOK, array( $batch_id ), self::AS_GROUP );
	}

	/**
	 * Fire the completion hook other code already listens for.
	 *
	 * @param array<string, mixed> $state Final run state.
	 * @return void
	 */
	private static function announce_completion( array $state ): void {
		/**
		 * Fires after a bulk fix batch completes.
		 *
		 * @since 2.0.0
		 * @param string $batch_id
		 * @param string $rule_id
		 * @param int    $applied
		 * @param int    $failed
		 */
		do_action(
			'dr_subs_after_bulk_fix',
			(string) $state['batch_id'],
			(string) $state['rule_id'],
			(int) $state['applied'],
			(int) $state['failed']
		);
	}

	/**
	 * Append an error, keeping the list bounded.
	 *
	 * @param array<string, mixed> $state   Run state, by reference.
	 * @param int                  $sub_id  Subscription id.
	 * @param string               $message Error text.
	 * @return void
	 */
	private static function push_error( array &$state, int $sub_id, string $message ): void {
		if ( count( (array) $state['errors'] ) >= self::MAX_ERRORS ) {
			return;
		}

		$state['errors'][] = array(
			'sub_id'  => $sub_id,
			'message' => $message,
		);
	}

	/**
	 * Option name holding a run's state.
	 *
	 * @param string $batch_id Run identifier.
	 * @return string
	 */
	private static function state_key( string $batch_id ): string {
		return self::STATE_PREFIX . $batch_id;
	}

	/**
	 * Read a run's state.
	 *
	 * @param string $batch_id Run identifier.
	 * @return array<string, mixed>
	 */
	private static function get_state( string $batch_id ): array {
		if ( ! self::valid_batch_id( $batch_id ) ) {
			return array();
		}

		$state = get_option( self::state_key( $batch_id ), array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Write a run's state.
	 *
	 * Stored autoload-off: these are read by the poller and the chunk handler,
	 * never on a normal page load.
	 *
	 * @param string               $batch_id Run identifier.
	 * @param array<string, mixed> $state    State to persist.
	 * @return void
	 */
	private static function save_state( string $batch_id, array $state ): void {
		update_option( self::state_key( $batch_id ), $state, false );

		if ( in_array( $state['status'] ?? '', array( 'done', 'failed', 'cancelled' ), true ) ) {
			// Let a finished run's state age out rather than accumulating a row
			// per bulk fix forever.
			as_schedule_single_action(
				time() + self::STATE_TTL,
				'dr_subs_bulk_state_cleanup',
				array( $batch_id ),
				self::AS_GROUP
			);
		}
	}

	/**
	 * Delete a finished run's state.
	 *
	 * @param string $batch_id Run identifier.
	 * @return void
	 */
	public static function cleanup_state( $batch_id ): void {
		$batch_id = (string) $batch_id;
		if ( self::valid_batch_id( $batch_id ) ) {
			delete_option( self::state_key( $batch_id ) );
		}
	}

	/**
	 * Batch ids are generated here and echoed back by the client, so validate
	 * before they reach an option name.
	 *
	 * @param string $batch_id Candidate.
	 * @return bool
	 */
	public static function valid_batch_id( string $batch_id ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9]{1,40}$/', $batch_id );
	}

	/**
	 * Generate a batch id.
	 *
	 * @return string
	 */
	private static function generate_batch_id(): string {
		return substr( bin2hex( random_bytes( 12 ) ), 0, 20 );
	}
}
