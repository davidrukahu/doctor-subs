<?php
declare( strict_types=1 );
/**
 * Admin Interface Controller
 *
 * @package Dr_Subs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface controller class.
 *
 * @since 1.0.0
 */
class WCST_Admin {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'plugin_action_links_' . WCST_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Add admin menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Doctor Subs', 'dr-subs' ),
			__( 'Doctor Subs', 'dr-subs' ),
			'manage_woocommerce',
			'doctor-subs',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_doctor-subs' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wcst-admin-styles',
			WCST_PLUGIN_URL . 'admin/css/admin-styles.css',
			array(),
			WCST_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'wcst-admin-scripts',
			WCST_PLUGIN_URL . 'admin/js/admin-scripts.js',
			array( 'jquery' ),
			WCST_PLUGIN_VERSION,
			true
		);

		// Localize script for AJAX.
		wp_localize_script(
			'wcst-admin-scripts',
			'wcst_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wcst_nonce' ),
				'strings'  => array(
					'error'                   => __( 'An error occurred. Please try again.', 'dr-subs' ),
					'searching'               => __( 'Searching...', 'dr-subs' ),
					'analyzing'               => __( 'Analyzing subscription...', 'dr-subs' ),
					'step1_complete'          => __( 'Step 1: Anatomy analysis complete', 'dr-subs' ),
					'step2_complete'          => __( 'Step 2: Expected behavior analysis complete', 'dr-subs' ),
					'step3_complete'          => __( 'Step 3: Timeline analysis complete', 'dr-subs' ),
					'analysis_complete'       => __( 'Analysis complete!', 'dr-subs' ),
					'no_subscription_found'   => __( 'No subscription found with that ID.', 'dr-subs' ),
					'invalid_subscription_id' => __( 'Please enter a valid subscription ID.', 'dr-subs' ),
				),
			)
		);
	}

	/**
	 * Add action links to the plugin page.
	 *
	 * @since 1.0.0
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=doctor-subs' ),
			__( 'Open', 'dr-subs' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Render the main admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_admin_page() {
		?>
		<div class="wrap wcst-admin-wrap">
			<h1 class="wcst-page-title">
				<span class="wcst-doctor-icon">🩺</span>
				<?php esc_html_e( 'Doctor Subs', 'dr-subs' ); ?>
			</h1>
			
			<div class="wcst-intro">
				<p>
				<?php
					printf(
						/* translators: %s: link to troubleshooting framework documentation */
						esc_html__( 'An intuitive WooCommerce Subscriptions troubleshooting tool that implements a simple 3-step diagnostic process. %s', 'dr-subs' ),
						'<a href="https://woocommerce.com/document/subscriptions/troubleshooting-framework/" target="_blank" rel="noopener">' . esc_html__( 'View Framework Documentation', 'dr-subs' ) . ' ↗</a>'
					);
				?>
				</p>
			</div>

			<!-- Subscription Search -->
					<!-- Subscription Search -->
					<div class="wcst-search-section">
						<h2><?php esc_html_e( 'Search Subscriptions', 'dr-subs' ); ?></h2>
						<p class="wcst-search-description"><?php esc_html_e( 'Enter a subscription ID or customer email to find and analyze subscriptions.', 'dr-subs' ); ?></p>
						<div class="wcst-search-container">
							<input 
								type="text" 
								id="wcst-subscription-search" 
								placeholder="<?php esc_attr_e( 'Enter subscription ID or customer email...', 'dr-subs' ); ?>"
								class="wcst-search-input"
							/>
						</div>
						<div id="wcst-search-results" class="wcst-search-results"></div>
					</div>
					
					<!-- Progress Indicator -->
					<div id="wcst-progress" class="wcst-progress" style="display: none;">
						<h3><?php esc_html_e( '3-Step Troubleshooting Process', 'dr-subs' ); ?></h3>
						<div class="wcst-steps">
							<div class="wcst-step" data-step="1">
								<div class="wcst-step-number">1</div>
								<div class="wcst-step-title"><?php esc_html_e( 'Understand the Anatomy', 'dr-subs' ); ?></div>
								<div class="wcst-step-description"><?php esc_html_e( 'Review subscription structure and configuration', 'dr-subs' ); ?></div>
							</div>
							<div class="wcst-step" data-step="2">
								<div class="wcst-step-number">2</div>
								<div class="wcst-step-title"><?php esc_html_e( 'Determine Expected Behavior', 'dr-subs' ); ?></div>
								<div class="wcst-step-description"><?php esc_html_e( 'Establish what should happen based on setup', 'dr-subs' ); ?></div>
							</div>
							<div class="wcst-step" data-step="3">
								<div class="wcst-step-number">3</div>
								<div class="wcst-step-title"><?php esc_html_e( 'Create Timeline', 'dr-subs' ); ?></div>
								<div class="wcst-step-description"><?php esc_html_e( 'Document what actually occurred', 'dr-subs' ); ?></div>
							</div>
						</div>
					</div>
					
					<!-- Results -->
					<div id="wcst-results" class="wcst-results" style="display: none;">
						
						<!-- Main Analysis Tabs -->
						<div class="wcst-main-tabs">
							<nav class="wcst-main-nav">
								<a href="#step1" class="wcst-main-tab active" data-tab="step1">
									<?php esc_html_e( 'Step 1: Anatomy', 'dr-subs' ); ?>
								</a>
								<a href="#step2" class="wcst-main-tab" data-tab="step2">
									<?php esc_html_e( 'Step 2: Expected', 'dr-subs' ); ?>
								</a>
								<a href="#step3" class="wcst-main-tab" data-tab="step3">
									<?php esc_html_e( 'Step 3: Timeline', 'dr-subs' ); ?>
								</a>
								<a href="#summary" class="wcst-main-tab" data-tab="summary">
									<?php esc_html_e( 'Issues & Stats', 'dr-subs' ); ?>
								</a>
								<a href="#detection" class="wcst-main-tab" data-tab="detection">
									<?php esc_html_e( 'Advanced', 'dr-subs' ); ?>
								</a>
							</nav>
							
							<div class="wcst-main-tab-content">
								<!-- Step 1: Subscription Anatomy -->
								<div id="step1" class="wcst-main-tab-panel active">
									<div class="wcst-section wcst-anatomy-section">
										<h2><?php esc_html_e( 'Step 1: Subscription Anatomy', 'dr-subs' ); ?></h2>
										<p class="wcst-section-description"><?php esc_html_e( 'Understanding how your subscription is structured and configured.', 'dr-subs' ); ?></p>
										<div id="wcst-anatomy-content" class="wcst-content"></div>
									</div>
								</div>
								
								<!-- Step 2: Expected Behavior -->
								<div id="step2" class="wcst-main-tab-panel">
									<div class="wcst-section wcst-expected-section">
										<h2><?php esc_html_e( 'Step 2: Expected Behavior', 'dr-subs' ); ?></h2>
										<p class="wcst-section-description"><?php esc_html_e( 'What should happen based on your subscription configuration.', 'dr-subs' ); ?></p>
										<div id="wcst-expected-content" class="wcst-content"></div>
									</div>
								</div>
								
								<!-- Step 3: Timeline -->
								<div id="step3" class="wcst-main-tab-panel">
									<div class="wcst-section wcst-timeline-section">
										<h2><?php esc_html_e( 'Step 3: Timeline of Events', 'dr-subs' ); ?></h2>
										<p class="wcst-section-description"><?php esc_html_e( 'Chronological record of what actually happened with this subscription.', 'dr-subs' ); ?></p>
										<div id="wcst-timeline-content" class="wcst-content"></div>
									</div>
								</div>
								
								<!-- Issues & Statistics -->
								<div id="summary" class="wcst-main-tab-panel">
									<div class="wcst-section wcst-summary-section">
										<h2><?php esc_html_e( 'Issues Detected & Summary Statistics', 'dr-subs' ); ?></h2>
										<div id="wcst-summary-content" class="wcst-content"></div>
									</div>
								</div>

								<!-- Detection -->
								<div id="detection" class="wcst-main-tab-panel">
									<div class="wcst-section wcst-detection-section">
										<h2><?php esc_html_e( 'Advanced Detection', 'dr-subs' ); ?></h2>
										<p class="wcst-section-description"><?php esc_html_e( 'Advanced detection for subscription issues and anomalies.', 'dr-subs' ); ?></p>
										
										<div class="wcst-detection-sections">
											<div class="wcst-detection-section">
												<h3><?php esc_html_e( 'Skipped Cycles', 'dr-subs' ); ?></h3>
												<p class="wcst-section-description"><?php esc_html_e( 'Detects when subscription payments have skipped expected billing cycles.', 'dr-subs' ); ?></p>
												<div id="wcst-skipped-cycles-content" class="wcst-content"></div>
											</div>
											
											<div class="wcst-detection-section">
												<h3><?php esc_html_e( 'Manual Completions', 'dr-subs' ); ?></h3>
												<p class="wcst-section-description"><?php esc_html_e( 'Identifies orders completed manually without proper transaction IDs.', 'dr-subs' ); ?></p>
												<div id="wcst-manual-completions-content" class="wcst-content"></div>
											</div>
											
											<div class="wcst-detection-section">
												<h3><?php esc_html_e( 'Status Mismatches', 'dr-subs' ); ?></h3>
												<p class="wcst-section-description"><?php esc_html_e( 'Detects inconsistencies between subscription status and payment schedules.', 'dr-subs' ); ?></p>
												<div id="wcst-status-mismatches-content" class="wcst-content"></div>
											</div>
											
											<div class="wcst-detection-section">
												<h3><?php esc_html_e( 'Action Scheduler', 'dr-subs' ); ?></h3>
												<p class="wcst-section-description"><?php esc_html_e( 'Reviews scheduled actions for failed or missing events.', 'dr-subs' ); ?></p>
												<div id="wcst-action-scheduler-content" class="wcst-content"></div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
			</div>
			
		</div>
		<?php
	}
}
