# ARCHITECTURE.md

Technical documentation for the WooCommerce Subscriptions Tax Retrofit plugin.

## Overview

Single-file WordPress plugin (~3600 lines of PHP, ~60 functions) that migrates WooCommerce Subscriptions from tax-inclusive pricing (no separated VAT) to pre-tax + VAT format, while preserving the total price paid by customers.

**Use case**: A merchant crosses the VAT threshold and needs to retroactively add proper tax breakdown to existing subscriptions.

## File structure

```
wcs-tax-retrofit.php                — Main plugin file (all code)
uninstall.php                       — Database cleanup on plugin deletion (all sites of a network)
tests/
  README.md                         — How to run the suite, variables, measured performance
  run.sh                            — Integration test runner (disposable WordPress + database)
  cases/*.php                       — Scenarios (wp eval-file), see tests/README.md
  lib/assert.php, lib/fixtures.php  — Assertions and reference data set
.github/workflows/checks.yml        — CI: PHP syntax 7.4-8.5, translations in sync, same version everywhere
languages/
  wcs-tax-retrofit.pot              — Translation template (303 strings)
  wcs-tax-retrofit-fr_FR.po         — French translation
  wcs-tax-retrofit-en_US.po         — English translation
```

## Constants

```php
WC_TAX_RETROFIT_VERSION     = '1.4.5'
WC_TAX_RETROFIT_BATCH_SIZE  = 100       // Subscriptions per batch
WC_TAX_RETROFIT_TOLERANCE   = 0.01      // Max rounding difference (1 cent)
```

## Database options (wp_options)

| Option | Type | Description |
|--------|------|-------------|
| `wc_tax_retrofit_selected_tax_rate_id` | int | Selected WooCommerce tax rate ID |
| `wc_tax_retrofit_date_limit` | string (Y-m-d) | Cutoff date for subscription filtering |
| `wc_tax_retrofit_statuses` | array | Subscription statuses to process |
| `wc_tax_retrofit_tolerance_setting` | float | Rounding tolerance in currency units |
| `wc_tax_retrofit_executed` | bool | Whether migration has completed |
| `wc_tax_retrofit_current_offset` | int | Resume position after interruption |
| `wc_tax_retrofit_last_activity` | int (timestamp) | Last batch processing time |
| `wc_tax_retrofit_count` | int | Total number of migrated subscriptions |
| `wc_tax_retrofit_partial_count` | int | Intermediate counter for multi-batch runs |
| `wc_tax_retrofit_date` | string | Migration execution date |
| `wc_tax_retrofit_csv_users` | int[] (not autoloaded) | Registry of user IDs owning a CSV transient (needed to delete them under a persistent object cache) |
| `wc_tax_retrofit_lock` | string `timestamp:token` | Atomic execution lock (row inserted with `INSERT IGNORE`, 10 min expiry, heartbeat) — replaces the `wc_tax_retrofit_running` transient |
| `wc_tax_retrofit_bg_state` | array (not autoloaded) | State of the background migration (status, offset, counters, capped error lists) |

Post meta on migrated subscriptions / renewals: `_wc_tax_retrofit_original` (original amounts of lines, coupons, totals, prices-include-tax, linked unpaid renewals) — read by `revert`.

## Configuration priority

Consistent across all 3 main getters: the DB option always takes priority; the filter is only a fallback.

```
DB option  →  (auto-detection for date)  →  PHP filter (fallback)  →  constant/default
```

- `get_selected_rate()`: DB `selected_tax_rate_id` → filter `wc_tax_retrofit_rate` → 0.20
- `get_date_limit()`: DB `date_limit` → auto-detection → filter `wc_tax_retrofit_date_limit` → `current_time('Y-m-d')`
- `get_tolerance()`: DB `tolerance_setting` → filter `wc_tax_retrofit_tolerance` → constant `WC_TAX_RETROFIT_TOLERANCE`

## Core processing flow

### `wc_tax_retrofit_process($dry_run = false, $offset = 0) : array`

Central engine used by the web interface (simulation and synchronous fallback), WP-CLI and the background task.

Two layers: `wc_tax_retrofit_process()` (permissions + nonce, web / WP-CLI) calls `wc_tax_retrofit_process_batch()` (engine, also called by the Action Scheduler task, which has no user / nonce: checks are done when the run is started).

```
1. Check dependencies (WooCommerce + Subscriptions)
2. Verify permissions (manage_woocommerce) + nonce (skipped for WP-CLI)
2b. Real run only: refuse if an order table is not InnoDB (no rollback on MyISAM)
3. Auto-resume from saved offset (real migration only, not dry-run)
4. Acquire the atomic lock (wc_tax_retrofit_acquire_lock(), released in a finally block)
5. Look up the WooCommerce tax_rate_id (selected rate first) and its tax class
6. Query wcs_get_subscriptions() with LIMIT/OFFSET
7. For each subscription:
   a. Validate (valid WC_Subscription, total > 0, items > 0)
   b. Skip if already migrated (existing tax > 0.01)
   c. For each line item:
      - Compute pre-tax = total / (1 + rate), tax = total - pre-tax
      - Same for the subtotal (before coupon discount)
      - Check per-item tolerance → skip entire subscription if exceeded
      - Report (ℹ️, no skip) if the line tax class differs from the rate's class
   d. Recurring coupon lines: discount TTC → discount HT + discount tax
   e. If changes exist and not dry_run:
      - START TRANSACTION
      - Plan first (wc_tax_retrofit_plan_order_conversion), apply after (…_apply_order_conversion),
        which saves the original values in the _wc_tax_retrofit_original meta
      - Update lines IN PLACE: set_subtotal(), set_total(), set_taxes({tax_rate_id: tax})
        (line IDs, product, tax class and all meta are preserved)
      - Update coupon lines: set_discount(), set_discount_tax()
      - set_prices_include_tax(wc_prices_include_tax()) (WCS manual renewal pricing)
      - update_taxes() + calculate_totals(FALSE) (TRUE would let WooCommerce recompute
        taxes from the customer address and overwrite the selected rate)
      - Same conversion for the unpaid renewals (pending / failed / on-hold) of the subscription
      - Check total tolerance of the subscription AND its renewals → ROLLBACK + flush caches if
        exceeded, otherwise COMMIT
      - Add order notes to the subscription and the converted renewals
   Shipping and fee lines are never modified (the total paid is preserved).
8. Save offset for resume if has_more (real migration only)
9. Store CSV data in a transient (per user_id, 24h TTL)
10. do_action('wc_tax_retrofit_after_process', $stats, $dry_run)
```

### Returned `$stats` array

```php
[
    'total'               => int|null,  // Total count (computed only when offset=0)
    'batch_size'          => int,       // Number in this batch
    'offset'              => int,       // Current offset
    'updated'             => int,       // Migrated in this batch (dry run: would be migrated)
    'renewals_updated'    => int,       // Unpaid renewals converted with them
    'skipped'             => int,       // Skipped (already migrated, tolerance, validation)
    'errors'              => int,       // Errors in this batch
    'has_more'            => bool,      // More batches to process
    'details'             => string[],  // Per-subscription detail messages
    'errors_list'         => string[],  // Error messages
    'tolerance_warnings'  => string[],  // Rounding difference alerts
    'csv_data'            => array[],   // Data for CSV export
    'locked'              => bool,      // (optional) another run holds the lock: nothing was processed
    'message'             => string,    // (with 'locked') message to display
    'fatal'               => bool,      // (optional) blocking error (tax rate not found, non-InnoDB tables):
                                        //   nothing was processed, callers must NOT mark the migration as executed
]
```

## Function organization

### Lock, safety checks, conversion, revert, background (1.4.5)

| Function | Purpose |
|----------|---------|
| `acquire_lock()` / `refresh_lock(&$token)` / `release_lock($token)` | Atomic lock: row in `wp_options` inserted with `INSERT IGNORE`, token `timestamp:random`, 10 min expiry taken over by compare-and-swap, heartbeat at every subscription, released only by its owner |
| `is_locked()` / `force_release_lock()` | Lock state read straight from the database (never a stale object-cache value) / manual reset |
| `get_non_transactional_tables()` | Order tables that are not InnoDB (`information_schema`), filter `wc_tax_retrofit_non_transactional_tables` |
| `plan_order_conversion($order, $rate, $tolerance, $class)` | Computes the conversion (lines, subtotals, coupons, VAT) without modifying anything |
| `apply_order_conversion($order, $plan, $tax_rate_id, $rate)` | Applies it in place and saves the originals in `_wc_tax_retrofit_original` (inside a transaction) |
| `get_unpaid_renewals($subscription)` | Renewals `pending` / `failed` / `on-hold` still without VAT |
| `process_batch()` / `process_locked_batch()` | Engine (lock, resume, batch); `process()` only adds permission and nonce checks |
| `get_migrated_subscription_ids()` | Subscriptions carrying `_wc_tax_retrofit_original` |
| `revert_order($order, $dry_run)` / `revert_subscription($id, $dry_run, $by)` | Restores the original amounts; refuses an object modified since the migration |
| `background_available()`, `bg_start()`, `bg_run_batch()`, `bg_cancel()`, `bg_get_state()`, `bg_is_running()`, `bg_schedule()` | Background migration through Action Scheduler (hook `wc_tax_retrofit_bg_batch`) |
| `bg_render_progress()` / `bg_render_summary()` | Admin progress page (auto-refresh) and final summary |

### Validation

| Function | Purpose |
|----------|---------|
| `validate_tolerance($tolerance, $default)` | Float between 0.001 and 1.0 |
| `validate_statuses($statuses, $default)` | Array of valid WooCommerce Subscriptions statuses |
| `validate_date($date)` | Strict Y-m-d format + real calendar date |
| `validate_float($value, $min, $max)` | Float within a range |
| `validate_subscription($subscription)` | Valid WC_Subscription with total > 0 and items > 0 |

### Configuration (getters with static cache)

| Function | Returns | Cache |
|----------|---------|-------|
| `get_selected_rate()` | float (0.20) | `static $cached` |
| `get_selected_rate_percent()` | float (20.0) | via `get_selected_rate()` |
| `get_date_limit()` | string (Y-m-d) | `static $cached` |
| `get_tolerance()` | float (0.01) | `static $cached` |
| `get_subscription_statuses()` | array | `static $cached` |
| `get_all_tax_rates()` | array | none |
| `get_tax_rate_id()` | int\|null | `static $cached` + `$cached_resolved` |
| `count_subscriptions()` | int | `static $cached` |

### Automatic date detection

| Function | Purpose | Cache |
|----------|---------|-------|
| `detect_no_tax_date($order)` | Scans 50 subscriptions for one without tax | `static $cache` + 5 min transient |
| `detect_last_no_tax_date()` | Wrapper (DESC order) | via `detect_no_tax_date` |
| `detect_first_no_tax_date()` | Wrapper (ASC order) | via `detect_no_tax_date` |

### Tax rate lookup: `get_tax_rate_id()`

Searches `{prefix}_woocommerce_tax_rates` in 5 steps:

0. Rate selected in the UI / WP-CLI (`wc_tax_retrofit_selected_tax_rate_id`) if it still exists
1. Match FR + standard class + `ABS(tax_rate - target) < 0.01`
2. All countries + standard class + same tolerance
3. WooCommerce API `WC_Tax::find_rates()` (may fail if called too early)
4. Debug: log all available rates

Result is cached with a `static` variable and a `$cached_resolved` flag to handle the `null` case.

### Web interface

| Function | ~Lines | Purpose |
|----------|--------|---------|
| `admin_page()` | ~900 | Full admin page: config, simulation, migration, reset |
| `admin_menu()` | 8 | Registers the WooCommerce submenu |
| `display_results($stats, $dry_run)` | ~130 | Renders results with progress bar and auto-continue |
| `export_csv()` | ~50 | Generates CSV download (hooked on admin_init) |
| `require_capability()` | 8 | Checks `manage_woocommerce` |
| `activation_notice()` | ~25 | Activation notice |

### Admin actions (inside `admin_page()`)

The web interface handles 8 distinct POST actions, each checked with a nonce:

- `save_date` — Save tax rate + cutoff date
- `save_settings` — Save statuses + tolerance
- `full_reset` — Full migration reset (also stops the background run and releases the lock)
- `clear_csv` — Delete cached CSV data
- `dry_run` — Run simulation (synchronous)
- `confirm_update` — Run real migration (background run, or synchronous fallback)
- `bg_cancel` — Stop the background migration after the current batch
- `bg_dismiss` — Hide the final summary of a background migration

`dry_run` and `confirm_update` share the nonce `wc_tax_retrofit_nonce`.

### Batch auto-continuation

- **Simulation and synchronous fallback**: when `has_more` is true, `display_results()` generates a hidden form with a JavaScript countdown (3 seconds) that automatically submits the next batch.
- **Real migration (default)**: runs in the background (Action Scheduler). The page shows the progress and refreshes itself every 5 seconds; closing it does not interrupt the migration. Needs WP-Cron or a real cron, like every WooCommerce task. Disabled with the filter `wc_tax_retrofit_use_background`.

## WP-CLI

### Class `WC_Tax_Retrofit_CLI_Command`

Registered as `wp tax-retrofit` via `WP_CLI::add_command()`.

| Command | Purpose |
|---------|---------|
| `config` | Display or modify configuration (--tax-rate, --date-limit, --tolerance, --statuses). Unknown rate / invalid value = error (exit code 1) |
| `simulate` | Loop through all batches in dry-run mode. `--format=json` (`--json` alias), temporary --tax-rate/--date-limit/--tolerance validated first and restored afterwards |
| `migrate` | Loop through all batches in real mode. Requires --yes-i-have-a-backup. Interactive confirmation unless --skip-confirm. Refused if locked, running in the background or on non-InnoDB tables |
| `revert` | Restore the original amounts (`--dry-run`, `--subscription=<id>`, --yes-i-have-a-backup, --skip-confirm). One transaction per subscription; refuses an object modified since the migration |
| `stats` | Offset, last activity, status (running/stopped), background progress, execution date and count |
| `reset` | Delete all migration options, background state and CSV, release the lock (identical to the web reset) |

**Key differences between CLI and Web**:

- CLI loops internally (`do { ... } while ($stats['has_more'])`) instead of using the JavaScript auto-refresh
- CLI bypasses nonce verification (`defined('WP_CLI') && WP_CLI`)
- `simulate` saves/restores DB options for temporary --tax-rate, --date-limit, and --tolerance overrides

## Security

### Systematic checks

1. **Capability**: `current_user_can('manage_woocommerce')` on all actions
2. **Nonce**: one nonce per action (7 distinct nonces for the admin page forms, plus the AJAX counter and the CSV export)
3. **Sanitization**: `sanitize_text_field()`, `intval()`, `floatval()` on all inputs; `array_map('sanitize_text_field', ...)` on array inputs
4. **SQL**: `$wpdb->prepare()` on every query, no direct interpolation
5. **Output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()` as appropriate
6. **ABSPATH check**: `if (!defined('ABSPATH')) exit;` at line 17

### Concurrency lock

Option `wc_tax_retrofit_lock` (see *Lock, safety checks…* above): atomic, with a 10-minute expiry and a heartbeat, released in a `finally` block. If processing is already running, `process()` returns a full `$stats` array with `'locked' => true` and all counters set to 0. A transient is **not** used: `get_transient()` + `set_transient()` is not atomic and the key can be evicted from Redis/Memcached.

## Data integrity

### SQL transactions

Each modified subscription is wrapped in `START TRANSACTION` / `COMMIT` / `ROLLBACK`. Rollback triggers if:

- The total price difference after migration >= tolerance, for the subscription or for one of its converted renewals (one transaction for all of them)
- A `\Throwable` is thrown during item manipulation (covers `Exception`, `TypeError`, `Error`)

After every `ROLLBACK`, `flush_subscription_cache()` clears the object caches of the objects involved. The real run, `revert` and the background run are **refused** if an order table is not InnoDB (on MyISAM, `START TRANSACTION` / `ROLLBACK` are silently ignored).

### Double tolerance check

1. **Per item**: `abs((pre_tax + tax) - original_total) >= tolerance` → skip the entire subscription (`continue 2`)
2. **Per subscription** (after save): `abs(new_total - original_total) >= tolerance` → ROLLBACK

### Resume after interruption

- After each real migration batch, the offset is saved to `wc_tax_retrofit_current_offset`
- On restart, if `$offset === 0` and a saved offset exists, processing resumes automatically (web, CLI and background alike: callers must display / continue from `$stats['offset']`, the offset actually processed)
- `!$dry_run` guard: simulation always starts at offset 0
- Background run: the state (`wc_tax_retrofit_bg_state`) holds the offset and the counters; a batch that finds the lock held is retried 30 seconds later

## Available hooks

### Filters

```php
// Override the tax rate (decimal, e.g. 0.055 for 5.5%)
add_filter('wc_tax_retrofit_rate', fn($rate) => 0.20);

// Override the cutoff date
add_filter('wc_tax_retrofit_date_limit', fn($date) => '2024-01-15');

// Override the rounding tolerance
add_filter('wc_tax_retrofit_tolerance', fn($t) => 0.02);

// Override which subscription statuses to process
add_filter('wc_tax_retrofit_subscription_statuses', fn($s) => ['active', 'on-hold']);
```

Behaviour filters (since 1.4.5):

```php
add_filter('wc_tax_retrofit_use_background', '__return_false');           // synchronous web migration
add_filter('wc_tax_retrofit_process_unpaid_renewals', '__return_false');  // leave unpaid renewals untouched
add_filter('wc_tax_retrofit_renewal_statuses', fn($s) => ['pending', 'failed']);  // default: pending, failed, on-hold
add_filter('wc_tax_retrofit_non_transactional_tables', '__return_empty_array');   // disables the InnoDB check (not recommended)
```

### Action

```php
// Intercept results after each batch
add_action('wc_tax_retrofit_after_process', function($stats, $dry_run) {
    // $stats contains updated, renewals_updated, skipped, errors, tolerance_warnings, csv_data...
}, 10, 2);
```

## Plugin lifecycle

### Activation (`register_activation_hook`)

- Sets transient `wc_tax_retrofit_activation_notice` (60s TTL)
- Logs "Plugin activated"

### Deactivation (`register_deactivation_hook`)

- Releases the lock (`wc_tax_retrofit_lock`, and the legacy `wc_tax_retrofit_running` transient of ≤ 1.4.4)
- Stops the background migration: unschedules the Action Scheduler tasks (hook only, see below) and deletes `wc_tax_retrofit_bg_state`
- Deletes date detection transients (`detect_date_desc`, `detect_date_asc`)
- Deletes intermediate data (`partial_count`, `current_offset`, `last_activity`)
- Deletes the activation notice
- Cleans all CSV transients via `wc_tax_retrofit_delete_all_csv_transients()`: `delete_transient()` for each user of the `csv_users` registry, every WooCommerce manager and user 0 (WP-CLI), then a SQL `LIKE` safety net
- Keeps the configuration (rate, date, statuses, tolerance) and the execution state

### Uninstall (`uninstall.php`)

Runs on **every site** of a multisite network (`get_sites()` + `switch_to_blog()`):

- Deletes ALL `wc_tax_retrofit_*` options (13 options, including the `csv_users` registry, the lock and the background state)
- Unschedules the background tasks (`as_unschedule_all_actions('wc_tax_retrofit_bg_batch')`)
- Deletes all `wc_tax_retrofit_*` transients (legacy lock, activation notice, date detection)
- Deletes all per-user CSV transients, same strategy as deactivation (the plugin is not loaded during uninstall, so the logic is duplicated in `uninstall.php`)

**Persistent object cache (Redis Object Cache, Memcached…):** transients are stored in the cache, not in `wp_options`, so a SQL `LIKE` query cannot find them. Always delete transients through `delete_transient()` with known keys — this is why the `csv_users` registry exists.

Intentionally **kept**: migrated subscription data, `[Tax Retrofit vX.Y.Z]` order notes, the `_wc_tax_retrofit_original` meta (original amounts: proof of the previous state, and what `revert` needs if the plugin is reinstalled) and WooCommerce logs (source `wcs-tax-retrofit`) — fiscal audit trail.

## Revert, background processing, tests

- **`wp tax-retrofit revert`** (`wc_tax_retrofit_revert_subscription()` / `…_revert_order()`): one transaction per subscription (+ its converted unpaid renewals); refuses an object modified since the migration; always use `$order->get_item($id, false)` for writes (the default reloads a detached object that `save()` ignores); final check compares each line to the original values.
- **Background migration**: `wc_tax_retrofit_bg_start()` → Action Scheduler task `wc_tax_retrofit_bg_batch` (one batch per task, next one scheduled at the end) → state in `wc_tax_retrofit_bg_state`. Cancel with `as_unschedule_all_actions($hook)` **without group/args** (with empty args, AS only matches tasks whose args are empty).
- **Tests**: `tests/run.sh` (see `tests/README.md`). Run it before every release: it covers posts storage + HPOS, Redis, Index WP MySQL For Speed, multisite, rollback, lock, revert, background, and a renewal order created after the migration (`09-renewal`). It needs the paid WooCommerce Subscriptions, so CI (`.github/workflows/checks.yml`) only runs the fast checks.
- **Performance** (measured, 237 subscriptions, PHP 8.3): ~17 ms and ~117 SQL queries per subscription, 2 saves per subscription, flat memory between batches. Flushing the in-memory cache between batches was tried and had no measurable effect, so it is not done. The test runner deletes the `mu-plugins/index-wp-mysql-for-speed-update-filter.php` file left by that plugin when it rebuilds a site (it otherwise prevents WooCommerce from creating its `*_meta` tables on a fresh database).

## Dependencies and loading

```
PHP file loads (BEFORE WooCommerce and Subscriptions: WordPress loads plugins alphabetically)
  └─ ABSPATH check (exit if missing)
  └─ define(), functions, hooks, CLI — always registered
  └─ admin_notices → wc_tax_retrofit_dependency_notice()
       └─ wc_tax_retrofit_check_dependencies_early()
            ├─ PHP >= 7.4 ?
            ├─ class_exists('WooCommerce') ?
            └─ function_exists('wcs_get_subscriptions') ?
  └─ before_woocommerce_init → declare HPOS (custom_order_tables) compatibility
```

**Never check dependencies at file load time.** `wcs-tax-retrofit/` sorts before `woocommerce/` and `woocommerce-subscriptions/` in `active_plugins`, so they are not loaded yet; an early `return` (as in ≤ 1.4.4) disables the whole plugin. Each entry point (admin menu, AJAX, CSV export, process, WP-CLI commands) calls `wc_tax_retrofit_check_dependencies()` at runtime instead. Dependency messages are built in `admin_notices` (after `init`) to comply with WordPress 6.7+ translation loading rules.

## Internationalization

- Text domain: `wcs-tax-retrofit`
- Loaded via `plugins_loaded` hook → `load_plugin_textdomain()`
- 303 translatable strings
- Any change to `__()` strings must be reflected in all 3 .po/.pot files
- Compiled `.mo` files are in `.gitignore`
