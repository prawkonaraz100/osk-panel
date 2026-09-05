# 46. Kursanci — filtry listy

Data weryfikacji: 2026-09-05

**Kontekst:** `/kursanci` -> `Filtruj`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## Potwierdzony drawer

Tytuł: `Filtrowanie`.

Akcje:
- `Filtruj`,
- zamknięcie `X`.

## 1. Kategoria kursu

Pole `Kategoria kursu` jest multi-selectem.

UI komunikuje możliwość wyboru dowolnej liczby kategorii.

Dokładna lista kategorii nie była rozwinięta na tym ekranie, ale słownik kategorii jest już obserwowany w innych formularzach kursu.

## 2. Etap szkolenia

Można zaznaczyć wiele etapów jednocześnie.

Potwierdzone opcje:
- `Nieprzypisany`,
- `Teoria`,
- `Praktyka`,
- `Dokumentacja`,
- `Egzamin WORD`,
- `Szkolenie uzupełniające`,
- `Szkolenie zakończone`.

Etapy odpowiadają lifecycle widocznemu również na karcie kursanta.

## 3. Egzamin wewnętrzny

Sekcja `Egzamin wewnętrzny` zawiera switch:
- `Pokaż tylko niezaliczone`.

Interpretacja minimalna:
- lista może zostać ograniczona do kursantów spełniających warunek `internal_exam_not_passed`.

Nie wyprowadzamy z tego ekranu dokładnej definicji statusów egzaminu ani tego, czy obejmuje `nieprzeprowadzony`, `niezdany`, wygasły dostęp itp. Semantyka filtra na poziomie backendu pozostaje do implementacji według naszego modelu egzaminów.

## 4. Archiwum

Osobny switch:
- `Pokaż zarchiwizowane`.

Domyślny stan na obserwowanym ekranie był wyłączony.

To potwierdza, że archiwalni kursanci są domyślnie ukryci z podstawowej listy i mogą zostać jawnie dołączeni przez filtr.

## 5. Wniosek dla naszego produktu

Filtry powinny być łączalne i tenant-scoped:
- category_ids[],
- training_stage_ids[],
- internal_exam_not_passed boolean,
- include_archived boolean.

Filtrowanie musi działać po stronie backendu dla większych zbiorów danych; UI nie powinno pobierać wszystkich kursantów i filtrować ich wyłącznie lokalnie.

## Acceptance criteria

### AC-STUDENT-FILTER-01
Administrator może wybrać wiele kategorii kursu.

### AC-STUDENT-FILTER-02
Administrator może zaznaczyć wiele etapów szkolenia.

### AC-STUDENT-FILTER-03
Administrator może ograniczyć listę do kursantów z niezaliczonym egzaminem wewnętrznym.

### AC-STUDENT-FILTER-04
Administrator może dołączyć kursantów zarchiwizowanych.

### AC-STUDENT-FILTER-05
Filtry mogą działać łącznie i są ograniczone do bieżącego OSK.
