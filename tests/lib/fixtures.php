<?php
/**
 * Jeu de données : abonnements TTC sans TVA couvrant les cas à risque.
 * Exécuté via `wp eval-file tests/lib/fixtures.php [bulk]` sur un site vierge.
 *
 *   S1  FR, 30 €                         + 1 renouvellement impayé (pending) + 1 payé (completed)
 *   S2  client BE, 30 €                  (WooCommerce appliquerait 21 % si on le laissait recalculer)
 *   S3  FR, 30 € + 5 € de port           (le port ne doit pas être taxé)
 *   S4  FR, coupon récurrent 30 → 27 €   (sous-total / remise)
 *   S5  FR, 3 × 9,99 €                   (arrondis)
 *   S6  FR, produit supprimé depuis
 *   S7  FR, ligne en classe "reduced-rate"
 *   S8  déjà avec TVA                    (doit être ignoré)
 *   S9  créé après la date limite        (hors périmètre)
 *   bulk : 230 abonnements supplémentaires (lots de 100)
 */

update_option('woocommerce_default_country', 'FR');
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_prices_include_tax', 'yes');
update_option('woocommerce_tax_based_on', 'billing');
update_option('woocommerce_tax_classes', "Reduced rate\nZero rate");

$rates = array(
    'fr' => WC_Tax::_insert_tax_rate(array('tax_rate_country' => 'FR', 'tax_rate' => '20.0000', 'tax_rate_name' => 'TVA FR', 'tax_rate_priority' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '')),
    'be' => WC_Tax::_insert_tax_rate(array('tax_rate_country' => 'BE', 'tax_rate' => '21.0000', 'tax_rate_name' => 'TVA BE', 'tax_rate_priority' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '')),
    'fr55' => WC_Tax::_insert_tax_rate(array('tax_rate_country' => 'FR', 'tax_rate' => '5.5000', 'tax_rate_name' => 'TVA FR réduite', 'tax_rate_priority' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => 'reduced-rate')),
);

$make_product = function (string $name, string $price): int {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price($price);
    $product->set_status('publish');
    return $product->save();
};
$p_box = $make_product('Box mensuelle', '30');
$p_refill = $make_product('Recharge', '9.99');
$p_deleted = $make_product('Produit supprimé', '30');

$customer_id = wp_insert_user(array('user_login' => 'client', 'user_pass' => wp_generate_password(), 'user_email' => 'client@example.test', 'role' => 'customer'));

$make_sub = function (string $country, array $lines, array $opts = array()) use ($customer_id) {
    $sub = wcs_create_subscription(array(
        'customer_id' => $customer_id, 'billing_period' => 'month', 'billing_interval' => 1,
        'start_date' => '2025-06-01 10:00:00', 'status' => 'pending',
    ));
    if (is_wp_error($sub)) {
        echo 'FAIL: création abonnement → ' . $sub->get_error_message() . "\n";
        return null;
    }
    $sub->set_billing_country($country);
    $sub->set_billing_email('client@example.test');
    foreach ($lines as $line) {
        $item = new WC_Order_Item_Product();
        $item->set_product_id($line['product']);
        $item->set_name($line['name'] ?? 'Ligne');
        $item->set_quantity($line['qty'] ?? 1);
        $item->set_subtotal($line['subtotal'] ?? $line['total']);
        $item->set_total($line['total']);
        if (!empty($line['tax_class'])) {
            $item->set_tax_class($line['tax_class']);
        }
        $item->add_meta_data('_custom_note', 'meta à préserver');
        $sub->add_item($item);
    }
    if (!empty($opts['shipping'])) {
        $shipping = new WC_Order_Item_Shipping();
        $shipping->set_method_title('Colissimo');
        $shipping->set_method_id('flat_rate');
        $shipping->set_total($opts['shipping']);
        $sub->add_item($shipping);
    }
    if (!empty($opts['coupon'])) {
        $coupon = new WC_Order_Item_Coupon();
        $coupon->set_code('promo3');
        $coupon->set_discount($opts['coupon']);
        $coupon->set_discount_tax(0);
        $sub->add_item($coupon);
    }
    if (!empty($opts['taxed'])) {
        foreach ($sub->get_items() as $item) {
            $item->set_subtotal(25);
            $item->set_total(25);
            $item->set_taxes(array('total' => array($opts['taxed'] => 5), 'subtotal' => array($opts['taxed'] => 5)));
        }
        $sub->update_taxes();
    }
    $sub->set_prices_include_tax(false);
    $sub->calculate_totals(false);
    $sub->set_date_created($opts['created'] ?? '2025-06-01 10:00:00');
    $sub->update_status('active');
    $sub->save();
    return $sub;
};

$ids = array();
$box = array('product' => $p_box, 'name' => 'Box mensuelle', 'total' => 30);
$ids['S1'] = $make_sub('FR', array($box))->get_id();
$ids['S2'] = $make_sub('BE', array($box))->get_id();
$ids['S3'] = $make_sub('FR', array($box), array('shipping' => 5))->get_id();
$ids['S4'] = $make_sub('FR', array(array('product' => $p_box, 'name' => 'Box mensuelle', 'subtotal' => 30, 'total' => 27)), array('coupon' => 3))->get_id();
$ids['S5'] = $make_sub('FR', array(array('product' => $p_refill, 'name' => 'Recharge', 'qty' => 3, 'total' => 29.97)))->get_id();
$ids['S6'] = $make_sub('FR', array(array('product' => $p_deleted, 'name' => 'Produit supprimé', 'total' => 30)))->get_id();
$ids['S7'] = $make_sub('FR', array($box + array('tax_class' => 'reduced-rate')))->get_id();
$ids['S8'] = $make_sub('FR', array($box), array('taxed' => $rates['fr']))->get_id();
$ids['S9'] = $make_sub('FR', array($box), array('created' => '2026-03-01 10:00:00'))->get_id();
wp_delete_post($p_deleted, true);

// Renouvellements de S1 : un impayé (doit être converti), un payé (doit rester intact)
$s1 = wcs_get_subscription($ids['S1']);
$pending = wcs_create_renewal_order($s1);
$pending->set_status('pending');
$pending->save();
$paid = wcs_create_renewal_order($s1);
$paid->set_status('completed');
$paid->save();
$ids['R1_pending'] = $pending->get_id();
$ids['R1_paid'] = $paid->get_id();

if (in_array('bulk', $args ?? array(), true)) {
    for ($i = 0; $i < 230; $i++) {
        $value = 10 + ($i % 37) * 0.37;
        $make_sub('FR', array(array('product' => $p_box, 'name' => 'Box', 'total' => $value)));
    }
}

update_option('wc_tax_retrofit_selected_tax_rate_id', $rates['fr']);
update_option('wc_tax_retrofit_date_limit', '2025-12-31');
update_option('wcs_retrofit_test_ids', $ids + array('rate_fr' => $rates['fr']));

echo 'Jeu de données : ' . count($ids) . " objets, HPOS=" . (wcs_is_custom_order_tables_usage_enabled() ? 'oui' : 'non') . "\n";
