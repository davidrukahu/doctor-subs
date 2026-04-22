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

// Also clean up any transients or legacy v1 options we care to remove.
delete_option( 'wcst_settings' );
