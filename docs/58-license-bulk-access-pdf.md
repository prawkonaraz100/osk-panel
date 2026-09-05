# 58. Licencje — wielokrotny eksport „Pobierz dostępy”

Data weryfikacji: 2026-09-05

**Kontekst:** `/licencje/panel` -> zaznaczenie kilku dostępów -> `Pobierz dostępy`  
**Źródło:** rzeczywisty pobrany plik PDF przekazany podczas audytu  
**Status:** `USER_CONFIRMED_DOWNLOADED_DOCUMENT`

---

## 1. Znaczenie

Panel licencji pozwala zaznaczyć wiele rekordów `learning_access` i pobrać ich dane dostępowe w jednej operacji.

Potwierdzone zachowanie:
- wielokrotny wybór służy co najmniej do `Pobierz dostępy`,
- wynik jest **jednym wielostronicowym PDF-em**,
- nie jest to ZIP ani zestaw osobnych plików.

To nie zmienia wcześniejszego ustalenia, że **przydzielenie licencji działa dla jednego kursanta na raz**.

---

## 2. Struktura zaobserwowanego PDF

Pobrany plik miał 4 strony dla 3 wybranych dostępów.

### Strona 1 — spis dostępów

Pierwsza strona pełni rolę indeksu/spisu i zawiera dla każdego wybranego dostępu:
- język,
- login/identyfikator dostępu,
- numer strony z indywidualną kartą.

Zaobserwowane wpisy:
- język Polski -> `alanowakprawoajzdy360` -> strona 2,
- język Ukraiński -> `pawelkowalskiprawojazdy360` -> strona 3,
- język Angielski -> `jannowakprawojazdy360` -> strona 4.

### Strona 2 — dostęp PL
Indywidualna karta Ali Nowak w języku polskim.

### Strona 3 — dostęp UK
Indywidualna karta Pawła Kowalskiego w języku ukraińskim.

### Strona 4 — dostęp EN
Indywidualna karta Jana Nowaka w języku angielskim.

---

## 3. Lokalizacja per dostęp

Bardzo ważne potwierdzenie:
- dokument zbiorczy może zawierać różne języki jednocześnie,
- **każda indywidualna strona jest generowana w języku danego learning access**, a nie w jednym globalnym języku eksportu.

Zaobserwowano:
- PL -> polska strona logowania,
- UK -> ukraińska wersja dokumentu i ukraiński adres logowania,
- EN -> angielska wersja dokumentu i angielski adres logowania.

Dla naszego produktu generator zbiorczy musi renderować każdy segment na podstawie `learning_access.language`.

---

## 4. Zawartość indywidualnej strony

Każda karta zawiera zasadniczo ten sam zestaw:
- login,
- stan/hasło,
- adres logowania,
- instrukcję logowania,
- instrukcję aktywacji, jeśli dostęp nie jest aktywny,
- dodatkową sekcję informacyjną/marketingową.

W obserwowanym pliku istniejące hasło nie było ujawniane; wyświetlano komunikat typu `Hasło ustawione przez użytkownika`.

To jest spójne z wcześniej zmapowanym pojedynczym PDF-em `Pobierz dostęp`.

---

## 5. Anomalie konkurenta

Zaobserwowano niespójności lokalizacyjne:
- na ukraińskiej i angielskiej stronie komunikat o haśle pozostał po polsku,
- w ukraińskim adresie/instrukcji widoczny jest path `lohin` zamiast typowego `login`.

Nie kopiujemy tych błędów.

Dla naszego produktu wymagamy:
- pełnego tłumaczenia wszystkich stringów,
- poprawnych URL-i per locale,
- testów snapshot/integration dla każdego wspieranego języka.

---

## 6. Semantyka wielokrotnego zaznaczenia

Potwierdzone:
- kilka wierszy można zaznaczyć,
- `Pobierz dostępy` wykorzystuje zaznaczone rekordy,
- wynik agreguje wybrane dostępy do jednego PDF.

Nie wyciągamy z tego wniosku, że inne akcje również są batchowe.

W szczególności:
- `Przydziel licencje` pozostaje single-target zgodnie z osobno obserwowanym flow.

---

## 7. Model dla naszego produktu

Rekomendowany command:

`GenerateSelectedLearningAccessCredentialsPdf`

Input:
- `organization_id`,
- `learning_access_ids[]`,
- `requested_by_user_id`.

Processing:
1. sprawdź tenant i uprawnienia dla każdego ID,
2. zachowaj wybraną kolejność albo jednoznaczny sort,
3. wygeneruj stronę indeksową,
4. dla każdego dostępu wygeneruj zlokalizowaną kartę,
5. scal do jednego PDF,
6. zapisz audyt eksportu,
7. zwróć plik.

---

## 8. Własna polityka danych dostępowych

Zgodnie z naszą decyzją produktową sekretariat może przygotować kursantowi gotowe konto i kartkę z loginem oraz hasłem.

W wariancie zbiorczym:
- jeśli hasło jest właśnie generowane/resetowane w ramach bezpiecznego flow, może zostać umieszczone na indywidualnej stronie PDF,
- istniejącego hasła nie odczytujemy z bazy,
- ponowny wydruk starego hasła nie jest możliwy,
- jeśli sekretariat potrzebuje nowej kartki z hasłem, wykonuje reset/generuje nowe hasło.

---

## 9. Potwierdzone akcje

- `select_multiple_learning_access_rows`
- `download_selected_access_credentials`
- `generate_single_combined_access_pdf`
- `render_access_index_page`
- `render_one_localized_access_page_per_selected_access`

---

## 10. Acceptance criteria

### AC-BULK-ACCESS-01
Administrator może zaznaczyć wiele dostępów i pobrać je w jednym PDF.

### AC-BULK-ACCESS-02
Pierwsza strona zawiera indeks wybranych dostępów i numery stron.

### AC-BULK-ACCESS-03
Każdy wybrany dostęp ma własną stronę.

### AC-BULK-ACCESS-04
Każda karta używa języka danego learning access.

### AC-BULK-ACCESS-05
Eksport jest tenant-scoped, permission-scoped i audytowany.

### AC-BULK-ACCESS-06
Batch download nie implikuje batch assignmentu licencji.

---

## 11. Pozostałe niewiadome

- maksymalna liczba dostępów w jednym eksporcie u konkurenta,
- zachowanie dla bardzo dużych zestawów,
- czy `Zaznacz widoczne` obejmuje wyłącznie aktualną stronę tabeli czy cały wynik filtrowania,
- kolejność wpisów przy sortowaniu/filtering,
- zachowanie dla dostępu zakończonego,
- nazwa pobieranego pliku i reguła timestampów w nazwie.
