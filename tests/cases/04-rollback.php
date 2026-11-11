<?php
/**
 * ROLLBACK : un écart de total sur S1 (simulé par un hook) doit annuler TOUTE la transaction
 * de l'abonnement, y compris son renouvellement impayé. Les autres abonnements sont migrés.
 */
require __DIR__ . '/../lib/assert.php';

$ids = t_ids();
$s1 = (int) $ids['S1'];
add_action('woocommerce_order_after_calculate_totals', function ($and_taxes, $order) use ($s1) {
    if ($order->get_id() === $s1) {
        $order->set_total((float) $order->get_total() + 1);
    }
}, 10, 2);

$stats = wc_tax_retrofit_process(false, 0);
t_eq(1, count($stats['tolerance_warnings']), 'une alerte de tolérance');
t_eq(6, $stats['updated'], 'les 6 autres abonnements sont migrés');

$sub = t_fresh($s1);
t_eq(30, $sub->get_total(), 'S1 : total intact après ROLLBACK');
t_eq(0, $sub->get_total_tax(), 'S1 : aucune TVA après ROLLBACK');
t_ok(!$sub->get_meta('_wc_tax_retrofit_original'), 'S1 : pas d\'instantané après ROLLBACK');
t_eq(0, t_fresh((int) $ids['R1_pending'])->get_total_tax(), 'renouvellement de S1 : intact après ROLLBACK');
t_ok(!wc_tax_retrofit_is_locked(), 'verrou libéré');
