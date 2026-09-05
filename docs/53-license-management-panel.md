# 53. Licencje — Panel „Generowanie dostępu”

Data weryfikacji: 2026-09-05

**Route:** `/licencje/panel`  
**Źródło:** bieżące zalogowane ekrany, screenshoty oraz pobrane PDF-y przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Powiązane szczegóły:
- rozwinięcie pojedynczego dostępu: `docs/54-license-expanded-assignments.md`,
- formularz `Generuj dostęp dla kursanta`: `docs/55-license-generate-access-form.md`,
- stan po wybraniu istniejącego kursanta: `docs/56-license-existing-student-selected-state.md`,
- sortowanie: `docs/57-license-list-sorting.md`,
- wielokrotny eksport dostępów: `docs/58-license-bulk-access-pdf.md`.

---

## 1. Znaczenie modułu

`Panel - Generuj licencje` jest centralnym ekranem OSK do zarządzania:
- pulą zakupionych licencji,
- dostępami kursantów do platformy,
- licencjami przypisanymi do poszczególnych dostępów,
- statusem aktywacji/licencji,
- pobieraniem danych dostępowych,
- przejściem do profilu kursanta.

Ekran rozdziela:
1. **inventory OSK** — dostępne/aktywne licencje per wariant,
2. **learning access / kursant** — konto/login,
3. **license assignments** — konkretne licencje przypisane do danego dostępu.

---

## 2. Sekcja „Dostępne licencje”

Potwierdzone warianty:
- `Licencje na 31 dni`,
- `Licencje na 90 dni`,
- `Licencje na 180 dni`.

Każda karta pokazuje:
- `Dostępne`,
- `Aktywne`,
- `Dokup licencje` -> `/licencje/wykup`.

Snapshot demo:
- 31 dni: dostępne 1, aktywne 9,
- 90 dni: dostępne 36, aktywne 4,
- 180 dni: dostępne 56, aktywne 4.

Liczby są danymi konta/demo. Dokładna semantyka agregatu `Aktywne` pozostaje do potwierdzenia.

---

## 3. Generowanie/przydzielanie dostępu

Drawer `Przydzielanie licencji` został potwierdzony.

Pola/elementy:
- wariant `1 miesiąc / 3 miesiące / 6 miesięcy`,
- `Dostępne: N`,
- język dla nowego dostępu,
- `Dodaj nowego kursanta` -> `Email lub login`,
- `lub`,
- `Wyszukaj kursanta`,
- `Przydziel licencje`.

Wyszukiwanie istniejącego kursanta obsługuje:
- imię,
- nazwisko,
- email,
- login,
- PESEL.

### Kardynalność

Wyniki wyszukiwania mogą pokazać wiele osób, ale operator wybiera **dokładnie jednego kursanta** na jedno przydzielenie.

`1 submit -> 1 target learning_access/student`

Masowe przydzielanie licencji nie jest potwierdzone i nie jest wymaganiem parytetowym.

### Języki — SOURCE_CONFLICT

Zalogowany formularz przydzielania pokazuje:
- Polski,
- Angielski,
- Niemiecki,
- Rosyjski,
- Ukraiński.

Ekran zakupu opisuje 4 języki bez rosyjskiego. Lista języków w naszym produkcie jest capability-driven i konfigurowalna.

---

## 4. Sekcja „Przydzielone licencje”

Wiersz główny reprezentuje **learning access**, nie pojedynczą licencję.

Potwierdzone kontrolki:
- `Dodaj nowego kursanta`,
- `Zaznacz widoczne`,
- `Pobierz dostępy`,
- `Przydziel licencje`,
- `Ukryj licencje zakończone`,
- wyszukiwarka,
- `Sortuj`.

### Wielokrotne zaznaczanie — potwierdzony cel

Wielokrotne zaznaczenie jest potwierdzone co najmniej dla **`Pobierz dostępy`**.

Dla 3 wybranych dostępów system wygenerował **jeden 4-stronicowy PDF**:
- strona 1 — spis dostępów z językiem, loginem i numerem strony,
- strona 2 — karta PL,
- strona 3 — karta UK,
- strona 4 — karta EN.

Każda karta jest lokalizowana według języka danego `learning_access`.

To potwierdza:
- `select_multiple_learning_access_rows`,
- `download_selected_access_credentials`,
- jeden combined PDF,
- index page + one page per selected access.

**Nie oznacza to batch assignmentu licencji.** `Przydziel licencje` pozostaje single-target.

Pełny opis: `docs/58-license-bulk-access-pdf.md`.

---

## 5. Kolumny głównej tabeli

Potwierdzone:
- `Dane logowania prawo-jazdy-360.pl`,
- `Imię i nazwisko`,
- `Najnowsza licencja`:
  - `Data wygenerowania`,
  - `Język`,
  - `Status`,
- `Przypisane licencje`,
- akcje.

Statusy zaobserwowane:
- `Nie aktywowano`,
- `Aktywna, pozostało 139 dni`.

Jeden dostęp może mieć wiele licencji — zaobserwowano liczniki `5`, `2`, `2`.

---

## 6. Sortowanie

Potwierdzone kierunki:
- Rosnąco,
- Malejąco.

Potwierdzone kolumny sortowania:
- dane logowania,
- imię i nazwisko,
- data wygenerowania,
- język,
- status,
- przypisane licencje.

Zaobserwowany stan `Malejąco + Data wygenerowania` nie jest automatycznie traktowany jako globalny default.

---

## 7. Rozwinięcie przypisanych licencji

Potwierdzone kolumny:
- dane logowania,
- `Data dodania`,
- `Data do`,
- `Pozostało`,
- `Status`,
- akcje.

### Nieaktywowane

Dla nieaktywowanych:
- `Data do` pusta,
- `Pozostało` puste,
- `Status = Nie aktywowano`,
- `Usuń` dostępne.

### Aktywne

Dla Ali Nowak:
- `23-01-2026 08:41` -> `22-01-2027 23:37`, `139 dni`, `Aktywna`,
- `21-01-2026 12:36` -> `22-12-2026 23:37`, `108 dni`, `Aktywna`.

Dla aktywnych pozycji nie było widocznego `Usuń`.

---

## 8. Aktywacja i przedłużanie

Potwierdzone obserwacje:
- przydzielenie != aktywacja,
- przed aktywacją brak daty końca,
- po aktywacji widoczna data końca i pozostały czas,
- kolejne licencje mogą przedłużać istniejący dostęp.

Dwie aktywne pozycje Ali różnią się dokładnie o 31 dni w `Data do` i `Pozostało`, co silnie wspiera stacking/extension.

Dla naszego produktu:

`base = max(effective_activation_time, current_expiry)`

`new_expiry = base + product_duration`

Audit per assignment:
- `duration_days`,
- `expiry_before`,
- `expiry_after`.

---

## 9. Usuwanie nieaktywowanej licencji

Każda nieaktywna pozycja ma osobne `Usuń`.

U nas:
- brak hard-delete,
- assignment -> `revoked`,
- dokładnie jedna jednostka wraca do inventory,
- transakcja i audit.

Aktywnej licencji nie cofamy zwykłą akcją administracyjną.

---

## 10. Akcje wiersza

Potwierdzone:
- `Dostęp` -> `/licencje/pobierz?guid=<student_id>`,
- `Profil` -> `/kursanci/<student_id>`,
- `Rozwiń / Zwiń`.

---

## 11. Model naszego produktu

Encje:
- `license_products`,
- `license_inventory_entries`,
- `learning_accesses`,
- `license_assignments`,
- `license_activation_events` / entitlement ledger.

Jeden `learning_access` ma wiele `license_assignments`.

Przydzielanie z profilu kursanta i panelu licencji korzysta z jednego backendowego command/service.

Eksport wielu dostępów korzysta z osobnego commandu:
`GenerateSelectedLearningAccessCredentialsPdf`.

---

## 12. Pozostałe niewiadome

- rozwinięcie zakończonej licencji,
- exact search fields głównej tabeli,
- exact semantics `Aktywne` w kartach inventory,
- czy `Zaznacz widoczne` obejmuje aktualną stronę czy cały wynik filtrowania,
- maksymalny rozmiar batch PDF,
- zachowanie `Ukryj licencje zakończone` dla mixed-history account,
- kolejność aktywowania kilku oczekujących licencji,
- zachowanie przy przedłużaniu wygasłego dostępu,
- czy `Dodaj nowego kursanta` tworzy pełny minimalny profil czy wyłącznie learning access,
- walidacja duplikatów email/login,
- pagination,
- empty states,
- success/error messages.
