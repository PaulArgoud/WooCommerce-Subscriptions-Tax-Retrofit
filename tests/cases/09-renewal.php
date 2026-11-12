<?php
/**
 * Renouvellement APRÈS migration : la commande que WooCommerce Subscriptions crée à partir d'un
 * abonnement migré doit garder le même total TTC et faire apparaître la TVA.
 * Joué après `wp tax-retrofit migrate` sur le jeu de données (fixtures.php).
 */
require __DIR__ . '/../lib/assert.php';

$ids = t_ids();
$expect = array(
    'S1' => array(30, 5), 'S2' => array(30, 5), 'S3' => array(35, 5), 'S4' => array(27, 4.5),
    'S5' => array(29.97, 4.99), 'S6' => array(30, 5), 'S7' => array(30, 5),
);

foreach ($expect as $key => [$total, $tax]) {
    $subscription = t_fresh((int) $ids[$key]);
    $renewal = wcs_create_renewal_order($subscription);
    if (is_wp_error($renewal)) {
        t_ok(false, "$key renouvellement créé", $renewal->get_error_message());
        continue;
    }
    $renewal = t_fresh($renewal->get_id());
    t_eq($total, $renewal->get_total(), "$key renouvellement : total TTC identique à l'abonnement");
    t_eq($tax, $renewal->get_total_tax(), "$key renouvellement : TVA présente");
    t_eq(wc_prices_include_tax(), (bool) $renewal->get_prices_include_tax(), "$key renouvellement : drapeau prix TTC de la boutique");
    // HT (lignes + port) + TVA = TTC
    $ht = (float) $renewal->get_total() - (float) $renewal->get_total_tax();
    $lines = (float) $renewal->get_subtotal() - (float) $renewal->get_discount_total() + (float) $renewal->get_shipping_total();
    t_ok(abs($lines - $ht) < 0.011, "$key renouvellement : HT + TVA = TTC", "HT $lines vs total-TVA $ht");
}
