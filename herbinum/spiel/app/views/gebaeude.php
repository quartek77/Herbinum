<?php
/** @var array $z */
$titel = 'Gebäude';
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$queueVoll = count($z['queue']) >= (int)(config()['spiel']['max_warteschlange'] ?? 3);
$landVoll = bauland_belegt($z['stufen'], $z['queue']) >= $b['bauplaetze'] * BAULAND_JE_BAUPLATZ;

// Stufen nach Abschluss der Warteschlange – Grundlage für die Wirkungsvorschau
$geplant = $z['stufen'];
foreach ($z['queue'] as $q) {
    $geplant[$q['typ']] = max($geplant[$q['typ']] ?? 0, (int)$q['ziel_stufe']);
}
// Was ändert die nächste Stufe? Vergleich der Produktion mit und ohne diese Stufe.
$wirkung = function (string $typ, int $stufe) use ($geplant, $b, $z): array {
    $vorher = produktion($b, $geplant, tempo(), $z['fo']);
    $nachher = produktion($b, [$typ => $stufe] + $geplant, tempo(), $z['fo']);
    $out = [];
    foreach (['holz' => 'Holz/h', 'ziegel' => 'Baustoffe/h', 'korn_brutto' => 'Nahrung/h', 'taler' => 'Euro/h', 'strom_prod' => 'Strom', 'einwohner' => 'Einwohner', 'lager' => 'Lager', 'lebensqualitaet' => 'Lebensqualität'] as $k => $n) {
        $d = $nachher[$k] - $vorher[$k];
        if (abs($d) >= 1) {
            $out[] = ($d > 0 ? '+' : '−') . zahl(abs($d)) . ' ' . $n;
        }
    }
    $strom = $nachher['strom_bedarf'] - $vorher['strom_bedarf'];
    if ($strom >= 1) {
        $out[] = 'braucht ' . zahl($strom) . ' Strom mehr';
    }
    if ($typ === 'hofstelle') {
        $out[] = 'Bauzeiten ×' . number_format((1 + $stufe - 1) / (1 + $stufe), 2, ',', '');
    }
    return $out;
};
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow"><?= h($ort['name']) ?> · <?= h($b['name']) ?></div>
    <h1>Gebäude</h1>
  </div>
</div>

<div class="raster-gebaeude">
  <div class="gebaeudeliste">
    <?php foreach (GEBAEUDE as $typ => $g):
        $jetzt = (int)($z['stufen'][$typ] ?? 0);
        $stufe = naechste_stufe($typ, $z['stufen'], $z['queue']);
        $kosten = baukosten($typ, $stufe);
        $hof = (int)($z['stufen']['hofstelle'] ?? 0);
        $fehlt = fehlende_voraussetzungen($g['braucht'], $z['stufen'], $z['fo']);
        $mangel = array_filter(RESSOURCEN, fn($r) => $b[$r] < $kosten[$r], ARRAY_FILTER_USE_KEY);
        $grund = $fehlt ? 'Braucht ' . implode(', ', $fehlt) : ($queueVoll ? 'Warteschlange voll' : ($landVoll ? 'Kein Bauland frei' : ($mangel ? 'Zu wenig ' . implode(', ', $mangel) : '')));
        if (isset($g['max']) && $stufe > $g['max']) {
            $grund = 'Höchste Stufe erreicht';
        } elseif (!empty($g['einmalig']) && ($anderswo = gebaeude_anderswo(db(), (int)$spieler['id'], $typ, $b['id']))) {
            $grund = 'Gibt es nur einmal – steht schon in ' . $anderswo;
        }
        ?>
      <article class="gebaeude<?= $fehlt ? ' gesperrt' : '' ?>" id="g-<?= $typ ?>">
        <header>
          <h2><?= h($g['name']) ?></h2>
          <span class="stufe"><?= $jetzt ? "Stufe {$jetzt}" : 'nicht gebaut' ?><?= $stufe - 1 > $jetzt ? ' · ' . ($stufe - 1) . ' im Bau' : '' ?></span>
        </header>
        <p><?= h($g['kurz']) ?></p>
        <?php if ($g['braucht']) echo bedarf_html('gebaeude', $typ, $z, (int)$spieler['id'], $kosten); ?>
        <?php if ($w = $wirkung($typ, $stufe)): ?>
          <p class="wirkung"><?= h(implode(' · ', $w)) ?></p>
        <?php endif ?>
        <dl class="kosten">
          <?php foreach ($kosten as $r => $k): if (!$k) continue; ?>
            <div class="<?= $b[$r] < $k ? 'fehlt' : '' ?>"><dt><?= RESSOURCEN[$r] ?></dt><dd><?= zahl($k) ?></dd></div>
          <?php endforeach ?>
          <div><dt>Bauzeit</dt><dd><?= dauer(bauzeit($kosten, $hof, tempo())) ?></dd></div>
        </dl>
        <form method="post">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="bauen">
          <input type="hidden" name="typ" value="<?= $typ ?>">
          <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
          <button class="knopf" type="submit"<?= $grund ? ' disabled' : '' ?>><?= $jetzt || $stufe > 1 ? "Ausbauen auf Stufe {$stufe}" : 'Bauen' ?></button>
          <?php if ($grund): ?><span class="grund"><?= h($grund) ?></span><?php endif ?>
        </form>
      </article>
    <?php endforeach ?>
  </div>
  <div class="spalte">
    <?php require __DIR__ . '/warteschlange.php'; ?>
    <section class="karte-box">
      <h2>So funktioniert's</h2>
      <p class="leise">Die Kosten werden beim Auftrag sofort abgezogen. Bis zu <?= (int)(config()['spiel']['max_warteschlange'] ?? 3) ?> Aufträge laufen nacheinander ab, auch wenn du nicht eingeloggt bist. Sägewerk, Baustoffwerk und Gewächshaus brauchen Strom – ohne genug Windparks laufen sie nur gedrosselt.</p>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
