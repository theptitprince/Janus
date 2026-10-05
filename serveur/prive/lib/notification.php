<?php
// notification.php - E-mails a l'administrateur (nouvelle demande, e-mail de test).
//                    Un echec est journalise et ne bloque jamais l'enregistrement.
// ETDEL (c) 2026

declare(strict_types=1);

require_once __DIR__ . '/commun.php';

/** Adresses valides du reglage email_notification (separees par des virgules). */
function notification_destinataires(PDO $db): array
{
    $adresses = [];
    foreach (explode(',', reglage_lire($db, 'email_notification')) as $adresse) {
        $adresse = trim($adresse);
        if ($adresse !== '' && filter_var($adresse, FILTER_VALIDATE_EMAIL) !== false) {
            $adresses[] = $adresse;
        }
    }
    return $adresses;
}

function notification_expediteur(PDO $db): string
{
    $expediteur = trim(reglage_lire($db, 'email_expediteur'));
    // Expediteur sur le domaine du serveur : limite le classement en indesirable.
    return filter_var($expediteur, FILTER_VALIDATE_EMAIL) !== false
        ? $expediteur : 'licences@' . domaine_serveur($db);
}

/** Envoi en texte brut ; renvoie ['ok' => bool, 'message' => str]. */
function notification_envoyer(PDO $db, array $config, string $sujet, string $corps, int $maintenant): array
{
    $destinataires = notification_destinataires($db);
    if ($destinataires === []) {
        return ['ok' => false, 'message' => 'aucune adresse de notification'];
    }
    // Les champs viennent en partie du client : aucun saut de ligne dans les en-tetes.
    $sujet = tronquer_utf8(nettoyer_texte($sujet), 200);
    if (limite_depassee($db, 'serveur', 'email', (int)$config['emails_par_jour'], JOUR, $maintenant)) {
        journal_ecrire($db, 'systeme', 'email_plafond', null, $sujet, null, $maintenant);
        return ['ok' => false, 'message' => 'plafond quotidien d\'e-mails atteint'];
    }
    $expediteur = notification_expediteur($db);
    $entetes = implode("\r\n", [
        'From: ETDEL Licences <' . $expediteur . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $sujet_code = preg_match('/^[\x20-\x7E]*$/', $sujet) === 1 ? $sujet : '=?UTF-8?B?' . base64_encode($sujet) . '?=';
    $a = implode(', ', $destinataires);
    $erreur = '';
    try {
        $mailer = $config['mailer'] ?? null;
        if (is_callable($mailer)) {
            $ok = (bool)$mailer($a, $sujet_code, $corps, $entetes);
        } else {
            $ok = @mail($a, $sujet_code, $corps, $entetes);
            if (!$ok) {
                $derniere = error_get_last();
                $erreur = is_array($derniere) ? (string)$derniere['message'] : '';
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        $erreur = $e->getMessage();
    }
    journal_ecrire($db, 'systeme', $ok ? 'email_envoye' : 'email_echec', $a,
        $sujet . ($erreur !== '' ? ' : ' . $erreur : ''), null, $maintenant);
    return ['ok' => $ok, 'message' => $ok ? 'e-mail envoye a ' . $a : 'echec de l\'envoi' . ($erreur !== '' ? ' : ' . $erreur : '')];
}

function notification_nouvelle_demande(PDO $db, array $config, array $demande, array $dist, int $maintenant): void
{
    try {
        $lien = url_console($db, $config);
        $lien = $lien === '' ? '(adresse de la console inconnue)' : $lien . 'index.php?page=demande&id=' . $demande['id'];
        $essai = $demande['essai_jusqu'] === null ? 'non' : 'oui, jusqu\'au ' . date_fr((int)$demande['essai_jusqu']);
        $corps = implode("\n", [
            'Nouvelle demande de licence n. ' . $demande['id'],
            '',
            'Produit        : ' . $dist['produit_code'] . ' (' . $dist['produit_nom'] . ')',
            'Distribution   : ' . $dist['code'] . ' (' . $dist['libelle'] . ')',
            'Ordinateur     : ' . $demande['nom_ordinateur'],
            'Identifiant    : ' . $demande['id_poste'],
            'Titulaire      : ' . $demande['titulaire'],
            'E-mail client  : ' . ($demande['email'] ?? '-'),
            'Mot du client  : ' . ($demande['message'] ?? '-'),
            'Essai accorde  : ' . $essai,
            'Date           : ' . date_fr((int)$demande['cree_le'], true),
            'IP             : ' . $demande['ip'],
            '',
            'Traiter la demande : ' . $lien,
            '',
        ]);
        $sujet = '[ETDEL Licences] Nouvelle demande - ' . $dist['produit_code'] . ' - '
            . ($demande['nom_ordinateur'] !== '' ? $demande['nom_ordinateur'] : $demande['id_poste']);
        notification_envoyer($db, $config, $sujet, $corps, $maintenant);
    } catch (Throwable $e) {
        error_log('etdel notification : ' . $e->getMessage());
    }
}
