<?php
/**
 * Plugin header partial - brand, meta, tabs.
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var string $active_tab     Current tab: 'dashboard', 'history', 'settings'.
 * @var bool   $show_meta      Whether to render "Last scanned" + Refresh.
 * @var string $last_scanned   Human-readable timestamp ("2 hours ago").
 * @var bool   $stale          True if the last scan is older than the stale threshold.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Partial is included from DR_Subs_Admin::load_view(); variables are scoped to
// that method's call frame, not globals, so the prefix-all-globals warning is
// a false positive here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$active_tab   = isset( $active_tab ) ? $active_tab : 'dashboard';
$show_meta    = isset( $show_meta ) ? (bool) $show_meta : true;
$last_scanned = isset( $last_scanned ) ? $last_scanned : '';
$stale        = isset( $stale ) ? (bool) $stale : false;

$tabs = array(
	'dashboard' => array(
		'label' => __( 'Dashboard', 'doctor-subs' ),
		'url'   => admin_url( 'admin.php?page=doctor-subs' ),
	),
	'history'   => array(
		'label' => __( 'Fix history', 'doctor-subs' ),
		'url'   => admin_url( 'admin.php?page=doctor-subs&tab=history' ),
	),
	'settings'  => array(
		'label' => __( 'Settings', 'doctor-subs' ),
		'url'   => admin_url( 'admin.php?page=doctor-subs&tab=settings' ),
	),
);
?>
<div class="plugin-header">
	<div class="plugin-header-inner">
		<div class="plugin-brand">
			<?php
			$size = 26;
			require __DIR__ . '/stethoscope.php';
			?>
			<div>
				<div class="name"><?php esc_html_e( 'Doctor Subs', 'doctor-subs' ); ?></div>
				<div class="tagline"><?php esc_html_e( 'Find and fix broken WooCommerce subscriptions.', 'doctor-subs' ); ?></div>
			</div>
		</div>

		<?php if ( $show_meta ) : ?>
			<div class="plugin-meta<?php echo $stale ? ' stale' : ''; ?>">
				<span>
					<?php
					/* translators: %s: relative time, e.g. "2 hours ago" */
					echo esc_html( sprintf( __( 'Last scanned %s', 'doctor-subs' ), $last_scanned ) );
					?>
				</span>
				<span class="sep">·</span>
				<button type="button" class="refresh" data-dr-subs-refresh
				        aria-keyshortcuts="R"
				        title="<?php esc_attr_e( 'Keyboard shortcut: R', 'doctor-subs' ); ?>">
					<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
					     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M21 12a9 9 0 1 1-3-6.7L21 8" />
						<path d="M21 3v5h-5" />
					</svg>
					<?php
					echo esc_html( $stale ? __( 'Refresh now', 'doctor-subs' ) : __( 'Refresh', 'doctor-subs' ) );
					?>
					<kbd class="kbd-hint" aria-hidden="true">R</kbd>
				</button>
			</div>
		<?php endif; ?>
	</div>

	<nav class="tabs" aria-label="<?php esc_attr_e( 'Doctor Subs sections', 'doctor-subs' ); ?>">
		<?php foreach ( $tabs as $id => $tab ) : ?>
			<a class="tab<?php echo $active_tab === $id ? ' active' : ''; ?>"
			   href="<?php echo esc_url( $tab['url'] ); ?>"
			   <?php echo $active_tab === $id ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $tab['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
</div>
