<?php
/**
 * Doctor Subs - destructive wipe of ALL orders/subs/refunds + Doctor
 * Subs scan & journal tables. Local sandbox only.
 *
 * Run from the WordPress install root:
 *
 *     wp eval-file wp-content/plugins/doctor-subs/tools/wipe-test-data.php
 *
 * @package Dr_Subs
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "Run via WP-CLI: wp eval-file wp-content/plugins/doctor-subs/tools/wipe-test-data.php\n" );
	return;
}

// Posts/CPT path - covers legacy storage and HPOS-with-sync.
$ids = get_posts(
	array(
		'post_type'   => array( 'shop_subscription', 'shop_order', 'shop_order_refund' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}

// HPOS path - orders that exist only in the orders table.
if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
	&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$orders = wc_get_orders(
		array(
			'type'   => array( 'shop_subscription', 'shop_order' ),
			'status' => 'any',
			'limit'  => -1,
			'return' => 'ids',
		)
	);
	foreach ( $orders as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
}

global $wpdb;
$wpdb->query( "TRUNCATE {$wpdb->prefix}dr_subs_sub_health" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}dr_subs_fix_journal" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}dr_subs_status_transitions" );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'woocommerce_scheduled_subscription_payment', null, 'doctor-subs' );
	as_unschedule_all_actions( 'woocommerce_scheduled_subscription_payment', null, 'doctor-subs-seed' );
}

WP_CLI::success( sprintf( 'Wiped %d posts + Doctor Subs tables.', count( $ids ) ) );
