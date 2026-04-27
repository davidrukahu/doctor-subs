<?php
/**
 * Rule interface - every detection rule implements this.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a detection + fix rule.
 *
 * A rule is responsible for:
 *  - deciding when a subscription matches a broken/at-risk pattern
 *    (detect_batch, using pre-built indexes from DR_Subs_Scan_Context
 *    so it never does N+1 queries);
 *  - previewing the fix it would apply, including a named diff and an
 *    already-executed warning when applicable;
 *  - applying the fix behind a state guard (the apply aborts if the
 *    tracked fields have drifted since detection);
 *  - reverting a previously-applied fix using its journal entry;
 *  - producing a plain-English narration string for the match
 *    (template-based, no LLM calls).
 *
 * @since 2.0.0
 */
interface DR_Subs_Rule_Interface {

	/**
	 * Stable machine-readable ID. Used as the primary key in
	 * dr_subs_fix_journal.rule_id and in telemetry pings.
	 *
	 * @return string  e.g. 'ghost_sub', 'onhold_paid', 'repeated_failures'
	 */
	public function id(): string;

	/**
	 * Short UI label for pills, counter filters, and headings.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Which bucket a match lands in when categorised in dr_subs_sub_health.
	 *
	 * @return string  'broken' | 'risk' | 'healthy'
	 */
	public function bucket(): string;

	/**
	 * Allow-list of WC_Subscription-related fields whose stability the
	 * rule depends on. The apply-fix state guard hashes these fields at
	 * detection and refuses to apply if any has changed by the time the
	 * merchant clicks Fix.
	 *
	 * Immune to future WCS versions adding unrelated derived properties.
	 *
	 * @return array<int, string>  e.g. ['status', 'next_payment']
	 */
	public function tracked_fields(): array;

	/**
	 * Detect this rule across a batch of subs.
	 *
	 * MUST be O(1) per sub: use the pre-built indexes on
	 * DR_Subs_Scan_Context (pending AS events, failed AS events,
	 * related orders) rather than issuing per-sub queries.
	 *
	 * @param array<int, int>      $sub_ids  Subscription IDs to inspect.
	 * @param DR_Subs_Scan_Context $context  Shared scan indexes.
	 * @return array<int, DR_Subs_Rule_Match>
	 */
	public function detect_batch( array $sub_ids, DR_Subs_Scan_Context $context ): array;

	/**
	 * Build a preview payload for the fix modal.
	 *
	 * Returns an array with:
	 *  - 'narrative'        string  Plain-English one-paragraph summary.
	 *  - 'diff'             array   List of ['field', 'before', 'after', 'emph'?, 'unchanged'?]
	 *  - 'already_executed' bool    True if the rule's side-effect has
	 *                               already run (e.g., re-enqueued AS
	 *                               payment executed); triggers the
	 *                               executed-payment warning in the
	 *                               modal.
	 *
	 * @param DR_Subs_Rule_Match $match
	 * @return array
	 */
	public function preview_fix( DR_Subs_Rule_Match $match ): array;

	/**
	 * Apply the fix.
	 *
	 * Throws RuntimeException if the state guard rejects the apply
	 * (tracked fields moved between detection and now) or if the fix
	 * operation fails mid-way.
	 *
	 * Returns a payload the journal records verbatim:
	 *  - 'before_state'      array  Subset of tracked_fields at apply time.
	 *  - 'before_state_hash' string sha256 of the before_state for future revert guard.
	 *  - 'after_state'       array  Same fields post-apply.
	 *  - 'side_effects'      array  Ordered list of side-effect records
	 *                               for revert (see below).
	 *
	 * Side-effect record shapes:
	 *  ['type' => 'as_action',       'hook' => str, 'args' => array, 'id' => int]
	 *  ['type' => 'order_status',    'order_id' => int, 'from' => str, 'to' => str]
	 *  ['type' => 'sub_status',      'sub_id' => int, 'from' => str, 'to' => str]
	 *  ['type' => 'sub_meta',        'sub_id' => int, 'key' => str, 'from' => mixed, 'to' => mixed]
	 *
	 * @param DR_Subs_Rule_Match $match
	 * @return array
	 * @throws RuntimeException
	 */
	public function apply_fix( DR_Subs_Rule_Match $match ): array;

	/**
	 * Revert a previously-applied fix.
	 *
	 * Walks $entry->side_effects in reverse order. For AS action
	 * side-effects, checks whether the action has already executed -
	 * if so, the side_effect is flagged as "already executed" in the
	 * returned message so the UX can surface the warning.
	 *
	 * @param object $entry  Row from dr_subs_fix_journal.
	 * @return array  ['success' => bool, 'message' => str, 'already_executed' => bool, 'drift' => array]
	 */
	public function revert_fix( $entry ): array;

	/**
	 * Plain-English narration for this match.
	 *
	 * Template-based only in v1 - no LLM calls. Selects one of the
	 * rule's template variants (3-5 per rule) based on match context
	 * and substitutes variables (sub_id, customer name, dates).
	 *
	 * @param DR_Subs_Rule_Match $match
	 * @return string
	 */
	public function narrate( DR_Subs_Rule_Match $match ): string;
}
