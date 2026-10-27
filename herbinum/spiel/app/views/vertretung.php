<?php
/** @var array $spieler @var array|null $gen */
$titel = 'Vertretung';
require __DIR__ . '/kopf.php';
$sid = (int)$spieler['id'];
$v = vertretung_laden($sid);
$fenster = vertretung_fenster($v, time());
$tage = $v ? str_split($v['tage']) : ['1', '2', '3', '4', '5'];
$rechte = $v['rechte_liste'] ?? ['bauen', 'forschen', 'werkstatt', 'zuege', 'bunker'];
$kurz = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
$andere = vertretbare($sid);
?>
<div class="kopfzeile">
  <div><div class="eyebrow">Für alle, die nicht immer online sein können</div><h1>Vertretung</h1></div>
</div>

<div class="raster-gen">
  <div class="spalte">
    <section class="karte-box">
      <h2>Dein Zeitfenster</h2>
      <p>Trag ein, wann du regelmäßig nicht spielen kannst – zum Beispiel während der Arbeit. In dieser Zeit
        <b>steuerst du dein Konto nicht selbst</b>, aber Mitglieder deiner Allianz dürfen die Dinge erledigen, die du unten erlaubst.
        Jede ihrer Aktionen landet als Nachricht bei dir.</p>
      <?php if (!$gen): ?>
        <p class="meldung fehler">Du bist in keiner Allianz – ohne Allianz kann dich niemand vertreten. <a href="?s=allianz">Zur Allianz</a></p>
      <?php endif ?>
      <?php if ($v): ?>
        <p class="status-zeile">
          <?php if ($fenster): ?>
            <span class="chip gefahr">läuft</span> bis <?= date('H:i', $fenster[1]) ?> Uhr.
          <?php elseif ((int)$v['gueltig_ab_ts'] > time()): ?>
            <span class="chip">ab <?= date('d.m. H:i', (int)$v['gueltig_ab_ts']) ?> Uhr</span>
          <?php else: ?>
            <span class="chip ok">eingerichtet</span>
          <?php endif ?>
          <?= h(tage_text($v['tage'])) ?>, <?= uhrzeit((int)$v['von_min']) ?>–<?= uhrzeit((int)$v['bis_min']) ?> Uhr
        </p>
      <?php endif ?>
      <?php if ($fenster): ?>
        <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="vertretung_zurueck"><button class="knopf" type="submit">Ich bin wieder da</button></form>
        <p class="leise">Beendet nur das heutige Fenster. Ändern oder löschen kannst du es, wenn es nicht läuft.</p>
      <?php else: ?>
        <form method="post" class="formular">
          <?= csrf_feld() ?>
          <input type="hidden" name="a" value="vertretung_speichern">
          <fieldset class="tage"><legend>Wochentage</legend>
            <?php foreach ($kurz as $n => $t): ?><label class="tag-wahl"><input type="checkbox" name="tage[]" value="<?= $n ?>"<?= in_array((string)$n, $tage, true) ? ' checked' : '' ?>><span><?= $t ?></span></label><?php endforeach ?>
          </fieldset>
          <div class="zeit-zeile">
            <label for="v-von">von</label><input id="v-von" name="von" type="time" value="<?= $v ? uhrzeit((int)$v['von_min']) : '07:30' ?>" required>
            <label for="v-bis">bis</label><input id="v-bis" name="bis" type="time" value="<?= $v ? uhrzeit((int)$v['bis_min']) : '16:30' ?>" required>
          </div>
          <fieldset><legend>Das darf deine Allianz in dieser Zeit</legend>
            <?php foreach (VERTRETUNG_RECHTE as $k => $text): ?>
              <label class="haken"><input type="checkbox" name="rechte[]" value="<?= $k ?>"<?= in_array($k, $rechte, true) ? ' checked' : '' ?>> <?= h($text) ?></label>
            <?php endforeach ?>
          </fieldset>
          <button class="knopf" type="submit">Speichern</button>
          <p class="leise">Höchstens <?= VERTRETUNG_MAX_STUNDEN ?> Stunden am Stück; ein Fenster über Mitternacht (z. B. Nachtschicht 22:00–06:00) geht auch.
            Änderungen gelten erst nach <?= VERTRETUNG_VORLAUF_S / 3600 ?> Stunden. Raubzüge, Erkundungen, Besiedlungen, Spielerhandel, Nachrichten und Allianzsachen darf nie jemand anderes für dich erledigen.</p>
        </form>
        <?php if ($v): ?>
          <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="vertretung_loeschen"><button class="knopf leicht gefahr-knopf" type="submit">Vertretung löschen</button></form>
        <?php endif ?>
      <?php endif ?>
    </section>
  </div>
  <div class="spalte">
    <section class="karte-box">
      <h2>Jetzt vertreten</h2>
      <?php if (!$andere): ?>
        <p class="leise">Gerade ist niemand aus deiner Allianz in seinem Vertretungsfenster.</p>
      <?php else: ?>
        <?php foreach ($andere as $x): ?>
          <div class="aktionen vertreten-zeile">
            <span><span class="spielername"><i style="background:<?= h($x['spieler']['farbe']) ?>"></i><?= h($x['spieler']['name']) ?></span>
              <span class="leise">bis <?= date('H:i', $x['bis']) ?> Uhr</span></span>
            <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="vertreten_start"><input type="hidden" name="spieler" value="<?= (int)$x['spieler']['id'] ?>"><button class="knopf" type="submit">Vertreten</button></form>
          </div>
        <?php endforeach ?>
      <?php endif ?>
    </section>
    <section class="karte-box">
      <h2>Warum das fair ist</h2>
      <p class="leise">Wer tagsüber arbeitet, wäre gegen Spieler, die ständig online sind, schnell im Nachteil. Mit der Vertretung kann die Allianz Bauaufträge nachlegen,
        Truppen in den Bunker bringen oder Vorräte in Sicherheit fahren. Weil du in deinem Fenster selbst nicht steuerst, spielt nie mehr als eine Person gleichzeitig dein Konto.</p>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
