# 04 — Interaction states

Every surface has more than one state. Design them all. Skipping states is how plugins ship broken.

## Dashboard states

| State | When | What the user sees |
|---|---|---|
| **Never-scanned** | Plugin just activated, no scan run yet | Redirect to Surface 1 (first-run). Dashboard should not load empty. |
| **Scanning (initial)** | First scan running | Calm progress indicator. Heading shifts to "Scanning your subscriptions..." + a subtle sub-line ("Checking 247 active subs..."). Counters not yet shown — placeholder with skeleton or subtle shimmer. |
| **Scanning (refresh)** | Merchant clicked Refresh | Existing counters stay visible but grayed slightly. Tiny spinner next to "Refresh". Disappears when done. No full-page takeover. |
| **All healthy** | Zero at-risk, zero broken | Healthy counter shows the number. At-risk and Broken counters show "0" in a muted tone. Table replaced with a calm empty state: "Everything looks good. Last checked 2 hours ago." No celebration. No emoji. Just confirmation. |
| **Mixed (some broken)** | The normal case after a scan | Counters populated. Table shows broken + at-risk subs. Default view. |
| **Big store, many broken** | 50+ broken | Table paginates. Show first 25. Pagination subtle at bottom. No "Export all" button (out of scope). |
| **Scan failed** | Scanner crashed or got stuck | Soft error banner above counters: "The last scan didn't complete. [Try again]. If this keeps happening, [view logs]." Don't block the rest of the UI. |

## First-run states

| State | When | What the user sees |
|---|---|---|
| **Default** | First visit | The full first-run layout as described in 03-surfaces.md |
| **Scanning** | After clicking Scan | Transforms in place: the button area becomes a progress indicator ("Checking 247 subscriptions... about 20 seconds left"). Detect-list can stay visible to fill the time. |
| **Scan complete** | Scan finishes | Short transition to Surface 2 (dashboard). Don't show a "scan complete!" interstitial — just arrive on the results. |
| **Subscription count zero** | Store has no active subs yet | Alternative first-run copy: "You don't have any active subscriptions to scan yet. Doctor Subs will start watching once you do." No button. Low-key. |

## Fix preview modal states

| State | When | What the user sees |
|---|---|---|
| **Default** | Modal opened | The diff + two buttons. As spec'd in 03-surfaces. |
| **Applying** | Fix button clicked | Button shows "Applying..." with a spinner. Cancel button disabled. Modal backdrop stays. |
| **Success** | Fix succeeded | Button area transforms to a calm "Fixed. ↩ View in history" link. Or modal closes after a brief confirming state. No confetti. |
| **Apply failed** | Something threw mid-apply | Modal stays open. Inline error: "Something went wrong: [short reason]. Nothing was changed. [Try again] [Close]." Never leaves the merchant wondering if a half-fix happened. |
| **State drift detected** | Sub state changed between detection and apply | Inline warning above the diff: "Heads up: this subscription has changed since we last checked. [Re-scan] recommended before applying." |

## Undo log states

| State | When | What the user sees |
|---|---|---|
| **Default** | History has entries | List of fixes as described. |
| **Empty** | No fixes applied yet | "No fixes yet. Fixes you apply will appear here so you can undo them." |
| **Reverting** | Revert clicked | Row shows "Reverting..." with spinner. Other rows interactable. |
| **Reverted** | Revert succeeded | Row transforms: timestamp stays, status changes to "Reverted [Xh ago]". Revert button gone. |
| **Revert failed** | Revert errored (e.g., state moved on) | Inline warning in the row: "Couldn't revert — [reason]. [Details]." Keeps the fix as applied. |

## Settings states

| State | When | What the user sees |
|---|---|---|
| **Default** | Settings page loaded | All options with current values. |
| **Unsaved changes** | Merchant edited but didn't save | Subtle sticky "You have unsaved changes" bar at bottom with Save + Discard buttons. |
| **Saved** | Save clicked | Brief confirmation ("Saved") that fades. No page reload. |
| **Email invalid** | Bad email entered | Inline field error beneath input: "Enter a valid email address." |

## Error tone

All errors should sound like a calm colleague, not an angry system:

- "Something went wrong — we didn't change anything. Try again in a moment?"
- "Couldn't reach your server — check your network and retry."
- "This subscription changed since we last looked. Re-scan first?"

Never "An error occurred." Never stack traces.
