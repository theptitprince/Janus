# demo_appli.py
# Role : application Tkinter minimale, banc d'essai de bout en bout du module
#        etdel_licence (activation, demande, bandeau, fenetre Licence, options).
#        Banc d'essai uniquement : --serveur, --cle-publique et --banc remplacent
#        les constantes du module, ce qu'aucune application livree ne doit faire.
#        Exemples : python demo_appli.py --banc   (banc local, voir README.md)
#                   python demo_appli.py --serveur http://127.0.0.1:8090/api/v1/ --cle-publique <cle>
# ETDEL (c) 2026

import argparse
import json
import os
import pathlib
import sqlite3
import subprocess
import sys
import tkinter as tk
from tkinter import messagebox

import etdel_licence

APP_VERSION = "1.0.0"
PRODUIT = "DEMO"
# Distribution du banc (README, etape 5), aussi le defaut sans base lisible.
DISTRIBUTION = "DEMO-BANC"
ICI = os.path.dirname(os.path.abspath(__file__))
# Theme de la fenetre de demonstration : celui du module (cle publique PALETTE_DEFAUT).
FOND = etdel_licence.PALETTE_DEFAUT["fond"]
TEXTE = etdel_licence.PALETTE_DEFAUT["texte"]
DISCRET = etdel_licence.PALETTE_DEFAUT["discret"]
URL_BANC = "http://127.0.0.1:8090/api/v1/"
# Premiere cle publique du banc, comme celle qu'une application embarque ;
# les rotations suivantes arrivent par bulletins, comme sur un vrai poste.
FICHE_BANC = os.path.join(ICI, "..", "serveur", "www", "prive", "cles", "signature_1.json")
BASE_BANC = os.path.join(ICI, "..", "serveur", "www", "prive", "data", "licenses.db")


def _cle_banc():
    try:
        with open(FICHE_BANC, "r", encoding="utf-8") as fichier:
            return json.load(fichier)["cle_publique"]
    except (OSError, ValueError, KeyError, TypeError):
        return None


def _distribution_banc():
    """Distribution de la demo sur le banc, lue dans sa base ouverte en lecture
    seule : DEMO-BANC si elle existe et est active (README, etape 5), sinon la
    premiere distribution active (plus petit id) du produit DEMO ; DEMO-BANC a
    defaut."""
    try:
        uri = pathlib.Path(os.path.abspath(BASE_BANC)).as_uri() + "?mode=ro"
        connexion = sqlite3.connect(uri, uri=True, timeout=2)
        try:
            ligne = connexion.execute(
                "SELECT d.code FROM distributions d JOIN produits p ON p.id = d.produit_id "
                "WHERE p.code = ? AND d.actif = 1 "
                "ORDER BY (d.code = ?) DESC, d.id LIMIT 1", (PRODUIT, DISTRIBUTION)).fetchone()
        finally:
            connexion.close()
        if ligne and ligne[0]:
            return str(ligne[0])
    except (sqlite3.Error, OSError, ValueError):
        pass
    return DISTRIBUTION


def _bouton_plat(parent, texte, commande=None):
    """Bouton plat du theme de la demo : bord fin, survol teinte. Renvoie
    (cadre a placer, bouton)."""
    cadre = tk.Frame(parent, bg="#d0d0d0")
    bouton = tk.Button(cadre, text=texte, command=commande, relief="flat", bd=0, highlightthickness=0,
                       padx=14, pady=6, bg="#ffffff", fg=TEXTE, activebackground="#f3f3f3",
                       activeforeground=TEXTE, disabledforeground="#a0a0a0", cursor="hand2",
                       font=("Segoe UI", 10))
    bouton.pack(padx=1, pady=1)

    def survol(actif):
        if str(bouton.cget("state")) != "disabled":
            bouton.configure(bg="#f3f3f3" if actif else "#ffffff")

    bouton.bind("<Enter>", lambda _e: survol(True), add="+")
    bouton.bind("<Leave>", lambda _e: survol(False), add="+")
    bouton.bind("<FocusIn>", lambda _e: cadre.configure(bg=etdel_licence.PALETTE_DEFAUT["accent"]), add="+")
    bouton.bind("<FocusOut>", lambda _e: cadre.configure(bg="#d0d0d0"), add="+")
    return cadre, bouton


def construire(arguments=None):
    """Construit la fenetre principale (sans mainloop) ; renvoie (root, garde)."""
    arguments = list(sys.argv[1:] if arguments is None else arguments)
    lecteur = argparse.ArgumentParser(description="Banc d'essai de la licence ETDEL")
    lecteur.add_argument("--banc", action="store_true",
                         help="serveur local du banc (%s), cle publique lue dans serveur/www/prive/cles" % URL_BANC)
    lecteur.add_argument("--serveur", help="URL de l'API, ex. %s" % URL_BANC)
    lecteur.add_argument("--cle-publique", help="cle publique affichee par install.php ou l'ecran Cles")
    lecteur.add_argument("--distribution",
                         help="distribution (defaut %s ; avec --banc, si la base du banc n'a pas de %s active, "
                              "la premiere distribution active du produit %s)" % (DISTRIBUTION, DISTRIBUTION, PRODUIT))
    options = lecteur.parse_args(arguments)
    distribution = options.distribution or (_distribution_banc() if options.banc else DISTRIBUTION)
    probleme = None
    if options.banc:
        etdel_licence.LICENCE_URL = URL_BANC
        cle = _cle_banc()
        if cle:
            etdel_licence.LICENCE_CLE_PUBLIQUE = cle
        else:
            probleme = ("Banc non installe : demarrer le serveur du banc puis ouvrir\n"
                        "http://127.0.0.1:8090/install.php (voir README.md).")
    if options.serveur:
        etdel_licence.LICENCE_URL = options.serveur
    if options.cle_publique:
        etdel_licence.LICENCE_CLE_PUBLIQUE = options.cle_publique

    root = tk.Tk()
    root.title("Demo ETDEL")
    root.configure(bg=FOND)
    # Taille naturelle : le cadre Licence est affiche en entier.
    root.minsize(600, 0)
    garde = etdel_licence.installer(root, produit=PRODUIT, distribution=distribution,
                                    version=APP_VERSION)

    tk.Label(root, text="Application de demonstration", font=("Segoe UI", 14, "bold"), bg=FOND,
             fg=TEXTE).pack(pady=(40, 2))
    configure = bool(etdel_licence.LICENCE_URL and etdel_licence.LICENCE_CLE_PUBLIQUE)
    tk.Label(root, fg=DISCRET, bg=FOND, text=("Serveur : %s - distribution %s" % (etdel_licence.LICENCE_URL, distribution)
                                              if configure else "Licence non configuree : lancer avec --banc")).pack()

    def ouvrir_licence():
        # Meme fenetre que le raccourci Ctrl+Maj+L, qui reste disponible.
        if garde._integration is None:
            messagebox.showinfo("Licence", "Licence non configuree : lancer la demo avec --banc.", parent=root)
        else:
            garde._integration.ouvrir_licence()

    def supprimer_licence():
        if garde._stockage is None:
            messagebox.showinfo("Licence", "Licence non configuree : rien a supprimer.", parent=root)
            return
        if not messagebox.askyesno(
                "Supprimer la licence",
                "Effacer la licence enregistree sur ce poste pour la demo, puis relancer la demo ?\n\n"
                "Seul l'etat local est efface : sur le serveur, la licence reste liee a ce poste "
                "(la meme cle peut etre ressaisie) et l'essai deja accorde ne sera pas redonne.",
                parent=root):
            return
        garde.arreter()
        for chemin in garde._stockage.chemins:
            for fichier in (chemin, chemin + ".tmp"):
                try:
                    os.remove(fichier)
                except OSError:
                    pass
        subprocess.Popen([sys.executable, os.path.abspath(__file__)] + arguments, cwd=ICI)
        root.destroy()

    rangee = tk.Frame(root, bg=FOND)
    rangee.pack(pady=(14, 4))
    for texte, commande in (("Fenetre Licence", ouvrir_licence),
                            ("Supprimer la licence de ce poste (essais)", supprimer_licence)):
        _bouton_plat(rangee, texte, commande)[0].pack(side="left", padx=4)
    cadre_export, export = _bouton_plat(root, "Exporter en PDF (option export_pdf)")
    cadre_export.pack(pady=10)
    garde.cadre_licence(root).pack(fill="x", padx=16, pady=8)
    if probleme:
        root.after(300, lambda: messagebox.showwarning("Banc d'essai", probleme, parent=root))

    def rafraichir():
        # L'option peut changer en cours de session (activation, acceptation d'une demande).
        actif = garde.option("export_pdf")
        export.configure(state="normal" if actif else "disabled", cursor="hand2" if actif else "arrow")
        root.after(1000, rafraichir)

    rafraichir()
    return root, garde


if __name__ == "__main__":
    construire()[0].mainloop()
