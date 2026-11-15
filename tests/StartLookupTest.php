<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/StartLookup.php';

function expectStartLookup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE competitions (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        event_date TEXT NOT NULL,
        location TEXT NOT NULL,
        calendar TEXT NOT NULL,
        event_url TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE competition_results (
        competition_id INTEGER NOT NULL,
        discipline TEXT NOT NULL,
        license_number TEXT NULL,
        name_key TEXT NOT NULL,
        competition_name TEXT NOT NULL
    )'
);
$pdo->exec(
    "INSERT INTO competitions VALUES
        (1, 'Competition A', '2026-01-01', 'Krakow', 'PZSS', ''),
        (2, 'Competition B', '2026-01-02', 'Krakow', 'PZSS', ''),
        (3, 'Competition C', '2026-01-03', 'Krakow', 'PZSS', '')"
);
$pdo->exec(
    "INSERT INTO competition_results VALUES
        (1, 'rifle', NULL, 'zarzyczny bartosz', 'MINI RIFLE'),
        (2, 'pistol', '99999', 'zarzyczny bartosz', 'CLASSIC'),
        (3, 'pistol', '45862', 'different person', 'OPEN')"
);

$starts = StartLookup::find($pdo, '45862', 'Bartosz', 'Zarzyczny');
$remarks = array_column($starts, 'remarks');
sort($remarks);
expectStartLookup(
    $remarks === ['MINI RIFLE', 'OPEN'],
    'Wyszukiwanie po licencji powinno uwzględnić jej wyniki i rekordy bez licencji znalezione po nazwisku.'
);

$licenseOnlyStarts = StartLookup::find($pdo, '45862', '', '');
expectStartLookup(
    count($licenseOnlyStarts) === 1 && $licenseOnlyStarts[0]['remarks'] === 'OPEN',
    'Bez imienia i nazwiska wyszukiwanie powinno ograniczyć się do numeru licencji.'
);

echo "Testy StartLookup: OK\n";
