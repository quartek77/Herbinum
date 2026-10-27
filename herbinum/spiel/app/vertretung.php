<?php
// Vertretung: Wer z. B. tagsüber arbeitet, trägt ein festes Zeitfenster ein. In dieser Zeit steuert er sein Konto
// nicht selbst, aber Mitglieder seiner Allianz dürfen ausgewählte Dinge für ihn erledigen. Alles wird protokolliert.
declare(strict_types=1);

const VERTRETUNG_RECHTE = [
    'bauen' => 'Gebäude bauen und Bauaufträge abbrechen',
    'forschen' => 'Forschung starten',
    'werkstatt' => 'Einheiten in Werkstatt und Schützenhalle bauen',
    'zuege' => 'Transporte und Verlegungen zwischen seinen eigenen Bereichen, Züge umkehren',
    'bunker' => 'Einheiten im Bunker ein- und auslagern',
    'markt' => 'Beim Händler tauschen',
];
const VERTRETUNG_MAX_STUNDEN = 14;        // längstes Zeitfenster pro Tag
const VERTRETUNG_VORLAUF_S = 7200;        // Änderungen gelten erst nach 2 Stunden

/** Welche Aktion (POST a=…) welches Recht braucht. Alles andere ist in Vertretung gesperrt. */
const VERTRETUNG_AKTIONEN = [
    'bauen' => 'bauen', 'abbrechen' => 'bauen', 'forschen' => 'forschen', 'forschung_abbrechen' => 'forschen',
    'einheiten' => 'werkstatt', 'losschicken' => 'zuege', 'umkehren' => 'zuege',
    'bunker_einlagern' => 'bunker', 'bunker_auslagern' => 'bunker', 'markt_tauschen' => 'markt',
];

function vertretung_laden(int $sid): ?array
{
    $st = db()->prepare('SELECT * FROM vertretung WHERE spieler_id = ?');
    $st->execute([$sid]);
    $v = $st->fetch();
    if (!$v) {
        return null;
    }
    $v['rechte_liste'] = array_values(array_intersect(explode(',', $v['rechte']), array_keys(VERTRETUNG_RECHTE)));
    return $v;
}

function uhrzeit(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
}

/** Läuft das Zeitfenster gerade? Gibt [beginn_ts, ende_ts] zurück oder null. Zählt in echter Ortszeit. */
function vertretung_fenster(?array $v, int $ts): ?array
{
    if (!$v || (int)$v['gueltig_ab_ts'] > $ts) {
        return null;
    }
    $lokal = ortszeit($ts);
    $mitternacht = $ts - $lokal % 86400;
    $min = intdiv($lokal % 86400, 60);
    $von = (int)$v['von_min'];
    $bis = (int)$v['bis_min'];
    $wt = (int)date('N', $ts);
    $vortag = $wt === 1 ? 7 : $wt - 1;
    $laenge = (($bis - $von + 1440) % 1440) * 60;
    $beginn = null;
    if ($von < $bis) {
        if (str_contains($v['tage'], (string)$wt) && $min >= $von && $min < $bis) {
            $beginn = $mitternacht + $von * 60;
        }
    } elseif ($min >= $von && str_contains($v['tage'], (string)$wt)) {
        $beginn = $mitternacht + $von * 60;                 // Fenster über Mitternacht, heute begonnen
    } elseif ($min < $bis && str_contains($v['tage'], (string)$vortag)) {
        $beginn = $mitternacht - 86400 + $von * 60;         // … gestern begonnen
    }
    if ($beginn === null || (int)$v['pause_bis_ts'] > $ts) {
        return null;
    }
    return [$beginn, $beginn + $laenge];
}

/** Ist der Spieler gerade in seinem Vertretungsfenster (und damit selbst gesperrt)? */
function vertretung_aktiv(int $sid, ?int $ts = null): ?array
{
    return vertretung_fenster(vertretung_laden($sid), $ts ?? time());
}

function vertretung_speichern(int $sid, array $tage, string $von, string $bis, array $rechte): ?string
{
    if (vertretung_aktiv($sid)) {
        return 'Während dein Zeitfenster läuft, kannst du es nicht ändern. Beende es vorher mit „Ich bin wieder da“.';
    }
    $tage = array_values(array_unique(array_filter(array_map('intval', $tage), fn($t) => $t >= 1 && $t <= 7)));
    sort($tage);
    $rechte = array_values(array_intersect($rechte, array_keys(VERTRETUNG_RECHTE)));
    $zeit = function (string $s): ?int {
        return preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m) && (int)$m[1] < 24 && (int)$m[2] < 60 ? (int)$m[1] * 60 + (int)$m[2] : null;
    };
    $vonMin = $zeit($von);
    $bisMin = $zeit($bis);
    if (!$tage) {
        return 'Bitte mindestens einen Wochentag wählen.';
    }
    if ($vonMin === null || $bisMin === null || $vonMin === $bisMin) {
        return 'Bitte Beginn und Ende als Uhrzeit angeben, z. B. 07:30 und 16:30.';
    }
    if ((($bisMin - $vonMin + 1440) % 1440) > VERTRETUNG_MAX_STUNDEN * 60) {
        return 'Das Zeitfenster darf höchstens ' . VERTRETUNG_MAX_STUNDEN . ' Stunden lang sein.';
    }
    if (!$rechte) {
        return 'Bitte mindestens ein Recht auswählen, sonst kann dich niemand vertreten.';
    }
    db()->prepare('INSERT INTO vertretung (spieler_id, tage, von_min, bis_min, rechte, gueltig_ab_ts, pause_bis_ts) VALUES (?, ?, ?, ?, ?, ?, 0)
                   ON DUPLICATE KEY UPDATE tage = VALUES(tage), von_min = VALUES(von_min), bis_min = VALUES(bis_min), rechte = VALUES(rechte),
                                           gueltig_ab_ts = VALUES(gueltig_ab_ts), pause_bis_ts = 0')
        ->execute([$sid, implode('', $tage), $vonMin, $bisMin, implode(',', $rechte), time() + VERTRETUNG_VORLAUF_S]);
    return null;
}

function vertretung_loeschen(int $sid): ?string
{
    if (vertretung_aktiv($sid)) {
        return 'Während dein Zeitfenster läuft, kannst du es nicht löschen. Beende es vorher mit „Ich bin wieder da“.';
    }
    db()->prepare('DELETE FROM vertretung WHERE spieler_id = ?')->execute([$sid]);
    return null;
}

/** „Ich bin wieder da“: das laufende Fenster endet sofort, das nächste gilt wieder normal. */
function vertretung_heute_beenden(int $sid): ?string
{
    $f = vertretung_aktiv($sid);
    if (!$f) {
        return 'Dein Zeitfenster läuft gerade nicht.';
    }
    db()->prepare('UPDATE vertretung SET pause_bis_ts = ? WHERE spieler_id = ?')->execute([$f[1], $sid]);
    return null;
}

/**
 * Darf $vertreter gerade für $ziel handeln? Gibt ['spieler' => Zeile, 'rechte' => […], 'bis' => ts] zurück oder null.
 * Bedingungen: beide in derselben Allianz, Zeitfenster des Ziels läuft, Ziel nicht gesperrt.
 */
function vertretung_ziel(int $vertreter, int $ziel): ?array
{
    if ($vertreter === $ziel || !gleiche_genossenschaft(db(), $vertreter, $ziel)) {
        return null;
    }
    $v = vertretung_laden($ziel);
    $f = vertretung_fenster($v, time());
    if (!$f || !$v['rechte_liste']) {
        return null;
    }
    $st = db()->prepare('SELECT id, name, farbe, ist_admin, gesperrt, sperrgrund FROM spieler WHERE id = ? AND gesperrt = 0');
    $st->execute([$ziel]);
    $s = $st->fetch();
    if (!$s) {
        return null;
    }
    $s['ist_admin'] = 0;    // Admin-Rechte gehen nie mit
    return ['spieler' => $s, 'rechte' => $v['rechte_liste'], 'bis' => $f[1]];
}

/** Allianzmitglieder, die gerade vertreten werden können, mit ihren Rechten und dem Ende des Fensters. */
function vertretbare(int $sid): array
{
    $gen = gen_von($sid);
    if (!$gen) {
        return [];
    }
    $out = [];
    foreach (gen_mitglieder((int)$gen['id']) as $m) {
        if ((int)$m['id'] !== $sid && ($x = vertretung_ziel($sid, (int)$m['id']))) {
            $out[] = $x;
        }
    }
    return $out;
}

/** Protokoll: Der vertretene Spieler erfährt jede Aktion. */
function vertretung_protokoll(int $ziel, string $vertreterName, string $was): void
{
    nachricht(db(), $ziel, time(), 'vertretung', "Vertretung: {$vertreterName} – " . mb_substr($was, 0, 120), ['text' => $was, 'von' => $vertreterName]);
}

/** Text der Tage für die Anzeige, z. B. „Mo–Fr“. */
function tage_text(string $tage): string
{
    $k = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
    if ($tage === '12345') {
        return 'Mo–Fr';
    }
    if ($tage === '1234567') {
        return 'täglich';
    }
    return implode(', ', array_map(fn($t) => $k[(int)$t], str_split($tage)));
}
