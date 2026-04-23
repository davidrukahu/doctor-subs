<?php
/**
 * Surface 4 - Fix history (undo log).
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var array  $entries       Array of entries, newest first. Each entry:
 *                            ['id','when','customer','sub_id','rule','summary','status','batch','batch_items','past_retention']
 * @var int    $total_count   Total number of entries (for "N fixes" display).
 * @var array  $rule_counts   Associative: ['ghost' => N, 'onhold' => N, 'repfail' => N]
 * @var string $filter        Current rule filter ('all','ghost','onhold','repfail').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// View is included from DR_Subs_Admin::load_view(); variables are scoped to that
// method's call frame, not globals, so the prefix-all-globals warning is a false
// positive here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$entries     = isset( $entries ) && is_array( $entries ) ? $entries : array();
$total_count = isset( $total_count ) ? absint( $total_count ) : count( $entries );
$rule_counts = isset( $rule_counts ) ? $rule_counts : array();
$filter      = isset( $filter ) ? $filter : 'all';

$rule_meta = array(
	'ghost'   => array( 'label' => __( 'Ghost', 'doctor-subs' ),          'pill' => 'pill-broken' ),
	'onhold'  => array( 'label' => __( 'Stuck on-hold', 'doctor-subs' ),  'pill' => 'pill-broken' ),
	'repfail' => array( 'label' => __( 'Repeated fails', 'doctor-subs' ), 'pill' => 'pill-risk'   ),
);

$is_empty = empty( $entries );
?>
<div class="ds-root">
	<?php
	$active_tab = 'history';
	$show_meta  = false;
	require __DIR__ . '/partials/plugin-header.php';
	?>

	<div class="history">

		<?php if ( $is_empty ) : ?>

			<div class="history-head">
				<h1><?php esc_html_e( 'Fix history', 'doctor-subs' ); ?></h1>
			</div>

			<div class="history-empty">
				<div class="kicker"><?php esc_html_e( 'Nothing here yet', 'doctor-subs' ); ?></div>
				<h2><?php esc_html_e( 'No fixes applied.', 'doctor-subs' ); ?></h2>
				<p>
					<?php esc_html_e( 'When you apply a fix from the dashboard, it&rsquo;ll show up here so you can undo it. Fixes stay revertible for 180 days by default - change that in Settings.', 'doctor-subs' ); ?>
				</p>
			</div>

		<?php else : ?>

			<div class="history-head">
				<h1><?php esc_html_e( 'Fix history', 'doctor-subs' ); ?></h1>
				<div class="meta">
					<?php
					printf(
						/* translators: %d: total number of fix entries */
						esc_html( _n( '%d fix · showing most recent first', '%d fixes · showing most recent first', $total_count, 'doctor-subs' ) ),
						(int) $total_count
					);
					?>
				</div>
			</div>

			<div class="history-filters" role="group" aria-label="<?php esc_attr_e( 'Filter fixes by rule', 'doctor-subs' ); ?>">
				<button type="button"
				        class="filter<?php echo 'all' === $filter ? ' active' : ''; ?>"
				        data-dr-subs-history-filter="all"
				        aria-pressed="<?php echo 'all' === $filter ? 'true' : 'false'; ?>">
					<?php esc_html_e( 'All', 'doctor-subs' ); ?>
					<span class="count"><?php echo esc_html( (string) $total_count ); ?></span>
				</button>
				<?php foreach ( $rule_meta as $rid => $meta ) :
					$count = isset( $rule_counts[ $rid ] ) ? (int) $rule_counts[ $rid ] : 0;
					if ( 0 === $count ) {
						continue;
					}
					?>
					<button type="button"
					        class="filter<?php echo $filter === $rid ? ' active' : ''; ?>"
					        data-dr-subs-history-filter="<?php echo esc_attr( $rid ); ?>"
					        aria-pressed="<?php echo $filter === $rid ? 'true' : 'false'; ?>">
						<?php echo esc_html( $meta['label'] ); ?>
						<span class="count"><?php echo esc_html( (string) $count ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $entries as $entry ) :
				$is_batch          = ! empty( $entry['batch'] );
				$is_reverted       = ( $entry['status'] ?? '' ) === 'reverted';
				$past_retention    = ! empty( $entry['past_retention'] );
				$classes           = array( 'entry' );
				if ( $is_batch ) { $classes[] = 'batch'; }
				if ( $is_reverted ) { $classes[] = 'reverted'; }
				?>
				<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
				     data-dr-subs-entry
				     data-entry-id="<?php echo esc_attr( $entry['id'] ?? '' ); ?>">

					<div class="when"><?php echo esc_html( $entry['when'] ?? '' ); ?></div>

					<?php if ( $is_batch ) : ?>

						<div>
							<div class="batch-head">
								<?php
								$batch_count = isset( $entry['batch_count'] ) ? (int) $entry['batch_count'] : count( (array) ( $entry['batch_items'] ?? array() ) );
								printf(
									/* translators: %d: number of subs in batch */
									esc_html( _n( 'Fixed %d subscription in one batch', 'Fixed %d subscriptions in one batch', $batch_count, 'doctor-subs' ) ),
									(int) $batch_count
								);
								?>
							</div>
							<div class="batch-meta">
								<?php
								$items = (array) ( $entry['batch_items'] ?? array() );
								$ids   = array_map(
									static function ( $i ) {
										return '<span class="batch-item-id">#' . esc_html( $i ) . '</span>';
									},
									$items
								);
								echo wp_kses(
									implode( ', ', $ids ),
									array( 'span' => array( 'class' => array() ) )
								);
								?>
								· <?php echo esc_html( $entry['summary'] ?? '' ); ?>
								<?php if ( ! empty( $entry['batch_id'] ) ) : ?>
									· <?php esc_html_e( 'batch id', 'doctor-subs' ); ?>
									<span class="batch-id-value"><?php echo esc_html( $entry['batch_id'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>

					<?php else : ?>

						<div>
							<div class="who">
								<span class="name"><?php echo esc_html( $entry['customer'] ?? '' ); ?></span>
								<span class="sub">#<?php echo esc_html( (string) ( $entry['sub_id'] ?? '' ) ); ?></span>
							</div>
							<div class="summary"><?php echo esc_html( $entry['summary'] ?? '' ); ?></div>
						</div>

						<div class="rule">
							<?php
							$rid = $entry['rule'] ?? 'ghost';
							$rmeta = $rule_meta[ $rid ] ?? $rule_meta['ghost'];
							if ( $is_reverted ) {
								echo '<span class="pill pill-quiet">' . esc_html__( 'Reverted', 'doctor-subs' ) . '</span>';
							} else {
								echo '<span class="pill ' . esc_attr( $rmeta['pill'] ) . '">' . esc_html( $rmeta['label'] ) . '</span>';
							}
							?>
						</div>

					<?php endif; ?>

					<div class="action">
						<?php if ( $is_reverted ) : ?>
							<span class="status">
								<?php
								/* translators: %s: when the revert happened, e.g. "1h ago" */
								printf( esc_html__( 'Reverted %s', 'doctor-subs' ), esc_html( $entry['reverted_when'] ?? '' ) );
								?>
							</span>
						<?php elseif ( $past_retention ) : ?>
							<span class="status status--muted">
								<?php esc_html_e( 'Too old to revert', 'doctor-subs' ); ?>
							</span>
						<?php else : ?>
							<button type="button" class="btn btn-ghost btn-sm"
							        data-dr-subs-revert
							        data-entry-id="<?php echo esc_attr( $entry['id'] ?? '' ); ?>">
								<?php
								if ( $is_batch ) {
									printf(
										/* translators: %d: number of subs in batch */
										esc_html__( 'Revert all %d', 'doctor-subs' ),
										(int) ( $entry['batch_count'] ?? count( (array) ( $entry['batch_items'] ?? array() ) ) )
									);
								} else {
									esc_html_e( 'Revert', 'doctor-subs' );
								}
								?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

		<?php endif; ?>
	</div>
</div>
