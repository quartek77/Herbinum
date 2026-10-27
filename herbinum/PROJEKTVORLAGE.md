# Projektvorlage: Herbinum

Kurzbeschreibung eines abgeschlossenen Projekts – als Vorlage und Erfahrungsschatz für ähnliche Vorhaben.

## Worum es geht

**Herbinum** ist ein browserbasiertes Aufbau- und Strategiespiel nach dem Vorbild von OGame/2Moons – mit einem
regionalen Setting: 21 echte Orte rund um Ascheberg-Herbern (südliches Münsterland) statt Sonnensystemen.
Bis zu 40 Spieler aus der Region erkennen ihre Orte und Straßen wieder – mit modernem Setting statt Dorfidylle.

| OGame | Herbinum |
|---|---|
| Galaxie / Sonnensystem / Planet | Region / Ort / Bereich (Viertel, Ortsteil) |
| Metall, Kristall, Deuterium, Energie | Holz, Baustoffe, Nahrung, Euro + Strom, Einwohner |
| Raumschiffe, Flotten | Transporter, E-Bike-Kuriere, Siedlerkonvois, Sicherheitskräfte, Einsatzteams |
| Allianzen | Allianzen (früher „Genossenschaften“) mit Wappen |

## Umfang (Version 7)

- **Karte aus OpenStreetMap:** Regionskarte, Ortspläne mit echten Gebäuden/Straßen, 207 Bereiche – automatisch aus der
  Bebauung abgeleitet, nach echten Straßen/Bauerschaften benannt.
- **Werte pro Bereich aus der echten Lage:** Bauplätze, Lebensqualität, Wehrkraft, Einkommen, Rohstoff-Boni
  (Wald → Holz, Ackerland → Nahrung usw.).
- **Spielmechanik:** 14 Gebäude mit Bauschleife, 12 Forschungen, 7 Einheiten/Verteidigungsanlagen, Züge (Transport,
  Verlegen, Erkunden, Besiedeln, Raubzug), rundenbasierte Kämpfe mit Beute, Anfängerschutz.
- **Sichtbares Wachstum:** isometrische Ansicht jedes Bereichs – Gebäude, Wege, Schutzwall und Barrikaden wachsen mit den Stufen.
- **Gemeinschaft:** Rangliste (OGame-Punkte), Allianzen mit Bewerbung/Vorstand und hochladbarem Wappen, Briefe zwischen allen Spielern,
  Chat (öffentlich, Allianz, privat) mit Online-Lampe an jedem Spielernamen, Wappen-Vorschau am Allianz-Kürzel, Rundschreiben.
- **Markt:** Händler tauscht eigene Rohstoffe mit Verlust und Tageslimit (besser durch Ausbau des Handelszentrums und Forschung „Handelskunde“);
  ab Handelskunde 2 Schwarzes Brett für Spielerhandel, unter Allianzmitgliedern mit viel geringerer Gebühr.
- **Bunker:** Einheiten und Fahrzeuge sicher vor Raubzügen und Erkundung einlagern; Auslagern mit Verzögerung; Platz und Tempo per Ausbau und Forschung.
- **Vertretung:** Berufstätige tragen ein festes Zeitfenster ein; darin steuern sie selbst nicht, die Allianz darf ausgewählte Dinge erledigen (alles protokolliert).
- **Tag/Nacht und Zufallsereignisse:** Sturm, Frost, Hochwasser, Hagel, Wirtschaftskrise, Zombie-Horde, Erdbeben – selten, gleichzeitig in der
  Region, aber je Bereich unterschiedlich stark, mit begrenztem Schaden. Nachts verteidigt die Nachtwache besser.
- **Herbinum News:** tägliches Nachrichtenportal mit Wetter und unsicherer Mehrtagesprognose der (vorausgeplanten) Ereignisse, Lagebericht
  ohne verräterische Details und Blick in den öffentlichen Chat – auf Wunsch von einer KI geschrieben (Stil einstellbar), sonst als Kurzmeldungen.
- **Planungshilfe „Was brauche ich?“:** an jedem Gebäude, jeder Forschung und Einheit alle fehlenden Stufen in Bau-Reihenfolge mit Kosten –
  bei Einheiten auch, was zum Einsetzen fehlt (z. B. Verwaltung für den Siedlerkonvoi), plus Gesamtkosten und Wartezeit bis einsatzbereit.
- **KI-Berater:** Jeder Spieler fragt eine KI nach seinen eigenen Daten („Wann kann ich X erforschen?“, „Was soll ich in welcher Reihenfolge bauen?“);
  mit Anleitung und Beispielfragen, Tageslimit pro Spieler.
- **Oberfläche:** Seitenleiste mit Gruppen und Icons, Rohstoffleiste mit Lagerbalken, Tageszeit im Kopf, Zielauswahl für Züge in zwei Schritten (Ort nach Entfernung, dann Bereich).
- **Spielleitung:** Admin-Bereich mit Spielerverwaltung (sperren, Passwort zurücksetzen, löschen), Upload eines
  Hintergrund-Musikloops (lückenlos über Web Audio), Steuerung der Zufallsereignisse und KI-Einstellungen (Modell je Funktion, Limits,
  Monatsbudget mit Kostenanzeige).

## Technik

- **Hosting:** Shared Hosting (All-Inkl Premium) – PHP 8.1+ und MySQL/MariaDB, **kein** Node.js, kein Framework,
  kein Build-Schritt. Upload per FTP, optional automatisches Deploy per GitHub Actions.
- **Aufbau:** `public/` als einziger Web-Ordner (Front-Controller `index.php`, `install.php`, Assets, Kartendaten als JSON);
  `app/` mit Logik und Vorlagen; `config.php` außerhalb des Web-Ordners.
- **Spielregeln zentral** in einer Datei (`rules.php`) – Balancing ohne Suche im Code; Spieltempo per Konfiguration.
- **Kein Cronjob nötig:** Rohstoffe und Ereignisse (Bauaufträge, Ankünfte, Forschung) werden beim Seitenaufruf
  zeitlich korrekt nachberechnet; Zeilen-Sperren (`SELECT … FOR UPDATE`) verhindern Doppelbuchungen.
- **Installer mit Versionsnummer:** `install.php?key=…` legt Tabellen an und ergänzt bei Updates fehlende
  Tabellen/Spalten, ohne Spielstände zu verlieren.
- **Sicherheit:** Prepared Statements, CSRF-Token, Passwort-Hashes, Login-Drosselung, Upload-Prüfung am Dateiinhalt.
- **KI ohne SDK:** News und Berater rufen die Claude-API per curl auf (Shared Hosting, kein Composer). Standardmodell ist das günstige
  Claude Haiku 5.5, Sonnet 5.5 ist pro Funktion wählbar. Ein Fehler fällt auf eine Vorlage zurück – das Spiel hängt nie an der KI.
- **Kosten im Griff:** Jede KI-Anfrage wird mit ihren Token-Zahlen protokolliert; daraus entstehen Kostenanzeige und ein Monatsbudget,
  bei dessen Erreichen die KI pausiert. Dazu ein Fragen-Limit pro Spieler und Tag.
- **Rechnen lässt man das Spiel, erklären die KI:** Der Berater bekommt vorab berechnete Wartezeiten („bezahlbar in“), fehlende
  Voraussetzungen und Kosten statt roher Formeln – so stimmen die Zahlen. Die gleichbleibende Spielanleitung geht als zwischenspeicherbarer
  Block mit, die aktuellen Daten nur an der neuen Frage; frühere Antworten als reiner Text (ohne Thinking-Blöcke).
- **Anzeige-Namen getrennt von Schlüsseln:** Gebäude, Einheiten und Rohstoffe haben interne Schlüssel (`hofstelle`, `korn` …) und
  Anzeigenamen in `rules.php`. Dadurch ließ sich das ganze Spiel im laufenden Betrieb umbenennen, ohne die Datenbank anzufassen.
- **Ereignisse vorausplanen:** Zufallsereignisse werden Tage im Voraus in der Datenbank geplant. Nur so kann eine Vorhersage stimmen –
  die Unsicherheit wird beim Schreiben der Vorhersage künstlich hinzugefügt. Produktionsänderungen durch Ereignisse fließen in die
  nachträgliche Rohstoffberechnung ein, indem der Zeitraum an Beginn und Ende jedes Ereignisses geteilt wird.
- **Kartendaten:** Python-Skripte (pyosmium, shapely) werten Geofabrik-Auszüge aus und erzeugen kompakte JSON-Dateien;
  Darstellung im Browser als SVG (Karten) und Canvas (isometrische Ansicht). Quellenangabe © OpenStreetMap-Mitwirkende (ODbL).

## Vorgehen, das sich bewährt hat

1. **Erst ein klickbarer Prototyp** der Visualisierung mit echten Daten – dann der spielbare Kern.
2. **In kleinen Ausbaustufen** liefern (Kern → Forschung/Einheiten → Kampf → Admin → Gemeinschaft), jede mit eigenem Update-Weg.
3. **Lokal mit derselben Technik testen** wie beim Hoster (portables PHP + MariaDB) und **automatische Testskripte** je
   Ausbaustufe (am Ende 185 Prüfungen), dazu ein Durchklicken im Browser.
4. **Vor der Auslieferung eine Frischinstallation** aus dem fertigen Upload-Paket in eine leere Datenbank.
5. **Updates im laufenden Betrieb testen:** mit der *alten* Version einen Spielstand aufbauen, das Update aus dem fertigen ZIP darüberspielen
   und alle Tabellen vorher/nachher vergleichen (Version 6: identisch, 96 Prüfungen; Version 7: 364 Datensätze identisch, 62 neue Prüfungen
   plus alle alten).
6. **KI ohne Schlüssel testen:** ein kleiner lokaler Ersatz-Server ahmt die API nach und speichert jede Anfrage – so lassen sich Modell,
   Header, Verlauf und Fehlerfälle prüfen, ohne Kosten zu verursachen.

## Stolpersteine beim Live-Gang (für künftige Projekte)

- **Nur das Upload-Paket hochladen**, nie den Entwicklungsordner: Sonst landen lokale Dateien auf dem Server
  (hier: Sperrdatei `app/.installiert` → Installer hielt sich für fertig; Testzugänge; `.gitignore`).
- **`.htaccess` in übergeordneten Ordnern** wirkt auch dann, wenn die Domain auf einen Unterordner zeigt –
  eine Umleitung nach `public/` führte zu `public/public/…` und damit zu „Not Found“.
- **Zufallsschlüssel ohne verwechselbare Zeichen** erzeugen (kein I/l/1, O/0) – sonst scheitert das Abtippen.
- **FTP-Pfad ≠ KAS-Pfad möglich:** Bei Zusatz-FTP-Benutzern beginnt „/“ in einem Unterordner. Im KAS den Ordner am besten
  über den Ordner-Browser auswählen.
- **Ein Testfile (`test.txt`)** im Web-Ordner klärt in Sekunden, ob die Domain auf den richtigen Ordner zeigt.
- **Zugangsdaten nicht in Screenshots** teilen; ein dort sichtbares Datenbank-Passwort danach ändern.
- **Beispielkonfiguration sauber halten:** `config.example.php` darf nie echte Zugangsdaten enthalten – sie wird mit hochgeladen.
- **Update-Pakete ohne `config.php`** bauen; so kann beim Drüberladen nichts überschrieben werden. Updates dürfen nur Tabellen und Spalten
  ergänzen (`CREATE TABLE IF NOT EXISTS`, Spalte nur anlegen, wenn sie fehlt) – nie bestehende Daten ändern.
- **Rückweg einplanen:** Die alte Version muss mit der erweiterten Datenbank weiterlaufen können (neue Tabellen ignoriert sie).
- **Zielordner genau beschreiben:** Zeigt die Domain auf einen Unterordner (`public/`), muss die Anleitung sagen, welcher Ordner aus dem
  Paket in welchen Ordner auf dem Server gehört – sonst landet der Inhalt von `public` eine Ebene zu hoch und `app` fehlt ganz.

## Offene Ideen

Gemeinsame Verteidigung in Allianzen (Truppen bei Verbündeten stationieren), Lieferung beim Spielerhandel per Transporter statt sofort,
weitere KI-Funktionen (Händler mit Tageslaune, KI-Lagebericht für die Allianz), positive Ereignisse (Festivals, Förderprogramme).
