# 05 — Copy

Draft text for every visible surface. Tone: calm, clinical, confident. No cheer. No marketing speak. Plain language the 50-year-old shop owner understands.

## Global

- **Product name:** Doctor Subs
- **Product short tagline (for plugin page):** Find and fix broken WooCommerce subscriptions.
- **Brand mark:** stethoscope outline, always paired with "Doctor Subs" wordmark in header.

## First-run surface

- Header: **Doctor Subs**
- Hero headline: **Let's check your subscriptions for problems.**
- Body paragraph: We'll scan your active subscriptions for three common renewal failures. Takes about 30 seconds. Runs entirely on your server — nothing sent anywhere.
- Primary button: **Scan my subscriptions** →
- Secondary link: _or configure settings first_
- Section heading: **What this detects**
- Detect items (name + one-line description):
  - **Ghost subscriptions** — Active subscriptions that won't renew because the payment didn't get scheduled.
  - **Stuck on-hold** — Payment went through, but the subscription never switched back to active.
  - **Repeated payment failures** — Something has been failing to process a payment for a while.

## Scanning state

- Title while scanning: **Scanning your subscriptions…**
- Progress sub-line: Checking {N} active subs… about {S} seconds left.
- Cancel option: _Cancel_ (small text link, for long scans).

## Dashboard

- Header: **Doctor Subs** + stethoscope.
- Last-scanned indicator: Last scanned {relative time} — [Refresh]
- Stale variant (>24h): Last scanned {N} days ago — [Refresh now]
- Counter labels:
  - **Healthy** — no problems detected
  - **At risk** — might need attention
  - **Broken** — needs you now
- Table heading: **Needs attention**
- Table columns: Customer · Subscription · Issue · Reason · (Fix)
- Row action: **Fix**
- All-healthy empty state heading: Everything looks good.
- All-healthy sub-line: We'll keep watching. Last checked {relative time}.
- Filtered empty state (e.g. "Broken: 0"): No broken subscriptions right now.

## Fix preview modal

- Modal title format: **#{id} · {Customer first name}** · {Rule name}
- Context narrative (example for Ghost Sub):
  > Sarah's monthly subscription was supposed to renew on March 15 at 09:00 UTC. The payment event wasn't scheduled, so nothing was charged. This means she hasn't paid for April either.
- Context narrative (Stuck on-hold):
  > Marcus's last renewal payment went through in Stripe on April 8 — he was charged $29. But the subscription status stayed "on hold" instead of switching back to active. He's been treated as a lapsed customer since.
- Context narrative (Repeated AS failures):
  > Something has been failing to process Anya's renewal since March 22 — 4 attempts, all failed. The error looks like a gateway issue, not a card problem.
- "What will change" heading: **What will change**
- Diff row label format: `Field name: Before → After`
- Reversibility note: You can undo this from Fix history at any time.
- Cancel button: **Cancel**
- Primary button: **Fix subscription**
- Applying state button: **Applying…**
- Success state: **Fixed.** [View in history]
- Error state: Something went wrong — nothing was changed. [Try again] [Close]
- Drift warning: This subscription changed since we last checked. [Re-scan first]

## Fix history (undo log)

- Page title: **Fix history**
- Empty state: No fixes yet. Fixes you apply will appear here so you can undo them.
- Entry format: **{relative time}** · {customer} · {rule} · {short change summary} · [Revert]
- Batch entry: **{relative time}** · Fixed {N} subscriptions in one batch · [Revert all]
- Reverting state: Reverting…
- Reverted: **Reverted {when}**
- Revert-failed: Couldn't revert — {reason}. [Details]

## Settings

- Page title: **Settings**
- Section 1 heading: **Alerts**
- Section 1 body: When something breaks between scans, we'll email you a daily digest. Off by default.
- Toggle label: Email alerts
- Email input label: Send to
- Email input helper: Defaults to your WordPress admin email.
- Section 2 heading: **Fix history retention**
- Section 2 body: Older entries get pruned automatically. Recent entries stay revertible.
- Select label: Keep fixes for
- Options: 30 days · 90 days · **180 days** · 365 days · Forever
- Section 3 heading: **Help us improve**
- Section 3 body: Anonymous telemetry helps us know which fixes are actually used. We only send the rule name and a timestamp when a fix is applied. Nothing else — no customer data, no subscription details, no identifying information.
- Toggle label: Send anonymous fix stats
- Save button: **Save changes**
- Saved flash: Saved.
- Invalid email: Enter a valid email address.

## Email digest

- Subject line: Doctor Subs: {N} subscriptions need attention
- Body opener: Between yesterday's scan and today's, {N} subscription{s} started showing problems.
- Entry format: {Customer name} · #{id} · {rule name}
- CTA: [View in Doctor Subs]
- Footer line: Sent because alerts are on in Doctor Subs. [Turn off alerts.]

## Error copy (reusable)

- Network/server unreachable: Couldn't reach your server. Check your network and try again.
- Permission denied: You don't have permission to do this. Ask your site admin.
- WC Subscriptions missing: Doctor Subs needs WooCommerce Subscriptions to run. [Install it.]
- HPOS required: Doctor Subs needs WooCommerce 9.0+ with HPOS enabled.
- Generic fallback: Something went wrong — nothing was changed. Try again in a moment?

## What to avoid in copy

- "Awesome!" "Great news!" "You're all set!" — none of these.
- Emoji of any kind.
- Exclamation points — rarely, if ever. This is a diagnostic tool, not a party.
- "Your subscription family" / "your subscribers" / other cutesy framing.
- The word "simply" or "just" (hides complexity, condescending).
- Overusing "we" — makes the plugin sound like a team when it's a tool.
