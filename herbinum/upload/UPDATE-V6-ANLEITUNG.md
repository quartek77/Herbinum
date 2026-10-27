# Update auf Version 6 im laufenden Betrieb – Schritt für Schritt

**Was du brauchst:** die Datei `herbinum-update-v6.zip`, deinen FTP-Zugang (KAS → **FTP**), FileZilla
und den Installationsschlüssel aus der `config.php` auf dem Server (Zeile `'install_key' => '…'`).
**Dauer:** etwa 15 Minuten. Das Spiel ist dabei nur für wenige Sekunden nicht erreichbar.

## Was das Update mit dem Spielstand macht – und was nicht

- Es **legt nur neue Tabellen und Spalten an** (Chat, Markt, Bunker, Vertretung, Ereignisse, Zeitung).
  Spieler, Bereiche, Gebäude, Einheiten, Forschung, laufende Züge, Allianzen, Nachrichten und die Musik bleiben **unverändert**.
  Das ist getestet: Ein Spielstand der alten Version war nach dem Update Feld für Feld identisch.
- **Kein Neustart, kein Zurücksetzen.** Alle neuen Funktionen setzen auf dem vorhandenen Spielstand auf.
- Das Paket enthält **keine `config.php`** – deine Konfiguration auf dem Server bleibt, wie sie ist.
  Ebenso bleiben hochgeladene Musik und die interne Sperrdatei unangetastet.

## 1. Sicherung anlegen (5 Minuten – bitte nicht überspringen)

1. KAS → **Datenbanken** → bei `d048b287` auf **phpMyAdmin** klicken.
2. Links die Datenbank `d048b287` anklicken → oben **Exportieren** → Methode „Schnell“, Format „SQL“ → **OK**.
   Die Datei (z. B. `d048b287.sql`) gut aufheben.
3. Optional: In FileZilla den kompletten Ordner `/herbinum/` (bzw. den Ordner deiner Subdomain) auf den PC herunterladen.

## 2. Spieler vorwarnen (optional)

Spielleitung → **Rundschreiben**, z. B.: „Heute um 20 Uhr kommt ein großes Update (Markt, Chat, Bunker, Zeitung).
Das Spiel ist ein paar Sekunden nicht erreichbar.“

## 3. Dateien hochladen

1. `herbinum-update-v6.zip` entpacken. Es entsteht ein Ordner `herbinum` mit `app`, `public`, `config.example.php`, `README.md`.
   **Eine `config.php` ist absichtlich nicht dabei.**
2. FileZilla mit dem FTP-Zugang verbinden und rechts den Ordner öffnen, in dem das Spiel liegt
   (der Ordner, in dem `app/`, `public/` und `config.php` liegen).
3. Den **Inhalt** des entpackten Ordners `herbinum` dorthin hochladen. Fragt FileZilla wegen vorhandener Dateien:
   **„Überschreiben“** wählen und **„Immer diese Aktion verwenden“** anhaken.
4. Auf dem Server **nichts löschen** – auch keine alten Dateien.

Sobald die neuen Dateien oben sind, zeigt das Spiel kurz „Herbinum wird gerade aktualisiert“. Das ist richtig –
weiter mit Schritt 4.

## 4. Datenbank ergänzen

Im Browser aufrufen (den Schlüssel aus deiner `config.php` einsetzen):

```
https://herbinum.event-dv.de/install.php?key=DEIN-INSTALL-KEY
```

Erwartete Antwort:

```
Tabellen sind aktuell.
21 Orte und 207 Bereiche importiert.
Update von Version 4 auf 5 fertig.
```

(„Version 5“ ist die Nummer der Datenbankstruktur, nicht die des Spiels.) Ein zweiter Aufruf schadet nicht –
dann kommt „Herbinum ist auf dem neuesten Stand (Version 5)“. Danach läuft das Spiel normal weiter.

Steht dort zusätzlich „Achtung: Ordner public/uploads/wappen konnte nicht angelegt werden“: den Ordner
`public/uploads/wappen` per FileZilla anlegen (Rechtsklick → Verzeichnis erstellen) und ihm die Rechte **755** geben
(Rechtsklick → Dateiberechtigungen).

## 5. Kurz prüfen (3 Minuten)

Melde dich an und klicke einmal durch:

- [ ] Links ist jetzt eine Seitenleiste mit **Hof**, **Land** und **Dorfleben**; oben stehen Uhrzeit und Tageszeit.
- [ ] **Züge:** Erst den Ort wählen (nach Entfernung sortiert), dann den Bereich.
- [ ] **Markt:** In einem Bereich mit Marktplatz zeigt der Händler die Vorschau.
- [ ] **Chat:** Eine Nachricht auf den Dorfplatz schreiben.
- [ ] **Zeitung:** Die erste Ausgabe erscheint (ohne KI-Schlüssel als Kurzausgabe).
- [ ] **Spielleitung:** Abschnitte „Zufallsereignisse“ und „Herbinumer Anzeiger“ sind da.

## 6. Optional: Einstellungen in der config.php ergänzen

Das Spiel läuft auch ohne diesen Schritt (es gelten dann die Standardwerte). Wer etwas ändern möchte:
`config.php` per FileZilla herunterladen, mit dem Editor öffnen, ergänzen, speichern und wieder hochladen.

Im Abschnitt `'spiel' => [ … ]` (vor der schließenden `],`) einfügen:

```php
        'spieltag_stunden' => 24,       // Länge eines Spieltags (Tag/Nacht, Zeitung, Wettervorhersage)
        'ereignis_haeufigkeit' => 1.0,  // Zufallsereignisse: 1 = selten (etwa jeder 4.–5. Tag), 0 = nie, 2 = doppelt so oft
```

### KI-Zeitung einschalten (Herbinumer Anzeiger mit Claude Sonnet)

1. Auf **console.anthropic.com** ein Konto anlegen, unter **Billing** etwas Guthaben aufladen
   (5 € reichen lange – eine Ausgabe kostet nur wenige Cent) und am besten unter **Limits** ein monatliches Limit setzen.
2. Unter **API Keys** → **Create Key** einen Schlüssel erzeugen (beginnt mit `sk-ant-`) und kopieren.
3. In der `config.php` **vor** der Zeile mit `'install_key'` einfügen:

```php
    'ki' => [
        'api_key' => 'sk-ant-HIER-DEIN-SCHLÜSSEL',
        'modell' => 'claude-sonnet-5-5',
    ],
```

4. Speichern und hochladen. In der Spielleitung steht dann „KI an“. Die erste Ausgabe schreibt die Redaktion,
   sobald jemand die Zeitung öffnet (das dauert bis zu einer Minute). Mit „Heutige Ausgabe neu schreiben lassen“
   kannst du es sofort testen. Ein Fehler (z. B. falscher Schlüssel) steht ebenfalls dort – das Spiel zeigt dann weiter die Kurzausgabe.

Der Schlüssel steht nur in der `config.php` und damit außerhalb des öffentlichen Ordners. Nicht in Screenshots zeigen.

## Wenn etwas nicht klappt

| Was du siehst | Ursache und Lösung |
|---|---|
| „Herbinum wird gerade aktualisiert“ bleibt stehen | Schritt 4 noch nicht ausgeführt oder Schlüssel falsch abgetippt. |
| „Falscher oder fehlender Schlüssel“ | Den `install_key` genau so aus der `config.php` übernehmen (Groß-/Kleinschreibung, Sonderzeichen). |
| „config.php fehlt“ | Beim Hochladen wurde die `config.php` gelöscht oder verschoben. Aus der Sicherung (Schritt 1.3) zurückspielen. |
| Seite ohne neue Gestaltung | Browser-Cache: Seite mit Strg + F5 neu laden. |
| Wappen-Upload schlägt fehl | Ordner `public/uploads/wappen` anlegen, Rechte 755 (siehe Schritt 4). |
| Fehler 500 / weiße Seite | Ist eine Datei nur halb hochgeladen? In FileZilla unten unter „Fehlgeschlagene Übertragungen“ nachsehen und erneut hochladen. |

### Notfall: zurück zur alten Version

Die alte Version kommt mit der erweiterten Datenbank zurecht – die neuen Tabellen ignoriert sie einfach.

1. Den Ordner `herbinum` aus deiner alten Datei `herbinum-upload.zip` entpacken und **die darin enthaltene `config.php` löschen**
   (sie enthält nur einen Platzhalter statt deines Passworts).
2. Den Rest per FileZilla überschreibend hochladen. Die alte Version läuft sofort wieder, ein Aufruf von `install.php` ist nicht nötig.
3. Nur wenn die Daten selbst beschädigt wären: in phpMyAdmin unter **Importieren** die Sicherung aus Schritt 1 einspielen.

## Hinweis zur Sicherheit

In der bisherigen Datei `config.example.php` auf dem Server stand das echte Datenbank-Passwort.
Das Update ersetzt sie durch eine Fassung mit Platzhaltern. Die Datei lag außerhalb des öffentlichen Ordners,
war also nicht über den Browser abrufbar. Wer ganz sichergehen will, vergibt im KAS unter **Datenbanken** ein neues
Passwort und trägt es in die `config.php` auf dem Server ein.
