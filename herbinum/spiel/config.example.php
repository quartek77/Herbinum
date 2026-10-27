<?php
// Kopiere diese Datei nach config.php und trage deine Werte ein.
// config.php gehört NICHT ins Git-Repository und nicht in den öffentlichen Ordner.
return [
    'db' => [
        'host' => 'localhost',          // bei All-Inkl: localhost
        'port' => 3306,
        'name' => 'DATENBANKNAME',      // Datenbankname aus dem KAS
        'user' => 'DATENBANKBENUTZER',  // Datenbankbenutzer aus dem KAS
        'pass' => 'DATENBANKPASSWORT',
    ],
    'spiel' => [
        'tempo' => 1.0,                 // 1 = normal, 2 = doppelt so schnell (Produktion und Bauzeiten)
        'max_spieler' => 40,
        'max_warteschlange' => 3,       // Bauaufträge pro Bereich hintereinander
        'schutz_stunden' => 72,         // Anfängerschutz nach der Registrierung: kein Angreifen, nicht angreifbar
        'musik_max_mb' => 20,           // größte erlaubte Musikdatei (zusätzlich gilt upload_max_filesize von PHP)
        'gen_max_mitglieder' => 10,     // höchstens so viele Spieler pro Allianz
        'spieltag_stunden' => 24,       // Länge eines Spieltags (Zeitung, Wettervorhersage)
        // 'tageslaenge_stunden' => 12,  // Tag-Nacht-Wechsel; ohne Angabe 24 ÷ tempo (bei Tempo 2 also 12 Stunden)
        'ereignis_haeufigkeit' => 1.0,  // Zufallsereignisse: 1 = selten (etwa jeder 4.–5. Tag), 0 = nie, 2 = doppelt so oft
    ],
    // KI für „Herbinum News“ und den KI-Berater (optional). Ohne Schlüssel erscheinen die News als Kurzmeldungen,
    // und den Berater gibt es nicht. Schlüssel unter https://console.anthropic.com anlegen.
    // Modelle, Ein/Aus, Fragen-Limit und Monatsbudget stellst du in der Spielleitung ein (Standard: Claude Haiku).
    'ki' => [
        'api_key' => '',
    ],
    // Einmaliger Schlüssel für install.php – nach der Installation egal, aber nicht leer lassen.
    'install_key' => 'HIER-EINEN-LANGEN-ZUFALLSWERT-EINTRAGEN',
];
