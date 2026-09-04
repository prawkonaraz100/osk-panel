# 23. Pracownicy — ekran szczegółów pracownika

Data weryfikacji: 2026-09-05

**Route pattern:** `/pracownicy/<staff_uuid>`  
**Źródło:** bieżący zalogowany ekran + screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument opisuje aktualny ekran szczegółów pracownika i uzupełnia:
- `docs/21-staff-screen.md`
- `docs/22-staff-create-form.md`
- `docs/24-staff-edit-form.md`

Dane osobowe i UUID z audytowanego konta nie są przepisywane do dokumentacji.

---

## 1. Struktura ekranu

Ekran składa się z:

1. breadcrumb `Panel główny / Pracownicy / <pracownik>`,
2. akcji `Powrót`,
3. zdjęcia i imienia/nazwiska pracownika,
4. globalnych akcji rekordu `Archiwizuj` i `Usuń`,
5. sekcji `Szczegóły pracownika`,
6. sekcji `Dostęp do panelu`,
7. sekcji `Kalendarz`.

Potwierdza to rozdzielenie trzech obszarów domenowych:
- profil/ewidencja pracownika,
- konto i autoryzacja,
- planowanie pracy.

---

## 2. Potwierdzone akcje globalne

### `Powrót`
Prowadzi do:
`/pracownicy`

### `Archiwizuj`
Potwierdzona widoczna akcja rekordu.

Wniosek dla naszego systemu:
- archiwizacja jest osobnym stanem/procesem od trwałego usunięcia,
- należy zachować historię powiązań pracownika z wydarzeniami, dokumentami i audytem,
- dokładny modal potwierdzający i skutek dla kalendarza pozostają do sprawdzenia.

### `Usuń`
Potwierdzona osobna widoczna akcja rekordu.

Nie zakładamy jeszcze:
- czy jest to hard delete,
- soft delete,
- czy usunięcie jest blokowane przy istniejących powiązaniach,
- jaki komunikat potwierdzenia jest używany.

Dla naszego systemu rekomendacja: preferować archiwizację/soft-delete tam, gdzie istnieją dane historyczne.

---

## 3. Sekcja `Szczegóły pracownika`

Posiada działającą akcję:
- `Edytuj`

### Podsekcja `Dane`
Potwierdzone pola prezentowane w szczegółach:
- Imię i nazwisko,
- Rodzaj pracownika,
- Email,
- Telefon,
- Pesel,
- Numer uprawnień,
- Kategorie,
- Data dodania.

Na obserwowanym rekordzie część pól może być pusta.

### `Rodzaj pracownika`
Na ekranie szczegółów obserwowana jest wartość:
- `OfficeWorker`

Na formularzu edycji tego samego typu wyświetlana jest etykieta:
- `Pracownik biurowy`

Wniosek: konkurencyjny system posiada co najmniej techniczną wartość/kod oraz etykietę użytkową albo niespójnie renderuje enum. W naszym systemie rozdzielamy:
- `staff_type.code`, np. `office_worker`,
- lokalizowaną etykietę, np. `Pracownik biurowy`.

Nie pokazujemy użytkownikowi surowego enuma technicznego.

### `Data dodania`
Potwierdzone, że system zachowuje i pokazuje datę utworzenia rekordu pracownika.

---

## 4. Formularz `Edytuj` szczegółów — potwierdzony

Formularz został zweryfikowany osobnym screenshotem i jest szczegółowo opisany w:
`docs/24-staff-edit-form.md`.

### Widocznie wymagane pola
- E-mail,
- Imię,
- Nazwisko,
- Rodzaj pracownika.

### Pozostałe potwierdzone pola
- Pesel,
- Telefon,
- Numer uprawnień,
- Kategorie — multi-select,
- Lokalizacje — multi-select,
- Ważność legitymacji,
- Ważność badań lekarskich,
- Ważność badań psychologicznych,
- opcjonalne zdjęcie,
- `Utwórz konto do logowania`.

### Potwierdzone akcje formularza
- Zapisz,
- Anuluj,
- zamknięcie `X`,
- zmiana/usunięcie zdjęcia z formularza,
- wybór wielu rodzajów pracownika,
- wybór wielu kategorii,
- wybór wielu lokalizacji,
- wybór trzech niezależnych dat ważności.

Najważniejszy wniosek: istniejący pracownik bez konta może zostać później oznaczony do utworzenia konta podczas edycji.

---

## 5. Sekcja `Ważność`

Potwierdzone pozycje:
- Legitymacji,
- Badań lekarskich,
- Badań psychologicznych.

Każda prezentuje własną datę ważności.

Na obserwowanym ekranie przeterminowane terminy są oznaczone ikoną ostrzegawczą.

Nie łączymy tych trzech terminów w jeden status pracownika.

---

## 6. Sekcja `Dostęp do panelu`

Sekcja pokazuje element tekstowy/sterujący:
- `Edytuj`

### Potwierdzone pole informacyjne
`Czy pracownik ma dostęp do panelu?`

Zaobserwowana wartość:
- `Nie`

To potwierdza rozdzielenie:
- `staff_profile`
- opcjonalnego `user/account`.

Formularz edycji danych pracownika dodatkowo potwierdził możliwość późniejszego uruchomienia procesu `Utwórz konto do logowania`.

### Ważna korekta audytu — `Edytuj` jest widoczne, ale nie udało się go uruchomić

W bieżącym obserwowanym stanie użytkownik próbował otworzyć `Edytuj` w sekcji `Dostęp do panelu`, ale kontrolka **nie uruchomiła żadnego widoku ani formularza**.

Status tej kontrolki:
- `VISIBLE_BUT_NOT_ACTIONABLE`
- `REASON_UNKNOWN`

Na ekranie widoczny jest jednocześnie banner `Przeglądasz panel demonstracyjny`, dlatego jedną z możliwych przyczyn może być ograniczenie trybu demo. Nie zapisujemy tego jednak jako faktu, ponieważ brak bezpośredniego potwierdzenia.

Nie można też wykluczyć innych przyczyn, np.:
- brak uprawnienia,
- nieaktywna/niezaimplementowana akcja w tym stanie rekordu,
- ograniczenie zależne od braku konta pracownika,
- błąd frontendu.

Wniosek dokumentacyjny:
- **nie traktujemy `edit_panel_access` jako potwierdzonej działającej akcji**, tylko jako widoczny element UI;
- nie znamy osobnego ekranu edycji dostępu do panelu;
- jedyny faktycznie potwierdzony działający punkt rozpoczęcia tworzenia konta to `Utwórz konto do logowania` w formularzu dodawania/edycji pracownika.

Do dalszego audytu, jeśli kiedyś będzie możliwy na koncie poza tym ograniczeniem:
- włączenie/wyłączenie dostępu,
- login/e-mail konta,
- hasło lub zaproszenie,
- role i permissions,
- zakres dostępu per lokalizacja/moduł,
- sposób odebrania dostępu,
- reset hasła,
- komunikaty i walidacje.

---

## 7. Wbudowany `Kalendarz`

Ekran szczegółów zawiera osadzony kalendarz pracownika.

Potwierdzone elementy:
- tytuł `Kalendarz`,
- akcja `Pełny kalendarz`,
- akcja `Dodaj wydarzenie`,
- nawigacja poprzedni/następny okres,
- przycisk `Dzisiaj`,
- przełączniki widoku:
  - `Miesiąc`,
  - `Tydzień`,
  - `Dzień`,
- widok miesięczny z siatką dni.

### `Pełny kalendarz`
Potwierdzony link:
`/kalendarz`

Nie przesądzamy jeszcze, czy po przejściu zachowany jest filtr konkretnego pracownika.

### `Dodaj wydarzenie`
Potwierdzona akcja z poziomu szczegółów pracownika.

Do audytu po kliknięciu:
- formularz wydarzenia,
- typ wydarzenia,
- data i czas,
- pracownik,
- kursant,
- pojazd,
- lokalizacja,
- status,
- konflikty,
- notatki,
- powtarzalność,
- zapis/anulowanie.

---

## 8. Potwierdzone akcje ekranu szczegółów

Działające/potwierdzone:
- back_to_staff_list
- edit_staff_details
- save_staff_changes
- cancel_staff_edit
- request_create_login_account_from_staff_edit
- open_full_calendar
- add_calendar_event
- calendar_previous_period
- calendar_next_period
- calendar_today
- calendar_view_month
- calendar_view_week
- calendar_view_day

Widoczne, ale lifecycle/skutek niezweryfikowany:
- archive_staff
- delete_staff

Widoczne, ale **nieuruchamialne w obserwowanym stanie**:
- edit_panel_access (`VISIBLE_BUT_NOT_ACTIONABLE`, `REASON_UNKNOWN`)

---

## 9. Aktualizacja modelu domenowego

### `staff_profiles`
Minimalnie:
- id UUID
- organization_id
- first_name
- last_name
- email
- phone nullable
- pesel nullable
- authorization_number nullable
- photo_asset_id nullable
- created_at
- archived_at nullable
- deleted_at nullable — decyzja naszej implementacji

### relacje
Potwierdzone:
- staff <-> staff_types many-to-many
- staff <-> categories many-to-many
- staff <-> locations many-to-many
- staff -> documents/validity records
- staff -> optional panel account
- staff -> calendar/events context

---

## 10. Wnioski dla RBAC

Permissions nie powinny być skutkiem samego typu pracownika.

Model naszego produktu rozdziela:
- funkcję biznesową pracownika (`staff_types`),
- konto uwierzytelniające (`user`),
- membership w organizacji,
- role/permissions.

`Pracownik biurowy` / `office_worker` nie oznacza automatycznie pełnego dostępu administracyjnego.

Ponieważ osobna kontrolka `Dostęp do panelu -> Edytuj` nie dała się uruchomić, **nie odtwarzamy na tej podstawie żadnego nieznanego mechanizmu RBAC konkurenta**.

---

## 11. Czego nadal nie znamy

Po zweryfikowaniu szczegółów i formularza edycji w module Pracownicy pozostają głównie:
- rzeczywisty flow provisioningu konta po zaznaczeniu `Utwórz konto do logowania`,
- czy istnieje działający osobny ekran zarządzania `Dostępem do panelu` poza obserwowanym stanem,
- pełny słownik `Rodzaj pracownika`,
- dokładny lifecycle archiwizacji,
- dokładny lifecycle usuwania,
- formularz `Dodaj wydarzenie`,
- wpływ archiwizacji/usunięcia na przyszłe wydarzenia,
- permissions po utworzeniu konta,
- dokładne walidacje PESEL/e-mail/telefonu/numeru uprawnień,
- komunikaty success/error,
- dokładne zachowanie usuwania zdjęcia.

---

## 12. Acceptance criteria

### AC-STF-DET-01 — szczegóły
Po wejściu na `/pracownicy/{id}` administrator widzi profil pracownika, terminy dokumentów, stan dostępu do panelu i kalendarz.

### AC-STF-DET-02 — konto opcjonalne
Dane pracownika są niezależne od opcjonalnego konta do logowania.

### AC-STF-DET-03 — archiwizacja
Administrator widzi osobną akcję archiwizacji pracownika niezależną od usunięcia; dokładny skutek pozostaje do potwierdzenia.

### AC-STF-DET-04 — usunięcie
Administrator widzi osobną akcję usunięcia; nasza implementacja chroni integralność historycznych danych i zależności.

### AC-STF-DET-05 — dokumenty
Każdy z trzech terminów ważności jest prezentowany i edytowany niezależnie.

### AC-STF-DET-06 — calendar views
Kalendarz na karcie pracownika obsługuje co najmniej widoki miesiąc/tydzień/dzień oraz nawigację poprzedni/następny/dzisiaj.

### AC-STF-DET-07 — add event
Z poziomu szczegółów pracownika dostępna jest akcja utworzenia wydarzenia kalendarza.

### AC-STF-DET-08 — późniejszy provisioning konta
Istniejący pracownik bez dostępu do panelu może z formularza edycji rozpocząć proces utworzenia konta do logowania.

### AC-STF-DET-09 — nieudokumentowana kontrolka dostępu
Widoczna, ale nieuruchamialna kontrolka `Dostęp do panelu -> Edytuj` nie może być traktowana jako potwierdzony działający workflow konkurenta bez dalszego dowodu.
