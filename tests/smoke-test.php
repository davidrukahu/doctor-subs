<?php
/**
 * Proves the harness boots: WordPress, WooCommerce, Subscriptions and the
 * plugin's own tables are all present before any real assertion runs.
 *
 * @package Dr_Subs
 */

/**
 * Harness smoke test.
 */
class DR_Subs_Smoke_Test extends DR_Subs_Test_Case {

	/**
	 * Every dependency the suite assumes is actually loaded.
	 */
	public function test_dependencies_are_loaded() {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce is not loaded' );
		$this->assertTrue( function_exists( 'wcs_create_subscription' ), 'Subscriptions is not loaded' );
		$this->assertTrue( class_exists( 'DR_Subs_Rules_Registry' ), 'Doctor Subs is not loaded' );
		$this->assertTrue( function_exists( 'as_schedule_single_action' ), 'Action Scheduler is not loaded' );
	}

	/**
	 * The plugin's tables exist, so journal writes have somewhere to go.
	 */
	public function test_plugin_tables_exist() {
		global $wpdb;

		foreach ( array( DR_Subs_Migration::sub_health_table(), DR_Subs_Migration::fix_journal_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Missing table {$table}" );
		}
	}

	/**
	 * The fixture builder produces a real, active subscription.
	 */
	public function test_fixture_builds_a_subscription() {
		$sub = $this->make_subscription( 'active' );

		$this->assertInstanceOf( 'WC_Subscription', $sub );
		$this->assertSame( 'active', $sub->get_status() );
		$this->assertFalse( $sub->get_requires_manual_renewal() );
	}
}
