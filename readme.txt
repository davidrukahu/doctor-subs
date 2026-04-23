=== Doctor Subs ===
Contributors: davidrukahu
Tags: woocommerce, subscriptions, troubleshooting, diagnostics, payment issues
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0-alpha.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and fix broken WooCommerce subscriptions: ghost subs, stuck on-hold renewals, and repeated payment failures. One-click reversible fixes.

== Description ==

Doctor Subs v2 is a focused diagnostic and fix tool for WooCommerce Subscriptions. It runs a background scan across every active subscription on your store, classifies each into healthy / at-risk / broken, and gives you a one-click preview + fix + undo for the three most common renewal failures.

Built for solo, non-technical store owners with 20 to 500 active subscriptions. No log-reading, no WP-CLI, no support tickets.

= What it detects =

* **Ghost subscriptions** - active subs whose next payment was supposed to fire but WordPress never scheduled the renewal event. Silent revenue loss.
* **Stuck on-hold** - subs whose latest Stripe renewal actually captured successfully but the status never flipped back to active. Customer paid, your store shows them as delinquent.
* **Repeated payment failures** - 2+ failed scheduled payment attempts in the last 30 days. Often a gateway blip that a single retry fixes.

= What it does about them =

Every detected problem gets:

1. A plain-English explanation of what happened (no jargon)
2. A preview modal showing exactly which fields will change before you commit
3. A one-click fix that WordPress handles for you
4. A journal entry you can undo at any time
5. An explicit warning if reverting the fix cannot undo something that already ran (e.g. a re-scheduled payment already charged)

All fixes are state-guarded: if the subscription changed between detection and when you click Fix, the plugin aborts and asks you to re-scan. You never get surprised.

= Dashboard + alerts =

* **Traffic-light dashboard** - three counters (Healthy / At risk / Broken) you can glance at in 5 seconds. Click any counter to drill into the affected subs.
* **Daily background scan** - Action Scheduler runs the scan automatically. A WP-Cron watchdog catches the rare case when AS stops firing.
* **Email digest** - when something new breaks between scans, you get a plain-text summary email.
* **Bulk fix** - "Fix all N ghost subs" across the whole store in one click (all reversible atomically).
* **Fix history** - every applied fix, with Revert on each. Configurable retention (30-365 days or forever).

= Modern design system =

Self-hosted typography (Switzer, Instrument Serif, JetBrains Mono), OKLCH colour tokens, full responsive + print stylesheets, WCAG 2.1 AA contrast, `prefers-reduced-motion` respected. Scoped to Doctor Subs - never leaks into the rest of WP admin.

= Translations =

Ships in 20 major languages: French, Spanish, German, Italian, Portuguese (BR + PT), Dutch, Polish, Russian, Japanese, Chinese (Simplified + Traditional), Korean, Arabic, Turkish, Swedish, Norwegian, Danish, Finnish, Czech.

= Privacy =

* Zero external asset fetches at runtime. Fonts, CSS, JS all bundled.
* No data leaves your site unless you explicitly opt in to anonymous fix telemetry (off by default; rule name + timestamp only, no customer data).

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
3. Review the traffic-light counters, click into the broken bucket, preview a fix, commit

== Frequently Asked Questions ==

= Does Doctor Subs fix issues automatically? =

No. The plugin never mutates your data without an explicit Fix click. Every change shows a preview first and lands in the Fix history with a Revert button. The scanner only detects; the merchant decides.

= What happens if I revert a fix after the payment already ran? =

The modal explicitly tells you: the re-scheduled payment has already charged the customer, reverting only removes the fix from your history. If a refund is needed, handle it in the related WooCommerce order.

= Is this a replacement for dunning management? =

No. Doctor Subs fixes structural issues (missing AS events, stuck-on-hold statuses). It does not handle card declines, SCA prompts, or retry strategy. For those, use your gateway's built-in dunning or a dedicated plugin.

= Which gateways does it support? =

v2.0: Stripe fully supported for all three rules. PayPal, Authorize.net, Square, and WooPayments variants land in v1.1.

= Will it work on stores with 10,000+ subscriptions? =

Yes. The scanner uses a shared pre-built index so every rule is O(1) per sub (no N+1 queries). The `DR_SUBS_SCAN_BATCH_SIZE` constant lets you tune the batch size in wp-config.php for very large stores.

= Can I extend it with my own rules? =

Yes. Implement `DR_Subs_Rule_Interface` and register on the `dr_subs_register_rules` action. See the three built-in rules under `includes/rules/` for examples.

= Is HPOS supported? =

Required. Doctor Subs v2 declares High-Performance Order Storage compatibility and requires WooCommerce 9.0+.

== Screenshots ==

1. Traffic-light dashboard with three counters and the Needs attention table
2. Fix preview modal showing the named diff and plain-English narrative
3. Fix history with individual + batch entries and per-entry Revert
4. Settings page (alerts, retention, telemetry opt-in)
5. First-run landing page with three-rule explainer
6. Executed-payment warning in the revert modal

== Changelog ==

= 2.0.0-alpha.1 =

Major rewrite. Single breaking change moment: every PHP class renamed from WCST_* to DR_Subs_*; legacy class_alias shims ship for the three most-likely-extended public classes. Detailed notes:

**New:**

* Traffic-light dashboard (Healthy / At risk / Broken) with per-bucket drill-down
* Three deterministic detection rules: Ghost Sub, On-Hold with Paid Renewal (Stripe), Repeated Payment Failures
* Daily background scanner via Action Scheduler + WP-Cron watchdog
* One-click fixes with state-guarded apply and reversible Fix journal
* Fix preview modal with named diff and executed-payment warning
* Bulk "Fix all N ghost subs" action
* Email digest alerts (off by default; configurable recipient)
* Settings page with retention controls and anonymous telemetry opt-in
* 20 language translations via Potomatic
* Self-hosted typography (Switzer, Instrument Serif, JetBrains Mono) - no external asset fetches
* Full responsive + print + reduced-motion support
* HPOS baseline (WC 9.0+)

**Changed:**

* All PHP classes: WCST_* -> DR_Subs_*. class_alias shims for DR_Subs_Plugin, DR_Subs_Admin, DR_Subs_Ajax_Handler.
* All hook/filter/nonce prefixes: wcst_ -> dr_subs_
* WooCommerce minimum version: 9.0 (was 9.8.5)
* The "Doctor Subs" link on the subscription row now opens the dashboard instead of an auto-analyze flow.

**Fixed:**

* Double spl_autoload_register registration in the main plugin file
* args LIKE '%sub_id%' false-positive where sub ID 1 matched sub IDs 11, 111, 123 etc.
* Month-is-30-days billing math that flagged every February as "skipped cycle" on monthly subs
* set_time_limit(30) masking slow analyzer code in v1's AJAX path

**Removed:**

* The v1 3-step analyzer UI (Anatomy / Expected / Timeline tabs). The existing v1 analyzer classes stay in includes/analyzers/ as reference but are no longer wired. Future releases may surface them behind a Per-sub "Investigate" drill-down.

= 1.2.4 =

* Fixed all security issues (sanitization, validation, escaping)
* Fixed Action Scheduler compatibility (scheduled_date_gmt column)
* Improved error handling and debugging
* Enhanced analyzer stability
* Added PHPCS configuration

= 1.2.3 =

* Initial release

== Upgrade Notice ==

= 2.0.0-alpha.1 =

Major rewrite. Breaking class rename: WCST_* to DR_Subs_*. class_alias shims keep WCST_Plugin / WCST_Admin / WCST_Ajax_Handler working. All other identifiers (hooks, filters, nonces) follow the new prefix without shims. Backup before upgrading if you have custom integrations.

= 1.2.4 =

Important security fixes and compatibility improvements. All users should upgrade.
