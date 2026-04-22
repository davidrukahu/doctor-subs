<?php
/**
 * AJAX Request Handler
 *
 * Registers and handles the six AJAX endpoints admin.js expects. Each
 * endpoint verifies the `dr_subs_admin` nonce and `manage_woocommerce`
 * capability before doing any work.
 *
 * Endpoints:
 *  - dr_subs_get_fix_preview  -> HTML fragment for the modal
 *  - dr_subs_apply_fix        -> JSON result
 *  - dr_subs_revert_fix       -> JSON result
 *  - dr_subs_run_scan         -> JSON summary
 *  - dr_subs_cancel_scan      -> JSON ack
 *  - dr_subs_save_settings    -> JSON result
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler class.
 *
 * @since 1.0.0
 */
class DR_Subs_Ajax_Handler {

	/**
	 * Nonce action used by admin.js for all AJAX calls.
	 */
	const NONCE_ACTION = 'dr_subs_admin';

	/**
	 * Required capability for every endpoint.
	 */
	const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Constructor. Registers every AJAX action.
	 */
	public function __construct() {
		$actions = array(
			'dr_subs_get_fix_preview' => 'get_fix_preview',
			'dr_subs_apply_fix'       => 'apply_fix',
			'dr_subs_revert_fix'      => 'revert_fix',
			'dr_subs_run_scan'        => 'run_scan',
			'dr_subs_cancel_scan'     => 'cancel_scan',
			'dr_subs_save_settings'   => 'save_settings',
		);
		foreach ( $actions as $action => $method ) {
			add_action( "wp_ajax_{$action}", array( $this, $method ) );
		}
	}

	/**
	 * Return the modal HTML fragment for a given sub_id.
	 *
	 * Re-runs detection for the sub so the preview always reflects
	 * current state (not whatever was in the most recent scan).
	 *
	 * @return void  Sends output + exits.
	 */
	public function get_fix_preview(): void {
		$this->guard();
		$sub_id = $this->post_int( 'sub_id' );
		if ( $sub_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'doctor-subs' ) ), 400 );
		}

		$rule_id = $this->post_string( 'rule_id' );
		$rule    = $this->resolve_rule( $sub_id, $rule_id );
		if ( ! $rule ) {
			wp_send_json_error( array( 'message' => __( 'No matching rule found for this subscription.', 'doctor-subs' ) ), 404 );
		}

		$context = new DR_Subs_Scan_Context();
		$matches = $rule->detect_batch( array( $sub_id ), $context );
		$match   = $matches[0] ?? null;
		if ( ! $match ) {
			wp_send_json_error(
				array( 'message' => __( 'This subscription no longer matches the rule (state changed since scan). Re-scan and try again.', 'doctor-subs' ) ),
				409
			);
		}
		$match->narration = DR_Subs_Narrator::for_match( $rule, $match );

		$preview = $rule->preview_fix( $match );

		$sub           = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
		$customer_name = $sub ? $sub->get_formatted_billing_full_name() : '';

		$vars = array(
			'sub_id'           => $sub_id,
			'customer_name'    => $customer_name,
			'rule_id'          => $rule->id(),
			'narrative'        => (string) ( $preview['narrative'] ?? '' ),
			'diff'             => (array) ( $preview['diff'] ?? array() ),
			'already_executed' => ! empty( $preview['already_executed'] ),
		);

		$this->render_view( 'modal-fix-preview.php', $vars );
		wp_die();
	}

	/**
	 * Apply a fix to a subscription. Returns JSON.
	 *
	 * @return void
	 */
	public function apply_fix(): void {
		$this->guard();
		$sub_id = $this->post_int( 'sub_id' );
		if ( $sub_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'doctor-subs' ) ), 400 );
		}

		$rule_id = $this->post_string( 'rule_id' );
		$rule    = $this->resolve_rule( $sub_id, $rule_id );
		if ( ! $rule ) {
			wp_send_json_error( array( 'message' => __( 'No matching rule for this subscription.', 'doctor-subs' ) ), 404 );
		}

		$context = new DR_Subs_Scan_Context();
		$matches = $rule->detect_batch( array( $sub_id ), $context );
		$match   = $matches[0] ?? null;
		if ( ! $match ) {
			wp_send_json_error(
				array( 'message' => __( 'State changed since scan. Re-scan and try again.', 'doctor-subs' ) ),
				409
			);
		}

		/**
		 * Fires just before a fix is applied via AJAX.
		 *
		 * @since 2.0.0
		 * @param WC_Subscription|null $sub
		 * @param string               $rule_id
		 */
		do_action(
			'dr_subs_before_fix_apply',
			function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null,
			$rule->id()
		);

		try {
			$payload  = $rule->apply_fix( $match );
			$entry_id = DR_Subs_Fix_Journal::record( $sub_id, $rule->id(), $payload );
		} catch ( \Throwable $t ) {
			DR_Subs_Logger::error(
				'apply_fix failed',
				array(
					'sub'   => $sub_id,
					'rule'  => $rule->id(),
					'error' => $t->getMessage(),
				)
			);
			wp_send_json_error( array( 'message' => $t->getMessage() ), 500 );
		}

		wp_send_json_success(
			array(
				'entry_id' => $entry_id,
				'message'  => __( 'Fix applied.', 'doctor-subs' ),
			)
		);
	}

	/**
	 * Revert a previously-applied fix. Returns JSON.
	 *
	 * @return void
	 */
	public function revert_fix(): void {
		$this->guard();
		$entry_id = $this->post_int( 'entry_id' );
		if ( $entry_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid journal entry.', 'doctor-subs' ) ), 400 );
		}

		$result = DR_Subs_Fix_Journal::revert( $entry_id );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result, 500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Kick off a one-shot scan. Returns the summary.
	 *
	 * @return void
	 */
	public function run_scan(): void {
		$this->guard();
		$scanner = new DR_Subs_Health_Scanner();
		$summary = $scanner->run();
		wp_send_json_success( $summary );
	}

	/**
	 * Cancel an in-progress scan (best effort - clears the lock
	 * transient so the next run can start; does not interrupt a
	 * currently-executing batch).
	 *
	 * @return void
	 */
	public function cancel_scan(): void {
		$this->guard();
		delete_transient( DR_Subs_Health_Scanner::SCAN_LOCK_TRANSIENT );
		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/**
	 * Save the settings form.
	 *
	 * Accepts POST from admin.js's form-submit interception. Returns
	 * JSON so admin.js can show the Saving… / Saved flash.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		$this->guard();

		$defaults = DR_Subs_Migration::default_settings();
		$settings = array(
			'alerts_enabled'         => ! empty( $_POST['alerts_enabled'] ),
			'alert_email'            => sanitize_email(
				(string) wp_unslash( $_POST['alert_email'] ?? $defaults['alert_email'] )
			),
			'journal_retention_days' => (int) ( $_POST['journal_retention_days'] ?? $defaults['journal_retention_days'] ),
			'telemetry_enabled'      => ! empty( $_POST['telemetry_enabled'] ),
		);

		if ( ! empty( $settings['alert_email'] ) && ! is_email( $settings['alert_email'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter a valid email address.', 'doctor-subs' ) ),
				400
			);
		}

		update_option( 'dr_subs_settings', $settings );
		update_option( 'dr_subs_settings_last_saved', current_time( 'mysql' ) );

		wp_send_json_success( array( 'settings' => $settings ) );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Shared guard: verify nonce + capability. Terminates on failure.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'doctor-subs' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
	}

	/**
	 * Resolve a rule from the POSTed rule_id, or - when rule_id is absent -
	 * by looking up the sub's current primary match in dr_subs_sub_health.
	 *
	 * @param int    $sub_id
	 * @param string $rule_id  Optional explicit rule id.
	 * @return DR_Subs_Rule_Interface|null
	 */
	private function resolve_rule( int $sub_id, string $rule_id = '' ): ?DR_Subs_Rule_Interface {
		DR_Subs_Rules_Registry::bootstrap();

		if ( '' !== $rule_id ) {
			return DR_Subs_Rules_Registry::get( $rule_id );
		}

		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ad-hoc lookup.
		$matched_rules = $wpdb->get_var(
			$wpdb->prepare( "SELECT matched_rules FROM {$table} WHERE sub_id = %d", $sub_id )
		);
		// phpcs:enable

		if ( empty( $matched_rules ) ) {
			return null;
		}

		$rules = json_decode( (string) $matched_rules, true );
		if ( ! is_array( $rules ) || empty( $rules[0]['rule_id'] ) ) {
			return null;
		}

		return DR_Subs_Rules_Registry::get( (string) $rules[0]['rule_id'] );
	}

	/**
	 * Sanitised int from POST.
	 *
	 * @param string $key
	 * @return int
	 */
	private function post_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() validated nonce first.
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
	}

	/**
	 * Sanitised string from POST.
	 *
	 * @param string $key
	 * @return string
	 */
	private function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() validated nonce first.
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Render a view template with an array of scoped variables.
	 *
	 * @param string $view Filename under admin/views/.
	 * @param array  $vars Variables to extract.
	 * @return void
	 */
	private function render_view( string $view, array $vars = array() ): void {
		$path = DR_SUBS_PLUGIN_DIR . 'admin/views/' . $view;
		if ( ! file_exists( $path ) ) {
			return;
		}
		( function () use ( $path, $vars ) {
			extract( $vars, EXTR_SKIP );
			include $path;
		} )();
	}
}

/**
 * Legacy v1 alias.
 *
 * @deprecated 2.0.0 Use DR_Subs_Ajax_Handler instead.
 */
class_alias( 'DR_Subs_Ajax_Handler', 'WCST_Ajax_Handler' );
