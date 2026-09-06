<?php
/**
 * Journal round-trip tests.
 *
 * The journal is what makes every fix in this plugin reversible, and it is the
 * single largest correctness risk in a write-heavy plugin. These tests assert
 * the whole loop: record an applied fix, read it back, revert it, and confirm
 * the row is marked reverted rather than silently left as applied.
 *
 * @package Dr_Subs
 */

/**
 * Fix journal tests.
 */
class DR_Subs_Journal_Test extends DR_Subs_Test_Case {

	/**
	 * A recorded entry comes back with every field it was given.
	 */
	public function test_record_then_read_preserves_the_payload() {
		$sub = $this->make_subscription( 'active' );

		$payload = array(
			'before_state'      => array(
				'status' => 'active',
				'total'  => '20.00',
			),
			'before_state_hash' => 'deadbeef',
			'after_state'       => array(
				'status' => 'active',
				'total'  => '25.00',
			),
			'side_effects'      => array( array( 'type' => 'noop' ) ),
		);

		$entry_id = DR_Subs_Fix_Journal::record( $sub->get_id(), 'ghost_sub', $payload );
		$this->assertGreaterThan( 0, $entry_id, 'record() returned no entry id' );

		$entry = DR_Subs_Fix_Journal::get( $entry_id );

		$this->assertNotNull( $entry );
		$this->assertSame( (int) $sub->get_id(), (int) $entry->sub_id );
		$this->assertSame( 'ghost_sub', $entry->rule_id );
		$this->assertSame( 'applied', $entry->status );
		$this->assertSame( 'deadbeef', $entry->before_state_hash );
		$this->assertSame( $payload['before_state'], json_decode( $entry->before_state, true ) );
		$this->assertSame( $payload['after_state'], json_decode( $entry->after_state, true ) );
		$this->assertSame( $payload['side_effects'], json_decode( $entry->side_effects, true ) );
	}

	/**
	 * A batch id groups entries and get_batch() returns them in insert order.
	 *
	 * This is the data behind the dashboard's "fixed N subscriptions in one
	 * batch" line, which read a hardcoded 1 before 2.2.0.
	 */
	public function test_get_batch_returns_every_entry_in_the_batch() {
		$batch_id = 'AbC123XyZ';
		$expected = array();

		foreach ( range( 1, 4 ) as $i ) {
			$sub        = $this->make_subscription( 'active' );
			$expected[] = $sub->get_id();

			DR_Subs_Fix_Journal::record(
				$sub->get_id(),
				'ghost_sub',
				array(
					'before_state'      => array( 'n' => $i ),
					'before_state_hash' => 'hash' . $i,
					'after_state'       => array( 'n' => $i + 1 ),
					'side_effects'      => array(),
				),
				$batch_id
			);
		}

		$batch = DR_Subs_Fix_Journal::get_batch( $batch_id );

		$this->assertCount( 4, $batch );
		$this->assertSame( $expected, array_map( 'intval', wp_list_pluck( $batch, 'sub_id' ) ) );

		// Entries outside the batch must not leak in.
		$other = $this->make_subscription( 'active' );
		DR_Subs_Fix_Journal::record( $other->get_id(), 'ghost_sub', array(), null );
		$this->assertCount( 4, DR_Subs_Fix_Journal::get_batch( $batch_id ) );
	}

	/**
	 * A mixed-case batch id is stored with its case intact.
	 *
	 * sanitize_key() lowercased the incoming id before 2.2.0. On MySQL's
	 * default case-insensitive collation a lowercased lookup still matches, so
	 * that bug stayed hidden on most hosts; on a case-sensitive collation
	 * (utf8mb4_bin) it makes bulk undo unreachable. This asserts the stored
	 * value, which is the part that has to be right either way, rather than
	 * asserting a lowercase lookup misses - that depends on the collation, not
	 * on the plugin.
	 */
	public function test_batch_id_case_is_preserved() {
		$sub      = $this->make_subscription( 'active' );
		$batch_id = 'MiXeDCase123';

		DR_Subs_Fix_Journal::record( $sub->get_id(), 'ghost_sub', array(), $batch_id );

		$batch = DR_Subs_Fix_Journal::get_batch( $batch_id );
		$this->assertCount( 1, $batch );
		$this->assertSame( $batch_id, $batch[0]->batch_id, 'the batch id was not stored verbatim' );
	}

	/**
	 * Reverting an entry marks it reverted and stamps reverted_at.
	 */
	public function test_revert_marks_the_entry_reverted() {
		$sub = $this->make_subscription( 'active' );
		$this->clear_payment_actions( $sub->get_id() );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ) ) );

		$match = $this->detect( 'ghost_sub', $sub->get_id() );
		$this->assertNotNull( $match, 'fixture did not trip ghost_sub' );

		$payload  = $this->rule( 'ghost_sub' )->apply_fix( $match );
		$entry_id = DR_Subs_Fix_Journal::record( $sub->get_id(), 'ghost_sub', $payload );

		$result = DR_Subs_Fix_Journal::revert( $entry_id );

		$this->assertTrue( (bool) $result['success'], 'revert reported failure: ' . ( $result['message'] ?? '' ) );

		$entry = DR_Subs_Fix_Journal::get( $entry_id );
		$this->assertSame( 'reverted', $entry->status );
		$this->assertNotEmpty( $entry->reverted_at );
	}

	/**
	 * Reverting an unknown entry fails cleanly instead of fatalling.
	 */
	public function test_revert_of_a_missing_entry_fails_without_throwing() {
		$result = DR_Subs_Fix_Journal::revert( 999999 );

		$this->assertIsArray( $result );
		$this->assertFalse( (bool) ( $result['success'] ?? false ) );
	}

	/**
	 * Reverting twice does not double-apply. The second call must not report
	 * a fresh success, or the UI would offer undo on an already-undone row.
	 */
	public function test_revert_is_not_repeatable() {
		$sub = $this->make_subscription( 'active' );
		$this->clear_payment_actions( $sub->get_id() );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ) ) );

		$match    = $this->detect( 'ghost_sub', $sub->get_id() );
		$payload  = $this->rule( 'ghost_sub' )->apply_fix( $match );
		$entry_id = DR_Subs_Fix_Journal::record( $sub->get_id(), 'ghost_sub', $payload );

		$first = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertTrue( (bool) $first['success'] );

		$second = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertFalse( (bool) ( $second['success'] ?? false ), 'a second revert reported success' );
	}

	/**
	 * Reverting a batch reverts every entry in it.
	 */
	public function test_revert_batch_reverts_every_entry() {
		$batch_id = 'BatchRevert1';
		$subs     = array();

		foreach ( range( 1, 3 ) as $unused ) {
			$sub = $this->make_subscription( 'active' );
			$this->clear_payment_actions( $sub->get_id() );
			$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ) ) );

			$match   = $this->detect( 'ghost_sub', $sub->get_id() );
			$payload = $this->rule( 'ghost_sub' )->apply_fix( $match );
			DR_Subs_Fix_Journal::record( $sub->get_id(), 'ghost_sub', $payload, $batch_id );

			$subs[] = $sub->get_id();
		}

		foreach ( $subs as $sub_id ) {
			$this->assertSame( 1, $this->pending_payment_actions( $sub_id ), "apply did not schedule for {$sub_id}" );
		}

		DR_Subs_Fix_Journal::revert_batch( $batch_id );

		foreach ( $subs as $sub_id ) {
			$this->assertSame( 0, $this->pending_payment_actions( $sub_id ), "batch revert left a scheduled action on {$sub_id}" );
		}

		foreach ( DR_Subs_Fix_Journal::get_batch( $batch_id ) as $entry ) {
			$this->assertSame( 'reverted', $entry->status );
		}
	}
}
