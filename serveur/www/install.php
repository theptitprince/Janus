<?php
// install.php - Assistant d'installation, a executer une seule fois depuis le navigateur :
//               cree la base, la paire de cles de signature, le .htpasswd de la console,
//               protege /admin/ puis se verrouille (refuse toute nouvelle execution).
// ETDEL (c) 2026

declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function install_page(int $code, string $titre, string $corps): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Installation - Licences ETDEL</title></head><body>'
        . '<h1>Licences ETDEL - installation</h1><h2>' . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . '</h2>'
        . $corps . '</body></html>';
    exit;
}

$prive = null;
foreach (array_merge(array_filter([getenv('ETDEL_PRIVE') ?: null]), [dirname(__DIR__) . '/prive', __DIR__ . '/prive'])
         as $candidat) {
    if (is_file($candidat . '/lib/installation.php')) {
        $prive = realpath($candidat) ?: $candidat;
        break;
    }
}
if ($prive === null) {
    install_page(500, 'Dossier prive introuvable',
        '<p>Envoyer le dossier <code>prive/</code> a cote du dossier <code>www/</code> '
        . '(ou dans <code>www/</code> si l\'hebergement l\'impose), puis recharger cette page.</p>');
}
require $prive . '/lib/installation.php';
if (!is_file($prive . '/config.php')) {
    install_page(500, 'Configuration absente',
        '<p>Copier <code>prive/config.exemple.php</code> en <code>prive/config.php</code>, y inscrire un '
        . '<code>jeton_installation</code> d\'au moins 20 caracteres, envoyer le fichier puis recharger cette page.</p>');
}
try {
    $config = config_charger($prive);
} catch (Throwable $e) {
    install_page(500, 'Configuration illisible', '<p>' . h($e->getMessage()) . '</p>');
}
if (installation_verrouillee($config)) {
    if (!reinitialisation_ouverte($config)) {
        install_page(403, 'Installation deja effectuee',
            '<p>Cet assistant est verrouille et refuse toute nouvelle execution.</p>'
            . '<p>Console d\'administration : <a href="admin/">admin/</a></p>');
    }
    // Seule action possible une fois verrouille : reecrire le .htpasswd (mot de passe perdu).
    $message = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try {
            reinitialisation_executer($config, (string)($_POST['jeton'] ?? ''), trim((string)($_POST['utilisateur'] ?? '')),
                (string)($_POST['mot_de_passe'] ?? ''), (string)($_POST['confirmation'] ?? ''), ip_client(), time());
            install_page(200, 'Mot de passe reinitialise',
                '<p>Le mot de passe de la console est change. Remettre <code>jeton_reinitialisation</code> a vide dans '
                . 'config.php.</p><p><a href="admin/">Console d\'administration</a></p>');
        } catch (Throwable $e) {
            // Ralentit les essais de jeton ; les jetons font 20 caracteres au moins.
            sleep(1);
            $message = '<ul><li>' . h($e->getMessage()) . '</li></ul>';
        }
    }
    install_page(200, 'Reinitialiser le mot de passe de la console', $message
        . '<p>L\'installation est verrouillee. Ce formulaire, ouvert par <code>jeton_reinitialisation</code> dans '
        . 'config.php, ne fait que remplacer le mot de passe de la console.</p>'
        . '<form method="post" action="install.php">'
        . '<p><label for="jeton">Jeton de reinitialisation (config.php)</label><br>'
        . '<input id="jeton" name="jeton" type="password" size="50"></p>'
        . '<p><label for="utilisateur">Identifiant</label><br>'
        . '<input id="utilisateur" name="utilisateur" type="text" value="etienne" size="50"></p>'
        . '<p><label for="mot_de_passe">Nouveau mot de passe (' . MOT_DE_PASSE_MIN . ' caracteres minimum)</label><br>'
        . '<input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="new-password" size="50"></p>'
        . '<p><label for="confirmation">Confirmation</label><br>'
        . '<input id="confirmation" name="confirmation" type="password" autocomplete="new-password" size="50"></p>'
        . '<p><button type="submit">Reinitialiser</button></p></form>');
}
$problemes = installation_prealables($config);
if ($problemes !== []) {
    $liste = '';
    foreach ($problemes as $probleme) {
        $liste .= '<li>' . h($probleme) . '</li>';
    }
    install_page(500, 'Installation impossible', '<ul>' . $liste . '</ul>');
}

// Adresse proposee : celle par laquelle on consulte l'assistant.
$hote = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$chemin = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/');
$valeurs = [
    'utilisateur' => 'etienne',
    'url' => ($https || !preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/', $hote) ? 'https://' : 'http://')
        . $hote . $chemin . '/api/v1/',
    'url_secours' => '',
    'email_notification' => '',
];
$erreurs = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach (array_keys($valeurs) as $champ) {
        $valeurs[$champ] = trim((string)($_POST[$champ] ?? ''));
    }
    $parametres = $valeurs + [
        'mot_de_passe' => (string)($_POST['mot_de_passe'] ?? ''),
        'confirmation' => (string)($_POST['confirmation'] ?? ''),
    ];
    if (!hash_equals((string)$config['jeton_installation'], (string)($_POST['jeton'] ?? ''))) {
        sleep(1);
        $erreurs[] = 'Jeton d\'installation incorrect.';
    } else {
        $erreurs = installation_erreurs($parametres);
    }
    if ($erreurs === []) {
        try {
            $resultat = installation_executer($config, $parametres, __DIR__ . '/admin', time());
            $lignes = 'LICENCE_URL = "' . $resultat['urls'][0] . "\"\n"
                . 'LICENCE_URL_SECOURS = "' . ($resultat['urls'][1] ?? '') . "\"\n"
                . 'LICENCE_CLE_PUBLIQUE = "' . $resultat['cle_publique'] . "\"\n";
            $avertissement = strpos($prive, (string)realpath(__DIR__)) === 0
                ? '<p><strong>Attention :</strong> le dossier prive est dans www/. Il est protege par .htaccess ; '
                . 'verifier dans le tableau de bord de la console que la base et les cles ne sont pas '
                . 'telechargeables.</p>' : '';
            install_page(200, 'Installation terminee',
                '<p>Base, cle de signature (kid 1), secret des demandes et acces a la console sont crees. '
                . 'L\'assistant est maintenant verrouille.</p>' . $avertissement
                . '<p>Cle publique : <code id="cle">' . h($resultat['cle_publique']) . '</code></p>'
                . '<p>Lignes a reporter une fois en tete de <code>etdel_licence.py</code> :</p>'
                . '<pre>' . h($lignes) . '</pre>'
                . '<p>Ensuite : creer la tache planifiee OVH de sauvegarde quotidienne '
                . '(<code>prive/sauvegarde.php</code>), puis ouvrir la '
                . '<a href="admin/">console d\'administration</a>.</p>');
        } catch (Throwable $e) {
            $erreurs[] = 'Echec de l\'installation : ' . $e->getMessage();
        }
    }
}

$messages = '';
foreach ($erreurs as $erreur) {
    $messages .= '<li>' . h($erreur) . '</li>';
}
$champ = static function (string $nom, string $libelle, string $type, string $valeur, string $aide = ''): string {
    return '<p><label for="' . $nom . '">' . h($libelle) . '</label><br>'
        . '<input id="' . $nom . '" name="' . $nom . '" type="' . $type . '" value="' . h($valeur) . '" size="50"'
        . ($type === 'password' ? ' autocomplete="new-password"' : '') . '>'
        . ($aide !== '' ? '<br><small>' . h($aide) . '</small>' : '') . '</p>';
};
install_page(200, 'Parametres',
    ($messages !== '' ? '<ul>' . $messages . '</ul>' : '')
    . '<form method="post" action="install.php">'
    . $champ('jeton', 'Jeton d\'installation (config.php)', 'password', '')
    . $champ('utilisateur', 'Identifiant de la console', 'text', $valeurs['utilisateur'])
    . $champ('mot_de_passe', 'Mot de passe de la console', 'password', '', MOT_DE_PASSE_MIN . ' caracteres minimum')
    . $champ('confirmation', 'Mot de passe (confirmation)', 'password', '')
    . $champ('url', 'URL de l\'API', 'url', $valeurs['url'])
    . $champ('url_secours', 'URL de secours (facultative)', 'url', $valeurs['url_secours'],
        'Second domaine ou sous-domaine pointant vers ce serveur')
    . $champ('email_notification', 'Adresse(s) de notification (facultatives)', 'text',
        $valeurs['email_notification'], 'Separees par des virgules')
    . '<p><button type="submit">Installer</button></p></form>');
