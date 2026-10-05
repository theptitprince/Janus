<?php
// sauvegarde.php - Copie datee de la base (tache planifiee OVH, quotidienne).
//                  Conserve les N copies les plus recentes (config : sauvegardes_conservees).
// ETDEL (c) 2026

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // Le dossier prive n'est jamais servi ; ceinture et bretelles.
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib/commun.php';

try {
    $config = config_charger(__DIR__);
    $db = db_ouvrir((string)$config['base']);
    $fichier = sauvegarde_creer($db, $config, time());
    journal_ecrire($db, 'systeme', 'sauvegarde', basename($fichier), null, null, time());
    echo 'Sauvegarde : ' . $fichier . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Sauvegarde impossible : ' . $e->getMessage() . "\n");
    exit(1);
}
