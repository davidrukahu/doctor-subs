# 02 — Aesthetic direction

## Three words for the feel

**Calm. Clinical. Confident.**

- Calm: the user arrived stressed. Every pixel should lower their heart rate.
- Clinical: this is a diagnostic tool. Objective, precise, not salesy. Think how a good doctor speaks to a patient — kind, direct, informative, not optimistic-to-the-point-of-unhelpful.
- Confident: the plugin knows what it's doing. Visually solid. Not cute, not tentative, not hedging.

## Reference points (feel, not copy)

- **Linear** — information hierarchy, dark/light with one accent, typography-led.
- **Plausible Analytics** — clean admin density, no dashboard-card mosaic, utility over flair.
- **WHOOP / Oura web** — health-data calm, soft tints behind numbers, never red-alert.
- **Stripe dashboard (newer)** — tables done right, calm interactive states, strong typographic hierarchy.
- **Apple Health** — the specific shade of care and trust around personal-data interactions.
- **GitHub's issue pages (modern era)** — plain text as a design material, density without chaos.

## Anti-references (do not do this)

- **Stock WP admin plugin page** — flat WP blue, 14px everything, 4px radius, everything inside a white box with 20px padding. Dated.
- **SaaS landing-page aesthetic** — gradient headers, purple/violet anything, huge bold numbers with stats-hero-layout, 3-column feature grid.
- **"AI dashboard" aesthetic** — glass morphism, neon-on-dark, icons in colored circles, sparkline decorations.
- **Healthcare-portal panic aesthetic** — oversized red "ALERT" banners, flashing indicators, blood-donation-drive red.
- **Marketing-site cheer** — no confetti, no "🎉 Great news!", no "We've scanned your store and we're so proud!"

## Specific visual guidance (but feel free to deviate if it serves the feel)

### Typography

- Two faces: one distinctive display/heading, one refined body. NOT Inter/Plex/DM/Outfit — those are the default reflex and they all look the same.
- Candidates to audition: Söhne, Graphik, Mona Sans, Söhne Mono (for numbers), Supreme, Monument Grotesk, Lausanne, or something from Pangram Pangram / Klim / Dinamo. Numbers (the counters) want a distinctive treatment — tabular numerals, maybe a slightly condensed display face.
- Type scale: at least 1.25 ratio between steps. A 5-step scale is enough. 13px minimum for body, 15-16px comfortable.

### Color

- One accent color. NOT WordPress blue (#2271b1). NOT purple. NOT indigo-to-violet gradient.
- Suggested direction: desaturated teal / muted sage / warm charcoal / deep forest / muted terracotta — something that feels medical-but-not-hospital-sterile.
- Neutrals: tint them toward the brand hue. Pure white / pure black are banned. Use OKLCH if the tool supports it.
- State colors: green = healthy, amber = at-risk, red = broken. But NOT the stock web palette — pick desaturated, tint-matched versions. Think pastel with soul, not alert colors.

### Layout

- Left WP admin nav stays visible (subdued, compressed). That's the container — can't change it. The plugin's content area is what gets designed.
- Inside the plugin area: no card-grid mosaics. No stacked cards as the main layout primitive. Use tables, lists, and typographic hierarchy as the primary organizing tools.
- Generous whitespace. Breathing room around the counters especially.

### Motion

- Minimal. One meaningful moment per page load. Counters can count up on first render (once, not every refresh).
- No bouncing, no elastic easing, no spring animations on basic UI. Exponential ease-out.

### Decorative elements

- Stethoscope as brand mark is fine if rendered well (outline stroke, single color, minimal). NOT emoji. NOT wiggling.
- No blobs, no wavy dividers, no floating circles, no section-separator flourishes.

## The "would they believe an AI made this?" test

When the design is done, imagine showing it to a senior designer and saying "AI generated this in one shot." If they'd nod and say "yeah, I can tell," go back. A good design makes them ask "how was this made?" not "which AI made it?"
