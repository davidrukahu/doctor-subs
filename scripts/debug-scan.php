<?php
/**
 * Doctor Subs - local scan debugger.
 *
 * Dumps what each detection rule sees for every active subscription, plus
 * the current row in dr_subs_sub_health for comparison. Use when the
 * dashboard shows "all healthy" but you expect broken subs.
 *
 * Run via Local's Site Shell:
 *   wp eval-file wp-content/plugins/doctor-subs/scripts/debug-scan.php
 *
 * Output: plain text table. Share the output back for diagnosis.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run this via wp eval-file, not directly.\n";
	exit;
}

if ( ! class_exists( 'DR_Subs_Rules_Registry' ) ) {
	echo "Doctor Subs plugin not active. Activate it first.\n";
	exit;
}

if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
	echo "WooCommerce Subscriptions not active.\n";
	exit;
}

echo "=== Doctor Subs scan debugger ===\n\n";

// 1. Version + last scan timestamp.
$last_scan_ts = get_option( 'dr_subs_last_scan_ts', 0 );
echo "Plugin version:   " . ( defined( 'DR_SUBS_PLUGIN_VERSION' ) ? DR_SUBS_PLUGIN_VERSION : '?' ) . "\n";
echo "Last scan ran at: " . ( $last_scan_ts ? gmdate( 'Y-m-d H:i:s', (int) $last_scan_ts ) . ' UTC' : 'NEVER' ) . "\n";
echo "Current UTC time: " . gmdate( 'Y-m-d H:i:s' ) . "\n\n";

// 2. sub_health table snapshot.
global $wpdb;
$health_table = DR_Subs_Migration::sub_health_table();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$health_rows = $wpdb->get_results( "SELECT sub_id, bucket, matched_rules, last_scanned_at FROM {$health_table} ORDER BY sub_id" );
echo "sub_health table: " . count( (array) $health_rows ) . " rows\n";
if ( $health_rows ) {
	foreach ( $health_rows as $r ) {
		echo sprintf(
			"  sub=%-6d bucket=%-8s scanned=%s matched=%s\n",
			(int) $r->sub_id,
			$r->bucket,
			$r->last_scanned_at,
			$r->matched_rules ?: '[]'
		);
	}
} else {
	echo "  (empty - scan has never run OR store has no active subs)\n";
}
echo "\n";

// 3. Count active subs, grouped by status.
$active = wcs_get_subscriptions( array( 'subscriptions_per_page' => -1, 'subscription_status' => 'active' ) );
$on_hold = wcs_get_subscriptions( array( 'subscriptions_per_page' => -1, 'subscription_status' => 'on-hold' ) );
echo "Subs: " . count( $active ) . " active, " . count( $on_hold ) . " on-hold\n\n";

if ( empty( $active ) && empty( $on_hold ) ) {
	echo "No subs to test. Seed some first.\n";
	exit;
}

// 4. For each sub, dump the raw signals + what each rule says.
DR_Subs_Rules_Registry::bootstrap();
$rules   = DR_Subs_Rules_Registry::all();
$context = new DR_Subs_Scan_Context();

echo "=== Per-sub rule evaluation ===\n\n";

$subs_to_check = array_merge( $active, $on_hold );
$max_subs      = 20;
if ( count( $subs_to_check ) > $max_subs ) {
	echo "(Showing first {$max_subs} of " . count( $subs_to_check ) . " subs)\n\n";
	$subs_to_check = array_slice( $subs_to_check, 0, $max_subs );
}

foreach ( $subs_to_check as $sub ) {
	$sub_id       = $sub->get_id();
	$status       = $sub->get_status();
	$next_payment = $sub->get_time( 'next_payment' );
	$has_pending  = $context->has_pending_as( $sub_id );
	$failed_count = $context->failed_as_count_for( $sub_id );

	echo sprintf(
		"sub=%d status=%s next_payment=%s pending_AS=%s failed_30d=%d\n",
		$sub_id,
		$status,
		$next_payment ? gmdate( 'Y-m-d H:i', $next_payment ) . ' UTC' : 'none',
		$has_pending ? 'yes' : 'NO',
		$failed_count
	);

	// Run every rule's detector.
	foreach ( $rules as $rule_id => $rule ) {
		$matches = $rule->detect_batch( array( $sub_id ), $context );
		if ( ! empty( $matches ) ) {
			$match = $matches[0];
			echo sprintf(
				"  -> MATCH rule=%s bucket=%s\n",
				$rule_id,
				$match->bucket
			);
		}
	}
}

echo "\n=== End debugger ===\n";
