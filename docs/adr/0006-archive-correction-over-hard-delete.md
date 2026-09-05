# ADR-0006 — Archive/cancel/correction zamiast hard-delete historii formalnej

Status: Accepted  
Data: 2026-09-05

## Context

W panelu referencyjnym widoczne są akcje `Usuń`/`Archiwizuj`, ale dla formalnych i finansowych danych fizyczne usunięcie może zniszczyć historię kursu, PKK, egzaminów, wpłat i audytu.

## Decision

Canonical operacje:
- `archive` — zasób wyłączony z nowych użyć, historia zostaje,
- `cancel` — proces anulowany,
- `revoke` — cofnięcie przed nieodwracalnym użyciem,
- `reverse` — kompensacja finansowa/ledgerowa,
- `correct` — audytowalna korekta,
- `invalidate` — oznaczenie historycznego rekordu jako nieważnego,
- `hard_delete` — tylko techniczne/robocze rekordy bez obowiązku retencji i historycznych skutków.

Szczegóły: `docs/83-core-lifecycle-policy.md`, `specs/design/core-lifecycle.yml`.

## Consequences

- historyczne relacje nie znikają,
- przyszłe wydarzenia zależne od zarchiwizowanego zasobu wymagają jawnej decyzji,
- API używa command endpoints (`/archive`, `/cancel`, `/reverse`) zamiast mylącego DELETE dla formalnych zasobów.

## Rejected alternatives

- cascade delete formalnych danych,
- bezśladowa edycja wpłat/godzin/wyników egzaminu.
