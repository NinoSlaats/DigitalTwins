<?php
// api.php
//   api.php?jaar=1880            -> alle plekken die in 1880 bestonden
//   api.php?plek=3&jaar=1880     -> details + geschiedenis van plek 3 tot en met 1880

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

    if (isset($_GET['plek'])) {
        // ---- Details van één plek ----
        $plekId = leesGetal('plek', 0, 0, PHP_INT_MAX);

        $stmt = $pdo->prepare(
            'SELECT plekken.id, plekken.naam, types.naam AS type,
                    plekken.van_jaar, plekken.tot_jaar, plekken.jaar_onzeker, plekken.beschrijving
             FROM plekken
             JOIN types ON plekken.type_id = types.id
             WHERE plekken.id = :id'
        );
        $stmt->execute([':id' => $plekId]);
        $plek = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plek) {
            http_response_code(404);
            echo json_encode(['fout' => 'Plek niet gevonden']);
            exit;
        }

        // Alleen geschiedenis tot en met het gekozen jaar
        $stmt = $pdo->prepare(
            'SELECT geschiedenis.jaar, geschiedenis.tekst,
                    bronnen.titel AS bron, bronnen.url AS bron_url
             FROM geschiedenis
             LEFT JOIN bronnen ON geschiedenis.bron_id = bronnen.id
             WHERE geschiedenis.plek_id = :id AND geschiedenis.jaar <= :jaar
             ORDER BY geschiedenis.jaar'
        );
        $stmt->execute([':id' => $plekId, ':jaar' => $jaar]);
        $plek['geschiedenis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $plek['jaar_onzeker'] = (int) $plek['jaar_onzeker'];
        echo json_encode($plek);
    } else {
        // ---- Alle plekken die in het gekozen jaar bestonden ----
        $stmt = $pdo->prepare(
            'SELECT plekken.id, plekken.naam, types.naam AS type,
                    plekken.lat, plekken.lon,
                    plekken.van_jaar, plekken.tot_jaar, plekken.jaar_onzeker, plekken.beschrijving
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
        }
        unset($p);

        echo json_encode($plekken);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['fout' => 'Database-fout: ' . $e->getMessage()]);
}
