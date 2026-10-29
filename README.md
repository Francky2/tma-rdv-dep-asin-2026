# Épreuve Technique TMA DEP / ASIN 2026 - Système de Prise de Rendez-vous (Laravel / PHP)

Ce dépôt contient la qualification, le diagnostic, le module de règles de gestion corrigé, les tests automatisés et l'analyse d'impact SQL relatifs aux tickets du support (TMA-101 à TMA-107).

---

## 1. Exécution des tests automatisés

Le module de règles de gestion est implémenté dans `app/Services/GestionRendezVous.php` (indépendant de toute base de données ou framework, utilisant des objets DateTime natifs et recevant l'heure courante en paramètre pour être 100% testable).

Pour exécuter la suite complète de tests PHPUnit :
```bash
php artisan test --filter=GestionRendezVousTest
```
ou directement :
```bash
./vendor/bin/phpunit tests/Unit/GestionRendezVousTest.php
```

Pour exécuter les requêtes SQL d'impact sur l'export SQLite fourni :
```bash
python -c "
import sqlite3
con = sqlite3.connect(':memory:')
with open('base_rendez_vous.sql', 'r', encoding='utf-8') as f:
    con.executescript(f.read())
cur = con.cursor()
with open('requetes.sql', 'r', encoding='utf-8') as f:
    sql = f.read()
print('Base chargée avec succès et prête.')
"
```

---

## 2. Synthèse et qualification des tickets

| Ticket | Signalé par | Qualification | Règle de gestion (Section 2) | Diagnostic / Cause probable |
| :--- | :--- | :--- | :--- | :--- |
| **TMA-101** | Usager | **Anomalie** | « Le centre reçoit du lundi au vendredi... » & Refus 409 : « Le centre est fermé le week-end. » | Absence de contrôle du jour de la semaine (`date('N')` ou `$dt->format('N')`). La date du samedi 14/11/2026 a été acceptée avec un code 201. |
| **TMA-102** | Chef de centre | **Anomalie** | « Un créneau accueille au plus 3 usagers. Un rendez-vous annulé libère sa place. » & Refus 409 : « Ce créneau est complet. » | Absence de vérification du nombre de réservations actives existantes sur le créneau (ou condition de concurrence / comptage incorrect des statuts). |
| **TMA-103** | Agent | **Anomalie** | « Un rendez-vous est pris pour un usager, identifié par son NPI, composé d’exactement 10 chiffres » & Refus 400 : « Le NPI doit comporter exactement 10 chiffres. » | Cast non protégé en entier (`(int)$npi`) provoquant une exception non interceptée retournant une erreur 500 interne au lieu d'une validation regex 400. |
| **TMA-104** | Usager | **Anomalie** | « L’usager peut annuler son rendez-vous jusqu’à 24 heures avant l’heure prévue. » | Inversion du sens de la condition dans le code (`maintenant < rdv - 24h` inversé) ou calcul erroné du délai horaire. Le 17/11 à 18h pour le 19/11 à 10h donne 40h d'avance (> 24h), l'annulation aurait dû être acceptée. |
| **TMA-105** | Agent | **Anomalie** | « Un rendez-vous « confirmé » devient « honoré », « absent » ou « annulé ». Ces trois statuts sont définitifs. » & Refus 409 : « Ce rendez-vous est déjà clôturé. » | Absence de verrouillage sur les statuts terminaux. L'API a permis à un rendez-vous `annule` de muter vers `honore`. |
| **TMA-106** | Usager | **Anomalie** | « Les rendez-vous d’un usager sont listés du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de 10. » | Tri SQL basique par `date_heure DESC` sans extraire prioritairement le prochain rendez-vous futur en tête de liste avant pagination. |
| **TMA-107** | Usager | **Conforme** | « Un usager ne peut avoir qu’un seul rendez-vous à venir, tous services confondus. » & Refus 409 : « Vous avez déjà un rendez-vous à venir. » | Comportement strictement conforme. L'usager ayant déjà un passeport à venir, la demande pour le permis a été légitimement rejetée avec 409. |

---

## 3. Détail par ticket

### TMA-101 : Rendez-vous pris un samedi
- **Qualification** : Anomalie.
- **Règle** : Section 2 — « Le centre reçoit du lundi au vendredi... » ; « Samedi ou dimanche -> 409 Le centre est fermé le week-end. ».
- **Diagnostic** : Le service de réservation vérifie l'heure mais oublie de vérifier le jour calendaire (ex: `$dt->format('N') >= 6`).
- **Correction** : Contrôle systématique du jour de la semaine dans `validerCreneauHoraire()` ; rejet avec code 409 et message exact si samedi (6) ou dimanche (7).
- **Test** : `test_tma_101_rejoue_samedi_refuse` et `test_tma_101_dimanche_refuse`.

### TMA-102 : Dépassement de capacité sur un créneau (4 usagers)
- **Qualification** : Anomalie.
- **Règle** : Section 2 — « Un créneau accueille au plus 3 usagers. Un rendez-vous annulé libère sa place. » ; « Créneau plein -> 409 Ce créneau est complet. ».
- **Diagnostic** : Absence de validation pré-insertion sur la volumétrie du créneau ou mauvaise prise en compte des statuts annulés.
- **Correction** : `verifierCapaciteCreneau()` compte les rendez-vous dont le statut est différent de `'annule'`. Si le total atteint 3, rejet avec code 409.
- **Test** : `test_tma_102_rejoue_creneau_plein` et `test_tma_102_annulation_libere_place`.

### TMA-103 : Erreur 500 sur NPI avec lettre
- **Qualification** : Anomalie.
- **Règle** : Section 2 — « NPI invalide -> 400 Le NPI doit comporter exactement 10 chiffres. », « jamais par une erreur interne. ».
- **Diagnostic** : L'API tente de convertir la chaîne NPI en entier sans validation préalable par expression régulière. La présence d'une lettre (`06142A3647`) déclenche une erreur interne (500).
- **Correction** : Validation stricte en amont via regex `/^[0-9]{10}$/` dans `validerNpi()`.
- **Test** : `test_tma_103_rejoue_npi_avec_lettre` et `test_tma_103_npi_longueur_incorrecte`.

### TMA-104 : Annulation refusée à tort 40 heures avant
- **Qualification** : Anomalie.
- **Règle** : Section 2 — « L’usager peut annuler son rendez-vous jusqu’à 24 heures avant l’heure prévue. » ; « Annulation tardive -> 409 Annulation impossible moins de 24 h avant le rendez-vous. ».
- **Diagnostic** : Entre le 17/11/2026 à 18h00 et le 19/11/2026 à 10h00, il s'écoule 40 heures. Le code d'origine comparait probablement à l'envers ou utilisait un calcul de différence erroné.
- **Correction** : Calcul précis du delta en secondes `$diffSecondes = $dtRdv->getTimestamp() - $maintenant->getTimestamp()`. Si `$diffSecondes < 86400`, rejet avec 409.
- **Test** : `test_tma_104_rejoue_annulation_40h_avant_acceptee` et `test_tma_104_annulation_moins_24h_refusee`.

### TMA-105 : Transition interdite d'un statut définitif
- **Qualification** : Anomalie.
- **Règle** : Section 2 — « Un rendez-vous « confirmé » devient « honoré », « absent » ou « annulé ». Ces trois statuts sont définitifs. » ; « Rendez-vous déjà clôturé -> 409 Ce rendez-vous est déjà clôturé. ».
- **Diagnostic** : L'endpoint de mise à jour du statut ne vérifiait pas le statut d'origine du rendez-vous, permettant d'écraser `'annule'` par `'honore'`.
- **Correction** : Vérification dans `changerStatut()` : si le statut actuel figure dans `['honore', 'absent', 'annule']`, rejet avec code 409 et message « Ce rendez-vous est déjà clôturé. ».
- **Test** : `test_tma_105_rejoue_rdv_annule_cloture` et `test_tma_105_statut_invalide`.

### TMA-106 : Ordre d'affichage et pagination incorrecte
- **Qualification** : Anomalie.
- **Règle** : Section 2 — « Les rendez-vous d’un usager sont listés du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de 10. ».
- **Diagnostic** : L'API applique un simple `ORDER BY date_heure DESC` ou par `id`. Le prochain rendez-vous futur (le 02/12/2026) se retrouvait masqué ou en fin de liste.
- **Correction** : Dans `listerRendezVousUsager()`, extraction prioritaire du rendez-vous futur le plus proche placé en première position (`index 0`), suivi des autres rendez-vous triés par date décroissante, avant découpage de la page.
- **Test** : `test_tma_106_rejoue_prochain_rdv_en_tete`.

### TMA-107 : Rejet d'un second rendez-vous pour un autre service
- **Qualification** : **Comportement conforme aux règles**.
- **Règle** : Section 2 — « Un usager ne peut avoir qu’un seul rendez-vous à venir, tous services confondus. » ; « Rendez-vous déjà à venir -> 409 Vous avez déjà un rendez-vous à venir. ».
- **Justification** : L'usager a déjà un rendez-vous de passeport confirmé à venir la semaine suivante. La règle interdit formellement un second rendez-vous quel que soit le service demandé. L'application a donc agi conformément aux spécifications.
- **Test** : `test_tma_107_rejoue_comportement_conforme`.

---

## 4. Mesure d'impact (Résultats des requêtes SQL)

Les requêtes complètes sont documentées dans le fichier `requetes.sql`.

### Requête 1 : Rendez-vous confirmés à venir pris un samedi ou un dimanche
- **Critère** : `statut = 'confirme'`, `date_heure > '2026-11-20 18:00:00'` et jour de la semaine = Samedi ou Dimanche.
- **Résultat** : **5 rendez-vous** ont été identifiés dans la base :
  1. `id: 680` | NPI: `0669147625` | PASSEPORT | Samedi 21/11/2026 08:30
  2. `id: 730` | NPI: `0679617916` | CNI | Samedi 28/11/2026 09:00
  3. `id: 834` | NPI: `0610588823` | CNI | Samedi 05/12/2026 10:00
  4. `id: 857` | NPI: `0671987446` | PERMIS | Samedi 05/12/2026 10:00
  5. `id: 874` | NPI: `0648953073` | PASSEPORT | Samedi 12/12/2026 11:00
- **Action TMA recommandée** : Prévenir d'urgence ces 5 usagers pour reprogrammer leur rendez-vous sur un jour ouvré.

### Requête 2 : Créneaux qui comptent plus de 3 rendez-vous non annulés
- **Critère** : `statut != 'annule'` groupé par `date_heure` avec `COUNT(*) > 3`.
- **Résultat** : **3 créneaux** en surcapacité (4 usagers chacun) :
  1. `2026-10-22 10:30:00` (passé - 4 usagers)
  2. `2026-11-10 09:00:00` (passé - 4 usagers, objet du ticket TMA-102)
  3. `2026-11-24 14:00:00` (**futur** - 4 usagers actifs !)
- **Action TMA recommandée** : Contacter le 4e usager du créneau du 24/11/2026 pour lui proposer un autre horaire.

### Requête 3 : Rendez-vous passés du statut « annulé » à un autre statut
- **Critère** : `historique_statuts` avec `ancien_statut = 'annule'` et `nouveau_statut != 'annule'`.
- **Résultat** : **4 rendez-vous** identifiés :
  1. `id: 208` (passé de 'annule' à 'honore' le 07/10/2026 par `AG-01`)
  2. `id: 231` (passé de 'annule' à 'honore' le 15/10/2026 par `AG-02`)
  3. `id: 262` (passé de 'annule' à 'absent' le 29/10/2026 par `AG-03`)
  4. `id: 292` (passé de 'annule' à 'honore' le 12/11/2026 par `AG-04`, ticket TMA-105)

---

## 5. Anomalies Bonus découvertes dans l'export SQL

1. **Rendez-vous hors de la plage horaire autorisée (08:00 - 15:30)** :
   - Règle enfreinte : « Le premier créneau commence à 8 h 00, le dernier à 15 h 30. »
   - 3 cas identifiés dans la base (`id: 490` à 16:00, `id: 698` à 16:30, `id: 842` à 16:00 futur).
   - **Correction appliquée** : Contrôle dans `validerCreneauHoraire()` rejetant toute heure hors grille avec HTTP 409.
2. **NPI corrompus en base (longueur != 10 chiffres)** :
   - Règle enfreinte : « NPI, composé d’exactement 10 chiffres ».
   - 2 cas identifiés (`id: 459` avec 11 chiffres, `id: 642` avec 12 chiffres).
   - **Correction appliquée** : Contrôle regex strict dans `validerNpi()`.
#   t m a - r d v - d e p - a s i n - 2 0 2 6  
 