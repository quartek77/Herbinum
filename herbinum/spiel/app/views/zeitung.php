<?php
/** @var array $echt */
$titel = 'Herbinumer Anzeiger';
$heute = spieltag(time());
$tag = (int)($_GET['tag'] ?? $heute);
$ausgabe = $tag === $heute ? zeitung_heute() : zeitung($tag);
db()->prepare('UPDATE spieler SET zeitung_tag = ? WHERE id = ?')->execute([$heute, (int)$echt['id']]);
$zeitung_neu = false;
require __DIR__ . '/kopf.php';
$archiv = db()->query('SELECT tag FROM zeitung ORDER BY tag DESC LIMIT 8')->fetchAll(PDO::FETCH_COLUMN);
$i = $ausgabe['inhalt'] ?? [];
$kiWartet = $ausgabe && $tag === $heute && $ausgabe['quelle'] !== 'ki' && ki_konfiguriert() && (int)einstellung('zeitung_ki_lauf', '0') < time() - 1800;
$risikoKlasse = fn(int $p) => $p >= 70 ? 'hoch' : ($p >= 40 ? 'mittel' : 'niedrig');
?>
<?php if (!$ausgabe): ?>
  <section class="karte-box"><p>Diese Ausgabe gibt es nicht. <a href="?s=zeitung">Zur heutigen Ausgabe</a></p></section>
<?php else: ?>
<article class="zeitung" id="zeitung" data-ki="<?= $kiWartet ? '1' : '0' ?>">
  <header class="zeitung-kopf">
    <div class="zeitung-meta"><span><?= h($i['datum'] ?? '') ?></span><span>Ausgabe Nr. <?= (int)$tag ?></span><span><?= $ausgabe['quelle'] === 'ki' ? 'aus der Redaktion' : 'Kurzausgabe' ?></span></div>
    <div class="zeitung-name">Herbinumer Anzeiger</div>
    <div class="zeitung-meta"><span>Unabhängig · überparteilich · westfälisch</span><span>Preis: 1 Taler</span></div>
  </header>
  <?php if ($kiWartet): ?><p class="leise zeitung-hinweis" id="zeitung-status">Die Redaktion schreibt gerade an der ausführlichen Ausgabe …</p><?php endif ?>
  <h1 class="schlagzeile"><?= h($i['titel'] ?? 'Neues aus Herbinum') ?></h1>

  <div class="zeitung-spalten">
    <div class="artikel-spalte">
      <?php foreach ($i['artikel'] ?? [] as $a): ?>
        <section class="artikel">
          <h2><?= h($a['ueberschrift']) ?></h2>
          <?php foreach (preg_split('/\n{2,}/', trim($a['text'])) as $absatz): ?><p><?= nl2br(h(trim($absatz))) ?></p><?php endforeach ?>
        </section>
      <?php endforeach ?>
    </div>
    <aside class="wetter-kasten">
      <h2>Wetter</h2>
      <?php if (!empty($i['wetter'])): ?>
        <div class="wetter-heute"><b><?= (int)$i['wetter']['temp'] ?> °C</b><span><?= h($i['wetter']['lage']) ?> · Wind <?= (int)$i['wetter']['wind'] ?> km/h</span></div>
      <?php endif ?>
      <h3>Aussichten</h3>
      <ol class="vorhersage">
        <?php foreach ($i['vorhersage'] ?? [] as $v): ?>
          <li>
            <div class="vh-tag"><b><?= h($v['wochentag']) ?></b> <span><?= h($v['wetter']) ?>, <?= (int)$v['temp'] ?> °C</span></div>
            <?php foreach ($v['risiken'] as $r): ?>
              <div class="risiko risiko-<?= $risikoKlasse((int)$r['prozent']) ?>"><span aria-hidden="true"><?= EREIGNISSE[$r['typ']]['icon'] ?? '!' ?></span>
                <?= h($r['name']) ?> <b><?= (int)$r['prozent'] ?> %</b><small><?= h($r['gegend'] === 'unklar' ? 'Gerücht' : $r['gegend']) ?><?= $r['staerke'] ? ' · eher ' . h($r['staerke']) : '' ?></small></div>
            <?php endforeach ?>
            <?php if (!$v['risiken']): ?><div class="leise">keine Warnungen</div><?php endif ?>
          </li>
        <?php endforeach ?>
      </ol>
      <p class="leise">Je weiter weg, desto unsicherer – der Wetterfrosch irrt sich auch mal.</p>
    </aside>
  </div>
</article>
<?php endif ?>
<?php if (count($archiv) > 1): ?>
  <nav class="reiter" aria-label="Frühere Ausgaben">
    <?php foreach ($archiv as $t): ?>
      <a href="?s=zeitung&amp;tag=<?= (int)$t ?>"<?= (int)$t === $tag ? ' aria-current="page"' : '' ?>><?= (int)$t === $heute ? 'Heute' : date('d.m.', spieltag_beginn((int)$t) + 43200) ?></a>
    <?php endforeach ?>
  </nav>
<?php endif ?>
<?php require __DIR__ . '/fuss.php'; ?>
