# 121. Stage 5 — implementation CI contract

Data: 2026-09-10

**Blocker:** `S5-CI-001`
**Narrative result:** `PASS`
**Machine clean commit:** `55b6d0af82ee018cb3c0a201e4d4e6a6358c6a06`
**Permanent accepted-branch CI run:** `34505180921`

---

## 1. Zakres

`S5-CI-001` zamyka brak stałej bramki jakości dla wykonywalnej implementacji. Nie implementuje domenowych feature'ów ani UI. Jego rolą jest zatrzymanie zmian, które nie przechodzą backendowego lint/static analysis, frontendowego lint/typecheck/build, rzeczywistego PostgreSQL test suite, migration validation, OpenAPI contract validation, secret scan lub changed-module traceability.

Stały workflow znajduje się w `.github/workflows/implementation-ci.yml`, uruchamia się dla pull requestów oraz pushy na accepted branch `docs-consolidation-2026-09-05` i działa z `permissions: contents: read`.

## 2. Backend quality

Job `backend-quality` wykonuje na PHP 8.5:

- `composer install --no-interaction --prefer-dist --no-progress`,
- `composer validate --strict`,
- `vendor/bin/pint --test`,
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.

Static analysis używa Larastan `3.12.0` i PHPStan `2.2.13`. `phpstan.neon.dist` analizuje `app` i `routes` na poziomie `8`. Nie dodano baseline'u, ignored errors ani suppressions służących do przepuszczenia istniejącego długu.

Podczas machine validation Larastan ujawnił 18 rzeczywistych problemów statycznego typowania w istniejącej warstwie migracyjnej i command wrapperze. Zostały poprawione jawnie; finalny gate przeszedł z zerem błędów zamiast wyciszenia reguł. Mechaniczne różnice Pint nie zmieniają kontraktów domenowych ani Stage-4 migration authority.

## 3. Frontend quality

Job `frontend-quality` używa Node przypiętego przez `.nvmrc` i wykonuje:

- `npm ci`,
- `npm run lint`,
- `npm run typecheck`,
- `npm run build`,
- `npm audit --audit-level=high`.

Manifest przypina ESLint `10.10.0`, `@eslint/js` `10.0.1`, `eslint-plugin-vue` `10.11.0`, `typescript-eslint` `8.70.0`, TypeScript `6.0.3`, Vue `3.5.42`, Vite `8.3.0` i `vue-tsc` `3.3.11`. Lint obejmuje `resources/js` i działa z `--max-warnings=0`.

Finalny accepted-branch run przeszedł lint, typecheck, production build i audit bez błędu.

## 4. PostgreSQL tests i migration validation

Job `runtime-tests-and-migrations` korzysta z tego samego deterministycznego runtime'u ustanowionego przez `S5-ENV-001`. Na runnerze tworzy efemeryczny `.env`, generuje testowy application key, renderuje `docker compose config`, uruchamia usługi z `docker compose up -d --wait`, a następnie wykonuje:

- `php artisan migration:plan:validate --json`,
- `php artisan test --fail-on-warning`.

Oznacza to, że implementation CI nie zastępuje PostgreSQL przez SQLite ani application-only mock. Migration validation nadal sprawdza registry związany z immutable 170-node Stage-4 authority, a framework suite działa na rzeczywistych usługach testowych. Cleanup `docker compose down -v` jest wykonywany również po błędzie.

## 5. OpenAPI i changed-module traceability

Job `contracts-and-traceability` zachowuje istniejący OpenAPI contract gate przez uruchomienie `scripts/contract-validation/validate_openapi_contract.py`; nowy CI nie zastępuje ani nie osłabia wcześniejszej bramki kontraktu API.

Dodatkowo `scripts/ci/validate_changed_module_traceability.py` działa fail-closed i ma własny self-test. Zmiana implementacyjna pod `app/Modules/<Module>/` albo `resources/js/modules/<Module>/` musi mieć odpowiadającą zmianę traceability w tym samym diffie. Mechanizm ma uniemożliwić przyszłym slice'om wejście bez powiązania implementacji z evidence/requirements/domain/API/permissions/UI/tests.

## 6. Secret scan

Job `secret-scan` używa `gitleaks/gitleaks-action@v3` na pełnej historii potrzebnej do diff scan. Komentarze i upload artefaktów są wyłączone; workflow używa wyłącznie standardowego `GITHUB_TOKEN`. Nie dodano produkcyjnych sekretów ani realnych danych osobowych.

## 7. Machine validation i accepted-branch proof

Machine layer został zbudowany na helperze i dopiero po pełnym PASS skompresowany do jednego clean commita z parentem dokładnie na wcześniejszym accepted tipie. Helper workflow/history nie weszły do accepted branch.

Finalny helper validation run `34504826574` zakończył się SUCCESS. Następnie clean machine commit `55b6d0af82ee018cb3c0a201e4d4e6a6358c6a06` uruchomił już stały `Implementation CI` bezpośrednio na accepted branch.

Permanent run `34505180921` zakończył się **5/5 SUCCESS**:

- `backend-quality` — SUCCESS,
- `frontend-quality` — SUCCESS,
- `runtime-tests-and-migrations` — SUCCESS,
- `contracts-and-traceability` — SUCCESS,
- `secret-scan` — SUCCESS.

To jest closure evidence dla `S5-CI-001`: nie tylko tymczasowy helper, lecz docelowy workflow na accepted branch przeszedł cały kontrakt.

## 8. Preservation

`S5-CI-001` nie zmienia finalnego Stage-4 migration/test authority. Nadal obowiązują:

- final matrix blob `ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441`,
- 170-node executable migration DAG,
- siedem faz `expand -> preflight -> write_fence -> backfill -> reconcile -> validate -> contract`,
- 491 finalnych `DBT-*`,
- 77/77 zamkniętych Stage-4 blockerów,
- zero coverage gaps.

Nie rozpoczęto `S5-FOUND-001`, domenowych ekranów ani szerokiej implementacji feature'ów. Migration executor został jedynie utwardzony statycznie na błędy ujawnione przez nową bramkę jakości; jego Stage-4 plan/order/restart/cutover authority nie został przepisany.

## 9. Wynik

Narrative gate: **S5-CI-001 PASS**.

Po centralnym zamknięciu jedynym pozostałym P1 w entry/foundation tranche ma być `S5-FOUND-001 — first core foundation slice`.

**STOP przed S5-FOUND-001.**
