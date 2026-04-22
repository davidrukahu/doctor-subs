<?php
/**
 * Main Plugin Class
 *
 * Wires all v2 subsystems: admin UI, AJAX handlers, scanner + journal
 * hooks, and the recurring Action Scheduler + WP-Cron schedules.
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
class DR_Subs_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var DR_Subs_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin controller.
	 *
	 * @var DR_Subs_Admin
	 */
	public $admin;

	/**
	 * AJAX handler.
	 *
	 * @var DR_Subs_Ajax_Handler
	 */
	public $ajax_handler;

	/**
	 * Logger.
	 *
	 * @var DR_Subs_Logger
	 */
	public $logger;

	/**
	 * Get plugin instance.
	 *
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
	 */
	public function __construct() {
		$this->init_hooks();
		$this->load_dependencies();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Scanner hooks (both AS primary + WP-Cron watchdog).
		add_action( DR_Subs_Health_Scanner::RECURRING_HOOK, array( 'DR_Subs_Health_Scanner', 'run_recurring' ) );
		add_action( DR_Subs_Health_Scanner::WATCHDOG_HOOK, array( 'DR_Subs_Health_Scanner', 'run_watchdog' ) );

		// Journal cleanup hook.
		add_action( DR_Subs_Fix_Journal::CLEANUP_HOOK, array( 'DR_Subs_Fix_Journal', 'run_cleanup' ) );

		// Alert dispatcher (listens on dr_subs_after_scan).
		if ( class_exists( 'DR_Subs_Alert_Dispatcher' ) ) {
			DR_Subs_Alert_Dispatcher::register();
		}
	}

	/**
	 * Instantiate subsystems.
	 */
	private function load_dependencies() {
		if ( is_admin() ) {
			$this->admin = new DR_Subs_Admin();
		}
		$this->ajax_handler = new DR_Subs_Ajax_Handler();
		$this->logger       = new DR_Subs_Logger();
	}

	/**
	 * `init` callback.
	 */
	public function init() {
		/**
		 * Fires after Doctor Subs boots on every request.
		 *
		 * @since 2.0.0
		 */
		do_action( 'dr_subs_init' );
	}

	/**
	 * `admin_init` callback.
	 */
	public function admin_init() {
		/**
		 * Fires during admin_init after Doctor Subs boots.
		 *
		 * @since 2.0.0
		 */
		do_action( 'dr_subs_admin_init' );
	}

	/**
	 * Plugin activation.
	 *
	 * Runs AFTER DR_Subs_Migration::activate() because the
	 * activation hook in doctor-subs.php calls migration first.
	 *
	 * @return void
	 */
	public static function activate() {
		// Schedule recurring scanner + watchdog + journal cleanup.
		if ( class_exists( 'DR_Subs_Health_Scanner' ) ) {
			DR_Subs_Health_Scanner::schedule_recurring();
		}
		if ( class_exists( 'DR_Subs_Fix_Journal' ) ) {
			DR_Subs_Fix_Journal::schedule_cleanup();
		}

		// Make sure permalinks pick up our page route.
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( class_exists( 'DR_Subs_Health_Scanner' ) ) {
			DR_Subs_Health_Scanner::unschedule();
		}
		if ( class_exists( 'DR_Subs_Fix_Journal' ) ) {
			DR_Subs_Fix_Journal::unschedule_cleanup();
		}

		flush_rewrite_rules();
	}

	/**
	 * Get a plugin setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default if unset.
	 * @return mixed
	 */
	public static function get_option( $key, $default = null ) {
		$options = get_option( 'dr_subs_settings', array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Update a plugin setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return bool
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
