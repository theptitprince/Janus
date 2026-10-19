# Décisions

Choix faits pendant la réalisation, sur les points non couverts ou ambigus du cahier des charges (consigne 0.1). Chaque entrée : date, question, décision, raison.

## Organisation

### D1 — 2026-10-05 — Nom et dépôt
- **Question** : le cahier des charges nomme le dépôt `etdel-licence` ; la session de travail fournit le dépôt `theptitprince/Janus`.
- **Décision** : le projet s'appelle **Janus** (dieu romain des portes et des passages) et vit dans ce dépôt.
- **Raison** : demande « tu trouveras un nom » ; le dépôt existait déjà et c'est le seul accessible en écriture.

### D2 — 2026-10-05 — Une branche par étape
- **Question** : « une branche et un commit par étape » alors que la session n'autorise qu'une branche de travail.
- **Décision** : un commit par étape, dans l'ordre, sur une seule branche de travail.
- **Raison** : contrainte de l'environnement ; l'historique reste lisible étape par étape.

### D3 — 2026-10-05 — Fichier LICENSE existant
- **Question** : le dépôt a été créé avec une licence GPL v3 alors que le code est propriétaire (« ETDEL (c) 2026 »).
- **Décision** : fichier laissé tel quel.
- **Raison** : choix juridique qui revient au propriétaire du projet (à trancher ; tranché en D85 et D86).

## Module client

### D4 — 2026-10-05 — Constantes d'URL
- **Question** : « LICENCE_URL (URL initiale, plus une URL de secours) ».
- **Décision** : deux constantes chaînes, `LICENCE_URL` et `LICENCE_URL_SECOURS`, plus `LICENCE_CLE_PUBLIQUE`. Le module est `NON_CONFIGURE` si les deux URL sont vides ou si la clé est vide.
- **Raison** : solution la plus simple, lisible et sans liste à éditer.

### D5 — 2026-10-05 — Signature de `exiger()`
- **Question** : le texte montre `exiger(version=APP_VERSION)` sans produit ni distribution, indispensables au contrôle.
- **Décision** : `exiger(produit, distribution, version)`. Sans licence et dans un terminal interactif, la clé est demandée au clavier ; sinon message sur stderr et `sys.exit(3)`. Pas de demande de licence en mode console.
- **Raison** : une application console doit pouvoir s'activer ; la saisie clavier est la voie la plus simple et reste une action explicite.

### D6 — 2026-10-05 — Méthodes `activer()` et `demander()`
- **Question** : la fenêtre d'activation a besoin d'opérations non listées dans l'API de la Garde.
- **Décision** : `Garde.activer(cle)` et `Garde.demander(titulaire, email, message)` sont publiques, bloquantes, à appeler hors du fil de l'interface ; elles renvoient `{"ok", "code", "message"}` et ne lèvent jamais d'exception.
- **Raison** : utiles aussi pour une intégration avancée ; la fenêtre les appelle dans un fil.

### D7 — 2026-10-05 — Paramètres d'injection pour les tests
- **Question** : horloge, transport, dossiers, empreinte doivent être injectables.
- **Décision** : paramètres préfixés `_` (`_horloge`, `_transport`, `_dossiers`, `_machine`, `_urls`, `_cle_publique`, `_fil`, `_monotone`, `_garde`…). Aucun n'est documenté pour les applications.
- **Raison** : l'API publique reste celle du cahier des charges.

### D8 — 2026-10-05 — HTTP clair en boucle locale
- **Question** : HTTPS obligatoire, mais le banc d'essai tourne avec `php -S` (HTTP).
- **Décision** : HTTP accepté uniquement vers `127.0.0.1`, `localhost` et `::1` ; toute autre URL non HTTPS est refusée, y compris dans une liste signée. Les redirections HTTP ne sont pas suivies (un POST redirigé deviendrait un GET) : elles comptent comme serveur injoignable.
- **Raison** : rend possible le test de bout en bout sans affaiblir le transport réel.

### D9 — 2026-10-05 — Format de clé et identifiant de poste
- **Question** : forme exacte de la somme de contrôle et de la lecture tolérante.
- **Décision** : 16e caractère = somme des valeurs des 15 premiers modulo 32. À la saisie, `O` est lu `0`, `I` et `L` sont lus `1` (règle de Crockford). Identifiant de poste : 40 premiers bits de SHA-256(machine + produit), 8 caractères base32 de Crockford, poids fort en tête.
- **Raison** : définition la plus simple qui détecte toute faute de frappe sur un caractère.

### D10 — 2026-10-05 — Empreinte machine
- **Question** : comment combiner MachineGuid et numéro de série du volume.
- **Décision** : `machine = SHA-256("<MachineGuid en minuscules>|<numéro de série en hexadécimal sur 8 chiffres>")`. Le registre est lu en vue 64 bits (`KEY_WOW64_64KEY`) pour qu'un Python 32 bits trouve la valeur. Hors Windows (développement uniquement) : `/etc/machine-id`.
- **Raison** : forme stable et documentée ; la vue 64 bits évite une empreinte vide sur un exe 32 bits.

### D11 — 2026-10-05 — Format de l'état local
- **Question** : détail du chiffrement, du scellement et de la sélection de l'exemplaire « le plus récent ».
- **Décision** : fichier `ETDL\x01` + méthode (`D` = DPAPI portée utilisateur ; `X` = flux SHA-256 si DPAPI indisponible, c'est-à-dire hors Windows) + sceau HMAC-SHA256 + données. Clés dérivées de SHA-256(`ETDEL|machine|produit|distribution`). Un compteur `seq` incrémenté à chaque écriture désigne l'exemplaire le plus récent. Hors Windows, dossiers `~/.config/ETDEL/licences` et `~/.local/share/ETDEL/licences`. Le jeton stocké est revérifié (signature Ed25519) à chaque lancement.
- **Raison** : un compteur est insensible aux changements d'heure ; la revérification rend inutile toute modification manuelle du cache, même scellé correctement.

### D12 — 2026-10-05 — Garde anti-recul d'horloge
- **Question** : « si maintenant < heure_max − 48 h, le calcul utilise heure_max ». Appliquée seule, cette règle fige le temps tant que l'horloge reste reculée, ce qui prolongerait la tolérance indéfiniment.
- **Décision** : pendant l'exécution, `heure_max` avance aussi avec l'horloge monotone du système ; à chaque réponse signée, elle est recalée sur `max(heure locale, emis)` (horodatage signé du serveur, lié à notre nonce).
- **Raison** : respecte le critère « recul d'horloge : ne prolonge pas la tolérance » ; le recalage répare une avance accidentelle de l'horloge, qui sinon bloquerait le poste pour toujours.

### D13 — 2026-10-05 — Traitement des codes de refus
- **Question** : effet côté client des codes non décrits dans l'annexe D.
- **Décision** :
  - `revoquee`, `suspendue`, `poste_revoque`, `cle_liee_autre_poste` et `cle_invalide` (en validation) → `REVOQUEE`, clé locale effacée ;
  - `expiree` et `produit_inconnu` → `EXPIREE`, clé conservée (une prolongation ou une réactivation débloque au contrôle suivant) ;
  - `version_trop_ancienne` → `VERSION_REFUSEE` ;
  - `horloge`, `trop_de_requetes`, `requete_invalide` → transitoires (nouvel essai 15 min plus tard), statut inchangé ;
  - `poste_revoque` est le code renvoyé par `valider` quand la clé a été libérée dans la console.
- **Raison** : une clé supprimée ne doit pas être réessayée sans fin ; un blocage administratif réversible ne doit pas forcer une nouvelle saisie.

### D14 — 2026-10-05 — EXPIREE au lancement
- **Question** : l'annexe D dit « message puis fermeture » ; la section 7 prévoit une « fenêtre de licence et bouton Réessayer » quand la tolérance est épuisée.
- **Décision** : au lancement, `EXPIREE` ouvre la fenêtre de licence (« Reessayer », « J'ai une cle ») ; en cours de session, message puis fermeture. Même principe pour `VERSION_REFUSEE` (voir D44).
- **Raison** : concilie les deux passages ; un poste revenu à portée du réseau peut se débloquer sans relancer l'application.

### D15 — 2026-10-05 — Fenêtre d'activation
- **Question** : comportement de la fenêtre principale pendant l'activation ; actions disponibles en attente.
- **Décision** : la fenêtre principale est masquée (`withdraw`) tant que la fenêtre d'activation est ouverte, puis réaffichée. En attente sans essai, la fenêtre propose aussi « J'ai une cle ». Après envoi avec essai, la fenêtre affiche la confirmation et un bouton « Continuer ».
- **Raison** : l'application ne doit pas être utilisable avant la licence ; une clé peut arriver par un autre canal pendant l'attente ; la mention de délai doit rester visible après l'envoi.

### D16 — 2026-10-05 — Options pendant l'essai et sans configuration
- **Question** : que renvoie `option()` pendant l'essai, et en `NON_CONFIGURE` ?
- **Décision** : pendant l'essai, les options de la distribution, transmises signées dans les réponses `demander` et `suivre_demande` ; en `NON_CONFIGURE` et dans tout statut non utilisable, `False`.
- **Raison** : l'essai reflète l'édition demandée ; « une option absente vaut False ».

### D17 — 2026-10-05 — Demande idempotente
- **Question** : si la réponse à `demander` se perd, le poste ne connaît pas le numéro et toute nouvelle demande serait refusée (`demande_en_cours`).
- **Décision** : le jeton de demande est enregistré localement avant l'envoi et réutilisé au renvoi ; le serveur renvoie la demande en attente existante quand le jeton correspond.
- **Raison** : évite un blocage définitif sans assouplir la règle « une seule demande en attente ».

### D18 — 2026-10-05 — Après acceptation
- **Question** : la clé conservée chiffrée par le serveur est effacée « après le premier valider réussi ».
- **Décision** : dès réception de l'acceptation, le client enchaîne un `valider`.
- **Raison** : la clé ne reste pas sur le serveur jusqu'au contrôle suivant (6 h).

### D19 — 2026-10-05 — Rotation : clé embarquée et 90 jours
- **Question** : la clé embarquée n'a pas de `kid` ; sens de « l'ancienne reste acceptée 90 jours ».
- **Décision** : la clé embarquée apprend son `kid` à sa première vérification réussie. Les bulletins sont vérifiés avant la réponse qui les porte (ils sont signés par la clé précédente). Après adoption d'une nouvelle clé, l'ancienne vérifie encore de nouvelles réponses jusqu'à `valide_des` + 90 jours ; un jeton déjà stocké reste vérifiable avec elle.
- **Raison** : un poste neuf livré avec l'ancienne clé suit la chaîne de bulletins ; une clé retirée et éventuellement compromise ne peut plus annoncer de clé après 90 jours.

### D20 — 2026-10-05 — Divers client
- **Question** : points de détail.
- **Décision** :
  - le message d'accueil de la distribution s'affiche dans la fenêtre « Licence » et dans `cadre_licence()` ;
  - `diagnostic_text()` est un alias de `texte_diagnostic()` (les deux noms figurent dans le cahier des charges) ;
  - en `ESSAI`, `jours_restants` donne les jours d'essai restants ;
  - préavis d'échéance fixe de 15 jours (annexe D) ; si les deux préavis s'appliquent, le message porte sur la date la plus proche ;
  - `exiger()` ne lance pas de fil de contrôle et enregistre `arreter()` à la sortie (`atexit`) ;
  - la table de translittération est écrite avec `chr()` pour que le fichier reste en ASCII pur.
- **Raison** : solutions les plus simples compatibles avec le texte.

### D21 — 2026-10-05 — Exécution des tests Tkinter
- **Question** : l'environnement de développement n'a Tkinter que pour Python 3.12.
- **Décision** : les tests Tkinter s'exécutent sous Xvfb avec Python 3.12 ; le reste est aussi vérifié avec Python 3.10 et 3.11. Sans Tkinter, la suite échoue, sauf si `ETDEL_TESTS_SANS_TK=1` est positionné explicitement.
- **Raison** : ne jamais déclarer verte une suite qui a sauté sa partie graphique sans le dire.

## Serveur et installation

### D22 — 2026-10-05 — Fichiers ajoutés à l'arborescence de l'annexe A
- **Question** : certains éléments n'ont pas d'emplacement prévu.
- **Décision** : `prive/lib/commun.php` (configuration et outils partagés), `prive/lib/installation.php` (logique de l'assistant, testable en CLI ; `www/install.php` n'en est que l'interface), `serveur/tests/test_bout_en_bout.py` (serveur PHP réel et client Python réel), `prive/.htaccess`.
- **Raison** : garder les points d'entrée web minces et toute la logique testable hors navigateur.

### D23 — 2026-10-05 — Secret de chiffrement des clés remises par une demande
- **Question** : « secret dans config.php », alors que l'administrateur n'a aucun outil local pour générer 32 octets aléatoires.
- **Décision** : `install.php` génère le secret dans `prive/cles/secret_demandes.key` (0600, à côté des clés privées) ; une valeur `secret_demandes` (base64) dans `config.php`, si elle existe, est prioritaire.
- **Raison** : respecte « aucun utilitaire sur le poste de l'administrateur » ; même protection que les clés de signature.

### D24 — 2026-10-05 — Requête mal formée
- **Question** : aucun code de refus pour une requête illisible ou hors bornes.
- **Décision** : réponse HTTP 400, signée, code `requete_invalide`. Les champs reçus sont bornés (produit et distribution `[A-Za-z0-9_.-]{1,64}`, machine 64 hexadécimaux, poste 64, version 32, titulaire 120, e-mail 254, message 200, jeton 43) ; un champ trop long est refusé, jamais tronqué en silence. Le client traite ce code comme transitoire.
- **Raison** : « réponses toujours signées » et « champs reçus bornés en taille ».

### D25 — 2026-10-05 — Tolérance de 0 jour
- **Question** : une tolérance de 0 jour rendrait le jeton périmé dès sa réception, donc l'application inutilisable même connectée.
- **Décision** : la durée hors ligne diffusée ne descend jamais sous 7 heures (un cycle de contrôle de 6 h plus une heure).
- **Raison** : « 0 » se lit alors « pas d'usage hors ligne au-delà du contrôle suivant ».

### D26 — 2026-10-05 — Règles de contrôle côté serveur
- **Question** : ordre des contrôles et cas limites.
- **Décision** :
  - activer et valider vérifient dans l'ordre : statut (révoquée, suspendue), poste (libéré → `poste_revoque` en validation ; autre poste → `cle_liee_autre_poste`), échéance, version ; la liaison au poste n'a lieu qu'après tous ces contrôles (une clé expirée ou une application trop ancienne ne lie jamais un poste) ;
  - la liaison est atomique (`UPDATE … WHERE machine IS NULL`) : deux activations simultanées ne lient qu'un poste ;
  - version minimale effective = la plus exigeante entre produit et distribution (depuis D68 : seule la version minimale de la distribution compte ; `produits.version_min` est sans effet) ; une demande venant d'une version trop ancienne est refusée ;
  - une clé d'une autre distribution répond `cle_invalide` ;
  - `ping` n'est pas limité ; l'adresse IP retenue est `REMOTE_ADDR` (les en-têtes de type X-Forwarded-For sont falsifiables) ;
  - une seule demande en attente par poste et par distribution ; l'essai, lui, n'est accordé qu'une fois par poste et par produit, toutes distributions confondues (depuis D68 : une fois par poste et par distribution) ;
  - le journal reçoit les activations, les refus « autre poste », les demandes, les e-mails, les actions de la console ; un `valider` réussi n'y est pas inscrit (`dernier_contact` suffit).
- **Raison** : solutions les plus simples, sans trou de sécurité.

### D27 — 2026-10-05 — Assistant d'installation
- **Question** : comment protéger `/admin/` avec un chemin absolu inconnu à l'avance.
- **Décision** : le `admin/.htaccess` livré ferme la console (`Require all denied`) ; `install.php` le remplace par la protection `AuthType Basic` avec le chemin absolu réel du `.htpasswd`. Une installation qui échoue retire la base partielle pour permettre un nouvel essai ; le verrou n'est posé qu'à la fin. `.htpasswd` en 0644 (lu par Apache, mots de passe hachés), clés en 0600. Le changement de mot de passe se fait dans l'écran Réglages de la console, avec la même fonction (`password_hash`).
- **Raison** : la console n'est jamais ouverte sans mot de passe, même entre l'envoi FTP et l'installation.

### D28 — 2026-10-05 — Redirection HTTPS et sauvegarde
- **Question** : détection du HTTPS sur OVH mutualisé ; méthode de copie de la base en mode WAL.
- **Décision** : redirection quand `SERVER_PORT` vaut 80 (méthode documentée par OVH) et que `X-Forwarded-Proto` ne vaut pas `https`. Copie par `VACUUM INTO` (cohérente même en WAL), fichiers `data/sauvegardes/licenses-AAAAMMJJ-HHMMSS.db`, 30 copies conservées (réglable dans `config.php`).
- **Raison** : une simple copie du fichier pendant une écriture WAL peut être incohérente.

### D29 — 2026-10-05 — Notification par e-mail
- **Question** : détails d'envoi.
- **Décision** : `mail()` sans paramètre d'enveloppe `-f` ; adresses invalides de la liste ignorées ; objet encodé en UTF-8 (RFC 2047) s'il contient des accents ; caractères de contrôle retirés des champs venant du client ; plafond de 50 par jour compté dans la table `limites` ; lien vers la console pris dans `url_console` (config.php) ou déduit de la première URL d'API active.
- **Raison** : `-f` est refusé par certains hébergements ; pas d'injection d'en-tête possible.

### D30 — 2026-10-05 — Heure dans le test de bout en bout
- **Question** : « aucun test ne dépend de l'heure réelle », alors que `php -S` utilise l'horloge du système.
- **Décision** : les tests unitaires (client et serveur) injectent l'heure ; le test de bout en bout fait tourner client et serveur sur l'heure du système, et aucune de ses vérifications ne dépend de sa valeur.
- **Raison** : le serveur n'expose pas d'horloge réglable en production, volontairement.

## Console d'administration

### D31 — 2026-10-05 — Confirmation, CSRF et en-têtes
- **Question** : forme de la confirmation ; détails du contrôle CSRF.
- **Décision** : chaque formulaire d'écriture déclenche une boîte de confirmation (JavaScript) ; sans JavaScript, le serveur affiche une page de confirmation qui renvoie les mêmes champs. Le jeton CSRF est renouvelé après chaque écriture réussie : un formulaire renvoyé (touche F5) est refusé, ce qui garantit aussi qu'une clé n'est affichée qu'une fois. L'en-tête Origin, s'il est présent, doit correspondre à l'hôte (`null` est refusé) ; absent, le jeton suffit. `Referrer-Policy: same-origin` et non `no-referrer` : un essai dans un vrai navigateur a montré qu'avec `no-referrer`, Chrome envoie `Origin: null` sur les POST de formulaire, que le contrôle refusait. Les messages après redirection passent par un code fixe dans l'URL, jamais par une donnée.
- **Raison** : sécurité sans dépendre de JavaScript ; la session PHP ne sert qu'au jeton.

### D32 — 2026-10-05 — Utilisateur de la console
- **Question** : d'où lire l'utilisateur authentifié.
- **Décision** : `REMOTE_USER`, à défaut `REDIRECT_REMOTE_USER`, jamais `PHP_AUTH_USER` ; sans utilisateur, la console répond 403 même si le `.htaccess` n'est pas appliqué.
- **Raison** : PHP remplit `PHP_AUTH_USER` depuis l'en-tête envoyé par le client, même quand Apache n'a rien vérifié.

### D33 — 2026-10-05 — Règles des actions sur les licences
- **Question** : transitions et calculs non précisés.
- **Décision** :
  - « réactiver » ne s'applique qu'à une licence suspendue ; la révocation est définitive ;
  - « libérer le poste » efface l'empreinte, l'identifiant, le nom de l'ordinateur et la date de liaison (l'ancien poste est inscrit au journal) ;
  - « prolonger » part de l'échéance, ou d'aujourd'hui si elle est passée ; la date libre fixe l'échéance à 23 h 59 min 59 s (fuseau de `config.php`) ; une licence perpétuelle ne se prolonge pas ;
  - à la création d'une clé, la durée est préremplie avec la durée par défaut de la distribution ; vide = perpétuelle ;
  - à l'acceptation d'une demande, des options identiques à celles de la distribution ne créent pas de surcharge.
- **Raison** : comportements les plus prévisibles pour l'administrateur.

### D34 — 2026-10-05 — Produits, distributions et URL
- **Question** : modification des codes ; liste d'URL vide.
- **Décision** : les codes de produit et de distribution ne sont plus modifiables après création (ils sont inscrits dans les applications) ; désactiver se fait par la case « Actif ». La console refuse de retirer la dernière URL diffusée. L'écran Serveurs affiche le nombre de postes vus depuis 24 h, 7 et 30 jours pour suivre une migration.
- **Raison** : éviter de casser des applications livrées par une simple saisie.

### D35 — 2026-10-05 — Exports et sauvegarde
- **Question** : format CSV ; copie téléchargée.
- **Décision** : séparateur point-virgule et BOM UTF-8 (ouverture directe dans un tableur français) ; une cellule commençant par `=`, `+`, `-` ou `@` est préfixée d'une apostrophe (pas de formule exécutée). La copie téléchargée est faite par `VACUUM INTO` dans le dossier des sauvegardes, envoyée puis supprimée ; le téléchargement est journalisé.
- **Raison** : simplicité d'usage et protection contre l'injection de formules.

### D36 — 2026-10-05 — Vérification « base non téléchargeable »
- **Question** : l'annexe A demande un test HTTP prouvant que la base renvoie 403 quand `prive/` est dans `www/`, ce qu'aucun test hors OVH ne peut faire (`php -S` ignore `.htaccess`).
- **Décision** : le tableau de bord contient un « contrôle d'exposition » : le navigateur tente de télécharger la base, les clés, `config.php`, `.htpasswd` et `schema.sql` par leur URL et affiche « protégé » ou « DANGER ». Les tests PHP vérifient la présence des directives `Require all denied` et `FilesMatch`.
- **Raison** : la vérification se fait sur l'hébergement réel, là où elle a du sens.

### D37 — 2026-10-05 — Mot de passe de la console
- **Question** : « changement de mot de passe par la même voie » alors que l'assistant est verrouillé après usage.
- **Décision** : écran Réglages, formulaire « Changer le mot de passe » (même fonction `password_hash`, 20 caractères minimum), pour l'utilisateur connecté.
- **Raison** : l'assistant ne doit jamais redevenir exécutable.

## Rotation de clé

### D38 — 2026-10-05 — Rotation de la clé de signature
- **Question** : sort de l'ancienne clé privée ; sens de `retiree_le`.
- **Décision** : le bouton « Nouvelle cle de signature » génère la paire sur le serveur, signe le bulletin `{"type": "nouvelle_cle", "kid", "cle_publique", "valide_des"}` avec la clé active, enregistre la nouvelle clé (kid + 1), date `retiree_le` de l'ancienne au moment de la rotation, puis efface l'ancienne clé privée. Le serveur signe toujours avec la clé non retirée de plus grand kid et diffuse les bulletins dont `active_depuis` date de moins de 12 mois. L'écran Clés montre la clé active (bouton « Copier »), les trois lignes à reporter dans `etdel_licence.py`, l'historique et la date jusqu'à laquelle les postes acceptent encore chaque ancienne clé (retrait + 90 jours, règle appliquée côté client, voir D19).
- **Raison** : une fois le bulletin signé, l'ancienne clé privée ne sert plus à rien ; la conserver n'ajouterait qu'un risque.

## Kit d'intégration

### D39 — 2026-10-05 — Empreinte publiée du module
- **Question** : comment publier et contrôler l'empreinte d'un fichier qui change une fois (constantes renseignées après l'installation du serveur) et que Git peut convertir en CRLF sous Windows.
- **Décision** : l'empreinte SHA-256 est calculée sur le contenu aux fins de ligne normalisées en LF. Elle est publiée avec la version dans `docs/INTEGRATION.md` et reprise dans `client/test_licence_integration.py` ; `test_etdel_licence.py` échoue si ces trois éléments divergent. Le test du module accepte des constantes vides, ou renseignées (URL HTTPS et clé publique de 32 octets).
- **Raison** : une seule référence, impossible à oublier lors du renseignement des constantes.

### D40 — 2026-10-05 — Test d'intégration générique
- **Question** : comment tester une application réelle sans réseau ni heure réelle, alors que `installer()` construit sa propre Garde.
- **Décision** : `test_licence_integration.py` remplace, le temps du test, les constantes du module, la fabrique `Garde` (horloge et transport factices) et la boîte de message ; il redirige `%APPDATA%`, `%LOCALAPPDATA%`, `%PROGRAMDATA%` et le dossier personnel vers un dossier temporaire. Seule la fonction `creer_application()` (et les codes produit et distribution) est à adapter dans chaque application.
- **Raison** : l'API publique du module ne porte aucun réglage de test, et l'application testée est bien la vraie.

### D41 — 2026-10-05 — Modèle de brief
- **Question** : le brief cite « version X.Y, empreinte SHA-256 fournie », valeurs qui évoluent.
- **Décision** : le modèle porte des emplacements `{VERSION}` et `{EMPREINTE}` (et `{APPLICATION}`, `{PRODUIT}`, `{DISTRIBUTION}`) à remplir depuis `docs/INTEGRATION.md` au moment de l'usage.
- **Raison** : un brief figé deviendrait faux dès le renseignement des constantes.

## Application de démonstration

### D42 — 2026-10-05 — Banc d'essai
- **Question** : comment faire de l'application de démonstration un banc d'essai de bout en bout alors que les constantes du module sont vides dans le dépôt, et que `php -S` ne fait pas d'authentification Basic.
- **Décision** : `client/demo_appli.py` accepte `--serveur` et `--cle-publique`, qui remplacent les constantes du module pour cette exécution seulement (banc d'essai, jamais dans une application livrée) ; `construire()` renvoie la fenêtre sans `mainloop` pour être pilotée par le test de bout en bout. `serveur/tests/routeur_banc.php` (non déployé) reproduit Apache : authentification Basic de `/admin/` contre le `.htpasswd` réel, puis `REMOTE_USER` ; refus des chemins protégés par les `.htaccess`. Le test de bout en bout active la démo par sa fenêtre contre le serveur PHP réel et consulte la console par HTTP.
- **Raison** : le parcours complet (installation, console, application) se rejoue sur un poste de développement, sans hébergement.

## Exploitation

### D43 — 2026-10-05 — Mot de passe de la console perdu
- **Question** : sans SSH ni outil local, et avec un assistant verrouillé, comment retrouver l'accès à la console si le mot de passe est perdu ? Le cahier des charges prévoit le « changement de mot de passe par la même voie » que la création du `.htpasswd`.
- **Décision** : un jeton `jeton_reinitialisation` (20 caractères minimum, différent du jeton d'installation) déposé par FTP dans `config.php` ouvre, dans `install.php` toujours verrouillé, un formulaire qui ne fait que remplacer le mot de passe d'un compte existant du `.htpasswd` (bcrypt ; jamais de compte supplémentaire ; une seconde d'attente après un échec). Chaque jeton ne sert qu'une fois (son SHA-256 est inscrit dans `prive/reinitialisation.utilisee`) ; l'opération est journalisée. Rien d'autre de l'installation n'est rejouable.
- **Raison** : l'accès FTP est la seule preuve d'autorité disponible ; la portée est limitée au mot de passe.

## Corrections après revue du code

Une revue indépendante (lecture du code et scénarios rejoués contre le vrai module et le vrai serveur) a relevé les défauts suivants, corrigés et couverts par des tests.

### D44 — 2026-10-05 — Refus de version après mise à jour de l'application
- **Question** : le refus `version_trop_ancienne` mémorisé bloquait aussi la version mise à jour, qui se fermait au lancement avant tout contrôle.
- **Décision** : le refus mémorise la version refusée et ne s'applique qu'à elle. Au lancement, `VERSION_REFUSEE` ouvre la fenêtre « Mise a jour necessaire » avec « Reessayer » et « Quitter » (le message reste affiché, fermer quitte) ; en session, message puis fermeture.
- **Raison** : une baisse de la version minimale ou une mise à jour doit débloquer le poste.

### D45 — 2026-10-05 — Préavis supérieur à la tolérance
- **Question** : avec un préavis au moins égal à la tolérance (par exemple 5 j pour 3 j), le bandeau s'affichait dès la vérification réussie et ne partait plus.
- **Décision** : le préavis ne commence jamais avant la moitié de la durée hors ligne (`max(fin − préavis, émis + durée/2)`). Avec les valeurs par défaut (15 j / 5 j), rien ne change : bandeau du 10e au 15e jour.
- **Raison** : un avertissement doit signaler une absence de connexion, pas un réglage.

### D46 — 2026-10-05 — Plusieurs instances de la même application
- **Question** : deux instances ouvertes s'écrasaient l'état local (la dernière fermée pouvait effacer la clé ou la demande enregistrée par l'autre).
- **Décision** : avant chaque contrôle, activation, demande, fermeture, et à chaque relecture périodique (25 s), le module relit l'état sur disque ; s'il est plus récent (compteur `seq`), il l'adopte en gardant l'heure maximale la plus grande.
- **Raison** : solution simple sans verrou système ; il ne reste qu'une course sur deux écritures simultanées.

### D47 — 2026-10-05 — Fermeture annulée par l'application
- **Question** : `arreter()` appelé avant le gestionnaire de fermeture d'origine stoppait le contrôle même si l'application annulait la fermeture (« modifications non enregistrées »).
- **Décision** : à la demande de fermeture, le module enregistre l'heure atteinte puis appelle le gestionnaire d'origine ; l'arrêt du contrôle a lieu à la destruction réelle de la fenêtre.
- **Raison** : respecte l'intention (« grave l'heure atteinte ») sans désarmer la licence d'une application restée ouverte.

### D48 — 2026-10-05 — `kid` hors signature
- **Question** : le `kid` de l'enveloppe n'est pas signé ; une réponse authentique dont le `kid` est changé en route pouvait classer la clé embarquée sous un faux numéro et faire rejeter ensuite toutes les vraies réponses.
- **Décision** : la clé embarquée est toujours essayée en dernier recours, quel que soit le `kid` annoncé, sauf si elle est retirée ; aucune clé retirée n'est acceptée pour une réponse nouvelle, sous quelque `kid` que ce soit.
- **Raison** : corrige le défaut sans changer le protocole de l'annexe C.

### D49 — 2026-10-05 — Limites et téléchargements côté serveur
- **Question** : limites contournables en changeant d'adresse IPv6 ; copie de la base laissée sur le serveur après un téléchargement interrompu ; réinitialisation du mot de passe capable de créer un second compte.
- **Décision** : les limites comptent par adresse IPv4 et par préfixe /64 en IPv6 (une IPv4 notée en IPv6 reste une IPv4) ; la copie téléchargée est supprimée même si le téléchargement est interrompu (et toute copie de plus d'une heure est purgée) ; la réinitialisation n'accepte qu'un compte existant (D43).
- **Raison** : fermer les contournements relevés sans changer l'usage normal.

## Reprise sur le poste de développement Windows

La branche de travail a été relue (client en entier, serveur par une revue indépendante) puis reprise sur `main` ; toutes les suites ont été exécutées sous Windows 11 avec Python 3.14 et PHP 8.3 portable.

### D50 — 2026-10-05 — Journal du module sous Windows
- **Question** : sous Windows, `RotatingFileHandler` garde `licence.log` ouvert ; le fichier ne peut alors être ni renommé ni supprimé. Une seconde instance de l'application faisait échouer la rotation (trace « Logging error » sur la sortie d'erreur) et le dossier de licence restait verrouillé.
- **Décision** : gestionnaire `_FichierJournal` qui ouvre le fichier, ajoute la ligne et le referme à chaque écriture ; rotation à 1 Mo, 2 archives (annexe D), tentée sans bloquer si une autre instance écrit. Module en version 1.0.1.
- **Raison** : mêmes règles que l'annexe D, sans verrou sur le fichier ; le coût d'une ouverture par ligne est négligeable pour quelques lignes par contrôle.

### D51 — 2026-10-05 — Bulletin et kid déjà connu
- **Question** : après une réponse dont le `kid` (hors signature) a été altéré en route, la clé embarquée restait classée sous ce faux numéro ; le bulletin authentique annonçant plus tard ce même numéro était ignoré et le poste ne pouvait plus vérifier la nouvelle clé.
- **Décision** : un bulletin valide (signé par une clé connue) remplace l'association d'un `kid` à une autre clé.
- **Raison** : le bulletin est signé, le `kid` d'une enveloppe ne l'est pas : le premier fait foi.

### D52 — 2026-10-05 — Tests sur poste Windows
- **Question** : trois vérifications du serveur supposaient Linux (droits `0600`, chemin absolu commençant par `/`) et le test de bout en bout simulait l'échec d'envoi par `sendmail_path=/bin/false`, que PHP pour Windows ignore (il renvoie toujours succès).
- **Décision** : sous Windows, les droits Unix ne sont pas vérifiés (NTFS n'en a pas) et un chemin `X:\` ou `X:/` est absolu ; l'échec d'envoi passe par un port SMTP fermé (`-d SMTP=127.0.0.1 -d smtp_port=9`).
- **Raison** : les mêmes suites doivent passer sur le poste de développement Windows et sous Linux, sans affaiblir les vérifications faites sur l'hébergement réel.

### D53 — 2026-10-05 — Restauration d'une base antérieure à une rotation
- **Question** : la rotation efface l'ancienne clé privée (D38). Une base restaurée d'avant la rotation désigne donc une clé privée absente : l'API répondait 503 à tout, et la console ne pouvait rien réparer (elle passe par la même clé active).
- **Décision** : chaque clé privée a sa « fiche » publique `prive/cles/signature_N.json` (kid, clé publique, bulletin, date). Quand la clé active de la base est introuvable, le serveur reprend dans l'ordre les fiches des kid suivants, sans trou, et l'inscrit au journal (`cles_resynchronisees`). Une chaîne incomplète n'est jamais complétée : l'erreur reste visible.
- **Raison** : restaurer une sauvegarde ne doit demander ni accès SQL ni outil ; la fiche ne contient que des données publiques déjà diffusées aux postes.

### D54 — 2026-10-05 — HTTPS exigé avant l'authentification de la console
- **Question** : la redirection HTTPS de `www/.htaccess` (mod_rewrite) n'intervient qu'après l'authentification Basic : une première visite en `http://…/admin/` envoyait le mot de passe en clair.
- **Décision** : le `admin/.htaccess` généré combine, dans un `<RequireAll>`, la même détection du HTTPS que la redirection (`SERVER_PORT` différent de 80 ou `X-Forwarded-Proto: https`) et `Require valid-user`. En HTTP, Apache répond 403 « Console accessible uniquement en https:// » sans demander d'identifiants.
- **Raison** : « HTTPS forcé » doit valoir dès la première requête, HSTS ne protégeant qu'à partir de la deuxième.

### D55 — 2026-10-05 — Suspension d'une licence obtenue par demande
- **Question** : sur `suspendue`, le poste efface sa clé (annexe D). Pour une licence obtenue par demande, personne n'a jamais vu la clé et le serveur n'en garde que le hachage : « Réactiver » ne peut pas débloquer ce poste.
- **Décision** : comportement de l'annexe D inchangé ; la fiche de la licence l'explique avant toute suspension (licence obtenue par demande : préférer une échéance proche pour une coupure temporaire, sinon créer ensuite une nouvelle clé).
- **Raison** : l'annexe fait foi ; l'administrateur doit connaître la conséquence avant d'agir.

### D56 — 2026-10-05 — Écritures de la console strictement en POST
- **Question** : le téléchargement d'une copie de la base passait par un lien (GET) alors qu'il écrit un fichier et le journal ; une simple image sur un autre site suffisait à le déclencher. La page de confirmation sans JavaScript recopiait aussi le nouveau mot de passe dans des champs cachés.
- **Décision** : le téléchargement est un formulaire POST (jeton CSRF, confirmation) dont le jeton n'est pas renouvelé, la page restant affichée après le téléchargement. Le changement de mot de passe ne passe pas par la page de confirmation sans JavaScript : la double saisie en tient lieu (la boîte de confirmation JavaScript reste).
- **Raison** : « écritures en POST uniquement » ; un mot de passe ne doit jamais réapparaître dans une page.

### D57 — 2026-10-05 — Détails de la console
- **Question** : points relevés par la revue.
- **Décision** :
  - CSV : une cellule commençant par une tabulation ou un retour chariot est aussi préfixée d'une apostrophe ;
  - date libre de prolongation : une date inexistante (31 février) est refusée au lieu d'être reportée au mois suivant ;
  - une distribution désactivée porte l'étiquette « Inactive » (et non « Revoquee »).
- **Raison** : corrections sans effet sur l'usage normal.

### D58 — 2026-10-05 — Tout le serveur dans `www/`
- **Question** : Le propriétaire du projet demande, pour faciliter les essais, de regrouper tout le serveur dans le dossier servi ; l'annexe A prévoit ce repli (`prive/` dans `www/` avec `Require all denied`, et un test HTTP qui vérifie le 403 sur la base).
- **Décision** : le dépôt range `prive/` dans `serveur/www/prive/` ; la base et les sauvegardes passent par défaut dans `prive/data/` (un seul dossier à protéger, au lieu de `data/` à côté de `prive/`). Les points d'entrée cherchent toujours `prive/` d'abord à côté du dossier servi : déplacer `prive/` d'un bloc hors de `www/` reste possible, sans autre changement (variante documentée dans `LISEZ-MOI-SERVEUR.md`). Le contrôle d'exposition de la console calcule ses URL à partir des chemins réels (clé de signature active et non plus `signature_1.key`, journal WAL, dossier des sauvegardes). Le test de bout en bout installe le serveur dans cette disposition et vérifie le 403 sur la base, les clés, la configuration et le `.htpasswd`, y compris sous des variantes de casse, de points finaux et d'encodage.
- **Raison** : un seul dossier à envoyer et à essayer ; la protection reste double (`prive/.htaccess` et règles de `www/.htaccess`) et vérifiable sur l'hébergement réel.

### D59 — 2026-10-05 — Banc d'essai sur le poste Windows
- **Question** : comment essayer le serveur complet sur le poste de développement, navigateur compris, sans rien installer dans le système ni rien envoyer sur Internet.
- **Décision** : PHP 8.3 portable dans `php/` (ignoré par Git) ; `php -S` directement sur `serveur/www/` avec `routeur_banc.php` (commande dans le README, « Banc d'essai local »). Le routeur décode l'URL et la normalise comme le système de fichiers Windows (casse, antislash, points et espaces finaux, flux `:`) avant de refuser `prive/`, `data/`, `.ht*` et les extensions sensibles : sans cela, `/PRIVE/Data/Licenses.DB` aurait été servi. `serveur/tests/smtp_banc.py` reçoit les e-mails de PHP (SMTP sur 127.0.0.1:2525) et les range dans `serveur/tests/courriels/` (ignoré par Git).
- **Raison** : le parcours complet (installation, console, notification, application) se rejoue sur le poste, au plus près de l'hébergement.

### D60 — 2026-10-06 — Contrôle d'exposition : seule une réponse prouve la protection
- **Question** : essayé dans le navigateur, le contrôle affichait « protégé » quand la requête n'aboutissait pas du tout (erreur réseau, redirection, ou page ouverte par une adresse contenant les identifiants, que `fetch` refuse).
- **Décision** : « protégé » uniquement sur une réponse 401, 403 ou 404 ; « DANGER » sur 200 ; tout autre cas est affiché « non vérifié, à contrôler à la main ». Les URL testées sont rebâties sans identifiants.
- **Raison** : un contrôle de sécurité ne doit jamais conclure à la sécurité faute de réponse.

### D61 — 2026-10-06 — Suspension datée, clé conservée ; révocation définitive
- **Question** : le propriétaire du projet trouve trop définitif qu'un poste suspendu ou révoqué perde sa clé (annexe D : « effacement de la clé locale »), et souhaite une suspension avec date de fin. La révocation, elle, reste définitive (réponse explicite : « on garde tel quel »).
- **Décision** :
  - console : « Suspendre » accepte une date de fin facultative (« jusqu'au », fin de journée dans le fuseau de `config.php`) ; à cette date, la licence redevient active d'elle-même (contrôle à chaque requête de l'API et de la console, inscrit au journal par `systeme`) ; « Reactiver » et « Revoquer » effacent la date ; une licence déjà suspendue peut recevoir une nouvelle date ;
  - base : colonne `licences.suspendue_jusqu` (écart à l'annexe B) ; les bases existantes sont mises à niveau automatiquement (`PRAGMA user_version`, `db_migrer()`) ;
  - API : le refus signé `suspendue` porte `suspendue_jusqu` ;
  - client (module 1.1.0) : sur `suspendue`, le poste **garde sa clé** et reste bloqué (statut `EXPIREE`, fenêtre « Licence suspendue » avec « Reessayer » au lancement, message puis fermeture en session) ; il se débloque seul au premier contrôle qui suit la réactivation ou la date de fin, sans ressaisie ; le blocage persiste hors ligne. `revoquee`, `poste_revoque`, `cle_liee_autre_poste` et `cle_invalide` effacent toujours la clé (statut `REVOQUEE`).
- **Raison** : une suspension est par nature temporaire ; effacer la clé la rendait irréversible pour une licence obtenue par demande (D55, désormais sans objet). Le serveur reste seul juge : garder la clé ne donne aucun droit au poste.

### D62 — 2026-10-06 — Deux défauts du client relevés par la vérification de l'aide
- **Question** : (1) une licence révoquée (ou un poste libéré) après l'acceptation d'une demande, mais avant que le poste ait récupéré sa clé, laissait le poste attendre indéfiniment, essai compris, sans pouvoir refaire de demande ; (2) avec une tolérance de 0 jour (7 h diffusées, D25) et le préavis par défaut, un poste toujours connecté affichait le bandeau « Licence non verifiee depuis 0 jour(s) » entre 3 h 30 et 6 h après chaque contrôle.
- **Décision** : (1) sur un refus `revoquee`, `poste_revoque`, `cle_liee_autre_poste` ou `cle_invalide` pendant le suivi d'une demande, le poste abandonne la demande : l'essai s'arrête (statut `REVOQUEE`), puis la fenêtre d'activation permet une nouvelle demande ou la saisie d'une clé ; une suspension, elle, laisse la demande en attente et la clé arrive à la réactivation (D61). (2) Le bandeau de tolérance ne commence jamais avant le contrôle suivant (6 h) plus une heure ; avec 0 jour, l'application passe directement de « valide » à « bloquée » si elle n'a pas pu se contrôler.
- **Raison** : « refus, blocage immédiat à la connexion suivante » (section 6) ; un bandeau ne doit signaler qu'une absence réelle de connexion (même principe que D45).

### D63 — 2026-10-06 — Écran Aide de la console
- **Question** : le propriétaire du projet demande une aide complète sur le serveur, qui permette à quelqu'un qui ne le connaît pas de comprendre son fonctionnement, chaque réglage et chaque action (accepter, refuser, révoquer, suspendre, fonctionnement des clés…).
- **Décision** : écran « Aide » dans la console (dernier lien du menu, `index.php?page=aide`), dans un fichier ajouté à l'arborescence de l'annexe A, `prive/lib/aide.php`. Seize sections avec sommaire : premiers pas, tableau de bord, demandes, licences, comparatif suspendre / révoquer / libérer / laisser expirer, produits et distributions (chaque champ), ce que voit l'utilisateur dans l'application, échéance et tolérance (exemple jour par jour), serveurs, clés de signature, journal, sauvegarde et restauration, réglages, sécurité, dépannage (FAQ et tableau de tous les codes de refus), glossaire. Chaque écran de la console porte un lien « Aide sur cet ecran » vers sa section. Les valeurs réglables (plafond d'e-mails, sauvegardes conservées, fuseau, longueur minimale du mot de passe, limites par adresse IP, tolérance minimale) sont lues dans `config.php` et les constantes du code, pour que l'aide reste juste. Rédaction faite à partir d'un inventaire du code, puis vérifiée affirmation par affirmation contre le code par des relecteurs indépendants, dont un relecteur « débutant ».
- **Raison** : la console est le seul outil d'administration (section 9) ; son mode d'emploi doit y être, à jour avec le code.

### D64 — 2026-10-06 — Démo utilisable seule par l'administrateur
- **Question** : le propriétaire du projet veut faire ses propres essais avec l'application de démonstration : bouton plutôt que raccourci clavier, possibilité de repartir sans licence, banc remis à zéro.
- **Décision** : `demo_appli.py` affiche les boutons « Fenetre Licence » (le raccourci Ctrl+Maj+L reste) et « Supprimer la licence de ce poste (essais) » (après confirmation : arrêt du contrôle, effacement des trois exemplaires de l'état local de la démo, relance avec les mêmes options ; rien n'est modifié sur le serveur). Option `--banc` : URL `http://127.0.0.1:8090/api/v1/` et première clé publique lue dans `serveur/www/prive/cles/signature_1.json`, comme une application livrée avec la clé d'origine ; lanceur `client/lancer_demo_banc.bat`. Le test de bout en bout ouvre la fenêtre par le bouton et écrit le journal de ses postes simulés dans son dossier temporaire, plus jamais dans le `%LOCALAPPDATA%` réel.
- **Raison** : un banc d'essai doit pouvoir être rejoué de bout en bout sans outil ni manipulation de fichiers.

### D65 — 2026-10-06 — Joker « toutes les options »
- **Question** : le propriétaire du projet demande s'il est possible de donner « all » pour les options d'une licence.
- **Décision** : dans tout champ Options de la console (distribution, licence, acceptation d'une demande), `*` seul veut dire « toutes les options, présentes et futures » ; mêlé à d'autres codes, il les absorbe (`export_pdf, *` devient `*`) ; collé à un code (`export_*`), il est refusé. Il est enregistré et diffusé tel quel (`["*"]`) dans le jeton signé, affiché « toutes (*) ». Le module client (1.2.0) répond `True` à `option(code)` pour tout code quand `*` est présent, toujours seulement si la licence est utilisable (valide, avertissement, essai).
- **Raison** : édition complète ou usage interne sans tenir à jour la liste des codes à chaque nouvelle fonction ; un symbole qui ne peut pas être un code d'option valide évite toute confusion avec une option réelle nommée « all ».

### D66 — 2026-10-06 — Clé visible dans l'application, hachée sur le serveur
- **Question** : le propriétaire du projet constate que le numéro de licence n'apparaît nulle part dans l'application, et demande pourquoi le serveur ne l'affiche pas.
- **Décision** : côté application, la fenêtre « Licence » et `cadre_licence()` affichent la ligne « Cle » masquée comme dans la console (`ETDEL-****-****-****-XXXX`, champ sélectionnable) et un bouton « Afficher la cle » / « Masquer la cle » qui montre la clé complète, gardée localement par le module ; elle n'apparaît jamais dans la ligne de diagnostic ni dans le journal. Côté serveur, rien ne change (choix explicite) : seul le SHA-256 de la clé est stocké, la console n'en montre que les 4 derniers caractères.
- **Raison** : au téléphone, les 4 derniers caractères rapprochent l'application de la bonne fiche ; l'utilisateur peut noter sa clé avant une réinstallation. Sur le serveur, la règle du cahier des charges (« seul le hash SHA-256 de la clé est stocké ») protège toutes les clés non encore activées ou libérées en cas de fuite de la base.

### D67 — 2026-10-06 — « Nouvelle cle » et fiche licence pour débutant
- **Question** : le propriétaire du projet, qui tiendra la console seul, la trouve beaucoup trop complexe : chaque écran doit se comprendre sans lire l'aide. Il manque aussi un moyen de remplacer une clé perdue ou transmise par erreur sans révoquer la licence, et de servir de nouveau un client dont la licence a été révoquée.
- **Décision** :
  - action « Nouvelle cle » (`licence_nouvelle_cle`, confirmation « Remplacer la cle (l'ancienne cle ne fonctionnera plus) »), permise sur une licence active ou suspendue, refusée sur une licence révoquée. En une transaction : nouvelle clé (hash et 4 derniers caractères remplacés), ordinateur libéré (la clé se liera au premier ordinateur où elle sera saisie), remise en attente de l'ancienne clé par une demande acceptée annulée (`demandes.cle_chiffree` effacée), journal `cle_remplacee` (« ancienne cle ...XXXX ; ordinateur libere : … » ou « aucun ordinateur lie ») ; statut, échéance, options et historique sont conservés. La réponse n'est pas une redirection : la page « Nouvelle cle » affiche la clé une seule fois, comme après la création d'une licence (un renvoi du formulaire est refusé par le jeton CSRF renouvelé). L'ancienne clé reçoit `cle_invalide` au contrôle suivant : l'application l'efface et prévient l'utilisateur, qui devra saisir la nouvelle clé (D61) ; un poste qui attendait encore la remise de sa clé reçoit `demande_inconnue` et oublie sa demande. Côté API, `activer` et `valider` lisent la licence par le hash de la clé et la lient dans une même transaction immédiate : un remplacement ne peut pas s'intercaler, l'ancienne clé ne reçoit plus aucun jeton ni ne se lie une fois remplacée. La page de création d'une licence s'intitule « Nouvelle licence » (bouton « Nouvelle licence » de l'accueil et de l'écran Licences) : « Nouvelle cle » est réservé au remplacement ;
  - licence révoquée : plus aucune action sur sa fiche, seulement le bouton « Nouvelle licence pour ce client », qui ouvre la création d'une clé pré-remplie (même distribution, titulaire, e-mail, note « Remplace la licence n. X », durée par défaut de la distribution). La révocation reste définitive ;
  - fiche licence : un résumé de six lignes en tête (Statut, Client, Clé, Ordinateur, Échéance, Dernier contact), puis « Que voulez-vous faire ? » et un bouton par action : « Prolonger » (sauf licence perpétuelle), « Nouvelle cle », « Changer d'ordinateur » (si un ordinateur est lié), « Suspendre » ou, si la licence est suspendue, « Reactiver » et « Changer la date de fin », « Revoquer » (en rouge). Chaque bouton (`<details>`, sans script en ligne) déplie une ou deux phrases qui disent ce qui va se passer et son formulaire ; avec JavaScript, une seule action est dépliée à la fois. La modification (titulaire, e-mail, note, tolérance, options), les informations techniques et l'historique sont repliés sous « Reglages avances » ;
  - « Liberer le poste » devient « Changer d'ordinateur » dans toute la console (confirmation « Changer d'ordinateur (liberer la cle) », bouton « Liberer cette cle ») ; l'action `licence_liberer` et le journal `poste_libere` ne changent pas.
- **Raison** : un débutant part de ce qu'il veut faire et lit ce qui va se passer avant de confirmer ; le reste ne disparaît pas mais ne l'encombre plus. Remplacer la clé règle le cas « clé perdue ou diffusée » sans perdre l'échéance ni l'historique ; libérer l'ordinateur et annuler la remise en attente garantissent qu'aucune copie de l'ancienne clé ne reste utilisable ou livrable. Seul le hash de la nouvelle clé est stocké, comme pour toute clé.

### D68 — 2026-10-06 — Distributions autonomes, produit pour le seul classement ; menu réduit
- **Question** : suite de D67. Une première version (un produit « simple » portant les règles d'une distribution standard créée d'office, des « variantes » pour les autres) a été écartée par le propriétaire du projet, qui veut quelque chose de simple : chaque distribution doit être gérée de façon autonome, le regroupement en produits ne doit servir qu'à l'affichage et au tri sur le serveur. Le menu de dix entrées mêlait aussi le quotidien (demandes, licences) et les écrans techniques.
- **Décision** :
  - la distribution est l'unité gérée : ses règles (libellé, client, durée par défaut, essai, options, tolérance hors ligne, préavis, version minimale, message d'accueil, état actif), sa ligne `installer(...)`, ses licences, ses demandes et son essai. Le produit n'est plus qu'une étiquette de classement (code définitif et nom) qui range, trie et filtre les listes. L'application envoie toujours son code produit, qui doit être celui de la distribution : sinon `produit_inconnu`, comme pour une distribution inconnue ou inactive ;
  - essai accordé une fois par ordinateur **et par distribution** (avant : par produit, toutes distributions confondues) ; toujours une seule demande en attente par ordinateur et par distribution ;
  - `produits.version_min` et `produits.actif` restent dans le schéma (annexe B, aucune migration) mais n'ont plus aucun effet : API, demandes, activation et listes de la console ne regardent que la version minimale et l'état de la distribution (`version_min_effective()` devient `version_min_normalisee()`). La console ne les montre plus ;
  - menu : Accueil (`page=tableau`, lien du nom de la console), Demandes (avec la pastille), Licences, Distributions (`page=distributions` ; `page=produits` et `page=produit` en restent des alias), Aide, Administration. L'entrée de chaque écran est en évidence sur ses pages de détail, et sur une page répondant à une action (clé affichée, erreur, confirmation sans JavaScript) l'entrée de cette action (`licence_*` → Licences, `distribution_*` et `produit_*` → Distributions, `demande_*` → Demandes, les autres → Administration), jamais Accueil par défaut. Sous 900 px de large, le menu passe sur sa propre ligne ;
  - écran Distributions : la phrase « Une distribution = une application livree (un executable) avec ses regles, ses licences et ses demandes. Le produit sert seulement a ranger les distributions. », un filtre par produit (GET), « Nouvelle distribution », puis une carte par produit (« CODE - Nom », petit formulaire « Renommer », qui ramène à la liste avec le même filtre) avec le tableau de ses distributions : code (lien) suivi de son étiquette Active ou Inactive (toujours visible sur un écran étroit), libellé, client, règles en une ligne (« Licence 365 jours, essai 15 jours, options : export_pdf, hors ligne 15 jours », version minimale ajoutée si elle est fixée), nombre de licences (toutes, comme la liste filtrée ouverte par ce lien), demandes en attente (colonne « En attente », les demandes traitées n'y comptent pas) ;
  - page d'une distribution : en-tête (code, libellé, produit « (classement) », état), ligne `installer(...)` avec « Copier », « Voir ses licences » (écran Licences filtré sur la distribution, avec « Nouvelle licence pour cette distribution » et « Retour a la distribution ») et « Nouvelle licence pour cette distribution » (création pré-remplie ; remplacé, pour une distribution inactive, par la phrase « Distribution inactive : reactivez-la (Reglages avances, case Active) pour lui creer des licences. »), puis les règles : libellé (« ex. Edition cabinet, Demo »), « Client de cette distribution (facultatif, pour memoire) », durée par défaut, essai, options (`*` = toutes), et sous « Reglages avances » tolérance, préavis, version minimale, message d'accueil et case « Active » (dépliés d'eux-mêmes si la distribution est inactive) ; enfin « Dupliquer » : le code de la copie est à saisir (champ vide, exemple « APP-CLIENTB »), la copie reprend règles et produit, sans licence, sans demande et sans client. Ni son code ni son produit ne changent après la création ;
  - « Nouvelle distribution » : « Produit (pour le classement) ». S'il existe des produits, la liste commence par « -- choisir un produit -- » (choix exigé par le navigateur), puis les produits, puis « Nouveau produit » : un doublon n'est jamais créé parce que « Nouveau produit » était proposé d'office ; sans aucun produit, « Nouveau produit » seul. Une phrase dit pourquoi les codes sont définitifs (inscrits dans l'application par la ligne `installer(...)`, exemple MONAPPLI / MONAPPLI-CLIENTA). « Code du nouveau produit (definitif, obligatoire) » et « Nom du nouveau produit (obligatoire) » sont masqués, non envoyés et non exigés par `app.js` quand un produit existant est choisi, exigés quand « Nouveau produit » l'est (sans JavaScript, remplir les deux est refusé plutôt que deviné, et ni produit choisi ni code saisi donne « Produit : choisissez-le dans la liste, ou remplissez le code et le nom du nouveau produit. »). Puis « Code de la distribution (definitif) » et les règles avec les valeurs d'un débutant (durée 365 jours, essai 15, tolérance 15, préavis 5). Un code de produit ou de distribution déjà pris à la casse près (`compta` face à `COMPTA`) est refusé, à la création comme à la duplication, en nommant le code existant : l'API distingue la casse, mais deux codes pareils à la lecture se confondraient dans les listes. Une seule action (`distribution_enregistrer`), une confirmation, une transaction : le nouveau produit éventuel (journal `produit_cree`) et la distribution (`distribution_creee`) ; un refus ne crée rien. Plus de page de produit à règles, plus de distribution créée d'office, plus de « variante ». `produit_enregistrer` ne fait plus que renommer (« Renommer le produit », journal `produit_renomme`) ;
  - la distribution reste visible depuis ce qui en dépend : le résumé de la fiche licence passe à sept lignes (Statut, Client, Distribution — lien vers sa page —, Clé, Ordinateur, Échéance, Dernier contact), la fiche d'une demande a le même lien. « Nouvelle licence » ne choisit plus rien à la place de l'administrateur : une distribution n'est présélectionnée que si elle est demandée (page d'une distribution, liste filtrée), si c'est celle de la licence remplacée ou si c'est la seule active ; sinon, ou si celle demandée est inactive, « -- choisir une distribution -- » (choix exigé ; côté serveur « Distribution : choisissez-la dans la liste. »). Après la création, « Creer une autre licence pour cette distribution » et « Retour a la distribution » ;
  - suite de D67 : « Nouvelle licence pour ce client » transmet la licence révoquée avec le formulaire ; la création écrit alors, dans la même transaction, `licence_remplacee` (« par la licence n. Y ») dans l'historique de la licence révoquée, dont la fiche affiche « Deja remplacee par la licence n. Y » (lien) et ne garde le bouton qu'au second plan. Une licence révoquée n'affiche plus de jours restants (« sans objet : licence revoquee »). « Changer la date de fin » d'une licence suspendue est pré-rempli avec la date actuelle : valider sans y toucher ne supprime plus la réactivation automatique ;
  - un seul mot pour la personne ou l'entreprise à qui appartient une licence : « Client » (colonnes, fiches), « Client (titulaire de la licence) » dans les formulaires, qui rappelle le mot « Titulaire » de l'application ; « ordinateur » plutôt que « poste » dans les textes de la console, sauf « Identifiant du poste », le libellé que montre l'application ;
  - accueil : les demandes en attente (avec leur distribution et « Traiter ») ou « Aucune demande en attente. », puis « Nouvelle licence » et « Nouvelle distribution », les compteurs (chacun ouvre la liste filtrée) et les licences expirant sous 30 jours. Écran Administration (`page=administration`) : Serveurs, Clés de signature, Journal, Sauvegarde et Réglages en une phrase chacun, et le contrôle d'exposition, retiré de l'accueil ; son lien « Aide sur cet ecran » mène à la section Sécurité, et celui de l'écran Distributions à la section Produits, en attendant la réécriture de l'aide. Un champ refusé par le navigateur dans des réglages repliés les déplie (`app.js`).
- **Raison** : une distribution correspond à un exécutable livré ; la gérer d'un seul tenant (règles, licences, demandes, essai) se comprend sans lire l'aide, et la même application livrée à deux clients n'est que deux distributions côte à côte. Réduit à un nom pour ranger, le produit ne peut plus agir en silence sur ses distributions (un produit désactivé ou sa version minimale bloquaient des distributions dont la page n'en disait rien). Rien ne change dans le schéma, l'API (codes, champs) ni le module client : une base existante, dont celle du banc (`DEMO` / `DEMO-BANC`), fonctionne telle quelle. Toutes les écritures restent en POST, confirmées et journalisées.

### D69 — 2026-10-06 — Fenetres de l'application plus agreables
- **Question** : le propriétaire du projet trouve austères les fenêtres de gestion, de demande et de consultation de la licence dans l'application (libellés Tk simples, boutons gris en relief).
- **Décision** : module client 1.4.0, toujours bibliothèque standard seule, widgets `tk` simples dessinés à partir de la palette de l'application (pas de thème ttk, pour que la palette s'applique partout) :
  - chaque fenêtre suit la même hiérarchie : barre de couleur en haut, pastille ronde à icône (polices d'icônes de Windows 10 et 11, « i » ou « ! » à défaut), titre, explication d'une ligne, contenu, pied distinct portant l'identifiant du poste (« Copier ») et les boutons. Boutons plats : principal sur l'accent avec anneau de focus de 2 px, secondaire au bord fin, liens soulignés au survol et au focus. Fenêtres centrées, non redimensionnables, bornées au-dessus de la barre des tâches ; fenêtre « Licence » de 508 px de haut au lieu de 603 (marges resserrées, « Verifier maintenant » au pied à côté de « Fermer ») ;
  - « Licence requise » : deux cartes de choix entièrement cliquables (« J'ai une cle », « Demander une licence »), mises en évidence au survol et au focus clavier (la première a le focus à l'ouverture). Saisie de la clé en majuscules, tirets et préfixe `ETDEL-` ajoutés pendant la frappe, indication en direct (incomplète, format correct, caractère non valide). Formulaire sur deux colonnes, titulaire manquant signalé sous le champ entouré de rouge, mention du délai dans un encadré d'information au-dessus de « Envoyer la demande ». « Demande envoyee » et « Demande en attente » montrent une frise des trois étapes ; motif de refus et messages d'alerte dans des encadrés teintés ;
  - fenêtre « Licence » et `cadre_licence()` : statut en pastille de couleur (vert valide, bleu essai ou attente, ambre avertissement, rouge bloqué), diagnostic renvoyé à la ligne entre les mots avec « Copier le diagnostic » ; « Verifier maintenant » est grisé sans clé ni demande en attente (« Aucune licence a verifier sur ce poste. ») et la fin d'un contrôle se lit sur un compteur de contrôles terminés tenu par la Garde, plus sur l'heure du dernier contrôle ; un échec affiche une phrase simple, le détail reste dans le diagnostic ;
  - bandeau compact (environ 30 px à 100 %) : bleu clair pendant l'essai, couleur d'accent au préavis, entièrement cliquable vers la fenêtre « Licence » ; la marge à prévoir au-dessus de la barre d'outils est documentée dans `INTEGRATION.md` ;
  - clavier : Entrée active le bouton qui a le focus (Entrée sur « Retour » n'envoie jamais la demande ni la clé), dans un champ l'action principale de la page ; Échap revient en arrière, ferme « Demande envoyee » et la fenêtre « Licence » ;
  - palette : les clés de 1.3.0 restent valables. `fond` devient le pied des fenêtres, mais une palette sans `panneau` garde son `fond` pour le corps (et un texte illisible sur `panneau` fait revenir à `fond`) ; une couleur ou une police refusée par Tk prend sa valeur par défaut, et si la fenêtre d'activation ne peut pas se construire elle est refaite avec la palette par défaut, sinon message puis fermeture : l'application n'est jamais invisible. Liens et icônes prennent une nuance de l'accent d'au moins 4,5:1 de contraste ; le ton d'alerte est un ambre distinct de l'accent par défaut ; palette sombre : barre de titre sombre ; les fenêtres reprennent l'icône de la fenêtre principale ;
  - démo : boutons plats et fond de la palette par défaut. Avec `--banc`, la distribution est lue dans la base du banc ouverte en lecture seule : `DEMO-BANC` si elle existe et est active (distribution créée à l'étape 5 du README), sinon la première distribution active du produit `DEMO`, `DEMO-BANC` à défaut de base lisible.
- **Raison** : ces fenêtres sont tout ce que l'utilisateur final voit de la licence ; une présentation claire (une action principale par page, statut lisible d'un coup d'œil, messages en une phrase) réduit les appels au support sans rien changer au protocole ni à la règle « rien n'est envoyé sans action explicite ». Plus petite id seule, la détection de la démo choisissait `DEMO` sur un banc installé selon le README, dont le serveur refuse la clé de `DEMO-BANC` (`cle_invalide`).

## Modèle final : des produits, sans distribution

Le propriétaire du projet, qui n'est pas technicien et tiendra seul la console, a arrêté un modèle volontairement simple. Ses choix priment sur le cahier des charges et sur les décisions antérieures là où ils s'en écartent ; les décisions remplacées sont signalées dans chaque entrée, sans réécrire leur texte.

### D70 — 2026-10-06 — Produits seuls : l'application et ses règles
- **Question** : même réduite à une étiquette de classement (D68), la distinction entre produit et distribution reste difficile à comprendre pour le propriétaire du projet, qui veut un seul objet : l'application, avec ses règles.
- **Décision** :
  - un produit = une application, reconnue par le code qu'elle envoie (`produit`), avec ses règles : durée de licence par défaut (jours, vide = perpétuelle), essai pendant une demande (jours, 0 = aucun, 15 par défaut), options (codes, `*` = toutes), tolérance hors ligne (15 par défaut), préavis (5 par défaut), message d'accueil, actif. La notion de distribution disparaît de tout ce qui est visible : console, aide, documentation, assistant d'installation, API publique du module (D76) ;
  - schéma de l'annexe B inchangé : chaque produit a exactement une ligne de règles dans la table `distributions`, jamais montrée (son code est celui du produit, suivi d'un numéro s'il est déjà pris) ; `produits.actif` suit l'état de cette ligne ;
  - API : le produit est identifié par le seul champ `produit` ; le champ `distribution` n'est plus lu pour la recherche, mais il est toujours renvoyé tel quel dans la réponse, que le client vérifie. Une licence est valable pour un produit si sa ligne de règles appartient à ce produit ; la clé d'un autre produit reçoit `cle_invalide` ;
  - bases existantes, dont celle du banc (produit `DEMO`, ligne de règles `DEMO-BANC`, une licence) : rien à faire. Un produit qui a plusieurs lignes de règles (anciennes distributions) prend ses règles, ses nouvelles licences et ses demandes dans la première ligne active, à défaut la plus petite id ; les licences des autres lignes restent valables tant que le produit est actif, y compris celles d'une ancienne ligne désactivée ; le premier « Enregistrer » sur la page du produit aligne toutes ses lignes sur les règles affichées. La migration vers `user_version` 2 (D74) crée une ligne de règles par défaut pour un produit qui n'en aurait aucune ;
  - console : menu Accueil, Demandes, Licences, Produits, Aide, Administration (Administration et son contrôle d'exposition sont repris de D68). Écran Produits : une ligne par produit (code, nom, règles en une phrase, nombre de licences, demandes en attente, Actif ou Inactif, étiquette « cree automatiquement » pour un produit créé par une demande et jamais enregistré depuis) et le bouton « Nouveau produit ». Page d'un produit : code définitif et nom, ligne `etdel_licence.installer(root, produit="CODE", version=APP_VERSION)` avec « Copier », liens « Voir ses licences » et « Nouvelle licence pour ce produit », puis les règles (nom, durée par défaut, essai, options ; repliés sous « Reglages avances » : tolérance, préavis, message d'accueil, actif). « Nouvelle licence » fait choisir le produit. La page d'une licence garde la présentation de D67, avec la clé consultable (D74). Les anciennes adresses `page=distributions` et `page=distribution` redirigent vers les écrans Produits. Aucun texte « distribution » ni « version minimale » dans la console, l'API, les e-mails, l'assistant d'installation ni l'aide : les tests le vérifient ;
  - **remplace** D68 pour tout ce qui concerne les distributions (distribution unité de gestion, produit simple étiquette, duplication, « Renommer », filtre par produit) ; dans D26, « une clé d'une autre distribution » se lit désormais « d'un autre produit » et la demande en attente unique vaut par ordinateur et par produit ; D34 (codes définitifs, désactivation par « Actif ») ne concerne plus que le code du produit.
- **Raison** : « une application, ses règles, ses licences » se comprend sans explication. Garder le schéma et le champ `distribution` du protocole évite toute migration risquée et laisse fonctionner telles quelles les bases existantes et les applications déjà livrées.

### D71 — 2026-10-06 — Création automatique du produit à la première demande
- **Question** : le propriétaire du projet ne veut rien avoir à préparer dans la console pour une nouvelle application.
- **Décision** : une opération `demander` dont le code produit est inconnu crée le produit (nom = code, actif) et sa ligne de règles avec les valeurs par défaut (durée 365 jours, essai 15, tolérance 15, préavis 5, aucune option), l'inscrit au journal (acteur `systeme`, action `produit_cree_automatiquement`), puis enregistre la demande normalement : l'essai vient de ces règles par défaut. La création se fait sous transaction immédiate (deux premières demandes simultanées ne créent qu'un produit). Un code qui ne diffère d'un produit existant que par la casse n'est jamais créé : l'application reçoit `produit_inconnu` (deux codes pareils à la lecture se confondraient dans la console ; même règle que pour la création à la main). L'e-mail de notification le signale : objet suffixé « (nouveau produit) », paragraphe « NOUVEAU PRODUIT » avec le lien vers la page du produit. `activer`, `valider`, `liberer` et `suivre_demande` ne créent jamais de produit et refusent comme avant (`produit_inconnu`, ou `cle_invalide`). Les limites ne changent pas (3 demandes par jour et par adresse IP). La création à la main reste possible (« Nouveau produit »), pour préparer des clés avant toute demande ; l'étiquette « cree automatiquement » disparaît au premier « Enregistrer » du produit.
- **Raison** : seule une demande, action explicite d'un utilisateur, limitée par adresse IP et notifiée à l'administrateur, peut créer quelque chose sur le serveur ; une activation ou un contrôle ne doit jamais créer un objet à partir d'un code quelconque.

### D72 — 2026-10-06 — Essai une fois par ordinateur et par produit
- **Question** : D68 accordait l'essai une fois par ordinateur et par distribution ; les distributions disparaissent (D70).
- **Décision** : l'essai est accordé une seule fois par ordinateur (empreinte) et par produit, toutes ses lignes de règles confondues, bases antérieures comprises ; une seule demande en attente par ordinateur et par produit ; pendant l'essai, l'application reçoit les options du produit. **Remplace** la règle « par distribution » de D68 et revient à celle de D26.
- **Raison** : le produit est désormais la seule unité ; compter par ligne de règles permettrait d'obtenir un second essai sur une base antérieure qui en a plusieurs.

### D73 — 2026-10-06 — Plus aucune version minimale
- **Question** : le propriétaire du projet demande de supprimer la version minimale partout.
- **Décision** : le serveur ne refuse jamais une requête pour sa version (plus de code `version_trop_ancienne`) ; le champ `version_min` du jeton est conservé pour la stabilité du format (annexe C) et vaut toujours `null` ; les champs disparaissent de la console (produit et règles) ; les colonnes `version_min` restent dans le schéma, sans effet. Module client 2.0.0 : plus de statut `VERSION_REFUSEE` ni de fenêtre « Mise a jour necessaire » ; une `version_min` reçue d'un ancien serveur est ignorée, et un refus `version_trop_ancienne` n'est qu'un contrôle échoué, sans blocage ; un refus de version mémorisé par un module 1.x est sans effet. La version de l'application est toujours envoyée et affichée dans la console (demande, licence), comme simple information. **Remplace** D13 (point `version_trop_ancienne`), D14 (partie `VERSION_REFUSEE`), D26 (contrôle de version et version minimale effective), D44 et la version minimale de la distribution de D68.
- **Raison** : choix explicite du propriétaire du projet : la mise à jour des applications se gère en dehors des licences, et un refus de version bloquait un client sans autre issue qu'une mise à jour. Garder le champ à `null` laisse le format du jeton inchangé pour les modules déjà livrés.

### D74 — 2026-10-06 — Clé consultable dans la console (écart au cahier des charges)
- **Question** : le propriétaire du projet demande : « J'aimerais que sur le serveur, on puisse voir la clé pour la redicter en cas de besoin. » Le cahier des charges (« seul le hash SHA-256 de la clé est stocké ») et D66 l'excluaient.
- **Décision** : écart assumé au cahier des charges, sur choix explicite du propriétaire du projet :
  - `cle_hash` reste la seule voie de recherche d'une clé ; en plus, chaque clé créée désormais (création dans la console, acceptation d'une demande, « Nouvelle cle ») est conservée chiffrée dans la nouvelle colonne `licences.cle_chiffree` (`sodium_crypto_secretbox`, avec le secret du serveur déjà utilisé pour les clés remises par une demande, D23 : `prive/cles/secret_demandes.key`, hors de la base). Migration du schéma vers `user_version` 2 dans `db_migrer()` et `schema.sql` ;
  - page de la licence : ligne « Cle : ETDEL-****-****-****-XXXX » et bouton « Afficher la cle » : formulaire POST avec jeton CSRF, sans confirmation puisque rien ne change (le jeton n'est pas renouvelé, les autres formulaires de la page restent valables), inscrit au journal (`cle_affichee`, avec l'utilisateur de la console et les 4 derniers caractères). La page est réaffichée avec la clé complète et un bouton « Copier ». La clé déchiffrée est comparée à `cle_hash` avant l'affichage ; en cas d'échec : « Cle complete illisible (secret du serveur remplace ?) : utilisez Nouvelle cle pour en generer une. » Une licence révoquée n'affiche plus sa clé ;
  - licences créées avant cette fonction : aucune clé conservée ; la page l'indique (« Cle complete non disponible (licence creee avant cette fonction) ») et donne les deux recours : le client lit sa clé dans l'application (Ctrl+Maj+L, « Afficher la cle »), sinon « Nouvelle cle » ;
  - la clé n'apparaît jamais dans le journal (détails compris), le journal d'erreurs PHP, les e-mails, les exports CSV ni les adresses des pages : seulement ses 4 derniers caractères ; la copie téléchargée de la base contient les clés chiffrées, jamais le secret ;
  - **remplace** la partie serveur de D66 (« seul le SHA-256 de la clé est stocké ») et, dans D31 et D67, la règle « la clé n'est affichée qu'une fois ».
- **Raison** : retrouver et redicter une clé est un besoin courant pour un administrateur seul (client qui réinstalle, clé perdue), et « Nouvelle cle » oblige le client à ressaisir. Mesures qui limitent le risque : chiffrement authentifié avec un secret hors de la base (une copie volée de la base ou d'une sauvegarde ne donne aucune clé), accès seulement par la console authentifiée en HTTPS, POST et jeton CSRF, chaque affichage journalisé avec son auteur, aucune copie en clair ailleurs. Risque résiduel accepté : qui contrôle tout l'hébergement (base et `prive/`) peut lire les clés conservées ; il pouvait déjà signer des réponses, et « Nouvelle cle » permet de remplacer une clé exposée.

### D75 — 2026-10-06 — Transfert vers un autre ordinateur, depuis l'application ou depuis la console
- **Question** : le propriétaire du projet veut qu'un client qui change d'ordinateur puisse le faire lui-même, sans passer par lui.
- **Décision** :
  - API : nouvelle opération `liberer` (requête signée, champs communs et `cle`), limitée comme `activer` (30 par heure et par adresse IP). Si la clé existe pour ce produit, est liée à **cet** ordinateur et que la licence n'est pas révoquée (active, ou suspendue : la suspension suit la licence), le serveur remet à NULL `machine`, `id_poste`, `nom_ordinateur` et `lie_le`, annule une éventuelle remise en attente de cette clé, inscrit `licence_transferee` au journal (acteur `poste:<id_poste>`, 4 derniers caractères seulement) et répond `ok` signé (même enveloppe que les autres opérations, `ok` vrai, `code` nul, sans jeton de licence). Refus : `cle_invalide` (clé inconnue ou d'un autre produit), `cle_liee_autre_poste` (liée ailleurs), `poste_revoque` (déjà libre), `revoquee`. Ajout fait pendant la réalisation : au plus 3 transferts par licence sur 30 jours (comptés dans le journal) ; au-delà, `transferts_trop_nombreux`, inscrit au journal (`transfert_refuse`), et rien ne change ; la console peut toujours libérer la clé ;
  - module client : dans la fenêtre « Licence », lien « Transferer cette licence » quand une clé est enregistrée et que la licence est utilisable ou bloquée par une suspension (aussi proposé sur la page « Licence suspendue ») ; fenêtre de confirmation qui montre la clé complète à noter (« Copier »), explique que l'application sera désactivée sur cet ordinateur et qu'il doit être connecté ; appel réseau dans un fil ; en cas de succès, le module efface sa clé et son état de licence, affiche « Licence transferee : saisissez la cle ETDEL-... sur le nouvel ordinateur. » puis l'application se ferme (« Fermer l'application »), comme après une révocation ; en cas d'échec (serveur injoignable, refus), rien ne change sur le poste et le message dit pourquoi. Une réponse `poste_revoque` compte comme un succès (réponse perdue d'un premier essai, ou clé déjà libérée dans la console). Nouvelle méthode publique `Garde.transferer()` : bloquante, à appeler hors du fil de l'interface, renvoie `{"ok", "code", "message"}` et ne lève jamais d'exception ;
  - console : « Changer d'ordinateur » est conservé (D67 : l'ancien ordinateur est bloqué à son contrôle suivant) ; son explication présente le transfert depuis l'application comme la voie à préférer quand l'ancien ordinateur fonctionne encore, et rappelle la limite des transferts.
- **Raison** : le transfert depuis l'application est immédiat (l'ancien ordinateur efface sa clé avant que le nouveau l'active), alors qu'une libération dans la console laisse les deux ordinateurs fonctionner jusqu'au contrôle suivant de l'ancien, voire jusqu'à la fin de sa tolérance s'il est hors ligne. Seul l'ordinateur lié peut libérer sa clé ; la limite empêche de faire tourner une clé d'un ordinateur à l'autre en profitant de la tolérance hors ligne de chacun.

### D76 — 2026-10-06 — Module client 2.0.0 sans distribution
- **Question** : l'API publique du module portait un paramètre `distribution` qui n'a plus de sens (D70).
- **Décision** : `installer(root, produit, version, palette=None)`, `Garde(produit, version, ...)`, `exiger(produit, version)` ; le paramètre `distribution` disparaît de l'API publique. En interne, les requêtes envoient `distribution` = code produit (champ du protocole v1) ; l'état local s'appelle `<PRODUIT>-<PRODUIT>.dat`, avec le même secret dérivé qu'en 1.x pour une distribution qui portait le code du produit (D11, avec le produit à la place de la distribution) ; au premier lancement en 2.0.0 sans fichier 2.0, l'état laissé par la 1.x (`<PRODUIT>-<DISTRIBUTION>.dat`) est repris (une clé d'abord, puis une demande, puis le plus récent), sans modifier ni supprimer les anciens fichiers ; l'écho `distribution` de chaque réponse reste vérifié, mais plus celui du jeton conservé (un jeton 1.x repris porte l'ancien code ; produit et empreinte suffisent à le lier au poste). `MODULE_VERSION = "2.0.0"`, empreinte republiée (`docs/INTEGRATION.md`, `client/test_licence_integration.py`). Appelants mis à jour : tests, démo, test d'intégration, documentation et modèle de brief (plus d'emplacement `{DISTRIBUTION}`). Démo : `installer(root, produit="DEMO", version=...)` ; avec `--banc`, rien à préparer dans la console, la première « Demander une licence » crée le produit `DEMO` (README mis à jour). Ordre de déploiement : serveur en version 2 d'abord, puis applications avec le module 2.0.0 (un serveur 1.x répondrait `produit_inconnu` quand le code de distribution diffère du code produit). **Remplace** D5 (signature de `exiger()`), la partie distribution de D11, D40 et D41 (codes produit et distribution : le produit seul) et la détection de la distribution de la démo dans D69.
- **Raison** : une application n'a plus qu'un code à connaître ; la reprise de l'état 1.x évite toute ressaisie de clé ou nouvelle demande sur les postes existants.

### D77 — 2026-10-06 — Aide de la console réécrite pour le modèle final
- **Question** : l'aide (D63) décrivait distributions, version minimale et clé visible une seule fois ; le propriétaire du projet veut qu'une personne qui ne connaît pas le serveur comprenne son fonctionnement, chaque réglage et chaque action.
- **Décision** : `prive/lib/aide.php` réécrit, même cadre technique (sommaire, sections à ancre, valeurs lues dans `config.php` et les constantes du code, lien « Aide sur cet ecran » de chaque écran). Dix-huit sections : « Comprendre en 2 minutes » (produit créé automatiquement, licence = un ordinateur, clé, les deux façons d'obtenir une clé), les tâches courantes pas à pas (mise en service, traiter une demande, créer et transmettre une licence, retrouver et redicter une clé, prolonger, transférer depuis l'application ou la console, suspendre avec ou sans date, réactiver, révoquer puis servir de nouveau le client, remplacer une clé), chaque écran et chaque champ (Accueil, Demandes, Licences, comparatif des actions, Produits avec la valeur à choisir pour chaque règle, Administration et ses cinq écrans, sécurité), ce que voit l'utilisateur dans l'application (fenêtres, bandeau, fenêtre Licence, transfert), la tolérance hors ligne jour par jour, une FAQ et le tableau de tous les codes de refus (dont `transferts_trop_nombreux`), un glossaire. L'écran Administration a sa propre section. Les tests vérifient l'absence de « distribution » et de « version minimale », la présence de chaque code de refus de l'API et des rubriques des tâches.
- **Raison** : D63 reste la règle (le mode d'emploi est dans la console, à jour avec le code) ; seul son contenu change avec le modèle.

## Licences hors ligne

### D78 — 2026-10-07 — Licence hors ligne : un code vérifié sans Internet, pour un seul poste
- **Question** : le propriétaire du projet veut équiper des ordinateurs jamais (ou rarement) connectés : « un code à dicter par téléphone ou à envoyer par e-mail, qui contient sa durée de validité, vérifié sans Internet ». Non révocable (accepté). Il a choisi les deux formes de code (D79, D80).
- **Décision** :
  - console : *Licences* › « Licence hors ligne » (aussi « Licence hors ligne pour ce produit » sur la page d'un produit actif et dans la liste filtrée sur un produit) : produit, identifiant du poste (saisie tolérante : majuscules, `O` lu `0`, `I` et `L` lus `1`, tiret et espaces facultatifs ; exactement 8 caractères Crockford, `U` refusé), client, durée en jours (celle du produit proposée ; vide = illimitée), case « Toutes les options » (cochée d'office ; décochée = aucune option). Bouton « Creer les codes », confirmation « Creer une licence hors ligne » ; la page de résultat montre les deux codes, chacun avec « Copier », et le récapitulatif. Une saisie refusée réaffiche le formulaire rempli, sous le message ;
  - base : une ligne `licences` par licence hors ligne (inventaire, recherche, journal, exports) : `hors_ligne = 1`, `machine` NULL, `id_poste` = identifiant saisi, échéance calculée, options `["*"]` ou `[]`, `cle_hash` = SHA-256 du code court, `cle_indice` = ses 4 derniers caractères, `cle_chiffree` = code court chiffré comme les clés (D74, réaffichable), nouvelle colonne `cle_long_hash` = SHA-256 du code long (index unique). Migration vers `PRAGMA user_version` 3 dans `db_migrer()` et `schema.sql` (colonnes ajoutées, aucune licence modifiée) ; `VERSION_SERVEUR` 2.1.0 ;
  - fiche de la licence : type « hors ligne » et rappel que Prolonger, Suspendre et Revoquer n'atteignent l'ordinateur que s'il se connecte un jour ; codes masqués et « Afficher les codes » (lecture sans confirmation, inscrite au journal `cle_affichee`, comme D74 : code court déchiffré, code long recalculé) ; poste (identifiant saisi dans la console et « jamais connecte » tant que le serveur n'a accepté aucun contrôle ; ordinateur lié ; ou « liee a aucun ordinateur » pour une licence déjà vue par le serveur puis détachée, D81, D82) ; échéance ; options ; dernier contact. Actions : « Nouveau code hors ligne pour ce poste » et « Nouveau code pour un autre ordinateur » (D82), puis Prolonger, Suspendre, Réactiver, Changer la date de fin et Révoquer, avec la mention « n'atteint le poste que s'il se connecte un jour a Internet » ; ni « Nouvelle cle » ni « Changer d'ordinateur » (refusés aussi par envoi direct) ; pas de tolérance dans les réglages avancés. Licence hors ligne révoquée : « Nouvelle licence hors ligne pour ce poste » (produit, client et poste repris ; `licence_remplacee` comme D67). Listes : étiquette « hors ligne » (licences, échéances proches de l'accueil), identifiant du poste suivi de « jamais connecte » (aucun contrôle accepté) ou « non lie » (licence déjà vue puis détachée) ; export CSV : colonne « hors ligne » ;
  - application, module 2.1.0 (empreinte republiée dans `docs/INTEGRATION.md` et `client/test_licence_integration.py`) : « J'ai une cle » accepte aussi ces codes ; vérification sur le poste, sans aucun appel réseau, dans un fil ; un code valable remplace la licence du poste (clé, jeton, blocage, demande) : statut VALIDE (« Licence hors ligne valable jusqu'au JJ/MM/AAAA » ou « Licence hors ligne illimitee ») jusqu'à l'échéance, puis EXPIREE (fenêtre « Licence hors ligne expiree », « Licence hors ligne expiree le ... : demandez un nouveau code a ETDEL », « J'ai une cle » en bouton principal). Garde anti-recul d'horloge habituelle (`heure_max`). `option()` : toutes les options si le code les accorde, aucune sinon, tant qu'aucun jeton signé n'est reçu. Fenêtre Licence : ligne « Code hors ligne » (masqué, « Afficher le code »), échéance, « Verifier maintenant » actif ; « Transferer cette licence » seulement une fois le poste lié par un contact. Un code court tapé sans `ETDHL` (le champ ajoute alors `ETDEL-`) est reconnu à son MAC ;
  - échéance : `jours` = numéro du dernier jour de validité (jour 0 = 2026-01-01 UTC) + 1, 0 = illimitée ; la licence vaut jusqu'à la fin de ce jour en UTC. Pour une durée D saisie, ce dernier jour est celui qui contient « maintenant + D jours » (jamais moins que demandé, au plus un jour de plus). Point laissé ouvert par la spécification, tranché au plus simple : la console et l'application affichent toutes deux ce dernier jour, calculé en UTC, pour montrer la même date.
- **Raison** : une ligne de `licences` par poste garde la licence hors ligne dans tout l'existant (recherche, statuts, journal, sauvegardes, exports) sans table nouvelle ; l'identifiant du poste, déjà affiché par l'application et facile à dicter, suffit à lier le code à l'ordinateur sans aucun échange préalable ; une date de fin en jours entiers tient en 16 bits, ce qui garde le code court dictable.

### D79 — 2026-10-07 — Code court à dicter : HMAC-SHA256 tronqué et `LICENCE_SECRET_HORS_LIGNE`
- **Question** : comment un code dictable de 16 caractères peut-il être vérifié par l'application sans Internet ?
- **Décision** :
  - format `ETDHL-XXXX-XXXX-XXXX-XXXX` : 16 caractères Crockford = 80 bits : version (2 bits = 1), options (1 bit : 1 = toutes), jours (16 bits, D78), MAC (56 bits = 7 premiers octets de HMAC-SHA256(secret, `"ETDHL1|" + produit + "|" + id_poste + "|" + jours + "|" + options`)), somme de contrôle (5 bits : somme des 15 premiers caractères modulo 32, comme les clés). Saisie tolérante (majuscules, tirets et blancs ignorés ; dans le corps, `O` lu `0`, `I` et `L` lus `1`). Vecteurs de test fixes en tête de `prive/lib/hors_ligne.php`, vérifiés côté serveur et côté client ;
  - secret : 32 octets aléatoires du serveur, `prive/cles/secret_hors_ligne.key`, créé par `install.php` ou, pour une installation antérieure, au premier besoin (écran *Clés de signature* ou première licence hors ligne ; journal `secret_hors_ligne_cree`), seulement pour une base qui n'en a jamais eu. Son empreinte SHA-256 est inscrite dans les réglages (`secret_hors_ligne_empreinte`) dès sa création (par `install.php`, ou au premier besoin), même si aucun code n'existe encore : un fichier perdu ou remplacé ensuite n'est **jamais** recréé, puisque des applications livrées l'embarquent peut-être déjà (une base antérieure sans empreinte mais avec des licences hors ligne est traitée de même) ; l'écran *Clés* et la création d'une licence hors ligne affichent « Secret des codes hors ligne absent ... » ou « ... different ... » (à restaurer depuis la copie de secours). Il ne change pas lors d'une rotation de la clé de signature ;
  - client : nouvelle constante `LICENCE_SECRET_HORS_LIGNE` (base64url sans remplissage) en tête de `etdel_licence.py`, quatrième ligne de l'écran *Clés de signature* et de la page de fin d'`install.php`. Vide : codes courts refusés (« Codes hors ligne courts non pris en charge par cette application : demandez le code long (ETDHL2-...) a ETDEL »), codes longs acceptés.
- **Raison** : 16 caractères se dictent comme une clé ETDEL ; 56 bits de MAC rendent un code deviné improbable (une chance sur 2^56 par saisie) ; un seul secret par serveur suffit puisque le produit et le poste sont dans le message. Ne jamais recréer le secret en silence protège les applications déjà livrées, qui embarquent l'ancien. Limite assumée (D83) : le secret est dans chaque application, donc un code court peut être fabriqué par qui décompile une application.

### D80 — 2026-10-07 — Code long à envoyer par e-mail : signature Ed25519
- **Question** : une forme de code impossible à fabriquer, quitte à ne pas être dictable.
- **Décision** : 68 octets : version (1 octet = 2), jours (2 octets), options (1 octet, 0 ou 1), signature Ed25519 (64 octets) de `"ETDHL2|" + produit + "|" + id_poste + "|" + jours + "|" + options` par la clé de signature active du serveur ; encodage base32 Crockford (109 caractères, un bit 0 de remplissage), `ETDHL2-` puis groupes de 4 séparés par des tirets (143 caractères) ; saisie tolérante (espaces, retours à la ligne, tirets, minuscules). Le client vérifie avec toutes les clés publiques qu'il connaît : clés adoptées encore acceptées, puis clé embarquée, sauf si elle est elle-même retirée (clé adoptée remplacée par un bulletin depuis plus de 90 jours) ; pour un code déjà enregistré, aussi les clés retirées. Un ancien code long reste donc valable sur un poste qui l'a déjà saisi, mais un poste qui a adopté la nouvelle clé le refuse à une nouvelle saisie 90 jours après la rotation. Le serveur ne stocke que le SHA-256 du code long et le recalcule à l'affichage (signature déterministe) ; après une rotation, le recalcul ne correspond plus au hachage : la fiche affiche « Code long non reaffichable » (l'ancien code long reste reconnu par l'API et valable sur les postes qui connaissent l'ancienne clé).
- **Raison** : sans la clé privée, qui ne quitte jamais le serveur, personne ne peut produire un code long accepté ; la clé de signature existe déjà et les applications savent vérifier Ed25519, aucun nouveau secret n'est nécessaire. Limite assumée (D83) : un poste jamais connecté ne connaît que la clé publique de son application.

### D81 — 2026-10-07 — Contrôle opportuniste quand Internet est disponible
- **Question** : le propriétaire du projet précise : « Le code hors ligne n'empêche pas de tester malgré tout si Internet est dispo au lancement, et si jamais la durée a changé ou une révocation a eu lieu. »
- **Décision** :
  - module : avec une licence hors ligne, le cycle de contrôle habituel continue (3 secondes après le lancement, puis toutes les 6 heures après un contrôle tranché, 15 minutes après un échec — donc toutes les 15 minutes, en fond, sur un poste jamais connecté —, et « Verifier maintenant ») ; il envoie `valider` avec `cle` = le code saisi (forme canonique). Le champ `cle` de l'API passe de 64 à 256 caractères ;
  - serveur : `activer`, `valider` et `liberer` acceptent un code `ETDHL-` ou `ETDHL2-` ; la licence est retrouvée par `cle_hash` (court) ou `cle_long_hash` (long), parmi les licences hors ligne du même produit ; le serveur ne vérifie ni MAC ni signature. **Premier contact** (`machine` NULL) : `activer` ou `valider` lie l'ordinateur réel seulement si `id_poste(machine, produit)` est l'identifiant saisi dans la console ; sinon `cle_liee_autre_poste` (journal `refus_autre_poste`, 4 derniers caractères seulement). Ensuite, comme une licence ordinaire : machine liée, autre ordinateur refusé ;
  - le client applique le jeton signé reçu : échéance et options (prolongation ou raccourcissement) ; révocation, code remplacé ou inconnu (`revoquee`, `cle_invalide`, `poste_revoque`, `cle_liee_autre_poste`) : le code est effacé et inscrit dans une liste locale de codes retirés (empreinte produit, poste, jours, options ; 50 au plus), qui fait refuser ses deux formes à la saisie, même sans réseau (« Code hors ligne revoque, remplace ou transfere : demandez un nouveau code a ETDEL ») ; suspension : blocage, code conservé ; une suspension datée reçue se lève à sa date même sans nouveau contact (décision prise en réalisation : le poste s'en tient à la dernière information reçue, qui la prévoyait). Ressaisir la même licence, sous la même forme ou sous l'autre, ne lève ni une suspension ni une échéance reçue ;
  - serveur injoignable : **aucun effet**, ni tolérance, ni bandeau, ni blocage ; la licence vaut jusqu'à la plus récente échéance connue : celle du code, puis celle du dernier jeton signé reçu. Pas non plus de bandeau avant l'échéance : statut VALIDE puis EXPIREE, comme le prévoit la spécification ;
  - transfert depuis l'application (D75) : seulement une fois le poste lié ; `liberer` détache l'ordinateur mais garde `id_poste` (le code ne vaut que pour ce poste) ; l'application retire le code et affiche « Licence transferee : communiquez a ETDEL l'identifiant du poste du nouvel ordinateur pour obtenir son code. ».
- **Raison** : un poste « rarement » connecté reçoit ainsi tout ce que la console a changé, sans rien perdre quand il est hors ligne ; ne lier que l'identifiant saisi empêche qu'un code dicté pour un poste serve d'abord sur un autre ; la liste des codes retirés empêche de défaire hors ligne une révocation reçue en ressaisissant le code.

### D82 — 2026-10-07 — Prolonger ou changer d'ordinateur : un nouveau code sur la même licence
- **Question** : comment prolonger un poste qui ne se connecte jamais, remplacer un ordinateur ou corriger un identifiant mal dicté ?
- **Décision** :
  - « Nouveau code hors ligne pour ce poste » (licence active ou suspendue) : durée comptée à partir d'aujourd'hui (celle du produit proposée) et case des options ; les nouveaux codes remplacent les anciens **sur la même ligne** (échéance, options, hachages, code chiffré ; statut et historique conservés ; journal `code_hors_ligne_remplace`, 4 derniers caractères seulement) ; le serveur ne reconnaît plus l'ancien code, qu'un poste qui se connecterait avec lui perdrait. Une durée et des options qui redonneraient le code actuel sont refusées ;
  - « Nouveau code pour un autre ordinateur » (ajout fait pendant la réalisation) : identifiant du nouveau poste, durée, options ; la licence passe à ce poste, n'est plus liée à aucun ordinateur et reçoit de nouveaux codes. C'est la suite d'un transfert depuis l'application (l'action vient alors en premier sur la fiche), le remplacement d'un ordinateur perdu ou la correction d'un identifiant mal dicté ;
  - un code est entièrement déterminé par le produit, le poste, la fin et les options : si le code court obtenu est déjà celui d'une autre licence, la fin est repoussée d'un jour (30 fois au plus, la page le signale) ; une licence illimitée identique est refusée (« indiquez une duree ») ;
  - saisie du nouveau code : par « J'ai une cle » une fois la licence bloquée (fenêtre « Licence hors ligne expiree ») ; tant que la licence est valable, l'application ne propose aucune saisie, comme pour une clé. Pour une application en mode console, `exiger()` demande aussi le code au clavier quand la licence hors ligne est bloquée (statut EXPIREE : expirée, ou suspendue, comme la fenêtre d'activation qui propose « J'ai une cle » dans les deux cas ; sinon, sans fenêtre, le nouveau code ne pourrait jamais être saisi) ; même message d'invite, identifiant du poste rappelé.
- **Raison** : garder une seule ligne par poste conserve l'historique et évite de multiplier les licences d'un même ordinateur ; deux licences ne doivent jamais partager un code (recherche par hachage unique, retrait côté client par empreinte) ; la saisie à l'échéance est le parcours décrit par la spécification (« J'ai une cle » pour saisir le nouveau), et la durée comptée depuis la création invite à créer le code quand le client appelle.

### D83 — 2026-10-07 — Limites assumées des licences hors ligne
- **Question** : quelles limites accepter et documenter ?
- **Décision** : limites assumées, écrites dans l'aide de la console (rubrique « Limites »), sur l'écran *Clés de signature*, dans `docs/INTEGRATION.md` et `docs/LISEZ-MOI-SERVEUR.md` :
  - **non révocable tant que le poste ne se connecte pas** : un code transmis vaut sur son poste jusqu'à son échéance (ou toujours s'il est illimité) ; suspendre, révoquer, raccourcir ou remplacer le code n'y change rien tant qu'il ne joint pas le serveur ;
  - **code court falsifiable** par quelqu'un qui décompile une application (le secret y est inscrit) : protection dissuasive seulement ; le secret étant unique pour le serveur (D79), celui extrait d'une seule application permet de fabriquer des codes courts pour tous les produits et tous les identifiants de poste ; un code fabriqué est inconnu du serveur et effacé si le poste se connecte. **Le code long est infalsifiable** ;
  - **après une rotation de la clé de signature**, les codes longs créés ensuite ne fonctionnent que dans les applications construites avec la nouvelle clé publique (ou qui l'ont adoptée en se connectant) ; le code court n'est pas concerné ;
  - pas de préavis avant l'échéance, et pas de titulaire affiché tant que le poste ne s'est pas connecté (le nom du client n'est pas dans le code) ;
  - une copie de la base contient, pour un code court, son hachage, ses 4 derniers caractères, le produit, le poste, l'échéance et les options : il reste 41 bits inconnus, qu'un calcul de force brute retrouve ; les copies sont à protéger comme les codes.
- **Raison** : choix explicite du propriétaire du projet (« Non révocable (accepté) », les deux formes de code) ; un code vérifié sans Internet ne peut pas apprendre une révocation, et un secret embarqué ne peut pas rester secret face à la décompilation ; le code long existe précisément pour qui veut un code infalsifiable.

### D84 — 2026-10-07 — Aide de la console : licences hors ligne pour un débutant
- **Question** : la console a de nouveaux écrans, champs et actions ; l'application de nouveaux messages.
- **Décision** : `prive/lib/aide.php` complété, même cadre que D77 : tâche pas à pas « Poste toujours hors ligne : licence hors ligne » (identifiant du poste à se faire dicter, création, code court par téléphone ou code long par e-mail, ce que tape l'utilisateur, refus et remèdes, prolongation par un nouveau code, ce qui se passe s'il se connecte un jour) ; nouvelle section « Licences hors ligne : postes jamais connectes a Internet » (comparatif clé / licence hors ligne, les deux codes, écran et champs, page de résultat, fiche et actions avec leurs confirmations, « Si l'ordinateur se connecte un jour », « Limites », journal), vers laquelle mène le lien « Aide sur cet ecran » de l'écran *Licence hors ligne* ; les autres sections mises à jour (quatre lignes du module, *Clés de signature* et rotation, sauvegarde et restauration, journal et exports, sécurité, ce que voit l'utilisateur, statuts, mode console, tolérance, FAQ — huit questions —, codes de refus, glossaire — six termes). Les libellés des confirmations et la mention « n'atteint le poste... » sont lus dans les constantes du code. Tests (`test_console_aide`) : section et rubriques présentes, étapes de la tâche, limites, chaque libellé des écrans hors ligne (champs, cases, boutons, actions dépliables, liens) cité entre guillemets, et chaque message de l'application cité présent tel quel dans `client/etdel_licence.py`.
- **Raison** : D63 et D77 restent la règle : le mode d'emploi vit dans la console, vérifié contre le code.

## Publication

### D85 — 2026-10-14 — Licence du projet
- **Question** : D3 laissait au propriétaire du projet le choix de la licence ; les README annonçaient « GPL-3.0 », sans dire si une version ultérieure de la GPL est acceptée.
- **Décision** : GPL-3.0-or-later (GNU GPL version 3 ou ultérieure). `LICENSE`, texte officiel de la GPL version 3, inchangé ; badge, section Licence et pied de page des deux README alignés.
- **Raison** : choix du propriétaire du projet : la même licence que ses autres projets publiés.

### D86 — 2026-10-15 — Conditions additionnelles (article 7) et mention visible
- **Question** : le propriétaire du projet veut que toute copie ou version modifiée de Janus garde, visible pour l'utilisateur et dans sa documentation, la mention « Janus, par ETDEL — https://github.com/theptitprince/Janus », sans que Janus cesse d'être un logiciel libre.
- **Décision** : conditions additionnelles permises par l'article 7 de la GPL v3 (7 b : attribution ; 7 c : origine, une version modifiée doit le dire et ne pas se présenter comme l'original), dans un fichier `NOTICE` à la racine du dépôt, en français (qui fait foi) et en anglais ; `LICENSE` reste le texte officiel, inchangé. Le code Ed25519 en Python pur du module suit le code de référence de la RFC 8032, section 6 (IETF Trust et auteurs de la RFC, licence BSD simplifiée) : `NOTICE` l'exclut de ces conditions et le crédite, comme les README ; le module, qui le crédite aussi, reproduit l'avis de copyright de l'IETF Trust et le texte de cette licence (dispositions juridiques de l'IETF Trust, section 4.c), comme elle l'exige pour le code repris. Chaque fichier source garde « ETDEL (c) 2026 » suivi, en ASCII, de la mention et de « GPL-3.0-or-later + conditions additionnelles (article 7) : voir NOTICE » ; le module, qui voyage seul dans les applications, renvoie au `NOTICE` du dépôt par son adresse ; l'`admin/.htaccess` généré par l'assistant porte les mêmes lignes. Mention visible : pied de page de chaque écran de la console (texte seul, sans lien ni style en ligne, règle `footer.mentions` de `style.css`), lignes « Logiciel » et « Licence » de *Réglages* › « Serveur », fenêtre « Licence » du module (en petit, au-dessus du pied, constante `_MENTION_LICENCE`), bas de la fenêtre de l'application de démonstration ; l'aide de la console décrit ces mentions (D63, D77). Module 2.1.1, empreinte republiée dans `docs/INTEGRATION.md` (D39) ; serveur 2.1.1 ; ni l'API, ni le protocole, ni le schéma de la base ne changent.
- **Raison** : choix du propriétaire du projet, le même pour ses autres projets publiés : rester un logiciel libre tout en gardant visible l'origine de chaque copie. Version de correctif : aucune fonction nouvelle pour les applications ; les octets du module changent, donc sa version et son empreinte aussi.

## Évolutions 2.2.0 : système en service

Le serveur 2.1.1 est en production et des applications sont livrées avec le module 2.1.1 : chaque changement reste compatible avec eux (protocole v1 inchangé, schéma de la base seulement complété, clé de signature et secret des codes hors ligne intacts).

### D87 — 2026-10-19 — Refus `requete_invalide` lisible par l'application
- **Question** : D24 renvoyait `requete_invalide` en HTTP 400 ; le module, qui ne lit pas le corps d'une erreur HTTP, affichait « serveur injoignable » (diagnostic « http 400 ») et son message « Requete refusee par le serveur de licences » n'apparaissait jamais. Une application mal intégrée (code produit avec un espace, version de plus de 32 caractères) semblait donc avoir un problème de réseau.
- **Décision** : serveur 2.2.0 : HTTP 200 pour ce refus signé, comme pour tous les autres ; les modules déjà livrés (2.1.x) l'affichent alors tel quel, sans mise à jour. Module 2.2.0 : le corps d'une réponse d'erreur HTTP est lu ; un refus signé et lié à la requête (nonce, produit, machine) y est pris en compte, pour un serveur 2.1.x encore en service ; une réponse non signée, ou un accord (`ok` vrai) hors HTTP 200, reste un échec. **Remplace** le code HTTP de D24 ; le refus reste signé et transitoire (nouvel essai 15 minutes plus tard).
- **Raison** : le diagnostic doit dire la vraie cause ; la correction côté serveur suffit pour les postes déjà équipés, celle du module couvre un serveur pas encore mis à jour.

### D88 — 2026-10-19 — Ménage : licences révoquées et produits sans licence
- **Question** : le propriétaire du projet veut pouvoir « faire du ménage » : supprimer les licences révoquées et les produits qui n'ont plus de licence.
- **Décision** :
  - console : écran *Administration* › « Menage », avec la liste de ce qui partira et un bouton par liste (« Supprimer les N licences revoquees », « Supprimer les N produits sans licence » ; confirmations « Supprimer definitivement les licences revoquees » et « Supprimer definitivement les produits sans licence ») ; un élément à la fois : « Supprimer cette licence » sur la fiche d'une licence révoquée (seule action qui lui reste), « Supprimer ce produit » en bas de la page d'un produit sans licence. Le formulaire porte les numéros affichés : seul ce que l'administrateur a vu est supprimé, sous transaction ; ce qui a changé entre-temps est gardé et signalé ;
  - licence : seulement révoquée (définitif). Ses demandes déjà traitées restent, sans lien vers elle (l'essai, accordé une fois par ordinateur et par produit, n'est pas redonné) ; ses demandes de renouvellement (D89) sont effacées ; une clé supprimée est inconnue de l'API (`cle_invalide`), qu'un poste traite comme une révocation ;
  - produit : sans aucune licence, quel qu'en soit le statut, et sans demande en attente ; ses règles et ses demandes traitées partent avec lui. Une nouvelle demande de l'application le recrée avec les règles par défaut (D71), essai compris : c'est assumé ;
  - journal : choix du propriétaire, les licences seules sont supprimées et le journal garde tout. Comme SQLite peut redonner l'id de la dernière ligne supprimée, les cibles des lignes supprimées sont marquées (« licence 12 (supprimee) », « produit 3 (supprime) », « demande 7 (supprimee) ») : historique d'une fiche, quota de transferts (D75) et étiquette « cree automatiquement » (D71) ne mélangent jamais deux lignes de même numéro ; une remplaçante supprimée n'est plus désignée sur la licence révoquée qu'elle remplaçait (D67). Actions : `licence_supprimee`, `produit_supprime`, `menage_licences`, `menage_produits` ;
  - aide de la console : tâche « Faire le menage », rubrique « Menage » (écran Administration), question de la FAQ ; nouveau fichier `prive/lib/menage.php`.
- **Raison** : une licence révoquée ne sert plus et encombre les listes ; un produit sans licence est le plus souvent un essai ou une erreur. Garder le journal conserve la trace de ce qui a été fait, sans effort pour l'administrateur.

### D89 — 2026-10-19 — Demande de renouvellement depuis l'application
- **Question** : le propriétaire du projet veut, dans la fenêtre « Licence » de l'application, un bouton pour demander le renouvellement pendant les derniers jours de la licence, quand le bandeau annonce les jours restants. Il a choisi une demande reçue dans la console (plutôt qu'un simple e-mail).
- **Décision** :
  - API : nouvelle opération `renouveler` (requête signée, champs communs, `cle`, `message` facultatif de 200 caractères), limitée à 10 par jour et par adresse IP. Pour la licence de cette clé et de ce produit, liée à ce poste (licence hors ligne : poste saisi), ni révoquée ni suspendue, avec une échéance (expirée de peu : acceptée) : une demande enregistrée dans la nouvelle table `renouvellements` (migration du schéma vers `user_version` 4), une seule en attente par licence (un renvoi répond comme la première fois, sans nouvel e-mail), e-mail à l'administrateur (« [ETDEL Licences] Renouvellement demande - PRODUIT - CLIENT »), journal `renouvellement_demande` (acteur `poste:XXXX-XXXX`). Réponse `ok` signée, avec le numéro de la demande. Refus : ceux des autres opérations, plus `licence_perpetuelle` ;
  - console : demandes de renouvellement sur l'Accueil (étiquette « renouvellement ») et dans *Demandes* (« Renouvellements demandes », « Renouvellements traites »), comptées dans la pastille du menu ; page de la demande : « Prolonger » (durée du produit proposée, ajoutée à l'échéance actuelle, ou à aujourd'hui si elle est passée) ou « Refuser » (motif inscrit au journal ; l'application n'en est pas prévenue). La fiche de la licence signale une demande en attente ; « Prolonger » sur la fiche l'accepte, « Revoquer » la refuse (motif « licence revoquee »). Actions `renouvellement_accepte`, `renouvellement_refuse`. Nouveau fichier `prive/lib/renouvellements.php` ;
  - module 2.2.0 : dans les 15 derniers jours (`_PREAVIS_ECHEANCE_J`, ceux du bandeau d'échéance) d'une clé ETDEL à échéance, déjà vérifiée par le serveur, la fenêtre « Licence » montre sous l'échéance le lien « Demander le renouvellement », et le bandeau remplace « Details » par « Renouveler » (qui ouvre la fenêtre « Licence » puis la demande). Fenêtre « Demander le renouvellement » : échéance rappelée, « Mot pour ETDEL », mention du délai, « Envoyer la demande » (réseau dans un fil), page « Demande envoyee ». Après l'envoi, le lien devient « Demande envoyee le JJ/MM/AAAA » (renvoi possible) jusqu'à ce que l'échéance change ; la date est gardée dans l'état local (champ `renouvellement`, ignoré par un module plus ancien). Nouvelle méthode publique `Garde.renouveler(message="")`, bloquante, qui ne change rien sur le poste ; un refus du serveur n'y est qu'un message (le contrôle habituel applique une révocation). Ni licence perpétuelle, ni licence hors ligne (sans bandeau), ni cadre `cadre_licence()` des réglages, ni mode console ;
  - ordre de déploiement : serveur 2.2.0 d'abord, puis applications avec le module 2.2.0. Face à un serveur 2.1.x, la demande est refusée (`requete_invalide`, D87) avec un message clair ; tout le reste fonctionne.
- **Raison** : le client agit au moment où le bandeau l'alerte, sans chercher comment joindre ETDEL ; l'administrateur garde la décision, et sa réponse réutilise la prolongation existante, que l'application reçoit déjà à son contrôle. Une table à part laisse intacte la logique des demandes de licence (essai unique, suivi par jeton). Module et serveur 2.2.0 ; protocole v1 inchangé pour les opérations existantes.

### D90 — 2026-10-19 — Changer d'avis entre licence perpétuelle et licence datée
- **Question** : le propriétaire du projet remarque qu'une licence créée sans durée (perpétuelle) ne peut plus recevoir d'échéance : « Prolonger » n'existe pas pour elle (D33) et l'aide proposait une nouvelle licence, puis la révocation de l'ancienne, ce qui oblige le client à ressaisir une clé.
- **Décision** : console : sur la fiche d'une licence perpétuelle, l'action « Fixer une echeance » (« +30 jours » et « +1 an » à partir d'aujourd'hui, ou une date, comme « Prolonger » ; confirmation « Fixer une echeance (la licence ne sera plus perpetuelle) », message « Echeance fixee : la licence n'est plus perpetuelle. ») ; en bas du panneau « Prolonger » d'une licence datée, « Rendre perpetuelle » (confirmation « Rendre la licence perpetuelle (sans echeance) », message « Licence rendue perpetuelle. »). Même licence, même clé : l'application reçoit le changement à son contrôle suivant (l'échéance est lue dans chaque jeton, module 2.1.1 compris) ; une demande de renouvellement en attente (D89) est close comme acceptée. Journal `licence_prolongee` (« perpetuelle -> JJ/MM/AAAA » ou l'inverse). Licence hors ligne : de même, mais l'ordinateur ne l'apprend que s'il se connecte. Licence révoquée : rien. **Remplace** dans D33 « une licence perpétuelle ne se prolonge pas » ; la réponse de la FAQ de l'aide est réécrite.
- **Raison** : changer d'avis sur la durée est une opération courante, que le protocole permettait déjà ; seule la console l'interdisait.
