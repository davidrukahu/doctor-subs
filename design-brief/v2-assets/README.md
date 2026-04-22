# v2-assets - production design assets for Doctor Subs v2

Staged, shippable files for the v2 rebuild. During v2 implementation, `mv` the contents of `admin/` into the plugin root's `admin/` directory (replacing the v1 files). Until then they live here so they don't break v1 still running on WP.org.

## Layout

```
v2-assets/admin/
├── css/
│   ├── tokens.css        Design system. Self-hosted @font-face, OKLCH colors,
│   │                     type scale, spacing, shadow, state helpers, focus,
│   │                     reduced-motion, banner primitive, skeleton/fade animations.
│   │                     Scoped to .ds-root so it can't leak into WP admin.
│   ├── admin.css         Surface-specific styles (first-run, dashboard, modal,
│   │                     fix history, settings). Depends on tokens.css.
│   └── responsive.css    Tablet (≤1023px) + mobile (<768px) breakpoints.
│                         Print stylesheet included.
│
├── fonts/                9 woff2 files, 156 KB total. All self-hosted per
│   ├── switzer-400.woff2                        Fontshare free-tier license
│   ├── switzer-500.woff2                        (permits self-hosting).
│   ├── switzer-600.woff2
│   ├── instrument-serif-400-latin.woff2         OFL license.
│   ├── instrument-serif-400-latin-ext.woff2
│   ├── instrument-serif-400-italic-latin.woff2
│   ├── instrument-serif-400-italic-latin-ext.woff2
│   ├── jetbrains-mono-latin.woff2               OFL. Variable font,
│   └── jetbrains-mono-latin-ext.woff2           serves both 400 and 500.
│
├── js/
│   └── admin.js          Vanilla JS. Counter filter, fix preview modal
│                         (AJAX open, focus trap, ESC/backdrop close),
│                         apply fix with row-exit animation, refresh scan,
│                         fix history filter + revert, settings form with
│                         unsaved-changes guard, toggle label sync.
│
└── views/
    ├── first-run.php               Surface 1. States: default, scanning, zero.
    ├── dashboard.php               Surface 2. States: mixed, healthy, failed, refreshing.
    ├── modal-fix-preview.php       Surface 3. AJAX fragment. Includes
    │                               $already_executed warning branch that
    │                               resolves the revert-silent-no-op concern
    │                               from /plan-eng-review.
    ├── fix-history.php             Surface 4. List + empty + batch + reverted
    │                               + past-retention variants. Filter tabs.
    ├── settings.php                Surface 5. Alerts / Retention / Telemetry.
    │                               Unsaved-changes guard in JS.
    └── partials/
        ├── plugin-header.php       Shared header with brand + tabs + meta.
        └── stethoscope.php         Inline SVG brand mark.
```

## Wiring into the plugin

When the v2 backend (scanner, rules, journal, migrations) lands:

1. In `class-admin.php` (or its renamed `class-dr-subs-admin.php`), replace `render_admin_page()` with a router that loads the right view:

   ```php
   $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
   // Gather state (from scanner, journal, settings) ...
   switch ( $tab ) {
       case 'history':  require WCST_PLUGIN_DIR . 'admin/views/fix-history.php'; break;
       case 'settings': require WCST_PLUGIN_DIR . 'admin/views/settings.php'; break;
       default:
           $never_scanned = empty( $last_scan_time );
           if ( $never_scanned ) {
               require WCST_PLUGIN_DIR . 'admin/views/first-run.php';
           } else {
               require WCST_PLUGIN_DIR . 'admin/views/dashboard.php';
           }
   }
   ```

2. In `enqueue_admin_scripts()`, load the new CSS:

   ```php
   wp_enqueue_style( 'dr-subs-tokens',     WCST_PLUGIN_URL . 'admin/css/tokens.css',     array(), WCST_PLUGIN_VERSION );
   wp_enqueue_style( 'dr-subs-admin',      WCST_PLUGIN_URL . 'admin/css/admin.css',      array( 'dr-subs-tokens' ), WCST_PLUGIN_VERSION );
   wp_enqueue_style( 'dr-subs-responsive', WCST_PLUGIN_URL . 'admin/css/responsive.css', array( 'dr-subs-admin' ), WCST_PLUGIN_VERSION );

   wp_enqueue_script( 'dr-subs-admin', WCST_PLUGIN_URL . 'admin/js/admin.js', array(), WCST_PLUGIN_VERSION, true );
   wp_localize_script( 'dr-subs-admin', 'drSubsAjax', array(
       'ajaxUrl' => admin_url( 'admin-ajax.php' ),
       'nonce'   => wp_create_nonce( 'dr_subs_admin' ),
       'strings' => array(
           'showingAll'     => __( 'showing all broken and at-risk', 'doctor-subs' ),
           'filtering'      => __( 'filtering to %d %s', 'doctor-subs' ),
           'modalLoadError' => __( 'Could not load the fix preview. Try again in a moment.', 'doctor-subs' ),
           'applying'       => __( 'Applying…', 'doctor-subs' ),
           'applyError'     => __( 'Something went wrong - nothing was changed.', 'doctor-subs' ),
           'reverting'      => __( 'Reverting…', 'doctor-subs' ),
           'confirmRevert'  => __( 'Revert this fix? The subscription will return to its previous state.', 'doctor-subs' ),
           'saving'         => __( 'Saving…', 'doctor-subs' ),
           'saved'          => __( 'Saved.', 'doctor-subs' ),
           'saveError'      => __( 'Could not save. Check your connection and try again.', 'doctor-subs' ),
       ),
   ) );
   ```

3. Register the AJAX handlers the JS expects in the AJAX handler class. Each must verify the `dr_subs_admin` nonce and the `manage_woocommerce` capability:
   - `dr_subs_get_fix_preview` - returns the modal HTML fragment for a given sub_id
   - `dr_subs_apply_fix` - applies the fix, writes to journal, returns `{success, message}`
   - `dr_subs_revert_fix` - reverts a journal entry, returns `{success, message}`
   - `dr_subs_run_scan` - kicks off a fresh scan
   - `dr_subs_cancel_scan` - cancels an in-progress scan
   - `dr_subs_save_settings` - saves settings form (the JS intercepts the form submit to give a "Saving… -> Saved." flash instead of a full page reload)

4. In the Ghost-sub rule's (or other rules') implementation, after determining that an already-executed AS action precedes the fix-preview render, pass `$already_executed = true` to the modal template. The template's `.executed-warning` branch then renders - resolving the revert-silent-no-op concern.

## What's NOT in here (intentional)

- No PHP class files (`DR_Subs_Scanner`, `DR_Subs_Rule_Ghost_Sub`, etc). Those are backend work covered by the plan doc's Next Steps 1-17 and Next Step 17 (CI release).
- No test fixtures - covered by the test-plan artifact at `~/.gstack/projects/davidrukahu-doctor-subs/david-main-eng-review-test-plan-20260422-223000.md`.
- No migrations - the `dbDelta` schema lives in the plan doc's Schema section.
- No email digest HTML template - deferred to v1.1 per eng review.

## Font attribution + licenses

- **Switzer** by [Indian Type Foundry](https://www.indiantypefoundry.com/) via Fontshare. Free tier. Commercial self-hosting permitted. See https://www.fontshare.com/fonts/switzer - readme credit recommended.
- **Instrument Serif** by Instrument via Google Fonts. OFL 1.1. Commercial self-hosting permitted.
- **JetBrains Mono** by JetBrains. OFL 1.1. Commercial self-hosting permitted.

Attribution lines for the plugin's `readme.txt` license section:

```
This plugin ships three self-hosted typefaces under their original licenses:
- Switzer - Indian Type Foundry via Fontshare (free tier, self-hosting permitted)
- Instrument Serif - Instrument (OFL 1.1)
- JetBrains Mono - JetBrains (OFL 1.1)
```
