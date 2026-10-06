# test_licence_integration.py
# Role : test generique de l'integration du module etdel_licence dans une
#        application ETDEL. A copier tel quel a cote de etdel_licence.py, puis
#        a adapter dans la seule section "A ADAPTER" ci-dessous.
#        Verifie : copie du module identique a la reference (empreinte),
#        constantes vides = demarrage normal sans fichier ni message,
#        bandeau visible en AVERTISSEMENT (fausse horloge), fermeture propre
#        en EXPIREE. Aucun reseau reel (faux serveur), aucune heure reelle.
#        Lancement : python test_licence_integration.py  (Linux : xvfb-run -a)
# ETDEL (c) 2026

import hashlib
import json
import os
import shutil
import sys
import tempfile
import time

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

import etdel_licence  # noqa: E402

# --- Reference publiee du module (docs/INTEGRATION.md du depot Janus) --------
VERSION_REFERENCE = "1.1.0"
EMPREINTE_REFERENCE = "bbee20f18a76c94072e72c623a948c3319e04a305c446df3b69aaf3d01751139"

# --- A ADAPTER pour chaque application ---------------------------------------
PRODUIT = "MONAPPLI"
DISTRIBUTION = "MONAPPLI-CLIENTA"


def creer_application():
    """Construit l'application comme au lancement, sans mainloop, et renvoie sa
    fenetre principale (tk.Tk). Remplacer le corps par la construction reelle,
    par exemple : import monappli ; return monappli.Application().root"""
    import tkinter as tk
    root = tk.Tk()
    etdel_licence.installer(root, produit=PRODUIT, distribution=DISTRIBUTION, version="1.0.0")
    return root
# ------------------------------------------------------------------------------

failures = []
_nb = [0]
JOUR = 86400
URL = "https://licence.integration.invalid/api/v1/"
GRAINE = hashlib.sha256(b"etdel test integration").digest()


def check(nom, condition):
    _nb[0] += 1
    if not condition:
        failures.append(nom)
        print("ECHEC : %s" % nom)


def empreinte(chemin):
    # Fins de ligne normalisees : une extraction Git en CRLF ne change pas l'empreinte.
    with open(chemin, "rb") as fichier:
        return hashlib.sha256(fichier.read().replace(b"\r\n", b"\n")).hexdigest()


class Horloge(object):
    def __init__(self):
        self.t = 1791200000

    def __call__(self):
        return self.t


class FauxServeur(object):
    """Repond a activer et valider avec un jeton signe ; peut devenir injoignable."""

    def __init__(self, horloge):
        self.horloge = horloge
        self.injoignable = False
        self.appels = 0

    def transport(self, url, corps, delai):
        self.appels += 1
        if self.injoignable:
            raise OSError("serveur injoignable (test)")
        r = json.loads(corps.decode("utf-8"))
        maintenant = self.horloge()
        payload = {"v": 1, "ok": True, "code": None, "nonce": r["nonce"], "produit": r["produit"],
                   "distribution": r["distribution"], "machine": r["machine"], "id_poste": "TEST-TEST",
                   "titulaire": "Test integration", "echeance": None, "jours_restants": None,
                   "emis": maintenant, "hors_ligne_jusqu": maintenant + 15 * JOUR, "preavis_j": 5,
                   "options": [], "version_min": None, "message": None, "urls": [URL], "bulletins": []}
        brut = json.dumps(payload).encode("utf-8")
        return 200, json.dumps({"payload": etdel_licence._b64url(brut), "kid": 1, "sig": etdel_licence._b64url(
            etdel_licence._ed25519_signer(GRAINE, brut))}).encode("utf-8")


class Environnement(object):
    """Dossiers temporaires, constantes et fabrique de Garde remplacees le temps d'un test."""

    def __init__(self, configure):
        self.dossier = tempfile.mkdtemp(prefix="etdel_integration_")
        self.horloge = Horloge()
        self.serveur = FauxServeur(self.horloge)
        self.messages = []
        self.gardes = []
        self.sauvegarde = (etdel_licence.LICENCE_URL, etdel_licence.LICENCE_URL_SECOURS,
                           etdel_licence.LICENCE_CLE_PUBLIQUE, etdel_licence.Garde,
                           etdel_licence._afficher_message, dict(os.environ))
        for variable in ("APPDATA", "LOCALAPPDATA", "PROGRAMDATA", "HOME", "USERPROFILE"):
            os.environ[variable] = os.path.join(self.dossier, variable)
        etdel_licence._afficher_message = lambda parent, titre, texte: self.messages.append(texte)
        if configure:
            etdel_licence.LICENCE_URL = URL
            etdel_licence.LICENCE_URL_SECOURS = ""
            etdel_licence.LICENCE_CLE_PUBLIQUE = etdel_licence._b64url(etdel_licence._ed25519_cle_publique(GRAINE))
            classe = self.sauvegarde[3]

            def fabrique(produit, distribution, version, **options):
                options.update(_horloge=self.horloge, _transport=self.serveur.transport, _fil=False,
                               _monotone=lambda: 0.0)
                garde = classe(produit, distribution, version, **options)
                self.gardes.append(garde)
                return garde

            etdel_licence.Garde = fabrique
        else:
            etdel_licence.LICENCE_URL = etdel_licence.LICENCE_URL_SECOURS = etdel_licence.LICENCE_CLE_PUBLIQUE = ""

    def fichiers(self):
        trouves = []
        for racine, _dossiers, noms in os.walk(self.dossier):
            trouves.extend(os.path.join(racine, n) for n in noms)
        return trouves

    def fermer(self):
        (etdel_licence.LICENCE_URL, etdel_licence.LICENCE_URL_SECOURS, etdel_licence.LICENCE_CLE_PUBLIQUE,
         etdel_licence.Garde, etdel_licence._afficher_message, environ) = self.sauvegarde
        os.environ.clear()
        os.environ.update(environ)
        shutil.rmtree(self.dossier, ignore_errors=True)


def pomper(root, secondes=0.3):
    import tkinter as tk
    fin = time.time() + secondes
    while time.time() < fin:
        try:
            root.update()
        except tk.TclError:
            return
        time.sleep(0.01)


def detruire(root):
    try:
        root.destroy()
    except Exception:
        pass


def fenetres_licence(root):
    import tkinter as tk
    return [w for w in root.winfo_children() if isinstance(w, tk.Toplevel)
            and w.title().startswith("Licence")]


def test_empreinte():
    check("module : version %s" % VERSION_REFERENCE, etdel_licence.MODULE_VERSION == VERSION_REFERENCE)
    check("module : copie identique a la reference (SHA-256)",
          empreinte(os.path.join(ICI, "etdel_licence.py")) == EMPREINTE_REFERENCE)


def test_constantes_vides():
    env = Environnement(configure=False)
    root = None
    try:
        debut = time.perf_counter()
        root = creer_application()
        duree = time.perf_counter() - debut
        pomper(root)
        check("constantes vides : fenetre principale visible", root.state() == "normal")
        check("constantes vides : aucune fenetre de licence", fenetres_licence(root) == [])
        check("constantes vides : aucun message", env.messages == [])
        check("constantes vides : aucun fichier cree", env.fichiers() == [])
        check("constantes vides : aucun raccourci de licence", root.bind_all("<Control-Shift-KeyPress-L>") == "")
        print("(construction de l'application : %.0f ms)" % (duree * 1000))
    finally:
        if root is not None:
            detruire(root)
        env.fermer()


def preparer_licence(env):
    """Active une licence sur le faux serveur, comme au premier lancement."""
    garde = etdel_licence.Garde(PRODUIT, DISTRIBUTION, "1.0.0")
    cle = "ETDEL-0000-0000-0000-0000"
    resultat = garde.activer(cle)
    garde.arreter()
    return resultat["ok"]


def test_avertissement_et_expiration():
    env = Environnement(configure=True)
    root = None
    try:
        check("licence de test activee", preparer_licence(env))
        debut = time.perf_counter()
        root = creer_application()
        check("installer() rend la main vite (demarrage non ralenti)", time.perf_counter() - debut < 5)
        pomper(root)
        garde = env.gardes[-1]
        integration = garde._integration
        check("licence valide : application visible, aucune fenetre", root.state() == "normal"
              and fenetres_licence(root) == [] and env.messages == [])
        check("licence valide : pas de bandeau", integration.bandeau is None or not integration.bandeau.winfo_ismapped())
        env.serveur.injoignable = True
        env.horloge.t += 11 * JOUR
        integration._tick()
        pomper(root)
        check("AVERTISSEMENT : statut", garde.etat()["statut"] == etdel_licence.AVERTISSEMENT)
        check("AVERTISSEMENT : bandeau visible", integration.bandeau is not None and integration.bandeau.winfo_ismapped())
        check("AVERTISSEMENT : texte du bandeau", "connectez l'ordinateur" in integration.bandeau.cget("text"))
        env.horloge.t += 5 * JOUR
        integration._tick()
        pomper(root)
        check("EXPIREE : un message", len(env.messages) == 1)
        check("EXPIREE : application fermee", integration.termine)
        check("EXPIREE : heure atteinte gravee (arreter)", garde._arrete)
        root = None
    finally:
        if root is not None:
            detruire(root)
        env.fermer()


def main():
    test_empreinte()
    try:
        import tkinter
        tkinter.Tk().destroy()
    except Exception as exc:
        check("Tkinter indisponible (%r) : lancer sous Windows ou xvfb-run" % (exc,), False)
    else:
        for test in (test_constantes_vides, test_avertissement_et_expiration):
            try:
                test()
            except Exception as exc:
                import traceback
                traceback.print_exc()
                check("%s : exception %r" % (test.__name__, exc), False)
    if failures:
        print("=== %d ECHEC(S) sur %d verifications (integration licence) ===" % (len(failures), _nb[0]))
        for nom in failures:
            print(" - " + nom)
        sys.exit(1)
    print("=== TOUS LES TESTS PASSENT (integration licence) === (%d verifications)" % _nb[0])


if __name__ == "__main__":
    main()
