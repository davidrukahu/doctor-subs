# 03 — Surfaces to design

Five screens. Each lives inside WordPress admin (at `/wp-admin/admin.php?page=doctor-subs`). The WP left sidebar is always visible; design the content area to the right of it.

---

## Surface 1: First-run landing

Seen on the very first visit, before any scan has been done. This is the panic-mode entry point.

**Layout direction:**
- Plugin name "Doctor Subs" + stethoscope mark, top of content area.
- Generous vertical whitespace.
- One confident headline, conversational: "Let's check your subscriptions for problems."
- One supporting paragraph with the privacy reassurance: "We'll scan your active subscriptions for three common renewal failures. Takes about 30 seconds. Runs entirely on your server, nothing sent anywhere."
- One primary button: "Scan my subscriptions" with a subtle chevron.
- Secondary subtle link below: "or configure settings first".
- Below a divider: "What this detects" section with three items. Each item = problem name + one-line plain description.

**Data shown:** none. Empty state by design.

**Primary action:** Scan button.

**Don'ts:** No hero image. No stock "welcome" card with checkmarks. No testimonials. No pricing (it's free). No marketing cheer.

---

## Surface 2: Dashboard

The main surface after a scan has completed. Also the default view for returning merchants.

**Layout direction:**
- Header: "Doctor Subs" + stethoscope mark, top-left.
- Subheader, top-right: "Last scanned 2 hours ago" + subtle "Refresh" link with a small rotate icon. If the scan is stale (>24 hours), the timestamp color shifts subtly to warn.
- Three counter cards, side by side: Healthy / At risk / Broken. Each shows a large number and a subtle hint of its state color. Counters must be obviously clickable (hover state filters the table below).
- Below counters: a table titled "Needs attention". Columns:
  - Customer name (stronger weight)
  - Subscription ID (linkable, monospace or subtle treatment)
  - Rule that matched (tiny pill or tag, color-coded subtle)
  - Plain-English reason (one line, truncates if long)
  - "Fix" action (right-aligned, outline button style, accent color)
- When a counter is selected, table shows subs in that bucket. When none selected, table shows all broken + at-risk subs.

**Data shown:** realistic subscription data — real-sounding customer names, believable issue descriptions. Don't use "John Doe" and "Payment error" — use "Sarah Mendez, #4812, Ghost subscription, Next payment didn't get rescheduled after March 15 renewal."

**Primary action:** clicking a table row opens Surface 3 (preview modal).

**Don'ts:** No cards-inside-cards. No sparkline decorations. No "growth arrow" indicators. No stat-hero metrics. No 3-column dashboard-grid widgets.

---

## Surface 3: Fix preview modal

Opens when the merchant clicks a row or the "Fix" button. This is the single highest-trust moment in the product.

**Layout direction:**
- Modal, centered, medium width (~560px). Subtle backdrop blur or dim.
- Header: sub ID + customer name + rule that matched (one line).
- Body: plain-English narrative of what happened. Example: "Sarah's subscription #4812 was supposed to renew on March 15 at 09:00, but WordPress didn't schedule the payment event. She hasn't been charged for April either."
- Then: a "What will change" section with a side-by-side or stacked diff view:
  - Before state (left or top): status, next payment date, any relevant fields.
  - After state (right or bottom): same fields with new values highlighted.
  - The diff must be visually unambiguous — no guessing what changes.
- Below diff: one-line note on reversibility: "You can undo this from the Undo log at any time."
- Footer: two buttons — "Cancel" (secondary/ghost) and "Fix subscription" (primary, accent color).

**Don'ts:** No long confirmation copy. No "Are you sure?" extra step. No warning icons. No disclaimers. The clarity of the diff IS the disclaimer.

---

## Surface 4: Undo log

Tab (or separate page) showing every fix that's been applied. Secondary surface, accessed from a link in the header or a tab.

**Layout direction:**
- Page title: "Fix history" (friendlier than "Undo log").
- List of entries in reverse chronological order. Each entry is a compact row:
  - Timestamp (relative: "3 hours ago", "yesterday", hover for exact).
  - Subscription ID + customer name.
  - Rule that was applied.
  - One-line summary of what changed.
  - "Revert" button (subtle, right-aligned) if still revertible. If already reverted, show "Reverted" label in place.
- Grouped batch fixes (bulk operations) collapse under a single parent row with a count ("Fixed 3 subscriptions in one batch").
- Empty state: "No fixes yet. Fixes you apply will appear here so you can undo them."

**Data shown:** realistic 10-row history mixing individual and batch fixes.

**Don'ts:** No full audit-log density (timestamps every millisecond, UUIDs, raw JSON). This is for the merchant, not a forensic tool.

---

## Surface 5: Settings

Tab (or separate page). Configuration for alerts, telemetry opt-in, journal retention.

**Layout direction:**
- Page title: "Settings".
- Three grouped sections, each with a small heading and supporting description:
  1. **Alerts** — enable/disable toggle + email input ("Send alerts to: [admin@example.com]"). Helper text: "When something breaks between scans, we'll email you a daily digest."
  2. **Retention** — "Keep fix history for: [180 days] (select)". Helper text: "Older entries get pruned automatically. Recent entries stay revertible."
  3. **Help us improve** — toggle for anonymous telemetry. Helper text explicitly lists what's sent: "Sends only the rule name + timestamp when a fix is applied. Nothing else. No customer data, no subscription data, no identifiable information."
- Save button at the bottom — or inline autosave if that feels right.

**Don'ts:** No advanced tab, no nested forms. Keep it flat. No scary "Danger zone" red section.

---

## Optional sixth surface: Email digest

When alerts are on and new broken subscriptions appear between scans, a daily digest email goes out. Plain-text-first email design (not a marketing template).

**Layout direction:**
- Subject line: "Doctor Subs: 3 subscriptions need attention"
- From: [Store name] via Doctor Subs
- Body: brief intro (one line), then a list of affected subscriptions (customer name, ID, rule). A single button/link to the dashboard.
- Footer: tiny footer with "Sent because alerts are on in Doctor Subs. [Turn off.]"

**Don'ts:** No promotional imagery. No branded header bar. No "View in browser" link. Treat it like a transactional email from a serious tool.
