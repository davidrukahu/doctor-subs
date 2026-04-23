<?php
/**
 * Alert dispatcher.
 *
 * Listens on the `dr_subs_after_scan` action. When the scan found
 * newly-broken subscriptions that haven't been suppressed by the
 * merchant, composes a plain-text digest email and sends it to the
 * configured address (or admin_email as fallback).
 *
 * Plain-text email is a deliberate choice per the design brief: this
 * is a transactional health-status email, not marketing. Treat it
 * like one.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily digest email composer + sender.
 *
 * @since 2.0.0
 */
class DR_Subs_Alert_Dispatcher {

	/**
	 * Register the hook. Called from DR_Subs_Plugin::init_hooks().
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'dr_subs_after_scan', array( __CLASS__, 'maybe_send_digest' ), 20, 1 );
	}

	/**
	 * Action handler. Decides whether to send based on settings +
	 * suppression, then composes + sends.
	 *
	 * @param array $summary DR_Subs_Health_Scanner::run() return value.
	 * @return void
	 */
	public static function maybe_send_digest( array $summary ): void {
		$settings = get_option( 'dr_subs_settings', DR_Subs_Migration::default_settings() );
		if ( empty( $settings['alerts_enabled'] ) ) {
			return;
		}

		$newly_broken = (array) ( $summary['newly_broken_sub_ids'] ?? array() );
		if ( empty( $newly_broken ) ) {
			return;
		}

		$newly_broken = self::filter_suppressed( $newly_broken );
		if ( empty( $newly_broken ) ) {
			return;
		}

		$recipient = self::recipient_for( $settings );
		if ( empty( $recipient ) ) {
			return;
		}

		$subject = self::compose_subject( count( $newly_broken ) );
		$body    = self::compose_body( $newly_broken );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $recipient, $subject, $body, $headers );

		if ( ! $sent ) {
			DR_Subs_Logger::error(
				'Daily digest wp_mail returned false',
				array(
					'recipient' => $recipient,
					'count'     => count( $newly_broken ),
				)
			);
			return;
		}

		/**
		 * Fires after a digest email is sent successfully.
		 *
		 * @since 2.0.0
		 * @param string $recipient
		 * @param array  $newly_broken_sub_ids
		 */
		do_action( 'dr_subs_digest_sent', $recipient, $newly_broken );
	}

	/**
	 * Remove sub_ids whose suppressed_until is in the future.
	 *
	 * @param array<int, int> $sub_ids
	 * @return array<int, int>
	 */
	private static function filter_suppressed( array $sub_ids ): array {
		if ( empty( $sub_ids ) ) {
			return $sub_ids;
		}
		global $wpdb;
		$table        = DR_Subs_Migration::sub_health_table();
		$placeholders = implode( ',', array_fill( 0, count( $sub_ids ), '%d' ) );
		$now          = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder count controlled; %i escapes the table identifier.
		$suppressed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sub_id FROM %i WHERE sub_id IN ({$placeholders}) AND suppressed_until IS NOT NULL AND suppressed_until > %s",
				array_merge( array( $table ), array_map( 'intval', $sub_ids ), array( $now ) )
			)
		);
		// phpcs:enable

		$suppressed = array_map( 'intval', (array) $suppressed );
		return array_values( array_diff( $sub_ids, $suppressed ) );
	}

	/**
	 * Choose the recipient email.
	 *
	 * @param array $settings
	 * @return string
	 */
	private static function recipient_for( array $settings ): string {
		$addr = sanitize_email( (string) ( $settings['alert_email'] ?? '' ) );
		if ( '' !== $addr && is_email( $addr ) ) {
			return $addr;
		}
		$fallback = (string) get_option( 'admin_email', '' );
		return is_email( $fallback ) ? $fallback : '';
	}

	/**
	 * Compose the subject line.
	 *
	 * @param int $count
	 * @return string
	 */
	private static function compose_subject( int $count ): string {
		return sprintf(
			/* translators: %d: number of affected subscriptions. */
			_n(
				'Doctor Subs: %d subscription needs attention',
				'Doctor Subs: %d subscriptions need attention',
				$count,
				'doctor-subs'
			),
			$count
		);
	}

	/**
	 * Compose the plain-text body.
	 *
	 * @param array<int, int> $sub_ids
	 * @return string
	 */
	private static function compose_body( array $sub_ids ): string {
		$site  = (string) get_bloginfo( 'name' );
		$lines = array();

		$intro = sprintf(
			/* translators: 1: site name, 2: count */
			_n(
				'%2$d subscription on %1$s started showing problems between the last scan and this one.',
				'%2$d subscriptions on %1$s started showing problems between the last scan and this one.',
				count( $sub_ids ),
				'doctor-subs'
			),
			$site,
			count( $sub_ids )
		);
		$lines[] = $intro;
		$lines[] = '';

		foreach ( $sub_ids as $sub_id ) {
			$lines[] = self::entry_line( (int) $sub_id );
		}

		$lines[] = '';
		$lines[] = __( 'Open Doctor Subs:', 'doctor-subs' );
		$lines[] = esc_url_raw( admin_url( 'admin.php?page=doctor-subs' ) );
		$lines[] = '';
		$lines[] = __( 'You received this because email alerts are on in Doctor Subs. Change settings:', 'doctor-subs' );
		$lines[] = esc_url_raw( admin_url( 'admin.php?page=doctor-subs&tab=settings' ) );

		return implode( "\n", $lines );
	}

	/**
	 * One-line digest entry for a sub.
	 *
	 * @param int $sub_id
	 * @return string
	 */
	private static function entry_line( int $sub_id ): string {
		$customer = '';
		$rule_label = '';

		if ( function_exists( 'wcs_get_subscription' ) ) {
			$sub = wcs_get_subscription( $sub_id );
			if ( $sub ) {
				$customer = (string) $sub->get_formatted_billing_full_name();
			}
		}

		global $wpdb;
		$table = DR_Subs_Migration::sub_health_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- alert composer.
		$matched_rules = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT matched_rules FROM %i WHERE sub_id = %d', $table, $sub_id )
		);
		// phpcs:enable
		if ( ! empty( $matched_rules ) ) {
			$decoded = json_decode( $matched_rules, true );
			if ( is_array( $decoded ) && ! empty( $decoded[0]['rule_id'] ) ) {
				$rule = DR_Subs_Rules_Registry::get( (string) $decoded[0]['rule_id'] );
				if ( $rule ) {
					$rule_label = $rule->label();
				}
			}
		}

		return sprintf(
			/* translators: 1: customer name, 2: sub ID, 3: rule label */
			__( '- %1$s (#%2$d) - %3$s', 'doctor-subs' ),
			$customer ?: __( '(no name)', 'doctor-subs' ),
			$sub_id,
			$rule_label ?: __( 'Issue detected', 'doctor-subs' )
		);
	}
}
