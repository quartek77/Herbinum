# Konzept Version 8: Fairness für Gelegenheitsspieler

> **Stand: umgesetzt in Version 8** (Zweig `v8/fairness`) mit den hier vorgeschlagenen Werten, Schutz gegen alle Angreifer,
> ohne Urlaubsmodus und E-Mail-Warnung (Abschnitt D bleibt offen). Eine bewusste Abweichung: Die Kosten der Forschung
> Informationsnetz wurden nicht erhöht (C.2), weil sich sonst die Ranglistenpunkte für bereits erforschte Stufen rückwirkend
> geändert hätten. Spionage wird stattdessen über Kurierpreis und Einsatzgeld teurer.
>
> **Nachbesserung (A.4 ersetzt):** Ein Schutz „gegen alle Angreifer“ hätte ein Windhundrennen erzeugt: Wer morgens als Erster
> alle überfällt, hätte alle anderen ausgesperrt – und ein Verbündeter hätte ein Opfer mit einem Mini-Überfall absichtlich schützen können.
> Deshalb gilt jetzt Abschnitt **A.5**: persönliche Sperre nur für den Angreifer plus gemeinsamer Verlustdeckel pro Opfer.

**Ziel:** Angreifen bleibt lohnend und Kern des Spiels. Gute Taktik (Timing, Aufklärung, starke Armee) wird weiter voll belohnt.
Was nicht mehr belohnt wird: ständige Anwesenheit, Ausplündern in Serie und gezieltes „Farmen“ deutlich schwächerer Spieler.
Wer ein paar Stunden nicht online ist, darf etwas verlieren – aber nie alles und nie mehrmals hintereinander.

**Leitlinien**

1. **Erster Treffer voll, Serien nicht.** Ein erfolgreicher Raubzug bringt volle Beute; danach ist das Ziel eine Zeit lang geschützt – je größer der Schaden, desto länger.
2. **Risiko für Angreifer.** Aufklärung ist teuer, nicht immer erfolgreich und liefert ohne Investition nur ungenaue Werte.
3. **Verteidigung ist planbar.** Wer sich schützen will, kann das mit Gebäuden und Forschung tun – nicht nur mit Online-Zeit.
4. **Gleiche Regeln für alle**, offen einsehbar, alle Zahlen zentral in `app/rules.php`.
5. **Spielstand bleibt erhalten.** Nur neue Tabellen/Spalten, keine Änderung an bestehenden Daten.

---

## A. Angriffssperre nach Schaden (Kernstück)

Nach jedem erfolgreichen oder unentschiedenen Raubzug bekommt das **Ziel** eine Schutzzeit, in der **niemand** es überfallen kann.
Die Länge richtet sich nach dem **angerichteten Schaden** und dem **Stärkeunterschied** der beiden Spieler.

### A.1 Schaden des letzten Angriffs

Gemessen wird der **Wert** (Grundwerte wie beim Händler: Holz 1, Baustoffe 1,5, Nahrung 1,2, Euro 4; Einheiten mit ihren Baukosten)
über **alle Bereiche** des Verteidigers:

```
Vermögen vorher  W = Wert aller Vorräte + Wert aller Einheiten und Anlagen (inkl. Bunker)   – zum Zeitpunkt des Angriffs
Verlust          L = Wert der Beute + Wert der verlorenen Einheiten + Wert der zerstörten Anlagen (nach Wiederaufbau)
Schadensanteil   S = L / W              (0 … 1)
```

Mehrere Angriffe innerhalb von 24 Stunden **summieren sich** (gleich, von wem), sodass sich viele kleine Überfälle nicht lohnen:

```
S_24 = 1 − (1 − S_1) · (1 − S_2) · …      (alle Angriffe auf diesen Spieler in den letzten 24 h)
```

Beispiel: 30 % und danach 40 % Verlust → S_24 = 1 − 0,7 · 0,6 = 58 %.

### A.2 Längste sinnvolle Wartezeit aus dem Punkteverhältnis

```
r     = Punkte des Verteidigers / Punkte des Angreifers           (bei 0 Punkten: r = 0)
T_max = T_BASIS · (1 + K_SCHWAECHE · (1 − min(1, r)))
```

| Konstante | Vorschlag | Bedeutung |
|---|---|---|
| `T_BASIS` | 12 h | Schutzzeit bei gleich starken Gegnern und 100 % Schaden |
| `K_SCHWAECHE` | 1,0 | Aufschlag für deutlich Schwächere: bis zu doppelte Zeit |

- Gleich stark oder Verteidiger stärker (r ≥ 1): T_max = 12 h
- Verteidiger halb so stark (r = 0,5): T_max = 18 h
- Verteidiger sehr schwach (r → 0): T_max = 24 h

### A.3 Schutzzeit

```
Schutz = max(T_MIN, S_24 · T_max) / Spieltempo
Schutz endet = max(bisheriges Ende, Zeitpunkt des Angriffs + Schutz)      – eine Schutzzeit wird nie verkürzt
```

| Konstante | Vorschlag | Bedeutung |
|---|---|---|
| `T_MIN` | 1 h | auch ein kleiner Erfolg verschafft eine kurze Pause |

**Beispiele** (Spieltempo 1; bei Tempo 2 jeweils halb so lang):

| Angreifer | Verteidiger | r | T_max | Schaden S_24 | Schutz |
|---|---|---|---|---|---|
| 200 P. | 200 P. | 1,0 | 12 h | 20 % | 2,4 h |
| 200 P. | 200 P. | 1,0 | 12 h | 60 % | 7,2 h |
| 200 P. | 100 P. | 0,5 | 18 h | 60 % | 10,8 h |
| 300 P. | 60 P. | 0,2 | 21,6 h | 60 % | 13 h |
| 300 P. | 60 P. | 0,2 | 21,6 h | 90 % | 19,4 h |

Damit entspricht der Wunsch „60 % Verlust → 60 % der maximalen Wartezeit“ genau der Formel.

### A.4 Regeln drumherum

- **Gilt gegen alle Angreifer**, damit sich nicht reihum andere über das geschwächte Ziel hermachen.
- **Prüfung beim Losschicken und bei der Ankunft.** Ist das Ziel bei Ankunft geschützt, kehrt der Zug ohne Kampf um (Nachricht an den Angreifer).
- **Gegenschlag erlaubt:** Greift ein geschützter Spieler selbst jemanden an, endet sein Schutz **gegenüber diesem einen Spieler**. Sonst könnte er aus dem Schutz heraus angreifen.
- **Abgewehrter Angriff** (Angreifer verliert) löst keine Schutzzeit aus – der Verteidiger hat ja gewonnen.
- **Anzeige:** Auf der Karte, in der Zielauswahl unter „Züge“ und im Erkundungsbericht steht „geschützt bis 18:40“ mit Schloss-Symbol. Der Verteidiger sieht seine eigene Schutzzeit im Kopf der Seite.

### A.5 Umgesetzte Fassung: persönliche Sperre + Verlustdeckel

**1. Persönliche Sperre (Angreifer → Opfer).** Nur wer angegriffen hat, ist für dieses Opfer gesperrt:

```
Sperre = max(T_MIN, S · T_max) / Spieltempo        S = Schaden DIESES Angriffs
```

**2. Gemeinsamer Verlustdeckel pro Opfer.**

```
Fenster          = 24 h / Spieltempo
S_fenster        = 1 − Π(1 − S_i)       über alle gewonnenen/unentschiedenen Angriffe im Fenster
Spielraum R      = VERLUST_MAX_TAG (50 %) − S_fenster
Erlaubter Verlust je Angriff = min(VERLUST_MAX_ANGRIFF (25 %), R)
Beute wird gekürzt, bis Kampfverluste + Beute ≤ erlaubter Verlust · Vermögen (gefallene Einheiten bleiben verloren)
R < VOLLSCHUTZ_REST (5 %) → Vollschutz für alle, bis genug alte Verluste aus dem Fenster gefallen sind
```

**3. Rückschlag.** Greift X den Spieler Y an, darf Y einmal (innerhalb 24 h / Tempo) zurückschlagen – trotz Sperre und Vollschutz,
mit der 25-%-Grenze. Das Recht ist mit dem ersten Rückschlag verbraucht (sonst könnte ein einziger Angriff tagelanges Farmen erlauben).

**Beispiel (Tempo 1):** A überfällt morgens B, C und D (je bis zu 25 %) und ist für jeden einige Stunden gesperrt. E kann B später
trotzdem überfallen (noch 25 % Spielraum). Danach hat B 50 % erreicht und ist für alle geschützt.

---

## B. Beute begrenzen

### B.1 Tresor (geschützte Vorräte)

Ein Teil jedes Vorrats ist nie erbeutbar:

```
Geschützt je Rohstoff = Lagergrenze · min(TRESOR_MAX, TRESOR_BASIS + TRESOR_JE_STUFE · Sicherheitstechnik)
Euro (kein Lager):      Geschützt = Euro-Produktion pro Stunde · TRESOR_EURO_STUNDEN · (1 + 0,5 · Sicherheitstechnik)
```

| Konstante | Vorschlag |
|---|---|
| `TRESOR_BASIS` | 10 % der Lagergrenze |
| `TRESOR_JE_STUFE` | +5 % je Stufe der neuen Forschung **Sicherheitstechnik** |
| `TRESOR_MAX` | 50 % |
| `TRESOR_EURO_STUNDEN` | 6 h Euro-Produktion |

Beute = min(BEUTE_ANTEIL · (Vorrat − Geschützt), Ladefläche). Die Lagerhalle bekommt damit einen zweiten Sinn.

### B.2 Beute nach Stärkeverhältnis

```
r < 0,2:          Raubzug nicht möglich (außer der Schwächere hat in den letzten 72 h zuerst angegriffen)
0,2 ≤ r < 0,5:    Beutefaktor = (r − 0,2) / 0,3       → zwischen 0 % und 100 %
r ≥ 0,5:          Beutefaktor = 100 %
```

Starke spielen gegen Starke. Neue und abgehängte Spieler sind kein lohnendes Dauerziel.

### B.3 Wiederaufbauhilfe

Wer einen Raubzug verliert, produziert **12 h lang 25 % mehr** (Produktionsfaktor wie bei den Ereignissen, nicht stapelbar).
Das verhindert die Abwärtsspirale „einmal verloren, nie wieder aufgeholt“.

---

## C. Spionage-Abwehr

### C.1 Was sich ändert

| Heute | Neu |
|---|---|
| Vorräte immer exakt sichtbar | Werte als **Spanne**, genauer erst mit Aufwand |
| Kurier kostet 400 Rohstoffe | Kurier deutlich teurer, jede Erkundung kostet zusätzlich Euro |
| Erkundung immer erfolgreich | Kuriere können **abgefangen** werden – dann sind sie weg und der Verteidiger erfährt, wer es war |
| Keine Pause | **Erkundungssperre** pro Ziel |
| Nur Informationsnetz zählt | Neue Abwehr-Forschung **Abschirmung**, Wachtürme und Sicherheitskräfte helfen mit |

### C.2 Kosten

| Was | Heute | Vorschlag |
|---|---|---|
| E-Bike-Kurier | 100 Holz, 300 Baustoffe | 400 Holz, 800 Baustoffe, 100 Euro |
| Einsatzkosten je Erkundung | – | 100 Euro + 50 Euro je Stufe Informationsnetz (Bestechung, Ausrüstung) |
| Informationsnetz (Forschung) | Faktor 2,0 | Faktor 2,2, Startkosten doppelt |
| **Abschirmung** (neu) | – | 400 Holz, 800 Baustoffe, 200 Euro, Faktor 2,0; braucht Forschungszentrum 2 und Sicherheitszentrale 1 |

### C.3 Erkundungsstärke und Abwehr

```
Angriffswert  A = Informationsnetz (Angreifer) + log2(Anzahl Kuriere)
Abwehrwert    D = Abschirmung (Verteidiger) + 0,05 · Wachtürme + 0,01 · Sicherheitskräfte     (im erkundeten Bereich)
Vorsprung     V = A − D + Zufall(−0,5 … +0,5)
```

**Abfangen:** Jeder Kurier wird mit dieser Wahrscheinlichkeit abgefangen:

```
p_ab = clamp(0,10 + 0,15 · (D − A), 0,05, 0,90)
```

Werden **alle** Kuriere abgefangen, gibt es keinen Bericht. Der Verteidiger erfährt in jedem Fall, dass erkundet wurde, und
**den Namen des Auftraggebers**, sobald mindestens ein Kurier abgefangen wurde oder seine Abschirmung ≥ dem Informationsnetz des Spähers ist.

### C.4 Was der Bericht verrät

| Vorsprung V | Inhalt |
|---|---|
| unter 0 | nur Besitzer und Lagewerte; Vorräte „nicht erkennbar“ |
| 0 bis unter 1 | Vorräte als grobe Spanne (± 40 %) |
| 1 bis unter 2 | Vorräte ± 20 %, Einheiten als Größenordnung („etwa 30–50“) |
| 2 bis unter 3 | Vorräte ± 10 %, Einheiten genau, Gebäude |
| ab 3 | Vorräte ± 5 %, alles inkl. Forschung und Schutzzeit; ob der Bunker belegt ist (nicht wie voll) |

Die Spannen werden zufällig verschoben (der echte Wert liegt irgendwo darin, nicht immer in der Mitte).
Bunker-Inhalte und der Tresor-Anteil sind **nie** genau zu sehen. Ein Angreifer weiß also nie sicher, was ihn erwartet.

### C.5 Erkundungssperre

```
Sperre (gleicher Späher → gleicher Ziel-Spieler) = SPIONAGE_SPERRE / Spieltempo        Vorschlag: 6 h
Nach abgefangener Erkundung:                       doppelt so lang
```

Zusätzlich darf ein Spieler höchstens **eine** Erkundung pro Ziel-Spieler gleichzeitig unterwegs haben. Die Sperre gilt pro Spieler,
nicht pro Bereich, damit man sie nicht über mehrere Bereiche umgeht.

### C.6 Zusammenspiel mit der Angriffssperre

Erkunden ist auch während der Angriffssperre möglich (der Bericht zeigt „geschützt bis …“). Ein Angriff wird dadurch aber nicht früher möglich.

---

## D. Weitere Bausteine (optional, unabhängig umsetzbar)

| Baustein | Kurzbeschreibung |
|---|---|
| **Urlaubsmodus** | mindestens 48 h, höchstens 21 Tage; keine Raubzüge und Erkundungen gegen den Spieler, aber auch keine Produktion und keine eigenen Aktionen; frühestens 7 Tage nach dem letzten Urlaub erneut |
| **Angriffswarnung per E-Mail** | freiwillig hinterlegte Adresse; Mail bei anrückendem Raubzug (höchstens eine pro Stunde); Versand über PHP `mail()` von All-Inkl |
| **Vertretung** | bleibt wie in Version 6 |

---

## E. Umsetzung im Code (für später)

### E.1 Datenbank (Schema 7, nur Ergänzungen)

```sql
CREATE TABLE IF NOT EXISTS angriffe_log (          -- Grundlage für S_24, Anzeige und Statistik
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts INT UNSIGNED NOT NULL, angreifer_id INT UNSIGNED NOT NULL, verteidiger_id INT UNSIGNED NOT NULL,
  ergebnis VARCHAR(12) NOT NULL, wert_vorher DOUBLE NOT NULL, wert_verlust DOUBLE NOT NULL,
  KEY ix_al_vert (verteidiger_id, ts));
CREATE TABLE IF NOT EXISTS angriffsschutz (        -- Schutz des Verteidigers, ausgenommen ggf. einzelne Gegner (Gegenschlag)
  verteidiger_id INT UNSIGNED PRIMARY KEY, bis_ts INT UNSIGNED NOT NULL, ausnahmen VARCHAR(255) NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS spionage_log (          -- Erkundungssperre und Abfang-Statistik
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ts INT UNSIGNED NOT NULL, spaeher_id INT UNSIGNED NOT NULL,
  ziel_id INT UNSIGNED NOT NULL, abgefangen TINYINT(1) NOT NULL, sperre_bis INT UNSIGNED NOT NULL,
  KEY ix_sp (spaeher_id, ziel_id, ts));
-- Spalte spieler.wiederaufbau_bis_ts (Produktionsbonus), optional spieler.urlaub_bis_ts, spieler.warn_mail
```

Neue Forschungen `sicherheitstechnik` und `abschirmung` kommen nur in `FORSCHUNG` dazu – keine Tabellenänderung.

### E.2 Stellen im Code

| Datei | Änderung |
|---|---|
| `app/rules.php` | Konstanten aus A–C (`T_BASIS`, `K_SCHWAECHE`, `T_MIN`, `TRESOR_*`, `SPIONAGE_*`, Beutefaktor-Grenzen), neue Forschungen, neue Kurierkosten, Funktionen `schutzzeit(S_24, r, tempo)`, `beutefaktor(r)`, `tresor(…)` – rein rechnerisch, gut testbar |
| `app/einheiten.php` → `zug_starten` | bei `angriff`: Schutzzeit, Stärkeverhältnis (r < 0,2), Gegenschlag-Ausnahme prüfen; bei `erkunden`: Sperre, Einsatzkosten, nur eine Erkundung je Ziel unterwegs |
| `app/einheiten.php` → `zug_ankunft` | Schutzzeit erneut prüfen (sonst Umkehr); Gegenschlag: eigenen Schutz gegenüber dem Ziel aufheben |
| `app/einheiten.php` → `raubzug_ausfuehren` | Vermögen vorher/nachher über alle Bereiche, Beute mit Tresor und Beutefaktor, `angriffe_log` schreiben, S_24 berechnen, Schutz setzen, Wiederaufbauhilfe |
| `app/einheiten.php` → `erkundungsbericht` | Abfangen, Spannen statt exakter Werte, Name des Auftraggebers, `spionage_log` |
| `app/economy.php` | Wiederaufbaubonus in `produzieren_zeitraum` (wie Ereignis-Faktoren) |
| `app/planung.php` | „Zum Einsetzen“ für Kurier und Raubzug: Sperren und Kosten anzeigen |
| `app/views/zuege.php`, `app.js` | Schloss und „geschützt bis“ in der Zielauswahl; Vorschau zeigt Einsatzkosten und Sperre |
| `app/views/nachrichten.php` | Kampfbericht: Schutzzeit des Ziels; Erkundungsbericht: Spannen, „abgefangen“ |
| `app/views/kopf.php` | eigene Schutzzeit anzeigen |
| `app/ki.php` | Berater kennt Schutzzeiten und Tresor (Daten und Regeltext) |
| `app/ereignisse.php` | Zeitung: Hinweis auf geschützte Spieler nicht verraten (keine Namen) |
| `public/install.php` | neue Tabellen/Spalten; Schema-Version 7 |

### E.3 Übergang im laufenden Spiel

- Beim Update gibt es keine rückwirkenden Schutzzeiten; ab dem ersten Raubzug nach dem Update gelten die neuen Regeln.
- Bereits **unterwegs befindliche** Raubzüge werden bei Ankunft nach den neuen Regeln geprüft (Schutz → Umkehr).
- Alte Erkundungsberichte bleiben, wie sie sind.
- Die teureren Kuriere gelten nur für neu gebaute; vorhandene bleiben.
- Rundschreiben an alle Spieler mit den neuen Regeln (Textvorschlag bei der Umsetzung).

### E.4 Tests

- Formeln einzeln: Schutzzeit (Tabelle aus A.3), Beutefaktor, Tresor, Abfang-Wahrscheinlichkeit, Spannen enthalten immer den echten Wert.
- Ablauf: Raubzug → Schutz gesetzt → zweiter Raubzug abgelehnt; unterwegs befindlicher Zug kehrt um; Gegenschlag hebt Schutz nur gegenüber dem Ziel auf; abgewehrter Angriff setzt keinen Schutz; S_24 summiert mehrere Angreifer.
- Erkundung: Sperre, Einsatzkosten, Abfangen meldet den Namen, keine Bunker-Details.
- Update-Test wie bisher: Spielstand Version 7 → Version 8 unverändert, alle bisherigen Prüfungen weiter grün.

### E.5 Offene Entscheidungen vor der Umsetzung

1. Werte `T_BASIS` (12 h) und `K_SCHWAECHE` (1,0) – lieber großzügiger (z. B. 16 h)?
2. Schutz gegen **alle** Angreifer (Vorschlag) oder nur gegen denselben?
3. Soll die Sperre bei der Allianz des Angreifers mitzählen (Allianzmitglieder können sich nicht abwechseln)? Mit A.4 „gegen alle“ ist das automatisch erfüllt.
4. Urlaubsmodus und E-Mail-Warnung gleich mit oder später?
