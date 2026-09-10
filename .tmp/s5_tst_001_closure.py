from pathlib import Path


def replace_once(text: str, old: str, new: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"expected exactly one occurrence ({count} found): {old!r}")
    return text.replace(old, new, 1)


gate_path = Path("specs/gates/stage-5-implementation-gate.yml")
gate = gate_path.read_text()

gate = replace_once(gate, "  status: FAIL_WITH_3_P1_BLOCKERS\n", "  status: FAIL_WITH_2_P1_BLOCKERS\n")
gate = replace_once(gate, "  executable_framework_test_suite_present: false\n", "  executable_framework_test_suite_present: true\n")
gate = replace_once(gate, "  conclusion: S5_MIG_001_pass_and_S5_TST_001_is_next\n", "  conclusion: S5_TST_001_pass_and_S5_CI_001_is_next\n")
gate = replace_once(
    gate,
    "  - id: S5-TST-001\n    severity: P1\n    status: OPEN\n",
    "  - id: S5-TST-001\n    severity: P1\n    status: PASS\n",
)
gate = replace_once(
    gate,
    "  fixes_applied_total: 3\n  open_P0_P1: 3\n  result: FAIL_WITH_3_P1_BLOCKERS\n",
    "  fixes_applied_total: 4\n  open_P0_P1: 2\n  result: FAIL_WITH_2_P1_BLOCKERS\n",
)

old_next = """next_single_step:
  id: S5-TST-001
  action: resolve_executable_PostgreSQL_backed_framework_test_harness_only_after_next_explicit_user_instruction
  prerequisite_S5_BOOT_001: PASS
  prerequisite_S5_ENV_001: PASS
  prerequisite_S5_MIG_001: PASS
  allowed_scope:
    - PostgreSQL_backed_test_runner_and_synthetic_factories
    - independent_database_connection_concurrency_primitives
    - DBT_ID_to_executable_test_traceability_mechanism
    - executable_migration_preflight_and_postcheck_test_classes
    - Stage_5_gate_and_audit_updates
  forbidden_scope:
    - marking_DB_TST_contracts_implemented_without_executable_assertions
    - domain_feature_UI
    - full_implementation_CI_until_S5_CI_001
  after_action: rerun_S5_TST_001_gate_and_STOP_before_S5_CI_001
"""
new_next = """next_single_step:
  id: S5-CI-001
  action: resolve_implementation_CI_gate_only_after_next_explicit_user_instruction
  prerequisite_S5_BOOT_001: PASS
  prerequisite_S5_ENV_001: PASS
  prerequisite_S5_MIG_001: PASS
  prerequisite_S5_TST_001: PASS
  allowed_scope:
    - implementation_CI_workflow
    - backend_lint_and_static_analysis
    - frontend_lint_typecheck_and_build
    - PostgreSQL_unit_integration_and_migration_validation
    - OpenAPI_contract_gate_preservation
    - secret_scan
    - changed_module_traceability
    - Stage_5_gate_and_audit_updates
  forbidden_scope:
    - domain_feature_UI
    - S5_FOUND_001_implementation
    - weakening_existing_contract_or_test_gates
  after_action: rerun_S5_CI_001_gate_and_STOP_before_S5_FOUND_001
"""
gate = replace_once(gate, old_next, new_next)

if "S5_TST_001_closure:" in gate:
    raise SystemExit("S5_TST_001_closure already exists")

closure = """
S5_TST_001_closure:
  status: PASS
  accepted_scope: PostgreSQL_backed_framework_test_harness_and_truthful_DBT_traceability
  authority_binding:
    final_matrix_blob: ad5f2aa2e14ef248b95dd3dda0d1cbcb2e69d441
    final_stage_4_test_IDs: 491
    catalog_class_counts:
      schema_constraint: 180
      transaction: 178
      security_storage: 57
      projection: 31
      concurrency: 29
      migration_preflight: 14
      migration_postcheck: 2
  harness:
    framework_runner_database: PostgreSQL
    SQLite_substitution_for_integration_tests: false
    synthetic_tenant_separated_fixture_factory: true
    real_personal_data_in_fixtures: false
    independent_PostgreSQL_connections: true
    distinct_backend_sessions_proven: true
    uncommitted_transaction_visibility_isolation_proven: true
    concurrency_advisory_lock_primitive_proven: true
    generic_fail_closed_migration_preflight_harness: PASS
  runtime_traceability:
    every_final_DBT_ID_has_runtime_state: true
    allowed_runtime_states: [implemented_executable_assertion, pending_domain_materialization]
    implemented_executable_assertions: 2
    implemented_test_IDs: [DBT-CORE-099, DBT-CORE-100]
    pending_domain_materialization: 489
    migration_preflight_catalog_IDs: 14
    migration_preflight_domain_IDs_implemented_here: 0
    migration_postcheck_catalog_IDs: 2
    migration_postcheck_IDs_implemented_here: 2
    implementation_registry_requires_existing_public_PHPUnit_method: true
    false_implemented_claims_allowed: false
  executable_evidence:
    phpunit_fail_on_warning: PASS
    suite_tests_passed: 19
    suite_assertions: 1096
    DBT_CORE_099_zero_gap_postcheck: PASS
    DBT_CORE_100_final_aggregate_postcheck: PASS
    composer_validate_strict: PASS
    PHP_syntax_checks: PASS
    Stage_4_authority_preservation: PASS
  intentionally_not_closed_here:
    full_491_domain_contract_implementation: false
    S5_CI_001: OPEN
    S5_FOUND_001: OPEN
"""

gate_path.write_text(gate.rstrip() + "\n\n" + closure.strip() + "\n")

audit_path = Path("docs/117-stage-5-implementation-entry-audit.md")
audit = audit_path.read_text()
audit = replace_once(audit, "**Aktualny krok:** `S5-BOOT-001`\n", "**Aktualny krok:** `S5-TST-001`\n")
audit = replace_once(audit, "**Status:** `S5-BOOT-001 PASS / 0 P0 / 5 P1 OPEN`\n", "**Status:** `S5-TST-001 PASS / 0 P0 / 2 P1 OPEN`\n")
if "## 13. S5-TST-001 — closure" in audit:
    raise SystemExit("S5-TST-001 audit closure already exists")
if "## 12. S5-MIG-001 — closure" not in audit:
    raise SystemExit("S5-MIG-001 audit closure prerequisite missing")

audit_closure = """
---

## 13. S5-TST-001 — closure

**PASS.** Utworzono wykonywalny framework test harness działający na rzeczywistym PostgreSQL. Harness ma syntetyczne, tenant-separated fixtures oraz niezależne połączenia PDO; testy potwierdzają różne backend PID, niewidoczność niezatwierdzonej transakcji w drugiej sesji i zachowanie advisory lock między połączeniami.

Finalny katalog Stage 4 jest parsowany bezpośrednio z immutable matrix blob `ad5f2aa2…` i zawiera dokładnie 491 unikalnych `DBT-*`. Traceability nie udaje implementacji domenowej: tylko `DBT-CORE-099` i `DBT-CORE-100` mają status `implemented_executable_assertion`, ponieważ wskazują istniejącą publiczną metodę PHPUnit z realnymi assertions. Pozostałe 489 mają `pending_domain_materialization`.

W katalogu jest 14 `migration_preflight` oraz 2 `migration_postcheck`. Wszystkie 14 domenowych preflightów pozostaje pending do materializacji ich DDL/slices; jednocześnie sam fail-closed preflight harness jest wykonany na syntetycznym przypadku unresolved legacy row. Oba postchecki Stage 4 są realnie wykonywane: `DBT-CORE-099` sprawdza `PASS_ZERO_GAPS`, a `DBT-CORE-100` immutable final aggregate authority.

Pełny przebieg na usługach S5-ENV-001 zakończył się **19 testów / 1096 assertions — PASS**, przy `--fail-on-warning`. Composer validation, PHP syntax oraz preservation Stage-4 authority również przeszły.

Nie utworzono domenowych modeli, endpointów, UI ani kolejnych migracji Stage 4. Pełny implementation CI pozostaje `S5-CI-001`, a core foundation `S5-FOUND-001`.

Następny pojedynczy krok: **S5-CI-001**.

**STOP przed S5-CI-001.**
"""
audit_path.write_text(audit.rstrip() + "\n\n" + audit_closure.strip() + "\n")

doc120 = Path("docs/120-stage-5-executable-test-harness.md")
text120 = doc120.read_text()
text120 = replace_once(text120, "Status: `S5-TST-001 PASS candidate`\n", "Status: `S5-TST-001 PASS`\n")
doc120.write_text(text120.rstrip() + "\n")
