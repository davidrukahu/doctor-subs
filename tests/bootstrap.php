<?php
/**
 * PHPUnit bootstrap for the Doctor Subs integration tests.
 *
 * These are integration tests on purpose. Every rule in this plugin reads a
 * live WC_Subscription and the Action Scheduler store, so a mocked suite would
 * assert the behaviour of the mocks rather than the behaviour of the plugin.
 * The bootstrap therefore loads real WordPress, real WooCommerce and real
 * WooCommerce Subscriptions, and runs each test inside a transaction that is
 * rolled back afterwards.
 *
 * WooCommerce Subscriptions is a paid plugin and cannot be installed in public
 * CI, so the suite skips itself with a clear message when it is absent rather
 * than failing.
 *
 * @package Dr_Subs
 */

define( 'DR_SUBS_TESTS_DIR', __DIR__ );
define( 'DR_SUBS_TESTS_ROOT', dirname( __DIR__ ) );

// Where to find WooCommerce and WooCommerce Subscriptions. Defaults to the
// plugin's own sandbox; point DR_SUBS_PLUGINS_DIR at another site to run the
// same suite against a different WCS version.
$dr_subs_plugins_dir = getenv( 'DR_SUBS_PLUGINS_DIR' )
	?: '/Users/david/Local Sites/doctor-subs/app/public/wp-content/plugins';

$dr_subs_wp_tests_dir = getenv( 'WP_TESTS_DIR' )
	?: DR_SUBS_TESTS_ROOT . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $dr_subs_wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library at {$dr_subs_wp_tests_dir}.\n" );
	exit( 1 );
}

// The test library reads its config from this constant.
define( 'WP_TESTS_CONFIG_FILE_PATH', DR_SUBS_TESTS_DIR . '/wp-tests-config.php' );

require_once DR_SUBS_TESTS_ROOT . '/vendor/autoload.php';
require_once $dr_subs_wp_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce, WooCommerce Subscriptions and Doctor Subs before WordPress
 * finishes booting, in that order, so dependencies resolve the way they do on
 * a real site.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $dr_subs_plugins_dir ) {
		$plugins = array(
			$dr_subs_plugins_dir . '/woocommerce/woocommerce.php',
			$dr_subs_plugins_dir . '/woocommerce-subscriptions/woocommerce-subscriptions.php',
			DR_SUBS_TESTS_ROOT . '/doctor-subs.php',
		);

		foreach ( $plugins as $plugin ) {
			if ( ! file_exists( $plugin ) ) {
				fwrite( STDERR, "\nMissing dependency: {$plugin}\n" );
				fwrite( STDERR, "Set DR_SUBS_PLUGINS_DIR to a WordPress plugins directory that has WooCommerce and WooCommerce Subscriptions.\n\n" );
				exit( 1 );
			}
			require_once $plugin;
		}
	}
);

/**
 * Install WooCommerce's and Subscriptions' tables once the test database has
 * been created. WordPress's installer only creates core tables.
 */
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			// Suppress the version-check redirects and notices the installer
			// would otherwise queue up.
			remove_all_actions( 'admin_notices' );
			WC_Install::install();
		}

		// Action Scheduler ships inside WooCommerce and creates its tables on
		// this hook in normal operation.
		if ( class_exists( 'ActionScheduler_DataController' ) ) {
			ActionScheduler_DataController::init();
		}
		if ( class_exists( 'ActionScheduler' ) ) {
			$store = ActionScheduler::store();
			if ( method_exists( $store, 'init' ) ) {
				$store->init();
			}
		}
	}
);

require $dr_subs_wp_tests_dir . '/includes/bootstrap.php';

// Doctor Subs creates its own tables on activation, which the test bootstrap
// never fires. Create them here so every test starts against real schema.
if ( class_exists( 'DR_Subs_Migration' ) ) {
	DR_Subs_Migration::create_tables();
}

require_once DR_SUBS_TESTS_DIR . '/class-dr-subs-test-case.php';
