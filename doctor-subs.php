<?php
/**
 * Doctor Subs - WooCommerce Subscriptions diagnostic + one-click fix plugin.
 *
 * @package Dr_Subs
 */

declare( strict_types=1 );

/**
 * Plugin Name: Doctor Subs
 * Plugin URI: https://github.com/davidrukahu/doctor-subs
 * Description: Find and fix broken WooCommerce subscriptions. Detects ghost subs, stuck-on-hold renewals, and repeated payment failures, with one-click reversible fixes.
 * Version: 2.1.0
 * Author: DavidR
 * Author URI: https://github.com/davidrukahu
 * Text Domain: doctor-subs
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 9.0
 * WC tested up to: 9.9.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Dr_Subs
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'DR_SUBS_PLUGIN_FILE', __FILE__ );
define( 'DR_SUBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DR_SUBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DR_SUBS_PLUGIN_VERSION', '2.1.0' );
define( 'DR_SUBS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check plugin dependencies.
 *
 * @since 1.0.0
 * @return array List of error messages if dependencies are not met.
 */
function dr_subs_check_dependencies() {
	$errors = array();

	// Check if WooCommerce is active.
	$woocommerce_active = false;

	// Check regular plugin activation.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
	if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
		$woocommerce_active = true;
	}

	// Check network activation for multisite.
	if ( ! $woocommerce_active && is_multisite() ) {
		$network_plugins = get_site_option( 'active_sitewide_plugins' );
		if ( isset( $network_plugins['woocommerce/woocommerce.php'] ) ) {
			$woocommerce_active = true;
		}
	}

	// Check if WooCommerce class exists.
	if ( ! $woocommerce_active && class_exists( 'WooCommerce' ) ) {
		$woocommerce_active = true;
	}

	if ( ! $woocommerce_active ) {
		$errors[] = __( 'Doctor Subs requires WooCommerce to be installed and activated.', 'doctor-subs' );
	}

	// Check if WooCommerce Subscriptions is active using multiple methods.
	$subscriptions_active = false;

	// Method 1: Check if the plugin file is active.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
	if ( in_array( 'woocommerce-subscriptions/woocommerce-subscriptions.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
		$subscriptions_active = true;
	}

	// Method 1b: Check network activation for multisite.
	if ( ! $subscriptions_active && is_multisite() ) {
		$network_plugins = get_site_option( 'active_sitewide_plugins' );
		if ( isset( $network_plugins['woocommerce-subscriptions/woocommerce-subscriptions.php'] ) ) {
			$subscriptions_active = true;
		}
	}

	// Method 2: Check if the main plugin class exists.
	if ( ! $subscriptions_active && class_exists( 'WC_Subscriptions_Plugin' ) ) {
		$subscriptions_active = true;
	}

	// Method 3: Check if core function exists.
	if ( ! $subscriptions_active && function_exists( 'wcs_get_subscription' ) ) {
		$subscriptions_active = true;
	}

	// Method 4: Check if WC_Subscription class exists.
	if ( ! $subscriptions_active && class_exists( 'WC_Subscription' ) ) {
		$subscriptions_active = true;
	}

	if ( ! $subscriptions_active ) {
		$errors[] = __( 'Doctor Subs requires WooCommerce Subscriptions to be installed and activated.', 'doctor-subs' );
	}

	return $errors;
}

/**
 * Display admin notices for dependency issues.
 *
 * @since 1.0.0
 */
function dr_subs_admin_notices() {
	$errors = dr_subs_check_dependencies();
	if ( ! empty( $errors ) ) {
		foreach ( $errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}
}
add_action( 'admin_notices', 'dr_subs_admin_notices' );

// Only proceed if dependencies are met.
if ( ! empty( dr_subs_check_dependencies() ) ) {
	return;
}

/**
 * Plugin autoloader.
 *
 * Accepts both DR_Subs_ (v2) and WCST_ (legacy v1) prefixes. Both map to the
 * same file; the file defines DR_Subs_* and adds a class_alias for WCST_*.
 *
 * @since 2.0.0
 * @param string $class_name Class name to load.
 */
function dr_subs_autoloader( $class_name ) {
	$is_dr_subs = 0 === strpos( $class_name, 'DR_Subs_' );
	$is_wcst    = 0 === strpos( $class_name, 'WCST_' );

	if ( ! $is_dr_subs && ! $is_wcst ) {
		return;
	}

	// Normalize to a short file name (strip prefix, lowercase, hyphenate).
	$short = str_replace( '_', '-', strtolower( $class_name ) );
	$short = preg_replace( '/^(dr-subs-|wcst-)/', '', $short );

	// Define class file mappings.
	$class_directories = array(
		// v1 legacy classes (kept during v2 transition for back-compat shims).
		'plugin'                    => 'includes/',
		'admin'                     => 'includes/',
		'ajax-handler'              => 'includes/',
		'subscription-anatomy'      => 'includes/analyzers/',
		'expected-behavior'         => 'includes/analyzers/',
		'timeline-builder'          => 'includes/analyzers/',
		'discrepancy-detector'      => 'includes/analyzers/',
		'skipped-cycle-detector'    => 'includes/analyzers/',
		'subscription-data'         => 'includes/collectors/',
		'logger'                    => 'includes/utilities/',
		'security'                  => 'includes/utilities/',
		// v2 new classes (populated as tasks ship).
		'migration'                 => 'includes/migration/',
		'rule-interface'            => 'includes/rules/',
		'rules-registry'            => 'includes/rules/',
		'rule-match'                => 'includes/rules/',
		'rule-ghost-sub'            => 'includes/rules/',
		'rule-on-hold-paid'         => 'includes/rules/',
		'rule-repeated-failures'    => 'includes/rules/',
		'rule-mass-hold'            => 'includes/rules/',
		'rule-total-drift'          => 'includes/rules/',
		'rule-manual-renewal-drift' => 'includes/rules/',
		'rule-catalog'              => 'includes/rules/',
		'scan-context'              => 'includes/scanner/',
		'health-scanner'            => 'includes/scanner/',
		'fix-journal'               => 'includes/journal/',
		'fix-journal-entry'         => 'includes/journal/',
		'narrator'                  => 'includes/narrator/',
		'alert-dispatcher'          => 'includes/alerts/',
		'telemetry'                 => 'includes/telemetry/',
		'status-transition-log'     => 'includes/observers/',
	);

	// Explicit ambiguous-short-name overrides that can't be inferred from
	// the short name alone (not needed yet, placeholder for future files).

	$directory = isset( $class_directories[ $short ] ) ? $class_directories[ $short ] : 'includes/';
	$file_path = DR_SUBS_PLUGIN_DIR . $directory . 'class-' . $short . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

// Register autoloader exactly once.
spl_autoload_register( 'dr_subs_autoloader' );

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 */
function dr_subs_init_plugin() {
	// Double-check dependencies before initializing.
	if ( empty( dr_subs_check_dependencies() ) ) {
		new DR_Subs_Plugin();
	}
}
add_action( 'plugins_loaded', 'dr_subs_init_plugin', 20 );

/**
 * Declare HPOS compatibility.
 *
 * @since 1.0.0
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Plugin activation hook.
 *
 * @since 1.0.0
 */
function dr_subs_activate_plugin() {
	// Run schema migration before the plugin class boots.
	if ( class_exists( 'DR_Subs_Migration' ) ) {
		DR_Subs_Migration::activate();
	}

	DR_Subs_Plugin::activate();
}
register_activation_hook( __FILE__, 'dr_subs_activate_plugin' );

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
function dr_subs_deactivate_plugin() {
	DR_Subs_Plugin::deactivate();
}
register_deactivation_hook( __FILE__, 'dr_subs_deactivate_plugin' );
