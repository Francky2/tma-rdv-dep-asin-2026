<?php

require_once __DIR__ . '/app/Services/GestionRendezVous.php';

use App\Services\GestionRendezVous;
use App\Services\GestionRendezVousException;

$succes = 0;
$total = 0;

function tester(string $nom, callable $callback) {
    global $succes, $total;
    $total++;
    try {
        $callback();
        echo "[OK] $nom\n";
        $succes++;
    } catch (\Throwable $e) {
        echo "[ECHEC] $nom : " . $e->getMessage() . "\n";
    }
}

echo "=== Test d'indépendance de GestionRendezVous (PHP pur, SANS Laravel) ===\n\n";

// Test TMA-101
tester("TMA-101 : Samedi refuse avec 409", function() {
    $maintenant = new DateTimeImmutable('2026-11-01 09:00:00');
    try {
        GestionRendezVous::prendreRendezVous('0614253647', 'PASSEPORT', '2026-11-14T10:00', $maintenant, []);
        throw new Exception("Devait échouer");
    } catch (GestionRendezVousException $e) {
        assert($e->getCodeHttp() === 409);
        assert($e->getMessage() === GestionRendezVous::MSG_WEEKEND);
    }
});

// Test TMA-102
tester("TMA-102 : Creneau plein (> 3) refuse avec 409", function() {
    $maintenant = new DateTimeImmutable('2026-11-01 08:00:00');
    $existants = [
        ['id' => 1, 'npi' => '1111111111', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
        ['id' => 2, 'npi' => '2222222222', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
        ['id' => 3, 'npi' => '3333333333', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
    ];
    try {
        GestionRendezVous::prendreRendezVous('4444444444', 'CNI', '2026-11-10T09:00', $maintenant, $existants);
        throw new Exception("Devait échouer");
    } catch (GestionRendezVousException $e) {
        assert($e->getCodeHttp() === 409);
        assert($e->getMessage() === GestionRendezVous::MSG_CRENEAU_PLEIN);
    }
});

// Test TMA-103
tester("TMA-103 : NPI avec lettre refuse avec 400 (sans 500)", function() {
    $maintenant = new DateTimeImmutable('2026-11-01 08:00:00');
    try {
        GestionRendezVous::prendreRendezVous('06142A3647', 'CNI', '2026-11-23T09:00', $maintenant, []);
        throw new Exception("Devait échouer");
    } catch (GestionRendezVousException $e) {
        assert($e->getCodeHttp() === 400);
        assert($e->getMessage() === GestionRendezVous::MSG_NPI_INVALIDE);
    }
});

// Test TMA-104
tester("TMA-104 : Annulation a H-40h acceptee", function() {
    $maintenant = new DateTimeImmutable('2026-11-17 18:00:00');
    $rdv = ['id' => 709, 'npi' => '0339205992', 'date_heure' => '2026-11-19 10:00:00', 'statut' => 'confirme'];
    $res = GestionRendezVous::annulerRendezVous($rdv, $maintenant);
    assert($res['statut'] === 'annule');
});

// Test TMA-105
tester("TMA-105 : RDV annule cloture refuse avec 409", function() {
    $maintenant = new DateTimeImmutable('2026-11-12 10:13:00');
    $rdv = ['id' => 292, 'statut' => 'annule'];
    try {
        GestionRendezVous::changerStatut($rdv, 'honore', 'AG-04', $maintenant);
        throw new Exception("Devait échouer");
    } catch (GestionRendezVousException $e) {
        assert($e->getCodeHttp() === 409);
        assert($e->getMessage() === GestionRendezVous::MSG_RDV_DEJA_CLOTURE);
    }
});

// Test TMA-107
tester("TMA-107 : Un seul rdv a venir (conforme)", function() {
    $maintenant = new DateTimeImmutable('2026-11-20 18:00:00');
    $existants = [['npi' => '0102030405', 'statut' => 'confirme', 'date_heure' => '2026-11-25 10:00:00']];
    try {
        GestionRendezVous::prendreRendezVous('0102030405', 'PERMIS', '2026-11-27T09:00', $maintenant, $existants);
        throw new Exception("Devait échouer");
    } catch (GestionRendezVousException $e) {
        assert($e->getCodeHttp() === 409);
        assert($e->getMessage() === GestionRendezVous::MSG_RDV_DEJA_A_VENIR);
    }
});

echo "\nRésultat : $succes / $total tests passés avec succès en PHP natif sans aucune dépendance !\n";
