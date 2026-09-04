# 29. Kursanci — lista kursantów

Data weryfikacji: 2026-09-05

**Route:** `/kursanci`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane osobowe, numery telefonów, dane logowania, numery PKK i identyfikatory rekordów z konta demonstracyjnego nie są przepisywane do dokumentacji.

---

## 1. Cel ekranu

`Kursanci` jest centralną listą osób obsługiwanych przez OSK. Ekran łączy w jednym widoku:
- dane kursanta,
- dane logowania do serwisu nauki,
- kurs / kontekst PKK,
- telefon,
- datę dodania,
- stan/indykator egzaminu wewnętrznego,
- przejście do szczegółów kursanta.

---

## 2. Nagłówek i akcje główne

Potwierdzone elementy:
- tytuł `Twoi kursanci`,
- licznik wyników w formie `Wyszukano <N> kursantów`,
- akcja `Dodaj kursanta z PKK`,
- akcja `Dodaj kursanta`.

To potwierdza dwa odrębne wejścia do tworzenia kursanta:
1. flow oparty o PKK,
2. zwykły/manualny flow dodania kursanta.

Nie zakładamy jeszcze, że finalne formularze są identyczne.

---

## 3. Wyszukiwanie, filtrowanie i sortowanie

Potwierdzone kontrolki:
- pole wyszukiwania z placeholderem `Szukaj kursanta...`,
- `Filtruj`,
- `Sortuj`.

To jest pierwsze bezpośrednie potwierdzenie wyszukiwania/filtrowania/sortowania na liście kursantów.

Do dalszego audytu:
- po jakich polach działa search,
- lista filtrów,
- lista kluczy sortowania,
- kolejność ASC/DESC,
- zachowanie zerowych wyników,
- paginacja / page size.

---

## 4. Potwierdzone kolumny listy

Na ekranie występują:

1. `Imię i Nazwisko`
2. `Dane logowania prawo-jazdy-360.pl`
3. `Kursy`
4. `Telefon`
5. `Data dodania`
6. `Egzamin wewnętrzny`
7. kolumna akcji wiersza

Przy nagłówkach widoczny jest również indykator sortowania przy części tabeli; dokładny aktualny klucz sortowania wymaga osobnego kliknięcia/obserwacji.

---

## 5. Dane logowania kursanta

Kolumna `Dane logowania prawo-jazdy-360.pl` pokazuje co najmniej:
- język / kod języka z flagą,
- login/username kursanta.

Zaobserwowane kody językowe:
- `PL`,
- `EN`,
- `UK`.

Nie zapisujemy konkretnych loginów z audytowanego konta.

Wniosek domenowy dla naszego produktu:
- `student_profile` nie powinien być tym samym rekordem co konto e-learningowe,
- kursant może posiadać osobny `learning_account` / credentials identity,
- język jest właściwością konta/usługi nauki lub preferencją kursanta i nie powinien być zaszyty w samym OSK student profile.

Nie potwierdzono na tym ekranie:
- hasła,
- e-maila konta,
- sposobu resetu hasła,
- możliwości zmiany języka z listy.

---

## 6. Kolumna `Kursy`

Na obserwowanym rekordzie widoczny jest kafel kursu zawierający:
- kategorię, np. `A`,
- typ/rodzaj `Teoria`,
- numer `PKK`,
- sekcję `Ostatnia operacja PKK`,
- wartość stanu typu `Brak operacji`.

To potwierdza, że rekord kursanta może mieć powiązany obiekt kursu/enrollment z kontekstem PKK.

Minimalny własny model:
- `student`
- `student_course` / `enrollment`
- `category_id`
- `training_type`
- `pkk_profile_id` / `pkk_number_reference`
- `last_pkk_operation` / relation to PKK operation log

Nagłówek `Kursy` jest w liczbie mnogiej, ale na tym ekranie nie obserwujemy jednego kursanta z wieloma równoczesnymi kaflami. Multiplicity >1 pozostaje `TO_VERIFY`.

---

## 7. Telefon i data dodania

Potwierdzone pola listy:
- `Telefon`,
- `Data dodania`.

Dla naszego modelu:
- telefon należy do profilu kursanta,
- data dodania powinna wynikać z rzeczywistego `created_at` / membership creation timestamp.

---

## 8. Egzamin wewnętrzny

Kolumna `Egzamin wewnętrzny` jest potwierdzona.

Na obserwowanych wierszach widoczne są różne prezentacje:
- `-`,
- czerwony znak `X` / negatywny indykator wizualny.

**Znaczenie czerwonego `X` nie jest jeszcze potwierdzone.** Nie interpretujemy go bez dalszego audytu jako:
- niezdany egzamin,
- brak egzaminu,
- brak uprawnienia,
- nieprzeprowadzony egzamin,
- błąd.

Status semantyki: `TO_VERIFY`.

---

## 9. Akcje wiersza

Potwierdzone elementy akcji:
- `Podgląd`,
- `Przejdź do`.

### `Przejdź do`
Potwierdzony wzorzec trasy:
`/kursanci/<student_id>`

Zaobserwowany identyfikator w trasie ma postać nieprzewidywalnego tokenu/ID. Konkretnych wartości nie zapisujemy.

To potwierdza osobny ekran szczegółów kursanta.

### `Podgląd`
Element jest widoczny przy wierszu. Dokładne zachowanie po kliknięciu pozostaje do sprawdzenia:
- drawer,
- modal,
- quick preview,
- tooltip / link pomocniczy.

Nie łączymy `Podgląd` z `Przejdź do`, dopóki nie zobaczymy reakcji UI.

---

## 10. Model domenowy — wnioski

Ekran wspiera rozdzielenie co najmniej następujących encji:

### `students`
- id
- organization_id
- first_name
- last_name
- phone nullable
- created_at
- archived_at nullable — do potwierdzenia na szczegółach

### `student_learning_accounts`
- id
- student_id
- username / login identity
- language_code
- account status — do potwierdzenia

### `student_courses` / `enrollments`
- id
- student_id
- category_id
- training_type
- pkk_reference nullable
- created_at
- status — do potwierdzenia

### PKK
PKK pozostaje osobnym agregatem/integracją z historią operacji. Kurs/enrollment może wskazywać bieżący profil PKK i prezentować ostatnią operację.

### Egzamin wewnętrzny
Status/relacja egzaminu nie powinny być pojedynczym booleanem na `students`; egzamin ma własny lifecycle i historię prób. Kolumna listy jest jedynie projekcją tego stanu.

---

## 11. Potwierdzone akcje ekranu

- `add_student_from_pkk`
- `add_student_manual`
- `search_students`
- `open_student_filters`
- `open_student_sort`
- `preview_student` — visible, behavior to verify
- `open_student_details`

---

## 12. Bezpieczeństwo

- identyfikator kursanta w trasie musi być autoryzowany względem `organization_id`,
- login kursanta nie może pełnić funkcji autoryzacji zasobu,
- numer PKK i dane logowania nie powinny trafiać do zwykłych logów frontend/backend,
- search/filter API musi być tenant-scoped,
- dane logowania powinny podlegać osobnym uprawnieniom od podstawowej listy kursantów, jeśli w naszym RBAC rozdzielimy takie możliwości.

---

## 13. Czego nadal nie znamy

Po tym ekranie w module `Kursanci` pozostają głównie:
- formularz `Dodaj kursanta`,
- flow `Dodaj kursanta z PKK`,
- zachowanie `Podgląd`,
- ekran `/kursanci/{id}`,
- edycja kursanta,
- pełna lista filtrów,
- pełna lista sortowań,
- paginacja,
- znaczenie czerwonego `X` w `Egzamin wewnętrzny`,
- multiplicity kursów/enrollments,
- statusy kursu,
- zarządzanie kontem/logowaniem,
- licencje i postęp nauki na szczegółach,
- egzamin wewnętrzny na szczegółach,
- archiwizacja/usunięcie,
- walidacje i komunikaty success/error.

---

## 14. Acceptance criteria

### AC-STU-LIST-01 — lista
Administrator widzi listę kursantów z nazwą, danymi logowania, kursem, telefonem, datą dodania i kolumną egzaminu wewnętrznego.

### AC-STU-LIST-02 — dwa sposoby dodania
Administrator ma osobne akcje rozpoczęcia dodawania kursanta ręcznie oraz z PKK.

### AC-STU-LIST-03 — search/filter/sort
Lista posiada wyszukiwanie, filtrowanie i sortowanie.

### AC-STU-LIST-04 — learning account projection
Lista może pokazać język i login powiązanego konta nauki bez łączenia profilu kursanta i konta w jeden model domenowy.

### AC-STU-LIST-05 — PKK course projection
Powiązany kurs może prezentować kategorię, typ szkolenia, referencję PKK i ostatnią operację PKK.

### AC-STU-LIST-06 — detail route
`Przejdź do` otwiera osobny ekran szczegółów kursanta należącego do bieżącego OSK.

### AC-STU-LIST-07 — tenant isolation
Zmiana identyfikatora w `/kursanci/{id}` nie może pozwolić na odczyt kursanta innego OSK.
