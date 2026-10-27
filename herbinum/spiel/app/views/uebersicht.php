<?php
/** @var array $z @var array $ort */
$titel = 'Übersicht';
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$r = $z['rate'];
$bauland = $b['bauplaetze'] * BAULAND_JE_BAUPLATZ;
$belegt = bauland_belegt($z['stufen'], $z['queue']);
$imBau = $z['queue'] ? $z['queue'][0]['typ'] : '';
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow"><?= h($ort['name']) ?> · Bereich <?= (int)$b['nr'] ?> · <?= h($b['typ']) ?> · <?= h(number_format((float)$b['ha'], 1, ',', '.')) ?> ha</div>
    <h1><?= h($b['name']) ?></h1>
  </div>
</div>

<div class="raster-uebersicht">
  <section class="buehne" aria-label="Ansicht deines Bereichs">
    <canvas id="iso" data-ort="<?= h($b['ort_id']) ?>" data-nr="<?= (int)$b['nr'] ?>"
            data-stufen="<?= h(json_encode($z['stufen'])) ?>" data-baut="<?= h($imBau) ?>"
            data-einheiten="<?= h(json_encode($z['einheiten'])) ?>"
            role="img" aria-label="Isometrische Ansicht von <?= h($b['name']) ?> mit deinen Gebäuden"></canvas>
    <div class="buehne-info">Norden oben rechts · echte Straßen aus OpenStreetMap</div>
    <div class="himmel" aria-hidden="true"></div>
  </section>

  <div class="spalte">
    <?php require __DIR__ . '/warteschlange.php'; ?>

    <section class="karte-box">
      <h2>Bauland</h2>
      <div class="balken gross"><i style="width:<?= min(100, round($belegt / $bauland * 100)) ?>%"></i></div>
      <p class="leise"><?= $belegt ?> von <?= $bauland ?> Parzellen belegt. Jede Gebäudestufe belegt eine Parzelle.</p>
    </section>

    <?php if ($z['einheiten'] || $z['werkstatt'] || $z['bunker'] || $z['auslagerung']): ?>
      <section class="karte-box">
        <h2>Einheiten</h2>
        <?php if ($z['einheiten']): ?>
          <table class="tabelle">
            <?php foreach ($z['einheiten'] as $typ => $n): ?><tr><td><?= h(EINHEITEN[$typ]['name']) ?></td><td class="n"><?= zahl($n) ?></td></tr><?php endforeach ?>
          </table>
        <?php endif ?>
        <?php if ($z['bunker']): ?><p class="leise">Im <a href="?s=bunker">Bunker</a>: <?= h(implode(', ', array_map(fn($t, $n) => "{$n} × " . EINHEITEN[$t]['name'], array_keys($z['bunker']), $z['bunker']))) ?></p><?php endif ?>
        <?php if ($z['werkstatt']): ?><p class="leise">Im Fuhrpark: <?= h(implode(', ', array_map(fn($w) => ($w['anzahl'] - $w['fertig']) . ' × ' . EINHEITEN[$w['typ']]['name'], $z['werkstatt']))) ?></p><?php endif ?>
        <?php if (bewegliche($z['einheiten'])): ?><a class="knopf leicht" href="?s=zuege">Zug losschicken</a><?php endif ?>
      </section>
    <?php endif ?>

    <section class="karte-box">
      <h2>Werte des Bereichs</h2>
      <div class="werte">
        <?php foreach ([['Lebensqualität', $r['lebensqualitaet'], 'Einwohnerzahl'], ['Wehrkraft', (int)$b['wehr'], 'Verteidigung bei Überfällen'], ['Einkommen', (int)$b['einkommen'], 'Euro pro Einwohner']] as [$n, $v, $w]): ?>
          <div class="wert"><span><?= $n ?></span><div class="balken"><i style="width:<?= max(3, $v) ?>%"></i></div><b><?= $v ?></b><small><?= $w ?></small></div>
        <?php endforeach ?>
      </div>
      <div class="boni">
        <?php foreach (['holz' => 'Holz', 'ziegel' => 'Baustoffe', 'korn' => 'Nahrung', 'strom' => 'Strom'] as $k => $n): ?>
          <div><span><?= $n ?></span><b>×<?= number_format((float)$b["mod_$k"], 2, ',', '') ?></b></div>
        <?php endforeach ?>
      </div>
      <p class="leise">Die Werte ergeben sich aus der echten Lage: Wald, Wasser, Gewerbe, Straßen und Landmarken im Bereich.</p>
    </section>

    <section class="karte-box">
      <h2>Gebäude</h2>
      <table class="tabelle">
        <?php foreach (GEBAEUDE as $typ => $g):
            if (empty($z['stufen'][$typ])) continue; ?>
          <tr><td><?= h($g['name']) ?></td><td class="n">Stufe <?= (int)$z['stufen'][$typ] ?></td></tr>
        <?php endforeach ?>
      </table>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
