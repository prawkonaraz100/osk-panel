# 43. Kursanci — PDF „Pobierz dostępy”

Data weryfikacji: 2026-09-05

**Kontekst:** karta kursanta -> sekcja `Dostępy` -> `Pobierz dostępy`  
**Źródło:** rzeczywisty pobrany plik `dostep.pdf` przekazany podczas audytu + render strony  
**Status:** `USER_CONFIRMED_DOWNLOADED_DOCUMENT`

---

## 1. Format dokumentu

Akcja `Pobierz dostępy` generuje/pobiera:
- PDF,
- 1 strona w obserwowanym przykładzie.

Dokument jest przeznaczony do przekazania kursantowi jako instrukcja logowania i uruchomienia dostępu do nauki.

W obserwowanym przypadku dokument był w języku angielskim, zgodnym z kontekstem dostępu kursanta `EN`.

---

## 2. Dane dostępowe widoczne w PDF

Sekcja `Your login details` zawiera:
- `Login`,
- `Password`,
- `Website`.

Zaobserwowane zachowanie:
- login kursanta jest drukowany wprost,
- dla istniejącego dostępu pole hasła nie ujawniło hasła; dokument pokazał komunikat `Hasło ustawione przez użytkownika`,
- adres logowania jest językowym adresem serwisu, np. `https://en.prawo-jazdy-360.pl/login`.

### Ważna granica
Nie potwierdzono, jak wygląda PDF dla świeżo utworzonego dostępu, który jeszcze nie ma hasła ustawionego przez kursanta.

Nie wolno zakładać, że platforma zawsze drukuje hasło jawnie.

---

## 3. Instrukcja logowania i aktywacji

PDF zawiera instrukcję krok po kroku:
1. wejście na stronę logowania,
2. podanie loginu i hasła,
3. akceptacja regulaminu i zmiana hasła na własne,
4. jeżeli dostęp nie jest jeszcze aktywny — kliknięcie przycisku aktywacji kursu,
5. po aktywacji dostęp jest aktywny.

### Kluczowy wniosek domenowy
To bezpośrednio potwierdza rozdzielenie stanów:

`license assigned / access exists` != `course access activated`

Aktywacja jest osobnym działaniem kursanta po zalogowaniu.

Własny model powinien rozdzielać:
- `learning_access`,
- `license_assignment`,
- `license_activation`.

---

## 4. Aktywacja licencji/dostępu

Potwierdzony trigger UI po stronie kursanta:
- kursant klika przycisk typu `Aktywuj kurs`, jeśli dostęp jest nieaktywny.

Nie potwierdzono z samego PDF:
- czy okres ważności licencji zaczyna się dokładnie w sekundzie kliknięcia,
- czy istnieje dodatkowy ekran potwierdzenia,
- czy aktywacja jest odwracalna,
- co dzieje się przy błędzie aktywacji.

Dla naszego produktu rekomendowany lifecycle:

`assigned_not_activated -> learner_explicit_activation -> active -> expired`

Po aktywacji cofnięcie licencji do puli nie powinno być dostępne jako zwykła akcja administracyjna.

---

## 5. Lokalizacja dokumentu względem języka dostępu

Zaobserwowano:
- konto/dostęp kursanta miał język `EN`,
- PDF jest w większości po angielsku,
- strona logowania używa subdomeny `en`.

Wniosek dla naszego produktu:
- dokument dostępowy powinien być generowany w języku dostępu kursanta,
- język dokumentu nie powinien zależeć wyłącznie od języka panelu administratora OSK.

### Anomalia konkurenta
W angielskim dokumencie pole hasła zawierało polski tekst `Hasło ustawione przez użytkownika`.

Nie kopiujemy tej niespójności. Nasze dokumenty muszą mieć pełną lokalizację.

---

## 6. Dodatkowa zawartość PDF

Dolna część strony zawiera materiał informacyjno-marketingowy o elementach platformy, m.in.:
- testy na prawo jazdy,
- baza pytań,
- elektroniczny podręcznik kursanta,
- wykłady online.

Ta sekcja nie jest konieczna funkcjonalnie do przekazania danych dostępowych.

Dla naszego produktu rekomendowany dokument może być bardziej operacyjny i krótszy:
- logo/OSK,
- dane kursanta,
- login,
- sposób uzyskania/ustawienia hasła,
- adres logowania,
- QR do logowania/aktywacji,
- instrukcja 3–5 kroków,
- kontakt do OSK / wsparcia.

Nie kopiujemy treści marketingowych ani layoutu konkurenta.

---

## 7. Czego PDF nie zawierał w obserwowanym przypadku

Nie zaobserwowano:
- QR code,
- danych adresowych OSK,
- numeru PKK,
- kategorii prawa jazdy,
- numeru licencji,
- daty końca licencji,
- jawnego istniejącego hasła,
- podpisu administratora.

Nie oznacza to, że inne warianty dokumentu nigdy ich nie zawierają; dotyczy obserwowanego pliku.

---

## 8. Potwierdzone akcje i efekty

- `download_student_access_credentials_pdf`
- `render_learning_identifier_in_pdf`
- `render_learning_login_url_in_pdf`
- `render_password_state_or_instruction_in_pdf`
- `render_login_instructions`
- `render_activation_instruction_when_not_active`
- `localize_access_document_by_learning_access_language`

---

## 9. Wymagania dla naszego produktu

- PDF generowany serwerowo,
- tenant isolation,
- tylko uprawniony pracownik OSK może pobrać dokument kursanta,
- pobranie audytowane,
- nie ujawniać istniejącego hasła użytkownika,
- jednorazowe hasła / tokeny tylko jeśli produkt świadomie je wspiera,
- pełna lokalizacja dokumentu,
- QR powinien zawierać bezpieczny URL lub link logowania, nie hasło,
- nie przechowywać plain-text haseł tylko po to, by móc je ponownie wydrukować.

---

## 10. Acceptance criteria

### AC-ACCESS-PDF-01 — downloadable PDF
Administrator może pobrać dokument dostępowy kursanta w PDF.

### AC-ACCESS-PDF-02 — language
Dokument jest generowany w języku właściwego dostępu kursanta.

### AC-ACCESS-PDF-03 — no existing password disclosure
Dla konta z hasłem ustawionym przez użytkownika dokument nie ujawnia jego hasła.

### AC-ACCESS-PDF-04 — activation instruction
Dokument wyjaśnia osobny krok aktywacji, jeżeli dostęp/licencja nie została jeszcze aktywowana.

### AC-ACCESS-PDF-05 — lifecycle separation
Przydzielenie licencji i aktywacja są odrębnymi zdarzeniami domenowymi.

### AC-ACCESS-PDF-06 — audit/security
Pobranie dokumentu jest autoryzowane i audytowane; PDF nie wymaga przechowywania odwracalnych haseł użytkowników.
