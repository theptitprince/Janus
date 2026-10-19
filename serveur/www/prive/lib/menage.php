<?php
// menage.php - Menage depuis la console (D88) : suppression des licences revoquees et des produits
//              qui n'ont plus aucune licence. Le journal garde la trace de ce qui a ete fait.
// ETDEL (c) 2026
// Janus, par ETDEL - https://github.com/theptitprince/Janus
// GPL-3.0-or-later + conditions additionnelles (article 7) : voir NOTICE

declare(strict_types=1);

require_once __DIR__ . '/commun.php';

/** Licences revoquees, avec leur produit et la date de leur revocation (journal), pour l'ecran Menage. */
function menage_licences_revoquees(PDO $db): array
{
    return db_lignes($db, "SELECT l.*, p.code AS produit_code, (SELECT MAX(j.date) FROM journal j "
        . "WHERE j.action = 'licence_revoquee' AND j.cible = 'licence ' || l.id) AS revoquee_le FROM licences l "
        . "JOIN distributions d ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id "
        . "WHERE l.statut = 'revoquee' ORDER BY l.id");
}

/**
 * Produits sans aucune licence, quel que soit son statut. nb_attente : demandes en attente, qui empechent
 * la suppression (l'application attend une reponse) ; nb_demandes : toutes ses demandes.
 */
function menage_produits_sans_licence(PDO $db): array
{
    return db_lignes($db, 'SELECT ' . SQL_COLONNES_PRODUIT . ', (SELECT COUNT(*) FROM demandes dm JOIN distributions d '
        . "ON d.id = dm.distribution_id WHERE d.produit_id = p.id AND dm.statut = 'en_attente') AS nb_attente, "
        . '(SELECT COUNT(*) FROM demandes dm JOIN distributions d ON d.id = dm.distribution_id WHERE d.produit_id = p.id) '
        . 'AS nb_demandes FROM produits p JOIN distributions r ON r.id = ' . SQL_REGLES_DU_PRODUIT
        . ' WHERE NOT EXISTS (SELECT 1 FROM licences l JOIN distributions d ON d.id = l.distribution_id '
        . 'WHERE d.produit_id = p.id) ORDER BY p.code');
}

/** Un produit peut-il etre supprime ? null si oui, sinon la raison, a afficher telle quelle. */
function produit_suppression_impossible(PDO $db, int $id): ?string
{
    $licences = (int)db_valeur($db, 'SELECT COUNT(*) FROM licences l JOIN distributions d ON d.id = l.distribution_id '
        . 'WHERE d.produit_id = ?', [$id]);
    if ($licences > 0) {
        return 'Ce produit a encore ' . $licences . ($licences > 1 ? ' licences' : ' licence') . ' : seul un produit '
            . 'sans aucune licence peut etre supprime (supprimez d\'abord ses licences revoquees, ecran Menage).';
    }
    $attente = (int)db_valeur($db, "SELECT COUNT(*) FROM demandes dm JOIN distributions d ON d.id = dm.distribution_id "
        . "WHERE d.produit_id = ? AND dm.statut = 'en_attente'", [$id]);
    if ($attente > 0) {
        return 'Une demande de ce produit attend votre reponse : acceptez-la ou refusez-la d\'abord.';
    }
    return null;
}

/**
 * Les cibles du journal portent l'id d'une ligne ("licence 12") ; SQLite peut redonner l'id d'une ligne
 * supprimee a la suivante. Les cibles de la ligne supprimee sont donc marquees ("licence 12 (supprimee)") :
 * l'historique, le quota de transferts et l'etiquette "cree automatiquement" d'une nouvelle ligne de meme id
 * ne melangent jamais les deux.
 */
function menage_journal_marquer(PDO $db, string $cible, string $suffixe): string
{
    $marquee = $cible . ' (' . $suffixe . ')';
    db_modifier($db, 'UPDATE journal SET cible = ? WHERE cible = ?', [$marquee, $cible]);
    return $marquee;
}

/**
 * Supprime une licence revoquee (a appeler dans une transaction). Ses demandes deja traitees restent (essai
 * deja accorde : il n'est pas redonne), sans lien vers elle ; ses demandes de renouvellement sont effacees.
 * Renvoie la licence supprimee.
 */
function licence_supprimer(PDO $db, int $id, string $acteur, ?string $ip, int $maintenant): array
{
    $lic = db_ligne($db, 'SELECT l.*, p.code AS produit_code FROM licences l JOIN distributions d '
        . 'ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id WHERE l.id = ?', [$id]);
    if ($lic === null) {
        throw new InvalidArgumentException('Licence inconnue (deja supprimee ?).');
    }
    if ($lic['statut'] !== 'revoquee') {
        throw new InvalidArgumentException('Seule une licence revoquee peut etre supprimee : revoquez-la d\'abord.');
    }
    db_modifier($db, 'DELETE FROM renouvellements WHERE licence_id = ?', [$id]);
    db_modifier($db, 'UPDATE demandes SET licence_id = NULL, cle_chiffree = NULL WHERE licence_id = ?', [$id]);
    db_modifier($db, 'DELETE FROM licences WHERE id = ?', [$id]);
    $cible = menage_journal_marquer($db, 'licence ' . $id, 'supprimee');
    // Licence revoquee remplacee par celle-ci (D67) : sa fiche ne la designe plus.
    db_modifier($db, "UPDATE journal SET detail = detail || ' (supprimee)' WHERE action = 'licence_remplacee' "
        . 'AND detail = ?', ['par la licence n. ' . $id]);
    journal_ecrire($db, $acteur, 'licence_supprimee', $cible, $lic['titulaire'] . ', ' . $lic['produit_code'] . ', '
        . licence_designation($lic), $ip, $maintenant);
    return $lic;
}

/**
 * Supprime un produit sans licence ni demande en attente (a appeler dans une transaction), avec ses regles et
 * ses demandes deja traitees. Une nouvelle demande de l'application le recreera, avec les regles par defaut.
 * Renvoie le produit supprime.
 */
function produit_supprimer(PDO $db, int $id, string $acteur, ?string $ip, int $maintenant): array
{
    $produit = produit_lire($db, $id);
    if ($produit === null) {
        throw new InvalidArgumentException('Produit inconnu (deja supprime ?).');
    }
    $raison = produit_suppression_impossible($db, $id);
    if ($raison !== null) {
        throw new InvalidArgumentException($raison);
    }
    $demandes = db_lignes($db, 'SELECT dm.id FROM demandes dm JOIN distributions d ON d.id = dm.distribution_id '
        . 'WHERE d.produit_id = ?', [$id]);
    foreach ($demandes as $demande) {
        menage_journal_marquer($db, 'demande ' . $demande['id'], 'supprimee');
    }
    db_modifier($db, 'DELETE FROM demandes WHERE distribution_id IN (SELECT id FROM distributions WHERE produit_id = ?)',
        [$id]);
    db_modifier($db, 'DELETE FROM distributions WHERE produit_id = ?', [$id]);
    db_modifier($db, 'DELETE FROM produits WHERE id = ?', [$id]);
    $cible = menage_journal_marquer($db, 'produit ' . $id, 'supprime');
    $n = count($demandes);
    journal_ecrire($db, $acteur, 'produit_supprime', $cible, produit_libelle((string)$produit['code'],
        (string)$produit['nom']) . ($n === 0 ? ', aucune demande' : ', ' . $n . ($n > 1 ? ' demandes traitees effacees'
        : ' demande traitee effacee')), $ip, $maintenant);
    return $produit;
}
