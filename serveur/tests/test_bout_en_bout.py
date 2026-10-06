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
import http.cookiejar as cookiejar  # le nom http est pris par la fonction http() ci-dessous
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


class Console:
    """Console par HTTP comme un navigateur : authentification Basic, cookie de session, jeton CSRF."""

    def __init__(self, base, mot_de_passe):
        self.base = base
        self.autorisation = "Basic " + base64.b64encode(("admin:%s" % mot_de_passe).encode()).decode()
        self.ouvreur = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookiejar.CookieJar()))

    def requete(self, chemin, donnees=None):
        corps = urllib.parse.urlencode(donnees).encode() if donnees is not None else None
        requete = urllib.request.Request(self.base + chemin, data=corps, headers={
            "Authorization": self.autorisation, "Origin": self.base.rstrip("/")})
        try:
            with self.ouvreur.open(requete, timeout=10) as reponse:
                return reponse.status, reponse.geturl(), reponse.read().decode("utf-8")
        except urllib.error.HTTPError as erreur:
            return erreur.code, erreur.geturl(), erreur.read().decode("utf-8", "replace")

    def ecrire(self, page, action, champs):
        """Ouvre la page (jeton CSRF de son formulaire), puis envoie l'action confirmee."""
        _code, _url, html = self.requete("admin/index.php?" + page)
        trouve = re.search(r'name="csrf" value="([^"]+)"', html)
        donnees = dict(champs, action=action, csrf=trouve.group(1) if trouve else "", confirme="1")
        return self.requete("admin/index.php", donnees)


def cle_affichee(page):
    trouve = re.search(r'<code id="cle">(ETDEL(?:-[0-9A-Z]{4}){4})</code>', page)
    return trouve.group(1) if trouve else None


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
                   # Journal dans le dossier du test, jamais dans le %LOCALAPPDATA% reel.
                   _dossier_journal=os.path.join(dossier, "a"),
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
        boutons(root)["Fenetre Licence"].invoke()
        pomper(0.5)
        check("demo : bouton Fenetre Licence", integration.fenetre_licence is not None)
        check("demo : bouton de remise a zero de la licence locale",
              "Supprimer la licence de ce poste (essais)" in boutons(root))
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
        # Disposition du depot : prive/ (et sa base prive/data/) dans le dossier servi.
        www = os.path.join(racine, "www")
        prive = os.path.join(www, "prive")
        shutil.copytree(os.path.join(SERVEUR, "www"), www,
                        ignore=shutil.ignore_patterns("config.php", ".htpasswd", "cles", "data", "install.verrou",
                                                      "reinitialisation.utilisee"))
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
        check("install.php : rien cree avec un mauvais jeton", not os.path.exists(os.path.join(prive, "data")))
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

        # Annexe A : prive/ dans le dossier servi, rien n'y est telechargeable
        # (le routeur du banc reproduit les .htaccess, variantes Windows comprises).
        for chemin in ("prive/data/licenses.db", "prive/config.php", "prive/.htpasswd", "prive/cles/signature_1.key",
                       "prive/cles/signature_1.json", "prive/cles/secret_demandes.key", "prive/schema.sql",
                       "prive/lib/commun.php", "prive/data/", "PRIVE/Data/Licenses.DB", "prive./config.php",
                       "pr%69ve/config.php", "pr%2569ve/config.php", "prive%2fconfig.php", "admin/../prive/config.php"):
            code, _page = http(base + chemin)
            check("HTTP 403 sur %s" % chemin, code == 403)

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
        check("console : accueil par HTTP", code == 200 and "<h1>Accueil</h1>" in page)
        check("console : menu reduit (Distributions, Administration)", 'href="index.php?page=administration"' in page
              and 'href="index.php?page=distributions"' in page and 'href="index.php?page=serveurs"' not in page
              and 'href="index.php?page=produits"' not in page)
        check("console : accueil, boutons Nouvelle licence et Nouvelle distribution",
              'href="index.php?page=licence_nouvelle">Nouvelle licence</a>' in page
              and 'href="index.php?page=distribution">Nouvelle distribution</a>' in page)
        check("console : en-tetes CSP et X-Frame-Options", "default-src 'none'" in entetes.get("Content-Security-Policy", "")
              and entetes.get("X-Frame-Options") == "DENY")
        check("console : cookie de session HttpOnly SameSite=Strict",
              "HttpOnly" in entetes.get("Set-Cookie", "") and "SameSite=Strict" in entetes.get("Set-Cookie", ""))
        code, _entetes, page = console("admin/index.php?page=cles", MOT_DE_PASSE)
        check("console : ecran Cles", code == 200 and publique in page)
        code, _entetes, page = console("admin/index.php?page=administration", MOT_DE_PASSE)
        check("console : ecran Administration avec le controle d'exposition",
              code == 200 and "<h1>Administration</h1>" in page and 'id="exposition"' in page)

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

        # Donnees, comme au banc : Distributions > Nouvelle distribution, produit DEMO (cree avec elle,
        # pour le classement) et distribution DEMO-BANC ; puis une cle.
        adm = Console(base, nouveau)
        code, url_finale, page = adm.ecrire("page=distribution", "distribution_enregistrer", {
            "id": "0", "produit_id": "0", "produit_code": "DEMO", "produit_nom": "Demo", "code": "DEMO-BANC",
            "libelle": "Banc", "client": "", "duree_defaut_j": "365", "essai_j": "15", "options": "export_pdf",
            "tolerance_j": "15", "preavis_j": "5", "version_min": "", "message": "", "actif": "1"})
        check("console : nouvelle distribution DEMO-BANC et son produit DEMO (POST, CSRF, confirmation)",
              code == 200 and "ok=creee" in url_finale and "<h1>Distribution DEMO-BANC</h1>" in page
              and "installer(root, produit=&quot;DEMO&quot;, distribution=&quot;DEMO-BANC&quot;" in page)
        code, _url, page = adm.requete("admin/index.php?page=distributions")
        check("console : ecran Distributions", code == 200 and "<h2>DEMO - Demo</h2>" in page
              and "Licence 365 jours, essai 15 jours, options : export_pdf, hors ligne 15 jours" in page)
        chemin_base = os.path.join(prive, "data", "licenses.db")
        maintenant = int(time.time())
        db = sqlite3.connect(chemin_base)
        check("console : distribution enregistree en base", db.execute(
            "SELECT p.code, p.nom, d.id, d.code, d.options, d.duree_defaut_j, d.essai_j, d.tolerance_j, d.actif "
            "FROM distributions d JOIN produits p ON p.id = d.produit_id").fetchall()
              == [("DEMO", "Demo", 1, "DEMO-BANC", '["export_pdf"]', 365, 15, 15, 1)])
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

        # Nouvelle cle depuis la console (D67) : l'ancienne est refusee et effacee du poste qui l'utilisait,
        # la nouvelle s'active sur le premier ordinateur ou elle est saisie.
        code, _url, page = adm.ecrire("page=licence_nouvelle&distribution=1", "licence_creer", {
            "distribution_id": "1", "titulaire": "Armement D", "email": "", "note": "", "duree_j": "30"})
        cle_d = cle_affichee(page)
        check("console : nouvelle licence, cle affichee", code == 200 and cle_d is not None)
        gd = garde(url, publique, os.path.join(racine, "client_d"), hashlib.sha256(b"banc D").hexdigest(), "PC-D")
        check("nouvelle cle : premiere cle activee", cle_d is not None and gd.activer(cle_d)["ok"])
        id_d = db.execute("SELECT id FROM licences WHERE titulaire = 'Armement D'").fetchone()[0]
        code, _url, page = adm.ecrire("page=licence&id=%d" % id_d, "licence_nouvelle_cle", {"id": str(id_d)})
        nouvelle_d = cle_affichee(page)
        check("console : nouvelle cle affichee une fois", code == 200 and "<h1>Nouvelle cle</h1>" in page
              and nouvelle_d is not None and nouvelle_d != cle_d and page.count(nouvelle_d or "-") == 1)
        gd._controler()
        check("nouvelle cle : ancienne cle refusee et effacee du poste", gd._local.get("cle") is None
              and gd.etat()["statut"] == L.REVOQUEE)
        # Cas le plus courant (cle perdue) : le client ressaisit la nouvelle cle sur le meme ordinateur, comme la
        # console le lui annonce ; elle s'y lie, et un autre ordinateur ne peut plus l'utiliser.
        gd.arreter()
        relance_d = garde(url, publique, os.path.join(racine, "client_d"), hashlib.sha256(b"banc D").hexdigest(), "PC-D")
        check("nouvelle cle : ordinateur relance, licence a activer", relance_d.etat()["statut"] == L.A_ACTIVER)
        check("nouvelle cle : ressaisie sur le meme ordinateur", nouvelle_d is not None
              and relance_d.activer(nouvelle_d)["ok"] and relance_d.etat()["statut"] == L.VALIDE)
        ge = garde(url, publique, os.path.join(racine, "client_e"), hashlib.sha256(b"banc E").hexdigest(), "PC-E")
        check("nouvelle cle : refusee sur un autre ordinateur", nouvelle_d is not None
              and ge.activer(nouvelle_d)["code"] == "cle_liee_autre_poste")
        check("nouvelle cle : journalisee", db.execute("SELECT COUNT(*) FROM journal WHERE action = 'cle_remplacee' "
                                                      "AND acteur = 'admin'").fetchone()[0] == 1)

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
