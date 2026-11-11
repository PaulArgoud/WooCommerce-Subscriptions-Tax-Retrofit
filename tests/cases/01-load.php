<?php
/** Chargement : le plugin doit être opérationnel alors qu'il est chargé AVANT WooCommerce (ordre alphabétique) */
require __DIR__ . '/../lib/assert.php';

$active = (array) get_option('active_plugins');
t_ok(array_search('wcs-tax-retrofit/wcs-tax-retrofit.php', $active, true) < array_search('woocommerce/woocommerce.php', $active, true),
    'le plugin est chargé avant WooCommerce (condition réelle)');
t_ok(has_action('admin_menu', 'wc_tax_retrofit_admin_menu') !== false, 'hook admin_menu enregistré');
t_ok(has_action('wp_ajax_wc_tax_retrofit_count', 'wc_tax_retrofit_ajax_count') !== false, 'hook AJAX enregistré');
t_ok(has_action('wc_tax_retrofit_bg_batch', 'wc_tax_retrofit_bg_run_batch') !== false, 'tâche Action Scheduler enregistrée');
t_ok(wc_tax_retrofit_check_dependencies(), 'dépendances détectées à l\'exécution');
t_ok(wc_tax_retrofit_background_available(), 'Action Scheduler disponible');
t_eq(array(), wc_tax_retrofit_get_non_transactional_tables(), 'toutes les tables de commandes sont en InnoDB');

$compat = wc_get_container()->get(\Automattic\WooCommerce\Internal\Features\FeaturesController::class)
    ->get_compatible_plugins_for_feature('custom_order_tables', true);
t_ok(in_array('wcs-tax-retrofit/wcs-tax-retrofit.php', $compat['compatible'], true), 'compatibilité HPOS déclarée');
