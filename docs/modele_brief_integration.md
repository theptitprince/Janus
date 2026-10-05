# Modèle de brief — intégrer la licence ETDEL dans une application

À compléter, puis à coller dans Claude Code à la racine de l'application à protéger.

| À remplacer | Valeur |
|---|---|
| `{APPLICATION}` | nom de l'application (ex. Mon application) |
| `{PRODUIT}` | code produit créé dans la console (ex. `MONAPPLI`) |
| `{DISTRIBUTION}` | code de distribution par défaut (ex. `MONAPPLI-CLIENTA`) |
| `{VERSION}` | version du module, lue dans `docs/INTEGRATION.md` du dépôt Janus |
| `{EMPREINTE}` | empreinte SHA-256 du module, lue au même endroit |

---

## Brief

Objectif : soumettre **{APPLICATION}** à la licence ETDEL, avec le module unique `etdel_licence.py` (version {VERSION}, empreinte SHA-256 `{EMPREINTE}`, fins de ligne normalisées en LF), sans rien changer d'autre au comportement de l'application. Référence : `docs/INTEGRATION.md` du dépôt Janus. Consigne générale : ne rien inventer ; en cas de doute, choisir la solution la plus simple et la noter dans le compte rendu.

1. **Copier le module.** Copier `etdel_licence.py` tel quel à côté du script principal, sans aucune modification. Vérifier son empreinte (`python -c "import hashlib; print(hashlib.sha256(open('etdel_licence.py','rb').read().replace(b'\r\n', b'\n')).hexdigest())"` doit afficher `{EMPREINTE}`). Compléter `.gitignore` si besoin (aucun fichier de licence local n'est à versionner : l'état vit dans `%APPDATA%`).

2. **Brancher la licence.** Repérer la création de la fenêtre principale (`root = tk.Tk()` ou équivalent) et ajouter juste après :
   ```python
   import etdel_licence
   etdel_licence.installer(root, produit="{PRODUIT}", distribution=DISTRIBUTION,
                           version=APP_VERSION, palette=...)
   ```
   - `APP_VERSION` : la constante de version existante de l'application (la créer si elle n'existe pas, et le signaler).
   - `DISTRIBUTION` : constante de l'application, fixée à la construction de l'exe (`construire_exe.bat <distribution>`, voir INTEGRATION.md) ; valeur par défaut `"{DISTRIBUTION}"` lors d'une exécution depuis les sources.
   - `palette` : si l'application a un thème, lui passer ses couleurs et polices (clés `fond`, `panneau`, `texte`, `discret`, `accent`, `accent_texte`, `police`, `police_titre`, `police_champ`) ; sinon omettre le paramètre.
   - Application console : utiliser `etdel_licence.exiger(produit="{PRODUIT}", distribution=DISTRIBUTION, version=APP_VERSION)` au début de `main()` à la place.
   - Ne pas toucher à la gestion de `WM_DELETE_WINDOW`, aux boucles d'événements ni aux fils de l'application : `installer()` s'en occupe.

3. **Facultatif.** Si l'application a une page de réglages, y insérer `garde.cadre_licence(parent)` (lecture seule) en gardant la valeur renvoyée par `installer()`. Si elle produit un rapport de diagnostic, y ajouter la ligne `garde.texte_diagnostic()`. Si des fonctions doivent dépendre de l'édition, les conditionner par `garde.option("<code>")` et lister les codes utilisés.

4. **Construction de l'exécutable.** Ajouter `etdel_licence.py` aux fichiers vérifiés par le script de construction (contrôle de l'empreinte avant la compilation ; échec si elle diffère). Empaquetage recommandé : Nuitka.

5. **Tests.** Copier `test_licence_integration.py` (depuis `client/` du dépôt Janus) à côté du module ; renseigner `PRODUIT`, `DISTRIBUTION` et `creer_application()`, qui doit construire l'application réelle comme au lancement, sans `mainloop`, et renvoyer sa fenêtre principale. Le test doit être vert : constantes vides = démarrage normal ; empreinte du module conforme ; bandeau visible en `AVERTISSEMENT` (fausse horloge) ; fermeture propre en `EXPIREE`. L'ajouter à la commande de tests habituelle de l'application.

6. **Documentation.** Ajouter au LISEZ-MOI de l'application une section « Licence » :
   - obtention : clé fournie par ETDEL, ou demande depuis l'application (« Demander une licence » ; le traitement peut prendre plusieurs jours, une période d'essai peut être accordée en attendant) ;
   - vérification périodique auprès du serveur ETDEL (au lancement puis toutes les 6 heures) ;
   - tolérance sans connexion (15 jours par défaut) et préavis (bandeau pendant les 5 derniers jours) ;
   - données transmises : empreinte du poste, nom de l'ordinateur, produit, version ; pour une demande, titulaire, e-mail et message saisis ;
   - raccourci **Ctrl+Maj+L** : fenêtre « Licence » (identifiant du poste, statut, « Verifier maintenant »).

7. **Critère d'acceptation.** Les tests existants de l'application restent verts, `test_licence_integration.py` est vert, et le démarrage n'est pas plus lent (`installer()` rend la main en moins de 50 ms ; aucun accès réseau sur le fil de l'interface). Livrer un court compte rendu : fichiers modifiés, valeur de `APP_VERSION`, distributions prévues, options utilisées, écarts éventuels avec ce brief.
