<?php
// Spielregeln von Herbinum: Gebäude, Kosten, Bauzeiten, Produktion.
// Alle Werte an einer Stelle, damit das Balancing ohne Suche im Code geht.
declare(strict_types=1);

const RESSOURCEN = ['holz' => 'Holz', 'ziegel' => 'Baustoffe', 'korn' => 'Nahrung', 'taler' => 'Euro'];

// kosten = Kosten für Stufe 1, faktor = Steigerung pro Stufe (wie OGame)
const GEBAEUDE = [
    'hofstelle' => [
        'name' => 'Zentrale', 'kurz' => 'Das Herz deiner Basis. Jede Stufe verkürzt alle Bauzeiten und bringt Einwohner.',
        'kosten' => ['holz' => 400, 'ziegel' => 200, 'korn' => 100], 'faktor' => 2.0, 'braucht' => [],
    ],
    'holzplatz' => [
        'name' => 'Sägewerk', 'kurz' => 'Produziert Holz. Braucht Strom.',
        'kosten' => ['holz' => 60, 'ziegel' => 15], 'faktor' => 1.5, 'braucht' => [],
    ],
    'ziegelei' => [
        'name' => 'Baustoffwerk', 'kurz' => 'Produziert Baustoffe. Braucht Strom.',
        'kosten' => ['holz' => 48, 'ziegel' => 24], 'faktor' => 1.6, 'braucht' => [],
    ],
    'acker' => [
        'name' => 'Gewächshaus', 'kurz' => 'Produziert Nahrung. Nahrung versorgt die Einwohner und dient als Proviant für Transporter und Konvois.',
        'kosten' => ['holz' => 225, 'ziegel' => 75], 'faktor' => 1.5, 'braucht' => [],
    ],
    'windrad' => [
        'name' => 'Windpark', 'kurz' => 'Erzeugt Strom für Sägewerk, Baustoffwerk und Gewächshaus.',
        'kosten' => ['holz' => 75, 'ziegel' => 30], 'faktor' => 1.5, 'braucht' => [],
    ],
    'scheune' => [
        'name' => 'Lagerhalle', 'kurz' => 'Lager für Holz, Baustoffe und Nahrung. Was nicht hineinpasst, verfällt.',
        'kosten' => ['holz' => 1000], 'faktor' => 2.0, 'braucht' => [],
    ],
    'wohnhaus' => [
        'name' => 'Wohnviertel', 'kurz' => 'Mehr Einwohner. Einwohner zahlen Steuern in Euro und verbrauchen Nahrung.',
        'kosten' => ['holz' => 100, 'ziegel' => 80, 'korn' => 20], 'faktor' => 1.6, 'braucht' => ['hofstelle' => 1],
    ],
    'gasthof' => [
        'name' => 'Gastronomie', 'kurz' => 'Cafés, Bars, Restaurants: +3 Lebensqualität je Stufe – das lässt den Bereich wachsen.',
        'kosten' => ['holz' => 300, 'ziegel' => 200, 'korn' => 100, 'taler' => 50], 'faktor' => 1.8, 'braucht' => ['wohnhaus' => 2],
    ],
    'markt' => [
        'name' => 'Handelszentrum', 'kurz' => 'Bringt zusätzliche Euro und öffnet den Markt: Rohstoffe beim Händler tauschen. Jede Stufe erlaubt mehr Handel zu besseren Kursen.',
        'kosten' => ['holz' => 500, 'ziegel' => 300, 'korn' => 100], 'faktor' => 1.8, 'braucht' => ['wohnhaus' => 3],
    ],
    'dorfschule' => [
        'name' => 'Forschungszentrum', 'kurz' => 'Hier wird geforscht. Höhere Stufen forschen schneller und schalten weitere Forschungen frei.',
        'kosten' => ['holz' => 200, 'ziegel' => 400, 'korn' => 200], 'faktor' => 2.0, 'braucht' => ['hofstelle' => 2],
    ],
    'werkstatt' => [
        'name' => 'Fuhrpark', 'kurz' => 'Baut E-Bike-Kuriere, Transporter und Siedlerkonvois. Jede Stufe beschleunigt das.',
        'kosten' => ['holz' => 400, 'ziegel' => 200, 'korn' => 100], 'faktor' => 2.0, 'braucht' => ['hofstelle' => 2],
    ],
    'schuetzenhalle' => [
        'name' => 'Sicherheitszentrale', 'kurz' => 'Bildet Sicherheitskräfte und Einsatzteams aus und baut Barrikaden und Wachtürme. Jede Stufe beschleunigt das.',
        'kosten' => ['holz' => 400, 'ziegel' => 400, 'taler' => 100], 'faktor' => 2.0, 'braucht' => ['hofstelle' => 3],
    ],
    'graben' => [
        'name' => 'Schutzwall', 'kurz' => '+10 % Lebenspunkte für alle Verteidiger je Stufe.',
        'kosten' => ['holz' => 300, 'ziegel' => 600], 'faktor' => 1.9, 'braucht' => ['schuetzenhalle' => 1],
    ],
    'bunker' => [
        'name' => 'Bunker', 'kurz' => 'Gesicherte Tiefgarage. Eingelagerte Einheiten und Fahrzeuge sind vor Raubzügen und Erkundung sicher.',
        'kosten' => ['holz' => 600, 'ziegel' => 1500, 'taler' => 150], 'faktor' => 1.8, 'braucht' => ['hofstelle' => 3],
    ],
];

const START_RESSOURCEN = ['holz' => 500, 'ziegel' => 500, 'korn' => 150, 'taler' => 0];
const START_GEBAEUDE = ['hofstelle' => 1];
const BAULAND_JE_BAUPLATZ = 5;   // Bauland = Bauplätze × 5; jede Gebäudestufe belegt 1 Bauland

function f_stufe(int $l): float
{
    return $l * (1.1 ** $l);
}

function baukosten(string $typ, int $stufe): array
{
    $g = GEBAEUDE[$typ];
    $k = [];
    foreach (RESSOURCEN as $r => $_) {
        $k[$r] = (int)floor(($g['kosten'][$r] ?? 0) * ($g['faktor'] ** ($stufe - 1)));
    }
    return $k;
}

// Bauzeit in Sekunden (OGame-Formel, Zentrale statt Roboterfabrik)
function bauzeit(array $kosten, int $hofstelle, float $tempo): int
{
    $stunden = ($kosten['holz'] + $kosten['ziegel']) / (2500 * (1 + $hofstelle));
    return max(5, (int)round($stunden * 3600 / $tempo));
}

function lagerkapazitaet(int $scheune): float
{
    return 5000 * floor(2.5 * exp(20 * $scheune / 33));
}

/**
 * Produktion pro Stunde für einen Bereich bei gegebenen Gebäudestufen.
 * $b: Zeile aus `bereiche` (Werte + Boni), $lv: ['typ' => stufe], $fo: Forschung des Besitzers ['typ' => stufe]
 */
function produktion(array $b, array $lv, float $tempo, array $fo = [], array $mod = []): array
{
    $l = fn(string $t) => (int)($lv[$t] ?? 0);
    $bonus = fn(string $t) => 1 + FORSCHUNG[$t]['bonus'] * (int)($fo[$t] ?? 0);
    $m = fn(string $k) => 1 + (float)($mod[$k] ?? 0);      // Ereignisse (Sturm, Frost …), siehe ereignis_mods()
    $stromProd = 20 * f_stufe($l('windrad')) * (float)$b['mod_strom'] * $bonus('windkraft') * $m('strom');
    $stromBedarf = 10 * f_stufe($l('holzplatz')) + 10 * f_stufe($l('ziegelei')) + 20 * f_stufe($l('acker'));
    $eff = $stromBedarf > 0 ? min(1.0, $stromProd / $stromBedarf) : 1.0;

    $lq = min(100, (int)$b['lebensqualitaet'] + 3 * $l('gasthof'));
    $einwohner = (int)round((40 * f_stufe($l('hofstelle')) + 60 * f_stufe($l('wohnhaus'))) * (0.6 + $lq / 125));
    $eink = (int)$b['einkommen'];

    $holz = (20 + 30 * f_stufe($l('holzplatz')) * (float)$b['mod_holz'] * $eff * $bonus('forstwirtschaft')) * $m('holz');
    $ziegel = (10 + 20 * f_stufe($l('ziegelei')) * (float)$b['mod_ziegel'] * $eff * $bonus('ziegelbrand')) * $m('ziegel');
    $kornBrutto = 10 * f_stufe($l('acker')) * (float)$b['mod_korn'] * $eff * $bonus('fruchtfolge') * $m('korn');
    $kornVerbrauch = $einwohner * 0.05;
    $taler = ($einwohner * 0.08 * (0.5 + $eink / 100) + 25 * f_stufe($l('markt')) * ($eink / 50)) * $m('taler');

    return [
        'holz' => $holz * $tempo, 'ziegel' => $ziegel * $tempo,
        'korn' => ($kornBrutto - $kornVerbrauch) * $tempo, 'taler' => $taler * $tempo,
        'korn_brutto' => $kornBrutto * $tempo, 'korn_verbrauch' => $kornVerbrauch * $tempo,
        'strom_prod' => $stromProd, 'strom_bedarf' => $stromBedarf, 'eff' => $eff,
        'einwohner' => $einwohner, 'lebensqualitaet' => $lq, 'lager' => lagerkapazitaet($l('scheune')),
    ];
}

// ---------------------------------------------------------------------------
// Forschung (gilt für alle Bereiche eines Spielers, wird in einer Forschungszentrum erforscht)
// bonus = Wirkung je Stufe (0.05 = +5 %)
// ---------------------------------------------------------------------------
const FORSCHUNG = [
    'forstwirtschaft' => ['name' => 'Forstwirtschaft', 'kurz' => '+5 % Holz je Stufe.', 'bonus' => 0.05,
        'kosten' => ['holz' => 200, 'ziegel' => 100], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 1]],
    'ziegelbrand' => ['name' => 'Baustofftechnik', 'kurz' => '+5 % Baustoffe je Stufe.', 'bonus' => 0.05,
        'kosten' => ['holz' => 150, 'ziegel' => 200], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 1]],
    'fruchtfolge' => ['name' => 'Agrartechnik', 'kurz' => '+5 % Nahrung je Stufe.', 'bonus' => 0.05,
        'kosten' => ['holz' => 100, 'ziegel' => 100, 'korn' => 200], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 1]],
    'windkraft' => ['name' => 'Windkraft', 'kurz' => '+5 % Strom je Stufe.', 'bonus' => 0.05,
        'kosten' => ['holz' => 100, 'ziegel' => 400, 'taler' => 50], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 1]],
    'mechanik' => ['name' => 'Fahrzeugtechnik', 'kurz' => 'Transporter und Siedlerkonvois fahren 10 % schneller je Stufe.', 'bonus' => 0.10,
        'kosten' => ['holz' => 400, 'korn' => 600], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 1, 'werkstatt' => 1]],
    'paettkes' => ['name' => 'Radwegenetz', 'kurz' => 'E-Bike-Kuriere und Sicherheitskräfte fahren 10 % schneller je Stufe.', 'bonus' => 0.10,
        'kosten' => ['holz' => 300, 'ziegel' => 200, 'taler' => 50], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 1]],
    'dorftratsch' => ['name' => 'Informationsnetz', 'kurz' => 'Erkundungsberichte verraten mehr: erst Einheiten, dann Gebäude, dann Forschung.', 'bonus' => 0,
        'kosten' => ['holz' => 200, 'ziegel' => 1000, 'korn' => 200], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 3]],
    'verwaltung' => ['name' => 'Verwaltung', 'kurz' => 'Erlaubt weitere Bereiche: Stufe 1 → 2 Bereiche, Stufe 3 → 3, Stufe 5 → 4 …', 'bonus' => 0,
        'kosten' => ['holz' => 2000, 'ziegel' => 4000, 'korn' => 2000], 'faktor' => 1.75, 'braucht' => ['dorfschule' => 3, 'mechanik' => 2]],
    'schiessausbildung' => ['name' => 'Einsatztraining', 'kurz' => '+10 % Angriff für Sicherheitskräfte, Einsatzteams und Wachtürme je Stufe.', 'bonus' => 0.10,
        'kosten' => ['holz' => 400, 'ziegel' => 200, 'taler' => 100], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 2, 'schuetzenhalle' => 1]],
    'schutzausruestung' => ['name' => 'Schutzausrüstung', 'kurz' => '+10 % Lebenspunkte für alle Einheiten und Anlagen je Stufe.', 'bonus' => 0.10,
        'kosten' => ['holz' => 600, 'ziegel' => 400, 'taler' => 100], 'faktor' => 2.0, 'braucht' => ['dorfschule' => 2, 'schuetzenhalle' => 1]],
    'handelskunde' => ['name' => 'Handelskunde', 'kurz' => 'Bessere Kurse beim Händler (−3 % Verlust) und +25 % Handelsmenge je Stufe. Ab Stufe 2 Handel mit anderen Spielern.', 'bonus' => 0.25,
        'kosten' => ['holz' => 300, 'ziegel' => 300, 'taler' => 150], 'faktor' => 1.9, 'braucht' => ['dorfschule' => 1, 'markt' => 1]],
    'bunkerbau' => ['name' => 'Bunkerbau', 'kurz' => '+25 % Bunkerplätze und 10 % schnelleres Auslagern je Stufe.', 'bonus' => 0.25,
        'kosten' => ['holz' => 500, 'ziegel' => 1200, 'taler' => 100], 'faktor' => 1.9, 'braucht' => ['dorfschule' => 2, 'bunker' => 1]],
];

function forschungskosten(string $typ, int $stufe): array
{
    $f = FORSCHUNG[$typ];
    $k = [];
    foreach (RESSOURCEN as $r => $_) {
        $k[$r] = (int)floor(($f['kosten'][$r] ?? 0) * ($f['faktor'] ** ($stufe - 1)));
    }
    return $k;
}

function forschungszeit(array $kosten, int $dorfschule, float $tempo): int
{
    $stunden = ($kosten['holz'] + $kosten['ziegel']) / (1000 * (1 + $dorfschule));
    return max(5, (int)round($stunden * 3600 / $tempo));
}

function max_bereiche(array $fo): int
{
    return 1 + intdiv((int)($fo['verwaltung'] ?? 0) + 1, 2);
}

// ---------------------------------------------------------------------------
// Einheiten und Verteidigung
// halle = Gebäude, in dem gebaut wird (dessen Stufe verkürzt die Bauzeit)
// beweglich = false: Verteidigungsanlage, bleibt im Bereich
// tempo in km/h, ladung in Rohstoffen, korn_km = Nahrungsverbrauch pro km und Einheit
// angriff = Schaden pro Kampfrunde, leben = Lebenspunkte
// ---------------------------------------------------------------------------
const EINHEITEN = [
    'fahrradkurier' => ['name' => 'E-Bike-Kurier', 'kurz' => 'Schnell und sparsam. Erkundet fremde Bereiche.',
        'kosten' => ['holz' => 100, 'ziegel' => 300], 'tempo' => 30, 'ladung' => 20, 'korn_km' => 0, 'angriff' => 0, 'leben' => 5,
        'forschung' => 'paettkes', 'halle' => 'werkstatt', 'beweglich' => true, 'braucht' => ['werkstatt' => 1]],
    'trecker' => ['name' => 'Transporter', 'kurz' => 'Bringt Rohstoffe zu anderen Bereichen und fährt bei Raubzügen die Beute heim.',
        'kosten' => ['holz' => 2000, 'ziegel' => 2000], 'tempo' => 25, 'ladung' => 2500, 'korn_km' => 3, 'angriff' => 0, 'leben' => 300,
        'forschung' => 'mechanik', 'halle' => 'werkstatt', 'beweglich' => true, 'braucht' => ['werkstatt' => 2, 'mechanik' => 1]],
    'siedlertreck' => ['name' => 'Siedlerkonvoi', 'kurz' => 'Umzugskonvoi mit Pionieren. Gründet eine neue Zentrale in einem freien Bereich und bleibt dort.',
        'kosten' => ['holz' => 10000, 'ziegel' => 20000, 'korn' => 10000, 'taler' => 500], 'tempo' => 6, 'ladung' => 1000, 'korn_km' => 15, 'angriff' => 0, 'leben' => 600,
        'forschung' => 'mechanik', 'halle' => 'werkstatt', 'beweglich' => true, 'braucht' => ['werkstatt' => 3, 'mechanik' => 2]],
    'schuetze' => ['name' => 'Sicherheitskraft', 'kurz' => 'Mit dem E-Scooter unterwegs. Günstig – für Raubzüge und zur Verteidigung.',
        'kosten' => ['holz' => 200, 'ziegel' => 50, 'korn' => 150, 'taler' => 20], 'tempo' => 12, 'ladung' => 50, 'korn_km' => 0.2, 'angriff' => 10, 'leben' => 40,
        'forschung' => 'paettkes', 'halle' => 'schuetzenhalle', 'beweglich' => true, 'braucht' => ['schuetzenhalle' => 1]],
    'reiter' => ['name' => 'Einsatzteam', 'kurz' => 'Schnelle Eingreiftruppe im Geländewagen: stark, aber teuer im Unterhalt.',
        'kosten' => ['holz' => 800, 'ziegel' => 200, 'korn' => 1200, 'taler' => 150], 'tempo' => 22, 'ladung' => 150, 'korn_km' => 1.5, 'angriff' => 40, 'leben' => 150,
        'forschung' => null, 'halle' => 'schuetzenhalle', 'beweglich' => true, 'braucht' => ['schuetzenhalle' => 3, 'schiessausbildung' => 2]],
    'wallhecke' => ['name' => 'Barrikade', 'kurz' => 'Mobile Sperren und Container. Fangen Angriffe ab und werden zum Großteil wieder aufgebaut.',
        'kosten' => ['holz' => 150, 'ziegel' => 50], 'tempo' => 0, 'ladung' => 0, 'korn_km' => 0, 'angriff' => 0, 'leben' => 250,
        'forschung' => null, 'halle' => 'schuetzenhalle', 'beweglich' => false, 'braucht' => ['schuetzenhalle' => 1]],
    'hochsitz' => ['name' => 'Wachturm', 'kurz' => 'Erhöhter Posten mit Kameras und Scheinwerfern, von dem aus Angreifer abgewehrt werden.',
        'kosten' => ['holz' => 600, 'ziegel' => 300, 'taler' => 30], 'tempo' => 0, 'ladung' => 0, 'korn_km' => 0, 'angriff' => 30, 'leben' => 120,
        'forschung' => null, 'halle' => 'schuetzenhalle', 'beweglich' => false, 'braucht' => ['schuetzenhalle' => 2]],
];

const AUFTRAEGE = [
    'transport' => 'Transport',        // Rohstoffe abliefern, leer zurück
    'verlegen' => 'Verlegen',          // Einheiten + Ladung bleiben im eigenen Zielbereich
    'erkunden' => 'Erkunden',          // E-Bike-Kurier späht aus, kommt zurück
    'kolonisieren' => 'Besiedeln',     // Siedlerkonvoi gründet eine Zentrale
    'angriff' => 'Raubzug',            // Kampf, bei Sieg Beute
];
const UMWEG_FAKTOR = 1.3;              // Straße statt Luftlinie
const MIN_STRECKE_KM = 0.5;

// Kampf
const KAMPF_RUNDEN = 4;
const BEUTE_ANTEIL = 0.5;              // höchstens die Hälfte der Vorräte
const WIEDERAUFBAU_ANTEIL = 0.7;       // zerstörte Verteidigung wächst zu 70 % nach
const GRABEN_BONUS = 0.10;             // +10 % Leben aller Verteidiger je Schutzwallstufe

function einheit_bauzeit(string $typ, int $hallenstufe, float $tempo): int
{
    $k = EINHEITEN[$typ]['kosten'];
    $stunden = (($k['holz'] ?? 0) + ($k['ziegel'] ?? 0)) / (2500 * (1 + $hallenstufe));
    return max(2, (int)round($stunden * 3600 / $tempo));
}

function einheit_kmh(string $typ, array $fo): float
{
    $e = EINHEITEN[$typ];
    return $e['tempo'] * (1 + ($e['forschung'] ? FORSCHUNG[$e['forschung']]['bonus'] * (int)($fo[$e['forschung']] ?? 0) : 0));
}

/** Nur Einheiten, die mitfahren können (keine Verteidigungsanlagen). */
function bewegliche(array $einheiten): array
{
    return array_filter($einheiten, fn($n, $t) => EINHEITEN[$t]['beweglich'] ?? false, ARRAY_FILTER_USE_BOTH);
}

function strecke_km(array $von, array $nach): float
{
    $luft = hypot((float)$von['x'] - (float)$nach['x'], (float)$von['y'] - (float)$nach['y']) / 1000;
    return max(MIN_STRECKE_KM, $luft * UMWEG_FAKTOR);
}

/** Langsamste Einheit bestimmt das Tempo des Zuges (km/h). */
function zug_tempo(array $einheiten, array $fo): float
{
    $v = INF;
    foreach ($einheiten as $typ => $n) {
        if ($n > 0) {
            $v = min($v, einheit_kmh($typ, $fo));
        }
    }
    return is_finite($v) ? $v : 1.0;
}

function reisezeit(float $km, float $kmh, float $tempo): int
{
    return max(10, (int)round($km / $kmh * 3600 / $tempo));
}

function zug_ladung(array $einheiten): int
{
    $s = 0;
    foreach ($einheiten as $typ => $n) {
        $s += EINHEITEN[$typ]['ladung'] * $n;
    }
    return $s;
}

/** Nahrungsverbrauch für die Fahrt; Rückfahrt inklusive, außer bei Verlegen und Besiedeln. */
function zug_verbrauch(array $einheiten, float $km, string $auftrag): int
{
    $s = 0;
    foreach ($einheiten as $typ => $n) {
        $s += EINHEITEN[$typ]['korn_km'] * $n * $km;
    }
    return (int)ceil($s * (in_array($auftrag, ['verlegen', 'kolonisieren'], true) ? 1 : 2));
}

// ---------------------------------------------------------------------------
// Markt
// Händler: eigene Rohstoffe gegen andere tauschen – zum Grundwert, abzüglich Verlust.
// Spielerhandel: Angebote an alle oder nur an die Allianz, Gebühr in Prozent der erhaltenen Menge.
// ---------------------------------------------------------------------------
const MARKT_WERT = ['holz' => 1.0, 'ziegel' => 1.5, 'korn' => 1.2, 'taler' => 4.0];   // Grundwert je Einheit
const MARKT_VERLUST_START = 0.40;          // 40 % Verlust beim Händler mit Handelszentrum Stufe 1 …
const MARKT_VERLUST_JE_STUFE = 0.02;       // … minus 2 Punkte je weiterer Handelszentrum-Stufe
const MARKT_VERLUST_JE_FORSCHUNG = 0.03;   // … minus 3 Punkte je Stufe Handelskunde
const MARKT_VERLUST_MIN = 0.10;            // besser als 10 % Verlust wird es nie
const MARKT_MENGE_JE_STUFE = 1500;         // Tageslimit (abgegebener Grundwert) je Handelszentrum-Stufe, × Tempo
const MARKT_GEBUEHR = 0.10;                // Spielerhandel: 10 % der erhaltenen Menge bleiben beim Markt …
const MARKT_GEBUEHR_ALLIANZ = 0.02;        // … unter Allianzmitgliedern nur 2 %
const MARKT_SPIELER_AB = 2;                // Spielerhandel ab Handelskunde Stufe 2
const MARKT_ANGEBOT_TAGE = 3;              // Angebote verfallen nach 3 Tagen, die Ware geht zurück
const MARKT_KURS_SPANNE = [0.5, 2.0];      // Gegenwert eines Angebots: 50 % bis 200 % des Grundwerts (kein Verschenken)

/** Verlust beim Händler (0,10 bis 0,40) – ohne Ereignisse. */
function markt_verlust(int $marktStufe, array $fo): float
{
    return max(MARKT_VERLUST_MIN, MARKT_VERLUST_START - MARKT_VERLUST_JE_STUFE * max(0, $marktStufe - 1)
        - MARKT_VERLUST_JE_FORSCHUNG * (int)($fo['handelskunde'] ?? 0));
}

/** Wie viel Grundwert ein Spieler in 24 Stunden beim Händler abgeben darf (Handelszentrum des Bereichs zählt). */
function markt_tageslimit(int $marktStufe, array $fo, float $tempo): int
{
    return (int)(MARKT_MENGE_JE_STUFE * $marktStufe * (1 + FORSCHUNG['handelskunde']['bonus'] * (int)($fo['handelskunde'] ?? 0)) * $tempo);
}

/** Händlerkurs: so viel $nach bekommt man für $menge $von. */
function markt_tausch(string $von, string $nach, int $menge, float $verlust): int
{
    return (int)floor($menge * MARKT_WERT[$von] / MARKT_WERT[$nach] * (1 - $verlust));
}

function max_angebote(array $fo): int
{
    return 1 + (int)($fo['handelskunde'] ?? 0);
}

// ---------------------------------------------------------------------------
// Bunker
// ---------------------------------------------------------------------------
const BUNKER_PLATZ = ['fahrradkurier' => 1, 'schuetze' => 1, 'reiter' => 2, 'trecker' => 4, 'siedlertreck' => 6];
const BUNKER_PLAETZE_JE_STUFE = 15;
const BUNKER_AUSLAGERN_STUNDEN = 4.0;      // so lange dauert das Auslagern bei Bunker Stufe 1 ohne Forschung
const BUNKER_AUSLAGERN_FAKTOR = 0.9;       // je weitere Bunkerstufe und je Stufe Bunkerbau 10 % schneller
const BUNKER_AUSLAGERN_MIN_S = 900;        // nie schneller als 15 Minuten

function bunker_plaetze(int $stufe, array $fo): int
{
    return (int)floor(BUNKER_PLAETZE_JE_STUFE * $stufe * (1 + FORSCHUNG['bunkerbau']['bonus'] * (int)($fo['bunkerbau'] ?? 0)));
}

function bunker_auslagern_s(int $stufe, array $fo, float $tempo): int
{
    $h = BUNKER_AUSLAGERN_STUNDEN * BUNKER_AUSLAGERN_FAKTOR ** max(0, $stufe - 1) * BUNKER_AUSLAGERN_FAKTOR ** (int)($fo['bunkerbau'] ?? 0);
    return max(BUNKER_AUSLAGERN_MIN_S, (int)round($h * 3600 / $tempo));
}

function bunker_belegt(array $einheiten): int
{
    $n = 0;
    foreach ($einheiten as $typ => $anzahl) {
        $n += (BUNKER_PLATZ[$typ] ?? 0) * (int)$anzahl;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Tag und Nacht, Zufallsereignisse
// Ein Spieltag dauert config spiel.spieltag_stunden (Standard 24) und beginnt um Mitternacht.
// ---------------------------------------------------------------------------
const NACHT_VON = 21;                      // Uhrzeit im Spieltag (0–24)
const NACHT_BIS = 6;
const NACHT_VERTEIDIGUNG = 0.10;           // nachts +10 % Leben für Verteidiger (Nachtwache)
const EREIGNIS_SCHWELLE = 0.15;            // darunter spürt ein Bereich nichts
const EREIGNIS_TAGE_VORAUS = 4;            // so weit im Voraus wird geplant (und vorhergesagt)
const EREIGNIS_CHANCE_JE_TAG = 0.22;       // Chance, dass an einem Spieltag etwas passiert (× haeufigkeit aus config)

// dauer_h = Dauer in Spielstunden; wirkung = Produktionsänderung bei voller Stärke (−0,3 = 30 % weniger);
// verlust = einmaliger Anteil der Vorräte bei voller Stärke; zug = Züge so viel langsamer; gewicht = relative Häufigkeit
const EREIGNISSE = [
    'sturm' => ['name' => 'Sturm', 'icon' => '🌪', 'dauer_h' => 8, 'gewicht' => 5, 'radius' => [9000, 18000],
        'wirkung' => ['holz' => -0.3, 'strom' => 0.5], 'verlust' => ['holz' => 0.08, 'ziegel' => 0.05], 'zug' => 0.25,
        'text' => 'Orkanböen decken Dächer ab und wirbeln Baustellen durcheinander. Die Windparks laufen dafür auf Rekordniveau.'],
    'frost' => ['name' => 'Starker Frost', 'icon' => '❄', 'dauer_h' => 30, 'gewicht' => 4, 'radius' => [15000, 30000],
        'wirkung' => ['korn' => -0.5, 'ziegel' => -0.3], 'verlust' => ['korn' => 0.04], 'zug' => 0.1,
        'text' => 'Dauerfrost legt Gewächshäuser und Baustoffwerke teilweise lahm.'],
    'starkregen' => ['name' => 'Starkregen und Hochwasser', 'icon' => '🌧', 'dauer_h' => 14, 'gewicht' => 5, 'radius' => [7000, 15000],
        'wirkung' => ['korn' => -0.25, 'ziegel' => -0.25], 'verlust' => ['korn' => 0.10], 'zug' => 0.3,
        'text' => 'Starkregen flutet Keller und Unterführungen, Straßen sind gesperrt.'],
    'hagel' => ['name' => 'Hagelschlag', 'icon' => '🌨', 'dauer_h' => 6, 'gewicht' => 3, 'radius' => [4000, 9000],
        'wirkung' => ['korn' => -0.35], 'verlust' => ['korn' => 0.12, 'ziegel' => 0.04], 'zug' => 0.1,
        'text' => 'Golfballgroße Hagelkörner beschädigen Gewächshäuser, Dächer und Fahrzeuge.'],
    'krise' => ['name' => 'Wirtschaftskrise', 'icon' => '📉', 'dauer_h' => 48, 'gewicht' => 2, 'radius' => [25000, 40000],
        'wirkung' => ['taler' => -0.4], 'verlust' => ['taler' => 0.05], 'zug' => 0.0, 'markt' => 0.15,
        'text' => 'Die Märkte brechen ein, die Steuereinnahmen sinken. Händler verlangen höhere Aufschläge.'],
    'zombies' => ['name' => 'Zombie-Horde', 'icon' => '🧟', 'dauer_h' => 4, 'gewicht' => 1, 'radius' => [5000, 11000],
        'wirkung' => ['korn' => -0.2], 'verlust' => ['korn' => 0.06], 'zug' => 0.2, 'einheiten' => 0.06,
        'text' => 'Eine Zombie-Horde zieht durch die Region. Wer keine Sicherheitskräfte und Wachtürme hat, verliert Leute.'],
    'erdbeben' => ['name' => 'Erdbeben', 'icon' => '〰', 'dauer_h' => 2, 'gewicht' => 1, 'radius' => [8000, 16000],
        'wirkung' => [], 'verlust' => ['ziegel' => 0.08, 'holz' => 0.03], 'zug' => 0.0, 'bau_h' => 1.0,
        'text' => 'Ein Beben aus alten Bergbaustollen: Risse in Gebäuden, Baustellen ruhen eine Weile.'],
];
