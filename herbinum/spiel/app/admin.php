<?php
// Admin: Hintergrundmusik, Spielerverwaltung, Rundschreiben, Einstellungen.
declare(strict_types=1);

const MUSIK_FORMATE = ['mp3' => 'MP3', 'ogg' => 'OGG', 'm4a' => 'M4A/AAC', 'wav' => 'WAV'];

function einstellung(string $schluessel, string $standard = ''): string
{
    $st = db()->prepare('SELECT wert FROM einstellungen WHERE schluessel = ?');
    $st->execute([$schluessel]);
    $w = $st->fetchColumn();
    return $w === false ? $standard : (string)$w;
}

function einstellung_setzen(string $schluessel, string $wert): void
{
    db()->prepare('INSERT INTO einstellungen (schluessel, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)')->execute([$schluessel, $wert]);
}

// ---------------------------------------------------------------------
// Musik
// ---------------------------------------------------------------------

/** Erkennt das Audioformat am Dateianfang – die Dateiendung allein ist nicht vertrauenswürdig. */
function audioformat_erkennen(string $pfad): ?string
{
    $k = (string)file_get_contents($pfad, false, null, 0, 16);
    if (strlen($k) < 12) {
        return null;
    }
    return match (true) {
        str_starts_with($k, 'ID3'), ord($k[0]) === 0xFF && (ord($k[1]) & 0xE0) === 0xE0 => 'mp3',
        str_starts_with($k, 'OggS') => 'ogg',
        substr($k, 4, 4) === 'ftyp' => 'm4a',
        str_starts_with($k, 'RIFF') && substr($k, 8, 4) === 'WAVE' => 'wav',
        default => null,
    };
}

function musik_max_bytes(): int
{
    return (int)(config()['spiel']['musik_max_mb'] ?? 20) * 1024 * 1024;
}

/** Größte Datei, die PHP auf diesem Server annimmt (upload_max_filesize / post_max_size). */
function php_upload_grenze(): int
{
    $bytes = function (string $v): int {
        $v = trim($v);
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    return min($bytes((string)ini_get('upload_max_filesize')), $bytes((string)ini_get('post_max_size')));
}

function groesse(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB';
}

/**
 * Nimmt eine hochgeladene Datei an. $verschieben ist move_uploaded_file im Betrieb (Tests übergeben rename).
 * Gibt null bei Erfolg zurück, sonst die Fehlermeldung.
 */
function musik_hochladen(array $datei, string $titel, callable $verschieben = null): ?string
{
    $verschieben ??= 'move_uploaded_file';
    $fehler = (int)($datei['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
        return 'Die Datei ist größer, als der Server erlaubt (' . groesse(php_upload_grenze()) . ').';
    }
    if ($fehler === UPLOAD_ERR_NO_FILE) {
        return 'Bitte eine Audiodatei auswählen.';
    }
    if ($fehler !== UPLOAD_ERR_OK) {
        return "Der Upload ist fehlgeschlagen (Fehlercode {$fehler}). Bitte noch einmal versuchen.";
    }
    if ((int)$datei['size'] > musik_max_bytes()) {
        return 'Die Datei ist größer als ' . groesse(musik_max_bytes()) . '. Ein Loop braucht selten mehr – lieber als MP3 oder OGG speichern.';
    }
    $format = audioformat_erkennen((string)$datei['tmp_name']);
    if (!$format) {
        return 'Das ist keine erkennbare Audiodatei. Erlaubt sind ' . implode(', ', MUSIK_FORMATE) . '.';
    }
    if (!is_dir(MUSIK_DIR) && !mkdir(MUSIK_DIR, 0755, true)) {
        return 'Der Ordner public/uploads/musik fehlt und konnte nicht angelegt werden.';
    }
    $name = bin2hex(random_bytes(8)) . '.' . $format;
    if (!$verschieben((string)$datei['tmp_name'], MUSIK_DIR . '/' . $name)) {
        return 'Die Datei konnte nicht gespeichert werden. Hat der Ordner public/uploads/musik Schreibrechte?';
    }
    $titel = trim($titel) !== '' ? trim($titel) : pathinfo((string)($datei['name'] ?? 'Loop'), PATHINFO_FILENAME);
    $titel = mb_substr(preg_replace('/[\x00-\x1F]+/u', '', $titel), 0, 120);
    $db = db();
    $keineAktiv = !(int)$db->query('SELECT COUNT(*) FROM musik WHERE aktiv = 1')->fetchColumn();
    $db->prepare('INSERT INTO musik (datei, titel, groesse, aktiv, hochgeladen_ts) VALUES (?, ?, ?, ?, ?)')
       ->execute([$name, $titel, (int)$datei['size'], $keineAktiv ? 1 : 0, time()]);
    return null;
}

function musik_liste(): array
{
    return db()->query('SELECT * FROM musik ORDER BY hochgeladen_ts DESC, id DESC')->fetchAll();
}

function aktive_musik(): ?array
{
    return db()->query('SELECT * FROM musik WHERE aktiv = 1 LIMIT 1')->fetch() ?: null;
}

/** $id = 0 schaltet die Musik für alle aus. */
function musik_aktivieren(int $id): void
{
    $db = db();
    $db->beginTransaction();
    $db->exec('UPDATE musik SET aktiv = 0');
    if ($id) {
        $db->prepare('UPDATE musik SET aktiv = 1 WHERE id = ?')->execute([$id]);
    }
    $db->commit();
}

function musik_loeschen(int $id): ?string
{
    $st = db()->prepare('SELECT datei FROM musik WHERE id = ?');
    $st->execute([$id]);
    $datei = $st->fetchColumn();
    if ($datei === false) {
        return 'Diesen Loop gibt es nicht.';
    }
    db()->prepare('DELETE FROM musik WHERE id = ?')->execute([$id]);
    $pfad = MUSIK_DIR . '/' . basename((string)$datei);
    if (is_file($pfad)) {
        unlink($pfad);
    }
    return null;
}

// ---------------------------------------------------------------------
// Spieler
// ---------------------------------------------------------------------

function spielerliste(): array
{
    return db()->query('SELECT s.*, (SELECT COUNT(*) FROM bereiche b WHERE b.besitzer_id = s.id) AS bereiche,
                               (SELECT GROUP_CONCAT(o.name ORDER BY b.ist_heimat DESC SEPARATOR ", ") FROM bereiche b JOIN orte o ON o.id = b.ort_id WHERE b.besitzer_id = s.id) AS orte
                        FROM spieler s ORDER BY s.name')->fetchAll();
}

function admin_statistik(): array
{
    $db = db();
    $tag = time() - 86400;
    return [
        'spieler' => (int)$db->query('SELECT COUNT(*) FROM spieler')->fetchColumn(),
        'aktiv' => (int)$db->query("SELECT COUNT(*) FROM spieler WHERE zuletzt_ts >= {$tag}")->fetchColumn(),
        'bereiche' => (int)$db->query('SELECT COUNT(*) FROM bereiche WHERE besitzer_id IS NOT NULL')->fetchColumn(),
        'bereiche_gesamt' => (int)$db->query('SELECT COUNT(*) FROM bereiche')->fetchColumn(),
        'zuege' => (int)$db->query('SELECT COUNT(*) FROM zuege')->fetchColumn(),
        'gesperrt' => (int)$db->query('SELECT COUNT(*) FROM spieler WHERE gesperrt = 1')->fetchColumn(),
    ];
}

/** Gemeinsame Prüfung: Ziel existiert und ist nicht der Admin selbst. */
function admin_ziel(int $adminId, int $sid, bool $selbstErlaubt = false): array|string
{
    $st = db()->prepare('SELECT * FROM spieler WHERE id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) {
        return 'Diesen Spieler gibt es nicht.';
    }
    if (!$selbstErlaubt && $sid === $adminId) {
        return 'Das geht nicht bei deinem eigenen Konto.';
    }
    return $s;
}

function spieler_sperren(int $adminId, int $sid, bool $sperren, string $grund = ''): ?string
{
    $s = admin_ziel($adminId, $sid);
    if (is_string($s)) {
        return $s;
    }
    db()->prepare('UPDATE spieler SET gesperrt = ?, sperrgrund = ? WHERE id = ?')
        ->execute([$sperren ? 1 : 0, $sperren ? mb_substr(trim($grund), 0, 200) : '', $sid]);
    return null;
}

function admin_recht_setzen(int $adminId, int $sid, bool $admin): ?string
{
    $s = admin_ziel($adminId, $sid);
    if (is_string($s)) {
        return $s;
    }
    db()->prepare('UPDATE spieler SET ist_admin = ? WHERE id = ?')->execute([$admin ? 1 : 0, $sid]);
    return null;
}

/** Setzt ein neues Zufallspasswort und gibt es zurück (oder eine Fehlermeldung als ['fehler' => …]). */
function passwort_zuruecksetzen(int $adminId, int $sid): array
{
    $s = admin_ziel($adminId, $sid);
    if (is_string($s)) {
        return ['fehler' => $s];
    }
    // ohne leicht verwechselbare Zeichen (0/O, 1/l/I)
    $zeichen = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pw = '';
    for ($i = 0; $i < 10; $i++) {
        $pw .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    db()->prepare('UPDATE spieler SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $sid]);
    return ['name' => $s['name'], 'passwort' => $pw];
}

/** Löscht einen Spieler; seine Bereiche werden wieder frei. $bestaetigung muss der Spielername sein. */
function spieler_loeschen(int $adminId, int $sid, string $bestaetigung): ?string
{
    $s = admin_ziel($adminId, $sid);
    if (is_string($s)) {
        return $s;
    }
    if (trim($bestaetigung) !== $s['name']) {
        return 'Zur Sicherheit bitte den Spielernamen genau so eintippen, wie er angezeigt wird.';
    }
    return in_transaktion(function (PDO $db) use ($sid) {
        // Allianz geordnet verlassen (Vorstand wird ggf. weitergegeben, leere Allianz aufgelöst)
        gen_austritt_intern($db, $sid);
        $st = $db->prepare('SELECT id FROM bereiche WHERE besitzer_id = ? FOR UPDATE');
        $st->execute([$sid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $bid) {
            foreach (['gebaeude', 'einheiten', 'bauauftraege', 'werkstattauftraege', 'bunker', 'bunker_auslagerung'] as $t) {
                $db->prepare("DELETE FROM {$t} WHERE bereich_id = ?")->execute([$bid]);
            }
            $db->prepare('UPDATE bereiche SET besitzer_id = NULL, ist_heimat = 0, holz = 0, ziegel = 0, korn = 0, taler = 0, stand_ts = ? WHERE id = ?')
               ->execute([time(), $bid]);
        }
        // Forschung, Forschungsaufträge, eigene Züge, Nachrichten, Chat, Marktangebote und Vertretung fallen per Fremdschlüssel mit weg
        foreach (['DELETE FROM angriffe_log WHERE angreifer_id = ? OR verteidiger_id = ?', 'DELETE FROM spionage_log WHERE spaeher_id = ? OR ziel_id = ?'] as $sql) {
            $db->prepare($sql)->execute([$sid, $sid]);
        }
        $db->prepare('DELETE FROM angriffsschutz WHERE verteidiger_id = ?')->execute([$sid]);
        $db->prepare('DELETE FROM spieler WHERE id = ?')->execute([$sid]);
        return null;
    });
}

function rundschreiben(string $titel, string $text): ?string
{
    $titel = trim($titel);
    $text = trim($text);
    if ($titel === '' || $text === '') {
        return 'Bitte Betreff und Text ausfüllen.';
    }
    $ts = time();
    $inhalt = json_encode(['text' => mb_substr($text, 0, 2000)], JSON_UNESCAPED_UNICODE);
    db()->prepare("INSERT INTO nachrichten (spieler_id, ts, art, titel, inhalt) SELECT id, ?, 'admin', ?, ? FROM spieler")
        ->execute([$ts, mb_substr($titel, 0, 160), $inhalt]);
    return null;
}
