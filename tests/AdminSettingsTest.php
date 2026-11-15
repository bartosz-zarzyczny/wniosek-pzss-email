<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/AdminSettings.php';

function expectAdminSettings(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$legacyUrl = 'https://example.com/calendar';
expectAdminSettings(
    AdminSettings::calendarUrls(['calendar_url' => $legacyUrl]) === [$legacyUrl],
    'Stary pojedynczy adres kalendarza powinien pozostać obsługiwany.'
);
expectAdminSettings(
    AdminSettings::parseRecipients(" one@example.com \n\none@example.com\n two@example.com ")
        === ['one@example.com', 'two@example.com'],
    'Adresy odbiorców powinny być przycinane, deduplikowane i pomijać puste wiersze.'
);
expectAdminSettings(
    AdminSettings::parseCalendarUrls("https://example.com/calendar\nhttps://example.org/events")
        === ['https://example.com/calendar', 'https://example.org/events'],
    'Lista kalendarzy powinna akceptować kilka adresów HTTP/HTTPS.'
);
$yearRejected = false;
try {
    AdminSettings::parseLicenseYear('2100');
} catch (InvalidArgumentException) {
    $yearRejected = true;
}
expectAdminSettings(
    AdminSettings::parseLicenseYear('2020') === 2020 &&
        AdminSettings::parseLicenseYear('2099') === 2099 &&
        $yearRejected,
    'Rok licencji powinien być ograniczony do zakresu 2020–2099.'
);
$headerValues = AdminSettings::headerDefaults();
$headerValues['brand_title'] = 'Klub';
expectAdminSettings(
    AdminSettings::parseHeaderTexts($headerValues)['brand_title'] === 'Klub',
    'Teksty nagłówka powinny przechodzić walidację i być zapisywalne.'
);
expectAdminSettings(
    !AdminSettings::isValidCalendarUrl('https://user:pass@example.com/calendar') &&
        !AdminSettings::isValidCalendarUrl('https://example.com/calendar#section'),
    'Adresy kalendarzy nie powinny zawierać danych logowania ani fragmentu.'
);

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admin-settings-test-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Nie można utworzyć katalogu testowego.');
}

try {
    $path = $directory . DIRECTORY_SEPARATOR . 'settings.json';
    file_put_contents($path, "\xEF\xBB\xBF" . '{"license_year":2027,"calendar_url":"' . $legacyUrl . '"}');
    $settings = AdminSettings::load($path);
    $settings['calendar_urls'] = AdminSettings::calendarUrls($settings);
    unset($settings['calendar_url']);
    $settings['brand_title'] = 'Klub';
    AdminSettings::save($path, $settings);
    $saved = AdminSettings::load($path);

    expectAdminSettings(
        ($saved['license_year'] ?? null) === 2027 &&
            AdminSettings::calendarUrls($saved) === [$legacyUrl] &&
            ($saved['brand_title'] ?? null) === 'Klub' &&
            !array_key_exists('calendar_url', $saved),
        'Zapis listy kalendarzy powinien zachować pozostałe ustawienia i usunąć klucz legacy.'
    );
} finally {
    if (is_file($path ?? '')) {
        unlink($path);
    }
    rmdir($directory);
}

echo "Testy AdminSettings: OK\n";
