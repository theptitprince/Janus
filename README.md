# Janus

Système de licences pour les applications Python Windows signées ETDEL : un serveur de licences et sa console d'administration (PHP, hébergement mutualisé OVH), et un module client unique, `etdel_licence.py`, intégré en deux lignes dans chaque application.

| Dossier | Contenu |
|---|---|
| `client/` | module `etdel_licence.py` (à copier tel quel dans chaque application) et ses tests |
| `serveur/` | API, console d'administration, assistant d'installation, tests |
| `docs/` | intégration, déploiement, décisions de réalisation |

## Tests

```
python client/test_etdel_licence.py          # Windows
xvfb-run -a python3 client/test_etdel_licence.py   # Linux sans écran
```

La suite affiche `=== TOUS LES TESTS PASSENT (client) ===` ou la liste des échecs (code de sortie 1). Aucun test ne dépend du réseau réel ni de l'heure réelle.

Les choix de réalisation sont consignés dans [docs/DECISIONS.md](docs/DECISIONS.md).

ETDEL (c) 2026
