<?php
/**
 * Surface 3 - Fix preview modal.
 *
 * Loaded via AJAX when the merchant clicks a row or "Fix" button.
 * Rendered HTML fragment (not a full page).
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var int    $sub_id        The subscription ID being fixed.
 * @var string $customer_name Customer's full name.
 * @var string $rule_id       'ghost' | 'onhold' | 'repfail'
 * @var string $narrative     Plain-English description of what happened.
 *                            May contain one <em> wrapping a date/detail.
 * @var array  $diff          Array of ['field' => str, 'before' => str, 'after' => str, 'emph' => bool, 'unchanged' => bool]
 * @var bool   $already_executed  Optional. True if the fix may have already run side-effects
 *                                (e.g., re-enqueued AS action executed). Triggers the executed-payment warning.
 *                                Resolves the "revert-silent-no-op UX" concern from /plan-eng-review.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sub_id            = isset( $sub_id ) ? absint( $sub_id ) : 0;
$customer_name     = isset( $customer_name ) ? $customer_name : '';
$rule_id           = isset( $rule_id ) ? $rule_id : 'ghost';
$narrative         = isset( $narrative ) ? $narrative : '';
$diff              = isset( $diff ) && is_array( $diff ) ? $diff : array();
$already_executed  = isset( $already_executed ) ? (bool) $already_executed : false;

$rule_meta = array(
	'ghost'   => array( 'label' => __( 'Ghost', 'doctor-subs' ),          'pill' => 'pill-broken' ),
	'onhold'  => array( 'label' => __( 'Stuck on-hold', 'doctor-subs' ),  'pill' => 'pill-broken' ),
	'repfail' => array( 'label' => __( 'Repeated fails', 'doctor-subs' ), 'pill' => 'pill-risk'   ),
);
$meta = $rule_meta[ $rule_id ] ?? $rule_meta['ghost'];
?>
<div class="ds-root" data-dr-subs-modal-layer>
<div class="modal-backdrop" data-dr-subs-modal-backdrop tabindex="-1"></div>
<div class="modal"
     role="dialog"
     aria-modal="true"
     aria-labelledby="dr-subs-modal-title"
     data-dr-subs-modal
     data-sub-id="<?php echo esc_attr( $sub_id ); ?>">

	<div class="modal-head">
		<span class="sub-id">#<?php echo esc_html( (string) $sub_id ); ?></span>
		<span class="customer" id="dr-subs-modal-title">
			<?php echo esc_html( $customer_name ); ?>
		</span>
		<span class="rule">
			<span class="pill <?php echo esc_attr( $meta['pill'] ); ?>">
				<?php echo esc_html( $meta['label'] ); ?>
			</span>
		</span>
	</div>

	<div class="modal-body">
		<p class="narrative">
			<?php
			// Narrative contains trusted copy from the rule's template engine.
			// Allow only <em> for the display-serif date accent.
			echo wp_kses( $narrative, array( 'em' => array(), 'strong' => array() ) );
			?>
		</p>

		<div class="section-label"><?php esc_html_e( 'What will change', 'doctor-subs' ); ?></div>

		<div class="diff" role="list">
			<?php foreach ( $diff as $row ) :
				$field     = $row['field'] ?? '';
				$before    = $row['before'] ?? '';
				$after     = $row['after'] ?? '';
				$emph      = ! empty( $row['emph'] );
				$unchanged = ! empty( $row['unchanged'] );
				?>
				<div class="diff-row" role="listitem">
					<span class="field"><?php echo esc_html( $field ); ?></span>
					<span class="before"><?php echo esc_html( $before ); ?></span>
					<span class="arrow" aria-hidden="true">→</span>
					<span class="after<?php echo $emph ? ' emph' : ''; ?>">
						<?php echo esc_html( $after ); ?>
						<?php if ( $unchanged ) : ?>
							<span class="unchanged">(<?php esc_html_e( 'unchanged', 'doctor-subs' ); ?>)</span>
						<?php endif; ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="revert-note">
			<?php esc_html_e( 'You can undo this from Fix history at any time.', 'doctor-subs' ); ?>
		</p>

		<?php if ( $already_executed ) : ?>
			<!--
				Executed-payment warning. Resolves the revert-silent-no-op UX concern.
				Shown when the fix we are about to apply OR revert may have had side-effects
				that already ran (e.g., the re-enqueued AS payment event already fired and
				charged the customer). Explicit about what revert can and cannot undo.
			-->
			<div class="executed-warning" role="note">
				<span class="icon" aria-hidden="true">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none"
					     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="9" />
						<path d="M12 8v5" />
						<circle cx="12" cy="16" r="0.8" fill="currentColor" />
					</svg>
				</span>
				<span class="body">
					<strong><?php esc_html_e( 'Heads up:', 'doctor-subs' ); ?></strong>
					<?php
					esc_html_e(
						'the payment we re-scheduled already went through. Reverting this fix will not refund the customer - it only removes the fix from your history. If a refund is needed, handle it in the WooCommerce order directly.',
						'doctor-subs'
					);
					?>
				</span>
			</div>
		<?php endif; ?>
	</div>

	<div class="modal-foot">
		<button type="button" class="btn btn-ghost" data-dr-subs-modal-cancel>
			<?php esc_html_e( 'Cancel', 'doctor-subs' ); ?>
		</button>
		<button type="button" class="btn btn-primary" data-dr-subs-modal-apply data-sub-id="<?php echo esc_attr( $sub_id ); ?>">
			<?php esc_html_e( 'Fix subscription', 'doctor-subs' ); ?>
		</button>
	</div>
</div>
</div><!-- /.ds-root modal layer -->
