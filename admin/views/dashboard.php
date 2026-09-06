<?php
/**
 * Surface 2 - Dashboard (traffic-light counters + needs-attention table).
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var string $state         One of: 'mixed' (default) | 'healthy' | 'failed' | 'refreshing'.
 * @var array  $counts        Associative array: ['healthy' => int, 'risk' => int, 'broken' => int]
 * @var array  $subs          Array of sub rows for the table. Each: [id, name, rule, reason, bucket, amount, since]
 * @var string $filter        Current bucket filter: 'all' | 'broken' | 'risk' | 'healthy'
 * @var string $last_scanned  Relative string.
 * @var bool   $stale         If last scan is older than stale threshold.
 * @var int    $page          Current page of the Needs attention table (1-based).
 * @var int    $total_rows    Total rows matching the current filter, across all pages.
 * @var int    $total_pages   Number of pages at the current page size.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// View is included from DR_Subs_Admin::load_view(); variables are scoped to that
// method's call frame, not globals, so the prefix-all-globals warning is a false
// positive here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$state        = isset( $state ) ? $state : 'mixed';
$counts       = isset( $counts ) ? $counts : array(
	'healthy' => 238,
	'risk'    => 3,
	'broken'  => 3,
);
$subs         = isset( $subs ) ? $subs : array();
$filter       = isset( $filter ) ? $filter : 'all';
$last_scanned = isset( $last_scanned ) ? $last_scanned : __( '2 hours ago', 'doctor-subs' );
$stale        = isset( $stale ) ? (bool) $stale : false;

$is_failed     = 'failed' === $state;
$is_healthy    = 'healthy' === $state;
$is_refreshing = 'refreshing' === $state;

if ( $is_healthy ) {
	$counts = array(
		'healthy' => $counts['healthy'],
		'risk'    => 0,
		'broken'  => 0,
	);
}

$rule_meta = array(
	// Canonical rule ids emitted by the journal + scanner.
	'ghost_sub'            => array(
		'label' => __( 'Ghost', 'doctor-subs' ),
		'pill'  => 'pill-broken',
		'dot'   => 'dot-broken',
	),
	'onhold_paid'          => array(
		'label' => __( 'Stuck on-hold', 'doctor-subs' ),
		'pill'  => 'pill-broken',
		'dot'   => 'dot-broken',
	),
	'repeated_failures'    => array(
		'label' => __( 'Repeated fails', 'doctor-subs' ),
		'pill'  => 'pill-risk',
		'dot'   => 'dot-risk',
	),
	'mass_hold'            => array(
		'label' => __( 'Mass hold', 'doctor-subs' ),
		'pill'  => 'pill-broken',
		'dot'   => 'dot-broken',
	),
	'total_drift'          => array(
		'label' => __( 'Total drift', 'doctor-subs' ),
		'pill'  => 'pill-risk',
		'dot'   => 'dot-risk',
	),
	'manual_renewal_drift' => array(
		'label' => __( 'Manual-renewal drift', 'doctor-subs' ),
		'pill'  => 'pill-broken',
		'dot'   => 'dot-broken',
	),
	// Legacy short-name fallbacks (kept for back-compat with any pre-existing rows).
	'ghost'                => array(
		'label' => __( 'Ghost', 'doctor-subs' ),
		'pill'  => 'pill-broken',
		'dot'   => 'dot-broken',
	),
	'onhold'               => array(
		'label' => __( 'Stuck on-hold', 'doctor-subs' ),
		'pill'  => 'pill-broken',
		'dot'   => 'dot-broken',
	),
	'repfail'              => array(
		'label' => __( 'Repeated fails', 'doctor-subs' ),
		'pill'  => 'pill-risk',
		'dot'   => 'dot-risk',
	),
);

// The query already applied the filter and the page window, so the rows handed
// to this view are exactly the rows to draw. Filtering again here is what made
// the old table drop rows that the SQL LIMIT had already truncated.
$visible = $subs;

$page        = isset( $page ) ? max( 1, (int) $page ) : 1;
$total_rows  = isset( $total_rows ) ? (int) $total_rows : count( $subs );
$total_pages = isset( $total_pages ) ? (int) $total_pages : 1;

// Bucket tallies for the filter chips. These describe the whole result set,
// not just this page, so they come from the counters rather than the rows.
$broken_count = isset( $counts['broken'] ) ? (int) $counts['broken'] : 0;
$risk_count   = isset( $counts['risk'] ) ? (int) $counts['risk'] : 0;

/**
 * Build a dashboard URL preserving the current filter.
 *
 * @param int $target_page Page to link to.
 * @return string
 */
$dr_subs_page_url = static function ( int $target_page ) use ( $filter ): string {
	$args = array( 'page' => 'doctor-subs' );
	if ( 'all' !== $filter ) {
		$args['filter'] = $filter;
	}
	if ( $target_page > 1 ) {
		$args['ds_page'] = $target_page;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
};

// Collect distinct rule ids present in the table for the chip row,
// preserving the registry order (most-impactful first).
$rule_chip_order = array( 'manual_renewal_drift', 'ghost_sub', 'mass_hold', 'onhold_paid', 'repeated_failures', 'total_drift' );
$present_rules   = array_unique( array_filter( array_map( static fn( $s ) => (string) ( $s['rule'] ?? '' ), $subs ) ) );
$rule_chips      = array();
foreach ( $rule_chip_order as $rid ) {
	if ( in_array( $rid, $present_rules, true ) && isset( $rule_meta[ $rid ] ) ) {
		$rule_chips[ $rid ] = $rule_meta[ $rid ];
	}
}

// Rule one-liners for chip + pill tooltips.
$rule_summaries = class_exists( 'DR_Subs_Rule_Catalog' ) ? DR_Subs_Rule_Catalog::summaries() : array();
?>
<div class="ds-root">
	<?php
	$active_tab = 'dashboard';
	$show_meta  = true;
	require __DIR__ . '/partials/plugin-header.php';
	?>

	<div class="dashboard">

		<?php if ( $is_failed ) : ?>
			<div class="banner banner-risk fade-in" role="status">
				<span class="banner-icon" aria-hidden="true">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="9" />
						<path d="M12 8v5" />
						<circle cx="12" cy="16" r="0.8" fill="currentColor" />
					</svg>
				</span>
				<span class="banner-body">
					<?php esc_html_e( 'The last scan didn&rsquo;t complete. Nothing was changed.', 'doctor-subs' ); ?>
				</span>
				<button type="button" class="linklike" data-dr-subs-retry-scan><?php esc_html_e( 'Try again', 'doctor-subs' ); ?></button>
				<span class="banner-sep" aria-hidden="true">·</span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>">
					<?php esc_html_e( 'View logs', 'doctor-subs' ); ?>
				</a>
			</div>
		<?php endif; ?>

		<?php
		$healthy_n = isset( $counts['healthy'] ) ? (int) $counts['healthy'] : 0;
		$total_n   = $healthy_n + ( isset( $counts['risk'] ) ? (int) $counts['risk'] : 0 ) + ( isset( $counts['broken'] ) ? (int) $counts['broken'] : 0 );
		?>
		<?php if ( $total_n > 0 ) : ?>
			<div class="scan-summary" role="status" aria-live="polite">
				<span class="dot dot-healthy" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: 1: healthy sub count, 2: total scanned count */
					esc_html__( '%1$s of %2$s healthy', 'doctor-subs' ),
					esc_html( number_format_i18n( $healthy_n ) ),
					esc_html( number_format_i18n( $total_n ) )
				);
				?>
			</div>
		<?php endif; ?>

		<!-- Counters row: action-only (broken + at-risk). Healthy moved to
			the scan-summary line above so it can't be mistaken for a
			filter chip. -->
		<div class="counters<?php echo $is_refreshing ? ' refreshing' : ''; ?>"
			role="group" aria-label="<?php esc_attr_e( 'Subscription health counts', 'doctor-subs' ); ?>">

			<?php
			$counter_configs = array(
				'risk'   => array(
					'label' => __( 'At risk', 'doctor-subs' ),
					'hint'  => __( 'since last scan', 'doctor-subs' ),
				),
				'broken' => array(
					'label' => __( 'Broken', 'doctor-subs' ),
					'hint'  => __( 'since last scan', 'doctor-subs' ),
				),
			);
			$i               = 0;
			foreach ( $counter_configs as $state_key => $cfg ) :
				$n      = isset( $counts[ $state_key ] ) ? (int) $counts[ $state_key ] : 0;
				$active = $filter === $state_key;
				?>
				<?php
				if ( $i++ > 0 ) :
					?>
					<div class="divider" aria-hidden="true"></div><?php endif; ?>
				<button type="button"
						class="counter<?php echo $active ? ' active' : ''; ?>"
						data-state="<?php echo esc_attr( $state_key ); ?>"
						data-dr-subs-filter="<?php echo esc_attr( $state_key ); ?>"
						aria-pressed="<?php echo $active ? 'true' : 'false'; ?>">
					<span class="counter-label">
						<span class="dot dot-<?php echo esc_attr( $state_key ); ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( $cfg['label'] ); ?></span>
					</span>
					<span class="counter-n<?php echo 0 === $n ? ' zero' : ''; ?>">
						<span class="num tnum"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
						<span class="hint"><?php echo esc_html( $cfg['hint'] ); ?></span>
					</span>
				</button>
			<?php endforeach; ?>
		</div>

		<?php if ( $is_healthy ) : ?>

			<!-- All-healthy empty state -->
			<div class="all-clear fade-in">
				<div class="kicker">
					<span class="dot dot-healthy" aria-hidden="true"></span>
					<?php esc_html_e( 'All clear', 'doctor-subs' ); ?>
				</div>
				<h2><?php esc_html_e( 'Everything looks good.', 'doctor-subs' ); ?></h2>
				<p>
					<?php
					/* translators: %s: relative time */
					echo esc_html( sprintf( __( 'We&rsquo;ll keep watching. Last checked %s.', 'doctor-subs' ), $last_scanned ) );
					?>
				</p>
			</div>

		<?php else : ?>

			<!-- Needs attention table -->
			<div class="table-region">
				<div class="table-head">
					<div class="left">
						<h2><?php esc_html_e( 'Needs attention', 'doctor-subs' ); ?></h2>
						<span class="meta">
							<?php
							if ( 'all' === $filter ) {
								esc_html_e( 'showing all broken and at-risk', 'doctor-subs' );
							} elseif ( 'broken' === $filter ) {
								printf(
									/* translators: %d: number of broken subs filtered */
									esc_html( _n( 'filtering to %d broken', 'filtering to %d broken', $broken_count, 'doctor-subs' ) ),
									(int) $broken_count
								);
							} elseif ( 'risk' === $filter ) {
								printf(
									/* translators: %d: number of at-risk subs filtered */
									esc_html( _n( 'filtering to %d at-risk', 'filtering to %d at-risk', $risk_count, 'doctor-subs' ) ),
									(int) $risk_count
								);
							}
							?>
						</span>
					</div>
					<?php if ( 'all' !== $filter ) : ?>
						<button type="button" class="clear" data-dr-subs-filter="all">
							<?php esc_html_e( 'clear filter', 'doctor-subs' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $subs ) ) : ?>
					<div class="table-toolbar" role="search">
						<div class="ds-search">
							<svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
								stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
								aria-hidden="true">
								<circle cx="11" cy="11" r="7" />
								<path d="m20 20-3.5-3.5" />
							</svg>
							<input type="search"
									data-dr-subs-search
									placeholder="<?php esc_attr_e( 'Search by sub #, customer, or email', 'doctor-subs' ); ?>"
									aria-label="<?php esc_attr_e( 'Search subscriptions', 'doctor-subs' ); ?>" />
							<button type="button"
									class="clear-search"
									data-dr-subs-search-clear
									aria-label="<?php esc_attr_e( 'Clear search', 'doctor-subs' ); ?>"
									hidden>×</button>
						</div>

						<?php if ( ! empty( $rule_chips ) ) : ?>
							<div class="rule-chips" role="tablist" aria-label="<?php esc_attr_e( 'Filter by rule', 'doctor-subs' ); ?>">
								<button type="button"
										class="rule-chip active"
										data-dr-subs-rule-chip="all"
										aria-pressed="true">
									<?php esc_html_e( 'All rules', 'doctor-subs' ); ?>
								</button>
								<?php
								foreach ( $rule_chips as $rid => $cfg ) :
									$tip           = isset( $rule_summaries[ $rid ] ) ? (string) $rule_summaries[ $rid ] : '';
									$bulk_disabled = ( 'total_drift' === $rid ) ? '1' : '0';
									?>
									<button type="button"
											class="rule-chip"
											data-dr-subs-rule-chip="<?php echo esc_attr( $rid ); ?>"
											data-bulk-disabled="<?php echo esc_attr( $bulk_disabled ); ?>"
											aria-pressed="false"
											<?php if ( '' !== $tip ) : ?>title="<?php echo esc_attr( $tip ); ?>"<?php endif; ?>>
										<span class="dot <?php echo esc_attr( $cfg['dot'] ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( $cfg['label'] ); ?>
									</button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<button type="button"
								class="btn btn-primary btn-sm bulk-fix-btn"
								data-dr-subs-bulk-fix
								hidden>
							<?php esc_html_e( 'Fix all', 'doctor-subs' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<div class="table-wrap">
					<table class="ds-table">
						<thead>
							<tr>
								<th style="width: 40px;"><span class="screen-reader-text"><?php esc_html_e( 'Rule indicator', 'doctor-subs' ); ?></span></th>
								<th><?php esc_html_e( 'Customer', 'doctor-subs' ); ?></th>
								<th><?php esc_html_e( 'Subscription', 'doctor-subs' ); ?></th>
								<th><span class="screen-reader-text"><?php esc_html_e( 'Issue', 'doctor-subs' ); ?></span></th>
								<th><?php esc_html_e( 'Reason', 'doctor-subs' ); ?></th>
								<th class="action-cell"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'doctor-subs' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $visible as $s ) :
							$rule   = isset( $s['rule'] ) ? $s['rule'] : 'ghost_sub';
							$meta   = $rule_meta[ $rule ] ?? $rule_meta['ghost_sub'];
							$bucket = isset( $s['bucket'] ) ? (string) $s['bucket'] : 'broken';
							?>
							<tr class="clickable"
								data-dr-subs-row
								data-sub-id="<?php echo esc_attr( $s['id'] ); ?>"
								data-rule="<?php echo esc_attr( $rule ); ?>"
								data-bucket="<?php echo esc_attr( $bucket ); ?>"
								data-customer="<?php echo esc_attr( strtolower( (string) $s['name'] ) ); ?>"
								data-email="<?php echo esc_attr( strtolower( (string) ( $s['email'] ?? '' ) ) ); ?>"
								tabindex="0">
								<td>
									<span class="dot <?php echo esc_attr( $meta['dot'] ); ?>" aria-hidden="true"></span>
								</td>
								<td class="customer">
									<?php echo esc_html( $s['name'] ); ?>
								</td>
								<td>
									<?php if ( ! empty( $s['edit_url'] ) ) : ?>
										<a class="sub-id has-ext"
											href="<?php echo esc_url( $s['edit_url'] ); ?>"
											target="_blank" rel="noopener noreferrer"
											data-dr-subs-row-link
											title="<?php esc_attr_e( 'Open subscription in WooCommerce (new tab)', 'doctor-subs' ); ?>">
											<span class="num">#<?php echo esc_html( (string) $s['id'] ); ?></span>
											<svg class="ext-icon" width="9" height="9" viewBox="0 0 12 12" fill="none"
												stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
												aria-hidden="true">
												<path d="M4.5 7.5 8 4" />
												<path d="M5 4h3v3" />
											</svg>
										</a>
									<?php else : ?>
										<span class="sub-id">#<?php echo esc_html( (string) $s['id'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $s['amount'] ) ) : ?>
										<span class="amount"><?php echo wp_kses_post( $s['amount'] ); ?></span>
									<?php endif; ?>
								</td>
								<?php $rule_tip = isset( $rule_summaries[ $rule ] ) ? (string) $rule_summaries[ $rule ] : ''; ?>
								<td>
									<span class="pill <?php echo esc_attr( $meta['pill'] ); ?>"
											<?php
											if ( '' !== $rule_tip ) :
												?>
												title="<?php echo esc_attr( $rule_tip ); ?>"<?php endif; ?>>
										<?php echo esc_html( $meta['label'] ); ?>
									</span>
								</td>
								<?php $reason_plain = wp_strip_all_tags( (string) $s['reason'] ); ?>
								<td class="reason" title="<?php echo esc_attr( $reason_plain ); ?>">
									<?php echo esc_html( $reason_plain ); ?>
								</td>
								<td class="action-cell">
									<button type="button"
											class="btn btn-outline btn-sm"
											data-dr-subs-fix
											data-sub-id="<?php echo esc_attr( $s['id'] ); ?>">
										<?php esc_html_e( 'Fix', 'doctor-subs' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if ( empty( $visible ) ) : ?>
							<tr>
								<td colspan="6" class="table-empty">
									<?php esc_html_e( 'No subscriptions match this filter.', 'doctor-subs' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<tr data-dr-subs-empty hidden>
								<td colspan="6" class="table-empty">
									<?php esc_html_e( 'No subscriptions match.', 'doctor-subs' ); ?>
								</td>
							</tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<nav class="ds-pagination" aria-label="<?php esc_attr_e( 'Needs attention pages', 'doctor-subs' ); ?>">
						<span class="ds-pagination-count">
							<?php
							$dr_subs_first = ( ( $page - 1 ) * count( $visible ) ) + 1;
							printf(
								/* translators: 1: first row number on this page, 2: last row number, 3: total rows */
								esc_html__( 'Showing %1$s to %2$s of %3$s', 'doctor-subs' ),
								esc_html( number_format_i18n( max( 1, $dr_subs_first ) ) ),
								esc_html( number_format_i18n( min( $total_rows, $dr_subs_first + count( $visible ) - 1 ) ) ),
								esc_html( number_format_i18n( $total_rows ) )
							);
							?>
						</span>

						<span class="ds-pagination-links">
							<?php if ( $page > 1 ) : ?>
								<a class="ds-page-link" href="<?php echo esc_url( $dr_subs_page_url( $page - 1 ) ); ?>" rel="prev">
									<?php esc_html_e( 'Previous', 'doctor-subs' ); ?>
								</a>
							<?php else : ?>
								<span class="ds-page-link is-disabled"><?php esc_html_e( 'Previous', 'doctor-subs' ); ?></span>
							<?php endif; ?>

							<span class="ds-pagination-position">
								<?php
								printf(
									/* translators: 1: current page number, 2: total number of pages */
									esc_html__( 'Page %1$s of %2$s', 'doctor-subs' ),
									esc_html( number_format_i18n( $page ) ),
									esc_html( number_format_i18n( $total_pages ) )
								);
								?>
							</span>

							<?php if ( $page < $total_pages ) : ?>
								<a class="ds-page-link" href="<?php echo esc_url( $dr_subs_page_url( $page + 1 ) ); ?>" rel="next">
									<?php esc_html_e( 'Next', 'doctor-subs' ); ?>
								</a>
							<?php else : ?>
								<span class="ds-page-link is-disabled"><?php esc_html_e( 'Next', 'doctor-subs' ); ?></span>
							<?php endif; ?>
						</span>
					</nav>
				<?php endif; ?>
			</div>

		<?php endif; ?>
	</div>
</div>
