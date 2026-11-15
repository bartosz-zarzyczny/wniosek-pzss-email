import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))

import parse_pdf  # noqa: E402


class ParsePdfTest(unittest.TestCase):
    def test_keeps_same_person_in_each_protocol_with_competition_name(self):
        protocols = [
            ('3', 'Strzelba Dynamiczna Standard Manual', 'shotgun', 'Strzelba dynamiczna - Standard Manual'),
            ('4', 'Strzelba Dynamiczna OPEN', 'shotgun', 'Strzelba dynamiczna - OPEN'),
            ('9', 'Pistolet Hop Stop - 20 strzałów', 'pistol', 'Pistolet .22LR "HopStop"'),
            ('10', 'Pistolet Mini Czarny Koń', 'pistol', 'Pistolet .22LR Czarny Koń'),
            ('11', 'Arsenal Shotgun Trophy', 'shotgun', 'STANDARD MANUAL'),
        ]
        lines = []
        for number, title, _, _ in protocols:
            lines.extend([
                f'Protokół nr {number}: {title}',
                '1 ZARZYCZNY Bartosz L-47895 ZSD Kraków 120 14,73 8,1466',
            ])

        rows = parse_pdf.extract_rows(['\n'.join(lines)])
        starts = [row for row in rows if row[1] == '47895' and row[2] == 'zarzyczny bartosz']

        self.assertEqual(
            {(discipline, competition_name) for discipline, _, _, competition_name in starts},
            {(discipline, name) for _, _, discipline, name in protocols},
        )
        self.assertEqual(len(starts), 5)

    def test_extracts_layout_rows_for_alternate_license_header_and_protocol_separator(self):
        plain_text = (
            'Protokół nr 4 – Strzelba Dynamiczna OPEN\n'
            'Miejsce Nazwisko i imię Licencja Klub Seria1 Czas'
        )
        layout_text = (
            'Protokół nr 4 – Strzelba Dynamiczna OPEN\n'
            '         1  ZARZYCZNY  Bartosz  L-47895  ZSD Kraków'
        )

        rows = parse_pdf.extract_rows([plain_text], [layout_text])
        starts = {row for row in rows if row[1] == '47895'}

        self.assertEqual(
            starts,
            {('shotgun', '47895', 'zarzyczny bartosz', 'Strzelba dynamiczna - OPEN')},
        )

    def test_layout_row_without_rank_recovers_both_name_columns(self):
        plain_text = (
            'Miejsce Nazwisko i imię Licencja Klub Wynik\n'
            'Rezultaty konkurencji MINI RIFLE\n'
            '3 Bartosz L-47895 Klub 120'
        )
        layout_text = (
            'Rezultaty konkurencji MINI RIFLE\n'
            '       ZARZYCZNY            Bartosz       L-47895       Klub 120'
        )

        rows = parse_pdf.extract_rows([plain_text], [layout_text])

        self.assertIn(('rifle', '47895', 'zarzyczny bartosz', 'MINI RIFLE'), rows)

    def test_extracts_ranked_rows_without_license_numbers(self):
        rows = parse_pdf.extract_rows([
            'Protokół nr 1: Pistolet\n'
            '1. ZARZYCZNY Bartosz ZSD Kraków 120 14,73\n'
            '2. NOWAK Anna Klub Kraków 118 15,21'
        ])

        self.assertEqual(
            rows,
            {
                ('pistol', '', 'zarzyczny bartosz', 'Pistolet'),
                ('pistol', '', 'nowak anna', 'Pistolet'),
            },
        )

    def test_extracts_practiscore_rows_without_license_numbers(self):
        rows = parse_pdf.extract_rows([
            'Protokół nr 1: Pistolet\n'
            '1 Zarzyczny, Bartosz, ZSD Kraków, 120\n'
            '2 Nowak, Anna, Klub Kraków, 118'
        ])

        self.assertEqual(
            rows,
            {
                ('pistol', '', 'zarzyczny bartosz', 'Pistolet'),
                ('pistol', '', 'nowak anna', 'Pistolet'),
            },
        )

    def test_extracts_layout_rows_without_license_column(self):
        plain_text = (
            'Protokół nr 1: Pistolet\n'
            'Miejsce Nazwisko i imię Klub Wynik'
        )
        layout_text = (
            'Protokół nr 1: Pistolet\n'
            '1  ZARZYCZNY  Bartosz  ZSD Kraków 120'
        )

        rows = parse_pdf.extract_rows([plain_text], [layout_text])

        self.assertIn(('pistol', '', 'zarzyczny bartosz', 'Pistolet'), rows)

    def test_maps_dynamic_result_categories_and_skips_senior_sections(self):
        rows = parse_pdf.extract_rows([
            'Rezultaty konkurencji CLASSIC\n'
            '1 ZARZYCZNY Bartosz Klub 120\n'
            'Rezultaty konkurencji MINI RIFLE\n'
            '2 NOWAK Anna Klub 118\n'
            'Rezultaty konkurencji OPEN\n'
            '3 KOWALSKI Jan Klub 117\n'
            'Rezultaty konkurencji OPTICS\n'
            '4 WIŚNIEWSKI Adam Klub 116\n'
            'Rezultaty konkurencji PC OPTICS\n'
            '5 KOWALCZYK Piotr Klub 115\n'
            'Rezultaty konkurencji PRODUCTION\n'
            '6 WOŹNIAK Marek Klub 114\n'
            'Rezultaty konkurencji PRODUCTION OPTICS\n'
            '7 WÓJCIK Tomasz Klub 113\n'
            'Rezultaty konkurencji STANDARD\n'
            '8 KAMIŃSKI Paweł Klub 112\n'
            'Rezultaty konkurencji PC OPTICS Senior\n'
            '9 LEWANDOWSKI Andrzej Klub 111\n'
            'Rezultaty konkurencji PRODUCTION Senior\n'
            '10 ZIELIŃSKI Michał Klub 110\n'
            'Rezultaty konkurencji PRODUCTION OPTICS Senior\n'
            '11 SZYMAŃSKI Krzysztof Klub 109\n'
            'Rezultaty konkurencji OPTICS Senior\n'
            '12 DĄBROWSKI Łukasz Klub 108\n'
            'Rezultaty konkurencji STANDARD Senior\n'
            '13 JANKOWSKI Marcin Klub 107'
        ])

        self.assertEqual(
            {(row[2], row[0], row[3]) for row in rows},
            {
                ('zarzyczny bartosz', 'pistol', 'CLASSIC'),
                ('nowak anna', 'rifle', 'MINI RIFLE'),
                ('kowalski jan', 'pistol', 'OPEN'),
                ('wisniewski adam', 'pistol', 'OPTICS'),
                ('kowalczyk piotr', 'rifle', 'PC OPTICS'),
                ('wozniak marek', 'pistol', 'PRODUCTION'),
                ('wojcik tomasz', 'pistol', 'PRODUCTION OPTICS'),
                ('kaminski pawel', 'pistol', 'STANDARD'),
            },
        )

    def test_recognizes_protocol_and_category_header_variants(self):
        rows = parse_pdf.extract_rows([
            'Protokół nr 1 - kategoria Classic\n'
            '1 ZARZYCZNY Bartosz Klub 120\n'
            'Protokół nr 2 - kategoria Mini Rifle\n'
            '2 NOWAK Anna Klub 118\n'
            'Kategoria PC OPTICS\n'
            '3 KOWALSKI Jan Klub 117'
        ])

        self.assertEqual(
            {(row[2], row[0], row[3]) for row in rows},
            {
                ('zarzyczny bartosz', 'pistol', 'CLASSIC'),
                ('nowak anna', 'rifle', 'MINI RIFLE'),
                ('kowalski jan', 'rifle', 'PC OPTICS'),
            },
        )

    def test_dynamic_category_headers_work_in_layout_text_and_keep_section_state(self):
        plain_text = (
            'Miejsce Nazwisko i imię Licencja Klub Wynik\n'
            'Rezultaty konkurencji PC OPTICS\n'
            'Rezultaty konkurencji PC OPTICS Senior\n'
            'Rezultaty konkurencji CLASSIC'
        )
        layout_text = (
            'Rezultaty konkurencji PC OPTICS\n'
            '1  ZARZYCZNY  Bartosz  L-47895  Klub 120\n'
            'Rezultaty konkurencji PC OPTICS Senior\n'
            '2  NOWAK  Anna  L-47896  Klub 118\n'
            'Rezultaty konkurencji CLASSIC\n'
            '3  KOWALSKI  Jan  L-47897  Klub 117'
        )

        rows = parse_pdf.extract_rows([plain_text], [layout_text])

        self.assertEqual(
            {(row[1], row[0], row[2], row[3]) for row in rows},
            {
                ('47895', 'rifle', 'zarzyczny bartosz', 'PC OPTICS'),
                ('47897', 'pistol', 'kowalski jan', 'CLASSIC'),
            },
        )

    def test_does_not_import_unknown_dynamic_category_using_fallback(self):
        rows = parse_pdf.extract_rows([
            'Rezultaty konkurencji UNKNOWN DIVISION\n'
            '1 ZARZYCZNY Bartosz Klub 120'
        ])

        self.assertEqual(rows, set())

    def test_keeps_existing_dynamic_pistol_protocol_title_handling(self):
        rows = parse_pdf.extract_rows([
            'Protokół nr 1 - Pistolet dynamiczny Hop Stop\n'
            '1 ZARZYCZNY Bartosz L-47895 Klub 120'
        ])

        self.assertIn(
            ('pistol', '47895', 'zarzyczny bartosz', 'Pistolet .22LR "HopStop"'),
            rows,
        )


if __name__ == '__main__':
    unittest.main()
