# TODOs — Doctor Subs

Work deferred from active development. Capture enough context that someone picking this up in 3 months understands motivation, current state, and where to start.

---

## Happiness mode (A8C internal support surface)

**What:** A hidden admin toggle (option or constant) that reveals a different Doctor Subs surface optimized for A8C Happiness engineers: bulk audit across all active subs, keyboard shortcuts for fast ticket-handling, full raw analyzer output (not plain-English narration), and cross-site-safe batch controls.

**Why:** Happiness engineers currently spend ~2 hours per WooCommerce Subscriptions ticket doing manual forensics (confirmed via weekly check-ins on Atari/Galaga P2s in summer 2025). A dedicated support surface could compress this substantially, but it's a different product shape than the merchant-facing dashboard in v2.0.0. Shipping it inside an open-source WP.org release creates a conflation concern (internal tooling in public code).

**Pros:**
- Immediate internal adoption if even a handful of Happiness engineers pick it up.
- Real tickets = daily feedback loop the merchant audience can't provide at the same cadence.
- Happiness mode's needs might surface rule improvements that benefit merchants too.

**Cons:**
- Expands scope before merchant v2 has proven the core loop.
- Risk of "A8C internal wishlist" pulling direction away from WP.org merchant fit.
- Any Happiness-specific code is maintenance burden in a public repo.

**Current state:** Deferred from v2.0 scope. Design doc lists it in the v1.1 roadmap.

**Discovery plan (the bar before building):**
1. After v2.0.0 ships and has >30 days of install data, post in `#woo-support` (or the equivalent current A8C Happiness Slack channel for WooCommerce Subscriptions) asking: "If Doctor Subs had a support-engineer mode, what would make it worth installing on your admin session?"
2. If 3+ Happiness engineers independently describe similar needs (bulk audit, keyboard-driven triage, export), build Happiness mode for v1.1.
3. If fewer than 3, keep deferred. Revisit in 6 months.

**Depends on / blocked by:** v2.0.0 shipping. Do not build before then.

---

## Additional TODOs from /plan-eng-review 2026-04-22

### `WCST_` → `DR_Subs_` class rename tracking

The v2 rebuild is renaming all PHP classes from `WCST_*` to `DR_Subs_*`. Any third-party code extending Doctor Subs via class name references will break. Post-launch, monitor:

- WP.org support forum for "class not found" or "undefined" bug reports after v2 install.
- GitHub issues on `davidrukahu/doctor-subs`.

If >1 report surfaces, publish a migration note pinned to the plugin description and add `class_alias()` shims for the most common offenders.

### Real-store perf validation

The scanner's single-batched-query approach is theoretical until tested on a real store with 5000+ subs. Before shipping v2.0.0 (step 15 of Next Steps), reach out to a friendly merchant or seed a load-test store to validate:
- Scanner completes in <5 min.
- Dashboard loads in <500ms (reads from `dr_subs_sub_health` snapshot).
- Memory footprint during scan stays under 128MB.

If any of these fail, adjust batch size constant (`DR_SUBS_SCAN_BATCH_SIZE`) and/or add continuation offsets to the scanner.
