<?php
declare(strict_types=1);

final class MzssImporter
{
    private const MAX_PDF_BYTES = 30 * 1024 * 1024;
    private const PYTHON_TIMEOUT = 25;
    private const PATH_PREFIX = '/uploads/';

    private string $calendarUrl;
    private string $pythonPath;
    private string $downloadDir;
    private string $host;

    /** @param array<string,mixed> $settings */
    public function __construct(private PDO $pdo, array $settings, string $downloadDir)
    {
        $this->calendarUrl = (string) ($settings['calendar_url'] ?? '');
        $this->pythonPath = (string) ($settings['python_path'] ?? 'python3');
        $this->downloadDir = rtrim($downloadDir, '/\\');
        $parts = parse_url($this->calendarUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Brak poprawnego "calendar_url" w settings.json.');
        }
        $this->host = strtolower((string) $parts['host']);
    }

    public static function isResultFile(string $fileName): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])r(?![a-z0-9])|rezultat|result|wyniki/i', $fileName);
    }

    /** @return array{year:int,items:list<array<string,mixed>>} */
    public function scan(?int $year = null): array
    {
        [$html] = $this->fetchPage($this->calendarUrl);
        $doc = $this->loadHtml($html);
        $currentYear = $this->pageYear($doc);
        if ($year !== null && $year !== $currentYear) {
            $href = $this->yearLink($doc, $year);
            if ($href === null) {
                throw new RuntimeException('Kalendarz nie udostępnia roku ' . $year . '.');
            }
            [$html] = $this->fetchPage($href);
            $doc = $this->loadHtml($html);
            $currentYear = $this->pageYear($doc);
        }

        $xpath = new DOMXPath($doc);
        $items = [];
        foreach ($xpath->query("//table[contains(@class,'events-table')]//tr[@class='text-center']") as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length < 11 || !preg_match('/^\d+\.$/', trim($cells->item(0)->textContent))) {
                continue;
            }
            $date = null;
            if (preg_match('/^(\d{1,2})\.(\d{1,2})$/', trim($cells->item(5)->textContent), $m)
                && checkdate((int) $m[2], (int) $m[1], $currentYear)) {
                $date = sprintf('%04d-%02d-%02d', $currentYear, $m[2], $m[1]);
            }
            $flags = [];
            foreach ([8 => 'pistol', 9 => 'rifle', 10 => 'shotgun'] as $index => $discipline) {
                if ($xpath->query('.//img', $cells->item($index))->length > 0) {
                    $flags[] = $discipline;
                }
            }
            foreach ($xpath->query('.//a[@href]', $cells->item(2)) as $link) {
                $url = $this->validPdfUrl(html_entity_decode((string) $link->getAttribute('href')));
                if ($url === null) {
                    continue;
                }
                $file = $this->fileNameFor($url);
                if (!self::isResultFile($file)) {
                    continue;
                }
                $items[$url] = [
                    'url' => $url,
                    'file' => $file,
                    'title' => trim(preg_replace('/\s+/u', ' ', $cells->item(1)->textContent) ?? ''),
                    'date' => $date,
                    'location' => trim(preg_replace('/\s+/u', ' ', $cells->item(7)->textContent) ?? ''),
                    'flags' => $flags,
                ];
            }
        }

        $states = [];
        foreach ($this->pdo->query('SELECT url, status, message FROM pdf_files')->fetchAll() as $state) {
            $states[$state['url']] = $state;
        }
        foreach ($items as $url => &$item) {
            $item['status'] = $states[$url]['status'] ?? 'new';
            $item['message'] = $states[$url]['message'] ?? '';
        }
        unset($item);

        return ['year' => $currentYear, 'items' => array_values($items)];
    }

    /**
     * Pobiera plik (tylko gdy brak lub jest nowsza wersja) i importuje wyniki do bazy.
     *
     * @param array<string,mixed> $item pozycja zwrócona przez scan()
     * @return array{status:string,message:string}
     */
    public function process(array $item, bool $force = false): array
    {
        $url = $this->validPdfUrl((string) $item['url']);
        if ($url === null) {
            return ['status' => 'error', 'message' => 'Niedozwolony adres pliku.'];
        }
        $file = $this->downloadDir . DIRECTORY_SEPARATOR . $this->fileNameFor($url);
        if (!is_dir($this->downloadDir) && !mkdir($this->downloadDir, 0755, true) && !is_dir($this->downloadDir)) {
            return ['status' => 'error', 'message' => 'Nie można utworzyć folderu download_pdf.'];
        }

        $record = $this->pdo->prepare('SELECT * FROM pdf_files WHERE url = :u');
        $record->execute(['u' => $url]);
        $record = $record->fetch() ?: null;

        $remote = $this->head($url);
        $downloaded = false;
        if (!$this->isCurrent($file, $record, $remote)) {
            $remote = $this->download($url, $file);
            $downloaded = true;
        }

        $sha = hash_file('sha256', $file) ?: null;
        $changed = $downloaded && ($record === null || $record['sha256'] !== $sha);
        $needsImport = $force || $changed || $record === null || $record['imported_at'] === null;

        $this->saveRecord($url, basename($file), $remote, $sha, $downloaded, $record);

        if (!$needsImport) {
            return ['status' => 'current', 'message' => (string) ($record['message'] ?? '') ?: 'Aktualny, bez zmian.'];
        }

        $result = $this->import($url, $file, $item);
        $this->pdo->prepare('UPDATE pdf_files SET status = :s, message = :m, imported_at = :t WHERE url = :u')
            ->execute(['s' => $result['status'], 'm' => $result['message'], 't' => date('Y-m-d H:i:s'), 'u' => $url]);
        $prefix = $downloaded ? 'Pobrano. ' : 'Plik już aktualny. ';
        return ['status' => $result['status'], 'message' => $prefix . $result['message']];
    }

    /**
     * Imports one uploaded PDF using metadata entered by an administrator.
     *
     * @param array{title:string,date:string,location:string,calendar:string,event_url:string,flags:list<string>} $item
     * @return array{status:string,message:string}
     */
    public function importManual(string $file, string $fileName, array $item): array
    {
        $identity = implode("\0", [
            $item['date'],
            $item['title'],
            $item['location'],
            $item['calendar'],
            str_replace('\\', '/', $fileName),
        ]);
        $sourceKey = 'manual:' . hash('sha256', $identity);

        return $this->import($sourceKey, $file, $item, true);
    }

    /** @return array{ok:bool,message:string} */
    public function checkPython(): array
    {
        $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pzss_probe_' . bin2hex(random_bytes(4)) . '.py';
        file_put_contents($probe, "import sys\nsys.path.insert(0, sys.argv[1])\nimport pypdf\nprint(sys.version.split()[0], 'pypdf', pypdf.__version__)\n");
        try {
            [$code, $out, $err] = $this->runPython([$probe, __DIR__ . '/../tools/vendor']);
        } finally {
            @unlink($probe);
        }
        $text = trim($out !== '' ? $out : $err);
        return ['ok' => $code === 0, 'message' => $text !== '' ? $text : 'Brak odpowiedzi od Pythona.'];
    }

    /** @return array{0:string} */
    private function fetchPage(string $url): array
    {
        $headers = [];
        $handle = $this->curl($url, $headers);
        curl_setopt($handle, CURLOPT_TIMEOUT, 25);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_MAXREDIRS, 3);
        curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('Nie udało się pobrać kalendarza (HTTP ' . $status . ').');
        }
        return [$body];
    }

    /** @param array<string,string> $headers */
    private function curl(string $url, array &$headers): CurlHandle
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'wniosek-pzss/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        return $handle;
    }

    /** @return array<string,string> */
    private function head(string $url): array
    {
        $headers = [];
        $handle = $this->curl($url, $headers);
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return $status === 200 ? $headers : [];
    }

    /** @return array<string,string> */
    private function download(string $url, string $file): array
    {
        $headers = [];
        $temp = $file . '.part';
        $stream = fopen($temp, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Nie można zapisać pliku w download_pdf.');
        }
        $handle = $this->curl($url, $headers);
        curl_setopt_array($handle, [
            CURLOPT_FILE => $stream,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $total, $now): int => $now > self::MAX_PDF_BYTES ? 1 : 0,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($stream);

        $magic = $status === 200 ? (string) file_get_contents($temp, false, null, 0, 5) : '';
        if ($magic !== '%PDF-') {
            @unlink($temp);
            throw new RuntimeException('Pobieranie nie powiodło się (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ').');
        }
        if (!rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Nie można zapisać pliku w download_pdf.');
        }
        return $headers;
    }

    /** @param array<string,mixed>|null $record @param array<string,string> $remote */
    private function isCurrent(string $file, ?array $record, array $remote): bool
    {
        if (!is_file($file) || $remote === []) {
            return false;
        }
        $etag = $remote['etag'] ?? null;
        $modified = $remote['last-modified'] ?? null;
        $length = isset($remote['content-length']) ? (int) $remote['content-length'] : null;

        if ($record !== null) {
            if ($etag !== null && $record['etag'] !== null) {
                return $etag === $record['etag'];
            }
            if ($modified !== null && $record['last_modified'] !== null) {
                return $modified === $record['last_modified'];
            }
            if ($length !== null && $record['size'] !== null) {
                return $length === (int) $record['size'];
            }
            return false;
        }

        // Plik położony ręcznie: aktualny, gdy ma ten sam rozmiar i nie jest starszy niż wersja na serwerze.
        if ($length === null || filesize($file) !== $length) {
            return false;
        }
        $remoteTime = $modified !== null ? strtotime($modified) : false;
        return $remoteTime === false || filemtime($file) >= $remoteTime;
    }

    /** @param array<string,mixed>|null $record @param array<string,string> $remote */
    private function saveRecord(string $url, string $name, array $remote, ?string $sha, bool $downloaded, ?array $record): void
    {
        $now = date('Y-m-d H:i:s');
        $values = [
            'u' => $url,
            'n' => $name,
            'e' => $remote['etag'] ?? ($record['etag'] ?? null),
            'l' => $remote['last-modified'] ?? ($record['last_modified'] ?? null),
            's' => isset($remote['content-length']) ? (int) $remote['content-length'] : ($record['size'] ?? null),
            'h' => $sha,
            'd' => $downloaded ? $now : ($record['downloaded_at'] ?? null),
        ];
        $this->pdo->prepare(
            'INSERT INTO pdf_files (url, file_name, etag, last_modified, size, sha256, downloaded_at)
             VALUES (:u, :n, :e, :l, :s, :h, :d)
             ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), etag = VALUES(etag),
                last_modified = VALUES(last_modified), size = VALUES(size), sha256 = VALUES(sha256),
                downloaded_at = VALUES(downloaded_at)'
        )->execute($values);
    }

    /**
     * @param array<string,mixed> $item
     * @return array{status:string,message:string}
     */
    private function import(string $url, string $file, array $item, bool $manual = false): array
    {
        [$code, $out, $err] = $this->runPython([__DIR__ . '/../tools/parse_pdf.py', $file]);
        $data = json_decode($out, true);
        if ($code !== 0 || !is_array($data) || !isset($data['rows'])) {
            return ['status' => 'error', 'message' => 'Błąd odczytu PDF: ' . mb_substr(trim($err) ?: 'brak odpowiedzi Pythona', 0, 150, 'UTF-8')];
        }
        $rows = $data['rows'];
        if ($rows === []) {
            if (!$manual) {
                $this->removeCompetition($url);
            }
            return ['status' => 'empty', 'message' => 'Brak numerów licencji w pliku — pominięto.'];
        }

        $flags = array_values(array_intersect(['pistol', 'rifle', 'shotgun'], (array) ($item['flags'] ?? [])));
        $hints = array_values(array_intersect(['pistol', 'rifle', 'shotgun'], (array) ($data['hints'] ?? [])));
        $fallback = null;
        if (count($flags) === 1) {
            $fallback = $flags[0];
        } else {
            $candidates = $flags !== [] ? array_values(array_intersect($flags, $hints)) : $hints;
            if (count($candidates) === 1) {
                $fallback = $candidates[0];
            }
        }

        $date = (string) ($item['date'] ?? '');
        if ($date === '') {
            return ['status' => 'error', 'message' => 'Brak daty zawodów w kalendarzu.'];
        }

        $starts = [];
        $unknown = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 3) {
                continue;
            }
            [$discipline, $license, $nameKey] = $row;
            $competitionName = isset($row[3]) && is_string($row[3])
                ? mb_substr($row[3], 0, 120, 'UTF-8')
                : '';
            $discipline = $discipline ?? $fallback;
            if ($discipline === null) {
                $unknown++;
                continue;
            }
            $starts[] = [$discipline, (string) $license, (string) $nameKey, $competitionName];
        }
        if ($starts === []) {
            if (!$manual) {
                $this->removeCompetition($url);
            }
            return ['status' => 'unknown_discipline', 'message' => 'Nie rozpoznano dyscypliny — pominięto.'];
        }

        $this->pdo->beginTransaction();
        try {
            $this->removeCompetition($url);
            $this->pdo->prepare(
                'INSERT INTO competitions (name, event_date, location, calendar, source_key, event_url)
                 VALUES (:n, :d, :l, :c, :k, :url)'
            )->execute([
                'n' => mb_substr((string) $item['title'], 0, 80, 'UTF-8'),
                'd' => $date,
                'l' => mb_substr((string) $item['location'], 0, 60, 'UTF-8'),
                'c' => (string) ($item['calendar'] ?? 'MZSS'),
                'k' => $url,
                'url' => (string) ($item['event_url'] ?? ''),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO competition_results (competition_id, discipline, license_number, name_key, competition_name)
                 VALUES (:c, :d, :l, :n, :cn)'
            );
            foreach ($starts as [$discipline, $license, $nameKey, $competitionName]) {
                $insert->execute([
                    'c' => $id,
                    'd' => $discipline,
                    'l' => $license,
                    'n' => $nameKey,
                    'cn' => $competitionName,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $disciplines = implode(', ', array_unique(array_column($starts, 0)));
        $namedStarts = count(array_filter($starts, static function (array $start): bool {
            return trim($start[3]) !== '';
        }));
        $message = sprintf(
            'Zaimportowano %d startów (%s); nazwy konkurencji odczytano dla %d/%d.',
            count($starts),
            $disciplines,
            $namedStarts,
            count($starts)
        );
        if ($unknown > 0) {
            $message .= sprintf(' Pominięto %d bez rozpoznanej dyscypliny.', $unknown);
        }
        return ['status' => 'imported', 'message' => $message];
    }

    private function removeCompetition(string $url): void
    {
        $this->pdo->prepare('DELETE FROM competitions WHERE source_key = :k')->execute(['k' => $url]);
    }

    /**
     * @param list<string> $arguments
     * @return array{0:int,1:string,2:string}
     */
    private function runPython(array $arguments): array
    {
        $out = tempnam(sys_get_temp_dir(), 'pzo');
        $err = tempnam(sys_get_temp_dir(), 'pze');
        $process = @proc_open(
            array_merge([$this->pythonPath], $arguments),
            [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            null,
            ['PYTHONIOENCODING' => 'utf-8'] + (getenv() ?: [])
        );
        if (!is_resource($process)) {
            @unlink($out);
            @unlink($err);
            return [127, '', 'Nie można uruchomić Pythona (' . $this->pythonPath . ').'];
        }
        $deadline = microtime(true) + self::PYTHON_TIMEOUT;
        $exit = -1;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                $exit = 124;
                break;
            }
            usleep(50000);
        }
        proc_close($process);
        $stdout = (string) file_get_contents($out);
        $stderr = (string) file_get_contents($err);
        @unlink($out);
        @unlink($err);
        return [$exit, $stdout, $exit === 124 ? 'Przekroczono limit czasu odczytu PDF.' : $stderr];
    }

    private function validPdfUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || strtolower((string) ($parts['host'] ?? '')) !== $this->host
            || isset($parts['query']) || isset($parts['user'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if (!str_starts_with($path, self::PATH_PREFIX) || !preg_match('/\.pdf$/i', $path) || str_contains($path, '..')
            || strlen($url) > 255) {
            return null;
        }
        return $url;
    }

    private function fileNameFor(string $url): string
    {
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '._');
        return $name === '' ? 'plik.pdf' : substr($name, -150);
    }

    private function loadHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    private function pageYear(DOMDocument $doc): int
    {
        foreach ((new DOMXPath($doc))->query('//h2') as $heading) {
            if (preg_match('/^\d{4}$/', trim($heading->textContent))) {
                return (int) trim($heading->textContent);
            }
        }
        return (int) date('Y');
    }

    private function yearLink(DOMDocument $doc, int $year): ?string
    {
        $links = (new DOMXPath($doc))->query('//a[@title="Wyświetl rok ' . $year . '"]/@href');
        if ($links->length === 0) {
            return null;
        }
        $href = html_entity_decode($links->item(0)->nodeValue ?? '');
        $parts = parse_url($href);
        return is_array($parts) && strtolower((string) ($parts['host'] ?? '')) === $this->host ? $href : null;
    }
}
