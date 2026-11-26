<?php
// api.php
//   api.php?jaar=1880            -> alle plekken die in 1880 bestonden
//   api.php?plek=3&jaar=1880     -> details + alle geschiedenis van plek 3 (latere gebeurtenissen gemarkeerd)
//   api.php?jaren=1              -> jaartallen met gebeurtenissen (voor de tijdlijn)
//   api.php?types=1              -> alle soorten plekken met hun groep (voor de filterlijst)

header('Content-Type: application/json; charset=utf-8');

const MIN_JAAR = 1832;
const MAX_JAAR = 2026;

// Hulpfunctie: haal een geheel getal uit de URL, of geef een standaardwaarde
function leesGetal(string $naam, int $standaard, int $min, int $max): int {
    $waarde = filter_input(INPUT_GET, $naam, FILTER_VALIDATE_INT);
    if ($waarde === false || $waarde === null) {
        return $standaard;
    }
    return max($min, min($max, $waarde)); // binnen de grenzen houden
}

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=vlodrop;charset=utf8mb4',
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $jaar = leesGetal('jaar', MAX_JAAR, MIN_JAAR, MAX_JAAR);

    if (isset($_GET['types'])) {
        // ---- Alle soorten plekken, met groep en aantal (voor de filterlijst) ----
        $stmt = $pdo->query(
            'SELECT types.groep, types.naam AS type, COUNT(plekken.id) AS aantal
             FROM types
             JOIN plekken ON plekken.type_id = types.id
             GROUP BY types.id, types.groep, types.naam
             ORDER BY types.groep, types.naam'
        );
        $rijen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rijen as &$r) { $r['aantal'] = (int) $r['aantal']; }
        unset($r);
        echo json_encode($rijen);

    } elseif (isset($_GET['jaren'])) {
        // ---- Jaartallen waarin iets is gebeurd (voor de streepjes op de tijdlijn) ----
        $stmt = $pdo->prepare(
            'SELECT jaar, COUNT(*) AS aantal
             FROM geschiedenis
             WHERE jaar BETWEEN :min AND :max
             GROUP BY jaar
             ORDER BY jaar'
        );
        $stmt->execute([':min' => MIN_JAAR, ':max' => MAX_JAAR]);
        $jaren = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jaren as &$j) {
            $j['jaar'] = (int) $j['jaar'];
            $j['aantal'] = (int) $j['aantal'];
        }
        unset($j);
        echo json_encode($jaren);

    } elseif (isset($_GET['plek'])) {
        // ---- Details van één plek ----
        $plekId = leesGetal('plek', 0, 0, PHP_INT_MAX);

        $stmt = $pdo->prepare(
            'SELECT plekken.id, plekken.naam, types.naam AS type, types.groep AS groep,
                    plekken.van_jaar, plekken.tot_jaar, plekken.jaar_onzeker,
                    plekken.beschrijving, plekken.adres, plekken.coord_geschat,
                    plekken.website, plekken.wikipedia,
                    plekken.facebook, plekken.instagram, plekken.linkedin, plekken.tiktok,
                    plekken.bag_bouwjaar,
                    plekken.lat, plekken.lon,
                    bronnen.titel AS plek_bron, bronnen.url AS plek_bron_url
             FROM plekken
             JOIN types ON plekken.type_id = types.id
             LEFT JOIN bronnen ON plekken.bron_id = bronnen.id
             WHERE plekken.id = :id'
        );
        $stmt->execute([':id' => $plekId]);
        $plek = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plek) {
            http_response_code(404);
            echo json_encode(['fout' => 'Plek niet gevonden']);
            exit;
        }

        // Alle gebeurtenissen; 'later' = na het gekozen jaar (wordt grijs getoond)
        $stmt = $pdo->prepare(
            'SELECT geschiedenis.jaar, geschiedenis.tekst,
                    bronnen.titel AS bron, bronnen.url AS bron_url
             FROM geschiedenis
             LEFT JOIN bronnen ON geschiedenis.bron_id = bronnen.id
             WHERE geschiedenis.plek_id = :id
             ORDER BY geschiedenis.jaar, geschiedenis.id'
        );
        $stmt->execute([':id' => $plekId]);
        $gebeurtenissen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($gebeurtenissen as &$g) {
            $g['jaar'] = (int) $g['jaar'];
            $g['later'] = $g['jaar'] > $jaar;
        }
        unset($g);
        $plek['geschiedenis'] = $gebeurtenissen;

        $plek['id'] = (int) $plek['id'];
        $plek['van_jaar'] = (int) $plek['van_jaar'];
        $plek['tot_jaar'] = $plek['tot_jaar'] === null ? null : (int) $plek['tot_jaar'];
        $plek['lat'] = (float) $plek['lat'];
        $plek['lon'] = (float) $plek['lon'];
        $plek['coord_geschat'] = (int) $plek['coord_geschat'];
        $plek['jaar_onzeker'] = (int) $plek['jaar_onzeker'];
        echo json_encode($plek);

    } else {
        // ---- Alle plekken die in het gekozen jaar bestonden ----
        $stmt = $pdo->prepare(
            'SELECT plekken.id, plekken.naam, types.naam AS type, types.groep AS groep,
                    plekken.lat, plekken.lon,
                    plekken.van_jaar, plekken.tot_jaar, plekken.jaar_onzeker
             FROM plekken
             JOIN types ON plekken.type_id = types.id
             WHERE plekken.van_jaar <= :jaar1
               AND (plekken.tot_jaar IS NULL OR plekken.tot_jaar >= :jaar2)'
        );
        $stmt->execute([':jaar1' => $jaar, ':jaar2' => $jaar]);

        $plekken = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Coordinaten als getal (PDO geeft DECIMAL als tekst terug)
        foreach ($plekken as &$p) {
            $p['lat'] = (float) $p['lat'];
            $p['lon'] = (float) $p['lon'];
            $p['jaar_onzeker'] = (int) $p['jaar_onzeker'];
        }
        unset($p);

        echo json_encode($plekken);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['fout' => 'Database-fout: ' . $e->getMessage()]);
}
