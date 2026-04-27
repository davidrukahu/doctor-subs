# Doctor Subs

[![Latest Release](https://img.shields.io/github/v/release/davidrukahu/doctor-subs)](https://github.com/davidrukahu/doctor-subs/releases)
[![WordPress.org](https://img.shields.io/wordpress/plugin/v/doctor-subs)](https://wordpress.org/plugins/doctor-subs/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)

> Find and fix broken WooCommerce subscriptions. One-click reversible fixes for the six most common renewal failure patterns.

Doctor Subs is a focused diagnostic tool for WooCommerce Subscriptions. It runs a daily background scan across every active and on-hold subscription, classifies each one as **healthy / at risk / broken**, and gives you a one-click preview + fix + undo for the patterns that silently kill recurring revenue.

Built for solo, non-technical store owners with 20-500 active subscriptions. No log-reading, no WP-CLI, no support tickets.

## What it detects

| Rule | Bucket | What it catches |
|---|---|---|
| **Manual-renewal drift** | broken | Active subs silently flipped to "manual renewal" despite a working Stripe card on file. Direct response to the four April 2026 subscriptions-core bug disclosures (stale dates cache, HPOS↔postmeta sync gap, `wcs_create_subscription` state discard, same-gateway switch). |
| **Ghost subscription** | broken | Active sub whose next payment was supposed to fire but WordPress never scheduled the renewal event. Silent revenue loss. |
| **Mass on-hold cascade** | broken | 20+ subs sharing the same product transitioning to on-hold within an hour. Symptom of a product-edit cascade or faulty bulk operation. |
| **Stuck on-hold** | broken | Sub whose latest Stripe renewal captured successfully but the status never flipped back to active. Customer paid, store shows them as delinquent. |
| **Repeated payment failures** | risk | 2+ failed scheduled payment attempts in 30 days. Often a gateway blip a single retry fixes. |
| **Total drift** | risk (flag-only) | Stored subscription total drifted from the sum of line items by more than $0.50. Manual review only - drift causes are too varied to safely auto-correct. |

Disable any rule individually in **Settings → Detection rules**.

## What it does about them

Every detected problem gets:

1. A plain-English explanation of what happened (no jargon)
2. A preview modal showing exactly which fields will change before you commit
3. A one-click fix that WordPress handles for you
4. A journal entry you can undo at any time
5. An explicit warning if reverting cannot undo something that already ran (e.g. a re-scheduled payment already charged)

All fixes are **state-guarded**: if the subscription changed between detection and apply-time, the plugin aborts and asks you to re-scan. You never get surprised.

## Dashboard + alerts

- **Calm-clinical surface**: "X of Y healthy" stat plus two action counters (At risk / Broken). Click any counter, search by sub#/customer/email, filter by rule chip.
- **Bulk fix**: "Fix all N matches" across one rule or every visible row. Each fix lands as its own reversible journal entry. Total Drift opts out (manual-only).
- **Daily background scan** via Action Scheduler with WP-Cron watchdog.
- **Email digest** when something new breaks between scans (off by default).
- **Fix history** with per-entry Revert. Configurable retention (30 days - forever).
- **Per-rule on/off** in Settings.

## Install

### From WordPress.org

```
Plugins → Add New → search "Doctor Subs" → Install → Activate
```

### From GitHub Releases

1. Download `doctor-subs-X.Y.Z.zip` from the [latest release](https://github.com/davidrukahu/doctor-subs/releases/latest)
2. **Plugins → Add New → Upload Plugin**
3. Install → Activate

### After activation

1. **WooCommerce → Doctor Subs**
2. Click **Scan my subscriptions**
3. Review the dashboard, click into the broken bucket, preview a fix, commit

## Requirements

- WordPress 6.4+
- PHP 7.4+
- WooCommerce 9.0+ (HPOS-compatible; HPOS recommended)
- WooCommerce Subscriptions

## Architecture

- **`includes/rules/`** - one class per detection rule, all implement `DR_Subs_Rule_Interface`
- **`includes/scanner/`** - `DR_Subs_Health_Scanner` walks subs in batches; `DR_Subs_Scan_Context` builds shared O(1) indexes so rules never do N+1 queries
- **`includes/journal/`** - `DR_Subs_Fix_Journal` records every applied fix with full revert metadata
- **`includes/observers/`** - `DR_Subs_Status_Transition_Log` writes to `dr_subs_status_transitions` for cascade detection
- **`includes/migration/`** - `DR_Subs_Migration` owns schema versioning + dbDelta on activate / in-place upgrade

## Extending

Implement your own rule by registering a class on the `dr_subs_register_rules` action:

```php
add_action( 'dr_subs_register_rules', function () {
    DR_Subs_Rules_Registry::register( new My_Custom_Rule() );
} );
```

Your class implements `DR_Subs_Rule_Interface`: `id()`, `label()`, `bucket()`, `tracked_fields()`, `detect_batch()`, `preview_fix()`, `apply_fix()`, `revert_fix()`, `narrate()`. See `includes/rules/class-rule-ghost-sub.php` for the canonical example.

## Privacy

- Zero external asset fetches at runtime. All fonts, CSS, JS bundled.
- No data leaves your site unless you explicitly opt in to anonymous fix telemetry (off by default; rule name + timestamp only, no customer data).

## Development

```bash
# Install dev deps (PHPCS + WordPress coding standards)
composer install

# Run PHPCS
vendor/bin/phpcs

# Auto-fix what's auto-fixable
vendor/bin/phpcbf

# Build a production zip locally
./scripts/build-zip.sh

# Seed test data on a Local-by-Flywheel install (NOT production)
wp eval-file wp-content/plugins/doctor-subs/dev/seed-test-data.php
wp eval "DR_Subs_Health_Scanner::run_recurring();"

# Wipe local test data
wp eval-file wp-content/plugins/doctor-subs/dev/wipe-test-data.php
```

## Releasing

Tagging `vX.Y.Z` on `main` triggers `.github/workflows/release.yml`:

1. Builds the production zip (excludes `dev/`, `scripts/`, `design-brief/`, etc. via `.distignore`)
2. Drafts a GitHub Release with the zip attached
3. On stable tags only (no alpha/beta/rc), deploys to WP.org SVN trunk + `tags/X.Y.Z` via [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)

## License

GPLv2 or later. See [LICENSE](LICENSE).
