# ADR-0001 — Modular monolith jako architektura startowa

Status: Accepted  
Data: 2026-09-05

## Context

Core OSK ma silnie powiązane transakcje między kursantem, kursem, kalendarzem, licencjami, egzaminem, płatnościami i PKK. Zespół nie potrzebuje na starcie niezależnego skalowania kilkunastu usług.

## Decision

Budujemy backend jako **modular monolith w Laravelu** z jawnymi granicami bounded contexts.

Moduły komunikują się przez application services/domain events, a nie bezpośrednie odwołania do przypadkowych tabel z dowolnego miejsca kodu.

Outbox stosujemy dla krytycznych asynchronicznych efektów.

## Consequences

Plusy:
- prostsze transakcje,
- prostszy deployment,
- niższy koszt operacyjny,
- łatwiejsze testy integracyjne.

Koszt:
- trzeba pilnować granic modułów w review,
- wydzielenie usługi później wymaga dojrzałych kontraktów.

## Rejected alternatives

- mikroserwisy od pierwszego dnia — zbyt duży koszt i złożoność,
- jeden niepodzielony Laravel bez modułów — ryzyko spaghetti i trudnego wydzielenia domen.
