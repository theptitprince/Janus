# Serveur de licences ETDEL — déploiement et exploitation sur OVH mutualisé

Tout se fait avec l'espace client OVH, un client FTP (FileZilla par exemple) et un navigateur. Aucun outil n'est à installer sur le poste de l'administrateur, aucun accès SSH n'est nécessaire.

## 1. Ce qu'il faut avant de commencer

- Un hébergement mutualisé OVH avec **PHP 8.1 ou plus** (extensions `pdo_sqlite` et `sodium`, présentes par défaut chez OVH).
- Un **sous-domaine** dédié, par exemple `licence.mondomaine.fr`, et si possible un **domaine de secours** (second domaine ou autre sous-domaine) pointant vers le même hébergement.
- Les identifiants FTP de l'hébergement.
- Le dossier `serveur/` du dépôt Janus.

À trancher avant l'installation : nom de domaine et sous-domaine, domaine de secours. La liste des produits n'a pas à être préparée : la première demande de licence envoyée par une application crée son produit, avec les règles par défaut.

## 2. Organisation des fichiers sur l'hébergement

Tout le serveur tient dans le dossier `serveur/www/` du dépôt, qui s'envoie tel quel :

```
/ (racine du compte FTP)
└── licence/      ← contenu de serveur/www/ : dossier servi par le web
    ├── .htaccess          HTTPS forcé, en-têtes de sécurité, pas de listing
    ├── install.php        assistant d'installation (usage unique, puis verrouillé)
    ├── api/v1/index.php   API utilisée par les applications
    ├── admin/             console d'administration (protégée par mot de passe)
    └── prive/             jamais servi (.htaccess « Require all denied »)
        ├── config.php     paramètres (créé à partir de config.exemple.php)
        ├── .htpasswd      créé par install.php
        ├── cles/          clés privées de signature et leurs fiches publiques, secret des demandes,
        │                  secret des codes hors ligne (secret_hors_ligne.key), créés par install.php
        ├── data/          créé par install.php
        │   ├── licenses.db    base SQLite (mode WAL)
        │   └── sauvegardes/   copies quotidiennes
        └── lib/ …
```

`prive/` est protégé par son propre `.htaccess` (`Require all denied`) et, en double, par les règles de `licence/.htaccess` qui refusent bases, clés et fichiers cachés. Le « Contrôle d'exposition » de l'écran *Administration* de la console le vérifie sur l'hébergement réel (§ 4).

**Variante plus sûre, si l'on veut :** déplacer `licence/prive/` à la racine du compte, **à côté** de `licence/` (et non dedans). Le code le cherche d'abord à cet endroit ; rien d'autre ne change. Une erreur de configuration d'Apache ne pourrait alors plus exposer la base. À faire de préférence avant l'installation, ou en déplaçant tout le dossier `prive/` d'un bloc.

Le dossier `serveur/tests/` ne s'envoie pas sur l'hébergement.

## 3. Installation pas à pas

1. **Sous-domaine et HTTPS.** Espace client OVH › Hébergements › *Multisite* › *Ajouter un domaine ou sous-domaine* : `licence.mondomaine.fr`, dossier racine `licence`, case **SSL** cochée (certificat Let's Encrypt). Faire de même pour le domaine de secours, avec le **même** dossier racine. Attendre que le certificat soit actif (quelques minutes à quelques heures).
2. **Version de PHP.** Hébergements › *Informations générales* › *Version PHP globale* : 8.1 ou plus.
3. **Configuration.** Sur le poste, copier `serveur/www/prive/config.exemple.php` en `config.php` (même dossier) et inscrire dans `jeton_installation` une chaîne aléatoire d'au moins 20 caractères (un générateur de mots de passe convient). Les autres valeurs conviennent telles quelles.
4. **Envoi FTP.** Envoyer le contenu de `serveur/www/` (dont `prive/` avec `config.php`) dans `licence/`. Ne pas oublier les fichiers cachés `.htaccess`.
5. **Assistant.** Ouvrir `https://licence.mondomaine.fr/install.php` et remplir : jeton d'installation, identifiant et mot de passe de la console (20 caractères minimum), URL de l'API (proposée), URL de secours (`https://<domaine de secours>/api/v1/`), adresse(s) de notification. L'assistant crée la base, la paire de clés de signature, le secret des demandes, le secret des codes hors ligne et le `.htpasswd`, protège `/admin/`, puis se verrouille.
6. **Noter le résultat.** La page finale affiche la clé publique et les quatre lignes à reporter dans `client/etdel_licence.py` (voir `docs/INTEGRATION.md`, « Après l'installation du serveur ») : `LICENCE_URL`, `LICENCE_URL_SECOURS`, `LICENCE_CLE_PUBLIQUE` et `LICENCE_SECRET_HORS_LIGNE` (secret des codes hors ligne courts, § 4). Ces lignes restent consultables dans la console, *Administration* › *Clés de signature*.
7. **Vérifier.**
   - `https://licence.mondomaine.fr/install.php` répond « Installation deja effectuee » ;
   - `https://licence.mondomaine.fr/admin/` demande l'identifiant et le mot de passe ;
   - `http://licence.mondomaine.fr/admin/` (sans « s ») répond « Console accessible uniquement en https:// » **sans** demander le mot de passe : il ne circule jamais en clair ;
   - dans *Administration*, le « Contrôle d'exposition » affiche « protégé » sur chaque ligne ;
   - `http://licence.mondomaine.fr/` redirige vers `https://`.
8. **Copie de secours des secrets.** Télécharger par FTP `licence/prive/config.php`, `licence/prive/.htpasswd`, le dossier `licence/prive/cles/` et le fichier `licence/admin/.htaccess` généré, et les ranger hors ligne (clé USB chiffrée, coffre de mots de passe). Ils ne sont pas dans la base et ne doivent **jamais** entrer dans un dépôt Git.
9. **Réglages.** Console › *Administration* › *Réglages* : adresses de notification et adresse expéditrice (sur le domaine du serveur, par exemple `licences@licence.mondomaine.fr`), puis **« Envoyer un e-mail de test »**.
10. **Produits.** Un produit = une application, reconnue par le code qu'elle envoie au serveur (`produit="CODE"` dans sa ligne `installer(...)`), avec ses règles, ses licences et ses demandes. Rien n'est à préparer : la première demande de licence d'une application inconnue crée son produit (nom = code, actif) avec les règles par défaut — licence d'un an, essai de 15 jours pendant une demande, 15 jours hors ligne, préavis de 5 jours, aucune option. L'e-mail de notification de cette demande le signale (« NOUVEAU PRODUIT ») et l'écran *Produits* marque le produit « cree automatiquement » tant que ses règles n'ont pas été enregistrées depuis sa page. Pour préparer des clés avant toute demande : Console › *Produits* › **« Nouveau produit »** : code (définitif : c'est celui de l'application ; un code déjà pris, même avec d'autres majuscules, est refusé), nom, puis les règles (durée de licence par défaut, essai, options ; tolérance hors ligne, préavis, message d'accueil et case « Actif » sous « Reglages avances »). Les valeurs proposées conviennent dans la plupart des cas. La page du produit donne la ligne `installer(...)` à copier dans l'application.
11. **Sauvegarde quotidienne** (§ 5).

## 4. La console d'administration

`https://licence.mondomaine.fr/admin/` — utilisable sur téléphone. Le menu compte six entrées : Accueil, Demandes, Licences, Produits, Aide et Administration (écrans techniques).

| Écran | Usage |
|---|---|
| Accueil | demandes en attente (avec leur produit et le bouton « Traiter »), boutons « Nouvelle licence » et « Nouveau produit », compteurs (licences actives, expirées, suspendues, révoquées : chacun ouvre la liste filtrée ; ordinateurs vus sur 7 jours), licences expirant sous 30 jours |
| Demandes | accepter (durée, client titulaire de la licence, options) ou refuser (motif facultatif, renvoyé tel quel à l'application) ; historique ; export CSV |
| Licences | « Nouvelle licence » (choisir le produit), « Licence hors ligne » (poste jamais connecté, voir plus bas), rechercher, filtrer par statut ou par produit ; les licences hors ligne sont marquées « hors ligne » ; sur la fiche d'une licence : son produit (lien), sa clé masquée et le bouton « Afficher la cle », prolonger (+30 j, +1 an, date libre), nouvelle clé, changer d'ordinateur, suspendre (avec date de fin facultative : réactivation automatique), réactiver, révoquer (définitif), et sous « Reglages avances » tolérance et options ; licence hors ligne : « Nouveau code hors ligne pour ce poste » et « Nouveau code pour un autre ordinateur » au lieu de « Nouvelle cle » et « Changer d'ordinateur » ; export CSV (colonne « hors ligne ») |
| Produits | une ligne par produit : code et état (Actif, Inactif, « cree automatiquement »), nom, règles en une phrase, licences, demandes en attente ; « Nouveau produit » ; sur sa page : ligne `installer(...)` à copier, « Voir ses licences », « Nouvelle licence pour ce produit » et « Licence hors ligne pour ce produit » (produit actif), règles (nom, durée par défaut, essai, options ; tolérance, préavis, message d'accueil et case « Actif » sous « Reglages avances ») |
| Aide | mode d'emploi de chaque écran ; chaque écran porte un lien « Aide sur cet ecran » |
| Administration | accès aux écrans ci-dessous et contrôle d'exposition des fichiers |
| › Serveurs | liste des URL diffusées aux postes (priorité, diffusion), suivi d'une migration |
| › Clés de signature | clé publique active (bouton « Copier »), les quatre lignes pour `etdel_licence.py` (dont `LICENCE_SECRET_HORS_LIGNE`), historique, rotation |
| › Journal | toutes les actions (API et console), filtre, export CSV |
| › Sauvegarde | téléchargement d'une copie de la base, dernières sauvegardes |
| › Réglages | notification, e-mail de test, mot de passe de la console |

Toute action d'écriture demande une confirmation et est inscrite au journal avec l'identifiant de connexion. Une notification par e-mail part à chaque nouvelle demande (50 au plus par jour) ; un échec d'envoi est inscrit au journal et n'empêche jamais l'enregistrement de la demande.

**Retrouver une clé** (client qui l'a perdue, clé à redicter au téléphone) : *Licences* › la licence › **« Afficher la cle »**, à côté de la clé masquée. La clé complète s'affiche avec un bouton « Copier » ; chaque affichage est inscrit au journal (`cle_affichee`, avec l'identifiant de connexion). Une licence créée avant la version 2 du serveur n'a pas de clé conservée : la fiche l'indique. Le client peut alors lire sa clé dans l'application (touches Ctrl+Maj+L, fenêtre *Licence* › « Afficher la cle ») ; à défaut, **« Nouvelle cle »** en crée une, affichable ensuite, que l'ordinateur du client devra saisir (l'ancienne cesse de fonctionner). Clé diffusée ou transmise par erreur : **« Nouvelle cle »** (l'ancienne clé cesse de fonctionner).

**Changer d'ordinateur pour un client**, deux façons :
- depuis l'application, sur l'ancien ordinateur s'il fonctionne encore (le plus simple) : fenêtre *Licence* (touches Ctrl+Maj+L) › **« Transferer cette licence »**. L'application, connectée, libère la clé auprès du serveur, se désactive sur cet ordinateur et affiche la clé à saisir sur le nouveau (journal : `licence_transferee`, par le poste). Trois transferts au plus par licence sur 30 jours : au-delà, le serveur refuse (journal : `transfert_refuse`) et le changement passe par la console ;
- depuis la console, si l'ancien ordinateur est perdu ou en panne : *Licences* › la licence › **« Changer d'ordinateur »** › « Liberer cette cle », puis saisie de la même clé sur le nouvel ordinateur ; l'ancien perd la licence à son contrôle suivant.

**Suspendre ou révoquer ?**
- **Suspendre** : coupure temporaire. Le poste reste bloqué mais garde sa clé ; il se débloque tout seul à son contrôle suivant quand la licence est réactivée (« Reactiver ») ou à la date de fin choisie (« Suspendre jusqu'au »). Rien à ressaisir, même pour une licence obtenue par demande.
- **Révoquer** : définitif. Le poste efface sa clé et la licence ne peut plus être réactivée. Pour remettre le poste en service : « Nouvelle licence pour ce client » sur la fiche révoquée (création d'une clé pré-remplie ; la fiche révoquée indique ensuite « Deja remplacee par la licence n. … ») et transmettre la clé, ou laisser l'utilisateur envoyer une nouvelle demande depuis l'application (pas de nouvel essai si ce poste en a déjà eu un pour ce produit : l'essai est accordé une fois par ordinateur et par produit).

**Poste jamais (ou rarement) connecté : licence hors ligne.** Un code, à dicter par téléphone ou à envoyer par e-mail, que l'application vérifie sans Internet et qui contient sa durée de validité.
1. Sur le poste isolé, l'application affiche son « Identifiant du poste » (`XXXX-XXXX`, fenêtre d'activation ou fenêtre *Licence*) ; l'utilisateur le dicte ou l'envoie.
2. Console › *Licences* › **« Licence hors ligne »** (ou, sur la page du produit, « Licence hors ligne pour ce produit ») : produit, identifiant du poste (majuscules, `O` lu `0`, `I` et `L` lus `1`, tiret facultatif), client, durée en jours (celle du produit proposée ; vide = illimitée), case « Toutes les options » (cochée par défaut ; décochée = aucune option). Une saisie refusée (identifiant mal dicté, par exemple) réaffiche le formulaire avec ce qui a été saisi, sous le message : il suffit de corriger le champ.
3. La page de résultat affiche **deux codes équivalents**, chacun avec son bouton « Copier » : le **code court** `ETDHL-XXXX-XXXX-XXXX-XXXX`, à dicter, et le **code long** `ETDHL2-…` (143 caractères), à envoyer par e-mail. L'utilisateur saisit l'un ou l'autre avec « J'ai une cle » ; ils ne valent que pour ce poste et ce produit. La licence est valable jusqu'à la fin (heure UTC) du jour qui suit la durée choisie, jamais moins que cette durée. L'échéance affichée (résultat, fiche, listes, journal) est ce **dernier jour de validité**, comme dans l'application ; une échéance fixée ensuite par « Fixer la date » s'affiche telle quelle.
4. La licence apparaît dans *Licences*, marquée « hors ligne », avec l'identifiant du poste (de même dans les échéances proches de l'accueil). Sa fiche montre les codes masqués, avec la fin du code court et celle du code long telle que l'application l'affiche (`ETDHL2-****-...-XXXX-X`), et le bouton **« Afficher les codes »** (lecture inscrite au journal, `cle_affichee`) ; le code court est conservé chiffré comme les clés, le code long est recalculé à l'affichage.

Si l'ordinateur se connecte un jour à Internet, l'application contrôle aussi sa licence auprès du serveur : son premier contact la lie à l'ordinateur réel, seulement s'il s'agit bien du poste saisi dans la console ; ensuite « Prolonger », « Suspendre » et « Revoquer » l'atteignent comme une licence ordinaire (la fiche le rappelle : « n'atteint le poste que s'il se connecte un jour »). Un code révoqué, remplacé ou transféré que le poste a appris du serveur ne s'y ressaisit plus, même sans Internet. Un poste qui ne se connecte jamais ne voit rien de tout cela : pour le prolonger, **« Nouveau code hors ligne pour ce poste »** sur la fiche crée de nouveaux codes, avec une nouvelle durée, qui remplacent les anciens sur la même licence (le serveur ne reconnaît plus l'ancien code : un poste qui se connecterait encore avec lui le perdrait). La même durée et les mêmes options le même jour donneraient un code identique : c'est refusé (« Rien n'a ete change »), choisir une autre durée. « Nouvelle cle » et « Changer d'ordinateur » n'existent pas pour une licence hors ligne : pour un autre ordinateur (après « Transferer cette licence » dans l'application, ou ordinateur perdu), **« Nouveau code pour un autre ordinateur »** demande l'identifiant du poste du nouvel ordinateur ; la licence passe à ce poste, n'est plus liée à aucun ordinateur, et reçoit de nouveaux codes (les anciens ne sont plus reconnus). Après un transfert depuis l'application, la fiche propose cette action en premier. Une licence révoquée propose « Nouvelle licence hors ligne pour ce poste ». Journal : `licence_hors_ligne_creee`, `code_hors_ligne_remplace` (avec l'ancien et le nouveau poste en cas de changement ; jamais plus que les 4 derniers caractères d'un code).

Limites assumées : une révocation n'atteint jamais un poste qui ne se connecte pas (il garde son code jusqu'à son échéance) ; le code court est vérifié avec un secret inscrit dans chaque application (`LICENCE_SECRET_HORS_LIGNE`) : quelqu'un qui décompile une application peut en fabriquer, la protection est dissuasive seulement ; le code long, signé par la clé du serveur, est infalsifiable. Une application construite sans `LICENCE_SECRET_HORS_LIGNE` refuse les codes courts mais accepte les codes longs. Sur un poste jamais connecté, la protection contre le recul de l'horloge n'est elle aussi que dissuasive (voir `docs/INTEGRATION.md`).

**Version de l'application** : envoyée à chaque contrôle et affichée dans la console (fiche d'une licence ou d'une demande) pour information seulement ; le serveur ne refuse jamais une application pour sa version.

L'écran **Aide** de la console détaille chaque écran, chaque réglage et chaque action.

## 5. Sauvegardes

**Sauvegarde quotidienne automatique.** Espace client OVH › Hébergements › *Tâches planifiées - Cron* › *Ajouter une planification* :
- commande : `licence/prive/sauvegarde.php` ;
- langage : PHP (même version que le site) ;
- fréquence : quotidienne (par exemple à 3 h) ;
- cocher l'envoi du rapport par e-mail en cas d'erreur.

Chaque exécution crée `prive/data/sauvegardes/licenses-AAAAMMJJ-HHMMSS.db` (copie cohérente faite par SQLite, même pendant une écriture) et garde les 30 plus récentes (`sauvegardes_conservees` dans `config.php`). Le journal de la console mentionne chaque sauvegarde.

**Copie hors de l'hébergement.** Une fois par semaine au moins : console › *Administration* › *Sauvegarde* › « Telecharger une copie de la base ». Les copies ne contiennent ni clé de licence en clair ni clé privée : les clés y sont chiffrées, et le secret qui les déchiffre (`prive/cles/secret_demandes.key`) n'est pas dans la base. Sans ce secret, une base restaurée fonctionne, mais « Afficher la cle » ne peut plus rien montrer (« Nouvelle cle » reste possible). Le secret des codes hors ligne (`prive/cles/secret_hors_ligne.key`) n'est pas non plus dans la base, et il est inscrit dans les applications : il ne doit jamais être perdu ni remplacé (sans lui, les nouveaux codes courts ne seraient plus acceptés par les applications déjà livrées). La base en garde l'empreinte (SHA-256, réglage `secret_hors_ligne_empreinte`, qui ne permet pas de le retrouver) : si le fichier manque ou diffère une fois le secret créé, même si aucun code hors ligne n'existe encore (migration d'hébergement, restauration de la base sans `prive/cles/`), le serveur ne le recrée **jamais** ; l'écran *Clés de signature* et la création d'une licence hors ligne affichent « Secret des codes hors ligne absent » (ou « different ») : restaurer le fichier depuis la copie de secours.

**Restauration.**
1. Par FTP, renommer `prive/data/licenses.db` (par sécurité) et supprimer `prive/data/licenses.db-wal` et `prive/data/licenses.db-shm` s'ils existent.
2. Envoyer la copie choisie sous le nom `prive/data/licenses.db`.
3. Vérifier la console. Les licences créées après la date de la copie n'existent plus : les postes concernés seront refusés (`cle_invalide`) jusqu'à recréation.

Une copie antérieure à une rotation de clé se restaure de la même façon : au premier appel, le serveur reprend les clés suivantes depuis les fiches `signature_N.json` rangées à côté des clés privées dans `prive/cles/` (journal : `cles_resynchronisees`). Ne jamais supprimer ces fiches.

La base va avec les clés de signature : en cas de reconstruction complète de l'hébergement, remettre aussi `prive/cles/`, `prive/config.php` et `prive/.htpasswd` depuis la copie de secours (§ 3, étape 8).

## 6. Changer l'URL du serveur

Les applications ne dépendent jamais d'une seule URL : chaque réponse du serveur contient la liste signée des URL, que le poste mémorise dans son état local (hors de l'exe) et utilise en priorité. Changer d'URL ne demande donc ni recompilation ni redistribution.

1. Faire pointer le nouveau domaine vers l'hébergement (*Multisite*, même dossier `licence`, SSL coché) et vérifier `https://<nouveau>/install.php` (doit répondre « Installation deja effectuee »).
2. Console › *Administration* › *Serveurs* › ajouter `https://<nouveau>/api/v1/` avec une priorité plus petite que l'actuelle (la plus petite passe en premier).
3. Attendre que les postes se soient connectés : *Serveurs* affiche les postes vus depuis 24 h, 7 et 30 jours ; *Licences* donne le dernier contact de chacun. Attendre au moins la durée de tolérance (15 jours par défaut), pour les postes restés hors ligne.
4. Décocher « Diffusée » pour l'ancienne URL. Laisser l'ancien domaine en service encore un moment : il répond toujours, avec la nouvelle liste signée.
5. Couper l'ancien domaine.

Une réponse dont la signature est invalide ne remplace jamais la liste d'un poste : un domaine perdu puis détourné ne peut pas rediriger les applications.

Changer d'**hébergeur** suit la même procédure, à condition d'emporter tout le dossier `prive/` (clés de signature, secret, `.htpasswd`, `config.php`, base) : sans les clés d'origine, les postes rejetteraient les réponses du nouveau serveur.

## 7. Rotation de la clé de signature

À faire en cas de doute sur la confidentialité de l'hébergement (mot de passe FTP divulgué, accès suspect), après avoir repris la main (mots de passe OVH et FTP changés).

1. Console › *Administration* › *Clés de signature* › **« Nouvelle cle de signature »** › confirmer.
2. Le serveur génère la nouvelle paire, signe un bulletin d'annonce avec l'ancienne clé, signe désormais avec la nouvelle et efface l'ancienne clé privée.
3. Chaque poste adopte la nouvelle clé à sa connexion suivante, grâce au bulletin, sans mise à jour de l'application ; il accepte encore l'ancienne pendant 90 jours. Les bulletins sont diffusés pendant 12 mois : un poste resté hors ligne plus longtemps devra recevoir une version à jour de l'application.
4. Refaire la copie de secours de `prive/cles/` (§ 3, étape 8).
5. Facultatif : les nouvelles versions des applications peuvent embarquer la nouvelle clé publique (écran *Clés de signature*), en suivant `docs/INTEGRATION.md`.

Codes hors ligne : le secret des codes courts ne change pas lors d'une rotation. En revanche, un poste jamais connecté ne connaît que la clé publique inscrite dans son application : après une rotation, les nouveaux **codes longs** (signés par la nouvelle clé) ne fonctionnent que dans les applications construites avec la nouvelle clé publique ; pour les autres, transmettre le code court. Les codes longs créés avant la rotation restent valables sur les postes, mais la console ne peut plus les réafficher (« Code long non reaffichable ») : « Nouveau code hors ligne pour ce poste » en crée de nouveaux.

Limite assumée : qui contrôle l'hébergement peut signer. La sécurité du système repose sur celle du compte OVH (mot de passe fort, double authentification sur l'espace client).

## 8. Mettre à jour le serveur

Envoyer par FTP les nouveaux fichiers de `serveur/www/` en **excluant** :
- `licence/admin/.htaccess` (généré par l'assistant, il contient le chemin du `.htpasswd` ; la version du dépôt ferme la console) ;
- `prive/config.php`, `prive/.htpasswd`, `prive/cles/`, `prive/install.verrou`, `prive/reinitialisation.utilisee` et tout le dossier `prive/data/`.

La base existante est mise à niveau automatiquement à la première requête qui suit l'envoi des nouveaux fichiers (version du schéma dans `PRAGMA user_version`) : rien à faire à la main.

Passage à la version 2 du serveur : une base créée par une version précédente continue de fonctionner telle quelle. Mettre à jour le serveur **avant** de livrer des applications avec le module 2.0.0 : un serveur plus ancien ne reconnaîtrait pas toujours leurs requêtes et bloquerait leurs postes (« Produit inconnu »). Chaque produit garde toutes ses licences et ses demandes ; s'il avait plusieurs jeux de règles (anciennes distributions), le premier jeu actif (à défaut, le premier créé) s'applique désormais à tout le produit, et toutes ses licences restent valables tant que le produit est actif, y compris celles d'un ancien jeu désactivé (les suspendre ou les révoquer une à une si besoin). Le premier « Enregistrer » sur la page du produit aligne tous ces jeux sur les règles affichées. Les applications livrées avec l'ancien module sont reconnues par leur code produit seul. Les clés créées avant la mise à jour ne sont pas conservées : « Afficher la cle » ne vaut que pour les clés créées ensuite (création, acceptation d'une demande, « Nouvelle cle »).

Passage à la version 2.1 du serveur (licences hors ligne) : la base est mise à niveau d'elle-même (colonnes ajoutées, aucune licence modifiée) ; le secret des codes hors ligne est créé au premier besoin (premier affichage de l'écran *Clés de signature* ou première licence hors ligne ; journal : `secret_hors_ligne_cree`), seulement pour une base qui n'en a jamais eu (ni empreinte inscrite, ni licence hors ligne). Reporter alors la ligne `LICENCE_SECRET_HORS_LIGNE` dans les nouvelles versions des applications, et ajouter `prive/cles/secret_hors_ligne.key` à la copie de secours (§ 3, étape 8).

Si `admin/.htaccess` a été écrasé par erreur, la console répond « accès refusé » : renvoyer la copie de secours faite à l'installation.

## 9. Sécurité, récapitulatif

- HTTPS forcé, HSTS, politique de sécurité du contenu stricte, aucun listing de répertoire, aucune ressource externe dans la console.
- Console protégée par Apache (`AuthType Basic`, `.htpasswd` en bcrypt dans `prive/`, jamais servi), accessible uniquement en HTTPS ; mot de passe d'au moins 20 caractères ; la console refuse aussi toute requête qu'Apache n'a pas authentifiée.
- Écritures : jeton CSRF, contrôle de l'origine, confirmation, journal.
- Les clés de licence sont retrouvées par leur SHA-256 et conservées chiffrées (secretbox, avec le secret de `prive/cles/`, hors de la base) pour « Afficher la cle » ; une fuite de la base seule ne livre donc aucune clé. L'affichage d'une clé passe par un formulaire (jeton CSRF) et est inscrit au journal ; une clé n'apparaît jamais dans le journal, les e-mails ni les exports. La clé d'une demande acceptée est aussi conservée chiffrée dans la demande jusqu'à sa récupération par le poste, puis effacée de la demande. Licences hors ligne : SHA-256 des deux codes, code court chiffré comme les clés, code long jamais stocké (recalculé, signature déterministe) ; mêmes règles d'affichage et de journal. Exception pour le **code court** : la base contient en clair le produit, l'identifiant du poste, l'échéance et les options, et ses 4 derniers caractères (15 bits de son code d'authentification) ; avec son SHA-256, il ne reste que 41 bits inconnus, qu'une copie de la base (écran *Sauvegarde* ou fuite) permet de retrouver par force brute (quelques minutes sur une carte graphique). Conséquence limitée : un code ne vaut que pour son poste et son produit, et le secret des codes courts n'en est pas déduit ; mais les copies de la base sont à protéger comme les codes eux-mêmes. Le code long et les clés ETDEL (60 bits inconnus) ne sont pas concernés.
- Réponses de l'API toujours signées (Ed25519), refus compris ; requêtes horodatées (écart maximal 10 minutes) ; limites par adresse IP.
- Transfert depuis l'application : l'ancien ordinateur garde un jeton valable hors ligne jusqu'à la fin de sa tolérance (15 jours par défaut). Une personne qui restaurerait ses fichiers de licence après un transfert pourrait donc utiliser la même clé sur deux ordinateurs pendant ce délai ; d'où la limite de trois transferts par licence sur 30 jours, et la trace de chaque transfert dans l'historique de la licence (`licence_transferee`, `transfert_refuse`).
- Aucun secret dans le dépôt Git (`.gitignore`).

## 10. Dépannage

| Symptôme | Piste |
|---|---|
| Les postes affichent « serveur injoignable » | certificat SSL du sous-domaine, URL de l'API, horloge du poste (écart > 10 min refusé) ; ligne de diagnostic Ctrl+Maj+L |
| L'API répond 503 | base absente ou clé privée illisible : vérifier `prive/data/` et `prive/cles/` (droits d'écriture du compte) ; journaux d'erreurs PHP dans l'espace client OVH |
| Aucun e-mail reçu | *Administration* › *Réglages* › e-mail de test ; *Administration* › *Journal*, actions `email_echec` et `email_plafond` ; dossier des indésirables ; adresse expéditrice sur le domaine du serveur |
| « Formulaire expiré » dans la console | recharger la page (le jeton change après chaque écriture) |
| Ligne « DANGER » dans le contrôle d'exposition (*Administration*) | `prive/` est servi par le web : vérifier que `licence/.htaccess` et `licence/prive/.htaccess` ont bien été envoyés, ou déplacer `prive/` à côté de `licence/` (§ 2) |
| L'application affiche « produit inconnu » | produit inactif (*Produits* › sa page › « Reglages avances », case « Actif ») ; ou code de l'application qui ne diffère d'un produit existant que par les majuscules (refusé pour ne pas créer deux produits pareils à la lecture) : corriger le code dans l'application |
| « Cle complete non disponible » sur une licence | licence créée avant la version 2 du serveur : « Nouvelle cle » en crée une, consultable ensuite |
| L'application refuse un code hors ligne court (« non pris en charge ») | application construite sans `LICENCE_SECRET_HORS_LIGNE` : transmettre le code long, et reporter la ligne dans sa prochaine version |
| « Code long non reaffichable » sur une licence hors ligne | rotation de la clé de signature depuis la création des codes (§ 7) : « Nouveau code hors ligne pour ce poste » |
| L'identifiant du poste est refusé | 8 caractères affichés par l'application, chiffres et lettres sans `U` ; une lettre mal lue (`O`, `I`, `L`) est corrigée d'elle-même ; le formulaire réaffiché garde le reste de la saisie |
| « Secret des codes hors ligne absent » ou « different » (écran *Clés de signature*, création d'une licence hors ligne) | `prive/cles/secret_hors_ligne.key` perdu ou remplacé (migration, restauration) : le remettre depuis la copie de secours (§ 3, étape 8) ; ne jamais en fabriquer un autre, les applications livrées embarquent celui-ci |
| L'application refuse un code « revoque, remplace ou transfere » | ce code a été retiré de ce poste (révocation, nouveau code ou transfert appris du serveur) : créer un nouveau code (autre durée) |
| Après « Transferer cette licence », le client demande un code pour son nouvel ordinateur | fiche de la licence › « Nouveau code pour un autre ordinateur », avec l'identifiant du poste affiché sur le nouvel ordinateur |
| Mot de passe de la console perdu | voir ci-dessous |

**Mot de passe de la console perdu.** Par FTP, inscrire dans `prive/config.php` une nouvelle chaîne aléatoire d'au moins 20 caractères dans `jeton_reinitialisation` (différente du jeton d'installation et de tout jeton déjà utilisé), puis ouvrir `https://licence.mondomaine.fr/install.php` : l'assistant, toujours verrouillé, ne propose alors que le remplacement du mot de passe d'un compte existant de la console. Chaque jeton ne sert qu'une fois ; remettre ensuite `jeton_reinitialisation` à vide.

## 11. Banc d'essai local

Pour essayer le serveur sans hébergement (développement), voir la section « Banc d'essai local » du `README.md` : `php -S` sur `127.0.0.1` directement sur `serveur/www/`, avec `serveur/tests/routeur_banc.php` (qui reproduit les `.htaccess` et l'authentification d'Apache) et la boîte aux lettres `serveur/tests/smtp_banc.py`, puis `client/demo_appli.py`.
