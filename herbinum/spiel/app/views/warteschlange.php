<?php /** @var array $z @var string $seite */ ?>
<section class="karte-box">
  <h2>Bauwarteschlange</h2>
  <?php if (!$z['queue']): ?>
    <p class="leise">Gerade wird nichts gebaut. <?= $seite !== 'gebaeude' ? '<a href="?s=gebaeude">Zu den Gebäuden</a>' : '' ?></p>
  <?php else: ?>
    <ol class="queue">
      <?php foreach ($z['queue'] as $i => $q):
          $letzter = $i === count($z['queue']) - 1; ?>
        <li>
          <div>
            <b><?= h(GEBAEUDE[$q['typ']]['name']) ?></b> auf Stufe <?= (int)$q['ziel_stufe'] ?>
            <div class="leise">
              <?php if ($q['start_ts'] <= time()): ?>
                fertig in <span class="countdown" data-ende="<?= (int)$q['ende_ts'] ?>"><?= dauer($q['ende_ts'] - time()) ?></span>
              <?php else: ?>
                wartet · fertig um <?= date('H:i', (int)$q['ende_ts']) ?><?= date('Ymd', (int)$q['ende_ts']) !== date('Ymd') ? ' am ' . date('d.m.', (int)$q['ende_ts']) : '' ?> Uhr
              <?php endif ?>
            </div>
            <?php if ($q['start_ts'] <= time()):
                $p = max(0, min(100, (time() - $q['start_ts']) / max(1, $q['ende_ts'] - $q['start_ts']) * 100)); ?>
              <div class="fortschritt" data-start="<?= (int)$q['start_ts'] ?>" data-ende="<?= (int)$q['ende_ts'] ?>"><i style="width:<?= round($p, 1) ?>%"></i></div>
            <?php endif ?>
          </div>
          <?php if ($letzter): ?>
            <form method="post">
              <?= csrf_feld() ?>
              <input type="hidden" name="a" value="abbrechen">
              <input type="hidden" name="bereich" value="<?= h($z['bereich']['id']) ?>">
              <input type="hidden" name="auftrag" value="<?= (int)$q['id'] ?>">
              <input type="hidden" name="zurueck" value="<?= h($seite) ?>">
              <button class="knopf leicht" type="submit">Abbrechen</button>
            </form>
          <?php endif ?>
        </li>
      <?php endforeach ?>
    </ol>
  <?php endif ?>
</section>
