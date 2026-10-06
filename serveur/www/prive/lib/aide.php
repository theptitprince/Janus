<?php
// aide.php - Ecran Aide de la console : mode d'emploi ecran par ecran, etats d'une licence,
//            ce que voit l'utilisateur, depannage (FAQ, codes de refus) et glossaire.
// ETDEL (c) 2026

declare(strict_types=1);

// Ecran de la console -> section de l'aide qui le decrit (lien "Aide sur cet ecran").
const AIDE_ANCRES = [
    'tableau' => 'tableau',
    'demandes' => 'demandes',
    'demande' => 'demandes',
    'licences' => 'licences',
    'licence' => 'licences',
    'licence_nouvelle' => 'licences',
    'produits' => 'produits',
    'produit' => 'produits',
    'distribution' => 'produits',
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
        'demarrage' => 'Premiers pas',
        'tableau' => 'Tableau de bord',
        'demandes' => 'Demandes',
        'licences' => 'Licences',
        'etats' => 'Suspendre, revoquer, liberer, laisser expirer',
        'produits' => 'Produits et distributions',
        'application' => 'Ce que voit l\'utilisateur dans l\'application',
        'tolerance' => 'Echeance, tolerance hors ligne et controles',
        'serveurs' => 'Serveurs (URL de l\'API)',
        'cles' => 'Cles de signature',
        'journal' => 'Journal et exports',
        'sauvegarde' => 'Sauvegarde et restauration',
        'reglages' => 'Reglages et mot de passe',
        'securite' => 'Securite',
        'depannage' => 'Depannage et questions frequentes',
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
    $html = '<div class="aide"><p>Mode d\'emploi de la console des licences ETDEL : chaque ecran, chaque action, ce que '
        . 'voit l\'utilisateur dans l\'application et que faire en cas de probleme. En haut de chaque ecran de la console, '
        . 'le lien "Aide sur cet ecran" mene directement a la bonne section.</p>'
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
    foreach (ADMIN_ACTIONS as $libelle) {
        $confirmations .= '<li>' . h($libelle) . '</li>';
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
        '{{VERSION_SERVEUR}}' => h(VERSION_SERVEUR),
        '{{VERSION_API}}' => (string)VERSION_API,
        '{{CONFIRMATIONS}}' => '<ul class="aide-colonnes">' . $confirmations . '</ul>',
    ];
}

/** Limites de l'API par adresse IP, en clair. */
function aide_limites(): string
{
    $noms = ['activer' => 'activations de cle', 'valider' => 'controles de licence',
        'suivre_demande' => 'suivis de demande', 'demander' => 'demandes de licence'];
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

function aide_section_demarrage(): string
{
    return <<<'HTML'
        <h3>Vue d'ensemble</h3>
        <ul>
        <li><strong>Produit</strong> : une application, designee par un code definitif (par exemple MONAPPLI).</li>
        <li><strong>Distribution</strong> : une variante livree d'un produit, pour un client ou un canal (MONAPPLI-CLIENTA,
        MONAPPLI-PUBLIC). Elle porte les regles : tolerance hors ligne, preavis, duree par defaut, essai, version minimale,
        options, message d'accueil. Chaque executable livre est construit pour une seule distribution.</li>
        <li><strong>Licence</strong> : une cle ETDEL-XXXX-XXXX-XXXX-XXXX, rattachee a une distribution, avec un titulaire,
        une echeance (ou perpetuelle) et un statut : active, suspendue (blocage temporaire, avec ou sans date de fin, le
        poste garde sa cle) ou revoquee (arret definitif, le poste efface sa cle) ; "expiree" quand l'echeance est
        passee.</li>
        </ul>
        <p><strong>Une cle = un poste.</strong> Une cle se lie au premier ordinateur qui l'active. Saisie sur un autre
        ordinateur, elle est refusee ("Cle deja utilisee sur un autre ordinateur"). Pour changer d'ordinateur, utiliser
        "Liberer le poste" (voir <a href="#etats">Suspendre, revoquer, liberer</a>).</p>
        <h3>Deux facons d'obtenir une cle</h3>
        <ol>
        <li><strong>Cle creee dans la console</strong> (Licences, "Creer une cle") : la cle s'affiche une seule fois ; vous
        la transmettez au client (e-mail, telephone) et il la saisit dans l'application par "J'ai une cle". C'est la seule
        possibilite pour une application en mode console (sans fenetre).</li>
        <li><strong>Demande depuis l'application</strong> : l'utilisateur clique "Demander une licence" ; vous recevez un
        e-mail et la demande apparait dans Demandes. Si vous l'acceptez, la cle est transmise automatiquement a
        l'application, deja liee a ce poste : l'utilisateur ne la voit jamais. En attendant votre decision, l'application
        peut etre utilisable quelques jours (essai, une seule fois par poste et par produit).</li>
        </ol>
        <h3>Parcours type du premier jour</h3>
        <ol>
        <li>Tableau de bord : verifier que chaque ligne du "Controle d'exposition" indique "protege".</li>
        <li>Reglages : saisir les adresses de notification et l'adresse expeditrice, "Enregistrer", puis
        "Envoyer un e-mail de test" et verifier sa reception.</li>
        <li>Produits : "Nouveau produit" (code definitif), puis "Nouvelle distribution" (code definitif et regles).</li>
        <li>Fiche de la distribution : copier la ligne <code>etdel_licence.installer(...)</code> dans l'application.
        Ecran Cles : copier les trois lignes a reporter une fois en tete de <code>etdel_licence.py</code>.</li>
        <li>Sauvegarde : si ce n'est pas deja fait (la page de fin d'installation le demande), creer la tache planifiee OVH
        de sauvegarde quotidienne (voir <a href="#sauvegarde">Sauvegarde et restauration</a>) ; le lendemain, verifier dans
        l'ecran Sauvegarde qu'elle a produit une sauvegarde (tableau non vide). Faire aussi par FTP la copie de secours hors
        ligne de prive/config.php, prive/.htpasswd, prive/install.verrou, prive/cles/ et admin/.htaccess (meme
        section).</li>
        <li>Faire un essai complet : une demande envoyee depuis l'application et acceptee ici ; une cle creee ici et saisie
        dans l'application.</li>
        </ol>
        <h3>Gestes courants</h3>
        <ul>
        <li>Repondre a une demande : <a href="#demandes">Demandes</a>, "Traiter", puis "Accepter" ou "Refuser".</li>
        <li>Donner une licence sans demande : <a href="#licences">Licences</a>, "Creer une cle", puis transmettre la cle au
        client.</li>
        <li>Allonger une licence datee : fiche de la licence, "Prolonger".</li>
        <li>Couper l'acces pour un temps (impaye, litige, conge) : fiche de la licence, "Suspendre", avec si besoin une date
        de fin ; arreter pour toujours : "Revoquer" ; changer d'ordinateur : "Liberer le poste" (voir
        <a href="#etats">Suspendre, revoquer, liberer, laisser expirer</a>).</li>
        <li>Comprendre ce que voit le client : <a href="#application">Ce que voit l'utilisateur dans l'application</a> et
        <a href="#codes">les codes de refus</a>.</li>
        </ul>
        <h3>Principes de la console</h3>
        <ul>
        <li>Toute action qui modifie quelque chose demande une confirmation (par exemple "Revoquer definitivement la
        licence ?") et est inscrite au journal avec votre identifiant ({{UTILISATEUR}}).</li>
        <li>Rien ne se supprime : ni licence, ni demande, ni produit, ni distribution, ni URL. On desactive, on revoque, on
        retire de la diffusion ; tout reste visible dans les listes, le journal et les exports.</li>
        <li>Un changement fait ici atteint un poste a son controle suivant : 3 secondes apres le lancement de l'application,
        puis toutes les 6 heures (voir <a href="#tolerance">Echeance, tolerance hors ligne et controles</a>).</li>
        <li>Les dates sont affichees dans le fuseau {{FUSEAU}} (reglage de config.php).</li>
        <li>La console s'utilise aussi sur telephone : sous 600 pixels de large, le menu passe a la ligne et les fiches
        s'affichent sur une colonne ; les grands tableaux defilent horizontalement.</li>
        </ul>
        HTML;
}

function aide_section_tableau(): string
{
    $html = <<<'HTML'
        <p>Ecran d'accueil de la console (lien "Licences ETDEL" en haut a gauche).</p>
        <h3>Compteurs</h3>
        <ul>
        <li><strong>N demande(s) en attente</strong> : encadre orange s'il y en a au moins une ; un clic ouvre Demandes.
        Le menu Demandes porte la meme pastille.</li>
        <li><strong>Licences actives</strong> : statut actif, echeance future ou licence perpetuelle.</li>
        <li><strong>Licences expirees</strong> : statut actif mais echeance passee. "Expiree" n'est pas un statut enregistre :
        une prolongation suffit a rendre la licence active de nouveau.</li>
        <li><strong>Licences suspendues</strong> et <strong>Licences revoquees</strong> : selon le statut, meme si
        l'echeance est passee.</li>
        <li><strong>Postes vus sur 7 jours</strong> : nombre de licences dont le poste a fait un controle reussi depuis
        7 jours. Ce sont des licences et non des ordinateurs : un ordinateur qui a deux licences compte deux fois. Un poste
        refuse (suspendu, revoque, expire) ne met pas a jour son dernier contact.</li>
        </ul>
        <h3>Licences expirant sous 30 jours</h3>
        <p>Licences actives dont l'echeance tombe dans les 30 prochains jours (les suspendues n'y figurent pas), de la plus
        proche a la plus lointaine, 100 au plus : Titulaire (lien vers la fiche), Distribution, Echeance, Reste (en jours),
        Poste. L'application previent elle-meme l'utilisateur 15 jours avant l'echeance : c'est le moment de prolonger
        (voir <a href="#licences">Licences</a>).</p>
        <h3>Controle d'exposition</h3>
        <p>Le navigateur essaie de telecharger, par leur URL et sans identifiants, les fichiers qui ne doivent jamais
        l'etre : config.php, .htpasswd, cle privee de signature active, secret des demandes, base licenses.db et son
        journal -wal, dossier des sauvegardes, schema.sql et lib/commun.php. Chaque ligne affiche un resultat :</p>
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

function aide_section_demandes(): string
{
    return <<<'HTML'
        <h3>D'ou viennent les demandes</h3>
        <p>Dans l'application, fenetre "Licence requise", bouton "Demander une licence" (ou "Nouvelle demande" apres un
        refus) : "Titulaire" (obligatoire), "E-mail" (facultatif) et "Mot pour ETDEL" (facultatif, 200 caracteres, avec
        compteur). Le formulaire affiche aussi le "Nom de l'ordinateur" (non modifiable) et "Le traitement d'une demande
        peut prendre plusieurs jours.", puis les boutons "Envoyer la demande" et "Retour". Si un essai est accorde, la page
        "Demande envoyee" indique "Demande envoyee le JJ/MM/AAAA. Le traitement peut prendre plusieurs jours." et "En
        attendant la reponse, l'application est utilisable pendant N jour(s)." ; "Continuer" ouvre l'application. Sans
        essai, la fenetre passe directement a "Demande en attente".</p>
        <p>Le nom de l'ordinateur et la version de l'application sont transmis automatiquement. Un poste n'a qu'une demande
        en attente par distribution. Au plus {{LIMITE_DEMANDER}} demandes par adresse IP et par jour. Aucun reglage
        n'interdit les demandes : toute application sans licence propose "Demander une licence" (voir l'essai dans
        <a href="#produits">Produits et distributions</a>).</p>
        <p>Chaque nouvelle demande envoie un e-mail aux adresses des <a href="#reglages">Reglages</a>. Objet :
        "[ETDEL Licences] Nouvelle demande - PRODUIT - ORDINATEUR" ; le corps reprend produit, distribution, ordinateur,
        identifiant de poste, titulaire, e-mail, mot du client, essai, date et IP, avec un lien "Traiter la demande" vers
        sa fiche. Un echec d'envoi n'empeche jamais l'enregistrement de la demande : elle est toujours dans cet ecran.</p>
        <h3>Liste</h3>
        <ul>
        <li><strong>En attente</strong> : toutes les demandes en attente, les plus anciennes d'abord : Date, Produit,
        Distribution, Ordinateur, Identifiant (de poste), Titulaire, E-mail, Message, IP, "Essai jusqu'au" ("aucun" sans
        essai) et le bouton "Traiter".</li>
        <li><strong>Historique</strong> : les {{PAR_PAGE}} dernieres demandes traitees : Demandee le, Traitee le, Statut
        (Acceptee ou Refusee), Distribution, Ordinateur, Titulaire, Demande, "Licence ou motif".</li>
        <li>"Exporter les demandes (CSV)" : toutes les demandes (voir <a href="#journal">Journal et exports</a>).</li>
        </ul>
        <h3>Traiter une demande</h3>
        <p>"Traiter" ouvre la fiche : statut, date, produit, distribution, ordinateur, identifiant de poste, titulaire,
        e-mail, mot du client, version de l'application, fin d'essai et IP. En cas de doute, contacter le client :
        l'identifiant de poste permet de reconnaitre son ordinateur (il le lit dans sa fenetre Licence, Ctrl+Maj+L).</p>
        <p><strong>Accepter</strong> (section "Accepter") :</p>
        <ul>
        <li>"Duree en jours (vide = perpetuelle)" : preremplie avec la duree par defaut de la distribution, de 1 a 36500.
        L'echeance court a partir de l'acceptation.</li>
        <li>"Titulaire" : prerempli avec le nom saisi par le demandeur, modifiable (120 caracteres).</li>
        <li>"Options (codes separes par des virgules, * = toutes)" : preremplies avec celles de la distribution.
        Laissees telles quelles, la licence suivra les options de la distribution. Toute autre liste (meme les memes codes
        dans un autre ordre, ou un champ vide qui veut dire aucune option) devient une surcharge propre a cette licence.
        Taper * seul donne toutes les options a cette licence, y compris celles qui seront ajoutees plus tard a
        l'application.</li>
        <li>Bouton "Accepter", confirmation "Accepter la demande et creer la cle". La licence est creee active, deja liee
        au poste demandeur, avec l'e-mail de la demande (origine "demande").</li>
        <li>L'ecran affiche la cle une seule fois ("Cle (affichee une seule fois)", bouton "Copier"). Rien a transmettre :
        l'application la recupere seule. Gardez-en quand meme une copie en lieu sur : c'est la seule occasion de la voir,
        et elle sera necessaire si ce client change un jour d'ordinateur.</li>
        <li>Delai de remise : sans essai, l'application interroge le serveur chaque minute et sa fenetre se ferme seule.
        Pendant un essai, elle ne verifie que toutes les 6 heures et a chaque lancement : l'utilisateur peut accelerer
        par Ctrl+Maj+L puis "Verifier maintenant", ou en relancant l'application.</li>
        </ul>
        <p><strong>Refuser</strong> (section "Refuser") : "Motif (facultatif, renvoye tel quel a l'application)",
        {{MOTIF_MAX}} caracteres au plus, puis le bouton rouge "Refuser". L'application affiche la fenetre "Demande refusee"
        avec le motif (sans accents) et les boutons "Nouvelle demande" et "J'ai une cle". Le motif est lu par le client :
        rester factuel.</p>
        <p>Une demande traitee ne se traite plus : sa fiche indique "Acceptee le ... : licence n. X" ou "Refusee le ... :
        motif". Pour revenir sur une acceptation, agir sur la licence creee (suspendre, revoquer ; voir les cas
        particuliers ci-dessous si le poste n'a pas encore recupere sa cle). Si la demande a deja ete traitee depuis un autre
        navigateur ou un autre appareil, la seconde tentative affiche la page "Action impossible" avec "demande inconnue ou
        deja traitee". Depuis un autre onglet du meme navigateur, c'est la page "Requete refusee" avec "Formulaire expire ou
        invalide : rechargez la page puis recommencez." ; recharger la fiche montre alors la decision deja prise.</p>
        <h3>Periode d'essai</h3>
        <ul>
        <li>Fixee a l'envoi de la demande, pour la duree "Essai pendant une demande" de la distribution (0 = aucun essai).
        Modifier ce reglage ne change rien aux demandes deja recues.</li>
        <li>Accordee une seule fois par poste et par produit, toutes distributions confondues. Une nouvelle demande d'un
        poste qui a deja eu un essai pour ce produit (apres un refus, une revocation, une perte des fichiers de licence) n'a
        pas d'essai : l'application reste bloquee sur "Demande en attente" jusqu'a votre decision. Un poste qui n'en a
        jamais eu (par exemple equipe jusque-la d'une cle creee dans la console) en obtient un a sa premiere demande, si la
        distribution en prevoit un.</li>
        <li>Pendant l'essai, l'application utilise les options de la distribution.</li>
        </ul>
        <h3>Cas particuliers</h3>
        <ul>
        <li>Une cle a ete transmise par un autre canal alors qu'une demande de ce poste etait en attente : en activant la
        cle, l'application a oublie sa demande, mais celle-ci reste en attente ici. La refuser : l'accepter creerait une
        seconde licence que personne ne recupererait.</li>
        <li>Une demande acceptee dont la licence a ete revoquee (ou dont le poste a ete libere) avant que le poste ait
        recupere sa cle : le poste ne recevra jamais cette cle. A son controle suivant, il abandonne la demande : l'essai
        en cours s'arrete (message "Licence revoquee" puis fermeture), et au lancement suivant la fenetre d'activation
        propose "J'ai une cle" et "Demander une licence". Pour le remettre en service, creer une nouvelle cle dans la
        console et la lui transmettre, ou le laisser envoyer une nouvelle demande (pas de nouvel essai : il en a deja eu
        un).</li>
        <li>Une demande acceptee dont la licence a ete suspendue avant que le poste ait recupere sa cle : le poste ne la
        recoit pas encore, et la suspension ne le bloque pas. S'il a un essai en cours, l'application reste utilisable
        jusqu'a la fin de l'essai ; ensuite elle reste sur "Demande en attente" ("Verifier maintenant" y affiche "Licence
        suspendue"). Des que la licence redevient active (bouton "Reactiver", ou date de fin de la suspension atteinte), le
        poste recupere sa cle tout seul a son controle suivant (dans la minute sur "Demande en attente" ; pendant l'essai,
        en general dans les 15 minutes, au plus 6 heures), sans rien ressaisir.</li>
        </ul>
        HTML;
}

function aide_section_licences(): string
{
    return <<<'HTML'
        <h3>Liste et recherche</h3>
        <ul>
        <li>"Recherche" porte sur le titulaire, l'e-mail, les 4 derniers caracteres de la cle, l'identifiant de poste, le
        nom de l'ordinateur et la note. "Statut" : tous, actives, expirees, suspendues, revoquees. "Distribution" : toutes,
        y compris les inactives. Bouton "Filtrer".</li>
        <li>Colonnes : Titulaire (lien vers la fiche), Distribution, Cle (4 derniers caracteres), Poste lie ("non lie" tant
        que la cle n'a pas ete activee, ou apres "Liberer le poste"), Echeance ("perpetuelle" s'il n'y en a pas), Statut
        (Active, Expiree, Suspendue ou Revoquee ; une licence suspendue porte en plus "jusqu'au JJ/MM/AAAA", dernier jour de
        la suspension : elle redevient active d'elle-meme a la fin de ce jour, ou "(sans date de fin)" : elle reste
        suspendue jusqu'a "Reactiver"), Dernier contact (dernier controle reussi du poste).</li>
        <li>La liste s'arrete a 500 lignes, triees par titulaire, sans avertissement au-dela : affiner la recherche, ou
        utiliser "Exporter les licences (CSV)", qui donne toutes les licences quels que soient les filtres.</li>
        </ul>
        <h3>Creer une cle</h3>
        <ol>
        <li>Licences, bouton "Creer une cle".</li>
        <li>"Distribution" : seules les distributions actives de produits actifs sont proposees. Choisir celle de
        l'executable du client : saisie dans une application d'une autre distribution, la cle est refusee ("Cle invalide").
        Pour connaitre la distribution du client, lui faire ouvrir la fenetre Licence (Ctrl+Maj+L) et lire la zone
        Diagnostic, qui commence par "Licence ETDEL x.y.z ; PRODUIT/DISTRIBUTION vVERSION" ; ou reprendre la distribution
        d'une demande ou d'une licence precedente de ce client.</li>
        <li>"Titulaire" (obligatoire, affiche dans l'application), "E-mail du client (facultatif)", "Note interne
        (facultative)" (500 caracteres, jamais montree au client).</li>
        <li>"Duree en jours (vide = perpetuelle)" : preremplie avec la duree par defaut de la distribution (sans JavaScript,
        elle ne suit pas le changement de distribution). La duree court des la creation, pas des la premiere activation :
        creer la cle au moment de la transmettre.</li>
        <li>"Creer la cle", confirmer "Creer une cle de licence". La cle s'affiche une seule fois avec un bouton "Copier" :
        la copier avant de quitter la page. Recharger la page ne la reaffiche pas ("Formulaire expire") et le serveur n'en
        garde que le hachage et les 4 derniers caracteres.</li>
        <li>Transmettre la cle vous-meme (e-mail, telephone) : le serveur n'envoie jamais rien au client, le champ E-mail
        n'est qu'une note. Mode d'emploi a joindre : dans l'application, fenetre "Licence requise", cliquer "J'ai une cle",
        coller ou taper la cle sous "Cle de licence fournie par ETDEL :", puis "Activer" ; la fenetre se ferme et
        l'application s'ouvre.</li>
        </ol>
        <p>La saisie n'est possible que dans la fenetre d'activation ("Licence requise", "Demande en attente", "Demande
        refusee", "Licence a verifier", "Licence suspendue"). Elle est impossible pendant un essai ou quand une licence
        fonctionne : pour un poste en essai, accepter plutot sa demande.</p>
        <p>Format : ETDEL-XXXX-XXXX-XXXX-XXXX ; le dernier caractere est une somme de controle : un caractere mal tape est
        detecte des la saisie ("Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)"), mais pas toujours deux
        caracteres inverses ; la cle est alors refusee par le serveur avec "Cle invalide" seul. A la saisie, majuscules ou
        minuscules, tirets et espaces sont indifferents ; O est lu 0, I et L sont lus 1. La cle se lie au premier ordinateur
        qui l'active. Une licence creee ici suit la tolerance et les options de sa distribution.</p>
        <h3>Fiche d'une licence</h3>
        <p>Statut (pour une licence suspendue, suivi de "jusqu'au JJ/MM/AAAA" ou "(sans date de fin)"), Titulaire, E-mail,
        Note, Distribution, Cle (seule la fin est conservee), Echeance (et jours restants),
        Tolerance hors ligne ("distribution (N j)" ou "N j (surcharge)"), Options (suivies de "(distribution)" ou
        "(surcharge)"), Poste lie (identifiant, nom de l'ordinateur, debut de l'empreinte), Lie le, Version de
        l'application, Dernier contact, Derniere IP, Origine (console ou demande), Creee le, Modifiee le. En bas,
        "Historique" : les 50 derniers evenements du journal pour cette licence, y compris ceux des postes (activation,
        refus_autre_poste) et du serveur (licence_reactivee, acteur "systeme", a la fin d'une suspension datee).</p>
        <h3>Prolonger</h3>
        <p>Section presente seulement pour une licence a echeance : une licence perpetuelle ne se prolonge pas.</p>
        <ul>
        <li>"+30 jours" et "+1 an" (365 jours) : a partir de l'echeance actuelle, ou d'aujourd'hui si elle est deja
        passee.</li>
        <li>"Nouvelle echeance" puis "Fixer la date" : l'echeance devient ce jour-la a 23:59:59 ({{FUSEAU}}). La date doit
        etre aujourd'hui ou plus tard (une date passee est refusee : "Date invalide ou passee.") ; choisir aujourd'hui fait
        expirer la licence ce soir a 23:59:59. Elle peut etre plus proche que l'echeance actuelle et donc raccourcir la
        licence.</li>
        <li>Seule l'echeance change : une licence suspendue reste suspendue. Une licence expiree redevient active sans autre
        action ; le poste a garde sa cle et se debloque a son controle suivant (faire cliquer "Reessayer" ou relancer
        l'application).</li>
        <li>Le choix entre perpetuelle et datee est definitif : pour passer de l'une a l'autre, creer une nouvelle cle.</li>
        </ul>
        <h3>Modifier</h3>
        <ul>
        <li>"Titulaire", "E-mail", "Note interne" : le titulaire est aussi affiche dans la fenetre Licence de l'application,
        apres son controle suivant.</li>
        <li>"Tolerance hors ligne en jours (vide = distribution)" : de 0 a 365. Une valeur saisie fige la tolerance de
        cette licence ; vide, elle suit la distribution.</li>
        <li>Case "Options de la distribution" cochee : la licence suit les options de la distribution, et le champ
        "Options propres a la licence (* = toutes)" est ignore (ce qui y est tape est perdu). Case decochee : la liste
        saisie devient la surcharge de la licence (vide = aucune option, * = toutes les options, presentes et futures) et
        ne suit plus les changements de la distribution.</li>
        <li>"Enregistrer", confirmer "Modifier la licence". Les nouvelles valeurs parviennent au poste a son controle
        suivant.</li>
        </ul>
        <h3>Etat : suspendre, reactiver, revoquer, liberer</h3>
        <p>Sur une licence active (meme affichee expiree), la section "Etat" propose le champ "Jusqu'au (facultatif)" et le
        bouton "Suspendre", puis "Revoquer" (bouton rouge) et, si un poste est lie, "Liberer le poste". Pour suspendre :</p>
        <ol>
        <li>Suspension sans date : laisser "Jusqu'au" vide. La licence reste suspendue jusqu'a ce que vous cliquiez
        "Reactiver".</li>
        <li>Suspension datee : choisir le DERNIER jour de la suspension. La licence reste suspendue toute cette journee et
        redevient active d'elle-meme a sa fin (23:59:59, fuseau {{FUSEAU}}), sans action de votre part. La suspension
        commence des la validation (il n'y a pas de date de debut). Exemple : le 10, pour couper jusqu'au 15 inclus, saisir
        le 15 ; le poste peut remarcher des le 16, a son controle suivant. La date du jour est acceptee ; une date passee ou
        inexistante est refusee ("Date invalide ou passee.").</li>
        <li>Cliquer "Suspendre", confirmer "Suspendre la licence". Le message "Licence suspendue." s'affiche ; la fiche et
        la liste indiquent "Suspendue jusqu'au JJ/MM/AAAA" ou "Suspendue (sans date de fin)".</li>
        </ol>
        <p>Sur une licence suspendue, la section propose :</p>
        <ul>
        <li>"Reactiver" (confirmation "Reactiver la licence") : la licence redevient active tout de suite et la date de fin
        eventuelle est effacee ; le poste se debloque a son controle suivant.</li>
        <li>"Nouvelle date de fin (vide = sans date)" puis "Changer la date de fin" : une date remplace l'ancienne (fin
        avancee ou reculee). Attention : valider ce champ vide retire la date existante, et la suspension dure alors
        jusqu'a "Reactiver". La confirmation affichee est encore "Suspendre la licence" : c'est normal.</li>
        <li>"Revoquer" (bouton rouge, definitif ; efface aussi une date de fin) et, si un poste est lie, "Liberer le
        poste", comme pour une licence active.</li>
        </ul>
        <p>A la date de fin, rien a faire : le serveur reactive la licence a la premiere requete qui suit (controle de
        n'importe quel poste, ou affichage d'une page de la console) et l'inscrit au journal (licence_reactivee, acteur
        "systeme", detail "fin de la suspension programmee") ; l'heure inscrite peut donc etre posterieure a la date de fin.
        L'echeance n'est pas modifiee : si elle est passee pendant la suspension, la licence reapparait "Expiree" et il faut
        la prolonger. Les effets de chaque action sur le poste sont compares dans <a href="#etats">Suspendre, revoquer,
        liberer, laisser expirer</a>. Une licence revoquee n'a plus aucune action : seuls la fiche et l'historique
        restent.</p>
        HTML;
}

function aide_section_etats(): string
{
    $html = <<<'HTML'
        <p>Quatre facons de couper ou de deplacer un acces, a ne pas confondre :</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Action | Effet sur le poste | Reversible ? | Ce que voit l'utilisateur | Remise en service
        <strong>Suspendre</strong>, avec ou sans date "Jusqu'au" (confirmation "Suspendre la licence") | bloque a sa connexion suivante ; le poste garde sa cle et reste bloque, meme sans Internet, jusqu'a un controle reussi apres la fin de la suspension | oui : "Reactiver", ou automatiquement a la fin du jour choisi dans "Jusqu'au" | en cours d'utilisation : boite "Licence" avec le message, puis fermeture ; au lancement : fenetre "Licence suspendue" avec le message, "Reessayer" et "J'ai une cle". Message : "Licence suspendue jusqu'au JJ/MM/AAAA. Elle sera reactivee automatiquement a cette date (Reessayer)." ou, sans date de fin, "Licence suspendue. Contactez ETDEL pour la reactiver." | "Reactiver", ou attendre la date de fin. Rien a ressaisir, meme pour une licence obtenue par demande : le poste se debloque seul a son premier controle apres la reactivation ou la date de fin (3 secondes apres le lancement, puis toutes les 6 heures ; "Reessayer" pour tout de suite), a condition de pouvoir joindre le serveur
        <strong>Revoquer</strong> (confirmation "Revoquer definitivement la licence") | bloque a sa connexion suivante ; le poste efface sa cle | <strong>non, jamais</strong> | message "Licence revoquee" puis fermeture ; au lancement suivant : fenetre "Licence requise" avec "J'ai une cle" et "Demander une licence". Si la fenetre d'activation est deja ouverte (par exemple "Licence suspendue"), elle passe directement a "Licence requise", avec le message | nouvelle cle creee dans la console et transmise (l'utilisateur la saisit par "J'ai une cle" dans la fenetre "Licence requise"), ou nouvelle demande envoyee depuis l'application et acceptee (pas de nouvel essai si ce poste en a deja eu un pour ce produit)
        <strong>Liberer le poste</strong> (confirmation "Liberer le poste (changement d'ordinateur)") | la cle n'est plus liee a aucun ordinateur ; l'ancien poste efface sa cle a son controle suivant. Sur une licence suspendue, rien ne change avant la fin de la suspension : l'ancien poste reste bloque avec sa cle, et le nouvel ordinateur recoit "Licence suspendue" (cle non enregistree) ; la cle ne peut etre activee sur le nouvel ordinateur qu'apres "Reactiver" ou la date de fin | oui : la cle se lie de nouveau au premier ordinateur qui l'active | ancien poste : message "Ce poste n'est plus autorise pour cette licence" puis fermeture, puis "Licence requise" | saisir la meme cle par "J'ai une cle" sur le nouvel ordinateur
        <strong>Laisser expirer</strong> (ou "Fixer la date" a une echeance proche) | bloque a l'echeance, meme hors ligne ; le poste garde sa cle | oui : "Prolonger" | 15 jours avant : bandeau "Licence valable jusqu'au..." ; a l'echeance : message "Licence expiree le..." puis fermeture ; au lancement : "Licence a verifier" avec "Reessayer" | prolonger, puis "Reessayer" ou relancer l'application
        T);
    $html .= <<<'HTML'
        <h3>A retenir</h3>
        <ul>
        <li><strong>Delai</strong> : suspendre, revoquer ou liberer n'agit qu'a la connexion suivante du poste : au plus
        6 heures pour un poste connecte (plus 25 secondes pour l'affichage), au plus la tolerance hors ligne (15 jours par
        defaut) pour un poste deconnecte. Apres "Liberer le poste", l'ancien et le nouvel ordinateur peuvent donc
        fonctionner tous les deux pendant ce temps. L'echeance, elle, est verifiee par l'application elle-meme, meme hors
        ligne.</li>
        <li><strong>Remise en service</strong> : dans l'autre sens, une reactivation ("Reactiver" ou fin de la date
        choisie) ou une prolongation n'atteint le poste qu'a son controle suivant. Un poste bloque ne refait un controle
        automatique que 6 heures apres le dernier refus : pour un deblocage immediat, faire cliquer "Reessayer" ou relancer
        l'application (controle 3 secondes apres le lancement). Le poste doit pouvoir joindre le serveur : un poste qui a
        deja recu la suspension reste bloque tant qu'il ne s'est pas connecte, meme apres la date de fin. La date de fin
        compte en entier (jusqu'a 23:59:59) : pour un retour le lundi matin, choisir le dimanche.</li>
        <li><strong>La revocation est definitive</strong> : "Reactiver" ne s'applique qu'a une licence suspendue, la fiche
        d'une licence revoquee n'a plus aucun bouton, et la meme cle ressaisie est refusee pour toujours ("Licence
        revoquee"). La licence reste visible (filtre "revoquees", compteur, journal, exports). Un poste qui n'a jamais eu
        d'essai pour ce produit (par exemple une cle creee dans la console, sans demande anterieure) obtient l'essai de la
        distribution s'il envoie une demande apres la revocation : il peut utiliser l'application jusqu'a la fin de l'essai
        ou jusqu'a votre decision ; refuser la demande y met fin a son controle suivant (au plus 6 heures). Pour une coupure
        temporaire, utiliser "Suspendre", avec une date "Jusqu'au" si la fin est connue : la licence redevient active
        d'elle-meme a la fin de ce jour, y compris une licence perpetuelle. Pour une licence datee, autre possibilite :
        "Fixer la date" a une echeance proche (blocage a cette date, meme hors ligne), puis prolonger vous-meme.</li>
        <li><strong>Licences obtenues par demande</strong> (origine "demande") : l'utilisateur n'a jamais vu sa cle, et la
        console ne l'a affichee qu'une fois, a l'acceptation. Suspendre puis reactiver fonctionne normalement, puisque le
        poste garde sa cle : rien a ressaisir. Exception : si la licence est suspendue avant que le poste ait recupere sa
        cle (demande acceptee, poste encore en essai ou sur "Demande en attente"), la suspension ne bloque pas ce poste. Il
        reste sur sa demande (essai utilisable jusqu'a sa fin, puis "Demande en attente") et ne recupere sa cle qu'a son
        premier controle apres la reactivation. En revanche, apres "Liberer le poste", il faut saisir cette cle sur le
        nouvel ordinateur : sans la copie faite a l'acceptation, creer plutot une nouvelle cle pour le nouvel ordinateur (et
        revoquer l'ancienne licence), ou laisser l'utilisateur faire une nouvelle demande depuis le nouvel ordinateur.</li>
        <li><strong>Desactiver une distribution ou un produit</strong> (case "Active" ou "Actif") bloque, a leur controle
        suivant, tous les postes qui ont une cle ("Produit ou distribution inconnu du serveur de licences") sans effacer
        leur cle : recocher la case les debloque. Exception : un poste en periode d'essai (demande en attente) n'est pas
        bloque ; il continue jusqu'a la fin de son essai, puis reste sur "Demande en attente", et votre reponse a sa demande
        ne lui parvient qu'une fois la case recochee.</li>
        <li>Chaque action est inscrite au journal : licence_suspendue (detail "active -> suspendue, jusqu'au JJ/MM/AAAA"
        ou "..., sans date de fin" ; un changement de date de fin donne "suspendue -> suspendue, ..."), licence_reactivee
        (avec votre identifiant pour un clic sur "Reactiver", ou par l'acteur "systeme" avec le detail "fin de la suspension
        programmee" quand la date de fin est atteinte), licence_revoquee, poste_libere, licence_prolongee.</li>
        </ul>
        HTML;
    return $html;
}

function aide_section_produits(): string
{
    $html = <<<'HTML'
        <h3>Produits</h3>
        <ul>
        <li>"Nouveau produit" : "Code (ex. MONAPPLI, definitif)" (lettres, chiffres, point, tiret, tiret bas ; 64 caracteres ;
        majuscules et minuscules distinguees), "Nom", "Version minimale globale (facultative)", case "Actif". Le code est
        inscrit dans les applications : il ne se modifie plus.</li>
        <li>L'ecran Produits affiche pour chaque produit "CODE - Nom" (etiquette "desactive" s'il l'est), sa version
        minimale, les liens "Modifier" et "Nouvelle distribution", puis le tableau de ses distributions : Code (etiquette
        "Inactive" si besoin), Libelle, Client ou canal, Tolerance / preavis, Duree par defaut, Essai, Version min,
        Options, Licences (nombre de licences).</li>
        </ul>
        <h3>Distributions</h3>
        <p>"Nouvelle distribution" : choisir le "Produit", puis "Code (ex. MONAPPLI-CLIENTA, definitif)", unique sur tout le
        serveur (tous produits confondus), et les reglages :</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Champ | Valeurs | Effet
        Libelle | 120 caracteres, obligatoire | nom affiche dans la console
        Client ou canal | 120 caracteres | pour vos dossiers
        Tolerance hors ligne (jours, 0 a 365) | 15 par defaut | duree d'utilisation sans controle reussi aupres du serveur ; 0 donne quand meme {{TOLERANCE_MIN_H}} heures (voir <a href="#tolerance">Echeance et tolerance</a>)
        Preavis (jours, 0 a 365) | 5 par defaut | bandeau avant la fin de la tolerance hors ligne, jamais avant la moitie de celle-ci ni dans les 7 heures qui suivent un controle reussi
        Duree de licence par defaut (jours, vide = perpetuelle) | 1 a 36500 | valeur proposee a la creation d'une cle et a l'acceptation d'une demande
        Essai pendant une demande (jours, 0 = aucun) | 15 par defaut, 0 a 365 | utilisation pendant l'attente de votre decision, une fois par poste et par produit
        Version minimale (facultative) | 32 caracteres | version la plus ancienne acceptee ; la plus exigeante entre produit et distribution s'applique
        Options activees (codes separes par des virgules, * = toutes) | minuscules, chiffres et _ (40 caracteres par code), ou * seul | fonctions activees dans l'application ; * active toutes les options, y compris celles qui seront ajoutees plus tard (edition complete, usage interne). Les codes sont fixes par le developpeur dans le code de l'application (par exemple garde.option("export_pdf")) : lui en demander la liste. La console ne verifie pas les codes : un code mal orthographie n'active rien, sans aucun message. Vide = aucune option ; une option absente vaut "non"
        Message d'accueil (facultatif) | {{ACCUEIL_MAX}} caracteres | affiche dans la fenetre Licence de l'application, sans accents
        Active | case | decochee : les postes qui ont une cle sont bloques a leur controle suivant, sauf les essais en cours (voir plus bas)
        T);
    $html .= <<<'HTML'
        <p>Tolerance, preavis, options, version minimale et message d'accueil sont transmis au poste a chaque controle : les
        licences sans surcharge suivent une modification de la distribution au controle suivant de leur poste. La duree par
        defaut et l'essai ne valent que pour les cles et les demandes a venir.</p>
        <p>Aucun reglage n'interdit les demandes : toute application sans licence propose "Demander une licence". Pour une
        distribution reservee a un client a qui vous creez les cles, mettre "Essai pendant une demande" a 0 : sinon tout
        ordinateur qui a cet executable peut utiliser l'application pendant l'essai, en attendant votre decision. Pour une
        distribution publique (demandes depuis l'application), choisir l'essai selon votre offre.</p>
        <h3>Modifier un produit ou une distribution</h3>
        <p>Produit : lien "Modifier" sous son nom, sur l'ecran Produits (ligne "Version minimale"). "Nom", "Version minimale
        globale (facultative)" et la case "Actif" se modifient, pas le code. Cliquer "Enregistrer", confirmer "Enregistrer
        le produit" (message "Produit enregistre.").</p>
        <p>Distribution : cliquer sur son code dans le tableau de l'ecran Produits. Sa fiche affiche la ligne a copier, puis
        les memes champs qu'a la creation, tous modifiables sauf le produit et le code. Cliquer "Enregistrer", confirmer
        "Enregistrer la distribution" (message "Distribution enregistree."). L'effet de chaque changement sur les licences
        existantes est decrit ci-dessus.</p>
        <h3>Ligne a copier dans l'application</h3>
        <p>La fiche d'une distribution existante affiche "Ligne a inserer dans l'application, juste apres la creation de la
        fenetre principale :", par exemple
        <code>etdel_licence.installer(root, produit="MONAPPLI", distribution="MONAPPLI-CLIENTA", version=APP_VERSION)</code>,
        avec un bouton "Copier". Chaque executable livre correspond a une seule distribution. Les lignes LICENCE_URL,
        LICENCE_URL_SECOURS et LICENCE_CLE_PUBLIQUE, a reporter une fois dans <code>etdel_licence.py</code>, sont sur
        l'ecran <a href="#cles">Cles</a>.</p>
        <h3>Dupliquer une distribution</h3>
        <p>Section "Dupliquer" de la fiche : "Code de la copie" (propose CODE-COPIE), bouton "Dupliquer". La copie reprend
        tous les reglages, y compris la case Active, avec le libelle suivi de "(copie)" ; les licences ne sont pas copiees.
        Elle s'ouvre avec le message "Distribution dupliquee : verifiez ses reglages."</p>
        <h3>Version minimale</h3>
        <p>Les versions se comparent nombre par nombre : 1.10 est plus recente que 1.9, 1.2 vaut 1.2.0, les suffixes sont
        ignores. Saisir des versions purement numeriques (1.4.0, pas v1.4). Une application trop ancienne affiche "Mise a
        jour necessaire" ("Reessayer", "Quitter") et garde sa cle ; installer une version a jour, ou baisser la version
        minimale, la debloque. Elle ne peut pas non plus envoyer de demande.</p>
        <h3>Desactiver plutot que supprimer</h3>
        <p>Un produit ou une distribution ne se supprime pas. Decocher "Actif" (produit) ou "Active" (distribution) bloque,
        a leur controle suivant, tous les postes qui ont une cle, licences valides comprises ("Produit ou distribution
        inconnu du serveur de licences"), et empeche toute nouvelle activation ou demande. Les postes gardent leur cle :
        recocher la case les debloque au controle suivant. Un produit desactive bloque toutes ses distributions.</p>
        <p>Exception : un poste en periode d'essai (demande en attente) n'est pas bloque. Il continue jusqu'a la fin de son
        essai, puis reste sur "Demande en attente". Votre reponse a sa demande (acceptation ou refus) ne lui parvient
        qu'une fois la case recochee.</p>
        HTML;
    return $html;
}

function aide_section_application(): string
{
    $html = <<<'HTML'
        <h3>Ou l'utilisateur voit sa licence</h3>
        <ul>
        <li><strong>Fenetre d'activation</strong> (titre "Licence - PRODUIT") : s'ouvre au lancement quand l'application ne
        peut pas etre utilisee ; la fenetre principale reste alors cachee. La fermer par la croix quitte l'application.</li>
        <li><strong>Bandeau</strong> en haut de la fenetre principale, sur toute la largeur, sans bouton de fermeture :
        seulement pendant un essai ou un avertissement.</li>
        <li><strong>Fenetre Licence (Ctrl+Maj+L)</strong>, a tout moment : Identifiant du poste, Nom de l'ordinateur,
        Titulaire, Echeance, Statut, Dernier controle, le message d'accueil de la distribution, le bouton "Verifier
        maintenant" et la zone "Diagnostic". Elle se ferme par "Fermer", Echap ou la croix. La cle n'y figure jamais.
        Certaines applications affichent le meme cadre dans leurs reglages. Rien n'y est modifiable : on ne peut pas y
        saisir de cle.</li>
        <li><strong>Page "J'ai une cle"</strong> (bouton present dans les fenetres "Licence requise", "Demande en attente",
        "Demande refusee", "Licence a verifier" et "Licence suspendue") : champ "Cle de licence fournie par ETDEL :",
        boutons "Activer" (ou touche Entree) et "Retour". Pendant l'envoi : "Connexion au serveur...". Une faute de frappe
        est signalee sans contacter le serveur ("Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)"). Un refus
        du serveur s'affiche sous le champ (voir <a href="#codes">les codes de refus</a>) et la cle n'est pas enregistree.
        Si la cle est acceptee, la fenetre se ferme et l'application s'ouvre.</li>
        </ul>
        <h3>Statut par statut</h3>
        HTML;
    $html .= aide_tableau(<<<'T'
        Statut | Quand | Ce que voit l'utilisateur | Que faire
        Licence requise (A_ACTIVER) | ni cle ni demande : premier lancement, cle revoquee ou liberee | fenetre "Licence requise" : "Cette application necessite une licence ETDEL.", identifiant du poste, "J'ai une cle" et "Demander une licence" | transmettre une cle, ou attendre sa demande
        Essai (ESSAI) | demande en attente, essai en cours | juste apres l'envoi, page "Demande envoyee" ("En attendant la reponse, l'application est utilisable pendant N jour(s).", bouton "Continuer"), puis application utilisable, bandeau "Licence en cours de traitement - N jour(s) d'essai restant(s)" | traiter la demande avant la fin de l'essai
        Demande en attente (DEMANDE_EN_ATTENTE) | demande en attente, sans essai ou essai termine | fenetre "Demande en attente" : "Demande envoyee le JJ/MM/AAAA. Le traitement peut prendre plusieurs jours.", puis "La periode d'essai est terminee." ou "Aucune periode d'essai n'est disponible pour ce poste." (essai deja accorde a ce poste pour ce produit, ou essai a 0 dans la distribution), "La reponse est verifiee automatiquement chaque minute.", identifiant, "Verifier maintenant" et "J'ai une cle" ; application inutilisable | accepter ou refuser la demande
        Demande refusee (DEMANDE_REFUSEE) | demande refusee | fenetre "Demande refusee" avec le motif, "Nouvelle demande" et "J'ai une cle" | aucune action
        Valide (VALIDE) | licence en regle | rien : l'application s'ouvre normalement | aucune action
        Avertissement (AVERTISSEMENT) | echeance a moins de 15 jours, ou fin de la tolerance hors ligne proche | bandeau "Licence valable jusqu'au JJ/MM/AAAA (N jour(s) restant(s)). Contactez ETDEL pour la prolonger." ou "Licence non verifiee depuis N jour(s) : connectez l'ordinateur a Internet avant le JJ/MM/AAAA." | prolonger, ou faire connecter le poste
        Bloquee (EXPIREE) | echeance passee, tolerance epuisee, licence suspendue, produit ou distribution desactive | en cours d'utilisation : boite "Licence" avec le message puis fermeture ; au lancement : fenetre "Licence a verifier" (titre "Licence suspendue" pour une suspension) avec le message, l'identifiant du poste, "Reessayer" et "J'ai une cle" ; la cle est conservee. Pour une suspension, le message est "Licence suspendue jusqu'au JJ/MM/AAAA. Elle sera reactivee automatiquement a cette date (Reessayer)." ou, sans date de fin, "Licence suspendue. Contactez ETDEL pour la reactiver." | prolonger, "Reactiver", recocher "Active" (distribution) ou "Actif" (produit), ou faire connecter le poste ; suspension avec date de fin : rien a faire. Le poste se debloque seul, sans ressaisie, meme pour une licence obtenue par demande, a son premier controle apres le changement : 3 secondes apres un lancement, sinon jusqu'a 6 heures plus tard ; "Reessayer" le fait tout de suite. Une suspension "jusqu'au 10/10" dure toute la journee du 10/10 (fin a 23:59:59, fuseau {{FUSEAU}}) : le poste se debloque au plus tot le 11/10, et seulement s'il joint le serveur (hors ligne, il reste bloque)
        Revoquee (REVOQUEE) | licence revoquee, poste libere, cle liee a un autre ordinateur ou inconnue du serveur | message ("Licence revoquee", "Ce poste n'est plus autorise pour cette licence", "Cle deja utilisee sur un autre ordinateur" ou "Cle invalide") puis fermeture ; la cle est effacee ; au lancement suivant : "Licence requise". Si la fenetre d'activation est deja ouverte (par exemple "Licence suspendue" ou "Licence a verifier"), pas de boite ni de fermeture : elle passe a la page "Licence requise", avec le message, "J'ai une cle" et "Demander une licence" | nouvelle cle ou nouvelle demande
        Version refusee (VERSION_REFUSEE) | version inferieure a la version minimale | "Version X trop ancienne : mettez l'application a jour." puis fermeture ; au lancement : "Mise a jour necessaire" avec "Reessayer" et "Quitter" | installer une version a jour
        Non configure (NON_CONFIGURE) | LICENCE_CLE_PUBLIQUE vide, ou LICENCE_URL et LICENCE_URL_SECOURS vides toutes les deux, dans etdel_licence.py | rien : le module ne fait rien et l'application fonctionne sans licence | reporter les trois lignes de l'ecran Cles avant de livrer (il faut au moins une URL et la cle publique)
        T);
    $html .= <<<'HTML'
        <p>Les messages d'erreur affiches lors de la saisie d'une cle ou de l'envoi d'une demande sont expliques dans
        <a href="#codes">les codes de refus</a>.</p>
        <h3>Ce que l'utilisateur peut vous dicter</h3>
        <ul>
        <li><strong>Identifiant du poste</strong> (XXXX-XXXX) : dans la fenetre Licence (Ctrl+Maj+L) ou sur les pages
        "Licence requise", "Demande en attente", "Demande refusee", "Licence a verifier" et "Licence suspendue" (pas sur
        "Mise a jour necessaire"). Il ne contient jamais les lettres I, L, O ni U. Il est different pour chaque produit sur
        un meme ordinateur : demander celui de la bonne application. Il correspond a la colonne "Identifiant" (Demandes) ou
        "Poste lie" (Licences). Pour le retrouver : Licences, taper l'identifiant AVEC son tiret dans "Recherche", puis
        "Filtrer" (sans le tiret, l'identifiant complet ne donne rien). Un O dicte est un zero, un I ou un L est un 1. Rien
        dans Licences : chercher dans la colonne "Identifiant" des demandes en attente (Ctrl+F du navigateur), puis dans le
        Journal ("Recherche" : l'identifiant ; acteur "poste:XXXX-XXXX" pour les evenements demande, activation et
        refus_autre_poste). Apres "Liberer le poste", la licence ne porte plus l'identifiant : seul le journal le garde
        (poste_libere, detail "ancien poste ...").</li>
        <li><strong>Ligne de diagnostic</strong> (zone "Diagnostic" de la fenetre Licence, selectionnable pour la copier) :
        version du module, produit, distribution et version de l'application, poste, statut, echeance, dernier controle,
        serveur qui a repondu, numero (kid) de la cle de signature et, a la fin, la raison du dernier echec : par exemple
        "serveur injoignable (...)" (entre parentheses, pour chaque adresse essayee : le nom de l'erreur reseau, par exemple
        URLError, "http 503" ou "reponse invalide ou mal signee"), un code de refus (par exemple suspendue) ou "Horloge de
        l'ordinateur incorrecte...". La cle n'y figure jamais.</li>
        <li><strong>Dernier controle</strong> : date du dernier controle tranche par le serveur. Une date ancienne signale
        un probleme de connexion ou d'horloge.</li>
        </ul>
        <h3>Verifier maintenant, Reessayer</h3>
        <p>Ces boutons lancent un controle immediat. Si le statut change, la fenetre bascule ou se ferme. Sinon, la fenetre
        d'activation affiche "Verifie le JJ/MM/AAAA HH:MM : pas encore de reponse." (le serveur a repondu sans changement)
        ou la raison de l'echec. Dans la fenetre Licence, le resultat se lit dans Dernier controle, Statut et
        Diagnostic.</p>
        <h3>Quand l'application se ferme-t-elle ?</h3>
        <p>Si, en cours d'utilisation, la licence devient expiree, suspendue, revoquee ou la version refusee, l'application
        affiche une boite "Licence" avec le message, puis se ferme apres le clic sur OK (au plus 25 secondes apres le
        controle) : le travail non enregistre peut etre perdu. Si une demande est refusee ou si l'essai se termine en cours
        d'utilisation, la fenetre principale est remplacee par la fenetre d'activation.</p>
        <p>Application en mode console (sans fenetre) : controle au lancement seulement ; sans licence, la cle est demandee
        au clavier si le programme est lance dans une console interactive ; sinon (tache planifiee, entree redirigee), il
        s'arrete aussitot avec le code de sortie 3. Aucune demande n'est possible, il faut lui creer une cle ; une licence
        inutilisable arrete le programme (code de sortie 3).</p>
        HTML;
    return $html;
}

function aide_section_tolerance(): string
{
    $html = <<<'HTML'
        <h3>Trois notions differentes</h3>
        <ul>
        <li><strong>Echeance</strong> : date de fin de la licence, fixee dans la console (ou aucune pour une licence
        perpetuelle). Elle est inscrite dans la reponse signee que le poste conserve : l'application la verifie elle-meme,
        meme hors ligne. Bandeau 15 jours avant (valeur fixe du module), blocage a la date.</li>
        <li><strong>Tolerance hors ligne</strong> : duree pendant laquelle l'application fonctionne sans controle reussi
        aupres du serveur. Chaque controle reussi la fait repartir de zero. Reglee par distribution (15 jours par defaut)
        ou par licence (surcharge), de 0 a 365 jours ; jamais moins de {{TOLERANCE_MIN_H}} heures, meme a 0.</li>
        <li><strong>Preavis</strong> (reglage de la distribution, 5 jours par defaut) : nombre de jours avant la fin de la
        tolerance ou le bandeau apparait, jamais avant la moitie de la tolerance. Il ne concerne pas l'echeance et n'a pas
        de surcharge par licence.</li>
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
        <p>Un poste dont les controles reussissent (toutes les 6 heures s'il est connecte) ne voit pas ce bandeau, ni un
        poste hors ligne qui se reconnecte avant le debut du preavis (J10 dans l'exemple). Le bandeau ne commence jamais
        dans les 7 heures qui suivent un controle reussi (controle suivant a 6 heures, plus une heure de marge) : avec une
        tolerance de 0 ({{TOLERANCE_MIN_H}} heures), il n'y a donc aucun bandeau, et un poste qui n'a pas pu se controler
        passe directement de "valide" a "bloque" au bout de {{TOLERANCE_MIN_H}} heures. Une modification de la tolerance
        n'atteint le poste qu'a son controle reussi suivant. Si l'echeance et la fin de tolerance approchent
        toutes les deux, le bandeau annonce la date la plus proche.</p>
        <h3>Cycle de controle</h3>
        HTML;
    $html .= aide_tableau(<<<'T'
        Moment | Controle aupres du serveur
        Lancement de l'application | 3 secondes apres
        Apres un controle reussi | 6 heures plus tard
        Apres un echec (serveur injoignable, horloge, trop de tentatives) | 15 minutes plus tard
        Demande en attente sans essai | chaque minute
        Demande en attente pendant l'essai | toutes les 6 heures
        "Verifier maintenant" (Ctrl+Maj+L ou fenetre d'activation), "Reessayer" | immediatement
        Ni cle ni demande | aucun echange avec le serveur
        T);
    $html .= <<<'HTML'
        <p>Un controle est "reussi" des que le serveur a tranche, y compris par un refus. Exemple : apres le refus d'une
        licence expiree, le controle automatique suivant n'a lieu que 6 heures plus tard ; apres une prolongation, faire
        cliquer "Reessayer" ou relancer l'application.</p>
        <h3>Horloge du poste</h3>
        <ul>
        <li>Le serveur refuse toute requete dont l'heure differe de la sienne de plus de {{ECART_MIN}} minutes : le
        controle echoue et le poste reessaie 15 minutes plus tard, sans rien afficher tant que la licence reste utilisable.
        Le message "Horloge de l'ordinateur incorrecte : corrigez la date et l'heure" se lit dans la zone Diagnostic de la
        fenetre Licence (Ctrl+Maj+L), dans la fenetre d'activation apres "Verifier maintenant" ou "Reessayer", et a la
        saisie d'une cle ou a l'envoi d'une demande. La tolerance hors ligne continue de s'ecouler pendant ce temps. Le
        premier signe visible est en general le bandeau "Licence non verifiee depuis N jour(s)...", puis le blocage.</li>
        <li>Reculer l'horloge ne permet pas de revenir plus de 48 heures avant l'heure la plus avancee que l'application a
        deja vue. L'application retient cette heure, qui avance aussi pendant qu'elle tourne, meme horloge reculee. Elle
        l'utilise des que l'horloge de l'ordinateur est en retard de plus de 48 heures sur elle ; un ecart plus faible est
        tolere. Chaque reponse du serveur recale cette heure.</li>
        </ul>
        HTML;
    return $html;
}

function aide_section_serveurs(): string
{
    return <<<'HTML'
        <p>Les applications ne dependent pas d'une seule adresse : chaque reponse du serveur contient la liste signee des URL
        diffusees, dans l'ordre de priorite (le plus petit nombre en premier). Les postes la memorisent (10 URL au plus) :
        changer d'URL ne demande ni recompilation ni redistribution. Une reponse mal signee ne remplace jamais cette
        liste.</p>
        <p>Chaque poste s'adresse d'abord a la derniere URL qui lui a repondu, tant qu'elle figure dans la liste ; si elle ne
        repond pas, il essaie les autres dans l'ordre de priorite, puis les URL inscrites dans etdel_licence.py. Un poste
        dont l'URL actuelle repond ne change donc pas d'URL a cause de la priorite.</p>
        <h3>Ecran Serveurs</h3>
        <ul>
        <li>Tableau : URL, Etat (Active ou inactive) et, pour chacune, "Priorite" (0 a 9999), case "Diffusee", bouton
        "Enregistrer". L'installation a cree l'URL principale (priorite 10) et l'URL de secours (priorite 20).</li>
        <li>La colonne Etat reprend la case Diffusee : Active = URL envoyee aux postes. En temps normal, il n'y a rien a
        faire sur cet ecran ; il sert a ajouter une adresse de secours ou a changer de domaine (procedure ci-dessous).</li>
        <li>"Ajouter une URL" : "URL de l'API (https://.../api/v1/)", en https, terminee par "/", 300 caracteres au plus ;
        "Priorite" (20 par defaut) ; bouton "Ajouter". La console ne verifie pas que l'URL repond : la tester avant.</li>
        <li>Une URL ne se corrige pas et ne se supprime pas : on la retire de la diffusion en decochant "Diffusee". La
        derniere URL diffusee ne peut pas etre retiree ("Au moins une URL doit rester diffusee.").</li>
        <li>Une URL qui redirige (http vers https, autre domaine) est vue comme injoignable par les postes : declarer
        l'adresse finale exacte.</li>
        <li>La premiere URL diffusee sert aussi pour l'adresse expeditrice par defaut (licences@ suivi de son domaine), pour
        le lien des e-mails (sauf si url_console est renseigne dans config.php) et pour la ligne LICENCE_URL de l'ecran Cles
        (la deuxieme pour LICENCE_URL_SECOURS).</li>
        </ul>
        <h3>Migration vers un nouveau domaine, pas a pas</h3>
        <ol>
        <li>OVH, Multisite : faire pointer le nouveau domaine sur le meme dossier licence, SSL coche. Verifier que
        https://NOUVEAU-DOMAINE/install.php repond "Installation deja effectuee".</li>
        <li>Serveurs, "Ajouter une URL" : https://NOUVEAU-DOMAINE/api/v1/ avec une priorite plus petite que l'actuelle
        (moins de 10) : elle passe en tete de la liste et les postes l'apprennent a leur controle suivant, mais ils
        continuent d'utiliser l'ancienne URL tant qu'elle est diffusee et repond.</li>
        <li>Attendre que les postes se soient connectes, au moins la duree de la tolerance hors ligne (15 jours par defaut).
        La section "Suivi d'une migration" donne le nombre de postes actifs vus depuis 24 h, 7 jours et 30 jours, sur le
        nombre de postes lies ; la colonne Dernier contact (Licences) donne le detail.</li>
        <li>Reglages : changer l'adresse expeditrice si elle est sur l'ancien domaine.</li>
        <li>Decocher "Diffusee" pour l'ancienne URL. Chaque poste recoit alors, par l'ancienne URL, la liste sans elle, et
        passe sur la nouvelle des son controle suivant. Laisser l'ancien domaine en service encore un moment, puis le
        couper.</li>
        </ol>
        <h3>Changer d'hebergeur</h3>
        <p>Changer d'hebergeur (nouveau domaine) demande plus de precautions, car chaque hebergement a sa propre base : ce
        qui est fait dans une console n'apparait pas dans l'autre.</p>
        <ol>
        <li>Envoyer serveur/www/ sur le nouvel hebergement, sans prive/ pour l'instant : tant que prive/ manque, la
        nouvelle URL repond 503 et les postes passent a l'URL suivante.</li>
        <li>Dans l'ancienne console, ajouter la nouvelle URL (etapes 2 et 3 ci-dessus) et continuer d'y travailler pendant
        l'attente.</li>
        <li>Pour basculer : dans l'ancienne console, decocher "Diffusee" pour toutes les URL de l'ancien hebergement
        (principale et secours), puis "Telecharger une copie de la base".</li>
        <li>Envoyer sur le nouvel hebergement tout le dossier prive/ de l'ancien (cles, secret des demandes, config.php,
        .htpasswd, install.verrou), en mettant cette copie a la place de prive/data/licenses.db (sans licenses.db-wal ni
        licenses.db-shm). Remplacer admin/.htaccess (celui du depot ferme la console) par la copie faite a l'installation,
        en corrigeant sa ligne AuthUserFile : elle contient le chemin complet de prive/.htpasswd sur l'ancien hebergement.
        Verifier que https://NOUVEAU-DOMAINE/install.php repond "Installation deja effectuee" et que la console s'ouvre.
        Recreer la tache planifiee de sauvegarde.</li>
        <li>Ne plus utiliser que la nouvelle console ; laisser l'ancien hebergement en service quelques jours, puis le
        couper.</li>
        </ol>
        <p>Sans les cles d'origine, les postes rejetteraient les reponses du nouveau serveur.</p>
        HTML;
}

function aide_section_cles(): string
{
    return <<<'HTML'
        <p>Toutes les reponses du serveur sont signees ; les applications verifient cette signature avec la cle publique.
        Sans la cle privee, personne ne peut fabriquer une reponse acceptee par les applications.</p>
        <h3>Ecran Cles</h3>
        <ul>
        <li>"Cle publique active (kid N)" avec un bouton "Copier".</li>
        <li>"Lignes a reporter une fois en tete de etdel_licence.py :" LICENCE_URL, LICENCE_URL_SECOURS et
        LICENCE_CLE_PUBLIQUE, avec un bouton "Copier". A faire une seule fois dans le module, avant de livrer : sans ces
        lignes, le module ne fait rien et l'application fonctionne sans licence.</li>
        <li>"Historique" : kid, cle publique, active depuis, retiree le (avec la date jusqu'a laquelle les postes
        l'acceptent encore), bulletin ("premiere cle" ou "signe par la cle precedente").</li>
        </ul>
        <h3>Rotation</h3>
        <p>A faire seulement en cas de doute sur la confidentialite de l'hebergement (mot de passe FTP divulgue, ligne
        DANGER au controle d'exposition, acces suspect), apres avoir repris la main (mots de passe OVH et FTP changes).</p>
        <ol>
        <li>Cles, bouton rouge "Nouvelle cle de signature", confirmer "Generer une nouvelle cle de signature (rotation,
        irreversible)".</li>
        <li>Le serveur cree une nouvelle paire, annonce la nouvelle cle par un bulletin signe avec l'ancienne, puis efface
        l'ancienne cle privee. Message : "Nouvelle cle de signature active : les postes l'adoptent a leur prochain
        controle."</li>
        <li>Aussitot : refaire par FTP la copie de secours de prive/cles/ (l'ancienne copie ne contient pas la nouvelle cle
        privee). Ne jamais supprimer les fichiers signature_N.json.</li>
        </ol>
        <p>Consequences : les applications deja livrees adoptent la nouvelle cle a leur connexion suivante, sans mise a jour,
        et acceptent encore l'ancienne pendant 90 jours ; une rotation apres une fuite ne neutralise donc pas l'ancienne
        cle immediatement. Les bulletins sont diffuses pendant 12 mois : un poste reste hors ligne plus longtemps devra
        recevoir une version a jour de l'application. La rotation est irreversible.</p>
        <p>Apres une rotation, reporter la nouvelle ligne LICENCE_CLE_PUBLIQUE (ci-dessus) dans etdel_licence.py pour toute
        version livree ensuite. Un executable qui embarque une cle remplacee ne s'active sur un nouveau poste (ou sur un
        poste qui a perdu ses fichiers de licence) que pendant les 12 mois de diffusion du bulletin. Au-dela, il rejette
        toutes les reponses ("reponse invalide ou mal signee" dans le diagnostic), affiche "Serveur de licences
        injoignable" et ne peut ni activer une cle ni envoyer une demande.</p>
        HTML;
}

function aide_section_journal(): string
{
    return <<<'HTML'
        <h3>Ce qui est inscrit</h3>
        <ul>
        <li>Les actions de la console, avec votre identifiant de connexion et l'IP de votre navigateur :
        demande_acceptee, demande_refusee, licence_creee, licence_prolongee, licence_modifiee, licence_suspendue,
        licence_reactivee, licence_revoquee, poste_libere, produit_cree, produit_modifie, distribution_creee,
        distribution_modifiee, distribution_dupliquee, url_ajoutee, url_modifiee, reglages, email_test, mot_de_passe,
        cle_rotation, sauvegarde_telechargee. licence_suspendue a pour detail "active -> suspendue, sans date de fin" ou
        "active -> suspendue, jusqu'au JJ/MM/AAAA" ; "Changer la date de fin" inscrit aussi licence_suspendue ("suspendue
        -> suspendue, ..."). Inscrite avec votre identifiant, licence_reactivee correspond a un clic sur "Reactiver".</li>
        <li>Les evenements des postes (acteur "poste:XXXX-XXXX") : demande, activation (premiere liaison d'une cle a un
        ordinateur), refus_autre_poste (cle saisie sur un autre ordinateur, avec le nom de celui-ci).</li>
        <li>Le serveur (acteur "systeme") : licence_reactivee (fin d'une suspension datee : a la date choisie, la licence
        redevient active d'elle-meme ; detail "fin de la suspension programmee"), email_envoye, email_echec,
        email_plafond, sauvegarde, cles_resynchronisees.</li>
        <li>L'assistant d'installation : installation, mot_de_passe_reinitialise.</li>
        <li>Un controle reussi d'un poste n'est pas inscrit : pour savoir quand un poste s'est connecte, lire "Dernier
        contact" sur la licence.</li>
        </ul>
        <h3>Consultation</h3>
        <p>"Recherche" (dans l'acteur, la cible, le detail et l'IP) et "Action" ("toutes" ou une action presente au
        journal), bouton "Filtrer". Le nombre d'evenements s'affiche, puis {{PAR_PAGE}} evenements par page, du plus recent
        au plus ancien, avec les liens "Plus recents" et "Plus anciens". La fiche d'une licence montre aussi ses 50 derniers
        evenements.</p>
        <h3>Exports CSV</h3>
        <ul>
        <li>"Exporter (CSV)" (Journal) : applique les filtres en cours, du plus ancien au plus recent.</li>
        <li>"Exporter les licences (CSV)" (Licences) : toutes les licences, quels que soient les filtres de l'ecran ; statut
        calcule (expiree compris) ; colonne options remplie seulement pour une surcharge.</li>
        <li>"Exporter les demandes (CSV)" (Demandes) : toutes les demandes, par numero.</li>
        <li>Format : separateur point-virgule, UTF-8 avec BOM (le fichier s'ouvre directement dans un tableur francais),
        dates AAAA-MM-JJ HH:MM, nom du fichier licences-AAAAMMJJ-HHMM.csv (ou demandes-, journal-). Une cellule qui commence
        par =, +, - ou @ est precedee d'une apostrophe : c'est voulu, aucune formule ne s'execute.</li>
        <li>Consulter un ecran ou exporter ne demande pas de confirmation et n'est pas inscrit au journal.</li>
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
        <p>Chaque execution cree une copie coherente de la base, prive/data/sauvegardes/licenses-AAAAMMJJ-HHMMSS.db (heure
        UTC dans le nom), et ne garde que les {{SAUVEGARDES}} plus recentes (reglage sauvegardes_conservees de config.php).
        Elle est inscrite au journal (action sauvegarde). L'ecran Sauvegarde affiche la taille de la base et les 10
        dernieres sauvegardes ; "Aucune sauvegarde : verifier la tache planifiee." signale une tache absente ou en
        echec.</p>
        <h3>Copie a telecharger</h3>
        <p>Au moins une fois par semaine : "Telecharger une copie de la base", confirmer. Le fichier recu se nomme
        licenses-AAAAMMJJ-HHMM.db (heure de {{FUSEAU}}). Les sauvegardes quotidiennes restent sur l'hebergement : seule
        cette copie protege contre la perte de l'hebergement. Elle contient des donnees personnelles (titulaires, e-mails,
        IP, empreintes des postes), les hachages des cles et, chiffrees, les cles des demandes acceptees pas encore
        recuperees : la ranger en lieu sur. Elle ne contient jamais de cle de licence en clair ni la cle privee de
        signature. Le telechargement est inscrit au journal (sauvegarde_telechargee).</p>
        <h3>Restauration (par FTP)</h3>
        <ol>
        <li>Renommer prive/data/licenses.db (par securite) ; supprimer prive/data/licenses.db-wal et
        prive/data/licenses.db-shm s'ils existent.</li>
        <li>Envoyer la copie choisie sous le nom prive/data/licenses.db.</li>
        <li>Ouvrir la console et verifier les listes.</li>
        </ol>
        <p>Tout ce qui a ete fait apres la date de la copie disparait : licences creees ou acceptees, demandes, revocations,
        suspensions, prolongations, journal. Les postes dont la licence n'existe plus recoivent "Cle invalide" et effacent
        leur cle : leur creer une nouvelle cle. Les postes dont la demande n'existe plus reviennent a "Licence requise".
        Une licence revoquee apres la date de la copie redevient active : la revoquer de nouveau. Une cle activee pour la
        premiere fois apres la date de la copie redevient non liee : au controle suivant, son poste affiche "Ce poste n'est
        plus autorise pour cette licence" et efface sa cle ; lui faire ressaisir la meme cle par "J'ai une cle". Un
        "Liberer le poste" fait apres la date de la copie est annule : le nouvel ordinateur affiche "Cle deja utilisee sur
        un autre ordinateur" et efface sa cle ; refaire "Liberer le poste", puis faire ressaisir la cle. Une suspension
        decidee apres la date de la copie est annulee : la refaire. Une copie anterieure a une rotation de cle se recale
        d'elle-meme grace aux fichiers signature_N.json (journal : cles_resynchronisees).</p>
        <h3>Ce qui n'est pas dans la base : copie de secours hors ligne</h3>
        <p>A telecharger par FTP apres l'installation et a ranger hors ligne (cle USB chiffree, coffre de mots de passe),
        jamais dans un depot Git :</p>
        <ul>
        <li>prive/config.php (jetons et parametres) ;</li>
        <li>prive/.htpasswd (acces a la console) ;</li>
        <li>prive/install.verrou (verrou de l'assistant : sans lui, install.php repond "Installation impossible" au lieu de
        "Installation deja effectuee", et la procedure Mot de passe perdu ne s'ouvre plus ; a defaut de copie, recreer par
        FTP un fichier de ce nom, au contenu indifferent) ;</li>
        <li>prive/reinitialisation.utilisee s'il existe (jetons de reinitialisation deja utilises) ;</li>
        <li>prive/cles/ : cles privees de signature (signature_N.key), fiches publiques (signature_N.json) et secret des
        demandes (secret_demandes.key). A refaire apres chaque rotation ;</li>
        <li>admin/.htaccess genere par l'installation (celui du depot ferme la console).</li>
        </ul>
        <p>En cas de reconstruction complete de l'hebergement, remettre la base et ces fichiers. Sans les cles de signature,
        les applications rejetteraient le serveur ; sans le secret des demandes, les cles des demandes acceptees ne
        pourraient plus etre remises.</p>
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
        le domaine du serveur limite le classement en indesirable. Vide, elle vaut licences@ suivi du domaine de la
        premiere URL diffusee.</li>
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
        jeton, l'identifiant (le debut de chaque ligne de prive/.htpasswd, avant les deux-points), le nouveau mot de passe
        et sa confirmation.</li>
        <li>Apres "Mot de passe reinitialise", remettre <code>jeton_reinitialisation</code> a vide. Chaque jeton ne sert
        qu'une fois. Si install.php repond seulement "Installation deja effectuee", le jeton est trop court, identique au
        jeton d'installation ou deja utilise.</li>
        </ol>
        <h3>Serveur</h3>
        <p>La section "Serveur" affiche la version du serveur ({{VERSION_SERVEUR}}), la version de l'API ({{VERSION_API}}),
        les versions de PHP et de SQLite et l'utilisateur connecte. Les autres parametres (fuseau horaire, plafond
        d'e-mails, nombre de sauvegardes conservees, adresse de la console dans les e-mails) se reglent dans
        prive/config.php, par FTP.</p>
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
        ecriture demande une confirmation : boite du navigateur, ou, sans JavaScript, page "Confirmation" avec
        "Confirmer : ..." et "Annuler". Seul "Changer le mot de passe" ne passe pas par cette page (la double saisie en
        tient lieu). Libelles des confirmations :
        {{CONFIRMATIONS}}</li>
        <li><strong>Rien n'est telechargeable</strong> : le dossier prive/ (base, cles, configuration, sauvegardes) est
        refuse par le serveur web ; le controle d'exposition du tableau de bord le verifie. Variante plus sure : placer
        prive/ a cote de licence/ et non dedans.</li>
        <li><strong>Cles de licence</strong> : le serveur n'en garde que le hachage et les 4 derniers caracteres ; la cle
        d'une demande acceptee est conservee chiffree jusqu'a sa remise au poste, puis effacee.</li>
        <li><strong>Reponses signees</strong> : toutes les reponses aux applications sont signees, refus compris ; les
        requetes sont horodatees ({{ECART_MIN}} minutes d'ecart au plus).</li>
        <li><strong>Limites par adresse IP</strong> (IPv4, ou prefixe /64 en IPv6), en fenetres fixes, tentatives refusees
        comprises : {{LIMITES}}. Au-dela, l'application affiche "Trop de tentatives : reessayez plus tard". Les ordinateurs
        d'un meme bureau derriere la meme box partagent ces limites.</li>
        <li><strong>Pages de la console</strong> : aucune ressource externe, aucun script ni style ecrit dans la page
        (politique de securite du contenu stricte), aucune mise en cache.</li>
        <li>La securite repose aussi sur le compte OVH : qui controle l'hebergement peut signer. Mot de passe fort et double
        authentification sur l'espace client.</li>
        </ul>
        HTML;
}

function aide_section_depannage(): string
{
    $html = <<<'HTML'
        <h3>Questions frequentes</h3>
        <details>
        <summary>Une licence revoquee peut-elle etre reactivee ?</summary>
        <p>Non. La revocation est definitive : "Reactiver" ne s'applique qu'a une licence suspendue, la fiche d'une licence
        revoquee n'a plus aucun bouton, et la meme cle ressaisie est toujours refusee ("Licence revoquee"). La licence reste
        visible (filtre "revoquees", journal, exports). Pour remettre ce poste en service, deux voies :</p>
        <ul>
        <li>creer une nouvelle cle (Licences, "Creer une cle") et la transmettre ; l'utilisateur la saisit par "J'ai une
        cle" dans la fenetre "Licence requise" qui s'ouvre au lancement ;</li>
        <li>ou laisser l'utilisateur envoyer une nouvelle demande ("Demander une licence") et l'accepter. Pas de nouvel
        essai si ce poste en a deja eu un pour ce produit : l'application reste bloquee sur "Demande en attente" jusqu'a
        votre decision (un poste qui n'en a jamais eu en obtient un, si la distribution en prevoit).</li>
        </ul>
        <p>Pour une coupure temporaire, utiliser "Suspendre", avec ou sans date de fin ("Jusqu'au (facultatif)") : le poste
        garde sa cle et se debloque seul a la reactivation ou a la date de fin, sans rien ressaisir, meme pour une licence
        obtenue par demande. Pour une licence datee, "Fixer la date" a une echeance proche convient aussi (voir
        <a href="#etats">Suspendre, revoquer, liberer</a>). La copie de la cle faite a l'acceptation d'une demande ne sert
        que si la cle doit etre ressaisie sur un poste, par exemple apres "Liberer le poste" (changement d'ordinateur) ou
        sur un poste qui a perdu ses fichiers de licence ; elle ne permet jamais de reutiliser une licence revoquee.</p>
        </details>
        <details>
        <summary>Un client change d'ordinateur : que faire ?</summary>
        <p>Licences, ouvrir la licence, "Liberer le poste", confirmer. Le client saisit ensuite la meme cle sur le nouvel
        ordinateur par "J'ai une cle" ; elle se lie a ce nouvel ordinateur. L'ancien poste efface sa cle a son controle
        suivant ("Ce poste n'est plus autorise pour cette licence"). Licence obtenue par demande : le client ne connait pas
        sa cle ; sans la copie faite a l'acceptation, creer une nouvelle cle pour le nouvel ordinateur (puis revoquer
        l'ancienne licence), ou laisser le client faire une nouvelle demande depuis le nouvel ordinateur. Licence
        suspendue : la cle est refusee sur le nouvel ordinateur ("Licence suspendue") jusqu'a "Reactiver" ou la date de
        fin.</p>
        </details>
        <details>
        <summary>Le client a renomme son ordinateur : faut-il faire quelque chose ?</summary>
        <p>Non. Le nom de l'ordinateur n'est qu'un affichage, mis a jour dans la console a son controle suivant ;
        l'identifiant du poste et la licence ne changent pas.</p>
        </details>
        <details>
        <summary>Le client voit "Cle deja utilisee sur un autre ordinateur".</summary>
        <p>La cle est liee a un autre poste : soit elle est saisie sur un second ordinateur (une cle = un poste), soit le
        meme ordinateur a change d'empreinte (Windows reinstalle, ou volume systeme C: reformate). La tentative est
        visible dans l'historique de la licence (refus_autre_poste, avec le nom de l'ordinateur). Si c'est legitime :
        "Liberer le poste", puis faire ressaisir la cle. Sinon, chaque ordinateur a besoin de sa propre cle.</p>
        </details>
        <details>
        <summary>Le client voit "Serveur de licences injoignable : verifiez la connexion Internet".</summary>
        <p>Verifier dans l'ordre : la connexion Internet du poste (pare-feu, proxy d'entreprise) ; que l'URL de l'API repond
        en https (certificat SSL du sous-domaine actif chez OVH) ; que l'URL diffusee ne redirige pas ; que l'API ne repond
        pas 503 (voir plus bas). Demander la ligne de diagnostic (Ctrl+Maj+L) : elle donne la cause pour chaque URL essayee
        (nom d'erreur reseau, "http 503" ou "reponse invalide ou mal signee"). Tant que la tolerance hors ligne n'est pas
        epuisee, l'application continue de fonctionner.</p>
        </details>
        <details>
        <summary>L'horloge du poste est decalee.</summary>
        <p>Au-dela de {{ECART_MIN}} minutes d'ecart avec le serveur, tout est refuse, meme connecte, et le poste reessaie
        toutes les 15 minutes. Rien ne s'affiche de soi-meme tant que la licence reste utilisable : le message "Horloge de
        l'ordinateur incorrecte : corrigez la date et l'heure" se lit dans le diagnostic (Ctrl+Maj+L), apres "Verifier
        maintenant" ou "Reessayer", et a la saisie d'une cle ou a l'envoi d'une demande. Faire corriger la date, l'heure et
        le fuseau de Windows (reglage automatique), puis cliquer "Verifier maintenant". Pendant ce temps, la tolerance hors
        ligne s'ecoule : le premier signe visible est en general le bandeau "Licence non verifiee depuis N
        jour(s)...".</p>
        </details>
        <details>
        <summary>Le client voit "Trop de tentatives : reessayez plus tard".</summary>
        <p>Une limite par adresse IP est depassee : {{LIMITES}}. Les ordinateurs d'un meme bureau derriere la meme box
        partagent ces limites, et des essais repetes de cle erronee consomment aussi le quota. Attendre l'heure suivante
        (ou le lendemain, a partir de minuit UTC, pour les demandes) ; le poste reessaie seul 15 minutes plus tard. Pour
        equiper plusieurs postes d'un meme site, preferer des cles creees dans la console.</p>
        </details>
        <details>
        <summary>Apres une reinstallation, le client voit "Une demande est deja en attente pour ce poste".</summary>
        <p>Sa premiere demande attend toujours dans l'ecran Demandes, mais l'application ne peut plus la suivre (ses
        fichiers de licence ont ete perdus : fichiers supprimes, autre compte Windows). La traiter : l'accepter, puis
        transmettre au client la cle affichee a l'acceptation, a saisir par "J'ai une cle" (elle ne lui parviendra pas
        automatiquement) ; ou la refuser, et le client peut alors envoyer une nouvelle demande (pas de nouvel essai si ce
        poste en a deja eu un pour ce produit).</p>
        </details>
        <details>
        <summary>Je ne recois pas les e-mails de nouvelle demande.</summary>
        <p>Reglages : verifier les adresses, "Enregistrer", puis "Envoyer un e-mail de test". Journal : rechercher les
        actions email_echec (envoi refuse par l'hebergement) et email_plafond (plafond de {{EMAILS_JOUR}} e-mails par jour
        atteint). Regarder le dossier des indesirables et utiliser une adresse expeditrice sur le domaine du serveur. Sans
        adresse de notification valide, rien n'est envoye ni inscrit au journal. Les demandes restent toujours visibles dans
        l'ecran Demandes (pastille du menu).</p>
        </details>
        <details>
        <summary>La console affiche "Formulaire expire ou invalide : rechargez la page puis recommencez."</summary>
        <p>Le jeton du formulaire a change : une autre ecriture a eu lieu depuis l'affichage de la page (autre onglet), la
        page a ete renvoyee (touche F5, retour arriere apres une action), ou le navigateur a ete ferme. Recharger la page
        sans renvoyer le formulaire, puis recommencer. Apres une erreur ("Action impossible"), le lien "Retour" permet en
        revanche de corriger la saisie et de renvoyer le formulaire.</p>
        </details>
        <details>
        <summary>Une ligne affiche DANGER ou "non verifie" dans le controle d'exposition.</summary>
        <p>DANGER : le fichier est servi par le web. Verifier par FTP que licence/.htaccess et licence/prive/.htaccess sont
        bien presents (fichiers caches, souvent oublies a l'envoi), ou deplacer tout le dossier prive/ d'un bloc a cote de
        licence/. Si le fichier a pu etre telecharge, considerer les secrets comme divulgues : changer les mots de passe
        OVH, FTP et de la console, puis faire une rotation de cle (voir <a href="#cles">Cles de signature</a>).</p>
        <p>"non verifie" : le navigateur n'a pas obtenu de reponse probante. Ouvrir l'URL indiquee dans une fenetre privee :
        elle doit etre refusee (erreur 403 ou 404).</p>
        </details>
        <details>
        <summary>Le client a perdu sa cle, ou je dois retrouver une cle.</summary>
        <p>Impossible : le serveur n'en garde que le hachage et les 4 derniers caracteres (utiles pour la retrouver dans la
        liste). Si le poste fonctionne, rien a faire : l'application conserve la cle. Sinon, creer une nouvelle cle et, si
        besoin, revoquer l'ancienne licence.</p>
        </details>
        <details>
        <summary>Une licence a expire : comment debloquer le client ?</summary>
        <p>Prolonger ("+30 jours", "+1 an" ou "Fixer la date"). Le poste a garde sa cle : faire cliquer "Reessayer" dans la
        fenetre "Licence a verifier", ou relancer l'application. Sans cela, le controle automatique n'a lieu que 6 heures
        apres le dernier refus.</p>
        </details>
        <details>
        <summary>Une demande acceptee n'arrive pas sur le poste.</summary>
        <p>Sans essai, l'application verifie chaque minute et sa fenetre se ferme seule. Pendant un essai, elle ne verifie
        que toutes les 6 heures : faire relancer l'application ou cliquer "Verifier maintenant" (Ctrl+Maj+L). Si rien
        n'arrive, verifier la connexion du poste (diagnostic). Si la licence creee a ete suspendue entre-temps, la cle
        n'arrive qu'apres "Reactiver" ou a la date de fin de la suspension ; d'ici la, le poste reste sur "Demande en
        attente" (ou en essai jusqu'a la fin de celui-ci). Si elle a ete revoquee, transmettre une nouvelle cle, a saisir
        par "J'ai une cle".</p>
        </details>
        <details>
        <summary>Le client voit "Cle invalide".</summary>
        <p>"Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)" : faute de frappe detectee par la somme de
        controle, sans contacter le serveur. "Cle invalide" seul : le serveur ne connait pas cette cle pour cette
        application : cle d'une autre distribution (verifier la distribution de l'executable du client), cle jamais creee,
        base restauree d'avant sa creation, ou deux caracteres inverses a la saisie (la somme de controle ne le detecte pas
        toujours : faire relire la cle). Sinon, creer une cle dans la bonne distribution.</p>
        </details>
        <details>
        <summary>Comment couper temporairement l'acces d'un client ?</summary>
        <p>"Suspendre" (Licences, fiche de la licence, section "Etat"), en remplissant au besoin "Jusqu'au (facultatif)" :
        le poste est bloque a sa connexion suivante et garde sa cle. Si la fin est connue, la saisir : c'est le dernier jour
        suspendu, et la licence redevient active d'elle-meme a la fin de ce jour (23:59:59, fuseau {{FUSEAU}} ; journal :
        licence_reactivee par "systeme"), y compris une licence perpetuelle. Sans date, elle reste suspendue jusqu'a
        "Reactiver", qui peut aussi servir avant la date. Tant qu'elle est suspendue, sa fiche propose "Reactiver" et
        "Changer la date de fin" ("Nouvelle date de fin (vide = sans date)") pour changer ou supprimer la date.</p>
        <p>Le poste se debloque seul a son controle suivant (au lancement, puis toutes les 6 heures ; tout de suite par
        "Reessayer"), sans rien ressaisir, meme pour une licence obtenue par demande ; un poste hors ligne reste bloque
        jusqu'a sa reconnexion. Pour une licence datee, autre possibilite : "Fixer la date" a une echeance proche, puis
        prolonger ; le poste apprend la nouvelle echeance a son controle suivant, puis se bloque a cette date meme sans
        Internet. Ne jamais revoquer pour une coupure temporaire : c'est definitif.</p>
        </details>
        <details>
        <summary>La date de fin de la suspension est passee mais le client est toujours bloque.</summary>
        <p>La suspension couvre toute la journee indiquee : la licence redevient active a la fin de ce jour. Le poste doit
        ensuite joindre le serveur : faire cliquer "Reessayer" ou relancer l'application, avec Internet ; sinon, le controle
        automatique n'a lieu que 6 heures apres le dernier refus. Verifier sur la fiche que la licence est "Active" ; si
        elle est "Expiree" (echeance passee pendant la suspension), la prolonger ; si elle est encore "Suspendue", lire la
        date affichee a cote ("(sans date de fin)" : seul "Reactiver" la leve).</p>
        </details>
        <details>
        <summary>Le client travaille longtemps sans Internet.</summary>
        <p>Augmenter la tolerance hors ligne de sa licence ("Modifier") ou de la distribution, jusqu'a 365 jours. Le
        changement n'atteint le poste qu'a son controle reussi suivant : le faire connecter une fois. Pendant cette
        tolerance, une suspension ou une revocation ne l'atteint pas non plus.</p>
        </details>
        <details>
        <summary>Passer une licence de perpetuelle a datee, ou l'inverse.</summary>
        <p>Impossible sur la meme licence.</p>
        <ol>
        <li>Creer une nouvelle cle avec la bonne duree et la transmettre au client.</li>
        <li>Revoquer l'ancienne licence.</li>
        <li>Faire cliquer "Verifier maintenant" (Ctrl+Maj+L) ou relancer l'application : message "Licence revoquee", puis
        fermeture apres OK.</li>
        <li>Relancer l'application : fenetre "Licence requise", "J'ai une cle", saisir la nouvelle cle, "Activer".</li>
        </ol>
        <p>L'application ne permet pas de saisir une cle tant qu'une licence fonctionne : convenir du moment avec le
        client.</p>
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
        <p>Base absente, cle privee de signature ou secret des demandes illisible, ou dossier prive/ introuvable. Verifier
        par FTP prive/data/ et prive/cles/ (droits d'ecriture du compte) et les journaux d'erreurs PHP de l'espace client
        OVH. Apres une restauration ou une reconstruction, prive/cles/ doit contenir la cle privee active et toutes les
        fiches signature_N.json.</p>
        </details>
        <details>
        <summary>J'ai perdu le mot de passe de la console.</summary>
        <p>Voir <a href="#reglages">Mot de passe perdu</a> : un jeton a inscrire dans config.php par FTP, puis install.php.</p>
        </details>
        <h3 id="codes">Codes de refus</h3>
        <p>Codes renvoyes par le serveur aux applications (ils apparaissent dans le diagnostic et le journal du poste).</p>
        HTML;
    $html .= aide_tableau(<<<'T'
        Code | Situation | Ce que voit l'utilisateur | Que faire
        cle_invalide | cle mal formee, inconnue du serveur, ou d'une autre distribution | "Cle invalide" ; si la cle etait deja enregistree, elle est effacee | verifier la distribution de l'executable ; creer une cle dans la bonne distribution
        revoquee | licence revoquee | "Licence revoquee" ; cle effacee | nouvelle cle ou nouvelle demande (la revocation est definitive)
        suspendue | licence suspendue, avec ou sans date de fin (la date est transmise au poste) | au controle : fenetre "Licence suspendue" avec "Licence suspendue jusqu'au JJ/MM/AAAA. Elle sera reactivee automatiquement a cette date (Reessayer)." ou "Licence suspendue. Contactez ETDEL pour la reactiver." ; en cours d'utilisation, ce message puis fermeture ; cle conservee, poste bloque meme hors ligne. A la saisie par "J'ai une cle" : "Licence suspendue", la cle saisie n'est pas enregistree (la ressaisir apres la fin de la suspension si le poste ne l'avait pas deja) | "Reactiver", ou attendre la date de fin (modifiable par "Changer la date de fin") ; le poste se debloque seul a son controle suivant (au lancement, puis toutes les 6 heures ; tout de suite par "Reessayer"), sans ressaisie
        poste_revoque | "Liberer le poste" a ete fait : la cle n'est plus liee a ce poste | "Ce poste n'est plus autorise pour cette licence" ; cle effacee | normal apres un changement d'ordinateur ; sinon faire ressaisir la cle
        cle_liee_autre_poste | cle deja liee a un autre ordinateur, ou empreinte du poste changee | "Cle deja utilisee sur un autre ordinateur" ; cle effacee | "Liberer le poste" si c'est legitime, sinon une autre cle
        expiree | echeance atteinte | "Licence expiree. Contactez ETDEL pour la renouveler." ; cle conservee | prolonger, puis "Reessayer"
        version_trop_ancienne | version de l'application inferieure a la version minimale | "Mise a jour necessaire", "Version trop ancienne : mettez l'application a jour" ; cle conservee | installer une version a jour, ou baisser la version minimale
        produit_inconnu | distribution inexistante, rattachee a un autre produit, ou desactivee (elle ou son produit) | "Produit ou distribution inconnu du serveur de licences" ; cle conservee | reactiver la distribution ou le produit ; verifier les codes de la ligne installer()
        demande_en_cours | une demande de ce poste est deja en attente pour cette distribution | "Une demande est deja en attente pour ce poste" | traiter la demande en attente
        demande_inconnue | demande introuvable (base restauree) ou cle deja remise | le poste abandonne sa demande et revient a "Licence requise" | nouvelle demande, ou nouvelle cle
        horloge | heure du poste decalee de plus de {{ECART_MIN}} minutes | "Horloge de l'ordinateur incorrecte : corrigez la date et l'heure" ; nouvel essai 15 minutes plus tard | faire corriger l'heure du poste
        trop_de_requetes | limite par adresse IP depassee | "Trop de tentatives : reessayez plus tard" ; nouvel essai 15 minutes plus tard | attendre
        requete_invalide | requete mal formee (HTTP 400) | comme "serveur injoignable" (diagnostic "http 400") ; nouvel essai 15 minutes plus tard | verifier les codes de la ligne installer() et la version du module etdel_licence.py
        indisponible, non_installe | HTTP 503 : base, cle privee ou secret illisible ; dossier prive/ introuvable | "Serveur de licences injoignable" (diagnostic "http 503") | voir "L'API repond 503" ci-dessus
        methode | HTTP 405 : URL de l'API ouverte dans un navigateur | rien (l'API ne s'ouvre pas dans un navigateur) | aucune action
        T);
    return $html;
}

function aide_section_glossaire(): string
{
    return fiche_html([
        'Cle' => 'ETDEL-XXXX-XXXX-XXXX-XXXX : 16 caracteres dont une somme de controle ; donne droit a une licence sur un '
            . 'seul poste. Le serveur n\'en garde que le hachage (SHA-256) et les 4 derniers caracteres.',
        'Poste' => 'Ordinateur sur lequel une cle est activee, reconnu par son empreinte.',
        'Empreinte' => 'Valeur calculee par l\'application a partir de l\'identifiant de Windows (MachineGuid) et du '
            . 'numero de serie du volume systeme (C:), attribue quand ce volume est formate. Elle change si Windows est '
            . 'reinstalle ou si le volume systeme est reformate (par exemple sur un disque neuf) ; un disque copie a '
            . 'l\'identique (clonage) la garde en general ; le nom de l\'ordinateur n\'y entre pas.',
        'Identifiant de poste' => 'XXXX-XXXX, tire de l\'empreinte et du code du produit, facile a dicter (jamais de I, L, '
            . 'O ni U). Different pour chaque produit sur un meme ordinateur.',
        'Titulaire' => 'Nom du client ou de l\'entreprise, affiche dans la fenetre Licence de l\'application.',
        'Distribution' => 'Variante livree d\'un produit (un client, un canal), avec ses regles. Une cle n\'est valable '
            . 'que dans sa distribution.',
        'Option' => 'Code (minuscules, chiffres, _) qui active une fonction de l\'application ; une option absente vaut '
            . '"non". Une licence suit les options de sa distribution, sauf surcharge. Le joker * active toutes les '
            . 'options, presentes et futures.',
        'Surcharge' => 'Tolerance ou options propres a une licence, qui ne suivent plus la distribution.',
        'Tolerance (hors ligne)' => 'Duree d\'utilisation sans controle reussi aupres du serveur (15 jours par defaut).',
        'Preavis' => 'Nombre de jours avant la fin de la tolerance hors ligne ou l\'application affiche un bandeau '
            . '(5 jours par defaut).',
        'Echeance' => 'Date de fin d\'une licence ; aucune pour une licence perpetuelle.',
        'Suspension' => 'Blocage temporaire d\'une licence ("Suspendre") : le poste garde sa cle et se debloque seul, a '
            . 'son controle suivant, quand la licence est reactivee ("Reactiver") ou a la date de fin choisie '
            . '("Jusqu\'au").',
        'Revocation' => 'Arret definitif d\'une licence ("Revoquer") : le poste efface sa cle et la meme cle est refusee '
            . 'pour toujours ; remise en service par une nouvelle cle ou une nouvelle demande.',
        'Essai' => 'Utilisation accordee pendant l\'attente d\'une demande, une seule fois par poste et par produit.',
        'kid' => 'Numero de la cle de signature du serveur : 1 a l\'installation, puis un de plus a chaque rotation.',
        'Bulletin' => 'Annonce d\'une nouvelle cle de signature, signee par la precedente et diffusee pendant 12 mois.',
        'Jeton' => 'Reponse signee du serveur que l\'application conserve (echeance, tolerance, options, version '
            . 'minimale). Dans la console, le jeton de formulaire protege les actions ; dans config.php, les jetons '
            . 'd\'installation et de reinitialisation ouvrent install.php.',
    ]);
}
