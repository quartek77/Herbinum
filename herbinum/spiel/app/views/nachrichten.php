<?php
/** @var array $spieler */
$titel = 'Nachrichten';
$sid = (int)$spieler['id'];
$ordner = in_array($_GET['ordner'] ?? '', ['briefe', 'gesendet'], true) ? $_GET['ordner'] : 'eingang';
$filter = ['eingang' => "art <> 'gesendet'", 'briefe' => "art = 'brief'", 'gesendet' => "art = 'gesendet'"][$ordner];
$st = db()->prepare("SELECT * FROM nachrichten WHERE spieler_id = ? AND {$filter} ORDER BY ts DESC, id DESC LIMIT 100");
$st->execute([$sid]);
$liste = $st->fetchAll();
$neueBriefe = db()->prepare("SELECT COUNT(*) FROM nachrichten WHERE spieler_id = ? AND art = 'brief' AND gelesen = 0");
$neueBriefe->execute([$sid]);
$neueBriefe = (int)$neueBriefe->fetchColumn();
require __DIR__ . '/kopf.php';
// Nach dem Anzeigen als gelesen markieren
db()->prepare("UPDATE nachrichten SET gelesen = 1 WHERE spieler_id = ? AND gelesen = 0 AND {$filter}")->execute([$sid]);
$res = function (array $r): string {
    $t = [];
    foreach (RESSOURCEN as $k => $n) {
        if (!empty($r[$k])) {
            $t[] = zahl((float)$r[$k]) . ' ' . $n;
        }
    }
    return $t ? implode(' · ', $t) : 'nichts';
};
$artName = ['admin' => 'Spielleitung', 'kampf' => 'Kampf', 'bericht' => 'Erkundung', 'transport' => 'Transport', 'kolonie' => 'Besiedlung', 'forschung' => 'Forschung',
            'warnung' => 'Warnung', 'genossenschaft' => 'Allianz', 'brief' => 'Brief', 'gesendet' => 'Gesendet', 'ereignis' => 'Ereignis', 'markt' => 'Markt', 'vertretung' => 'Vertretung'];
$entwurf = $_SESSION['brief_entwurf'] ?? [];
unset($_SESSION['brief_entwurf']);
$schreiben = isset($_GET['schreiben']) || $entwurf;
$an = (int)($entwurf['an'] ?? ($_GET['an'] ?? 0));
$betreff = (string)($entwurf['betreff'] ?? ($_GET['betreff'] ?? ''));
$empfaenger = array_values(array_filter(spieler_namen(), fn($s) => (int)$s['id'] !== $sid));
?>
<div class="kopfzeile">
  <div><div class="eyebrow">Berichte, Meldungen und Briefe</div><h1>Nachrichten</h1></div>
  <div class="aktionen">
    <a class="knopf" href="?s=nachrichten&amp;schreiben=1">Brief schreiben</a>
    <?php if ($liste && $ordner !== 'gesendet'): ?>
      <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="nachrichten_loeschen"><button class="knopf leicht" type="submit">Gelesene löschen</button></form>
    <?php endif ?>
  </div>
</div>
<nav class="reiter" aria-label="Ordner">
  <a href="?s=nachrichten"<?= $ordner === 'eingang' ? ' aria-current="page"' : '' ?>>Alle</a>
  <a href="?s=nachrichten&amp;ordner=briefe"<?= $ordner === 'briefe' ? ' aria-current="page"' : '' ?>>Briefe<?= $neueBriefe && $ordner !== 'briefe' ? ' <span class="zahlmarke">' . $neueBriefe . '</span>' : '' ?></a>
  <a href="?s=nachrichten&amp;ordner=gesendet"<?= $ordner === 'gesendet' ? ' aria-current="page"' : '' ?>>Gesendet</a>
</nav>
<?php if ($schreiben): ?>
  <section class="karte-box brief-form">
    <h2>Brief schreiben</h2>
    <form method="post" class="formular">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="brief">
      <label for="b-an">An</label>
      <select id="b-an" name="an" required>
        <option value="">– Spieler wählen –</option>
        <?php foreach ($empfaenger as $e): ?><option value="<?= (int)$e['id'] ?>"<?= (int)$e['id'] === $an ? ' selected' : '' ?>><?= h($e['name']) ?></option><?php endforeach ?>
      </select>
      <label for="b-betreff">Betreff</label>
      <input id="b-betreff" name="betreff" maxlength="140" required value="<?= h($betreff) ?>">
      <label for="b-text">Text</label>
      <textarea id="b-text" name="text" rows="6" maxlength="4000" required><?= h((string)($entwurf['text'] ?? '')) ?></textarea>
      <div class="aktionen"><button class="knopf" type="submit">Abschicken</button><a class="knopf leicht" href="?s=nachrichten">Abbrechen</a></div>
    </form>
  </section>
<?php endif ?>
<?php if (!$liste): ?>
  <p class="leise"><?= $ordner === 'gesendet' ? 'Du hast noch keine Briefe geschrieben.' : ($ordner === 'briefe' ? 'Noch keine Briefe. Schreib doch selbst einen!' : 'Noch keine Nachrichten. Hier landen Briefe, Erkundungsberichte, Lieferungen, Ereignisse und fertige Forschungen.') ?></p>
<?php endif ?>
<div class="nachrichten">
  <?php foreach ($liste as $n):
      $i = json_decode($n['inhalt'], true) ?: []; ?>
    <article class="nachricht<?= $n['gelesen'] ? '' : ' neu' ?> art-<?= h($n['art']) ?>">
      <header>
        <span class="chip"><?= h($artName[$n['art']] ?? $n['art']) ?></span>
        <h2><?= h($n['titel']) ?></h2>
        <time datetime="<?= date('c', (int)$n['ts']) ?>"><?= date('d.m. H:i', (int)$n['ts']) ?></time>
      </header>
      <?php if (in_array($n['art'], ['brief', 'gesendet'], true)): ?>
        <p class="leise"><?= $n['art'] === 'brief' ? 'Von <b>' . h($i['von'] ?? '?') . '</b>' : 'An <b>' . h($i['an'] ?? '?') . '</b>' ?></p>
        <p class="brieftext"><?= nl2br(h($i['text'] ?? '')) ?></p>
        <div class="aktionen">
          <?php if ($n['art'] === 'brief' && !empty($i['von_id'])): ?>
            <a class="knopf leicht" href="?s=nachrichten&amp;schreiben=1&amp;an=<?= (int)$i['von_id'] ?>&amp;betreff=<?= urlencode(str_starts_with($n['titel'], 'Re: ') ? $n['titel'] : 'Re: ' . $n['titel']) ?>">Antworten</a>
            <a class="knopf leicht" href="?s=chat&amp;k=privat&amp;mit=<?= (int)$i['von_id'] ?>">Im Chat schreiben</a>
          <?php endif ?>
          <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="nachricht_loeschen"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="ordner" value="<?= h($ordner) ?>"><button class="knopf leicht" type="submit">Löschen</button></form>
        </div>
      <?php elseif (!empty($i['text'])): ?><p><?= h($i['text']) ?></p><?php endif ?>
      <?php if ($n['art'] === 'ereignis'): ?>
        <dl class="bericht">
          <div><dt>Stärke</dt><dd><?= h($i['staerke'] ?? '') ?> · dauert bis <?= date('d.m. H:i', (int)($i['bis'] ?? 0)) ?> Uhr</dd></div>
          <?php if (!empty($i['verlust'])): ?><div><dt>Verloren</dt><dd><?= h($res($i['verlust'])) ?></dd></div><?php endif ?>
          <?php if (!empty($i['wirkung'])): ?><div><dt>Solange</dt><dd><?= h(implode(' · ', array_map(fn($k, $w) => ($k === 'strom' ? 'Strom' : (RESSOURCEN[$k] ?? $k)) . ' ' . ($w > 0 ? '+' : '−') . round(abs($w) * 100) . ' %', array_keys($i['wirkung']), $i['wirkung']))) ?></dd></div><?php endif ?>
          <?php if (isset($i['opfer'])): ?><div><dt>Opfer</dt><dd><?= $i['opfer'] ? h(implode(', ', array_map(fn($t, $c) => "{$c} × " . (EINHEITEN[$t]['name'] ?? $t), array_keys($i['opfer']), $i['opfer']))) : 'keine' ?> (Abwehr <?= (int)($i['abwehr'] ?? 0) ?> %)</dd></div><?php endif ?>
          <?php if (!empty($i['bauverzug'])): ?><div><dt>Baustellen</dt><dd>ruhen <?= dauer((int)$i['bauverzug']) ?></dd></div><?php endif ?>
        </dl>
      <?php endif ?>
      <?php if (!empty($i['einheiten']) && $n['art'] !== 'bericht'): ?><p class="leise">Einheiten: <?= h(implode(', ', array_map(fn($t, $c) => "{$c} × " . (EINHEITEN[$t]['name'] ?? $t), array_keys($i['einheiten']), $i['einheiten']))) ?></p><?php endif ?>
      <?php if (isset($i['ladung']) && array_sum($i['ladung']) > 0): ?><p class="leise">Ladung: <?= h($res($i['ladung'])) ?></p><?php endif ?>
      <?php if ($n['art'] === 'kampf' && isset($i['a'])):
          $seiteText = fn(array $s) => $s ? implode(', ', array_map(fn($t, $p) => (EINHEITEN[$t]['name'] ?? $t) . ' ' . $p[0] . ' → ' . $p[1], array_keys($s), $s)) : 'niemand'; ?>
        <dl class="bericht">
          <div><dt>Angreifer</dt><dd><?= h($i['angreifer']) ?>: <?= h($seiteText($i['a'])) ?></dd></div>
          <div><dt>Verteidiger</dt><dd><?= h($i['verteidiger']) ?>: <?= h($seiteText($i['v'])) ?></dd></div>
          <div><dt>Runden</dt><dd><?= (int)$i['runden'] ?> · Graben Stufe <?= (int)$i['graben'] ?> · Wehrkraft <?= (int)$i['wehr'] ?><?= !empty($i['nacht']) ? ' · Nachtwache +' . round(NACHT_VERTEIDIGUNG * 100) . ' %' : '' ?></dd></div>
          <?php if (array_sum($i['beute'])): ?><div><dt>Beute</dt><dd><?= h($res($i['beute'])) ?></dd></div><?php endif ?>
          <?php if ($i['nachgewachsen']): ?><div><dt>Nachgewachsen</dt><dd><?= h(implode(', ', array_map(fn($t, $c) => "{$c} × " . EINHEITEN[$t]['name'], array_keys($i['nachgewachsen']), $i['nachgewachsen']))) ?></dd></div><?php endif ?>
        </dl>
      <?php endif ?>
      <?php if ($n['art'] === 'bericht'): ?>
        <dl class="bericht">
          <div><dt>Besitzer</dt><dd><?= $i['besitzer'] ? h($i['besitzer']) : 'frei – besiedelbar' ?></dd></div>
          <?php foreach ($i['werte'] ?? [] as $k => $v): ?><div><dt><?= h($k) ?></dt><dd><?= (int)$v ?></dd></div><?php endforeach ?>
          <?php if (!empty($i['vorraete'])): ?><div><dt>Vorräte</dt><dd><?= h($res($i['vorraete'])) ?></dd></div><?php endif ?>
          <?php if (array_key_exists('einheiten', $i)): ?><div><dt>Einheiten</dt><dd><?= $i['einheiten'] ? h(implode(', ', array_map(fn($t, $c) => "{$c} × " . (EINHEITEN[$t]['name'] ?? $t), array_keys($i['einheiten']), $i['einheiten']))) : 'keine' ?></dd></div><?php endif ?>
          <?php if (array_key_exists('gebaeude', $i)): ?><div><dt>Gebäude</dt><dd><?= h(implode(', ', array_map(fn($t, $c) => (GEBAEUDE[$t]['name'] ?? $t) . " {$c}", array_keys($i['gebaeude']), $i['gebaeude']))) ?></dd></div><?php endif ?>
          <?php if (array_key_exists('forschung', $i)): ?><div><dt>Forschung</dt><dd><?= $i['forschung'] ? h(implode(', ', array_map(fn($t, $c) => (FORSCHUNG[$t]['name'] ?? $t) . " {$c}", array_keys($i['forschung']), $i['forschung']))) : 'keine' ?></dd></div><?php endif ?>
        </dl>
        <?php if ($i['besitzer'] && ($i['tiefe'] ?? 0) < 3): ?><p class="leise">Mehr Dorftratsch oder mehr Kuriere verraten mehr.</p><?php endif ?>
      <?php endif ?>
    </article>
  <?php endforeach ?>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
