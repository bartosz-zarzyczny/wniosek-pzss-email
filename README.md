# Formularz przedłużenia licencji zawodniczej

Lekka strona PHP do wypełnienia załączonego wniosku na rok 2027, wygenerowania nowego PDF i wysłania go jako załącznika na adresy skonfigurowane w `settings.json`. Formularz korzysta wyłącznie z PHP, HTML, CSS i JavaScript — nie wymaga Composera ani zewnętrznych bibliotek.

## Wdrożenie na hostingu

1. Skopiuj pliki projektu do katalogu strony WWW, zachowując strukturę folderów.
2. W panelu hostingu wybierz obsługiwaną wersję PHP (zalecane PHP 8.1 lub nowsze).
3. Skopiuj `settings.json-example` jako `settings.json` i ustaw rok, adresy odbiorców i adres nadawcy:

   ```json
   {
     "license_year": 2027,
     "recipients": ["sekretariat@example.pl"],
     "from_email": "formularz@example.pl",
     "rate_limit_seconds": 30
   }
   ```

   `recipients` może zawierać jeden lub kilka adresów; wiadomość jest wysyłana osobno do każdego z nich. Plik `settings.json` jest w `.gitignore` i nie jest częścią repozytorium — utwórz go ręcznie na każdym środowisku, kopiując wzór z `settings.json-example` (zawiera adresy przykładowe `klub@example.com` i `prezes@example.com`, które zastąp właściwymi). Ustaw `from_email` na adres w domenie obsługiwanej przez hosting i skonfiguruj dla niej SPF/DKIM zgodnie z panelem hostingu. Jeśli `from_email` pozostanie pusty, aplikacja spróbuje użyć `formularz@` plus domena bieżącej strony. `calendar_url` (strona kalendarza MZSS) i `python_path` (polecenie Pythona ≥ 3.10) są używane tylko przez panel administracyjny.
4. Upewnij się, że PHP może korzystać z `mail()` oraz zapisywać pliki w systemowym katalogu tymczasowym. Hosting musi mieć skonfigurowaną pocztę wychodzącą; samo poprawne działanie PHP nie gwarantuje dostarczenia wiadomości.
5. Opcjonalnie, dla wyszukiwania startów: utwórz bazę MySQL, skopiuj `local.example.php` jako `local.php`, uzupełnij dane dostępowe i otwórz `admin.php` (patrz „Baza wyników i panel administracyjny”).
6. Otwórz stronę przez HTTPS i wykonaj test, kontrolując skrzynkę odbiorczą oraz folder spam. Nie wysyłaj danych testowych do rzeczywistych adresów bez zgody ich właścicieli.

Plik `.htaccess` blokuje bezpośredni dostęp HTTP do `settings.json`, oryginalnego PDF i fontu. Nie usuwaj tych reguł przy wdrożeniu na Apache. Ustawienia PHP dotyczące limitów powinny dopuszczać wiadomość o rozmiarze co najmniej kilku megabajtów.

## Działanie formularza

- Dane zawodnika oraz maksymalnie osiem startów są nakładane na dostarczony, jednostronicowy PDF. Oryginał pozostaje bez zmian.
- Numer patentu, PESEL i telefon są opcjonalne.
- Wybierz 1–3 dyscypliny. Ręcznie pierwsza daje 4 miejsca na starty, druga i trzecia po 2 kolejne (odpowiednio 4, 6 lub 8 miejsc). Wyszukiwanie może pokazać wszystkie znalezione starty, także ponad 8; PDF obsługuje maksymalnie 8 startów, więc usuń nadmiarowe pozycje przyciskiem w dowolnym wierszu. W każdym starcie można wybrać dokładnie jedną z wybranych dyscyplin.
- Dla każdego startu wybierz jeden kalendarz: PZSS, WZSS, MZSS albo „Inny / brak”. Wybrany skrót jest wpisywany w odpowiedniej kolumnie PDF i podawany w e-mailu. Osobny znacznik MZSS służy do określenia, czy wymagany jest link do zawodów; kalendarze mogą się pokrywać. Po odznaczeniu pola MZSS formularz pokazuje wymagane pole URL, który trafia do treści wiadomości.
- Długie nazwy zawodów w PDF są łamane na granicach słów. Pojedynczy wyraz szerszy niż kolumna jest dzielony; jeśli nazwa mimo tego nie mieści się w wierszu, formularz prosi o jej skrócenie zamiast ucinać tekst.
- Pole „Uwagi” przyjmuje do 120 znaków (także długie nazwy konkurencji z importu); tekst jest zawijany w kolumnie PDF.
- Wiadomość ma temat `Licencja 2027 – Imię NAZWISKO`; rok można zmienić w `settings.json`.
- PDF można pobrać bez wysyłania e-maila albo wysłać jako załącznik MIME. Po wysłaniu dostępne jest także pobranie kopii przez jednorazowy link ważny 15 minut.
- Dane z formularza nie są zapisywane w bazie ani w plikach aplikacji (baza zawiera tylko publiczne wyniki zawodów, konta administratorów i skróty adresów IP do limitu prób). Tymczasowy PDF znajduje się poza katalogiem strony, jest usuwany po pobraniu, a wygasłe pliki są sprzątane przy kolejnych wejściach na stronę. Formularz ma ograniczenie częstotliwości wysyłek dla adresu IP.
- Wysyłka e-mail wymaga odpowiedzi na proste pytanie zabezpieczające (captcha), np. „Broń krótka w dynamice o kalibrze 9x19” → `pistolet`. Pytanie jest losowane z listy `$captchaQuestions` w `index.php` i zmienia się przy każdym wyświetleniu oraz próbie wysyłki. Wielkość liter i polskie znaki w odpowiedzi nie mają znaczenia. Pobranie PDF nie wymaga captchy. Aby dodać pytanie, dopisz parę `['Pytanie', 'odpowiedz']`, z odpowiedzią małymi literami bez polskich znaków.
- Podpis wnioskodawcy pozostaje pusty. Wygenerowany dokument należy podpisać, jeżeli wymaga tego procedura klubu.

## Captcha — pytania i odpowiedzi

Captcha obowiązuje tylko przy akcji „Przygotuj i wyślij wniosek”. Pytanie jest losowane przy każdym wyświetleniu formularza i jest jednorazowe: po próbie wysyłki (także nieudanej) losowane jest nowe. Przy porównaniu odpowiedź jest przycinana z białych znaków, zamieniana na małe litery i pozbawiana polskich znaków, więc `Suchawki`, `SŁUCHAWKI` i `suchawki` są poprawne.

| Pytanie | Odpowiedź |
|---|---|
| Broń krótka w dynamice o kalibrze 9x19 | `pistolet` |
| Lubi dalszy dystans | `karabin` |
| Dużo małych kulek | `strzelba` |
| Ile nabojów mieści się w pełnym magazynku 5-strzałowego rewolweru? | `5` |
| Strzał obok celu to… | `pudlo` |
| Chronią uszy na strzelnicy | `suchawki` |
| Chronią oczy na strzelnicy | `okulary` |
| Papierowy cel, do którego się strzela | `tarcza` |

Pytania znajdują się w tablicy `$captchaQuestions` w `index.php`. Nowe pytanie dodaje się jako parę `['Pytanie', 'odpowiedz']`; odpowiedź musi być zapisana małymi literami i bez polskich znaków (np. `suchawki`). Przy zmianie listy zaktualizuj powyższą tabelę.

## Zabezpieczenia

- Token CSRF w sesji weryfikowany przy każdym wysłaniu formularza.
- Ukryte pole‑pułapka (honeypot) odrzucające proste boty.
- Captcha tekstowa przy wysyłce e-mail (patrz wyżej).
- Ograniczenie częstotliwości wysyłek dla adresu IP (`rate_limit_seconds` w `settings.json`, wartość ograniczona do 10–3600 s, domyślnie 30); blokady są plikami `zsd-license-*.lock` w katalogu tymczasowym.
- Wymagane zaznaczenie oświadczenia o prawdziwości danych i zgody na zapoznanie się z informacją o przetwarzaniu danych.
- Cookie sesji z flagami `HttpOnly`, `SameSite=Lax` i `Secure` (przy HTTPS).
- Pobranie kopii po wysyłce (`download.php`) wymaga jednorazowego tokenu powiązanego z sesją, ważnego 15 minut.

## Baza wyników i panel administracyjny

Przycisk „Wyszukaj moje starty” w sekcji 01 formularza (`lookup.php`) szuka w bazie MySQL startów zawodnika po **numerze licencji i nazwisku** (bez względu na wielkość liter i polskie znaki), zaznacza dyscypliny i wypełnia puste wiersze startów. Wyszukiwanie jest ograniczone do 10 prób na 10 minut dla adresu IP (w bazie zapisywany jest tylko skrót SHA-256 adresu). Dane dostępowe do bazy są w `local.php` (poza repozytorium; wzór: `local.example.php`).

Tabele tworzy `database/schema.sql`; panel administracyjny sam dodaje brakujące tabele i kolumny przy pierwszym wejściu, więc na hostingu nie trzeba ręcznie importować schematu.

### Panel administracyjny (`admin.php`)

- **Pierwsze wejście:** gdy w bazie nie ma żadnego użytkownika, panel pokazuje formularz utworzenia pierwszego konta (login + hasło min. 10 znaków). Zrób to od razu po wdrożeniu — kto pierwszy otworzy `admin.php`, zakłada konto.
- **Użytkownicy:** po zalogowaniu można dodawać kolejnych użytkowników i usuwać innych (nie własne konto). Hasła są zapisywane jako skrót `password_hash`. Logowanie ma limit 5 nieudanych prób na 15 minut dla adresu IP, token CSRF i regenerację sesji.
- **Pobieranie i import:** przycisk „Pobierz i zaimportuj” wczytuje kalendarz MZSS z `calendar_url` dla wybranego roku, wybiera pliki z wynikami (nazwa zawiera `_R_`/`R` jako osobny człon, „rezultat”, „result” lub „wyniki”, bez względu na wielkość liter; komunikaty `_K_` są pomijane) i zapisuje je w `download_pdf/`. Plik jest pobierany tylko wtedy, gdy go nie ma lub na serwerze jest nowsza wersja (porównanie `ETag` / `Last-Modified` / rozmiaru z zapisem w tabeli `pdf_files`; ręcznie wgrany plik o tym samym rozmiarze i nie starszy niż na serwerze jest uznawany za aktualny). Po pobraniu nowej wersji wyniki zawodów są importowane od nowa. Opcja „przetwórz ponownie” wymusza ponowny import plików lokalnych.
- **Limity hostingu:** pliki są przetwarzane po jednym na żądanie AJAX (limit 30 s), postęp widać w tabeli. Do odczytu PDF używany jest `tools/parse_pdf.py` z dołączoną biblioteką `pypdf` (`tools/vendor/`, licencja BSD) uruchamiany przez `proc_open`; hosting musi udostępniać Python ≥ 3.10 (przycisk „Sprawdź Pythona” pokazuje wersję i status). Ścieżkę zmienisz w `python_path`.
- **Dyscyplina:** brana z treści PDF (np. protokoły Legion Tarnów), a w pozostałych przypadkach z zaznaczeń P/K/S w kalendarzu MZSS, gdy jest jednoznaczna (jedna dyscyplina lub jedna wskazana także w tekście PDF). Gdy dyscypliny nie da się ustalić (np. zawody pneumatyczne), plik jest pomijany z komunikatem. Pliki bez numerów licencji (np. wyniki IPSC z numerami startowymi) także są pomijane. Wyniki są oznaczane kalendarzem `MZSS`.
- Zawody spoza kalendarza MZSS (np. PZSS) można dodać ręcznie narzędziem `tools/import_results.py`, które generuje `database/data.sql` (usuwa i wstawia tylko rekordy bez `source_key`). Powtarzające się starty (ta sama data i dyscyplina) są w wynikach wyszukiwania scalane.
## Struktura projektu

| Plik | Przeznaczenie |
|---|---|
| `index.php` | Formularz, walidacja, captcha, generowanie PDF i wysyłka e-mail |
| `download.php` | Jednorazowe pobranie kopii PDF po wysyłce |
| `lib/PdfFiller.php` | Nakładanie danych na oryginalny PDF |
| `lookup.php`, `lib/StartLookup.php` | Wyszukiwanie startów zawodnika w bazie (JSON) |
| `admin.php`, `admin_api.php`, `assets/admin.js` | Panel administracyjny: logowanie, użytkownicy, pobieranie i import PDF |
| `lib/AdminAuth.php`, `lib/MzssImporter.php`, `lib/Schema.php` | Konta, pobieranie/import z kalendarza MZSS, tworzenie tabel |
| `tools/parse_pdf.py`, `tools/vendor/` | Odczyt zawodników z PDF (Python + pypdf) |
| `tools/import_results.py`, `database/` | Ręczny import wyników do SQL, schemat bazy |
| `local.php`, `local.example.php` | Dane dostępowe do bazy (`local.php` poza repozytorium) |
| `download_pdf/` | Pobrane PDF-y z wynikami (poza repozytorium, zablokowane w `.htaccess`) |
| `assets/` | `app.js`, `style.css`, font Source Sans 3 i licencja OFL |
| `settings.json` | Konfiguracja (poza repozytorium, patrz `.gitignore`) |
| `settings.json-example` | Wzór konfiguracji z adresami przykładowymi, zapisany w repozytorium; skopiuj go jako `settings.json` |
| `.htaccess` | Blokada dostępu HTTP do `settings.json`, oryginalnego PDF i fontu oraz listingu katalogów (tylko Apache) |
| `tests/PdfFillerTest.php` | Test generowania PDF |

## Font

`assets/SourceSans3-Regular.ttf` jest osadzany w wygenerowanym PDF, aby zachować polskie znaki. Font jest udostępniony na licencji SIL Open Font License 1.1; tekst licencji znajduje się w `assets/OFL.txt`. Dokumenty PDF wygenerowane przy użyciu fontu nie podlegają tej licencji.

## Test PDF

Uruchom `php tests/PdfFillerTest.php`, aby sprawdzić oznaczenia kalendarzy i łamanie nazw zawodów w generowanym PDF. Test nie wysyła wiadomości.

Importer wyników zachowuje nazwę konkurencji z każdego protokołu, nawet gdy ten sam zawodnik startuje kilka razy w tej samej dyscyplinie danego dnia. Nazwa trafia do pola „Uwagi” w formularzu. Wynik importu pokazuje, dla ilu startów udało się odczytać nazwę konkurencji. Po wdrożeniu tej wersji wymuś ponowny import odpowiedniego PDF w panelu administratora, aby odświeżyć już zaimportowane wyniki; panel automatycznie uzupełni brakującą kolumnę bazy.

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
