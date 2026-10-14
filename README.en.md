<div align="center">

# Janus — Licenses for ETDEL applications

**A license server on shared web hosting, its administration console, and a single module to copy into each Python application.**

![Version](https://img.shields.io/badge/version-2.1.0-2ea44f)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.10%2B-3776AB?logo=python&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-pdo__sqlite-003B57?logo=sqlite&logoColor=white)
![tkinter](https://img.shields.io/badge/tkinter-module%20GUI-3776AB)
![Ed25519](https://img.shields.io/badge/signature-Ed25519-555555)
![Platform](https://img.shields.io/badge/platform-Windows%20%2B%20web%20hosting-0078D6?logo=windows&logoColor=white)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)

</div>

[Français](README.md) · **English**

> The program's interface is in French; its labels are quoted below as they appear on screen.

---

> [!NOTE]
> **A single module, copied as is into each application.** The server is installed once on the web host;
> the module `client/etdel_licence.py` is copied, without any modification, next to the main script of each
> application. As long as its constants `LICENCE_URL` and `LICENCE_CLE_PUBLIQUE` are empty, as in this repository,
> it does nothing: no file, no network access, no message. You can therefore integrate it before the
> server exists. The module is made for Windows applications; outside Windows, it is only useful for development.

A licensing system for the Windows Python applications published by ETDEL: a license server and its administration
console (PHP, OVH shared hosting), and a single client module, `etdel_licence.py`, integrated in two lines into
each application.

**Who it is for.** The publisher who ships Python applications for Windows and wants to decide who may use them.
Each application is a **product**, with its own rules; a license is valid for one computer. From a console that
can be used on a phone, the publisher creates license keys, accepts or refuses the requests sent from the
application (with, if they wish, a trial period in the meantime), retrieves a key to dictate it again, extends,
suspends or revokes a license, moves a customer to another computer, and creates offline licenses for computers
that are never connected to the Internet. Nothing needs to be prepared for a new application: its first license
request creates its product. On the user's side, the application receives the key or sends the request, checks
itself in the background, and shows the state of its license with the shortcut **Ctrl+Maj+L** (Ctrl+Shift+L),
from which the license can also be transferred to a new computer.

Current version: client module 2.1.0, server 2.1.0 (API v1) — the implementation choices, with the version changes
that follow from them, are recorded in [docs/DECISIONS.md](docs/DECISIONS.md) (in French).

## Contents
- [1. Installation](#1-installation)
- [2. The files](#2-the-files)
- [3. Setting up](#3-setting-up)
- [4. The demo application](#4-the-demo-application)
- [5. How it works](#5-how-it-works)
- [6. If something goes wrong](#6-if-something-goes-wrong)
- [7. License](#7-license)

---

## 1. Installation

### What you need

| For | You need |
|---|---|
| the server | an OVH shared hosting plan with **PHP 8.1 or later** and the `pdo_sqlite` and `sodium` extensions (present by default at OVH), a subdomain over HTTPS and, if possible, a backup domain; an FTP client and a web browser are enough, with no SSH access |
| the applications | Windows and **Python 3.10 or later**; tkinter for a windowed application (`installer()`); the module uses only the standard library |
| the demo application | Python with tkinter, and a license server already installed |

### Getting the repository

```powershell
git clone https://github.com/theptitprince/Janus.git
cd Janus
```

### Where to start

- **Setting up the server on the web host**: section 3, then the complete guide
  [docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md) (in French).
- **Integrating the module into an application**: section 3, then [docs/INTEGRATION.md](docs/INTEGRATION.md) (in
  French) and the [integration brief template](docs/modele_brief_integration.md) (in French).
- **Trying the installed server before shipping an application**: section 4.

---

## 2. The files

| File | Role |
|---|---|
| `client/etdel_licence.py` | the single client module, version 2.1.0: online check, encrypted local state, offline tolerance, offline licenses (codes verified without a network), Tkinter windows; only its four `LICENCE_*` constants are filled in, once, after the server is installed |
| `client/demo_appli.py` | demo application (product `DEMO`), to be connected to an installed server (section 4) |
| `serveur/www/` | the whole server, to be uploaded as is by FTP: API, administration console, installation wizard, and `prive/` (libraries, configuration, keys, database), never served |
| `serveur/www/install.php` | installation wizard, for single use, locked afterwards; from then on it only serves to replace a lost console password, with a token uploaded by FTP |
| `serveur/www/api/v1/` | API used by the applications: activation, check, request and its follow-up, transfer |
| `serveur/www/admin/` | administration console, password-protected, with its **Aide** (Help) screen |
| `serveur/www/prive/` | never served: `config.exemple.php` (template for `config.php`), `schema.sql` (database schema), `sauvegarde.php` (daily backup), `lib/` (libraries); the installation creates in it the database (`data/`), the signing keys and the secrets (`cles/`) and the `.htpasswd` |
| `docs/INTEGRATION.md` | [integration](docs/INTEGRATION.md) of the module into an application |
| `docs/modele_brief_integration.md` | [integration brief template](docs/modele_brief_integration.md), to be filled in for each application |
| `docs/LISEZ-MOI-SERVEUR.md` | [deployment and operation of the server](docs/LISEZ-MOI-SERVEUR.md) |
| `docs/DECISIONS.md` | [implementation decisions](docs/DECISIONS.md) |
| `LICENSE` | full text of the GNU GPL version 3 license |

Never under version control (`.gitignore`): `serveur/www/prive/config.php`, the keys and the secrets
(`prive/cles/`), the database and its backups (`prive/data/`), the `.htpasswd`, the installation lock
`install.verrou` and the file `reinitialisation.utilisee`.

---

## 3. Setting up

### The server on the web host

In short (the complete step-by-step guide, with the checks, is in
[docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md), § 3):

1. In the OVH customer area, create the subdomain (for example `licence.mondomaine.fr`, root folder `licence`,
   **SSL** box checked) and choose PHP 8.1 or later.
2. On your computer, copy `serveur/www/prive/config.exemple.php` to `config.php` (same folder) and set
   `jeton_installation` to a random string of at least 20 characters.
3. By FTP, upload the contents of `serveur/www/` into `licence/`, without forgetting the hidden `.htaccess` files.
4. Open `https://licence.mondomaine.fr/install.php`: the wizard creates the database, the signing key pair,
   the request secret, the offline-code secret and the `.htpasswd`, protects `/admin/`, then locks itself.
   Note down the public key and the four lines to copy into `client/etdel_licence.py`.
5. Make the backup copy of the secrets (`prive/config.php`, `prive/.htpasswd`, the `prive/cles/` folder and the
   generated `admin/.htaccess`), set up the e-mail notifications and the daily backup (scheduled task
   `licence/prive/sauvegarde.php`).

No product needs to be prepared: the first license request from an unknown application creates its product, with
the default rules. The same guide covers the console, backups and restoring, changing the server's URL, rotating
the signing key, updating the server and troubleshooting.

### The module in an application

The module is copied as is next to the main script. For a Tkinter application, add right after the
creation of the main window:

```python
import tkinter as tk
import etdel_licence

root = tk.Tk()
etdel_licence.installer(root, produit="MONAPPLI", version=APP_VERSION)   # juste après la création de root
```

This is the line that the console displays, with "Copier" (Copy), on the page of each product. For a console
application, call `etdel_licence.exiger(produit="MONOUTIL", version=APP_VERSION)` at the start of `main()`.

Once the server is installed, the four lines displayed by the wizard, and also in the console under
*Administration* › *Clés de signature* (signing keys), are copied once only to the top of
`client/etdel_licence.py`: `LICENCE_URL`, `LICENCE_URL_SECOURS`, `LICENCE_CLE_PUBLIQUE` and
`LICENCE_SECRET_HORS_LIGNE`. The module's new SHA-256 fingerprint is then recorded in `docs/INTEGRATION.md`, after
which this copy is redistributed to the applications.
Integration levels, palette, options, offline licenses, statuses and checks:
[docs/INTEGRATION.md](docs/INTEGRATION.md).

For an application shipped with a 1.x module: first upgrade the server to version 2
([docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md), § 8), and only then ship the application with the
2.x module; an older server would block its computers with "Produit inconnu" (unknown product).

---

## 4. The demo application

`client/demo_appli.py` is a small Tkinter application (product `DEMO`, version 1.0.0) integrated like a shipped
application, through `installer(root, produit="DEMO", version=...)`. It lets you try an installed server
end to end: key entered by hand, license request and trial period, banner, "Licence" (License) window,
options, transfer and offline licenses. Its command-line options override the module's constants for this run
only, which no shipped application should do.

From the root of the repository:

```powershell
python client/demo_appli.py --serveur https://licence.mondomaine.fr/api/v1/ --cle-publique <clé publique>
```

| Option | Role |
|---|---|
| `--serveur <url>` | URL of the server's API, ending in `/api/v1/` |
| `--cle-publique <clé>` | public key displayed by `install.php` and by the console, *Administration* › *Clés de signature* |
| `--secret-hors-ligne <secret>` | value of `LICENCE_SECRET_HORS_LIGNE` (same screen): the demo then also accepts the short offline codes; without it, only the long codes are accepted |
| `--banc` | shortcut for a server installed on this very computer, from the `serveur/www/` folder of this repository, and served at the address `http://127.0.0.1:8090/api/v1/`: the public key and the secret are read from `serveur/www/prive/cles/` |

Started without these options, with this repository's copy of the module (empty constants), the demo opens
without a license and says so: "Licence non configuree" (license not configured). The module accepts plain HTTP
only on the loopback interface (`127.0.0.1`, `localhost`, `::1`); any other address must use HTTPS.

Nothing needs to be prepared in the console: the demo's first "Demander une licence" (request a license) creates
the product `DEMO` with the default rules; the request appears under *Demandes* (Requests), and the notification
e-mail flags "NOUVEAU PRODUIT" (new product). To try next:

- **a key entered by hand**: *Produits* (Products) › `DEMO` › "Nouvelle licence pour ce produit" (new license for
  this product) — before any request, *Produits* › "Nouveau produit" (new product), code `DEMO` — then
  "J'ai une cle" (I have a key) in the demo;
- **an option**: add `export_pdf` to the options of the product `DEMO`, or to those of the license, under
  "Reglages avances" (advanced settings) on its record page; after "Verifier maintenant" (check now), the demo's
  "Exporter en PDF (option export_pdf)" (export to PDF) button becomes active (it does nothing else);
- **an offline license**: *Licences* (Licenses) › "Licence hors ligne" (offline license), product `DEMO`, with the
  "Identifiant du poste" (computer ID) displayed by the demo; enter the short code (demo started with
  `--secret-hors-ligne`) or the long code with "J'ai une cle";
- **a transfer**: "Licence" window › "Transferer cette licence" (transfer this license); the demo deactivates
  itself and closes, and the key is entered on the other computer.

The demo's window has two buttons: "Fenetre Licence" (License window), the same window as Ctrl+Maj+L, and
"Supprimer la licence de ce poste (essais)" (remove the license from this computer, for testing), which erases
the demo's local state and restarts it with the same options; on the server side, the license stays bound to the
computer (the same key can be entered again) and a trial already granted is not given again. The "Licence" frame
at the bottom of the window is the one that an application places in its settings page (`cadre_licence()`). To
set the `DEMO` product aside afterwards, uncheck "Actif" (active) under "Reglages avances" on its page.

To erase the module's local state on a computer by hand, delete the folders `%APPDATA%\ETDEL\licences`,
`%LOCALAPPDATA%\ETDEL\licences` and `%PROGRAMDATA%\ETDEL\licences`: this applies to all the ETDEL applications
on the computer, log included, and not only to the demo.

---

## 5. How it works

- **Products.** A product is an application, recognized by the code it sends (`produit="..."` in its
  `installer(...)` line), with its rules: default license duration (empty = perpetual), trial during a request,
  options (`*` = all), offline tolerance, advance warning, welcome message, active or not. An unknown product is
  created at its first license request (one-year license, 15-day trial, 15 days offline, 5-day advance warning,
  no options); it can also be created by hand to prepare keys. Its code never changes afterwards.
- **One license, one computer.** A key `ETDEL-XXXX-XXXX-XXXX-XXXX` is bound to the first computer that activates
  it. There are two ways to obtain it: the key supplied by the publisher ("J'ai une cle"), or the request sent
  from the application ("Demander une licence"), accepted or refused in the console; the trial, if any, is
  granted only once per computer and per product.
- **Background check.** `installer()` returns control in under 50 ms. The first check takes place 3 s after
  startup, then every 6 h (15 min after a failure). Without an active license, a window offers
  "J'ai une cle" or "Demander une licence"; nothing is sent without an explicit click.
- **Signed responses.** All of the server's responses, refusals included, are signed (Ed25519); the module
  verifies them with the public key it embeds. Requests are timestamped: a computer whose clock is off by more
  than 10 minutes is refused.
- **Offline.** The computer keeps an encrypted (DPAPI) and sealed local state, in three copies, in
  `%APPDATA%`, `%LOCALAPPDATA%` and `%PROGRAMDATA%` (subfolder `ETDEL\licences`). The application remains usable
  offline for its product's tolerance period (15 days by default), with an advance-warning banner during the
  last days (5 by default).
- **Suspending or revoking.** A suspended license blocks the computer, which keeps its key and unblocks itself on
  reactivation or at the chosen end date. A revoked license is revoked permanently: the computer erases its key.
- **Retrieving a key.** The console keeps each key encrypted (keys created since version 2 of the server), with
  a secret stored outside the database: "Afficher la cle" (show the key), on the license's record page, shows it
  so that it can be dictated again, and each display is written to the log.
  In the application, the "Licence" window shows it too, hidden by default.
- **Changing computers.** From the application, on the old computer while it is connected:
  "Transferer cette licence" frees the key, deactivates the application and displays the key to enter on the new
  computer (at most three transfers per license over 30 days). From the console, if the old computer is lost:
  "Changer d'ordinateur" (change computer).
- **Offline licenses.** For a computer that is never connected, the publisher creates in the console, from the
  "Identifiant du poste" (`XXXX-XXXX`) that the application displays, two equivalent codes that contain their
  end date and are verified without a network: a short code `ETDHL-XXXX-XXXX-XXXX-XXXX` to be dictated,
  protected by `LICENCE_SECRET_HORS_LIGNE` (a deterrent: anyone who decompiles an application can forge some),
  and a long code `ETDHL2-…` to be sent by e-mail, signed by the server (unforgeable). A license is extended with
  a new code. If the computer ever connects, the license follows the console like the others; otherwise, it
  cannot be revoked.
- **URLs and signing keys.** Each response carries the signed list of the server's URLs, which the computer
  remembers: changing the address requires neither recompiling nor redistributing. A new signing key is
  announced by a bulletin signed with the old one; computers adopt it at their next connection.
- **Application version.** It is sent to the server and displayed in the console, for information only: no
  version is ever refused.
- **Data.** The module sends the computer's fingerprint (SHA-256 of the machine's Windows identifier and of the
  system volume's serial number), the computer name, the product code and the application version; for a
  request, in addition, the holder, the e-mail address and the message entered. The server looks up a key by its
  SHA-256; a key or a code never appears in the log, the e-mails or the exports, only its last 4 characters.

---

## 6. If something goes wrong

**`install.php` responds "Configuration absente" (configuration missing)** — `prive/config.php` is missing on the
web host: copy `serveur/www/prive/config.exemple.php` to `config.php`, enter the `jeton_installation` in it,
upload it, then reload the page (section 3, step 2).

**`install.php` responds "Installation impossible"** — the page gives the cause: PHP extension `pdo_sqlite` or
`sodium` missing, `jeton_installation` missing or shorter than 20 characters, or database already present.

**`install.php` responds "Installation deja effectuee" (installation already done)** — the wizard is locked,
which is normal after an installation. Lost console password: see the end of
[docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md), § 10.

**The console displays "Formulaire expire ou invalide" (form expired or invalid)** — reload the page, then try
again: the form token changes after each write.

**An application displays "Produit inconnu"** — the product is inactive (*Produits* › its page ›
"Reglages avances", "Actif" box), or the application's code differs from an existing product only in upper and
lower case: correct the code in the application.

**An application refuses a short offline code with "non pris en charge" (not supported)** — its copy of the
module does not have `LICENCE_SECRET_HORS_LIGNE`: send the long code, and copy the line into its next version.

**The demo displays "Licence non configuree"** — start it with `--serveur` and `--cle-publique` (section 4).

**An application reports an unreachable license server, or stays blocked** — **Ctrl+Maj+L** opens the
"Licence" window: computer ID, status, diagnostic line, and "Verifier maintenant" to run a check again. The
technical detail is in the log `%LOCALAPPDATA%\ETDEL\licences\licence.log`. A clock that is off by more than
10 minutes is refused by the server: correct the computer's date and time.

For everything else: the console's **Aide** screen details each screen, each setting and each action; the
"Dépannage" (Troubleshooting) part of [docs/LISEZ-MOI-SERVEUR.md](docs/LISEZ-MOI-SERVEUR.md) covers the web host,
and that of [docs/INTEGRATION.md](docs/INTEGRATION.md) covers the applications.

---

## 7. License

Janus is **free software**, distributed under the **GNU General Public License, version 3 or later** (GPL-3.0-or-later).

Copyright © 2026 ETDEL.

In practical terms, you may use the program freely, study it, modify it and redistribute it — provided that any
redistributed version, modified or not, remains under the same license, with its source code and the copyright
notice. The program is provided **without any warranty**.

The full text of the license is in the repository's `LICENSE` file and at
<https://www.gnu.org/licenses/gpl-3.0.html>. Each source file carries the notice "ETDEL (c) 2026".

---

*Janus — ETDEL 2026 — GPL-3.0-or-later*
