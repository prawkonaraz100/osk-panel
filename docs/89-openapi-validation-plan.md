# 89. OpenAPI validation plan

Data: 2026-09-05

## Cel

`specs/api/openapi-v1.yaml` ma być kontraktem współdzielonym przez backend, frontend i testy, a nie statycznym dokumentem, który szybko się rozjeżdża.

## CI

Po bootstrapie aplikacji pipeline powinien:
1. walidować składnię OpenAPI 3.1,
2. sprawdzać unikalność operationId po ich dodaniu,
3. generować/aktualizować typed client dla Vue albo przynajmniej typy DTO,
4. uruchamiać contract tests na wybranych endpointach,
5. failować PR przy breaking change bez jawnej wersji/zgody.

## Reguły zmian

- nowy endpoint -> najpierw lub razem z OpenAPI,
- zmiana request/response -> aktualizacja OpenAPI w tym samym PR,
- stable machine error codes są częścią kontraktu,
- dokument nie opisuje vendorowego API konkurenta.

## Breaking changes

Za breaking uznajemy m.in.:
- usunięcie pola required/endpointu,
- zmianę typu,
- zmianę semantyki status code,
- usunięcie obsługi istniejącego error code,
- zmianę momentu efektu biznesowego bez nowego ADR.

## Wersjonowanie

Core v1 utrzymuje `/api/v1`.

Drobne additive changes nie wymagają `/v2`.
Breaking changes po produkcji wymagają migration/deprecation strategy.

## Do wykonania przed implementacją każdego modułu

OpenAPI blueprint zawiera już główne ścieżki, ale developer przed kodowaniem modułu doprecyzowuje:
- complete request schema,
- complete response schema,
- auth/permission notes,
- error responses,
- examples dla krytycznych flow.
