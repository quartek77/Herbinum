<?php
/** @var array $z @var array $spieler */
$titel = 'Bunker';
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$stufe = (int)($z['stufen']['bunker'] ?? 0);
$plaetze = bunker_plaetze($stufe, $z['fo']);
$belegt = bunker_belegt($z['bunker']);
$draussen = array_intersect_key($z['einheiten'], BUNKER_PLATZ);
$dauer = bunker_auslagern_s(max(1, $stufe), $z['fo'], tempo());
$eingabe = function (string $typ, int $max) {
    return '<label><span>' . h(EINHEITEN[$typ]['name']) . ' <small>(' . $max . ' · ' . BUNKER_PLATZ[$typ] . ' Platz' . (BUNKER_PLATZ[$typ] > 1 ? 'e' : '') . ')</small></span>'
        . '<input type="number" name="e[' . $typ . ']" min="0" max="' . $max . '" value="0" inputmode="numeric" data-platz="' . BUNKER_PLATZ[$typ] . '"></label>';
};
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow"><?= h($ort['name']) ?> · <?= h($b['name']) ?> · Bunker <?= $stufe ? "Stufe {$stufe}" : 'nicht gebaut' ?></div>
    <h1>Bunker</h1>
  </div>
</div>

<?php if ($stufe < 1): ?>
  <section class="karte-box">
    <p>In diesem Bereich gibt es noch keinen Bunker. Bau ihn unter <a href="?s=gebaeude#g-bunker">Gebäude</a> (braucht Hofstelle Stufe 3).</p>
    <p class="leise">Im Bunker sind Einheiten und Fahrzeuge vor Raubzügen und neugierigen Kurieren sicher. Dafür stehen sie nach dem Auslagern erst mit Verzögerung wieder bereit.</p>
  </section>
<?php else: ?>
<div class="raster-gebaeude">
  <div class="spalte">
    <section class="karte-box">
      <h2>Belegung</h2>
      <div class="balken gross"><i style="width:<?= $plaetze ? min(100, round($belegt / $plaetze * 100)) : 0 ?>%"></i></div>
      <p class="leise"><?= $belegt ?> von <?= $plaetze ?> Plätzen belegt. Auslagern dauert <?= dauer($dauer) ?> – so lange bleiben die Einheiten geschützt, können aber nicht kämpfen oder losfahren.</p>
    </section>

    <section class="karte-box">
      <h2>Einlagern</h2>
      <?php if (!$draussen): ?>
        <p class="leise">Hier stehen keine Einheiten, die in den Bunker passen. Wallhecken und Hochsitze bleiben draußen.</p>
      <?php else: ?>
        <form method="post" class="bunkerform" data-frei="<?= max(0, $plaetze - $belegt) ?>">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="bunker_einlagern">
          <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
          <div class="eingaben"><?php foreach ($draussen as $typ => $n) echo $eingabe($typ, (int)$n); ?></div>
          <p class="vorschau bunker-vorschau" aria-live="polite">Noch <?= max(0, $plaetze - $belegt) ?> Plätze frei.</p>
          <button class="knopf" type="submit">Einlagern</button>
          <p class="leise">Einlagern geht sofort.</p>
        </form>
      <?php endif ?>
    </section>

    <section class="karte-box">
      <h2>Auslagern</h2>
      <?php if (!$z['bunker']): ?>
        <p class="leise">Der Bunker ist leer.</p>
      <?php else: ?>
        <form method="post" class="bunkerform">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="bunker_auslagern">
          <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
          <div class="eingaben"><?php foreach ($z['bunker'] as $typ => $n) echo $eingabe($typ, (int)$n); ?></div>
          <button class="knopf" type="submit">Auslagern (<?= dauer($dauer) ?>)</button>
        </form>
      <?php endif ?>
    </section>
  </div>

  <div class="spalte">
    <section class="karte-box">
      <h2>Im Bunker</h2>
      <?php if (!$z['bunker']): ?>
        <p class="leise">Nichts eingelagert.</p>
      <?php else: ?>
        <table class="tabelle"><?php foreach ($z['bunker'] as $typ => $n): ?><tr><td><?= h(EINHEITEN[$typ]['name']) ?></td><td class="n"><?= zahl($n) ?></td></tr><?php endforeach ?></table>
      <?php endif ?>
    </section>
    <?php if ($z['auslagerung']): ?>
      <section class="karte-box">
        <h2>Wird ausgelagert</h2>
        <ol class="queue">
          <?php foreach ($z['auslagerung'] as $a): ?>
            <li><div><?= h(implode(', ', array_map(fn($t, $n) => "{$n} × " . EINHEITEN[$t]['name'], array_keys(json_decode($a['einheiten'], true)), json_decode($a['einheiten'], true)))) ?>
              <div class="leise">bereit in <span class="countdown" data-ende="<?= (int)$a['fertig_ts'] ?>"><?= dauer((int)$a['fertig_ts'] - time()) ?></span></div></div></li>
          <?php endforeach ?>
        </ol>
      </section>
    <?php endif ?>
    <section class="karte-box">
      <h2>So wächst der Bunker</h2>
      <ul class="liste-leise">
        <li><b>Bunker ausbauen:</b> +<?= BUNKER_PLAETZE_JE_STUFE ?> Plätze je Stufe, Auslagern 10 % schneller.</li>
        <li><b>Bunkerbau erforschen:</b> +25 % Plätze und 10 % schnelleres Auslagern je Stufe.</li>
        <li>Platzbedarf: Kurier und Schütze 1, Reiter 2, Trecker 4, Siedlertreck 6.</li>
      </ul>
    </section>
  </div>
</div>
<?php endif ?>
<?php require __DIR__ . '/fuss.php'; ?>
