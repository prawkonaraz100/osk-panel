# 85. Production operations — core OSK v1

Data: 2026-09-05

**Status:** `ACTIVE_POLICY_REPOSITORY_SUBSTRATE_COMPLETE_TARGET_EVIDENCE_PENDING`

> Stan wykonania 2026-09-15: repository-owned runtime/preflight/restore/alerting/
> evidence tooling jest zaimplementowane i przechodzi accepted CI. To nie jest
> równoznaczne z produkcyjnym go-live. Realny target nadal musi dostarczyć dziewięć
> klas external evidence śledzonych w #106. `production_ready=false` pozostaje
> obowiązującą prawdą do czasu pełnego target evidence PASS.

## 1. Environmenty

- local,
- CI/test,
- staging,
- production.

Każde środowisko ma osobne:
- DB,
- Redis,
- object storage bucket/prefix,
- secrets,
- provider credentials,
- mail configuration.

Nie używać produkcyjnych danych osobowych w staging bez jawnej podstawy i procesu anonimizacji.

## 2. Secrets

Sekrety:
- nigdy w repo,
- nigdy w screenshotach/logach,
- dostarczane przez secret manager / bezpieczne environment injection,
- rotowalne bez rebuild całej aplikacji, jeśli provider na to pozwala.

Minimum:
- app key,
- DB credentials,
- Redis credentials,
- S3 keys,
- payment keys/webhook secrets,
- PKK credentials,
- mail/SMS credentials,
- OAuth client secrets.

## 3. CI

Pipeline PR:
1. install dependencies,
2. lint/format check,
3. static analysis,
4. unit tests,
5. integration tests,
6. migration dry-run/test DB,
7. API contract tests,
8. secret scan,
9. build frontend/backend artifacts.

## 4. CD

Production deployment:
- immutable build artifact/container,
- tagged release,
- controlled DB migration,
- health/readiness check,
- progressive rollout lub szybki rollback aplikacji,
- migration rollback tylko jeśli jawnie bezpieczny.

## 5. Migration policy

Preferowany expand/contract:

1. dodaj nową strukturę kompatybilnie,
2. deploy kod czytający/stosujący nową strukturę,
3. backfill/reconciliation,
4. przełącz source of truth,
5. usuń stare pole dopiero w osobnym release.

Nie robić w jednym deployu destrukcyjnego rename/drop dla krytycznych danych formalnych.

## 6. Backup

Authority:
- docs/134-disaster-recovery-authority.md,
- specs/operations/disaster-recovery.yml,
- config/recovery.php.

PostgreSQL jest źródłem prawdy dla krytycznego stanu transakcyjnego. Produkcja musi zapewnić:
- ciągły mechanizm pozwalający osiągnąć RPO <= 5 minut dla tier-0,
- szyfrowane backupy bazowe,
- point-in-time restore do server-derived recovery point,
- backup oddzielony administracyjnie od podstawowej instancji,
- retencję backupów zgodną z privacy/retention authority,
- brak polegania na Redis jako kopii danych biznesowych.

Object storage dla formalnych załączników musi zapewnić:
- versioning albo równoważną ochronę przed przypadkowym nadpisaniem/usunięciem,
- mechanizm osiągający RPO <= 60 minut,
- restore pojedynczego obiektu i wybranego zakresu,
- niezależny lifecycle od rekordów tymczasowych.

## 7. Restore test

Backup bez testu restore nie jest wystarczający.

Przed go-live wymagany jest udokumentowany drill:
- PostgreSQL restore do izolowanego środowiska,
- restore reprezentatywnego formalnego obiektu,
- weryfikacja spójności krytycznych rekordów,
- pomiar osiągniętego RPO i RTO,
- potwierdzenie, że Redis/cache nie jest potrzebny do odtworzenia business authority,
- raport z timestampami, recovery point, result i corrective actions.

Po go-live drill minimum kwartalny oraz po istotnej zmianie architektury backupu.

Repo zawiera dodatkowo CI restore-drill harness opisany w docs/135-restore-drill-harness.md. Harness uruchamia realny pg_dump/pg_restore, S3-version restore w Moto i restart z pustym Redis. Jest dowodem poprawności procedury i kontraktu aplikacji, ale nie zastępuje target-infrastructure drill dla produkcyjnego PITR, off-site copy i docelowego object storage.

## 8. RPO / RTO

Core-v1 recovery targets są wersjonowane w config/recovery.php.

Tier 0 — PostgreSQL business authority:
- RPO: <= 5 minut,
- RTO: <= 60 minut.

Obejmuje co najmniej formalne kursy/godziny, egzaminy, inventory licencji/egzaminów, finanse, zamówienia/płatności, audyt i durable domain events.

Tier 1 — formalne object assets:
- RPO: <= 60 minut,
- RTO: <= 240 minut.

Tier 2 — rebuildable projections:
- RPO: nie jest liczone jako utrata business authority, jeśli projekcja może być deterministycznie odtworzona z zachowanego źródła,
- RTO: <= 240 minut.

Redis/cache:
- nie jest durable authority,
- po awarii może zostać odtworzony pusty,
- żadna formalna operacja nie może wymagać odzyskania stanu wyłącznie z Redis.

Cele mogą być zaostrzone przez biznes przed go-live. Ich rozluźnienie wymaga jawnej zmiany authority i ponownego restore drill.

## 9. Observability

### Logs
Structured JSON, pola:
- timestamp,
- level,
- service/module,
- request_id,
- user_id jeśli dozwolone,
- organization_id,
- route/action,
- error_code,
- duration.

Nie logować:
- plaintext password,
- pełnego PESEL jeśli niepotrzebny,
- sekretów providerów,
- pełnych tokenów exam/auth.

### Metrics
Minimum:
- HTTP latency/error rate,
- DB latency/connections,
- Redis/queue depth,
- failed jobs,
- webhook error rate,
- PKK success/error/timeout rate,
- exam start/submit error rate,
- license inventory mutation failures,
- calendar conflict rate,
- PDF generation failures.

### Traces/correlation
Request/job/integration chain ma ten sam correlation context, jeśli technicznie możliwe.

## 10. Alerting

Critical alerts:
- payment webhook failing,
- PKK integration widespread failures,
- queue backlog growing,
- DB unavailable/high error,
- object storage unavailable,
- exam start/submit elevated failures,
- backup failure,
- restore/reconciliation failure.

Nie alertować na pojedynczy biznesowy validation error.

## 11. Queue failure handling

Każdy async job:
- timeout,
- max attempts,
- exponential/backoff policy,
- idempotency,
- failed-job/DLQ visibility,
- manual replay tooling z audytem dla krytycznych jobs.

## 12. Reconciliation jobs

Authority:
- docs/137-provider-neutral-reconciliation.md,
- specs/operations/reconciliation.yml,
- config/reconciliation.php,
- app/Support/Operations/CoreReconciliationScanner.php.

Core-v1 ma read-only reconciliation scanner uruchamiany przez `operations:reconciliation:scan`. Skan obejmuje:
- platform commerce settlement/fulfillment,
- purchase-to-license grant cardinality i license inventory/assignment consistency,
- purchase-to-internal-exam grant cardinality oraz exam ledger/unit/reservation consistency,
- outbox `requires_reconciliation`, stale lease i state-matrix violations.

Scheduler rejestruje skan co 15 minut z `--fail-on-findings`. Sam scanner:
- nie zmienia business state,
- nie oznacza płatności jako potwierdzonej,
- nie fabricuje settlement/grant/reservation,
- nie oznacza outbox jako published,
- nie wykonuje remote provider truth lookup.

PKK jest **zamrożone do odwołania** i wyłączone z H7. Nie tworzymy PKK reconciliation ani provider-specific zachowania do czasu jawnego odmrożenia po authoritative PWPW guidance.

Każda przyszła naprawa findingu musi przejść przez osobny, audytowany maintenance/business path. Produkcja nadal musi dowieść, że scheduler faktycznie działa i że finding failure trafia do operatora.

## 13. Health endpoints

- `/health/live` — proces żyje,
- `/health/ready` — zależności wymagane do obsługi ruchu są gotowe.

Readiness może sprawdzać m.in. DB/Redis, ale nie powinien blokować całej aplikacji tylko dlatego, że opcjonalny provider marketingowy nie działa.

## 14. Incident response

Authority:
- docs/136-incident-response-runbooks.md,
- specs/operations/incident-response.yml,
- runtime-readable config/incident_response.php.

Severity i ownership są przypisane do ról, nie do nazwisk w repo. Każdy SEV1/SEV2 musi mieć Incident Commander, Technical Lead, scribe/timeline i odpowiedniego właściciela privacy/business/communications.

Minimalny zestaw runbooków:
- database_or_data_integrity,
- object_storage,
- redis_or_queue,
- payment_or_reconciliation,
- internal_exam,
- security_or_credentials,
- personal_data_breach,
- deployment_or_destructive_operation,
- PKK_provider_deferred_boundary.

Każdy krytyczny incydent kończy się:
- timeline,
- impact,
- root cause,
- corrective actions,
- regression test/monitoring improvement, jeśli dotyczy,
- jawne wskazanie czy wymagany jest legal/privacy follow-up.

Repo nie przechowuje prywatnych danych dyżurnych. Przed produkcją deployment musi dostarczyć contact references i przejść paging smoke test.

## 15. Data retention

Authority:
- docs/133-privacy-retention-schedule.md,
- specs/privacy/retention-schedule.yml,
- runtime-readable config/retention.php.

Retention jest per typ danych i rozróżnia:
- ustawowy okres formalnej dokumentacji OSK,
- ustawowy okres dokumentacji finansowej,
- purpose-based retention danych osobowych,
- techniczny TTL dla sesji, idempotency, outbox i temporary assets,
- osobne activity/notification/audit/security retention,
- legal hold / incident hold jako nadrzędny blokator purge.

Nie implementować globalnego delete everything after X days.

Normalna rola aplikacyjna nie może wykonywać retencji. Automatyczny executor wymaga osobnego privileged path, jawnej wersji polityki, server-derived cutoff, dry-run/candidate count i immutable execution evidence. Pending/reconciliation outbox oraz provider-specific PKK raw payload nie mogą być automatycznie usuwane lub nawet zbierane bez osobnego authority.

## 16. Security operations

- dependency updates,
- vulnerability scanning,
- secret rotation,
- admin MFA/re-auth policy,
- session revocation,
- access review dla elevated permissions,
- periodic audit of stale staff accounts.

## 17. Release checklist

Przed production release:
- migrations reviewed,
- backup healthy,
- rollback strategy known,
- feature flags/defaults checked,
- legal/config rule version checked,
- payment/PKK credentials target environment checked,
- smoke tests passed,
- observability dashboards/alerts active.

## 18. Go-live blockers

Nie uruchamiamy produkcji bez:
- backup + restore test,
- monitoring i alerting,
- tenant isolation test suite,
- payment reconciliation,
- audit logs dla krytycznych mutacji,
- incident ownership/runbook,
- privacy/retention policy,
- secrets poza repo.
