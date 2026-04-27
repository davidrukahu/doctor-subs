<?php
/**
 * Doctor Subs - render all v2 surfaces into one static HTML preview.
 *
 * Runs the real PHP view templates under design-brief/v2-assets/ with
 * fixture data + WordPress-function stubs. Captures each surface's
 * output and writes a single scrollable review page.
 *
 * Usage:
 *   php design-brief/render-preview.php > design-brief/all-surfaces-preview.html
 *   open design-brief/all-surfaces-preview.html
 *
 * Fonts resolve relative to tokens.css → v2-assets/admin/fonts/*.woff2.
 */

declare( strict_types=1 );

// ---- Let templates know they're "in wp" ---- //
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ---- Minimal WordPress function stubs ---- //
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return (string) $text; }
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = null ) { echo __( $text ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $domain = null ) {
		return (int) $n === 1 ? (string) $single : (string) $plural;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $ctx, $domain = null ) { return (string) $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; }
}
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $string, $allowed ) { return (string) $string; } // trust for preview
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $string ) { return (string) $string; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return '#' . $path; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action, $name ) {
		echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="NONCE_STUB">';
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $c, $v = true, $echo = true ) {
		$out = (string) $c === (string) $v || ( $v === true && $c ) ? ' checked' : '';
		if ( $echo ) echo $out;
		return $out;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $s, $v = true, $echo = true ) {
		$out = (string) $s === (string) $v ? ' selected' : '';
		if ( $echo ) echo $out;
		return $out;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = '' ) {
		$map = array( 'admin_email' => 'david@mercato.shop' );
		return $map[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults ) { return array_merge( (array) $defaults, (array) $args ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
}

// ---- Fixtures ---- //

$VIEWS_DIR = __DIR__ . '/../admin/views';

$SUBS_FIXTURE = array(
	array( 'id' => 4812, 'name' => 'Sarah Mendez',       'rule' => 'ghost',   'reason' => "Next payment didn't get rescheduled after the March 15 renewal.", 'bucket' => 'broken', 'amount' => '$29.00', 'since' => 'Mar 15' ),
	array( 'id' => 5104, 'name' => 'Marcus Abernathy',   'rule' => 'onhold',  'reason' => 'Charged $29 in Stripe on Apr 8 but subscription stayed on-hold.',  'bucket' => 'broken', 'amount' => '$29.00', 'since' => 'Apr 8' ),
	array( 'id' => 3918, 'name' => 'Anya Volkova',       'rule' => 'repfail', 'reason' => "4 failed renewal attempts since Mar 22 - looks like a gateway issue.", 'bucket' => 'broken', 'amount' => '$49.00', 'since' => 'Mar 22' ),
	array( 'id' => 6230, 'name' => 'Jin-ho Park',        'rule' => 'ghost',   'reason' => 'Renewal event for Apr 19 missing from the scheduler.',                'bucket' => 'risk',   'amount' => '$15.00', 'since' => 'Apr 19' ),
	array( 'id' => 6477, 'name' => 'Beatrice Owoyemi',   'rule' => 'repfail', 'reason' => '2 failed attempts in the last 36 hours - card may be declining.',   'bucket' => 'risk',   'amount' => '$79.00', 'since' => 'Apr 20' ),
	array( 'id' => 2201, 'name' => 'Theo Lindqvist',     'rule' => 'onhold',  'reason' => 'Last payment succeeded Apr 12, status has been on-hold since.',      'bucket' => 'risk',   'amount' => '$12.00', 'since' => 'Apr 12' ),
);

$HISTORY_FIXTURE = array(
	array(
		'id' => 'f_001', 'when' => '12 minutes ago',
		'customer' => 'Sarah Mendez', 'sub_id' => 4812,
		'rule' => 'ghost',
		'summary' => 'Rescheduled next payment for Apr 23, 09:00 UTC. AS event id 1284921.',
		'status' => 'applied',
	),
	array(
		'id' => 'f_002', 'when' => '2 hours ago',
		'customer' => 'Marcus Abernathy', 'sub_id' => 5104,
		'rule' => 'onhold',
		'summary' => 'Flipped on-hold → active after matching renewal charge in Stripe (Apr 8, $29).',
		'status' => 'reverted', 'reverted_when' => '1h ago',
	),
	array(
		'id' => 'f_003', 'when' => 'Yesterday · 16:40',
		'batch' => true, 'batch_id' => 'b_4f21', 'batch_count' => 3,
		'batch_items' => array( 6201, 6230, 6477 ),
		'summary' => 'Ghost sub rule applied in batch',
		'rule' => 'ghost',
		'status' => 'applied',
	),
	array(
		'id' => 'f_004', 'when' => '3 days ago',
		'customer' => 'Anya Volkova', 'sub_id' => 3918,
		'rule' => 'repfail',
		'summary' => 'Retried failed scheduled payment. Gateway accepted the retry, sub is now active.',
		'status' => 'applied',
	),
	array(
		'id' => 'f_005', 'when' => 'Mar 28 · 11:02',
		'customer' => 'Jin-ho Park', 'sub_id' => 6230,
		'rule' => 'ghost',
		'summary' => 'Rescheduled next payment for Mar 29. Sub has renewed twice since (unreverted).',
		'status' => 'applied',
		'past_retention' => true,
	),
);

// ---- Helper ---- //

function render_view( string $path, array $vars ): string {
	extract( $vars, EXTR_SKIP );
	ob_start();
	include $path;
	return ob_get_clean();
}

function wp_chrome( string $content, string $active_menu_label = 'Doctor Subs' ): string {
	$items = array(
		array( 'label' => 'Overview',       'icon' => '▤', 'active' => false ),
		array( 'label' => 'Orders',         'icon' => '▢', 'active' => false ),
		array( 'label' => 'Products',       'icon' => '▧', 'active' => false ),
		array( 'label' => 'Customers',      'icon' => '◎', 'active' => false ),
		array( 'label' => 'Subscriptions',  'icon' => '∞', 'active' => false ),
		array( 'label' => 'Doctor Subs',    'icon' => '✚', 'active' => true  ),
		array( 'label' => 'Analytics',      'icon' => '◨', 'active' => false ),
		array( 'label' => 'Settings',       'icon' => '⚙', 'active' => false ),
	);
	ob_start();
	?>
	<div class="wp-shell">
		<div class="wp-topbar">
			<span class="wp-topbar-site">mercato · admin</span>
			<span class="wp-topbar-dim">plugins</span>
			<span class="wp-topbar-dim">updates (2)</span>
			<span class="wp-topbar-spacer"></span>
			<span class="wp-topbar-dim">howdy, rita</span>
		</div>
		<div class="wp-shell-body">
			<aside class="wp-sidebar">
				<div class="wp-sidebar-label">Admin</div>
				<?php foreach ( $items as $it ) : ?>
					<div class="wp-sidebar-item<?php echo $it['active'] ? ' active' : ''; ?>">
						<span class="wp-sidebar-icon"><?php echo $it['icon']; ?></span>
						<span><?php echo esc_html( $it['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</aside>
			<main class="wp-main">
				<?php echo $content; ?>
			</main>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

// ---- Render each surface ---- //

$firstrun_default  = render_view( "$VIEWS_DIR/first-run.php", array( 'state' => 'default' ) );
$firstrun_scanning = render_view( "$VIEWS_DIR/first-run.php", array( 'state' => 'scanning', 'scan_total' => 247, 'scan_left' => 18 ) );
$firstrun_zero     = render_view( "$VIEWS_DIR/first-run.php", array( 'state' => 'zero' ) );

$dashboard_mixed = render_view( "$VIEWS_DIR/dashboard.php", array(
	'state'        => 'mixed',
	'counts'       => array( 'healthy' => 238, 'risk' => 3, 'broken' => 3 ),
	'subs'         => $SUBS_FIXTURE,
	'filter'       => 'all',
	'last_scanned' => '2 hours ago',
	'stale'        => false,
) );
$dashboard_healthy = render_view( "$VIEWS_DIR/dashboard.php", array(
	'state'        => 'healthy',
	'counts'       => array( 'healthy' => 241, 'risk' => 0, 'broken' => 0 ),
	'subs'         => array(),
	'filter'       => 'all',
	'last_scanned' => '2 hours ago',
) );
$dashboard_failed = render_view( "$VIEWS_DIR/dashboard.php", array(
	'state'        => 'failed',
	'counts'       => array( 'healthy' => 238, 'risk' => 3, 'broken' => 3 ),
	'subs'         => $SUBS_FIXTURE,
	'filter'       => 'all',
	'last_scanned' => '18 hours ago',
	'stale'        => true,
) );

$modal_ghost = render_view( "$VIEWS_DIR/modal-fix-preview.php", array(
	'sub_id'        => 4812,
	'customer_name' => 'Sarah Mendez',
	'rule_id'       => 'ghost',
	'narrative'     => "Sarah's monthly subscription was supposed to renew on <em>March 15 at 09:00</em>. WordPress didn't schedule the payment event, so nothing was charged. She hasn't been billed for April either.",
	'diff'          => array(
		array( 'field' => 'Next payment', 'before' => '- (not scheduled)', 'after' => 'Apr 23 · 09:00 UTC', 'emph' => true ),
		array( 'field' => 'AS event',     'before' => 'missing', 'after' => 'woocommerce_scheduled_subscription_payment · id 1284921', 'emph' => true ),
		array( 'field' => 'Sub status',   'before' => 'active',  'after' => 'active', 'unchanged' => true ),
	),
	'already_executed' => false,
) );

$modal_executed = render_view( "$VIEWS_DIR/modal-fix-preview.php", array(
	'sub_id'        => 5104,
	'customer_name' => 'Marcus Abernathy',
	'rule_id'       => 'onhold',
	'narrative'     => "Marcus's last renewal payment went through in Stripe on <em>April 8</em>. He was charged $29. But his subscription status stayed \"on hold\" instead of switching back to active.",
	'diff'          => array(
		array( 'field' => 'Sub status',    'before' => 'on-hold',        'after' => 'active',    'emph' => true ),
		array( 'field' => 'Renewal #5105', 'before' => 'on-hold',        'after' => 'completed', 'emph' => true ),
		array( 'field' => 'Next payment',  'before' => 'May 8 · 09:00',  'after' => 'May 8 · 09:00', 'unchanged' => true ),
	),
	'already_executed' => true, // triggers the warning block
) );

$history_default = render_view( "$VIEWS_DIR/fix-history.php", array(
	'entries'     => $HISTORY_FIXTURE,
	'total_count' => 14,
	'rule_counts' => array( 'ghost' => 8, 'onhold' => 5, 'repfail' => 1 ),
	'filter'      => 'all',
) );

$history_empty = render_view( "$VIEWS_DIR/fix-history.php", array(
	'entries'     => array(),
	'total_count' => 0,
	'rule_counts' => array(),
	'filter'      => 'all',
) );

$settings_default = render_view( "$VIEWS_DIR/settings.php", array(
	'settings' => array(
		'alerts_enabled'         => true,
		'alert_email'            => 'david@mercato.shop',
		'journal_retention_days' => 180,
		'telemetry_enabled'      => false,
	),
	'last_saved' => '3 days ago',
	'just_saved' => false,
) );

// ---- Emit the combined HTML ---- //
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Doctor Subs v2 - All Surfaces Preview</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="../admin/css/tokens.css">
<link rel="stylesheet" href="../admin/css/admin.css">
<link rel="stylesheet" href="../admin/css/responsive.css">
<style>
/* Preview chrome only - not part of the plugin */
html, body {
	margin: 0;
	background: oklch(95% 0.008 200);
	font-family: 'Switzer', system-ui, sans-serif;
	color: oklch(18% 0.015 210);
}

.preview-header {
	position: sticky; top: 0; z-index: 200;
	background: oklch(99% 0.004 200);
	border-bottom: 1px solid oklch(92% 0.008 200);
	padding: 14px 28px;
	display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
}
.preview-header .title { font-family: 'Instrument Serif', serif; font-size: 22px; letter-spacing: -0.01em; font-weight: 400; }
.preview-header .meta { font-size: 12px; color: oklch(55% 0.01 210); }
.preview-header .toc { display: flex; gap: 12px; margin-left: auto; flex-wrap: wrap; }
.preview-header .toc a {
	font-size: 12px;
	color: oklch(38% 0.012 210);
	text-decoration: none;
	border-bottom: 1px dotted oklch(72% 0.008 210);
	padding-bottom: 1px;
}
.preview-header .toc a:hover { color: oklch(28% 0.05 195); border-bottom-color: oklch(48% 0.06 195); }

.preview-section { padding: 40px 28px 20px; }
.preview-group { padding: 20px 28px 10px; }
.preview-group h1 {
	font-family: 'Instrument Serif', serif;
	font-size: 36px; letter-spacing: -0.015em; font-weight: 400;
	margin: 0 0 4px;
}
.preview-group p { color: oklch(55% 0.01 210); font-size: 13.5px; max-width: 720px; margin: 0; }

.preview-label { font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: oklch(55% 0.01 210); margin-bottom: 8px; }
.preview-title { font-family: 'Instrument Serif', serif; font-size: 24px; letter-spacing: -0.015em; margin-bottom: 2px; font-weight: 400; }
.preview-desc { font-size: 13px; color: oklch(38% 0.012 210); max-width: 640px; margin-bottom: 20px; }

/* The WP admin "shell" wrapping each rendered surface */
.preview-frame {
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 2px 8px oklch(18% 0.015 210 / 0.08), 0 16px 40px oklch(18% 0.015 210 / 0.06);
	border: 1px solid oklch(92% 0.008 200);
	background: oklch(99% 0.004 200);
	position: relative;
}

.wp-shell { height: 780px; display: flex; flex-direction: column; overflow: hidden; }
.wp-topbar {
	height: 34px;
	background: oklch(16% 0.012 210);
	color: oklch(75% 0.008 210);
	font-size: 12px;
	display: flex; align-items: center;
	padding: 0 14px; gap: 16px;
	border-bottom: 1px solid oklch(10% 0.01 210);
}
.wp-topbar-site { color: oklch(88% 0.01 210); font-weight: 500; }
.wp-topbar-dim { opacity: 0.6; }
.wp-topbar-spacer { flex: 1; }
.wp-shell-body { display: flex; flex: 1; min-height: 0; }
.wp-sidebar {
	width: 188px; flex: 0 0 188px;
	background: oklch(22% 0.012 210);
	color: oklch(80% 0.008 210);
	font-size: 12.5px;
	padding: 14px 0; user-select: none;
}
.wp-sidebar-label {
	padding: 4px 16px 12px; font-size: 10.5px; letter-spacing: 0.12em;
	text-transform: uppercase; color: oklch(58% 0.01 210);
}
.wp-sidebar-item {
	padding: 7px 16px; display: flex; align-items: center; gap: 10px;
	color: oklch(78% 0.008 210);
	border-left: 2px solid transparent;
}
.wp-sidebar-item.active {
	background: oklch(30% 0.03 200);
	color: oklch(95% 0.008 200);
	border-left-color: oklch(60% 0.07 195);
}
.wp-sidebar-icon { width: 14px; opacity: 0.7; text-align: center; font-size: 12px; }
.wp-main { flex: 1; overflow: auto; background: oklch(99% 0.004 200); }

/* Modal preview - scope the fixed-position modal to its preview frame */
.preview-frame--modal {
	position: relative;
	height: 820px;
	isolation: isolate;
	overflow: hidden;
}
.preview-frame--modal .wp-shell { height: 100%; }
.preview-frame--modal .modal-backdrop {
	position: absolute !important;
	inset: 0 !important;
	z-index: 20;
}
.preview-frame--modal .modal {
	position: absolute !important;
	top: 48px !important;
	left: 50% !important;
	transform: translateX(-50%) !important;
	width: 560px !important;
	max-width: calc(100% - 60px) !important;
	max-height: calc(100% - 96px) !important;
	z-index: 30;
	overflow-y: auto;
}

.preview-frame--modal .executed-warning-note {
	margin-top: 10px;
	padding: 10px 14px;
	background: oklch(95% 0.02 160);
	color: oklch(32% 0.06 160);
	border-radius: 6px;
	font-size: 12.5px;
	max-width: 720px;
}

hr.sep {
	border: 0;
	border-top: 1px solid oklch(87% 0.01 200);
	margin: 40px 0 0;
}

@media print {
	.preview-header, .preview-label, .preview-title, .preview-desc { display: none; }
}
</style>
</head>
<body>

<div class="preview-header">
	<div>
		<div class="title">Doctor Subs v2 - All Surfaces Preview</div>
		<div class="meta">9 states rendered from real PHP templates · <code>design-brief/all-surfaces-preview.html</code></div>
	</div>
	<nav class="toc">
		<a href="#s1">First-run</a>
		<a href="#s1-scan">· scanning</a>
		<a href="#s1-zero">· zero</a>
		<a href="#s2">Dashboard</a>
		<a href="#s2-healthy">· all healthy</a>
		<a href="#s2-failed">· scan failed</a>
		<a href="#s3">Fix modal</a>
		<a href="#s3-exec">· exec warning</a>
		<a href="#s4">Fix history</a>
		<a href="#s4-empty">· empty</a>
		<a href="#s5">Settings</a>
	</nav>
</div>

<section class="preview-group">
	<h1>Surface 1 - First-run landing</h1>
	<p>The very first visit to the plugin. Before any scan has run. Panic-mode entry point must lower the merchant's heart rate.</p>
</section>

<section class="preview-section" id="s1">
	<div class="preview-label">State: default</div>
	<div class="preview-title">Default - the calm CTA</div>
	<div class="preview-desc">What a brand-new install sees. Instrument Serif hero, one primary action, three detect-rows underneath.</div>
	<div class="preview-frame"><?php echo wp_chrome( $firstrun_default ); ?></div>
</section>

<section class="preview-section" id="s1-scan">
	<div class="preview-label">State: scanning</div>
	<div class="preview-title">Scanning (midway through first scan)</div>
	<div class="preview-desc">Deterministic progress bar. Live <code>sub_id</code> being checked. Honest, not performative.</div>
	<div class="preview-frame"><?php echo wp_chrome( $firstrun_scanning ); ?></div>
</section>

<section class="preview-section" id="s1-zero">
	<div class="preview-label">State: zero active subs</div>
	<div class="preview-title">Nothing to scan yet</div>
	<div class="preview-desc">Alternative copy for stores with no active subscriptions. No CTA.</div>
	<div class="preview-frame"><?php echo wp_chrome( $firstrun_zero ); ?></div>
</section>

<hr class="sep">

<section class="preview-group">
	<h1>Surface 2 - Dashboard</h1>
	<p>The main surface after a scan completes. Also what returning merchants land on. Three counters, one "Needs attention" table.</p>
</section>

<section class="preview-section" id="s2">
	<div class="preview-label">State: default (mixed)</div>
	<div class="preview-title">Normal weekday - 3 broken, 3 at-risk</div>
	<div class="preview-desc">Terracotta for broken, warm amber for at-risk, muted green for healthy. Counters are clickable filters. "Needs attention" table shows the breakdown.</div>
	<div class="preview-frame"><?php echo wp_chrome( $dashboard_mixed ); ?></div>
</section>

<section class="preview-section" id="s2-healthy">
	<div class="preview-label">State: all healthy</div>
	<div class="preview-title">Everything looks good</div>
	<div class="preview-desc">Zero broken, zero at-risk. Quiet confirmation replaces the table. The most calming state the product can produce.</div>
	<div class="preview-frame"><?php echo wp_chrome( $dashboard_healthy ); ?></div>
</section>

<section class="preview-section" id="s2-failed">
	<div class="preview-label">State: scan failed</div>
	<div class="preview-title">Inline banner, non-blocking</div>
	<div class="preview-desc">Scanner crashed or timed out. Calm tone, action available, nothing blocking the rest of the UI. Note: uses background-tint banner (not left-stripe - that pattern was rewritten).</div>
	<div class="preview-frame"><?php echo wp_chrome( $dashboard_failed ); ?></div>
</section>

<hr class="sep">

<section class="preview-group">
	<h1>Surface 3 - Fix preview modal</h1>
	<p>The highest-trust moment in the product. Named diff. Two buttons. Never surprising.</p>
</section>

<section class="preview-section" id="s3">
	<div class="preview-label">State: default (Ghost sub)</div>
	<div class="preview-title">Sarah Mendez #4812</div>
	<div class="preview-desc">Narrative in plain English. Every field that will change is shown side-by-side before commit. Reversibility is explicit.</div>
	<div class="preview-frame preview-frame--modal">
		<?php echo wp_chrome( $dashboard_mixed ); ?>
		<?php echo $modal_ghost; ?>
	</div>
</section>

<section class="preview-section" id="s3-exec">
	<div class="preview-label">State: already-executed warning (Stuck on-hold)</div>
	<div class="preview-title">Marcus Abernathy #5104 - charge already ran</div>
	<div class="preview-desc">
		Resolves the <code>revert-silent-no-op</code> concern flagged in /plan-eng-review. When the plugin detects that the fix's scheduled action has already executed (the payment fired and charged the customer), the modal renders an explicit warning. Reverting this fix can't undo the charge, and now the merchant is told clearly.
	</div>
	<div class="preview-frame preview-frame--modal">
		<?php echo wp_chrome( $dashboard_mixed ); ?>
		<?php echo $modal_executed; ?>
	</div>
</section>

<hr class="sep">

<section class="preview-group">
	<h1>Surface 4 - Fix history</h1>
	<p>Every applied fix lives here. Undoable until retention window closes. Mixes individual + batch fixes + reverted + past-retention.</p>
</section>

<section class="preview-section" id="s4">
	<div class="preview-label">State: default (14 fixes)</div>
	<div class="preview-title">Fix history - mixed entries</div>
	<div class="preview-desc">Shows individual fixes, a grouped batch fix, a reverted entry, and one past the retention window. Filter tabs at top. Revert button on each.</div>
	<div class="preview-frame"><?php echo wp_chrome( $history_default ); ?></div>
</section>

<section class="preview-section" id="s4-empty">
	<div class="preview-label">State: empty (fresh install)</div>
	<div class="preview-title">No fixes yet</div>
	<div class="preview-desc">Honest empty state. No cheer. Tells the merchant what this page is for so they don't wonder.</div>
	<div class="preview-frame"><?php echo wp_chrome( $history_empty ); ?></div>
</section>

<hr class="sep">

<section class="preview-group">
	<h1>Surface 5 - Settings</h1>
	<p>Three groups: Alerts, Retention, Telemetry. Unsaved-changes guard in JS. Telemetry off by default, explicit about what's sent.</p>
</section>

<section class="preview-section" id="s5">
	<div class="preview-label">State: default</div>
	<div class="preview-title">Settings - default values</div>
	<div class="preview-desc">Alerts ON, retention 180 days, telemetry OFF. Each section explains what it does in plain language. Toggle state reflects in the visible label.</div>
	<div class="preview-frame"><?php echo wp_chrome( $settings_default ); ?></div>
</section>

</body>
</html>
