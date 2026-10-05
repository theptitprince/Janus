# etdel_licence.py
# Role : module client de licence ETDEL. Controle en ligne, cache local chiffre
#        en trois exemplaires, tolerance hors ligne, composants Tkinter.
#        Copie a l'identique dans chaque application : seules les trois
#        constantes LICENCE_* sont renseignees, une fois, apres l'installation
#        du serveur ; tout ce qui est propre a une application passe en
#        parametres de installer() ou exiger().
# ETDEL (c) 2026
"""Module de licence ETDEL.

Integration Tkinter (cas normal), juste apres la creation de root :

    import etdel_licence
    etdel_licence.installer(root, produit="MONAPPLI",
                            distribution="MONAPPLI-CLIENTA", version=APP_VERSION)

Application console :

    etdel_licence.exiger(produit="MONAPPLI", distribution="MONAPPLI-CLIENTA",
                         version=APP_VERSION)

Bibliotheque standard uniquement. Aucune fonction publique ne leve
d'exception vers l'application : les erreurs sont journalisees et traduites
en statut.
"""

import atexit
import base64
import hashlib
import hmac
import json
import logging
import logging.handlers
import math
import os
import queue
import socket
import ssl
import sys
import threading
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request

MODULE_VERSION = "1.0.0"

# Renseignees une fois le serveur installe (ecran Cles de la console).
# Vides : aucun controle, aucun fichier, l'application ne parle jamais de licence.
LICENCE_URL = ""
LICENCE_URL_SECOURS = ""
LICENCE_CLE_PUBLIQUE = ""

NON_CONFIGURE = "NON_CONFIGURE"
A_ACTIVER = "A_ACTIVER"
ESSAI = "ESSAI"
DEMANDE_EN_ATTENTE = "DEMANDE_EN_ATTENTE"
DEMANDE_REFUSEE = "DEMANDE_REFUSEE"
VALIDE = "VALIDE"
AVERTISSEMENT = "AVERTISSEMENT"
EXPIREE = "EXPIREE"
REVOQUEE = "REVOQUEE"
VERSION_REFUSEE = "VERSION_REFUSEE"

STATUTS_UTILISABLES = (VALIDE, AVERTISSEMENT, ESSAI)
_STATUTS_FENETRE = (A_ACTIVER, DEMANDE_EN_ATTENTE, DEMANDE_REFUSEE, EXPIREE, VERSION_REFUSEE)
# cle_invalide en validation : la cle n'existe plus sur le serveur, on la
# traite comme une revocation plutot que de reessayer indefiniment.
_CODES_REVOCATION = ("revoquee", "suspendue", "poste_revoque",
                     "cle_liee_autre_poste", "cle_invalide")
# Refus definitifs qui bloquent sans effacer la cle : une prolongation ou une
# reactivation dans la console suffit a debloquer au controle suivant.
_CODES_BLOCAGE = ("expiree", "version_trop_ancienne", "produit_inconnu")

JOUR = 86400
_DELAI_PREMIER_CONTROLE = 3.0
_INTERVALLE_CONTROLE = 6 * 3600
_INTERVALLE_ECHEC = 15 * 60
_INTERVALLE_ATTENTE = 60
_TICK_MS = 25000
_TIMEOUT = 5.0
_RECUL_TOLERE = 48 * 3600
_PREAVIS_ECHEANCE_J = 15
_CONSERVATION_ANCIENNE_CLE = 90 * JOUR
_MESSAGE_MAX = 200
_TITULAIRE_MAX = 120
_EMAIL_MAX = 254
_ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
_CORRECTIONS = str.maketrans({"O": "0", "I": "1", "L": "1"})

MENTION_DELAI = "Le traitement d'une demande peut prendre plusieurs jours."

_MESSAGES = {
    "cle_invalide": "Cle invalide",
    "expiree": "Licence expiree. Contactez ETDEL pour la renouveler.",
    "revoquee": "Licence revoquee",
    "suspendue": "Licence suspendue",
    "poste_revoque": "Ce poste n'est plus autorise pour cette licence",
    "cle_liee_autre_poste": "Cle deja utilisee sur un autre ordinateur",
    "version_trop_ancienne": "Version trop ancienne : mettez l'application a jour",
    "produit_inconnu": "Produit ou distribution inconnu du serveur de licences",
    "demande_en_cours": "Une demande est deja en attente pour ce poste",
    "demande_inconnue": "Demande inconnue du serveur de licences",
    "horloge": "Horloge de l'ordinateur incorrecte : corrigez la date et l'heure",
    "trop_de_requetes": "Trop de tentatives : reessayez plus tard",
    "requete_invalide": "Requete refusee par le serveur de licences",
    "injoignable": "Serveur de licences injoignable : verifiez la connexion Internet",
    "non_configure": "Licence non configuree",
    "interne": "Erreur interne du module de licence",
}


# ---------------------------------------------------------------------------
# Outils
# ---------------------------------------------------------------------------

def _b64url(octets):
    return base64.urlsafe_b64encode(octets).rstrip(b"=").decode("ascii")


_B64URL = set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_")


def _deb64url(texte):
    # b64decode ignore silencieusement les caracteres hors alphabet : on les
    # refuse explicitement pour qu'un jeton altere ne passe jamais.
    if not isinstance(texte, str) or any(c not in _B64URL for c in texte):
        raise ValueError("base64url invalide")
    return base64.urlsafe_b64decode(texte + "=" * (-len(texte) % 4))


def _entier(valeur):
    """Entier JSON strict (True n'est pas un entier ici)."""
    return isinstance(valeur, int) and not isinstance(valeur, bool)


def normaliser_cle(texte):
    """Renvoie la cle sous la forme ETDEL-XXXX-XXXX-XXXX-XXXX, ou None.

    Saisie insensible a la casse et aux tirets ; O, I et L sont lus 0, 1, 1
    (base32 de Crockford) pour pardonner les confusions de dictee.
    """
    if not isinstance(texte, str):
        return None
    brut = "".join(c for c in texte.upper() if c not in "- \t\r\n")
    if len(brut) == 21 and brut.startswith("ETDEL"):
        brut = brut[5:]
    if len(brut) != 16:
        return None
    brut = brut.translate(_CORRECTIONS)
    if any(c not in _ALPHABET for c in brut):
        return None
    if _ALPHABET[sum(_ALPHABET.index(c) for c in brut[:15]) % 32] != brut[15]:
        return None
    return "ETDEL-" + "-".join(brut[i:i + 4] for i in range(0, 16, 4))


def _id_poste(machine, produit):
    """Forme courte de l'empreinte (40 bits), assez courte pour la radio."""
    n = int.from_bytes(hashlib.sha256((machine + produit).encode("utf-8")).digest()[:5], "big")
    s = "".join(_ALPHABET[(n >> (35 - 5 * i)) & 31] for i in range(8))
    return s[:4] + "-" + s[4:]


def _version_tuple(version):
    parties = []
    for morceau in str(version or "").strip().split("."):
        chiffres = ""
        for c in morceau:
            if c not in "0123456789":
                break
            chiffres += c
        parties.append(int(chiffres) if chiffres else 0)
    while len(parties) > 1 and parties[-1] == 0:
        parties.pop()
    return parties


def _comparer_versions(a, b):
    x, y = _version_tuple(a), _version_tuple(b)
    n = max(len(x), len(y))
    x, y = x + [0] * (n - len(x)), y + [0] * (n - len(y))
    return (x > y) - (x < y)


# Codes et non caracteres : le fichier doit rester en ASCII pur.
_TRANSLITTERATION = {
    chr(0x153): "oe", chr(0x152): "OE", chr(0xE6): "ae", chr(0xC6): "AE", chr(0xDF): "ss",
    chr(0x2018): "'", chr(0x2019): "'", chr(0x201C): '"', chr(0x201D): '"', chr(0xAB): '"',
    chr(0xBB): '"', chr(0x2013): "-", chr(0x2014): "-", chr(0x2026): "...", chr(0xA0): " ",
}


def _ascii(texte):
    """Translittere en ASCII affichable (motifs de refus saisis avec accents)."""
    if texte is None:
        return ""
    texte = "".join(_TRANSLITTERATION.get(c, c) for c in str(texte))
    texte = unicodedata.normalize("NFKD", texte).encode("ascii", "ignore").decode("ascii")
    return "".join(c for c in texte if c == "\n" or 32 <= ord(c) < 127)


def _nettoyer(texte, longueur, multiligne=False):
    if not isinstance(texte, str):
        texte = "" if texte is None else str(texte)
    garder = "\n" if multiligne else ""
    texte = "".join(c for c in texte if c in garder or unicodedata.category(c)[0] != "C")
    return texte.strip()[:longueur]


def _date(ts):
    return time.strftime("%d/%m/%Y", time.localtime(ts)) if ts else ""


def _date_heure(ts):
    return time.strftime("%d/%m/%Y %H:%M", time.localtime(ts)) if ts else ""


def _jours_restants(fin, maintenant):
    return max(0, int(math.ceil((fin - maintenant) / float(JOUR))))


def _url_autorisee(url):
    try:
        morceaux = urllib.parse.urlsplit(url)
        hote = morceaux.hostname
    except (ValueError, AttributeError):
        return False
    if morceaux.scheme == "https" and hote:
        return True
    # HTTP clair tolere uniquement en boucle locale : banc d'essai avec php -S.
    return morceaux.scheme == "http" and hote in ("127.0.0.1", "localhost", "::1")


def _hote(url):
    try:
        return urllib.parse.urlsplit(url).hostname or url
    except ValueError:
        return url


# ---------------------------------------------------------------------------
# Ed25519 (RFC 8032), verification en Python pur
# ---------------------------------------------------------------------------

_P = 2 ** 255 - 19
_Q = 2 ** 252 + 27742317777372353535851937790883648493
_D = -121665 * pow(121666, _P - 2, _P) % _P
_RACINE_M1 = pow(2, (_P - 1) // 4, _P)
_NEUTRE = (0, 1, 1, 0)


def _ed_addition(a, b):
    # Coordonnees etendues (RFC 8032, 5.1.4) : aucune inversion dans la boucle.
    xa = (a[1] - a[0]) * (b[1] - b[0]) % _P
    xb = (a[1] + a[0]) * (b[1] + b[0]) % _P
    xc = 2 * a[3] * b[3] * _D % _P
    xd = 2 * a[2] * b[2] % _P
    e, f, g, h = xb - xa, xd - xc, xd + xc, xb + xa
    return (e * f % _P, g * h % _P, f * g % _P, e * h % _P)


def _ed_double(a):
    xa = a[0] * a[0] % _P
    xb = a[1] * a[1] % _P
    xc = 2 * a[2] * a[2] % _P
    h = xa + xb
    e = h - (a[0] + a[1]) * (a[0] + a[1])
    g = xa - xb
    f = xc + g
    return (e * f % _P, g * h % _P, f * g % _P, e * h % _P)


def _ed_multiplier(s, point):
    resultat = _NEUTRE
    for i in range(s.bit_length() - 1, -1, -1):
        resultat = _ed_double(resultat)
        if (s >> i) & 1:
            resultat = _ed_addition(resultat, point)
    return resultat


def _ed_multiplier2(s, p1, t, p2):
    # Astuce de Shamir : [s]P1 + [t]P2 en une passe, ~40 % plus rapide que
    # deux multiplications, ce qui compte pour le demarrage en moins de 50 ms.
    somme = _ed_addition(p1, p2)
    resultat = _NEUTRE
    for i in range(max(s.bit_length(), t.bit_length()) - 1, -1, -1):
        resultat = _ed_double(resultat)
        bs, bt = (s >> i) & 1, (t >> i) & 1
        if bs and bt:
            resultat = _ed_addition(resultat, somme)
        elif bs:
            resultat = _ed_addition(resultat, p1)
        elif bt:
            resultat = _ed_addition(resultat, p2)
    return resultat


def _ed_egal(a, b):
    return (a[0] * b[2] - b[0] * a[2]) % _P == 0 and (a[1] * b[2] - b[1] * a[2]) % _P == 0


def _ed_retrouver_x(y, signe):
    if y >= _P:
        return None
    x2 = (y * y - 1) * pow(_D * y * y + 1, _P - 2, _P)
    if x2 % _P == 0:
        return None if signe else 0
    x = pow(x2, (_P + 3) // 8, _P)
    if (x * x - x2) % _P != 0:
        x = x * _RACINE_M1 % _P
    if (x * x - x2) % _P != 0:
        return None
    if (x & 1) != signe:
        x = _P - x
    return x


def _ed_decompresser(octets):
    if len(octets) != 32:
        return None
    y = int.from_bytes(octets, "little")
    signe = y >> 255
    y &= (1 << 255) - 1
    x = _ed_retrouver_x(y, signe)
    if x is None:
        return None
    return (x, y, 1, x * y % _P)


def _ed_compresser(point):
    zinv = pow(point[2], _P - 2, _P)
    x = point[0] * zinv % _P
    y = point[1] * zinv % _P
    return (y | ((x & 1) << 255)).to_bytes(32, "little")


_GY = 4 * pow(5, _P - 2, _P) % _P
_GX = _ed_retrouver_x(_GY, 0)
_G = (_GX, _GY, 1, _GX * _GY % _P)


def _ed_hash_modq(octets):
    return int.from_bytes(hashlib.sha512(octets).digest(), "little") % _Q


def _ed25519_verifier(publique, message, signature):
    """True si signature (64 octets) est valide pour message sous publique (32 octets)."""
    try:
        if len(publique) != 32 or len(signature) != 64:
            return False
        a = _ed_decompresser(publique)
        r = _ed_decompresser(signature[:32])
        if a is None or r is None:
            return False
        s = int.from_bytes(signature[32:], "little")
        if s >= _Q:
            return False
        h = _ed_hash_modq(signature[:32] + publique + message)
        moins_a = ((_P - a[0]) % _P, a[1], a[2], (_P - a[3]) % _P)
        # [s]B - [h]A doit valoir R.
        return _ed_egal(_ed_multiplier2(s, _G, h, moins_a), r)
    except Exception:
        return False


def _ed_developper(graine):
    h = hashlib.sha512(graine).digest()
    a = int.from_bytes(h[:32], "little")
    a &= (1 << 254) - 8
    a |= 1 << 254
    return a, h[32:]


def _ed25519_cle_publique(graine):
    """Utilise par les tests (faux serveur) : jamais de cle privee dans une application."""
    return _ed_compresser(_ed_multiplier(_ed_developper(graine)[0], _G))


def _ed25519_signer(graine, message):
    """Utilise par les tests (faux serveur) : jamais de cle privee dans une application."""
    a, prefixe = _ed_developper(graine)
    publique = _ed_compresser(_ed_multiplier(a, _G))
    r = _ed_hash_modq(prefixe + message)
    rs = _ed_compresser(_ed_multiplier(r, _G))
    s = (r + _ed_hash_modq(rs + publique + message) * a) % _Q
    return rs + s.to_bytes(32, "little")


# ---------------------------------------------------------------------------
# Poste : empreinte, nom, chiffrement DPAPI, stockage local
# ---------------------------------------------------------------------------

def _empreinte_machine():
    """SHA-256 de MachineGuid + numero de serie du volume systeme."""
    guid = ""
    serie = 0
    if sys.platform == "win32":
        try:
            import winreg
            # KEY_WOW64_64KEY : un Python 32 bits lirait sinon WOW6432Node,
            # ou MachineGuid n'existe pas.
            cle = winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography",
                                 0, winreg.KEY_READ | winreg.KEY_WOW64_64KEY)
            try:
                guid = str(winreg.QueryValueEx(cle, "MachineGuid")[0])
            finally:
                winreg.CloseKey(cle)
        except OSError:
            pass
        try:
            import ctypes
            numero = ctypes.c_uint32(0)
            racine = os.environ.get("SystemDrive", "C:") + "\\"
            if ctypes.windll.kernel32.GetVolumeInformationW(
                    ctypes.c_wchar_p(racine), None, 0, ctypes.byref(numero), None, None, None, 0):
                serie = numero.value
        except Exception:
            pass
    else:
        # Hors Windows (developpement, tests) : identifiant systemd.
        for chemin in ("/etc/machine-id", "/var/lib/dbus/machine-id"):
            try:
                with open(chemin, "r") as fichier:
                    guid = fichier.read().strip()
                break
            except OSError:
                pass
    if not guid and not serie:
        guid = "hote:" + socket.gethostname()
    source = "%s|%08X" % (guid.strip().lower(), serie)
    return hashlib.sha256(source.encode("utf-8")).hexdigest()


def _nom_ordinateur():
    return _nettoyer(os.environ.get("COMPUTERNAME") or socket.gethostname(), 64)


def _dpapi(donnees, entropie, proteger):
    import ctypes
    from ctypes import wintypes

    class _Blob(ctypes.Structure):
        _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_char))]

    def blob(octets):
        tampon = ctypes.create_string_buffer(octets, len(octets))
        return _Blob(len(octets), ctypes.cast(tampon, ctypes.POINTER(ctypes.c_char))), tampon

    entree, _t1 = blob(donnees)
    sel, _t2 = blob(entropie)
    sortie = _Blob()
    crypt32 = ctypes.windll.crypt32
    fonction = crypt32.CryptProtectData if proteger else crypt32.CryptUnprotectData
    # 0x01 = CRYPTPROTECT_UI_FORBIDDEN : jamais de fenetre systeme.
    if not fonction(ctypes.byref(entree), None, ctypes.byref(sel), None, None, 0x01,
                    ctypes.byref(sortie)):
        raise OSError("DPAPI a echoue")
    try:
        return ctypes.string_at(sortie.pbData, sortie.cbData)
    finally:
        ctypes.windll.kernel32.LocalFree(ctypes.cast(sortie.pbData, ctypes.c_void_p))


def _flux(cle, nonce, longueur):
    blocs = []
    compteur = 0
    while sum(len(b) for b in blocs) < longueur:
        blocs.append(hashlib.sha256(cle + nonce + compteur.to_bytes(8, "big")).digest())
        compteur += 1
    return b"".join(blocs)[:longueur]


def _xor(donnees, flux):
    return bytes(a ^ b for a, b in zip(donnees, flux))


class _Stockage(object):
    """Etat local en plusieurs exemplaires, chiffre (DPAPI) et scelle (HMAC)."""

    MAGIE = b"ETDL\x01"

    def __init__(self, chemins, secret):
        self.chemins = list(chemins)
        self._sceau = hmac.new(secret, b"sceau", hashlib.sha256).digest()
        self._flux = hmac.new(secret, b"flux", hashlib.sha256).digest()
        self._sel = hmac.new(secret, b"dpapi", hashlib.sha256).digest()

    def _chiffrer(self, clair):
        if sys.platform == "win32":
            try:
                return b"D", _dpapi(clair, self._sel, True)
            except Exception:
                pass
        nonce = os.urandom(16)
        return b"X", nonce + _xor(clair, _flux(self._flux, nonce, len(clair)))

    def _dechiffrer(self, methode, corps):
        if methode == b"D":
            if sys.platform != "win32":
                return None
            return _dpapi(corps, self._sel, False)
        if methode == b"X" and len(corps) >= 16:
            nonce, chiffre = corps[:16], corps[16:]
            return _xor(chiffre, _flux(self._flux, nonce, len(chiffre)))
        return None

    def encoder(self, contenu):
        clair = json.dumps(contenu, separators=(",", ":"), sort_keys=True).encode("utf-8")
        methode, corps = self._chiffrer(clair)
        entete = self.MAGIE + methode
        sceau = hmac.new(self._sceau, entete + corps, hashlib.sha256).digest()
        return entete + sceau + corps

    def decoder(self, donnees):
        try:
            if len(donnees) < 38 or not donnees.startswith(self.MAGIE):
                return None
            entete, sceau, corps = donnees[:6], donnees[6:38], donnees[38:]
            attendu = hmac.new(self._sceau, entete + corps, hashlib.sha256).digest()
            if not hmac.compare_digest(sceau, attendu):
                return None
            clair = self._dechiffrer(entete[5:6], corps)
            if clair is None:
                return None
            contenu = json.loads(clair.decode("utf-8"))
            return contenu if isinstance(contenu, dict) else None
        except Exception:
            return None

    def lire(self):
        lus = []
        for chemin in self.chemins:
            try:
                with open(chemin, "rb") as fichier:
                    donnees = fichier.read(1048576)
            except OSError:
                continue
            contenu = self.decoder(donnees)
            if contenu is not None and _entier(contenu.get("seq")):
                lus.append((contenu["seq"], chemin, donnees, contenu))
        if not lus:
            return None
        lus.sort(key=lambda v: v[0], reverse=True)
        _seq, _chemin, donnees, contenu = lus[0]
        a_jour = set(v[1] for v in lus if v[2] == donnees)
        # Les exemplaires absents, alteres ou anciens sont reecrits avec le plus recent.
        self._ecrire(donnees, [c for c in self.chemins if c not in a_jour])
        return contenu

    def ecrire(self, contenu):
        return self._ecrire(self.encoder(contenu), self.chemins)

    @staticmethod
    def _ecrire(donnees, chemins):
        ecrits = 0
        for chemin in chemins:
            temporaire = chemin + ".tmp"
            try:
                os.makedirs(os.path.dirname(chemin), exist_ok=True)
                with open(temporaire, "wb") as fichier:
                    fichier.write(donnees)
                os.replace(temporaire, chemin)
                ecrits += 1
            except OSError:
                # %PROGRAMDATA% peut etre en lecture seule : exemplaire ignore.
                try:
                    os.remove(temporaire)
                except OSError:
                    pass
        return ecrits


def _dossiers_defaut():
    dossiers = []
    for variable in ("APPDATA", "LOCALAPPDATA", "PROGRAMDATA"):
        valeur = os.environ.get(variable)
        if valeur:
            dossiers.append(os.path.join(valeur, "ETDEL", "licences"))
    if not dossiers:
        maison = os.path.expanduser("~")
        dossiers = [os.path.join(maison, ".config", "ETDEL", "licences"),
                    os.path.join(maison, ".local", "share", "ETDEL", "licences")]
    return dossiers


def _dossier_journal_defaut(dossiers):
    local = os.environ.get("LOCALAPPDATA")
    return os.path.join(local, "ETDEL", "licences") if local else dossiers[-1]


_JOURNAUX = {}
_VERROU_JOURNAUX = threading.Lock()


def _journal(dossier):
    chemin = os.path.join(dossier, "licence.log")
    with _VERROU_JOURNAUX:
        journal = _JOURNAUX.get(chemin)
        if journal is None:
            os.makedirs(dossier, exist_ok=True)
            # Journal dedie : ne jamais toucher a la configuration logging de l'appli.
            journal = logging.getLogger("etdel_licence." + hashlib.sha256(chemin.encode("utf-8")).hexdigest()[:12])
            journal.propagate = False
            journal.setLevel(logging.INFO)
            gestionnaire = logging.handlers.RotatingFileHandler(
                chemin, maxBytes=1000000, backupCount=2, encoding="utf-8", delay=True)
            gestionnaire.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
            journal.addHandler(gestionnaire)
            _JOURNAUX[chemin] = journal
    return journal


# ---------------------------------------------------------------------------
# Transport HTTPS
# ---------------------------------------------------------------------------

class _SansRedirection(urllib.request.HTTPRedirectHandler):
    # Une redirection transformerait le POST en GET : on la traite comme un
    # serveur injoignable plutot que de suivre un detour non signe.
    def redirect_request(self, *args, **kwargs):
        return None


def _transport_defaut(url, corps, delai):
    if not _url_autorisee(url):
        raise ValueError("url refusee (https obligatoire)")
    requete = urllib.request.Request(url, data=corps, method="POST", headers={
        "Content-Type": "application/json",
        "User-Agent": "ETDEL-Licence/" + MODULE_VERSION,
    })
    ouvreur = urllib.request.build_opener(
        _SansRedirection(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    try:
        with ouvreur.open(requete, timeout=delai) as reponse:
            return reponse.status, reponse.read(262144)
    except urllib.error.HTTPError as erreur:
        return erreur.code, b""


# ---------------------------------------------------------------------------
# Garde
# ---------------------------------------------------------------------------

def _jeton_coherent(p):
    if p.get("ok") is not True or not _entier(p.get("hors_ligne_jusqu")):
        return False
    if p.get("echeance") is not None and not _entier(p.get("echeance")):
        return False
    if not _entier(p.get("preavis_j", 0)) or not _entier(p.get("emis")):
        return False
    options = p.get("options", [])
    if not isinstance(options, list) or not all(isinstance(o, str) for o in options):
        return False
    for champ in ("version_min", "titulaire", "message"):
        if p.get(champ) is not None and not isinstance(p.get(champ), str):
            return False
    return True


class Garde(object):
    """Controle de licence d'une application (produit + distribution)."""

    def __init__(self, produit, distribution, version, _horloge=None, _transport=None,
                 _dossiers=None, _dossier_journal=None, _machine=None, _poste=None,
                 _urls=None, _cle_publique=None, _fil=True, _monotone=None,
                 _delai_initial=_DELAI_PREMIER_CONTROLE):
        # Parametres prefixes par _ : injection pour les tests uniquement.
        self.produit = str(produit)
        self.distribution = str(distribution)
        self.version = str(version)
        self._horloge = _horloge or time.time
        self._monotone = _monotone or time.monotonic
        self._transport = _transport or _transport_defaut
        urls = [LICENCE_URL, LICENCE_URL_SECOURS] if _urls is None else list(_urls)
        self._urls_embarquees = [u for u in urls if u]
        self._cle_embarquee = LICENCE_CLE_PUBLIQUE if _cle_publique is None else _cle_publique
        self._configure = bool(self._urls_embarquees) and bool(self._cle_embarquee)
        self._avec_fil = _fil
        self._delai_initial = _delai_initial
        self._verrou = threading.RLock()
        self._verrou_reseau = threading.Lock()
        self._reveil = threading.Event()
        self._arret = threading.Event()
        self._fil = None
        self._demarre = False
        self._prochain = 0.0
        self._arrete = False
        self._local = None
        self._payload = None
        self._revoque = None
        self._raison = ""
        self._hm = None
        self._mono_prec = None
        self._journal = None
        self._integration = None
        self._dernier_etat = None
        self._machine = ""
        self._poste = ""
        self._id = ""
        self._stockage = None
        self._dossier_journal = None
        if not self._configure:
            return
        try:
            self._machine = _machine or _empreinte_machine()
            self._poste = _nettoyer(_poste, 64) if _poste is not None else _nom_ordinateur()
            self._id = _id_poste(self._machine, self.produit)
            dossiers = list(_dossiers) if _dossiers else _dossiers_defaut()
            nom = "".join(c if (c.isalnum() and c.isascii()) or c in "-_." else "_"
                          for c in "%s-%s.dat" % (self.produit, self.distribution))
            secret = hashlib.sha256(("ETDEL|%s|%s|%s" % (self._machine, self.produit,
                                                         self.distribution)).encode("utf-8")).digest()
            self._stockage = _Stockage([os.path.join(d, nom) for d in dossiers], secret)
            self._dossier_journal = _dossier_journal or _dossier_journal_defaut(dossiers)
        except Exception as exc:
            self._raison = "initialisation : %s" % exc.__class__.__name__

    # -- journal ------------------------------------------------------------

    def _log(self, niveau, texte, *args):
        if not self._configure or not self._dossier_journal:
            return
        try:
            if self._journal is None:
                self._journal = _journal(self._dossier_journal)
            self._journal.log(niveau, "[%s/%s] " % (self.produit, self.distribution) + texte, *args)
        except Exception:
            pass

    # -- etat local ---------------------------------------------------------

    @staticmethod
    def _etat_vide():
        return {"v": 1, "seq": 0, "cle": None, "jeton": None, "demande": None, "cles": {},
                "urls": [], "url_active": None, "heure_max": 0, "refus": None,
                "dernier_controle": None, "jeton_envoi": None}

    def _assurer_charge(self):
        with self._verrou:
            if self._local is None:
                self._charger()

    def _charger(self):
        self._installer_etat(self._stockage.lire() if self._stockage else None)

    def _rafraichir(self):
        """Relit l'etat si une autre instance de l'application l'a ecrit depuis.

        Sans cela, deux instances ouvertes s'ecraseraient : la derniere fermee
        effacerait par exemple la cle activee dans l'autre.
        """
        with self._verrou:
            if self._stockage is None or self._local is None:
                return
            contenu = self._stockage.lire()
            if (contenu is None or not _entier(contenu.get("seq"))
                    or contenu["seq"] <= int(self._local.get("seq") or 0)):
                return
            heure = self._hm
            self._installer_etat(contenu)
            if heure is not None:
                self._hm = max(heure, float(self._local["heure_max"]))
                self._mono_prec = float(self._monotone())
            self._log(logging.INFO, "etat relu (modifie par une autre instance)")

    def _installer_etat(self, contenu):
        local = self._etat_vide()
        if contenu and contenu.get("v") == 1:
            for champ in local:
                if champ in contenu:
                    local[champ] = contenu[champ]
        if not isinstance(local["cles"], dict):
            local["cles"] = {}
        if not isinstance(local["urls"], list):
            local["urls"] = []
        if not _entier(local["heure_max"]):
            local["heure_max"] = 0
        if local["cle"] is not None and normaliser_cle(local["cle"]) != local["cle"]:
            local["cle"] = None
        self._local = local
        # heure_max relue : une heure calculee avant le chargement ne compte pas.
        self._hm = None
        self._mono_prec = None
        self._payload = None
        if local["jeton"]:
            payload = self._verifier_jeton_stocke(local["jeton"])
            if payload is None:
                self._log(logging.WARNING, "jeton local rejete (signature ou contenu invalide)")
                local["jeton"] = None
            else:
                self._payload = payload
        if local["cle"] is None:
            local["jeton"] = None
            self._payload = None

    def _sauver(self):
        if self._stockage is None or self._local is None:
            return
        self._local["seq"] = int(self._local.get("seq") or 0) + 1
        if self._hm is not None:
            self._local["heure_max"] = int(self._hm)
        if self._stockage.ecrire(self._local) == 0:
            self._log(logging.ERROR, "etat local non enregistre (aucun dossier inscriptible)")

    # -- temps --------------------------------------------------------------

    def _maintenant(self):
        """Heure de calcul, protegee contre le recul d'horloge.

        heure_max avance avec l'horloge et, si celle-ci est reculee, avec le
        temps d'execution mesure par l'horloge monotone : reculer l'heure ne
        fige donc pas le decompte de la tolerance.
        """
        reel = float(self._horloge())
        mono = float(self._monotone())
        with self._verrou:
            if self._hm is None:
                self._hm = float(self._local["heure_max"]) if self._local else 0.0
            elif self._mono_prec is not None:
                self._hm += max(0.0, mono - self._mono_prec)
            self._mono_prec = mono
            if reel > self._hm:
                self._hm = reel
            if self._local is not None:
                self._local["heure_max"] = int(self._hm)
            return int(reel if reel >= self._hm - _RECUL_TOLERE else self._hm)

    # -- signatures ---------------------------------------------------------

    def _cles_candidates(self, kid, accepter_retirees):
        cles = self._local["cles"]
        retirees = set()
        if not accepter_retirees:
            maintenant = self._maintenant()
            retirees = set(v.get("cle") for v in cles.values() if isinstance(v, dict)
                           and _entier(v.get("jusqu")) and maintenant >= v["jusqu"])
        candidates = []
        info = cles.get(str(kid))
        if isinstance(info, dict) and info.get("cle") not in retirees:
            candidates.append(info.get("cle"))
        # Le kid de l'enveloppe n'est pas signe : la cle embarquee est toujours
        # essayee en dernier, pour qu'un kid falsifie ne l'ecarte jamais, sauf si
        # elle a ete retiree (une cle retiree ne signe plus rien de neuf).
        if self._cle_embarquee not in retirees and self._cle_embarquee not in candidates:
            candidates.append(self._cle_embarquee)
        sortie = []
        for cle in candidates:
            try:
                octets = _deb64url(cle)
            except ValueError:
                continue
            if len(octets) == 32:
                sortie.append((cle, octets))
        return sortie

    def _signature_valide(self, enveloppe, accepter_retirees):
        """Octets du payload si la signature est valide, sinon None."""
        try:
            brut = _deb64url(enveloppe["payload"])
            signature = _deb64url(enveloppe["sig"])
            kid = enveloppe["kid"]
            if not _entier(kid):
                return None
        except (ValueError, KeyError, TypeError):
            return None
        for texte, octets in self._cles_candidates(kid, accepter_retirees):
            if _ed25519_verifier(octets, brut, signature):
                if str(kid) not in self._local["cles"]:
                    self._local["cles"][str(kid)] = {"cle": texte, "jusqu": None}
                return brut
        return None

    def _adopter_bulletins(self, bulletins):
        cles = self._local["cles"]
        for _passe in range(min(len(bulletins), 24)):
            progres = False
            for bulletin in bulletins[:24]:
                if not isinstance(bulletin, dict):
                    continue
                brut = self._signature_valide(bulletin, accepter_retirees=False)
                if brut is None:
                    continue
                try:
                    annonce = json.loads(brut.decode("utf-8"))
                    kid, signataire = annonce["kid"], bulletin["kid"]
                    cle = annonce["cle_publique"]
                    valide_des = annonce.get("valide_des")
                    if (annonce.get("type") != "nouvelle_cle" or not _entier(kid)
                            or kid <= signataire or len(_deb64url(cle)) != 32):
                        continue
                except (ValueError, KeyError, TypeError, AttributeError):
                    continue
                if str(kid) in cles:
                    continue
                cles[str(kid)] = {"cle": cle, "jusqu": None}
                ancienne = cles.get(str(signataire))
                if isinstance(ancienne, dict) and ancienne.get("jusqu") is None:
                    depart = valide_des if _entier(valide_des) else self._maintenant()
                    ancienne["jusqu"] = depart + _CONSERVATION_ANCIENNE_CLE
                self._log(logging.INFO, "nouvelle cle de signature adoptee (kid %s)", kid)
                progres = True
            if not progres:
                break

    def _verifier_jeton_stocke(self, enveloppe):
        if not isinstance(enveloppe, dict):
            return None
        brut = self._signature_valide(enveloppe, accepter_retirees=True)
        if brut is None:
            return None
        try:
            payload = json.loads(brut.decode("utf-8"))
        except ValueError:
            return None
        if not isinstance(payload, dict) or not _jeton_coherent(payload):
            return None
        if (payload.get("produit"), payload.get("distribution"), payload.get("machine")) != (
                self.produit, self.distribution, self._machine):
            return None
        return payload

    def _lire_reponse(self, donnees, corps):
        """Renvoie (payload, enveloppe) si la reponse est signee et correspond a la requete."""
        try:
            enveloppe = json.loads(donnees.decode("utf-8"))
            if not isinstance(enveloppe, dict):
                return None
            apercu = json.loads(_deb64url(enveloppe["payload"]).decode("utf-8"))
            if not isinstance(apercu, dict):
                return None
        except (ValueError, KeyError, TypeError, UnicodeDecodeError):
            return None
        # Les bulletins portent leur propre signature (cle precedente) : on les
        # traite d'abord pour pouvoir verifier une reponse signee par une cle neuve.
        bulletins = apercu.get("bulletins")
        if isinstance(bulletins, list) and bulletins:
            self._adopter_bulletins(bulletins)
        brut = self._signature_valide(enveloppe, accepter_retirees=False)
        if brut is None:
            return None
        payload = json.loads(brut.decode("utf-8"))
        if payload.get("v") != 1:
            return None
        for champ in ("nonce", "produit", "distribution", "machine"):
            if payload.get(champ) != corps[champ]:
                return None
        return payload, {"payload": enveloppe["payload"], "sig": enveloppe["sig"],
                         "kid": enveloppe["kid"]}

    # -- reseau -------------------------------------------------------------

    def _cascade(self):
        local = self._local
        urls = []
        actif = local.get("url_active")
        if actif and actif in local["urls"]:
            urls.append(actif)
        urls.extend(u for u in local["urls"] if isinstance(u, str))
        # URL embarquees : premier contact et dernier recours seulement.
        urls.extend(self._urls_embarquees)
        vues = []
        for url in urls:
            if url not in vues:
                vues.append(url)
        return vues

    def _envoyer(self, op, champs, delai_max=None):
        with self._verrou:
            cascade = self._cascade()
        corps = {"v": 1, "op": op, "produit": self.produit, "distribution": self.distribution,
                 "machine": self._machine, "poste": self._poste, "version": self.version,
                 "nonce": _b64url(os.urandom(16)), "t": int(self._horloge())}
        corps.update(champs)
        octets = json.dumps(corps).encode("utf-8")
        debut = time.monotonic()
        raisons = []
        for url in cascade:
            delai = _TIMEOUT
            if delai_max is not None:
                delai = min(delai, delai_max - (time.monotonic() - debut))
                if delai < 0.5:
                    raisons.append("delai depasse")
                    break
            try:
                code, donnees = self._transport(url, octets, delai)
            except Exception as exc:
                raisons.append("%s : %s" % (_hote(url), exc.__class__.__name__))
                continue
            if code != 200:
                raisons.append("%s : http %s" % (_hote(url), code))
                continue
            with self._verrou:
                lu = self._lire_reponse(donnees or b"", corps)
                if lu is None:
                    raisons.append("%s : reponse invalide ou mal signee" % _hote(url))
                    continue
                payload, enveloppe = lu
                self._contact(payload, url)
            return payload, enveloppe, None
        return None, None, "serveur injoignable (%s)" % ("; ".join(raisons) or "aucune url")

    def _contact(self, payload, url):
        reel = int(self._horloge())
        emis = payload.get("emis")
        # Reponse signee et liee a notre nonce : son horodatage est fiable et
        # corrige une avance accidentelle de l'horloge locale.
        self._hm = float(max(reel, emis if _entier(emis) else reel))
        self._mono_prec = float(self._monotone())
        urls = payload.get("urls")
        if isinstance(urls, list):
            propres = [u for u in urls if isinstance(u, str) and len(u) <= 300 and _url_autorisee(u)]
            if propres:
                self._local["urls"] = propres[:10]
        self._local["url_active"] = url

    def _traiter_licence(self, payload, enveloppe):
        """Applique une reponse activer/valider ; True si le serveur a tranche."""
        local = self._local
        reel = int(self._horloge())
        if payload.get("ok") is True:
            if not _jeton_coherent(payload):
                self._raison = "jeton incoherent"
                return False
            local["jeton"] = enveloppe
            local["refus"] = None
            local["dernier_controle"] = reel
            self._payload = payload
            self._revoque = None
            self._raison = ""
            return True
        code = payload.get("code")
        if code in _CODES_REVOCATION:
            self._log(logging.WARNING, "licence refusee par le serveur : %s (cle ...%s)",
                      code, (local.get("cle") or "")[-4:])
            self._revoque = code
            local["cle"] = None
            local["jeton"] = None
            local["refus"] = None
            local["dernier_controle"] = reel
            self._payload = None
            self._raison = code
            return True
        if code in _CODES_BLOCAGE:
            self._log(logging.WARNING, "licence bloquee par le serveur : %s", code)
            # La version refusee est memorisee : une mise a jour de l'application leve le blocage.
            local["refus"] = {"code": code, "t": reel, "version": self.version}
            local["dernier_controle"] = reel
            self._raison = code
            return True
        self._raison = _MESSAGES.get(code, "refus %s" % code)
        return False

    def _valider(self, cle, delai_max=None):
        payload, enveloppe, raison = self._envoyer("valider", {"cle": cle}, delai_max)
        with self._verrou:
            if payload is None:
                self._raison = raison
                self._log(logging.WARNING, "valider : %s", raison)
                self._sauver()
                return False
            if self._local.get("cle") != cle:
                return True
            tranche = self._traiter_licence(payload, enveloppe)
            self._sauver()
            return tranche

    def _suivre(self, demande, delai_max=None):
        payload, enveloppe, raison = self._envoyer(
            "suivre_demande", {"demande": demande.get("numero"), "jeton": demande.get("jeton")},
            delai_max)
        cle_acceptee = None
        with self._verrou:
            local = self._local
            if payload is None:
                self._raison = raison
                self._log(logging.WARNING, "suivre_demande : %s", raison)
                self._sauver()
                return False
            courante = local.get("demande")
            if not isinstance(courante, dict) or courante.get("numero") != demande.get("numero"):
                return True
            reel = int(self._horloge())
            if payload.get("ok") is not True:
                code = payload.get("code")
                self._raison = _MESSAGES.get(code, "refus %s" % code)
                if code == "demande_inconnue":
                    self._log(logging.WARNING, "demande %s inconnue du serveur", demande.get("numero"))
                    local["demande"] = None
                    self._sauver()
                    return True
                self._sauver()
                return False
            local["dernier_controle"] = reel
            self._raison = ""
            statut = payload.get("statut")
            if statut == "en_attente":
                essai = payload.get("essai_jusqu")
                courante["essai_jusqu"] = essai if _entier(essai) else None
                options = payload.get("options")
                if isinstance(options, list):
                    courante["options"] = [o for o in options if isinstance(o, str)]
            elif statut == "refusee":
                motif = payload.get("motif")
                courante["statut"] = "refusee"
                courante["motif"] = motif if isinstance(motif, str) and motif else None
                self._log(logging.INFO, "demande %s refusee", demande.get("numero"))
            elif statut == "acceptee":
                cle = normaliser_cle(payload.get("cle") or "")
                if cle is None or not _jeton_coherent(payload):
                    self._raison = "reponse d'acceptation incoherente"
                    self._sauver()
                    return False
                local["cle"] = cle
                local["jeton"] = enveloppe
                local["demande"] = None
                local["refus"] = None
                self._payload = payload
                self._revoque = None
                cle_acceptee = cle
                self._log(logging.INFO, "demande %s acceptee (cle ...%s)", demande.get("numero"), cle[-4:])
            self._sauver()
        if cle_acceptee:
            # Premier valider : le serveur efface alors la cle qu'il conservait.
            self._valider(cle_acceptee, delai_max)
        return True

    def _controler(self, delai_max=None):
        """Controle reseau synchrone ; a n'appeler que hors du fil de l'interface."""
        if not self._configure or self._stockage is None:
            return True
        if not self._verrou_reseau.acquire(timeout=60):
            return False
        try:
            self._assurer_charge()
            self._rafraichir()
            with self._verrou:
                cle = self._local.get("cle")
                demande = self._local.get("demande")
            if cle:
                return self._valider(cle, delai_max)
            if isinstance(demande, dict) and demande.get("statut") == "en_attente":
                return self._suivre(dict(demande), delai_max)
            return True
        except Exception as exc:
            self._raison = "erreur interne : %s" % exc.__class__.__name__
            self._log(logging.ERROR, "controle : %r", exc)
            return False
        finally:
            self._verrou_reseau.release()

    # -- planification ------------------------------------------------------

    def _planifier(self, delai):
        self._prochain = time.monotonic() + delai
        self._reveil.set()

    def _planifier_au_plus_tard(self, delai):
        self._prochain = min(self._prochain, time.monotonic() + delai)
        self._reveil.set()

    def _delai_suivant(self, succes):
        with self._verrou:
            statut = self._statut()[0]
        if statut == DEMANDE_EN_ATTENTE:
            return _INTERVALLE_ATTENTE
        return _INTERVALLE_CONTROLE if succes else _INTERVALLE_ECHEC

    def _boucle(self):
        while not self._arret.is_set():
            reste = self._prochain - time.monotonic()
            if reste > 0:
                self._reveil.wait(reste)
                self._reveil.clear()
                continue
            try:
                delai = self._delai_suivant(self._controler())
            except Exception as exc:
                self._log(logging.ERROR, "boucle : %r", exc)
                delai = _INTERVALLE_ECHEC
            self._prochain = time.monotonic() + delai

    # -- statut -------------------------------------------------------------

    def _statut(self):
        """(statut, message, jours_restants) calcules sur l'etat en memoire."""
        if not self._configure:
            return NON_CONFIGURE, "", None
        if self._stockage is None or self._local is None:
            return EXPIREE, "Licence indisponible : %s" % self._raison, None
        maintenant = self._maintenant()
        local = self._local
        if self._revoque:
            return REVOQUEE, _MESSAGES.get(self._revoque, "Licence revoquee"), None
        if local.get("cle"):
            refus = local.get("refus")
            if (isinstance(refus, dict) and refus.get("code") == "version_trop_ancienne"
                    and refus.get("version") != self.version):
                refus = None
            if isinstance(refus, dict):
                code = refus.get("code")
                if code == "version_trop_ancienne":
                    return VERSION_REFUSEE, ("Version %s trop ancienne : mettez l'application a jour."
                                             % self.version), None
                return EXPIREE, _MESSAGES.get(code, "Licence bloquee"), None
            p = self._payload
            if p is None:
                return EXPIREE, "Licence a verifier : connectez l'ordinateur a Internet puis reessayez.", None
            version_min = p.get("version_min")
            if version_min and _comparer_versions(self.version, version_min) < 0:
                return VERSION_REFUSEE, ("Version %s trop ancienne : mettez l'application a jour "
                                         "(version minimale %s)." % (self.version, version_min)), None
            echeance = p.get("echeance")
            jours = _jours_restants(echeance, maintenant) if echeance else None
            if echeance and maintenant >= echeance:
                return EXPIREE, ("Licence expiree le %s. Contactez ETDEL pour la renouveler."
                                 % _date(echeance)), 0
            hors_ligne = p["hors_ligne_jusqu"]
            if maintenant >= hors_ligne:
                return EXPIREE, ("Licence non verifiee depuis trop longtemps : connectez "
                                 "l'ordinateur a Internet puis reessayez."), jours
            # Preavis borne a la seconde moitie de la duree hors ligne : un preavis
            # superieur a la tolerance afficherait le bandeau des la verification reussie.
            debut_alerte = max(hors_ligne - int(p.get("preavis_j", 5)) * JOUR,
                               p["emis"] + (hors_ligne - p["emis"]) // 2)
            alerte_tolerance = maintenant >= debut_alerte
            alerte_echeance = bool(echeance) and echeance - maintenant < _PREAVIS_ECHEANCE_J * JOUR
            if alerte_tolerance and (not alerte_echeance or hors_ligne <= echeance):
                ecart = max(0, (maintenant - p["emis"]) // JOUR)
                return AVERTISSEMENT, ("Licence non verifiee depuis %d jour(s) : connectez l'ordinateur "
                                       "a Internet avant le %s." % (ecart, _date(hors_ligne))), jours
            if alerte_echeance:
                return AVERTISSEMENT, ("Licence valable jusqu'au %s (%d jour(s) restant(s)). "
                                       "Contactez ETDEL pour la prolonger." % (_date(echeance), jours)), jours
            if echeance:
                return VALIDE, "Licence valide jusqu'au %s" % _date(echeance), jours
            return VALIDE, "Licence valide (perpetuelle)", None
        demande = local.get("demande")
        if isinstance(demande, dict):
            if demande.get("statut") == "refusee":
                motif = _ascii(demande.get("motif"))
                return DEMANDE_REFUSEE, "Demande refusee" + (" : " + motif if motif else ""), None
            essai = demande.get("essai_jusqu")
            if _entier(essai) and maintenant < essai:
                jours = _jours_restants(essai, maintenant)
                return ESSAI, ("Licence en cours de traitement - %d jour(s) d'essai restant(s)"
                               % jours), jours
            return DEMANDE_EN_ATTENTE, self._texte_demande_envoyee(), None
        return A_ACTIVER, "Licence requise", None

    def _texte_demande_envoyee(self):
        demande = (self._local or {}).get("demande") or {}
        return "Demande envoyee le %s. Le traitement peut prendre plusieurs jours." % _date(
            demande.get("date"))

    def _etat(self):
        base = {"statut": NON_CONFIGURE, "message": "", "id_poste": None, "nom_ordinateur": None,
                "titulaire": None, "echeance": None, "jours_restants": None,
                "dernier_controle": None, "demande": None, "motif_refus": None,
                "url_active": None, "kid_actif": None, "raison": "constantes vides"}
        if not self._configure:
            return base
        if self._stockage is not None:
            self._assurer_charge()
        with self._verrou:
            statut, message, jours = self._statut()
            local = self._local or {}
            base.update(statut=statut, message=message, jours_restants=jours, id_poste=self._id,
                        nom_ordinateur=self._poste, raison=self._raison,
                        dernier_controle=local.get("dernier_controle"),
                        url_active=local.get("url_active"))
            if self._payload:
                base["titulaire"] = self._payload.get("titulaire")
                base["echeance"] = self._payload.get("echeance")
                base["kid_actif"] = (local.get("jeton") or {}).get("kid")
            demande = local.get("demande")
            if isinstance(demande, dict):
                base["demande"] = demande.get("numero")
                base["motif_refus"] = demande.get("motif")
        return base

    # -- API publique -------------------------------------------------------

    def demarrer(self):
        """Charge l'etat local et lance le controle en fond ; rend la main aussitot."""
        try:
            if not self._configure or self._demarre or self._stockage is None:
                return
            self._demarre = True
            self._assurer_charge()
            self._log(logging.INFO, "demarrage version %s, module %s, poste %s (%s), statut %s",
                      self.version, MODULE_VERSION, self._id, self._poste, self._statut()[0])
            self._prochain = time.monotonic() + self._delai_initial
            if self._avec_fil:
                self._fil = threading.Thread(target=self._boucle, name="etdel_licence", daemon=True)
                self._fil.start()
            atexit.register(self.arreter)
        except Exception as exc:
            self._raison = "demarrage : %s" % exc.__class__.__name__
            self._log(logging.ERROR, "demarrer : %r", exc)

    def etat(self):
        """Dict : statut, message, id_poste, nom_ordinateur, titulaire, echeance,
        jours_restants, dernier_controle, demande, motif_refus, url_active,
        kid_actif, raison."""
        try:
            self._dernier_etat = self._etat()
        except Exception as exc:
            self._log(logging.ERROR, "etat : %r", exc)
            if self._dernier_etat is None:
                self._dernier_etat = {"statut": EXPIREE, "message": _MESSAGES["interne"],
                                      "id_poste": self._id, "nom_ordinateur": self._poste,
                                      "titulaire": None, "echeance": None, "jours_restants": None,
                                      "dernier_controle": None, "demande": None,
                                      "motif_refus": None, "url_active": None,
                                      "kid_actif": None, "raison": repr(exc)}
        return dict(self._dernier_etat)

    def option(self, code):
        """Option de la distribution lue dans le jeton signe ; False si absente."""
        try:
            statut = self.etat()["statut"]
            with self._verrou:
                if statut in (VALIDE, AVERTISSEMENT) and self._payload:
                    return code in self._payload.get("options", [])
                if statut == ESSAI:
                    return code in ((self._local.get("demande") or {}).get("options") or [])
        except Exception as exc:
            self._log(logging.ERROR, "option : %r", exc)
        return False

    def controler_maintenant(self):
        """Lance un controle immediat, toujours dans un fil."""
        try:
            if not self._configure:
                return
            if self._fil is not None and self._fil.is_alive():
                self._planifier(0)
            else:
                threading.Thread(target=self._controler, name="etdel_licence_controle",
                                 daemon=True).start()
        except Exception as exc:
            self._log(logging.ERROR, "controler_maintenant : %r", exc)

    def _graver(self):
        """Enregistre l'heure atteinte sans arreter le controle."""
        with self._verrou:
            if self._local is not None:
                self._rafraichir()
                self._maintenant()
                self._sauver()

    def arreter(self):
        """A la fermeture : grave l'heure atteinte (garde anti-recul)."""
        try:
            if not self._configure or self._arrete:
                return
            self._arrete = True
            self._arret.set()
            self._reveil.set()
            self._graver()
            self._log(logging.INFO, "arret")
        except Exception as exc:
            self._log(logging.ERROR, "arreter : %r", exc)

    def activer(self, cle):
        """Active une cle (bloquant : a appeler hors du fil de l'interface).

        Renvoie {"ok": bool, "code": str ou None, "message": str}.
        """
        try:
            if not self._configure or self._stockage is None:
                return {"ok": False, "code": "non_configure", "message": _MESSAGES["non_configure"]}
            normalisee = normaliser_cle(cle)
            if normalisee is None:
                return {"ok": False, "code": "cle_invalide",
                        "message": "Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)"}
            with self._verrou_reseau:
                self._assurer_charge()
                self._rafraichir()
                payload, enveloppe, raison = self._envoyer("activer", {"cle": normalisee})
                with self._verrou:
                    if payload is None:
                        self._raison = raison
                        self._sauver()
                        return {"ok": False, "code": "injoignable", "message": _MESSAGES["injoignable"]}
                    if payload.get("ok") is True and _jeton_coherent(payload):
                        self._local["cle"] = normalisee
                        self._local["demande"] = None
                        self._local["jeton_envoi"] = None
                        self._traiter_licence(payload, enveloppe)
                        self._sauver()
                        self._log(logging.INFO, "cle ...%s activee", normalisee[-4:])
                        return {"ok": True, "code": None, "message": "Licence activee"}
                    code = payload.get("code")
                    self._sauver()
                    self._log(logging.INFO, "activation refusee : %s", code)
                    return {"ok": False, "code": code,
                            "message": _MESSAGES.get(code, "Activation refusee (%s)" % code)}
        except Exception as exc:
            self._log(logging.ERROR, "activer : %r", exc)
            return {"ok": False, "code": "interne", "message": _MESSAGES["interne"]}

    def demander(self, titulaire, email="", message=""):
        """Envoie une demande de licence (bloquant : hors du fil de l'interface).

        N'est appelee que sur clic explicite de l'utilisateur.
        """
        try:
            if not self._configure or self._stockage is None:
                return {"ok": False, "code": "non_configure", "message": _MESSAGES["non_configure"]}
            titulaire = _nettoyer(titulaire, _TITULAIRE_MAX)
            email = _nettoyer(email, _EMAIL_MAX)
            message = _nettoyer(message, _MESSAGE_MAX, multiligne=True)
            if not titulaire:
                return {"ok": False, "code": "titulaire", "message": "Le titulaire est obligatoire."}
            with self._verrou_reseau:
                self._assurer_charge()
                self._rafraichir()
                with self._verrou:
                    # Jeton conserve avant l'envoi : si la reponse se perd, le
                    # renvoi reprend la meme demande au lieu d'etre refuse.
                    jeton = self._local.get("jeton_envoi") or _b64url(os.urandom(32))
                    self._local["jeton_envoi"] = jeton
                    self._sauver()
                payload, _enveloppe, raison = self._envoyer("demander", {
                    "titulaire": titulaire, "email": email, "message": message, "jeton": jeton})
                with self._verrou:
                    if payload is None:
                        self._raison = raison
                        self._sauver()
                        return {"ok": False, "code": "injoignable", "message": _MESSAGES["injoignable"]}
                    if payload.get("ok") is not True or not _entier(payload.get("demande")):
                        code = payload.get("code")
                        if code not in ("horloge", "trop_de_requetes"):
                            self._local["jeton_envoi"] = None
                        self._sauver()
                        return {"ok": False, "code": code,
                                "message": _MESSAGES.get(code, "Demande refusee (%s)" % code)}
                    essai = payload.get("essai_jusqu")
                    options = payload.get("options")
                    self._local["demande"] = {
                        "numero": payload["demande"], "jeton": jeton,
                        "date": payload["emis"] if _entier(payload.get("emis")) else int(self._horloge()),
                        "essai_jusqu": essai if _entier(essai) else None, "statut": "en_attente",
                        "motif": None,
                        "options": [o for o in options if isinstance(o, str)] if isinstance(options, list) else []}
                    self._local["jeton_envoi"] = None
                    self._local["dernier_controle"] = int(self._horloge())
                    self._revoque = None
                    self._sauver()
                    self._log(logging.INFO, "demande %s envoyee (essai jusqu'au %s)",
                              payload["demande"], _date(essai) if _entier(essai) else "-")
                    statut = self._statut()[0]
            self._planifier(_INTERVALLE_ATTENTE if statut == DEMANDE_EN_ATTENTE else _INTERVALLE_CONTROLE)
            return {"ok": True, "code": None, "message": self._texte_demande_envoyee()}
        except Exception as exc:
            self._log(logging.ERROR, "demander : %r", exc)
            return {"ok": False, "code": "interne", "message": _MESSAGES["interne"]}

    def texte_diagnostic(self):
        """Une ligne prete a inserer dans un rapport (jamais la cle en clair)."""
        try:
            if not self._configure:
                return "Licence ETDEL %s : non configuree" % MODULE_VERSION
            e = self.etat()
            echeance = _date(e["echeance"]) if e["echeance"] else (
                "perpetuelle" if e["statut"] in (VALIDE, AVERTISSEMENT) else "-")
            return ("Licence ETDEL %s ; %s/%s v%s ; poste %s (%s) ; %s ; echeance %s ; "
                    "controle %s ; serveur %s ; kid %s%s" % (
                        MODULE_VERSION, self.produit, self.distribution, self.version,
                        e["id_poste"], _ascii(e["nom_ordinateur"]), e["statut"], echeance,
                        _date_heure(e["dernier_controle"]) or "jamais",
                        _hote(e["url_active"]) if e["url_active"] else "-",
                        e["kid_actif"] if e["kid_actif"] is not None else "-",
                        " ; " + _ascii(e["raison"]) if e["raison"] else ""))
        except Exception as exc:
            return "Licence ETDEL %s : diagnostic indisponible (%s)" % (MODULE_VERSION, exc.__class__.__name__)

    diagnostic_text = texte_diagnostic

    def cadre_licence(self, parent, palette=None):
        """tk.Frame en lecture seule a placer dans une page de reglages."""
        try:
            tk = _tk()
            if not self._configure:
                return tk.Frame(parent)
            pal = _palette(palette if palette is not None else
                           (self._integration.pal if self._integration else None))
            return _CadreLicence(parent, self, pal).cadre
        except Exception as exc:
            self._log(logging.ERROR, "cadre_licence : %r", exc)
            try:
                return _tk().Frame(parent)
            except Exception:
                return None


# ---------------------------------------------------------------------------
# Composants Tkinter
# ---------------------------------------------------------------------------

PALETTE_DEFAUT = {
    "fond": "#f2f2f2", "panneau": "#ffffff", "texte": "#1e1e1e", "discret": "#6b6b6b",
    "accent": "#b4500a", "accent_texte": "#ffffff",
    "police": ("Segoe UI", 10), "police_titre": ("Segoe UI", 13, "bold"),
    "police_champ": ("Consolas", 11),
}


def _tk():
    import tkinter
    return tkinter


def _palette(palette):
    resultat = dict(PALETTE_DEFAUT)
    if isinstance(palette, dict):
        resultat.update((k, v) for k, v in palette.items() if k in PALETTE_DEFAUT and v)
    return resultat


def _afficher_message(parent, titre, texte):
    from tkinter import messagebox
    messagebox.showerror(titre, texte, parent=parent)


def _ecrire_champ(entree, texte):
    entree.configure(state="normal")
    entree.delete(0, "end")
    entree.insert(0, texte)
    entree.configure(state="readonly")


class _CadreLicence(object):
    LIGNES = (("id_poste", "Identifiant du poste"), ("nom_ordinateur", "Nom de l'ordinateur"),
              ("titulaire", "Titulaire"), ("echeance", "Echeance"), ("statut", "Statut"),
              ("dernier_controle", "Dernier controle"))

    def __init__(self, parent, garde, pal):
        tk = _tk()
        self.garde = garde
        self.cadre = tk.Frame(parent, bg=pal["panneau"], padx=12, pady=10)
        self.valeurs = {}
        for rang, (cle, libelle) in enumerate(self.LIGNES):
            tk.Label(self.cadre, text=libelle, bg=pal["panneau"], fg=pal["discret"],
                     font=pal["police"], anchor="w").grid(row=rang, column=0, sticky="w", padx=(0, 12))
            if cle == "id_poste":
                # Champ en lecture seule : selectionnable pour une dictee ou un copier-coller.
                widget = tk.Entry(self.cadre, font=pal["police_champ"], width=14, relief="flat",
                                  readonlybackground=pal["panneau"], fg=pal["texte"])
            else:
                widget = tk.Label(self.cadre, bg=pal["panneau"], fg=pal["texte"], font=pal["police"],
                                  anchor="w", justify="left", wraplength=380)
            widget.grid(row=rang, column=1, sticky="w")
            self.valeurs[cle] = widget
        rang = len(self.LIGNES)
        self.message = tk.Label(self.cadre, bg=pal["panneau"], fg=pal["discret"], font=pal["police"],
                                anchor="w", justify="left", wraplength=480)
        self.message.grid(row=rang, column=0, columnspan=2, sticky="w", pady=(4, 0))
        tk.Button(self.cadre, text="Verifier maintenant", command=garde.controler_maintenant,
                  font=pal["police"]).grid(row=rang + 1, column=0, sticky="w", pady=(8, 4))
        tk.Label(self.cadre, text="Diagnostic", bg=pal["panneau"], fg=pal["discret"],
                 font=pal["police"]).grid(row=rang + 2, column=0, sticky="w")
        self.diagnostic = tk.Entry(self.cadre, font=pal["police"], width=60, relief="flat",
                                   readonlybackground=pal["panneau"], fg=pal["discret"])
        self.diagnostic.grid(row=rang + 3, column=0, columnspan=2, sticky="we")
        self._apres = None
        self.cadre.bind("<Destroy>", self._sur_destruction, add="+")
        self.rafraichir()

    def _sur_destruction(self, evenement):
        # Un after en attente sur un widget detruit finit en erreur Tcl sur stderr.
        if evenement.widget is self.cadre and self._apres is not None:
            try:
                self.cadre.after_cancel(self._apres)
            except Exception:
                pass
            self._apres = None

    def rafraichir(self):
        try:
            if not self.cadre.winfo_exists():
                return
            e = self.garde.etat()
            _ecrire_champ(self.valeurs["id_poste"], e["id_poste"] or "")
            if e["echeance"]:
                echeance = _date(e["echeance"])
            elif e["statut"] in (VALIDE, AVERTISSEMENT):
                echeance = "Perpetuelle"
            else:
                echeance = "-"
            textes = {"nom_ordinateur": _ascii(e["nom_ordinateur"]), "titulaire": _ascii(e["titulaire"]) or "-",
                      "echeance": echeance, "statut": e["message"] or e["statut"],
                      "dernier_controle": _date_heure(e["dernier_controle"]) or "Jamais"}
            for cle, texte in textes.items():
                self.valeurs[cle].configure(text=texte)
            accueil = self.garde._payload.get("message") if self.garde._payload else None
            self.message.configure(text=_ascii(accueil))
            _ecrire_champ(self.diagnostic, self.garde.texte_diagnostic())
            self._apres = self.cadre.after(1000, self.rafraichir)
        except Exception:
            pass


class _FenetreLicence(object):
    """Fenetre ouverte par Ctrl+Maj+L."""

    def __init__(self, integ):
        tk = _tk()
        self.integ = integ
        pal = integ.pal
        self.win = tk.Toplevel(integ.root)
        self.win.title("Licence")
        self.win.configure(bg=pal["fond"])
        self.win.resizable(False, False)
        _CadreLicence(self.win, integ.garde, pal).cadre.pack(fill="both", expand=True, padx=12, pady=12)
        tk.Button(self.win, text="Fermer", command=self.fermer, font=pal["police"]).pack(pady=(0, 12))
        self.win.protocol("WM_DELETE_WINDOW", self.fermer)
        self.win.bind("<Escape>", lambda _e: self.fermer())

    def fermer(self):
        try:
            self.win.destroy()
        finally:
            self.integ.fenetre_licence = None


class _FenetreActivation(object):
    """Fenetre modale : A_ACTIVER, DEMANDE_EN_ATTENTE, DEMANDE_REFUSEE, EXPIREE.

    Rien n'est envoye au serveur sans clic explicite. Fermer quitte l'application.
    """

    PAGES_STATUT = ("accueil", "attente", "refus", "expiree", "version")

    def __init__(self, integ):
        tk = _tk()
        self.integ = integ
        self.garde = integ.garde
        self.pal = integ.pal
        self.file = queue.Queue()
        self.occupe = False
        self.ferme = False
        self.page = None
        self.corps = None
        self.boutons = []
        self.erreur = None
        self.info = None
        self._apres = None
        integ.root.withdraw()
        self.win = tk.Toplevel(integ.root)
        self.win.title("Licence - %s" % self.garde.produit)
        self.win.configure(bg=self.pal["fond"])
        self.win.minsize(440, 0)
        self.win.protocol("WM_DELETE_WINDOW", integ.quitter)
        self.win.bind("<Destroy>", self._sur_destruction, add="+")
        self.afficher_statut()
        if not self.ferme:
            self._apres = self.win.after(200, self._sonder)

    def _sur_destruction(self, evenement):
        if evenement.widget is self.win:
            self.ferme = True
            if self._apres is not None:
                try:
                    self.win.after_cancel(self._apres)
                except Exception:
                    pass
                self._apres = None

    # -- construction -------------------------------------------------------

    def _nouvelle_page(self, nom, titre):
        tk = _tk()
        if self.corps is not None:
            self.corps.destroy()
        self.page = nom
        self.boutons = []
        self.erreur = None
        self.info = None
        self.corps = tk.Frame(self.win, bg=self.pal["fond"], padx=20, pady=16)
        self.corps.pack(fill="both", expand=True)
        self._label(titre, police=self.pal["police_titre"])

    def _label(self, texte, police=None, couleur=None):
        tk = _tk()
        label = tk.Label(self.corps, text=texte, justify="left", anchor="w", wraplength=440,
                         bg=self.pal["fond"], fg=couleur or self.pal["texte"],
                         font=police or self.pal["police"])
        label.pack(fill="x", pady=(0, 8))
        return label

    def _champ(self, libelle, valeur="", lecture_seule=False, largeur=44):
        tk = _tk()
        self._label(libelle)
        entree = tk.Entry(self.corps, width=largeur, font=self.pal["police_champ"])
        entree.pack(anchor="w", pady=(0, 10))
        if valeur:
            entree.insert(0, valeur)
        if lecture_seule:
            entree.configure(state="readonly")
        return entree

    def _boutons(self, definitions):
        tk = _tk()
        rangee = tk.Frame(self.corps, bg=self.pal["fond"])
        rangee.pack(fill="x", pady=(6, 0))
        for texte, commande in definitions:
            bouton = tk.Button(rangee, text=texte, command=commande, font=self.pal["police"], padx=10)
            bouton.pack(side="left", padx=(0, 8))
            self.boutons.append(bouton)

    def _identifiant(self, e):
        self._label("Identifiant du poste : %s" % e["id_poste"], couleur=self.pal["discret"])

    # -- pages --------------------------------------------------------------

    def afficher_statut(self):
        e = self.garde.etat()
        statut = e["statut"]
        if statut in STATUTS_UTILISABLES:
            self.fermer()
        elif statut == DEMANDE_EN_ATTENTE:
            self.page_attente(e)
        elif statut == DEMANDE_REFUSEE:
            self.page_refus(e)
        elif statut == EXPIREE:
            self.page_expiree(e)
        elif statut == VERSION_REFUSEE:
            self.page_version(e)
        else:
            self.page_accueil(e)

    @staticmethod
    def _page_pour(statut):
        return {DEMANDE_EN_ATTENTE: "attente", DEMANDE_REFUSEE: "refus", EXPIREE: "expiree",
                VERSION_REFUSEE: "version"}.get(statut, "accueil")

    def page_accueil(self, e=None):
        e = e or self.garde.etat()
        self._nouvelle_page("accueil", "Licence requise")
        self._label("Cette application necessite une licence ETDEL.")
        if e["statut"] == REVOQUEE:
            self._label(e["message"], couleur=self.pal["accent"])
        self._identifiant(e)
        self._boutons([("J'ai une cle", self.page_cle), ("Demander une licence", self.page_formulaire)])

    def page_cle(self):
        self._nouvelle_page("cle", "J'ai une cle")
        self.entree_cle = self._champ("Cle de licence fournie par ETDEL :", largeur=32)
        self.entree_cle.bind("<Return>", lambda _e: self._activer())
        self.entree_cle.focus_set()
        self.erreur = self._label("", couleur=self.pal["accent"])
        self._boutons([("Activer", self._activer), ("Retour", self.afficher_statut)])

    def page_formulaire(self):
        tk = _tk()
        self._nouvelle_page("formulaire", "Demander une licence")
        self.entree_titulaire = self._champ("Titulaire (obligatoire) :")
        self.entree_email = self._champ("E-mail (facultatif) :")
        self._label("Mot pour ETDEL (facultatif, %d caracteres maximum) :" % _MESSAGE_MAX)
        self.texte_mot = tk.Text(self.corps, width=48, height=4, wrap="word", font=self.pal["police"])
        self.texte_mot.pack(anchor="w")
        self.texte_mot.bind("<KeyRelease>", self._borner_mot)
        self.compteur = self._label("0/%d" % _MESSAGE_MAX, couleur=self.pal["discret"])
        self._champ("Nom de l'ordinateur :", valeur=self.garde._poste, lecture_seule=True)
        self._label(MENTION_DELAI, couleur=self.pal["discret"])
        self.erreur = self._label("", couleur=self.pal["accent"])
        self._boutons([("Envoyer la demande", self._envoyer), ("Retour", self.afficher_statut)])
        self.entree_titulaire.focus_set()

    def page_envoyee(self, e):
        self._nouvelle_page("envoyee", "Demande envoyee")
        self._label(self.garde._texte_demande_envoyee())
        self._label("En attendant la reponse, l'application est utilisable pendant %d jour(s)."
                    % (e["jours_restants"] or 0))
        self._boutons([("Continuer", self.fermer)])

    def page_attente(self, e):
        self._nouvelle_page("attente", "Demande en attente")
        self._label(self.garde._texte_demande_envoyee())
        demande = (self.garde._local or {}).get("demande") or {}
        if demande.get("essai_jusqu"):
            self._label("La periode d'essai est terminee.")
        else:
            self._label("Aucune periode d'essai n'est disponible pour ce poste.")
        self._label("La reponse est verifiee automatiquement chaque minute.", couleur=self.pal["discret"])
        self._identifiant(e)
        self.info = self._label("", couleur=self.pal["discret"])
        self._boutons([("Verifier maintenant", self._verifier), ("J'ai une cle", self.page_cle)])
        self.garde._planifier_au_plus_tard(_INTERVALLE_ATTENTE)

    def page_refus(self, e):
        self._nouvelle_page("refus", "Demande refusee")
        motif = _ascii(e.get("motif_refus"))
        if motif:
            self._label(motif)
        self._identifiant(e)
        self._boutons([("Nouvelle demande", self.page_formulaire), ("J'ai une cle", self.page_cle)])

    def page_expiree(self, e):
        self._nouvelle_page("expiree", "Licence a verifier")
        self._label(e["message"])
        self._identifiant(e)
        self.info = self._label("", couleur=self.pal["discret"])
        self._boutons([("Reessayer", self._verifier), ("J'ai une cle", self.page_cle)])

    def page_version(self, e):
        self._nouvelle_page("version", "Mise a jour necessaire")
        self._label(e["message"])
        self.info = self._label("", couleur=self.pal["discret"])
        self._boutons([("Reessayer", self._verifier), ("Quitter", self.integ.quitter)])

    # -- actions ------------------------------------------------------------

    def _borner_mot(self, _evenement=None):
        texte = self.texte_mot.get("1.0", "end-1c")
        if len(texte) > _MESSAGE_MAX:
            self.texte_mot.delete("1.0", "end")
            self.texte_mot.insert("1.0", texte[:_MESSAGE_MAX])
            texte = texte[:_MESSAGE_MAX]
        self.compteur.configure(text="%d/%d" % (len(texte), _MESSAGE_MAX))

    def _en_fond(self, fonction, rappel, attente="Connexion au serveur..."):
        if self.occupe:
            return
        self.occupe = True
        for bouton in self.boutons:
            bouton.configure(state="disabled")
        cible = self.erreur or self.info
        if cible is not None:
            cible.configure(text=attente, fg=self.pal["discret"])

        def tache():
            try:
                resultat = fonction()
            except Exception:
                resultat = {"ok": False, "message": _MESSAGES["interne"]}
            self.file.put((rappel, resultat))

        threading.Thread(target=tache, name="etdel_licence_ui", daemon=True).start()

    def _activer(self):
        cle = self.entree_cle.get()
        if normaliser_cle(cle) is None:
            self.erreur.configure(text="Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)",
                                  fg=self.pal["accent"])
            return
        self._en_fond(lambda: self.garde.activer(cle), self._apres_action)

    def _envoyer(self):
        titulaire = self.entree_titulaire.get().strip()
        if not titulaire:
            self.erreur.configure(text="Le titulaire est obligatoire.", fg=self.pal["accent"])
            return
        email = self.entree_email.get()
        mot = self.texte_mot.get("1.0", "end-1c")[:_MESSAGE_MAX]
        self._en_fond(lambda: self.garde.demander(titulaire, email, mot), self._apres_demande)

    def _apres_action(self, resultat):
        if resultat.get("ok"):
            self.afficher_statut()
        elif self.erreur is not None:
            self.erreur.configure(text=resultat.get("message") or "", fg=self.pal["accent"])

    def _apres_demande(self, resultat):
        if not resultat.get("ok"):
            self.erreur.configure(text=resultat.get("message") or "", fg=self.pal["accent"])
            return
        e = self.garde.etat()
        if e["statut"] == ESSAI:
            self.page_envoyee(e)
        else:
            self.afficher_statut()

    def _verifier(self):
        self._en_fond(self.garde._controler, self._apres_verification, "Verification en cours...")

    def _apres_verification(self, succes):
        e = self.garde.etat()
        if self._page_pour(e["statut"]) != self.page or e["statut"] in STATUTS_UTILISABLES:
            self.afficher_statut()
            return
        if self.info is not None:
            if succes is True:
                texte = "Verifie le %s : pas encore de reponse." % _date_heure(int(time.time()))
            else:
                texte = _ascii(e["raison"]) or _MESSAGES["injoignable"]
            self.info.configure(text=texte, fg=self.pal["discret"])

    def _sonder(self):
        if self.ferme:
            return
        try:
            while True:
                rappel, resultat = self.file.get_nowait()
                self.occupe = False
                for bouton in self.boutons:
                    try:
                        bouton.configure(state="normal")
                    except Exception:
                        pass
                rappel(resultat)
                if self.ferme:
                    return
        except queue.Empty:
            pass
        try:
            if not self.occupe:
                statut = self.garde.etat()["statut"]
                if statut in STATUTS_UTILISABLES:
                    if self.page != "envoyee":
                        self.fermer()
                        return
                elif self.page in self.PAGES_STATUT and self._page_pour(statut) != self.page:
                    self.afficher_statut()
            if not self.ferme:
                self._apres = self.win.after(200, self._sonder)
        except Exception:
            pass

    def fermer(self):
        if self.ferme:
            return
        try:
            self.win.destroy()
        except Exception:
            pass
        self.ferme = True
        self.integ.fenetre_fermee()


class _Integration(object):
    """Tout ce que installer() branche sur la fenetre principale."""

    def __init__(self, root, garde, palette):
        self.root = root
        self.garde = garde
        self.pal = _palette(palette)
        self.fenetre = None
        self.fenetre_licence = None
        self.bandeau = None
        self.termine = False
        self._origine = ""
        self._apres = []

    def installer(self):
        root = self.root
        try:
            self._origine = root.protocol("WM_DELETE_WINDOW") or ""
        except Exception:
            self._origine = ""
        root.protocol("WM_DELETE_WINDOW", self._sur_fermeture)
        # Filet si l'application remplace WM_DELETE_WINDOW apres nous.
        root.bind("<Destroy>", self._sur_destruction, add="+")
        for sequence in ("<Control-Shift-KeyPress-L>", "<Control-Shift-KeyPress-l>"):
            root.bind_all(sequence, self._sur_raccourci, add="+")
        if self.garde.etat()["statut"] in _STATUTS_FENETRE:
            root.withdraw()
        # Differe : l'application construit encore son interface apres installer().
        self._apres = [root.after(0, self._premier_affichage), root.after(_TICK_MS, self._tick)]

    def _sur_fermeture(self):
        # L'application peut encore annuler la fermeture (modifications non
        # enregistrees) : on grave l'heure sans arreter le controle ; l'arret a
        # lieu a la destruction reelle de la fenetre (<Destroy>).
        try:
            self.garde._graver()
        except Exception as exc:
            self.garde._log(logging.ERROR, "fermeture : %r", exc)
        if self._origine:
            try:
                self.root.tk.eval(self._origine)
                return
            except Exception:
                pass
        self._detruire()

    def _sur_destruction(self, evenement):
        if evenement.widget is self.root:
            self.termine = True
            for apres in self._apres:
                try:
                    self.root.after_cancel(apres)
                except Exception:
                    pass
            self.garde.arreter()

    def _sur_raccourci(self, _evenement=None):
        self.ouvrir_licence()

    def _detruire(self):
        self.termine = True
        try:
            self.root.destroy()
        except Exception:
            pass

    def quitter(self):
        self.garde.arreter()
        self._detruire()

    def ouvrir_licence(self):
        try:
            if self.fenetre_licence is not None and self.fenetre_licence.win.winfo_exists():
                self.fenetre_licence.win.lift()
            else:
                self.fenetre_licence = _FenetreLicence(self)
        except Exception as exc:
            self.garde._log(logging.ERROR, "fenetre licence : %r", exc)

    def ouvrir_activation(self):
        if self.fenetre is None:
            fenetre = _FenetreActivation(self)
            # La fenetre a pu se refermer pendant sa construction (statut redevenu utilisable).
            self.fenetre = None if fenetre.ferme else fenetre

    def fenetre_fermee(self):
        self.fenetre = None
        if not self.termine:
            self.root.deiconify()
            self._maj_bandeau(self.garde.etat())

    def _bloquer(self, e):
        _afficher_message(self.root, "Licence", e["message"] or e["statut"])
        self.quitter()

    def _premier_affichage(self):
        if self.termine:
            return
        try:
            e = self.garde.etat()
            if e["statut"] in _STATUTS_FENETRE:
                self.ouvrir_activation()
            elif e["statut"] == REVOQUEE:
                self._bloquer(e)
                return
            self._maj_bandeau(e)
        except Exception as exc:
            self.garde._log(logging.ERROR, "premier affichage : %r", exc)

    def _tick(self):
        if self.termine:
            return
        try:
            self.garde._rafraichir()
            e = self.garde.etat()
            statut = e["statut"]
            self._maj_bandeau(e)
            if self.fenetre is None:
                if statut in (EXPIREE, REVOQUEE, VERSION_REFUSEE):
                    self._bloquer(e)
                    return
                if statut in _STATUTS_FENETRE:
                    self.ouvrir_activation()
        except Exception as exc:
            self.garde._log(logging.ERROR, "tick : %r", exc)
        if not self.termine:
            self._apres = [self.root.after(_TICK_MS, self._tick)]

    def _maj_bandeau(self, e):
        tk = _tk()
        if e["statut"] in (AVERTISSEMENT, ESSAI) and self.fenetre is None:
            if self.bandeau is None:
                self.bandeau = tk.Label(self.root, bg=self.pal["accent"], fg=self.pal["accent_texte"],
                                        font=self.pal["police"], anchor="w", padx=10, pady=4)
            self.bandeau.configure(text=e["message"])
            # Superposition : ne depend ni de pack ni de grid dans l'application.
            self.bandeau.place(relx=0, rely=0, relwidth=1)
            self.bandeau.lift()
        elif self.bandeau is not None:
            self.bandeau.place_forget()


def installer(root, produit, distribution, version, palette=None, _garde=None):
    """Branche la licence sur la fenetre principale ; renvoie la Garde.

    Constantes vides : ne fait rien (aucun fichier, aucun reseau, aucun message).
    """
    garde = _garde
    try:
        if garde is None:
            garde = Garde(produit=produit, distribution=distribution, version=version)
        if not garde._configure:
            return garde
        garde.demarrer()
        integration = _Integration(root, garde, palette)
        garde._integration = integration
        integration.installer()
    except Exception as exc:
        if garde is not None:
            garde._log(logging.ERROR, "installer : %r", exc)
    return garde


def exiger(produit, distribution, version, _garde=None):
    """Variante console : controle synchrone (5 s), sinon message et sys.exit(3)."""
    garde = _garde
    try:
        if garde is None:
            garde = Garde(produit=produit, distribution=distribution, version=version, _fil=False)
        if not garde._configure:
            return garde
        garde._assurer_charge()
        garde._controler(delai_max=_TIMEOUT)
        e = garde.etat()
        if e["statut"] == A_ACTIVER and sys.stdin is not None and sys.stdin.isatty():
            sys.stderr.write("Licence requise (identifiant du poste %s).\n" % e["id_poste"])
            cle = input("Cle de licence ETDEL : ")
            resultat = garde.activer(cle)
            if not resultat["ok"]:
                sys.stderr.write("Licence : %s\n" % resultat["message"])
            e = garde.etat()
        atexit.register(garde.arreter)
    except (EOFError, KeyboardInterrupt):
        e = {"statut": A_ACTIVER, "message": "Licence requise"}
    except Exception as exc:
        if garde is not None:
            garde._log(logging.ERROR, "exiger : %r", exc)
        e = {"statut": EXPIREE, "message": _MESSAGES["interne"]}
    if e["statut"] in STATUTS_UTILISABLES:
        if e["statut"] != VALIDE:
            sys.stderr.write("Licence : %s\n" % e["message"])
        return garde
    sys.stderr.write("Licence : %s\n" % (e["message"] or e["statut"]))
    sys.exit(3)
