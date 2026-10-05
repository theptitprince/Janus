<?php
// installation.php - Logique de l'assistant d'installation (install.php) : base,
//                    paire de cles, secret des demandes, .htpasswd, protection de
//                    /admin/, verrou. Aussi utilise pour changer le mot de passe.
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/commun.php';

const MOT_DE_PASSE_MIN = 20;
const JETON_INSTALLATION_MIN = 20;

function installation_verrouillee(array $config): bool
{
    return is_file((string)$config['verrou']);
}

/** Problemes bloquants avant tout formulaire. */
function installation_prealables(array $config): array
{
    $problemes = [];
    if (!extension_loaded('pdo_sqlite')) {
        $problemes[] = 'Extension PHP pdo_sqlite absente.';
    }
    if (!extension_loaded('sodium')) {
        $problemes[] = 'Extension PHP sodium absente.';
    }
    if (strlen((string)$config['jeton_installation']) < JETON_INSTALLATION_MIN) {
        $problemes[] = 'jeton_installation absent ou trop court dans config.php ('
            . JETON_INSTALLATION_MIN . ' caracteres minimum).';
    }
    if (is_file((string)$config['base'])) {
        $problemes[] = 'Une base existe deja : ' . $config['base'] . '.';
    }
    return $problemes;
}

/** URL d'API : HTTPS, sauf boucle locale (banc d'essai), terminee par /. */
function url_api_valide(string $url): bool
{
    $morceaux = parse_url($url);
    if (!is_array($morceaux) || !isset($morceaux['scheme'], $morceaux['host']) || substr($url, -1) !== '/'
        || strlen($url) > 300 || preg_match('/[\s"<>]/', $url) === 1) {
        return false;
    }
    if ($morceaux['scheme'] === 'https') {
        return true;
    }
    return $morceaux['scheme'] === 'http' && in_array($morceaux['host'], ['127.0.0.1', 'localhost', '[::1]'], true);
}

function utilisateur_valide(string $utilisateur): bool
{
    return preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $utilisateur) === 1;
}

function mot_de_passe_erreur(string $mot_de_passe, string $confirmation): ?string
{
    if (longueur_utf8($mot_de_passe) < MOT_DE_PASSE_MIN) {
        return 'Le mot de passe doit faire au moins ' . MOT_DE_PASSE_MIN . ' caracteres.';
    }
    if (!hash_equals($mot_de_passe, $confirmation)) {
        return 'Les deux saisies du mot de passe different.';
    }
    return null;
}

function installation_erreurs(array $p): array
{
    $erreurs = [];
    if (!utilisateur_valide($p['utilisateur'])) {
        $erreurs[] = 'Identifiant invalide (lettres, chiffres, . _ -, 32 caracteres maximum).';
    }
    $mdp = mot_de_passe_erreur($p['mot_de_passe'], $p['confirmation']);
    if ($mdp !== null) {
        $erreurs[] = $mdp;
    }
    if (!url_api_valide($p['url'])) {
        $erreurs[] = 'URL principale invalide (https://.../api/v1/).';
    }
    if ($p['url_secours'] !== '' && (!url_api_valide($p['url_secours']) || $p['url_secours'] === $p['url'])) {
        $erreurs[] = 'URL de secours invalide (https://.../api/v1/, differente de la principale).';
    }
    foreach (explode(',', $p['email_notification']) as $adresse) {
        if (trim($adresse) !== '' && filter_var(trim($adresse), FILTER_VALIDATE_EMAIL) === false) {
            $erreurs[] = 'Adresse de notification invalide : ' . trim($adresse);
        }
    }
    return $erreurs;
}

/** Ajoute ou remplace l'utilisateur ; mot de passe en bcrypt ($2y$, accepte par Apache 2.4). */
function htpasswd_ecrire(string $fichier, string $utilisateur, string $mot_de_passe): void
{
    $lignes = [];
    if (is_file($fichier)) {
        foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            if (strncmp($ligne, $utilisateur . ':', strlen($utilisateur) + 1) !== 0) {
                $lignes[] = $ligne;
            }
        }
    }
    $lignes[] = $utilisateur . ':' . password_hash($mot_de_passe, PASSWORD_BCRYPT);
    $temporaire = $fichier . '.tmp';
    if (file_put_contents($temporaire, implode("\n", $lignes) . "\n", LOCK_EX) === false
        || !rename($temporaire, $fichier)) {
        throw new RuntimeException('ecriture du .htpasswd impossible');
    }
    // Lisible par Apache ; les mots de passe n'y sont que haches.
    @chmod($fichier, 0644);
}

function htpasswd_utilisateurs(string $fichier): array
{
    $utilisateurs = [];
    foreach ((is_file($fichier) ? file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []) ?: [] as $ligne) {
        $utilisateurs[] = explode(':', $ligne, 2)[0];
    }
    return $utilisateurs;
}

function admin_htaccess(string $htpasswd): string
{
    if (preg_match('/["\r\n]/', $htpasswd) === 1) {
        throw new RuntimeException('chemin du .htpasswd invalide');
    }
    return implode("\n", [
        '# .htaccess - Console d\'administration : authentification Basic (genere par install.php)',
        '# ETDEL (c) 2026',
        'AuthType Basic',
        'AuthName "Console licences ETDEL"',
        'AuthUserFile "' . $htpasswd . '"',
        'Require valid-user',
        'Options -Indexes',
        '',
    ]);
}

function dossier_proteger(string $dossier): void
{
    $fichier = $dossier . '/.htaccess';
    if (!is_file($fichier)) {
        file_put_contents($fichier, "# Jamais servi (ETDEL)\n<IfModule mod_authz_core.c>\nRequire all denied\n"
            . "</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
    }
}

/**
 * Installation complete. $p : utilisateur, mot_de_passe, confirmation, url,
 * url_secours, email_notification. Renvoie kid, cle publique et URL.
 */
function installation_executer(array $config, array $p, string $dossier_admin, int $maintenant): array
{
    if (installation_verrouillee($config)) {
        throw new RuntimeException('installation deja effectuee');
    }
    $problemes = installation_prealables($config);
    $erreurs = installation_erreurs($p);
    if ($problemes !== [] || $erreurs !== []) {
        throw new InvalidArgumentException(implode(' ', array_merge($problemes, $erreurs)));
    }
    $base = (string)$config['base'];
    $dossier_base = dirname($base);
    if (!is_dir($dossier_base) && !mkdir($dossier_base, 0700, true) && !is_dir($dossier_base)) {
        throw new RuntimeException('dossier de la base impossible a creer : ' . $dossier_base);
    }
    // Garde contre deux executions simultanees.
    $encours = @fopen($config['verrou'] . '.encours', 'x');
    if ($encours === false) {
        throw new RuntimeException('installation deja en cours');
    }
    try {
        dossier_proteger($dossier_base);
        $db = db_connecter($base);
        $db->exec((string)file_get_contents($config['prive'] . '/schema.sql'));
        $urls = array_values(array_filter([$p['url'], $p['url_secours']]));
        foreach ($urls as $rang => $url) {
            db_inserer($db, 'urls_serveur', ['url' => $url, 'priorite' => ($rang + 1) * 10, 'actif' => 1]);
        }
        $cle = signature_creer($db, $config, 1, null, $maintenant);
        secret_demandes_creer($config);
        htpasswd_ecrire((string)$config['htpasswd'], $p['utilisateur'], $p['mot_de_passe']);
        if (file_put_contents($dossier_admin . '/.htaccess', admin_htaccess((string)$config['htpasswd'])) === false) {
            throw new RuntimeException('ecriture de admin/.htaccess impossible');
        }
        reglage_ecrire($db, 'email_notification', trim($p['email_notification']));
        reglage_ecrire($db, 'email_expediteur', 'licences@' . parse_url($p['url'], PHP_URL_HOST));
        journal_ecrire($db, $p['utilisateur'], 'installation', null, 'serveur ' . VERSION_SERVEUR . ', kid 1',
            ip_client(), $maintenant);
        $db = null;
        if (file_put_contents((string)$config['verrou'], date('c', $maintenant) . "\n") === false) {
            throw new RuntimeException('creation du verrou impossible');
        }
    } catch (Throwable $e) {
        // Une installation ratee ne doit pas bloquer la suivante : la base partielle est retiree.
        $db = null;
        foreach (['', '-wal', '-shm'] as $suffixe) {
            if (is_file($base . $suffixe)) {
                @unlink($base . $suffixe);
            }
        }
        throw $e;
    } finally {
        fclose($encours);
        @unlink($config['verrou'] . '.encours');
    }
    return ['kid' => 1, 'cle_publique' => $cle['cle_publique'], 'urls' => $urls];
}

/**
 * Mot de passe de la console perdu : sans SSH ni outil local, la seule preuve
 * d'autorite est l'acces FTP. Un jeton 'jeton_reinitialisation' depose dans
 * config.php ouvre, dans install.php verrouille, un formulaire qui ne fait que
 * reecrire le .htpasswd ; chaque jeton ne sert qu'une fois.
 */
function reinitialisation_fichier(array $config): string
{
    return $config['prive'] . '/reinitialisation.utilisee';
}

function reinitialisation_ouverte(array $config): bool
{
    $jeton = (string)($config['jeton_reinitialisation'] ?? '');
    if (strlen($jeton) < JETON_INSTALLATION_MIN || hash_equals((string)$config['jeton_installation'], $jeton)
        || !installation_verrouillee($config)) {
        return false;
    }
    $utilises = @file(reinitialisation_fichier($config), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return !in_array(hash('sha256', $jeton), $utilises, true);
}

function reinitialisation_executer(array $config, string $jeton, string $utilisateur, string $mot_de_passe,
                                   string $confirmation, string $ip, int $maintenant): void
{
    if (!reinitialisation_ouverte($config) || !hash_equals((string)$config['jeton_reinitialisation'], $jeton)) {
        throw new RuntimeException('Jeton de reinitialisation incorrect ou deja utilise.');
    }
    // Seul le mot de passe d'un compte existant se remplace : jamais de compte en plus.
    if (!in_array($utilisateur, htpasswd_utilisateurs((string)$config['htpasswd']), true)) {
        throw new InvalidArgumentException('Identifiant inconnu : seul le mot de passe d\'un compte existant peut etre '
            . 'reinitialise.');
    }
    $erreur = mot_de_passe_erreur($mot_de_passe, $confirmation);
    if ($erreur !== null) {
        throw new InvalidArgumentException($erreur);
    }
    htpasswd_ecrire((string)$config['htpasswd'], $utilisateur, $mot_de_passe);
    if (file_put_contents(reinitialisation_fichier($config), hash('sha256', $jeton) . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('enregistrement du jeton utilise impossible');
    }
    try {
        journal_ecrire(db_ouvrir((string)$config['base']), $utilisateur, 'mot_de_passe_reinitialise', $utilisateur,
            'par install.php (jeton de reinitialisation)', $ip, $maintenant);
    } catch (Throwable $e) {
        error_log('etdel reinitialisation : ' . $e->getMessage());
    }
}
