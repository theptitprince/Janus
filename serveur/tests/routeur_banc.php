<?php
// routeur_banc.php - Routeur du banc d'essai local (php -S), jamais deploye sur OVH.
//                    Reproduit ce que fait Apache : authentification Basic de /admin/
//                    contre le .htpasswd cree par install.php (REMOTE_USER), et refus
//                    des fichiers que les .htaccess protegent.
//                    Usage : php -S 127.0.0.1:8080 -t <dossier>/www serveur/tests/routeur_banc.php
// ETDEL (c) 2026

declare(strict_types=1);

$racine = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
$brut = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Chemin tel que le systeme de fichiers le resoudra : sous Windows, casse
// indifferente, antislash, points et espaces finaux ignores (/PRIVE./x) ;
// l'encodage %xx est decode jusqu'a stabilite.
do {
    $avant = $brut;
    $brut = rawurldecode($brut);
} while ($brut !== $avant);
$segments = [];
foreach (explode('/', strtolower(str_replace('\\', '/', $brut))) as $segment) {
    $segments[] = rtrim($segment, '. ');
}
$chemin = implode('/', $segments);

$interdit = strpos($chemin, ':') !== false
    || preg_match('#\.(db|db-wal|db-shm|key|sql|verrou|json|log|bak)$#', $chemin) === 1;
foreach ($segments as $segment) {
    if ($segment === 'prive' || $segment === 'data' || strncmp($segment, '.ht', 3) === 0) {
        $interdit = true;
    }
}
if ($interdit) {
    http_response_code(403);
    echo "Interdit\n";
    return true;
}
if (strpos($chemin, '/admin/') !== 0 && $chemin !== '/admin') {
    return false;
}

$prive = getenv('ETDEL_PRIVE') ?: (is_dir(dirname($racine) . '/prive') ? dirname($racine) . '/prive' : $racine . '/prive');
require $prive . '/lib/commun.php';
$config = config_charger($prive);
$utilisateur = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
$mot_de_passe = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
$autorise = false;
if (is_file((string)$config['htpasswd'])) {
    foreach (file((string)$config['htpasswd'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
        [$nom, $hachage] = array_pad(explode(':', $ligne, 2), 2, '');
        if ($utilisateur !== '' && hash_equals($nom, $utilisateur) && password_verify($mot_de_passe, $hachage)) {
            $autorise = true;
        }
    }
}
if (!$autorise) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Console licences ETDEL"');
    echo "Authentification requise\n";
    return true;
}
if (preg_match('#^/admin/(index\.php)?$#', $chemin) !== 1) {
    return false;
}
// Comme Apache apres une authentification reussie.
$_SERVER['REMOTE_USER'] = $utilisateur;
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
$_SERVER['SCRIPT_NAME'] = '/admin/index.php';
require $racine . '/admin/index.php';
return true;
