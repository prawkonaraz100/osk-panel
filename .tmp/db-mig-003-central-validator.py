from pathlib import Path

p = Path('specs/gates/stage-4-database-contract-gate.yml')
text = p.read_text()
original = text
machine = Path('specs/database/final-migration-order-invariant-matrix.yml').read_text()
narrative = Path('docs/116-stage-4-final-migration-order-invariant-matrix-audit.md').read_text()


def replace_once(src, old, new, label):
    count = src.count(old)
    assert count == 1, f'{label}: expected 1 match, got {count}'
    return src.replace(old, new, 1)

# Current DB4_11 counters only; historical diagnosis remains untouched.
marker = '  - id: DB4_11_FINAL_MIGRATION_ORDER_AND_INVARIANT_TEST_MATRIX\n'
assert text.count(marker) == 1
start = text.index(marker)
end = text.index('\nquality_checks:\n', start)
sec = text[start:end]
sec = replace_once(sec, '    status: FAIL_WITH_4_P1_BLOCKERS\n', '    status: FAIL_WITH_3_P1_BLOCKERS\n', 'slice status')
sec = replace_once(sec, '    blockers_resolved: 2\n', '    blockers_resolved: 3\n', 'slice resolved')
sec = replace_once(sec, '    open_P0_P1: 4\n', '    open_P0_P1: 3\n', 'slice open')
sec = replace_once(sec, '    result: FAIL_WITH_4_P1_BLOCKERS\n', '    result: FAIL_WITH_3_P1_BLOCKERS\n', 'slice result')
text = text[:start] + sec + text[end:]

# Append DB-MIG-003 current quality gate immediately before resolved_blockers.
insert_marker = '\nresolved_blockers:\n'
assert text.count(insert_marker) == 1
quality_lines = [
    '  DB4_11_DB_MIG_003:',
    '    status: PASS',
    '    resolved_blocker_id: DB-MIG-003',
    '    machine_source: specs/database/final-migration-order-invariant-matrix.yml',
    '    narrative_source: docs/116-stage-4-final-migration-order-invariant-matrix-audit.md',
    '    machine_commit: a7c00bb726944ba3e6a29a1b9fc974a67b0ae082',
    '    machine_blob: df06baca7947d74e04adc5912fb40fdb972fa38e',
    '    narrative_commit: dfc329fd034a0d5f0fc98c0de8dcc0cc5b6230c0',
    '    narrative_blob: 5668ed5d3e555953e8dafa3b37ad68ddd447089a',
    '    aggregate_core_schema_blob_frozen: 39958721c99550cfc27c3774e8dfa1af0d0637e6',
    '    aggregate_docs87_blob_frozen: 6c090b082604b6b42c283667fcf08e9033a5b523',
    '    checks:',
    '      DB_MIG_001_DAG_authority_unchanged: PASS',
    '      DB_MIG_002_seven_phase_authority_unchanged: PASS',
    '      all_170_DAG_nodes_resolve_restart_classification: PASS',
    '      restart_classes_are_restart_safe_or_manual_review_only: PASS',
    '      restart_safe_requires_exact_postcondition_before_retry: PASS',
    '      blind_retry_forbidden: PASS',
    '      all_seven_phases_have_entry_exit_abort_restart_and_rollback_contract: PASS',
    '      nontransactional_or_concurrent_partial_index_requires_manual_review: PASS',
    '      interrupted_backfill_requires_exact_idempotent_checkpoint_or_manual_review: PASS',
    '      interrupted_reconciliation_not_replayed_blindly: PASS',
    '      validation_failure_keeps_write_fence_and_blocks_contract: PASS',
    '      rollback_modes_limited_to_application_rollback_schema_forward_fix_explicit_safe_down: PASS',
    '      automatic_destructive_down_for_required_history_forbidden: PASS',
    '      destructive_contract_requires_zero_required_unresolved_cases: PASS',
    '      destructive_contract_requires_backup_health_and_restore_test_evidence: PASS',
    '      final_cutover_smoke_integrity_gate_defined_without_stable_test_ids: PASS',
    '      DB_TST_001_and_DB_TST_002_not_solved_early: PASS',
    '      DB_FINAL_001_aggregate_sync_not_solved_early: PASS',
    '      production_specific_RPO_RTO_not_invented: PASS',
    '      core_schema_modified_in_DB_MIG_003: false',
    '      docs87_modified_in_DB_MIG_003: false',
    '      Laravel_migrations_created: false',
    '      Stage5_started: false',
    '      UI_or_feature_implementation_started: false',
    '',
]
quality = '\n'.join(quality_lines) + '\n'
assert '  DB4_11_DB_MIG_003:\n' not in text
text = text.replace(insert_marker, '\n' + quality + 'resolved_blockers:\n', 1)

# Resolved/current-open lists only.
r_start = text.index('\nresolved_blockers:\n')
r_end = text.index('\nopen_blockers_current_diagnosed_scope:\n', r_start)
resolved = text[r_start:r_end]
assert resolved.count('  - DB-MIG-002\n') == 1
assert '  - DB-MIG-003\n' not in resolved
resolved = resolved.replace('  - DB-MIG-002\n', '  - DB-MIG-002\n  - DB-MIG-003\n', 1)
text = text[:r_start] + resolved + text[r_end:]

o_start = text.index('\nopen_blockers_current_diagnosed_scope:\n')
o_end = text.index('\ngate_definition:\n', o_start)
opened = text[o_start:o_end]
for item in ['  - DB-MIG-003\n','  - DB-TST-001\n','  - DB-TST-002\n','  - DB-FINAL-001\n']:
    assert opened.count(item) == 1, item
opened = opened.replace('  - DB-MIG-003\n', '', 1)
text = text[:o_start] + opened + text[o_end:]

# Current gate results only.
g_start = text.index('\ngate_definition:\n')
n_start = text.index('\nnext_single_step:\n', g_start)
gate = text[g_start:n_start]
gate = replace_once(gate, '  current_result: DB4_11_FAIL_WITH_4_P1_BLOCKERS\n', '  current_result: DB4_11_FAIL_WITH_3_P1_BLOCKERS\n', 'current result')
gate = replace_once(gate, '  DB4_11_DB_MIG_003_result: OPEN\n', '  DB4_11_DB_MIG_003_result: PASS\n', 'MIG003 result')
gate = replace_once(gate, '  DB4_11_result: FAIL_WITH_4_P1_BLOCKERS\n', '  DB4_11_result: FAIL_WITH_3_P1_BLOCKERS\n', 'DB4_11 result')
text = text[:g_start] + gate + text[n_start:]

# Advance next pointer to DB-TST-001 and freeze aggregates explicitly.
n_start = text.index('\nnext_single_step:\n')
nxt = text[n_start:]
nxt = replace_once(nxt, '  id: DB-MIG-003\n', '  id: DB-TST-001\n', 'next id')
nxt = replace_once(nxt, '  action: resolve_DB_MIG_003_only_after_next_explicit_user_instruction\n', '  action: resolve_DB_TST_001_only_after_next_explicit_user_instruction\n', 'next action')
old_scope = '''  allowed_scope:
    - specs/database/final-migration-order-invariant-matrix.yml
    - docs/116-stage-4-final-migration-order-invariant-matrix-audit.md
    - specs/database/core-schema.yml
    - docs/87-physical-database-schema.md
    - specs/gates/stage-4-database-contract-gate.yml
  prior_bounded_context_specs_read_only: true
  scope_lock: >-
    DB-MIG-001 and DB-MIG-002 are PASS. Resolve DB-MIG-003 only. Preserve DB-TST-001,
    DB-TST-002 and DB-FINAL-001 as OPEN. Do not enter Stage 5, generate Laravel
    migrations, or implement UI/features in the same step.
  after_action: run_DB_MIG_003_machine_narrative_central_gate_and_STOP_before_DB_TST_001
'''
new_scope = '''  allowed_scope:
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
nxt = replace_once(nxt, old_scope, new_scope, 'next scope')
text = text[:n_start] + nxt

# Cross-authority semantic checks.
assert '  step: DB-MIG-003\n' in machine
assert '  status: PASS_DB_MIG_003\n' in machine
assert 'DAG_node_count_at_contract_close: 170' in machine
assert 'blind_retry: forbidden' in machine
assert 'automatic_down_for_formal_financial_audit_history: forbidden' in machine
assert 'destructive_contract_requires_successful_restore_evidence: true' in machine
assert 'PASS_does_not_mean_DB_TST_001_or_DB_TST_002_are_closed: true' in machine
assert '**Aktualny krok:** `DB-MIG-003`' in narrative
assert '**Status:** `FAIL_WITH_3_P1_BLOCKERS / 0 P0 / 3 P1 OPEN`' in narrative
assert '**Status: PASS / P1 RESOLVED**' in narrative[narrative.index('## 5. DB-MIG-003'):narrative.index('## 6. DB-TST-001')]
assert '**STOP przed fixerem DB-TST-001.**' in narrative

# Preservation/history checks.
assert '  DB4_11_diagnosis:\n    status: FAIL_WITH_6_P1_BLOCKERS\n' in text
assert '      executable_dependency_DAG_missing: FAIL_DB_MIG_001\n' in text
assert '      migration_phase_composition_missing: FAIL_DB_MIG_002\n' in text
assert '      global_cutover_restart_rollback_gate_missing: FAIL_DB_MIG_003\n' in text
assert text.count('  DB4_11_DB_MIG_003:\n') == 1
assert text.count('  DB4_11_DB_MIG_003_result: PASS\n') == 1
assert '  DB4_11_DB_TST_001_result: OPEN\n' in text
assert '  DB4_11_DB_TST_002_result: OPEN\n' in text
assert '  DB4_11_DB_FINAL_001_result: OPEN\n' in text
assert '  current_result: DB4_11_FAIL_WITH_3_P1_BLOCKERS\n' in text
assert '  id: DB-TST-001\n' in text[text.index('\nnext_single_step:\n'):]
assert text != original

p.write_text(text)
print('CENTRAL_ASSERTIONS_PASS')
