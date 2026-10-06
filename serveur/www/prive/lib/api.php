<?php
// api.php - API v1 : validation des requetes, limites, operations sur les licences,
//           reponses signees Ed25519 (refus compris).
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/demandes.php';
require_once __DIR__ . '/notification.php';

const API_OPS = ['activer', 'valider', 'demander', 'suivre_demande', 'ping'];
// [nombre maximal, fenetre en secondes] par IP ; ping n'est pas limite.
const API_LIMITES = [
    'activer' => [30, 3600],
    'valider' => [120, 3600],
    'suivre_demande' => [60, 3600],
    'demander' => [3, 86400],
];
const API_ECART_MAX = 600;
const API_CORPS_MAX = 16384;
// Une tolerance de 0 jour bloquerait le poste entre deux controles (toutes les 6 h) :
// la duree hors ligne ne descend jamais sous un cycle de controle plus une heure.
const TOLERANCE_MIN_S = 7 * 3600;

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

/** Champs communs a toute reponse ; nonce, produit, distribution et machine sont ceux de la requete. */
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
            if (!is_string($r['cle'] ?? null) || strlen($r['cle']) > 64) {
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
    $dist = distribution_trouver($db, $r['produit'], $r['distribution']);
    if ($dist === null) {
        return api_refus($base, 'produit_inconnu');
    }
    switch ($r['op']) {
        case 'demander':
            return demande_creer($db, $config, $r, $dist, $base, $ip, $maintenant);
        case 'suivre_demande':
            return demande_suivre($db, $config, $r, $dist, $base, $ip, $maintenant);
        default:
            return api_licence($db, $r, $dist, $base, $ip, $maintenant);
    }
}

/**
 * Distribution active, si son produit est bien celui qu'envoie l'application ; null sinon
 * (produit_inconnu). Le produit ne sert qu'a ranger les distributions (D68) : ni son etat
 * (produits.actif) ni sa version minimale n'ont d'effet, seuls ceux de la distribution comptent.
 */
function distribution_trouver(PDO $db, string $produit, string $code): ?array
{
    $dist = db_ligne($db, 'SELECT d.*, p.code AS produit_code, p.nom AS produit_nom '
        . 'FROM distributions d JOIN produits p ON p.id = d.produit_id WHERE d.code = ?', [$code]);
    if ($dist === null || $dist['produit_code'] !== $produit || (int)$dist['actif'] !== 1) {
        return null;
    }
    return $dist;
}

function api_version_refusee(array $dist, string $version): bool
{
    $minimum = version_min_normalisee($dist['version_min']);
    return $minimum !== null && version_comparer($version, $minimum) < 0;
}

/** Code de refus d'une licence pour ce poste, ou null si elle est utilisable. */
function licence_controler(array $lic, string $machine, bool $activation, int $maintenant): ?string
{
    if ($lic['statut'] === 'revoquee') {
        return 'revoquee';
    }
    if ($lic['statut'] === 'suspendue') {
        return 'suspendue';
    }
    if ($lic['machine'] === null) {
        // Cle liberee dans la console : seule une activation peut la relier.
        if (!$activation) {
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

/** Jeton de licence (annexe C). */
function api_jeton(array $lic, array $dist, array $base, int $maintenant): array
{
    $tolerance = $lic['tolerance_j'] !== null ? (int)$lic['tolerance_j'] : (int)$dist['tolerance_j'];
    $options = $lic['options'] !== null ? options_lire($lic['options']) : options_lire($dist['options']);
    $echeance = $lic['echeance'] === null ? null : (int)$lic['echeance'];
    $message = $dist['message'] ?? null;
    return $base + [
        'ok' => true,
        'code' => null,
        'id_poste' => id_poste((string)$base['machine'], (string)$base['produit']),
        'titulaire' => (string)$lic['titulaire'],
        'echeance' => $echeance,
        'jours_restants' => jours_restants($echeance, $maintenant),
        'hors_ligne_jusqu' => $maintenant + max($tolerance * JOUR, TOLERANCE_MIN_S),
        'preavis_j' => (int)$dist['preavis_j'],
        'options' => $options,
        'version_min' => version_min_normalisee($dist['version_min']),
        'message' => ($message === null || $message === '') ? null : (string)$message,
    ];
}

/**
 * activer / valider, en une transaction immediate : un remplacement de cle depuis la console
 * (D67) ne peut pas s'intercaler entre la lecture de la licence par son hash et sa liaison ou
 * l'envoi du jeton ; l'ancienne cle ne recoit plus rien une fois remplacee.
 */
function api_licence(PDO $db, array $r, array $dist, array $base, string $ip, int $maintenant): array
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $reponse = api_licence_traiter($db, $r, $dist, $base, $ip, $maintenant);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return $reponse;
}

function api_licence_traiter(PDO $db, array $r, array $dist, array $base, string $ip, int $maintenant): array
{
    $cle = cle_normaliser($r['cle']);
    $lic = $cle === null ? null : db_ligne($db, 'SELECT * FROM licences WHERE cle_hash = ?', [cle_hash($cle)]);
    if ($lic === null || (int)$lic['distribution_id'] !== (int)$dist['id']) {
        return api_refus($base, 'cle_invalide');
    }
    $activation = $r['op'] === 'activer';
    $code = licence_controler($lic, $r['machine'], $activation, $maintenant);
    if ($code === null && api_version_refusee($dist, $r['version'])) {
        $code = 'version_trop_ancienne';
    }
    $poste = id_poste($r['machine'], $r['produit']);
    if ($code !== null) {
        if ($code === 'cle_liee_autre_poste') {
            journal_ecrire($db, 'poste:' . $poste, 'refus_autre_poste', 'licence ' . $lic['id'],
                'cle ...' . $lic['cle_indice'] . ' saisie sur ' . $r['poste'], $ip, $maintenant);
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
        journal_ecrire($db, 'poste:' . $poste, 'activation', 'licence ' . $lic['id'],
            'cle ...' . $lic['cle_indice'] . ' liee a ' . $r['poste'], $ip, $maintenant);
    }
    licence_contact($db, (int)$lic['id'], $r, $ip, $maintenant);
    if (!$activation) {
        // Premier valider reussi : la cle remise par une demande acceptee n'a plus a etre conservee.
        db_modifier($db, 'UPDATE demandes SET cle_chiffree = NULL WHERE licence_id = ? AND cle_chiffree IS NOT NULL',
            [(int)$lic['id']]);
    }
    return api_jeton($lic, $dist, $base, $maintenant);
}
