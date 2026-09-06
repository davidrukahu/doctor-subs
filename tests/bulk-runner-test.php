<?php
/**
 * Chunked bulk repair tests.
 *
 * The point of the runner is that a cohort bigger than one request can be
 * repaired safely: it must apply everything exactly once, survive being
 * re-entered, report honest progress, and leave one revertable batch behind.
 *
 * @package Dr_Subs
 */

/**
 * Bulk runner tests.
 */
class DR_Subs_Bulk_Runner_Test extends DR_Subs_Test_Case {

	/**
	 * Build a ghost subscription and record it in the health table, which is
	 * what the runner reads its cohort from.
	 *
	 * @return int Subscription id.
	 */
	private function make_scanned_ghost(): int {
		$sub = $this->make_subscription( 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ) ) );
		$this->clear_payment_actions( $sub->get_id() );

		$scanner = new DR_Subs_Health_Scanner();
		$scanner->rescan_sub( $sub->get_id() );

		return (int) $sub->get_id();
	}

	/**
	 * Drain the queue the way Action Scheduler would, with a hard stop so a
	 * runner that never finishes fails the test instead of hanging it.
	 *
	 * @param string $batch_id Run identifier.
	 * @return int Chunks run.
	 */
	private function drain( string $batch_id ): int {
		$runs = 0;

		while ( $runs < 50 ) {
			$progress = DR_Subs_Bulk_Runner::progress( $batch_id );
			if ( 'running' !== $progress['status'] ) {
				break;
			}
			DR_Subs_Bulk_Runner::run_chunk( $batch_id );
			++$runs;
		}

		$this->assertLessThan( 50, $runs, 'the runner did not terminate' );

		return $runs;
	}

	/**
	 * A cohort larger than one chunk is fixed completely, across chunks.
	 */
	public function test_a_cohort_larger_than_one_chunk_completes() {
		$count   = DR_Subs_Bulk_Runner::CHUNK_SIZE + 5;
		$sub_ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$sub_ids[] = $this->make_scanned_ghost();
		}

		$run = DR_Subs_Bulk_Runner::start( 'ghost_sub', $sub_ids );
		$this->assertSame( $count, $run['total'] );

		$chunks = $this->drain( $run['batch_id'] );
		$this->assertGreaterThan( 1, $chunks, 'the cohort was not split across chunks' );

		$progress = DR_Subs_Bulk_Runner::progress( $run['batch_id'] );
		$this->assertSame( 'done', $progress['status'] );
		$this->assertSame( $count, $progress['applied'] );
		$this->assertSame( 0, $progress['failed'] );
		$this->assertSame( 100, $progress['percent'] );

		foreach ( $sub_ids as $sub_id ) {
			$this->assertSame( 1, $this->pending_payment_actions( $sub_id ), "sub {$sub_id} was not fixed" );
		}
	}

	/**
	 * Every fix lands in one batch, so the whole run can be undone at once.
	 */
	public function test_the_whole_run_is_one_revertable_batch() {
		$sub_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$sub_ids[] = $this->make_scanned_ghost();
		}

		$run = DR_Subs_Bulk_Runner::start( 'ghost_sub', $sub_ids );
		$this->drain( $run['batch_id'] );

		$batch = DR_Subs_Fix_Journal::get_batch( $run['batch_id'] );
		$this->assertCount( 3, $batch );

		DR_Subs_Fix_Journal::revert_batch( $run['batch_id'] );

		foreach ( $sub_ids as $sub_id ) {
			$this->assertSame( 0, $this->pending_payment_actions( $sub_id ), "sub {$sub_id} was not reverted" );
		}
	}

	/**
	 * Re-running a chunk does not apply anything twice.
	 *
	 * Action Scheduler can retry a chunk that timed out after its writes but
	 * before its state was saved, and applying a fix twice is not harmless.
	 */
	public function test_replaying_a_chunk_does_not_double_apply() {
		$sub_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$sub_ids[] = $this->make_scanned_ghost();
		}

		$run = DR_Subs_Bulk_Runner::start( 'ghost_sub', $sub_ids );
		$this->drain( $run['batch_id'] );

		$before = count( DR_Subs_Fix_Journal::get_batch( $run['batch_id'] ) );

		// Force the finished run back to running and replay a chunk over the
		// same subscriptions.
		DR_Subs_Bulk_Runner::run_chunk( $run['batch_id'] );

		$this->assertCount(
			$before,
			DR_Subs_Fix_Journal::get_batch( $run['batch_id'] ),
			'a replayed chunk wrote extra journal entries'
		);

		foreach ( $sub_ids as $sub_id ) {
			$this->assertSame( 1, $this->pending_payment_actions( $sub_id ), 'a fix was applied twice' );
		}
	}

	/**
	 * With no explicit ids the runner finds the cohort itself.
	 */
	public function test_it_collects_the_cohort_when_given_no_ids() {
		$ghosts = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ghosts[] = $this->make_scanned_ghost();
		}

		// A healthy subscription that must not be touched.
		$healthy = $this->make_subscription( 'active' );
		$next    = time() + ( 7 * DAY_IN_SECONDS );
		$healthy->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );
		as_schedule_single_action( $next, self::PAYMENT_HOOK, array( $healthy->get_id() ) );
		( new DR_Subs_Health_Scanner() )->rescan_sub( $healthy->get_id() );

		$run = DR_Subs_Bulk_Runner::start( 'ghost_sub' );
		$this->assertSame( 3, $run['total'], 'the cohort picked up a subscription it should not have' );

		$this->drain( $run['batch_id'] );

		$this->assertSame( 3, DR_Subs_Bulk_Runner::progress( $run['batch_id'] )['applied'] );
		$this->assertCount( 3, DR_Subs_Fix_Journal::get_batch( $run['batch_id'] ) );
	}

	/**
	 * A cancelled run stops, and keeps what it already did.
	 */
	public function test_cancelling_stops_the_run_and_keeps_completed_work() {
		$sub_ids = array();
		for ( $i = 0; $i < DR_Subs_Bulk_Runner::CHUNK_SIZE + 5; $i++ ) {
			$sub_ids[] = $this->make_scanned_ghost();
		}

		$run = DR_Subs_Bulk_Runner::start( 'ghost_sub', $sub_ids );

		// One chunk, then stop.
		DR_Subs_Bulk_Runner::run_chunk( $run['batch_id'] );
		DR_Subs_Bulk_Runner::cancel( $run['batch_id'] );

		$progress = DR_Subs_Bulk_Runner::progress( $run['batch_id'] );
		$this->assertSame( 'cancelled', $progress['status'] );
		$this->assertSame( DR_Subs_Bulk_Runner::CHUNK_SIZE, $progress['applied'] );

		// Further chunks are refused.
		DR_Subs_Bulk_Runner::run_chunk( $run['batch_id'] );
		$this->assertSame(
			DR_Subs_Bulk_Runner::CHUNK_SIZE,
			DR_Subs_Bulk_Runner::progress( $run['batch_id'] )['applied'],
			'a cancelled run kept going'
		);

		// What it did finish is still recorded and still revertable.
		$this->assertCount( DR_Subs_Bulk_Runner::CHUNK_SIZE, DR_Subs_Fix_Journal::get_batch( $run['batch_id'] ) );
	}

	/**
	 * A subscription that stopped matching between queueing and running is
	 * counted as skipped, not applied, and does not stop the run.
	 */
	public function test_a_subscription_that_no_longer_matches_is_skipped() {
		$ghost = $this->make_scanned_ghost();
		$fixed = $this->make_scanned_ghost();

		// Something else repairs one of them before the run reaches it.
		as_schedule_single_action( time() + DAY_IN_SECONDS, self::PAYMENT_HOOK, array( $fixed ) );

		$run = DR_Subs_Bulk_Runner::start( 'ghost_sub', array( $ghost, $fixed ) );
		$this->drain( $run['batch_id'] );

		$progress = DR_Subs_Bulk_Runner::progress( $run['batch_id'] );
		$this->assertSame( 'done', $progress['status'] );
		$this->assertSame( 1, $progress['applied'] );
		$this->assertSame( 1, $progress['failed'] );
		$this->assertNotEmpty( $progress['errors'] );
	}

	/**
	 * Progress for an unknown batch reports unknown rather than throwing.
	 */
	public function test_progress_for_an_unknown_batch_is_safe() {
		$this->assertSame( 'unknown', DR_Subs_Bulk_Runner::progress( 'nosuchbatch' )['status'] );
	}

	/**
	 * Batch ids are echoed back by the client and end up in an option name,
	 * so anything that is not a plain token has to be rejected.
	 */
	public function test_batch_ids_are_validated() {
		$this->assertTrue( DR_Subs_Bulk_Runner::valid_batch_id( 'AbC123' ) );
		$this->assertFalse( DR_Subs_Bulk_Runner::valid_batch_id( '' ) );
		$this->assertFalse( DR_Subs_Bulk_Runner::valid_batch_id( '../../etc/passwd' ) );
		$this->assertFalse( DR_Subs_Bulk_Runner::valid_batch_id( 'has space' ) );
		$this->assertFalse( DR_Subs_Bulk_Runner::valid_batch_id( str_repeat( 'a', 41 ) ) );
	}
}
