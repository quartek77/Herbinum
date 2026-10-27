<?php
// Fairness (Version 8): Schutzzeit nach Raubzügen, Stärkeverhältnis, Gegenschlag, Erkundungssperre.
// Die Formeln stehen in rules.php, das Konzept in konzepte/V8-KONZEPT-FAIRNESS.md.
declare(strict_types=1);

/** Gesamtpunkte eines Spielers (einmal je Aufruf berechnet). */
function punkte_von(int $sid, bool $neu = false): int
{
    static $p = null;
    if ($p === null || $neu) {
        $p = punkte_alle();
    }
    return (int)($p[$sid]['gesamt'] ?? 0);
}

/** Stärkeverhältnis r = Punkte Verteidiger / Punkte Angreifer. Hat der Angreifer 0 Punkte, gilt r = 1. */
function staerke_r(int $angreifer, int $verteidiger): float
{
    $a = punkte_von($angreifer);
    return $a <= 0 ? 1.0 : punkte_von($verteidiger) / $a;
}

/** Hat $wer in den letzten 72 h $gegen angegriffen? (Dann darf $gegen ohne Stärke-Grenze zurückschlagen.) */
function hat_zuerst_angegriffen(PDO $db, int $wer, int $gegen, int $ts): bool
{
    $st = $db->prepare('SELECT COUNT(*) FROM angriffe_log WHERE angreifer_id = ? AND verteidiger_id = ? AND ts > ?');
    $st->execute([$wer, $gegen, $ts - GEGENANGRIFF_S]);
    return (bool)$st->fetchColumn();
}

/**
 * Darf $angreifer den Spieler $verteidiger zum Zeitpunkt $ts überfallen? Gibt den Beutefaktor zurück
 * oder eine Fehlermeldung (Text) mit Grund (Schutzzeit, zu schwach).
 */
function raubzug_erlaubt(PDO $db, int $angreifer, int $verteidiger, int $ts): float|string
{
    if ($bis = angriffsschutz_bis($db, $verteidiger, $angreifer, $ts)) {
        return 'Dieser Spieler ist nach einem Überfall noch bis ' . date('d.m. H:i', $bis) . ' Uhr geschützt.';
    }
    if (hat_zuerst_angegriffen($db, $verteidiger, $angreifer, $ts)) {
        return 1.0;     // Gegenschlag: volle Beute, keine Stärke-Grenze
    }
    $f = beutefaktor(staerke_r($angreifer, $verteidiger));
    if ($f === null) {
        return 'Dieser Spieler hat weniger als ' . round(STAERKE_SPERRE * 100) . ' % deiner Punkte – gegen so viel Schwächere gibt es keine Raubzüge.';
    }
    return $f;
}

/** Ende der Schutzzeit des Verteidigers (gegenüber $angreifer) oder null. */
function angriffsschutz_bis(PDO $db, int $verteidiger, ?int $angreifer = null, ?int $ts = null): ?int
{
    $st = $db->prepare('SELECT bis_ts, ausnahmen FROM angriffsschutz WHERE verteidiger_id = ?');
    $st->execute([$verteidiger]);
    $s = $st->fetch();
    if (!$s || (int)$s['bis_ts'] <= ($ts ?? time())) {
        return null;
    }
    if ($angreifer !== null && in_array((string)$angreifer, explode(',', $s['ausnahmen']), true)) {
        return null;    // Gegenschlag gegen jemanden, den der Geschützte selbst angegriffen hat
    }
    return (int)$s['bis_ts'];
}

/** Greift ein Geschützter selbst an, darf ihn genau dieses Ziel während der Schutzzeit zurück angreifen. */
function gegenschlag_freigeben(PDO $db, int $geschuetzter, int $ziel): void
{
    $st = $db->prepare('SELECT bis_ts, ausnahmen FROM angriffsschutz WHERE verteidiger_id = ? FOR UPDATE');
    $st->execute([$geschuetzter]);
    $s = $st->fetch();
    if ($s && (int)$s['bis_ts'] > time()) {
        $liste = array_filter(explode(',', $s['ausnahmen']));
        if (!in_array((string)$ziel, $liste, true)) {
            $liste[] = (string)$ziel;
            $db->prepare('UPDATE angriffsschutz SET ausnahmen = ? WHERE verteidiger_id = ?')->execute([mb_substr(implode(',', $liste), 0, 255), $geschuetzter]);
        }
    }
}

/**
 * Vermögen eines Spielers in Grundwert: Vorräte, Einheiten, Anlagen, Bunker, Auslagerungen in allen Bereichen.
 * Alle Bereiche werden dafür auf den Stand $ts gebracht (läuft in der Transaktion des Raubzugs).
 */
function vermoegen(PDO $db, int $sid, int $ts): float
{
    $st = $db->prepare('SELECT id FROM bereiche WHERE besitzer_id = ?');
    $st->execute([$sid]);
    $w = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $z = bereich_aktualisieren($db, $bid, $ts);
        $w += rohstoff_wert($z['bereich']);
        foreach ($z['einheiten'] + [] as $typ => $n) {
            $w += einheit_wert($typ) * $n;
        }
        foreach ($z['bunker'] as $typ => $n) {
            $w += einheit_wert($typ) * $n;
        }
        foreach ($z['auslagerung'] as $a) {
            foreach (json_decode($a['einheiten'], true) ?: [] as $typ => $n) {
                $w += einheit_wert($typ) * (int)$n;
            }
        }
    }
    return $w;
}

/**
 * Nach einem Raubzug: Schaden protokollieren, Schadensanteil der letzten 24 h berechnen und die Schutzzeit setzen.
 * Gibt ['anteil' => S, 'anteil_24' => S_24, 'schutz_bis' => ts|null] zurück.
 */
function schutz_nach_raubzug(PDO $db, int $angreifer, int $verteidiger, int $ts, string $ergebnis, float $wertVorher, float $verlust): array
{
    $anteil = $wertVorher > 0 ? min(1.0, $verlust / $wertVorher) : 0.0;
    $db->prepare('INSERT INTO angriffe_log (ts, angreifer_id, verteidiger_id, ergebnis, wert_vorher, wert_verlust) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$ts, $angreifer, $verteidiger, $ergebnis, $wertVorher, $verlust]);
    if ($ergebnis === 'verteidiger') {
        return ['anteil' => $anteil, 'anteil_24' => 0.0, 'schutz_bis' => null];     // abgewehrt: kein Schutz nötig
    }
    $st = $db->prepare("SELECT wert_verlust / GREATEST(wert_vorher, 1) FROM angriffe_log WHERE verteidiger_id = ? AND ts > ? AND ts <= ? AND ergebnis <> 'verteidiger'");
    $st->execute([$verteidiger, $ts - SCHUTZ_FENSTER_S, $ts]);
    $s24 = schaden_kombiniert($st->fetchAll(PDO::FETCH_COLUMN));
    $bis = $ts + schutzzeit_s($s24, staerke_r($angreifer, $verteidiger), tempo());
    // Eine laufende Schutzzeit wird nie verkürzt; beginnt eine neue, starten die Gegenschlag-Ausnahmen leer
    $db->prepare('INSERT INTO angriffsschutz (verteidiger_id, bis_ts, ausnahmen) VALUES (?, ?, \'\')
                  ON DUPLICATE KEY UPDATE ausnahmen = IF(bis_ts < ?, \'\', ausnahmen), bis_ts = GREATEST(bis_ts, VALUES(bis_ts))')
       ->execute([$verteidiger, $bis, $ts]);
    return ['anteil' => $anteil, 'anteil_24' => $s24, 'schutz_bis' => angriffsschutz_bis($db, $verteidiger, null, $ts)];
}

/** Ende der Erkundungssperre für $spaeher gegen $ziel oder null. */
function spionage_sperre_bis(PDO $db, int $spaeher, int $ziel, ?int $ts = null): ?int
{
    $st = $db->prepare('SELECT MAX(sperre_bis) FROM spionage_log WHERE spaeher_id = ? AND ziel_id = ?');
    $st->execute([$spaeher, $ziel]);
    $bis = (int)$st->fetchColumn();
    return $bis > ($ts ?? time()) ? $bis : null;
}

/** Ist schon eine Erkundung dieses Spähers zu irgendeinem Bereich des Ziel-Spielers unterwegs? */
function erkundung_unterwegs(PDO $db, int $spaeher, int $ziel): bool
{
    $st = $db->prepare("SELECT COUNT(*) FROM zuege z JOIN bereiche b ON b.id = z.ziel_id
                        WHERE z.spieler_id = ? AND z.auftrag = 'erkunden' AND z.status = 'hin' AND b.besitzer_id = ?");
    $st->execute([$spaeher, $ziel]);
    return (bool)$st->fetchColumn();
}

/** Wiederaufbauhilfe: bis wann läuft der Produktionsbonus des Spielers? */
function wiederaufbau_bis(int $sid): int
{
    $st = db()->prepare('SELECT wiederaufbau_bis_ts FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    return (int)$st->fetchColumn();
}

function wiederaufbau_setzen(PDO $db, int $sid, int $ts): void
{
    $db->prepare('UPDATE spieler SET wiederaufbau_bis_ts = GREATEST(wiederaufbau_bis_ts, ?) WHERE id = ?')
       ->execute([$ts + (int)round(WIEDERAUFBAU_H * 3600 / tempo()), $sid]);
}
