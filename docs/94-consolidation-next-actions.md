# 94. Consolidation — next actions

Data: 2026-09-05

Po dotychczasowych naprawach kolejne decyzje są już węższe i nie dotyczą podstawowego screen mappingu.

## Remaining technical decisions

1. zweryfikować legal category dictionary (`PT` / tram mapping i pełna tabela kategorii),
2. podjąć ADR: UUIDv7 vs ULID,
3. podjąć ADR: application-level encryption / HMAC lookup key rotation,
4. podjąć ADR: calendar conflict enforcement,
5. zdefiniować privacy/retention schedule,
6. uzupełniać OpenAPI per moduł równolegle z implementacją,
7. po review scalić branch konsolidacyjny do `main`.

## Rule

Nie wracamy już do broad competitor audit core, chyba że podczas implementacji wyjdzie realna luka biznesowa. Core jest wystarczająco zmapowany; teraz priorytetem jest własna poprawna architektura.
