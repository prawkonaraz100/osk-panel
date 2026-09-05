# 73. Moja szkoła — Lokalizacje — formularz edycji

Data weryfikacji: 2026-09-05

**Kontekst:** `Moja szkoła -> Lokalizacje -> Edytuj`  
**Źródło:** bieżący zalogowany drawer przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Drawer

Zaobserwowany przykład: `Sala wykładowa`.

Formularz edycji ma ten sam rdzeń pól co formularz dodawania, ale jest wypełniony bieżącymi wartościami rekordu.

Akcje:
- `Zapisz`,
- `Anuluj`,
- zamknięcie `X`,
- dodatkowa akcja archiwizacji: `Archiwizuj filie`.

## 2. Pola

Potwierdzone pola, wszystkie oznaczone `*` jako wymagane:
- `Rodzaj`,
- `Nazwa`,
- `Ulica i nr`,
- `Kod pocztowy`,
- `Miejscowość`.

Zaobserwowany rekord:
- Rodzaj: `Sala wykładowa`,
- Nazwa: `Sala wykładowa`,
- Ulica i nr: `Bielerzewskiej 4B`,
- Kod pocztowy: `61-369`,
- Miejscowość: `Kowary`.

## 3. Edytowalność

`Rodzaj` pozostaje kontrolką select również podczas edycji, więc zmiana rodzaju lokalizacji jest dostępna w UI.

Potwierdzone opcje słownika z formularza create:
- `Filia`,
- `Sala wykładowa`,
- `Plac manewrowy`.

Pozostałe pola są zwykłymi edytowalnymi kontrolkami.

## 4. Miejscowość

Pole miejscowości zachowuje formę wyboru z katalogu/wyszukiwarki także podczas edycji; pokazana była wybrana miejscowość `Kowary`.

## 5. Archiwizacja

Na dole formularza znajduje się akcja tekstowa `Archiwizuj filie`.

Ważna obserwacja:
- akcja ta jest widoczna również dla rekordu typu `Sala wykładowa`,
- dlatego etykietę `Archiwizuj filie` traktujemy jako niespójność/ogólną etykietę konkurenta, a nie jako dowód, że archiwizować można wyłącznie typ `Filia`.

Dla własnego produktu używamy neutralnej nazwy:
- `Archiwizuj lokalizację`.

Dokładny modal potwierdzenia i skutki archiwizacji pozostają `TO_VERIFY_AUTH` do chwili kliknięcia tej akcji.

## 6. Model naszego produktu

Edycja aktualizuje bieżący `school_location`, ale historia zmian ważnych pól powinna być audytowana:
- type before/after,
- name before/after,
- address before/after,
- locality before/after,
- actor,
- timestamp.

Archiwizacja powinna być soft-state:
- `status = archived`,
- bez fizycznego kasowania historycznych relacji,
- istniejące wydarzenia/kursy/dokumenty zachowują historyczny snapshot lub referencję,
- zarchiwizowana lokalizacja nie powinna być domyślnie proponowana do nowych przypisań.

## 7. Potwierdzone akcje

- `open_edit_school_location_drawer`
- `edit_school_location_type`
- `edit_school_location_name`
- `edit_school_location_street_and_number`
- `edit_school_location_postal_code`
- `edit_school_location_locality`
- `save_school_location_changes`
- `cancel_school_location_edit`
- `archive_school_location_action_visible`

## 8. Pozostałe niewiadome

- modal po kliknięciu `Archiwizuj filie`,
- czy archiwizację można cofnąć,
- gdzie wyświetlane są zarchiwizowane lokalizacje,
- zachowanie powiązań z kalendarzem, pojazdami, pracownikami i kursantami,
- exact komunikaty sukcesu/błędu.
