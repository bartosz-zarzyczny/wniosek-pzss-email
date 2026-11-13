# Formularz przedłużenia licencji zawodniczej

Lekka strona PHP do wypełnienia załączonego wniosku na rok 2027, wygenerowania nowego PDF i wysłania go jako załącznika na adresy skonfigurowane w `settings.json`. Formularz korzysta wyłącznie z PHP, HTML, CSS i JavaScript — nie wymaga Composera ani zewnętrznych bibliotek.

## Wdrożenie na hostingu

1. Skopiuj pliki projektu do katalogu strony WWW, zachowując strukturę folderów.
2. W panelu hostingu wybierz obsługiwaną wersję PHP (zalecane PHP 8.1 lub nowsze).
3. W `settings.json` ustaw rok, adresy odbiorców i adres nadawcy:

   ```json
   {
     "license_year": 2027,
     "recipients": ["sekretariat@example.pl"],
     "from_email": "formularz@example.pl",
     "rate_limit_seconds": 30
   }
   ```

   `recipients` może zawierać jeden lub kilka adresów. W projekcie są wpisane adresy testowe: `boli@op.pl` oraz `zarzyczny+p@gmail.com`. Przed uruchomieniem produkcyjnym zmień je na właściwe adresy. Ustaw `from_email` na adres w domenie obsługiwanej przez hosting i skonfiguruj dla niej SPF/DKIM zgodnie z panelem hostingu. Jeśli `from_email` pozostanie pusty, aplikacja spróbuje użyć `formularz@` plus domena bieżącej strony.
4. Upewnij się, że PHP może korzystać z `mail()` oraz zapisywać pliki w systemowym katalogu tymczasowym. Hosting musi mieć skonfigurowaną pocztę wychodzącą; samo poprawne działanie PHP nie gwarantuje dostarczenia wiadomości.
5. Otwórz stronę przez HTTPS i wykonaj test, kontrolując skrzynkę odbiorczą oraz folder spam. Nie wysyłaj danych testowych do rzeczywistych adresów bez zgody ich właścicieli.

Plik `.htaccess` blokuje bezpośredni dostęp HTTP do `settings.json`, oryginalnego PDF i fontu. Nie usuwaj tych reguł przy wdrożeniu na Apache. Ustawienia PHP dotyczące limitów powinny dopuszczać wiadomość o rozmiarze co najmniej kilku megabajtów.

## Działanie formularza

- Dane zawodnika oraz maksymalnie osiem startów są nakładane na dostarczony, jednostronicowy PDF. Oryginał pozostaje bez zmian.
- Numer patentu, PESEL i telefon są opcjonalne.
- Wybierz 1–3 dyscypliny. Pierwsza daje 4 miejsca na starty, druga i trzecia po 2 kolejne (odpowiednio 4, 6 lub 8 miejsc). W każdym starcie można wybrać dokładnie jedną z wybranych dyscyplin. Po zmniejszeniu liczby dyscyplin ukryte starty nie są uwzględniane w PDF ani wiadomości.
- Dla każdego startu wybierz jeden kalendarz: PZSS, WZSS, MZSS albo „Inny / brak”. Wybrany skrót jest wpisywany w odpowiedniej kolumnie PDF i podawany w e-mailu. Osobny znacznik MZSS służy do określenia, czy wymagany jest link do zawodów; kalendarze mogą się pokrywać. Po odznaczeniu pola MZSS formularz pokazuje wymagane pole URL, który trafia do treści wiadomości.
- Długie nazwy zawodów w PDF są łamane na granicach słów. Pojedynczy wyraz szerszy niż kolumna jest dzielony; jeśli nazwa mimo tego nie mieści się w wierszu, formularz prosi o jej skrócenie zamiast ucinać tekst.
- Wiadomość ma temat `Licencja 2027 – Imię NAZWISKO`; rok można zmienić w `settings.json`.
- PDF można pobrać bez wysyłania e-maila albo wysłać jako załącznik MIME. Po wysłaniu dostępne jest także pobranie kopii przez jednorazowy link ważny 15 minut.
- Dane z formularza nie są zapisywane w bazie ani w plikach aplikacji. Tymczasowy PDF znajduje się poza katalogiem strony, jest usuwany po pobraniu, a wygasłe pliki są sprzątane przy kolejnych wejściach na stronę. Formularz ma ograniczenie częstotliwości wysyłek dla adresu IP.
- Podpis wnioskodawcy pozostaje pusty. Wygenerowany dokument należy podpisać, jeżeli wymaga tego procedura klubu.

## Font

`assets/SourceSans3-Regular.ttf` jest osadzany w wygenerowanym PDF, aby zachować polskie znaki. Font jest udostępniony na licencji SIL Open Font License 1.1; tekst licencji znajduje się w `assets/OFL.txt`. Dokumenty PDF wygenerowane przy użyciu fontu nie podlegają tej licencji.

## Test PDF

Uruchom `php tests/PdfFillerTest.php`, aby sprawdzić oznaczenia kalendarzy i łamanie nazw zawodów w generowanym PDF. Test nie wysyła wiadomości.

## Treść wiadomości

> Szanowni Państwo,  
> w załączeniu przekazuję wniosek o przedłużenie ważności licencji zawodniczej na rok 2027.  
> Wiadomość zawiera dane zawodnika oraz wykaz startów. Dla zawodów spoza kalendarza MZSS podawany jest link do strony zawodów.  
> Uprzejmie proszę o weryfikację wniosku i dalsze procedowanie sprawy.  
> Z poważaniem,  
> Imię Nazwisko

Pełna wiadomość zawiera również numery patentu i licencji, dyscypliny, daty, miejsca zawodów oraz uwagi podane w formularzu.

## Ograniczenia i prywatność

Wysyłka zależy od konfiguracji poczty na hostingu i nie stanowi potwierdzenia dostarczenia do skrzynki odbiorcy. Adres IP jest używany do krótkotrwałego ograniczania liczby wysyłek; formularz nie utrwala pozostałych danych poza tymczasowym plikiem PDF. Oświadczenia i informacja o przetwarzaniu danych w PDF pozostają częścią dokumentu źródłowego.
