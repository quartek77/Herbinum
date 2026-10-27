# Update auf Version 7 – Schritt für Schritt

**Neu:** Hinweis „Was brauche ich?“ an jedem Gebäude, jeder Forschung und Einheit · KI-Berater für jeden Spieler ·
KI-Einstellungen mit Monatsbudget in der Spielleitung · Online-Lampe an Spielernamen · Wappen beim Überfahren des Allianz-Kürzels ·
moderne Begriffe und moderne News statt Dorfzeitung.

**Spielstände bleiben erhalten.** Das Update legt nur zwei neue Tabellen an (`ki_chat`, `ki_log`). Die neuen Namen sind reine Anzeige –
in der Datenbank heißt alles wie vorher. Getestet: Ein Spielstand der Version 6 war nach dem Update Feld für Feld identisch (364 Datensätze).

**Dauer:** etwa 10 Minuten. Du brauchst `herbinum-update-v7.zip`, FileZilla und den Installationsschlüssel aus deiner `config.php`.

## 1. Sicherung

KAS → Datenbanken → phpMyAdmin → Datenbank anklicken → **Exportieren** → **OK**. Datei aufheben.

## 2. Entpacken

`herbinum-update-v7.zip` entpacken. Darin liegt ein Ordner `herbinum` mit `app`, `public`, `config.example.php`, `README.md`.
**Eine `config.php` ist absichtlich nicht dabei.**

## 3. Hochladen – genau so, wie es auf deinem Server liegt

Auf deinem Server liegt das Spiel in `/herbinum.event-dv.de/`, die Domain zeigt auf den Unterordner `public`:

```
/herbinum.event-dv.de/
├── app/        ← hierhin kommt der Inhalt von herbinum/app
├── public/     ← hierhin kommt der Inhalt von herbinum/public
└── config.php  ← bleibt, wie sie ist
```

In FileZilla zuerst *Server → Versteckte Dateien anzeigen erzwingen* einschalten (wegen der `.htaccess`-Dateien). Dann:

1. **Links** den entpackten Ordner `herbinum/app` öffnen, alles markieren (Strg+A).
   **Rechts** `/herbinum.event-dv.de/app` öffnen. Markierte Dateien nach rechts ziehen → **Überschreiben** → „Immer diese Aktion verwenden“.
   Ab jetzt zeigt das Spiel kurz „wird gerade aktualisiert“ – das ist richtig.
2. **Links** `herbinum/public` öffnen, alles markieren. **Rechts** `/herbinum.event-dv.de/public` öffnen. Nach rechts ziehen → Überschreiben.
3. Unten in FileZilla **„Fehlgeschlagene Übertragungen“** prüfen: Steht dort etwas, diese Dateien erneut hochladen.

Nichts auf dem Server löschen, und nichts direkt in `/herbinum.event-dv.de/` hochladen (außer, wenn du magst, `README.md` und `config.example.php`).

## 4. Datenbank ergänzen

```
https://herbinum.event-dv.de/install.php?key=DEIN-INSTALL-KEY
```

Richtig ist: **„Update von Version 5 auf 6 fertig.“** (Das ist die Nummer der Datenbankstruktur.)
Steht dort „auf dem neuesten Stand (Version 5)“, liegen die neuen Dateien noch nicht in `app/` – Schritt 3.1 prüfen.

## 5. Kurz prüfen

- [ ] Links im Menü heißen die Gruppen **Basis**, **Region**, **Community**; unten in der Seitenleiste steht „x online“.
- [ ] **Gebäude** oder **Fuhrpark:** In jeder Karte gibt es „Was brauche ich?“ zum Aufklappen.
- [ ] **Rangliste:** Vor den Namen leuchtet die Online-Lampe; Maus über ein Allianz-Kürzel zeigt das Wappen.
- [ ] **Spielleitung:** Abschnitt **„KI: News und Berater“** ist da.
- [ ] Browser zeigt alte Gestaltung? Einmal **Strg + F5**.

## 6. KI einschalten (optional)

1. API-Schlüssel auf **console.anthropic.com** anlegen (Billing: Guthaben aufladen, unter Limits ein Monatslimit setzen; API Keys → Create Key).
2. `config.php` vom Server herunterladen (liegt in `/herbinum.event-dv.de/`, **nicht** in `public`). Vor der Zeile mit `'install_key'` einfügen –
   oder, falls schon ein `'ki'`-Block da ist, nur den Schlüssel eintragen (eine Zeile `'modell' => …` darf stehen bleiben, sie wird nicht mehr gebraucht):

```php
    'ki' => [
        'api_key' => 'sk-ant-HIER-DEIN-SCHLÜSSEL',
    ],
```

3. Speichern, hochladen, überschreiben.
4. **Spielleitung → KI: News und Berater:** Dort schaltest du News und Berater ein oder aus, wählst das Modell
   (Standard ist **Claude Haiku 5.5** – günstig und für beides gut geeignet), den Stil der News, das Fragen-Limit pro Spieler und Tag
   und ein **Monatsbudget**. Ist es erreicht, pausiert die KI bis zum Monatsersten. Die Kosten des laufenden Monats stehen direkt darüber.
5. Testen: „Heutige News neu schreiben lassen“ und im Menü **Basis → KI-Berater** eine Beispielfrage stellen.

Richtwerte mit Haiku: eine News-Ausgabe etwa 0,1 Cent, eine Berater-Frage etwa 0,2 Cent. Bei 20 Spielern mit je 5 Fragen am Tag
sind das grob 6 US-Dollar im Monat – mit dem Budget behältst du das im Griff.

## Den Spielern Bescheid geben

Vorschlag für ein Rundschreiben (Spielleitung → Rundschreiben):

> **Update: Herbinum geht mit der Zeit!** Viele Namen sind moderner geworden – eure Gebäude, Einheiten und Vorräte sind alle noch da,
> sie heißen nur anders: Korn → Nahrung, Ziegel → Baustoffe, Taler → Euro, Hofstelle → Zentrale, Siedlertreck → Siedlerkonvoi,
> Schützen → Sicherheitskräfte, Reiter → Einsatzteams. Neu: An jedem Gebäude und jeder Einheit zeigt „Was brauche ich?“, was noch fehlt –
> auch, was ihr zum Einsetzen braucht. Und unter Basis → KI-Berater beantwortet euch die KI Fragen zu euren eigenen Daten.

## Notfall: zurück zu Version 6

Version 6 kommt mit der erweiterten Datenbank zurecht. Den Inhalt von `herbinum-update-v6.zip` genauso hochladen wie in Schritt 3
(`app` nach `app`, `public` nach `public`). `install.php` muss nicht aufgerufen werden.
