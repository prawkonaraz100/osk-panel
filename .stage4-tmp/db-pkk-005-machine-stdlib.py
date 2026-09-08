from pathlib import Path

p = Path('specs/database/pkk.yml')
s = p.read_text()
frag = Path('.stage4-tmp/db-pkk-005-machine.ymlfrag').read_text().rstrip() + '\n\n'

def once(old, new, label):
    global s
    n = s.count(old)
    assert n == 1, f'{label}: expected exactly 1 match, got {n}'
    s = s.replace(old, new, 1)

once('  step: DB_PKK_004_IDEMPOTENCY_RETRY_RECONCILIATION_AND_EXACTLY_ONCE_EXTERNAL_EFFECT\n',
     '  step: DB_PKK_005_SIGNED_XML_HANDOFF_AND_FILE_ASSET_EVIDENCE_INTEGRITY\n', 'meta step')
once('  status: FAIL_WITH_4_P1_BLOCKERS\n', '  status: FAIL_WITH_3_P1_BLOCKERS\n', 'meta status')
once('  current_step_scope: DB_PKK_004_IDEMPOTENCY_RETRY_RECONCILIATION_AND_EXACTLY_ONCE_EXTERNAL_EFFECT_only\n',
     '  current_step_scope: DB_PKK_005_SIGNED_XML_HANDOFF_AND_FILE_ASSET_EVIDENCE_INTEGRITY_only\n', 'scope')

marker = '\nblockers:\n'
assert s.count(marker) == 1, f'blockers marker count={s.count(marker)}'
assert '\n  DB-PKK-005:\n    status: PASS\n' not in s
s = s.replace(marker, '\n' + frag + 'blockers:\n', 1)

old_blocker = '''  - id: DB-PKK-005
    title: signed_XML_handoff_and_file_asset_evidence_integrity
    severity: P1
    status: OPEN
'''
new_blocker = '''  - id: DB-PKK-005
    title: signed_XML_handoff_and_file_asset_evidence_integrity
    severity: P1
    status: RESOLVED
    resolution_ref: resolved_contracts.DB-PKK-005
'''
once(old_blocker, new_blocker, 'blocker 005')

start = s.index('diagnosis_summary:\n')
end = s.index('\nAPI_contract_sync_gaps_recorded_not_fixed:', start)
ds = s[start:end]
assert '  resolved_after_diagnosis: 4\n' in ds
assert '  open_P0_P1: 4\n' in ds
assert '  result: FAIL_WITH_4_P1_BLOCKERS\n' in ds
assert ds.count('    - DB-PKK-005\n') == 1
ds = ds.replace('  resolved_after_diagnosis: 4\n','  resolved_after_diagnosis: 5\n',1)
ds = ds.replace('  open_P0_P1: 4\n','  open_P0_P1: 3\n',1)
ds = ds.replace('  result: FAIL_WITH_4_P1_BLOCKERS\n','  result: FAIL_WITH_3_P1_BLOCKERS\n',1)
ds = ds.replace('    - DB-PKK-005\n','',1)
s = s[:start] + ds + s[end:]

ps = s.index('preservation_gate:\n')
ns = s.index('\nnext_single_step_after_central_gate:', ps)
pb = s[ps:ns]
assert '  status: PASS_DB_PKK_004\n' in pb
pb = pb.replace('  status: PASS_DB_PKK_004\n','  status: PASS_DB_PKK_005\n',1)
old_tail = '    DB_PKK_005_through_DB_PKK_008_remain_open: true\n'
assert pb.count(old_tail) == 1, f'preservation tail count={pb.count(old_tail)}'
pb = pb.replace(old_tail,
    '    DB_PKK_005_signed_XML_handoff_and_FileAsset_evidence_integrity_closed: true\n'
    '    DB_PKK_006_through_DB_PKK_008_remain_open: true\n',1)
s = s[:ps] + pb + s[ns:]

ns = s.index('next_single_step_after_central_gate:\n')
nb = s[ns:]
assert '  id: DB_PKK_005_SIGNED_XML_HANDOFF_AND_FILE_ASSET_EVIDENCE_INTEGRITY\n' in nb
assert '  action: resolve_DB_PKK_005_only_after_next_explicit_user_instruction\n' in nb
assert '  stop_before: DB_PKK_006_SENSITIVE_PROVIDER_PAYLOAD_ENCRYPTION_REDACTION_HASH_AND_KEY_VERSION_BOUNDARY\n' in nb
nb = nb.replace('  id: DB_PKK_005_SIGNED_XML_HANDOFF_AND_FILE_ASSET_EVIDENCE_INTEGRITY\n',
                '  id: DB_PKK_006_SENSITIVE_PROVIDER_PAYLOAD_ENCRYPTION_REDACTION_HASH_AND_KEY_VERSION_BOUNDARY\n',1)
nb = nb.replace('  action: resolve_DB_PKK_005_only_after_next_explicit_user_instruction\n',
                '  action: resolve_DB_PKK_006_only_after_next_explicit_user_instruction\n',1)
nb = nb.replace('  stop_before: DB_PKK_006_SENSITIVE_PROVIDER_PAYLOAD_ENCRYPTION_REDACTION_HASH_AND_KEY_VERSION_BOUNDARY\n',
                '  stop_before: DB_PKK_007_INTEGRATION_CONFIGURATION_REVISION_BINDING_AND_ASYNC_EXECUTION_CONTEXT\n',1)
s = s[:ns] + nb

p.write_text(s)
