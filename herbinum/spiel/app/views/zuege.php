<?php
/** @var array $z @var array $spieler @var array|null $vertretung @var array|null $gen */
$titel = 'Züge';
require __DIR__ . '/kopf.php';
$b = $z['bereich'];
$sid = (int)$spieler['id'];
$alle = db()->query('SELECT b.id, b.nr, b.name, b.x, b.y, b.besitzer_id, b.ort_id, o.name AS ort, s.name AS besitzer, s.genossenschaft_id AS gid, g.kuerzel
                     FROM bereiche b JOIN orte o ON o.id = b.ort_id LEFT JOIN spieler s ON s.id = b.besitzer_id
                     LEFT JOIN genossenschaften g ON g.id = s.genossenschaft_id')->fetchAll();
$zielVorwahl = (string)($_GET['ziel'] ?? '');
$auftragVorwahl = (string)($_GET['auftrag'] ?? 'transport');
$namen = array_column($alle, null, 'id');
$label = fn(string $id) => isset($namen[$id]) ? $namen[$id]['ort'] . ' · ' . $namen[$id]['name'] : $id;
$meineGid = (int)($gen['id'] ?? 0);
// Ziele mit Entfernung und Beziehung (eigen, allianz, fremd, frei); Orte nach Entfernung zum Startbereich
$ziele = [];
$schutzCache = $sperreCache = $raumCache = [];
$blauhelme = [];
foreach (db()->query('SELECT * FROM blauhelme')->fetchAll() as $r) {
    $blauhelme[$r['bereich_id']] = $r;
}
$orte = [];
foreach ($alle as $x) {
    if ($x['id'] === $b['id']) {
        continue;
    }
    $km = strecke_km($b, $x);
    $rel = $x['besitzer_id'] === null ? 'frei' : ((int)$x['besitzer_id'] === $sid ? 'eigen' : ($meineGid && (int)$x['gid'] === $meineGid ? 'allianz' : 'fremd'));
    // Schutzzeit und Erkundungssperre gegenüber fremden Spielern (einmal je Spieler ermittelt)
    if ($rel === 'fremd') {
        $bes = (int)$x['besitzer_id'];
        $schutzCache[$bes] ??= angriffsschutz_bis(db(), $bes, $sid);
        $raumCache[$bes] ??= round(min(VERLUST_MAX_ANGRIFF, verlust_stand(db(), $bes, time())['spielraum']) * 100);
        $sperreCache[$bes] ??= spionage_sperre_bis(db(), $sid, $bes) ?? 0;
    }
    $ziele[] = ['id' => $x['id'], 'ort' => $x['ort_id'], 'nr' => (int)$x['nr'], 'name' => $x['name'], 'km' => round($km, 1), 'rel' => $rel,
                'besitzer' => $x['besitzer'] ? $x['besitzer'] . ($x['kuerzel'] ? " [{$x['kuerzel']}]" : '') : '',
                'schutz' => $rel === 'fremd' && $schutzCache[$bes] ? date('d.m. H:i', $schutzCache[$bes]) : '',
                'spielraum' => $rel === 'fremd' ? (int)$raumCache[$bes] : null,
                'blauhelme' => $rel === 'fremd' && isset($blauhelme[$x['id']]) && (float)$blauhelme[$x['id']]['staerke'] > 0
                    ? (int)round(100 * blauhelme_rest($blauhelme[$x['id']], time()) / (float)$blauhelme[$x['id']]['staerke']) : 0,
                'sperre' => $rel === 'fremd' && $sperreCache[$bes] ? date('d.m. H:i', $sperreCache[$bes]) : ''];
    $o = &$orte[$x['ort_id']];
    $o ??= ['id' => $x['ort_id'], 'name' => $x['ort'], 'km' => INF, 'frei' => 0, 'besetzt' => 0, 'eigen' => 0];
    $o['km'] = min($o['km'], $km);
    $o[$rel === 'frei' ? 'frei' : ($rel === 'eigen' ? 'eigen' : 'besetzt')]++;
    unset($o);
}
usort($ziele, fn($a, $c) => $a['km'] <=> $c['km']);
uasort($orte, fn($a, $c) => $a['km'] <=> $c['km']);
$kmText = fn(float $km) => str_replace('.', ',', (string)round($km, 1)) . ' km';
// Werte für die Live-Vorschau im Browser (gleiche Formeln wie auf dem Server)
$einheitenJs = [];
foreach (bewegliche(array_fill_keys(array_keys(EINHEITEN), 1)) as $typ => $_) {
    $einheitenJs[$typ] = ['kmh' => einheit_kmh($typ, $z['fo']), 'ladung' => EINHEITEN[$typ]['ladung'], 'korn_km' => EINHEITEN[$typ]['korn_km'],
                          'platz' => PLATZBEDARF[$typ] ?? 0, 'angriff' => EINHEITEN[$typ]['angriff'], 'name' => EINHEITEN[$typ]['name']];
}
$mobil = bewegliche($z['einheiten']);
$koord = [];
foreach ($alle as $x) {
    $koord[$x['id']] = [(int)$x['x'], (int)$x['y']];
}
$auftraege = $vertretung ? array_intersect_key(AUFTRAEGE, ['transport' => 1, 'verlegen' => 1]) : AUFTRAEGE;
$eigene = zuege_des_spielers($sid);
$fremde = ankommende_zuege($sid);
$einheitenText = fn(array $e) => implode(', ', array_map(fn($t, $n) => "{$n} × " . EINHEITEN[$t]['name'], array_keys($e), $e));
$gruppen = einsatzgruppen(db(), $sid);
// Was die Zeile eines Raubzugs anzeigt: [Countdown-Text, Abbruch-Knopf oder null]
$phaseZeile = function (array $zg): array {
    if ($zg['status'] !== 'hin') {
        return ['Rückkehr in', null];
    }
    return match ($zg['phase'] ?? '') {
        'ruesten' => ['Ausrücken in', 'Absagen'],
        'marsch', '' => ['Ankunft in', 'Umkehren'],
        'spaehen' => ['Gefecht beginnt in', 'Rückzug'],
        'gefecht' => ['Gefecht endet in', null],
        'pluendern' => ['Plünderung endet in', null],
        default => ['Nächster Schritt in', null],
    };
};
?>
<div class="kopfzeile">
  <div>
    <div class="eyebrow">Start: <?= h($ort['name']) ?> · <?= h($b['name']) ?></div>
    <h1>Züge</h1>
  </div>
</div>

<div class="raster-gebaeude">
  <section class="karte-box">
    <h2>Zug losschicken</h2>
    <?php if (!$mobil): ?>
      <p class="leise">In diesem Bereich stehen keine Einheiten, die losfahren können. Baue sie in der <a href="?s=werkstatt">Fuhrpark</a> oder der <a href="?s=schuetzen">Sicherheitszentrale</a>.</p>
    <?php else: ?>
      <form method="post" class="zugformular" id="zugform"
            data-start="<?= h(json_encode([(int)$b['x'], (int)$b['y']])) ?>" data-koord="<?= h(json_encode($koord)) ?>"
            data-einheiten="<?= h(json_encode($einheitenJs)) ?>" data-tempo="<?= tempo() ?>"
            data-umweg="<?= UMWEG_FAKTOR ?>" data-min="<?= MIN_STRECKE_KM ?>" data-faktor="<?= round(ereignis_zugfaktor($b, time()), 3) ?>"
            data-plaetze="<?= round(mw_plaetze($z['fo']), 2) ?>" data-ruesten="<?= h(json_encode([RUESTEN_MIN, RUESTEN_JE_20_MIN, RUESTEN_MAX_MIN, zeitbonus($z['fo'], 'funktechnik')])) ?>"
            data-spaehen="<?= spaehzeit_s($z['fo'], tempo()) ?>" data-gefecht="<?= h(json_encode([GEFECHT_MIN_MIN, GEFECHT_MAX_MIN])) ?>" data-gruppe-frei="<?= freie_einsatzgruppe(db(), $sid, time()) ? 1 : 0 ?>">
        <?= csrf_feld() ?>
        <input type="hidden" name="a" value="losschicken">
        <input type="hidden" name="bereich" value="<?= h($b['id']) ?>">

        <fieldset>
          <legend>Auftrag</legend>
          <div class="auftraege">
            <?php foreach ($auftraege as $k => $n): ?>
              <label class="auftrag-wahl"><input type="radio" name="auftrag" value="<?= $k ?>"<?= $auftragVorwahl === $k ? ' checked' : '' ?>> <span><?= $n ?></span></label>
            <?php endforeach ?>
          </div>
          <?php if ($vertretung): ?><p class="leise">In Vertretung gehen Züge nur zwischen den Bereichen von <?= h($spieler['name']) ?>.</p><?php endif ?>
        </fieldset>

        <fieldset class="zielwahl" id="zielwahl" data-ziele="<?= h(json_encode($ziele, JSON_UNESCAPED_UNICODE)) ?>" data-vorwahl="<?= h($zielVorwahl) ?>">
          <legend>Ziel</legend>
          <div class="ziel-schritte" hidden>
            <label for="zielort"><span class="schritt">1</span> Ort <small>(nach Entfernung)</small></label>
            <select id="zielort">
              <option value="*">★ Eigene Bereiche und Allianz</option>
              <?php foreach ($orte as $o): ?>
                <option value="<?= h($o['id']) ?>"><?= h($o['name']) ?> · <?= $kmText($o['km']) ?> · <?= $o['frei'] ?> frei<?= $o['besetzt'] ? ' · ' . $o['besetzt'] . ' besiedelt' : '' ?><?= $o['eigen'] ? ' · ' . $o['eigen'] . ' eigene' : '' ?></option>
              <?php endforeach ?>
            </select>
            <div class="zielfilter" role="group" aria-label="Bereiche filtern">
              <?php foreach (['alle' => 'Alle', 'frei' => 'Frei', 'fremd' => 'Andere Spieler', 'eigen' => 'Eigene + Allianz'] as $k => $n): ?>
                <button type="button" class="filterknopf" data-filter="<?= $k ?>" aria-pressed="<?= $k === 'alle' ? 'true' : 'false' ?>"><?= $n ?></button>
              <?php endforeach ?>
            </div>
            <label for="ziel"><span class="schritt">2</span> Bereich</label>
          </div>
          <select id="ziel" name="ziel" required>
            <option value="">– Bereich wählen –</option>
            <?php foreach ($orte as $o): ?>
              <optgroup label="<?= h($o['name']) ?> (<?= $kmText($o['km']) ?>)">
                <?php foreach ($ziele as $x): if ($x['ort'] !== $o['id']) continue; ?>
                  <option value="<?= h($x['id']) ?>"<?= $zielVorwahl === $x['id'] ? ' selected' : '' ?>><?= $x['nr'] ?> · <?= h($x['name']) ?> – <?= $x['besitzer'] !== '' ? h($x['besitzer']) . ($x['rel'] === 'eigen' ? ' (du)' : '') : 'frei' ?> · <?= $kmText($x['km']) ?></option>
                <?php endforeach ?>
              </optgroup>
            <?php endforeach ?>
          </select>
          <div class="zielinfo" id="zielinfo" aria-live="polite"></div>
        </fieldset>

        <fieldset>
          <legend>Einheiten</legend>
          <div class="eingaben">
            <?php foreach ($mobil as $typ => $n): ?>
              <label><span><?= h(EINHEITEN[$typ]['name']) ?> <small>(<?= $n ?> da)</small></span>
                <input type="number" name="e[<?= $typ ?>]" min="0" max="<?= $n ?>" value="0" data-einheit="<?= $typ ?>" inputmode="numeric"></label>
            <?php endforeach ?>
          </div>
        </fieldset>

        <fieldset>
          <legend>Ladung</legend>
          <div class="eingaben">
            <?php foreach (RESSOURCEN as $r => $n): ?>
              <label><span><?= $n ?> <small>(<?= zahl($b[$r]) ?>)</small></span>
                <input type="number" name="l[<?= $r ?>]" min="0" value="0" data-ladung="<?= $r ?>" inputmode="numeric"></label>
            <?php endforeach ?>
          </div>
        </fieldset>

        <div class="vorschau" id="vorschau" aria-live="polite">Ziel und Einheiten wählen.</div>
        <button class="knopf" type="submit">Losschicken</button>
      </form>
    <?php endif ?>
  </section>

  <div class="spalte">
    <section class="karte-box">
      <h2>Deine Züge</h2>
      <?php if (!$eigene): ?>
        <p class="leise">Keine Züge unterwegs.</p>
      <?php else: ?>
        <ol class="queue">
          <?php foreach ($eigene as $zg):
              $hin = $zg['status'] === 'hin';
              $ende = $hin ? (int)$zg['ankunft_ts'] : (int)$zg['rueckkehr_ts'];
              [$wann, $knopf] = $zg['auftrag'] === 'angriff' ? $phaseZeile($zg) : [$hin ? 'Ankunft in' : 'Rückkehr in', $hin ? 'Umkehren' : null]; ?>
            <li><div>
              <b><?= AUFTRAEGE[$zg['auftrag']] ?></b> <?= $hin ? 'nach' : 'zurück von' ?> <?= h($label($zg['ziel_id'])) ?>
              <?php if ($zg['auftrag'] === 'angriff' && ($zg['phase'] ?? '') !== ''): ?>
                <ol class="phasen" aria-label="Phasen des Raubzugs">
                  <?php $aktiv = $zg['status'] === 'hin' ? $zg['phase'] : 'rueckmarsch'; $vorbei = true;
                  foreach (PHASEN as $k => $n): $istAktiv = $k === $aktiv; if ($istAktiv) $vorbei = false; ?>
                    <li class="<?= $istAktiv ? 'aktiv' : ($vorbei ? 'vorbei' : '') ?>"<?= $istAktiv ? ' aria-current="step"' : '' ?>><?= h($n) ?></li>
                  <?php endforeach ?>
                  <li>Regeneration</li>
                </ol>
              <?php endif ?>
              <div class="leise"><?= h($einheitenText(zug_einheiten($zg))) ?><?= ($zg['phase'] ?? '') === 'ruesten' ? ' – noch im Bereich, verteidigen mit' : '' ?></div>
              <div class="leise"><?= $wann ?> <span class="countdown" data-ende="<?= $ende ?>"><?= dauer($ende - time()) ?></span></div>
            </div>
            <?php if ($knopf): ?>
              <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="umkehren"><input type="hidden" name="zug" value="<?= (int)$zg['id'] ?>"><button class="knopf leicht" type="submit"><?= $knopf ?></button></form>
            <?php endif ?>
            </li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </section>
    <?php if ($fremde): ?>
      <section class="karte-box">
        <h2>Unterwegs zu dir</h2>
        <ol class="queue">
          <?php foreach ($fremde as $zg): ?>
            <li><div>
              <b><?= h($zg['absender']) ?></b>: <?= $zg['auftrag'] === 'angriff' ? '<span class="gefahr">Raubzug</span> (' . h(phase_text($zg)) . ')' : AUFTRAEGE[$zg['auftrag']] ?> nach <?= h($label($zg['ziel_id'])) ?>
              <div class="leise"><?= $zg['auftrag'] === 'angriff' ? $phaseZeile($zg)[0] : 'Ankunft in' ?> <span class="countdown" data-ende="<?= (int)$zg['ankunft_ts'] ?>"><?= dauer($zg['ankunft_ts'] - time()) ?></span></div>
            </div></li>
          <?php endforeach ?>
        </ol>
      </section>
    <?php endif ?>
    <section class="karte-box">
      <h2>Einsatzgruppen</h2>
      <ul class="liste-leise">
        <?php foreach ($gruppen as $g => $x): ?>
          <li><b>Gruppe <?= $g ?>:</b>
            <?php if ($x['zug_id'] !== null): ?>im Einsatz
            <?php elseif (!$x['frei']): ?>Regeneration bis <?= date('d.m. H:i', $x['bereit_ab']) ?> Uhr (<span class="countdown" data-ende="<?= $x['bereit_ab'] ?>"><?= dauer($x['bereit_ab'] - time()) ?></span>)
            <?php else: ?>einsatzbereit<?php endif ?></li>
        <?php endforeach ?>
      </ul>
      <p class="leise">Jeder Raubzug belegt eine Gruppe vom Rüsten bis zum Ende der Regeneration. Mehr Gruppen gleichzeitig erlaubt die
        <a href="?s=gebaeude#g-kommandozentrale">Kommandozentrale</a> (bis <?= 1 + KOMMANDO_MAX_STUFE ?>).</p>
    </section>
    <section class="karte-box">
      <h2>Einsatzregeln</h2>
      <ul class="regeln-fair">
        <li><b>Ablauf:</b> Rüsten (<?= round(RUESTEN_MIN / tempo()) ?> Min.+, absagen kostenlos) → Anmarsch (die langsamste Einheit bestimmt das Tempo – zu Fuß <?= EINHEITEN['schuetze']['tempo'] ?> km/h, im Mannschaftstransporter schneller) → Spähen (<?= round(SPAEHEN_MIN / tempo()) ?> Min., Rückzug möglich, der Verteidiger wird gewarnt) → Gefecht (<?= round(GEFECHT_MIN_MIN / tempo()) ?>–<?= round(GEFECHT_MAX_MIN / tempo()) ?> Min., je überlegener, desto kürzer, kein Zurück) → Plündern → Rückmarsch.</li>
        <li><b>Regeneration:</b> Danach werden Fahrzeuge gewartet, getankt und munitioniert: <?= round(REGENERATION_H / tempo(), 1) ?> h plus etwas je gefallener Einheit, kostet <?= round(WARTUNG_ANTEIL * 100) ?> % des Einheitenwerts. Verteidigen können die Einheiten sofort wieder.</li>
        <li><b>Blauhelme:</b> Wer einen Raubzug verliert, bekommt eine neutrale Schutztruppe mit <?= round(BLAUHELM_ANTEIL * 100) ?> % der Kampfkraft des Siegers, die in <?= round(BLAUHELM_H / tempo(), 1) ?> h abnimmt. Angreifen bleibt möglich, wird aber teuer.</li>
        <li><b>Verlustgrenze:</b> Die Beute wird so gekürzt, dass ein Angriff samt Kampfverlusten höchstens <?= round(VERLUST_MAX_ANGRIFF * 100) ?> % des Vermögens kostet und alle Angriffe in <?= round(SCHUTZ_FENSTER_H / tempo()) ?> h zusammen höchstens <?= round(VERLUST_MAX_TAG * 100) ?> %. Danach gibt es keine Beute mehr, und die Blauhelme stehen in voller Stärke bereit. Gefallene Einheiten bleiben verloren.</li>
        <li><b>Rückschlag:</b> Wer selbst angreift, darf vom Ziel einmal zurückbekommen – gegen ihn greifen keine Blauhelme ein.</li>
        <li><b>Tresor:</b> <?= round(TRESOR_BASIS * 100) ?> % des Lagers sind nie erbeutbar, mit Sicherheitstechnik bis <?= round(TRESOR_MAX * 100) ?> %.</li>
        <li><b>Stärke:</b> Gegen Spieler unter <?= round(STAERKE_SPERRE * 100) ?> % deiner Punkte gibt es keine Raubzüge, bis <?= round(STAERKE_VOLL * 100) ?> % weniger Beute – außer sie haben dich zuerst angegriffen.</li>
        <li><b>Erkundung:</b> kostet Einsatzgeld, liefert Schätzungen, Kuriere können abgefangen werden; denselben Spieler nur alle <?= round(SPIONAGE_SPERRE_H / tempo(), 1) ?> h.</li>
      </ul>
    </section>
    <section class="karte-box">
      <h2>Bereiche</h2>
      <p class="leise">Du hast <?= anzahl_bereiche(db(), $sid) ?> von <?= max_bereiche($z['fo']) ?> erlaubten Bereichen (inklusive Siedlerkonvois unterwegs). Mehr erlaubt die Forschung <a href="?s=forschung">Verwaltung</a>.</p>
    </section>
  </div>
</div>
<?php require __DIR__ . '/fuss.php'; ?>
