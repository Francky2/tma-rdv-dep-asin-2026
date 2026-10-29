<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DateTimeImmutable;
use App\Services\GestionRendezVous;
use App\Services\GestionRendezVousException;

class GestionRendezVousTest extends TestCase
{
    private DateTimeImmutable $refDate;

    protected function setUp(): void
    {
        parent::setUp();
        // Vendredi 20 novembre 2026 à 18h00 (heure du Bénin)
        $this->refDate = new DateTimeImmutable('2026-11-20 18:00:00');
    }

    /**
     * TMA-101 : Un usager a pris rendez-vous un samedi (2026-11-14T10:00).
     * Refus avec 409 et "Le centre est fermé le week-end.".
     */
    public function test_tma_101_rejoue_samedi_refuse(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-01 09:00:00');

        try {
            GestionRendezVous::prendreRendezVous(
                '0614253647',
                'PASSEPORT',
                '2026-11-14T10:00',
                $maintenant,
                []
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_WEEKEND, $e->getMessage());
        }
    }

    public function test_tma_101_dimanche_refuse(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-01 09:00:00');

        try {
            GestionRendezVous::prendreRendezVous(
                '0614253647',
                'PASSEPORT',
                '2026-11-15T10:00',
                $maintenant,
                []
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_WEEKEND, $e->getMessage());
        }
    }

    /**
     * TMA-102 : Le 10/11/2026 à 09:00, 3 rendez-vous existaient déjà (632, 644, 681).
     * Le 4e (n° 697) doit être refusé avec 409 et "Ce créneau est complet.".
     */
    public function test_tma_102_rejoue_creneau_plein(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-01 08:00:00');
        $creneau = '2026-11-10T09:00';
        $existants = [
            ['id' => 632, 'npi' => '0111111111', 'service' => 'PASSEPORT', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
            ['id' => 644, 'npi' => '0222222222', 'service' => 'CNI', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
            ['id' => 681, 'npi' => '0333333333', 'service' => 'PERMIS', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
        ];

        try {
            GestionRendezVous::prendreRendezVous(
                '0444444444',
                'CNI',
                $creneau,
                $maintenant,
                $existants
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_CRENEAU_PLEIN, $e->getMessage());
        }
    }

    public function test_tma_102_annulation_libere_place(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-01 08:00:00');
        $creneau = '2026-11-10T09:00';
        $existants = [
            ['id' => 632, 'npi' => '0111111111', 'service' => 'PASSEPORT', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
            ['id' => 644, 'npi' => '0222222222', 'service' => 'CNI', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'annule'],
            ['id' => 681, 'npi' => '0333333333', 'service' => 'PERMIS', 'date_heure' => '2026-11-10 09:00:00', 'statut' => 'confirme'],
        ];

        $rdv = GestionRendezVous::prendreRendezVous(
            '0444444444',
            'CNI',
            $creneau,
            $maintenant,
            $existants
        );

        $this->assertSame('confirme', $rdv['statut']);
    }

    /**
     * TMA-103 : Usager entre "06142A3647".
     * Rejet avec HTTP 400 et "Le NPI doit comporter exactement 10 chiffres." (jamais 500).
     */
    public function test_tma_103_rejoue_npi_avec_lettre(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-01 08:00:00');

        try {
            GestionRendezVous::prendreRendezVous(
                '06142A3647',
                'CNI',
                '2026-11-23T09:00',
                $maintenant,
                []
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(400, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_NPI_INVALIDE, $e->getMessage());
        }
    }

    public function test_tma_103_npi_longueur_incorrecte(): void
    {
        foreach (['123', '01234567890', ''] as $badNpi) {
            try {
                GestionRendezVous::validerNpi($badNpi);
                $this->fail('Exception attendue pour NPI ' . $badNpi);
            } catch (GestionRendezVousException $e) {
                $this->assertSame(400, $e->getCodeHttp());
                $this->assertSame(GestionRendezVous::MSG_NPI_INVALIDE, $e->getMessage());
            }
        }
    }

    /**
     * TMA-104 : Annulation le mardi 17/11/2026 à 18h00 pour un rendez-vous le jeudi 19/11/2026 à 10h00.
     * Délai = 40h (> 24h) -> Annulation acceptée.
     */
    public function test_tma_104_rejoue_annulation_40h_avant_acceptee(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-17 18:00:00');
        $rdv709 = [
            'id' => 709,
            'npi' => '0339205992',
            'service' => 'PERMIS',
            'date_heure' => '2026-11-19 10:00:00',
            'statut' => 'confirme',
        ];

        $annule = GestionRendezVous::annulerRendezVous($rdv709, $maintenant);
        $this->assertSame('annule', $annule['statut']);
    }

    public function test_tma_104_annulation_moins_24h_refusee(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-18 14:00:00'); // 20h avant
        $rdv709 = [
            'id' => 709,
            'npi' => '0339205992',
            'service' => 'PERMIS',
            'date_heure' => '2026-11-19 10:00:00',
            'statut' => 'confirme',
        ];

        try {
            GestionRendezVous::annulerRendezVous($rdv709, $maintenant);
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_ANNULATION_TARDIVE, $e->getMessage());
        }
    }

    /**
     * TMA-105 : Rendez-vous n° 292 déjà au statut 'annule'.
     * L'agent tente de le passer à 'honore' -> Rejet 409 "Ce rendez-vous est déjà clôturé.".
     */
    public function test_tma_105_rejoue_rdv_annule_cloture(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-12 10:13:00');
        $rdv292 = [
            'id' => 292,
            'npi' => '0330677555',
            'service' => 'PASSEPORT',
            'date_heure' => '2026-11-12 10:00:00',
            'statut' => 'annule',
        ];

        try {
            GestionRendezVous::changerStatut($rdv292, 'honore', 'AG-04', $maintenant);
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_RDV_DEJA_CLOTURE, $e->getMessage());
        }
    }

    public function test_tma_105_statut_invalide(): void
    {
        $rdv = ['id' => 1, 'statut' => 'confirme', 'date_heure' => '2026-12-01 10:00:00'];
        try {
            GestionRendezVous::changerStatut($rdv, 'inconnu', 'AG-01', new DateTimeImmutable());
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(400, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_STATUT_INCONNU, $e->getMessage());
        }
    }

    /**
     * TMA-106 : Usager 0203040506 a 13 rendez-vous.
     * Le prochain rdv (le 02/12/2026) doit être en tête absolue de la page 1.
     */
    public function test_tma_106_rejoue_prochain_rdv_en_tete(): void
    {
        $tousRdv = [
            ['id' => 1, 'npi' => '0203040506', 'service' => 'PASSEPORT', 'date_heure' => '2026-01-05 08:30:00', 'statut' => 'honore'],
            ['id' => 2, 'npi' => '0203040506', 'service' => 'CNI', 'date_heure' => '2026-02-12 10:00:00', 'statut' => 'honore'],
            ['id' => 3, 'npi' => '0203040506', 'service' => 'PERMIS', 'date_heure' => '2026-03-03 14:30:00', 'statut' => 'absent'],
            ['id' => 4, 'npi' => '0203040506', 'service' => 'PASSEPORT', 'date_heure' => '2026-04-21 09:00:00', 'statut' => 'honore'],
            ['id' => 5, 'npi' => '0203040506', 'service' => 'CNI', 'date_heure' => '2026-05-08 11:30:00', 'statut' => 'honore'],
            ['id' => 6, 'npi' => '0203040506', 'service' => 'PERMIS', 'date_heure' => '2026-05-19 15:00:00', 'statut' => 'absent'],
            ['id' => 7, 'npi' => '0203040506', 'service' => 'PASSEPORT', 'date_heure' => '2026-06-02 08:00:00', 'statut' => 'honore'],
            ['id' => 8, 'npi' => '0203040506', 'service' => 'CNI', 'date_heure' => '2026-06-16 13:30:00', 'statut' => 'honore'],
            ['id' => 9, 'npi' => '0203040506', 'service' => 'PERMIS', 'date_heure' => '2026-07-07 10:30:00', 'statut' => 'honore'],
            ['id' => 10, 'npi' => '0203040506', 'service' => 'PASSEPORT', 'date_heure' => '2026-07-28 09:30:00', 'statut' => 'absent'],
            ['id' => 11, 'npi' => '0203040506', 'service' => 'CNI', 'date_heure' => '2026-08-11 14:00:00', 'statut' => 'honore'],
            ['id' => 259, 'npi' => '0203040506', 'service' => 'CNI', 'date_heure' => '2026-09-25 11:00:00', 'statut' => 'honore'],
            ['id' => 826, 'npi' => '0203040506', 'service' => 'PASSEPORT', 'date_heure' => '2026-12-02 08:30:00', 'statut' => 'confirme'],
        ];

        $maintenant = new DateTimeImmutable('2026-11-20 18:00:00');
        $res = GestionRendezVous::listerRendezVousUsager('0203040506', $tousRdv, $maintenant, 1, 10);

        $this->assertSame(1, $res['page']);
        $this->assertSame(10, $res['parPage']);
        $this->assertSame(13, $res['total']);
        $this->assertCount(10, $res['rendezVous']);

        // Le premier élément doit être le rdv futur du 02/12/2026 (id: 826)
        $this->assertSame(826, $res['rendezVous'][0]['id']);
        $this->assertSame('2026-12-02 08:30:00', $res['rendezVous'][0]['date_heure']);

        // Le deuxième doit être le rdv le plus récent des passés (id: 259 du 25/09/2026)
        $this->assertSame(259, $res['rendezVous'][1]['id']);
    }

    /**
     * TMA-107 : Comportement conforme.
     * Un usager ne peut avoir qu'un seul rendez-vous à venir, tous services confondus.
     */
    public function test_tma_107_rejoue_comportement_conforme(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-20 18:00:00');
        $existants = [
            [
                'id' => 800,
                'npi' => '0102030405',
                'service' => 'PASSEPORT',
                'date_heure' => '2026-11-25 10:00:00',
                'statut' => 'confirme',
            ],
        ];

        try {
            GestionRendezVous::prendreRendezVous(
                '0102030405',
                'PERMIS',
                '2026-11-27T09:00',
                $maintenant,
                $existants
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_RDV_DEJA_A_VENIR, $e->getMessage());
        }
    }

    /**
     * Tests Bonus : Horaires hors grille et date passée.
     */
    public function test_bonus_heure_hors_grille(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-20 18:00:00');
        try {
            GestionRendezVous::prendreRendezVous(
                '0123456789',
                'PASSEPORT',
                '2026-11-25T16:00',
                $maintenant,
                []
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_HEURE_HORS_GRILLE, $e->getMessage());
        }
    }

    public function test_bonus_date_passee(): void
    {
        $maintenant = new DateTimeImmutable('2026-11-20 18:00:00');
        try {
            GestionRendezVous::prendreRendezVous(
                '0123456789',
                'PASSEPORT',
                '2026-11-19T10:00',
                $maintenant,
                []
            );
            $this->fail('Exception attendue');
        } catch (GestionRendezVousException $e) {
            $this->assertSame(409, $e->getCodeHttp());
            $this->assertSame(GestionRendezVous::MSG_DATE_PASSEE, $e->getMessage());
        }
    }
}
