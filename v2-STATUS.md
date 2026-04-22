# Doctor Subs v2.0.0-alpha.1 — Morning Handoff

**Branch:** `v2-build` (16 commits ahead of `main`)
**Status:** All 15 autopilot tasks complete. Ready for local smoke test + decision to merge/tag.

## Commits (chronological)

| # | Commit  | Task                                                            |
|---|---------|-----------------------------------------------------------------|
| 0 | 97105af | baseline — design system, PRD, taskmaster scope                 |
| 1 | f8d1ca4 | T1  rename WCST_ → DR_Subs_, fix autoloader double-register     |
| 2 | 87ec7a2 | T2  migration class — custom tables + settings defaults         |
| 3 | 2627929 | T3  move v2 design assets into `admin/`, rewire enqueue         |
| 4 | c0d2919 | T4  tab-based admin router + data wiring                        |
| 5 | bff54ac | T5  rules engine — interface, registry, `RuleMatch`, `ScanContext` |
| 6 | 5ffd79c | T6  Ghost Sub rule — detect + fix + revert + narrate            |
| 7 | e88a86e | T7  On-Hold Paid rule (Stripe v1)                               |
| 8 | 5dbcbad | T8  Repeated Failures rule — one-shot retry                     |
| 9 | b9aebe6 | T9  health scanner + fix journal + wiring                       |
| 10| e9a2f72 | T10 narrator facade + `dr_subs_narration` filter                |
| 11| d167f6c | T11 AJAX handlers — 8 endpoints                                 |
| 12| e74d945 | T12 email alerts — daily digest                                 |
| 13| 10fa612 | T13 bulk fix + batch revert                                     |
| 14| 987c5ff | T14 translations — POT + Potomatic pipeline                     |
| 15| 672a933 | T15 release pipeline — readme.txt, CI workflow, build script    |

## What shipped (code)

- **Bootstrap**: `doctor-subs.php` bumped to 2.0.0-alpha.1, unified dual-prefix autoloader (DR_Subs_ + WCST_ back-compat), textdomain loader, activation calls `DR_Subs_Migration::activate()`.
- **Migration**: `includes/migration/class-migration.php` creates `dr_subs_sub_health` + `dr_subs_fix_journal` tables via `dbDelta`, migrates legacy `wcst_settings` → `dr_subs_settings` with sane defaults.
- **Admin**: `includes/class-admin.php` — tab router (`?tab=history|settings|` → first-run or dashboard), data helpers for health counts/attention rows/journal.
- **Rules engine**:
  - `DR_Subs_Rule_Interface` — `detect_batch`, `preview_fix`, `apply_fix`, `revert_fix`, `narrate`, `tracked_fields`
  - `DR_Subs_Rule_Match` DTO with `hash_state()` (sha256, ksort'd) for drift guard
  - `DR_Subs_Rules_Registry` — fires `dr_subs_register_rules` action for 3rd-party rules
  - `DR_Subs_Scan_Context` — **single-query AS index** bucketed by sub_id in PHP. Fixes v1 `args LIKE '%sub_id%'` false-positive where sub 1 matched 11/111.
- **Three detection rules**:
  - Ghost Sub: active + past next_payment + no pending AS → `as_schedule_single_action`; revert checks AS status, flags `already_executed` on COMPLETE.
  - On-Hold Paid (Stripe v1): on-hold sub + latest renewal has `_stripe_charge_captured` → flip order to completed/processing + sub to active.
  - Repeated Failures: `bucket='risk'` at 2-3 fails/30d, `bucket='broken'` at 4+. Fix: one-shot retry.
- **Scanner**: `DR_Subs_Health_Scanner` — transient-locked, 100/batch via `wcs_get_subscriptions`, one `ScanContext` shared across all rules, diffs `newly_broken_sub_ids` vs prior state, fires `dr_subs_before_scan` / `dr_subs_after_scan`. Daily via AS, WP-Cron watchdog catches AS stall.
- **Fix journal**: `DR_Subs_Fix_Journal` — `record`, `get`, `get_batch`, `revert`, `revert_batch` (reverse insertion order), retention cleanup (days<0 = forever).
- **Narrator**: `DR_Subs_Narrator::for_match()` wraps rule narration with `dr_subs_narration` filter.
- **Alerts**: `DR_Subs_Alert_Dispatcher` hooks `dr_subs_after_scan @ 20`, sends plain-text digest to settings recipient, gated on `alerts_enabled` + `newly_broken` non-empty + `dr_subs_digest_suppressed_until` filter.
- **AJAX**: 8 endpoints — `get_fix_preview`, `apply_fix`, `revert_fix`, `bulk_fix`, `revert_batch`, `run_scan`, `cancel_scan`, `save_settings`. All guarded by nonce + `manage_woocommerce` cap.

## What shipped (release infra)

- `readme.txt` rewritten for v2.0 — Stable tag: `2.0.0-alpha.1`, full New/Changed/Fixed/Removed changelog.
- `.github/workflows/release.yml` — on tag push `v*`:
  1. Checkout + PHP 8.2 + Node 20
  2. Verify plugin header version matches tag (fail-fast)
  3. `php -l` syntax check on `doctor-subs.php` + `includes/` + `admin/`
  4. rsync build with excludes → zip
  5. GitHub Release (prerelease flag if `alpha|beta|rc` in tag)
  6. `10up/action-wordpress-plugin-deploy@stable` for stable tags (gated on `SVN_USERNAME` + `SVN_PASSWORD` secrets; skips gracefully if missing)
- `scripts/build-zip.sh` — local release builder, matches CI excludes (including `.DS_Store`), auto-detects version from plugin header. Smoke-tested: `/tmp/doctor-subs-2.0.0-alpha.1.zip` = 276K, 70 files, clean.
- `scripts/translate.sh` — POT → Potomatic → `msgfmt` → `make-json`. 20 locales pre-wired.
- `languages/doctor-subs.pot` — 350 translatable strings, generated.
- `config/dictionaries/dictionary.json` — no-translate brand terms.

## Blockers before live WP.org ship

1. **SVN credentials** — add `SVN_USERNAME` + `SVN_PASSWORD` as GitHub repo secrets. Without them the workflow runs but skips the SVN deploy step (`continue-on-error: true`).
2. **Translations not generated** — run `OPENAI_API_KEY=sk-... ./scripts/translate.sh` to populate `languages/doctor-subs-*.po/.mo/.json` for the 20 locales. Dry-run first: `./scripts/translate.sh --dry-run`.
3. **Live smoke test** — `wp plugin install /tmp/doctor-subs-2.0.0-alpha.1.zip --activate` on a staging WooCommerce store with Subscriptions installed. Exercise:
   - First-run page renders
   - Scan button works, populates dashboard counters
   - Click into a broken bucket, preview modal, apply fix, revert
   - Bulk fix
   - Settings save
   - Email digest on next-day scan with a newly-broken sub

## How to finish the ship

```bash
# 1. Merge v2-build into main (or open a PR if you want review)
git checkout main
git merge --no-ff v2-build
git push origin main

# 2. Tag and push — CI takes over
git tag v2.0.0-alpha.1
git push origin v2.0.0-alpha.1

# 3. Watch GitHub Actions. It will:
#    - Build the zip
#    - Create the GitHub Release (marked prerelease because "alpha")
#    - Skip SVN deploy (alpha/beta/rc guard, and prerelease shouldn't go live anyway)

# 4. When you cut the stable v2.0.0 tag later, SVN deploy will fire IF secrets are set
```

## Known incomplete items (deferred, not blockers for alpha)

- v1 analyzer classes (`includes/analyzers/*`) remain in the zip as dead code. Per PRD they stay as reference for a future per-sub "Investigate" drill-down. Not wired into v2 UI.
- PayPal / Authorize.net / Square / WooPayments variants for On-Hold Paid rule — v2.0 ships Stripe only per PRD.
- Screenshots (referenced in `readme.txt == Screenshots ==` section) not yet captured. WP.org directory won't render them until files land in `assets/` on SVN. Can add post-launch.

## Files you probably want to eyeball first

- `doctor-subs.php` (autoloader + activation)
- `includes/scanner/class-scan-context.php` (perf-critical)
- `includes/rules/class-rule-ghost-sub.php` (canonical rule shape)
- `includes/class-ajax-handler.php` (entire AJAX surface)
- `.github/workflows/release.yml` (CI)
- `readme.txt` (what WP.org users will read)
