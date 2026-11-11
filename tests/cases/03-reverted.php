<?php
/** État attendu APRÈS `wp tax-retrofit revert` : retour exact à l'état d'origine (fixtures.php) */
require __DIR__ . '/../lib/assert.php';

$ids = t_ids();
$expect = array(
    // id => [total, sous-total ligne, total ligne]
    'S1' => array(30, 30, 30), 'S2' => array(30, 30, 30), 'S3' => array(35, 30, 30),
    'S4' => array(27, 30, 27), 'S5' => array(29.97, 29.97, 29.97), 'S6' => array(30, 30, 30), 'S7' => array(30, 30, 30),
);
foreach ($expect as $key => [$total, $line_subtotal, $line_total]) {
    $sub = t_fresh((int) $ids[$key]);
    $item = current($sub->get_items());
    t_eq($total, $sub->get_total(), "$key total d'origine");
    t_eq(0, $sub->get_total_tax(), "$key plus de TVA");
    t_eq(0, count($sub->get_items('tax')), "$key lignes de taxe supprimées");
    t_eq($line_subtotal, $item->get_subtotal(), "$key sous-total d'origine");
    t_eq($line_total, $item->get_total(), "$key total de ligne d'origine");
    t_ok($sub->get_prices_include_tax() === false, "$key drapeau prix TTC d'origine");
    t_ok(!$sub->get_meta('_wc_tax_retrofit_original'), "$key instantané supprimé");
    t_eq('meta à préserver', $item->get_meta('_custom_note'), "$key métadonnée de ligne toujours présente");
}
$coupon = current(t_fresh((int) $ids['S4'])->get_items('coupon'));
t_eq(3, $coupon->get_discount(), 'S4 remise d\'origine');
t_eq(0, $coupon->get_discount_tax(), 'S4 TVA de remise d\'origine');

$pending = t_fresh((int) $ids['R1_pending']);
t_eq(30, $pending->get_total(), 'renouvellement impayé : total d\'origine');
t_eq(0, $pending->get_total_tax(), 'renouvellement impayé : TVA retirée');
t_ok(!get_option('wc_tax_retrofit_executed'), 'état "exécuté" effacé quand plus rien n\'est migré');
