# Modèle de brief — intégrer la licence ETDEL dans une application

À compléter, puis à remettre à la personne qui réalise l'intégration ; les chemins et les commandes du brief s'entendent depuis la racine de l'application à protéger.

Avant de livrer une application avec le module 2.x (2.0.0 et suivants) : mettre d'abord le serveur en version 2 (`docs/LISEZ-MOI-SERVEUR.md`, § 8), puis seulement livrer les applications. Un serveur plus ancien ne reconnaît pas toujours les requêtes du module 2.x et bloquerait les postes (« Produit inconnu »). Le module 2.1.0 accepte en plus les licences hors ligne (codes `ETDHL-…` et `ETDHL2-…`) ; une fois le serveur installé, la copie de référence du dépôt Janus porte les quatre constantes renseignées (`LICENCE_SECRET_HORS_LIGNE` comprise), à copier sans rien modifier.

| À remplacer | Valeur |
|---|---|
| `{APPLICATION}` | nom de l'application (ex. Mon application) |
| `{PRODUIT}` | code produit de l'application (ex. `MONAPPLI`) : il l'identifie auprès du serveur, qui le crée automatiquement à la première demande de licence (on peut aussi le créer à l'avance dans la console, écran Produits) |
| `{VERSION}` | version du module, lue dans `docs/INTEGRATION.md` du dépôt Janus |
| `{EMPREINTE}` | empreinte SHA-256 du module, lue au même endroit |

---

## Brief

Objectif : soumettre **{APPLICATION}** à la licence ETDEL, avec le module unique `etdel_licence.py` (version {VERSION}, empreinte SHA-256 `{EMPREINTE}`, fins de ligne normalisées en LF), sans rien changer d'autre au comportement de l'application. Référence : `docs/INTEGRATION.md` du dépôt Janus. Consigne générale : ne rien inventer ; en cas de doute, choisir la solution la plus simple et la noter dans le compte rendu.

1. **Copier le module.** Copier `etdel_licence.py` tel quel à côté du script principal, sans aucune modification. Vérifier son empreinte (`python -c "import hashlib; print(hashlib.sha256(open('etdel_licence.py','rb').read().replace(b'\r\n', b'\n')).hexdigest())"` doit afficher `{EMPREINTE}`). Compléter `.gitignore` si besoin (aucun fichier de licence local n'est à versionner : l'état vit dans `%APPDATA%`).

2. **Brancher la licence.** Repérer la création de la fenêtre principale (`root = tk.Tk()` ou équivalent) et ajouter juste après :
   ```python
   import etdel_licence
   etdel_licence.installer(root, produit="{PRODUIT}", version=APP_VERSION, palette=...)
   ```
   - `APP_VERSION` : la constante de version existante de l'application (la créer si elle n'existe pas, et le signaler). Elle est transmise au serveur pour information ; aucune version n'est jamais refusée.
   - `palette` : si l'application a un thème, lui passer ses couleurs et polices (clés `fond`, `panneau`, `texte`, `discret`, `accent`, `accent_texte`, `police`, `police_titre`, `police_champ`) ; sinon omettre le paramètre.
   - Application console : utiliser `etdel_licence.exiger(produit="{PRODUIT}", version=APP_VERSION)` au début de `main()` à la place.
   - Ne pas toucher à la gestion de `WM_DELETE_WINDOW`, aux boucles d'événements ni aux fils de l'application : `installer()` s'en occupe.

3. **Facultatif.** Si l'application a une page de réglages, y insérer `garde.cadre_licence(parent)` (lecture seule) en gardant la valeur renvoyée par `installer()`. Si elle produit un rapport de diagnostic, y ajouter la ligne `garde.texte_diagnostic()`. Si des fonctions doivent dépendre de l'édition, les conditionner par `garde.option("<code>")` et lister les codes utilisés (à saisir ensuite dans les règles du produit, console, écran Produits).

4. **Construction de l'exécutable.** Ajouter `etdel_licence.py` aux fichiers vérifiés par le script de construction (contrôle de l'empreinte avant la compilation ; échec si elle diffère). Empaquetage recommandé : Nuitka.

5. **Vérifications.** Faire, sur l'application réelle, les vérifications de la section « Vérifier l'intégration » de `docs/INTEGRATION.md` du dépôt Janus : empreinte du module conforme ; constantes vides = démarrage et fermeture inchangés, sans fenêtre ni bandeau ; constantes renseignées = fenêtre d'activation sur un poste sans licence ; bandeau d'essai visible sans masquer d'élément indispensable ; fin propre de l'application quand la licence est bloquée en cours de session ; licence hors ligne sans connexion = application ouverte, sans fenêtre ni bandeau. Noter le résultat de chacune dans le compte rendu.

6. **Documentation.** Ajouter au LISEZ-MOI de l'application une section « Licence » :
   - obtention : clé fournie par ETDEL, ou demande depuis l'application (« Demander une licence » ; le traitement peut prendre plusieurs jours, une période d'essai peut être accordée en attendant) ;
   - vérification périodique auprès du serveur ETDEL (au lancement puis toutes les 6 heures) ;
   - tolérance sans connexion (15 jours par défaut) et préavis (bandeau pendant les 5 derniers jours) ;
   - données transmises : empreinte du poste, nom de l'ordinateur, produit, version ; pour une demande, titulaire, e-mail et message saisis ;
   - raccourci **Ctrl+Maj+L** : fenêtre « Licence » (identifiant du poste, statut, « Verifier maintenant ») ;
   - changement d'ordinateur : « Transferer cette licence » dans la fenêtre « Licence » de l'ancien ordinateur (connecté à Internet), noter la clé affichée, puis la saisir sur le nouvel ordinateur (« J'ai une cle ») ; trois transferts au plus en 30 jours, au-delà ETDEL libère la licence ;
   - ordinateur jamais connecté à Internet : communiquer à ETDEL l'identifiant du poste (fenêtre d'activation ou Ctrl+Maj+L) ; ETDEL fournit un code hors ligne, court à dicter (`ETDHL-…`) ou long à copier-coller depuis un e-mail (`ETDHL2-…`), à saisir dans « J'ai une cle » ; il est vérifié sans connexion et vaut jusqu'à la date indiquée, puis il faut un nouveau code ; si l'ordinateur se connecte un jour, la licence est vérifiée comme les autres (prolongation, suspension ou révocation décidées par ETDEL s'appliquent alors).

7. **Critère d'acceptation.** Les tests existants de l'application restent verts, les vérifications de l'étape 5 sont concluantes, et le démarrage n'est pas plus lent (`installer()` rend la main en moins de 50 ms ; aucun accès réseau sur le fil de l'interface). Livrer un court compte rendu : fichiers modifiés, valeur de `APP_VERSION`, code produit utilisé, options utilisées, écarts éventuels avec ce brief.
