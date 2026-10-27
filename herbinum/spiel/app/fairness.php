<?php
// Fairness (Version 8): persönliche Sperre nach Raubzügen, gemeinsamer Verlustdeckel pro Opfer, Stärkeverhältnis,
// Rückschlagrecht, Erkundungssperre. Die Formeln stehen in rules.php, das Konzept in konzepte/V8-KONZEPT-FAIRNESS.md.
//
// Zwei getrennte Regeln, damit niemand andere aussperren kann:
//  1. Persönliche Sperre: Nur wer angegriffen hat, ist für dieses Opfer eine Zeit lang gesperrt (je nach Schaden).
//  2. Verlustdeckel: Ein Opfer verliert im Zeitfenster höchstens VERLUST_MAX_TAG, durch einen Angriff höchstens
//     VERLUST_MAX_ANGRIFF. Ist der Spielraum fast aufgebraucht, ist es für alle geschützt (Vollschutz).
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

/** Hat $wer in den letzten 72 h $gegen angegriffen? (Dann gilt für $gegen keine Stärke-Grenze.) */
function hat_zuerst_angegriffen(PDO $db, int $wer, int $gegen, int $ts): bool
{
    $st = $db->prepare('SELECT COUNT(*) FROM angriffe_log WHERE angreifer_id = ? AND verteidiger_id = ? AND ts > ?');
    $st->execute([$wer, $gegen, $ts - GEGENANGRIFF_S]);
    return (bool)$st->fetchColumn();
}

/** Verlustanteile des Opfers im Zeitfenster (ohne abgewehrte Angriffe), älteste zuerst: [[ts, anteil], …] */
function verluste_im_fenster(PDO $db, int $verteidiger, int $ts): array
{
    $st = $db->prepare("SELECT ts, wert_verlust / GREATEST(wert_vorher, 1) AS anteil FROM angriffe_log
                        WHERE verteidiger_id = ? AND ts > ? AND ts <= ? AND ergebnis <> 'verteidiger' ORDER BY ts, id");
    $st->execute([$verteidiger, $ts - schutz_fenster_s(tempo()), $ts]);
    return array_map(fn($r) => [(int)$r['ts'], (float)$r['anteil']], $st->fetchAll());
}

/** Verlust im Zeitfenster (kombiniert) und verbleibender Spielraum bis zur Tagesgrenze. */
function verlust_stand(PDO $db, int $verteidiger, int $ts): array
{
    $s = schaden_kombiniert(array_column(verluste_im_fenster($db, $verteidiger, $ts), 1));
    return ['verlust' => $s, 'spielraum' => max(0.0, VERLUST_MAX_TAG - $s)];
}

/**
 * Vollschutz: Ist die Tagesgrenze (fast) erreicht, kann niemand angreifen. Gibt zurück, bis wann – also bis so viele
 * ältere Verluste aus dem Zeitfenster gefallen sind, dass wieder mindestens VOLLSCHUTZ_REST Spielraum bleibt – oder null.
 */
function vollschutz_bis(PDO $db, int $verteidiger, ?int $ts = null): ?int
{
    $ts ??= time();
    $liste = verluste_im_fenster($db, $verteidiger, $ts);
    if (VERLUST_MAX_TAG - schaden_kombiniert(array_column($liste, 1)) >= VOLLSCHUTZ_REST) {
        return null;
    }
    while ($liste) {
        [$alt] = array_shift($liste);
        if (VERLUST_MAX_TAG - schaden_kombiniert(array_column($liste, 1)) >= VOLLSCHUTZ_REST) {
            return $alt + schutz_fenster_s(tempo());
        }
    }
    return null;
}

function sperre_zeile(PDO $db, int $verteidiger, int $angreifer): array
{
    $st = $db->prepare('SELECT bis_ts, frei_bis_ts FROM angriffssperre WHERE verteidiger_id = ? AND angreifer_id = ?');
    $st->execute([$verteidiger, $angreifer]);
    return $st->fetch() ?: ['bis_ts' => 0, 'frei_bis_ts' => 0];
}

/** Hat $angreifer gerade ein Rückschlagrecht gegen $verteidiger (weil der ihn angegriffen hat)? */
function rueckschlag_aktiv(PDO $db, int $verteidiger, int $angreifer, int $ts): bool
{
    return (int)sperre_zeile($db, $verteidiger, $angreifer)['frei_bis_ts'] > $ts;
}

/** Ende der persönlichen Sperre von $angreifer gegen $verteidiger oder null. */
function persoenliche_sperre_bis(PDO $db, int $verteidiger, int $angreifer, ?int $ts = null): ?int
{
    $bis = (int)sperre_zeile($db, $verteidiger, $angreifer)['bis_ts'];
    return $bis > ($ts ?? time()) ? $bis : null;
}

/**
 * Bis wann kann $angreifer das Opfer nicht überfallen? (persönliche Sperre oder Vollschutz; ohne $angreifer nur Vollschutz)
 * Ein Rückschlagrecht hebt beides auf.
 */
function angriffsschutz_bis(PDO $db, int $verteidiger, ?int $angreifer = null, ?int $ts = null): ?int
{
    $ts ??= time();
    if ($angreifer !== null && rueckschlag_aktiv($db, $verteidiger, $angreifer, $ts)) {
        return null;
    }
    $bis = array_filter([$angreifer !== null ? persoenliche_sperre_bis($db, $verteidiger, $angreifer, $ts) : null, vollschutz_bis($db, $verteidiger, $ts)]);
    return $bis ? max($bis) : null;
}

/**
 * Darf $angreifer den Spieler $verteidiger zum Zeitpunkt $ts überfallen? Gibt den Beutefaktor zurück
 * oder eine Fehlermeldung (Text) mit Grund (Sperre, Tagesgrenze, zu schwach).
 */
function raubzug_erlaubt(PDO $db, int $angreifer, int $verteidiger, int $ts): float|string
{
    if (!rueckschlag_aktiv($db, $verteidiger, $angreifer, $ts)) {
        if ($bis = persoenliche_sperre_bis($db, $verteidiger, $angreifer, $ts)) {
            return 'Du hast diesen Spieler eben erst überfallen – erneut frühestens ab ' . date('d.m. H:i', $bis) . ' Uhr.';
        }
        if ($bis = vollschutz_bis($db, $verteidiger, $ts)) {
            return 'Dieser Spieler hat seine Tagesgrenze an Verlusten erreicht und ist bis ' . date('d.m. H:i', $bis) . ' Uhr geschützt.';
        }
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

/** Wer selbst angreift, darf vom Ziel einmal zurückbekommen – auch während Sperre oder Vollschutz. */
function gegenschlag_freigeben(PDO $db, int $angreifer, int $ziel): void
{
    $bis = time() + (int)round(RUECKSCHLAG_H * 3600 / tempo());
    $db->prepare('INSERT INTO angriffssperre (verteidiger_id, angreifer_id, bis_ts, frei_bis_ts) VALUES (?, ?, 0, ?)
                  ON DUPLICATE KEY UPDATE frei_bis_ts = GREATEST(frei_bis_ts, VALUES(frei_bis_ts))')
       ->execute([$angreifer, $ziel, $bis]);
}

/** Höchster Verlust (Anteil am Vermögen), den dieser Angriff anrichten darf. */
function erlaubter_verlust(PDO $db, int $angreifer, int $verteidiger, int $ts): float
{
    if (rueckschlag_aktiv($db, $verteidiger, $angreifer, $ts)) {
        return VERLUST_MAX_ANGRIFF;
    }
    return min(VERLUST_MAX_ANGRIFF, verlust_stand($db, $verteidiger, $ts)['spielraum']);
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
        foreach ([$z['einheiten'], $z['bunker']] as $liste) {
            foreach ($liste as $typ => $n) {
                $w += einheit_wert($typ) * $n;
            }
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
 * Nach einem Raubzug: Schaden protokollieren und die persönliche Sperre des Angreifers setzen.
 * Ein genutztes Rückschlagrecht ist damit verbraucht.
 * Gibt ['anteil', 'anteil_24', 'spielraum', 'sperre_bis', 'vollschutz_bis'] zurück.
 */
function schutz_nach_raubzug(PDO $db, int $angreifer, int $verteidiger, int $ts, string $ergebnis, float $wertVorher, float $verlust): array
{
    $anteil = $wertVorher > 0 ? min(1.0, $verlust / $wertVorher) : 0.0;
    $db->prepare('INSERT INTO angriffe_log (ts, angreifer_id, verteidiger_id, ergebnis, wert_vorher, wert_verlust) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$ts, $angreifer, $verteidiger, $ergebnis, $wertVorher, $verlust]);
    $db->prepare('UPDATE angriffssperre SET frei_bis_ts = 0 WHERE verteidiger_id = ? AND angreifer_id = ?')->execute([$verteidiger, $angreifer]);
    $stand = verlust_stand($db, $verteidiger, $ts);
    if ($ergebnis === 'verteidiger') {
        return ['anteil' => $anteil, 'anteil_24' => $stand['verlust'], 'spielraum' => $stand['spielraum'], 'sperre_bis' => null, 'vollschutz_bis' => null];
    }
    $bis = $ts + schutzzeit_s($anteil, staerke_r($angreifer, $verteidiger), tempo());
    $db->prepare('INSERT INTO angriffssperre (verteidiger_id, angreifer_id, bis_ts, frei_bis_ts) VALUES (?, ?, ?, 0)
                  ON DUPLICATE KEY UPDATE bis_ts = GREATEST(bis_ts, VALUES(bis_ts))')
       ->execute([$verteidiger, $angreifer, $bis]);
    return ['anteil' => $anteil, 'anteil_24' => $stand['verlust'], 'spielraum' => $stand['spielraum'],
            'sperre_bis' => persoenliche_sperre_bis($db, $verteidiger, $angreifer, $ts), 'vollschutz_bis' => vollschutz_bis($db, $verteidiger, $ts)];
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
