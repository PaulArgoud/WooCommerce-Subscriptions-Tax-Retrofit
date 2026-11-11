# Tests d'intégration

Suite de tests de bout en bout : un WordPress jetable (base dédiée, recréée à chaque suite) avec WooCommerce, WooCommerce Subscriptions et le plugin. Les scénarios (`tests/cases/`) vérifient des montants réels en base, pas des mocks.

```bash
WCS_PATH=/chemin/vers/woocommerce-subscriptions \
DB_USER=root DB_PASS=root DB_PORT=3306 \
tests/run.sh
```

Code retour `0` si tout passe, `1` sinon. **La base `DB_NAME` (défaut `wcs_retrofit_tests`) est supprimée et recréée** : ne pointez jamais vers une base existante.

## Variables

| Variable | Défaut | Rôle |
|---|---|---|
| `WCS_PATH` | *(obligatoire)* | Dossier de WooCommerce Subscriptions (extension payante, non téléchargeable) |
| `PHP_BIN` | `php` | Binaire PHP (testé 7.4, 8.3, 8.5) |
| `DB_HOST` `DB_PORT` `DB_USER` `DB_PASS` `DB_NAME` | `127.0.0.1` `3306` `root` *(vide)* `wcs_retrofit_tests` | Serveur MySQL / MariaDB |
| `WC_PATH`, `WC_VERSION` | WooCommerce depuis wordpress.org | Dossier local de WooCommerce, ou version précise |
| `WP_CORE_DIR`, `WP_VERSION` | dernière version téléchargée | Copie locale de WordPress, ou version précise |
| `HPOS_MODES` | `yes no` | Stockage des commandes : HPOS (`yes`) et/ou posts classiques (`no`) |
| `REDIS_PORT` | *(désactivé)* | Active Redis Object Cache (serveur Redis requis sur ce port) |
| `IMFS` | `0` | `1` : Index WP MySQL For Speed, avec ses index haute performance |
| `MULTISITE` | `1` | `1` : teste aussi la désinstallation sur un réseau de 2 sites |
| `BULK` | `1` | `1` : scénarios à 237 abonnements (3 lots de 100), en CLI puis en arrière-plan |
| `SETUP_ONLY` | | `yes` ou `no` : monte le site (HPOS oui/non) et s'arrête, base conservée (débogage) |

Exemple complet : `REDIS_PORT=6379 IMFS=1 WCS_PATH=… tests/run.sh`.

## Ce qui est couvert

| Scénario | Vérifie |
|---|---|
| `01-load` | Chargement avant WooCommerce (ordre alphabétique), hooks, HPOS déclaré, tables InnoDB |
| CLI (dans `run.sh`) | Codes retour (taux inconnu, date ou tolérance invalide, flags manquants), JSON propre, CSV non cumulé, idempotence |
| `02-migrated` | 9 abonnements types : client hors pays, port, coupon, arrondis, produit supprimé, classe de taxe, déjà taxé, hors date. Renouvellements impayé (converti) et payé (intact) |
| `03-reverted` | `revert` : retour exact aux valeurs d'origine, métadonnées et renouvellements |
| `04-rollback` | Un écart de total annule toute la transaction (abonnement + renouvellement) |
| `05-revert-refused` | `revert` refuse un abonnement modifié depuis la migration |
| `06-lock` | Verrou atomique : exclusivité, jeton étranger, reprise d'un verrou expiré, refus CLI |
| MyISAM (dans `run.sh`) | Migration refusée si une table n'est pas InnoDB |
| `07-background` | Action Scheduler : lancement, double lancement refusé, exécution des lots, annulation |
| `08-uninstall` | Plus aucune option ni transient, y compris dans Redis ; multisite |

## Ajouter un scénario

1. Créer `tests/cases/NN-nom.php` (exécuté par `wp eval-file`), commencer par `require __DIR__ . '/../lib/assert.php';`.
2. Utiliser `t_ok()`, `t_eq()`, `t_fresh($id)` (relecture sans cache) et `t_ids()` (jeu de données de `lib/fixtures.php`).
3. Chaque ligne doit commencer par `PASS` ou `FAIL`, ce que `run.sh` compte.
4. Appeler `run_case NN-nom.php` dans `run.sh`.
