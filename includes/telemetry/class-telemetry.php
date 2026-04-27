<?php
/**
 * Anonymous usage telemetry via Umami Cloud.
 *
 * Fires custom events when a merchant applies or reverts a fix. Off by
 * default; merchant opts in via Settings > "Send anonymous fix stats".
 *
 * What we send:
 *   - Event name ('fix_applied', 'fix_reverted', 'bulk_fix')
 *   - Rule id ('ghost_sub', 'onhold_paid', 'repfail')
 *   - Plugin / WP / PHP version strings
 *
 * What we NEVER send:
 *   - Customer info, order ids, subscription ids
 *   - Site URL, admin email, user id
 *   - Any meta, totals, or payment data
 *
 * Self-hosters can override the endpoint and site id via:
 *   - define( 'DR_SUBS_TELEMETRY_ENDPOINT', 'https://my-umami.example.com/api/send' );
 *   - define( 'DR_SUBS_TELEMETRY_SITE_ID',  '...uuid...' );
 * or the `dr_subs_telemetry_endpoint` / `dr_subs_telemetry_site_id` filters.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telemetry dispatcher.
 *
 * @since 2.0.0
 */
class DR_Subs_Telemetry {

	/**
	 * Default Umami Cloud endpoint for server-side custom events.
	 */
	const ENDPOINT_DEFAULT = 'https://cloud.umami.is/api/send';

	/**
	 * Default Umami site id - the Doctor Subs aggregation bucket.
	 */
	const SITE_ID_DEFAULT = '100ee098-1f8c-45fa-be0d-60349fede96a';

	/**
	 * Synthetic hostname. Not a real domain; just a stable key Umami uses
	 * to group events from every plugin install.
	 */
	const HOSTNAME = 'doctor-subs.plugin';

	/**
	 * Register action hooks. Called once from DR_Subs_Plugin::init_hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'dr_subs_after_fix_apply', array( __CLASS__, 'on_fix_apply' ), 10, 4 );
		add_action( 'dr_subs_after_fix_revert', array( __CLASS__, 'on_fix_revert' ), 10, 3 );
		add_action( 'dr_subs_after_bulk_fix', array( __CLASS__, 'on_bulk_fix' ), 10, 4 );
	}

	/**
	 * Hooked to dr_subs_after_fix_apply.
	 *
	 * @param int    $entry_id Journal entry id.
	 * @param int    $sub_id   Subscription id (NOT sent).
	 * @param string $rule_id  Rule id.
	 * @param array  $payload  Apply payload (NOT sent).
	 * @return void
	 */
	public static function on_fix_apply( $entry_id, $sub_id, $rule_id, $payload ): void {
		self::send( 'fix_applied', array( 'rule' => (string) $rule_id ) );
	}

	/**
	 * Hooked to dr_subs_after_fix_revert.
	 *
	 * @param int    $entry_id
	 * @param int    $sub_id
	 * @param string $rule_id
	 * @return void
	 */
	public static function on_fix_revert( $entry_id, $sub_id, $rule_id ): void {
		self::send( 'fix_reverted', array( 'rule' => (string) $rule_id ) );
	}

	/**
	 * Hooked to dr_subs_after_bulk_fix. Fires once per batch regardless
	 * of how many individual fixes it covered, plus a 'count' prop so
	 * we see batch sizes without leaking which subs were touched.
	 *
	 * @param string $batch_id (NOT sent)
	 * @param string $rule_id
	 * @param int    $applied
	 * @param int    $failed
	 * @return void
	 */
	public static function on_bulk_fix( $batch_id, $rule_id, $applied, $failed ): void {
		self::send(
			'bulk_fix',
			array(
				'rule'    => (string) $rule_id,
				'applied' => (int) $applied,
				'failed'  => (int) $failed,
			)
		);
	}

	/**
	 * Is telemetry opted in?
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		$settings = get_option( 'dr_subs_settings', array() );
		return ! empty( $settings['telemetry_enabled'] );
	}

	/**
	 * Build and POST an Umami event. Non-blocking - fires and forgets so
	 * it never slows down the fix flow the merchant is actually trying
	 * to complete.
	 *
	 * @param string $name  Event name.
	 * @param array  $props Custom event properties. rule, applied, etc.
	 * @return void
	 */
	private static function send( string $name, array $props = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$endpoint = defined( 'DR_SUBS_TELEMETRY_ENDPOINT' ) ? (string) DR_SUBS_TELEMETRY_ENDPOINT : self::ENDPOINT_DEFAULT;
		$site_id  = defined( 'DR_SUBS_TELEMETRY_SITE_ID' ) ? (string) DR_SUBS_TELEMETRY_SITE_ID : self::SITE_ID_DEFAULT;

		$endpoint = (string) apply_filters( 'dr_subs_telemetry_endpoint', $endpoint, $name );
		$site_id  = (string) apply_filters( 'dr_subs_telemetry_site_id', $site_id, $name );

		if ( empty( $endpoint ) || empty( $site_id ) ) {
			return;
		}

		$data = array_merge(
			$props,
			array(
				'plugin_version' => defined( 'DR_SUBS_PLUGIN_VERSION' ) ? DR_SUBS_PLUGIN_VERSION : 'unknown',
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
			)
		);

		$body = wp_json_encode(
			array(
				'type'    => 'event',
				'payload' => array(
					'website'  => $site_id,
					'hostname' => self::HOSTNAME,
					'language' => 'en',
					'screen'   => '1920x1080',
					'url'      => '/events/' . $name,
					'referrer' => '',
					'title'    => $name,
					'name'     => $name,
					'data'     => $data,
				),
			)
		);

		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 2,
				'blocking'    => false,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					// Umami drops non-browser User-Agents as bots (returns
					// {"beep":"boop"}). Use a browser-shaped UA so events
					// register. We still identify the source via the
					// custom 'plugin_version' prop in the payload.
					'User-Agent'   => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
				),
				'body'        => $body,
			)
		);
	}
}
