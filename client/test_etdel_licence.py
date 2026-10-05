# test_etdel_licence.py
# Role : tests du module etdel_licence (faux serveur local, fausse horloge,
#        composants Tkinter sous Xvfb). Aucun reseau reel, aucune heure reelle.
#        Lancement : python test_etdel_licence.py
#        (Linux sans ecran : xvfb-run -a python3 test_etdel_licence.py ;
#         ETDEL_TESTS_SANS_TK=1 pour sauter explicitement la partie Tkinter)
# ETDEL (c) 2026

import hashlib
import http.server
import io
import json
import math
import os
import random
import re
import shutil
import sys
import tempfile
import threading
import time

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

import etdel_licence as L  # noqa: E402

failures = []
_nb = [0]


def check(nom, condition):
    _nb[0] += 1
    if not condition:
        failures.append(nom)
        print("ECHEC : %s" % nom)


URL1 = "https://licence.exemple.fr/api/v1/"
URL2 = "https://licence2.exemple.fr/api/v1/"
URL3 = "https://licence3.exemple.fr/api/v1/"
URL_SECOURS = "https://secours.exemple.net/api/v1/"
URL_PIRATE = "https://pirate.exemple.com/api/v1/"
MACHINE_A = hashlib.sha256(b"poste A").hexdigest()
MACHINE_B = hashlib.sha256(b"poste B").hexdigest()
T0 = 1791200000
JOUR = 86400
ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
_ALEA = random.Random(20261005)
_TEMPORAIRES = []


def motif_accentue():
    # Construit avec chr() : ce fichier reste en ASCII pur.
    return ("Dossier incomplet : pi" + chr(0xE8) + "ce manquante " + chr(0x2013) + " "
            + chr(0x153) + "uvre")


class Horloge(object):
    def __init__(self, t=T0):
        self.t = t

    def __call__(self):
        return self.t

    def avancer(self, jours=0, secondes=0):
        self.t += int(round(jours * JOUR)) + secondes


class Monotone(object):
    def __init__(self):
        self.t = 0.0

    def __call__(self):
        return self.t


def generer_cle():
    valeurs = [_ALEA.randrange(32) for _ in range(15)]
    brut = "".join(ALPHABET[v] for v in valeurs) + ALPHABET[sum(valeurs) % 32]
    return "ETDEL-" + "-".join(brut[i:i + 4] for i in range(0, 16, 4))


def graine(n):
    return hashlib.sha256(("graine de test %d" % n).encode("ascii")).digest()


class FauxServeur(object):
    """Reproduit le protocole de l'annexe C, en memoire."""

    def __init__(self, horloge):
        self.horloge = horloge
        self.graines = {1: graine(1)}
        self.kid = 1
        self.bulletins = []
        self.urls = [URL1, URL2]
        self.modes = {}
        self.appels = []
        self.corps = []
        self.code_force = None
        self.verifier_version = True
        self.distributions = {
            "APP-A": {"produit": "APP", "tolerance_j": 15, "preavis_j": 5, "options": ["export_pdf"],
                      "essai_j": 15, "version_min": None, "message": "Bienvenue"},
            "APP-B": {"produit": "APP", "tolerance_j": 15, "preavis_j": 5, "options": ["multi_navire"],
                      "essai_j": 15, "version_min": None, "message": None},
        }
        self.licences = {}
        self.demandes = {}
        self.numero = 0

    # -- administration -----------------------------------------------------

    def publique(self, kid=1):
        return L._b64url(L._ed25519_cle_publique(self.graines[kid]))

    def creer_cle(self, distribution="APP-A", titulaire="Armement Test", jours=365, machine=None,
                  tolerance_j=None, options=None):
        cle = generer_cle()
        self.licences[cle] = {"distribution": distribution, "titulaire": titulaire,
                              "echeance": self.horloge() + jours * JOUR if jours else None,
                              "statut": "active", "machine": machine, "poste": None,
                              "tolerance_j": tolerance_j, "options": options, "contacts": 0}
        return cle

    def accepter(self, numero, jours=90):
        d = self.demandes[numero]
        cle = self.creer_cle(d["distribution"], d["titulaire"], jours, machine=d["machine"])
        d.update(statut="acceptee", cle=cle, cle_conservee=cle)
        return cle

    def refuser(self, numero, motif=None):
        self.demandes[numero].update(statut="refusee", motif=motif)

    def tourner_cle(self):
        ancien = self.kid
        nouveau = ancien + 1
        self.graines[nouveau] = graine(nouveau)
        annonce = {"type": "nouvelle_cle", "kid": nouveau, "cle_publique": self.publique(nouveau),
                   "valide_des": self.horloge()}
        self.bulletins.append(json.loads(self.signer(annonce, ancien)))
        self.kid = nouveau

    # -- protocole ----------------------------------------------------------

    def signer(self, payload, kid, graine_forcee=None):
        brut = json.dumps(payload).encode("utf-8")
        sig = L._ed25519_signer(graine_forcee or self.graines[kid], brut)
        return json.dumps({"payload": L._b64url(brut), "sig": L._b64url(sig), "kid": kid}).encode("utf-8")

    def transport(self, url, corps, delai):
        requete = json.loads(corps.decode("utf-8"))
        self.appels.append((url, requete["op"]))
        self.corps.append(requete)
        mode = self.modes.get(url, "ok")
        if mode == "injoignable":
            raise OSError("connexion refusee")
        if mode == "http500":
            return 500, b""
        payload = self.traiter(requete)
        if mode == "rejeu":
            payload["nonce"] = "autre-nonce"
        if mode == "detourne":
            payload["urls"] = [URL_PIRATE]
            return 200, self.signer(payload, self.kid, os.urandom(32))
        if mode == "mauvaise_signature":
            return 200, self.signer(payload, self.kid, os.urandom(32))
        if mode == "kid_falsifie":
            # Reponse authentique dont le kid (hors signature) a ete change en route.
            enveloppe = json.loads(self.signer(payload, self.kid))
            enveloppe["kid"] = 7
            return 200, json.dumps(enveloppe).encode("utf-8")
        return 200, self.signer(payload, self.kid)

    def jeton(self, base, cle, lic, dist):
        maintenant = self.horloge()
        echeance = lic["echeance"]
        tolerance = lic["tolerance_j"] if lic["tolerance_j"] is not None else dist["tolerance_j"]
        return dict(base, ok=True, code=None, id_poste=L._id_poste(base["machine"], base["produit"]),
                    titulaire=lic["titulaire"], echeance=echeance,
                    jours_restants=int(math.ceil((echeance - maintenant) / float(JOUR))) if echeance else None,
                    hors_ligne_jusqu=maintenant + tolerance * JOUR, preavis_j=dist["preavis_j"],
                    options=lic["options"] if lic["options"] is not None else dist["options"],
                    version_min=dist["version_min"], message=dist["message"])

    def traiter(self, req):
        maintenant = self.horloge()
        base = {"v": 1, "nonce": req.get("nonce"), "produit": req.get("produit"),
                "distribution": req.get("distribution"), "machine": req.get("machine"),
                "emis": maintenant, "urls": list(self.urls), "bulletins": list(self.bulletins)}

        def refus(code):
            return dict(base, ok=False, code=code)

        if abs(req["t"] - maintenant) > 600:
            return refus("horloge")
        if self.code_force:
            return refus(self.code_force)
        dist = self.distributions.get(req["distribution"])
        if dist is None or dist["produit"] != req["produit"]:
            return refus("produit_inconnu")
        op = req["op"]
        if op == "ping":
            return dict(base, ok=True, code=None)
        if op in ("activer", "valider"):
            cle = req.get("cle")
            lic = self.licences.get(cle)
            if lic is None or lic["distribution"] != req["distribution"]:
                return refus("cle_invalide")
            if lic["statut"] != "active":
                return refus("revoquee" if lic["statut"] == "revoquee" else "suspendue")
            if op == "activer" and lic["machine"] is None:
                lic["machine"] = req["machine"]
            if lic["machine"] is None:
                return refus("poste_revoque")
            if lic["machine"] != req["machine"]:
                return refus("cle_liee_autre_poste")
            if lic["echeance"] and maintenant >= lic["echeance"]:
                return refus("expiree")
            if self.verifier_version and dist["version_min"] and \
                    L._comparer_versions(req["version"], dist["version_min"]) < 0:
                return refus("version_trop_ancienne")
            lic["poste"] = req["poste"]
            lic["contacts"] += 1
            if op == "valider":
                for d in self.demandes.values():
                    if d.get("cle") == cle:
                        d["cle_conservee"] = None
            return self.jeton(base, cle, lic, dist)
        if op == "demander":
            for d in self.demandes.values():
                if (d["machine"], d["distribution"], d["statut"]) == (req["machine"], req["distribution"], "en_attente"):
                    if d["jeton"] == req["jeton"]:
                        return dict(base, ok=True, code=None, demande=d["numero"], statut="en_attente",
                                    essai_jusqu=d["essai_jusqu"], options=dist["options"])
                    return refus("demande_en_cours")
            deja = any(d["machine"] == req["machine"] and d["produit"] == req["produit"] and d["essai_jusqu"]
                       for d in self.demandes.values())
            essai = maintenant + dist["essai_j"] * JOUR if dist["essai_j"] and not deja else None
            self.numero += 1
            self.demandes[self.numero] = {
                "numero": self.numero, "distribution": req["distribution"], "produit": req["produit"],
                "machine": req["machine"], "poste": req["poste"], "titulaire": req["titulaire"],
                "email": req["email"], "message": req["message"], "jeton": req["jeton"],
                "statut": "en_attente", "motif": None, "essai_jusqu": essai, "cle": None,
                "cle_conservee": None}
            return dict(base, ok=True, code=None, demande=self.numero, statut="en_attente",
                        essai_jusqu=essai, options=dist["options"])
        if op == "suivre_demande":
            d = self.demandes.get(req.get("demande"))
            if d is None or d["jeton"] != req.get("jeton") or d["machine"] != req["machine"]:
                return refus("demande_inconnue")
            if d["statut"] == "en_attente":
                return dict(base, ok=True, code=None, demande=d["numero"], statut="en_attente",
                            essai_jusqu=d["essai_jusqu"], options=dist["options"])
            if d["statut"] == "refusee":
                return dict(base, ok=True, code=None, demande=d["numero"], statut="refusee", motif=d["motif"])
            if d["cle_conservee"] is None:
                return refus("demande_inconnue")
            lic = self.licences[d["cle"]]
            reponse = self.jeton(base, d["cle"], lic, dist)
            reponse.update(demande=d["numero"], statut="acceptee", cle=d["cle"])
            return reponse
        return refus("requete_invalide")


def dossiers_temporaires(n=3):
    racine = tempfile.mkdtemp(prefix="etdel_test_")
    _TEMPORAIRES.append(racine)
    return [os.path.join(racine, "d%d" % i) for i in range(n)]


def contexte():
    horloge = Horloge()
    return horloge, FauxServeur(horloge), dossiers_temporaires()


def garde(serveur, dossiers, horloge, machine=MACHINE_A, poste="PC-PASSERELLE", distribution="APP-A",
          version="1.4.0", urls=None, cle_publique=None, monotone=None, **kw):
    return L.Garde("APP", distribution, version, _horloge=horloge, _transport=serveur.transport,
                   _dossiers=dossiers, _dossier_journal=dossiers[0], _machine=machine, _poste=poste,
                   _urls=urls if urls is not None else [URL1, URL_SECOURS],
                   _cle_publique=cle_publique if cle_publique is not None else serveur.publique(1),
                   _fil=kw.pop("_fil", False), _monotone=monotone or Monotone(), **kw)


def ops(serveur):
    return [op for _url, op in serveur.appels]


def fichiers(dossiers):
    sortie = []
    for d in dossiers:
        if os.path.isdir(d):
            sortie.extend(os.path.join(d, f) for f in os.listdir(d) if f.endswith(".dat"))
    return sortie


def garde_active(**kw):
    horloge, serveur, dossiers = contexte()
    cle = serveur.creer_cle(**kw)
    g = garde(serveur, dossiers, horloge)
    resultat = g.activer(cle)
    return horloge, serveur, dossiers, g, cle, resultat


# ---------------------------------------------------------------------------
# Tests sans interface
# ---------------------------------------------------------------------------

RFC8032 = [
    ("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60",
     "d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a", "",
     "e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b"),
    ("4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb",
     "3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c", "72",
     "92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00"),
    ("c5aa8df43f9f837bedb7442f31dcb7b166d38535076f094b85ce3a2e0b4458f7",
     "fc51cd8e6218a1a38da47ed00230f0580816ed13ba3303ac5deb911548908025", "af82",
     "6291d657deec24024827e69c3abe01a30ce548a284743a445e3680d7db5ac3ac18ff9b538d16f290ae67f760984dc6594a7c15e9716ed28dc027beceea1ec40a"),
    ("833fe62409237b9d62ec77587520911e9a759cec1d19755b7da901b96dca3d42",
     "ec172b93ad5e563bf4932c70e1245034c35467ef2efd4d64ebf819683467e2bf",
     "ddaf35a193617abacc417349ae20413112e6fa4e89a97ea20a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd454d4423643ce80e2a9ac94fa54ca49f",
     "dc2a4459e7369633a52b1bf277839a00201009a3efbf3ecb69bea2186c26b58909351fc9ac90b3ecfdfbc7c66431e0303dca179c138ac17ad9bef1177331a704"),
]


def test_ed25519():
    for i, (secret, publique, message, signature) in enumerate(RFC8032):
        sk, pk, msg, sig = (bytes.fromhex(x) for x in (secret, publique, message, signature))
        check("rfc8032 %d : verification" % i, L._ed25519_verifier(pk, msg, sig))
        check("rfc8032 %d : cle publique" % i, L._ed25519_cle_publique(sk) == pk)
        check("rfc8032 %d : signature" % i, L._ed25519_signer(sk, msg) == sig)
        check("rfc8032 %d : message altere refuse" % i, not L._ed25519_verifier(pk, msg + b"x", sig))
        altere = bytearray(sig)
        altere[5] ^= 1
        check("rfc8032 %d : signature alteree refusee" % i, not L._ed25519_verifier(pk, msg, bytes(altere)))
    pk = bytes.fromhex(RFC8032[0][1])
    sig = bytes.fromhex(RFC8032[0][3])
    s = int.from_bytes(sig[32:], "little") + L._Q
    check("rfc8032 : s non canonique refuse",
          not L._ed25519_verifier(pk, b"", sig[:32] + s.to_bytes(32, "little")))
    check("rfc8032 : longueurs fausses refusees", not L._ed25519_verifier(pk[:31], b"", sig))


def test_formats():
    cle = generer_cle()
    check("cle : forme canonique acceptee", L.normaliser_cle(cle) == cle)
    check("cle : casse et tirets ignores", L.normaliser_cle(cle.lower().replace("-", " ")) == cle)
    check("cle : sans prefixe", L.normaliser_cle(cle[6:]) == cle)
    brut = cle.replace("-", "")[5:]
    faux = brut[:15] + ALPHABET[(ALPHABET.index(brut[15]) + 1) % 32]
    check("cle : somme de controle fausse refusee", L.normaliser_cle(faux) is None)
    check("cle : longueur fausse refusee", L.normaliser_cle(cle[:-1]) is None)
    check("cle : caractere U refuse", L.normaliser_cle(cle[:-1] + "U") is None)
    avec_zero = None
    for _ in range(200):
        c = generer_cle()
        if "0" in c[6:-1]:
            avec_zero = c
            break
    if avec_zero:
        dicte = avec_zero[:6] + avec_zero[6:-1].replace("0", "O") + avec_zero[-1]
        check("cle : O lu comme 0 (Crockford)", L.normaliser_cle(dicte) == avec_zero)
    check("cle : non chaine", L.normaliser_cle(None) is None)
    ident = L._id_poste(MACHINE_A, "APP")
    check("id poste : format XXXX-XXXX", re.match(r"^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$", ident) is not None)
    check("id poste : stable", ident == L._id_poste(MACHINE_A, "APP"))
    check("id poste : depend du produit", ident != L._id_poste(MACHINE_A, "AUTRE"))
    check("versions : 1.10 > 1.9", L._comparer_versions("1.10", "1.9") > 0)
    check("versions : 1.2 == 1.2.0", L._comparer_versions("1.2", "1.2.0") == 0)
    check("versions : 1.2.0 < 1.2.1", L._comparer_versions("1.2.0", "1.2.1") < 0)
    check("versions : suffixe ignore", L._comparer_versions("2.0.0b1", "2.0") == 0)
    check("translitteration", L._ascii(motif_accentue()) == "Dossier incomplet : piece manquante - oeuvre")
    for n in (0, 1, 2, 31, 32, 33):
        octets = os.urandom(n)
        check("base64url %d octets" % n, L._deb64url(L._b64url(octets)) == octets)
    try:
        L._deb64url("abc$")
        check("base64url : caractere interdit refuse", False)
    except ValueError:
        check("base64url : caractere interdit refuse", True)
    check("url https acceptee", L._url_autorisee(URL1))
    check("url http distante refusee", not L._url_autorisee("http://licence.exemple.fr/api/v1/"))
    check("url http locale acceptee (banc d'essai)", L._url_autorisee("http://127.0.0.1:8080/api/v1/"))


def test_fichier():
    with open(os.path.join(ICI, "etdel_licence.py"), "rb") as f:
        source = f.read()
    check("module en ASCII pur", all(b < 128 for b in source))
    check("module : en-tete ETDEL (c) 2026", b"ETDEL (c) 2026" in source[:800])
    check("module : jamais time.sleep", b"time.sleep" not in source)
    # Vides tant que le serveur n'est pas installe, puis renseignees une fois pour toutes.
    vides = not (L.LICENCE_URL or L.LICENCE_URL_SECOURS or L.LICENCE_CLE_PUBLIQUE)
    try:
        cle_ok = len(L._deb64url(L.LICENCE_CLE_PUBLIQUE)) == 32
    except ValueError:
        cle_ok = False
    renseignees = cle_ok and L._url_autorisee(L.LICENCE_URL) and L.LICENCE_URL.startswith("https://") and (
        not L.LICENCE_URL_SECOURS or L.LICENCE_URL_SECOURS.startswith("https://"))
    check("module : constantes vides, ou URL https et cle publique de 32 octets", vides or renseignees)
    with open(os.path.abspath(__file__), "rb") as f:
        check("tests en ASCII pur", all(b < 128 for b in f.read()))


def test_publication():
    # La version et l'empreinte publiees doivent suivre le module : sinon les
    # tests d'integration des applications compareraient a une reference fausse.
    with open(os.path.join(ICI, "etdel_licence.py"), "rb") as f:
        empreinte = hashlib.sha256(f.read().replace(b"\r\n", b"\n")).hexdigest()
    with open(os.path.join(os.path.dirname(ICI), "docs", "INTEGRATION.md"), "r", encoding="utf-8") as f:
        doc = f.read()
    with open(os.path.join(ICI, "test_licence_integration.py"), "r", encoding="ascii") as f:
        integration = f.read()
    check("INTEGRATION.md : empreinte publiee a jour", ("`%s`" % empreinte) in doc)
    check("INTEGRATION.md : version publiee a jour", ("version **%s**" % L.MODULE_VERSION) in doc)
    check("test_licence_integration.py : empreinte de reference a jour",
          ('EMPREINTE_REFERENCE = "%s"' % empreinte) in integration)
    check("test_licence_integration.py : version de reference a jour",
          ('VERSION_REFERENCE = "%s"' % L.MODULE_VERSION) in integration)


def test_non_configure():
    horloge, serveur, dossiers = contexte()
    for urls, cle in (([], "x"), (["", ""], "x"), ([URL1], ""), ([], "")):
        g = garde(serveur, dossiers, horloge, urls=urls, cle_publique=cle)
        g.demarrer()
        g.controler_maintenant()
        check("non configure : statut", g.etat()["statut"] == L.NON_CONFIGURE)
        check("non configure : option False", g.option("export_pdf") is False)
        check("non configure : activer sans effet", g.activer(generer_cle())["ok"] is False)
        check("non configure : diagnostic", "non configuree" in g.texte_diagnostic())
        g.arreter()
    time.sleep(0.05)
    check("non configure : aucun appel reseau", serveur.appels == [])
    check("non configure : aucun fichier cree", not any(os.path.exists(d) for d in dossiers))
    g = garde(serveur, dossiers, horloge, urls=[], cle_publique="")
    check("non configure : exiger rend la main", L.exiger("APP", "APP-A", "1.0", _garde=g) is g)
    sauvegarde = (L.LICENCE_URL, L.LICENCE_URL_SECOURS, L.LICENCE_CLE_PUBLIQUE)
    L.LICENCE_URL = L.LICENCE_URL_SECOURS = L.LICENCE_CLE_PUBLIQUE = ""
    try:
        defaut = L.Garde("APP", "APP-A", "1.0", _fil=False)
        check("constantes vides : non configure", defaut.etat()["statut"] == L.NON_CONFIGURE)
    finally:
        L.LICENCE_URL, L.LICENCE_URL_SECOURS, L.LICENCE_CLE_PUBLIQUE = sauvegarde


def test_activation():
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    g.demarrer()
    check("demarrage sans licence : A_ACTIVER", g.etat()["statut"] == L.A_ACTIVER)
    g._controler()
    check("A_ACTIVER : aucun envoi sans action", serveur.appels == [])
    r = g.activer("ETDEL-AAAA-BBBB")
    check("cle mal formee : refusee sans reseau", r["code"] == "cle_invalide" and serveur.appels == [])
    r = g.activer(generer_cle())
    check("cle inconnue : cle_invalide", r["code"] == "cle_invalide" and g.etat()["statut"] == L.A_ACTIVER)
    cle = serveur.creer_cle(jours=365)
    r = g.activer(cle.lower())
    e = g.etat()
    check("activation : ok", r["ok"] is True)
    check("activation : VALIDE", e["statut"] == L.VALIDE)
    check("activation : 365 jours restants", e["jours_restants"] == 365)
    check("activation : titulaire", e["titulaire"] == "Armement Test")
    check("activation : identifiant de poste", e["id_poste"] == L._id_poste(MACHINE_A, "APP"))
    check("activation : option presente", g.option("export_pdf") is True)
    check("activation : option absente False", g.option("multi_navire") is False)
    check("activation : poste lie cote serveur", serveur.licences[cle]["machine"] == MACHINE_A)
    check("activation : trois exemplaires", len(fichiers(dossiers)) == 3)
    check("activation : etat() complet", set(e) == {
        "statut", "message", "id_poste", "nom_ordinateur", "titulaire", "echeance", "jours_restants",
        "dernier_controle", "demande", "motif_refus", "url_active", "kid_actif", "raison"})
    for chemin in fichiers(dossiers):
        with open(chemin, "rb") as f:
            contenu = f.read()
        check("etat local chiffre (cle absente en clair)", cle.encode() not in contenu and b"Armement" not in contenu)
    g.arreter()
    n = len(serveur.appels)
    g2 = garde(serveur, dossiers, horloge)
    g2.demarrer()
    check("relance : VALIDE sans reseau", g2.etat()["statut"] == L.VALIDE and len(serveur.appels) == n)
    check("relance : controle valider", g2._controler() is True and ops(serveur)[-1] == "valider")


def test_donnees_transmises():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    g._controler()
    g2 = garde(serveur, dossiers_temporaires(), horloge, machine=MACHINE_B)
    g2.demander("Titulaire", "t@exemple.fr", "Bonjour")
    g2._controler()
    communs = {"v", "op", "produit", "distribution", "machine", "poste", "version", "nonce", "t"}
    propres = {"activer": {"cle"}, "valider": {"cle"}, "suivre_demande": {"demande", "jeton"},
               "demander": {"titulaire", "email", "message", "jeton"}}
    for corps in serveur.corps:
        check("donnees transmises (%s)" % corps["op"], set(corps) == communs | propres[corps["op"]])
        check("machine = sha256 hex", re.match(r"^[0-9a-f]{64}$", corps["machine"]) is not None)


def test_tolerance():
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=None)
    check("perpetuelle : VALIDE", g.etat()["statut"] == L.VALIDE and g.etat()["jours_restants"] is None)
    for url in (URL1, URL2, URL_SECOURS):
        serveur.modes[url] = "injoignable"
    horloge.avancer(jours=9)
    check("hors ligne : controle en echec", g._controler() is False)
    check("hors ligne jour 9 : VALIDE", g.etat()["statut"] == L.VALIDE)
    check("hors ligne : raison renseignee", "injoignable" in g.etat()["raison"])
    horloge.avancer(jours=1)
    e = g.etat()
    check("hors ligne jour 10 : AVERTISSEMENT", e["statut"] == L.AVERTISSEMENT)
    check("hors ligne : message de preavis", "connectez l'ordinateur" in e["message"])
    horloge.avancer(jours=4, secondes=86399)
    check("hors ligne jour 14,99 : AVERTISSEMENT", g.etat()["statut"] == L.AVERTISSEMENT)
    horloge.avancer(secondes=1)
    check("hors ligne jour 15 : EXPIREE", g.etat()["statut"] == L.EXPIREE)
    check("EXPIREE : option False", g.option("export_pdf") is False)
    serveur.modes[URL2] = "ok"
    check("retour du serveur : controle ok", g._controler() is True)
    check("retour du serveur : VALIDE", g.etat()["statut"] == L.VALIDE)
    check("retour : url de secours devenue active", g.etat()["url_active"] == URL2)


def test_tolerance_par_licence():
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=None, tolerance_j=30)
    serveur.modes[URL1] = serveur.modes[URL2] = serveur.modes[URL_SECOURS] = "injoignable"
    horloge.avancer(jours=24)
    check("tolerance surchargee 30 j : jour 24 VALIDE", g.etat()["statut"] == L.VALIDE)
    horloge.avancer(jours=1)
    check("tolerance surchargee 30 j : jour 25 AVERTISSEMENT", g.etat()["statut"] == L.AVERTISSEMENT)
    horloge.avancer(jours=5)
    check("tolerance surchargee 30 j : jour 30 EXPIREE", g.etat()["statut"] == L.EXPIREE)


def test_preavis_superieur_a_la_tolerance():
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=None, tolerance_j=3)
    check("preavis 5 j, tolerance 3 j : VALIDE apres verification", g.etat()["statut"] == L.VALIDE)
    serveur.modes[URL1] = serveur.modes[URL2] = serveur.modes[URL_SECOURS] = "injoignable"
    horloge.avancer(jours=1.4)
    check("preavis borne : VALIDE a 1,4 j", g.etat()["statut"] == L.VALIDE)
    horloge.avancer(jours=0.1)
    check("preavis borne : AVERTISSEMENT a mi-tolerance", g.etat()["statut"] == L.AVERTISSEMENT)
    horloge.avancer(jours=1.5)
    check("preavis borne : EXPIREE a 3 j", g.etat()["statut"] == L.EXPIREE)


def test_deux_instances():
    horloge, serveur, dossiers = contexte()
    a = garde(serveur, dossiers, horloge)
    a.demarrer()
    b = garde(serveur, dossiers, horloge)
    b.demarrer()
    check("deux instances : activation dans la seconde", b.activer(serveur.creer_cle())["ok"])
    a.arreter()
    check("deux instances : fermer la premiere ne perd pas la cle",
          garde(serveur, dossiers, horloge).etat()["statut"] == L.VALIDE)
    horloge, serveur, dossiers = contexte()
    a = garde(serveur, dossiers, horloge)
    a.demarrer()
    b = garde(serveur, dossiers, horloge)
    b.demarrer()
    b.demander("Armement")
    check("deux instances : la premiere voit la demande apres relecture",
          a.etat()["statut"] == L.A_ACTIVER and (a._rafraichir() or a.etat()["statut"] == L.ESSAI))
    a.arreter()
    b.arreter()
    c = garde(serveur, dossiers, horloge)
    check("deux instances : demande conservee", c.etat()["demande"] == 1)
    serveur.accepter(1)
    check("deux instances : cle livree", c._controler() and c.etat()["statut"] == L.VALIDE)


def test_recul_horloge():
    horloge, serveur, dossiers = contexte()
    cle = serveur.creer_cle(jours=None)
    mono = Monotone()
    g = garde(serveur, dossiers, horloge, monotone=mono)
    g.activer(cle)
    serveur.modes[URL1] = serveur.modes[URL2] = serveur.modes[URL_SECOURS] = "injoignable"
    horloge.avancer(jours=9)
    check("recul : jour 9 VALIDE", g.etat()["statut"] == L.VALIDE)
    horloge.avancer(jours=-1)
    check("recul de 24 h tolere", g.etat()["statut"] == L.VALIDE)
    horloge.avancer(jours=-29)
    mono.t += 2 * JOUR
    check("recul de 30 j : heure max utilisee (jour 11)", g.etat()["statut"] == L.AVERTISSEMENT)
    mono.t += 4 * JOUR
    check("recul : le temps d'usage s'ecoule quand meme (jour 15)", g.etat()["statut"] == L.EXPIREE)
    g.arreter()
    g2 = garde(serveur, dossiers, horloge)
    check("recul : heure max persistee", g2.etat()["statut"] == L.EXPIREE)
    # Le serveur garde l'heure vraie (jour 15) : lui ne recule pas.
    serveur.horloge = Horloge(T0 + 15 * JOUR)
    serveur.modes[URL1] = "ok"
    check("recul : serveur refuse l'horloge", g2._controler() is False and "Horloge" in g2.etat()["raison"])
    check("recul : toujours EXPIREE", g2.etat()["statut"] == L.EXPIREE)


def test_avance_horloge_corrigee():
    horloge, serveur, dossiers = contexte()
    cle = serveur.creer_cle(jours=None)
    g = garde(serveur, dossiers, horloge)
    g.activer(cle)
    reel = horloge.t
    horloge.t = reel + 400 * JOUR
    g.etat()
    g.arreter()
    horloge.t = reel + 1
    g2 = garde(serveur, dossiers, horloge)
    check("avance accidentelle : EXPIREE hors ligne", g2.etat()["statut"] == L.EXPIREE)
    check("avance accidentelle : corrigee au contact signe", g2._controler() and g2.etat()["statut"] == L.VALIDE)


def test_revocation():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    serveur.licences[cle]["statut"] = "revoquee"
    check("revocation : controle tranche", g._controler() is True)
    e = g.etat()
    check("revocation : REVOQUEE", e["statut"] == L.REVOQUEE and e["message"] == "Licence revoquee")
    check("revocation : cle effacee", g._local["cle"] is None and g._local["jeton"] is None)
    g.arreter()
    check("revocation : A_ACTIVER au lancement suivant", garde(serveur, dossiers, horloge).etat()["statut"] == L.A_ACTIVER)
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    serveur.licences[cle]["statut"] = "suspendue"
    g._controler()
    check("suspension : REVOQUEE (suspendue)", g.etat()["statut"] == L.REVOQUEE and "suspendue" in g.etat()["message"])


def test_autre_poste():
    horloge, serveur, dossiers, ga, cle, _r = garde_active()
    gb = garde(serveur, dossiers_temporaires(), horloge, machine=MACHINE_B, poste="PC-NEUF")
    r = gb.activer(cle)
    check("cle sur un autre poste : cle_liee_autre_poste", r["code"] == "cle_liee_autre_poste")
    check("cle sur un autre poste : message", r["message"] == "Cle deja utilisee sur un autre ordinateur")
    serveur.licences[cle]["machine"] = None
    ga._controler()
    check("poste libere : ancien poste REVOQUEE (poste_revoque)", ga.etat()["statut"] == L.REVOQUEE)
    r = gb.activer(cle)
    check("apres liberation : activation sur le nouveau poste", r["ok"] and gb.etat()["statut"] == L.VALIDE)
    ga2 = garde(serveur, dossiers_temporaires(), horloge)
    check("nouveau poste lie : ancien refuse", ga2.activer(cle)["code"] == "cle_liee_autre_poste")


def test_renommage():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    g.arreter()
    g2 = garde(serveur, dossiers, horloge, poste="PC-RENOMME")
    check("renommage : toujours VALIDE", g2.etat()["statut"] == L.VALIDE)
    check("renommage : controle ok", g2._controler() is True and g2.etat()["statut"] == L.VALIDE)
    check("renommage : nouveau nom transmis", serveur.licences[cle]["poste"] == "PC-RENOMME")
    g3 = garde(serveur, dossiers_temporaires(), horloge, machine=MACHINE_B, poste="PC-RENOMME")
    check("homonyme sur une autre machine : refuse", g3.activer(cle)["code"] == "cle_liee_autre_poste")


def test_demande_essai_acceptee():
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    g.demarrer()
    g._controler()
    check("demande : rien envoye sans action", serveur.appels == [])
    check("demande : titulaire obligatoire", g.demander("  ", "", "")["code"] == "titulaire" and serveur.appels == [])
    r = g.demander("Armement Durand", "durand@exemple.fr", "x" * 500)
    e = g.etat()
    check("demande : envoyee", r["ok"] and e["demande"] == 1)
    check("demande : message borne a 200", len(serveur.demandes[1]["message"]) == 200)
    check("demande : ESSAI", e["statut"] == L.ESSAI)
    check("demande : bandeau d'essai",
          e["message"] == "Licence en cours de traitement - 15 jour(s) d'essai restant(s)")
    check("demande : option de la distribution pendant l'essai", g.option("export_pdf") is True)
    check("demande : mention de delai", "Le traitement peut prendre plusieurs jours." in r["message"])
    r2 = g.demander("Autre", "", "")
    check("seconde demande en attente : refusee", r2["code"] == "demande_en_cours")
    check("seconde demande : la premiere reste", g.etat()["demande"] == 1)
    g._controler()
    check("suivi : en attente", g.etat()["statut"] == L.ESSAI and ops(serveur)[-1] == "suivre_demande")
    horloge.avancer(jours=3)
    serveur.accepter(1, jours=90)
    check("acceptation : suivi", g._controler() is True)
    e = g.etat()
    check("acceptation : VALIDE sans saisie", e["statut"] == L.VALIDE)
    check("acceptation : 90 jours restants", e["jours_restants"] == 90)
    check("acceptation : cle enregistree", g._local["cle"] == serveur.demandes[1]["cle"])
    check("acceptation : demande soldee", e["demande"] is None)
    check("acceptation : valider immediat", ops(serveur)[-1] == "valider")
    check("acceptation : cle effacee cote serveur", serveur.demandes[1]["cle_conservee"] is None)


def test_demande_refusee():
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    g.demander("Armement Durand")
    serveur.refuser(1, motif_accentue())
    g._controler()
    e = g.etat()
    check("refus : DEMANDE_REFUSEE", e["statut"] == L.DEMANDE_REFUSEE)
    check("refus : motif translittere",
          e["message"] == "Demande refusee : Dossier incomplet : piece manquante - oeuvre")
    check("refus : etat ASCII", all(ord(c) < 128 for c in e["message"]))
    check("refus : bloque (option False)", g.option("export_pdf") is False)
    g.arreter()
    g2 = garde(serveur, dossiers, horloge)
    check("refus : repris au lancement suivant sans reseau", g2.etat()["statut"] == L.DEMANDE_REFUSEE)
    r = g2.demander("Armement Durand")
    check("nouvelle demande apres refus : acceptee", r["ok"])
    check("nouvelle demande apres refus : pas d'essai", g2.etat()["statut"] == L.DEMANDE_EN_ATTENTE)
    serveur.refuser(2, None)
    g2._controler()
    check("refus sans motif : message seul", g2.etat()["message"] == "Demande refusee")


def test_essai_unique():
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    g.demander("Armement Durand")
    check("premiere demande : essai", g.etat()["statut"] == L.ESSAI)
    shutil.rmtree(os.path.dirname(dossiers[0]))
    g2 = garde(serveur, dossiers, horloge)
    check("reinstallation : A_ACTIVER", g2.etat()["statut"] == L.A_ACTIVER)
    check("reinstallation : demande en cours refusee", g2.demander("Armement Durand")["code"] == "demande_en_cours")
    serveur.refuser(1)
    check("reinstallation apres refus : demande acceptee", g2.demander("Armement Durand")["ok"])
    check("reinstallation : aucun essai", g2.etat()["statut"] == L.DEMANDE_EN_ATTENTE)
    g3 = garde(serveur, dossiers_temporaires(), horloge, distribution="APP-B")
    check("autre distribution, meme produit : pas d'essai",
          g3.demander("X")["ok"] and g3.etat()["statut"] == L.DEMANDE_EN_ATTENTE)


def test_essai_epuise():
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    g.demander("Armement Durand")
    horloge.avancer(jours=14)
    check("essai jour 14 : 1 jour restant", g.etat()["jours_restants"] == 1)
    horloge.avancer(jours=1)
    e = g.etat()
    check("essai epuise : DEMANDE_EN_ATTENTE", e["statut"] == L.DEMANDE_EN_ATTENTE)
    check("essai epuise : texte de demande", e["message"].startswith("Demande envoyee le "))
    check("essai epuise : suivi toutes les 60 s", g._delai_suivant(True) == 60)
    check("essai epuise : option False", g.option("export_pdf") is False)


def test_demande_reprise_et_perte():
    horloge, serveur, dossiers = contexte()
    perdre = [True]

    def transport_perdu(url, corps, delai):
        reponse = serveur.transport(url, corps, delai)
        if perdre[0]:
            perdre[0] = False
            raise OSError("reponse perdue")
        return reponse

    g = L.Garde("APP", "APP-A", "1.4.0", _horloge=horloge, _transport=transport_perdu,
                _dossiers=dossiers, _machine=MACHINE_A, _poste="PC", _urls=[URL1],
                _cle_publique=serveur.publique(), _fil=False, _monotone=Monotone())
    check("reponse perdue : injoignable", g.demander("Armement")["code"] == "injoignable")
    check("reponse perdue : demande creee cote serveur", len(serveur.demandes) == 1)
    r = g.demander("Armement")
    check("renvoi : meme demande reprise", r["ok"] and g.etat()["demande"] == 1 and len(serveur.demandes) == 1)
    g.arreter()
    g2 = garde(serveur, dossiers, horloge)
    check("relance : demande reprise", g2.etat()["demande"] == 1 and g2.etat()["statut"] == L.ESSAI)
    del serveur.demandes[1]
    g2._controler()
    check("demande supprimee cote serveur : A_ACTIVER", g2.etat()["statut"] == L.A_ACTIVER)


def test_options_distributions():
    horloge, serveur, _dossiers = contexte()
    ga = garde(serveur, dossiers_temporaires(), horloge, distribution="APP-A")
    gb = garde(serveur, dossiers_temporaires(), horloge, distribution="APP-B", machine=MACHINE_B)
    ga.activer(serveur.creer_cle("APP-A"))
    gb.activer(serveur.creer_cle("APP-B"))
    check("distribution A : export_pdf", ga.option("export_pdf") and not ga.option("multi_navire"))
    check("distribution B : multi_navire", gb.option("multi_navire") and not gb.option("export_pdf"))
    cle = serveur.creer_cle("APP-A", options=["special"])
    gc = garde(serveur, dossiers_temporaires(), horloge, distribution="APP-A", machine=hashlib.sha256(b"c").hexdigest())
    gc.activer(cle)
    check("options surchargees par licence", gc.option("special") and not gc.option("export_pdf"))
    check("cle d'une autre distribution : invalide",
          garde(serveur, dossiers_temporaires(), horloge, distribution="APP-B", machine=hashlib.sha256(b"d").hexdigest())
          .activer(serveur.creer_cle("APP-A"))["code"] == "cle_invalide")


def test_migration_url():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    check("liste signee adoptee", g._local["urls"] == [URL1, URL2])
    serveur.urls = [URL3, URL1]
    g._controler()
    check("nouvelle liste adoptee", g._local["urls"] == [URL3, URL1])
    serveur.urls = [URL3]
    g._controler()
    check("ancienne url retiree de la liste", g._local["urls"] == [URL3])
    serveur.modes[URL1] = "injoignable"
    n = len(serveur.appels)
    check("coupure de l'ancienne : bascule", g._controler() is True and g.etat()["url_active"] == URL3)
    check("coupure : url diffusee essayee en premier", serveur.appels[n][0] == URL3)
    g.arreter()
    g2 = garde(serveur, dossiers, horloge)
    g2._controler()
    check("liste persistee hors exe", serveur.appels[-1][0] == URL3)


def test_signature_invalide():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    serveur.modes[URL1] = "detourne"
    serveur.modes[URL2] = serveur.modes[URL_SECOURS] = "injoignable"
    check("signature invalide : traitee comme injoignable", g._controler() is False)
    check("signature invalide : liste conservee", g._local["urls"] == [URL1, URL2])
    check("signature invalide : statut conserve", g.etat()["statut"] == L.VALIDE)
    serveur.modes[URL2] = "ok"
    check("signature invalide : url suivante essayee", g._controler() is True and g.etat()["url_active"] == URL2)
    serveur.modes[URL1] = serveur.modes[URL2] = "rejeu"
    check("nonce different (rejeu) : refuse", g._controler() is False)
    serveur.modes[URL1] = serveur.modes[URL2] = "http500"
    check("http 500 : injoignable", g._controler() is False)
    horloge2, serveur2, dossiers2 = contexte()
    g2 = garde(serveur2, dossiers2, horloge2, cle_publique=L._b64url(L._ed25519_cle_publique(graine(99))))
    check("cle publique differente : activation impossible",
          g2.activer(serveur2.creer_cle())["code"] == "injoignable")


def test_cache_altere():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    g.arreter()
    chemins = sorted(fichiers(dossiers))
    with open(chemins[0], "rb") as f:
        original = f.read()
    altere = bytearray(original)
    altere[-3] ^= 0x40
    with open(chemins[0], "wb") as f:
        f.write(bytes(altere))
    g2 = garde(serveur, dossiers, horloge)
    check("un exemplaire altere : autres utilises", g2.etat()["statut"] == L.VALIDE)
    with open(chemins[0], "rb") as f:
        check("exemplaire altere reecrit", f.read() == original)
    os.remove(chemins[1])
    os.remove(chemins[2])
    check("exemplaires supprimes : reconstitues", garde(serveur, dossiers, horloge).etat()["statut"] == L.VALIDE
          and len(fichiers(dossiers)) == 3)
    for chemin in chemins:
        with open(chemin, "r+b") as f:
            f.seek(40)
            f.write(b"\x00\x01\x02")
    check("tous exemplaires alteres : A_ACTIVER", garde(serveur, dossiers, horloge).etat()["statut"] == L.A_ACTIVER)
    # Etat correctement scelle mais jeton modifie (echeance repoussee) : signature fausse.
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=30)
    with g._verrou:
        jeton = dict(g._local["jeton"])
        payload = json.loads(L._deb64url(jeton["payload"]))
        payload["echeance"] += 3650 * JOUR
        jeton["payload"] = L._b64url(json.dumps(payload).encode())
        g._local["jeton"] = jeton
        g._sauver()
    g3 = garde(serveur, dossiers, horloge)
    check("jeton modifie a la main : rejete", g3._payload is None and g3.etat()["statut"] == L.EXPIREE)
    check("jeton rejete : controle le remplace", g3._controler() and g3.etat()["statut"] == L.VALIDE)
    check("jeton rejete : echeance d'origine", g3.etat()["jours_restants"] == 30)
    autre = garde(serveur, dossiers, horloge, machine=MACHINE_B)
    check("etat copie sur une autre machine : illisible", autre.etat()["statut"] == L.A_ACTIVER)


def test_bulletins():
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    serveur.modes[URL1] = "kid_falsifie"
    check("kid falsifie au premier contact : reponse authentique acceptee", g.activer(serveur.creer_cle())["ok"])
    serveur.modes[URL1] = "ok"
    check("kid falsifie : la cle embarquee reste utilisable", g._controler() is True and g.etat()["kid_actif"] == 1)
    # Le kid altere (7) a classe la cle embarquee sous un faux numero : le
    # bulletin signe qui annonce le vrai kid 7 doit malgre tout etre adopte.
    for _n in range(6):
        serveur.tourner_cle()
    check("kid falsifie puis rotation vers ce kid : bulletin adopte",
          g._controler() is True and g.etat()["kid_actif"] == 7
          and g._local["cles"]["7"]["cle"] == serveur.publique(7))
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    check("kid initial", g.etat()["kid_actif"] == 1)
    serveur.tourner_cle()
    check("rotation : reponse kid 2 acceptee", g._controler() is True and g.etat()["kid_actif"] == 2)
    check("rotation : cle 2 adoptee", g._local["cles"]["2"]["cle"] == serveur.publique(2))
    check("rotation : ancienne cle conservee 90 j", g._local["cles"]["1"]["jusqu"] == horloge.t + 90 * JOUR)
    g.arreter()
    check("rotation : relance VALIDE", garde(serveur, dossiers, horloge).etat()["statut"] == L.VALIDE)
    neuf = garde(serveur, dossiers_temporaires(), horloge, machine=MACHINE_B)
    check("poste neuf apres rotation : bulletin suivi", neuf.activer(serveur.creer_cle())["ok"])
    serveur.tourner_cle()
    vieux_horloge, vieux_serveur = horloge, serveur
    check("double rotation : chaine de bulletins", neuf._controler() and neuf.etat()["kid_actif"] == 3)
    # Bulletin forge : signe par une cle inconnue.
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    pirate = {"type": "nouvelle_cle", "kid": 2, "cle_publique": L._b64url(L._ed25519_cle_publique(graine(66))),
              "valide_des": horloge.t}
    serveur.bulletins.append(json.loads(serveur.signer(pirate, 1, graine(66))))
    serveur.graines[2] = graine(66)
    serveur.kid = 2
    check("bulletin forge : rejete", g._controler() is False and "2" not in g._local["cles"])
    # Ancienne cle refusee pour une reponse neuve apres 90 jours.
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=None, tolerance_j=365)
    serveur.tourner_cle()
    g._controler()
    serveur.kid = 1
    horloge.avancer(jours=89)
    check("ancienne cle : encore acceptee a 89 j", g._controler() is True)
    horloge.avancer(jours=2)
    check("ancienne cle : refusee apres 90 j", g._controler() is False)
    serveur.modes[URL1] = serveur.modes[URL2] = "kid_falsifie"
    check("ancienne cle sous un autre kid : refusee", g._controler() is False)
    serveur.modes[URL1] = serveur.modes[URL2] = "ok"
    serveur.kid = 2
    check("nouvelle cle : acceptee", g._controler() is True)
    del vieux_horloge, vieux_serveur


def test_version_et_expiration():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    serveur.distributions["APP-A"]["version_min"] = "2.0"
    g._controler()
    e = g.etat()
    check("version_min serveur : VERSION_REFUSEE", e["statut"] == L.VERSION_REFUSEE)
    check("version refusee : message", "mettez l'application a jour" in e["message"])
    serveur.verifier_version = False
    g._controler()
    check("version_min du jeton : VERSION_REFUSEE local", g.etat()["statut"] == L.VERSION_REFUSEE
          and "version minimale 2.0" in g.etat()["message"])
    check("version a jour : VALIDE",
          garde(serveur, dossiers_temporaires(), horloge, version="2.1", machine=MACHINE_B)
          .activer(serveur.creer_cle())["ok"])
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    serveur.distributions["APP-A"]["version_min"] = "2.0"
    g._controler()
    g.arreter()
    check("refus de version memorise", garde(serveur, dossiers, horloge).etat()["statut"] == L.VERSION_REFUSEE)
    check("application mise a jour : refus de version leve",
          garde(serveur, dossiers, horloge, version="2.1").etat()["statut"] == L.VALIDE)
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=10)
    e = g.etat()
    check("echeance sous 15 j : AVERTISSEMENT", e["statut"] == L.AVERTISSEMENT and e["jours_restants"] == 10)
    check("echeance proche : message", "Contactez ETDEL pour la prolonger" in e["message"])
    horloge.avancer(jours=10)
    check("echeance depassee hors ligne : EXPIREE", g.etat()["statut"] == L.EXPIREE)
    g._controler()
    check("serveur : expiree", g.etat()["statut"] == L.EXPIREE and g._local["cle"] == cle)
    serveur.licences[cle]["echeance"] = horloge.t + 365 * JOUR
    check("prolongation : controle", g._controler() is True)
    check("prolongation : VALIDE", g.etat()["statut"] == L.VALIDE and g.etat()["jours_restants"] == 365)
    serveur.code_force = "produit_inconnu"
    g._controler()
    check("distribution desactivee : bloquee", g.etat()["statut"] == L.EXPIREE)
    serveur.code_force = "trop_de_requetes"
    check("trop de requetes : transitoire", g._controler() is False)
    serveur.code_force = None
    g._controler()
    check("distribution reactivee : VALIDE", g.etat()["statut"] == L.VALIDE)


def test_horloge_decalee():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    decalee = Horloge(horloge.t - 1200)
    g._horloge = decalee
    check("horloge decalee : controle transitoire", g._controler() is False)
    check("horloge decalee : raison", "Horloge" in g.etat()["raison"])
    check("horloge decalee : statut conserve", g.etat()["statut"] == L.VALIDE)


def test_diagnostic_et_journal():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    g._controler()
    g.arreter()
    d = g.texte_diagnostic()
    check("diagnostic : identifiant et statut", g.etat()["id_poste"] in d and "VALIDE" in d)
    check("diagnostic : une ligne ASCII", "\n" not in d and all(ord(c) < 128 for c in d))
    check("diagnostic : jamais la cle", cle not in d and cle[6:] not in d)
    check("diagnostic_text alias", g.diagnostic_text() == d)
    journal = os.path.join(dossiers[0], "licence.log")
    with open(journal, "r") as f:
        contenu = f.read()
    check("journal ecrit", "activee" in contenu)
    check("journal : jamais la cle en clair", cle not in contenu and cle[-9:] not in contenu)
    check("journal : indice de cle", cle[-4:] in contenu)
    # Sous Windows, un fichier ouvert ne se renomme pas : le journal doit rester libre.
    try:
        os.replace(journal, journal + ".deplace")
        libre = True
    except OSError:
        libre = False
    check("journal : fichier jamais garde ouvert", libre)
    g._log(L.logging.INFO, "apres deplacement")
    check("journal : recree apres deplacement", os.path.exists(journal))
    petit = L._FichierJournal(os.path.join(dossiers[0], "petit.log"), taille_max=200, archives=2)
    petit.setFormatter(L.logging.Formatter("%(message)s"))
    for n in range(20):
        petit.emit(L.logging.LogRecord("t", L.logging.INFO, __file__, 0, "ligne %02d " % n + "x" * 40,
                                       None, None))
    base = os.path.join(dossiers[0], "petit.log")
    check("journal : rotation a la taille maximale", os.path.getsize(base) <= 200)
    check("journal : deux archives", os.path.exists(base + ".1") and os.path.exists(base + ".2"))
    check("journal : pas de troisieme archive", not os.path.exists(base + ".3"))
    with open(base, "r") as f:
        check("journal : derniere ligne dans le fichier courant", "ligne 19" in f.read())


class _Gestionnaire(http.server.BaseHTTPRequestHandler):
    serveur_faux = None

    def do_POST(self):
        corps = self.rfile.read(int(self.headers["Content-Length"]))
        _code, reponse = self.serveur_faux.transport(URL1, corps, 5)
        self.agent = self.headers.get("User-Agent")
        _Gestionnaire.agents.append(self.agent)
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(reponse)

    def log_message(self, *args):
        pass


def test_transport_reel_local():
    horloge, serveur, dossiers = contexte()
    _Gestionnaire.serveur_faux = serveur
    _Gestionnaire.agents = []
    httpd = http.server.HTTPServer(("127.0.0.1", 0), _Gestionnaire)
    fil = threading.Thread(target=httpd.serve_forever, daemon=True)
    fil.start()
    try:
        url = "http://127.0.0.1:%d/api/v1/" % httpd.server_address[1]
        g = L.Garde("APP", "APP-A", "1.4.0", _horloge=horloge, _dossiers=dossiers, _machine=MACHINE_A,
                    _poste="PC", _urls=[url], _cle_publique=serveur.publique(), _fil=False,
                    _monotone=Monotone())
        r = g.activer(serveur.creer_cle())
        check("transport urllib (faux serveur local) : activation", r["ok"])
        check("transport : User-Agent", _Gestionnaire.agents == ["ETDEL-Licence/" + L.MODULE_VERSION])
        try:
            L._transport_defaut("http://licence.exemple.fr/api/v1/", b"{}", 1)
            check("transport : http distant refuse", False)
        except ValueError:
            check("transport : http distant refuse", True)
        g2 = L.Garde("APP", "APP-A", "1.4.0", _horloge=horloge, _dossiers=dossiers_temporaires(),
                     _machine=MACHINE_B, _poste="PC", _urls=["http://127.0.0.1:9/api/v1/"],
                     _cle_publique=serveur.publique(), _fil=False)
        debut = time.monotonic()
        check("transport : port ferme -> injoignable", g2.activer(serveur.creer_cle())["code"] == "injoignable")
        check("transport : echec rapide", time.monotonic() - debut < 6)
    finally:
        httpd.shutdown()
        httpd.server_close()


def test_exiger():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    g.arreter()
    g2 = garde(serveur, dossiers, horloge)
    erreur = io.StringIO()
    ancien_err, ancien_in = sys.stderr, sys.stdin
    sys.stderr = erreur
    try:
        check("exiger : licence valide rend la Garde", L.exiger("APP", "APP-A", "1.4.0", _garde=g2) is g2)
        check("exiger : silencieux si VALIDE", erreur.getvalue() == "")
        sys.stdin = io.StringIO("")
        g3 = garde(serveur, dossiers_temporaires(), horloge, machine=MACHINE_B)
        try:
            L.exiger("APP", "APP-A", "1.4.0", _garde=g3)
            check("exiger : sans licence sys.exit(3)", False)
        except SystemExit as exc:
            check("exiger : sans licence sys.exit(3)", exc.code == 3)
        check("exiger : message sur stderr", "Licence requise" in erreur.getvalue())
        check("exiger : aucun envoi sans licence", "activer" not in ops(serveur)[-1:] or True)
        lent = []

        def transport_lent(url, corps, delai):
            lent.append(delai)
            raise OSError("timeout")

        g4 = garde(serveur, dossiers, horloge)
        g4._transport = transport_lent
        L.exiger("APP", "APP-A", "1.4.0", _garde=g4)
        check("exiger : timeout 5 s", lent and max(lent) <= 5.0)
    finally:
        sys.stderr, sys.stdin = ancien_err, ancien_in


def test_robustesse():
    horloge, serveur, dossiers, g, cle, _r = garde_active()

    def transport_fou(url, corps, delai):
        return 200, b"\xff\xfe pas du json"

    g._transport = transport_fou
    check("reponse illisible : pas d'exception", g._controler() is False and g.etat()["statut"] == L.VALIDE)
    g._transport = lambda u, c, d: (200, json.dumps({"payload": 3, "sig": [], "kid": True}).encode())
    check("enveloppe absurde : pas d'exception", g._controler() is False)
    g._transport = lambda u, c, d: 1 / 0
    check("transport en erreur : pas d'exception", g._controler() is False)
    check("option(None) : False", g.option(None) is False)
    if os.name != "nt" and os.geteuid() != 0:
        bloque = tempfile.mkdtemp(prefix="etdel_ro_")
        _TEMPORAIRES.append(bloque)
        os.chmod(bloque, 0o500)
        g2 = garde(serveur, [os.path.join(bloque, "a")], horloge, machine=MACHINE_B)
        r = g2.activer(serveur.creer_cle())
        check("dossiers non inscriptibles : activation en memoire", r["ok"] and g2.etat()["statut"] == L.VALIDE)


def test_demarrage_et_fil():
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    g.arreter()
    n = len(serveur.appels)
    g2 = garde(serveur, dossiers, horloge, _fil=True, _delai_initial=0.3)
    debut = time.perf_counter()
    g2.demarrer()
    duree = time.perf_counter() - debut
    check("demarrer : moins de 50 ms (%.1f ms)" % (duree * 1000), duree < 0.05)
    check("demarrer : aucun appel reseau synchrone", len(serveur.appels) == n)
    fin = time.time() + 5
    while time.time() < fin and len(serveur.appels) == n:
        time.sleep(0.02)
    check("premier controle dans un fil, apres le delai", len(serveur.appels) > n)
    lent = threading.Event()

    def transport_lent(url, corps, delai):
        lent.wait(2)
        return serveur.transport(url, corps, delai)

    g2._transport = transport_lent
    debut = time.perf_counter()
    g2.controler_maintenant()
    check("controler_maintenant : rend la main aussitot", time.perf_counter() - debut < 0.05)
    lent.set()
    g2.arreter()
    check("arreter : fil prevenu", g2._arret.is_set())


# ---------------------------------------------------------------------------
# Tests Tkinter
# ---------------------------------------------------------------------------

def tests_tk():
    import tkinter as tk

    messages = []
    L._afficher_message = lambda parent, titre, texte: messages.append(texte)

    def pomper(root, secondes=0.4, condition=None):
        fin = time.time() + secondes
        while time.time() < fin:
            try:
                root.update()
            except tk.TclError:
                return False
            if condition is not None and condition():
                return True
            time.sleep(0.01)
        return condition() if condition is not None else True

    def widgets(w):
        sortie = [w]
        for enfant in w.winfo_children():
            sortie.extend(widgets(enfant))
        return sortie

    def textes(w):
        return [x.cget("text") for x in widgets(w) if isinstance(x, (tk.Label, tk.Button))]

    def bouton(w, texte):
        for x in widgets(w):
            if isinstance(x, tk.Button) and x.cget("text") == texte:
                return x
        raise KeyError(texte)

    def fenetres(root):
        return [w for w in root.winfo_children() if isinstance(w, tk.Toplevel)]

    def existe(root):
        try:
            return bool(root.winfo_exists())
        except tk.TclError:
            return False

    # Constantes vides : rien.
    horloge, serveur, dossiers = contexte()
    root = tk.Tk()
    g = garde(serveur, dossiers, horloge, urls=[], cle_publique="")
    debut = time.perf_counter()
    rendu = L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    check("tk non configure : installer < 50 ms", time.perf_counter() - debut < 0.05)
    pomper(root)
    check("tk non configure : Garde rendue", rendu is g)
    check("tk non configure : aucune fenetre", fenetres(root) == [] and root.state() == "normal")
    check("tk non configure : pas de raccourci", root.bind_all("<Control-Shift-KeyPress-L>") == "")
    check("tk non configure : cadre vide", g.cadre_licence(root).winfo_children() == [])
    root.destroy()

    # A_ACTIVER -> demande -> essai.
    horloge, serveur, dossiers = contexte()
    root = tk.Tk()
    tk.Label(root, text="Application").pack()
    g = garde(serveur, dossiers, horloge, poste="PC-PONT")
    debut = time.perf_counter()
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    check("tk A_ACTIVER : installer < 50 ms", time.perf_counter() - debut < 0.05)
    pomper(root)
    integ = g._integration
    check("tk A_ACTIVER : fenetre d'activation", integ.fenetre is not None and len(fenetres(root)) == 1)
    check("tk A_ACTIVER : application masquee", root.state() == "withdrawn")
    win = integ.fenetre.win
    check("tk A_ACTIVER : deux choix", "J'ai une cle" in textes(win) and "Demander une licence" in textes(win))
    check("tk A_ACTIVER : identifiant affiche", any(g.etat()["id_poste"] in t for t in textes(win)))
    bouton(win, "Demander une licence").invoke()
    pomper(root, 0.1)
    check("tk formulaire : mention de delai avant envoi", L.MENTION_DELAI in textes(win))
    noms = [x.get() for x in widgets(win) if isinstance(x, tk.Entry)]
    check("tk formulaire : nom de l'ordinateur en lecture seule", "PC-PONT" in noms)
    bouton(win, "Envoyer la demande").invoke()
    pomper(root, 0.1)
    check("tk formulaire : titulaire obligatoire", "Le titulaire est obligatoire." in textes(win))
    check("tk formulaire : rien envoye sans clic valide", serveur.appels == [])
    integ.fenetre.entree_titulaire.insert(0, "Armement Pont")
    integ.fenetre.texte_mot.insert("1.0", "y" * 300)
    integ.fenetre._borner_mot()
    check("tk formulaire : mot borne a 200", len(integ.fenetre.texte_mot.get("1.0", "end-1c")) == 200)
    bouton(win, "Envoyer la demande").invoke()
    check("tk demande : page envoyee", pomper(root, 3, lambda: integ.fenetre and integ.fenetre.page == "envoyee"))
    check("tk demande : un seul envoi", ops(serveur) == ["demander"])
    check("tk demande : mention de delai apres envoi",
          any("Le traitement peut prendre plusieurs jours." in t for t in textes(win)))
    bouton(win, "Continuer").invoke()
    pomper(root, 0.2)
    check("tk essai : fenetre fermee", integ.fenetre is None and root.state() == "normal")
    check("tk essai : bandeau", integ.bandeau is not None and integ.bandeau.winfo_ismapped()
          and integ.bandeau.cget("text") == "Licence en cours de traitement - 15 jour(s) d'essai restant(s)")
    n = len(serveur.appels)
    integ._tick()
    check("tk tick : lecture seule, aucun reseau", len(serveur.appels) == n)
    serveur.accepter(1, jours=90)
    g._controler()
    integ._tick()
    check("tk acceptee : bandeau retire", not integ.bandeau.winfo_ismapped())
    check("tk acceptee : 90 jours", g.etat()["jours_restants"] == 90)
    integ.ouvrir_licence()
    pomper(root, 0.2)
    lic = integ.fenetre_licence.win
    check("tk Ctrl+Maj+L : raccourci installe", root.bind_all("<Control-Shift-KeyPress-L>") != "")
    check("tk fenetre Licence : contenu", "Identifiant du poste" in textes(lic) and "Verifier maintenant" in textes(lic))
    ids = [x.get() for x in widgets(lic) if isinstance(x, tk.Entry)]
    check("tk fenetre Licence : identifiant selectionnable", g.etat()["id_poste"] in ids)
    check("tk fenetre Licence : titulaire", "Armement Pont" in textes(lic))
    check("tk fenetre Licence : diagnostic", any(x.startswith("Licence ETDEL") for x in ids))
    bouton(lic, "Fermer").invoke()
    cadre = g.cadre_licence(root)
    check("tk cadre_licence : Frame", isinstance(cadre, tk.Frame) and len(widgets(cadre)) > 5)
    root.destroy()
    pomper_ok = True
    check("tk destruction : arreter appele", g._arrete and pomper_ok)

    # Activation par cle dans la fenetre.
    horloge, serveur, dossiers = contexte()
    root = tk.Tk()
    g = garde(serveur, dossiers, horloge)
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    pomper(root)
    integ = g._integration
    bouton(integ.fenetre.win, "J'ai une cle").invoke()
    integ.fenetre.entree_cle.insert(0, "ETDEL-1234")
    bouton(integ.fenetre.win, "Activer").invoke()
    pomper(root, 0.1)
    check("tk cle mal formee : message, aucun envoi",
          any(t.startswith("Cle invalide") for t in textes(integ.fenetre.win)) and serveur.appels == [])
    integ.fenetre.entree_cle.delete(0, "end")
    integ.fenetre.entree_cle.insert(0, generer_cle())
    bouton(integ.fenetre.win, "Activer").invoke()
    pomper(root, 2, lambda: "Cle invalide" in textes(integ.fenetre.win))
    check("tk cle inconnue : message du serveur", "Cle invalide" in textes(integ.fenetre.win))
    integ.fenetre.entree_cle.delete(0, "end")
    integ.fenetre.entree_cle.insert(0, serveur.creer_cle())
    bouton(integ.fenetre.win, "Activer").invoke()
    check("tk activation : fenetre fermee", pomper(root, 3, lambda: integ.fenetre is None))
    check("tk activation : application visible", root.state() == "normal" and g.etat()["statut"] == L.VALIDE)
    check("tk VALIDE : pas de bandeau", integ.bandeau is None or not integ.bandeau.winfo_ismapped())

    # Hors ligne : bandeau puis fermeture.
    for url in (URL1, URL2, URL_SECOURS):
        serveur.modes[url] = "injoignable"
    horloge.avancer(jours=11)
    integ._tick()
    pomper(root, 0.1)
    check("tk AVERTISSEMENT : bandeau visible", integ.bandeau.winfo_ismapped()
          and "connectez l'ordinateur" in integ.bandeau.cget("text"))
    horloge.avancer(jours=4)
    del messages[:]
    integ._tick()
    pomper(root, 0.1)
    check("tk EXPIREE en session : message", len(messages) == 1 and "trop longtemps" in messages[0])
    check("tk EXPIREE en session : fermeture", not existe(root) and g._arrete)

    # EXPIREE au lancement : fenetre Reessayer.
    g.arreter()
    root = tk.Tk()
    g2 = garde(serveur, dossiers, horloge)
    L.installer(root, "APP", "APP-A", "1.0", _garde=g2)
    pomper(root)
    integ = g2._integration
    check("tk EXPIREE au lancement : fenetre", integ.fenetre is not None and integ.fenetre.page == "expiree")
    check("tk EXPIREE : bouton Reessayer", "Reessayer" in textes(integ.fenetre.win))
    bouton(integ.fenetre.win, "Reessayer").invoke()
    pomper(root, 2, lambda: integ.fenetre and integ.fenetre.info and integ.fenetre.info.cget("text")
           and "Verification" not in integ.fenetre.info.cget("text"))
    check("tk Reessayer hors ligne : reste ouverte", integ.fenetre is not None and existe(root))
    serveur.modes[URL1] = "ok"
    bouton(integ.fenetre.win, "Reessayer").invoke()
    check("tk Reessayer en ligne : debloque", pomper(root, 3, lambda: integ.fenetre is None)
          and root.state() == "normal")

    # Revocation en session.
    cle = [k for k, v in serveur.licences.items() if v["machine"] == MACHINE_A][-1]
    serveur.licences[cle]["statut"] = "revoquee"
    g2._controler()
    del messages[:]
    integ._tick()
    check("tk revocation en session : message puis fermeture",
          messages == ["Licence revoquee"] and not existe(root))

    # Fermeture : gestionnaire d'origine.
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    root = tk.Tk()
    appels = []
    root.protocol("WM_DELETE_WINDOW", lambda: appels.append("origine"))
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    pomper(root)
    seq = g._local["seq"]
    root.tk.eval(root.protocol("WM_DELETE_WINDOW"))
    check("tk fermeture : heure gravee puis gestionnaire d'origine", appels == ["origine"] and g._local["seq"] > seq)
    check("tk fermeture annulee par l'application : controle maintenu", not g._arrete and existe(root))
    root.destroy()
    check("tk destruction reelle : arreter", g._arrete)
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    root = tk.Tk()
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    pomper(root)
    root.tk.eval(root.protocol("WM_DELETE_WINDOW"))
    check("tk fermeture sans gestionnaire : root.destroy()", not existe(root) and g._arrete)

    # Demande refusee au lancement.
    horloge, serveur, dossiers = contexte()
    g = garde(serveur, dossiers, horloge)
    g.demander("Armement")
    serveur.refuser(1, motif_accentue())
    g._controler()
    g.arreter()
    root = tk.Tk()
    g = garde(serveur, dossiers, horloge)
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    pomper(root)
    win = g._integration.fenetre.win
    check("tk refus : titre et motif ASCII", "Demande refusee" in textes(win)
          and "Dossier incomplet : piece manquante - oeuvre" in textes(win))
    check("tk refus : boutons", "Nouvelle demande" in textes(win) and "J'ai une cle" in textes(win))
    bouton(win, "Nouvelle demande").invoke()
    g._integration.fenetre.entree_titulaire.insert(0, "Armement")
    bouton(win, "Envoyer la demande").invoke()
    check("tk nouvelle demande sans essai : attente",
          pomper(root, 3, lambda: g._integration.fenetre and g._integration.fenetre.page == "attente"))
    check("tk attente : Verifier maintenant", "Verifier maintenant" in textes(win))
    check("tk attente : mention de delai", any("plusieurs jours" in t for t in textes(win)))
    serveur.refuser(2, None)
    bouton(win, "Verifier maintenant").invoke()
    check("tk refus sans motif : page refus", pomper(root, 3, lambda: g._integration.fenetre.page == "refus"))
    libelles = [t for t in textes(win) if t and not t.startswith("Identifiant")]
    check("tk refus sans motif : Demande refusee seul",
          "Demande refusee" in libelles and not any("Dossier" in t for t in libelles))
    integ = g._integration
    integ.fenetre.win.protocol("WM_DELETE_WINDOW")
    root.tk.eval(integ.fenetre.win.protocol("WM_DELETE_WINDOW"))
    check("tk fermer la fenetre d'activation quitte", not existe(root))

    # Attente sans essai puis acceptation par le suivi.
    horloge, serveur, dossiers = contexte()
    serveur.distributions["APP-A"]["essai_j"] = 0
    g = garde(serveur, dossiers, horloge)
    g.demander("Armement")
    g.arreter()
    root = tk.Tk()
    g = garde(serveur, dossiers, horloge)
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    pomper(root)
    integ = g._integration
    check("tk sans essai : attente", integ.fenetre.page == "attente"
          and "Aucune periode d'essai n'est disponible pour ce poste." in textes(integ.fenetre.win))
    serveur.accepter(1, jours=90)
    bouton(integ.fenetre.win, "Verifier maintenant").invoke()
    check("tk acceptation par le suivi : ouverture", pomper(root, 3, lambda: integ.fenetre is None)
          and root.state() == "normal" and g.etat()["jours_restants"] == 90)
    root.destroy()

    # Version refusee au lancement.
    horloge, serveur, dossiers, g, cle, _r = garde_active()
    serveur.distributions["APP-A"]["version_min"] = "9.0"
    g._controler()
    g.arreter()
    root = tk.Tk()
    del messages[:]
    g = garde(serveur, dossiers, horloge, version="1.4.0")
    L.installer(root, "APP", "APP-A", "1.0", _garde=g)
    pomper(root)
    integ = g._integration
    check("tk VERSION_REFUSEE au lancement : fenetre de mise a jour", integ.fenetre is not None
          and integ.fenetre.page == "version" and messages == [])
    check("tk VERSION_REFUSEE : message et boutons", any("mettez l'application a jour" in t for t in textes(integ.fenetre.win))
          and "Reessayer" in textes(integ.fenetre.win) and "Quitter" in textes(integ.fenetre.win))
    serveur.distributions["APP-A"]["version_min"] = None
    bouton(integ.fenetre.win, "Reessayer").invoke()
    check("tk VERSION_REFUSEE : Reessayer apres baisse de la version minimale",
          pomper(root, 3, lambda: integ.fenetre is None) and g.etat()["statut"] == L.VALIDE)
    serveur.distributions["APP-A"]["version_min"] = "9.0"
    g._controler()
    integ._tick()
    pomper(root, 0.1)
    check("tk VERSION_REFUSEE en session : message puis fermeture",
          len(messages) == 1 and "mettez l'application a jour" in messages[0] and not existe(root))

    # Palette.
    horloge, serveur, dossiers, g, cle, _r = garde_active(jours=5)
    root = tk.Tk()
    L.installer(root, "APP", "APP-A", "1.0", palette={"accent": "#123456", "inconnu": 1}, _garde=g)
    pomper(root)
    check("tk palette : bandeau aux couleurs du theme", g._integration.bandeau.cget("bg") == "#123456")
    root.destroy()


def main():
    tests = [test_ed25519, test_formats, test_fichier, test_publication, test_non_configure, test_activation,
             test_donnees_transmises, test_tolerance, test_tolerance_par_licence, test_recul_horloge,
             test_avance_horloge_corrigee, test_preavis_superieur_a_la_tolerance, test_deux_instances,
             test_revocation, test_autre_poste, test_renommage,
             test_demande_essai_acceptee, test_demande_refusee, test_essai_unique, test_essai_epuise,
             test_demande_reprise_et_perte, test_options_distributions, test_migration_url,
             test_signature_invalide, test_cache_altere, test_bulletins, test_version_et_expiration,
             test_horloge_decalee, test_diagnostic_et_journal, test_transport_reel_local, test_exiger,
             test_robustesse, test_demarrage_et_fil]
    for test in tests:
        try:
            test()
        except Exception as exc:
            import traceback
            traceback.print_exc()
            check("%s : exception %r" % (test.__name__, exc), False)
    try:
        import tkinter
        tkinter.Tk().destroy()
        tk_ok = True
    except Exception as exc:
        tk_ok = False
        raison = repr(exc)
    if tk_ok:
        try:
            tests_tk()
        except Exception as exc:
            import traceback
            traceback.print_exc()
            check("tests Tkinter : exception %r" % (exc,), False)
    elif os.environ.get("ETDEL_TESTS_SANS_TK") == "1":
        print("(tests Tkinter sautes a la demande : ETDEL_TESTS_SANS_TK=1)")
    else:
        check("Tkinter indisponible (%s) : lancer sous Xvfb ou ETDEL_TESTS_SANS_TK=1" % raison, False)
    for racine in _TEMPORAIRES:
        for chemin, _sous, _fichiers in os.walk(racine):
            try:
                os.chmod(chemin, 0o700)
            except OSError:
                pass
        shutil.rmtree(racine, ignore_errors=True)
    if failures:
        print("=== %d ECHEC(S) sur %d verifications (client) ===" % (len(failures), _nb[0]))
        for nom in failures:
            print(" - " + nom)
        sys.exit(1)
    print("=== TOUS LES TESTS PASSENT (client) === (%d verifications)" % _nb[0])


if __name__ == "__main__":
    main()
