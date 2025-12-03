<?php
/**
 * Doctor Subs - WooCommerce Subscription Troubleshooter
 *
 * @package Dr_Subs
 */

declare( strict_types=1 );

/**
 * Plugin Name: Doctor Subs
 * Plugin URI: https://github.com/davidrukahu/doctor-subs
 * Description: An intuitive WooCommerce Subscriptions troubleshooting tool that implements a simple 3-step diagnostic process.
 * Version: 1.2.4
 * Author: DavidR
 * Author URI: https://github.com/davidrukahu
 * Text Domain: doctor-subs
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 9.8.5
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
define( 'WCST_PLUGIN_FILE', __FILE__ );
define( 'WCST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCST_PLUGIN_VERSION', '1.2.4' );
define( 'WCST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check plugin dependencies.
 *
 * @since 1.0.0
 * @return array List of error messages if dependencies are not met.
 */
function wcst_check_dependencies() {
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
function wcst_admin_notices() {
	$errors = wcst_check_dependencies();
	if ( ! empty( $errors ) ) {
		foreach ( $errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}
}
add_action( 'admin_notices', 'wcst_admin_notices' );

// Only proceed if dependencies are met.
if ( ! empty( wcst_check_dependencies() ) ) {
	return;
}

// Register autoloader.
spl_autoload_register( 'wcst_autoloader' );

/**
 * Plugin autoloader.
 *
 * @since 1.0.0
 * @param string $class_name Class name to load.
 */
function wcst_autoloader( $class_name ) {
	// Only handle our plugin classes.
	if ( 0 !== strpos( $class_name, 'WCST_' ) ) {
		return;
	}

	// Convert class name to file path.
	$class_file = str_replace( '_', '-', strtolower( $class_name ) );
	$class_file = str_replace( 'wcst-', '', $class_file );

	// Define class file mappings.
	$class_directories = array(
		'plugin'                 => 'includes/',
		'admin'                  => 'includes/',
		'ajax-handler'           => 'includes/',
		'subscription-anatomy'   => 'includes/analyzers/',
		'expected-behavior'      => 'includes/analyzers/',
		'timeline-builder'       => 'includes/analyzers/',
		'discrepancy-detector'   => 'includes/analyzers/',
		'skipped-cycle-detector' => 'includes/analyzers/',
		'subscription-data'      => 'includes/collectors/',
		'logger'                 => 'includes/utilities/',
		'security'               => 'includes/utilities/',
	);

	$directory = isset( $class_directories[ $class_file ] ) ? $class_directories[ $class_file ] : 'includes/';
	$file_path = WCST_PLUGIN_DIR . $directory . 'class-' . $class_file . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

// Register autoloader after function is defined.
spl_autoload_register( 'wcst_autoloader' );

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 */
function wcst_init_plugin() {
	// Double-check dependencies before initializing.
	if ( empty( wcst_check_dependencies() ) ) {
		new WCST_Plugin();
	}
}
add_action( 'plugins_loaded', 'wcst_init_plugin', 20 );

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
function wcst_activate_plugin() {
	WCST_Plugin::activate();
}
register_activation_hook( __FILE__, 'wcst_activate_plugin' );

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
function wcst_deactivate_plugin() {
	WCST_Plugin::deactivate();
}
register_deactivation_hook( __FILE__, 'wcst_deactivate_plugin' );
