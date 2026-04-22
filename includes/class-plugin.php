<?php
/**
 * Main Plugin Class
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class for WooCommerce Subscriptions Troubleshooter.
 *
 * @since 1.0.0
 */
class DR_Subs_Plugin {

	/**
	 * Plugin instance.
	 *
	 * @since 1.0.0
	 * @var DR_Subs_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin instance.
	 *
	 * @since 1.0.0
	 * @var DR_Subs_Admin
	 */
	public $admin;

	/**
	 * AJAX handler instance.
	 *
	 * @since 1.0.0
	 * @var DR_Subs_Ajax_Handler
	 */
	public $ajax_handler;

	/**
	 * Logger instance.
	 *
	 * @since 1.0.0
	 * @var DR_Subs_Logger
	 */
	public $logger;

	/**
	 * Get plugin instance.
	 *
	 * @since 1.0.0
	 * @return DR_Subs_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
		$this->load_dependencies();
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Add settings link to plugins page.
		add_filter( 'plugin_action_links_' . DR_SUBS_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies() {
		// Load admin class for admin interface.
		if ( is_admin() ) {
			$this->admin = new DR_Subs_Admin();
		}

		// Load AJAX handler.
		$this->ajax_handler = new DR_Subs_Ajax_Handler();

		// Load logger.
		$this->logger = new DR_Subs_Logger();
	}

	/**
	 * Initialize plugin.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Fire action for extensions.
		do_action( 'dr_subs_init' );
	}

	/**
	 * Admin initialization.
	 *
	 * @since 1.0.0
	 */
	public function admin_init() {
		// Fire action for admin-specific initialization.
		do_action( 'dr_subs_admin_init' );
	}

	/**
	 * Add settings link to plugins page.
	 *
	 * @since 1.0.0
	 * @param array $links Plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=doctor-subs' ) ) . '">' .
						esc_html__( 'Troubleshoot', 'doctor-subs' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Plugin activation.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		// Set default options.
		self::set_default_options();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Cleanup if needed.
		flush_rewrite_rules();
	}

	/**
	 * Set default plugin options.
	 *
	 * @since 1.0.0
	 */
	private static function set_default_options() {
		$default_options = array(
			'enable_logging'     => true,
			'log_retention_days' => 30,
			'show_advanced_data' => false,
		);

		add_option( 'dr_subs_settings', $default_options );
	}

	/**
	 * Get plugin option.
	 *
	 * @since 1.0.0
	 * @param string $key    Option key.
	 * @param mixed  $default Default value if option doesn't exist.
	 * @return mixed Option value or default.
	 */
	public static function get_option( $key, $default = null ) {
		$options = get_option( 'dr_subs_settings', array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Update plugin option.
	 *
	 * @since 1.0.0
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 * @return bool True if option was updated, false otherwise.
	 */
	public static function update_option( $key, $value ) {
		$options         = get_option( 'dr_subs_settings', array() );
		$options[ $key ] = $value;
		return update_option( 'dr_subs_settings', $options );
	}
}

/**
 * Legacy v1 alias. Do not use in new code; DR_Subs_Plugin is canonical.
 *
 * @deprecated 2.0.0 Use DR_Subs_Plugin instead.
 */
class_alias( 'DR_Subs_Plugin', 'WCST_Plugin' );
