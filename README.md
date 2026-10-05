# Janus

Système de licences pour les applications Python Windows signées ETDEL : un serveur de licences et sa console d'administration (PHP, hébergement mutualisé OVH), et un module client unique, `etdel_licence.py`, intégré en deux lignes dans chaque application.

| Dossier | Contenu |
|---|---|
| `client/` | module `etdel_licence.py` (à copier tel quel dans chaque application) et ses tests |
| `serveur/` | API, console d'administration, assistant d'installation, tests |
| `docs/` | [intégration](docs/INTEGRATION.md), [modèle de brief d'intégration](docs/modele_brief_integration.md), déploiement, [décisions de réalisation](docs/DECISIONS.md) |

## Tests

```
python client/test_etdel_licence.py                # module client (Windows)
xvfb-run -a python3 client/test_etdel_licence.py   # module client (Linux sans écran)
xvfb-run -a python3 client/test_licence_integration.py  # test générique à copier dans chaque application
php serveur/tests/test_serveur.php                 # serveur, PHP CLI, base temporaire
python3 serveur/tests/test_bout_en_bout.py         # serveur PHP réel (php -S) + client réel
```

Chaque suite affiche `=== TOUS LES TESTS PASSENT (<suite>) ===` ou la liste des échecs (code de sortie 1). Aucun test ne dépend du réseau réel ; les tests unitaires injectent l'heure (voir D30 pour le test de bout en bout).

Les choix de réalisation sont consignés dans [docs/DECISIONS.md](docs/DECISIONS.md).

ETDEL (c) 2026
