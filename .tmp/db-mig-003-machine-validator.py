from pathlib import Path
import hashlib
import re

p = Path('specs/database/final-migration-order-invariant-matrix.yml')
payload_path = Path('.tmp/db-mig-003-machine-contract.yml')
text = p.read_text()
original = text
payload = payload_path.read_text()

def section(src, start_marker, end_marker):
    s = src.index(start_marker)
    e = src.index(end_marker, s)
    return src[s:e]

def replace_once(src, old, new, label):
    count = src.count(old)
    assert count == 1, f'{label}: expected exactly 1 match, got {count}'
    return src.replace(old, new, 1)

dag_before = section(text, 'migration_dependency_dag:\n', '\nmigration_phase_composition:\n')
phase_before = section(text, 'migration_phase_composition:\n', '\nblockers:\n')
dag_hash = hashlib.sha256(dag_before.encode()).hexdigest()
phase_hash = hashlib.sha256(phase_before.encode()).hexdigest()

node_pairs = re.findall(r'^  - id: (MIG-[A-Z0-9_-]+)\n    type: ([a-z_]+)\n', dag_before, re.M)
node_ids = [node for node, _ in node_pairs]
node_types = {typ for _, typ in node_pairs}
expected_types = {'extension','table','candidate_key','index','foreign_key','trigger','projection','constraint'}
assert node_pairs and len(node_ids) == len(set(node_ids))
assert node_types == expected_types
topo_section = section(dag_before, '  topological_order:\n', '  validation_gate:\n')
topo = re.findall(r'^  - (MIG-[A-Z0-9_-]+)$', topo_section, re.M)
assert len(topo) == len(node_ids) and set(topo) == set(node_ids)

payload = payload.replace('__DAG_NODE_COUNT__', str(len(node_ids)))
assert '__DAG_NODE_COUNT__' not in payload
assert payload.startswith('migration_cutover_execution_contract:\n')

meta_end = text.index('\ncanonical_sources:\n')
head = text[:meta_end]
head = replace_once(head, '  step: DB-MIG-002\n', '  step: DB-MIG-003\n', 'meta step')
head = replace_once(head, '  status: FAIL_WITH_4_P1_BLOCKERS\n', '  status: FAIL_WITH_3_P1_BLOCKERS\n', 'meta status')
head = replace_once(head, '  fixes_applied_total: 2\n', '  fixes_applied_total: 3\n', 'meta fixes')
head = replace_once(head, '  current_step_scope: DB_MIG_002_only\n', '  current_step_scope: DB_MIG_003_only\n', 'scope step')
text = head + text[meta_end:]

insert_marker = '\nblockers:\n'
assert text.count(insert_marker) == 1
text = text.replace(insert_marker, '\n' + payload.rstrip() + '\n' + insert_marker, 1)

blockers_start = text.index('\nblockers:\n')
resolved_start = text.index('\nresolved_contracts:\n', blockers_start)
blockers = text[blockers_start:resolved_start]
mig3_start = blockers.index('  - id: DB-MIG-003\n')
mig3_end = blockers.index('\n  - id: DB-TST-001\n', mig3_start)
mig3 = blockers[mig3_start:mig3_end]
mig3 = replace_once(mig3, '    status: OPEN\n', '    status: PASS\n', 'DB-MIG-003 blocker')
blockers = blockers[:mig3_start] + mig3 + blockers[mig3_end:]
text = text[:blockers_start] + blockers + text[resolved_start:]

resolved_start = text.index('\nresolved_contracts:\n')
diagnosis_start = text.index('\ndiagnosis_summary:\n', resolved_start)
resolved = text[resolved_start:diagnosis_start]
assert '  DB-MIG-003:\n' not in resolved
summary_lines = [
    '',
    '  DB-MIG-003:',
    '    status: PASS',
    '    title: global_cutover_restart_failure_and_safe_rollback_gate',
    '    machine_authority: migration_cutover_execution_contract',
    '    dependency_order_authority_preserved: migration_dependency_dag',
    '    phase_order_authority_preserved: migration_phase_composition',
    f'    DAG_nodes_restart_classified: {len(node_ids)}',
    '    restart_values: [restart_safe, manual_review]',
    '    blind_retry_forbidden: true',
    '    per_phase_entry_exit_abort_defined: true',
    '    per_phase_restart_and_rollback_posture_defined: true',
    '    failed_validation_blocks_contract: true',
    '    destructive_contract_requires_zero_required_unresolved_cases: true',
    '    destructive_contract_requires_backup_health_and_restore_test_evidence: true',
    '    rollback_modes: [application_rollback, schema_forward_fix, explicit_safe_down]',
    '    automatic_destructive_down_for_required_history: forbidden',
    '    final_cutover_smoke_and_integrity_gate_defined: true',
    '    production_specific_RPO_RTO_values_invented: false',
    '    DB_TST_001_and_DB_TST_002_closed_here: false',
    '    DB_FINAL_001_aggregate_sync_closed_here: false',
    '    Laravel_migrations_generated: false',
]
resolved = resolved.rstrip() + '\n' + '\n'.join(summary_lines) + '\n'
text = text[:resolved_start] + resolved + text[diagnosis_start:]

diagnosis_start = text.index('\ndiagnosis_summary:\n')
preservation_start = text.index('\npreservation_gate:\n', diagnosis_start)
diagnosis = text[diagnosis_start:preservation_start]
diagnosis = replace_once(diagnosis, '  resolved_after_diagnosis: 2\n', '  resolved_after_diagnosis: 3\n', 'resolved count')
diagnosis = replace_once(diagnosis, '  open_P0_P1: 4\n', '  open_P0_P1: 3\n', 'open count')
diagnosis = replace_once(diagnosis, '  result: FAIL_WITH_4_P1_BLOCKERS\n', '  result: FAIL_WITH_3_P1_BLOCKERS\n', 'diagnosis result')
diagnosis = replace_once(diagnosis, '    - DB-MIG-003\n', '', 'required order')
text = text[:diagnosis_start] + diagnosis + text[preservation_start:]

preservation_start = text.index('\npreservation_gate:\n')
next_start = text.index('\nnext_single_step_after_central_gate:\n', preservation_start)
preservation = text[preservation_start:next_start]
preservation = replace_once(preservation, '  status: PASS_DB_MIG_002\n', '  status: PASS_DB_MIG_003\n', 'preservation status')
preservation = replace_once(preservation, '    DB_MIG_002_phase_composition_closed: true\n', '    DB_MIG_002_phase_composition_closed: true\n    DB_MIG_003_cutover_restart_failure_rollback_closed: true\n', 'preservation flag')
text = text[:preservation_start] + preservation + text[next_start:]

next_start = text.index('\nnext_single_step_after_central_gate:\n')
nxt = text[next_start:]
nxt = replace_once(nxt, '  id: DB-MIG-003\n', '  id: DB-TST-001\n', 'next id')
nxt = replace_once(nxt, '  action: resolve_DB_MIG_003_only_after_next_explicit_user_instruction\n', '  action: resolve_DB_TST_001_only_after_next_explicit_user_instruction\n', 'next action')
allowed_old = '  allowed_files_only:\n    - specs/database/final-migration-order-invariant-matrix.yml\n    - docs/116-stage-4-final-migration-order-invariant-matrix-audit.md\n    - specs/database/core-schema.yml\n    - docs/87-physical-database-schema.md\n    - specs/gates/stage-4-database-contract-gate.yml\n'
allowed_new = '  allowed_files_only:\n    - specs/database/final-migration-order-invariant-matrix.yml\n    - docs/116-stage-4-final-migration-order-invariant-matrix-audit.md\n    - specs/gates/stage-4-database-contract-gate.yml\n'
nxt = replace_once(nxt, allowed_old, allowed_new, 'next allowed')
scope_old = '  scope_lock: >-\n    DB-MIG-001 and DB-MIG-002 are PASS. Resolve DB-MIG-003 only. Preserve\n    DB-TST-001, DB-TST-002 and DB-FINAL-001 as OPEN. Do not enter Stage 5,\n    generate Laravel migrations, or implement UI/features in the same step.\n  after_action: rerun_DB_MIG_003_machine_narrative_central_gate_and_STOP_before_DB_TST_001\n'
scope_new = '  aggregate_core_schema_and_docs87_frozen_until_DB_FINAL_001: true\n  scope_lock: >-\n    DB-MIG-001, DB-MIG-002 and DB-MIG-003 are PASS. Resolve DB-TST-001 only.\n    Preserve DB-TST-002 and DB-FINAL-001 as OPEN. Do not modify core-schema.yml\n    or docs/87, enter Stage 5, generate Laravel migrations, or implement UI/features.\n  after_action: rerun_DB_TST_001_machine_narrative_central_gate_and_STOP_before_DB_TST_002\n'
nxt = replace_once(nxt, scope_old, scope_new, 'next scope')
text = text[:next_start] + nxt

dag_after = section(text, 'migration_dependency_dag:\n', '\nmigration_phase_composition:\n')
phase_after = section(text, 'migration_phase_composition:\n', '\nmigration_cutover_execution_contract:\n')
assert hashlib.sha256(dag_after.encode()).hexdigest() == dag_hash
assert hashlib.sha256(phase_after.encode()).hexdigest() == phase_hash

contract = section(text, 'migration_cutover_execution_contract:\n', '\nblockers:\n')
defaults = {'extension':'restart_safe','table':'restart_safe','candidate_key':'manual_review','index':'manual_review','foreign_key':'restart_safe','trigger':'restart_safe','projection':'manual_review','constraint':'manual_review'}
assert set(defaults) == node_types
overrides = {'MIG-IDX-CALENDAR_GIST','MIG-FK-PURCHASE_DOWNSTREAM','MIG-FK-EVENTS','MIG-TRG-EVENTS'}
assert overrides <= set(node_ids)
classifications = {node: defaults[typ] for node, typ in node_pairs}
for node in overrides:
    classifications[node] = 'manual_review'
assert len(classifications) == len(node_ids)
assert set(classifications.values()) <= {'restart_safe','manual_review'}

phases = ['expand','preflight','write_fence','backfill','reconcile','validate','contract']
phase_gate = section(contract, '  per_phase_execution_gate:\n', '  failure_and_resume_matrix:\n')
for i, phase in enumerate(phases):
    marker = f'    {phase}:\n'
    assert phase_gate.count(marker) == 1
    start = phase_gate.index(marker)
    end = phase_gate.find('\n    ' + phases[i+1] + ':\n', start) if i + 1 < len(phases) else len(phase_gate)
    chunk = phase_gate[start:end]
    for req in ['entry_requires:', 'exit_requires:', 'abort_if:', 'restart_posture:', 'rollback_posture:']:
        assert req in chunk, f'{phase} missing {req}'

required_contract_markers = [
    '    blind_retry: forbidden',
    '    allowed_modes:\n      - application_rollback\n      - schema_forward_fix\n      - explicit_safe_down',
    '    destructive_contract_requires_successful_restore_evidence: true',
    '    automatic_down_for_formal_financial_audit_history: forbidden',
    '    stable_test_ids_defined_here: false',
    '    exact_RPO_RTO_values: external_production_business_policy_not_set_in_DB_MIG_003',
    '    PASS_does_not_mean_DB_TST_001_or_DB_TST_002_are_closed: true',
]
for marker in required_contract_markers:
    assert marker in contract, marker

assert '  - id: DB-TST-001\n    severity: P1\n    status: OPEN\n' in text
assert '  - id: DB-TST-002\n    severity: P1\n    status: OPEN\n' in text
assert '  - id: DB-FINAL-001\n    severity: P1\n    status: OPEN\n' in text
assert '  step: DB-MIG-003\n' in text
assert '  fixes_applied_total: 3\n' in text
assert '  status: PASS_DB_MIG_003\n' in text[text.index('\npreservation_gate:\n'):]
assert '  id: DB-TST-001\n' in text[text.index('\nnext_single_step_after_central_gate:\n'):]
assert text != original
p.write_text(text)
print(f'MACHINE_ASSERTIONS_PASS nodes={len(node_ids)}')
