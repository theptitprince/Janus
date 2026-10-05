# Serveur de licences ETDEL — déploiement et exploitation sur OVH mutualisé

Tout se fait avec l'espace client OVH, un client FTP (FileZilla par exemple) et un navigateur. Aucun outil n'est à installer sur le poste d'Etienne, aucun accès SSH n'est nécessaire.

## 1. Ce qu'il faut avant de commencer

- Un hébergement mutualisé OVH avec **PHP 8.1 ou plus** (extensions `pdo_sqlite` et `sodium`, présentes par défaut chez OVH).
- Un **sous-domaine** dédié, par exemple `licence.mondomaine.fr`, et si possible un **domaine de secours** (second domaine ou autre sous-domaine) pointant vers le même hébergement.
- Les identifiants FTP de l'hébergement.
- Le dossier `serveur/` du dépôt Janus.

À trancher par Etienne avant l'installation : nom de domaine et sous-domaine, domaine de secours, liste initiale des produits et distributions, durée et options de la distribution démo.

## 2. Organisation des fichiers sur l'hébergement

```
/ (racine du compte FTP)
├── licence/      ← contenu de serveur/www/ : seul dossier servi par le web
│   ├── .htaccess          HTTPS forcé, en-têtes de sécurité, pas de listing
│   ├── install.php        assistant d'installation (usage unique, puis verrouillé)
│   ├── api/v1/index.php   API utilisée par les applications
│   └── admin/             console d'administration (protégée par mot de passe)
├── prive/        ← contenu de serveur/prive/ : jamais servi
│   ├── config.php         paramètres (créé à partir de config.exemple.php)
│   ├── .htpasswd          créé par install.php
│   ├── cles/              clés privées de signature, créées par install.php
│   └── lib/ …
└── data/         ← créé par install.php
    ├── licenses.db        base SQLite (mode WAL)
    └── sauvegardes/       copies quotidiennes
```

`prive/` et `data/` doivent être **à côté** du dossier servi, jamais dedans. Le code les cherche à cet endroit. Si l'hébergement impose malgré tout de les placer dans le dossier servi, leurs fichiers `.htaccess` (`Require all denied`) les protègent ; le « Contrôle d'exposition » du tableau de bord le vérifie (§ 4).

Le dossier `serveur/tests/` ne s'envoie pas sur l'hébergement.

## 3. Installation pas à pas

1. **Sous-domaine et HTTPS.** Espace client OVH › Hébergements › *Multisite* › *Ajouter un domaine ou sous-domaine* : `licence.mondomaine.fr`, dossier racine `licence`, case **SSL** cochée (certificat Let's Encrypt). Faire de même pour le domaine de secours, avec le **même** dossier racine. Attendre que le certificat soit actif (quelques minutes à quelques heures).
2. **Version de PHP.** Hébergements › *Informations générales* › *Version PHP globale* : 8.1 ou plus.
3. **Configuration.** Sur le poste, copier `serveur/prive/config.exemple.php` en `config.php` (même dossier) et inscrire dans `jeton_installation` une chaîne aléatoire d'au moins 20 caractères (un générateur de mots de passe convient). Les autres valeurs conviennent telles quelles.
4. **Envoi FTP.** Envoyer le contenu de `serveur/www/` dans `licence/`, et le dossier `serveur/prive/` (avec `config.php`) à la racine du compte, à côté de `licence/`. Ne pas oublier les fichiers cachés `.htaccess`.
5. **Assistant.** Ouvrir `https://licence.mondomaine.fr/install.php` et remplir : jeton d'installation, identifiant et mot de passe de la console (20 caractères minimum), URL de l'API (proposée), URL de secours (`https://<domaine de secours>/api/v1/`), adresse(s) de notification. L'assistant crée la base, la paire de clés de signature, le secret des demandes et le `.htpasswd`, protège `/admin/`, puis se verrouille.
6. **Noter le résultat.** La page finale affiche la clé publique et les trois lignes à reporter dans `client/etdel_licence.py` (voir `docs/INTEGRATION.md`, « Après l'installation du serveur »). Ces lignes restent consultables dans la console, écran *Clés*.
7. **Vérifier.**
   - `https://licence.mondomaine.fr/install.php` répond « Installation deja effectuee » ;
   - `https://licence.mondomaine.fr/admin/` demande l'identifiant et le mot de passe ;
   - dans le *Tableau de bord*, le « Contrôle d'exposition » affiche « protégé » sur chaque ligne ;
   - `http://licence.mondomaine.fr/` redirige vers `https://`.
8. **Copie de secours des secrets.** Télécharger par FTP `prive/config.php`, `prive/.htpasswd`, le dossier `prive/cles/` et le fichier `licence/admin/.htaccess` généré, et les ranger hors ligne (clé USB chiffrée, coffre de mots de passe). Ils ne sont pas dans la base et ne doivent **jamais** entrer dans un dépôt Git.
9. **Réglages.** Console › *Réglages* : adresses de notification et adresse expéditrice (sur le domaine du serveur, par exemple `licences@licence.mondomaine.fr`), puis **« Envoyer un e-mail de test »**.
10. **Produits.** Console › *Produits* : créer chaque produit et ses distributions (tolérance, préavis, durée par défaut, essai, options, message d'accueil). L'écran de chaque distribution donne la ligne `installer(...)` à copier dans l'application.
11. **Sauvegarde quotidienne** (§ 5).

## 4. La console d'administration

`https://licence.mondomaine.fr/admin/` — utilisable sur téléphone.

| Écran | Usage |
|---|---|
| Tableau de bord | demandes en attente, licences actives, expirées, suspendues, révoquées, postes vus sur 7 jours, licences expirant sous 30 jours, contrôle d'exposition des fichiers |
| Demandes | accepter (durée, titulaire, options) ou refuser (motif facultatif, renvoyé tel quel à l'application) ; historique ; export CSV |
| Licences | créer une clé (affichée une seule fois), rechercher, prolonger (+30 j, +1 an, date libre), modifier tolérance et options, suspendre, réactiver, révoquer, libérer le poste ; export CSV |
| Produits | produits et distributions, duplication, ligne `installer(...)` |
| Serveurs | liste des URL diffusées aux postes (priorité, diffusion), suivi d'une migration |
| Clés | clé publique active (bouton « Copier »), historique, rotation |
| Journal | toutes les actions (API et console), filtre, export CSV |
| Sauvegarde | téléchargement d'une copie de la base, dernières sauvegardes |
| Réglages | notification, e-mail de test, mot de passe de la console |

Toute action d'écriture demande une confirmation et est inscrite au journal avec l'identifiant de connexion. Une notification par e-mail part à chaque nouvelle demande (50 au plus par jour) ; un échec d'envoi est inscrit au journal et n'empêche jamais l'enregistrement de la demande.

Changer d'ordinateur pour un client : *Licences* › la licence › **« Liberer le poste »**, puis saisie de la même clé sur le nouvel ordinateur.

## 5. Sauvegardes

**Sauvegarde quotidienne automatique.** Espace client OVH › Hébergements › *Tâches planifiées - Cron* › *Ajouter une planification* :
- commande : `prive/sauvegarde.php` ;
- langage : PHP (même version que le site) ;
- fréquence : quotidienne (par exemple à 3 h) ;
- cocher l'envoi du rapport par e-mail en cas d'erreur.

Chaque exécution crée `data/sauvegardes/licenses-AAAAMMJJ-HHMMSS.db` (copie cohérente faite par SQLite, même pendant une écriture) et garde les 30 plus récentes (`sauvegardes_conservees` dans `config.php`). Le journal de la console mentionne chaque sauvegarde.

**Copie hors de l'hébergement.** Une fois par semaine au moins : console › *Sauvegarde* › « Telecharger une copie de la base ». Les copies ne contiennent ni clé de licence en clair ni clé privée.

**Restauration.**
1. Par FTP, renommer `data/licenses.db` (par sécurité) et supprimer `data/licenses.db-wal` et `data/licenses.db-shm` s'ils existent.
2. Envoyer la copie choisie sous le nom `data/licenses.db`.
3. Vérifier la console. Les licences créées après la date de la copie n'existent plus : les postes concernés seront refusés (`cle_invalide`) jusqu'à recréation.

La base va avec les clés de signature : en cas de reconstruction complète de l'hébergement, remettre aussi `prive/cles/`, `prive/config.php` et `prive/.htpasswd` depuis la copie de secours (§ 3, étape 8).

## 6. Changer l'URL du serveur

Les applications ne dépendent jamais d'une seule URL : chaque réponse du serveur contient la liste signée des URL, que le poste mémorise dans son état local (hors de l'exe) et utilise en priorité. Changer d'URL ne demande donc ni recompilation ni redistribution.

1. Faire pointer le nouveau domaine vers l'hébergement (*Multisite*, même dossier `licence`, SSL coché) et vérifier `https://<nouveau>/install.php` (doit répondre « Installation deja effectuee »).
2. Console › *Serveurs* › ajouter `https://<nouveau>/api/v1/` avec une priorité plus petite que l'actuelle (la plus petite passe en premier).
3. Attendre que les postes se soient connectés : *Serveurs* affiche les postes vus depuis 24 h, 7 et 30 jours ; *Licences* donne le dernier contact de chacun. Attendre au moins la durée de tolérance (15 jours par défaut), pour les postes restés hors ligne.
4. Décocher « Diffusée » pour l'ancienne URL. Laisser l'ancien domaine en service encore un moment : il répond toujours, avec la nouvelle liste signée.
5. Couper l'ancien domaine.

Une réponse dont la signature est invalide ne remplace jamais la liste d'un poste : un domaine perdu puis détourné ne peut pas rediriger les applications.

Changer d'**hébergeur** suit la même procédure, à condition d'emporter `prive/` (clés de signature, secret, `.htpasswd`, `config.php`) et `data/` : sans les clés d'origine, les postes rejetteraient les réponses du nouveau serveur.

## 7. Rotation de la clé de signature

À faire en cas de doute sur la confidentialité de l'hébergement (mot de passe FTP divulgué, accès suspect), après avoir repris la main (mots de passe OVH et FTP changés).

1. Console › *Clés* › **« Nouvelle cle de signature »** › confirmer.
2. Le serveur génère la nouvelle paire, signe un bulletin d'annonce avec l'ancienne clé, signe désormais avec la nouvelle et efface l'ancienne clé privée.
3. Chaque poste adopte la nouvelle clé à sa connexion suivante, grâce au bulletin, sans mise à jour de l'application ; il accepte encore l'ancienne pendant 90 jours. Les bulletins sont diffusés pendant 12 mois : un poste resté hors ligne plus longtemps devra recevoir une version à jour de l'application.
4. Refaire la copie de secours de `prive/cles/` (§ 3, étape 8).
5. Facultatif : les nouvelles versions des applications peuvent embarquer la nouvelle clé publique (écran *Clés*), en suivant `docs/INTEGRATION.md`.

Limite assumée : qui contrôle l'hébergement peut signer. La sécurité du système repose sur celle du compte OVH (mot de passe fort, double authentification sur l'espace client).

## 8. Mettre à jour le serveur

Envoyer par FTP les nouveaux fichiers de `serveur/www/` et `serveur/prive/lib/` en **excluant** :
- `licence/admin/.htaccess` (généré par l'assistant, il contient le chemin du `.htpasswd` ; la version du dépôt ferme la console) ;
- `prive/config.php`, `prive/.htpasswd`, `prive/cles/`, `prive/install.verrou` et tout le dossier `data/`.

Si `admin/.htaccess` a été écrasé par erreur, la console répond « accès refusé » : renvoyer la copie de secours faite à l'installation.

## 9. Sécurité, récapitulatif

- HTTPS forcé, HSTS, politique de sécurité du contenu stricte, aucun listing de répertoire, aucune ressource externe dans la console.
- Console protégée par Apache (`AuthType Basic`, `.htpasswd` en bcrypt hors du dossier servi) ; mot de passe d'au moins 20 caractères ; la console refuse aussi toute requête qu'Apache n'a pas authentifiée.
- Écritures : jeton CSRF, contrôle de l'origine, confirmation, journal.
- Seul le SHA-256 des clés de licence est stocké ; la clé d'une demande acceptée est conservée chiffrée jusqu'à sa récupération par le poste, puis effacée.
- Réponses de l'API toujours signées (Ed25519), refus compris ; requêtes horodatées (écart maximal 10 minutes) ; limites par adresse IP.
- Aucun secret dans le dépôt Git (`.gitignore`).

## 10. Dépannage

| Symptôme | Piste |
|---|---|
| Les postes affichent « serveur injoignable » | certificat SSL du sous-domaine, URL de l'API, horloge du poste (écart > 10 min refusé) ; ligne de diagnostic Ctrl+Maj+L |
| L'API répond 503 | base absente ou clé privée illisible : vérifier `data/` et `prive/cles/` (droits d'écriture du compte) ; journaux d'erreurs PHP dans l'espace client OVH |
| Aucun e-mail reçu | *Réglages* › e-mail de test ; *Journal*, actions `email_echec` et `email_plafond` ; dossier des indésirables ; adresse expéditrice sur le domaine du serveur |
| « Formulaire expiré » dans la console | recharger la page (le jeton change après chaque écriture) |
| Ligne « DANGER » dans le contrôle d'exposition | `prive/` ou `data/` est servi par le web : les déplacer à côté du dossier `licence/`, ou vérifier que leurs `.htaccess` ont bien été envoyés |
| Mot de passe de la console perdu | voir ci-dessous |

**Mot de passe de la console perdu.** Par FTP, inscrire dans `prive/config.php` une nouvelle chaîne aléatoire d'au moins 20 caractères dans `jeton_reinitialisation` (différente du jeton d'installation et de tout jeton déjà utilisé), puis ouvrir `https://licence.mondomaine.fr/install.php` : l'assistant, toujours verrouillé, ne propose alors que le remplacement du mot de passe d'un compte existant de la console. Chaque jeton ne sert qu'une fois ; remettre ensuite `jeton_reinitialisation` à vide.

## 11. Banc d'essai local

Pour essayer le serveur sans hébergement (développement), voir la section « Banc d'essai local » du `README.md` : `php -S` sur `127.0.0.1` avec `serveur/tests/routeur_banc.php`, puis `client/demo_appli.py`.
