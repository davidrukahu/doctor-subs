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
	const SCHEMA_VERSION = '2.3.0';

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
		self::backfill_primary_rule();
		self::migrate_settings();
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Boot-time schema check. Runs dbDelta if the installed version is
	 * older than what this file ships, so an in-place plugin update
	 * picks up new tables without requiring a deactivate/reactivate.
	 *
	 * Why: dbDelta is idempotent + cheap; running it once per upgrade
	 * is much less footgun than telling merchants to toggle the plugin
	 * off and on after a version bump.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		if ( '' === $installed || version_compare( $installed, self::SCHEMA_VERSION, '<' ) ) {
			self::create_tables();
			self::backfill_primary_rule();
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
		}
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

		$health_table      = self::sub_health_table();
		$journal_table     = self::fix_journal_table();
		$transitions_table = self::status_transitions_table();

		$health_sql = "CREATE TABLE {$health_table} (
			sub_id BIGINT UNSIGNED NOT NULL,
			bucket VARCHAR(20) NOT NULL DEFAULT 'healthy',
			matched_rules LONGTEXT NULL,
			primary_rule VARCHAR(64) NOT NULL DEFAULT '',
			narration LONGTEXT NULL,
			last_scanned_at DATETIME NOT NULL,
			suppressed_until DATETIME NULL,
			PRIMARY KEY  (sub_id),
			KEY bucket_scanned (bucket, last_scanned_at),
			KEY rule_scanned (primary_rule, last_scanned_at),
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

		$transitions_sql = "CREATE TABLE {$transitions_table} (
			transition_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sub_id BIGINT UNSIGNED NOT NULL,
			from_status VARCHAR(20) NOT NULL,
			to_status VARCHAR(20) NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			transitioned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (transition_id),
			KEY to_status_time (to_status, transitioned_at),
			KEY product_to_time (product_id, to_status, transitioned_at),
			KEY sub_time (sub_id, transitioned_at)
		) {$charset_collate};";

		dbDelta( $health_sql );
		dbDelta( $journal_sql );
		dbDelta( $transitions_sql );
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
	/**
	 * Fill in primary_rule for rows written before the column existed.
	 *
	 * The dashboard used to read the first matched rule out of the JSON in
	 * PHP, which meant the rule filter could only be applied after the SQL
	 * LIMIT had already thrown rows away. The column makes the filter and the
	 * count exact. A scan would repopulate it anyway, but backfilling here
	 * means the dashboard is right immediately after the upgrade rather than
	 * after the next nightly run.
	 *
	 * Uses string functions rather than JSON_EXTRACT so it works the same on
	 * older MySQL and on MariaDB.
	 *
	 * @return void
	 */
	private static function backfill_primary_rule(): void {
		global $wpdb;

		$table = self::sub_health_table();

		// Take the text after the FIRST '"rule_id":"' and stop at the closing
		// quote. LOCATE finds that first occurrence; SUBSTRING_INDEX with a
		// count of 2 would return the whole string whenever there is only one
		// match, which is the common case of a single matched rule.
		$needle = '"rule_id":"';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema backfill.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
					SET primary_rule = SUBSTRING_INDEX(
						SUBSTRING( matched_rules, LOCATE( %s, matched_rules ) + CHAR_LENGTH( %s ) ),
						'\"',
						1
					)
					WHERE LOCATE( %s, matched_rules ) > 0",
				$table,
				$needle,
				$needle,
				$needle
			)
		);
		// phpcs:enable
	}

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
			// Off by default. The plugin must not send mail the merchant did
			// not ask for; the Alerts blurb on the settings page says "Off by
			// default" and this is what makes that true.
			'alerts_enabled'         => false,
			'alert_email'            => get_option( 'admin_email', '' ),
			'journal_retention_days' => 180,
			'telemetry_enabled'      => false,
			// All detection rules enabled by default. Settings page lets
			// merchants disable individual rules; missing entries fall
			// back to true via DR_Subs_Rule_Catalog::enabled_map().
			'rules'                  => array(
				'manual_renewal_drift' => true,
				'ghost_sub'            => true,
				'mass_hold'            => true,
				'onhold_paid'          => true,
				'repeated_failures'    => true,
				'total_drift'          => true,
			),
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
	 * Fully-qualified status_transitions table name.
	 *
	 * Stores observed sub status changes so the Mass Hold cascade rule can
	 * detect spikes (>=N transitions to on-hold within a short window
	 * sharing the same product). Append-only; pruned on a TTL.
	 *
	 * @since 2.1.0
	 * @return string
	 */
	public static function status_transitions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dr_subs_status_transitions';
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
		$health      = self::sub_health_table();
		$journal     = self::fix_journal_table();
		$transitions = self::status_transitions_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional schema change on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $health ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $journal ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $transitions ) );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );
		delete_option( self::SETTINGS_OPTION );
	}
}
