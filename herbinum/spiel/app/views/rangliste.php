<?php
/** @var array $spieler */
$titel = 'Rangliste';
require __DIR__ . '/kopf.php';
$ansicht = ($_GET['ansicht'] ?? '') === 'gen' ? 'gen' : 'spieler';
$nach = in_array($_GET['nach'] ?? '', ['gebaeude', 'forschung', 'einheiten'], true) ? $_GET['nach'] : 'gesamt';
$spalten = ['gesamt' => 'Gesamt', 'gebaeude' => 'Gebäude', 'forschung' => 'Forschung', 'einheiten' => 'Einheiten'];
?>
<div class="kopfzeile">
  <div><div class="eyebrow">1 Punkt je 1.000 verbaute Rohstoffe</div><h1>Rangliste</h1></div>
</div>

<nav class="reiter" aria-label="Rangliste">
  <a href="?s=rangliste"<?= $ansicht === 'spieler' ? ' aria-current="page"' : '' ?>>Spieler</a>
  <a href="?s=rangliste&amp;ansicht=gen"<?= $ansicht === 'gen' ? ' aria-current="page"' : '' ?>>Allianzen</a>
</nav>

<?php if ($ansicht === 'spieler'):
    $liste = rangliste($nach);
    $max = max(1, ...array_map(fn($s) => $s[$nach], $liste ?: [['gesamt' => 1, $nach => 1]])); ?>
  <nav class="reiter" aria-label="Sortieren nach">
    <?php foreach ($spalten as $k => $v): ?>
      <a href="?s=rangliste&amp;nach=<?= $k ?>"<?= $nach === $k ? ' aria-current="page"' : '' ?>><?= $v ?></a>
    <?php endforeach ?>
  </nav>
  <section class="karte-box">
    <div class="tabelle-rahmen">
      <table class="tabelle rangtabelle">
        <tr><th>Platz</th><th>Spieler</th><th class="n">Bereiche</th>
          <?php foreach ($spalten as $k => $v): ?><th class="n"><?= $v ?></th><?php endforeach ?></tr>
        <?php foreach ($liste as $s): ?>
          <tr class="<?= (int)$s['id'] === (int)$spieler['id'] ? 'ich' : '' ?>">
            <td class="platz"><?= (int)$s['platz'] ?>.</td>
            <td>
              <span class="spielername"><i style="background:<?= h($s['farbe']) ?>"></i><?= h($s['name']) ?></span>
              <?php if ($s['kuerzel']): ?><a class="kuerzel" href="?s=allianz&amp;id=<?= (int)$s['gid'] ?>">[<?= h($s['kuerzel']) ?>]</a><?php endif ?>
              <?= (int)$s['gesperrt'] ? '<span class="chip gefahr">gesperrt</span>' : '' ?>
            </td>
            <td class="n"><?= (int)$s['bereiche'] ?></td>
            <?php foreach ($spalten as $k => $v): ?>
              <td class="n"><?= zahl($s[$k]) ?><?php if ($k === $nach): ?><span class="balken-mini" style="width:<?= round($s[$k] / $max * 60) ?>px" aria-hidden="true"></span><?php endif ?></td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </table>
    </div>
  </section>
<?php else:
    $gens = gen_rangliste(); ?>
  <section class="karte-box">
    <?php if (!$gens): ?>
      <p class="leise">Noch gibt es keine Allianzen. <a href="?s=allianz">Gründe die erste.</a></p>
    <?php else: ?>
      <div class="tabelle-rahmen">
        <table class="tabelle rangtabelle">
          <tr><th>Platz</th><th>Allianz</th><th class="n">Mitglieder</th><th class="n">Punkte</th><th class="n">Schnitt</th></tr>
          <?php foreach ($gens as $g): ?>
            <tr class="<?= (int)$g['id'] === (int)($gen['id'] ?? 0) ? 'ich' : '' ?>">
              <td class="platz"><?= (int)$g['platz'] ?>.</td>
              <td><?= wappen_html($g, "wappen klein") ?> <a href="?s=allianz&amp;id=<?= (int)$g["id"] ?>"><?= h($g['name']) ?></a> <span class="kuerzel">[<?= h($g['kuerzel']) ?>]</span></td>
              <td class="n"><?= (int)$g['mitglieder'] ?></td>
              <td class="n"><?= zahl($g['punkte']) ?></td>
              <td class="n"><?= zahl($g['schnitt']) ?></td>
            </tr>
          <?php endforeach ?>
        </table>
      </div>
    <?php endif ?>
  </section>
<?php endif ?>
<p class="leise">Gebäude und Forschung zählen mit allen bezahlten Stufen, Einheiten und Verteidigung mit ihren Baukosten – auch wenn sie gerade unterwegs sind. Verlorene Einheiten zählen nicht mehr.</p>
<?php require __DIR__ . '/fuss.php'; ?>
