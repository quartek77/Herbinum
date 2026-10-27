<?php
// Wirtschaft: Ressourcen werden erst beim Aufruf nachgerechnet (wie OGame).
// Fertige Bauaufträge werden dabei in der richtigen Reihenfolge abgeschlossen,
// sodass die Produktion zwischen zwei Fertigstellungen mit den damaligen Stufen läuft.
declare(strict_types=1);

const LAGER_RES = ['holz', 'ziegel', 'korn'];

function stufen_laden(PDO $db, string $bid): array
{
    $st = $db->prepare('SELECT typ, stufe FROM gebaeude WHERE bereich_id = ?');
    $st->execute([$bid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

function warteschlange_laden(PDO $db, string $bid): array
{
    $st = $db->prepare('SELECT * FROM bauauftraege WHERE bereich_id = ? ORDER BY ende_ts, id');
    $st->execute([$bid]);
    return $st->fetchAll();
}

function produzieren(array &$res, array $rate, int $sekunden): void
{
    if ($sekunden <= 0) {
        return;
    }
    $h = $sekunden / 3600;
    foreach (LAGER_RES as $r) {
        $neu = $res[$r] + $rate[$r] * $h;
        if ($rate[$r] >= 0) {
            // über der Kapazität wächst nichts mehr, vorhandener Überschuss bleibt aber
            $res[$r] = $res[$r] >= $rate['lager'] ? $res[$r] : min($rate['lager'], $neu);
        } else {
            $res[$r] = max(0.0, $neu);
        }
    }
    $res['taler'] += $rate['taler'] * $h;
}

/**
 * Bringt einen Bereich auf den Stand $jetzt. Muss innerhalb einer Transaktion laufen;
 * sperrt die Bereichszeile, damit parallele Aufrufe nichts doppelt gutschreiben.
 * Gibt den aktuellen Zustand zurück.
 */
function bereich_aktualisieren(PDO $db, string $bid, int $jetzt): array
{
    $st = $db->prepare('SELECT * FROM bereiche WHERE id = ? FOR UPDATE');
    $st->execute([$bid]);
    $b = $st->fetch();
    if (!$b) {
        throw new RuntimeException('Bereich nicht gefunden');
    }
    $lv = stufen_laden($db, $bid);
    $fo = $b['besitzer_id'] ? forschung_laden($db, (int)$b['besitzer_id']) : [];
    $res = ['holz' => (float)$b['holz'], 'ziegel' => (float)$b['ziegel'], 'korn' => (float)$b['korn'], 'taler' => (float)$b['taler']];
    $t = (int)$b['stand_ts'];
    // Ereignisse aus der Vergangenheit (z. B. eine Lieferung, die erst jetzt verarbeitet wird) dürfen den Stand nicht zurückdrehen
    $jetzt = max($jetzt, $t);
    $queue = [];

    foreach (warteschlange_laden($db, $bid) as $q) {
        if ((int)$q['ende_ts'] > $jetzt) {
            $queue[] = $q;
            continue;
        }
        produzieren_zeitraum($res, $b, $lv, $fo, $t, (int)$q['ende_ts']);
        $t = max($t, (int)$q['ende_ts']);
        $lv[$q['typ']] = (int)$q['ziel_stufe'];
        $db->prepare('INSERT INTO gebaeude (bereich_id, typ, stufe) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stufe = VALUES(stufe)')
           ->execute([$bid, $q['typ'], $q['ziel_stufe']]);
        $db->prepare('DELETE FROM bauauftraege WHERE id = ?')->execute([$q['id']]);
    }
    produzieren_zeitraum($res, $b, $lv, $fo, $t, $jetzt);

    $db->prepare('UPDATE bereiche SET holz = ?, ziegel = ?, korn = ?, taler = ?, stand_ts = ? WHERE id = ?')
       ->execute([$res['holz'], $res['ziegel'], $res['korn'], $res['taler'], $jetzt, $bid]);

    foreach ($res as $k => $v) {
        $b[$k] = $v;
    }
    $b['stand_ts'] = $jetzt;
    $werkstatt = werkstatt_abarbeiten($db, $bid, $jetzt);
    $auslagerung = bunker_abarbeiten($db, $bid, $jetzt);
    return ['bereich' => $b, 'stufen' => $lv, 'queue' => $queue, 'rate' => produktion($b, $lv, tempo(), $fo, ereignis_mods($b, $jetzt)),
            'fo' => $fo, 'einheiten' => einheiten_laden($db, $bid), 'werkstatt' => $werkstatt,
            'bunker' => bunker_laden($db, $bid), 'auslagerung' => $auslagerung];
}

/**
 * Produktion von $von bis $bis. Beginnt oder endet dazwischen ein Ereignis (Frost, Sturm …),
 * wird der Zeitraum dort geteilt, damit jeder Abschnitt mit den richtigen Faktoren läuft.
 */
function produzieren_zeitraum(array &$res, array $b, array $lv, array $fo, int $von, int $bis): void
{
    if ($bis <= $von) {
        return;
    }
    $grenzen = [$von, $bis];
    foreach (alle_ereignisse() as $e) {
        foreach ([(int)$e['start_ts'], (int)$e['ende_ts']] as $g) {
            if ($g > $von && $g < $bis) {
                $grenzen[] = $g;
            }
        }
    }
    sort($grenzen);
    for ($i = 1, $n = count($grenzen); $i < $n; $i++) {
        [$a, $e] = [$grenzen[$i - 1], $grenzen[$i]];
        if ($e > $a) {
            produzieren($res, produktion($b, $lv, tempo(), $fo, ereignis_mods($b, intdiv($a + $e, 2))), $e - $a);
        }
    }
}

function bunker_laden(PDO $db, string $bid): array
{
    $st = $db->prepare('SELECT typ, anzahl FROM bunker WHERE bereich_id = ? AND anzahl > 0');
    $st->execute([$bid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Fertig ausgelagerte Einheiten kommen zurück in den Bereich. Gibt die noch laufenden Auslagerungen zurück. */
function bunker_abarbeiten(PDO $db, string $bid, int $jetzt): array
{
    $st = $db->prepare('SELECT * FROM bunker_auslagerung WHERE bereich_id = ? ORDER BY fertig_ts, id');
    $st->execute([$bid]);
    $offen = [];
    foreach ($st->fetchAll() as $a) {
        if ((int)$a['fertig_ts'] > $jetzt) {
            $offen[] = $a;
            continue;
        }
        foreach (json_decode($a['einheiten'], true) ?: [] as $typ => $n) {
            einheiten_aendern($db, $bid, $typ, (int)$n);
        }
        $db->prepare('DELETE FROM bunker_auslagerung WHERE id = ?')->execute([$a['id']]);
    }
    return $offen;
}

function forschung_laden(PDO $db, int $sid): array
{
    $st = $db->prepare('SELECT typ, stufe FROM forschung WHERE spieler_id = ?');
    $st->execute([$sid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

function einheiten_laden(PDO $db, string $bid): array
{
    $st = $db->prepare('SELECT typ, anzahl FROM einheiten WHERE bereich_id = ? AND anzahl > 0');
    $st->execute([$bid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

function einheiten_aendern(PDO $db, string $bid, string $typ, int $delta): void
{
    $db->prepare('INSERT INTO einheiten (bereich_id, typ, anzahl) VALUES (?, ?, GREATEST(0, ?)) ON DUPLICATE KEY UPDATE anzahl = GREATEST(0, CAST(anzahl AS SIGNED) + ?)')
       ->execute([$bid, $typ, $delta, $delta]);
}

/** Fuhrpark-Aufträge laufen nacheinander; fertige Stücke werden gutgeschrieben. Gibt offene Aufträge zurück. */
function werkstatt_abarbeiten(PDO $db, string $bid, int $jetzt): array
{
    $st = $db->prepare('SELECT * FROM werkstattauftraege WHERE bereich_id = ? ORDER BY id');
    $st->execute([$bid]);
    $offen = [];
    foreach ($st->fetchAll() as $w) {
        $fertig = (int)min($w['anzahl'], max(0, intdiv($jetzt - (int)$w['start_ts'], max(1, (int)$w['stueck_s']))));
        if ($fertig > (int)$w['fertig']) {
            einheiten_aendern($db, $bid, $w['typ'], $fertig - (int)$w['fertig']);
        }
        if ($fertig >= (int)$w['anzahl']) {
            $db->prepare('DELETE FROM werkstattauftraege WHERE id = ?')->execute([$w['id']]);
        } else {
            if ($fertig > (int)$w['fertig']) {
                $db->prepare('UPDATE werkstattauftraege SET fertig = ? WHERE id = ?')->execute([$fertig, $w['id']]);
            }
            $w['fertig'] = $fertig;
            $offen[] = $w;
        }
    }
    return $offen;
}

/** Aktuellen Zustand holen (eigene kurze Transaktion). */
function bereich_zustand(string $bid): array
{
    $db = db();
    $db->beginTransaction();
    try {
        $z = bereich_aktualisieren($db, $bid, time());
        $db->commit();
        return $z;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function bauland_belegt(array $stufen, array $queue): int
{
    return array_sum($stufen) + count($queue);
}

/** Welche Stufe würde als Nächstes für $typ gebaut (inkl. Warteschlange)? */
function naechste_stufe(string $typ, array $stufen, array $queue): int
{
    $n = (int)($stufen[$typ] ?? 0);
    foreach ($queue as $q) {
        if ($q['typ'] === $typ) {
            $n = max($n, (int)$q['ziel_stufe']);
        }
    }
    return $n + 1;
}

/** $braucht kann Gebäude (im Bereich) und Forschung (des Spielers) mischen. */
function fehlende_voraussetzungen(array $braucht, array $stufen, array $fo = []): array
{
    $fehlt = [];
    foreach ($braucht as $t => $min) {
        $ist = isset(GEBAEUDE[$t]) ? ($stufen[$t] ?? 0) : ($fo[$t] ?? 0);
        if ($ist < $min) {
            $fehlt[] = (GEBAEUDE[$t]['name'] ?? FORSCHUNG[$t]['name']) . " Stufe {$min}";
        }
    }
    return $fehlt;
}

/** Bauauftrag anlegen. Gibt null bei Erfolg zurück, sonst eine Fehlermeldung für den Spieler. */
function bau_starten(string $bid, int $spielerId, string $typ): ?string
{
    if (!isset(GEBAEUDE[$typ])) {
        return 'Dieses Gebäude gibt es nicht.';
    }
    $db = db();
    $db->beginTransaction();
    try {
        $jetzt = time();
        $z = bereich_aktualisieren($db, $bid, $jetzt);
        $b = $z['bereich'];
        $fehler = null;
        $stufe = naechste_stufe($typ, $z['stufen'], $z['queue']);
        $kosten = baukosten($typ, $stufe);
        if ((int)$b['besitzer_id'] !== $spielerId) {
            $fehler = 'Das ist nicht dein Bereich.';
        } elseif (count($z['queue']) >= (int)(config()['spiel']['max_warteschlange'] ?? 3)) {
            $fehler = 'Die Bauwarteschlange ist voll.';
        } elseif (bauland_belegt($z['stufen'], $z['queue']) >= $b['bauplaetze'] * BAULAND_JE_BAUPLATZ) {
            $fehler = 'Kein Bauland mehr frei.';
        } elseif ($f = fehlende_voraussetzungen(GEBAEUDE[$typ]['braucht'], $z['stufen'], $z['fo'])) {
            $fehler = 'Dafür brauchst du zuerst: ' . implode(', ', $f) . '.';
        } else {
            foreach ($kosten as $r => $k) {
                if ($b[$r] < $k) {
                    $fehler = 'Nicht genug ' . RESSOURCEN[$r] . '.';
                    break;
                }
            }
        }
        if ($fehler) {
            $db->commit();   // die Nachberechnung bleibt gespeichert
            return $fehler;
        }
        // Bauzeit hängt von der Zentrale ab, die zum Baubeginn steht
        $start = $jetzt;
        $hof = (int)($z['stufen']['hofstelle'] ?? 0);
        foreach ($z['queue'] as $q) {
            $start = max($start, (int)$q['ende_ts']);
            if ($q['typ'] === 'hofstelle') {
                $hof = max($hof, (int)$q['ziel_stufe']);
            }
        }
        $ende = $start + bauzeit($kosten, $hof, tempo());
        $db->prepare('UPDATE bereiche SET holz = holz - ?, ziegel = ziegel - ?, korn = korn - ?, taler = taler - ? WHERE id = ?')
           ->execute([$kosten['holz'], $kosten['ziegel'], $kosten['korn'], $kosten['taler'], $bid]);
        $db->prepare('INSERT INTO bauauftraege (bereich_id, typ, ziel_stufe, start_ts, ende_ts, holz, ziegel, korn, taler) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$bid, $typ, $stufe, $start, $ende, $kosten['holz'], $kosten['ziegel'], $kosten['korn'], $kosten['taler']]);
        $db->commit();
        return null;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Nur der letzte Auftrag der Warteschlange kann abgebrochen werden; die Kosten kommen voll zurück. */
function bau_abbrechen(string $bid, int $spielerId, int $auftragId): ?string
{
    $db = db();
    $db->beginTransaction();
    try {
        $z = bereich_aktualisieren($db, $bid, time());
        $letzter = end($z['queue']);
        if ((int)$z['bereich']['besitzer_id'] !== $spielerId) {
            $fehler = 'Das ist nicht dein Bereich.';
        } elseif (!$letzter || (int)$letzter['id'] !== $auftragId) {
            $fehler = 'Nur der letzte Auftrag in der Warteschlange kann abgebrochen werden.';
        } else {
            $db->prepare('UPDATE bereiche SET holz = holz + ?, ziegel = ziegel + ?, korn = korn + ?, taler = taler + ? WHERE id = ?')
               ->execute([$letzter['holz'], $letzter['ziegel'], $letzter['korn'], $letzter['taler'], $bid]);
            $db->prepare('DELETE FROM bauauftraege WHERE id = ?')->execute([$auftragId]);
            $fehler = null;
        }
        $db->commit();
        return $fehler;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
