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
- **Raison** : choix juridique qui revient au propriétaire du projet (à trancher).

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
- **Décision** : PHP 8.3 portable dans `php/` (ignoré par Git) ; `php -S` directement sur `serveur/www/` avec `routeur_banc.php`, configuration `banc-licences` dans `.claude/launch.json`. Le routeur décode l'URL et la normalise comme le système de fichiers Windows (casse, antislash, points et espaces finaux, flux `:`) avant de refuser `prive/`, `data/`, `.ht*` et les extensions sensibles : sans cela, `/PRIVE/Data/Licenses.DB` aurait été servi. `serveur/tests/smtp_banc.py` reçoit les e-mails de PHP (SMTP sur 127.0.0.1:2525) et les range dans `serveur/tests/courriels/` (ignoré par Git).
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
