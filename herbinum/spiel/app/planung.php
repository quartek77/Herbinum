<?php
// Planungshilfe: Was brauche ich noch, um ein Gebäude, eine Forschung oder eine Einheit zu bauen – und um die Einheit
// danach auch einsetzen zu können? Liefert die fehlenden Schritte in sinnvoller Reihenfolge samt Kosten.
declare(strict_types=1);

/** Name und Kosten einer Stufe für Gebäude oder Forschung. */
function plan_eintrag(string $typ): ?array
{
    if (isset(GEBAEUDE[$typ])) {
        return ['art' => 'gebaeude', 'name' => GEBAEUDE[$typ]['name'], 'braucht' => GEBAEUDE[$typ]['braucht']];
    }
    if (isset(FORSCHUNG[$typ])) {
        return ['art' => 'forschung', 'name' => FORSCHUNG[$typ]['name'], 'braucht' => FORSCHUNG[$typ]['braucht']];
    }
    return null;
}

/**
 * Alle fehlenden Schritte bis $braucht erfüllt ist – Voraussetzungen der Voraussetzungen zuerst.
 * Gebäude zählen im aktuellen Bereich, Forschung gilt für den Spieler.
 * Ergebnis: [['art', 'typ', 'name', 'von', 'bis', 'kosten' => [...]], …]
 */
function fehlende_schritte(array $braucht, array $stufen, array $fo): array
{
    $ziel = [];          // typ => benötigte Stufe (höchste gewinnt)
    $reihenfolge = [];   // Abhängigkeiten zuerst
    $besuche = function (array $braucht, int $tiefe) use (&$besuche, &$ziel, &$reihenfolge, $stufen, $fo): void {
        if ($tiefe > 8) {
            return;
        }
        foreach ($braucht as $typ => $min) {
            $e = plan_eintrag($typ);
            if (!$e) {
                continue;
            }
            $ist = (int)($e['art'] === 'gebaeude' ? ($stufen[$typ] ?? 0) : ($fo[$typ] ?? 0));
            if ($ist >= $min) {
                continue;
            }
            $besuche($e['braucht'], $tiefe + 1);
            if (!isset($ziel[$typ])) {
                $reihenfolge[] = $typ;
            }
            $ziel[$typ] = max($ziel[$typ] ?? 0, (int)$min);
        }
    };
    $besuche($braucht, 0);
    $out = [];
    foreach ($reihenfolge as $typ) {
        $e = plan_eintrag($typ);
        $von = (int)($e['art'] === 'gebaeude' ? ($stufen[$typ] ?? 0) : ($fo[$typ] ?? 0));
        $kosten = array_fill_keys(array_keys(RESSOURCEN), 0);
        for ($l = $von + 1; $l <= $ziel[$typ]; $l++) {
            foreach ($e['art'] === 'gebaeude' ? baukosten($typ, $l) : forschungskosten($typ, $l) as $r => $k) {
                $kosten[$r] += $k;
            }
        }
        $out[] = ['art' => $e['art'], 'typ' => $typ, 'name' => $e['name'], 'von' => $von, 'bis' => $ziel[$typ], 'kosten' => $kosten];
    }
    return $out;
}

/** Wie viele Stufen Verwaltung für einen weiteren Bereich nötig sind (max_bereiche = 1 + (Verwaltung + 1) div 2). */
function verwaltung_fuer_bereiche(int $bereiche): int
{
    return max(0, 2 * ($bereiche - 1) - 1);
}

/**
 * Was eine Einheit über den Bau hinaus braucht, um eingesetzt zu werden.
 * Gibt ['braucht' => zusätzliche Voraussetzungen, 'hinweise' => [['text', 'ok' => true|false|null], …]] zurück.
 */
function einsatz_bedarf(string $typ, array $z, int $sid): array
{
    $b = $z['bereich'];
    $fo = $z['fo'];
    $db = db();
    $braucht = [];
    $h = [];
    switch ($typ) {
        case 'siedlertreck':
            $anzahl = anzahl_bereiche($db, $sid);
            $erlaubt = max_bereiche($fo);
            if ($anzahl < $erlaubt) {
                $h[] = ['text' => "Platz für einen weiteren Bereich: du hast {$anzahl} von {$erlaubt} erlaubten (inklusive Konvois unterwegs).", 'ok' => true];
            } else {
                $stufe = verwaltung_fuer_bereiche($anzahl + 1);
                $braucht['verwaltung'] = $stufe;
                $h[] = ['text' => "Zum Besiedeln brauchst du Verwaltung Stufe {$stufe} – sonst kehrt der Konvoi am Ziel um. Du hast {$anzahl} von {$erlaubt} erlaubten Bereichen.", 'ok' => false];
            }
            // Nächster freier Bereich und Proviant für die Fahrt
            $frei = $db->query("SELECT id, x, y FROM bereiche WHERE besitzer_id IS NULL AND typ <> 'Kern'")->fetchAll();
            if (!$frei) {
                $h[] = ['text' => 'Zurzeit gibt es keinen freien Bereich mehr.', 'ok' => false];
            } else {
                $km = min(array_map(fn($x) => strecke_km($b, $x), $frei));
                $proviant = (int)ceil(EINHEITEN['siedlertreck']['korn_km'] * $km);
                $dauer = reisezeit($km, einheit_kmh('siedlertreck', $fo), tempo());
                $h[] = ['text' => count($frei) . ' freie Bereiche; der nächste ist ' . str_replace('.', ',', (string)round($km, 1)) . ' km entfernt: '
                    . zahl($proviant) . ' ' . RESSOURCEN['korn'] . ' Proviant, Fahrt ' . dauer($dauer) . '.', 'ok' => (float)$b['korn'] >= $proviant];
            }
            $h[] = ['text' => 'Der Konvoi ist langsam und eine lohnende Beute. Baue ihn erst, wenn alles andere bereit ist – oder lagere ihn bis zur Abfahrt im Bunker ein (' . BUNKER_PLATZ['siedlertreck'] . ' Plätze).', 'ok' => null];
            break;
        case 'trecker':
            $h[] = ['text' => 'Verbraucht ' . EINHEITEN['trecker']['korn_km'] . ' ' . RESSOURCEN['korn'] . ' pro km, bei Transporten für Hin- und Rückweg.', 'ok' => null];
            break;
        case 'fahrradkurier':
            $h[] = ['text' => 'Erkundet fremde Bereiche. Jede Erkundung eines Spielers kostet ' . zahl(spionage_kosten((int)($fo['dorftratsch'] ?? 0)))
                . ' Euro Einsatzgeld; denselben Spieler nur alle ' . dauer((int)round(SPIONAGE_SPERRE_H * 3600 / tempo())) . '.', 'ok' => null];
            $h[] = ['text' => 'Berichte sind Schätzungen. Genauer wird es mit ' . FORSCHUNG['dorftratsch']['name'] . ' (du hast Stufe ' . (int)($fo['dorftratsch'] ?? 0)
                . ') und mehr Kurieren; die ' . FORSCHUNG['abschirmung']['name'] . ' des Gegners fängt Kuriere ab.', 'ok' => null];
            break;
        case 'schuetze':
        case 'reiter':
            if ($bis = schutz_bis($db, $sid)) {
                $h[] = ['text' => 'Raubzüge erst nach dem Anfängerschutz (bis ' . date('d.m. H:i', $bis) . ' Uhr). Verteidigen geht sofort.', 'ok' => false];
            } else {
                $h[] = ['text' => 'Für Raubzüge gegen andere Spieler und zur Verteidigung. Gegen Mitglieder der eigenen Allianz gibt es keine Raubzüge.', 'ok' => true];
            }
            $h[] = ['text' => 'Nach einem Überfall bist du für dieses Opfer eine Zeit lang gesperrt; mehr als ' . round(VERLUST_MAX_ANGRIFF * 100) . ' % je Angriff verliert niemand. '
                . 'Gegen deutlich Schwächere gibt es weniger oder keine Beute, und ein Teil der Vorräte liegt immer im Tresor.', 'ok' => null];
            break;
        case 'wallhecke':
        case 'hochsitz':
            $h[] = ['text' => 'Bleibt im Bereich und verteidigt ihn. Zerstörtes wird zu ' . round(WIEDERAUFBAU_ANTEIL * 100) . ' % wieder aufgebaut.', 'ok' => null];
            break;
    }
    return ['braucht' => $braucht, 'hinweise' => $h];
}

/** Sekunden, bis die Kosten mit der aktuellen Produktion bezahlbar sind; 0 = sofort, null = nie (Lager zu klein, keine Produktion). */
function zeit_bis_bezahlbar(array $b, array $rate, array $kosten): ?int
{
    $max = 0.0;
    foreach ($kosten as $r => $k) {
        $fehlt = $k - (float)$b[$r];
        if ($fehlt <= 0) {
            continue;
        }
        if ($r !== 'taler' && $k > $rate['lager']) {
            return null;
        }
        if ($rate[$r] <= 0) {
            return null;
        }
        $max = max($max, $fehlt / $rate[$r] * 3600);
    }
    return (int)ceil($max);
}

function kosten_summe(array ...$listen): array
{
    $s = array_fill_keys(array_keys(RESSOURCEN), 0);
    foreach ($listen as $l) {
        foreach ($l as $r => $k) {
            $s[$r] = ($s[$r] ?? 0) + $k;
        }
    }
    return $s;
}

function kosten_text(array $k): string
{
    $t = [];
    foreach (RESSOURCEN as $r => $n) {
        if (!empty($k[$r])) {
            $t[] = zahl($k[$r]) . ' ' . $n;
        }
    }
    return $t ? implode(' · ', $t) : 'kostenlos';
}

/**
 * Der aufklappbare Hinweis „Was brauche ich?“ für Gebäude, Forschung und Einheiten.
 * $art: 'gebaeude', 'forschung' oder 'einheit'; $eigene = Kosten des Objekts selbst (für die Gesamtrechnung).
 */
function bedarf_html(string $art, string $typ, array $z, int $sid, array $eigene): string
{
    $braucht = match ($art) {
        'gebaeude' => GEBAEUDE[$typ]['braucht'],
        'forschung' => FORSCHUNG[$typ]['braucht'],
        default => EINHEITEN[$typ]['braucht'],
    };
    $einsatz = $art === 'einheit' ? einsatz_bedarf($typ, $z, $sid) : ['braucht' => [], 'hinweise' => []];
    $schritte = fehlende_schritte($braucht, $z['stufen'], $z['fo']);
    $bauSchritte = array_column($schritte, 'typ');
    $nachher = $z['stufen'];
    $foNachher = $z['fo'];
    foreach ($schritte as $s) {
        $s['art'] === 'gebaeude' ? $nachher[$s['typ']] = $s['bis'] : $foNachher[$s['typ']] = $s['bis'];
    }
    // Was danach noch für den Einsatz fehlt (z. B. Verwaltung für den Siedlerkonvoi)
    $einsatzSchritte = array_values(array_filter(fehlende_schritte($einsatz['braucht'], $nachher, $foNachher), fn($s) => !in_array($s['typ'], $bauSchritte, true)));
    $alle = array_merge($schritte, $einsatzSchritte);
    $gesamt = kosten_summe($eigene, ...array_map(fn($s) => $s['kosten'], $alle));
    $warten = zeit_bis_bezahlbar($z['bereich'], $z['rate'], $gesamt);

    $zeile = function (array $s): string {
        $wo = $s['art'] === 'gebaeude' ? 'Gebäude' : 'Forschung';
        return '<li class="fehlt"><span class="bedarf-name">' . h($s['name']) . ' ' . ($s['von'] ? "{$s['von']} → " : '') . 'Stufe ' . $s['bis']
            . ' <small>(' . $wo . ')</small></span><span class="bedarf-kosten">' . h(kosten_text($s['kosten'])) . '</span></li>';
    };
    $erfuellt = [];
    foreach (array_merge($braucht, $einsatz['braucht']) as $t => $min) {
        $e = plan_eintrag($t);
        $ist = (int)($e['art'] === 'gebaeude' ? ($z['stufen'][$t] ?? 0) : ($z['fo'][$t] ?? 0));
        if ($e && $ist >= $min) {
            $erfuellt[] = '<li class="ok"><span class="bedarf-name">' . h($e['name']) . ' Stufe ' . $min . '</span><span class="bedarf-kosten">vorhanden</span></li>';
        }
    }
    $offen = count($alle) + count(array_filter($einsatz['hinweise'], fn($x) => $x['ok'] === false && !str_contains($x['text'], 'Verwaltung')));
    $html = '<details class="bedarf"' . ($offen ? '' : '') . '><summary>' . ($offen
            ? '<span class="bedarf-status fehlt">' . count($alle) . ' Schritt' . (count($alle) === 1 ? '' : 'e') . ' fehlen</span> Was brauche ich?'
            : '<span class="bedarf-status ok">✓ alles bereit</span> Was brauche ich?') . '</summary>';
    if ($schritte || $erfuellt) {
        $html .= '<p class="bedarf-titel">Zum Bauen</p><ol class="bedarf-liste">' . implode('', $erfuellt) . implode('', array_map($zeile, $schritte)) . '</ol>';
    }
    if ($einsatzSchritte || $einsatz['hinweise']) {
        $html .= '<p class="bedarf-titel">Zum Einsetzen</p><ol class="bedarf-liste">' . implode('', array_map($zeile, $einsatzSchritte));
        foreach ($einsatz['hinweise'] as $x) {
            $html .= '<li class="' . ($x['ok'] === true ? 'ok' : ($x['ok'] === false ? 'fehlt' : 'info')) . '"><span class="bedarf-name">' . h($x['text']) . '</span></li>';
        }
        $html .= '</ol>';
    }
    if ($alle) {
        $html .= '<p class="bedarf-summe"><b>Insgesamt bis einsatzbereit:</b> ' . h(kosten_text($gesamt))
            . '<br>' . ($warten === null ? 'Mit der jetzigen Produktion nicht erreichbar – Lagerhalle oder Produktion ausbauen.'
                : ($warten === 0 ? 'Die Rohstoffe dafür hast du schon (Bauzeiten kommen dazu).' : 'Rohstoffe zusammen in etwa ' . dauer($warten) . ' (bei der jetzigen Produktion, ohne Bauzeiten).')) . '</p>';
    } elseif (!$offen) {
        $html .= '<p class="leise">Alle Voraussetzungen sind erfüllt.</p>';
    }
    return $html . '</details>';
}
