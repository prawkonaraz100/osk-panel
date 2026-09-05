# 55. Licencje — formularz „Przydzielanie licencji” z panelu licencji

Data weryfikacji: 2026-09-05

**Kontekst:** `/licencje/panel` -> `Generuj dostęp dla kursanta do prawo-jazdy-360.pl`  
**Źródło:** bieżący zalogowany ekran + screenshoty rozwiniętych selektorów przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie

Akcja `Generuj dostęp dla kursanta do prawo-jazdy-360.pl` otwiera drawer zatytułowany:

`Przydzielanie licencji`

Formularz jednocześnie:
- wybiera wariant licencji,
- wybiera język dostępu,
- wskazuje docelowego kursanta/dostęp,
- zużywa jedną jednostkę odpowiedniej puli po skutecznym przydzieleniu.

UI wspiera dwie ścieżki wyboru celu:
1. minimalne utworzenie nowego dostępu przez `Email lub login`,
2. wyszukanie istniejącego kursanta.

---

## 2. Wybierz rodzaj licencji

Kontrolka: single select.

Potwierdzone opcje:
- `1 miesiąc`,
- `3 miesiące`,
- `6 miesięcy`.

Obok wyboru widoczny jest dynamiczny licznik:
- `Dostępne: <N>`.

W obserwowanym przypadku dla `1 miesiąc`:
- `Dostępne: 1`.

Licznik jest stanem inventory OSK, nie stałą produktu.

---

## 3. Język

Kontrolka: single select.

Potwierdzone opcje w bieżącym zalogowanym formularzu:
- `Polski`,
- `Angielski`,
- `Niemiecki`,
- `Rosyjski`,
- `Ukraiński`.

### SOURCE_CONFLICT

Na ekranie `/licencje/wykup` tekst produktowy mówi o 4 językach:
- Polski,
- Angielski,
- Niemiecki,
- Ukraiński.

Natomiast bieżący formularz operacyjny przydzielania licencji pokazuje również `Rosyjski`.

Wnioski:
- nie hardkodować jednej globalnej listy języków,
- capability języka powinno wynikać z konfiguracji produktu/contentu,
- dla parytetu widocznego panelu administracyjnego `Rosyjski` jest `USER_CONFIRMED_AUTH_SCREEN` jako dostępna opcja selektora,
- nie wyciągamy z samego selektora wniosku, że cała zawartość każdego modułu w języku rosyjskim jest kompletna.

---

## 4. Ścieżka A — „Dodaj nowego kursanta”

Sekcja nosi etykietę:
- `Dodaj nowego kursanta`.

Widoczne pole:
- `Email lub login`.

Nie ma w tym drawerze pól:
- imię,
- nazwisko,
- telefon,
- PESEL,
- lokalizacja,
- PKK.

### Ważna interpretacja

Mimo etykiety `Dodaj nowego kursanta`, obserwowany ekran bardziej przypomina utworzenie **minimalnego learning access** niż pełnego profilu kursanta.

Nie zapisujemy jako faktu konkurenta, czy backend:
- tworzy minimalny rekord kursanta,
- tworzy wyłącznie konto dostępu,
- czy później wymusza uzupełnienie danych.

Dla naszego produktu te domeny pozostają rozdzielone:
- `student_profile`,
- `learning_access`.

---

## 5. Ścieżka B — „Wyszukaj kursanta”

Sekcja:
- `Wyszukaj kursanta`.

Kontrolka:
- searchable single select.

Tekst pomocniczy potwierdza wyszukiwanie po:
- imieniu,
- nazwisku,
- emailu,
- loginie,
- PESEL-u.

Zaobserwowane wyniki pokazują:
- imię i nazwisko,
- login/dane dostępu.

Przykłady:
- Jan Nowak / `jannowakprawojazdy360`,
- Paweł Kowalski / `pawelkowalskiprawojazdy360`,
- Ala Nowak / `alanowakprawojazdy360`.

---

## 6. Relacja dwóch ścieżek

Między sekcjami widoczny jest separator:
- `lub`.

Semantyka UI: operator wybiera albo nowy identyfikator dostępu, albo istniejącego kursanta.

Nie potwierdzono zachowania przy jednoczesnym wypełnieniu obu kontrolek.

Dla naszego produktu wymagamy jawnego `target_mode`:
- `new_learning_access`,
- `existing_student`.

Backend powinien odrzucać payload wskazujący oba cele jednocześnie.

---

## 7. Główna akcja

Potwierdzony przycisk:
- `Przydziel licencje`.

Widoczne jest również zamknięcie drawera przez `X`.

Nie można w demo potwierdzić:
- success toast,
- dokładnego momentu decrement inventory,
- rollbacku po błędzie,
- generowania hasła,
- automatycznej aktywacji.

Dla naszego produktu assignment musi być transakcyjny:
1. lock/select dostępnej jednostki inventory,
2. validate target,
3. create/reuse learning access,
4. create license assignment,
5. mark inventory assigned,
6. audit,
7. commit.

Przy błędzie całość rollback.

---

## 8. Powiązanie z formularzem na karcie kursanta

Wcześniej zmapowany `Przydziel licencje` z profilu kursanta pozwala:
- przypisać licencję do istniejącego dostępu,
- albo utworzyć nowy dostęp.

Bieżący formularz z `/licencje/panel` działa od drugiej strony:
- zaczynamy od produktu/licencji,
- następnie wskazujemy nowego identyfikatora albo istniejącego kursanta.

Oba flow powinny korzystać z tego samego backendowego command/service, aby uniknąć różnic w logice inventory i aktywacji.

---

## 9. Potwierdzone akcje

- `open_generate_license_access_drawer`
- `select_license_product_duration`
- `view_available_inventory_count`
- `select_learning_access_language`
- `enter_new_learning_identifier`
- `search_existing_student_for_license`
- `search_student_by_name`
- `search_student_by_surname`
- `search_student_by_email`
- `search_student_by_login`
- `search_student_by_pesel`
- `select_existing_student`
- `submit_license_assignment`
- `close_license_assignment_drawer`

---

## 10. Wymagania dla naszego produktu

- wspólny service/command do przydzielania licencji niezależnie od entry pointu,
- tenant isolation,
- atomic inventory decrement/assignment,
- brak zejścia inventory poniżej zera,
- wyszukiwanie istniejącego kursanta tenant-scoped,
- nie logować PESEL w telemetry/search logs w plaintext ponad potrzebę,
- języki capability-driven i konfigurowalne,
- osobny `student_profile` oraz `learning_access`,
- audit assignmentu,
- polityka hasła/aktywacji zgodna z naszym ustalonym workflow sekretariatu.

---

## 11. Pozostałe niewiadome

- walidacja formatu `Email lub login`,
- zachowanie przy duplikacie loginu/emaila,
- czy nowa ścieżka tworzy pełny minimalny `student_profile`, czy tylko access,
- success/error messages,
- dokładny inventory transaction moment u konkurenta,
- czy język rosyjski ma kompletny content parity we wszystkich modułach,
- zachowanie, gdy wybrany wariant ma `Dostępne: 0`.
