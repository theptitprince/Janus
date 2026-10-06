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

MODULE_VERSION = "1.4.0"

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
_CODES_REVOCATION = ("revoquee", "poste_revoque", "cle_liee_autre_poste", "cle_invalide")
# Refus qui bloquent sans effacer la cle : une prolongation, une reactivation
# ou la fin d'une suspension dans la console debloque au controle suivant,
# sans ressaisie (une cle obtenue par demande n'a jamais ete montree).
_CODES_BLOCAGE = ("expiree", "suspendue", "version_trop_ancienne", "produit_inconnu")

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
# Joker saisi dans la console : toutes les options, presentes et futures.
OPTION_TOUTES = "*"

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


class _FichierJournal(logging.Handler):
    """Journal tournant qui ne garde jamais le fichier ouvert.

    Sous Windows, un fichier ouvert ne peut etre ni renomme ni supprime : avec
    RotatingFileHandler, une seconde instance de l'application faisait echouer
    la rotation (trace "Logging error" sur stderr) et le dossier de licence
    restait verrouille tant que l'application tournait.
    """

    def __init__(self, chemin, taille_max=1000000, archives=2):
        logging.Handler.__init__(self)
        self.chemin = chemin
        self.taille_max = taille_max
        self.archives = archives

    def _tourner(self):
        for n in range(self.archives, 0, -1):
            source = self.chemin if n == 1 else "%s.%d" % (self.chemin, n - 1)
            if os.path.exists(source):
                os.replace(source, "%s.%d" % (self.chemin, n))

    def emit(self, enregistrement):
        try:
            ligne = (self.format(enregistrement) + "\n").encode("utf-8", "replace")
            with self.lock:
                try:
                    if os.path.getsize(self.chemin) + len(ligne) > self.taille_max:
                        self._tourner()
                except OSError:
                    # Fichier absent, ou rotation impossible (autre instance en
                    # train d'ecrire) : on ecrit quand meme, la rotation suivra.
                    pass
                with open(self.chemin, "ab") as fichier:
                    fichier.write(ligne)
        except Exception:
            # Un journal en echec ne doit jamais rien afficher a l'utilisateur.
            pass


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
            gestionnaire = _FichierJournal(chemin)
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
        self._bilan = (0, None)
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
                connue = cles.get(str(kid))
                if isinstance(connue, dict) and connue.get("cle") == cle:
                    continue
                # Un kid deja associe a une autre cle ne peut venir que d'une
                # enveloppe dont le kid (hors signature) a ete altere : le
                # bulletin, lui, est signe et fait foi.
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
            jusqu = payload.get("suspendue_jusqu")
            if code == "suspendue" and _entier(jusqu):
                local["refus"]["jusqu"] = jusqu
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
                if code in _CODES_REVOCATION:
                    # Licence revoquee (ou poste libere) avant la remise de la cle :
                    # la demande n'aboutira plus. L'essai s'arrete et la fenetre
                    # d'activation permet une nouvelle demande ou la saisie d'une cle.
                    self._log(logging.WARNING, "demande %s : licence %s avant la remise de la cle",
                              demande.get("numero"), code)
                    local["demande"] = None
                    local["dernier_controle"] = reel
                    self._revoque = code
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
        succes = False
        try:
            succes = self._controler_une_fois(delai_max)
            return succes
        finally:
            # Bilan lu par "Verifier maintenant" : nombre de controles termines et
            # resultat du dernier (l'heure du dernier controle ne suffit pas : elle
            # ne change pas quand il n'y a rien a verifier).
            with self._verrou:
                self._bilan = (self._bilan[0] + 1, succes)

    def _a_verifier(self):
        """Vrai si un controle a un objet : une cle a valider ou une demande en attente."""
        with self._verrou:
            local = self._local or {}
            demande = local.get("demande")
            return bool(local.get("cle")) or (isinstance(demande, dict) and demande.get("statut") == "en_attente")

    def _controler_une_fois(self, delai_max=None):
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
                if code == "suspendue":
                    # Cle conservee : le poste se debloque seul au premier controle
                    # qui suit la reactivation ou la date de fin.
                    fin = refus.get("jusqu")
                    if _entier(fin):
                        return EXPIREE, ("Licence suspendue jusqu'au %s. Elle sera reactivee automatiquement "
                                         "a cette date (Reessayer)." % _date(fin)), None
                    return EXPIREE, "Licence suspendue. Contactez ETDEL pour la reactiver.", None
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
            # Jamais avant le controle suivant (6 h) plus une heure non plus : avec une
            # tolerance de 0 jour (7 h), un poste connecte verrait sinon le bandeau
            # entre deux controles.
            debut_alerte = max(hors_ligne - int(p.get("preavis_j", 5)) * JOUR,
                               p["emis"] + (hors_ligne - p["emis"]) // 2,
                               p["emis"] + _INTERVALLE_CONTROLE + 3600)
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
        """Option de la distribution lue dans le jeton signe ; False si absente.

        Le joker "*" (console : toutes les options) active toute option demandee.
        """
        try:
            statut = self.etat()["statut"]
            with self._verrou:
                options = []
                if statut in (VALIDE, AVERTISSEMENT) and self._payload:
                    options = self._payload.get("options", [])
                elif statut == ESSAI:
                    options = (self._local.get("demande") or {}).get("options") or []
                return OPTION_TOUTES in options or code in options
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

    def _cle_affichee(self, complete=False):
        """Cle du poste pour la fenetre Licence : masquee comme dans la console
        (ETDEL-****-****-****-XXXX), ou complete sur demande ; "" sans cle."""
        with self._verrou:
            cle = (self._local or {}).get("cle")
        if not cle:
            return ""
        return cle if complete else "ETDEL-****-****-****-" + cle[-4:]

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
                           (self._integration.palette_app if self._integration else None), parent, self._log)
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

# Couleurs de statut (pastilles, icones, encadres). Les fonds teintes sont
# derives de la palette : ils restent lisibles sur un theme sombre. "orange"
# est un ambre, distinct de l'accent par defaut : une alerte ne se confond
# pas avec une page neutre.
_TONS = {"vert": "#1a7f37", "bleu": "#0969da", "orange": "#9a6700", "rouge": "#cf222e"}
# Statut -> (ton, libelle de la pastille de la fenetre Licence).
_PASTILLES = {
    VALIDE: ("vert", "Valide"), ESSAI: ("bleu", "Essai"), AVERTISSEMENT: ("orange", "Attention"),
    DEMANDE_EN_ATTENTE: ("bleu", "En attente"), DEMANDE_REFUSEE: ("rouge", "Refusee"),
    EXPIREE: ("rouge", "Bloquee"), REVOQUEE: ("rouge", "Revoquee"),
    VERSION_REFUSEE: ("rouge", "Mise a jour requise"), A_ACTIVER: ("gris", "Non activee"),
    NON_CONFIGURE: ("gris", "Non configuree"),
}
# Glyphes des polices d'icones de Windows 10 et 11 (codes : le fichier reste en
# ASCII). Sans ces polices, un "i" ou un "!" les remplace.
_GLYPHES = {"cle": chr(0xE8D7), "envoi": chr(0xE724), "ok": chr(0xE73E), "horloge": chr(0xE823),
            "alerte": chr(0xE7BA), "refus": chr(0xE711), "maj": chr(0xE898), "info": chr(0xE946),
            "licence": chr(0xEA18)}
_POLICES_ICONES = ("Segoe Fluent Icons", "Segoe MDL2 Assets")
_FAMILLE_ICONES = [None]
# Largeur utile des pages de la fenetre d'activation, en pixels a 96 ppp.
_LARGEUR = 540


def _tk():
    import tkinter
    return tkinter


_COULEURS_PALETTE = ("fond", "panneau", "texte", "discret", "accent", "accent_texte")


def _palette(palette, widget=None, journal=None, fenetres=False):
    """Palette de l'application completee par PALETTE_DEFAUT.

    Avec widget : toute couleur ou police que Tk refuse est remplacee par sa
    valeur par defaut (une couleur invalide empecherait sinon d'afficher la
    fenetre d'activation). fenetres : une palette qui donne "fond" sans
    "panneau" garde ce fond pour le corps des fenetres, son role jusqu'a la 1.3.0.
    """
    resultat = dict(PALETTE_DEFAUT)
    if not isinstance(palette, dict):
        return resultat
    fournies = dict((k, v) for k, v in palette.items() if k in PALETTE_DEFAUT and v)
    if widget is not None:
        for cle, valeur in list(fournies.items()):
            try:
                if cle in _COULEURS_PALETTE:
                    widget.winfo_rgb(valeur)
                else:
                    widget.tk.call("font", "actual", valeur)
            except Exception:
                del fournies[cle]
                if journal is not None:
                    journal(logging.WARNING, "palette : %s invalide (%r), valeur par defaut", cle, valeur)
    resultat.update(fournies)
    if fenetres and "fond" in fournies and "panneau" not in fournies:
        resultat["panneau"] = fournies["fond"]
    return resultat


def _raison_lisible(raison):
    """Echec d'un controle en une phrase pour l'utilisateur ; le detail
    technique (serveurs essayes, erreurs) reste dans la ligne de diagnostic."""
    raison = _ascii(raison)
    if not raison or raison.startswith("serveur injoignable"):
        return _MESSAGES["injoignable"]
    return raison


def _afficher_message(parent, titre, texte):
    from tkinter import messagebox
    messagebox.showerror(titre, texte, parent=parent)


def _ecrire_champ(entree, texte):
    # Reecrit seulement si le texte change : une selection en cours est conservee.
    if entree.get() == texte:
        return
    entree.configure(state="normal")
    entree.delete(0, "end")
    entree.insert(0, texte)
    entree.configure(state="readonly")


def _configurer(widget, **options):
    """configure() limite aux options qui changent (rafraichissement sans scintillement)."""
    changees = dict((cle, valeur) for cle, valeur in options.items()
                    if str(widget.cget(cle)) != str(valeur))
    if changees:
        widget.configure(**changees)


def _differer(widget, delais, ms, fonction):
    """after() memorise dans delais, a annuler a la destruction (_annuler) :
    un after en attente sur un widget detruit finit en erreur Tcl sur stderr."""
    ident = []

    def appel():
        delais.discard(ident[0])
        try:
            fonction()
        except Exception:
            pass

    ident.append(widget.after(ms, appel))
    delais.add(ident[0])


def _annuler(widget, delais):
    for ident in list(delais):
        try:
            widget.after_cancel(ident)
        except Exception:
            pass
    delais.clear()


def _rgb(widget, couleur):
    return [valeur / 65535.0 for valeur in widget.winfo_rgb(couleur)]


def _melange(widget, couleur1, couleur2, part):
    """Couleur situee a part (0 a 1) du chemin de couleur1 a couleur2, en #rrggbb."""
    a, b = _rgb(widget, couleur1), _rgb(widget, couleur2)
    return "#%02x%02x%02x" % tuple(int(round(255 * (x + (y - x) * part))) for x, y in zip(a, b))


def _clarte(widget, couleur):
    r, v, b = _rgb(widget, couleur)
    return 0.2126 * r + 0.7152 * v + 0.0722 * b


def _contraste(widget, couleur1, couleur2):
    """Rapport de contraste WCAG (1 a 21) entre deux couleurs."""
    def luminance(couleur):
        r, v, b = [x / 12.92 if x <= 0.03928 else ((x + 0.055) / 1.055) ** 2.4 for x in _rgb(widget, couleur)]
        return 0.2126 * r + 0.7152 * v + 0.0722 * b
    a, b = sorted((luminance(couleur1), luminance(couleur2)))
    return (b + 0.05) / (a + 0.05)


def _police(tkapp, police, delta=0, gras=None, souligne=False):
    """Variante d'une police Tk (tuple ou chaine) ; une police nommee est rendue telle quelle."""
    try:
        morceaux = list(police) if isinstance(police, (tuple, list)) else list(tkapp.splitlist(police))
        if len(morceaux) < 2:
            return police
        famille, taille = morceaux[0], int(morceaux[1])
        styles = []
        for morceau in morceaux[2:]:
            styles.extend(str(morceau).split())
        if gras is not None:
            styles = [m for m in styles if m not in ("bold", "normal")] + (["bold"] if gras else [])
        if souligne and "underline" not in styles:
            styles.append("underline")
        # Taille negative : en pixels.
        taille = taille + delta if taille > 0 else taille - delta
        return tuple([famille, taille] + styles)
    except Exception:
        return police


def _famille_icones(widget):
    if _FAMILLE_ICONES[0] is None:
        famille = ""
        try:
            from tkinter import font
            presentes = set(font.families(widget))
            for nom in _POLICES_ICONES:
                if nom in presentes:
                    famille = nom
                    break
        except Exception:
            pass
        _FAMILLE_ICONES[0] = famille
    return _FAMILLE_ICONES[0]


class _Style(object):
    """Palette de l'application, completee de couleurs et de polices derivees.

    Seules les cles de PALETTE_DEFAUT viennent de l'application ; bordures,
    survols, fonds teintes et couleurs de statut en sont calcules, pour qu'un
    theme (clair ou sombre) s'applique a tous les composants.
    """

    def __init__(self, widget, pal):
        self.pal = pal
        try:
            # Interface a l'echelle si l'application declare la prise en charge du DPI.
            self.k = max(1.0, float(widget.winfo_fpixels("1i")) / 96.0)
        except Exception:
            self.k = 1.0
        self.c = self._couleurs(widget, pal)
        try:
            self.sombre = _clarte(widget, self.c["panneau"]) < 0.45
        except Exception:
            self.sombre = False
        tkapp = widget.tk
        police = pal["police"]
        self.texte = police
        self.gras = _police(tkapp, police, 0, True)
        self.petit = _police(tkapp, police, -1)
        self.petit_gras = _police(tkapp, police, -1, True)
        self.lien = _police(tkapp, police, -1, None, True)
        self.saisie = _police(tkapp, police, 1)
        self.carte = _police(tkapp, police, 1, True)
        self.titre = _police(tkapp, pal["police_titre"], 2)
        self.champ = pal["police_champ"]
        self.cle = _police(tkapp, pal["police_champ"], 2)
        self.icones = _famille_icones(widget)

    def px(self, n):
        return int(round(n * self.k))

    def icone(self, taille):
        return (self.icones, -self.px(taille))

    @staticmethod
    def _couleurs(widget, pal):
        c = dict((cle, pal[cle]) for cle in _COULEURS_PALETTE)
        tons = dict(_TONS, accent=pal["accent"], gris=pal["discret"])
        try:
            def m(a, b, part):
                return _melange(widget, a, b, part)

            # Texte illisible sur "panneau" (palette partielle d'un theme sombre) :
            # les fenetres reprennent "fond", leur fond jusqu'a la 1.3.0.
            sur_panneau = _contraste(widget, c["texte"], c["panneau"])
            if sur_panneau < 3 and _contraste(widget, c["texte"], c["fond"]) > sur_panneau:
                c["panneau"] = c["fond"]
            panneau, texte = c["panneau"], c["texte"]
            sombre = _clarte(widget, panneau) < 0.45
            if _rgb(widget, c["fond"]) == _rgb(widget, panneau):
                # Pied des fenetres (zone des boutons) a peine distinct du corps.
                c["fond"] = m(panneau, "#000000", 0.25) if sombre else m(panneau, texte, 0.05)
            c["bordure"] = m(panneau, texte, 0.25 if sombre else 0.16)
            c["survol"] = m(panneau, texte, 0.08 if sombre else 0.045)
            c["lecture"] = m(panneau, texte, 0.06 if sombre else 0.04)
            c["champ"] = m(panneau, "#000000", 0.2) if sombre else panneau
            c["accent_survol"] = m(pal["accent"], "#ffffff" if sombre else "#000000", 0.15)
            c["accent_inactif"] = m(pal["accent"], panneau, 0.5)
            c["inactif"] = m(texte, panneau, 0.55)
            for nom, base in tons.items():
                # Sur fond sombre, une couleur foncee est eclaircie pour rester lisible.
                origine = m(base, "#ffffff", 0.35) if sombre and _clarte(widget, base) < 0.45 else base
                # Liens, icones, barre du haut : contraste d'au moins 4,5:1 avec le
                # panneau (un accent jaune sur fond blanc est rapproche du texte).
                fort, pas = origine, 0
                while pas < 10 and _contraste(widget, fort, panneau) < 4.5:
                    pas += 1
                    fort = m(origine, texte, pas / 10.0)
                c[nom + "_fort"] = fort
                c[nom + "_doux"] = m(panneau, base, 0.2 if sombre else 0.1)
                c[nom + "_bord"] = m(panneau, base, 0.45 if sombre else 0.35)
        except Exception:
            c.update(bordure=pal["discret"], survol=pal["panneau"], lecture=pal["panneau"],
                     champ=pal["panneau"], accent_survol=pal["accent"], accent_inactif=pal["discret"],
                     inactif=pal["discret"])
            for nom, base in tons.items():
                c[nom + "_fort"], c[nom + "_doux"], c[nom + "_bord"] = base, pal["panneau"], base
        return c


def _bouton(parent, s, texte, commande, genre="secondaire", fond=None):
    """Bouton plat : "principal" (accent), "secondaire" (bord fin) ou "lien" (texte seul).

    Renvoie le tk.Button ; bouton.cadre est le widget a placer (pack, grid).
    Sous Windows, Tk ne dessine pas highlightbackground autour d'un bouton :
    le bord d'un bouton secondaire (1 pixel, accent au focus clavier) et
    l'anneau de focus d'un bouton principal (2 pixels, couleur du texte) sont
    donc un cadre. Un lien se souligne au survol et au focus clavier.
    """
    tk = _tk()
    c = s.c
    cadre = None
    if genre == "principal":
        normal, survol, inactif = c["accent"], c["accent_survol"], c["accent_inactif"]
        options = dict(fg=c["accent_texte"], activeforeground=c["accent_texte"],
                       disabledforeground=c["accent_texte"], font=s.gras, padx=s.px(16), pady=s.px(4),
                       highlightthickness=0)
        cadre, epaisseur = tk.Frame(parent, bg=normal), s.px(2)
    elif genre == "lien":
        normal = survol = inactif = fond or c["panneau"]
        options = dict(fg=c["accent_fort"], activeforeground=c["accent_survol"],
                       disabledforeground=c["inactif"], font=s.petit, padx=s.px(2), pady=0,
                       highlightthickness=0)
    else:
        normal, survol, inactif = c["panneau"], c["survol"], c["panneau"]
        options = dict(fg=c["texte"], activeforeground=c["texte"], disabledforeground=c["inactif"],
                       font=s.texte, padx=s.px(14), pady=s.px(6), highlightthickness=0)
        cadre, epaisseur = tk.Frame(parent, bg=c["bordure"]), 1
    bouton = tk.Button(cadre if cadre is not None else parent, text=texte, command=commande, relief="flat",
                       bd=0, cursor="hand2", bg=normal, activebackground=survol, **options)
    bouton._etdel = (normal, inactif)
    bouton.cadre = bouton
    if cadre is not None:
        bouton.pack(padx=epaisseur, pady=epaisseur)
        bouton.cadre = cadre
    etat = {"survol": False, "focus": False}

    def peindre():
        try:
            actif = str(bouton.cget("state")) != "disabled"
            if genre == "lien":
                bouton.configure(font=s.lien if actif and (etat["survol"] or etat["focus"]) else s.petit)
                return
            bg = (survol if etat["survol"] else normal) if actif else inactif
            bouton.configure(bg=bg)
            if genre == "principal":
                cadre.configure(bg=c["texte"] if etat["focus"] else bg)
            else:
                cadre.configure(bg=c["accent_fort"] if etat["focus"] else c["bordure"])
        except Exception:
            pass

    def changer(cle, valeur):
        etat[cle] = valeur
        peindre()

    bouton._etdel_peindre = peindre
    bouton.bind("<Enter>", lambda _e: changer("survol", True), add="+")
    bouton.bind("<Leave>", lambda _e: changer("survol", False), add="+")
    bouton.bind("<FocusIn>", lambda _e: changer("focus", True), add="+")
    bouton.bind("<FocusOut>", lambda _e: changer("focus", False), add="+")
    return bouton


def _invoquer_bouton(widget):
    """Entree sur un bouton qui a le focus clavier : ce bouton, pas l'action
    principale de la fenetre (usage de Windows). Renvoie True si widget est un
    bouton (evenement traite, meme s'il est grise)."""
    try:
        if widget.winfo_class() != "Button":
            return False
    except Exception:
        return False
    try:
        if str(widget.cget("state")) != "disabled":
            widget.invoke()
    except Exception:
        pass
    return True


def _activer_bouton(bouton, actif):
    normal, inactif = getattr(bouton, "_etdel", (None, None))
    options = {"state": "normal" if actif else "disabled", "cursor": "hand2" if actif else "arrow"}
    if normal is not None:
        options["bg"] = normal if actif else inactif
    bouton.configure(**options)
    peindre = getattr(bouton, "_etdel_peindre", None)
    if peindre is not None:
        peindre()


def _badge(parent, s, icone, ton, taille, fond):
    """Disque teinte portant une icone, en tete des fenetres et des cartes."""
    tk = _tk()
    c = s.c
    d = s.px(taille)
    canevas = tk.Canvas(parent, width=d, height=d, bg=fond, highlightthickness=0, bd=0)
    doux, fort = c[ton + "_doux"], c[ton + "_fort"]
    try:
        # Liseret de couleur intermediaire : bord du disque moins crenele.
        canevas.create_oval(0, 0, d, d, fill=_melange(canevas, fond, doux, 0.5), outline="")
    except Exception:
        pass
    canevas.create_oval(1, 1, d - 1, d - 1, fill=doux, outline="")
    if s.icones:
        canevas.create_text(d / 2.0, d / 2.0, text=_GLYPHES.get(icone, ""), fill=fort,
                            font=s.icone(taille * 0.42))
    else:
        canevas.create_text(d / 2.0, d / 2.0, fill=fort, font=s.carte,
                            text="!" if icone in ("alerte", "refus", "maj") else "i")
    return canevas


def _encadre(parent, s, ton, icone=None):
    """Encadre teinte a barre laterale (information, succes, alerte) ; renvoie (cadre, zone de texte)."""
    tk = _tk()
    c = s.c
    doux, fort = c[ton + "_doux"], c[ton + "_fort"]
    cadre = tk.Frame(parent, bg=doux)
    tk.Frame(cadre, bg=fort, width=s.px(3)).pack(side="left", fill="y")
    interieur = tk.Frame(cadre, bg=doux, padx=s.px(12), pady=s.px(8))
    interieur.pack(side="left", fill="both", expand=True)
    if icone and s.icones:
        tk.Label(interieur, text=_GLYPHES[icone], font=s.icone(16), bg=doux, fg=fort, bd=0).pack(
            side="left", anchor="n", padx=(0, s.px(10)), pady=(s.px(1), 0))
    zone = tk.Frame(interieur, bg=doux)
    zone.pack(side="left", fill="both", expand=True)
    return cadre, zone


def _bord_actif(cadre, champ, s):
    """Bord du cadre a la couleur d'accent tant que le champ a le focus (rouge
    tant que cadre.erreur est vrai)."""
    c = s.c
    cadre.erreur = False

    def changer(actif):
        try:
            if cadre.erreur:
                couleur = c["rouge_fort"]
            else:
                couleur = c["accent_fort"] if actif else c["bordure"]
            # Tk dessine highlightcolor quand le focus est dans le cadre (champ
            # compris), highlightbackground sinon : les deux suivent l'etat.
            cadre.configure(highlightbackground=couleur, highlightcolor=couleur)
        except Exception:
            pass

    cadre.changer_bord = changer
    champ.bind("<FocusIn>", lambda _e: changer(True), add="+")
    champ.bind("<FocusOut>", lambda _e: changer(False), add="+")


def _saisie(parent, s, police=None, lecture_seule=False, valeur=""):
    """Champ de saisie plat : bord fin, marge interieure, bord d'accent au focus."""
    tk = _tk()
    c = s.c
    fond = c["lecture"] if lecture_seule else c["champ"]
    cadre = tk.Frame(parent, bg=fond, highlightthickness=1, highlightbackground=c["bordure"],
                     highlightcolor=c["bordure"])
    entree = tk.Entry(cadre, width=12, font=police or s.saisie, relief="flat", bd=0, highlightthickness=0,
                      bg=fond, fg=c["texte"], readonlybackground=fond, disabledbackground=fond,
                      insertbackground=c["texte"], selectbackground=c["accent"],
                      selectforeground=c["accent_texte"])
    entree.pack(fill="x", padx=s.px(9), pady=s.px(6))
    if valeur:
        entree.insert(0, valeur)
    if lecture_seule:
        entree.configure(state="readonly")
    else:
        _bord_actif(cadre, entree, s)
    cadre.bind("<Button-1>", lambda _e: entree.focus_set())
    return cadre, entree


def _carte(parent, s, icone, titre, description, commande):
    """Carte de choix entierement cliquable ; renvoie (cadre, bouton du titre)."""
    tk = _tk()
    c = s.c
    fond = c["panneau"]
    cadre = tk.Frame(parent, bg=fond, highlightthickness=1, highlightbackground=c["bordure"],
                     highlightcolor=c["bordure"], padx=s.px(16), pady=s.px(16), cursor="hand2")
    badge = _badge(cadre, s, icone, "accent", 36, fond)
    badge.configure(cursor="hand2")
    badge.pack(anchor="w")
    bouton = tk.Button(cadre, text=titre, command=commande, font=s.carte, bg=fond, fg=c["texte"],
                       activebackground=fond, activeforeground=c["accent_fort"],
                       disabledforeground=c["inactif"], relief="flat", bd=0, highlightthickness=0,
                       padx=0, pady=0, anchor="w", cursor="hand2")
    bouton._etdel = (fond, fond)
    bouton.pack(anchor="w", fill="x", pady=(s.px(12), s.px(4)))
    texte = tk.Label(cadre, text=description, font=s.texte, bg=fond, fg=c["discret"], justify="left",
                     anchor="w", bd=0, padx=0, wraplength=s.px(_LARGEUR // 2 - 40), cursor="hand2")
    texte.pack(anchor="w", fill="x")
    elements = (cadre, badge, bouton, texte)
    # Survol : fond teinte et bord d'accent ; focus clavier (Tab) : bord d'accent.
    etat = {"survol": False, "focus": False}

    def peindre():
        actif = etat["survol"] or etat["focus"]
        bg = c["survol"] if etat["survol"] else fond
        bord = c["accent_fort"] if actif else c["bordure"]
        try:
            # highlightcolor : dessine quand le focus est dans la carte (son bouton).
            cadre.configure(bg=bg, highlightbackground=bord, highlightcolor=bord)
            for widget in elements[1:]:
                widget.configure(bg=bg)
            bouton.configure(activebackground=bg, fg=c["accent_fort"] if actif else c["texte"])
            bouton._etdel = (bg, bg)
        except Exception:
            pass

    def survol(actif):
        etat["survol"] = actif
        peindre()

    def focus(actif):
        etat["focus"] = actif
        peindre()

    bouton.bind("<FocusIn>", lambda _e: focus(True), add="+")
    bouton.bind("<FocusOut>", lambda _e: focus(False), add="+")

    def sortie(_e):
        # Passer d'un element de la carte a un autre n'est pas une sortie.
        try:
            x, y = cadre.winfo_pointerxy()
            dessous = cadre.winfo_containing(x, y)
            while dessous is not None:
                if dessous is cadre:
                    return
                dessous = dessous.master
        except Exception:
            pass
        survol(False)

    def clic(_e):
        if str(bouton.cget("state")) != "disabled":
            commande()

    for widget in elements:
        widget.bind("<Enter>", lambda _e: survol(True), add="+")
        widget.bind("<Leave>", sortie, add="+")
        if widget is not bouton:
            widget.bind("<Button-1>", clic, add="+")
    return cadre, bouton


def _etapes(parent, s, fond, courante=1):
    """Frise des trois etapes d'une demande ; courante : indice de l'etape en cours."""
    tk = _tk()
    c = s.c
    largeur, hauteur = s.px(_LARGEUR), s.px(54)
    canevas = tk.Canvas(parent, width=largeur, height=hauteur, bg=fond, highlightthickness=0, bd=0)
    noms = ("Demande envoyee", "Traitement par ETDEL", "Licence activee")
    xs = [largeur * (2 * i + 1) / 6.0 for i in range(3)]
    r, y = s.px(10), s.px(12)
    for i in range(2):
        canevas.create_line(xs[i] + r + s.px(8), y, xs[i + 1] - r - s.px(8), y, width=s.px(2),
                            fill=c["vert_fort"] if i < courante else c["bordure"])
    for i, x in enumerate(xs):
        if i < courante:
            canevas.create_oval(x - r, y - r, x + r, y + r, fill=c["vert_fort"], outline="")
            if s.icones:
                canevas.create_text(x, y, text=_GLYPHES["ok"], fill=c["panneau"], font=s.icone(11))
            else:
                canevas.create_line(x - r / 2.0, y, x - r / 6.0, y + r / 3.0, x + r / 2.0, y - r / 3.0,
                                    fill=c["panneau"], width=s.px(2))
        elif i == courante:
            canevas.create_oval(x - r, y - r, x + r, y + r, fill=c["bleu_doux"], outline=c["bleu_fort"],
                                width=s.px(2))
            p = s.px(4)
            canevas.create_oval(x - p, y - p, x + p, y + p, fill=c["bleu_fort"], outline="")
        else:
            canevas.create_oval(x - r, y - r, x + r, y + r, fill=fond, outline=c["bordure"], width=s.px(2))
        canevas.create_text(x, y + r + s.px(13), text=noms[i], font=s.petit_gras if i == courante else s.petit,
                            fill=c["texte"] if i <= courante else c["discret"])
    return canevas


def _haut_borne(fenetre, y, hauteur):
    """Ordonnee bornee : le bas de la fenetre (barre de titre comprise) reste
    au-dessus de la barre des taches de l'ecran principal quand c'est possible."""
    try:
        k = max(1.0, float(fenetre.winfo_fpixels("1i")) / 96.0)
        ecran = fenetre.winfo_screenheight()
        if 0 <= y < ecran:
            # Environ 32 px de barre de titre et de bords, 48 px de barre des taches.
            y = min(y, ecran - int(48 * k) - int(32 * k) - hauteur)
    except Exception:
        pass
    return max(0, y)


def _centrer(fenetre, reference=None):
    """Place la fenetre au centre de la fenetre de reference si elle est visible, sinon de l'ecran."""
    try:
        fenetre.update_idletasks()
        largeur, hauteur = fenetre.winfo_reqwidth(), fenetre.winfo_reqheight()
        if reference is not None and reference.winfo_viewable():
            cx = reference.winfo_rootx() + reference.winfo_width() // 2
            cy = reference.winfo_rooty() + reference.winfo_height() // 2
        else:
            cx, cy = fenetre.winfo_screenwidth() // 2, fenetre.winfo_screenheight() // 2
        fenetre.geometry("+%d+%d" % (cx - largeur // 2, _haut_borne(fenetre, cy - hauteur // 2, hauteur)))
    except Exception:
        pass


def _recentrer(fenetre):
    """Apres un changement de page : meme centre, nouvelle taille."""
    try:
        taille, _plus, position = fenetre.geometry().partition("+")
        largeur, hauteur = [int(v) for v in taille.split("x")]
        x, y = [int(v) for v in position.split("+")]
        fenetre.update_idletasks()
        nouvelle_l, nouvelle_h = fenetre.winfo_reqwidth(), fenetre.winfo_reqheight()
        fenetre.geometry("+%d+%d" % (x + (largeur - nouvelle_l) // 2,
                                     _haut_borne(fenetre, y + (hauteur - nouvelle_h) // 2, nouvelle_h)))
    except Exception:
        pass


def _habiller_fenetre(fenetre, root, s):
    """Windows : icone de la fenetre principale, et barre de titre sombre avec
    une palette sombre (Windows 10 2004 et suivants, sans effet ailleurs)."""
    if sys.platform != "win32":
        return
    try:
        import ctypes
        from ctypes import wintypes
        fenetre.update_idletasks()
        hwnd = int(fenetre.wm_frame(), 16)
        if s.sombre:
            vrai = ctypes.c_int(1)
            # DWMWA_USE_IMMERSIVE_DARK_MODE : 20, ou 19 avant Windows 10 2004.
            if ctypes.windll.dwmapi.DwmSetWindowAttribute(hwnd, 20, ctypes.byref(vrai), 4) != 0:
                ctypes.windll.dwmapi.DwmSetWindowAttribute(hwnd, 19, ctypes.byref(vrai), 4)
        # Prototype propre au module : celui de ctypes.windll, partage avec
        # l'application, n'est pas modifie.
        envoyer = ctypes.WINFUNCTYPE(ctypes.c_ssize_t, wintypes.HWND, wintypes.UINT, wintypes.WPARAM,
                                     wintypes.LPARAM)(("SendMessageW", ctypes.windll.user32))
        source = int(root.wm_frame(), 16)
        for taille in (0, 1):  # ICON_SMALL, ICON_BIG
            icone = envoyer(source, 0x007F, taille, 0)  # WM_GETICON
            if icone:
                envoyer(hwnd, 0x0080, taille, icone)  # WM_SETICON
    except Exception:
        pass


def _brut_cle(texte):
    return "".join(ch for ch in (texte or "").upper() if ch.isascii() and ch.isalnum())


def _mettre_en_forme_cle(texte):
    """Saisie de cle en cours -> (texte a afficher, caracteres saisis hors prefixe).

    Majuscules, tirets places tous les 4 caracteres, prefixe ETDEL ajoute ;
    les caracteres hors alphabet restent visibles pour etre signales.
    """
    brut = _brut_cle(texte)
    if brut.startswith("ETDEL"):
        corps = brut[5:21]
    elif "ETDEL".startswith(brut):
        # Prefixe en cours de frappe (ou champ vide).
        return brut, ""
    else:
        corps = brut[:16]
    if not corps:
        return "ETDEL", ""
    return "ETDEL-" + "-".join(corps[i:i + 4] for i in range(0, len(corps), 4)), corps


def _position_apres(texte, rang):
    """Indice qui suit le rang-ieme caractere alphanumerique de texte."""
    if rang <= 0:
        return 0
    vus = 0
    for indice, ch in enumerate(texte):
        if ch.isascii() and ch.isalnum():
            vus += 1
            if vus == rang:
                return indice + 1
    return len(texte)


class _CadreLicence(object):
    """Etat de la licence en lecture seule : fenetre Licence et page de reglages d'une application.

    actions : cadre ou placer "Verifier maintenant" et son retour (pied de la
    fenetre Licence) ; a defaut, sous les informations du cadre.
    """

    AUCUNE = "Aucune licence a verifier sur ce poste."

    def __init__(self, parent, garde, pal, style=None, encadre=True, actions=None):
        tk = _tk()
        self.garde = garde
        self.s = s = style or _Style(parent, pal)
        c = s.c
        fond = c["panneau"]
        self.cadre = tk.Frame(parent, bg=fond)
        if encadre:
            # Carte a bord fin : se detache du fond de la page de reglages.
            self.cadre.configure(padx=s.px(20), pady=s.px(14), highlightthickness=1,
                                 highlightbackground=c["bordure"], highlightcolor=c["bordure"])
        self.cadre.columnconfigure(1, weight=1)
        self.valeurs = {}
        self._apres = None
        self._delais = set()
        self._verification = None
        self._verifiable = None
        self._message_visible = False

        # Statut : pastille de couleur (vert, bleu, ambre, rouge) et message.
        haut = tk.Frame(self.cadre, bg=fond)
        haut.grid(row=0, column=0, columnspan=2, sticky="we", pady=(0, s.px(10)))
        self.pastille = tk.Label(haut, font=s.petit_gras, padx=s.px(9), pady=s.px(2), bd=0)
        self.pastille.pack(side="left", anchor="n", pady=(s.px(1), 0))
        self.valeurs["statut"] = tk.Label(haut, bg=fond, fg=c["texte"], font=s.gras, anchor="w",
                                          justify="left", wraplength=s.px(420))
        self.valeurs["statut"].pack(side="left", fill="x", expand=True, padx=(s.px(10), 0))

        def libelle(rang, texte):
            tk.Label(self.cadre, text=texte, bg=fond, fg=c["discret"], font=s.texte, anchor="w").grid(
                row=rang, column=0, sticky="w", padx=(0, s.px(20)), pady=s.px(2))

        def valeur(rang, cle):
            etiquette = tk.Label(self.cadre, bg=fond, fg=c["texte"], font=s.texte, anchor="w",
                                 justify="left", wraplength=s.px(300))
            etiquette.grid(row=rang, column=1, sticky="w", pady=s.px(2))
            self.valeurs[cle] = etiquette

        def code(rang, cle, largeur, texte_lien, commande):
            # Champ en lecture seule : selectionnable pour une dictee ou un copier-coller ;
            # suivi de son lien d'action (Copier, Afficher la cle).
            cellule = tk.Frame(self.cadre, bg=fond)
            cellule.grid(row=rang, column=1, sticky="w", pady=s.px(2))
            boite = tk.Frame(cellule, bg=c["lecture"])
            boite.pack(side="left")
            entree = tk.Entry(boite, font=s.champ, width=largeur, relief="flat", bd=0, highlightthickness=0,
                              fg=c["texte"], readonlybackground=c["lecture"], selectbackground=c["accent"],
                              selectforeground=c["accent_texte"])
            entree.pack(padx=s.px(8), pady=s.px(2))
            entree.configure(state="readonly")
            self.valeurs[cle] = entree
            bouton = _bouton(cellule, s, texte_lien, commande, "lien", fond)
            # Largeur fixe : le libelle change ("Copie !", "Masquer la cle") sans decalage.
            bouton.configure(width=len(texte_lien), anchor="w")
            bouton.pack(side="left", padx=(s.px(10), 0))
            return bouton

        for rang, (cle, texte) in enumerate((("titulaire", "Titulaire"), ("echeance", "Echeance"),
                                             ("dernier_controle", "Dernier controle")), 1):
            libelle(rang, texte)
            valeur(rang, cle)
        tk.Frame(self.cadre, bg=c["bordure"], height=1).grid(row=4, column=0, columnspan=2, sticky="we",
                                                             pady=s.px(8))
        libelle(5, "Identifiant du poste")
        copier = code(5, "id_poste", 10, "Copier", None)
        copier.configure(command=lambda: self._copier(self.valeurs["id_poste"].get(), copier, "Copier"))
        libelle(6, "Cle")
        # Cle masquee comme dans la console (4 derniers caracteres, pour s'y retrouver
        # au telephone) ; affichee en entier a la demande, pour la noter avant une
        # reinstallation. Jamais dans le diagnostic ni dans le journal.
        self.cle_visible = False
        self.bouton_cle = code(6, "cle", 26, "Afficher la cle", self._basculer_cle)
        libelle(7, "Nom de l'ordinateur")
        valeur(7, "nom_ordinateur")
        # Message de la distribution (saisi dans la console), affiche s'il existe.
        self.boite_message, zone = _encadre(self.cadre, s, "bleu", "info")
        self.boite_message.grid(row=8, column=0, columnspan=2, sticky="we", pady=(s.px(10), 0))
        self.message = tk.Label(zone, bg=c["bleu_doux"], fg=c["texte"], font=s.texte, anchor="w",
                                justify="left", wraplength=s.px(430))
        self.message.pack(fill="x")
        self.boite_message.grid_remove()
        dans_pied = actions is not None
        if not dans_pied:
            actions = tk.Frame(self.cadre, bg=fond)
            actions.grid(row=9, column=0, columnspan=2, sticky="we", pady=(s.px(14), 0))
        self.bouton_verifier = _bouton(actions, s, "Verifier maintenant", self._verifier, "secondaire")
        self.bouton_verifier.cadre.pack(side="left")
        self.retour_verification = tk.Label(actions, bg=actions.cget("bg"), fg=c["discret"], font=s.petit,
                                            anchor="w", justify="left", wraplength=s.px(220 if dans_pied else 300))
        self.retour_verification.pack(side="left", fill="x", expand=True, padx=(s.px(12), s.px(8)))
        # Diagnostic : texte a transmettre au support (jamais la cle en clair),
        # renvoye a la ligne entre les mots, selectionnable (Ctrl+C) et copiable.
        tk.Label(self.cadre, text="Diagnostic", bg=fond, fg=c["discret"], font=s.texte, anchor="w").grid(
            row=10, column=0, sticky="w", pady=(s.px(12), s.px(4)))
        copier_diagnostic = _bouton(self.cadre, s, "Copier le diagnostic", None, "lien", fond)
        copier_diagnostic.configure(width=len("Copier le diagnostic"), anchor="e", command=lambda: self._copier(
            self.garde.texte_diagnostic(), copier_diagnostic, "Copier le diagnostic"))
        copier_diagnostic.grid(row=10, column=1, sticky="e", pady=(s.px(12), s.px(4)))
        self.diagnostic = tk.Text(self.cadre, font=s.petit, width=1, height=2, wrap="word", relief="flat", bd=0,
                                  highlightthickness=0, padx=s.px(8), pady=s.px(5), bg=c["lecture"],
                                  fg=c["discret"], selectbackground=c["accent"], selectforeground=c["accent_texte"],
                                  inactiveselectbackground=c["accent"], insertwidth=0, cursor="xterm")
        self.diagnostic.grid(row=11, column=0, columnspan=2, sticky="we")
        self.diagnostic.configure(state="disabled")
        self.diagnostic.bind("<Configure>", self.ajuster_diagnostic, add="+")
        self.cadre.bind("<Destroy>", self._sur_destruction, add="+")
        self.rafraichir()

    def _ecrire_diagnostic(self, texte):
        # Reecrit seulement si le texte change : une selection en cours est conservee.
        if self.diagnostic.get("1.0", "end-1c") == texte:
            return
        self.diagnostic.configure(state="normal")
        self.diagnostic.delete("1.0", "end")
        self.diagnostic.insert("1.0", texte)
        self.diagnostic.configure(state="disabled")
        self.ajuster_diagnostic()

    def ajuster_diagnostic(self, _evenement=None):
        """Hauteur du diagnostic : son nombre de lignes affichees (1 a 4)."""
        try:
            lignes = self.diagnostic.count("1.0", "end", "update", "displaylines")
            if isinstance(lignes, (tuple, list)):
                lignes = lignes[0]
            lignes = max(1, min(4, int(lignes or 1)))
            if lignes != int(self.diagnostic.cget("height")):
                self.diagnostic.configure(height=lignes)
        except Exception:
            pass

    def _sur_destruction(self, evenement):
        # Un after en attente sur un widget detruit finit en erreur Tcl sur stderr.
        if evenement.widget is self.cadre:
            if self._apres is not None:
                try:
                    self.cadre.after_cancel(self._apres)
                except Exception:
                    pass
                self._apres = None
            _annuler(self.cadre, self._delais)

    def _copier(self, texte, bouton, libelle):
        try:
            self.cadre.clipboard_clear()
            self.cadre.clipboard_append(texte)
            bouton.configure(text="Copie !")
            _differer(self.cadre, self._delais, 1500, lambda: bouton.configure(text=libelle))
        except Exception:
            pass

    def _afficher_cle(self):
        texte = self.garde._cle_affichee(self.cle_visible)
        _ecrire_champ(self.valeurs["cle"], texte or "-")
        _configurer(self.bouton_cle, text="Masquer la cle" if self.cle_visible else "Afficher la cle")
        if (str(self.bouton_cle.cget("state")) != "disabled") != bool(texte):
            _activer_bouton(self.bouton_cle, bool(texte))

    def _basculer_cle(self):
        self.cle_visible = not self.cle_visible
        try:
            self._afficher_cle()
        except Exception:
            pass

    def _verifier(self):
        try:
            c = self.s.c
            if self._verification is not None:
                return
            if not self.garde._a_verifier():
                # Ni cle ni demande en attente : rien a demander au serveur.
                self.retour_verification.configure(text=self.AUCUNE, fg=c["discret"])
                return
            self._verification = (time.monotonic(), self.garde._bilan[0])
            _activer_bouton(self.bouton_verifier, False)
            self.retour_verification.configure(text="Verification en cours...", fg=c["discret"])
            self.garde.controler_maintenant()
        except Exception:
            pass

    def _suivre_verification(self):
        if self._verification is None:
            return
        debut, avant = self._verification
        termines, succes = self.garde._bilan
        ecoule = time.monotonic() - debut
        # Le controle tourne dans un fil ; "en cours" reste affiche au moins 1,5 s.
        if ecoule < 1.5 or (termines == avant and ecoule < 90):
            return
        self._verification = None
        # Disponibilite du bouton reevaluee juste apres (rafraichir).
        self._verifiable = None
        c = self.s.c
        e = self.garde.etat()
        if termines == avant or not succes:
            texte, couleur = _raison_lisible(e["raison"]), c["rouge_fort"]
        elif e["statut"] in (VALIDE, AVERTISSEMENT):
            texte, couleur = "Licence verifiee aupres du serveur.", c["vert_fort"]
        elif e["statut"] in (ESSAI, DEMANDE_EN_ATTENTE):
            texte, couleur = "Verifie : demande toujours en cours de traitement.", c["discret"]
        else:
            texte, couleur = "Verification terminee.", c["discret"]
        self.retour_verification.configure(text=texte, fg=couleur)

    def _disponibilite(self):
        """"Verifier maintenant" grise quand il n'y a ni cle ni demande en attente."""
        if self._verification is not None:
            return
        possible = self.garde._a_verifier()
        if possible == self._verifiable:
            return
        self._verifiable = possible
        _activer_bouton(self.bouton_verifier, possible)
        if not possible:
            self.retour_verification.configure(text=self.AUCUNE, fg=self.s.c["discret"])
        elif self.retour_verification.cget("text") == self.AUCUNE:
            self.retour_verification.configure(text="")

    def rafraichir(self):
        self._apres = None
        try:
            if not self.cadre.winfo_exists():
                return
            c = self.s.c
            e = self.garde.etat()
            ton, libelle = _PASTILLES.get(e["statut"], ("gris", e["statut"]))
            _configurer(self.pastille, text=libelle, bg=c[ton + "_doux"], fg=c[ton + "_fort"])
            _ecrire_champ(self.valeurs["id_poste"], e["id_poste"] or "")
            self._afficher_cle()
            if e["echeance"]:
                echeance = _date(e["echeance"])
                if e["jours_restants"]:
                    echeance += " (dans %d jour(s))" % e["jours_restants"]
            elif e["statut"] in (VALIDE, AVERTISSEMENT):
                echeance = "Perpetuelle"
            else:
                echeance = "-"
            textes = {"nom_ordinateur": _ascii(e["nom_ordinateur"]) or "-",
                      "titulaire": _ascii(e["titulaire"]) or "-", "echeance": echeance,
                      "statut": e["message"] or libelle,
                      "dernier_controle": _date_heure(e["dernier_controle"]) or "Jamais"}
            for cle, texte in textes.items():
                _configurer(self.valeurs[cle], text=texte)
            accueil = _ascii(self.garde._payload.get("message")) if self.garde._payload else ""
            _configurer(self.message, text=accueil)
            if bool(accueil) != self._message_visible:
                self._message_visible = bool(accueil)
                if accueil:
                    self.boite_message.grid()
                else:
                    self.boite_message.grid_remove()
            self._suivre_verification()
            self._disponibilite()
            self._ecrire_diagnostic(self.garde.texte_diagnostic())
            self._apres = self.cadre.after(1000, self.rafraichir)
        except Exception:
            pass


class _FenetreLicence(object):
    """Fenetre ouverte par Ctrl+Maj+L (ou un clic sur le bandeau)."""

    def __init__(self, integ):
        tk = _tk()
        self.integ = integ
        s = integ.style()
        c = s.c
        fond = c["panneau"]
        garde = integ.garde
        self.win = tk.Toplevel(integ.root)
        # Construite cachee puis centree : rien ne saute a l'ecran.
        self.win.withdraw()
        self.win.title("Licence - %s" % _ascii(garde.produit))
        self.win.configure(bg=fond)
        self.win.resizable(False, False)
        tk.Frame(self.win, bg=c["accent_fort"], height=s.px(4)).pack(fill="x")
        # Pied : "Verifier maintenant" et son retour a gauche, "Fermer" a droite.
        pied = tk.Frame(self.win, bg=c["fond"], padx=s.px(28), pady=s.px(10))
        pied.pack(side="bottom", fill="x")
        tk.Frame(self.win, bg=c["bordure"], height=1).pack(side="bottom", fill="x")
        self.bouton_fermer = _bouton(pied, s, "Fermer", self.fermer, "principal")
        self.bouton_fermer.cadre.pack(side="right")
        actions = tk.Frame(pied, bg=c["fond"])
        actions.pack(side="left", fill="x", expand=True)
        entete = tk.Frame(self.win, bg=fond)
        entete.pack(fill="x", padx=s.px(28), pady=(s.px(16), 0))
        _badge(entete, s, "licence", "accent", 44, fond).pack(side="left", anchor="n")
        textes = tk.Frame(entete, bg=fond)
        textes.pack(side="left", fill="x", expand=True, padx=(s.px(16), 0))
        tk.Label(textes, text="Licence", font=s.titre, bg=fond, fg=c["texte"], anchor="w").pack(fill="x")
        tk.Label(textes, text="%s - version %s" % (_ascii(garde.produit), _ascii(garde.version)), font=s.texte,
                 bg=fond, fg=c["discret"], anchor="w").pack(fill="x", pady=(s.px(2), 0))
        self.cadre = _CadreLicence(self.win, garde, integ.pal, s, encadre=False, actions=actions)
        self.cadre.cadre.pack(fill="both", expand=True, padx=s.px(28), pady=(s.px(14), s.px(16)))
        self.win.protocol("WM_DELETE_WINDOW", self.fermer)
        for sequence in ("<Return>", "<KP_Enter>"):
            self.win.bind(sequence, self._sur_entree)
        self.win.bind("<Escape>", lambda _e: self.fermer())
        # Diagnostic a sa hauteur definitive avant le centrage.
        self.win.update_idletasks()
        self.cadre.ajuster_diagnostic()
        _centrer(self.win, integ.root)
        _habiller_fenetre(self.win, integ.root, s)
        self.win.deiconify()
        self.win.lift()
        try:
            self.bouton_fermer.focus_set()
        except Exception:
            pass

    def _sur_entree(self, evenement):
        # Entree sur un bouton qui a le focus (Verifier maintenant, Copier...) : ce
        # bouton, comme sous Windows ; ailleurs : fermer.
        if not _invoquer_bouton(evenement.widget):
            self.fermer()
        return "break"

    def fermer(self):
        try:
            self.win.destroy()
        finally:
            self.integ.fenetre_licence = None


class _FenetreActivation(object):
    """Fenetre modale : A_ACTIVER, DEMANDE_EN_ATTENTE, DEMANDE_REFUSEE, EXPIREE, VERSION_REFUSEE.

    Rien n'est envoye au serveur sans clic explicite. Fermer quitte l'application.
    Entree active le bouton qui a le focus clavier, sinon (dans un champ)
    l'action principale de la page ; Echap revient en arriere depuis la saisie
    d'une cle ou le formulaire de demande, et ferme la page "Demande envoyee".
    """

    PAGES_STATUT = ("accueil", "attente", "refus", "expiree", "version")
    AIDE_TITULAIRE = "Societe ou personne a qui la licence est destinee"

    def __init__(self, integ):
        tk = _tk()
        self.integ = integ
        self.garde = integ.garde
        self.pal = integ.pal
        self.s = integ.style()
        self.file = queue.Queue()
        self.occupe = False
        self.ferme = False
        self.page = None
        self.corps = None
        self.contenu = None
        self.pied = None
        self.boutons = []
        self.principal = None
        self.echap = None
        self.erreur = None
        self.info = None
        self._apres = None
        self._apres_cle = None
        self._message_cle = None
        self._formatage = False
        self._place = False
        self._delais = set()
        integ.root.withdraw()
        self.win = tk.Toplevel(integ.root)
        # Construite cachee puis centree : aucune fenetre ne saute a l'ecran.
        self.win.withdraw()
        self.win.title("Licence - %s" % self.garde.produit)
        self.win.configure(bg=self.s.c["panneau"])
        self.win.resizable(False, False)
        self.win.protocol("WM_DELETE_WINDOW", integ.quitter)
        self.win.bind("<Destroy>", self._sur_destruction, add="+")
        for sequence in ("<Return>", "<KP_Enter>"):
            self.win.bind(sequence, self._sur_entree, add="+")
        self.win.bind("<Escape>", self._sur_echap, add="+")
        try:
            self.afficher_statut()
        except Exception:
            # Fenetre cachee inachevee : detruite, l'appelant (ouvrir_activation)
            # prend le relais ; l'application ne reste jamais invisible.
            try:
                self.win.destroy()
            except Exception:
                pass
            self.ferme = True
            raise
        if not self.ferme:
            self._apres = self.win.after(200, self._sonder)

    def _sur_destruction(self, evenement):
        if evenement.widget is self.win:
            self.ferme = True
            for apres in (self._apres, self._apres_cle):
                if apres is not None:
                    try:
                        self.win.after_cancel(apres)
                    except Exception:
                        pass
            self._apres = self._apres_cle = None
            _annuler(self.win, self._delais)

    # -- construction -------------------------------------------------------

    def _nouvelle_page(self, nom, titre, sous_titre="", icone="cle", ton="accent", e=None):
        """En-tete (icone, titre, explication), contenu, pied (identifiant du poste si e, actions)."""
        tk = _tk()
        s, c = self.s, self.s.c
        fond = c["panneau"]
        if self._apres_cle is not None:
            try:
                self.win.after_cancel(self._apres_cle)
            except Exception:
                pass
            self._apres_cle = None
        if self.corps is not None:
            self.corps.destroy()
        self.page = nom
        self.boutons = []
        self.principal = None
        self.echap = None
        self.erreur = None
        self.info = None
        self._message_cle = None
        self.corps = tk.Frame(self.win, bg=fond)
        self.corps.pack(fill="both", expand=True)
        tk.Frame(self.corps, bg=c[ton + "_fort"], height=s.px(4)).pack(fill="x")
        self.pied = tk.Frame(self.corps, bg=c["fond"], padx=s.px(28), pady=s.px(10))
        self.pied.pack(side="bottom", fill="x")
        tk.Frame(self.corps, bg=c["bordure"], height=1).pack(side="bottom", fill="x")
        if e is not None:
            self._identifiant(e)
        entete = tk.Frame(self.corps, bg=fond)
        entete.pack(fill="x", padx=s.px(28), pady=(s.px(16), 0))
        _badge(entete, s, icone, ton, 44, fond).pack(side="left", anchor="n")
        textes = tk.Frame(entete, bg=fond)
        textes.pack(side="left", fill="x", expand=True, padx=(s.px(16), 0))
        tk.Label(textes, text=titre, font=s.titre, bg=fond, fg=c["texte"], anchor="w", bd=0,
                 padx=0).pack(fill="x")
        if sous_titre:
            tk.Label(textes, text=sous_titre, font=s.texte, bg=fond, fg=c["discret"], anchor="w", bd=0, padx=0,
                     justify="left", wraplength=s.px(_LARGEUR - 60)).pack(fill="x", pady=(s.px(3), 0))
        self.contenu = tk.Frame(self.corps, bg=fond)
        self.contenu.pack(fill="both", expand=True, padx=s.px(28), pady=(s.px(14), s.px(16)))
        # Meme largeur pour toutes les pages.
        tk.Frame(self.contenu, bg=fond, width=s.px(_LARGEUR), height=1).pack()

    def _texte(self, texte, parent=None, couleur=None, police=None, fond=None, largeur=_LARGEUR,
               haut=0, bas=8):
        tk = _tk()
        s, c = self.s, self.s.c
        label = tk.Label(parent or self.contenu, text=texte, justify="left", anchor="w", bd=0, padx=0,
                         wraplength=s.px(largeur), bg=fond or c["panneau"], fg=couleur or c["texte"],
                         font=police or s.texte)
        label.pack(fill="x", pady=(s.px(haut), s.px(bas)))
        return label

    def _libelle(self, parent, texte, mention=None):
        """Libelle de champ en gras, suivi d'une mention discrete (obligatoire, facultatif)."""
        tk = _tk()
        s, c = self.s, self.s.c
        ligne = tk.Frame(parent, bg=c["panneau"])
        tk.Label(ligne, text=texte, font=s.gras, bg=c["panneau"], fg=c["texte"]).pack(side="left")
        if mention:
            tk.Label(ligne, text=mention, font=s.petit, bg=c["panneau"], fg=c["discret"]).pack(
                side="left", padx=(s.px(6), 0))
        return ligne

    def _boite(self, ton, lignes, icone=None, haut=0, bas=0):
        """Encadre teinte ; lignes : (texte, "normal" | "gras" | "discret" | "titre")."""
        s, c = self.s, self.s.c
        cadre, zone = _encadre(self.contenu, s, ton, icone)
        cadre.pack(fill="x", pady=(s.px(haut), s.px(bas)))
        largeur = _LARGEUR - 30 - (30 if icone and s.icones else 0)
        for rang, (texte, genre) in enumerate(lignes):
            police = {"gras": s.gras, "titre": s.petit_gras}.get(genre, s.texte)
            couleur = {"discret": c["discret"], "titre": c[ton + "_fort"]}.get(genre, c["texte"])
            self._texte(texte, zone, couleur, police, c[ton + "_doux"], largeur, haut=3 if rang else 0, bas=0)

    def _boutons(self, definitions):
        """(texte, commande, genre) de gauche a droite ; l'action principale en dernier."""
        tk = _tk()
        s, c = self.s, self.s.c
        rangee = tk.Frame(self.pied, bg=c["fond"])
        rangee.pack(side="right")
        for texte, commande, genre in definitions:
            bouton = _bouton(rangee, s, texte, commande, genre)
            bouton.cadre.pack(side="left", padx=(s.px(8), 0))
            self.boutons.append(bouton)
            if genre == "principal":
                self.principal = bouton

    def _identifiant(self, e):
        # A dicter au support : toujours visible au pied des pages d'etat.
        tk = _tk()
        s, c = self.s, self.s.c
        identifiant = e.get("id_poste") or ""
        tk.Label(self.pied, text="Identifiant du poste : %s" % identifiant, font=s.petit, bg=c["fond"],
                 fg=c["discret"]).pack(side="left")
        copier = _bouton(self.pied, s, "Copier", None, "lien", c["fond"])
        copier.configure(width=7, anchor="w", command=lambda: self._copier(identifiant, copier))
        copier.pack(side="left", padx=(s.px(4), 0))

    def _copier(self, texte, bouton):
        try:
            self.win.clipboard_clear()
            self.win.clipboard_append(texte)
            bouton.configure(text="Copie !")
            _differer(self.win, self._delais, 1500, lambda: bouton.configure(text="Copier"))
        except Exception:
            pass

    def _placer(self):
        try:
            if self._place:
                _recentrer(self.win)
            else:
                self._place = True
                _centrer(self.win)
                _habiller_fenetre(self.win, self.integ.root, self.s)
                self.win.deiconify()
                self.win.lift()
        except Exception:
            pass

    def _sur_entree(self, evenement):
        # Entree sur un bouton qui a le focus clavier : ce bouton (Entree sur
        # "Retour" revient en arriere, n'envoie jamais la demande) ; dans un champ
        # ou sur la fenetre : l'action principale de la page ; dans le mot pour
        # ETDEL : retour a la ligne.
        try:
            if self.occupe:
                return "break"
            widget = evenement.widget
            if _invoquer_bouton(widget):
                return "break"
            if self.principal is None or widget.winfo_class() == "Text":
                return None
            if str(self.principal.cget("state")) != "disabled":
                self.principal.invoke()
        except Exception:
            pass
        return None

    def _sur_echap(self, _evenement=None):
        if self.echap is not None and not self.occupe:
            try:
                self.echap()
            except Exception:
                pass

    @staticmethod
    def _focus_voisin(evenement, suivant):
        # Tab dans le mot pour ETDEL : champ suivant plutot qu'une tabulation.
        try:
            voisin = evenement.widget.tk_focusNext() if suivant else evenement.widget.tk_focusPrev()
            if voisin is not None:
                voisin.focus_set()
        except Exception:
            pass
        return "break"

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
        tk = _tk()
        e = e or self.garde.etat()
        s, c = self.s, self.s.c
        self._nouvelle_page("accueil", "Licence requise", "Cette application necessite une licence ETDEL.",
                            "cle", "accent", e)
        if e["statut"] == REVOQUEE:
            self._boite("rouge", [(e["message"], "normal")], "alerte", bas=16)
        cartes = tk.Frame(self.contenu, bg=c["panneau"])
        cartes.pack(fill="x")
        for colonne in (0, 1):
            cartes.columnconfigure(colonne, weight=1, uniform="carte")
        choix = (("cle", "J'ai une cle", "Saisissez la cle de licence fournie par ETDEL.", self.page_cle),
                 ("envoi", "Demander une licence",
                  "Pas encore de cle ? Envoyez une demande a ETDEL depuis ce poste.", self.page_formulaire))
        for colonne, (icone, titre, description, commande) in enumerate(choix):
            carte, bouton = _carte(cartes, s, icone, titre, description, commande)
            carte.grid(row=0, column=colonne, sticky="nsew",
                       padx=(0, s.px(6)) if colonne == 0 else (s.px(6), 0))
            self.boutons.append(bouton)
        self._boutons([("Quitter", self.integ.quitter, "secondaire")])
        self._placer()
        # Clavier : la premiere carte a le focus (bord d'accent) ; Tab passe a
        # la suivante, Entree ou Espace ouvre celle qui a le focus.
        self.boutons[0].focus_set()

    def page_cle(self):
        s, c = self.s, self.s.c
        self._nouvelle_page("cle", "J'ai une cle", "Saisissez la cle de licence fournie par ETDEL.", "cle")
        self._libelle(self.contenu, "Cle de licence").pack(fill="x", pady=(0, s.px(6)))
        cadre, self.entree_cle = _saisie(self.contenu, s, police=s.cle)
        cadre.pack(fill="x")
        # Mise en forme pendant la frappe : majuscules, tirets, prefixe ETDEL.
        self.entree_cle.configure(validate="key",
                                  validatecommand=(self.entree_cle.register(self._sur_saisie_cle),))
        self.erreur = self._texte("", couleur=c["discret"], haut=8, bas=0)
        self._indiquer_cle("")
        self._boutons([("Retour", self.afficher_statut, "secondaire"), ("Activer", self._activer, "principal")])
        self.echap = self.afficher_statut
        self._placer()
        self.entree_cle.focus_set()

    def page_formulaire(self):
        tk = _tk()
        s, c = self.s, self.s.c
        fond = c["panneau"]
        self._nouvelle_page("formulaire", "Demander une licence",
                            "ETDEL etudie la demande puis attribue une licence a ce poste.", "envoi")
        gauche, droite = (0, s.px(8)), (s.px(8), 0)
        grille = tk.Frame(self.contenu, bg=fond)
        grille.pack(fill="x")
        for colonne in (0, 1):
            grille.columnconfigure(colonne, weight=1, uniform="champ")
        self._libelle(grille, "Titulaire", "obligatoire").grid(row=0, column=0, sticky="we", padx=gauche,
                                                               pady=(0, s.px(6)))
        self._libelle(grille, "E-mail", "facultatif").grid(row=0, column=1, sticky="we", padx=droite,
                                                           pady=(0, s.px(6)))
        self.cadre_titulaire, self.entree_titulaire = _saisie(grille, s)
        self.cadre_titulaire.grid(row=1, column=0, sticky="we", padx=gauche)
        cadre_email, self.entree_email = _saisie(grille, s)
        cadre_email.grid(row=1, column=1, sticky="we", padx=droite)
        # Aide sous le champ ; remplacee par l'erreur si le titulaire manque.
        self.aide_titulaire = tk.Label(grille, text=self.AIDE_TITULAIRE, font=s.petit, bg=fond, fg=c["discret"],
                                       anchor="w")
        self.aide_titulaire.grid(row=2, column=0, columnspan=2, sticky="w", pady=(s.px(4), 0))
        self.entree_titulaire.bind("<Key>", self._titulaire_saisi, add="+")
        tete = tk.Frame(self.contenu, bg=fond)
        tete.pack(fill="x", pady=(s.px(16), s.px(6)))
        self._libelle(tete, "Mot pour ETDEL", "facultatif, %d caracteres maximum" % _MESSAGE_MAX).pack(side="left")
        self.compteur = tk.Label(tete, text="0/%d" % _MESSAGE_MAX, font=s.petit, bg=fond, fg=c["discret"])
        self.compteur.pack(side="right")
        cadre_mot = tk.Frame(self.contenu, bg=c["champ"], highlightthickness=1, highlightbackground=c["bordure"],
                             highlightcolor=c["bordure"])
        cadre_mot.pack(fill="x")
        self.texte_mot = tk.Text(cadre_mot, width=10, height=3, wrap="word", font=s.saisie, relief="flat", bd=0,
                                 highlightthickness=0, bg=c["champ"], fg=c["texte"], insertbackground=c["texte"],
                                 selectbackground=c["accent"], selectforeground=c["accent_texte"],
                                 padx=s.px(9), pady=s.px(6))
        self.texte_mot.pack(fill="x")
        _bord_actif(cadre_mot, self.texte_mot, s)
        self.texte_mot.bind("<KeyRelease>", self._borner_mot)
        self.texte_mot.bind("<Tab>", lambda ev: self._focus_voisin(ev, True))
        self.texte_mot.bind("<Shift-Tab>", lambda ev: self._focus_voisin(ev, False))
        poste = tk.Frame(self.contenu, bg=fond)
        poste.pack(fill="x", pady=(s.px(16), 0))
        for colonne in (0, 1):
            poste.columnconfigure(colonne, weight=1, uniform="champ")
        self._libelle(poste, "Nom de l'ordinateur").grid(row=0, column=0, sticky="we", padx=gauche,
                                                         pady=(0, s.px(6)))
        cadre_nom, _entree = _saisie(poste, s, lecture_seule=True, valeur=self.garde._poste)
        cadre_nom.grid(row=1, column=0, sticky="we", padx=gauche)
        tk.Label(poste, text="Transmis avec la demande.", font=s.petit, bg=fond, fg=c["discret"],
                 anchor="w").grid(row=1, column=1, sticky="w", padx=droite)
        # Mention obligatoire, visible avant l'envoi.
        self._boite("bleu", [(MENTION_DELAI, "normal")], "info", haut=18)
        self.erreur = self._texte("", couleur=c["rouge_fort"], haut=10, bas=0)
        self._boutons([("Retour", self.afficher_statut, "secondaire"),
                       ("Envoyer la demande", self._envoyer, "principal")])
        self.echap = self.afficher_statut
        self._placer()
        self.entree_titulaire.focus_set()

    def page_envoyee(self, e):
        s, c = self.s, self.s.c
        self._nouvelle_page("envoyee", "Demande envoyee", self.garde._texte_demande_envoyee(), "ok", "vert", e)
        _etapes(self.contenu, s, c["panneau"], 1).pack(fill="x", pady=(0, s.px(16)))
        self._boite("vert", [("En attendant la reponse, l'application est utilisable pendant %d jour(s)."
                              % (e["jours_restants"] or 0), "gras"),
                             ("La licence s'activera automatiquement des que la demande sera acceptee.",
                              "normal")], "ok")
        self._boutons([("Continuer", self.fermer, "principal")])
        # Fermer est permis ici (l'essai est ouvert) : Echap comme Continuer.
        self.echap = self.fermer
        self._placer()

    def page_attente(self, e):
        s, c = self.s, self.s.c
        self._nouvelle_page("attente", "Demande en attente", self.garde._texte_demande_envoyee(), "horloge",
                            "bleu", e)
        _etapes(self.contenu, s, c["panneau"], 1).pack(fill="x", pady=(0, s.px(16)))
        demande = (self.garde._local or {}).get("demande") or {}
        if demande.get("essai_jusqu"):
            essai = "La periode d'essai est terminee."
        else:
            essai = "Aucune periode d'essai n'est disponible pour ce poste."
        self._boite("bleu", [(essai, "gras"),
                             ("L'application s'ouvrira des que la demande sera acceptee.", "normal"),
                             ("La reponse est verifiee automatiquement chaque minute.", "discret")], "info")
        self.info = self._texte("", couleur=c["discret"], police=s.petit, haut=10, bas=0)
        self._boutons([("J'ai une cle", self.page_cle, "secondaire"),
                       ("Verifier maintenant", self._verifier, "principal")])
        self.garde._planifier_au_plus_tard(_INTERVALLE_ATTENTE)
        self._placer()

    def page_refus(self, e):
        self._nouvelle_page("refus", "Demande refusee", "ETDEL n'a pas donne suite a la demande de licence.",
                            "refus", "rouge", e)
        motif = _ascii(e.get("motif_refus"))
        if motif:
            self._boite("rouge", [("Motif", "titre"), (motif, "normal")], bas=16)
        self._texte("Vous pouvez envoyer une nouvelle demande ou saisir une cle fournie par ETDEL.",
                    bas=0)
        self._boutons([("J'ai une cle", self.page_cle, "secondaire"),
                       ("Nouvelle demande", self.page_formulaire, "principal")])
        self._placer()

    def page_expiree(self, e):
        refus = (self.garde._local or {}).get("refus")
        suspendue = isinstance(refus, dict) and refus.get("code") == "suspendue"
        ton = "rouge" if suspendue else "orange"
        self._nouvelle_page("expiree", "Licence suspendue" if suspendue else "Licence a verifier",
                            "L'application ne peut pas s'ouvrir pour le moment.", "alerte", ton, e)
        self._boite(ton, [(e["message"], "normal")])
        self.info = self._texte("", couleur=self.s.c["discret"], police=self.s.petit, haut=10, bas=0)
        self._boutons([("J'ai une cle", self.page_cle, "secondaire"), ("Reessayer", self._verifier, "principal")])
        self._placer()

    def page_version(self, e):
        self._nouvelle_page("version", "Mise a jour necessaire",
                            "Cette version de l'application n'est plus acceptee.", "maj", "orange", e)
        self._boite("orange", [(e["message"], "normal")])
        self.info = self._texte("", couleur=self.s.c["discret"], police=self.s.petit, haut=10, bas=0)
        self._boutons([("Quitter", self.integ.quitter, "secondaire"), ("Reessayer", self._verifier, "principal")])
        self._placer()

    # -- actions ------------------------------------------------------------

    def _borner_mot(self, _evenement=None):
        texte = self.texte_mot.get("1.0", "end-1c")
        if len(texte) > _MESSAGE_MAX:
            self.texte_mot.delete("1.0", "end")
            self.texte_mot.insert("1.0", texte[:_MESSAGE_MAX])
            texte = texte[:_MESSAGE_MAX]
        c = self.s.c
        self.compteur.configure(text="%d/%d" % (len(texte), _MESSAGE_MAX),
                                fg=c["orange_fort"] if len(texte) > _MESSAGE_MAX - 20 else c["discret"])

    def _sur_saisie_cle(self):
        # validatecommand : la mise en forme est differee, car modifier le champ
        # pendant la validation desactiverait celle-ci.
        if not self._formatage and self._apres_cle is None and not self.ferme:
            self._apres_cle = self.win.after_idle(self._formater_cle)
        return True

    def _formater_cle(self):
        self._apres_cle = None
        try:
            entree = self.entree_cle
            ancien = entree.get()
            nouveau, _corps = _mettre_en_forme_cle(ancien)
            if nouveau != ancien:
                position = entree.index("insert")
                rang = sum(1 for ch in ancien[:position] if ch.isascii() and ch.isalnum())
                if nouveau.startswith("ETDEL-") and not _brut_cle(ancien).startswith("ETDEL"):
                    rang += 5
                self._formatage = True
                try:
                    entree.delete(0, "end")
                    entree.insert(0, nouveau)
                finally:
                    self._formatage = False
                entree.icursor("end" if position >= len(ancien) else _position_apres(nouveau, rang))
            self._indiquer_cle(nouveau)
        except Exception:
            pass

    def _indiquer_cle(self, texte):
        """Indication en direct sous le champ. Un message (cle refusee, erreur du
        serveur) reste affiche tant que la saisie ne change pas."""
        if self.erreur is None or self.occupe:
            return
        if self._message_cle is not None:
            if _brut_cle(texte) == self._message_cle:
                return
            self._message_cle = None
        s, c = self.s, self.s.c
        _affiche, corps = _mettre_en_forme_cle(texte)
        invalides = sorted(set(ch for ch in corps.translate(_CORRECTIONS) if ch not in _ALPHABET))
        if not corps:
            message, couleur = "Format : ETDEL-XXXX-XXXX-XXXX-XXXX (les tirets s'ajoutent seuls)", c["discret"]
        elif invalides:
            message, couleur = "Caractere non valide : %s" % ", ".join(invalides), c["rouge_fort"]
        elif len(corps) < 16:
            message, couleur = "Cle incomplete : %d/16 caracteres" % len(corps), c["discret"]
        elif normaliser_cle(texte):
            message, couleur = "Format correct : cliquez sur Activer.", c["vert_fort"]
        else:
            message, couleur = "Cle incorrecte : verifiez chaque caractere.", c["rouge_fort"]
        self.erreur.configure(text=message, fg=couleur, font=s.petit)

    def _message_saisie(self, texte):
        # Message sur la page de la cle ; conserve tant que la saisie ne change pas.
        if self.erreur is None:
            return
        self.erreur.configure(text=texte, fg=self.s.c["rouge_fort"], font=self.s.texte)
        try:
            self._message_cle = _brut_cle(self.entree_cle.get())
        except Exception:
            self._message_cle = None

    def _en_fond(self, fonction, rappel, attente="Connexion au serveur..."):
        if self.occupe:
            return
        self.occupe = True
        for bouton in self.boutons:
            _activer_bouton(bouton, False)
        cible = self.erreur or self.info
        if cible is not None:
            cible.configure(text=attente, fg=self.s.c["discret"])

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
            self._message_saisie("Cle invalide : verifiez la saisie (ETDEL-XXXX-XXXX-XXXX-XXXX)")
            return
        self._en_fond(lambda: self.garde.activer(cle), self._apres_action)

    def _envoyer(self):
        titulaire = self.entree_titulaire.get().strip()
        if not titulaire:
            # Erreur sous le champ, entoure de rouge, qui reprend le focus.
            c = self.s.c
            self.aide_titulaire.configure(text="Le titulaire est obligatoire.", fg=c["rouge_fort"])
            self.cadre_titulaire.erreur = True
            self.cadre_titulaire.changer_bord(True)
            self.entree_titulaire.focus_set()
            return
        email = self.entree_email.get()
        mot = self.texte_mot.get("1.0", "end-1c")[:_MESSAGE_MAX]
        self._en_fond(lambda: self.garde.demander(titulaire, email, mot), self._apres_demande)

    def _titulaire_saisi(self, _evenement=None):
        # Premiere frappe apres l'erreur : l'aide d'origine revient.
        try:
            if self.cadre_titulaire.erreur:
                self.cadre_titulaire.erreur = False
                self.cadre_titulaire.changer_bord(True)
                self.aide_titulaire.configure(text=self.AIDE_TITULAIRE, fg=self.s.c["discret"])
        except Exception:
            pass

    def _apres_action(self, resultat):
        if resultat.get("ok"):
            self.afficher_statut()
        elif self.page == "cle":
            self._message_saisie(resultat.get("message") or "")
        elif self.erreur is not None:
            self.erreur.configure(text=resultat.get("message") or "", fg=self.s.c["rouge_fort"])

    def _apres_demande(self, resultat):
        if not resultat.get("ok"):
            self.erreur.configure(text=resultat.get("message") or "", fg=self.s.c["rouge_fort"])
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
            c = self.s.c
            if succes is True:
                suite = "pas encore de reponse" if self.page == "attente" else "situation inchangee"
                texte, couleur = "Verifie le %s : %s." % (_date_heure(int(time.time())), suite), c["discret"]
            else:
                texte, couleur = _raison_lisible(e["raison"]), c["rouge_fort"]
            self.info.configure(text=texte, fg=couleur)

    def _sonder(self):
        if self.ferme:
            return
        try:
            while True:
                rappel, resultat = self.file.get_nowait()
                self.occupe = False
                for bouton in self.boutons:
                    try:
                        _activer_bouton(bouton, True)
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
        self.palette_app = palette
        self.pal = _palette(palette, root, garde._log, fenetres=True)
        self.fenetre = None
        self.fenetre_licence = None
        self.bandeau = None
        self.termine = False
        self._origine = ""
        self._apres = []
        self._style = None

    def style(self):
        # Calcule a la premiere fenetre, pas dans installer() (moins de 50 ms).
        if self._style is None:
            self._style = _Style(self.root, self.pal)
        return self._style

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
        if self.fenetre is not None:
            return
        try:
            fenetre = _FenetreActivation(self)
        except Exception as exc:
            # Jamais d'application invisible : nouvel essai avec la palette par
            # defaut, puis, a defaut, message et fermeture.
            self.garde._log(logging.ERROR, "fenetre d'activation : %r", exc)
            self.pal, self._style = dict(PALETTE_DEFAUT), None
            try:
                fenetre = _FenetreActivation(self)
            except Exception as exc2:
                self.garde._log(logging.ERROR, "fenetre d'activation (palette par defaut) : %r", exc2)
                try:
                    _afficher_message(self.root, "Licence", self.garde.etat()["message"] or "Licence requise")
                finally:
                    self.quitter()
                return
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
        statut = e["statut"]
        if statut in (AVERTISSEMENT, ESSAI) and self.fenetre is None:
            if self.bandeau is None:
                self._creer_bandeau()
            self._habiller_bandeau(statut)
            _configurer(self.bandeau, text=e["message"])
            # Superposition : ne depend ni de pack ni de grid dans l'application.
            self.bandeau.place(relx=0, rely=0, relwidth=1)
            self.bandeau.lift()
        elif self.bandeau is not None:
            self.bandeau.place_forget()

    def _creer_bandeau(self):
        tk = _tk()
        s = self.style()
        marge = s.px(34)
        # Le bandeau est un Label (texte : etat()["message"]) ; l'icone et le lien
        # y sont places et disparaissent avec lui. Un clic ouvre la fenetre Licence.
        # Hauteur compacte (environ 30 px a 100 %) : il recouvre le haut de la
        # fenetre de l'application (voir INTEGRATION.md).
        self.bandeau = tk.Label(self.root, font=s.texte, anchor="w", justify="left", padx=marge,
                                pady=s.px(5), bd=0, highlightthickness=1, cursor="hand2")
        self._bandeau_icone = tk.Label(self.bandeau, bd=0, cursor="hand2",
                                       font=s.icone(13) if s.icones else s.gras)
        self._bandeau_icone.place(x=s.px(12), rely=0.5, anchor="w")
        self._bandeau_lien = tk.Label(self.bandeau, text="Details", bd=0, cursor="hand2", font=s.gras)
        self._bandeau_lien.place(relx=1.0, x=-s.px(14), rely=0.5, anchor="e")
        souligne = _police(self.root.tk, s.gras, 0, None, True)
        self._bandeau_lien.bind("<Enter>", lambda _e: self._bandeau_lien.configure(font=souligne), add="+")
        self._bandeau_lien.bind("<Leave>", lambda _e: self._bandeau_lien.configure(font=s.gras), add="+")
        for widget in (self.bandeau, self._bandeau_icone, self._bandeau_lien):
            widget.bind("<Button-1>", lambda _e: self.ouvrir_licence(), add="+")

        def ajuster(evenement):
            # Le message passe a la ligne avant le lien plutot que dessous.
            try:
                largeur = max(s.px(120), evenement.width - 2 * marge - self._bandeau_lien.winfo_reqwidth())
                if str(self.bandeau.cget("wraplength")) != str(largeur):
                    self.bandeau.configure(wraplength=largeur)
            except Exception:
                pass

        self.bandeau.bind("<Configure>", ajuster, add="+")

    def _habiller_bandeau(self, statut):
        s = self.style()
        c = s.c
        if statut == AVERTISSEMENT:
            # Action attendue : couleurs d'accent de l'application.
            fond, texte, vif, bord, icone = c["accent"], c["accent_texte"], c["accent_texte"], c["accent"], "alerte"
        else:
            # Essai : simple information, fond teinte discret.
            fond, texte, vif, bord, icone = c["bleu_doux"], c["texte"], c["bleu_fort"], c["bleu_bord"], "info"
        glyphe = _GLYPHES[icone] if s.icones else ("!" if icone == "alerte" else "i")
        _configurer(self.bandeau, bg=fond, fg=texte, highlightbackground=bord, highlightcolor=bord)
        _configurer(self._bandeau_icone, bg=fond, fg=vif, text=glyphe)
        _configurer(self._bandeau_lien, bg=fond, fg=vif)


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
