<?php

namespace App\Services;

use DateTime;
use DateTimeImmutable;
use DateInterval;
use Exception;

class GestionRendezVousException extends Exception
{
    private int $codeHttp;

    public function __construct(int $codeHttp, string $message)
    {
        parent::__construct($message);
        $this->codeHttp = $codeHttp;
    }

    public function getCodeHttp(): int
    {
        return $this->codeHttp;
    }
}

class GestionRendezVous
{
    public const SERVICES_VALIDES = ['PASSEPORT', 'CNI', 'PERMIS'];
    public const STATUTS_CHANGEMENT_VALIDES = ['honore', 'absent'];
    public const STATUTS_DEFINITIFS = ['honore', 'absent', 'annule'];

    public const FORMAT_DATE_ENTREE = 'Y-m-d\TH:i';

    // Messages d'erreur officiels de la spécification
    public const MSG_NPI_INVALIDE = 'Le NPI doit comporter exactement 10 chiffres.';
    public const MSG_SERVICE_INCONNU = 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.';
    public const MSG_DATE_ILLISIBLE = 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).';
    public const MSG_DATE_PASSEE = 'Impossible de prendre un rendez-vous dans le passé.';
    public const MSG_WEEKEND = 'Le centre est fermé le week-end.';
    public const MSG_HEURE_HORS_GRILLE = 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.';
    public const MSG_RDV_DEJA_A_VENIR = 'Vous avez déjà un rendez-vous à venir.';
    public const MSG_CRENEAU_PLEIN = 'Ce créneau est complet.';
    public const MSG_ANNULATION_NON_CONFIRME = 'Ce rendez-vous ne peut plus être annulé.';
    public const MSG_ANNULATION_TARDIVE = 'Annulation impossible moins de 24 h avant le rendez-vous.';
    public const MSG_STATUT_INCONNU = 'Statut invalide : honore ou absent.';
    public const MSG_RDV_DEJA_CLOTURE = 'Ce rendez-vous est déjà clôturé.';

    /**
     * Valide le NPI de l'usager (exactement 10 chiffres).
     * Refus : 400 Le NPI doit comporter exactement 10 chiffres.
     */
    public static function validerNpi(mixed $npi): void
    {
        if (!is_string($npi) || !preg_match('/^[0-9]{10}$/', $npi)) {
            throw new GestionRendezVousException(400, self::MSG_NPI_INVALIDE);
        }
    }

    /**
     * Valide le service demandé (PASSEPORT, CNI ou PERMIS).
     * Refus : 400 Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.
     */
    public static function validerService(string $service): void
    {
        if (!in_array($service, self::SERVICES_VALIDES, true)) {
            throw new GestionRendezVousException(400, self::MSG_SERVICE_INCONNU);
        }
    }

    /**
     * Parse la date/heure au format AAAA-MM-JJTHH:MM.
     * Refus : 400 Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).
     */
    public static function parserDateHeure(mixed $dateStr): DateTimeImmutable
    {
        if (!is_string($dateStr)) {
            throw new GestionRendezVousException(400, self::MSG_DATE_ILLISIBLE);
        }

        $dt = DateTimeImmutable::createFromFormat(self::FORMAT_DATE_ENTREE, $dateStr);
        $errors = DateTimeImmutable::getLastErrors();

        if ($dt === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0 || $dt->format(self::FORMAT_DATE_ENTREE) !== $dateStr) {
            throw new GestionRendezVousException(400, self::MSG_DATE_ILLISIBLE);
        }

        return $dt;
    }

    /**
     * Vérifie la grille horaire, jours ouvrés et antériorité.
     */
    public static function validerCreneauHoraire(DateTimeImmutable $dt, DateTimeImmutable $maintenant): void
    {
        if ($dt <= $maintenant) {
            throw new GestionRendezVousException(409, self::MSG_DATE_PASSEE);
        }

        // Jour de la semaine : 6 = Samedi, 7 = Dimanche (format 'N')
        $jourSemaine = (int)$dt->format('N');
        if ($jourSemaine === 6 || $jourSemaine === 7) {
            throw new GestionRendezVousException(409, self::MSG_WEEKEND);
        }

        // Créneau toutes les 30 min (00 ou 30) et secondes à 0
        $minute = (int)$dt->format('i');
        $seconde = (int)$dt->format('s');
        if (!in_array($minute, [0, 30], true) || $seconde !== 0) {
            throw new GestionRendezVousException(409, self::MSG_HEURE_HORS_GRILLE);
        }

        // Heure de 08:00 à 15:30 inclus
        $heureMinute = $dt->format('H:i');
        if ($heureMinute < '08:00' || $heureMinute > '15:30') {
            throw new GestionRendezVousException(409, self::MSG_HEURE_HORS_GRILLE);
        }
    }

    /**
     * Vérifie qu'un usager n'a pas déjà un rdv à venir, tous services confondus.
     */
    public static function verifierUniciteRdvAVenir(string $npi, DateTimeImmutable $maintenant, array $rdvExistants): void
    {
        foreach ($rdvExistants as $rdv) {
            if (($rdv['npi'] ?? null) === $npi && ($rdv['statut'] ?? null) === 'confirme') {
                $dtRdv = self::convertirEnDateTime($rdv['date_heure']);
                if ($dtRdv > $maintenant) {
                    throw new GestionRendezVousException(409, self::MSG_RDV_DEJA_A_VENIR);
                }
            }
        }
    }

    /**
     * Vérifie la capacité d'un créneau (au plus 3 usagers actifs, annulation libère place).
     */
    public static function verifierCapaciteCreneau(DateTimeImmutable $dt, array $rdvExistants): void
    {
        $nbActifs = 0;
        foreach ($rdvExistants as $rdv) {
            if (($rdv['statut'] ?? null) !== 'annule') {
                $dtRdv = self::convertirEnDateTime($rdv['date_heure']);
                if ($dtRdv->format('Y-m-d H:i') === $dt->format('Y-m-d H:i')) {
                    $nbActifs++;
                }
            }
        }

        if ($nbActifs >= 3) {
            throw new GestionRendezVousException(409, self::MSG_CRENEAU_PLEIN);
        }
    }

    /**
     * Effectue la prise de rendez-vous.
     */
    public static function prendreRendezVous(string $npi, string $service, string $dateHeureStr, DateTimeImmutable $maintenant, array $rdvExistants): array
    {
        self::validerNpi($npi);
        self::validerService($service);
        $dt = self::parserDateHeure($dateHeureStr);
        self::validerCreneauHoraire($dt, $maintenant);
        self::verifierUniciteRdvAVenir($npi, $maintenant, $rdvExistants);
        self::verifierCapaciteCreneau($dt, $rdvExistants);

        return [
            'npi' => $npi,
            'service' => $service,
            'date_heure' => $dt,
            'statut' => 'confirme',
            'cree_le' => $maintenant,
        ];
    }

    /**
     * Annule un rendez-vous (jusqu'à 24h avant l'heure prévue).
     */
    public static function annulerRendezVous(array $rdv, DateTimeImmutable $maintenant): array
    {
        if (($rdv['statut'] ?? null) !== 'confirme') {
            throw new GestionRendezVousException(409, self::MSG_ANNULATION_NON_CONFIRME);
        }

        $dtRdv = self::convertirEnDateTime($rdv['date_heure']);
        $diffSecondes = $dtRdv->getTimestamp() - $maintenant->getTimestamp();

        // 24 heures = 86400 secondes
        if ($diffSecondes < 86400) {
            throw new GestionRendezVousException(409, self::MSG_ANNULATION_TARDIVE);
        }

        $copie = $rdv;
        $copie['statut'] = 'annule';
        return $copie;
    }

    /**
     * Change le statut d'un rendez-vous par un agent ('honore' ou 'absent').
     */
    public static function changerStatut(array $rdv, string $nouveauStatut, string $agentId, DateTimeImmutable $maintenant): array
    {
        if (!in_array($nouveauStatut, self::STATUTS_CHANGEMENT_VALIDES, true)) {
            throw new GestionRendezVousException(400, self::MSG_STATUT_INCONNU);
        }

        $statutActuel = $rdv['statut'] ?? null;
        if (in_array($statutActuel, self::STATUTS_DEFINITIFS, true)) {
            throw new GestionRendezVousException(409, self::MSG_RDV_DEJA_CLOTURE);
        }

        $copie = $rdv;
        $copie['statut'] = $nouveauStatut;
        return $copie;
    }

    /**
     * Liste les rendez-vous d'un usager :
     * - Prochain rendez-vous en tête
     * - Suivi des autres triés du plus récent au plus ancien
     * - Par pages de 10
     */
    public static function listerRendezVousUsager(string $npi, array $tousRdv, DateTimeImmutable $maintenant, int $page = 1, int $parPage = 10): array
    {
        self::validerNpi($npi);
        if ($page < 1) {
            $page = 1;
        }

        // Filtrer les rendez-vous de l'usager
        $rdvsUsager = [];
        foreach ($tousRdv as $r) {
            if (($r['npi'] ?? null) === $npi) {
                $copie = $r;
                $copie['_dt'] = self::convertirEnDateTime($r['date_heure']);
                $rdvsUsager[] = $copie;
            }
        }

        // Isoler les rdv confirmés à venir
        $rdvsAVenir = [];
        foreach ($rdvsUsager as $r) {
            if ($r['statut'] === 'confirme' && $r['_dt'] > $maintenant) {
                $rdvsAVenir[] = $r;
            }
        }

        // Trier les rendez-vous à venir du plus proche au plus lointain
        usort($rdvsAVenir, fn($a, $b) => $a['_dt'] <=> $b['_dt']);
        $prochainRdv = $rdvsAVenir[0] ?? null;

        // Autres rendez-vous triés du plus récent au plus ancien (décroissant)
        $autresRdv = [];
        foreach ($rdvsUsager as $r) {
            if ($prochainRdv === null || ($r['id'] ?? null) !== ($prochainRdv['id'] ?? null)) {
                $autresRdv[] = $r;
            }
        }
        usort($autresRdv, fn($a, $b) => $b['_dt'] <=> $a['_dt']);

        $ordonnes = $prochainRdv !== null ? array_merge([$prochainRdv], $autresRdv) : $autresRdv;

        $total = count($ordonnes);
        $offset = ($page - 1) * $parPage;
        $elementsPage = array_slice($ordonnes, $offset, $parPage);

        // Nettoyer la clé interne '_dt'
        foreach ($elementsPage as &$item) {
            unset($item['_dt']);
        }
        unset($item);

        return [
            'page' => $page,
            'parPage' => $parPage,
            'total' => $total,
            'rendezVous' => $elementsPage,
        ];
    }

    private static function convertirEnDateTime(mixed $dateVal): DateTimeImmutable
    {
        if ($dateVal instanceof DateTimeImmutable) {
            return $dateVal;
        }
        if ($dateVal instanceof DateTime) {
            return DateTimeImmutable::createFromMutable($dateVal);
        }
        if (is_string($dateVal)) {
            // Nettoyage format SQL ou ISO
            $cleanStr = substr($dateVal, 0, 16);
            $cleanStr = str_replace('T', ' ', $cleanStr);
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $cleanStr);
            if ($dt !== false) {
                return $dt;
            }
        }
        throw new GestionRendezVousException(400, self::MSG_DATE_ILLISIBLE);
    }
}
