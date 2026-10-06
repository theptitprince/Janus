<?php
// test_serveur.php - Tests du serveur en PHP CLI sur une base SQLite temporaire.
//                    Horloge injectee (aucune dependance a l'heure reelle), e-mails captures.
//                    Lancement : php serveur/tests/test_serveur.php
// ETDEL (c) 2026

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
    if (!(error_reporting() & $niveau)) {
        return false;
    }
    throw new ErrorException($message, 0, $niveau, $fichier, $ligne);
});

const RACINE = __DIR__ . '/..';
const T0 = 1791200000;
define('MACHINE_A', hash('sha256', 'poste A'));
define('MACHINE_B', hash('sha256', 'poste B'));
define('MACHINE_C', hash('sha256', 'poste C'));

require RACINE . '/www/prive/lib/api.php';
require RACINE . '/www/prive/lib/installation.php';
require RACINE . '/www/prive/lib/admin.php';

$failures = [];
$verifications = 0;

// Windows (poste de developpement) n'a pas de droits Unix : chmod n'y fait rien.
const WINDOWS = PHP_OS_FAMILY === 'Windows';

function droits_0600(string $fichier): bool
{
    return is_file($fichier) && (WINDOWS || (fileperms($fichier) & 0777) === 0600);
}

function chemin_absolu(string $chemin): bool
{
    return $chemin !== '' && ($chemin[0] === '/' || (WINDOWS && preg_match('#^[A-Za-z]:[/\\\\]#', $chemin) === 1));
}

function check(string $nom, bool $condition): void
{
    global $failures, $verifications;
    $verifications++;
    if (!$condition) {
        $failures[] = $nom;
        echo 'ECHEC : ' . $nom . "\n";
    }
}

$temporaires = [];

function supprimer_dossier(string $dossier): void
{
    if (!is_dir($dossier)) {
        return;
    }
    $elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($elements as $element) {
        $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
    }
    rmdir($dossier);
}

/** Environnement isole : dossier temporaire, config, e-mails captures. */
function environnement(array $surcharges = []): array
{
    global $temporaires;
    $tmp = sys_get_temp_dir() . '/etdel_test_' . bin2hex(random_bytes(6));
    mkdir($tmp . '/prive', 0700, true);
    mkdir($tmp . '/www/admin', 0700, true);
    $temporaires[] = $tmp;
    $mails = new ArrayObject();
    $config = config_completer(array_merge([
        'jeton_installation' => str_repeat('j', 24),
        'base' => $tmp . '/data/licenses.db',
        'dossier_sauvegardes' => $tmp . '/data/sauvegardes',
        'cles' => $tmp . '/prive/cles',
        'htpasswd' => $tmp . '/prive/.htpasswd',
        'verrou' => $tmp . '/prive/install.verrou',
        'mailer' => static function ($a, $sujet, $corps, $entetes) use ($mails): bool {
            $mails[] = ['a' => $a, 'sujet' => $sujet, 'corps' => $corps, 'entetes' => $entetes];
            return true;
        },
    ], $surcharges), realpath(RACINE . '/www/prive'));
    return ['tmp' => $tmp, 'config' => $config, 'mails' => $mails];
}

function parametres_installation(array $surcharges = []): array
{
    return array_merge([
        'utilisateur' => 'admin',
        'mot_de_passe' => 'un mot de passe tres long 2026',
        'confirmation' => 'un mot de passe tres long 2026',
        'url' => 'https://licence.exemple.fr/api/v1/',
        'url_secours' => 'https://licence2.exemple.fr/api/v1/',
        'email_notification' => 'admin@exemple.fr',
    ], $surcharges);
}

/** Serveur installe avec un produit APP et ses distributions. */
function serveur(array $surcharges = []): array
{
    $env = environnement($surcharges);
    $env['installation'] = installation_executer($env['config'], parametres_installation(), $env['tmp'] . '/www/admin', T0);
    $db = db_ouvrir($env['config']['base']);
    $app = db_inserer($db, 'produits', ['code' => 'APP', 'nom' => 'Application test', 'cree_le' => T0]);
    $autre = db_inserer($db, 'produits', ['code' => 'AUTRE', 'nom' => 'Autre', 'cree_le' => T0]);
    $inactif = db_inserer($db, 'produits', ['code' => 'INACTIF', 'nom' => 'Inactif', 'actif' => 0, 'cree_le' => T0]);
    $env['dist'] = [
        'APP-A' => db_inserer($db, 'distributions', ['produit_id' => $app, 'code' => 'APP-A', 'libelle' => 'Client A',
            'options' => '["export_pdf"]', 'message' => 'Bienvenue', 'duree_defaut_j' => 365, 'cree_le' => T0]),
        'APP-B' => db_inserer($db, 'distributions', ['produit_id' => $app, 'code' => 'APP-B', 'libelle' => 'Public',
            'options' => '["multi_navire"]', 'cree_le' => T0]),
        'APP-OFF' => db_inserer($db, 'distributions', ['produit_id' => $app, 'code' => 'APP-OFF', 'libelle' => 'Off',
            'actif' => 0, 'cree_le' => T0]),
        'AUTRE-A' => db_inserer($db, 'distributions', ['produit_id' => $autre, 'code' => 'AUTRE-A', 'libelle' => 'A',
            'cree_le' => T0]),
        'INACTIF-A' => db_inserer($db, 'distributions', ['produit_id' => $inactif, 'code' => 'INACTIF-A',
            'libelle' => 'A', 'cree_le' => T0]),
    ];
    $env['db'] = $db;
    $env['publique'] = $env['installation']['cle_publique'];
    return $env;
}

function creer_licence(array $env, string $distribution = 'APP-A', ?int $jours = 365, array $champs = []): string
{
    $cle = cle_generer();
    db_inserer($env['db'], 'licences', array_merge([
        'distribution_id' => $env['dist'][$distribution], 'cle_hash' => cle_hash($cle), 'cle_indice' => substr($cle, -4),
        'titulaire' => 'Armement Test', 'echeance' => $jours === null ? null : T0 + $jours * JOUR,
        'statut' => 'active', 'origine' => 'console', 'cree_le' => T0, 'modifie_le' => T0,
    ], $champs));
    return $cle;
}

function licence(array $env, string $cle): array
{
    return db_ligne($env['db'], 'SELECT * FROM licences WHERE cle_hash = ?', [cle_hash($cle)]);
}

function jeton_demande(): string
{
    return b64url(random_bytes(32));
}

/** Appel de l'API ; renvoie code HTTP, payload decode et validite de la signature. */
function appel(array $env, string $op, array $champs = [], array $options = []): array
{
    $requete = array_merge(['v' => 1, 'op' => $op, 'produit' => 'APP', 'distribution' => 'APP-A',
        'machine' => MACHINE_A, 'poste' => 'PC-PASSERELLE', 'version' => '1.4.0',
        'nonce' => b64url(random_bytes(16)), 't' => $options['t'] ?? ($options['maintenant'] ?? T0)], $champs);
    $ip = $options['ip'] ?? ('10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254));
    $corps = $options['brut'] ?? json_encode($requete);
    [$code, $enveloppe] = api_traiter($env['db'], $env['config'], $corps, $ip, $options['maintenant'] ?? T0);
    $octets = deb64url($enveloppe['payload']);
    return [
        'http' => $code,
        'p' => json_decode($octets, true),
        'sig' => sodium_crypto_sign_verify_detached(deb64url($enveloppe['sig']), $octets, deb64url($env['publique'])),
        'kid' => $enveloppe['kid'],
        'requete' => $requete,
    ];
}

// ---------------------------------------------------------------------------

function test_fichiers(): void
{
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE, FilesystemIterator::SKIP_DOTS));
    $php = 0;
    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }
        $php++;
        $contenu = file_get_contents($fichier->getPathname());
        $nom = basename($fichier->getPathname());
        check('ASCII pur : ' . $nom, preg_match('/[^\x00-\x7F]/', $contenu) === 0);
        check('en-tete ETDEL (c) 2026 : ' . $nom, strpos(substr($contenu, 0, 600), 'ETDEL (c) 2026') !== false);
        // config.php : copie locale de config.exemple.php (banc d'essai), jamais versionnee.
        check('strict_types : ' . $nom, in_array($nom, ['config.exemple.php', 'config.php'], true)
            || strpos($contenu, 'declare(strict_types=1);') !== false);
    }
    check('fichiers PHP trouves', $php >= 10);
    $prive = file_get_contents(RACINE . '/www/prive/.htaccess');
    check('prive/.htaccess : Require all denied', strpos($prive, 'Require all denied') !== false);
    $admin = file_get_contents(RACINE . '/www/admin/.htaccess');
    check('admin/.htaccess du depot : console fermee avant installation', strpos($admin, 'Require all denied') !== false);
    $www = file_get_contents(RACINE . '/www/.htaccess');
    check('www/.htaccess : pas de listing', strpos($www, 'Options -Indexes') !== false);
    check('www/.htaccess : HTTPS force', strpos($www, 'RewriteRule ^ https://') !== false);
    check('www/.htaccess : Referrer-Policy identique a la console', strpos($www, 'Referrer-Policy "same-origin"') !== false);
    check('www/.htaccess : HSTS, CSP, X-Frame-Options', strpos($www, 'Strict-Transport-Security') !== false
        && strpos($www, 'Content-Security-Policy') !== false && strpos($www, 'X-Frame-Options "DENY"') !== false);
    check('www/.htaccess : base et cles jamais servies', preg_match('/FilesMatch[^\n]*db[^\n]*key/', $www) === 1);
    check('config.php jamais versionne', strpos(file_get_contents(RACINE . '/../.gitignore'), 'prive/config.php') !== false);
}

function test_formats(): void
{
    check('cle : forme tolerante', cle_normaliser('etdel 7k2m qx9p 4rtb hc34') === 'ETDEL-7K2M-QX9P-4RTB-HC34');
    check('cle : hash identique au client Python',
        cle_hash('ETDEL-7K2M-QX9P-4RTB-HC34') === '7677a2bf4a824de869e5920b58641c33751e58e87413a8d898002e655b4702f4');
    check('cle : O lu 0', cle_normaliser('ETDEL-7K2M-QX9P-4RTB-HC34') === cle_normaliser('ETDEL-7K2M-QX9P-4RTB-HC34'));
    check('cle : somme fausse', cle_normaliser('ETDEL-7K2M-QX9P-4RTB-HC35') === null);
    check('cle : U refuse', cle_normaliser('ETDEL-7K2M-QX9P-4RTB-HCU4') === null);
    check('cle : non chaine', cle_normaliser(42) === null);
    $valides = 0;
    for ($i = 0; $i < 200; $i++) {
        $cle = cle_generer();
        $valides += (cle_normaliser($cle) === $cle && preg_match('/^ETDEL(-[0-9A-HJKMNP-TV-Z]{4}){4}$/', $cle) === 1) ? 1 : 0;
    }
    check('cle_generer : 200 cles valides', $valides === 200);
    check('id_poste identique au client Python (APP)', id_poste(MACHINE_A, 'APP') === 'SHXT-2380');
    check('id_poste identique au client Python (MONAPPLI)', id_poste(MACHINE_A, 'MONAPPLI') === 'Y19N-R4ZR');
    check('versions : 1.10 > 1.9', version_comparer('1.10', '1.9') > 0);
    check('versions : 1.2 == 1.2.0', version_comparer('1.2', '1.2.0') === 0);
    check('versions : 1.2.0 < 1.2.1', version_comparer('1.2.0', '1.2.1') < 0);
    check('versions : suffixe ignore', version_comparer('2.0.0b1', '2.0') === 0);
    check('version min effective', version_min_effective('1.2', '1.10') === '1.10'
        && version_min_effective(null, '') === null && version_min_effective('3.0', '2.0') === '3.0');
    check('base64url aller-retour', deb64url(b64url("\x00\xff\xfe abc")) === "\x00\xff\xfe abc");
    check('base64url : caractere interdit', deb64url('ab$c') === null);
    check('texte borne : trop long refuse', texte_borne(str_repeat('e', 201), 200) === null);
    check('texte borne : UTF-8 compte en caracteres', texte_borne(str_repeat("\u{e9}", 200), 200) !== null);
    check('texte borne : controles retires', texte_borne("PC\r\nBcc: x", 64) === 'PCBcc: x');
    check('options : texte', options_depuis_texte('export_pdf, multi_navire export_pdf') === ['export_pdf', 'multi_navire']);
    check('options : code invalide', options_depuis_texte('Export PDF!') === null);
}

function test_installation(): void
{
    $env = environnement(['jeton_installation' => 'court']);
    check('installation : jeton trop court bloquant', count(installation_prealables($env['config'])) === 1);
    $env = environnement();
    check('installation : prealables ok', installation_prealables($env['config']) === []);
    $erreurs = installation_erreurs(parametres_installation(['mot_de_passe' => 'court', 'confirmation' => 'court',
        'url' => 'http://licence.exemple.fr/api/v1/', 'email_notification' => 'pas une adresse', 'utilisateur' => 'a b']));
    check('installation : 4 erreurs detectees', count($erreurs) === 4);
    check('installation : mots de passe differents',
        installation_erreurs(parametres_installation(['confirmation' => 'autre mot de passe tres long'])) !== []);
    check('installation : http local accepte (banc d\'essai)', url_api_valide('http://127.0.0.1:8080/api/v1/'));
    try {
        installation_executer($env['config'], parametres_installation(['mot_de_passe' => 'court']), $env['tmp'] . '/www/admin', T0);
        check('installation invalide : refusee', false);
    } catch (InvalidArgumentException $e) {
        check('installation invalide : refusee', true);
    }
    check('installation invalide : ni base ni verrou', !is_file($env['config']['base']) && !installation_verrouillee($env['config']));
    $r = installation_executer($env['config'], parametres_installation(), $env['tmp'] . '/www/admin', T0);
    check('installation : cle publique 32 octets', strlen((string)deb64url($r['cle_publique'])) === 32 && $r['kid'] === 1);
    check('installation : base creee', is_file($env['config']['base']));
    check('installation : verrou', installation_verrouillee($env['config']));
    $fichier_cle = $env['config']['cles'] . '/signature_1.key';
    check('installation : cle privee hors www, 0600', droits_0600($fichier_cle));
    check('installation : secret des demandes', strlen(secret_demandes($env['config'])) === 32);
    $htpasswd = file_get_contents($env['config']['htpasswd']);
    check('installation : .htpasswd bcrypt $2y$', preg_match('/^admin:\$2y\$/', $htpasswd) === 1);
    check('installation : mot de passe verifiable',
        password_verify('un mot de passe tres long 2026', substr(trim($htpasswd), strlen('admin:'))));
    $htaccess = file_get_contents($env['tmp'] . '/www/admin/.htaccess');
    check('installation : admin/.htaccess AuthType Basic', strpos($htaccess, 'AuthType Basic') !== false
        && strpos($htaccess, 'Require valid-user') !== false);
    $https_exige = implode("\n", ['<RequireAll>',
        'Require expr "%{SERVER_PORT} != \'80\' || %{HTTP:X-Forwarded-Proto} == \'https\'"',
        'Require valid-user', '</RequireAll>']);
    check('installation : admin/.htaccess exige HTTPS avant l\'authentification',
        strpos($htaccess, $https_exige) !== false);
    check('installation : AuthUserFile absolu', strpos($htaccess, 'AuthUserFile "' . $env['config']['htpasswd'] . '"') !== false
        && chemin_absolu($env['config']['htpasswd']));
    check('installation : dossier de la base protege', is_file(dirname($env['config']['base']) . '/.htaccess'));
    $db = db_ouvrir($env['config']['base']);
    $tables = array_column(db_lignes($db, "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name');
    check('installation : tables de l\'annexe B', $tables === ['cles_signature', 'demandes', 'distributions', 'journal',
        'licences', 'limites', 'produits', 'reglages', 'urls_serveur']);
    check('installation : mode WAL', strtolower((string)db_valeur($db, 'PRAGMA journal_mode')) === 'wal');
    check('installation : URL diffusees', api_urls($db) === ['https://licence.exemple.fr/api/v1/', 'https://licence2.exemple.fr/api/v1/']);
    check('installation : adresse de notification', reglage_lire($db, 'email_notification') === 'admin@exemple.fr');
    check('installation : expediteur sur le domaine', reglage_lire($db, 'email_expediteur') === 'licences@licence.exemple.fr');
    check('installation : journalisee', db_valeur($db, "SELECT acteur FROM journal WHERE action = 'installation'") === 'admin');
    try {
        installation_executer($env['config'], parametres_installation(), $env['tmp'] . '/www/admin', T0 + 10);
        check('seconde installation : refusee', false);
    } catch (RuntimeException $e) {
        check('seconde installation : refusee', strpos($e->getMessage(), 'deja') !== false);
    }
    check('reinitialisation : fermee par defaut', !reinitialisation_ouverte($env['config']));
    $config = $env['config'];
    $config['jeton_reinitialisation'] = $config['jeton_installation'];
    check('reinitialisation : jeton d\'installation refuse', !reinitialisation_ouverte($config));
    $config['jeton_reinitialisation'] = 'jeton-de-reinitialisation-0001';
    $config['prive'] = $env['tmp'] . '/prive';
    check('reinitialisation : ouverte par config.php', reinitialisation_ouverte($config));
    try {
        reinitialisation_executer($config, 'mauvais', 'admin', 'mot de passe retrouve 2026', 'mot de passe retrouve 2026', '', T0);
        check('reinitialisation : mauvais jeton refuse', false);
    } catch (RuntimeException $e) {
        check('reinitialisation : mauvais jeton refuse', true);
    }
    try {
        reinitialisation_executer($config, 'jeton-de-reinitialisation-0001', 'pirate', 'mot de passe retrouve 2026',
            'mot de passe retrouve 2026', '', T0);
        check('reinitialisation : aucun compte supplementaire', false);
    } catch (InvalidArgumentException $e) {
        check('reinitialisation : aucun compte supplementaire', htpasswd_utilisateurs($config['htpasswd']) === ['admin']);
    }
    reinitialisation_executer($config, 'jeton-de-reinitialisation-0001', 'admin', 'mot de passe retrouve 2026',
        'mot de passe retrouve 2026', '', T0);
    check('reinitialisation : .htpasswd reecrit', password_verify('mot de passe retrouve 2026',
        substr(trim((string)file_get_contents($config['htpasswd'])), strlen('admin:'))));
    check('reinitialisation : jeton a usage unique', !reinitialisation_ouverte($config));
    check('reinitialisation : journalisee', db_valeur(db_ouvrir($config['base']),
        "SELECT COUNT(*) FROM journal WHERE action = 'mot_de_passe_reinitialise'") == 1);
    $config['jeton_reinitialisation'] = 'jeton-de-reinitialisation-0002';
    check('reinitialisation : nouveau jeton, nouvelle possibilite', reinitialisation_ouverte($config));
    htpasswd_ecrire($env['config']['htpasswd'], 'admin', 'nouveau mot de passe bien plus long');
    $lignes = file($env['config']['htpasswd'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    check('changement de mot de passe : une seule ligne', count($lignes) === 1
        && password_verify('nouveau mot de passe bien plus long', substr($lignes[0], strlen('admin:'))));
}

function test_ping_et_requetes_invalides(): void
{
    $env = serveur();
    $r = appel($env, 'ping');
    check('ping : ok signe', $r['http'] === 200 && $r['sig'] && $r['kid'] === 1 && $r['p']['ok'] === true);
    check('ping : nonce renvoye', $r['p']['nonce'] === $r['requete']['nonce'] && $r['p']['machine'] === MACHINE_A);
    check('ping : version api', $r['p']['version_api'] === 1 && $r['p']['emis'] === T0);
    $r = appel($env, 'ping', [], ['brut' => 'pas du json']);
    check('json invalide : 400 signe requete_invalide', $r['http'] === 400 && $r['sig'] && $r['p']['code'] === 'requete_invalide');
    $cas = [
        'op inconnue' => ['op' => 'supprimer'],
        'version de protocole' => ['v' => 2],
        'machine non hexadecimale' => ['machine' => str_repeat('z', 64)],
        'nonce trop court' => ['nonce' => 'abc'],
        't non entier' => ['t' => '1791200000'],
        'poste trop long' => ['poste' => str_repeat('p', 65)],
        'produit avec espace' => ['produit' => 'A B'],
    ];
    foreach ($cas as $nom => $champs) {
        $r = appel($env, 'ping', $champs);
        check('requete invalide (' . $nom . ')', $r['http'] === 400 && $r['p']['ok'] === false && $r['p']['code'] === 'requete_invalide');
    }
    $jeton = jeton_demande();
    foreach ([
        'titulaire vide' => ['titulaire' => '  ', 'email' => '', 'message' => '', 'jeton' => $jeton],
        'message de 201 caracteres' => ['titulaire' => 'X', 'email' => '', 'message' => str_repeat('m', 201), 'jeton' => $jeton],
        'jeton mal forme' => ['titulaire' => 'X', 'email' => '', 'message' => '', 'jeton' => 'abc'],
    ] as $nom => $champs) {
        check('demande invalide (' . $nom . ')', appel($env, 'demander', $champs)['p']['code'] === 'requete_invalide');
    }
    check('suivre sans numero : invalide', appel($env, 'suivre_demande', ['jeton' => $jeton])['p']['code'] === 'requete_invalide');
    check('horloge : ecart de 601 s refuse', appel($env, 'ping', [], ['t' => T0 - 601])['p']['code'] === 'horloge');
    check('horloge : ecart de 600 s accepte', appel($env, 'ping', [], ['t' => T0 + 600])['p']['ok'] === true);
    foreach (['distribution inconnue' => ['distribution' => 'NUL'], 'produit different' => ['produit' => 'AUTRE'],
              'distribution inactive' => ['distribution' => 'APP-OFF'],
              'produit inactif' => ['produit' => 'INACTIF', 'distribution' => 'INACTIF-A']] as $nom => $champs) {
        $r = appel($env, 'valider', $champs + ['cle' => creer_licence($env)]);
        check('produit_inconnu (' . $nom . ')', $r['p']['code'] === 'produit_inconnu' && $r['sig']);
    }
}

function test_activer_valider(): void
{
    $env = serveur();
    check('activer : format invalide', appel($env, 'activer', ['cle' => 'ETDEL-1234'])['p']['code'] === 'cle_invalide');
    check('activer : cle inconnue', appel($env, 'activer', ['cle' => cle_generer()])['p']['code'] === 'cle_invalide');
    check('activer : cle d\'une autre distribution',
        appel($env, 'activer', ['cle' => creer_licence($env, 'APP-B')])['p']['code'] === 'cle_invalide');
    $cle = creer_licence($env);
    $r = appel($env, 'activer', ['cle' => strtolower(str_replace('-', '', $cle))], ['ip' => '192.0.2.7']);
    $p = $r['p'];
    check('activer : ok et signe', $r['http'] === 200 && $r['sig'] && $p['ok'] === true && $p['code'] === null);
    check('activer : champs d\'echo', $p['v'] === 1 && $p['nonce'] === $r['requete']['nonce'] && $p['produit'] === 'APP'
        && $p['distribution'] === 'APP-A' && $p['machine'] === MACHINE_A);
    check('activer : id_poste', $p['id_poste'] === 'SHXT-2380');
    check('activer : echeance et jours', $p['echeance'] === T0 + 365 * JOUR && $p['jours_restants'] === 365);
    check('activer : tolerance par defaut 15 j', $p['emis'] === T0 && $p['hors_ligne_jusqu'] === T0 + 15 * JOUR && $p['preavis_j'] === 5);
    check('activer : options, message, version', $p['options'] === ['export_pdf'] && $p['message'] === 'Bienvenue'
        && $p['version_min'] === null && $p['titulaire'] === 'Armement Test');
    check('activer : urls et bulletins', $p['urls'] === ['https://licence.exemple.fr/api/v1/', 'https://licence2.exemple.fr/api/v1/']
        && $p['bulletins'] === []);
    $lic = licence($env, $cle);
    check('activer : poste lie', $lic['machine'] === MACHINE_A && $lic['id_poste'] === 'SHXT-2380' && (int)$lic['lie_le'] === T0);
    check('activer : contact enregistre', (int)$lic['dernier_contact'] === T0 && $lic['nom_ordinateur'] === 'PC-PASSERELLE'
        && $lic['version_appli'] === '1.4.0' && $lic['derniere_ip'] === '192.0.2.7');
    check('activer : journal', db_valeur($env['db'], "SELECT COUNT(*) FROM journal WHERE action = 'activation'") == 1);
    check('activer : cle jamais en clair en base', strpos(file_get_contents($env['config']['base']) . (is_file($env['config']['base'] . '-wal')
        ? file_get_contents($env['config']['base'] . '-wal') : ''), substr($cle, 6)) === false);
    check('activer : deuxieme fois sur le meme poste', appel($env, 'activer', ['cle' => $cle])['p']['ok'] === true);
    $r = appel($env, 'activer', ['cle' => $cle, 'machine' => MACHINE_B, 'poste' => 'PC-NEUF']);
    check('activer sur un autre poste : cle_liee_autre_poste', $r['p']['code'] === 'cle_liee_autre_poste');
    check('autre poste : journalise', db_valeur($env['db'], "SELECT COUNT(*) FROM journal WHERE action = 'refus_autre_poste'") == 1);
    $r = appel($env, 'valider', ['cle' => $cle, 'poste' => 'PC-RENOMME', 'version' => '1.5.0'], ['maintenant' => T0 + 3600]);
    check('valider : ok', $r['p']['ok'] === true && $r['p']['hors_ligne_jusqu'] === T0 + 3600 + 15 * JOUR);
    $lic = licence($env, $cle);
    check('renommage : nouveau nom affiche', $lic['nom_ordinateur'] === 'PC-RENOMME' && $lic['version_appli'] === '1.5.0');
    check('valider : autre poste refuse', appel($env, 'valider', ['cle' => $cle, 'machine' => MACHINE_B])['p']['code'] === 'cle_liee_autre_poste');
    db_modifier($env['db'], 'UPDATE licences SET machine = NULL, id_poste = NULL WHERE cle_hash = ?', [cle_hash($cle)]);
    check('poste libere : valider poste_revoque', appel($env, 'valider', ['cle' => $cle])['p']['code'] === 'poste_revoque');
    check('poste libere : activation sur le nouveau poste',
        appel($env, 'activer', ['cle' => $cle, 'machine' => MACHINE_B])['p']['ok'] === true);
    check('poste libere : ancien poste refuse', appel($env, 'valider', ['cle' => $cle])['p']['code'] === 'cle_liee_autre_poste');
    $perpetuelle = creer_licence($env, 'APP-A', null);
    $p = appel($env, 'activer', ['cle' => $perpetuelle, 'machine' => MACHINE_C])['p'];
    check('perpetuelle : echeance null', $p['ok'] === true && $p['echeance'] === null && $p['jours_restants'] === null);
}

function test_statuts_versions_surcharges(): void
{
    $env = serveur();
    foreach (['suspendue' => 'suspendue', 'revoquee' => 'revoquee'] as $statut => $code) {
        $cle = creer_licence($env, 'APP-A', 365, ['statut' => $statut, 'machine' => MACHINE_A]);
        check('licence ' . $statut, appel($env, 'valider', ['cle' => $cle])['p']['code'] === $code);
    }
    $cle = creer_licence($env, 'APP-A', 10, ['machine' => MACHINE_A]);
    check('echeance non atteinte : 10 jours', appel($env, 'valider', ['cle' => $cle])['p']['jours_restants'] === 10);
    check('echeance atteinte : expiree', appel($env, 'valider', ['cle' => $cle], ['maintenant' => T0 + 10 * JOUR])['p']['code'] === 'expiree');
    $neuve = creer_licence($env, 'APP-A', 0);
    check('cle expiree : non liee a l\'activation', appel($env, 'activer', ['cle' => $neuve])['p']['code'] === 'expiree'
        && licence($env, $neuve)['machine'] === null);
    db_modifier($env['db'], "UPDATE distributions SET version_min = '2.0' WHERE code = 'APP-A'");
    $cle = creer_licence($env);
    check('version trop ancienne', appel($env, 'activer', ['cle' => $cle])['p']['code'] === 'version_trop_ancienne');
    check('version trop ancienne : pas de liaison', licence($env, $cle)['machine'] === null);
    $p = appel($env, 'activer', ['cle' => $cle, 'version' => '2.0.0'])['p'];
    check('version a jour : ok et version_min diffusee', $p['ok'] === true && $p['version_min'] === '2.0');
    db_modifier($env['db'], "UPDATE produits SET version_min = '3.1' WHERE code = 'APP'");
    $p = appel($env, 'valider', ['cle' => $cle, 'version' => '3.0'])['p'];
    check('version min du produit plus exigeante', $p['code'] === 'version_trop_ancienne');
    db_modifier($env['db'], "UPDATE produits SET version_min = NULL");
    db_modifier($env['db'], "UPDATE distributions SET version_min = NULL");
    $cle = creer_licence($env, 'APP-A', 365, ['tolerance_j' => 30, 'options' => '["special"]']);
    $p = appel($env, 'activer', ['cle' => $cle, 'machine' => MACHINE_B])['p'];
    check('surcharge par licence : tolerance 30 j', $p['hors_ligne_jusqu'] === T0 + 30 * JOUR);
    check('surcharge par licence : options', $p['options'] === ['special']);
    $cle = creer_licence($env, 'APP-A', 365, ['tolerance_j' => 0]);
    $p = appel($env, 'activer', ['cle' => $cle, 'machine' => MACHINE_C])['p'];
    check('tolerance 0 : un cycle de controle plus une heure', $p['hors_ligne_jusqu'] === T0 + 7 * 3600);
    db_modifier($env['db'], 'UPDATE urls_serveur SET actif = 0 WHERE priorite = 10');
    db_inserer($env['db'], 'urls_serveur', ['url' => 'https://licence3.exemple.fr/api/v1/', 'priorite' => 5]);
    check('urls : actives, par priorite', appel($env, 'ping')['p']['urls']
        === ['https://licence3.exemple.fr/api/v1/', 'https://licence2.exemple.fr/api/v1/']);
}

function test_demandes(): void
{
    $env = serveur();
    $jeton = jeton_demande();
    $message = "Bonjour,\nlicence pour la passerelle";
    $r = appel($env, 'demander', ['titulaire' => 'Armement Durand', 'email' => 'durand@exemple.fr', 'message' => $message,
        'jeton' => $jeton], ['ip' => '198.51.100.9']);
    $p = $r['p'];
    check('demander : ok signe', $r['sig'] && $p['ok'] === true && $p['demande'] === 1 && $p['statut'] === 'en_attente');
    check('demander : essai 15 j', $p['essai_jusqu'] === T0 + 15 * JOUR);
    check('demander : options de la distribution', $p['options'] === ['export_pdf']);
    $d = db_ligne($env['db'], 'SELECT * FROM demandes WHERE id = 1');
    check('demande enregistree', $d['id_poste'] === 'SHXT-2380' && $d['nom_ordinateur'] === 'PC-PASSERELLE'
        && $d['titulaire'] === 'Armement Durand' && $d['message'] === $message && $d['ip'] === '198.51.100.9');
    check('demande : seul le SHA-256 du jeton', $d['jeton_hash'] === hash('sha256', $jeton) && strpos(json_encode($d), $jeton) === false);
    check('notification : un e-mail', count($env['mails']) === 1);
    $mail = $env['mails'][0];
    check('notification : destinataire', $mail['a'] === 'admin@exemple.fr');
    check('notification : objet', $mail['sujet'] === '[ETDEL Licences] Nouvelle demande - APP - PC-PASSERELLE');
    check('notification : expediteur sur le domaine', strpos($mail['entetes'], 'From: ETDEL Licences <licences@licence.exemple.fr>') !== false);
    check('notification : texte brut UTF-8', strpos($mail['entetes'], 'Content-Type: text/plain; charset=UTF-8') !== false);
    foreach (['APP', 'APP-A', 'PC-PASSERELLE', 'SHXT-2380', 'Armement Durand', 'durand@exemple.fr', 'licence pour la passerelle',
              'Essai accorde  : oui', '198.51.100.9', 'https://licence.exemple.fr/admin/index.php?page=demande&id=1'] as $attendu) {
        check('notification : corps contient ' . $attendu, strpos($mail['corps'], $attendu) !== false);
    }
    check('notification : aucune cle', strpos($mail['corps'], 'ETDEL-') === false);
    check('notification : journalisee', db_valeur($env['db'], "SELECT COUNT(*) FROM journal WHERE action = 'email_envoye'") == 1);
    $r = appel($env, 'demander', ['titulaire' => 'Autre', 'email' => '', 'message' => '', 'jeton' => jeton_demande()]);
    check('seconde demande en attente : demande_en_cours', $r['p']['code'] === 'demande_en_cours');
    $r = appel($env, 'demander', ['titulaire' => 'Armement Durand', 'email' => '', 'message' => '', 'jeton' => $jeton]);
    check('renvoi avec le meme jeton : meme demande', $r['p']['ok'] === true && $r['p']['demande'] === 1
        && (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM demandes') === 1 && count($env['mails']) === 1);
    $r = appel($env, 'demander', ['distribution' => 'APP-B', 'titulaire' => 'X', 'email' => '', 'message' => '', 'jeton' => jeton_demande()]);
    check('autre distribution du meme produit : acceptee sans essai', $r['p']['ok'] === true && $r['p']['essai_jusqu'] === null);
    $r = appel($env, 'demander', ['machine' => MACHINE_B, 'titulaire' => 'X', 'email' => '', 'message' => '', 'jeton' => jeton_demande()]);
    check('autre poste : essai accorde', $r['p']['essai_jusqu'] === T0 + 15 * JOUR);

    // Suivi.
    $suivre = static function (int $numero, string $jeton, array $champs = [], int $maintenant = T0) use ($env): array {
        return appel($env, 'suivre_demande', $champs + ['demande' => $numero, 'jeton' => $jeton], ['maintenant' => $maintenant])['p'];
    };
    check('suivre : mauvais jeton', $suivre(1, jeton_demande())['code'] === 'demande_inconnue');
    check('suivre : autre poste', $suivre(1, $jeton, ['machine' => MACHINE_B])['code'] === 'demande_inconnue');
    check('suivre : numero inconnu', $suivre(99, $jeton)['code'] === 'demande_inconnue');
    check('suivre : autre distribution', $suivre(1, $jeton, ['distribution' => 'APP-B'])['code'] === 'demande_inconnue');
    $p = $suivre(1, $jeton);
    check('suivre : en attente avec essai', $p['statut'] === 'en_attente' && $p['essai_jusqu'] === T0 + 15 * JOUR);
    $resultat = demande_accepter($env['db'], $env['config'], 1, 90, 'Armement Durand SA', null, 'admin', '192.0.2.1', T0 + 3600);
    $lic = db_ligne($env['db'], 'SELECT * FROM licences WHERE id = ?', [$resultat['licence_id']]);
    check('acceptation : cle liee au poste demandeur', $lic['machine'] === MACHINE_A && $lic['origine'] === 'demande'
        && $lic['id_poste'] === 'SHXT-2380' && $lic['titulaire'] === 'Armement Durand SA');
    check('acceptation : echeance 90 j', (int)$lic['echeance'] === T0 + 3600 + 90 * JOUR && $lic['options'] === null);
    check('acceptation : cle conservee chiffree', strpos((string)db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 1'),
        substr($resultat['cle'], 6)) === false);
    check('acceptation : journal', db_valeur($env['db'], "SELECT acteur FROM journal WHERE action = 'demande_acceptee'") === 'admin');
    $p = $suivre(1, $jeton, [], T0 + 7200);
    check('suivre : acceptee avec cle et jeton', $p['ok'] === true && $p['statut'] === 'acceptee' && $p['cle'] === $resultat['cle']);
    check('suivre : 90 jours restants', $p['jours_restants'] === 90 && $p['hors_ligne_jusqu'] === T0 + 7200 + 15 * JOUR);
    check('suivre : la cle reste conservee avant valider', db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 1') !== null);
    check('valider la cle recue', appel($env, 'valider', ['cle' => $resultat['cle']])['p']['ok'] === true);
    check('premier valider : cle effacee du serveur', db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 1') === null);
    check('suivre apres effacement : demande_inconnue', $suivre(1, $jeton)['code'] === 'demande_inconnue');
    try {
        demande_accepter($env['db'], $env['config'], 1, 90, 'X', null, 'admin', '', T0);
        check('accepter deux fois : refuse', false);
    } catch (RuntimeException $e) {
        check('accepter deux fois : refuse', true);
    }
    // Refus.
    $motif = "Dossier incomplet : pi\u{e8}ce manquante";
    demande_refuser($env['db'], 2, $motif, 'admin', '', T0);
    $p = appel($env, 'suivre_demande', ['distribution' => 'APP-B', 'demande' => 2, 'jeton' => 'x'])['p'];
    check('suivre une demande refusee avec un faux jeton', ($p['code'] ?? '') === 'requete_invalide');
    try {
        demande_refuser($env['db'], 3, str_repeat('m', 501), 'admin', '', T0);
        check('motif de 501 caracteres refuse', false);
    } catch (InvalidArgumentException $e) {
        check('motif de 501 caracteres refuse', true);
    }
    demande_refuser($env['db'], 3, '', 'admin', '', T0);
    check('refus sans motif : motif null', db_valeur($env['db'], 'SELECT motif_refus FROM demandes WHERE id = 3') === null);
    try {
        demande_refuser($env['db'], 3, '', 'admin', '', T0);
        check('refuser deux fois : refuse', false);
    } catch (RuntimeException $e) {
        check('refuser deux fois : refuse', true);
    }
    // Refus suivi de bout en bout, avec motif accentue.
    $jeton4 = jeton_demande();
    $p = appel($env, 'demander', ['machine' => MACHINE_C, 'titulaire' => 'Z', 'email' => '', 'message' => '', 'jeton' => $jeton4])['p'];
    demande_refuser($env['db'], $p['demande'], $motif, 'admin', '', T0);
    $p = appel($env, 'suivre_demande', ['machine' => MACHINE_C, 'demande' => $p['demande'], 'jeton' => $jeton4])['p'];
    check('suivre : refusee avec motif UTF-8 tel quel', $p['statut'] === 'refusee' && $p['motif'] === $motif);
    $p = appel($env, 'demander', ['machine' => MACHINE_C, 'titulaire' => 'Z', 'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p'];
    check('nouvelle demande apres refus : sans essai', $p['ok'] === true && $p['essai_jusqu'] === null);
    $p = appel($env, 'suivre_demande', ['machine' => MACHINE_C, 'demande' => $p['demande'], 'jeton' => $jeton4])['p'];
    check('ancien jeton sur la nouvelle demande : refuse', $p['code'] === 'demande_inconnue');
    // Options modifiees a l'acceptation.
    $jeton5 = jeton_demande();
    $n = appel($env, 'demander', ['machine' => hash('sha256', 'poste D'), 'titulaire' => 'D', 'email' => '', 'message' => '',
        'jeton' => $jeton5])['p']['demande'];
    $acc = demande_accepter($env['db'], $env['config'], $n, null, 'D', ['export_pdf', 'multi_navire'], 'admin', '', T0);
    $lic = db_ligne($env['db'], 'SELECT * FROM licences WHERE id = ?', [$acc['licence_id']]);
    check('acceptation : options modifiees et perpetuelle', $lic['options'] === '["export_pdf","multi_navire"]' && $lic['echeance'] === null);
    db_modifier($env['db'], "UPDATE licences SET statut = 'revoquee' WHERE id = ?", [$acc['licence_id']]);
    $p = appel($env, 'suivre_demande', ['machine' => hash('sha256', 'poste D'), 'demande' => $n, 'jeton' => $jeton5])['p'];
    check('acceptee puis revoquee avant recuperation : revoquee', $p['code'] === 'revoquee');
    db_modifier($env['db'], "UPDATE distributions SET version_min = '9' WHERE code = 'APP-A'");
    $p = appel($env, 'demander', ['machine' => hash('sha256', 'poste E'), 'titulaire' => 'E', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()])['p'];
    check('demande depuis une version trop ancienne : refusee', $p['code'] === 'version_trop_ancienne');
    db_modifier($env['db'], "UPDATE distributions SET essai_j = 0, version_min = NULL WHERE code = 'APP-A'");
    $p = appel($env, 'demander', ['machine' => hash('sha256', 'poste F'), 'titulaire' => 'F', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()])['p'];
    check('distribution sans essai (0 j) : aucun essai', $p['ok'] === true && $p['essai_jusqu'] === null);
}

function test_notifications(): void
{
    $env = serveur();
    reglage_ecrire($env['db'], 'email_notification', '');
    $demander = static function (array $env, string $machine, array $champs = []): array {
        return appel($env, 'demander', $champs + ['machine' => hash('sha256', $machine), 'titulaire' => 'T', 'email' => '',
            'message' => '', 'jeton' => jeton_demande()])['p'];
    };
    check('sans adresse : demande enregistree, aucun e-mail', $demander($env, 'm1')['ok'] === true && count($env['mails']) === 0);
    reglage_ecrire($env['db'], 'email_notification', 'a@exemple.fr, pas-une-adresse, b@exemple.fr');
    $demander($env, 'm2', ['poste' => "Pont-\u{e9}tage\r\nBcc: pirate@exemple.com"]);
    $mail = $env['mails'][0];
    check('plusieurs adresses (invalides ignorees)', $mail['a'] === 'a@exemple.fr, b@exemple.fr');
    check('objet UTF-8 encode', strpos($mail['sujet'], '=?UTF-8?B?') === 0
        && strpos(base64_decode(substr($mail['sujet'], 10, -2)), "Pont-\u{e9}tage") !== false);
    check('aucune injection d\'en-tete', strpos($mail['entetes'], 'Bcc') === false && strpos($mail['sujet'], "\n") === false);
    $env2 = serveur(['mailer' => static function (): bool {
        return false;
    }]);
    $p = $demander($env2, 'm3');
    check('echec d\'envoi : demande enregistree', $p['ok'] === true);
    check('echec d\'envoi : journalise', db_valeur($env2['db'], "SELECT COUNT(*) FROM journal WHERE action = 'email_echec'") == 1);
    $env3 = serveur(['mailer' => static function (): bool {
        throw new RuntimeException('serveur smtp absent');
    }]);
    check('exception a l\'envoi : demande enregistree', $demander($env3, 'm4')['ok'] === true);
    check('exception a l\'envoi : journalisee', strpos((string)db_valeur($env3['db'],
        "SELECT detail FROM journal WHERE action = 'email_echec'"), 'smtp absent') !== false);
    $env4 = serveur(['emails_par_jour' => 2]);
    foreach (['p1', 'p2', 'p3'] as $poste) {
        $demander($env4, $poste);
    }
    check('plafond quotidien : 2 e-mails', count($env4['mails']) === 2);
    check('plafond quotidien : journalise', db_valeur($env4['db'], "SELECT COUNT(*) FROM journal WHERE action = 'email_plafond'") == 1);
    check('plafond quotidien : 3 demandes enregistrees', (int)db_valeur($env4['db'], 'SELECT COUNT(*) FROM demandes') === 3);
    $r = notification_envoyer($env['db'], $env['config'], 'Essai', "Corps\n", T0);
    check('e-mail de test', $r['ok'] === true && $env['mails'][count($env['mails']) - 1]['sujet'] === 'Essai');
}

function test_limites(): void
{
    $env = serveur();
    $cle = creer_licence($env);
    $ip = ['ip' => '203.0.113.5'];
    $codes = [];
    for ($i = 0; $i < 31; $i++) {
        $codes[] = appel($env, 'activer', ['cle' => $cle], $ip)['p']['code'];
    }
    check('activer : 30 par heure et par IP', count(array_filter($codes, 'is_null')) === 30 && end($codes) === 'trop_de_requetes');
    check('activer : autre IP non limitee', appel($env, 'activer', ['cle' => $cle], ['ip' => '203.0.113.6'])['p']['ok'] === true);
    check('activer : heure suivante', appel($env, 'activer', ['cle' => $cle], $ip + ['maintenant' => T0 + 3600 - T0 % 3600])['p']['ok'] === true);
    $codes = [];
    for ($i = 0; $i < 121; $i++) {
        $codes[] = appel($env, 'valider', ['cle' => $cle], $ip)['p']['code'];
    }
    check('valider : 120 par heure', count(array_filter($codes, 'is_null')) === 120 && end($codes) === 'trop_de_requetes');
    $codes = [];
    for ($i = 0; $i < 61; $i++) {
        $codes[] = appel($env, 'suivre_demande', ['demande' => 1, 'jeton' => jeton_demande()], $ip)['p']['code'];
    }
    check('suivre_demande : 60 par heure', end($codes) === 'trop_de_requetes' && $codes[59] === 'demande_inconnue');
    $codes = [];
    for ($i = 0; $i < 4; $i++) {
        $codes[] = appel($env, 'demander', ['machine' => hash('sha256', 'lim' . $i), 'titulaire' => 'T', 'email' => '',
            'message' => '', 'jeton' => jeton_demande()], $ip)['p']['code'];
    }
    check('demander : 3 par jour et par IP', $codes === [null, null, null, 'trop_de_requetes']);
    check('demander : jour suivant', appel($env, 'demander', ['machine' => hash('sha256', 'lim9'), 'titulaire' => 'T',
        'email' => '', 'message' => '', 'jeton' => jeton_demande()], $ip + ['maintenant' => T0 + JOUR])['p']['ok'] === true);
    check('ping : non limite', appel($env, 'ping', [], $ip)['p']['ok'] === true);
    $codes = [];
    for ($i = 0; $i < 4; $i++) {
        $codes[] = appel($env, 'demander', ['machine' => hash('sha256', 'v6-' . $i), 'titulaire' => 'T', 'email' => '',
            'message' => '', 'jeton' => jeton_demande()], ['ip' => '2001:db8:aa:bb::' . dechex($i + 1)])['p']['code'];
    }
    check('limites IPv6 : par prefixe /64', $codes === [null, null, null, 'trop_de_requetes']);
    check('ip_limite : IPv6 /64', ip_limite('2001:db8:aa:bb:1:2:3:4') === '2001:db8:aa:bb::/64');
    check('ip_limite : IPv4 et IPv4 notee en IPv6', ip_limite('203.0.113.5') === '203.0.113.5'
        && ip_limite('::ffff:203.0.113.5') === '203.0.113.5');
}

function test_sauvegarde(): void
{
    $env = serveur(['sauvegardes_conservees' => 2]);
    creer_licence($env);
    $fichiers = [];
    foreach ([0, 1, 2] as $jour) {
        $fichiers[] = sauvegarde_creer($env['db'], $env['config'], T0 + $jour * JOUR);
    }
    check('sauvegarde : copies datees', basename($fichiers[0]) === 'licenses-' . gmdate('Ymd-His', T0) . '.db');
    check('sauvegarde : 2 plus recentes conservees', !is_file($fichiers[0]) && is_file($fichiers[1]) && is_file($fichiers[2]));
    $copie = db_connecter($fichiers[2]);
    check('sauvegarde : base lisible et complete', (int)db_valeur($copie, 'SELECT COUNT(*) FROM licences') === 1
        && (int)db_valeur($copie, 'SELECT COUNT(*) FROM cles_signature') === 1);
}

function test_signature(): void
{
    $env = serveur();
    $r = appel($env, 'ping');
    $autre = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    $enveloppe = api_traiter($env['db'], $env['config'], json_encode($r['requete']), '1.1.1.1', T0)[1];
    check('signature : invalide avec une autre cle', !sodium_crypto_sign_verify_detached(deb64url($enveloppe['sig']),
        deb64url($enveloppe['payload']), $autre));
    check('signature : payload JSON sans echappement de /', strpos((string)deb64url($enveloppe['payload']), 'https://licence') !== false);
    unlink($env['config']['cles'] . '/signature_1.key');
    try {
        api_traiter($env['db'], $env['config'], json_encode($r['requete']), '1.1.1.1', T0);
        check('cle privee absente : erreur (503 au point d\'entree)', false);
    } catch (RuntimeException $e) {
        check('cle privee absente : erreur (503 au point d\'entree)', true);
    }
}


// ---------------------------------------------------------------------------
// Console d'administration
// ---------------------------------------------------------------------------

function console(array $env, array &$session, string $methode, array $get = [], array $post = [], array $options = []): array
{
    $ctx = ['db' => $env['db'], 'config' => $env['config'], 'methode' => $methode, 'get' => $get, 'post' => $post,
        'utilisateur' => $options['utilisateur'] ?? 'admin', 'ip' => '192.0.2.50',
        'origine' => array_key_exists('origine', $options) ? $options['origine'] : 'https://licence.exemple.fr',
        'hote' => 'licence.exemple.fr', 'maintenant' => $options['maintenant'] ?? T0];
    return admin_traiter($ctx, $session);
}

function action(array $env, array &$session, string $action, array $champs = [], array $options = []): array
{
    $post = array_map('strval', $champs) + ['action' => $action, 'csrf' => csrf_jeton($session), 'confirme' => '1'];
    return console($env, $session, 'POST', [], $post, $options);
}

function cle_affichee(array $reponse): ?string
{
    return preg_match('/<code id="cle">(ETDEL(-[0-9A-Z]{4}){4})<\/code>/', $reponse['corps'], $m) === 1 ? $m[1] : null;
}

function test_console_acces(): void
{
    $env = serveur();
    $session = [];
    $r = console($env, $session, 'GET', [], [], ['utilisateur' => '']);
    check('console : sans utilisateur .htpasswd, 403', $r['code'] === 403 && strpos($r['corps'], 'Authentification') !== false);
    check('utilisateur : REMOTE_USER', admin_utilisateur(['REMOTE_USER' => 'admin']) === 'admin');
    check('utilisateur : REDIRECT_REMOTE_USER', admin_utilisateur(['REDIRECT_REMOTE_USER' => 'admin']) === 'admin');
    check('utilisateur : nom invalide ignore', admin_utilisateur(['REMOTE_USER' => '<x>']) === '');
    check('utilisateur : PHP_AUTH_USER (en-tete du client) ignore', admin_utilisateur(['PHP_AUTH_USER' => 'admin']) === '');
    $pages = ['tableau', 'demandes', 'licences', 'licence_nouvelle', 'produits', 'produit', 'distribution', 'serveurs',
        'cles', 'journal', 'sauvegarde', 'reglages'];
    foreach ($pages as $page) {
        $r = console($env, $session, 'GET', ['page' => $page]);
        check('console : page ' . $page, $r['code'] === 200 && strpos($r['corps'], '<title>') !== false);
        check('console : en-tetes de securite (' . $page . ')', isset($r['entetes']['Content-Security-Policy'],
            $r['entetes']['X-Frame-Options'], $r['entetes']['Strict-Transport-Security']));
        check('console : mobile (' . $page . ')', strpos($r['corps'], 'name="viewport"') !== false);
        check('console : Referrer-Policy compatible avec le controle Origin (' . $page . ')',
            $r['entetes']['Referrer-Policy'] === 'same-origin');
        check('console : aucune ressource externe (' . $page . ')',
            preg_match('/(src|href)="(https?:)?\/\//', $r['corps']) === 0);
        check('console : pas de script en ligne (' . $page . ')', preg_match('/<script(?![^>]*src=)/', $r['corps']) === 0
            && strpos($r['corps'], 'style="') === false && strpos($r['corps'], 'javascript:') === false);
    }
    check('console : page inconnue 404', console($env, $session, 'GET', ['page' => 'rien'])['code'] === 404);
    $avant = (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM produits');
    $post = ['action' => 'produit_enregistrer', 'code' => 'NOUVEAU', 'nom' => 'N', 'actif' => '1', 'confirme' => '1'];
    check('CSRF : sans jeton, refuse', console($env, $session, 'POST', [], $post)['code'] === 403);
    $post['csrf'] = csrf_jeton($session);
    check('CSRF : mauvaise origine, refusee', console($env, $session, 'POST', [], $post,
        ['origine' => 'https://pirate.exemple.com'])['code'] === 403);
    check('CSRF : origine null, refusee', console($env, $session, 'POST', [], $post, ['origine' => 'null'])['code'] === 403);
    $sans = $post;
    $sans['confirme'] = '0';
    $r = console($env, $session, 'POST', [], $sans);
    check('confirmation : page de confirmation sans ecriture', $r['code'] === 200 && strpos($r['corps'], 'Confirmer') !== false
        && strpos($r['corps'], 'name="confirme" value="1"') !== false && (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM produits') === $avant);
    $r = console($env, $session, 'POST', [], $post, ['origine' => null]);
    check('ecriture confirmee (origine absente) : redirection', $r['code'] === 303 && $r['entetes']['Location'] === 'index.php?page=produits&ok=produit');
    check('ecriture : effectuee', (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM produits') === $avant + 1);
    check('ecriture : journalisee avec l\'utilisateur', db_valeur($env['db'],
        "SELECT acteur FROM journal WHERE action = 'produit_cree'") === 'admin');
    $post['code'] = 'NOUVEAU2';
    check('jeton renouvele apres ecriture : renvoi refuse', console($env, $session, 'POST', [], $post)['code'] === 403);
    check('action inconnue', action($env, $session, 'supprimer_tout')['code'] === 400);
    $r = console($env, $session, 'GET', ['page' => 'tableau', 'ok' => 'produit']);
    check('message apres redirection', strpos($r['corps'], 'Produit enregistre.') !== false);
    $r = console($env, $session, 'GET', ['page' => 'tableau', 'ok' => '<script>']);
    check('message inconnu ignore', strpos($r['corps'], '&lt;script') === false && strpos($r['corps'], '<script>') === false);
}

function test_console_demandes(): void
{
    $env = serveur();
    $session = [];
    $jeton = jeton_demande();
    appel($env, 'demander', ['titulaire' => '<script>alert(1)</script>', 'email' => 'c@exemple.fr',
        'message' => "Mot pour ETDEL : merci", 'jeton' => $jeton]);
    $r = console($env, $session, 'GET', ['page' => 'tableau']);
    check('tableau : 1 demande en attente', strpos($r['corps'], '<strong>1</strong> demande(s) en attente') !== false);
    check('navigation : pastille', strpos($r['corps'], '<span class="pastille">1</span>') !== false);
    $r = console($env, $session, 'GET', ['page' => 'demandes']);
    check('demandes : echappement HTML', strpos($r['corps'], '&lt;script&gt;alert(1)&lt;/script&gt;') !== false
        && strpos($r['corps'], '<script>alert') === false);
    foreach (['SHXT-2380', 'PC-PASSERELLE', 'Mot pour ETDEL : merci', 'c@exemple.fr', date_fr(T0 + 15 * JOUR)] as $attendu) {
        check('demandes : colonne ' . $attendu, strpos($r['corps'], h($attendu)) !== false);
    }
    $r = console($env, $session, 'GET', ['page' => 'demande', 'id' => 1]);
    check('fiche demande : duree pre-remplie (365)', strpos($r['corps'], 'name="duree_j" value="365"') !== false);
    check('fiche demande : options pre-remplies', strpos($r['corps'], 'name="options" value="export_pdf"') !== false);
    check('fiche demande : motif borne a 500', strpos($r['corps'], 'maxlength="500"') !== false);
    $r = action($env, $session, 'demande_accepter', ['id' => 1, 'duree_j' => 90, 'titulaire' => 'Armement Durand',
        'options' => 'export_pdf'], ['maintenant' => T0 + 600]);
    $cle = cle_affichee($r);
    check('acceptation : cle affichee une fois', $r['code'] === 200 && $cle !== null);
    $lic = licence(['db' => $env['db']], (string)$cle);
    check('acceptation : licence liee au poste', $lic['machine'] === MACHINE_A && (int)$lic['echeance'] === T0 + 600 + 90 * JOUR);
    check('acceptation : options de la distribution = pas de surcharge', $lic['options'] === null);
    check('acceptation : journal', db_valeur($env['db'], "SELECT acteur FROM journal WHERE action = 'demande_acceptee'") === 'admin');
    $p = appel($env, 'suivre_demande', ['demande' => 1, 'jeton' => $jeton], ['maintenant' => T0 + 700])['p'];
    check('application : cle recue sans saisie, 90 jours', $p['statut'] === 'acceptee' && $p['cle'] === $cle && $p['jours_restants'] === 90);
    $r = console($env, $session, 'GET', ['page' => 'demandes']);
    check('historique des demandes traitees', strpos($r['corps'], 'licence n. ' . $lic['id']) !== false);
    $r = console($env, $session, 'GET', ['page' => 'demande', 'id' => 1]);
    check('fiche demande traitee : plus de formulaire', strpos($r['corps'], 'demande_accepter') === false);
    $jeton2 = jeton_demande();
    appel($env, 'demander', ['machine' => MACHINE_B, 'titulaire' => 'B', 'email' => '', 'message' => '', 'jeton' => $jeton2]);
    $r = action($env, $session, 'demande_refuser', ['id' => 2, 'motif' => 'Licence reservee aux armateurs']);
    check('refus : redirection', $r['code'] === 303 && strpos($r['entetes']['Location'], 'ok=refusee') !== false);
    $p = appel($env, 'suivre_demande', ['machine' => MACHINE_B, 'demande' => 2, 'jeton' => $jeton2])['p'];
    check('refus : motif renvoye tel quel', $p['statut'] === 'refusee' && $p['motif'] === 'Licence reservee aux armateurs');
    check('refus d\'une demande traitee : erreur', action($env, $session, 'demande_refuser', ['id' => 2])['code'] === 409);
    check('acceptation : duree invalide', action($env, $session, 'demande_accepter', ['id' => 3, 'duree_j' => 'abc',
        'titulaire' => 'X'])['code'] === 400);
}

function test_console_licences(): void
{
    $env = serveur();
    $session = [];
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle']);
    check('nouvelle cle : duree par defaut de la distribution', strpos($r['corps'], 'data-duree="365"') !== false);
    $r = action($env, $session, 'licence_creer', ['distribution_id' => $env['dist']['APP-A'], 'titulaire' => 'Armement Martin',
        'email' => 'martin@exemple.fr', 'note' => 'Navire 1', 'duree_j' => 30]);
    $cle = cle_affichee($r);
    check('creation : cle affichee', $cle !== null && cle_normaliser($cle) === $cle);
    $lic = licence(['db' => $env['db']], (string)$cle);
    $id = (int)$lic['id'];
    check('creation : non liee, 30 jours', $lic['machine'] === null && (int)$lic['echeance'] === T0 + 30 * JOUR
        && $lic['origine'] === 'console');
    check('creation : seul le hash est stocke', $lic['cle_hash'] === cle_hash((string)$cle) && $lic['cle_indice'] === substr($cle, -4));
    check('creation : titulaire obligatoire', action($env, $session, 'licence_creer', ['distribution_id' => $env['dist']['APP-A'],
        'titulaire' => ''])['code'] === 400);
    check('creation : e-mail invalide', action($env, $session, 'licence_creer', ['distribution_id' => $env['dist']['APP-A'],
        'titulaire' => 'X', 'email' => 'pas-une-adresse'])['code'] === 400);
    check('activation de la cle creee', appel($env, 'activer', ['cle' => $cle])['p']['ok'] === true);
    $r = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id]);
    check('fiche licence : poste lie et dernier contact', strpos($r['corps'], 'SHXT-2380') !== false
        && strpos($r['corps'], 'PC-PASSERELLE') !== false);
    check('fiche licence : cle jamais affichee', strpos($r['corps'], substr((string)$cle, 0, 14)) === false);
    $r = console($env, $session, 'GET', ['page' => 'licences', 'q' => 'martin']);
    check('recherche', strpos($r['corps'], 'Armement Martin') !== false);
    $r = console($env, $session, 'GET', ['page' => 'licences', 'q' => 'inexistant_%']);
    check('recherche sans resultat (joker echappe)', strpos($r['corps'], 'Aucune licence.') !== false);
    action($env, $session, 'licence_prolonger', ['id' => $id, 'mode' => '30']);
    check('prolonger +30 j', (int)licence_lire($env['db'], $id)['echeance'] === T0 + 60 * JOUR);
    action($env, $session, 'licence_prolonger', ['id' => $id, 'mode' => '365']);
    check('prolonger +1 an', (int)licence_lire($env['db'], $id)['echeance'] === T0 + 425 * JOUR);
    action($env, $session, 'licence_prolonger', ['id' => $id, 'mode' => 'date', 'date' => '2030-01-31']);
    check('prolonger : date libre (fin de journee)', date('Y-m-d H:i:s', (int)licence_lire($env['db'], $id)['echeance'])
        === '2030-01-31 23:59:59');
    check('prolonger : date passee refusee', action($env, $session, 'licence_prolonger', ['id' => $id, 'mode' => 'date',
        'date' => '2020-01-01'])['code'] === 400);
    check('prolonger : date inexistante refusee (31 fevrier)', action($env, $session, 'licence_prolonger', ['id' => $id,
        'mode' => 'date', 'date' => '2031-02-31'])['code'] === 400
        && date('Y-m-d', (int)licence_lire($env['db'], $id)['echeance']) === '2030-01-31');
    $echue = creer_licence($env, 'APP-A', -10);
    $id_echue = (int)licence(['db' => $env['db']], $echue)['id'];
    action($env, $session, 'licence_prolonger', ['id' => $id_echue, 'mode' => '30']);
    check('prolonger une licence echue : depuis aujourd\'hui', (int)licence_lire($env['db'], $id_echue)['echeance'] === T0 + 30 * JOUR);
    $perpetuelle = (int)licence(['db' => $env['db']], creer_licence($env, 'APP-A', null))['id'];
    check('prolonger une perpetuelle : refuse', action($env, $session, 'licence_prolonger', ['id' => $perpetuelle,
        'mode' => '30'])['code'] === 400);
    action($env, $session, 'licence_modifier', ['id' => $id, 'titulaire' => 'Armement Martin SA', 'email' => '',
        'note' => '', 'tolerance_j' => '30', 'options' => 'export_pdf, special']);
    $lic = licence_lire($env['db'], $id);
    check('modifier : tolerance et options surchargees', (int)$lic['tolerance_j'] === 30
        && $lic['options'] === '["export_pdf","special"]' && $lic['titulaire'] === 'Armement Martin SA');
    $p = appel($env, 'valider', ['cle' => $cle])['p'];
    check('modifier : effet au controle suivant', $p['hors_ligne_jusqu'] === T0 + 30 * JOUR && $p['options'] === ['export_pdf', 'special']);
    action($env, $session, 'licence_modifier', ['id' => $id, 'titulaire' => 'Armement Martin SA', 'tolerance_j' => '',
        'options_distribution' => '1', 'options' => 'ignore']);
    $lic = licence_lire($env['db'], $id);
    check('modifier : retour aux valeurs de la distribution', $lic['tolerance_j'] === null && $lic['options'] === null);
    check('modifier : tolerance hors bornes', action($env, $session, 'licence_modifier', ['id' => $id, 'titulaire' => 'X',
        'tolerance_j' => '366'])['code'] === 400);
    check('modifier : option invalide', action($env, $session, 'licence_modifier', ['id' => $id, 'titulaire' => 'X',
        'options' => 'Export PDF'])['code'] === 400);
    // Joker * (D65) : toutes les options ; il absorbe les autres codes saisis.
    check('options : joker seul', options_depuis_texte('*') === ['*'] && options_depuis_texte('export_pdf, *') === ['*']);
    check('options : joker colle a un code refuse', options_depuis_texte('export_*') === null);
    action($env, $session, 'licence_modifier', ['id' => $id, 'titulaire' => 'Armement Martin SA', 'tolerance_j' => '',
        'options' => '*']);
    check('options : joker enregistre', licence_lire($env['db'], $id)['options'] === '["*"]');
    check('options : joker diffuse dans le jeton signe', appel($env, 'valider', ['cle' => $cle])['p']['options'] === ['*']);
    check('options : joker affiche "toutes"', strpos(console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'],
        'toutes (*) (surcharge)') !== false);
    action($env, $session, 'licence_modifier', ['id' => $id, 'titulaire' => 'Armement Martin SA', 'tolerance_j' => '',
        'options_distribution' => '1', 'options' => '']);
    $fiche = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    check('suspendre : explication (cle conservee, revocation definitive)',
        strpos($fiche, 'sans effacer sa cle') !== false && strpos($fiche, 'Revoquer est definitif') !== false
        && strpos($fiche, 'name="jusqu"') !== false);
    action($env, $session, 'licence_suspendre', ['id' => $id]);
    $p = appel($env, 'valider', ['cle' => $cle])['p'];
    check('suspendre : poste bloque, sans date de fin', $p['code'] === 'suspendue'
        && array_key_exists('suspendue_jusqu', $p) && $p['suspendue_jusqu'] === null);
    check('suspendre : fiche "sans date de fin"', strpos(console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'],
        '(sans date de fin)') !== false);
    action($env, $session, 'licence_reactiver', ['id' => $id]);
    check('reactiver', appel($env, 'valider', ['cle' => $cle])['p']['ok'] === true);
    // Suspension datee (D61) : fin de journee dans le fuseau de config.php, reactivation automatique.
    check('suspendre jusqu\'au : date passee refusee', action($env, $session, 'licence_suspendre', ['id' => $id,
        'jusqu' => '2020-01-01'])['code'] === 400 && licence_lire($env['db'], $id)['statut'] === 'active');
    action($env, $session, 'licence_suspendre', ['id' => $id, 'jusqu' => date('Y-m-d', T0 + 10 * JOUR)]);
    $fin = (int)licence_lire($env['db'], $id)['suspendue_jusqu'];
    check('suspendre jusqu\'au : fin de journee', date('Y-m-d H:i:s', $fin) === date('Y-m-d', T0 + 10 * JOUR) . ' 23:59:59');
    check('suspendre jusqu\'au : journalise', strpos((string)db_valeur($env['db'], "SELECT detail FROM journal "
        . "WHERE action = 'licence_suspendue' ORDER BY id DESC LIMIT 1"), 'jusqu\'au ' . date_fr($fin)) !== false);
    $p = appel($env, 'valider', ['cle' => $cle], ['maintenant' => T0 + 5 * JOUR])['p'];
    check('suspension datee : refus signe avec la date de fin', $p['code'] === 'suspendue' && $p['suspendue_jusqu'] === $fin);
    $r = console($env, $session, 'GET', ['page' => 'licences'], [], ['maintenant' => T0 + 5 * JOUR]);
    check('suspension datee : liste avec la date de fin', strpos($r['corps'], 'jusqu\'au ' . date_fr($fin)) !== false);
    action($env, $session, 'licence_suspendre', ['id' => $id, 'jusqu' => date('Y-m-d', T0 + 20 * JOUR)],
        ['maintenant' => T0 + 5 * JOUR]);
    check('suspension : nouvelle date de fin', date('Y-m-d', (int)licence_lire($env['db'], $id)['suspendue_jusqu'])
        === date('Y-m-d', T0 + 20 * JOUR));
    $p = appel($env, 'valider', ['cle' => $cle], ['maintenant' => T0 + 21 * JOUR])['p'];
    $lic = licence_lire($env['db'], $id);
    check('fin de suspension : licence reactivee d\'elle-meme', $p['ok'] === true && $lic['statut'] === 'active'
        && $lic['suspendue_jusqu'] === null);
    check('fin de suspension : journalisee par le systeme', db_valeur($env['db'], "SELECT acteur FROM journal "
        . "WHERE action = 'licence_reactivee' ORDER BY id DESC LIMIT 1") === 'systeme');
    action($env, $session, 'licence_suspendre', ['id' => $id, 'jusqu' => date('Y-m-d', T0 + 25 * JOUR)],
        ['maintenant' => T0 + 21 * JOUR]);
    console($env, $session, 'GET', ['page' => 'tableau'], [], ['maintenant' => T0 + 26 * JOUR]);
    check('fin de suspension : aussi a l\'ouverture de la console', licence_lire($env['db'], $id)['statut'] === 'active');
    action($env, $session, 'licence_suspendre', ['id' => $id, 'jusqu' => date('Y-m-d', T0 + 40 * JOUR)],
        ['maintenant' => T0 + 26 * JOUR]);
    action($env, $session, 'licence_reactiver', ['id' => $id], ['maintenant' => T0 + 27 * JOUR]);
    $lic = licence_lire($env['db'], $id);
    check('reactiver avant la date : date de fin effacee', $lic['statut'] === 'active' && $lic['suspendue_jusqu'] === null);
    $r = action($env, $session, 'licence_liberer', ['id' => $id]);
    check('liberer le poste', $r['code'] === 303 && licence_lire($env['db'], $id)['machine'] === null);
    check('apres liberation : nouvel ordinateur', appel($env, 'activer', ['cle' => $cle, 'machine' => MACHINE_B])['p']['ok'] === true);
    check('apres liberation : journal', strpos((string)db_valeur($env['db'], "SELECT detail FROM journal WHERE action = 'poste_libere'"),
        'SHXT-2380') !== false);
    action($env, $session, 'licence_revoquer', ['id' => $id]);
    check('revoquer : poste bloque a la connexion suivante',
        appel($env, 'valider', ['cle' => $cle, 'machine' => MACHINE_B])['p']['code'] === 'revoquee');
    check('revocation definitive', action($env, $session, 'licence_reactiver', ['id' => $id])['code'] === 400);
    $r = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id]);
    check('licence revoquee : plus d\'actions', strpos($r['corps'], 'licence_reactiver') === false);
    check('licence inconnue', console($env, $session, 'GET', ['page' => 'licence', 'id' => 999])['code'] === 400);
}

function test_console_produits_serveurs(): void
{
    $env = serveur();
    $session = [];
    action($env, $session, 'produit_enregistrer', ['code' => 'NAVIRE', 'nom' => 'Suivi navire', 'version_min' => '', 'actif' => '1']);
    $produit = (int)db_valeur($env['db'], "SELECT id FROM produits WHERE code = 'NAVIRE'");
    check('produit cree', $produit > 0);
    check('produit : code en double refuse', action($env, $session, 'produit_enregistrer', ['code' => 'NAVIRE',
        'nom' => 'X'])['code'] === 400);
    check('produit : code invalide refuse', action($env, $session, 'produit_enregistrer', ['code' => 'NA VIRE',
        'nom' => 'X'])['code'] === 400);
    $r = action($env, $session, 'distribution_enregistrer', ['produit_id' => $produit, 'code' => 'NAVIRE-DEMO',
        'libelle' => 'Demo', 'client' => 'Salon', 'tolerance_j' => '3', 'preavis_j' => '1', 'duree_defaut_j' => '30',
        'essai_j' => '7', 'version_min' => '', 'options' => 'demo', 'message' => 'Version de demonstration', 'actif' => '1']);
    $dist = (int)db_valeur($env['db'], "SELECT id FROM distributions WHERE code = 'NAVIRE-DEMO'");
    check('distribution creee depuis la console', $r['code'] === 303 && $dist > 0);
    $p = appel($env, 'demander', ['produit' => 'NAVIRE', 'distribution' => 'NAVIRE-DEMO', 'titulaire' => 'Visiteur',
        'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p'];
    check('nouveau produit utilisable sans toucher au code', $p['ok'] === true && $p['essai_jusqu'] === T0 + 7 * JOUR
        && $p['options'] === ['demo']);
    $r = console($env, $session, 'GET', ['page' => 'distribution', 'id' => $dist]);
    check('ligne installer() prete a copier', strpos($r['corps'],
        h('etdel_licence.installer(root, produit="NAVIRE", distribution="NAVIRE-DEMO", version=APP_VERSION)')) !== false);
    check('distribution : tolerance hors bornes', action($env, $session, 'distribution_enregistrer', ['id' => $dist,
        'libelle' => 'Demo', 'tolerance_j' => '400', 'preavis_j' => '1', 'essai_j' => '0'])['code'] === 400);
    action($env, $session, 'distribution_dupliquer', ['id' => $dist, 'code' => 'NAVIRE-CLIENTA']);
    $copie = db_ligne($env['db'], "SELECT * FROM distributions WHERE code = 'NAVIRE-CLIENTA'");
    check('dupliquer une distribution', $copie !== null && $copie['options'] === '["demo"]' && (int)$copie['essai_j'] === 7
        && $copie['libelle'] === 'Demo (copie)');
    check('dupliquer : code existant refuse', action($env, $session, 'distribution_dupliquer', ['id' => $dist,
        'code' => 'NAVIRE-DEMO'])['code'] === 400);
    action($env, $session, 'distribution_enregistrer', ['id' => $dist, 'libelle' => 'Demo', 'tolerance_j' => '3',
        'preavis_j' => '1', 'essai_j' => '7', 'options' => 'demo']);
    check('desactiver une distribution', appel($env, 'demander', ['produit' => 'NAVIRE', 'distribution' => 'NAVIRE-DEMO',
        'machine' => MACHINE_B, 'titulaire' => 'V', 'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p']['code']
        === 'produit_inconnu');
    $r = console($env, $session, 'GET', ['page' => 'produits']);
    check('ecran produits', strpos($r['corps'], 'NAVIRE-CLIENTA') !== false && strpos($r['corps'], 'Suivi navire') !== false);
    check('distribution desactivee : etiquette Inactive', strpos($r['corps'], 'etiquette inactive">Inactive') !== false
        && strpos($r['corps'], '>Revoquee<') === false);
    // Serveurs.
    check('url : http distant refuse', action($env, $session, 'url_ajouter', ['url' => 'http://licence3.exemple.fr/api/v1/',
        'priorite' => 5])['code'] === 400);
    action($env, $session, 'url_ajouter', ['url' => 'https://licence3.exemple.fr/api/v1/', 'priorite' => 5]);
    check('url ajoutee en tete', appel($env, 'ping')['p']['urls'][0] === 'https://licence3.exemple.fr/api/v1/');
    check('url en double refusee', action($env, $session, 'url_ajouter', ['url' => 'https://licence3.exemple.fr/api/v1/',
        'priorite' => 5])['code'] === 400);
    $ids = array_map('intval', array_column(db_lignes($env['db'], 'SELECT id FROM urls_serveur ORDER BY id'), 'id'));
    action($env, $session, 'url_modifier', ['id' => $ids[0], 'priorite' => 10]);
    action($env, $session, 'url_modifier', ['id' => $ids[1], 'priorite' => 20]);
    check('urls desactivees retirees de la liste', appel($env, 'ping')['p']['urls'] === ['https://licence3.exemple.fr/api/v1/']);
    check('derniere url active : non desactivable', action($env, $session, 'url_modifier', ['id' => $ids[2],
        'priorite' => 5])['code'] === 400);
    $r = console($env, $session, 'GET', ['page' => 'serveurs']);
    check('ecran serveurs : suivi de migration', strpos($r['corps'], 'Suivi d\'une migration') !== false);
}

function test_console_reglages_exports(): void
{
    $env = serveur();
    $session = [];
    check('reglages : adresse invalide refusee', action($env, $session, 'reglages_enregistrer',
        ['email_notification' => 'a@exemple.fr, faux', 'email_expediteur' => ''])['code'] === 400);
    action($env, $session, 'reglages_enregistrer', ['email_notification' => 'a@exemple.fr, b@exemple.fr',
        'email_expediteur' => 'licences@licence.exemple.fr']);
    check('reglages enregistres', reglage_lire($env['db'], 'email_notification') === 'a@exemple.fr, b@exemple.fr');
    $r = action($env, $session, 'email_test');
    $mail = $env['mails'][count($env['mails']) - 1];
    check('e-mail de test envoye', strpos($r['corps'], 'E-mail envoye a a@exemple.fr') !== false && $mail['sujet'] === '[ETDEL Licences] E-mail de test'
        && $mail['a'] === 'a@exemple.fr, b@exemple.fr');
    check('mot de passe trop court refuse', action($env, $session, 'mot_de_passe', ['nouveau' => 'court',
        'confirmation' => 'court'])['code'] === 400);
    // Sans JavaScript (confirme = 0) : pas de page de confirmation qui recopierait le mot de passe.
    $r = console($env, $session, 'POST', [], ['action' => 'mot_de_passe', 'csrf' => csrf_jeton($session), 'confirme' => '0',
        'nouveau' => 'un tout nouveau mot de passe 2026', 'confirmation' => 'un tout nouveau mot de passe 2026']);
    check('mot de passe : jamais recopie dans la page', strpos($r['corps'], 'un tout nouveau mot de passe 2026') === false);
    $ligne = trim((string)file_get_contents($env['config']['htpasswd']));
    check('mot de passe change (.htpasswd bcrypt)', password_verify('un tout nouveau mot de passe 2026', substr($ligne, strlen('admin:'))));
    $cle = creer_licence($env, 'APP-A', 365, ['titulaire' => '=HYPERLINK("http://x")']);
    appel($env, 'activer', ['cle' => $cle]);
    $r = console($env, $session, 'GET', ['page' => 'export', 'quoi' => 'licences']);
    check('export licences : CSV UTF-8', $r['entetes']['Content-Type'] === 'text/csv; charset=UTF-8'
        && strpos($r['corps'], "\xEF\xBB\xBF\"id\";\"titulaire\";") === 0);
    check('export : formule neutralisee', strpos($r['corps'], "\"'=HYPERLINK(\"\"http://x\"\")\"") !== false);
    check('export : tabulation et retour chariot en tete neutralises', csv_cellule("\t=1+1") === "\"'\t=1+1\""
        && csv_cellule("\r=1+1") === "\"'\r=1+1\"" && csv_cellule('PC-1') === '"PC-1"');
    check('export : jamais la cle', strpos($r['corps'], substr($cle, 6, 9)) === false);
    $r = console($env, $session, 'GET', ['page' => 'export', 'quoi' => 'demandes']);
    check('export demandes', $r['code'] === 200 && strpos($r['corps'], '"motif_refus"') !== false);
    $r = console($env, $session, 'GET', ['page' => 'export', 'quoi' => 'journal', 'action' => 'activation']);
    check('export journal filtre', substr_count($r['corps'], "\r\n") === 2 && strpos($r['corps'], 'activation') !== false);
    $r = console($env, $session, 'GET', ['page' => 'journal', 'action' => 'mot_de_passe']);
    check('journal filtre', strpos($r['corps'], '1 evenement(s).') !== false);
    $r = console($env, $session, 'GET', ['page' => 'sauvegarde', 'telecharger' => '1']);
    check('telechargement : jamais par GET (aucune copie, rien au journal)', !isset($r['fichier'])
        && strpos($r['corps'], 'name="action" value="sauvegarde_telecharger"') !== false
        && db_valeur($env['db'], "SELECT COUNT(*) FROM journal WHERE action = 'sauvegarde_telechargee'") == 0);
    $jeton = csrf_jeton($session);
    $r = action($env, $session, 'sauvegarde_telecharger');
    check('telecharger une copie de la base', $r['code'] === 200 && is_file($r['fichier'])
        && strncmp((string)file_get_contents($r['fichier'], false, null, 0, 16), 'SQLite format 3', 15) === 0);
    check('telechargement : formulaire de la page toujours valable', csrf_jeton($session) === $jeton);
    @unlink($r['fichier']);
    $reste = $env['config']['dossier_sauvegardes'] . '/telechargement-abandonne.db';
    file_put_contents($reste, 'copie interrompue');
    touch($reste, time() - 7200);
    $r = action($env, $session, 'sauvegarde_telecharger');
    check('copie d\'un telechargement interrompu purgee', !is_file($reste));
    @unlink($r['fichier']);
    check('telechargement journalise', db_valeur($env['db'], "SELECT acteur FROM journal WHERE action = 'sauvegarde_telechargee'") === 'admin');
    $ecritures = array_column(db_lignes($env['db'], "SELECT action FROM journal WHERE acteur = 'admin' "
        . "AND action <> 'installation' ORDER BY id"), 'action');
    check('toutes les ecritures journalisees', $ecritures === ['reglages', 'email_test', 'mot_de_passe', 'sauvegarde_telechargee', 'sauvegarde_telechargee']);
    check('tableau : controle d\'exposition', strpos(console($env, $session, 'GET', [])['corps'], 'id="exposition"') !== false);
}


function verifier_enveloppe(array $enveloppe, string $publique): ?array
{
    $octets = deb64url((string)$enveloppe['payload']);
    if ($octets === null || !sodium_crypto_sign_verify_detached((string)deb64url((string)$enveloppe['sig']), $octets,
            (string)deb64url($publique))) {
        return null;
    }
    return json_decode($octets, true);
}

function test_rotation(): void
{
    $env = serveur();
    $session = [];
    $kid1 = $env['publique'];
    $cle = creer_licence($env);
    appel($env, 'activer', ['cle' => $cle]);
    $r = console($env, $session, 'GET', ['page' => 'cles']);
    check('ecran Cles : cle publique et bouton Copier', strpos($r['corps'], '<code id="cle_publique">' . $kid1 . '</code>') !== false
        && strpos($r['corps'], 'data-copier="cle_publique"') !== false);
    check('ecran Cles : lignes pour etdel_licence.py', strpos($r['corps'], h('LICENCE_CLE_PUBLIQUE = "' . $kid1 . '"')) !== false
        && strpos($r['corps'], h('LICENCE_URL = "https://licence.exemple.fr/api/v1/"')) !== false);
    $r = action($env, $session, 'cle_rotation', [], ['maintenant' => T0 + 100]);
    check('rotation depuis la console', $r['code'] === 303 && strpos($r['entetes']['Location'], 'ok=rotation') !== false);
    check('rotation journalisee', db_valeur($env['db'], "SELECT acteur FROM journal WHERE action = 'cle_rotation'") === 'admin');
    $active = signature_active($env['db'], $env['config']);
    check('rotation : kid 2 actif', $active['kid'] === 2 && $active['cle_publique'] !== $kid1);
    check('rotation : kid 1 retire', (int)db_valeur($env['db'], 'SELECT retiree_le FROM cles_signature WHERE kid = 1') === T0 + 100);
    check('rotation : ancienne cle privee effacee', !is_file($env['config']['cles'] . '/signature_1.key'));
    $fichier = $env['config']['cles'] . '/signature_2.key';
    check('rotation : nouvelle cle privee 0600', droits_0600($fichier));
    $bulletin = json_decode((string)db_valeur($env['db'], 'SELECT bulletin FROM cles_signature WHERE kid = 2'), true);
    $annonce = verifier_enveloppe($bulletin, $kid1);
    check('bulletin signe par la cle precedente', $bulletin['kid'] === 1 && $annonce !== null);
    check('bulletin : contenu', $annonce === ['type' => 'nouvelle_cle', 'kid' => 2, 'cle_publique' => $active['cle_publique'],
        'valide_des' => T0 + 100]);
    check('bulletin : invalide sous la nouvelle cle', verifier_enveloppe($bulletin, $active['cle_publique']) === null);
    [$code, $enveloppe] = api_traiter($env['db'], $env['config'], json_encode(['v' => 1, 'op' => 'valider', 'produit' => 'APP',
        'distribution' => 'APP-A', 'machine' => MACHINE_A, 'poste' => 'PC', 'version' => '1.4.0', 'nonce' => 'nonce-rotation',
        't' => T0 + 200, 'cle' => $cle]), '10.9.9.9', T0 + 200);
    $p = verifier_enveloppe($enveloppe, $active['cle_publique']);
    check('reponses signees par la nouvelle cle', $enveloppe['kid'] === 2 && $p !== null && $p['ok'] === true);
    check('bulletin diffuse dans les reponses', $p['bulletins'] === [$bulletin]);
    check('ancienne cle : ne verifie plus les reponses', verifier_enveloppe($enveloppe, $kid1) === null);
    check('bulletins diffuses 12 mois', api_bulletins($env['db'], T0 + 100 + 365 * JOUR) === [$bulletin]
        && api_bulletins($env['db'], T0 + 101 + 365 * JOUR) === []);
    $r3 = signature_rotation($env['db'], $env['config'], T0 + 300);
    $b3 = json_decode($r3['bulletin'], true);
    check('seconde rotation : chaine de bulletins', $r3['kid'] === 3 && $b3['kid'] === 2
        && verifier_enveloppe($b3, $active['cle_publique'])['cle_publique'] === $r3['cle_publique']
        && count(api_bulletins($env['db'], T0 + 400)) === 2);
    $r = console($env, $session, 'GET', ['page' => 'cles']);
    check('ecran Cles : historique', substr_count($r['corps'], 'signe par la cle precedente') === 2
        && strpos($r['corps'], 'premiere cle') !== false);
    check('navigation : ecran Cles', strpos($r['corps'], 'href="index.php?page=cles"') !== false);
    // Controle d'exposition : chemins reels, cle active (kid 3) et non kid 1 efface.
    $chemins = exposition_chemins(['prive' => '/h/www/prive', 'htpasswd' => '/h/www/prive/.htpasswd',
        'cles' => '/h/www/prive/cles', 'base' => '/h/www/prive/data/licenses.db',
        'dossier_sauvegardes' => '/h/www/prive/data/sauvegardes'], $env['db']);
    check('exposition : cle de signature active', in_array('../prive/cles/signature_3.key', $chemins, true)
        && !in_array('../prive/cles/signature_1.key', $chemins, true));
    check('exposition : base, WAL et sauvegardes', in_array('../prive/data/licenses.db', $chemins, true)
        && in_array('../prive/data/licenses.db-wal', $chemins, true) && in_array('../prive/data/sauvegardes/', $chemins, true)
        && in_array('../prive/config.php', $chemins, true) && in_array('../prive/.htpasswd', $chemins, true));
    $chemins = exposition_chemins(['prive' => '/h/www/prive', 'htpasswd' => '/h/www/prive/.htpasswd',
        'cles' => '/h/www/prive/cles', 'base' => '/ailleurs/licenses.db', 'dossier_sauvegardes' => '/ailleurs/s'], $env['db']);
    check('exposition : base hors du dossier servi non testee', !in_array('../prive/data/licenses.db', $chemins, true)
        && preg_grep('#ailleurs#', $chemins) === []);
}

function test_migration_schema(): void
{
    $env = environnement();
    // Base creee par une version precedente du serveur : sans suspendue_jusqu, user_version 0.
    $schema = (string)file_get_contents(RACINE . '/www/prive/schema.sql');
    $ancien = str_replace('PRAGMA user_version = 1;', '', (string)preg_replace('/,\s*suspendue_jusqu INTEGER\);[^\n]*/',
        ');', $schema));
    check('migration : schema precedent reconstitue', strpos($ancien, 'suspendue_jusqu') === false);
    @mkdir(dirname($env['config']['base']), 0700, true);
    $db = db_connecter($env['config']['base']);
    $db->exec($ancien);
    $p = db_inserer($db, 'produits', ['code' => 'P', 'nom' => 'P', 'cree_le' => T0]);
    $d = db_inserer($db, 'distributions', ['produit_id' => $p, 'code' => 'P-A', 'libelle' => 'A', 'cree_le' => T0]);
    db_inserer($db, 'licences', ['distribution_id' => $d, 'cle_hash' => 'h', 'cle_indice' => 'ABCD', 'titulaire' => 'T',
        'statut' => 'suspendue', 'origine' => 'console', 'cree_le' => T0, 'modifie_le' => T0]);
    $db = null;
    $db = db_ouvrir($env['config']['base']);
    $colonnes = array_column(db_lignes($db, 'PRAGMA table_info(licences)'), 'name');
    check('migration : colonne suspendue_jusqu ajoutee', in_array('suspendue_jusqu', $colonnes, true));
    check('migration : version du schema', (int)db_valeur($db, 'PRAGMA user_version') === SCHEMA_VERSION);
    check('migration : donnees conservees', db_ligne($db, 'SELECT statut, suspendue_jusqu FROM licences')
        === ['statut' => 'suspendue', 'suspendue_jusqu' => null]);
    licences_fin_suspension($db, T0 + 365 * JOUR);
    check('migration : suspension sans date jamais levee automatiquement',
        db_valeur($db, 'SELECT statut FROM licences') === 'suspendue');
    $db = null;
    check('migration : reouverture sans effet', (int)db_valeur(db_ouvrir($env['config']['base']), 'PRAGMA user_version') === 1);
    check('base neuve : deja a jour', (int)db_valeur(serveur()['db'], 'PRAGMA user_version') === SCHEMA_VERSION);
}

function test_restauration_apres_rotation(): void
{
    $env = serveur();
    $cle = creer_licence($env);
    appel($env, 'activer', ['cle' => $cle]);
    check('fiche publique de la premiere cle', is_file(signature_fiche($env['config'], 1)));
    $copie = $env['tmp'] . '/sauvegarde-avant-rotation.db';
    $env['db']->exec("VACUUM INTO '" . $copie . "'");
    $r2 = signature_rotation($env['db'], $env['config'], T0 + 100);
    $r3 = signature_rotation($env['db'], $env['config'], T0 + 200);
    $bulletins = api_bulletins($env['db'], T0 + 300);
    // Restauration de la sauvegarde de la veille : la base designe kid 1, dont la cle privee est effacee.
    $env['db'] = null;
    foreach (['', '-wal', '-shm'] as $suffixe) {
        @unlink($env['config']['base'] . $suffixe);
    }
    copy($copie, $env['config']['base']);
    $env['db'] = db_ouvrir($env['config']['base']);
    check('restauration : base d\'avant la rotation', (int)db_valeur($env['db'], 'SELECT MAX(kid) FROM cles_signature') === 1);
    $active = signature_active($env['db'], $env['config']);
    check('restauration : rotations rattrapees depuis les fiches', $active['kid'] === 3
        && $active['cle_publique'] === $r3['cle_publique']);
    check('restauration : anciennes cles retirees aux bonnes dates',
        (int)db_valeur($env['db'], 'SELECT retiree_le FROM cles_signature WHERE kid = 1') === T0 + 100
        && (int)db_valeur($env['db'], 'SELECT retiree_le FROM cles_signature WHERE kid = 2') === T0 + 200);
    check('restauration : bulletins identiques', api_bulletins($env['db'], T0 + 300) === $bulletins);
    check('restauration : journalisee', db_valeur($env['db'], "SELECT COUNT(*) FROM journal WHERE action = 'cles_resynchronisees'") == 1);
    [$code, $enveloppe] = api_traiter($env['db'], $env['config'], json_encode(['v' => 1, 'op' => 'valider', 'produit' => 'APP',
        'distribution' => 'APP-A', 'machine' => MACHINE_A, 'poste' => 'PC', 'version' => '1.4.0', 'nonce' => 'nonce-restauration',
        't' => T0 + 300, 'cle' => $cle]), '10.9.9.9', T0 + 300);
    $p = verifier_enveloppe($enveloppe, $r3['cle_publique']);
    check('restauration : l\'API signe de nouveau', $code === 200 && $enveloppe['kid'] === 3 && $p !== null && $p['ok'] === true);
    // Chaine rompue (fiche intermediaire absente) : rien n'est invente, l'erreur reste visible.
    $env2 = serveur();
    $copie2 = $env2['tmp'] . '/avant.db';
    $env2['db']->exec("VACUUM INTO '" . $copie2 . "'");
    signature_rotation($env2['db'], $env2['config'], T0 + 100);
    signature_rotation($env2['db'], $env2['config'], T0 + 200);
    unlink(signature_fiche($env2['config'], 2));
    $env2['db'] = null;
    foreach (['', '-wal', '-shm'] as $suffixe) {
        @unlink($env2['config']['base'] . $suffixe);
    }
    copy($copie2, $env2['config']['base']);
    $env2['db'] = db_ouvrir($env2['config']['base']);
    try {
        signature_active($env2['db'], $env2['config']);
        $leve = false;
    } catch (RuntimeException $e) {
        $leve = strpos($e->getMessage(), 'kid 1') !== false;
    }
    check('restauration : chaine rompue non rattrapee', $leve
        && (int)db_valeur($env2['db'], 'SELECT MAX(kid) FROM cles_signature') === 1);
}

function test_console_aide(): void
{
    $env = serveur(['emails_par_jour' => 42, 'sauvegardes_conservees' => 12]);
    $session = [];
    $r = console($env, $session, 'GET', ['page' => 'aide']);
    $corps = $r['corps'];
    check('aide : page 200', $r['code'] === 200);
    check('aide : titre Aide', strpos($corps, '<title>Aide - Licences ETDEL</title>') !== false
        && strpos($corps, '<h1>Aide</h1>') !== false);
    check('aide : ASCII pur', preg_match('/[^\x00-\x7F]/', $corps) === 0);
    check('aide : aucune valeur oubliee', strpos($corps, '{{') === false && strpos($corps, '}}') === false);
    check('aide : ni script ni style en ligne, aucune ressource externe', preg_match('/<script(?![^>]*src=)/', $corps) === 0
        && strpos($corps, 'style="') === false && strpos($corps, 'javascript:') === false
        && preg_match('/(src|href)="(https?:)?\/\//', $corps) === 0);
    $ancres = ['demarrage', 'tableau', 'demandes', 'licences', 'etats', 'produits', 'application', 'tolerance', 'serveurs',
        'cles', 'journal', 'sauvegarde', 'reglages', 'securite', 'depannage', 'glossaire'];
    preg_match_all('/<section id="([a-z]+)"/', $corps, $sections);
    check('aide : sections dans l\'ordre', $sections[1] === $ancres);
    $sommaire = preg_match('#<nav id="sommaire" class="aide-sommaire"[^>]*>.*?</nav>#s', $corps, $m) === 1 ? $m[0] : '';
    foreach ($ancres as $ancre) {
        check('aide : section ' . $ancre . ' avec titre', preg_match('#<section id="' . $ancre . '"[^>]*><h2>#', $corps) === 1);
        check('aide : sommaire vers ' . $ancre, strpos($sommaire, 'href="#' . $ancre . '"') !== false);
    }
    check('aide : sommaire sans lien en trop', substr_count($sommaire, '<a ') === count($ancres));
    preg_match_all('/href="#([A-Za-z0-9_-]+)"/', $corps, $liens);
    $cassees = array_filter(array_unique($liens[1]), static function (string $id) use ($corps): bool {
        return strpos($corps, 'id="' . $id . '"') === false;
    });
    check('aide : aucun lien interne casse', $cassees === []);
    check('aide : FAQ depliable', substr_count($corps, '<details>') >= 10);
    $faq = preg_match('#<summary>Une licence revoquee peut-elle etre reactivee \?</summary>(.*?)</details>#s', $corps, $m) === 1
        ? $m[1] : '';
    check('aide : FAQ revocation definitive', strpos($faq, 'Non. La revocation est definitive') !== false
        && strpos($faq, 'Creer une cle') !== false && strpos($faq, 'Demander une licence') !== false
        && strpos($faq, 'Pas de nouvel') !== false && strpos($faq, 'Suspendre') !== false);
    check('aide : reglages lus dans config.php', strpos($corps, '42 e-mails par jour') !== false
        && strpos($corps, 'les 12 plus recentes') !== false);
    check('aide : constantes lues dans le code', strpos($corps, MOT_DE_PASSE_MIN . ' caracteres minimum') !== false
        && strpos($corps, MOTIF_REFUS_MAX . ' caracteres au plus') !== false
        && strpos($corps, API_LIMITES['demander'][0] . ' demandes de licence par jour') !== false);
    check('aide : libelles des confirmations', strpos($corps, h(ADMIN_ACTIONS['licence_revoquer'])) !== false);
    check('aide : utilisateur connecte affiche', strpos(console($env, $session, 'GET', ['page' => 'aide'], [],
        ['utilisateur' => 'a.b-c'])['corps'], '(a.b-c)') !== false);
    check('aide : menu, onglet actif', strpos($corps, '<a href="index.php?page=aide" class="actif">Aide</a>') !== false);
    check('aide : pas de lien vers elle-meme', strpos($corps, 'class="aide-lien"') === false);
    $pages = ['tableau' => 'tableau', 'demandes' => 'demandes', 'licences' => 'licences', 'licence_nouvelle' => 'licences',
        'produits' => 'produits', 'produit' => 'produits', 'distribution' => 'produits', 'serveurs' => 'serveurs',
        'cles' => 'cles', 'journal' => 'journal', 'sauvegarde' => 'sauvegarde', 'reglages' => 'reglages'];
    foreach ($pages as $page => $ancre) {
        $r = console($env, $session, 'GET', ['page' => $page]);
        check('aide : menu sur ' . $page, strpos($r['corps'], 'href="index.php?page=aide"') !== false);
        check('aide : lien contextuel ' . $page . ' vers #' . $ancre, strpos($r['corps'],
            '<a class="aide-lien" href="index.php?page=aide#' . $ancre . '">Aide sur cet ecran</a>') !== false);
    }
    check('aide : lien contextuel par defaut (tableau de bord)',
        strpos(console($env, $session, 'GET')['corps'], 'href="index.php?page=aide#tableau"') !== false);
    check('aide : pas de lien sur une page inconnue',
        strpos(console($env, $session, 'GET', ['page' => 'rien'])['corps'], 'class="aide-lien"') === false);
    $sans = ['action' => 'produit_enregistrer', 'code' => 'AIDE', 'nom' => 'A', 'csrf' => csrf_jeton($session), 'confirme' => '0'];
    check('aide : pas de lien sur la page de confirmation',
        strpos(console($env, $session, 'POST', [], $sans)['corps'], 'class="aide-lien"') === false);
}

$tests = ['test_fichiers', 'test_formats', 'test_installation', 'test_ping_et_requetes_invalides', 'test_activer_valider',
    'test_statuts_versions_surcharges', 'test_demandes', 'test_notifications', 'test_limites', 'test_sauvegarde',
    'test_signature', 'test_console_acces', 'test_console_demandes', 'test_console_licences',
    'test_console_produits_serveurs', 'test_console_reglages_exports', 'test_rotation',
    'test_restauration_apres_rotation', 'test_migration_schema', 'test_console_aide'];
foreach ($tests as $test) {
    try {
        $test();
    } catch (Throwable $e) {
        check($test . ' : exception ' . get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':'
            . $e->getLine() . ')', false);
    }
}
foreach ($temporaires as $dossier) {
    supprimer_dossier($dossier);
}
if ($failures !== []) {
    echo '=== ' . count($failures) . ' ECHEC(S) sur ' . $verifications . " verifications (serveur) ===\n";
    foreach ($failures as $nom) {
        echo ' - ' . $nom . "\n";
    }
    exit(1);
}
echo '=== TOUS LES TESTS PASSENT (serveur) === (' . $verifications . " verifications)\n";
