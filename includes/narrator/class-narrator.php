<?php
/**
 * Narrator.
 *
 * Thin facade that wraps the rule's own narrate() with the
 * `dr_subs_narration` filter hook so third-party plugins can decorate
 * or replace narration without subclassing rules.
 *
 * v1 is template-only - no LLM calls. The rule owns its copy variants;
 * this class exists to give the decoration point a stable, documented
 * entry point and to centralise the "customer first-name fallback"
 * helper used by multiple rules.
 *
 * v1.1 will add AI-backed narration providers (BYOK Anthropic, Jetpack
 * AI) that plug in here without changing rule code.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrator facade.
 *
 * @since 2.0.0
 */
class DR_Subs_Narrator {

	/**
	 * Produce the final narration string for a match.
	 *
	 * Calls the rule's own narrate(), then runs it through the public
	 * `dr_subs_narration` filter so downstream plugins can edit, replace,
	 * or translate it.
	 *
	 * @param DR_Subs_Rule_Interface $rule
	 * @param DR_Subs_Rule_Match     $match
	 * @return string
	 */
	public static function for_match( DR_Subs_Rule_Interface $rule, DR_Subs_Rule_Match $match ): string {
		try {
			$raw = $rule->narrate( $match );
		} catch ( \Throwable $t ) {
			// Never let a narrator exception break scanning or preview.
			if ( class_exists( 'DR_Subs_Logger' ) ) {
				DR_Subs_Logger::error(
					'Narrator failed for rule ' . $rule->id(),
					array(
						'error'   => $t->getMessage(),
						'sub_id'  => $match->sub_id,
					)
				);
			}
			$raw = '';
		}

		/**
		 * Filter the narration string for a rule match.
		 *
		 * @since 2.0.0
		 *
		 * @param string                 $text  The rule's narrated copy.
		 * @param DR_Subs_Rule_Match     $match The match being narrated.
		 * @param DR_Subs_Rule_Interface $rule  The rule instance.
		 */
		return (string) apply_filters( 'dr_subs_narration', $raw, $match, $rule );
	}

	/**
	 * Customer first-name helper with a "This customer" fallback when
	 * the subscription has no billing-first-name set (common on
	 * fresh-imported or guest-checkout subs).
	 *
	 * Rules call this instead of reaching into WC_Subscription directly
	 * so the fallback copy stays consistent across all narration and
	 * stays translatable.
	 *
	 * @param int $sub_id
	 * @return string
	 */
	public static function customer_first_name( int $sub_id ): string {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return __( 'This customer', 'doctor-subs' );
		}
		$sub = wcs_get_subscription( $sub_id );
		if ( ! $sub ) {
			return __( 'This customer', 'doctor-subs' );
		}
		$first = trim( (string) $sub->get_billing_first_name() );
		if ( '' === $first ) {
			$first = trim( (string) $sub->get_billing_last_name() );
		}
		return '' !== $first ? $first : __( 'This customer', 'doctor-subs' );
	}

	/**
	 * Format a Unix timestamp for narration copy.
	 *
	 * Uses the site timezone via wp_date() so translations see the
	 * merchant's local time, not UTC.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string e.g. "Mar 15 at 09:00"
	 */
	public static function format_date( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}
		return (string) wp_date( 'M j \a\t H:i', $timestamp );
	}
}
