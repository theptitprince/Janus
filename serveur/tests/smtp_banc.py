# smtp_banc.py
# Role : boite aux lettres du banc d'essai local. Serveur SMTP minimal sur
#        127.0.0.1 qui enregistre chaque e-mail recu dans un fichier .eml, pour
#        verifier les notifications sans rien envoyer sur Internet. Sous
#        Windows, mail() de PHP parle SMTP (php -d SMTP=127.0.0.1 -d smtp_port=2525).
#        Jamais deploye. Usage : python smtp_banc.py [--port 2525] [--dossier courriels]
# ETDEL (c) 2026

import argparse
import os
import socketserver
import time


class _Session(socketserver.StreamRequestHandler):
    dossier = "courriels"

    def _repondre(self, ligne):
        self.wfile.write((ligne + "\r\n").encode("ascii"))

    def handle(self):
        self._repondre("220 banc ETDEL")
        expediteur, destinataires = "", []
        while True:
            brut = self.rfile.readline()
            if not brut:
                return
            commande = brut.decode("latin-1").strip()
            verbe = commande[:4].upper()
            if verbe in ("HELO", "EHLO"):
                self._repondre("250 banc")
            elif verbe == "MAIL":
                expediteur, destinataires = commande[10:].strip(), []
                self._repondre("250 OK")
            elif verbe == "RCPT":
                destinataires.append(commande[8:].strip())
                self._repondre("250 OK")
            elif verbe == "DATA":
                self._repondre("354 fin par un point seul")
                lignes = []
                while True:
                    ligne = self.rfile.readline()
                    if not ligne or ligne in (b".\r\n", b".\n"):
                        break
                    # Transparence SMTP (RFC 5321, 4.5.2) : un point en tete est double.
                    lignes.append(ligne[1:] if ligne.startswith(b"..") else ligne)
                self._enregistrer(expediteur, destinataires, b"".join(lignes))
                self._repondre("250 recu")
            elif verbe == "QUIT":
                self._repondre("221 au revoir")
                return
            else:
                self._repondre("250 OK")

    def _enregistrer(self, expediteur, destinataires, corps):
        os.makedirs(self.dossier, exist_ok=True)
        nom = time.strftime("%Y%m%d-%H%M%S") + "-%06d.eml" % (time.perf_counter_ns() // 1000 % 1000000)
        entete = "X-Banc-De: %s\r\nX-Banc-Pour: %s\r\n" % (expediteur, ", ".join(destinataires))
        with open(os.path.join(self.dossier, nom), "wb") as fichier:
            fichier.write(entete.encode("latin-1") + corps)
        print("e-mail recu : %s -> %s (%s)" % (expediteur, ", ".join(destinataires), nom), flush=True)


def main():
    lecteur = argparse.ArgumentParser(description="Boite aux lettres SMTP du banc d'essai")
    lecteur.add_argument("--port", type=int, default=2525)
    lecteur.add_argument("--dossier", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "courriels"))
    options = lecteur.parse_args()
    _Session.dossier = options.dossier
    socketserver.ThreadingTCPServer.allow_reuse_address = True
    with socketserver.ThreadingTCPServer(("127.0.0.1", options.port), _Session) as serveur:
        print("SMTP du banc sur 127.0.0.1:%d, e-mails dans %s" % (options.port, options.dossier), flush=True)
        serveur.serve_forever()


if __name__ == "__main__":
    main()
