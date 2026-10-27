<?php
/** @var array $spieler @var array|null $gen */
$titel = 'Allianz';
require __DIR__ . '/kopf.php';
$sid = (int)$spieler['id'];
$punkte = punkte_alle();
$pkt = fn(int $id) => $punkte[$id]['gesamt'] ?? 0;
$wann = fn(int $ts) => $ts ? date('d.m.y', $ts) : '–';
$fremdId = (int)($_GET['id'] ?? 0);
$zeige = $fremdId && $fremdId !== (int)($gen['id'] ?? 0) ? genossenschaft($fremdId) : null;
$hidden = fn(string $a, array $f = []) => csrf_feld() . '<input type="hidden" name="a" value="' . h($a) . '">'
    . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">', array_keys($f), $f));
?>

<?php if ($zeige):
    // ---------- Profil einer fremden Allianz ----------
    $mitglieder = gen_mitglieder((int)$zeige['id']);
    $beworben = in_array((int)$zeige['id'], array_map('intval', array_column(eigene_bewerbungen($sid), 'genossenschaft_id')), true); ?>
  <div class="kopfzeile"><div>
    <div class="eyebrow">Allianz · gegründet <?= $wann((int)$zeige['erstellt_ts']) ?></div>
    <div class="gen-kopf"><?= wappen_html($zeige, 'wappen riesig') ?><h1><?= h($zeige['name']) ?></h1><span class="kuerzel">[<?= h($zeige['kuerzel']) ?>]</span></div>
  </div></div>
  <div class="raster-gen">
    <section class="karte-box">
      <h2>Über uns</h2>
      <p class="beschreibung"><?= $zeige['beschreibung'] !== '' ? h($zeige['beschreibung']) : '<span class="leise">Noch keine Beschreibung.</span>' ?></p>
      <h2>Mitglieder (<?= count($mitglieder) ?> von <?= gen_max_mitglieder() ?>)</h2>
      <table class="tabelle mitglieder">
        <?php foreach ($mitglieder as $m): ?>
          <tr><td><?= spieler_html($m) ?></td>
            <td><?= GEN_RAENGE[$m['gen_rang']] ?? '' ?></td><td class="n"><?= zahl($pkt((int)$m['id'])) ?> P.</td></tr>
        <?php endforeach ?>
      </table>
    </section>
    <section class="karte-box">
      <?php if ($gen): ?>
        <p class="leise">Du bist schon in <a href="?s=allianz"><?= h($gen['name']) ?></a>. Um zu wechseln, musst du erst austreten.</p>
      <?php elseif ($beworben): ?>
        <p>Deine Bewerbung liegt dem Vorstand vor.</p>
        <form method="post"><?= $hidden('gen_zurueckziehen', ['gid' => $zeige['id']]) ?><button class="knopf leicht" type="submit">Bewerbung zurückziehen</button></form>
      <?php elseif (count($mitglieder) >= gen_max_mitglieder()): ?>
        <p class="leise">Diese Allianz ist voll.</p>
      <?php else: ?>
        <h2>Bewerben</h2>
        <form method="post" class="formular">
          <?= $hidden('gen_bewerben', ['gid' => $zeige['id']]) ?>
          <label for="b-text">Ein paar Worte an den Vorstand (optional)</label>
          <textarea id="b-text" name="text" rows="4" maxlength="500" placeholder="Wo du siedelst, was du vorhast …"></textarea>
          <button class="knopf" type="submit">Bewerbung abschicken</button>
        </form>
      <?php endif ?>
      <a href="?s=allianz">← Zurück</a>
    </section>
  </div>

<?php elseif ($gen):
    // ---------- Eigene Allianz ----------
    $vorstand = $gen['rang'] === 'vorstand';
    $mitglieder = gen_mitglieder((int)$gen['id']);
    $bewerbungen = $vorstand ? gen_bewerbungen((int)$gen['id']) : [];
    $summe = array_sum(array_map(fn($m) => $pkt((int)$m['id']), $mitglieder)); ?>
  <div class="kopfzeile"><div>
    <div class="eyebrow">Deine Allianz · <?= GEN_RAENGE[$gen['rang']] ?? '' ?> seit <?= $wann((int)$gen['seit']) ?></div>
    <div class="gen-kopf"><?= wappen_html($gen, 'wappen riesig') ?><h1><?= h($gen['name']) ?></h1><span class="kuerzel">[<?= h($gen['kuerzel']) ?>]</span></div>
  </div></div>
  <div class="raster-gen">
    <div class="spalte">
      <section class="karte-box">
        <h2>Mitglieder (<?= count($mitglieder) ?> von <?= gen_max_mitglieder() ?>) · <?= zahl($summe) ?> Punkte</h2>
        <div class="tabelle-rahmen">
        <table class="tabelle mitglieder">
          <tr><th>Name</th><th>Rang</th><th class="n">Punkte</th><th>Dabei seit</th><th>Zuletzt da</th><?php if ($vorstand): ?><th></th><?php endif ?></tr>
          <?php foreach ($mitglieder as $m): $mid = (int)$m['id']; ?>
            <tr>
              <td><?= spieler_html($m) ?><?= $mid === $sid ? ' <span class="leise">(du)</span>' : '' ?></td>
              <td><?= GEN_RAENGE[$m['gen_rang']] ?? '' ?></td>
              <td class="n"><?= zahl($pkt($mid)) ?></td>
              <td class="n"><?= $wann((int)$m['gen_seit_ts']) ?></td>
              <td class="n"><?= $wann((int)$m['zuletzt_ts']) ?></td>
              <?php if ($vorstand): ?>
                <td class="knoepfe">
                  <?php if ($mid !== $sid): ?>
                    <form method="post"><?= $hidden('gen_rang', ['spieler' => $mid, 'rang' => $m['gen_rang'] === 'vorstand' ? 'mitglied' : 'vorstand']) ?>
                      <button class="knopf leicht" type="submit"><?= $m['gen_rang'] === 'vorstand' ? 'Aus dem Vorstand' : 'In den Vorstand' ?></button></form>
                    <form method="post"><?= $hidden('gen_entfernen', ['spieler' => $mid]) ?><button class="knopf leicht gefahr-knopf" type="submit">Entfernen</button></form>
                  <?php endif ?>
                </td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
        </table>
        </div>
      </section>

      <?php if ($vorstand): ?>
        <section class="karte-box">
          <h2>Bewerbungen</h2>
          <?php if (!$bewerbungen): ?>
            <p class="leise">Keine offenen Bewerbungen.</p>
          <?php endif ?>
          <?php foreach ($bewerbungen as $bw): ?>
            <div class="bewerbung">
              <b><?= h($bw['name']) ?></b> <span class="leise"><?= zahl($pkt((int)$bw['spieler_id'])) ?> Punkte · <?= date('d.m. H:i', (int)$bw['ts']) ?></span>
              <?php if ($bw['text'] !== ''): ?><p class="beschreibung"><?= h($bw['text']) ?></p><?php endif ?>
              <div class="aktionen">
                <form method="post"><?= $hidden('gen_annehmen', ['spieler' => $bw['spieler_id']]) ?><button class="knopf" type="submit">Aufnehmen</button></form>
                <form method="post"><?= $hidden('gen_ablehnen', ['spieler' => $bw['spieler_id']]) ?><button class="knopf leicht" type="submit">Ablehnen</button></form>
              </div>
            </div>
          <?php endforeach ?>
        </section>
      <?php endif ?>

      <section class="karte-box">
        <h2>Nachricht an alle Mitglieder</h2>
        <form method="post" class="formular">
          <?= $hidden('gen_rundschreiben') ?>
          <label for="g-titel">Betreff</label><input id="g-titel" name="titel" maxlength="140" required>
          <label for="g-text">Text</label><textarea id="g-text" name="text" rows="4" maxlength="2000" required></textarea>
          <button class="knopf" type="submit">Verschicken</button>
        </form>
      </section>
    </div>

    <div class="spalte">
      <section class="karte-box">
        <h2>Über uns</h2>
        <?php if ($vorstand): ?>
          <form method="post" class="formular">
            <?= $hidden('gen_beschreibung') ?>
            <label class="sr" for="g-besch">Beschreibung</label>
            <textarea id="g-besch" name="beschreibung" rows="6" maxlength="2000"><?= h($gen['beschreibung']) ?></textarea>
            <button class="knopf leicht" type="submit">Speichern</button>
          </form>
        <?php else: ?>
          <p class="beschreibung"><?= $gen['beschreibung'] !== '' ? h($gen['beschreibung']) : '<span class="leise">Noch keine Beschreibung.</span>' ?></p>
        <?php endif ?>
      </section>
      <?php if ($vorstand): ?>
        <section class="karte-box">
          <h2>Wappen</h2>
          <div class="wappen-zeile"><?= wappen_html($gen, 'wappen gross') ?>
            <form method="post" enctype="multipart/form-data" class="formular">
              <?= $hidden('gen_wappen') ?>
              <label for="g-wappen">Logo oder Wappen hochladen (PNG, JPG, GIF oder WebP, höchstens 1 MB)</label>
              <input id="g-wappen" name="wappen" type="file" accept="image/png,image/jpeg,image/gif,image/webp" required>
              <button class="knopf leicht" type="submit">Hochladen</button>
            </form>
          </div>
          <?php if (!empty($gen['wappen'])): ?>
            <form method="post"><?= $hidden('gen_wappen_weg') ?><button class="knopf leicht gefahr-knopf" type="submit">Wappen entfernen</button></form>
          <?php endif ?>
          <p class="leise">Quadratische Bilder sehen am besten aus; das Bild wird auf <?= WAPPEN_PIXEL ?> Pixel verkleinert. Das Wappen sehen alle Spieler.</p>
        </section>
      <?php endif ?>
      <section class="karte-box">
        <h2>Vertretung</h2>
        <?php $vertretbar = vertretbare($sid); ?>
        <?php if ($vertretbar): ?>
          <?php foreach ($vertretbar as $x): ?>
            <div class="aktionen vertreten-zeile">
              <span><?= spieler_html($x['spieler']) ?> <span class="leise">bis <?= date('H:i', $x['bis']) ?> Uhr</span></span>
              <form method="post"><?= $hidden('vertreten_start', ['spieler' => $x['spieler']['id']]) ?><button class="knopf" type="submit">Vertreten</button></form>
            </div>
          <?php endforeach ?>
        <?php else: ?>
          <p class="leise">Gerade braucht niemand eine Vertretung.</p>
        <?php endif ?>
        <p class="leise">Wer arbeitet, trägt unter <a href="?s=vertretung">Vertretung</a> ein Zeitfenster ein. Dann könnt ihr für ihn bauen, forschen und seine Truppen in Sicherheit bringen.</p>
      </section>
      <section class="karte-box">
        <h2>Was die Allianz bringt</h2>
        <ul class="liste-leise">
          <li>Untereinander gibt es keine Raubzüge.</li>
          <li>Eigener <a href="?s=chat&amp;k=allianz">Allianz-Chat</a>.</li>
          <li>Auf dem Markt nur <?= round(MARKT_GEBUEHR_ALLIANZ * 100) ?> % Gebühr statt <?= round(MARKT_GEBUEHR * 100) ?> %, und Angebote nur für die Allianz.</li>
          <li>Vertretung für Mitglieder, die gerade nicht spielen können.</li>
          <li>In der Rangliste zählen die Punkte aller Mitglieder zusammen.</li>
        </ul>
      </section>
      <section class="karte-box">
        <h2>Austreten</h2>
        <p class="leise"><?= $vorstand && count(array_filter($mitglieder, fn($m) => $m['gen_rang'] === 'vorstand')) === 1 && count($mitglieder) > 1
            ? 'Du bist der einzige Vorstand. Wenn du gehst, übernimmt das dienstälteste Mitglied.' : (count($mitglieder) === 1 ? 'Du bist das letzte Mitglied – die Allianz wird dann aufgelöst.' : '') ?></p>
        <form method="post"><?= $hidden('gen_austreten') ?><button class="knopf leicht" type="submit">Austreten</button></form>
        <?php if ($vorstand): ?>
          <form method="post" class="zeilenform"><?= $hidden('gen_aufloesen') ?>
            <label class="sr" for="g-auf">Kürzel zum Bestätigen</label>
            <input id="g-auf" name="bestaetigung" placeholder="Kürzel <?= h($gen['kuerzel']) ?> eintippen" autocomplete="off">
            <button class="knopf leicht gefahr-knopf" type="submit">Auflösen</button></form>
        <?php endif ?>
      </section>
    </div>
  </div>

<?php else:
    // ---------- Noch in keiner Allianz ----------
    $gens = gen_rangliste();
    $meine = eigene_bewerbungen($sid); ?>
  <div class="kopfzeile"><div>
    <div class="eyebrow">Zusammen ist man weniger allein</div><h1>Allianzen</h1>
  </div></div>
  <div class="raster-gen">
    <div class="spalte">
      <section class="karte-box">
        <h2>Alle Allianzen</h2>
        <?php if (!$gens): ?>
          <p class="leise">Noch keine – gründe die erste.</p>
        <?php else: ?>
          <table class="tabelle rangtabelle">
            <tr><th>Name</th><th class="n">Mitglieder</th><th class="n">Punkte</th></tr>
            <?php foreach ($gens as $g): ?>
              <tr><td><?= wappen_html($g, 'wappen klein') ?> <a href="?s=allianz&amp;id=<?= (int)$g['id'] ?>"><?= h($g['name']) ?></a> <span class="kuerzel">[<?= h($g['kuerzel']) ?>]</span></td>
                <td class="n"><?= (int)$g['mitglieder'] ?> / <?= gen_max_mitglieder() ?></td><td class="n"><?= zahl($g['punkte']) ?></td></tr>
            <?php endforeach ?>
          </table>
        <?php endif ?>
      </section>
      <?php if ($meine): ?>
        <section class="karte-box">
          <h2>Deine Bewerbungen</h2>
          <?php foreach ($meine as $bw): ?>
            <div class="aktionen"><span><a href="?s=allianz&amp;id=<?= (int)$bw['genossenschaft_id'] ?>"><?= h($bw['name']) ?></a> [<?= h($bw['kuerzel']) ?>] – wartet</span>
              <form method="post"><?= $hidden('gen_zurueckziehen', ['gid' => $bw['genossenschaft_id']]) ?><button class="knopf leicht" type="submit">Zurückziehen</button></form></div>
          <?php endforeach ?>
        </section>
      <?php endif ?>
    </div>
    <section class="karte-box">
      <h2>Allianz gründen</h2>
      <form method="post" class="formular">
        <?= $hidden('gen_gruenden') ?>
        <label for="n-name">Name</label>
        <input id="n-name" name="name" minlength="3" maxlength="40" required placeholder="z. B. Landjugend Herbern">
        <label for="n-kuerzel">Kürzel (2–6 Zeichen)</label>
        <input id="n-kuerzel" name="kuerzel" minlength="2" maxlength="6" required placeholder="LJH" style="text-transform:uppercase">
        <label for="n-besch">Beschreibung (optional)</label>
        <textarea id="n-besch" name="beschreibung" rows="4" maxlength="2000"></textarea>
        <button class="knopf" type="submit">Gründen</button>
      </form>
      <p class="leise">Du wirst Vorstand und nimmst Bewerbungen an. Höchstens <?= gen_max_mitglieder() ?> Mitglieder pro Allianz.</p>
    </section>
  </div>
<?php endif ?>
<?php require __DIR__ . '/fuss.php'; ?>
