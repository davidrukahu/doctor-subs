<?php
/**
 * Rule match DTO.
 *
 * Lightweight value object returned by a rule's detect_batch(). Carries
 * the rule's detected context forward into preview/apply/narrate without
 * any of them needing to re-query the data.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule match value object.
 *
 * @since 2.0.0
 */
class DR_Subs_Rule_Match {

	/**
	 * Stable rule ID this match belongs to (e.g. 'ghost_sub').
	 *
	 * @var string
	 */
	public string $rule_id;

	/**
	 * Subscription ID this match is about.
	 *
	 * @var int
	 */
	public int $sub_id;

	/**
	 * Bucket classification ('broken' | 'risk' | 'healthy'). Usually
	 * mirrors the rule's own bucket() but rules may downgrade their own
	 * matches based on detected specifics (e.g. repeated-failures might
	 * land a single match as 'risk' instead of 'broken').
	 *
	 * @var string
	 */
	public string $bucket;

	/**
	 * Rule-specific detected context. Shape is entirely up to the rule.
	 * Serialised into dr_subs_sub_health.matched_rules JSON.
	 *
	 * Examples:
	 *  Ghost sub:   ['expected_payment_ts' => int, 'tracked_fields_snapshot' => array]
	 *  OnHold:      ['renewal_order_id' => int, 'stripe_charge_id' => str]
	 *  Repeated:    ['failed_action_ids' => [int, ...], 'last_error' => str]
	 *
	 * @var array<string, mixed>
	 */
	public array $context = array();

	/**
	 * Optional cached narration produced by the rule's narrate().
	 * Populated at scan time by the scanner and persisted on
	 * dr_subs_sub_health.narration.
	 *
	 * @var string|null
	 */
	public ?string $narration = null;

	/**
	 * Constructor.
	 *
	 * @param string $rule_id
	 * @param int    $sub_id
	 * @param string $bucket  'broken' | 'risk' | 'healthy'
	 * @param array  $context Rule-specific detected payload.
	 */
	public function __construct( string $rule_id, int $sub_id, string $bucket, array $context = array() ) {
		$this->rule_id = $rule_id;
		$this->sub_id  = $sub_id;
		$this->bucket  = $bucket;
		$this->context = $context;
	}

	/**
	 * Hydrate from a JSON-decoded array (the shape stored on sub_health).
	 *
	 * @param array $data
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$match            = new self(
			(string) ( $data['rule_id'] ?? '' ),
			(int) ( $data['sub_id'] ?? 0 ),
			(string) ( $data['bucket'] ?? 'broken' ),
			(array) ( $data['context'] ?? array() )
		);
		$match->narration = isset( $data['narration'] ) ? (string) $data['narration'] : null;
		return $match;
	}

	/**
	 * Serialise to the JSON-friendly array shape persisted on sub_health.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'rule_id'   => $this->rule_id,
			'sub_id'    => $this->sub_id,
			'bucket'    => $this->bucket,
			'context'   => $this->context,
			'narration' => $this->narration,
		);
	}

	/**
	 * Hash the tracked-field snapshot in context for use as the
	 * before_state_hash in the fix journal's state guard.
	 *
	 * @param array $before_state Values of the rule's tracked_fields().
	 * @return string 64-char hex sha256.
	 */
	public static function hash_state( array $before_state ): string {
		// Normalize: sort keys so encode is stable across insertion orders.
		ksort( $before_state );
		return hash( 'sha256', (string) wp_json_encode( $before_state ) );
	}
}
