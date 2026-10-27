-- Herbinum Datenbankschema (MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS spieler (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(32)  NOT NULL,
  pass_hash     VARCHAR(255) NOT NULL,
  farbe         VARCHAR(7)   NOT NULL,
  ist_admin     TINYINT(1)   NOT NULL DEFAULT 0,
  erstellt_ts   INT UNSIGNED NOT NULL,
  zuletzt_ts    INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_spieler_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orte (
  id      VARCHAR(32) PRIMARY KEY,
  name    VARCHAR(64) NOT NULL,
  x       INT NOT NULL,
  y       INT NOT NULL,
  radius  INT NOT NULL,
  typ     VARCHAR(16) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bereiche (
  id               VARCHAR(40) PRIMARY KEY,
  ort_id           VARCHAR(32) NOT NULL,
  nr               SMALLINT UNSIGNED NOT NULL,
  name             VARCHAR(80) NOT NULL,
  typ              VARCHAR(16) NOT NULL,
  ha               DECIMAL(7,1) NOT NULL,
  bauplaetze       SMALLINT UNSIGNED NOT NULL,
  lebensqualitaet  TINYINT UNSIGNED NOT NULL,
  wehr             TINYINT UNSIGNED NOT NULL,
  einkommen        TINYINT UNSIGNED NOT NULL,
  mod_holz         DECIMAL(4,2) NOT NULL,
  mod_ziegel       DECIMAL(4,2) NOT NULL,
  mod_korn         DECIMAL(4,2) NOT NULL,
  mod_strom        DECIMAL(4,2) NOT NULL,
  besitzer_id      INT UNSIGNED NULL,
  ist_heimat       TINYINT(1) NOT NULL DEFAULT 0,
  holz             DOUBLE NOT NULL DEFAULT 0,
  ziegel           DOUBLE NOT NULL DEFAULT 0,
  korn             DOUBLE NOT NULL DEFAULT 0,
  taler            DOUBLE NOT NULL DEFAULT 0,
  stand_ts         INT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_bereiche_ort (ort_id),
  KEY ix_bereiche_besitzer (besitzer_id),
  CONSTRAINT fk_bereiche_ort FOREIGN KEY (ort_id) REFERENCES orte(id),
  CONSTRAINT fk_bereiche_besitzer FOREIGN KEY (besitzer_id) REFERENCES spieler(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gebaeude (
  bereich_id  VARCHAR(40) NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  stufe       SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (bereich_id, typ),
  CONSTRAINT fk_gebaeude_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bauauftraege (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bereich_id  VARCHAR(40) NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  ziel_stufe  SMALLINT UNSIGNED NOT NULL,
  start_ts    INT UNSIGNED NOT NULL,
  ende_ts     INT UNSIGNED NOT NULL,
  holz        INT UNSIGNED NOT NULL,
  ziegel      INT UNSIGNED NOT NULL,
  korn        INT UNSIGNED NOT NULL,
  taler       INT UNSIGNED NOT NULL,
  KEY ix_bau_bereich (bereich_id, ende_ts),
  CONSTRAINT fk_bau_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_versuche (
  ip   VARCHAR(45) NOT NULL,
  ts   INT UNSIGNED NOT NULL,
  KEY ix_login_ip (ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Version 2: Forschung, Einheiten, Züge, Nachrichten ----------

CREATE TABLE IF NOT EXISTS forschung (
  spieler_id  INT UNSIGNED NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  stufe       SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (spieler_id, typ),
  CONSTRAINT fk_forschung_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forschungsauftraege (
  spieler_id  INT UNSIGNED PRIMARY KEY,
  bereich_id  VARCHAR(40) NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  ziel_stufe  SMALLINT UNSIGNED NOT NULL,
  start_ts    INT UNSIGNED NOT NULL,
  ende_ts     INT UNSIGNED NOT NULL,
  holz        INT UNSIGNED NOT NULL,
  ziegel      INT UNSIGNED NOT NULL,
  korn        INT UNSIGNED NOT NULL,
  taler       INT UNSIGNED NOT NULL,
  KEY ix_fa_ende (ende_ts),
  CONSTRAINT fk_fa_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS einheiten (
  bereich_id  VARCHAR(40) NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  anzahl      INT UNSIGNED NOT NULL,
  PRIMARY KEY (bereich_id, typ),
  CONSTRAINT fk_einheiten_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS werkstattauftraege (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bereich_id  VARCHAR(40) NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  anzahl      INT UNSIGNED NOT NULL,
  fertig      INT UNSIGNED NOT NULL DEFAULT 0,
  start_ts    INT UNSIGNED NOT NULL,
  stueck_s    INT UNSIGNED NOT NULL,
  KEY ix_wa_bereich (bereich_id, id),
  CONSTRAINT fk_wa_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zuege (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spieler_id    INT UNSIGNED NOT NULL,
  start_id      VARCHAR(40) NOT NULL,
  ziel_id       VARCHAR(40) NOT NULL,
  auftrag       VARCHAR(16) NOT NULL,
  status        VARCHAR(8)  NOT NULL,        -- 'hin' oder 'zurueck'
  abflug_ts     INT UNSIGNED NOT NULL,
  ankunft_ts    INT UNSIGNED NOT NULL,
  rueckkehr_ts  INT UNSIGNED NOT NULL,
  einheiten     VARCHAR(255) NOT NULL,       -- JSON {"trecker":2}
  holz          DOUBLE NOT NULL DEFAULT 0,
  ziegel        DOUBLE NOT NULL DEFAULT 0,
  korn          DOUBLE NOT NULL DEFAULT 0,
  taler         DOUBLE NOT NULL DEFAULT 0,
  KEY ix_zuege_spieler (spieler_id),
  KEY ix_zuege_ankunft (status, ankunft_ts),
  KEY ix_zuege_rueckkehr (status, rueckkehr_ts),
  CONSTRAINT fk_zuege_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nachrichten (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spieler_id  INT UNSIGNED NOT NULL,
  ts          INT UNSIGNED NOT NULL,
  art         VARCHAR(16) NOT NULL,
  titel       VARCHAR(160) NOT NULL,
  inhalt      TEXT NOT NULL,                 -- JSON
  gelesen     TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_nachrichten_spieler (spieler_id, ts),
  CONSTRAINT fk_nachrichten_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ---------- Version 3: Admin, Musik ----------

CREATE TABLE IF NOT EXISTS musik (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  datei          VARCHAR(64)  NOT NULL,
  titel          VARCHAR(120) NOT NULL,
  groesse        INT UNSIGNED NOT NULL,
  aktiv          TINYINT(1)   NOT NULL DEFAULT 0,
  hochgeladen_ts INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS einstellungen (
  schluessel  VARCHAR(40) PRIMARY KEY,
  wert        VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ---------- Version 4: Genossenschaften ----------

CREATE TABLE IF NOT EXISTS genossenschaften (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(40)  NOT NULL,
  kuerzel       VARCHAR(6)   NOT NULL,
  beschreibung  TEXT         NOT NULL,
  erstellt_ts   INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_gen_name (name),
  UNIQUE KEY uq_gen_kuerzel (kuerzel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gen_bewerbungen (
  genossenschaft_id  INT UNSIGNED NOT NULL,
  spieler_id         INT UNSIGNED NOT NULL,
  text               VARCHAR(500) NOT NULL,
  ts                 INT UNSIGNED NOT NULL,
  PRIMARY KEY (genossenschaft_id, spieler_id),
  KEY ix_bew_spieler (spieler_id),
  CONSTRAINT fk_bew_gen FOREIGN KEY (genossenschaft_id) REFERENCES genossenschaften(id) ON DELETE CASCADE,
  CONSTRAINT fk_bew_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ---------- Version 6 (Schema 5): Chat, Markt, Bunker, Vertretung, Ereignisse, Zeitung ----------

CREATE TABLE IF NOT EXISTS chat (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kanal     VARCHAR(8)   NOT NULL,          -- 'alle', 'allianz', 'privat'
  gen_id    INT UNSIGNED NULL,              -- bei 'allianz'
  von_id    INT UNSIGNED NOT NULL,
  an_id     INT UNSIGNED NULL,              -- bei 'privat'
  ts        INT UNSIGNED NOT NULL,
  text      VARCHAR(500) NOT NULL,
  KEY ix_chat_kanal (kanal, id),
  KEY ix_chat_an (an_id, id),
  CONSTRAINT fk_chat_von FOREIGN KEY (von_id) REFERENCES spieler(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_an FOREIGN KEY (an_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS markt_log (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spieler_id    INT UNSIGNED NOT NULL,
  ts            INT UNSIGNED NOT NULL,
  art           VARCHAR(8)  NOT NULL,       -- 'haendler' oder 'spieler'
  gibt_res      VARCHAR(8)  NOT NULL,
  gibt_menge    INT UNSIGNED NOT NULL,
  bekommt_res   VARCHAR(8)  NOT NULL,
  bekommt_menge INT UNSIGNED NOT NULL,
  KEY ix_markt_log (spieler_id, ts),
  CONSTRAINT fk_markt_log_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS markt_angebote (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spieler_id   INT UNSIGNED NOT NULL,
  bereich_id   VARCHAR(40)  NOT NULL,
  gibt_res     VARCHAR(8)   NOT NULL,
  gibt_menge   INT UNSIGNED NOT NULL,
  will_res     VARCHAR(8)   NOT NULL,
  will_menge   INT UNSIGNED NOT NULL,
  nur_allianz  TINYINT(1)   NOT NULL DEFAULT 0,
  erstellt_ts  INT UNSIGNED NOT NULL,
  ablauf_ts    INT UNSIGNED NOT NULL,
  KEY ix_angebote_ablauf (ablauf_ts),
  CONSTRAINT fk_angebote_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bunker (
  bereich_id  VARCHAR(40) NOT NULL,
  typ         VARCHAR(20) NOT NULL,
  anzahl      INT UNSIGNED NOT NULL,
  PRIMARY KEY (bereich_id, typ),
  CONSTRAINT fk_bunker_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bunker_auslagerung (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bereich_id  VARCHAR(40) NOT NULL,
  einheiten   VARCHAR(255) NOT NULL,        -- JSON {"schuetze":20}
  fertig_ts   INT UNSIGNED NOT NULL,
  KEY ix_ausl_bereich (bereich_id, fertig_ts),
  CONSTRAINT fk_ausl_bereich FOREIGN KEY (bereich_id) REFERENCES bereiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vertretung (
  spieler_id    INT UNSIGNED PRIMARY KEY,
  tage          VARCHAR(7)   NOT NULL,      -- Wochentage 1 (Mo) bis 7 (So), z. B. '12345'
  von_min       SMALLINT UNSIGNED NOT NULL, -- Minuten nach Mitternacht
  bis_min       SMALLINT UNSIGNED NOT NULL,
  rechte        VARCHAR(80)  NOT NULL,      -- z. B. 'bauen,forschen,werkstatt'
  gueltig_ab_ts INT UNSIGNED NOT NULL,      -- Änderungen wirken erst nach einer Vorlaufzeit
  pause_bis_ts  INT UNSIGNED NOT NULL DEFAULT 0,   -- „Ich bin wieder da“: Zeitfenster heute vorzeitig beendet
  CONSTRAINT fk_vertretung_spieler FOREIGN KEY (spieler_id) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ereignisse (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  typ         VARCHAR(12)  NOT NULL,
  start_ts    INT UNSIGNED NOT NULL,
  ende_ts     INT UNSIGNED NOT NULL,
  x           INT NOT NULL,                 -- Mittelpunkt in Kartenmetern
  y           INT NOT NULL,
  radius      INT UNSIGNED NOT NULL,
  staerke     DECIMAL(3,2) NOT NULL,        -- 0,30 bis 1,00
  angewendet  TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_ereignisse_zeit (start_ts, ende_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zeitung (
  tag         INT UNSIGNED PRIMARY KEY,     -- Spieltag-Nummer
  ts          INT UNSIGNED NOT NULL,
  quelle      VARCHAR(8)   NOT NULL,        -- 'ki' oder 'vorlage'
  inhalt      MEDIUMTEXT   NOT NULL         -- JSON: titel, artikel[], vorhersage[]
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chronik (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts      INT UNSIGNED NOT NULL,
  art     VARCHAR(16)  NOT NULL,            -- raubzug, erkundung, besiedlung, allianz, ereignis, neuling …
  ort     VARCHAR(64)  NOT NULL DEFAULT '',
  text    VARCHAR(255) NOT NULL DEFAULT '',
  KEY ix_chronik_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
