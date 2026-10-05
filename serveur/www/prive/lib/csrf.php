<?php
// csrf.php - Protection CSRF de la console : jeton par session (la session PHP ne
//            sert qu'a ce jeton) et controle de l'en-tete Origin.
// ETDEL (c) 2026

declare(strict_types=1);

function csrf_jeton(array &$session): string
{
    if (!isset($session['csrf']) || !is_string($session['csrf']) || strlen($session['csrf']) < 32) {
        $session['csrf'] = b64url(random_bytes(32));
    }
    return $session['csrf'];
}

/** Nouveau jeton apres chaque ecriture : un formulaire renvoye (F5) est refuse. */
function csrf_renouveler(array &$session): string
{
    $session['csrf'] = b64url(random_bytes(32));
    return $session['csrf'];
}

/** null si la requete est acceptable, sinon le motif du refus. */
function csrf_verifier(array &$session, $jeton, ?string $origine, string $hote): ?string
{
    if (!is_string($jeton) || !isset($session['csrf']) || !is_string($session['csrf'])
        || !hash_equals($session['csrf'], $jeton)) {
        return 'Formulaire expire ou invalide : rechargez la page puis recommencez.';
    }
    if ($origine !== null && $origine !== '') {
        $morceaux = parse_url($origine);
        $source = is_array($morceaux) && isset($morceaux['host'])
            ? $morceaux['host'] . (isset($morceaux['port']) ? ':' . $morceaux['port'] : '') : '';
        if ($source === '' || strcasecmp($source, $hote) !== 0) {
            return 'Origine de la requete refusee.';
        }
    }
    return null;
}
