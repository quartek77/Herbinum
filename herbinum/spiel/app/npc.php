<?php
// NPC-Mitspieler (Version 9): regelbasiert, ohne KI für Entscheidungen. Die Spielleitung stellt die Anzahl ein (0–6).
// NPCs bauen, forschen, verteidigen und handeln wie Spieler über dieselben Funktionen, greifen aber nie von sich aus an –
// höchstens einmal zurück, wenn sie selbst überfallen wurden. Ihre Stärke folgt dem Mittelfeld der aktiven Spieler.
// Ein Schritt je NPC und halbe Stunde (÷ Tempo), ausgelöst beim Seitenaufruf wie die Produktion – kein Cronjob.
declare(strict_types=1);

const NPC_MAX = 6;
const NPC_TAKT_MIN = 30.0;                 // ein Schritt je 30 Minuten (÷ Tempo)
const NPC_ZIEL_ANTEIL = [0.7, 0.9];        // Zielpunkte: 70–90 % des Medians der aktiven Spieler
const NPC_FAKTOR_GRENZEN = [0.5, 3.0];     // Wachstumshilfe (Produktion × Faktor)
const NPC_FREI_IM_ORT = 4;                 // nur in Orten mit mindestens so vielen freien Bereichen siedeln
const NPC_NAMEN = ['Nordlicht AG', 'Kollektiv Südhang', 'Davert Werke', 'Grünstrom eG', 'Funkenflug GmbH', 'Kanalwerk Nord',
                   'Lindenhof Labs', 'Westwind Handel', 'Steinbruch Sieben', 'Morgenrot Kollektiv', 'Auenland Logistik', 'Turmfalke Security'];
// bau = Reihenfolge der Gebäude, verteidigung = Soll je Stufe der Zentrale (Barrikaden, Wachtürme, Sicherheitskräfte)
const NPC_TYPEN = [
    'haendler' => ['name' => 'Händler', 'bau' => ['holzplatz', 'ziegelei', 'windrad', 'acker', 'markt', 'scheune', 'hofstelle', 'wohnhaus', 'gasthof', 'schuetzenhalle', 'dorfschule'],
                   'verteidigung' => ['wallhecke' => 3, 'hochsitz' => 0.5, 'schuetze' => 3]],
    'aufbauer' => ['name' => 'Aufbauer', 'bau' => ['wohnhaus', 'hofstelle', 'holzplatz', 'ziegelei', 'windrad', 'acker', 'scheune', 'gasthof', 'markt', 'schuetzenhalle', 'dorfschule'],
                   'verteidigung' => ['wallhecke' => 6, 'hochsitz' => 1, 'schuetze' => 4]],
    'festung' => ['name' => 'Festung', 'bau' => ['holzplatz', 'ziegelei', 'windrad', 'acker', 'hofstelle', 'schuetzenhalle', 'graben', 'scheune', 'bunker', 'wohnhaus', 'dorfschule'],
                  'verteidigung' => ['wallhecke' => 12, 'hochsitz' => 3, 'schuetze' => 8]],
];
const NPC_FORSCHUNG = ['forstwirtschaft', 'ziegelbrand', 'fruchtfolge', 'windkraft', 'schutzausruestung', 'sicherheitstechnik', 'schiessausbildung', 'handelskunde'];

function npc_soll(): int
{
    return max(0, min(NPC_MAX, (int)einstellung('npc_anzahl', '0')));
}

/** Alle NPCs mit Bereich, Typ und Punkten (für die Spielleitung). */
function npc_liste(): array
{
    $p = punkte_alle();
    $liste = db()->query('SELECT s.id, s.name, n.*, (SELECT b.id FROM bereiche b WHERE b.besitzer_id = s.id LIMIT 1) AS bereich_id
                          FROM spieler s JOIN npc n ON n.spieler_id = s.id ORDER BY s.id')->fetchAll();
    $median = npc_median();
    foreach ($liste as &$n) {
        $n['punkte'] = (int)($p[(int)$n['id']]['gesamt'] ?? 0);
        $n['ziel'] = (int)round((float)$n['ziel_anteil'] * $median);
    }
    return $liste;
}

/** Median der Punkte aller echten Spieler, die in den letzten 7 Tagen da waren (mindestens 1). */
function npc_median(): int
{
    $p = punkte_alle();
    $ids = db()->query('SELECT id FROM spieler WHERE ist_npc = 0 AND gesperrt = 0 AND zuletzt_ts > ' . (time() - 7 * 86400))->fetchAll(PDO::FETCH_COLUMN);
    $werte = array_map(fn($id) => (int)($p[(int)$id]['gesamt'] ?? 0), $ids);
    if (!$werte) {
        return 1;
    }
    sort($werte);
    $m = count($werte);
    return max(1, $m % 2 ? $werte[intdiv($m, 2)] : intdiv($werte[$m / 2 - 1] + $werte[$m / 2], 2));
}

/** Läuft bei jedem Seitenaufruf (über ereignisse_abarbeiten): höchstens ein NPC-Schritt. */
function npc_takt(int $jetzt): void
{
    $sid = db()->query("SELECT spieler_id FROM npc WHERE naechster_schritt_ts <= {$jetzt} ORDER BY naechster_schritt_ts LIMIT 1")->fetchColumn();
    if ($sid !== false) {
        npc_schritt((int)$sid, $jetzt);
    }
}

/** Anzahl an die Einstellung angleichen: je Aufruf höchstens ein NPC mehr oder weniger. Läuft alle 10 Minuten. */
function npc_abgleichen(int $jetzt): ?string
{
    $ist = (int)db()->query('SELECT COUNT(*) FROM npc')->fetchColumn();
    $soll = npc_soll();
    if ($ist < $soll) {
        return npc_anlegen($jetzt);
    }
    if ($ist > $soll) {
        // der zuletzt angelegte zieht weg, sobald keine Züge mehr von ihm oder zu ihm unterwegs sind
        foreach (db()->query('SELECT spieler_id FROM npc ORDER BY spieler_id DESC')->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            if (npc_entfernen((int)$sid)) {
                return null;
            }
        }
    }
    return null;
}

function npc_name(PDO $db): ?string
{
    $vergeben = array_flip($db->query('SELECT name FROM spieler')->fetchAll(PDO::FETCH_COLUMN));
    if (einstellung('npc_ki_namen', '0') === '1' && ki_konfiguriert() && !ki_budget_erreicht()) {
        try {
            $vorschlag = trim(ki_anfrage('Du erfindest Namen für computergesteuerte Mitspieler in einem modernen Aufbau-Strategiespiel im Münsterland. '
                . 'Antworte nur mit einem kurzen, erfundenen Firmen- oder Gruppennamen (2–24 Zeichen, nur Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich), '
                . 'ohne echte Firmen, Personen oder Marken, ohne Anführungszeichen.', [['role' => 'user', 'content' => 'Ein neuer Name, bitte. Schon vergeben: '
                . implode(', ', array_slice(array_keys($vergeben), 0, 40))]], 'claude-haiku-5-5', 'npc', null, 60));
            if (preg_match('/^[\p{L}\p{N} _.\-]{3,24}$/u', $vorschlag) && !isset($vergeben[$vorschlag])) {
                return $vorschlag;
            }
        } catch (Throwable) {
            // dann eben ein Name aus der Liste
        }
    }
    foreach (NPC_NAMEN as $n) {
        if (!isset($vergeben[$n])) {
            return $n;
        }
    }
    return null;
}

/** Legt einen NPC mit Startausstattung in einem freien Bereich an. Gibt bei Erfolg null zurück. */
function npc_anlegen(int $jetzt): ?string
{
    $median = npc_median();
    $name = npc_name(db());     // vor der Transaktion – eine KI-Anfrage soll keine Zeilen sperren
    if ($name === null) {
        return 'Kein freier NPC-Name mehr.';
    }
    return in_transaktion(function (PDO $db) use ($jetzt, $median, $name) {
        $st = $db->prepare("SELECT b.id, b.bauplaetze FROM bereiche b
                            WHERE b.besitzer_id IS NULL AND b.typ <> 'Kern'
                              AND b.ort_id NOT IN (SELECT b2.ort_id FROM bereiche b2 JOIN spieler s ON s.id = b2.besitzer_id WHERE s.ist_npc = 1)
                              AND b.id NOT IN (SELECT ziel_id FROM zuege WHERE auftrag = 'kolonisieren')
                              AND (SELECT COUNT(*) FROM bereiche b3 WHERE b3.ort_id = b.ort_id AND b3.besitzer_id IS NULL AND b3.typ <> 'Kern') >= ?
                            ORDER BY RAND() LIMIT 1 FOR UPDATE");
        $st->execute([NPC_FREI_IM_ORT]);
        $b = $st->fetch();
        if (!$b) {
            return 'Kein passender freier Bereich für einen NPC-Mitspieler.';
        }
        $st = $db->prepare('SELECT COUNT(*) FROM spieler WHERE name = ?');
        $st->execute([$name]);
        if ((int)$st->fetchColumn()) {
            return 'Der NPC-Name ist inzwischen vergeben.';
        }
        $typ = array_rand(NPC_TYPEN);
        $anteil = NPC_ZIEL_ANTEIL[0] + (NPC_ZIEL_ANTEIL[1] - NPC_ZIEL_ANTEIL[0]) * mt_rand(0, 1000) / 1000;
        // ohne Anfängerschutz: NPCs sind ab sofort Ziele (für sie gelten aber Tresor, Verlustgrenze und Blauhelme)
        $erstellt = $jetzt - (int)(config()['spiel']['schutz_stunden'] ?? 72) * 3600 - 60;
        $db->prepare('INSERT INTO spieler (name, pass_hash, farbe, ist_admin, erstellt_ts, zuletzt_ts, ist_npc) VALUES (?, ?, ?, 0, ?, 0, 1)')
           ->execute([$name, '!npc', '#6b7a8c', $erstellt]);
        $sid = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO npc (spieler_id, typ, ziel_anteil, prod_faktor, faktor_ts, naechster_schritt_ts, letzter_schritt_ts) VALUES (?, ?, ?, 1, ?, ?, ?)')
           ->execute([$sid, $typ, $anteil, $jetzt, $jetzt + 60, $jetzt]);

        // Startausstattung: Gebäude bis etwa 60 % der Zielpunkte, nach der Reihenfolge des Typs
        $lv = npc_startgebaeude(NPC_TYPEN[$typ]['bau'], (int)round($median * $anteil * 0.6), (int)$b['bauplaetze'] * BAULAND_JE_BAUPLATZ - 3);
        $lager = lagerkapazitaet($lv['scheune'] ?? 0);
        $db->prepare('UPDATE bereiche SET besitzer_id = ?, ist_heimat = 1, holz = ?, ziegel = ?, korn = ?, taler = ?, stand_ts = ? WHERE id = ?')
           ->execute([$sid, $lager * 0.3, $lager * 0.3, $lager * 0.3, 500, $jetzt, $b['id']]);
        $ins = $db->prepare('INSERT INTO gebaeude (bereich_id, typ, stufe) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stufe = VALUES(stufe)');
        foreach ($lv as $t => $l) {
            $ins->execute([$b['id'], $t, $l]);
        }
        foreach (NPC_TYPEN[$typ]['verteidigung'] as $t => $je) {
            $n = (int)floor($je * ($lv['hofstelle'] ?? 1));
            if ($n > 0 && !fehlende_voraussetzungen(EINHEITEN[$t]['braucht'], $lv)) {
                einheiten_aendern($db, $b['id'], $t, $n);
            }
        }
        chronik('neuling', ort_von_bereich($db, $b['id']), '', $jetzt);
        return null;
    });
}

/** Gebäudestufen für den Start: reihum nach Plan, bis die Punkte (Baukosten ÷ 1.000) erreicht oder das Bauland knapp ist. */
function npc_startgebaeude(array $plan, int $zielPunkte, int $bauland): array
{
    $lv = START_GEBAEUDE;
    $roh = 0.0;
    foreach ($lv as $t => $l) {
        $roh += kosten_bis('baukosten', $t, $l);
    }
    for ($i = 0; $i < 150 && $roh / PUNKTE_JE < $zielPunkte && array_sum($lv) < $bauland; $i++) {
        $wahl = null;
        foreach ($plan as $k => $t) {
            if (fehlende_voraussetzungen(GEBAEUDE[$t]['braucht'], $lv) || (isset(GEBAEUDE[$t]['max']) && ($lv[$t] ?? 0) >= GEBAEUDE[$t]['max'])) {
                continue;
            }
            $gewicht = ($lv[$t] ?? 0) + $k * 0.3;
            if ($wahl === null || $gewicht < $wahl[1]) {
                $wahl = [$t, $gewicht];
            }
        }
        if ($wahl === null) {
            break;
        }
        $lv[$wahl[0]] = ($lv[$wahl[0]] ?? 0) + 1;
        $roh += array_sum(baukosten($wahl[0], $lv[$wahl[0]]));
    }
    return $lv;
}

/** NPC entfernen, wenn keine Züge von ihm oder zu ihm unterwegs sind. true bei Erfolg. */
function npc_entfernen(int $sid): bool
{
    return in_transaktion(function (PDO $db) use ($sid) {
        $st = $db->prepare('SELECT COUNT(*) FROM zuege z LEFT JOIN bereiche b ON b.id = z.ziel_id WHERE z.spieler_id = ? OR b.besitzer_id = ?');
        $st->execute([$sid, $sid]);
        if ((int)$st->fetchColumn() > 0) {
            return false;
        }
        spieler_daten_loeschen($db, $sid);
        return true;
    });
}

/** Ein Schritt eines NPC: Wachstum anpassen, zurückschlagen, verteidigen, bauen, forschen, handeln. */
function npc_schritt(int $sid, int $jetzt): void
{
    $db = db();
    $st = $db->prepare('SELECT * FROM npc WHERE spieler_id = ?');
    $st->execute([$sid]);
    $n = $st->fetch();
    $st = $db->prepare('SELECT id FROM bereiche WHERE besitzer_id = ? ORDER BY ist_heimat DESC LIMIT 1');
    $st->execute([$sid]);
    $bid = $st->fetchColumn();
    // zuerst den nächsten Termin setzen, damit parallele Aufrufe nicht denselben Schritt doppelt machen
    $db->prepare('UPDATE npc SET naechster_schritt_ts = ?, letzter_schritt_ts = ? WHERE spieler_id = ? AND naechster_schritt_ts <= ?')
       ->execute([$jetzt + (int)round(NPC_TAKT_MIN * 60 / tempo() * (0.8 + mt_rand(0, 400) / 1000)), $jetzt, $sid, $jetzt]);
    if (!$n || !$bid) {
        return;
    }
    $typ = NPC_TYPEN[$n['typ']] ?? NPC_TYPEN['aufbauer'];
    $faktor = (float)$n['prod_faktor'];
    $ziel = (float)$n['ziel_anteil'] * npc_median();
    $punkte = punkte_von($sid, true);
    // einmal am Tag: Wachstumshilfe dem Ziel anpassen
    if ($jetzt - (int)$n['faktor_ts'] >= 86400) {
        $faktor = max(NPC_FAKTOR_GRENZEN[0], min(NPC_FAKTOR_GRENZEN[1], $faktor * ($punkte < $ziel ? 1.2 : 0.8)));
        $db->prepare('UPDATE npc SET prod_faktor = ?, faktor_ts = ? WHERE spieler_id = ?')->execute([$faktor, $jetzt, $sid]);
    }
    // Wachstumshilfe: zusätzliche Produktion für die Zeit seit dem letzten Schritt (höchstens 6 Stunden)
    $stunden = min(6.0, max(0, $jetzt - (int)$n['letzter_schritt_ts']) / 3600);
    if ($faktor > 1 && $stunden > 0) {
        in_transaktion(function (PDO $db) use ($bid, $jetzt, $faktor, $stunden) {
            $z = bereich_aktualisieren($db, $bid, $jetzt);
            $plus = [];
            foreach (['holz', 'ziegel', 'korn', 'taler'] as $r) {
                $menge = max(0.0, $z['rate'][$r]) * ($faktor - 1) * $stunden;
                $plus[$r] = $r === 'taler' ? $menge : min($menge, max(0.0, $z['rate']['lager'] - (float)$z['bereich'][$r]));
            }
            kosten_abziehen($db, $bid, $plus, +1);
        });
    }
    npc_rueckschlag($db, $sid, $bid, $jetzt);
    npc_verteidigung($sid, $bid, $typ);
    if ($punkte < $ziel * 1.05) {
        npc_bauen($sid, $bid, $typ);
        npc_forschen($sid, $bid);
    }
    npc_handeln($sid, $bid);
}

/** Einmal zurückschlagen, wenn ein Angreifer ein Rückschlagrecht eröffnet hat – mit der Hälfte der Truppen. */
function npc_rueckschlag(PDO $db, int $sid, string $bid, int $jetzt): void
{
    $st = $db->prepare("SELECT DISTINCT angreifer_id FROM angriffe_log WHERE verteidiger_id = ? AND ergebnis <> 'verteidiger' AND ts > ?");
    $st->execute([$sid, $jetzt - (int)round(RUECKSCHLAG_H * 3600 / tempo())]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $angreifer) {
        $angreifer = (int)$angreifer;
        if (!rueckschlag_aktiv($db, $angreifer, $sid, $jetzt)) {
            continue;
        }
        $offen = $db->prepare('SELECT COUNT(*) FROM zuege z JOIN bereiche b ON b.id = z.ziel_id WHERE z.spieler_id = ? AND b.besitzer_id = ?');
        $offen->execute([$sid, $angreifer]);
        if ((int)$offen->fetchColumn() > 0) {
            continue;
        }
        $zielSt = $db->prepare('SELECT id FROM bereiche WHERE besitzer_id = ? ORDER BY ist_heimat DESC LIMIT 1');
        $zielSt->execute([$angreifer]);
        $zielId = $zielSt->fetchColumn();
        $z = bereich_zustand($bid);
        $truppe = array_filter(['schuetze' => intdiv((int)($z['einheiten']['schuetze'] ?? 0), 2), 'reiter' => intdiv((int)($z['einheiten']['reiter'] ?? 0), 2)]);
        if ($zielId && array_sum($truppe) >= 2) {
            zug_starten($bid, $sid, (string)$zielId, 'angriff', $truppe, []);
        }
        return;
    }
}

/** Verteidigung auf Soll bringen: Barrikaden, Wachtürme, Sicherheitskräfte je Stufe der Zentrale. */
function npc_verteidigung(int $sid, string $bid, array $typ): void
{
    $z = bereich_zustand($bid);
    if (count($z['werkstatt']) >= 2) {
        return;
    }
    $zentrale = (int)($z['stufen']['hofstelle'] ?? 1);
    foreach ($typ['verteidigung'] as $t => $je) {
        $fehlt = (int)floor($je * $zentrale) - (int)($z['einheiten'][$t] ?? 0);
        if ($fehlt <= 0 || fehlende_voraussetzungen(EINHEITEN[$t]['braucht'], $z['stufen'], $z['fo'])) {
            continue;
        }
        $menge = min($fehlt, 25);
        if (einheiten_bauen($bid, $sid, $t, $menge) !== null && $menge > 1) {
            einheiten_bauen($bid, $sid, $t, intdiv($menge, 2));
        }
        return;
    }
}

/** Ein Gebäude in Auftrag geben: erst Engpässe (Nahrung, Strom, Lager), dann nach der Reihenfolge des Typs. */
function npc_bauen(int $sid, string $bid, array $typ): void
{
    $z = bereich_zustand($bid);
    if ($z['queue']) {
        return;
    }
    $r = $z['rate'];
    $kandidaten = [];
    if ($r['korn'] < 0) {
        $kandidaten[] = 'acker';
    }
    if ($r['eff'] < 1) {
        $kandidaten[] = 'windrad';
    }
    foreach (LAGER_RES as $res) {
        if ((float)$z['bereich'][$res] >= 0.9 * $r['lager']) {
            $kandidaten[] = 'scheune';
            break;
        }
    }
    $plan = $typ['bau'];
    usort($plan, fn($a, $b) => (($z['stufen'][$a] ?? 0) + array_search($a, $typ['bau'], true) * 0.3) <=> (($z['stufen'][$b] ?? 0) + array_search($b, $typ['bau'], true) * 0.3));
    $versuche = 0;
    foreach (array_unique([...$kandidaten, ...$plan]) as $t) {
        if (fehlende_voraussetzungen(GEBAEUDE[$t]['braucht'], $z['stufen'], $z['fo'])) {
            continue;
        }
        if (bau_starten($bid, $sid, $t) === null || ++$versuche >= 3) {
            return;
        }
    }
}

function npc_forschen(int $sid, string $bid): void
{
    if (forschungsauftrag($sid)) {
        return;
    }
    $z = bereich_zustand($bid);
    if ((int)($z['stufen']['dorfschule'] ?? 0) < 1) {
        return;
    }
    $liste = array_filter(NPC_FORSCHUNG, fn($t) => !fehlende_voraussetzungen(FORSCHUNG[$t]['braucht'], $z['stufen'], $z['fo']));
    usort($liste, fn($a, $b) => (int)($z['fo'][$a] ?? 0) <=> (int)($z['fo'][$b] ?? 0));
    if ($liste) {
        forschung_starten($bid, $sid, $liste[0]);
    }
}

/** Überschüsse beim Händler gegen den knappsten Rohstoff tauschen. */
function npc_handeln(int $sid, string $bid): void
{
    $z = bereich_zustand($bid);
    if ((int)($z['stufen']['markt'] ?? 0) < 1) {
        return;
    }
    $fuellung = [];
    foreach (LAGER_RES as $r) {
        $fuellung[$r] = (float)$z['bereich'][$r] / max(1.0, $z['rate']['lager']);
    }
    arsort($fuellung);
    $von = array_key_first($fuellung);
    $nach = array_key_last($fuellung);
    if ($fuellung[$von] >= 0.85 && $von !== $nach) {
        markt_tauschen($bid, $sid, $von, $nach, (int)((float)$z['bereich'][$von] * 0.2));
    }
}
