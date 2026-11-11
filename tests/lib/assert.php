<?php
/**
 * Mini-bibliothèque d'assertions pour les scénarios (exécutés via `wp eval-file`).
 * Chaque assertion imprime une ligne "PASS: ..." ou "FAIL: ..." ; run.sh compte les FAIL.
 */

if (!function_exists('t_ok')) {
    function t_ok(bool $condition, string $label, string $detail = ''): bool {
        echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . ($condition || $detail === '' ? '' : ' → ' . $detail) . "\n";
        return $condition;
    }

    function t_eq($expected, $actual, string $label): bool {
        $ok = is_numeric($expected) && is_numeric($actual)
            ? abs((float) $expected - (float) $actual) < 0.0001
            : $expected === $actual;
        return t_ok($ok, $label, 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }

    /** Relit un abonnement / une commande sans cache (comme une nouvelle requête) */
    function t_fresh(int $id) {
        wp_cache_flush_runtime();
        clean_post_cache($id);
        wp_cache_delete('order-items-' . $id, 'orders');
        if (class_exists('\Automattic\WooCommerce\Caches\OrderCache')) {
            try { wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($id); } catch (\Throwable $e) {}
        }
        return wc_get_order($id);
    }

    /** Somme des taxes d'une ligne */
    function t_line_tax($item): float {
        $taxes = $item->get_taxes();
        return (float) array_sum($taxes['total'] ?? array());
    }

    /** Identifiants du jeu de données (fixtures.php) */
    function t_ids(): array {
        return (array) get_option('wcs_retrofit_test_ids', array());
    }
}
