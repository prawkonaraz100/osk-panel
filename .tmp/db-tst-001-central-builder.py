from pathlib import Path

P = Path('specs/gates/stage-4-database-contract-gate.yml')
text = P.read_text()
original = text

def repl(old, new, label):
    global text
    n = text.count(old)
    if n != 1:
        raise AssertionError(f'{label}: expected 1 match got {n}')
    text = text.replace(old, new, 1)

# Current DB4_11 slice only; preserve historical diagnosis result.
old_slice = '''  - id: DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX
    status: FAIL_WITH_3_P1_BLOCKERS
    diagnosis: COMPLETE
    blockers_diagnosed: 6
    blockers_resolved: 3
    open_P0_P1: 3
    result: FAIL_WITH_3_P1_BLOCKERS
'''
new_slice = '''  - id: DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX
    status: FAIL_WITH_2_P1_BLOCKERS
    diagnosis: COMPLETE
    blockers_diagnosed: 6
    blockers_resolved: 4
    open_P0_P1: 2
    result: FAIL_WITH_2_P1_BLOCKERS
'''
repl(old_slice, new_slice, 'current DB4_11 slice')

quality_marker = '''      UI_or_feature_implementation_started: false

resolved_blockers:
'''
quality_block = '''      UI_or_feature_implementation_started: false

  DB4_11_DB_TST_001:
    status: PASS
    resolved_blocker_id: DB-TST-001
    machine_source: specs/database/final-migration-order-invariant-matrix.yml
    narrative_source: docs/116-stage-4-final-migration-order-invariant-matrix-audit.md
    machine_commit: e4ea8472d93c9ba6b454fd8f80d0372f1beb94fb
    machine_blob: b658c5fcd8ba1f451b2b01e807c6e88ab703f234
    narrative_commit: 8e0b2d7453e29f1fe18d7b6009676ad89bebe413
    narrative_blob: 45f584bd36206c0b13592dbaf40bb55801cfac63
    aggregate_core_schema_blob_frozen: 39958721c99550cfc27c3774e8dfa1af0d0637e6
    aggregate_docs87_blob_frozen: 6c090b082604b6b42c283667fcf08e9033a5b523
    checks:
      DB_MIG_001_DAG_authority_unchanged: PASS
      DB_MIG_002_phase_composition_authority_unchanged: PASS
      DB_MIG_003_cutover_execution_authority_unchanged: PASS
      source_migration_tests_required_occurrences: 261
      unique_source_invariants: 261
      duplicate_source_occurrences: 0
      stable_test_ids_defined_for_all_261_tests: PASS
      stable_test_id_format_DBT_DOMAIN_NNN_defined: PASS
      existing_test_id_renumber_without_authority_migration_forbidden: PASS
      seven_test_class_values_declared: PASS
      all_seven_class_counts_explicit_including_zero: PASS
      test_class_counts_sum_to_261: PASS
      class_count_transaction: 142
      class_count_security_storage: 46
      class_count_schema_constraint: 23
      class_count_projection: 21
      class_count_concurrency: 15
      class_count_migration_preflight: 14
      class_count_migration_postcheck: 0
      every_test_has_source_invariant_and_nonempty_source_refs: PASS
      every_test_has_execution_phase_blocking_severity_expected_outcome_and_fixture_profile: PASS
      every_concurrency_test_has_explicit_non_none_concurrency_profile: PASS
      race_tests_not_reduced_to_sequential_unit_contract: PASS
      framework_test_files_generated: false
      DB_TST_002_coverage_traceability_and_completeness_not_solved_early: PASS
      DB_FINAL_001_aggregate_sync_not_solved_early: PASS
      core_schema_modified_in_DB_TST_001: false
      docs87_modified_in_DB_TST_001: false
      Laravel_migrations_created: false
      Stage5_started: false
      UI_or_feature_implementation_started: false

resolved_blockers:
'''
# The marker occurs many times earlier; anchor after DB4_11_DB_MIG_003 section.
mig3 = text.index('  DB4_11_DB_MIG_003:\n')
res = text.index('\nresolved_blockers:\n', mig3)
segment = text[mig3:res+len('\nresolved_blockers:\n')]
if not segment.endswith('\nresolved_blockers:\n'):
    raise AssertionError('quality anchor malformed')
text = text[:mig3] + segment[:-len('\nresolved_blockers:\n')] + '\n' + quality_block.split('\nresolved_blockers:\n')[0] + '\nresolved_blockers:\n' + text[res+len('\nresolved_blockers:\n'):]

repl('  - DB-MIG-003\n\nopen_blockers_current_diagnosed_scope:\n  - DB-TST-001\n  - DB-TST-002\n  - DB-FINAL-001\n',
     '  - DB-MIG-003\n  - DB-TST-001\n\nopen_blockers_current_diagnosed_scope:\n  - DB-TST-002\n  - DB-FINAL-001\n', 'resolved/open lists')

repl('  current_result: DB4_11_FAIL_WITH_3_P1_BLOCKERS\n', '  current_result: DB4_11_FAIL_WITH_2_P1_BLOCKERS\n', 'current result')
repl('  DB4_11_DB_TST_001_result: OPEN\n', '  DB4_11_DB_TST_001_result: PASS\n', 'TST001 result')
repl('  DB4_11_result: FAIL_WITH_3_P1_BLOCKERS\n', '  DB4_11_result: FAIL_WITH_2_P1_BLOCKERS\n', 'DB4_11 result')

old_next = '''next_single_step:
  id: DB-TST-001
  action: resolve_DB_TST_001_only_after_next_explicit_user_instruction
  diagnosis_only: false
  fixes_allowed_in_same_step: true
  allowed_scope:
    - specs/database/final-migration-order-invariant-matrix.yml
    - docs/116-stage-4-final-migration-order-invariant-matrix-audit.md
    - specs/gates/stage-4-database-contract-gate.yml
  prior_bounded_context_specs_read_only: true
  aggregate_core_schema_and_docs87_frozen_until_DB_FINAL_001: true
  scope_lock: >-
    DB-MIG-001, DB-MIG-002 and DB-MIG-003 are PASS. Resolve DB-TST-001 only.
    Preserve DB-TST-002 and DB-FINAL-001 as OPEN. Do not modify core-schema.yml
    or docs/87, enter Stage 5, generate Laravel migrations, or implement UI/features.
  after_action: run_DB_TST_001_machine_narrative_central_gate_and_STOP_before_DB_TST_002
'''
new_next = '''next_single_step:
  id: DB-TST-002
  action: resolve_DB_TST_002_only_after_next_explicit_user_instruction
  diagnosis_only: false
  fixes_allowed_in_same_step: true
  allowed_scope:
    - specs/database/final-migration-order-invariant-matrix.yml
    - docs/116-stage-4-final-migration-order-invariant-matrix-audit.md
    - specs/gates/stage-4-database-contract-gate.yml
  prior_bounded_context_specs_read_only: true
  aggregate_core_schema_and_docs87_frozen_until_DB_FINAL_001: true
  scope_lock: >-
    DB-MIG-001, DB-MIG-002, DB-MIG-003 and DB-TST-001 are PASS. Resolve DB-TST-002 only.
    Preserve DB-FINAL-001 as OPEN. Do not modify core-schema.yml or docs/87, enter Stage 5,
    generate Laravel migrations, or implement UI/features.
  after_action: run_DB_TST_002_machine_narrative_central_gate_and_STOP_before_DB_FINAL_001
'''
repl(old_next, new_next, 'next step')

if text == original:
    raise AssertionError('no-op central')
# Historical diagnosis must remain exactly present and only once.
if text.count('  DB4_11_diagnosis_result: FAIL_WITH_6_P1_BLOCKERS\n') != 1:
    raise AssertionError('historical DB4_11 diagnosis drift')
for marker in [
    '  DB4_11_DB_TST_001:\n    status: PASS\n',
    '      stable_test_ids_defined_for_all_261_tests: PASS\n',
    '  DB4_11_DB_TST_001_result: PASS\n',
    '  DB4_11_DB_TST_002_result: OPEN\n',
    '  DB4_11_DB_FINAL_001_result: OPEN\n',
    '  DB4_11_result: FAIL_WITH_2_P1_BLOCKERS\n',
    'next_single_step:\n  id: DB-TST-002\n',
]:
    if marker not in text:
        raise AssertionError(f'missing marker {marker!r}')
P.write_text(text)
print('DB_TST_001_CENTRAL_PASS')
