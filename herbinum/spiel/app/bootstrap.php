<?php
// Gemeinsamer Start für jede Anfrage: Konfiguration, Datenbank, Sitzung, Hilfsfunktionen.
declare(strict_types=1);

const APP_DIR = __DIR__;
define('ROOT_DIR', dirname(__DIR__));
const SCHEMA_VERSION = 6;               // erhöhen, wenn schema.sql neue Tabellen/Spalten bekommt
const INSTALL_LOCK = APP_DIR . '/.installiert';
define('MUSIK_DIR', ROOT_DIR . '/public/uploads/musik');
define('WAPPEN_DIR', ROOT_DIR . '/public/uploads/wappen');

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

require APP_DIR . '/rules.php';
require APP_DIR . '/economy.php';
require APP_DIR . '/einheiten.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/admin.php';
require APP_DIR . '/gemeinschaft.php';
require APP_DIR . '/ereignisse.php';
require APP_DIR . '/markt.php';
require APP_DIR . '/kommunikation.php';
require APP_DIR . '/vertretung.php';
require APP_DIR . '/planung.php';
require APP_DIR . '/ki.php';

/** 0 = nicht installiert; Version 1 schrieb ein Datum in die Sperrdatei. */
function installierte_version(): int
{
    if (!is_file(INSTALL_LOCK)) {
        return 0;
    }
    $v = trim((string)file_get_contents(INSTALL_LOCK));
    return ctype_digit($v) ? (int)$v : 1;
}

function config(): array
{
    static $c = null;
    if ($c === null) {
        $file = ROOT_DIR . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('config.php fehlt. Kopiere config.example.php nach config.php und trage die Datenbankdaten ein.');
        }
        $c = require $file;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = config()['db'];
        $pdo = new PDO(
            "mysql:host={$d['host']};port=" . ($d['port'] ?? 3306) . ";dbname={$d['name']};charset=utf8mb4",
            $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function tempo(): float
{
    return (float)(config()['spiel']['tempo'] ?? 1.0);
}

function sitzung_starten(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('herbinum');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function zahl(float $n): string
{
    return number_format(floor($n), 0, ',', '.');
}

function dauer(int $s): string
{
    $s = max(0, $s);
    $t = intdiv($s, 86400);
    $s %= 86400;
    $hms = sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    return $t > 0 ? "{$t} T {$hms}" : $hms;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_feld(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_pruefen(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.');
    }
}

function weiter(string $seite, array $params = []): never
{
    header('Location: ?' . http_build_query(['s' => $seite] + $params), true, 303);
    exit;
}

function meldung(string $text, string $art = 'ok'): void
{
    $_SESSION['meldung'] = [$art, $text];
}

function meldung_holen(): ?array
{
    $m = $_SESSION['meldung'] ?? null;
    unset($_SESSION['meldung']);
    return $m;
}

function ansicht(string $name, array $daten = []): void
{
    extract($daten, EXTR_SKIP);
    require APP_DIR . "/views/{$name}.php";
}
