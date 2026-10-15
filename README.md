<div align="center">

# Janus — Licences des applications ETDEL

**Un serveur de licences sur hébergement mutualisé, sa console d'administration, et un seul module à copier dans chaque application Python.**

![Version](https://img.shields.io/badge/version-2.1.1-2ea44f)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.10%2B-3776AB?logo=python&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-pdo__sqlite-003B57?logo=sqlite&logoColor=white)
![tkinter](https://img.shields.io/badge/tkinter-fen%C3%AAtres%20du%20module-3776AB)
![Ed25519](https://img.shields.io/badge/signature-Ed25519-555555)
![Plateforme](https://img.shields.io/badge/plateforme-Windows%20%2B%20h%C3%A9bergement%20web-0078D6?logo=windows&logoColor=white)
![Licence](https://img.shields.io/badge/licence-GPL--3.0--or--later-blue)

</div>

**Français** · [English](README.en.md)

---

> [!NOTE]
> **Un seul module, copié tel quel dans chaque application.** Le serveur s'installe une fois sur l'hébergement ;
> le module `client/etdel_licence.py` se copie sans aucune modification à côté du script principal de chaque
> application. Tant que ses constantes `LICENCE_URL` et `LICENCE_CLE_PUBLIQUE` sont vides, comme dans ce dépôt,
> il ne fait rien : aucun fichier, aucun accès réseau, aucun message. Vous pouvez donc l'intégrer avant que le
> serveur existe. Le module est fait pour les applications Windows ; hors Windows, il ne sert qu'au développement.

Système de licences pour les applications Python Windows signées ETDEL : un serveur de licences et sa console
d'administration (PHP, hébergement mutualisé OVH), et un module client unique, `etdel_licence.py`, intégré en
deux lignes dans chaque application.

**À qui ça sert.** À l'éditeur qui livre des applications Python pour Windows et veut décider qui peut s'en
servir. Chaque application est un **produit**, avec ses règles ; une licence vaut pour un ordinateur. Depuis une
console utilisable sur téléphone, l'éditeur crée des clés de licence, accepte ou refuse les demandes envoyées
depuis l'application (avec, s'il le veut, une période d'essai en attendant), retrouve une clé pour la redicter,
prolonge, suspend ou révoque une licence, fait passer un client sur un autre ordinateur, et crée des licences
hors ligne pour les postes jamais connectés à Internet. Rien n'est à préparer pour une nouvelle application : sa
première demande de licence crée son produit. Côté utilisateur, l'application reçoit la clé ou envoie la demande,
se contrôle d'elle-même en arrière-plan, et montre l'état de sa licence par le raccourci **Ctrl+Maj+L**, d'où l'on
peut aussi transférer la licence vers un nouvel ordinateur.

Version actuelle : module client 2.1.1, serveur 2.1.1 (API v1) — les choix de réalisation, avec les changements
de version qui en découlent, sont consignés dans [docs/DECISIONS.md](docs/DECISIONS.md).

## Sommaire
- [1. Installation](#1-installation)
- [2. Les fichiers](#2-les-fichiers)
- [3. Mise en service](#3-mise-en-service)
- [4. L'application de démonstration](#4-lapplication-de-démonstration)
- [5. Comment ça marche](#5-comment-ça-marche)
- [6. En cas de souci](#6-en-cas-de-souci)
- [7. Licence](#7-licence)

---

## 1. Installation

### Ce qu'il faut

| Pour | Il faut |
|---|---|
| le serveur | un hébergement mutualisé OVH avec **PHP 8.1 ou plus** et les extensions `pdo_sqlite` et `sodium` (présentes par défaut chez OVH), un sous-domaine en HTTPS et, si possible, un domaine de secours ; un client FTP et un navigateur suffisent, sans accès SSH |
| les applications | Windows et **Python 3.10 ou plus** ; tkinter pour une application à fenêtres (`installer()`) ; le module n'utilise que la bibliothèque standard |
| l'application de démonstration | Python avec tkinter, et un serveur de licences déjà installé |

### Récupérer le dépôt

```powershell
git clone https://github.com/theptitprince/Janus.git
cd Janus
```

### Par où commencer

- **Mettre le serveur en service sur l'hébergement** : section 3, puis le guide complet
  [docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md).
- **Intégrer le module dans une application** : section 3, puis [docs/INTEGRATION.md](docs/INTEGRATION.md) et le
  [modèle de brief d'intégration](docs/modele_brief_integration.md).
- **Essayer le serveur installé avant de livrer une application** : section 4.

---

## 2. Les fichiers

| Fichier | Rôle |
|---|---|
| `client/etdel_licence.py` | le module client unique, version 2.1.1 : contrôle en ligne, état local chiffré, tolérance hors ligne, licences hors ligne (codes vérifiés sans réseau), fenêtres Tkinter ; seules ses quatre constantes `LICENCE_*` sont renseignées, une fois, après l'installation du serveur |
| `client/demo_appli.py` | application de démonstration (produit `DEMO`), à brancher sur un serveur installé (section 4) |
| `serveur/www/` | tout le serveur, à envoyer tel quel par FTP : API, console d'administration, assistant d'installation, et `prive/` (bibliothèques, configuration, clés, base), jamais servi |
| `serveur/www/install.php` | assistant d'installation, à usage unique, verrouillé ensuite ; il ne sert plus alors qu'à remplacer un mot de passe perdu de la console, avec un jeton déposé par FTP |
| `serveur/www/api/v1/` | API utilisée par les applications : activation, contrôle, demande et son suivi, transfert |
| `serveur/www/admin/` | console d'administration, protégée par mot de passe, avec son écran **Aide** |
| `serveur/www/prive/` | jamais servi : `config.exemple.php` (modèle de `config.php`), `schema.sql` (schéma de la base), `sauvegarde.php` (sauvegarde quotidienne), `lib/` (bibliothèques) ; l'installation y crée la base (`data/`), les clés de signature et les secrets (`cles/`) et le `.htpasswd` |
| `docs/INTEGRATION.md` | [intégration](docs/INTEGRATION.md) du module dans une application |
| `docs/modele_brief_integration.md` | [modèle de brief d'intégration](docs/modele_brief_integration.md), à compléter pour chaque application |
| `docs/LISEZ-MOI-SERVEUR.md` | [déploiement et exploitation du serveur](docs/LISEZ-MOI-SERVEUR.md) |
| `docs/DECISIONS.md` | [décisions de réalisation](docs/DECISIONS.md) |
| `LICENSE` | texte intégral de la licence GNU GPL version 3 |
| `NOTICE` | les conditions additionnelles de la licence (article 7) : la mention à conserver |

Ne sont jamais versionnés (`.gitignore`) : `serveur/www/prive/config.php`, les clés et les secrets
(`prive/cles/`), la base et ses sauvegardes (`prive/data/`), le `.htpasswd`, le verrou d'installation
`install.verrou` et le fichier `reinitialisation.utilisee`.

---

## 3. Mise en service

### Le serveur sur l'hébergement

En résumé (le pas à pas complet, avec les vérifications, est dans
[docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md), § 3) :

1. Dans l'espace client OVH, créez le sous-domaine (par exemple `licence.mondomaine.fr`, dossier racine `licence`,
   case **SSL** cochée) et choisissez PHP 8.1 ou plus.
2. Sur le poste, copiez `serveur/www/prive/config.exemple.php` en `config.php` (même dossier) et inscrivez dans
   `jeton_installation` une chaîne aléatoire d'au moins 20 caractères.
3. Envoyez par FTP le contenu de `serveur/www/` dans `licence/`, sans oublier les fichiers cachés `.htaccess`.
4. Ouvrez `https://licence.mondomaine.fr/install.php` : l'assistant crée la base, la paire de clés de signature,
   le secret des demandes, le secret des codes hors ligne et le `.htpasswd`, protège `/admin/`, puis se verrouille.
   Notez la clé publique et les quatre lignes à reporter dans `client/etdel_licence.py`.
5. Faites la copie de secours des secrets (`prive/config.php`, `prive/.htpasswd`, le dossier `prive/cles/` et le
   `admin/.htaccess` généré), réglez les notifications par e-mail et la sauvegarde quotidienne (tâche planifiée
   `licence/prive/sauvegarde.php`).

Aucun produit n'est à préparer : la première demande de licence d'une application inconnue crée son produit, avec
les règles par défaut. Le même guide couvre la console, les sauvegardes et la restauration, le changement d'URL du
serveur, la rotation de la clé de signature, la mise à jour du serveur et le dépannage.

### Le module dans une application

Le module se copie tel quel à côté du script principal. Pour une application Tkinter, ajoutez juste après la
création de la fenêtre principale :

```python
import tkinter as tk
import etdel_licence

root = tk.Tk()
etdel_licence.installer(root, produit="MONAPPLI", version=APP_VERSION)   # juste après la création de root
```

C'est la ligne que la console affiche, avec « Copier », sur la page de chaque produit. Pour une application
console, appelez `etdel_licence.exiger(produit="MONOUTIL", version=APP_VERSION)` au début de `main()`.

Une fois le serveur installé, les quatre lignes affichées par l'assistant (et dans la console, *Administration* ›
*Clés de signature*) se reportent une seule fois en tête de `client/etdel_licence.py` : `LICENCE_URL`,
`LICENCE_URL_SECOURS`, `LICENCE_CLE_PUBLIQUE` et `LICENCE_SECRET_HORS_LIGNE`. La nouvelle empreinte SHA-256 du
module se reporte ensuite dans `docs/INTEGRATION.md`, puis cette copie est redistribuée dans les applications.
Niveaux d'intégration, palette, options, licences hors ligne, statuts et vérifications :
[docs/INTEGRATION.md](docs/INTEGRATION.md).

Pour une application livrée avec un module 1.x : mettez d'abord le serveur en version 2
([docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md), § 8), puis seulement livrez l'application avec le
module 2.x ; un serveur plus ancien bloquerait ses postes (« Produit inconnu »).

---

## 4. L'application de démonstration

`client/demo_appli.py` est une petite application Tkinter (produit `DEMO`, version 1.0.0) intégrée comme une
application livrée, par `installer(root, produit="DEMO", version=...)`. Elle permet d'essayer un serveur installé
de bout en bout : clé saisie à la main, demande de licence et période d'essai, bandeau, fenêtre « Licence »,
options, transfert et licences hors ligne. Ses options remplacent les constantes du module pour cette exécution
seulement, ce qu'aucune application livrée ne doit faire.

Depuis la racine du dépôt :

```powershell
python client/demo_appli.py --serveur https://licence.mondomaine.fr/api/v1/ --cle-publique <clé publique>
```

| Option | Rôle |
|---|---|
| `--serveur <url>` | URL de l'API du serveur, terminée par `/api/v1/` |
| `--cle-publique <clé>` | clé publique affichée par `install.php` et par la console, *Administration* › *Clés de signature* |
| `--secret-hors-ligne <secret>` | valeur de `LICENCE_SECRET_HORS_LIGNE` (même écran) : la démo accepte alors aussi les codes hors ligne courts ; sans elle, seuls les codes longs sont acceptés |
| `--banc` | raccourci pour un serveur installé sur ce poste même, depuis le dossier `serveur/www/` de ce dépôt, et servi à l'adresse `http://127.0.0.1:8090/api/v1/` : la clé publique et le secret sont lus dans `serveur/www/prive/cles/` |

Lancée sans ces options, avec la copie du module de ce dépôt (constantes vides), la démo s'ouvre sans licence et
l'indique (« Licence non configuree »). Le module n'accepte le HTTP clair qu'en boucle locale (`127.0.0.1`,
`localhost`, `::1`) ; toute autre adresse doit être en HTTPS.

Rien n'est à préparer dans la console : la première « Demander une licence » de la démo crée le produit `DEMO`
avec les règles par défaut ; la demande apparaît dans *Demandes*, et l'e-mail de notification signale
« NOUVEAU PRODUIT ». Pour essayer ensuite :

- **une clé saisie à la main** : *Produits* › `DEMO` › « Nouvelle licence pour ce produit » (avant toute demande :
  *Produits* › « Nouveau produit », code `DEMO`), puis « J'ai une cle » dans la démo ;
- **une option** : ajoutez `export_pdf` aux options du produit `DEMO`, ou à celles de la licence (« Reglages
  avances » de sa fiche) ; après « Verifier maintenant », le bouton « Exporter en PDF (option export_pdf) » de la
  démo s'active (il ne fait rien d'autre) ;
- **une licence hors ligne** : *Licences* › « Licence hors ligne », produit `DEMO`, avec l'« Identifiant du poste »
  affiché par la démo ; saisissez le code court (démo lancée avec `--secret-hors-ligne`) ou le code long avec
  « J'ai une cle » ;
- **un transfert** : fenêtre « Licence » › « Transferer cette licence » ; la démo se désactive et se ferme, et la
  clé se saisit sur l'autre ordinateur.

La fenêtre de la démo porte deux boutons : « Fenetre Licence » (même fenêtre que Ctrl+Maj+L) et « Supprimer la
licence de ce poste (essais) », qui efface l'état local de la démo et la relance avec les mêmes options ; côté
serveur, la licence reste liée au poste (la même clé peut être ressaisie) et l'essai déjà accordé n'est pas
redonné. Le cadre « Licence » en bas de la fenêtre est celui qu'une application place dans sa page de réglages
(`cadre_licence()`) ; en dessous, la démo affiche les mentions de licence de Janus (voir la section 7, Licence).
Pour écarter ensuite le produit `DEMO`, décochez « Actif » sous « Reglages avances » sur sa page.

Pour effacer à la main l'état local du module sur un poste, supprimez les dossiers `%APPDATA%\ETDEL\licences`,
`%LOCALAPPDATA%\ETDEL\licences` et `%PROGRAMDATA%\ETDEL\licences` : cela vaut pour toutes les applications ETDEL
du poste, journal compris, et pas seulement pour la démo.

---

## 5. Comment ça marche

- **Produits.** Un produit est une application, reconnue par le code qu'elle envoie (`produit="..."` dans sa ligne
  `installer(...)`), avec ses règles : durée de licence par défaut (vide = perpétuelle), essai pendant une demande,
  options (`*` = toutes), tolérance hors ligne, préavis, message d'accueil, actif ou non. Un produit inconnu est
  créé à sa première demande de licence (licence d'un an, essai de 15 jours, 15 jours hors ligne, préavis de
  5 jours, aucune option) ; on peut aussi le créer à la main pour préparer des clés. Son code ne change plus.
- **Une licence, un ordinateur.** Une clé `ETDEL-XXXX-XXXX-XXXX-XXXX` se lie au premier ordinateur qui l'active.
  Deux façons de l'obtenir : la clé fournie par l'éditeur (« J'ai une cle »), ou la demande envoyée depuis
  l'application (« Demander une licence »), acceptée ou refusée dans la console ; l'essai éventuel n'est accordé
  qu'une fois par ordinateur et par produit.
- **Contrôle en arrière-plan.** `installer()` rend la main en moins de 50 ms. Le premier contrôle a lieu 3 s
  après le lancement, puis toutes les 6 h (15 min après un échec). Sans licence active, une fenêtre propose
  « J'ai une cle » ou « Demander une licence » ; rien n'est envoyé sans clic explicite.
- **Réponses signées.** Toutes les réponses du serveur, refus compris, sont signées (Ed25519) ; le module les
  vérifie avec la clé publique qu'il embarque. Les requêtes sont horodatées : un poste dont l'horloge est décalée
  de plus de 10 minutes est refusé.
- **Hors connexion.** Le poste garde un état local chiffré (DPAPI) et scellé, en trois exemplaires, dans
  `%APPDATA%`, `%LOCALAPPDATA%` et `%PROGRAMDATA%` (sous-dossier `ETDEL\licences`). L'application reste utilisable
  hors connexion pendant la tolérance de son produit (15 jours par défaut), avec un bandeau de préavis les
  derniers jours (5 par défaut).
- **Suspendre ou révoquer.** Une licence suspendue bloque le poste, qui garde sa clé et se débloque seul à la
  réactivation ou à la date de fin choisie. Une licence révoquée l'est définitivement : le poste efface sa clé.
- **Retrouver une clé.** La console garde chaque clé chiffrée (clés créées depuis la version 2 du serveur), avec
  un secret rangé hors de la base : « Afficher la cle », sur la fiche de la licence, la montre pour la redicter,
  et chaque affichage est inscrit au journal.
  Dans l'application, la fenêtre « Licence » la montre aussi, masquée par défaut.
- **Changer d'ordinateur.** Depuis l'application, sur l'ancien ordinateur connecté : « Transferer cette licence »
  libère la clé, désactive l'application et affiche la clé à saisir sur le nouvel ordinateur (trois transferts au
  plus par licence sur 30 jours). Depuis la console, si l'ancien ordinateur est perdu : « Changer d'ordinateur ».
- **Licences hors ligne.** Pour un ordinateur jamais connecté, l'éditeur crée dans la console, à partir de
  l'« Identifiant du poste » (`XXXX-XXXX`) que l'application affiche, deux codes équivalents qui contiennent leur
  date de fin et se vérifient sans réseau : un code court `ETDHL-XXXX-XXXX-XXXX-XXXX` à dicter, protégé par
  `LICENCE_SECRET_HORS_LIGNE` (dissuasif : qui décompile une application peut en fabriquer), et un code long
  `ETDHL2-…` à envoyer par e-mail, signé par le serveur (infalsifiable). On prolonge par un nouveau code. Si
  l'ordinateur se connecte un jour, la licence suit la console comme les autres ; sinon, elle n'est pas révocable.
- **URL et clés de signature.** Chaque réponse porte la liste signée des URL du serveur, que le poste mémorise :
  changer d'adresse ne demande ni recompilation ni redistribution. Une nouvelle clé de signature est annoncée par
  un bulletin signé avec l'ancienne ; les postes l'adoptent à leur connexion suivante.
- **Version de l'application.** Elle est transmise au serveur et affichée dans la console, pour information
  seulement : aucune version n'est jamais refusée.
- **Données.** Le module transmet l'empreinte du poste (SHA-256 de l'identifiant Windows de la machine et du
  numéro de série du volume système), le nom de l'ordinateur, le code produit et la version de l'application ;
  pour une demande, en plus, le titulaire, l'e-mail et le message saisis. Le serveur retrouve une clé par son
  SHA-256 ; une clé ou un code n'apparaît jamais dans le journal, les e-mails ni les exports, seulement ses
  4 derniers caractères.

---

## 6. En cas de souci

**`install.php` répond « Configuration absente »** — `prive/config.php` manque sur l'hébergement : copiez
`serveur/www/prive/config.exemple.php` en `config.php`, inscrivez-y le `jeton_installation`, envoyez-le, puis
rechargez la page (section 3, étape 2).

**`install.php` répond « Installation impossible »** — la page donne la cause : extension PHP `pdo_sqlite` ou
`sodium` absente, `jeton_installation` absent ou de moins de 20 caractères, ou base déjà présente.

**`install.php` répond « Installation deja effectuee »** — l'assistant est verrouillé, c'est normal après une
installation. Mot de passe de la console perdu : voir la fin de
[docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md), § 10.

**La console affiche « Formulaire expire ou invalide »** — rechargez la page puis recommencez : le jeton du
formulaire change après chaque écriture.

**Une application affiche « Produit inconnu »** — le produit est inactif (*Produits* › sa page › « Reglages
avances », case « Actif »), ou le code de l'application ne diffère d'un produit existant que par les majuscules :
corrigez le code dans l'application.

**Une application refuse un code hors ligne court (« non pris en charge »)** — sa copie du module n'a pas
`LICENCE_SECRET_HORS_LIGNE` : transmettez le code long, et reportez la ligne dans sa prochaine version.

**La démo affiche « Licence non configuree »** — lancez-la avec `--serveur` et `--cle-publique` (section 4).

**Une application signale un serveur de licences injoignable, ou reste bloquée** — **Ctrl+Maj+L** ouvre la fenêtre
« Licence » : identifiant du poste, statut, ligne de diagnostic, et « Verifier maintenant » pour relancer un
contrôle. Le détail technique est dans le journal `%LOCALAPPDATA%\ETDEL\licences\licence.log`. Une horloge décalée
de plus de 10 minutes est refusée par le serveur : corrigez la date et l'heure du poste.

Pour le reste : l'écran **Aide** de la console détaille chaque écran, chaque réglage et chaque action ; la partie
« Dépannage » de [docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md) couvre l'hébergement, et celle de
[docs/INTEGRATION.md](docs/INTEGRATION.md) les applications.

---

## 7. Licence

Janus est un **logiciel libre**, distribué sous la licence **GNU General Public License, version 3 ou ultérieure** (GPL-3.0-or-later).

Copyright © 2026 ETDEL.

Concrètement, vous pouvez utiliser le programme librement, l'étudier, le modifier et le redistribuer — à condition
que toute version redistribuée, modifiée ou non, reste sous la même licence, avec son code source et la mention de
copyright. Le programme est fourni **sans aucune garantie**.

### Conditions additionnelles (article 7)

La GPL v3 permet à l'auteur d'ajouter quelques conditions (article 7). Celles de Janus sont dans
le fichier [`NOTICE`](NOTICE), en français et en anglais :

- **Attribution** : toute copie ou version modifiée doit garder, visible pour l'utilisateur
  (fenêtre « À propos » ou équivalent) et dans sa documentation, la mention :

  > Janus, par ETDEL — https://github.com/theptitprince/Janus

  Une version modifiée peut la faire précéder de « D'après » et ajouter ses propres crédits,
  sans la retirer ni la masquer.
- **Origine** : une version modifiée doit dire clairement qu'elle est modifiée, et ne doit se
  présenter ni comme l'original, ni comme approuvée par ETDEL.

Dans Janus, cette mention s'affiche dans le pied de page de la console d'administration, dans la fenêtre
« Licence » du module client et dans l'application de démonstration.

Le texte intégral de la licence est dans le fichier `LICENSE` du dépôt et sur
<https://www.gnu.org/licenses/gpl-3.0.html>. Chaque fichier source porte un en-tête : la mention « ETDEL (c) 2026 »,
celle de Janus, la licence (GPL-3.0-or-later) et un renvoi aux conditions additionnelles du fichier `NOTICE`.

La vérification Ed25519 en Python pur du module client suit le code de référence de la RFC 8032, section 6
(IETF Trust et auteurs de la RFC, licence BSD simplifiée) : ce qui en est repris reste sous cette licence, dont
l'avis de copyright et le texte sont reproduits dans le module, et les conditions additionnelles ne s'y
appliquent pas (voir `NOTICE`).

---

*Janus — ETDEL 2026 — GPL-3.0-or-later*
