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
import subprocess
import sys
import tkinter as tk
from tkinter import messagebox

import etdel_licence

APP_VERSION = "1.0.0"
PRODUIT = "DEMO"
DISTRIBUTION = "DEMO-BANC"
ICI = os.path.dirname(os.path.abspath(__file__))
URL_BANC = "http://127.0.0.1:8090/api/v1/"
# Premiere cle publique du banc, comme celle qu'une application embarque ;
# les rotations suivantes arrivent par bulletins, comme sur un vrai poste.
FICHE_BANC = os.path.join(ICI, "..", "serveur", "www", "prive", "cles", "signature_1.json")


def _cle_banc():
    try:
        with open(FICHE_BANC, "r", encoding="utf-8") as fichier:
            return json.load(fichier)["cle_publique"]
    except (OSError, ValueError, KeyError, TypeError):
        return None


def construire(arguments=None):
    """Construit la fenetre principale (sans mainloop) ; renvoie (root, garde)."""
    arguments = list(sys.argv[1:] if arguments is None else arguments)
    lecteur = argparse.ArgumentParser(description="Banc d'essai de la licence ETDEL")
    lecteur.add_argument("--banc", action="store_true",
                         help="serveur local du banc (%s), cle publique lue dans serveur/www/prive/cles" % URL_BANC)
    lecteur.add_argument("--serveur", help="URL de l'API, ex. %s" % URL_BANC)
    lecteur.add_argument("--cle-publique", help="cle publique affichee par install.php ou l'ecran Cles")
    lecteur.add_argument("--distribution", default=DISTRIBUTION)
    options = lecteur.parse_args(arguments)
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
    root.geometry("600x520")
    garde = etdel_licence.installer(root, produit=PRODUIT, distribution=options.distribution,
                                    version=APP_VERSION)

    tk.Label(root, text="Application de demonstration", font=("Segoe UI", 14, "bold")).pack(pady=(40, 2))
    configure = bool(etdel_licence.LICENCE_URL and etdel_licence.LICENCE_CLE_PUBLIQUE)
    tk.Label(root, fg="#6b6b6b", text=("Serveur : %s - distribution %s" % (etdel_licence.LICENCE_URL, options.distribution)
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

    rangee = tk.Frame(root)
    rangee.pack(pady=(14, 4))
    tk.Button(rangee, text="Fenetre Licence", command=ouvrir_licence, padx=10).pack(side="left", padx=4)
    tk.Button(rangee, text="Supprimer la licence de ce poste (essais)", command=supprimer_licence,
              padx=10).pack(side="left", padx=4)
    export = tk.Button(root, text="Exporter en PDF (option export_pdf)")
    export.pack(pady=10)
    garde.cadre_licence(root).pack(fill="x", padx=16, pady=8)
    if probleme:
        root.after(300, lambda: messagebox.showwarning("Banc d'essai", probleme, parent=root))

    def rafraichir():
        # L'option peut changer en cours de session (activation, acceptation d'une demande).
        export.configure(state="normal" if garde.option("export_pdf") else "disabled")
        root.after(1000, rafraichir)

    rafraichir()
    return root, garde


if __name__ == "__main__":
    construire()[0].mainloop()
