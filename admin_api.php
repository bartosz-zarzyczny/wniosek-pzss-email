<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/lib/StartLookup.php';
require_once __DIR__ . '/lib/Schema.php';
require_once __DIR__ . '/lib/MzssImporter.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Niedozwolona metoda.']);
}
$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
    respond(400, ['ok' => false, 'error' => 'Sesja wygasła. Odśwież stronę.']);
}
if (empty($_SESSION['admin_id'])) {
    respond(401, ['ok' => false, 'error' => 'Zaloguj się ponownie.']);
}

set_time_limit(60);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

try {
    $pdo = StartLookup::connect();
    Schema::ensure($pdo);
    $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE id = :id');
    $exists->execute(['id' => (int) $_SESSION['admin_id']]);
    if (!$exists->fetchColumn()) {
        respond(401, ['ok' => false, 'error' => 'Zaloguj się ponownie.']);
    }

    $settings = json_decode((string) file_get_contents(__DIR__ . '/settings.json'), true);
    if (!is_array($settings)) {
        throw new RuntimeException('Nieprawidłowy settings.json.');
    }
    $importer = new MzssImporter($pdo, $settings, __DIR__ . '/download_pdf');

    if ($action === 'python') {
        respond(200, ['ok' => true] + $importer->checkPython());
    }

    if ($action === 'scan') {
        $year = (int) ($_POST['year'] ?? 0);
        $scan = $importer->scan($year >= 2000 && $year <= 2100 ? $year : null);
        $_SESSION['mzss_items'] = [];
        foreach ($scan['items'] as $item) {
            $_SESSION['mzss_items'][$item['url']] = $item;
        }
        respond(200, ['ok' => true, 'year' => $scan['year'], 'items' => $scan['items']]);
    }

    if ($action === 'process') {
        $url = is_string($_POST['url'] ?? null) ? $_POST['url'] : '';
        $item = $_SESSION['mzss_items'][$url] ?? null;
        if (!is_array($item)) {
            respond(422, ['ok' => false, 'error' => 'Nieznany plik. Wykonaj skanowanie ponownie.']);
        }
        session_write_close();
        try {
            $result = $importer->process($item, ($_POST['force'] ?? '') === '1');
        } catch (RuntimeException $exception) {
            $result = ['status' => 'error', 'message' => $exception->getMessage()];
            $pdo->prepare(
                "INSERT INTO pdf_files (url, file_name, status, message) VALUES (:u, :n, 'error', :m)
                 ON DUPLICATE KEY UPDATE status = 'error', message = VALUES(message)"
            )->execute(['u' => $url, 'n' => (string) $item['file'], 'm' => mb_substr($exception->getMessage(), 0, 250, 'UTF-8')]);
        }
        respond(200, ['ok' => true] + $result);
    }

    respond(400, ['ok' => false, 'error' => 'Nieznana akcja.']);
} catch (Throwable $exception) {
    error_log('Panel admina (API): ' . $exception->getMessage());
    respond(500, ['ok' => false, 'error' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Błąd serwera.']);
}
