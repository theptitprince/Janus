<?php
// config.exemple.php - Parametres du serveur de licences.
// A copier en config.php (meme dossier), a completer, puis a envoyer par FTP.
// config.php n'est jamais versionne (.gitignore).
// ETDEL (c) 2026

return [
    // Jeton a usage unique demande par install.php : une chaine aleatoire
    // d'au moins 20 caracteres, inventee pour l'occasion.
    'jeton_installation' => '',

    // Mot de passe de la console perdu (seulement dans ce cas) : inscrire ici une
    // nouvelle chaine aleatoire d'au moins 20 caracteres, differente de la
    // precedente, puis ouvrir install.php. Chaque jeton ne sert qu'une fois ;
    // le remettre a vide ensuite.
    'jeton_reinitialisation' => '',

    // Base SQLite et sauvegardes : hors du dossier www (dossier data/ a cote de prive/).
    'base' => dirname(__DIR__) . '/data/licenses.db',
    'dossier_sauvegardes' => dirname(__DIR__) . '/data/sauvegardes',
    'sauvegardes_conservees' => 30,

    // Cles privees de signature et .htpasswd de la console : crees par install.php.
    'cles' => __DIR__ . '/cles',
    'htpasswd' => __DIR__ . '/.htpasswd',

    // Fuseau des dates affichees dans la console et les e-mails.
    'fuseau' => 'Europe/Paris',

    // Adresse de la console pour le lien des e-mails (vide = deduite de la
    // premiere URL d'API active : .../api/v1/ devient .../admin/).
    'url_console' => '',

    // Plafond d'e-mails de notification par jour (protection contre l'inondation).
    'emails_par_jour' => 50,
];
