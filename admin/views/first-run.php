<?php
/**
 * Surface 1 - First-run landing.
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var string $state       One of: 'default' | 'scanning' | 'zero'.
 * @var int    $scan_total  During scanning: total active subs being scanned.
 * @var int    $scan_left   During scanning: seconds remaining (approximate).
 * @var int    $scan_current During scanning: sub_id currently being checked (for display).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// View is included from DR_Subs_Admin::load_view(); variables are scoped to that
// method's call frame, not globals, so the prefix-all-globals warning is a false
// positive here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$state        = isset( $state ) ? $state : 'default';
$scan_total   = isset( $scan_total ) ? absint( $scan_total ) : 247;
$scan_left    = isset( $scan_left ) ? absint( $scan_left ) : 18;
$scan_current = isset( $scan_current ) ? absint( $scan_current ) : ( $scan_total - ( $scan_left * 8 ) );

$is_scanning = 'scanning' === $state;
$is_zero     = 'zero' === $state;

$pct = 0;
if ( $is_scanning && $scan_total > 0 ) {
	$pct = ( $scan_total - ( $scan_left * ( $scan_total / 30 ) ) ) / $scan_total * 100;
	$pct = max( 8, min( 96, $pct ) );
}
?>
<div class="ds-root">
	<?php
	$active_tab = 'dashboard';
	$show_meta  = false;
	require __DIR__ . '/partials/plugin-header.php';
	?>

	<div class="firstrun">
		<?php if ( ! $is_zero && ! $is_scanning ) : ?>

			<div class="kicker"><?php esc_html_e( 'First visit', 'doctor-subs' ); ?></div>

			<h1 class="hero">
				<?php
				/* translators: %s: "for problems" phrase wrapped with styling. Keep as-is; escaping handled manually. */
				echo wp_kses(
					sprintf(
						/* translators: %s: italic accent phrase "for problems" */
						__( 'Let&rsquo;s check your subscriptions %s.', 'doctor-subs' ),
						'<span class="accent">' . esc_html__( 'for problems', 'doctor-subs' ) . '</span>'
					),
					array( 'span' => array( 'class' => array() ) )
				);
				?>
			</h1>

			<p class="lede">
				<?php esc_html_e( 'We&rsquo;ll scan your active subscriptions for three common renewal failures. Takes about 30 seconds. Runs entirely on your server - nothing sent anywhere.', 'doctor-subs' ); ?>
			</p>

			<div class="actions">
				<button type="button" class="btn btn-primary" data-dr-subs-scan>
					<?php esc_html_e( 'Scan my subscriptions', 'doctor-subs' ); ?>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
					     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
					     aria-hidden="true">
						<path d="M9 18l6-6-6-6" />
					</svg>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=doctor-subs&tab=settings' ) ); ?>"
				   class="secondary">
					<em><?php esc_html_e( 'or', 'doctor-subs' ); ?></em>
					<?php esc_html_e( 'configure settings first', 'doctor-subs' ); ?>
				</a>
			</div>

		<?php elseif ( $is_scanning ) : ?>

			<div class="kicker"><?php esc_html_e( 'Scanning', 'doctor-subs' ); ?></div>

			<h1 class="hero">
				<?php esc_html_e( 'Scanning your subscriptions', 'doctor-subs' ); ?>
				<span class="hero-accent">…</span>
			</h1>

			<p class="lede">
				<?php
				printf(
					/* translators: 1: total active subs, 2: seconds remaining */
					wp_kses_post( __( 'Checking <span class="mono tnum">%1$d</span> active subs - about <span class="mono tnum">%2$d</span> seconds left.', 'doctor-subs' ) ),
					esc_html( $scan_total ),
					esc_html( $scan_left )
				);
				?>
			</p>

			<div class="scanning-bar">
				<div class="progress" role="progressbar"
				     aria-valuenow="<?php echo esc_attr( (int) $pct ); ?>"
				     aria-valuemin="0" aria-valuemax="100"
				     aria-label="<?php esc_attr_e( 'Scan progress', 'doctor-subs' ); ?>">
					<div class="thumb" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
				</div>
				<div class="scanning-meta">
					<span class="mono tnum">
						<?php
						/* translators: %s: sub_id being scanned right now */
						printf( esc_html__( 'checking sub_%s', 'doctor-subs' ), esc_html( str_pad( (string) $scan_current, 5, '0', STR_PAD_LEFT ) ) );
						?>
					</span>
					<button type="button" class="linklike" data-dr-subs-cancel-scan>
						<?php esc_html_e( 'Cancel', 'doctor-subs' ); ?>
					</button>
				</div>
			</div>

		<?php else : /* zero state */ ?>

			<div class="kicker"><?php esc_html_e( 'Nothing to scan yet', 'doctor-subs' ); ?></div>

			<h1 class="hero hero--zero">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: italic muted phrase "to scan yet" */
						__( 'You don&rsquo;t have any active subscriptions %s.', 'doctor-subs' ),
						'<span class="accent accent--muted">' . esc_html__( 'to scan yet', 'doctor-subs' ) . '</span>'
					),
					array( 'span' => array( 'class' => array() ) )
				);
				?>
			</h1>

			<p class="lede">
				<?php esc_html_e( 'Doctor Subs will start watching once you do. You can leave this tab open - or come back when your first customer subscribes.', 'doctor-subs' ); ?>
			</p>

		<?php endif; ?>

		<div class="detects">
			<div class="detects-head">
				<span class="count">03</span>
				<h2><?php esc_html_e( 'What this detects', 'doctor-subs' ); ?></h2>
			</div>

			<div class="detect-row">
				<span class="n">01</span>
				<span class="name"><?php esc_html_e( 'Ghost subscriptions', 'doctor-subs' ); ?></span>
				<span class="desc"><?php esc_html_e( 'Active subscriptions that won&rsquo;t renew because the payment didn&rsquo;t get scheduled.', 'doctor-subs' ); ?></span>
			</div>

			<div class="detect-row">
				<span class="n">02</span>
				<span class="name"><?php esc_html_e( 'Stuck on-hold', 'doctor-subs' ); ?></span>
				<span class="desc"><?php esc_html_e( 'Payment went through, but the subscription never switched back to active.', 'doctor-subs' ); ?></span>
			</div>

			<div class="detect-row">
				<span class="n">03</span>
				<span class="name"><?php esc_html_e( 'Repeated payment failures', 'doctor-subs' ); ?></span>
				<span class="desc"><?php esc_html_e( 'Something has been failing to process a payment for a while.', 'doctor-subs' ); ?></span>
			</div>
		</div>
	</div>
</div>
