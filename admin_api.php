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

    if ($action === 'manual_import') {
        $upload = $_FILES['pdf'] ?? null;
        if (!is_array($upload)) {
            respond(422, ['ok' => false, 'error' => 'Wybierz plik PDF z wynikami.']);
        }
        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Plik przekracza limit rozmiaru dozwolony przez serwer.',
                UPLOAD_ERR_NO_FILE => 'Wybierz plik PDF z wynikami.',
                default => 'Nie udało się przesłać pliku. Spróbuj ponownie.',
            };
            respond(422, ['ok' => false, 'error' => $message]);
        }

        $tmpFile = $upload['tmp_name'] ?? null;
        $fileName = $upload['name'] ?? null;
        if (!is_string($tmpFile) || !is_uploaded_file($tmpFile) || !is_string($fileName)) {
            respond(422, ['ok' => false, 'error' => 'Nieprawidłowy plik przesłany do importu.']);
        }
        $fileName = basename(str_replace('\\', '/', $fileName));
        if ($fileName === '' || !preg_match('/\.pdf$/i', $fileName)) {
            respond(422, ['ok' => false, 'error' => 'Do importu wybierz plik z rozszerzeniem PDF.']);
        }
        $fileSize = filesize($tmpFile);
        $signature = file_get_contents($tmpFile, false, null, 0, 5);
        if (!is_int($fileSize) || $fileSize < 5 || $fileSize > 30 * 1024 * 1024 || $signature !== '%PDF-') {
            respond(422, ['ok' => false, 'error' => 'Plik musi być poprawnym PDF-em o rozmiarze do 30 MB.']);
        }

        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $date = is_string($_POST['date'] ?? null) ? trim($_POST['date']) : '';
        $location = is_string($_POST['location'] ?? null) ? trim($_POST['location']) : '';
        $calendar = is_string($_POST['calendar'] ?? null) ? trim($_POST['calendar']) : '';
        $dateFormatValid = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1;
        $dateValue = $dateFormatValid ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        $dateErrors = $dateFormatValid ? DateTimeImmutable::getLastErrors() : false;
        if ($title === '' || !preg_match('//u', $title) || mb_strlen($title, 'UTF-8') > 80) {
            respond(422, ['ok' => false, 'error' => 'Podaj nazwę zawodów (maksymalnie 80 znaków).']);
        }
        $dateHasErrors = $dateErrors !== false &&
            ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);
        if ($dateValue === false || $dateValue->format('Y-m-d') !== $date ||
            $dateHasErrors) {
            respond(422, ['ok' => false, 'error' => 'Podaj prawidłową datę zawodów.']);
        }
        if ($location === '' || !preg_match('//u', $location) || mb_strlen($location, 'UTF-8') > 60) {
            respond(422, ['ok' => false, 'error' => 'Podaj miejsce zawodów (maksymalnie 60 znaków).']);
        }
        if (!in_array($calendar, ['PZSS', 'WZSS', 'MZSS', 'other'], true)) {
            respond(422, ['ok' => false, 'error' => 'Wybierz prawidłowy kalendarz zawodów.']);
        }

        $allowedDisciplines = ['pistol', 'rifle', 'shotgun'];
        $postedDisciplines = $_POST['disciplines'] ?? [];
        if (!is_array($postedDisciplines)) {
            respond(422, ['ok' => false, 'error' => 'Nieprawidłowe dyscypliny.']);
        }
        $disciplines = [];
        foreach ($postedDisciplines as $discipline) {
            if (!is_string($discipline) || !in_array($discipline, $allowedDisciplines, true)) {
                respond(422, ['ok' => false, 'error' => 'Wybierz prawidłowe dyscypliny.']);
            }
            if (!in_array($discipline, $disciplines, true)) {
                $disciplines[] = $discipline;
            }
        }
        if (count($disciplines) > count($allowedDisciplines)) {
            respond(422, ['ok' => false, 'error' => 'Wybierz maksymalnie trzy dyscypliny.']);
        }

        session_write_close();
        $result = $importer->importManual($tmpFile, $fileName, [
            'title' => $title,
            'date' => $date,
            'location' => $location,
            'calendar' => $calendar,
            'flags' => $disciplines,
        ]);
        respond(200, ['ok' => true] + $result);
    }

    respond(400, ['ok' => false, 'error' => 'Nieznana akcja.']);
} catch (Throwable $exception) {
    error_log('Panel admina (API): ' . $exception->getMessage());
    respond(500, ['ok' => false, 'error' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Błąd serwera.']);
}
