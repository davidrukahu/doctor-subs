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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// View is included from DR_Subs_Admin::load_view(); variables are scoped to that
// method's call frame, not globals, so the prefix-all-globals warning is a false
// positive here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$state        = isset( $state ) ? $state : 'mixed';
$counts       = isset( $counts ) ? $counts : array( 'healthy' => 238, 'risk' => 3, 'broken' => 3 );
$subs         = isset( $subs ) ? $subs : array();
$filter       = isset( $filter ) ? $filter : 'all';
$last_scanned = isset( $last_scanned ) ? $last_scanned : __( '2 hours ago', 'doctor-subs' );
$stale        = isset( $stale ) ? (bool) $stale : false;

$is_failed     = 'failed' === $state;
$is_healthy    = 'healthy' === $state;
$is_refreshing = 'refreshing' === $state;

if ( $is_healthy ) {
	$counts = array( 'healthy' => $counts['healthy'], 'risk' => 0, 'broken' => 0 );
}

$rule_meta = array(
	'ghost'   => array( 'label' => __( 'Ghost', 'doctor-subs' ),         'pill' => 'pill-broken', 'dot' => 'dot-broken' ),
	'onhold'  => array( 'label' => __( 'Stuck on-hold', 'doctor-subs' ), 'pill' => 'pill-risk',   'dot' => 'dot-risk'   ),
	'repfail' => array( 'label' => __( 'Repeated fails', 'doctor-subs' ),'pill' => 'pill-risk',   'dot' => 'dot-risk'   ),
);

// Filter visible rows per current filter.
$visible = $subs;
if ( 'all' !== $filter ) {
	$visible = array_values( array_filter( $subs, static function ( $s ) use ( $filter ) {
		return isset( $s['bucket'] ) && $s['bucket'] === $filter;
	} ) );
}

$broken_count = count( array_filter( $subs, static fn( $s ) => ( $s['bucket'] ?? '' ) === 'broken' ) );
$risk_count   = count( array_filter( $subs, static fn( $s ) => ( $s['bucket'] ?? '' ) === 'risk' ) );
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

		<!-- Counters row -->
		<div class="counters<?php echo $is_refreshing ? ' refreshing' : ''; ?>"
		     role="group" aria-label="<?php esc_attr_e( 'Subscription health counts', 'doctor-subs' ); ?>">

			<?php
			$counter_configs = array(
				'healthy' => array(
					'label' => __( 'Healthy', 'doctor-subs' ),
					'hint'  => __( 'no problems', 'doctor-subs' ),
				),
				'risk'    => array(
					'label' => __( 'At risk', 'doctor-subs' ),
					'hint'  => __( 'might need attention', 'doctor-subs' ),
				),
				'broken'  => array(
					'label' => __( 'Broken', 'doctor-subs' ),
					'hint'  => __( 'needs you now', 'doctor-subs' ),
				),
			);
			$i = 0;
			foreach ( $counter_configs as $state_key => $cfg ) :
				$n      = isset( $counts[ $state_key ] ) ? (int) $counts[ $state_key ] : 0;
				$active = $filter === $state_key;
				?>
				<?php if ( $i++ > 0 ) : ?><div class="divider" aria-hidden="true"></div><?php endif; ?>
				<button type="button"
				        class="counter<?php echo $active ? ' active' : ''; ?>"
				        data-state="<?php echo esc_attr( $state_key ); ?>"
				        data-dr-subs-filter="<?php echo esc_attr( $state_key ); ?>"
				        aria-pressed="<?php echo $active ? 'true' : 'false'; ?>">
					<span class="counter-label">
						<span class="dot dot-<?php echo esc_attr( $state_key ); ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( $cfg['label'] ); ?></span>
					</span>
					<span class="counter-n<?php echo ( 0 === $n && 'healthy' !== $state_key ) ? ' zero' : ''; ?>">
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

				<div class="table-wrap">
					<table class="ds-table">
						<thead>
							<tr>
								<th style="width: 40px;"><span class="screen-reader-text"><?php esc_html_e( 'Rule indicator', 'doctor-subs' ); ?></span></th>
								<th><?php esc_html_e( 'Customer', 'doctor-subs' ); ?></th>
								<th><?php esc_html_e( 'Subscription', 'doctor-subs' ); ?></th>
								<th><?php esc_html_e( 'Issue', 'doctor-subs' ); ?></th>
								<th><?php esc_html_e( 'Reason', 'doctor-subs' ); ?></th>
								<th class="action-cell"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'doctor-subs' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $visible as $s ) :
							$rule = isset( $s['rule'] ) ? $s['rule'] : 'ghost';
							$meta = $rule_meta[ $rule ] ?? $rule_meta['ghost'];
							?>
							<tr class="clickable"
							    data-dr-subs-row
							    data-sub-id="<?php echo esc_attr( $s['id'] ); ?>"
							    data-rule="<?php echo esc_attr( $rule ); ?>"
							    tabindex="0">
								<td>
									<span class="dot <?php echo esc_attr( $meta['dot'] ); ?>" aria-hidden="true"></span>
								</td>
								<td class="customer">
									<?php echo esc_html( $s['name'] ); ?>
								</td>
								<td>
									<span class="sub-id">#<?php echo esc_html( (string) $s['id'] ); ?></span>
									<?php if ( ! empty( $s['amount'] ) ) : ?>
										<span class="amount"><?php echo esc_html( $s['amount'] ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<span class="pill <?php echo esc_attr( $meta['pill'] ); ?>">
										<?php echo esc_html( $meta['label'] ); ?>
									</span>
								</td>
								<td class="reason" title="<?php echo esc_attr( $s['reason'] ); ?>">
									<?php echo esc_html( $s['reason'] ); ?>
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
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

		<?php endif; ?>
	</div>
</div>
