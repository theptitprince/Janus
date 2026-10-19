<?php
// admin.php - Console d'administration : ecrans, actions d'ecriture (confirmation,
//             CSRF, journal avec l'utilisateur .htpasswd), exports CSV, sauvegarde, menage,
//             demandes de renouvellement.
// ETDEL (c) 2026
// Janus, par ETDEL - https://github.com/theptitprince/Janus
// GPL-3.0-or-later + conditions additionnelles (article 7) : voir NOTICE

declare(strict_types=1);

require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/demandes.php';
require_once __DIR__ . '/notification.php';
require_once __DIR__ . '/installation.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/aide.php';
require_once __DIR__ . '/menage.php';
require_once __DIR__ . '/renouvellements.php';

/** Erreur de saisie affichable telle quelle a l'administrateur. */
class AdminErreur extends RuntimeException
{
}

/**
 * Erreur de saisie d'un formulaire de creation : la page d'erreur le reaffiche, pre-rempli avec la saisie, sous
 * le message (rien n'est perdu ; le jeton CSRF n'est pas renouvele sur une erreur, le formulaire reste valable).
 */
class AdminErreurFormulaire extends AdminErreur
{
    /** @var string */
    public $titre;
    /** @var string */
    public $formulaire;

    public function __construct(string $message, string $titre, string $formulaire)
    {
        parent::__construct($message);
        $this->titre = $titre;
        $this->formulaire = $formulaire;
    }
}

// Libelles des actions d'ecriture : servent a la confirmation et au journal.
const ADMIN_ACTIONS = [
    'demande_accepter' => 'Accepter la demande et creer la cle',
    'demande_refuser' => 'Refuser la demande',
    'renouvellement_accepter' => 'Prolonger la licence (accepter le renouvellement)',
    'renouvellement_refuser' => 'Refuser le renouvellement',
    'licence_creer' => 'Creer une cle de licence',
    'licence_hors_ligne_creer' => 'Creer une licence hors ligne',
    'licence_hors_ligne_code' => 'Nouveau code hors ligne pour ce poste (l\'ancien code ne sera plus reconnu)',
    'licence_hors_ligne_poste' => 'Nouveau code hors ligne pour un autre ordinateur (les codes actuels ne seront plus '
        . 'reconnus)',
    'licence_prolonger' => 'Prolonger la licence',
    'licence_echeance' => 'Fixer une echeance (la licence ne sera plus perpetuelle)',
    'licence_perpetuelle' => 'Rendre la licence perpetuelle (sans echeance)',
    'licence_modifier' => 'Modifier la licence',
    'licence_suspendre' => 'Suspendre la licence',
    'licence_reactiver' => 'Reactiver la licence',
    'licence_revoquer' => 'Revoquer definitivement la licence',
    'licence_liberer' => 'Changer d\'ordinateur (liberer la cle)',
    'licence_nouvelle_cle' => 'Remplacer la cle (l\'ancienne cle ne fonctionnera plus)',
    'licence_afficher_cle' => 'Afficher la cle',
    'licence_supprimer' => 'Supprimer definitivement cette licence revoquee',
    'produit_enregistrer' => 'Enregistrer le produit',
    'produit_supprimer' => 'Supprimer definitivement ce produit',
    'menage_licences' => 'Supprimer definitivement les licences revoquees',
    'menage_produits' => 'Supprimer definitivement les produits sans licence',
    'url_ajouter' => 'Ajouter l\'URL',
    'url_modifier' => 'Modifier l\'URL',
    'reglages_enregistrer' => 'Enregistrer les reglages',
    'email_test' => 'Envoyer un e-mail de test',
    'mot_de_passe' => 'Changer le mot de passe',
    'cle_rotation' => 'Generer une nouvelle cle de signature (rotation, irreversible)',
    'sauvegarde_telecharger' => 'Telecharger une copie de la base',
];

// Actions sans renouvellement du jeton CSRF : la page reste affichee apres un telechargement, son
// formulaire doit rester utilisable.
const ADMIN_ACTIONS_SANS_RENOUVELLEMENT = ['sauvegarde_telecharger'];

// Lectures en POST (jeton CSRF) inscrites au journal : rien ne change, donc ni confirmation (ni boite
// de dialogue, ni page), ni renouvellement du jeton (les formulaires de la page affichee restent valables).
const ADMIN_ACTIONS_LECTURE = ['licence_afficher_cle'];

// Messages apres redirection : seul un code passe dans l'URL, jamais une donnee.
const ADMIN_MESSAGES = [
    'refusee' => 'Demande refusee.',
    'renouvellement_refuse' => 'Demande de renouvellement refusee.',
    'renouvelee' => 'Licence prolongee : l\'application recoit la nouvelle date a son prochain controle.',
    'prolongee' => 'Licence prolongee.',
    'echeance' => 'Echeance fixee : la licence n\'est plus perpetuelle.',
    'perpetuelle' => 'Licence rendue perpetuelle.',
    'licence_supprimee' => 'Licence supprimee.',
    'produit_supprime' => 'Produit supprime.',
    'modifiee' => 'Licence modifiee.',
    'suspendue' => 'Licence suspendue.',
    'reactivee' => 'Licence reactivee.',
    'revoquee' => 'Licence revoquee.',
    'liberee' => 'Cle liberee : elle peut etre saisie sur le nouvel ordinateur.',
    'produit' => 'Produit enregistre.',
    'cree' => 'Produit cree. Copiez la ligne ci-dessous dans l\'application.',
    'url' => 'Liste des URL mise a jour.',
    'reglages' => 'Reglages enregistres.',
    'rotation' => 'Nouvelle cle de signature active : les ordinateurs l\'adoptent a leur prochain controle.',
];

// Entree du menu mise en evidence pour chaque ecran qui n'a pas la sienne.
const ADMIN_MENU_PARENT = [
    'demande' => 'demandes',
    'renouvellement' => 'demandes',
    'licence' => 'licences',
    'licence_nouvelle' => 'licences',
    'licence_hors_ligne' => 'licences',
    'produit' => 'produits',
    'serveurs' => 'administration',
    'cles' => 'administration',
    'journal' => 'administration',
    'sauvegarde' => 'administration',
    'reglages' => 'administration',
    'menage' => 'administration',
];

// Page affichee en reponse a une action (POST) : entree du menu d'apres le debut du nom de l'action ;
// les autres actions sont celles des ecrans de l'Administration.
const ADMIN_MENU_ACTIONS = ['demande_' => 'demandes', 'renouvellement_' => 'demandes', 'licence_' => 'licences',
    'produit_' => 'produits'];

// Lien "Aide sur cet ecran" des ecrans que AIDE_ANCRES (aide.php) ne connait pas encore.
const ADMIN_AIDE_ANCRES = ['administration' => 'securite'];

const ADMIN_PAR_PAGE = 100;
const MESSAGE_ACCUEIL_MAX = 500;
// Phrase en tete des ecrans Produits et Nouveau produit.
const PRODUITS_EXPLICATION = 'Un produit = une application, reconnue par le code qu\'elle envoie au serveur, avec ses '
    . 'regles, ses licences et ses demandes. La premiere demande d\'une application inconnue cree son produit toute '
    . 'seule, avec les regles par defaut.';
// Un seul mot pour la personne ou l'entreprise a qui appartient une licence : "Client" ; les formulaires
// rappellent qu'il s'agit du titulaire, nom affiche par l'application.
const LIBELLE_TITULAIRE = 'Client (titulaire de la licence)';
// Licence creee avant que les cles soient conservees chiffrees : le client lit sa cle dans l'application ;
// "Nouvelle cle" (qui oblige a la ressaisir) seulement a defaut.
const CLE_NON_DISPONIBLE = 'Cle complete non disponible (licence creee avant cette fonction). Le client peut la lire '
    . 'dans l\'application : touches Ctrl+Maj+L, puis Afficher la cle. Sinon, Nouvelle cle en cree une autre, que '
    . 'l\'ordinateur du client devra saisir (l\'ancienne cesse de fonctionner).';
// Licence hors ligne : le code est verifie par l'application sans Internet ; ce qui change sur le serveur ne
// l'atteint que par un controle reussi.
const HORS_LIGNE_TYPE = 'Licence hors ligne : son code est verifie par l\'application sans Internet. Prolonger, '
    . 'Suspendre et Revoquer n\'atteignent l\'ordinateur que s\'il se connecte un jour.';
const HORS_LIGNE_MENTION = 'Licence hors ligne : n\'atteint le poste que s\'il se connecte un jour a Internet.';
const HORS_LIGNE_EXPLICATION = 'Pour un ordinateur jamais (ou rarement) connecte a Internet. L\'application y affiche '
    . 'son "Identifiant du poste" (fenetre d\'activation, ou fenetre Licence : touches Ctrl+Maj+L) ; le client vous le '
    . 'dicte ou vous l\'envoie. La console cree alors deux codes equivalents : un code court a dicter par telephone, et '
    . 'un code long a envoyer par e-mail. Le client saisit l\'un ou l\'autre avec "J\'ai une cle" : l\'application le '
    . 'verifie sans Internet, et ce code ne fonctionne que sur ce poste et pour ce produit.';
const HORS_LIGNE_LIMITE = 'Si l\'ordinateur se connecte un jour a Internet, l\'application controle aussi sa licence '
    . 'aupres du serveur : une prolongation, une suspension ou une revocation faite ici l\'atteint alors. S\'il ne se '
    . 'connecte jamais, seul un nouveau code change sa licence.';

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
        // Suspensions arrivees a leur date de fin : la console affiche l'etat reel.
        licences_fin_suspension($ctx['db'], $ctx['maintenant']);
        if ($ctx['methode'] === 'POST') {
            return admin_post($ctx, $session);
        }
        if ($ctx['methode'] !== 'GET') {
            return admin_page_brute(405, 'Methode refusee', 'GET ou POST uniquement.');
        }
        return admin_get($ctx);
    } catch (AdminErreurFormulaire $e) {
        return admin_page($ctx, $e->titre, '<p class="erreur">' . h($e->getMessage()) . '</p>' . $e->formulaire, 400);
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
            return admin_page($ctx, 'Accueil', admin_ecran_tableau($ctx));
        case 'demandes':
            return admin_page($ctx, 'Demandes', admin_ecran_demandes($ctx));
        case 'demande':
            return admin_page($ctx, 'Demande n. ' . $id, admin_ecran_demande($ctx, $id));
        case 'renouvellement':
            return admin_page($ctx, 'Renouvellement n. ' . $id, admin_ecran_renouvellement($ctx, $id));
        case 'licences':
            return admin_page($ctx, 'Licences', admin_ecran_licences($ctx));
        case 'licence':
            return admin_page($ctx, 'Licence n. ' . $id, admin_ecran_licence($ctx, $id));
        case 'licence_nouvelle':
            return admin_page($ctx, 'Nouvelle licence', admin_ecran_licence_nouvelle($ctx));
        case 'licence_hors_ligne':
            return admin_page($ctx, 'Licence hors ligne', admin_ecran_licence_hors_ligne($ctx));
        case 'produits':
            return admin_page($ctx, 'Produits', admin_ecran_produits($ctx));
        case 'produit':
            if ($id === 0) {
                return admin_page($ctx, 'Nouveau produit', admin_ecran_produit_nouveau($ctx));
            }
            $produit = produit_lire($ctx['db'], $id);
            if ($produit === null) {
                throw new AdminErreur('Produit inconnu.');
            }
            return admin_page($ctx, 'Produit ' . $produit['code'], admin_ecran_produit($ctx, $produit));
        case 'distributions':
            // Anciennes adresses (avant les produits) : vers les ecrans Produits.
            return admin_redirection('index.php?page=produits');
        case 'distribution':
            $produit = $id ? db_valeur($ctx['db'], 'SELECT produit_id FROM distributions WHERE id = ?', [$id]) : null;
            return admin_redirection($produit !== null ? 'index.php?page=produit&id=' . (int)$produit
                : ($id ? 'index.php?page=produits' : 'index.php?page=produit'));
        case 'administration':
            return admin_page($ctx, 'Administration', admin_ecran_administration($ctx));
        case 'serveurs':
            return admin_page($ctx, 'Serveurs', admin_ecran_serveurs($ctx));
        case 'journal':
            return admin_page($ctx, 'Journal', admin_ecran_journal($ctx));
        case 'sauvegarde':
            return admin_page($ctx, 'Sauvegarde', admin_ecran_sauvegarde($ctx));
        case 'reglages':
            return admin_page($ctx, 'Reglages', admin_ecran_reglages($ctx));
        case 'menage':
            return admin_page($ctx, 'Menage', admin_ecran_menage($ctx));
        case 'cles':
            return admin_page($ctx, 'Cles de signature', admin_ecran_cles($ctx));
        case 'aide':
            return admin_page($ctx, 'Aide', admin_ecran_aide($ctx));
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
    $lecture = in_array($action, ADMIN_ACTIONS_LECTURE, true);
    if (($ctx['post']['confirme'] ?? '') !== '1' && $action !== 'mot_de_passe' && !$lecture) {
        return admin_page($ctx, 'Confirmation', admin_ecran_confirmation($ctx, $action));
    }
    $fonction = 'admin_action_' . $action;
    $reponse = $fonction($ctx);
    // Jeton renouvele apres une ecriture reussie : un renvoi du formulaire est refuse.
    if (!$lecture && !in_array($action, ADMIN_ACTIONS_SANS_RENOUVELLEMENT, true)) {
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

// Mention exigee par les conditions additionnelles de la licence (GPL v3, article 7 b : fichier NOTICE du
// depot), en HTML : au pied de chaque ecran et dans Reglages. Texte seul, sans lien : la console ne pointe
// vers aucune ressource externe.
const ADMIN_MENTION = 'Janus, par ETDEL &mdash; https://github.com/theptitprince/Janus';

/** Pied de chaque ecran : mention, copyright, licence, absence de garantie, ou lire la licence. */
function admin_mentions(): string
{
    return '<footer class="mentions"><p>' . ADMIN_MENTION . ' (version ' . h(VERSION_SERVEUR) . ')</p>'
        . '<p>Copyright &copy; 2026 ETDEL. Logiciel libre sous licence GNU GPL version 3 ou ulterieure '
        . '(GPL-3.0-or-later), avec des conditions additionnelles (article 7, fichier NOTICE du depot), fourni '
        . 'SANS AUCUNE GARANTIE. Licence : https://www.gnu.org/licenses/gpl-3.0.html ; conditions additionnelles : '
        . 'https://github.com/theptitprince/Janus/blob/main/NOTICE. Une version modifiee doit le dire et ne pas se '
        . 'presenter comme l\'original.</p></footer>';
}

function admin_page(array $ctx, string $titre, string $contenu, int $code = 200): array
{
    // Demandes de licence et de renouvellement : toutes attendent une reponse.
    $attente = (int)db_valeur($ctx['db'], "SELECT (SELECT COUNT(*) FROM demandes WHERE statut = 'en_attente') "
        . "+ (SELECT COUNT(*) FROM renouvellements WHERE statut = 'en_attente')");
    $liens = [
        'tableau' => 'Accueil',
        'demandes' => 'Demandes' . ($attente > 0 ? ' <span class="pastille">' . $attente . '</span>' : ''),
        'licences' => 'Licences',
        'produits' => 'Produits',
        'aide' => 'Aide',
        'administration' => 'Administration',
    ];
    $courante = admin_menu_courant($ctx);
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
        . '<header><a class="marque" href="index.php?page=tableau">Licences ETDEL</a><nav>' . $nav . '</nav>'
        . '<span class="utilisateur">' . h($ctx['utilisateur']) . '</span></header>'
        . '<main><h1>' . h($titre) . '</h1>' . admin_aide_lien($ctx) . $bandeau . $contenu . '</main>'
        . admin_mentions() . '</body></html>';
    return ['code' => $code, 'entetes' => admin_entetes() + ['Content-Type' => 'text/html; charset=UTF-8'], 'corps' => $html];
}

/**
 * Entree du menu en evidence : celle de l'ecran consulte (GET), ou celle de l'action envoyee (POST :
 * cle affichee, erreur, confirmation sans JavaScript), jamais Accueil par defaut.
 */
function admin_menu_courant(array $ctx): string
{
    if ($ctx['methode'] === 'POST') {
        $action = $ctx['post']['action'] ?? '';
        if (!is_string($action) || !isset(ADMIN_ACTIONS[$action])) {
            return '';
        }
        foreach (ADMIN_MENU_ACTIONS as $prefixe => $page) {
            if (strncmp($action, $prefixe, strlen($prefixe)) === 0) {
                return $page;
            }
        }
        return 'administration';
    }
    $page = $ctx['get']['page'] ?? 'tableau';
    $page = is_string($page) ? $page : '';
    return ADMIN_MENU_PARENT[$page] ?? $page;
}

/** Lien "Aide sur cet ecran" : celui de aide.php, sinon ADMIN_AIDE_ANCRES (meme regle : ecran consulte en GET). */
function admin_aide_lien(array $ctx): string
{
    $lien = aide_lien($ctx);
    $page = $ctx['get']['page'] ?? 'tableau';
    if ($lien === '' && $ctx['methode'] === 'GET' && is_string($page) && isset(ADMIN_AIDE_ANCRES[$page])) {
        $lien = '<a class="aide-lien" href="index.php?page=aide#' . ADMIN_AIDE_ANCRES[$page] . '">Aide sur cet ecran</a>';
    }
    return $lien;
}

/** Formulaire d'une action, confirmee par app.js (data-confirmer), sauf une lecture (ADMIN_ACTIONS_LECTURE). */
function f_formulaire(array $ctx, string $action, string $contenu, array $caches = [], string $classe = 'formulaire'): string
{
    $confirmer = !in_array($action, ADMIN_ACTIONS_LECTURE, true);
    $html = '<form method="post" action="index.php" class="' . h($classe) . '"'
        . ($confirmer ? ' data-confirmer="' . h(ADMIN_ACTIONS[$action]) . '"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . h($ctx['csrf']) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . ($confirmer ? '<input type="hidden" name="confirme" value="0">' : '');
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
        'en_attente' => 'En attente', 'acceptee' => 'Acceptee', 'refusee' => 'Refusee', 'actif' => 'Actif',
        'inactif' => 'Inactif', 'auto' => 'cree automatiquement', 'hors_ligne' => 'hors ligne',
        'renouvellement' => 'renouvellement'];
    return '<span class="etiquette ' . h($statut) . '">' . h($libelles[$statut] ?? $statut) . '</span>';
}

/** Licence, avec son produit et les regles de celui-ci (tolerance et options heritees). */
function licence_lire(PDO $db, int $id): array
{
    $lic = db_ligne($db, 'SELECT l.*, p.id AS produit_id, p.code AS produit_code, p.nom AS produit_nom, '
        . 'r.tolerance_j AS produit_tolerance, r.options AS produit_options FROM licences l '
        . 'JOIN distributions d ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id '
        . 'JOIN distributions r ON r.id = ' . SQL_REGLES_DU_PRODUIT . ' WHERE l.id = ?', [$id]);
    if ($lic === null) {
        throw new AdminErreur('Licence inconnue.');
    }
    return $lic;
}

/** Lien vers la page d'un produit : "CODE - Nom" (le code seul si le nom le repete). */
function lien_produit(int $id, string $code, string $nom): string
{
    return '<a href="index.php?page=produit&amp;id=' . $id . '">' . h(produit_libelle($code, $nom)) . '</a>';
}

/** Produits pour une liste de choix (actifs seulement si demande), avec leur duree de licence par defaut. */
function produits_choix(PDO $db, bool $actifs_seulement): array
{
    $choix = [];
    $sql = 'SELECT ' . SQL_COLONNES_PRODUIT . ' FROM produits p JOIN distributions r ON r.id = ' . SQL_REGLES_DU_PRODUIT
        . ($actifs_seulement ? ' WHERE r.actif = 1' : '') . ' ORDER BY p.code';
    foreach (db_lignes($db, $sql) as $p) {
        $choix[(int)$p['id']] = [produit_libelle((string)$p['code'], (string)$p['nom']),
            ' data-duree="' . h($p['duree_defaut_j'] === null ? '' : (string)$p['duree_defaut_j']) . '"'];
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
        throw new AdminErreur('Options : codes en minuscules, chiffres et _ separes par des virgules (ex. export_pdf), '
            . 'ou * pour toutes les options.');
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
// Accueil et Administration
// ---------------------------------------------------------------------------

/** Accueil : demandes a traiter, raccourcis, compteurs, licences qui expirent bientot. */
function admin_ecran_tableau(array $ctx): string
{
    $db = $ctx['db'];
    $n = $ctx['maintenant'];
    // Demandes de licence et de renouvellement (etiquette), les plus anciennes d'abord.
    $attente = [];
    foreach (db_lignes($db, "SELECT dm.id, dm.cree_le, dm.titulaire, dm.nom_ordinateur, p.code AS produit_code "
        . "FROM demandes dm JOIN distributions d ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id "
        . "WHERE dm.statut = 'en_attente' ORDER BY dm.cree_le, dm.id") as $d) {
        $attente[] = [(int)$d['cree_le'], [h(date_fr((int)$d['cree_le'], true)), h($d['titulaire']),
            h($d['nom_ordinateur']), h($d['produit_code']),
            '<a class="bouton principal" href="index.php?page=demande&amp;id=' . (int)$d['id'] . '">Traiter</a>']];
    }
    foreach (renouvellements_en_attente($db) as $rn) {
        $attente[] = [(int)$rn['cree_le'], [h(date_fr((int)$rn['cree_le'], true)), h($rn['titulaire']) . ' '
            . etiquette('renouvellement'), h($rn['nom_ordinateur']), h($rn['produit_code']),
            '<a class="bouton principal" href="index.php?page=renouvellement&amp;id=' . (int)$rn['id'] . '">Traiter</a>']];
    }
    usort($attente, static function (array $a, array $b): int {
        return $a[0] <=> $b[0];
    });
    $html = '<h2>Demandes en attente</h2>' . tableau_html(['Date', 'Client', 'Ordinateur', 'Produit', ''],
        array_column($attente, 1), 'Aucune demande en attente.');
    $html .= '<p class="raccourcis"><a class="bouton principal" href="index.php?page=licence_nouvelle">Nouvelle licence</a>'
        . '<a class="bouton" href="index.php?page=produit">Nouveau produit</a></p>';
    $c = db_ligne($db, "SELECT "
        . "COALESCE(SUM(statut = 'active' AND (echeance IS NULL OR echeance > ?)), 0) AS actives, "
        . "COALESCE(SUM(statut = 'active' AND echeance IS NOT NULL AND echeance <= ?), 0) AS expirees, "
        . "COALESCE(SUM(statut = 'suspendue'), 0) AS suspendues, "
        . "COALESCE(SUM(statut = 'revoquee'), 0) AS revoquees, "
        . "COALESCE(SUM(dernier_contact >= ?), 0) AS vus FROM licences", [$n, $n, $n - 7 * JOUR]);
    $html .= '<h2>En chiffres</h2><div class="cartes">';
    // Chaque compteur de licences ouvre la liste filtree correspondante.
    foreach (['actives' => ['Licences actives', 'active'], 'expirees' => ['Licences expirees', 'expiree'],
              'suspendues' => ['Licences suspendues', 'suspendue'], 'revoquees' => ['Licences revoquees', 'revoquee'],
              'vus' => ['Ordinateurs vus sur 7 jours', '']] as $cle => [$libelle, $statut]) {
        $carte = '<strong>' . (int)$c[$cle] . '</strong><span>' . h($libelle) . '</span>';
        $html .= $statut === '' ? '<div class="carte">' . $carte . '</div>'
            : '<a class="carte" href="index.php?page=licences&amp;statut=' . $statut . '">' . $carte . '</a>';
    }
    $html .= '</div><h2>Licences expirant sous 30 jours</h2>';
    $lignes = [];
    foreach (db_lignes($db, "SELECT l.*, p.code AS produit_code FROM licences l JOIN distributions d "
        . "ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id WHERE l.statut = 'active' "
        . "AND l.echeance > ? AND l.echeance <= ? ORDER BY l.echeance LIMIT 100", [$n, $n + 30 * JOUR]) as $l) {
        $lignes[] = ['<a href="index.php?page=licence&amp;id=' . (int)$l['id'] . '">' . h($l['titulaire']) . '</a>',
            h($l['produit_code']), h(echeance_fr((int)$l['echeance'], licence_hors_ligne($l))),
            (int)jours_restants((int)$l['echeance'], $n) . ' j',
            h(($l['id_poste'] ?? '') . ' ' . ($l['nom_ordinateur'] ?? ''))
                . (licence_hors_ligne($l) ? ' ' . etiquette('hors_ligne') : '')];
    }
    return $html . tableau_html(['Client', 'Produit', 'Echeance', 'Reste', 'Ordinateur'], $lignes, 'Aucune.');
}

/** Administration : ecrans techniques (une phrase chacun) et controle d'exposition. */
function admin_ecran_administration(array $ctx): string
{
    $ecrans = [
        'serveurs' => ['Serveurs', 'Adresses de l\'API diffusees aux applications : en ajouter une, changer d\'adresse '
            . 'sans recompiler les applications.'],
        'cles' => ['Cles de signature', 'Cle publique et secret des codes hors ligne a inscrire dans les applications ; '
            . 'nouvelle cle en cas de doute sur la securite de l\'hebergement.'],
        'journal' => ['Journal', 'Tout ce qui a ete fait, depuis la console et par les applications ; recherche et '
            . 'export CSV.'],
        'sauvegarde' => ['Sauvegarde', 'Telecharger une copie de la base ; liste des sauvegardes quotidiennes.'],
        'reglages' => ['Reglages', 'E-mails de notification des nouvelles demandes (et e-mail de test), mot de passe de '
            . 'la console ; version et licence du logiciel.'],
        'menage' => ['Menage', 'Supprimer les licences revoquees et les produits qui n\'ont plus aucune licence, pour '
            . 'alleger les listes.'],
    ];
    $html = '<p>Ecrans techniques, utiles a l\'installation et de temps en temps, rarement au quotidien.</p>'
        . '<div class="menu-admin">';
    foreach ($ecrans as $page => [$titre, $texte]) {
        $html .= '<a class="carte lien" href="index.php?page=' . $page . '"><strong>' . h($titre) . '</strong><span>'
            . h($texte) . '</span></a>';
    }
    return $html . '</div><h2>Controle d\'exposition</h2><p class="discret">Le navigateur tente de telecharger la base, '
        . 'les cles et la configuration par leur URL : chaque ligne doit indiquer "protege".</p>'
        . '<ul id="exposition" data-chemins="'
        . h(json_encode(exposition_chemins($ctx['config'], $ctx['db']), JSON_UNESCAPED_SLASHES)) . '"></ul>';
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
        secret_fichier($config), secret_hors_ligne_fichier($config), $config['base'], $config['base'] . '-wal',
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
    foreach (db_lignes($db, "SELECT dm.*, p.code AS produit_code FROM demandes dm "
        . "JOIN distributions d ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id "
        . "WHERE dm.statut = 'en_attente' ORDER BY dm.cree_le") as $d) {
        $lignes[] = [h(date_fr((int)$d['cree_le'], true)), h($d['produit_code']),
            h($d['nom_ordinateur']), '<code>' . h($d['id_poste']) . '</code>', h($d['titulaire']), h($d['email']),
            '<span class="message">' . h($d['message']) . '</span>', h($d['ip']),
            $d['essai_jusqu'] === null ? 'aucun' : h(date_fr((int)$d['essai_jusqu'])),
            '<a class="bouton" href="index.php?page=demande&amp;id=' . (int)$d['id'] . '">Traiter</a>'];
    }
    $html = '<h2>En attente</h2>' . tableau_html(['Date', 'Produit', 'Ordinateur', 'Identifiant',
            'Client', 'E-mail', 'Message', 'IP', 'Essai jusqu\'au', ''], $lignes, 'Aucune demande en attente.');
    $html .= admin_renouvellements_listes($ctx);
    $lignes = [];
    foreach (db_lignes($db, "SELECT dm.*, p.code AS produit_code FROM demandes dm "
        . "JOIN distributions d ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id "
        . "WHERE dm.statut <> 'en_attente' ORDER BY dm.traitee_le DESC LIMIT ?", [ADMIN_PAR_PAGE]) as $d) {
        $suite = $d['statut'] === 'acceptee' && $d['licence_id'] !== null
            ? '<a href="index.php?page=licence&amp;id=' . (int)$d['licence_id'] . '">licence n. ' . (int)$d['licence_id'] . '</a>'
            : h($d['motif_refus'] ?? '');
        $lignes[] = [h(date_fr((int)$d['cree_le'])), h(date_fr((int)$d['traitee_le'])), etiquette($d['statut']),
            h($d['produit_code']), h($d['nom_ordinateur']), h($d['titulaire']),
            '<a href="index.php?page=demande&amp;id=' . (int)$d['id'] . '">n. ' . (int)$d['id'] . '</a>', $suite];
    }
    $html .= '<h2>Historique</h2>' . tableau_html(['Demandee le', 'Traitee le', 'Statut', 'Produit', 'Ordinateur',
            'Client', 'Demande', 'Licence ou motif'], $lignes, 'Aucune demande traitee.');
    return $html . '<p><a href="index.php?page=export&amp;quoi=demandes">Exporter les demandes (CSV)</a></p>';
}

function admin_ecran_demande(array $ctx, int $id): string
{
    $d = db_ligne($ctx['db'], 'SELECT dm.*, p.id AS produit_id, p.code AS produit_code, p.nom AS produit_nom, '
        . 'r.duree_defaut_j, r.options AS produit_options FROM demandes dm '
        . 'JOIN distributions d ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id '
        . 'JOIN distributions r ON r.id = ' . SQL_REGLES_DU_PRODUIT . ' WHERE dm.id = ?', [$id]);
    if ($d === null) {
        throw new AdminErreur('Demande inconnue.');
    }
    $html = fiche_html([
        'Statut' => etiquette($d['statut']),
        'Date' => h(date_fr((int)$d['cree_le'], true)),
        'Produit' => lien_produit((int)$d['produit_id'], $d['produit_code'], $d['produit_nom']),
        'Ordinateur' => h($d['nom_ordinateur']),
        'Identifiant du poste' => '<code>' . h($d['id_poste']) . '</code>',
        'Client' => h($d['titulaire']),
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
        . f_champ('titulaire', LIBELLE_TITULAIRE, $d['titulaire'], 'text', 'maxlength="120" required')
        . f_champ('options', 'Options (codes separes par des virgules, * = toutes)', implode(', ', options_lire($d['produit_options'])))
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
    $titulaire = (string)texte_saisi($ctx['post']['titulaire'] ?? '', 120, 'Client', true);
    $options = options_saisies($ctx['post']['options'] ?? '');
    $r = demande_accepter($ctx['db'], $ctx['config'], $id, $duree, $titulaire, $options, $ctx['utilisateur'], $ctx['ip'],
        $ctx['maintenant']);
    return '<p class="succes">Demande n. ' . $id . ' acceptee : la cle est transmise automatiquement a l\'application '
        . 'au prochain controle, deja liee au poste.</p><p>Cle (consultable ensuite sur la page de la licence, bouton '
        . '"Afficher la cle") :</p>'
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
// Demandes de renouvellement (D89) : envoyees par l'application dans les derniers jours d'une licence
// ---------------------------------------------------------------------------

/** Echeance d'une licence pour un renouvellement : "JJ/MM/AAAA (N jours restants)" ou "(depassee)". */
function renouvellement_echeance(array $rn, int $maintenant): string
{
    $echeance = (int)$rn['echeance'];
    $texte = echeance_fr($echeance, licence_hors_ligne($rn));
    if ($echeance <= $maintenant) {
        return $texte . ' (depassee)';
    }
    $jours = (int)jours_restants($echeance, $maintenant);
    return $texte . ' (' . $jours . ($jours > 1 ? ' jours restants)' : ' jour restant)');
}

/** Ecran Demandes : renouvellements a traiter, puis les derniers traites. */
function admin_renouvellements_listes(array $ctx): string
{
    $lignes = [];
    foreach (renouvellements_en_attente($ctx['db']) as $rn) {
        $lignes[] = [h(date_fr((int)$rn['cree_le'], true)), h($rn['produit_code']), h($rn['titulaire']),
            h(renouvellement_echeance($rn, $ctx['maintenant'])), h($rn['nom_ordinateur']),
            '<span class="message">' . h($rn['message']) . '</span>',
            '<a class="bouton" href="index.php?page=renouvellement&amp;id=' . (int)$rn['id'] . '">Traiter</a>'];
    }
    $html = '<h2>Renouvellements demandes</h2><p class="discret">Envoyes depuis l\'application, dans les derniers jours '
        . 'd\'une licence : accepter prolonge la licence, sans rien a ressaisir pour le client.</p>'
        . tableau_html(['Date', 'Produit', 'Client', 'Echeance', 'Ordinateur', 'Message', ''], $lignes,
            'Aucune demande de renouvellement en attente.');
    $lignes = [];
    foreach (db_lignes($ctx['db'], SQL_RENOUVELLEMENT . " WHERE rn.statut <> 'en_attente' ORDER BY rn.traitee_le DESC, "
        . 'rn.id DESC LIMIT ?', [ADMIN_PAR_PAGE]) as $rn) {
        $hors_ligne = licence_hors_ligne($rn);
        $suite = $rn['statut'] === 'acceptee' && $rn['echeance_apres'] !== null
            ? 'prolongee jusqu\'au ' . h(echeance_fr((int)$rn['echeance_apres'], $hors_ligne))
            : h($rn['motif_refus'] ?? '');
        $lignes[] = [h(date_fr((int)$rn['cree_le'])), h(date_fr((int)$rn['traitee_le'])), etiquette($rn['statut']),
            h($rn['produit_code']), '<a href="index.php?page=licence&amp;id=' . (int)$rn['licence_id'] . '">'
            . h($rn['titulaire']) . '</a>', $suite];
    }
    return $html . ($lignes === [] ? '' : '<h3>Renouvellements traites</h3>' . tableau_html(['Demande le', 'Traitee le',
        'Statut', 'Produit', 'Client', 'Suite'], $lignes));
}

/** Une demande de renouvellement : la licence concernee, puis Prolonger ou Refuser. */
function admin_ecran_renouvellement(array $ctx, int $id): string
{
    $rn = renouvellement_lire($ctx['db'], $id);
    if ($rn === null) {
        throw new AdminErreur('Demande de renouvellement inconnue.');
    }
    $html = fiche_html([
        'Statut' => etiquette($rn['statut']),
        'Date' => h(date_fr((int)$rn['cree_le'], true)),
        'Licence' => '<a href="index.php?page=licence&amp;id=' . (int)$rn['licence_id'] . '">licence n. '
            . (int)$rn['licence_id'] . '</a> (' . h(licence_designation($rn)) . ')',
        'Client' => h($rn['titulaire']),
        'Produit' => lien_produit((int)$rn['produit_id'], $rn['produit_code'], $rn['produit_nom']),
        'Echeance' => $rn['statut'] === 'en_attente' && $rn['echeance'] !== null
            ? h(renouvellement_echeance($rn, $ctx['maintenant']))
            : h(echeance_fr($rn['echeance_avant'] === null ? null : (int)$rn['echeance_avant'], licence_hors_ligne($rn))),
        'Ordinateur' => h($rn['nom_ordinateur']),
        'Mot du client' => '<span class="message">' . h($rn['message']) . '</span>',
        'Version de l\'application' => h($rn['version_appli']),
        'IP' => h($rn['ip']),
    ]);
    $hors_ligne = licence_hors_ligne($rn);
    if ($rn['statut'] === 'acceptee') {
        return $html . '<p>Acceptee le ' . h(date_fr((int)$rn['traitee_le'], true)) . ' : licence prolongee jusqu\'au '
            . h(echeance_fr($rn['echeance_apres'] === null ? null : (int)$rn['echeance_apres'], $hors_ligne)) . '.</p>';
    }
    if ($rn['statut'] === 'refusee') {
        return $html . '<p>Refusee le ' . h(date_fr((int)$rn['traitee_le'], true)) . ($rn['motif_refus'] !== null
            ? ' : ' . h($rn['motif_refus']) : ' (sans motif)') . '.</p>';
    }
    if ($rn['licence_statut'] === 'revoquee' || $rn['echeance'] === null) {
        $accepter = '<p class="alerte">' . ($rn['licence_statut'] === 'revoquee' ? 'Licence revoquee' : 'Licence '
            . 'perpetuelle') . ' : rien a prolonger. Refusez la demande.</p>';
    } else {
        $produit = produit_lire($ctx['db'], (int)$rn['produit_id']);
        $depart = max((int)$rn['echeance'], $ctx['maintenant']);
        $accepter = '<p class="discret">La duree s\'ajoute a partir du ' . h(echeance_fr($depart, $hors_ligne))
            . ($depart > $ctx['maintenant'] ? ' (echeance actuelle)' : ' (aujourd\'hui : l\'echeance est passee)')
            . '. Le client n\'a rien a faire : l\'application recoit la nouvelle date a son prochain controle.'
            . ($hors_ligne ? ' ' . HORS_LIGNE_MENTION : '') . '</p>'
            . f_formulaire($ctx, 'renouvellement_accepter', f_champ('duree_j', 'Prolonger de (jours)',
                $produit['duree_defaut_j'] ?? 365, 'number', 'min="1" max="36500" required')
                . f_bouton('Prolonger', 'principal'), ['id' => $id]);
    }
    $refuser = '<p class="discret">L\'application n\'est pas prevenue du refus : la licence s\'arrete a son echeance. '
        . 'Prevenez le client vous-meme si besoin.</p>'
        . f_formulaire($ctx, 'renouvellement_refuser', f_zone('motif', 'Motif (facultatif, inscrit au journal)', '',
            MOTIF_REFUS_MAX) . f_bouton('Refuser', 'danger'), ['id' => $id]);
    return $html . '<div class="colonnes"><section><h2>Prolonger</h2>' . $accepter . '</section>'
        . '<section><h2>Refuser</h2>' . $refuser . '</section></div>';
}

function admin_action_renouvellement_accepter(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $duree = (int)entier_saisi($ctx['post']['duree_j'] ?? '', 1, 36500, 'Duree');
    renouvellement_accepter($ctx['db'], $id, $duree, $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
    return admin_redirection('index.php?page=renouvellement&id=' . $id . '&ok=renouvelee');
}

function admin_action_renouvellement_refuser(array $ctx): array
{
    renouvellement_refuser($ctx['db'], (int)($ctx['post']['id'] ?? 0), (string)($ctx['post']['motif'] ?? ''),
        $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
    return admin_redirection('index.php?page=demandes&ok=renouvellement_refuse');
}

// ---------------------------------------------------------------------------
// Licences
// ---------------------------------------------------------------------------

function admin_ecran_licences(array $ctx): string
{
    $db = $ctx['db'];
    $q = trim((string)($ctx['get']['q'] ?? ''));
    $statut = (string)($ctx['get']['statut'] ?? '');
    $produit = (int)($ctx['get']['produit'] ?? 0);
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
    if ($produit > 0) {
        $conditions[] = 'd.produit_id = ?';
        $parametres[] = $produit;
    }
    $sql = 'SELECT l.*, p.code AS produit_code FROM licences l JOIN distributions d ON d.id = l.distribution_id '
        . 'JOIN produits p ON p.id = d.produit_id'
        . ($conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '')
        . ' ORDER BY l.titulaire COLLATE NOCASE, l.id LIMIT 500';
    $lignes = [];
    foreach (db_lignes($db, $sql, $parametres) as $l) {
        $hors_ligne = licence_hors_ligne($l);
        if ($l['machine'] !== null) {
            $ordinateur = '<code>' . h($l['id_poste']) . '</code> ' . h($l['nom_ordinateur']);
        } elseif ($hors_ligne) {
            // Licence hors ligne : le poste est connu des la creation, meme s'il ne se connecte jamais.
            $ordinateur = '<code>' . h($l['id_poste']) . '</code> <span class="discret">'
                . ($l['dernier_contact'] === null ? 'jamais connecte' : 'non lie') . '</span>';
        } else {
            $ordinateur = '<span class="discret">non lie</span>';
        }
        $lignes[] = ['<a href="index.php?page=licence&amp;id=' . (int)$l['id'] . '">' . h($l['titulaire']) . '</a>',
            h($l['produit_code']), '<code>...' . h($l['cle_indice']) . '</code>'
                . ($hors_ligne ? ' ' . etiquette('hors_ligne') : ''),
            $ordinateur,
            $l['echeance'] === null ? ($hors_ligne ? 'illimitee' : 'perpetuelle')
                : h(echeance_fr((int)$l['echeance'], $hors_ligne)),
            etiquette(licence_statut_affiche($l, $ctx['maintenant'])) . suspension_detail($l),
            h(date_fr($l['dernier_contact'] === null ? null
                : (int)$l['dernier_contact'], true))];
    }
    $produits = array_map(static function (array $choix): string {
        return $choix[0];
    }, produits_choix($db, false));
    $filtres = '<form method="get" action="index.php" class="filtres"><input type="hidden" name="page" value="licences">'
        . f_champ('q', 'Recherche', $q, 'search', 'placeholder="client, e-mail, fin de cle, ordinateur"')
        . f_choix('statut', 'Statut', ['' => 'tous', 'active' => 'actives', 'expiree' => 'expirees',
            'suspendue' => 'suspendues', 'revoquee' => 'revoquees'], $statut)
        . f_choix('produit', 'Produit', [0 => 'tous'] + $produits, $produit)
        . '<button type="submit">Filtrer</button></form>';
    // Arrive par "Voir ses licences" : la nouvelle licence est pour ce produit (s'il est actif), et on
    // peut revenir a sa page.
    if (isset($produits[$produit])) {
        $boutons = '<p class="raccourcis">' . (isset(produits_choix($db, true)[$produit])
                ? '<a class="bouton principal" href="index.php?page=licence_nouvelle&amp;produit=' . $produit
                    . '">Nouvelle licence pour ce produit</a><a class="bouton" href="index.php?page=licence_hors_ligne'
                    . '&amp;produit=' . $produit . '">Licence hors ligne pour ce produit</a>' : '')
            . '<a class="bouton" href="index.php?page=produit&amp;id=' . $produit . '">Retour au produit</a></p>';
    } else {
        $boutons = '<p class="raccourcis"><a class="bouton principal" href="index.php?page=licence_nouvelle">Nouvelle '
            . 'licence</a><a class="bouton" href="index.php?page=licence_hors_ligne">Licence hors ligne</a></p>';
    }
    return $boutons . $filtres
        . tableau_html(['Client', 'Produit', 'Cle', 'Ordinateur', 'Echeance', 'Statut', 'Dernier contact'],
            $lignes, 'Aucune licence.')
        . '<p><a href="index.php?page=export&amp;quoi=licences">Exporter les licences (CSV)</a></p>';
}

/**
 * Fiche d'une licence, pour un debutant : resume en tete, puis un bouton par action
 * (il deplie une explication et son formulaire), puis les reglages avances replies.
 * $cle : cle complete, affichee a la demande (Afficher la cle) ; sinon masquee. Licence hors ligne : $codes,
 * ses deux codes (licence_hors_ligne_codes), affiches a la demande (Afficher les codes).
 */
function admin_ecran_licence(array $ctx, int $id, ?string $cle = null, ?array $codes = null): string
{
    $lic = licence_lire($ctx['db'], $id);
    $n = $ctx['maintenant'];
    $hors_ligne = licence_hors_ligne($lic);
    if ($lic['echeance'] === null) {
        $echeance = $hors_ligne ? 'illimitee' : 'perpetuelle';
    } elseif ($lic['statut'] === 'revoquee') {
        // Jours restants sans objet : la licence ne sert plus, quelle que soit son echeance.
        $echeance = h(echeance_fr((int)$lic['echeance'], $hors_ligne)) . ' (sans objet : licence revoquee)';
    } elseif ((int)$lic['echeance'] <= $n) {
        $echeance = h(echeance_fr((int)$lic['echeance'], $hors_ligne)) . ' (depassee)';
    } else {
        $jours = (int)jours_restants((int)$lic['echeance'], $n);
        $echeance = h(echeance_fr((int)$lic['echeance'], $hors_ligne)) . ' (' . $jours
            . ($jours > 1 ? ' jours restants)' : ' jour restant)');
    }
    if ($hors_ligne) {
        return admin_ecran_licence_hors_ligne_fiche($ctx, $lic, $echeance, $codes) . '<h2>Que voulez-vous faire ?</h2>'
            . admin_licence_actions($ctx, $lic) . '<details class="avance"><summary>Reglages avances</summary>'
            . admin_licence_avance($ctx, $lic) . '</details>';
    }
    $html = fiche_html([
        'Statut' => etiquette(licence_statut_affiche($lic, $n)) . suspension_detail($lic),
        'Client' => h($lic['titulaire']),
        'Produit' => lien_produit((int)$lic['produit_id'], $lic['produit_code'], $lic['produit_nom']),
        'Cle' => $cle !== null ? bloc_copie('cle', $cle) : licence_cle_masquee($ctx, $lic),
        'Ordinateur' => $lic['machine'] === null ? 'aucun : la cle s\'activera sur le premier ordinateur ou elle sera saisie'
            : '<code>' . h($lic['id_poste']) . '</code> ' . h($lic['nom_ordinateur']),
        'Echeance' => $echeance,
        'Dernier contact' => $lic['dernier_contact'] === null ? 'jamais' : h(date_fr((int)$lic['dernier_contact'], true)),
    ]);
    return $html . '<h2>Que voulez-vous faire ?</h2>' . admin_licence_actions($ctx, $lic)
        . '<details class="avance"><summary>Reglages avances</summary>' . admin_licence_avance($ctx, $lic) . '</details>';
}

/**
 * Ligne "Cle" de la fiche : cle masquee, et le bouton "Afficher la cle" si elle est conservee (cles
 * creees depuis que la console les garde chiffrees) ; rien de plus pour une licence revoquee.
 */
function licence_cle_masquee(array $ctx, array $lic): string
{
    $hors_ligne = licence_hors_ligne($lic);
    $masquee = '<code>' . ($hors_ligne ? 'ETDHL' : 'ETDEL') . '-****-****-****-' . h($lic['cle_indice']) . '</code>';
    if ($hors_ligne) {
        // Fin du code long telle que l'application l'affiche (ETDHL2-****-...-XXXX-X) quand il y a ete saisi.
        try {
            $long = licence_hors_ligne_codes($ctx['db'], $ctx['config'], $lic)['long'] ?? null;
        } catch (RuntimeException $e) {
            $long = null;
        }
        if ($long !== null) {
            $masquee .= ' ou <code>ETDHL2-****-...-' . h(substr($long, -6)) . '</code>';
        }
    }
    if ($lic['statut'] === 'revoquee') {
        return $masquee;
    }
    if ($lic['cle_chiffree'] === null) {
        return $masquee . ' <span class="discret">' . h(CLE_NON_DISPONIBLE) . '</span>';
    }
    return $masquee . ' ' . f_formulaire($ctx, 'licence_afficher_cle', f_bouton($hors_ligne ? 'Afficher les codes'
        : 'Afficher la cle'), ['id' => (int)$lic['id']], 'en-ligne');
}

/** Les deux codes d'une licence hors ligne, chacun avec son bouton Copier ; $long null : non reaffichable. */
function codes_hors_ligne_html(string $court, ?string $long): string
{
    return '<p>Code court, a dicter par telephone :</p>' . bloc_copie('code_court', $court)
        . '<p>Code long, a envoyer par e-mail (copier-coller) :</p>'
        . ($long !== null ? bloc_copie('code_long', $long) : '<p class="discret">' . h(CODE_LONG_NON_DISPONIBLE) . '</p>');
}

/** Options d'une licence hors ligne, en clair : toutes, aucune, ou la liste fixee depuis la console. */
function hors_ligne_options_affichees(array $lic): string
{
    $options = $lic['options'] === null ? options_lire($lic['produit_options']) : options_lire($lic['options']);
    return in_array(OPTION_TOUTES, $options, true) ? 'toutes' : options_affichees($options);
}

/**
 * Resume en tete de la fiche d'une licence hors ligne : type, client, produit, codes (masques, ou affiches
 * par Afficher les codes), poste, echeance, options, dernier contact.
 */
function admin_ecran_licence_hors_ligne_fiche(array $ctx, array $lic, string $echeance, ?array $codes): string
{
    if ($lic['machine'] !== null) {
        $poste = '<code>' . h($lic['id_poste']) . '</code> ' . h($lic['nom_ordinateur'])
            . ' <span class="discret">(lie depuis son premier contact avec le serveur)</span>';
    } elseif ($lic['dernier_contact'] === null) {
        $poste = '<code>' . h($lic['id_poste']) . '</code> <span class="discret">(identifiant saisi a la creation ; '
            . 'jamais connecte au serveur : les codes ne fonctionnent que sur ce poste)</span>';
    } else {
        $poste = '<code>' . h($lic['id_poste']) . '</code> <span class="discret">(liee a aucun ordinateur : liberee par un '
            . 'transfert depuis l\'application, ou nouveau poste pas encore connecte ; les codes ne fonctionnent que sur ce '
            . 'poste. Pour le nouvel ordinateur d\'un transfert : "Nouveau code pour un autre ordinateur")</span>';
    }
    return fiche_html([
        'Statut' => etiquette(licence_statut_affiche($lic, $ctx['maintenant'])) . suspension_detail($lic),
        'Type' => etiquette('hors_ligne') . ' ' . h(HORS_LIGNE_TYPE),
        'Client' => h($lic['titulaire']),
        'Produit' => lien_produit((int)$lic['produit_id'], $lic['produit_code'], $lic['produit_nom']),
        'Codes' => $codes !== null ? codes_hors_ligne_html($codes['court'], $codes['long']) : licence_cle_masquee($ctx, $lic),
        'Poste' => $poste,
        'Echeance' => $echeance,
        'Options' => h(hors_ligne_options_affichees($lic)),
        'Dernier contact' => $lic['dernier_contact'] === null ? 'jamais' : h(date_fr((int)$lic['dernier_contact'], true)),
    ]);
}

/**
 * Afficher la cle : simple lecture (POST pour le jeton CSRF, sans confirmation), inscrite au journal
 * avec l'utilisateur de la console. La cle est dechiffree pour cette page seulement : jamais dans une
 * URL, le journal, un e-mail ni un export.
 */
function admin_action_licence_afficher_cle(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $lic = licence_lire($ctx['db'], $id);
    if ($lic['statut'] === 'revoquee') {
        throw new AdminErreur('Licence revoquee : sa cle ne fonctionne plus et n\'est plus affichee.');
    }
    if ($lic['cle_chiffree'] === null) {
        throw new AdminErreur(CLE_NON_DISPONIBLE);
    }
    if (licence_hors_ligne($lic)) {
        // Code court dechiffre, code long recalcule (signature deterministe) : jamais dans le journal.
        $codes = licence_hors_ligne_codes($ctx['db'], $ctx['config'], $lic);
        if ($codes === null) {
            throw new AdminErreur('Code hors ligne illisible (secret du serveur remplace ?) : utilisez "Nouveau code hors '
                . 'ligne pour ce poste" pour en generer un.');
        }
        admin_journal($ctx, 'cle_affichee', 'licence ' . $id, licence_designation($lic));
        return admin_page($ctx, 'Licence n. ' . $id, admin_ecran_licence($ctx, $id, null, $codes));
    }
    $cle = cle_dechiffrer((string)$lic['cle_chiffree'], secret_demandes($ctx['config']));
    if ($cle === null || cle_hash($cle) !== $lic['cle_hash']) {
        throw new AdminErreur('Cle complete illisible (secret du serveur remplace ?) : utilisez Nouvelle cle pour en '
            . 'generer une.');
    }
    admin_journal($ctx, 'cle_affichee', 'licence ' . $id, 'cle ...' . $lic['cle_indice']);
    return admin_page($ctx, 'Licence n. ' . $id, admin_ecran_licence($ctx, $id, $cle));
}

/** Une action de la fiche licence : bouton (summary) qui deplie une explication et son formulaire. */
function action_depliable(string $titre, string $explication, string $formulaire, string $classe = 'action'): string
{
    return '<details class="' . $classe . '"><summary>' . h($titre) . '</summary><div class="panneau"><p>'
        . h($explication) . '</p>' . $formulaire . '</div></details>';
}

/**
 * Actions de la fiche. Licence hors ligne : "Nouveau code hors ligne pour ce poste" d'abord ; Prolonger,
 * Suspendre, Reactiver et Revoquer portent la mention qu'ils n'atteignent le poste que s'il se connecte un
 * jour ; ni Nouvelle cle ni Changer d'ordinateur (le code ne vaut que pour le poste saisi).
 */
function admin_licence_actions(array $ctx, array $lic): string
{
    $id = (int)$lic['id'];
    $hors_ligne = licence_hors_ligne($lic);
    if ($lic['statut'] === 'revoquee') {
        // Revocation definitive (D61) : on sert de nouveau le client par une nouvelle licence (D67). Deja
        // remplacee : la remplacante est indiquee et le bouton passe au second plan (pas de doublon par megarde).
        $remplacantes = array_map(static function (int $autre): string {
            return 'la <a href="index.php?page=licence&amp;id=' . $autre . '">licence n. ' . $autre . '</a>';
        }, licence_remplacantes($ctx['db'], $id));
        $lien = '<p><a class="bouton' . ($remplacantes === [] ? ' principal' : '') . '" href="index.php?page=';
        return ($hors_ligne
                ? '<p>Cette licence hors ligne est revoquee : c\'est definitif, elle ne peut plus etre reactivee. Un poste '
                    . 'qui ne se connecte jamais garde son code jusqu\'a son echeance. Pour servir de nouveau ce poste, '
                    . 'creez une nouvelle licence hors ligne, avec de nouveaux codes a transmettre.</p>'
                : '<p>Cette licence est revoquee : c\'est definitif, elle ne peut plus etre reactivee. Pour servir de '
                    . 'nouveau ce client, creez-lui une nouvelle licence, avec une nouvelle cle a lui transmettre.</p>')
            . ($remplacantes === [] ? '' : '<p><strong>Deja remplacee par ' . implode(', ', $remplacantes) . '.</strong></p>')
            . ($hors_ligne
                ? $lien . 'licence_hors_ligne&amp;modele=' . $id . '">Nouvelle licence hors ligne pour ce poste</a></p>'
                : $lien . 'licence_nouvelle&amp;modele=' . $id . '">Nouvelle licence pour ce client</a></p>')
            . '<div class="actions">' . action_depliable('Supprimer cette licence', 'Definitif : la licence disparait '
                . 'de la base et des listes (seule une sauvegarde permet de revenir en arriere). Le journal garde la '
                . 'trace de ce qui a ete fait. Si un ordinateur presente encore ' . ($hors_ligne ? 'son code' : 'sa cle')
                . ', le serveur le refuse comme inconnu et l\'application l\'efface, comme pour une revocation. Pour '
                . 'supprimer d\'un coup toutes les licences revoquees : Administration, Menage.',
                f_formulaire($ctx, 'licence_supprimer', f_bouton('Supprimer definitivement', 'danger'), ['id' => $id],
                    'en-ligne'), 'action action-danger') . '</div>';
    }
    // Licence hors ligne : ce qui change ici n'atteint le poste que par un controle reussi. Liberee par un
    // transfert depuis l'application : le code pour le nouvel ordinateur vient en premier.
    $mention = $hors_ligne ? ' ' . HORS_LIGNE_MENTION : '';
    $html = '';
    $renouvellement = db_valeur($ctx['db'], "SELECT id FROM renouvellements WHERE licence_id = ? AND statut = 'en_attente'",
        [$id]);
    if ($renouvellement !== null) {
        // Prolonger ici repond aussi a la demande (renouvellements_clore).
        $html .= '<p class="alerte">Le client a demande le renouvellement de cette licence depuis l\'application. '
            . '<a href="index.php?page=renouvellement&amp;id=' . (int)$renouvellement . '">Traiter la demande</a>, ou '
            . 'utilisez Prolonger ci-dessous.</p>';
    }
    if ($hors_ligne) {
        $transferee = $lic['machine'] === null && $lic['dernier_contact'] !== null;
        $html = $transferee ? admin_action_autre_poste_formulaire($ctx, $lic) . admin_action_nouveau_code_formulaire($ctx, $lic)
            : admin_action_nouveau_code_formulaire($ctx, $lic) . admin_action_autre_poste_formulaire($ctx, $lic);
    }
    if ($lic['echeance'] !== null) {
        $html .= action_depliable('Prolonger', 'Ajoute du temps a partir de l\'echeance actuelle (ou d\'aujourd\'hui si '
            . 'elle est deja passee). ' . ($hors_ligne ? HORS_LIGNE_MENTION . ' Pour un poste qui ne se connecte jamais, '
            . 'utilisez plutot "Nouveau code hors ligne pour ce poste".' : 'Le client n\'a rien a faire : l\'application '
            . 'recoit la nouvelle date a son prochain controle.'),
            '<div class="rangee">'
            . f_formulaire($ctx, 'licence_prolonger', f_bouton('+30 jours', 'principal'), ['id' => $id, 'mode' => '30'],
                'en-ligne')
            . f_formulaire($ctx, 'licence_prolonger', f_bouton('+1 an', 'principal'), ['id' => $id, 'mode' => '365'],
                'en-ligne')
            . '</div>'
            . f_formulaire($ctx, 'licence_prolonger', f_champ('date', 'Ou choisir la nouvelle echeance', '', 'date',
                'required') . f_bouton('Fixer la date'), ['id' => $id, 'mode' => 'date'])
            // Changer d'avis (D90) : la meme licence, sans echeance ; le client n'a rien a ressaisir.
            . '<p class="discret">Ou supprimer l\'echeance : la licence devient perpetuelle.</p>'
            . f_formulaire($ctx, 'licence_perpetuelle', f_bouton('Rendre perpetuelle'), ['id' => $id], 'en-ligne'));
    } else {
        // Licence perpetuelle : lui fixer une echeance la rend datee (D90), sans nouvelle cle.
        $html .= action_depliable('Fixer une echeance', 'Licence perpetuelle : une echeance la rend datee, sans changer '
            . 'de cle. "+30 jours" et "+1 an" comptent a partir d\'aujourd\'hui. ' . ($hors_ligne ? HORS_LIGNE_MENTION
            : 'Le client n\'a rien a faire : l\'application recoit l\'echeance a son prochain controle, et l\'annonce '
            . '15 jours avant.'),
            '<div class="rangee">'
            . f_formulaire($ctx, 'licence_echeance', f_bouton('+30 jours', 'principal'), ['id' => $id, 'mode' => '30'],
                'en-ligne')
            . f_formulaire($ctx, 'licence_echeance', f_bouton('+1 an', 'principal'), ['id' => $id, 'mode' => '365'],
                'en-ligne')
            . '</div>'
            . f_formulaire($ctx, 'licence_echeance', f_champ('date', 'Ou choisir l\'echeance', '', 'date', 'required')
                . f_bouton('Fixer la date'), ['id' => $id, 'mode' => 'date']));
    }
    if (!$hors_ligne) {
        $html .= action_depliable('Nouvelle cle', 'Pour une cle diffusee ou transmise par erreur : une nouvelle cle '
            . 'remplace l\'ancienne, qui ne fonctionnera plus. Le client la saisit dans l\'application et elle s\'active sur '
            . 'le premier ordinateur ou elle est saisie ; elle reste consultable ici (Afficher la cle).',
            f_formulaire($ctx, 'licence_nouvelle_cle', f_bouton('Creer une nouvelle cle', 'principal'), ['id' => $id],
                'en-ligne'));
    }
    if ($lic['machine'] !== null && !$hors_ligne) {
        $html .= action_depliable('Changer d\'ordinateur', 'Le plus simple, si l\'ancien ordinateur fonctionne encore : '
            . 'dans l\'application, fenetre Licence (touches Ctrl+Maj+L), bouton "Transferer cette licence" ; la cle est '
            . 'liberee aussitot et l\'application se desactive sur cet ordinateur (' . TRANSFERTS_MAX . ' transferts au plus '
            . 'en ' . TRANSFERTS_PERIODE_J . ' jours). Sinon (ordinateur perdu ou en panne, ou transferts trop nombreux), '
            . 'liberez la cle ici : '
            . 'elle est detachee de l\'ordinateur actuel (' . $lic['id_poste'] . ' ' . $lic['nom_ordinateur'] . '), puis '
            . 'le client saisit la meme cle sur le nouveau. L\'ancien ordinateur perd la licence a son prochain controle.',
            f_formulaire($ctx, 'licence_liberer', f_bouton('Liberer cette cle', 'principal'), ['id' => $id], 'en-ligne'));
    }
    if ($lic['statut'] === 'active') {
        $html .= action_depliable('Suspendre', 'Bloque l\'application du client a son prochain controle, sans effacer '
            . ($hors_ligne ? 'son code' : 'sa cle') . '. Sans date, elle reste bloquee jusqu\'a ce que vous cliquiez sur '
            . '"Reactiver" ; avec une date, elle se debloque toute seule a la fin de ce jour-la.' . $mention,
            f_formulaire($ctx, 'licence_suspendre', f_champ('jusqu', 'Jusqu\'au (facultatif)', '', 'date')
                . f_bouton('Suspendre', 'principal'), ['id' => $id]));
    } else {
        $html .= action_depliable('Reactiver', 'Met fin a la suspension : l\'application du client se debloque a son '
            . 'prochain controle, sans rien ressaisir.' . $mention,
            f_formulaire($ctx, 'licence_reactiver', f_bouton('Reactiver', 'principal'), ['id' => $id], 'en-ligne'));
        // Date de fin actuelle pre-remplie : valider sans y toucher ne supprime pas la reactivation automatique.
        $fin = $lic['suspendue_jusqu'] === null ? '' : date('Y-m-d', (int)$lic['suspendue_jusqu']);
        $html .= action_depliable('Changer la date de fin', 'La licence reste suspendue jusqu\'a cette date, puis se '
            . 'reactive toute seule. Effacer la date pour une suspension sans fin prevue.' . $mention,
            f_formulaire($ctx, 'licence_suspendre', f_champ('jusqu', 'Nouvelle date de fin (vide = sans date)', $fin, 'date')
                . f_bouton('Changer la date de fin', 'principal'), ['id' => $id]));
    }
    $html .= action_depliable('Revoquer', $hors_ligne
        ? 'Definitif : la licence ne pourra plus etre reactivee et l\'ordinateur efface son code a son prochain controle '
            . 'reussi.' . $mention . ' Pour servir de nouveau ce poste ensuite, utilisez le bouton "Nouvelle licence hors '
            . 'ligne pour ce poste" qui apparaitra sur cette page.'
        : 'Definitif : la licence ne pourra plus etre reactivee et l\'ordinateur du client efface sa cle a son prochain '
            . 'controle. Pour servir de nouveau ce client ensuite, utilisez le bouton "Nouvelle licence pour ce client" '
            . 'qui apparaitra sur cette page.',
        f_formulaire($ctx, 'licence_revoquer', f_bouton('Revoquer definitivement', 'danger'), ['id' => $id], 'en-ligne'),
        'action action-danger');
    return '<div class="actions">' . $html . '</div>';
}

/**
 * "Nouveau code hors ligne pour ce poste" : nouvelle duree (proposee : celle du produit) et options ; le
 * nouveau code remplace l'ancien sur la meme ligne. C'est ainsi qu'on prolonge un poste jamais connecte.
 */
function admin_action_nouveau_code_formulaire(array $ctx, array $lic): string
{
    $produit = produit_lire($ctx['db'], (int)$lic['produit_id']);
    $toutes = in_array(OPTION_TOUTES, options_lire($lic['options']), true);
    return action_depliable('Nouveau code hors ligne pour ce poste', 'Cree un nouveau code court et un nouveau code long '
        . 'pour le meme poste (' . $lic['id_poste'] . '), avec une nouvelle duree comptee a partir d\'aujourd\'hui : '
        . 'c\'est ainsi qu\'on prolonge la licence d\'un poste qui ne se connecte jamais. Le client saisit le nouveau code '
        . 'avec "J\'ai une cle". Le serveur ne reconnait plus l\'ancien code : un poste qui se connecterait encore avec '
        . 'lui le perdrait.',
        f_formulaire($ctx, 'licence_hors_ligne_code', f_champ('duree_j', 'Duree en jours (vide = illimitee)',
            $produit['duree_defaut_j'] ?? '', 'number', 'min="1" max="36500"')
            . f_case('toutes_options', 'Toutes les options (decochee : aucune option)', $toutes)
            . f_bouton('Creer le nouveau code', 'principal'), ['id' => (int)$lic['id']]));
}

/**
 * "Nouveau code pour un autre ordinateur" : identifiant du nouveau poste (saisie tolerante), duree et options ;
 * le nouveau code remplace l'ancien sur la meme ligne, qui passe a ce poste. C'est la suite d'un transfert
 * depuis l'application, ou le remplacement d'un ordinateur perdu.
 */
function admin_action_autre_poste_formulaire(array $ctx, array $lic): string
{
    $produit = produit_lire($ctx['db'], (int)$lic['produit_id']);
    $toutes = in_array(OPTION_TOUTES, options_lire($lic['options']), true);
    return action_depliable('Nouveau code pour un autre ordinateur', 'Apres un transfert depuis l\'application, ou si '
        . 'l\'ordinateur ' . $lic['id_poste'] . ' est perdu ou remplace : saisissez l\'identifiant du poste affiche par '
        . 'l\'application sur le nouvel ordinateur. La licence passe a ce poste, avec un nouveau code court et un nouveau '
        . 'code long (nouvelle duree comptee a partir d\'aujourd\'hui). Les codes actuels ne sont plus reconnus par le '
        . 'serveur : un ordinateur qui se connecterait encore avec eux les perdrait.',
        f_formulaire($ctx, 'licence_hors_ligne_poste', f_champ('id_poste', 'Identifiant du nouveau poste (par exemple '
            . 'SHXT-2380)', '', 'text', 'maxlength="20" required autocomplete="off" spellcheck="false"')
            . f_champ('duree_j', 'Duree en jours (vide = illimitee)', $produit['duree_defaut_j'] ?? '', 'number',
                'min="1" max="36500"')
            . f_case('toutes_options', 'Toutes les options (decochee : aucune option)', $toutes)
            . f_bouton('Creer le code pour ce poste', 'principal'), ['id' => (int)$lic['id']]));
}

/** Reglages avances de la fiche licence : modification, informations techniques, historique. */
function admin_licence_avance(array $ctx, array $lic): string
{
    $id = (int)$lic['id'];
    $options = $lic['options'] === null ? options_lire($lic['produit_options']) : options_lire($lic['options']);
    // Licence hors ligne : pas de tolerance (sans reponse du serveur, l'application s'en tient a son code).
    $hors_ligne = licence_hors_ligne($lic);
    $html = '';
    if ($lic['statut'] !== 'revoquee') {
        $html .= '<h2>Modifier</h2>' . f_formulaire($ctx, 'licence_modifier',
            f_champ('titulaire', LIBELLE_TITULAIRE, $lic['titulaire'], 'text', 'maxlength="120" required')
            . f_champ('email', 'E-mail', $lic['email'] ?? '', 'email')
            . f_zone('note', 'Note interne', $lic['note'] ?? '', 500)
            . ($hors_ligne ? '' : f_champ('tolerance_j', 'Tolerance hors ligne en jours (vide = celle du produit)',
                $lic['tolerance_j'] ?? '', 'number', 'min="0" max="365"'))
            . f_case('options_produit', 'Options du produit', $lic['options'] === null)
            . f_champ('options', 'Options propres a la licence (* = toutes)', implode(', ', $options))
            . f_bouton('Enregistrer'), ['id' => $id]);
    }
    $informations = [
        'Produit' => h(produit_libelle((string)$lic['produit_code'], (string)$lic['produit_nom'])),
        'E-mail' => h($lic['email']),
        'Note' => '<span class="message">' . h($lic['note']) . '</span>',
        'Tolerance hors ligne' => $lic['tolerance_j'] === null ? 'produit (' . (int)$lic['produit_tolerance'] . ' j)'
            : (int)$lic['tolerance_j'] . ' j (surcharge)',
        'Options' => h(options_affichees($options)) . ($lic['options'] === null ? ' (produit)' : ' (surcharge)'),
        'Empreinte de l\'ordinateur' => $lic['machine'] === null ? ''
            : '<code>' . h(substr((string)$lic['machine'], 0, 12)) . '...</code>',
        'Version de l\'application' => h($lic['version_appli']),
        'Derniere IP' => h($lic['derniere_ip']),
        'Origine' => h($lic['origine']),
        'Lie le' => h(date_fr($lic['lie_le'] === null ? null : (int)$lic['lie_le'], true)),
        'Creee le' => h(date_fr((int)$lic['cree_le'], true)),
        'Modifiee le' => h(date_fr((int)$lic['modifie_le'], true)),
    ];
    if ($hors_ligne) {
        unset($informations['Tolerance hors ligne']);
    }
    $html .= '<h2>Informations techniques</h2>' . fiche_html($informations);
    $lignes = [];
    foreach (db_lignes($ctx['db'], 'SELECT * FROM journal WHERE cible = ? ORDER BY id DESC LIMIT 50', ['licence ' . $id]) as $j) {
        $lignes[] = [h(date_fr((int)$j['date'], true)), h($j['acteur']), h($j['action']), h($j['detail'])];
    }
    return $html . '<h2>Historique</h2>' . tableau_html(['Date', 'Acteur', 'Action', 'Detail'], $lignes, 'Aucun evenement.');
}

/**
 * Formulaire de creation d'une cle. Rien n'est choisi a la place de l'administrateur : un produit
 * n'est preselectionne que s'il est demande (produit=<id>, page d'un produit), si c'est celui du
 * modele (modele=<id>, licence revoquee, D67 : pre-rempli pour le meme client) ou si c'est le seul
 * actif ; sinon "-- choisir un produit --", a choisir avant l'envoi.
 */
function admin_ecran_licence_nouvelle(array $ctx): string
{
    $choix = produits_choix($ctx['db'], true);
    if ($choix === []) {
        return '<p>Aucun produit actif : <a href="index.php?page=produit">creer d\'abord un produit</a> (ou en reactiver '
            . 'un, sur l\'ecran <a href="index.php?page=produits">Produits</a>). La premiere demande envoyee par une '
            . 'application cree aussi son produit.</p>';
    }
    $produit = count($choix) === 1 ? (int)array_key_first($choix) : 0;
    $id_modele = (int)($ctx['get']['modele'] ?? 0);
    $modele = $id_modele > 0 ? licence_lire($ctx['db'], $id_modele) : null;
    $intro = '';
    if ($modele !== null) {
        $intro = '<p>Nouvelle licence pour <strong>' . h($modele['titulaire']) . '</strong>, en remplacement de la '
            . '<a href="index.php?page=licence&amp;id=' . $id_modele . '">licence n. ' . $id_modele . '</a> : verifiez '
            . 'les informations ci-dessous, puis cliquez sur "Creer la cle".</p>';
    }
    $voulu = $modele !== null ? (int)$modele['produit_id'] : (int)($ctx['get']['produit'] ?? 0);
    if ($voulu > 0 && isset($choix[$voulu])) {
        $produit = $voulu;
    } elseif ($voulu > 0) {
        // Produit inactif : aucun autre n'est choisi a sa place.
        $produit = 0;
        $intro .= '<p class="alerte">' . ($modele !== null ? 'Le produit de cette licence n\'est plus actif'
            : 'Ce produit n\'est pas actif') . ' : choisissez-en un autre, ou reactivez-le sur sa page '
            . '(Reglages avances, case Actif).</p>';
    }
    $duree = $produit > 0 ? produit_lire($ctx['db'], $produit)['duree_defaut_j'] : null;
    if ($produit === 0) {
        $choix = ['' => '-- choisir un produit --'] + $choix;
    }
    return $intro . f_formulaire($ctx, 'licence_creer',
        f_choix('produit_id', 'Produit', $choix, $produit === 0 ? '' : $produit, 'data-durees required')
        . f_champ('titulaire', LIBELLE_TITULAIRE, $modele['titulaire'] ?? '', 'text', 'maxlength="120" required')
        . f_champ('email', 'E-mail du client (facultatif)', $modele['email'] ?? '', 'email')
        . f_zone('note', 'Note interne (facultative)', $modele === null ? '' : 'Remplace la licence n. ' . $id_modele, 500)
        . f_champ('duree_j', 'Duree en jours (vide = perpetuelle)', $duree ?? '', 'number', 'min="1" max="36500"')
        . f_bouton('Creer la cle', 'principal'), $modele === null ? [] : ['modele' => $id_modele])
        . '<p class="discret">La cle est a transmettre au client, qui la saisit dans l\'application ; elle reste '
        . 'consultable sur la page de la licence (Afficher la cle). Elle se lie au premier ordinateur qui l\'active.</p>';
}

/** Licences creees pour remplacer une licence revoquee (journal "licence_remplacee"), dans l'ordre. */
function licence_remplacantes(PDO $db, int $id): array
{
    $ids = [];
    foreach (db_lignes($db, "SELECT detail FROM journal WHERE action = 'licence_remplacee' AND cible = ? ORDER BY id",
        ['licence ' . $id]) as $j) {
        if (preg_match('/^par la licence n\. (\d+)$/', (string)$j['detail'], $m) === 1
            && db_valeur($db, 'SELECT COUNT(*) FROM licences WHERE id = ?', [(int)$m[1]]) == 1) {
            $ids[] = (int)$m[1];
        }
    }
    return $ids;
}

/**
 * Cree une cle non liee pour un produit actif (sa ligne de regles), conservee chiffree pour la
 * console ; renvoie ['id' => ..., 'cle' => ...].
 */
function licence_creer(PDO $db, array $config, int $produit_id, string $titulaire, ?string $email, ?string $note,
                       ?int $duree_j, string $acteur, string $ip, int $maintenant): array
{
    $produit = produit_lire($db, $produit_id);
    if ($produit === null) {
        throw new AdminErreur('Produit inconnu.');
    }
    if ((int)$produit['actif'] !== 1) {
        throw new AdminErreur('Produit inactif : reactivez-le sur sa page (Reglages avances, case Actif) pour lui creer '
            . 'des licences. Rien n\'a ete cree.');
    }
    $cle = cle_generer();
    $id = db_inserer($db, 'licences', [
        'distribution_id' => (int)$produit['regles_id'], 'cle_hash' => cle_hash($cle), 'cle_indice' => substr($cle, -4),
        'cle_chiffree' => cle_chiffrer($cle, secret_demandes($config)),
        'titulaire' => $titulaire, 'email' => $email, 'note' => $note,
        'echeance' => $duree_j === null ? null : $maintenant + $duree_j * JOUR,
        'statut' => 'active', 'origine' => 'console', 'cree_le' => $maintenant, 'modifie_le' => $maintenant,
    ]);
    journal_ecrire($db, $acteur, 'licence_creee', 'licence ' . $id,
        $titulaire . ', ' . ($duree_j === null ? 'perpetuelle' : $duree_j . ' j') . ', cle ...' . substr($cle, -4), $ip, $maintenant);
    return ['id' => $id, 'cle' => $cle];
}

/**
 * Cree une cle depuis le formulaire Nouvelle licence. Avec modele (licence revoquee, D67), en une
 * transaction : la licence revoquee note sa remplacante (journal "licence_remplacee", affiche sur sa fiche).
 */
function admin_action_licence_creer(array $ctx)
{
    $p = $ctx['post'];
    $db = $ctx['db'];
    $produit = (int)($p['produit_id'] ?? 0);
    if ($produit <= 0) {
        throw new AdminErreur('Produit : choisissez-le dans la liste. Rien n\'a ete cree.');
    }
    $titulaire = (string)texte_saisi($p['titulaire'] ?? '', 120, 'Client', true);
    $email = email_saisi($p['email'] ?? '', 'E-mail');
    $note = texte_saisi($p['note'] ?? '', 500, 'Note', false, true);
    $duree = entier_saisi($p['duree_j'] ?? '', 1, 36500, 'Duree', true);
    $id_modele = (int)($p['modele'] ?? 0);
    $modele = $id_modele > 0 ? licence_lire($db, $id_modele) : null;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $r = licence_creer($db, $ctx['config'], $produit, $titulaire, $email, $note, $duree, $ctx['utilisateur'],
            $ctx['ip'], $ctx['maintenant']);
        if ($modele !== null && $modele['statut'] === 'revoquee') {
            admin_journal($ctx, 'licence_remplacee', 'licence ' . $id_modele, 'par la licence n. ' . $r['id']);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return '<p class="succes">Cle creee : copiez-la pour la transmettre au client. Elle reste consultable sur la page '
        . 'de la licence (Afficher la cle).</p>' . bloc_copie('cle', $r['cle'])
        . '<p><a href="index.php?page=licence&amp;id=' . (int)$r['id'] . '">Voir la licence</a> - '
        . '<a href="index.php?page=licence_nouvelle&amp;produit=' . $produit . '">Creer une autre licence pour ce produit</a>'
        . ' - <a href="index.php?page=produit&amp;id=' . $produit . '">Retour au produit</a></p>';
}

/**
 * Date saisie (AAAA-MM-JJ, champ date du navigateur) -> fin de cette journee
 * dans le fuseau de config.php ; refusee si invalide ou deja passee.
 */
function admin_date_fin_journee(string $saisie, int $maintenant): int
{
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $saisie) === 1
        ? DateTime::createFromFormat('!Y-m-d H:i:s', $saisie . ' 23:59:59') : false;
    // createFromFormat accepte le 31 fevrier (devient le 3 mars) : la date relue doit etre la date saisie.
    if ($date === false || $date->format('Y-m-d') !== $saisie || $date->getTimestamp() <= $maintenant) {
        throw new AdminErreur('Date invalide ou passee.');
    }
    return $date->getTimestamp();
}

function admin_action_licence_prolonger(array $ctx): array
{
    $lic = licence_lire($ctx['db'], (int)($ctx['post']['id'] ?? 0));
    if ($lic['echeance'] === null) {
        throw new AdminErreur('Licence perpetuelle : utilisez "Fixer une echeance".');
    }
    return admin_licence_echeance_changer($ctx, $lic, admin_echeance_saisie($ctx, (int)$lic['echeance']), 'prolongee');
}

/** Licence perpetuelle : une echeance la rend datee (D90), a partir d'aujourd'hui pour +30 jours et +1 an. */
function admin_action_licence_echeance(array $ctx): array
{
    $lic = licence_lire($ctx['db'], (int)($ctx['post']['id'] ?? 0));
    if ($lic['echeance'] !== null) {
        throw new AdminErreur('Cette licence a deja une echeance : utilisez "Prolonger".');
    }
    return admin_licence_echeance_changer($ctx, $lic, admin_echeance_saisie($ctx, $ctx['maintenant']), 'echeance');
}

/** Licence datee rendue perpetuelle (D90) : meme licence, meme cle, plus d'echeance. */
function admin_action_licence_perpetuelle(array $ctx): array
{
    $lic = licence_lire($ctx['db'], (int)($ctx['post']['id'] ?? 0));
    if ($lic['echeance'] === null) {
        throw new AdminErreur('Licence deja perpetuelle.');
    }
    return admin_licence_echeance_changer($ctx, $lic, null, 'perpetuelle');
}

/** Echeance choisie : +30 jours ou +1 an a partir de $depart (ou d'aujourd'hui s'il est passe), ou une date. */
function admin_echeance_saisie(array $ctx, int $depart): int
{
    $mode = (string)($ctx['post']['mode'] ?? '');
    if ($mode === '30' || $mode === '365') {
        return max($depart, $ctx['maintenant']) + (int)$mode * JOUR;
    }
    if ($mode === 'date') {
        return admin_date_fin_journee((string)($ctx['post']['date'] ?? ''), $ctx['maintenant']);
    }
    throw new AdminErreur('Echeance invalide.');
}

/**
 * Nouvelle echeance d'une licence non revoquee (null : perpetuelle). Une demande de renouvellement en attente
 * est satisfaite (D89). L'application recoit l'echeance a son controle suivant, sans rien ressaisir.
 */
function admin_licence_echeance_changer(array $ctx, array $lic, ?int $echeance, string $ok): array
{
    $id = (int)$lic['id'];
    if ($lic['statut'] === 'revoquee') {
        throw new AdminErreur('Licence revoquee.');
    }
    $hors_ligne = licence_hors_ligne($lic);
    $avant = $lic['echeance'] === null ? null : (int)$lic['echeance'];
    db_maj($ctx['db'], 'licences', ['echeance' => $echeance, 'modifie_le' => $ctx['maintenant']], 'id', $id);
    $close = renouvellements_clore($ctx['db'], $id, 'acceptee', $avant, $echeance, null, $ctx['maintenant']);
    $texte = static function (?int $date) use ($hors_ligne): string {
        return $date === null ? ($hors_ligne ? 'illimitee' : 'perpetuelle') : echeance_fr($date, $hors_ligne);
    };
    admin_journal($ctx, 'licence_prolongee', 'licence ' . $id, $texte($avant) . ' -> ' . $texte($echeance)
        . ($close > 0 ? ' (demande de renouvellement acceptee)' : ''));
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=' . $ok);
}

function admin_action_licence_modifier(array $ctx): array
{
    $p = $ctx['post'];
    $id = (int)($p['id'] ?? 0);
    licence_lire($ctx['db'], $id);
    $valeurs = [
        'titulaire' => (string)texte_saisi($p['titulaire'] ?? '', 120, 'Client', true),
        'email' => email_saisi($p['email'] ?? '', 'E-mail'),
        'note' => texte_saisi($p['note'] ?? '', 500, 'Note', false, true),
        'tolerance_j' => entier_saisi($p['tolerance_j'] ?? '', 0, 365, 'Tolerance', true),
        'options' => ($p['options_produit'] ?? '') === '1' ? null : json_encode(options_saisies($p['options'] ?? '')),
        'modifie_le' => $ctx['maintenant'],
    ];
    db_maj($ctx['db'], 'licences', $valeurs, 'id', $id);
    admin_journal($ctx, 'licence_modifiee', 'licence ' . $id, 'tolerance ' . ($valeurs['tolerance_j'] ?? 'du produit')
        . ', options ' . ($valeurs['options'] ?? 'du produit'));
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=modifiee');
}

function admin_changer_statut(array $ctx, array $depuis, string $vers, string $action, string $message): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $lic = licence_lire($ctx['db'], $id);
    if (!in_array($lic['statut'], $depuis, true)) {
        throw new AdminErreur('Impossible depuis le statut "' . $lic['statut'] . '".');
    }
    // Reactiver ou revoquer met fin a toute suspension datee.
    db_maj($ctx['db'], 'licences', ['statut' => $vers, 'suspendue_jusqu' => null, 'modifie_le' => $ctx['maintenant']],
        'id', $id);
    if ($vers === 'revoquee') {
        // Une demande de renouvellement en attente n'a plus d'objet.
        renouvellements_clore($ctx['db'], $id, 'refusee', null, null, 'licence revoquee', $ctx['maintenant']);
    }
    admin_journal($ctx, $action, 'licence ' . $id, $lic['statut'] . ' -> ' . $vers);
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=' . $message);
}

/** Suspend, ou change la date de fin d'une licence deja suspendue (vide = sans date de fin). */
function admin_action_licence_suspendre(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $lic = licence_lire($ctx['db'], $id);
    if (!in_array($lic['statut'], ['active', 'suspendue'], true)) {
        throw new AdminErreur('Impossible depuis le statut "' . $lic['statut'] . '".');
    }
    $saisie = trim((string)($ctx['post']['jusqu'] ?? ''));
    $jusqu = $saisie === '' ? null : admin_date_fin_journee($saisie, $ctx['maintenant']);
    db_maj($ctx['db'], 'licences', ['statut' => 'suspendue', 'suspendue_jusqu' => $jusqu,
        'modifie_le' => $ctx['maintenant']], 'id', $id);
    admin_journal($ctx, 'licence_suspendue', 'licence ' . $id, $lic['statut'] . ' -> suspendue, '
        . ($jusqu === null ? 'sans date de fin' : 'jusqu\'au ' . date_fr($jusqu)));
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=suspendue');
}

/** " jusqu'au JJ/MM/AAAA" ou " (sans date de fin)" pour une licence suspendue, sinon "". */
function suspension_detail(array $lic): string
{
    if ($lic['statut'] !== 'suspendue') {
        return '';
    }
    return $lic['suspendue_jusqu'] === null ? ' <span class="discret">(sans date de fin)</span>'
        : ' <span class="discret">jusqu\'au ' . h(date_fr((int)$lic['suspendue_jusqu'])) . '</span>';
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
    if (licence_hors_ligne($lic)) {
        throw new AdminErreur('Licence hors ligne : ses codes ne fonctionnent que sur le poste ' . $lic['id_poste']
            . '. Pour un autre ordinateur, creez une licence hors ligne pour son identifiant (Licences, Licence hors '
            . 'ligne).');
    }
    if ($lic['machine'] === null) {
        throw new AdminErreur('Cette cle n\'est liee a aucun ordinateur.');
    }
    db_maj($ctx['db'], 'licences', ['machine' => null, 'id_poste' => null, 'nom_ordinateur' => null, 'lie_le' => null,
        'modifie_le' => $ctx['maintenant']], 'id', $id);
    admin_journal($ctx, 'poste_libere', 'licence ' . $id, 'ancien poste ' . $lic['id_poste'] . ' (' . $lic['nom_ordinateur'] . ')');
    return admin_redirection('index.php?page=licence&id=' . $id . '&ok=liberee');
}

/**
 * Nouvelle cle (D67) pour une licence active ou suspendue, en une transaction : l'ancienne
 * cle n'existe plus pour l'API (cle_invalide : l'ancien ordinateur l'efface), l'ordinateur
 * lie est libere et une remise en attente de l'ancienne cle (demande acceptee) est annulee.
 * La nouvelle cle est conservee chiffree (Afficher la cle) ; la reponse l'affiche (pas de redirection).
 */
function admin_action_licence_nouvelle_cle(array $ctx): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $db = $ctx['db'];
    $db->exec('BEGIN IMMEDIATE');
    try {
        $lic = licence_lire($db, $id);
        if (!in_array($lic['statut'], ['active', 'suspendue'], true)) {
            throw new AdminErreur('Licence revoquee : sa cle ne peut plus etre remplacee. Utiliser "Nouvelle licence pour '
                . 'ce client" sur la fiche de la licence.');
        }
        if (licence_hors_ligne($lic)) {
            throw new AdminErreur('Licence hors ligne : utilisez "Nouveau code hors ligne pour ce poste" sur la fiche de '
                . 'la licence.');
        }
        $cle = cle_generer();
        db_maj($db, 'licences', ['cle_hash' => cle_hash($cle), 'cle_indice' => substr($cle, -4),
            'cle_chiffree' => cle_chiffrer($cle, secret_demandes($ctx['config'])), 'machine' => null,
            'id_poste' => null, 'nom_ordinateur' => null, 'lie_le' => null, 'modifie_le' => $ctx['maintenant']], 'id', $id);
        db_modifier($db, 'UPDATE demandes SET cle_chiffree = NULL WHERE licence_id = ?', [$id]);
        admin_journal($ctx, 'cle_remplacee', 'licence ' . $id, 'ancienne cle ...' . $lic['cle_indice'] . ' ; '
            . ($lic['machine'] === null ? 'aucun ordinateur lie'
                : 'ordinateur libere : ' . $lic['id_poste'] . ' (' . $lic['nom_ordinateur'] . ')'));
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return admin_page($ctx, 'Nouvelle cle', '<p class="succes">Nouvelle cle creee : copiez-la pour la transmettre au '
        . 'client. Elle reste consultable sur la page de la licence (Afficher la cle).</p>' . bloc_copie('cle', $cle)
        . '<p>L\'ancienne cle ne fonctionne plus. Envoyez cette cle au client : il la saisit dans l\'application avec '
        . '"J\'ai une cle". Elle se liera au premier ordinateur ou elle sera saisie.</p>'
        . '<p class="discret">L\'ordinateur qui utilisait l\'ancienne cle la voit refusee a son prochain controle : '
        . 'l\'application l\'efface et previent l\'utilisateur ; il faudra y saisir la nouvelle cle.</p>'
        . '<p><a href="index.php?page=licence&amp;id=' . $id . '">Retour a la licence</a></p>');
}

/** Supprime une licence revoquee (fiche de la licence) ; retour a la liste des licences. */
function admin_action_licence_supprimer(array $ctx): array
{
    $db = $ctx['db'];
    $db->exec('BEGIN IMMEDIATE');
    try {
        licence_supprimer($db, (int)($ctx['post']['id'] ?? 0), $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return admin_redirection('index.php?page=licences&ok=licence_supprimee');
}

// ---------------------------------------------------------------------------
// Licences hors ligne : un poste jamais (ou rarement) connecte, un code verifie sans Internet
// ---------------------------------------------------------------------------

const ID_POSTE_INVALIDE = 'Identifiant du poste : les 8 caracteres affiches par l\'application (fenetre d\'activation '
    . 'ou fenetre Licence), par exemple SHXT-2380 : chiffres et lettres, sans la lettre U. Rien n\'a ete cree.';

/**
 * Formulaire "Licence hors ligne" : produit, identifiant du poste (saisie tolerante), client, duree (celle du
 * produit proposee, vide = illimitee), case "Toutes les options" (cochee par defaut ; decochee = aucune).
 * Produit preselectionne comme pour une nouvelle licence (produit=<id>, seul produit actif) ; modele=<id>
 * (licence revoquee) : produit, client et, pour une licence hors ligne, poste repris. $saisie : champs envoyes
 * (formulaire reaffiche apres une erreur de saisie), repris tels quels.
 */
function admin_ecran_licence_hors_ligne(array $ctx, ?array $saisie = null): string
{
    $db = $ctx['db'];
    $explication = '<p>' . h(HORS_LIGNE_EXPLICATION) . '</p>';
    $choix = produits_choix($db, true);
    if ($choix === []) {
        return $explication . '<p>Aucun produit actif : <a href="index.php?page=produit">creer d\'abord un produit</a> (ou '
            . 'en reactiver un, sur l\'ecran <a href="index.php?page=produits">Produits</a>).</p>';
    }
    $texte = static function (string $champ) use ($saisie): string {
        return is_string($saisie[$champ] ?? null) ? $saisie[$champ] : '';
    };
    $produit = count($choix) === 1 ? (int)array_key_first($choix) : 0;
    $id_modele = (int)($saisie !== null ? $texte('modele') : ($ctx['get']['modele'] ?? 0));
    $modele = $id_modele > 0 ? licence_lire($db, $id_modele) : null;
    $intro = '';
    if ($modele !== null) {
        $intro = '<p>Nouvelle licence hors ligne pour <strong>' . h($modele['titulaire']) . '</strong>, en remplacement de '
            . 'la <a href="index.php?page=licence&amp;id=' . $id_modele . '">licence n. ' . $id_modele . '</a> : verifiez '
            . 'les informations ci-dessous, puis cliquez sur "Creer les codes".</p>';
    }
    if ($saisie !== null) {
        $voulu = (int)$texte('produit_id');
    } else {
        $voulu = $modele !== null ? (int)$modele['produit_id'] : (int)($ctx['get']['produit'] ?? 0);
    }
    if ($voulu > 0 && isset($choix[$voulu])) {
        $produit = $voulu;
    } elseif ($voulu > 0) {
        // Produit inactif : aucun autre n'est choisi a sa place.
        $produit = 0;
        $intro .= '<p class="alerte">' . ($modele !== null ? 'Le produit de cette licence n\'est plus actif'
            : 'Ce produit n\'est pas actif') . ' : choisissez-en un autre, ou reactivez-le sur sa page '
            . '(Reglages avances, case Actif).</p>';
    }
    $duree = $produit > 0 ? produit_lire($db, $produit)['duree_defaut_j'] : null;
    if ($produit === 0) {
        $choix = ['' => '-- choisir un produit --'] + $choix;
    }
    $poste = ($modele !== null && licence_hors_ligne($modele)) ? (string)$modele['id_poste'] : '';
    $titulaire = $modele['titulaire'] ?? '';
    $toutes = true;
    if ($saisie !== null) {
        [$poste, $titulaire, $duree] = [$texte('id_poste'), $texte('titulaire'), $texte('duree_j')];
        $toutes = $texte('toutes_options') === '1';
    }
    return $explication . $intro . f_formulaire($ctx, 'licence_hors_ligne_creer',
        f_choix('produit_id', 'Produit', $choix, $produit === 0 ? '' : $produit, 'data-durees required')
        . f_champ('id_poste', 'Identifiant du poste (affiche par l\'application, par exemple SHXT-2380)', $poste, 'text',
            'maxlength="20" required autocomplete="off" spellcheck="false"')
        . f_champ('titulaire', LIBELLE_TITULAIRE, $titulaire, 'text', 'maxlength="120" required')
        . f_champ('duree_j', 'Duree en jours (vide = illimitee)', $duree ?? '', 'number', 'min="1" max="36500"')
        . f_case('toutes_options', 'Toutes les options (decochee : aucune option)', $toutes)
        . f_bouton('Creer les codes', 'principal'), $modele === null ? [] : ['modele' => $id_modele])
        . '<p class="discret">' . h(HORS_LIGNE_LIMITE) . '</p>';
}

/** Page de resultat : les deux codes a copier, ce qu'ils contiennent, puis des liens. */
function admin_hors_ligne_resultat(string $message, array $produit, string $id_poste, string $titulaire, bool $toutes,
                                   array $r, string $liens): string
{
    return '<p class="succes">' . h($message) . '</p>' . codes_hors_ligne_html($r['court'], $r['long'])
        . fiche_html([
            'Produit' => h(produit_libelle((string)$produit['code'], (string)$produit['nom'])),
            'Identifiant du poste' => '<code>' . h($id_poste) . '</code>',
            'Client' => h($titulaire),
            'Echeance' => $r['echeance'] === null ? 'illimitee' : h(echeance_fr((int)$r['echeance'], true))
                . ' <span class="discret">(dernier jour de validite)</span>',
            'Options' => $toutes ? 'toutes' : 'aucune',
        ])
        . ($r['decalage'] > 0 ? '<p class="discret">Echeance repoussee de ' . (int)$r['decalage'] . ' jour(s) : la licence '
            . 'n. ' . (int)$r['identique'] . ' avait deja le meme code (meme poste, meme produit, meme fin, memes '
            . 'options).</p>' : '')
        . '<p class="discret">' . h(HORS_LIGNE_LIMITE) . '</p>' . $liens;
}

/**
 * Cree une licence hors ligne depuis le formulaire ; avec modele (licence revoquee), la remplacante est notee.
 * Saisie refusee (identifiant mal dicte, par exemple) : le formulaire est reaffiche avec ce qui a ete saisi.
 */
function admin_action_licence_hors_ligne_creer(array $ctx): string
{
    try {
        return admin_licence_hors_ligne_creer($ctx);
    } catch (AdminErreur | InvalidArgumentException $e) {
        if ($e instanceof AdminErreurFormulaire) {
            throw $e;
        }
        throw new AdminErreurFormulaire($e->getMessage(), ADMIN_ACTIONS['licence_hors_ligne_creer'],
            admin_ecran_licence_hors_ligne($ctx, $ctx['post']));
    }
}

function admin_licence_hors_ligne_creer(array $ctx): string
{
    $p = $ctx['post'];
    $db = $ctx['db'];
    $produit_id = (int)($p['produit_id'] ?? 0);
    if ($produit_id <= 0) {
        throw new AdminErreur('Produit : choisissez-le dans la liste. Rien n\'a ete cree.');
    }
    $id_poste = id_poste_normaliser($p['id_poste'] ?? '');
    if ($id_poste === null) {
        throw new AdminErreur(ID_POSTE_INVALIDE);
    }
    $titulaire = (string)texte_saisi($p['titulaire'] ?? '', 120, 'Client', true);
    $duree = entier_saisi($p['duree_j'] ?? '', 1, 36500, 'Duree', true);
    $toutes = ($p['toutes_options'] ?? '') === '1';
    $id_modele = (int)($p['modele'] ?? 0);
    $modele = $id_modele > 0 ? licence_lire($db, $id_modele) : null;
    $produit = produit_lire($db, $produit_id);
    if ($produit === null) {
        throw new AdminErreur('Produit inconnu.');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $r = licence_hors_ligne_creer($db, $ctx['config'], $produit, $id_poste, $titulaire, $duree, $toutes,
            $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
        if ($modele !== null && $modele['statut'] === 'revoquee') {
            admin_journal($ctx, 'licence_remplacee', 'licence ' . $id_modele, 'par la licence n. ' . $r['id']);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return admin_hors_ligne_resultat('Licence hors ligne creee pour le poste ' . $id_poste . '. Transmettez au client l\'un '
        . 'des deux codes, au choix : il le saisit dans l\'application avec "J\'ai une cle", sans connexion a Internet. '
        . 'Les codes restent consultables sur la page de la licence (Afficher les codes).', $produit, $id_poste, $titulaire,
        $toutes, $r, '<p><a href="index.php?page=licence&amp;id=' . (int)$r['id'] . '">Voir la licence</a> - '
        . '<a href="index.php?page=licence_hors_ligne&amp;produit=' . $produit_id . '">Creer une autre licence hors ligne</a>'
        . ' - <a href="index.php?page=produit&amp;id=' . $produit_id . '">Retour au produit</a></p>');
}

/**
 * Nouveau code pour le poste d'une licence hors ligne active ou suspendue, en une transaction : il remplace
 * l'ancien sur la meme ligne (nouvelle echeance, nouveaux hachages) ; la reponse affiche les deux codes.
 */
function admin_action_licence_hors_ligne_code(array $ctx): array
{
    return admin_hors_ligne_nouveau_code($ctx, null);
}

/**
 * Nouveau code pour un autre ordinateur (identifiant saisi) : la licence passe a ce poste, n'est plus liee a
 * aucun ordinateur et les anciens codes ne sont plus reconnus ; la reponse affiche les deux nouveaux codes.
 */
function admin_action_licence_hors_ligne_poste(array $ctx): array
{
    $id_poste = id_poste_normaliser($ctx['post']['id_poste'] ?? '');
    if ($id_poste === null) {
        throw new AdminErreur(str_replace('Rien n\'a ete cree.', 'Rien n\'a ete change.', ID_POSTE_INVALIDE));
    }
    return admin_hors_ligne_nouveau_code($ctx, $id_poste);
}

/** Nouveau code d'une licence hors ligne, pour son poste ($id_poste null) ou pour un autre, en une transaction. */
function admin_hors_ligne_nouveau_code(array $ctx, ?string $id_poste): array
{
    $id = (int)($ctx['post']['id'] ?? 0);
    $duree = entier_saisi($ctx['post']['duree_j'] ?? '', 1, 36500, 'Duree', true);
    $toutes = ($ctx['post']['toutes_options'] ?? '') === '1';
    $db = $ctx['db'];
    $db->exec('BEGIN IMMEDIATE');
    try {
        $lic = licence_lire($db, $id);
        if (!licence_hors_ligne($lic)) {
            throw new AdminErreur('Cette licence n\'est pas une licence hors ligne : utilisez "Nouvelle cle".');
        }
        if ($lic['statut'] === 'revoquee') {
            throw new AdminErreur('Licence revoquee : utilisez "Nouvelle licence hors ligne pour ce poste" sur la fiche de '
                . 'la licence.');
        }
        $r = licence_hors_ligne_nouveau_code($db, $ctx['config'], $lic, $duree, $toutes, $ctx['utilisateur'], $ctx['ip'],
            $ctx['maintenant'], $id_poste);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    $poste = $id_poste ?? (string)$lic['id_poste'];
    $message = $poste === (string)$lic['id_poste']
        ? 'Nouveau code hors ligne cree pour le poste ' . $poste . ' : il remplace l\'ancien, que le serveur ne reconnait '
            . 'plus. Transmettez au client l\'un des deux codes : il le saisit dans l\'application avec "J\'ai une cle".'
        : 'La licence passe au poste ' . $poste . ' (auparavant ' . $lic['id_poste'] . ') : nouveaux codes ci-dessous, les '
            . 'anciens ne sont plus reconnus par le serveur. Transmettez au client l\'un des deux codes : il le saisit sur le '
            . 'nouvel ordinateur avec "J\'ai une cle".';
    return admin_page($ctx, 'Nouveau code hors ligne', admin_hors_ligne_resultat($message,
        ['code' => $lic['produit_code'], 'nom' => $lic['produit_nom']], $poste, (string)$lic['titulaire'],
        $toutes, $r, '<p><a href="index.php?page=licence&amp;id=' . $id . '">Retour a la licence</a></p>'));
}

// ---------------------------------------------------------------------------
// Produits : une application, reconnue par son code, et ses regles
// ---------------------------------------------------------------------------

/** Ligne a inserer dans l'application, juste apres la creation de sa fenetre principale. */
function ligne_installer(string $produit): string
{
    return 'etdel_licence.installer(root, produit="' . $produit . '", version=APP_VERSION)';
}

/** Regles d'un produit en une phrase : "Licence 365 jours, essai 15 jours, options : export_pdf, hors ligne 15 jours". */
function regles_resume(array $p): string
{
    $jours = static function ($n): string {
        return (int)$n . ((int)$n > 1 ? ' jours' : ' jour');
    };
    $options = options_lire($p['options']);
    if ($options === []) {
        $texte_options = 'aucune option';
    } elseif (in_array(OPTION_TOUTES, $options, true)) {
        $texte_options = 'toutes les options';
    } else {
        $texte_options = 'options : ' . implode(', ', $options);
    }
    return ($p['duree_defaut_j'] === null ? 'Licence perpetuelle' : 'Licence ' . $jours($p['duree_defaut_j']))
        . ', ' . ((int)$p['essai_j'] === 0 ? 'pas d\'essai' : 'essai ' . $jours($p['essai_j']))
        . ', ' . $texte_options . ', hors ligne ' . $jours($p['tolerance_j']);
}

/** Regles essentielles d'un produit ($p null : valeurs proposees pour un nouveau produit). */
function f_regles_essentiel(?array $p): string
{
    return '<p class="discret">La duree est proposee pour chaque nouvelle licence. L\'essai laisse l\'application '
        . 'fonctionner pendant qu\'une demande attend votre reponse (une seule fois par ordinateur pour ce produit). '
        . 'Les options sont les codes des fonctions que l\'application active selon la licence (vide si elle n\'en a '
        . 'pas).</p>'
        . f_champ('duree_defaut_j', 'Duree de licence par defaut (jours, vide = perpetuelle)',
            $p === null ? REGLES_DEFAUT['duree_defaut_j'] : ($p['duree_defaut_j'] ?? ''), 'number', 'min="1" max="36500"')
        . f_champ('essai_j', 'Essai pendant une demande (jours, 0 = aucun)', $p['essai_j'] ?? REGLES_DEFAUT['essai_j'],
            'number', 'min="0" max="365" required')
        . f_champ('options', 'Options (codes separes par des virgules, * = toutes)',
            implode(', ', options_lire($p['options'] ?? '[]')));
}

/** Reglages avances d'un produit, replies (ouverts si $ouvert) : tolerance, preavis, message d'accueil, case Actif. */
function f_regles_avance(?array $p, bool $ouvert = false): string
{
    return '<details class="avance"' . ($ouvert ? ' open' : '') . '><summary>Reglages avances</summary>'
        . '<p class="discret">Les valeurs proposees conviennent dans la plupart des cas. Tolerance hors ligne : nombre de '
        . 'jours pendant lesquels l\'application fonctionne sans joindre le serveur. Preavis : l\'application previent '
        . 'l\'utilisateur ce nombre de jours avant la fin de cette tolerance. Un produit inactif bloque ses licences et '
        . 'refuse les demandes (l\'application recoit "produit inconnu").</p>'
        . f_champ('tolerance_j', 'Tolerance hors ligne (jours, 0 a 365)', $p['tolerance_j'] ?? REGLES_DEFAUT['tolerance_j'],
            'number', 'min="0" max="365" required')
        . f_champ('preavis_j', 'Preavis (jours, 0 a 365)', $p['preavis_j'] ?? REGLES_DEFAUT['preavis_j'], 'number',
            'min="0" max="365" required')
        . f_zone('message', 'Message d\'accueil (facultatif, affiche dans la fenetre Licence de l\'application)',
            $p['message'] ?? '', MESSAGE_ACCUEIL_MAX)
        . f_case('actif', 'Actif', $p === null || (int)$p['actif'] === 1) . '</details>';
}

/** Regles saisies par f_regles_essentiel() et f_regles_avance() : colonnes de la ligne de regles. */
function regles_saisies(array $p): array
{
    return [
        'tolerance_j' => entier_saisi($p['tolerance_j'] ?? '', 0, 365, 'Tolerance'),
        'preavis_j' => entier_saisi($p['preavis_j'] ?? '', 0, 365, 'Preavis'),
        'duree_defaut_j' => entier_saisi($p['duree_defaut_j'] ?? '', 1, 36500, 'Duree par defaut', true),
        'essai_j' => entier_saisi($p['essai_j'] ?? '', 0, 365, 'Essai'),
        'options' => json_encode(options_saisies($p['options'] ?? '')),
        'message' => texte_saisi($p['message'] ?? '', MESSAGE_ACCUEIL_MAX, 'Message', false, true),
        'actif' => ($p['actif'] ?? '') === '1' ? 1 : 0,
    ];
}

/**
 * Ecran Produits : une ligne par produit (code et etat, nom, regles en une phrase, licences,
 * demandes en attente) ; etiquette "cree automatiquement" tant qu'un produit cree par une
 * demande n'a pas ete enregistre depuis sa page.
 */
function admin_ecran_produits(array $ctx): string
{
    $db = $ctx['db'];
    $auto = produits_crees_automatiquement($db);
    $lignes = [];
    foreach (db_lignes($db, 'SELECT ' . SQL_COLONNES_PRODUIT . ', '
        . '(SELECT COUNT(*) FROM licences l JOIN distributions d ON d.id = l.distribution_id WHERE d.produit_id = p.id) '
        . 'AS nb_licences, (SELECT COUNT(*) FROM demandes dm JOIN distributions d ON d.id = dm.distribution_id '
        . "WHERE d.produit_id = p.id AND dm.statut = 'en_attente') AS nb_attente "
        . 'FROM produits p JOIN distributions r ON r.id = ' . SQL_REGLES_DU_PRODUIT . ' ORDER BY p.code') as $p) {
        $id = (int)$p['id'];
        // Etat a cote du code : toujours visible, meme sur un ecran etroit.
        $lignes[] = ['<a href="index.php?page=produit&amp;id=' . $id . '">' . h($p['code']) . '</a> '
            . etiquette((int)$p['actif'] === 1 ? 'actif' : 'inactif') . (isset($auto[$id]) ? ' ' . etiquette('auto') : ''),
            h($p['nom']), h(regles_resume($p)),
            '<a href="index.php?page=licences&amp;produit=' . $id . '">' . (int)$p['nb_licences'] . '</a>',
            (int)$p['nb_attente'] === 0 ? '0' : '<a href="index.php?page=demandes">' . (int)$p['nb_attente'] . '</a>'];
    }
    return '<p>' . h(PRODUITS_EXPLICATION) . '</p>'
        . '<p><a class="bouton principal" href="index.php?page=produit">Nouveau produit</a></p>'
        . '<div class="produits">' . tableau_html(['Produit', 'Nom', 'Regles', 'Licences', 'En attente'], $lignes,
            'Aucun produit : creez-en un, ou laissez l\'application le creer par sa premiere demande.') . '</div>';
}

/**
 * Page d'un produit : en-tete (code definitif, nom, etat), ligne installer(...) a copier, ses
 * licences, puis ses regles (essentiel, puis reglages avances replies, deplies s'il est inactif).
 */
function admin_ecran_produit(array $ctx, array $p): string
{
    $id = (int)$p['id'];
    $actif = (int)$p['actif'] === 1;
    $licences = (int)db_valeur($ctx['db'], 'SELECT COUNT(*) FROM licences l JOIN distributions d '
        . 'ON d.id = l.distribution_id WHERE d.produit_id = ?', [$id]);
    $auto = isset(produits_crees_automatiquement($ctx['db'])[$id]);
    return fiche_html([
            'Code' => '<code>' . h($p['code']) . '</code> <span class="discret">(definitif : c\'est le code que '
                . 'l\'application envoie)</span>',
            'Nom' => h($p['nom']),
            'Etat' => etiquette($actif ? 'actif' : 'inactif') . ($actif ? '' : ' <span class="discret">ses licences '
                . 'sont bloquees et les demandes refusees (Reglages avances, case Actif)</span>'),
        ])
        . ($auto ? '<p class="alerte">Produit cree automatiquement le ' . h(date_fr((int)$p['cree_le'])) . ' par la '
            . 'premiere demande de l\'application, avec les regles par defaut : verifiez-les ci-dessous, puis cliquez sur '
            . '"Enregistrer".</p>' : '')
        . '<p>Ligne a inserer dans l\'application, juste apres la creation de la fenetre principale :</p>'
        . bloc_copie('installer', ligne_installer($p['code']))
        . '<p class="raccourcis"><a class="bouton" href="index.php?page=licences&amp;produit=' . $id
        . '">Voir ses licences (' . $licences . ')</a>'
        . ($actif ? '<a class="bouton principal" href="index.php?page=licence_nouvelle&amp;produit=' . $id
            . '">Nouvelle licence pour ce produit</a><a class="bouton" href="index.php?page=licence_hors_ligne&amp;produit='
            . $id . '">Licence hors ligne pour ce produit</a>' : '') . '</p>'
        . ($actif ? '' : '<p class="alerte">Produit inactif : reactivez-le (Reglages avances, case Actif) pour lui creer '
            . 'des licences.</p>')
        . '<h2>Regles</h2>' . f_formulaire($ctx, 'produit_enregistrer',
            f_champ('nom', 'Nom du produit', $p['nom'], 'text', 'maxlength="120" required')
            . f_regles_essentiel($p) . f_regles_avance($p, !$actif)
            . f_bouton('Enregistrer', 'principal'), ['id' => $id])
        . admin_produit_suppression($ctx, $id, $licences);
}

/**
 * Bas de la page d'un produit sans aucune licence : "Supprimer ce produit" (D88), ou ce qui l'empeche encore
 * (demande en attente). Rien pour un produit qui a des licences.
 */
function admin_produit_suppression(array $ctx, int $id, int $licences): string
{
    if ($licences > 0) {
        return '';
    }
    $raison = produit_suppression_impossible($ctx['db'], $id);
    if ($raison !== null) {
        return '<p class="discret">Ce produit n\'a aucune licence. ' . h($raison) . ' Il pourra ensuite etre supprime.</p>';
    }
    return '<div class="actions">' . action_depliable('Supprimer ce produit', 'Ce produit n\'a aucune licence : vous '
        . 'pouvez le supprimer, avec ses regles et ses demandes deja traitees. C\'est definitif (seule une sauvegarde '
        . 'permet de revenir en arriere) ; le journal garde la trace de ce qui a ete fait. Si l\'application envoie un '
        . 'jour une nouvelle demande, son produit sera recree automatiquement avec les regles par defaut, et la periode '
        . 'd\'essai pourra de nouveau etre accordee.',
        f_formulaire($ctx, 'produit_supprimer', f_bouton('Supprimer definitivement', 'danger'), ['id' => $id], 'en-ligne'),
        'action action-danger') . '</div>';
}

function admin_action_produit_supprimer(array $ctx): array
{
    $db = $ctx['db'];
    $db->exec('BEGIN IMMEDIATE');
    try {
        produit_supprimer($db, (int)($ctx['post']['id'] ?? 0), $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return admin_redirection('index.php?page=produits&ok=produit_supprime');
}

/** Nouveau produit (pour preparer des cles avant toute demande) : code definitif, nom, regles proposees. */
function admin_ecran_produit_nouveau(array $ctx): string
{
    return '<p>' . h(PRODUITS_EXPLICATION) . ' Creez-le ici pour preparer des cles avant toute demande.</p>'
        . f_formulaire($ctx, 'produit_enregistrer',
            '<p class="discret">Le code est celui que l\'application envoie (ligne installer(...) donnee apres '
            . 'l\'enregistrement) : il ne pourra plus changer. Exemple : MONAPPLI.</p>'
            . f_champ('code', 'Code du produit (definitif)', '', 'text', 'maxlength="64" required placeholder="ex. MONAPPLI"')
            . f_champ('nom', 'Nom du produit', '', 'text', 'maxlength="120" required placeholder="ex. Mon application"')
            . f_regles_essentiel(null) . f_regles_avance(null)
            . f_bouton('Enregistrer', 'principal'), ['id' => 0]);
}

/**
 * Enregistre un produit, en une transaction. Creation (id = 0) : le produit et ses regles ; un code
 * deja pris, meme a la casse pres (COMPTA face a compta), est refuse en nommant l'existant. Modification :
 * nom, regles et etat, jamais le code (inscrit dans l'application) ; l'etiquette "cree automatiquement"
 * disparait.
 */
function admin_action_produit_enregistrer(array $ctx): array
{
    $p = $ctx['post'];
    $db = $ctx['db'];
    $id = (int)($p['id'] ?? 0);
    $nom = (string)texte_saisi($p['nom'] ?? '', 120, 'Nom du produit', true);
    $regles = regles_saisies($p);
    $code = $id === 0 ? code_saisi($p['code'] ?? '', 'Code du produit') : '';
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($id !== 0) {
            $produit = produit_lire($db, $id);
            if ($produit === null) {
                throw new AdminErreur('Produit inconnu.');
            }
            // L'etat du produit est celui de ses regles ; produits.actif suit, pour une base lisible.
            db_maj($db, 'produits', ['nom' => $nom, 'actif' => $regles['actif']], 'id', $id);
            produit_regles_enregistrer($db, $id, $regles);
            admin_journal($ctx, 'produit_modifie', 'produit ' . $id, $produit['code'] . ' : '
                . json_encode(['nom' => $nom] + $regles));
            $ok = 'produit';
        } else {
            $existant = produit_code_existant($db, $code);
            if ($existant !== null) {
                throw new AdminErreur('Le produit ' . $existant . ' existe deja' . ($existant !== $code
                    ? ' (meme code a la casse pres)' : '') . '. Rien n\'a ete cree.');
            }
            $id = produit_creer($db, $code, $nom, $regles, $ctx['maintenant']);
            admin_journal($ctx, 'produit_cree', 'produit ' . $id, $code . ' (' . $nom . ')');
            $ok = 'cree';
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return admin_redirection('index.php?page=produit&id=' . $id . '&ok=' . $ok);
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
    return '<p>Liste signee diffusee a chaque reponse, dans l\'ordre de priorite (plus petit = premier). Les ordinateurs '
        . 'la memorisent : changer d\'URL ne demande jamais de recompiler les applications.</p>'
        . tableau_html(['URL', 'Etat', 'Reglage'], $lignes, 'Aucune URL.')
        . '<h2>Ajouter une URL</h2>' . f_formulaire($ctx, 'url_ajouter',
            f_champ('url', 'URL de l\'API (https://.../api/v1/)', '', 'url', 'required maxlength="300"')
            . f_champ('priorite', 'Priorite', 20, 'number', 'min="0" max="9999"') . f_bouton('Ajouter', 'principal'))
        . '<h2>Suivi d\'une migration</h2><p>Ordinateurs actifs vus depuis 24 h : <strong>' . (int)$vus['j1'] . '</strong>, '
        . '7 jours : <strong>' . (int)$vus['j7'] . '</strong>, 30 jours : <strong>' . (int)$vus['j30'] . '</strong> sur '
        . (int)$vus['liees'] . ' ordinateurs lies.</p><p class="discret">Procedure : ajouter la nouvelle URL, attendre que '
        . 'les ordinateurs se soient connectes (colonne dernier contact), puis desactiver l\'ancienne.</p>';
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
        // Jamais la cle ni un code hors ligne (ni chiffres) : seulement leurs 4 derniers caracteres.
        $entetes = ['id', 'titulaire', 'email', 'produit', 'fin de cle', 'statut', 'echeance', 'tolerance_j',
            'options', 'id_poste', 'nom_ordinateur', 'version', 'lie_le', 'dernier_contact', 'derniere_ip', 'origine',
            'cree_le', 'note', 'hors ligne'];
        $lignes = [];
        foreach (db_lignes($db, 'SELECT l.*, p.code AS pc FROM licences l JOIN distributions d '
            . 'ON d.id = l.distribution_id JOIN produits p ON p.id = d.produit_id ORDER BY l.titulaire, l.id') as $l) {
            $lignes[] = [$l['id'], $l['titulaire'], $l['email'], $l['pc'], $l['cle_indice'],
                licence_statut_affiche($l, $ctx['maintenant']), $date($l['echeance']), $l['tolerance_j'], $l['options'],
                $l['id_poste'], $l['nom_ordinateur'], $l['version_appli'], $date($l['lie_le']), $date($l['dernier_contact']),
                $l['derniere_ip'], $l['origine'], $date($l['cree_le']), $l['note'], licence_hors_ligne($l) ? 'oui' : 'non'];
        }
    } elseif ($quoi === 'demandes') {
        $entetes = ['id', 'date', 'statut', 'produit', 'nom_ordinateur', 'id_poste', 'titulaire', 'email',
            'message', 'version', 'essai_jusqu', 'traitee_le', 'motif_refus', 'licence', 'ip'];
        $lignes = [];
        foreach (db_lignes($db, 'SELECT dm.*, p.code AS pc FROM demandes dm JOIN distributions d '
            . 'ON d.id = dm.distribution_id JOIN produits p ON p.id = d.produit_id ORDER BY dm.id') as $d) {
            $lignes[] = [$d['id'], $date($d['cree_le']), $d['statut'], $d['pc'], $d['nom_ordinateur'],
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
        . '<p class="discret">La copie contient les empreintes des ordinateurs, les hachages des cles et les cles '
        . 'chiffrees, jamais les cles en clair : le secret qui les dechiffre reste dans prive/cles/, hors de la base. La '
        . 'sauvegarde quotidienne est faite par la tache planifiee OVH (prive/sauvegarde.php).</p>'
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
// Menage (D88) : licences revoquees et produits sans licence
// ---------------------------------------------------------------------------

/**
 * Ecran Menage : ce qui serait supprime, liste par liste, avec un bouton par liste. Le formulaire porte les
 * numeros affiches : seul ce que l'administrateur a vu est supprime, meme si la liste a change entre-temps.
 */
function admin_ecran_menage(array $ctx): string
{
    $html = '<p>Pour alleger les listes, supprimez ce qui ne sert plus. C\'est definitif : seule une sauvegarde '
        . '(Administration, Sauvegarde) permet de revenir en arriere. Le journal garde la trace de tout ce qui a ete '
        . 'fait.</p>';
    $lignes = [];
    $ids = [];
    foreach (menage_licences_revoquees($ctx['db']) as $l) {
        $ids[] = (int)$l['id'];
        $lignes[] = ['<a href="index.php?page=licence&amp;id=' . (int)$l['id'] . '">' . h($l['titulaire']) . '</a>',
            h($l['produit_code']), '<code>...' . h($l['cle_indice']) . '</code>'
                . (licence_hors_ligne($l) ? ' ' . etiquette('hors_ligne') : ''),
            h(date_fr($l['revoquee_le'] === null ? (int)$l['modifie_le'] : (int)$l['revoquee_le'])),
            h(date_fr($l['dernier_contact'] === null ? null : (int)$l['dernier_contact']))];
    }
    $n = count($ids);
    $html .= '<h2>Licences revoquees (' . $n . ')</h2><p class="discret">Une licence revoquee ne peut plus servir. La '
        . 'supprimer l\'efface de la base et des listes. Si un ordinateur presente encore sa cle (ou son code hors '
        . 'ligne), le serveur la refuse comme inconnue et l\'application l\'efface, comme pour une revocation. Les '
        . 'demandes deja traitees restent dans l\'historique : la periode d\'essai n\'est pas redonnee.</p>'
        . tableau_html(['Client', 'Produit', 'Cle', 'Revoquee le', 'Dernier contact'], $lignes,
            'Aucune licence revoquee.');
    if ($n > 0) {
        $html .= f_formulaire($ctx, 'menage_licences', f_bouton('Supprimer ' . ($n > 1 ? 'les ' . $n
            . ' licences revoquees' : 'la licence revoquee'), 'danger'), ['ids' => implode(',', $ids)], 'raccourcis');
    }
    $lignes = [];
    $ids = [];
    foreach (menage_produits_sans_licence($ctx['db']) as $p) {
        $attente = (int)$p['nb_attente'] > 0;
        if (!$attente) {
            $ids[] = (int)$p['id'];
        }
        $lignes[] = ['<a href="index.php?page=produit&amp;id=' . (int)$p['id'] . '">' . h($p['code']) . '</a>',
            h($p['nom']), (string)(int)$p['nb_demandes'], h(date_fr((int)$p['cree_le'])),
            $attente ? '<a href="index.php?page=demandes">demande en attente : a traiter d\'abord</a>' : 'a supprimer'];
    }
    $n = count($ids);
    // Titre : tous les produits listes, ceux qu'une demande en attente garde compris.
    $html .= '<h2>Produits sans licence (' . count($lignes) . ')</h2><p class="discret">Un produit qui n\'a plus aucune licence '
        . 'peut etre supprime, avec ses regles et ses demandes deja traitees. Si l\'application envoie un jour une '
        . 'nouvelle demande, son produit sera recree automatiquement avec les regles par defaut, et la periode d\'essai '
        . 'pourra de nouveau etre accordee. Un produit dont seules restent des licences revoquees apparait ici une fois '
        . 'celles-ci supprimees.</p>'
        . tableau_html(['Produit', 'Nom', 'Demandes', 'Cree le', ''], $lignes, 'Aucun produit sans licence.');
    if ($n > 0) {
        $html .= f_formulaire($ctx, 'menage_produits', f_bouton('Supprimer ' . ($n > 1 ? 'les ' . $n
            . ' produits sans licence' : 'le produit sans licence'), 'danger'), ['ids' => implode(',', $ids)], 'raccourcis');
    }
    return $html;
}

/** Numeros envoyes par le formulaire de l'ecran Menage ("3,7,12"). */
function menage_ids_saisis($valeur): array
{
    $ids = [];
    foreach (explode(',', is_string($valeur) ? $valeur : '') as $morceau) {
        if (preg_match('/^\d{1,9}$/', trim($morceau)) === 1) {
            $ids[(int)$morceau] = true;
        }
    }
    if ($ids === []) {
        throw new AdminErreur('Rien a supprimer.');
    }
    return array_keys($ids);
}

/** Supprime, en une transaction, les licences revoquees affichees par l'ecran Menage. */
function admin_action_menage_licences(array $ctx): string
{
    $db = $ctx['db'];
    $n = 0;
    $db->exec('BEGIN IMMEDIATE');
    try {
        foreach (menage_ids_saisis($ctx['post']['ids'] ?? '') as $id) {
            // Une licence deja supprimee, ou reactivee entre-temps (impossible : revocation definitive), est sautee.
            if (db_valeur($db, "SELECT COUNT(*) FROM licences WHERE id = ? AND statut = 'revoquee'", [$id]) == 1) {
                licence_supprimer($db, $id, $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
                $n++;
            }
        }
        admin_journal($ctx, 'menage_licences', null, $n . ($n > 1 ? ' licences revoquees supprimees'
            : ' licence revoquee supprimee'));
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return '<p class="succes">' . ($n === 0 ? 'Aucune licence supprimee (deja fait ?).' : $n . ($n > 1
        ? ' licences revoquees supprimees.' : ' licence revoquee supprimee.')) . '</p>'
        . '<p><a href="index.php?page=menage">Retour au menage</a> - <a href="index.php?page=licences">Voir les '
        . 'licences</a></p>';
}

/** Supprime, en une transaction, les produits sans licence affiches par l'ecran Menage (s'ils le sont encore). */
function admin_action_menage_produits(array $ctx): string
{
    $db = $ctx['db'];
    $n = 0;
    $sautes = 0;
    $db->exec('BEGIN IMMEDIATE');
    try {
        foreach (menage_ids_saisis($ctx['post']['ids'] ?? '') as $id) {
            if (produit_lire($db, $id) === null) {
                continue;
            }
            // Une licence ou une demande arrivee depuis l'affichage garde le produit.
            if (produit_suppression_impossible($db, $id) !== null) {
                $sautes++;
                continue;
            }
            produit_supprimer($db, $id, $ctx['utilisateur'], $ctx['ip'], $ctx['maintenant']);
            $n++;
        }
        admin_journal($ctx, 'menage_produits', null, $n . ($n > 1 ? ' produits sans licence supprimes'
            : ' produit sans licence supprime'));
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
    return '<p class="succes">' . ($n === 0 ? 'Aucun produit supprime.' : $n . ($n > 1 ? ' produits supprimes.'
        : ' produit supprime.')) . '</p>'
        . ($sautes > 0 ? '<p class="alerte">' . $sautes . ($sautes > 1 ? ' produits conserves : ils ont' : ' produit '
            . 'conserve : il a') . ' recu une licence ou une demande depuis l\'affichage de la liste.</p>' : '')
        . '<p><a href="index.php?page=menage">Retour au menage</a> - <a href="index.php?page=produits">Voir les '
        . 'produits</a></p>';
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
            'Logiciel' => ADMIN_MENTION,
            'Licence' => 'GNU GPL version 3 ou ulterieure (GPL-3.0-or-later), avec des conditions additionnelles '
                . '(article 7, fichier NOTICE du depot) ; SANS AUCUNE GARANTIE',
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
        . '<p><a href="index.php?page=tableau">Accueil</a></p>';
}

// ---------------------------------------------------------------------------
// Cles de signature
// ---------------------------------------------------------------------------

function admin_ecran_cles(array $ctx): string
{
    $db = $ctx['db'];
    $active = signature_active($db, $ctx['config']);
    $urls = api_urls($db);
    // Secret des codes hors ligne courts : cree ici au premier besoin pour une installation anterieure ; perdu ou
    // remplace alors que des codes existent : signale, jamais recree ni propose (les applications ont l'ancien).
    $erreur_secret = '';
    try {
        $secret = b64url(secret_hors_ligne($ctx['config'], $db, $ctx['maintenant']));
    } catch (RuntimeException $e) {
        $secret = null;
        $erreur_secret = '<p class="erreur">' . h($e->getMessage()) . '</p>';
    }
    $lignes_module = 'LICENCE_URL = "' . ($urls[0] ?? '') . "\"\n"
        . 'LICENCE_URL_SECOURS = "' . ($urls[1] ?? '') . "\"\n"
        . 'LICENCE_CLE_PUBLIQUE = "' . $active['cle_publique'] . '"'
        . ($secret === null ? '' : "\nLICENCE_SECRET_HORS_LIGNE = \"" . $secret . '"');
    $html = '<h2>Cle publique active (kid ' . (int)$active['kid'] . ')</h2>'
        . bloc_copie('cle_publique', (string)$active['cle_publique'])
        . '<p>Lignes a reporter une fois en tete de <code>etdel_licence.py</code> :</p>'
        . '<div class="copie"><pre id="lignes_module">' . h($lignes_module) . '</pre>'
        . '<button type="button" data-copier="lignes_module">Copier</button></div>' . $erreur_secret
        . '<p class="discret">Les applications deja livrees n\'ont pas besoin d\'etre recompilees apres une rotation : '
        . 'elles adoptent la nouvelle cle par un bulletin signe par la precedente, diffuse pendant 12 mois. Les nouvelles '
        . 'versions peuvent embarquer la cle active.</p>'
        . '<p class="discret">LICENCE_SECRET_HORS_LIGNE verifie les codes hors ligne courts (licences hors ligne) ; il ne '
        . 'change jamais, meme apres une rotation. Une application qui ne l\'a pas refuse les codes courts mais accepte '
        . 'les codes longs. Limite assumee : quelqu\'un qui decompile une application peut fabriquer des codes courts '
        . '(protection dissuasive seulement) ; le code long, signe, est infalsifiable.</p>';
    $lignes = [];
    foreach (db_lignes($db, 'SELECT * FROM cles_signature ORDER BY kid DESC') as $cle) {
        $lignes[] = [(int)$cle['kid'], '<code>' . h($cle['cle_publique']) . '</code>',
            h(date_fr((int)$cle['active_depuis'], true)),
            $cle['retiree_le'] === null ? etiquette('active') : h(date_fr((int)$cle['retiree_le'], true))
                . ' <span class="discret">(acceptee par les ordinateurs jusqu\'au ' . h(date_fr((int)$cle['retiree_le'] + 90 * JOUR))
                . ')</span>',
            $cle['bulletin'] === null ? 'premiere cle' : 'signe par la cle precedente'];
    }
    $html .= '<h2>Historique</h2>' . tableau_html(['kid', 'Cle publique', 'Active depuis', 'Retiree le', 'Bulletin'], $lignes);
    $html .= '<h2>Rotation</h2><p>Genere une nouvelle paire sur le serveur. La cle privee actuelle est effacee ; les '
        . 'ordinateurs qui se connectent adoptent la nouvelle cle et acceptent encore l\'ancienne pendant 90 jours. Un '
        . 'ordinateur reste hors ligne plus de 12 mois apres une rotation devra etre mis a jour. Codes hors ligne longs : '
        . 'un poste jamais connecte ne connait que la cle inscrite dans son application ; apres une rotation, les nouveaux '
        . 'codes longs ne fonctionnent que dans les applications construites avec la nouvelle cle publique (les codes '
        . 'courts ne sont pas concernes).</p>'
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
