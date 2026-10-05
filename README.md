# Janus

Système de licences pour les applications Python Windows signées ETDEL : un serveur de licences et sa console d'administration (PHP, hébergement mutualisé OVH), et un module client unique, `etdel_licence.py`, intégré en deux lignes dans chaque application.

| Dossier | Contenu |
|---|---|
| `client/` | module `etdel_licence.py` (à copier tel quel dans chaque application) et ses tests |
| `serveur/` | API, console d'administration, assistant d'installation, tests |
| `docs/` | [intégration](docs/INTEGRATION.md), [modèle de brief d'intégration](docs/modele_brief_integration.md), [déploiement et exploitation du serveur](docs/LISEZ-MOI-SERVEUR.md), [décisions de réalisation](docs/DECISIONS.md) |

## Tests

```
python client/test_etdel_licence.py                # module client (Windows)
xvfb-run -a python3 client/test_etdel_licence.py   # module client (Linux sans écran)
xvfb-run -a python3 client/test_licence_integration.py  # test générique à copier dans chaque application
php serveur/tests/test_serveur.php                 # serveur, PHP CLI, base temporaire
xvfb-run -a python3 serveur/tests/test_bout_en_bout.py  # serveur PHP réel (php -S) + client et démo réels
```

## Banc d'essai local

Le serveur complet tourne sur le poste de développement avec le serveur intégré de PHP ; `serveur/tests/routeur_banc.php` y reproduit l'authentification Apache de la console.

```
mkdir -p ~/banc && cp -r serveur/www serveur/prive ~/banc/
echo "<?php return ['jeton_installation' => 'un-jeton-de-banc-assez-long'];" > ~/banc/prive/config.php
php -S 127.0.0.1:8080 -t ~/banc/www serveur/tests/routeur_banc.php
```

1. `http://127.0.0.1:8080/install.php` : installation (l'URL proposée est `http://127.0.0.1:8080/api/v1/`) ; noter la clé publique affichée.
2. `http://127.0.0.1:8080/admin/` : créer le produit `DEMO` et la distribution `DEMO-BANC` (option `export_pdf` par exemple), puis une clé.
3. `python client/demo_appli.py --serveur http://127.0.0.1:8080/api/v1/ --cle-publique <clé publique>` : activer la clé, ou envoyer une demande et la traiter dans la console.

HTTP clair n'est accepté par le module qu'en boucle locale (`127.0.0.1`, `localhost`).

Chaque suite affiche `=== TOUS LES TESTS PASSENT (<suite>) ===` ou la liste des échecs (code de sortie 1). Aucun test ne dépend du réseau réel ; les tests unitaires injectent l'heure (voir D30 pour le test de bout en bout).

Les choix de réalisation sont consignés dans [docs/DECISIONS.md](docs/DECISIONS.md).

ETDEL (c) 2026
