<?php
declare(strict_types=1);

$secureCookie = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Lax',
]);
session_start();
cleanupTemporaryPdfs();

require_once __DIR__ . '/lib/PdfFiller.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cleanText($value, int $maxLength, string $label, array &$errors): string
{
    if (!is_string($value)) {
        return '';
    }
    if (!preg_match('//u', $value)) {
        $errors[] = $label . ' zawiera nieprawidłowe znaki.';
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim($value));
    if (!is_string($value)) {
        return '';
    }
    preg_match_all('/./us', $value, $characters);
    if (count($characters[0]) > $maxLength) {
        $errors[] = $label . ' może mieć maksymalnie ' . $maxLength . ' znaków.';
    }
    return implode('', array_slice($characters[0], 0, $maxLength));
}

function textLength(string $value): int
{
    preg_match_all('/./us', $value, $characters);
    return count($characters[0]);
}

function readSettings(): array
{
    $path = __DIR__ . '/settings.json';
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Nie można odczytać settings.json.');
    }
    $settings = json_decode($contents, true);
    if (!is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Plik settings.json nie zawiera poprawnej konfiguracji JSON.');
    }
    return $settings;
}

function defaultCompetition(): array
{
    return [
        'name' => '',
        'date' => '',
        'location' => '',
        'categories' => [],
        'in_mzss' => true,
        'calendar' => '',
        'url' => '',
        'remarks' => '',
    ];
}

function validateDate(string $value, string $label, array &$errors): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($date === false || $date->format('Y-m-d') !== $value ||
        ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        $errors[] = $label . ': podaj prawidłową datę.';
        return null;
    }
    return $date;
}

function reserveMailAttempt(int $limitSeconds, string &$error): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
        'zsd-license-' . hash('sha256', $ip) . '.lock';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        $error = 'Nie można zweryfikować limitu wysyłek. Spróbuj ponownie później.';
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        $error = 'Nie można zweryfikować limitu wysyłek. Spróbuj ponownie później.';
        return false;
    }

    rewind($handle);
    $lastAttempt = (int) stream_get_contents($handle);
    $remaining = $limitSeconds - (time() - $lastAttempt);
    if ($lastAttempt > 0 && $remaining > 0) {
        flock($handle, LOCK_UN);
        fclose($handle);
        $error = 'Ze względów bezpieczeństwa kolejną wiadomość można wysłać za ' . $remaining . ' s.';
        return false;
    }

    rewind($handle);
    $timestamp = (string) time();
    if (!ftruncate($handle, 0) || fwrite($handle, $timestamp) !== strlen($timestamp) || !fflush($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        $error = 'Nie można zapisać limitu wysyłek. Spróbuj ponownie później.';
        return false;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}

function cleanupTemporaryPdfs(): void
{
    $now = time();
    $activePath = (string) ($_SESSION['download_path'] ?? '');
    $expires = (int) ($_SESSION['download_expires'] ?? 0);
    if ($activePath !== '' && $expires > 0 && $expires <= $now && is_file($activePath)) {
        if (!unlink($activePath)) {
            error_log('Nie można usunąć wygasłego tymczasowego PDF wniosku.');
        }
        unset($_SESSION['download_path'], $_SESSION['download_token'], $_SESSION['download_expires'], $_SESSION['download_year']);
    }

    $files = glob(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'zsdlic-pdf-*');
    if ($files !== false) {
        foreach ($files as $file) {
            $modifiedAt = is_file($file) ? filemtime($file) : false;
            if ($modifiedAt !== false && $modifiedAt < $now - 1800 && !unlink($file)) {
                error_log('Nie można usunąć starego tymczasowego PDF wniosku.');
            }
        }
    }

    $lockFiles = glob(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'zsd-license-*.lock');
    if ($lockFiles !== false) {
        foreach ($lockFiles as $file) {
            $modifiedAt = is_file($file) ? filemtime($file) : false;
            if ($modifiedAt !== false && $modifiedAt < $now - 86400 && !unlink($file)) {
                error_log('Nie można usunąć starego pliku limitu wysyłek.');
            }
        }
    }
}

function senderEmail(array $settings): string
{
    $configured = trim((string) ($settings['from_email'] ?? ''));
    if ($configured !== '') {
        if (filter_var($configured, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Adres from_email w settings.json jest nieprawidłowy.');
        }
        return $configured;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    $host = trim((string) $host, '[]');
    $fallback = 'formularz@' . $host;
    if ($host === '' || filter_var($fallback, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Uzupełnij poprawny adres nadawcy from_email w settings.json.');
    }
    return $fallback;
}

function buildEmailBody(array $applicant, array $competitions, int $year): string
{
    $name = $applicant['first_name'] . ' ' . $applicant['last_name'];
    $lines = [
        'Szanowni Państwo,',
        '',
        'w załączeniu przekazuję wniosek o przedłużenie ważności licencji zawodniczej na rok ' . $year . '.',
        '',
        'Dane zawodnika:',
        'Imię i nazwisko: ' . $name,
        'Numer licencji: ' . $applicant['license'],
        '',
        'Starty wykazane we wniosku:',
    ];
    if ($applicant['patent'] !== '') {
        array_splice($lines, 6, 0, ['Numer patentu: ' . $applicant['patent']]);
    }

    foreach ($competitions as $index => $competition) {
        $categories = implode(', ', array_map(function (string $category): string {
            $labels = ['pistol' => 'pistolet', 'rifle' => 'karabin', 'shotgun' => 'strzelba'];
            return $labels[$category];
        }, $competition['categories']));
        $lines[] = ($index + 1) . '. ' . $competition['name'] . ' — ' .
            $competition['date_display'] . ', ' . $competition['location'] .
            ' (dyscypliny: ' . $categories . '; kalendarz: ' .
            ($competition['calendar'] === 'other' ? 'inny lub brak' : $competition['calendar']) .
            '; zawody w MZSS: ' .
            ($competition['in_mzss'] ? 'tak' : 'nie') . ')';
        if (!$competition['in_mzss']) {
            $lines[] = '   Link do zawodów: ' . $competition['url'];
        }
        if ($competition['remarks'] !== '') {
            $lines[] = '   Uwagi: ' . $competition['remarks'];
        }
    }

    $lines[] = '';
    $lines[] = 'Uprzejmie proszę o weryfikację wniosku i dalsze procedowanie sprawy.';
    $lines[] = '';
    $lines[] = 'Z poważaniem,';
    $lines[] = $name;
    if ($applicant['phone'] !== '') {
        $lines[] = 'Telefon: ' . $applicant['phone'];
    }
    return implode("\r\n", $lines) . "\r\n";
}

$errors = [];
$settings = [];
$settingsError = '';
try {
    $settings = readSettings();
} catch (RuntimeException $exception) {
    $settingsError = $exception->getMessage();
}

$configuredYear = $settings['license_year'] ?? 2027;
$licenseYear = filter_var($configuredYear, FILTER_VALIDATE_INT);
if ($licenseYear === false || $licenseYear < 2020 || $licenseYear > 2099) {
    $settingsError = 'Pole license_year w settings.json musi zawierać rok z zakresu 2020–2099.';
    $licenseYear = 2027;
}

$form = [
    'first_name' => '',
    'last_name' => '',
    'patent' => '',
    'license' => '',
    'pesel' => '',
    'phone' => '',
    'application_place' => '',
    'application_date' => date('Y-m-d'),
];
$competitions = array_fill(0, 8, defaultCompetition());
$categoryLabels = ['pistol' => 'Pistolet', 'rifle' => 'Karabin', 'shotgun' => 'Strzelba'];
$calendarLabels = ['PZSS' => 'PZSS', 'WZSS' => 'WZSS', 'MZSS' => 'MZSS', 'other' => 'Inny / brak'];
$selectedDisciplines = [];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$captchaQuestions = [
    ['Broń krótka w dynamice o kalibrze 9x19', 'pistolet'],
    ['Lubi dalszy dystans', 'karabin'],
    ['Wystrzeli dużo małych kulek', 'strzelba'],
    ['Ile nabojów mieści się w pełnym magazynku 5-strzałowego rewolweru? Podaj cyfrę', '5'],
    ['Strzał obok celu to…', 'miss'],
    ['Chronią uszy na strzelnicy', 'suchawki'],
    ['Chronią oczy na strzelnicy', 'okulary'],
    ['Papierowy cel, do którego się strzela', 'tarcza'],
];

function normalizeCaptcha(string $value): string
{
    $value = strtr(mb_strtolower(trim($value), 'UTF-8'), [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ]);
    return $value;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($isPost) {
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) ||
        !hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
        http_response_code(400);
    }
    if (!empty($_POST['website'])) {
        $errors[] = 'Nie udało się przyjąć formularza.';
        http_response_code(400);
    }

    $postedApplicant = isset($_POST['applicant']) && is_array($_POST['applicant'])
        ? $_POST['applicant']
        : [];
    foreach (array_keys($form) as $key) {
        $limits = [
            'first_name' => 50,
            'last_name' => 50,
            'patent' => 30,
            'license' => 30,
            'pesel' => 11,
            'phone' => 24,
            'application_place' => 60,
            'application_date' => 10,
        ];
        $labels = [
            'first_name' => 'Imię',
            'last_name' => 'Nazwisko',
            'patent' => 'Numer patentu',
            'license' => 'Numer licencji',
            'pesel' => 'PESEL',
            'phone' => 'Numer telefonu',
            'application_place' => 'Miejscowość',
            'application_date' => 'Data wniosku',
        ];
        $form[$key] = cleanText($postedApplicant[$key] ?? '', $limits[$key], $labels[$key], $errors);
    }

    $postedDisciplines = isset($_POST['disciplines']) && is_array($_POST['disciplines'])
        ? $_POST['disciplines'] : [];
    foreach ($postedDisciplines as $discipline) {
        if (!is_string($discipline) || !isset($categoryLabels[$discipline]) ||
            in_array($discipline, $selectedDisciplines, true)) {
            $errors[] = 'Wybierz prawidłowe dyscypliny.';
            continue;
        }
        $selectedDisciplines[] = $discipline;
    }
    if ($selectedDisciplines === [] || count($selectedDisciplines) > 3) {
        $errors[] = 'Wybierz od jednej do trzech dyscyplin.';
    }
    $allowedRows = $selectedDisciplines === [] ? 0 : 4 + 2 * (count($selectedDisciplines) - 1);
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if (!in_array($action, ['send', 'download'], true)) {
        $errors[] = 'Wybierz wysłanie lub pobranie wniosku.';
    }

    $postedCompetitions = isset($_POST['competitions']) && is_array($_POST['competitions'])
        ? $_POST['competitions']
        : [];
    foreach ($postedCompetitions as $postedIndex => $postedRow) {
        if ((!is_int($postedIndex) || $postedIndex < 0 || $postedIndex >= $allowedRows) &&
            is_array($postedRow) && array_filter(
                array_intersect_key($postedRow, array_flip(['name', 'date', 'location', 'category', 'calendar', 'url', 'remarks'])),
                function ($value): bool {
                    return !is_string($value) || trim($value) !== '';
                }
            ) !== []) {
            $errors[] = 'Liczba startów przekracza limit dla wybranych dyscyplin.';
            break;
        }
    }
    foreach ($competitions as $index => $default) {
        $row = isset($postedCompetitions[$index]) && is_array($postedCompetitions[$index])
            ? $postedCompetitions[$index]
            : [];
        $category = isset($row['category']) && is_string($row['category']) ? $row['category'] : '';
        $categories = $category === '' ? [] : [$category];
        $competitions[$index] = [
            'name' => cleanText($row['name'] ?? '', 80, 'Nazwa zawodów w starcie ' . ($index + 1), $errors),
            'date' => cleanText($row['date'] ?? '', 10, 'Data startu ' . ($index + 1), $errors),
            'location' => cleanText($row['location'] ?? '', 60, 'Miejsce w starcie ' . ($index + 1), $errors),
            'categories' => array_values(array_unique($categories)),
            'in_mzss' => isset($row['in_mzss']) && (string) $row['in_mzss'] === '1',
            'calendar' => isset($row['calendar']) && is_string($row['calendar']) ? $row['calendar'] : '',
            'url' => cleanText($row['url'] ?? '', 250, 'Adres URL w starcie ' . ($index + 1), $errors),
            'remarks' => cleanText($row['remarks'] ?? '', 60, 'Uwagi w starcie ' . ($index + 1), $errors),
        ];
    }

    if ($form['first_name'] === '' || textLength($form['first_name']) > 50) {
        $errors[] = 'Podaj imię (maksymalnie 50 znaków).';
    }
    if ($form['last_name'] === '' || textLength($form['last_name']) > 50) {
        $errors[] = 'Podaj nazwisko (maksymalnie 50 znaków).';
    }
    if ($form['license'] === '' || textLength($form['license']) > 30) {
        $errors[] = 'Podaj numer posiadanej licencji (maksymalnie 30 znaków).';
    }
    if ($form['pesel'] !== '' && !preg_match('/^\d{11}$/', $form['pesel'])) {
        $errors[] = 'PESEL musi składać się z 11 cyfr.';
    }
    if ($form['phone'] !== '' && !preg_match('/^[0-9+()\s-]{6,24}$/', $form['phone'])) {
        $errors[] = 'Podaj prawidłowy numer telefonu.';
    }
    if ($form['application_place'] === '' || textLength($form['application_place']) > 60) {
        $errors[] = 'Podaj miejscowość (maksymalnie 60 znaków).';
    }
    $applicationDate = validateDate($form['application_date'], 'Data wniosku', $errors);
    if (empty($_POST['declaration'])) {
        $errors[] = 'Potwierdź prawdziwość danych i zapoznanie się z oświadczeniami we wniosku.';
    }
    if (empty($_POST['privacy_consent'])) {
        $errors[] = 'Potwierdź zapoznanie się z informacją o przetwarzaniu danych we wniosku.';
    }

    $validCompetitions = [];
    foreach ($competitions as $index => $competition) {
        $hasData = $competition['name'] !== '' || $competition['date'] !== '' ||
            $competition['location'] !== '' || $competition['categories'] !== [] ||
            $competition['calendar'] !== '' || $competition['url'] !== '' || $competition['remarks'] !== '';
        if (!$hasData) {
            continue;
        }

        $rowNumber = $index + 1;
        if ($competition['name'] === '') {
            $errors[] = 'Start ' . $rowNumber . ': podaj nazwę zawodów.';
        }
        if ($competition['location'] === '') {
            $errors[] = 'Start ' . $rowNumber . ': podaj miejsce zawodów.';
        }
        if (count($competition['categories']) !== 1 ||
            !in_array($competition['categories'][0], $selectedDisciplines, true)) {
            $errors[] = 'Start ' . $rowNumber . ': wybierz jedną z zaznaczonych dyscyplin.';
        }
        if (!isset($calendarLabels[$competition['calendar']])) {
            $errors[] = 'Start ' . $rowNumber . ': wybierz kalendarz zawodów.';
        }
        $date = validateDate($competition['date'], 'Data startu ' . $rowNumber, $errors);
        if (!$competition['in_mzss']) {
            $urlParts = parse_url($competition['url']);
            if (filter_var($competition['url'], FILTER_VALIDATE_URL) === false ||
                !is_array($urlParts) || !isset($urlParts['scheme'], $urlParts['host']) ||
                !in_array(strtolower($urlParts['scheme']), ['http', 'https'], true)) {
                $errors[] = 'Start ' . $rowNumber . ': podaj prawidłowy adres URL do zawodów spoza kalendarza MZSS.';
            }
        }

        if ($date !== null && $competition['name'] !== '' &&
            $competition['location'] !== '' && count($competition['categories']) === 1 &&
            in_array($competition['categories'][0], $selectedDisciplines, true)) {
            $competition['date_display'] = $date->format('d.m.Y');
            $validCompetitions[] = $competition;
        }
    }
    if ($validCompetitions === []) {
        $errors[] = 'Dodaj co najmniej jeden start w zawodach.';
    }
    if ($action === 'send') {
        $captchaAnswer = isset($_POST['captcha']) && is_string($_POST['captcha']) ? $_POST['captcha'] : '';
        $captchaIndex = $_SESSION['captcha_index'] ?? null;
        unset($_SESSION['captcha_index']);
        if (!is_int($captchaIndex) || !isset($captchaQuestions[$captchaIndex]) ||
            normalizeCaptcha($captchaAnswer) !== $captchaQuestions[$captchaIndex][1]) {
            $errors[] = 'Nieprawidłowa odpowiedź na pytanie zabezpieczające.';
        }
    }

    if (empty($_POST['website']) && $errors === [] && $applicationDate !== null) {
        try {
            if ($settingsError !== '') {
                throw new RuntimeException($settingsError);
            }
            $pdfCompetitions = [];
            foreach ($validCompetitions as $competition) {
                $pdfCompetitions[] = [
                    'name' => $competition['name'],
                    'date_display' => $competition['date_display'],
                    'location' => $competition['location'],
                    'categories' => $competition['categories'],
                    'calendar' => $competition['calendar'],
                    'remarks' => $competition['remarks'],
                ];
            }
            $applicant = $form;
            $applicant['application_date_display'] = $applicationDate->format('d.m.Y');
            $pdf = (new PdfFiller())->fill(
                __DIR__ . '/wniosek-o-przedluzenie-licencji-zawodniczej.pdf',
                __DIR__ . '/assets/SourceSans3-Regular.ttf',
                $applicant,
                $pdfCompetitions,
                $licenseYear,
                $selectedDisciplines
            );

            if ($action === 'download') {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="wniosek-licencja-' . $licenseYear . '.pdf"');
                header('Content-Length: ' . strlen($pdf));
                header('Cache-Control: no-store, private');
                header('X-Content-Type-Options: nosniff');
                echo $pdf;
                exit;
            }

            $recipients = $settings['recipients'] ?? null;
            if (!is_array($recipients)) {
                throw new RuntimeException('Ustaw listę odbiorców w settings.json.');
            }
            foreach ($recipients as $address) {
                if (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('Każdy adres w recipients w settings.json musi być prawidłowy.');
                }
            }
            $recipients = array_values(array_unique($recipients));
            if ($recipients === []) {
                throw new RuntimeException('W settings.json musi być co najmniej jeden adres w recipients.');
            }
            $from = senderEmail($settings);
            $limitSeconds = max(10, min(3600, (int) ($settings['rate_limit_seconds'] ?? 30)));
            $rateLimitError = '';
            if (!reserveMailAttempt($limitSeconds, $rateLimitError)) {
                throw new RuntimeException($rateLimitError);
            }

            $temporaryPdf = tempnam(sys_get_temp_dir(), 'zsdlic-pdf-');
            if ($temporaryPdf === false || file_put_contents($temporaryPdf, $pdf) !== strlen($pdf)) {
                if (is_string($temporaryPdf) && is_file($temporaryPdf)) {
                    unlink($temporaryPdf);
                }
                throw new RuntimeException('Nie można przygotować pliku PDF do pobrania.');
            }

            $subject = 'Licencja ' . $licenseYear . ' – ' . $form['first_name'] . ' ' .
                strtr(strtoupper($form['last_name']), [
                    'ą' => 'Ą', 'ć' => 'Ć', 'ę' => 'Ę', 'ł' => 'Ł', 'ń' => 'Ń',
                    'ó' => 'Ó', 'ś' => 'Ś', 'ź' => 'Ź', 'ż' => 'Ż',
                ]);
            $boundary = '=_ZSD_' . bin2hex(random_bytes(16));
            $attachmentName = 'wniosek-licencja-' . $licenseYear . '.pdf';
            $mimeBody = '--' . $boundary . "\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
                quoted_printable_encode(buildEmailBody($form, $validCompetitions, $licenseYear)) . "\r\n" .
                '--' . $boundary . "\r\n" .
                'Content-Type: application/pdf; name="' . $attachmentName . '"' . "\r\n" .
                "Content-Transfer-Encoding: base64\r\n" .
                'Content-Disposition: attachment; filename="' . $attachmentName . '"' . "\r\n\r\n" .
                chunk_split(base64_encode($pdf)) .
                '--' . $boundary . "--\r\n";
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                'From: ' . '=?UTF-8?B?' . base64_encode('Formularz wniosku licencyjnego') . '?= <' . $from . '>',
                'Reply-To: ' . $from,
            ];
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $sent = true;
            foreach ($recipients as $recipient) {
                $sent = mail($recipient, $encodedSubject, $mimeBody, implode("\r\n", $headers)) && $sent;
            }
            if (!$sent) {
                unlink($temporaryPdf);
                error_log('Nie udało się wysłać wniosku licencyjnego przez PHP mail().');
                throw new RuntimeException('Serwer nie przyjął wiadomości e-mail. Sprawdź konfigurację poczty hostingu i spróbuj ponownie później.');
            }

            if (isset($_SESSION['download_path']) && is_file((string) $_SESSION['download_path'])) {
                unlink((string) $_SESSION['download_path']);
            }
            $_SESSION['download_token'] = bin2hex(random_bytes(24));
            $_SESSION['download_path'] = $temporaryPdf;
            $_SESSION['download_expires'] = time() + 900;
            $_SESSION['download_year'] = $licenseYear;
            session_regenerate_id(true);
            header('Location: index.php?sent=1', true, 303);
            exit;
        } catch (RuntimeException $exception) {
            error_log('Formularz licencyjny: ' . $exception->getMessage());
            $errors[] = $exception->getMessage();
        }
    }
}

$success = isset($_GET['sent']) && isset($_SESSION['download_token']) &&
    isset($_SESSION['download_expires']) && (int) $_SESSION['download_expires'] > time();
$downloadToken = $success ? (string) $_SESSION['download_token'] : '';
if (isset($_GET['sent']) && !$success) {
    unset($_SESSION['download_path'], $_SESSION['download_token'], $_SESSION['download_expires'], $_SESSION['download_year']);
}
$csrfToken = (string) $_SESSION['csrf_token'];
$captchaIndex = random_int(0, count($captchaQuestions) - 1);
$_SESSION['captcha_index'] = $captchaIndex;
$captchaQuestion = $captchaQuestions[$captchaIndex][0];
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1b1918">
    <title>Wniosek o przedłużenie licencji <?= h((string) $licenseYear) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="index.php" aria-label="Formularz wniosku licencyjnego">
            <span class="brand-mark" aria-hidden="true">Z</span>
            <span class="brand-copy"><strong>ZSD</strong><span>STREFA ZAWODNIKA</span></span>
        </a>
        <span class="header-label">LICENCJA ZAWODNICZA · <?= h((string) $licenseYear) ?></span>
    </div>
</header>

<main class="page-shell">
    <section class="intro">
        <p class="eyebrow"><span></span> WNIOSEK ONLINE</p>
        <h1>Przedłużenie licencji<br><em>zawodniczej</em></h1>
        <p class="intro-copy">Uzupełnij dane i starty w zawodach. Przygotowany wniosek PDF możesz pobrać lub przesłać na adresy zapisane w konfiguracji strony.</p>
        <div class="process-note">
            <span class="note-icon" aria-hidden="true">i</span>
            <p>Wygenerowany PDF nie zawiera odręcznego podpisu. Po pobraniu podpisz dokument, jeśli jest to wymagane przez klub.</p>
        </div>
    </section>

    <?php if ($settingsError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= h($settingsError) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert">
            <strong>Formularz wymaga poprawy:</strong>
            <ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <section class="success-card" aria-live="polite">
            <div class="success-mark" aria-hidden="true">✓</div>
            <p class="eyebrow">GOTOWE</p>
            <h2>Wniosek został wysłany</h2>
            <p>Wiadomość dotycząca licencji <?= h((string) $licenseYear) ?> została przekazana do serwera pocztowego.</p>
            <a class="button button-primary" href="download.php?token=<?= h($downloadToken) ?>">Pobierz wypełniony PDF</a>
            <p class="small-note">Link do pobrania jest ważny przez 15 minut. Podpisz dokument, jeśli wymaga tego procedura klubu.</p>
        </section>
    <?php else: ?>
        <form id="license-form" method="post" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="honeypot" aria-hidden="true">
                <label for="website">Nie wypełniaj tego pola</label>
                <input id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <section class="form-card">
                <div class="section-heading">
                    <span class="section-number">01</span>
                    <div><h2>Dane zawodnika</h2><p>Wpisz dane zgodne z patentem i aktualną licencją.</p></div>
                </div>
                <div class="form-grid">
                    <label class="field"><span>Imię <b>*</b></span>
                        <input name="applicant[first_name]" maxlength="50" autocomplete="given-name" required value="<?= h($form['first_name']) ?>">
                    </label>
                    <label class="field"><span>Nazwisko <b>*</b></span>
                        <input name="applicant[last_name]" maxlength="50" autocomplete="family-name" required value="<?= h($form['last_name']) ?>">
                    </label>
                    <label class="field"><span>Numer patentu strzeleckiego <small>(opcjonalnie)</small></span>
                        <input name="applicant[patent]" maxlength="30" value="<?= h($form['patent']) ?>">
                    </label>
                    <label class="field"><span>Numer posiadanej licencji <b>*</b></span>
                        <input name="applicant[license]" maxlength="30" required value="<?= h($form['license']) ?>">
                    </label>
                    <label class="field"><span>PESEL <small>(opcjonalnie)</small></span>
                        <input name="applicant[pesel]" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" autocomplete="off" value="<?= h($form['pesel']) ?>">
                        <small>11 cyfr, bez spacji i kresek</small>
                    </label>
                    <label class="field"><span>Numer telefonu <small>(opcjonalnie)</small></span>
                        <input name="applicant[phone]" type="tel" maxlength="24" autocomplete="tel" value="<?= h($form['phone']) ?>">
                    </label>
                </div>
            </section>

            <section class="form-card">
                <div class="section-heading">
                    <span class="section-number">02</span>
                    <div><h2>Dyscypliny</h2><p>Wybierz od jednej do trzech dyscyplin, w których przedłużasz licencję.</p></div>
                </div>
                <fieldset class="category-options" data-disciplines>
                    <legend>Dyscypliny <b>*</b></legend>
                    <?php foreach ($categoryLabels as $category => $label): ?>
                        <label class="check-option">
                            <input type="checkbox" name="disciplines[]" value="<?= h($category) ?>" <?= in_array($category, $selectedDisciplines, true) ? 'checked' : '' ?>>
                            <span><?= h($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="privacy-note">Pierwsza dyscyplina daje 4 miejsca na starty, każda następna dodaje 2. Po zmniejszeniu liczby dyscyplin ukryte starty nie zostaną wysłane ani zapisane w PDF.</p>
            </section>

            <section class="form-card" data-competitions-section>
                <div class="section-heading">
                    <span class="section-number">03</span>
                    <div><h2>Starty w zawodach</h2><p>Wpisz co najmniej jeden start. Dla każdych zawodów wybierz jedną z wybranych dyscyplin.</p></div>
                </div>
                <noscript><p>Bez JavaScript widoczne są wszystkie miejsca. Wypełnij maksymalnie 4, 6 lub 8 startów odpowiednio dla 1, 2 lub 3 dyscyplin.</p></noscript>
                <div class="competition-list">
                    <?php foreach ($competitions as $index => $competition): ?>
                        <fieldset class="competition-row" data-competition data-index="<?= $index ?>">
                            <legend><span class="row-index"><?= sprintf('%02d', $index + 1) ?></span> Zawody</legend>
                            <div class="form-grid competition-grid">
                                <label class="field field-wide"><span>Nazwa zawodów</span>
                                    <input name="competitions[<?= $index ?>][name]" maxlength="80" data-required-when-active value="<?= h($competition['name']) ?>">
                                </label>
                                <label class="field"><span>Data</span>
                                    <input name="competitions[<?= $index ?>][date]" type="date" data-required-when-active value="<?= h($competition['date']) ?>">
                                </label>
                                <label class="field"><span>Miejsce</span>
                                    <input name="competitions[<?= $index ?>][location]" maxlength="60" data-required-when-active value="<?= h($competition['location']) ?>">
                                </label>
                            </div>
                            <div class="row-options">
                                <fieldset class="category-options">
                                    <legend>Dyscyplina <b>*</b></legend>
                                    <?php
                                    foreach ($categoryLabels as $category => $label):
                                    ?>
                                        <label class="check-option" data-category-option>
                                            <input type="radio" name="competitions[<?= $index ?>][category]" value="<?= h($category) ?>" <?= in_array($category, $competition['categories'], true) ? 'checked' : '' ?>>
                                            <span><?= h($label) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <label class="check-option mzss-toggle">
                                    <input type="checkbox" name="competitions[<?= $index ?>][in_mzss]" value="1" data-mzss-toggle <?= $competition['in_mzss'] ? 'checked' : '' ?>>
                                    <span>Zawody w kalendarzu MZSS</span>
                                </label>
                            </div>
                            <fieldset class="category-options calendar-options" data-calendar-options>
                                <legend>Kalendarz zawodów <b>*</b></legend>
                                <?php foreach ($calendarLabels as $calendar => $label): ?>
                                    <label class="check-option">
                                        <input type="radio" name="competitions[<?= $index ?>][calendar]" value="<?= h($calendar) ?>" <?= $competition['calendar'] === $calendar ? 'checked' : '' ?>>
                                        <span><?= h($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="privacy-note">Zaznacz kalendarz do wpisania w PDF. Zawody mogą jednocześnie znajdować się w kalendarzu MZSS i innym kalendarzu.</p>
                            <div class="url-field" data-url-field <?= $competition['in_mzss'] ? 'hidden' : '' ?>>
                                <label class="field"><span>Link do strony zawodów <b>*</b></span>
                                    <input name="competitions[<?= $index ?>][url]" type="url" maxlength="250" placeholder="https://…" data-required-when-active value="<?= h($competition['url']) ?>">
                                    <small>Link zostanie umieszczony w treści wiadomości e-mail.</small>
                                </label>
                            </div>
                            <label class="field remarks-field"><span>Uwagi <small>(opcjonalnie)</small></span>
                                <input name="competitions[<?= $index ?>][remarks]" maxlength="60" value="<?= h($competition['remarks']) ?>">
                            </label>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="form-card">
                <div class="section-heading">
                    <span class="section-number">04</span>
                    <div><h2>Oświadczenia i wysyłka</h2><p>Rok licencji: <strong><?= h((string) $licenseYear) ?></strong>. Data i miejscowość zostaną wpisane do wniosku.</p></div>
                </div>
                <div class="form-grid">
                    <label class="field"><span>Miejscowość <b>*</b></span>
                        <input name="applicant[application_place]" maxlength="60" autocomplete="address-level2" required value="<?= h($form['application_place']) ?>">
                    </label>
                    <label class="field"><span>Data wniosku <b>*</b></span>
                        <input name="applicant[application_date]" type="date" required value="<?= h($form['application_date']) ?>">
                    </label>
                </div>
                <div class="declarations">
                    <label class="check-option declaration">
                        <input type="checkbox" name="declaration" value="1" <?= !empty($_POST['declaration']) ? 'checked' : '' ?>>
                        <span>Potwierdzam prawdziwość podanych danych i zapoznałem(-am) się z oświadczeniami zawartymi we wniosku. <b>*</b></span>
                    </label>
                    <label class="check-option declaration">
                        <input type="checkbox" name="privacy_consent" value="1" <?= !empty($_POST['privacy_consent']) ? 'checked' : '' ?>>
                        <span>Zapoznałem(-am) się z informacją o przetwarzaniu danych zamieszczoną w dokumencie PDF. <b>*</b></span>
                    </label>
                </div>
                <p class="privacy-note">Dane są używane wyłącznie do przygotowania wniosku i wysłania wiadomości. Formularz nie zapisuje ich w bazie; tymczasowy PDF jest usuwany po pobraniu, a wygasłe pliki są sprzątane przy kolejnych wejściach na stronę.</p>
            </section>

            <label class="field captcha-field"><span>Pytanie zabezpieczające (wymagane do wysyłki e-mail): <?= h($captchaQuestion) ?> <b>*</b></span>
                <input name="captcha" maxlength="30" autocomplete="off" autocapitalize="none" placeholder="Wpisz jedno słowo (wielkość liter bez znaczenia)">
            </label>

            <div class="submit-row">
                <p>Pola oznaczone <b>*</b> są wymagane. Możesz pobrać PDF bez wysyłania e-maila.</p>
                <div class="submit-actions">
                    <button class="button button-secondary" type="submit" name="action" value="download">Pobierz PDF</button>
                    <button class="button button-primary" type="submit" name="action" value="send"><span>Przygotuj i wyślij wniosek</span><span aria-hidden="true">→</span></button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</main>
<footer class="site-footer">
    <div class="footer-inner"><span>ZSD · WNIOSEK LICENCYJNY</span><span>Dokument źródłowy: wniosek o przedłużenie licencji zawodniczej</span></div>
</footer>
<script src="assets/app.js" defer></script>
</body>
</html>
