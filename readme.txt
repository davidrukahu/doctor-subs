=== Doctor Subs ===
Contributors: davidrukahu
Tags: woocommerce, subscriptions, troubleshooting, diagnostics, payment issues
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spot the broken WooCommerce subscriptions you didn't know about, and fix them with one click you can undo.

== Description ==

You don't always find out a renewal is broken until a customer emails you. Doctor Subs watches your subscriptions in the background and tells you when something is silently wrong, before you hear about it.

For each problem, you get a plain-English explanation, a preview of exactly what the fix will change, and one button to apply it. Every fix is logged and can be undone. If a fix already triggered a real payment, the undo screen says so explicitly.

Built for store owners who run between 20 and 500 active subscriptions and don't want to read logs or write code.

= What it spots =

* Subscriptions silently flipped to "manual renewal" by recent WooCommerce bugs, so auto-billing has quietly stopped.
* Active subscriptions with no next payment scheduled - the next charge will never happen.
* A wave of subscriptions all going on hold at once after a product change.
* Customers who paid in Stripe but whose subscription is still showing on hold.
* Subscriptions with a couple of recent failed payments - usually a gateway blip a single retry fixes.
* Subscription totals that no longer match their line items.

= What it doesn't do =

* It doesn't change anything without you clicking. There's no auto-fix.
* It doesn't replace dunning, retries, or refunds. For card declines and refunds, use your gateway.
* It doesn't send your customer data anywhere.

If you've ever found out about a broken renewal from a customer instead of from your store, this is for you.

== Installation ==

= Automatic =

1. Plugins > Add New > search "Doctor Subs"
2. Install > Activate

= Manual =

1. Download the plugin zip from WordPress.org or GitHub Releases
2. Plugins > Add New > Upload Plugin
3. Install > Activate

= After activation =

1. WooCommerce > Doctor Subs
2. Click **Scan my subscriptions**
3. Review the X-of-Y healthy stat and the two action counters, click into the broken bucket, preview a fix, commit

== Frequently Asked Questions ==

= Does Doctor Subs fix issues automatically? =

No. The plugin never mutates your data without an explicit Fix click. Every change shows a preview first and lands in the Fix history with a Revert button. The scanner only detects; the merchant decides.

= What happens if I revert a fix after the payment already ran? =

The revert confirm explicitly tells you: the re-scheduled payment has already charged the customer, reverting will undo the status change but will NOT refund. If a refund is needed, handle it in the related WooCommerce order.

= Does it really detect the silent "manual renewal" bug? =

Yes. The Manual-renewal drift rule looks for active subs whose `_requires_manual_renewal` flag is set despite a working Stripe customer/source meta on file. It clears the flag in both the orders table and postmeta (belt-and-braces against the HPOS sync gap), re-stamps `next_payment` if past-due, and schedules a fresh renewal so WCS bills automatically again.

= Is this a replacement for dunning management? =

No. Doctor Subs fixes structural issues (missing AS events, stuck-on-hold statuses, manual-renewal flag drift, line-item total drift). It does not handle card declines, SCA prompts, or retry strategy. For those, use your gateway's built-in dunning or a dedicated plugin.

= Which gateways does it support? =

v2.1: Stripe fully supported across all rules that need a gateway signal (stuck on-hold, manual-renewal drift). The remaining rules are gateway-agnostic. PayPal, Authorize.net, Square, and WooPayments variants land in a future release.

= Will it work on stores with 10,000+ subscriptions? =

Yes. The scanner uses a shared pre-built index so every rule is O(1) per sub (no N+1 queries). The `DR_SUBS_SCAN_BATCH_SIZE` constant lets you tune the batch size in wp-config.php for very large stores.

= Can I extend it with my own rules? =

Yes. Implement `DR_Subs_Rule_Interface` and register on the `dr_subs_register_rules` action. See the six built-in rules under `includes/rules/` for examples.

= Is HPOS supported? =

Required. Doctor Subs declares High-Performance Order Storage compatibility and requires WooCommerce 9.0 or higher.

== Screenshots ==

1. Dashboard: X-of-Y healthy stat, At risk + Broken counters, search, rule chips, and the Needs attention table with the "Fix all" CTA next to the active filter
2. Fix preview modal: plain-English narrative, named diff (before -> after), and the "you can undo this" reassurance line
3. Fix history: per-rule filter chips along the top, plain-English summary per row, individual Revert buttons
4. Settings: alerts toggle + email recipient, fix-history retention selector, anonymous telemetry opt-in
5. Detection rules: six rule cards with on/off toggles plus Detects + Fix descriptions for every rule

== Changelog ==

= 2.1.0 =

Major detection + UX expansion. Six rules now ship; design language tightened.

**New rules (3):**

* **Manual-renewal drift** (Stripe-only): detects active subs silently flipped to manual renewal despite a Stripe customer/source on file. Direct response to the four April 2026 subscriptions-core bug disclosures. Fix clears the flag in HPOS + postmeta, re-stamps next_payment if past-due, and schedules a fresh renewal.
* **Mass on-hold cascade**: detects 20 or more on-hold transitions for the same product within a 1-hour window. Backed by a new `dr_subs_status_transitions` log written by an observer on every subscription status change. Fix reactivates each cascade member; bulk-fix recovers the whole cascade in one click.
* **Total drift** (flag-only): detects subs whose stored total no longer matches the sum of line items + tax + shipping + fees by more than $0.50, ignoring subs modified in the last 7 days. Surfaces the discrepancy and links to the sub for manual review.

**Dashboard:**

* Healthy counter relocated as an "X of Y healthy" stat above the action counters; can no longer be mistaken for a clickable filter.
* Search bar matches sub number, customer name, and billing email. Debounced, ESC clears.
* Rule chip filters above the table; bucket counter and rule chip are now mutually exclusive (clicking one clears the other).
* Reason column strips inline HTML so emphasis tags never render literally.
* Issue column header moved to screen-reader-only; the rule pill carries the label.

**Bulk + revert:**

* Bulk-fix button beside the active rule chip OR in "All rules" mode: groups visible rows by rule, posts one batch per rule, surfaces a styled confirm modal listing the per-rule counts and a renewal-payment warning. Total Drift opts out (manual-only).
* Revert confirm now opens a styled in-plugin modal instead of `window.confirm()`. When the journal entry's AS action has already executed, the modal escalates to a danger-styled button and the explicit "this will NOT refund" warning.

**Settings:**

* New "Detection rules" section listing all six rules with toggle, plain-English Detects/Fix descriptions, and bucket tag. Disabled rules skip detection on every scan.
* Plain-English summaries on dashboard rule chips and table pills via `DR_Subs_Rule_Catalog`.

**Fix history:**

* Plain-English summary per row ("Rescheduled the missed renewal payment", "Reactivated as part of a mass-hold cascade recovery", etc.) instead of the raw `key: value` after-state dump.
* All canonical rule ids now render correct labels (legacy short ids fall back).

**Schema + scanner:**

* Schema bumped to 2.1.0; new `dr_subs_status_transitions` table with `sub_id`, `from_status`, `to_status`, `product_id`, `variation_id`, `transitioned_at`. Pruned daily on a 30-day TTL.
* Scanner now walks `active`, `on-hold`, and `pending-cancel` statuses (was `active` only). Mass-hold + Stuck-on-hold rules now reach their target subs.
* Manual_renewal_drift registered before Ghost_sub so it claims primary-rule on the same broken state with the right fix. Ghost_sub now skips manual-renewal subs entirely.

**Design + accessibility:**

* Display typeface swapped from Instrument Serif to Source Serif 4 (less editorial-romantic, calmer italic).
* Counter numerals dropped from 62px display serif to 32px Switzer 500: a 28-broken count no longer reads like a panic-amplifier.
* Dashboard hint copy de-imperative-d ("needs you now" -> "since last scan").
* All em dashes / en dashes / minus signs replaced with plain hyphens across PHP, JS, CSS, and views.
* Modal focus on open lands on the dialog itself (tabindex=-1) so the sub-id link no longer reads as "selected".
* New `.btn-danger` token using the existing terracotta `--broken` for genuinely destructive actions.

**Internal:**

* New `DR_Subs_Rule_Catalog` central source-of-truth for per-rule label, summary, detect, fix, bucket, and journal_summary copy.
* New `dev/` directory with `seed-test-data.php` and `wipe-test-data.php` (excluded from the production zip via build script + CI workflow).

= 2.0.0-alpha.1 =

Major rewrite. Single breaking change moment: every PHP class renamed from WCST_* to DR_Subs_*; legacy class_alias shims ship for the three most-likely-extended public classes.

* Traffic-light dashboard with per-bucket drill-down
* Three deterministic detection rules: Ghost Sub, On-Hold with Paid Renewal (Stripe), Repeated Payment Failures
* Daily background scanner via Action Scheduler + WP-Cron watchdog
* One-click fixes with state-guarded apply and reversible Fix journal
* Fix preview modal with named diff and executed-payment warning
* Email digest alerts (off by default; configurable recipient)
* Settings page with retention controls and anonymous telemetry opt-in
* 20 language translations via Potomatic
* Self-hosted typography, no external asset fetches
* HPOS baseline (WC 9.0+)

= 1.2.4 =

* Fixed all security issues (sanitization, validation, escaping)
* Fixed Action Scheduler compatibility (scheduled_date_gmt column)
* Improved error handling and debugging
* Enhanced analyzer stability
* Added PHPCS configuration

= 1.2.3 =

* Initial release

== Upgrade Notice ==

= 2.1.0 =

Adds three new detection rules (manual-renewal drift, mass on-hold cascade, total drift), bulk-fix for any visible row set, and a styled revert confirm with executed-payment warnings. Schema migrates automatically on first admin page load. No breaking API changes for third-party rules.

= 2.0.0-alpha.1 =

Major rewrite. Breaking class rename: WCST_* to DR_Subs_*. class_alias shims keep WCST_Plugin / WCST_Admin / WCST_Ajax_Handler working. Backup before upgrading if you have custom integrations.

= 1.2.4 =

Important security fixes and compatibility improvements. All users should upgrade.
