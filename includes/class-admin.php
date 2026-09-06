<?php
/**
 * Admin Interface Controller
 *
 * @package Dr_Subs
 * @since   1.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface controller class.
 *
 * Routes the plugin's admin page to one of five surfaces (first-run,
 * dashboard, fix-history, settings) based on the ?tab= query string and
 * the state of the `dr_subs_sub_health` table. Loads the PHP view
 * templates under admin/views/ with the variables each expects.
 *
 * @since 1.0.0
 */
class DR_Subs_Admin {

	/**
	 * Rows per page in the Needs attention table.
	 *
	 * Was a hard LIMIT 50 with no pagination, so on any store with more than
	 * fifty broken subscriptions the rest were simply invisible.
	 */
	const ROWS_PER_PAGE = 25;


	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'doctor-subs';

	/**
	 * Hours-since-last-scan threshold above which the dashboard shows the
	 * "stale" refresh affordance.
	 */
	const STALE_HOURS = 24;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'plugin_action_links_' . DR_SUBS_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
		add_filter( 'woocommerce_subscription_list_table_column_status_content', array( $this, 'add_doctor_subs_to_status_column' ), 10, 3 );
		add_action( 'admin_post_dr_subs_save_settings', array( $this, 'handle_settings_submit' ) );
	}

	/**
	 * Register the Doctor Subs submenu under WooCommerce.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Doctor Subs', 'doctor-subs' ),
			__( 'Doctor Subs', 'doctor-subs' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin styles + script on the Doctor Subs page.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_doctor-subs' !== $hook ) {
			return;
		}

		// Design system - loaded in order: tokens -> surfaces -> responsive.
		wp_enqueue_style(
			'dr-subs-tokens',
			DR_SUBS_PLUGIN_URL . 'admin/css/tokens.css',
			array(),
			DR_SUBS_PLUGIN_VERSION
		);
		wp_enqueue_style(
			'dr-subs-admin',
			DR_SUBS_PLUGIN_URL . 'admin/css/admin.css',
			array( 'dr-subs-tokens' ),
			DR_SUBS_PLUGIN_VERSION
		);
		wp_enqueue_style(
			'dr-subs-responsive',
			DR_SUBS_PLUGIN_URL . 'admin/css/responsive.css',
			array( 'dr-subs-admin' ),
			DR_SUBS_PLUGIN_VERSION
		);

		// Vanilla JS - no jQuery dependency.
		wp_enqueue_script(
			'dr-subs-admin',
			DR_SUBS_PLUGIN_URL . 'admin/js/admin.js',
			array(),
			DR_SUBS_PLUGIN_VERSION,
			true
		);

		// Localise for AJAX. admin.js reads the `drSubsAjax` global.
		wp_localize_script(
			'dr-subs-admin',
			'drSubsAjax',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dr_subs_admin' ),
				'strings' => array(
					'showingAll'     => __( 'showing all broken and at-risk', 'doctor-subs' ),
					'filtering'      => array(
						/* translators: %d: number of broken subs filtered */
						'broken'  => __( 'filtering to %d broken', 'doctor-subs' ),
						/* translators: %d: number of at-risk subs filtered */
						'risk'    => __( 'filtering to %d at-risk', 'doctor-subs' ),
						/* translators: %d: number of healthy subs filtered */
						'healthy' => __( 'filtering to %d healthy', 'doctor-subs' ),
					),
					'modalLoadError' => __( 'Could not load the fix preview. Try again in a moment.', 'doctor-subs' ),
					'applying'       => __( 'Applying…', 'doctor-subs' ),
					'applyError'     => __( 'Something went wrong - nothing was changed.', 'doctor-subs' ),
					'reverting'      => __( 'Reverting…', 'doctor-subs' ),
					'revert'         => __( 'Revert', 'doctor-subs' ),
					'revertTitle'    => __( 'Revert this fix?', 'doctor-subs' ),
					'revertBody'     => __( 'The subscription will return to its previous state.', 'doctor-subs' ),
					'revertExecutedBody' => __( 'The renewal payment for this fix has already gone through. Reverting will undo the status change.', 'doctor-subs' ),
					'revertNoRefund' => __( 'This will NOT refund the customer. If a refund is needed, handle it in the WooCommerce order directly.', 'doctor-subs' ),
					'revertBatchTitle' => __( 'Revert this batch?', 'doctor-subs' ),
					/* translators: %d: number of subscriptions in the batch */
					'revertBatchBody'  => __( 'All %d subscriptions in this batch will return to their previous state. They are reverted newest first.', 'doctor-subs' ),
					'bulkStarting'   => __( 'Starting…', 'doctor-subs' ),
					/* translators: 1: number of subscriptions fixed so far, 2: total in the run */
					'bulkProgress'   => __( 'Fixed %1$s of %2$s', 'doctor-subs' ),
					'saving'         => __( 'Saving…', 'doctor-subs' ),
					'saved'          => __( 'Saved.', 'doctor-subs' ),
					'saveError'      => __( 'Could not save. Check your connection and try again.', 'doctor-subs' ),
					'scanning'       => __( 'Scanning…', 'doctor-subs' ),
					'refreshing'     => __( 'Refreshing…', 'doctor-subs' ),
					'scanError'      => __( 'Scan failed. Check your connection and try again.', 'doctor-subs' ),
				),
			)
		);
	}

	/**
	 * Add an "Open" action link on the plugins page.
	 *
	 * @since 1.0.0
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open', 'doctor-subs' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add a "Doctor Subs" link on each row of the WooCommerce Subscriptions
	 * list table so merchants can jump from a specific sub into the plugin.
	 *
	 * v2 note: links to the dashboard rather than an auto-analyze flow.
	 * Per-sub drill-down is reached by clicking the sub's row on the
	 * dashboard once it shows up in "Needs attention".
	 *
	 * @since 1.0.0
	 * @param string          $column_content The status column content.
	 * @param WC_Subscription $subscription   The subscription object.
	 * @param array           $actions        The existing actions array.
	 * @return string Modified column content.
	 */
	public function add_doctor_subs_to_status_column( $column_content, $subscription, $actions ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $column_content;
		}

		$doctor_subs_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$doctor_subs_link = sprintf(
			'<span class="doctor-subs"><a href="%s">%s</a></span>',
			esc_url( $doctor_subs_url ),
			esc_html__( 'Doctor Subs', 'doctor-subs' )
		);

		return str_replace( '</div>', ' | ' . $doctor_subs_link . '</div>', $column_content );
	}

	/**
	 * Main page entrypoint. Routes to the appropriate surface based on
	 * ?tab= and table state.
	 *
	 * @since 1.0.0
	 */
	public function render_admin_page() {
		$tab = $this->current_tab();

		switch ( $tab ) {
			case 'history':
				$this->render_fix_history();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_dashboard_or_firstrun();
		}
	}

	/**
	 * Read, sanitize, and validate the current tab from the URL.
	 *
	 * @return string 'dashboard' | 'history' | 'settings'
	 */
	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return in_array( $raw, array( 'history', 'settings' ), true ) ? $raw : 'dashboard';
	}

	/**
	 * Render the first-run CTA or the dashboard depending on whether a scan
	 * has ever populated the sub_health table.
	 *
	 * @return void
	 */
	private function render_dashboard_or_firstrun(): void {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only count for view routing.
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);
		// phpcs:enable

		if ( 0 === $row_count ) {
			// No scan yet. If there are no active subs at all, render zero state;
			// otherwise render the first-run CTA.
			$state = $this->has_any_active_subs() ? 'default' : 'zero';
			$this->load_view( 'first-run.php', compact( 'state' ) );
			return;
		}

		$counts    = $this->fetch_health_counts();
		$filter    = $this->current_filter();
		$page      = $this->current_page();
		$attention = $this->fetch_attention_rows( $filter, $page );

		$subs         = $attention['rows'];
		$total_rows   = $attention['total'];
		$total_pages  = $attention['pages'];
		$state        = ( 0 === $counts['broken'] && 0 === $counts['risk'] ) ? 'healthy' : 'mixed';
		$last_scanned = $this->relative_last_scanned();
		$stale        = $this->is_stale();

		$this->load_view(
			'dashboard.php',
			compact( 'state', 'counts', 'subs', 'filter', 'last_scanned', 'stale', 'page', 'total_rows', 'total_pages' )
		);
	}

	/**
	 * Render the Fix history tab.
	 *
	 * Populates from the `dr_subs_fix_journal` table once T9 ships the
	 * scanner + journal. Until then, the view's empty state renders.
	 *
	 * @return void
	 */
	private function render_fix_history(): void {
		$journal_rows = $this->fetch_journal_entries();

		$entries     = $journal_rows['entries'];
		$total_count = $journal_rows['total'];
		$rule_counts = $journal_rows['rule_counts'];
		$filter      = $this->current_filter();

		$this->load_view( 'fix-history.php', compact( 'entries', 'total_count', 'rule_counts', 'filter' ) );
	}

	/**
	 * Render the Settings tab.
	 *
	 * @return void
	 */
	private function render_settings(): void {
		$defaults = DR_Subs_Migration::default_settings();
		$raw      = get_option( 'dr_subs_settings', $defaults );
		$settings = is_array( $raw ) ? wp_parse_args( $raw, $defaults ) : $defaults;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query flag.
		$just_saved  = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
		$last_saved  = $this->relative_last_saved();
		$email_error = '';

		$this->load_view( 'settings.php', compact( 'settings', 'just_saved', 'last_saved', 'email_error' ) );
	}

	/**
	 * Fallback non-AJAX settings form submit handler.
	 *
	 * admin.js intercepts form submit for JS-enabled users and saves via
	 * `dr_subs_save_settings` AJAX action (registered in
	 * DR_Subs_Ajax_Handler, T11). This handler catches the no-JS path.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function handle_settings_submit(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'doctor-subs' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'dr_subs_settings_save', 'dr_subs_settings_nonce' );

		$settings = $this->sanitize_settings_post( $_POST );

		update_option( 'dr_subs_settings', $settings );
		update_option( 'dr_subs_settings_last_saved', current_time( 'mysql' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings&saved=1' ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Data helpers
	// ---------------------------------------------------------------------

	/**
	 * Load a view file with an array of scoped variables.
	 *
	 * @param string $view View filename under admin/views/.
	 * @param array  $vars Variables to extract into the view's scope.
	 * @return void
	 */
	private function load_view( string $view, array $vars = array() ): void {
		$path = DR_SUBS_PLUGIN_DIR . 'admin/views/' . $view;
		if ( ! file_exists( $path ) ) {
			return;
		}
		// Don't leak arbitrary keys into global scope; load within a closure.
		( function () use ( $path, $vars ) {
			extract( $vars, EXTR_SKIP );
			include $path;
		} )();
	}

	/**
	 * Canonical rule ids accepted as filter values, plus the legacy short
	 * names still present on journal rows written before 2.1.0.
	 *
	 * @return string[]
	 */
	private function rule_filter_values(): array {
		return array(
			// Canonical ids, as written by the rules registry.
			'manual_renewal_drift',
			'ghost_sub',
			'mass_hold',
			'onhold_paid',
			'repeated_failures',
			'total_drift',
			// Legacy short names, kept so old journal rows stay filterable.
			'ghost',
			'onhold',
			'repfail',
		);
	}

	/**
	 * Map a legacy short rule name to its canonical id, or return it unchanged.
	 *
	 * @param string $rule_id Rule id from a journal row or a filter value.
	 * @return string
	 */
	private function canonical_rule_id( string $rule_id ): string {
		$aliases = array(
			'ghost'   => 'ghost_sub',
			'onhold'  => 'onhold_paid',
			'repfail' => 'repeated_failures',
		);
		return $aliases[ $rule_id ] ?? $rule_id;
	}

	/**
	 * Every spelling of a rule id that may appear in stored rows.
	 *
	 * Rows written by older versions carry the legacy short names, so a filter
	 * on the canonical id has to match both or the older rows disappear.
	 *
	 * @param string $canonical Canonical rule id.
	 * @return array<int, string>
	 */
	private function rule_id_aliases( string $canonical ): array {
		$legacy = array(
			'ghost_sub'         => 'ghost',
			'onhold_paid'       => 'onhold',
			'repeated_failures' => 'repfail',
		);

		$ids = array( $canonical );
		if ( isset( $legacy[ $canonical ] ) ) {
			$ids[] = $legacy[ $canonical ];
		}

		return $ids;
	}

	/**
	 * Read, validate, and return the current bucket or rule filter from the URL.
	 *
	 * @return string 'all' | 'broken' | 'risk' | 'healthy' | a canonical rule id | a legacy short name
	 */
	private function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter switch.
		$raw   = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$valid = array_merge( array( 'all', 'broken', 'risk', 'healthy' ), $this->rule_filter_values() );
		return in_array( $raw, $valid, true ) ? $raw : 'all';
	}

	/**
	 * Current page number for the Needs attention table.
	 *
	 * @return int
	 */
	private function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$raw = isset( $_GET['ds_page'] ) ? absint( wp_unslash( $_GET['ds_page'] ) ) : 1;
		return max( 1, $raw );
	}

	/**
	 * Check whether the store has any active subscriptions at all.
	 *
	 * Used to choose between the first-run default state and the zero
	 * state.
	 *
	 * @return bool
	 */
	private function has_any_active_subs(): bool {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			return true; // Assume yes if WCS isn't loaded - first-run safer than zero.
		}
		$subs = wcs_get_subscriptions(
			array(
				'subscription_status'    => 'active',
				'subscriptions_per_page' => 1,
			)
		);
		return ! empty( $subs );
	}

	/**
	 * Fetch per-bucket counts from the sub_health table.
	 *
	 * @return array ['healthy' => int, 'risk' => int, 'broken' => int]
	 */
	private function fetch_health_counts(): array {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();

		$counts = array(
			'healthy' => 0,
			'risk'    => 0,
			'broken'  => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregation query, no caching.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT bucket, COUNT(*) AS n FROM %i GROUP BY bucket', $table )
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->bucket ] ) ) {
				$counts[ $row->bucket ] = (int) $row->n;
			}
		}

		return $counts;
	}

	/**
	 * Fetch "Needs attention" rows for the dashboard table.
	 *
	 * @param string $filter Bucket / rule filter from the URL.
	 * @return array Rows shaped for dashboard.php.
	 */
	private function fetch_attention_rows( string $filter = 'all', int $page = 1, int $per_page = self::ROWS_PER_PAGE ): array {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();

		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Build the WHERE in SQL, including the rule filter. This used to be
		// a fixed LIMIT 50 with the rule filter applied in PHP afterwards,
		// which meant a filtered view could show nothing at all while matching
		// rows sat below the cut, and every count was wrong.
		$where  = array( 'bucket IN ( %s, %s )' );
		$params = array( 'broken', 'risk' );

		if ( in_array( $filter, array( 'broken', 'risk' ), true ) ) {
			$where  = array( 'bucket = %s' );
			$params = array( $filter );
		} elseif ( in_array( $filter, $this->rule_filter_values(), true ) ) {
			$canonical = $this->canonical_rule_id( $filter );
			$aliases   = $this->rule_id_aliases( $canonical );

			$where[]    = 'primary_rule IN ( ' . implode( ', ', array_fill( 0, count( $aliases ), '%s' ) ) . ' )';
			$params     = array_merge( $params, $aliases );
		}

		// $where_sql is assembled only from the literal fragments above. Every
		// merchant-supplied value is a %s placeholder bound through prepare(),
		// and $filter itself was whitelisted by current_filter() before it got
		// here, so no caller input reaches the SQL string.
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders are generated one per bound value; %i escapes the table identifier; the interpolated fragment is literal (see above).
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$where_sql}",
				array_merge( array( $table ), $params )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sub_id, bucket, matched_rules, primary_rule, narration, last_scanned_at
					FROM %i
					WHERE {$where_sql}
					ORDER BY last_scanned_at DESC, sub_id DESC
					LIMIT %d OFFSET %d",
				array_merge( array( $table ), $params, array( $per_page, $offset ) )
			)
		);
		// phpcs:enable

		$subs    = array();
		$missing = array();

		foreach ( (array) $rows as $row ) {
			$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) $row->sub_id ) : null;
			if ( ! $sub ) {
				// The subscription is gone but its health row survived. Collect
				// them so the table does not show a short page and the counts
				// do not drift further every time one is deleted.
				$missing[] = (int) $row->sub_id;
				continue;
			}

			$primary_rule = (string) $row->primary_rule;
			if ( '' === $primary_rule ) {
				$rules        = json_decode( (string) $row->matched_rules, true );
				$primary_rule = ( is_array( $rules ) && isset( $rules[0]['rule_id'] ) ) ? (string) $rules[0]['rule_id'] : 'ghost';
			}

			$subs[] = array(
				'id'       => (int) $row->sub_id,
				'name'     => $this->customer_for_sub( (int) $row->sub_id ),
				'email'    => (string) $sub->get_billing_email(),
				'rule'     => $primary_rule,
				'reason'   => (string) $row->narration,
				'bucket'   => (string) $row->bucket,
				'amount'   => $sub->get_formatted_order_total(),
				'edit_url' => method_exists( $sub, 'get_edit_order_url' ) ? $sub->get_edit_order_url() : '',
			);
		}

		if ( ! empty( $missing ) ) {
			$this->purge_health_rows( $missing );
			$total = max( 0, $total - count( $missing ) );
		}

		return array(
			'rows'      => $subs,
			'total'     => $total,
			'page'      => $page,
			'per_page'  => $per_page,
			'pages'     => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Delete health rows whose subscription no longer exists.
	 *
	 * Health rows were never cleaned up, so a deleted or trashed subscription
	 * left a row behind forever: it inflated every counter and ate into the
	 * page limit, pushing real rows off the table.
	 *
	 * @param array<int, int> $sub_ids Subscription ids to drop.
	 * @return void
	 */
	private function purge_health_rows( array $sub_ids ): void {
		global $wpdb;

		$sub_ids = array_values( array_filter( array_map( 'absint', $sub_ids ) ) );
		if ( empty( $sub_ids ) ) {
			return;
		}

		$table        = DR_Subs_Migration::sub_health_table();
		$placeholders = implode( ', ', array_fill( 0, count( $sub_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- cleanup of orphaned rows.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE sub_id IN ( {$placeholders} )",
				array_merge( array( $table ), $sub_ids )
			)
		);
		// phpcs:enable
	}

	/**
	 * Fetch fix journal entries for the history view.
	 *
	 * @return array ['entries' => array, 'total' => int, 'rule_counts' => array]
	 */
	private function fetch_journal_entries(): array {
		global $wpdb;
		$table = DR_Subs_Migration::fix_journal_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- journal read.
		$total       = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);
		$rows        = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT 100', $table )
		);
		$rule_counts = $wpdb->get_results(
			$wpdb->prepare( 'SELECT rule_id, COUNT(*) AS n FROM %i GROUP BY rule_id', $table )
		);
		// phpcs:enable

		// Fold legacy short names into their canonical id so the chip counts
		// match the chips the template renders.
		$counts_map = array();
		foreach ( (array) $rule_counts as $rc ) {
			$key                 = $this->canonical_rule_id( (string) $rc->rule_id );
			$counts_map[ $key ]  = ( $counts_map[ $key ] ?? 0 ) + (int) $rc->n;
		}

		// A bulk fix writes one journal row per subscription, all sharing a
		// batch_id. Collapse each batch to a single entry so the history shows
		// "Fixed 42 subscriptions in one batch" once, not 42 identical rows.
		$batch_meta = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row->batch_id ) ) {
				continue;
			}
			$bid = (string) $row->batch_id;
			if ( ! isset( $batch_meta[ $bid ] ) ) {
				$batch_meta[ $bid ] = array(
					'count'        => 0,
					'sub_ids'      => array(),
					'all_reverted' => true,
					'first_row'    => $row->entry_id,
				);
			}
			++$batch_meta[ $bid ]['count'];
			$batch_meta[ $bid ]['sub_ids'][] = (int) $row->sub_id;
			if ( 'reverted' !== $row->status ) {
				$batch_meta[ $bid ]['all_reverted'] = false;
			}
		}

		$entries    = array();
		$filter     = $this->current_filter();
		$seen_batch = array();
		foreach ( (array) $rows as $row ) {
			if ( in_array( $filter, $this->rule_filter_values(), true )
				&& $this->canonical_rule_id( (string) $row->rule_id ) !== $this->canonical_rule_id( $filter )
			) {
				continue;
			}

			$is_batch = ! empty( $row->batch_id );

			// Emit a batch only on its newest row; skip the rest.
			if ( $is_batch ) {
				$bid = (string) $row->batch_id;
				if ( isset( $seen_batch[ $bid ] ) ) {
					continue;
				}
				$seen_batch[ $bid ] = true;
			}

			$is_reverted = $is_batch
				? $batch_meta[ (string) $row->batch_id ]['all_reverted']
				: 'reverted' === $row->status;

			$sub_for_url = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) $row->sub_id ) : null;

			$entry = array(
				'id'             => (string) $row->entry_id,
				'when'           => $this->relative_time( $row->created_at ),
				'customer'       => $this->customer_for_sub( (int) $row->sub_id ),
				'sub_id'         => (int) $row->sub_id,
				'sub_edit_url'   => ( $sub_for_url && method_exists( $sub_for_url, 'get_edit_order_url' ) ) ? $sub_for_url->get_edit_order_url() : '',
				'rule'           => (string) $row->rule_id,
				'summary'        => $this->journal_summary( $row ),
				'status'         => (string) $row->status,
				'past_retention' => $this->is_past_retention( $row->created_at ),
				// Flag rows whose side-effect (typically a scheduled AS
				// payment) may already have executed. The revert UI uses
				// this to escalate the confirm copy from "are you sure?"
				// to "this won't refund the customer."
				'has_executed_side_effect' => $this->journal_has_executed_side_effect( $row ),
			);

			if ( $is_batch ) {
				$meta                 = $batch_meta[ (string) $row->batch_id ];
				$entry['batch']       = true;
				$entry['batch_id']    = (string) $row->batch_id;
				$entry['batch_items'] = $meta['sub_ids'];
				$entry['batch_count'] = (int) $meta['count'];
			}

			if ( $is_reverted ) {
				$entry['reverted_when'] = $row->reverted_at ? $this->relative_time( $row->reverted_at ) : '';
			}

			$entries[] = $entry;
		}

		return array(
			'entries'     => $entries,
			'total'       => $total,
			'rule_counts' => $counts_map,
		);
	}

	/**
	 * Human-readable relative time for the "Last scanned" label.
	 *
	 * @return string
	 */
	private function relative_last_scanned(): string {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- view metadata.
		$latest = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MAX(last_scanned_at) FROM %i', $table )
		);
		// phpcs:enable

		return $latest ? $this->relative_time( $latest ) : __( 'never', 'doctor-subs' );
	}

	/**
	 * Is the most recent scan older than the stale threshold?
	 *
	 * @return bool
	 */
	private function is_stale(): bool {
		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- staleness check.
		$latest = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MAX(last_scanned_at) FROM %i', $table )
		);
		// phpcs:enable

		if ( ! $latest ) {
			return true;
		}

		$latest_ts = strtotime( (string) $latest . ' UTC' );
		return ( $latest_ts && ( time() - $latest_ts ) > ( self::STALE_HOURS * HOUR_IN_SECONDS ) );
	}

	/**
	 * Relative time string for the "Last saved X ago" in settings.
	 *
	 * @return string
	 */
	private function relative_last_saved(): string {
		$saved = (string) get_option( 'dr_subs_settings_last_saved', '' );
		return $saved ? $this->relative_time( $saved ) : '';
	}

	/**
	 * Format a timestamp as "N minutes ago" / "N hours ago" / "Mar 14".
	 *
	 * @param string $mysql_datetime MySQL DATETIME string in site timezone.
	 * @return string
	 */
	private function relative_time( string $mysql_datetime ): string {
		$ts = strtotime( $mysql_datetime . ' UTC' );
		if ( ! $ts ) {
			return '';
		}

		$diff = time() - $ts;
		if ( $diff < 60 ) {
			return __( 'just now', 'doctor-subs' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = (int) floor( $diff / MINUTE_IN_SECONDS );
			/* translators: %d: minutes */
			return sprintf( _n( '%d minute ago', '%d minutes ago', $mins, 'doctor-subs' ), $mins );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hours = (int) floor( $diff / HOUR_IN_SECONDS );
			/* translators: %d: hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'doctor-subs' ), $hours );
		}
		if ( $diff < WEEK_IN_SECONDS ) {
			$days = (int) floor( $diff / DAY_IN_SECONDS );
			/* translators: %d: days */
			return sprintf( _n( '%d day ago', '%d days ago', $days, 'doctor-subs' ), $days );
		}
		return wp_date( __( 'M j', 'doctor-subs' ), $ts );
	}

	/**
	 * Look up a subscription's customer name for journal display.
	 *
	 * @param int $sub_id Subscription ID.
	 * @return string
	 */
	private function customer_for_sub( int $sub_id ): string {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}
		$sub = wcs_get_subscription( $sub_id );
		if ( ! $sub ) {
			return '';
		}

		// Fallback chain: billing name -> WP user display_name -> billing
		// email -> WP user email -> generic placeholder. Test-fixture subs
		// often have no billing name set, so without this chain the
		// Customer column renders empty.
		$name = trim( (string) $sub->get_formatted_billing_full_name() );
		if ( '' !== $name ) {
			return $name;
		}

		$user_id = (int) $sub->get_user_id();
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				if ( ! empty( $user->display_name ) ) {
					return (string) $user->display_name;
				}
				if ( ! empty( $user->user_email ) ) {
					return (string) $user->user_email;
				}
			}
		}

		$email = (string) $sub->get_billing_email();
		if ( '' !== $email ) {
			return $email;
		}

		/* translators: %d: subscription ID */
		return sprintf( __( 'Customer #%d', 'doctor-subs' ), $sub_id );
	}

	/**
	 * One-line summary of a journal entry for the history view.
	 *
	 * @param object $row Journal row.
	 * @return string
	 */
	/**
	 * Has any AS action recorded on this journal row already executed?
	 * Used to decide whether the revert UI needs to re-warn the merchant
	 * that the payment has already run and revert won't refund.
	 *
	 * @param object $row Journal row.
	 * @return bool
	 */
	private function journal_has_executed_side_effect( $row ): bool {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}
		$side_effects = json_decode( (string) $row->side_effects, true );
		if ( ! is_array( $side_effects ) ) {
			return false;
		}
		foreach ( $side_effects as $effect ) {
			if ( ! is_array( $effect ) || 'as_action' !== ( $effect['type'] ?? '' ) ) {
				continue;
			}
			$action_id = (int) ( $effect['id'] ?? 0 );
			if ( $action_id <= 0 ) {
				continue;
			}
			try {
				$status = ActionScheduler_Store::instance()->get_status( $action_id );
				if ( ActionScheduler_Store::STATUS_COMPLETE === $status ) {
					return true;
				}
			} catch ( \Throwable $t ) {
				// Action purged - treat as executed-or-gone (safer to warn).
				return true;
			}
		}
		return false;
	}

	private function journal_summary( $row ): string {
		$rule_id = (string) $row->rule_id;

		// Prefer the catalog's plain-English summary so the history reads
		// like a story instead of a debug dump. Fall back to a key:value
		// snapshot of after_state for legacy / unknown rule ids.
		if ( class_exists( 'DR_Subs_Rule_Catalog' ) ) {
			$summary = DR_Subs_Rule_Catalog::journal_summary( $rule_id );
			if ( '' !== $summary ) {
				return $summary;
			}
		}

		$after = json_decode( (string) $row->after_state, true );
		if ( ! is_array( $after ) || empty( $after ) ) {
			return $rule_id;
		}
		$pairs = array();
		foreach ( $after as $k => $v ) {
			$pairs[] = $k . ': ' . ( is_scalar( $v ) ? (string) $v : '[...]' );
		}
		return implode( ', ', array_slice( $pairs, 0, 3 ) );
	}

	/**
	 * Has this entry aged past the merchant's retention window?
	 *
	 * @param string $created_at MySQL DATETIME.
	 * @return bool
	 */
	private function is_past_retention( string $created_at ): bool {
		$settings = get_option( 'dr_subs_settings', DR_Subs_Migration::default_settings() );
		$days     = isset( $settings['journal_retention_days'] ) ? (int) $settings['journal_retention_days'] : 180;
		if ( $days < 0 ) {
			return false;
		}
		$ts = strtotime( $created_at . ' UTC' );
		return $ts && ( time() - $ts ) > ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Sanitize a $_POST array into the settings shape.
	 *
	 * @param array $post Raw POST data.
	 * @return array
	 */
	private function sanitize_settings_post( array $post ): array {
		$defaults = DR_Subs_Migration::default_settings();
		return array(
			'alerts_enabled'         => ! empty( $post['alerts_enabled'] ),
			'alert_email'            => sanitize_email( (string) ( $post['alert_email'] ?? $defaults['alert_email'] ) ),
			'journal_retention_days' => (int) ( $post['journal_retention_days'] ?? $defaults['journal_retention_days'] ),
			'telemetry_enabled'      => ! empty( $post['telemetry_enabled'] ),
		);
	}
}

/**
 * Legacy v1 alias. Do not use in new code; DR_Subs_Admin is canonical.
 *
 * @deprecated 2.0.0 Use DR_Subs_Admin instead.
 */
class_alias( 'DR_Subs_Admin', 'WCST_Admin' );
