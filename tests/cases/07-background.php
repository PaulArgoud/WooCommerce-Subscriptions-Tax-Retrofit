<?php
/**
 * Arrière-plan : `start` programme la tâche Action Scheduler ; `check` vérifie l'état final
 * (run.sh exécute la file entre les deux avec `wp action-scheduler run`).
 */
require __DIR__ . '/../lib/assert.php';

$step = $args[0] ?? 'start';

if ($step === 'start') {
    t_ok(wc_tax_retrofit_bg_start(1), 'migration en arrière-plan lancée');
    t_ok(!wc_tax_retrofit_bg_start(1), 'second lancement refusé');
    t_eq(1, count(as_get_scheduled_actions(array('hook' => 'wc_tax_retrofit_bg_batch', 'status' => 'pending'), 'ids')), 'une tâche en attente');
}

if ($step === 'check') {
    $state = wc_tax_retrofit_bg_get_state();
    t_eq('completed', $state['status'] ?? '', 'état final : terminé');
    t_eq((int) ($args[1] ?? 7), (int) $state['updated'], 'nombre d\'abonnements migrés en arrière-plan');
    t_eq(1, (int) $state['renewals'], 'renouvellement impayé converti en arrière-plan');
    t_ok((bool) get_option('wc_tax_retrofit_executed'), 'migration marquée comme exécutée');
    t_ok(!get_option('wc_tax_retrofit_current_offset'), 'offset de reprise nettoyé');
    t_eq(0, count(as_get_scheduled_actions(array('hook' => 'wc_tax_retrofit_bg_batch', 'status' => 'pending'), 'ids')), 'plus aucune tâche en attente');
    $csv = get_transient('wc_tax_retrofit_csv_1');
    t_eq((int) $state['updated'], is_array($csv) ? count($csv) : 0, 'CSV de l\'utilisateur ayant lancé la migration');
    t_ok(!wc_tax_retrofit_is_locked(), 'verrou libéré');
}

if ($step === 'cancel') {
    delete_option(WC_TAX_RETROFIT_BG_STATE); // indépendant du scénario précédent
    t_ok(wc_tax_retrofit_bg_start(1), 'migration lancée (avant annulation)');
    wc_tax_retrofit_bg_cancel();
    t_eq('cancelled', wc_tax_retrofit_bg_get_state()['status'] ?? '', 'état : arrêtée');
    t_eq(0, count(as_get_scheduled_actions(array('hook' => 'wc_tax_retrofit_bg_batch', 'status' => 'pending'), 'ids')), 'tâches en attente supprimées');
    wc_tax_retrofit_bg_run_batch();
    t_eq(0, (int) (wc_tax_retrofit_bg_get_state()['updated'] ?? 0), 'aucun lot exécuté après annulation');
}
