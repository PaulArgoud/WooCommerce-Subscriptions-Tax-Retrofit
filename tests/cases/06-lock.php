<?php
/** Verrou atomique : exclusivité, libération, reprise d'un verrou expiré */
require __DIR__ . '/../lib/assert.php';
global $wpdb;

$first = wc_tax_retrofit_acquire_lock();
t_ok($first !== null, 'premier verrou obtenu');
t_ok(wc_tax_retrofit_acquire_lock() === null, 'second verrou refusé tant que le premier est actif');
t_ok(wc_tax_retrofit_is_locked(), 'verrou détecté');

$stats = wc_tax_retrofit_process(true, 0);
t_ok(!empty($stats['locked']), 'un lot est refusé pendant que le verrou est détenu');

wc_tax_retrofit_release_lock('jeton-étranger:xxx');
t_ok(wc_tax_retrofit_is_locked(), 'un jeton étranger ne libère pas le verrou');
wc_tax_retrofit_release_lock($first);
t_ok(!wc_tax_retrofit_is_locked(), 'verrou libéré par son propriétaire');

// Verrou expiré (exécution tuée il y a 20 minutes) : repris
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
    WC_TAX_RETROFIT_LOCK_OPTION, (time() - 20 * MINUTE_IN_SECONDS) . ':mort'
));
t_ok(!wc_tax_retrofit_is_locked(), 'verrou expiré considéré comme libre');
$taken = wc_tax_retrofit_acquire_lock();
t_ok($taken !== null, 'verrou expiré repris');
wc_tax_retrofit_release_lock($taken);

// Option conservée pour le verrou "en cours" utilisé par run.sh (test CLI concurrent)
if (in_array('hold', $args ?? array(), true)) {
    wc_tax_retrofit_acquire_lock();
    echo "PASS: verrou laissé actif pour le test CLI\n";
}
