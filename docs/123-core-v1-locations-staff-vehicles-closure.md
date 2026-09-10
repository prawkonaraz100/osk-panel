# 123. Core v1 — Locations + Staff + Vehicles closure

Data: 2026-09-11

**Slice:** `CORE-V1-LOC-STAFF-VEH-001`  
**Implementation machine:** PASS  
**Narrative payload:** READY  
**Central closure:** PENDING — ten commit jest payloadem closure i nie może sam ogłosić swojego wyniku przed własnym CI.

## 1. Zakres

Pierwszy regularny produktowy slice po Stage-5 foundation obejmuje wyłącznie:

- Locations,
- Staff,
- Vehicles,
- dependency-closed migracje fazy `expand` potrzebne temu zakresowi,
- backend/API/permissions,
- wykonywalne testy i DBT traceability,
- własny UI dla potwierdzonych ekranów.

Nie rozpoczęto Students, Course Enrollment, Calendar engine, Training Session/Hour Ledger ani późniejszych modułów.

## 2. Clean implementation provenance

Zwalidowany helper-free tree:

`fab3cd8369f2b07c35c3dce67408a37130da3d0a`

Clean accepted implementation commit:

`bac644cf46e64e63b149196c15f76f2efb683810`

Parent:

`2b35b92358fc64db54f27e56bb0b36a4d77a016f`

Helper history i tymczasowe formatter workflows nie weszły do accepted history.

## 3. Migracje

Materializacja wzrosła z 18/170 do **34/170** node'ów i phase steps.

Slice dodał 16 dependency-closed table nodes, m.in. słowniki zasobów, `file_assets`, `idempotency_records`, Locations, Staff i Vehicles wraz z relacjami oraz historią dokumentów.

Aktualny execution identity:

`6e39b3c71a7a2ad44bfb78513506b8a3e53cc4a5ff443a89b066d8b44917cc1f`

Nie materializowano zbiorczych FK/index/constraint phases przed globalną barierą migracyjną. `MIG-FK-RESOURCES` zależy także od późniejszych tabel domenowych, więc wcześniejsze stworzenie tych constraint phases łamałoby authority. Stage-4 170-node DAG i siedmiofazowa kolejność pozostają niezmienione.

## 4. Locations

Zaimplementowano tenant-scoped:

- list/get/create/update,
- archive i restore z zachowaniem historii,
- trzy potwierdzone typy lokalizacji,
- `organization` oraz `assigned_locations` read scope,
- fail-closed dla cross-tenant visibility i assignments,
- audit/domain-event/outbox dla mutacji.

Canonical zewnętrzny katalog miejscowości pozostaje jawną późniejszą decyzją. Slice nie udaje integracji, której authority jeszcze nie wybrano.

## 5. Staff

StaffProfile pozostaje odrębny od globalnej tożsamości i konta panelowego.

Zaimplementowano:

- dane profilu, typy, kategorie i lokalizacje,
- szyfrowanie PESEL at rest i HMAC lookup bez zapisu plaintextu,
- brak PESEL w audit payload,
- wersjonowane ważności legitymacji, badań lekarskich i psychologicznych,
- historyczne `staff_membership_links`,
- jawne tworzenie/odpinanie konta panelowego,
- osobne permission governance — staff type nie jest permission,
- archive non-owner: unlink + suspend membership + clear bound session tenant context,
- archive Ownera: unlink Staff bez ukrytej zmiany Owner governance,
- restore profilu bez automatycznego przywracania dostępu panelowego lub dawnych sesji.

## 6. Vehicles

Zaimplementowano:

- tenant-scoped fleet list/detail/create/update,
- kategorie i lokalizacje,
- archive/restore,
- historyczne zachowanie VIN,
- zwolnienie numeru rejestracyjnego dla bieżącej floty po archive,
- konflikt restore, jeśli numer został przejęty przez inny aktywny pojazd,
- niezależne ważności przeglądu technicznego, OC i AC,
- wersjonowane/superseded document history,
- prywatne asset references tylko dla tego samego tenantu, właściwego purpose i stanu `ready`.

## 7. API i UI

API zachowuje istniejący OpenAPI contract. Komendy wymagające `Idempotency-Key` mają rzeczywiste persistence/replay storage. Potwierdzone update flows obsługują ETag/`If-Match`.

UI obejmuje:

- listę i formularze Locations,
- listę, detail i formularze Staff,
- panel-account/permission actions,
- listę, detail i formularze Vehicles,
- ważności dokumentów,
- archive/restore,
- potwierdzone wejścia do kalendarza.

UploadsAssets transport i właściwy Calendar engine są jawnie deferred. Pole zdjęcia i wejścia kalendarzowe są zachowane, ale slice nie tworzy fikcyjnego uploadu ani fikcyjnych zdarzeń.

## 8. Executable DBT

Runtime catalog wzrósł z 13/491 do **36/491** wykonywalnych assertions.

Dodano 23 `DBT-RES` obejmujące m.in.:

- assigned-location scope bez linku Staff -> empty,
- Staff bez membership,
- same-tenant account linking,
- zakaz linku dla archived Staff,
- wielokrotne kategorie/lokalizacje,
- cross-tenant assignment rejection,
- private asset tenant/purpose/ready checks,
- pojedynczy current version dokumentu,
- PESEL/VIN history po archive,
- registration reuse i restore conflict,
- Owner/non-Owner Staff archive semantics,
- restore bez implicit panel access,
- document supersession history.

Pozostałe **455/491** są nadal `pending_domain_materialization`. Fizyczne resource constraint DBT nie są fałszywie oznaczone jako wykonane przed materializacją odpowiedniej fazy constraintów.

## 9. Machine evidence

Helper-free PR validation run `34540278440`:

- backend-quality — PASS,
- frontend-quality — PASS,
- runtime-tests-and-migrations — PASS,
- contracts-and-traceability — PASS,
- PR secret-scan — infrastrukturalny fail przed skanem: `Resource not accessible by integration`.

Accepted implementation commit `bac644cf...`:

- Implementation CI run `34540533039` — **5/5 SUCCESS**,
- API Contract Gate run `34540533016` — **SUCCESS**,
- PostgreSQL suite — **58 tests / 1290 assertions PASS**,
- accepted push secret-scan — **PASS**.

To potwierdza, że PR-only secret-scan failure nie był findingiem bezpieczeństwa.

## 10. Jawnie odroczone zależności

Nie są częścią PASS tego slice:

- późniejsze FK/index/constraint phases blokowane globalną fazowością migracji,
- UploadsAssets transport pipeline,
- Calendar event/availability/conflict engine,
- wybór canonical external locality directory.

Są to jawne granice kolejnych slice'ów, a nie ukryte braki oznaczone jako wykonane.

## 11. Narrative result

Implementation machine = **PASS**.

Ten dokument i odpowiadająca mu sekcja w `stage-5-implementation-gate.yml` są closure candidate. Finalne `CORE-V1-LOC-STAFF-VEH-001 = PASS` wolno ustawić dopiero po 5/5 SUCCESS na accepted branch dla tego closure payload.

**STOP przed Students + Course Enrollment.**
