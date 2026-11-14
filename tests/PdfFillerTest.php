<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/PdfFiller.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$applicant = [
    'patent' => '',
    'license' => 'TEST',
    'pesel' => '',
    'phone' => '',
    'last_name' => 'Żak',
    'first_name' => 'Jan',
    'application_place' => 'Kraków',
    'application_date_display' => '13.11.2026',
];
$names = [
    'Mistrzostwa Krakowa',
    'Międzynarodowe Mistrzostwa Strzelectwa Sportowego o Puchar Prezydenta Miasta',
    str_repeat('W', 80),
    'Zawody lokalne',
];
$calendars = ['PZSS', 'WZSS', 'MZSS', 'other'];
$longRemarks = str_repeat('Długa nazwa konkurencji ', 5);
$competitions = [];
foreach ($names as $index => $name) {
    $competitions[] = [
        'name' => $name,
        'date_display' => '01.06.2026',
        'location' => 'Kraków',
        'categories' => ['pistol'],
        'calendar' => $calendars[$index],
        'remarks' => $index === 0 ? $longRemarks : '',
    ];
}

$pdf = (new PdfFiller())->fill(
    __DIR__ . '/../wniosek-o-przedluzenie-licencji-zawodniczej.pdf',
    __DIR__ . '/../assets/SourceSans3-Regular.ttf',
    $applicant,
    $competitions,
    2027,
    ['pistol']
);

expect(preg_match('/(?:^|\r?\n)45 0 obj\s*<<.*?>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $cmap) === 1,
    'Brak mapy Unicode w PDF.');
preg_match_all('/^<([0-9A-F]{4})> <([0-9A-F]{4})>$/m', $cmap[1], $mapping, PREG_SET_ORDER);
$glyphs = [];
foreach ($mapping as $entry) {
    $glyphs[$entry[1]] = html_entity_decode('&#' . hexdec($entry[2]) . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
expect(preg_match('/(?:^|\r?\n)47 0 obj\s*<<.*?>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $content) === 1,
    'Brak zawartości formularza w PDF.');
preg_match_all('/^BT \/ZSD [\d.]+ Tf 1 0 0 1 ([\d.]+) ([\d.]+) Tm <([0-9A-F]+)> Tj ET$/m',
    $content[1], $textCommands, PREG_SET_ORDER);
$rows = [462.72, 433.80, 405.00, 376.08];
$nameLines = array_fill(0, 4, []);
$calendarText = array_fill(0, 4, '');
$remarkLines = array_fill(0, 4, []);
foreach ($textCommands as $command) {
    $x = (float) $command[1];
    $y = (float) $command[2];
    $text = '';
    foreach (str_split($command[3], 4) as $glyph) {
        expect(isset($glyphs[$glyph]), 'Brak mapowania znaku w PDF.');
        $text .= $glyphs[$glyph];
    }
    foreach ($rows as $index => $baseline) {
        if (abs($y - $baseline) <= 7.01 && $x === 86.0) {
            $nameLines[$index][] = $text;
        }
        if (abs($y - $baseline) < 0.01 && $x === 424.0) {
            $calendarText[$index] = $text;
        }
        if (abs($y - $baseline) <= 10 && $x === 468.0) {
            $remarkLines[$index][] = $text;
        }
    }
}

expect(count($nameLines[0]) === 1 && $nameLines[0][0] === $names[0], 'Krótka nazwa powinna mieścić się w jednym wierszu.');
expect(count($nameLines[1]) >= 2 && count($nameLines[1]) <= 3 &&
    implode(' ', $nameLines[1]) === $names[1], 'Długa nazwa powinna być łamana po spacjach bez utraty treści.');
expect(count($nameLines[2]) >= 2 && count($nameLines[2]) <= 3 &&
    implode('', $nameLines[2]) === $names[2], 'Długi wyraz powinien mieścić się w PDF bez utraty znaków.');
expect($calendarText === ['PZSS', 'WZSS', 'MZSS', '-'], 'Nieprawidłowe oznaczenia kalendarzy w PDF.');
expect(implode(' ', $remarkLines[0]) === trim($longRemarks), 'Długie uwagi powinny mieścić się w PDF bez utraty treści.');

echo "Testy PdfFiller: OK\n";
