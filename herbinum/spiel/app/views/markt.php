<?php
/** @var array $z @var array $spieler @var array|null $vertretung @var array|null $gen */
$titel = 'Markt';
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$sid = (int)$spieler['id'];
$k = markt_konditionen(db(), $z, $sid);
$rest = max(0, $k['limit'] - $k['genutzt']);
$handelskunde = (int)($z['fo']['handelskunde'] ?? 0);
$spielerhandel = !$vertretung && $handelskunde >= MARKT_SPIELER_AB;
$angebote = $vertretung ? [] : markt_angebote($sid);
$eigene = array_filter($angebote, fn($a) => (int)$a['spieler_id'] === $sid);
$fremde = array_filter($angebote, fn($a) => (int)$a['spieler_id'] !== $sid);
$icon = fn(string $n) => '<svg class="ic" aria-hidden="true"><use href="#i-' . $n . '"/></svg>';
$res = fn(string $r, int|float $n) => '<span class="menge">' . $icon($r) . zahl($n) . '</span>';
$kurse = [];
foreach (MARKT_WERT as $von => $_) {
    foreach (MARKT_WERT as $nach => $_2) {
        if ($von !== $nach) {
            $kurse[$von][$nach] = MARKT_WERT[$von] / MARKT_WERT[$nach] * (1 - $k['verlust']);
        }
    }
}
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow"><?= h($ort['name']) ?> · <?= h($b['name']) ?> · Marktplatz <?= $k['stufe'] ? "Stufe {$k['stufe']}" : 'nicht gebaut' ?></div>
    <h1>Markt</h1>
  </div>
</div>

<div class="raster-gebaeude">
  <div class="spalte">
    <section class="karte-box">
      <h2><?= $icon('waage') ?> Beim Händler tauschen</h2>
      <?php if ($k['stufe'] < 1): ?>
        <p>Der Händler kommt erst, wenn in diesem Bereich ein <a href="?s=gebaeude#g-markt">Marktplatz</a> steht (braucht Wohnhäuser Stufe 3).</p>
      <?php else: ?>
        <p class="leise">Der Händler nimmt deine Rohstoffe und gibt dir andere – mit <b><?= round($k['verlust'] * 100) ?> % Verlust</b><?= $k['krise'] > 0 ? ' (davon ' . round($k['krise'] * 100) . ' % Krisenaufschlag)' : '' ?>.
          Heute kannst du noch Waren im Grundwert von <b><?= zahl($rest) ?></b> von <?= zahl($k['limit']) ?> abgeben (gilt für 24 Stunden).</p>
        <div class="balken"><i style="width:<?= $k['limit'] ? min(100, round($k['genutzt'] / $k['limit'] * 100)) : 0 ?>%"></i></div>
        <form method="post" class="handelsform" id="haendler" data-kurse="<?= h(json_encode($kurse)) ?>" data-wert="<?= h(json_encode(MARKT_WERT)) ?>" data-rest="<?= $rest ?>">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="markt_tauschen">
          <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
          <label><span>Ich gebe</span>
            <span class="zeilenform"><input name="menge" type="number" min="10" step="10" value="1000" inputmode="numeric" aria-label="Menge">
              <select name="von" aria-label="Rohstoff, den du abgibst"><?php foreach (RESSOURCEN as $r => $n): ?><option value="<?= $r ?>"<?= $r === 'holz' ? ' selected' : '' ?>><?= $n ?> (<?= zahl($b[$r]) ?>)</option><?php endforeach ?></select></span></label>
          <label><span>und bekomme</span>
            <select name="nach" aria-label="Rohstoff, den du bekommst"><?php foreach (RESSOURCEN as $r => $n): ?><option value="<?= $r ?>"<?= $r === 'ziegel' ? ' selected' : '' ?>><?= $n ?></option><?php endforeach ?></select></label>
          <div class="vorschau" id="haendler-vorschau" aria-live="polite">–</div>
          <button class="knopf" type="submit"<?= $rest < 10 ? ' disabled' : '' ?>>Tauschen</button>
        </form>
        <details>
          <summary>Kurstabelle</summary>
          <table class="tabelle">
            <tr><th>Für 100 …</th><?php foreach (RESSOURCEN as $n): ?><th class="n"><?= $n ?></th><?php endforeach ?></tr>
            <?php foreach (RESSOURCEN as $von => $n): ?>
              <tr><td><?= $n ?></td><?php foreach (RESSOURCEN as $nach => $_): ?><td class="n"><?= $von === $nach ? '–' : zahl(100 * $kurse[$von][$nach]) ?></td><?php endforeach ?></tr>
            <?php endforeach ?>
          </table>
        </details>
      <?php endif ?>
    </section>

    <section class="karte-box">
      <h2><?= $icon('leute') ?> Schwarzes Brett – Handel mit Spielern</h2>
      <?php if ($vertretung): ?>
        <p class="leise">In Vertretung gibt es nur den Händler.</p>
      <?php elseif (!$spielerhandel): ?>
        <p>Handel mit anderen Spielern gibt es ab <a href="?s=forschung">Handelskunde</a> Stufe <?= MARKT_SPIELER_AB ?> (du hast Stufe <?= $handelskunde ?>).
          Dann kostet ein Handel <?= round(MARKT_GEBUEHR * 100) ?> % Marktgebühr – unter Allianzmitgliedern nur <?= round(MARKT_GEBUEHR_ALLIANZ * 100) ?> %.</p>
      <?php endif ?>
      <?php if (!$vertretung): ?>
        <?php if (!$fremde): ?>
          <p class="leise">Gerade hängt kein Angebot anderer Spieler aus.</p>
        <?php else: ?>
          <div class="tabelle-rahmen">
          <table class="tabelle angebote">
            <tr><th>Von</th><th>Bietet</th><th>Will</th><th>Gebühr</th><th></th></tr>
            <?php foreach ($fremde as $a):
                $geb = (int)$a['verbuendet'] ? MARKT_GEBUEHR_ALLIANZ : MARKT_GEBUEHR;
                $kann = $spielerhandel && $k['stufe'] >= 1 && (float)$b[$a['will_res']] >= (int)$a['will_menge']; ?>
              <tr>
                <td><span class="spielername"><i style="background:<?= h($a['farbe']) ?>"></i><?= h($a['name']) ?></span><?= $a['kuerzel'] ? ' <span class="kuerzel">[' . h($a['kuerzel']) . ']</span>' : '' ?>
                  <div class="leise"><?= h($a['ort']) ?><?= (int)$a['nur_allianz'] ? ' · nur Allianz' : '' ?> · noch <?= dauer((int)$a['ablauf_ts'] - time()) ?></div></td>
                <td><?= $res($a['gibt_res'], (int)$a['gibt_menge']) ?></td>
                <td><?= $res($a['will_res'], (int)$a['will_menge']) ?></td>
                <td class="n"><?= round($geb * 100) ?> %</td>
                <td><form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="markt_annehmen"><input type="hidden" name="bereich" value="<?= h($b['id']) ?>"><input type="hidden" name="angebot" value="<?= (int)$a['id'] ?>">
                  <button class="knopf" type="submit"<?= $kann ? '' : ' disabled' ?>>Annehmen</button></form></td>
              </tr>
            <?php endforeach ?>
          </table>
          </div>
          <p class="leise">Du bezahlst aus diesem Bereich und bekommst die Ware sofort hierher – abzüglich Gebühr.</p>
        <?php endif ?>
      <?php endif ?>
    </section>
  </div>

  <div class="spalte">
    <?php if ($spielerhandel): ?>
      <section class="karte-box">
        <h2>Angebot aufhängen</h2>
        <?php if ($k['stufe'] < 1): ?>
          <p class="leise">Angebote gehen nur aus einem Bereich mit Marktplatz.</p>
        <?php elseif (count($eigene) >= max_angebote($z['fo'])): ?>
          <p class="leise">Du hast schon <?= count($eigene) ?> Angebote offen (höchstens <?= max_angebote($z['fo']) ?>).</p>
        <?php else: ?>
          <form method="post" class="formular">
            <?= csrf_feld() ?>
            <input type="hidden" name="a" value="markt_angebot">
            <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
            <label for="ga">Ich biete</label>
            <span class="zeilenform"><input id="ga" name="gibt_menge" type="number" min="100" step="50" value="1000" inputmode="numeric">
              <select name="gibt" aria-label="Rohstoff, den du bietest"><?php foreach (RESSOURCEN as $r => $n): ?><option value="<?= $r ?>"><?= $n ?></option><?php endforeach ?></select></span>
            <label for="wa">und will dafür</label>
            <span class="zeilenform"><input id="wa" name="will_menge" type="number" min="1" step="10" value="700" inputmode="numeric">
              <select name="will" aria-label="Rohstoff, den du willst"><?php foreach (RESSOURCEN as $r => $n): ?><option value="<?= $r ?>"<?= $r === 'ziegel' ? ' selected' : '' ?>><?= $n ?></option><?php endforeach ?></select></span>
            <?php if ($gen): ?><label class="haken"><input type="checkbox" name="nur_allianz" value="1"> nur für meine Allianz [<?= h($gen['kuerzel']) ?>]</label><?php endif ?>
            <button class="knopf" type="submit">Aufhängen</button>
            <p class="leise">Die Ware geht gleich an den Markt und kommt zurück, wenn du das Angebot zurückziehst oder es nach <?= MARKT_ANGEBOT_TAGE ?> Tagen verfällt.
              Der Gegenwert muss zwischen der Hälfte und dem Doppelten des Grundwerts liegen (Holz 1 · Ziegel 1,5 · Korn 1,2 · Taler 4).</p>
          </form>
        <?php endif ?>
      </section>
    <?php endif ?>
    <?php if ($eigene): ?>
      <section class="karte-box">
        <h2>Deine Angebote</h2>
        <ol class="queue">
          <?php foreach ($eigene as $a): ?>
            <li><div><?= $res($a['gibt_res'], (int)$a['gibt_menge']) ?> gegen <?= $res($a['will_res'], (int)$a['will_menge']) ?>
              <div class="leise"><?= (int)$a['nur_allianz'] ? 'nur Allianz · ' : '' ?>verfällt in <span class="countdown" data-ende="<?= (int)$a['ablauf_ts'] ?>"><?= dauer((int)$a['ablauf_ts'] - time()) ?></span></div></div>
              <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="markt_zurueckziehen"><input type="hidden" name="angebot" value="<?= (int)$a['id'] ?>"><button class="knopf leicht" type="submit">Zurückziehen</button></form></li>
          <?php endforeach ?>
        </ol>
      </section>
    <?php endif ?>
    <section class="karte-box">
      <h2>So wird der Markt besser</h2>
      <ul class="liste-leise">
        <li><b>Marktplatz ausbauen:</b> je Stufe <?= round(MARKT_VERLUST_JE_STUFE * 100) ?> Punkte weniger Verlust und <?= zahl(MARKT_MENGE_JE_STUFE * tempo()) ?> mehr Tageslimit.</li>
        <li><b>Handelskunde erforschen:</b> je Stufe <?= round(MARKT_VERLUST_JE_FORSCHUNG * 100) ?> Punkte weniger Verlust, +25 % Tageslimit und ein Angebot mehr; ab Stufe <?= MARKT_SPIELER_AB ?> Handel mit Spielern.</li>
        <li>Besser als <?= round(MARKT_VERLUST_MIN * 100) ?> % Verlust wird der Händler nie. Bei einer Wirtschaftskrise verlangt er mehr.</li>
      </ul>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
