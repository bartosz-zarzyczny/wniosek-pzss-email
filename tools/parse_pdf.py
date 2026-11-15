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

EXTRA_CHARS = str.maketrans({'ł': 'l', 'ø': 'o', 'đ': 'd', 'ß': 'ss', 'æ': 'ae', 'œ': 'oe'})
HINTS = {
    'shotgun': re.compile(r'shotgun|strzelb', re.I),
    'pistol': re.compile(r'pistolet|pistol\b|\bpcc\b', re.I),
    'rifle': re.compile(r'karabin|rifle', re.I),
}
DYNAMIC_CATEGORIES = {
    'classic': ('pistol', 'CLASSIC'),
    'mini rifle': ('rifle', 'MINI RIFLE'),
    'open': ('pistol', 'OPEN'),
    'optics': ('pistol', 'OPTICS'),
    'pc optics': ('rifle', 'PC OPTICS'),
    'production': ('pistol', 'PRODUCTION'),
    'production optics': ('pistol', 'PRODUCTION OPTICS'),
    'standard': ('pistol', 'STANDARD'),
}
DYNAMIC_SENIOR_CATEGORIES = {
    'pc optics senior',
    'production senior',
    'production optics senior',
    'optics senior',
    'standard senior',
}
LAYOUT_PERSON_HEADER = re.compile(r'Nazwisko\s+(?:i\s+)?Imi[ęe]', re.I)


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
PRACTISCORE_NO_LICENSE_ROW = re.compile(
    r'^\s*\d+\s*(?:\(DQ\)\s*)?([^,\d/]+),\s*([^,\d/]+?)(?=,\s|$)'
)
LAYOUT_ROW = re.compile(r'^\s*(?:\d+\.?\s+)?(.+?)\s{2,}(.+?)\s{2,}L-(\d{3,6})\s')
RANKED_ROW = re.compile(r'^\s*\d+\.?\s+(.+)$')


PROTOCOL_HEADER = re.compile(r'^\s*Protok[oó]ł\s+nr\s*\d+\s*[:–—-]*\s*(.*?)\s*$', re.I)
RESULTS_HEADER = re.compile(
    r'^\s*(?:Rezultaty|Wyniki)\s+konkurencji\b\s*[:–—-]?\s*(.*?)\s*$',
    re.I,
)
CATEGORY_HEADER = re.compile(
    r'^\s*(?:kategoria|klasa|konkurencja)\b\s*[:–—-]?\s*(.*?)\s*$',
    re.I,
)


def discipline_of(title):
    title = title.lower()
    if re.search(r'strzelb|shotgun', title):
        return 'shotgun'
    if re.search(r'karabin|rifle|\bpcc\b', title):
        return 'rifle'
    if re.search(r'pistolet|pistol|rewolwer', title):
        return 'pistol'
    return None


def competition_name_of(title):
    """Normalizuje tytuł protokołu do nazwy konkurencji pokazywanej we wniosku."""
    title = re.sub(r'^\s*[-:–—]+\s*', '', title.strip())
    normalized = normalize(title)
    if 'arsenal shotgun trophy' in normalized:
        return 'STANDARD MANUAL'
    if 'strzelba dynamiczna' in normalized and 'standard manual' in normalized:
        return 'Strzelba dynamiczna - Standard Manual'
    if 'strzelba dynamiczna' in normalized and 'open' in normalized.split():
        return 'Strzelba dynamiczna - OPEN'
    if 'pistolet' in normalized and re.search(r'hop\s*stop', normalized):
        return 'Pistolet .22LR "HopStop"'
    if 'pistolet' in normalized and 'mini czarny kon' in normalized:
        return 'Pistolet .22LR Czarny Koń'
    return title


def category_key(title):
    key = normalize(title)
    prefixes = (
        'kategoria ',
        'klasa ',
        'konkurencja ',
    )
    changed = True
    while changed:
        changed = False
        for prefix in prefixes:
            if key.startswith(prefix):
                key = key[len(prefix):]
                changed = True
                break
    return key


def dynamic_category(title):
    key = category_key(title)
    for prefix in (
        'pistolet dynamiczny ',
        'karabin dynamiczny ',
        'dynamic pistol ',
        'dynamic rifle ',
    ):
        if key.startswith(prefix):
            key = key[len(prefix):]
            break
    if key in DYNAMIC_SENIOR_CATEGORIES:
        return None, '', True
    category = DYNAMIC_CATEGORIES.get(key)
    if category is None:
        return None, '', False
    return category[0], category[1], False


def section_from_header(line):
    match = RESULTS_HEADER.match(line)
    if match:
        discipline, name, skip = dynamic_category(match.group(1))
        return True, discipline, name, skip or not name

    match = CATEGORY_HEADER.match(line)
    if match:
        discipline, name, skip = dynamic_category(match.group(1))
        return True, discipline, name, skip or not name

    match = PROTOCOL_HEADER.match(line)
    if match:
        title = match.group(1).strip()
        key = category_key(title)
        discipline, name, skip = dynamic_category(title)
        if name or skip:
            return True, discipline, name, skip
        if key != normalize(title):
            return True, None, '', True
        discipline = discipline_of(title)
        return True, discipline, competition_name_of(title) if discipline is not None else '', False

    key = category_key(line)
    if key in DYNAMIC_CATEGORIES or key in DYNAMIC_SENIOR_CATEGORIES:
        discipline, name, skip = dynamic_category(line)
        return True, discipline, name, skip
    return False, None, '', False


def valid(license, name_key):
    return (not license or license[0] != '0') and name_key and not re.search(r'\d', name_key)


def rows_from_line(line, section, competition_name):
    rows = set()
    if ',' in line:
        match = PRACTISCORE_ROW.match(line)
        if match:
            rows.add((section, match.group(3), normalize(match.group(1) + ' ' + match.group(2)), competition_name))
            return rows
        match = PRACTISCORE_NO_LICENSE_ROW.match(line)
        if match:
            name_key = normalize(match.group(1) + ' ' + match.group(2))
            if valid('', name_key):
                rows.add((section, '', name_key, competition_name))
            return rows

    match = TABLE_ROW.match(line)
    if match:
        rows.add((section, match.group(2), normalize(person_from_upper_surname(match.group(1))), competition_name))
        return rows

    match = RANKED_ROW.match(line)
    if match:
        name_key = normalize(person_from_upper_surname(match.group(1)))
        if len(name_key.split()) >= 2 and valid('', name_key):
            rows.add((section, '', name_key, competition_name))
    return rows


def extract_rows(texts, layout_texts=None):
    rows = set()
    section = None
    competition_name = ''
    skip_section = False
    for text in texts:
        for line in text.splitlines():
            is_header, next_section, next_name, skip = section_from_header(line)
            if is_header:
                section = next_section
                competition_name = next_name
                skip_section = skip
                continue
            if not skip_section:
                rows.update(rows_from_line(line, section, competition_name))

    rows = {row for row in rows if valid(row[1], row[2])}
    classified = {row[1] for row in rows if row[0] is not None}
    rows = {row for row in rows if row[0] is not None or row[1] not in classified}
    layout_rows = set()
    if layout_texts and any(LAYOUT_PERSON_HEADER.search(text) for text in texts):
        discipline = None
        layout_competition_name = ''
        layout_skip_section = False
        for text in layout_texts:
            for line in text.splitlines():
                is_header, next_section, next_name, skip = section_from_header(line)
                if is_header:
                    discipline = next_section
                    layout_competition_name = next_name
                    layout_skip_section = skip
                    continue
                if layout_skip_section:
                    continue
                match = LAYOUT_ROW.match(line)
                if match:
                    layout_rows.add((discipline, match.group(3), normalize(match.group(1) + ' ' + match.group(2)), layout_competition_name))
                elif not re.search(r'\bL-\d{3,6}\b', line):
                    match = RANKED_ROW.match(line)
                    if match:
                        name_key = normalize(person_from_upper_surname(match.group(1)))
                        if len(name_key.split()) >= 2 and valid('', name_key):
                            layout_rows.add((discipline, '', name_key, layout_competition_name))
        licenses_in_layout = {row[1] for row in layout_rows if row[1]}
        names_without_license_in_layout = {
            (row[0], row[2], row[3]) for row in layout_rows if not row[1]
        }
        rows = {
            row for row in rows
            if row[1] not in licenses_in_layout and
            not (not row[1] and (row[0], row[2], row[3]) in names_without_license_in_layout)
        } | layout_rows
    return rows


def main(path):
    reader = PdfReader(path)
    texts = [page.extract_text() or '' for page in reader.pages]
    layout_texts = None
    if any(LAYOUT_PERSON_HEADER.search(text) for text in texts):
        layout_texts = [page.extract_text(extraction_mode='layout') or '' for page in reader.pages]
    rows = extract_rows(texts, layout_texts)

    head = '\n'.join(texts[:3])
    hints = [name for name, pattern in HINTS.items() if pattern.search(head)]
    print(json.dumps({'rows': sorted(rows, key=lambda r: (r[0] or '', r[1])), 'hints': hints}, ensure_ascii=False))


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('Użycie: parse_pdf.py plik.pdf')
    main(sys.argv[1])
