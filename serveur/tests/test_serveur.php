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
    $console = [];
    foreach ($fichiers as $fichier) {
        $extension = $fichier->getExtension();
        if (!in_array($extension, ['php', 'js', 'css'], true)) {
            continue;
        }
        $contenu = file_get_contents($fichier->getPathname());
        $nom = basename($fichier->getPathname());
        check('ASCII pur : ' . $nom, preg_match('/[^\x00-\x7F]/', $contenu) === 0);
        check('en-tete ETDEL (c) 2026 : ' . $nom, strpos(substr($contenu, 0, 600), 'ETDEL (c) 2026') !== false);
        if ($extension !== 'php') {
            $console[] = $nom;
            continue;
        }
        $php++;
        // config.php : copie locale de config.exemple.php (banc d'essai), jamais versionnee.
        check('strict_types : ' . $nom, in_array($nom, ['config.exemple.php', 'config.php'], true)
            || strpos($contenu, 'declare(strict_types=1);') !== false);
    }
    check('fichiers PHP trouves', $php >= 10);
    sort($console);
    check('console : app.js et style.css verifies (ASCII, en-tete)', $console === ['app.js', 'style.css']);
    // Accroches de app.js : le HTML de la console porte les memes (verifie avec chaque ecran).
    $js = (string)file_get_contents(RACINE . '/www/admin/app.js');
    foreach (["querySelector('select[data-produit]')", "querySelector('fieldset[data-nouveau-produit]')",
              'champ.required = aCreer', "classList.contains('action')", "querySelectorAll('details.action[open]')",
              "querySelector('select[data-durees]')", "closest('[data-copier]')", "getElementById('exposition')",
              "getAttribute('data-confirmer')"] as $accroche) {
        check('app.js : ' . $accroche, strpos($js, $accroche) !== false);
    }
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
    check('version min de la distribution', version_min_normalisee(' 1.10 ') === '1.10'
        && version_min_normalisee('') === null && version_min_normalisee(null) === null);
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
              'produit d\'une autre distribution' => ['produit' => 'APP', 'distribution' => 'AUTRE-A']] as $nom => $champs) {
        $r = appel($env, 'valider', $champs + ['cle' => creer_licence($env)]);
        check('produit_inconnu (' . $nom . ')', $r['p']['code'] === 'produit_inconnu' && $r['sig']);
    }
    // D68 : le produit ne sert qu'a ranger ; produits.actif est sans effet, seule la distribution compte.
    $r = appel($env, 'activer', ['produit' => 'INACTIF', 'distribution' => 'INACTIF-A',
        'cle' => creer_licence($env, 'INACTIF-A')]);
    check('produit inactif : sans effet (D68)', $r['sig'] && $r['p']['ok'] === true);
    check('produit inactif : demande acceptee (D68)', appel($env, 'demander', ['produit' => 'INACTIF',
        'distribution' => 'INACTIF-A', 'titulaire' => 'T', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()])['p']['ok'] === true);
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
    // Lecture et liaison en une transaction (D67) : une panne a l'ecriture du journal annule la liaison.
    $cle_t = creer_licence($env);
    $env['db']->exec("CREATE TEMP TRIGGER panne BEFORE INSERT ON journal WHEN NEW.action = 'activation' "
        . "BEGIN SELECT RAISE(ABORT, 'panne simulee'); END");
    try {
        appel($env, 'activer', ['cle' => $cle_t, 'machine' => hash('sha256', 'poste T')]);
        $leve = false;
    } catch (PDOException $e) {
        $leve = true;
    }
    $env['db']->exec('DROP TRIGGER panne');
    check('activer : une transaction (panne au journal, aucune liaison)', $leve && licence($env, $cle_t)['machine'] === null
        && licence($env, $cle_t)['dernier_contact'] === null);
    check('activer : apres la panne, activation normale', appel($env, 'activer', ['cle' => $cle_t,
        'machine' => hash('sha256', 'poste T')])['p']['ok'] === true);
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
    check('version min du produit sans effet (D68)', $p['ok'] === true && $p['version_min'] === '2.0');
    db_modifier($env['db'], "UPDATE distributions SET version_min = '   ' WHERE code = 'APP-A'");
    $p = appel($env, 'valider', ['cle' => $cle, 'version' => '1.0'])['p'];
    check('version min de la distribution vide : aucune, meme si le produit en a une', $p['ok'] === true
        && $p['version_min'] === null);
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
    // D68 : chaque distribution est geree seule ; l'essai est accorde une fois par poste ET par distribution.
    $r = appel($env, 'demander', ['distribution' => 'APP-B', 'titulaire' => 'X', 'email' => '', 'message' => '', 'jeton' => jeton_demande()]);
    check('autre distribution du meme produit : son propre essai (D68)', $r['p']['ok'] === true
        && $r['p']['essai_jusqu'] === T0 + 15 * JOUR && $r['p']['options'] === ['multi_navire']);
    check('essai par distribution : verifie en base', demande_essai_deja_accorde($env['db'], MACHINE_A, $env['dist']['APP-A'])
        && demande_essai_deja_accorde($env['db'], MACHINE_A, $env['dist']['APP-B'])
        && !demande_essai_deja_accorde($env['db'], MACHINE_A, $env['dist']['AUTRE-A'])
        && !demande_essai_deja_accorde($env['db'], MACHINE_B, $env['dist']['APP-A']));
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
    // D68 : la version minimale et l'etat du produit n'ont aucun effet sur les demandes.
    db_modifier($env['db'], "UPDATE produits SET version_min = '9', actif = 0 WHERE code = 'APP'");
    $p = appel($env, 'demander', ['distribution' => 'APP-B', 'machine' => hash('sha256', 'poste G'), 'titulaire' => 'G',
        'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p'];
    check('demande : version minimale et etat du produit sans effet (D68)', $p['ok'] === true
        && $p['essai_jusqu'] === T0 + 15 * JOUR);
    // Une demande faite sans essai (distribution a 0 jour d'essai) ne consomme pas l'essai de ce poste.
    $f = hash('sha256', 'poste F');
    demande_refuser($env['db'], (int)db_valeur($env['db'], 'SELECT id FROM demandes WHERE machine = ?', [$f]), '', 'admin',
        '', T0);
    db_modifier($env['db'], "UPDATE distributions SET essai_j = 15 WHERE code = 'APP-A'");
    $p = appel($env, 'demander', ['machine' => $f, 'titulaire' => 'F', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()])['p'];
    check('demande sans essai : l\'essai du poste reste disponible', $p['ok'] === true
        && $p['essai_jusqu'] === T0 + 15 * JOUR && !demande_essai_deja_accorde($env['db'], $f, $env['dist']['APP-B']));
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

/** Champs de regles d'une distribution (valeurs proposees a un debutant, D68). */
function regles_distribution(array $surcharges = []): array
{
    return array_merge(['libelle' => 'Standard', 'client' => '', 'duree_defaut_j' => 365, 'essai_j' => 15, 'options' => '',
        'tolerance_j' => 15, 'preavis_j' => 5, 'version_min' => '', 'message' => '', 'actif' => '1'], $surcharges);
}

/** Carte d'un produit sur l'ecran Distributions (section class="produit" dont le titre commence par son code). */
function carte_produit(string $corps, string $code): string
{
    return preg_match('#<section class="produit"><div class="entete-produit"><h2>' . preg_quote(h($code), '#')
        . ' - .*?</section>#s', $corps, $m) === 1 ? $m[0] : '';
}

/** Ligne du tableau d'une distribution sur l'ecran Distributions (celle dont la premiere cellule est son lien). */
function ligne_distribution(string $corps, int $id): string
{
    return preg_match('#<tr><td><a href="index\.php\?page=distribution&amp;id=' . $id . '">.*?</tr>#s', $corps, $m) === 1
        ? $m[0] : '';
}

/**
 * Champs envoyes par un formulaire de la page, comme le ferait le navigateur : caches, textes,
 * zones de texte, cases cochees, option choisie. $action designe le formulaire (champ cache action).
 */
function champs_formulaire(string $corps, string $action): array
{
    $champs = [];
    foreach (preg_split('#<form #', $corps) as $morceau) {
        $formulaire = (string)strstr($morceau, '</form>', true);
        if (strpos($formulaire, 'name="action" value="' . $action . '"') === false) {
            continue;
        }
        preg_match_all('#<input type="([a-z]+)" name="([a-z_]+)" value="([^"]*)"([^>]*)>#', $formulaire, $m, PREG_SET_ORDER);
        foreach ($m as [, $type, $nom, $valeur, $reste]) {
            if ($type !== 'checkbox' || strpos($reste, 'checked') !== false) {
                $champs[$nom] = html_entity_decode($valeur, ENT_QUOTES, 'UTF-8');
            }
        }
        preg_match_all('#<textarea name="([a-z_]+)"[^>]*>(.*?)</textarea>#s', $formulaire, $m, PREG_SET_ORDER);
        foreach ($m as [, $nom, $valeur]) {
            $champs[$nom] = html_entity_decode($valeur, ENT_QUOTES, 'UTF-8');
        }
        preg_match_all('#<select name="([a-z_]+)"[^>]*>(.*?)</select>#s', $formulaire, $m, PREG_SET_ORDER);
        foreach ($m as [, $nom, $options]) {
            // Sans option "selected", le navigateur envoie la premiere.
            if (preg_match('#<option value="([^"]*)" selected#', $options, $o) !== 1) {
                preg_match('#<option value="([^"]*)"#', $options, $o);
            }
            $champs[$nom] = html_entity_decode($o[1] ?? '', ENT_QUOTES, 'UTF-8');
        }
        return $champs;
    }
    return [];
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
    $pages = ['tableau', 'demandes', 'licences', 'licence_nouvelle', 'distributions', 'produits', 'produit', 'distribution',
        'administration', 'serveurs', 'cles', 'journal', 'sauvegarde', 'reglages'];
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
    $avant = (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM distributions');
    $post = ['action' => 'distribution_enregistrer', 'id' => '0', 'produit_id' => '0', 'produit_code' => 'NOUVEAU',
        'produit_nom' => 'N', 'code' => 'NOUVEAU-A', 'confirme' => '1'] + array_map('strval', regles_distribution());
    check('CSRF : sans jeton, refuse', console($env, $session, 'POST', [], $post)['code'] === 403);
    $post['csrf'] = csrf_jeton($session);
    check('CSRF : mauvaise origine, refusee', console($env, $session, 'POST', [], $post,
        ['origine' => 'https://pirate.exemple.com'])['code'] === 403);
    check('CSRF : origine null, refusee', console($env, $session, 'POST', [], $post, ['origine' => 'null'])['code'] === 403);
    $sans = $post;
    $sans['confirme'] = '0';
    $r = console($env, $session, 'POST', [], $sans);
    check('confirmation : page de confirmation sans ecriture', $r['code'] === 200 && strpos($r['corps'], 'Confirmer') !== false
        && strpos($r['corps'], 'name="confirme" value="1"') !== false
        && (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM distributions') === $avant);
    $r = console($env, $session, 'POST', [], $post, ['origine' => null]);
    $nouvelle = (int)db_valeur($env['db'], "SELECT id FROM distributions WHERE code = 'NOUVEAU-A'");
    check('ecriture confirmee (origine absente) : redirection', $r['code'] === 303
        && $r['entetes']['Location'] === 'index.php?page=distribution&id=' . $nouvelle . '&ok=creee');
    check('ecriture : effectuee', (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM distributions') === $avant + 1);
    check('ecriture : journalisee avec l\'utilisateur', db_valeur($env['db'],
        "SELECT acteur FROM journal WHERE action = 'distribution_creee'") === 'admin');
    $post['code'] = 'NOUVEAU2';
    $post['produit_code'] = 'NOUVEAU2';
    check('jeton renouvele apres ecriture : renvoi refuse', console($env, $session, 'POST', [], $post)['code'] === 403);
    check('action inconnue', action($env, $session, 'supprimer_tout')['code'] === 400);
    $r = console($env, $session, 'GET', ['page' => 'tableau', 'ok' => 'produit']);
    check('message apres redirection', strpos($r['corps'], 'Produit renomme.') !== false);
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
    check('accueil : demande en attente avec "Traiter"', strpos($r['corps'],
        '<a class="bouton principal" href="index.php?page=demande&amp;id=1">Traiter</a>') !== false
        && strpos($r['corps'], 'Aucune demande en attente.') === false);
    check('accueil : demande echappee', strpos($r['corps'], '&lt;script&gt;alert(1)&lt;/script&gt;') !== false
        && strpos($r['corps'], '<script>alert') === false);
    check('navigation : pastille', strpos($r['corps'], '<span class="pastille">1</span>') !== false);
    check('navigation : Demandes en evidence sur une demande', strpos(console($env, $session, 'GET', ['page' => 'demande',
        'id' => 1])['corps'], '<a href="index.php?page=demandes" class="actif">Demandes') !== false);
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
    check('fiche demande : lien vers sa distribution', strpos($r['corps'], '<dt>Distribution</dt><dd><a href="index.php?'
        . 'page=distribution&amp;id=' . $env['dist']['APP-A'] . '">APP-A - Client A</a></dd>') !== false);
    check('fiche demande : un seul mot, Client', strpos($r['corps'], '<dt>Client</dt><dd>&lt;script&gt;') !== false
        && strpos($r['corps'], '<label>Client (titulaire de la licence)<input type="text" name="titulaire"') !== false
        && strpos($r['corps'], 'Titulaire') === false && strpos($r['corps'], '<dt>Identifiant du poste</dt>') !== false);
    $r = action($env, $session, 'demande_accepter', ['id' => 1, 'duree_j' => 90, 'titulaire' => 'Armement Durand',
        'options' => 'export_pdf'], ['maintenant' => T0 + 600]);
    $cle = cle_affichee($r);
    check('acceptation : cle affichee une fois', $r['code'] === 200 && $cle !== null);
    check('acceptation : Demandes reste en evidence dans le menu', strpos($r['corps'],
        '<a href="index.php?page=demandes" class="actif">') !== false && strpos($r['corps'], 'class="actif">Accueil') === false);
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
    // Accueil : seules les demandes en attente, chacune avec "Traiter" (les traitees en disparaissent).
    $r = console($env, $session, 'GET', ['page' => 'tableau']);
    check('accueil : demandes traitees retirees', strpos($r['corps'], 'Aucune demande en attente.') !== false
        && strpos($r['corps'], 'page=demande&amp;id=1"') === false && strpos($r['corps'], 'page=demande&amp;id=2"') === false
        && strpos($r['corps'], 'class="pastille"') === false);
    appel($env, 'demander', ['machine' => MACHINE_C, 'titulaire' => 'Client C', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()]);
    appel($env, 'demander', ['distribution' => 'APP-B', 'machine' => MACHINE_C, 'titulaire' => 'Client C bis', 'email' => '',
        'message' => '', 'jeton' => jeton_demande()]);
    $r = console($env, $session, 'GET', ['page' => 'tableau']);
    check('accueil : deux demandes en attente, chacune avec Traiter et sa distribution', strpos($r['corps'],
        '<td>Client C</td><td>PC-PASSERELLE</td><td>APP-A</td><td><a class="bouton principal" href="index.php?page=demande&amp;'
        . 'id=3">Traiter</a>') !== false && strpos($r['corps'], '<td>Client C bis</td><td>PC-PASSERELLE</td><td>APP-B</td><td>'
        . '<a class="bouton principal" href="index.php?page=demande&amp;id=4">Traiter</a>') !== false
        && strpos($r['corps'], '<span class="pastille">2</span>') !== false
        && strpos($r['corps'], 'Aucune demande en attente.') === false);
}

function test_console_licences(): void
{
    $env = serveur();
    $session = [];
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle']);
    check('nouvelle licence : titre distinct de "Nouvelle cle"', strpos($r['corps'], '<h1>Nouvelle licence</h1>') !== false
        && strpos($r['corps'], 'Nouvelle cle') === false);
    check('nouvelle licence : duree par defaut de la distribution', strpos($r['corps'], 'data-duree="365"') !== false);
    // Plusieurs distributions actives, aucune demandee : rien n'est choisi a la place de l'administrateur.
    check('nouvelle licence : distribution a choisir, aucune preselectionnee', strpos($r['corps'], '<label>Distribution'
        . '<select name="distribution_id" data-durees required><option value="" selected>-- choisir une distribution --'
        . '</option>') !== false && substr_count($r['corps'], ' selected') === 1
        && strpos($r['corps'], 'name="duree_j" value=""') !== false);
    check('nouvelle licence : Client (titulaire de la licence), ordinateur', strpos($r['corps'], '<label>Client (titulaire '
        . 'de la licence)<input type="text" name="titulaire" value=""') !== false
        && strpos($r['corps'], 'Elle se lie au premier ordinateur qui l\'active.') !== false);
    check('licences : bouton "Nouvelle licence"', strpos(console($env, $session, 'GET', ['page' => 'licences'])['corps'],
        '<a class="bouton principal" href="index.php?page=licence_nouvelle">Nouvelle licence</a>') !== false);
    // Arrive par "Voir ses licences" : nouvelle licence pour cette distribution, retour a sa page.
    $corps = console($env, $session, 'GET', ['page' => 'licences', 'distribution' => $env['dist']['APP-B']])['corps'];
    check('licences d\'une distribution : nouvelle licence pour elle, retour a sa page', strpos($corps, '<p class="raccourcis">'
        . '<a class="bouton principal" href="index.php?page=licence_nouvelle&amp;distribution=' . $env['dist']['APP-B']
        . '">Nouvelle licence pour cette distribution</a><a class="bouton" href="index.php?page=distribution&amp;id='
        . $env['dist']['APP-B'] . '">Retour a la distribution</a></p>') !== false
        && strpos($corps, 'href="index.php?page=licence_nouvelle">') === false);
    $corps = console($env, $session, 'GET', ['page' => 'licences', 'distribution' => $env['dist']['APP-OFF']])['corps'];
    check('licences d\'une distribution inactive : seulement le retour a sa page', strpos($corps, '<p class="raccourcis">'
        . '<a class="bouton" href="index.php?page=distribution&amp;id=' . $env['dist']['APP-OFF'] . '">Retour a la '
        . 'distribution</a></p>') !== false && strpos($corps, 'page=licence_nouvelle') === false);
    // Depuis la page d'une distribution : elle est choisie, sa duree proposee ; inactive : signale.
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle', 'distribution' => $env['dist']['APP-B']]);
    check('nouvelle licence pour une distribution : choisie, duree proposee', strpos($r['corps'],
        '<option value="' . $env['dist']['APP-B'] . '" selected') !== false && strpos($r['corps'], 'name="duree_j" value=""')
        !== false && strpos($r['corps'], 'class="alerte"') === false);
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle', 'distribution' => $env['dist']['APP-OFF']]);
    check('nouvelle licence pour une distribution inactive : signale, non proposee, aucune autre choisie a sa place',
        $r['code'] === 200 && strpos($r['corps'], '<p class="alerte">Cette distribution n\'est pas active') !== false
        && strpos($r['corps'], '<option value="' . $env['dist']['APP-OFF'] . '"') === false
        && strpos($r['corps'], '<option value="" selected>-- choisir une distribution --</option>') !== false
        && substr_count($r['corps'], ' selected') === 1);
    check('nouvelle licence : distribution d\'un produit inactif proposee (D68)', strpos($r['corps'],
        '<option value="' . $env['dist']['INACTIF-A'] . '"') !== false);
    $r = action($env, $session, 'licence_creer', ['distribution_id' => $env['dist']['APP-A'], 'titulaire' => 'Armement Martin',
        'email' => 'martin@exemple.fr', 'note' => 'Navire 1', 'duree_j' => 30]);
    $cle = cle_affichee($r);
    check('creation : cle affichee', $cle !== null && cle_normaliser($cle) === $cle);
    $lic = licence(['db' => $env['db']], (string)$cle);
    $id = (int)$lic['id'];
    $a = $env['dist']['APP-A'];
    check('creation : une autre licence pour la meme distribution, retour a la distribution', strpos($r['corps'],
        '<a href="index.php?page=licence&amp;id=' . $id . '">Voir la licence</a> - <a href="index.php?page=licence_nouvelle'
        . '&amp;distribution=' . $a . '">Creer une autre licence pour cette distribution</a> - <a href="index.php?page='
        . 'distribution&amp;id=' . $a . '">Retour a la distribution</a>') !== false);
    check('creation : Licences en evidence dans le menu (reponse a un POST)', strpos($r['corps'],
        '<a href="index.php?page=licences" class="actif">Licences</a>') !== false
        && strpos($r['corps'], 'class="actif">Accueil') === false);
    $avant = (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM licences');
    $r = action($env, $session, 'licence_creer', ['distribution_id' => '', 'titulaire' => 'Sans distribution']);
    check('creation : distribution non choisie, refusee', $r['code'] === 400 && strpos($r['corps'],
        'Distribution : choisissez-la dans la liste. Rien n&#039;a ete cree.') !== false
        && (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM licences') === $avant
        && strpos($r['corps'], '<a href="index.php?page=licences" class="actif">') !== false);
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
    check('licences : colonnes Client et Ordinateur', strpos($r['corps'], '<th>Client</th><th>Distribution</th><th>Cle</th>'
        . '<th>Ordinateur</th>') !== false && strpos($r['corps'], 'placeholder="client, e-mail, fin de cle, ordinateur"')
        !== false);
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
        strpos($fiche, 'sans effacer sa cle') !== false
        && strpos($fiche, 'Definitif : la licence ne pourra plus etre reactivee') !== false
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
    check('changer d\'ordinateur (liberer la cle)', $r['code'] === 303 && licence_lire($env['db'], $id)['machine'] === null
        && $r['entetes']['Location'] === 'index.php?page=licence&id=' . $id . '&ok=liberee');
    check('changer d\'ordinateur : message', strpos(console($env, $session, 'GET', ['page' => 'licence', 'id' => $id,
        'ok' => 'liberee'])['corps'], 'Cle liberee : elle peut etre saisie sur le nouvel ordinateur.') !== false);
    check('changer d\'ordinateur : cle non liee refusee', action($env, $session, 'licence_liberer', ['id' => $id])['code'] === 400);
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

/** Resumes (summary) des actions depliables de la fiche, dans l'ordre. */
function actions_fiche(string $corps): array
{
    preg_match_all('#<details class="action[^"]*"><summary>(.*?)</summary>#', $corps, $m);
    return array_map(static function (string $titre): string {
        return html_entity_decode($titre, ENT_QUOTES, 'UTF-8');
    }, $m[1]);
}

/** Contenu du panneau d'une action depliable de la fiche licence (apres son summary). */
function panneau_fiche(string $corps, string $titre): string
{
    return preg_match('#<details class="action[^"]*"><summary>' . preg_quote(h($titre), '#') . '</summary>(.*?)</details>#s',
        $corps, $m) === 1 ? $m[1] : '';
}

/**
 * Formulaires d'un fragment, chacun resume en une ligne : action, champs caches (nom=valeur), champs
 * a saisir (nom:type), boutons [texte/classe] ; signale une confirmation absente ou fausse.
 */
function formulaires_resumes(string $html): array
{
    preg_match_all('#<form method="post" action="index\.php" [^>]*data-confirmer="([^"]*)">(.*?)</form>#s', $html, $f,
        PREG_SET_ORDER);
    $resumes = [];
    foreach ($f as [, $confirmer, $contenu]) {
        preg_match_all('#<input type="([a-z]+)" name="([a-z_]+)" value="([^"]*)"#', $contenu, $c, PREG_SET_ORDER);
        $champs = [];
        foreach ($c as [, $type, $nom, $valeur]) {
            $champs[$nom] = [$type, html_entity_decode($valeur, ENT_QUOTES, 'UTF-8')];
        }
        $action = $champs['action'][1] ?? '?';
        $parties = [$action];
        foreach ($champs as $nom => [$type, $valeur]) {
            if ($nom === 'confirme') {
                $parties[] = $valeur === '0' ? '' : 'CONFIRME D\'AVANCE';
            } elseif ($nom !== 'csrf' && $nom !== 'action') {
                $parties[] = $type === 'hidden' ? $nom . '=' . $valeur : $nom . ':' . $type;
            }
        }
        preg_match_all('#<button type="submit"(?: class="([a-z]+)")?>([^<]*)</button>#', $contenu, $b, PREG_SET_ORDER);
        foreach ($b as $bouton) {
            $parties[] = '[' . html_entity_decode($bouton[2], ENT_QUOTES, 'UTF-8') . ($bouton[1] !== '' ? '/' . $bouton[1] : '')
                . ']';
        }
        if (html_entity_decode($confirmer, ENT_QUOTES, 'UTF-8') !== (ADMIN_ACTIONS[$action] ?? null)) {
            $parties[] = 'CONFIRMATION FAUSSE';
        }
        $resumes[] = implode(' ', array_filter($parties, 'strlen'));
    }
    return $resumes;
}

/** Termes (dt) de la premiere fiche de la page : le resume en tete de la fiche licence. */
function resume_fiche(string $corps): array
{
    $fiche = preg_match('#<dl class="fiche">(.*?)</dl>#s', $corps, $m) === 1 ? $m[1] : '';
    preg_match_all('#<dt>(.*?)</dt>#', $fiche, $t);
    return $t[1];
}

function test_console_fiche_licence(): void
{
    $env = serveur();
    $session = [];
    $cle = creer_licence($env, 'APP-A', 30, ['titulaire' => 'Armement <Fiche>', 'email' => 'fiche@exemple.fr']);
    $id = (int)licence($env, $cle)['id'];
    $r = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id]);
    $corps = $r['corps'];
    check('fiche : page 200, ni script ni style en ligne', $r['code'] === 200
        && preg_match('/<script(?![^>]*src=)/', $corps) === 0 && strpos($corps, 'style="') === false
        && strpos($corps, 'onclick') === false);
    check('fiche : resume en tete (7 lignes)', resume_fiche($corps)
        === ['Statut', 'Client', 'Distribution', 'Cle', 'Ordinateur', 'Echeance', 'Dernier contact']);
    check('fiche : lien vers sa distribution', strpos($corps, '<dt>Distribution</dt><dd><a href="index.php?page='
        . 'distribution&amp;id=' . $env['dist']['APP-A'] . '">APP-A - Client A</a></dd>') !== false);
    check('fiche : client echappe', strpos($corps, 'Armement &lt;Fiche&gt;') !== false && strpos($corps, '<Fiche>') === false);
    check('fiche : cle masquee', strpos($corps, '<code>ETDEL-****-****-****-' . substr($cle, -4) . '</code>') !== false
        && strpos($corps, substr($cle, 0, 14)) === false);
    check('fiche : aucun ordinateur', strpos($corps, 'aucun : la cle s\'activera sur le premier ordinateur ou elle sera '
        . 'saisie') !== false);
    check('fiche : echeance et jours restants', strpos($corps, date_fr(T0 + 30 * JOUR) . ' (30 jours restants)') !== false);
    check('fiche : jamais contactee', strpos($corps, '<dt>Dernier contact</dt><dd>jamais</dd>') !== false);
    check('fiche : actions d\'une cle non liee', actions_fiche($corps) === ['Prolonger', 'Nouvelle cle', 'Suspendre', 'Revoquer']);
    check('fiche : action = bouton depliable avec explication et formulaire', preg_match('#<details class="action">'
        . '<summary>Nouvelle cle</summary><div class="panneau"><p>[^<]+</p><form method="post"[^>]*data-confirmer="'
        . preg_quote(h(ADMIN_ACTIONS['licence_nouvelle_cle']), '#') . '"#', $corps) === 1);
    check('fiche : prolonger (+30 jours, +1 an, date)', strpos($corps, '>+30 jours</button>') !== false
        && strpos($corps, '>+1 an</button>') !== false && strpos($corps, '>Fixer la date</button>') !== false);
    check('fiche : suspendre avec date facultative', strpos($corps, 'Jusqu&#039;au (facultatif)') !== false
        && strpos($corps, 'Sans date, elle reste bloquee jusqu&#039;a ce que vous cliquiez sur &quot;Reactiver&quot; ; '
            . 'avec une date, elle se debloque toute seule a la fin de ce jour-la.') !== false);
    check('fiche : revoquer en rouge', strpos($corps, '<details class="action action-danger"><summary>Revoquer</summary>')
        !== false && strpos($corps, '<button type="submit" class="danger">Revoquer definitivement</button>') !== false
        && strpos($corps, 'Nouvelle licence pour ce client') !== false);
    // Chaque panneau envoie la bonne action, pour cette licence, avec sa confirmation (et le bon mode).
    check('fiche : Prolonger envoie licence_prolonger (30, 365, date)', formulaires_resumes(panneau_fiche($corps, 'Prolonger'))
        === ['licence_prolonger id=' . $id . ' mode=30 [+30 jours/principal]',
            'licence_prolonger id=' . $id . ' mode=365 [+1 an/principal]',
            'licence_prolonger id=' . $id . ' mode=date date:date [Fixer la date]']);
    check('fiche : Nouvelle cle envoie licence_nouvelle_cle', formulaires_resumes(panneau_fiche($corps, 'Nouvelle cle'))
        === ['licence_nouvelle_cle id=' . $id . ' [Creer une nouvelle cle/principal]']);
    check('fiche : Suspendre envoie licence_suspendre (date facultative)',
        formulaires_resumes(panneau_fiche($corps, 'Suspendre')) === ['licence_suspendre id=' . $id . ' jusqu:date '
            . '[Suspendre/principal]'] && strpos(panneau_fiche($corps, 'Suspendre'), 'name="jusqu" value="" >') !== false);
    check('fiche : Revoquer envoie licence_revoquer (bouton rouge)', formulaires_resumes(panneau_fiche($corps, 'Revoquer'))
        === ['licence_revoquer id=' . $id . ' [Revoquer definitivement/danger]']);
    check('fiche : Prolonger, date obligatoire', strpos(panneau_fiche($corps, 'Prolonger'),
        'name="date" value="" required>') !== false);
    check('menu : Licences en evidence sur la fiche', strpos($corps,
        '<a href="index.php?page=licences" class="actif">Licences</a>') !== false);
    $avance = preg_match('#<details class="avance"><summary>Reglages avances</summary>(.*)</details>#s', $corps, $m) === 1
        ? $m[1] : '';
    check('fiche : reglages avances replies (modifier, informations, historique)',
        strpos($avance, 'value="licence_modifier"') !== false && strpos($avance, 'name="options_distribution"') !== false
        && strpos($avance, '<h2>Informations techniques</h2>') !== false && strpos($avance, '<h2>Historique</h2>') !== false
        && strpos($avance, 'Derniere IP') !== false && strpos($avance, 'Empreinte de l&#039;ordinateur') !== false
        && strpos($avance, 'fiche@exemple.fr') !== false);
    check('fiche : modifier hors du resume', strpos(substr($corps, 0, (int)strpos($corps, 'class="avance"')),
        'licence_modifier') === false);
    check('fiche : lien d\'aide conserve', strpos($corps, 'href="index.php?page=aide#licences"') !== false);
    check('fiche : bandeau de succes conserve', strpos(console($env, $session, 'GET', ['page' => 'licence', 'id' => $id,
        'ok' => 'prolongee'])['corps'], '<p class="succes">Licence prolongee.</p>') !== false);
    appel($env, 'activer', ['cle' => $cle]);
    $corps = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    check('fiche liee : ordinateur affiche', strpos($corps, '<dt>Ordinateur</dt><dd><code>SHXT-2380</code> PC-PASSERELLE</dd>')
        !== false && strpos($corps, '<dt>Dernier contact</dt><dd>' . date_fr(T0, true) . '</dd>') !== false);
    check('fiche liee : changer d\'ordinateur', actions_fiche($corps)
        === ['Prolonger', 'Nouvelle cle', 'Changer d\'ordinateur', 'Suspendre', 'Revoquer']
        && strpos($corps, '>Liberer cette cle</button>') !== false
        && strpos($corps, 'data-confirmer="' . h(ADMIN_ACTIONS['licence_liberer']) . '"') !== false);
    check('libelle : changer d\'ordinateur', ADMIN_ACTIONS['licence_liberer'] === 'Changer d\'ordinateur (liberer la cle)');
    check('fiche liee : Changer d\'ordinateur envoie licence_liberer',
        formulaires_resumes(panneau_fiche($corps, 'Changer d\'ordinateur'))
        === ['licence_liberer id=' . $id . ' [Liberer cette cle/principal]']);
    action($env, $session, 'licence_suspendre', ['id' => $id]);
    $corps = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    check('fiche suspendue : reactiver et changer la date de fin', actions_fiche($corps)
        === ['Prolonger', 'Nouvelle cle', 'Changer d\'ordinateur', 'Reactiver', 'Changer la date de fin', 'Revoquer']);
    check('fiche suspendue : Reactiver envoie licence_reactiver', formulaires_resumes(panneau_fiche($corps, 'Reactiver'))
        === ['licence_reactiver id=' . $id . ' [Reactiver/principal]']);
    check('fiche suspendue : Changer la date de fin envoie licence_suspendre',
        formulaires_resumes(panneau_fiche($corps, 'Changer la date de fin'))
        === ['licence_suspendre id=' . $id . ' jusqu:date [Changer la date de fin/principal]']);
    check('fiche suspendue : Revoquer envoie licence_revoquer', formulaires_resumes(panneau_fiche($corps, 'Revoquer'))
        === ['licence_revoquer id=' . $id . ' [Revoquer definitivement/danger]']);
    check('fiche suspendue sans date : date de fin vide', strpos(panneau_fiche($corps, 'Changer la date de fin'),
        'name="jusqu" value="" >') !== false);
    // Suspension datee : la date de fin actuelle est pre-remplie ; envoyer le panneau tel quel ne la perd pas.
    $fin = date('Y-m-d', T0 + 10 * JOUR);
    action($env, $session, 'licence_suspendre', ['id' => $id, 'jusqu' => $fin]);
    $corps = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    $avant = licence_lire($env['db'], $id)['suspendue_jusqu'];
    check('fiche suspendue datee : date de fin actuelle pre-remplie', $avant !== null
        && strpos(panneau_fiche($corps, 'Changer la date de fin'), 'name="jusqu" value="' . $fin . '" >') !== false);
    $r = console($env, $session, 'POST', [], ['confirme' => '1']
        + champs_formulaire(panneau_fiche($corps, 'Changer la date de fin'), 'licence_suspendre'));
    check('fiche suspendue datee : panneau envoye tel quel, date de fin conservee', $r['code'] === 303
        && licence_lire($env['db'], $id)['suspendue_jusqu'] === $avant);
    $perpetuelle = (int)licence($env, creer_licence($env, 'APP-A', null))['id'];
    $corps = console($env, $session, 'GET', ['page' => 'licence', 'id' => $perpetuelle])['corps'];
    check('fiche perpetuelle : pas de prolongation', actions_fiche($corps) === ['Nouvelle cle', 'Suspendre', 'Revoquer']
        && strpos($corps, '<dt>Echeance</dt><dd>perpetuelle</dd>') !== false);
    $echue = (int)licence($env, creer_licence($env, 'APP-A', -3))['id'];
    check('fiche echue : echeance depassee', strpos(console($env, $session, 'GET', ['page' => 'licence', 'id' => $echue])['corps'],
        date_fr(T0 - 3 * JOUR) . ' (depassee)') !== false);
    action($env, $session, 'licence_revoquer', ['id' => $id]);
    $corps = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    check('fiche revoquee : aucune action', actions_fiche($corps) === [] && strpos($corps, '<form method="post"') === false);
    check('fiche revoquee : nouvelle licence pour ce client', strpos($corps, '<a class="bouton principal" '
        . 'href="index.php?page=licence_nouvelle&amp;modele=' . $id . '">Nouvelle licence pour ce client</a>') !== false
        && strpos($corps, 'Deja remplacee') === false);
    check('fiche revoquee : echeance sans jours restants', strpos($corps, '<dt>Echeance</dt><dd>' . date_fr(T0 + 30 * JOUR)
        . ' (sans objet : licence revoquee)</dd>') !== false && strpos($corps, 'restant') === false);
    check('fiche revoquee : informations et historique', strpos($corps, 'Reglages avances') !== false
        && strpos($corps, 'licence_revoquee') !== false);
}

function test_console_nouvelle_cle(): void
{
    $env = serveur();
    $session = [];
    check('nouvelle cle : libelle de confirmation',
        ADMIN_ACTIONS['licence_nouvelle_cle'] === 'Remplacer la cle (l\'ancienne cle ne fonctionnera plus)');
    // Demande acceptee dont la cle attend d'etre remise au poste C (cle chiffree conservee).
    $jeton = jeton_demande();
    appel($env, 'demander', ['machine' => MACHINE_C, 'poste' => 'PC-ANCIEN', 'titulaire' => 'Armement Cle', 'email' => '',
        'message' => '', 'jeton' => $jeton]);
    $ancienne = (string)cle_affichee(action($env, $session, 'demande_accepter', ['id' => 1, 'duree_j' => 90,
        'titulaire' => 'Armement Cle', 'options' => 'export_pdf']));
    $id = (int)licence($env, $ancienne)['id'];
    check('nouvelle cle : remise en attente avant', db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 1') !== null);
    // Autre client, demande acceptee elle aussi en attente de remise : elle ne doit pas etre touchee.
    $jeton_autre = jeton_demande();
    appel($env, 'demander', ['machine' => MACHINE_B, 'poste' => 'PC-AUTRE', 'titulaire' => 'Armement Autre', 'email' => '',
        'message' => '', 'jeton' => $jeton_autre]);
    $cle_autre = (string)cle_affichee(action($env, $session, 'demande_accepter', ['id' => 2, 'duree_j' => 30,
        'titulaire' => 'Armement Autre', 'options' => 'export_pdf']));
    $chiffree_autre = db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 2');
    $sans = ['action' => 'licence_nouvelle_cle', 'id' => (string)$id, 'csrf' => csrf_jeton($session), 'confirme' => '0'];
    $r = console($env, $session, 'POST', [], $sans);
    check('nouvelle cle : confirmation sans JavaScript', $r['code'] === 200
        && strpos($r['corps'], h(ADMIN_ACTIONS['licence_nouvelle_cle'])) !== false
        && licence_lire($env['db'], $id)['cle_hash'] === cle_hash($ancienne));
    $post = ['action' => 'licence_nouvelle_cle', 'id' => (string)$id, 'csrf' => csrf_jeton($session), 'confirme' => '1'];
    $r = console($env, $session, 'POST', [], $post, ['maintenant' => T0 + 100]);
    $nouvelle = cle_affichee($r);
    check('nouvelle cle : page "Nouvelle cle", pas de redirection', $r['code'] === 200
        && strpos($r['corps'], '<h1>Nouvelle cle</h1>') !== false);
    check('nouvelle cle : affichee une fois, avec bouton Copier', $nouvelle !== null && $nouvelle !== $ancienne
        && substr_count($r['corps'], (string)$nouvelle) === 1 && strpos($r['corps'], 'data-copier="cle"') !== false);
    check('nouvelle cle : consignes', strpos($r['corps'], 'L\'ancienne cle ne fonctionne plus') !== false
        && strpos($r['corps'], '"J\'ai une cle"') !== false
        && strpos($r['corps'], 'premier ordinateur ou elle sera saisie') !== false
        && strpos($r['corps'], 'il faudra y saisir la nouvelle cle') !== false
        && strpos($r['corps'], 'href="index.php?page=licence&amp;id=' . $id . '"') !== false);
    $lic = licence_lire($env['db'], $id);
    check('nouvelle cle : hash et indice remplaces', $lic['cle_hash'] === cle_hash((string)$nouvelle)
        && $lic['cle_indice'] === substr((string)$nouvelle, -4) && (int)$lic['modifie_le'] === T0 + 100);
    check('nouvelle cle : ordinateur libere', $lic['machine'] === null && $lic['id_poste'] === null
        && $lic['nom_ordinateur'] === null && $lic['lie_le'] === null);
    check('nouvelle cle : statut et echeance inchanges', $lic['statut'] === 'active' && (int)$lic['echeance'] === T0 + 90 * JOUR);
    check('nouvelle cle : remise de l\'ancienne cle annulee',
        db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 1') === null);
    check('nouvelle cle : la demande ne remet plus l\'ancienne cle', appel($env, 'suivre_demande', ['machine' => MACHINE_C,
        'demande' => 1, 'jeton' => $jeton])['p']['code'] === 'demande_inconnue');
    check('nouvelle cle : remise d\'une autre licence intacte', $chiffree_autre !== null
        && db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = 2') === $chiffree_autre);
    $p = appel($env, 'suivre_demande', ['machine' => MACHINE_B, 'demande' => 2, 'jeton' => $jeton_autre])['p'];
    check('nouvelle cle : l\'autre client recoit toujours sa cle', $p['ok'] === true && $p['statut'] === 'acceptee'
        && $p['cle'] === $cle_autre);
    $journal = db_ligne($env['db'], "SELECT * FROM journal WHERE action = 'cle_remplacee'");
    check('nouvelle cle : journal', $journal !== null && $journal['acteur'] === 'admin' && $journal['cible'] === 'licence ' . $id
        && $journal['detail'] === 'ancienne cle ...' . substr($ancienne, -4) . ' ; ordinateur libere : '
            . id_poste(MACHINE_C, 'APP') . ' (PC-ANCIEN)');
    check('nouvelle cle : renvoi du formulaire (F5) refuse', console($env, $session, 'POST', [], $post)['code'] === 403
        && licence_lire($env['db'], $id)['cle_hash'] === cle_hash((string)$nouvelle));
    $r = appel($env, 'valider', ['cle' => $ancienne, 'machine' => MACHINE_C]);
    check('nouvelle cle : ancienne cle refusee (cle_invalide)', $r['sig'] && $r['p']['code'] === 'cle_invalide');
    check('nouvelle cle : ancienne cle non activable', appel($env, 'activer', ['cle' => $ancienne,
        'machine' => MACHINE_B])['p']['code'] === 'cle_invalide');
    $p = appel($env, 'activer', ['cle' => $nouvelle, 'machine' => MACHINE_B, 'poste' => 'PC-NOUVEAU'])['p'];
    check('nouvelle cle : se lie au premier ordinateur', $p['ok'] === true && licence_lire($env['db'], $id)['machine'] === MACHINE_B
        && $p['jours_restants'] === 90);
    $fiche = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    check('nouvelle cle : fiche avec la nouvelle fin, jamais la cle', strpos($fiche, 'ETDEL-****-****-****-'
        . substr((string)$nouvelle, -4)) !== false && strpos($fiche, substr((string)$nouvelle, 0, 14)) === false
        && strpos($fiche, 'cle_remplacee') !== false);
    // Licence non liee, suspendue : permis, la suspension demeure.
    $cle_s = creer_licence($env, 'APP-B', 365, ['statut' => 'suspendue']);
    $id_s = (int)licence($env, $cle_s)['id'];
    $r = action($env, $session, 'licence_nouvelle_cle', ['id' => $id_s]);
    $lic = licence_lire($env['db'], $id_s);
    check('nouvelle cle : licence suspendue permise', cle_affichee($r) !== null && $lic['statut'] === 'suspendue'
        && $lic['cle_hash'] === cle_hash((string)cle_affichee($r)));
    check('nouvelle cle : journal sans ordinateur', db_valeur($env['db'], "SELECT detail FROM journal WHERE action = "
        . "'cle_remplacee' AND cible = ?", ['licence ' . $id_s]) === 'ancienne cle ...' . substr($cle_s, -4)
        . ' ; aucun ordinateur lie');
    // Licence revoquee : refus, rien ne change.
    $cle_r = creer_licence($env, 'APP-A', 365, ['statut' => 'revoquee', 'machine' => MACHINE_A, 'id_poste' => 'SHXT-2380']);
    $id_r = (int)licence($env, $cle_r)['id'];
    $avant = (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM journal');
    $r = action($env, $session, 'licence_nouvelle_cle', ['id' => $id_r]);
    $lic = licence_lire($env['db'], $id_r);
    check('nouvelle cle : refusee sur une licence revoquee', $r['code'] === 400 && cle_affichee($r) === null
        && strpos($r['corps'], 'Nouvelle licence pour ce client') !== false);
    check('nouvelle cle : revoquee inchangee', $lic['cle_hash'] === cle_hash($cle_r) && $lic['machine'] === MACHINE_A
        && (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM journal') === $avant);
    check('nouvelle cle : licence inconnue', action($env, $session, 'licence_nouvelle_cle', ['id' => 999])['code'] === 400);
    // Une seule transaction : une panne a la derniere ecriture (journal) annule tout le reste.
    $jeton_p = jeton_demande();
    appel($env, 'demander', ['machine' => hash('sha256', 'poste P'), 'poste' => 'PC-PANNE', 'titulaire' => 'Armement Panne',
        'email' => '', 'message' => '', 'jeton' => $jeton_p]);
    $num_p = (int)db_valeur($env['db'], "SELECT id FROM demandes WHERE titulaire = 'Armement Panne'");
    $cle_p = (string)cle_affichee(action($env, $session, 'demande_accepter', ['id' => $num_p, 'duree_j' => 30,
        'titulaire' => 'Armement Panne', 'options' => 'export_pdf']));
    $avant = licence($env, $cle_p);
    $chiffree_p = db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = ?', [$num_p]);
    $env['db']->exec("CREATE TEMP TRIGGER panne BEFORE INSERT ON journal WHEN NEW.action = 'cle_remplacee' "
        . "BEGIN SELECT RAISE(ABORT, 'panne simulee'); END");
    $journal_php = ini_set('error_log', $env['tmp'] . '/php.log');
    $r = action($env, $session, 'licence_nouvelle_cle', ['id' => (int)$avant['id']]);
    ini_set('error_log', (string)$journal_php);
    $env['db']->exec('DROP TRIGGER panne');
    $apres = licence_lire($env['db'], (int)$avant['id']);
    check('nouvelle cle : panne, erreur affichee sans cle', $r['code'] === 500 && cle_affichee($r) === null);
    check('nouvelle cle : panne, rien n\'a change (une transaction)', $apres['cle_hash'] === $avant['cle_hash']
        && $apres['cle_indice'] === $avant['cle_indice'] && $apres['machine'] === $avant['machine']
        && $apres['id_poste'] === $avant['id_poste'] && $apres['lie_le'] === $avant['lie_le'] && $chiffree_p !== null
        && db_valeur($env['db'], 'SELECT cle_chiffree FROM demandes WHERE id = ?', [$num_p]) === $chiffree_p);
    check('nouvelle cle : panne, la cle remise reste valable', appel($env, 'suivre_demande', ['machine' => hash('sha256',
        'poste P'), 'demande' => $num_p, 'jeton' => $jeton_p])['p']['cle'] === $cle_p);
}

function test_console_nouvelle_licence_modele(): void
{
    $env = serveur();
    $session = [];
    $cle = creer_licence($env, 'APP-B', 365, ['titulaire' => 'Armement "Modele"', 'email' => 'modele@exemple.fr',
        'note' => 'ancienne note', 'statut' => 'revoquee']);
    $id = (int)licence($env, $cle)['id'];
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle', 'modele' => $id]);
    $corps = $r['corps'];
    check('modele : page 200', $r['code'] === 200 && strpos($corps, 'value="licence_creer"') !== false);
    check('modele : meme distribution', strpos($corps, '<option value="' . $env['dist']['APP-B'] . '" selected') !== false
        && strpos($corps, '<option value="' . $env['dist']['APP-A'] . '" selected') === false);
    check('modele : titulaire et e-mail', strpos($corps, 'name="titulaire" value="Armement &quot;Modele&quot;"') !== false
        && strpos($corps, 'name="email" value="modele@exemple.fr"') !== false);
    check('modele : note de remplacement', strpos($corps, '>Remplace la licence n. ' . $id . '</textarea>') !== false
        && strpos($corps, 'ancienne note') === false);
    check('modele : duree par defaut de la distribution (APP-B : perpetuelle)',
        strpos($corps, 'name="duree_j" value=""') !== false);
    check('modele : rappel de la licence remplacee', strpos($corps, 'href="index.php?page=licence&amp;id=' . $id . '"') !== false);
    check('modele : transmis avec le formulaire', strpos($corps, '<input type="hidden" name="modele" value="' . $id . '">')
        !== false);
    $page_modele = $corps;
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle', 'modele' => (int)licence($env,
        creer_licence($env, 'APP-A', 10, ['statut' => 'revoquee']))['id']]);
    check('modele : duree par defaut de la distribution (APP-A : 365)', strpos($r['corps'], 'name="duree_j" value="365"') !== false);
    $hors = (int)licence($env, creer_licence($env, 'APP-OFF', 10, ['statut' => 'revoquee']))['id'];
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle', 'modele' => $hors]);
    check('modele : distribution devenue inactive signalee, aucune autre choisie a sa place', $r['code'] === 200
        && strpos($r['corps'], 'La distribution de cette licence n\'est plus active') !== false
        && strpos($r['corps'], '<option value="" selected>-- choisir une distribution --</option>') !== false
        && substr_count($r['corps'], ' selected') === 1);
    check('modele : licence inconnue', console($env, $session, 'GET', ['page' => 'licence_nouvelle', 'modele' => 999])['code'] === 400);
    $r = console($env, $session, 'GET', ['page' => 'licence_nouvelle']);
    check('sans modele : formulaire vide', strpos($r['corps'], 'name="titulaire" value=""') !== false
        && strpos($r['corps'], 'Remplace la licence') === false && strpos($r['corps'], 'name="modele"') === false);
    $avant = (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM licences');
    check('modele inconnu a l\'envoi : refuse, rien cree', action($env, $session, 'licence_creer', ['distribution_id' =>
        $env['dist']['APP-B'], 'titulaire' => 'X', 'modele' => 999])['code'] === 400
        && (int)db_valeur($env['db'], 'SELECT COUNT(*) FROM licences') === $avant);
    // Le formulaire tel que le navigateur l'envoie : la licence revoquee note sa remplacante.
    $r = console($env, $session, 'POST', [], ['confirme' => '1', 'csrf' => csrf_jeton($session)]
        + champs_formulaire($page_modele, 'licence_creer'));
    $nouvelle = cle_affichee($r);
    $lic = $nouvelle === null ? null : licence($env, $nouvelle);
    check('modele : nouvelle licence creee pour le client', $lic !== null && (int)$lic['id'] !== $id
        && $lic['echeance'] === null && $lic['note'] === 'Remplace la licence n. ' . $id
        && (int)$lic['distribution_id'] === $env['dist']['APP-B'] && $lic['titulaire'] === 'Armement "Modele"'
        && licence_lire($env['db'], $id)['statut'] === 'revoquee');
    $journal = db_ligne($env['db'], "SELECT * FROM journal WHERE action = 'licence_remplacee'");
    check('modele : remplacement journalise sur la licence revoquee', $journal !== null && $journal['acteur'] === 'admin'
        && $journal['cible'] === 'licence ' . $id && $journal['detail'] === 'par la licence n. ' . $lic['id']);
    $corps = console($env, $session, 'GET', ['page' => 'licence', 'id' => $id])['corps'];
    check('modele : la licence revoquee indique sa remplacante, bouton au second plan', strpos($corps, '<p><strong>Deja '
        . 'remplacee par la <a href="index.php?page=licence&amp;id=' . $lic['id'] . '">licence n. ' . $lic['id']
        . '</a>.</strong></p><p><a class="bouton" href="index.php?page=licence_nouvelle&amp;modele=' . $id . '">Nouvelle '
        . 'licence pour ce client</a></p>') !== false && strpos($corps, 'bouton principal') === false);
    // Sans modele, ou modele non revoque : aucune remplacante notee.
    $active = (int)licence($env, creer_licence($env, 'APP-A'))['id'];
    action($env, $session, 'licence_creer', ['distribution_id' => $env['dist']['APP-A'], 'titulaire' => 'Y',
        'modele' => $active]);
    check('modele non revoque : rien note', (int)db_valeur($env['db'], "SELECT COUNT(*) FROM journal "
        . "WHERE action = 'licence_remplacee'") === 1);
}

function test_console_distributions_serveurs(): void
{
    $env = serveur();
    $session = [];
    // Nouvelle distribution et nouveau produit (simple classement) en une seule fois (D68).
    $r = action($env, $session, 'distribution_enregistrer', ['id' => 0, 'produit_id' => 0, 'produit_code' => 'NAVIRE',
        'produit_nom' => 'Suivi navire', 'code' => 'NAVIRE-DEMO'] + regles_distribution(['libelle' => 'Demo',
        'client' => 'Salon', 'tolerance_j' => '3', 'preavis_j' => '1', 'duree_defaut_j' => '30', 'essai_j' => '7',
        'options' => 'demo', 'message' => 'Version de demonstration']));
    $produit = (int)db_valeur($env['db'], "SELECT id FROM produits WHERE code = 'NAVIRE'");
    $dist = (int)db_valeur($env['db'], "SELECT id FROM distributions WHERE code = 'NAVIRE-DEMO'");
    check('distribution creee avec son nouveau produit', $r['code'] === 303 && $produit > 0 && $dist > 0
        && (int)db_valeur($env['db'], 'SELECT produit_id FROM distributions WHERE id = ?', [$dist]) === $produit
        && db_valeur($env['db'], 'SELECT nom FROM produits WHERE id = ?', [$produit]) === 'Suivi navire');
    $d = db_ligne($env['db'], 'SELECT * FROM distributions WHERE id = ?', [$dist]);
    check('creation : chaque regle saisie enregistree', $d['libelle'] === 'Demo' && $d['client'] === 'Salon'
        && (int)$d['duree_defaut_j'] === 30 && (int)$d['essai_j'] === 7 && (int)$d['tolerance_j'] === 3
        && (int)$d['preavis_j'] === 1 && $d['options'] === '["demo"]' && $d['message'] === 'Version de demonstration'
        && $d['version_min'] === null && (int)$d['actif'] === 1 && (int)$d['cree_le'] === T0);
    // Creation avec une version minimale, case Active decochee (champ absent, comme le navigateur l'envoie).
    $champs = ['id' => 0, 'produit_id' => $produit, 'code' => 'NAVIRE-V2'] + regles_distribution(['libelle' => 'V2',
        'version_min' => '2.0']);
    unset($champs['actif']);
    $r = action($env, $session, 'distribution_enregistrer', $champs);
    $v2 = db_ligne($env['db'], "SELECT * FROM distributions WHERE code = 'NAVIRE-V2'");
    check('creation inactive avec version minimale : enregistree telle quelle', $r['code'] === 303 && $v2 !== null
        && (int)$v2['actif'] === 0 && $v2['version_min'] === '2.0');
    check('creation inactive : refusee par l\'API (produit_inconnu)', appel($env, 'demander', ['produit' => 'NAVIRE',
        'distribution' => 'NAVIRE-V2', 'version' => '2.0', 'titulaire' => 'V', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()])['p']['code'] === 'produit_inconnu');
    $corps = console($env, $session, 'GET', ['page' => 'distribution', 'id' => (int)$v2['id']])['corps'];
    check('creation inactive : page avec Inactive, reglages avances deplies, sans nouvelle licence', strpos($corps,
        '<dt>Etat</dt><dd><span class="etiquette inactive">Inactive</span>') !== false
        && strpos($corps, '<details class="avance" open>') !== false && strpos($corps, 'name="version_min" value="2.0"') !== false
        && strpos($corps, 'page=licence_nouvelle') === false && strpos($corps, '<p class="alerte">Distribution inactive : '
            . 'reactivez-la (Reglages avances, case Active) pour lui creer des licences.</p>') !== false);
    $r = action($env, $session, 'distribution_enregistrer', ['id' => 0, 'produit_id' => 0, 'produit_code' => 'NA VIRE',
        'produit_nom' => 'X', 'code' => 'NAVIRE-X'] + regles_distribution());
    check('nouveau produit : code invalide refuse', $r['code'] === 400
        && strpos($r['corps'], 'Code du nouveau produit : lettres') !== false);
    $r = action($env, $session, 'distribution_enregistrer', ['id' => 0, 'produit_id' => $produit, 'code' => 'NAVIRE DEMO']
        + regles_distribution());
    check('distribution : code invalide refuse', $r['code'] === 400
        && strpos($r['corps'], 'Code de la distribution : lettres') !== false);
    $p = appel($env, 'demander', ['produit' => 'NAVIRE', 'distribution' => 'NAVIRE-DEMO', 'titulaire' => 'Visiteur',
        'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p'];
    check('nouvelle distribution utilisable sans toucher au code', $p['ok'] === true && $p['essai_jusqu'] === T0 + 7 * JOUR
        && $p['options'] === ['demo']);
    check('produit envoye par l\'application different : produit_inconnu', appel($env, 'demander', ['produit' => 'APP',
        'distribution' => 'NAVIRE-DEMO', 'machine' => MACHINE_C, 'titulaire' => 'V', 'email' => '', 'message' => '',
        'jeton' => jeton_demande()])['p']['code'] === 'produit_inconnu');
    $r = console($env, $session, 'GET', ['page' => 'distribution', 'id' => $dist]);
    check('ligne installer() prete a copier', strpos($r['corps'],
        h('etdel_licence.installer(root, produit="NAVIRE", distribution="NAVIRE-DEMO", version=APP_VERSION)')) !== false);
    check('distribution : tolerance hors bornes', action($env, $session, 'distribution_enregistrer', ['id' => $dist,
        'libelle' => 'Demo', 'tolerance_j' => '400', 'preavis_j' => '1', 'essai_j' => '0'])['code'] === 400);
    db_modifier($env['db'], "UPDATE distributions SET version_min = '1.5' WHERE id = ?", [$dist]);
    action($env, $session, 'distribution_dupliquer', ['id' => $dist, 'code' => 'NAVIRE-CLIENTA'], ['maintenant' => T0 + 50]);
    $copie = db_ligne($env['db'], "SELECT * FROM distributions WHERE code = 'NAVIRE-CLIENTA'");
    $source = db_ligne($env['db'], 'SELECT * FROM distributions WHERE id = ?', [$dist]);
    $sans = static function (?array $ligne): array {
        return array_diff_key((array)$ligne, array_flip(['id', 'code', 'libelle', 'client', 'cree_le']));
    };
    check('dupliquer une distribution : memes regles, colonne par colonne', $copie !== null
        && $sans($copie) === $sans($source) && (int)$copie['produit_id'] === $produit && $copie['version_min'] === '1.5'
        && (int)$copie['tolerance_j'] === 3 && (int)$copie['preavis_j'] === 1 && (int)$copie['duree_defaut_j'] === 30);
    check('dupliquer : libelle "(copie)", sans client, date du jour', $copie['libelle'] === 'Demo (copie)'
        && $copie['client'] === null && (int)$copie['cree_le'] === T0 + 50);
    check('dupliquer : journalise', db_ligne($env['db'], "SELECT acteur, cible, detail FROM journal "
        . "WHERE action = 'distribution_dupliquee'") === ['acteur' => 'admin', 'cible' => 'distribution ' . $copie['id'],
        'detail' => 'copie de ' . $dist . ' : NAVIRE-CLIENTA']);
    check('dupliquer : code existant refuse', action($env, $session, 'distribution_dupliquer', ['id' => $dist,
        'code' => 'NAVIRE-DEMO'])['code'] === 400);
    $r = action($env, $session, 'distribution_dupliquer', ['id' => $dist, 'code' => 'navire-demo']);
    check('dupliquer : meme code a la casse pres refuse', $r['code'] === 400 && strpos($r['corps'], 'Ce code de '
        . 'distribution existe deja (NAVIRE-DEMO, meme code a la casse pres).') !== false
        && db_valeur($env['db'], "SELECT COUNT(*) FROM distributions WHERE code = 'navire-demo'") == 0);
    db_modifier($env['db'], 'UPDATE distributions SET version_min = NULL WHERE id IN (?, ?)', [$dist, (int)$copie['id']]);
    action($env, $session, 'distribution_enregistrer', ['id' => $dist, 'libelle' => 'Demo', 'tolerance_j' => '3',
        'preavis_j' => '1', 'essai_j' => '7', 'options' => 'demo']);
    check('desactiver une distribution', appel($env, 'demander', ['produit' => 'NAVIRE', 'distribution' => 'NAVIRE-DEMO',
        'machine' => MACHINE_B, 'titulaire' => 'V', 'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p']['code']
        === 'produit_inconnu');
    check('desactiver une distribution : les autres du meme produit restent utilisables', appel($env, 'demander',
        ['produit' => 'NAVIRE', 'distribution' => 'NAVIRE-CLIENTA', 'machine' => MACHINE_B, 'titulaire' => 'V', 'email' => '',
            'message' => '', 'jeton' => jeton_demande()])['p']['ok'] === true);
    $r = console($env, $session, 'GET', ['page' => 'distributions']);
    $carte = carte_produit($r['corps'], 'NAVIRE');
    check('ecran distributions : rangees sous leur produit', strpos($carte, 'NAVIRE-CLIENTA') !== false
        && strpos($carte, 'NAVIRE-DEMO') !== false && strpos($carte, 'Suivi navire') !== false);
    check('distribution desactivee : etiquette Inactive', strpos(ligne_distribution($r['corps'], $dist),
        '<span class="etiquette inactive">Inactive</span>') !== false
        && strpos(ligne_distribution($r['corps'], (int)$copie['id']), '<span class="etiquette active">Active</span>') !== false);
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
    check('administration : controle d\'exposition', strpos(console($env, $session, 'GET', ['page' => 'administration'])['corps'],
        '<ul id="exposition" data-chemins="') !== false);
    check('accueil : plus de controle d\'exposition', strpos(console($env, $session, 'GET', [])['corps'], 'id="exposition"') === false);
}

/** Vrai si chaque position est trouvee et plus loin que la precedente. */
function dans_l_ordre(array $positions): bool
{
    if (in_array(false, $positions, true)) {
        return false;
    }
    for ($i = 1; $i < count($positions); $i++) {
        if ($positions[$i - 1] >= $positions[$i]) {
            return false;
        }
    }
    return true;
}

/** Ecran Distributions (D68) : liste rangee par produit, filtre, renommage du produit. */
function test_console_ecran_distributions(): void
{
    $env = serveur();
    $session = [];
    $db = $env['db'];
    $app = (int)db_valeur($db, "SELECT id FROM produits WHERE code = 'APP'");
    $autre = (int)db_valeur($db, "SELECT id FROM produits WHERE code = 'AUTRE'");
    $a = $env['dist']['APP-A'];
    $b = $env['dist']['APP-B'];
    // APP-A : une licence active et une revoquee (toutes comptees, comme la liste ouverte par le lien), une
    // demande en attente et une refusee (seule la premiere est "en attente"), un client a echapper.
    creer_licence($env, 'APP-A');
    creer_licence($env, 'APP-A', 365, ['statut' => 'revoquee']);
    appel($env, 'demander', ['titulaire' => 'T', 'email' => '', 'message' => '', 'jeton' => jeton_demande()]);
    demande_refuser($db, (int)appel($env, 'demander', ['machine' => MACHINE_B, 'titulaire' => 'T2', 'email' => '',
        'message' => '', 'jeton' => jeton_demande()])['p']['demande'], '', 'admin', '', T0);
    db_modifier($db, 'UPDATE distributions SET client = ? WHERE id = ?', ['Cabinet <Dupont> & fils', $a]);
    // APP-B : une demande acceptee seulement (sa licence comptee, aucune en attente).
    demande_accepter($db, $env['config'], (int)appel($env, 'demander', ['distribution' => 'APP-B', 'machine' => MACHINE_C,
        'titulaire' => 'T3', 'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p']['demande'], null, 'T3', null,
        'admin', '', T0);
    $r = console($env, $session, 'GET', ['page' => 'distributions']);
    $corps = $r['corps'];
    check('distributions : titre, phrase et bouton', $r['code'] === 200 && strpos($corps, '<h1>Distributions</h1>') !== false
        && strpos($corps, '<p>Une distribution = une application livree (un executable) avec ses regles, ses licences et ses '
            . 'demandes. Le produit sert seulement a ranger les distributions.</p>') !== false
        && strpos($corps, '<a class="bouton principal" href="index.php?page=distribution">Nouvelle distribution</a>') !== false);
    check('distributions : filtre par produit (GET)', strpos($corps, '<form method="get" action="index.php" class="filtres">'
        . '<input type="hidden" name="page" value="distributions"><label>Produit<select name="produit" >'
        . '<option value="0" selected>tous</option><option value="' . $app . '">APP - Application test</option>') !== false);
    check('distributions : rangees par produit, dans l\'ordre des codes', dans_l_ordre([
        strpos($corps, '<h2>APP - Application test</h2>'), strpos($corps, '<h2>AUTRE - Autre</h2>'),
        strpos($corps, '<h2>INACTIF - Inactif</h2>')]));
    $carte = carte_produit($corps, 'APP');
    check('distributions : celles du produit sous son titre', dans_l_ordre([strpos($carte, '>APP-A</a>'),
        strpos($carte, '>APP-B</a>'), strpos($carte, '>APP-OFF</a>')]) && strpos($carte, 'AUTRE-A') === false);
    check('distributions : colonnes (etat a cote du code, "En attente")', strpos($carte, '<thead><tr><th>Distribution</th>'
        . '<th>Libelle</th><th>Client</th><th>Regles</th><th>Licences</th><th>En attente</th></tr></thead>') !== false);
    check('distributions : une ligne par distribution (etat, client echappe, regles en clair, toutes ses licences, '
        . 'demandes en attente seulement)', ligne_distribution($corps, $a) === '<tr><td><a href="index.php?page='
        . 'distribution&amp;id=' . $a . '">APP-A</a> <span class="etiquette active">Active</span></td><td>Client A</td>'
        . '<td>Cabinet &lt;Dupont&gt; &amp; fils</td><td>Licence 365 jours, essai 15 jours, options : export_pdf, hors ligne '
        . '15 jours</td><td><a href="index.php?page=licences&amp;distribution=' . $a . '">2</a></td>'
        . '<td><a href="index.php?page=demandes">1</a></td></tr>');
    check('distributions : perpetuelle, une demande acceptee (sa licence, 0 en attente)', strpos(ligne_distribution($corps,
        $b), '<td>Licence perpetuelle, essai 15 jours, options : multi_navire, hors ligne 15 jours</td><td><a href="index.php'
        . '?page=licences&amp;distribution=' . $b . '">1</a></td><td>0</td></tr>') !== false);
    check('distributions : sans licence ni demande', strpos(ligne_distribution($corps, $env['dist']['APP-OFF']),
        '<td><a href="index.php?page=licences&amp;distribution=' . $env['dist']['APP-OFF'] . '">0</a></td><td>0</td></tr>')
        !== false);
    check('distributions : etiquette Inactive', strpos(ligne_distribution($corps, $env['dist']['APP-OFF']),
        '<span class="etiquette inactive">Inactive</span>') !== false);
    check('distributions : produit "inactif" sans effet ni etiquette (D68)', strpos($corps, 'desactive') === false
        && strpos(ligne_distribution($corps, $env['dist']['INACTIF-A']), 'etiquette active') !== false);
    check('distributions : plus de "variante" ni de reglages de produit', stripos($corps, 'variante') === false
        && strpos($corps, 'name="version_min"') === false && strpos($corps, 'name="actif"') === false);
    check('distributions : renommer le produit (nom seul)', strpos($carte, '<details class="renommer"><summary>Renommer'
        . '</summary>') !== false && formulaires_resumes($carte) === ['produit_enregistrer id=' . $app . ' nom:text [Renommer]']
        && strpos($carte, 'name="nom" value="Application test" maxlength="120" required') !== false);
    check('libelle : renommer le produit', ADMIN_ACTIONS['produit_enregistrer'] === 'Renommer le produit');
    check('distributions : lien d\'aide', strpos($corps, 'href="index.php?page=aide#produits">Aide sur cet ecran') !== false);
    // Filtre, et anciennes adresses de l'ecran Produits.
    $corps = console($env, $session, 'GET', ['page' => 'distributions', 'produit' => $autre])['corps'];
    check('filtre : un seul produit', carte_produit($corps, 'AUTRE') !== '' && carte_produit($corps, 'APP') === ''
        && strpos($corps, '<option value="' . $autre . '" selected>') !== false
        && strpos($corps, 'href="index.php?page=distribution&amp;produit=' . $autre . '">Nouvelle distribution</a>') !== false);
    check('filtre : le renommage le conserve', formulaires_resumes(carte_produit($corps, 'AUTRE'))
        === ['produit_enregistrer id=' . $autre . ' produit=' . $autre . ' nom:text [Renommer]']);
    $r = console($env, $session, 'POST', [], ['confirme' => '1', 'nom' => 'Autre produit']
        + champs_formulaire(carte_produit($corps, 'AUTRE'), 'produit_enregistrer'));
    check('filtre : retour a la liste filtree apres le renommage', $r['code'] === 303 && $r['entetes']['Location']
        === 'index.php?page=distributions&produit=' . $autre . '&ok=produit'
        && db_valeur($db, 'SELECT nom FROM produits WHERE id = ?', [$autre]) === 'Autre produit');
    check('filtre : produit inconnu = tous', carte_produit(console($env, $session, 'GET', ['page' => 'distributions',
        'produit' => 999])['corps'], 'APP') !== '');
    $corps = console($env, $session, 'GET', ['page' => 'produits'])['corps'];
    check('ancienne adresse page=produits : ecran Distributions', strpos($corps, '<h1>Distributions</h1>') !== false
        && carte_produit($corps, 'APP') !== '' && carte_produit($corps, 'AUTRE') !== '');
    $corps = console($env, $session, 'GET', ['page' => 'produit', 'id' => $app])['corps'];
    check('ancienne adresse page=produit : distributions du produit', strpos($corps, '<h1>Distributions</h1>') !== false
        && carte_produit($corps, 'APP') !== '' && carte_produit($corps, 'AUTRE') === '');

    // Renommer : seul le nom change, confirme et journalise ; le code est definitif.
    $r = action($env, $session, 'produit_enregistrer', ['id' => $app, 'nom' => 'Appli <renommee>', 'code' => 'PIRATE',
        'actif' => '0', 'version_min' => '9']);
    $p = db_ligne($db, 'SELECT * FROM produits WHERE id = ?', [$app]);
    check('renommer : redirection et message', $r['code'] === 303
        && $r['entetes']['Location'] === 'index.php?page=distributions&ok=produit'
        && strpos(console($env, $session, 'GET', ['page' => 'distributions', 'ok' => 'produit'])['corps'],
            '<p class="succes">Produit renomme.</p>') !== false);
    check('renommer : nom seul, code et reste inchanges', $p['nom'] === 'Appli <renommee>' && $p['code'] === 'APP'
        && (int)$p['actif'] === 1 && $p['version_min'] === null);
    check('renommer : journal', db_valeur($db, "SELECT detail FROM journal WHERE action = 'produit_renomme' AND cible = ?",
        ['produit ' . $app]) === 'APP : Application test -> Appli <renommee>'
        && db_valeur($db, "SELECT acteur FROM journal WHERE action = 'produit_renomme'") === 'admin');
    check('renommer : nom echappe', strpos(console($env, $session, 'GET', ['page' => 'distributions'])['corps'],
        '<h2>APP - Appli &lt;renommee&gt;</h2>') !== false);
    $avant = (int)db_valeur($db, 'SELECT COUNT(*) FROM journal');
    check('renommer : nom vide refuse', action($env, $session, 'produit_enregistrer', ['id' => $app, 'nom' => ' '])['code'] === 400);
    check('renommer : produit inconnu refuse', action($env, $session, 'produit_enregistrer', ['id' => 999,
        'nom' => 'X'])['code'] === 400 && action($env, $session, 'produit_enregistrer', ['nom' => 'X'])['code'] === 400);
    check('renommer : refus sans ecriture', (int)db_valeur($db, 'SELECT COUNT(*) FROM journal') === $avant
        && db_valeur($db, 'SELECT nom FROM produits WHERE id = ?', [$app]) === 'Appli <renommee>');
    $vide = db_inserer($db, 'produits', ['code' => 'VIDE', 'nom' => 'Sans distribution', 'cree_le' => T0]);
    check('produit sans distribution : lien pour en creer une', strpos(carte_produit(console($env, $session, 'GET',
        ['page' => 'distributions'])['corps'], 'VIDE'), 'Aucune distribution pour ce produit.</p><p><a href="index.php?page='
        . 'distribution&amp;produit=' . $vide . '">Nouvelle distribution pour ce produit</a>') !== false);
    // Sans produit du tout : seulement le bouton.
    $vierge = environnement();
    $vierge['installation'] = installation_executer($vierge['config'], parametres_installation(), $vierge['tmp'] . '/www/admin', T0);
    $vierge['db'] = db_ouvrir($vierge['config']['base']);
    $s = [];
    $corps = console($vierge, $s, 'GET', ['page' => 'distributions'])['corps'];
    check('distributions : base vide', strpos($corps, 'Aucune distribution.') !== false
        && strpos($corps, '>Nouvelle distribution</a>') !== false && strpos($corps, 'class="filtres"') === false);
    $corps = console($vierge, $s, 'GET', ['page' => 'licence_nouvelle'])['corps'];
    check('nouvelle licence sans distribution : lien vers la creation', strpos($corps, 'Aucune distribution active : '
        . '<a href="index.php?page=distribution">creer d\'abord une distribution</a>') !== false);
    $corps = console($vierge, $s, 'GET', ['page' => 'distribution'])['corps'];
    check('nouvelle distribution sans produit : "Nouveau produit" seul, choisi', strpos($corps, '<select name="produit_id" '
        . 'data-produit required><option value="0" selected>Nouveau produit</option></select>') !== false);
    $r = console($vierge, $s, 'POST', [], ['confirme' => '1', 'produit_code' => 'SEUL', 'produit_nom' => 'Seul',
        'code' => 'SEUL-1', 'libelle' => 'Unique'] + champs_formulaire($corps, 'distribution_enregistrer'));
    $seule = (int)db_valeur($vierge['db'], "SELECT id FROM distributions WHERE code = 'SEUL-1'");
    check('premiere distribution creee depuis la page', $r['code'] === 303 && $seule > 0);
    $corps = console($vierge, $s, 'GET', ['page' => 'licence_nouvelle'])['corps'];
    check('nouvelle licence : la seule distribution active est choisie', strpos($corps, '<option value="' . $seule
        . '" selected') !== false && strpos($corps, '-- choisir') === false && strpos($corps, 'name="duree_j" value="365"')
        !== false);
}

/** Page d'une distribution (D68) : en-tete, ligne installer(), ses licences, regles, duplication. */
function test_console_page_distribution(): void
{
    $env = serveur();
    $session = [];
    $db = $env['db'];
    $a = $env['dist']['APP-A'];
    $cle = creer_licence($env, 'APP-A', 365, ['titulaire' => 'Client de A']);
    creer_licence($env, 'APP-B', 365, ['titulaire' => 'Client de B']);
    $r = console($env, $session, 'GET', ['page' => 'distribution', 'id' => $a]);
    $corps = $r['corps'];
    check('distribution : titre avec son code', $r['code'] === 200 && strpos($corps, '<h1>Distribution APP-A</h1>') !== false
        && strpos($corps, '<title>Distribution APP-A - Licences ETDEL</title>') !== false);
    check('distribution : en-tete (code, libelle, produit de classement, etat)', resume_fiche($corps)
        === ['Code', 'Libelle', 'Produit', 'Etat']
        && strpos($corps, '<dt>Libelle</dt><dd>Client A</dd>') !== false
        && strpos($corps, '<dt>Produit</dt><dd>APP - Application test <span class="discret">(classement)</span></dd>') !== false
        && strpos($corps, '<dt>Etat</dt><dd><span class="etiquette active">Active</span></dd>') !== false);
    check('distribution : ligne installer() avec bouton Copier', strpos($corps, '<code id="installer">'
        . h(ligne_installer('APP', 'APP-A')) . '</code><button type="button" data-copier="installer">Copier</button>') !== false);
    check('distribution : liens vers ses licences et une nouvelle licence', strpos($corps, '<a class="bouton" href="index.php?'
        . 'page=licences&amp;distribution=' . $a . '">Voir ses licences (1)</a>') !== false
        && strpos($corps, '<a class="bouton principal" href="index.php?page=licence_nouvelle&amp;distribution=' . $a
            . '">Nouvelle licence pour cette distribution</a>') !== false);
    $liste = console($env, $session, 'GET', ['page' => 'licences', 'distribution' => $a])['corps'];
    check('voir ses licences : liste filtree sur la distribution', strpos($liste, 'Client de A') !== false
        && strpos($liste, 'Client de B') === false && strpos($liste, '<option value="' . $a . '" selected') !== false);
    $avance = strpos($corps, '<details class="avance"><summary>Reglages avances</summary>');
    check('distribution : regles essentielles, puis reglages avances replies, puis Dupliquer', dans_l_ordre([
        strpos($corps, '<h2>Regles</h2>'), strpos($corps, 'name="libelle" value="Client A"'), strpos($corps, 'name="client"'),
        strpos($corps, 'name="duree_defaut_j" value="365"'), strpos($corps, 'name="essai_j" value="15"'),
        strpos($corps, 'name="options" value="export_pdf"'), $avance, strpos($corps, 'name="tolerance_j" value="15"'),
        strpos($corps, 'name="preavis_j" value="5"'), strpos($corps, 'name="version_min" value=""'),
        strpos($corps, 'name="message" maxlength="500" rows="3">Bienvenue</textarea>'),
        strpos($corps, 'name="actif" value="1" checked> Active'), strpos($corps, '</details>'),
        strpos($corps, '>Enregistrer</button>'), strpos($corps, '<h2>Dupliquer</h2>')]));
    check('distribution : options, * = toutes', strpos($corps, 'Options (codes separes par des virgules, * = toutes)') !== false);
    check('distribution : libelle et client sans confusion avec le client d\'une licence', strpos($corps, '<label>Libelle '
        . '(ex. Edition cabinet, Demo)<input type="text" name="libelle"') !== false && strpos($corps, '<label>Client de cette '
        . 'distribution (facultatif, pour memoire)<input type="text" name="client"') !== false);
    check('distribution : copie a nommer (code vide, exemple)', strpos($corps, '<label>Code de la copie (definitif)<input '
        . 'type="text" name="code" value="" maxlength="64" required placeholder="ex. APP-CLIENTB"></label>') !== false
        && strpos($corps, 'COPIE') === false);
    check('distribution : formulaires et confirmations', formulaires_resumes(substr($corps, (int)strpos($corps, '<h2>Regles</h2>')))
        === ['distribution_enregistrer id=' . $a . ' libelle:text client:text duree_defaut_j:number essai_j:number options:text '
            . 'tolerance_j:number preavis_j:number version_min:text actif:checkbox [Enregistrer/principal]',
            'distribution_dupliquer id=' . $a . ' code:text [Dupliquer]']);
    check('distribution : ni code ni produit modifiables, pas de "variante"', strpos($corps, 'name="produit_id"') === false
        && strpos($corps, 'name="code" value="APP-A"') === false && stripos($corps, 'variante') === false);
    check('distribution : Distributions en evidence dans le menu', strpos($corps,
        '<a href="index.php?page=distributions" class="actif">Distributions</a>') !== false);
    // Aller-retour : le formulaire tel que le navigateur l'envoie ne change rien.
    $avant = db_ligne($db, 'SELECT * FROM distributions WHERE id = ?', [$a]);
    $champs = champs_formulaire($corps, 'distribution_enregistrer');
    $r = console($env, $session, 'POST', [], ['confirme' => '1'] + $champs);
    check('distribution : enregistrer sans rien changer ne change rien', $r['code'] === 303
        && $r['entetes']['Location'] === 'index.php?page=distribution&id=' . $a . '&ok=distribution'
        && db_ligne($db, 'SELECT * FROM distributions WHERE id = ?', [$a]) === $avant);
    check('distribution : message apres enregistrement', strpos(console($env, $session, 'GET', ['page' => 'distribution',
        'id' => $a, 'ok' => 'distribution'])['corps'], '<p class="succes">Distribution enregistree.</p>') !== false);
    $off = $env['dist']['APP-OFF'];
    $corps = console($env, $session, 'GET', ['page' => 'distribution', 'id' => $off])['corps'];
    check('distribution inactive : signalee, reglages avances deplies', strpos($corps, '<details class="avance" open>') !== false
        && strpos($corps, 'etiquette inactive">Inactive</span> <span class="discret">') !== false
        && strpos($corps, 'name="actif" value="1"> Active') !== false);
    check('distribution inactive : pas de nouvelle licence, une phrase a la place', strpos($corps, 'page=licence_nouvelle')
        === false && strpos($corps, '<p class="alerte">Distribution inactive : reactivez-la (Reglages avances, case Active) '
            . 'pour lui creer des licences.</p>') !== false
        && strpos($corps, 'href="index.php?page=licences&amp;distribution=' . $off . '">Voir ses licences (0)</a>') !== false);
    check('distribution active : pas de phrase "inactive"', strpos(console($env, $session, 'GET', ['page' => 'distribution',
        'id' => $a])['corps'], 'Distribution inactive') === false);
    $avant = db_ligne($db, 'SELECT * FROM distributions WHERE id = ?', [$off]);
    console($env, $session, 'POST', [], ['confirme' => '1'] + champs_formulaire($corps, 'distribution_enregistrer'));
    check('distribution inactive : aller-retour sans la reactiver', db_ligne($db, 'SELECT * FROM distributions WHERE id = ?',
        [$off]) === $avant);
    // Modification : regles et etat ; le code et le produit ne bougent pas.
    $autre = (int)db_valeur($db, "SELECT id FROM produits WHERE code = 'AUTRE'");
    $r = action($env, $session, 'distribution_enregistrer', ['id' => $a, 'produit_id' => $autre,
        'code' => 'PIRATE'] + regles_distribution(['libelle' => 'Client A2', 'client' => 'Canal', 'duree_defaut_j' => '',
        'essai_j' => 0, 'options' => '*', 'tolerance_j' => 3, 'preavis_j' => 1, 'version_min' => '2.0',
        'message' => 'Bonjour']));
    $d = db_ligne($db, 'SELECT * FROM distributions WHERE id = ?', [$a]);
    check('distribution : regles enregistrees', $d['libelle'] === 'Client A2' && $d['client'] === 'Canal'
        && $d['duree_defaut_j'] === null && (int)$d['essai_j'] === 0 && $d['options'] === '["*"]' && (int)$d['tolerance_j'] === 3
        && (int)$d['preavis_j'] === 1 && $d['version_min'] === '2.0' && $d['message'] === 'Bonjour' && (int)$d['actif'] === 1);
    check('distribution : code et produit definitifs (meme vers un produit existant)', $d['code'] === 'APP-A' && $autre > 0
        && (int)$d['produit_id'] === (int)db_valeur($db, "SELECT id FROM produits WHERE code = 'APP'"));
    check('distribution : journal', strpos((string)db_valeur($db, "SELECT detail FROM journal WHERE action = "
        . "'distribution_modifiee' AND cible = ? ORDER BY id DESC LIMIT 1", ['distribution ' . $a]), '"version_min":"2.0"') !== false);
    check('distribution : regles en clair a jour', strpos(ligne_distribution(console($env, $session, 'GET',
        ['page' => 'distributions'])['corps'], $a), 'Licence perpetuelle, pas d&#039;essai, toutes les options, hors ligne '
        . '3 jours, version minimale 2.0') !== false);
    check('distribution : version minimale appliquee par l\'API', appel($env, 'activer', ['cle' => $cle,
        'version' => '1.9'])['p']['code'] === 'version_trop_ancienne');
    $p = appel($env, 'activer', ['cle' => $cle, 'version' => '2.0'])['p'];
    check('distribution : regles appliquees par l\'API', $p['ok'] === true && $p['version_min'] === '2.0'
        && $p['options'] === ['*'] && $p['message'] === 'Bonjour' && $p['preavis_j'] === 1);
    check('distribution inconnue', console($env, $session, 'GET', ['page' => 'distribution', 'id' => 999])['code'] === 400
        && action($env, $session, 'distribution_enregistrer', ['id' => 999] + regles_distribution())['code'] === 400);
    // Dupliquer.
    $r = action($env, $session, 'distribution_dupliquer', ['id' => $a, 'code' => 'APP-A2']);
    $copie = (int)db_valeur($db, "SELECT id FROM distributions WHERE code = 'APP-A2'");
    check('dupliquer : vers la copie, avec message', $r['code'] === 303
        && $r['entetes']['Location'] === 'index.php?page=distribution&id=' . $copie . '&ok=dupliquee'
        && strpos(console($env, $session, 'GET', ['page' => 'distribution', 'id' => $copie, 'ok' => 'dupliquee'])['corps'],
            'Distribution dupliquee : verifiez ses reglages.') !== false);
    check('dupliquer : sans licence', (int)db_valeur($db, 'SELECT COUNT(*) FROM licences WHERE distribution_id = ?',
        [$copie]) === 0);
    check('dupliquer : client de l\'original non recopie', db_valeur($db, 'SELECT client FROM distributions WHERE id = ?',
        [$a]) === 'Canal' && db_valeur($db, 'SELECT client FROM distributions WHERE id = ?', [$copie]) === null);
}

/** Nouvelle distribution (D68) : produit existant ou nouveau produit, en une transaction. */
function test_console_nouvelle_distribution(): void
{
    $env = serveur();
    $session = [];
    $db = $env['db'];
    $app = (int)db_valeur($db, "SELECT id FROM produits WHERE code = 'APP'");
    $r = console($env, $session, 'GET', ['page' => 'distribution']);
    $corps = $r['corps'];
    $avance = strpos($corps, '<details class="avance"><summary>Reglages avances</summary>');
    check('nouvelle distribution : titre et phrase', $r['code'] === 200 && strpos($corps, '<h1>Nouvelle distribution</h1>')
        !== false && strpos($corps, 'Le produit sert seulement a ranger les distributions.') !== false);
    // Des produits existent : aucun choisi d'avance ("Nouveau produit" en dernier), choix exige par le navigateur.
    check('nouvelle distribution : produit a choisir, rien par defaut', strpos($corps, '<label>Produit (pour le '
        . 'classement)<select name="produit_id" data-produit required><option value="" selected>-- choisir un produit --'
        . '</option><option value="' . $app . '">APP - Application test</option>') !== false
        && strpos($corps, '<option value="0">Nouveau produit</option></select>') !== false
        && substr_count($corps, ' selected') === 1);
    check('nouvelle distribution : code et nom du nouveau produit, obligatoires (attributs poses par app.js)',
        strpos($corps, '<fieldset class="nouveau-produit" data-nouveau-produit><legend>Nouveau produit</legend><label>Code du '
        . 'nouveau produit (definitif, obligatoire)<input type="text" name="produit_code" value=""') !== false
        && strpos($corps, '<label>Nom du nouveau produit (obligatoire)<input type="text" name="produit_nom" value=""') !== false
        && preg_match('#<fieldset[^>]*>.*?\brequired\b.*?</fieldset>#s', $corps) === 0);
    check('nouvelle distribution : pourquoi les codes sont definitifs', strpos($corps, '<p class="discret">Le code du produit '
        . 'et le code de la distribution sont inscrits dans l\'application (ligne installer(...) donnee apres '
        . 'l\'enregistrement) : ils ne pourront plus changer. Exemple : produit MONAPPLI, distribution MONAPPLI-CLIENTA.'
        . '</p><fieldset') !== false);
    check('nouvelle distribution : produit, code definitif, regles, reglages avances', dans_l_ordre([
        strpos($corps, 'name="produit_id"'), strpos($corps, 'name="produit_code"'), strpos($corps, 'name="produit_nom"'),
        strpos($corps, '<label>Code de la distribution (definitif)<input type="text" name="code" value=""'),
        strpos($corps, 'name="libelle" value=""'), strpos($corps, 'name="client" value=""'),
        strpos($corps, 'name="duree_defaut_j" value="365"'), strpos($corps, 'name="essai_j" value="15"'),
        strpos($corps, 'name="options" value=""'), $avance, strpos($corps, 'name="tolerance_j" value="15"'),
        strpos($corps, 'name="preavis_j" value="5"'), strpos($corps, 'name="version_min" value=""'),
        strpos($corps, 'name="message"'), strpos($corps, 'name="actif" value="1" checked> Active'),
        strpos($corps, '</details>'), strpos($corps, '>Enregistrer</button>')]));
    check('nouvelle distribution : un formulaire, une confirmation', substr_count($corps, '<form method="post"') === 1
        && strpos($corps, 'data-confirmer="Enregistrer la distribution"') !== false && stripos($corps, 'variante') === false);
    check('nouvelle distribution : produit choisi d\'avance', strpos(console($env, $session, 'GET', ['page' => 'distribution',
        'produit' => $app])['corps'], '<option value="' . $app . '" selected>') !== false);

    // Avec un nouveau produit, a partir des champs de la page.
    $compter = static function () use ($db): array {
        return [(int)db_valeur($db, 'SELECT COUNT(*) FROM produits'), (int)db_valeur($db, 'SELECT COUNT(*) FROM distributions'),
            (int)db_valeur($db, 'SELECT COUNT(*) FROM journal')];
    };
    $champs = champs_formulaire($corps, 'distribution_enregistrer');
    check('nouvelle distribution : formulaire envoye tel quel, aucun produit choisi', ($champs['produit_id'] ?? null) === '');
    $champs = ['confirme' => '1', 'produit_id' => '0', 'produit_code' => 'NEUF', 'produit_nom' => 'Appli neuve',
        'code' => 'NEUF-1', 'libelle' => 'Standard', 'options' => 'export_pdf'] + $champs;
    $avant = $compter();
    $r = console($env, $session, 'POST', [], $champs);
    $p = db_ligne($db, "SELECT * FROM produits WHERE code = 'NEUF'");
    $d = db_ligne($db, "SELECT * FROM distributions WHERE code = 'NEUF-1'");
    check('nouveau produit et distribution : crees ensemble', $r['code'] === 303 && $p !== null && $d !== null
        && $r['entetes']['Location'] === 'index.php?page=distribution&id=' . $d['id'] . '&ok=creee'
        && $p['nom'] === 'Appli neuve' && (int)$p['actif'] === 1 && $p['version_min'] === null
        && (int)$d['produit_id'] === (int)$p['id'] && $compter() === [$avant[0] + 1, $avant[1] + 1, $avant[2] + 2]);
    check('nouvelle distribution : regles d\'un debutant', $d['libelle'] === 'Standard' && (int)$d['duree_defaut_j'] === 365
        && (int)$d['essai_j'] === 15 && (int)$d['tolerance_j'] === 15 && (int)$d['preavis_j'] === 5
        && $d['options'] === '["export_pdf"]' && (int)$d['actif'] === 1 && $d['version_min'] === null
        && $d['message'] === null && $d['client'] === null);
    check('nouvelle distribution : produit et distribution journalises', (int)db_valeur($db, "SELECT COUNT(*) FROM journal "
        . "WHERE acteur = 'admin' AND ((action = 'produit_cree' AND cible = ? AND detail = 'NEUF (Appli neuve)') "
        . "OR (action = 'distribution_creee' AND cible = ? AND detail = 'NEUF-1'))", ['produit ' . $p['id'],
        'distribution ' . $d['id']]) === 2);
    check('nouvelle distribution : message et ligne a copier', strpos(console($env, $session, 'GET', ['page' => 'distribution',
        'id' => $d['id'], 'ok' => 'creee'])['corps'], '<p class="succes">Distribution creee. Copiez la ligne ci-dessous dans '
        . 'l&#039;application.</p>') !== false);
    $api = appel($env, 'demander', ['produit' => 'NEUF', 'distribution' => 'NEUF-1', 'titulaire' => 'Visiteur',
        'email' => '', 'message' => '', 'jeton' => jeton_demande()])['p'];
    check('nouvelle distribution : utilisable aussitot par l\'application', $api['ok'] === true
        && $api['essai_jusqu'] === T0 + 15 * JOUR && $api['options'] === ['export_pdf']);

    // Avec un produit existant : aucun produit cree.
    $avant = $compter();
    $r = action($env, $session, 'distribution_enregistrer', ['id' => 0, 'produit_id' => $app, 'produit_code' => '',
        'produit_nom' => '', 'code' => 'APP-C'] + regles_distribution(['libelle' => 'Client C']));
    $c = db_ligne($db, "SELECT * FROM distributions WHERE code = 'APP-C'");
    check('produit existant : distribution rangee sous ce produit', $r['code'] === 303 && $c !== null
        && (int)$c['produit_id'] === $app && $compter() === [$avant[0], $avant[1] + 1, $avant[2] + 1]);

    // Refus : rien n'est cree ni journalise.
    $avant = $compter();
    $refus = [
        'code de distribution deja pris' => [['produit_id' => 0, 'produit_code' => 'ZZZ', 'produit_nom' => 'Z', 'code' => 'APP-A'],
            'Ce code de distribution existe deja. Rien n&#039;a ete cree.'],
        'nouveau produit deja existant' => [['produit_id' => 0, 'produit_code' => 'APP', 'produit_nom' => 'Doublon',
            'code' => 'APP-Z'], 'Le produit APP existe deja : choisissez-le dans la liste.'],
        'produit existant et nouveau a la fois' => [['produit_id' => $app, 'produit_code' => 'ZZZ', 'produit_nom' => '',
            'code' => 'APP-Z'], 'pas les deux'],
        'nouveau produit sans nom' => [['produit_id' => 0, 'produit_code' => 'ZZZ', 'produit_nom' => '', 'code' => 'ZZZ-1'],
            'Nom du nouveau produit : champ obligatoire.'],
        'aucun produit choisi' => [['produit_id' => '', 'produit_code' => '', 'produit_nom' => '', 'code' => 'ZZZ-1'],
            'Produit : choisissez-le dans la liste, ou remplissez le code et le nom du nouveau produit. Rien n&#039;a ete cree.'],
        'nouveau produit sans code' => [['produit_id' => 0, 'produit_code' => ' ', 'produit_nom' => 'Z', 'code' => 'ZZZ-1'],
            'Produit : choisissez-le dans la liste, ou remplissez le code et le nom du nouveau produit.'],
        'nouveau produit, meme code a la casse pres' => [['produit_id' => 0, 'produit_code' => 'app', 'produit_nom' => 'Doublon',
            'code' => 'ZZZ-1'], 'Le produit APP existe deja (meme code, autre casse) : choisissez-le dans la liste.'],
        'code de distribution, meme code a la casse pres' => [['produit_id' => $app, 'code' => 'app-a'],
            'Ce code de distribution existe deja (APP-A, meme code a la casse pres). Rien n&#039;a ete cree.'],
        'produit inconnu' => [['produit_id' => 999, 'code' => 'ZZZ-1'], 'Produit inconnu.'],
        'regle invalide' => [['produit_id' => 0, 'produit_code' => 'ZZZ', 'produit_nom' => 'Z', 'code' => 'ZZZ-1',
            'tolerance_j' => '400'], 'Tolerance : nombre entier'],
        'libelle manquant' => [['produit_id' => $app, 'code' => 'ZZZ-1', 'libelle' => ''], 'Libelle : champ obligatoire.'],
    ];
    foreach ($refus as $nom => [$champs, $message]) {
        $r = action($env, $session, 'distribution_enregistrer', $champs + ['id' => 0] + regles_distribution());
        check('nouvelle distribution refusee (' . $nom . ')', $r['code'] === 400 && strpos($r['corps'], $message) !== false);
    }
    check('nouvelle distribution : rien cree ni journalise apres un refus', $compter() === $avant
        && db_valeur($db, "SELECT COUNT(*) FROM produits WHERE code = 'ZZZ'") == 0);
    // Une seule transaction : une panne a l'insertion de la distribution n'y laisse pas le nouveau produit.
    $db->exec("CREATE TEMP TRIGGER panne BEFORE INSERT ON distributions BEGIN SELECT RAISE(ABORT, 'panne simulee'); END");
    $journal_php = ini_set('error_log', $env['tmp'] . '/php.log');
    $r = action($env, $session, 'distribution_enregistrer', ['id' => 0, 'produit_id' => 0, 'produit_code' => 'PANNE',
        'produit_nom' => 'Panne', 'code' => 'PANNE-1'] + regles_distribution());
    ini_set('error_log', (string)$journal_php);
    $db->exec('DROP TRIGGER panne');
    check('nouvelle distribution : panne, ni produit ni journal (une transaction)', $r['code'] === 500
        && $compter() === $avant && db_valeur($db, "SELECT COUNT(*) FROM produits WHERE code = 'PANNE'") == 0);
}

function test_console_menu(): void
{
    $env = serveur();
    $session = [];
    $corps = console($env, $session, 'GET', [])['corps'];
    check('accueil : titre', strpos($corps, '<title>Accueil - Licences ETDEL</title>') !== false
        && strpos($corps, '<h1>Accueil</h1>') !== false);
    check('menu : la marque mene a l\'accueil', strpos($corps, '<a class="marque" href="index.php?page=tableau">Licences ETDEL</a>')
        !== false);
    $nav = preg_match('#<nav>(.*?)</nav>#s', $corps, $m) === 1 ? $m[1] : '';
    preg_match_all('#<a href="index\.php\?page=([a-z_]+)"[^>]*>([^<]*)#', $nav, $entrees);
    check('menu reduit : six entrees dans l\'ordre', $entrees[1] === ['tableau', 'demandes', 'licences', 'distributions',
        'aide', 'administration'] && array_map('trim', $entrees[2]) === ['Accueil', 'Demandes', 'Licences', 'Distributions',
        'Aide', 'Administration']);
    check('menu : plus d\'entree Produits', strpos($nav, 'Produits') === false && strpos($nav, 'page=produits') === false);
    check('menu : Accueil en evidence', strpos($nav, '<a href="index.php?page=tableau" class="actif">Accueil</a>') !== false);
    check('accueil : aucune demande en attente', strpos($corps, 'Aucune demande en attente.') !== false);
    check('accueil : demandes, raccourcis, compteurs, echeances', dans_l_ordre([strpos($corps, '<h2>Demandes en attente</h2>'),
        strpos($corps, '<p class="raccourcis"><a class="bouton principal" href="index.php?page=licence_nouvelle">Nouvelle '
            . 'licence</a><a class="bouton" href="index.php?page=distribution">Nouvelle distribution</a></p>'),
        strpos($corps, '<div class="cartes">'), strpos($corps, '<h2>Licences expirant sous 30 jours</h2>')]));
    check('accueil : plus de "Nouveau produit"', strpos($corps, 'Nouveau produit') === false);
    check('accueil : compteurs vers les listes filtrees', strpos($corps, '<a class="carte" href="index.php?page=licences&amp;'
        . 'statut=suspendue"><strong>0</strong><span>Licences suspendues</span></a>') !== false);
    foreach (['administration', 'serveurs', 'cles', 'journal', 'sauvegarde', 'reglages'] as $page) {
        check('menu : Administration en evidence sur ' . $page, strpos(console($env, $session, 'GET', ['page' => $page])['corps'],
            '<a href="index.php?page=administration" class="actif">Administration</a>') !== false);
    }
    $cle = creer_licence($env);
    foreach ([['licence_nouvelle', 'licences'], ['licence', 'licences', (int)licence($env, $cle)['id']],
              ['distributions', 'distributions'], ['produits', 'distributions'], ['produit', 'distributions'],
              ['distribution', 'distributions'], ['distribution', 'distributions', $env['dist']['APP-A']]] as $cas) {
        $get = ['page' => $cas[0]] + (isset($cas[2]) ? ['id' => $cas[2]] : []);
        check('menu : ' . $cas[1] . ' en evidence sur ' . http_build_query($get), strpos(console($env, $session, 'GET',
            $get)['corps'], '<a href="index.php?page=' . $cas[1] . '" class="actif">') !== false);
    }
    $corps = console($env, $session, 'GET', ['page' => 'administration'])['corps'];
    check('administration : titre', strpos($corps, '<title>Administration - Licences ETDEL</title>') !== false
        && strpos($corps, '<h1>Administration</h1>') !== false);
    foreach (['serveurs' => 'Serveurs', 'cles' => 'Cles de signature', 'journal' => 'Journal', 'sauvegarde' => 'Sauvegarde',
              'reglages' => 'Reglages'] as $page => $titre) {
        check('administration : ' . $titre . ' avec une phrase', preg_match('#<a class="carte lien" href="index\.php\?page='
            . $page . '"><strong>' . $titre . '</strong><span>[^<]{30,}</span></a>#', $corps) === 1);
    }
    check('administration : serveurs = adresses de l\'API', strpos($corps, 'Adresses de l&#039;API diffusees aux applications')
        !== false);
    check('administration : controle d\'exposition deplace ici', strpos($corps, '<h2>Controle d\'exposition</h2>') !== false
        && strpos($corps, '<ul id="exposition" data-chemins="') !== false && strpos($corps, '../prive/config.php') !== false);
    check('accueil : ordinateurs vus', strpos(console($env, $session, 'GET', [])['corps'],
        '<span>Ordinateurs vus sur 7 jours</span>') !== false);
    // Page affichee en reponse a un POST (cle, erreur, confirmation sans JavaScript) : l'entree de l'action.
    $id = (int)licence($env, $cle)['id'];
    $actif = static function (array $r): string {
        return preg_match('#<a href="index\.php\?page=([a-z_]+)" class="actif">#', $r['corps'], $m) === 1 ? $m[1] : '';
    };
    $cas = [
        'nouvelle cle (cle affichee)' => [action($env, $session, 'licence_nouvelle_cle', ['id' => $id]), 'licences'],
        'confirmation sans JavaScript' => [console($env, $session, 'POST', [], ['action' => 'licence_revoquer',
            'id' => (string)$id, 'csrf' => csrf_jeton($session), 'confirme' => '0']), 'licences'],
        'erreur sur une distribution' => [action($env, $session, 'distribution_enregistrer', ['id' => 999]
            + regles_distribution()), 'distributions'],
        'erreur sur un produit' => [action($env, $session, 'produit_enregistrer', ['id' => 999, 'nom' => 'X']),
            'distributions'],
        'erreur sur une demande' => [action($env, $session, 'demande_refuser', ['id' => 999]), 'demandes'],
        'e-mail de test' => [action($env, $session, 'email_test'), 'administration'],
        'action inconnue' => [action($env, $session, 'supprimer_tout'), ''],
    ];
    foreach ($cas as $nom => [$r, $attendu]) {
        check('menu apres un POST (' . $nom . ') : ' . ($attendu === '' ? 'aucune entree' : $attendu) . ' en evidence',
            $actif($r) === $attendu);
    }
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
    check('navigation : ecran Cles, sous Administration', strpos($r['corps'],
        '<a href="index.php?page=administration" class="actif">Administration</a>') !== false
        && strpos(console($env, $session, 'GET', ['page' => 'administration'])['corps'], 'href="index.php?page=cles"') !== false);
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
        'distributions' => 'produits', 'produits' => 'produits', 'produit' => 'produits', 'distribution' => 'produits',
        'administration' => 'securite',
        'serveurs' => 'serveurs', 'cles' => 'cles', 'journal' => 'journal', 'sauvegarde' => 'sauvegarde',
        'reglages' => 'reglages'];
    foreach ($pages as $page => $ancre) {
        $r = console($env, $session, 'GET', ['page' => $page]);
        check('aide : menu sur ' . $page, strpos($r['corps'], 'href="index.php?page=aide"') !== false);
        check('aide : lien contextuel ' . $page . ' vers #' . $ancre, strpos($r['corps'],
            '<a class="aide-lien" href="index.php?page=aide#' . $ancre . '">Aide sur cet ecran</a>') !== false);
    }
    check('aide : lien contextuel par defaut (accueil)',
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
    'test_console_fiche_licence', 'test_console_nouvelle_cle', 'test_console_nouvelle_licence_modele',
    'test_console_distributions_serveurs', 'test_console_reglages_exports', 'test_console_ecran_distributions',
    'test_console_page_distribution', 'test_console_nouvelle_distribution', 'test_console_menu',
    'test_rotation',
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
