-- schema.sql - Schema de la base SQLite des licences (annexe B du cahier des charges)
-- ETDEL (c) 2026
-- Toutes les dates sont des horodatages Unix UTC (secondes).
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE produits (
  id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, nom TEXT NOT NULL,
  version_min TEXT, actif INTEGER NOT NULL DEFAULT 1, cree_le INTEGER NOT NULL);

CREATE TABLE distributions (
  id INTEGER PRIMARY KEY, produit_id INTEGER NOT NULL REFERENCES produits(id),
  code TEXT UNIQUE NOT NULL, libelle TEXT NOT NULL, client TEXT,
  tolerance_j INTEGER NOT NULL DEFAULT 15, preavis_j INTEGER NOT NULL DEFAULT 5,
  duree_defaut_j INTEGER,                          -- NULL = perpetuelle
  version_min TEXT, options TEXT NOT NULL DEFAULT '[]', essai_j INTEGER NOT NULL DEFAULT 15, message TEXT,
  actif INTEGER NOT NULL DEFAULT 1, cree_le INTEGER NOT NULL);

-- Une ligne = une cle = un poste.
CREATE TABLE licences (
  id INTEGER PRIMARY KEY, distribution_id INTEGER NOT NULL REFERENCES distributions(id),
  cle_hash TEXT UNIQUE NOT NULL, cle_indice TEXT NOT NULL,   -- 4 derniers caracteres
  titulaire TEXT NOT NULL, email TEXT, note TEXT,
  echeance INTEGER,                                -- NULL = perpetuelle
  tolerance_j INTEGER, options TEXT,               -- NULL = valeur de la distribution
  machine TEXT,                                    -- NULL = pas encore liee
  id_poste TEXT, nom_ordinateur TEXT, version_appli TEXT,
  lie_le INTEGER, dernier_contact INTEGER, derniere_ip TEXT,
  statut TEXT NOT NULL DEFAULT 'active' CHECK (statut IN ('active','suspendue','revoquee')),
  origine TEXT NOT NULL CHECK (origine IN ('console','demande')),
  cree_le INTEGER NOT NULL, modifie_le INTEGER NOT NULL,
  suspendue_jusqu INTEGER);                        -- fin d'une suspension datee (NULL = sans date), D61

CREATE TABLE demandes (
  id INTEGER PRIMARY KEY, distribution_id INTEGER NOT NULL REFERENCES distributions(id),
  machine TEXT NOT NULL, id_poste TEXT NOT NULL, nom_ordinateur TEXT,
  titulaire TEXT NOT NULL, email TEXT, message TEXT, version_appli TEXT,
  jeton_hash TEXT NOT NULL,                        -- SHA-256 du jeton de demande
  statut TEXT NOT NULL DEFAULT 'en_attente'
    CHECK (statut IN ('en_attente','acceptee','refusee')),
  motif_refus TEXT, essai_jusqu INTEGER, licence_id INTEGER REFERENCES licences(id),
  cle_chiffree TEXT,      -- cle remise au client, effacee apres le premier valider reussi
  cree_le INTEGER NOT NULL, traitee_le INTEGER, ip TEXT);

CREATE TABLE urls_serveur (
  id INTEGER PRIMARY KEY, url TEXT UNIQUE NOT NULL, priorite INTEGER NOT NULL,
  actif INTEGER NOT NULL DEFAULT 1);

CREATE TABLE cles_signature (
  kid INTEGER PRIMARY KEY, cle_publique TEXT NOT NULL,
  bulletin TEXT,          -- annonce signee par la cle precedente (NULL pour la premiere)
  active_depuis INTEGER NOT NULL, retiree_le INTEGER);

CREATE TABLE reglages (cle TEXT PRIMARY KEY, valeur TEXT);  -- email_notification, email_expediteur

CREATE TABLE journal (
  id INTEGER PRIMARY KEY, date INTEGER NOT NULL, acteur TEXT NOT NULL,
  action TEXT NOT NULL, cible TEXT, detail TEXT, ip TEXT);

CREATE TABLE limites (
  ip TEXT NOT NULL, point TEXT NOT NULL, fenetre INTEGER NOT NULL,
  compte INTEGER NOT NULL, PRIMARY KEY (ip, point, fenetre));

-- Version du schema, relue par db_migrer() (lib/db.php) pour mettre a niveau une
-- base creee par une version precedente du serveur.
PRAGMA user_version = 1;
