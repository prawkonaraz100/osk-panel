# 19. Moja szkoła — Lokalizacje

Data weryfikacji: 2026-09-05

**Route:** `/lokalizacje`  
**Źródło:** bieżący zalogowany ekran przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Ten dokument zastępuje wcześniejsze założenie, że moduł `Lokalizacje` jest tylko ogólnym zasobem wymagającym dalszej weryfikacji.

## 1. Cel ekranu

`Lokalizacje` jest listą fizycznych miejsc wykorzystywanych przez OSK. Lokalizacja jest pełnoprawnym zasobem operacyjnym, a nie jedynie adresem organizacji.

Na potwierdzonym ekranie występują co najmniej dwa rodzaje lokalizacji:
- `Sala wykładowa`,
- `Plac manewrowy`.

Rodzaj i nazwa są osobnymi polami. Oznacza to, że administrator może mieć wiele lokalizacji tego samego rodzaju z własnymi nazwami.

## 2. Potwierdzone elementy ekranu

### Akcja główna
- `Dodaj lokalizacje` — otwiera proces utworzenia nowej lokalizacji.

### Kolumny listy
1. `Rodzaj`
2. `Nazwa`
3. `Adres`

### Akcje wiersza
- `Kalendarz`
- `Edytuj`

Nie potwierdzono na tym ekranie akcji `Usuń`, `Archiwizuj` ani `Dezaktywuj`.

## 3. Potwierdzone dane lokalizacji

Minimalny model widoczny na liście:

- `type` — rodzaj lokalizacji,
- `name` — nazwa własna lokalizacji,
- `address` — pełny adres prezentacyjny.

W obserwowanym widoku adres jest prezentowany jako jedna sformatowana wartość. Nie przesądza to jeszcze, czy formularz edycji przechowuje ulicę, numer, kod i miejscowość w osobnych polach — to trzeba zweryfikować na ekranie dodawania/edycji.

## 4. Rodzaje lokalizacji

Potwierdzone wartości:
- `lecture_room` / etykieta `Sala wykładowa`,
- `maneuvering_area` / etykieta `Plac manewrowy`.

W naszym systemie typ powinien być wartością domenową/konfigurowalną, a nie swobodnym tekstem, jeśli formularz 360 potwierdzi wybór z listy.

Nie zakładamy jeszcze, że powyższe dwa typy są jedynymi możliwymi.

## 5. Powiązanie z kalendarzem

Każdy rekord ma akcję `Kalendarz`, która prowadzi do:

`/kalendarz?b=<location_uuid>`

To jest ważne potwierdzenie architektury:
- lokalizacja ma własny stabilny identyfikator,
- kalendarz może być otwierany w kontekście konkretnej lokalizacji,
- lokalizacja jest filtrem/kontekstem operacyjnym kalendarza.

Nie zapisujemy w dokumentacji konkretnych UUID z audytowanego konta; dokumentujemy wyłącznie wzorzec.

### Wymaganie naszego odpowiednika

Powinniśmy obsłużyć:
- `GET /calendar?location_id=<uuid>` albo równoważny parametr,
- filtrowanie wydarzeń po lokalizacji,
- sprawdzanie uprawnień użytkownika do danej lokalizacji,
- zachowanie filtra lokalizacji przy tworzeniu nowego wydarzenia z widoku lokalizacji.

## 6. Akcja `Edytuj`

Potwierdzono możliwość edycji każdego rekordu.

Do zweryfikowania po wejściu w formularz:
- pola formularza,
- walidacje,
- możliwość zmiany rodzaju,
- możliwość zmiany adresu,
- możliwość zmiany nazwy,
- możliwość usunięcia/dezaktywacji,
- zachowanie przy lokalizacji używanej już w kalendarzu.

## 7. Akcja `Dodaj lokalizacje`

Potwierdzono osobny proces tworzenia.

Minimalny wymagany flow naszego systemu:

`list -> add -> validate -> create -> return_to_list`

Potwierdzone z listy dane wymagane domenowo do utworzenia odpowiednika:
- rodzaj,
- nazwa,
- adres.

Dokładna konstrukcja adresu i required fields pozostają do weryfikacji na formularzu.

## 8. Model danych naszego odpowiednika

### `locations`
- `id` UUID
- `organization_id`
- `type`
- `name`
- `address_line1` nullable do czasu weryfikacji formularza
- `address_line2` nullable
- `postal_code` nullable
- `city` nullable
- `country_code` default `PL`
- `display_address`
- `is_active` — decyzja projektowa; ekran nie potwierdza dezaktywacji
- `created_at`
- `updated_at`

### Relacje
Potwierdzona:
- `location -> calendar events` / filtr kalendarza.

Projektowo prawdopodobne, ale nadal do potwierdzenia:
- `location -> employees`,
- `location -> vehicles`,
- `location -> business cards`,
- `location -> students/courses`.

Nie oznaczamy tych relacji jako zachowanie 360 bez kolejnych ekranów.

## 9. Rekomendowane API

- `GET /api/v1/locations`
- `POST /api/v1/locations`
- `GET /api/v1/locations/{location}`
- `PATCH /api/v1/locations/{location}`
- `GET /api/v1/calendar/events?location_id={location}`

`DELETE`/archive endpoint nie jest jeszcze częścią parytetu — brak potwierdzonej akcji na aktualnym ekranie.

## 10. Uprawnienia

Minimum dla naszego produktu:
- odczyt lokalizacji tylko w obrębie własnego `organization_id`,
- tworzenie/edycja przez uprawnioną rolę administracyjną,
- dostęp do kalendarza lokalizacji zgodnie z permissions użytkownika.

Dokładna macierz ról 360 pozostaje do zebrania z panelu pracowników/uprawnień.

## 11. Acceptance criteria

### AC-LOC-01 — lista
**Given** OSK ma zapisane lokalizacje  
**When** administrator otworzy `/lokalizacje`  
**Then** widzi co najmniej rodzaj, nazwę i adres każdej lokalizacji.

### AC-LOC-02 — typy
System wspiera co najmniej typy odpowiadające `Sala wykładowa` oraz `Plac manewrowy`.

### AC-LOC-03 — edycja
**Given** administrator ma uprawnienie do zarządzania lokalizacjami  
**When** wybierze `Edytuj`  
**Then** może wejść do formularza wskazanej lokalizacji bez dostępu do lokalizacji innego tenanta.

### AC-LOC-04 — kalendarz lokalizacji
**Given** administrator wybierze `Kalendarz` przy lokalizacji  
**Then** kalendarz otwiera się z aktywnym kontekstem/filtracją wskazanej lokalizacji.

### AC-LOC-05 — utworzenie
**Given** administrator wybierze `Dodaj lokalizacje` i poda poprawne wymagane dane  
**Then** powstaje nowy rekord lokalizacji przypisany do bieżącego OSK.

## 12. Pozostałe luki

Do pełnego zamknięcia modułu potrzebujemy jeszcze:
- ekran `Dodaj lokalizacje`,
- ekran `Edytuj`,
- listę wszystkich możliwych rodzajów,
- dokładne pola adresowe,
- required/optional,
- walidacje i komunikaty,
- potwierdzenie istnienia lub braku usuwania/dezaktywacji,
- zachowanie lokalizacji używanej w istniejących wydarzeniach,
- ewentualne filtrowanie/sortowanie/paginację listy.
