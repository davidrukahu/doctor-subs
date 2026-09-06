<?php
/**
 * Shared base class for the Doctor Subs integration tests.
 *
 * Provides subscription fixtures shaped to trip exactly one rule each, plus
 * the Action Scheduler helpers the rules read. Every fixture is built through
 * the real WooCommerce Subscriptions CRUD, never by writing meta directly, so
 * the tests exercise the same code path a store does.
 *
 * @package Dr_Subs
 */

/**
 * Base test case.
 */
abstract class DR_Subs_Test_Case extends WP_UnitTestCase {

	/**
	 * The Action Scheduler hook WooCommerce Subscriptions schedules renewals on.
	 */
	const PAYMENT_HOOK = 'woocommerce_scheduled_subscription_payment';

	/**
	 * Product used by every fixture subscription.
	 *
	 * @var WC_Product|null
	 */
	protected $product;

	/**
	 * Id of that product. Fixtures re-fetch by id rather than reusing the
	 * object, which goes stale across repeated add_product() calls and makes
	 * Subscriptions report the line item's product as unavailable.
	 *
	 * @var int
	 */
	protected $product_id = 0;

	/**
	 * Customer used by every fixture subscription.
	 *
	 * @var int
	 */
	protected $customer_id = 0;

	/**
	 * Skip the whole suite when WooCommerce Subscriptions is absent, rather
	 * than failing with a confusing "undefined function" further down.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! function_exists( 'wcs_create_subscription' ) ) {
			self::markTestSkipped( 'WooCommerce Subscriptions is not available.' );
		}
	}

	/**
	 * Build the shared product and customer, and clear the health and journal
	 * tables so counts asserted in a test are that test's own.
	 */
	public function set_up() {
		parent::set_up();

		// WP_UnitTestCase rolls each test back in a transaction, so auto
		// increment ids restart and a fresh order can be handed the previous
		// test's cached line items. WooCommerce caches order items by order id,
		// so without this a fixture ends up carrying a stale item whose product
		// no longer exists, and Subscriptions then refuses to activate it.
		wp_cache_flush();
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'orders' );
		}

		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );

		// A real subscription product, not a simple one. Subscriptions blocks
		// activation of any subscription that contains_unavailable_product(),
		// and a simple product counts as unavailable, so a simple-product
		// fixture can only be activated by an admin. That made fixtures pass
		// or fail depending on which test had run first.
		$this->product = class_exists( 'WC_Product_Subscription' )
			? new WC_Product_Subscription()
			: new WC_Product_Simple();

		$this->product->set_name( 'Test Plan' );
		$this->product->set_regular_price( '20.00' );
		// Subscriptions treats a non-published product as unavailable, which
		// blocks activation of any subscription containing it.
		$this->product->set_status( 'publish' );
		$this->product->set_catalog_visibility( 'visible' );

		if ( $this->product instanceof WC_Product_Subscription ) {
			$this->product->update_meta_data( '_subscription_price', '20.00' );
			$this->product->update_meta_data( '_subscription_period', 'month' );
			$this->product->update_meta_data( '_subscription_period_interval', '1' );
			$this->product->update_meta_data( '_subscription_length', '0' );
		}

		$this->product->save();
		$this->product_id = $this->product->get_id();

		$this->truncate_plugin_tables();
	}

	/**
	 * Empty the plugin's own tables between tests. The WordPress test case
	 * rolls back core tables, but these are created outside that transaction.
	 */
	protected function truncate_plugin_tables(): void {
		global $wpdb;

		$tables = array(
			DR_Subs_Migration::sub_health_table(),
			DR_Subs_Migration::fix_journal_table(),
			DR_Subs_Migration::status_transitions_table(),
		);

		foreach ( array_filter( $tables ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test teardown against a scratch database.
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}

	/**
	 * Create a subscription through the real CRUD.
	 *
	 * Backdates the start by 60 days so individual fixtures can put
	 * next_payment in the past without tripping the "next payment must be
	 * after start date" validation.
	 *
	 * @param string $status Status to leave the subscription in.
	 * @param array  $args   Extra arguments for wcs_create_subscription().
	 * @return WC_Subscription
	 */
	protected function make_subscription( string $status = 'active', array $args = array() ): WC_Subscription {
		$sub = wcs_create_subscription(
			array_merge(
				array(
					'customer_id'      => $this->customer_id,
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'start_date'       => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
					'status'           => 'pending',
				),
				$args
			)
		);

		$this->assertNotWPError( $sub, 'wcs_create_subscription failed' );

		// Start from a known-empty line-item set. WooCommerce caches order items
		// by order id and the per-test transaction rollback recycles those ids,
		// so a freshly created subscription can arrive already carrying an item
		// that points at a product which no longer exists. Subscriptions then
		// reports the subscription as containing an unavailable product and
		// refuses to activate it.
		$sub->remove_order_items();
		$sub->add_product( wc_get_product( $this->product_id ), 1 );
		$sub->set_billing_first_name( 'Test' );
		$sub->set_billing_last_name( 'Customer' );
		$sub->set_billing_email( 'test.customer@example.test' );
		$sub->set_payment_method( 'stripe' );
		$sub->calculate_totals();

		// Drive the status transitions with manual renewal on. No real payment
		// gateway is registered in the test environment, and Subscriptions
		// gates some transitions on gateway support; a manual-renewal
		// subscription is always allowed to move.
		$sub->set_requires_manual_renewal( true );
		$sub->save();

		if ( 'pending' !== $status ) {
			// Subscriptions rejects a direct pending -> on-hold transition.
			if ( 'on-hold' === $status ) {
				$sub->update_status( 'active', 'Test fixture.' );
				$sub->update_status( 'on-hold', 'Test fixture.' );
			} else {
				$sub->update_status( $status, 'Test fixture.' );
			}
		}

		// Most rules deliberately skip manual-renewal subscriptions, so leave
		// the fixture on auto-renew unless the test turns it back on itself.
		$sub->set_requires_manual_renewal( false );
		$sub->save();

		return wcs_get_subscription( $sub->get_id() );
	}

	/**
	 * Count pending Action Scheduler renewal events for a subscription.
	 *
	 * @param int $sub_id Subscription id.
	 * @return int
	 */
	protected function pending_payment_actions( int $sub_id ): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'   => self::PAYMENT_HOOK,
					'args'   => array( $sub_id ),
					'status' => ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
	}

	/**
	 * Remove every scheduled renewal event for a subscription.
	 *
	 * @param int $sub_id Subscription id.
	 */
	protected function clear_payment_actions( int $sub_id ): void {
		as_unschedule_all_actions( self::PAYMENT_HOOK, array( $sub_id ) );
	}

	/**
	 * Run one rule's detection over one subscription and return the match.
	 *
	 * @param string $rule_id Rule id from the registry.
	 * @param int    $sub_id  Subscription id.
	 * @return DR_Subs_Rule_Match|null
	 */
	protected function detect( string $rule_id, int $sub_id ) {
		$registry = new DR_Subs_Rules_Registry();
		$rule     = $registry->get( $rule_id );

		$this->assertNotNull( $rule, "Rule {$rule_id} is not registered." );

		$matches = $rule->detect_batch( array( $sub_id ), new DR_Subs_Scan_Context() );

		return $matches[0] ?? null;
	}

	/**
	 * Fetch a rule instance from the registry.
	 *
	 * @param string $rule_id Rule id.
	 * @return DR_Subs_Rule_Interface
	 */
	protected function rule( string $rule_id ) {
		$registry = new DR_Subs_Rules_Registry();
		$rule     = $registry->get( $rule_id );
		$this->assertNotNull( $rule, "Rule {$rule_id} is not registered." );
		return $rule;
	}
}
