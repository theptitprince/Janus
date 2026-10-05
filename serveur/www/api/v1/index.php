<?php
// index.php - Routeur unique de l'API de licences v1 (POST JSON, reponses signees Ed25519).
//             Toute la logique est dans prive/lib/api.php, hors de la racine web.
// ETDEL (c) 2026

declare(strict_types=1);

$candidats = [dirname(__DIR__, 3) . '/prive', dirname(__DIR__, 2) . '/prive'];
$prive = null;
foreach (array_merge(array_filter([getenv('ETDEL_PRIVE') ?: null]), $candidats) as $candidat) {
    if (is_file($candidat . '/lib/api.php')) {
        $prive = $candidat;
        break;
    }
}
if ($prive === null) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"ok":false,"code":"non_installe"}';
    exit;
}
require $prive . '/lib/api.php';
api_point_entree($prive);
