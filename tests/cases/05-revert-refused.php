<?php
/** Préparation : après migration, S2 est modifié à la main → `revert` doit le refuser */
require __DIR__ . '/../lib/assert.php';

$sub = t_fresh((int) t_ids()['S2']);
$item = current($sub->get_items());
$item->set_total(20);
$item->set_subtotal(20);
$sub->calculate_totals(false);
t_eq(20, current(t_fresh((int) t_ids()['S2'])->get_items())->get_total(), 'S2 modifié manuellement après migration');
