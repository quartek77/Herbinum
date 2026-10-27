# Herbinum auf herbinum.event-dv.de installieren

Du brauchst: die Datei `herbinum-upload.zip`, das Passwort der Datenbank `d048b287`,
deine FTP-Zugangsdaten (KAS → **FTP**) und ein FTP-Programm wie **FileZilla**.
Dauer: etwa 20 Minuten.

## 1. Paket entpacken und Passwort eintragen

1. `herbinum-upload.zip` entpacken. Es entsteht ein Ordner `herbinum` mit `app`, `public`, `config.php` usw.
2. `herbinum/config.php` mit einem Texteditor öffnen (Editor/Notepad, Notepad++ – **nicht** Word).
3. In der Zeile
   `'pass' => 'HIER-DAS-DATENBANK-PASSWORT-EINTRAGEN',`
   den Platzhalter durch das Datenbank-Passwort ersetzen. Die einfachen Anführungszeichen bleiben stehen.
   Enthält das Passwort selbst ein `'`, schreibe davor einen Backslash: `\'`.
4. Speichern. Sonst nichts ändern – Datenbankname, Benutzer und Installationsschlüssel sind schon eingetragen.

> Das Passwort steht im KAS unter **Datenbanken** (Datenbank `d048b287` bearbeiten). Hast du es nicht mehr,
> kannst du dort ein neues vergeben.

## 2. Hochladen per FTP

1. FileZilla mit deinem FTP-Zugang verbinden (Server, Benutzer, Passwort aus dem KAS unter **FTP**).
2. Im KAS unter **Domain → Subdomains** bei `herbinum.event-dv.de` nachsehen, welcher Ordner eingetragen ist
   (oft `/herbinum.event-dv.de/` oder `/herbinum/`).
3. Den **Inhalt** des entpackten Ordners `herbinum` in genau diesen Ordner hochladen.
   Danach liegen dort: `app/`, `public/`, `config.php`, `config.example.php`, `README.md`, `.htaccess`.
   Tipp: In FileZilla unter *Server → Versteckte Dateien anzeigen erzwingen* aktivieren, damit die `.htaccess`-Dateien sichtbar sind.

## 3. Subdomain auf den Ordner `public` umstellen

Im KAS unter **Domain → Subdomains** `herbinum.event-dv.de` bearbeiten:

- **Pfad:** den bisherigen Ordner um `public/` ergänzen, also z. B. `/herbinum.event-dv.de/public/`.
  So ist nur das Spiel erreichbar – `config.php` und der Programmcode liegen außerhalb und sind für Besucher unsichtbar.
- **PHP-Version:** 8.2 oder neuer auswählen (mindestens 8.1).
- Speichern. Die Änderung wirkt meist nach ein bis zwei Minuten.

## 4. SSL einschalten

KAS → **Domain → SSL-Schutz** (bzw. beim Bearbeiten der Subdomain) → für `herbinum.event-dv.de`
ein **Let's-Encrypt-Zertifikat** anlegen. Danach läuft das Spiel unter `https://`.

## 5. Installieren

Im Browser aufrufen:

```
https://herbinum.event-dv.de/install.php?key=DEIN-INSTALL-KEY
```

Erwartete Antwort:

```
Tabellen sind aktuell.
21 Orte und 207 Bereiche importiert.
Fertig. Der erste Spieler, der sich registriert, wird Admin.
```

`DEIN-INSTALL-KEY` ist der Wert von `install_key` aus deiner `config.php`. Den Link nicht weitergeben. Ein zweiter Aufruf schadet nicht („auf dem neuesten Stand“).

## 6. Als Erster registrieren

`https://herbinum.event-dv.de` öffnen → **Neu mitspielen** → Name, Passwort, Startort wählen.
**Wichtig: Registriere dich selbst als Erstes** – der erste Spieler wird automatisch Admin
(Menüpunkt „Spielleitung“). Danach kannst du die Adresse an die Mitspieler geben.

## 7. Optional: Hintergrundmusik

Spielleitung → Hintergrundmusik → Loop hochladen (MP3, OGG, M4A oder WAV).
Steht dort eine kleine Höchstgröße (z. B. 2 MB): KAS → Domain bearbeiten → **PHP-Einstellungen** →
`upload_max_filesize` und `post_max_size` auf z. B. `32M` setzen.

## Wenn etwas nicht klappt

| Was du siehst | Ursache und Lösung |
|---|---|
| „config.php fehlt“ | `config.php` liegt nicht eine Ebene über `public/`. Prüfe, dass sie neben `app/` liegt. |
| „SQLSTATE[HY000] [1045] Access denied“ | Passwort in `config.php` stimmt nicht. Korrigieren und erneut hochladen. |
| „Herbinum ist noch nicht installiert“ | Schritt 5 noch nicht ausgeführt. |
| „Falscher oder fehlender Schlüssel“ | Den Link aus Schritt 5 vollständig kopieren. |
| Fehler 500 / weiße Seite | PHP-Version in Schritt 3 prüfen (mindestens 8.1). |
| Seite ohne Gestaltung | Subdomain zeigt nicht auf `public/` (Schritt 3), oder `public/assets/` fehlt. |
| Upload von Musik schlägt fehl | Ordner `public/uploads/musik` per FTP anlegen und Schreibrechte 755 geben. |

## Später: Updates

Neue Version per FTP drüberladen – `config.php` auf dem Server dabei **nicht** überschreiben.
Zeigt das Spiel „wird gerade aktualisiert“, den Link aus Schritt 5 einmal aufrufen. Spielstände bleiben erhalten.
