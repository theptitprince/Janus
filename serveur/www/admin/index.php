<?php
// index.php - Routeur unique de la console d'administration (protegee par .htaccess / .htpasswd).
//             Toute la logique est dans prive/lib/admin.php, hors de la racine web.
// ETDEL (c) 2026

declare(strict_types=1);

$prive = null;
foreach (array_merge(array_filter([getenv('ETDEL_PRIVE') ?: null]), [dirname(__DIR__, 2) . '/prive', dirname(__DIR__) . '/prive'])
         as $candidat) {
    if (is_file($candidat . '/lib/admin.php')) {
        $prive = $candidat;
        break;
    }
}
if ($prive === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Console indisponible : dossier prive introuvable.\n";
    exit;
}
require $prive . '/lib/admin.php';
admin_point_entree($prive);
