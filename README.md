# TMA DEP / ASIN 2026 — Prise de rendez-vous au guichet

Dépôt rendu pour l'étude de cas TMA. Il contient la qualification des tickets
TMA-101 à TMA-107, le module de règles corrigé, les tests, les requêtes SQL
d'impact et ce README.

Stack : Laravel 11 / PHP 8.2 pour le harnais (tests, artisan), mais le module
de règles lui-même est en PHP pur, sans dépendance à Laravel ni à la base.
C'est volontaire : le jury peut copier `app/Rdv/` dans n'importe quel projet
PHP et le faire tourner.

---

## Lancer les tests

```bash
php artisan test --filter=GestionRendezVousTest
# ou
./vendor/bin/phpunit tests/Unit/GestionRendezVousTest.php