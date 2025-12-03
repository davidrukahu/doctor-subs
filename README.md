=== Doctor Subs ===
Contributors: davidrukahu
Tags: woocommerce, subscriptions, troubleshooting, diagnostics
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that helps diagnose and troubleshoot WooCommerce subscription issues.

## What It Does

Doctor Subs analyzes WooCommerce subscriptions to identify common problems:

- **Skipped Payment Cycles**: Detects when subscription payments have missed expected billing cycles
- **Manual Completions**: Flags orders that were completed manually without proper payment processing
- **Status Mismatches**: Identifies inconsistencies between subscription status and payment schedules
- **Action Scheduler Issues**: Reviews scheduled events for failures or missing actions
- **Timeline Analysis**: Creates visual timelines showing renewal patterns and missing payments
- **Stripe Payment Method Detachment**: Detects detached payment methods caused by cloned/staging sites (fixes Stripe renewal failures)

## Installation

1. **Download**: Visit [GitHub Releases](https://github.com/davidrukahu/doctor-subs/releases) and download "Source code (zip)"
2. **Upload**: Go to **Plugins > Add New > Upload Plugin** in WordPress
3. **Install**: Choose the downloaded zip file and click **Install Now**
4. **Activate**: Click **Activate Plugin**
5. **Access**: Navigate to **WooCommerce > Doctor Subs**

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WooCommerce 9.8.5+
- WooCommerce Subscriptions (latest version)

## How to Use

### Quick Access from Subscriptions List
1. Go to **WooCommerce > Subscriptions**
2. Find any subscription in the list
3. Click the **"Doctor Subs"** link in the Status column (next to Suspend/Cancel)
4. The analysis will start automatically

### Manual Analysis
1. Go to **WooCommerce > Doctor Subs**
2. Search for a subscription by ID or customer email
3. Click on the search result to analyze
4. Review the automated analysis results

## Analysis Process

The plugin follows a systematic troubleshooting approach:

### Step 1: Subscription Anatomy
Reviews the subscription structure, settings, and configuration

### Step 2: Expected Behavior
Determines what should happen based on the subscription setup

### Step 3: Timeline Analysis
Documents what actually occurred to identify discrepancies

### Step 4: Advanced Detection
- **Skipped Cycles**: Analyzes payment history for missed billing cycles
- **Manual Completions**: Identifies orders completed without proper transactions
- **Status Mismatches**: Finds inconsistencies between status and payments
- **Action Scheduler**: Reviews scheduled events for failures
- **Payment Gateway**: Checks gateway configuration and mode (live/sandbox)
- **Stripe Payment Method Detachment**: Detects detached payment methods from cloned/staging sites

## Common Issues Detected

- Missing renewal orders
- Failed scheduled actions
- Payment method problems
- Timeline discrepancies
- Status inconsistencies
- Gateway communication issues
- Skipped payment cycles
- Manual completion flags
- **Stripe payment method detachment** (cloned site bug)
- **Stripe API errors** in renewal orders

## Technical Details

### Core Components

- **Main Plugin**: Handles initialization and WordPress integration
- **Admin Interface**: Provides the user interface and menu integration
- **AJAX Handler**: Processes analysis requests securely
- **Analyzers**: Specialized classes for different types of analysis
- **Data Collectors**: Gathers subscription and order information
- **Utilities**: Security, logging, and helper functions

### Security Features

- Nonce verification for all requests
- Permission checking (requires manage_woocommerce capability)
- Input sanitization and validation
- Rate limiting for analysis requests
- Secure database queries using prepared statements

## License

GPL v2 or later. See LICENSE file for details.
