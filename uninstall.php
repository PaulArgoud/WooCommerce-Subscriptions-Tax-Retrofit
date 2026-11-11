<?php
/**
 * WooCommerce Subscription Tax Retrofit - Uninstall
 *
 * Supprime toutes les options et transients du plugin lors de la suppression.
 * Ce fichier est appelé automatiquement par WordPress quand l'utilisateur
 * supprime le plugin via l'interface admin.
 *
 * Volontairement CONSERVÉS (traçabilité fiscale de la migration) :
 * - les modifications apportées aux abonnements (HT + TVA) ;
 * - les notes "[Tax Retrofit vX.Y.Z]" ajoutées sur chaque abonnement migré ;
 * - les journaux WooCommerce (source "wcs-tax-retrofit"), supprimés par la
 *   rétention automatique de WooCommerce ;
 * - la méta _wc_tax_retrofit_original des abonnements / commandes migrés : valeurs
 *   d'origine (TTC sans TVA) nécessaires à `wp tax-retrofit revert` si le plugin est
 *   réinstallé, et preuve de l'état avant migration.
 *
 * @package WC_Subscriptions_Tax_Retrofit
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Supprime les données du plugin pour le site courant
 */
function wc_tax_retrofit_uninstall_site(): void {
    global $wpdb;

    // Options de configuration
    delete_option('wc_tax_retrofit_selected_tax_rate_id');
    delete_option('wc_tax_retrofit_date_limit');
    delete_option('wc_tax_retrofit_statuses');
    delete_option('wc_tax_retrofit_tolerance_setting');

    // Options d'état de migration
    delete_option('wc_tax_retrofit_executed');
    delete_option('wc_tax_retrofit_count');
    delete_option('wc_tax_retrofit_date');
    delete_option('wc_tax_retrofit_partial_count');
    delete_option('wc_tax_retrofit_current_offset');
    delete_option('wc_tax_retrofit_last_activity');

    // Verrou d'exécution et état de la migration en arrière-plan (1.4.5)
    delete_option('wc_tax_retrofit_lock');
    delete_option('wc_tax_retrofit_bg_state');

    // Transients
    delete_transient('wc_tax_retrofit_running'); // ancien verrou (≤ 1.4.4)
    delete_transient('wc_tax_retrofit_activation_notice');
    delete_transient('wc_tax_retrofit_detect_date_desc');
    delete_transient('wc_tax_retrofit_detect_date_asc');

    // Transients CSV par utilisateur (clé : wc_tax_retrofit_csv_{user_id})
    // 1) Via l'API : indispensable avec un cache objet persistant (Redis Object Cache,
    //    Memcached...), où les transients ne sont PAS stockés dans wp_options.
    //    Sources des IDs : registre tenu par le plugin (wc_tax_retrofit_csv_users),
    //    administrateurs WooCommerce actuels, et 0 (exécutions WP-CLI sans --user).
    $registered = get_option('wc_tax_retrofit_csv_users', array());
    $user_ids = array_merge(
        array(0),
        is_array($registered) ? array_map('intval', $registered) : array(),
        array_map('intval', get_users(array('capability' => 'manage_woocommerce', 'fields' => 'ID')))
    );
    foreach (array_unique($user_ids) as $user_id) {
        delete_transient('wc_tax_retrofit_csv_' . $user_id);
    }
    delete_option('wc_tax_retrofit_csv_users');

    // 2) Filet de sécurité en base (sans cache persistant) : utilisateurs ayant perdu
    //    leur capacité depuis, ou supprimés
    $like_csv = $wpdb->esc_like('_transient_wc_tax_retrofit_csv_') . '%';
    $like_timeout = $wpdb->esc_like('_transient_timeout_wc_tax_retrofit_csv_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_csv));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout));
}

// Multisite : les options et transients sont propres à chaque site.
// Nettoyer chaque site (le plugin a pu y être activé individuellement ou au niveau du réseau).
// Tâches Action Scheduler de la migration en arrière-plan (si WooCommerce est encore actif)
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_tax_retrofit_bg_batch'); // hook seul (voir wc_tax_retrofit_bg_cancel)
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
        switch_to_blog($site_id);
        wc_tax_retrofit_uninstall_site();
        restore_current_blog();
    }
} else {
    wc_tax_retrofit_uninstall_site();
}
