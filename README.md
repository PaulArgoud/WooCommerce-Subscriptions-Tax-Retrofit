# WooCommerce Subscriptions Tax Retrofit

[![Version](https://img.shields.io/badge/version-1.4.5-blue.svg)](CHANGELOG.md)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B%20%7C%20tested%207.1-21759b.svg?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B%20%7C%20tested%2011.3-96588a.svg?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![WooCommerce Subscriptions](https://img.shields.io/badge/WC%20Subscriptions-tested%209.4.1-96588a.svg)](https://woocommerce.com/products/woocommerce-subscriptions/)
[![HPOS](https://img.shields.io/badge/HPOS-compatible-success.svg)](https://developer.woocommerce.com/docs/features/high-performance-order-storage/)
[![WP-CLI](https://img.shields.io/badge/WP--CLI-supported-success.svg)](#wp-cli)
[![Multisite](https://img.shields.io/badge/multisite-supported-success.svg)](#uninstall)
[![Translations](https://img.shields.io/badge/i18n-fr__FR%20%7C%20en__US-informational.svg)](#translations)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL%20v2%2B-blue.svg)](LICENSE)

Retrofits existing WooCommerce Subscriptions stored as **tax-inclusive with no separated VAT** into **pre-tax + VAT**, while preserving the exact total paid by customers.

**Typical use case:** you just crossed the VAT registration threshold and must show VAT on the invoices of your existing subscriptions, without changing what your customers pay.

## Requirements

| Component | Minimum | Tested up to |
|---|---|---|
| WordPress | 6.2 | 7.1 (single site and multisite) |
| PHP | 7.4 | 8.3 (integration tests); syntax checked on 7.4 to 8.5 |
| WooCommerce | 8.0 | 11.3 (posts storage and HPOS) |
| WooCommerce Subscriptions | — | 9.4.1 |
| Database | InnoDB tables (SQL transactions are used for rollback) | MySQL 8.0 |

Also tested with **Redis Object Cache** (persistent object cache) and **Index WP MySQL For Speed** (high-performance keys).

## Installation

### Via the WordPress admin

1. Download the [latest release](../../releases/latest) ZIP
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Select the ZIP file, install and activate (WooCommerce must be active)

### Manual installation

Download and extract into `wp-content/plugins/wcs-tax-retrofit/`

### Via WP-CLI

```bash
wp plugin install wcs-tax-retrofit.zip --activate
```

## Try it on a copy of your shop first

The automated tests use synthetic data. Before the real migration, run it once on a **copy of your production site** (staging), with your real gateways and plugins:

1. Copy the database and the files to a staging site, and **disable outgoing payments and emails** (put the gateways in test mode, or use a mail catcher). Never run a staging copy with live Stripe / PayPal keys.
2. `wp tax-retrofit simulate --format=json`: no tolerance warning, no error, and the number of subscriptions matches your expectation.
3. `wp tax-retrofit migrate --yes-i-have-a-backup`, then open 3 or 4 different subscriptions (with a coupon, with shipping, a customer abroad): total unchanged, VAT shown.
4. **Create a renewal** for one of them (*Subscription → Actions → Process renewal*) and check that its total is identical and that VAT appears.
5. Check the next payment amount on your gateway side (for example a Stripe subscription): it must not have changed.
6. Run `wp tax-retrofit revert --dry-run` then `revert` once to make sure you can go back, and migrate again.
7. Only then, make a fresh backup of production and migrate for real.

## What the migration changes

For each subscription matching the selected statuses, created on or before the cutoff date, and **without any VAT yet**:

| Element | Before | After (20% example) |
|---|---|---|
| Product line | 30.00 incl. tax, no VAT | 25.00 + 5.00 VAT (selected tax rate) |
| Product line subtotal (before coupon) | 30.00 | 25.00 + 5.00 VAT |
| Recurring coupon | −3.00 | −2.50 + −0.50 VAT |
| Shipping and fees | 5.00 | **unchanged** |
| **Total paid by the customer** | **35.00** | **35.00 (identical)** |

- Lines are converted **in place**: line IDs, products, tax classes and all metadata are preserved
- The subscription's *prices include tax* flag is aligned with your store setting, so manual renewals are charged correctly
- Each subscription is processed in its own SQL transaction: if the new total differs from the original by more than the tolerance, the change is **rolled back** and the subscription is skipped
- **Unpaid renewals** (pending, failed, on-hold) created before the migration are converted in the same transaction; paid renewals are never touched
- A `[Tax Retrofit vX.Y.Z]` note is added to every migrated subscription
- The original amounts are saved on each subscription: the migration can be **reverted** (`wp tax-retrofit revert`) without restoring a backup
- Already migrated subscriptions (VAT > 0.01) are skipped: running the migration twice is safe
- The migration is **refused** if an order table is not InnoDB (no rollback possible on MyISAM)

## Usage

### Web interface (recommended)

1. **Backup**: make a full database backup (NON-NEGOTIABLE)
2. Go to **WooCommerce → Tax Retrofit**
3. Configure:
   - Tax rate (selected from your WooCommerce tax rates)
   - Cutoff date (automatic detection available)
   - Subscription statuses to process and rounding tolerance
4. Run a **simulation** and review the results (no subscription is modified)
5. If everything looks good, run the **real migration**: it runs **in the background** (WooCommerce Action Scheduler), so you can close the page; a progress page lets you follow or stop it
6. Download the CSV export for your records

### WP-CLI

```bash
# Display / change the configuration
wp tax-retrofit config
wp tax-retrofit config --tax-rate=20 --date-limit=2024-01-01 --tolerance=0.01 --statuses=active,on-hold

# Simulation (dry-run)
wp tax-retrofit simulate

# Simulation with temporary values (restored afterwards, never saved)
wp tax-retrofit simulate --tax-rate=5.5 --date-limit=2024-06-01 --tolerance=0.02

# Simulation with JSON output (--json is an alias)
wp tax-retrofit simulate --format=json > simulation.json

# Real migration (AFTER BACKUP!) — asks you to type "OUI" unless --skip-confirm
wp tax-retrofit migrate --yes-i-have-a-backup

# Execution state and progress
wp tax-retrofit stats

# Revert the migration (restores the original amounts) — check first with --dry-run
wp tax-retrofit revert --dry-run
wp tax-retrofit revert --yes-i-have-a-backup

# Reset the migration state (does not revert migrated subscriptions)
wp tax-retrofit reset
```

| Command | Description |
|---|---|
| `config` | Display or change the configuration |
| `simulate` | Dry-run of every batch, optional temporary `--tax-rate` / `--date-limit` / `--tolerance`, `--format=table\|json` |
| `migrate` | Real migration of every batch, `--yes-i-have-a-backup` required, `--skip-confirm`, `--offset=<n>` |
| `revert` | Restores the original amounts of migrated subscriptions (`--dry-run`, `--subscription=<id>`); refuses a subscription modified since the migration |
| `stats` | Offset, last activity, lock state, background progress, execution date and number of migrated subscriptions |
| `reset` | Clears the execution state, resume data and CSV |

Every invalid value (unknown tax rate, invalid date, tolerance out of range) stops the command with exit code `1`, so scripts can rely on it. An interrupted migration (web or CLI) resumes automatically from the last saved batch.

## Features

### Web interface
- Batch processing (100 subscriptions per batch) in the background (Action Scheduler) with progress bar and stop button
- Simulation (dry-run) before execution, with the same calculation as the real run
- Any tax rate configured in WooCommerce (France, Belgium, Switzerland, reduced rates…)
- Automatic resume after interruption
- Configurable rounding tolerance and double verification (per line, per subscription)
- Detailed WooCommerce logs (source `wcs-tax-retrofit`)
- CSV export of all modifications

### WP-CLI
- Full command-line support, ideal for large databases (no PHP timeout)
- Scriptable (CI/CD), reliable exit codes, clean JSON output

### Internationalization
- French and English interface
- POT file included for new translations (303 strings)

### Performance
- About 17 ms per subscription (237 subscriptions in 4 seconds, ~3 minutes for 10,000 extrapolated), flat memory between batches. Details in `tests/README.md`

### For developers
- 4 configuration filters + 1 action, and 4 optional behaviour filters (see below)
- Single PHP file, no build step
- Integration test suite: `tests/run.sh` (see `tests/README.md`), including a renewal created after the migration
- GitHub Actions checks on every push (PHP syntax 7.4 to 8.5, translations, version consistency)

## Available hooks

Filters are **fallbacks**: values configured in the admin page or with `wp tax-retrofit config` take priority.

```php
// Tax rate used when no rate is selected in the admin (decimal)
add_filter('wc_tax_retrofit_rate', function($rate) {
    return 0.20; // 20%
});

// Cutoff date used when none is saved and none is auto-detected
add_filter('wc_tax_retrofit_date_limit', function($date) {
    return '2024-01-15';
});

// Rounding tolerance used when none is saved
add_filter('wc_tax_retrofit_tolerance', function($tolerance) {
    return 0.02; // 2 cents
});

// Subscription statuses used when none are saved
add_filter('wc_tax_retrofit_subscription_statuses', function($statuses) {
    return array('active', 'on-hold'); // Default: active, pending-cancel, on-hold
});

// Optional behaviour switches (all default to the values shown)
add_filter('wc_tax_retrofit_use_background', '__return_true');          // false: synchronous web migration (page reloads)
add_filter('wc_tax_retrofit_process_unpaid_renewals', '__return_true'); // false: leave unpaid renewals untouched
add_filter('wc_tax_retrofit_renewal_statuses', function($statuses) {
    return array('pending', 'failed', 'on-hold');                       // renewals considered unpaid
});
add_filter('wc_tax_retrofit_non_transactional_tables', function($tables) {
    return $tables;                                                      // return array() to disable the InnoDB check (not recommended)
});

// Intercept results after each batch
add_action('wc_tax_retrofit_after_process', function($stats, $dry_run) {
    if (!empty($stats['tolerance_warnings'])) {
        error_log('Tax Retrofit: ' . count($stats['tolerance_warnings']) . ' warnings');
    }
}, 10, 2);
```

## Translations

The plugin ships with French (fr_FR) and English (en_US).

**To create a new translation:**
1. Copy `languages/wcs-tax-retrofit.pot`
2. Open it with [Poedit](https://poedit.net/) and create a new catalog for your language
3. Translate, save (generates .po + .mo), and place them in `languages/`

The plugin automatically loads the correct translation based on the WordPress locale.

## Uninstall

- **Deactivation** removes the lock, the resume data and the CSV exports, and keeps your configuration.
- **Deletion** (`uninstall.php`) removes every plugin option and transient — on **every site** of a multisite network, and also from persistent object caches such as Redis or Memcached (the CSV exports contain customer email addresses).
- Always **kept**: the migrated subscriptions, the `[Tax Retrofit]` order notes, the original amounts (`_wc_tax_retrofit_original` meta, needed by `revert`) and the WooCommerce logs (fiscal audit trail). Uninstalling does **not** revert the migration: restore your backup for that.

## Warning

**MAKE A FULL BACKUP BEFORE USE**

This plugin directly modifies your WooCommerce database. Although it has been extensively tested, a backup is ESSENTIAL before any migration.

This plugin is provided "as is", with no warranty and no technical support. Use at your own risk.

## WP-CLI recipes

### Automated migration (backup + simulation + migration)

```bash
#!/bin/bash
set -euo pipefail

wp db export "backup-before-tax-retrofit-$(date +%Y%m%d-%H%M%S).sql"
wp tax-retrofit config --tax-rate=20 --date-limit=2024-01-01

RESULT=$(wp tax-retrofit simulate --format=json)
ERRORS=$(echo "$RESULT" | jq -r '.errors')
WARNINGS=$(echo "$RESULT" | jq -r '.tolerance_warnings | length')

if [ "$ERRORS" -eq 0 ] && [ "$WARNINGS" -eq 0 ]; then
    wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    echo "Migration completed successfully"
else
    echo "Simulation reported $ERRORS error(s) and $WARNINGS tolerance warning(s): migration cancelled"
    exit 1
fi
```

### One-off migration at night

The migration is a **one-shot** operation: schedule it once (for example with `at`), never as a recurring cron job.

```bash
echo 'cd /var/www/html && wp db export /var/backups/before-tax-retrofit.sql && wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm >> /var/log/tax-retrofit.log 2>&1' | at 02:00
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[GPL v2 or later](LICENSE)
