# Intégrer la licence ETDEL dans une application

Module de référence : `client/etdel_licence.py`, version **2.1.1**.
Empreinte SHA-256 (fins de ligne normalisées en LF) : `28657c93ec05e748ed62bf97a724348152746fd318a27c6382bc0904bbd33475`

Le module est **le même pour toutes les applications** : on le copie tel quel, sans aucune modification, à côté du script principal. Il n'a pas de fichier de configuration. Ce qui est propre à une application passe en paramètres : son **code produit** et sa **version**.

Le code produit identifie l'application auprès du serveur. Le serveur porte les règles de ce produit (durée de licence par défaut, période d'essai, options, tolérance hors ligne, préavis, message d'accueil) : on les règle dans la console, écran **Produits**, sans rien changer à l'application. Un produit encore inconnu du serveur est créé automatiquement, avec des règles par défaut, à la première « Demander une licence » envoyée par l'application ; on peut aussi le créer à l'avance dans la console pour préparer des clés.

La version de l'application est transmise au serveur et affichée dans la console, à titre d'information seulement : aucune version n'est jamais refusée.

Tant que les constantes `LICENCE_URL` et `LICENCE_CLE_PUBLIQUE` sont vides, le module ne fait rien : aucun fichier, aucun accès réseau, aucun message. On peut donc l'intégrer avant que le serveur existe. La quatrième constante, `LICENCE_SECRET_HORS_LIGNE`, ne sert qu'aux codes hors ligne courts (voir « Licences hors ligne ») : vide, ces codes sont refusés, tout le reste fonctionne.

## Les trois niveaux d'intégration

### 1. Application console

```python
import etdel_licence

APP_VERSION = "1.4.0"

def main():
    etdel_licence.exiger(produit="MONOUTIL", version=APP_VERSION)
    ...  # suite normale du programme
```

`exiger()` fait un contrôle synchrone (5 secondes au plus). Si la licence n'est pas utilisable, un message est écrit sur la sortie d'erreur et le programme s'arrête avec le code 3. Sans licence, ou avec une licence hors ligne expirée, et seulement si le programme tourne dans un terminal interactif, la clé (ou un code hors ligne) est demandée au clavier : c'est ainsi qu'un poste jamais connecté reçoit son nouveau code.

### 2. Application Tkinter, cas normal

```python
import tkinter as tk
import etdel_licence

APP_VERSION = "1.4.0"

root = tk.Tk()
etdel_licence.installer(root, produit="MONAPPLI", version=APP_VERSION)   # juste après la création de root
...  # construction habituelle de l'interface
root.mainloop()
```

C'est la ligne que la console affiche, avec « Copier », sur la page de chaque produit.

`installer()` prend tout en charge, sans autre modification du code :

- contrôle en arrière-plan (premier essai 3 s après le lancement, puis toutes les 6 h ; 15 min après un échec) ; la fonction rend la main en moins de 50 ms ;
- fenêtre d'activation si aucune licence n'est active : deux cartes de choix « J'ai une cle » et « Demander une licence » ; la clé se met en forme pendant la frappe (majuscules, tirets, préfixe `ETDEL-`) avec une indication en direct (incomplète, format correct, caractère non valide) ; le même champ accepte un code hors ligne (`ETDHL-…` dicté, ou `ETDHL2-…` collé en entier, sans limite à 16 caractères), vérifié sur le poste sans aucun accès réseau ; l'identifiant du poste reste affiché au pied de la page ; rien n'est envoyé sans clic explicite ; au clavier, Entrée active le bouton qui a le focus (Entrée sur « Retour » revient en arrière, n'envoie rien) et, dans un champ, l'action principale de la page (« Activer », « Envoyer la demande ») ; Échap revient en arrière depuis la saisie de clé ou le formulaire et ferme la page « Demande envoyee » ; fermer cette fenêtre quitte l'application ;
- bandeau de préavis en superposition (période d'essai sur fond bleu clair, tolérance hors ligne bientôt épuisée ou échéance proche aux couleurs d'accent) ; un clic sur le bandeau ouvre la fenêtre « Licence ». Le bandeau recouvre environ 30 px (à 100 %) du haut de la fenêtre pendant l'essai ou le préavis, davantage si son message passe sur deux lignes dans une fenêtre étroite (moins de 700 px environ) : prévoir cette marge au-dessus de la barre d'outils ;
- relecture de l'état toutes les 25 s ; licence expirée ou révoquée en cours de session : message, puis fermeture ;
- interception de la fermeture de la fenêtre : l'heure atteinte est enregistrée, puis le gestionnaire d'origine de l'application est appelé (à défaut, `root.destroy()`) ; si l'application annule la fermeture, le contrôle continue ; il s'arrête à la destruction réelle de la fenêtre ;
- raccourci **Ctrl+Maj+L** : fenêtre « Licence » (statut en pastille de couleur : vert valide, bleu essai ou attente, ambre avertissement, rouge bloqué ; titulaire, échéance, dernier contrôle, identifiant du poste avec « Copier », clé masquée `ETDEL-****-****-****-ZS95` avec « Afficher la cle », nom de l'ordinateur avec « Transferer cette licence », diagnostic avec « Copier le diagnostic » ; juste au-dessus du pied, en petit, la mention « Janus, par ETDEL — https://github.com/theptitprince/Janus (GPL-3.0-or-later, sans garantie) » ; au pied, « Verifier maintenant », grisé tant que le poste n'a ni clé ni demande en attente, et « Fermer »). La clé complète n'apparaît que sur demande, jamais dans le diagnostic ni le journal. Avec une licence hors ligne : statut « Licence hors ligne valable jusqu'au … » (ou « illimitee »), ligne « Code hors ligne » masquée avec « Afficher le code », « Verifier maintenant » actif. La mention de Janus est exigée par les conditions additionnelles de sa licence (fichier `NOTICE` du dépôt, article 7 b) : l'application hôte ne doit ni la retirer ni la masquer.

`installer()` renvoie la Garde pour les usages avancés.

### 3. Application Tkinter avec page de réglages

```python
garde = etdel_licence.installer(root, produit="MONAPPLI", version=APP_VERSION, palette=PALETTE_THEME)

# Dans la page de réglages : cadre en lecture seule, placé où l'on veut.
cadre = garde.cadre_licence(page_reglages)
cadre.pack(fill="x", padx=10, pady=10)

# Dans le rapport de diagnostic : une ligne prête à insérer (jamais la clé en clair).
rapport.append(garde.texte_diagnostic())
```

Rien de la licence n'est modifiable depuis les réglages : le cadre est en lecture seule (pas de transfert, qui se fait depuis la fenêtre « Licence »).

## Changer d'ordinateur

Deux façons, au choix :

1. **Depuis l'application, sur l'ancien ordinateur** (à préférer tant qu'il fonctionne) : fenêtre « Licence » (Ctrl+Maj+L), lien « Transferer cette licence », proposé quand le poste a une clé et que la licence est utilisable ou suspendue (une licence suspendue le propose aussi sur la page « Licence suspendue » affichée au lancement). Une confirmation montre la **clé complète**, à noter ou copier, et explique que l'application sera désactivée sur cet ordinateur ; l'ordinateur doit être connecté à Internet. Après « Transferer », le serveur libère la licence : le module efface la clé et l'état de licence du poste, affiche « Licence transferee : saisissez la cle ETDEL-… sur le nouvel ordinateur. », puis l'application se ferme (« Fermer l'application »). Il suffit alors de saisir la clé sur le nouvel ordinateur (« J'ai une cle »). En cas d'échec (serveur injoignable, refus), rien ne change sur l'ancien ordinateur et le message dit pourquoi. Une clé déjà libérée sur le serveur (réponse perdue lors d'un premier essai, ou libération depuis la console) compte comme un transfert réussi. Le serveur accepte au plus trois transferts par licence sur 30 jours (refus `transferts_trop_nombreux` au-delà : « Changer d'ordinateur » dans la console reste possible).
2. **Depuis la console** : « Changer d'ordinateur » sur la fiche de la licence, puis saisir la même clé sur le nouvel ordinateur ; l'ancien ordinateur est bloqué à son contrôle suivant. C'est la solution quand l'ancien ordinateur est perdu ou hors service.

Une licence hors ligne ne se transfère pas par son code : il ne vaut que pour l'identifiant du poste pour lequel la console l'a calculé. Après « Transferer cette licence », ETDEL donne au nouvel ordinateur son propre code par « Nouveau code pour un autre ordinateur » sur la fiche de la licence (voir « Licences hors ligne »).

## Licences hors ligne (postes jamais ou rarement connectés)

Pour un ordinateur qui n'a jamais Internet, ETDEL fournit un **code hors ligne** qui contient sa date de fin et se vérifie sur le poste, sans réseau. Rien à faire dans l'application : `installer()` et `exiger()` les acceptent tels quels.

1. Sur le poste isolé, l'utilisateur lit l'**identifiant du poste** (`XXXX-XXXX`, au pied de la fenêtre d'activation et dans la fenêtre « Licence ») et le transmet à ETDEL (téléphone, e-mail).
2. Dans la console, « Licence hors ligne » donne deux codes équivalents pour ce poste et ce produit :
   - code **court** `ETDHL-XXXX-XXXX-XXXX-XXXX`, à dicter (16 caractères, protégé par un code d'authentification calculé avec `LICENCE_SECRET_HORS_LIGNE`) ;
   - code **long** `ETDHL2-…` (143 caractères), à copier-coller depuis un e-mail (signé avec la clé de signature du serveur).
3. Dans l'application, « J'ai une cle » : le code est vérifié sur place (produit, identifiant du poste, date de fin, intégrité), sans aucun accès réseau. Saisie tolérante : minuscules, espaces, retours à la ligne et tirets ignorés, `O` lu `0`, `I` et `L` lus `1` ; pour le code long, la fin d'un copier-coller trop large (signature de l'e-mail) est ignorée. Code court dicté sans son préfixe `ETDHL` (le champ affiche alors `ETDEL-…`) : reconnu à son code d'authentification, il est activé lui aussi sans réseau (aucune clé ETDEL ne peut passer pour un code valable de ce poste).

Ensuite :

- **Statut** `VALIDE`, message « Licence hors ligne valable jusqu'au JJ/MM/AAAA » ou « Licence hors ligne illimitee », sans préavis ni bandeau ; après la date de fin, `EXPIREE` : « Licence hors ligne expiree le JJ/MM/AAAA : demandez un nouveau code a ETDEL », fenêtre au lancement avec « J'ai une cle » en action principale pour saisir le nouveau code (qui remplace l'ancien) ; « Reessayer » sans réseau y répond « Serveur de licences injoignable : saisissez le nouveau code avec "J'ai une cle". », jamais un conseil de connexion. Le code dure jusqu'à la fin (UTC) de son dernier jour, et c'est ce **dernier jour de validité** qui est affiché, dans l'application comme dans la console (fin exacte le 01/01/2027 à 00:00 UTC, soit 01:00 à Paris : « valable jusqu'au 31/12/2026 ») ; une échéance fixée dans la console par « Fixer la date » s'affiche telle quelle (fin de ce jour-là). `jours_restants` compte les jours entamés jusqu'à la fin, aujourd'hui compris.
- **Horloge** : la protection contre le recul de l'horloge s'applique comme pour toute licence (heure maximale atteinte, au-delà de 48 h de recul le temps d'utilisation continue de s'écouler). Limite assumée : sur un poste qui ne se connecte jamais, rien ne la recale ; une horloge remise en arrière à chaque démarrage (script, pile du BIOS hors d'usage) ne fait plus avancer la licence qu'au rythme du temps d'utilisation de l'application. Comme l'état local peut aussi être effacé, ce n'est qu'une protection dissuasive, du même ordre que le code court.
- **Options** : `option()` renvoie `True` pour tout code si le code accorde « toutes les options », `False` sinon ; dès qu'un jeton signé a été reçu du serveur (voir ci-dessous), ce sont les options de ce jeton.
- **Contrôle opportuniste** : le module garde son cycle habituel (3 s après le lancement, toutes les 6 h, « Verifier maintenant ») et envoie le code (forme normalisée) au serveur quand Internet est disponible. Serveur injoignable : **aucun effet** (pas de tolérance hors ligne, pas de bandeau). Serveur joint : au premier contact, il lie la licence à l'ordinateur réel, seulement si son identifiant est celui saisi dans la console ; ensuite, la licence suit la console comme une licence ordinaire : prolongation ou raccourcissement (l'échéance du dernier jeton signé remplace celle du code), options, suspension (bloquée, le code est conservé ; une suspension datée est levée à sa date de fin même sans nouveau contact), révocation (le code est effacé du poste), code remplacé dans la console (l'ancien, inconnu du serveur, est effacé du poste).
- **Ressaisie** : ressaisir la licence déjà enregistrée ne change rien, sous la même forme ou sous l'autre (code long au lieu du court, ou l'inverse : mêmes jours, mêmes options) : une suspension ou une échéance reçue du serveur reste appliquée. Un code **révoqué, remplacé ou transféré** (refus `revoquee`, `cle_invalide`, `cle_liee_autre_poste` reçu du serveur, ou transfert réussi) est retenu dans l'état local (empreinte du produit, du poste, des jours et des options, commune aux deux formes, jamais le code) et refusé ensuite à la saisie, même sans réseau : « Code hors ligne revoque, remplace ou transfere : demandez un nouveau code a ETDEL » (code `revoquee`). Un vrai nouveau code (autre durée ou autres options) reste accepté.
- **Transfert** : « Transferer cette licence » n'est proposé qu'une fois le poste lié par un contact ; après le transfert, le nouvel ordinateur a besoin de son propre code (« communiquez a ETDEL l'identifiant du poste du nouvel ordinateur ») : dans la console, « Nouveau code pour un autre ordinateur » fait passer la licence à cet identifiant, avec de nouveaux codes (les anciens ne sont plus reconnus).
- **Refus à la saisie** (rien n'est enregistré) : code mal formé (« Code hors ligne invalide : verifiez la saisie ») ; code d'un autre poste ou d'un autre produit, caractère altéré, clé de signature inconnue (« Code hors ligne refuse : il ne correspond pas a cet ordinateur (identifiant du poste XXXX-XXXX) ou contient une erreur de saisie. ») ; code déjà échu ; code retiré de ce poste (voir « Ressaisie ») ; code court avec `LICENCE_SECRET_HORS_LIGNE` vide (« Codes hors ligne courts non pris en charge par cette application : demandez le code long (ETDHL2-...) a ETDEL »).
- **Code masqué** (fenêtre « Licence ») : `ETDHL-****-****-****-XXXX` pour le code court, `ETDHL2-****-...-XXXX-X` pour le code long (sa fin telle qu'elle s'écrit) ; la fiche de la console montre les deux fins.

Limites assumées :

- le code court n'est que **dissuasif** : le secret est embarqué dans l'application, et qui la décompile peut fabriquer des codes courts. Le code long, signé, est infalsifiable ;
- le code long est vérifié avec toutes les clés publiques que le poste connaît (embarquée et adoptées par bulletins ; une clé retirée ne vaut plus pour un nouveau code, mais un code déjà enregistré reste valable). Après une rotation de la clé de signature, un poste jamais connecté ne connaît que la clé embarquée, et le serveur ne signe plus avec l'ancienne : les nouveaux codes longs ne fonctionnent alors que dans les applications construites avec la nouvelle clé publique (ou sur un poste qui s'est connecté depuis la rotation). Le code court n'est pas concerné ;
- une licence hors ligne n'est jamais révocable sur un poste qui ne se connecte jamais ;
- le refus local des codes retirés et la protection de l'horloge reposent sur l'état local : qui l'efface (trois exemplaires) les contourne ; ils évitent les erreurs et les contournements faciles, sans plus.

## Palette

Les composants s'adaptent au thème de l'application par un dictionnaire ; les clés absentes prennent la valeur par défaut.

| Clé | Rôle | Défaut |
|---|---|---|
| `fond` | pied des fenêtres (zone des boutons) ; sans `panneau`, aussi le fond des fenêtres, comme en 1.3.0 | `#f2f2f2` |
| `panneau` | fond des fenêtres et du cadre « Licence » | `#ffffff` |
| `texte` | texte principal | `#1e1e1e` |
| `discret` | libellés secondaires | `#6b6b6b` |
| `accent` | bouton principal, liens, bandeau d'avertissement (éviter un accent vert ou bleu : il colore aussi le bandeau d'avertissement) | `#b4500a` |
| `accent_texte` | texte sur l'accent (bouton principal, bandeau) | `#ffffff` |
| `police` | police courante (tailles et graisses dérivées) | `("Segoe UI", 10)` |
| `police_titre` | titres (agrandis de 2 points en tête de fenêtre) | `("Segoe UI", 13, "bold")` |
| `police_champ` | clé de licence, identifiant du poste | `("Consolas", 11)` |

Bordures, survols, fonds teintés et couleurs de statut (vert, bleu, ambre, rouge) sont calculés à partir de ces clés : une palette sombre (`panneau` foncé) donne des fenêtres sombres lisibles sans autre réglage, barre de titre sombre comprise (Windows 10 2004 et suivants). Un accent clair (jaune par exemple) reste au fond du bouton principal et du bandeau ; liens et icônes en prennent une nuance assombrie, lisible sur `panneau` (contraste d'au moins 4,5:1). Une couleur ou une police refusée par Tk est remplacée par sa valeur par défaut (ligne dans le journal). Les fenêtres reprennent l'icône de la fenêtre principale. Les icônes utilisent les polices d'icônes de Windows 10 et 11 (Segoe Fluent Icons, Segoe MDL2 Assets) ; sans elles, un simple « i » ou « ! » les remplace.

## Options

```python
if garde.option("export_pdf"):
    menu_fichier.add_command(label="Exporter en PDF", command=exporter_pdf)
```

`option()` lit la liste d'options du jeton signé par le serveur : celles des règles du produit (console, écran Produits), surchargeables par licence. Une option absente vaut `False`, de même que tant que la licence n'est pas utilisable. Pendant une période d'essai, ce sont les options du produit. Une même base de code peut ainsi livrer une édition démo ou réduite sans construction séparée. Dans la console, le joker `*` (seul dans le champ Options) active toutes les options, y compris celles qu'une version future de l'application ajoutera : `option()` renvoie alors `True` pour tout code. Licence hors ligne : toutes les options ou aucune, selon le code, jusqu'à ce qu'un jeton signé du serveur apporte les siennes.

## Construction de l'exécutable

Empaquetage recommandé : **Nuitka** (compilation en C, plus résistante à la décompilation que PyInstaller). Le module est importé normalement : aucune option particulière n'est nécessaire, mais le script de construction doit vérifier que la copie de `etdel_licence.py` est la bonne (empreinte ci-dessus) et échouer sinon.

```bat
@echo off
rem construire_exe.bat - construit l'exe de MONAPPLI
python -m nuitka --standalone --enable-plugin=tk-inter --windows-console-mode=disable ^
    --output-filename=MONAPPLI.exe monappli.py
```

## Usages avancés : la Garde

```python
from etdel_licence import Garde, VALIDE, AVERTISSEMENT, EXPIREE

garde = Garde(produit="MONAPPLI", version=APP_VERSION)
garde.demarrer()                # rend la main, premier essai réseau dans un fil à +3 s
etat = garde.etat()             # dict, voir ci-dessous
garde.option("export_pdf")      # booléen
garde.controler_maintenant()    # toujours dans un fil
garde.arreter()                 # à la fermeture : enregistre l'heure atteinte
```

Aucune fonction publique ne lève d'exception vers l'application : toute erreur interne est journalisée et traduite en statut. `activer(cle)`, `demander(titulaire, email, message)` et `transferer()` existent aussi ; elles sont bloquantes (réseau) et doivent être appelées hors du fil de l'interface. Toutes trois renvoient `{"ok": bool, "code": str ou None, "message": str}`. `transferer()` libère la licence de ce poste (voir « Changer d'ordinateur ») : en cas de succès, la clé et l'état de licence sont effacés du poste, le statut passe à `REVOQUEE` jusqu'à la fermeture et `message` donne la clé à saisir sur le nouvel ordinateur (une clé déjà libérée sur le serveur, refus `poste_revoque`, compte comme un succès) ; en cas d'échec (`injoignable`, `cle_invalide`, `cle_liee_autre_poste`, `revoquee`, `transferts_trop_nombreux`, `aucune_cle`…), rien ne change sur le poste.

`etat()` renvoie : `statut`, `message` (texte affichable, ASCII), `id_poste`, `nom_ordinateur`, `titulaire`, `echeance`, `jours_restants`, `dernier_controle`, `demande`, `motif_refus`, `url_active`, `kid_actif`, `raison` (détail technique), `hors_ligne` (`True` pour une licence hors ligne, module 2.1.0). `activer(cle)` accepte aussi un code hors ligne, vérifié sans réseau (codes de refus : `cle_invalide`, `expiree`, `suspendue` pour la licence déjà enregistrée et suspendue, `revoquee` pour un code retiré de ce poste, `court_non_pris_en_charge`).

| Statut | Signification | Effet avec `installer()` |
|---|---|---|
| `NON_CONFIGURE` | constantes vides | rien |
| `A_ACTIVER` | ni clé ni demande | fenêtre d'activation |
| `ESSAI` | demande en attente, essai en cours | bandeau « Licence en cours de traitement - N jour(s) d'essai restant(s) » |
| `DEMANDE_EN_ATTENTE` | demande en attente, sans essai | fenêtre d'attente, suivi chaque minute |
| `DEMANDE_REFUSEE` | refus reçu | fenêtre : motif éventuel, « Nouvelle demande », « J'ai une cle » |
| `VALIDE` | tout va bien (licence hors ligne : jusqu'à sa date de fin, serveur joignable ou non) | rien |
| `AVERTISSEMENT` | fin de tolérance hors ligne (préavis, limité à la seconde moitié de la tolérance) ou échéance sous 15 jours ; jamais pour une licence hors ligne | bandeau avec `etat()["message"]` |
| `EXPIREE` | tolérance épuisée, échéance dépassée, licence suspendue (le poste garde sa clé ; message « Licence suspendue jusqu'au … » si une date de fin est fixée), produit désactivé sur le serveur, ou licence hors ligne arrivée à sa date de fin | au lancement : fenêtre « Reessayer » (titre « Licence suspendue » en cas de suspension, « Licence hors ligne expiree » avec « J'ai une cle » en action principale pour une licence hors ligne échue) ; en session : message puis fermeture. La réactivation ou la fin de la suspension débloque au contrôle suivant, sans ressaisie (licence hors ligne : la date de fin d'une suspension débloque aussi sans contrôle) |
| `REVOQUEE` | clé révoquée (définitif), libérée, liée à un autre poste ou transférée depuis ce poste : le poste efface sa clé | message puis fermeture (après un transfert, la fenêtre de transfert ferme elle-même l'application) ; fenêtre d'activation au lancement suivant |

Il n'y a plus de statut `VERSION_REFUSEE` (module 2.0.0) : aucune version minimale n'existe. Un ancien serveur qui enverrait encore une version minimale ou un refus de version n'a aucun effet de blocage.

## Ce que le module transmet et conserve

Données transmises au serveur, et rien d'autre : empreinte du poste (SHA-256 de l'identifiant Windows de la machine et du numéro de série du volume système), nom de l'ordinateur (affichage seulement), code produit, version de l'application (information seulement). Pour une demande, en plus : titulaire, e-mail et message saisis par l'utilisateur. S'y ajoutent les éléments techniques du protocole : nonce, horodatage, champ `distribution` (toujours égal au code produit, conservé pour la stabilité du protocole v1), clé de licence ou code hors ligne (activation, contrôle, transfert ; un code hors ligne n'est envoyé qu'au contrôle, jamais à sa saisie), jeton et numéro de demande.

État local, chiffré (DPAPI) et scellé, en trois exemplaires : `%APPDATA%\ETDEL\licences\`, `%LOCALAPPDATA%\ETDEL\licences\`, `%PROGRAMDATA%\ETDEL\licences\` (fichier `<PRODUIT>-<PRODUIT>.dat`). Un code hors ligne y est conservé et vérifié de nouveau à chaque lancement (l'état local n'est qu'un cache). Journal : `%LOCALAPPDATA%\ETDEL\licences\licence.log` (1 Mo, 2 archives ; la clé ou le code n'y figurent jamais, seulement leurs 4 derniers caractères).

## Vérifier l'intégration

À faire dans l'application, après l'intégration et après chaque mise à jour du module :

1. **Copie du module** : depuis le dossier de l'application, la commande de calcul de l'empreinte (« Après l'installation du serveur », étape 2, avec le chemin `etdel_licence.py`) affiche l'empreinte publiée en tête de ce document, et `etdel_licence.MODULE_VERSION` vaut la version publiée.
2. **Constantes vides** (copie du dépôt avant l'installation du serveur) : l'application démarre, fonctionne et se ferme exactement comme avant, sans fenêtre ni bandeau de licence, sans fichier de licence créé et sans réaction au raccourci Ctrl+Maj+L.
3. **Constantes renseignées**, sur un poste sans licence : la fenêtre d'activation s'ouvre au lancement (« J'ai une cle », « Demander une licence ») ; une fois la licence active, l'application s'ouvre normalement, Ctrl+Maj+L affiche la fenêtre « Licence », et la fermeture de la fenêtre principale passe toujours par le gestionnaire de l'application (demande d'enregistrement, par exemple).
4. **Bandeau** : pendant une période d'essai (demande envoyée pour un produit dont les règles accordent un essai), le bandeau apparaît en haut de la fenêtre principale ; vérifier qu'il ne masque rien d'indispensable (marge à prévoir : voir « Application Tkinter, cas normal »).
5. **Blocage en cours de session** : suspendre la licence dans la console, puis « Verifier maintenant » dans la fenêtre « Licence » : dans la demi-minute qui suit, un message s'affiche, puis le module ferme la fenêtre principale ; l'application doit alors se terminer sans erreur. « Reactiver » dans la console, puis « Reessayer » au lancement suivant, la débloque sans ressaisie.
6. **Licence hors ligne** (facultatif) : un code créé dans la console (*Licences* › « Licence hors ligne ») pour l'identifiant du poste, saisi avec « J'ai une cle », ouvre l'application sans fenêtre ni bandeau, même sans connexion à Internet.

Les licences créées pour ces vérifications se révoquent ensuite dans la console.

## Après l'installation du serveur : renseigner les constantes

Une seule fois, dans le dépôt Janus :

1. Ouvrir l'écran **Clés** de la console et copier les quatre lignes proposées en tête de `client/etdel_licence.py` (`LICENCE_URL`, `LICENCE_URL_SECOURS`, `LICENCE_CLE_PUBLIQUE`, `LICENCE_SECRET_HORS_LIGNE`). Le secret des codes hors ligne courts ne change jamais (ni rotation de clé, ni changement d'URL) : une copie déjà renseignée avec les trois premières lignes n'a qu'à recevoir la quatrième.
2. Calculer la nouvelle empreinte :
   ```
   python -c "import hashlib; print(hashlib.sha256(open('client/etdel_licence.py','rb').read().replace(b'\r\n', b'\n')).hexdigest())"
   ```
3. La reporter dans ce document (ligne « Empreinte SHA-256 ») : c'est la référence que vérifient les scripts de construction des applications et le brief d'intégration.
4. Redistribuer cette copie dans les applications.

Une rotation de clé ou un changement d'URL ne demande **pas** de refaire cette opération : les postes reçoivent la liste d'URL et les nouvelles clés par des messages signés et les conservent dans leur état local.

## Mettre à jour le module dans une application

Remplacer `etdel_licence.py` par la nouvelle référence, mettre à jour l'empreinte attendue par le script de construction de l'application, relancer les tests de l'application et refaire les vérifications de « Vérifier l'intégration ». Le module garde la compatibilité de l'état local d'une version à l'autre : aucune nouvelle activation n'est nécessaire.

Passage de la 2.1.0 à la 2.1.1 (mentions de licence) : rien à changer dans le code de l'application ni sur les postes ; seule l'empreinte attendue change. La fenêtre « Licence » affiche désormais, au-dessus de son pied, la mention de Janus et sa licence. Protocole, API et état local sont inchangés : le module 2.1.1 fonctionne avec un serveur 2.1.0 comme avec un serveur 2.1.1.

Passage de la 2.0.0 à la 2.1.0 (licences hors ligne) : rien à changer dans le code de l'application ni sur les postes. Les codes hors ligne courts demandent la quatrième constante, `LICENCE_SECRET_HORS_LIGNE` (déjà renseignée dans la référence du dépôt une fois le serveur installé) ; sans elle, seuls les codes longs sont acceptés.

Passage d'une version 1.x à la 2.0.0 :

- **mettre d'abord le serveur en version 2** (`LISEZ-MOI-SERVEUR.md`, § 8), puis seulement livrer les applications avec le module 2.0.0 : le module 2.0.0 envoie le code produit comme distribution, qu'un serveur 1.x ne reconnaît pas quand le code de distribution diffère du code produit (il répondrait `produit_inconnu`, qui bloque le poste) ;
- retirer le paramètre `distribution=` des appels `installer()`, `exiger()` et `Garde()` (ainsi que la constante `DISTRIBUTION`, le fichier `distribution_construite.py` et le paramètre de `construire_exe.bat`) ; le code produit suffit. Les tests de l'application qui passent `distribution=` sont à adapter de même ;
- retirer toute référence à `VERSION_REFUSEE`, qui n'existe plus ;
- rien à faire sur les postes : au premier lancement en 2.0.0, l'état laissé par la 1.x (`<PRODUIT>-<DISTRIBUTION>.dat`) est repris automatiquement, clé, demande en attente et jeton compris ; les anciens fichiers ne sont ni modifiés ni supprimés.

## Dépannage

- **Ctrl+Maj+L** affiche l'identifiant du poste (à dicter au support, ou pour obtenir un code hors ligne), le statut et la ligne de diagnostic (mention « (licence hors ligne) » le cas échéant) ; « Verifier maintenant » relance un contrôle.
- Code hors ligne refusé : vérifier que le code a été calculé pour l'identifiant du poste affiché et pour ce produit ; code court refusé avec « non pris en charge » : la copie du module n'a pas `LICENCE_SECRET_HORS_LIGNE` (utiliser le code long, ou livrer une copie renseignée) ; « revoque, remplace ou transfere » : ce code a été retiré de ce poste par le serveur ou par un transfert, il en faut un nouveau.
- La colonne « raison » du diagnostic et le journal `licence.log` donnent le détail technique (serveur injoignable, horloge décalée, signature invalide…).
- Horloge de l'ordinateur décalée de plus de 10 minutes : le serveur refuse le contrôle ; corriger la date et l'heure.
- Changement d'ordinateur : « Transferer cette licence » dans la fenêtre « Licence » de l'ancien ordinateur, puis la clé sur le nouveau ; à défaut, « Changer d'ordinateur » sur la fiche de la licence dans la console (voir « Changer d'ordinateur »).
