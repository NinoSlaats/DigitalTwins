<?php
// importeer.php - slaat plekken op die importeer.html heeft opgehaald of die je handmatig invult.
// ALLEEN voor gebruik op je eigen laptop. Verwijder dit bestand (en importeer.html) voordat de site online gaat.

header('Content-Type: application/json; charset=utf-8');

// Alleen vanaf de eigen computer toestaan
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['fout' => 'Alleen lokaal toegestaan']);
    exit;
}

// Alleen POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['fout' => 'Gebruik POST']);
    exit;
}

$invoer = json_decode(file_get_contents('php://input'), true);
if (!is_array($invoer) || !isset($invoer['plekken']) || !is_array($invoer['plekken'])) {
    http_response_code(400);
    echo json_encode(['fout' => 'Ongeldige invoer']);
    exit;
}
if (count($invoer['plekken']) > 3000) {
    http_response_code(400);
    echo json_encode(['fout' => 'Te veel plekken in een keer (maximaal 3000)']);
    exit;
}

// Alleen https-links naar het juiste platform overnemen, anders leeg
function socialOfNull($url, string $domein) {
    $url = trim((string) $url);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $ok = parse_url($url, PHP_URL_SCHEME) === 'https'
        && ($host === $domein || substr($host, -strlen('.' . $domein)) === '.' . $domein);
    return $ok ? mb_substr($url, 0, 300) : null;
}

const TOEGESTANE_BRONNEN = [21, 22];   // 21 = OpenStreetMap, 22 = handmatig

try {
    $pdo = new PDO('mysql:host=localhost;dbname=vlodrop;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $zoekType  = $pdo->prepare('SELECT id FROM types WHERE naam = :naam');
    $maakType  = $pdo->prepare('INSERT INTO types (naam, groep) VALUES (:naam, :groep)');

    // Bestaat de plek al (zelfde osm_id)? Dan bijwerken in plaats van dubbel toevoegen.
    $opslaan = $pdo->prepare(
        'INSERT INTO plekken (naam, type_id, lat, lon, van_jaar, tot_jaar, jaar_onzeker, beschrijving, bron_id, osm_id, adres, website, wikipedia, facebook, instagram, linkedin, tiktok)
         VALUES (:naam, :type_id, :lat, :lon, 2026, NULL, 1, :beschrijving, :bron_id, :osm_id, :adres, :website, :wikipedia, :facebook, :instagram, :linkedin, :tiktok)
         ON DUPLICATE KEY UPDATE
           naam = VALUES(naam), type_id = VALUES(type_id), lat = VALUES(lat), lon = VALUES(lon),
           beschrijving = VALUES(beschrijving), bron_id = VALUES(bron_id), adres = VALUES(adres),
           website = COALESCE(website, VALUES(website)), wikipedia = COALESCE(wikipedia, VALUES(wikipedia)),
           facebook = COALESCE(facebook, VALUES(facebook)), instagram = COALESCE(instagram, VALUES(instagram)),
           linkedin = COALESCE(linkedin, VALUES(linkedin)), tiktok = COALESCE(tiktok, VALUES(tiktok))'
    );

    $pdo->beginTransaction();
    $toegevoegd = 0;
    $overgeslagen = 0;

    foreach ($invoer['plekken'] as $p) {
        $naam   = trim((string) ($p['naam'] ?? ''));
        $type   = trim((string) ($p['type'] ?? ''));
        $groep  = trim((string) ($p['groep'] ?? 'Overig'));
        $lat    = filter_var($p['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon    = filter_var($p['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        $bronId = (int) ($p['bron_id'] ?? 0);
        $osmId  = isset($p['osm_id']) && $p['osm_id'] !== '' ? substr((string) $p['osm_id'], 0, 30) : null;

        // Controleer de invoer; ongeldige regels slaan we over
        $geldig = $naam !== '' && $type !== ''
            && $lat !== false && $lon !== false
            && $lat > 51.10 && $lat < 51.20 && $lon > 6.00 && $lon < 6.20
            && in_array($bronId, TOEGESTANE_BRONNEN, true);
        if (!$geldig) { $overgeslagen++; continue; }

        // Soort opzoeken of aanmaken
        $zoekType->execute([':naam' => mb_substr($type, 0, 50)]);
        $typeId = $zoekType->fetchColumn();
        if (!$typeId) {
            $maakType->execute([':naam' => mb_substr($type, 0, 50), ':groep' => mb_substr($groep, 0, 40)]);
            $typeId = (int) $pdo->lastInsertId();
        }

        $opslaan->execute([
            ':naam' => mb_substr($naam, 0, 100),
            ':type_id' => $typeId,
            ':lat' => $lat,
            ':lon' => $lon,
            ':beschrijving' => mb_substr((string) ($p['beschrijving'] ?? ''), 0, 1000),
            ':bron_id' => $bronId,
            ':osm_id' => $osmId,
            ':website' => preg_match('#^https?://#i', (string) ($p['website'] ?? '')) ? mb_substr((string) $p['website'], 0, 300) : null,
            ':wikipedia' => preg_match('#^https://[a-z]{2,3}\.wikipedia\.org/#', (string) ($p['wikipedia'] ?? '')) ? mb_substr((string) $p['wikipedia'], 0, 300) : null,
            ':facebook' => socialOfNull($p['facebook'] ?? '', 'facebook.com'),
            ':instagram' => socialOfNull($p['instagram'] ?? '', 'instagram.com'),
            ':linkedin' => socialOfNull($p['linkedin'] ?? '', 'linkedin.com'),
            ':tiktok' => socialOfNull($p['tiktok'] ?? '', 'tiktok.com'),
            ':adres' => ($a = mb_substr(trim((string) ($p['adres'] ?? '')), 0, 120)) === '' ? null : $a,
        ]);
        $toegevoegd++;
    }

    $pdo->commit();
    echo json_encode(['opgeslagen' => $toegevoegd, 'overgeslagen' => $overgeslagen]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo json_encode(['fout' => 'Database-fout: ' . $e->getMessage()]);
}
