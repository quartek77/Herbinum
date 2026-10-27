<?php
/** @var array $z */
$titel = 'Karte';
require __DIR__ . '/kopf.php';
?>
<div class="kartenansicht" id="karte" data-heimat="<?= h($z['bereich']['id']) ?>">
  <div class="karte-flaeche" id="kartenflaeche"><div class="laden">Karte wird geladen …</div></div>
  <aside class="karte-panel" id="kartenpanel"></aside>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
