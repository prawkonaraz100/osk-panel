# 75. Integracja PKK — punkty wejścia z listy kursantów


> **Current runtime boundary (2026-09-15):** ten dokument zachowuje reverse-engineering/evidence i przyszłą capability PKK. Nie jest dowodem aktywnej integracji PWPW. Aktualny Core ma lokalną, ręcznie wprowadzaną course-scoped identity PKK; provider-specific import, live calls, konfiguracja połączenia i operacyjny UI pozostają `FROZEN_UNTIL_EXPLICIT_UNFREEZE` do czasu autorytatywnych wytycznych PWPW.
Data weryfikacji: 2026-09-05

**Kontekst:** `Kursanci` + strona informacyjna `/integracja-pkk`  
**Źródło:** bieżący zalogowany panel przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Ważne rozróżnienie

Trasa `/integracja-pkk` jest stroną informacyjną/onboardingową. Nie jest głównym ekranem wykonywania operacji PKK.

Strona sama wskazuje właściwą ścieżkę operacyjną:

`Kursanci -> Profil kursanta -> Kursy (PKK) -> Zarządzaj PKK`

Przycisk `Otwórz kursantów` / `Przejdź do kursantów` prowadzi do `/kursanci`.

Wniosek dla własnego produktu: moduł PKK powinien być przede wszystkim kontekstowy względem kursanta i konkretnego kursu, a globalna pozycja menu może pełnić rolę strony informacyjnej / statusowej / skrótu.

## 2. Punkty wejścia na liście kursantów

Na liście `Kursanci` potwierdzono dwa wejścia związane z PKK:

1. `Dodaj kursanta z PKK`
   - osobna akcja obok zwykłego `Dodaj kursanta`,
   - rozpoczyna ścieżkę dla osoby posiadającej PKK.

2. Istniejący kursant z kursem PKK
   - w kolumnie `Kursy` widać dane konkretnego kursu,
   - zaobserwowany przykład Pawła Kowalskiego:
     - kategoria: `A`,
     - etap/typ prezentowany: `Teoria`,
     - `PKK 445645645646455`,
     - `Ostatnia operacja PKK: Brak operacji`.

## 3. Model domenowy

PKK wiążemy z **konkretnym kursem/enrollmentem**, nie wyłącznie z rekordem osoby.

Powód:
- jeden kursant może posiadać wiele kursów/kategorii w czasie,
- każdy kurs może mieć własny numer PKK,
- historia operacji PKK ma pozostawać przy danym kursie,
- status ostatniej operacji PKK jest prezentowany w kontekście kursu.

Minimalna relacja:

`student -> course_enrollment -> pkk_profile / pkk_operation_history`

Nie modelujemy:

`student -> one_global_pkk`

## 4. Dane prezentowane na liście

Dla kursu powiązanego z PKK panel może prezentować skrót:
- kategoria,
- etap/rodzaj kursu,
- numer PKK,
- ostatnia operacja PKK,
- status ostatniej operacji.

Przykład stanu początkowego:
- `Ostatnia operacja PKK: Brak operacji`.

## 5. Strona informacyjna `/integracja-pkk`

Potwierdzone funkcje opisane przez operatora:
- pobranie Profilu Kandydata,
- podgląd szczegółów profilu,
- aktualizacja szkolenia i zwrot,
- zwrot PKK do innego OSK,
- zwrot PKK do urzędu,
- zwrot przedawnionego profilu,
- historia operacji.

Przy aktualizacji szkolenia operator opisuje flow podpisania XML:
`pobierz XML -> podpis.gov.pl -> wgraj podpisany plik`.

Dokładne operacyjne formularze pozostają do audytu w ekranie `Zarządzaj PKK` przy konkretnym kursie.

## 6. Własny produkt — decyzje

- zachować przycisk `Dodaj kursanta z PKK`,
- PKK zawsze przypinać do `course_enrollment`,
- globalna sekcja `Integracja PKK` może być dashboardem/status page + instrukcją,
- wszystkie operacje wykonywać z poziomu konkretnego kursu,
- historia operacji musi być append-only/audytowalna,
- ostatnią operację można denormalizować do szybkiego podglądu na liście kursantów.

## 7. Do dalszej weryfikacji

- drawer/formularz `Dodaj kursanta z PKK`,
- ekran `Zarządzaj PKK`,
- dokładne wymagane identyfikatory przy pobraniu profilu,
- struktura podglądu szczegółów PKK,
- flow XML przy aktualizacji szkolenia,
- statusy i błędy API,
- szczegóły zwrotu do innego OSK / urzędu / przedawnionego profilu,
- historia operacji i jej kolumny.