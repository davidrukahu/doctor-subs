=== Doctor Subs ===
Contributors: davidrukahu
Tags: subscription renewals, failed renewals, recurring payments, renewal, woocommerce subscriptions
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find the subscriptions that quietly stopped renewing. Fix them in bulk, with a preview before and an undo after.

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

= How big a store can it handle? =

It is built and tested for the 20 to 500 active subscription range. The scanner pages through subscriptions rather than loading them all at once, and `DR_SUBS_SCAN_BATCH_SIZE` in wp-config.php lets you lower the batch size on a constrained host.

As of 2.3.0 a bulk repair no longer runs inside one request. It is split into chunks on Action Scheduler with a saved position, so it shows progress, can be stopped, and picks up where it left off if a chunk times out. Cohort size is not the limit it used to be.

The scan itself still runs in one pass. On a very large store it can take long enough to hit a PHP time limit, in which case it reports a failure and you can re-run it; nothing is left half-fixed, because fixes are separate and explicit. Making the scan resumable the same way is next.

= Can I extend it with my own rules? =

Yes. Implement `DR_Subs_Rule_Interface` and register on the `dr_subs_register_rules` action. See the six built-in rules under `includes/rules/` for examples.

= Is HPOS supported? =

Required. Doctor Subs declares High-Performance Order Storage compatibility and requires WooCommerce 9.0 or higher.

= How is this different from WooCommerce's built-in Subscriptions Health Check tool? =

WooCommerce ships a [Subscriptions Health Check tool](https://woocommerce.com/document/woocommerce-subscriptions-health-check/) that flags two conditions: subs on manual renewal that have a valid saved payment token, and subs with a missing or overdue next-payment date. It surfaces them as a list and points you at the relevant order so you can act manually.

Doctor Subs overlaps on those two patterns (Manual-renewal drift, Ghost subscription) and adds four more the built-in tool doesn't cover: mass on-hold cascade after a product edit, stuck on-hold despite a captured Stripe renewal, repeated payment failures within 30 days, and total drift between the stored total and line items.

It also wraps every detection in a preview-before-apply modal, one-click fixes, and a per-entry undo journal. Bulk repair runs in the background in chunks, so it reports progress and resumes after a timeout rather than dying with the request. Both directions are state-guarded: a fix aborts if the subscription changed since it was detected, and an undo asks first if the subscription changed since the fix. There is also an optional email digest when something new breaks between scans.

Short version: WC's tool is a flagger. Doctor Subs is a flagger plus a reversible repair surface. Running both is fine - they don't conflict.

== Screenshots ==

1. Dashboard: X-of-Y healthy stat, At risk + Broken counters, search, a filter chip per rule, and the Needs attention table with a plain-English reason and a Fix button on every row
2. Fix preview modal: plain-English narrative, named diff (before -> after), and the "you can undo this" reassurance line
3. Fix history: per-rule filter chips along the top, plain-English summary per row, individual Revert buttons
4. Settings: alerts toggle + email recipient, fix-history retention selector, anonymous telemetry opt-in
5. Detection rules: six rule cards with on/off toggles plus Detects + Fix descriptions for every rule

== Changelog ==

= 2.3.0 =

Bulk repair that holds up on a real store, and the first tests.

* Fixed: Manual-renewal drift did not actually fix anything on stores that keep orders in the classic posts table. The rule cleared the flag correctly, then wrote a follow-up value that WooCommerce Subscriptions reads back as "manual renewal is on", undoing the repair it had just made. If you ran this fix in 2.2.0, re-scan and run it again. Stores on High-Performance Order Storage were not affected.
* Bulk fix now runs in the background in chunks instead of inside one browser request. It shows progress, can be stopped, and resumes where it stopped if a chunk times out, so the size of the cohort is no longer the limit. Everything it fixes is still one batch with one undo.
* Fixed: the Needs attention table showed at most 50 subscriptions with nothing telling you there were more. It now pages, 25 at a time.
* Fixed: the rule filter on that table was applied after the database had already cut the list to 50, so filtering could show an empty table while matching subscriptions sat below the cut, and the counts were wrong.
* Undo now checks whether the subscription changed after the fix was applied. If it did, it says what changed and asks before overwriting your edit, instead of silently rolling it back.
* Fixed: subscriptions you deleted left a health record behind forever, inflating the counters and pushing real rows off the table.
* Fixed: alert suppression never survived a scan, because each scan rewrote the health record from scratch.
* Faster: reading the scheduled-renewal index took one database query per scheduled renewal, so a store with thousands of them paid thousands of queries before any check ran. It is now a fixed handful, whatever the size of the store.
* Added a test suite: 35 tests covering the undo journal and every rule's fix-and-undo pair, run against real WooCommerce and Subscriptions. The manual-renewal bug above was found by it.

= 2.2.0 =

Fixes, honesty, and a compatibility pass.

* Fixed: the rule filters on Fix history matched only legacy rule names, so clicking any chip hid every row.
* Fixed: a bulk fix wrote one history row per subscription and each claimed "Fixed 1 subscription in one batch". Batches now collapse into a single entry with the real count and the subscription IDs, and Revert undoes the whole batch.
* Fixed: batch IDs were lowercased before lookup, so batch revert could miss on case-sensitive databases.
* Changed: email alerts are now off by default, which is what the settings screen always said. Existing sites keep whatever they have set.
* Changed: the telemetry note now lists exactly what is sent, the anonymous install ID it promised is now real, and the request identifies itself as Doctor Subs instead of imitating a browser.
* Changed: the first-run screen lists every rule that ships, read from the rule catalogue, instead of a hardcoded three.
* Changed: tested against WordPress 7.1 and WooCommerce 10.9.
* Fixed: uninstall with the purge constant set now removes every option, the scan lock, and the scheduled jobs, rather than one legacy option.
* Fixed: the scanner paged with an argument WooCommerce Subscriptions ignores, so every scan re-read the same first page up to 500 times. On a small store a scan now processes 15 subscriptions instead of 7,500, and the counts it reports are the real ones.

= 2.1.1 =

Docs only. New FAQ entry comparing Doctor Subs to WooCommerce's built-in Subscriptions Health Check tool, with link to the official WooCommerce documentation. No code changes.

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

= 2.3.0 =

Manual-renewal drift did not actually repair anything on stores using the classic posts table. If you ran that fix in 2.2.0, re-scan and run it again. Bulk fix now runs in the background with progress and resume, the subscription table pages instead of stopping at 50, and undo warns you before overwriting a change you made after the fix.

= 2.2.0 =

Fixes two bugs on the Fix history screen: the rule filters hid every row, and bulk fixes all displayed as "1 subscription". Email alerts are now off by default, matching what the settings screen has always said.

= 2.1.1 =

Docs-only release. Adds an FAQ comparing Doctor Subs to WooCommerce's new built-in Subscriptions Health Check tool. No code changes.

= 2.1.0 =

Adds three new detection rules (manual-renewal drift, mass on-hold cascade, total drift), bulk-fix for any visible row set, and a styled revert confirm with executed-payment warnings. Schema migrates automatically on first admin page load. No breaking API changes for third-party rules.

= 2.0.0-alpha.1 =

Major rewrite. Breaking class rename: WCST_* to DR_Subs_*. class_alias shims keep WCST_Plugin / WCST_Admin / WCST_Ajax_Handler working. Backup before upgrading if you have custom integrations.

= 1.2.4 =

Important security fixes and compatibility improvements. All users should upgrade.
