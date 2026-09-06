# Doctor Subs tests

Integration tests, on purpose. Every rule reads a live `WC_Subscription` and the
Action Scheduler store, so a mocked suite would assert the behaviour of the
mocks. These load real WordPress, real WooCommerce and real WooCommerce
Subscriptions, and roll each test back in a transaction.

## Running them

`composer.json` is gitignored in this repo (local-only tooling), so a fresh
clone installs the dev dependencies first:

```bash
composer require --dev phpunit/phpunit:^9.6 yoast/phpunit-polyfills:^2.0 wp-phpunit/wp-phpunit:^6.9
```

Then:

```bash
vendor/bin/phpunit
```

Against the Subscriptions release-testing rig instead of the plugin's own
sandbox:

```bash
DR_SUBS_PLUGINS_DIR="/Users/david/Local Sites/wcs/app/public/wp-content/plugins" vendor/bin/phpunit
```

Where `composer.json` is present locally, `composer test` and `composer test:wcs`
are wired to the same two commands.

## What it needs

- A WordPress core checkout, and WooCommerce plus WooCommerce Subscriptions in
  a plugins directory.
- A scratch MySQL database. **It is wiped on every run.** Never point it at a
  site you care about.

Both are configured in `tests/wp-tests-config.php` and every path can be
overridden with an environment variable, so nothing here is pinned to one
machine:

| Variable | Default |
|---|---|
| `DR_SUBS_WP_ROOT` | the plugin's LocalWP sandbox docroot |
| `DR_SUBS_PLUGINS_DIR` | that sandbox's `wp-content/plugins` |
| `DR_SUBS_TEST_DB` | `doctor_subs_tests` |
| `DR_SUBS_TEST_DB_USER` / `_PASS` / `_HOST` | `root` / `root` / the sandbox socket |

Create the database once:

```sql
CREATE DATABASE doctor_subs_tests DEFAULT CHARACTER SET utf8mb4;
```

## Why there is no CI job

WooCommerce Subscriptions is a paid plugin and cannot be installed on a public
runner, so this suite cannot run in GitHub Actions as things stand. The
bootstrap skips rather than fails when Subscriptions is missing, so adding a job
later is a matter of making the plugin available to the runner. Until then it is
a pre-release gate you run locally, and CI keeps doing the version check and
`php -l`.

## What is covered

- **Journal round trip** (`journal-test.php`): record, read back, revert, batch
  revert, double revert, missing entry, batch id storage.
- **Every rule's apply/revert pair** (`rules-test.php`): each rule detects a
  fixture shaped to trip only it, applies, asserts the store actually changed,
  reverts, and asserts the store is back where it started. Plus the negative
  cases that keep a rule in its lane, and a registry contract test so a
  catalogued rule cannot go unregistered.

`total_drift` is flag-only, so its test asserts it detects, advertises
`manual_only`, and throws if anyone calls `apply_fix` on it.

## Fixture gotchas worth knowing

Three things cost real time to work out, and all three are load-bearing in
`class-dr-subs-test-case.php`:

1. **Use a subscription product, published.** Subscriptions refuses to activate
   a subscription that `contains_unavailable_product()`, and a simple or
   unpublished product counts as unavailable.
2. **Clear line items before adding one.** WooCommerce caches order items by
   order id, and the per-test rollback recycles ids, so a fresh subscription can
   arrive already holding a previous test's item.
3. **Drive status transitions with manual renewal on.** No real gateway is
   registered in the test environment, and Subscriptions gates some transitions
   on gateway support.
