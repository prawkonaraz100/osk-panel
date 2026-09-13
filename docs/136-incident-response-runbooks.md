# 136. Incident response runbooks and ownership

Data: 2026-09-13

Status: **PRE-PRODUCTION AUTHORITY**

## 1. Zasada

Incydent ma jednego Incident Commandera. Role są wersjonowane w repo, ale prywatne dane kontaktowe i paging destinations są dostarczane dopiero przez deployment.

Role:
- Incident Commander — decyzje, severity, priorytety, cadence i zamknięcie incydentu,
- Technical Lead — diagnoza i zmiany techniczne,
- Privacy Lead — breach assessment i obowiązki RODO; IOD, jeżeli został wyznaczony, jest włączany zgodnie z rolą i obowiązkami,
- Business Liaison — wpływ na OSK/kursantów i decyzje operacyjne,
- Communications Lead — komunikacja do użytkowników/partnerów, gdy potrzebna,
- Scribe — timeline, evidence i decyzje.

Nie wpisujemy nazwisk, telefonów, prywatnych e-maili ani tokenów pagingowych do Git.

## 2. Severity

### SEV1
Ack <= 10 min, Incident Commander <= 15 min, status cadence <= 30 min.

Przykłady: aktywne naruszenie bezpieczeństwa, niedostępność/uszkodzenie tier-0 authority, masowy problem integralności egzaminów lub płatności, destrukcyjna operacja dotykająca formalnych danych.

### SEV2
Ack <= 30 min, IC <= 30 min, status cadence <= 60 min.

Znaczna degradacja z bezpiecznym obejściem albo ograniczone ryzyko integralności bez potwierdzonej utraty danych.

### SEV3
Ack <= 240 min. Ograniczona awaria niekrytyczna bez ryzyka integralności.

Severity może zostać podniesione w dowolnej chwili. Obniżenie wymaga zapisania uzasadnienia w timeline.

## 3. Wspólny flow

Dla SEV1/SEV2:
1. utwórz incident ID i czas awareness,
2. przypisz Incident Commandera i Scribe,
3. określ wpływ: tenant, moduł, dane, liczba użytkowników,
4. zabezpiecz dowody i correlation IDs,
5. containment przed naprawą kosmetyczną,
6. nie zmieniaj formalnych/finansowych faktów bez authority,
7. komunikuj status według cadence,
8. po stabilizacji wykonaj reconciliation,
9. zamknij dopiero po exit criteria,
10. przeprowadź post-incident review z corrective actions.

## 4. Runbook: database_or_data_integrity

Pierwsze działania:
- rozróżnij availability od integrity,
- przy podejrzeniu korupcji ogranicz writes zanim zaczniesz repair,
- zachowaj recovery point, logi i evidence,
- użyj recovery authority z docs/134 i docs/135,
- restore zawsze do izolowanego targetu przed decyzją o produkcyjnym przełączeniu.

Nigdy:
- restore over source DB,
- blind destructive migration rollback,
- ręczne przepisywanie formalnej historii, żeby „pasowała”.

Exit: durable authority jest spójne, reconciliation zakończone, a monitoring nie pokazuje dalszej degradacji.

## 5. Runbook: object_storage

- zatrzymaj masowe writes/deletes, jeżeli mogą pogłębić utratę,
- zachowaj VersionId/evidence,
- zweryfikuj spójność DB metadata ↔ object,
- odtwórz pojedynczy obiekt lub scope z wersji/backupów,
- formalny brak pliku nie może być maskowany pustym placeholderem.

## 6. Runbook: redis_or_queue

Redis nie jest business authority.

- zdiagnozuj cache vs queue impact,
- można reprovisionować pusty Redis,
- po utracie kolejki uruchom durable outbox/reconciliation,
- nie oznaczaj operacji jako udanej tylko dlatego, że job zniknął,
- sprawdź duplicate-safe/idempotent replay.

## 7. Runbook: payment_or_reconciliation

- jeżeli confirmation path jest niepewny, wstrzymaj nowe ryzykowne provider attempts,
- browser return ani lokalny pending nie oznacza paid,
- nie twórz ręcznie provider confirmation,
- zestaw lokalne immutable attempts/events z autorytatywnym provider state po odzyskaniu łączności,
- różnice rozwiązuj reconciliation z audytem.

## 8. Runbook: internal_exam

- przy ryzyku integralności wstrzymaj nowe starty, nie kasując istniejących faktów,
- zachowaj canonical attempt/result/inventory ledger,
- station failover nie może podwójnie zużyć inventory,
- transport failure nie tworzy wyniku,
- nigdy nie wpisuj ręcznie fikcyjnego PASS, aby ominąć awarię.

## 9. Runbook: security_or_credentials

- ogranicz blast radius,
- revoke/rotate dotknięte credentiale i sesje,
- zachowaj logi i evidence przed cleanup,
- ustal, czy doszło do dostępu do danych osobowych,
- jeżeli tak lub nie można tego wykluczyć, uruchom równolegle personal_data_breach assessment,
- uruchom legal/incident hold dla dowodów.

## 10. Runbook: personal_data_breach

Każde naruszenie ochrony danych dokumentujemy, niezależnie od tego, czy finalnie wymaga zgłoszenia.

Processor zgłasza naruszenie administratorowi bez zbędnej zwłoki. Administrator ocenia prawdopodobieństwo i wagę ryzyka dla praw lub wolności osób.

Nie ma automatycznej zasady „każdy incydent zgłaszamy UODO”. Jeżeli naruszenie prawdopodobnie powoduje ryzyko, administrator zgłasza je organowi bez zbędnej zwłoki i — gdy jest to wykonalne — nie później niż 72 godziny od stwierdzenia. Opóźnienie wymaga uzasadnienia; brakujące informacje można uzupełniać etapami.

Jeżeli prawdopodobne jest wysokie ryzyko, osoby, których dane dotyczą, zawiadamiamy bez zbędnej zwłoki, z uwzględnieniem wyjątków przewidzianych w art. 34 RODO.

Minimalny incident record:
- awareness timestamp,
- facts and detection source,
- affected systems,
- categories and approximate volume of data,
- approximate number/categories of people,
- confidentiality/integrity/availability impact,
- mitigations,
- risk assessment and rationale,
- regulator decision and timestamps,
- data-subject notification decision and timestamps,
- corrective actions.

Official authority checked 2026-09-13:
- UODO: https://uodo.gov.pl/pl/525/2584
- UODO: https://uodo.gov.pl/pl/525/2581
- GDPR art. 33–34: https://eur-lex.europa.eu/eli/reg/2016/679/oj

## 11. Runbook: deployment_or_destructive_operation

- zatrzymaj rollout,
- ustal, czy problem jest kodem czy zmianą danych,
- aplikację rollbackuj tylko do kompatybilnego artefaktu,
- migracji destrukcyjnej nie rollbackuj w ciemno,
- przy zmianie danych użyj restore/reconciliation authority,
- każda ręczna korekta danych formalnych wymaga jawnego, audytowanego maintenance path.

## 12. Runbook: PKK_provider_deferred_boundary

PKK provider runtime jest obecnie odroczony.

Do czasu authoritative PWPW guidance:
- nie wykonujemy provider-specific calls,
- nie wymyślamy provider statusów,
- nie uznajemy braku provider connectivity za awarię aktywnego runtime.

Po przyszłym uruchomieniu integracji runbook musi zostać uzupełniony na podstawie rzeczywistej umowy/API i reconciliation contract.

## 13. Produkcyjny contact roster

Repo definiuje role, ale nie dowodzi reachability.

Przed go-live wymagane są:
- primary on-call reference,
- technical lead reference,
- privacy lead reference,
- business liaison reference,
- communications lead reference,
- paging channel reference,
- smoke test potwierdzający, że paging dochodzi do człowieka.

Dopóki te referencje nie są skonfigurowane i przetestowane, incident operational readiness pozostaje P1.

## 14. Closure evidence

Hardening H6 is closed on accepted implementation commit:

- accepted commit: `a15810b54cdc81876affddc8fe47f67dee5e15cf`,
- accepted tree: `412a1d7c37a927824135ae677f430d301d3c861c`,
- validation helper: `cd945fdd76d78291f2e8f6c9eb8be5be4e2e6d29`,
- helper Implementation CI #269: **5/5 PASS**,
- accepted Implementation CI #270: **5/5 PASS**,
- accepted PostgreSQL suite: **181 tests / 2729 assertions**,
- deterministic restore harness: **PASS**,
- backend Pint + PHPStan: **PASS**,
- frontend lint + typecheck + build + audit: **PASS**,
- contracts and traceability: **PASS**,
- secret scan: **PASS**.

The repository now contains incident severity, role ownership, nine runbooks and the conditional GDPR breach decision contract. It intentionally does not contain private contact details and does not claim that a production paging channel reaches a human.

Production contact roster + paging smoke test remains P1 deployment evidence.

Next repository-actionable hardening gate: **provider-neutral reconciliation jobs and evidence**.

