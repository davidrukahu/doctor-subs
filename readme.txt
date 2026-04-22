=== Doctor Subs ===
Contributors: davidrukahu
Tags: woocommerce, subscriptions, troubleshooting, diagnostics, payment issues
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnose and fix WooCommerce subscription issues with a 3-step troubleshooting tool. Detects skipped payments, failed renewals, and payment problems.

== Description ==

Doctor Subs is a comprehensive diagnostic tool for WooCommerce Subscriptions that helps you identify and troubleshoot subscription payment issues quickly and efficiently.

= Key Features =

* **3-Step Diagnostic Process**: Systematic analysis of subscription anatomy, expected behavior, and timeline
* **Skipped Payment Detection**: Identifies when subscription payments have missed expected billing cycles
* **Manual Completion Flags**: Detects orders that were completed manually without proper payment processing
* **Status Mismatch Detection**: Finds inconsistencies between subscription status and payment schedules
* **Action Scheduler Review**: Analyzes scheduled events for failures or missing actions
* **Timeline Analysis**: Creates visual timelines showing renewal patterns and missing payments
* **Stripe Payment Method Detection**: Identifies detached payment methods caused by cloned/staging sites

= Common Issues Detected =

* Missing renewal orders
* Failed scheduled actions
* Payment method problems
* Timeline discrepancies
* Status inconsistencies
* Gateway communication issues
* Skipped payment cycles
* Manual completion flags
* Stripe payment method detachment (cloned site bug)
* Stripe API errors in renewal orders

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Search for "Doctor Subs"
4. Click "Install Now"
5. Click "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the downloaded zip file
4. Click "Install Now"
5. Click "Activate"

= After Installation =

1. Navigate to WooCommerce > Doctor Subs
2. Search for a subscription by ID or customer email
3. Click on the search result to analyze
4. Review the automated analysis results

== Frequently Asked Questions ==

= How do I access Doctor Subs? =

You can access Doctor Subs from:
* **WooCommerce > Doctor Subs** menu
* **WooCommerce > Subscriptions** - Click the "Doctor Subs" link in the Status column

= What subscription issues can Doctor Subs detect? =

Doctor Subs can detect:
* Skipped payment cycles
* Manual order completions
* Status mismatches
* Action Scheduler failures
* Payment method detachment
* Timeline discrepancies

= Does Doctor Subs fix issues automatically? =

No, Doctor Subs is a diagnostic tool. It identifies problems and provides detailed information to help you fix them manually.

= What are the requirements? =

* WordPress 5.0+
* PHP 7.4+
* WooCommerce 9.8.5+
* WooCommerce Subscriptions (latest version)

== Screenshots ==

1. Search for subscriptions by ID or customer email
2. Subscription anatomy analysis
3. Expected behavior analysis
4. Timeline visualization
5. Issues and statistics
6. Advanced detection results

== Changelog ==

= 1.2.4 =
* Fixed all security issues (sanitization, validation, escaping)
* Fixed Action Scheduler compatibility (scheduled_date_gmt column)
* Improved error handling and debugging
* Enhanced analyzer stability
* Added PHPCS configuration

= 1.2.3 =
* Initial release

== Upgrade Notice ==

= 1.2.4 =
This version includes important security fixes and compatibility improvements. All users should upgrade.
