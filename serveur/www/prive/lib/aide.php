<?php
// aide.php - Ecran Aide de la console, pour quelqu'un qui la decouvre : le modele en deux minutes,
//            les taches courantes pas a pas, chaque ecran et chaque reglage, les licences hors ligne
//            (postes jamais connectes), ce que voit l'utilisateur dans l'application, la tolerance hors
//            ligne, le depannage (FAQ, codes de refus), le glossaire.
// ETDEL (c) 2026

declare(strict_types=1);

// Ecran de la console -> section de l'aide qui le decrit (lien "Aide sur cet ecran"). Les anciennes
// adresses page=distributions et page=distribution sont des redirections : elles n'ont pas de lien.
const AIDE_ANCRES = [
    'tableau' => 'tableau',
    'demandes' => 'demandes',
    'demande' => 'demandes',
    'licences' => 'licences',
    'licence' => 'licences',
    'licence_nouvelle' => 'licences',
    'licence_hors_ligne' => 'horsligne',
    'produits' => 'produits',
    'produit' => 'produits',
    'administration' => 'administration',
    'serveurs' => 'serveurs',
    'cles' => 'cles',
    'journal' => 'journal',
    'sauvegarde' => 'sauvegarde',
    'reglages' => 'reglages',
];

/** Sections dans l'ordre du sommaire : ancre => titre ; le contenu vient de aide_section_<ancre>(). */
function aide_sections(): array
{
    return [
        'comprendre' => 'Comprendre en 2 minutes',
        'taches' => 'Les taches courantes, pas a pas',
        'tableau' => 'Ecran Accueil',
        'demandes' => 'Ecran Demandes',
        'licences' => 'Ecran Licences et page d\'une licence',
        'horsligne' => 'Licences hors ligne : postes jamais connectes a Internet',
        'etats' => 'Suspendre, revoquer, changer d\'ordinateur, remplacer la cle : comparaison',
        'produits' => 'Ecran Produits : vos applications et leurs regles',
        'administration' => 'Ecran Administration',
        'serveurs' => 'Serveurs (adresses de l\'API)',
        'cles' => 'Cles de signature',
        'journal' => 'Journal et exports',
        'sauvegarde' => 'Sauvegarde et restauration',
        'reglages' => 'Reglages et mot de passe',
        'securite' => 'Securite',
        'application' => 'Ce que voit l\'utilisateur dans l\'application',
        'tolerance' => 'Hors ligne, echeance et controles',
        'depannage' => 'Depannage : questions frequentes et codes de refus',
        'glossaire' => 'Glossaire',
    ];
}

/**
 * Lien vers la section qui decrit l'ecran affiche. Seulement sur un ecran consulte
 * (GET) : une page de confirmation ou le resultat d'une action n'en a pas.
 */
function aide_lien(array $ctx): string
{
    $page = $ctx['get']['page'] ?? 'tableau';
    if ($ctx['methode'] !== 'GET' || !is_string($page) || !isset(AIDE_ANCRES[$page])) {
        return '';
    }
    return '<a class="aide-lien" href="index.php?page=aide#' . AIDE_ANCRES[$page] . '">Aide sur cet ecran</a>';
}

function admin_ecran_aide(array $ctx): string
{
    $sommaire = '';
    $corps = '';
    foreach (aide_sections() as $ancre => $titre) {
        $sommaire .= '<li><a href="#' . $ancre . '">' . h($titre) . '</a></li>';
        $corps .= '<section id="' . $ancre . '" class="aide-section"><h2>' . h($titre) . '</h2>'
            . call_user_func('aide_section_' . $ancre)
            . '<p class="aide-haut"><a href="#sommaire">Retour au sommaire</a></p></section>';
    }
    $html = '<div class="aide"><p>Mode d\'emploi de la console des licences ETDEL, pour quelqu\'un qui la decouvre. '
        . 'Lire d\'abord "Comprendre en 2 minutes", puis la tache qui vous occupe. Les sections suivantes decrivent chaque '
        . 'ecran et chaque reglage, ce que voit l\'utilisateur dans l\'application et que faire en cas de probleme. En haut '
        . 'de chaque ecran de la console, le lien "Aide sur cet ecran" mene directement a la bonne section.</p>'
        . '<nav id="sommaire" class="aide-sommaire" aria-label="Sommaire de l\'aide"><h2>Sommaire</h2><ol>' . $sommaire
        . '</ol></nav>' . $corps . '</div>';
    // Les valeurs reglables (config.php, constantes du code) sont lues ici, jamais recopiees dans le texte.
    return strtr($html, aide_valeurs($ctx));
}

/** Valeurs inserees dans le texte, deja echappees. */
function aide_valeurs(array $ctx): array
{
    $config = $ctx['config'];
    $url_console = url_console($ctx['db'], $config);
    $confirmations = '';
    foreach (ADMIN_ACTIONS as $action => $libelle) {
        // Une lecture (Afficher la cle) n'a pas de confirmation.
        if (!in_array($action, ADMIN_ACTIONS_LECTURE, true)) {
            $confirmations .= '<li>' . h($libelle) . '</li>';
        }
    }
    return [
        '{{UTILISATEUR}}' => h($ctx['utilisateur']),
        '{{FUSEAU}}' => h((string)$config['fuseau']),
        '{{EMAILS_JOUR}}' => (string)(int)$config['emails_par_jour'],
        '{{SAUVEGARDES}}' => (string)max(1, (int)$config['sauvegardes_conservees']),
        '{{URL_CONSOLE}}' => h($url_console !== '' ? $url_console : 'https://.../admin/'),
        '{{MDP_MIN}}' => (string)MOT_DE_PASSE_MIN,
        '{{JETON_MIN}}' => (string)JETON_INSTALLATION_MIN,
        '{{MOTIF_MAX}}' => (string)MOTIF_REFUS_MAX,
        '{{ACCUEIL_MAX}}' => (string)MESSAGE_ACCUEIL_MAX,
        '{{PAR_PAGE}}' => (string)ADMIN_PAR_PAGE,
        '{{ECART_MIN}}' => (string)intdiv(API_ECART_MAX, 60),
        '{{TOLERANCE_MIN_H}}' => (string)intdiv(TOLERANCE_MIN_S, 3600),
        '{{LIMITE_DEMANDER}}' => (string)API_LIMITES['demander'][0],
        '{{LIMITES}}' => h(aide_limites()),
        '{{TRANSFERTS_MAX}}' => (string)TRANSFERTS_MAX,
        '{{TRANSFERTS_JOURS}}' => (string)TRANSFERTS_PERIODE_J,
        '{{DEFAUT_DUREE}}' => h(REGLES_DEFAUT['duree_defaut_j'] === null ? 'perpetuelle'
            : REGLES_DEFAUT['duree_defaut_j'] . ' jours'),
        '{{DEFAUT_ESSAI}}' => (string)(int)REGLES_DEFAUT['essai_j'],
        '{{DEFAUT_TOLERANCE}}' => (string)(int)REGLES_DEFAUT['tolerance_j'],
        '{{DEFAUT_PREAVIS}}' => (string)(int)REGLES_DEFAUT['preavis_j'],
        '{{VERSION_SERVEUR}}' => h(VERSION_SERVEUR),
        '{{VERSION_API}}' => (string)VERSION_API,
        '{{CONFIRMATIONS}}' => '<ul class="aide-colonnes">' . $confirmations . '</ul>',
        // Licences hors ligne : libelles des confirmations et mention des actions, tels que la console les affiche.
        '{{HL_CONF_CREER}}' => h(ADMIN_ACTIONS['licence_hors_ligne_creer']),
        '{{HL_CONF_CODE}}' => h(ADMIN_ACTIONS['licence_hors_ligne_code']),
        '{{HL_CONF_POSTE}}' => h(ADMIN_ACTIONS['licence_hors_ligne_poste']),
        '{{HL_MENTION}}' => h(HORS_LIGNE_MENTION),
    ];
}

/** Limites de l'API par adresse IP, en clair. */
function aide_limites(): string
{
    $noms = ['activer' => 'activations de cle', 'valider' => 'controles de licence',
        'liberer' => 'transferts depuis l\'application', 'suivre_demande' => 'suivis de demande',
        'demander' => 'demandes de licence'];
    $textes = [];
    foreach (API_LIMITES as $operation => [$max, $duree]) {
        $periode = $duree === 3600 ? 'par heure' : ($duree === JOUR ? 'par jour (a partir de minuit UTC)'
            : 'par periode de ' . $duree . ' s');
        $textes[] = $max . ' ' . ($noms[$operation] ?? $operation) . ' ' . $periode;
    }
    return implode(', ', $textes);
}

/** Tableau ecrit ligne par ligne : cellules separees par " | ", premiere ligne = en-tetes. */
function aide_tableau(string $texte): string
{
    $lignes = [];
    foreach (preg_split('/\R/', trim($texte)) ?: [] as $ligne) {
        if (trim($ligne) !== '') {
            $lignes[] = array_map('trim', explode(' | ', $ligne));
        }
    }
    $entetes = array_shift($lignes) ?? [];
    return tableau_html($entetes, $lignes);
}

// ---------------------------------------------------------------------------
// Sections
// ---------------------------------------------------------------------------

function aide_section_comprendre(): string
{
    return <<<'HTML'
        <p>La console sert a donner, suivre et retirer les licences de vos applications Windows. Voici l'essentiel.</p>
        <h3>Trois mots a connaitre</h3>
        <ul>
        <li><strong>Produit</strong> = votre application, reconnue par le code qu'elle envoie au serveur (par exemple
        MONAPPLI : le developpeur l'inscrit dans l'application par la ligne <code>etdel_licence.installer(...)</code>). Le
        produit porte les regles de ses licences : duree par defaut, essai, options, tolerance hors ligne, preavis, message
        d'accueil, actif ou non. <strong>Vous n'avez rien a creer</strong> : la premiere demande envoyee par une application
        inconnue cree son produit toute seule, avec les regles par defaut (licence de {{DEFAUT_DUREE}}, essai de
        {{DEFAUT_ESSAI}} jours, {{DEFAUT_TOLERANCE}} jours hors ligne, preavis de {{DEFAUT_PREAVIS}} jours, aucune option).
        L'e-mail de la demande vous le signale et le produit apparait dans Produits avec l'etiquette "cree
        automatiquement". Vous pouvez aussi creer un produit a la main, pour preparer des cles avant toute demande.</li>
        <li><strong>Licence</strong> = le droit d'utiliser un produit sur <strong>un seul ordinateur</strong>. Elle a un
        client (le titulaire, nom affiche dans l'application), une echeance (ou aucune : licence perpetuelle) et un statut :
        active, suspendue (bloquee pour un temps), revoquee (arretee pour toujours) ; on dit "expiree" quand l'echeance est
        passee.</li>
        <li><strong>Cle</strong> = ETDEL-XXXX-XXXX-XXXX-XXXX, le numero de la licence. Elle se lie au premier ordinateur sur
        lequel elle est activee : saisie ensuite sur un autre ordinateur, elle est refusee. Elle reste consultable dans la
        console : page de la licence, bouton "Afficher la cle" (sauf licence creee avant cette fonction : le client la lit
        alors dans son application, voir <a href="#t-cle">Retrouver une cle</a>).</li>
        </ul>
        <h3>Comment un utilisateur obtient une cle</h3>
        <ol>
        <li><strong>Il la demande depuis l'application</strong> (le cas le plus courant). Sans licence, l'application
        affiche la fenetre "Licence requise" ; l'utilisateur choisit "Demander une licence", tape son nom et envoie. Vous
        recevez un e-mail et la demande apparait dans Demandes et sur l'Accueil. En attendant votre reponse, l'application
        peut etre utilisable quelques jours (l'essai, une seule fois par ordinateur et par produit). Vous cliquez "Traiter",
        puis "Accepter" : la cle est creee, deja liee a cet ordinateur, et l'application la recoit toute seule.
        L'utilisateur n'a rien a taper. Ou vous cliquez "Refuser" : l'application affiche votre motif.</li>
        <li><strong>Vous la creez dans la console</strong> : "Nouvelle licence", choisir le produit, le client et la duree,
        puis "Creer la cle". Vous transmettez la cle (e-mail, telephone) ; l'utilisateur la saisit dans l'application ("J'ai
        une cle", puis "Activer"). C'est la seule voie pour une application sans fenetre (mode console).</li>
        <li><strong>Son ordinateur n'a jamais Internet : licence hors ligne.</strong> L'utilisateur vous dicte
        l'"Identifiant du poste" affiche par l'application ; Licences, "Licence hors ligne" cree pour ce poste un code court a
        dicter par telephone et un code long a envoyer par e-mail. L'utilisateur saisit l'un ou l'autre par "J'ai une cle" :
        l'application le verifie sans Internet. Voir <a href="#t-hors-ligne">Poste toujours hors ligne</a>.</li>
        </ol>
        <h3>Ensuite</h3>
        <ul>
        <li>L'application controle sa licence aupres du serveur 3 secondes apres chaque lancement, puis toutes les 6 heures.
        Ce que vous changez ici (prolongation, suspension, revocation, options) l'atteint a ce controle.</li>
        <li>Sans Internet, elle continue de fonctionner pendant la tolerance hors ligne du produit ({{DEFAUT_TOLERANCE}} jours
        par defaut), puis se bloque jusqu'a ce qu'elle puisse de nouveau joindre le serveur. Une licence hors ligne n'a pas
        de tolerance : elle vaut jusqu'a son echeance, avec ou sans Internet (voir <a href="#horsligne">Licences hors
        ligne</a>).</li>
        <li>L'utilisateur voit sa licence a tout moment par les touches Ctrl+Maj+L (fenetre "Licence"). Il peut y lire sa
        cle et y transferer sa licence vers un autre ordinateur.</li>
        <li>Toute action qui modifie quelque chose demande une confirmation et est inscrite au journal avec votre identifiant
        ({{UTILISATEUR}}). Rien ne se supprime : ni licence, ni demande, ni produit. On suspend, on revoque, on desactive ;
        tout reste visible.</li>
        <li>Les dates sont affichees dans le fuseau {{FUSEAU}} (reglage de config.php). La console s'utilise aussi sur
        telephone.</li>
        </ul>
        <h3>Le menu</h3>
        <ul>
        <li><a href="#tableau">Accueil</a> : demandes a traiter, chiffres, licences qui expirent bientot.</li>
        <li><a href="#demandes">Demandes</a> : la pastille donne le nombre de demandes en attente.</li>
        <li><a href="#licences">Licences</a> : retrouver une licence, la prolonger, la suspendre, afficher sa cle, en creer
        une, ou creer une licence hors ligne pour un ordinateur sans Internet.</li>
        <li><a href="#produits">Produits</a> : vos applications et leurs regles.</li>
        <li>Aide : cette page.</li>
        <li><a href="#administration">Administration</a> : ecrans techniques (adresses du serveur, cles de signature,
        journal, sauvegarde, e-mails et mot de passe), utiles a l'installation et de temps en temps.</li>
        </ul>
        HTML;
}

function aide_section_taches(): string
{
    return <<<'HTML'
        <p>Chaque tache pas a pas. Les boutons sont nommes comme dans la console ; chaque action demande une
        confirmation.</p>
        <h3 id="t-service">Mettre le serveur en service (une seule fois)</h3>
        <ol>
        <li>Administration, Reglages : saisir votre adresse dans "Adresse(s) de notification", "Enregistrer", puis "Envoyer
        un e-mail de test" et verifier sa reception (voir <a href="#reglages">Reglages</a>).</li>
        <li>Administration : verifier que chaque ligne du "Controle d'exposition" indique "protege".</li>
        <li>Administration, Cles de signature : copier les quatre lignes LICENCE_URL, LICENCE_URL_SECOURS,
        LICENCE_CLE_PUBLIQUE et LICENCE_SECRET_HORS_LIGNE et les donner au developpeur, qui les reporte une fois en tete de
        <code>etdel_licence.py</code>. Sans les trois premieres, l'application fonctionne sans licence ; sans la quatrieme,
        elle refuse les codes hors ligne courts (voir <a href="#horsligne">Licences hors ligne</a>).</li>
        <li>Si ce n'est pas deja fait, creer la tache planifiee OVH de sauvegarde quotidienne ; le lendemain, verifier dans
        Administration, Sauvegarde qu'une sauvegarde apparait. Faire aussi par FTP la copie de secours hors ligne (voir
        <a href="#sauvegarde">Sauvegarde et restauration</a>).</li>
        <li>Faire un essai complet : une demande envoyee depuis l'application et acceptee ici (le produit se cree a cette
        occasion), puis une cle creee ici ("Nouvelle licence") et saisie dans l'application d'un second ordinateur : sur le
        premier, qui a deja sa licence, l'application ne propose plus de saisir une cle.</li>
        </ol>
        <h3 id="t-demande">Traiter une demande</h3>
        <ol>
        <li>Ouvrir Demandes (ou l'Accueil, ou le lien "Traiter la demande" de l'e-mail recu) ; chaque demande en attente a un
        bouton "Traiter".</li>
        <li>La page de la demande dit qui demande (client, e-mail, mot pour vous), pour quel produit, depuis quel ordinateur,
        et jusqu'a quand court l'essai. En cas de doute, contacter la personne.</li>
        <li>Pour accepter : verifier "Duree en jours" (proposee par le produit ; vide = perpetuelle), le nom du client et les
        options, puis "Accepter". La cle s'affiche avec un bouton "Copier", mais il n'y a rien a transmettre : l'application
        la recoit toute seule (dans la minute si elle attend sans essai, sinon a son controle suivant).</li>
        <li>Pour refuser : ecrire si besoin un motif (il est montre tel quel a l'utilisateur), puis "Refuser".</li>
        </ol>
        <p>Details et cas particuliers : <a href="#demandes">Ecran Demandes</a>.</p>
        <h3 id="t-creer">Creer une licence et transmettre la cle</h3>
        <ol>
        <li>Licences (ou Accueil) : bouton "Nouvelle licence". Depuis la page d'un produit, "Nouvelle licence pour ce
        produit" le choisit d'avance.</li>
        <li>"Produit" : l'application du client. "Client (titulaire de la licence)" : le nom affiche dans l'application.
        "E-mail du client" et "Note interne" sont facultatifs et ne servent qu'a vous : le serveur n'envoie rien au client.
        "Duree en jours" : proposee par le produit ; vide = perpetuelle. La duree court des la creation : creer la cle au
        moment de la transmettre.</li>
        <li>"Creer la cle". La cle s'affiche avec "Copier".</li>
        <li>Transmettre la cle au client (e-mail, telephone) avec ce mode d'emploi : "Lancez l'application ; dans la fenetre
        Licence requise, cliquez J'ai une cle, saisissez la cle, puis Activer." La cle se lie a l'ordinateur ou elle est
        saisie en premier.</li>
        </ol>
        <p>Un client qui veut l'application sur deux ordinateurs a besoin de deux licences.</p>
        <h3 id="t-cle">Retrouver une cle et la dicter au client</h3>
        <ol>
        <li>Licences : taper dans "Recherche" le nom du client, son e-mail, le nom de son ordinateur ou les 4 derniers
        caracteres de la cle, puis "Filtrer".</li>
        <li>Cliquer sur le nom du client : la page de la licence s'ouvre. La ligne "Cle" montre ETDEL-****-****-****-XXXX.</li>
        <li>Cliquer "Afficher la cle" : la page se recharge avec la cle complete et un bouton "Copier". Pas de confirmation,
        puisque rien n'est modifie, mais chaque affichage est inscrit au journal (action cle_affichee, avec votre
        identifiant).</li>
        <li>Dicter la cle groupe par groupe. Elle ne contient jamais les lettres I, L, O ni U : un O entendu est un zero, un
        I ou un L est un 1 (l'application corrige d'elle-meme). Majuscules, minuscules, tirets et espaces sont indifferents.
        Le dernier caractere est une somme de controle : une faute de frappe est signalee aussitot par l'application.</li>
        </ol>
        <p>Cas particuliers :</p>
        <ul>
        <li>Licence creee avant que la console garde les cles : la page affiche "Cle complete non disponible (licence creee
        avant cette fonction)". Si l'ordinateur du client fonctionne, il lit lui-meme sa cle dans l'application : touches
        Ctrl+Maj+L, puis "Afficher la cle". Sinon, "Nouvelle cle" (voir plus bas) en cree une autre, a transmettre ;
        l'ancienne cesse alors de fonctionner.</li>
        <li>Licence revoquee : sa cle ne sert plus a rien et n'est plus affichee.</li>
        <li>La cle n'apparait jamais dans le journal, les e-mails, les exports CSV ni l'adresse des pages : seuls ses 4
        derniers caracteres y figurent.</li>
        </ul>
        <h3 id="t-prolonger">Prolonger une licence</h3>
        <ol>
        <li>Page de la licence, bouton "Prolonger" (absent pour une licence perpetuelle).</li>
        <li>"+30 jours" ou "+1 an" ajoute ce temps a l'echeance actuelle, ou a aujourd'hui si elle est deja passee. Ou
        choisir une date dans "Ou choisir la nouvelle echeance", puis "Fixer la date" : la licence vaut jusqu'a la fin de ce
        jour.</li>
        <li>Le client n'a rien a faire : l'application recoit la nouvelle date a son controle suivant. Une licence deja
        expiree se debloque des que l'utilisateur clique "Reessayer" ou relance l'application.</li>
        </ol>
        <h3 id="t-transfert">Transferer une licence vers un autre ordinateur</h3>
        <p>Une cle ne fonctionne que sur un ordinateur a la fois : pour changer d'ordinateur, il faut d'abord la detacher de
        l'ancien. Deux facons.</p>
        <p><strong>1. Depuis l'application, sur l'ancien ordinateur</strong> : a preferer s'il fonctionne encore. Rien a
        faire dans la console.</p>
        <ol>
        <li>L'utilisateur ouvre la fenetre Licence (touches Ctrl+Maj+L, ou clic sur le bandeau) et clique "Transferer cette
        licence", a cote du nom de l'ordinateur. Le lien est present quand une cle est enregistree et que la licence est
        valide, ou bloquee par une suspension (la fenetre "Licence suspendue" le propose aussi).</li>
        <li>La fenetre "Transferer cette licence" montre la cle complete, avec "Copier" : la noter. Elle previent que
        l'application sera desactivee sur cet ordinateur, qui doit etre connecte a Internet.</li>
        <li>"Transferer" : le serveur detache la cle de cet ordinateur. Page "Licence transferee" : "Licence transferee :
        saisissez la cle ETDEL-... sur le nouvel ordinateur.", puis "Fermer l'application". L'application a efface sa cle sur
        cet ordinateur ; a son prochain lancement, elle affiche "Licence requise".</li>
        <li>Sur le nouvel ordinateur : "J'ai une cle", saisir la meme cle, "Activer".</li>
        </ol>
        <p>En cas d'echec (pas d'Internet, refus du serveur), rien ne change sur l'ancien ordinateur et la fenetre dit
        pourquoi. Au plus {{TRANSFERTS_MAX}} transferts par licence sur {{TRANSFERTS_JOURS}} jours depuis l'application :
        au-dela, elle affiche "Transfert refuse : trop de transferts recents pour cette licence. Contactez ETDEL, qui peut la
        liberer pour vous." et il faut passer par la console. Apres un transfert, la page de la licence affiche "Ordinateur :
        aucun..." et son historique porte licence_transferee (acteur "poste:XXXX-XXXX").</p>
        <p><strong>2. Depuis la console</strong> : ancien ordinateur perdu, en panne ou deja reinstalle, ou transferts trop
        nombreux.</p>
        <ol>
        <li>Page de la licence, "Changer d'ordinateur", bouton "Liberer cette cle". Message : "Cle liberee : elle peut etre
        saisie sur le nouvel ordinateur."</li>
        <li>Donner la cle au client si besoin ("Afficher la cle") ; il la saisit sur le nouvel ordinateur par "J'ai une
        cle".</li>
        <li>L'ancien ordinateur, s'il fonctionne encore, perd la licence a son controle suivant ("Ce poste n'est plus autorise
        pour cette licence") : au plus 6 heures s'il est connecte, au plus la tolerance hors ligne s'il ne l'est pas. Pendant
        ce temps, les deux ordinateurs peuvent fonctionner : c'est pourquoi le transfert depuis l'application est
        preferable.</li>
        </ol>
        <p>Licence suspendue : le transfert est possible des deux facons, mais la cle est refusee sur le nouvel ordinateur
        ("Licence suspendue", cle non enregistree) jusqu'a la reactivation ou la date de fin.</p>
        <h3 id="t-suspendre">Suspendre une licence, avec ou sans date</h3>
        <p>Pour couper l'acces pour un temps (impaye, litige, conges) sans rien effacer.</p>
        <ol>
        <li>Page de la licence, bouton "Suspendre".</li>
        <li>Sans date : laisser "Jusqu'au (facultatif)" vide ; la licence reste suspendue jusqu'a ce que vous cliquiez
        "Reactiver". Avec une date : choisir le DERNIER jour de la suspension ; la licence redevient active d'elle-meme a la
        fin de ce jour (23:59:59, fuseau {{FUSEAU}}). Exemple : le 10, pour couper jusqu'au 15 inclus, saisir le 15. Pour un
        retour le lundi matin, choisir le dimanche. La suspension commence tout de suite ; une date passee est refusee.</li>
        <li>"Suspendre". La licence affiche "Suspendue jusqu'au JJ/MM/AAAA" ou "Suspendue (sans date de fin)".</li>
        </ol>
        <p>L'application du client est bloquee a son controle suivant ; elle garde sa cle et se debloque toute seule plus
        tard, sans rien ressaisir. Pour changer la date : bouton "Changer la date de fin" (la date actuelle est proposee ;
        champ vide = suspension sans date).</p>
        <h3 id="t-reactiver">Reactiver une licence suspendue</h3>
        <ol>
        <li>Page de la licence, bouton "Reactiver", puis "Reactiver".</li>
        <li>La licence redevient active tout de suite (la date de fin eventuelle est effacee). L'application se debloque a
        son controle suivant ; pour tout de suite, faire cliquer "Reessayer" dans la fenetre "Licence suspendue", ou relancer
        l'application (il faut Internet).</li>
        </ol>
        <h3 id="t-revoquer">Revoquer une licence, puis servir de nouveau le client</h3>
        <ol>
        <li>Page de la licence, bouton "Revoquer", puis le bouton rouge "Revoquer definitivement".</li>
        <li>C'est definitif : la licence ne pourra plus etre reactivee. L'application du client efface sa cle a son controle
        suivant, affiche "Licence revoquee" et se ferme.</li>
        <li>Pour servir de nouveau ce client plus tard : page de la licence revoquee, bouton "Nouvelle licence pour ce
        client". Le formulaire est pre-rempli (produit, client, e-mail, note "Remplace la licence n. X") : "Creer la cle",
        puis transmettre la nouvelle cle. La licence revoquee affiche ensuite "Deja remplacee par la licence n. Y".</li>
        </ol>
        <p>Pour une coupure temporaire, utiliser "Suspendre", jamais "Revoquer".</p>
        <h3 id="t-nouvelle-cle">Remplacer une cle diffusee ou transmise par erreur</h3>
        <ol>
        <li>Page de la licence, bouton "Nouvelle cle", puis "Creer une nouvelle cle".</li>
        <li>La nouvelle cle s'affiche avec "Copier" : la transmettre au client. L'ancienne cle est refusee partout ; la
        licence garde son echeance, son statut, ses options et son historique, mais n'est plus liee a aucun ordinateur.</li>
        <li>L'ordinateur qui utilisait l'ancienne cle la voit refusee a son controle suivant : l'application affiche "Cle
        invalide", efface la cle et se ferme ; au lancement suivant, "Licence requise", "J'ai une cle", saisir la nouvelle
        cle.</li>
        </ol>
        <h3 id="t-hors-ligne">Poste toujours hors ligne : licence hors ligne</h3>
        <p>Pour un ordinateur qui n'a jamais (ou presque jamais) Internet : atelier isole, bateau, reseau ferme. Une cle
        ordinaire ne peut pas s'y activer, car l'activation passe par le serveur. La licence hors ligne la remplace par un code
        que l'application verifie elle-meme, sans Internet, et qui contient sa date de fin et ses options. Ce code ne
        fonctionne que sur cet ordinateur et pour ce produit.</p>
        <ol>
        <li><strong>Obtenir l'identifiant du poste.</strong> Sur l'ordinateur isole, l'utilisateur lance l'application : la
        fenetre "Licence requise" affiche en bas "Identifiant du poste : XXXX-XXXX", avec "Copier" (une application deja en
        service le montre dans sa fenetre Licence : touches Ctrl+Maj+L, ligne "Identifiant du poste"). Il vous le dicte ou
        vous l'envoie : 8 caracteres, jamais les lettres I, L, O ni U. Il est different pour chaque application d'un meme
        ordinateur : demander celui de l'application a activer.</li>
        <li><strong>Creer la licence hors ligne.</strong> Licences, bouton "Licence hors ligne" (ou, sur la page du produit,
        "Licence hors ligne pour ce produit"). Remplir "Produit", "Identifiant du poste (affiche par l'application, par
        exemple SHXT-2380)" (majuscules ou minuscules, tiret facultatif ; un O est lu 0, un I ou un L est lu 1), "Client
        (titulaire de la licence)", "Duree en jours (vide = illimitee)" (la duree par defaut du produit est proposee) et la
        case "Toutes les options (decochee : aucune option)", cochee d'office. Cliquer "Creer les codes", puis confirmer
        ("{{HL_CONF_CREER}}").</li>
        <li><strong>Noter les codes.</strong> La page affiche "Code court, a dicter par telephone" (ETDHL-XXXX-XXXX-XXXX-XXXX)
        et "Code long, a envoyer par e-mail (copier-coller)" (ETDHL2- suivi de 109 caracteres), chacun avec "Copier", puis
        le produit, l'identifiant du poste, le client, l'echeance (dernier jour de validite) et les options. Les deux codes
        sont equivalents : en transmettre un seul, au choix. Ils restent consultables sur la page de la licence ("Afficher
        les codes").</li>
        <li><strong>Transmettre un code.</strong> Par telephone : dicter le code court, ETDHL puis les quatre groupes de 4
        caracteres. Memes regles que pour une cle : un O entendu est un zero, un I ou un L est un 1, majuscules, tirets et
        espaces sont indifferents, et le dernier caractere est une somme de controle (une faute de frappe est signalee
        aussitot). Par e-mail : coller le code long (bouton "Copier") dans le message ; l'utilisateur l'amene sur
        l'ordinateur isole (par une cle USB, par exemple) et le colle tel quel, espaces et retours a la ligne compris.</li>
        <li><strong>Ce que fait l'utilisateur.</strong> Dans la fenetre "Licence requise" : "J'ai une cle", taper ou coller le
        code dans le champ "Cle de licence", puis "Activer". Aucune connexion n'est necessaire : "Verification du code...",
        puis l'application s'ouvre. Sa fenetre Licence affiche "Licence hors ligne valable jusqu'au JJ/MM/AAAA" (ou "Licence
        hors ligne illimitee"). S'il a tape le code court sans ETDHL, l'application le reconnait quand meme.</li>
        <li><strong>Si le code est refuse.</strong> "Code hors ligne refuse : il ne correspond pas a cet ordinateur
        (identifiant du poste XXXX-XXXX) ou contient une erreur de saisie." : comparer l'identifiant cite avec la ligne
        "Poste" de la page de la licence. Meme identifiant : faire relire le code (un code long correct peut aussi etre
        refuse par une application construite avant une rotation de la cle de signature et jamais connectee depuis : dicter
        alors le code court). Identifiant different : verifier d'abord que le code est tape dans la bonne application (un
        meme ordinateur a un identifiant different pour chaque application) ; si oui, l'identifiant a ete mal dicte ou mal
        saisi : page de la licence, "Nouveau code pour un autre ordinateur", saisir l'identifiant que cette application
        affiche, puis transmettre les nouveaux codes. "Codes hors ligne courts non pris en charge par cette application :
        demandez le code long (ETDHL2-...) a ETDEL" : envoyer le code long par e-mail.</li>
        <li><strong>Prolonger avec un nouveau code.</strong> Un ordinateur qui ne se connecte jamais ne recoit rien du
        serveur : "Prolonger" ne l'atteint pas. A l'echeance, l'application affiche la fenetre "Licence hors ligne expiree"
        ("Licence hors ligne expiree le JJ/MM/AAAA : demandez un nouveau code a ETDEL"). Page de la licence, "Nouveau code
        hors ligne pour ce poste" : "Duree en jours (vide = illimitee)", comptee a partir d'aujourd'hui, et la case des
        options ; "Creer le nouveau code", puis confirmer ("{{HL_CONF_CODE}}"). Transmettre l'un des deux nouveaux codes ;
        l'utilisateur clique "J'ai une cle" dans la fenetre "Licence hors ligne expiree" et le saisit. Tant que la licence
        est valable, l'application ne propose pas de saisir un code : creer le nouveau code quand l'utilisateur appelle, a
        l'echeance ou peu avant, puisque sa duree court des sa creation.</li>
        <li><strong>Si l'ordinateur se connecte un jour</strong>, meme une seule fois : rien a faire. L'application controle
        alors sa licence aupres du serveur, comme une licence ordinaire ; ce contact la lie a cet ordinateur, et une
        prolongation, une suspension ou une revocation faite dans la console l'atteint. Sans Internet, rien ne change : ni
        bandeau, ni blocage avant l'echeance. Detail : <a href="#hl-connexion">Si l'ordinateur se connecte un jour</a>.</li>
        </ol>
        <p>Limites a connaitre avant de choisir une licence hors ligne : <a href="#hl-limites">Limites</a>.</p>
        HTML;
}

function aide_section_tableau(): string
{
    return <<<'HTML'
        <p>Premier ecran de la console (menu "Accueil", ou le nom "Licences ETDEL" en haut a gauche).</p>
        <ul>
        <li><strong>Demandes en attente</strong> : Date, Client, Ordinateur, Produit et le bouton "Traiter" ; "Aucune demande
        en attente." s'il n'y en a pas (voir <a href="#t-demande">Traiter une demande</a>).</li>
        <li>Raccourcis <strong>"Nouvelle licence"</strong> et <strong>"Nouveau produit"</strong>.</li>
        <li><strong>En chiffres</strong> : "Licences actives" (statut actif, echeance a venir ou perpetuelle), "Licences
        expirees" (statut actif mais echeance passee : "expiree" n'est pas un statut enregistre, une prolongation suffit a
        la rendre active), "Licences suspendues" et "Licences revoquees" ; un clic ouvre la liste correspondante.
        "Ordinateurs vus sur 7 jours" : licences dont l'ordinateur a reussi un controle depuis 7 jours (un ordinateur qui a
        deux licences compte deux fois ; un ordinateur refuse ne compte pas).</li>
        <li><strong>Licences expirant sous 30 jours</strong> : licences actives dont l'echeance tombe dans les 30 prochains
        jours (les suspendues n'y sont pas), la plus proche d'abord, 100 au plus : Client (lien vers la licence), Produit,
        Echeance, Reste (en jours), Ordinateur. L'application previent l'utilisateur 15 jours avant l'echeance : c'est le
        moment de prolonger. Une licence hors ligne y porte l'etiquette "hors ligne" : son application ne previent pas
        (voir <a href="#horsligne">Licences hors ligne</a>).</li>
        </ul>
        <p>Le controle d'exposition (fichiers proteges) est dans <a href="#administration">Administration</a>.</p>
        HTML;
}

function aide_section_demandes(): string
{
    return <<<'HTML'
        <h3>D'ou viennent les demandes</h3>
        <p>Dans l'application, fenetre "Licence requise", l'utilisateur choisit "Demander une licence" (ou "Nouvelle demande"
        apres un refus) et remplit "Titulaire" (obligatoire : societe ou personne a qui la licence est destinee), "E-mail"
        (facultatif) et "Mot pour ETDEL" (facultatif, 200 caracteres). Le nom de l'ordinateur et la version de l'application
        sont transmis automatiquement. Il clique "Envoyer la demande".</p>
        <ul>
        <li>Si le produit prevoit un essai et que cet ordinateur n'en a jamais eu pour ce produit, l'application affiche
        "Demande envoyee" ("En attendant la reponse, l'application est utilisable pendant N jour(s).", bouton "Continuer")
        et s'ouvre. Sinon, elle reste sur "Demande en attente" et ne s'ouvre qu'apres votre acceptation.</li>
        <li>Si l'application envoie un code que le serveur ne connait pas, le produit est cree a ce moment-la (voir
        <a href="#produits">Produits</a>) et la demande est enregistree normalement, essai compris.</li>
        <li>Un ordinateur n'a qu'une demande en attente par produit. Au plus {{LIMITE_DEMANDER}} demandes par adresse IP et
        par jour. Aucun reglage n'interdit les demandes : toute application sans licence propose "Demander une licence" ;
        seul un produit inactif les refuse.</li>
        </ul>
        <p>Chaque nouvelle demande envoie un e-mail aux adresses des <a href="#reglages">Reglages</a>. Objet : "[ETDEL
        Licences] Nouvelle demande - PRODUIT - ORDINATEUR", avec "(nouveau produit)" apres le code si la demande vient de
        creer le produit. Le corps reprend produit, ordinateur, identifiant de poste, titulaire, e-mail, mot du client, essai,
        date et IP, et donne le lien "Traiter la demande". Un echec d'envoi n'empeche jamais l'enregistrement : la demande
        est toujours dans cet ecran.</p>
        <h3>Liste</h3>
        <ul>
        <li><strong>En attente</strong>, les plus anciennes d'abord : Date, Produit, Ordinateur, Identifiant (de poste),
        Client, E-mail, Message, IP, "Essai jusqu'au" ("aucun" sans essai) et le bouton "Traiter".</li>
        <li><strong>Historique</strong> : les {{PAR_PAGE}} dernieres demandes traitees : Demandee le, Traitee le, Statut
        (Acceptee ou Refusee), Produit, Ordinateur, Client, Demande (lien), "Licence ou motif".</li>
        <li>"Exporter les demandes (CSV)" : toutes les demandes (voir <a href="#journal">Journal et exports</a>).</li>
        </ul>
        <h3>Page d'une demande</h3>
        <p>"Traiter" ouvre la page : Statut, Date, Produit (lien vers sa page), Ordinateur, Identifiant du poste, Client,
        E-mail, Mot du client, Version de l'application (simple information), "Essai jusqu'au" et IP. L'identifiant du poste
        permet de reconnaitre l'ordinateur : l'utilisateur le lit en bas de la fenetre d'activation ou dans la fenetre
        Licence (Ctrl+Maj+L).</p>
        <p><strong>Accepter</strong> (colonne "Accepter") :</p>
        <ul>
        <li>"Duree en jours (vide = perpetuelle)" : preremplie avec la duree par defaut du produit, de 1 a 36500. L'echeance
        court a partir de l'acceptation.</li>
        <li>"Client (titulaire de la licence)" : prerempli avec le nom saisi par le demandeur, modifiable (120 caracteres) ;
        c'est le nom affiche dans l'application.</li>
        <li>"Options (codes separes par des virgules, * = toutes)" : preremplies avec celles du produit. Laissees telles
        quelles, la licence suit les options du produit, y compris leurs changements futurs. Toute autre liste (les memes
        codes dans un autre ordre, ou un champ vide qui veut dire aucune option) devient une liste propre a cette licence
        (surcharge). * seul donne toutes les options, y compris celles qui seront ajoutees plus tard.</li>
        <li>Bouton "Accepter", confirmation "Accepter la demande et creer la cle". La licence est creee active, deja liee a
        l'ordinateur demandeur, avec l'e-mail de la demande (origine "demande").</li>
        <li>La page affiche "Demande n. X acceptee..." et la cle avec "Copier", puis les liens "Voir la licence" et
        "Retour aux demandes". Rien a transmettre : l'application la recupere seule. La cle reste consultable sur la page
        de la licence ("Afficher la cle").</li>
        <li>Delai de remise : sans essai, l'application interroge le serveur chaque minute et sa fenetre se ferme seule.
        Pendant un essai, elle ne verifie qu'au lancement et toutes les 6 heures : l'utilisateur peut accelerer par
        Ctrl+Maj+L puis "Verifier maintenant", ou en relancant l'application.</li>
        </ul>
        <p><strong>Refuser</strong> (colonne "Refuser") : "Motif (facultatif, renvoye tel quel a l'application)",
        {{MOTIF_MAX}} caracteres au plus, puis le bouton rouge "Refuser", confirmation "Refuser la demande". Message : "Demande
        refusee.". L'application affiche la fenetre "Demande refusee" avec le motif (sans accents) et les boutons "J'ai une
        cle" et "Nouvelle demande". Le motif est lu par le client : rester factuel.</p>
        <p>Une demande traitee ne se traite plus : sa page indique "Acceptee le ... : licence n. X" ou "Refusee le ... :
        motif". Pour revenir sur une acceptation, agir sur la licence creee (suspendre, revoquer). Si la demande a deja ete
        traitee depuis un autre navigateur, la seconde tentative affiche "Action impossible" avec "demande inconnue ou deja
        traitee" ; depuis un autre onglet du meme navigateur, "Requete refusee" avec "Formulaire expire ou invalide :
        rechargez la page puis recommencez." ; recharger la page montre alors la decision deja prise.</p>
        <h3>Periode d'essai</h3>
        <ul>
        <li>Fixee a l'envoi de la demande, pour la duree "Essai pendant une demande" du produit (0 = aucun essai). Changer ce
        reglage ne change rien aux demandes deja recues.</li>
        <li>Accordee une seule fois par ordinateur et par produit. Une nouvelle demande d'un ordinateur qui a deja eu un
        essai pour ce produit (apres un refus, une revocation, une reinstallation) n'en a pas : l'application reste sur
        "Demande en attente" jusqu'a votre decision. Un ordinateur qui n'en a jamais eu (par exemple equipe jusque-la d'une
        cle creee dans la console) en obtient un a sa premiere demande.</li>
        <li>Pendant l'essai, l'application utilise les options du produit.</li>
        </ul>
        <h3>Cas particuliers</h3>
        <ul>
        <li>Une cle a ete transmise autrement alors qu'une demande de cet ordinateur etait en attente : en activant la cle,
        l'application a oublie sa demande, mais celle-ci reste en attente ici. La refuser : l'accepter creerait une seconde
        licence que personne ne recupererait.</li>
        <li>La licence d'une demande acceptee a ete revoquee, liberee ("Changer d'ordinateur") ou a recu une "Nouvelle cle"
        avant que l'ordinateur ait recupere sa cle : il ne recevra jamais cette cle. A son controle suivant, il abandonne sa
        demande (l'essai s'arrete) et revient a "Licence requise". Pour le remettre en service : lui transmettre une cle
        ("Afficher la cle" sur la licence si elle n'est pas revoquee, sinon une nouvelle licence), ou le laisser envoyer une
        nouvelle demande (sans nouvel essai).</li>
        <li>La licence d'une demande acceptee a ete suspendue avant la remise de la cle : l'ordinateur ne la recoit pas
        encore, et la suspension ne le bloque pas pendant son essai ; ensuite il reste sur "Demande en attente". Des que la
        licence redevient active, il recupere sa cle tout seul a son controle suivant, sans rien ressaisir.</li>
        </ul>
        HTML;
}

function aide_section_licences(): string
{
    $html = <<<'HTML'
        <h3>Liste et recherche</h3>
        <ul>
        <li>Boutons "Nouvelle licence" (voir plus bas) et "Licence hors ligne" (ordinateur sans Internet, voir
        <a href="#horsligne">Licences hors ligne</a>). Quand la liste est filtree sur un produit (filtre "Produit", ou lien
        "Voir ses licences" de sa page), l'ecran propose a la place "Nouvelle licence pour ce produit" et "Licence hors ligne
        pour ce produit" (produit actif seulement), et "Retour au produit".</li>
        <li>"Recherche" porte sur le client, l'e-mail, les 4 derniers caracteres de la cle, l'identifiant de poste, le nom
        de l'ordinateur et la note (pas sur la cle complete). "Statut" : tous, actives, expirees, suspendues, revoquees.
        "Produit" : tous, ou un produit, inactifs compris. Bouton "Filtrer".</li>
        <li>Colonnes : Client (lien vers la page de la licence), Produit, Cle (4 derniers caracteres ; suivis de l'etiquette
        "hors ligne" pour une licence hors ligne, dont ce sont les 4 derniers caracteres du code court), Ordinateur
        (identifiant de poste et nom ; "non lie" tant que la cle n'a pas ete activee, et apres un changement d'ordinateur ou
        une "Nouvelle cle" ; licence hors ligne : l'identifiant du poste saisi dans la console, suivi de "jamais connecte"
        tant que le serveur n'a accepte aucun controle avec son code, ou de "non lie" si la licence a deja eu un contact mais
        n'est plus liee, par exemple apres un transfert), Echeance ("perpetuelle" s'il n'y en a pas, "illimitee" pour une
        licence hors ligne), Statut (Active, Expiree, Suspendue ou Revoquee ; une licence suspendue porte en plus "jusqu'au
        JJ/MM/AAAA" ou "(sans date de fin)"), Dernier contact (dernier controle reussi de l'ordinateur).</li>
        <li>La liste s'arrete a 500 lignes, triees par client : affiner la recherche, ou utiliser "Exporter les licences
        (CSV)", qui donne toutes les licences quels que soient les filtres.</li>
        </ul>
        <h3>Nouvelle licence</h3>
        <ul>
        <li>"Produit" : seuls les produits actifs sont proposes (s'il n'y en a aucun, l'ecran le dit et propose d'en creer
        un). S'il n'y en a qu'un, ou si vous venez de la page d'un produit ou d'une licence revoquee ("Nouvelle licence
        pour ce client"), il est deja choisi ; sinon, le choisir ("-- choisir un produit --"). Une cle saisie dans l'application d'un
        autre produit est refusee ("Cle invalide") : en cas de doute, le code du produit est ecrit dans la fenetre Licence
        de l'application (Ctrl+Maj+L, sous le titre "Licence" : "PRODUIT - version X").</li>
        <li>"Client (titulaire de la licence)" (obligatoire, 120 caracteres, affiche dans l'application), "E-mail du client
        (facultatif)" et "Note interne (facultative)" (500 caracteres, jamais montree au client).</li>
        <li>"Duree en jours (vide = perpetuelle)" : preremplie avec la duree par defaut du produit, et changee si vous
        changez de produit. La duree court des la creation, pas des la premiere activation.</li>
        <li>"Creer la cle", confirmation "Creer une cle de licence". La cle s'affiche avec "Copier", suivie des liens "Voir
        la licence", "Creer une autre licence pour ce produit" et "Retour au produit".</li>
        <li>Le serveur n'envoie rien au client : transmettez vous-meme la cle (le champ E-mail n'est qu'une note).</li>
        </ul>
        <p>Saisie dans l'application : fenetre "Licence requise", "J'ai une cle", taper ou coller la cle, "Activer". La
        saisie est possible dans la fenetre d'activation ("Licence requise", "Demande en attente", "Demande refusee",
        "Licence a verifier", "Licence suspendue", "Licence hors ligne expiree"), pas pendant un essai ni quand une licence fonctionne : pour un ordinateur
        en essai, accepter plutot sa demande. Format ETDEL-XXXX-XXXX-XXXX-XXXX ; majuscules, minuscules, tirets et espaces
        sont indifferents ; O est lu 0, I et L sont lus 1. Pendant la frappe, une ligne sous le champ guide l'utilisateur
        ("Cle incomplete : N/16 caracteres", "Caractere non valide : X", "Cle incorrecte : verifiez chaque caractere.",
        "Format correct : cliquez sur Activer."). Le dernier caractere est une somme de controle : un caractere mal tape est
        detecte sans contacter le serveur ("Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)" au clic sur
        "Activer"), mais pas deux caracteres inverses : le serveur repond alors "Cle invalide".</p>
        <h3>Page d'une licence : le resume</h3>
        <p>Ci-dessous, la page d'une licence ordinaire (cle ETDEL). Celle d'une licence hors ligne a son propre resume et ses
        propres actions : voir <a href="#hl-fiche">Page d'une licence hors ligne</a>.</p>
        <ul>
        <li><strong>Statut</strong> : Active, Expiree, Suspendue (avec "jusqu'au JJ/MM/AAAA" ou "(sans date de fin)") ou
        Revoquee.</li>
        <li><strong>Client</strong> et <strong>Produit</strong> (lien vers sa page).</li>
        <li><strong>Cle</strong> : ETDEL-****-****-****-XXXX et le bouton "Afficher la cle" (voir
        <a href="#t-cle">Retrouver une cle</a>) ; apres le clic, la cle complete s'affiche a sa place avec "Copier". Licence
        creee avant que la console garde les cles : "Cle complete non disponible..." ; licence revoquee : cle masquee, sans
        bouton.</li>
        <li><strong>Ordinateur</strong> : identifiant de poste et nom de l'ordinateur lie, ou "aucun : la cle s'activera sur
        le premier ordinateur ou elle sera saisie".</li>
        <li><strong>Echeance</strong> : date et jours restants, "(depassee)", "perpetuelle", ou "(sans objet : licence
        revoquee)".</li>
        <li><strong>Dernier contact</strong> : dernier controle reussi de l'ordinateur ("jamais" sinon).</li>
        </ul>
        <h3>Page d'une licence : "Que voulez-vous faire ?"</h3>
        <p>Un bouton par action ; un clic deplie une explication et le formulaire (une seule action depliee a la fois).
        Chaque action demande une confirmation, dont le libelle est indique ci-dessous.</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Bouton | Quand | Ce qu'il fait | Confirmation
        Prolonger | licence a echeance (pas perpetuelle) | "+30 jours" ou "+1 an" (365 jours) a partir de l'echeance, ou d'aujourd'hui si elle est passee ; ou "Ou choisir la nouvelle echeance" puis "Fixer la date" (fin de ce jour, fuseau {{FUSEAU}} ; date passee refusee ; une date plus proche raccourcit la licence). Seule l'echeance change : une licence suspendue le reste. Le choix entre perpetuelle et datee est definitif | Prolonger la licence
        Nouvelle cle | licence active ou suspendue | bouton "Creer une nouvelle cle" : voir <a href="#t-nouvelle-cle">Remplacer une cle</a> | Remplacer la cle (l'ancienne cle ne fonctionnera plus)
        Changer d'ordinateur | cle liee a un ordinateur | bouton "Liberer cette cle" : voir <a href="#t-transfert">Transferer une licence</a> | Changer d'ordinateur (liberer la cle)
        Suspendre | licence active (meme expiree) | champ "Jusqu'au (facultatif)" puis "Suspendre" : voir <a href="#t-suspendre">Suspendre</a> | Suspendre la licence
        Reactiver | licence suspendue | fin immediate de la suspension (la date de fin est effacee) | Reactiver la licence
        Changer la date de fin | licence suspendue | "Nouvelle date de fin (vide = sans date)", la date actuelle proposee, puis "Changer la date de fin" ; vide = suspension jusqu'a "Reactiver" | Suspendre la licence
        Revoquer | licence active ou suspendue | bouton rouge "Revoquer definitivement" : arret definitif (voir <a href="#t-revoquer">Revoquer</a>) | Revoquer definitivement la licence
        T);
    $html .= <<<'HTML'
        <p>Apres chaque action, un message confirme : "Licence prolongee.", "Licence suspendue.", "Licence reactivee.",
        "Licence revoquee.", "Cle liberee : elle peut etre saisie sur le nouvel ordinateur.", "Licence modifiee.". Une licence
        revoquee n'a plus aucune de ces actions : sa page dit que c'est definitif et propose seulement "Nouvelle licence pour
        ce client".</p>
        <h3>Page d'une licence : "Reglages avances"</h3>
        <p>Replies en bas de la page :</p>
        <ul>
        <li><strong>Modifier</strong> (sauf licence revoquee) : "Client (titulaire de la licence)", "E-mail", "Note
        interne", "Tolerance hors ligne en jours (vide = celle du produit)" (0 a 365 ; une valeur saisie fige la tolerance de
        cette licence), la case "Options du produit" (cochee : la licence suit les options du produit, et le champ suivant
        est ignore) et "Options propres a la licence (* = toutes)" (case decochee : cette liste remplace les options du
        produit pour cette licence ; vide = aucune option). "Enregistrer", confirmation "Modifier la licence". Les
        changements atteignent l'application a son controle suivant.</li>
        <li><strong>Informations techniques</strong> : Produit, E-mail, Note, Tolerance hors ligne ("produit (N j)" ou "N j
        (surcharge)"), Options (suivies de "(produit)" ou "(surcharge)"), Empreinte de l'ordinateur (debut), Version de
        l'application, Derniere IP, Origine (console ou demande), Lie le, Creee le, Modifiee le.</li>
        <li><strong>Historique</strong> : les 50 derniers evenements du journal pour cette licence : vos actions, chaque
        affichage de la cle (cle_affichee), les evenements des ordinateurs (activation, refus_autre_poste,
        licence_transferee, transfert_refuse) et du serveur (licence_reactivee par "systeme" a la fin d'une suspension
        datee).</li>
        </ul>
        HTML;
    return $html;
}

function aide_section_horsligne(): string
{
    $html = <<<'HTML'
        <p>Une licence hors ligne sert un ordinateur qui n'a jamais (ou rarement) Internet. Elle figure dans Licences comme
        les autres, mais au lieu d'une cle elle a deux codes, calcules pour un seul poste (son "Identifiant du poste") et
        verifies par l'application elle-meme, sans Internet. Pas a pas : <a href="#t-hors-ligne">Poste toujours hors
        ligne</a>.</p>
        <h3>Cle ou licence hors ligne ?</h3>
        HTML;
    $html .= aide_tableau(<<<'T'
        Point | Licence ordinaire (cle ETDEL-...) | Licence hors ligne (code ETDHL-... ou ETDHL2-...)
        Activation | par le serveur : Internet necessaire au moment de saisir la cle | sur l'ordinateur, sans Internet
        Ordinateur | le premier qui active la cle | seulement celui dont l'identifiant a ete saisi dans la console
        Echeance | transmise par le serveur | inscrite dans le code ; si l'ordinateur se connecte, celle du serveur la remplace
        Sans Internet | tolerance hors ligne, bandeau, puis blocage | aucun effet : valable jusqu'a l'echeance, sans bandeau ni blocage
        Prolonger, suspendre, revoquer | atteint l'ordinateur a son controle suivant | n'atteint l'ordinateur que s'il se connecte un jour
        Prolonger un ordinateur jamais connecte | sans objet | "Nouveau code hors ligne pour ce poste"
        Remplacer la cle | "Nouvelle cle" | "Nouveau code hors ligne pour ce poste"
        Autre ordinateur | "Changer d'ordinateur", ou "Transferer cette licence" dans l'application | "Nouveau code pour un autre ordinateur" (apres "Transferer cette licence" dans l'application, si l'ancien ordinateur a deja ete connecte)
        Options | celles du produit, ou propres a la licence | toutes ou aucune, choisi a la creation
        T);
    $html .= <<<'HTML'
        <h3>Les deux codes</h3>
        <ul>
        <li><strong>Code court</strong> : ETDHL-XXXX-XXXX-XXXX-XXXX (16 caracteres apres ETDHL), fait pour etre dicte. Meme
        alphabet que les cles (jamais I, L, O ni U ; majuscules, minuscules, tirets et espaces indifferents) ; le dernier
        caractere est une somme de controle. L'application le verifie avec le secret LICENCE_SECRET_HORS_LIGNE inscrit dans
        son module (voir <a href="#cles">Cles de signature</a>) ; une application construite sans ce secret refuse les codes
        courts et accepte les codes longs.</li>
        <li><strong>Code long</strong> : ETDHL2- suivi de 109 caracteres en groupes de 4 (143 caracteres en tout), fait pour
        etre copie-colle depuis un e-mail. Il est signe par la cle de signature du serveur ; l'application le verifie avec
        la cle publique inscrite dans son module ou adoptee lors d'une connexion.</li>
        <li>Les deux codes disent la meme chose : pour quel produit et quel identifiant de poste, jusqu'a quand (ou
        illimitee), avec toutes les options ou aucune. Le nom du client n'y figure pas. Saisi sur un autre ordinateur ou dans
        une autre application, un code est refuse.</li>
        <li><strong>Echeance</strong> : le code vaut jusqu'a la fin de son dernier jour de validite, celui qui tombe "Duree
        en jours" apres sa creation (fin du jour en temps universel, soit 1 h ou 2 h du matin a Paris le lendemain). La
        console et l'application affichent cette meme date. Ressaisir le code ne l'allonge pas : il faut un nouveau code.
        Reculer l'horloge de l'ordinateur ne fait pas gagner plus de 48 heures, comme pour une cle (voir
        <a href="#horloge">Horloge de l'ordinateur</a>).</li>
        </ul>
        <h3>Ecran Licence hors ligne</h3>
        <p>Bouton "Licence hors ligne" de l'ecran Licences, ou "Licence hors ligne pour ce produit" sur la page d'un produit
        actif (ou dans la liste filtree sur ce produit). L'ecran rappelle en tete a quoi sert une licence hors ligne et ou
        l'utilisateur lit son identifiant, et en bas ce qui se passe si l'ordinateur se connecte un jour.</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Champ | Valeurs | A savoir
        Produit | produits actifs ; deja choisi s'il n'y en a qu'un ou si vous venez d'un produit | l'application de l'ordinateur ; changer de produit change la duree proposee
        Identifiant du poste (affiche par l'application, par exemple SHXT-2380) | 8 caracteres, tiret facultatif, majuscules ou minuscules ; O lu 0, I et L lus 1 ; la lettre U est refusee | celui de l'application concernee sur l'ordinateur concerne. La console ne peut pas verifier qu'il est juste : mal dicte, il donne des codes que l'application refuse
        Client (titulaire de la licence) | 120 caracteres, obligatoire | n'est pas dans le code : l'application ne l'affiche qu'apres une connexion
        Duree en jours (vide = illimitee) | 1 a 36500, ou vide | preremplie avec la duree par defaut du produit ; vide = licence hors ligne illimitee, sans echeance
        Toutes les options (decochee : aucune option) | case, cochee d'office | cochee : toutes les options de l'application, presentes et futures ; decochee : aucune option
        T);
    $html .= <<<'HTML'
        <p>"Creer les codes", confirmation "{{HL_CONF_CREER}}". Une saisie refusee (identifiant de moins ou de plus de 8
        caracteres, lettre U, client vide, produit non choisi) reaffiche le formulaire avec ce qui a ete saisi, sous le
        message, par exemple "Identifiant du poste : les 8 caracteres affiches par l'application (fenetre d'activation ou
        fenetre Licence), par exemple SHXT-2380 : chiffres et lettres, sans la lettre U. Rien n'a ete cree." : corriger le
        champ et recommencer. Un produit inactif est refuse.</p>
        <p>La page de resultat dit "Licence hors ligne creee pour le poste XXXX-XXXX. Transmettez au client l'un des deux
        codes, au choix : ...", puis montre "Code court, a dicter par telephone" et "Code long, a envoyer par e-mail
        (copier-coller)", chacun avec "Copier", et le recapitulatif : Produit, Identifiant du poste, Client, Echeance
        ("(dernier jour de validite)", ou "illimitee") et Options ("toutes" ou "aucune"). Liens "Voir la licence", "Creer une
        autre licence hors ligne" et "Retour au produit". Cas rare : si une autre licence du meme poste et du meme produit a
        deja exactement la meme fin et les memes options, la fin est repoussee d'un jour pour que les codes different (la
        page le signale) ; pour une licence illimitee, la creation est refusee ("... indiquez une duree").</p>
        <h3 id="hl-fiche">Page d'une licence hors ligne</h3>
        <ul>
        <li><strong>Statut</strong> : comme pour une cle (Active, Expiree, Suspendue, Revoquee).</li>
        <li><strong>Type</strong> : etiquette "hors ligne" et le rappel "Licence hors ligne : son code est verifie par
        l'application sans Internet. Prolonger, Suspendre et Revoquer n'atteignent l'ordinateur que s'il se connecte un
        jour."</li>
        <li><strong>Client</strong> et <strong>Produit</strong>.</li>
        <li><strong>Codes</strong> : ETDHL-****-****-****-XXXX ou ETDHL2-****-...-XXXX-X (les fins que montre aussi
        l'application) et le bouton "Afficher les codes". Comme "Afficher la cle" : pas de confirmation, mais chaque affichage
        est inscrit au journal (cle_affichee, "code hors ligne ...XXXX"). La page se recharge avec les deux codes complets,
        chacun avec "Copier". Le code court est conserve chiffre comme les cles ; le code long est recalcule a chaque
        affichage. Apres une rotation de la cle de signature, le code long ne peut plus etre recalcule a l'identique : la
        page affiche "Code long non reaffichable : la cle de signature a change depuis sa creation..." (le code long deja
        transmis reste valable sur les ordinateurs qui connaissent l'ancienne cle). Licence revoquee : codes masques, sans
        bouton.</li>
        <li><strong>Poste</strong> : tant que le serveur n'a accepte aucun controle avec un code de cette licence,
        l'identifiant saisi dans la console, avec "(identifiant saisi a la creation ; jamais connecte au serveur : les codes
        ne fonctionnent que sur ce poste)" (apres "Nouveau code pour un autre ordinateur", c'est le nouvel identifiant) ; ou
        l'identifiant et le nom de l'ordinateur, avec "(lie depuis son premier contact avec le serveur)" ; ou, pour une
        licence qui a deja eu un contact mais n'est plus liee (transfert depuis l'application, ou nouveau code pour un autre
        ordinateur pas encore connecte), "(liee a aucun ordinateur : ...)".</li>
        <li><strong>Echeance</strong> : le dernier jour de validite et les jours restants, "(depassee)", ou "illimitee".</li>
        <li><strong>Options</strong> : "toutes", "aucune", ou la liste fixee dans les reglages avances.</li>
        <li><strong>Dernier contact</strong> : "jamais" tant que l'ordinateur ne s'est pas connecte.</li>
        </ul>
        <p>"Que voulez-vous faire ?" : chaque action qui depend du serveur porte la mention "{{HL_MENTION}}"</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Bouton | Quand | Ce qu'il fait | Confirmation
        Nouveau code hors ligne pour ce poste | licence active ou suspendue | "Duree en jours (vide = illimitee)", comptee a partir d'aujourd'hui (celle du produit est proposee), et "Toutes les options (decochee : aucune option)", puis "Creer le nouveau code" : un nouveau code court et un nouveau code long pour le meme poste, affiches avec "Copier". Ils remplacent les anciens sur la meme licence (meme numero, meme historique, statut conserve) : le serveur ne reconnait plus l'ancien code, mais un ordinateur jamais connecte le garde jusqu'a son echeance. C'est ainsi qu'on prolonge un ordinateur qui ne se connecte jamais. Une duree et des options qui redonneraient le code actuel sont refusees ("Le nouveau code serait identique au code actuel...") | {{HL_CONF_CODE}}
        Nouveau code pour un autre ordinateur | licence active ou suspendue | "Identifiant du nouveau poste (par exemple SHXT-2380)", la duree et la case des options, puis "Creer le code pour ce poste" : la licence passe a ce poste, n'est plus liee a aucun ordinateur et recoit de nouveaux codes ; les anciens ne sont plus reconnus par le serveur. Pour un identifiant mal dicte, un ordinateur perdu ou remplace, ou apres "Transferer cette licence" dans l'application (l'action vient alors en premier sur la page) | {{HL_CONF_POSTE}}
        Prolonger | licence a echeance | comme pour une cle ("+30 jours", "+1 an", "Fixer la date"), mais l'ordinateur ne l'apprend que s'il se connecte ; sinon, utiliser "Nouveau code hors ligne pour ce poste" | Prolonger la licence
        Suspendre, Reactiver, Changer la date de fin | comme pour une cle | l'ordinateur ne l'apprend que s'il se connecte : il est alors bloque et garde son code. Une suspension datee qu'il a recue se leve d'elle-meme a sa date, meme sans nouvelle connexion | Suspendre la licence, Reactiver la licence
        Revoquer | licence active ou suspendue | definitif. A sa connexion suivante, l'ordinateur efface son code et ne peut plus le ressaisir ; un ordinateur qui ne se connecte jamais garde son code jusqu'a son echeance | Revoquer definitivement la licence
        T);
    $html .= <<<'HTML'
        <p>"Nouvelle cle" et "Changer d'ordinateur" n'existent pas pour une licence hors ligne : ses codes ne valent que pour
        le poste saisi. Licence hors ligne revoquee : la page le dit ("Un poste qui ne se connecte jamais garde son code
        jusqu'a son echeance.") et propose "Nouvelle licence hors ligne pour ce poste", qui ouvre l'ecran Licence hors ligne
        pre-rempli (produit, client, identifiant du poste) ; apres "Creer les codes", la licence revoquee affiche "Deja
        remplacee par la licence n. Y".</p>
        <p>"Reglages avances" : comme pour une cle, sans "Tolerance hors ligne" (une licence hors ligne n'en a pas). Les
        options modifiees ici n'atteignent l'ordinateur que s'il se connecte.</p>
        <h3 id="hl-connexion">Si l'ordinateur se connecte un jour</h3>
        <p>Avec une licence hors ligne, l'application garde son cycle de controle habituel (3 secondes apres le lancement,
        puis toutes les 6 heures apres un controle reussi, 15 minutes apres un echec, et "Verifier maintenant") : elle
        envoie son code au serveur. Sans reponse (pas d'Internet, serveur injoignable), rien ne change : ni bandeau, ni
        compte a rebours, ni blocage ; seul un controle demande le signale ("Verifier maintenant" dans la fenetre Licence :
        "Serveur de licences injoignable : la licence hors ligne reste valable." ; voir aussi
        <a href="#application">Ce que voit l'utilisateur</a>). Des que le serveur repond :</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Situation sur le serveur | Ce qui se passe sur l'ordinateur
        Premier contact, licence active | si cet ordinateur est bien le poste saisi dans la console, la licence se lie a lui (journal : activation, "premier contact avec le serveur") ; un autre ordinateur qui presenterait le code est refuse (refus_autre_poste). L'application recoit la reponse signee habituelle : le client s'affiche ("Titulaire"), l'echeance et les options du serveur s'appliquent, "Transferer cette licence" devient possible
        Echeance prolongee ou avancee dans la console | la nouvelle echeance remplace celle du code, dans un sens comme dans l'autre, et reste valable ensuite, meme de nouveau sans Internet
        Licence suspendue | application bloquee (fenetre "Licence suspendue"), code conserve ; elle se debloque a un controle reussi apres "Reactiver", ou d'elle-meme a la date de fin d'une suspension datee
        Licence revoquee | "Licence revoquee" ; le code est efface et ne se ressaisit plus sur cet ordinateur ("Code hors ligne revoque, remplace ou transfere : demandez un nouveau code a ETDEL")
        Code remplace par un nouveau code, ou inconnu du serveur | "Cle invalide" ; le code est efface et ne se ressaisit plus ; saisir le nouveau code par "J'ai une cle"
        Echeance du serveur passee | "Licence hors ligne expiree : demandez un nouveau code a ETDEL" ; "Prolonger" dans la console puis "Reessayer" (avec Internet) suffit, ou un nouveau code
        T);
    $html .= <<<'HTML'
        <p>Tant que l'ordinateur ne s'est jamais connecte, la licence vaut jusqu'a l'echeance de son code ; ensuite, jusqu'a la
        derniere echeance recue du serveur.</p>
        <h3 id="hl-limites">Limites</h3>
        <ul>
        <li><strong>Non revocable tant que l'ordinateur ne se connecte pas.</strong> Un code transmis vaut sur son poste
        jusqu'a son echeance, ou toujours s'il est illimite : suspendre, revoquer, raccourcir ou remplacer le code n'y change
        rien tant que l'ordinateur ne joint pas le serveur. Preferer des durees raisonnables et des codes renouveles.</li>
        <li><strong>Le code court peut etre fabrique par quelqu'un qui decompile une application</strong> : le secret qui le
        verifie est inscrit dans l'application. Ce secret est le meme pour toutes les applications du serveur : qui l'a
        extrait d'une seule peut fabriquer des codes courts pour n'importe quel produit et n'importe quel identifiant de
        poste. La protection est seulement dissuasive. Un code fabrique est inconnu du
        serveur : il est efface des que l'ordinateur se connecte. <strong>Le code long, lui, ne peut pas etre
        fabrique</strong> : la cle privee qui le signe ne quitte jamais le serveur.</li>
        <li><strong>Apres une rotation de la cle de signature</strong>, un ordinateur jamais connecte ne connait que la cle
        publique inscrite dans son application : les codes longs crees ensuite ne fonctionnent que dans les applications
        construites avec la nouvelle cle publique (ou qui l'ont adoptee en se connectant). Pour les autres, transmettre le
        code court, qui n'est pas concerne. Les codes longs crees avant la rotation restent valables sur les ordinateurs qui
        les ont deja saisis, et se saisissent encore dans les applications qui ne connaissent que l'ancienne cle ; mais un
        ordinateur qui a adopte la nouvelle cle refuse l'ancienne pour une nouvelle saisie 90 jours apres la rotation
        (transmettre alors le code court), et la console ne peut plus les reafficher.</li>
        <li><strong>Pas de preavis</strong> : l'application ne previent pas avant l'echeance d'une licence hors ligne ; elle
        se bloque a la date. Surveiller les "Licences expirant sous 30 jours" de l'Accueil.</li>
        <li>Le nom du client n'est pas dans le code : la ligne "Titulaire" de la fenetre Licence reste "-" tant que
        l'ordinateur ne s'est pas connecte.</li>
        <li>Une copie de la base (ecran Sauvegarde) permet de retrouver par calcul un code court : la ranger en lieu sur,
        comme les codes eux-memes.</li>
        </ul>
        <h3>Journal</h3>
        <p>licence_hors_ligne_creee (votre identifiant ; detail : client, poste, echeance, options, 4 derniers caracteres du
        code court), code_hors_ligne_remplace (ancien et nouveau code par leurs 4 derniers caracteres ; ancien et nouveau
        poste pour un autre ordinateur), cle_affichee ("Afficher les codes"), activation par "poste:XXXX-XXXX" ("premier
        contact avec le serveur", ou "de nouveau" pour une licence deja vue par le serveur : apres un transfert, ou un
        nouveau code pour un autre ordinateur), refus_autre_poste (code presente par un autre
        ordinateur, avec le poste pour lequel il a ete etabli), licence_transferee, et secret_hors_ligne_cree (acteur
        "systeme", une seule fois). Un code n'y figure jamais en entier, pas plus que dans les e-mails ou les exports : 4
        derniers caracteres seulement. L'export des licences a une colonne "hors ligne" (oui ou non).</p>
        HTML;
    return $html;
}

function aide_section_etats(): string
{
    $html = <<<'HTML'
        <p>Six facons de bloquer, deplacer ou remplacer un acces, a ne pas confondre :</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Action | Effet sur l'ordinateur du client | Reversible ? | Ce que voit l'utilisateur | Remise en service
        <strong>Suspendre</strong>, avec ou sans date "Jusqu'au" | bloque a son controle suivant ; il garde sa cle et reste bloque, meme sans Internet, jusqu'a un controle reussi apres la fin de la suspension | oui : "Reactiver", ou tout seul a la fin du jour choisi | en cours d'utilisation : boite "Licence" avec le message, puis fermeture ; au lancement : fenetre "Licence suspendue" avec "Licence suspendue jusqu'au JJ/MM/AAAA. Elle sera reactivee automatiquement a cette date." ou "Licence suspendue. Contactez ETDEL pour la reactiver.", les boutons "J'ai une cle" et "Reessayer", et le lien "Transferer cette licence" | rien a ressaisir, meme pour une licence obtenue par demande : l'ordinateur se debloque seul a son premier controle apres la reactivation ou la date de fin ("Reessayer" pour tout de suite), s'il peut joindre le serveur
        <strong>Revoquer</strong> | bloque a son controle suivant ; il efface sa cle | <strong>non, jamais</strong> | message "Licence revoquee" puis fermeture ; au lancement suivant : fenetre "Licence requise" avec "J'ai une cle" et "Demander une licence". Si la fenetre d'activation est deja ouverte, elle passe directement a "Licence requise", avec le message | "Nouvelle licence pour ce client" (nouvelle cle a transmettre), ou nouvelle demande acceptee (pas de nouvel essai si cet ordinateur en a deja eu un pour ce produit)
        <strong>Changer d'ordinateur</strong> (console, "Liberer cette cle") | la cle n'est plus liee a aucun ordinateur ; l'ancien efface sa cle a son controle suivant | oui : la cle se lie au premier ordinateur qui l'active | ancien ordinateur : "Ce poste n'est plus autorise pour cette licence" puis fermeture, puis "Licence requise" | saisir la meme cle sur le nouvel ordinateur ("J'ai une cle")
        <strong>Transferer cette licence</strong> (dans l'application) | immediat : la cle est detachee sur le serveur et effacee de l'ancien ordinateur, qui doit etre connecte | oui : la cle se lie au premier ordinateur qui l'active | page "Licence transferee" avec la cle a noter, puis "Fermer l'application" ; au lancement suivant : "Licence requise" | saisir la meme cle sur le nouvel ordinateur
        <strong>Nouvelle cle</strong> | l'ancienne cle est refusee partout : l'ordinateur qui l'utilisait l'efface a son controle suivant | non pour l'ancienne cle ; la licence continue avec la nouvelle | message "Cle invalide" puis fermeture ; au lancement suivant : "Licence requise" | saisir la nouvelle cle ("J'ai une cle")
        <strong>Laisser expirer</strong> (ou "Fixer la date" a une echeance proche) | bloque a l'echeance, meme sans Internet ; il garde sa cle | oui : "Prolonger" | 15 jours avant : bandeau "Licence valable jusqu'au JJ/MM/AAAA (N jour(s) restant(s)). Contactez ETDEL pour la prolonger." ; a l'echeance : "Licence expiree le JJ/MM/AAAA. Contactez ETDEL pour la renouveler." puis fermeture ; au lancement : "Licence a verifier" avec "Reessayer" | prolonger, puis "Reessayer" ou relancer l'application
        T);
    $html .= <<<'HTML'
        <h3>A retenir</h3>
        <ul>
        <li><strong>Delai</strong> : suspendre, revoquer, changer d'ordinateur ou remplacer la cle n'agit qu'au controle
        suivant de l'ordinateur : au plus 6 heures s'il est connecte (plus 25 secondes pour l'affichage), au plus la
        tolerance hors ligne du produit s'il ne l'est pas. L'echeance, elle, est verifiee par l'application elle-meme, meme
        hors ligne. Seul "Transferer cette licence", fait dans l'application, est immediat.</li>
        <li><strong>Licence hors ligne</strong> : ces actions n'atteignent l'ordinateur que s'il se connecte un jour ; un
        ordinateur jamais connecte garde son code jusqu'a son echeance. Une suspension datee qu'il a recue se leve d'elle-meme
        a sa date, meme sans nouvelle connexion. On le prolonge, ou on change son code, par "Nouveau code hors ligne pour ce
        poste" ; "Nouvelle cle" et "Changer d'ordinateur" n'existent pas pour elle (voir
        <a href="#horsligne">Licences hors ligne</a>).</li>
        <li><strong>Remise en service</strong> : une reactivation ou une prolongation n'atteint l'ordinateur qu'a son
        controle suivant. Un ordinateur bloque ne refait un controle automatique que 6 heures apres le dernier refus : pour un
        deblocage immediat, faire cliquer "Reessayer" ou relancer l'application (controle 3 secondes apres le lancement). Il
        doit pouvoir joindre le serveur : un ordinateur deja bloque par une suspension le reste tant qu'il ne s'est pas
        connecte, meme apres la date de fin (sauf licence hors ligne, voir le point precedent).</li>
        <li><strong>La revocation est definitive</strong> : "Reactiver" ne s'applique qu'a une licence suspendue, et la meme
        cle ressaisie est refusee pour toujours ("Licence revoquee"). La licence reste visible (filtre "revoquees",
        compteur, journal, exports). Un ordinateur qui n'a jamais eu d'essai pour ce produit en obtient un s'il envoie une
        demande apres la revocation ; refuser la demande y met fin a son controle suivant.</li>
        <li><strong>Licences obtenues par demande</strong> : l'utilisateur n'a jamais tape sa cle, mais elle est
        consultable dans la console ("Afficher la cle") et dans l'application (fenetre Licence, "Afficher la cle").
        Suspendre puis reactiver fonctionne normalement : rien a ressaisir.</li>
        <li><strong>Desactiver un produit</strong> (case "Actif" de ses reglages avances) bloque, a leur controle suivant,
        tous les ordinateurs qui ont une cle de ce produit ("Produit inconnu ou inactif sur le serveur de licences"), sans
        effacer leur cle : recocher la case les debloque. Un ordinateur en periode d'essai n'est pas bloque ; il continue
        jusqu'a la fin de son essai, puis reste sur "Demande en attente".</li>
        <li>Chaque action est inscrite au journal : licence_suspendue (detail "active -> suspendue, jusqu'au JJ/MM/AAAA" ou
        "..., sans date de fin" ; un changement de date de fin donne "suspendue -> suspendue, ..."), licence_reactivee (avec
        votre identifiant, ou par "systeme" avec le detail "fin de la suspension programmee"), licence_revoquee,
        poste_libere, licence_transferee, cle_remplacee, licence_prolongee, code_hors_ligne_remplace.</li>
        </ul>
        HTML;
    return $html;
}

function aide_section_produits(): string
{
    $html = <<<'HTML'
        <p>Un produit = une application, reconnue par le code qu'elle envoie au serveur, avec ses regles, ses licences et ses
        demandes.</p>
        <h3>Ecran Produits</h3>
        <ul>
        <li>Bouton "Nouveau produit".</li>
        <li>Une ligne par produit : Produit (le code, lien vers sa page, suivi de "Actif" ou "Inactif", et de "cree
        automatiquement" pour un produit cree par une demande et jamais enregistre depuis), Nom, Regles en une phrase (par
        exemple "Licence 365 jours, essai 15 jours, aucune option, hors ligne 15 jours"), Licences (leur nombre ; un clic
        ouvre la liste de ses licences), En attente (demandes en attente ; un clic ouvre Demandes).</li>
        </ul>
        <h3>Comment un produit apparait</h3>
        <ul>
        <li><strong>Tout seul</strong>, a la premiere demande envoyee par une application dont le code est inconnu du
        serveur : nom = code, actif, regles par defaut (licence de {{DEFAUT_DUREE}}, essai de {{DEFAUT_ESSAI}} jours,
        tolerance hors ligne de {{DEFAUT_TOLERANCE}} jours, preavis de {{DEFAUT_PREAVIS}} jours, aucune option, pas de
        message). Le journal l'inscrit (produit_cree_automatiquement, acteur "systeme"), l'e-mail de la demande le signale
        ("NOUVEAU PRODUIT"), et sa page affiche "Produit cree automatiquement le ..." : verifier ses regles, puis cliquer
        "Enregistrer" (l'etiquette disparait). Seule une demande cree un produit : une activation de cle ou un controle,
        jamais.</li>
        <li><strong>A la main</strong> : "Nouveau produit", pour preparer des cles avant toute demande. "Code du produit
        (definitif)" : exactement le code que l'application envoie (lettres, chiffres, point, tiret, tiret bas ; 64
        caracteres ; majuscules et minuscules comptent : MONAPPLI n'est pas monappli). Un code deja pris, meme a la casse
        pres, est refuse ("Le produit X existe deja..."). Puis "Nom du produit" (120 caracteres) et les regles.
        "Enregistrer", confirmation "Enregistrer le produit" : la page du produit s'ouvre avec "Produit cree. Copiez la ligne
        ci-dessous dans l'application."</li>
        </ul>
        <h3>Page d'un produit</h3>
        <ul>
        <li>Code ("definitif : c'est le code que l'application envoie"), Nom, Etat (Actif ou Inactif).</li>
        <li>"Ligne a inserer dans l'application, juste apres la creation de la fenetre principale :", par exemple
        <code>etdel_licence.installer(root, produit="MONAPPLI", version=APP_VERSION)</code>, avec "Copier" : a donner au
        developpeur. Les quatre lignes de configuration du module sont sur l'ecran <a href="#cles">Cles de
        signature</a>.</li>
        <li>"Voir ses licences (N)", "Nouvelle licence pour ce produit" et "Licence hors ligne pour ce produit" (produit actif
        seulement).</li>
        <li>"Regles" : le formulaire decrit ci-dessous, puis "Enregistrer", confirmation "Enregistrer le produit", message
        "Produit enregistre.". Le code ne se modifie jamais : il est inscrit dans l'application.</li>
        </ul>
        <h3 id="regles">Chaque regle, et quelle valeur choisir</h3>
        <p>Les quatre premieres sont toujours visibles ; les autres sont repliees sous "Reglages avances" (depliees d'office
        pour un produit inactif).</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Champ | Valeurs | Effet | Quelle valeur choisir
        Nom du produit | 120 caracteres, obligatoire | nom affiche dans la console | le nom commercial de l'application
        Duree de licence par defaut (jours, vide = perpetuelle) | 1 a 36500, ou vide | duree proposee pour chaque nouvelle licence (creation dans la console, acceptation d'une demande, licence hors ligne et nouveau code hors ligne), modifiable a chaque fois ; sans effet sur les licences existantes | 365 pour un abonnement annuel, 30 pour un mois, vide pour une licence achetee une fois pour toutes
        Essai pendant une demande (jours, 0 = aucun) | 0 a 365 | l'application est utilisable pendant ce nombre de jours apres l'envoi d'une demande, en attendant votre reponse ; une seule fois par ordinateur pour ce produit ; vaut pour les demandes a venir | 15 pour une application diffusee largement (le temps d'essayer et de traiter la demande) ; 0 si vous creez vous-meme les cles de clients connus, sinon tout ordinateur qui a l'application peut l'utiliser pendant l'essai
        Options (codes separes par des virgules, * = toutes) | codes en minuscules, chiffres et _ (40 caracteres par code), ou * seul | fonctions activees dans l'application, pendant l'essai et pour les licences qui n'ont pas leurs propres options ; une option absente vaut "non" ; * active toutes les options, y compris celles qui seront ajoutees plus tard | vide si l'application n'a pas d'options. Les codes sont fixes par le developpeur dans l'application (par exemple export_pdf) : lui en demander la liste. La console ne verifie pas les codes : un code mal orthographie n'active rien, sans message. * pour une edition complete ou un usage interne
        Tolerance hors ligne (jours, 0 a 365) | reglage avance | duree pendant laquelle l'application fonctionne sans joindre le serveur ; 0 donne quand meme {{TOLERANCE_MIN_H}} heures (voir <a href="#tolerance">Hors ligne</a>). Sans effet sur les licences hors ligne, qui n'ont pas de tolerance | 15 convient a la plupart des cas ; 30 a 90 pour des clients souvent sans Internet ; moins si une suspension ou une revocation doit atteindre vite un ordinateur qui reste deconnecte
        Preavis (jours, 0 a 365) | reglage avance | l'application previent l'utilisateur ce nombre de jours avant la fin de la tolerance hors ligne (jamais avant la moitie de celle-ci) | 5 ; ce reglage ne concerne pas l'echeance, annoncee 15 jours avant (valeur fixe)
        Message d'accueil (facultatif, affiche dans la fenetre Licence de l'application) | reglage avance, {{ACCUEIL_MAX}} caracteres | texte affiche dans la fenetre Licence de l'application, sans accents | par exemple un numero d'assistance ; vide si inutile
        Actif | reglage avance, case | decochee : ses licences sont bloquees au controle suivant (cle conservee), ses demandes et activations sont refusees ("Produit inconnu ou inactif sur le serveur de licences") et on ne peut plus lui creer de licence ; recocher debloque tout | cochee ; decocher seulement pour arreter un produit, ou un produit cree par erreur
        T);
    $html .= <<<'HTML'
        <p>Ce qui change pour les licences existantes quand vous enregistrez : options, tolerance, preavis, message d'accueil
        et etat Actif atteignent chaque application a son controle suivant (une licence qui a ses propres options ou sa
        propre tolerance garde les siennes). La duree par defaut et l'essai ne valent que pour les licences et les demandes a
        venir.</p>
        <p>Un produit ne se supprime pas : on le desactive. Un ordinateur en periode d'essai d'un produit desactive n'est pas
        bloque : il continue jusqu'a la fin de son essai, puis reste sur "Demande en attente", et votre reponse ne lui
        parvient qu'une fois le produit reactive.</p>
        <p class="discret">Base creee par une ancienne version du serveur : un produit peut y avoir plusieurs jeux de regles.
        Le premier jeu actif (a defaut, le premier cree) s'applique a tout le produit, toutes ses licences restent
        valables, et le premier "Enregistrer" sur sa page aligne tous ces jeux sur les regles affichees.</p>
        HTML;
    return $html;
}

function aide_section_administration(): string
{
    $html = <<<'HTML'
        <p>Le menu Administration regroupe cinq ecrans techniques, utiles a l'installation et de temps en temps, rarement au
        quotidien, et le controle d'exposition :</p>
        <ul>
        <li><a href="#serveurs">Serveurs</a> : adresses de l'API diffusees aux applications ; en ajouter une, changer de
        domaine sans recompiler les applications.</li>
        <li><a href="#cles">Cles de signature</a> : lignes a inscrire une fois dans le module de l'application (cle publique
        et secret des codes hors ligne) ; nouvelle cle de signature en cas de doute sur la securite de l'hebergement.</li>
        <li><a href="#journal">Journal</a> : tout ce qui a ete fait, depuis la console et par les applications ; recherche
        et export CSV.</li>
        <li><a href="#sauvegarde">Sauvegarde</a> : copie de la base a telecharger ; liste des sauvegardes quotidiennes.</li>
        <li><a href="#reglages">Reglages</a> : e-mails de notification des nouvelles demandes, mot de passe de la
        console.</li>
        </ul>
        <h3>Controle d'exposition</h3>
        <p>Le navigateur essaie de telecharger, par leur URL et sans identifiants, les fichiers qui ne doivent jamais l'etre :
        config.php, .htpasswd, cle privee de signature active, secret qui chiffre les cles, secret des codes hors ligne, base
        licenses.db et son journal -wal, dossier des sauvegardes, schema.sql et lib/commun.php. Chaque ligne affiche un
        resultat :</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Resultat | Signification | Que faire
        verification... | essai en cours | attendre
        protege (401, 403 ou 404), en vert | le serveur a refuse ou ne connait pas ce fichier | rien
        DANGER, telechargeable, en rouge | le fichier a ete servi (reponse 200) : n'importe qui peut le telecharger | agir tout de suite (voir <a href="#depannage">Depannage</a>)
        non verifie (...), a controler a la main, en orange | aucune reponse probante (erreur reseau, redirection, autre code) | ouvrir l'URL indiquee dans une fenetre privee du navigateur : elle doit etre refusee
        T);
    $html .= <<<'HTML'
        <p class="discret">Le controle s'execute dans le navigateur : sans JavaScript, la liste reste vide. Si le dossier
        prive/ a ete place a cote du dossier servi (variante plus sure), les URL testees ne menent a aucun fichier et le
        resultat "protege" est juste.</p>
        HTML;
    return $html;
}

function aide_section_serveurs(): string
{
    return <<<'HTML'
        <p>Les applications ne dependent pas d'une seule adresse : chaque reponse du serveur contient la liste signee des URL
        diffusees, dans l'ordre de priorite (le plus petit nombre en premier). Les ordinateurs la memorisent (10 URL au
        plus) : changer d'URL ne demande ni recompilation ni nouvelle livraison. Une reponse mal signee ne remplace jamais cette
        liste.</p>
        <p>Chaque ordinateur s'adresse d'abord a la derniere URL qui lui a repondu, tant qu'elle figure dans la liste ; si
        elle ne repond pas, il essaie les autres dans l'ordre de priorite, puis les URL inscrites dans etdel_licence.py. Un
        ordinateur dont l'URL actuelle repond ne change donc pas d'URL a cause de la priorite.</p>
        <h3>Ecran Serveurs</h3>
        <ul>
        <li>Tableau : URL, Etat (Active ou inactive) et, pour chacune, "Priorite" (0 a 9999), case "Diffusee", bouton
        "Enregistrer" (confirmation "Modifier l'URL"). L'installation a cree l'URL principale (priorite 10) et l'URL de
        secours (priorite 20).</li>
        <li>En temps normal, il n'y a rien a faire sur cet ecran ; il sert a ajouter une adresse de secours ou a changer de
        domaine (procedure ci-dessous).</li>
        <li>"Ajouter une URL" : "URL de l'API (https://.../api/v1/)", en https, terminee par "/", 300 caracteres au plus ;
        "Priorite" (20 par defaut) ; bouton "Ajouter". La console ne verifie pas que l'URL repond : la tester avant.</li>
        <li>Une URL ne se corrige pas et ne se supprime pas : on la retire de la diffusion en decochant "Diffusee". La
        derniere URL diffusee ne peut pas etre retiree ("Au moins une URL doit rester diffusee.").</li>
        <li>Une URL qui redirige (http vers https, autre domaine) est vue comme injoignable par les applications : declarer
        l'adresse finale exacte.</li>
        <li>La premiere URL diffusee sert aussi pour l'adresse expeditrice par defaut (licences@ suivi de son domaine), pour
        les liens des e-mails (sauf si url_console est renseigne dans config.php) et pour la ligne LICENCE_URL de l'ecran
        Cles (la deuxieme pour LICENCE_URL_SECOURS).</li>
        </ul>
        <h3>Migration vers un nouveau domaine, pas a pas</h3>
        <ol>
        <li>OVH, Multisite : faire pointer le nouveau domaine sur le meme dossier licence, SSL coche. Verifier que
        https://NOUVEAU-DOMAINE/install.php repond "Installation deja effectuee".</li>
        <li>Serveurs, "Ajouter une URL" : https://NOUVEAU-DOMAINE/api/v1/ avec une priorite plus petite que l'actuelle
        (moins de 10) : elle passe en tete de la liste et les ordinateurs l'apprennent a leur controle suivant, mais ils
        continuent d'utiliser l'ancienne URL tant qu'elle est diffusee et repond.</li>
        <li>Attendre que les ordinateurs se soient connectes, au moins la duree de la tolerance hors ligne. La section "Suivi
        d'une migration" donne le nombre d'ordinateurs actifs vus depuis 24 h, 7 jours et 30 jours, sur le nombre
        d'ordinateurs lies ; la colonne Dernier contact (Licences) donne le detail.</li>
        <li>Reglages : changer l'adresse expeditrice si elle est sur l'ancien domaine.</li>
        <li>Decocher "Diffusee" pour l'ancienne URL. Chaque ordinateur recoit alors, par l'ancienne URL, la liste sans elle,
        et passe sur la nouvelle des son controle suivant. Laisser l'ancien domaine en service encore un moment, puis le
        couper.</li>
        </ol>
        <h3>Changer d'hebergeur</h3>
        <p>Changer d'hebergeur (nouveau domaine) demande plus de precautions, car chaque hebergement a sa propre base : ce
        qui est fait dans une console n'apparait pas dans l'autre.</p>
        <ol>
        <li>Envoyer serveur/www/ sur le nouvel hebergement, sans prive/ pour l'instant : tant que prive/ manque, la nouvelle
        URL repond 503 et les ordinateurs passent a l'URL suivante.</li>
        <li>Dans l'ancienne console, ajouter la nouvelle URL (etapes 2 et 3 ci-dessus) et continuer d'y travailler pendant
        l'attente.</li>
        <li>Pour basculer : dans l'ancienne console, decocher "Diffusee" pour toutes les URL de l'ancien hebergement
        (principale et secours), puis "Telecharger une copie de la base".</li>
        <li>Envoyer sur le nouvel hebergement tout le dossier prive/ de l'ancien (cles, secret des cles, secret des codes hors
        ligne, config.php, .htpasswd, install.verrou), en mettant cette copie a la place de prive/data/licenses.db (sans licenses.db-wal ni
        licenses.db-shm). Remplacer admin/.htaccess (celui du depot ferme la console) par la copie faite a l'installation, en
        corrigeant sa ligne AuthUserFile : elle contient le chemin complet de prive/.htpasswd sur l'ancien hebergement.
        Verifier que https://NOUVEAU-DOMAINE/install.php repond "Installation deja effectuee" et que la console s'ouvre.
        Recreer la tache planifiee de sauvegarde.</li>
        <li>Ne plus utiliser que la nouvelle console ; laisser l'ancien hebergement en service quelques jours, puis le
        couper.</li>
        </ol>
        <p>Sans les cles de signature d'origine, les applications rejetteraient les reponses du nouveau serveur ; sans le
        secret des cles (prive/cles/secret_demandes.key), la console ne pourrait plus ni creer une cle (Nouvelle licence,
        acceptation d'une demande, Nouvelle cle) ni en afficher une, et les cles des demandes acceptees pas encore remises
        n'arriveraient plus aux ordinateurs ; sans le secret des codes hors ligne (prive/cles/secret_hors_ligne.key), la
        console refuserait de creer une licence ou un code hors ligne.</p>
        HTML;
}

function aide_section_cles(): string
{
    return <<<'HTML'
        <p>A ne pas confondre avec les cles de licence (ETDEL-...) : les cles de signature protegent le dialogue entre le
        serveur et les applications. Toutes les reponses du serveur sont signees ; les applications verifient cette
        signature avec la cle publique. Sans la cle privee, personne ne peut fabriquer une reponse acceptee par les
        applications.</p>
        <h3>Ecran Cles de signature</h3>
        <ul>
        <li>"Cle publique active (kid N)" avec un bouton "Copier".</li>
        <li>"Lignes a reporter une fois en tete de etdel_licence.py :" LICENCE_URL, LICENCE_URL_SECOURS, LICENCE_CLE_PUBLIQUE
        et LICENCE_SECRET_HORS_LIGNE, avec un bouton "Copier". A faire une seule fois dans le module, avant de livrer : sans
        les trois premieres, le module ne fait rien et l'application fonctionne sans licence.</li>
        <li>LICENCE_SECRET_HORS_LIGNE est le secret qui verifie les codes hors ligne courts (voir
        <a href="#horsligne">Licences hors ligne</a>). Il est cree une fois pour toutes : a l'installation, ou, pour un
        serveur installe avant les licences hors ligne, au premier affichage de cet ecran ou a la premiere licence hors ligne
        (journal : secret_hors_ligne_cree). Il ne change jamais, meme apres une rotation. Une application construite sans cette ligne refuse les codes courts
        ("Codes hors ligne courts non pris en charge par cette application...") mais accepte les codes longs. L'ecran
        rappelle la limite assumee : quelqu'un qui decompile une application peut fabriquer des codes courts ; le code long,
        signe, est infalsifiable. Si le fichier du secret (prive/cles/secret_hors_ligne.key) a ete perdu ou remplace une fois
        cree (la base garde son empreinte des sa creation, meme si aucun code n'existe encore : des applications livrees
        l'embarquent peut-etre deja), l'ecran affiche en rouge "Secret des codes hors ligne absent ..." (ou "Secret des
        codes hors ligne different de celui cree pour ce serveur ...") et ne propose pas de quatrieme ligne : remettre le fichier depuis la copie de secours (voir
        <a href="#sauvegarde">Sauvegarde et restauration</a>). Le serveur ne le recree jamais de lui-meme, puisque les
        applications livrees embarquent l'ancien.</li>
        <li>"Historique" : kid, cle publique, active depuis, retiree le (avec la date jusqu'a laquelle les ordinateurs
        l'acceptent encore), bulletin ("premiere cle" ou "signe par la cle precedente").</li>
        </ul>
        <h3>Rotation</h3>
        <p>A faire seulement en cas de doute sur la confidentialite de l'hebergement (mot de passe FTP divulgue, ligne DANGER
        au controle d'exposition, acces suspect), apres avoir repris la main (mots de passe OVH et FTP changes).</p>
        <ol>
        <li>Bouton rouge "Nouvelle cle de signature", confirmer "Generer une nouvelle cle de signature (rotation,
        irreversible)".</li>
        <li>Le serveur cree une nouvelle paire, annonce la nouvelle cle par un bulletin signe avec l'ancienne, puis efface
        l'ancienne cle privee. Message : "Nouvelle cle de signature active : les ordinateurs l'adoptent a leur prochain
        controle."</li>
        <li>Aussitot : refaire par FTP la copie de secours de prive/cles/ (l'ancienne copie ne contient pas la nouvelle cle
        privee). Ne jamais supprimer les fichiers signature_N.json.</li>
        </ol>
        <p>Consequences : les applications deja livrees adoptent la nouvelle cle a leur connexion suivante, sans mise a jour,
        et acceptent encore l'ancienne pendant 90 jours ; une rotation apres une fuite ne neutralise donc pas l'ancienne cle
        immediatement. Les bulletins sont diffuses pendant 12 mois : un ordinateur reste hors ligne plus longtemps devra
        recevoir une version a jour de l'application. La rotation est irreversible. Elle ne change rien aux cles de licence
        ni aux codes hors ligne courts.</p>
        <p>Codes hors ligne longs : ils sont signes par la cle de signature active. Un ordinateur jamais connecte ne connait
        que la cle publique inscrite dans son application : apres une rotation, les codes longs crees ensuite ne fonctionnent
        que dans les applications construites avec la nouvelle cle publique (ou qui l'ont adoptee en se connectant) ; pour
        les autres, transmettre le code court. Les codes longs crees avant la rotation restent valables sur les ordinateurs
        qui les ont deja saisis (nouvelle saisie : voir <a href="#hl-limites">Limites</a>), mais la page de leur licence
        affiche "Code long non reaffichable..." ; "Nouveau code hors ligne pour ce poste" en
        cree de nouveaux.</p>
        <p>Apres une rotation, reporter la nouvelle ligne LICENCE_CLE_PUBLIQUE dans etdel_licence.py pour toute version livree
        ensuite. Un executable qui embarque une cle remplacee ne s'active sur un nouvel ordinateur (ou sur un ordinateur qui a
        perdu ses fichiers de licence) que pendant les 12 mois de diffusion du bulletin. Au-dela, il rejette toutes les
        reponses ("reponse invalide ou mal signee" dans le diagnostic), affiche "Serveur de licences injoignable" et ne peut
        ni activer une cle ni envoyer une demande.</p>
        HTML;
}

function aide_section_journal(): string
{
    return <<<'HTML'
        <h3>Ce qui est inscrit</h3>
        <ul>
        <li>Les actions de la console, avec votre identifiant de connexion et l'IP de votre navigateur : demande_acceptee,
        demande_refusee, licence_creee, licence_remplacee (sur une licence revoquee servie par "Nouvelle licence pour ce
        client" ou "Nouvelle licence hors ligne pour ce poste"), licence_prolongee, licence_modifiee, licence_suspendue, licence_reactivee, licence_revoquee, poste_libere
        ("Changer d'ordinateur"), cle_remplacee ("Nouvelle cle"), cle_affichee ("Afficher la cle", "Afficher les codes"),
        licence_hors_ligne_creee ("Licence hors ligne"), code_hors_ligne_remplace ("Nouveau code hors ligne pour ce poste",
        "Nouveau code pour un autre ordinateur"), produit_cree, produit_modifie, url_ajoutee, url_modifiee, reglages,
        email_test, mot_de_passe, cle_rotation, sauvegarde_telechargee.</li>
        <li>Les evenements des ordinateurs (acteur "poste:XXXX-XXXX") : demande, activation (premiere liaison d'une cle a un
        ordinateur ; pour une licence hors ligne, son premier contact avec le serveur), refus_autre_poste (cle ou code hors
        ligne presente par un autre ordinateur, avec le nom de celui-ci), licence_transferee (transfert depuis
        l'application), transfert_refuse (trop de transferts recents).</li>
        <li>Le serveur (acteur "systeme") : produit_cree_automatiquement, licence_reactivee (fin d'une suspension datee ;
        detail "fin de la suspension programmee"), email_envoye, email_echec, email_plafond, sauvegarde,
        cles_resynchronisees, secret_hors_ligne_cree (secret des codes hors ligne cree).</li>
        <li>L'assistant d'installation : installation, mot_de_passe_reinitialise.</li>
        <li>Une cle ou un code hors ligne n'y figure jamais en entier : seulement ses 4 derniers caracteres ("cle ...XXXX",
        "code hors ligne ...XXXX").</li>
        <li>Un controle reussi d'un ordinateur n'est pas inscrit : pour savoir quand il s'est connecte, lire "Dernier
        contact" sur la licence.</li>
        </ul>
        <h3>Consultation</h3>
        <p>"Recherche" (dans l'acteur, la cible, le detail et l'IP) et "Action" ("toutes" ou une action presente au
        journal), bouton "Filtrer". Le nombre d'evenements s'affiche, puis {{PAR_PAGE}} evenements par page, du plus recent au
        plus ancien, avec les liens "Plus recents" et "Plus anciens". La page d'une licence montre aussi ses 50 derniers
        evenements.</p>
        <h3>Exports CSV</h3>
        <ul>
        <li>"Exporter (CSV)" (Journal) : applique les filtres en cours, du plus ancien au plus recent.</li>
        <li>"Exporter les licences (CSV)" (Licences) : toutes les licences, quels que soient les filtres de l'ecran ; statut
        calcule (expiree compris) ; colonne "fin de cle" (4 derniers caracteres, jamais la cle ni le code hors ligne) ;
        colonne options remplie seulement pour une surcharge ; colonne "hors ligne" (oui pour une licence hors ligne).</li>
        <li>"Exporter les demandes (CSV)" (Demandes) : toutes les demandes, par numero.</li>
        <li>Format : separateur point-virgule, UTF-8 avec BOM (le fichier s'ouvre directement dans un tableur francais),
        dates AAAA-MM-JJ HH:MM, nom du fichier licences-AAAAMMJJ-HHMM.csv (ou demandes-, journal-). Une cellule qui commence
        par =, +, - ou @ est precedee d'une apostrophe : c'est voulu, aucune formule ne s'execute.</li>
        <li>Consulter un ecran ou exporter ne demande pas de confirmation et n'est pas inscrit au journal ; seuls "Afficher la
        cle" et "Afficher les codes" le sont.</li>
        </ul>
        HTML;
}

function aide_section_sauvegarde(): string
{
    return <<<'HTML'
        <h3>Sauvegarde quotidienne</h3>
        <p>Elle est faite par une tache planifiee OVH, pas par la console : espace client OVH, Hebergements, "Taches
        planifiees - Cron", "Ajouter une planification" ; commande <code>licence/prive/sauvegarde.php</code> (ou le chemin
        reel de prive/ s'il a ete place a cote de licence/) ; langage PHP, meme version que le site ; frequence quotidienne
        (par exemple a 3 h) ; cocher l'envoi du rapport par e-mail en cas d'erreur.</p>
        <p>Chaque execution cree une copie coherente de la base, prive/data/sauvegardes/licenses-AAAAMMJJ-HHMMSS.db (heure UTC
        dans le nom), et ne garde que les {{SAUVEGARDES}} plus recentes (reglage sauvegardes_conservees de config.php). Elle
        est inscrite au journal (action sauvegarde). L'ecran Sauvegarde affiche la taille de la base et les 10 dernieres
        sauvegardes ; "Aucune sauvegarde : verifier la tache planifiee." signale une tache absente ou en echec.</p>
        <h3>Copie a telecharger</h3>
        <p>Au moins une fois par semaine : "Telecharger une copie de la base", confirmer. Le fichier recu se nomme
        licenses-AAAAMMJJ-HHMM.db (heure de {{FUSEAU}}). Les sauvegardes quotidiennes restent sur l'hebergement : seule cette
        copie protege contre la perte de l'hebergement. Elle contient des donnees personnelles (clients, e-mails, IP,
        empreintes des ordinateurs), les hachages des cles et des codes hors ligne, les cles et les codes courts chiffres
        (ceux que montrent "Afficher la cle" et "Afficher les codes", et les cles des demandes acceptees pas encore
        remises) : la ranger en lieu sur (elle permet aussi de retrouver par calcul un code hors ligne court). Elle ne
        contient jamais de cle en clair, ni le secret qui dechiffre les cles, ni le secret des codes hors ligne, ni la cle
        privee de signature : ils restent dans prive/, hors de la base. Le telechargement est inscrit au journal
        (sauvegarde_telechargee).</p>
        <h3>Restauration (par FTP)</h3>
        <ol>
        <li>Renommer prive/data/licenses.db (par securite) ; supprimer prive/data/licenses.db-wal et
        prive/data/licenses.db-shm s'ils existent.</li>
        <li>Envoyer la copie choisie sous le nom prive/data/licenses.db.</li>
        <li>Ouvrir la console et verifier les listes. Une base d'une version precedente du serveur est mise a niveau
        d'elle-meme.</li>
        </ol>
        <p>Tout ce qui a ete fait apres la date de la copie disparait : licences creees ou acceptees, demandes, produits crees,
        revocations, suspensions, prolongations, journal. Consequences sur les ordinateurs :</p>
        <ul>
        <li>licence qui n'existe plus : l'ordinateur recoit "Cle invalide" et efface sa cle ; lui creer une nouvelle
        licence ;</li>
        <li>demande qui n'existe plus : l'ordinateur revient a "Licence requise" ;</li>
        <li>licence revoquee ou suspendue apres la date de la copie : elle redevient active ; refaire l'action ;</li>
        <li>cle activee pour la premiere fois apres la date de la copie : elle redevient non liee ; l'ordinateur affiche "Ce
        poste n'est plus autorise pour cette licence" et efface sa cle ; lui faire ressaisir la meme cle ;</li>
        <li>changement d'ordinateur (console ou application) fait apres la date de la copie : annule ; le nouvel ordinateur
        affiche "Cle deja utilisee sur un autre ordinateur" et efface sa cle ; refaire "Changer d'ordinateur", puis faire
        ressaisir la cle ;</li>
        <li>"Nouvelle cle" faite apres la date de la copie : annulee ; l'ancienne cle fonctionne de nouveau et la nouvelle est
        refusee ("Cle invalide") ; refaire "Nouvelle cle" si besoin ;</li>
        <li>licence hors ligne creee apres la date de la copie : elle n'existe plus ; un ordinateur jamais connecte continue
        de fonctionner avec son code, mais s'il se connecte, il recoit "Cle invalide" et efface le code ; lui creer une
        nouvelle licence hors ligne ;</li>
        <li>"Nouveau code hors ligne" fait apres la date de la copie : annule ; le serveur reconnait de nouveau l'ancien code
        et refuse le nouveau, qu'un ordinateur qui se connecte efface ("Cle invalide") ; refaire "Nouveau code hors ligne
        pour ce poste" et transmettre le code obtenu.</li>
        </ul>
        <p>Une copie anterieure a une rotation de cle de signature se recale d'elle-meme grace aux fichiers signature_N.json
        (journal : cles_resynchronisees).</p>
        <h3>Ce qui n'est pas dans la base : copie de secours hors ligne</h3>
        <p>A telecharger par FTP apres l'installation et a ranger hors ligne (cle USB chiffree, coffre de mots de passe),
        jamais dans un depot Git ni a cote des copies de la base :</p>
        <ul>
        <li>prive/config.php (jetons et parametres) ;</li>
        <li>prive/.htpasswd (acces a la console) ;</li>
        <li>prive/install.verrou (verrou de l'assistant : sans lui, install.php repond "Installation impossible" au lieu de
        "Installation deja effectuee", et la procedure Mot de passe perdu ne s'ouvre plus ; a defaut de copie, recreer par
        FTP un fichier de ce nom, au contenu indifferent) ;</li>
        <li>prive/reinitialisation.utilisee s'il existe (jetons de reinitialisation deja utilises) ;</li>
        <li>prive/cles/ : cles privees de signature (signature_N.key), fiches publiques (signature_N.json), secret qui
        chiffre les cles de licence (secret_demandes.key) et secret des codes hors ligne (secret_hors_ligne.key, inscrit dans
        les applications : irremplacable). A refaire apres chaque rotation ;</li>
        <li>admin/.htaccess genere par l'installation (celui du depot ferme la console).</li>
        </ul>
        <p>En cas de reconstruction complete de l'hebergement, remettre la base et ces fichiers. Sans les cles de signature,
        les applications rejetteraient le serveur ; sans le secret des cles, la console refuserait de creer ou d'afficher
        une cle ("Action impossible" avec "secret des demandes illisible") et les cles des demandes acceptees ne pourraient
        plus etre remises ; avec un autre secret (fichier remplace), "Afficher la cle" repondrait "Cle complete illisible
        (secret du serveur remplace ?)" pour les cles deja conservees. Qui
        detient a la fois une copie de la base et ce secret peut lire toutes les cles conservees : garder ces deux copies
        separees. Sans le secret des codes hors ligne, ou avec un autre, la console refuse de creer une licence ou un code
        hors ligne ("Secret des codes hors ligne absent ..." ou "... different ...") : ne jamais en fabriquer un nouveau,
        puisque les applications deja livrees embarquent celui-ci ; le remettre depuis la copie de secours.</p>
        HTML;
}

function aide_section_reglages(): string
{
    return <<<'HTML'
        <h3>Notifications</h3>
        <ul>
        <li>"Adresse(s) de notification (separees par des virgules, vide = aucun envoi)" : recoivent un e-mail a chaque
        nouvelle demande. Une adresse invalide est refusee a l'enregistrement.</li>
        <li>"Adresse expeditrice (sur le domaine du serveur)" : par exemple licences@licence.mondomaine.fr ; une adresse sur
        le domaine du serveur limite le classement en indesirable. Vide, elle vaut licences@ suivi du domaine de la premiere
        URL diffusee.</li>
        <li>"Enregistrer", puis "Envoyer un e-mail de test" : le test part vers les adresses deja enregistrees, pas vers
        celles seulement saisies. Resultat : "E-mail envoye a ...", "Aucune adresse de notification.", "Plafond quotidien
        d'e-mails atteint." ou "Echec de l'envoi : ...". "E-mail envoye" signifie que l'hebergement a accepte l'envoi, pas
        que l'e-mail est arrive.</li>
        <li>Plafond : {{EMAILS_JOUR}} e-mails par jour (emails_par_jour dans config.php), e-mails de test et envois echoues
        compris ; le compteur repart a minuit UTC. Au-dela, l'e-mail n'est pas envoye (journal : email_plafond), mais la
        demande est bien enregistree : consulter l'ecran Demandes.</li>
        </ul>
        <h3>Mot de passe de la console</h3>
        <p>"Nouveau mot de passe ({{MDP_MIN}} caracteres minimum)", "Confirmation", bouton "Changer le mot de passe". Seul le
        mot de passe du compte connecte ({{UTILISATEUR}}) change. Le navigateur redemande ensuite l'identifiant et le nouveau
        mot de passe. Aucun ecran ne permet d'ajouter ou de retirer un compte.</p>
        <h3>Mot de passe perdu</h3>
        <ol>
        <li>Par FTP, ouvrir prive/config.php et inscrire dans <code>jeton_reinitialisation</code> une nouvelle chaine
        aleatoire d'au moins {{JETON_MIN}} caracteres, differente du jeton d'installation et de tout jeton deja utilise.</li>
        <li>Ouvrir https://VOTRE-DOMAINE/install.php : la page "Reinitialiser le mot de passe de la console" demande le
        jeton, l'identifiant (le debut de chaque ligne de prive/.htpasswd, avant les deux-points), le nouveau mot de passe et
        sa confirmation.</li>
        <li>Apres "Mot de passe reinitialise", remettre <code>jeton_reinitialisation</code> a vide. Chaque jeton ne sert
        qu'une fois. Si install.php repond seulement "Installation deja effectuee", le jeton est trop court, identique au
        jeton d'installation ou deja utilise.</li>
        </ol>
        <h3>Serveur</h3>
        <p>La section "Serveur" affiche la version du serveur ({{VERSION_SERVEUR}}), la version de l'API ({{VERSION_API}}),
        les versions de PHP et de SQLite et l'utilisateur connecte. Les autres parametres (fuseau horaire, plafond d'e-mails,
        nombre de sauvegardes conservees, adresse de la console dans les e-mails) se reglent dans prive/config.php, par
        FTP.</p>
        HTML;
}

function aide_section_securite(): string
{
    return <<<'HTML'
        <ul>
        <li><strong>HTTPS</strong> : la console ({{URL_CONSOLE}}) n'est accessible qu'en https. En http, le serveur repond
        "Console accessible uniquement en https://" sans demander le mot de passe, qui ne circule donc jamais en clair. Tout
        le site redirige vers https et demande au navigateur de s'y tenir (HSTS).</li>
        <li><strong>Protection de la console</strong> : identifiant et mot de passe demandes par le serveur web (fichier
        .htpasswd, mot de passe hache, {{MDP_MIN}} caracteres au moins). La console refuse en plus toute requete que le
        serveur web n'a pas authentifiee. Pour se deconnecter, fermer le navigateur.</li>
        <li><strong>Actions protegees</strong> : chaque formulaire porte un jeton lie a la session du navigateur, renouvele
        apres chaque ecriture ; une requete venue d'un autre site est refusee ("Origine de la requete refusee."). Chaque
        ecriture demande une confirmation : boite du navigateur, ou, sans JavaScript, page "Confirmation" avec "Confirmer :
        ..." et "Annuler". "Changer le mot de passe" ne passe pas par cette page (la double saisie en tient lieu) ; "Afficher
        la cle" et "Afficher les codes" n'ont pas de confirmation, puisqu'ils ne modifient rien, mais ils sont inscrits au
        journal. Libelles des confirmations :
        {{CONFIRMATIONS}}</li>
        <li><strong>Rien n'est telechargeable</strong> : le dossier prive/ (base, cles, configuration, sauvegardes) est refuse
        par le serveur web ; le controle d'exposition (Administration) le verifie. Variante plus sure : placer prive/ a cote
        de licence/ et non dedans.</li>
        <li><strong>Cles de licence</strong> : le serveur garde de chaque cle son hachage (pour la reconnaitre), ses 4
        derniers caracteres (pour la retrouver) et, pour les cles creees depuis que la console les affiche, une copie
        chiffree (pour "Afficher la cle"). Le secret qui la dechiffre est conserve hors de la base (dans prive/) : une
        copie volee de la base ne donne aucune cle utilisable. La cle complete n'apparait que sur la page ouverte par "Afficher la
        cle", jamais dans le journal, les e-mails, les exports ni l'adresse d'une page. Qui controle tout l'hebergement
        (base et prive/cles/) peut en revanche lire les cles conservees : proteger les comptes OVH et FTP.</li>
        <li><strong>Codes hors ligne</strong> : memes regles que les cles (hachages des deux codes, code court chiffre, code
        long recalcule a l'affichage, 4 derniers caracteres seulement dans le journal, les e-mails et les exports). Le code
        long est signe par la cle privee du serveur : personne ne peut en fabriquer. Le code court est verifie par un secret
        inscrit dans les applications, le meme pour toutes : quelqu'un qui decompile une application peut fabriquer des
        codes courts pour n'importe quel produit et n'importe quel identifiant de poste (protection dissuasive, limite
        assumee) ; un tel code est efface des que l'ordinateur se connecte. Une copie de la base permet de retrouver par calcul un code court deja cree : proteger les copies.</li>
        <li><strong>Reponses signees</strong> : toutes les reponses aux applications sont signees, refus compris ; les
        requetes sont horodatees ({{ECART_MIN}} minutes d'ecart au plus).</li>
        <li><strong>Limites par adresse IP</strong> (IPv4, ou prefixe /64 en IPv6), en fenetres fixes, tentatives refusees
        comprises : {{LIMITES}}. Au-dela, l'application affiche "Trop de tentatives : reessayez plus tard". Les ordinateurs
        d'un meme bureau derriere la meme box partagent ces limites. En plus, au plus {{TRANSFERTS_MAX}} transferts par
        licence sur {{TRANSFERTS_JOURS}} jours depuis l'application : une cle ne peut pas tourner sans fin d'un ordinateur a
        l'autre.</li>
        <li><strong>Pages de la console</strong> : aucune ressource externe, aucun script ni style ecrit dans la page
        (politique de securite du contenu stricte), aucune mise en cache.</li>
        <li>La securite repose aussi sur le compte OVH : qui controle l'hebergement peut signer. Mot de passe fort et double
        authentification sur l'espace client.</li>
        </ul>
        HTML;
}

function aide_section_application(): string
{
    $html = <<<'HTML'
        <h3>Ou l'utilisateur voit sa licence</h3>
        <ul>
        <li><strong>Fenetre d'activation</strong> (titre "Licence - PRODUIT") : s'ouvre au lancement quand l'application ne
        peut pas etre utilisee ; la fenetre principale reste alors cachee. En bas, l'identifiant du poste avec "Copier"
        (c'est lui qu'il faut pour une licence hors ligne). La fermer par la croix quitte l'application.</li>
        <li><strong>Bandeau</strong> en haut de la fenetre principale, seulement pendant un essai (bleu clair) ou un
        avertissement (couleur de l'application), avec le lien "Details" ; un clic ouvre la fenetre Licence. Il ne se ferme
        pas.</li>
        <li><strong>Fenetre Licence</strong>, a tout moment par les touches Ctrl+Maj+L (ou un clic sur le bandeau) : titre
        "Licence", produit et version de l'application ; le statut (pastille de couleur et message) ; Titulaire, Echeance,
        Dernier controle ; Identifiant du poste ("Copier") ; Cle, masquee comme dans la console
        (ETDEL-****-****-****-XXXX), avec "Afficher la cle" / "Masquer la cle" ; Nom de l'ordinateur, suivi du lien
        "Transferer cette licence" quand le transfert est possible ; le message d'accueil du produit ; "Verifier maintenant"
        et son resultat ; la zone "Diagnostic" avec "Copier le diagnostic" ; "Fermer" (ou Echap). On n'y saisit rien.
        Certaines applications affichent le meme cadre dans leurs reglages, sans le lien de transfert. Licence hors ligne :
        la ligne s'appelle "Code hors ligne" (ETDHL-****-****-****-XXXX, ou ETDHL2-****-...-XXXX-X pour un code long), avec
        "Afficher le code" / "Masquer le code" ; Echeance montre le dernier jour de validite ou "Illimitee" ; Titulaire et
        Dernier controle restent "-" et "Jamais" tant que l'ordinateur ne s'est pas connecte ; "Transferer cette licence"
        n'apparait qu'apres une premiere connexion ; le diagnostic porte "(licence hors ligne)".</li>
        <li><strong>Page "J'ai une cle"</strong> (fenetres "Licence requise", "Demande en attente", "Demande refusee",
        "Licence a verifier", "Licence suspendue", "Licence hors ligne expiree") : champ "Cle de licence" (mis en forme pendant la frappe : majuscules,
        tirets, ETDEL-), boutons "Retour" et "Activer" (ou Entree). Pendant l'envoi : "Connexion au serveur...". Une faute de
        frappe est signalee sans contacter le serveur ; un refus du serveur s'affiche sous le champ (voir
        <a href="#codes">les codes de refus</a>) et la cle n'est pas enregistree. Si la cle est acceptee, la fenetre se ferme
        et l'application s'ouvre. Le meme champ accepte un code hors ligne (indication "ou code hors ligne (ETDHL-...,
        ETDHL2-...)") : voir ci-dessous.</li>
        <li><strong>Code hors ligne dans "J'ai une cle"</strong> : des que la saisie commence par ETDHL, la ligne sous le champ
        dit "Code hors ligne : ETDHL-XXXX-XXXX-XXXX-XXXX (les tirets s'ajoutent seuls)" ou, pour ETDHL2, "Code hors ligne
        long : collez-le en entier", puis "Code incomplet : N/16 caracteres" (N/109 pour un code long), "Code incorrect :
        verifiez chaque caractere." ou "Format correct : cliquez sur Activer.". "Activer" verifie le code sur l'ordinateur, sans Internet ("Verification du
        code..."). Refus possibles, affiches sous le champ : "Code hors ligne refuse : il ne correspond pas a cet ordinateur
        (identifiant du poste XXXX-XXXX) ou contient une erreur de saisie." (code d'un autre poste, d'une autre application,
        ou mal saisi) ; "Codes hors ligne courts non pris en charge par cette application : demandez le code long (ETDHL2-...)
        a ETDEL" ; "Code hors ligne expire le JJ/MM/AAAA : demandez un nouveau code a ETDEL" ; "Code hors ligne revoque,
        remplace ou transfere : demandez un nouveau code a ETDEL" (code que le serveur a deja retire de cet ordinateur) ;
        "Code hors ligne invalide : verifiez la saisie". Ressaisir le code deja enregistre (ou son autre forme) ne change
        rien, et ne leve pas une suspension.</li>
        <li><strong>Fenetre "Transferer cette licence"</strong> (lien du meme nom) : la cle complete a noter ("Copier"),
        l'avertissement "L'application sera desactivee sur cet ordinateur." et "Cet ordinateur doit etre connecte a
        Internet.", boutons "Annuler" et "Transferer". Pendant l'envoi : "Transfert en cours...". Succes : page "Licence
        transferee" avec la cle, puis "Fermer l'application". Echec : le message s'affiche (par exemple "Transfert
        impossible : serveur de licences injoignable. Connectez l'ordinateur a Internet puis reessayez.") et rien ne change.
        Voir <a href="#t-transfert">Transferer une licence</a>. Licence hors ligne (deja connectee une fois) : pas de code a
        noter, puisqu'il ne vaut que pour cet ordinateur ; la fenetre dit "Le code hors ligne ne vaut que pour cet
        ordinateur : communiquez a ETDEL l'identifiant du poste du nouvel ordinateur pour obtenir son code." et, apres le
        transfert, "Licence transferee : communiquez a ETDEL l'identifiant du poste du nouvel ordinateur pour obtenir son
        code." Dans la console : "Nouveau code pour un autre ordinateur" sur la page de la licence.</li>
        </ul>
        <h3>Statut par statut</h3>
        HTML;
    $html .= aide_tableau(<<<'T'
        Statut (pastille de la fenetre Licence) | Quand | Ce que voit l'utilisateur | Que faire
        Licence requise (Non activee) | ni cle ni demande : premier lancement, licence revoquee, liberee, remplacee ou transferee | fenetre "Licence requise" : "Cette application necessite une licence ETDEL.", deux choix "J'ai une cle" et "Demander une licence", bouton "Quitter" | transmettre une cle, ou attendre sa demande
        Essai (Essai) | demande en attente, essai en cours | juste apres l'envoi, page "Demande envoyee" ("En attendant la reponse, l'application est utilisable pendant N jour(s).", "Continuer") ; puis application utilisable, bandeau "Licence en cours de traitement - N jour(s) d'essai restant(s)" | traiter la demande avant la fin de l'essai
        Demande en attente (En attente) | demande en attente, sans essai ou essai termine | fenetre "Demande en attente" : "Demande envoyee le JJ/MM/AAAA. Le traitement peut prendre plusieurs jours.", puis "La periode d'essai est terminee." ou "Aucune periode d'essai n'est disponible pour ce poste.", "La reponse est verifiee automatiquement chaque minute.", boutons "J'ai une cle" et "Verifier maintenant" ; application inutilisable | accepter ou refuser la demande
        Demande refusee (Refusee) | demande refusee | fenetre "Demande refusee" : "ETDEL n'a pas donne suite a la demande de licence.", le motif, "J'ai une cle" et "Nouvelle demande" | aucune action
        Valide (Valide) | licence en regle | rien : l'application s'ouvre normalement | aucune action
        Licence hors ligne valable (Valide) | code hors ligne saisi, echeance a venir (voir <a href="#horsligne">Licences hors ligne</a>) | rien : l'application s'ouvre normalement ; la fenetre Licence affiche "Licence hors ligne valable jusqu'au JJ/MM/AAAA" ou "Licence hors ligne illimitee". Aucun bandeau, ni avant l'echeance ni sans Internet ; "Verifier maintenant" sans Internet repond "Serveur de licences injoignable : la licence hors ligne reste valable." | aucune action ; a l'echeance, "Nouveau code hors ligne pour ce poste"
        Licence hors ligne expiree (Bloquee) | echeance du code atteinte (ou celle recue du serveur, si l'ordinateur s'est connecte) | en cours d'utilisation : boite "Licence" avec le message, puis fermeture ; au lancement : fenetre "Licence hors ligne expiree" : "L'application ne peut pas s'ouvrir pour le moment.", "Licence hors ligne expiree le JJ/MM/AAAA : demandez un nouveau code a ETDEL", boutons "Reessayer" et "J'ai une cle" ; le code est conserve | "Nouveau code hors ligne pour ce poste", saisi par "J'ai une cle" ; ou, si l'ordinateur peut se connecter, "Prolonger" puis "Reessayer"
        Avertissement (Attention) | echeance dans moins de 15 jours, ou fin de la tolerance hors ligne proche | bandeau "Licence valable jusqu'au JJ/MM/AAAA (N jour(s) restant(s)). Contactez ETDEL pour la prolonger." ou "Licence non verifiee depuis N jour(s) : connectez l'ordinateur a Internet avant le JJ/MM/AAAA." | prolonger, ou faire connecter l'ordinateur
        Bloquee (Bloquee) | echeance passee, tolerance epuisee, licence suspendue, produit inactif | en cours d'utilisation : boite "Licence" avec le message, puis fermeture ; au lancement : fenetre "Licence a verifier" ("Licence suspendue" pour une suspension) : "L'application ne peut pas s'ouvrir pour le moment.", le message, "J'ai une cle" et "Reessayer" (et, pour une suspension, "Changer d'ordinateur : Transferer cette licence") ; la cle est conservee | prolonger, "Reactiver", cocher "Actif" sur le produit, ou faire connecter l'ordinateur ; suspension datee : rien a faire. L'ordinateur se debloque seul, sans ressaisie, a son premier controle apres le changement (3 secondes apres un lancement, sinon jusqu'a 6 heures plus tard ; "Reessayer" le fait tout de suite)
        Revoquee (Revoquee, ou Transferee) | licence revoquee, cle liberee, liee a un autre ordinateur, inconnue ou remplacee ; ou licence transferee depuis cet ordinateur | message ("Licence revoquee", "Ce poste n'est plus autorise pour cette licence", "Cle deja utilisee sur un autre ordinateur" ou "Cle invalide") puis fermeture ; la cle est effacee ; au lancement suivant : "Licence requise". Si la fenetre d'activation est deja ouverte, pas de fermeture : elle passe a "Licence requise", avec le message. Apres un transfert : page "Licence transferee" (pastille "Transferee"), puis "Fermer l'application" | nouvelle cle ou nouvelle demande
        Non configure (Non configuree) | LICENCE_CLE_PUBLIQUE vide, ou LICENCE_URL et LICENCE_URL_SECOURS vides toutes les deux, dans etdel_licence.py | rien : le module ne fait rien et l'application fonctionne sans licence | reporter les quatre lignes de l'ecran Cles avant de livrer
        T);
    $html .= <<<'HTML'
        <h3>Ce que l'utilisateur peut vous dicter</h3>
        <ul>
        <li><strong>Sa cle</strong> : fenetre Licence, ligne Cle (ETDEL-****-****-****-XXXX, ou en entier apres "Afficher la
        cle"). Les 4 derniers caracteres sont ceux de la colonne Cle des Licences : les taper dans "Recherche" retrouve la
        licence. Licence hors ligne : ligne "Code hors ligne", meme principe (4 derniers caracteres du code court ; pour un
        code long, la fin affichee ETDHL2-****-...-XXXX-X figure sur la page de la licence).</li>
        <li><strong>Identifiant du poste</strong> (XXXX-XXXX) : fenetre Licence, ou en bas de la fenetre d'activation. Il ne
        contient jamais les lettres I, L, O ni U (un O dicte est un zero, un I ou un L est un 1). Il est different pour chaque
        produit sur un meme ordinateur : demander celui de la bonne application. Il correspond a la colonne "Identifiant"
        (Demandes) ou "Ordinateur" (Licences). Pour le retrouver : Licences, taper l'identifiant AVEC son tiret dans
        "Recherche", puis "Filtrer". Rien dans Licences : chercher dans les demandes en attente (Ctrl+F du navigateur), puis
        dans le Journal (acteur "poste:XXXX-XXXX"). Apres un changement d'ordinateur, la licence ne porte plus l'identifiant :
        seul le journal le garde (poste_libere, licence_transferee). Une licence hors ligne, elle, porte toujours l'identifiant
        saisi dans la console (a sa creation, ou par "Nouveau code pour un autre ordinateur"), meme si l'ordinateur ne s'est
        jamais connecte. C'est aussi l'identifiant a saisir dans l'ecran Licence hors ligne.</li>
        <li><strong>Ligne de diagnostic</strong> (zone "Diagnostic", bouton "Copier le diagnostic") : version du module,
        produit et version de l'application, poste, statut, echeance, dernier controle, serveur qui a repondu, numero (kid) de
        la cle de signature et, a la fin, la raison du dernier echec : par exemple "serveur injoignable (...)" (pour chaque
        adresse essayee : le nom de l'erreur reseau, "http 503" ou "reponse invalide ou mal signee"), un code de refus ou
        "Horloge de l'ordinateur incorrecte...". La cle n'y figure jamais.</li>
        <li><strong>Dernier controle</strong> : date du dernier controle tranche par le serveur. Une date ancienne signale un
        probleme de connexion ou d'horloge.</li>
        </ul>
        <h3>Verifier maintenant, Reessayer</h3>
        <p>Ces boutons lancent un controle immediat. Si le statut change, la fenetre bascule ou se ferme. Sinon, la fenetre
        d'activation affiche "Verifie le JJ/MM/AAAA HH:MM : pas encore de reponse." (demande en attente) ou "... : situation
        inchangee.", ou la raison de l'echec. Dans la fenetre Licence : "Licence verifiee aupres du serveur.", "Verifie :
        demande toujours en cours de traitement.", "Verification terminee." ou la raison de l'echec ; sans cle ni demande, le
        bouton est grise ("Aucune licence a verifier sur ce poste."). Licence hors ligne, sans Internet : la fenetre Licence
        repond "Serveur de licences injoignable : la licence hors ligne reste valable." ; "Reessayer" dans la fenetre "Licence
        hors ligne expiree" repond "Serveur de licences injoignable : saisissez le nouveau code avec "J'ai une cle"."</p>
        <h3>Quand l'application se ferme-t-elle ?</h3>
        <p>Si, en cours d'utilisation, la licence devient expiree (licence hors ligne comprise, a son echeance, meme sans
        Internet), suspendue ou revoquee, si la cle ou le code est libere ou remplace, ou si le produit est desactive, l'application affiche une boite "Licence" avec le message, puis se ferme apres le
        clic sur OK (au plus 25 secondes apres le controle) : le travail non enregistre peut etre perdu. Apres un transfert,
        c'est l'utilisateur qui la ferme ("Fermer l'application"). Si une demande est refusee ou si l'essai se termine en
        cours d'utilisation, la fenetre principale est remplacee par la fenetre d'activation.</p>
        <p>Application en mode console (sans fenetre) : controle au lancement seulement ; sans licence, la cle (ou un code
        hors ligne) est demandee au clavier si le programme est lance dans une console interactive, apres l'identifiant du
        poste ; de meme, pour un nouveau code, quand une licence hors ligne est bloquee (expiree, ou suspendue). Sinon (tache planifiee, entree redirigee), il
        s'arrete aussitot avec le code de sortie 3. Aucune demande ni aucun transfert n'est possible : il faut lui creer une
        cle (ou une licence hors ligne), et passer par "Changer d'ordinateur" dans la console. Une licence inutilisable
        arrete le programme (code de sortie 3).</p>
        HTML;
    return $html;
}

function aide_section_tolerance(): string
{
    $html = <<<'HTML'
        <h3>Trois notions differentes</h3>
        <ul>
        <li><strong>Echeance</strong> : date de fin de la licence, fixee dans la console (aucune pour une licence
        perpetuelle). Elle est inscrite dans la reponse signee que l'application conserve : celle-ci la verifie elle-meme,
        meme hors ligne. Bandeau 15 jours avant (valeur fixe du module), blocage a la date.</li>
        <li><strong>Tolerance hors ligne</strong> : duree pendant laquelle l'application fonctionne sans controle reussi
        aupres du serveur. Chaque controle reussi la fait repartir de zero. Reglee par produit ({{DEFAUT_TOLERANCE}} jours
        pour un nouveau produit) ou par licence (surcharge), de 0 a 365 jours ; jamais moins de {{TOLERANCE_MIN_H}} heures,
        meme a 0.</li>
        <li><strong>Preavis</strong> (reglage du produit, {{DEFAUT_PREAVIS}} jours pour un nouveau produit) : nombre de jours
        avant la fin de la tolerance ou le bandeau apparait, jamais avant la moitie de la tolerance. Il ne concerne pas
        l'echeance et n'a pas de surcharge par licence.</li>
        </ul>
        <h3>Exemple jour par jour (tolerance 15 jours, preavis 5 jours)</h3>
        HTML;
    $html .= aide_tableau(<<<'T'
        Jour | Situation | Ce que voit l'utilisateur
        J0 | dernier controle reussi, puis l'ordinateur reste sans Internet | rien
        J1 a J9 | aucun contact avec le serveur | rien, l'application fonctionne normalement
        J10 a J14 | debut du preavis (15 - 5 = 10 jours) | bandeau "Licence non verifiee depuis 10 jour(s) : connectez l'ordinateur a Internet avant le JJ/MM/AAAA." (le nombre de jours augmente chaque jour)
        J15 | tolerance epuisee | message "Licence non verifiee depuis trop longtemps : connectez l'ordinateur a Internet puis reessayez." puis fermeture ; au lancement : "Licence a verifier" avec "Reessayer"
        N'importe quel jour | Internet revient et un controle reussit | tout redevient normal, pour 15 nouveaux jours
        T);
    $html .= <<<'HTML'
        <p>Un ordinateur dont les controles reussissent (toutes les 6 heures s'il est connecte) ne voit pas ce bandeau, ni un
        ordinateur hors ligne qui se reconnecte avant le debut du preavis (J10 dans l'exemple). Le bandeau ne commence jamais
        dans les 7 heures qui suivent un controle reussi : avec une tolerance de 0 ({{TOLERANCE_MIN_H}} heures), il n'y a
        donc aucun bandeau, et un ordinateur qui n'a pas pu se controler passe directement de "valide" a "bloque" au bout de
        {{TOLERANCE_MIN_H}} heures. Une modification de la tolerance n'atteint l'ordinateur qu'a son controle reussi suivant.
        Si l'echeance et la fin de tolerance approchent toutes les deux, le bandeau annonce la date la plus proche.</p>
        <p>Pendant la tolerance, un ordinateur deconnecte n'apprend rien de ce que vous faites dans la console (suspension,
        revocation, changement d'ordinateur) : il ne l'apprend qu'en se reconnectant, ou se bloque a la fin de la
        tolerance.</p>
        <p><strong>Licence hors ligne</strong> : ni tolerance ni preavis. Elle vaut jusqu'a son echeance (celle du code, ou la
        derniere recue du serveur si l'ordinateur s'est connecte), meme sans aucun controle reussi : pas de bandeau "Licence
        non verifiee", pas de blocage faute d'Internet, et pas non plus de bandeau 15 jours avant l'echeance. Les controles
        ont lieu aux memes moments que pour une cle ; quand ils echouent, rien ne change (voir
        <a href="#hl-connexion">Si l'ordinateur se connecte un jour</a>).</p>
        <h3>Cycle de controle</h3>
        HTML;
    $html .= aide_tableau(<<<'T'
        Moment | Controle aupres du serveur
        Lancement de l'application | 3 secondes apres
        Apres un controle reussi | 6 heures plus tard
        Apres un echec (serveur injoignable, horloge, trop de tentatives) | 15 minutes plus tard
        Demande en attente sans essai | chaque minute
        Demande en attente pendant l'essai | toutes les 6 heures
        "Verifier maintenant" (fenetre Licence ou fenetre d'activation), "Reessayer" | immediatement
        "Transferer" (fenetre de transfert) | immediatement
        Ni cle ni demande | aucun echange avec le serveur
        T);
    $html .= <<<'HTML'
        <p>Un controle est "reussi" des que le serveur a tranche, y compris par un refus. Exemple : apres le refus d'une
        licence expiree, le controle automatique suivant n'a lieu que 6 heures plus tard ; apres une prolongation, faire
        cliquer "Reessayer" ou relancer l'application.</p>
        <h3 id="horloge">Horloge de l'ordinateur</h3>
        <ul>
        <li>Le serveur refuse toute requete dont l'heure differe de la sienne de plus de {{ECART_MIN}} minutes : le controle
        echoue et l'ordinateur reessaie 15 minutes plus tard, sans rien afficher tant que la licence reste utilisable. Le
        message "Horloge de l'ordinateur incorrecte : corrigez la date et l'heure" se lit dans la zone Diagnostic de la
        fenetre Licence, apres "Verifier maintenant" ou "Reessayer", et a la saisie d'une cle, a l'envoi d'une demande ou a
        un transfert. La tolerance hors ligne continue de s'ecouler pendant ce temps : le premier signe visible est en
        general le bandeau "Licence non verifiee depuis N jour(s)...", puis le blocage.</li>
        <li>Reculer l'horloge ne permet pas de revenir plus de 48 heures avant l'heure la plus avancee que l'application a
        deja vue. L'application retient cette heure, qui avance aussi pendant qu'elle tourne, meme horloge reculee. Elle
        l'utilise des que l'horloge de l'ordinateur est en retard de plus de 48 heures sur elle ; un ecart plus faible est
        tolere. Chaque reponse du serveur recale cette heure.</li>
        </ul>
        HTML;
    return $html;
}

function aide_section_depannage(): string
{
    $html = <<<'HTML'
        <h3>Questions frequentes</h3>
        <details>
        <summary>Un client me demande sa cle, ou l'a perdue.</summary>
        <p>Licences, rechercher le client, ouvrir sa licence, "Afficher la cle", puis la lui dicter ou la copier dans un
        e-mail (voir <a href="#t-cle">Retrouver une cle et la dicter</a>). Si la page affiche "Cle complete non disponible
        (licence creee avant cette fonction)" : si son ordinateur fonctionne, le client la lit lui-meme (Ctrl+Maj+L, puis
        "Afficher la cle") ; sinon, "Nouvelle cle" en cree une autre, a lui transmettre (l'ancienne cesse de
        fonctionner).</p>
        </details>
        <details>
        <summary>Une licence revoquee peut-elle etre reactivee ?</summary>
        <p>Non. La revocation est definitive : "Reactiver" ne s'applique qu'a une licence suspendue, la page d'une licence
        revoquee n'a plus aucune action, et la meme cle ressaisie est toujours refusee ("Licence revoquee"). La licence reste
        visible (filtre "revoquees", journal, exports). Pour servir de nouveau ce client, deux voies :</p>
        <ul>
        <li>sur la page de la licence revoquee, "Nouvelle licence pour ce client" : formulaire pre-rempli, "Creer la cle",
        puis transmettre la nouvelle cle ; le client la saisit par "J'ai une cle" dans la fenetre "Licence requise" ;</li>
        <li>ou laisser le client envoyer une nouvelle demande ("Demander une licence") et l'accepter. Pas de nouvel essai si
        cet ordinateur en a deja eu un pour ce produit : l'application reste sur "Demande en attente" jusqu'a votre
        decision.</li>
        </ul>
        <p>Pour une coupure temporaire, utiliser "Suspendre", avec ou sans date de fin : l'ordinateur garde sa cle et se
        debloque seul a la reactivation ou a la date de fin, sans rien ressaisir. Pour une licence datee, "Fixer la date" a
        une echeance proche convient aussi.</p>
        </details>
        <details>
        <summary>Un client change d'ordinateur : que faire ?</summary>
        <p>Si l'ancien ordinateur fonctionne et a Internet : rien dans la console. Le client ouvre la fenetre Licence
        (Ctrl+Maj+L), clique "Transferer cette licence", note la cle affichee, puis la saisit sur le nouvel ordinateur ("J'ai
        une cle"). Sinon : page de la licence, "Changer d'ordinateur", "Liberer cette cle", puis donner la cle au client
        ("Afficher la cle"). Detail : <a href="#t-transfert">Transferer une licence</a>. Licence suspendue : la cle est
        refusee sur le nouvel ordinateur jusqu'a la reactivation ou la date de fin.</p>
        </details>
        <details>
        <summary>Le transfert depuis l'application est refuse.</summary>
        <p>"Transfert impossible : serveur de licences injoignable..." : l'ordinateur doit etre connecte a Internet ; sinon,
        "Changer d'ordinateur" dans la console. "Transfert refuse : trop de transferts recents pour cette licence..." : deja
        {{TRANSFERTS_MAX}} transferts depuis l'application en {{TRANSFERTS_JOURS}} jours (journal : transfert_refuse) ;
        verifier que c'est legitime, puis "Changer d'ordinateur" dans la console. "Transfert refuse : la licence est liee a un
        autre ordinateur." : la cle a deja ete activee ailleurs. "Transfert refuse : licence revoquee." : rien a
        transferer. Dans tous ces cas, rien ne change sur l'ordinateur.</p>
        </details>
        <details>
        <summary>Un client veut l'application sur deux ordinateurs.</summary>
        <p>Une cle = un ordinateur : il faut deux licences. Creer une seconde licence ("Nouvelle licence") et la lui
        transmettre, ou laisser le second ordinateur envoyer sa propre demande. Pour simplement changer d'ordinateur, voir la
        question precedente.</p>
        </details>
        <details>
        <summary>Un produit que je ne connais pas est apparu dans Produits.</summary>
        <p>Une application a envoye une demande avec un code inconnu du serveur : le produit a ete cree automatiquement
        (etiquette "cree automatiquement" ; journal : produit_cree_automatiquement ; l'e-mail de la demande disait "NOUVEAU
        PRODUIT"). Si c'est votre application, verifier ses regles et cliquer "Enregistrer". Si le code est une erreur (faute
        de frappe dans la ligne installer(...), version de test), refuser la demande et decocher "Actif" dans ses reglages
        avances : un produit ne se supprime pas. Les codes distinguent majuscules et minuscules : "monappli" n'est pas
        "MONAPPLI". Un code qui ne differe d'un produit existant que par les majuscules n'est jamais cree : l'application
        recoit "Produit inconnu ou inactif sur le serveur de licences".</p>
        </details>
        <details>
        <summary>J'ai change les regles d'un produit : qu'est-ce qui change pour les licences existantes ?</summary>
        <p>Options, tolerance hors ligne, preavis, message d'accueil et etat Actif s'appliquent a chaque application a son
        controle suivant, sauf pour une licence qui a ses propres options ou sa propre tolerance (Reglages avances de la
        licence). La duree par defaut et l'essai ne valent que pour les licences et les demandes a venir.</p>
        </details>
        <details>
        <summary>Le client a renomme son ordinateur : faut-il faire quelque chose ?</summary>
        <p>Non. Le nom de l'ordinateur n'est qu'un affichage, mis a jour dans la console a son controle suivant ;
        l'identifiant du poste et la licence ne changent pas.</p>
        </details>
        <details>
        <summary>Le client voit "Cle deja utilisee sur un autre ordinateur".</summary>
        <p>La cle est liee a un autre ordinateur : soit elle est saisie sur un second ordinateur (une cle = un ordinateur),
        soit le meme ordinateur a change d'empreinte (Windows reinstalle, ou volume systeme C: reformate). La tentative est
        visible dans l'historique de la licence (refus_autre_poste, avec le nom de l'ordinateur). Si c'est legitime :
        "Changer d'ordinateur", puis faire ressaisir la cle. Sinon, chaque ordinateur a besoin de sa propre licence.</p>
        </details>
        <details>
        <summary>Le client voit "Cle invalide".</summary>
        <p>"Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)" : faute de frappe detectee par la somme de
        controle, sans contacter le serveur. "Cle invalide" seul : le serveur ne connait pas cette cle pour cette
        application : cle d'un autre produit, cle remplacee par "Nouvelle cle", cle jamais creee, base restauree d'avant sa
        creation, ou deux caracteres inverses a la saisie (faire relire la cle : "Afficher la cle" sur la licence). Verifier
        que le produit de la licence est bien celui de l'application (code affiche dans sa fenetre Licence). Licence hors
        ligne qui se connecte : son code a ete remplace par "Nouveau code hors ligne pour ce poste" ou "Nouveau code pour un
        autre ordinateur" (transmettre le code actuel), ou le serveur ne le connait pas (code fabrique, base restauree
        d'avant sa creation) ; le code est efface de l'ordinateur.</p>
        </details>
        <details>
        <summary>Le client voit "Produit inconnu ou inactif sur le serveur de licences".</summary>
        <p>Le produit est desactive (le reactiver : case "Actif" de ses reglages avances), ou l'application envoie un code
        que le serveur ne connait pas. Une activation de cle ne cree jamais de produit : comparer le code affiche dans la
        fenetre Licence de l'application (sous le titre) avec ceux de l'ecran Produits, majuscules comprises. La cle de
        l'ordinateur est conservee : il se debloque des que le produit est actif.</p>
        </details>
        <details>
        <summary>Le client voit "Serveur de licences injoignable : verifiez la connexion Internet".</summary>
        <p>Verifier dans l'ordre : la connexion Internet de l'ordinateur (pare-feu, proxy d'entreprise) ; que l'URL de l'API
        repond en https (certificat SSL du sous-domaine actif chez OVH) ; que l'URL diffusee ne redirige pas ; que l'API ne
        repond pas 503 (voir plus bas). Demander la ligne de diagnostic (Ctrl+Maj+L, "Copier le diagnostic") : elle donne la
        cause pour chaque URL essayee. Tant que la tolerance hors ligne n'est pas epuisee, l'application continue de
        fonctionner.</p>
        </details>
        <details>
        <summary>L'horloge de l'ordinateur est decalee.</summary>
        <p>Au-dela de {{ECART_MIN}} minutes d'ecart avec le serveur, tout est refuse, meme connecte, et l'ordinateur reessaie
        toutes les 15 minutes. Rien ne s'affiche de soi-meme tant que la licence reste utilisable : le message "Horloge de
        l'ordinateur incorrecte : corrigez la date et l'heure" se lit dans le diagnostic, apres "Verifier maintenant" ou
        "Reessayer", et a la saisie d'une cle ou a l'envoi d'une demande. Faire corriger la date, l'heure et le fuseau de
        Windows (reglage automatique), puis cliquer "Verifier maintenant".</p>
        </details>
        <details>
        <summary>Le client voit "Trop de tentatives : reessayez plus tard".</summary>
        <p>Une limite par adresse IP est depassee : {{LIMITES}}. Les ordinateurs d'un meme bureau derriere la meme box
        partagent ces limites, et des essais repetes de cle erronee consomment aussi le quota. Attendre l'heure suivante (ou
        le lendemain, a partir de minuit UTC, pour les demandes) ; l'ordinateur reessaie seul 15 minutes plus tard. Pour
        equiper plusieurs ordinateurs d'un meme site, preferer des cles creees dans la console.</p>
        </details>
        <details>
        <summary>Apres une reinstallation, le client voit "Une demande est deja en attente pour ce poste".</summary>
        <p>Sa premiere demande attend toujours dans l'ecran Demandes, mais l'application ne peut plus la suivre (ses
        fichiers de licence ont ete perdus). La traiter : l'accepter, puis transmettre au client la cle affichee (elle ne lui
        parviendra pas automatiquement), a saisir par "J'ai une cle" ; ou la refuser, et le client peut alors envoyer une
        nouvelle demande (sans nouvel essai).</p>
        </details>
        <details>
        <summary>Une demande acceptee n'arrive pas sur l'ordinateur.</summary>
        <p>Sans essai, l'application verifie chaque minute et sa fenetre se ferme seule. Pendant un essai, elle ne verifie
        que toutes les 6 heures : faire relancer l'application ou cliquer "Verifier maintenant" (Ctrl+Maj+L). Si rien
        n'arrive, verifier la connexion de l'ordinateur (diagnostic). Si la licence creee a ete suspendue entre-temps, la cle
        n'arrive qu'apres la reactivation ; si elle a ete revoquee, liberee ou a recu une nouvelle cle, l'ordinateur revient
        a "Licence requise" : lui transmettre une cle a saisir par "J'ai une cle".</p>
        </details>
        <details>
        <summary>Une licence a expire : comment debloquer le client ?</summary>
        <p>"Prolonger" ("+30 jours", "+1 an" ou "Fixer la date"). L'ordinateur a garde sa cle : faire cliquer "Reessayer"
        dans la fenetre "Licence a verifier", ou relancer l'application. Sans cela, le controle automatique n'a lieu que 6
        heures apres le dernier refus.</p>
        </details>
        <details>
        <summary>Comment couper temporairement l'acces d'un client ?</summary>
        <p>"Suspendre", en remplissant au besoin "Jusqu'au (facultatif)" : l'ordinateur est bloque a son controle suivant et
        garde sa cle. Avec une date (le dernier jour suspendu), la licence redevient active d'elle-meme a la fin de ce jour
        (23:59:59, fuseau {{FUSEAU}}), y compris une licence perpetuelle. Sans date, elle reste suspendue jusqu'a
        "Reactiver". Voir <a href="#t-suspendre">Suspendre</a>. Ne jamais revoquer pour une coupure temporaire : c'est
        definitif.</p>
        </details>
        <details>
        <summary>La date de fin de la suspension est passee mais le client est toujours bloque.</summary>
        <p>La suspension couvre toute la journee indiquee : la licence redevient active a la fin de ce jour. L'ordinateur doit
        ensuite joindre le serveur : faire cliquer "Reessayer" ou relancer l'application, avec Internet ; sinon, le controle
        automatique n'a lieu que 6 heures apres le dernier refus. Verifier sur la page de la licence qu'elle est "Active" ; si
        elle est "Expiree" (echeance passee pendant la suspension), la prolonger ; si elle est encore "Suspendue", lire la date
        affichee a cote ("(sans date de fin)" : seul "Reactiver" la leve).</p>
        </details>
        <details>
        <summary>Le client travaille longtemps sans Internet.</summary>
        <p>Augmenter la tolerance hors ligne de sa licence (Reglages avances de la licence) ou du produit, jusqu'a 365 jours.
        Le changement n'atteint l'ordinateur qu'a son controle reussi suivant : le faire connecter une fois. Pendant cette
        tolerance, une suspension ou une revocation ne l'atteint pas non plus. S'il n'a jamais Internet, une licence hors
        ligne convient mieux (question suivante).</p>
        </details>
        <details>
        <summary>Un ordinateur n'a jamais Internet : comment lui donner une licence ?</summary>
        <p>Une licence hors ligne (voir <a href="#t-hors-ligne">Poste toujours hors ligne</a>) : se faire dicter
        l'identifiant du poste affiche par l'application (en bas de la fenetre "Licence requise"), puis Licences, "Licence
        hors ligne", "Creer les codes". Dicter le code court par telephone, ou envoyer le code long par e-mail ; l'utilisateur
        le saisit par "J'ai une cle", sans Internet. Une cle ordinaire ne peut pas s'activer sans Internet.</p>
        </details>
        <details>
        <summary>Code court ou code long : lequel transmettre ?</summary>
        <p>Ils donnent la meme licence : un seul suffit. Par telephone, le code court (16 caracteres apres ETDHL). Par e-mail,
        le code long, a copier-coller (109 caracteres apres ETDHL2) ; il ne peut pas etre fabrique, contrairement au code
        court. Application qui refuse le code court ("Codes hors ligne courts non pris en charge par cette
        application...") : elle a ete construite sans LICENCE_SECRET_HORS_LIGNE, envoyer le code long. Application ancienne
        jamais connectee apres une rotation de la cle de signature : le code long recent est refuse, dicter le code
        court.</p>
        </details>
        <details>
        <summary>L'application refuse le code hors ligne.</summary>
        <p>"Code hors ligne refuse : il ne correspond pas a cet ordinateur (identifiant du poste XXXX-XXXX) ou contient une
        erreur de saisie." : comparer l'identifiant cite avec la ligne "Poste" de la page de la licence. Identique : faire
        relire le code (pour le code long, le recoller en entier) ; un code long correct reste refuse par une application qui
        ne connait pas la cle de signature qui l'a signe (application construite avant une rotation et jamais connectee
        depuis) : dicter le code court. Different : si le code est saisi dans une autre application que celle de la
        licence (code du produit dans le titre de la fenetre, "Licence - PRODUIT"), le faire saisir dans la bonne ; sinon
        l'identifiant a ete mal dicte, ou le code est saisi sur un autre ordinateur : page de la licence, "Nouveau code
        pour un autre ordinateur" avec l'identifiant cite, puis transmettre les nouveaux codes. "Code hors ligne expire le JJ/MM/AAAA" :
        le code est deja perime (cree trop tot, duree trop courte, ou horloge de l'ordinateur en avance) ; "Nouveau code hors
        ligne pour ce poste". "Code hors ligne revoque, remplace ou transfere : demandez un nouveau code a ETDEL" : ce code
        a ete retire de cet ordinateur lors d'une connexion au serveur. Code remplace : transmettre le code actuel
        ("Afficher les codes") ; licence revoquee : "Nouvelle licence hors ligne pour ce poste" ; apres un transfert :
        "Nouveau code hors ligne pour ce poste". "Codes hors ligne courts non pris en charge..." : envoyer le code long.</p>
        </details>
        <details>
        <summary>Comment prolonger une licence hors ligne ?</summary>
        <p>Si l'ordinateur ne se connecte jamais : page de la licence, "Nouveau code hors ligne pour ce poste", nouvelle duree
        comptee a partir d'aujourd'hui, "Creer le nouveau code", puis transmettre l'un des deux codes ; l'utilisateur le
        saisit par "J'ai une cle" dans la fenetre "Licence hors ligne expiree" (l'application ne propose pas de saisir un
        code tant que la licence est valable). Si l'ordinateur peut se connecter, meme ponctuellement : "Prolonger" suffit ;
        la nouvelle echeance l'atteint a son controle suivant ("Reessayer" pour tout de suite).</p>
        </details>
        <details>
        <summary>Peut-on suspendre ou revoquer une licence hors ligne ?</summary>
        <p>Oui dans la console, mais l'ordinateur ne l'apprend que s'il se connecte un jour. Un ordinateur qui ne se connecte
        jamais garde son code jusqu'a son echeance (ou toujours, si elle est illimitee) : c'est la limite assumee des
        licences hors ligne. D'ou l'interet de durees raisonnables, renouvelees par de nouveaux codes : il suffit alors de ne
        plus en donner. S'il se connecte : suspension, revocation, remplacement du code et echeance avancee s'appliquent
        comme pour une cle.</p>
        </details>
        <details>
        <summary>Licence hors ligne : le client a reinstalle l'application, Windows, ou change d'ordinateur.</summary>
        <p>Application reinstallee ou fichiers de licence perdus, sur le meme Windows : l'identifiant du poste ne change pas ;
        si l'application redemande une licence, lui redonner le meme code ("Afficher les codes" sur la page de la licence),
        qu'il saisit par "J'ai une cle". Windows reinstalle, disque systeme
        reformate ou autre ordinateur : l'identifiant change ; se faire dicter le nouveau, puis "Nouveau code pour un autre
        ordinateur" sur la page de la licence. Si l'ancien ordinateur fonctionne et s'est deja connecte une fois, il peut
        d'abord "Transferer cette licence" depuis l'application ; la page de la licence propose ensuite "Nouveau code pour
        un autre ordinateur" en premier.</p>
        </details>
        <details>
        <summary>La page d'une licence hors ligne affiche "Code long non reaffichable".</summary>
        <p>La cle de signature a change (rotation) depuis la creation des codes : la console ne peut plus recalculer le code
        long d'origine. Il reste valable sur les ordinateurs qui l'ont deja saisi, et se saisit encore dans les applications
        qui ne connaissent que l'ancienne cle (voir <a href="#hl-limites">Limites</a>) ; le code court s'affiche
        toujours. Pour un nouveau code long : "Nouveau code hors ligne pour ce poste" (le code long obtenu ne fonctionne que
        dans les applications qui connaissent la nouvelle cle publique ; sinon, transmettre le code court).</p>
        </details>
        <details>
        <summary>La console affiche "Secret des codes hors ligne absent" ou "different".</summary>
        <p>Le fichier prive/cles/secret_hors_ligne.key a ete perdu ou remplace (changement d'hebergement, restauration sans
        prive/cles/) apres sa creation : la base en garde l'empreinte depuis l'installation (ou depuis sa creation pour un
        serveur plus ancien), meme si aucun code hors ligne n'a encore ete cree. Tant que c'est le cas, la console refuse de creer une
        licence ou un code hors ligne, et l'ecran Cles de signature ne propose pas la ligne LICENCE_SECRET_HORS_LIGNE. Le
        remettre par FTP depuis la copie de secours hors ligne (voir <a href="#sauvegarde">Sauvegarde et restauration</a>).
        Ne jamais en fabriquer un autre : les applications deja livrees embarquent celui-ci. Les licences et codes existants
        continuent de fonctionner.</p>
        </details>
        <details>
        <summary>Passer une licence de perpetuelle a datee, ou l'inverse.</summary>
        <p>Impossible sur la meme licence.</p>
        <ol>
        <li>Creer une nouvelle licence avec la bonne duree et transmettre sa cle au client.</li>
        <li>Revoquer l'ancienne licence.</li>
        <li>Faire cliquer "Verifier maintenant" (Ctrl+Maj+L) ou relancer l'application : message "Licence revoquee", puis
        fermeture apres OK.</li>
        <li>Relancer l'application : fenetre "Licence requise", "J'ai une cle", saisir la nouvelle cle, "Activer".</li>
        </ol>
        <p>L'application ne permet pas de saisir une cle tant qu'une licence fonctionne : convenir du moment avec le
        client.</p>
        </details>
        <details>
        <summary>Je ne recois pas les e-mails de nouvelle demande.</summary>
        <p>Administration, Reglages : verifier les adresses, "Enregistrer", puis "Envoyer un e-mail de test". Journal :
        rechercher les actions email_echec (envoi refuse par l'hebergement) et email_plafond (plafond de {{EMAILS_JOUR}}
        e-mails par jour atteint). Regarder le dossier des indesirables et utiliser une adresse expeditrice sur le domaine du
        serveur. Sans adresse de notification valide, rien n'est envoye ni inscrit au journal. Les demandes restent toujours
        visibles dans l'ecran Demandes (pastille du menu) et sur l'Accueil.</p>
        </details>
        <details>
        <summary>La console affiche "Formulaire expire ou invalide : rechargez la page puis recommencez."</summary>
        <p>Le jeton du formulaire a change : une autre ecriture a eu lieu depuis l'affichage de la page (autre onglet), la
        page a ete renvoyee (touche F5, retour arriere apres une action), ou le navigateur a ete ferme. Recharger la page sans
        renvoyer le formulaire, puis recommencer. Apres une erreur ("Action impossible"), le lien "Retour" permet en revanche
        de corriger la saisie et de renvoyer le formulaire.</p>
        </details>
        <details>
        <summary>Une ligne affiche DANGER ou "non verifie" dans le controle d'exposition.</summary>
        <p>DANGER : le fichier est servi par le web. Verifier par FTP que licence/.htaccess et licence/prive/.htaccess sont
        bien presents (fichiers caches, souvent oublies a l'envoi), ou deplacer tout le dossier prive/ d'un bloc a cote de
        licence/. Si le fichier a pu etre telecharge, considerer les secrets comme divulgues : changer les mots de passe OVH,
        FTP et de la console, faire une rotation de cle de signature (voir <a href="#cles">Cles de signature</a>) et, si le
        secret des cles ou la base ont pu etre lus, remplacer les cles des licences ("Nouvelle cle").</p>
        <p>"non verifie" : le navigateur n'a pas obtenu de reponse probante. Ouvrir l'URL indiquee dans une fenetre privee :
        elle doit etre refusee (erreur 403 ou 404).</p>
        </details>
        <details>
        <summary>La console affiche une erreur 403, "Acces refuse" ou "Console indisponible".</summary>
        <p>Page d'erreur 403 du serveur web ("Forbidden") juste apres une mise a jour par FTP : admin/.htaccess a ete ecrase
        par celui du depot, qui ferme la console. Page "Acces refuse" avec "Authentification requise (.htaccess /
        .htpasswd)." : admin/.htaccess est absent ou sans effet. Dans les deux cas, renvoyer la copie de secours faite a
        l'installation.</p>
        <p>"Console indisponible : dossier prive introuvable." : le dossier prive/ (avec prive/lib/) n'est ni dans licence/
        ni a cote ; le renvoyer par FTP. "Console indisponible" avec "Base ou configuration absente : lancer install.php." :
        prive/config.php ou prive/data/licenses.db manque ou est illisible ; verifier par FTP.</p>
        </details>
        <details>
        <summary>L'API repond 503 (diagnostic "http 503").</summary>
        <p>Base absente, cle privee de signature ou secret des cles illisible, ou dossier prive/ introuvable. Verifier par FTP
        prive/data/ et prive/cles/ (droits d'ecriture du compte) et les journaux d'erreurs PHP de l'espace client OVH. Apres
        une restauration ou une reconstruction, prive/cles/ doit contenir la cle privee active et toutes les fiches
        signature_N.json.</p>
        </details>
        <details>
        <summary>La console affiche "Action impossible" avec "secret des demandes illisible".</summary>
        <p>Le fichier prive/cles/secret_demandes.key, qui chiffre les cles conservees, manque ou est abime (oublie lors d'un
        changement d'hebergement ou d'une reconstruction). Tant qu'il manque, la console ne peut ni creer une cle (Nouvelle
        licence, Accepter une demande, Nouvelle cle) ni en afficher une, et les cles des demandes acceptees pas encore
        remises n'arrivent plus aux ordinateurs (l'API repond 503). Le remettre par FTP depuis la copie de secours hors
        ligne (voir <a href="#sauvegarde">Sauvegarde et restauration</a>). Sans copie, demander au developpeur d'en creer un
        nouveau : les cles deja conservees deviennent alors illisibles ("Cle complete illisible") et se remplacent au
        besoin par "Nouvelle cle".</p>
        </details>
        <details>
        <summary>J'ai perdu le mot de passe de la console.</summary>
        <p>Voir <a href="#reglages">Mot de passe perdu</a> : un jeton a inscrire dans config.php par FTP, puis
        install.php.</p>
        </details>
        <h3 id="codes">Codes de refus</h3>
        <p>Codes renvoyes par le serveur aux applications (ils apparaissent dans le diagnostic et dans le journal de
        l'application).</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Code | Situation | Ce que voit l'utilisateur | Que faire
        cle_invalide | cle mal formee, inconnue du serveur, d'un autre produit, ou remplacee par "Nouvelle cle" ; code hors ligne remplace par un nouveau code, ou inconnu du serveur | "Cle invalide" ; si la cle (ou le code) etait deja enregistree, elle est effacee et l'application se ferme ; un code hors ligne efface ne se ressaisit plus sur cet ordinateur. Pour un transfert : "Transfert refuse : cle inconnue du serveur de licences." | verifier le produit de la licence ; transmettre la bonne cle ("Afficher la cle") ou le code actuel ("Afficher les codes")
        revoquee | licence revoquee | "Licence revoquee" ; cle ou code hors ligne efface (un code hors ligne ressaisi est ensuite refuse : "Code hors ligne revoque, remplace ou transfere : demandez un nouveau code a ETDEL") | "Nouvelle licence pour ce client", "Nouvelle licence hors ligne pour ce poste" ou nouvelle demande (la revocation est definitive)
        suspendue | licence suspendue, avec ou sans date de fin (la date est transmise a l'application) | au lancement : fenetre "Licence suspendue" avec "Licence suspendue jusqu'au JJ/MM/AAAA. Elle sera reactivee automatiquement a cette date." ou "Licence suspendue. Contactez ETDEL pour la reactiver." ; en cours d'utilisation, ce message puis fermeture ; cle conservee, ordinateur bloque meme hors ligne. A la saisie par "J'ai une cle" : "Licence suspendue", la cle n'est pas enregistree | "Reactiver", ou attendre la date de fin ; l'ordinateur se debloque seul a son controle suivant ("Reessayer" pour tout de suite), sans ressaisie ; licence hors ligne : a la date de fin recue, meme sans connexion
        poste_revoque | la cle a ete liberee ("Changer d'ordinateur" dans la console) : elle n'est plus liee a cet ordinateur | "Ce poste n'est plus autorise pour cette licence" ; cle effacee. Pour un transfert depuis l'application, ce code vaut succes (cle deja liberee) | normal apres un changement d'ordinateur ; sinon faire ressaisir la cle
        cle_liee_autre_poste | cle deja liee a un autre ordinateur, ou empreinte de l'ordinateur changee ; code hors ligne presente par un autre ordinateur que le poste saisi dans la console | "Cle deja utilisee sur un autre ordinateur" ; cle effacee. Pour un transfert : "Transfert refuse : la licence est liee a un autre ordinateur." | "Changer d'ordinateur" si c'est legitime (licence hors ligne : "Nouveau code pour un autre ordinateur"), sinon une autre licence
        expiree | echeance atteinte | "Licence expiree. Contactez ETDEL pour la renouveler." (licence hors ligne : "Licence hors ligne expiree : demandez un nouveau code a ETDEL") ; cle conservee | "Prolonger", puis "Reessayer" ; ou, pour un ordinateur qui ne se connecte plus, "Nouveau code hors ligne pour ce poste"
        produit_inconnu | code produit inconnu du serveur (seule une demande cree un produit), produit inactif, ou code qui ne differe d'un produit existant que par les majuscules | "Produit inconnu ou inactif sur le serveur de licences" ; cle conservee ; ni demande ni activation possible | cocher "Actif" sur le produit ; verifier le code de la ligne installer(...) de l'application
        demande_en_cours | une demande de cet ordinateur est deja en attente pour ce produit | "Une demande est deja en attente pour ce poste" | traiter la demande en attente
        demande_inconnue | demande introuvable (base restauree), cle deja remise, ou remplacee par "Nouvelle cle" avant sa remise | l'application oublie sa demande et revient a "Licence requise" | nouvelle demande, ou cle transmise
        transferts_trop_nombreux | deja {{TRANSFERTS_MAX}} transferts depuis l'application pour cette licence dans les {{TRANSFERTS_JOURS}} derniers jours | "Transfert refuse : trop de transferts recents pour cette licence. Contactez ETDEL, qui peut la liberer pour vous." ; rien ne change | "Changer d'ordinateur" dans la console, si c'est legitime
        horloge | heure de l'ordinateur decalee de plus de {{ECART_MIN}} minutes | "Horloge de l'ordinateur incorrecte : corrigez la date et l'heure" ; nouvel essai 15 minutes plus tard | faire corriger l'heure de l'ordinateur
        trop_de_requetes | limite par adresse IP depassee | "Trop de tentatives : reessayez plus tard" ; nouvel essai 15 minutes plus tard | attendre
        requete_invalide | requete mal formee (HTTP 400) | comme "serveur injoignable" (diagnostic "http 400") ; nouvel essai 15 minutes plus tard | verifier, dans la ligne installer(...), le code produit (lettres, chiffres, . _ - ; 64 caracteres) et la version de l'application (32 caracteres au plus), puis la version du module etdel_licence.py
        indisponible, non_installe | HTTP 503 : base, cle privee ou secret illisible ; dossier prive/ introuvable | "Serveur de licences injoignable" (diagnostic "http 503") | voir "L'API repond 503" ci-dessus
        methode | HTTP 405 : URL de l'API ouverte dans un navigateur | rien (l'API ne s'ouvre pas dans un navigateur) | aucune action
        T);
    return $html;
}

function aide_section_glossaire(): string
{
    return fiche_html([
        'Produit' => 'Une application, reconnue par le code qu\'elle envoie au serveur (ligne installer(...)), avec ses '
            . 'regles, ses licences et ses demandes. Cree automatiquement par la premiere demande de l\'application, ou a '
            . 'la main dans Produits.',
        'Regles' => 'Reglages d\'un produit : duree par defaut, essai, options, tolerance hors ligne, preavis, message '
            . 'd\'accueil, actif.',
        'Licence' => 'Droit d\'utiliser un produit sur un seul ordinateur, avec un client, une echeance (ou perpetuelle), '
            . 'un statut et une cle (ou, pour une licence hors ligne, un code hors ligne).',
        'Cle' => 'ETDEL-XXXX-XXXX-XXXX-XXXX : 16 caracteres (jamais I, L, O ni U) dont une somme de controle ; se lie au '
            . 'premier ordinateur qui l\'active. Le serveur en garde le hachage, les 4 derniers caracteres et une copie '
            . 'chiffree (Afficher la cle).',
        'Client (titulaire)' => 'Personne ou entreprise a qui appartient la licence ; son nom est affiche dans la fenetre '
            . 'Licence de l\'application ("Titulaire").',
        'Ordinateur (poste)' => 'Ordinateur sur lequel une cle est activee, reconnu par son empreinte.',
        'Empreinte' => 'Valeur calculee par l\'application a partir de l\'identifiant de Windows (MachineGuid) et du '
            . 'numero de serie du volume systeme (C:), attribue quand ce volume est formate. Elle change si Windows est '
            . 'reinstalle ou si le volume systeme est reformate ; un disque copie a l\'identique (clonage) la garde en '
            . 'general ; le nom de l\'ordinateur n\'y entre pas.',
        'Identifiant de poste' => 'XXXX-XXXX, tire de l\'empreinte et du code du produit, facile a dicter (jamais de I, L, '
            . 'O ni U). Different pour chaque produit sur un meme ordinateur. Les codes hors ligne sont calcules pour lui.',
        'Licence hors ligne' => 'Licence d\'un ordinateur jamais (ou rarement) connecte a Internet ("Licence hors ligne" '
            . 'dans Licences) : deux codes equivalents, verifies par l\'application sans Internet, qui ne valent que pour un '
            . 'identifiant de poste et un produit. Ni tolerance ni bandeau ; ce que change la console ne l\'atteint que si '
            . 'l\'ordinateur se connecte un jour.',
        'Code hors ligne court' => 'ETDHL-XXXX-XXXX-XXXX-XXXX, a dicter par telephone : il contient la fin de la licence et '
            . 'ses options, et se verifie avec le secret LICENCE_SECRET_HORS_LIGNE de l\'application. Quelqu\'un qui '
            . 'decompile une application peut en fabriquer (protection dissuasive).',
        'Code hors ligne long' => 'ETDHL2- suivi de 109 caracteres, a envoyer par e-mail : memes informations que le code '
            . 'court, signees par la cle de signature du serveur ; impossible a fabriquer. Apres une rotation, les nouveaux '
            . 'codes longs ne valent que pour les applications qui connaissent la nouvelle cle publique.',
        'LICENCE_SECRET_HORS_LIGNE' => 'Quatrieme ligne de l\'ecran Cles de signature, a reporter dans etdel_licence.py : '
            . 'le secret qui verifie les codes hors ligne courts (fichier prive/cles/secret_hors_ligne.key du serveur). Il '
            . 'ne change jamais.',
        'Premier contact' => 'Premier controle reussi d\'un ordinateur equipe d\'une licence hors ligne : la licence se lie '
            . 'a lui s\'il est bien le poste saisi dans la console, et il recoit ensuite echeance, options, suspension et '
            . 'revocation comme une licence ordinaire.',
        'Nouveau code hors ligne' => 'Nouveaux codes (court et long) d\'une licence hors ligne, avec une nouvelle duree : '
            . 'ils remplacent les anciens sur la meme licence ; c\'est ainsi qu\'on prolonge un ordinateur jamais '
            . 'connecte.',
        'Demande' => 'Demande de licence envoyee depuis l\'application ("Demander une licence"), a accepter ou refuser '
            . 'dans Demandes.',
        'Essai' => 'Utilisation accordee pendant l\'attente d\'une demande, une seule fois par ordinateur et par produit.',
        'Option' => 'Code (minuscules, chiffres, _) qui active une fonction de l\'application ; une option absente vaut '
            . '"non". Une licence suit les options de son produit, sauf surcharge. Le joker * active toutes les options, '
            . 'presentes et futures.',
        'Surcharge' => 'Tolerance ou options propres a une licence, qui ne suivent plus celles du produit.',
        'Tolerance (hors ligne)' => 'Duree d\'utilisation sans controle reussi aupres du serveur. Une licence hors ligne '
            . 'n\'en a pas : elle vaut jusqu\'a son echeance.',
        'Preavis' => 'Nombre de jours avant la fin de la tolerance hors ligne ou l\'application affiche un bandeau.',
        'Echeance' => 'Date de fin d\'une licence ; aucune pour une licence perpetuelle.',
        'Controle' => 'Echange de l\'application avec le serveur : 3 secondes apres le lancement, puis toutes les 6 heures.',
        'Suspension' => 'Blocage temporaire d\'une licence ("Suspendre") : l\'ordinateur garde sa cle et se debloque seul, '
            . 'a son controle suivant, quand la licence est reactivee ("Reactiver") ou a la date de fin choisie '
            . '("Jusqu\'au").',
        'Revocation' => 'Arret definitif d\'une licence ("Revoquer") : l\'ordinateur efface sa cle et la meme cle est '
            . 'refusee pour toujours ; remise en service par une nouvelle licence ou une nouvelle demande.',
        'Changer d\'ordinateur' => 'Detacher la cle de l\'ordinateur actuel pour la saisir sur un autre : depuis la '
            . 'console ("Liberer cette cle"), ou depuis l\'application ("Transferer cette licence", immediat).',
        'Nouvelle cle' => 'Remplacement de la cle d\'une licence : l\'ancienne ne fonctionne plus, la licence garde son '
            . 'echeance, son statut et son historique.',
        'kid' => 'Numero de la cle de signature du serveur : 1 a l\'installation, puis un de plus a chaque rotation.',
        'Bulletin' => 'Annonce d\'une nouvelle cle de signature, signee par la precedente et diffusee pendant 12 mois.',
        'Jeton' => 'Reponse signee du serveur que l\'application conserve (echeance, tolerance, options). Dans la console, '
            . 'le jeton de formulaire protege les actions ; dans config.php, les jetons d\'installation et de '
            . 'reinitialisation ouvrent install.php.',
    ]);
}
