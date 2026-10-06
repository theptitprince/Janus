# Janus

Système de licences pour les applications Python Windows signées ETDEL : un serveur de licences et sa console d'administration (PHP, hébergement mutualisé OVH), et un module client unique, `etdel_licence.py`, intégré en deux lignes dans chaque application.

| Dossier | Contenu |
|---|---|
| `client/` | module `etdel_licence.py` (à copier tel quel dans chaque application), ses tests, application de démonstration |
| `serveur/www/` | tout le serveur, à envoyer tel quel par FTP : API, console d'administration, assistant d'installation, et `prive/` (bibliothèques, configuration, clés, base), jamais servi |
| `serveur/tests/` | tests du serveur et outils du banc d'essai local (jamais envoyés sur l'hébergement) |
| `docs/` | [intégration](docs/INTEGRATION.md), [modèle de brief d'intégration](docs/modele_brief_integration.md), [déploiement et exploitation du serveur](docs/LISEZ-MOI-SERVEUR.md), [décisions de réalisation](docs/DECISIONS.md) |

## Tests

Sous Windows, avec le PHP portable du dossier `php/` (non versionné) :

```
python client/test_etdel_licence.py                 # module client
python client/test_licence_integration.py           # test générique à copier dans chaque application
php\php.exe serveur/tests/test_serveur.php          # serveur, PHP CLI, base temporaire
python serveur/tests/test_bout_en_bout.py           # serveur PHP réel (php -S) + client et démo réels (php dans le PATH)
```

Sous Linux sans écran, préfixer les suites Python par `xvfb-run -a`.

Chaque suite affiche `=== TOUS LES TESTS PASSENT (<suite>) ===` ou la liste des échecs (code de sortie 1). Aucun test ne dépend du réseau réel ; les tests unitaires injectent l'heure (voir D30 pour le test de bout en bout).

## Banc d'essai local

Le serveur complet tourne sur le poste de développement, directement depuis `serveur/www/`, avec le serveur intégré de PHP. `serveur/tests/routeur_banc.php` y reproduit ce que fait Apache sur l'hébergement : authentification de la console et refus de tout ce que les `.htaccess` protègent (`prive/`). Les e-mails de notification sont reçus par une boîte aux lettres locale, sans rien envoyer sur Internet.

1. Créer `serveur/www/prive/config.php` (non versionné) à partir de `config.exemple.php`, avec un `jeton_installation` d'au moins 20 caractères.
2. Démarrer la boîte aux lettres : `python serveur/tests/smtp_banc.py` (e-mails rangés dans `serveur/tests/courriels/`).
3. Démarrer le serveur (configuration `banc-licences` de `.claude/launch.json`) :
   ```
   php\php.exe -d SMTP=127.0.0.1 -d smtp_port=2525 -S 127.0.0.1:8090 -t serveur/www serveur/tests/routeur_banc.php
   ```
4. `http://127.0.0.1:8090/install.php` : installation (l'URL proposée est `http://127.0.0.1:8090/api/v1/`) ; noter la clé publique affichée.
5. `http://127.0.0.1:8090/admin/` : créer le produit `DEMO` et la distribution `DEMO-BANC` (option `export_pdf` par exemple), puis une clé.
6. Application de démonstration : double-cliquer `client/lancer_demo_banc.bat` (ou `python client/demo_appli.py --banc`). Elle se branche sur le banc avec la première clé publique de l'installation, comme une application livrée, et suit les rotations par bulletins. Boutons « Fenetre Licence » (même fenêtre que Ctrl+Maj+L) et « Supprimer la licence de ce poste (essais) », qui efface l'état local de la démo et la relance (côté serveur, la licence reste liée au poste et l'essai déjà accordé n'est pas redonné). Hors banc : `--serveur <url> --cle-publique <clé>`.

Pour repartir de zéro : arrêter le serveur, supprimer `serveur/www/prive/data/`, `serveur/www/prive/cles/`, `serveur/www/prive/.htpasswd`, `serveur/www/prive/install.verrou`, remettre `serveur/www/admin/.htaccess` depuis Git (`git checkout serveur/www/admin/.htaccess`), et, côté poste, supprimer `%APPDATA%\ETDEL`, `%LOCALAPPDATA%\ETDEL` et `%PROGRAMDATA%\ETDEL` (ou utiliser le bouton de la démo).

HTTP clair n'est accepté par le module qu'en boucle locale (`127.0.0.1`, `localhost`).

Les choix de réalisation sont consignés dans [docs/DECISIONS.md](docs/DECISIONS.md).

ETDEL (c) 2026
