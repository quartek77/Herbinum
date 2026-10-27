# Update auf Version 8 (Fairness) – Schritt für Schritt

Dieses Paket enthält **Version 7 und Version 8 zusammen**. Läuft auf dem Server noch Version 6, genügt dieses eine Update –
Version 7 muss nicht vorher eingespielt werden.

**Neu in Version 8:**
- **Schutzzeit nach Überfällen:** Wer überfallen wurde, ist danach eine Zeit lang vor *allen* Raubzügen geschützt –
  je größer der Schaden und je schwächer das Opfer, desto länger (bei Spieltempo 2: höchstens 12 Stunden).
- **Tresor:** 10 % des Lagers sind nie erbeutbar, mit der neuen Forschung **Sicherheitstechnik** bis 50 %.
- **Stärkeverhältnis:** Keine Raubzüge gegen Spieler unter 20 % der eigenen Punkte, bis 50 % weniger Beute.
- **Wiederaufbauhilfe:** Nach einem verlorenen Raubzug 25 % mehr Produktion für einige Stunden.
- **Spionage-Abwehr:** Erkundungen kosten Einsatzgeld, liefern nur Schätzungen und sind gegen denselben Spieler nur alle paar Stunden möglich.
  Mit der neuen Forschung **Abschirmung** werden Kuriere abgefangen – und der Auftraggeber verraten.

Dazu alles aus Version 7: „Was brauche ich?“, KI-Berater, KI-Einstellungen, Online-Lampe, Wappen-Vorschau, moderne Begriffe.

**Spielstände bleiben erhalten.** Neu sind nur Tabellen (`angriffe_log`, `angriffsschutz`, `spionage_log`, aus Version 7 `ki_chat`, `ki_log`)
und eine Spalte. Getestet: Ein Spielstand der Version 7 war nach dem Update Feld für Feld identisch; die Wege 6 → 8 und 5 → 8 ebenfalls.

## 1. Sicherung

KAS → Datenbanken → phpMyAdmin → Datenbank anklicken → **Exportieren** → **OK**. Datei aufheben.

## 2. Entpacken

`herbinum-update-v8.zip` entpacken. Darin liegt ein Ordner `herbinum` mit `app`, `public`, `config.example.php`, `README.md`.
**Eine `config.php` ist absichtlich nicht dabei.**

## 3. Hochladen

Auf deinem Server liegt das Spiel in `/herbinum.event-dv.de/`, die Domain zeigt auf `public`:

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

Richtig ist **„Update von Version 5 auf 7 fertig.“** (von Version 6 aus) bzw. **„von Version 6 auf 7“** (falls Version 7 schon lief).
Steht dort „auf dem neuesten Stand (Version 5)“, liegen die neuen Dateien noch nicht in `app/`.

## 5. Kurz prüfen

- [ ] **Züge:** rechts der Kasten **„Faire Regeln“**; in der Zielauswahl steht bei geschützten Spielern „🛡 bis …“.
- [ ] **Forschung:** neu sind **Sicherheitstechnik** und **Abschirmung**.
- [ ] **Fuhrpark:** Der E-Bike-Kurier kostet jetzt 400 Holz, 800 Baustoffe, 100 Euro.
- [ ] Alte Gestaltung? Einmal **Strg + F5**.

## 6. Den Spielern Bescheid geben

Vorschlag für ein Rundschreiben:

> **Update: Mehr Fairness in Herbinum.** Wer überfallen wurde, ist jetzt eine Weile vor weiteren Raubzügen geschützt –
> je größer der Schaden, desto länger. Ein Teil eures Lagers liegt immer sicher im Tresor (ausbaubar mit Sicherheitstechnik).
> Gegen deutlich Schwächere gibt es weniger oder keine Beute, und wer verliert, produziert eine Zeit lang schneller.
> Spionieren ist teurer und liefert nur noch Schätzungen; mit Abschirmung fangt ihr fremde Kuriere ab und erfahrt, wer euch ausspäht.
> Die Regeln stehen unter **Züge → Faire Regeln**.
> *(Falls Version 7 neu ist, zusätzlich:)* Viele Namen sind moderner geworden – Korn → Nahrung, Ziegel → Baustoffe, Taler → Euro,
> Hofstelle → Zentrale, Siedlertreck → Siedlerkonvoi. Neu sind „Was brauche ich?“ an jedem Gebäude und der KI-Berater.

## Werte nachjustieren

Alle Zahlen stehen in `app/rules.php` im Abschnitt **„Fairness“** (z. B. `SCHUTZ_T_BASIS_H = 12.0` für die Schutzzeit,
`TRESOR_BASIS = 0.10` für den Grundschutz). Datei ändern, nach `/herbinum.event-dv.de/app/` hochladen – fertig, ohne `install.php`.

## Notfall: zurück

Den Inhalt von `herbinum-update-v6.zip` (oder `-v7.zip`) genauso hochladen wie in Schritt 3. Die älteren Versionen laufen mit der
erweiterten Datenbank weiter; `install.php` muss nicht aufgerufen werden.
