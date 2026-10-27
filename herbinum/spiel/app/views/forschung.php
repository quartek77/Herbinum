<?php
/** @var array $z @var array $spieler */
$titel = 'Forschung';
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$fo = $z['fo'];
$auftrag = forschungsauftrag((int)$spieler['id']);
$schule = (int)($z['stufen']['dorfschule'] ?? 0);
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow"><?= h($ort['name']) ?> · <?= h($b['name']) ?> · Dorfschule <?= $schule ? "Stufe {$schule}" : 'nicht gebaut' ?></div>
    <h1>Forschung</h1>
  </div>
</div>

<div class="raster-gebaeude">
  <div class="gebaeudeliste">
    <?php foreach (FORSCHUNG as $typ => $f):
        $jetzt = (int)($fo[$typ] ?? 0);
        $stufe = $jetzt + 1;
        $kosten = forschungskosten($typ, $stufe);
        $fehlt = fehlende_voraussetzungen($f['braucht'], $z['stufen'], $fo);
        $mangel = array_filter(RESSOURCEN, fn($r) => $b[$r] < $kosten[$r], ARRAY_FILTER_USE_KEY);
        $grund = $fehlt ? 'Braucht ' . implode(', ', $fehlt) : ($auftrag ? 'Es wird schon geforscht' : ($mangel ? 'Zu wenig ' . implode(', ', $mangel) : ''));
        $laeuft = $auftrag && $auftrag['typ'] === $typ;
        ?>
      <article class="gebaeude<?= $fehlt ? ' gesperrt' : '' ?>">
        <header>
          <h2><?= h($f['name']) ?></h2>
          <span class="stufe"><?= $jetzt ? "Stufe {$jetzt}" : 'nicht erforscht' ?><?= $laeuft ? " · {$stufe} läuft" : '' ?></span>
        </header>
        <p><?= h($f['kurz']) ?></p>
        <?php if ($typ === 'verwaltung'): ?>
          <p class="wirkung">Jetzt <?= max_bereiche($fo) ?> Bereiche erlaubt, mit Stufe <?= $stufe ?>: <?= max_bereiche([$typ => $stufe] + $fo) ?></p>
        <?php elseif ($f['bonus'] > 0): ?>
          <p class="wirkung">Jetzt +<?= round($f['bonus'] * $jetzt * 100) ?> %, mit Stufe <?= $stufe ?>: +<?= round($f['bonus'] * $stufe * 100) ?> %</p>
        <?php endif ?>
        <dl class="kosten">
          <?php foreach ($kosten as $r => $k): if (!$k) continue; ?>
            <div class="<?= $b[$r] < $k ? 'fehlt' : '' ?>"><dt><?= RESSOURCEN[$r] ?></dt><dd><?= zahl($k) ?></dd></div>
          <?php endforeach ?>
          <div><dt>Dauer</dt><dd><?= $schule ? dauer(forschungszeit($kosten, $schule, tempo())) : '–' ?></dd></div>
        </dl>
        <form method="post">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="forschen">
          <input type="hidden" name="typ" value="<?= $typ ?>">
          <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">
          <button class="knopf" type="submit"<?= $grund ? ' disabled' : '' ?>>Stufe <?= $stufe ?> erforschen</button>
          <?php if ($grund): ?><span class="grund"><?= h($grund) ?></span><?php endif ?>
        </form>
      </article>
    <?php endforeach ?>
  </div>
  <div class="spalte">
    <section class="karte-box">
      <h2>Läuft gerade</h2>
      <?php if (!$auftrag): ?>
        <p class="leise">Keine Forschung aktiv.</p>
      <?php else: ?>
        <div>
          <b><?= h(FORSCHUNG[$auftrag['typ']]['name']) ?></b> auf Stufe <?= (int)$auftrag['ziel_stufe'] ?>
          <div class="leise">in <?= h(bereich_name(db(), $auftrag['bereich_id'])) ?> · fertig in <span class="countdown" data-ende="<?= (int)$auftrag['ende_ts'] ?>"><?= dauer($auftrag['ende_ts'] - time()) ?></span></div>
          <div class="fortschritt" data-start="<?= (int)$auftrag['start_ts'] ?>" data-ende="<?= (int)$auftrag['ende_ts'] ?>"><i></i></div>
        </div>
        <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="forschung_abbrechen"><button class="knopf leicht" type="submit">Abbrechen</button></form>
      <?php endif ?>
    </section>
    <section class="karte-box">
      <h2>So funktioniert's</h2>
      <p class="leise">Forschung gilt für alle deine Bereiche. Geforscht wird in der Dorfschule des Bereichs, in dem du gerade bist – je höher die Schule, desto schneller. Immer nur eine Forschung gleichzeitig.</p>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
