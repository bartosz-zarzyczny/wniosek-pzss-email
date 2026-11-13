<?php
declare(strict_types=1);

final class PdfFiller
{
    private string $fontData;
    private array $tables = [];
    private array $glyphs = [];
    private array $glyphWidths = [];
    private int $unitsPerEm;
    private int $ascent;
    private int $descent;
    private array $fontBox;

    public function fill(
        string $templatePath,
        string $fontPath,
        array $applicant,
        array $competitions,
        int $year,
        array $disciplines
    ): string {
        $template = file_get_contents($templatePath);
        $this->fontData = (string) file_get_contents($fontPath);

        if ($template === false || $this->fontData === '') {
            throw new RuntimeException('Nie można odczytać szablonu PDF lub pliku fontu.');
        }

        $this->readFontTables();
        $content = $this->buildContent($applicant, $competitions, $year, $disciplines);
        return $this->appendRevision($template, $content);
    }

    private function readFontTables(): void
    {
        if (strlen($this->fontData) < 12 || substr($this->fontData, 0, 4) !== "\x00\x01\x00\x00") {
            throw new RuntimeException('Plik fontu nie jest obsługiwanym fontem TrueType.');
        }

        $count = $this->uint16($this->fontData, 4);
        for ($index = 0; $index < $count; $index++) {
            $record = 12 + ($index * 16);
            $tag = substr($this->fontData, $record, 4);
            $offset = $this->uint32($this->fontData, $record + 8);
            $length = $this->uint32($this->fontData, $record + 12);
            if ($offset + $length > strlen($this->fontData)) {
                throw new RuntimeException('Font zawiera nieprawidłową tabelę.');
            }
            $this->tables[$tag] = ['offset' => $offset, 'length' => $length];
        }

        foreach (['cmap', 'head', 'hhea', 'hmtx', 'maxp'] as $requiredTable) {
            if (!isset($this->tables[$requiredTable])) {
                throw new RuntimeException('Font nie zawiera wymaganej tabeli ' . $requiredTable . '.');
            }
        }

        $head = $this->tables['head']['offset'];
        $hhea = $this->tables['hhea']['offset'];
        $this->unitsPerEm = $this->uint16($this->fontData, $head + 18);
        $this->ascent = $this->int16($this->fontData, $hhea + 4);
        $this->descent = $this->int16($this->fontData, $hhea + 6);
        $this->fontBox = [
            $this->int16($this->fontData, $head + 36),
            $this->int16($this->fontData, $head + 38),
            $this->int16($this->fontData, $head + 40),
            $this->int16($this->fontData, $head + 42),
        ];

        if ($this->unitsPerEm < 16 || $this->unitsPerEm > 16384) {
            throw new RuntimeException('Font zawiera nieprawidłową wartość unitsPerEm.');
        }
    }

    private function buildContent(array $applicant, array $competitions, int $year, array $disciplines): string
    {
        $commands = ["q", "0 g"];
        $this->drawText($commands, $applicant['patent'], 179, 661, 8, 119);
        $this->drawText($commands, $applicant['license'], 427, 661, 8, 107);
        foreach (str_split($applicant['pesel']) as $index => $digit) {
            $this->drawText($commands, $digit, 111 + ($index * 14.19), 632, 8, 10);
        }
        $this->drawText($commands, $applicant['phone'], 385, 632, 8, 150);

        $fullName = $this->polishUpper($applicant['last_name']) . ' ' . $applicant['first_name'];
        $this->drawText($commands, $fullName, 150, 600, 9, 386);
        $this->drawText($commands, substr((string) $year, -2), 375, 529, 8, 36);

        $disciplinePositions = [
            'pistol' => 291,
            'rifle' => 376,
            'shotgun' => 520,
        ];
        foreach ($disciplinePositions as $category => $x) {
            if (in_array($category, $disciplines, true)) {
                $this->drawText($commands, 'X', $x, 691, 8, 10);
            }
        }

        $rowBaselines = [462.72, 433.80, 405.00, 376.08, 346.32, 317.40, 287.52, 258.72];
        $categoryPositions = [
            'pistol' => 369.5,
            'rifle' => 386.5,
            'shotgun' => 403.5,
        ];
        foreach ($competitions as $index => $competition) {
            $baseline = $rowBaselines[$index];
            $this->drawCompetitionName($commands, $competition['name'], $baseline, $index + 1);
            $this->drawText($commands, $competition['date_display'], 245, $baseline, 6.2, 53);
            $this->drawText($commands, $competition['location'], 301, $baseline, 6.2, 60);
            $this->drawText($commands, $competition['remarks'], 468, $baseline, 6.2, 66);

            foreach ($competition['categories'] as $category) {
                $this->drawText($commands, 'X', $categoryPositions[$category], $baseline, 6, 9);
            }
            $calendar = $competition['calendar'] === 'other' ? '-' : $competition['calendar'];
            $this->drawText($commands, $calendar, 424, $baseline, 6.2, 39);
        }

        $date = $applicant['application_date_display'];
        $this->drawText($commands, $applicant['application_place'] . ', ' . $date, 61, 163, 7, 235);
        $commands[] = 'Q';
        return implode("\n", $commands) . "\n";
    }

    private function drawCompetitionName(array &$commands, string $name, float $baseline, int $rowNumber): void
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new RuntimeException('Start ' . $rowNumber . ': nieprawidłowa nazwa zawodów.');
        }

        for ($size = 6.2; $size >= 4.0; $size = round($size - 0.2, 1)) {
            $lines = [];
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->measureCodePoints($this->utf8CodePoints($candidate), $size) <= 154) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                foreach ($this->utf8CodePoints($word) as $point) {
                    $character = $this->encodeCodePoints([$point]);
                    if ($line !== '' &&
                        $this->measureCodePoints($this->utf8CodePoints($line . $character), $size) > 154) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $character;
                }
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            if (count($lines) <= 3) {
                $firstBaseline = $baseline + ((count($lines) - 1) * 3.5);
                foreach ($lines as $lineIndex => $text) {
                    $this->drawText($commands, $text, 86, $firstBaseline - ($lineIndex * 7), $size, 154);
                }
                return;
            }
        }

        throw new RuntimeException('Start ' . $rowNumber . ': nazwa zawodów nie mieści się w PDF. Skróć ją.');
    }

    private function drawText(array &$commands, string $text, float $x, float $y, float $size, float $maxWidth): void
    {
        $text = $this->fitText($text, $size, $maxWidth);
        if ($text === '') {
            return;
        }

        $hex = '';
        foreach ($this->utf8CodePoints($text) as $codePoint) {
            $glyph = $this->glyphForCodePoint($codePoint);
            $this->glyphs[$glyph] = $codePoint;
            $hex .= sprintf('%04X', $glyph);
        }

        $commands[] = sprintf(
            'BT /ZSD %s Tf 1 0 0 1 %s %s Tm <%s> Tj ET',
            $this->pdfNumber($size),
            $this->pdfNumber($x),
            $this->pdfNumber($y),
            $hex
        );
    }

    private function fitText(string $text, float $size, float $maxWidth): string
    {
        $points = $this->utf8CodePoints($text);
        while ($points !== [] && $this->measureCodePoints($points, $size) > $maxWidth) {
            array_pop($points);
        }
        if ($points !== [] && count($points) < count($this->utf8CodePoints($text))) {
            while ($points !== [] && $this->measureCodePoints(array_merge($points, [46, 46, 46]), $size) > $maxWidth) {
                array_pop($points);
            }
            $points = array_merge($points, [46, 46, 46]);
        }
        return $this->encodeCodePoints($points);
    }

    private function measureCodePoints(array $points, float $size): float
    {
        $units = 0;
        foreach ($points as $codePoint) {
            $glyph = $this->glyphForCodePoint($codePoint);
            $units += $this->advanceWidth($glyph);
        }
        return ($units / $this->unitsPerEm) * $size;
    }

    private function advanceWidth(int $glyph): int
    {
        if (isset($this->glyphWidths[$glyph])) {
            return $this->glyphWidths[$glyph];
        }

        $hhea = $this->tables['hhea']['offset'];
        $hmtx = $this->tables['hmtx']['offset'];
        $maxp = $this->tables['maxp']['offset'];
        $numberOfMetrics = $this->uint16($this->fontData, $hhea + 34);
        $numberOfGlyphs = $this->uint16($this->fontData, $maxp + 4);
        if ($glyph < 0 || $glyph >= $numberOfGlyphs || $numberOfMetrics < 1) {
            throw new RuntimeException('Font nie zawiera prawidłowych metryk glifu.');
        }

        $metricIndex = min($glyph, $numberOfMetrics - 1);
        $width = $this->uint16($this->fontData, $hmtx + ($metricIndex * 4));
        $this->glyphWidths[$glyph] = $width;
        return $width;
    }

    private function glyphForCodePoint(int $codePoint): int
    {
        if (isset($this->glyphs['cp:' . $codePoint])) {
            return $this->glyphs['cp:' . $codePoint];
        }

        $cmap = $this->tables['cmap']['offset'];
        $recordCount = $this->uint16($this->fontData, $cmap + 2);
        $selectedOffset = null;
        $selectedFormat = null;
        $bestScore = -1;

        for ($index = 0; $index < $recordCount; $index++) {
            $record = $cmap + 4 + ($index * 8);
            $platform = $this->uint16($this->fontData, $record);
            $encoding = $this->uint16($this->fontData, $record + 2);
            $offset = $this->uint32($this->fontData, $record + 4);
            $format = $this->uint16($this->fontData, $cmap + $offset);
            if ($format !== 4 && $format !== 12) {
                continue;
            }

            $score = ($format === 12 ? 2 : 1) + ($platform === 0 ? 2 : 0) +
                ($platform === 3 && ($encoding === 1 || $encoding === 10) ? 2 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $selectedOffset = $cmap + $offset;
                $selectedFormat = $format;
            }
        }

        if ($selectedOffset === null || $selectedFormat === null) {
            throw new RuntimeException('Font nie zawiera obsługiwanej mapy Unicode.');
        }

        $glyph = $selectedFormat === 12
            ? $this->glyphFromFormat12($selectedOffset, $codePoint)
            : $this->glyphFromFormat4($selectedOffset, $codePoint);

        if ($glyph < 1) {
            throw new RuntimeException('Font nie obsługuje znaku Unicode U+' . strtoupper(dechex($codePoint)) . '.');
        }

        $this->glyphs['cp:' . $codePoint] = $glyph;
        return $glyph;
    }

    private function glyphFromFormat12(int $offset, int $codePoint): int
    {
        $groups = $this->uint32($this->fontData, $offset + 12);
        for ($index = 0; $index < $groups; $index++) {
            $group = $offset + 16 + ($index * 12);
            $start = $this->uint32($this->fontData, $group);
            $end = $this->uint32($this->fontData, $group + 4);
            if ($codePoint >= $start && $codePoint <= $end) {
                return $this->uint32($this->fontData, $group + 8) + ($codePoint - $start);
            }
        }
        return 0;
    }

    private function glyphFromFormat4(int $offset, int $codePoint): int
    {
        if ($codePoint > 0xFFFF) {
            return 0;
        }
        $segmentCount = intdiv($this->uint16($this->fontData, $offset + 6), 2);
        $endCodes = $offset + 14;
        $startCodes = $endCodes + ($segmentCount * 2) + 2;
        $deltaCodes = $startCodes + ($segmentCount * 2);
        $rangeOffsets = $deltaCodes + ($segmentCount * 2);

        for ($index = 0; $index < $segmentCount; $index++) {
            $end = $this->uint16($this->fontData, $endCodes + ($index * 2));
            if ($codePoint > $end) {
                continue;
            }
            $start = $this->uint16($this->fontData, $startCodes + ($index * 2));
            if ($codePoint < $start) {
                return 0;
            }
            $delta = $this->int16($this->fontData, $deltaCodes + ($index * 2));
            $rangeOffsetAddress = $rangeOffsets + ($index * 2);
            $rangeOffset = $this->uint16($this->fontData, $rangeOffsetAddress);
            if ($rangeOffset === 0) {
                return ($codePoint + $delta) & 0xFFFF;
            }
            $glyphAddress = $rangeOffsetAddress + $rangeOffset + (($codePoint - $start) * 2);
            $glyph = $this->uint16($this->fontData, $glyphAddress);
            return $glyph === 0 ? 0 : (($glyph + $delta) & 0xFFFF);
        }
        return 0;
    }

    private function appendRevision(string $template, string $content): string
    {
        if (!preg_match('/(?:^|\r?\n)4\s+0\s+obj\s*(.*?)\s*endobj/s', $template, $pageMatch)) {
            throw new RuntimeException('Szablon PDF nie zawiera oczekiwanej strony.');
        }
        $pageBody = $pageMatch[1];
        if (strpos($pageBody, '/Type/Page') === false || strpos($pageBody, '/Contents 5 0 R') === false) {
            throw new RuntimeException('Układ szablonu PDF różni się od obsługiwanej wersji.');
        }

        $pageBody = preg_replace('/(\/Font\s*<<)/', '$1 /ZSD 46 0 R', $pageBody, 1, $fontReplacements);
        $pageBody = preg_replace('/\/Contents\s+5\s+0\s+R/', '/Contents [5 0 R 47 0 R]', $pageBody, 1, $contentReplacements);
        if ($fontReplacements !== 1 || $contentReplacements !== 1) {
            throw new RuntimeException('Nie można przygotować zasobów strony PDF.');
        }

        if (!preg_match('/(?:^|\r?\n)startxref\s+(\d+)\s+%%EOF/', $template, $xrefMatch)) {
            throw new RuntimeException('Szablon PDF nie zawiera poprawnej tabeli xref.');
        }
        $previousXref = (int) $xrefMatch[1];
        $trailerPosition = strrpos($template, 'trailer');
        $trailerSource = $trailerPosition === false ? '' : substr($template, $trailerPosition);
        if (!preg_match('/\/Root\s+(\d+\s+\d+\s+R)/', $trailerSource, $rootMatch) ||
            !preg_match('/\/Info\s+(\d+\s+\d+\s+R)/', $trailerSource, $infoMatch) ||
            !preg_match('/\/ID\s*(\[[^\]]+\])/', $trailerSource, $idMatch) ||
            !preg_match('/\/Size\s+(\d+)/', $trailerSource, $sizeMatch)) {
            throw new RuntimeException('Nie można odczytać metadanych szablonu PDF.');
        }

        $nextObject = (int) $sizeMatch[1];
        if ($nextObject !== 42) {
            throw new RuntimeException('Szablon PDF ma nieobsługiwany układ obiektów.');
        }
        $fontFileId = $nextObject;
        $descriptorId = $nextObject + 1;
        $cidFontId = $nextObject + 2;
        $toUnicodeId = $nextObject + 3;
        $type0FontId = $nextObject + 4;
        $contentId = $nextObject + 5;

        $usedGlyphs = $this->glyphs;
        $mapping = [];
        $widths = [];
        foreach ($usedGlyphs as $glyph => $codePoint) {
            if (!is_int($glyph)) {
                continue;
            }
            $mapping[$glyph] = $codePoint;
            $widths[$glyph] = (int) round(($this->advanceWidth($glyph) * 1000) / $this->unitsPerEm);
        }
        ksort($mapping);
        ksort($widths);

        $compressedFont = gzcompress($this->fontData);
        if ($compressedFont === false) {
            throw new RuntimeException('Nie można skompresować fontu PDF.');
        }
        $fontFile = $this->streamObject(
            '/Length1 ' . strlen($this->fontData) . ' /Filter /FlateDecode',
            $compressedFont
        );

        $scale = 1000 / $this->unitsPerEm;
        $fontBox = array_map(function (int $value) use ($scale): int {
            return (int) round($value * $scale);
        }, $this->fontBox);
        $descriptor = sprintf(
            '<< /Type /FontDescriptor /FontName /SourceSans3Regular /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $fontBox[0],
            $fontBox[1],
            $fontBox[2],
            $fontBox[3],
            (int) round($this->ascent * $scale),
            (int) round($this->descent * $scale),
            (int) round($this->ascent * $scale),
            $fontFileId
        );

        $widthEntries = [];
        foreach ($widths as $glyph => $width) {
            $widthEntries[] = $glyph . ' [' . $width . ']';
        }
        $cidFont = sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /SourceSans3Regular /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W [%s] /CIDToGIDMap /Identity >>',
            $descriptorId,
            implode(' ', $widthEntries)
        );

        $cmap = $this->buildToUnicodeCMap($mapping);
        $toUnicode = $this->streamObject('', $cmap);
        $type0Font = sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /SourceSans3Regular /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
            $cidFontId,
            $toUnicodeId
        );
        $contentStream = $this->streamObject('', $content);

        $objects = [
            [4, $pageBody],
            [$fontFileId, $fontFile],
            [$descriptorId, $descriptor],
            [$cidFontId, $cidFont],
            [$toUnicodeId, $toUnicode],
            [$type0FontId, $type0Font],
            [$contentId, $contentStream],
        ];

        $appendix = "\r\n";
        $offsets = [];
        foreach ($objects as [$objectId, $body]) {
            $offsets[$objectId] = strlen($template) + strlen($appendix);
            $appendix .= $objectId . " 0 obj\r\n" . $body . "\r\nendobj\r\n";
        }

        $xrefOffset = strlen($template) + strlen($appendix);
        $appendix .= "xref\r\n4 1\r\n" . sprintf("%010d 00000 n \r\n", $offsets[4]);
        $appendix .= $fontFileId . " 6\r\n";
        for ($objectId = $fontFileId; $objectId <= $contentId; $objectId++) {
            $appendix .= sprintf("%010d 00000 n \r\n", $offsets[$objectId]);
        }
        $appendix .= sprintf(
            "trailer\r\n<< /Size %d /Root %s /Info %s /ID %s /Prev %d >>\r\nstartxref\r\n%d\r\n%%%%EOF\r\n",
            $contentId + 1,
            $rootMatch[1],
            $infoMatch[1],
            $idMatch[1],
            $previousXref,
            $xrefOffset
        );

        return $template . $appendix;
    }

    private function buildToUnicodeCMap(array $mapping): string
    {
        $entries = [];
        foreach ($mapping as $glyph => $codePoint) {
            $unicode = '';
            if ($codePoint <= 0xFFFF) {
                $unicode = sprintf('%04X', $codePoint);
            } else {
                $value = $codePoint - 0x10000;
                $unicode = sprintf('%04X%04X', 0xD800 + ($value >> 10), 0xDC00 + ($value & 0x3FF));
            }
            $entries[] = sprintf('<%04X> <%s>', $glyph, $unicode);
        }

        $chunks = array_chunk($entries, 100);
        $cmap = [
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def',
            '/CMapName /Adobe-Identity-UCS def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            '<0000> <FFFF>',
            'endcodespacerange',
        ];
        foreach ($chunks as $chunk) {
            $cmap[] = count($chunk) . ' beginbfchar';
            $cmap = array_merge($cmap, $chunk);
            $cmap[] = 'endbfchar';
        }
        $cmap[] = 'endcmap';
        $cmap[] = 'CMapName currentdict /CMap defineresource pop';
        $cmap[] = 'end';
        $cmap[] = 'end';
        return implode("\n", $cmap) . "\n";
    }

    private function streamObject(string $dictionaryEntries, string $stream): string
    {
        $dictionary = '<< /Length ' . strlen($stream);
        if ($dictionaryEntries !== '') {
            $dictionary .= ' ' . $dictionaryEntries;
        }
        return $dictionary . " >>\nstream\n" . $stream . "\nendstream";
    }

    private function utf8CodePoints(string $text): array
    {
        if (!preg_match('//u', $text)) {
            throw new RuntimeException('Wprowadzony tekst nie jest poprawnym UTF-8.');
        }

        $points = [];
        $length = strlen($text);
        for ($index = 0; $index < $length;) {
            $first = ord($text[$index]);
            if ($first < 0x80) {
                $points[] = $first;
                $index++;
            } elseif (($first & 0xE0) === 0xC0) {
                $points[] = (($first & 0x1F) << 6) | (ord($text[$index + 1]) & 0x3F);
                $index += 2;
            } elseif (($first & 0xF0) === 0xE0) {
                $points[] = (($first & 0x0F) << 12) |
                    ((ord($text[$index + 1]) & 0x3F) << 6) |
                    (ord($text[$index + 2]) & 0x3F);
                $index += 3;
            } else {
                $points[] = (($first & 0x07) << 18) |
                    ((ord($text[$index + 1]) & 0x3F) << 12) |
                    ((ord($text[$index + 2]) & 0x3F) << 6) |
                    (ord($text[$index + 3]) & 0x3F);
                $index += 4;
            }
        }
        return $points;
    }

    private function encodeCodePoints(array $points): string
    {
        $result = '';
        foreach ($points as $point) {
            if ($point < 0x80) {
                $result .= chr($point);
            } elseif ($point < 0x800) {
                $result .= chr(0xC0 | ($point >> 6)) . chr(0x80 | ($point & 0x3F));
            } elseif ($point < 0x10000) {
                $result .= chr(0xE0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3F)) .
                    chr(0x80 | ($point & 0x3F));
            } else {
                $result .= chr(0xF0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3F)) .
                    chr(0x80 | (($point >> 6) & 0x3F)) . chr(0x80 | ($point & 0x3F));
            }
        }
        return $result;
    }

    private function polishUpper(string $text): string
    {
        return strtr(strtoupper($text), [
            'ą' => 'Ą',
            'ć' => 'Ć',
            'ę' => 'Ę',
            'ł' => 'Ł',
            'ń' => 'Ń',
            'ó' => 'Ó',
            'ś' => 'Ś',
            'ź' => 'Ź',
            'ż' => 'Ż',
        ]);
    }

    private function pdfNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function uint16(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) {
            throw new RuntimeException('Nieprawidłowy offset w pliku fontu.');
        }
        return unpack('nvalue', substr($data, $offset, 2))['value'];
    }

    private function int16(string $data, int $offset): int
    {
        $value = $this->uint16($data, $offset);
        return $value > 32767 ? $value - 65536 : $value;
    }

    private function uint32(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            throw new RuntimeException('Nieprawidłowy offset w pliku fontu.');
        }
        return unpack('Nvalue', substr($data, $offset, 4))['value'];
    }
}
