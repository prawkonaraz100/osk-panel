# OSK Panel — clean-room functional specification

Repozytorium dokumentacji i specyfikacji dla własnego panelu administracyjnego OSK PrawkoNaRaz.

Celem projektu jest zbudowanie własnego produktu realizującego tę samą **klasę procesów biznesowych** co analizowane rozwiązania rynkowe, bez kopiowania ich kodu, layoutu, assetów, tekstów ani chronionej implementacji.

## Aktualny stan

Core administratora OSK jest wystarczająco zmapowany do implementacji:

- Panel główny,
- Integracja PKK,
- Kursanci,
- Kursy (PKK) / formalny course enrollment,
- Kalendarz,
- Lokalizacje,
- Pojazdy,
- Pracownicy,
- Licencje,
- Egzaminy wewnętrzne,
- Ustawienia,
- Historia zakupów.

Status modułów: `docs/71-admin-osk-module-mapping-status.md`.

Moduły marketingowe (`Moje wizytówki`, `Moje reklamy`, `Wykłady`, `Szkolenie z instruktorem`) nie blokują core v1.

## Najważniejszy plik przed implementacją

**`specs/implementation-baseline-v1.yml`** jest aktywnym baseline implementacyjnym.

Jeżeli starszy dokument lub agregat przeczy nowszemu screen/legal/design spec, obowiązuje kolejność opisana w `AGENTS.md` i baseline.

Trwa konsolidacja developerska dokumentacji: `docs/81-developer-consolidation-plan.md`.

## Struktura repo

### `specs/legal/`
Reguły formalne/prawne zweryfikowane dla własnego produktu, m.in.:
- ewidencja kursanta,
- 45 min teorii / 60 min praktyki,
- zwolnienia z teorii,
- wymagania egzaminu wewnętrznego.

### `specs/design/`
Jawne decyzje naszego produktu w miejscach, gdzie nie kopiujemy nieobserwowalnego zachowania konkurenta, np.:
- lifecycle egzaminu,
- student finance ledger.

### `specs/screens/`
Najbardziej szczegółowe machine-readable mapy bieżących ekranów audytowanego panelu.

### `docs/17-...` i późniejsze
Dokumenty screen-level i decyzje architektoniczne wynikające z audytu.

### `docs/01-16`
Starsze dokumenty ogólne i historyczny kontekst. Nie są nadrzędne wobec nowszych screen/legal/design specs.

## Zasady clean-room

Nie kopiujemy:
- kodu HTML/CSS/JS,
- backendu/API konkurenta,
- logo,
- grafik,
- treści marketingowych,
- layoutu pixel-perfect,
- chronionych assetów.

Mapujemy:
- usługi biznesowe,
- role i uprawnienia,
- ekrany i przepływy,
- pola/akcje/statusy,
- state machines,
- zależności między modułami,
- wymagania bezpieczeństwa i zgodności.

## Architektura docelowa

- Backend: Laravel,
- Frontend: Vue,
- DB: PostgreSQL,
- Cache/queue: Redis,
- Storage: S3-compatible,
- background jobs: Laravel Queue,
- frontend server-state: Vue Query/TanStack Query,
- frontend local state: Pinia.

Architektura domenowa musi pozostać niezależna od komponentów Vue.

## Fundamentalne inwarianty

- pełna tenant isolation po `organization_id`,
- permission-based RBAC egzekwowany na backendzie,
- krytyczne operacje audytowane,
- brak hard-delete historii formalnej/finansowej,
- PKK powiązane z konkretnym `course_enrollment`,
- godziny bieżącego OSK wynikają z ewidencji zajęć/ledgera,
- formalny egzamin wymaga trwałego kursanta i kursu,
- egzamin core v1 konsumuje sztukę przy rozpoczęciu zgodnie z `specs/design/internal-exam-lifecycle.yml`,
- cofnięcie nieaktywowanej licencji atomowo przywraca dokładnie jedną sztukę inventory,
- money nigdy jako float,
- zmienne języki/kategorie/ceny/parametry są konfiguracją, nie hard-code.

## Dokumenty startowe dla developera/Codex

1. `AGENTS.md`
2. `specs/implementation-baseline-v1.yml`
3. właściwy `specs/legal/*.yml`
4. właściwy `specs/design/*.yml`
5. właściwe `specs/screens/*.yml`
6. `docs/71-admin-osk-module-mapping-status.md`
7. `docs/05-domain-model.md`
8. `docs/06-api-contract.md`
9. `docs/07-architecture.md`
10. `docs/08-security-compliance.md`
11. `docs/13-acceptance-criteria.md`

## Dokumenty statusowe

- `docs/02-screen-inventory.md` — skonsolidowany inwentarz ekranów,
- `docs/10-gap-register.md` — aktualne realne luki, nie historyczna lista braków,
- `docs/71-admin-osk-module-mapping-status.md` — gotowość modułów,
- `docs/81-developer-consolidation-plan.md` — plan porządkowania repo.

## Ważne rozdzielenia domen

- `student` ≠ `learning account`,
- `course enrollment` ≠ licencja,
- `course enrollment` ≠ egzamin,
- `student finance` ≠ zakupy OSK na platformie,
- `license inventory` ≠ `license assignment` ≠ `license activation`,
- `exam inventory/reservation` ≠ `exam attempt`,
- typ pracownika ≠ permission,
- lokalizacja ≠ adres organizacji.

## Zasada dla dalszej pracy

Każda kolejna zmiana powinna aktualizować najbliższy właściwy spec (`legal`, `design`, `screen`) oraz baseline, jeżeli zmienia inwariant core. Nie dopisujemy nowych wymagań wyłącznie do starego dokumentu agregującego.
