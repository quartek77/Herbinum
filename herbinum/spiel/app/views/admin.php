<?php
/** @var array $spieler @var int $lautstaerke */
$titel = 'Spielleitung';
require __DIR__ . '/kopf.php';
$stat = admin_statistik();
$liste = musik_liste();
$alle = spielerliste();
$neuesPw = $_SESSION['neues_passwort'] ?? null;
unset($_SESSION['neues_passwort']);
$grenze = min(musik_max_bytes(), php_upload_grenze());
$wann = fn(int $ts) => $ts ? date('d.m.y H:i', $ts) : '–';
?>
<div class="kopfzeile">
  <div><div class="eyebrow">Nur für Admins sichtbar</div><h1>Spielleitung</h1></div>
</div>

<div class="kennzahlen">
  <div><b><?= $stat['spieler'] ?></b><span>von <?= (int)(config()['spiel']['max_spieler'] ?? 40) ?> Spielern</span></div>
  <div><b><?= $stat['aktiv'] ?></b><span>in den letzten 24 h aktiv</span></div>
  <div><b><?= $stat['bereiche'] ?></b><span>von <?= $stat['bereiche_gesamt'] ?> Bereichen besiedelt</span></div>
  <div><b><?= $stat['zuege'] ?></b><span>Züge unterwegs</span></div>
  <div><b>×<?= str_replace('.', ',', (string)tempo()) ?></b><span>Spieltempo (config.php)</span></div>
</div>

<?php if ($neuesPw): ?>
  <section class="karte-box hinweis" role="status">
    <h2>Neues Passwort für <?= h($neuesPw['name']) ?></h2>
    <p><code class="passwort"><?= h($neuesPw['passwort']) ?></code></p>
    <p class="leise">Wird nur jetzt angezeigt. Gib es dem Spieler persönlich weiter.</p>
  </section>
<?php endif ?>

<div class="raster-admin">
  <section class="karte-box">
    <h2>Hintergrundmusik</h2>
    <?php $aktiv = array_values(array_filter($liste, fn($m) => (int)$m['aktiv'])); ?>
    <?php if ($aktiv): ?>
      <p>Läuft gerade für alle: <b><?= h($aktiv[0]['titel']) ?></b></p>
      <audio controls preload="none" src="uploads/musik/<?= h($aktiv[0]['datei']) ?>"></audio>
    <?php else: ?>
      <p class="leise">Zurzeit läuft keine Musik.</p>
    <?php endif ?>

    <?php if ($liste): ?>
      <table class="tabelle">
        <tr><th>Loop</th><th>Größe</th><th>Hochgeladen</th><th></th></tr>
        <?php foreach ($liste as $m): ?>
          <tr>
            <td><?= h($m['titel']) ?><?= (int)$m['aktiv'] ? ' <span class="chip ok">läuft</span>' : '' ?></td>
            <td class="n"><?= groesse((int)$m['groesse']) ?></td>
            <td class="n"><?= $wann((int)$m['hochgeladen_ts']) ?></td>
            <td class="knoepfe">
              <?php if (!(int)$m['aktiv']): ?>
                <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_musik_aktiv"><input type="hidden" name="musik" value="<?= (int)$m['id'] ?>"><button class="knopf leicht" type="submit">Abspielen</button></form>
              <?php endif ?>
              <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_musik_loeschen"><input type="hidden" name="musik" value="<?= (int)$m['id'] ?>"><button class="knopf leicht gefahr-knopf" type="submit">Löschen</button></form>
            </td>
          </tr>
        <?php endforeach ?>
      </table>
      <?php if ($aktiv): ?>
        <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_musik_aktiv"><input type="hidden" name="musik" value="0"><button class="knopf leicht" type="submit">Musik für alle ausschalten</button></form>
      <?php endif ?>
    <?php endif ?>

    <form method="post" enctype="multipart/form-data" class="formular">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="admin_musik_hochladen">
      <label for="m-datei">Neuer Loop (<?= implode(', ', MUSIK_FORMATE) ?>, höchstens <?= groesse($grenze) ?>)</label>
      <input id="m-datei" name="datei" type="file" accept=".mp3,.ogg,.m4a,.wav,audio/*" required>
      <label for="m-titel">Titel (optional)</label>
      <input id="m-titel" name="titel" maxlength="120" placeholder="z. B. Night Drive">
      <button class="knopf" type="submit">Hochladen</button>
      <p class="leise">Der erste Loop startet automatisch. Für ganz lückenlose Übergänge eignet sich OGG am besten – MP3-Dateien haben oft eine winzige Stille am Anfang.
        <?php if (php_upload_grenze() < musik_max_bytes()): ?>Der Server nimmt derzeit nur <?= groesse(php_upload_grenze()) ?> an (upload_max_filesize / post_max_size in den PHP-Einstellungen im KAS).<?php endif ?></p>
    </form>

    <form method="post" class="formular lautstaerke-form">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="admin_lautstaerke">
      <label for="m-vol">Standardlautstärke für neue Spieler: <output for="m-vol" id="m-vol-out"><?= $lautstaerke ?> %</output></label>
      <input id="m-vol" name="wert" type="range" min="0" max="100" step="5" value="<?= $lautstaerke ?>" oninput="document.getElementById('m-vol-out').textContent = this.value + ' %'">
      <button class="knopf leicht" type="submit">Speichern</button>
    </form>
  </section>

  <section class="karte-box">
    <h2>Rundschreiben</h2>
    <form method="post" class="formular">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="admin_rundschreiben">
      <label for="r-titel">Betreff</label>
      <input id="r-titel" name="titel" maxlength="160" required placeholder="z. B. Sicherheitskräftefest am Wochenende: doppelte Produktion">
      <label for="r-text">Text</label>
      <textarea id="r-text" name="text" rows="5" maxlength="2000" required></textarea>
      <button class="knopf" type="submit">An alle <?= $stat['spieler'] ?> Spieler schicken</button>
    </form>
  </section>
</div>

<?php
$geplant = array_values(array_filter(alle_ereignisse(), fn($e) => (int)$e['ende_ts'] > time()));
$alleOrte = db()->query('SELECT id, name FROM orte ORDER BY name')->fetchAll();
$kiFehler = einstellung('zeitung_ki_fehler', '');
?>
<div class="raster-admin">
  <section class="karte-box">
    <h2>Zufallsereignisse</h2>
    <p class="leise">Sturm, Frost, Hochwasser, Hagel, Krisen, Zombies und Erdbeben werden <?= EREIGNIS_TAGE_VORAUS ?> Spieltage im Voraus geplant, damit die Zeitung sie (unsicher) vorhersagen kann.
      Häufigkeit in config.php: <code>ereignis_haeufigkeit</code> = <?= h((string)(config()['spiel']['ereignis_haeufigkeit'] ?? 1.0)) ?>.</p>
    <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_ereignisse_schalter"><input type="hidden" name="aus" value="<?= ereignisse_an() ? 1 : 0 ?>">
      <button class="knopf leicht" type="submit"><?= ereignisse_an() ? 'Zufallsereignisse ausschalten' : 'Zufallsereignisse einschalten' ?></button></form>
    <?php if ($geplant): ?>
      <table class="tabelle">
        <tr><th>Ereignis</th><th>Beginn</th><th>Ende</th><th>Stärke</th><th></th></tr>
        <?php foreach ($geplant as $e): ?>
          <tr><td><?= EREIGNISSE[$e['typ']]['icon'] ?? '' ?> <?= h(EREIGNISSE[$e['typ']]['name'] ?? $e['typ']) ?><div class="leise">rund um <?= h(lage_von($e)['ort']) ?>, <?= round((int)$e['radius'] / 1000) ?> km</div></td>
            <td class="n"><?= date('d.m. H:i', (int)$e['start_ts']) ?></td><td class="n"><?= date('d.m. H:i', (int)$e['ende_ts']) ?></td>
            <td class="n"><?= number_format((float)$e['staerke'], 2, ',', '') ?></td>
            <td><?php if ((int)$e['start_ts'] > time()): ?><form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_ereignis_loeschen"><input type="hidden" name="ereignis" value="<?= (int)$e['id'] ?>"><button class="knopf leicht" type="submit">Streichen</button></form><?php else: ?><span class="chip gefahr">läuft</span><?php endif ?></td></tr>
        <?php endforeach ?>
      </table>
    <?php else: ?>
      <p class="leise">Nichts geplant.</p>
    <?php endif ?>
    <form method="post" class="formular">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="admin_ereignis">
      <label for="e-typ">Ereignis jetzt auslösen</label>
      <span class="zeilenform">
        <select id="e-typ" name="typ"><?php foreach (EREIGNISSE as $k => $e): ?><option value="<?= $k ?>"><?= $e['icon'] ?> <?= h($e['name']) ?></option><?php endforeach ?></select>
        <select name="ort" aria-label="Mittelpunkt"><?php foreach ($alleOrte as $o): ?><option value="<?= h($o['id']) ?>"<?= $o['id'] === 'herbern' ? ' selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach ?></select>
        <select name="staerke" aria-label="Stärke"><option value="0.35">leicht</option><option value="0.6" selected>mäßig</option><option value="0.9">schwer</option></select>
      </span>
      <button class="knopf leicht" type="submit">Auslösen</button>
      <p class="leise">Wirkt sofort auf alle besiedelten Bereiche in der Nähe, mit Nachricht an die Betroffenen.</p>
    </form>
  </section>

  <section class="karte-box">
    <h2>KI: News und Berater</h2>
    <?php $kiStat = ki_statistik(); $budget = (float)ki_einstellung('ki_budget_monat'); ?>
    <?php if (ki_konfiguriert()): ?>
      <p><span class="chip ok">API-Schlüssel vorhanden</span> <?= ki_budget_erreicht() ? '<span class="chip gefahr">Monatsbudget erreicht – KI pausiert</span>' : '' ?></p>
    <?php else: ?>
      <p><span class="chip">kein Schlüssel</span> Ohne Schlüssel erscheinen die News als Kurzmeldungen, und den Berater gibt es nicht.</p>
      <p class="leise">Schlüssel in config.php eintragen: <code>'ki' => ['api_key' => 'sk-ant-…']</code> (unter console.anthropic.com → API Keys).
        <?= function_exists('curl_init') ? '' : 'Achtung: PHP hat hier kein curl – das braucht die KI.' ?></p>
    <?php endif ?>
    <div class="kennzahlen">
      <div><b><?= number_format($kiStat['kosten'], 2, ',', '.') ?> $</b><span>Kosten diesen Monat<?= $budget > 0 ? ' von ' . number_format($budget, 2, ',', '.') . ' $' : '' ?></span></div>
      <div><b><?= $kiStat['zeitung'] ?></b><span>News-Ausgaben (<?= number_format($kiStat['kosten_zeitung'], 2, ',', '.') ?> $)</span></div>
      <div><b><?= $kiStat['berater'] ?></b><span>Berater-Fragen (<?= number_format($kiStat['kosten_berater'], 2, ',', '.') ?> $)</span></div>
    </div>
    <form method="post" class="formular">
      <?= csrf_feld() ?>
      <input type="hidden" name="a" value="admin_ki">
      <div class="ki-einstellungen">
        <fieldset><legend>Herbinum News</legend>
          <label class="haken"><input type="checkbox" name="ki_zeitung_an" value="1"<?= ki_einstellung('ki_zeitung_an') === '1' ? ' checked' : '' ?>> Von der KI schreiben lassen</label>
          <label for="k-zm">Modell</label>
          <select id="k-zm" name="ki_zeitung_modell"><?php foreach (KI_MODELLE as $m => $x): ?><option value="<?= $m ?>"<?= ki_modell('zeitung') === $m ? ' selected' : '' ?>><?= h($x['name']) ?></option><?php endforeach ?></select>
          <label for="k-ton">Schreibstil</label>
          <select id="k-ton" name="ki_zeitung_ton"><?php foreach (KI_TOENE as $t => $x): ?><option value="<?= $t ?>"<?= ki_einstellung('ki_zeitung_ton') === $t ? ' selected' : '' ?>><?= h(ucfirst($x)) ?></option><?php endforeach ?></select>
        </fieldset>
        <fieldset><legend>KI-Berater für Spieler</legend>
          <label class="haken"><input type="checkbox" name="ki_berater_an" value="1"<?= ki_einstellung('ki_berater_an') === '1' ? ' checked' : '' ?>> Berater anbieten</label>
          <label for="k-bm">Modell</label>
          <select id="k-bm" name="ki_berater_modell"><?php foreach (KI_MODELLE as $m => $x): ?><option value="<?= $m ?>"<?= ki_modell('berater') === $m ? ' selected' : '' ?>><?= h($x['name']) ?></option><?php endforeach ?></select>
          <label for="k-lim">Fragen pro Spieler und Tag</label>
          <input id="k-lim" name="ki_berater_limit" type="number" min="0" max="200" value="<?= (int)ki_einstellung('ki_berater_limit') ?>">
        </fieldset>
        <fieldset><legend>Kosten im Griff</legend>
          <label for="k-bud">Monatsbudget in US-Dollar (0 = ohne Grenze)</label>
          <input id="k-bud" name="ki_budget_monat" type="number" min="0" max="1000" step="0.5" value="<?= h(ki_einstellung('ki_budget_monat')) ?>">
          <p class="leise">Ist das Budget erreicht, pausieren News und Berater bis zum Monatsersten. Richtwerte mit Haiku: eine News-Ausgabe etwa 0,1 Cent, eine Berater-Frage etwa 0,2 Cent.</p>
        </fieldset>
      </div>
      <button class="knopf" type="submit">Einstellungen speichern</button>
    </form>
    <?php if (ki_konfiguriert()): ?>
      <?php if ($kiFehler !== ''): ?><p class="meldung fehler">Letzter News-Fehler: <?= h($kiFehler) ?></p><?php endif ?>
      <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_zeitung"><button class="knopf leicht" type="submit">Heutige News neu schreiben lassen</button></form>
    <?php endif ?>
  </section>
</div>

<section class="karte-box">
  <h2>NPC-Mitspieler</h2>
  <p class="leise">Computergesteuerte Mitspieler besiedeln freie Bereiche und spielen defensiv: Sie bauen, forschen, verteidigen und handeln,
    greifen aber nie von sich aus an – höchstens einmal zurück. Ihre Stärke folgt 70–90 % des Mittelfelds der aktiven Spieler.
    Für offensive Spieler sind sie faire Ziele. Sie zählen nicht zur Spielerzahl und können sich nicht anmelden.</p>
  <form method="post" class="admin-ki">
    <?= csrf_feld() ?><input type="hidden" name="a" value="admin_npc">
    <label for="npc-anz">Anzahl (0–<?= NPC_MAX ?>)</label>
    <input id="npc-anz" name="anzahl" type="number" min="0" max="<?= NPC_MAX ?>" value="<?= npc_soll() ?>">
    <label class="haken"><input type="checkbox" name="ki_namen" value="1"<?= einstellung('npc_ki_namen', '0') === '1' ? ' checked' : '' ?>> Namen von der KI erfinden lassen (eine kurze Haiku-Anfrage je neuem NPC)</label>
    <button class="knopf" type="submit">Speichern</button>
  </form>
  <?php if ($npcs = npc_liste()): ?>
    <div class="tabelle-rahmen">
    <table class="tabelle">
      <tr><th>Name</th><th>Typ</th><th>Bereich</th><th>Punkte / Ziel</th><th>Wachstum</th></tr>
      <?php foreach ($npcs as $n): ?>
        <tr><td><?= h($n['name']) ?> 🤖</td><td><?= h(NPC_TYPEN[$n['typ']]['name'] ?? $n['typ']) ?></td><td><?= h(bereich_name(db(), (string)$n['bereich_id'])) ?></td>
            <td><?= zahl($n['punkte']) ?> / <?= zahl($n['ziel']) ?></td><td>× <?= str_replace('.', ',', (string)round((float)$n['prod_faktor'], 2)) ?></td></tr>
      <?php endforeach ?>
    </table>
    </div>
  <?php endif ?>
</section>

<section class="karte-box">
  <h2>Spieler</h2>
  <div class="tabelle-rahmen">
  <table class="tabelle spielertabelle">
    <tr><th>Name</th><th>Bereiche</th><th>Registriert</th><th>Zuletzt da</th><th>Verwalten</th></tr>
    <?php foreach ($alle as $s):
        $selbst = (int)$s['id'] === (int)$spieler['id']; ?>
      <tr class="<?= (int)$s['gesperrt'] ? 'gesperrt-zeile' : '' ?>">
        <td>
          <?= spieler_html($s) ?>
          <?= (int)$s['ist_admin'] ? '<span class="chip">Admin</span>' : '' ?>
          <?= (int)$s['gesperrt'] ? '<span class="chip gefahr">gesperrt</span>' : '' ?>
          <?php if ((int)$s['gesperrt'] && $s['sperrgrund'] !== ''): ?><div class="leise"><?= h($s['sperrgrund']) ?></div><?php endif ?>
        </td>
        <td><?= (int)$s['bereiche'] ?><div class="leise"><?= h((string)$s['orte']) ?></div></td>
        <td class="n"><?= $wann((int)$s['erstellt_ts']) ?></td>
        <td class="n"><?= $wann((int)$s['zuletzt_ts']) ?></td>
        <td>
          <?php if ($selbst): ?>
            <span class="leise">das bist du</span>
          <?php else: ?>
            <details class="verwalten">
              <summary>Aktionen</summary>
              <div class="aktionsliste">
                <?php if ((int)$s['gesperrt']): ?>
                  <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_entsperren"><input type="hidden" name="spieler" value="<?= (int)$s['id'] ?>"><button class="knopf leicht" type="submit">Sperre aufheben</button></form>
                <?php else: ?>
                  <form method="post" class="zeilenform"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_sperren"><input type="hidden" name="spieler" value="<?= (int)$s['id'] ?>">
                    <label class="sr" for="g-<?= (int)$s['id'] ?>">Grund</label><input id="g-<?= (int)$s['id'] ?>" name="grund" maxlength="200" placeholder="Grund (optional)">
                    <button class="knopf leicht" type="submit">Sperren</button></form>
                <?php endif ?>
                <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_recht"><input type="hidden" name="spieler" value="<?= (int)$s['id'] ?>"><input type="hidden" name="an" value="<?= (int)$s['ist_admin'] ? 0 : 1 ?>"><button class="knopf leicht" type="submit"><?= (int)$s['ist_admin'] ? 'Admin-Recht entziehen' : 'Zum Admin machen' ?></button></form>
                <form method="post"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_passwort"><input type="hidden" name="spieler" value="<?= (int)$s['id'] ?>"><button class="knopf leicht" type="submit">Neues Passwort erzeugen</button></form>
                <form method="post" class="zeilenform"><?= csrf_feld() ?><input type="hidden" name="a" value="admin_loeschen"><input type="hidden" name="spieler" value="<?= (int)$s['id'] ?>">
                  <label class="sr" for="l-<?= (int)$s['id'] ?>">Zum Löschen den Namen eintippen</label><input id="l-<?= (int)$s['id'] ?>" name="bestaetigung" placeholder="Name zum Bestätigen" autocomplete="off">
                  <button class="knopf leicht gefahr-knopf" type="submit">Löschen</button></form>
                <p class="leise">Löschen gibt alle Bereiche frei und entfernt Gebäude, Einheiten, Forschung und Nachrichten. Das lässt sich nicht rückgängig machen.</p>
              </div>
            </details>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
  </table>
  </div>
</section>
<?php require __DIR__ . '/fuss.php'; ?>
