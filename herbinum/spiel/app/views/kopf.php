<?php
/** @var array $spieler @var array $echt @var array|null $vertretung @var array $z @var array $bereiche @var array $ort @var string $seite @var string $titel */
$b = $z['bereich'];
$r = $z['rate'];
$m = meldung_holen();
$zeit = tageszeit_text(time());
$wetterJetzt = ereignisse_bei($b, time());
$ist = fn(string $k) => $seite === $k ? ' aria-current="page"' : '';
$marke = fn(int $n, string $label) => $n > 0 ? ' <span class="zahlmarke" aria-label="' . $n . ' ' . $label . '">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
$icon = fn(string $n) => '<svg class="ic" aria-hidden="true"><use href="#i-' . $n . '"/></svg>';
$nav = [
    'Basis' => ['uebersicht' => ['Übersicht', 'hof'], 'gebaeude' => ['Gebäude', 'haus'], 'forschung' => ['Forschung', 'buch'],
              'werkstatt' => ['Fuhrpark', 'trecker'], 'schuetzen' => ['Sicherheitszentrale', 'ziel'], 'bunker' => ['Bunker', 'bunker']],
    'Region' => ['zuege' => ['Züge', 'weg'], 'karte' => ['Karte', 'karte'], 'markt' => ['Markt', 'waage']],
    'Community' => ['zeitung' => ['News', 'zeitung'], 'chat' => ['Chat', 'sprechblase'], 'nachrichten' => ['Nachrichten', 'brief'],
                    'allianz' => ['Allianz', 'wappen'], 'rangliste' => ['Rangliste', 'pokal'], 'vertretung' => ['Vertretung', 'uhr']],
];
if (berater_bereit()) {
    $nav['Basis']['berater'] = ['KI-Berater', 'funke'];
}
// Wer gerade online ist (für die Anzeige unten in der Seitenleiste)
$online = db()->query('SELECT name, farbe, zuletzt_ts FROM spieler WHERE zuletzt_ts >= ' . (time() - ONLINE_S) . ' ORDER BY name')->fetchAll();
$vertretungSeiten = ['uebersicht', 'gebaeude', 'forschung', 'werkstatt', 'schuetzen', 'bunker', 'zuege', 'karte', 'markt', 'rangliste', 'zeitung'];
$resIcon = ['holz' => 'holz', 'ziegel' => 'ziegel', 'korn' => 'korn', 'taler' => 'taler'];
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titel) ?> · Herbinum</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Zilla+Slab:wght@500;700&family=Source+Sans+3:wght@400;600;700&family=IBM+Plex+Mono:wght@500&display=swap">
<link rel="stylesheet" href="assets/style.css?v=8">
</head>
<body data-jetzt="<?= time() ?>" data-csrf="<?= h(csrf_token()) ?>" class="phase-<?= h($zeit['phase']) ?><?= $vertretung ? ' in-vertretung' : '' ?>">
<?php require __DIR__ . '/icons.php'; ?>
<div class="rahmen">
<aside class="seitenleiste" aria-label="Hauptmenü">
  <a class="brand" href="?s=uebersicht"><svg class="brand-zeichen" aria-hidden="true"><use href="#i-logo"/></svg><span>Herbinum</span></a>
  <nav class="hauptnav">
    <?php foreach ($nav as $gruppe => $eintraege): ?>
      <div class="navgruppe">
        <span class="navtitel"><?= h($gruppe) ?></span>
        <?php foreach ($eintraege as $k => [$name, $ic]):
            if ($vertretung && !in_array($k, $vertretungSeiten, true)) continue; ?>
          <a href="?s=<?= $k ?>"<?= $ist($k) ?>><?= $icon($ic) ?><span><?= $name ?></span><?php
            if ($k === 'nachrichten') echo $marke((int)($ungelesen ?? 0), 'ungelesen');
            if ($k === 'chat') echo $marke((int)($chat_neu ?? 0), 'neu');
            if ($k === 'zeitung' && !empty($zeitung_neu)) echo ' <span class="zahlmarke neu">neu</span>';
            if ($k === 'zuege' && !empty($ueberfaelle)) echo ' <span class="zahlmarke">!</span>'; ?></a>
        <?php endforeach ?>
      </div>
    <?php endforeach ?>
    <?php if ((int)$spieler['ist_admin']): ?>
      <div class="navgruppe"><a href="?s=admin"<?= $ist('admin') ?>><?= $icon('schluessel') ?><span>Spielleitung</span></a></div>
    <?php endif ?>
  </nav>
  <details class="online-anzeige">
    <summary><i class="lampe an"></i> <b><?= count($online) ?></b> online</summary>
    <ul><?php foreach ($online as $o): ?><li><?= spieler_html($o) ?></li><?php endforeach ?></ul>
  </details>
</aside>

<div class="hauptbereich">
<header class="kopf">
  <span class="tageszeit" title="Spieluhr: ein Tag dauert <?= h(str_replace('.', ',', (string)round(tageslaenge_s() / 3600, 1))) ?> Stunden"><span class="sonne" aria-hidden="true"><?= $zeit['icon'] ?></span> <?= h($zeit['name']) ?> · <span class="uhr" data-stunde="<?= round(spielstunde(time()), 4) ?>" data-faktor="<?= round(86400 / tageslaenge_s(), 4) ?>"><?= h($zeit['uhr']) ?></span> <small>Spielzeit</small></span>
  <?php if (count($bereiche) > 1): ?>
    <form method="get" class="bereichwahl">
      <input type="hidden" name="s" value="<?= h($seite) ?>">
      <label class="sr" for="bwahl">Bereich wechseln</label>
      <select id="bwahl" name="b" onchange="this.form.submit()">
        <?php foreach ($bereiche as $x): ?>
          <option value="<?= h($x['id']) ?>"<?= $x['id'] === $b['id'] ? ' selected' : '' ?>><?= h($x['ort'] . ' · ' . $x['name']) ?></option>
        <?php endforeach ?>
      </select>
    </form>
  <?php endif ?>
  <div class="wer">
    <?php if (!empty($musik)): ?>
      <span class="musik">
        <button type="button" id="musik" class="musik-knopf" aria-pressed="false" title="<?= h($musik['titel']) ?>"
                data-url="uploads/musik/<?= h($musik['datei']) ?>" data-id="<?= (int)$musik['id'] ?>" data-vol="<?= (int)($lautstaerke ?? 60) ?>">♪ Musik aus</button>
        <label class="sr" for="musik-vol">Lautstärke</label>
        <input type="range" id="musik-vol" min="0" max="100" step="5" hidden>
      </span>
    <?php endif ?>
    <?= spieler_html($echt + ['zuletzt_ts' => time()]) ?><?= !$vertretung && !empty($gen) ? ' ' . kuerzel_html($gen) : '' ?>
    <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="abmelden"><button class="link" type="submit">Abmelden</button></form>
  </div>
</header>

<?php if ($vertretung): ?>
  <div class="vertretung-band" role="status">
    <span><?= $icon('uhr') ?> Du vertrittst <b><?= h($spieler['name']) ?></b> bis <?= date('H:i', $vertretung['bis']) ?> Uhr.
      Erlaubt: <?= h(implode(', ', array_map(fn($x) => explode(' ', VERTRETUNG_RECHTE[$x])[0] . (str_contains(VERTRETUNG_RECHTE[$x], 'Einheiten') ? ' (Einheiten)' : ''), $vertretung['rechte']))) ?>. Alles wird ihm gemeldet.</span>
    <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="vertreten_ende"><button class="knopf hell" type="submit">Zurück zu meinem Konto</button></form>
  </div>
<?php elseif (!empty($eigenes_fenster)): ?>
  <div class="vertretung-band" role="status">
    <span><?= $icon('uhr') ?> Dein Vertretungsfenster läuft bis <?= date('H:i', $eigenes_fenster[1]) ?> Uhr – deine Allianz steuert, du schaust nur zu.</span>
    <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="vertretung_zurueck"><button class="knopf hell" type="submit">Ich bin wieder da</button></form>
  </div>
<?php endif ?>

<div class="ressourcen" role="group" aria-label="Vorräte in <?= h($b['name']) ?>">
  <?php foreach (RESSOURCEN as $k => $name):
      $max = $k === 'taler' ? 0 : $r['lager'];
      $voll = $max && $b[$k] >= $max; ?>
    <div class="res res-<?= $k ?><?= $voll ? ' voll' : '' ?>">
      <?= $icon($resIcon[$k]) ?>
      <div>
        <span class="res-name"><?= $name ?></span>
        <b data-wert="<?= $b[$k] ?>" data-rate="<?= $r[$k] ?>" data-max="<?= $max ?>"><?= zahl($b[$k]) ?></b>
        <small><?= ($r[$k] >= 0 ? '+' : '−') . zahl(round(abs($r[$k]))) ?>/h<?= $max ? ' · Lager ' . zahl($max) : '' ?></small>
        <?php if ($max): ?><span class="lagerbalken" aria-hidden="true"><i style="width:<?= min(100, round($b[$k] / max(1, $max) * 100)) ?>%"></i></span><?php endif ?>
      </div>
    </div>
  <?php endforeach ?>
  <div class="res res-strom<?= $r['eff'] < 1 ? ' knapp' : '' ?>">
    <?= $icon('strom') ?>
    <div>
      <span class="res-name">Strom</span>
      <b><?= zahl($r['strom_prod']) ?> / <?= zahl($r['strom_bedarf']) ?></b>
      <small><?= $r['eff'] < 1 ? 'Mangel – Betriebe auf ' . round($r['eff'] * 100) . ' %' : 'ausreichend' ?></small>
    </div>
  </div>
  <div class="res res-einwohner">
    <?= $icon('leute') ?>
    <div>
      <span class="res-name">Einwohner</span>
      <b><?= zahl($r['einwohner']) ?></b>
      <small>essen <?= zahl($r['korn_verbrauch']) ?> Nahrung/h</small>
    </div>
  </div>
</div>

<?php
$meinVerlust = verlust_stand(db(), (int)$spieler['id'], time())['verlust'];
$meinWiederaufbau = wiederaufbau_bis((int)$spieler['id']);
$meineBlauhelme = blauhelme_prozent(db(), $z['bereich']['id'], time());
if ($meinVerlust > 0.005 || $meinWiederaufbau > time() || $meineBlauhelme > 0): ?>
  <div class="schutz-band" role="status"><span aria-hidden="true">🛡</span>
    <span><?= $meinVerlust >= VERLUST_MAX_TAG - 0.001 ? '<b>Tagesgrenze erreicht:</b> Wer dich jetzt überfällt, bekommt keine Beute mehr – und die Blauhelme stehen in voller Stärke bereit.'
          : ($meinVerlust > 0.005 ? '<b>Verluste:</b> ' . round($meinVerlust * 100) . ' % deines Vermögens in den letzten ' . round(SCHUTZ_FENSTER_H / tempo()) . ' h – ab ' . round(VERLUST_MAX_TAG * 100) . ' % gibt es bei dir keine Beute mehr.' : '') ?>
      <?= $meineBlauhelme > 0 ? ' <b>Blauhelme</b> schützen diesen Bereich (noch etwa ' . $meineBlauhelme . ' %).' : '' ?>
      <?= $meinWiederaufbau > time() ? ' <b>Wiederaufbauhilfe:</b> +' . round(WIEDERAUFBAU_BONUS * 100) . ' % Produktion bis ' . date('H:i', $meinWiederaufbau) . ' Uhr.' : '' ?></span></div>
<?php endif ?>
<?php foreach (bedrohungen((int)$spieler['id']) as $bz):
    $wo = bereich_name(db(), $bz['ziel_id']); ?>
  <a class="meldung fehler alarm" href="?s=zuege" role="alert">⚠ <?= match ($bz['phase']) {
      'spaehen' => '<b>' . h($bz['absender']) . '</b> späht ' . h($wo) . ' aus – das Gefecht beginnt um ' . date('H:i', (int)$bz['ankunft_ts']) . ' Uhr. Jetzt Einheiten in den Bunker bringen oder verlegen.',
      'gefecht' => 'Gefecht in ' . h($wo) . ' gegen <b>' . h($bz['absender']) . '</b> – Ausgang um ' . date('H:i', (int)$bz['ankunft_ts']) . ' Uhr.',
      default => '<b>' . h($bz['absender']) . '</b> plündert ' . h($wo) . ' bis ' . date('H:i', (int)$bz['ankunft_ts']) . ' Uhr.',
  } ?></a>
<?php endforeach ?>
<?php foreach ($wetterJetzt as ['e' => $e, 's' => $s]):
    $def = EREIGNISSE[$e['typ']];
    $wirk = [];
    foreach ($def['wirkung'] as $k => $w) {
        $wirk[] = ($k === 'strom' ? 'Strom' : RESSOURCEN[$k]) . ' ' . ($w * $s > 0 ? '+' : '−') . round(abs($w * $s) * 100) . ' %';
    }
    if ($def['zug']) $wirk[] = 'Züge ' . round($def['zug'] * $s * 100) . ' % langsamer';
    if (!empty($def['markt'])) $wirk[] = 'Händler +' . round($def['markt'] * $s * 100) . ' % Verlust'; ?>
  <div class="unwetter-band typ-<?= h($e['typ']) ?>" role="status"><span class="unwetter-icon" aria-hidden="true"><?= $def['icon'] ?></span>
    <span><b><?= h($def['name']) ?></b> (<?= staerke_wort($s) ?>) in diesem Bereich bis <?= date('H:i', (int)$e['ende_ts']) ?> Uhr<?= $wirk ? ': ' . h(implode(' · ', $wirk)) : '' ?></span></div>
<?php endforeach ?>
<?php if (!empty($ueberfaelle)): ?>
  <a class="meldung fehler alarm" href="?s=zuege" role="alert"><?= (int)$ueberfaelle === 1 ? 'Ein Raubzug ist' : (int)$ueberfaelle . ' Raubzüge sind' ?> auf dem Weg zu <?= $vertretung ? h($spieler['name']) : 'dir' ?> – sieh nach, wann er ankommt.</a>
<?php endif ?>
<?php if ($m): ?>
  <div class="meldung <?= h($m[0]) ?>" role="status"><?= h($m[1]) ?></div>
<?php endif ?>
<main class="inhalt">
