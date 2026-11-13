#!/usr/bin/env python3
"""Zamienia PDF-y z wynikami zawodów (download_pdf/) na plik SQL (database/data.sql).

Uruchamiaj lokalnie (wymaga: pip install pypdf). Wygenerowany plik zaimportuj
w phpMyAdmin po zaimportowaniu database/schema.sql. Plik zawiera dane osobowe
i jest wykluczony z repozytorium przez .gitignore.
"""
import re
import sys
import unicodedata
from pathlib import Path

from pypdf import PdfReader

ROOT = Path(__file__).resolve().parent.parent
PDF_DIR = ROOT / 'download_pdf'
OUTPUT = ROOT / 'database' / 'data.sql'

# Metadane zawodów są wpisane ręcznie, bo formaty PDF-ów bardzo się różnią.
# Pliki spoza tej listy (np. wyniki bez numerów licencji) są pomijane.
EVENTS = [
    {
        'file': '2026-01-17_R_ASENAL_KRAKOW.pdf',
        'name': 'Arsenal Shotgun Trophy L1 Liga Zimowa - Runda Noworoczna',
        'date': '2026-01-17', 'location': 'Kraków', 'calendar': 'MZSS',
        'parser': 'arsenal', 'discipline': 'shotgun',
    },
    {
        'file': '2026-02-13_R_Legion_Tarnow.pdf',
        'name': 'Liga Południa 1/5 IPSC (Legion Tarnów)',
        'date': '2026-02-13', 'location': 'Strzelnica BIG GUN Kraków', 'calendar': 'MZSS',
        'parser': 'legion',
    },
    {
        'file': '2026-02-21-ARSENAL SHOTGUN TROPHY L1 LIGA ZIMOWA Runda Walentynkowa 2026 _ PractiScore.pdf',
        'name': 'Arsenal Shotgun Trophy L1 Liga Zimowa - Runda Walentynkowa',
        'date': '2026-02-21', 'location': 'Kraków', 'calendar': 'MZSS',
        'parser': 'practiscore', 'discipline': 'shotgun',
    },
    {
        'file': '2026-03-07_R_ASENAL_KRAKOW.pdf',
        'name': 'Arsenal Shotgun Trophy L1 Liga Zimowa - Runda Rumburaka',
        'date': '2026-03-07', 'location': 'Kraków', 'calendar': 'MZSS',
        'parser': 'arsenal', 'discipline': 'shotgun',
    },
    {
        'file': '2026-04-19-Rezultaty_SILESIA_CUP_STRZELBA_L2.pdf',
        'name': 'Silesia Cup 2026 Strzelba IPSC L2',
        'date': '2026-04-18', 'location': 'Przyszowice, Strzelnica TAS', 'calendar': 'PZSS',
        'parser': 'results_table', 'discipline': 'shotgun',
    },
    {
        'file': '2026-04-17-Rezultaty_FAM-PIONKI_GRYF_SHOTGUN_CUP_2026_14-17-05-2026_Wiechlice.pdf',
        'name': 'FAM-Pionki Gryf Shotgun Cup 2026 (L3)',
        'date': '2026-05-14', 'location': 'Wiechlice, Airbase Shooting Range', 'calendar': 'PZSS',
        'parser': 'results_table', 'discipline': 'shotgun',
    },
]

RIFLE_PROTOCOLS = ('pcc', 'mini rifle')
EXTRA_CHARS = str.maketrans({'ł': 'l', 'ø': 'o', 'đ': 'd', 'ß': 'ss', 'æ': 'ae', 'œ': 'oe'})


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


def parse_arsenal(reader, event):
    rows = set()
    for page in reader.pages:
        for line in page.extract_text().splitlines():
            match = re.match(r'^\s*\d+\s+(.+?)\s+L-(\d{3,6})\s', line)
            if match:
                rows.add((event['discipline'], match.group(2), normalize(person_from_upper_surname(match.group(1)))))
    return rows


def parse_results_table(reader, event):
    rows = set()
    for page in reader.pages:
        for line in page.extract_text().splitlines():
            match = re.match(r'^\s*\d+\s+(.+?)\s+L-(\d{3,6})\s+[\d,.]+\s+[\d,.]+\s*$', line)
            if match:
                rows.add((event['discipline'], match.group(2), normalize(person_from_upper_surname(match.group(1)))))
    return rows


def parse_practiscore(reader, event):
    rows = set()
    pattern = re.compile(r'^\s*\d+\s*(?:\(DQ\)\s*)?([^,]+),\s*(.+?)\s*(?:L-)?(\d{3,6})(?=[/\s])')
    for page in reader.pages:
        for line in page.extract_text().splitlines():
            match = pattern.match(line)
            if match:
                rows.add((event['discipline'], match.group(3), normalize(match.group(1) + ' ' + match.group(2))))
    return rows


def parse_legion(reader, event):
    rows = set()
    discipline = None
    row_pattern = re.compile(r'^\s*\d+\s+(.+?)\s{2,}(.+?)\s{2,}L-(\d{3,6})\s')
    for page in reader.pages:
        text = page.extract_text(extraction_mode='layout')
        header = re.search(r'Protokół nr \d+:\s*([^\n]+)', text)
        if header:
            title = header.group(1).strip().lower()
            discipline = 'rifle' if any(title.startswith(p) for p in RIFLE_PROTOCOLS) else 'pistol'
        elif discipline is None:
            continue
        for line in text.splitlines():
            match = row_pattern.match(line)
            if match:
                rows.add((discipline, match.group(3), normalize(match.group(1) + ' ' + match.group(2))))
    return rows


PARSERS = {
    'arsenal': parse_arsenal,
    'results_table': parse_results_table,
    'practiscore': parse_practiscore,
    'legion': parse_legion,
}


def quote(value):
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"


def main():
    competitions = []
    results = []
    for event_id, event in enumerate(EVENTS, start=1):
        path = PDF_DIR / event['file']
        if not path.is_file():
            sys.exit('Brak pliku: ' + str(path))
        rows = PARSERS[event['parser']](PdfReader(str(path)), event)
        by_discipline = {}
        for discipline, *_ in rows:
            by_discipline[discipline] = by_discipline.get(discipline, 0) + 1
        print('%-62s %s' % (event['name'], by_discipline))
        if not rows:
            sys.exit('Nie znaleziono zawodników w: ' + event['file'])
        competitions.append((event_id, event))
        results.extend((event_id,) + row for row in sorted(rows))

    OUTPUT.parent.mkdir(exist_ok=True)
    lines = [
        '-- Wygenerowane przez tools/import_results.py. Zawiera dane osobowe - nie dodawać do repozytorium.',
        'SET NAMES utf8mb4;',
        'START TRANSACTION;',
        'DELETE FROM competition_results WHERE competition_id IN (SELECT id FROM competitions WHERE source_key IS NULL);',
        'DELETE FROM competitions WHERE source_key IS NULL;',
        'INSERT INTO competitions (id, name, event_date, location, calendar) VALUES',
        ',\n'.join(
            '(%d, %s, %s, %s, %s)' % (i, quote(e['name']), quote(e['date']), quote(e['location']), quote(e['calendar']))
            for i, e in competitions
        ) + ';',
    ]
    for start in range(0, len(results), 500):
        lines.append('INSERT INTO competition_results (competition_id, discipline, license_number, name_key) VALUES')
        lines.append(',\n'.join(
            '(%d, %s, %s, %s)' % (cid, quote(d), quote(lic), quote(name))
            for cid, d, lic, name in results[start:start + 500]
        ) + ';')
    lines.append('COMMIT;')
    OUTPUT.write_text('\n'.join(lines) + '\n', encoding='utf-8')
    print('Zapisano %d zawodów i %d startów do %s' % (len(competitions), len(results), OUTPUT))


if __name__ == '__main__':
    main()
