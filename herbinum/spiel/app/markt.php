<?php
// Markt: eigene Rohstoffe beim Händler tauschen (mit Verlust) und Angebote anderer Spieler annehmen.
// Werte und Formeln stehen in rules.php (MARKT_…).
declare(strict_types=1);

/** Grundwert, den der Spieler in den letzten 24 Stunden beim Händler abgegeben hat. */
function markt_umsatz_24h(PDO $db, int $sid): int
{
    $st = $db->prepare("SELECT gibt_res, SUM(gibt_menge) FROM markt_log WHERE spieler_id = ? AND art = 'haendler' AND ts > ? GROUP BY gibt_res");
    $st->execute([$sid, time() - 86400]);
    $summe = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $r => $n) {
        $summe += (MARKT_WERT[$r] ?? 1) * (int)$n;
    }
    return (int)$summe;
}

/** Händlerkonditionen für einen Bereich: Verlust (inkl. Krise), Tageslimit, schon genutzt. */
function markt_konditionen(PDO $db, array $z, int $sid): array
{
    $stufe = (int)($z['stufen']['markt'] ?? 0);
    $krise = ereignis_marktaufschlag($z['bereich'], time());
    return [
        'stufe' => $stufe,
        'verlust' => min(0.9, markt_verlust($stufe, $z['fo']) + $krise),
        'krise' => $krise,
        'limit' => markt_tageslimit($stufe, $z['fo'], tempo()),
        'genutzt' => markt_umsatz_24h($db, $sid),
    ];
}

function markt_tauschen(string $bid, int $sid, string $von, string $nach, int $menge): ?string
{
    if (!isset(MARKT_WERT[$von], MARKT_WERT[$nach]) || $von === $nach) {
        return 'Bitte zwei verschiedene Rohstoffe wählen.';
    }
    if ($menge < 10) {
        return 'Der Händler tauscht erst ab 10 Stück.';
    }
    return in_transaktion(function (PDO $db) use ($bid, $sid, $von, $nach, $menge) {
        $z = bereich_aktualisieren($db, $bid, time());
        if ((int)$z['bereich']['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        $k = markt_konditionen($db, $z, $sid);
        if ($k['stufe'] < 1) {
            return 'Für den Händler brauchst du in diesem Bereich einen Marktplatz.';
        }
        $wert = (int)ceil($menge * MARKT_WERT[$von]);
        if ($k['genutzt'] + $wert > $k['limit']) {
            $rest = max(0, $k['limit'] - $k['genutzt']);
            return 'Das Tageslimit des Händlers reicht nicht – heute gehen noch höchstens ' . zahl(floor($rest / MARKT_WERT[$von])) . ' ' . RESSOURCEN[$von] . '.';
        }
        if ((float)$z['bereich'][$von] < $menge) {
            return 'Nicht genug ' . RESSOURCEN[$von] . '.';
        }
        $erhalt = markt_tausch($von, $nach, $menge, $k['verlust']);
        if ($erhalt < 1) {
            return 'Für so wenig gibt der Händler nichts.';
        }
        kosten_abziehen($db, $bid, [$von => $menge]);
        kosten_abziehen($db, $bid, [$nach => $erhalt], +1);
        $db->prepare("INSERT INTO markt_log (spieler_id, ts, art, gibt_res, gibt_menge, bekommt_res, bekommt_menge) VALUES (?, ?, 'haendler', ?, ?, ?, ?)")
           ->execute([$sid, time(), $von, $menge, $nach, $erhalt]);
        $_SESSION['markt_ergebnis'] = zahl($menge) . ' ' . RESSOURCEN[$von] . ' gegen ' . zahl($erhalt) . ' ' . RESSOURCEN[$nach] . ' getauscht.';
        return null;
    });
}

// ---------------------------------------------------------------------
// Spielerhandel
// ---------------------------------------------------------------------

/** Prüft, ob ein Angebot fair genug ist (kein Verschenken an Freunde). */
function angebot_kurs_ok(string $gibt, int $gibtMenge, string $will, int $willMenge): bool
{
    $verhaeltnis = ($willMenge * MARKT_WERT[$will]) / max(1, $gibtMenge * MARKT_WERT[$gibt]);
    return $verhaeltnis >= MARKT_KURS_SPANNE[0] && $verhaeltnis <= MARKT_KURS_SPANNE[1];
}

function angebot_erstellen(string $bid, int $sid, string $gibt, int $gibtMenge, string $will, int $willMenge, bool $nurAllianz): ?string
{
    if (!isset(MARKT_WERT[$gibt], MARKT_WERT[$will]) || $gibt === $will) {
        return 'Bitte zwei verschiedene Rohstoffe wählen.';
    }
    if ($gibtMenge < 100 || $willMenge < 1) {
        return 'Ein Angebot umfasst mindestens 100 Stück.';
    }
    if (!angebot_kurs_ok($gibt, $gibtMenge, $will, $willMenge)) {
        return 'Der Gegenwert muss zwischen der Hälfte und dem Doppelten des Grundwerts liegen (Grundwerte: '
            . implode(', ', array_map(fn($r, $w) => RESSOURCEN[$r] . ' ' . str_replace('.', ',', (string)$w), array_keys(MARKT_WERT), MARKT_WERT)) . ').';
    }
    return in_transaktion(function (PDO $db) use ($bid, $sid, $gibt, $gibtMenge, $will, $willMenge, $nurAllianz) {
        $z = bereich_aktualisieren($db, $bid, time());
        if ((int)$z['bereich']['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        if ((int)($z['stufen']['markt'] ?? 0) < 1) {
            return 'Angebote kannst du nur aus einem Bereich mit Marktplatz einstellen.';
        }
        if ((int)($z['fo']['handelskunde'] ?? 0) < MARKT_SPIELER_AB) {
            return 'Handel mit anderen Spielern gibt es ab Handelskunde Stufe ' . MARKT_SPIELER_AB . '.';
        }
        $st = $db->prepare('SELECT COUNT(*) FROM markt_angebote WHERE spieler_id = ?');
        $st->execute([$sid]);
        if ((int)$st->fetchColumn() >= max_angebote($z['fo'])) {
            return 'Du hast schon ' . max_angebote($z['fo']) . ' Angebote offen. Mehr erlaubt Handelskunde.';
        }
        if ($nurAllianz && !gen_von($sid)) {
            return 'Du bist in keiner Allianz.';
        }
        if ((float)$z['bereich'][$gibt] < $gibtMenge) {
            return 'Nicht genug ' . RESSOURCEN[$gibt] . '.';
        }
        // Die Ware liegt ab jetzt beim Markt und kommt bei Rückzug oder Ablauf zurück
        kosten_abziehen($db, $bid, [$gibt => $gibtMenge]);
        $db->prepare('INSERT INTO markt_angebote (spieler_id, bereich_id, gibt_res, gibt_menge, will_res, will_menge, nur_allianz, erstellt_ts, ablauf_ts) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$sid, $bid, $gibt, $gibtMenge, $will, $willMenge, $nurAllianz ? 1 : 0, time(), time() + MARKT_ANGEBOT_TAGE * 86400]);
        return null;
    });
}

/** Ware eines Angebots zurück an den Anbieter (in den Bereich, sonst in seinen Heimatbereich). */
function angebot_rueckgabe(PDO $db, array $a, string $grund): void
{
    $st = $db->prepare('SELECT id FROM bereiche WHERE besitzer_id = ? ORDER BY (id = ?) DESC, ist_heimat DESC LIMIT 1');
    $st->execute([$a['spieler_id'], $a['bereich_id']]);
    if ($ziel = $st->fetchColumn()) {
        bereich_aktualisieren($db, $ziel, time());
        kosten_abziehen($db, $ziel, [$a['gibt_res'] => (int)$a['gibt_menge']], +1);
    }
    $db->prepare('DELETE FROM markt_angebote WHERE id = ?')->execute([$a['id']]);
    if ($grund !== '') {
        nachricht($db, (int)$a['spieler_id'], time(), 'markt', $grund, ['text' => zahl((int)$a['gibt_menge']) . ' ' . RESSOURCEN[$a['gibt_res']] . ' sind zurück in deinem Lager.']);
    }
}

function angebot_zurueckziehen(int $sid, int $id): ?string
{
    return in_transaktion(function (PDO $db) use ($sid, $id) {
        $st = $db->prepare('SELECT * FROM markt_angebote WHERE id = ? AND spieler_id = ? FOR UPDATE');
        $st->execute([$id, $sid]);
        $a = $st->fetch();
        if (!$a) {
            return 'Dieses Angebot gibt es nicht mehr.';
        }
        angebot_rueckgabe($db, $a, '');
        return null;
    });
}

/** Gebühr in Prozent für einen Handel zwischen zwei Spielern. */
function markt_gebuehr(PDO $db, int $a, int $b): float
{
    return gleiche_genossenschaft($db, $a, $b) ? MARKT_GEBUEHR_ALLIANZ : MARKT_GEBUEHR;
}

function angebot_annehmen(string $bid, int $sid, int $id): ?string
{
    return in_transaktion(function (PDO $db) use ($bid, $sid, $id) {
        $st = $db->prepare('SELECT * FROM markt_angebote WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $a = $st->fetch();
        if (!$a || (int)$a['ablauf_ts'] <= time()) {
            return 'Dieses Angebot gibt es nicht mehr.';
        }
        $anbieter = (int)$a['spieler_id'];
        if ($anbieter === $sid) {
            return 'Das ist dein eigenes Angebot.';
        }
        $z = bereich_aktualisieren($db, $bid, time());
        if ((int)$z['bereich']['besitzer_id'] !== $sid) {
            return 'Das ist nicht dein Bereich.';
        }
        if ((int)($z['stufen']['markt'] ?? 0) < 1) {
            return 'Zum Handeln brauchst du in diesem Bereich einen Marktplatz.';
        }
        if ((int)($z['fo']['handelskunde'] ?? 0) < MARKT_SPIELER_AB) {
            return 'Handel mit anderen Spielern gibt es ab Handelskunde Stufe ' . MARKT_SPIELER_AB . '.';
        }
        if ((int)$a['nur_allianz'] && !gleiche_genossenschaft($db, $sid, $anbieter)) {
            return 'Dieses Angebot gilt nur für die Allianz des Anbieters.';
        }
        if ((float)$z['bereich'][$a['will_res']] < (int)$a['will_menge']) {
            return 'Nicht genug ' . RESSOURCEN[$a['will_res']] . '.';
        }
        $gebuehr = markt_gebuehr($db, $sid, $anbieter);
        $kaeuferBekommt = (int)floor((int)$a['gibt_menge'] * (1 - $gebuehr));
        $anbieterBekommt = (int)floor((int)$a['will_menge'] * (1 - $gebuehr));
        kosten_abziehen($db, $bid, [$a['will_res'] => (int)$a['will_menge']]);
        kosten_abziehen($db, $bid, [$a['gibt_res'] => $kaeuferBekommt], +1);
        // Der Anbieter bekommt die Gegenleistung in den Bereich, aus dem er angeboten hat
        $rueck = ['spieler_id' => $anbieter, 'bereich_id' => $a['bereich_id'], 'gibt_res' => $a['will_res'], 'gibt_menge' => $anbieterBekommt, 'id' => $a['id']];
        angebot_rueckgabe($db, $rueck, '');
        $name = $db->prepare('SELECT name FROM spieler WHERE id = ?');
        $name->execute([$sid]);
        nachricht($db, $anbieter, time(), 'markt', 'Dein Angebot wurde angenommen', ['text' => $name->fetchColumn() . ' hat ' . zahl((int)$a['gibt_menge']) . ' '
            . RESSOURCEN[$a['gibt_res']] . ' gekauft. Du erhältst ' . zahl($anbieterBekommt) . ' ' . RESSOURCEN[$a['will_res']]
            . ' (nach ' . round($gebuehr * 100) . ' % Marktgebühr).']);
        $log = $db->prepare("INSERT INTO markt_log (spieler_id, ts, art, gibt_res, gibt_menge, bekommt_res, bekommt_menge) VALUES (?, ?, 'spieler', ?, ?, ?, ?)");
        $log->execute([$sid, time(), $a['will_res'], $a['will_menge'], $a['gibt_res'], $kaeuferBekommt]);
        $log->execute([$anbieter, time(), $a['gibt_res'], $a['gibt_menge'], $a['will_res'], $anbieterBekommt]);
        chronik('markt', ort_von_bereich($db, $bid));
        $_SESSION['markt_ergebnis'] = 'Gekauft: ' . zahl($kaeuferBekommt) . ' ' . RESSOURCEN[$a['gibt_res']] . ' (nach ' . round($gebuehr * 100) . ' % Gebühr).';
        return null;
    });
}

/** Offene Angebote, die $sid sehen darf (eigene, öffentliche und die seiner Allianz). */
function markt_angebote(int $sid): array
{
    $st = db()->prepare('SELECT a.*, s.name, s.farbe, s.genossenschaft_id AS gid, g.kuerzel, o.name AS ort,
                                (s.genossenschaft_id IS NOT NULL AND s.genossenschaft_id = (SELECT genossenschaft_id FROM spieler WHERE id = ?)) AS verbuendet
                         FROM markt_angebote a JOIN spieler s ON s.id = a.spieler_id LEFT JOIN genossenschaften g ON g.id = s.genossenschaft_id
                         JOIN bereiche b ON b.id = a.bereich_id JOIN orte o ON o.id = b.ort_id
                         WHERE a.ablauf_ts > ? ORDER BY a.erstellt_ts DESC');
    $st->execute([$sid, time()]);
    return array_values(array_filter($st->fetchAll(), fn($a) => !(int)$a['nur_allianz'] || (int)$a['spieler_id'] === $sid || (int)$a['verbuendet']));
}

/** Abgelaufene Angebote zurückgeben, alte Protokolle löschen. */
function markt_aufraeumen(int $jetzt): void
{
    $st = db()->prepare('SELECT id FROM markt_angebote WHERE ablauf_ts <= ?');
    $st->execute([$jetzt]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        in_transaktion(function (PDO $db) use ($id) {
            $st = $db->prepare('SELECT * FROM markt_angebote WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            if ($a = $st->fetch()) {
                angebot_rueckgabe($db, $a, 'Dein Marktangebot ist abgelaufen');
            }
        });
    }
    db()->prepare('DELETE FROM markt_log WHERE ts < ?')->execute([$jetzt - 14 * 86400]);
}
