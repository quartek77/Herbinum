<?php
/** @var array $spieler @var array|null $gen */
$titel = 'Chat';
require __DIR__ . '/kopf.php';
$sid = (int)$spieler['id'];
$kanal = (string)($_GET['k'] ?? 'alle');
$kanal = isset(CHAT_KANAELE[$kanal]) ? $kanal : 'alle';
if ($kanal === 'allianz' && !$gen) {
    $kanal = 'alle';
}
$partner = (int)($_GET['mit'] ?? 0);
$neu = chat_ungelesen($sid);
$alleSpieler = array_values(array_filter(spieler_namen(), fn($s) => (int)$s['id'] !== $sid));
$namen = array_column($alleSpieler, null, 'id');
$gespraeche = chat_gespraeche($sid);
if ($kanal === 'privat' && !isset($namen[$partner])) {
    $partner = (int)($gespraeche[0]['partner'] ?? 0);
}
$zeigeVerlauf = $kanal !== 'privat' || isset($namen[$partner]);
?>
<div class="kopfzeile">
  <div><div class="eyebrow">Öffentlich, Allianz und privat</div><h1>Chat</h1></div>
</div>

<nav class="reiter" aria-label="Kanäle">
  <a href="?s=chat&amp;k=alle"<?= $kanal === 'alle' ? ' aria-current="page"' : '' ?>>Öffentlich<?= $neu['alle'] && $kanal !== 'alle' ? ' <span class="zahlmarke">' . $neu['alle'] . '</span>' : '' ?></a>
  <?php if ($gen): ?><a href="?s=chat&amp;k=allianz"<?= $kanal === 'allianz' ? ' aria-current="page"' : '' ?>>Allianz [<?= h($gen['kuerzel']) ?>]<?= $neu['allianz'] && $kanal !== 'allianz' ? ' <span class="zahlmarke">' . $neu['allianz'] . '</span>' : '' ?></a><?php endif ?>
  <a href="?s=chat&amp;k=privat"<?= $kanal === 'privat' ? ' aria-current="page"' : '' ?>>Privat<?= $neu['privat'] && $kanal !== 'privat' ? ' <span class="zahlmarke">' . $neu['privat'] . '</span>' : '' ?></a>
</nav>

<div class="chat-raster<?= $kanal === 'privat' ? ' mit-liste' : '' ?>">
  <?php if ($kanal === 'privat'): ?>
    <aside class="karte-box chat-partner">
      <form method="get" class="formular">
        <input type="hidden" name="s" value="chat"><input type="hidden" name="k" value="privat">
        <label for="mit">Gespräch beginnen mit</label>
        <select id="mit" name="mit" onchange="this.form.submit()">
          <option value="">– Spieler wählen –</option>
          <?php foreach ($alleSpieler as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $partner ? ' selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach ?>
        </select>
      </form>
      <?php if ($gespraeche): ?>
        <div class="liste">
          <?php foreach ($gespraeche as $g): $p = $namen[(int)$g['partner']] ?? null; if (!$p) continue; ?>
            <a class="zeile<?= (int)$g['partner'] === $partner ? ' sel' : '' ?>" href="?s=chat&amp;k=privat&amp;mit=<?= (int)$g['partner'] ?>">
              <span class="punkt" style="background:<?= h($p['farbe']) ?>"></span><span class="nm"><?= h($p['name']) ?></span>
              <span class="sub"><?= (int)$g['neu'] ? '<span class="zahlmarke">' . (int)$g['neu'] . '</span>' : '' ?></span></a>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </aside>
  <?php endif ?>

  <section class="karte-box chat-box">
    <?php if (!$zeigeVerlauf): ?>
      <p class="leise">Wähle links einen Spieler aus, um ein privates Gespräch zu beginnen.</p>
    <?php else: ?>
      <h2><?= $kanal === 'privat' ? 'Mit ' . h($namen[$partner]['name']) : ($kanal === 'allianz' ? 'Allianz ' . h($gen['name']) : 'Öffentlicher Chat – alle lesen mit') ?></h2>
      <div class="chat-verlauf" id="chat" data-kanal="<?= h($kanal) ?>" data-partner="<?= $partner ?>" data-admin="<?= (int)$spieler['ist_admin'] ?>" aria-live="polite">
        <p class="leise">Lädt …</p>
      </div>
      <form class="chat-eingabe" id="chat-form" autocomplete="off">
        <label class="sr" for="chat-text">Nachricht</label>
        <input id="chat-text" name="text" maxlength="<?= CHAT_LAENGE ?>" placeholder="<?= $kanal === 'alle' ? 'Schreib etwas in den öffentlichen Chat …' : 'Nachricht …' ?>" required>
        <button class="knopf" type="submit">Senden</button>
      </form>
      <?php if ($kanal === 'alle'): ?><p class="leise">Hier lesen alle mit – auch Herbinum News, die manchmal darüber berichten.</p><?php endif ?>
    <?php endif ?>
  </section>
</div>
<?php if ((int)$spieler['ist_admin'] && $kanal === 'alle'): ?>
  <form method="post" id="chat-loeschen" hidden><?= csrf_feld() ?><input type="hidden" name="a" value="admin_chat_loeschen"><input type="hidden" name="chat" value=""></form>
<?php endif ?>
<?php require __DIR__ . '/fuss.php'; ?>
