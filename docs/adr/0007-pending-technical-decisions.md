# ADR-0007 — Pending technical decisions register

Status: Proposed  
Data: 2026-09-05

Ten ADR nie podejmuje decyzji. Zbiera elementy, które muszą zostać rozstrzygnięte przed odpowiednią implementacją.

## 1. Public IDs

Opcje:
- UUIDv7,
- ULID.

Kryteria:
- sortowalność czasowa,
- wsparcie Laravel/PostgreSQL,
- rozmiar indeksu,
- ergonomia debugowania,
- brak potrzeby używania ID jako security boundary.

## 2. Szyfrowanie PESEL/PKK

Do decyzji:
- application-level envelope encryption,
- KMS/secret manager,
- HMAC exact lookup,
- rotacja kluczy,
- re-encryption plan.

Nie używać zwykłego globalnego SHA-256 jako lookup hash dla PESEL.

## 3. Calendar overlap — RESOLVED

Rozstrzygnięte przez `ADR-0008 — Calendar resource conflict boundary`.

Finalna granica produkcyjna jest zgodna z zamkniętym Stage-4 authority:
PostgreSQL half-open `tstzrange` + `btree_gist` + partial GiST exclusion
constraints na tenant-scoped `calendar_resource_claims`.

Do czasu materializacji późniejszych faz `MIG-IDX-CALENDAR_GIST` Stage-5 może
używać wyłącznie jawnej warstwy przejściowej opisanej w ADR-0008. Nie wolno
traktować application check ani advisory locka jako zamiennika finalnej
fizycznej boundary ani oznaczać odpowiadających DBT jako wykonanych.

## 4. Snapshot canonicalization

Dla PDF/exam snapshots trzeba zdefiniować stabilny sposób hash/canonical JSON, aby późniejsza weryfikacja integralności była deterministyczna.

## 5. Audit retention/partitioning

Nie partycjonować przedwcześnie. Decyzja po danych o wolumenie i wymaganiach retencji.

## 6. Session auth mechanism

Do finalizacji przy bootstrapie:
- Laravel Sanctum cookie/session dla first-party SPA,
- ewentualny token dla osobnych klientów.

OpenAPI używa obecnie logicznego `sessionCookie`, a nie twardej nazwy framework cookie.
