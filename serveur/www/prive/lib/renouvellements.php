<?php
// renouvellements.php - Demandes de renouvellement (D89) : envoyees par l'application dans les derniers jours
//                       d'une licence (op renouveler), acceptees (licence prolongee) ou refusees dans la console.
// ETDEL (c) 2026
// Janus, par ETDEL - https://github.com/theptitprince/Janus
// GPL-3.0-or-later + conditions additionnelles (article 7) : voir NOTICE

declare(strict_types=1);

require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/notification.php';

// Colonnes d'une demande de renouvellement, avec sa licence et son produit (alias rn, l, p).
const SQL_RENOUVELLEMENT = 'SELECT rn.*, l.titulaire, l.email, l.echeance, l.cle_indice, l.hors_ligne, l.id_poste, '
    . 'l.statut AS licence_statut, p.id AS produit_id, p.code AS produit_code, p.nom AS produit_nom '
    . 'FROM renouvellements rn JOIN licences l ON l.id = rn.licence_id JOIN distributions d ON d.id = l.distribution_id '
    . 'JOIN produits p ON p.id = d.produit_id';

/**
 * op renouveler : la licence de cette cle, liee a ce poste, non revoquee ni suspendue, avec une echeance (une
 * licence expiree de quelques instants est acceptee). Une seule demande en attente par licence : un renvoi
 * repond comme la premiere fois. Reponse ok : numero et statut de la demande.
 */
function renouvellement_demander(PDO $db, array $config, array $r, array $produit, array $base, string $ip,
                                 int $maintenant): array
{
    $nouveau = null;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $lic = licence_par_cle($db, $r['cle'], $produit);
        $code = $lic === null ? 'cle_invalide'
            : licence_controler($lic, $r['machine'], false, $maintenant, id_poste($r['machine'], $r['produit']));
        if ($code === null || $code === 'expiree') {
            $code = $lic['echeance'] === null ? 'licence_perpetuelle' : null;
        }
        if ($code !== null) {
            $db->exec('COMMIT');
            return api_refus($base, $code);
        }
        $existant = db_ligne($db, "SELECT * FROM renouvellements WHERE licence_id = ? AND statut = 'en_attente'",
            [(int)$lic['id']]);
        if ($existant === null) {
            $nouveau = [
                'licence_id' => (int)$lic['id'],
                'machine' => $r['machine'],
                'nom_ordinateur' => $r['poste'],
                'version_appli' => $r['version'],
                'message' => $r['message'] === '' ? null : $r['message'],
                'statut' => 'en_attente',
                'echeance_avant' => (int)$lic['echeance'],
                'cree_le' => $maintenant,
                'ip' => $ip,
            ];
            $nouveau['id'] = db_inserer($db, 'renouvellements', $nouveau);
            $existant = $nouveau;
            journal_ecrire($db, 'poste:' . id_poste($r['machine'], $r['produit']), 'renouvellement_demande',
                'licence ' . $lic['id'], 'demande n. ' . $nouveau['id'] . ', echeance '
                . echeance_fr((int)$lic['echeance'], licence_hors_ligne($lic)) . ', ' . $r['poste'], $ip, $maintenant);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    if ($nouveau !== null) {
        notification_renouvellement($db, $config, $nouveau, $lic, $produit, $maintenant);
    }
    return $base + ['ok' => true, 'code' => null, 'renouvellement' => (int)$existant['id'], 'statut' => 'en_attente'];
}

/** Demandes de renouvellement en attente, les plus anciennes d'abord. */
function renouvellements_en_attente(PDO $db): array
{
    return db_lignes($db, SQL_RENOUVELLEMENT . " WHERE rn.statut = 'en_attente' ORDER BY rn.cree_le, rn.id");
}

/** Demande de renouvellement et sa licence, ou null. */
function renouvellement_lire(PDO $db, int $id): ?array
{
    return db_ligne($db, SQL_RENOUVELLEMENT . ' WHERE rn.id = ?', [$id]);
}

/**
 * Accepte : la licence est prolongee de $duree_j jours a partir de son echeance (ou d'aujourd'hui si elle est
 * passee) ; l'application recoit la nouvelle date a son controle suivant. Renvoie la nouvelle echeance.
 */
function renouvellement_accepter(PDO $db, int $id, int $duree_j, string $acteur, string $ip, int $maintenant): int
{
    if ($duree_j < 1 || $duree_j > 36500) {
        throw new InvalidArgumentException('Duree : nombre entier de 1 a 36500 attendu.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $rn = renouvellement_lire($db, $id);
        if ($rn === null || $rn['statut'] !== 'en_attente') {
            throw new RuntimeException('Demande de renouvellement inconnue ou deja traitee.');
        }
        if ($rn['licence_statut'] === 'revoquee' || $rn['echeance'] === null) {
            throw new InvalidArgumentException($rn['licence_statut'] === 'revoquee' ? 'Licence revoquee : rien a '
                . 'prolonger. Refusez la demande.' : 'Licence perpetuelle : rien a prolonger. Refusez la demande.');
        }
        $avant = (int)$rn['echeance'];
        $apres = max($avant, $maintenant) + $duree_j * JOUR;
        db_maj($db, 'licences', ['echeance' => $apres, 'modifie_le' => $maintenant], 'id', (int)$rn['licence_id']);
        db_maj($db, 'renouvellements', ['statut' => 'acceptee', 'traitee_le' => $maintenant, 'echeance_avant' => $avant,
            'echeance_apres' => $apres], 'id', $id);
        $hors_ligne = licence_hors_ligne($rn);
        journal_ecrire($db, $acteur, 'renouvellement_accepte', 'licence ' . $rn['licence_id'], 'demande n. ' . $id
            . ', echeance ' . echeance_fr($avant, $hors_ligne) . ' -> ' . echeance_fr($apres, $hors_ligne), $ip, $maintenant);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return $apres;
}

/** Refus, avec un motif facultatif inscrit au journal (l'application n'en est pas informee). */
function renouvellement_refuser(PDO $db, int $id, string $motif, string $acteur, string $ip, int $maintenant): array
{
    $motif = texte_borne($motif, MOTIF_REFUS_MAX, true);
    if ($motif === null) {
        throw new InvalidArgumentException('Motif : ' . MOTIF_REFUS_MAX . ' caracteres maximum.');
    }
    $rn = renouvellement_lire($db, $id);
    $n = db_modifier($db, "UPDATE renouvellements SET statut = 'refusee', motif_refus = ?, traitee_le = ? "
        . "WHERE id = ? AND statut = 'en_attente'", [$motif === '' ? null : $motif, $maintenant, $id]);
    if ($rn === null || $n !== 1) {
        throw new RuntimeException('Demande de renouvellement inconnue ou deja traitee.');
    }
    journal_ecrire($db, $acteur, 'renouvellement_refuse', 'licence ' . $rn['licence_id'], 'demande n. ' . $id
        . ($motif === '' ? ', sans motif' : ' : ' . $motif), $ip, $maintenant);
    return $rn;
}

/**
 * Licence prolongee ou revoquee depuis sa fiche : sa demande de renouvellement en attente est close (acceptee
 * avec les echeances, ou refusee avec le motif). Renvoie le nombre de demandes closes (0 ou 1).
 */
function renouvellements_clore(PDO $db, int $licence_id, string $statut, ?int $avant, ?int $apres, ?string $motif,
                               int $maintenant): int
{
    return db_modifier($db, 'UPDATE renouvellements SET statut = ?, traitee_le = ?, echeance_avant = COALESCE(?, '
        . "echeance_avant), echeance_apres = ?, motif_refus = ? WHERE licence_id = ? AND statut = 'en_attente'",
        [$statut, $maintenant, $avant, $apres, $motif, $licence_id]);
}
