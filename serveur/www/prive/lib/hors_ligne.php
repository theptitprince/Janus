<?php
// hors_ligne.php - Licences hors ligne (postes jamais ou rarement connectes) : identifiant du poste
//                  saisi dans la console, code court a dicter (HMAC-SHA256 tronque), code long signe
//                  (Ed25519), secret des codes courts, creation et remplacement d'une licence hors ligne.
// ETDEL (c) 2026

declare(strict_types=1);

/*
 * FORMAT DES CODES HORS LIGNE (le module client applique exactement les memes regles).
 *
 * Commun aux deux formes
 *   produit  : code du produit, tel que l'application l'envoie (casse comprise), ex. "APP".
 *   id_poste : identifiant du poste sous sa forme canonique "XXXX-XXXX" (8 caracteres Crockford, tiret
 *              au milieu), ex. "SHXT-2380" = id_poste(machine, produit).
 *   jours    : entier 0..65535. 0 = illimitee. Sinon (jour 0 = 2026-01-01 UTC) : numero du dernier jour de
 *              validite + 1 ; la licence vaut jusqu'a la fin (UTC) de ce jour, soit
 *              echeance = HL_EPOQUE + jours * 86400 (exclue), HL_EPOQUE = 1767225600 (2026-01-01 00:00 UTC).
 *   options  : 1 = toutes les options ("*"), 0 = aucune.
 *   Message authentifie : "ETDHL<v>|" + produit + "|" + id_poste + "|" + jours en decimal sans zero en tete
 *   + "|" + "1" ou "0", en ASCII (v = 1 pour le code court, 2 pour le code long).
 *
 * Code court "ETDHL-XXXX-XXXX-XXXX-XXXX" (16 caracteres Crockford = 80 bits)
 *   Entier N de 75 bits code par les 15 premiers caracteres (5 bits chacun, poids fort d'abord) :
 *     bits 74-73 : version = 1 ; bit 72 : options ; bits 71-56 : jours ;
 *     bits 55-0  : MAC = 7 premiers octets (poids fort d'abord) de HMAC-SHA256(secret, message v1),
 *                  secret = 32 octets (prive/cles/secret_hors_ligne.key ; LICENCE_SECRET_HORS_LIGNE du client,
 *                  en base64url sans remplissage).
 *   16e caractere : somme des valeurs des 15 premiers modulo 32 (comme les cles ETDEL).
 *
 * Code long "ETDHL2-" + groupes de 4 caracteres separes par des tirets (109 caracteres Crockford)
 *   68 octets : version (1 octet = 2), jours (2 octets, poids fort d'abord), options (1 octet, 0 ou 1),
 *   signature Ed25519 (64 octets) du message v2 par la cle de signature active du serveur.
 *   Les 544 bits, poids fort d'abord, suivis d'un bit 0 de remplissage, donnent 109 caracteres de 5 bits.
 *   Forme canonique : "ETDHL2-" + 27 groupes de 4 + 1 caractere, soit 143 caracteres.
 *
 * Saisie tolerante (les deux formes) : majuscules ; tirets, espaces, tabulations et retours a la ligne
 * ignores ; dans le corps (apres le prefixe ETDHL ou ETDHL2), O lu 0, I et L lus 1. Le prefixe est
 * obligatoire. Apres nettoyage : 21 caracteres = code court, 115 = code long. Le serveur retrouve la
 * licence par le SHA-256 (hexadecimal) de la forme canonique : cle_hash (court) ou cle_long_hash (long).
 *
 * VECTEURS DE TEST FIXES (a reprendre tels quels cote client) :
 *   secret = octets 0x00, 0x01, ..., 0x1F (base64url : AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8)
 *   court("APP", "SHXT-2380", 365, toutes)    = ETDHL-C0PV-ZW13-4A4S-AAQJ  (MAC = ff8119144ca957, fin exclue
 *                                                1798761600 = 2027-01-01 00:00 UTC : valable tout le 31/12/2026 UTC)
 *   court("MONAPPLI", "Y19N-R4ZR", 0, aucune) = ETDHL-8000-Y79Y-QZ4Q-KQXC
 *   graine Ed25519 = octets 0x00, 0x01, ..., 0x1F (cle publique base64url :
 *     A6EHv_POEL4dcN0Y50vAmWfk1jCbpQ1fHdyGZBJVMbg)
 *   long("APP", "SHXT-2380", 365, toutes) =
 *     ETDHL2-080P-T0DF-3ZY9-J2Z1-JFNT-0S22-KPQ6-B1BM-DSXS-V14X-3SKA-CPJG-00PT-CEV4-
 *     WXW4-QW6A-8C3S-127D-GPPR-NBEC-V45T-WC9K-DD60-A1BP-YTTK-VQ8T-12VG-C
 *   (ligne coupee ici pour la lecture : le code est d'un seul tenant, 143 caracteres)
 */

// Jour 0 des codes hors ligne : 2026-01-01 00:00:00 UTC.
const HL_EPOQUE = 1767225600;
const HL_JOURS_MAX = 65535;
const HL_VERSION_COURT = 1;
const HL_VERSION_LONG = 2;
// Code long : 68 octets = 544 bits, plus 1 bit de remplissage = 109 caracteres de 5 bits.
const HL_LONG_CARACTERES = 109;
// Code identique deja attribue (meme poste, produit, fin et options) : la fin est repoussee d'un jour, au
// plus ce nombre de fois.
const HL_DECALAGES_MAX = 30;
const CODE_LONG_NON_DISPONIBLE = 'Code long non reaffichable : la cle de signature a change depuis sa creation. Le code '
    . 'long deja transmis reste valable sur les postes qui connaissent l\'ancienne cle ; "Nouveau code hors ligne pour '
    . 'ce poste" cree un nouveau code court et un nouveau code long.';

/** Identifiant du poste saisi : majuscules, O lu 0, I et L lus 1, tiret et espaces facultatifs ; XXXX-XXXX ou null. */
function id_poste_normaliser($texte): ?string
{
    if (!is_string($texte) || strlen($texte) > 32) {
        return null;
    }
    $brut = strtr(str_replace(['-', ' ', "\t", "\r", "\n"], '', strtoupper($texte)), ['O' => '0', 'I' => '1', 'L' => '1']);
    if (preg_match('/^[0-9A-HJKMNP-TV-Z]{8}$/', $brut) !== 1) {
        return null;
    }
    return substr($brut, 0, 4) . '-' . substr($brut, 4);
}

/** Octets -> chaine de bits ('0' et '1'), poids fort d'abord. */
function hl_bits(string $octets): string
{
    $bits = '';
    for ($i = 0, $n = strlen($octets); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($octets[$i])), 8, '0', STR_PAD_LEFT);
    }
    return $bits;
}

/** Chaine de bits (longueur multiple de 8) -> octets. */
function hl_octets(string $bits): string
{
    $octets = '';
    for ($i = 0, $n = strlen($bits); $i < $n; $i += 8) {
        $octets .= chr((int)bindec(substr($bits, $i, 8)));
    }
    return $octets;
}

/** Chaine de bits (longueur multiple de 5) -> caracteres Crockford. */
function hl_base32(string $bits): string
{
    $texte = '';
    for ($i = 0, $n = strlen($bits); $i < $n; $i += 5) {
        $texte .= ALPHABET_CROCKFORD[(int)bindec(substr($bits, $i, 5))];
    }
    return $texte;
}

/** Caracteres Crockford (deja corriges) -> chaine de bits, ou null. */
function hl_base32_bits(string $texte): ?string
{
    $bits = '';
    for ($i = 0, $n = strlen($texte); $i < $n; $i++) {
        $valeur = strpos(ALPHABET_CROCKFORD, $texte[$i]);
        if ($valeur === false) {
            return null;
        }
        $bits .= str_pad(decbin($valeur), 5, '0', STR_PAD_LEFT);
    }
    return $bits;
}

/** Caractere de controle : somme des valeurs des caracteres modulo 32 (comme les cles ETDEL). */
function hl_somme(string $texte): string
{
    $somme = 0;
    for ($i = 0, $n = strlen($texte); $i < $n; $i++) {
        $somme += (int)strpos(ALPHABET_CROCKFORD, $texte[$i]);
    }
    return ALPHABET_CROCKFORD[$somme % 32];
}

/** Message authentifie par le MAC (v1, code court) ou la signature (v2, code long). */
function hl_message(int $version, string $produit, string $id_poste, int $jours, bool $toutes): string
{
    return 'ETDHL' . $version . '|' . $produit . '|' . $id_poste . '|' . $jours . '|' . ($toutes ? '1' : '0');
}

function hl_verifier_parametres(string $id_poste, int $jours): void
{
    if (id_poste_normaliser($id_poste) !== $id_poste) {
        throw new InvalidArgumentException('identifiant du poste invalide');
    }
    if ($jours < 0 || $jours > HL_JOURS_MAX) {
        throw new InvalidArgumentException('duree hors limites');
    }
}

/** Code court "ETDHL-XXXX-XXXX-XXXX-XXXX" (voir le format en tete de ce fichier). */
function code_hors_ligne_court(string $secret, string $produit, string $id_poste, int $jours, bool $toutes): string
{
    hl_verifier_parametres($id_poste, $jours);
    $mac = substr(hash_hmac('sha256', hl_message(HL_VERSION_COURT, $produit, $id_poste, $jours, $toutes), $secret, true),
        0, 7);
    $corps = hl_base32(str_pad(decbin(HL_VERSION_COURT), 2, '0', STR_PAD_LEFT) . ($toutes ? '1' : '0')
        . str_pad(decbin($jours), 16, '0', STR_PAD_LEFT) . hl_bits($mac));
    $corps .= hl_somme($corps);
    return 'ETDHL-' . implode('-', str_split($corps, 4));
}

/** Code long "ETDHL2-..." signe par $privee (cle secrete Ed25519 de 64 octets). */
function code_hors_ligne_long(string $privee, string $produit, string $id_poste, int $jours, bool $toutes): string
{
    hl_verifier_parametres($id_poste, $jours);
    $signature = sodium_crypto_sign_detached(hl_message(HL_VERSION_LONG, $produit, $id_poste, $jours, $toutes), $privee);
    $octets = chr(HL_VERSION_LONG) . pack('n', $jours) . chr($toutes ? 1 : 0) . $signature;
    return 'ETDHL2-' . implode('-', str_split(hl_base32(hl_bits($octets) . '0'), 4));
}

/**
 * Code hors ligne saisi ou recu, ou null. Renvoie ['forme' => 'court' ou 'long', 'code' => forme canonique,
 * 'jours' => int, 'toutes' => bool, 'preuve' => MAC (7 octets) ou signature (64 octets)]. La preuve n'est
 * pas verifiee ici (voir code_hors_ligne_court_valide et code_hors_ligne_long_valide).
 */
function code_hors_ligne_normaliser($texte): ?array
{
    if (!is_string($texte) || strlen($texte) > 400) {
        return null;
    }
    $brut = str_replace(['-', ' ', "\t", "\r", "\n"], '', strtoupper($texte));
    $corrections = ['O' => '0', 'I' => '1', 'L' => '1'];
    if (strlen($brut) === 21 && strncmp($brut, 'ETDHL', 5) === 0) {
        $corps = strtr(substr($brut, 5), $corrections);
        $bits = hl_base32_bits(substr($corps, 0, 15));
        if ($bits === null || strpos(ALPHABET_CROCKFORD, $corps[15]) === false
            || hl_somme(substr($corps, 0, 15)) !== $corps[15] || bindec(substr($bits, 0, 2)) !== HL_VERSION_COURT) {
            return null;
        }
        return ['forme' => 'court', 'code' => 'ETDHL-' . implode('-', str_split($corps, 4)),
            'jours' => (int)bindec(substr($bits, 3, 16)), 'toutes' => $bits[2] === '1',
            'preuve' => hl_octets(substr($bits, 19, 56))];
    }
    if (strlen($brut) === 6 + HL_LONG_CARACTERES && strncmp($brut, 'ETDHL2', 6) === 0) {
        $corps = strtr(substr($brut, 6), $corrections);
        $bits = hl_base32_bits($corps);
        if ($bits === null || substr($bits, -1) !== '0') {
            return null;
        }
        $octets = hl_octets(substr($bits, 0, -1));
        if (ord($octets[0]) !== HL_VERSION_LONG || !in_array(ord($octets[3]), [0, 1], true)) {
            return null;
        }
        return ['forme' => 'long', 'code' => 'ETDHL2-' . implode('-', str_split($corps, 4)),
            'jours' => unpack('n', substr($octets, 1, 2))[1], 'toutes' => ord($octets[3]) === 1,
            'preuve' => substr($octets, 4)];
    }
    return null;
}

/** Le code court vaut-il pour ce produit et ce poste avec ce secret ? (verification faite par le client) */
function code_hors_ligne_court_valide(string $secret, string $produit, string $id_poste, string $saisie): bool
{
    $code = code_hors_ligne_normaliser($saisie);
    if ($code === null || $code['forme'] !== 'court' || id_poste_normaliser($id_poste) !== $id_poste) {
        return false;
    }
    return hash_equals(code_hors_ligne_court($secret, $produit, $id_poste, $code['jours'], $code['toutes']), $code['code']);
}

/** Le code long est-il signe par cette cle publique (32 octets) pour ce produit et ce poste ? */
function code_hors_ligne_long_valide(string $publique, string $produit, string $id_poste, string $saisie): bool
{
    $code = code_hors_ligne_normaliser($saisie);
    if ($code === null || $code['forme'] !== 'long' || strlen($publique) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        return false;
    }
    return sodium_crypto_sign_verify_detached($code['preuve'],
        hl_message(HL_VERSION_LONG, $produit, $id_poste, $code['jours'], $code['toutes']), $publique);
}

/**
 * Valeur "jours" pour une duree saisie : le dernier jour de validite est le jour UTC qui contient
 * maintenant + duree (jamais moins que la duree demandee, au plus un jour de plus) ; null = illimitee (0).
 */
function hl_jours(?int $duree_j, int $maintenant): int
{
    if ($duree_j === null) {
        return 0;
    }
    $fin = $maintenant + $duree_j * JOUR;
    $jours = $fin < HL_EPOQUE ? 0 : intdiv($fin - HL_EPOQUE, JOUR) + 1;
    if ($duree_j < 1 || $jours < 1 || $jours > HL_JOURS_MAX) {
        throw new InvalidArgumentException('Duree hors des limites d\'un code hors ligne.');
    }
    return $jours;
}

/** Echeance (horodatage exclu, comme licences.echeance) d'une valeur "jours" ; null = illimitee. */
function hl_echeance(int $jours): ?int
{
    return $jours === 0 ? null : HL_EPOQUE + $jours * JOUR;
}

/** Une licence est-elle une licence hors ligne ? */
function licence_hors_ligne(array $lic): bool
{
    return (int)($lic['hors_ligne'] ?? 0) === 1;
}

/**
 * Date affichee d'une echeance (JJ/MM/AAAA, '' sans echeance). Licence hors ligne dont l'echeance est un minuit
 * UTC (celle d'un code, ou prolongee de jours entiers) : son dernier jour de validite, en UTC, comme l'application
 * (le lendemain commence a 01:00 ou 02:00 en heure de Paris, deja hors validite). Sinon date_fr (fin d'un jour
 * fixe par "Fixer la date", ou cle ETDEL).
 */
function echeance_fr(?int $echeance, bool $hors_ligne): string
{
    if ($echeance !== null && $hors_ligne && ($echeance - HL_EPOQUE) % JOUR === 0) {
        return gmdate('d/m/Y', $echeance - 1);
    }
    return date_fr($echeance);
}

/** Designation d'une cle dans le journal : jamais plus que ses 4 derniers caracteres. */
function licence_designation(array $lic): string
{
    return (licence_hors_ligne($lic) ? 'code hors ligne ...' : 'cle ...') . $lic['cle_indice'];
}

// ---------------------------------------------------------------------------
// Secret des codes courts
// ---------------------------------------------------------------------------

function secret_hors_ligne_fichier(array $config): string
{
    return rtrim((string)$config['cles'], '/') . '/secret_hors_ligne.key';
}

function secret_hors_ligne_lire(array $config): ?string
{
    $contenu = @file_get_contents(secret_hors_ligne_fichier($config));
    $secret = is_string($contenu) ? base64_decode(trim($contenu), true) : false;
    return (is_string($secret) && strlen($secret) === 32) ? $secret : null;
}

/**
 * Cree le secret (32 octets aleatoires) s'il n'existe pas ; ne remplace jamais un secret existant : les
 * applications deja livrees l'embarquent (LICENCE_SECRET_HORS_LIGNE). Renvoie true s'il vient d'etre cree.
 */
function secret_hors_ligne_creer(array $config): bool
{
    $fichier = secret_hors_ligne_fichier($config);
    $dossier = dirname($fichier);
    if (!is_dir($dossier) && !mkdir($dossier, 0700, true) && !is_dir($dossier)) {
        throw new RuntimeException('dossier des cles impossible a creer');
    }
    $ancien = umask(0077);
    try {
        // Creation exclusive : deux requetes simultanees ne produisent jamais deux secrets.
        $flux = @fopen($fichier, 'x');
    } finally {
        umask($ancien);
    }
    if ($flux === false) {
        return false;
    }
    $ecrit = fwrite($flux, base64_encode(random_bytes(32)));
    fclose($flux);
    @chmod($fichier, 0600);
    if ($ecrit === false) {
        throw new RuntimeException('ecriture impossible : ' . basename($fichier));
    }
    return true;
}

// Reglage : empreinte SHA-256 (hexadecimal) du secret des codes courts, inscrite a sa creation (ou a sa premiere
// lecture pour une base anterieure). Un fichier absent ou remplace (migration d'hebergement, restauration de la
// base sans prive/cles/) n'est alors jamais recree en silence : les applications livrees embarquent l'ancien.
const REGLAGE_EMPREINTE_SECRET_HL = 'secret_hors_ligne_empreinte';
const SECRET_HL_A_RESTAURER = 'restaurer prive/cles/secret_hors_ligne.key depuis la copie de secours (les applications '
    . 'livrees embarquent ce secret : LICENCE_SECRET_HORS_LIGNE). Aucun code hors ligne ne peut etre cree d\'ici la.';

/**
 * Secret des codes courts. Cree au premier besoin, seulement pour une base qui n'en a jamais eu : ni empreinte
 * inscrite, ni licence hors ligne (installation anterieure a cette fonction ; creation inscrite au journal).
 * Sinon, fichier absent ou different de l'empreinte inscrite : RuntimeException, rien n'est cree. Sans $db
 * (aucune verification possible), un fichier absent n'est jamais cree.
 */
function secret_hors_ligne(array $config, ?PDO $db = null, ?int $maintenant = null): string
{
    $secret = secret_hors_ligne_lire($config);
    $empreinte = $db === null ? '' : reglage_lire($db, REGLAGE_EMPREINTE_SECRET_HL);
    if ($secret === null && !is_file(secret_hors_ligne_fichier($config))) {
        $existants = $db === null ? 0 : (int)db_valeur($db, 'SELECT COUNT(*) FROM licences WHERE hors_ligne = 1');
        if ($db === null || $empreinte !== '' || $existants > 0) {
            throw new RuntimeException('Secret des codes hors ligne absent (prive/cles/secret_hors_ligne.key) alors qu\'il '
                . 'a deja ete cree pour ce serveur : ' . SECRET_HL_A_RESTAURER);
        }
        if (secret_hors_ligne_creer($config)) {
            journal_ecrire($db, 'systeme', 'secret_hors_ligne_cree', null, 'secret des codes hors ligne courts cree '
                . '(prive/cles/secret_hors_ligne.key) : reporter LICENCE_SECRET_HORS_LIGNE dans les applications',
                null, $maintenant ?? time());
        }
        $secret = secret_hors_ligne_lire($config);
    }
    if ($secret === null) {
        throw new RuntimeException('secret des codes hors ligne illisible (prive/cles/secret_hors_ligne.key)');
    }
    if ($db !== null) {
        if ($empreinte === '') {
            reglage_ecrire($db, REGLAGE_EMPREINTE_SECRET_HL, hash('sha256', $secret));
        } elseif (!hash_equals($empreinte, hash('sha256', $secret))) {
            throw new RuntimeException('Secret des codes hors ligne different de celui cree pour ce serveur '
                . '(prive/cles/secret_hors_ligne.key remplace) : ' . SECRET_HL_A_RESTAURER);
        }
    }
    return $secret;
}

// ---------------------------------------------------------------------------
// Licences hors ligne
// ---------------------------------------------------------------------------

/**
 * Codes d'une licence hors ligne. Un code est entierement determine par le produit, le poste, la fin et les
 * options : si le code court est deja celui d'une autre licence (sauf $sauf_id), la fin est repoussee d'un
 * jour, au plus HL_DECALAGES_MAX fois ; une licence illimitee identique est refusee.
 * Renvoie ['court', 'long', 'jours', 'echeance', 'decalage' (jours ajoutes), 'identique' (id ou null)].
 */
function hl_codes_libres(PDO $db, array $config, string $produit, string $id_poste, ?int $duree_j, bool $toutes,
                         int $maintenant, int $sauf_id = 0): array
{
    $secret = secret_hors_ligne($config, $db, $maintenant);
    $jours = hl_jours($duree_j, $maintenant);
    $identique = null;
    for ($decalage = 0; ; $decalage++) {
        $court = code_hors_ligne_court($secret, $produit, $id_poste, $jours, $toutes);
        $autre = db_valeur($db, 'SELECT id FROM licences WHERE cle_hash = ? AND id <> ?', [cle_hash($court), $sauf_id]);
        if ($autre === null) {
            break;
        }
        $identique = $identique ?? (int)$autre;
        if ($jours === 0) {
            throw new InvalidArgumentException('La licence n. ' . (int)$autre . ' porte deja le meme code (meme poste, '
                . 'meme produit, illimitee, memes options) : indiquez une duree. Rien n\'a ete cree.');
        }
        if ($decalage >= HL_DECALAGES_MAX || $jours >= HL_JOURS_MAX) {
            throw new InvalidArgumentException('Codes identiques a ceux d\'autres licences de ce poste : choisissez une '
                . 'autre duree. Rien n\'a ete cree.');
        }
        $jours++;
    }
    $long = code_hors_ligne_long(signature_active($db, $config)['privee'], $produit, $id_poste, $jours, $toutes);
    return ['court' => $court, 'long' => $long, 'jours' => $jours, 'echeance' => hl_echeance($jours),
        'decalage' => $decalage, 'identique' => $identique];
}

/** Texte du journal pour une licence hors ligne : fin, options, fin du code (4 caracteres). */
function hl_resume(?int $echeance, bool $toutes, string $court): string
{
    return ($echeance === null ? 'illimitee' : 'echeance ' . echeance_fr($echeance, true)) . ', '
        . ($toutes ? 'toutes les options' : 'aucune option') . ', code ...' . substr($court, -4);
}

/**
 * Licence hors ligne (a appeler dans une transaction) : ligne de licences non liee (machine NULL), pour le
 * poste saisi, codes court (hache et chiffre, reaffichable) et long (hache, recalcule a l'affichage).
 * Renvoie l'id et les codes (voir hl_codes_libres).
 */
function licence_hors_ligne_creer(PDO $db, array $config, array $produit, string $id_poste, string $titulaire,
                                  ?int $duree_j, bool $toutes, string $acteur, string $ip, int $maintenant): array
{
    if ((int)$produit['actif'] !== 1) {
        throw new InvalidArgumentException('Produit inactif : reactivez-le sur sa page (Reglages avances, case Actif) pour '
            . 'lui creer des licences. Rien n\'a ete cree.');
    }
    $codes = hl_codes_libres($db, $config, (string)$produit['code'], $id_poste, $duree_j, $toutes, $maintenant);
    $id = db_inserer($db, 'licences', [
        'distribution_id' => (int)$produit['regles_id'], 'cle_hash' => cle_hash($codes['court']),
        'cle_indice' => substr($codes['court'], -4), 'cle_chiffree' => cle_chiffrer($codes['court'], secret_demandes($config)),
        'cle_long_hash' => cle_hash($codes['long']), 'hors_ligne' => 1, 'titulaire' => $titulaire,
        'echeance' => $codes['echeance'], 'options' => json_encode($toutes ? [OPTION_TOUTES] : []), 'id_poste' => $id_poste,
        'statut' => 'active', 'origine' => 'console', 'cree_le' => $maintenant, 'modifie_le' => $maintenant,
    ]);
    journal_ecrire($db, $acteur, 'licence_hors_ligne_creee', 'licence ' . $id, $titulaire . ', poste ' . $id_poste . ', '
        . hl_resume($codes['echeance'], $toutes, $codes['court']), $ip, $maintenant);
    return ['id' => $id] + $codes;
}

/**
 * Nouveau code (a appeler dans une transaction) : il remplace l'ancien sur la meme ligne (nouvelle echeance,
 * options, hachages et code chiffre) ; le serveur ne reconnait plus l'ancien code. Le statut est conserve.
 * $id_poste null ou identique : meme poste, ordinateur lie conserve ; un code identique a l'actuel (meme fin,
 * memes options) est refuse. Autre identifiant (nouvel ordinateur, par exemple apres un transfert) : la licence
 * passe a ce poste et n'est plus liee a aucun ordinateur ; le nouveau la liera a son premier contact.
 * $lic : licence_lire().
 */
function licence_hors_ligne_nouveau_code(PDO $db, array $config, array $lic, ?int $duree_j, bool $toutes, string $acteur,
                                         string $ip, int $maintenant, ?string $id_poste = null): array
{
    $ancien_poste = (string)$lic['id_poste'];
    $poste = $id_poste ?? $ancien_poste;
    $codes = hl_codes_libres($db, $config, (string)$lic['produit_code'], $poste, $duree_j, $toutes, $maintenant,
        (int)$lic['id']);
    if ($poste === $ancien_poste && hash_equals((string)$lic['cle_hash'], cle_hash($codes['court']))) {
        throw new InvalidArgumentException('Le nouveau code serait identique au code actuel (meme poste, meme fin, memes '
            . 'options) : choisissez une autre duree ou d\'autres options. Rien n\'a ete change.');
    }
    $valeurs = ['cle_hash' => cle_hash($codes['court']), 'cle_indice' => substr($codes['court'], -4),
        'cle_chiffree' => cle_chiffrer($codes['court'], secret_demandes($config)), 'cle_long_hash' => cle_hash($codes['long']),
        'echeance' => $codes['echeance'], 'options' => json_encode($toutes ? [OPTION_TOUTES] : []),
        'modifie_le' => $maintenant];
    $detail = 'ancien code ...' . $lic['cle_indice'] . ' ; nouveau : ' . hl_resume($codes['echeance'], $toutes, $codes['court']);
    if ($poste !== $ancien_poste) {
        $valeurs += ['id_poste' => $poste, 'machine' => null, 'nom_ordinateur' => null, 'lie_le' => null];
        $detail .= ' ; poste ' . $ancien_poste . ' -> ' . $poste . ($lic['machine'] === null ? ''
            : ' (ordinateur ' . $lic['nom_ordinateur'] . ' libere)');
    }
    db_maj($db, 'licences', $valeurs, 'id', (int)$lic['id']);
    journal_ecrire($db, $acteur, 'code_hors_ligne_remplace', 'licence ' . $lic['id'], $detail, $ip, $maintenant);
    return ['id' => (int)$lic['id']] + $codes;
}

/**
 * Codes d'une licence hors ligne pour la console : le code court dechiffre, le code long recalcule a partir
 * de lui (jours et options) avec la cle de signature active (signature deterministe) ; long null si la cle
 * a change depuis (son hachage ne correspond plus). $lic : licence_lire(). Null si le code est illisible.
 */
function licence_hors_ligne_codes(PDO $db, array $config, array $lic): ?array
{
    $court = $lic['cle_chiffree'] === null ? null : cle_dechiffrer((string)$lic['cle_chiffree'], secret_demandes($config));
    $code = $court === null ? null : code_hors_ligne_normaliser($court);
    if ($code === null || $code['forme'] !== 'court' || !hash_equals((string)$lic['cle_hash'], cle_hash($court))) {
        return null;
    }
    $long = code_hors_ligne_long(signature_active($db, $config)['privee'], (string)$lic['produit_code'],
        (string)$lic['id_poste'], $code['jours'], $code['toutes']);
    return ['court' => $court, 'long' => hash_equals((string)$lic['cle_long_hash'], cle_hash($long)) ? $long : null];
}
