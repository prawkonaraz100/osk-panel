# ADR-0003 — Training hour ledger jako source of truth

Status: Accepted  
Data: 2026-09-05

## Context

Audytowany formularz kursu pokazuje pola godzin teorii/praktyki, ale formalny system nie może utrzymywać niezależnego ręcznego agregatu i jednocześnie ewidencji zajęć, bo wartości się rozjadą.

## Decision

Dla bieżącego OSK source of truth jest:

`TrainingSession -> attendance -> TrainingHourLedgerEntry -> projection totals`.

Szkolenie uznane z innego OSK jest przechowywane w osobnym `RecognizedExternalTraining`.

Canonical storage czasu: minuty.

- teoria: 45 min / godzina szkoleniowa,
- praktyka: 60 min / godzina szkoleniowa.

## Consequences

- UI może pokazywać agregaty, ale nie zapisuje ich jako niezależnej prawdy,
- korekty są wpisami kompensującymi/audytowanymi,
- raporty i PKK pobierają totals z projekcji ledgeru + external recognition.

## Rejected alternatives

- ręczne `theory_hours_total` i `practice_hours_total` edytowane równolegle z sesjami,
- destrukcyjna edycja historycznego credited time.
