<?php
// crypto.php - Cles de licence, identifiant de poste, signature Ed25519 (sodium),
//              chiffrement de la cle remise par une demande acceptee.
// ETDEL (c) 2026

declare(strict_types=1);

const ALPHABET_CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function b64url(string $octets): string
{
    return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
}

function deb64url(string $texte): ?string
{
    if (preg_match('/^[A-Za-z0-9_-]*$/', $texte) !== 1) {
        return null;
    }
    $octets = base64_decode(strtr($texte, '-_', '+/'), true);
    return $octets === false ? null : $octets;
}

/** ETDEL- + 15 caracteres aleatoires + somme de controle (somme des valeurs mod 32). */
function cle_generer(): string
{
    $valeurs = [];
    for ($i = 0; $i < 15; $i++) {
        $valeurs[] = random_int(0, 31);
    }
    $valeurs[] = array_sum($valeurs) % 32;
    $brut = '';
    foreach ($valeurs as $v) {
        $brut .= ALPHABET_CROCKFORD[$v];
    }
    return 'ETDEL-' . implode('-', str_split($brut, 4));
}

/** Forme canonique ETDEL-XXXX-XXXX-XXXX-XXXX, ou null (meme regle que le client). */
function cle_normaliser($texte): ?string
{
    if (!is_string($texte) || strlen($texte) > 64) {
        return null;
    }
    $brut = str_replace(['-', ' ', "\t", "\r", "\n"], '', strtoupper($texte));
    if (strlen($brut) === 21 && strncmp($brut, 'ETDEL', 5) === 0) {
        $brut = substr($brut, 5);
    }
    if (strlen($brut) !== 16) {
        return null;
    }
    $brut = strtr($brut, ['O' => '0', 'I' => '1', 'L' => '1']);
    $somme = 0;
    for ($i = 0; $i < 16; $i++) {
        $v = strpos(ALPHABET_CROCKFORD, $brut[$i]);
        if ($v === false) {
            return null;
        }
        if ($i < 15) {
            $somme += $v;
        }
    }
    if (ALPHABET_CROCKFORD[$somme % 32] !== $brut[15]) {
        return null;
    }
    return 'ETDEL-' . implode('-', str_split($brut, 4));
}

/** Seul le SHA-256 de la cle est stocke. */
function cle_hash(string $cle): string
{
    return hash('sha256', $cle);
}

/** 40 premiers bits de SHA-256(machine + produit), base32 de Crockford, XXXX-XXXX. */
function id_poste(string $machine, string $produit): string
{
    $h = hash('sha256', $machine . $produit, true);
    $n = 0;
    for ($i = 0; $i < 5; $i++) {
        $n = ($n << 8) | ord($h[$i]);
    }
    $s = '';
    for ($i = 0; $i < 8; $i++) {
        $s .= ALPHABET_CROCKFORD[($n >> (35 - 5 * $i)) & 31];
    }
    return substr($s, 0, 4) . '-' . substr($s, 4);
}

function fichier_secret_ecrire(string $chemin, string $contenu): void
{
    $dossier = dirname($chemin);
    if (!is_dir($dossier) && !mkdir($dossier, 0700, true) && !is_dir($dossier)) {
        throw new RuntimeException('dossier des cles impossible a creer');
    }
    $temporaire = $chemin . '.tmp';
    $ancien = umask(0077);
    try {
        if (file_put_contents($temporaire, $contenu, LOCK_EX) === false) {
            throw new RuntimeException('ecriture impossible : ' . basename($chemin));
        }
    } finally {
        umask($ancien);
    }
    @chmod($temporaire, 0600);
    if (!rename($temporaire, $chemin)) {
        throw new RuntimeException('ecriture impossible : ' . basename($chemin));
    }
}

function signature_fichier(array $config, int $kid): string
{
    return rtrim((string)$config['cles'], '/') . '/signature_' . $kid . '.key';
}

/** Nouvelle paire Ed25519 : cle privee dans prive/cles/, cle publique en base. */
function signature_creer(PDO $db, array $config, int $kid, ?string $bulletin, int $maintenant): array
{
    $paire = sodium_crypto_sign_keypair();
    $privee = sodium_crypto_sign_secretkey($paire);
    $publique = b64url(sodium_crypto_sign_publickey($paire));
    fichier_secret_ecrire(signature_fichier($config, $kid), base64_encode($privee));
    db_inserer($db, 'cles_signature', [
        'kid' => $kid, 'cle_publique' => $publique, 'bulletin' => $bulletin,
        'active_depuis' => $maintenant, 'retiree_le' => null,
    ]);
    sodium_memzero($paire);
    return ['kid' => $kid, 'cle_publique' => $publique, 'privee' => $privee];
}

/** Cle de signature courante : la plus recente non retiree. */
function signature_active(PDO $db, array $config): array
{
    $ligne = db_ligne($db, 'SELECT kid, cle_publique FROM cles_signature WHERE retiree_le IS NULL '
        . 'ORDER BY kid DESC LIMIT 1');
    if ($ligne === null) {
        throw new RuntimeException('aucune cle de signature');
    }
    $kid = (int)$ligne['kid'];
    $contenu = @file_get_contents(signature_fichier($config, $kid));
    $privee = is_string($contenu) ? base64_decode(trim($contenu), true) : false;
    if (!is_string($privee) || strlen($privee) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('cle privee de signature illisible (kid ' . $kid . ')');
    }
    return ['kid' => $kid, 'cle_publique' => $ligne['cle_publique'], 'privee' => $privee];
}

/**
 * Enveloppe signee : le client verifie les octets de payload tels que recus,
 * sans re-serialisation (aucun JSON canonique a maintenir entre PHP et Python).
 */
function signer(array $payload, array $cle): array
{
    $octets = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return [
        'payload' => b64url($octets),
        'sig' => b64url(sodium_crypto_sign_detached($octets, $cle['privee'])),
        'kid' => $cle['kid'],
    ];
}

function secret_fichier(array $config): string
{
    return rtrim((string)$config['cles'], '/') . '/secret_demandes.key';
}

/** Secret secretbox des cles remises par les demandes (config.php peut le fournir). */
function secret_demandes(array $config): string
{
    if (isset($config['secret_demandes']) && is_string($config['secret_demandes'])
        && $config['secret_demandes'] !== '') {
        $secret = base64_decode($config['secret_demandes'], true);
    } else {
        $contenu = @file_get_contents(secret_fichier($config));
        $secret = is_string($contenu) ? base64_decode(trim($contenu), true) : false;
    }
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('secret des demandes illisible');
    }
    return $secret;
}

function secret_demandes_creer(array $config): void
{
    fichier_secret_ecrire(secret_fichier($config), base64_encode(sodium_crypto_secretbox_keygen()));
}

function cle_chiffrer(string $cle, string $secret): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return b64url($nonce . sodium_crypto_secretbox($cle, $nonce, $secret));
}

function cle_dechiffrer(string $chiffre, string $secret): ?string
{
    $octets = deb64url($chiffre);
    if ($octets === null || strlen($octets) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $clair = sodium_crypto_secretbox_open(substr($octets, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($octets, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $secret);
    return $clair === false ? null : $clair;
}
