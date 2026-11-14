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
        self.assertEqual(len(starts), 4)

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


if __name__ == '__main__':
    unittest.main()
