# Herbinum – spielbarer Kern

Browser-Aufbauspiel im südlichen Münsterland (PHP 8.1+ und MySQL/MariaDB, ohne Framework, ohne Build-Schritt).

Enthalten: Anmeldung und Registrierung (bis 40 Spieler), Startbereich in einem Ort nach Wahl,
vier Rohstoffe plus Strom und Einwohner, 12 Gebäude, Bauwarteschlange mit bis zu 3 Aufträgen,
isometrische Ansicht des eigenen Bereichs, Karte mit echten Besitzern.

Seit Version 2: 8 Forschungen in dem Forschungszentrum (Produktionsboni, Tempo, Informationsnetz, Verwaltung),
3 Einheiten aus dem Fuhrpark (E-Bike-Kurier, Transporter, Siedlerkonvoi), Züge mit den Aufträgen
Transport, Verlegen, Erkunden und Besiedeln (mit echten Entfernungen zwischen den Bereichen), Umkehren unterwegs,
Erkundungsberichte und Nachrichten.

## Wie es zusammenhängt

| Was | Wo | Voraussetzung |
|---|---|---|
| Forschung | Forschungszentrum des aktuellen Bereichs, gilt für alle Bereiche | Forschungszentrum 1 (Informationsnetz, Verwaltung: 3) |
| E-Bike-Kurier | Fuhrpark | Fuhrpark 1 |
| Transporter | Fuhrpark | Fuhrpark 2, Fahrzeugtechnik 1 |
| Siedlerkonvoi | Fuhrpark | Fuhrpark 3, Fahrzeugtechnik 2 |
| Weiteren Bereich besiedeln | Siedlerkonvoi zu einem freien Bereich | Verwaltung 1 → 2 Bereiche, 3 → 3, 5 → 4 … |

### Kämpfe (seit Version 3)

| Was | Wo | Voraussetzung | Angriff / Leben |
|---|---|---|---|
| Sicherheitskraft | Sicherheitszentrale | Sicherheitszentrale 1 | 10 / 40 |
| Einsatzteams | Sicherheitszentrale | Sicherheitszentrale 3, Einsatztraining 2 | 40 / 150 |
| Barrikade (bleibt im Bereich) | Sicherheitszentrale | Sicherheitszentrale 1 | 0 / 250 |
| Wachturm (bleibt im Bereich) | Sicherheitszentrale | Sicherheitszentrale 2 | 30 / 120 |
| Schutzwall (Gebäude) | Gebäude | Sicherheitszentrale 1 | +10 % Leben aller Verteidiger je Stufe |

- **Raubzug** ist ein Zugauftrag gegen besiedelte Bereiche anderer Spieler; mindestens ein Sicherheitskraft oder Einsatzteams muss mit.
  Transporter fahren gern mit – sie bringen die Beute heim.
- Bis zu 4 Runden, beide Seiten feuern gleichzeitig, der Schaden verteilt sich nach Lebenspunkten auf die Gegner (±10 % Zufall).
- Verteidiger: alle Einheiten und Anlagen im Bereich. Ihr Leben wird mit Schutzwall und der echten **Wehrkraft der Lage**
  verrechnet (Wehrkraft 0 → ×0,75, 100 → ×1,25).
- Forschung: **Einsatztraining** (+10 % Angriff), **Schutzausrüstung** (+10 % Leben).
- Sieg: bis zur Hälfte jeder Vorratsart, begrenzt durch die Ladefläche der Überlebenden. Unentschieden: keine Beute.
- Zerstörte Barrikaden und Wachtürme wachsen zu 70 % nach, Einheiten nicht.
- **Anfängerschutz:** 72 Stunden nach der Registrierung kann man weder angreifen noch angegriffen werden (`schutz_stunden` in `config.php`).
- Wer angegriffen wird, sieht oben eine Warnung und unter „Züge“ die Ankunftszeit. Beide Seiten bekommen einen Kampfbericht.

Die gesamte Kampflogik steckt in `kampf()` in `app/einheiten.php` und kommt ohne Datenbank aus – gut zum Ausprobieren.

Strecke = Luftlinie zwischen den Bereichen × 1,3 (Straßen statt Luftlinie). Der Zug fährt so schnell wie seine
langsamste Einheit. Transporter und Siedlerkonvois verbrauchen Nahrung pro km (bei Transport und Erkunden für Hin- und Rückweg).
Ankünfte, Rückkehrer und fertige Forschungen werden beim nächsten Seitenaufruf irgendeines Spielers verarbeitet –
in der richtigen zeitlichen Reihenfolge. Kommen zwei Siedlerkonvois zum selben Bereich, gewinnt der frühere.

## Ordner

```
spiel/
├── public/              ← nur dieser Ordner ist aus dem Web erreichbar
│   ├── index.php        Einstieg für alle Seiten und Aktionen
│   ├── install.php      einmalige Installation
│   ├── assets/          style.css, app.js
│   └── data/            Kartendaten aus OpenStreetMap (region.json, ort/*.json)
├── app/
│   ├── rules.php        Spielregeln: Gebäude, Kosten, Produktion – hier wird balanciert
│   ├── economy.php      Nachberechnung der Rohstoffe, Bauaufträge, Fuhrpark-Fortschritt
│   ├── einheiten.php    Forschung, Fuhrpark, Züge, Ereignisse, Erkundungsberichte
│   ├── auth.php         Anmeldung, Registrierung, Startbereich
│   ├── schema.sql       Tabellen
│   └── views/           Seitenvorlagen
├── config.example.php   Vorlage für config.php
└── .github/workflows/deploy.yml   optionales automatisches Hochladen
```

## Auf All-Inkl einrichten

1. **Datenbank anlegen:** KAS → Datenbanken → „Neue Datenbank“. Datenbankname, Benutzer und Passwort notieren.
2. **Dateien hochladen:** Per FTP (z. B. FileZilla, Zugangsdaten im KAS unter FTP) den kompletten Ordner `spiel` nach `/herbinum/` hochladen.
   Nicht mit hochladen: `config.php` aus deiner lokalen Kopie und `dev-testkonto.txt`.
3. **Domain auf `public/` zeigen lassen:** KAS → Domain bzw. Subdomain (z. B. `spiel.deine-domain.de`) → Zielverzeichnis `/herbinum/public/`.
   Geht das nicht, auf `/herbinum/` zeigen lassen – die `.htaccess` dort leitet dann nach `public/` um und sperrt den Rest.
4. **PHP-Version:** KAS → bei der Domain PHP 8.2 oder neuer einstellen.
5. **SSL einschalten:** KAS → SSL-Schutz → Let's-Encrypt-Zertifikat für die Domain.
6. **Konfiguration:** `config.example.php` nach `config.php` kopieren (im Ordner `/herbinum/`, nicht in `public/`) und Datenbankdaten eintragen.
   `install_key` auf einen langen Zufallswert setzen.
7. **Installieren:** Im Browser `https://spiel.deine-domain.de/install.php?key=DEIN-INSTALL-KEY` aufrufen.
   Erwartete Ausgabe: „21 Orte und 207 Bereiche importiert.“
8. **Mitspielen:** Startseite öffnen, „Neu mitspielen“. Der erste registrierte Spieler wird Admin.

## Rangliste und Genossenschaften (seit Version 5)

**Punkte:** 1 Punkt je 1.000 Rohstoffe, die in Gebäude, Forschung oder Einheiten geflossen sind – wie bei OGame.
Gebäude und Forschung zählen mit allen Stufen, Einheiten und Verteidigung mit ihren Baukosten (auch unterwegs).
Wer Einheiten im Kampf verliert, verliert auch die Punkte. Die Rangliste lässt sich nach Gesamt, Gebäuden, Forschung
und Einheiten sortieren; Genossenschaften werden nach der Summe ihrer Mitglieder gelistet.

**Genossenschaften** (Allianzen):

- Gründen mit Name und Kürzel (2–6 Zeichen, z. B. `LJH`); der Gründer ist Vorstand.
- Andere bewerben sich mit ein paar Worten; der Vorstand nimmt auf oder lehnt ab (Nachricht an beide Seiten).
  Bewerbungen bei anderen Genossenschaften verfallen mit der Aufnahme.
- Vorstand: Mitglieder in den Vorstand holen oder herausnehmen, entfernen, Beschreibung pflegen, auflösen (Kürzel zur Bestätigung).
- Alle Mitglieder: Nachricht an alle Mitglieder, austreten. Geht der letzte Vorstand, übernimmt das dienstälteste Mitglied;
  geht das letzte Mitglied, wird die Genossenschaft aufgelöst. Dasselbe passiert, wenn ein Admin einen Spieler löscht.
- **Unter Mitgliedern gibt es keine Raubzüge** – auch ein bereits laufender Raubzug kehrt um, wenn beide inzwischen
  in derselben Genossenschaft sind. Auf der Karte stehen Kürzel und „deine Genossenschaft“ bei den Besitzern.
- Höchstens 10 Mitglieder (`gen_max_mitglieder` in `config.php`).

## Spielleitung (Admin-Bereich)

Admins sehen im Menü „Spielleitung“. Der erste registrierte Spieler ist Admin; weitere können dort ernannt werden.

- **Hintergrundmusik:** Loop hochladen (MP3, OGG, M4A/AAC oder WAV), anhören, für alle abspielen, ausschalten, löschen,
  Standardlautstärke festlegen. Die Datei wird am Inhalt geprüft (nicht nur an der Endung) und unter einem Zufallsnamen
  in `public/uploads/musik/` gespeichert; dort verhindert eine `.htaccess`, dass Skripte ausgeführt werden.
  Spieler schalten die Musik oben rechts an und aus und stellen ihre Lautstärke selbst ein. Der Loop läuft lückenlos
  (Web Audio) und macht nach einem Seitenwechsel an derselben Stelle weiter. Browser spielen Ton erst nach dem ersten
  Klick ab – das ist eine Browser-Regel, kein Fehler. Für ganz nahtlose Übergänge eignet sich OGG am besten.
- **Größere Dateien:** Zeigt die Seite eine kleine Höchstgröße (z. B. 2 MB), im KAS unter den PHP-Einstellungen der Domain
  `upload_max_filesize` und `post_max_size` erhöhen (z. B. auf 32M). Die Spielgrenze steht in `config.php` (`musik_max_mb`).
- **Spieler:** sperren (mit Grund – wirkt sofort, auch für eingeloggte Spieler), entsperren, zum Admin machen,
  neues Passwort erzeugen (wird einmal angezeigt, zum Weitergeben), löschen (Name zur Bestätigung eintippen;
  alle Bereiche werden wieder frei). Das eigene Konto ist vor diesen Aktionen geschützt.
- **Rundschreiben:** Nachricht an alle Spieler, z. B. für Ereignisse oder Wartungsankündigungen.

Hochgeladene Musik liegt nur auf dem Server – sie ist nicht im Git-Repository und wird vom Deploy nicht überschrieben.

## Version 6: Markt, Chat, Briefe, Bunker, Vertretung, Ereignisse, Zeitung

Genossenschaften heißen jetzt **Allianzen** (nur im Spiel – Tabellen und Funktionen heißen intern weiter `genossenschaft…`).

| Was | Wo | Regeln (alle Werte in `app/rules.php`) |
|---|---|---|
| **Briefe** | Nachrichten → „Brief schreiben“ | an jeden Spieler, mit Antworten und Ordner „Gesendet“; höchstens 20 pro Stunde |
| **Chat** | Chat | öffentlich (alle), Allianz, privat; lädt alle 4 Sekunden nach; Admins können öffentliche Nachrichten entfernen; nach 30 Tagen gelöscht |
| **Händler** | Markt (braucht Handelszentrum im Bereich) | eigene Rohstoffe tauschen zum Grundwert (Holz 1, Baustoffe 1,5, Nahrung 1,2, Euro 4) mit 40 % Verlust, je Handelszentrumstufe −2 Punkte, je Stufe Handelskunde −3 Punkte, mindestens 10 %; Tageslimit 1.500 Grundwert × Handelszentrumstufe × Tempo, +25 % je Handelskunde |
| **Spielerhandel** | Markt → Schwarzes Brett | ab Handelskunde 2; Angebote an alle oder nur an die Allianz; Gegenwert 50–200 % des Grundwerts (kein Verschenken); Gebühr 10 %, unter Allianzmitgliedern 2 %; Ware wird beim Einstellen einbehalten und kommt bei Rückzug oder nach 3 Tagen zurück |
| **Bunker** | Gebäude (braucht Zentrale 3), Seite „Bunker“ | 15 Plätze je Stufe (+25 % je Stufe Bunkerbau); Kurier und Sicherheitskraft 1 Platz, Einsatzteams 2, Transporter 4, Siedlerkonvoi 6; Einlagern sofort, Auslagern dauert 4 h ÷ Tempo (−10 % je Bunker- und Bunkerbaustufe, mindestens 15 min); eingelagerte Einheiten kämpfen nicht, werden nicht erkundet und zählen weiter für die Rangliste |
| **Vertretung** | Vertretung | festes Zeitfenster (Wochentage, Uhrzeit, höchstens 14 h, auch über Mitternacht); Änderungen gelten nach 2 Stunden; im Fenster steuert man selbst nicht, Allianzmitglieder dürfen die freigegebenen Dinge (bauen, forschen, Einheiten, Transporte/Verlegen zwischen den eigenen Bereichen, Bunker, Händler); nie Raubzüge, Erkundung, Besiedlung, Spielerhandel, Nachrichten, Allianz- oder Admin-Dinge; jede Aktion wird dem Vertretenen gemeldet; „Ich bin wieder da“ beendet das Fenster vorzeitig |
| **Tag und Nacht** | Kopfzeile, Übersicht | Spieltag = `spieltag_stunden` (Standard 24, beginnt um Mitternacht); nachts (21–6 Uhr) +10 % Leben für Verteidiger (Nachtwache), Bereichsansicht dunkler |
| **Zufallsereignisse** | Nachrichten, Banner, Spielleitung | Sturm, Frost, Starkregen/Hochwasser, Hagel, Wirtschaftskrise, Zombie-Horde, Erdbeben; im Schnitt an jedem 4.–5. Spieltag (`ereignis_haeufigkeit`); ein Mittelpunkt mit Radius, Stärke je Bereich verschieden; einmalige Vorratsverluste von wenigen Prozent und zeitweise Produktionsänderungen; Züge langsamer; Zombies töten ungeschützte Einheiten (Sicherheitskräfte und Wachtürme schützen); Erdbeben verzögert Baustellen; Spielleitung kann auslösen, streichen und abschalten |
| **Herbinum News** | Zeitung | eine Ausgabe je Spieltag: Wetter und 4-Tage-Vorhersage (aus den geplanten Ereignissen, je weiter weg desto unsicherer, mit Fehlalarmen), Lokales vom Vortag ohne Spielernamen bei Kämpfen, Blick in den öffentlichen Chat; mit `ki.api_key` schreibt die KI den Text, sonst erscheinen Kurzmeldungen |
| **Wappen** | Allianz (Vorstand) | PNG, JPG, GIF, WebP bis 1 MB; wird mit GD neu gezeichnet und auf 256 px verkleinert; liegt in `public/uploads/wappen/` |
| **Züge** | Züge | Ziel in zwei Schritten: Ort (nach Entfernung sortiert, mit freien und besiedelten Bereichen), dann Bereich mit Filtern (frei, andere Spieler, eigene + Allianz); der Auftrag wählt den passenden Filter vor |

Neue Dateien: `app/markt.php`, `app/kommunikation.php` (Briefe, Chat), `app/vertretung.php`, `app/ereignisse.php`
(Tag/Nacht, Ereignisse, Wetter, Chronik, Zeitung, KI-Anfrage), Seiten `bunker`, `markt`, `chat`, `zeitung`, `vertretung`, `icons`.

## Version 7: Planungshilfe, KI-Berater, moderne Begriffe

| Was | Wo | Regeln |
|---|---|---|
| **„Was brauche ich?“** | jede Karte bei Gebäude, Forschung, Fuhrpark, Sicherheitszentrale | alle fehlenden Gebäude- und Forschungsstufen in Bau-Reihenfolge (Voraussetzungen der Voraussetzungen zuerst), mit Kosten; bei Einheiten zusätzlich, was zum **Einsetzen** fehlt (Siedlerkonvoi: Verwaltung für einen weiteren Bereich, Entfernung und Proviant zum nächsten freien Bereich; Raubzug: Anfängerschutz); Gesamtkosten bis einsatzbereit und Wartezeit bei der jetzigen Produktion (`app/planung.php`) |
| **KI-Berater** | Basis → KI-Berater | Chat mit Claude über die eigenen Daten: alle Bereiche, Vorräte, Produktion, Lager, Gebäude, Forschung, Einheiten, Bunker, anrückende Züge. Wartezeiten („bezahlbar in“) rechnet das Spiel exakt vor, die KI erklärt und plant. Anleitung und Beispielfragen zum Anklicken. Fragen-Limit pro Tag. Nur eigene Daten, nichts über andere Spieler (`app/ki.php`) |
| **KI-Einstellungen** | Spielleitung | News und Berater einzeln an/aus, Modell je Funktion (Standard **Claude Haiku 5.5**, wahlweise Sonnet 5.5), Stil der News, Fragen pro Spieler und Tag, Monatsbudget in US-Dollar mit Kostenanzeige; ist das Budget erreicht, pausiert die KI bis zum Monatsersten |
| **Online-Lampe** | Rangliste, Allianz, Chat, Markt, Karte, Spielleitung, Seitenleiste | grün = in den letzten 5 Minuten aktiv, gelb = bis 30 Minuten, grau = länger weg; unten in der Seitenleiste „x online“ |
| **Wappen am Kürzel** | überall, wo ein Allianz-Kürzel steht | beim Überfahren erscheint das Wappen mit dem Namen; auf der Allianzseite groß |
| **Moderne Begriffe** | überall | nur die angezeigten Namen – intern (Datenbank) bleibt alles gleich, siehe Tabelle unten |

| Früher | Jetzt |
|---|---|
| Holz, Ziegel, Korn, Taler | Holz, Baustoffe, Nahrung, Euro |
| Hofstelle, Holzplatz, Ziegelei, Acker & Hof, Windrad, Scheune | Zentrale, Sägewerk, Baustoffwerk, Gewächshaus, Windpark, Lagerhalle |
| Wohnhäuser, Gasthof, Marktplatz, Dorfschule | Wohnviertel, Gastronomie, Handelszentrum, Forschungszentrum |
| Landmaschinen-Werkstatt, Schützenhalle, Wassergraben | Fuhrpark, Sicherheitszentrale, Schutzwall |
| Fahrradkurier, Trecker mit Anhänger, Siedlertreck | E-Bike-Kurier, Transporter, Siedlerkonvoi |
| Schützenbruder, Reiter, Wallhecke, Hochsitz | Sicherheitskraft, Einsatzteam, Barrikade, Wachturm |
| Ziegelbrand, Fruchtfolge, Mechanik, Pättkes-Netz, Dorftratsch, Schießausbildung | Baustofftechnik, Agrartechnik, Fahrzeugtechnik, Radwegenetz, Informationsnetz, Einsatztraining |
| Herbinumer Anzeiger, Dorfplatz | Herbinum News, öffentlicher Chat |
| Menü: Hof, Land, Dorfleben | Basis, Region, Community |

**KI:** Schlüssel in `config.php` → `'ki' => ['api_key' => 'sk-ant-…']`, alles andere in der Spielleitung. Die Anfragen gehen per curl
an die Claude-API (`https://api.anthropic.com/v1/messages`, kein Composer nötig). Die News entstehen einmal pro Spieltag beim ersten Öffnen,
im Hintergrund; Fehler stehen in der Spielleitung, das Spiel zeigt dann die Kurzmeldungen. Der Berater schickt die gleichbleibende
Spielanleitung als zwischenspeicherbaren Block und die aktuellen Daten nur mit der jeweils neuen Frage; frühere Fragen und Antworten
gehen als reiner Text mit. Jede Anfrage wird mit Token-Zahlen in `ki_log` protokolliert (Grundlage für Kostenanzeige und Budget).

## Version 8: Fairness für Gelegenheitsspieler

Damit niemand durch Dauer-Überfälle aus dem Spiel gedrängt wird, solange er nicht online ist. Konzept mit Beispielen:
`../konzepte/V8-KONZEPT-FAIRNESS.md`, alle Werte in `app/rules.php` (Abschnitt „Fairness“), Logik in `app/fairness.php`.

| Regel | Wie |
|---|---|
| **Persönliche Sperre** | Nach einem gewonnenen oder unentschiedenen Raubzug ist **nur dieser Angreifer** für das Opfer gesperrt: `max(1 h, S · T_max) / Tempo`. S = Schaden dieses Angriffs als Anteil am Vermögen (Vorräte + Einheiten + Anlagen aller Bereiche, Grundwerte wie beim Händler); T_max = 12 h · (1 + (1 − min(1, r))), r = Punkte Opfer / Punkte Angreifer (12 bis 24 h). Andere Spieler werden nicht ausgesperrt; anrollende Züge des Gesperrten kehren um; abgewehrte Angriffe lösen keine Sperre aus. |
| **Verlustdeckel** | Ein Angriff darf samt Kampfverlusten höchstens **25 %** des Vermögens kosten, alle Angriffe im Fenster von 24 h ÷ Tempo zusammen höchstens **50 %** (kombiniert 1 − (1 − S₁)(1 − S₂)…). Die Beute wird entsprechend gekürzt; gefallene Einheiten bleiben verloren. Bleiben weniger als 5 % Spielraum, ist das Opfer für **alle** geschützt, bis alte Verluste aus dem Fenster fallen. |
| **Rückschlag** | Wer jemanden angreift, darf von diesem Ziel einmal zurückbekommen (24 h ÷ Tempo lang), auch trotz Sperre oder Tagesgrenze; die 25 %-Grenze gilt weiter. |
| **Stärkeverhältnis** | Unter 20 % der Punkte des Angreifers kein Raubzug, 20–50 % anteilig weniger Beute – außer der Schwächere hat in den letzten 72 h zuerst angegriffen. |
| **Tresor** | 10 % der Lagergrenze je Rohstoff sind nie erbeutbar, +5 % je Stufe der neuen Forschung **Sicherheitstechnik** (höchstens 50 %); bei Euro 6 h Produktion (+50 % je Stufe). |
| **Wiederaufbauhilfe** | Wer einen Raubzug verliert, produziert 12 h ÷ Tempo lang 25 % mehr (alle Bereiche). |
| **Spionage-Abwehr** | E-Bike-Kurier kostet 400 Holz, 800 Baustoffe, 100 Euro; jede Erkundung eines Spielers zusätzlich 100 Euro + 50 je Stufe Informationsnetz. Neue Forschung **Abschirmung**. Angriffswert A = Informationsnetz + log₂(Kuriere), Abwehrwert D = Abschirmung + 0,05 · Wachtürme + 0,01 · Sicherheitskräfte. Jeder Kurier wird mit 10 % + 15 % · (D − A) abgefangen (5–90 %). Bericht je Vorsprung A − D ± 0,5: unter 0 keine Vorräte, dann Spannen ± 40 / 20 / 10 / 5 %, ab 1 Einheiten ungefähr, ab 2 genau und Gebäude, ab 3 Forschung, Schutzzeit, ob der Bunker belegt ist. Bunker-Inhalt und Tresor nie genau. Erkundungssperre je Ziel-Spieler 6 h ÷ Tempo (abgefangen: 12 h), nur eine Erkundung je Ziel gleichzeitig. Der Erkundete erfährt den Namen, wenn Kuriere abgefangen wurden oder seine Abschirmung ≥ 1 und ≥ dem Informationsnetz des Spähers ist. |

Anzeigen: eigene Verluste, Tagesgrenze und Wiederaufbauhilfe oben im Kopf; „🛡 für dich gesperrt bis …“, Spielraum und Erkundungssperre
in der Zielauswahl unter Züge und auf der Karte; Kampfbericht mit Schaden, Sperre, Spielraum, Tresor und Beutefaktor; Kasten „Faire Regeln“ unter Züge;
Planungshilfe und KI-Berater kennen die Regeln. Neue Tabellen: `angriffe_log`, `angriffssperre`, `spionage_log`, Spalte `spieler.wiederaufbau_bis_ts`.

Hinweis: Weil der Kurier teurer ist, steigen die Punkte für bereits vorhandene Kuriere etwas (Einheiten zählen mit ihren aktuellen Baukosten).
Die Kosten der Forschung Informationsnetz sind bewusst **nicht** erhöht worden, damit sich die Punkte für bereits erforschte Stufen nicht
rückwirkend ändern – die Erkundung wird stattdessen über Kurierpreis und Einsatzgeld teurer.

## Spieltempo und Balancing

- `config.php` → `spiel.tempo`: 1 = normal, 2 = doppelt so schnell. Gilt für Produktion und Bauzeiten.
- Alle Kosten, Faktoren und Produktionsformeln stehen in `app/rules.php` (Formeln wie in OGame).
- Rohstoffe werden nicht per Cronjob gutgeschrieben, sondern beim Seitenaufruf nachberechnet – ein Cronjob ist nicht nötig.

## Updates einspielen

1. Neue Dateien per FTP überschreiben (oder per GitHub-Deploy). `config.php` auf dem Server bleibt unangetastet.
2. Braucht das Update neue Tabellen, zeigt das Spiel „Herbinum wird gerade aktualisiert“.
   Dann einmal `install.php?key=DEIN-INSTALL-KEY` aufrufen – fehlende Tabellen und Spalten werden ergänzt,
   Spieler und Spielstände bleiben erhalten. Danach läuft das Spiel normal weiter.

Version 2 (Forschung, Einheiten, Züge) braucht genau diesen Schritt. Version 3 (Kämpfe) braucht keine neuen Tabellen –
nur die Dateien hochladen und in `config.php` die Zeile `'schutz_stunden' => 72,` ergänzen (fehlt sie, gelten 72 Stunden).
Version 4 (Spielleitung, Musik) braucht wieder den Update-Schritt mit `install.php?key=…`; danach muss der Ordner
`public/uploads/musik/` existieren und für PHP beschreibbar sein (legt das Update selbst an; sonst per FTP anlegen).
Version 5 (Rangliste, Genossenschaften) braucht ebenfalls den Update-Schritt mit `install.php?key=…`.
Version 6 (Markt, Chat, Bunker, Vertretung, Ereignisse, Zeitung; Datenbankstruktur 5) braucht den Update-Schritt ebenfalls.
Er legt nur neue Tabellen und Spalten an und den Ordner `public/uploads/wappen/`. Neue Einträge in `config.php` sind optional
(`spieltag_stunden`, `ereignis_haeufigkeit`, `ki`). Siehe `upload/UPDATE-V6-ANLEITUNG.md`.
Version 7 (Planungshilfe, KI-Berater, moderne Begriffe; Datenbankstruktur 6) braucht den Update-Schritt ebenfalls – er legt nur
die Tabellen `ki_chat` und `ki_log` an. Siehe `upload/UPDATE-V7-ANLEITUNG.md`.
Version 8 (Fairness; Datenbankstruktur 7) braucht den Update-Schritt ebenfalls und enthält Version 7. Siehe `upload/UPDATE-V8-ANLEITUNG.md`.

## Optional: GitHub + automatisches Hochladen

1. Auf github.com ein **privates** Repository anlegen und den Ordner `spiel` hineinlegen
   (`config.php` und `dev-testkonto.txt` sind per `.gitignore` ausgeschlossen).
2. Im Repository unter *Settings → Secrets and variables → Actions* drei Secrets anlegen:
   `FTP_SERVER` (z. B. `w0123456.kasserver.com`), `FTP_USERNAME`, `FTP_PASSWORD` – am besten einen eigenen FTP-Benutzer
   im KAS anlegen, der nur Zugriff auf `/herbinum/` hat.
3. Jeder Push auf den Branch `main` lädt die geänderten Dateien automatisch hoch (`.github/workflows/deploy.yml`).

## Lokal testen

Benötigt PHP 8.1+ mit `pdo_mysql` und eine MySQL/MariaDB-Datenbank:

```
php public/install.php
php -S 127.0.0.1:8770 -t public
```

## Datenquelle

Karten- und Bereichsdaten: © OpenStreetMap-Mitwirkende, Lizenz ODbL. Der Hinweis im Seitenfuß muss sichtbar bleiben.
