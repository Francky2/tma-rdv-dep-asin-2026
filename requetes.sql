-- ============================================================================
-- ÉTUDE DE CAS TMA DEP/ASIN 2026 - REQUÊTES SQL D'IMPACT
-- Date d'export : Vendredi 20 novembre 2026 à 18 h 00 (heure du Bénin)
-- Compatible PostgreSQL, MySQL et SQLite.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Requête 1 : Rendez-vous confirmés à venir pris un samedi ou un dimanche
-- Objectif : Prévenir les usagers dont le rendez-vous a été accepté par erreur
--            alors que le centre est fermé le week-end.
-- Remarque : 'a venir' signifie date_heure > date de l'export ('2026-11-20 18:00:00')
--            et statut = 'confirme'.
-- SQLite : strftime('%w', date_heure) IN ('0', '6') (0 = Dimanche, 6 = Samedi)
-- PostgreSQL équivalent : EXTRACT(ISODOW FROM date_heure) IN (6, 7)
-- MySQL équivalent : DAYOFWEEK(date_heure) IN (1, 7)
-- ----------------------------------------------------------------------------
SELECT 
    id,
    npi,
    service,
    date_heure,
    statut,
    cree_le
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure > '2026-11-20 18:00:00'
  AND strftime('%w', date_heure) IN ('0', '6')
ORDER BY date_heure ASC;

-- Résultat sur la base fournie : 5 rendez-vous identifiés
-- id: 680 (2026-11-21 08:30:00 Samedi), id: 730 (2026-11-28 09:00:00 Samedi),
-- id: 834 (2026-12-05 10:00:00 Samedi), id: 857 (2026-12-05 10:00:00 Samedi),
-- id: 874 (2026-12-12 11:00:00 Samedi).


-- ----------------------------------------------------------------------------
-- Requête 2 : Créneaux qui comptent plus de 3 rendez-vous non annulés
-- Objectif : Détecter les créneaux en surcapacité (plus de 3 usagers actifs).
--            Rappel : Un créneau accueille au plus 3 usagers. Un rendez-vous
--            annulé libère sa place.
-- ----------------------------------------------------------------------------
SELECT 
    date_heure,
    COUNT(*) AS nb_rendez_vous_actifs
FROM rendez_vous
WHERE statut != 'annule'
GROUP BY date_heure
HAVING COUNT(*) > 3
ORDER BY date_heure ASC;

-- Résultat sur la base fournie : 3 créneaux identifiés avec 4 usagers chacun :
-- 1) 2026-10-22 10:30:00 (4 rdv)
-- 2) 2026-11-10 09:00:00 (4 rdv - ticket TMA-102)
-- 3) 2026-11-24 14:00:00 (4 rdv - créneau futur à désengorger d'urgence)


-- ----------------------------------------------------------------------------
-- Requête 3 : Rendez-vous passés du statut « annulé » à un autre statut
-- Objectif : Repérer les incohérences de cycle de vie où un rendez-vous
--            définitif 'annule' a été réactivé ou modifié (ex: ticket TMA-105).
-- ----------------------------------------------------------------------------
SELECT 
    h.id AS historique_id,
    h.rendez_vous_id,
    r.npi,
    r.service,
    r.date_heure,
    h.ancien_statut,
    h.nouveau_statut,
    h.modifie_le,
    h.modifie_par
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut != 'annule'
ORDER BY h.modifie_le ASC;

-- Résultat sur la base fournie : 4 rendez-vous identifiés :
-- - id: 208 (passé de 'annule' à 'honore' le 2026-10-07 par AG-01)
-- - id: 231 (passé de 'annule' à 'honore' le 2026-10-15 par AG-02)
-- - id: 262 (passé de 'annule' à 'absent' le 2026-10-29 par AG-03)
-- - id: 292 (passé de 'annule' à 'honore' le 2026-11-12 par AG-04 - ticket TMA-105)


-- ----------------------------------------------------------------------------
-- REQUÊTES BONUS (Anomalies supplémentaires non signalées détectées dans l'export)
-- ----------------------------------------------------------------------------

-- Bonus A : Rendez-vous pris en dehors de la plage horaire autorisée (08:00 à 15:30)
-- 3 cas détectés dans la base (ex: 16:00 ou 16:30) :
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE strftime('%H:%M', date_heure) < '08:00'
   OR strftime('%H:%M', date_heure) > '15:30'
   OR strftime('%M', date_heure) NOT IN ('00', '30');

-- Bonus B : NPI de longueur invalide insérés en base (différent de 10 chiffres)
-- 2 cas détectés (longueurs 11 et 12) :
SELECT id, npi, length(npi) AS longueur_npi, service, date_heure
FROM rendez_vous
WHERE length(npi) != 10;
