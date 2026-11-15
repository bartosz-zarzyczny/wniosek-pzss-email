<?php
declare(strict_types=1);

final class AdminSettings
{
    private const MAX_EMAIL_ADDRESSES = 20;
    private const MAX_CALENDAR_URLS = 20;
    private const HEADER_DEFAULTS = [
        'brand_aria_label' => 'Formularz wniosku licencyjnego',
        'brand_mark' => 'Z',
        'brand_title' => 'ZSD',
        'brand_subtitle' => 'STREFA ZAWODNIKA',
        'header_label' => 'LICENCJA ZAWODNICZA',
    ];
    private const HEADER_MAX_LENGTHS = [
        'brand_aria_label' => 100,
        'brand_mark' => 4,
        'brand_title' => 40,
        'brand_subtitle' => 60,
        'header_label' => 80,
    ];

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Nie można odczytać settings.json.');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Nie można odczytać settings.json.');
        }
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }
        $settings = json_decode($contents, true);
        if (!is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Plik settings.json nie zawiera poprawnej konfiguracji JSON.');
        }
        return $settings;
    }

    /** @param array<string,mixed> $settings
     *  @return list<string>
     */
    public static function recipients(array $settings): array
    {
        $recipients = $settings['recipients'] ?? [];
        if (!is_array($recipients)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $recipients),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /** @param array<string,mixed> $settings
     *  @return list<string>
     */
    public static function calendarUrls(array $settings): array
    {
        $urls = $settings['calendar_urls'] ?? null;
        if (!is_array($urls)) {
            $legacyUrl = $settings['calendar_url'] ?? null;
            $urls = is_string($legacyUrl) ? [$legacyUrl] : [];
        }
        return array_values(array_filter(
            array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $urls),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /** @return list<string> */
    public static function parseRecipients(string $value): array
    {
        $recipients = self::lines($value);
        $recipients = array_values(array_unique($recipients));
        if ($recipients === [] || count($recipients) > self::MAX_EMAIL_ADDRESSES) {
            throw new InvalidArgumentException('Podaj od 1 do 20 adresów odbiorców, po jednym w wierszu.');
        }
        foreach ($recipients as $recipient) {
            if (strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Każdy adres odbiorcy musi być poprawnym adresem e-mail.');
            }
        }
        return $recipients;
    }

    public static function parseSender(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Podaj poprawny adres nadawcy albo pozostaw pole puste.');
        }
        return $value;
    }

    public static function parseLicenseYear(string $value): int
    {
        $year = filter_var(trim($value), FILTER_VALIDATE_INT);
        if ($year === false || $year < 2020 || $year > 2099) {
            throw new InvalidArgumentException('Rok licencji musi być liczbą z zakresu 2020–2099.');
        }
        return $year;
    }

    /** @return array<string,string> */
    public static function headerDefaults(): array
    {
        return self::HEADER_DEFAULTS;
    }

    /** @param array<string,mixed> $settings
     *  @return array<string,string>
     */
    public static function headerTexts(array $settings): array
    {
        $texts = [];
        foreach (self::HEADER_DEFAULTS as $key => $default) {
            $value = $settings[$key] ?? null;
            $texts[$key] = is_string($value) && trim($value) !== ''
                ? trim($value)
                : $default;
        }
        return $texts;
    }

    /** @param array<string,string> $values
     *  @return array<string,string>
     */
    public static function parseHeaderTexts(array $values): array
    {
        $texts = [];
        foreach (self::HEADER_MAX_LENGTHS as $key => $maxLength) {
            $value = is_string($values[$key] ?? null) ? trim($values[$key]) : '';
            $characterCount = preg_match_all('/./us', $value, $matches);
            if ($value === '' || $characterCount === false || $characterCount > $maxLength ||
                preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
                throw new InvalidArgumentException('Uzupełnij wszystkie pola nagłówka tekstem o dozwolonej długości.');
            }
            $texts[$key] = $value;
        }
        return $texts;
    }

    /** @return list<string> */
    public static function parseCalendarUrls(string $value): array
    {
        $urls = array_values(array_unique(self::lines($value)));
        if ($urls === [] || count($urls) > self::MAX_CALENDAR_URLS) {
            throw new InvalidArgumentException('Podaj od 1 do 20 adresów kalendarzy, po jednym w wierszu.');
        }
        foreach ($urls as $url) {
            if (!self::isValidCalendarUrl($url)) {
                throw new InvalidArgumentException('Każdy adres kalendarza musi być poprawnym linkiem HTTP lub HTTPS.');
            }
        }
        return $urls;
    }

    public static function isValidCalendarUrl(string $url): bool
    {
        $parts = parse_url($url);
        return strlen($url) <= 2048 &&
            filter_var($url, FILTER_VALIDATE_URL) !== false &&
            is_array($parts) &&
            in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) &&
            !empty($parts['host']) &&
            !isset($parts['user']) &&
            !isset($parts['pass']) &&
            !isset($parts['fragment']);
    }

    /** @param array<string,mixed> $settings */
    public static function save(string $path, array $settings): void
    {
        try {
            $contents = json_encode(
                $settings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException('Nie można zakodować ustawień do JSON.', 0, $exception);
        }

        $temporary = tempnam(dirname($path), '.settings-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Nie można utworzyć pliku tymczasowego do zapisu ustawień.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Nie można zapisać ustawień. Sprawdź uprawnienia do plików.');
            }
            $permissions = is_file($path) ? fileperms($path) : false;
            if ($permissions !== false && !chmod($temporary, $permissions & 0777)) {
                throw new RuntimeException('Nie można zachować uprawnień pliku settings.json.');
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Nie można zastąpić pliku settings.json. Sprawdź uprawnienia do plików.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return list<string> */
    private static function lines(string $value): array
    {
        $lines = preg_split('/\R/u', $value);
        if (!is_array($lines)) {
            throw new InvalidArgumentException('Podane ustawienia zawierają nieprawidłowy tekst.');
        }
        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== ''
        ));
    }
}
