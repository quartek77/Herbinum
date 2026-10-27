<?php
// Anmeldung, Registrierung und Startbereich.
declare(strict_types=1);

const SPIELERFARBEN = ['#c0392b', '#2e7d32', '#7b4fb0', '#d17a00', '#0f7c8c', '#b03a78', '#5d6d1e', '#1f4fa3', '#8d5524', '#c2185b', '#00897b', '#6d4c41'];
const LOGIN_MAX_FEHLVERSUCHE = 10;      // pro IP in 15 Minuten

function aktueller_spieler(): ?array
{
    static $s = false;
    if ($s === false) {
        $s = null;
        if (!empty($_SESSION['spieler_id'])) {
            $st = db()->prepare('SELECT id, name, farbe, ist_admin, gesperrt, sperrgrund FROM spieler WHERE id = ?');
            $st->execute([$_SESSION['spieler_id']]);
            $s = $st->fetch() ?: null;
            if ($s && (int)$s['gesperrt']) {
                // Sperre wirkt sofort, auch für laufende Sitzungen
                unset($_SESSION['spieler_id']);
                meldung(sperrtext($s['sperrgrund']), 'fehler');
                $s = null;
            }
            if ($s) {
                db()->prepare('UPDATE spieler SET zuletzt_ts = ? WHERE id = ?')->execute([time(), $s['id']]);
            }
        }
    }
    return $s;
}

function sperrtext(?string $grund): string
{
    return 'Dein Zugang ist gesperrt' . (trim((string)$grund) !== '' ? ': ' . trim($grund) : '.') . ' Melde dich bei der Spielleitung.';
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 45);
}

function anmelden(string $name, string $pass): ?string
{
    $db = db();
    $ip = client_ip();
    $db->prepare('DELETE FROM login_versuche WHERE ts < ?')->execute([time() - 900]);
    $st = $db->prepare('SELECT COUNT(*) FROM login_versuche WHERE ip = ?');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= LOGIN_MAX_FEHLVERSUCHE) {
        return 'Zu viele Fehlversuche. Bitte warte 15 Minuten.';
    }
    $st = $db->prepare('SELECT id, pass_hash, gesperrt, sperrgrund FROM spieler WHERE name = ?');
    $st->execute([trim($name)]);
    $s = $st->fetch();
    if (!$s || !password_verify($pass, $s['pass_hash'])) {
        $db->prepare('INSERT INTO login_versuche (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
        return 'Name oder Passwort stimmt nicht.';
    }
    if ((int)$s['gesperrt']) {
        return sperrtext($s['sperrgrund']);
    }
    if (password_needs_rehash($s['pass_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE spieler SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $s['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['spieler_id'] = (int)$s['id'];
    return null;
}

function abmelden(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Legt Spieler + Startbereich an. Gibt null bei Erfolg zurück, sonst die Fehlermeldung. */
function registrieren(string $name, string $pass, string $pass2, string $ortId): ?string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if (!preg_match('/^[\p{L}\p{N} _.\-]{3,24}$/u', $name)) {
        return 'Der Name muss 3 bis 24 Zeichen lang sein (Buchstaben, Ziffern, Leerzeichen, Punkt, Binde- oder Unterstrich).';
    }
    if (mb_strlen($pass) < 8) {
        return 'Das Passwort muss mindestens 8 Zeichen haben.';
    }
    if ($pass !== $pass2) {
        return 'Die beiden Passwörter stimmen nicht überein.';
    }
    $db = db();
    $db->beginTransaction();
    try {
        // Sperre auf die Spielertabelle verhindert, dass zwei gleichzeitige Anmeldungen das Limit überschreiten
        $anzahl = (int)$db->query('SELECT COUNT(*) FROM spieler FOR UPDATE')->fetchColumn();
        if ($anzahl >= (int)(config()['spiel']['max_spieler'] ?? 40)) {
            $db->rollBack();
            return 'Herbinum ist voll – alle Plätze sind vergeben.';
        }
        $st = $db->prepare('SELECT id FROM spieler WHERE name = ?');
        $st->execute([$name]);
        if ($st->fetch()) {
            $db->rollBack();
            return 'Diesen Namen gibt es schon.';
        }
        $st = $db->prepare("SELECT id FROM bereiche WHERE ort_id = ? AND besitzer_id IS NULL AND typ <> 'Kern' ORDER BY RAND() LIMIT 1 FOR UPDATE");
        $st->execute([$ortId]);
        $bid = $st->fetchColumn();
        if (!$bid) {
            $db->rollBack();
            return 'In diesem Ort ist kein Startbereich mehr frei. Wähle einen anderen Ort.';
        }
        $jetzt = time();
        $db->prepare('INSERT INTO spieler (name, pass_hash, farbe, ist_admin, erstellt_ts, zuletzt_ts) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), SPIELERFARBEN[$anzahl % count(SPIELERFARBEN)], $anzahl === 0 ? 1 : 0, $jetzt, $jetzt]);
        $sid = (int)$db->lastInsertId();
        $r = START_RESSOURCEN;
        $db->prepare('UPDATE bereiche SET besitzer_id = ?, ist_heimat = 1, holz = ?, ziegel = ?, korn = ?, taler = ?, stand_ts = ? WHERE id = ?')
           ->execute([$sid, $r['holz'], $r['ziegel'], $r['korn'], $r['taler'], $jetzt, $bid]);
        $ins = $db->prepare('INSERT INTO gebaeude (bereich_id, typ, stufe) VALUES (?, ?, ?)');
        foreach (START_GEBAEUDE as $typ => $stufe) {
            $ins->execute([$bid, $typ, $stufe]);
        }
        $db->commit();
        chronik('neuling', ort_von_bereich($db, $bid));
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    session_regenerate_id(true);
    $_SESSION['spieler_id'] = $sid;
    return null;
}

function eigene_bereiche(int $sid): array
{
    $st = db()->prepare('SELECT b.id, b.name, b.nr, o.name AS ort FROM bereiche b JOIN orte o ON o.id = b.ort_id WHERE besitzer_id = ? ORDER BY ist_heimat DESC, b.id');
    $st->execute([$sid]);
    return $st->fetchAll();
}
