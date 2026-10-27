<?php
/** @var array $spieler */
$titel = 'KI-Berater';
require __DIR__ . '/kopf.php';
$sid = (int)$spieler['id'];
$verlauf = berater_verlauf($sid);
$limit = (int)ki_einstellung('ki_berater_limit');
$rest = max(0, $limit - berater_fragen_heute($sid));
$beispiele = [
    'Wie lange dauert es noch, bis ich die nächste Stufe Verwaltung erforschen kann?',
    'Was sollte ich als Nächstes bauen – und in welcher Reihenfolge?',
    'Was fehlt mir noch für einen Siedlerkonvoi, mit dem ich wirklich siedeln kann?',
    'Warum wächst meine Holzproduktion nicht schneller?',
    'Wie schütze ich mich am besten gegen Raubzüge?',
    'Lohnt sich gerade ein Tausch beim Händler?',
];
?>
<div class="kopfzeile">
  <div><div class="eyebrow">Deine Daten, ausgewertet von Claude Haiku</div><h1>KI-Berater</h1></div>
  <?php if ($verlauf): ?>
    <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="berater_loeschen"><button class="knopf leicht" type="submit">Verlauf löschen</button></form>
  <?php endif ?>
</div>

<div class="berater-raster">
  <section class="karte-box">
    <div class="berater-verlauf" id="berater-verlauf" aria-live="polite">
      <?php if (!$verlauf): ?>
        <div class="berater-msg antwort"><p>Hi <?= h($spieler['name']) ?>! Ich kenne deine Bereiche, Vorräte, Produktion und Forschung und rechne für dich aus,
          wann du dir etwas leisten kannst und was du dafür noch brauchst. Frag einfach – oder tippe rechts auf eine Beispielfrage.</p></div>
      <?php endif ?>
      <?php foreach ($verlauf as $m): ?>
        <div class="berater-msg <?= $m['rolle'] === 'user' ? 'frage' : 'antwort' ?>"><?= $m['rolle'] === 'user' ? '<p>' . nl2br(h($m['text'])) . '</p>' : berater_html($m['text']) ?></div>
      <?php endforeach ?>
    </div>
    <form method="post" class="chat-eingabe" id="berater-form" autocomplete="off">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="berater_frage">
      <label class="sr" for="berater-frage">Deine Frage</label>
      <input id="berater-frage" name="frage" maxlength="<?= BERATER_LAENGE ?>" placeholder="Was möchtest du wissen?" required<?= $rest ? '' : ' disabled' ?>>
      <button class="knopf" type="submit"<?= $rest ? '' : ' disabled' ?>>Fragen</button>
    </form>
    <p class="leise" id="berater-rest"><?= $rest ?> von <?= $limit ?> Fragen heute übrig. Antworten dauern meist ein paar Sekunden.</p>
  </section>

  <div class="spalte">
    <section class="karte-box">
      <details class="anleitung" open>
        <summary><b>So nutzt du den Berater</b></summary>
        <ol>
          <li><b>Frag in deinen Worten</b> – zum Beispiel nach Wartezeiten, Bau-Reihenfolgen oder was dir für eine Einheit noch fehlt.</li>
          <li>Der Berater sieht <b>deine aktuellen Daten</b>: alle Bereiche, Vorräte, Produktion, Lager, Gebäude, Forschung, Einheiten und anrückende Züge. Die Wartezeiten rechnet das Spiel exakt aus.</li>
          <li><b>Nachfragen</b> sind möglich – er erinnert sich an die letzten Fragen. Mit „Verlauf löschen“ fängst du neu an.</li>
          <li>Er <b>verrät nichts über andere Spieler</b> und kann im Spiel nichts für dich auslösen.</li>
          <li>Pro Tag hast du <b><?= $limit ?> Fragen</b>. Rechne mit Schätzungen: Bauzeiten und Unwetter können die Pläne verschieben.</li>
        </ol>
      </details>
    </section>
    <section class="karte-box">
      <h2>Beispielfragen</h2>
      <div class="beispiele">
        <?php foreach ($beispiele as $b): ?><button type="button" class="beispiel" data-frage="<?= h($b) ?>"><?= h($b) ?></button><?php endforeach ?>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
