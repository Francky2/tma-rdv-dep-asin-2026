# Étude de cas TMA — Prise de rendez-vous au guichet (DEP / ASIN 2026)

Bonjour et bienvenue sur ce dépôt. Vous y trouverez l'analyse des tickets du support, le module métier corrigé, la suite de tests automatisés et les requêtes SQL d'impact.

Pour répondre à l'exigence d'indépendance vis-à-vis des frameworks et de la base, le cœur métier est codé en **PHP pur** dans `app/Services/GestionRendezVous.php`. Le projet Laravel sert uniquement de socle pour lancer facilement les tests et les commandes.

---

## 1. Comment lancer les tests ?

Pour exécuter la suite de tests unitaires :
```bash
php artisan test --filter=GestionRendezVousTest
```

Pour rejouer directement les requêtes SQL sur l'export fourni :
```bash
python verifier_sql.py
```

---

## 2. Analyse et traitement des tickets

| Ticket | Signalement | Qualification | Diagnostic & Correction |
| :--- | :--- | :--- | :--- |
| **TMA-101** | RDV pris un samedi | **Anomalie** | Aucun contrôle sur les jours ouvrés. Corrigé dans `validerCreneauHoraire` : retour en HTTP 409 (*« Le centre est fermé le week-end. »*). |
| **TMA-102** | 4 usagers sur le même créneau | **Anomalie** | Absence de vérification du nombre d'inscrits actifs. Corrigé dans `verifierCapaciteCreneau` : blocage au 4e usager en HTTP 409 (*« Ce créneau est complet. »*). |
| **TMA-103** | Erreur 500 si lettre dans le NPI | **Anomalie** | Transtypage entier direct non sécurisé. Corrigé avec une validation regex stricte `^\d{10}$` retournant HTTP 400 (*« Le NPI doit comporter exactement 10 chiffres. »*). |
| **TMA-104** | Annulation refusée à H-40h | **Anomalie** | Condition de délai inversée ou mal calculée. Corrigé dans `annulerRendezVous` : le refus 409 n'intervient que si le délai est strictement inférieur à 24h. |
| **TMA-105** | RDV annulé passé à honoré | **Anomalie** | Les statuts terminaux n'étaient pas verrouillés. Corrigé dans `changerStatut` : impossible de modifier un RDV `annule`, `honore` ou `absent` (HTTP 409). |
| **TMA-106** | Prochain RDV relégué en bas | **Anomalie** | Tri SQL basique ne priorisant pas le futur. Corrigé dans `listerRendezVousUsager` : le prochain rendez-vous est isolé et placé en tête de la page 1. |
| **TMA-107** | Refus de prendre un 2e RDV | **Conforme** | Comportement normal. La règle stipule qu'un usager ne peut avoir qu'un seul RDV à venir tous services confondus. Le refus 409 est légitime. |

---

## 3. Résultats des requêtes SQL (`requetes.sql`)

1. **RDV confirmés pris le week-end** : **5 cas** identifiés dans la base (les samedis 21/11, 28/11, 05/12 et 12/12). Les usagers doivent être recontactés.
2. **Créneaux en surcapacité (> 3 actifs)** : **3 créneaux** trouvés, dont **1 créneau futur** le mardi 24/11/2026 à 14h00 comptant 4 usagers.
3. **RDV passés du statut « annulé » à un autre** : **4 cas** constatés dans l'historique (dont le RDV 292 du ticket TMA-105).

---

## 4. Anomalies bonus remarquées

En explorant la base, deux autres anomalies ont été corrigées dans le module :
* **Créneaux hors horaires d'ouverture** : Présence de rendez-vous enregistrés à 16h00 et 16h30 (le guichet fermant à 15h30).
* **NPI corrompus** : Présence d'identifiants à 11 et 12 chiffres au lieu des 10 chiffres réglementaires.