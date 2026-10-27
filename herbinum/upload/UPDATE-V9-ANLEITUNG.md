# Update auf Version 9 (Einsätze, Blauhelme, NPC-Mitspieler) – Schritt für Schritt

Dieses Paket enthält **Version 7, 8 und 9 zusammen**. Läuft auf dem Server noch Version 6, 7 oder 8, genügt dieses eine Update.

**Neu in Version 9:**
- **Raubzüge in Phasen:** Rüsten → Anmarsch → Spähen → Gefecht → Plündern → Rückmarsch → Regeneration. Bei Tempo 2 dauert das Rüsten etwa
  15 Minuten, das Spähen 10 Minuten und das Gefecht 10 bis 60 Minuten (je überlegener der Angreifer, desto kürzer). Im Rüsten lässt sich ein
  Raubzug kostenlos absagen, beim Spähen ist ein Rückzug möglich, im Gefecht nicht mehr. Der Verteidiger wird beim Spähen mit Namen gewarnt.
- **Einsatzgruppen und Kommandozentrale:** Jeder Raubzug belegt eine Einsatzgruppe bis zum Ende der Regeneration (bei Tempo 2 etwa 2 Stunden).
  Weitere Gruppen bringt das neue Gebäude **Kommandozentrale**, das viel Forschung braucht und teuer ist.
- **Mannschaftstransporter:** neue Einheit im Fuhrpark. **Sicherheitskräfte laufen jetzt zu Fuß (5 km/h)** – im Transporter geht es schnell.
- **Neue Forschungen:** Logistik, Funktechnik, Taktik.
- **Blauhelme statt Angriffsverbot:** Wer einen Raubzug verliert, bekommt eine neutrale Schutztruppe, die einige Stunden lang abnimmt.
  Die persönliche Sperre und der Vollschutz aus Version 8 entfallen. Über 50 % Tagesverlust gibt es weiterhin keine Beute.
- **NPC-Mitspieler:** computergesteuerte, defensive Mitspieler (🤖). Ihre Anzahl stellst du in der Spielleitung ein, **Standard ist 0**.
- **Tag und Nacht nach Spieltempo:** Bei Tempo 2 dauert ein Tag-Nacht-Wechsel 12 Stunden; die Uhr im Kopf zeigt die Spielzeit.
- **Moderne Bereichsansicht:** Betonleitwände und Container statt Hecken, Stahl-Wachtürme statt Hochsitze, Betonmauer statt Wassergraben,
  moderne Gebäude; neue Symbole für Nahrung, Euro und Transporter.

**Spielstände bleiben erhalten.** Neu sind nur Tabellen (`einsatzgruppen`, `blauhelme`, `npc`) und Spalten (`zuege.phase`, `zuege.gruppe`,
`zuege.kampf`, `spieler.ist_npc`). Getestet: Ein Spielstand der Version 8 war nach dem Update Feld für Feld identisch; die Wege 6 → 9 und
5 → 9 ebenfalls. Raubzüge, die beim Update unterwegs sind, kämpfen wie bisher sofort bei Ankunft.

## 1. Sicherung

KAS → Datenbanken → phpMyAdmin → Datenbank anklicken → **Exportieren** → **OK**. Datei aufheben.

## 2. Entpacken

`herbinum-update-v9.zip` entpacken. Darin liegt ein Ordner `herbinum` mit `app`, `public`, `config.example.php`, `README.md`.
**Eine `config.php` ist absichtlich nicht dabei.**

## 3. Hochladen

```
/herbinum.event-dv.de/
├── app/        ← hierhin kommt der Inhalt von herbinum/app
├── public/     ← hierhin kommt der Inhalt von herbinum/public
└── config.php  ← bleibt, wie sie ist
```

In FileZilla *Server → Versteckte Dateien anzeigen erzwingen* einschalten. Dann:

1. Links `herbinum/app` öffnen, alles markieren (Strg+A), rechts `/herbinum.event-dv.de/app` öffnen, hinüberziehen → **Überschreiben**
   (mit „Immer diese Aktion verwenden“). Ab jetzt zeigt das Spiel kurz „wird gerade aktualisiert“ – richtig so.
2. Links `herbinum/public` öffnen, alles markieren, rechts `/herbinum.event-dv.de/public` öffnen, hinüberziehen → Überschreiben.
3. Unten **„Fehlgeschlagene Übertragungen“** prüfen und ggf. wiederholen.

Nichts löschen und nichts direkt in `/herbinum.event-dv.de/` hochladen.

## 4. Datenbank ergänzen

```
https://herbinum.event-dv.de/install.php?key=DEIN-INSTALL-KEY
```

Richtig ist **„Update von Version 5 auf 8 fertig.“** (von Version 6 aus), **„von Version 6 auf 8“** (von Version 7) bzw. **„von Version 7 auf 8“** (von Version 8).
Steht dort „auf dem neuesten Stand“ mit einer kleineren Zahl als 8, liegen die neuen Dateien noch nicht in `app/`.

## 5. Kurz prüfen

- [ ] **Züge:** rechts die Kästen **„Einsatzgruppen“** und **„Einsatzregeln“**. Ein Raubzug zeigt die Phasenleiste.
- [ ] **Gebäude:** ganz unten die **Kommandozentrale**; **Forschung:** Logistik, Funktechnik, Taktik; **Fuhrpark:** Mannschaftstransporter.
- [ ] **Übersicht:** Barrikaden und Wachtürme sehen modern aus; oben steht die Uhr mit „Spielzeit“.
- [ ] Alte Gestaltung? Einmal **Strg + F5**.

## 6. NPC-Mitspieler einschalten (optional)

Spielleitung → **NPC-Mitspieler** → Anzahl eintragen (z. B. 3) → Speichern. Der erste NPC erscheint sofort, die weiteren alle 10 Minuten
einzeln. Sie siedeln nur in Orten mit mindestens 4 freien Bereichen und nie zwei im selben Ort. Wer die Anzahl wieder senkt, lässt NPCs
wegziehen, sobald keine Züge mehr von ihnen oder zu ihnen unterwegs sind; ihr Bereich wird wieder frei.
Mit dem Haken „Namen von der KI erfinden lassen“ kostet jeder neue NPC eine kurze Haiku-Anfrage (Bruchteile eines Cents).

## 7. Den Spielern Bescheid geben

Vorschlag für ein Rundschreiben:

> **Update: Raubzüge werden echte Einsätze.** Eure Truppen rüsten sich jetzt erst, marschieren los, spähen das Ziel aus und liefern sich ein
> Gefecht von 10 bis 60 Minuten, bevor sie plündern und heimkehren. Danach brauchen sie Zeit für Wartung, Tanken und Munition. Jeder Raubzug belegt
> eine Einsatzgruppe – mehr Gruppen gleichzeitig gibt es mit der neuen **Kommandozentrale**.
> Sicherheitskräfte laufen jetzt zu Fuß; schnell sind sie mit dem neuen **Mannschaftstransporter**.
> Wer angegriffen wird, bekommt beim Spähen eine Warnung mit dem Namen des Angreifers und hat ein paar Minuten, um zu reagieren.
> Statt Angriffssperren gibt es **Blauhelme**: Wer einen Raubzug verliert, bekommt eine Schutztruppe, die einige Stunden lang hilft.
> Neu sind auch computergesteuerte Mitspieler (🤖) – sie spielen defensiv und sind faire Ziele.
> Tag und Nacht laufen jetzt im Spieltempo. Alle Regeln stehen unter **Züge → Einsatzregeln**.

## Werte nachjustieren

Alle Zahlen stehen in `app/rules.php` im Abschnitt **„Einsätze“** (z. B. `GEFECHT_MIN = 60.0`, `REGENERATION_H = 4.0`, `BLAUHELM_ANTEIL = 0.60`)
und für die NPCs oben in `app/npc.php`. Datei ändern, nach `/herbinum.event-dv.de/app/` hochladen – fertig, ohne `install.php`.
Die Länge eines Tag-Nacht-Wechsels lässt sich in `config.php` mit `'tageslaenge_stunden' => 12,` im Block `spiel` festlegen (ohne Eintrag: 24 ÷ Tempo).

## Notfall: zurück

Den Inhalt von `herbinum-update-v8.zip` genauso hochladen wie in Schritt 3. Version 8 läuft mit der erweiterten Datenbank weiter;
`install.php` muss nicht aufgerufen werden. **Vorher** in der Spielleitung die NPC-Anzahl auf 0 setzen und warten, bis kein Raubzug
mehr läuft – Version 8 kennt die Phasen nicht und würde laufende Raubzüge falsch abrechnen.
