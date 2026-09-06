<?php
/**
 * Apply/revert round trips for every rule that ships.
 *
 * Each test builds a fixture shaped to trip exactly one rule, confirms the rule
 * detects it, applies the fix, asserts the store actually changed, then reverts
 * and asserts the store is back where it started. A rule whose revert does not
 * restore the original state is worse than a rule with no fix at all, which is
 * what these are here to catch.
 *
 * @package Dr_Subs
 */

/**
 * Rule round-trip tests.
 */
class DR_Subs_Rules_Test extends DR_Subs_Test_Case {

	/**
	 * Apply a rule and record it, returning the journal entry id.
	 *
	 * @param string             $rule_id Rule id.
	 * @param DR_Subs_Rule_Match $match   Detected match.
	 * @return int
	 */
	private function apply_and_record( string $rule_id, DR_Subs_Rule_Match $match ): int {
		$payload  = $this->rule( $rule_id )->apply_fix( $match );
		$entry_id = DR_Subs_Fix_Journal::record( $match->sub_id, $rule_id, $payload );
		$this->assertGreaterThan( 0, $entry_id );
		return $entry_id;
	}

	// -----------------------------------------------------------------
	// ghost_sub
	// -----------------------------------------------------------------

	/**
	 * Ghost: active sub, next payment in the past, no scheduled event.
	 *
	 * @return WC_Subscription
	 */
	private function make_ghost(): WC_Subscription {
		$sub = $this->make_subscription( 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ) ) );
		$this->clear_payment_actions( $sub->get_id() );
		return wcs_get_subscription( $sub->get_id() );
	}

	/**
	 * The scheduled event is created by apply and removed by revert.
	 */
	public function test_ghost_sub_round_trip() {
		$sub = $this->make_ghost();

		$match = $this->detect( 'ghost_sub', $sub->get_id() );
		$this->assertNotNull( $match, 'ghost_sub did not detect its own fixture' );
		$this->assertSame( 'broken', $match->bucket );
		$this->assertSame( 0, $this->pending_payment_actions( $sub->get_id() ) );

		$entry_id = $this->apply_and_record( 'ghost_sub', $match );
		$this->assertSame( 1, $this->pending_payment_actions( $sub->get_id() ), 'apply did not schedule a renewal' );

		$result = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertTrue( (bool) $result['success'], $result['message'] ?? '' );
		$this->assertSame( 0, $this->pending_payment_actions( $sub->get_id() ), 'revert left the scheduled renewal behind' );
	}

	/**
	 * A sub with a future payment already scheduled is not a ghost.
	 */
	public function test_ghost_sub_ignores_a_healthy_subscription() {
		$sub  = $this->make_subscription( 'active' );
		$next = time() + ( 7 * DAY_IN_SECONDS );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );
		as_schedule_single_action( $next, self::PAYMENT_HOOK, array( $sub->get_id() ) );

		$this->assertNull( $this->detect( 'ghost_sub', $sub->get_id() ) );
	}

	/**
	 * A manual-renewal sub belongs to manual_renewal_drift, not ghost_sub.
	 */
	public function test_ghost_sub_skips_manual_renewal_subs() {
		$sub = $this->make_ghost();
		$sub->set_requires_manual_renewal( true );
		$sub->save();

		$this->assertNull( $this->detect( 'ghost_sub', $sub->get_id() ) );
	}

	// -----------------------------------------------------------------
	// manual_renewal_drift
	// -----------------------------------------------------------------

	/**
	 * The flag is cleared by apply and restored by revert.
	 */
	public function test_manual_renewal_drift_round_trip() {
		$sub = $this->make_subscription( 'active' );
		$sub->set_requires_manual_renewal( true );
		$sub->update_meta_data( '_stripe_customer_id', 'cus_test123' );
		// The rule ignores subscriptions created in the last 7 days, and
		// wcs_create_subscription stamps date_created as now whatever
		// start_date says, so age the fixture explicitly.
		$sub->set_date_created( time() - ( 60 * DAY_IN_SECONDS ) );
		$sub->save();

		$match = $this->detect( 'manual_renewal_drift', $sub->get_id() );
		$this->assertNotNull( $match, 'manual_renewal_drift did not detect its own fixture' );

		$entry_id = $this->apply_and_record( 'manual_renewal_drift', $match );

		$after = wcs_get_subscription( $sub->get_id() );
		$this->assertFalse( $after->get_requires_manual_renewal(), 'apply did not clear the manual-renewal flag' );

		$result = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertTrue( (bool) $result['success'], $result['message'] ?? '' );

		$reverted = wcs_get_subscription( $sub->get_id() );
		$this->assertTrue( $reverted->get_requires_manual_renewal(), 'revert did not restore the manual-renewal flag' );
	}

	/**
	 * Without a stored card the manual flag is probably deliberate, so the
	 * rule must leave it alone.
	 */
	public function test_manual_renewal_drift_needs_a_stored_card() {
		$sub = $this->make_subscription( 'active' );
		$sub->set_requires_manual_renewal( true );
		$sub->set_date_created( time() - ( 60 * DAY_IN_SECONDS ) );
		$sub->save();

		$this->assertNull( $this->detect( 'manual_renewal_drift', $sub->get_id() ) );
	}

	/**
	 * Regression: the flag has to be spelled the way Subscriptions spells it.
	 *
	 * apply_fix mirrored the cleared flag to postmeta as 'no'. Subscriptions
	 * treats every value except the exact string 'false' as TRUE, so that
	 * mirror re-broke the subscription the fix had just repaired on any store
	 * where postmeta is canonical. Shipped in 2.2.0 and caught by this suite.
	 */
	public function test_manual_renewal_drift_writes_the_flag_subscriptions_can_read() {
		$sub = $this->make_subscription( 'active' );
		$sub->set_requires_manual_renewal( true );
		$sub->update_meta_data( '_stripe_customer_id', 'cus_test123' );
		$sub->set_date_created( time() - ( 60 * DAY_IN_SECONDS ) );
		$sub->save();

		$match = $this->detect( 'manual_renewal_drift', $sub->get_id() );
		$this->rule( 'manual_renewal_drift' )->apply_fix( $match );

		$this->assertSame(
			'false',
			get_post_meta( $sub->get_id(), '_requires_manual_renewal', true ),
			"postmeta must say 'false'; Subscriptions reads anything else as manual renewal ON"
		);
	}

	// -----------------------------------------------------------------
	// mass_hold
	// -----------------------------------------------------------------

	/**
	 * A cascade of on-hold subs on one product is reactivated by apply and
	 * put back on hold by revert.
	 */
	public function test_mass_hold_round_trip() {
		$subs = array();
		for ( $i = 0; $i < DR_Subs_Rule_Mass_Hold::MIN_CASCADE_SIZE + 2; $i++ ) {
			$subs[] = $this->make_subscription( 'on-hold' );
		}

		$first = $subs[0];
		$match = $this->detect( 'mass_hold', $first->get_id() );
		$this->assertNotNull( $match, 'mass_hold did not detect a cascade of ' . count( $subs ) );

		$entry_id = $this->apply_and_record( 'mass_hold', $match );

		$this->assertSame( 'active', wcs_get_subscription( $first->get_id() )->get_status(), 'apply did not reactivate' );

		$result = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertTrue( (bool) $result['success'], $result['message'] ?? '' );
		$this->assertSame( 'on-hold', wcs_get_subscription( $first->get_id() )->get_status(), 'revert did not restore on-hold' );
	}

	/**
	 * A handful of on-hold subs is not a cascade and must not be flagged.
	 */
	public function test_mass_hold_ignores_a_small_group() {
		$sub = $this->make_subscription( 'on-hold' );
		$this->make_subscription( 'on-hold' );
		$this->make_subscription( 'on-hold' );

		$this->assertNull( $this->detect( 'mass_hold', $sub->get_id() ) );
	}

	// -----------------------------------------------------------------
	// onhold_paid
	// -----------------------------------------------------------------

	/**
	 * On-hold subscription whose renewal order was actually captured.
	 *
	 * @return WC_Subscription
	 */
	private function make_onhold_paid(): WC_Subscription {
		$sub = $this->make_subscription( 'on-hold' );

		$renewal = wcs_create_renewal_order( $sub );
		$this->assertNotWPError( $renewal );
		$renewal->set_status( 'processing' );
		$renewal->set_payment_method( 'stripe' );
		$renewal->update_meta_data( '_stripe_charge_captured', 'yes' );
		$renewal->update_meta_data( '_stripe_charge_id', 'ch_test_123' );
		$renewal->set_transaction_id( 'ch_test_123' );
		$renewal->save();

		// Marking the renewal paid makes Subscriptions activate the parent,
		// which is exactly the step that does not happen in the wild when this
		// bug bites. Put the subscription back on hold so the fixture is the
		// broken state the rule is meant to find: money captured, sub stuck.
		$stuck = wcs_get_subscription( $sub->get_id() );
		if ( 'on-hold' !== $stuck->get_status() ) {
			$stuck->update_status( 'on-hold', 'Test fixture: renewal paid but status never flipped.' );
		}

		return wcs_get_subscription( $sub->get_id() );
	}

	/**
	 * Apply completes the order and reactivates; revert puts both back.
	 */
	public function test_onhold_paid_round_trip() {
		$sub = $this->make_onhold_paid();

		$match = $this->detect( 'onhold_paid', $sub->get_id() );
		$this->assertNotNull( $match, 'onhold_paid did not detect its own fixture' );

		$entry_id = $this->apply_and_record( 'onhold_paid', $match );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status(), 'apply did not reactivate' );

		$result = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertTrue( (bool) $result['success'], $result['message'] ?? '' );
		$this->assertSame( 'on-hold', wcs_get_subscription( $sub->get_id() )->get_status(), 'revert did not restore on-hold' );
	}

	// -----------------------------------------------------------------
	// repeated_failures
	// -----------------------------------------------------------------

	/**
	 * Two failed renewal actions in the window trip the rule, apply schedules
	 * a retry, and revert removes it.
	 */
	public function test_repeated_failures_round_trip() {
		$sub = $this->make_subscription( 'active' );

		// Record real failed Action Scheduler rows, which is what the rule
		// counts. Scheduling then failing is the only honest way to get one.
		for ( $i = 0; $i < 3; $i++ ) {
			$action_id = as_schedule_single_action(
				time() - ( ( $i + 1 ) * DAY_IN_SECONDS ),
				self::PAYMENT_HOOK,
				array( $sub->get_id() )
			);
			ActionScheduler::store()->mark_failure( $action_id );
		}

		$match = $this->detect( 'repeated_failures', $sub->get_id() );
		$this->assertNotNull( $match, 'repeated_failures did not detect three failed actions' );

		$before = $this->pending_payment_actions( $sub->get_id() );
		$entry_id = $this->apply_and_record( 'repeated_failures', $match );
		$this->assertSame( $before + 1, $this->pending_payment_actions( $sub->get_id() ), 'apply did not schedule a retry' );

		$result = DR_Subs_Fix_Journal::revert( $entry_id );
		$this->assertTrue( (bool) $result['success'], $result['message'] ?? '' );
		$this->assertSame( $before, $this->pending_payment_actions( $sub->get_id() ), 'revert did not remove the retry' );
	}

	/**
	 * A single failure is not a pattern.
	 */
	public function test_repeated_failures_ignores_one_failure() {
		$sub       = $this->make_subscription( 'active' );
		$action_id = as_schedule_single_action( time() - DAY_IN_SECONDS, self::PAYMENT_HOOK, array( $sub->get_id() ) );
		ActionScheduler::store()->mark_failure( $action_id );

		$this->assertNull( $this->detect( 'repeated_failures', $sub->get_id() ) );
	}

	// -----------------------------------------------------------------
	// total_drift (flag only)
	// -----------------------------------------------------------------

	/**
	 * Total drift detects, advertises itself as manual-only, and refuses to
	 * apply. Automatic correction is unsafe because a legitimate adjustment
	 * and a broken one look identical.
	 */
	public function test_total_drift_detects_but_refuses_to_apply() {
		$sub = $this->make_subscription( 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + ( 14 * DAY_IN_SECONDS ) ) ) );
		$sub->set_total( (float) $sub->get_total() + 8.0 );
		$sub->save();
		$this->backdate_modified( $sub->get_id() );

		$match = $this->detect( 'total_drift', $sub->get_id() );
		$this->assertNotNull( $match, 'total_drift did not detect an 8.00 gap' );
		$this->assertSame( 'risk', $match->bucket );

		$preview = $this->rule( 'total_drift' )->preview_fix( $match );
		$this->assertTrue( ! empty( $preview['manual_only'] ), 'total_drift did not advertise manual_only' );

		$this->expectException( 'RuntimeException' );
		$this->rule( 'total_drift' )->apply_fix( $match );
	}

	/**
	 * A recently edited subscription is skipped, because a fresh edit is
	 * usually the merchant doing it on purpose.
	 */
	public function test_total_drift_skips_a_recently_modified_subscription() {
		$sub = $this->make_subscription( 'active' );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + ( 14 * DAY_IN_SECONDS ) ) ) );
		$sub->set_total( (float) $sub->get_total() + 8.0 );
		$sub->save();

		// No backdating: date_modified is now.
		$this->assertNull( $this->detect( 'total_drift', $sub->get_id() ) );
	}

	/**
	 * Push date_modified into the past.
	 *
	 * WooCommerce's CRUD resets it to now on every save, so the only way to
	 * age a fixture is to write the columns directly after the last save.
	 *
	 * @param int $sub_id Subscription id.
	 */
	private function backdate_modified( int $sub_id ): void {
		global $wpdb;

		$past = gmdate( 'Y-m-d H:i:s', time() - ( 14 * DAY_IN_SECONDS ) );

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $past,
				'post_modified_gmt' => $past,
			),
			array( 'ID' => $sub_id )
		);

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $past ), array( 'id' => $sub_id ) );
		}

		clean_post_cache( $sub_id );
		wp_cache_flush();
	}

	// -----------------------------------------------------------------
	// Registry contract
	// -----------------------------------------------------------------

	/**
	 * Every rule the catalog advertises is actually registered and answers
	 * the whole interface. A rule in the catalog but not the registry shows
	 * an empty filter chip to the merchant.
	 */
	public function test_every_catalogued_rule_is_registered() {
		$registry = new DR_Subs_Rules_Registry();

		$catalog = DR_Subs_Rule_Catalog::all();
		$this->assertNotEmpty( $catalog, 'the rule catalog is empty' );

		foreach ( $catalog as $rule_id => $entry ) {
			$this->assertNotEmpty( $entry['label'] ?? '', "catalog entry {$rule_id} has no label" );

			$rule = $registry->get( $rule_id );
			$this->assertNotNull( $rule, "catalogued rule {$rule_id} is not registered" );
			$this->assertSame( $rule_id, $rule->id() );
			$this->assertNotEmpty( $rule->label(), "{$rule_id} has no label" );
			$this->assertContains( $rule->bucket(), array( 'risk', 'broken' ), "{$rule_id} has an unknown bucket" );
			$this->assertNotEmpty( $rule->tracked_fields(), "{$rule_id} tracks no fields" );
		}
	}
}
