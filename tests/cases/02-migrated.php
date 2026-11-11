<?php
/** État attendu APRÈS `wp tax-retrofit migrate` sur le jeu de données (fixtures.php) */
require __DIR__ . '/../lib/assert.php';

$ids = t_ids();
$rate = (int) $ids['rate_fr'];

$expect = array(
    // id => [total, tva, sous-total ligne, total ligne]
    'S1' => array(30, 5, 25, 25),
    'S2' => array(30, 5, 25, 25),     // client BE : taux choisi (FR 20 %), pas 21 %
    'S3' => array(35, 5, 25, 25),     // port 5 € inchangé
    'S4' => array(27, 4.5, 25, 22.5), // coupon : sous-total conservé
    'S5' => array(29.97, 4.99, 24.98, 24.98),
    'S6' => array(30, 5, 25, 25),     // produit supprimé
    'S7' => array(30, 5, 25, 25),     // classe reduced-rate conservée
);

foreach ($expect as $key => [$total, $tax, $line_subtotal, $line_total]) {
    $sub = t_fresh((int) $ids[$key]);
    $item = current($sub->get_items());
    t_eq($total, $sub->get_total(), "$key total TTC conservé");
    t_eq($tax, $sub->get_total_tax(), "$key TVA");
    t_eq($line_subtotal, $item->get_subtotal(), "$key sous-total HT de la ligne");
    t_eq($line_total, $item->get_total(), "$key total HT de la ligne");
    t_ok(array_keys(array_filter($item->get_taxes()['total'])) === array($rate), "$key taux appliqué = taux sélectionné");
    t_eq('meta à préserver', $item->get_meta('_custom_note'), "$key métadonnée de ligne conservée");
    t_eq(wc_prices_include_tax(), $sub->get_prices_include_tax(), "$key drapeau prix TTC aligné sur la boutique");
    t_ok(is_array($sub->get_meta('_wc_tax_retrofit_original')), "$key instantané d'origine enregistré");
}

$s3 = t_fresh((int) $ids['S3']);
t_eq(5, current($s3->get_items('shipping'))->get_total(), 'S3 port inchangé');
t_eq(0, $s3->get_shipping_tax(), 'S3 port non taxé');

$s4 = t_fresh((int) $ids['S4']);
$coupon = current($s4->get_items('coupon'));
t_eq(2.5, $coupon->get_discount(), 'S4 remise HT du coupon');
t_eq(0.5, $coupon->get_discount_tax(), 'S4 TVA de la remise');
t_eq(2.5, $s4->get_discount_total(), 'S4 remise totale de l\'abonnement');

t_eq('reduced-rate', current(t_fresh((int) $ids['S7'])->get_items())->get_tax_class(), 'S7 classe de taxe conservée');

$s8 = t_fresh((int) $ids['S8']);
t_ok(!$s8->get_meta('_wc_tax_retrofit_original'), 'S8 (déjà taxé) non modifié');
$s9 = t_fresh((int) $ids['S9']);
t_eq(0, $s9->get_total_tax(), 'S9 (après la date limite) non modifié');

// Renouvellements de S1
$pending = t_fresh((int) $ids['R1_pending']);
t_eq(30, $pending->get_total(), 'renouvellement impayé : total conservé');
t_eq(5, $pending->get_total_tax(), 'renouvellement impayé : converti en HT + TVA');
t_ok(in_array((int) $ids['R1_pending'], (array) (t_fresh((int) $ids['S1'])->get_meta('_wc_tax_retrofit_original')['renewals'] ?? array()), true),
    'renouvellement impayé référencé dans l\'instantané de S1');
$paid = t_fresh((int) $ids['R1_paid']);
t_eq(0, $paid->get_total_tax(), 'renouvellement payé : jamais modifié');

t_ok((bool) get_option('wc_tax_retrofit_executed'), 'migration marquée comme exécutée');
t_ok(!wc_tax_retrofit_is_locked(), 'verrou libéré en fin de migration');
