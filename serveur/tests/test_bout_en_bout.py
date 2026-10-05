# test_bout_en_bout.py
# Role : test de bout en bout du serveur PHP (php -S sur 127.0.0.1) et du module
#        client Python : installation par install.php, activation, demande
#        acceptee ou refusee, revocation, signature Ed25519 PHP verifiee en Python.
#        Le serveur et le client partagent l'heure du systeme ; aucune
#        verification ne depend de sa valeur. Aucun reseau hors boucle locale.
#        L'application de demonstration est pilotee sous Xvfb.
#        Lancement : xvfb-run -a python3 serveur/tests/test_bout_en_bout.py
# ETDEL (c) 2026

import base64
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


def tests_demo(url, publique, db, maintenant, racine):
    """Application de demonstration reelle (Tkinter) contre le serveur PHP reel."""
    try:
        import tkinter as tk
        tk.Tk().destroy()
    except Exception as exc:
        if os.environ.get("ETDEL_TESTS_SANS_TK") == "1":
            print("(application de demonstration sautee a la demande : ETDEL_TESTS_SANS_TK=1)")
        else:
            check("Tkinter indisponible (%r) : lancer sous xvfb-run ou ETDEL_TESTS_SANS_TK=1" % (exc,), False)
        return
    import demo_appli
    cle = generer_cle()
    db.execute("INSERT INTO licences (distribution_id, cle_hash, cle_indice, titulaire, echeance, origine, cree_le, "
               "modifie_le) VALUES (1, ?, ?, 'Armement Demo', ?, 'console', ?, ?)",
               (hashlib.sha256(cle.encode()).hexdigest(), cle[-4:], maintenant + 30 * 86400, maintenant, maintenant))
    db.commit()
    environnement = dict(os.environ)
    constantes = (L.LICENCE_URL, L.LICENCE_URL_SECOURS, L.LICENCE_CLE_PUBLIQUE)
    for variable in ("APPDATA", "LOCALAPPDATA", "PROGRAMDATA", "HOME"):
        os.environ[variable] = os.path.join(racine, "demo", variable)
    root = None

    def pomper(secondes, condition=lambda: False):
        fin = time.time() + secondes
        while time.time() < fin and not condition():
            root.update()
            time.sleep(0.02)
        return condition()

    def boutons(fenetre):
        trouves, pile = {}, [fenetre]
        while pile:
            w = pile.pop()
            pile.extend(w.winfo_children())
            if isinstance(w, tk.Button):
                trouves[w.cget("text")] = w
        return trouves

    try:
        root, garde = demo_appli.construire(["--serveur", url, "--cle-publique", publique])
        pomper(0.5)
        integration = garde._integration
        check("demo : fenetre d'activation au premier lancement", integration.fenetre is not None
              and root.state() == "withdrawn")
        boutons(integration.fenetre.win)["J'ai une cle"].invoke()
        integration.fenetre.entree_cle.insert(0, cle.lower())
        boutons(integration.fenetre.win)["Activer"].invoke()
        check("demo : activation par la fenetre", pomper(15, lambda: integration.fenetre is None))
        check("demo : application affichee", root.state() == "normal" and garde.etat()["statut"] == L.VALIDE)
        pomper(1.5)
        check("demo : option export_pdf de la distribution",
              str(boutons(root)["Exporter en PDF (option export_pdf)"].cget("state")) == "normal")
        integration.ouvrir_licence()
        pomper(0.5)
        check("demo : fenetre Licence (Ctrl+Maj+L)", integration.fenetre_licence is not None)
        lignes = db.execute("SELECT nom_ordinateur, version_appli FROM licences WHERE cle_hash = ?",
                            (hashlib.sha256(cle.encode()).hexdigest(),)).fetchone()
        check("demo : poste et version vus par le serveur", lignes[1] == demo_appli.APP_VERSION and lignes[0])
        root.tk.eval(root.protocol("WM_DELETE_WINDOW"))
        check("demo : fermeture propre", integration.termine and garde._arrete)
        root = None
    finally:
        if root is not None:
            try:
                root.destroy()
            except Exception:
                pass
        L.LICENCE_URL, L.LICENCE_URL_SECOURS, L.LICENCE_CLE_PUBLIQUE = constantes
        os.environ.clear()
        os.environ.update(environnement)


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
        # L'echec d'envoi est volontaire et doit etre journalise. Sous Windows, PHP
        # passe par SMTP (port 9 ferme) ; sendmail_path y renverrait toujours succes.
        if sys.platform == "win32":
            sans_mail = ["-d", "SMTP=127.0.0.1", "-d", "smtp_port=9"]
        else:
            sans_mail = ["-d", "sendmail_path=/bin/false"]
        processus = subprocess.Popen(["php"] + sans_mail + ["-S", "127.0.0.1:%d" % port,
                                      "-t", www, os.path.join(ICI, "routeur_banc.php")],
                                     cwd=www, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
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
        champs = {"jeton": "mauvais", "utilisateur": "admin", "mot_de_passe": MOT_DE_PASSE,
                  "confirmation": MOT_DE_PASSE, "url": url, "url_secours": "",
                  "email_notification": "admin@exemple.invalid"}
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

        # Console par HTTP (authentification Basic reproduite par le routeur du banc).
        def console(chemin, mot_de_passe):
            jeton = base64.b64encode(("admin:%s" % mot_de_passe).encode()).decode()
            requete = urllib.request.Request(base + chemin, headers={"Authorization": "Basic " + jeton})
            try:
                with urllib.request.urlopen(requete, timeout=10) as reponse:
                    return reponse.status, dict(reponse.headers), reponse.read().decode("utf-8")
            except urllib.error.HTTPError as erreur:
                return erreur.code, dict(erreur.headers), ""
        code, entetes, _page = console("admin/", "mauvais mot de passe")
        check("console : mauvais mot de passe, 401", code == 401 and "Basic" in entetes.get("WWW-Authenticate", ""))
        code, entetes, page = console("admin/", MOT_DE_PASSE)
        check("console : tableau de bord par HTTP", code == 200 and "Tableau de bord" in page)
        check("console : en-tetes CSP et X-Frame-Options", "default-src 'none'" in entetes.get("Content-Security-Policy", "")
              and entetes.get("X-Frame-Options") == "DENY")
        check("console : cookie de session HttpOnly SameSite=Strict",
              "HttpOnly" in entetes.get("Set-Cookie", "") and "SameSite=Strict" in entetes.get("Set-Cookie", ""))
        code, _entetes, page = console("admin/index.php?page=cles", MOT_DE_PASSE)
        check("console : ecran Cles", code == 200 and publique in page)

        # Mot de passe perdu : jeton de reinitialisation depose dans config.php (acces FTP).
        with open(os.path.join(prive, "config.php"), "w") as f:
            f.write("<?php\nreturn ['jeton_installation' => '%s', 'jeton_reinitialisation' => "
                    "'jeton-de-reinitialisation-e2e'];\n" % JETON)
        code, page = http(base + "install.php")
        check("reinitialisation : formulaire ouvert par config.php", code == 200 and "Reinitialiser" in page)
        nouveau = "mot de passe retrouve par le banc"
        code, page = http(base + "install.php", {"jeton": "jeton-de-reinitialisation-e2e", "utilisateur": "admin",
                                                 "mot_de_passe": nouveau, "confirmation": nouveau})
        check("reinitialisation : effectuee", code == 200 and "Mot de passe reinitialise" in page)
        check("reinitialisation : ancien mot de passe refuse", console("admin/", MOT_DE_PASSE)[0] == 401)
        check("reinitialisation : nouveau mot de passe accepte", console("admin/", nouveau)[0] == 200)
        code, page = http(base + "install.php")
        check("reinitialisation : jeton a usage unique, assistant verrouille", code == 403)

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

        # Rotation de la cle de signature : bulletin signe par la cle precedente.
        rotation = php_cli(prive, "echo json_encode(signature_rotation($db, $config, time()));")
        check("rotation : kid 2", rotation["kid"] == 2)
        check("rotation : poste en service bascule sans mise a jour",
              gb._controler() is True and gb.etat()["kid_actif"] == 2 and gb.etat()["statut"] == L.VALIDE)
        gb.arreter()
        relance = garde(url, publique, os.path.join(racine, "client_b"), hashlib.sha256(b"banc B").hexdigest(), "PC-B")
        check("rotation : relance du poste", relance.etat()["statut"] == L.VALIDE and relance._controler() is True)
        cle_neuve = generer_cle()
        db.execute("INSERT INTO licences (distribution_id, cle_hash, cle_indice, titulaire, origine, cree_le, modifie_le) "
                   "VALUES (1, ?, ?, 'Poste neuf', 'console', ?, ?)",
                   (hashlib.sha256(cle_neuve.encode()).hexdigest(), cle_neuve[-4:], maintenant, maintenant))
        db.commit()
        neuf = garde(url, publique, os.path.join(racine, "client_n"), hashlib.sha256(b"banc N").hexdigest(), "PC-N")
        check("rotation : application livree avec l'ancienne cle, poste neuf",
              neuf.activer(cle_neuve)["ok"] and neuf.etat()["kid_actif"] == 2)
        check("rotation : la nouvelle cle embarquee fonctionne aussi",
              garde(url, rotation["cle_publique"], os.path.join(racine, "client_m"), hashlib.sha256(b"banc N").hexdigest())
              .activer(cle_neuve)["ok"])

        # Revocation depuis la base.
        db.execute("UPDATE licences SET statut = 'revoquee' WHERE cle_hash = ?", (hashlib.sha256(cle.encode()).hexdigest(),))
        db.commit()
        ga._controler()
        check("bout en bout : revocation a la connexion suivante", ga.etat()["statut"] == L.REVOQUEE)
        tests_demo(url, publique, db, maintenant, racine)
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
