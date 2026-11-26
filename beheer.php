<?php
// beheer.php - lezen, aanpassen en verwijderen van plekken, gebeurtenissen en bronnen.
// ALLEEN voor gebruik op je eigen laptop. Verwijder dit bestand (en beheer.html) voordat de site online gaat.
//
//   GET  beheer.php            -> alle plekken, gebeurtenissen, bronnen en soorten (JSON)
//   GET  beheer.php?export=1   -> alles als CSV-bestand (om te laten controleren)
//   POST beheer.php            -> een actie uitvoeren, zie onderaan

header('Content-Type: application/json; charset=utf-8');

// Alleen vanaf de eigen computer toestaan
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['fout' => 'Alleen lokaal toegestaan']);
    exit;
}

// ---------- Hulpfuncties ----------
function antwoord($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Tekst inkorten en spaties weghalen
function tekst($waarde, int $max): string {
    return mb_substr(trim((string) ($waarde ?? '')), 0, $max);
}

// Jaar controleren; leeg mag alleen als $magLeeg true is
function jaar($waarde, bool $magLeeg) {
    if ($waarde === null || $waarde === '') {
        if ($magLeeg) return null;
        throw new InvalidArgumentException('Vul een jaar in.');
    }
    $j = filter_var($waarde, FILTER_VALIDATE_INT);
    if ($j === false || $j < 0 || $j > 2100) {
        throw new InvalidArgumentException('Ongeldig jaar: ' . $waarde);
    }
    return $j;
}

// Id controleren (leeg = null)
function idOfNull($waarde) {
    if ($waarde === null || $waarde === '' || $waarde === 0 || $waarde === '0') return null;
    $id = filter_var($waarde, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) throw new InvalidArgumentException('Ongeldig id.');
    return $id;
}

// Social-medialink controleren: leeg mag, anders https en het juiste platform
function socialLink($waarde, array $domeinen, string $naam) {
    $url = tekst($waarde, 300);
    if ($url === '') return null;
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $okDomein = false;
    foreach ($domeinen as $d) {
        if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { $okDomein = true; }
    }
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$okDomein) {
        throw new InvalidArgumentException('De ' . $naam . '-link moet beginnen met https:// en naar ' . $domeinen[0] . ' gaan.');
    }
    return $url;
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=vlodrop;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // =====================================================================
    // GET: alles ophalen
    // =====================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $plekken = $pdo->query(
            'SELECT plekken.id, plekken.naam, types.naam AS soort, types.groep,
                    plekken.lat, plekken.lon, plekken.van_jaar, plekken.tot_jaar,
                    plekken.jaar_onzeker, plekken.beschrijving, plekken.bron_id, plekken.osm_id,
                    plekken.adres, plekken.coord_geschat, plekken.website, plekken.wikipedia,
                    plekken.facebook, plekken.instagram, plekken.linkedin, plekken.tiktok, plekken.bag_bouwjaar
             FROM plekken
             JOIN types ON plekken.type_id = types.id
             ORDER BY plekken.naam'
        )->fetchAll(PDO::FETCH_ASSOC);

        $geschiedenis = $pdo->query(
            'SELECT id, plek_id, jaar, tekst, bron_id FROM geschiedenis ORDER BY plek_id, jaar'
        )->fetchAll(PDO::FETCH_ASSOC);

        $bronnen = $pdo->query(
            'SELECT id, titel, auteur, url, rechten FROM bronnen ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $types = $pdo->query(
            'SELECT naam, groep FROM types ORDER BY groep, naam'
        )->fetchAll(PDO::FETCH_ASSOC);

        // ---- Export als CSV (puntkomma's, opent goed in Excel) ----
        if (isset($_GET['export'])) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="vlodrop-plekken.csv"');

            $bronTitel = [];
            foreach ($bronnen as $b) {
                $bronTitel[$b['id']] = $b['titel'] . ($b['url'] ? ' (' . $b['url'] . ')' : '');
            }
            $perPlek = [];
            foreach ($geschiedenis as $g) {
                $bron = $g['bron_id'] && isset($bronTitel[$g['bron_id']]) ? ' [bron: ' . $bronTitel[$g['bron_id']] . ']' : ' [geen bron]';
                $perPlek[$g['plek_id']][] = $g['jaar'] . ': ' . $g['tekst'] . $bron;
            }

            $uit = fopen('php://output', 'w');
            fwrite($uit, "\xEF\xBB\xBF"); // zodat Excel de letters met accenten goed toont
            fputcsv($uit, ['id', 'naam', 'soort', 'groep', 'lat', 'lon', 'van_jaar', 'tot_jaar',
                           'jaar_onzeker', 'beschrijving', 'bron_plek', 'osm_id', 'adres', 'coord_geschat', 'website', 'wikipedia', 'facebook', 'instagram', 'linkedin', 'tiktok', 'bag_bouwjaar', 'geschiedenis'], ';');
            foreach ($plekken as $p) {
                fputcsv($uit, [
                    $p['id'], $p['naam'], $p['soort'], $p['groep'], $p['lat'], $p['lon'],
                    $p['van_jaar'], $p['tot_jaar'], $p['jaar_onzeker'], $p['beschrijving'],
                    $p['bron_id'] && isset($bronTitel[$p['bron_id']]) ? $bronTitel[$p['bron_id']] : '',
                    $p['osm_id'], $p['adres'], $p['coord_geschat'], $p['website'], $p['wikipedia'],
                    $p['facebook'], $p['instagram'], $p['linkedin'], $p['tiktok'], $p['bag_bouwjaar'],
                    implode(' | ', $perPlek[$p['id']] ?? []),
                ], ';');
            }
            fclose($uit);
            exit;
        }

        // Getallen als getal teruggeven
        foreach ($plekken as &$p) {
            $p['id'] = (int) $p['id'];
            $p['lat'] = (float) $p['lat'];
            $p['lon'] = (float) $p['lon'];
            $p['van_jaar'] = (int) $p['van_jaar'];
            $p['tot_jaar'] = $p['tot_jaar'] === null ? null : (int) $p['tot_jaar'];
            $p['jaar_onzeker'] = (int) $p['jaar_onzeker'];
            $p['bron_id'] = $p['bron_id'] === null ? null : (int) $p['bron_id'];
            $p['coord_geschat'] = (int) $p['coord_geschat'];
            $p['bag_bouwjaar'] = $p['bag_bouwjaar'] === null ? null : (int) $p['bag_bouwjaar'];
        }
        unset($p);
        foreach ($geschiedenis as &$g) {
            $g['id'] = (int) $g['id'];
            $g['plek_id'] = (int) $g['plek_id'];
            $g['jaar'] = (int) $g['jaar'];
            $g['bron_id'] = $g['bron_id'] === null ? null : (int) $g['bron_id'];
        }
        unset($g);
        foreach ($bronnen as &$b) { $b['id'] = (int) $b['id']; }
        unset($b);

        antwoord(compact('plekken', 'geschiedenis', 'bronnen', 'types'));
    }

    // =====================================================================
    // POST: een actie uitvoeren
    // =====================================================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        antwoord(['fout' => 'Gebruik GET of POST'], 405);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in) || !isset($in['actie'])) {
        antwoord(['fout' => 'Ongeldige invoer'], 400);
    }
    $d = $in['data'] ?? [];

    switch ($in['actie']) {

        // ---------- Plek opslaan (nieuw of bestaand) ----------
        case 'plek_opslaan':
            $naam  = tekst($d['naam'] ?? '', 100);
            $soort = tekst($d['soort'] ?? '', 50);
            $groep = tekst($d['groep'] ?? '', 40);
            $lat   = filter_var($d['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon   = filter_var($d['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            $van   = jaar($d['van_jaar'] ?? null, false);
            $tot   = jaar($d['tot_jaar'] ?? null, true);
            $onzeker = !empty($d['jaar_onzeker']) ? 1 : 0;
            $beschrijving = tekst($d['beschrijving'] ?? '', 2000);
            $bronId = idOfNull($d['bron_id'] ?? null);
            $id = idOfNull($d['id'] ?? null);
            $adres = tekst($d['adres'] ?? '', 120);
            $geschat = !empty($d['coord_geschat']) ? 1 : 0;
            $website   = tekst($d['website'] ?? '', 300);
            $wikipedia = tekst($d['wikipedia'] ?? '', 300);
            $facebook  = socialLink($d['facebook'] ?? '', ['facebook.com', 'fb.com'], 'Facebook');
            $instagram = socialLink($d['instagram'] ?? '', ['instagram.com'], 'Instagram');
            $linkedin  = socialLink($d['linkedin'] ?? '', ['linkedin.com'], 'LinkedIn');
            $tiktok    = socialLink($d['tiktok'] ?? '', ['tiktok.com'], 'TikTok');
            foreach ([$website, $wikipedia] as $link) {
                if ($link !== '' && !preg_match('#^https?://#i', $link)) {
                    throw new InvalidArgumentException('Een website-link moet beginnen met https://');
                }
            }

            if ($naam === '')  throw new InvalidArgumentException('Vul een naam in.');
            if ($soort === '') throw new InvalidArgumentException('Vul een soort in.');
            if ($groep === '') $groep = 'Overig';
            if ($lat === false || $lon === false || $lat < 51.0 || $lat > 51.3 || $lon < 5.9 || $lon > 6.3) {
                throw new InvalidArgumentException('Coordinaten liggen niet bij Vlodrop.');
            }
            if ($tot !== null && $tot < $van) {
                throw new InvalidArgumentException('Het eindjaar ligt voor het beginjaar.');
            }

            $pdo->beginTransaction();

            // Soort opzoeken of aanmaken. Let op: de groep hoort bij de soort,
            // dus een andere groep kiezen verandert hem voor alle plekken van die soort.
            $stmt = $pdo->prepare('SELECT id, groep FROM types WHERE naam = :naam');
            $stmt->execute([':naam' => $soort]);
            $type = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($type) {
                $typeId = (int) $type['id'];
                if ($type['groep'] !== $groep) {
                    $pdo->prepare('UPDATE types SET groep = :groep WHERE id = :id')
                        ->execute([':groep' => $groep, ':id' => $typeId]);
                }
            } else {
                $pdo->prepare('INSERT INTO types (naam, groep) VALUES (:naam, :groep)')
                    ->execute([':naam' => $soort, ':groep' => $groep]);
                $typeId = (int) $pdo->lastInsertId();
            }

            $waarden = [
                ':naam' => $naam, ':type_id' => $typeId, ':lat' => $lat, ':lon' => $lon,
                ':van' => $van, ':tot' => $tot, ':onzeker' => $onzeker,
                ':beschrijving' => $beschrijving, ':bron_id' => $bronId,
                ':adres' => $adres === '' ? null : $adres, ':geschat' => $geschat,
                ':website' => $website === '' ? null : $website, ':wikipedia' => $wikipedia === '' ? null : $wikipedia,
                ':facebook' => $facebook, ':instagram' => $instagram, ':linkedin' => $linkedin, ':tiktok' => $tiktok,
            ];
            if ($id) {
                $waarden[':id'] = $id;
                $pdo->prepare(
                    'UPDATE plekken SET naam = :naam, type_id = :type_id, lat = :lat, lon = :lon,
                            van_jaar = :van, tot_jaar = :tot, jaar_onzeker = :onzeker,
                            beschrijving = :beschrijving, bron_id = :bron_id,
                            adres = :adres, coord_geschat = :geschat,
                            website = :website, wikipedia = :wikipedia,
                            facebook = :facebook, instagram = :instagram, linkedin = :linkedin, tiktok = :tiktok
                     WHERE id = :id'
                )->execute($waarden);
            } else {
                $pdo->prepare(
                    'INSERT INTO plekken (naam, type_id, lat, lon, van_jaar, tot_jaar, jaar_onzeker, beschrijving, bron_id, adres, coord_geschat, website, wikipedia, facebook, instagram, linkedin, tiktok)
                     VALUES (:naam, :type_id, :lat, :lon, :van, :tot, :onzeker, :beschrijving, :bron_id, :adres, :geschat, :website, :wikipedia, :facebook, :instagram, :linkedin, :tiktok)'
                )->execute($waarden);
                $id = (int) $pdo->lastInsertId();
            }

            $pdo->commit();
            antwoord(['ok' => true, 'id' => $id]);

        // ---------- Alleen de locatie van een plek bijwerken ----------
        case 'locatie_opslaan':
            $id  = idOfNull($d['id'] ?? null);
            $lat = filter_var($d['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($d['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!$id) throw new InvalidArgumentException('Geen plek gekozen.');
            if ($lat === false || $lon === false || $lat < 51.0 || $lat > 51.3 || $lon < 5.9 || $lon > 6.3) {
                throw new InvalidArgumentException('Coordinaten liggen niet bij Vlodrop.');
            }
            $pdo->prepare('UPDATE plekken SET lat = :lat, lon = :lon, coord_geschat = 0 WHERE id = :id')
                ->execute([':lat' => $lat, ':lon' => $lon, ':id' => $id]);
            antwoord(['ok' => true]);

        // ---------- Bouwjaar uit de BAG opslaan ----------
        // Optie 'als_start': plekken waarvan het startjaar onbekend is, krijgen het bouwjaar als 'uiterlijk'-jaar
        case 'bag_opslaan':
            $id      = idOfNull($d['id'] ?? null);
            $bouw    = filter_var($d['bouwjaar'] ?? null, FILTER_VALIDATE_INT);
            $pandId  = preg_replace('/[^0-9]/', '', (string) ($d['pand_id'] ?? ''));
            $alsStart = !empty($d['als_start']);
            if (!$id) throw new InvalidArgumentException('Geen plek gekozen.');
            if ($bouw === false || $bouw < 1000 || $bouw > 2100) throw new InvalidArgumentException('Ongeldig bouwjaar.');

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE plekken SET bag_bouwjaar = :b, bag_pand_id = :pid WHERE id = :id')
                ->execute([':b' => $bouw, ':pid' => $pandId === '' ? null : substr($pandId, 0, 20), ':id' => $id]);

            $aangepast = false;
            if ($alsStart) {
                $stmt = $pdo->prepare('SELECT van_jaar, tot_jaar, jaar_onzeker FROM plekken WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $plek = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($plek && (int) $plek['jaar_onzeker'] === 1 && (int) $plek['van_jaar'] >= 2026
                    && $plek['tot_jaar'] === null && $bouw < 2026) {
                    // BAG-bron opzoeken of aanmaken
                    $url = 'https://www.kadaster.nl/zakelijk/registraties/basisregistraties/bag';
                    $pdo->prepare("INSERT INTO bronnen (titel, auteur, url, rechten)
                                   SELECT 'Basisregistratie Adressen en Gebouwen (BAG), via PDOK', 'Kadaster', :url, 'Open data (Public Domain Mark)'
                                   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM bronnen WHERE url = :url2)")
                        ->execute([':url' => $url, ':url2' => $url]);
                    $stmt = $pdo->prepare('SELECT id FROM bronnen WHERE url = :url ORDER BY id LIMIT 1');
                    $stmt->execute([':url' => $url]);
                    $bagBron = (int) $stmt->fetchColumn();

                    $pdo->prepare('UPDATE plekken SET van_jaar = :b WHERE id = :id')
                        ->execute([':b' => $bouw, ':id' => $id]);
                    $tekst = 'Het gebouw op dit adres is volgens de BAG gebouwd in ' . $bouw
                           . '. Wanneer deze zaak hier begon, is (nog) niet bekend.';
                    $pdo->prepare('INSERT INTO geschiedenis (plek_id, jaar, tekst, bron_id)
                                   SELECT :p, :j, :t, :b FROM DUAL
                                   WHERE NOT EXISTS (SELECT 1 FROM geschiedenis WHERE plek_id = :p2 AND jaar = :j2 AND tekst = :t2)')
                        ->execute([':p' => $id, ':j' => $bouw, ':t' => $tekst, ':b' => $bagBron,
                                   ':p2' => $id, ':j2' => $bouw, ':t2' => $tekst]);
                    $aangepast = true;
                }
            }
            $pdo->commit();
            antwoord(['ok' => true, 'startjaar_aangepast' => $aangepast]);

        // ---------- Plek verwijderen (met zijn gebeurtenissen) ----------
        case 'plek_verwijderen':
            $id = idOfNull($d['id'] ?? null);
            if (!$id) throw new InvalidArgumentException('Geen plek gekozen.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM geschiedenis WHERE plek_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM plekken WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
            antwoord(['ok' => true]);

        // ---------- Gebeurtenis opslaan ----------
        case 'gebeurtenis_opslaan':
            $id     = idOfNull($d['id'] ?? null);
            $plekId = idOfNull($d['plek_id'] ?? null);
            $j      = jaar($d['jaar'] ?? null, false);
            $t      = tekst($d['tekst'] ?? '', 2000);
            $bronId = idOfNull($d['bron_id'] ?? null);
            if (!$plekId) throw new InvalidArgumentException('Geen plek gekozen.');
            if ($t === '') throw new InvalidArgumentException('Vul een tekst in.');

            if ($id) {
                $pdo->prepare('UPDATE geschiedenis SET jaar = :jaar, tekst = :tekst, bron_id = :bron WHERE id = :id')
                    ->execute([':jaar' => $j, ':tekst' => $t, ':bron' => $bronId, ':id' => $id]);
            } else {
                $pdo->prepare('INSERT INTO geschiedenis (plek_id, jaar, tekst, bron_id) VALUES (:plek, :jaar, :tekst, :bron)')
                    ->execute([':plek' => $plekId, ':jaar' => $j, ':tekst' => $t, ':bron' => $bronId]);
                $id = (int) $pdo->lastInsertId();
            }
            antwoord(['ok' => true, 'id' => $id]);

        // ---------- Gebeurtenis verwijderen ----------
        case 'gebeurtenis_verwijderen':
            $id = idOfNull($d['id'] ?? null);
            if (!$id) throw new InvalidArgumentException('Geen gebeurtenis gekozen.');
            $pdo->prepare('DELETE FROM geschiedenis WHERE id = :id')->execute([':id' => $id]);
            antwoord(['ok' => true]);

        // ---------- Bron opslaan ----------
        case 'bron_opslaan':
            $id      = idOfNull($d['id'] ?? null);
            $titel   = tekst($d['titel'] ?? '', 200);
            $auteur  = tekst($d['auteur'] ?? '', 100);
            $url     = tekst($d['url'] ?? '', 300);
            $rechten = tekst($d['rechten'] ?? '', 200);
            if ($titel === '') throw new InvalidArgumentException('Vul een titel in.');
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                throw new InvalidArgumentException('Een link moet beginnen met https://');
            }
            $waarden = [':titel' => $titel, ':auteur' => $auteur, ':url' => $url === '' ? null : $url, ':rechten' => $rechten];
            if ($id) {
                $waarden[':id'] = $id;
                $pdo->prepare('UPDATE bronnen SET titel = :titel, auteur = :auteur, url = :url, rechten = :rechten WHERE id = :id')
                    ->execute($waarden);
            } else {
                $pdo->prepare('INSERT INTO bronnen (titel, auteur, url, rechten) VALUES (:titel, :auteur, :url, :rechten)')
                    ->execute($waarden);
                $id = (int) $pdo->lastInsertId();
            }
            antwoord(['ok' => true, 'id' => $id]);

        default:
            antwoord(['fout' => 'Onbekende actie'], 400);
    }

} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    antwoord(['fout' => $e->getMessage()], 400);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    // 1451 = de bron wordt nog gebruikt, 1452 = de gekozen bron bestaat niet
    antwoord(['fout' => 'Database-fout: ' . $e->getMessage()], 500);
}
