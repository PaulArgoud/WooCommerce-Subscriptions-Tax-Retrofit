#!/usr/bin/env bash
#
# Suite de tests d'intégration — WooCommerce Subscriptions Tax Retrofit
#
# Monte un WordPress jetable (base dédiée, recréée à chaque suite), installe WooCommerce,
# WooCommerce Subscriptions et le plugin, puis joue les scénarios de tests/cases/.
# Code retour : 0 si tout passe, 1 sinon. Voir tests/README.md.
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TESTS="$ROOT/tests"

# ---------------------------------------------------------------- configuration
PHP_BIN="${PHP_BIN:-php}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-wcs_retrofit_tests}"
WORK_DIR="${WORK_DIR:-$TESTS/.work}"
WP_CORE_DIR="${WP_CORE_DIR:-}"          # copie locale de WordPress (sinon téléchargement)
WP_VERSION="${WP_VERSION:-latest}"
WC_PATH="${WC_PATH:-}"                  # dossier woocommerce/ local (sinon wordpress.org)
WC_VERSION="${WC_VERSION:-}"
WCS_PATH="${WCS_PATH:-}"                # OBLIGATOIRE : dossier woocommerce-subscriptions/ (extension payante)
HPOS_MODES="${HPOS_MODES:-yes no}"
REDIS_PORT="${REDIS_PORT:-}"            # active Redis Object Cache (serveur Redis requis sur ce port)
IMFS="${IMFS:-0}"                       # 1 = Index WP MySQL For Speed avec ses index
MULTISITE="${MULTISITE:-1}"             # 1 = teste aussi la désinstallation multisite
BULK="${BULK:-1}"                       # 1 = scénarios à 230+ abonnements (lots de 100)
SETUP_ONLY="${SETUP_ONLY:-}"            # yes|no : monte le site (HPOS oui/non) avec le jeu de données puis s'arrête (débogage)

[ -n "$WCS_PATH" ] && [ -d "$WCS_PATH" ] || { echo "WCS_PATH doit pointer vers le dossier woocommerce-subscriptions (extension payante)"; exit 2; }

WP_DIR="$WORK_DIR/wp"
WP_CLI_PHAR="${WP_CLI_PHAR:-$WORK_DIR/wp-cli.phar}"
mkdir -p "$WORK_DIR"
if [ ! -f "$WP_CLI_PHAR" ]; then
    curl -sSLo "$WP_CLI_PHAR" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar || { echo "Téléchargement de wp-cli impossible"; exit 2; }
fi

PASS=0
FAIL=0
FAILED=()

wp() { "$PHP_BIN" "$WP_CLI_PHAR" --path="$WP_DIR" "$@" 2> >(grep -v "_load_textdomain_just_in_time\|^$" >&2); }
wpq() { wp "$@" >/dev/null 2>&1; }

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

record() { # record <0|1> <libellé>
    if [ "$1" = 0 ]; then PASS=$((PASS + 1)); echo "PASS: $2"; else FAIL=$((FAIL + 1)); FAILED+=("$2"); echo "FAIL: $2"; fi
}

run_case() { # run_case <fichier> [args...]
    local out
    out="$(wp eval-file "$TESTS/cases/$1" "${@:2}" 2>&1)"
    echo "$out" | grep -E "^(PASS|FAIL)" || true
    PASS=$((PASS + $(echo "$out" | grep -c "^PASS")))
    local fails; fails="$(echo "$out" | grep -c "^FAIL")"
    if [ "$fails" -gt 0 ] || ! echo "$out" | grep -q "^PASS"; then
        FAIL=$((FAIL + (fails > 0 ? fails : 1))); FAILED+=("$1 $*")
        echo "$out" | grep -vE "^(PASS|FAIL)" | head -20
    fi
}

expect_exit() { # expect_exit <code> <libellé> <commande wp...>
    local expected="$1" label="$2"; shift 2
    wpq "$@"; local code=$?
    [ "$code" = "$expected" ]; record $? "$label (code $code, attendu $expected)"
}

php_db() { # exécute du SQL sans client mysql (mysqli)
    "$PHP_BIN" -r '$m = @new mysqli($argv[1], $argv[2], $argv[3], "", (int) $argv[4]); if ($m->connect_errno) { fwrite(STDERR, $m->connect_error . "\n"); exit(1); } foreach (array_filter(array_map("trim", explode(";", $argv[5]))) as $q) { if (!$m->query($q)) { fwrite(STDERR, $m->error . "\n"); exit(1); } }' \
        "$DB_HOST" "$DB_USER" "$DB_PASS" "$DB_PORT" "$1"
}

json_field() { "$PHP_BIN" -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? json_encode($d[$argv[1]] ?? null) : "INVALID";' "$1"; }

# ---------------------------------------------------------------- site jetable
prepare_core() {
    if [ -f "$WP_DIR/wp-settings.php" ]; then return; fi
    mkdir -p "$WP_DIR"
    if [ -n "$WP_CORE_DIR" ]; then
        rsync -a --exclude wp-content/plugins --exclude wp-content/uploads --exclude wp-config.php --exclude wp-content/object-cache.php "$WP_CORE_DIR/" "$WP_DIR/"
    else
        "$PHP_BIN" "$WP_CLI_PHAR" core download --path="$WP_DIR" --version="$WP_VERSION" --skip-content >/dev/null
        mkdir -p "$WP_DIR/wp-content/themes"
    fi
    mkdir -p "$WP_DIR/wp-content/plugins"
    ln -sfn "$ROOT" "$WP_DIR/wp-content/plugins/wcs-tax-retrofit"
    ln -sfn "$WCS_PATH" "$WP_DIR/wp-content/plugins/woocommerce-subscriptions"
    [ -n "$WC_PATH" ] && ln -sfn "$WC_PATH" "$WP_DIR/wp-content/plugins/woocommerce"
}

write_config() {
    local redis=""
    [ -n "$REDIS_PORT" ] && redis="define('WP_REDIS_HOST', '127.0.0.1'); define('WP_REDIS_PORT', $REDIS_PORT); define('WP_REDIS_CLIENT', 'predis'); define('WP_REDIS_PREFIX', 'wcsretrofittests:');"
    cat > "$WP_DIR/wp-config.php" <<EOF
<?php
define('DB_NAME', '$DB_NAME'); define('DB_USER', '$DB_USER'); define('DB_PASSWORD', '$DB_PASS');
define('DB_HOST', '$DB_HOST:$DB_PORT'); define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
\$table_prefix = 'wp_';
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT') as \$k) define(\$k, 'tests');
define('WP_DEBUG', true); define('WP_DEBUG_LOG', __DIR__ . '/debug.log'); define('WP_DEBUG_DISPLAY', false);
define('WP_HOME', 'http://localhost'); define('WP_SITEURL', 'http://localhost');
$redis
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
EOF
}

fresh_site() { # fresh_site <hpos yes|no> [bulk]
    php_db "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4" || exit 2
    # Résidus d'une suite précédente : le drop-in Redis et le mu-plugin d'Index WP MySQL For Speed
    # survivent à la recréation de la base (le second perturbe la création des tables *_meta de WooCommerce)
    rm -f "$WP_DIR/wp-content/object-cache.php" "$WP_DIR/wp-content/mu-plugins/index-wp-mysql-for-speed-update-filter.php" "$WP_DIR/debug.log"
    write_config
    wpq core install --url=http://localhost --title=Tests --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
    if [ -z "$WC_PATH" ] && [ ! -d "$WP_DIR/wp-content/plugins/woocommerce" ]; then
        wpq plugin install woocommerce ${WC_VERSION:+--version=$WC_VERSION}
    fi
    wpq plugin activate woocommerce
    wpq option update woocommerce_custom_orders_table_enabled "$1"
    wpq option update woocommerce_custom_orders_table_data_sync_enabled no
    wpq plugin activate woocommerce-subscriptions
    wpq plugin activate wcs-tax-retrofit
    if [ -n "$REDIS_PORT" ]; then
        [ -d "$WP_DIR/wp-content/plugins/redis-cache" ] || wpq plugin install redis-cache
        wpq plugin activate redis-cache; wpq redis enable; wp eval 'wp_cache_flush();' >/dev/null 2>&1
    fi
    if [ "$IMFS" = 1 ]; then
        [ -d "$WP_DIR/wp-content/plugins/index-wp-mysql-for-speed" ] || wpq plugin install index-wp-mysql-for-speed
        wpq plugin activate index-wp-mysql-for-speed; wpq index-mysql enable --all
    fi
    wp eval-file "$TESTS/lib/fixtures.php" ${2:-} | sed 's/^/   /'
}

# ---------------------------------------------------------------- suites
prepare_core
echo "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') | Redis: ${REDIS_PORT:-non} | Index WP MySQL For Speed: $IMFS"

if [ -n "$SETUP_ONLY" ]; then
    fresh_site "$SETUP_ONLY" "${SETUP_BULK:-}"
    echo "Site prêt : $WP_DIR (base $DB_NAME conservée)"
    exit 0
fi

for HPOS in $HPOS_MODES; do
    say "HPOS=$HPOS — chargement, CLI, migration, annulation"
    fresh_site "$HPOS"
    run_case 01-load.php

    expect_exit 1 "config --tax-rate inconnu" tax-retrofit config --tax-rate=33
    expect_exit 1 "simulate --date-limit invalide" tax-retrofit simulate --date-limit=2025-13-01
    expect_exit 1 "simulate --tolerance hors plage" tax-retrofit simulate --tolerance=9
    expect_exit 1 "migrate sans --yes-i-have-a-backup" tax-retrofit migrate
    expect_exit 1 "revert sans --yes-i-have-a-backup" tax-retrofit revert

    SIM="$(wp tax-retrofit simulate --format=json 2>/dev/null)"
    [ "$(echo "$SIM" | json_field updated)" = "7" ]; record $? "simulate JSON : 7 abonnements à migrer"
    [ "$(echo "$SIM" | json_field renewals_updated)" = "1" ]; record $? "simulate JSON : 1 renouvellement impayé"
    wpq tax-retrofit simulate
    [ "$(wp eval 'echo count(get_transient("wc_tax_retrofit_csv_0") ?: []);' 2>/dev/null)" = "7" ]; record $? "CSV CLI non cumulé entre deux simulations"

    run_case 06-lock.php hold
    expect_exit 1 "simulate refusé pendant une migration (verrou)" tax-retrofit simulate --tax-rate=21 --date-limit=2025-01-01
    [ "$(wp eval 'echo get_option("wc_tax_retrofit_date_limit");' 2>/dev/null)" = "2025-12-31" ]; record $? "configuration intacte après refus (verrou)"
    expect_exit 1 "migrate refusé pendant une migration (verrou)" tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    wpq tax-retrofit reset

    expect_exit 0 "migrate" tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    run_case 02-migrated.php
    [ "$(wp tax-retrofit simulate --format=json 2>/dev/null | json_field updated)" = "0" ]; record $? "2e passage : plus rien à migrer (idempotent)"
    expect_exit 0 "revert --dry-run" tax-retrofit revert --dry-run
    expect_exit 0 "revert" tax-retrofit revert --yes-i-have-a-backup --skip-confirm
    run_case 03-reverted.php

    expect_exit 0 "re-migrate après annulation" tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    run_case 05-revert-refused.php
    expect_exit 1 "revert refuse un abonnement modifié depuis la migration" tax-retrofit revert --yes-i-have-a-backup --skip-confirm
    [ "$(wp eval '$s = wcs_get_subscription(get_option("wcs_retrofit_test_ids")["S2"]); echo $s->get_meta("_wc_tax_retrofit_original") ? "kept" : "lost";' 2>/dev/null)" = "kept" ]; record $? "abonnement modifié laissé intact par revert"
    [ "$(wp eval '$s = wcs_get_subscription(get_option("wcs_retrofit_test_ids")["S1"]); echo $s->get_meta("_wc_tax_retrofit_original") ? "migrated" : "reverted";' 2>/dev/null)" = "reverted" ]; record $? "les autres abonnements sont annulés"

    say "HPOS=$HPOS — ROLLBACK"
    fresh_site "$HPOS"
    run_case 04-rollback.php

    say "HPOS=$HPOS — tables MyISAM (pas de ROLLBACK possible)"
    fresh_site "$HPOS"
    wpq eval 'global $wpdb; $wpdb->query("ALTER TABLE {$wpdb->prefix}woocommerce_order_items ENGINE=MyISAM");'
    expect_exit 1 "migrate refusé sur table MyISAM" tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    [ "$(wp eval 'echo (int) wcs_get_subscription(get_option("wcs_retrofit_test_ids")["S1"])->get_total_tax();' 2>/dev/null)" = "0" ]; record $? "aucun abonnement modifié sur table MyISAM"
    wpq eval 'global $wpdb; $wpdb->query("ALTER TABLE {$wpdb->prefix}woocommerce_order_items ENGINE=InnoDB");'

    say "HPOS=$HPOS — arrière-plan (Action Scheduler)"
    fresh_site "$HPOS"
    run_case 07-background.php start
    for _ in $(seq 1 20); do
        wpq action-scheduler run --hooks=wc_tax_retrofit_bg_batch --force
        [ "$(wp eval 'echo wc_tax_retrofit_bg_get_state()["status"] ?? "";' 2>/dev/null)" = "running" ] || break
    done
    run_case 07-background.php check 7
    run_case 07-background.php cancel

    if [ "$BULK" = 1 ]; then
        say "HPOS=$HPOS — 237 abonnements (3 lots), CLI puis arrière-plan"
        fresh_site "$HPOS" bulk
        expect_exit 0 "migrate 237 abonnements" tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
        [ "$(wp eval 'echo get_option("wc_tax_retrofit_count");' 2>/dev/null)" = "237" ]; record $? "compteur final : 237"
        fresh_site "$HPOS" bulk
        run_case 07-background.php start
        for _ in $(seq 1 30); do
            wpq action-scheduler run --hooks=wc_tax_retrofit_bg_batch --force
            [ "$(wp eval 'echo wc_tax_retrofit_bg_get_state()["status"] ?? "";' 2>/dev/null)" = "running" ] || break
        done
        run_case 07-background.php check 237
    fi

    say "HPOS=$HPOS — désinstallation"
    fresh_site "$HPOS"
    run_case 08-uninstall.php
done

if [ "$MULTISITE" = 1 ]; then
    say "Multisite — désinstallation sur tous les sites"
    fresh_site yes
    wpq core multisite-convert
    "$PHP_BIN" -r '$f = $argv[1]; $c = file_get_contents($f); if (strpos($c, "MULTISITE") === false) { $c = str_replace("if (!defined(\x27ABSPATH\x27))", "define(\x27MULTISITE\x27, true); define(\x27SUBDOMAIN_INSTALL\x27, false); define(\x27DOMAIN_CURRENT_SITE\x27, \x27localhost\x27); define(\x27PATH_CURRENT_SITE\x27, \x27/\x27); define(\x27SITE_ID_CURRENT_SITE\x27, 1); define(\x27BLOG_ID_CURRENT_SITE\x27, 1);\nif (!defined(\x27ABSPATH\x27))", $c); file_put_contents($f, $c); }' "$WP_DIR/wp-config.php"
    wpq site create --slug=boutique2
    wpq --url=http://localhost/boutique2/ eval 'set_transient("wc_tax_retrofit_csv_1", [["x"=>1]], DAY_IN_SECONDS); update_option("wc_tax_retrofit_csv_users", [1], false); update_option("wc_tax_retrofit_executed", true);'
    run_case 08-uninstall.php
    LEFT="$(wp --url=http://localhost/boutique2/ eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"%tax_retrofit%\""), "/", get_transient("wc_tax_retrofit_csv_1") === false ? "ok" : "reste";' 2>/dev/null)"
    [ "$LEFT" = "0/ok" ]; record $? "site 2 du réseau nettoyé ($LEFT)"
fi

# ---------------------------------------------------------------- bilan
php_db "DROP DATABASE IF EXISTS \`$DB_NAME\`" >/dev/null 2>&1
say "Bilan : $PASS réussis, $FAIL échoués"
if [ "$FAIL" -gt 0 ]; then
    printf '  - %s\n' "${FAILED[@]}"
    exit 1
fi
exit 0
