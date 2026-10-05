<?php
// demandes.php - Demandes de licence : creation et suivi (API), acceptation et
//                refus (console), periode d'essai unique par poste et par produit.
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/notification.php';

const MOTIF_REFUS_MAX = 500;

function demande_reponse_attente(array $demande, array $dist, array $base): array
{
    return $base + [
        'ok' => true,
        'code' => null,
        'demande' => (int)$demande['id'],
        'statut' => 'en_attente',
        'essai_jusqu' => $demande['essai_jusqu'] === null ? null : (int)$demande['essai_jusqu'],
        // Options de la distribution : celles de l'essai, signees comme le reste.
        'options' => options_lire($dist['options']),
    ];
}

/** L'essai n'est accorde qu'une fois par poste (empreinte) et par produit, toutes distributions confondues. */
function demande_essai_deja_accorde(PDO $db, string $machine, int $produit_id): bool
{
    return (int)db_valeur($db, 'SELECT COUNT(*) FROM demandes dm JOIN distributions d ON d.id = dm.distribution_id '
        . 'WHERE dm.machine = ? AND d.produit_id = ? AND dm.essai_jusqu IS NOT NULL', [$machine, $produit_id]) > 0;
}

function demande_creer(PDO $db, array $config, array $r, array $dist, array $base, string $ip, int $maintenant): array
{
    if (api_version_refusee($dist, $r['version'])) {
        return api_refus($base, 'version_trop_ancienne');
    }
    $jeton_hash = hash('sha256', $r['jeton']);
    // Transaction immediate : la regle "une seule demande en attente" tient face aux envois simultanes.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $existante = db_ligne($db, "SELECT * FROM demandes WHERE distribution_id = ? AND machine = ? "
            . "AND statut = 'en_attente'", [(int)$dist['id'], $r['machine']]);
        if ($existante !== null) {
            $db->exec('COMMIT');
            // Meme jeton : renvoi d'une demande dont la reponse s'est perdue.
            if (hash_equals((string)$existante['jeton_hash'], $jeton_hash)) {
                return demande_reponse_attente($existante, $dist, $base);
            }
            return api_refus($base, 'demande_en_cours');
        }
        $essai = null;
        if ((int)$dist['essai_j'] > 0 && !demande_essai_deja_accorde($db, $r['machine'], (int)$dist['produit_id'])) {
            $essai = $maintenant + (int)$dist['essai_j'] * JOUR;
        }
        $demande = [
            'distribution_id' => (int)$dist['id'],
            'machine' => $r['machine'],
            'id_poste' => id_poste($r['machine'], $r['produit']),
            'nom_ordinateur' => $r['poste'],
            'titulaire' => $r['titulaire'],
            'email' => $r['email'] === '' ? null : $r['email'],
            'message' => $r['message'] === '' ? null : $r['message'],
            'version_appli' => $r['version'],
            'jeton_hash' => $jeton_hash,
            'statut' => 'en_attente',
            'essai_jusqu' => $essai,
            'cree_le' => $maintenant,
            'ip' => $ip,
        ];
        $demande['id'] = db_inserer($db, 'demandes', $demande);
        journal_ecrire($db, 'poste:' . $demande['id_poste'], 'demande', 'demande ' . $demande['id'],
            $dist['code'] . ', ' . $r['poste'] . ', essai ' . ($essai === null ? 'non' : 'jusqu\'au ' . date_fr($essai)),
            $ip, $maintenant);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    notification_nouvelle_demande($db, $config, $demande, $dist, $maintenant);
    return demande_reponse_attente($demande, $dist, $base);
}

function demande_suivre(PDO $db, array $config, array $r, array $dist, array $base, string $ip, int $maintenant): array
{
    $demande = db_ligne($db, 'SELECT * FROM demandes WHERE id = ? AND distribution_id = ?',
        [$r['demande'], (int)$dist['id']]);
    // Seul le poste qui a fait la demande (jeton secret + meme empreinte) peut la suivre.
    if ($demande === null || !hash_equals((string)$demande['jeton_hash'], hash('sha256', $r['jeton']))
        || !hash_equals((string)$demande['machine'], $r['machine'])) {
        return api_refus($base, 'demande_inconnue');
    }
    if ($demande['statut'] === 'en_attente') {
        return demande_reponse_attente($demande, $dist, $base);
    }
    if ($demande['statut'] === 'refusee') {
        $motif = $demande['motif_refus'];
        return $base + ['ok' => true, 'code' => null, 'demande' => (int)$demande['id'], 'statut' => 'refusee',
            'motif' => ($motif === null || $motif === '') ? null : (string)$motif];
    }
    if ($demande['cle_chiffree'] === null || $demande['licence_id'] === null) {
        return api_refus($base, 'demande_inconnue');
    }
    $cle = cle_dechiffrer((string)$demande['cle_chiffree'], secret_demandes($config));
    $lic = db_ligne($db, 'SELECT * FROM licences WHERE id = ?', [(int)$demande['licence_id']]);
    if ($cle === null || $lic === null) {
        return api_refus($base, 'demande_inconnue');
    }
    $code = licence_controler($lic, $r['machine'], false, $maintenant);
    if ($code !== null) {
        return api_refus($base, $code);
    }
    licence_contact($db, (int)$lic['id'], $r, $ip, $maintenant);
    return api_jeton($lic, $dist, $base, $maintenant)
        + ['demande' => (int)$demande['id'], 'statut' => 'acceptee', 'cle' => $cle];
}

/**
 * Acceptation : cree la cle deja liee au poste demandeur et la conserve chiffree
 * jusqu'au premier valider. Renvoie ['licence_id' => ..., 'cle' => ...].
 */
function demande_accepter(PDO $db, array $config, int $id, ?int $duree_j, string $titulaire, ?array $options,
                          string $acteur, string $ip, int $maintenant): array
{
    $titulaire = texte_borne($titulaire, 120);
    if ($titulaire === null || $titulaire === '') {
        throw new InvalidArgumentException('titulaire obligatoire (120 caracteres maximum)');
    }
    if ($duree_j !== null && ($duree_j < 1 || $duree_j > 36500)) {
        throw new InvalidArgumentException('duree invalide');
    }
    $secret = secret_demandes($config);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $demande = db_ligne($db, 'SELECT dm.*, d.options AS options_distribution FROM demandes dm '
            . 'JOIN distributions d ON d.id = dm.distribution_id WHERE dm.id = ?', [$id]);
        if ($demande === null || $demande['statut'] !== 'en_attente') {
            throw new RuntimeException('demande inconnue ou deja traitee');
        }
        $cle = cle_generer();
        $surcharge = ($options === null || $options === options_lire($demande['options_distribution']))
            ? null : json_encode(array_values($options));
        $licence_id = db_inserer($db, 'licences', [
            'distribution_id' => (int)$demande['distribution_id'],
            'cle_hash' => cle_hash($cle),
            'cle_indice' => substr($cle, -4),
            'titulaire' => $titulaire,
            'email' => $demande['email'],
            'echeance' => $duree_j === null ? null : $maintenant + $duree_j * JOUR,
            'options' => $surcharge,
            'machine' => $demande['machine'],
            'id_poste' => $demande['id_poste'],
            'nom_ordinateur' => $demande['nom_ordinateur'],
            'version_appli' => $demande['version_appli'],
            'lie_le' => $maintenant,
            'statut' => 'active',
            'origine' => 'demande',
            'cree_le' => $maintenant,
            'modifie_le' => $maintenant,
        ]);
        db_maj($db, 'demandes', ['statut' => 'acceptee', 'traitee_le' => $maintenant, 'licence_id' => $licence_id,
            'cle_chiffree' => cle_chiffrer($cle, $secret)], 'id', $id);
        journal_ecrire($db, $acteur, 'demande_acceptee', 'demande ' . $id,
            'licence ' . $licence_id . ', ' . ($duree_j === null ? 'perpetuelle' : $duree_j . ' j')
            . ', cle ...' . substr($cle, -4), $ip, $maintenant);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return ['licence_id' => $licence_id, 'cle' => $cle];
}

/** Refus avec motif facultatif (500 caracteres), renvoye tel quel a l'application. */
function demande_refuser(PDO $db, int $id, string $motif, string $acteur, string $ip, int $maintenant): void
{
    $motif = texte_borne($motif, MOTIF_REFUS_MAX, true);
    if ($motif === null) {
        throw new InvalidArgumentException('motif trop long (' . MOTIF_REFUS_MAX . ' caracteres maximum)');
    }
    $n = db_modifier($db, "UPDATE demandes SET statut = 'refusee', motif_refus = ?, traitee_le = ? "
        . "WHERE id = ? AND statut = 'en_attente'", [$motif === '' ? null : $motif, $maintenant, $id]);
    if ($n !== 1) {
        throw new RuntimeException('demande inconnue ou deja traitee');
    }
    journal_ecrire($db, $acteur, 'demande_refusee', 'demande ' . $id, $motif === '' ? 'sans motif' : $motif,
        $ip, $maintenant);
}
