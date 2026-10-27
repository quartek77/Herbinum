# Herbinum
Ein Browsergame das rund um den Ort Herbern im Münsterland spielt.

Aufbau- und Strategiespiel nach dem Vorbild von OGame – mit 21 echten Orten rund um Ascheberg-Herbern,
Karten aus OpenStreetMap, bis zu 40 Spielern. PHP und MySQL, läuft auf Shared Hosting (All-Inkl) ohne Build-Schritt.

## Ordner

| Ordner | Inhalt |
|---|---|
| [`herbinum/spiel`](herbinum/spiel) | Das Spiel selbst – Einrichtung, Regeln und Updates in der [README](herbinum/spiel/README.md) |
| [`herbinum/upload`](herbinum/upload) | Anleitungen für Installation und [Update auf Version 6](herbinum/upload/UPDATE-V6-ANLEITUNG.md) |
| [`herbinum/prototyp`](herbinum/prototyp) | Erster klickbarer Kartenprototyp |
| [`herbinum/werkzeuge`](herbinum/werkzeuge) | Python-Skripte, die die Kartendaten aus OpenStreetMap erzeugen |
| [`herbinum/PROJEKTVORLAGE.md`](herbinum/PROJEKTVORLAGE.md) | Kurzbeschreibung des Projekts und Erfahrungen für ähnliche Vorhaben |

## Wichtig

`config.php` (Datenbank-Passwort, Installationsschlüssel, KI-Schlüssel) gehört nie ins Repository –
sie ist per `.gitignore` ausgeschlossen. Vorlage: [`herbinum/spiel/config.example.php`](herbinum/spiel/config.example.php).

Kartendaten © OpenStreetMap-Mitwirkende, Lizenz ODbL.
