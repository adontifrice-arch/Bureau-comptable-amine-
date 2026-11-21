<?php

namespace App\Support;

/**
 * Types de documents qu'un client envoie au cabinet, et leur traitement :
 * - comptabilité : lecture (OCR par l'IA) puis proposition dans le journal approprié, à valider ;
 * - immobilisations : lecture de la facture d'immobilisation ou du contrat de leasing ;
 * - paie : import (pointage, congés, acomptes et prêts) ou classement dans la fiche du salarié ;
 * - GED seulement : classement, sans traitement.
 * Chaque type a sa catégorie de GED (config cabinet.GC).
 */
class TypesPieces
{
    public const DESTINATIONS = ['compta' => 'Comptabilité', 'immobilisations' => 'Immobilisations', 'paie' => 'Paie', 'ged' => 'GED (classement)'];

    /** clé => [libellé proposé au client, destination, catégorie GED, journal] */
    public const TYPES = [
        'facture_achat' => ['Facture d’achat', 'compta', 'Factures d’achat', 'ACH'],
        'facture_vente' => ['Facture de vente', 'compta', 'Factures de vente', 'VTE'],
        'releve_bancaire' => ['Relevé bancaire', 'compta', 'Relevés bancaires', 'BQ'],
        'tableau_achats' => ['Tableau (liste) des factures d’achat', 'compta', 'Factures d’achat', 'ACH'],
        'tableau_ventes' => ['Tableau (liste) des factures de vente', 'compta', 'Factures de vente', 'VTE'],
        'traites_cheques' => ['Copies de traites ou de chèques', 'compta', 'Traites et chèques', 'BQ'],
        'etat_caisse' => ['État de caisse', 'compta', 'États de caisse', 'CAI'],
        'piece_od' => ['Autre pièce comptable (opérations diverses)', 'compta', 'Pièces comptables diverses', 'OD'],
        'facture_immobilisation' => ['Facture d’achat d’une immobilisation (matériel, véhicule, local…)', 'immobilisations', 'Factures d’immobilisations', 'ACH'],
        'contrat_leasing' => ['Contrat de leasing', 'immobilisations', 'Contrats de leasing', null],
        'pointage' => ['Pointage de la paie (jours, heures, primes)', 'paie', 'Documents de paie', null],
        'etat_conges' => ['État des congés', 'paie', 'Documents de paie', null],
        'etat_acomptes_prets' => ['État des acomptes et prêts', 'paie', 'Documents de paie', null],
        'contrat_travail' => ['Contrat de travail ou CIVP', 'paie', 'Contrats de travail du personnel', null],
        'piece_identite' => ['Carte d’identité d’un salarié', 'paie', 'Documents des salariés', null],
        'document_salarie' => ['Autre document concernant un salarié', 'paie', 'Documents des salariés', null],
        'juridique' => ['Document juridique (statuts, RNE, patente…)', 'ged', 'Autres documents', null],
        'courrier' => ['Courrier et correspondance', 'ged', 'Courrier et correspondance', null],
        'autre' => ['Autre document', 'ged', 'Autres documents', null],
    ];

    /** Types pour lesquels le mois concerné est demandé au client. */
    public const AVEC_PERIODE = ['pointage', 'releve_bancaire', 'etat_caisse', 'etat_conges', 'etat_acomptes_prets'];

    public const STATUTS = [
        'en_attente' => ['En cours de lecture', 'badge-blue'],
        'propose' => ['Proposé, à valider', 'badge-amber'],
        'importe' => ['Intégré', 'badge-green'],
        'a_saisir' => ['À saisir', 'badge-gray'],
        'erreur' => ['Erreur', 'badge-red'],
    ];

    public static function libelle(?string $type): string
    {
        return self::TYPES[$type][0] ?? 'Document';
    }

    public static function destination(?string $type): string
    {
        return self::TYPES[$type][1] ?? 'ged';
    }

    public static function categorie(?string $type): string
    {
        return self::TYPES[$type][2] ?? 'Autres documents';
    }

    public static function journal(?string $type): ?string
    {
        return self::TYPES[$type][3] ?? null;
    }

    /** Types groupés par destination, pour les listes déroulantes. */
    public static function parDestination(): array
    {
        $g = [];
        foreach (self::TYPES as $k => [$lib, $dest]) $g[self::DESTINATIONS[$dest]][$k] = $lib;
        return $g;
    }
}
