<?php
// Forschung, Fuhrpark, Züge (Transport, Verlegen, Erkunden, Besiedeln) und deren Ereignisse.
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
            return 'In deinem Forschungszentrum wird schon geforscht – immer nur eine Forschung gleichzeitig.';
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
// Fuhrpark
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
            return 'Fuhrpark und Sicherheitszentrale haben zusammen schon ' . WERKSTATT_MAX_AUFTRAEGE . ' Aufträge.';
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
        $reserviert = reservierte_einheiten($db, $startId);
        foreach ($einheiten as $typ => $n) {
            if (($z['einheiten'][$typ] ?? 0) - ($reserviert[$typ] ?? 0) < $n) {
                return 'Hier stehen nicht genug Einheiten vom Typ „' . EINHEITEN[$typ]['name'] . '“ bereit' . (($reserviert[$typ] ?? 0) ? ' – ' . $reserviert[$typ] . ' rüsten gerade für einen Raubzug.' : '.');
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
                    return 'Zum Erkunden brauchst du mindestens einen E-Bike-Kurier.';
                }
                if ($zielBesitzer === $sid) {
                    return 'Deinen eigenen Bereich kennst du schon.';
                }
                if (array_sum($ladung) > 0) {
                    return 'Kuriere auf Erkundung nehmen keine Ladung mit.';
                }
                if ($zielBesitzer !== null) {
                    if ($bis = spionage_sperre_bis($db, $sid, $zielBesitzer, $jetzt)) {
                        return 'Diesen Spieler kannst du erst ab ' . date('d.m. H:i', $bis) . ' Uhr wieder erkunden.';
                    }
                    if (erkundung_unterwegs($db, $sid, $zielBesitzer)) {
                        return 'Zu diesem Spieler ist schon eine Erkundung von dir unterwegs.';
                    }
                }
                break;
            case 'angriff':
                if ($zielBesitzer === null || $zielBesitzer === $sid) {
                    return 'Raubzüge gehen nur gegen besiedelte Bereiche anderer Spieler.';
                }
                if (!array_filter($einheiten, fn($n, $t) => EINHEITEN[$t]['angriff'] > 0, ARRAY_FILTER_USE_BOTH)) {
                    return 'Für einen Raubzug brauchst du Sicherheitskräfte oder Einsatzteams.';
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
                $erlaubt = raubzug_erlaubt($db, $sid, $zielBesitzer, $jetzt);
                if (is_string($erlaubt)) {
                    return $erlaubt;
                }
                // Version 9: Jeder Raubzug braucht eine freie Einsatzgruppe
                $gruppe = freie_einsatzgruppe($db, $sid, $jetzt);
                if ($gruppe === null) {
                    $ab = naechste_einsatzgruppe_ab($db, $sid, $jetzt);
                    return 'Keine Einsatzgruppe frei' . ($ab ? ' – die nächste ist ab ' . date('d.m. H:i', $ab) . ' Uhr wieder einsatzbereit.' : ' – alle sind gerade im Einsatz.')
                        . ' Mehr Gruppen gleichzeitig erlaubt die Kommandozentrale.';
                }
                break;
            case 'kolonisieren':
                if (empty($einheiten['siedlertreck'])) {
                    return 'Zum Besiedeln brauchst du einen Siedlerkonvoi.';
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
        // Erkundung eines Spielers kostet Einsatzgeld (Ausrüstung, Bestechung)
        $einsatz = $auftrag === 'erkunden' && $zielBesitzer !== null ? spionage_kosten((int)($z['fo']['dorftratsch'] ?? 0)) : 0;
        $bedarf['taler'] += $einsatz;
        if ($f = kosten_pruefen($b, $bedarf)) {
            return $f . ($verbrauch ? ' (Die Fahrt kostet ' . zahl($verbrauch) . ' Nahrung.)' : '') . ($einsatz ? ' (Die Erkundung kostet ' . zahl($einsatz) . ' Euro Einsatzgeld.)' : '');
        }
        if ($auftrag === 'angriff') {
            // Raubzüge beginnen mit dem Rüsten; die Truppen verlassen den Bereich erst beim Ausrücken
            kosten_abziehen($db, $startId, $bedarf);
            einsatz_anlegen($db, $sid, $startId, $zielId, $einheiten, $z['fo'], $gruppe, $verbrauch, $jetzt);
            gegenschlag_freigeben($db, $sid, $zielBesitzer);     // wer angreift, darf vom Ziel einmal zurückbekommen
            return null;
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
        if (($zug['phase'] ?? '') !== '') {
            return einsatz_abbrechen($db, $zug, $jetzt);
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
                         WHERE b.besitzer_id = ? AND z.spieler_id <> ? AND z.status = 'hin' AND z.phase <> 'ruesten' ORDER BY z.ankunft_ts");
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

    // Mehrere Runden: eine Ankunft kann eine ebenfalls schon fällige Rückkehr auslösen, ein Raubzug mehrere Phasen nachholen
    for ($runde = 0; $runde < 12; $runde++) {
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
    if (($zug['phase'] ?? '') !== '') {
        einsatz_weiter($db, $zug);
        return;
    }
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
            erkundung_ankunft($db, $zug, $ziel, $zielName, $besitzer === $sid ? null : $besitzer);
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
            // Schutzzeit und Stärkeverhältnis gelten auch für Züge, die schon unterwegs waren
            $erlaubt = raubzug_erlaubt($db, $sid, $besitzer, $ts);
            if (is_string($erlaubt)) {
                nachricht($db, $sid, $ts, 'kampf', "Raubzug nach {$zielName} kehrt um", ['text' => $erlaubt]);
                zug_umkehren($db, $zug, false);
                return;
            }
            raubzug_ausfuehren($db, $zug, $ziel, $zielName, $besitzer, $erlaubt);
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
            $rest['siedlertreck']--;    // der Treck wird zur Zentrale
            foreach ($rest as $typ => $n) {
                if ($n > 0) {
                    einheiten_aendern($db, $zug['ziel_id'], $typ, $n);
                }
            }
            $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
            nachricht($db, $sid, $ts, 'kolonie', "{$zielName} besiedelt!", ['text' => 'Deine Siedler haben eine Zentrale gegründet. Wechsle oben rechts zum neuen Bereich.']);
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
        einsatzgruppe_freigeben($db, (int)$zug['spieler_id'], (int)($zug['gruppe'] ?? 0), (int)$zug['rueckkehr_ts']);
        return;
    }
    kosten_abziehen($db, $zug['start_id'], ['holz' => (float)$zug['holz'], 'ziegel' => (float)$zug['ziegel'], 'korn' => (float)$zug['korn'], 'taler' => (float)$zug['taler']], +1);
    foreach (zug_einheiten($zug) as $typ => $n) {
        einheiten_aendern($db, $zug['start_id'], $typ, $n);
    }
    $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
    if (($zug['phase'] ?? '') !== '') {
        einsatz_heimkehr($db, $zug, $start);
    }
}

/** Angriffs- und Abwehrwert einer Erkundung. */
function spionage_werte(array $foA, array $foV, int $kuriere, array $einheitenZiel): array
{
    $a = (int)($foA['dorftratsch'] ?? 0) + log(max(1, $kuriere), 2);
    $d = (int)($foV['abschirmung'] ?? 0) + 0.05 * (int)($einheitenZiel['hochsitz'] ?? 0) + 0.01 * (int)($einheitenZiel['schuetze'] ?? 0);
    return [$a, $d];
}

/**
 * Was der Kurier sieht, hängt vom Vorsprung V = Angriffswert − Abwehrwert ab (siehe spionage_genauigkeit):
 * Vorräte als Spanne, ab 1 Einheiten ungefähr, ab 2 genau und Gebäude, ab 3 Forschung, Schutzzeit und ob der Bunker belegt ist.
 * Ohne $vorsprung wird er ohne Zufall berechnet.
 */
function erkundungsbericht(PDO $db, array $zug, array $ziel, ?float $vorsprung = null): array
{
    $b = $ziel['bereich'];
    $besitzer = null;
    if ($b['besitzer_id']) {
        $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
        $st->execute([$b['besitzer_id']]);
        $besitzer = $st->fetchColumn();
    }
    $r = [
        'besitzer' => $besitzer,
        'werte' => ['Bauplätze' => (int)$b['bauplaetze'], 'Lebensqualität' => (int)$b['lebensqualitaet'], 'Wehrkraft' => (int)$b['wehr'], 'Einkommen' => (int)$b['einkommen']],
        'vorraete' => null,
    ];
    if (!$besitzer) {
        return $r;
    }
    $fremdeFo = forschung_laden($db, (int)$b['besitzer_id']);
    if ($vorsprung === null) {
        [$a, $d] = spionage_werte(forschung_laden($db, (int)$zug['spieler_id']), $fremdeFo, (int)(zug_einheiten($zug)['fahrradkurier'] ?? 1), $ziel['einheiten']);
        $vorsprung = $a - $d;
    }
    $r['tiefe'] = round($vorsprung, 1);
    $zufall = fn() => mt_rand(0, 1000) / 1000;
    if ($genau = spionage_genauigkeit($vorsprung)) {
        $r['vorraete'] = array_map(fn($r2) => spanne((float)$b[$r2], $genau, $zufall()), array_combine(array_keys(RESSOURCEN), array_keys(RESSOURCEN)));
    } else {
        $r['vorraete_unbekannt'] = true;
    }
    if ($vorsprung >= 2) {
        $r['einheiten'] = $ziel['einheiten'];
        $r['gebaeude'] = $ziel['stufen'];
    } elseif ($vorsprung >= 1) {
        $r['einheiten'] = array_map(fn($n) => spanne((float)$n, 0.25, $zufall()), $ziel['einheiten']);
    }
    if ($vorsprung >= 3) {
        $r['forschung'] = $fremdeFo;
        $r['bunker_belegt'] = bunker_belegt($ziel['bunker']) > 0;
        $r['blauhelme'] = blauhelme_anzahl(blauhelme_kraft($db, $b['id'], (int)$b['besitzer_id'], time()));
        $r['spielraum'] = round(verlust_stand($db, (int)$b['besitzer_id'], time())['spielraum'], 3);
    }
    return $r;
}

/** Ankunft einer Erkundung: Abfangen, Bericht, Sperre, Benachrichtigung des Erkundeten. */
function erkundung_ankunft(PDO $db, array $zug, array $ziel, string $zielName, ?int $besitzer): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    if ($besitzer === null) {
        nachricht($db, $sid, $ts, 'bericht', "Erkundung: {$zielName}", erkundungsbericht($db, $zug, $ziel));
        zug_umkehren($db, $zug, false);
        return;
    }
    $einheiten = zug_einheiten($zug);
    $kuriere = (int)($einheiten['fahrradkurier'] ?? 0);
    $foA = forschung_laden($db, $sid);
    $foV = forschung_laden($db, $besitzer);
    [$a, $d] = spionage_werte($foA, $foV, $kuriere, $ziel['einheiten']);
    $p = abfang_chance($a, $d);
    $abgefangen = 0;
    for ($i = 0; $i < $kuriere; $i++) {
        $abgefangen += mt_rand() / mt_getrandmax() < $p ? 1 : 0;
    }
    $vorsprung = $a - $d + mt_rand(-500, 500) / 1000;
    $sperre = $ts + (int)round(SPIONAGE_SPERRE_H * 3600 / tempo() * ($abgefangen ? SPIONAGE_SPERRE_ABGEFANGEN : 1));
    $db->prepare('INSERT INTO spionage_log (ts, spaeher_id, ziel_id, abgefangen, sperre_bis) VALUES (?, ?, ?, ?, ?)')
       ->execute([$ts, $sid, $besitzer, $abgefangen, $sperre]);
    $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    $spaeher = (string)$st->fetchColumn();
    $erkannt = $abgefangen > 0 || ((int)($foV['abschirmung'] ?? 0) >= 1 && (int)($foV['abschirmung'] ?? 0) >= (int)($foA['dorftratsch'] ?? 0));
    nachricht($db, $besitzer, $ts, 'warnung', 'Fremde Kuriere in ' . $zielName . ($erkannt ? " – geschickt von {$spaeher}" : ''), ['text' => $abgefangen
        ? "Deine Leute haben {$abgefangen} von {$kuriere} Kurieren abgefangen." . ($abgefangen < $kuriere ? ' Der Rest konnte sich umsehen.' : ' Niemand konnte sich umsehen.')
        : ($erkannt ? 'Jemand hat sich bei dir umgesehen. Deine Abschirmung hat erkannt, wer.' : 'Jemand hat sich bei dir umgesehen. Mehr Abschirmung verrät, wer es war.')]);
    chronik('erkundung', ort_von_bereich($db, $zug['ziel_id']), '', $ts);

    $einheiten['fahrradkurier'] = $kuriere - $abgefangen;
    $einheiten = array_filter($einheiten);
    if ($abgefangen >= $kuriere) {
        nachricht($db, $sid, $ts, 'bericht', "Erkundung von {$zielName} gescheitert", ['text' => "Alle {$kuriere} Kuriere wurden abgefangen. Der Spieler weiß jetzt, dass du es warst. "
            . 'Nächster Versuch ab ' . date('d.m. H:i', $sperre) . ' Uhr.']);
        if (!$einheiten) {
            $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
            return;
        }
        $db->prepare('UPDATE zuege SET einheiten = ? WHERE id = ?')->execute([json_encode($einheiten), $zug['id']]);
        zug_umkehren($db, $zug, false);
        return;
    }
    $bericht = erkundungsbericht($db, $zug, $ziel, $vorsprung) + ['abgefangen' => $abgefangen, 'kuriere' => $kuriere, 'sperre_bis' => $sperre];
    nachricht($db, $sid, $ts, 'bericht', "Erkundung: {$zielName}", $bericht);
    if ($abgefangen) {
        $db->prepare('UPDATE zuege SET einheiten = ? WHERE id = ?')->execute([json_encode($einheiten), $zug['id']]);
    }
    zug_umkehren($db, $zug, false);
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
        // Schutzwall und die echte Lage (Wehrkraft 0–100 → ×0,75 bis ×1,25) stärken die Verteidiger
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
            $s += $n * einheit_def($t)['angriff'] * $f;
        }
        return $s;
    };
    $treffen = function (array $seite, float $hpF, float $schaden): array {
        $gesamt = 0.0;
        foreach ($seite as $t => $n) {
            $gesamt += $n * einheit_def($t)['leben'] * $hpF;
        }
        if ($gesamt <= 0) {
            return $seite;
        }
        foreach ($seite as $t => $n) {
            $hp = einheit_def($t)['leben'] * $hpF;
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

/**
 * Beute: höchstens BEUTE_ANTEIL jedes Vorrats ohne den geschützten Tresor-Anteil, mal Beutefaktor (Stärkeverhältnis),
 * gleichmäßig gekürzt, wenn die Ladefläche nicht reicht.
 */
function beute(array $vorrat, int $kapazitaet, array $geschuetzt = [], float $faktor = 1.0): array
{
    $will = [];
    foreach (RESSOURCEN as $r => $_) {
        $will[$r] = max(0.0, floor(max(0.0, (float)$vorrat[$r] - (float)($geschuetzt[$r] ?? 0)) * BEUTE_ANTEIL * $faktor));
    }
    $summe = array_sum($will);
    $f = $summe > $kapazitaet ? $kapazitaet / $summe : 1.0;
    return array_map(fn($w) => floor($w * $f), $will);
}

function raubzug_ausfuehren(PDO $db, array $zug, array $ziel, string $zielName, int $verteidiger, float $beutefaktor = 1.0): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $zb = $ziel['bereich'];
    $nacht = ist_nacht($ts);
    $foV = forschung_laden($db, $verteidiger);
    $wertVorher = vermoegen($db, $verteidiger, $ts);
    $foA = forschung_laden($db, $sid);
    $vert = einsatz_verteidiger($db, $sid, $ziel, $verteidiger, $ts);
    $ergebnis = kampf(zug_einheiten($zug), $vert, $foA, $foV, (int)($ziel['stufen']['graben'] ?? 0), (int)$zb['wehr'], null, $nacht);

    // Verteidiger: Verluste abziehen, Anlagen wachsen teilweise nach
    $nachgewachsen = [];
    $verlust = 0.0;
    foreach ($ergebnis['v'] as $typ => [$vor, $nach]) {
        if (!isset(EINHEITEN[$typ])) {
            continue;   // Blauhelme fallen nicht
        }
        $neu = $nach;
        if (!EINHEITEN[$typ]['beweglich'] && $vor > $nach) {
            $nachgewachsen[$typ] = (int)round(($vor - $nach) * WIEDERAUFBAU_ANTEIL);
            $neu += $nachgewachsen[$typ];
        }
        einheiten_aendern($db, $zb['id'], $typ, $neu - $vor);
        $verlust += max(0, $vor - $neu) * einheit_wert($typ);
    }

    $ueberlebende = array_filter(array_map(fn($p) => $p[1], $ergebnis['a']));
    $beute = array_fill_keys(array_keys(RESSOURCEN), 0.0);
    $tresor = tresor($ziel['rate'], $foV);
    // Verlustdeckel: dieser Angriff darf höchstens min(Einzelgrenze, Spielraum bis zur Tagesgrenze) des Vermögens kosten
    $grenze = erlaubter_verlust($db, $sid, $verteidiger, $ts);
    $gekappt = false;
    if ($ergebnis['sieger'] === 'angreifer') {
        $beute = beute($zb, zug_ladung($ueberlebende), $tresor, $beutefaktor);
        $budget = max(0.0, $grenze * $wertVorher - $verlust);
        $wertBeute = rohstoff_wert($beute);
        if ($wertBeute > $budget) {
            $beute = array_map(fn($x) => floor($x * $budget / $wertBeute), $beute);
            $gekappt = true;
        }
        kosten_abziehen($db, $zb['id'], $beute);
        $verlust += rohstoff_wert($beute);
        wiederaufbau_setzen($db, $verteidiger, $ts);
        blauhelme_setzen($db, $zb['id'], $ts, kampfkraft(zug_einheiten($zug), $foA, false));
    }
    $schutz = schutz_nach_raubzug($db, $sid, $verteidiger, $ts, $ergebnis['sieger'], $wertVorher, $verlust);
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
                            'nacht' => $nacht, 'beutefaktor' => round($beutefaktor, 2), 'tresor' => array_map('floor', $tresor),
                            'schaden' => round($schutz['anteil'], 3), 'schaden_24' => round($schutz['anteil_24'], 3), 'spielraum' => round($schutz['spielraum'], 3),
                            'sperre_bis' => $schutz['sperre_bis'], 'gekappt' => $gekappt, 'blauhelme' => (int)($vert['blauhelm'] ?? 0),
                            'wiederaufbau' => $ergebnis['sieger'] === 'angreifer', 'blauhelme_neu' => $ergebnis['sieger'] === 'angreifer'];
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
                return 'Hier stehen nicht genug Einheiten vom Typ „' . EINHEITEN[$typ]['name'] . '“ bereit.';
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
                return 'Im Bunker sind nicht so viele Einheiten vom Typ „' . EINHEITEN[$typ]['name'] . '“.';
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
