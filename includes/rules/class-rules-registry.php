<?php
/**
 * Rules registry.
 *
 * Central registrar for DR_Subs_Rule_Interface implementations. The
 * scanner walks all registered rules on each pass. Third-party plugins
 * can register their own rules via the `dr_subs_register_rules` action.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static rules registry.
 *
 * @since 2.0.0
 */
class DR_Subs_Rules_Registry {

	/**
	 * Registered rules, indexed by their id().
	 *
	 * @var array<string, DR_Subs_Rule_Interface>
	 */
	private static array $rules = array();

	/**
	 * True once bootstrap() has been called at least once in this request.
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Register a rule.
	 *
	 * Calling with the same rule id twice replaces the earlier instance
	 * (last-registration-wins, allowing third parties to override core
	 * rules if they really mean it).
	 *
	 * @param DR_Subs_Rule_Interface $rule
	 * @return void
	 */
	public static function register( DR_Subs_Rule_Interface $rule ): void {
		self::$rules[ $rule->id() ] = $rule;
	}

	/**
	 * Look up a rule by id.
	 *
	 * @param string $rule_id
	 * @return DR_Subs_Rule_Interface|null
	 */
	public static function get( string $rule_id ): ?DR_Subs_Rule_Interface {
		self::bootstrap();
		return self::$rules[ $rule_id ] ?? null;
	}

	/**
	 * All registered rules in registration order.
	 *
	 * @return array<string, DR_Subs_Rule_Interface>
	 */
	public static function all(): array {
		self::bootstrap();
		return self::$rules;
	}

	/**
	 * Register core v1 rules + fire the third-party hook exactly once
	 * per request.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		$core = array(
			// Manual-renewal-drift runs BEFORE ghost_sub so that subs flipped
			// to manual by the X-disclosure bugs match here (with the right
			// fix) instead of getting the ghost-sub fix that doesn't address
			// root cause.
			'DR_Subs_Rule_Manual_Renewal_Drift',
			'DR_Subs_Rule_Ghost_Sub',
			'DR_Subs_Rule_On_Hold_Paid',
			'DR_Subs_Rule_Repeated_Failures',
			'DR_Subs_Rule_Mass_Hold',
			'DR_Subs_Rule_Total_Drift',
		);

		foreach ( $core as $cls ) {
			if ( class_exists( $cls ) ) {
				self::register( new $cls() );
			}
		}

		/**
		 * Fires after core rules are registered.
		 *
		 * Third-party plugins listen to this hook and register their own
		 * DR_Subs_Rule_Interface implementations via
		 * DR_Subs_Rules_Registry::register().
		 *
		 * Example:
		 *
		 *     add_action( 'dr_subs_register_rules', function () {
		 *         DR_Subs_Rules_Registry::register( new My_Custom_Rule() );
		 *     } );
		 *
		 * @since 2.0.0
		 */
		do_action( 'dr_subs_register_rules' );
	}

	/**
	 * Reset the registry. Testing aid; not intended for production use.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rules        = array();
		self::$bootstrapped = false;
	}
}
