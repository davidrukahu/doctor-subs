<?php
/**
 * Doctor Subs schema + option migration.
 *
 * Creates `dr_subs_sub_health` and `dr_subs_fix_journal` custom tables via
 * dbDelta on activation. Migrates v1 `wcst_settings` to v2 `dr_subs_settings`.
 * Stores a schema version option so future migrations can be incremental.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema + option migration.
 *
 * @since 2.0.0
 */
class DR_Subs_Migration {

	/**
	 * Schema version currently shipped by this file.
	 *
	 * Bump when adding new tables, adding columns, or changing indexes. The
	 * activation hook re-runs dbDelta every time; only option backfills are
	 * guarded by the version comparison.
	 */
	const SCHEMA_VERSION = '2.0.0';

	/**
	 * Option name storing the currently installed schema version.
	 */
	const VERSION_OPTION = 'dr_subs_schema_version';

	/**
	 * Option name storing plugin settings (reads + writes the v2 shape).
	 */
	const SETTINGS_OPTION = 'dr_subs_settings';

	/**
	 * Legacy v1 option name. Read on first activation, then ignored.
	 */
	const LEGACY_SETTINGS_OPTION = 'wcst_settings';

	/**
	 * Entrypoint fired on plugin activation. Idempotent.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::migrate_settings();
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Create / upgrade custom tables using dbDelta.
	 *
	 * dbDelta is idempotent: it diffs the schema against the live tables and
	 * only issues needed ALTER/CREATE statements. Safe to run on every boot
	 * or activation.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$health_table  = self::sub_health_table();
		$journal_table = self::fix_journal_table();

		$health_sql = "CREATE TABLE {$health_table} (
			sub_id BIGINT UNSIGNED NOT NULL,
			bucket VARCHAR(20) NOT NULL DEFAULT 'healthy',
			matched_rules LONGTEXT NULL,
			narration LONGTEXT NULL,
			last_scanned_at DATETIME NOT NULL,
			suppressed_until DATETIME NULL,
			PRIMARY KEY  (sub_id),
			KEY bucket_scanned (bucket, last_scanned_at),
			KEY last_scanned (last_scanned_at)
		) {$charset_collate};";

		$journal_sql = "CREATE TABLE {$journal_table} (
			entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(40) NULL,
			sub_id BIGINT UNSIGNED NOT NULL,
			rule_id VARCHAR(60) NOT NULL,
			before_state LONGTEXT NOT NULL,
			before_state_hash CHAR(64) NOT NULL,
			after_state LONGTEXT NOT NULL,
			side_effects LONGTEXT NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'applied',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reverted_at DATETIME NULL,
			PRIMARY KEY  (entry_id),
			KEY sub_created (sub_id, created_at),
			KEY batch (batch_id),
			KEY status_idx (status)
		) {$charset_collate};";

		dbDelta( $health_sql );
		dbDelta( $journal_sql );
	}

	/**
	 * Migrate legacy `wcst_settings` to `dr_subs_settings` on first activation.
	 *
	 * The v1 option had 3 fields (enable_logging, log_retention_days,
	 * show_advanced_data). None map 1:1 to the v2 shape, so v2 writes a fresh
	 * defaults object. The legacy option is left in place for rollback safety;
	 * it is never read again by v2 code.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function migrate_settings(): void {
		// If v2 settings already exist, leave them alone (idempotent on re-activation).
		if ( false !== get_option( self::SETTINGS_OPTION, false ) ) {
			return;
		}

		$defaults = self::default_settings();

		// Attempt to carry over legacy retention if present.
		$legacy = get_option( self::LEGACY_SETTINGS_OPTION, array() );
		if ( is_array( $legacy ) && isset( $legacy['log_retention_days'] ) && (int) $legacy['log_retention_days'] > 0 ) {
			$defaults['journal_retention_days'] = (int) $legacy['log_retention_days'];
		}

		add_option( self::SETTINGS_OPTION, $defaults );
	}

	/**
	 * Default v2 settings.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'alerts_enabled'         => true,
			'alert_email'            => get_option( 'admin_email', '' ),
			'journal_retention_days' => 180,
			'telemetry_enabled'      => false,
		);
	}

	/**
	 * Fully-qualified sub_health table name.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public static function sub_health_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dr_subs_sub_health';
	}

	/**
	 * Fully-qualified fix_journal table name.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public static function fix_journal_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dr_subs_fix_journal';
	}

	/**
	 * Drop custom tables. Called from uninstall.php only when the merchant
	 * sets `DR_SUBS_UNINSTALL_PURGE` in wp-config.php. Normal uninstall keeps
	 * the tables so a reinstall can still revert historical fixes.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;
		$health  = self::sub_health_table();
		$journal = self::fix_journal_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional schema change on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $health ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $journal ) );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );
		delete_option( self::SETTINGS_OPTION );
	}
}
