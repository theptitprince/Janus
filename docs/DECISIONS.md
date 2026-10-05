# Décisions

Choix faits pendant la réalisation, sur les points non couverts ou ambigus du cahier des charges (consigne 0.1). Chaque entrée : date, question, décision, raison.

## Organisation

### D1 — 2026-10-05 — Nom et dépôt
- **Question** : le cahier des charges nomme le dépôt `etdel-licence` ; la session de travail fournit le dépôt `theptitprince/Janus`.
- **Décision** : le projet s'appelle **Janus** (dieu romain des portes et des passages) et vit dans ce dépôt.
- **Raison** : demande « tu trouveras un nom » ; le dépôt existait déjà et c'est le seul accessible en écriture.

### D2 — 2026-10-05 — Une branche par étape
- **Question** : « une branche et un commit par étape » alors que la session n'autorise qu'une branche de travail.
- **Décision** : un commit par étape, dans l'ordre, sur la branche `claude/python-windows-license-system-v8kmek`.
- **Raison** : contrainte de l'environnement ; l'historique reste lisible étape par étape.

### D3 — 2026-10-05 — Fichier LICENSE existant
- **Question** : le dépôt a été créé avec une licence GPL v3 alors que le code est propriétaire (« ETDEL (c) 2026 »).
- **Décision** : fichier laissé tel quel.
- **Raison** : choix juridique qui revient à Etienne (à trancher).

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
- **Question** : « secret dans config.php », alors qu'Etienne n'a aucun outil local pour générer 32 octets aléatoires.
- **Décision** : `install.php` génère le secret dans `prive/cles/secret_demandes.key` (0600, à côté des clés privées) ; une valeur `secret_demandes` (base64) dans `config.php`, si elle existe, est prioritaire.
- **Raison** : respecte « aucun utilitaire sur le poste d'Etienne » ; même protection que les clés de signature.

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
  - version minimale effective = la plus exigeante entre produit et distribution ; une demande venant d'une version trop ancienne est refusée ;
  - une clé d'une autre distribution répond `cle_invalide` ;
  - `ping` n'est pas limité ; l'adresse IP retenue est `REMOTE_ADDR` (les en-têtes de type X-Forwarded-For sont falsifiables) ;
  - une seule demande en attente par poste et par distribution ; l'essai, lui, n'est accordé qu'une fois par poste et par produit, toutes distributions confondues ;
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
