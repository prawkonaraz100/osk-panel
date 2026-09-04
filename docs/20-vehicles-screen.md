# 20. Pojazdy — lista i powiązania operacyjne

Data weryfikacji: 2026-09-05

**Route:** `/pojazdy`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument zastępuje wcześniejsze ogólne założenie, że znamy tylko istnienie zasobu pojazdów. Lista, podstawowe pola, alert dokumentu i dwie główne akcje są już potwierdzone.

## 1. Cel ekranu

`Pojazdy` jest centralną listą pojazdów OSK wykorzystywanych operacyjnie w szkoleniu i kalendarzu.

Każdy rekord posiada własny identyfikator UUID używany co najmniej do:
- ekranu szczegółów pojazdu,
- filtrowania kalendarza po pojeździe,
- powiązania zdjęcia pojazdu.

Konkretnych UUID, numerów rejestracyjnych ani danych z audytowanego konta nie zapisujemy w dokumentacji.

## 2. Akcja główna

Potwierdzona akcja:
- `Dodaj pojazd` — uruchamia proces tworzenia nowego pojazdu.

Dokładny formularz dodawania pozostaje do zweryfikowania.

## 3. Potwierdzone kolumny listy

Na ekranie występują:

1. `Zdjęcie`
2. `Nr rejestracyjny`
3. nagłówek przekazany jako `Maria i model`
4. `Kategorie`
5. `Dokumenty`

### Uwaga do nagłówka `Maria i model`

Semantyka danych w rekordach jednoznacznie wskazuje na **markę i model** pojazdu. Nie wiemy, czy `Maria i model` jest literówką w bieżącym UI, błędem ekstrakcji tekstu czy rzeczywistą etykietą. W naszym modelu używamy pola `make_model` / osobnych `make` i `model`. Etykietę UI 360 oznaczamy `TO_VERIFY_VISUAL`.

## 4. Potwierdzone dane pojazdu

### Zdjęcie
- rekord może posiadać fotografię pojazdu,
- zdjęcie jest hostowane w domenie assetowej operatora,
- ścieżka obrazu zawiera identyfikator UUID odpowiadający pojazdowi w obserwowanym widoku.

W naszym systemie obraz powinien być przechowywany jako osobny asset/object storage reference, nie jako blob w tabeli pojazdu.

### Numer rejestracyjny
- osobne pole prezentowane na liście,
- powinno być wyszukiwalne projektowo, ale wyszukiwarka nie została jeszcze potwierdzona na ekranie.

### Marka i model
- lista pokazuje nazwę producenta i model pojazdu,
- przykłady obejmują różne typy pojazdów, więc model nie może zakładać wyłącznie samochodów osobowych.

### Kategorie
- kolumna jest obecna,
- w przekazanych rekordach nie otrzymaliśmy wartości kategorii,
- dokładny format i wielokrotność kategorii pozostają do weryfikacji.

Nazwa kolumny w liczbie mnogiej sugeruje możliwość wielu kategorii, ale nie oznaczamy tego jako potwierdzonego zachowania bez danych z rekordu/formularza.

### Dokumenty
Potwierdzony komunikat stanu:
- `Przegląd wygasł <data>`.

Wnioski:
- pojazd ma co najmniej termin przeglądu/badania technicznego,
- system porównuje termin z bieżącą datą,
- potrafi wyświetlić alert przeterminowania,
- data wygaśnięcia jest częścią prezentowanego komunikatu.

To potwierdza potrzebę oddzielnego modelu dokumentów/terminów pojazdu.

## 5. Potwierdzone akcje wiersza

### `Kalendarz`

Wzorzec trasy:

`/kalendarz?v=<vehicle_uuid>`

Znaczenie:
- kalendarz może działać w kontekście konkretnego pojazdu,
- pojazd jest zasobem kalendarzowym,
- parametr `v` identyfikuje pojazd.

W naszym systemie rekomendowany odpowiednik:

`GET /calendar?vehicle_id=<uuid>`

lub API:

`GET /api/v1/calendar/events?vehicle_id=<uuid>`.

Kalendarz powinien weryfikować tenant ownership pojazdu i wykorzystywać filtr również do wykrywania konfliktów rezerwacji.

### `Zobacz`

Wzorzec trasy:

`/pojazdy/<vehicle_uuid>`

To potwierdza osobny ekran szczegółów pojazdu.

Do dalszego audytu na ekranie szczegółów:
- pełne dane pojazdu,
- wszystkie dokumenty,
- edycja,
- zdjęcie/zmiana zdjęcia,
- historia terminów,
- ubezpieczenie,
- badanie techniczne,
- przypisane kategorie,
- ewentualna lokalizacja,
- status dostępności/serwisu,
- usuwanie/dezaktywacja.

### `Podgląd`

W przekazanym tekście po linku `Zobacz` występuje również słowo `Podgląd`. Bez obrazu/DOM nie rozstrzygamy, czy jest to:
- osobna akcja,
- tooltip,
- accessible label ikony,
- opis linku `Zobacz`.

Status: `TO_VERIFY_VISUAL`.

## 6. Identyfikacja pojazdu

Ten sam UUID-like identifier jest obserwowany w:
- URL szczegółów `/pojazdy/<uuid>`,
- filtrze kalendarza `/kalendarz?v=<uuid>`,
- ścieżce zdjęcia.

W naszym modelu:
- `vehicles.id` powinno być UUID,
- publiczne route param może używać tego UUID lub osobnego public ID,
- każda operacja musi sprawdzać `organization_id` server-side.

## 7. Model dokumentów pojazdu

Potwierdzone minimum:

### `vehicle_documents`
- `id`
- `organization_id`
- `vehicle_id`
- `type`
- `valid_from` nullable
- `valid_until`
- `status`
- `document_number` nullable
- `file_id` nullable
- `created_at`
- `updated_at`

Potwierdzony typ/termin:
- badanie techniczne / przegląd.

Publiczna oferta systemu wspomina również przypomnienia o ubezpieczeniu, ale dokładne pola i prezentacja ubezpieczenia na tym ekranie nie zostały potwierdzone przez przekazany widok.

### Rekomendowane stany dokumentu

Projektowo:
- `valid`
- `expiring_soon`
- `expired`

`expired` jest funkcjonalnie potwierdzony komunikatem `Przegląd wygasł ...`. Pozostałe etykiety/stany 360 wymagają dalszego audytu.

## 8. Minimalny model pojazdu

### `vehicles`
- `id` UUID
- `organization_id`
- `registration_number`
- `make`
- `model`
- `photo_asset_id` nullable
- `is_active` — decyzja projektowa, niepotwierdzona akcją listy
- `created_at`
- `updated_at`

### Kategorie
Do czasu audytu szczegółów nie przesądzamy schematu. Nasz model powinien jednak być gotowy na relację many-to-many:
- `vehicle_categories(vehicle_id, category_id)`.

## 9. Potwierdzone relacje

- `vehicle -> photo`
- `vehicle -> documents`
- `vehicle -> calendar context`
- `vehicle -> details screen`

Do potwierdzenia:
- `vehicle -> location`
- `vehicle -> employees/instructors`
- `vehicle -> categories` jako many-to-many
- `vehicle -> service/unavailability periods`

## 10. Rekomendowane API

- `GET /api/v1/vehicles`
- `POST /api/v1/vehicles`
- `GET /api/v1/vehicles/{vehicle}`
- `PATCH /api/v1/vehicles/{vehicle}`
- `GET /api/v1/vehicles/{vehicle}/documents`
- `GET /api/v1/calendar/events?vehicle_id={vehicle}`

Nie dodajemy jeszcze do parytetu endpointu `DELETE /vehicles/{vehicle}` ani `archive`, bo bieżąca lista nie potwierdza takich akcji.

## 11. Alerty i przypomnienia

Dla naszego produktu dokument pojazdu powinien generować:
- alert przed upływem terminu,
- alert po wygaśnięciu,
- odpowiednie powiadomienie do uprawnionych pracowników,
- widoczny status na liście pojazdów.

Dokładny próg ostrzegania przed terminem w 360 jest nieznany i powinien być konfigurowalny.

## 12. Acceptance criteria

### AC-VEH-01 — lista
**Given** OSK posiada pojazdy  
**When** administrator otworzy `/pojazdy`  
**Then** widzi zdjęcie, numer rejestracyjny, markę/model, kolumnę kategorii oraz stan dokumentów.

### AC-VEH-02 — przeterminowany przegląd
**Given** termin przeglądu minął  
**When** pojazd jest wyświetlany na liście  
**Then** system oznacza przegląd jako wygasły i pokazuje datę wygaśnięcia.

### AC-VEH-03 — kalendarz
**Given** administrator kliknie `Kalendarz` przy pojeździe  
**Then** kalendarz otwiera się z aktywnym kontekstem wskazanego pojazdu.

### AC-VEH-04 — szczegóły
**Given** administrator kliknie `Zobacz`  
**Then** otwiera się ekran `/pojazdy/{uuid}` dla pojazdu należącego do jego OSK.

### AC-VEH-05 — tenant isolation
Podmiana UUID w adresie nie może pozwolić na odczyt pojazdu innego OSK.

## 13. Pozostałe luki

Do pełnego zamknięcia `Pojazdy` potrzebujemy:
- ekranu `Dodaj pojazd`,
- ekranu `/pojazdy/{uuid}`,
- formularza edycji,
- dokładnych typów dokumentów,
- sposobu obsługi ubezpieczenia,
- listy kategorii i sposobu ich wyboru,
- potwierdzenia relacji z lokalizacją,
- walidacji numeru rejestracyjnego,
- zasad zdjęcia (format, rozmiar, crop),
- informacji o usuwaniu/dezaktywacji,
- filtrowania/sortowania/paginacji,
- dokładnego znaczenia `Podgląd`.
