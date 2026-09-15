# OSK Panel — Core V1 application and clean-room specification

Repozytorium zawiera działającą aplikację Laravel + Vue oraz jej canonical specs,
testy, migracje i production-readiness tooling dla własnego panelu administracyjnego
OSK PrawkoNaRaz.

Projekt pozostaje clean-room: mapujemy klasę procesów biznesowych i wymagania,
ale nie kopiujemy kodu, layoutu, assetów ani chronionej implementacji innych produktów.

## Aktualny stan — 2026-09-15

**Core V1 repository jest implementacyjnie zamknięty.**

Runtime authority po ostatnim zaakceptowanym gate:

`d6b2089903ac830581b8606914cd484f1207dee2`

Accepted Implementation CI #665: **6/6 PASS**.

- backend-quality: PASS,
- frontend-quality: PASS,
- contracts-and-traceability: PASS,
- secret-scan: PASS,
- PostgreSQL runtime + migrations: PASS,
- immutable release artifact: PASS,
- runtime suite: **380 tests / 6299 assertions**,
- deterministic restore: **122 -> 122 PASS**.

Repository-owned production substrate jest kompletny, ale **produkcja nie jest jeszcze
udowodniona jako gotowa**. Realny go-live pozostaje:

`GO_LIVE_STATUS=BLOCKED_EXTERNAL_EVIDENCE`

Target evidence jest śledzone w GitHub issue #106 i wymaga rzeczywistego środowiska
produkcyjnego, backup/PITR, object restore, secrets injection, monitoringu, paging,
scheduler proof i release smoke.

PKK/PWPW pozostaje:

`FROZEN_UNTIL_EXPLICIT_UNFREEZE`

i **nie jest wymagane do uruchomienia Core service**.

Aktualne luki produktowe po audycie kodu nie dotyczą już modelu domenowego ani
podstawowego backendu.

Ukończone w productization:
- **AUTH-RECOVERY-UI-001** — login, forgot-password i reset-password są częścią accepted runtime od `d6b2089903ac830581b8606914cd484f1207dee2`; PR #111, accepted CI #665 = 6/6 PASS.

Pozostałe luki produktowe:
- UI ustawień organizacji,
- UI zakupu licencji,
- UI zakupu egzaminów wewnętrznych,
- browser E2E dla głównych golden paths,
- realny deployment i external production evidence.

Bieżący status authority: **`docs/227-current-project-status-authority.md`**.

## Authority dla dalszej pracy

1. `AGENTS.md`
2. `docs/227-current-project-status-authority.md` — bieżący status wykonania
3. `specs/current-project-status.yml` — machine-readable status
4. `specs/implementation-baseline-v1.yml` — canonical zakres/inwarianty Core V1
5. właściwe `specs/legal/*.yml`, `specs/design/*.yml`, `specs/screens/*.yml`
6. nowsze closure/authority docs dla konkretnego gate

Dokumenty takie jak `docs/10-gap-register.md`, `docs/71-admin-osk-module-mapping-status.md`
oraz historyczne sekcje closure w baseline zachowują kontekst decyzji z wcześniejszych
etapów. Nie są bieżącą listą otwartych P0/P1.

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
- obecny frontend runtime: Vue 3 + cienka warstwa `fetch`/API,
- Vue Query/TanStack Query i Pinia pozostają opcjonalnym kierunkiem ewolucji, a nie aktualnie zainstalowanym wymaganiem.

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
