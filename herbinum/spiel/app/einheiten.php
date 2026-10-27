<?php
// Forschung, Werkstatt, Züge (Transport, Verlegen, Erkunden, Besiedeln) und deren Ereignisse.
// Wie die Rohstoffe werden auch Ankünfte erst beim nächsten Seitenaufruf verarbeitet – von irgendeinem Spieler.
declare(strict_types=1);

function in_transaktion(callable $fn): mixed
{
    $db = db();
    $db->beginTransaction();
    try {
        $r = $fn($db);
        $db->commit();
        return $r;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function nachricht(PDO $db, int $sid, int $ts, string $art, string $titel, array $inhalt): void
{
    $db->prepare('INSERT INTO nachrichten (spieler_id, ts, art, titel, inhalt) VALUES (?, ?, ?, ?, ?)')
       ->execute([$sid, $ts, $art, mb_substr($titel, 0, 160), json_encode($inhalt, JSON_UNESCAPED_UNICODE)]);
}

function bereich_name(PDO $db, string $bid): string
{
    $st = $db->prepare("SELECT CONCAT(o.name, ' · ', b.name) FROM bereiche b JOIN orte o ON o.id = b.ort_id WHERE b.id = ?");
    $st->execute([$bid]);
    return (string)$st->fetchColumn();
}

function kosten_pruefen(array $b, array $kosten): ?string
{
    foreach ($kosten as $r => $k) {
        if ($b[$r] < $k) {
            return 'Nicht genug ' . RESSOURCEN[$r] . '.';
        }
    }
    return null;
}

function kosten_abziehen(PDO $db, string $bid, array $k, int $vorzeichen = -1): void
{
    $db->prepare('UPDATE bereiche SET holz = holz + ?, ziegel = ziegel + ?, korn = korn + ?, taler = taler + ? WHERE id = ?')
       ->execute([$vorzeichen * ($k['holz'] ?? 0), $vorzeichen * ($k['ziegel'] ?? 0), $vorzeichen * ($k['korn'] ?? 0), $vorzeichen * ($k['taler'] ?? 0), $bid]);
}

// =====================================================================
// Forschung
// =====================================================================

function forschungsauftrag(int $sid): ?array
{
    $st = db()->prepare('SELECT * FROM forschungsauftraege WHERE spieler_id = ?');
    $st->execute([$sid]);
    return $st->fetch() ?: null;
}

function forschung_starten(string $bid, int $sid, string $typ): ?string
{
    if (!isset(FORSCHUNG[$typ])) {
        return 'Diese Forschung gibt es nicht.';
    }
    return in_transaktion(function (PDO $db) use ($bid, $sid, $typ) {
        $z = bereich_aktualisieren($db, $bid, time());
        $b = $z['bereich'];
        if ((int)$b['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        $st = $db->prepare('SELECT spieler_id FROM forschungsauftraege WHERE spieler_id = ? FOR UPDATE');
        $st->execute([$sid]);
        if ($st->fetch()) {
            return 'In deiner Dorfschule wird schon geforscht – immer nur eine Forschung gleichzeitig.';
        }
        if ($f = fehlende_voraussetzungen(FORSCHUNG[$typ]['braucht'], $z['stufen'], $z['fo'])) {
            return 'Dafür brauchst du in diesem Bereich zuerst: ' . implode(', ', $f) . '.';
        }
        $stufe = (int)($z['fo'][$typ] ?? 0) + 1;
        $kosten = forschungskosten($typ, $stufe);
        if ($f = kosten_pruefen($b, $kosten)) {
            return $f;
        }
        $jetzt = time();
        kosten_abziehen($db, $bid, $kosten);
        $db->prepare('INSERT INTO forschungsauftraege (spieler_id, bereich_id, typ, ziel_stufe, start_ts, ende_ts, holz, ziegel, korn, taler) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$sid, $bid, $typ, $stufe, $jetzt, $jetzt + forschungszeit($kosten, (int)($z['stufen']['dorfschule'] ?? 0), tempo()),
                      $kosten['holz'], $kosten['ziegel'], $kosten['korn'], $kosten['taler']]);
        return null;
    });
}

function forschung_abbrechen(int $sid): ?string
{
    return in_transaktion(function (PDO $db) use ($sid) {
        $st = $db->prepare('SELECT * FROM forschungsauftraege WHERE spieler_id = ? FOR UPDATE');
        $st->execute([$sid]);
        $a = $st->fetch();
        if (!$a || (int)$a['ende_ts'] <= time()) {
            return 'Es läuft keine Forschung, die sich abbrechen ließe.';
        }
        bereich_aktualisieren($db, $a['bereich_id'], time());
        kosten_abziehen($db, $a['bereich_id'], $a, +1);
        $db->prepare('DELETE FROM forschungsauftraege WHERE spieler_id = ?')->execute([$sid]);
        return null;
    });
}

// =====================================================================
// Werkstatt
// =====================================================================

const WERKSTATT_MAX_AUFTRAEGE = 5;

function einheiten_bauen(string $bid, int $sid, string $typ, int $anzahl): ?string
{
    if (!isset(EINHEITEN[$typ])) {
        return 'Diese Einheit gibt es nicht.';
    }
    if ($anzahl < 1 || $anzahl > 999) {
        return 'Bitte eine Anzahl zwischen 1 und 999 angeben.';
    }
    return in_transaktion(function (PDO $db) use ($bid, $sid, $typ, $anzahl) {
        $z = bereich_aktualisieren($db, $bid, time());
        $b = $z['bereich'];
        if ((int)$b['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        if ($f = fehlende_voraussetzungen(EINHEITEN[$typ]['braucht'], $z['stufen'], $z['fo'])) {
            return 'Dafür brauchst du zuerst: ' . implode(', ', $f) . '.';
        }
        if (count($z['werkstatt']) >= WERKSTATT_MAX_AUFTRAEGE) {
            return 'Werkstatt und Schützenhalle haben zusammen schon ' . WERKSTATT_MAX_AUFTRAEGE . ' Aufträge.';
        }
        $kosten = [];
        foreach (RESSOURCEN as $r => $_) {
            $kosten[$r] = (EINHEITEN[$typ]['kosten'][$r] ?? 0) * $anzahl;
        }
        if ($f = kosten_pruefen($b, $kosten)) {
            return $f;
        }
        $start = time();
        foreach ($z['werkstatt'] as $w) {
            $start = max($start, (int)$w['start_ts'] + (int)$w['anzahl'] * (int)$w['stueck_s']);
        }
        kosten_abziehen($db, $bid, $kosten);
        $db->prepare('INSERT INTO werkstattauftraege (bereich_id, typ, anzahl, start_ts, stueck_s) VALUES (?,?,?,?,?)')
           ->execute([$bid, $typ, $anzahl, $start, einheit_bauzeit($typ, (int)($z['stufen'][EINHEITEN[$typ]['halle']] ?? 0), tempo())]);
        return null;
    });
}

// =====================================================================
// Züge
// =====================================================================

function bereich_zeile(PDO $db, string $bid): ?array
{
    $st = $db->prepare('SELECT b.*, o.name AS ort FROM bereiche b JOIN orte o ON o.id = b.ort_id WHERE b.id = ?');
    $st->execute([$bid]);
    return $st->fetch() ?: null;
}

function anzahl_bereiche(PDO $db, int $sid): int
{
    $st = $db->prepare("SELECT (SELECT COUNT(*) FROM bereiche WHERE besitzer_id = ?) + (SELECT COUNT(*) FROM zuege WHERE spieler_id = ? AND auftrag = 'kolonisieren' AND status = 'hin')");
    $st->execute([$sid, $sid]);
    return (int)$st->fetchColumn();
}

/**
 * Schickt einen Zug los. $einheiten: ['typ' => anzahl], $ladung: ['holz' => …]
 * Gibt null bei Erfolg zurück, sonst die Fehlermeldung.
 */
function zug_starten(string $startId, int $sid, string $zielId, string $auftrag, array $einheiten, array $ladung, bool $nurEigeneZiele = false): ?string
{
    if (!isset(AUFTRAEGE[$auftrag])) {
        return 'Unbekannter Auftrag.';
    }
    $einheiten = bewegliche(array_filter(array_map('intval', array_intersect_key($einheiten, EINHEITEN)), fn($n) => $n > 0));
    $ladung = array_map(fn($v) => max(0, (int)$v), array_intersect_key($ladung, RESSOURCEN)) + array_fill_keys(array_keys(RESSOURCEN), 0);
    if (!$einheiten) {
        return 'Wähle mindestens eine Einheit aus.';
    }
    if ($startId === $zielId) {
        return 'Start und Ziel sind derselbe Bereich.';
    }
    if ($nurEigeneZiele && !in_array($auftrag, ['transport', 'verlegen'], true)) {
        return 'In Vertretung sind nur Transporte und Verlegungen erlaubt.';
    }
    return in_transaktion(function (PDO $db) use ($startId, $sid, $zielId, $auftrag, $einheiten, $ladung, $nurEigeneZiele) {
        $jetzt = time();
        $z = bereich_aktualisieren($db, $startId, $jetzt);
        $b = $z['bereich'];
        $ziel = bereich_zeile($db, $zielId);
        if ((int)$b['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        if (!$ziel) {
            return 'Diesen Zielbereich gibt es nicht.';
        }
        foreach ($einheiten as $typ => $n) {
            if (($z['einheiten'][$typ] ?? 0) < $n) {
                return 'Hier stehen nicht genug ' . EINHEITEN[$typ]['name'] . ' bereit.';
            }
        }
        $zielBesitzer = $ziel['besitzer_id'] === null ? null : (int)$ziel['besitzer_id'];
        if ($nurEigeneZiele && $zielBesitzer !== $sid) {
            return 'In Vertretung gehen Züge nur zwischen den eigenen Bereichen des Vertretenen.';
        }
        switch ($auftrag) {
            case 'transport':
                if ($zielBesitzer === null) {
                    return 'Transporte gehen nur zu besiedelten Bereichen.';
                }
                break;
            case 'verlegen':
                if ($zielBesitzer !== $sid) {
                    return 'Verlegen geht nur in eigene Bereiche.';
                }
                break;
            case 'erkunden':
                if (empty($einheiten['fahrradkurier'])) {
                    return 'Zum Erkunden brauchst du mindestens einen Fahrradkurier.';
                }
                if ($zielBesitzer === $sid) {
                    return 'Deinen eigenen Bereich kennst du schon.';
                }
                if (array_sum($ladung) > 0) {
                    return 'Kuriere auf Erkundung nehmen keine Ladung mit.';
                }
                break;
            case 'angriff':
                if ($zielBesitzer === null || $zielBesitzer === $sid) {
                    return 'Raubzüge gehen nur gegen besiedelte Bereiche anderer Spieler.';
                }
                if (!array_filter($einheiten, fn($n, $t) => EINHEITEN[$t]['angriff'] > 0, ARRAY_FILTER_USE_BOTH)) {
                    return 'Für einen Raubzug brauchst du Schützen oder Reiter.';
                }
                if (array_sum($ladung) > 0) {
                    return 'Auf dem Hinweg wird nichts geladen – Platz für die Beute.';
                }
                if ($bis = schutz_bis($db, $sid)) {
                    return 'Solange dein Anfängerschutz läuft (bis ' . date('d.m. H:i', $bis) . ' Uhr), kannst du nicht angreifen.';
                }
                if ($bis = schutz_bis($db, $zielBesitzer)) {
                    return 'Dieser Spieler steht noch bis ' . date('d.m. H:i', $bis) . ' Uhr unter Anfängerschutz.';
                }
                if (gleiche_genossenschaft($db, $sid, $zielBesitzer)) {
                    return 'Ihr seid in derselben Allianz – untereinander gibt es keine Raubzüge.';
                }
                break;
            case 'kolonisieren':
                if (empty($einheiten['siedlertreck'])) {
                    return 'Zum Besiedeln brauchst du einen Siedlertreck.';
                }
                if ($zielBesitzer !== null) {
                    return 'Dieser Bereich ist schon besiedelt.';
                }
                if (anzahl_bereiche($db, $sid) >= max_bereiche($z['fo'])) {
                    return 'Mehr Bereiche erlaubt deine Verwaltung nicht. Erforsche Verwaltung weiter.';
                }
                break;
        }
        if (array_sum($ladung) > zug_ladung($einheiten)) {
            return 'So viel passt nicht auf den Zug (höchstens ' . zahl(zug_ladung($einheiten)) . ').';
        }
        $km = strecke_km($b, $ziel);
        $verbrauch = zug_verbrauch($einheiten, $km, $auftrag);
        $bedarf = $ladung;
        $bedarf['korn'] += $verbrauch;
        if ($f = kosten_pruefen($b, $bedarf)) {
            return $f . ($verbrauch ? ' (Die Fahrt kostet ' . zahl($verbrauch) . ' Korn.)' : '');
        }
        // Bei Sturm, Hochwasser und Co. am Start dauert die Fahrt länger
        $dauer = (int)round(reisezeit($km, zug_tempo($einheiten, $z['fo']), tempo()) * ereignis_zugfaktor($b, $jetzt));
        kosten_abziehen($db, $startId, $bedarf);
        foreach ($einheiten as $typ => $n) {
            einheiten_aendern($db, $startId, $typ, -$n);
        }
        $db->prepare("INSERT INTO zuege (spieler_id, start_id, ziel_id, auftrag, status, abflug_ts, ankunft_ts, rueckkehr_ts, einheiten, holz, ziegel, korn, taler)
                      VALUES (?, ?, ?, ?, 'hin', ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$sid, $startId, $zielId, $auftrag, $jetzt, $jetzt + $dauer, $jetzt + 2 * $dauer, json_encode($einheiten),
                      $ladung['holz'], $ladung['ziegel'], $ladung['korn'], $ladung['taler']]);
        return null;
    });
}

function zug_zurueckrufen(int $sid, int $zugId): ?string
{
    return in_transaktion(function (PDO $db) use ($sid, $zugId) {
        $st = $db->prepare('SELECT * FROM zuege WHERE id = ? FOR UPDATE');
        $st->execute([$zugId]);
        $zug = $st->fetch();
        $jetzt = time();
        if (!$zug || (int)$zug['spieler_id'] !== $sid || $zug['status'] !== 'hin' || (int)$zug['ankunft_ts'] <= $jetzt) {
            return 'Dieser Zug kann nicht mehr umkehren.';
        }
        $db->prepare("UPDATE zuege SET status = 'zurueck', ankunft_ts = ?, rueckkehr_ts = ? WHERE id = ?")
           ->execute([$jetzt, $jetzt + ($jetzt - (int)$zug['abflug_ts']), $zugId]);
        return null;
    });
}

function zuege_des_spielers(int $sid): array
{
    $st = db()->prepare("SELECT * FROM zuege WHERE spieler_id = ? ORDER BY IF(status = 'hin', ankunft_ts, rueckkehr_ts)");
    $st->execute([$sid]);
    return $st->fetchAll();
}

/** Züge anderer Spieler, die gerade auf einen eigenen Bereich zufahren. */
function ankommende_zuege(int $sid): array
{
    $st = db()->prepare("SELECT z.*, s.name AS absender FROM zuege z JOIN bereiche b ON b.id = z.ziel_id JOIN spieler s ON s.id = z.spieler_id
                         WHERE b.besitzer_id = ? AND z.spieler_id <> ? AND z.status = 'hin' ORDER BY z.ankunft_ts");
    $st->execute([$sid, $sid]);
    return $st->fetchAll();
}

// =====================================================================
// Ereignisse
// =====================================================================

/** Arbeitet fällige Forschungen und Zug-Ereignisse in zeitlicher Reihenfolge ab. Läuft bei jedem Seitenaufruf. */
function ereignisse_abarbeiten(int $jetzt): void
{
    $db = db();
    foreach ($db->query("SELECT spieler_id FROM forschungsauftraege WHERE ende_ts <= {$jetzt}")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        in_transaktion(function (PDO $db) use ($sid, $jetzt) {
            $st = $db->prepare('SELECT * FROM forschungsauftraege WHERE spieler_id = ? AND ende_ts <= ? FOR UPDATE');
            $st->execute([$sid, $jetzt]);
            if (!$a = $st->fetch()) {
                return;     // hat ein paralleler Aufruf schon erledigt
            }
            $db->prepare('INSERT INTO forschung (spieler_id, typ, stufe) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stufe = VALUES(stufe)')
               ->execute([$sid, $a['typ'], $a['ziel_stufe']]);
            $db->prepare('DELETE FROM forschungsauftraege WHERE spieler_id = ?')->execute([$sid]);
            nachricht($db, (int)$sid, (int)$a['ende_ts'], 'forschung', FORSCHUNG[$a['typ']]['name'] . ' Stufe ' . $a['ziel_stufe'] . ' erforscht', []);
        });
    }

    // Mehrere Runden: eine Ankunft kann eine ebenfalls schon fällige Rückkehr auslösen
    for ($runde = 0; $runde < 5; $runde++) {
        $faellig = $db->query("SELECT id FROM zuege WHERE (status = 'hin' AND ankunft_ts <= {$jetzt}) OR (status = 'zurueck' AND rueckkehr_ts <= {$jetzt})
                               ORDER BY IF(status = 'hin', ankunft_ts, rueckkehr_ts), id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        if (!$faellig) {
            break;
        }
        zuege_verarbeiten($faellig, $jetzt);
    }
    welt_aktualisieren($jetzt);
}

function zuege_verarbeiten(array $faellig, int $jetzt): void
{
    foreach ($faellig as $id) {
        in_transaktion(function (PDO $db) use ($id, $jetzt) {
            $st = $db->prepare('SELECT * FROM zuege WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $zug = $st->fetch();
            if (!$zug) {
                return;
            }
            if ($zug['status'] === 'hin' && (int)$zug['ankunft_ts'] <= $jetzt) {
                zug_ankunft($db, $zug);
            } elseif ($zug['status'] === 'zurueck' && (int)$zug['rueckkehr_ts'] <= $jetzt) {
                zug_rueckkehr($db, $zug);
            }
        });
    }
}

function zug_einheiten(array $zug): array
{
    return json_decode($zug['einheiten'], true) ?: [];
}

function zug_umkehren(PDO $db, array $zug, bool $ladungAbgegeben): void
{
    $sql = "UPDATE zuege SET status = 'zurueck'" . ($ladungAbgegeben ? ', holz = 0, ziegel = 0, korn = 0, taler = 0' : '') . ' WHERE id = ?';
    $db->prepare($sql)->execute([$zug['id']]);
}

function zug_ankunft(PDO $db, array $zug): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $zielName = bereich_name($db, $zug['ziel_id']);
    $ziel = bereich_aktualisieren($db, $zug['ziel_id'], $ts);
    $zb = $ziel['bereich'];
    $besitzer = $zb['besitzer_id'] === null ? null : (int)$zb['besitzer_id'];
    $ladung = ['holz' => (float)$zug['holz'], 'ziegel' => (float)$zug['ziegel'], 'korn' => (float)$zug['korn'], 'taler' => (float)$zug['taler']];
    $ladungText = ['ladung' => array_map('floor', $ladung), 'ziel' => $zielName];

    switch ($zug['auftrag']) {
        case 'transport':
            if ($besitzer === null) {
                nachricht($db, $sid, $ts, 'transport', "Lieferung nach {$zielName} kehrt um", ['text' => 'Der Bereich ist nicht mehr besiedelt.'] + $ladungText);
                zug_umkehren($db, $zug, false);
                return;
            }
            kosten_abziehen($db, $zug['ziel_id'], $ladung, +1);
            nachricht($db, $sid, $ts, 'transport', "Lieferung in {$zielName} angekommen", $ladungText);
            if ($besitzer !== $sid) {
                $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
                $st->execute([$sid]);
                nachricht($db, $besitzer, $ts, 'transport', $st->fetchColumn() . " hat dir Rohstoffe nach {$zielName} gebracht", $ladungText);
            }
            zug_umkehren($db, $zug, true);
            return;

        case 'verlegen':
            if ($besitzer !== $sid) {
                nachricht($db, $sid, $ts, 'transport', "Verlegung nach {$zielName} kehrt um", ['text' => 'Der Bereich gehört dir nicht mehr.'] + $ladungText);
                zug_umkehren($db, $zug, false);
                return;
            }
            kosten_abziehen($db, $zug['ziel_id'], $ladung, +1);
            foreach (zug_einheiten($zug) as $typ => $n) {
                einheiten_aendern($db, $zug['ziel_id'], $typ, $n);
            }
            $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
            nachricht($db, $sid, $ts, 'transport', "Verlegung in {$zielName} angekommen", ['einheiten' => zug_einheiten($zug)] + $ladungText);
            return;

        case 'erkunden':
            nachricht($db, $sid, $ts, 'bericht', "Erkundung: {$zielName}", erkundungsbericht($db, $zug, $ziel));
            if ($besitzer !== null && $besitzer !== $sid) {
                nachricht($db, $besitzer, $ts, 'warnung', "Ein fremder Fahrradkurier war in {$zielName}", ['text' => 'Jemand hat sich bei dir umgesehen.']);
                chronik('erkundung', ort_von_bereich($db, $zug['ziel_id']), '', $ts);
            }
            zug_umkehren($db, $zug, false);
            return;

        case 'angriff':
            if ($besitzer === null || $besitzer === $sid) {
                nachricht($db, $sid, $ts, 'kampf', "Raubzug nach {$zielName} kehrt um", ['text' => 'Dort gibt es niemanden mehr zu überfallen.']);
                zug_umkehren($db, $zug, false);
                return;
            }
            if (gleiche_genossenschaft($db, $sid, $besitzer)) {
                nachricht($db, $sid, $ts, 'kampf', "Raubzug nach {$zielName} kehrt um", ['text' => 'Ihr seid inzwischen in derselben Allianz.']);
                zug_umkehren($db, $zug, false);
                return;
            }
            raubzug_ausfuehren($db, $zug, $ziel, $zielName, $besitzer);
            return;

        case 'kolonisieren':
            $st = $db->prepare('SELECT COUNT(*) FROM bereiche WHERE besitzer_id = ?');
            $st->execute([$sid]);
            $fo = forschung_laden($db, $sid);
            if ($besitzer !== null || (int)$st->fetchColumn() >= max_bereiche($fo)) {
                $grund = $besitzer !== null ? 'Jemand war schneller – der Bereich ist schon besiedelt.' : 'Deine Verwaltung erlaubt keinen weiteren Bereich.';
                nachricht($db, $sid, $ts, 'kolonie', "Besiedlung von {$zielName} gescheitert", ['text' => $grund]);
                zug_umkehren($db, $zug, false);
                return;
            }
            $db->prepare('UPDATE bereiche SET besitzer_id = ?, ist_heimat = 0, holz = ?, ziegel = ?, korn = ?, taler = ?, stand_ts = ? WHERE id = ?')
               ->execute([$sid, $ladung['holz'], $ladung['ziegel'], $ladung['korn'], $ladung['taler'], $ts, $zug['ziel_id']]);
            $db->prepare("INSERT INTO gebaeude (bereich_id, typ, stufe) VALUES (?, 'hofstelle', 1) ON DUPLICATE KEY UPDATE stufe = GREATEST(stufe, 1)")
               ->execute([$zug['ziel_id']]);
            $rest = zug_einheiten($zug);
            $rest['siedlertreck']--;    // der Treck wird zur Hofstelle
            foreach ($rest as $typ => $n) {
                if ($n > 0) {
                    einheiten_aendern($db, $zug['ziel_id'], $typ, $n);
                }
            }
            $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
            nachricht($db, $sid, $ts, 'kolonie', "{$zielName} besiedelt!", ['text' => 'Deine Siedler haben eine Hofstelle gegründet. Wechsle oben rechts zum neuen Bereich.']);
            chronik('besiedlung', ort_von_bereich($db, $zug['ziel_id']), '', $ts);
            return;
    }
}

function zug_rueckkehr(PDO $db, array $zug): void
{
    $start = bereich_aktualisieren($db, $zug['start_id'], (int)$zug['rueckkehr_ts']);
    if ((int)$start['bereich']['besitzer_id'] !== (int)$zug['spieler_id']) {
        // Startbereich gehört nicht mehr dem Spieler – Zug geht verloren (später: Kämpfe)
        $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
        return;
    }
    kosten_abziehen($db, $zug['start_id'], ['holz' => (float)$zug['holz'], 'ziegel' => (float)$zug['ziegel'], 'korn' => (float)$zug['korn'], 'taler' => (float)$zug['taler']], +1);
    foreach (zug_einheiten($zug) as $typ => $n) {
        einheiten_aendern($db, $zug['start_id'], $typ, $n);
    }
    $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
}

/**
 * Was der Kurier sieht, hängt vom Dorftratsch-Vorsprung ab (plus einem Bonus für mehrere Kuriere):
 * immer Vorräte, ab 1 Einheiten, ab 2 Gebäude, ab 3 Forschung.
 */
function erkundungsbericht(PDO $db, array $zug, array $ziel): array
{
    $b = $ziel['bereich'];
    $eigeneFo = forschung_laden($db, (int)$zug['spieler_id']);
    $fremdeFo = $b['besitzer_id'] ? forschung_laden($db, (int)$b['besitzer_id']) : [];
    $kuriere = (int)(zug_einheiten($zug)['fahrradkurier'] ?? 1);
    $tiefe = ($eigeneFo['dorftratsch'] ?? 0) - ($fremdeFo['dorftratsch'] ?? 0) + (int)floor(log(max(1, $kuriere), 2));
    $besitzer = null;
    if ($b['besitzer_id']) {
        $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
        $st->execute([$b['besitzer_id']]);
        $besitzer = $st->fetchColumn();
    }
    $r = [
        'besitzer' => $besitzer,
        'werte' => ['Bauplätze' => (int)$b['bauplaetze'], 'Lebensqualität' => (int)$b['lebensqualitaet'], 'Wehrkraft' => (int)$b['wehr'], 'Einkommen' => (int)$b['einkommen']],
        'vorraete' => $besitzer ? ['holz' => floor($b['holz']), 'ziegel' => floor($b['ziegel']), 'korn' => floor($b['korn']), 'taler' => floor($b['taler'])] : null,
        'tiefe' => $tiefe,
    ];
    if ($besitzer && $tiefe >= 1) {
        $r['einheiten'] = $ziel['einheiten'];
    }
    if ($besitzer && $tiefe >= 2) {
        $r['gebaeude'] = $ziel['stufen'];
    }
    if ($besitzer && $tiefe >= 3) {
        $r['forschung'] = $fremdeFo;
    }
    return $r;
}

// =====================================================================
// Kampf
// =====================================================================

/** Anfängerschutz: Zeitpunkt, bis zu dem der Spieler geschützt ist, oder null. */
function schutz_bis(PDO $db, int $sid): ?int
{
    $st = $db->prepare('SELECT erstellt_ts FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    $bis = (int)$st->fetchColumn() + (int)(config()['spiel']['schutz_stunden'] ?? 72) * 3600;
    return $bis > time() ? $bis : null;
}

/** Multiplikatoren für Angriff und Leben einer Seite. */
function kampfwerte(array $fo, bool $verteidiger, int $graben = 0, int $wehr = 50, bool $nacht = false): array
{
    $angriff = 1 + FORSCHUNG['schiessausbildung']['bonus'] * (int)($fo['schiessausbildung'] ?? 0);
    $leben = 1 + FORSCHUNG['schutzausruestung']['bonus'] * (int)($fo['schutzausruestung'] ?? 0);
    if ($verteidiger) {
        // Graben und die echte Lage (Wehrkraft 0–100 → ×0,75 bis ×1,25) stärken die Verteidiger
        // nachts hält die Nachtwache zusätzlich die Augen offen
        $leben *= (1 + GRABEN_BONUS * $graben) * (0.75 + $wehr / 200) * ($nacht ? 1 + NACHT_VERTEIDIGUNG : 1);
    }
    return [$angriff, $leben];
}

/**
 * Reine Kampfberechnung ohne Datenbank. Beide Seiten feuern pro Runde gleichzeitig;
 * der Schaden verteilt sich nach Lebenspunkt-Anteilen auf die gegnerischen Einheitentypen.
 * $zufall liefert pro Seite und Runde einen Faktor (Standard 0,9–1,1).
 */
function kampf(array $a, array $v, array $aFo, array $vFo, int $graben, int $wehr, ?callable $zufall = null, bool $nacht = false): array
{
    $zufall ??= fn() => mt_rand(900, 1100) / 1000;
    [$aAtk, $aHp] = kampfwerte($aFo, false);
    [$vAtk, $vHp] = kampfwerte($vFo, true, $graben, $wehr, $nacht);
    $A = array_map('floatval', array_filter($a));
    $V = array_map('floatval', array_filter($v));
    $staerke = function (array $seite, float $f): float {
        $s = 0.0;
        foreach ($seite as $t => $n) {
            $s += $n * EINHEITEN[$t]['angriff'] * $f;
        }
        return $s;
    };
    $treffen = function (array $seite, float $hpF, float $schaden): array {
        $gesamt = 0.0;
        foreach ($seite as $t => $n) {
            $gesamt += $n * EINHEITEN[$t]['leben'] * $hpF;
        }
        if ($gesamt <= 0) {
            return $seite;
        }
        foreach ($seite as $t => $n) {
            $hp = EINHEITEN[$t]['leben'] * $hpF;
            $seite[$t] = max(0.0, $n - $schaden * ($n * $hp / $gesamt) / $hp);
        }
        return $seite;
    };
    $lebt = fn(array $s) => array_sum($s) >= 0.5;
    $runden = 0;
    while ($runden < KAMPF_RUNDEN && $lebt($A) && $lebt($V)) {
        $sa = $staerke($A, $aAtk) * $zufall();
        $sv = $staerke($V, $vAtk) * $zufall();
        if ($sa <= 0 && $sv <= 0) {
            break;
        }
        $runden++;
        [$V, $A] = [$treffen($V, $vHp, $sa), $treffen($A, $aHp, $sv)];
    }
    $rund = fn(array $s) => array_map(fn($n) => (int)round($n), $s);
    $aNach = $rund($A);
    $vNach = $rund($V);
    $sieger = array_sum($aNach) === 0 ? 'verteidiger' : (array_sum($vNach) === 0 ? 'angreifer' : 'unentschieden');
    $paar = fn(array $vor, array $nach) => array_map(fn($t) => [(int)$vor[$t], $nach[$t] ?? 0], array_combine(array_keys($vor), array_keys($vor)));
    return ['runden' => $runden, 'sieger' => $sieger, 'a' => $paar(array_filter($a), $aNach), 'v' => $paar(array_filter($v), $vNach)];
}

/** Beute: höchstens BEUTE_ANTEIL jedes Vorrats, gleichmäßig gekürzt, wenn die Ladefläche nicht reicht. */
function beute(array $vorrat, int $kapazitaet): array
{
    $will = [];
    foreach (RESSOURCEN as $r => $_) {
        $will[$r] = max(0.0, floor((float)$vorrat[$r] * BEUTE_ANTEIL));
    }
    $summe = array_sum($will);
    $f = $summe > $kapazitaet ? $kapazitaet / $summe : 1.0;
    return array_map(fn($w) => floor($w * $f), $will);
}

function raubzug_ausfuehren(PDO $db, array $zug, array $ziel, string $zielName, int $verteidiger): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $zb = $ziel['bereich'];
    $nacht = ist_nacht($ts);
    $ergebnis = kampf(zug_einheiten($zug), $ziel['einheiten'], forschung_laden($db, $sid), forschung_laden($db, $verteidiger),
                      (int)($ziel['stufen']['graben'] ?? 0), (int)$zb['wehr'], null, $nacht);

    // Verteidiger: Verluste abziehen, Anlagen wachsen teilweise nach
    $nachgewachsen = [];
    foreach ($ergebnis['v'] as $typ => [$vor, $nach]) {
        $neu = $nach;
        if (!EINHEITEN[$typ]['beweglich'] && $vor > $nach) {
            $nachgewachsen[$typ] = (int)round(($vor - $nach) * WIEDERAUFBAU_ANTEIL);
            $neu += $nachgewachsen[$typ];
        }
        einheiten_aendern($db, $zb['id'], $typ, $neu - $vor);
    }

    $ueberlebende = array_filter(array_map(fn($p) => $p[1], $ergebnis['a']));
    $beute = array_fill_keys(array_keys(RESSOURCEN), 0.0);
    if ($ergebnis['sieger'] === 'angreifer') {
        $beute = beute($zb, zug_ladung($ueberlebende));
        kosten_abziehen($db, $zb['id'], $beute);
    }
    if ($ueberlebende) {
        $db->prepare("UPDATE zuege SET status = 'zurueck', einheiten = ?, holz = ?, ziegel = ?, korn = ?, taler = ? WHERE id = ?")
           ->execute([json_encode($ueberlebende), $beute['holz'], $beute['ziegel'], $beute['korn'], $beute['taler'], $zug['id']]);
    } else {
        $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
    }

    $namen = $db->prepare('SELECT id, name FROM spieler WHERE id IN (?, ?)');
    $namen->execute([$sid, $verteidiger]);
    $n = $namen->fetchAll(PDO::FETCH_KEY_PAIR);
    $bericht = $ergebnis + ['ort' => $zielName, 'angreifer' => $n[$sid] ?? '?', 'verteidiger' => $n[$verteidiger] ?? '?',
                            'beute' => $beute, 'nachgewachsen' => $nachgewachsen, 'graben' => (int)($ziel['stufen']['graben'] ?? 0), 'wehr' => (int)$zb['wehr'],
                            'nacht' => $nacht];
    chronik('raubzug', ort_von_bereich($db, $zb['id']), $ergebnis['sieger'], $ts);
    $ausgang = ['angreifer' => 'gewonnen', 'verteidiger' => 'verloren', 'unentschieden' => 'unentschieden'][$ergebnis['sieger']];
    nachricht($db, $sid, $ts, 'kampf', "Raubzug auf {$zielName}: {$ausgang}", $bericht + ['seite' => 'angreifer']);
    $ausgangV = ['angreifer' => 'Überfall verloren', 'verteidiger' => 'Überfall abgewehrt', 'unentschieden' => 'Überfall unentschieden'][$ergebnis['sieger']];
    nachricht($db, $verteidiger, $ts, 'kampf', "{$ausgangV}: {$bericht['angreifer']} in {$zielName}", $bericht + ['seite' => 'verteidiger']);
}

// =====================================================================
// Bunker
// =====================================================================

/** Einheiten sofort in den Bunker bringen. $einheiten: ['typ' => anzahl] */
function bunker_einlagern(string $bid, int $sid, array $einheiten): ?string
{
    $einheiten = array_filter(array_map('intval', array_intersect_key($einheiten, BUNKER_PLATZ)), fn($n) => $n > 0);
    if (!$einheiten) {
        return 'Wähle mindestens eine Einheit aus.';
    }
    return in_transaktion(function (PDO $db) use ($bid, $sid, $einheiten) {
        $z = bereich_aktualisieren($db, $bid, time());
        if ((int)$z['bereich']['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        $stufe = (int)($z['stufen']['bunker'] ?? 0);
        if ($stufe < 1) {
            return 'In diesem Bereich gibt es noch keinen Bunker.';
        }
        foreach ($einheiten as $typ => $n) {
            if (($z['einheiten'][$typ] ?? 0) < $n) {
                return 'Hier stehen nicht genug ' . EINHEITEN[$typ]['name'] . ' bereit.';
            }
        }
        $frei = bunker_plaetze($stufe, $z['fo']) - bunker_belegt($z['bunker']);
        if (bunker_belegt($einheiten) > $frei) {
            return 'So viel passt nicht in den Bunker (noch ' . max(0, $frei) . ' Plätze frei).';
        }
        foreach ($einheiten as $typ => $n) {
            einheiten_aendern($db, $bid, $typ, -$n);
            $db->prepare('INSERT INTO bunker (bereich_id, typ, anzahl) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE anzahl = anzahl + VALUES(anzahl)')
               ->execute([$bid, $typ, $n]);
        }
        return null;
    });
}

/** Einheiten aus dem Bunker holen – sie stehen erst nach einer Verzögerung wieder bereit (bis dahin weiter geschützt). */
function bunker_auslagern(string $bid, int $sid, array $einheiten): ?string
{
    $einheiten = array_filter(array_map('intval', array_intersect_key($einheiten, BUNKER_PLATZ)), fn($n) => $n > 0);
    if (!$einheiten) {
        return 'Wähle mindestens eine Einheit aus.';
    }
    return in_transaktion(function (PDO $db) use ($bid, $sid, $einheiten) {
        $z = bereich_aktualisieren($db, $bid, time());
        if ((int)$z['bereich']['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        foreach ($einheiten as $typ => $n) {
            if (($z['bunker'][$typ] ?? 0) < $n) {
                return 'Im Bunker sind nicht so viele ' . EINHEITEN[$typ]['name'] . '.';
            }
        }
        foreach ($einheiten as $typ => $n) {
            $db->prepare('UPDATE bunker SET anzahl = anzahl - ? WHERE bereich_id = ? AND typ = ?')->execute([$n, $bid, $typ]);
        }
        $db->prepare('DELETE FROM bunker WHERE bereich_id = ? AND anzahl = 0')->execute([$bid]);
        $fertig = time() + bunker_auslagern_s(max(1, (int)($z['stufen']['bunker'] ?? 1)), $z['fo'], tempo());
        $db->prepare('INSERT INTO bunker_auslagerung (bereich_id, einheiten, fertig_ts) VALUES (?, ?, ?)')->execute([$bid, json_encode($einheiten), $fertig]);
        return null;
    });
}
