<?php
// Briefe zwischen Spielern und der Chat (öffentlich für alle, Allianz, privat).
declare(strict_types=1);

const BRIEFE_JE_STUNDE = 20;
const CHAT_ABSTAND_S = 1;               // höchstens eine Chatnachricht pro Sekunde
const CHAT_LAENGE = 500;

function spieler_namen(): array
{
    return db()->query('SELECT id, name, farbe FROM spieler ORDER BY name')->fetchAll();
}

/** Brief an einen anderen Spieler; der Absender bekommt eine Kopie unter „Gesendet“. */
function brief_senden(int $sid, int $an, string $betreff, string $text): ?string
{
    $betreff = trim(preg_replace('/[\x00-\x1F]+/u', ' ', $betreff));
    $text = trim($text);
    if ($an === $sid) {
        return 'An dich selbst musst du keinen Brief schreiben.';
    }
    if ($betreff === '' || $text === '') {
        return 'Bitte Betreff und Text ausfüllen.';
    }
    $db = db();
    $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
    $st->execute([$an]);
    $empfaenger = $st->fetchColumn();
    if ($empfaenger === false) {
        return 'Diesen Spieler gibt es nicht.';
    }
    $st = $db->prepare("SELECT COUNT(*) FROM nachrichten WHERE spieler_id = ? AND art = 'gesendet' AND ts > ?");
    $st->execute([$sid, time() - 3600]);
    if ((int)$st->fetchColumn() >= BRIEFE_JE_STUNDE) {
        return 'Du hast in der letzten Stunde schon ' . BRIEFE_JE_STUNDE . ' Briefe geschrieben. Gönn der Post eine Pause.';
    }
    $st = $db->prepare('SELECT name FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    $absender = (string)$st->fetchColumn();
    return in_transaktion(function (PDO $db) use ($sid, $an, $betreff, $text, $empfaenger, $absender) {
        $betreff = mb_substr($betreff, 0, 140);
        $text = mb_substr($text, 0, 4000);
        nachricht($db, $an, time(), 'brief', $betreff, ['text' => $text, 'von' => $absender, 'von_id' => $sid]);
        $db->prepare('INSERT INTO nachrichten (spieler_id, ts, art, titel, inhalt, gelesen) VALUES (?, ?, ?, ?, ?, 1)')
           ->execute([$sid, time(), 'gesendet', $betreff, json_encode(['text' => $text, 'an' => $empfaenger, 'an_id' => $an], JSON_UNESCAPED_UNICODE)]);
        return null;
    });
}

// ---------------------------------------------------------------------
// Chat
// ---------------------------------------------------------------------

const CHAT_KANAELE = ['alle' => 'Öffentlich', 'allianz' => 'Allianz', 'privat' => 'Privat'];

/** Bis zu welcher Nachricht der Spieler je Kanal schon gelesen hat (Spalten chat_alle, chat_allianz, chat_privat). */
function chat_gesehen(int $sid): array
{
    $st = db()->prepare('SELECT chat_alle, chat_allianz, chat_privat FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    $r = $st->fetch() ?: [];
    return ['alle' => (int)($r['chat_alle'] ?? 0), 'allianz' => (int)($r['chat_allianz'] ?? 0), 'privat' => (int)($r['chat_privat'] ?? 0)];
}

function chat_gesehen_setzen(int $sid, string $kanal, int $id): void
{
    if (isset(CHAT_KANAELE[$kanal]) && $id > 0) {
        db()->prepare("UPDATE spieler SET chat_{$kanal} = GREATEST(chat_{$kanal}, ?) WHERE id = ?")->execute([$id, $sid]);
    }
}

/**
 * Nachrichten eines Kanals: $kanal 'alle', 'allianz' oder 'privat' (dann mit $partner).
 * $seit = letzte bekannte ID (für das Nachladen alle paar Sekunden).
 */
function chat_laden(int $sid, string $kanal, int $partner = 0, int $seit = 0, int $limit = 80): array
{
    $gen = gen_von($sid);
    $db = db();
    $sql = 'SELECT c.id, c.kanal, c.von_id, c.an_id, c.ts, c.text, s.name, s.farbe, s.zuletzt_ts FROM chat c JOIN spieler s ON s.id = c.von_id WHERE c.id > ? AND ';
    $p = [$seit];
    if ($kanal === 'allianz') {
        if (!$gen) {
            return [];
        }
        $sql .= "c.kanal = 'allianz' AND c.gen_id = ?";
        $p[] = (int)$gen['id'];
    } elseif ($kanal === 'privat') {
        $sql .= "c.kanal = 'privat' AND ((c.von_id = ? AND c.an_id = ?) OR (c.von_id = ? AND c.an_id = ?))";
        array_push($p, $sid, $partner, $partner, $sid);
    } else {
        $sql .= "c.kanal = 'alle'";
    }
    $st = $db->prepare($sql . ' ORDER BY c.id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute($p);
    return array_reverse($st->fetchAll());
}

function chat_senden(int $sid, string $kanal, int $partner, string $text): ?string
{
    $text = trim(preg_replace('/[\x00-\x08\x0B-\x1F]+/u', '', $text));
    if ($text === '') {
        return 'Leere Nachricht.';
    }
    $text = mb_substr($text, 0, CHAT_LAENGE);
    $db = db();
    $gid = null;
    $an = null;
    if ($kanal === 'allianz') {
        $gen = gen_von($sid);
        if (!$gen) {
            return 'Du bist in keiner Allianz.';
        }
        $gid = (int)$gen['id'];
    } elseif ($kanal === 'privat') {
        $st = $db->prepare('SELECT id FROM spieler WHERE id = ?');
        $st->execute([$partner]);
        if (!$st->fetchColumn() || $partner === $sid) {
            return 'Diesen Gesprächspartner gibt es nicht.';
        }
        $an = $partner;
    } elseif ($kanal !== 'alle') {
        return 'Unbekannter Kanal.';
    }
    $st = $db->prepare('SELECT MAX(ts) FROM chat WHERE von_id = ?');
    $st->execute([$sid]);
    if ((int)$st->fetchColumn() > time() - CHAT_ABSTAND_S) {
        return 'Nicht so schnell.';
    }
    $db->prepare('INSERT INTO chat (kanal, gen_id, von_id, an_id, ts, text) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$kanal, $gid, $sid, $an, time(), $text]);
    return null;
}

/** Admin: einzelne Nachricht entfernen. */
function chat_loeschen(int $id): void
{
    db()->prepare('DELETE FROM chat WHERE id = ?')->execute([$id]);
}

/** Ungelesene Chatnachrichten je Kanal (für die Zahlenmarken). */
function chat_ungelesen(int $sid): array
{
    $g = chat_gesehen($sid);
    $gen = gen_von($sid);
    $db = db();
    $zaehl = function (string $sql, array $p) use ($db): int {
        $st = $db->prepare($sql);
        $st->execute($p);
        return (int)$st->fetchColumn();
    };
    return [
        'alle' => $zaehl("SELECT COUNT(*) FROM chat WHERE kanal = 'alle' AND id > ? AND von_id <> ?", [$g['alle'], $sid]),
        'allianz' => $gen ? $zaehl("SELECT COUNT(*) FROM chat WHERE kanal = 'allianz' AND gen_id = ? AND id > ? AND von_id <> ?", [$gen['id'], $g['allianz'], $sid]) : 0,
        'privat' => $zaehl("SELECT COUNT(*) FROM chat WHERE kanal = 'privat' AND an_id = ? AND id > ?", [$sid, $g['privat']]),
    ];
}

/** Private Gesprächspartner, neueste zuerst, mit Zahl ungelesener Nachrichten. */
function chat_gespraeche(int $sid): array
{
    $gesehen = chat_gesehen($sid)['privat'];
    $st = db()->prepare("SELECT IF(c.von_id = ?, c.an_id, c.von_id) AS partner, MAX(c.id) AS letzte, SUM(c.an_id = ? AND c.id > ?) AS neu
                         FROM chat c WHERE c.kanal = 'privat' AND (c.von_id = ? OR c.an_id = ?) GROUP BY partner ORDER BY letzte DESC");
    $st->execute([$sid, $sid, $gesehen, $sid, $sid]);
    return $st->fetchAll();
}
