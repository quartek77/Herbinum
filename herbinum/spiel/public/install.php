<?php
// Installation und Updates: legt fehlende Tabellen/Spalten an und importiert Orte + Bereiche aus data/.
// Spielstände bleiben erhalten. Aufruf im Browser: install.php?key=<install_key aus config.php>   oder per SSH: php install.php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$cli = PHP_SAPI === 'cli';
header('Content-Type: text/plain; charset=utf-8');

if (!$cli && !hash_equals((string)config()['install_key'], (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Falscher oder fehlender Schlüssel. Aufruf: install.php?key=<install_key aus config.php>\n");
}
$version = installierte_version();
if ($version >= SCHEMA_VERSION) {
    exit("Herbinum ist auf dem neuesten Stand (Version {$version}).\n");
}

$db = db();
$schema = preg_replace('/^--.*$/m', '', file_get_contents(APP_DIR . '/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
    $db->exec($sql);
}
// Spalten, die nach Version 1 dazugekommen sind (ADD COLUMN IF NOT EXISTS kann MySQL nicht)
$spalte = function (string $tabelle, string $name, string $def) use ($db): void {
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tabelle, $name]);
    if (!(int)$st->fetchColumn()) {
        $db->exec("ALTER TABLE {$tabelle} ADD COLUMN {$name} {$def}");
    }
};
$spalte('bereiche', 'x', 'INT NOT NULL DEFAULT 0');
$spalte('bereiche', 'y', 'INT NOT NULL DEFAULT 0');
$spalte('spieler', 'gesperrt', 'TINYINT(1) NOT NULL DEFAULT 0');
$spalte('spieler', 'sperrgrund', "VARCHAR(200) NOT NULL DEFAULT ''");
$spalte('spieler', 'genossenschaft_id', 'INT UNSIGNED NULL DEFAULT NULL');
$spalte('spieler', 'gen_rang', "VARCHAR(12) NOT NULL DEFAULT ''");
$spalte('spieler', 'gen_seit_ts', 'INT UNSIGNED NOT NULL DEFAULT 0');
$spalte('spieler', 'chat_alle', 'INT UNSIGNED NOT NULL DEFAULT 0');
$spalte('spieler', 'chat_allianz', 'INT UNSIGNED NOT NULL DEFAULT 0');
$spalte('spieler', 'chat_privat', 'INT UNSIGNED NOT NULL DEFAULT 0');
$spalte('spieler', 'zeitung_tag', 'INT UNSIGNED NOT NULL DEFAULT 0');
$spalte('genossenschaften', 'wappen', "VARCHAR(64) NOT NULL DEFAULT ''");
foreach (['musik' => MUSIK_DIR, 'wappen' => WAPPEN_DIR] as $n => $ordner) {
    if (!is_dir($ordner) && !mkdir($ordner, 0755, true)) {
        echo "Achtung: Ordner public/uploads/{$n} konnte nicht angelegt werden – bitte per FTP anlegen.\n";
    }
}
echo "Tabellen sind aktuell.\n";

$region = json_decode(file_get_contents(__DIR__ . '/data/region.json'), true, 512, JSON_THROW_ON_ERROR);
$insOrt = $db->prepare('INSERT INTO orte (id, name, x, y, radius, typ) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
$insBer = $db->prepare('INSERT INTO bereiche (id, ort_id, nr, name, typ, ha, bauplaetze, lebensqualitaet, wehr, einkommen, mod_holz, mod_ziegel, mod_korn, mod_strom, x, y, stand_ts)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE name = VALUES(name), typ = VALUES(typ), bauplaetze = VALUES(bauplaetze), lebensqualitaet = VALUES(lebensqualitaet),
                          wehr = VALUES(wehr), einkommen = VALUES(einkommen), mod_holz = VALUES(mod_holz), mod_ziegel = VALUES(mod_ziegel),
                          mod_korn = VALUES(mod_korn), mod_strom = VALUES(mod_strom), x = VALUES(x), y = VALUES(y)');
$n = 0;
$db->beginTransaction();
foreach ($region['orte'] as $o) {
    $ox = $o['x'] * $region['q'];
    $oy = $o['y'] * $region['q'];
    $insOrt->execute([$o['id'], $o['name'], $ox, $oy, $o['r'], $o['typ']]);
    $ort = json_decode(file_get_contents(__DIR__ . "/data/ort/{$o['id']}.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($ort['bereiche'] as $b) {
        // stand_ts nur bei neuen Bereichen relevant; bestehende behalten ihren Stand
        $insBer->execute([$b['id'], $o['id'], $b['nr'], $b['name'], $b['typ'], $b['ha'], $b['felder'],
            $b['score']['lebensqualitaet'], $b['score']['wehr'], $b['score']['einkommen'],
            $b['mod']['holz'], $b['mod']['ziegel'], $b['mod']['korn'], $b['mod']['strom'], $ox + $b['lx'], $oy + $b['ly'], time()]);
        $n++;
    }
}
$db->commit();
echo count($region['orte']) . " Orte und {$n} Bereiche importiert.\n";

file_put_contents(INSTALL_LOCK, (string)SCHEMA_VERSION);
echo $version ? "Update von Version {$version} auf " . SCHEMA_VERSION . " fertig.\n" : "Fertig. Der erste Spieler, der sich registriert, wird Admin.\n";
