# 76. Integracja PKK — panel operacyjny przy kursie


> **Current runtime boundary (2026-09-15):** ten dokument zachowuje reverse-engineering/evidence i przyszłą capability PKK. Nie jest dowodem aktywnej integracji PWPW. Aktualny Core ma lokalną, ręcznie wprowadzaną course-scoped identity PKK; provider-specific import, live calls, konfiguracja połączenia i operacyjny UI pozostają `FROZEN_UNTIL_EXPLICIT_UNFREEZE` do czasu autorytatywnych wytycznych PWPW.
Data weryfikacji: 2026-09-05

**Kontekst:** `Kursanci -> Profil kursanta -> Kursy (PKK)` dla istniejącego kursu  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Najważniejszy wniosek domenowy

Operacje PKK są wykonywane **w kontekście konkretnego kursu kursanta**, a nie na globalnym rekordzie osoby.

Zaobserwowany kurs Pawła Kowalskiego:
- PKK: `445645645646455`,
- kategoria: `A`,
- rodzaj szkolenia: `Szkolenie podstawowe`,
- data rozpoczęcia: `27-01-2026`,
- ostatnia operacja PKK: `Brak operacji`.

To wspiera model:

`student -> course_enrollment -> pkk_profile_link -> pkk_operations[]`

Jeden kursant może mieć wiele kursów i każdy kurs może posiadać własny numer PKK i własną historię operacji.

## 2. Wybór kursu

Na profilu kursanta występuje kontrola `Wybierz kurs`, np. `Kurs: A`.

Wybrany kurs steruje:
- etapem szkolenia,
- sekcją `Kursy (PKK)`,
- operacjami PKK,
- historią PKK,
- powiązanymi danymi kursu.

## 3. Karta kursu PKK

Potwierdzone pola karty:
- `PKK`,
- `Kategoria`,
- `Rodzaj szkolenia`,
- `Data rozpoczęcia`,
- `Ostatnia operacja PKK`.

Potwierdzone akcje:
- `Zarządzaj PKK`,
- `Edytuj`,
- `Usuń`.

W aktualnym stanie ekran prezentuje również skróconą plakietkę kursu u góry sekcji, np.:
- kategoria `A`,
- oznaczenie `PKK`,
- numer profilu.

Sekcja posiada globalną akcję `Dodaj kurs`, co potwierdza obsługę wielu kursów jednego kursanta.

## 4. Historia operacji PKK

Pod kartą znajduje się osobna sekcja:
- `Historia operacji PKK (N)`.

W zaobserwowanym stanie:
- licznik: `0`,
- komunikat: `Brak operacji PKK`.

Wniosek:
- historia jest per kurs,
- licznik operacji jest prezentowany bezpośrednio przy kursie,
- kolejne operacje powinny tworzyć niezmienne wpisy audytowe z datą, typem operacji, statusem i odpowiedzią integracji.

## 5. Panel danych pobranych z PKK

Po prawej stronie istnieje obszar `DANE POBRANE Z PKK`.

W stanie początkowym wyświetlany jest komunikat:
`Brak pobranych danych PKK dla tego kursu. Użyj „Pobierz PKK”, aby je pobrać.`

Potwierdzone akcje w tym obszarze:
- `Podgląd PKK`,
- `Pobierz PKK`,
- `Aktualizuj i zwróć PKK`.

Układ potwierdzony na najnowszym screenie:
- `Podgląd PKK` znajduje się przy nagłówku obszaru danych,
- `Pobierz PKK` i `Aktualizuj i zwróć PKK` są głównymi akcjami po prawej stronie,
- przed pierwszym pobraniem panel pokazuje jawny empty state zamiast pustej tabeli.

`Pobierz PKK` jest więc pierwszą operacją synchronizującą dane profilu z systemem zewnętrznym dla danego kursu.

Nie zakładamy dokładnego zestawu pól po pobraniu bez obserwacji udanej operacji.

## 6. Relacja z danymi kursanta

Na tym samym profilu widoczne są dane osobowe kursanta, w tym:
- imię i nazwisko,
- data urodzenia,
- PESEL,
- e-mail,
- telefon.

Strona informacyjna Integracji PKK wskazuje, że przed rozpoczęciem operacji należy upewnić się, że kursant ma uzupełniony PESEL.

Dla naszego produktu dane źródłowe kursanta i kursu powinny być walidowane przed wywołaniem operacji PKK, natomiast odpowiedź PKK powinna być przechowywana jako osobny snapshot integracyjny, bez bezpośredniego nadpisywania danych bez świadomej akcji operatora.

## 7. Stan i lifecycle

Minimalny własny model:
- `pkk_not_fetched` — kurs ma numer PKK, ale danych jeszcze nie pobrano,
- `pkk_fetched` — profil pobrany i snapshot zapisany,
- kolejne operacje mają własny status `pending/success/failed` i typ operacji.

Nie utożsamiamy `Ostatnia operacja PKK` z ogólnym stanem kursu. To tylko skrót najnowszej operacji integracyjnej.

## 8. Potwierdzone akcje

- `select_course`
- `add_course`
- `open_manage_pkk`
- `edit_course`
- `delete_course`
- `open_pkk_preview`
- `fetch_pkk`
- `start_update_and_return_pkk`
- `view_pkk_operation_history`

## 9. Ograniczenie audytu konkurenta

Przycisk `Zarządzaj PKK` jest widoczny, ale podczas audytu jego zawartość nie otwierała się poprawnie z powodu błędu/zawieszania strony konkurenta.

Status tego detalu:
- `UNOBSERVABLE_UPSTREAM_ERROR`.

Nie blokuje to projektu, bo zakres operacji został potwierdzony na bieżącej stronie informacyjnej Integracji PKK oraz w samym panelu kursu.

Własny flow dla tego nieobserwowalnego drawera opisuje:
- `docs/77-pkk-management-flow-fallback-design.md`.

## 10. Pozostałe niewiadome konkurencyjne

- exact wygląd drawera `Zarządzaj PKK`,
- exact ekran `Podgląd PKK`,
- dokładny zestaw pól pobranych z PKK,
- exact komunikaty sukcesu/błędu,
- struktura pojedynczego wpisu historii operacji.

Powyższe nie są blockerami implementacji własnego modułu PKK.
