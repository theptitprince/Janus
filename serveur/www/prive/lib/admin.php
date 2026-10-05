<?php
// admin.php - Console d'administration : ecrans, actions d'ecriture (confirmation,
//             CSRF, journal avec l'utilisateur .htpasswd), exports CSV, sauvegarde.
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/demandes.php';
require_once __DIR__ . '/notification.php';
require_once __DIR__ . '/installation.php';
require_once __DIR__ . '/csrf.php';

/** Erreur de saisie affichable telle quelle a l'administrateur. */
class AdminErreur extends RuntimeException
{
}

// Libelles des actions d'ecriture : servent a la confirmation et au journal.
const ADMIN_ACTIONS = [
    'demande_accepter' => 'Accepter la demande et creer la cle',
    'demande_refuser' => 'Refuser la demande',
    'licence_creer' => 'Creer une cle de licence',
    'licence_prolonger' => 'Prolonger la licence',
    'licence_modifier' => 'Modifier la licence',
    'licence_suspendre' => 'Suspendre la licence',
    'licence_reactiver' => 'Reactiver la licence',
    'licence_revoquer' => 'Revoquer definitivement la licence',
    'licence_liberer' => 'Liberer le poste (changement d\'ordinateur)',
    'produit_enregistrer' => 'Enregistrer le produit',
    'distribution_enregistrer' => 'Enregistrer la distribution',
    'distribution_dupliquer' => 'Dupliquer la distribution',
    'url_ajouter' => 'Ajouter l\'URL',
    'url_modifier' => 'Modifier l\'URL',
    'reglages_enregistrer' => 'Enregistrer les reglages',
    'email_test' => 'Envoyer un e-mail de test',
    'mot_de_passe' => 'Changer le mot de passe',
    'cle_rotation' => 'Generer une nouvelle cle de signature (rotation, irreversible)',
    'sauvegarde_telecharger' => 'Telecharger une copie de la base',
];

// Actions sans renouvellement du jeton CSRF : la page reste affichee apres un
// telechargement, son formulaire doit rester utilisable.
const ADMIN_ACTIONS_SANS_RENOUVELLEMENT = ['sauvegarde_telecharger'];

// Messages apres redirection : seul un code passe dans l'URL, jamais une donnee.
const ADMIN_MESSAGES = [
    'refusee' => 'Demande refusee.',
    'prolongee' => 'Licence prolongee.',
    'modifiee' => 'Licence modifiee.',
    'suspendue' => 'Licence suspendue.',
    'reactivee' => 'Licence reactivee.',
    'revoquee' => 'Licence revoquee.',
    'liberee' => 'Poste libere : la cle peut etre activee sur un autre ordinateur.',
    'produit' => 'Produit enregistre.',
    'distribution' => 'Distribution enregistree.',
    'dupliquee' => 'Distribution dupliquee : verifiez ses reglages.',
    'url' => 'Liste des URL mise a jour.',
    'reglages' => 'Reglages enregistres.',
    'rotation' => 'Nouvelle cle de signature active : les postes l\'adoptent a leur prochain controle.',
];

const ADMIN_PAR_PAGE = 100;
const MESSAGE_ACCUEIL_MAX = 500;

// ---------------------------------------------------------------------------
// Point d'entree et reponses
// ---------------------------------------------------------------------------

/**
 * Utilisateur authentifie par Apache (.htpasswd) ; chaine vide si aucun.
 * Jamais PHP_AUTH_USER : PHP le remplit depuis l'en-tete du client meme quand
 * Apache n'a rien verifie (console sans .htaccess actif).
 */
function admin_utilisateur(array $serveur): string
{
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $cle) {
        $valeur = $serveur[$cle] ?? '';
        if (is_string($valeur) && utilisateur_valide($valeur)) {
            return $valeur;
        }
    }
    return '';
}

function admin_point_entree(string $prive): void
{
    $utilisateur = admin_utilisateur($_SERVER);
    // Defense en profondeur : sans authentification Apache, rien n'est servi.
    if ($utilisateur === '') {
        admin_envoyer(admin_page_brute(403, 'Acces refuse', 'Authentification requise (.htaccess / .htpasswd).'));
        return;
    }
    try {
        $config = config_charger($prive);
        $db = db_ouvrir((string)$config['base']);
    } catch (Throwable $e) {
        admin_envoyer(admin_page_brute(503, 'Console indisponible', 'Base ou configuration absente : lancer install.php.'));
        return;
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    session_name('etdel_csrf');
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/') . '/',
        'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $reponse = admin_traiter([
        'db' => $db,
        'config' => $config,
        'methode' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'get' => $_GET,
        'post' => $_POST,
        'utilisateur' => $utilisateur,
        'ip' => ip_client(),
        'origine' => isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : null,
        'hote' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'maintenant' => time(),
    ], $_SESSION);
    session_write_close();
    admin_envoyer($reponse);
}

function admin_entetes(): array
{
    return [
        'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        // same-origin et non no-referrer : avec no-referrer, le navigateur envoie "Origin: null"
        // sur les POST de formulaire, et le controle d'origine (CSRF) les refuserait.
        'Referrer-Policy' => 'same-origin',
        'Strict-Transport-Security' => 'max-age=31536000',
        'Cache-Control' => 'no-store',
    ];
}

function admin_envoyer(array $reponse): void
{
    http_response_code($reponse['code']);
    foreach ($reponse['entetes'] as $nom => $valeur) {
        header($nom . ': ' . $valeur);
    }
    if (isset($reponse['fichier'])) {
        // Copie complete de la base : supprimee meme si le telechargement est interrompu.
        $fichier = $reponse['fichier'];
        ignore_user_abort(true);
        register_shutdown_function(static function () use ($fichier): void {
            @unlink($fichier);
        });
        readfile($fichier);
        @unlink($fichier);
        return;
    }
    echo $reponse['corps'];
}

function admin_page_brute(int $code, string $titre, string $texte): array
{
    return ['code' => $code, 'entetes' => admin_entetes() + ['Content-Type' => 'text/html; charset=UTF-8'],
        'corps' => '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . h($titre)
            . '</title></head><body><h1>' . h($titre) . '</h1><p>' . h($texte) . '</p></body></html>'];
}

function admin_redirection(string $cible): array
{
    return ['code' => 303, 'entetes' => admin_entetes() + ['Location' => $cible], 'corps' => ''];
}

/** Traite une requete ; $session ne sert qu'au jeton CSRF. */
function admin_traiter(array $ctx, array &$session): array
{
    if (($ctx['utilisateur'] ?? '') === '') {
        return admin_page_brute(403, 'Acces refuse', 'Authentification requise (.htaccess / .htpasswd).');
    }
    $ctx['csrf'] = csrf_jeton($session);
    try {
        if ($ctx['methode'] === 'POST') {
            return admin_post($ctx, $session);
        }
        if ($ctx['methode'] !== 'GET') {
            return admin_page_brute(405, 'Methode refusee', 'GET ou POST uniquement.');
        }
        return admin_get($ctx);
    } catch (AdminErreur | InvalidArgumentException $e) {
        return admin_page($ctx, 'Action impossible', '<p class="erreur">' . h($e->getMessage()) . '</p>'
            . '<p><a href="index.php" data-retour>Retour</a></p>', 400);
    } catch (PDOException $e) {
        error_log('etdel console : ' . $e->getMessage());
        return admin_page($ctx, 'Erreur', '<p class="erreur">Erreur de la base de donnees.</p>', 500);
    } catch (RuntimeException $e) {
        return admin_page($ctx, 'Action impossible', '<p class="erreur">' . h($e->getMessage()) . '</p>'
            . '<p><a href="index.php" data-retour>Retour</a></p>', 409);
    } catch (Throwable $e) {
        error_log('etdel console : ' . $e->getMessage());
        return admin_page_brute(500, 'Erreur', 'Erreur interne de la console.');
    }
}

function admin_get(array $ctx): array
{
    $page = (string)($ctx['get']['page'] ?? 'tableau');
    $id = (int)($ctx['get']['id'] ?? 0);
    switch ($page) {
        case 'tableau':
            return admin_page($ctx, 'Tableau de bord', admin_ecran_tableau($ctx));
        case 'demandes':
            return admin_page($ctx, 'Demandes', admin_ecran_demandes($ctx));
        case 'demande':
            return admin_page($ctx, 'Demande n. ' . $id, admin_ecran_demande($ctx, $id));
        case 'licences':
            return admin_page($ctx, 'Licences', admin_ecran_licences($ctx));
        case 'licence':
            return admin_page($ctx, 'Licence n. ' . $id, admin_ecran_licence($ctx, $id));
        case 'licence_nouvelle':
            return admin_page($ctx, 'Nouvelle cle', admin_ecran_licence_nouvelle($ctx));
        case 'produits':
            return admin_page($ctx, 'Produits et distributions', admin_ecran_produits($ctx));
        case 'produit':
            return admin_page($ctx, $id ? 'Produit' : 'Nouveau produit', admin_ecran_produit($ctx, $id));
        case 'distribution':
            return admin_page($ctx, $id ? 'Distribution' : 'Nouvelle distribution', admin_ecran_distribution($ctx, $id));
        case 'serveurs':
            return admin_page($ctx, 'Serveurs', admin_ecran_serveurs($ctx));
        case 'journal':
            return admin_page($ctx, 'Journal', admin_ecran_journal($ctx));
        case 'sauvegarde':
            return admin_page($ctx, 'Sauvegarde', admin_ecran_sauvegarde($ctx));
        case 'reglages':
            return admin_page($ctx, 'Reglages', admin_ecran_reglages($ctx));
        case 'cles':
            return admin_page($ctx, 'Cles de signature', admin_ecran_cles($ctx));
        case 'export':
            return admin_export($ctx, (string)($ctx['get']['quoi'] ?? ''));
    }
    return admin_page($ctx, 'Page inconnue', '<p>Cette page n\'existe pas.</p>', 404);
}

function admin_post(array $ctx, array &$session): array
{
    $erreur = csrf_verifier($session, $ctx['post']['csrf'] ?? null, $ctx['origine'], $ctx['hote']);
    if ($erreur !== null) {
        return admin_page($ctx, 'Requete refusee', '<p class="erreur">' . h($erreur) . '</p>', 403);
    }
    $action = (string)($ctx['post']['action'] ?? '');
    if (!isset(ADMIN_ACTIONS[$action])) {
        return admin_page($ctx, 'Action inconnue', '<p class="erreur">Action inconnue.</p>', 400);
    }
    // Changement de mot de passe : la double saisie tient lieu de confirmation ;
    // la page de confirmation sans JavaScript recopierait sinon le mot de passe
    // en clair dans des champs caches.
    if (($ctx['post']['confirme'] ?? '') !== '1' && $action !== 'mot_de_passe') {
        return admin_page($ctx, 'Confirmation', admin_ecran_confirmation($ctx, $action));
    }
    $fonction = 'admin_action_' . $action;
    $reponse = $fonction($ctx);
    // Jeton renouvele apres une ecriture reussie : un renvoi du formulaire est refuse.
    if (!in_array($action, ADMIN_ACTIONS_SANS_RENOUVELLEMENT, true)) {
        $ctx['csrf'] = csrf_renouveler($session);
    }
    return is_array($reponse) ? $reponse : admin_page($ctx, ADMIN_ACTIONS[$action], (string)$reponse);
}

function admin_journal(array $ctx, string $action, ?string $cible, ?string $detail): void
{
    journal_ecrire($ctx['db'], $ctx['utilisateur'], $action, $cible, $detail, $ctx['ip'], $ctx['maintenant']);
}

// ---------------------------------------------------------------------------
// Gabarit et formulaires
// ---------------------------------------------------------------------------

function admin_page(array $ctx, string $titre, string $contenu, int $code = 200): array
{
    $attente = (int)db_valeur($ctx['db'], "SELECT COUNT(*) FROM demandes WHERE statut = 'en_attente'");
    $liens = [
        'tableau' => 'Tableau de bord',
        'demandes' => 'Demandes' . ($attente > 0 ? ' <span class="pastille">' . $attente . '</span>' : ''),
        'licences' => 'Licences',
        'produits' => 'Produits',
        'serveurs' => 'Serveurs',
        'cles' => 'Cles',
        'journal' => 'Journal',
        'sauvegarde' => 'Sauvegarde',
        'reglages' => 'Reglages',
    ];
    $courante = (string)($ctx['get']['page'] ?? 'tableau');
    $nav = '';
    foreach ($liens as $page => $libelle) {
        $nav .= '<a href="index.php?page=' . $page . '"' . ($page === $courante ? ' class="actif"' : '') . '>' . $libelle . '</a>';
    }
    $bandeau = '';
    $ok = (string)($ctx['get']['ok'] ?? '');
    if ($ctx['methode'] === 'GET' && isset(ADMIN_MESSAGES[$ok])) {
        $bandeau = '<p class="succes">' . h(ADMIN_MESSAGES[$ok]) . '</p>';
    }
    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($titre) . ' - Licences ETDEL</title>'
        . '<link rel="stylesheet" href="style.css"><script src="app.js" defer></script></head><body>'
        . '<header><a class="marque" href="index.php">Licences ETDEL</a><nav>' . $nav . '</nav>'
        . '<span class="utilisateur">' . h($ctx['utilisateur']) . '</span></header>'
        . '<main><h1>' . h($titre) . '</h1>' . $bandeau . $contenu . '</main></body></html>';
    return ['code' => $code, 'entetes' => admin_entetes() + ['Content-Type' => 'text/html; charset=UTF-8'], 'corps' => $html];
}

function f_formulaire(array $ctx, string $action, string $contenu, array $caches = [], string $classe = 'formulaire'): string
{
    $html = '<form method="post" action="index.php" class="' . h($classe) . '" data-confirmer="' . h(ADMIN_ACTIONS[$action]) . '">'
        . '<input type="hidden" name="csrf" value="' . h($ctx['csrf']) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="confirme" value="0">';
    foreach ($caches as $nom => $valeur) {
        $html .= '<input type="hidden" name="' . h($nom) . '" value="' . h((string)$valeur) . '">';
    }
    return $html . $contenu . '</form>';
}

function f_champ(string $nom, string $libelle, $valeur = '', string $type = 'text', string $attributs = ''): string
{
    return '<label>' . h($libelle) . '<input type="' . $type . '" name="' . $nom . '" value="' . h((string)$valeur) . '" '
        . $attributs . '></label>';
}

function f_zone(string $nom, string $libelle, $valeur = '', int $max = 500): string
{
    return '<label>' . h($libelle) . '<textarea name="' . $nom . '" maxlength="' . $max . '" rows="3">'
        . h((string)$valeur) . '</textarea></label>';
}

function f_choix(string $nom, string $libelle, array $choix, $selection, string $attributs = ''): string
{
    $html = '<label>' . h($libelle) . '<select name="' . $nom . '" ' . $attributs . '>';
    foreach ($choix as $valeur => $texte) {
        $extra = '';
        if (is_array($texte)) {
            [$texte, $extra] = $texte;
        }
        $html .= '<option value="' . h((string)$valeur) . '"' . ((string)$valeur === (string)$selection ? ' selected' : '')
            . $extra . '>' . h($texte) . '</option>';
    }
    return $html . '</select></label>';
}

function f_case(string $nom, string $libelle, bool $coche): string
{
    return '<label class="case"><input type="checkbox" name="' . $nom . '" value="1"' . ($coche ? ' checked' : '') . '> '
        . h($libelle) . '</label>';
}

function f_bouton(string $libelle, string $classe = ''): string
{
    return '<button type="submit"' . ($classe !== '' ? ' class="' . $classe . '"' : '') . '>' . h($libelle) . '</button>';
}

function tableau_html(array $entetes, array $lignes, string $vide = 'Aucun element.'): string
{
    if ($lignes === []) {
        return '<p class="discret">' . h($vide) . '</p>';
    }
    $html = '<div class="defilement"><table><thead><tr>';
    foreach ($entetes as $entete) {
        $html .= '<th>' . h($entete) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($lignes as $ligne) {
        $html .= '<tr>';
        foreach ($ligne as $cellule) {
            // Les cellules sont deja echappees par l'appelant (elles peuvent contenir un lien).
            $html .= '<td>' . $cellule . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

function fiche_html(array $paires): string
{
    $html = '<dl class="fiche">';
    foreach ($paires as $terme => $valeur) {
        $html .= '<dt>' . h($terme) . '</dt><dd>' . $valeur . '</dd>';
    }
    return $html . '</dl>';
}

function bloc_copie(string $id, string $texte): string
{
    return '<div class="copie"><code id="' . h($id) . '">' . h($texte) . '</code>'
        . '<button type="button" data-copier="' . h($id) . '">Copier</button></div>';
}

function admin_ecran_confirmation(array $ctx, string $action): string
{
    // Sans JavaScript : la confirmation passe par cette page, qui renvoie les memes champs.
    $resume = [];
    $caches = [];
    foreach ($ctx['post'] as $nom => $valeur) {
        if (!is_string($nom) || !is_string($valeur) || in_array($nom, ['csrf', 'action', 'confirme'], true)) {
            continue;
        }
        $caches[$nom] = $valeur;
        $resume[$nom] = h($valeur);
    }
    $formulaire = f_formulaire($ctx, $action, f_bouton('Confirmer : ' . ADMIN_ACTIONS[$action], 'principal'), $caches);
    $formulaire = str_replace('name="confirme" value="0"', 'name="confirme" value="1"', $formulaire);
    return '<p>Confirmer l\'action suivante : <strong>' . h(ADMIN_ACTIONS[$action]) . '</strong></p>'
        . ($resume !== [] ? fiche_html($resume) : '') . $formulaire
        . '<p><a href="index.php">Annuler</a></p>';
}

// ---------------------------------------------------------------------------
// Lectures communes
// ---------------------------------------------------------------------------

function licence_statut_affiche(array $lic, int $maintenant): string
{
    if ($lic['statut'] === 'active' && $lic['echeance'] !== null && (int)$lic['echeance'] <= $maintenant) {
        return 'expiree';
    }
    return (string)$lic['statut'];
}

function etiquette(string $statut): string
{
    $libelles = ['active' => 'Active', 'expiree' => 'Expiree', 'suspendue' => 'Suspendue', 'revoquee' => 'Revoquee',
        'en_attente' => 'En attente', 'acceptee' => 'Acceptee', 'refusee' => 'Refusee', 'inactive' => 'Inactive'];
    return '<span class="etiquette ' . h($statut) . '">' . h($libelles[$statut] ?? $statut) . '</span>';
}

function licence_lire(PDO $db, int $id): array
{
    $lic = db_ligne($db, 'SELECT l.*, d.code AS distribution_code, d.libelle AS distribution_libelle, '
        . 'd.tolerance_j AS distribution_tolerance, d.options AS distribution_options, p.code AS produit_code '
        . 'FROM licences l JOIN distributions d ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id '
        . 'WHERE l.id = ?', [$id]);
    if ($lic === null) {
        throw new AdminErreur('Licence inconnue.');
    }
    return $lic;
}

function distributions_choix(PDO $db, bool $actives_seulement): array
{
    $choix = [];
    $sql = 'SELECT d.id, d.code, d.libelle, d.duree_defaut_j FROM distributions d JOIN produits p ON p.id = d.produit_id '
        . ($actives_seulement ? 'WHERE d.actif = 1 AND p.actif = 1 ' : '') . 'ORDER BY p.code, d.code';
    foreach (db_lignes($db, $sql) as $d) {
        $choix[(int)$d['id']] = [$d['code'] . ' - ' . $d['libelle'],
            ' data-duree="' . h($d['duree_defaut_j'] === null ? '' : (string)$d['duree_defaut_j']) . '"'];
    }
    return $choix;
}

function entier_saisi($valeur, int $min, int $max, string $nom, bool $vide_permis = false): ?int
{
    $valeur = trim((string)$valeur);
    if ($valeur === '' && $vide_permis) {
        return null;
    }
    if (preg_match('/^-?\d{1,9}$/', $valeur) !== 1 || (int)$valeur < $min || (int)$valeur > $max) {
        throw new AdminErreur($nom . ' : nombre entier de ' . $min . ' a ' . $max . ' attendu.');
    }
    return (int)$valeur;
}

function texte_saisi($valeur, int $max, string $nom, bool $obligatoire = false, bool $multiligne = false): ?string
{
    $texte = texte_borne(is_string($valeur) ? $valeur : '', $max, $multiligne);
    if ($texte === null) {
        throw new AdminErreur($nom . ' : ' . $max . ' caracteres maximum.');
    }
    if ($texte === '') {
        if ($obligatoire) {
            throw new AdminErreur($nom . ' : champ obligatoire.');
        }
        return null;
    }
    return $texte;
}

function email_saisi($valeur, string $nom): ?string
{
    $email = texte_saisi($valeur, 254, $nom);
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new AdminErreur($nom . ' : adresse invalide.');
    }
    return $email;
}

function options_saisies($valeur): array
{
    $options = options_depuis_texte(is_string($valeur) ? $valeur : '');
    if ($options === null) {
        throw new AdminErreur('Options : codes en minuscules, chiffres et _ separes par des virgules (ex. export_pdf).');
    }
    return $options;
}

function code_saisi($valeur, string $nom): string
{
    $code = trim((string)$valeur);
    if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $code) !== 1) {
        throw new AdminErreur($nom . ' : lettres, chiffres, . _ - (64 caracteres maximum).');
    }
    return $code;
}

// ---------------------------------------------------------------------------
// Tableau de bord
// ---------------------------------------------------------------------------

function admin_ecran_tableau(array $ctx): string
{
    $db = $ctx['db'];
    $n = $ctx['maintenant'];
    $attente = (int)db_valeur($db, "SELECT COUNT(*) FROM demandes WHERE statut = 'en_attente'");
    $c = db_ligne($db, "SELECT "
        . "COALESCE(SUM(statut = 'active' AND (echeance IS NULL OR echeance > ?)), 0) AS actives, "
        . "COALESCE(SUM(statut = 'active' AND echeance IS NOT NULL AND echeance <= ?), 0) AS expirees, "
        . "COALESCE(SUM(statut = 'suspendue'), 0) AS suspendues, "
        . "COALESCE(SUM(statut = 'revoquee'), 0) AS revoquees, "
        . "COALESCE(SUM(dernier_contact >= ?), 0) AS vus FROM licences", [$n, $n, $n - 7 * JOUR]);
    $html = '<a class="compteur' . ($attente > 0 ? ' alerte' : '') . '" href="index.php?page=demandes">'
        . '<strong>' . $attente . '</strong> demande(s) en attente</a>';
    $html .= '<div class="cartes">';
    foreach (['actives' => 'Licences actives', 'expirees' => 'Licences expirees', 'suspendues' => 'Licences suspendues',
              'revoquees' => 'Licences revoquees', 'vus' => 'Postes vus sur 7 jours'] as $cle => $libelle) {
        $html .= '<div class="carte"><strong>' . (int)$c[$cle] . '</strong><span>' . h($libelle) . '</span></div>';
    }
    $html .= '</div><h2>Licences expirant sous 30 jours</h2>';
    $lignes = [];
    foreach (db_lignes($db, "SELECT l.*, d.code AS distribution_code FROM licences l JOIN distributions d "
        . "ON d.id = l.distribution_id WHERE l.statut = 'active' AND l.echeance > ? AND l.echeance <= ? "
        . "ORDER BY l.echeance LIMIT 100", [$n, $n + 30 * JOUR]) as $l) {
        $lignes[] = ['<a href="index.php?page=licence&amp;id=' . (int)$l['id'] . '">' . h($l['titulaire']) . '</a>',
            h($l['distribution_code']), h(date_fr((int)$l['echeance'])), (int)jours_restants((int)$l['echeance'], $n) . ' j',
            h(($l['id_poste'] ?? '') . ' ' . ($l['nom_ordinateur'] ?? ''))];
    }
    $html .= tableau_html(['Titulaire', 'Distribution', 'Echeance', 'Reste', 'Poste'], $lignes, 'Aucune.');
    $html .= '<h2>Controle d\'exposition</h2><p class="discret">Le navigateur tente de telecharger la base, les cles et '
        . 'la configuration par leur URL : chaque ligne doit indiquer "protege".</p>'
        . '<ul id="exposition" data-chemins="' . h(json_encode(exposition_chemins($ctx['config'], $db), JSON_UNESCAPED_SLASHES))
        . '"></ul>';
    return $html;
}

/**
 * URL (relatives a admin/) des fichiers sensibles, calculees depuis les chemins
 * reels : cle de signature active (et non kid 1, efface apres une rotation),
 * journal WAL de la base, dossier des sauvegardes. Le dossier parent de prive/
 * est suppose servi : si prive/ est a cote du dossier servi, ces URL tombent
 * hors de tout fichier et le controle conclut "protege", a juste titre.
 */
function exposition_chemins(array $config, PDO $db): array
{
    $normaliser = static function (string $chemin): string {
        return str_replace('\\', '/', $chemin);
    };
    $racine = rtrim($normaliser(dirname((string)$config['prive'])), '/') . '/';
    $kid = (int)db_valeur($db, 'SELECT COALESCE(MAX(kid), 1) FROM cles_signature WHERE retiree_le IS NULL');
    $fichiers = [$config['prive'] . '/config.php', $config['htpasswd'], signature_fichier($config, $kid),
        secret_fichier($config), $config['base'], $config['base'] . '-wal',
        rtrim((string)$config['dossier_sauvegardes'], '/') . '/', $config['prive'] . '/schema.sql',
        $config['prive'] . '/lib/commun.php'];
    $chemins = [];
    foreach ($fichiers as $fichier) {
        $fichier = $normaliser((string)$fichier);
        if (strncmp($fichier, $racine, strlen($racine)) === 0) {
            $chemins[] = '../' . substr($fichier, strlen($racine));
        }
    }
    return array_values(array_unique($chemins));
}

// ---------------------------------------------------------------------------
// Demandes
// ---------------------------------------------------------------------------

function admin_ecran_demandes(array $ctx): string
{
    $db = $ctx['db'];
    $lignes = [];
    foreach (db_lignes($db, "SELECT dm.*, d.code AS distribution_code, p.code AS produit_code FROM demandes dm "
        . "JOIN distributions d ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id "
        . "WHERE dm.statut = 'en_attente' ORDER BY dm.cree_le") as $d) {
        $lignes[] = [h(date_fr((int)$d['cree_le'], true)), h($d['produit_code']), h($d['distribution_code']),
            h($d['nom_ordinateur']), '<code>' . h($d['id_poste']) . '</code>', h($d['titulaire']), h($d['email']),
            '<span class="message">' . h($d['message']) . '</span>', h($d['ip']),
            $d['essai_jusqu'] === null ? 'aucun' : h(date_fr((int)$d['essai_jusqu'])),
            '<a class="bouton" href="index.php?page=demande&amp;id=' . (int)$d['id'] . '">Traiter</a>'];
    }
    $html = '<h2>En attente</h2>' . tableau_html(['Date', 'Produit', 'Distribution', 'Ordinateur', 'Identifiant',
            'Titulaire', 'E-mail', 'Message', 'IP', 'Essai jusqu\'au', ''], $lignes, 'Aucune demande en attente.');
    $lignes = [];
    foreach (db_lignes($db, "SELECT dm.*, d.code AS distribution_code FROM demandes dm "
        . "JOIN distributions d ON d.id = dm.distribution_id WHERE dm.statut <> 'en_attente' "
        . "ORDER BY dm.traitee_le DESC LIMIT ?", [ADMIN_PAR_PAGE]) as $d) {
        $suite = $d['statut'] === 'acceptee' && $d['licence_id'] !== null
            ? '<a href="index.php?page=licence&amp;id=' . (int)$d['licence_id'] . '">licence n. ' . (int)$d['licence_id'] . '</a>'
            : h($d['motif_refus'] ?? '');
        $lignes[] = [h(date_fr((int)$d['cree_le'])), h(date_fr((int)$d['traitee_le'])), etiquette($d['statut']),
            h($d['distribution_code']), h($d['nom_ordinateur']), h($d['titulaire']),
            '<a href="index.php?page=demande&amp;id=' . (int)$d['id'] . '">n. ' . (int)$d['id'] . '</a>', $suite];
    }
    $html .= '<h2>Historique</h2>' . tableau_html(['Demandee le', 'Traitee le', 'Statut', 'Distribution', 'Ordinateur',
            'Titulaire', 'Demande', 'Licence ou motif'], $lignes, 'Aucune demande traitee.');
    return $html . '<p><a href="index.php?page=export&amp;quoi=demandes">Exporter les demandes (CSV)</a></p>';
}

function admin_ecran_demande(array $ctx, int $id): string
{
    $d = db_ligne($ctx['db'], 'SELECT dm.*, d.code AS distribution_code, d.libelle AS distribution_libelle, '
        . 'd.duree_defaut_j, d.options AS distribution_options, p.code AS produit_code FROM demandes dm '
        . 'JOIN distributions d ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id WHERE dm.id = ?', [$id]);
    if ($d === null) {
        throw new AdminErreur('Demande inconnue.');
    }
    $html = fiche_html([
        'Statut' => etiquette($d['statut']),
        'Date' => h(date_fr((int)$d['cree_le'], true)),
        'Produit' => h($d['produit_code']),
        'Distribution' => h($d['distribution_code'] . ' - ' . $d['distribution_libelle']),
        'Ordinateur' => h($d['nom_ordinateur']),
        'Identifiant de poste' => '<code>' . h($d['id_poste']) . '</code>',
        'Titulaire' => h($d['titulaire']),
        'E-mail' => h($d['email']),
        'Mot du client' => '<span class="message">' . h($d['message']) . '</span>',
        'Version de l\'application' => h($d['version_appli']),
        'Essai jusqu\'au' => $d['essai_jusqu'] === null ? 'aucun essai' : h(date_fr((int)$d['essai_jusqu'])),
        'IP' => h($d['ip']),
    ]);
    if ($d['statut'] === 'acceptee') {
        return $html . '<p>Acceptee le ' . h(date_fr((int)$d['traitee_le'], true)) . ' : <a href="index.php?page=licence&amp;id='
            . (int)$d['licence_id'] . '">licence n. ' . (int)$d['licence_id'] . '</a>.</p>';
    }
    if ($d['statut'] === 'refusee') {
        return $html . '<p>Refusee le ' . h(date_fr((int)$d['traitee_le'], true)) . ($d['motif_refus'] !== null
            ? ' : ' . h($d['motif_refus']) : ' (sans motif)') . '.</p>';
    }
    $accepter = f_formulaire($ctx, 'demande_accepter',
        f_champ('duree_j', 'Duree en jours (vide = perpetuelle)', $d['duree_defaut_j'] ?? '', 'number', 'min="1" max="36500"')
        . f_champ('titulaire', 'Titulaire', $d['titulaire'], 'text', 'maxlength="120" required')
        . f_champ('options', 'Options (codes separes par des virgules)', implode(', ', options_lire($d['distribution_options'])))
        . f_bouton('Accepter', 'principal'), ['id' => $id]);
    $refuser = f_formulaire($ctx, 'demande_refuser',
        f_zone('motif', 'Motif (facultatif, renvoye tel quel a l\'application)', '', MOTIF_REFUS_MAX)
        . f_bouton('Refuser', 'danger'), ['id' => $id]);
    return $html . '<div class="colonnes"><section><h2>Accepter</h2>' . $accepter . '</section>'
        . '<section><h2>Refuser</h2>' . $refuser . '</section></div>';
}

function admin_action_demande_accepter(array $ctx)
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $duree = entier_saisi($ctx['post']['duree_j'] ?? '', 1, 36500, 'Duree', true);
    $titulaire = (string)texte_saisi($ctx['post']['titulaire'] ?? '', 120, 'Titulaire', true);
    $options = options_saisies($ctx['post']['options'] ?? '');
    $r = demande_accepter($ctx['db'], $ctx['config'], $id, $duree, $titulaire, $options, $ctx['utilisateur'], $ctx['ip'],
        $ctx['maintenant']);
    return '<p class="succes">Demande n. ' . $id . ' acceptee : la cle est transmise automatiquement a l\'application '
        . 'au prochain controle, deja liee au poste.</p><p>Cle (affichee une seule fois) :</p>'
        . bloc_copie('cle', $r['cle']) . '<p><a href="index.php?page=licence&amp;id=' . (int)$r['licence_id']
        . '">Voir la licence</a> - <a href="index.php?page=demandes">Retour aux demandes</a></p>';
}

function admin_action_demande_refuser(array $ctx): array
{
    demande_refuser($ctx['db'], (int)($ctx['post']['id'] ?? 0), (string)($ctx['post']['motif'] ?? ''),
        $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
    return admin_redirection('index.php?page=demandes&ok=refusee');
}

// ---------------------------------------------------------------------------
// Licences
// ---------------------------------------------------------------------------

function admin_ecran_licences(array $ctx): string
{
    $db = $ctx['db'];
    $q = trim((string)($ctx['get']['q'] ?? ''));
    $statut = (string)($ctx['get']['statut'] ?? '');
    $distribution = (int)($ctx['get']['distribution'] ?? 0);
    $conditions = [];
    $parametres = [];
    if ($q !== '') {
        $colonnes = ['l.titulaire', 'l.email', 'l.cle_indice', 'l.id_poste', 'l.nom_ordinateur', 'l.note'];
        $conditions[] = '(' . implode(' OR ', array_map(static function (string $c): string {
            return $c . " LIKE ? ESCAPE '\\'";
        }, $colonnes)) . ')';
        $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $parametres = array_merge($parametres, array_fill(0, count($colonnes), $motif));
    }
    if ($statut === 'expiree') {
        $conditions[] = "l.statut = 'active' AND l.echeance IS NOT NULL AND l.echeance <= ?";
        $parametres[] = $ctx['maintenant'];
    } elseif ($statut === 'active') {
        $conditions[] = "l.statut = 'active' AND (l.echeance IS NULL OR l.echeance > ?)";
        $parametres[] = $ctx['maintenant'];
    } elseif (in_array($statut, ['suspendue', 'revoquee'], true)) {
        $conditions[] = 'l.statut = ?';
        $parametres[] = $statut;
    }
    if ($distribution > 0) {
        $conditions[] = 'l.distribution_id = ?';
        $parametres[] = $distribution;
    }
    $sql = 'SELECT l.*, d.code AS distribution_code FROM licences l JOIN distributions d ON d.id = l.distribution_id'
        . ($conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '')
        . ' ORDER BY l.titulaire COLLATE NOCASE, l.id LIMIT 500';
    $lignes = [];
    foreach (db_lignes($db, $sql, $parametres) as $l) {
        $lignes[] = ['<a href="index.php?page=licence&amp;id=' . (int)$l['id'] . '">' . h($l['titulaire']) . '</a>',
            h($l['distribution_code']), '<code>...' . h($l['cle_indice']) . '</code>',
            $l['machine'] === null ? '<span class="discret">non lie</span>'
                : '<code>' . h($l['id_poste']) . '</code> ' . h($l['nom_ordinateur']),
            $l['echeance'] === null ? 'perpetuelle' : h(date_fr((int)$l['echeance'])),
            etiquette(licence_statut_affiche($l, $ctx['maintenant'])), h(date_fr($l['dernier_contact'] === null ? null
                : (int)$l['dernier_contact'], true))];
    }
    $filtres = '<form method="get" action="index.php" class="filtres"><input type="hidden" name="page" value="licences">'
        . f_champ('q', 'Recherche', $q, 'search', 'placeholder="titulaire, e-mail, fin de cle, poste"')
        . f_choix('statut', 'Statut', ['' => 'tous', 'active' => 'actives', 'expiree' => 'expirees',
            'suspendue' => 'suspendues', 'revoquee' => 'revoquees'], $statut)
        . f_choix('distribution', 'Distribution', [0 => 'toutes'] + distributions_choix($db, false), $distribution)
        . '<button type="submit">Filtrer</button></form>';
    return '<p><a class="bouton principal" href="index.php?page=licence_nouvelle">Creer une cle</a></p>' . $filtres
        . tableau_html(['Titulaire', 'Distribution', 'Cle', 'Poste lie', 'Echeance', 'Statut', 'Dernier contact'],
            $lignes, 'Aucune licence.')
        . '<p><a href="index.php?page=export&amp;quoi=licences">Exporter les licences (CSV)</a></p>';
}

function admin_ecran_licence(array $ctx, int $id): string
{
    $lic = licence_lire($ctx['db'], $id);
    $n = $ctx['maintenant'];
    $statut = licence_statut_affiche($lic, $n);
    $options = $lic['options'] === null ? options_lire($lic['distribution_options']) : options_lire($lic['options']);
    $html = fiche_html([
        'Statut' => etiquette($statut),
        'Titulaire' => h($lic['titulaire']),
        'E-mail' => h($lic['email']),
        'Note' => h($lic['note']),
        'Distribution' => h($lic['produit_code'] . ' / ' . $lic['distribution_code'] . ' - ' . $lic['distribution_libelle']),
        'Cle' => '<code>ETDEL-****-****-****-' . h($lic['cle_indice']) . '</code> (seule la fin est conservee)',
        'Echeance' => $lic['echeance'] === null ? 'perpetuelle' : h(date_fr((int)$lic['echeance'])) . ' ('
            . (int)jours_restants((int)$lic['echeance'], $n) . ' j restants)',
        'Tolerance hors ligne' => $lic['tolerance_j'] === null ? 'distribution (' . (int)$lic['distribution_tolerance'] . ' j)'
            : (int)$lic['tolerance_j'] . ' j (surcharge)',
        'Options' => h(implode(', ', $options) ?: 'aucune') . ($lic['options'] === null ? ' (distribution)' : ' (surcharge)'),
        'Poste lie' => $lic['machine'] === null ? 'aucun (la premiere activation liera la cle)'
            : '<code>' . h($lic['id_poste']) . '</code> ' . h($lic['nom_ordinateur']) . ' <span class="discret">empreinte '
            . h(substr((string)$lic['machine'], 0, 12)) . '...</span>',
        'Lie le' => h(date_fr($lic['lie_le'] === null ? null : (int)$lic['lie_le'], true)),
        'Version de l\'application' => h($lic['version_appli']),
        'Dernier contact' => h(date_fr($lic['dernier_contact'] === null ? null : (int)$lic['dernier_contact'], true)),
        'Derniere IP' => h($lic['derniere_ip']),
        'Origine' => h($lic['origine']),
        'Creee le' => h(date_fr((int)$lic['cree_le'], true)),
        'Modifiee le' => h(date_fr((int)$lic['modifie_le'], true)),
    ]);
    $actions = '';
    if ($lic['statut'] !== 'revoquee') {
        if ($lic['echeance'] !== null) {
            $actions .= '<section><h2>Prolonger</h2><div class="rangee">'
                . f_formulaire($ctx, 'licence_prolonger', f_bouton('+30 jours'), ['id' => $id, 'mode' => '30'], 'en-ligne')
                . f_formulaire($ctx, 'licence_prolonger', f_bouton('+1 an'), ['id' => $id, 'mode' => '365'], 'en-ligne')
                . '</div>'
                . f_formulaire($ctx, 'licence_prolonger', f_champ('date', 'Nouvelle echeance', '', 'date', 'required')
                    . f_bouton('Fixer la date'), ['id' => $id, 'mode' => 'date'])
                . '</section>';
        }
        $actions .= '<section><h2>Modifier</h2>' . f_formulaire($ctx, 'licence_modifier',
            f_champ('titulaire', 'Titulaire', $lic['titulaire'], 'text', 'maxlength="120" required')
            . f_champ('email', 'E-mail', $lic['email'] ?? '', 'email')
            . f_zone('note', 'Note interne', $lic['note'] ?? '', 500)
            . f_champ('tolerance_j', 'Tolerance hors ligne en jours (vide = distribution)', $lic['tolerance_j'] ?? '', 'number',
                'min="0" max="365"')
            . f_case('options_distribution', 'Options de la distribution', $lic['options'] === null)
            . f_champ('options', 'Options propres a la licence', implode(', ', $options))
            . f_bouton('Enregistrer'), ['id' => $id]) . '</section>';
        $etat = '';
        if ($lic['statut'] === 'active') {
            $etat .= f_formulaire($ctx, 'licence_suspendre', f_bouton('Suspendre'), ['id' => $id], 'en-ligne');
        } else {
            $etat .= f_formulaire($ctx, 'licence_reactiver', f_bouton('Reactiver'), ['id' => $id], 'en-ligne');
        }
        $etat .= f_formulaire($ctx, 'licence_revoquer', f_bouton('Revoquer', 'danger'), ['id' => $id], 'en-ligne');
        if ($lic['machine'] !== null) {
            $etat .= f_formulaire($ctx, 'licence_liberer', f_bouton('Liberer le poste'), ['id' => $id], 'en-ligne');
        }
        // Annexe D : sur suspension, le poste efface sa cle. Une cle obtenue par
        // demande n'a jamais ete montree a personne : la reactivation seule ne
        // suffit pas a debloquer ce poste.
        $suspension = $lic['origine'] === 'demande'
            ? '<p class="alerte">Licence obtenue par demande : l\'utilisateur n\'a jamais vu sa cle. Apres une '
                . 'suspension, le poste l\'efface ; la reactiver ne le debloquera pas. Pour une coupure temporaire, '
                . 'preferer une echeance proche (date libre) ; sinon, creer ensuite une nouvelle cle a lui transmettre.</p>'
            : '<p class="discret">Apres une suspension, le poste efface sa cle : une fois la licence reactivee, '
                . 'l\'utilisateur saisit de nouveau la meme cle.</p>';
        $actions .= '<section><h2>Etat</h2><div class="rangee">' . $etat . '</div><p class="discret">Suspendre et revoquer '
            . 'bloquent le poste a sa connexion suivante. La revocation est definitive. Liberer le poste permet '
            . 'd\'activer la meme cle sur un autre ordinateur.</p>' . $suspension . '</section>';
    }
    $lignes = [];
    foreach (db_lignes($ctx['db'], 'SELECT * FROM journal WHERE cible = ? ORDER BY id DESC LIMIT 50', ['licence ' . $id]) as $j) {
        $lignes[] = [h(date_fr((int)$j['date'], true)), h($j['acteur']), h($j['action']), h($j['detail'])];
    }
    return $html . '<div class="colonnes">' . $actions . '</div><h2>Historique</h2>'
        . tableau_html(['Date', 'Acteur', 'Action', 'Detail'], $lignes, 'Aucun evenement.');
}

function admin_ecran_licence_nouvelle(array $ctx): string
{
    $choix = distributions_choix($ctx['db'], true);
    if ($choix === []) {
        return '<p>Aucune distribution active : <a href="index.php?page=produits">creer d\'abord un produit et une '
            . 'distribution</a>.</p>';
    }
    $premiere = (int)array_key_first($choix);
    $duree = db_valeur($ctx['db'], 'SELECT duree_defaut_j FROM distributions WHERE id = ?', [$premiere]);
    return f_formulaire($ctx, 'licence_creer',
        f_choix('distribution_id', 'Distribution', $choix, $premiere, 'data-durees')
        . f_champ('titulaire', 'Titulaire', '', 'text', 'maxlength="120" required')
        . f_champ('email', 'E-mail du client (facultatif)', '', 'email')
        . f_zone('note', 'Note interne (facultative)', '', 500)
        . f_champ('duree_j', 'Duree en jours (vide = perpetuelle)', $duree ?? '', 'number', 'min="1" max="36500"')
        . f_bouton('Creer la cle', 'principal'))
        . '<p class="discret">La cle n\'est affichee qu\'une fois, a la creation : elle est a transmettre au client, qui '
        . 'la saisit dans l\'application. Elle se lie au premier poste qui l\'active.</p>';
}

/** Cree une cle non liee ; renvoie ['id' => ..., 'cle' => ...]. */
function licence_creer(PDO $db, int $distribution_id, string $titulaire, ?string $email, ?string $note, ?int $duree_j,
                       string $acteur, string $ip, int $maintenant): array
{
    if (db_valeur($db, 'SELECT COUNT(*) FROM distributions WHERE id = ?', [$distribution_id]) != 1) {
        throw new AdminErreur('Distribution inconnue.');
    }
    $cle = cle_generer();
    $id = db_inserer($db, 'licences', [
        'distribution_id' => $distribution_id, 'cle_hash' => cle_hash($cle), 'cle_indice' => substr($cle, -4),
        'titulaire' => $titulaire, 'email' => $email, 'note' => $note,
        'echeance' => $duree_j === null ? null : $maintenant + $duree_j * JOUR,
        'statut' => 'active', 'origine' => 'console', 'cree_le' => $maintenant, 'modifie_le' => $maintenant,
    ]);
    journal_ecrire($db, $acteur, 'licence_creee', 'licence ' . $id,
        $titulaire . ', ' . ($duree_j === null ? 'perpetuelle' : $duree_j . ' j') . ', cle ...' . substr($cle, -4), $ip, $maintenant);
    return ['id' => $id, 'cle' => $cle];
}

function admin_action_licence_creer(array $ctx)
{
    $p = $ctx['post'];
    $r = licence_creer($ctx['db'], (int)($p['distribution_id'] ?? 0),
        (string)texte_saisi($p['titulaire'] ?? '', 120, 'Titulaire', true), email_saisi($p['email'] ?? '', 'E-mail'),
        texte_saisi($p['note'] ?? '', 500, 'Note', false, true), entier_saisi($p['duree_j'] ?? '', 1, 36500, 'Duree', true),
        $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
    return '<p class="succes">Cle creee. Elle ne sera plus affichee : copiez-la maintenant pour la transmettre au '
        . 'client.</p>' . bloc_copie('cle', $r['cle']) . '<p><a href="index.php?page=licence&amp;id=' . (int)$r['id']
        . '">Voir la licence</a> - <a href="index.php?page=licence_nouvelle">Creer une autre cle</a></p>';
}

function admin_action_licence_prolonger(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $lic = licence_lire($ctx['db'], $id);
    if ($lic['echeance'] === null) {
        throw new AdminErreur('Licence perpetuelle : rien a prolonger.');
    }
    if ($lic['statut'] === 'revoquee') {
        throw new AdminErreur('Licence revoquee.');
    }
    $mode = (string)($ctx['post']['mode'] ?? '');
    // Prolongation a partir de l'echeance, ou d'aujourd'hui si elle est deja passee.
    $depart = max((int)$lic['echeance'], $ctx['maintenant']);
    if ($mode === '30' || $mode === '365') {
        $echeance = $depart + (int)$mode * JOUR;
    } elseif ($mode === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($ctx['post']['date'] ?? '')) === 1) {
        $date = DateTime::createFromFormat('!Y-m-d H:i:s', $ctx['post']['date'] . ' 23:59:59');
        // createFromFormat accepte le 31 fevrier (devient le 3 mars) : la date relue doit etre la date saisie.
        if ($date === false || $date->format('Y-m-d') !== $ctx['post']['date']
            || $date->getTimestamp() <= $ctx['maintenant']) {
            throw new AdminErreur('Date invalide ou passee.');
        }
        $echeance = $date->getTimestamp();
    } else {
        throw new AdminErreur('Prolongation invalide.');
    }
    db_maj($ctx['db'], 'licences', ['echeance' => $echeance, 'modifie_le' => $ctx['maintenant']], 'id', $id);
    admin_journal($ctx, 'licence_prolongee', 'licence ' . $id,
        date_fr((int)$lic['echeance']) . ' -> ' . date_fr($echeance));
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=prolongee');
}

function admin_action_licence_modifier(array $ctx): array
{
    $p = $ctx['post'];
    $id = (int)($p['id'] ?? 0);
    licence_lire($ctx['db'], $id);
    $valeurs = [
        'titulaire' => (string)texte_saisi($p['titulaire'] ?? '', 120, 'Titulaire', true),
        'email' => email_saisi($p['email'] ?? '', 'E-mail'),
        'note' => texte_saisi($p['note'] ?? '', 500, 'Note', false, true),
        'tolerance_j' => entier_saisi($p['tolerance_j'] ?? '', 0, 365, 'Tolerance', true),
        'options' => ($p['options_distribution'] ?? '') === '1' ? null : json_encode(options_saisies($p['options'] ?? '')),
        'modifie_le' => $ctx['maintenant'],
    ];
    db_maj($ctx['db'], 'licences', $valeurs, 'id', $id);
    admin_journal($ctx, 'licence_modifiee', 'licence ' . $id, 'tolerance ' . ($valeurs['tolerance_j'] ?? 'distribution')
        . ', options ' . ($valeurs['options'] ?? 'distribution'));
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=modifiee');
}

function admin_changer_statut(array $ctx, array $depuis, string $vers, string $action, string $message): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $lic = licence_lire($ctx['db'], $id);
    if (!in_array($lic['statut'], $depuis, true)) {
        throw new AdminErreur('Impossible depuis le statut "' . $lic['statut'] . '".');
    }
    db_maj($ctx['db'], 'licences', ['statut' => $vers, 'modifie_le' => $ctx['maintenant']], 'id', $id);
    admin_journal($ctx, $action, 'licence ' . $id, $lic['statut'] . ' -> ' . $vers);
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=' . $message);
}

function admin_action_licence_suspendre(array $ctx): array
{
    return admin_changer_statut($ctx, ['active'], 'suspendue', 'licence_suspendue', 'suspendue');
}

function admin_action_licence_reactiver(array $ctx): array
{
    return admin_changer_statut($ctx, ['suspendue'], 'active', 'licence_reactivee', 'reactivee');
}

function admin_action_licence_revoquer(array $ctx): array
{
    return admin_changer_statut($ctx, ['active', 'suspendue'], 'revoquee', 'licence_revoquee', 'revoquee');
}

function admin_action_licence_liberer(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $lic = licence_lire($ctx['db'], $id);
    if ($lic['machine'] === null) {
        throw new AdminErreur('Cette cle n\'est liee a aucun poste.');
    }
    db_maj($ctx['db'], 'licences', ['machine' => null, 'id_poste' => null, 'nom_ordinateur' => null, 'lie_le' => null,
        'modifie_le' => $ctx['maintenant']], 'id', $id);
    admin_journal($ctx, 'poste_libere', 'licence ' . $id, 'ancien poste ' . $lic['id_poste'] . ' (' . $lic['nom_ordinateur'] . ')');
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=liberee');
}

// ---------------------------------------------------------------------------
// Produits et distributions
// ---------------------------------------------------------------------------

function ligne_installer(string $produit, string $distribution): string
{
    return 'etdel_licence.installer(root, produit="' . $produit . '", distribution="' . $distribution
        . '", version=APP_VERSION)';
}

function admin_ecran_produits(array $ctx): string
{
    $db = $ctx['db'];
    $html = '<p><a class="bouton principal" href="index.php?page=produit">Nouveau produit</a></p>';
    $produits = db_lignes($db, 'SELECT * FROM produits ORDER BY code');
    if ($produits === []) {
        return $html . '<p class="discret">Aucun produit.</p>';
    }
    foreach ($produits as $p) {
        $html .= '<section class="produit"><h2>' . h($p['code']) . ' - ' . h($p['nom'])
            . ((int)$p['actif'] === 1 ? '' : ' <span class="etiquette revoquee">desactive</span>') . '</h2>'
            . '<p>Version minimale : ' . h($p['version_min'] ?: 'aucune') . ' - <a href="index.php?page=produit&amp;id='
            . (int)$p['id'] . '">Modifier</a> - <a href="index.php?page=distribution&amp;produit=' . (int)$p['id']
            . '">Nouvelle distribution</a></p>';
        $lignes = [];
        foreach (db_lignes($db, 'SELECT d.*, (SELECT COUNT(*) FROM licences l WHERE l.distribution_id = d.id) AS nb '
            . 'FROM distributions d WHERE d.produit_id = ? ORDER BY d.code', [(int)$p['id']]) as $d) {
            $lignes[] = ['<a href="index.php?page=distribution&amp;id=' . (int)$d['id'] . '">' . h($d['code']) . '</a>'
                . ((int)$d['actif'] === 1 ? '' : ' ' . etiquette('inactive')),
                h($d['libelle']), h($d['client']), (int)$d['tolerance_j'] . ' / ' . (int)$d['preavis_j'] . ' j',
                $d['duree_defaut_j'] === null ? 'perpetuelle' : (int)$d['duree_defaut_j'] . ' j',
                (int)$d['essai_j'] . ' j', h($d['version_min'] ?: '-'), h(implode(', ', options_lire($d['options'])) ?: '-'),
                (int)$d['nb']];
        }
        $html .= tableau_html(['Code', 'Libelle', 'Client ou canal', 'Tolerance / preavis', 'Duree par defaut', 'Essai',
                'Version min', 'Options', 'Licences'], $lignes, 'Aucune distribution.') . '</section>';
    }
    return $html;
}

function admin_ecran_produit(array $ctx, int $id): string
{
    $p = $id ? db_ligne($ctx['db'], 'SELECT * FROM produits WHERE id = ?', [$id]) : null;
    if ($id && $p === null) {
        throw new AdminErreur('Produit inconnu.');
    }
    return f_formulaire($ctx, 'produit_enregistrer',
        ($p === null ? f_champ('code', 'Code (ex. MONAPPLI, definitif)', '', 'text', 'maxlength="64" required')
            : '<p>Code : <code>' . h($p['code']) . '</code> (non modifiable : il est inscrit dans les applications)</p>')
        . f_champ('nom', 'Nom', $p['nom'] ?? '', 'text', 'maxlength="120" required')
        . f_champ('version_min', 'Version minimale globale (facultative)', $p['version_min'] ?? '', 'text', 'maxlength="32"')
        . f_case('actif', 'Actif', $p === null || (int)$p['actif'] === 1)
        . f_bouton('Enregistrer', 'principal'), ['id' => $id])
        . '<p class="discret">Un produit desactive bloque toutes ses distributions (code produit_inconnu).</p>';
}

function admin_action_produit_enregistrer(array $ctx): array
{
    $p = $ctx['post'];
    $id = (int)($p['id'] ?? 0);
    $valeurs = [
        'nom' => (string)texte_saisi($p['nom'] ?? '', 120, 'Nom', true),
        'version_min' => texte_saisi($p['version_min'] ?? '', 32, 'Version minimale'),
        'actif' => ($p['actif'] ?? '') === '1' ? 1 : 0,
    ];
    if ($id === 0) {
        $valeurs['code'] = code_saisi($p['code'] ?? '', 'Code');
        if (db_valeur($ctx['db'], 'SELECT COUNT(*) FROM produits WHERE code = ?', [$valeurs['code']]) > 0) {
            throw new AdminErreur('Ce code de produit existe deja.');
        }
        $valeurs['cree_le'] = $ctx['maintenant'];
        $id = db_inserer($ctx['db'], 'produits', $valeurs);
        admin_journal($ctx, 'produit_cree', 'produit ' . $id, $valeurs['code']);
    } else {
        if (db_maj($ctx['db'], 'produits', $valeurs, 'id', $id) !== 1) {
            throw new AdminErreur('Produit inconnu.');
        }
        admin_journal($ctx, 'produit_modifie', 'produit ' . $id, json_encode($valeurs));
    }
    return admin_redirection('index.php?page=produits&ok=produit');
}

function admin_ecran_distribution(array $ctx, int $id): string
{
    $db = $ctx['db'];
    $d = $id ? db_ligne($db, 'SELECT d.*, p.code AS produit_code FROM distributions d JOIN produits p ON p.id = d.produit_id '
        . 'WHERE d.id = ?', [$id]) : null;
    if ($id && $d === null) {
        throw new AdminErreur('Distribution inconnue.');
    }
    $html = '';
    if ($d !== null) {
        $html .= '<p>Ligne a inserer dans l\'application, juste apres la creation de la fenetre principale :</p>'
            . bloc_copie('installer', ligne_installer($d['produit_code'], $d['code']));
        $produit_champ = '<p>Produit : <code>' . h($d['produit_code']) . '</code> - Code : <code>' . h($d['code'])
            . '</code> (non modifiable)</p>';
    } else {
        $produits = [];
        foreach (db_lignes($db, 'SELECT id, code, nom FROM produits ORDER BY code') as $p) {
            $produits[(int)$p['id']] = $p['code'] . ' - ' . $p['nom'];
        }
        if ($produits === []) {
            return '<p>Creer d\'abord un <a href="index.php?page=produit">produit</a>.</p>';
        }
        $produit_champ = f_choix('produit_id', 'Produit', $produits, (int)($ctx['get']['produit'] ?? 0))
            . f_champ('code', 'Code (ex. MONAPPLI-CLIENTA, definitif)', '', 'text', 'maxlength="64" required');
    }
    $html .= f_formulaire($ctx, 'distribution_enregistrer', $produit_champ
        . f_champ('libelle', 'Libelle', $d['libelle'] ?? '', 'text', 'maxlength="120" required')
        . f_champ('client', 'Client ou canal', $d['client'] ?? '', 'text', 'maxlength="120"')
        . f_champ('tolerance_j', 'Tolerance hors ligne (jours, 0 a 365)', $d['tolerance_j'] ?? 15, 'number', 'min="0" max="365" required')
        . f_champ('preavis_j', 'Preavis (jours, 0 a 365)', $d['preavis_j'] ?? 5, 'number', 'min="0" max="365" required')
        . f_champ('duree_defaut_j', 'Duree de licence par defaut (jours, vide = perpetuelle)', $d['duree_defaut_j'] ?? '',
            'number', 'min="1" max="36500"')
        . f_champ('essai_j', 'Essai pendant une demande (jours, 0 = aucun)', $d['essai_j'] ?? 15, 'number', 'min="0" max="365" required')
        . f_champ('version_min', 'Version minimale (facultative)', $d['version_min'] ?? '', 'text', 'maxlength="32"')
        . f_champ('options', 'Options activees (codes separes par des virgules)',
            implode(', ', options_lire($d['options'] ?? '[]')))
        . f_zone('message', 'Message d\'accueil (facultatif)', $d['message'] ?? '', MESSAGE_ACCUEIL_MAX)
        . f_case('actif', 'Active', $d === null || (int)$d['actif'] === 1)
        . f_bouton('Enregistrer', 'principal'), ['id' => $id]);
    if ($d !== null) {
        $html .= '<h2>Dupliquer</h2>' . f_formulaire($ctx, 'distribution_dupliquer',
            f_champ('code', 'Code de la copie', $d['code'] . '-COPIE', 'text', 'maxlength="64" required')
            . f_bouton('Dupliquer'), ['id' => $id]);
    }
    return $html;
}

function admin_action_distribution_enregistrer(array $ctx): array
{
    $p = $ctx['post'];
    $id = (int)($p['id'] ?? 0);
    $valeurs = [
        'libelle' => (string)texte_saisi($p['libelle'] ?? '', 120, 'Libelle', true),
        'client' => texte_saisi($p['client'] ?? '', 120, 'Client'),
        'tolerance_j' => entier_saisi($p['tolerance_j'] ?? '', 0, 365, 'Tolerance'),
        'preavis_j' => entier_saisi($p['preavis_j'] ?? '', 0, 365, 'Preavis'),
        'duree_defaut_j' => entier_saisi($p['duree_defaut_j'] ?? '', 1, 36500, 'Duree par defaut', true),
        'essai_j' => entier_saisi($p['essai_j'] ?? '', 0, 365, 'Essai'),
        'version_min' => texte_saisi($p['version_min'] ?? '', 32, 'Version minimale'),
        'options' => json_encode(options_saisies($p['options'] ?? '')),
        'message' => texte_saisi($p['message'] ?? '', MESSAGE_ACCUEIL_MAX, 'Message', false, true),
        'actif' => ($p['actif'] ?? '') === '1' ? 1 : 0,
    ];
    if ($id === 0) {
        $valeurs['produit_id'] = (int)($p['produit_id'] ?? 0);
        if (db_valeur($ctx['db'], 'SELECT COUNT(*) FROM produits WHERE id = ?', [$valeurs['produit_id']]) != 1) {
            throw new AdminErreur('Produit inconnu.');
        }
        $valeurs['code'] = code_saisi($p['code'] ?? '', 'Code');
        if (db_valeur($ctx['db'], 'SELECT COUNT(*) FROM distributions WHERE code = ?', [$valeurs['code']]) > 0) {
            throw new AdminErreur('Ce code de distribution existe deja.');
        }
        $valeurs['cree_le'] = $ctx['maintenant'];
        $id = db_inserer($ctx['db'], 'distributions', $valeurs);
        admin_journal($ctx, 'distribution_creee', 'distribution ' . $id, $valeurs['code']);
    } else {
        if (db_maj($ctx['db'], 'distributions', $valeurs, 'id', $id) !== 1) {
            throw new AdminErreur('Distribution inconnue.');
        }
        admin_journal($ctx, 'distribution_modifiee', 'distribution ' . $id, json_encode($valeurs));
    }
    return admin_redirection('index.php?page=distribution&id=' . $id . '&ok=distribution');
}

function admin_action_distribution_dupliquer(array $ctx): array
{
    $source = db_ligne($ctx['db'], 'SELECT * FROM distributions WHERE id = ?', [(int)($ctx['post']['id'] ?? 0)]);
    if ($source === null) {
        throw new AdminErreur('Distribution inconnue.');
    }
    $code = code_saisi($ctx['post']['code'] ?? '', 'Code');
    if (db_valeur($ctx['db'], 'SELECT COUNT(*) FROM distributions WHERE code = ?', [$code]) > 0) {
        throw new AdminErreur('Ce code de distribution existe deja.');
    }
    unset($source['id']);
    $source['code'] = $code;
    $source['libelle'] = tronquer_utf8($source['libelle'] . ' (copie)', 120);
    $source['cree_le'] = $ctx['maintenant'];
    $id = db_inserer($ctx['db'], 'distributions', $source);
    admin_journal($ctx, 'distribution_dupliquee', 'distribution ' . $id, 'copie de ' . (int)$ctx['post']['id'] . ' : ' . $code);
    return admin_redirection('index.php?page=distribution&id=' . $id . '&ok=dupliquee');
}

// ---------------------------------------------------------------------------
// Serveurs
// ---------------------------------------------------------------------------

function admin_ecran_serveurs(array $ctx): string
{
    $db = $ctx['db'];
    $lignes = [];
    foreach (db_lignes($db, 'SELECT * FROM urls_serveur ORDER BY priorite, id') as $u) {
        $lignes[] = ['<code>' . h($u['url']) . '</code>', (int)$u['actif'] === 1 ? etiquette('active') : 'inactive',
            f_formulaire($ctx, 'url_modifier', f_champ('priorite', 'Priorite', $u['priorite'], 'number', 'min="0" max="9999"')
                . f_case('actif', 'Diffusee', (int)$u['actif'] === 1) . f_bouton('Enregistrer'),
                ['id' => (int)$u['id']], 'en-ligne')];
    }
    $n = $ctx['maintenant'];
    $vus = db_ligne($db, 'SELECT COALESCE(SUM(dernier_contact >= ?), 0) AS j1, COALESCE(SUM(dernier_contact >= ?), 0) AS j7, '
        . 'COALESCE(SUM(dernier_contact >= ?), 0) AS j30, COUNT(*) AS liees FROM licences '
        . "WHERE machine IS NOT NULL AND statut = 'active'", [$n - JOUR, $n - 7 * JOUR, $n - 30 * JOUR]);
    return '<p>Liste signee diffusee a chaque reponse, dans l\'ordre de priorite (plus petit = premier). Les postes la '
        . 'memorisent : changer d\'URL ne demande jamais de recompiler les applications.</p>'
        . tableau_html(['URL', 'Etat', 'Reglage'], $lignes, 'Aucune URL.')
        . '<h2>Ajouter une URL</h2>' . f_formulaire($ctx, 'url_ajouter',
            f_champ('url', 'URL de l\'API (https://.../api/v1/)', '', 'url', 'required maxlength="300"')
            . f_champ('priorite', 'Priorite', 20, 'number', 'min="0" max="9999"') . f_bouton('Ajouter', 'principal'))
        . '<h2>Suivi d\'une migration</h2><p>Postes actifs vus depuis 24 h : <strong>' . (int)$vus['j1'] . '</strong>, '
        . '7 jours : <strong>' . (int)$vus['j7'] . '</strong>, 30 jours : <strong>' . (int)$vus['j30'] . '</strong> sur '
        . (int)$vus['liees'] . ' postes lies.</p><p class="discret">Procedure : ajouter la nouvelle URL, attendre que les '
        . 'postes se soient connectes (colonne dernier contact), puis desactiver l\'ancienne.</p>';
}

function admin_action_url_ajouter(array $ctx): array
{
    $url = trim((string)($ctx['post']['url'] ?? ''));
    if (!url_api_valide($url)) {
        throw new AdminErreur('URL invalide : https://.../api/v1/ attendu (terminee par /).');
    }
    if (db_valeur($ctx['db'], 'SELECT COUNT(*) FROM urls_serveur WHERE url = ?', [$url]) > 0) {
        throw new AdminErreur('Cette URL est deja dans la liste.');
    }
    $priorite = entier_saisi($ctx['post']['priorite'] ?? '', 0, 9999, 'Priorite');
    $id = db_inserer($ctx['db'], 'urls_serveur', ['url' => $url, 'priorite' => $priorite, 'actif' => 1]);
    admin_journal($ctx, 'url_ajoutee', 'url ' . $id, $url . ' (priorite ' . $priorite . ')');
    return admin_redirection('index.php?page=serveurs&ok=url');
}

function admin_action_url_modifier(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $url = db_ligne($ctx['db'], 'SELECT * FROM urls_serveur WHERE id = ?', [$id]);
    if ($url === null) {
        throw new AdminErreur('URL inconnue.');
    }
    $actif = ($ctx['post']['actif'] ?? '') === '1' ? 1 : 0;
    $autres = (int)db_valeur($ctx['db'], 'SELECT COUNT(*) FROM urls_serveur WHERE actif = 1 AND id <> ?', [$id]);
    if ($actif === 0 && $autres === 0) {
        // Une liste vide n'est jamais adoptee par les postes : elle masquerait l'erreur.
        throw new AdminErreur('Au moins une URL doit rester diffusee.');
    }
    $priorite = entier_saisi($ctx['post']['priorite'] ?? '', 0, 9999, 'Priorite');
    db_maj($ctx['db'], 'urls_serveur', ['priorite' => $priorite, 'actif' => $actif], 'id', $id);
    admin_journal($ctx, 'url_modifiee', 'url ' . $id, $url['url'] . ' : priorite ' . $priorite . ', '
        . ($actif ? 'diffusee' : 'retiree'));
    return admin_redirection('index.php?page=serveurs&ok=url');
}

// ---------------------------------------------------------------------------
// Journal, exports, sauvegarde
// ---------------------------------------------------------------------------

function journal_filtre(array $get): array
{
    $conditions = [];
    $parametres = [];
    $q = trim((string)($get['q'] ?? ''));
    if ($q !== '') {
        $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $conditions[] = "(acteur LIKE ? ESCAPE '\\' OR cible LIKE ? ESCAPE '\\' OR detail LIKE ? ESCAPE '\\' OR ip LIKE ? ESCAPE '\\')";
        array_push($parametres, $motif, $motif, $motif, $motif);
    }
    $action = (string)($get['action'] ?? '');
    if ($action !== '') {
        $conditions[] = 'action = ?';
        $parametres[] = $action;
    }
    return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parametres, $q, $action];
}

function admin_ecran_journal(array $ctx): string
{
    [$where, $parametres, $q, $action] = journal_filtre($ctx['get']);
    $page = max(1, (int)($ctx['get']['p'] ?? 1));
    $total = (int)db_valeur($ctx['db'], 'SELECT COUNT(*) FROM journal' . $where, $parametres);
    $lignes = [];
    foreach (db_lignes($ctx['db'], 'SELECT * FROM journal' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?',
        array_merge($parametres, [ADMIN_PAR_PAGE, ($page - 1) * ADMIN_PAR_PAGE])) as $j) {
        $lignes[] = [h(date_fr((int)$j['date'], true)), h($j['acteur']), h($j['action']), h($j['cible']), h($j['detail']), h($j['ip'])];
    }
    $actions = ['' => 'toutes'];
    foreach (db_lignes($ctx['db'], 'SELECT DISTINCT action FROM journal ORDER BY action') as $a) {
        $actions[$a['action']] = $a['action'];
    }
    $requete = http_build_query(['q' => $q, 'action' => $action]);
    $pages = '';
    if ($page > 1) {
        $pages .= '<a href="index.php?page=journal&amp;' . h($requete) . '&amp;p=' . ($page - 1) . '">Plus recents</a> ';
    }
    if ($page * ADMIN_PAR_PAGE < $total) {
        $pages .= '<a href="index.php?page=journal&amp;' . h($requete) . '&amp;p=' . ($page + 1) . '">Plus anciens</a>';
    }
    return '<form method="get" action="index.php" class="filtres"><input type="hidden" name="page" value="journal">'
        . f_champ('q', 'Recherche', $q, 'search') . f_choix('action', 'Action', $actions, $action)
        . '<button type="submit">Filtrer</button></form><p class="discret">' . $total . ' evenement(s).</p>'
        . tableau_html(['Date', 'Acteur', 'Action', 'Cible', 'Detail', 'IP'], $lignes, 'Aucun evenement.')
        . '<p>' . $pages . '</p><p><a href="index.php?page=export&amp;quoi=journal&amp;' . h($requete)
        . '">Exporter (CSV)</a></p>';
}

/** Cellule CSV ; une formule ne doit jamais etre interpretee par le tableur. */
function csv_cellule($valeur): string
{
    $texte = (string)$valeur;
    // Tabulation et retour chariot en tete aussi : certains tableurs les sautent puis evaluent la suite.
    if ($texte !== '' && strpos("=+-@\t\r", $texte[0]) !== false) {
        $texte = "'" . $texte;
    }
    return '"' . str_replace('"', '""', $texte) . '"';
}

function admin_export(array $ctx, string $quoi): array
{
    $db = $ctx['db'];
    $date = static function ($ts): string {
        return $ts === null ? '' : date('Y-m-d H:i', (int)$ts);
    };
    if ($quoi === 'licences') {
        $entetes = ['id', 'titulaire', 'email', 'produit', 'distribution', 'fin de cle', 'statut', 'echeance', 'tolerance_j',
            'options', 'id_poste', 'nom_ordinateur', 'version', 'lie_le', 'dernier_contact', 'derniere_ip', 'origine',
            'cree_le', 'note'];
        $lignes = [];
        foreach (db_lignes($db, 'SELECT l.*, d.code AS dc, p.code AS pc FROM licences l JOIN distributions d '
            . 'ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id ORDER BY l.titulaire, l.id') as $l) {
            $lignes[] = [$l['id'], $l['titulaire'], $l['email'], $l['pc'], $l['dc'], $l['cle_indice'],
                licence_statut_affiche($l, $ctx['maintenant']), $date($l['echeance']), $l['tolerance_j'], $l['options'],
                $l['id_poste'], $l['nom_ordinateur'], $l['version_appli'], $date($l['lie_le']), $date($l['dernier_contact']),
                $l['derniere_ip'], $l['origine'], $date($l['cree_le']), $l['note']];
        }
    } elseif ($quoi === 'demandes') {
        $entetes = ['id', 'date', 'statut', 'produit', 'distribution', 'nom_ordinateur', 'id_poste', 'titulaire', 'email',
            'message', 'version', 'essai_jusqu', 'traitee_le', 'motif_refus', 'licence', 'ip'];
        $lignes = [];
        foreach (db_lignes($db, 'SELECT dm.*, d.code AS dc, p.code AS pc FROM demandes dm JOIN distributions d '
            . 'ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id ORDER BY dm.id') as $d) {
            $lignes[] = [$d['id'], $date($d['cree_le']), $d['statut'], $d['pc'], $d['dc'], $d['nom_ordinateur'],
                $d['id_poste'], $d['titulaire'], $d['email'], $d['message'], $d['version_appli'], $date($d['essai_jusqu']),
                $date($d['traitee_le']), $d['motif_refus'], $d['licence_id'], $d['ip']];
        }
    } elseif ($quoi === 'journal') {
        [$where, $parametres] = journal_filtre($ctx['get']);
        $entetes = ['date', 'acteur', 'action', 'cible', 'detail', 'ip'];
        $lignes = [];
        foreach (db_lignes($db, 'SELECT * FROM journal' . $where . ' ORDER BY id', $parametres) as $j) {
            $lignes[] = [$date($j['date']), $j['acteur'], $j['action'], $j['cible'], $j['detail'], $j['ip']];
        }
    } else {
        throw new AdminErreur('Export inconnu.');
    }
    // Point-virgule et BOM UTF-8 : ouverture directe dans un tableur francais.
    $csv = "\xEF\xBB\xBF" . implode(';', array_map('csv_cellule', $entetes)) . "\r\n";
    foreach ($lignes as $ligne) {
        $csv .= implode(';', array_map('csv_cellule', $ligne)) . "\r\n";
    }
    return ['code' => 200, 'entetes' => admin_entetes() + [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="' . $quoi . '-' . date('Ymd-Hi', $ctx['maintenant']) . '.csv"',
    ], 'corps' => $csv];
}

function admin_ecran_sauvegarde(array $ctx): string
{
    $base = (string)$ctx['config']['base'];
    $copies = glob(rtrim((string)$ctx['config']['dossier_sauvegardes'], '/') . '/licenses-*.db') ?: [];
    rsort($copies);
    $lignes = [];
    foreach (array_slice($copies, 0, 10) as $copie) {
        $lignes[] = [h(basename($copie)), h(number_format((int)filesize($copie) / 1024, 0, ',', ' ') . ' Ko')];
    }
    return '<p>Base : ' . h(number_format((int)@filesize($base) / 1024, 0, ',', ' ')) . ' Ko.</p>'
        . f_formulaire($ctx, 'sauvegarde_telecharger', f_bouton('Telecharger une copie de la base', 'principal'))
        . '<p class="discret">La copie contient les empreintes des postes et les hachages des cles, jamais les cles '
        . 'en clair. La sauvegarde quotidienne est faite par la tache planifiee OVH (prive/sauvegarde.php).</p>'
        . '<h2>Dernieres sauvegardes quotidiennes</h2>' . tableau_html(['Fichier', 'Taille'], $lignes, 'Aucune sauvegarde : '
            . 'verifier la tache planifiee.');
}

// POST uniquement : la copie ecrit un fichier et le journal ; un simple lien
// (balise img sur un autre site) suffirait sinon a la declencher.
function admin_action_sauvegarde_telecharger(array $ctx): array
{
    $dossier = (string)$ctx['config']['dossier_sauvegardes'];
    if (!is_dir($dossier) && !mkdir($dossier, 0700, true) && !is_dir($dossier)) {
        throw new AdminErreur('Dossier de sauvegarde inaccessible.');
    }
    // Filet : une copie restee d'un telechargement interrompu ne survit pas plus d'une heure.
    foreach (glob($dossier . '/telechargement-*.db') ?: [] as $ancienne) {
        if (filemtime($ancienne) < time() - 3600) {
            @unlink($ancienne);
        }
    }
    $fichier = $dossier . '/telechargement-' . bin2hex(random_bytes(8)) . '.db';
    base_copier($ctx['db'], $fichier);
    admin_journal($ctx, 'sauvegarde_telechargee', null, null);
    return ['code' => 200, 'entetes' => admin_entetes() + [
        'Content-Type' => 'application/octet-stream',
        'Content-Disposition' => 'attachment; filename="licenses-' . date('Ymd-Hi', $ctx['maintenant']) . '.db"',
        'Content-Length' => (string)filesize($fichier),
    ], 'fichier' => $fichier, 'corps' => ''];
}

// ---------------------------------------------------------------------------
// Reglages
// ---------------------------------------------------------------------------

function admin_ecran_reglages(array $ctx): string
{
    $db = $ctx['db'];
    return '<section><h2>Notifications</h2>' . f_formulaire($ctx, 'reglages_enregistrer',
            f_champ('email_notification', 'Adresse(s) de notification (separees par des virgules, vide = aucun envoi)',
                reglage_lire($db, 'email_notification'), 'text', 'maxlength="500"')
            . f_champ('email_expediteur', 'Adresse expeditrice (sur le domaine du serveur)', reglage_lire($db, 'email_expediteur'),
                'email', 'maxlength="254"')
            . f_bouton('Enregistrer', 'principal'))
        . f_formulaire($ctx, 'email_test', f_bouton('Envoyer un e-mail de test')) . '</section>'
        . '<section><h2>Mot de passe de la console</h2>' . f_formulaire($ctx, 'mot_de_passe',
            f_champ('nouveau', 'Nouveau mot de passe (' . MOT_DE_PASSE_MIN . ' caracteres minimum)', '', 'password',
                'autocomplete="new-password" minlength="' . MOT_DE_PASSE_MIN . '" required')
            . f_champ('confirmation', 'Confirmation', '', 'password', 'autocomplete="new-password" required')
            . f_bouton('Changer le mot de passe'))
        . '<p class="discret">Le navigateur redemandera l\'identifiant et le nouveau mot de passe.</p></section>'
        . '<section><h2>Serveur</h2>' . fiche_html([
            'Version du serveur' => h(VERSION_SERVEUR),
            'Version de l\'API' => (string)VERSION_API,
            'PHP' => h(PHP_VERSION),
            'SQLite' => h((string)db_valeur($db, 'SELECT sqlite_version()')),
            'Utilisateur' => h($ctx['utilisateur']),
        ]) . '</section>';
}

function admin_action_reglages_enregistrer(array $ctx): array
{
    $adresses = [];
    foreach (explode(',', (string)($ctx['post']['email_notification'] ?? '')) as $adresse) {
        $adresse = trim($adresse);
        if ($adresse === '') {
            continue;
        }
        if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
            throw new AdminErreur('Adresse de notification invalide : ' . $adresse);
        }
        $adresses[] = $adresse;
    }
    $expediteur = (string)email_saisi($ctx['post']['email_expediteur'] ?? '', 'Adresse expeditrice');
    reglage_ecrire($ctx['db'], 'email_notification', implode(', ', $adresses));
    reglage_ecrire($ctx['db'], 'email_expediteur', $expediteur);
    admin_journal($ctx, 'reglages', null, 'notification : ' . (implode(', ', $adresses) ?: 'aucune')
        . ' ; expediteur : ' . ($expediteur ?: 'par defaut'));
    return admin_redirection('index.php?page=reglages&ok=reglages');
}

function admin_action_email_test(array $ctx): string
{
    $r = notification_envoyer($ctx['db'], $ctx['config'], '[ETDEL Licences] E-mail de test',
        "E-mail de test envoye depuis la console des licences ETDEL le " . date_fr($ctx['maintenant'], true)
        . " par " . $ctx['utilisateur'] . ".\n", $ctx['maintenant']);
    admin_journal($ctx, 'email_test', null, $r['message']);
    return '<p class="' . ($r['ok'] ? 'succes' : 'erreur') . '">' . h(ucfirst($r['message'])) . '.</p>'
        . '<p><a href="index.php?page=reglages">Retour aux reglages</a></p>';
}

function admin_action_mot_de_passe(array $ctx): string
{
    $nouveau = (string)($ctx['post']['nouveau'] ?? '');
    $erreur = mot_de_passe_erreur($nouveau, (string)($ctx['post']['confirmation'] ?? ''));
    if ($erreur !== null) {
        throw new AdminErreur($erreur);
    }
    htpasswd_ecrire((string)$ctx['config']['htpasswd'], $ctx['utilisateur'], $nouveau);
    admin_journal($ctx, 'mot_de_passe', $ctx['utilisateur'], null);
    return '<p class="succes">Mot de passe change. Le navigateur va redemander l\'identifiant et le nouveau mot de passe.</p>'
        . '<p><a href="index.php">Tableau de bord</a></p>';
}

// ---------------------------------------------------------------------------
// Cles de signature
// ---------------------------------------------------------------------------

function admin_ecran_cles(array $ctx): string
{
    $db = $ctx['db'];
    $active = signature_active($db, $ctx['config']);
    $urls = api_urls($db);
    $lignes_module = 'LICENCE_URL = "' . ($urls[0] ?? '') . "\"\n"
        . 'LICENCE_URL_SECOURS = "' . ($urls[1] ?? '') . "\"\n"
        . 'LICENCE_CLE_PUBLIQUE = "' . $active['cle_publique'] . '"';
    $html = '<h2>Cle publique active (kid ' . (int)$active['kid'] . ')</h2>'
        . bloc_copie('cle_publique', (string)$active['cle_publique'])
        . '<p>Lignes a reporter une fois en tete de <code>etdel_licence.py</code> :</p>'
        . '<div class="copie"><pre id="lignes_module">' . h($lignes_module) . '</pre>'
        . '<button type="button" data-copier="lignes_module">Copier</button></div>'
        . '<p class="discret">Les applications deja livrees n\'ont pas besoin d\'etre recompilees apres une rotation : '
        . 'elles adoptent la nouvelle cle par un bulletin signe par la precedente, diffuse pendant 12 mois. Les nouvelles '
        . 'versions peuvent embarquer la cle active.</p>';
    $lignes = [];
    foreach (db_lignes($db, 'SELECT * FROM cles_signature ORDER BY kid DESC') as $cle) {
        $lignes[] = [(int)$cle['kid'], '<code>' . h($cle['cle_publique']) . '</code>',
            h(date_fr((int)$cle['active_depuis'], true)),
            $cle['retiree_le'] === null ? etiquette('active') : h(date_fr((int)$cle['retiree_le'], true))
                . ' <span class="discret">(acceptee par les postes jusqu\'au ' . h(date_fr((int)$cle['retiree_le'] + 90 * JOUR))
                . ')</span>',
            $cle['bulletin'] === null ? 'premiere cle' : 'signe par la cle precedente'];
    }
    $html .= '<h2>Historique</h2>' . tableau_html(['kid', 'Cle publique', 'Active depuis', 'Retiree le', 'Bulletin'], $lignes);
    $html .= '<h2>Rotation</h2><p>Genere une nouvelle paire sur le serveur. La cle privee actuelle est effacee ; les postes '
        . 'qui se connectent adoptent la nouvelle cle et acceptent encore l\'ancienne pendant 90 jours. Un poste reste hors '
        . 'ligne plus de 12 mois apres une rotation devra etre mis a jour.</p>'
        . f_formulaire($ctx, 'cle_rotation', f_bouton('Nouvelle cle de signature', 'danger'));
    return $html;
}

function admin_action_cle_rotation(array $ctx): array
{
    $r = signature_rotation($ctx['db'], $ctx['config'], $ctx['maintenant']);
    admin_journal($ctx, 'cle_rotation', 'kid ' . $r['kid'], 'remplace le kid ' . $r['ancien_kid'] . ', cle publique '
        . $r['cle_publique']);
    return admin_redirection('index.php?page=cles&ok=rotation');
}
