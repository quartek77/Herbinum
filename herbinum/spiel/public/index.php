<?php
// Einstiegspunkt: jede Seite und jede Aktion läuft hier durch (?s=seite, POST a=aktion, ?api=… für JSON).
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

if (installierte_version() < SCHEMA_VERSION) {
    http_response_code(503);
    exit(installierte_version() === 0
        ? 'Herbinum ist noch nicht installiert. Rufe install.php auf (siehe README).'
        : 'Herbinum wird gerade aktualisiert. Admin: bitte install.php?key=… aufrufen (siehe README).');
}

sitzung_starten();
// Fällige Ankünfte, Rückkehrer, Forschungen und Ereignisse erledigen, bevor irgendetwas angezeigt wird
ereignisse_abarbeiten(time());
$echt = aktueller_spieler();
$spieler = $echt;

// ---------- Vertretung: ein Allianzmitglied handelt im Zeitfenster für einen anderen Spieler ----------
$vertretung = null;
if ($echt && !empty($_SESSION['vertritt'])) {
    $vertretung = vertretung_ziel((int)$echt['id'], (int)$_SESSION['vertritt']);
    if ($vertretung) {
        $spieler = $vertretung['spieler'];
    } else {
        unset($_SESSION['vertritt'], $_SESSION['v_bereich']);
        meldung('Die Vertretung ist beendet – das Zeitfenster ist abgelaufen oder ihr seid nicht mehr in derselben Allianz.', 'fehler');
    }
}
$seite = (string)($_GET['s'] ?? ($spieler ? 'uebersicht' : 'start'));
$seite = $seite === 'genossenschaft' ? 'allianz' : $seite;

function json_aus(array $daten, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode($daten, JSON_UNESCAPED_UNICODE));
}

// ---------- JSON-Schnittstellen ----------
$api = (string)($_GET['api'] ?? '');
if ($api !== '') {
    if (!$echt) {
        json_aus(['fehler' => 'Nicht angemeldet.'], 401);
    }
    $ich = (int)$echt['id'];
    if ($api === 'besitz') {
        $rows = db()->query('SELECT b.id, s.name, s.farbe, s.id AS sid, s.zuletzt_ts, s.genossenschaft_id AS gid, g.kuerzel, g.name AS gname, g.wappen
                             FROM bereiche b JOIN spieler s ON s.id = b.besitzer_id LEFT JOIN genossenschaften g ON g.id = s.genossenschaft_id')->fetchAll();
        $meineGen = gen_von((int)$spieler['id'])['id'] ?? null;
        $out = [];
        foreach ($rows as $r) {
            $out[$r['id']] = ['name' => $r['name'], 'farbe' => $r['farbe'], 'eigen' => (int)$r['sid'] === (int)$spieler['id'],
                              'gen' => $r['kuerzel'], 'verbuendet' => $meineGen !== null && (int)$r['gid'] === (int)$meineGen,
                              'online' => time() - (int)$r['zuletzt_ts'] <= ONLINE_S, 'gid' => (int)$r['gid'], 'gname' => $r['gname'], 'wappen' => $r['wappen']];
        }
        json_aus($out);
    }
    if ($api === 'chat') {
        $kanal = (string)($_GET['kanal'] ?? 'alle');
        $kanal = isset(CHAT_KANAELE[$kanal]) ? $kanal : 'alle';
        $liste = chat_laden($ich, $kanal, (int)($_GET['partner'] ?? 0), (int)($_GET['seit'] ?? 0));
        if ($liste) {
            chat_gesehen_setzen($ich, $kanal, (int)end($liste)['id']);
        }
        json_aus(['nachrichten' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'farbe' => $c['farbe'], 'ich' => (int)$c['von_id'] === $ich,
                                                        'online' => time() - (int)$c['zuletzt_ts'] <= ONLINE_S,
                                                        'zeit' => date('H:i', (int)$c['ts']), 'datum' => date('d.m.', (int)$c['ts']), 'text' => $c['text']], $liste),
                  'ungelesen' => chat_ungelesen($ich)]);
    }
    if ($api === 'ungelesen') {
        json_aus(['chat' => chat_ungelesen($ich)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        json_aus(['fehler' => 'Die Seite ist abgelaufen. Bitte neu laden.'], 400);
    }
    if ($api === 'chat_senden') {
        $f = chat_senden($ich, (string)($_POST['kanal'] ?? 'alle'), (int)($_POST['partner'] ?? 0), (string)($_POST['text'] ?? ''));
        json_aus($f ? ['fehler' => $f] : ['ok' => true], $f ? 422 : 200);
    }
    if ($api === 'berater') {
        if ($vertretung) {
            json_aus(['fehler' => 'In Vertretung gibt es keinen Berater.'], 403);
        }
        session_write_close();
        set_time_limit(120);
        $r = berater_fragen($ich, (string)($_POST['frage'] ?? ''));
        json_aus($r + ['html' => isset($r['antwort']) ? berater_html($r['antwort']) : '',
                       'rest' => max(0, (int)ki_einstellung('ki_berater_limit') - berater_fragen_heute($ich))], isset($r['fehler']) ? 422 : 200);
    }
    if ($api === 'zeitung_ki') {
        // Die Redaktion schreibt im Hintergrund weiter, auch wenn der Spieler die Seite verlässt
        ignore_user_abort(true);
        set_time_limit(180);
        session_write_close();
        $f = zeitung_ki();
        json_aus($f ? ['fehler' => $f] : ['ok' => true]);
    }
    json_aus(['fehler' => 'Unbekannte Schnittstelle.'], 404);
}

// ---------- Aktionen (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Überschreitet ein Upload post_max_size, kommt das Formular komplett leer an
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        meldung('Die Datei ist größer, als der Server annimmt (' . groesse(php_upload_grenze()) . ').', 'fehler');
        weiter($echt && (int)$echt['ist_admin'] ? 'admin' : 'uebersicht');
    }
    csrf_pruefen();
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'anmelden') {
        $f = anmelden((string)($_POST['name'] ?? ''), (string)($_POST['pass'] ?? ''));
        if ($f) {
            meldung($f, 'fehler');
            weiter('start');
        }
        weiter('uebersicht');
    }
    if ($a === 'registrieren') {
        $f = registrieren((string)($_POST['name'] ?? ''), (string)($_POST['pass'] ?? ''), (string)($_POST['pass2'] ?? ''), (string)($_POST['ort'] ?? ''));
        if ($f) {
            meldung($f, 'fehler');
            $_SESSION['form'] = ['name' => (string)($_POST['name'] ?? ''), 'ort' => (string)($_POST['ort'] ?? '')];
            weiter('start', ['neu' => 1]);
        }
        meldung('Willkommen in Herbinum! Deine Zentrale steht. Bau als Erstes ein Windpark und einen Sägewerk.');
        weiter('uebersicht');
    }
    if (!$echt) {
        weiter('start');
    }
    if ($a === 'abmelden') {
        abmelden();
        weiter('start');
    }
    $zurueck = (string)($_POST['zurueck'] ?? '');

    // Vertretung beginnen / beenden
    if ($a === 'vertreten_start') {
        $ziel = (int)($_POST['spieler'] ?? 0);
        if (!vertretung_ziel((int)$echt['id'], $ziel)) {
            meldung('Diesen Spieler kannst du gerade nicht vertreten.', 'fehler');
            weiter('allianz');
        }
        $_SESSION['vertritt'] = $ziel;
        unset($_SESSION['v_bereich']);
        weiter('uebersicht');
    }
    if ($a === 'vertreten_ende') {
        unset($_SESSION['vertritt'], $_SESSION['v_bereich']);
        weiter('allianz');
    }

    $sid = (int)$spieler['id'];
    $bid = (string)($_POST['bereich'] ?? '');
    $steuern = array_merge(array_keys(VERTRETUNG_AKTIONEN), ['markt_angebot', 'markt_annehmen', 'markt_zurueckziehen']);
    if ($vertretung) {
        $recht = VERTRETUNG_AKTIONEN[$a] ?? null;
        if (!$recht || !in_array($recht, $vertretung['rechte'], true)) {
            meldung('Das darfst du in Vertretung von ' . $spieler['name'] . ' nicht.', 'fehler');
            weiter($zurueck ?: 'uebersicht');
        }
    } elseif (in_array($a, $steuern, true) && ($f = vertretung_aktiv($sid))) {
        meldung('Dein Vertretungsfenster läuft noch bis ' . date('H:i', $f[1]) . ' Uhr – in der Zeit steuert deine Allianz. '
            . 'Unter „Vertretung“ kannst du es mit „Ich bin wieder da“ vorzeitig beenden.', 'fehler');
        weiter($zurueck ?: 'uebersicht');
    }
    // Ergebnis melden und in Vertretung protokollieren
    $fertig = function (?string $fehler, string $ok, string $seite, array $params = []) use ($vertretung, $echt, $sid) {
        meldung($fehler ?? $ok, $fehler ? 'fehler' : 'ok');
        if (!$fehler && $vertretung) {
            vertretung_protokoll($sid, $echt['name'], $ok);
        }
        weiter($seite, $params);
    };

    if ($a === 'bauen') {
        $typ = (string)($_POST['typ'] ?? '');
        $fertig(bau_starten($bid, $sid, $typ), (GEBAEUDE[$typ]['name'] ?? $typ) . ' wird gebaut.', $zurueck ?: 'gebaeude');
    }
    if ($a === 'abbrechen') {
        $fertig(bau_abbrechen($bid, $sid, (int)($_POST['auftrag'] ?? 0)), 'Bauauftrag abgebrochen, die Kosten wurden erstattet.', $zurueck ?: 'gebaeude');
    }
    if ($a === 'forschen') {
        $typ = (string)($_POST['typ'] ?? '');
        $fertig(forschung_starten($bid, $sid, $typ), 'In der Forschungszentrum wird jetzt ' . (FORSCHUNG[$typ]['name'] ?? $typ) . ' erforscht.', 'forschung');
    }
    if ($a === 'forschung_abbrechen') {
        $fertig(forschung_abbrechen($sid), 'Forschung abgebrochen, die Kosten wurden erstattet.', 'forschung');
    }
    if ($a === 'einheiten') {
        $typ = (string)($_POST['typ'] ?? '');
        $n = (int)($_POST['anzahl'] ?? 0);
        $fertig(einheiten_bauen($bid, $sid, $typ, $n), "{$n} × " . (EINHEITEN[$typ]['name'] ?? $typ) . ' in Auftrag gegeben.', $zurueck === 'schuetzen' ? 'schuetzen' : 'werkstatt');
    }
    if ($a === 'losschicken') {
        $ziel = (string)($_POST['ziel'] ?? '');
        $auftrag = (string)($_POST['auftrag'] ?? '');
        $f = zug_starten($bid, $sid, $ziel, $auftrag, (array)($_POST['e'] ?? []), (array)($_POST['l'] ?? []), $vertretung !== null);
        $fertig($f, 'Der Zug ist unterwegs (' . (AUFTRAEGE[$auftrag] ?? $auftrag) . ' nach ' . bereich_name(db(), $ziel) . ').', 'zuege', $f ? ['ziel' => $ziel, 'auftrag' => $auftrag] : []);
    }
    if ($a === 'umkehren') {
        $fertig(zug_zurueckrufen($sid, (int)($_POST['zug'] ?? 0)), 'Der Zug kehrt um.', 'zuege');
    }
    if ($a === 'bunker_einlagern' || $a === 'bunker_auslagern') {
        $e = (array)($_POST['e'] ?? []);
        $f = $a === 'bunker_einlagern' ? bunker_einlagern($bid, $sid, $e) : bunker_auslagern($bid, $sid, $e);
        $was = implode(', ', array_map(fn($t, $n) => "{$n} × " . EINHEITEN[$t]['name'], array_keys(array_filter(array_map('intval', array_intersect_key($e, BUNKER_PLATZ)))), array_filter(array_map('intval', array_intersect_key($e, BUNKER_PLATZ)))));
        $fertig($f, $a === 'bunker_einlagern' ? "Im Bunker eingelagert: {$was}." : "Auslagerung läuft: {$was}.", 'bunker');
    }
    if ($a === 'markt_tauschen') {
        $f = markt_tauschen($bid, $sid, (string)($_POST['von'] ?? ''), (string)($_POST['nach'] ?? ''), (int)($_POST['menge'] ?? 0));
        $text = $_SESSION['markt_ergebnis'] ?? 'Getauscht.';
        unset($_SESSION['markt_ergebnis']);
        $fertig($f, "Händler: {$text}", 'markt');
    }
    if ($a === 'markt_angebot') {
        $fertig(angebot_erstellen($bid, $sid, (string)($_POST['gibt'] ?? ''), (int)($_POST['gibt_menge'] ?? 0), (string)($_POST['will'] ?? ''),
            (int)($_POST['will_menge'] ?? 0), ($_POST['nur_allianz'] ?? '') === '1'), 'Dein Angebot hängt am Schwarzen Brett.', 'markt');
    }
    if ($a === 'markt_annehmen') {
        $f = angebot_annehmen($bid, $sid, (int)($_POST['angebot'] ?? 0));
        $text = $_SESSION['markt_ergebnis'] ?? 'Gekauft.';
        unset($_SESSION['markt_ergebnis']);
        $fertig($f, $text, 'markt');
    }
    if ($a === 'markt_zurueckziehen') {
        $fertig(angebot_zurueckziehen($sid, (int)($_POST['angebot'] ?? 0)), 'Angebot zurückgezogen, die Ware ist wieder im Lager.', 'markt');
    }

    // Ab hier nur noch mit dem eigenen Konto
    if ($vertretung) {
        meldung('Das geht nur mit deinem eigenen Konto. Beende zuerst die Vertretung.', 'fehler');
        weiter('uebersicht');
    }
    if ($a === 'vertretung_speichern') {
        $f = vertretung_speichern($sid, (array)($_POST['tage'] ?? []), (string)($_POST['von'] ?? ''), (string)($_POST['bis'] ?? ''), (array)($_POST['rechte'] ?? []));
        $fertig($f, 'Vertretung gespeichert. Sie gilt ab ' . date('d.m. H:i', time() + VERTRETUNG_VORLAUF_S) . ' Uhr.', 'vertretung');
    }
    if ($a === 'vertretung_loeschen') {
        $fertig(vertretung_loeschen($sid), 'Vertretung gelöscht.', 'vertretung');
    }
    if ($a === 'vertretung_zurueck') {
        $fertig(vertretung_heute_beenden($sid), 'Willkommen zurück! Du steuerst wieder selbst.', 'uebersicht');
    }
    if ($a === 'berater_frage') {
        // Ohne JavaScript: Frage stellen und Seite neu laden (mit JavaScript läuft das über ?api=berater)
        $r = berater_fragen($sid, (string)($_POST['frage'] ?? ''));
        if (isset($r['fehler'])) {
            meldung($r['fehler'], 'fehler');
        }
        weiter('berater');
    }
    if ($a === 'berater_loeschen') {
        berater_loeschen($sid);
        meldung('Verlauf gelöscht.');
        weiter('berater');
    }
    if ($a === 'brief') {
        $an = (int)($_POST['an'] ?? 0);
        $f = brief_senden($sid, $an, (string)($_POST['betreff'] ?? ''), (string)($_POST['text'] ?? ''));
        if ($f) {
            $_SESSION['brief_entwurf'] = ['an' => $an, 'betreff' => (string)($_POST['betreff'] ?? ''), 'text' => (string)($_POST['text'] ?? '')];
        }
        $fertig($f, 'Brief verschickt.', 'nachrichten', $f ? ['schreiben' => 1] : ['ordner' => 'gesendet']);
    }
    if (str_starts_with($a, 'admin_')) {
        if (!(int)$spieler['ist_admin']) {
            http_response_code(403);
            exit('Nur für die Spielleitung.');
        }
        $ziel = (int)($_POST['spieler'] ?? 0);
        $f = match ($a) {
            'admin_musik_hochladen' => isset($_FILES['datei']) && ($_FILES['datei']['error'] ?? 1) === UPLOAD_ERR_OK && !is_uploaded_file($_FILES['datei']['tmp_name'])
                ? 'Ungültiger Upload.' : musik_hochladen($_FILES['datei'] ?? [], (string)($_POST['titel'] ?? '')),
            'admin_musik_aktiv' => musik_aktivieren((int)($_POST['musik'] ?? 0)),
            'admin_musik_loeschen' => musik_loeschen((int)($_POST['musik'] ?? 0)),
            'admin_lautstaerke' => einstellung_setzen('musik_lautstaerke', (string)max(0, min(100, (int)($_POST['wert'] ?? 60)))),
            'admin_sperren' => spieler_sperren($sid, $ziel, true, (string)($_POST['grund'] ?? '')),
            'admin_entsperren' => spieler_sperren($sid, $ziel, false),
            'admin_recht' => admin_recht_setzen($sid, $ziel, ($_POST['an'] ?? '') === '1'),
            'admin_loeschen' => spieler_loeschen($sid, $ziel, (string)($_POST['bestaetigung'] ?? '')),
            'admin_rundschreiben' => rundschreiben((string)($_POST['titel'] ?? ''), (string)($_POST['text'] ?? '')),
            'admin_passwort' => (function () use ($sid, $ziel) {
                $r = passwort_zuruecksetzen($sid, $ziel);
                if (isset($r['fehler'])) {
                    return $r['fehler'];
                }
                $_SESSION['neues_passwort'] = $r;
                return null;
            })(),
            'admin_ereignis' => ereignis_ausloesen((string)($_POST['typ'] ?? ''), (string)($_POST['ort'] ?? ''), (float)($_POST['staerke'] ?? 0.5)),
            'admin_ereignis_loeschen' => ereignis_loeschen((int)($_POST['ereignis'] ?? 0)),
            'admin_ereignisse_schalter' => einstellung_setzen('ereignisse_aus', ($_POST['aus'] ?? '') === '1' ? '1' : '0'),
            'admin_zeitung' => (function () {
                ignore_user_abort(true);
                set_time_limit(180);
                return zeitung_ki(true);
            })(),
            'admin_chat_loeschen' => chat_loeschen((int)($_POST['chat'] ?? 0)),
            'admin_ki' => ki_einstellungen_speichern($_POST),
            default => 'Unbekannte Admin-Aktion.',
        };
        $ok = [
            'admin_musik_hochladen' => 'Loop hochgeladen.', 'admin_musik_aktiv' => (int)($_POST['musik'] ?? 0) ? 'Dieser Loop läuft jetzt für alle.' : 'Hintergrundmusik ist für alle aus.',
            'admin_musik_loeschen' => 'Loop gelöscht.', 'admin_lautstaerke' => 'Standardlautstärke gespeichert.',
            'admin_sperren' => 'Spieler gesperrt.', 'admin_entsperren' => 'Sperre aufgehoben.', 'admin_recht' => 'Rechte geändert.',
            'admin_loeschen' => 'Spieler gelöscht, seine Bereiche sind wieder frei.', 'admin_rundschreiben' => 'Rundschreiben an alle verschickt.',
            'admin_passwort' => 'Neues Passwort erzeugt.', 'admin_ereignis' => 'Das Ereignis hat begonnen.', 'admin_ereignis_loeschen' => 'Ereignis gestrichen.',
            'admin_ereignisse_schalter' => ($_POST['aus'] ?? '') === '1' ? 'Zufallsereignisse sind aus.' : 'Zufallsereignisse sind an.',
            'admin_zeitung' => 'Die KI hat die heutige Ausgabe neu geschrieben.', 'admin_chat_loeschen' => 'Chatnachricht entfernt.',
            'admin_ki' => 'KI-Einstellungen gespeichert.',
        ];
        meldung($f ?? ($ok[$a] ?? 'Erledigt.'), $f ? 'fehler' : 'ok');
        weiter($a === 'admin_chat_loeschen' ? 'chat' : 'admin');
    }
    if (str_starts_with($a, 'gen_')) {
        $ziel = (int)($_POST['spieler'] ?? 0);
        $gid = (int)($_POST['gid'] ?? 0);
        $f = match ($a) {
            'gen_gruenden' => gen_gruenden($sid, (string)($_POST['name'] ?? ''), (string)($_POST['kuerzel'] ?? ''), (string)($_POST['beschreibung'] ?? '')),
            'gen_bewerben' => gen_bewerben($sid, $gid, (string)($_POST['text'] ?? '')),
            'gen_zurueckziehen' => gen_bewerbung_zurueckziehen($sid, $gid),
            'gen_annehmen' => gen_entscheiden($sid, $ziel, true),
            'gen_ablehnen' => gen_entscheiden($sid, $ziel, false),
            'gen_rang' => gen_rang_setzen($sid, $ziel, (string)($_POST['rang'] ?? '')),
            'gen_entfernen' => gen_entfernen($sid, $ziel),
            'gen_austreten' => gen_austreten($sid),
            'gen_aufloesen' => gen_aufloesen($sid, (string)($_POST['bestaetigung'] ?? '')),
            'gen_beschreibung' => gen_beschreibung_setzen($sid, (string)($_POST['beschreibung'] ?? '')),
            'gen_rundschreiben' => gen_rundschreiben($sid, (string)($_POST['titel'] ?? ''), (string)($_POST['text'] ?? '')),
            'gen_wappen' => isset($_FILES['wappen']) && ($_FILES['wappen']['error'] ?? 1) === UPLOAD_ERR_OK && !is_uploaded_file($_FILES['wappen']['tmp_name'])
                ? 'Ungültiger Upload.' : wappen_hochladen($sid, $_FILES['wappen'] ?? []),
            'gen_wappen_weg' => wappen_entfernen($sid),
            default => 'Unbekannte Aktion.',
        };
        $ok = ['gen_gruenden' => 'Allianz gegründet – du bist im Vorstand.', 'gen_bewerben' => 'Bewerbung abgeschickt.',
               'gen_zurueckziehen' => 'Bewerbung zurückgezogen.', 'gen_annehmen' => 'Neues Mitglied aufgenommen.', 'gen_ablehnen' => 'Bewerbung abgelehnt.',
               'gen_rang' => 'Rang geändert.', 'gen_entfernen' => 'Mitglied entfernt.', 'gen_austreten' => 'Du bist ausgetreten.',
               'gen_aufloesen' => 'Allianz aufgelöst.', 'gen_beschreibung' => 'Beschreibung gespeichert.', 'gen_rundschreiben' => 'Nachricht an alle Mitglieder verschickt.',
               'gen_wappen' => 'Das neue Wappen hängt.', 'gen_wappen_weg' => 'Wappen entfernt.'];
        meldung($f ?? ($ok[$a] ?? 'Erledigt.'), $f ? 'fehler' : 'ok');
        weiter('allianz', $a === 'gen_bewerben' && $f ? ['id' => $gid] : []);
    }
    if ($a === 'nachrichten_loeschen') {
        db()->prepare("DELETE FROM nachrichten WHERE spieler_id = ? AND gelesen = 1 AND art <> 'gesendet'")->execute([$sid]);
        meldung('Gelesene Nachrichten gelöscht.');
        weiter('nachrichten');
    }
    if ($a === 'nachricht_loeschen') {
        db()->prepare('DELETE FROM nachrichten WHERE spieler_id = ? AND id = ?')->execute([$sid, (int)($_POST['id'] ?? 0)]);
        weiter('nachrichten', ['ordner' => (string)($_POST['ordner'] ?? 'eingang')]);
    }
    http_response_code(400);
    exit('Unbekannte Aktion.');
}

// ---------- Seiten ----------
if (!$spieler) {
    $orte = db()->query("SELECT o.id, o.name, SUM(b.besitzer_id IS NULL AND b.typ <> 'Kern') AS frei FROM orte o JOIN bereiche b ON b.ort_id = o.id GROUP BY o.id, o.name ORDER BY o.name")->fetchAll();
    $anzahl = (int)db()->query('SELECT COUNT(*) FROM spieler')->fetchColumn();
    $form = $_SESSION['form'] ?? [];
    unset($_SESSION['form']);
    ansicht('start', ['orte' => $orte, 'anzahl' => $anzahl, 'form' => $form, 'neu' => isset($_GET['neu'])]);
    exit;
}

$bereiche = eigene_bereiche((int)$spieler['id']);
if (!$bereiche) {
    exit('Du hast keinen Bereich mehr. Melde dich beim Admin.');
}
$bKey = $vertretung ? 'v_bereich' : 'bereich';
if (isset($_GET['b']) && in_array($_GET['b'], array_column($bereiche, 'id'), true)) {
    $_SESSION[$bKey] = $_GET['b'];
}
$bid = in_array($_SESSION[$bKey] ?? '', array_column($bereiche, 'id'), true) ? $_SESSION[$bKey] : $bereiche[0]['id'];
$z = bereich_zustand($bid);
$ort = db()->prepare('SELECT * FROM orte WHERE id = ?');
$ort->execute([$z['bereich']['ort_id']]);
$sid = (int)$spieler['id'];
$ungelesen = db()->prepare('SELECT COUNT(*) FROM nachrichten WHERE spieler_id = ? AND gelesen = 0');
$ungelesen->execute([(int)$echt['id']]);
$ueberfaelle = db()->prepare("SELECT COUNT(*) FROM zuege z JOIN bereiche b ON b.id = z.ziel_id WHERE b.besitzer_id = ? AND z.auftrag = 'angriff' AND z.status = 'hin'");
$ueberfaelle->execute([$sid]);
$zeitungTag = db()->prepare('SELECT zeitung_tag FROM spieler WHERE id = ?');
$zeitungTag->execute([(int)$echt['id']]);
$daten = ['spieler' => $spieler, 'echt' => $echt, 'vertretung' => $vertretung, 'bereiche' => $bereiche, 'z' => $z, 'ort' => $ort->fetch(), 'seite' => $seite,
          'ungelesen' => (int)$ungelesen->fetchColumn(), 'ueberfaelle' => (int)$ueberfaelle->fetchColumn(),
          'chat_neu' => array_sum(chat_ungelesen((int)$echt['id'])), 'zeitung_neu' => (int)$zeitungTag->fetchColumn() < spieltag(time()),
          'eigenes_fenster' => $vertretung ? null : vertretung_aktiv($sid),
          'musik' => aktive_musik(), 'lautstaerke' => (int)einstellung('musik_lautstaerke', '60'), 'gen' => gen_von($sid)];

// In Vertretung gibt es nur die Seiten, auf denen gesteuert werden darf
$vertretungSeiten = ['uebersicht', 'gebaeude', 'forschung', 'werkstatt', 'schuetzen', 'bunker', 'zuege', 'karte', 'markt', 'rangliste', 'zeitung'];
if ($vertretung && !in_array($seite, $vertretungSeiten, true)) {
    meldung('In Vertretung von ' . $spieler['name'] . ' gibt es diese Seite nicht. Beende oben die Vertretung, um zu deinem Konto zurückzukehren.', 'fehler');
    weiter('uebersicht');
}
if ($seite === 'admin' && !(int)$spieler['ist_admin']) {
    weiter('uebersicht');
}

match ($seite) {
    'gebaeude' => ansicht('gebaeude', $daten),
    'forschung' => ansicht('forschung', $daten),
    'werkstatt' => ansicht('werkstatt', $daten + ['halle' => 'werkstatt']),
    'schuetzen' => ansicht('werkstatt', $daten + ['halle' => 'schuetzenhalle']),
    'bunker' => ansicht('bunker', $daten),
    'zuege' => ansicht('zuege', $daten),
    'markt' => ansicht('markt', $daten),
    'nachrichten' => ansicht('nachrichten', $daten),
    'chat' => ansicht('chat', $daten),
    'zeitung' => ansicht('zeitung', $daten),
    'berater' => berater_bereit() ? ansicht('berater', $daten) : weiter('uebersicht'),
    'vertretung' => ansicht('vertretung', $daten),
    'admin' => ansicht('admin', $daten),
    'rangliste' => ansicht('rangliste', $daten),
    'allianz' => ansicht('genossenschaft', $daten),
    'karte' => ansicht('karte', $daten),
    default => ansicht('uebersicht', ['seite' => 'uebersicht'] + $daten),
};
