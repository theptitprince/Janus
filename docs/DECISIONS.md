# Décisions

Choix faits pendant la réalisation, sur les points non couverts ou ambigus du cahier des charges (consigne 0.1). Chaque entrée : date, question, décision, raison.

## Organisation

### D1 — 2026-10-05 — Nom et dépôt
- **Question** : le cahier des charges nomme le dépôt `etdel-licence` ; la session de travail fournit le dépôt `theptitprince/Janus`.
- **Décision** : le projet s'appelle **Janus** (dieu romain des portes et des passages) et vit dans ce dépôt.
- **Raison** : demande « tu trouveras un nom » ; le dépôt existait déjà et c'est le seul accessible en écriture.

### D2 — 2026-10-05 — Une branche par étape
- **Question** : « une branche et un commit par étape » alors que la session n'autorise qu'une branche de travail.
- **Décision** : un commit par étape, dans l'ordre, sur la branche `claude/python-windows-license-system-v8kmek`.
- **Raison** : contrainte de l'environnement ; l'historique reste lisible étape par étape.

### D3 — 2026-10-05 — Fichier LICENSE existant
- **Question** : le dépôt a été créé avec une licence GPL v3 alors que le code est propriétaire (« ETDEL (c) 2026 »).
- **Décision** : fichier laissé tel quel.
- **Raison** : choix juridique qui revient à Etienne (à trancher).

## Module client

### D4 — 2026-10-05 — Constantes d'URL
- **Question** : « LICENCE_URL (URL initiale, plus une URL de secours) ».
- **Décision** : deux constantes chaînes, `LICENCE_URL` et `LICENCE_URL_SECOURS`, plus `LICENCE_CLE_PUBLIQUE`. Le module est `NON_CONFIGURE` si les deux URL sont vides ou si la clé est vide.
- **Raison** : solution la plus simple, lisible et sans liste à éditer.

### D5 — 2026-10-05 — Signature de `exiger()`
- **Question** : le texte montre `exiger(version=APP_VERSION)` sans produit ni distribution, indispensables au contrôle.
- **Décision** : `exiger(produit, distribution, version)`. Sans licence et dans un terminal interactif, la clé est demandée au clavier ; sinon message sur stderr et `sys.exit(3)`. Pas de demande de licence en mode console.
- **Raison** : une application console doit pouvoir s'activer ; la saisie clavier est la voie la plus simple et reste une action explicite.

### D6 — 2026-10-05 — Méthodes `activer()` et `demander()`
- **Question** : la fenêtre d'activation a besoin d'opérations non listées dans l'API de la Garde.
- **Décision** : `Garde.activer(cle)` et `Garde.demander(titulaire, email, message)` sont publiques, bloquantes, à appeler hors du fil de l'interface ; elles renvoient `{"ok", "code", "message"}` et ne lèvent jamais d'exception.
- **Raison** : utiles aussi pour une intégration avancée ; la fenêtre les appelle dans un fil.

### D7 — 2026-10-05 — Paramètres d'injection pour les tests
- **Question** : horloge, transport, dossiers, empreinte doivent être injectables.
- **Décision** : paramètres préfixés `_` (`_horloge`, `_transport`, `_dossiers`, `_machine`, `_urls`, `_cle_publique`, `_fil`, `_monotone`, `_garde`…). Aucun n'est documenté pour les applications.
- **Raison** : l'API publique reste celle du cahier des charges.

### D8 — 2026-10-05 — HTTP clair en boucle locale
- **Question** : HTTPS obligatoire, mais le banc d'essai tourne avec `php -S` (HTTP).
- **Décision** : HTTP accepté uniquement vers `127.0.0.1`, `localhost` et `::1` ; toute autre URL non HTTPS est refusée, y compris dans une liste signée. Les redirections HTTP ne sont pas suivies (un POST redirigé deviendrait un GET) : elles comptent comme serveur injoignable.
- **Raison** : rend possible le test de bout en bout sans affaiblir le transport réel.

### D9 — 2026-10-05 — Format de clé et identifiant de poste
- **Question** : forme exacte de la somme de contrôle et de la lecture tolérante.
- **Décision** : 16e caractère = somme des valeurs des 15 premiers modulo 32. À la saisie, `O` est lu `0`, `I` et `L` sont lus `1` (règle de Crockford). Identifiant de poste : 40 premiers bits de SHA-256(machine + produit), 8 caractères base32 de Crockford, poids fort en tête.
- **Raison** : définition la plus simple qui détecte toute faute de frappe sur un caractère.

### D10 — 2026-10-05 — Empreinte machine
- **Question** : comment combiner MachineGuid et numéro de série du volume.
- **Décision** : `machine = SHA-256("<MachineGuid en minuscules>|<numéro de série en hexadécimal sur 8 chiffres>")`. Le registre est lu en vue 64 bits (`KEY_WOW64_64KEY`) pour qu'un Python 32 bits trouve la valeur. Hors Windows (développement uniquement) : `/etc/machine-id`.
- **Raison** : forme stable et documentée ; la vue 64 bits évite une empreinte vide sur un exe 32 bits.

### D11 — 2026-10-05 — Format de l'état local
- **Question** : détail du chiffrement, du scellement et de la sélection de l'exemplaire « le plus récent ».
- **Décision** : fichier `ETDL\x01` + méthode (`D` = DPAPI portée utilisateur ; `X` = flux SHA-256 si DPAPI indisponible, c'est-à-dire hors Windows) + sceau HMAC-SHA256 + données. Clés dérivées de SHA-256(`ETDEL|machine|produit|distribution`). Un compteur `seq` incrémenté à chaque écriture désigne l'exemplaire le plus récent. Hors Windows, dossiers `~/.config/ETDEL/licences` et `~/.local/share/ETDEL/licences`. Le jeton stocké est revérifié (signature Ed25519) à chaque lancement.
- **Raison** : un compteur est insensible aux changements d'heure ; la revérification rend inutile toute modification manuelle du cache, même scellé correctement.

### D12 — 2026-10-05 — Garde anti-recul d'horloge
- **Question** : « si maintenant < heure_max − 48 h, le calcul utilise heure_max ». Appliquée seule, cette règle fige le temps tant que l'horloge reste reculée, ce qui prolongerait la tolérance indéfiniment.
- **Décision** : pendant l'exécution, `heure_max` avance aussi avec l'horloge monotone du système ; à chaque réponse signée, elle est recalée sur `max(heure locale, emis)` (horodatage signé du serveur, lié à notre nonce).
- **Raison** : respecte le critère « recul d'horloge : ne prolonge pas la tolérance » ; le recalage répare une avance accidentelle de l'horloge, qui sinon bloquerait le poste pour toujours.

### D13 — 2026-10-05 — Traitement des codes de refus
- **Question** : effet côté client des codes non décrits dans l'annexe D.
- **Décision** :
  - `revoquee`, `suspendue`, `poste_revoque`, `cle_liee_autre_poste` et `cle_invalide` (en validation) → `REVOQUEE`, clé locale effacée ;
  - `expiree` et `produit_inconnu` → `EXPIREE`, clé conservée (une prolongation ou une réactivation débloque au contrôle suivant) ;
  - `version_trop_ancienne` → `VERSION_REFUSEE` ;
  - `horloge`, `trop_de_requetes`, `requete_invalide` → transitoires (nouvel essai 15 min plus tard), statut inchangé ;
  - `poste_revoque` est le code renvoyé par `valider` quand la clé a été libérée dans la console.
- **Raison** : une clé supprimée ne doit pas être réessayée sans fin ; un blocage administratif réversible ne doit pas forcer une nouvelle saisie.

### D14 — 2026-10-05 — EXPIREE au lancement
- **Question** : l'annexe D dit « message puis fermeture » ; la section 7 prévoit une « fenêtre de licence et bouton Réessayer » quand la tolérance est épuisée.
- **Décision** : au lancement, `EXPIREE` ouvre la fenêtre de licence (« Reessayer », « J'ai une cle ») ; en cours de session, message puis fermeture.
- **Raison** : concilie les deux passages ; un poste revenu à portée du réseau peut se débloquer sans relancer l'application.

### D15 — 2026-10-05 — Fenêtre d'activation
- **Question** : comportement de la fenêtre principale pendant l'activation ; actions disponibles en attente.
- **Décision** : la fenêtre principale est masquée (`withdraw`) tant que la fenêtre d'activation est ouverte, puis réaffichée. En attente sans essai, la fenêtre propose aussi « J'ai une cle ». Après envoi avec essai, la fenêtre affiche la confirmation et un bouton « Continuer ».
- **Raison** : l'application ne doit pas être utilisable avant la licence ; une clé peut arriver par un autre canal pendant l'attente ; la mention de délai doit rester visible après l'envoi.

### D16 — 2026-10-05 — Options pendant l'essai et sans configuration
- **Question** : que renvoie `option()` pendant l'essai, et en `NON_CONFIGURE` ?
- **Décision** : pendant l'essai, les options de la distribution, transmises signées dans les réponses `demander` et `suivre_demande` ; en `NON_CONFIGURE` et dans tout statut non utilisable, `False`.
- **Raison** : l'essai reflète l'édition demandée ; « une option absente vaut False ».

### D17 — 2026-10-05 — Demande idempotente
- **Question** : si la réponse à `demander` se perd, le poste ne connaît pas le numéro et toute nouvelle demande serait refusée (`demande_en_cours`).
- **Décision** : le jeton de demande est enregistré localement avant l'envoi et réutilisé au renvoi ; le serveur renvoie la demande en attente existante quand le jeton correspond.
- **Raison** : évite un blocage définitif sans assouplir la règle « une seule demande en attente ».

### D18 — 2026-10-05 — Après acceptation
- **Question** : la clé conservée chiffrée par le serveur est effacée « après le premier valider réussi ».
- **Décision** : dès réception de l'acceptation, le client enchaîne un `valider`.
- **Raison** : la clé ne reste pas sur le serveur jusqu'au contrôle suivant (6 h).

### D19 — 2026-10-05 — Rotation : clé embarquée et 90 jours
- **Question** : la clé embarquée n'a pas de `kid` ; sens de « l'ancienne reste acceptée 90 jours ».
- **Décision** : la clé embarquée apprend son `kid` à sa première vérification réussie. Les bulletins sont vérifiés avant la réponse qui les porte (ils sont signés par la clé précédente). Après adoption d'une nouvelle clé, l'ancienne vérifie encore de nouvelles réponses jusqu'à `valide_des` + 90 jours ; un jeton déjà stocké reste vérifiable avec elle.
- **Raison** : un poste neuf livré avec l'ancienne clé suit la chaîne de bulletins ; une clé retirée et éventuellement compromise ne peut plus annoncer de clé après 90 jours.

### D20 — 2026-10-05 — Divers client
- **Question** : points de détail.
- **Décision** :
  - le message d'accueil de la distribution s'affiche dans la fenêtre « Licence » et dans `cadre_licence()` ;
  - `diagnostic_text()` est un alias de `texte_diagnostic()` (les deux noms figurent dans le cahier des charges) ;
  - en `ESSAI`, `jours_restants` donne les jours d'essai restants ;
  - préavis d'échéance fixe de 15 jours (annexe D) ; si les deux préavis s'appliquent, le message porte sur la date la plus proche ;
  - `exiger()` ne lance pas de fil de contrôle et enregistre `arreter()` à la sortie (`atexit`) ;
  - la table de translittération est écrite avec `chr()` pour que le fichier reste en ASCII pur.
- **Raison** : solutions les plus simples compatibles avec le texte.

### D21 — 2026-10-05 — Exécution des tests Tkinter
- **Question** : l'environnement de développement n'a Tkinter que pour Python 3.12.
- **Décision** : les tests Tkinter s'exécutent sous Xvfb avec Python 3.12 ; le reste est aussi vérifié avec Python 3.10 et 3.11. Sans Tkinter, la suite échoue, sauf si `ETDEL_TESTS_SANS_TK=1` est positionné explicitement.
- **Raison** : ne jamais déclarer verte une suite qui a sauté sa partie graphique sans le dire.
