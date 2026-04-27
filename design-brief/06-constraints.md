# 06 — Constraints

## Technical container

- **Environment:** WordPress admin (`/wp-admin/admin.php?page=doctor-subs`). Plugin's content lives inside the main content area. The WP left navigation sidebar is always visible and cannot be redesigned.
- **CMS:** WordPress 6.0+, WooCommerce 9.0+, WooCommerce Subscriptions (latest).
- **Rendering:** Server-rendered PHP templates. No React/Vue/SPA. jQuery available if needed but not required. Modern vanilla JS preferred.
- **CSS:** Scoped to the plugin's wrapper. Must not leak out to the rest of WP admin. Tailwind is NOT used in the codebase; plain CSS (or a small utility set) is fine. CSS custom properties (variables) encouraged.
- **No external fonts over network:** fonts must be bundled with the plugin (WP.org review requires no external asset loading by default). This is a real constraint — pick a font with a permissive self-host license.
- **Stylesheet loads:** only on the plugin's admin page, not globally.
- **Bundle size budget:** keep the plugin admin CSS under 50 KB, JS under 100 KB.

## Dimensions

- The plugin's content area width depends on WP admin chrome + screen size. Typical useful range:
  - Narrow admin (collapsed sidebar, 1280px viewport): ~1100px content area.
  - Normal admin (sidebar expanded, 1440px viewport): ~1200px content area.
  - Wide admin (2560px viewport): ~1700px content area.
- Design for a comfortable reading width around 1200px. Don't fill ultra-wide unless it's the table.

## Responsive

- WP admin IS used on tablets and phones occasionally (merchants triaging from the couch or in line at Starbucks).
- Breakpoint targets:
  - Desktop: ≥1024px (primary).
  - Tablet: 768-1023px (should gracefully degrade — counter cards stack 3→2→1, table becomes scrollable horizontally, modal takes more width).
  - Mobile: <768px (must work, doesn't have to be gorgeous). Counters stack vertically. Table becomes a list of cards (one per row) with the Fix button still reachable.
- WP admin's own left nav becomes an off-canvas hamburger on mobile — don't design around it, WP handles it.

## Accessibility (hard requirements)

- WCAG 2.1 Level AA contrast.
- All interactive elements keyboard-reachable, with visible focus states. The focus style must be distinct from hover and obvious.
- 44px minimum touch target on mobile.
- All buttons have accessible names. Icon-only buttons have aria-labels.
- Color is never the only signal. The state-colored counters also have text labels and can be distinguished by shape/position/copy if colors were removed.
- Modal traps focus and closes on ESC.
- Tables have proper row/column semantics, not divs pretending to be tables.
- Animations respect `prefers-reduced-motion`.
- Works with screen readers (VoiceOver at minimum tested).

## Internationalization

- All copy is wrapped in WordPress i18n functions in the actual implementation. The design brief uses English strings, but the design should allow for:
  - Text expansion up to 30% (German, French often longer than English).
  - RTL mirroring for Arabic/Hebrew locales.
  - Non-ASCII characters in customer names.

## Dark mode

- Up to you. Not a hard requirement. WP admin itself doesn't have an official dark mode yet (ships 6.5+ maybe, controversial). If you include one, make it a toggle in Settings, not automatic.

## Colors already in the existing plugin (v1)

These can be replaced entirely — the design should pick its own palette. Listed for reference only:

```
Text:            #1d2327
Subtle text:     #646970
WP admin blue:   #2271b1
Error red:       #d63638
Warning amber:   #dba617
Success green:   #00a32a
Surface:         #ffffff
Light accent:    #f6f7f7
```

## What the plugin cannot do (design implications)

- Cannot change WP admin's top bar or left sidebar — can only style the main content area.
- Cannot load external scripts (no Google Fonts CDN, no Font Awesome CDN, no analytics pixels).
- Cannot open modal-style takeovers outside of native WP admin notice patterns — but within the plugin's own content, modals are fine and encouraged for the fix preview.
- Cannot play sound.

## Output format preference

If Claude Design produces:
- **HTML + CSS**, prefer vanilla CSS with `:root` custom properties. Single `.php` template-ish file per surface is easy to map into the plugin.
- **React**, we'll need to convert it down to PHP templates. Usable as visual reference but not final.
- **Figma-style mockup + tokens**, great — pair with any code output.

A few single-file HTML artifacts (one per surface) is ideal: each self-contained, opens in a browser, shows all the states side-by-side or toggleable.
