<?php
/**
 * Doctor Subs - automated test data seeder.
 *
 * Run from the WordPress install root:
 *
 *     wp eval-file wp-content/plugins/doctor-subs/tools/seed-test-data.php
 *
 * Idempotent: deletes prior seeded fixtures (matched by `_dr_subs_seed`
 * meta) before creating fresh ones. Seeds one or more matches for every
 * Doctor Subs rule so the dashboard exercises the full detection
 * surface in a single scan.
 *
 * Patterns produced:
 *  - 1 healthy control (active sub + scheduled AS payment)
 *  - 1 ghost sub (active sub, no AS payment scheduled)
 *  - 1 repeated-failures sub (3 failed AS actions in last 30d)
 *  - 1 on-hold-paid sub (on-hold + Stripe-captured renewal order)
 *  - 25 mass-hold cascade subs (same product, on-hold, transitions within 1h)
 *  - 1 total-drift sub (stored total drifted from line items, modified 14d ago)
 *
 * @package Dr_Subs
 */

// Note: no `declare( strict_types=1 )` here. wp-cli's eval-file strips
// the leading `<?php` and evals the remainder, which makes `declare`
// no longer the first statement and triggers a fatal.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run via WP-CLI: wp eval-file wp-content/plugins/doctor-subs/tools/seed-test-data.php\n" );
	return;
}

if ( ! function_exists( 'wcs_create_subscription' ) ) {
	WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
}

if ( ! class_exists( 'DR_Subs_Migration' ) ) {
	WP_CLI::error( 'Doctor Subs is not active.' );
}

const DR_SEED_META  = '_dr_subs_seed';
const DR_SEED_GROUP = 'doctor-subs-seed';
const DR_SEED_HOOK  = 'woocommerce_scheduled_subscription_payment';

// ---------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------

clean_prior_seeds();

// ---------------------------------------------------------------------
// Shared fixtures
// ---------------------------------------------------------------------

$customer_id = ensure_seed_customer();
$product_id  = ensure_seed_product( 'Doctor Subs Test Product', 19.99 );
$cascade_pid = ensure_seed_product( 'Doctor Subs Cascade Product', 9.99 );

WP_CLI::log( "Customer #{$customer_id}, product #{$product_id}, cascade product #{$cascade_pid}." );

// ---------------------------------------------------------------------
// Seed
// ---------------------------------------------------------------------

$summary = array();

$summary['healthy']              = seed_healthy( $customer_id, $product_id );
$summary['ghost_sub']            = seed_ghost( $customer_id, $product_id );
$summary['repeated_failures']    = seed_repeated_failures( $customer_id, $product_id );
$summary['onhold_paid']          = seed_onhold_paid( $customer_id, $product_id );
$summary['mass_hold']            = seed_mass_hold( $customer_id, $cascade_pid, 25 );
$summary['total_drift']          = seed_total_drift( $customer_id, $product_id );
$summary['manual_renewal_drift'] = seed_manual_renewal_drift( $customer_id, $product_id );

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------

WP_CLI::success( 'Seed complete.' );
foreach ( $summary as $rule => $ids ) {
	$count = is_array( $ids ) ? count( $ids ) : 1;
	$tail  = is_array( $ids ) ? ( ' [ ' . implode( ', ', array_slice( $ids, 0, 5 ) ) . ( $count > 5 ? ', …' : '' ) . ' ]' ) : " #{$ids}";
	WP_CLI::log( sprintf( '  %-20s %d sub%s%s', $rule, $count, 1 === $count ? '' : 's', $tail ) );
}

WP_CLI::log( '' );
WP_CLI::log( 'Run a fresh scan to populate the dashboard:' );
WP_CLI::log( '  wp eval "DR_Subs_Health_Scanner::run_recurring();"' );

// =====================================================================
// Helpers
// =====================================================================

/**
 * Tag a post or order so the seeder can find + reset it next run.
 */
function dr_seed_mark( int $object_id ): void {
	update_post_meta( $object_id, DR_SEED_META, 1 );
}

/**
 * Wipe everything previously seeded by this script.
 */
function clean_prior_seeds(): void {
	global $wpdb;

	// Subs + parent orders (HPOS-aware: posts table also queried as
	// fallback for sites still on legacy CPT storage).
	$ids = array();

	if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {

		$orders = wc_get_orders(
			array(
				'type'       => array( 'shop_subscription', 'shop_order' ),
				'limit'      => -1,
				'status'     => 'any',
				'meta_key'   => DR_SEED_META,
				'meta_value' => 1,
				'return'     => 'ids',
			)
		);
		$ids    = array_merge( $ids, (array) $orders );
	}

	// Always scan postmeta too - covers CPT storage + dangling parent orders.
	$post_ids = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
			DR_SEED_META
		)
	);
	$ids      = array_unique( array_map( 'intval', array_merge( $ids, $post_ids ) ) );

	foreach ( $ids as $id ) {
		// wp_delete_post handles HPOS via the order CRUD layer when
		// post_type is shop_subscription / shop_order.
		wp_delete_post( $id, true );
	}

	// Seeded products.
	$products = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
			DR_SEED_META
		)
	);
	foreach ( $products as $pid ) {
		wp_delete_post( (int) $pid, true );
	}

	// Seeded customer.
	$user = get_user_by( 'login', 'doctor-subs-seed' );
	if ( $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}

	// Action Scheduler rows in our seed group.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( DR_SEED_HOOK, null, DR_SEED_GROUP );
	}

	// Status transitions inserted by the seed.
	$transitions = DR_Subs_Migration::status_transitions_table();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE from_status = %s', $transitions, '__seed__' ) );

	WP_CLI::log( sprintf( 'Cleaned %d prior seeded object(s).', count( $ids ) + count( $products ) ) );
}

/**
 * Create (or fetch) the seed customer.
 */
function ensure_seed_customer(): int {
	$user = get_user_by( 'login', 'doctor-subs-seed' );
	if ( $user ) {
		return (int) $user->ID;
	}
	$user_id = wp_insert_user(
		array(
			'user_login'   => 'doctor-subs-seed',
			'user_pass'    => wp_generate_password( 24, true ),
			'user_email'   => 'seed@doctor-subs.test',
			'first_name'   => 'Doctor',
			'last_name'    => 'Seed',
			'display_name' => 'Doctor Seed',
			'role'         => 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		WP_CLI::error( 'Could not create seed customer: ' . $user_id->get_error_message() );
	}
	update_user_meta( $user_id, 'billing_first_name', 'Doctor' );
	update_user_meta( $user_id, 'billing_last_name', 'Seed' );
	update_user_meta( $user_id, 'billing_email', 'seed@doctor-subs.test' );
	return (int) $user_id;
}

/**
 * Create (or fetch) a simple product to attach to subs.
 */
function ensure_seed_product( string $name, float $price ): int {
	$existing = get_posts(
		array(
			'post_type'   => 'product',
			'title'       => $name,
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_query'  => array(
				array(
					'key'   => DR_SEED_META,
					'value' => 1,
				),
			),
		)
	);
	if ( ! empty( $existing ) ) {
		return (int) $existing[0];
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_virtual( true );
	$id = $product->save();

	dr_seed_mark( $id );
	return (int) $id;
}

/**
 * Build a sub with one item. Returns the WC_Subscription.
 *
 * @param int    $customer_id
 * @param int    $product_id
 * @param string $status        Final status to set.
 * @param array  $extra         Extra args (period, interval).
 * @return WC_Subscription
 */
function dr_make_sub( int $customer_id, int $product_id, string $status = 'active', array $extra = array() ): WC_Subscription {
	$sub = wcs_create_subscription(
		array_merge(
			array(
				'customer_id'      => $customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				// Backdate 60 days so individual seeders can set
				// next_payment in the past (e.g. ghost-sub) without
				// tripping WCS's "next_payment must be after start_date"
				// validation.
				'start_date'       => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ),
				'status'           => 'pending',
			),
			$extra
		)
	);
	if ( is_wp_error( $sub ) ) {
		WP_CLI::error( 'wcs_create_subscription failed: ' . $sub->get_error_message() );
	}

	$product = wc_get_product( $product_id );
	$sub->add_product( $product, 1 );
	$sub->set_billing_email( 'seed@doctor-subs.test' );
	$sub->set_billing_first_name( 'Doctor' );
	$sub->set_billing_last_name( 'Seed' );
	$sub->set_payment_method( 'stripe' );
	$sub->calculate_totals();

	// WCS rejects direct pending->on-hold; go via active.
	if ( 'pending' !== $status ) {
		if ( 'on-hold' === $status ) {
			$sub->update_status( 'active', 'Doctor Subs seed.' );
			$sub->update_status( 'on-hold', 'Doctor Subs seed.' );
		} else {
			$sub->update_status( $status, 'Doctor Subs seed.' );
		}
	}

	$sub->save();
	dr_seed_mark( $sub->get_id() );

	return $sub;
}

/**
 * Healthy sub: active + scheduled AS payment in the future.
 */
function seed_healthy( int $customer_id, int $product_id ): int {
	$sub = dr_make_sub( $customer_id, $product_id, 'active' );

	$next = time() + ( 7 * DAY_IN_SECONDS );
	$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );

	as_schedule_single_action(
		$next,
		DR_SEED_HOOK,
		array( $sub->get_id() ),
		DR_SEED_GROUP
	);

	return $sub->get_id();
}

/**
 * Ghost sub: active, next_payment in the past, no AS event.
 */
function seed_ghost( int $customer_id, int $product_id ): int {
	$sub = dr_make_sub( $customer_id, $product_id, 'active' );

	$past = time() - ( 5 * DAY_IN_SECONDS );
	$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $past ) ) );

	// No AS event scheduled. Make sure none lingers.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( DR_SEED_HOOK, array( $sub->get_id() ) );
	}

	return $sub->get_id();
}

/**
 * Repeated failures sub: 3 failed AS actions in the last 30 days.
 */
function seed_repeated_failures( int $customer_id, int $product_id ): int {
	$sub = dr_make_sub( $customer_id, $product_id, 'active' );

	for ( $i = 1; $i <= 3; $i++ ) {
		$ts        = time() - ( $i * 3 * DAY_IN_SECONDS );
		$action_id = as_schedule_single_action(
			$ts,
			DR_SEED_HOOK,
			array( $sub->get_id() ),
			DR_SEED_GROUP
		);
		if ( $action_id && class_exists( 'ActionScheduler_Store' ) ) {
			try {
				ActionScheduler_Store::instance()->mark_failure( (int) $action_id );
			} catch ( \Throwable $t ) {
				// Already failed / completed - ignore.
			}
		}
	}

	// Schedule a fresh future event so this sub isn't ALSO a ghost.
	$next = time() + ( 7 * DAY_IN_SECONDS );
	$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );
	as_schedule_single_action( $next, DR_SEED_HOOK, array( $sub->get_id() ), DR_SEED_GROUP );

	return $sub->get_id();
}

/**
 * On-hold paid sub: on-hold status, latest renewal order has Stripe
 * capture meta.
 */
function seed_onhold_paid( int $customer_id, int $product_id ): int {
	$sub = dr_make_sub( $customer_id, $product_id, 'on-hold' );

	// Build a renewal order via WCS.
	if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
		WP_CLI::warning( 'wcs_create_renewal_order missing - on-hold-paid not seeded.' );
		return $sub->get_id();
	}

	$renewal = wcs_create_renewal_order( $sub );
	if ( is_wp_error( $renewal ) || ! $renewal ) {
		WP_CLI::warning( 'Renewal order creation failed for on-hold-paid seed.' );
		return $sub->get_id();
	}

	$renewal->set_status( 'on-hold' );
	$renewal->update_meta_data( '_stripe_charge_captured', 'yes' );
	$renewal->update_meta_data( '_stripe_charge_id', 'ch_doctor_seed_' . $sub->get_id() );
	$renewal->set_payment_method( 'stripe' );
	$renewal->save();

	dr_seed_mark( $renewal->get_id() );

	return $sub->get_id();
}

/**
 * Mass hold cascade: N on-hold subs sharing one product, with their
 * transitions logged in the same hour.
 *
 * @return array<int, int> sub_ids
 */
function seed_mass_hold( int $customer_id, int $product_id, int $count ): array {
	$ids = array();
	for ( $i = 0; $i < $count; $i++ ) {
		// dr_make_sub flips pending->active->on-hold. The
		// active->on-hold step fires woocommerce_subscription_status_updated,
		// which DR_Subs_Status_Transition_Log writes into the
		// transitions table - so the cascade index picks the cluster up
		// without any manual inserts here. All N transitions land within
		// milliseconds, well inside the 1h window.
		$sub   = dr_make_sub( $customer_id, $product_id, 'on-hold' );
		$ids[] = $sub->get_id();
	}
	return $ids;
}

/**
 * Total drift sub: sub.get_total() forced higher than line items, sub
 * modified 14 days ago so the rule's recent-change guard doesn't skip.
 */
/**
 * Manual-renewal drift sub: active, requires_manual_renewal=true, has
 * Stripe customer id stored. Backdated created_date so it's past the
 * 7-day "very recent" guard.
 */
function seed_manual_renewal_drift( int $customer_id, int $product_id ): int {
	$sub = dr_make_sub( $customer_id, $product_id, 'active' );

	$sub->set_requires_manual_renewal( true );
	$sub->set_payment_method( 'stripe' );
	$sub->update_meta_data( '_stripe_customer_id', 'cus_doctor_seed_' . $sub->get_id() );
	$sub->update_meta_data( '_stripe_source_id', 'src_doctor_seed_' . $sub->get_id() );

	// Backdate creation so it's past the recent-create guard.
	$sub->set_date_created( time() - ( 30 * DAY_IN_SECONDS ) );
	$sub->save();

	// Belt-and-braces postmeta write to mirror the bug's expected state.
	update_post_meta( $sub->get_id(), '_requires_manual_renewal', 'yes' );

	// No AS event scheduled (which is the bug - WCS skipped scheduling).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( DR_SEED_HOOK, array( $sub->get_id() ) );
	}

	return $sub->get_id();
}

function seed_total_drift( int $customer_id, int $product_id ): int {
	$sub = dr_make_sub( $customer_id, $product_id, 'active' );

	// Force total to drift +$8 from line items.
	$drifted = (float) $sub->get_total() + 8.0;
	$sub->set_total( $drifted );
	$sub->save();

	// Schedule a future renewal so this sub doesn't ALSO trip ghost.
	$next = time() + ( 14 * DAY_IN_SECONDS );
	$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );
	as_schedule_single_action( $next, DR_SEED_HOOK, array( $sub->get_id() ), DR_SEED_GROUP );

	// WC's CRUD layer resets date_modified to "now" on every save(), so
	// the rule's 7-day "recently modified" guard would skip this sub if
	// we relied on the setter. Backdate via direct DB writes after all
	// saves are done. Both posts table (legacy CPT path) and HPOS orders
	// table (modern path) get the same value.
	$past = gmdate( 'Y-m-d H:i:s', time() - ( 14 * DAY_IN_SECONDS ) );
	global $wpdb;
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => $past,
			'post_modified_gmt' => $past,
		),
		array( 'ID' => $sub->get_id() )
	);
	if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$wpdb->update(
			$wpdb->prefix . 'wc_orders',
			array( 'date_updated_gmt' => $past ),
			array( 'id' => $sub->get_id() )
		);
	}
	clean_post_cache( $sub->get_id() );

	return $sub->get_id();
}
