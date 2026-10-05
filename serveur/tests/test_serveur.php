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

require RACINE . '/prive/lib/api.php';
require RACINE . '/prive/lib/installation.php';

$failures = [];
$verifications = 0;

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
    ], $surcharges), realpath(RACINE . '/prive'));
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
        check('strict_types : ' . $nom, $nom === 'config.exemple.php' || strpos($contenu, 'declare(strict_types=1);') !== false);
    }
    check('fichiers PHP trouves', $php >= 10);
    $prive = file_get_contents(RACINE . '/prive/.htaccess');
    check('prive/.htaccess : Require all denied', strpos($prive, 'Require all denied') !== false);
    $admin = file_get_contents(RACINE . '/www/admin/.htaccess');
    check('admin/.htaccess du depot : console fermee avant installation', strpos($admin, 'Require all denied') !== false);
    $www = file_get_contents(RACINE . '/www/.htaccess');
    check('www/.htaccess : pas de listing', strpos($www, 'Options -Indexes') !== false);
    check('www/.htaccess : HTTPS force', strpos($www, 'RewriteRule ^ https://') !== false);
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
    check('installation : cle privee hors www, 0600', is_file($fichier_cle) && (fileperms($fichier_cle) & 0777) === 0600);
    check('installation : secret des demandes', strlen(secret_demandes($env['config'])) === 32);
    $htpasswd = file_get_contents($env['config']['htpasswd']);
    check('installation : .htpasswd bcrypt $2y$', preg_match('/^admin:\$2y\$/', $htpasswd) === 1);
    check('installation : mot de passe verifiable',
        password_verify('un mot de passe tres long 2026', substr(trim($htpasswd), strlen('admin:'))));
    $htaccess = file_get_contents($env['tmp'] . '/www/admin/.htaccess');
    check('installation : admin/.htaccess AuthType Basic', strpos($htaccess, 'AuthType Basic') !== false
        && strpos($htaccess, 'Require valid-user') !== false);
    check('installation : AuthUserFile absolu', strpos($htaccess, 'AuthUserFile "' . $env['config']['htpasswd'] . '"') !== false
        && $env['config']['htpasswd'][0] === '/');
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

$tests = ['test_fichiers', 'test_formats', 'test_installation', 'test_ping_et_requetes_invalides', 'test_activer_valider',
    'test_statuts_versions_surcharges', 'test_demandes', 'test_notifications', 'test_limites', 'test_sauvegarde',
    'test_signature'];
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
