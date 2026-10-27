<?php
/** @var array $orte @var int $anzahl @var array $form @var bool $neu */
$m = meldung_holen();
$max = (int)(config()['spiel']['max_spieler'] ?? 40);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Herbinum</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Zilla+Slab:wght@500;700&family=Source+Sans+3:wght@400;600&family=IBM+Plex+Mono:wght@500&display=swap">
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body class="startseite">
<main class="start">
  <section class="start-text">
    <div class="eyebrow">Ein Aufbauspiel im südlichen Münsterland</div>
    <h1 class="start-titel">Herbinum</h1>
    <p>21 echte Orte rund um Herbern, jeder mit Vierteln und Bauerschaften, die du besiedeln kannst. Fang mit einer Hofstelle an, bau Holzplatz, Ziegelei, Acker und Windräder – und sieh zu, wie dein Bereich Stück für Stück wächst.</p>
    <p class="leise"><?= $anzahl ?> von <?= $max ?> Plätzen vergeben.</p>
  </section>

  <section class="start-formulare">
    <?php if ($m): ?><div class="meldung <?= h($m[0]) ?>" role="alert"><?= h($m[1]) ?></div><?php endif ?>
    <div class="tabs" role="tablist">
      <a role="tab" href="?s=start" aria-selected="<?= $neu ? 'false' : 'true' ?>">Anmelden</a>
      <a role="tab" href="?s=start&amp;neu=1" aria-selected="<?= $neu ? 'true' : 'false' ?>">Neu mitspielen</a>
    </div>
    <?php if (!$neu): ?>
      <form method="post" class="formular">
        <?= csrf_feld() ?>
        <input type="hidden" name="a" value="anmelden">
        <label for="l-name">Spielername</label>
        <input id="l-name" name="name" autocomplete="username" required>
        <label for="l-pass">Passwort</label>
        <input id="l-pass" name="pass" type="password" autocomplete="current-password" required>
        <button class="knopf" type="submit">Anmelden</button>
      </form>
    <?php elseif ($anzahl >= $max): ?>
      <p>Alle <?= $max ?> Plätze sind vergeben.</p>
    <?php else: ?>
      <form method="post" class="formular">
        <?= csrf_feld() ?>
        <input type="hidden" name="a" value="registrieren">
        <label for="r-name">Spielername</label>
        <input id="r-name" name="name" value="<?= h($form['name'] ?? '') ?>" minlength="3" maxlength="24" autocomplete="username" required>
        <label for="r-pass">Passwort (mindestens 8 Zeichen)</label>
        <input id="r-pass" name="pass" type="password" minlength="8" autocomplete="new-password" required>
        <label for="r-pass2">Passwort wiederholen</label>
        <input id="r-pass2" name="pass2" type="password" minlength="8" autocomplete="new-password" required>
        <label for="r-ort">Wo willst du anfangen?</label>
        <select id="r-ort" name="ort" required>
          <?php foreach ($orte as $o): ?>
            <option value="<?= h($o['id']) ?>"<?= ($form['ort'] ?? 'herbern') === $o['id'] ? ' selected' : '' ?><?= (int)$o['frei'] === 0 ? ' disabled' : '' ?>>
              <?= h($o['name']) ?> (<?= (int)$o['frei'] ?> frei)
            </option>
          <?php endforeach ?>
        </select>
        <p class="leise">Du bekommst einen zufälligen freien Bereich in diesem Ort. Dorfkerne und Altstädte sind nicht als Start möglich – die kannst du später besiedeln.</p>
        <button class="knopf" type="submit">Hofstelle gründen</button>
      </form>
    <?php endif ?>
  </section>
</main>
<footer class="fuss">Kartendaten © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap-Mitwirkende</a> (ODbL)</footer>
</body>
</html>
