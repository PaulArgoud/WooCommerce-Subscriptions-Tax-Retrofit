<?php
/** Désinstallation : plus aucune option ni transient du plugin (base ET cache objet persistant) */
require __DIR__ . '/../lib/assert.php';
global $wpdb;

// Données à nettoyer
foreach (array(0, 1) as $user_id) {
    set_transient('wc_tax_retrofit_csv_' . $user_id, array(array('x' => 1)), DAY_IN_SECONDS);
    wc_tax_retrofit_register_csv_user($user_id);
}
set_transient('wc_tax_retrofit_detect_date_desc', '2025-06-01', 300);
wc_tax_retrofit_acquire_lock();
update_option('wc_tax_retrofit_bg_state', array('status' => 'completed'), false);

define('WP_UNINSTALL_PLUGIN', 'wcs-tax-retrofit/wcs-tax-retrofit.php');
include WP_PLUGIN_DIR . '/wcs-tax-retrofit/uninstall.php';

wp_cache_flush_runtime();
$left = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%wc\\_tax\\_retrofit%'");
t_eq(array(), $left, 'aucune option / transient en base');
foreach (array('wc_tax_retrofit_csv_0', 'wc_tax_retrofit_csv_1', 'wc_tax_retrofit_detect_date_desc') as $transient) {
    t_ok(get_transient($transient) === false, "transient $transient supprimé (y compris du cache objet)");
}
t_ok(get_option('wc_tax_retrofit_csv_users', null) === null, 'registre CSV supprimé');
if (wp_using_ext_object_cache()) {
    echo "PASS: test exécuté avec un cache objet persistant\n";
}
