<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/lib/StartLookup.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Niedozwolona metoda.']);
}

$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
    respond(400, ['ok' => false, 'error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$license = is_string($_POST['license'] ?? null) ? trim($_POST['license']) : '';
$lastName = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';
if ($license === '' || $lastName === '' || strlen($license) > 30 || mb_strlen($lastName, 'UTF-8') > 50 ||
    !preg_match('//u', $lastName)) {
    respond(422, ['ok' => false, 'error' => 'Podaj numer licencji i nazwisko.']);
}

try {
    $pdo = StartLookup::connect();
    if (!StartLookup::registerAttempt($pdo, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
        respond(429, ['ok' => false, 'error' => 'Zbyt wiele prób wyszukiwania. Spróbuj ponownie za kilka minut.']);
    }
    respond(200, ['ok' => true, 'starts' => StartLookup::find($pdo, $license, $lastName)]);
} catch (PDOException | RuntimeException $exception) {
    error_log('Wyszukiwanie startów: ' . $exception->getMessage());
    respond(503, ['ok' => false, 'error' => 'Wyszukiwanie startów jest chwilowo niedostępne. Wpisz starty ręcznie.']);
}
