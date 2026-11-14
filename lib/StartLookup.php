<?php
declare(strict_types=1);

final class StartLookup
{
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 600;
    private const FALLBACK_MAP = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'é' => 'e', 'è' => 'e', 'č' => 'c', 'ř' => 'r', 'š' => 's', 'ž' => 'z',
        'ý' => 'y', 'í' => 'i', 'á' => 'a', 'ů' => 'u', 'ě' => 'e', 'ň' => 'n', 'ť' => 't', 'ď' => 'd',
        'ø' => 'o', 'đ' => 'd', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];

    public static function normalizeName(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), self::FALLBACK_MAP);
        if (class_exists('Normalizer')) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $value = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    public static function connect(): PDO
    {
        $path = __DIR__ . '/../local.php';
        $config = is_file($path) ? require $path : null;
        if (!is_array($config)) {
            throw new RuntimeException('Brak konfiguracji bazy danych (local.php).');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['database'] ?? '')
        );
        return new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function registerAttempt(PDO $pdo, string $ip): bool
    {
        $hash = hash('sha256', $ip);
        $now = time();
        $pdo->prepare('DELETE FROM lookup_attempts WHERE attempted_at < :before')
            ->execute(['before' => date('Y-m-d H:i:s', $now - self::WINDOW_SECONDS)]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM lookup_attempts WHERE ip_hash = :hash');
        $count->execute(['hash' => $hash]);
        if ((int) $count->fetchColumn() >= self::MAX_ATTEMPTS) {
            return false;
        }
        $pdo->prepare('INSERT INTO lookup_attempts (ip_hash, attempted_at) VALUES (:hash, :at)')
            ->execute(['hash' => $hash, 'at' => date('Y-m-d H:i:s', $now)]);
        return true;
    }

    /** @return list<array{name:string,date:string,location:string,category:string,calendar:string,remarks:string}> */
    public static function find(PDO $pdo, string $license, string $lastName): array
    {
        if (!preg_match('/\d{3,6}/', $license, $match)) {
            return [];
        }
        $tokens = array_filter(explode(' ', self::normalizeName($lastName)), 'strlen');
        if ($tokens === []) {
            return [];
        }

        $statement = $pdo->prepare(
            'SELECT c.name, c.event_date, c.location, c.calendar, r.discipline, r.name_key, r.competition_name
             FROM competition_results r
             JOIN competitions c ON c.id = r.competition_id
             WHERE r.license_number = :license
             ORDER BY c.event_date, c.name, c.location, r.discipline, r.competition_name'
        );
        $statement->execute(['license' => $match[0]]);

        $starts = [];
        $seen = [];
        foreach ($statement->fetchAll() as $row) {
            $nameTokens = explode(' ', (string) $row['name_key']);
            if (array_diff($tokens, $nameTokens) !== []) {
                continue;
            }
            // Scal duplikaty importu, ale nie odrębne konkurencje tej samej dyscypliny.
            $key = $row['event_date'] . '|' . $row['name'] . '|' . $row['location'] . '|' .
                $row['discipline'] . '|' . $row['competition_name'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $starts[] = [
                'name' => (string) $row['name'],
                'date' => (string) $row['event_date'],
                'location' => (string) $row['location'],
                'category' => (string) $row['discipline'],
                'calendar' => (string) $row['calendar'],
                'remarks' => (string) $row['competition_name'],
            ];
        }
        return $starts;
    }
}
