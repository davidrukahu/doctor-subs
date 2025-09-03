# Doctor Subs - WooCommerce Subscription Troubleshooter

A WordPress plugin that helps diagnose and troubleshoot WooCommerce subscription issues using a systematic approach.

## What It Does

Doctor Subs analyzes WooCommerce subscriptions to identify common problems:

- **Skipped Payment Cycles**: Detects when subscription payments have missed expected billing cycles
- **Manual Completions**: Flags orders that were completed manually without proper payment processing
- **Status Mismatches**: Identifies inconsistencies between subscription status and payment schedules
- **Action Scheduler Issues**: Reviews scheduled events for failures or missing actions
- **Timeline Analysis**: Creates visual timelines showing renewal patterns and missing payments

## Installation

### Download from GitHub Releases

1. **Visit the GitHub Releases page**: [Doctor Subs v1.0.0](https://github.com/davidrukahu/doctor-subs/releases/tag/v1.0.0)
2. **Download the plugin**: Click on `doctor-subs.zip` in the Assets section
3. **Upload to WordPress**: 
   - Go to **Plugins > Add New > Upload Plugin**
   - Choose the downloaded `doctor-subs.zip` file
   - Click **Install Now**
4. **Activate the plugin**: Click **Activate Plugin**
5. **Access the tool**: Navigate to **WooCommerce > Doctor Subs**

### Manual Installation (Alternative)

1. Download and extract the plugin files
2. Upload the `doctor-subs` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Access via **WooCommerce > Doctor Subs**

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WooCommerce 9.8.5+
- WooCommerce Subscriptions (latest version)

## How to Use

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

## Common Issues Detected

- Missing renewal orders
- Failed scheduled actions
- Payment method problems
- Timeline discrepancies
- Status inconsistencies
- Gateway communication issues
- Skipped payment cycles
- Manual completion flags

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
