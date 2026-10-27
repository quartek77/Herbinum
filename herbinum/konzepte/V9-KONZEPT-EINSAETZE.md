# Konzept Version 9: Einsätze statt Sperren

> **Stand: Konzept, noch nicht umgesetzt.** Baut auf Version 8 (Fairness) auf. Die offenen Fragen sind mit dem Spielleiter geklärt:
> Sicherheitskraft zu Fuß mit 5 km/h · NPC-Anzahl in der Spielleitung einstellbar, Stärke wächst mit dem Mittelfeld ·
> Warnung an den Verteidiger mit Namen des Angreifers · nach hohen Verlusten nur noch Blauhelme, kein Angriffsverbot.

**Ziel:** Version 8 schützt Gelegenheitsspieler vor allem über Verbote („für dich gesperrt“, „Tagesgrenze erreicht“).
Das bremst offensive Spieler, ohne ihnen etwas dafür zu geben. Version 9 ersetzt diese Verbote durch **Zeit, Aufwand und Gegenwehr**:
Ein Angriff wird zu einer Operation mit Phasen. Sie bindet Truppen, kostet Wartung und lässt dem Verteidiger Zeit zu reagieren.
Offensive Spieler bekommen neue Ziele (NPC-Mitspieler) und neue Ausbauziele (Mannschaftstransporter, Kommandozentrale).

**Leitlinien**

1. **Bremsen durch Zeit, nicht durch Verbote.** Jeder Angriff bindet seine Truppen mehrere Stunden. Wer öfter angreifen will, baut dafür aus.
2. **Opferschutz bleibt, aber weich.** Statt eines Angriffsverbots rücken Blauhelme an. Angreifen bleibt möglich, wird aber teuer.
3. **Realistische Abläufe.** Rüsten, Marsch, Aufklärung, Gefecht, Plünderung, Rückmarsch, Instandsetzung.
4. **Offensive braucht Ziele.** NPC-Mitspieler sind legitime, faire Ziele und schonen die echten Gelegenheitsspieler.
5. **Spielstand bleibt erhalten.** Nur neue Tabellen und Spalten, kein Neustart, laufende Züge enden nach alten Regeln.
6. **Alle Zahlen zentral in `app/rules.php`**, alle Zeiten ÷ Spieltempo. Die Beispiele unten gelten für das Live-Tempo 2.

---

## A. Was aus den Regeln von Version 8 wird

| Version 8 | Version 9 |
|---|---|
| Persönliche Sperre (Angreifer → dasselbe Ziel) | **entfällt** – ersetzt durch den Einsatzzyklus (C) |
| Vollschutz ab 50 % Verlust im Fenster | **entfällt** – ersetzt durch volle Blauhelme (E) |
| Höchstens 25 % Verlust je Angriff, 50 % im Fenster | **bleibt als Beutegrenze**: Kampf findet statt, Beute wird gekürzt; ab 50 % gibt es keine Beute mehr |
| Rückschlagrecht (einmal) | bleibt – und **Blauhelme greifen beim Rückschlag nicht ein** |
| Tresor, Stärkeverhältnis (< 20 % kein Raubzug), Wiederaufbauhilfe | bleiben unverändert |
| Spionage-Abwehr, Einsatzgeld, Erkundungssperre | bleiben unverändert |

Die Tabelle `angriffssperre` bleibt bestehen und wird nur noch für das Rückschlagrecht (`frei_bis_ts`) genutzt.

---

## B. Ablauf eines Angriffs in Phasen

Jeder Raubzug durchläuft diese Phasen. Grundwerte in `rules.php` (Tempo 1), in Klammern die Live-Werte bei Tempo 2.

| Phase | Dauer (Grundwert) | Live bei Tempo 2 | Abbruch | Was passiert |
|---|---|---|---|---|
| **1 Rüsten** | 30 Min. + 2 Min. je 20 Einheiten, höchstens 2 Std. | 15 Min. + 1 Min./20 | **frei**, Einsatzgruppe sofort wieder frei | Truppen sammeln sich im Startbereich, stehen dort noch zur Verteidigung bereit |
| **2 Anmarsch** | Strecke ÷ Tempo der langsamsten Einheit (D) | 8 km zu Fuß ≈ 48 Min., im Transporter ≈ 7 Min. | Umkehr, Regeneration 50 % | wie heute |
| **3 Spähen** | 20 Min. | 10 Min. | **Rückzug möglich**, Regeneration 50 % | Angreifer sieht eine Schätzung der Verteidigung (Genauigkeit wie Erkundung). **Verteidiger wird gewarnt** – mit Namen des Angreifers |
| **4 Gefecht** | 60 Min. × 2 ÷ (1 + q), 20–120 Min. | 30 Min. bei q = 1 | **nein** | q = Kampfkraft Angreifer ÷ Kampfkraft Verteidiger (inkl. Blauhelme). Überlegene Angreifer sind schneller fertig |
| **5 Plündern** | 10 Min. + 2 Min. je 2.000 Rohstoffe, höchstens 60 Min. | 5 Min. + 1 Min./2.000 | nein | Beute wird gesichert und verladen |
| **6 Rückmarsch** | wie Anmarsch | | – | Beute kommt an |
| **7 Regeneration** | 4 Std. + 2 Min. je gefallener Einheit, höchstens 8 Std. | 2 Std. + 1 Min./Einheit | – | Wartung, Tanken, Munition. Einsatzgruppe bleibt belegt |

### B.1 Gefechtsdauer

```
Kampfkraft K = Σ Anzahl · Angriff · (1 + Einsatztraining)  +  Σ Anzahl · Leben · (1 + Schutzausrüstung) / 4
q            = K_Angreifer / K_Verteidiger          (Verteidiger inkl. Wall, Wehrkraft, Nacht, Blauhelme)
Dauer        = 60 Min. · 2 / (1 + q)                begrenzt auf 20 … 120 Min.  (÷ Tempo)
```

| q | 0,5 | 1 | 2 | 3 | 5 | ohne Verteidiger |
|---|---|---|---|---|---|---|
| Live-Dauer | 40 Min. | 30 Min. | 20 Min. | 15 Min. | 10 Min. | 10 Min. (Minimum) |

**Berechnung:** Der Kampf wird mit der bestehenden Funktion `kampf()` **zu Beginn des Gefechts** mit dem Stand in diesem Moment berechnet.
Das Ergebnis wird gespeichert, aber erst **am Ende des Gefechts** gebucht und gemeldet. Die Beute wird am Ende der Plünderphase
vom dann vorhandenen Vorrat genommen (höchstens die berechnete Menge). Was der Verteidiger bis dahin in den Bunker gebracht oder
verbaut hat, ist sicher.

### B.2 Warnung in der Spähphase

Beginnt die Spähphase, bekommt der Verteidiger sofort eine Nachricht und ein rotes Band im Kopf:
„⚠ *Name* (Allianz) späht deinen Bereich *X* aus – Gefecht frühestens um *hh:mm*.“
So haben aktive Spieler Zeit, Waren in den Bunker zu legen oder Einheiten zu verlegen.
Gelegenheitsspieler schützen Tresor, Verlustgrenze und Blauhelme.

### B.3 Wartungskosten

Bei Rückkehr kostet die Regeneration **2 % des Grundwerts der zurückgekehrten Einheiten**, halb in Euro, halb in Nahrung.
Fehlt das Geld, dauert die Regeneration 50 % länger (keine Schulden).

---

## C. Einsatzgruppen und Kommandozentrale

- Jeder Spieler hat **1 Einsatzgruppe**. Ein Raubzug belegt sie von „Rüsten“ bis zum Ende der „Regeneration“.
- Solange keine Einsatzgruppe frei ist, kann der Spieler **keinen Raubzug starten** – auch nicht mit neu gebauten Einheiten.
- **Verteidigen, Transporte, Verlegen, Erkunden und Besiedeln sind davon nicht betroffen.**
  Einheiten in Regeneration verteidigen ihren Bereich weiter (mit voller Kampfkraft).
- Die Einsatzgruppen zählen **pro Spieler**, nicht pro Bereich.

### C.1 Neues Gebäude: Kommandozentrale (`kommandozentrale`)

| | Wert |
|---|---|
| Wirkung | +1 Einsatzgruppe je Stufe, höchstens Stufe 3 (= 4 parallele Raubzüge) |
| Kosten Stufe 1 | 8.000 Holz, 16.000 Baustoffe, 4.000 Nahrung, 2.000 Euro; Faktor 3,0 je Stufe |
| Voraussetzungen | Zentrale 6, Forschungszentrum 5, Sicherheitszentrale 5, Logistik 2, Funktechnik 2, Taktik 1 |
| Besonderheit | nur in **einem** Bereich je Spieler baubar |

### C.2 Neue Forschungen

| Schlüssel | Name | Wirkung je Stufe | Kosten Stufe 1 (Faktor 2,0) | Voraussetzung |
|---|---|---|---|---|
| `logistik` | Logistik | +15 % Plätze im Mannschaftstransporter | 1.000 Holz, 1.500 Baustoffe, 300 Euro | Forschungszentrum 3, Fuhrpark 4 |
| `funktechnik` | Funktechnik | −5 % Rüst- und Spähzeit (höchstens −25 %) | 800 Holz, 2.000 Baustoffe, 400 Euro | Forschungszentrum 4 |
| `taktik` | Taktik | −5 % Regenerationszeit (höchstens −25 %) | 1.500 Holz, 1.500 Baustoffe, 1.500 Nahrung, 500 Euro | Forschungszentrum 4, Einsatztraining 3 |

Die vorhandene Forschung **Fahrzeugtechnik** (`mechanik`, +10 % je Stufe) beschleunigt künftig auch den Mannschaftstransporter.

---

## D. Bewegung und Mannschaftstransporter

### D.1 Neue Einheit: Mannschaftstransporter (`mannschaftswagen`)

| | Wert |
|---|---|
| Tempo | 35 km/h (+10 % je Stufe Fahrzeugtechnik) |
| Plätze | 10 Plätze (+15 % je Stufe Logistik); Sicherheitskraft belegt 1 Platz, Einsatzteam 2 |
| Kampf | Angriff 0, Leben 500 |
| Ladung, Verbrauch | 200 Rohstoffe, 4 Nahrung je km |
| Kosten | 6.000 Holz, 8.000 Baustoffe, 2.000 Nahrung, 800 Euro |
| Voraussetzung | Fuhrpark 5, Fahrzeugtechnik 3, Logistik 1 |

### D.2 Tempo eines Zuges

```
Plätze      = Σ Mannschaftstransporter · 10 · (1 + 0,15 · Logistik)
Fahrgäste   = Sicherheitskräfte · 1 + Einsatzteams · 2       (in dieser Reihenfolge eingeladen: erst die langsamsten)
Zug-Tempo   = langsamste Einheit, die NICHT im Transporter sitzt;
              sitzen alle, dann das Tempo des Mannschaftstransporters
```

Die Zielauswahl zeigt: „12 von 20 Sicherheitskräften fahren mit, 8 marschieren – Ankunft in 48 Min. statt 7 Min.“

### D.3 Geänderte Werte

| Einheit | heute | Version 9 | Begründung |
|---|---|---|---|
| Sicherheitskraft | 12 km/h, E-Scooter, Radwegenetz +10 % | **5 km/h zu Fuß**, keine Tempo-Forschung | realistisch; der Mannschaftstransporter lohnt sich |
| Einsatzteam | 22 km/h, Geländewagen | unverändert | eigene Fahrzeuge |
| Radwegenetz | Kuriere und Sicherheitskräfte | **nur noch Kuriere** | Beschreibung anpassen |

Das ändert **keine gespeicherten Daten**: Züge, die beim Update unterwegs sind, behalten ihre Ankunftszeit.
Die Forschungsstufen bleiben, Punkte ändern sich nicht. Auch Transporte und Verlegungen mit Sicherheitskräften werden langsamer –
das ist gewollt und wird im Rundschreiben angekündigt.

---

## E. Blauhelme (Schutztruppe für Verlierer)

Verliert ein Bereich einen Raubzug (Angreifer gewinnt oder Unentschieden mit Beute), rückt eine neutrale **Schutztruppe** an.

```
Stärke beim Eintreffen   B₀ = 60 % der Kampfkraft K des siegreichen Angreifers
weitere Niederlage       B₀ = min(100 %, bisherige Reststärke + 60 %) der Kampfkraft des neuen Angreifers
Abbau                    linear auf 0 in 6 Stunden (÷ Tempo → live 3 Std.)
ab 50 % Verlust im Fenster  dauerhaft 100 % der stärksten Angreifer-Kampfkraft im Fenster, bis der Verlust wieder unter 50 % fällt
```

- Blauhelme kämpfen als zusätzliche Verteidiger (virtuelle Einheit: Angriff 30, Leben 150, Anzahl aus B ermittelt).
- Sie **fallen nicht dauerhaft** – ihre Verluste werden nicht gebucht.
- Sie gelten für den **Bereich**, der verloren hat, und greifen **nicht** gegen einen Spieler mit Rückschlagrecht ein.
- In der Zielauswahl: „🪖 Blauhelme vor Ort (noch etwa 70 %)“, im Spähbericht mit Schätzung.
- Ab 50 % Verlust gibt es zusätzlich **keine Beute mehr** (Beutegrenze aus A). Ein Angriff ist dann möglich, aber sinnlos.

---

## F. NPC-Mitspieler

### F.1 Grundsätze

- **Regelbasiert, ohne KI für Entscheidungen** – null Tokens im Spielbetrieb.
- Anzahl in der **Spielleitung einstellbar: 0–6, Standard 0.** Nach dem Update ändert sich also nichts, bis der Spielleiter NPCs einschaltet.
- NPCs sind normale Spieler-Konten mit `spieler.ist_npc = 1`: ohne Passwort (Anmeldung gesperrt), keine Allianz, kein Chat,
  zählen nicht zu `max_spieler`, in der Rangliste mit 🤖 gekennzeichnet.
- Sie besiedeln nur **Bereiche, die niemand nutzt**, und nie mehr als einen Bereich pro Ort.

### F.2 Verhalten

Drei Persönlichkeiten, zufällig verteilt:

| Typ | Schwerpunkt |
|---|---|
| **Händler** | Produktion, Handelszentrum, verkauft Überschüsse beim Händler |
| **Aufbauer** | Wohnviertel, Lebensqualität, Lager, wächst gleichmäßig |
| **Festung** | Schutzwall, Barrikaden, Wachtürme, Bunker – schwer zu knacken, wenig Beute |

Jeder NPC macht **höchstens einen Schritt je 30 Minuten (÷ Tempo)**, ausgelöst beim Seitenaufruf wie die Produktion (kein Cronjob).
Ein Schritt geht eine feste Prioritätenliste durch und führt die erste passende Aktion aus:

1. Nahrung oder Strom negativ → Gewächshaus bzw. Windpark ausbauen
2. Lager zu 90 % voll → Lagerhalle ausbauen
3. Verteidigung unter Soll (je nach Typ) → Barrikaden oder Wachtürme bauen
4. Kein Bau in der Warteschlange → nächstes Gebäude aus der Typ-Liste
5. Überschuss einer Ware → beim Händler tauschen

**Defensiv:** NPCs greifen nie von sich aus an. Sie nutzen nur ihr **Rückschlagrecht** – einmal, mit 50 % ihrer Truppen.

### F.3 Stärke wächst mit dem Mittelfeld

```
Zielpunkte   = 70 % … 90 % (je NPC fest gewürfelt) des Medians der echten, in den letzten 7 Tagen aktiven Spieler
Wachstum     = einmal täglich: Produktionsfaktor des NPC × 1,2 (unter Ziel) bzw. × 0,8 (über Ziel), begrenzt auf 0,5 … 3,0
```

So bleiben NPCs lohnende, aber faire Ziele – für Neulinge und für Fortgeschrittene.
Für sie gelten dieselben Schutzregeln wie für Menschen (Tresor, Beutegrenze, Blauhelme).

### F.4 Anzahl ändern

- **Erhöhen:** Beim nächsten Seitenaufruf wird ein NPC mit Startausstattung in einem freien Bereich angelegt.
- **Verringern:** Der zuletzt angelegte NPC „zieht weg“, sobald keine Züge mehr von ihm oder zu ihm unterwegs sind.
  Sein Konto und sein Bereich werden gelöscht, der Bereich wird frei. Echte Spielerdaten werden nie angefasst.

### F.5 KI nur für Atmosphäre (optional, Schalter in der Spielleitung)

- Name und Kurzprofil **einmal beim Anlegen** über Haiku (ca. 300 Tokens). Ohne KI wird ein Name aus einer Liste in `rules.php` gewählt.
- Die KI-Zeitung darf NPCs erwähnen. Sie bekommt dafür nur deren Namen und Ereignisse, also keine zusätzlichen Aufrufe.
- Kosten: Bruchteile eines Cents pro Tag, im bestehenden KI-Budget.

---

## G. Datenmodell (nur Ergänzungen)

```sql
-- Phasen eines Raubzugs (alte Züge haben phase = '' und laufen nach alten Regeln)
ALTER TABLE zuege ADD phase VARCHAR(12) NOT NULL DEFAULT '';
ALTER TABLE zuege ADD phase_bis_ts INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE zuege ADD kampf_json TEXT NULL;            -- zu Gefechtsbeginn berechnetes Ergebnis
ALTER TABLE zuege ADD gruppe TINYINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS einsatzgruppen (            -- belegt von Rüsten bis Ende Regeneration
  spieler_id INT UNSIGNED NOT NULL, gruppe TINYINT UNSIGNED NOT NULL,
  zug_id INT UNSIGNED NULL, bereit_ab_ts INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (spieler_id, gruppe)
);
CREATE TABLE IF NOT EXISTS blauhelme (
  bereich_id VARCHAR(40) PRIMARY KEY, staerke DOUBLE NOT NULL, ab_ts INT UNSIGNED NOT NULL, bis_ts INT UNSIGNED NOT NULL
);
ALTER TABLE spieler ADD ist_npc TINYINT NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS npc (
  spieler_id INT UNSIGNED PRIMARY KEY, typ VARCHAR(12) NOT NULL, ziel_anteil DOUBLE NOT NULL,
  prod_faktor DOUBLE NOT NULL DEFAULT 1, naechster_schritt_ts INT UNSIGNED NOT NULL DEFAULT 0
);
```

Neue Einstellungen in der Spielleitung (Tabelle der Einstellungen): `npc_anzahl` (0), `npc_ki_namen` (aus).
`SCHEMA_VERSION` 7 → 8. Gebäude `kommandozentrale`, Einheit `mannschaftswagen` und die Forschungen starten für alle bei 0.

## H. Übergang im laufenden Betrieb

1. Züge, die beim Update unterwegs sind (`phase = ''`), enden nach den Regeln von Version 8 (inkl. persönlicher Sperre).
2. Alle Spieler starten mit einer freien Einsatzgruppe. Vorhandene Einheiten sind sofort einsatzbereit.
3. Bestehende Sperren aus `angriffssperre` laufen aus und werden danach nicht mehr neu gesetzt.
4. NPCs erscheinen erst, wenn der Spielleiter die Anzahl in der Spielleitung erhöht.
5. Kein Neustart, keine geänderten Spielstände. Geprüft wird wie bisher mit Schnappschuss-Vergleich (alt → neu, Feld für Feld).

## I. Oberfläche

- **Züge:** Phasenleiste je Raubzug (Rüsten → Marsch → Spähen → Gefecht → Plündern → Rückmarsch → Regeneration) mit Countdown;
  Knopf „Abbrechen“ nur in Rüsten und Spähen. Anzeige der Einsatzgruppen („1 von 2 frei, nächste bereit um 14:20“).
- **Zielauswahl:** Ankunftszeit mit/ohne Transporter, Blauhelme, Beutegrenze.
- **Kopf:** rotes Warnband bei Spähern vor eigenen Bereichen.
- **„Faire Regeln“** wird zu **„Einsatzregeln“** und erklärt Phasen, Einsatzgruppen, Blauhelme, Beutegrenze, Rückschlag.
- **Berater:** bekommt Einsatzgruppen, laufende Phasen und Blauhelme in seine Daten.
- **Spielleitung:** Bereich „NPC-Mitspieler“ mit Anzahl, KI-Namen und Übersicht (Typ, Punkte, Ziel).

## J. Testplan

- Update 8 → 9 mit Schnappschuss: alle bestehenden Daten unverändert, laufender Zug endet nach alten Regeln.
- Phasen: Zeiten je Phase, Abbruch nur in Rüsten/Spähen, Gefechtsdauer für q = 0,5 / 1 / 3, Plünderdauer nach Menge.
- Einsatzgruppe belegt → kein zweiter Raubzug; Transport und Verteidigung weiter möglich; mit Kommandozentrale 2 parallel.
- Transporter: Tempo mit/ohne genug Plätze, Logistik-Bonus.
- Blauhelme: Stärke, Abbau, Steigerung nach zweiter Niederlage, kein Eingreifen beim Rückschlag, keine Beute ab 50 %.
- Warnung an den Verteidiger mit Angreifername; Beute nur aus dem Vorrat am Ende der Plünderung.
- NPC: Anlegen, Schritte, Wachstum zum Ziel, Rückschlag, Wegzug ohne laufende Züge, Anzahl 0 → keine NPCs.
- Wege 5 → 9, 6 → 9, 7 → 9 wie bisher.

## K. Offen für später

- Gemeinsame Verteidigung durch Allianz-Mitglieder (Verstärkung während der Spähphase).
- Urlaubsmodus, E-Mail-Warnung (aus Version 8 weiter offen).
