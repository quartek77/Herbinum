<?php
// Einsätze (Version 9): Raubzüge laufen in Phasen – Rüsten, Anmarsch, Spähen, Gefecht, Plündern, Rückmarsch, Regeneration.
// Ein Raubzug belegt eine Einsatzgruppe vom Rüsten bis zum Ende der Regeneration; die Kommandozentrale bringt weitere.
// Wer einen Raubzug verliert, bekommt Blauhelme (neutrale Schutztruppe). Werte in rules.php, Konzept in konzepte/V9-KONZEPT-EINSAETZE.md.
//
// Ein Raubzug in Phasen ist eine Zeile in `zuege` mit gesetzter Spalte `phase`. Solange status = 'hin', ist ankunft_ts das Ende
// der laufenden Phase; ereignisse_abarbeiten() ruft dann einsatz_weiter() auf. Zwischenstände stehen als JSON in `kampf`.
// Züge ohne Phase (vor dem Update losgeschickt) kämpfen wie bisher sofort bei Ankunft.
declare(strict_types=1);

const PHASEN = ['ruesten' => 'Rüsten', 'marsch' => 'Anmarsch', 'spaehen' => 'Spähen', 'gefecht' => 'Gefecht', 'pluendern' => 'Plündern', 'rueckmarsch' => 'Rückmarsch'];

// =====================================================================
// Einsatzgruppen
// =====================================================================

/** Zahl der Einsatzgruppen: 1 + Stufe der Kommandozentrale. */
function einsatzgruppen_zahl(PDO $db, int $sid): int
{
    $st = $db->prepare("SELECT MAX(g.stufe) FROM gebaeude g JOIN bereiche b ON b.id = g.bereich_id WHERE b.besitzer_id = ? AND g.typ = 'kommandozentrale'");
    $st->execute([$sid]);
    return 1 + min(KOMMANDO_MAX_STUFE, (int)$st->fetchColumn());
}

/** Alle Einsatzgruppen mit Zustand: [nr => ['zug_id' => ?int, 'bereit_ab' => int, 'frei' => bool]] */
function einsatzgruppen(PDO $db, int $sid, ?int $ts = null): array
{
    $ts ??= time();
    $st = $db->prepare('SELECT gruppe, zug_id, bereit_ab_ts FROM einsatzgruppen WHERE spieler_id = ?');
    $st->execute([$sid]);
    $zeilen = [];
    foreach ($st->fetchAll() as $r) {
        $zeilen[(int)$r['gruppe']] = $r;
    }
    $out = [];
    for ($g = 1, $n = einsatzgruppen_zahl($db, $sid); $g <= $n; $g++) {
        $r = $zeilen[$g] ?? null;
        $zug = $r && $r['zug_id'] !== null ? (int)$r['zug_id'] : null;
        $bereit = $r ? (int)$r['bereit_ab_ts'] : 0;
        $out[$g] = ['zug_id' => $zug, 'bereit_ab' => $bereit, 'frei' => $zug === null && $bereit <= $ts];
    }
    return $out;
}

function freie_einsatzgruppe(PDO $db, int $sid, int $ts): ?int
{
    foreach (einsatzgruppen($db, $sid, $ts) as $g => $x) {
        if ($x['frei']) {
            return $g;
        }
    }
    return null;
}

/** Wann wird die nächste Einsatzgruppe frei? null, solange alle noch unterwegs sind. */
function naechste_einsatzgruppe_ab(PDO $db, int $sid, int $ts): ?int
{
    $ab = null;
    foreach (einsatzgruppen($db, $sid, $ts) as $x) {
        if ($x['zug_id'] === null) {
            $ab = $ab === null ? $x['bereit_ab'] : min($ab, $x['bereit_ab']);
        }
    }
    return $ab;
}

function einsatzgruppe_belegen(PDO $db, int $sid, int $gruppe, int $zugId): void
{
    $db->prepare('INSERT INTO einsatzgruppen (spieler_id, gruppe, zug_id, bereit_ab_ts) VALUES (?, ?, ?, 0)
                  ON DUPLICATE KEY UPDATE zug_id = VALUES(zug_id), bereit_ab_ts = 0')->execute([$sid, $gruppe, $zugId]);
}

function einsatzgruppe_freigeben(PDO $db, int $sid, int $gruppe, int $bereitAb): void
{
    if ($gruppe < 1) {
        return;
    }
    $db->prepare('INSERT INTO einsatzgruppen (spieler_id, gruppe, zug_id, bereit_ab_ts) VALUES (?, ?, NULL, ?)
                  ON DUPLICATE KEY UPDATE zug_id = NULL, bereit_ab_ts = VALUES(bereit_ab_ts)')->execute([$sid, $gruppe, $bereitAb]);
}

// =====================================================================
// Kampfkraft und Blauhelme
// =====================================================================

/** Kampfkraft einer Seite: Angriff plus ein Viertel der Lebenspunkte, mit Forschung, Schutzwall, Wehrkraft und Nacht. */
function kampfkraft(array $einheiten, array $fo, bool $verteidiger, int $graben = 0, int $wehr = 50, bool $nacht = false): float
{
    [$atk, $hp] = kampfwerte($fo, $verteidiger, $graben, $wehr, $nacht);
    $k = 0.0;
    foreach ($einheiten as $t => $n) {
        $d = einheit_def($t);
        $k += (int)$n * ($d['angriff'] * $atk + $d['leben'] * $hp / 4);
    }
    return $k;
}

function blauhelme_zeile(PDO $db, string $bid): ?array
{
    $st = $db->prepare('SELECT * FROM blauhelme WHERE bereich_id = ?');
    $st->execute([$bid]);
    return $st->fetch() ?: null;
}

/** Reststärke nach linearem Abbau. */
function blauhelme_rest(array $r, int $ts): float
{
    $ab = (int)$r['ab_ts'];
    $bis = (int)$r['bis_ts'];
    return $ts >= $bis || $bis <= $ab ? 0.0 : (float)$r['staerke'] * ($bis - max($ts, $ab)) / ($bis - $ab);
}

/** Kampfkraft der Blauhelme in einem Bereich. Ist die Verlustgrenze des Tages erreicht, stehen sie in voller Stärke da. */
function blauhelme_kraft(PDO $db, string $bid, int $verteidiger, int $ts): float
{
    $r = blauhelme_zeile($db, $bid);
    if (!$r) {
        return 0.0;
    }
    $k = blauhelme_rest($r, $ts);
    if ((int)$r['spitze_ts'] > $ts - schutz_fenster_s(tempo()) && verlust_stand($db, $verteidiger, $ts)['verlust'] >= VERLUST_MAX_TAG - 1e-6) {
        $k = max($k, (float)$r['spitze']);
    }
    return $k;
}

function blauhelme_anzahl(float $kraft): int
{
    return $kraft > 0 ? (int)ceil($kraft / blauhelm_k()) : 0;
}

/** Nach einer Niederlage rücken Blauhelme an – oder werden verstärkt (bis 100 % der Kampfkraft des Siegers). */
function blauhelme_setzen(PDO $db, string $bid, int $ts, float $kAngreifer): void
{
    if ($kAngreifer <= 0) {
        return;
    }
    $r = blauhelme_zeile($db, $bid);
    $rest = $r ? blauhelme_rest($r, $ts) : 0.0;
    $neu = max($rest, min(1.0, $rest / $kAngreifer + BLAUHELM_ANTEIL) * $kAngreifer);
    $spitze = $r && (int)$r['spitze_ts'] > $ts - schutz_fenster_s(tempo()) ? max((float)$r['spitze'], $kAngreifer) : $kAngreifer;
    $db->prepare('INSERT INTO blauhelme (bereich_id, staerke, ab_ts, bis_ts, spitze, spitze_ts) VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE staerke = VALUES(staerke), ab_ts = VALUES(ab_ts), bis_ts = VALUES(bis_ts), spitze = VALUES(spitze), spitze_ts = VALUES(spitze_ts)')
       ->execute([$bid, $neu, $ts, $ts + (int)round(BLAUHELM_H * 3600 / tempo()), $spitze, $ts]);
}

/** Blauhelme in Prozent der ursprünglichen Stärke (für Anzeigen) oder 0. */
function blauhelme_prozent(PDO $db, string $bid, int $ts): int
{
    $r = blauhelme_zeile($db, $bid);
    return $r && (float)$r['staerke'] > 0 ? (int)round(100 * blauhelme_rest($r, $ts) / (float)$r['staerke']) : 0;
}

/** Wer verteidigt? Einheiten im Bereich plus Blauhelme – die greifen gegen ein Rückschlagrecht nicht ein. */
function einsatz_verteidiger(PDO $db, int $angreifer, array $ziel, int $besitzer, int $ts): array
{
    $einheiten = array_filter($ziel['einheiten']);
    if (!rueckschlag_aktiv($db, $besitzer, $angreifer, $ts)) {
        $n = blauhelme_anzahl(blauhelme_kraft($db, $ziel['bereich']['id'], $besitzer, $ts));
        if ($n > 0) {
            $einheiten['blauhelm'] = $n;
        }
    }
    return $einheiten;
}

// =====================================================================
// Start und Abbruch
// =====================================================================

/** Raubzug anlegen: Rüsten beginnt, die Truppen bleiben bis zum Ausrücken im Bereich. Läuft in der Transaktion von zug_starten. */
function einsatz_anlegen(PDO $db, int $sid, string $startId, string $zielId, array $einheiten, array $fo, int $gruppe, int $verbrauch, int $jetzt): void
{
    $ende = $jetzt + ruestzeit_s(array_sum($einheiten), $fo, tempo());
    $db->prepare("INSERT INTO zuege (spieler_id, start_id, ziel_id, auftrag, status, abflug_ts, ankunft_ts, rueckkehr_ts, einheiten, phase, gruppe, kampf)
                  VALUES (?, ?, ?, 'angriff', 'hin', ?, ?, ?, ?, 'ruesten', ?, ?)")
       ->execute([$sid, $startId, $zielId, $jetzt, $ende, $ende, json_encode($einheiten), $gruppe, json_encode(['verbrauch' => $verbrauch])]);
    einsatzgruppe_belegen($db, $sid, $gruppe, (int)$db->lastInsertId());
}

/** Einheiten, die in einem Bereich gerade für Raubzüge rüsten (noch da, aber schon verplant). */
function reservierte_einheiten(PDO $db, string $bid): array
{
    $st = $db->prepare("SELECT einheiten FROM zuege WHERE start_id = ? AND phase = 'ruesten' AND status = 'hin'");
    $st->execute([$bid]);
    $r = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach (json_decode($json, true) ?: [] as $t => $n) {
            $r[$t] = ($r[$t] ?? 0) + (int)$n;
        }
    }
    return $r;
}

/** Abbruch durch den Spieler: im Rüsten kostenlos, auf dem Marsch und beim Spähen mit halber Regeneration. */
function einsatz_abbrechen(PDO $db, array $zug, int $jetzt): ?string
{
    $d = einsatz_daten($zug);
    $sid = (int)$zug['spieler_id'];
    switch ($zug['phase']) {
        case 'ruesten':
            bereich_aktualisieren($db, $zug['start_id'], $jetzt);
            kosten_abziehen($db, $zug['start_id'], ['korn' => (float)($d['verbrauch'] ?? 0)], +1);
            $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
            einsatzgruppe_freigeben($db, $sid, (int)$zug['gruppe'], $jetzt);
            return null;
        case 'marsch':
            $d['regen_faktor'] = REGENERATION_ABBRUCH;
            einsatz_speichern($db, (int)$zug['id'], ['status' => 'zurueck', 'phase' => 'rueckmarsch', 'ankunft_ts' => $jetzt,
                'rueckkehr_ts' => $jetzt + ($jetzt - (int)$zug['abflug_ts'])], $d);
            return null;
        case 'spaehen':
            $d['regen_faktor'] = REGENERATION_ABBRUCH;
            einsatz_speichern($db, (int)$zug['id'], ['status' => 'zurueck', 'phase' => 'rueckmarsch', 'ankunft_ts' => $jetzt,
                'rueckkehr_ts' => $jetzt + (int)($d['marsch_s'] ?? 0)], $d);
            $besitzer = bereich_zeile($db, $zug['ziel_id'])['besitzer_id'] ?? null;
            if ($besitzer) {
                nachricht($db, (int)$besitzer, $jetzt, 'warnung', 'Entwarnung: Die Truppen vor ' . bereich_name($db, $zug['ziel_id']) . ' ziehen ab', ['text' => 'Der Angreifer hat sich nach dem Spähen zurückgezogen.']);
            }
            return null;
    }
    return 'Im Gefecht gibt es kein Zurück – der Raubzug muss zu Ende gebracht werden.';
}

// =====================================================================
// Phasen
// =====================================================================

function einsatz_daten(array $zug): array
{
    return json_decode((string)($zug['kampf'] ?? ''), true) ?: [];
}

function einsatz_speichern(PDO $db, int $zugId, array $felder, array $daten): void
{
    $felder['kampf'] = json_encode($daten, JSON_UNESCAPED_UNICODE);
    $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($felder)));
    $db->prepare("UPDATE zuege SET {$set} WHERE id = ?")->execute([...array_values($felder), $zugId]);
}

/** Nächster Schritt eines Raubzugs, dessen Phase abgelaufen ist (status = 'hin'). */
function einsatz_weiter(PDO $db, array $zug): void
{
    match ($zug['phase']) {
        'ruesten' => einsatz_ausruecken($db, $zug),
        'marsch' => einsatz_ankunft($db, $zug),
        'spaehen' => einsatz_gefecht($db, $zug),
        'gefecht' => einsatz_gefecht_ende($db, $zug),
        'pluendern' => einsatz_pluenderung_ende($db, $zug),
        default => $db->prepare("UPDATE zuege SET status = 'zurueck' WHERE id = ?")->execute([$zug['id']]),
    };
}

function spieler_name(PDO $db, int $sid): string
{
    $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    return (string)$st->fetchColumn();
}

/** Kann der Raubzug weitergehen? Gibt den Grund zurück, wenn nicht. */
function einsatz_hindernis(PDO $db, int $sid, ?int $besitzer, int $ts): ?string
{
    if ($besitzer === null || $besitzer === $sid) {
        return 'Dort gibt es niemanden mehr zu überfallen.';
    }
    if (gleiche_genossenschaft($db, $sid, $besitzer)) {
        return 'Ihr seid inzwischen in derselben Allianz.';
    }
    $erlaubt = raubzug_erlaubt($db, $sid, $besitzer, $ts);
    return is_string($erlaubt) ? $erlaubt : null;
}

/** Umkehren ohne Kampf (Ziel nicht mehr angreifbar): zurück mit halber Regeneration. */
function einsatz_umkehren(PDO $db, array $zug, int $ts, string $grund): void
{
    $d = einsatz_daten($zug);
    $d['regen_faktor'] = REGENERATION_ABBRUCH;
    einsatz_speichern($db, (int)$zug['id'], ['status' => 'zurueck', 'phase' => 'rueckmarsch', 'rueckkehr_ts' => $ts + (int)($d['marsch_s'] ?? 0)], $d);
    nachricht($db, (int)$zug['spieler_id'], $ts, 'kampf', 'Raubzug nach ' . bereich_name($db, $zug['ziel_id']) . ' kehrt um', ['text' => $grund]);
}

/** Ende des Rüstens: Die Truppen verlassen den Bereich – so viele, wie noch da sind. */
function einsatz_ausruecken(PDO $db, array $zug): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $d = einsatz_daten($zug);
    $z = bereich_aktualisieren($db, $zug['start_id'], $ts);
    $eigen = (int)$z['bereich']['besitzer_id'] === $sid;
    $soll = zug_einheiten($zug);
    $ist = [];
    foreach ($eigen ? $soll : [] as $t => $n) {
        if (($m = min((int)$n, (int)($z['einheiten'][$t] ?? 0))) > 0) {
            $ist[$t] = $m;
        }
    }
    $zielName = bereich_name($db, $zug['ziel_id']);
    if (!array_filter($ist, fn($n, $t) => EINHEITEN[$t]['angriff'] > 0, ARRAY_FILTER_USE_BOTH)) {
        if ($eigen) {
            kosten_abziehen($db, $zug['start_id'], ['korn' => (float)($d['verbrauch'] ?? 0)], +1);
        }
        $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
        einsatzgruppe_freigeben($db, $sid, (int)$zug['gruppe'], $ts);
        nachricht($db, $sid, $ts, 'kampf', "Raubzug nach {$zielName} abgesagt", ['text' => 'Beim Ausrücken standen keine Sicherheitskräfte oder Einsatzteams mehr bereit. Die Verpflegung ist zurück im Lager.']);
        return;
    }
    foreach ($ist as $t => $n) {
        einheiten_aendern($db, $zug['start_id'], $t, -$n);
    }
    if ($ist != $soll) {
        $d['fehlten'] = true;
    }
    $ziel = bereich_zeile($db, $zug['ziel_id']);
    $marsch = (int)round(reisezeit(strecke_km($z['bereich'], $ziel), zug_tempo($ist, $z['fo']), tempo()) * ereignis_zugfaktor($z['bereich'], $ts));
    $d['marsch_s'] = $marsch;
    einsatz_speichern($db, (int)$zug['id'], ['phase' => 'marsch', 'einheiten' => json_encode($ist), 'abflug_ts' => $ts,
        'ankunft_ts' => $ts + $marsch, 'rueckkehr_ts' => $ts + 2 * $marsch], $d);
}

/** Ankunft am Ziel: Spähphase beginnt, der Verteidiger wird gewarnt. */
function einsatz_ankunft(PDO $db, array $zug): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $d = einsatz_daten($zug);
    $ziel = bereich_aktualisieren($db, $zug['ziel_id'], $ts);
    $besitzer = $ziel['bereich']['besitzer_id'] === null ? null : (int)$ziel['bereich']['besitzer_id'];
    if ($grund = einsatz_hindernis($db, $sid, $besitzer, $ts)) {
        einsatz_umkehren($db, $zug, $ts, $grund);
        return;
    }
    $foA = forschung_laden($db, $sid);
    $foV = forschung_laden($db, $besitzer);
    $ende = $ts + spaehzeit_s($foA, tempo());
    $vert = einsatz_verteidiger($db, $sid, $ziel, $besitzer, $ts);
    $kV = kampfkraft($vert, $foV, true, (int)($ziel['stufen']['graben'] ?? 0), (int)$ziel['bereich']['wehr'], ist_nacht($ts));
    $kA = kampfkraft(zug_einheiten($zug), $foA, false);
    $d['schaetzung'] = spanne($kV, 0.2, mt_rand(0, 1000) / 1000);
    $d['k_a'] = round($kA);
    einsatz_speichern($db, (int)$zug['id'], ['phase' => 'spaehen', 'ankunft_ts' => $ende], $d);

    $zielName = bereich_name($db, $zug['ziel_id']);
    $angreifer = spieler_name($db, $sid);
    $kuerzel = gen_von($sid)['kuerzel'] ?? '';
    nachricht($db, $besitzer, $ts, 'warnung', "⚠ {$angreifer} späht {$zielName} aus", ['text' => "Truppen von {$angreifer}" . ($kuerzel ? " [{$kuerzel}]" : '')
        . " stehen vor {$zielName}. Das Gefecht beginnt frühestens um " . date('H:i', $ende) . ' Uhr. Bring Einheiten in den Bunker, verlege sie oder verstärke die Verteidigung.', 'gefecht_ab' => $ende]);
    nachricht($db, $sid, $ts, 'bericht', "Späher vor {$zielName}", ['text' => 'Deine Späher schätzen die Verteidigung auf ' . zahl($d['schaetzung'][0]) . '–' . zahl($d['schaetzung'][1])
        . ' Kampfkraft, deine Truppe hat ' . zahl($kA) . '.' . (isset($vert['blauhelm']) ? ' Blauhelme sind vor Ort.' : '') . ' Bis ' . date('H:i', $ende) . ' Uhr kannst du unter Züge noch abbrechen.']);
}

/** Ende des Spähens: Das Gefecht beginnt. Der Ausgang wird jetzt berechnet und am Ende des Gefechts gebucht. */
function einsatz_gefecht(PDO $db, array $zug): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $d = einsatz_daten($zug);
    $ziel = bereich_aktualisieren($db, $zug['ziel_id'], $ts);
    $besitzer = $ziel['bereich']['besitzer_id'] === null ? null : (int)$ziel['bereich']['besitzer_id'];
    if ($grund = einsatz_hindernis($db, $sid, $besitzer, $ts)) {
        einsatz_umkehren($db, $zug, $ts, $grund);
        return;
    }
    $foA = forschung_laden($db, $sid);
    $foV = forschung_laden($db, $besitzer);
    $nacht = ist_nacht($ts);
    $graben = (int)($ziel['stufen']['graben'] ?? 0);
    $wehr = (int)$ziel['bereich']['wehr'];
    $vert = einsatz_verteidiger($db, $sid, $ziel, $besitzer, $ts);
    $kA = kampfkraft(zug_einheiten($zug), $foA, false);
    $kV = kampfkraft($vert, $foV, true, $graben, $wehr, $nacht);
    $dauer = gefechtszeit_s($kA / max(1.0, $kV), tempo());
    $d += ['verteidiger' => $besitzer, 'beutefaktor' => (float)raubzug_erlaubt($db, $sid, $besitzer, $ts), 'wert_vorher' => vermoegen($db, $besitzer, $ts),
           'ergebnis' => kampf(zug_einheiten($zug), $vert, $foA, $foV, $graben, $wehr, null, $nacht),
           'k_a' => round($kA), 'k_v' => round($kV), 'nacht' => $nacht, 'graben' => $graben, 'wehr' => $wehr,
           'blauhelme' => (int)($vert['blauhelm'] ?? 0), 'gefecht_s' => $dauer];
    einsatz_speichern($db, (int)$zug['id'], ['phase' => 'gefecht', 'ankunft_ts' => $ts + $dauer], $d);
}

/** Ende des Gefechts: Verluste buchen; Sieger plündern, alle anderen ziehen ab. */
function einsatz_gefecht_ende(PDO $db, array $zug): void
{
    $ts = (int)$zug['ankunft_ts'];
    $sid = (int)$zug['spieler_id'];
    $d = einsatz_daten($zug);
    $erg = $d['ergebnis'];
    $ziel = bereich_aktualisieren($db, $zug['ziel_id'], $ts);
    $zb = $ziel['bereich'];
    // Verteidiger: Verluste buchen (höchstens, was noch da ist); Anlagen wachsen teilweise nach; Blauhelme fallen nicht
    $nachgewachsen = [];
    $verlust = 0.0;
    foreach ($erg['v'] as $typ => [$vor, $nach]) {
        if (!isset(EINHEITEN[$typ])) {
            continue;
        }
        $neu = $nach;
        if (!EINHEITEN[$typ]['beweglich'] && $vor > $nach) {
            $nachgewachsen[$typ] = (int)round(($vor - $nach) * WIEDERAUFBAU_ANTEIL);
            $neu += $nachgewachsen[$typ];
        }
        $delta = max($neu - $vor, -(int)($ziel['einheiten'][$typ] ?? 0));
        einheiten_aendern($db, $zb['id'], $typ, $delta);
        $verlust += max(0, -$delta) * einheit_wert($typ);
    }
    $ueberlebende = array_filter(array_map(fn($p) => $p[1], $erg['a']));
    $d['gefallen'] = (int)array_sum(array_map(fn($p) => $p[0] - $p[1], $erg['a']));
    $d['verlust'] = $verlust;
    $d['nachgewachsen'] = $nachgewachsen;
    if ($erg['sieger'] === 'angreifer' && $ueberlebende) {
        $verteidiger = (int)$d['verteidiger'];
        $tresor = tresor($ziel['rate'], forschung_laden($db, $verteidiger));
        // Verlustdeckel: dieser Angriff darf höchstens min(Einzelgrenze, Spielraum bis zur Tagesgrenze) des Vermögens kosten
        $grenze = erlaubter_verlust($db, $sid, $verteidiger, $ts);
        $beute = beute($zb, zug_ladung($ueberlebende), $tresor, (float)$d['beutefaktor']);
        $budget = max(0.0, $grenze * (float)$d['wert_vorher'] - $verlust);
        $wert = rohstoff_wert($beute);
        $d['gekappt'] = $wert > $budget;
        if ($d['gekappt']) {
            $beute = array_map(fn($x) => floor($x * $budget / $wert), $beute);
        }
        $d['beute_plan'] = $beute;
        $d['tresor'] = array_map('floor', $tresor);
        einsatz_speichern($db, (int)$zug['id'], ['phase' => 'pluendern', 'einheiten' => json_encode($ueberlebende),
            'ankunft_ts' => $ts + pluenderzeit_s(array_sum($beute), tempo())], $d);
        return;
    }
    einsatz_abschliessen($db, $zug, $d, $ueberlebende, array_fill_keys(array_keys(RESSOURCEN), 0.0), $ts);
}

/** Ende der Plünderung: Beute aus dem Vorrat nehmen, der jetzt noch da ist (ohne Tresor). */
function einsatz_pluenderung_ende(PDO $db, array $zug): void
{
    $ts = (int)$zug['ankunft_ts'];
    $d = einsatz_daten($zug);
    $zb = bereich_aktualisieren($db, $zug['ziel_id'], $ts)['bereich'];
    $beute = [];
    foreach (RESSOURCEN as $r => $_) {
        $beute[$r] = max(0.0, min((float)($d['beute_plan'][$r] ?? 0), floor((float)$zb[$r] - (float)($d['tresor'][$r] ?? 0))));
    }
    kosten_abziehen($db, $zb['id'], $beute);
    $d['verlust'] = (float)$d['verlust'] + rohstoff_wert($beute);
    wiederaufbau_setzen($db, (int)$d['verteidiger'], $ts);
    einsatz_abschliessen($db, $zug, $d, zug_einheiten($zug), $beute, $ts);
}

/** Abrechnung nach Gefecht (und Plünderung): Protokoll, Blauhelme, Berichte, Rückmarsch. */
function einsatz_abschliessen(PDO $db, array $zug, array $d, array $ueberlebende, array $beute, int $ts): void
{
    $sid = (int)$zug['spieler_id'];
    $verteidiger = (int)$d['verteidiger'];
    $erg = $d['ergebnis'];
    $schutz = schutz_nach_raubzug($db, $sid, $verteidiger, $ts, $erg['sieger'], (float)$d['wert_vorher'], (float)$d['verlust']);
    if ($erg['sieger'] === 'angreifer') {
        blauhelme_setzen($db, $zug['ziel_id'], $ts, (float)$d['k_a']);
    }
    if ($ueberlebende) {
        einsatz_speichern($db, (int)$zug['id'], ['status' => 'zurueck', 'phase' => 'rueckmarsch', 'einheiten' => json_encode($ueberlebende),
            'holz' => $beute['holz'], 'ziegel' => $beute['ziegel'], 'korn' => $beute['korn'], 'taler' => $beute['taler'],
            'rueckkehr_ts' => $ts + (int)($d['marsch_s'] ?? 0)], $d);
    } else {
        $db->prepare('DELETE FROM zuege WHERE id = ?')->execute([$zug['id']]);
        einsatzgruppe_freigeben($db, $sid, (int)$zug['gruppe'], $ts + regenerationszeit_s((int)$d['gefallen'], forschung_laden($db, $sid), tempo()));
    }
    $zielName = bereich_name($db, $zug['ziel_id']);
    $bericht = $erg + ['ort' => $zielName, 'angreifer' => spieler_name($db, $sid), 'verteidiger' => spieler_name($db, $verteidiger),
                       'beute' => $beute, 'nachgewachsen' => $d['nachgewachsen'] ?? [], 'graben' => (int)$d['graben'], 'wehr' => (int)$d['wehr'],
                       'nacht' => (bool)$d['nacht'], 'beutefaktor' => round((float)$d['beutefaktor'], 2), 'tresor' => $d['tresor'] ?? [],
                       'schaden' => round($schutz['anteil'], 3), 'schaden_24' => round($schutz['anteil_24'], 3), 'spielraum' => round($schutz['spielraum'], 3),
                       'gekappt' => !empty($d['gekappt']), 'wiederaufbau' => $erg['sieger'] === 'angreifer',
                       'blauhelme' => (int)$d['blauhelme'], 'gefecht_s' => (int)$d['gefecht_s'], 'k_a' => (int)$d['k_a'], 'k_v' => (int)$d['k_v'],
                       'blauhelme_neu' => $erg['sieger'] === 'angreifer'];
    chronik('raubzug', ort_von_bereich($db, $zug['ziel_id']), $erg['sieger'], $ts);
    $ausgang = ['angreifer' => 'gewonnen', 'verteidiger' => 'verloren', 'unentschieden' => 'unentschieden'][$erg['sieger']];
    nachricht($db, $sid, $ts, 'kampf', "Raubzug auf {$zielName}: {$ausgang}", $bericht + ['seite' => 'angreifer']);
    $ausgangV = ['angreifer' => 'Überfall verloren', 'verteidiger' => 'Überfall abgewehrt', 'unentschieden' => 'Überfall unentschieden'][$erg['sieger']];
    nachricht($db, $verteidiger, $ts, 'kampf', "{$ausgangV}: {$bericht['angreifer']} in {$zielName}", $bericht + ['seite' => 'verteidiger']);
}

/** Rückkehr eines Raubzugs: Wartung bezahlen, Einsatzgruppe regeneriert. Einheiten und Beute hat zug_rueckkehr schon gebucht. */
function einsatz_heimkehr(PDO $db, array $zug, array $start): void
{
    $ts = (int)$zug['rueckkehr_ts'];
    $sid = (int)$zug['spieler_id'];
    $d = einsatz_daten($zug);
    $regen = regenerationszeit_s((int)($d['gefallen'] ?? 0), $start['fo'], tempo()) * (float)($d['regen_faktor'] ?? 1.0);
    $wartung = wartungskosten(zug_einheiten($zug));
    $b = $start['bereich'];
    $bezahlt = (float)$b['korn'] + (float)$zug['korn'] >= $wartung['korn'] && (float)$b['taler'] + (float)$zug['taler'] >= $wartung['taler'];
    if ($bezahlt) {
        kosten_abziehen($db, $zug['start_id'], $wartung);
    } else {
        $regen *= REGENERATION_OHNE_WARTUNG;
    }
    $bereit = $ts + (int)round($regen);
    einsatzgruppe_freigeben($db, $sid, (int)$zug['gruppe'], $bereit);
    nachricht($db, $sid, $ts, 'transport', 'Einsatzgruppe ' . (int)$zug['gruppe'] . ' zurück in ' . bereich_name($db, $zug['start_id']), [
        'text' => ($bezahlt ? 'Wartung, Tanken und Munition kosten ' . zahl($wartung['taler']) . ' Euro und ' . zahl($wartung['korn']) . ' Nahrung.'
                            : 'Für die Wartung fehlten Euro oder Nahrung – sie dauert länger.')
                  . ' Die Gruppe ist ab ' . date('d.m. H:i', $bereit) . ' Uhr wieder einsatzbereit. Verteidigen können die Einheiten schon jetzt.',
        'einheiten' => zug_einheiten($zug), 'ladung' => array_map('floor', ['holz' => (float)$zug['holz'], 'ziegel' => (float)$zug['ziegel'], 'korn' => (float)$zug['korn'], 'taler' => (float)$zug['taler']])]);
}

// =====================================================================
// Anzeigen
// =====================================================================

/** Raubzüge, die gerade vor einem eigenen Bereich stehen (Spähen, Gefecht, Plündern) – für das Warnband. */
function bedrohungen(int $sid): array
{
    $st = db()->prepare("SELECT z.*, s.name AS absender FROM zuege z JOIN bereiche b ON b.id = z.ziel_id JOIN spieler s ON s.id = z.spieler_id
                         WHERE b.besitzer_id = ? AND z.status = 'hin' AND z.phase IN ('spaehen', 'gefecht', 'pluendern') ORDER BY z.ankunft_ts");
    $st->execute([$sid]);
    return $st->fetchAll();
}

/** Text für die Phase eines eigenen oder fremden Raubzugs. */
function phase_text(array $zug): string
{
    if (($zug['phase'] ?? '') === '') {
        return $zug['status'] === 'hin' ? 'Anmarsch' : 'Rückmarsch';
    }
    return $zug['status'] === 'zurueck' ? 'Rückmarsch' : (PHASEN[$zug['phase']] ?? $zug['phase']);
}
