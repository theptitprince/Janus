<?php
// journal.php - Journal des evenements (API, console, systeme) dans la table journal.
// ETDEL (c) 2026

declare(strict_types=1);

function journal_ecrire(PDO $db, string $acteur, string $action, ?string $cible, ?string $detail,
                        ?string $ip, int $maintenant): void
{
    db_inserer($db, 'journal', [
        'date' => $maintenant,
        'acteur' => tronquer_utf8($acteur, 64),
        'action' => tronquer_utf8($action, 64),
        'cible' => $cible === null ? null : tronquer_utf8($cible, 200),
        'detail' => $detail === null ? null : tronquer_utf8($detail, 2000),
        'ip' => $ip,
    ]);
}
