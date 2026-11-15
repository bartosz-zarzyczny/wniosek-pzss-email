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
require_once __DIR__ . '/lib/AdminAuth.php';
require_once __DIR__ . '/lib/AdminSettings.php';

header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$notice = '';
$pdo = null;
$user = null;
$userCount = 0;
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$requestedTab = $_POST['tab'] ?? $_GET['tab'] ?? 'import';
$activeTab = is_string($requestedTab) && in_array($requestedTab, ['import', 'settings'], true)
    ? $requestedTab
    : 'import';
$settingsPath = __DIR__ . '/settings.json';
$adminSettings = [];
$settingsAvailable = false;
$licenseYearValue = '2027';
$headerTexts = AdminSettings::headerDefaults();
$recipientsValue = '';
$senderValue = '';
$calendarUrlsValue = '';

try {
    $pdo = StartLookup::connect();
    Schema::ensure($pdo);
    $userCount = AdminAuth::countUsers($pdo);
} catch (Throwable $exception) {
    error_log('Panel admina: ' . $exception->getMessage());
    $pdo = null;
    $errors[] = 'Brak połączenia z bazą danych. Sprawdź local.php.';
}

if ($pdo !== null) {
    if (!empty($_SESSION['admin_id'])) {
        $check = $pdo->prepare('SELECT id, username FROM admin_users WHERE id = :id');
        $check->execute(['id' => (int) $_SESSION['admin_id']]);
        $user = $check->fetch() ?: null;
        if ($user === null) {
            unset($_SESSION['admin_id']);
        }
    }

    if ($user !== null) {
        try {
            $adminSettings = AdminSettings::load($settingsPath);
            $settingsAvailable = true;
            $configuredYear = $adminSettings['license_year'] ?? 2027;
            $licenseYearValue = is_int($configuredYear) || is_string($configuredYear)
                ? (string) $configuredYear
                : '';
            $headerTexts = AdminSettings::headerTexts($adminSettings);
            $recipientsValue = implode(PHP_EOL, AdminSettings::recipients($adminSettings));
            $senderValue = is_string($adminSettings['from_email'] ?? null)
                ? $adminSettings['from_email']
                : '';
            $calendarUrls = AdminSettings::calendarUrls($adminSettings);
            $calendarUrlsValue = implode(PHP_EOL, $calendarUrls);
        } catch (RuntimeException $exception) {
            error_log('Odczyt ustawień panelu: ' . $exception->getMessage());
            $errors[] = $exception->getMessage();
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $token = $_POST['csrf_token'] ?? null;
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $field = static fn (string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $repeat = is_string($_POST['password_repeat'] ?? null) ? $_POST['password_repeat'] : '';

        if (!is_string($token) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
            $errors[] = 'Sesja wygasła. Spróbuj ponownie.';
        } elseif ($action === 'setup' && $userCount === 0) {
            $error = AdminAuth::validateNewUser($field('username'), $password, $repeat);
            if ($error !== null) {
                $errors[] = $error;
            } else {
                AdminAuth::createUser($pdo, $field('username'), $password);
                $userCount = 1;
                $notice = 'Konto administratora zostało utworzone. Zaloguj się.';
            }
        } elseif ($action === 'login' && $userCount > 0) {
            if (AdminAuth::tooManyAttempts($pdo, $ip)) {
                $errors[] = 'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za kilkanaście minut.';
            } else {
                $found = AdminAuth::verify($pdo, $field('username'), $password);
                if ($found === null) {
                    AdminAuth::registerFailure($pdo, $ip);
                    $errors[] = 'Nieprawidłowy login lub hasło.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $found['id'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: admin.php');
                    exit;
                }
            }
        } elseif ($user !== null && $action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: admin.php');
            exit;
        } elseif ($user !== null && $action === 'save_settings') {
            $licenseYearValue = $field('license_year');
            $headerTexts = [];
            foreach (array_keys(AdminSettings::headerDefaults()) as $key) {
                $headerTexts[$key] = $field($key);
            }
            $recipientsValue = $field('recipients');
            $senderValue = $field('from_email');
            $calendarUrlsValue = $field('calendar_urls');
            if (!$settingsAvailable) {
                $errors[] = 'Nie można zapisać ustawień, ponieważ nie udało się odczytać settings.json.';
            } else {
                try {
                    $updatedSettings = $adminSettings;
                    $updatedSettings['license_year'] = AdminSettings::parseLicenseYear($licenseYearValue);
                    $headerTexts = AdminSettings::parseHeaderTexts($headerTexts);
                    foreach ($headerTexts as $key => $value) {
                        $updatedSettings[$key] = $value;
                    }
                    $updatedSettings['recipients'] = AdminSettings::parseRecipients($recipientsValue);
                    $updatedSettings['from_email'] = AdminSettings::parseSender($senderValue);
                    $updatedSettings['calendar_urls'] = AdminSettings::parseCalendarUrls($calendarUrlsValue);
                    unset($updatedSettings['calendar_url']);
                    AdminSettings::save($settingsPath, $updatedSettings);
                    $adminSettings = $updatedSettings;
                    $licenseYearValue = (string) $adminSettings['license_year'];
                    $recipientsValue = implode(PHP_EOL, $adminSettings['recipients']);
                    $senderValue = (string) $adminSettings['from_email'];
                    $calendarUrlsValue = implode(PHP_EOL, $adminSettings['calendar_urls']);
                    $notice = 'Ustawienia zostały zapisane.';
                } catch (InvalidArgumentException | RuntimeException $exception) {
                    error_log('Zapis ustawień panelu: ' . $exception->getMessage());
                    $errors[] = $exception->getMessage();
                }
            }
        } elseif ($user !== null && $action === 'add_user') {
            $error = AdminAuth::validateNewUser($field('username'), $password, $repeat);
            if ($error !== null) {
                $errors[] = $error;
            } elseif (!AdminAuth::createUser($pdo, $field('username'), $password)) {
                $errors[] = 'Użytkownik o takim loginie już istnieje.';
            } else {
                $notice = 'Dodano użytkownika ' . $field('username') . '.';
            }
        } elseif ($user !== null && $action === 'delete_user') {
            $id = (int) $field('user_id');
            if ($id === (int) $user['id']) {
                $errors[] = 'Nie możesz usunąć własnego konta.';
            } else {
                AdminAuth::deleteUser($pdo, $id);
                $notice = 'Użytkownik został usunięty.';
            }
        }
    }
}

$calendarUrls = AdminSettings::calendarUrls($adminSettings);
$users = $user !== null && $pdo !== null ? AdminAuth::listUsers($pdo) : [];
$stats = ['competitions' => 0, 'results' => 0];
if ($user !== null && $pdo !== null) {
    $stats = [
        'competitions' => (int) $pdo->query('SELECT COUNT(*) FROM competitions')->fetchColumn(),
        'results' => (int) $pdo->query('SELECT COUNT(*) FROM competition_results')->fetchColumn(),
    ];
}
$csrf = (string) $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Panel administracyjny</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">Z</span>
            <span class="brand-copy"><strong>ZSD</strong><span>PANEL ADMINISTRACYJNY</span></span>
        </a>
        <?php if ($user !== null): ?>
            <form method="post" class="admin-logout">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <span class="header-label"><?= h((string) $user['username']) ?></span>
                <button class="button button-secondary" type="submit" name="action" value="logout">Wyloguj</button>
            </form>
        <?php endif; ?>
    </div>
</header>

<main class="page-shell admin-shell">
    <?php if ($notice !== ''): ?>
        <div class="alert admin-notice" role="status"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error" role="alert"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($pdo === null): ?>
    <?php elseif ($userCount === 0): ?>
        <section class="admin-card">
            <h1>Pierwsze uruchomienie</h1>
            <p>Brak kont administratorów. Utwórz pierwsze konto — kolejne dodasz po zalogowaniu.</p>
            <form method="post" class="admin-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="setup">
                <label class="field"><span>Login</span><input name="username" required minlength="3" maxlength="40" autocomplete="username"></label>
                <label class="field"><span>Hasło (min. 10 znaków)</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
                <label class="field"><span>Powtórz hasło</span><input type="password" name="password_repeat" required minlength="10" autocomplete="new-password"></label>
                <button class="button button-primary" type="submit">Utwórz konto</button>
            </form>
        </section>
    <?php elseif ($user === null): ?>
        <section class="admin-card">
            <h1>Logowanie</h1>
            <form method="post" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="login">
                <label class="field"><span>Login</span><input name="username" required autocomplete="username" autofocus></label>
                <label class="field"><span>Hasło</span><input type="password" name="password" required autocomplete="current-password"></label>
                <button class="button button-primary" type="submit">Zaloguj</button>
            </form>
        </section>
    <?php else: ?>
        <nav class="admin-tabs" aria-label="Zakładki panelu">
            <a href="admin.php?tab=import" <?= $activeTab === 'import' ? 'aria-current="page"' : '' ?>>Import</a>
            <a href="admin.php?tab=settings" <?= $activeTab === 'settings' ? 'aria-current="page"' : '' ?>>Ustawienia i użytkownicy</a>
        </nav>
        <?php if ($activeTab === 'import'): ?>
            <section class="admin-card" data-admin data-csrf="<?= h($csrf) ?>">
                <h1>Wyniki zawodów z kalendarza</h1>
                <p>W bazie: <strong><?= $stats['competitions'] ?></strong> zawodów, <strong><?= $stats['results'] ?></strong> startów.
                    Pobierane są tylko pliki z wynikami (nazwa z <code>_R_</code>, „rezultat”, „results”, „wyniki”); pliki już aktualne nie są pobierane ponownie.</p>
                <div class="admin-toolbar">
                    <label class="field admin-calendar"><span>Kalendarz</span>
                        <select data-calendar-url>
                            <?php if ($calendarUrls === []): ?>
                                <option value="">Brak skonfigurowanych kalendarzy</option>
                            <?php else: ?>
                                <?php foreach ($calendarUrls as $calendarUrl): ?>
                                    <option value="<?= h($calendarUrl) ?>"><?= h($calendarUrl) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="field admin-year"><span>Rok</span><input type="number" min="2000" max="2100" value="<?= (int) date('Y') ?>" data-year></label>
                    <button class="button button-primary" type="button" data-run>Pobierz i zaimportuj z tego kalendarza</button>
                    <label class="admin-check"><input type="checkbox" data-force> przetwórz ponownie także aktualne pliki</label>
                    <button class="button button-secondary" type="button" data-python>Sprawdź Pythona</button>
                </div>
                <div class="admin-progress" data-progress hidden><div data-progress-bar></div></div>
                <p class="admin-status" data-status aria-live="polite"></p>
                <table class="admin-table" data-table hidden>
                    <thead><tr><th>Plik</th><th>Zawody</th><th>Wynik</th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>

            <section class="admin-card">
                <h2>Ręczny import PDF z wynikami</h2>
                <p>Dodaj PDF, którego nie ma w kalendarzu MZSS. Podaj metadane zawodów; plik zostanie odczytany tymczasowo i nie będzie przechowywany na serwerze. Link zostanie uzupełniony w formularzu i treści e-maila; szablon PDF nie ma osobnego pola na URL.</p>
                <form class="admin-form admin-manual-form" data-manual-import enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div class="form-grid">
                        <label class="field field-wide"><span>Nazwa zawodów</span>
                            <input name="title" maxlength="80" required>
                        </label>
                        <label class="field"><span>Data zawodów</span>
                            <input name="date" type="date" required>
                        </label>
                        <label class="field"><span>Miejsce</span>
                            <input name="location" maxlength="60" required>
                        </label>
                        <label class="field"><span>Kalendarz</span>
                            <select name="calendar" required>
                                <option value="">Wybierz kalendarz</option>
                                <option value="PZSS">PZSS</option>
                                <option value="WZSS">WZSS</option>
                                <option value="MZSS">MZSS</option>
                                <option value="other">Inny / brak</option>
                            </select>
                        </label>
                        <label class="field field-wide"><span>Link do strony zawodów <small>(opcjonalnie)</small></span>
                            <input name="event_url" type="url" maxlength="250" placeholder="https://…">
                        </label>
                    </div>
                    <fieldset class="category-options">
                        <legend>Dyscypliny domyślne <small>(można zaznaczyć kilka)</small></legend>
                        <p class="privacy-note">Kategorie rozpoznane w PDF są przypisywane osobno. Zaznaczone dyscypliny służą jako domyślne, gdy da się je jednoznacznie ustalić.</p>
                        <label class="check-option"><input type="checkbox" name="disciplines[]" value="pistol"><span>Pistolet</span></label>
                        <label class="check-option"><input type="checkbox" name="disciplines[]" value="rifle"><span>Karabin</span></label>
                        <label class="check-option"><input type="checkbox" name="disciplines[]" value="shotgun"><span>Strzelba</span></label>
                    </fieldset>
                    <label class="field"><span>Plik PDF z wynikami</span>
                        <input type="file" name="pdf" accept="application/pdf,.pdf" required>
                        <small>Maksymalny rozmiar: 30 MB.</small>
                    </label>
                    <button class="button button-primary" type="submit" data-manual-submit>Importuj PDF</button>
                    <p class="admin-status" data-manual-status role="status" aria-live="polite"></p>
                </form>
            </section>
        <?php else: ?>
            <section class="admin-card">
                <h2>Ustawienia formularza</h2>
                <p>Ustaw rok i teksty widoczne w nagłówku formularza. Adresy wpisuj po jednym w wierszu. Zmiany odbiorców i nadawcy obowiązują przy kolejnej wysyłce; podczas importu wybierasz jeden kalendarz.</p>
                <form method="post" class="admin-form admin-settings-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="tab" value="settings">
                    <label class="field"><span>Rok licencji</span>
                        <input name="license_year" type="number" min="2020" max="2099" step="1" value="<?= h($licenseYearValue) ?>" required>
                        <small>Wartość używana w formularzu i generowanym wniosku.</small>
                    </label>
                    <h3>Teksty nagłówka formularza</h3>
                    <label class="field field-wide"><span>Tekst dla czytników ekranu (aria-label)</span>
                        <input name="brand_aria_label" maxlength="100" value="<?= h($headerTexts['brand_aria_label']) ?>" required>
                    </label>
                    <label class="field"><span>Znak w logo</span>
                        <input name="brand_mark" maxlength="4" value="<?= h($headerTexts['brand_mark']) ?>" required>
                    </label>
                    <label class="field"><span>Nazwa marki</span>
                        <input name="brand_title" maxlength="40" value="<?= h($headerTexts['brand_title']) ?>" required>
                    </label>
                    <label class="field field-wide"><span>Podtytuł marki</span>
                        <input name="brand_subtitle" maxlength="60" value="<?= h($headerTexts['brand_subtitle']) ?>" required>
                    </label>
                    <label class="field field-wide"><span>Tekst nagłówka przy logo</span>
                        <input name="header_label" maxlength="80" value="<?= h($headerTexts['header_label']) ?>" required>
                    </label>
                    <label class="field field-wide"><span>Adresy odbiorców</span>
                        <textarea name="recipients" rows="4" required><?= h($recipientsValue) ?></textarea>
                        <small>Jeden adres e-mail w każdym wierszu; maksymalnie 20 adresów.</small>
                    </label>
                    <label class="field field-wide"><span>Adres nadawcy („Od”)</span>
                        <input name="from_email" type="email" maxlength="254" value="<?= h($senderValue) ?>">
                        <small>Pole może pozostać puste, aby użyć domyślnego adresu aplikacji.</small>
                    </label>
                    <label class="field field-wide"><span>Adresy kalendarzy</span>
                        <textarea name="calendar_urls" rows="5" required><?= h($calendarUrlsValue) ?></textarea>
                        <small>Jeden link HTTP/HTTPS w każdym wierszu; maksymalnie 20. Przy imporcie skanowany jest tylko wybrany link.</small>
                    </label>
                    <button class="button button-primary" type="submit" <?= $settingsAvailable ? '' : 'disabled' ?>>Zapisz ustawienia</button>
                </form>
            </section>

            <section class="admin-card">
                <h2>Użytkownicy panelu</h2>
                <table class="admin-table">
                    <thead><tr><th>Login</th><th>Dodano</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <td><?= h((string) $row['username']) ?></td>
                            <td><?= h((string) $row['created_at']) ?></td>
                            <td>
                                <?php if ((int) $row['id'] !== (int) $user['id']): ?>
                                    <form method="post" onsubmit="return confirm('Usunąć użytkownika?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="tab" value="settings">
                                        <button class="button button-secondary" type="submit">Usuń</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <h3>Dodaj użytkownika</h3>
                <form method="post" class="admin-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="tab" value="settings">
                    <label class="field"><span>Login</span><input name="username" required minlength="3" maxlength="40" autocomplete="off"></label>
                    <label class="field"><span>Hasło (min. 10 znaków)</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
                    <label class="field"><span>Powtórz hasło</span><input type="password" name="password_repeat" required minlength="10" autocomplete="new-password"></label>
                    <button class="button button-primary" type="submit">Dodaj</button>
                </form>
            </section>
        <?php endif; ?>
        <script src="assets/admin.js?v=<?= (int) filemtime(__DIR__ . '/assets/admin.js') ?>" defer></script>
    <?php endif; ?>
</main>
</body>
</html>
