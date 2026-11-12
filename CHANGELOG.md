# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

No change to the plugin code.

### Tests and tooling
- **Renewal after migration**: new scenario `09-renewal` creates a renewal order from each migrated subscription (`wcs_create_renewal_order()`) and checks that the total is identical, VAT is present, the prices-include-tax flag follows the store and HT + VAT = total (shipping included). 28 assertions, run on both storages
- **GitHub Actions** (`.github/workflows/checks.yml`): PHP syntax on 7.4 to 8.5, translations in sync with the code, same version in the plugin header, the constant and the changelog, syntax of `tests/run.sh`
- **Measured performance** (documented in `tests/README.md`): about 17 ms and 117 SQL queries per subscription, 2 saves per subscription, flat memory. No optimization needed; flushing the in-memory cache between batches was tried and had no measurable effect

### Documentation
- README: new section *Try it on a copy of your shop first* (staging procedure including a test renewal and a check on the payment gateway side)

## [1.4.5] - 2026-11-12

Compatibility and reliability release for **WooCommerce 11.3.0**, **WooCommerce Subscriptions 9.4.1**, **WordPress 7.1** and **PHP 7.4 → 8.5**. The bugs of the first sections were reproduced on v1.4.4 in a disposable WordPress 7.1.4 install (WC 11.3.0 + WCS 9.4.1). The automated suite (`tests/run.sh`, about 430 assertions on real amounts) covers HPOS and posts storage, single site and multisite, **Redis Object Cache 3.0.0** (persistent object cache) and **Index WP MySQL For Speed 1.6.0** (high-performance keys on every table); it is run with PHP 8.3, and the plugin is syntax-checked on PHP 7.4 and 8.5.

### New features
- **Atomic execution lock** (`wc_tax_retrofit_acquire_lock()`): the transient lock (`get_transient()` then `set_transient()`, not atomic, and evictable from Redis/Memcached when memory is full) is replaced by a row in `wp_options` inserted with `INSERT IGNORE` (unique key on `option_name`). Token `timestamp:random`, heartbeat refreshed at each subscription, 10-minute expiry with compare-and-swap takeover of a dead lock, release only by the owner. Lock reads bypass the object cache. The old transient is still cleaned up
- **Transactional tables check**: on MyISAM tables `START TRANSACTION` / `ROLLBACK` are silently ignored, so a subscription with a total mismatch would have been left modified. The real migration, `revert` and background run are now refused (error + log) if one of the order tables is not InnoDB; the simulation only warns. New filter `wc_tax_retrofit_non_transactional_tables`
- **Unpaid renewals converted**: a renewal order created *before* the migration (statuses `pending`, `failed`, `on-hold`) was copied from the subscription in tax-inclusive form with no VAT and would have been charged without VAT breakdown. They are now converted in the **same transaction** as their subscription (rolled back together if a total differs); paid renewals are never touched. New filters `wc_tax_retrofit_process_unpaid_renewals` and `wc_tax_retrofit_renewal_statuses`. New `renewals_updated` key in `$stats`, CLI output and JSON
- **`wp tax-retrofit revert`**: restores the original amounts (lines, subtotals, coupons, taxes, prices-include-tax flag) without restoring a backup. The original values are saved in the `_wc_tax_retrofit_original` meta of each migrated subscription and renewal. Options `--dry-run`, `--subscription=<id>`, `--yes-i-have-a-backup`, `--skip-confirm`. A subscription modified since the migration (deleted line, changed amount) is refused and left untouched; paid renewals are kept. One transaction per subscription, same total verification as the migration. Works only for migrations made with 1.4.5+
- **Background migration (Action Scheduler)**: the web migration now runs as Action Scheduler tasks (one batch per task), so closing the page no longer interrupts it. Progress page (auto-refresh, stop button), final summary, automatic retry if another run holds the lock, resume after interruption, and cleanup on reset / deactivation / uninstall. Requires WP-Cron or a real server cron (as all WooCommerce tasks). Disable with the filter `wc_tax_retrofit_use_background` (the previous synchronous mode is used)
- **Integration test suite** (`tests/`): `tests/run.sh` builds a disposable WordPress and runs about 430 assertions on real amounts: posts storage and HPOS, Redis Object Cache, Index WP MySQL For Speed, multisite, rollback, lock, revert, background run, uninstall, 237 subscriptions in 3 batches. The PHP binary is configurable (`PHP_BIN`). See `tests/README.md`

### Bugs found by the new test suite
- **`revert` reported success without reverting anything** (during development of this release): `$order->get_item($id)` reloads a *new* object from the database, not the one saved by `$order->save()`, so the `set_*()` calls were lost while the snapshot was deleted and the unchanged total passed the check. Now `get_item($id, false)`, and the final check compares each line to the original values
- **`revert` failed with posts storage (legacy orders in `wp_posts`)**: the list of migrated subscriptions was built with `wc_get_orders()` and a `meta_query`, an argument the legacy order data store ignores (WooCommerce only reports *"Order query argument (meta_query) is not supported on the current order datastore"*). `revert` therefore received **every** subscription, including those never migrated, and stopped with an error. Fixed with `wcs_get_orders_with_meta_query()` (WooCommerce Subscriptions, handles both storages), plus a guard that keeps only subscriptions actually carrying the saved original values. Found because the suite runs every scenario on both storages: only HPOS would have been tested otherwise
- **`as_unschedule_all_actions()` left tasks in the queue**: with a hook, a group and empty arguments, Action Scheduler only cancels tasks whose arguments are also empty. Tasks carry `{"batch":N}` and stayed pending after stop / reset / deactivation / uninstall. Now called with the hook only (specific to this plugin)

### Blocking (P0)
- **Plugin never loaded on a standard install**: the dependency check ran when the plugin file was included and `return`ed if `WooCommerce` / `wcs_get_subscriptions()` did not exist yet. WordPress loads plugins in alphabetical order (`activate_plugin()` sorts `active_plugins`), so `wcs-tax-retrofit/` is always loaded **before** `woocommerce-subscriptions/` and `woocommerce/`: the check always failed, the "missing dependencies" notice was shown, and no hook was registered (no admin menu, no AJAX, no CSV export, `wp tax-retrofit` → *"is not a registered wp command"*). The check now runs at display time in `wc_tax_retrofit_dependency_notice()` (`admin_notices`); every entry point (menu, AJAX, export, WP-CLI) already re-checks dependencies at runtime with `wc_tax_retrofit_check_dependencies()`
- **`wp tax-retrofit migrate --yes-i-have-a-backup` always rejected**: the docblock declared the flag as `--yes-i-have-a-backup` (without brackets), an invalid WP-CLI synopsis (*"invalid synopsis part"* then *"unknown --yes-i-have-a-backup parameter"*). The documented command had therefore never worked. Declared as `[--yes-i-have-a-backup]`; the mandatory check is still done in `migrate()`

### Critical (P1)
- **Migration marked as "executed" although nothing was processed**: the web interface and `wp tax-retrofit migrate` saved `wc_tax_retrofit_executed = true` whenever the last batch had no `has_more`, even when the batch had been refused (another run holding the lock) or had stopped on a blocking error (tax rate not found in WooCommerce). The admin page then showed *"Déjà exécuté"*, hid the migration screen and recorded 0 migrated subscriptions. The engine now returns `fatal` (blocking error) or `locked`, and neither the web interface nor the CLI mark the migration as done in that case (the CLI stops with an error, exit code 1)
- **`calculate_totals()` overwrote the plugin's VAT**: `$subscription->calculate_totals()` (default `true`) calls `calculate_taxes()`, which recalculates every line's tax from the customer address and the WooCommerce tax settings, discarding the amounts and `tax_rate_id` set by the plugin. On v1.4.4, 3 out of 7 test subscriptions were rolled back although the simulation announced them as migratable: non-French customer (WC applied 21% → 30.25 €), shipping line (shipping got taxed → 36.00 €), 3 × 9.99 € (rounding → 29.98 €). Now `calculate_totals(false)`: tax lines are built by `update_taxes()` from the plugin's own amounts. Shipping and fees are left untouched, so the total paid stays identical
- **Recurring coupon discount erased**: lines were recreated with `subtotal = total`, losing the pre-discount subtotal. Discount total dropped to 0 while the coupon line still showed the TTC discount. The subtotal is now converted too (`subtotal_ht` + `subtotal_tax`), and coupon lines are split into HT discount + VAT discount (`set_discount()` / `set_discount_tax()`). Example: 30 € → 27 € with coupon now gives subtotal 25 + 5, total 22.50 + 4.50, coupon 2.50 + 0.50
- **Manual renewals charged excl. tax**: WCS 9.4 rebuilds the renewal cart price from `line_subtotal` (excl. tax) and only adds VAT back when `$subscription->get_prices_include_tax()` is true. Migrated subscriptions kept `prices_include_tax = false` from the pre-VAT era, so on a store entering prices incl. tax, a manual renewal (or failed payment retried through checkout) could be charged the HT amount. The flag is now aligned with the store setting (`wc_prices_include_tax()`) during migration
- **`wp tax-retrofit simulate` could permanently change the configuration**: temporary `--tax-rate` / `--date-limit` / `--tolerance` values are written to the options during the simulation and restored at the end. When the simulation stopped on the concurrency lock (*"Une mise à jour est déjà en cours"*), `WP_CLI::error()` exits immediately and the restore code never ran: the temporary values stayed saved (reproduced: rate switched to BE 21% and date limit to 2025-01-01), and the next **real** migration would have used them. The lock is now checked before anything is modified, and options are restored before any `WP_CLI::error()` inside the batch loop
- **Wrong tax rate possibly applied**: `get_tax_rate_id()` ignored the rate selected in the UI and searched by percentage only, so another 20% rate (other country or tax class) could be used. The selected `wc_tax_retrofit_selected_tax_rate_id` now has priority (new step 0); the percentage search remains as a fallback

### Robustness (P1)
- **In-place line conversion instead of delete/recreate**: lines are now updated in place (`set_subtotal()`, `set_total()`, `set_taxes()`). Line item IDs, product, **tax class** (lost in v1.4.4), all meta (including internal) are preserved, and no item-deletion hook is fired (e.g. WCS download permission revocation queue)
- **Cache flush after ROLLBACK (defensive)**: `ROLLBACK` reverts SQL writes but not object caches (order items, HPOS `OrderCache`, post cache). New `wc_tax_retrofit_flush_subscription_cache()` called after every `ROLLBACK`. Note: with WC 11.3 + WCS 9.4.1 + Redis Object Cache, no stale data could be reproduced even without this flush (WooCommerce invalidates its caches on every `save()`, including with a third-party hook re-reading the subscription after save); the flush is kept as a cheap safeguard against other caching layers and future WooCommerce changes
- **`memory_limit` could be lowered**: `ini_set('memory_limit', '512M')` reduced the limit on servers configured above 512M. Replaced with `wp_raise_memory_limit('admin')` (never lowers it) and `wc_set_time_limit()`
- **Subscription count loaded every subscription in memory**: WCS 9.x `wcs_get_subscriptions()` ignores `'return' => 'ids'` and instantiates every subscription object. `wc_tax_retrofit_count_subscriptions()` (AJAX counter, progress bar) now uses `wc_get_orders()` with `'paginate' => true` (a single COUNT query, posts storage and HPOS)

### Compatibility (P1)
- **PHP 8.4 / 8.5 `fputcsv()` deprecation**: `fputcsv()` without an explicit `$escape` argument emits *"the $escape parameter must be provided as its default value will change"* on PHP 8.4+ (WooCommerce 11.3 supports PHP 8.4 and 8.5). With `display_errors` on, the notice ended up inside the downloaded CSV. `$escape` is now passed explicitly (`'\\'`, same output as before)
- **HPOS compatibility declared**: `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` on `before_woocommerce_init`. Required now that the plugin declares `WC tested up to` (WooCommerce would otherwise list it as "uncertain" on the HPOS settings screen)
- **Translations loaded too early (WordPress 6.7+)**: `__()` calls in the load-time dependency check triggered *"Translation loading for the wcs-tax-retrofit domain was triggered too early"*. The messages are now built in `admin_notices`
- **Plugin header**: added `Requires Plugins: woocommerce`, `WC requires at least: 8.0`, `WC tested up to: 11.3`; `Requires at least` raised from 5.0 to 6.2 (minimum WordPress for WooCommerce 8.0)

### Uninstall & deactivation (P1)
- **CSV exports left in Redis / Memcached (personal data)**: CSV transients (`wc_tax_retrofit_csv_{user_id}`, containing customer IDs and **email addresses**) were only deleted by a `DELETE … LIKE` query on `wp_options`. With a persistent object cache (e.g. Redis Object Cache), transients are not stored in `wp_options` at all, so uninstall and deactivation never removed them. The plugin now keeps a registry of users owning a CSV (new option `wc_tax_retrofit_csv_users`, not autoloaded) and deletes each transient through the API (`delete_transient()`), plus WooCommerce managers and user 0 (WP-CLI); the SQL query is kept as a safety net. Shared helper `wc_tax_retrofit_delete_all_csv_transients()` used by deactivation. Verified with Redis: v1.4.4 left 5 keys (3 CSV), v1.4.5 leaves none
- **Multisite: only the current site was cleaned**: options and transients are per site, but `uninstall.php` only cleaned the site running the uninstall. It now iterates over every site (`get_sites()` + `switch_to_blog()`). Verified on a 2-site network
- **New data cleaned too**: the lock (`wc_tax_retrofit_lock`), the background state (`wc_tax_retrofit_bg_state`) and the scheduled Action Scheduler tasks are removed on deactivation (state and tasks) and on uninstall (13 options in total)
- **`uninstall.php` documents what is intentionally kept**: migrated subscription data, `[Tax Retrofit]` order notes, the `_wc_tax_retrofit_original` meta (original amounts, needed by `revert`) and WooCommerce logs (fiscal audit trail)

### WP-CLI (P2)
- **`wp tax-retrofit config --tax-rate=<unknown>` exited with code 0**: an unknown rate only printed a warning, so scripts could not detect the failure. Now `WP_CLI::error()` (exit code 1). The accepted range now matches the error message (0.1 to 100)
- **`simulate` silently ignored invalid options**: an unknown `--tax-rate` fell back to the configured rate (the simulation ran with another rate than requested), an invalid `--date-limit` or `--tolerance` was ignored without any message. All options are now validated before anything runs, with an error (exit code 1)
- **CLI CSV export grew forever**: each `simulate` / `migrate` appended to the same CSV transient (user 0) without clearing it, mixing simulations and real runs (reproduced: 7 → 14 → 21 → 28 rows). Now cleared at the start of each run, like the web interface. `reset` also clears it, like the web reset
- **Wrong offset after an automatic resume**: `process()` resumes from the saved offset of an interrupted migration, but `migrate` / `simulate` kept their own counter: output showed *"Batch offset 0"* while batch 200 was processed, and already processed batches could be run again. The next offset now comes from `$stats['offset']`
- **Migrated count lost batches from an interrupted migration**: `migrate` saved `wc_tax_retrofit_count` from its own batches only, ignoring `wc_tax_retrofit_partial_count` (batches already done through the web interface), and did not update it while running. Now handled like the web interface: partial count updated after each batch, final count = partial + last batch
- **`wp help tax-retrofit` showed a block of `=====`**: WP-CLI used the file comment above the class as the command description. Added a proper class docblock with examples
- **`stats` now shows the execution state**: executed flag, date and number of migrated subscriptions (or the partial count of an ongoing migration), plus the progress of a background run

### Fixes (P2)
- **`wp tax-retrofit simulate --json` rejected**: WP-CLI converts `--json` into `--format=json` (backward compatibility), which the command did not declare (*"unknown --format parameter"*). Added `[--format=<format>]` (`table` | `json`); `--json` keeps working. In JSON mode, progress lines are no longer printed before the JSON, so the output can be parsed by scripts (README CI example)
- **WP-CLI `--tax-rate` picked an arbitrary rate**: `config` and `simulate` used `LIMIT 1` without ordering, so a reduced-rate or foreign rate with the same percentage could be selected. New `wc_tax_retrofit_find_rate_id_by_percent()`: standard class first, then France, then WooCommerce priority
- **Simulation showed a wrong excl. tax amount**: the dry-run displayed `total ÷ (1 + rate)`, wrong as soon as shipping or fees exist (35 € with 5 € shipping showed 29.17 € instead of 30.00 €). Now `total − extracted VAT`, the same calculation as the real migration
- **Tax class mismatch reported**: when a line's tax class differs from the applied rate's class (e.g. `reduced-rate` line, standard 20% rate), an `ℹ️` line is added to the details and logged (the subscription is still migrated, as in previous versions)
- **Duplicate `msgid` in translation files**: `"modifiable via filtre"` was defined twice in `.pot`, `fr_FR.po` and `en_US.po`, so `msgfmt` refused to compile the `.mo` files (*"duplicate message definition"*). Duplicate removed

### Consistency (P2)
- **Unescaped admin URLs**: 5 `admin_url()` links in the tax rate help and technical info sections were output without `esc_url()`. Fixed
- **Remaining unescaped outputs**: translated string containing HTML (`Ils sont comptés dans %1$s…`) wrapped in `wp_kses_post()`; subscription statuses (which can come from the `wc_tax_retrofit_subscription_statuses` filter) escaped with `esc_html()`

### Translations
- Updated string: "Supprime/recrée chaque ligne avec HT et TVA" → "Convertit chaque ligne en HT + TVA (sans la supprimer : ID, classe de taxe et métadonnées conservés)" (`.pot`, `fr_FR.po`, `en_US.po`)
- **18 strings added** for the revert command, the background migration screens and the InnoDB error ("Renouvellements impayés", "Migration en cours en arrière-plan"…), translated in `fr_FR` and `en_US`
- **Missing string added**: "Logs WooCommerce" (technical info section) was used in the code but absent from the translation files, so it stayed in French for English admins (en_US: "WooCommerce logs")
- **Obsolete strings removed**: "La tolérance doit être un nombre valide" (no longer used) and "Taux %s%% non trouvé dans WooCommerce, utilisation du taux configuré" (`simulate` now stops with an error instead of falling back)
- Translation files are now in exact sync with the code: 303 strings, 0 missing, 0 obsolete (checked with `xgettext`), and all files compile with `msgfmt --check`
- `Project-Id-Version` bumped to 1.4.5

### Documentation (P3)
- **README rewritten**: GitHub badges (version, WordPress, PHP, WooCommerce, Subscriptions, HPOS, WP-CLI, multisite, i18n, license); requirements table (including InnoDB); new sections *What the migration changes*, *Uninstall*, *Changelog*, *License*. Corrected statements: the simulation is recommended, not mandatory; filters are fallbacks (admin / CLI values take priority); 303 strings. Added the new features (`revert`, background run, unpaid renewals, InnoDB check, optional filters). WP-CLI section documents `--format=json`, temporary simulate values, `--offset`, exit codes and automatic resume
- **Dangerous cron recipe replaced**: the README suggested a daily cron running `migrate --yes-i-have-a-backup --skip-confirm` every night without any backup. Replaced with a one-off `at` job that runs `wp db export` first; the automated script now also exports the database and stops on tolerance warnings
- **CLAUDE.md / ARCHITECTURE.md**: runtime dependency checks, in-place conversion flow, `calculate_totals(false)`, atomic lock, `revert`, background run, unpaid renewals, 8 POST actions (the old text claimed 6 distinct nonces: `dry_run` and `confirm_update` share one), `csv_users` registry, uninstall strategy (multisite, persistent object cache), `$stats` keys `renewals_updated` / `locked` / `fatal`, size of the file (~3,600 lines, not ~2,500), Index WP MySQL For Speed note
- **`tests/README.md`** documents the variables of the test runner (`WCS_PATH`, `REDIS_PORT`, `IMFS`, `HPOS_MODES`…) and how to add a scenario

### Verified compatible (no change needed)
- `wcs_get_subscriptions()` (WCS 9.4.1) still maps `status`, `limit`, `offset`, `orderby => 'ID'` and passes `date_created` through to `WC_Order_Query`
- WooCommerce 11.3 APIs used by the plugin: `WC_Order_Item_Product` setters, `update_taxes()`, `add_order_note()`, `WC_Tax::find_rates()`, `woocommerce_tax_rates` table, `wc_get_logger()`, `wc-status&tab=logs` URL, `woocommerce` admin menu
- SQL transactions: neither WooCommerce nor Subscriptions opens its own transaction during a subscription `save()` (a nested `START TRANSACTION` would implicitly commit the plugin's transaction)
- Batch processing: 230 subscriptions migrated in 3 batches of 100, 0 errors, none left behind
- **Redis Object Cache 3.0.0**: simulation, migration, rollback, uninstall and deactivation run against a persistent Redis cache (each WP-CLI call being a separate request, the cache persists between steps as in production)
- **Index WP MySQL For Speed 1.6.0**: tests run with its high-performance keys on every table (`wp_options` primary key on `option_name`, `wc_orders_meta`, `woocommerce_order_itemmeta`, `postmeta`…). No query of the plugin depends on WordPress's default indexes. Note for test authors: this plugin leaves `mu-plugins/index-wp-mysql-for-speed-update-filter.php` behind; on a rebuilt database it prevents WooCommerce from creating its `*_meta` tables (the test runner deletes it)

## [1.4.4] - 2026-02-08

### Consistency (P2)
- **Unescaped `$interrupted_offset` in resume form**: `get_option('wc_tax_retrofit_current_offset')` output was interpolated directly in an HTML hidden input `value` attribute without `intval()`. Internal integer with no user input path, but inconsistent with the systematic escaping applied in v1.4.2. Fixed with `intval()`
- **Unescaped `$checked` in status checkboxes**: hardcoded `'checked'` / `''` string output without `esc_attr()` in the subscription status fieldset, while the adjacent `$status_key` was already escaped. Fixed with `esc_attr()`

## [1.4.3] - 2026-02-08

### Robustness (P1)
- **`catch (Exception)` → `catch (\Throwable)` in `get_tax_rate_id()`**: the try/catch around `WC_Tax::find_rates()` only caught `Exception`. A `TypeError` or `Error` thrown by WooCommerce would propagate uncaught. Fixed with `\Throwable` (consistent with the two catches in `process()` fixed in 1.4.1)
- **Missing `batch_size` and `offset` keys in tax-rate-not-found return**: when `get_tax_rate_id()` returns `null`, `process()` returned an early error array missing `batch_size` and `offset` keys, unlike all other return paths. Code consuming `$stats['batch_size']` or `$stats['offset']` (e.g. `display_results()`) would trigger an undefined index notice

### Consistency (P2)
- **`JSON_UNESCAPED_UNICODE` in CLI simulate**: `wp tax-retrofit simulate --json` output escaped Unicode characters (e.g. French accents as `\u00e9`). Added `JSON_UNESCAPED_UNICODE` flag for readable output
- **Plugin header Description translated to English**: the WordPress plugin header `Description` field was still in French. Rewritten in English for consistency with the rest of the project documentation

### Documentation (P3)
- **CLAUDE.md version outdated**: still referenced version 1.4.0 and "~130 translatable strings". Updated to 1.4.3 and ~290
- **ARCHITECTURE.md string count**: file structure section still said "~130 strings". Updated to ~290

## [1.4.2] - 2026-02-08

### Consistency (P2)
- **Systematic escaping in `display_results()`**: `$mode_color`, `$percent`, `$processed`, `$stats['total']`, `$stats['batch_size']`, `$stats['offset']`, `$stats['updated']`, `$stats['skipped']`, `$stats['errors']`, `$remaining`, `$eta_minutes`, `$eta_sec_remain` and `$next` were interpolated directly in HTML output without `intval()` or `esc_attr()`. All internal values with no user input path, but inconsistent with the rest of the plugin which escapes systematically. Fixed with `intval()` for integers and `esc_attr()` for attributes
- **Unescaped `$style` in tax rate debug table**: hardcoded CSS string output without `esc_attr()` in `admin_page()`. Fixed
- **Unescaped `$diff` in tax rate debug table**: float difference output without `esc_html()` while all other values in the same table row were escaped. Fixed with `esc_html(number_format(...))`
- **Loose comparison `==` for rate selection**: `$current_rate_id == $rate_id` used loose comparison where `null == 0` evaluates to `true`. Replaced with `(int) === (int)` strict comparison
- **Redundant tolerance validation**: `validate_tolerance()` and a manual bounds check were both applied on the same input. Simplified to a single `validate_float()` call
- **Translation file version headers**: `.pot`, `fr_FR.po` and `en_US.po` still referenced version 1.4.0 instead of current version

### Documentation (P3)
- **Translatable string count**: README.md and ARCHITECTURE.md claimed "~130 translatable strings" while the POT file contains 287 entries. Updated to "~290"

## [1.4.1] - 2026-02-08

### Robustness (P1)
- **`catch (Exception)` → `catch (\Throwable)`**: both try/catch blocks in `process()` (per-subscription transaction + outer loop) only caught `Exception`. In PHP 7+, `TypeError` and `Error` are not subclasses of `Exception` — a WooCommerce `TypeError` crash would leave the transaction open without ROLLBACK. Fixed with `\Throwable`
- **Float comparison `== 0` in `detect_no_tax_date()`**: `array_sum($taxes['total']) == 0` could produce a false positive for values very close to 0 (e.g. 0.0001). Replaced with `abs(array_sum($taxes['total'])) < 0.001`

### Performance (P2)
- **Transient cache for `detect_no_tax_date()`**: automatic date detection (N+1 queries across 50 subscriptions) ran on every admin page load when no date was configured. Result now cached in a 5-minute transient (`wc_tax_retrofit_detect_date_desc` / `_asc`)
- **Static cache for `get_tax_rate_id()`**: tax rate lookup (up to 4 DB queries) was not cached, unlike other getters. Added `static` cache with dual variables (`$cached` + `$cached_resolved`) to support the `null` case
- **Static cache for `count_subscriptions()`**: total count (`wcs_get_subscriptions` with `limit => -1`) was not cached. Added `static` cache and deduplicated the inline code in `process()` that replicated the same logic

### Consistency (P3)
- **`$wpdb->prepare()` on debug query step 4**: the only SQL query in the plugin not using `prepare()` (available rates listing in `get_tax_rate_id()`). Fixed with `LIMIT %d`
- **Sanitize `$_POST['statuses']`**: status checkboxes input was not passed through `sanitize_text_field()` before `validate_statuses()`. Near-zero risk (whitelist downstream) but inconsistent with other inputs
- **Cleanup `detect_date_*` transients**: both new transients are now deleted in `deactivate()` and `uninstall.php`

### Documentation (P3)
- **README.md**: 3 occurrences of the old name `woocommerce-subscription-tax-retrofit` corrected to `wcs-tax-retrofit` (renamed since v1.3.5)

## [1.4.0] - 2026-02-08

### Full internationalization (P1)
- **~70 untranslatable UI strings**: most admin interface texts (`admin_page()`, `display_results()`, `ajax_count()`, CSV headers) were raw French without `__()` calls. All strings now wrapped in `__()`, `esc_html__()` or `esc_js()` as appropriate. Translation files grew from ~62 to ~130 entries
- **Regenerated .po/.pot files**: all 3 translation files rewritten with new entries and updated versions

### Fixes (P2)
- **Missing `batch_size` and `csv_data`**: the `$stats` array returned on dependency errors in `process()` lacked `batch_size`, `offset` and `csv_data` keys, causing `undefined index` in calling code
- **`get_all_tax_rates()` without `$wpdb->prepare()`**: SQL query used direct interpolation instead of `$wpdb->prepare()`, inconsistent with the rest of the plugin. Fixed with parameterized `LIMIT %d`

## [1.3.9] - 2026-02-08

### Fixes (P1)
- **Undefined `$target` variable**: in `admin_page()`, when no FR rate existed in DB (`$debug_rates` empty), `$target` was used without being initialized in the configuration instructions, causing a PHP Warning. Moved before the conditional block
- **CLI `simulate --json` incomplete multi-batch**: only `details`, `csv_data` and `errors_list` from the last batch were included in JSON output. All three arrays now accumulated across batches, like `tolerance_warnings` (fixed in v1.3.4)

### Inconsistencies fixed (P2)
- **Filter priority harmonized**: `get_date_limit()` applied `apply_filters()` even when a DB value existed, unlike `get_selected_rate()` and `get_tolerance()` which only use the filter as fallback. All three getters now follow the same logic: DB → filter fallback
- **CLI `simulate --date-limit` and `--tolerance`**: these temporary options used `add_filter()` which was ignored when a DB value existed (DB > filter priority). Migrated to the save/restore DB pattern already used by `--tax-rate` since v1.3.1
- **Hardcoded date fallback**: the `'2024-11-29'` fallback in `get_date_limit()` (specific to one use case) replaced with `current_time('Y-m-d')` (today's date)
- **`locked` return from `process()`**: the array returned when processing was already running only contained `locked` and `message`, without expected keys (`updated`, `errors`, `has_more`, etc.). Added all standard `$stats` keys to prevent undefined index
- **CSV transient TTL**: temporary CSV data TTL increased from 1 hour to 24 hours, preventing data loss when interrupted migrations are resumed after >1h

### Performance (P3)
- **Static cache for `get_date_limit()`**: function was called ~8 times on admin page without cache. Each call reads `get_option()`, validates the date, and may trigger automatic detection. A `static` cache eliminates redundant calls
- **Static cache for `get_tolerance()`**: ~6 uncached calls on admin page
- **Static cache for `get_subscription_statuses()`**: ~5 uncached calls on admin page

### Cleanup (P3)
- **SQL with `$wpdb->prepare()`**: DELETE queries in deactivation hook and `uninstall.php` used raw SQL. Migrated to `esc_like()` + `prepare()` for consistency

## [1.3.8] - 2026-02-07

### Rename
- **Text domain renamed**: `wc-tax-retrofit` → `wcs-tax-retrofit` in plugin header, `load_plugin_textdomain()`, 60+ `__()` calls, log source, and 3 admin HTML references. Language files renamed accordingly (`wcs-tax-retrofit.pot`, `wcs-tax-retrofit-fr_FR.po`, `wcs-tax-retrofit-en_US.po`)

### Fix (P1)
- **Simulation after interrupted migration**: `wc_tax_retrofit_process()` automatically resumed at the saved offset even in simulation mode (`$dry_run = true`). A simulation launched after an interrupted migration would start at the wrong offset. Added `!$dry_run` guard to the resume condition

### Inconsistencies fixed (P2)
- **3x "before" → "up to"**: the `date_created` filter uses `<=` (inclusive) since v1.3.7, but 3 UI texts still said "before" instead of "up to" (affected subscriptions section, recalculation, and actions)
- **CSV filename**: exported file was named `wc-subscription-tax-retrofit-*.csv` (old naming without 's'). Renamed to `wcs-tax-retrofit-*.csv` for consistency with the text domain
- **README "~20 entries"**: translations section stated ~20 translatable strings while .po files contained 60
- **Missing PO string**: "Starting migration..." (CLI migrate) was not in .po files — old files incorrectly pointed to "Starting simulation..." for both references. Added with English translation

### Cleanup (P3)
- **PHPDoc added**: `get_subscription_statuses()`, `get_tolerance()` and `get_date_limit()` lacked `@return` docblocks, unlike other public functions

## [1.3.7] - 2026-02-07

### Fixes (P1)
- **Inclusive `date_created` filter**: code used `<` (strictly before) while UI indicated "before or on". Replaced with `<=` in all 3 locations (`count_subscriptions`, `process` batch, `process` total count) to include subscriptions created on the cutoff date itself
- **Duplicate success/error message**: when user unchecked all statuses in advanced settings, both error AND success messages displayed simultaneously. A `$settings_has_error` flag now conditions success display
- **`--skip-confirm` documented**: CLI `migrate --skip-confirm` option (for scripts/cron) was handled in code but absent from WP-CLI docblock. `wp tax-retrofit migrate --help` now displays it

### Improvements (P2)
- **`uninstall.php` added**: plugin deletion via admin now cleans all options and transients from the database. Previously, 7 config and state options remained orphaned
- **Dead code removed**: 3 `wp_die()` calls after `wp_send_json_success/error()` in `ajax_count()` were unreachable (those functions already call `wp_die()` internally)
- **Static cache for `get_selected_rate()`**: function made a SQL query (`$wpdb->get_var`) on each call. On admin page (~5 calls), this generated 5 identical queries. A `static` cache eliminates redundant calls

### Cleanup (P3)
- **`$meta->is_unique` removed**: `WC_Meta_Data` has no `is_unique` property; `$meta->is_unique ?? false` always evaluated to `false`. Replaced with `false` directly
- **`$stats` docblock reduced**: 65-line inline comment describing `$stats` structure in `process()` replaced with a 5-line summary
- **README hooks updated**: "3 PHP hooks" replaced with "5 PHP hooks (4 filters + 1 action)" with documentation of 2 missing hooks (`wc_tax_retrofit_subscription_statuses` and `wc_tax_retrofit_after_process`)

### Distribution
- **`uninstall.php` created**: full cleanup on plugin deletion
- **`LICENSE` file added**: complete GPL v2 license for GitHub distribution
- **`.gitignore` added**: excludes system files, IDE files and compiled `.mo` files
- **README file structure updated**: added `uninstall.php`, `CHANGELOG.md` and `LICENSE`

## [1.3.6] - 2026-02-07

### Internationalization
- **Language files regenerated**: all 3 files (POT, fr_FR.po, en_US.po) were frozen at v1.2.9 with old filename, obsolete line numbers, 8 missing strings and 3 removed strings. Fully regenerated with 60 strings (vs 50 before)
- **8 strings added**: `Tax rate not found...`, `Last activity: %s ago`, `No valid status provided...`, `Rate %s%% not found... using configured rate`, `Batch offset %d: %d to migrate...`, `To migrate`, `Batch offset %d: %d updated...`, `Migration fully reset`
- **3 obsolete strings removed**: `There are subscriptions left to process.`, `Run again with: wp tax-retrofit migrate...`, `Migration state reset`
- **References updated**: filename `wc-subscriptions-tax-retrofit.php`, version 1.3.6, date 2026-02-07
- **English translation fixed**: `Type "OUI"` instead of `Type "YES"` (code checks `$confirmation !== 'OUI'` regardless of locale)

## [1.3.5] - 2026-02-07

### Fix (P1)
- **`wc_tax_retrofit_tolerance` filter restored**: `get_tolerance()` no longer called `apply_filters()` since v1.3.2 (removed from `define()` but never reintroduced in the function). The filter documented in README, mentioned in admin UI, and used by CLI `simulate --tolerance` was silently ignored. Added as priority 2 (DB > filter > constant), consistent with `get_date_limit()` and `get_selected_rate()`

### Cleanup (P3)
- **Reused `$target` cache (technical info)**: `get_selected_rate_percent()` was still called in the "Tax Rate ID not found" technical info block while `$target` was already in scope (missed in v1.3.4 fix #4)
- **Duplicate `$saved_date` removed**: `get_option('wc_tax_retrofit_date_limit')` was called twice within 9 lines in the same admin page scope

### Rename
- **Main file renamed**: `woocommerce-subscription-tax-retrofit.php` → `wc-subscriptions-tax-retrofit.php`

## [1.3.4] - 2026-02-07

### Description
- **Plugin header rewritten**: long description with HTML link (not rendered in plugin list or `wp plugin list`) replaced with a concise, readable description

### Fix (P1)
- **CLI `simulate` tolerance warnings multi-batch**: only warnings from the last batch were checked after the loop. `tolerance_warnings` now accumulated across all batches (like `updated`, `skipped`, `errors`). Also fixed in JSON output

### Inconsistencies fixed (P2)
- **`wc_tax_retrofit_batch_size` filter reference removed**: admin technical info displayed `(filter wc_tax_retrofit_batch_size)` while this filter was removed in v1.3.3
- **`--batch-size` option removed from CLI `migrate` docblock**: option was documented but never implemented in code
- **Reused `$target` cache**: `get_selected_rate_percent()` was called 2 times in the "rate not found" error block while `$target` was already computed in the same scope

### Cleanup (P3)
- **`reset_migration` handler removed**: dead code — no UI form submitted this action (replaced by `full_reset` since v1.3.0). The handler was also incomplete compared to `full_reset` (did not delete `current_offset` or `last_activity`)

## [1.3.3] - 2026-02-07

### Inconsistencies fixed
- **`apply_filters()` in `define(WC_TAX_RETROFIT_BATCH_SIZE)`**: same timing issue as `TOLERANCE` and `RATE` fixed in v1.3.1/1.3.2 — filters are not yet registered at `define()` time. The constant now uses its default value directly
- **Stale comment**: `// Priority 2: Constant (defined via filter at load)` in `get_tolerance()` no longer reflected reality since filter removal in v1.3.2
- **README version**: header showed 1.3.1 instead of current version

### Performance
- **`get_selected_rate_percent()` cached in debug loop**: function was called on each iteration of the FR rates array instead of being cached before the loop
- **"How it works" section cached**: `get_selected_rate()` was called 3 times and `get_selected_rate_percent()` once in the same HTML block. Results now cached in local variables

### PHP 7.4+ modernization (continued)
- **Null coalescing operator**: replaced 7 occurrences of `isset($x) ? $x : default` with `$x ?? default` (available since PHP 7.0)

### Cleanup
- **`WHERE 1=1` removed**: unnecessary clause in `get_all_tax_rates()` SQL query (no dynamic conditions concatenated)

## [1.3.2] - 2026-02-07

### PHP 7.4+ modernization
- **Return type hints**: added explicit return types on 24 functions (`: array`, `: bool`, `: float`, `: int`, `: string`, `: void`). 6 functions returning union types (`string|false`, `string|null`, etc.) excluded as union types require PHP 8.0+
- **Arrow functions**: replaced closures `function() use ($var) { return $var; }` with `fn() => $var` in CLI `simulate` commands
- **Spread operator**: replaced `array_merge($a, $b)` with `[...$a, ...$b]` for CSV data merging (more efficient on numerically-keyed arrays)
- **Redundant PHP check removed**: `version_compare(PHP_VERSION, '7.4', '<')` in `wc_tax_retrofit_check_dependencies()` removed since WordPress `Requires PHP: 7.4` header and early check already prevent execution on PHP < 7.4. Function simplified to a single expression
- **`apply_filters()` removed from `define(WC_TAX_RETROFIT_TOLERANCE)`**: filters are not yet registered at `define()` time, constant now uses its default value directly

## [1.3.1] - 2026-02-07

### Fixes (P1)
- **CLI `simulate` multi-batch**: `wp tax-retrofit simulate` only processed the first batch of 100 subscriptions. Now loops through all batches like `migrate`
- **CLI `simulate --tax-rate` functional**: `--tax-rate` parameter was silently ignored when a rate was already saved in DB (DB had priority over filter). Temporary rate now applied via a DB option restored after simulation
- **CLI `reset` complete**: CLI command did not delete `wc_tax_retrofit_executed`, `wc_tax_retrofit_date` or `wc_tax_retrofit_count`, unlike the web reset. Both are now identical
- **CLI `config --tax-rate` approximate match**: rate lookup in DB used exact match (`WHERE tax_rate = %s`) while the rest of the plugin uses `ABS(tax_rate - %f) < 0.01`. Harmonized
- **Deprecated `current_time('timestamp')`**: replaced with `time()` in CLI `stats` command (deprecated since WordPress 5.3)

### Improvements (P2)
- **Debug query moved**: SQL debug query listing FR rates no longer runs systematically on admin page, only when rate is not found
- **`wc_tax_retrofit_get_tax_rate_id()` cached**: first call result on admin page reused in technical info (saves a heavy call with up to 4 SQL queries)
- **Static cache for date detection**: `wc_tax_retrofit_detect_no_tax_date()` now uses a static cache to avoid redundant calls within the same request

### Cleanup (P3)
- **`WC_TAX_RETROFIT_RATE` removed**: unused constant that used `apply_filters()` in `define()` (filters not yet registered at load time)
- **`WC_TAX_RETROFIT_TEXT_DOMAIN` removed**: constant defined but never used (text domain is hardcoded in all `__()` calls)
- **`WC_TAX_RETROFIT_TOLERANCE` filter removed**: constant keeps its default value without `apply_filters()` (same timing issue as `WC_TAX_RETROFIT_RATE`)
- **`$show_detection_block` removed**: variable assigned but never read (dead code)
- **Date detection factored**: `detect_first_no_tax_date()` and `detect_last_no_tax_date()` delegate to a single `detect_no_tax_date($order)` function instead of duplicating 30 lines
- **`validate_tolerance()` factored**: delegates to `validate_float()` instead of duplicating validation logic
- **README updated**: version 1.2.9 → 1.3.1

## [1.3.0] - 2026-02-07

### Critical fixes (P0)
- **PHP Fatal Error fix**: removed duplicate declaration of `wc_tax_retrofit_validate_date()` (lines 145 and 308) which caused a crash on activation
- **`$stats` used before declaration**: offset save block in `wc_tax_retrofit_process()` referenced `$stats['batch_size']` before `$stats` was initialized, causing incorrect resume offset

### Important fixes (P1)
- **WP-CLI functional**: permission and nonce checks in `wc_tax_retrofit_process()` now skipped in CLI context (`defined('WP_CLI')`)
- **CLI tolerance option fixed**: `wp tax-retrofit config --tolerance=` wrote to the wrong key (`wc_tax_retrofit_tolerance` instead of `wc_tax_retrofit_tolerance_setting`), making CLI configuration ineffective
- **CLI migrate saves state**: `wp tax-retrofit migrate` now saves `wc_tax_retrofit_executed`, `wc_tax_retrofit_date` and `wc_tax_retrofit_count`, like the web version. CLI also processes all batches automatically instead of stopping at the first
- **CLI default statuses fixed**: fallback in `config` displayed `pending` instead of `pending-cancel`
- **CLI status validation**: statuses passed via `--statuses=` now validated with `wc_tax_retrofit_validate_statuses()` instead of being saved as-is

### Improvements (P2)
- **DB transactions**: item modifications (delete/recreate) in `wc_tax_retrofit_process()` now wrapped in SQL transactions (`START TRANSACTION` / `COMMIT` / `ROLLBACK`) to prevent corrupted subscriptions on error
- **Improved date detection**: `wc_tax_retrofit_detect_last_no_tax_date()` and `wc_tax_retrofit_detect_first_no_tax_date()` now scan up to 50 subscriptions (instead of 1), significantly increasing chances of finding a tax-free subscription
- **Loop value caching**: `wc_tax_retrofit_get_selected_rate()` and `wc_tax_retrofit_get_tolerance()` cached at the start of `wc_tax_retrofit_process()` instead of being recalculated per item (saves ~400 SQL queries per 100-subscription batch)
- **Offset saved for resume**: current offset correctly saved at end of each batch to allow resume after interruption

### Cleanup (P3)
- **HTML echo removed from process()**: `wc_tax_retrofit_process()` no longer echoes HTML when tax rate is not found; returns errors cleanly via `errors_list`
- **i18n fix**: replaced `" ago"` (English) with a translatable string via `__()` in the interrupted migration alert
- **CSV timezone fix**: replaced `date()` with `wp_date()` in exported CSV filename
- **Closing `?>` tag removed**: removed PHP closing tag at end of file to prevent "headers already sent" errors
- **CSV export performance**: reordered checks in `wc_tax_retrofit_export_csv()` so action check (`$_GET['action']`) runs first (before dependency and permission checks), avoiding expensive operations on every `admin_init` load

## [1.2.9] - 2024-12-10

### Added
- **Full WP-CLI support**: 5 commands available (`config`, `simulate`, `migrate`, `stats`, `reset`)
- Ideal for very large databases (no PHP timeout)
- Automation and CI/CD integration
- JSON output for parsing
- Complete documentation

## [1.2.8] - 2024-12-09

### Improvements
- Improved dependency notices
- Full internationalization
- More explicit error messages

## [1.2.7] - 2024-12-09

### Added
- Configurable tax rate via interface
- Universal country support

## [1.2.6] - 2024-12-09

### Improvements
- Strengthened security
- Strict data validation
- Centralized dependency checking
