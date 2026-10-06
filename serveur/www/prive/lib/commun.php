<?php
// commun.php - Configuration et fonctions partagees par l'API, la console,
//              l'assistant d'installation et la sauvegarde.
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/journal.php';

const JOUR = 86400;
const VERSION_API = 1;
const VERSION_SERVEUR = '1.0.0';

/** Charge prive/config.php et complete les valeurs par defaut. */
function config_charger(string $prive): array
{
    $fichier = $prive . '/config.php';
    if (!is_file($fichier)) {
        throw new RuntimeException('config.php absent du dossier prive');
    }
    $config = require $fichier;
    if (!is_array($config)) {
        throw new RuntimeException('config.php doit renvoyer un tableau');
    }
    return config_completer($config, $prive);
}

function config_completer(array $config, string $prive): array
{
    // Base et sauvegardes dans prive/data/ : un seul dossier a proteger, que
    // prive/ soit dans le dossier servi (disposition du depot) ou a cote.
    $config = array_merge([
        'jeton_installation' => '',
        'jeton_reinitialisation' => '',
        'base' => $prive . '/data/licenses.db',
        'dossier_sauvegardes' => $prive . '/data/sauvegardes',
        'sauvegardes_conservees' => 30,
        'cles' => $prive . '/cles',
        'htpasswd' => $prive . '/.htpasswd',
        'verrou' => $prive . '/install.verrou',
        'fuseau' => 'Europe/Paris',
        'url_console' => '',
        'emails_par_jour' => 50,
        // Remplace mail() dans les tests : function ($a, $sujet, $corps, $entetes): bool.
        'mailer' => null,
    ], $config);
    $config['prive'] = $prive;
    date_default_timezone_set((string)$config['fuseau']);
    return $config;
}

/**
 * Dossier prive : variable d'environnement ETDEL_PRIVE (banc d'essai), sinon
 * le premier candidat qui contient lib/commun.php (hors www, puis dans www).
 */
function prive_trouver(array $candidats): ?string
{
    $env = getenv('ETDEL_PRIVE');
    if (is_string($env) && $env !== '') {
        array_unshift($candidats, $env);
    }
    foreach ($candidats as $candidat) {
        if (is_file($candidat . '/lib/commun.php')) {
            return realpath($candidat) ?: $candidat;
        }
    }
    return null;
}

function h($texte): string
{
    return htmlspecialchars((string)$texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Nombre de caracteres d'une chaine UTF-8, ou -1 si elle est invalide (sans mbstring). */
function longueur_utf8(string $texte): int
{
    $n = preg_match_all('/./us', $texte);
    return $n === false ? -1 : $n;
}

function tronquer_utf8(string $texte, int $max): string
{
    if (preg_match('/^.{0,' . $max . '}/us', $texte, $m) !== 1) {
        return '';
    }
    return $m[0];
}

/** Retire les caracteres de controle (on garde les sauts de ligne si demande). */
function nettoyer_texte(string $texte, bool $multiligne = false): string
{
    $motif = $multiligne ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
    $propre = preg_replace($motif, '', $texte);
    return trim($propre === null ? '' : $propre);
}

/**
 * Texte borne : chaine UTF-8 valide d'au plus $max caracteres, sinon null.
 * Les champs recus ne sont jamais tronques en silence : trop long = refus.
 */
function texte_borne($valeur, int $max, bool $multiligne = false): ?string
{
    if (!is_string($valeur)) {
        return null;
    }
    $propre = nettoyer_texte($valeur, $multiligne);
    $n = longueur_utf8($propre);
    return ($n < 0 || $n > $max) ? null : $propre;
}

function version_tuple(string $version): array
{
    $parties = [];
    foreach (explode('.', trim($version)) as $morceau) {
        preg_match('/^[0-9]*/', $morceau, $m);
        $parties[] = $m[0] === '' ? 0 : (int)$m[0];
    }
    while (count($parties) > 1 && end($parties) === 0) {
        array_pop($parties);
    }
    return $parties;
}

/** Meme regle que le client Python : 1.10 > 1.9, 1.2 == 1.2.0, suffixes ignores. */
function version_comparer(string $a, string $b): int
{
    $x = version_tuple($a);
    $y = version_tuple($b);
    $n = max(count($x), count($y));
    for ($i = 0; $i < $n; $i++) {
        $p = $x[$i] ?? 0;
        $q = $y[$i] ?? 0;
        if ($p !== $q) {
            return $p < $q ? -1 : 1;
        }
    }
    return 0;
}

/** Version minimale effective : la plus exigeante du produit et de la distribution. */
function version_min_effective(?string $produit, ?string $distribution): ?string
{
    $produit = ($produit === null || trim($produit) === '') ? null : trim($produit);
    $distribution = ($distribution === null || trim($distribution) === '') ? null : trim($distribution);
    if ($produit === null) {
        return $distribution;
    }
    if ($distribution === null) {
        return $produit;
    }
    return version_comparer($produit, $distribution) >= 0 ? $produit : $distribution;
}

function date_fr(?int $ts, bool $heure = false): string
{
    if ($ts === null || $ts === 0) {
        return '';
    }
    return date($heure ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

function jours_restants(?int $echeance, int $maintenant): ?int
{
    if ($echeance === null) {
        return null;
    }
    return max(0, (int)ceil(($echeance - $maintenant) / JOUR));
}

/** Liste JSON d'options (codes) ; tableau vide si illisible. */
function options_lire(?string $json): array
{
    $liste = json_decode((string)$json, true);
    if (!is_array($liste)) {
        return [];
    }
    return array_values(array_filter($liste, 'is_string'));
}

// Joker : toutes les options, presentes et futures (D65).
const OPTION_TOUTES = '*';

/** Codes d'options saisis dans la console : "export_pdf, multi_navire", ou "*" pour toutes. */
function options_depuis_texte(string $texte): ?array
{
    $codes = [];
    foreach (preg_split('/[\s,;]+/', trim($texte)) ?: [] as $code) {
        if ($code === '') {
            continue;
        }
        if ($code !== OPTION_TOUTES && preg_match('/^[a-z0-9_]{1,40}$/', $code) !== 1) {
            return null;
        }
        $codes[$code] = true;
    }
    // Le joker englobe tout : inutile de garder les autres codes a cote.
    return isset($codes[OPTION_TOUTES]) ? [OPTION_TOUTES] : array_keys($codes);
}

/** Texte affiche dans la console pour une liste d'options. */
function options_affichees(array $options, string $vide = 'aucune'): string
{
    if (in_array(OPTION_TOUTES, $options, true)) {
        return 'toutes (*)';
    }
    return $options === [] ? $vide : implode(', ', $options);
}

function ip_client(): string
{
    // REMOTE_ADDR seulement : X-Forwarded-For est fourni par le client et falsifiable.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * Cle de comptage des limites : une adresse IPv4, ou le prefixe /64 d'une adresse
 * IPv6 (un client dispose couramment de tout un /64 et pourrait sinon changer
 * d'adresse a chaque requete). Une IPv4 notee en IPv6 (::ffff:a.b.c.d) reste une IPv4.
 */
function ip_limite(string $ip): string
{
    $octets = @inet_pton($ip);
    if (!is_string($octets) || strlen($octets) !== 16) {
        return $ip;
    }
    if (strncmp($octets, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
        return (string)inet_ntop(substr($octets, 12));
    }
    return inet_ntop(substr($octets, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** Domaine de la premiere URL active (expediteur des e-mails, lien de la console). */
function url_console(PDO $db, array $config): string
{
    if ($config['url_console'] !== '') {
        return rtrim((string)$config['url_console'], '/') . '/';
    }
    $url = db_valeur($db, 'SELECT url FROM urls_serveur WHERE actif = 1 ORDER BY priorite, id LIMIT 1');
    if (!is_string($url)) {
        return '';
    }
    return preg_replace('#api/v1/?$#', 'admin/', $url) ?? '';
}

function domaine_serveur(PDO $db): string
{
    $url = db_valeur($db, 'SELECT url FROM urls_serveur WHERE actif = 1 ORDER BY priorite, id LIMIT 1');
    $hote = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
    return is_string($hote) ? $hote : 'localhost';
}

/** Copie coherente de la base (VACUUM INTO, compatible WAL). */
function base_copier(PDO $db, string $destination): void
{
    if (is_file($destination)) {
        unlink($destination);
    }
    $requete = $db->prepare('VACUUM INTO ?');
    $requete->execute([$destination]);
}

/** Sauvegarde datee ; conserve les N plus recentes. Renvoie le chemin cree. */
function sauvegarde_creer(PDO $db, array $config, int $maintenant): string
{
    $dossier = (string)$config['dossier_sauvegardes'];
    if (!is_dir($dossier) && !mkdir($dossier, 0700, true) && !is_dir($dossier)) {
        throw new RuntimeException('dossier de sauvegarde impossible a creer');
    }
    $fichier = $dossier . '/licenses-' . gmdate('Ymd-His', $maintenant) . '.db';
    base_copier($db, $fichier);
    @chmod($fichier, 0600);
    $copies = glob($dossier . '/licenses-*.db') ?: [];
    rsort($copies);
    foreach (array_slice($copies, max(1, (int)$config['sauvegardes_conservees'])) as $ancienne) {
        unlink($ancienne);
    }
    return $fichier;
}

/**
 * Fin des suspensions datees (D61) : a la date choisie, la licence redevient
 * active d'elle-meme. Appelee a chaque requete de l'API et de la console, ce
 * qui suffit : un poste ne voit son statut qu'au moment ou il interroge l'API.
 */
function licences_fin_suspension(PDO $db, int $maintenant): void
{
    $echues = db_lignes($db, "SELECT id FROM licences WHERE statut = 'suspendue' AND suspendue_jusqu IS NOT NULL "
        . 'AND suspendue_jusqu <= ?', [$maintenant]);
    foreach ($echues as $lic) {
        // Condition repetee : deux requetes simultanees ne reactivent et ne journalisent qu'une fois.
        $fait = db_modifier($db, "UPDATE licences SET statut = 'active', suspendue_jusqu = NULL, modifie_le = ? "
            . "WHERE id = ? AND statut = 'suspendue' AND suspendue_jusqu IS NOT NULL AND suspendue_jusqu <= ?",
            [$maintenant, (int)$lic['id'], $maintenant]);
        if ($fait === 1) {
            journal_ecrire($db, 'systeme', 'licence_reactivee', 'licence ' . $lic['id'],
                'fin de la suspension programmee', null, $maintenant);
        }
    }
}
