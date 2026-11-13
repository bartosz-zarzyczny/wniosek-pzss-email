#!/usr/bin/env python3
"""Wyciąga zawodników z PDF-a z wynikami zawodów i wypisuje JSON na stdout.

Użycie: python3 tools/parse_pdf.py plik.pdf
Wynik: {"rows": [[dyscyplina|null, "numer_licencji", "nazwisko imie"], ...], "hints": ["shotgun", ...]}
Używa dołączonej biblioteki pypdf (tools/vendor), więc nie wymaga instalacji pakietów.
"""
import json
import re
import sys
import unicodedata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent / 'vendor'))

from pypdf import PdfReader  # noqa: E402

RIFLE_PROTOCOLS = ('pcc', 'mini rifle')
EXTRA_CHARS = str.maketrans({'ł': 'l', 'ø': 'o', 'đ': 'd', 'ß': 'ss', 'æ': 'ae', 'œ': 'oe'})
HINTS = {
    'shotgun': re.compile(r'shotgun|strzelb', re.I),
    'pistol': re.compile(r'pistolet|pistol\b|\bpcc\b', re.I),
    'rifle': re.compile(r'karabin|rifle', re.I),
}
LAYOUT_HEADER = re.compile(r'Nazwisko\s+Imi[ęe]\s+(?:nr\s+)?licencji', re.I)


def normalize(value):
    value = value.lower().translate(EXTRA_CHARS)
    value = unicodedata.normalize('NFKD', value)
    value = ''.join(c for c in value if not unicodedata.combining(c))
    return ' '.join(re.sub(r'[^a-z0-9]+', ' ', value).split())


def person_from_upper_surname(prefix):
    """'KARAŚ Marcin COLT Łódź' -> 'KARAŚ Marcin' (nazwisko wielkimi literami + imię)."""
    tokens = prefix.split()
    surname = []
    while tokens and tokens[0].upper() == tokens[0] and any(c.isalpha() for c in tokens[0]):
        surname.append(tokens.pop(0))
    if not surname:
        return ' '.join(prefix.split()[:2])
    return ' '.join(surname + tokens[:1])


TABLE_ROW = re.compile(r'^\s*\d+\.?\s+(.+?)\s+L-(\d{3,6})(?=[\s/]|$)')
PRACTISCORE_ROW = re.compile(r'^\s*\d+\s*(?:\(DQ\)\s*)?([^,\d/]+),\s*([^,\d/]+?)\s*(?:L-)?(\d{3,6})(?=[/\s])')
LAYOUT_ROW = re.compile(r'^\s*\d+\s+(.+?)\s{2,}(.+?)\s{2,}L-(\d{3,6})\s')


PROTOCOL_HEADER = re.compile(r'Protok[oó]ł\s+nr\s*\d+\s*[:–-]*\s*(.+)', re.I)


def discipline_of(title):
    title = title.lower()
    if re.search(r'strzelb|shotgun', title):
        return 'shotgun'
    if re.search(r'karabin|rifle|\bpcc\b', title):
        return 'rifle'
    if re.search(r'pistolet|pistol|rewolwer', title):
        return 'pistol'
    return None


def valid(license, name_key):
    return license[0] != '0' and name_key and not re.search(r'\d', name_key)


def main(path):
    reader = PdfReader(path)
    rows = set()
    texts = []
    for page in reader.pages:
        texts.append(page.extract_text() or '')

    section = None
    for text in texts:
        for line in text.splitlines():
            header = PROTOCOL_HEADER.search(line)
            if header:
                section = discipline_of(header.group(1))
                continue
            if ',' in line:
                match = PRACTISCORE_ROW.match(line)
                if match:
                    rows.add((section, match.group(3), normalize(match.group(1) + ' ' + match.group(2))))
                    continue
            match = TABLE_ROW.match(line)
            if match:
                rows.add((section, match.group(2), normalize(person_from_upper_surname(match.group(1)))))

    rows = {row for row in rows if valid(row[1], row[2])}
    classified = {row[1] for row in rows if row[0] is not None}
    rows = {row for row in rows if row[0] is not None or row[1] not in classified}
    layout_rows = set()
    if any(LAYOUT_HEADER.search(text) for text in texts):
        discipline = None
        for page in reader.pages:
            text = page.extract_text(extraction_mode='layout') or ''
            header = re.search(r'Protokół nr \d+:\s*([^\n]+)', text)
            if header:
                title = header.group(1).strip().lower()
                discipline = 'rifle' if any(title.startswith(p) for p in RIFLE_PROTOCOLS) else 'pistol'
            for line in text.splitlines():
                match = LAYOUT_ROW.match(line)
                if match:
                    layout_rows.add((discipline, match.group(3), normalize(match.group(1) + ' ' + match.group(2))))
        licenses_in_layout = {row[1] for row in layout_rows}
        rows = {row for row in rows if row[1] not in licenses_in_layout} | layout_rows

    head = '\n'.join(texts[:3])
    hints = [name for name, pattern in HINTS.items() if pattern.search(head)]
    print(json.dumps({'rows': sorted(rows, key=lambda r: (r[0] or '', r[1])), 'hints': hints}, ensure_ascii=False))


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('Użycie: parse_pdf.py plik.pdf')
    main(sys.argv[1])
