<?php
/**
 * WordPress test-suite configuration for the Doctor Subs integration tests.
 *
 * Paths default to the plugin's own LocalWP sandbox and can be overridden with
 * environment variables so the same suite can run against the WooCommerce
 * Subscriptions release-testing rig, or against CI, without edits here.
 *
 * @package Dr_Subs
 */

// Where WordPress core lives. The suite needs a real core checkout, not the
// plugin directory.
define( 'ABSPATH', getenv( 'DR_SUBS_WP_ROOT' ) ?: '/Users/david/Local Sites/doctor-subs/app/public/' );

// The scratch database. THIS DATABASE IS WIPED ON EVERY RUN - never point it
// at a site you care about.
define( 'DB_NAME', getenv( 'DR_SUBS_TEST_DB' ) ?: 'doctor_subs_tests' );
define( 'DB_USER', getenv( 'DR_SUBS_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'DR_SUBS_TEST_DB_PASS' ) ?: 'root' );
// LocalWP gives every site its own mysqld socket, so the host has to name it
// explicitly. WordPress accepts the "localhost:/path/to/socket" form.
define(
	'DB_HOST',
	getenv( 'DR_SUBS_TEST_DB_HOST' )
		?: 'localhost:/Users/david/Library/Application Support/Local/run/VDMuglzhp/mysql/mysqld.sock'
);
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableNotSnakeCase -- required name.

define( 'WP_TESTS_DOMAIN', 'doctor-subs.test' );
define( 'WP_TESTS_EMAIL', 'admin@doctor-subs.test' );
define( 'WP_TESTS_TITLE', 'Doctor Subs Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );

// WooCommerce reads these during install.
define( 'WP_TESTS_MULTISITE', false );
