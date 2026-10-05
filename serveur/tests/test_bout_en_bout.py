# test_bout_en_bout.py
# Role : test de bout en bout du serveur PHP (php -S sur 127.0.0.1) et du module
#        client Python : installation par install.php, activation, demande
#        acceptee ou refusee, revocation, signature Ed25519 PHP verifiee en Python.
#        Le serveur et le client partagent l'heure du systeme ; aucune
#        verification ne depend de sa valeur. Aucun reseau hors boucle locale.
#        Lancement : python3 serveur/tests/test_bout_en_bout.py
# ETDEL (c) 2026

import hashlib
import json
import os
import random
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ICI = os.path.dirname(os.path.abspath(__file__))
SERVEUR = os.path.dirname(ICI)
sys.path.insert(0, os.path.join(os.path.dirname(SERVEUR), "client"))

import etdel_licence as L  # noqa: E402

failures = []
_nb = [0]
JETON = "jeton-de-test-bout-en-bout-2026"
MOT_DE_PASSE = "mot de passe de test tres long"
ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def check(nom, condition):
    _nb[0] += 1
    if not condition:
        failures.append(nom)
        print("ECHEC : %s" % nom)


def port_libre():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def http(url, donnees=None, methode=None):
    corps = urllib.parse.urlencode(donnees).encode() if donnees is not None else None
    requete = urllib.request.Request(url, data=corps, method=methode)
    try:
        with urllib.request.urlopen(requete, timeout=10) as reponse:
            return reponse.status, reponse.read().decode("utf-8")
    except urllib.error.HTTPError as erreur:
        return erreur.code, erreur.read().decode("utf-8", "replace")


def generer_cle():
    alea = random.SystemRandom()
    valeurs = [alea.randrange(32) for _ in range(15)]
    brut = "".join(ALPHABET[v] for v in valeurs) + ALPHABET[sum(valeurs) % 32]
    return "ETDEL-" + "-".join(brut[i:i + 4] for i in range(0, 16, 4))


def php_cli(prive, code, *args):
    """Execute du PHP avec les bibliotheques du serveur ; renvoie le JSON affiche."""
    script = ("$prive = $argv[1]; require $prive . '/lib/api.php'; $config = config_charger($prive); "
              "$db = db_ouvrir($config['base']); " + code)
    sortie = subprocess.run(["php", "-r", script, prive] + [str(a) for a in args],
                            capture_output=True, text=True, timeout=30)
    if sortie.returncode != 0:
        raise RuntimeError(sortie.stderr or sortie.stdout)
    return json.loads(sortie.stdout) if sortie.stdout.strip() else None


def garde(url, publique, dossier, machine, poste="PC-BANC"):
    return L.Garde("DEMO", "DEMO-BANC", "1.0.0", _urls=[url], _cle_publique=publique,
                   _dossiers=[os.path.join(dossier, "a"), os.path.join(dossier, "b")],
                   _machine=machine, _poste=poste, _fil=False)


def main():
    if shutil.which("php") is None:
        check("php disponible", False)
        return
    racine = tempfile.mkdtemp(prefix="etdel_e2e_")
    processus = None
    try:
        www = os.path.join(racine, "www")
        prive = os.path.join(racine, "prive")
        shutil.copytree(os.path.join(SERVEUR, "www"), www)
        shutil.copytree(os.path.join(SERVEUR, "prive"), prive,
                        ignore=shutil.ignore_patterns("config.php", ".htpasswd", "cles", "install.verrou"))
        with open(os.path.join(prive, "config.php"), "w") as f:
            f.write("<?php\nreturn ['jeton_installation' => '%s'];\n" % JETON)
        port = port_libre()
        base = "http://127.0.0.1:%d/" % port
        # sendmail_path=/bin/false : l'echec d'envoi est volontaire et doit etre journalise.
        processus = subprocess.Popen(["php", "-d", "sendmail_path=/bin/false", "-S", "127.0.0.1:%d" % port,
                                      "-t", www], cwd=www, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                socket.create_connection(("127.0.0.1", port), timeout=0.2).close()
                break
            except OSError:
                time.sleep(0.05)

        # Installation depuis le navigateur.
        code, page = http(base + "install.php")
        check("install.php : formulaire", code == 200 and "Parametres" in page)
        check("install.php : URL proposee", ("http://127.0.0.1:%d/api/v1/" % port) in page)
        url = base + "api/v1/"
        champs = {"jeton": "mauvais", "utilisateur": "etienne", "mot_de_passe": MOT_DE_PASSE,
                  "confirmation": MOT_DE_PASSE, "url": url, "url_secours": "",
                  "email_notification": "etienne@exemple.invalid"}
        code, page = http(base + "install.php", champs)
        check("install.php : mauvais jeton refuse", "Jeton d&#039;installation incorrect" in page)
        check("install.php : rien cree avec un mauvais jeton", not os.path.exists(os.path.join(racine, "data")))
        champs["jeton"] = JETON
        code, page = http(base + "install.php", champs)
        check("install.php : installation terminee", code == 200 and "Installation terminee" in page)
        trouve = re.search(r'<code id="cle">([A-Za-z0-9_-]{43})</code>', page)
        check("install.php : cle publique affichee", trouve is not None)
        publique = trouve.group(1) if trouve else ""
        check("install.php : lignes pour etdel_licence.py",
              'LICENCE_URL = &quot;%s&quot;' % url in page and "LICENCE_CLE_PUBLIQUE" in page)
        code, page = http(base + "install.php")
        check("install.php : seconde execution refusee (GET)", code == 403 and "deja effectuee" in page)
        code, page = http(base + "install.php", champs)
        check("install.php : seconde execution refusee (POST)", code == 403)
        with open(os.path.join(www, "admin", ".htaccess")) as f:
            check("install.php : console protegee", "AuthType Basic" in f.read())
        code, _page = http(url)
        check("API : GET refuse (405)", code == 405)

        # Donnees : produit DEMO, distribution DEMO-BANC, une cle.
        chemin_base = os.path.join(racine, "data", "licenses.db")
        maintenant = int(time.time())
        db = sqlite3.connect(chemin_base)
        db.execute("INSERT INTO produits (code, nom, cree_le) VALUES ('DEMO', 'Demo', ?)", (maintenant,))
        db.execute("INSERT INTO distributions (produit_id, code, libelle, options, duree_defaut_j, cree_le) "
                   "VALUES (1, 'DEMO-BANC', 'Banc', '[\"export_pdf\"]', 365, ?)", (maintenant,))
        cle = generer_cle()
        db.execute("INSERT INTO licences (distribution_id, cle_hash, cle_indice, titulaire, echeance, origine, "
                   "cree_le, modifie_le) VALUES (1, ?, ?, 'Armement Banc', ?, 'console', ?, ?)",
                   (hashlib.sha256(cle.encode()).hexdigest(), cle[-4:], maintenant + 365 * 86400, maintenant, maintenant))
        db.commit()

        # Activation reelle : HTTP, signature PHP verifiee par l'Ed25519 Python.
        machine_a = hashlib.sha256(b"banc A").hexdigest()
        ga = garde(url, publique, os.path.join(racine, "client_a"), machine_a)
        r = ga.activer(cle)
        e = ga.etat()
        check("bout en bout : activation", r["ok"] and e["statut"] == L.VALIDE)
        check("bout en bout : 365 jours", e["jours_restants"] in (364, 365))
        check("bout en bout : option", ga.option("export_pdf") and not ga.option("multi_navire"))
        lic = db.execute("SELECT machine, id_poste, nom_ordinateur FROM licences").fetchone()
        check("bout en bout : id_poste identique PHP / Python", lic == (machine_a, e["id_poste"], "PC-BANC"))
        check("bout en bout : liste d'URL signee adoptee", ga._local["urls"] == [url])
        check("bout en bout : valider", ga._controler() is True and ga.etat()["statut"] == L.VALIDE)
        check("bout en bout : cle sur un autre poste",
              garde(url, publique, os.path.join(racine, "client_x"), hashlib.sha256(b"x").hexdigest())
              .activer(cle)["code"] == "cle_liee_autre_poste")
        mauvaise = L._b64url(L._ed25519_cle_publique(b"\x01" * 32))
        check("bout en bout : cle publique differente = injoignable",
              garde(url, mauvaise, os.path.join(racine, "client_y"), machine_a).activer(cle)["code"] == "injoignable")

        # Demande acceptee : la cle arrive sans saisie.
        gb = garde(url, publique, os.path.join(racine, "client_b"), hashlib.sha256(b"banc B").hexdigest(), "PC-B")
        r = gb.demander("Armement B", "b@exemple.invalid", "Bonjour")
        check("bout en bout : demande envoyee avec essai", r["ok"] and gb.etat()["statut"] == L.ESSAI)
        check("bout en bout : 15 jours d'essai", gb.etat()["jours_restants"] == 15)
        check("bout en bout : echec d'e-mail journalise sans bloquer",
              db.execute("SELECT COUNT(*) FROM journal WHERE action = 'email_echec'").fetchone()[0] == 1)
        numero = gb.etat()["demande"]
        php_cli(prive, "echo json_encode(demande_accepter($db, $config, (int)$argv[2], 90, 'Armement B', null, "
                       "'test', '127.0.0.1', time()));", numero)
        check("bout en bout : acceptation recue", gb._controler() is True)
        e = gb.etat()
        check("bout en bout : cle recue sans saisie, 90 jours", e["statut"] == L.VALIDE and e["jours_restants"] == 90)
        check("bout en bout : cle effacee du serveur",
              db.execute("SELECT cle_chiffree FROM demandes WHERE id = ?", (numero,)).fetchone()[0] is None)

        # Demande refusee avec motif accentue.
        gc = garde(url, publique, os.path.join(racine, "client_c"), hashlib.sha256(b"banc C").hexdigest(), "PC-C")
        gc.demander("Armement C")
        motif = "Pi" + chr(0xE8) + "ce manquante"
        php_cli(prive, "demande_refuser($db, (int)$argv[2], $argv[3], 'test', '', time());", gc.etat()["demande"], motif)
        gc._controler()
        check("bout en bout : refus avec motif", gc.etat()["message"] == "Demande refusee : Piece manquante")
        check("bout en bout : seconde demande sans essai",
              gc.demander("Armement C")["ok"] and gc.etat()["statut"] == L.DEMANDE_EN_ATTENTE)

        # Revocation depuis la base.
        db.execute("UPDATE licences SET statut = 'revoquee' WHERE cle_hash = ?", (hashlib.sha256(cle.encode()).hexdigest(),))
        db.commit()
        ga._controler()
        check("bout en bout : revocation a la connexion suivante", ga.etat()["statut"] == L.REVOQUEE)
        db.close()
    finally:
        if processus is not None:
            processus.terminate()
            processus.wait(timeout=10)
        shutil.rmtree(racine, ignore_errors=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        import traceback
        traceback.print_exc()
        check("exception %r" % (exc,), False)
    if failures:
        print("=== %d ECHEC(S) sur %d verifications (bout en bout) ===" % (len(failures), _nb[0]))
        for nom in failures:
            print(" - " + nom)
        sys.exit(1)
    print("=== TOUS LES TESTS PASSENT (bout en bout) === (%d verifications)" % _nb[0])
