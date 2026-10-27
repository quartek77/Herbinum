<?php
/** @var array $z @var array $spieler @var string $halle 'werkstatt' oder 'schuetzenhalle' */
$titel = GEBAEUDE[$halle]['name'];
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$stufe = (int)($z['stufen'][$halle] ?? 0);
$voll = count($z['werkstatt']) >= WERKSTATT_MAX_AUFTRAEGE;
$gruppen = $halle === 'werkstatt'
    ? ['Fahrzeuge' => array_filter(EINHEITEN, fn($e) => $e['halle'] === 'werkstatt')]
    : ['Einheiten' => array_filter(EINHEITEN, fn($e) => $e['halle'] === 'schuetzenhalle' && $e['beweglich']),
       'Verteidigung' => array_filter(EINHEITEN, fn($e) => $e['halle'] === 'schuetzenhalle' && !$e['beweglich'])];
[$vAtk, $vHp] = kampfwerte($z['fo'], true, (int)($z['stufen']['graben'] ?? 0), (int)$b['wehr']);
[$aAtk, $aHp] = kampfwerte($z['fo'], false);
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow"><?= h($ort['name']) ?> · <?= h($b['name']) ?> · <?= h(GEBAEUDE[$halle]['name']) ?> <?= $stufe ? "Stufe {$stufe}" : 'nicht gebaut' ?></div>
    <h1><?= h(GEBAEUDE[$halle]['name']) ?></h1>
  </div>
</div>

<div class="raster-gebaeude">
  <div class="spalte">
  <?php foreach ($gruppen as $gruppe => $liste): ?>
    <?php if (count($gruppen) > 1): ?><h2 class="gruppe"><?= h($gruppe) ?></h2><?php endif ?>
    <div class="gebaeudeliste">
    <?php foreach ($liste as $typ => $e):
        $fehlt = fehlende_voraussetzungen($e['braucht'], $z['stufen'], $z['fo']);
        $leistbar = PHP_INT_MAX;
        foreach ($e['kosten'] as $r => $k) {
            $leistbar = min($leistbar, (int)floor($b[$r] / $k));
        }
        $grund = $fehlt ? 'Braucht ' . implode(', ', $fehlt) : ($voll ? 'Bauschleife voll' : ($leistbar < 1 ? 'Zu wenig Rohstoffe' : ''));
        $werte = [];
        if ($e['beweglich']) {
            $werte[] = zahl(einheit_kmh($typ, $z['fo'])) . ' km/h';
            $werte[] = 'Ladung ' . zahl($e['ladung']);
            $werte[] = $e['korn_km'] ? str_replace('.', ',', (string)$e['korn_km']) . ' Nahrung/km' : 'braucht keine Nahrung';
        }
        $atk = $e['angriff'] * ($e['beweglich'] ? $aAtk : $vAtk);
        $hp = $e['leben'] * ($e['beweglich'] ? $aHp : $vHp);
        $werte[] = 'Angriff ' . zahl(round($atk)) . ' · Leben ' . zahl(round($hp)) . ($e['beweglich'] ? '' : ' (mit Schutzwall und Lage)');
        ?>
      <article class="gebaeude<?= $fehlt ? ' gesperrt' : '' ?>">
        <header>
          <h2><?= h($e['name']) ?></h2>
          <span class="stufe"><?= (int)($z['einheiten'][$typ] ?? 0) ?> hier</span>
        </header>
        <p><?= h($e['kurz']) ?></p>
        <p class="werte-zeile"><?= h(implode(' · ', $werte)) ?></p>
        <?= bedarf_html('einheit', $typ, $z, (int)$spieler['id'], $e['kosten']) ?>
        <dl class="kosten">
          <?php foreach ($e['kosten'] as $r => $k): ?>
            <div class="<?= $b[$r] < $k ? 'fehlt' : '' ?>"><dt><?= RESSOURCEN[$r] ?></dt><dd><?= zahl($k) ?></dd></div>
          <?php endforeach ?>
          <div><dt>je Stück</dt><dd><?= dauer(einheit_bauzeit($typ, $stufe, tempo())) ?></dd></div>
        </dl>
        <form method="post" class="anzahl-form">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="einheiten">
          <input type="hidden" name="typ" value="<?= $typ ?>">
          <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
          <input type="hidden" name="zurueck" value="<?= h($seite) ?>">
          <label class="sr" for="n-<?= $typ ?>">Anzahl <?= h($e['name']) ?></label>
          <input id="n-<?= $typ ?>" name="anzahl" type="number" min="1" max="<?= max(1, min(999, $leistbar)) ?>" value="1" inputmode="numeric"<?= $grund ? ' disabled' : '' ?>>
          <button class="knopf" type="submit"<?= $grund ? ' disabled' : '' ?>>Bauen</button>
          <?php if ($grund): ?><span class="grund"><?= h($grund) ?></span><?php elseif ($leistbar < 1000): ?><span class="grund">höchstens <?= $leistbar ?></span><?php endif ?>
        </form>
      </article>
    <?php endforeach ?>
    </div>
  <?php endforeach ?>
  </div>
  <div class="spalte">
    <section class="karte-box">
      <h2>Bauschleife</h2>
      <p class="leise">Fuhrpark und Sicherheitszentrale teilen sich eine Bauschleife (höchstens <?= WERKSTATT_MAX_AUFTRAEGE ?> Aufträge).</p>
      <?php if (!$z['werkstatt']): ?>
        <p class="leise">Gerade frei.</p>
      <?php else: ?>
        <ol class="queue">
          <?php foreach ($z['werkstatt'] as $w):
              $naechstes = (int)$w['start_ts'] + ((int)$w['fertig'] + 1) * (int)$w['stueck_s'];
              $ende = (int)$w['start_ts'] + (int)$w['anzahl'] * (int)$w['stueck_s']; ?>
            <li><div>
              <b><?= (int)$w['anzahl'] ?> × <?= h(EINHEITEN[$w['typ']]['name']) ?></b> (<?= (int)$w['fertig'] ?> fertig)
              <div class="leise">
                <?php if ($w['start_ts'] <= time()): ?>nächstes in <span class="countdown" data-ende="<?= $naechstes ?>"><?= dauer($naechstes - time()) ?></span> · <?php endif ?>
                alle fertig um <?= date('H:i', $ende) ?> Uhr<?= date('Ymd', $ende) !== date('Ymd') ? ' am ' . date('d.m.', $ende) : '' ?>
              </div>
            </div></li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </section>
    <?php if ($halle === 'schuetzenhalle'): ?>
      <section class="karte-box">
        <h2>Verteidigung dieses Bereichs</h2>
        <table class="tabelle">
          <tr><td>Schutzwall</td><td class="n">Stufe <?= (int)($z['stufen']['graben'] ?? 0) ?></td></tr>
          <tr><td>Wehrkraft der Lage</td><td class="n"><?= (int)$b['wehr'] ?> / 100</td></tr>
          <tr><td>Leben der Verteidiger</td><td class="n">×<?= number_format($vHp, 2, ',', '') ?></td></tr>
          <tr><td>Angriff (Einsatztraining)</td><td class="n">×<?= number_format($vAtk, 2, ',', '') ?></td></tr>
        </table>
        <?php if ($bis = schutz_bis(db(), (int)$spieler['id'])): ?>
          <p class="leise">Du stehst noch bis <?= date('d.m. H:i', $bis) ?> Uhr unter Anfängerschutz: Niemand kann dich überfallen, du kannst aber auch selbst nicht angreifen.</p>
        <?php endif ?>
        <p class="leise">Bei einem Überfall kämpfen alle Einheiten und Anlagen im Bereich mit. Zerstörte Barrikaden und Wachtürme wachsen zu <?= round(WIEDERAUFBAU_ANTEIL * 100) ?> % nach. Gewinnt der Angreifer, nimmt er bis zur Hälfte der Vorräte mit.</p>
      </section>
    <?php endif ?>
    <section class="karte-box">
      <h2>Hier stationiert</h2>
      <?php if (!$z['einheiten']): ?>
        <p class="leise">Noch keine Einheiten in diesem Bereich.</p>
      <?php else: ?>
        <table class="tabelle">
          <?php foreach ($z['einheiten'] as $typ => $n): ?><tr><td><?= h(EINHEITEN[$typ]['name']) ?></td><td class="n"><?= zahl($n) ?></td></tr><?php endforeach ?>
        </table>
        <?php if (bewegliche($z['einheiten'])): ?><a class="knopf" href="?s=zuege">Zug losschicken</a><?php endif ?>
      <?php endif ?>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
