<?php
declare(strict_types=1);

$secureCookie = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax',
]);
session_start();

$providedToken = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
$storedToken = (string) ($_SESSION['download_token'] ?? '');
$path = (string) ($_SESSION['download_path'] ?? '');
$expires = (int) ($_SESSION['download_expires'] ?? 0);
$year = (int) ($_SESSION['download_year'] ?? 2027);

if ($providedToken === '' || $storedToken === '' || !hash_equals($storedToken, $providedToken) ||
    $expires < time() || $path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Link do pobrania jest nieprawidłowy lub wygasł. Wygeneruj wniosek ponownie.';
    exit;
}

$pdf = file_get_contents($path);
if ($pdf === false) {
    error_log('Nie można odczytać tymczasowego PDF wniosku licencyjnego.');
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Nie udało się odczytać pliku PDF. Wygeneruj wniosek ponownie.';
    exit;
}

unlink($path);
unset(
    $_SESSION['download_token'],
    $_SESSION['download_path'],
    $_SESSION['download_expires'],
    $_SESSION['download_year']
);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="wniosek-licencja-' . $year . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo $pdf;
