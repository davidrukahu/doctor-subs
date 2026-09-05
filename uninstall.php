<?php
/**
 * Doctor Subs uninstall routine.
 *
 * Default uninstall is conservative: custom tables and fix history stay on
 * disk so a future reinstall can still revert historical fixes. Settings
 * option is also preserved.
 *
 * To fully purge everything (tables, settings, schema version) on uninstall,
 * define DR_SUBS_UNINSTALL_PURGE as true in wp-config.php. Irreversible.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'DR_SUBS_UNINSTALL_PURGE' ) || true !== DR_SUBS_UNINSTALL_PURGE ) {
	return;
}

// Lazy-load the migration class; autoloader isn't available during uninstall.
require_once __DIR__ . '/includes/migration/class-migration.php';

DR_Subs_Migration::drop_tables();

// Every option the plugin writes, plus the legacy v1 option.
delete_option( 'dr_subs_settings' );
delete_option( 'dr_subs_settings_last_saved' );
delete_option( 'dr_subs_schema_version' );
delete_option( 'dr_subs_last_scan_ts' );
delete_option( 'dr_subs_install_hash' );
delete_option( 'wcst_settings' );

// Scan lock, in case uninstall happens while one is in flight.
delete_transient( 'dr_subs_scan_lock' );

// Recurring jobs, so nothing is left pointing at code that no longer exists.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'dr_subs_daily_health_scan', array(), 'doctor-subs' );
	as_unschedule_all_actions( 'dr_subs_journal_cleanup', array(), 'doctor-subs' );
	as_unschedule_all_actions( 'dr_subs_status_transitions_prune', array(), 'doctor-subs' );
}
$dr_subs_watchdog = wp_next_scheduled( 'dr_subs_cron_watchdog' );
if ( $dr_subs_watchdog ) {
	wp_unschedule_event( $dr_subs_watchdog, 'dr_subs_cron_watchdog' );
}
