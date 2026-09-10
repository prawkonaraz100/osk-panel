from pathlib import Path
import re

P = Path('specs/database/final-migration-order-invariant-matrix.yml')
text = P.read_text()
original = text

OVERRIDES = {
    'DBT-AUD-002': ('normal_application_cannot_update_or_delete_audit_history', 'schema_constraint'),
    'DBT-AUD-010': ('activity_retry_preserves_immutable_historical_display_snapshot_and_policy_version', 'projection'),
    'DBT-COM-002': ('commerce_server_snapshot_price_VAT_discount_line_and_order_totals_are_immutable_and_equivalent', 'schema_constraint'),
    'DBT-COM-006': ('commerce_payment_resolution_creates_recoverable_pending_fulfillment_and duplicate_workers_commit_at_most_one_complete_grant_set', 'concurrency'),
    'DBT-CORE-005': ('generic_login_identifier_resolves_to_at_most_one_current_user', 'schema_constraint'),
    'DBT-IAM-011': ('organization_membership_user_id_is_immutable', 'schema_constraint'),
    'DBT-CORE-019': ('superseded_document_business_fields_are_immutable', 'schema_constraint'),
    'DBT-CORE-042': ('activation_targets_exact_assignment_learning_account_and_is_immutable', 'schema_constraint'),
    'DBT-CORE-044': ('product_duration_and_inventory_product_binding_are_immutable_after_inventory_reference', 'schema_constraint'),
    'DBT-CORE-069': ('result_score_max_pass_and_question_evidence_match_frozen_scoring_policy_and_are_immutable', 'schema_constraint'),
    'DBT-RES-010': ('staff_document_has_at_most_one_current_row_per_type', 'schema_constraint'),
    'DBT-RES-011': ('vehicle_document_has_at_most_one_current_row_per_type', 'schema_constraint'),
}

META = {
    'schema_constraint': {
        'execution_phase': 'post_schema',
        'expected_outcome': 'invalid_tuple_or_mutation_is_rejected_and_valid_control_case_succeeds',
        'fixture_profile': 'FP-SCHEMA-NEGATIVE',
        'concurrency_profile': 'none',
    },
    'projection': {
        'execution_phase': 'projection_validation',
        'expected_outcome': 'canonical_source_projection_and_dedupe_semantics_remain_consistent_under_retry_or_replay',
        'fixture_profile': 'FP-PROJECTION-REPLAY',
        'concurrency_profile': 'CP-RETRY-OR-REPLAY',
    },
    'concurrency': {
        'execution_phase': 'runtime_concurrency',
        'expected_outcome': 'all_interleavings_preserve_one_legal_final_state_without_duplicate_or_lost_effect',
        'fixture_profile': 'FP-CONCURRENCY-RACE',
        'concurrency_profile': 'CP-TWO-TRANSACTIONS',
    },
}

def normalize_source(block):
    m = re.search(r'^    source_invariant: (.*?)(?=^    source_refs:)', block, re.M | re.S)
    if not m:
        raise AssertionError('missing source_invariant')
    raw = m.group(1).replace('\n      ', ' ').strip()
    return re.sub(r'\s+', ' ', raw)

def replace_field(block, field, value):
    pat = rf'(^    {re.escape(field)}: ).*$'
    out, n = re.subn(pat, rf'\g<1>{value}', block, count=1, flags=re.M)
    if n != 1:
        raise AssertionError(f'{field}: expected 1 replacement got {n}')
    return out

for test_id, (expected_source, new_class) in OVERRIDES.items():
    marker = f'  - test_id: {test_id}\n'
    start = text.find(marker)
    if start < 0:
        raise AssertionError(f'missing {test_id}')
    next_start = text.find('\n  - test_id: ', start + len(marker))
    gate_start = text.find('\n  validation_gate:', start + len(marker))
    candidates = [x for x in [next_start, gate_start] if x >= 0]
    end = min(candidates) if candidates else len(text)
    block = text[start:end]
    source = normalize_source(block)
    if source != expected_source:
        raise AssertionError(f'{test_id}: source drift: {source!r}')
    old_class = re.search(r'^    test_class: (.+)$', block, re.M).group(1)
    if old_class != 'transaction':
        raise AssertionError(f'{test_id}: expected transaction before correction, got {old_class}')
    meta = META[new_class]
    block = replace_field(block, 'test_class', new_class)
    block = replace_field(block, 'execution_phase', meta['execution_phase'])
    block = replace_field(block, 'expected_outcome', meta['expected_outcome'])
    block = replace_field(block, 'fixture_profile', meta['fixture_profile'])
    block = replace_field(block, 'concurrency_profile', meta['concurrency_profile'])
    text = text[:start] + block + text[end:]

old_counts = '''  class_counts:\n    concurrency: 14\n    migration_preflight: 14\n    projection: 20\n    schema_constraint: 13\n    security_storage: 46\n    transaction: 154\n'''
new_counts = '''  class_counts:\n    concurrency: 15\n    migration_postcheck: 0\n    migration_preflight: 14\n    projection: 21\n    schema_constraint: 23\n    security_storage: 46\n    transaction: 142\n'''
if text.count(old_counts) != 1:
    raise AssertionError('class_counts source block drift')
text = text.replace(old_counts, new_counts, 1)

# Add explicit validation that all seven class values have counts, even if zero.
needle = '    every_test_has_allowed_test_class: PASS\n'
insert = needle + '    all_seven_declared_test_classes_have_explicit_count: PASS\n    zero_count_class_is_allowed_when_no_current_source_invariant_requires_it: PASS\n'
if text.count(needle) != 1:
    raise AssertionError('validation gate marker drift')
text = text.replace(needle, insert, 1)

if text == original:
    raise AssertionError('no-op correction')
P.write_text(text)
print('DB_TST_001_CLASSIFICATION_CORRECTION_PASS overrides=12')
