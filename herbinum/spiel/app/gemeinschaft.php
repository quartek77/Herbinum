<?php
// Rangliste und Allianzen.
declare(strict_types=1);

const PUNKTE_JE = 1000;                 // 1 Punkt je 1.000 ausgegebene Rohstoffe (wie OGame)
const GEN_RAENGE = ['vorstand' => 'Vorstand', 'mitglied' => 'Mitglied'];

// =====================================================================
// Punkte
// =====================================================================

/** Summe aller Rohstoffe, die bis einschließlich $stufe ausgegeben wurden. */
function kosten_bis(string $kostenFn, string $typ, int $stufe): float
{
    static $cache = [];
    $summe = 0.0;
    for ($l = 1; $l <= $stufe; $l++) {
        $summe += $cache["{$kostenFn}:{$typ}:{$l}"] ??= array_sum($kostenFn($typ, $l));
    }
    return $summe;
}

/**
 * Punkte aller Spieler: ['spieler_id' => ['gebaeude' => …, 'forschung' => …, 'einheiten' => …, 'gesamt' => …]].
 * Einheiten auf Reisen zählen mit, Aufträge in Bau noch nicht.
 */
function punkte_alle(): array
{
    $db = db();
    $p = [];
    $plus = function (int $sid, string $art, float $roh) use (&$p) {
        $p[$sid] ??= ['gebaeude' => 0.0, 'forschung' => 0.0, 'einheiten' => 0.0];
        $p[$sid][$art] += $roh;
    };
    foreach ($db->query('SELECT b.besitzer_id, g.typ, g.stufe FROM gebaeude g JOIN bereiche b ON b.id = g.bereich_id WHERE b.besitzer_id IS NOT NULL') as $r) {
        if (isset(GEBAEUDE[$r['typ']])) {
            $plus((int)$r['besitzer_id'], 'gebaeude', kosten_bis('baukosten', $r['typ'], (int)$r['stufe']));
        }
    }
    foreach ($db->query('SELECT spieler_id, typ, stufe FROM forschung') as $r) {
        if (isset(FORSCHUNG[$r['typ']])) {
            $plus((int)$r['spieler_id'], 'forschung', kosten_bis('forschungskosten', $r['typ'], (int)$r['stufe']));
        }
    }
    $stueck = fn(string $t) => array_sum(EINHEITEN[$t]['kosten']);
    // Einheiten im Bereich und im Bunker zählen gleich
    foreach ($db->query('SELECT b.besitzer_id, e.typ, e.anzahl FROM einheiten e JOIN bereiche b ON b.id = e.bereich_id WHERE b.besitzer_id IS NOT NULL
                         UNION ALL SELECT b.besitzer_id, k.typ, k.anzahl FROM bunker k JOIN bereiche b ON b.id = k.bereich_id WHERE b.besitzer_id IS NOT NULL') as $r) {
        if (isset(EINHEITEN[$r['typ']])) {
            $plus((int)$r['besitzer_id'], 'einheiten', $stueck($r['typ']) * (int)$r['anzahl']);
        }
    }
    foreach ($db->query('SELECT spieler_id, einheiten FROM zuege
                         UNION ALL SELECT b.besitzer_id, a.einheiten FROM bunker_auslagerung a JOIN bereiche b ON b.id = a.bereich_id WHERE b.besitzer_id IS NOT NULL') as $r) {
        foreach (json_decode($r['einheiten'], true) ?: [] as $t => $n) {
            if (isset(EINHEITEN[$t])) {
                $plus((int)$r['spieler_id'], 'einheiten', $stueck($t) * (int)$n);
            }
        }
    }
    foreach ($p as &$x) {
        foreach ($x as &$v) {
            $v = (int)floor($v / PUNKTE_JE);
        }
        unset($v);
        $x['gesamt'] = $x['gebaeude'] + $x['forschung'] + $x['einheiten'];
    }
    return $p;
}

/** Spielerrangliste, sortiert nach $nach ('gesamt', 'gebaeude', 'forschung', 'einheiten'), mit Platz. */
function rangliste(string $nach = 'gesamt'): array
{
    $nach = in_array($nach, ['gesamt', 'gebaeude', 'forschung', 'einheiten'], true) ? $nach : 'gesamt';
    $punkte = punkte_alle();
    $liste = db()->query('SELECT s.id, s.name, s.farbe, s.gesperrt, s.zuletzt_ts, g.kuerzel, g.id AS gid, g.name AS gname, g.wappen,
                                 (SELECT COUNT(*) FROM bereiche b WHERE b.besitzer_id = s.id) AS bereiche
                          FROM spieler s LEFT JOIN genossenschaften g ON g.id = s.genossenschaft_id')->fetchAll();
    foreach ($liste as &$s) {
        $s += $punkte[(int)$s['id']] ?? ['gebaeude' => 0, 'forschung' => 0, 'einheiten' => 0, 'gesamt' => 0];
    }
    unset($s);
    usort($liste, fn($a, $b) => [$b[$nach], $b['gesamt'], $a['name']] <=> [$a[$nach], $a['gesamt'], $b['name']]);
    $platz = 0;
    $vorher = null;
    foreach ($liste as $i => &$s) {
        // gleiche Punkte → gleicher Platz
        if ($s[$nach] !== $vorher) {
            $platz = $i + 1;
            $vorher = $s[$nach];
        }
        $s['platz'] = $platz;
    }
    return $liste;
}

function gen_rangliste(): array
{
    $punkte = punkte_alle();
    $gens = db()->query('SELECT g.*, (SELECT COUNT(*) FROM spieler s WHERE s.genossenschaft_id = g.id) AS mitglieder FROM genossenschaften g')->fetchAll();
    $mitglieder = db()->query('SELECT id, genossenschaft_id FROM spieler WHERE genossenschaft_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($gens as &$g) {
        $g['punkte'] = 0;
        foreach ($mitglieder as $sid => $gid) {
            if ((int)$gid === (int)$g['id']) {
                $g['punkte'] += $punkte[(int)$sid]['gesamt'] ?? 0;
            }
        }
        $g['schnitt'] = $g['mitglieder'] ? intdiv($g['punkte'], (int)$g['mitglieder']) : 0;
    }
    unset($g);
    usort($gens, fn($a, $b) => [$b['punkte'], $a['name']] <=> [$a['punkte'], $b['name']]);
    foreach ($gens as $i => &$g) {
        $g['platz'] = $i + 1;
    }
    return $gens;
}

// =====================================================================
// Allianzen
// =====================================================================

function gen_max_mitglieder(): int
{
    return (int)(config()['spiel']['gen_max_mitglieder'] ?? 10);
}

function genossenschaft(int $gid): ?array
{
    $st = db()->prepare('SELECT * FROM genossenschaften WHERE id = ?');
    $st->execute([$gid]);
    return $st->fetch() ?: null;
}

/** Mitgliedschaft eines Spielers: ['gid', 'rang', 'name', 'kuerzel', …] oder null. */
function gen_von(int $sid): ?array
{
    $st = db()->prepare('SELECT g.*, s.gen_rang AS rang, s.gen_seit_ts AS seit FROM spieler s JOIN genossenschaften g ON g.id = s.genossenschaft_id WHERE s.id = ?');
    $st->execute([$sid]);
    return $st->fetch() ?: null;
}

function gen_mitglieder(int $gid): array
{
    $st = db()->prepare("SELECT id, name, farbe, gen_rang, gen_seit_ts, zuletzt_ts FROM spieler WHERE genossenschaft_id = ? ORDER BY gen_rang = 'vorstand' DESC, gen_seit_ts, name");
    $st->execute([$gid]);
    return $st->fetchAll();
}

function gen_bewerbungen(int $gid): array
{
    $st = db()->prepare('SELECT b.*, s.name FROM gen_bewerbungen b JOIN spieler s ON s.id = b.spieler_id WHERE b.genossenschaft_id = ? ORDER BY b.ts');
    $st->execute([$gid]);
    return $st->fetchAll();
}

function eigene_bewerbungen(int $sid): array
{
    $st = db()->prepare('SELECT b.*, g.name, g.kuerzel FROM gen_bewerbungen b JOIN genossenschaften g ON g.id = b.genossenschaft_id WHERE b.spieler_id = ? ORDER BY b.ts');
    $st->execute([$sid]);
    return $st->fetchAll();
}

function gleiche_genossenschaft(PDO $db, int $a, int $b): bool
{
    // NULL = NULL ist in SQL nicht wahr – zwei Spieler ohne Allianz gelten also nicht als verbündet
    $st = $db->prepare('SELECT COUNT(*) FROM spieler x JOIN spieler y ON x.genossenschaft_id = y.genossenschaft_id WHERE x.id = ? AND y.id = ?');
    $st->execute([$a, $b]);
    return (bool)$st->fetchColumn();
}

/** Prüft, ob $sid Vorstand ist; gibt die Mitgliedschaft oder eine Fehlermeldung zurück. */
function gen_vorstand(int $sid): array|string
{
    $m = gen_von($sid);
    if (!$m) {
        return 'Du bist in keiner Allianz.';
    }
    if ($m['rang'] !== 'vorstand') {
        return 'Das darf nur der Vorstand.';
    }
    return $m;
}

function gen_an_mitglieder(PDO $db, int $gid, string $titel, array $inhalt, ?int $ausser = null): void
{
    $st = $db->prepare('SELECT id FROM spieler WHERE genossenschaft_id = ?');
    $st->execute([$gid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        if ((int)$sid !== $ausser) {
            nachricht($db, (int)$sid, time(), 'genossenschaft', $titel, $inhalt);
        }
    }
}

function gen_gruenden(int $sid, string $name, string $kuerzel, string $beschreibung): ?string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $kuerzel = mb_strtoupper(trim($kuerzel));
    if (!preg_match('/^[\p{L}\p{N} .\-&\']{3,40}$/u', $name)) {
        return 'Der Name muss 3 bis 40 Zeichen lang sein (Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich, &).';
    }
    if (!preg_match('/^[\p{Lu}\p{N}]{2,6}$/u', $kuerzel)) {
        return 'Das Kürzel muss 2 bis 6 Buchstaben oder Ziffern haben, z. B. „LJH“.';
    }
    return in_transaktion(function (PDO $db) use ($sid, $name, $kuerzel, $beschreibung) {
        $st = $db->prepare('SELECT genossenschaft_id FROM spieler WHERE id = ? FOR UPDATE');
        $st->execute([$sid]);
        if ($st->fetchColumn()) {
            return 'Du bist schon in einer Allianz.';
        }
        $st = $db->prepare('SELECT name, kuerzel FROM genossenschaften WHERE name = ? OR kuerzel = ?');
        $st->execute([$name, $kuerzel]);
        if ($x = $st->fetch()) {
            return mb_strtolower($x['name']) === mb_strtolower($name) ? 'Diesen Namen gibt es schon.' : 'Dieses Kürzel ist schon vergeben.';
        }
        $db->prepare('INSERT INTO genossenschaften (name, kuerzel, beschreibung, erstellt_ts) VALUES (?, ?, ?, ?)')
           ->execute([$name, $kuerzel, mb_substr(trim($beschreibung), 0, 2000), time()]);
        $gid = (int)$db->lastInsertId();
        $db->prepare("UPDATE spieler SET genossenschaft_id = ?, gen_rang = 'vorstand', gen_seit_ts = ? WHERE id = ?")->execute([$gid, time(), $sid]);
        $db->prepare('DELETE FROM gen_bewerbungen WHERE spieler_id = ?')->execute([$sid]);
        chronik('allianz', '', "{$name} [{$kuerzel}]");
        return null;
    });
}

// =====================================================================
// Wappen / Logo einer Allianz
// =====================================================================

const WAPPEN_MAX_BYTES = 1048576;       // 1 MB
const WAPPEN_PIXEL = 256;               // wird auf höchstens 256 × 256 verkleinert

/** Bildformat am Dateianfang erkennen (PNG, JPEG, GIF, WebP). */
function bildformat_erkennen(string $pfad): ?string
{
    $k = (string)file_get_contents($pfad, false, null, 0, 16);
    return match (true) {
        str_starts_with($k, "\x89PNG\r\n\x1a\n") => 'png',
        str_starts_with($k, "\xFF\xD8\xFF") => 'jpg',
        str_starts_with($k, 'GIF87a'), str_starts_with($k, 'GIF89a') => 'gif',
        str_starts_with($k, 'RIFF') && substr($k, 8, 4) === 'WEBP' => 'webp',
        default => null,
    };
}

/**
 * Speichert ein Wappen. Mit GD (bei All-Inkl vorhanden) wird das Bild neu als PNG gezeichnet und verkleinert –
 * das entfernt alles, was kein Bild ist. $verschieben ist move_uploaded_file im Betrieb (Tests übergeben copy).
 */
function wappen_hochladen(int $sid, array $datei, ?callable $verschieben = null): ?string
{
    $verschieben ??= 'move_uploaded_file';
    $m = gen_vorstand($sid);
    if (is_string($m)) {
        return $m;
    }
    $fehler = (int)($datei['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehler === UPLOAD_ERR_NO_FILE) {
        return 'Bitte ein Bild auswählen.';
    }
    if ($fehler !== UPLOAD_ERR_OK || (int)($datei['size'] ?? 0) > WAPPEN_MAX_BYTES) {
        return 'Das Bild ist zu groß (höchstens 1 MB) oder der Upload ist fehlgeschlagen.';
    }
    $format = bildformat_erkennen((string)$datei['tmp_name']);
    if (!$format) {
        return 'Das ist kein PNG-, JPG-, GIF- oder WebP-Bild.';
    }
    if (!is_dir(WAPPEN_DIR) && !mkdir(WAPPEN_DIR, 0755, true)) {
        return 'Der Ordner public/uploads/wappen fehlt und konnte nicht angelegt werden.';
    }
    $name = bin2hex(random_bytes(8));
    if (function_exists('imagecreatefromstring')) {
        $bild = @imagecreatefromstring((string)file_get_contents((string)$datei['tmp_name']));
        if (!$bild) {
            return 'Das Bild lässt sich nicht lesen.';
        }
        [$w, $h] = [imagesx($bild), imagesy($bild)];
        $f = min(1, WAPPEN_PIXEL / max($w, $h));
        $neu = imagecreatetruecolor(max(1, (int)round($w * $f)), max(1, (int)round($h * $f)));
        imagealphablending($neu, false);
        imagesavealpha($neu, true);
        imagefill($neu, 0, 0, imagecolorallocatealpha($neu, 0, 0, 0, 127));
        imagecopyresampled($neu, $bild, 0, 0, 0, 0, imagesx($neu), imagesy($neu), $w, $h);
        $name .= '.png';
        $ok = imagepng($neu, WAPPEN_DIR . '/' . $name, 6);
    } else {
        $name .= '.' . $format;
        $ok = $verschieben((string)$datei['tmp_name'], WAPPEN_DIR . '/' . $name);
    }
    if (!$ok) {
        return 'Das Bild konnte nicht gespeichert werden. Hat der Ordner public/uploads/wappen Schreibrechte?';
    }
    $alt = (string)($m['wappen'] ?? '');
    db()->prepare('UPDATE genossenschaften SET wappen = ? WHERE id = ?')->execute([$name, $m['id']]);
    wappen_datei_loeschen($alt);
    return null;
}

function wappen_entfernen(int $sid): ?string
{
    $m = gen_vorstand($sid);
    if (is_string($m)) {
        return $m;
    }
    db()->prepare("UPDATE genossenschaften SET wappen = '' WHERE id = ?")->execute([$m['id']]);
    wappen_datei_loeschen((string)($m['wappen'] ?? ''));
    return null;
}

function wappen_datei_loeschen(string $name): void
{
    $pfad = WAPPEN_DIR . '/' . basename($name);
    if ($name !== '' && is_file($pfad)) {
        unlink($pfad);
    }
}

const ONLINE_S = 300;                   // bis 5 Minuten nach dem letzten Klick gilt man als online
const KUERZLICH_S = 1800;               // bis 30 Minuten „gerade eben da“

/** Kleine Lampe vor Spielernamen: grün = online, gelb = vor kurzem, grau = länger weg. */
function online_lampe(int $zuletzt): string
{
    $her = time() - $zuletzt;
    [$klasse, $text] = match (true) {
        $her <= ONLINE_S => ['an', 'online'],
        $her <= KUERZLICH_S => ['kuerzlich', 'vor ' . max(1, intdiv($her, 60)) . ' Minuten aktiv'],
        $zuletzt > 0 => ['aus', 'zuletzt da: ' . date('d.m. H:i', $zuletzt)],
        default => ['aus', 'noch nie da'],
    };
    return '<i class="lampe ' . $klasse . '" title="' . h($text) . '" role="img" aria-label="' . h($text) . '"></i>';
}

/** Spielername mit Farbe und Online-Lampe. $s braucht name, farbe, zuletzt_ts. */
function spieler_html(array $s): string
{
    return '<span class="spielername">' . online_lampe((int)($s['zuletzt_ts'] ?? 0)) . '<i class="farbpunkt" style="background:' . h((string)$s['farbe']) . '"></i>' . h((string)$s['name']) . '</span>';
}

/** Allianz-Kürzel als Link; beim Überfahren erscheint das Wappen mit dem Namen. $g braucht id, kuerzel, name, wappen. */
function kuerzel_html(?array $g): string
{
    if (!$g || empty($g['kuerzel'])) {
        return '';
    }
    return '<a class="kuerzel kuerzel-tip" href="?s=allianz&amp;id=' . (int)$g['id'] . '">[' . h($g['kuerzel']) . ']'
        . '<span class="tip" role="tooltip">' . wappen_html($g, 'wappen tipbild') . '<b>' . h((string)$g['name']) . '</b></span></a>';
}

/** HTML für das Wappen (oder ein Platzhalter mit dem Kürzel). */
function wappen_html(?array $g, string $klasse = 'wappen'): string
{
    if (!$g) {
        return '';
    }
    if (!empty($g['wappen'])) {
        return '<img class="' . h($klasse) . '" src="uploads/wappen/' . h($g['wappen']) . '" alt="Wappen von ' . h($g['name'] ?? '') . '">';
    }
    return '<span class="' . h($klasse) . ' wappen-leer" aria-hidden="true">' . h(mb_substr((string)($g['kuerzel'] ?? '?'), 0, 3)) . '</span>';
}

function gen_bewerben(int $sid, int $gid, string $text): ?string
{
    if (gen_von($sid)) {
        return 'Du bist schon in einer Allianz. Tritt erst aus.';
    }
    $g = genossenschaft($gid);
    if (!$g) {
        return 'Diese Allianz gibt es nicht.';
    }
    $st = db()->prepare('SELECT COUNT(*) FROM gen_bewerbungen WHERE genossenschaft_id = ? AND spieler_id = ?');
    $st->execute([$gid, $sid]);
    if ((int)$st->fetchColumn()) {
        return 'Du hast dich dort schon beworben.';
    }
    return in_transaktion(function (PDO $db) use ($sid, $gid, $text, $g) {
        $db->prepare('INSERT INTO gen_bewerbungen (genossenschaft_id, spieler_id, text, ts) VALUES (?, ?, ?, ?)')
           ->execute([$gid, $sid, mb_substr(trim($text), 0, 500), time()]);
        $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
        $st->execute([$sid]);
        $name = (string)$st->fetchColumn();
        $st = $db->prepare("SELECT id FROM spieler WHERE genossenschaft_id = ? AND gen_rang = 'vorstand'");
        $st->execute([$gid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
            nachricht($db, (int)$v, time(), 'genossenschaft', "Neue Bewerbung von {$name}", ['text' => trim($text) ?: 'Ohne Anschreiben.']);
        }
        return null;
    });
}

function gen_bewerbung_zurueckziehen(int $sid, int $gid): ?string
{
    $st = db()->prepare('DELETE FROM gen_bewerbungen WHERE genossenschaft_id = ? AND spieler_id = ?');
    $st->execute([$gid, $sid]);
    return $st->rowCount() ? null : 'Diese Bewerbung gibt es nicht.';
}

function gen_entscheiden(int $vorstandId, int $bewerberId, bool $annehmen): ?string
{
    $m = gen_vorstand($vorstandId);
    if (is_string($m)) {
        return $m;
    }
    $gid = (int)$m['id'];
    return in_transaktion(function (PDO $db) use ($gid, $bewerberId, $annehmen, $m) {
        $st = $db->prepare('SELECT * FROM gen_bewerbungen WHERE genossenschaft_id = ? AND spieler_id = ? FOR UPDATE');
        $st->execute([$gid, $bewerberId]);
        if (!$st->fetch()) {
            return 'Diese Bewerbung gibt es nicht mehr.';
        }
        $db->prepare('DELETE FROM gen_bewerbungen WHERE genossenschaft_id = ? AND spieler_id = ?')->execute([$gid, $bewerberId]);
        if (!$annehmen) {
            nachricht($db, $bewerberId, time(), 'genossenschaft', "Bewerbung bei {$m['name']} abgelehnt", ['text' => 'Vielleicht klappt es bei einer anderen Allianz.']);
            return null;
        }
        $st = $db->prepare('SELECT genossenschaft_id FROM spieler WHERE id = ? FOR UPDATE');
        $st->execute([$bewerberId]);
        if ($st->fetchColumn()) {
            return 'Dieser Spieler ist inzwischen in einer anderen Allianz.';
        }
        $st = $db->prepare('SELECT COUNT(*) FROM spieler WHERE genossenschaft_id = ?');
        $st->execute([$gid]);
        if ((int)$st->fetchColumn() >= gen_max_mitglieder()) {
            return 'Die Allianz ist voll (' . gen_max_mitglieder() . ' Mitglieder).';
        }
        $db->prepare("UPDATE spieler SET genossenschaft_id = ?, gen_rang = 'mitglied', gen_seit_ts = ? WHERE id = ?")->execute([$gid, time(), $bewerberId]);
        $db->prepare('DELETE FROM gen_bewerbungen WHERE spieler_id = ?')->execute([$bewerberId]);
        nachricht($db, $bewerberId, time(), 'genossenschaft', "Willkommen in der Allianz {$m['name']}!", ['text' => 'Ihr könnt euch jetzt gegenseitig beliefern, und untereinander gibt es keine Raubzüge.']);
        return null;
    });
}

function gen_rang_setzen(int $vorstandId, int $sid, string $rang): ?string
{
    $m = gen_vorstand($vorstandId);
    if (is_string($m)) {
        return $m;
    }
    if (!isset(GEN_RAENGE[$rang]) || $sid === $vorstandId) {
        return $sid === $vorstandId ? 'Deinen eigenen Rang kannst du nicht ändern – ernenne erst jemand anderen.' : 'Unbekannter Rang.';
    }
    $st = db()->prepare('UPDATE spieler SET gen_rang = ? WHERE id = ? AND genossenschaft_id = ?');
    $st->execute([$rang, $sid, $m['id']]);
    return $st->rowCount() || (gen_von($sid)['rang'] ?? '') === $rang ? null : 'Dieser Spieler ist nicht in deiner Allianz.';
}

function gen_entfernen(int $vorstandId, int $sid): ?string
{
    $m = gen_vorstand($vorstandId);
    if (is_string($m)) {
        return $m;
    }
    if ($sid === $vorstandId) {
        return 'Zum Gehen bitte „Austreten“ benutzen.';
    }
    return in_transaktion(function (PDO $db) use ($sid, $m) {
        $st = $db->prepare("UPDATE spieler SET genossenschaft_id = NULL, gen_rang = '', gen_seit_ts = 0 WHERE id = ? AND genossenschaft_id = ?");
        $st->execute([$sid, $m['id']]);
        if (!$st->rowCount()) {
            return 'Dieser Spieler ist nicht in deiner Allianz.';
        }
        nachricht($db, $sid, time(), 'genossenschaft', "Du bist nicht mehr Mitglied von {$m['name']}", ['text' => 'Der Vorstand hat dich aus der Allianz genommen.']);
        return null;
    });
}

/**
 * Austritt (auch beim Löschen eines Spielers). Geht der letzte Vorstand, übernimmt das dienstälteste Mitglied;
 * ist niemand mehr da, wird die Allianz aufgelöst. Läuft innerhalb einer bestehenden Transaktion.
 */
function gen_austritt_intern(PDO $db, int $sid): ?string
{
    $st = $db->prepare('SELECT genossenschaft_id, gen_rang, name FROM spieler WHERE id = ? FOR UPDATE');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s || !$s['genossenschaft_id']) {
        return 'Du bist in keiner Allianz.';
    }
    $gid = (int)$s['genossenschaft_id'];
    $db->prepare("UPDATE spieler SET genossenschaft_id = NULL, gen_rang = '', gen_seit_ts = 0 WHERE id = ?")->execute([$sid]);
    $st = $db->prepare('SELECT id, gen_rang FROM spieler WHERE genossenschaft_id = ? ORDER BY gen_seit_ts, id FOR UPDATE');
    $st->execute([$gid]);
    $rest = $st->fetchAll();
    if (!$rest) {
        wappen_datei_loeschen((string)(genossenschaft($gid)['wappen'] ?? ''));
        $db->prepare('DELETE FROM genossenschaften WHERE id = ?')->execute([$gid]);
        return null;
    }
    if (!array_filter($rest, fn($r) => $r['gen_rang'] === 'vorstand')) {
        $db->prepare("UPDATE spieler SET gen_rang = 'vorstand' WHERE id = ?")->execute([$rest[0]['id']]);
        nachricht($db, (int)$rest[0]['id'], time(), 'genossenschaft', 'Du bist jetzt im Vorstand', ['text' => "{$s['name']} ist ausgetreten, und du bist das dienstälteste Mitglied."]);
    }
    gen_an_mitglieder($db, $gid, "{$s['name']} hat die Allianz verlassen", []);
    return null;
}

function gen_austreten(int $sid): ?string
{
    return in_transaktion(fn(PDO $db) => gen_austritt_intern($db, $sid));
}

function gen_aufloesen(int $vorstandId, string $bestaetigung): ?string
{
    $m = gen_vorstand($vorstandId);
    if (is_string($m)) {
        return $m;
    }
    if (trim($bestaetigung) !== $m['kuerzel']) {
        return 'Zur Sicherheit bitte das Kürzel eintippen.';
    }
    return in_transaktion(function (PDO $db) use ($m, $vorstandId) {
        gen_an_mitglieder($db, (int)$m['id'], "Die Allianz {$m['name']} wurde aufgelöst", [], $vorstandId);
        $db->prepare("UPDATE spieler SET genossenschaft_id = NULL, gen_rang = '', gen_seit_ts = 0 WHERE genossenschaft_id = ?")->execute([$m['id']]);
        $db->prepare('DELETE FROM genossenschaften WHERE id = ?')->execute([$m['id']]);
        wappen_datei_loeschen((string)($m['wappen'] ?? ''));
        return null;
    });
}

function gen_beschreibung_setzen(int $vorstandId, string $text): ?string
{
    $m = gen_vorstand($vorstandId);
    if (is_string($m)) {
        return $m;
    }
    db()->prepare('UPDATE genossenschaften SET beschreibung = ? WHERE id = ?')->execute([mb_substr(trim($text), 0, 2000), $m['id']]);
    return null;
}

function gen_rundschreiben(int $sid, string $titel, string $text): ?string
{
    $m = gen_von($sid);
    if (!$m) {
        return 'Du bist in keiner Allianz.';
    }
    if (trim($titel) === '' || trim($text) === '') {
        return 'Bitte Betreff und Text ausfüllen.';
    }
    $st = db()->prepare('SELECT name FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    return in_transaktion(function (PDO $db) use ($m, $titel, $text, $st) {
        gen_an_mitglieder($db, (int)$m['id'], "[{$m['kuerzel']}] " . mb_substr(trim($titel), 0, 140), ['text' => mb_substr(trim($text), 0, 2000), 'von' => $st->fetchColumn()]);
        return null;
    });
}
