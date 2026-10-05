# demo_appli.py
# Role : application Tkinter minimale, banc d'essai de bout en bout du module
#        etdel_licence (activation, demande, bandeau, Ctrl+Maj+L, options).
#        Banc d'essai uniquement : --serveur et --cle-publique remplacent les
#        constantes du module, ce qu'aucune application livree ne doit faire.
#        Exemple : python demo_appli.py --serveur http://127.0.0.1:8080/api/v1/ --cle-publique <cle>
# ETDEL (c) 2026

import argparse
import tkinter as tk

import etdel_licence

APP_VERSION = "1.0.0"
PRODUIT = "DEMO"
DISTRIBUTION = "DEMO-BANC"


def construire(arguments=None):
    """Construit la fenetre principale (sans mainloop) ; renvoie (root, garde)."""
    lecteur = argparse.ArgumentParser(description="Banc d'essai de la licence ETDEL")
    lecteur.add_argument("--serveur", help="URL de l'API, ex. http://127.0.0.1:8080/api/v1/")
    lecteur.add_argument("--cle-publique", help="cle publique affichee par install.php ou l'ecran Cles")
    lecteur.add_argument("--distribution", default=DISTRIBUTION)
    options = lecteur.parse_args(arguments)
    if options.serveur:
        etdel_licence.LICENCE_URL = options.serveur
    if options.cle_publique:
        etdel_licence.LICENCE_CLE_PUBLIQUE = options.cle_publique

    root = tk.Tk()
    root.title("Demo ETDEL")
    root.geometry("560x420")
    garde = etdel_licence.installer(root, produit=PRODUIT, distribution=options.distribution,
                                    version=APP_VERSION)

    tk.Label(root, text="Application de demonstration", font=("Segoe UI", 14, "bold")).pack(pady=(40, 6))
    tk.Label(root, text="Ctrl+Maj+L : fenetre Licence").pack()
    export = tk.Button(root, text="Exporter en PDF (option export_pdf)")
    export.pack(pady=10)
    garde.cadre_licence(root).pack(fill="x", padx=16, pady=8)

    def rafraichir():
        # L'option peut changer en cours de session (activation, acceptation d'une demande).
        export.configure(state="normal" if garde.option("export_pdf") else "disabled")
        root.after(1000, rafraichir)

    rafraichir()
    return root, garde


if __name__ == "__main__":
    construire()[0].mainloop()
