<?php
// api.php - API v1 : validation des requetes, limites, operations sur les licences (cles ETDEL et
//           codes hors ligne, dont le transfert demande par l'application), reponses signees Ed25519
//           (refus compris).
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/demandes.php';
require_once __DIR__ . '/notification.php';

const API_OPS = ['activer', 'valider', 'liberer', 'demander', 'suivre_demande', 'ping'];
// [nombre maximal, fenetre en secondes] par IP ; ping n'est pas limite.
const API_LIMITES = [
    'activer' => [30, 3600],
    'valider' => [120, 3600],
    'liberer' => [30, 3600],
    'suivre_demande' => [60, 3600],
    'demander' => [3, 86400],
];
const API_ECART_MAX = 600;
const API_CORPS_MAX = 16384;
// Une tolerance de 0 jour bloquerait le poste entre deux controles (toutes les 6 h) :
// la duree hors ligne ne descend jamais sous un cycle de controle plus une heure.
const TOLERANCE_MIN_S = 7 * 3600;
// Transferts depuis l'application : au plus 3 par licence sur 30 jours. L'ancien ordinateur garde son
// jeton hors ligne jusqu'a la fin de sa tolerance ; sans limite, des transferts repetes feraient tourner
// une cle sur plusieurs ordinateurs. Au-dela, "Changer d'ordinateur" dans la console reste possible.
const TRANSFERTS_MAX = 3;
const TRANSFERTS_PERIODE_J = 30;

function api_point_entree(string $prive): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'code' => 'methode']);
        return;
    }
    try {
        $config = config_charger($prive);
        $db = db_ouvrir((string)$config['base']);
        $corps = (string)file_get_contents('php://input', false, null, 0, API_CORPS_MAX + 1);
        [$code, $reponse] = api_traiter($db, $config, $corps, ip_client(), time());
    } catch (Throwable $e) {
        error_log('etdel api : ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['ok' => false, 'code' => 'indisponible']);
        return;
    }
    http_response_code($code);
    echo json_encode($reponse, JSON_UNESCAPED_SLASHES);
}

/** Renvoie [code HTTP, enveloppe signee]. */
function api_traiter(PDO $db, array $config, string $corps, string $ip, int $maintenant): array
{
    $cle = signature_active($db, $config);
    licences_fin_suspension($db, $maintenant);
    $requete = strlen($corps) <= API_CORPS_MAX ? json_decode($corps, true, 16) : null;
    $requete = is_array($requete) ? $requete : [];
    $base = api_base($db, $requete, $maintenant);
    $champs = api_lire_requete($requete);
    if ($champs === null) {
        return [400, signer($base + ['ok' => false, 'code' => 'requete_invalide'], $cle)];
    }
    return [200, signer(api_operation($db, $config, $champs, $base, $ip, $maintenant), $cle)];
}

/**
 * Champs communs a toute reponse ; nonce, produit, distribution et machine sont ceux de la requete.
 * Le champ distribution n'identifie plus rien (le produit suffit) : il n'est que renvoye, l'application
 * verifiant cet echo.
 */
function api_base(PDO $db, array $requete, int $maintenant): array
{
    $echo = static function ($valeur) {
        return (is_string($valeur) && strlen($valeur) <= 100) ? $valeur : null;
    };
    return [
        'v' => 1,
        'nonce' => $echo($requete['nonce'] ?? null),
        'produit' => $echo($requete['produit'] ?? null),
        'distribution' => $echo($requete['distribution'] ?? null),
        'machine' => $echo($requete['machine'] ?? null),
        'emis' => $maintenant,
        'urls' => api_urls($db),
        'bulletins' => api_bulletins($db, $maintenant),
    ];
}

function api_urls(PDO $db): array
{
    $urls = [];
    foreach (db_lignes($db, 'SELECT url FROM urls_serveur WHERE actif = 1 ORDER BY priorite, id') as $ligne) {
        $urls[] = (string)$ligne['url'];
    }
    return $urls;
}

/** Bulletins de changement de cle des 12 derniers mois (rattrapage des postes restes hors ligne). */
function api_bulletins(PDO $db, int $maintenant): array
{
    $bulletins = [];
    $lignes = db_lignes($db, 'SELECT bulletin FROM cles_signature WHERE bulletin IS NOT NULL '
        . 'AND active_depuis >= ? ORDER BY kid', [$maintenant - 365 * JOUR]);
    foreach ($lignes as $ligne) {
        $bulletin = json_decode((string)$ligne['bulletin'], true);
        if (is_array($bulletin)) {
            $bulletins[] = $bulletin;
        }
    }
    return $bulletins;
}

/** Champs valides et bornes, ou null (requete_invalide). */
function api_lire_requete(array $r): ?array
{
    if (($r['v'] ?? null) !== 1 || !in_array($r['op'] ?? null, API_OPS, true) || !is_int($r['t'] ?? null)) {
        return null;
    }
    foreach (['produit', 'distribution'] as $champ) {
        if (!is_string($r[$champ] ?? null) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $r[$champ]) !== 1) {
            return null;
        }
    }
    if (!is_string($r['machine'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $r['machine']) !== 1) {
        return null;
    }
    if (!is_string($r['nonce'] ?? null) || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $r['nonce']) !== 1) {
        return null;
    }
    $poste = texte_borne($r['poste'] ?? '', 64);
    $version = texte_borne($r['version'] ?? '', 32);
    if ($poste === null || $version === null) {
        return null;
    }
    $champs = ['op' => $r['op'], 'produit' => $r['produit'], 'distribution' => $r['distribution'],
        'machine' => $r['machine'], 'nonce' => $r['nonce'], 't' => $r['t'], 'poste' => $poste,
        'version' => $version];
    $jeton_valide = is_string($r['jeton'] ?? null) && preg_match('/^[A-Za-z0-9_-]{43}$/', $r['jeton']) === 1;
    switch ($r['op']) {
        case 'activer':
        case 'valider':
        case 'liberer':
            // 256 : le code hors ligne long fait 143 caracteres sous sa forme canonique.
            if (!is_string($r['cle'] ?? null) || strlen($r['cle']) > 256) {
                return null;
            }
            $champs['cle'] = $r['cle'];
            break;
        case 'demander':
            $titulaire = texte_borne($r['titulaire'] ?? null, 120);
            $email = texte_borne($r['email'] ?? '', 254);
            $message = texte_borne($r['message'] ?? '', 200, true);
            if ($titulaire === null || $titulaire === '' || $email === null || $message === null || !$jeton_valide) {
                return null;
            }
            $champs += ['titulaire' => $titulaire, 'email' => $email, 'message' => $message, 'jeton' => $r['jeton']];
            break;
        case 'suivre_demande':
            if (!is_int($r['demande'] ?? null) || $r['demande'] < 1 || !$jeton_valide) {
                return null;
            }
            $champs += ['demande' => $r['demande'], 'jeton' => $r['jeton']];
            break;
    }
    return $champs;
}

function api_refus(array $base, string $code): array
{
    return $base + ['ok' => false, 'code' => $code];
}

function api_operation(PDO $db, array $config, array $r, array $base, string $ip, int $maintenant): array
{
    if (abs($r['t'] - $maintenant) > API_ECART_MAX) {
        return api_refus($base, 'horloge');
    }
    if ($r['op'] === 'ping') {
        return $base + ['ok' => true, 'code' => null, 'version_api' => VERSION_API,
            'version_serveur' => VERSION_SERVEUR];
    }
    [$max, $duree] = API_LIMITES[$r['op']];
    if (limite_depassee($db, ip_limite($ip), $r['op'], $max, $duree, $maintenant)) {
        return api_refus($base, 'trop_de_requetes');
    }
    // Le produit est identifie par le code qu'envoie l'application, seul ; le champ distribution
    // est ignore. Une demande d'une application inconnue cree son produit (regles par defaut).
    $produit = produit_trouver($db, $r['produit']);
    $cree = false;
    if ($produit === null && $r['op'] === 'demander') {
        $produit = produit_creer_automatiquement($db, $r['produit'], $ip, $maintenant);
        $cree = $produit !== null;
    }
    // Produit inactif : ses licences sont bloquees et ses demandes refusees.
    if ($produit === null || (int)$produit['actif'] !== 1) {
        return api_refus($base, 'produit_inconnu');
    }
    switch ($r['op']) {
        case 'demander':
            return demande_creer($db, $config, $r, $produit, $base, $ip, $maintenant, $cree);
        case 'suivre_demande':
            return demande_suivre($db, $config, $r, $produit, $base, $ip, $maintenant);
        default:
            // activer, valider, liberer : lecture de la licence et ecriture en une transaction immediate.
            $traiter = $r['op'] === 'liberer' ? 'api_liberer' : 'api_licence_traiter';
            return api_transaction($db, static function () use ($traiter, $db, $r, $produit, $base, $ip,
                                                                $maintenant): array {
                return $traiter($db, $r, $produit, $base, $ip, $maintenant);
            });
    }
}

/** Execute $operation dans une transaction immediate et renvoie sa reponse. */
function api_transaction(PDO $db, callable $operation): array
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $reponse = $operation();
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return $reponse;
}

/**
 * Licence d'une cle saisie, si elle appartient a ce produit (quelle que soit sa ligne de regles :
 * celles d'une base anterieure restent valables) ; null sinon (cle_invalide). Une cle ETDEL est
 * retrouvee par son hachage (cle_hash) ; un code hors ligne par celui de sa forme canonique : cle_hash
 * pour le code court, cle_long_hash pour le code long.
 */
function licence_par_cle(PDO $db, string $saisie, array $produit): ?array
{
    $cle = cle_normaliser($saisie);
    $condition = 'l.cle_hash = ?';
    if ($cle === null) {
        $code = code_hors_ligne_normaliser($saisie);
        if ($code === null) {
            return null;
        }
        $cle = $code['code'];
        $condition = ($code['forme'] === 'long' ? 'l.cle_long_hash = ?' : 'l.cle_hash = ?') . ' AND l.hors_ligne = 1';
    }
    $lic = db_ligne($db, 'SELECT l.*, d.produit_id FROM licences l JOIN distributions d ON d.id = l.distribution_id '
        . 'WHERE ' . $condition, [cle_hash($cle)]);
    return ($lic === null || (int)$lic['produit_id'] !== (int)$produit['id']) ? null : $lic;
}

/**
 * Code de refus d'une licence pour ce poste, ou null si elle est utilisable. $poste : identifiant du
 * poste qui la presente (id_poste(machine, produit)), exige pour une licence hors ligne non liee.
 */
function licence_controler(array $lic, string $machine, bool $activation, int $maintenant, ?string $poste = null): ?string
{
    if ($lic['statut'] === 'revoquee') {
        return 'revoquee';
    }
    if ($lic['statut'] === 'suspendue') {
        return 'suspendue';
    }
    if ($lic['machine'] === null) {
        if (licence_hors_ligne($lic)) {
            // Licence hors ligne : le poste s'est active sans reseau, son premier contact (activer ou valider)
            // la lie a l'ordinateur reel, seulement si c'est le poste saisi dans la console.
            if ($poste === null || !hash_equals((string)$lic['id_poste'], $poste)) {
                return 'cle_liee_autre_poste';
            }
        } elseif (!$activation) {
            // Cle liberee (console ou transfert depuis l'application) : seule une activation peut la relier.
            return 'poste_revoque';
        }
    } elseif (!hash_equals((string)$lic['machine'], $machine)) {
        return 'cle_liee_autre_poste';
    }
    if ($lic['echeance'] !== null && $maintenant >= (int)$lic['echeance']) {
        return 'expiree';
    }
    return null;
}

/** Le nom de l'ordinateur est mis a jour a chaque contact : affichage seulement, jamais un critere. */
function licence_contact(PDO $db, int $id, array $r, string $ip, int $maintenant): void
{
    db_modifier($db, 'UPDATE licences SET dernier_contact = ?, nom_ordinateur = ?, version_appli = ?, '
        . 'derniere_ip = ? WHERE id = ?', [$maintenant, $r['poste'], $r['version'], $ip, $id]);
}

/**
 * Jeton de licence (annexe C), avec les regles du produit (sauf tolerance et options propres a la
 * licence). Aucune version minimale : version_min reste dans le format, toujours null.
 */
function api_jeton(array $lic, array $produit, array $base, int $maintenant): array
{
    $tolerance = $lic['tolerance_j'] !== null ? (int)$lic['tolerance_j'] : (int)$produit['tolerance_j'];
    $options = $lic['options'] !== null ? options_lire($lic['options']) : options_lire($produit['options']);
    $echeance = $lic['echeance'] === null ? null : (int)$lic['echeance'];
    $message = $produit['message'] ?? null;
    return $base + [
        'ok' => true,
        'code' => null,
        'id_poste' => id_poste((string)$base['machine'], (string)$base['produit']),
        'titulaire' => (string)$lic['titulaire'],
        'echeance' => $echeance,
        'jours_restants' => jours_restants($echeance, $maintenant),
        'hors_ligne_jusqu' => $maintenant + max($tolerance * JOUR, TOLERANCE_MIN_S),
        'preavis_j' => (int)$produit['preavis_j'],
        'options' => $options,
        'version_min' => null,
        'message' => ($message === null || $message === '') ? null : (string)$message,
    ];
}

/**
 * activer / valider, dans la transaction immediate ouverte par api_operation : un remplacement de
 * cle depuis la console (D67) ne peut pas s'intercaler entre la lecture de la licence par son hash
 * et sa liaison ou l'envoi du jeton ; l'ancienne cle ne recoit plus rien une fois remplacee.
 * Licence hors ligne non liee : son premier contact, activer ou valider, la lie a l'ordinateur reel
 * si c'est le poste saisi dans la console (licence_controler) ; ensuite comme une cle ETDEL.
 */
function api_licence_traiter(PDO $db, array $r, array $produit, array $base, string $ip, int $maintenant): array
{
    $lic = licence_par_cle($db, $r['cle'], $produit);
    if ($lic === null) {
        return api_refus($base, 'cle_invalide');
    }
    $activation = $r['op'] === 'activer';
    $poste = id_poste($r['machine'], $r['produit']);
    $code = licence_controler($lic, $r['machine'], $activation, $maintenant, $poste);
    if ($code !== null) {
        if ($code === 'cle_liee_autre_poste') {
            journal_ecrire($db, 'poste:' . $poste, 'refus_autre_poste', 'licence ' . $lic['id'], licence_hors_ligne($lic)
                ? licence_designation($lic) . ' saisi sur ' . $r['poste'] . ' (poste ' . $poste . ', code etabli pour '
                    . $lic['id_poste'] . ')'
                : 'cle ...' . $lic['cle_indice'] . ' saisie sur ' . $r['poste'], $ip, $maintenant);
        }
        if ($code === 'suspendue') {
            // Le poste garde sa cle et affiche la date de fin (signee comme le reste).
            return api_refus($base, $code) + ['suspendue_jusqu' => $lic['suspendue_jusqu'] === null
                ? null : (int)$lic['suspendue_jusqu']];
        }
        return api_refus($base, $code);
    }
    if ($lic['machine'] === null) {
        // Condition machine IS NULL : deux activations simultanees ne lient qu'un poste.
        $lie = db_modifier($db, 'UPDATE licences SET machine = ?, id_poste = ?, lie_le = ? '
            . 'WHERE id = ? AND machine IS NULL', [$r['machine'], $poste, $maintenant, (int)$lic['id']]);
        if ($lie !== 1) {
            return api_refus($base, 'cle_liee_autre_poste');
        }
        // Licence hors ligne deja vue par le serveur (transfert depuis l'application, ou nouveau code pour un
        // autre ordinateur) : ce n'est pas son premier contact.
        journal_ecrire($db, 'poste:' . $poste, 'activation', 'licence ' . $lic['id'], licence_hors_ligne($lic)
            ? licence_designation($lic) . ' lie a ' . $r['poste'] . ($lic['dernier_contact'] === null
                ? ' (premier contact avec le serveur)' : ' (de nouveau : la licence n\'etait plus liee a aucun ordinateur)')
            : 'cle ...' . $lic['cle_indice'] . ' liee a ' . $r['poste'], $ip, $maintenant);
    }
    licence_contact($db, (int)$lic['id'], $r, $ip, $maintenant);
    if (!$activation) {
        // Premier valider reussi : la cle remise par une demande acceptee n'a plus a etre conservee.
        db_modifier($db, 'UPDATE demandes SET cle_chiffree = NULL WHERE licence_id = ? AND cle_chiffree IS NOT NULL',
            [(int)$lic['id']]);
    }
    return api_jeton($lic, $produit, $base, $maintenant);
}

/**
 * Transfert demande par l'application, depuis l'ancien ordinateur : la cle est detachee de ce
 * poste et pourra etre saisie sur le nouveau. Seul le poste lie peut liberer sa cle ; licence
 * active ou suspendue (une suspension suit la licence) ; au plus TRANSFERTS_MAX transferts par licence
 * sur TRANSFERTS_PERIODE_J jours (transferts_trop_nombreux au-dela). Reponse : ok, sans jeton.
 */
function api_liberer(PDO $db, array $r, array $produit, array $base, string $ip, int $maintenant): array
{
    $lic = licence_par_cle($db, $r['cle'], $produit);
    if ($lic === null) {
        return api_refus($base, 'cle_invalide');
    }
    if ($lic['statut'] === 'revoquee') {
        return api_refus($base, 'revoquee');
    }
    if ($lic['machine'] === null) {
        return api_refus($base, 'poste_revoque');
    }
    if (!hash_equals((string)$lic['machine'], $r['machine'])) {
        return api_refus($base, 'cle_liee_autre_poste');
    }
    $poste = id_poste($r['machine'], $r['produit']);
    $recents = (int)db_valeur($db, "SELECT COUNT(*) FROM journal WHERE action = 'licence_transferee' AND cible = ? "
        . 'AND date > ?', ['licence ' . $lic['id'], $maintenant - TRANSFERTS_PERIODE_J * JOUR]);
    if ($recents >= TRANSFERTS_MAX) {
        // Le poste garde sa licence ; le refus est inscrit au journal (fiche de la licence).
        journal_ecrire($db, 'poste:' . $poste, 'transfert_refuse', 'licence ' . $lic['id'],
            licence_designation($lic) . ' : ' . $recents . ' transferts depuis l\'application en '
            . TRANSFERTS_PERIODE_J . ' jours (Changer d\'ordinateur dans la console reste possible)', $ip, $maintenant);
        return api_refus($base, 'transferts_trop_nombreux');
    }
    // Licence hors ligne : son code ne vaut que pour le poste saisi dans la console, dont l'identifiant reste.
    db_modifier($db, 'UPDATE licences SET machine = NULL, id_poste = CASE WHEN hors_ligne = 1 THEN id_poste END, '
        . 'nom_ordinateur = NULL, lie_le = NULL, dernier_contact = ?, derniere_ip = ?, modifie_le = ? WHERE id = ?',
        [$maintenant, $ip, $maintenant, (int)$lic['id']]);
    // La cle est connue du poste qui la libere : une remise en attente n'a plus d'objet.
    db_modifier($db, 'UPDATE demandes SET cle_chiffree = NULL WHERE licence_id = ? AND cle_chiffree IS NOT NULL',
        [(int)$lic['id']]);
    journal_ecrire($db, 'poste:' . $poste, 'licence_transferee', 'licence ' . $lic['id'],
        licence_designation($lic) . (licence_hors_ligne($lic) ? ' libere par ' : ' liberee par ')
        . ($r['poste'] !== '' ? $r['poste'] : $poste)
        . ' (transfert depuis l\'application)', $ip, $maintenant);
    return $base + ['ok' => true, 'code' => null];
}
