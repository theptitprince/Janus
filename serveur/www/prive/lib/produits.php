<?php
// produits.php - Produits (une application = le code qu'elle envoie) et leurs regles :
//                recherche, creation depuis la console ou automatique a la premiere demande.
// ETDEL (c) 2026

declare(strict_types=1);

// Regles d'un nouveau produit, cree a la main ou automatiquement : licence d'un an, essai de
// 15 jours pendant une demande, 15 jours hors ligne, preavis de 5 jours, aucune option.
const REGLES_DEFAUT = ['duree_defaut_j' => 365, 'essai_j' => 15, 'tolerance_j' => 15, 'preavis_j' => 5];

// Ligne de regles d'un produit p. Une base anterieure peut en avoir plusieurs (anciennes distributions,
// chacune avec sa case Active) : la premiere active, sinon la premiere ; une ligne desactivee autrefois ne
// bloque donc pas les licences des autres. L'enregistrement du produit dans la console aligne ensuite
// toutes ses lignes sur les regles affichees (produit_regles_enregistrer).
const SQL_REGLES_DU_PRODUIT = '(SELECT x.id FROM distributions x WHERE x.produit_id = p.id '
    . 'ORDER BY x.actif DESC, x.id LIMIT 1)';

// Colonnes lues pour un produit et ses regles (alias p pour produits, r pour la ligne de regles).
const SQL_COLONNES_PRODUIT = 'p.id AS id, p.code AS code, p.nom AS nom, p.cree_le AS cree_le, r.id AS regles_id, '
    . 'r.tolerance_j, r.preavis_j, r.duree_defaut_j, r.options, r.essai_j, r.message, r.actif';

/**
 * Libelle d'un produit dans la console et les e-mails : "CODE - Nom", ou le code seul quand le nom le
 * repete (produit cree automatiquement : nom = code), pour ne pas faire croire a deux elements.
 */
function produit_libelle(string $code, string $nom): string
{
    return $nom === $code ? $code : $code . ' - ' . $nom;
}

/** Produit et ses regles d'apres le code envoye par l'application (casse comprise), ou null. */
function produit_trouver(PDO $db, string $code): ?array
{
    return db_ligne($db, 'SELECT ' . SQL_COLONNES_PRODUIT . ' FROM produits p JOIN distributions r ON r.id = '
        . SQL_REGLES_DU_PRODUIT . ' WHERE p.code = ?', [$code]);
}

/** Produit et ses regles d'apres son id, ou null. */
function produit_lire(PDO $db, int $id): ?array
{
    return db_ligne($db, 'SELECT ' . SQL_COLONNES_PRODUIT . ' FROM produits p JOIN distributions r ON r.id = '
        . SQL_REGLES_DU_PRODUIT . ' WHERE p.id = ?', [$id]);
}

/** Code de produit deja pris a la casse pres : le code existant, sinon null. */
function produit_code_existant(PDO $db, string $code): ?string
{
    $existant = db_valeur($db, 'SELECT code FROM produits WHERE code = ? COLLATE NOCASE ORDER BY code = ? DESC LIMIT 1',
        [$code, $code]);
    return $existant === null ? null : (string)$existant;
}

/**
 * Ligne de regles d'un produit, avec les valeurs par defaut completees par $regles. Son code
 * (unique, jamais montre) est celui du produit, suivi d'un numero s'il est deja pris.
 */
function produit_regles_creer(PDO $db, int $produit_id, string $code, array $regles, int $maintenant): int
{
    $libre = $code;
    for ($n = 2; db_valeur($db, 'SELECT COUNT(*) FROM distributions WHERE code = ?', [$libre]) > 0; $n++) {
        $libre = $code . '-' . $n;
    }
    return db_inserer($db, 'distributions', array_merge([
        'produit_id' => $produit_id, 'code' => $libre, 'libelle' => $code, 'options' => '[]', 'actif' => 1,
        'cree_le' => $maintenant,
    ], REGLES_DEFAUT, $regles));
}

/**
 * Regles enregistrees depuis la page du produit : ecrites sur toutes ses lignes de regles (une seule
 * pour un produit cree depuis cette version), pour que les licences de chacune suivent les regles
 * affichees et que l'etat Actif ne change jamais de ligne de reference.
 */
function produit_regles_enregistrer(PDO $db, int $produit_id, array $regles): void
{
    $affectations = [];
    foreach (array_keys($regles) as $colonne) {
        $affectations[] = $colonne . ' = ?';
    }
    db_modifier($db, 'UPDATE distributions SET ' . implode(', ', $affectations) . ' WHERE produit_id = ?',
        array_merge(array_values($regles), [$produit_id]));
}

/** Nouveau produit et ses regles (a appeler dans une transaction) ; renvoie l'id du produit. */
function produit_creer(PDO $db, string $code, string $nom, array $regles, int $maintenant): int
{
    $id = db_inserer($db, 'produits', ['code' => $code, 'nom' => $nom,
        'actif' => (int)($regles['actif'] ?? 1), 'cree_le' => $maintenant]);
    produit_regles_creer($db, $id, $code, $regles, $maintenant);
    return $id;
}

/**
 * Premiere demande d'une application inconnue : le produit est cree (nom = code, actif) avec les
 * regles par defaut et journalise par le systeme. Renvoie le produit et ses regles, ou null si un
 * produit du meme code a la casse pres existe deja (deux codes pareils a la lecture se
 * confondraient dans la console : l'application recoit produit_inconnu).
 */
function produit_creer_automatiquement(PDO $db, string $code, string $ip, int $maintenant): ?array
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Relu sous verrou : deux premieres demandes simultanees ne creent qu'un produit.
        $produit = produit_trouver($db, $code);
        if ($produit === null && produit_code_existant($db, $code) === null) {
            $id = produit_creer($db, $code, $code, [], $maintenant);
            journal_ecrire($db, 'systeme', 'produit_cree_automatiquement', 'produit ' . $id,
                $code . ' : premiere demande d\'une application inconnue, regles par defaut', $ip, $maintenant);
            $produit = produit_lire($db, $id);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return $produit;
}

/**
 * Ids des produits crees automatiquement et jamais modifies depuis la console (etiquette
 * "cree automatiquement" de l'ecran Produits), d'apres le journal.
 */
function produits_crees_automatiquement(PDO $db): array
{
    $ids = [];
    foreach (db_lignes($db, "SELECT cible FROM journal WHERE action = 'produit_cree_automatiquement' AND cible NOT IN "
        . "(SELECT cible FROM journal WHERE action = 'produit_modifie' AND cible IS NOT NULL)") as $ligne) {
        if (preg_match('/^produit (\d+)$/', (string)$ligne['cible'], $m) === 1) {
            $ids[(int)$m[1]] = true;
        }
    }
    return $ids;
}
