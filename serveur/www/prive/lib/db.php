<?php
// db.php - Acces a la base SQLite : connexion, requetes preparees, reglages.
// ETDEL (c) 2026

declare(strict_types=1);

function db_ouvrir(string $chemin): PDO
{
    if (!is_file($chemin)) {
        throw new RuntimeException('base absente : lancer install.php');
    }
    return db_connecter($chemin);
}

function db_connecter(string $chemin): PDO
{
    $db = new PDO('sqlite:' . $chemin, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');
    // Mutualise : plusieurs requetes concurrentes ; on attend le verrou plutot que d'echouer.
    $db->exec('PRAGMA busy_timeout = 5000');
    return $db;
}

function db_executer(PDO $db, string $sql, array $parametres = []): PDOStatement
{
    $requete = $db->prepare($sql);
    $requete->execute($parametres);
    return $requete;
}

function db_lignes(PDO $db, string $sql, array $parametres = []): array
{
    return db_executer($db, $sql, $parametres)->fetchAll();
}

function db_ligne(PDO $db, string $sql, array $parametres = []): ?array
{
    $ligne = db_executer($db, $sql, $parametres)->fetch();
    return $ligne === false ? null : $ligne;
}

function db_valeur(PDO $db, string $sql, array $parametres = [])
{
    $valeur = db_executer($db, $sql, $parametres)->fetchColumn();
    return $valeur === false ? null : $valeur;
}

function db_modifier(PDO $db, string $sql, array $parametres = []): int
{
    return db_executer($db, $sql, $parametres)->rowCount();
}

/** Insertion ; $table et les noms de colonnes viennent toujours du code, jamais d'une saisie. */
function db_inserer(PDO $db, string $table, array $valeurs): int
{
    $colonnes = array_keys($valeurs);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $colonnes) . ') VALUES ('
        . implode(', ', array_fill(0, count($colonnes), '?')) . ')';
    db_executer($db, $sql, array_values($valeurs));
    return (int)$db->lastInsertId();
}

function db_maj(PDO $db, string $table, array $valeurs, string $cle, $id): int
{
    $affectations = [];
    foreach (array_keys($valeurs) as $colonne) {
        $affectations[] = $colonne . ' = ?';
    }
    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $affectations) . ' WHERE ' . $cle . ' = ?';
    return db_modifier($db, $sql, array_merge(array_values($valeurs), [$id]));
}

function reglage_lire(PDO $db, string $cle, string $defaut = ''): string
{
    $valeur = db_valeur($db, 'SELECT valeur FROM reglages WHERE cle = ?', [$cle]);
    return is_string($valeur) ? $valeur : $defaut;
}

function reglage_ecrire(PDO $db, string $cle, string $valeur): void
{
    db_executer($db, 'INSERT INTO reglages (cle, valeur) VALUES (?, ?) '
        . 'ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur', [$cle, $valeur]);
}

/**
 * Compteur par IP, point et fenetre fixe ; true si la limite est depassee.
 * Les fenetres anciennes sont purgees de temps en temps.
 */
function limite_depassee(PDO $db, string $ip, string $point, int $max, int $duree, int $maintenant): bool
{
    $fenetre = intdiv($maintenant, $duree) * $duree;
    db_executer($db, 'INSERT INTO limites (ip, point, fenetre, compte) VALUES (?, ?, ?, 1) '
        . 'ON CONFLICT(ip, point, fenetre) DO UPDATE SET compte = compte + 1', [$ip, $point, $fenetre]);
    $compte = (int)db_valeur($db, 'SELECT compte FROM limites WHERE ip = ? AND point = ? AND fenetre = ?',
        [$ip, $point, $fenetre]);
    if (random_int(1, 50) === 1) {
        db_executer($db, 'DELETE FROM limites WHERE fenetre < ?', [$maintenant - 3 * JOUR]);
    }
    return $compte > $max;
}
