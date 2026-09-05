# 69. Egzamin wewnętrzny — filtrowanie listy

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> `Filtruj`  
**Źródło:** bieżący zalogowany drawer przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Drawer `Filtrowanie`

Drawer zawiera dwa niezależne obszary filtrowania:

### Kategoria kursu
- kontrolka wielokrotnego wyboru,
- placeholder: `Wybierz dowolną ilość kategorii`,
- exact lista kategorii nie została ponownie rozwinięta w tym capture; korzystamy z katalogu kategorii jako danych konfigurowalnych.

### Status egzaminu
Cztery niezależne checkboxy:
- `Brak przypisanego`,
- `Niezaliczony`,
- `Nie przeprowadzony`,
- `Zaliczony`.

Akcja końcowa:
- `Filtruj`.

## 2. Semantyka statusów

Potwierdzony przez historię prób status:
- `Niezaliczony` — istniejący zakończony egzamin z wynikiem negatywnym.

`Zaliczony` jest jawnie dostępny w filtrze, co potwierdza istnienie pozytywnego stanu biznesowego nawet jeśli nie mieliśmy jeszcze rekordu z jego wizualizacją w tabeli.

Filtr osobno udostępnia `Brak przypisanego` i `Nie przeprowadzony`. To bardzo mocno wskazuje, że system rozróżnia:
- brak przypisanego/wygenerowanego egzaminu dla kursanta,
- egzamin przypisany/przygotowany, ale jeszcze niewykonany.

Dokładna implementacja lifecycle konkurenta pozostaje nieobserwowana, więc nie utożsamiamy tych nazw z konkretnymi wewnętrznymi statusami technicznymi bez dalszego dowodu.

## 3. Model naszego produktu

W naszym panelu filtr powinien działać na projekcji stanu formalnego kursanta i prób egzaminacyjnych.

Rekomendowane publiczne filtry UI:
- `not_assigned` — brak przygotowanego formalnego egzaminu dla danego wymaganego etapu,
- `not_conducted` — egzamin/próba przygotowana, ale jeszcze nie zakończona,
- `failed` — zakończona negatywnie,
- `passed` — zakończona pozytywnie.

Techniczny lifecycle może być bogatszy, np. `draft`, `ready`, `sent`, `opened`, `started`, `completed`, `expired`, `cancelled`, ale UI nie musi eksponować wszystkich stanów technicznych jako oddzielnych checkboxów.

## 4. Ważna relacja z regułami formalnego kursu

Filtr `Brak przypisanego` powinien uwzględniać, czy dana część egzaminu jest w ogóle wymagana dla konkretnego `course_enrollment`.

Przykład:
- C+E z teorią niewymaganą nie może być oznaczone jako `Brak przypisanego` teoretycznego egzaminu wewnętrznego,
- brak teorii w takim przypadku jest stanem prawidłowym, a nie brakiem do uzupełnienia.

Źródłem wymagań jest rule engine opisany w:
- `docs/66-formal-student-record-and-theory-exemptions.md`,
- `docs/67-editable-training-requirements-and-theory-exemption.md`.

## 5. Potwierdzone akcje

- `open_internal_exam_filter_drawer`
- `filter_by_multiple_course_categories`
- `filter_by_unassigned_exam`
- `filter_by_failed_exam`
- `filter_by_not_conducted_exam`
- `filter_by_passed_exam`
- `apply_internal_exam_filters`

## 6. Pozostałe niewiadome

- exact kombinowanie wielu statusów: OR vs inne zachowanie — rekomendacja własna: OR w obrębie statusów,
- zachowanie filtrowania kategorii względem wielu kursów jednego kursanta,
- zachowanie URL/query state po zastosowaniu filtra,
- reset/wyczyszczenie filtrów,
- licznik aktywnych filtrów.
