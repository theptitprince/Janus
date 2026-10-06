# Intégrer la licence ETDEL dans une application

Module de référence : `client/etdel_licence.py`, version **1.4.0**.
Empreinte SHA-256 (fins de ligne normalisées en LF) : `86ac7b191d28dec162fe86e981629cacb36ea59626a8123e5ef14e9de89196cb`

Le module est **le même pour toutes les applications** : on le copie tel quel, sans aucune modification, à côté du script principal. Il n'a pas de fichier de configuration. Tout ce qui est propre à une application (produit, distribution, version) passe en paramètres.

Tant que les constantes `LICENCE_URL` et `LICENCE_CLE_PUBLIQUE` sont vides, le module ne fait rien : aucun fichier, aucun accès réseau, aucun message. On peut donc l'intégrer avant que le serveur existe.

## Les trois niveaux d'intégration

### 1. Application console

```python
import etdel_licence

APP_VERSION = "1.4.0"

def main():
    etdel_licence.exiger(produit="MONOUTIL", distribution="MONOUTIL-PUBLIC", version=APP_VERSION)
    ...  # suite normale du programme
```

`exiger()` fait un contrôle synchrone (5 secondes au plus). Si la licence n'est pas utilisable, un message est écrit sur la sortie d'erreur et le programme s'arrête avec le code 3. Sans licence, et seulement si le programme tourne dans un terminal interactif, la clé est demandée au clavier.

### 2. Application Tkinter, cas normal

```python
import tkinter as tk
import etdel_licence

APP_VERSION = "1.4.0"

root = tk.Tk()
etdel_licence.installer(root, produit="MONAPPLI", distribution="MONAPPLI-CLIENTA",
                        version=APP_VERSION)   # juste après la création de root
...  # construction habituelle de l'interface
root.mainloop()
```

`installer()` prend tout en charge, sans autre modification du code :

- contrôle en arrière-plan (premier essai 3 s après le lancement, puis toutes les 6 h ; 15 min après un échec) ; la fonction rend la main en moins de 50 ms ;
- fenêtre d'activation si aucune licence n'est active : deux cartes de choix « J'ai une cle » et « Demander une licence » ; la clé se met en forme pendant la frappe (majuscules, tirets, préfixe `ETDEL-`) avec une indication en direct (incomplète, format correct, caractère non valide) ; rien n'est envoyé sans clic explicite ; au clavier, Entrée active le bouton qui a le focus (Entrée sur « Retour » revient en arrière, n'envoie rien) et, dans un champ, l'action principale de la page (« Activer », « Envoyer la demande ») ; Échap revient en arrière depuis la saisie de clé ou le formulaire et ferme la page « Demande envoyee » ; fermer cette fenêtre quitte l'application ;
- bandeau de préavis en superposition (période d'essai sur fond bleu clair, tolérance hors ligne bientôt épuisée ou échéance proche aux couleurs d'accent) ; un clic sur le bandeau ouvre la fenêtre « Licence ». Le bandeau recouvre environ 30 px (à 100 %) du haut de la fenêtre pendant l'essai ou le préavis, davantage si son message passe sur deux lignes dans une fenêtre étroite (moins de 700 px environ) : prévoir cette marge au-dessus de la barre d'outils ;
- relecture de l'état toutes les 25 s ; licence expirée ou révoquée en cours de session : message, puis fermeture ;
- interception de la fermeture de la fenêtre : l'heure atteinte est enregistrée, puis le gestionnaire d'origine de l'application est appelé (à défaut, `root.destroy()`) ; si l'application annule la fermeture, le contrôle continue ; il s'arrête à la destruction réelle de la fenêtre ;
- raccourci **Ctrl+Maj+L** : fenêtre « Licence » (statut en pastille de couleur : vert valide, bleu essai ou attente, ambre avertissement, rouge bloqué ; titulaire, échéance, dernier contrôle, identifiant du poste avec « Copier », clé masquée `ETDEL-****-****-****-ZS95` avec « Afficher la cle », diagnostic avec « Copier le diagnostic » ; au pied, « Verifier maintenant », grisé tant que le poste n'a ni clé ni demande en attente, et « Fermer »). La clé complète n'apparaît que sur demande, jamais dans le diagnostic ni le journal.

`installer()` renvoie la Garde pour les usages avancés.

### 3. Application Tkinter avec page de réglages

```python
garde = etdel_licence.installer(root, produit="MONAPPLI", distribution="MONAPPLI-CLIENTA",
                                version=APP_VERSION, palette=PALETTE_THEME)

# Dans la page de réglages : cadre en lecture seule, placé où l'on veut.
cadre = garde.cadre_licence(page_reglages)
cadre.pack(fill="x", padx=10, pady=10)

# Dans le rapport de diagnostic : une ligne prête à insérer (jamais la clé en clair).
rapport.append(garde.texte_diagnostic())
```

Rien de la licence n'est modifiable depuis les réglages : le cadre est en lecture seule.

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

## Options par distribution

```python
if garde.option("export_pdf"):
    menu_fichier.add_command(label="Exporter en PDF", command=exporter_pdf)
```

`option()` lit la liste d'options du jeton signé par le serveur (réglée par distribution dans la console, surchargeable par licence). Une option absente vaut `False`, de même que tant que la licence n'est pas utilisable. Pendant une période d'essai, ce sont les options de la distribution demandée. Une même base de code peut ainsi livrer une édition démo ou réduite sans construction séparée. Dans la console, le joker `*` (seul dans le champ Options) active toutes les options, y compris celles qu'une version future de l'application ajoutera : `option()` renvoie alors `True` pour tout code (module 1.2.0 et suivants).

## Distribution fixée à la construction de l'exécutable

La distribution est une constante de l'application, fixée au moment de la construction : `construire_exe.bat <distribution>`. Exemple :

```bat
@echo off
rem construire_exe.bat - construit l'exe d'une distribution : construire_exe.bat MONAPPLI-CLIENTA
if "%~1"=="" (echo Usage : construire_exe.bat DISTRIBUTION & exit /b 1)
> distribution_construite.py echo DISTRIBUTION = "%~1"
python -m nuitka --standalone --enable-plugin=tk-inter --windows-console-mode=disable ^
    --output-filename=MONAPPLI-%~1.exe monappli.py
```

```python
try:
    from distribution_construite import DISTRIBUTION
except ImportError:
    DISTRIBUTION = "MONAPPLI-PUBLIC"   # exécution depuis les sources
```

Empaquetage recommandé : **Nuitka** (compilation en C, plus résistante à la décompilation que PyInstaller). Le module est importé normalement : aucune option particulière n'est nécessaire, mais le script de construction doit vérifier que la copie de `etdel_licence.py` est la bonne (empreinte ci-dessus).

## Usages avancés : la Garde

```python
from etdel_licence import Garde, VALIDE, AVERTISSEMENT, EXPIREE

garde = Garde(produit="MONAPPLI", distribution="MONAPPLI-CLIENTA", version=APP_VERSION)
garde.demarrer()                # rend la main, premier essai réseau dans un fil à +3 s
etat = garde.etat()             # dict, voir ci-dessous
garde.option("export_pdf")      # booléen
garde.controler_maintenant()    # toujours dans un fil
garde.arreter()                 # à la fermeture : enregistre l'heure atteinte
```

Aucune fonction publique ne lève d'exception vers l'application : toute erreur interne est journalisée et traduite en statut. `activer(cle)` et `demander(titulaire, email, message)` existent aussi ; elles sont bloquantes (réseau) et doivent être appelées hors du fil de l'interface.

`etat()` renvoie : `statut`, `message` (texte affichable, ASCII), `id_poste`, `nom_ordinateur`, `titulaire`, `echeance`, `jours_restants`, `dernier_controle`, `demande`, `motif_refus`, `url_active`, `kid_actif`, `raison` (détail technique).

| Statut | Signification | Effet avec `installer()` |
|---|---|---|
| `NON_CONFIGURE` | constantes vides | rien |
| `A_ACTIVER` | ni clé ni demande | fenêtre d'activation |
| `ESSAI` | demande en attente, essai en cours | bandeau « Licence en cours de traitement - N jour(s) d'essai restant(s) » |
| `DEMANDE_EN_ATTENTE` | demande en attente, sans essai | fenêtre d'attente, suivi chaque minute |
| `DEMANDE_REFUSEE` | refus reçu | fenêtre : motif éventuel, « Nouvelle demande », « J'ai une cle » |
| `VALIDE` | tout va bien | rien |
| `AVERTISSEMENT` | fin de tolérance hors ligne (préavis, limité à la seconde moitié de la tolérance) ou échéance sous 15 jours | bandeau avec `etat()["message"]` |
| `EXPIREE` | tolérance épuisée, échéance dépassée, ou licence suspendue (le poste garde sa clé ; message « Licence suspendue jusqu'au … » si une date de fin est fixée) | au lancement : fenêtre « Reessayer » (titre « Licence suspendue » en cas de suspension) ; en session : message puis fermeture. La réactivation ou la fin de la suspension débloque au contrôle suivant, sans ressaisie |
| `REVOQUEE` | clé révoquée (définitif), libérée ou liée à un autre poste : le poste efface sa clé | message puis fermeture ; fenêtre d'activation au lancement suivant |
| `VERSION_REFUSEE` | version inférieure à la version minimale | au lancement : fenêtre « Mise a jour necessaire » (« Reessayer », « Quitter ») ; en session : message puis fermeture. Installer une version à jour lève le blocage sans attendre le serveur |

## Ce que le module transmet et conserve

Données transmises au serveur, et rien d'autre : empreinte du poste (SHA-256 de l'identifiant Windows de la machine et du numéro de série du volume système), nom de l'ordinateur (affichage seulement), produit, distribution, version de l'application. Pour une demande, en plus : titulaire, e-mail et message saisis par l'utilisateur. S'y ajoutent les éléments techniques du protocole : nonce, horodatage, clé de licence, jeton et numéro de demande.

État local, chiffré (DPAPI) et scellé, en trois exemplaires : `%APPDATA%\ETDEL\licences\`, `%LOCALAPPDATA%\ETDEL\licences\`, `%PROGRAMDATA%\ETDEL\licences\` (fichier `<PRODUIT>-<DISTRIBUTION>.dat`). Journal : `%LOCALAPPDATA%\ETDEL\licences\licence.log` (1 Mo, 2 archives ; la clé n'y figure jamais, seulement ses 4 derniers caractères).

## Tester l'intégration

Copier `client/test_licence_integration.py` à côté de `etdel_licence.py` dans l'application, puis adapter la section « A ADAPTER » : `PRODUIT`, `DISTRIBUTION` et la fonction `creer_application()`, qui doit construire l'application comme au lancement (sans `mainloop`) et renvoyer sa fenêtre principale. Le test vérifie :

1. que la copie du module est identique à la référence (version et empreinte) ;
2. qu'avec des constantes vides l'application démarre normalement, sans fichier, sans message, sans raccourci de licence ;
3. que le bandeau est visible en `AVERTISSEMENT` (fausse horloge, faux serveur) ;
4. que l'application se ferme proprement en `EXPIREE`.

```
python test_licence_integration.py
```

## Après l'installation du serveur : renseigner les constantes

Une seule fois, dans le dépôt Janus :

1. Ouvrir l'écran **Clés** de la console et copier les trois lignes proposées en tête de `client/etdel_licence.py` (`LICENCE_URL`, `LICENCE_URL_SECOURS`, `LICENCE_CLE_PUBLIQUE`).
2. Calculer la nouvelle empreinte :
   ```
   python -c "import hashlib; print(hashlib.sha256(open('client/etdel_licence.py','rb').read().replace(b'\r\n', b'\n')).hexdigest())"
   ```
3. La reporter dans ce document (ligne « Empreinte SHA-256 ») et dans `EMPREINTE_REFERENCE` de `client/test_licence_integration.py`. Les tests du module échouent tant que ces trois éléments ne concordent pas.
4. Redistribuer cette copie dans les applications.

Une rotation de clé ou un changement d'URL ne demande **pas** de refaire cette opération : les postes reçoivent la liste d'URL et les nouvelles clés par des messages signés et les conservent dans leur état local.

## Mettre à jour le module dans une application

Remplacer `etdel_licence.py` par la nouvelle référence, mettre à jour `VERSION_REFERENCE` et `EMPREINTE_REFERENCE` dans `test_licence_integration.py`, relancer les tests de l'application. Le module garde la compatibilité de l'état local d'une version à l'autre : aucune nouvelle activation n'est nécessaire.

## Dépannage

- **Ctrl+Maj+L** affiche l'identifiant du poste (à dicter au support), le statut et la ligne de diagnostic ; « Verifier maintenant » relance un contrôle.
- La colonne « raison » du diagnostic et le journal `licence.log` donnent le détail technique (serveur injoignable, horloge décalée, signature invalide…).
- Horloge de l'ordinateur décalée de plus de 10 minutes : le serveur refuse le contrôle ; corriger la date et l'heure.
- Changement d'ordinateur : « Changer d'ordinateur » sur la fiche de la licence dans la console, puis saisir la même clé sur le nouvel ordinateur.
