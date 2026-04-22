<?php
/**
 * Surface 5 - Settings.
 *
 * @package Dr_Subs
 * @since   2.0.0
 *
 * @var array $settings  Current settings values. Keys:
 *                       'alerts_enabled' (bool), 'alert_email' (string),
 *                       'journal_retention_days' (int), 'telemetry_enabled' (bool).
 * @var string $last_saved Relative string or empty.
 * @var bool   $just_saved True right after save, shows "Saved" flash.
 * @var string $email_error Error message if email invalid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$defaults = array(
	'alerts_enabled'         => true,
	'alert_email'            => get_option( 'admin_email', '' ),
	'journal_retention_days' => 180,
	'telemetry_enabled'      => false,
);
$settings    = isset( $settings ) ? wp_parse_args( $settings, $defaults ) : $defaults;
$last_saved  = isset( $last_saved ) ? $last_saved : '';
$just_saved  = isset( $just_saved ) ? (bool) $just_saved : false;
$email_error = isset( $email_error ) ? $email_error : '';

$retention_options = array( 30, 90, 180, 365, -1 ); // -1 = forever
$retention_label   = static function ( $days ) {
	if ( -1 === (int) $days ) {
		return __( 'Forever', 'doctor-subs' );
	}
	if ( 365 === (int) $days ) {
		return __( '365 days', 'doctor-subs' );
	}
	/* translators: %d: number of days */
	return sprintf( _n( '%d day', '%d days', (int) $days, 'doctor-subs' ), (int) $days );
};
?>
<div class="ds-root">
	<?php
	$active_tab = 'settings';
	$show_meta  = false;
	require __DIR__ . '/partials/plugin-header.php';
	?>

	<form method="post" class="settings" data-dr-subs-settings>
		<?php wp_nonce_field( 'dr_subs_settings_save', 'dr_subs_settings_nonce' ); ?>

		<h1><?php esc_html_e( 'Settings', 'doctor-subs' ); ?></h1>

		<!-- Group 1 - Alerts -->
		<div class="settings-group">
			<h2><?php esc_html_e( 'Alerts', 'doctor-subs' ); ?></h2>
			<div class="blurb">
				<?php esc_html_e( 'When something breaks between scans, we&rsquo;ll email you a daily digest. Off by default.', 'doctor-subs' ); ?>
			</div>

			<div class="field">
				<label class="label" for="dr-subs-alerts-enabled">
					<?php esc_html_e( 'Email alerts', 'doctor-subs' ); ?>
				</label>
				<div>
					<label class="toggle">
						<input type="checkbox"
						       name="alerts_enabled"
						       id="dr-subs-alerts-enabled"
						       value="1"
						       <?php checked( ! empty( $settings['alerts_enabled'] ) ); ?>
						       aria-describedby="dr-subs-alerts-helper">
						<span class="track" aria-hidden="true"></span>
						<span class="toggle-state" data-on-label="<?php esc_attr_e( 'On', 'doctor-subs' ); ?>" data-off-label="<?php esc_attr_e( 'Off', 'doctor-subs' ); ?>">
							<?php echo ! empty( $settings['alerts_enabled'] ) ? esc_html__( 'On', 'doctor-subs' ) : esc_html__( 'Off', 'doctor-subs' ); ?>
						</span>
					</label>
					<div class="helper" id="dr-subs-alerts-helper">
						<?php esc_html_e( 'When new broken subscriptions appear, you&rsquo;ll get a daily summary email.', 'doctor-subs' ); ?>
					</div>
				</div>
			</div>

			<div class="field">
				<label class="label" for="dr-subs-alert-email">
					<?php esc_html_e( 'Send to', 'doctor-subs' ); ?>
				</label>
				<div>
					<input type="email"
					       name="alert_email"
					       id="dr-subs-alert-email"
					       value="<?php echo esc_attr( $settings['alert_email'] ); ?>"
					       aria-describedby="dr-subs-alert-email-helper<?php echo $email_error ? ' dr-subs-alert-email-error' : ''; ?>"
					       autocomplete="email">
					<div class="helper" id="dr-subs-alert-email-helper">
						<?php esc_html_e( 'Defaults to your WordPress admin email.', 'doctor-subs' ); ?>
					</div>
					<?php if ( $email_error ) : ?>
						<div class="error-msg" id="dr-subs-alert-email-error" role="alert">
							<?php echo esc_html( $email_error ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Group 2 - Retention -->
		<div class="settings-group">
			<h2><?php esc_html_e( 'Fix history retention', 'doctor-subs' ); ?></h2>
			<div class="blurb">
				<?php esc_html_e( 'Older entries get pruned automatically. Recent entries stay revertible.', 'doctor-subs' ); ?>
			</div>

			<div class="field">
				<div class="label-with-help">
					<label class="label" for="dr-subs-retention">
						<?php esc_html_e( 'Keep fixes for', 'doctor-subs' ); ?>
					</label>
					<span class="help-btn-wrap">
						<button type="button" class="help-btn" aria-label="<?php esc_attr_e( 'More about retention', 'doctor-subs' ); ?>">?</button>
						<span class="help-popover" role="tooltip">
							<?php esc_html_e( 'Fixes older than this stay in your history for reference, but the Revert button is removed. They are not deleted from the database unless you prune manually.', 'doctor-subs' ); ?>
						</span>
					</span>
				</div>
				<div>
					<select name="journal_retention_days" id="dr-subs-retention" aria-describedby="dr-subs-retention-helper">
						<?php foreach ( $retention_options as $days ) : ?>
							<option value="<?php echo esc_attr( (string) $days ); ?>"
							        <?php selected( (int) $settings['journal_retention_days'], (int) $days ); ?>>
								<?php echo esc_html( $retention_label( $days ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<div class="helper" id="dr-subs-retention-helper">
						<?php esc_html_e( 'Older fixes stay in your history but can&rsquo;t be reverted anymore.', 'doctor-subs' ); ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Group 3 - Telemetry -->
		<div class="settings-group">
			<h2><?php esc_html_e( 'Help us improve', 'doctor-subs' ); ?></h2>
			<div class="blurb">
				<?php esc_html_e( 'Anonymous telemetry helps us know which fixes are actually used. We only send the rule name and a timestamp when a fix is applied. Nothing else - no customer data, no subscription details, no identifying information.', 'doctor-subs' ); ?>
			</div>

			<div class="field">
				<div class="label-with-help">
					<label class="label" for="dr-subs-telemetry">
						<?php esc_html_e( 'Send anonymous fix stats', 'doctor-subs' ); ?>
					</label>
					<span class="help-btn-wrap">
						<button type="button" class="help-btn" aria-label="<?php esc_attr_e( 'What exactly gets sent', 'doctor-subs' ); ?>">?</button>
						<span class="help-popover" role="tooltip">
							<?php esc_html_e( 'Three fields only: (1) the rule name (e.g. ghost_sub), (2) an anonymous install hash, (3) a timestamp. We never see your store URL, customer data, subscription details, or store name.', 'doctor-subs' ); ?>
						</span>
					</span>
				</div>
				<div>
					<label class="toggle">
						<input type="checkbox"
						       name="telemetry_enabled"
						       id="dr-subs-telemetry"
						       value="1"
						       <?php checked( ! empty( $settings['telemetry_enabled'] ) ); ?>
						       aria-describedby="dr-subs-telemetry-helper">
						<span class="track" aria-hidden="true"></span>
						<span class="toggle-state" data-on-label="<?php esc_attr_e( 'On', 'doctor-subs' ); ?>" data-off-label="<?php esc_attr_e( 'Off', 'doctor-subs' ); ?>">
							<?php echo ! empty( $settings['telemetry_enabled'] ) ? esc_html__( 'On', 'doctor-subs' ) : esc_html__( 'Off', 'doctor-subs' ); ?>
						</span>
					</label>
					<div class="helper" id="dr-subs-telemetry-helper">
						<?php esc_html_e( 'Off by default. Turn it on if you want to help us build the plugin better.', 'doctor-subs' ); ?>
					</div>
				</div>
			</div>
		</div>

		<div class="settings-foot">
			<button type="submit" class="btn btn-primary">
				<?php esc_html_e( 'Save changes', 'doctor-subs' ); ?>
			</button>
			<button type="reset" class="btn btn-ghost">
				<?php esc_html_e( 'Discard', 'doctor-subs' ); ?>
			</button>
			<?php if ( $just_saved ) : ?>
				<span class="saved-flash" role="status"><?php esc_html_e( 'Saved.', 'doctor-subs' ); ?></span>
			<?php elseif ( $last_saved ) : ?>
				<span class="status">
					<?php
					/* translators: %s: relative time */
					echo esc_html( sprintf( __( 'Last saved %s.', 'doctor-subs' ), $last_saved ) );
					?>
				</span>
			<?php endif; ?>
		</div>
	</form>
</div>
