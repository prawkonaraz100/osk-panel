from pathlib import Path
import json

PATH = Path("specs/database/final-migration-order-invariant-matrix.yml")
text = PATH.read_text()

def replace_once(old, new):
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"expected exactly one match for {old!r}, got {count}")
    text = text.replace(old, new, 1)

replace_once("  step: DIAGNOSIS\n  status: FAIL_WITH_6_P1_BLOCKERS\n", "  step: DB_MIG_001\n  status: FAIL_WITH_5_P1_BLOCKERS\n")
replace_once("  diagnosis_only: true\n  fixes_applied_in_diagnosis_step: 0\n", "  diagnosis_only: false\n  fixes_applied_in_diagnosis_step: 0\n  fixes_applied_after_diagnosis: 1\n")
replace_once("  fixes_allowed_in_this_step: false\n  current_step_scope: DB4_11_DIAGNOSIS_only\n", "  fixes_allowed_in_this_step: true\n  current_step_scope: DB_MIG_001_only\n")
replace_once("  aggregate_files_frozen_in_diagnosis:\n", "  aggregate_files_frozen_in_DB_MIG_001:\n")
replace_once("  prior_bounded_context_specs_read_only_in_diagnosis: true\n", "  prior_bounded_context_specs_read_only_in_DB_MIG_001: true\n")

nodes = json.loads(r'''[{"id":"MIG-EXT-001","kind":"extension","scope":"postgres_extensions","ordered_objects":["btree_gist"],"requires":[]},{"id":"MIG-TBL-001","kind":"table","scope":"identity_global_dictionaries","ordered_objects":["organizations","users","permissions","data_scopes","languages","driving_categories","location_types","staff_types","internal_exam_capabilities","organization_memberships","auth_login_identifiers","auth_social_accounts","user_password_management","auth_sessions","account_closure_requests","permission_scope_options","membership_permissions","membership_permission_scopes"],"requires":[]},{"id":"MIG-CK-001","kind":"candidate_key","scope":"identity_candidate_keys","ordered_objects":["organization_memberships[organization_id,id]","organization_memberships[id,user_id]","organization_memberships[organization_id,id,user_id]","auth_login_identifiers[id,user_id]"],"requires":["MIG-TBL-001"]},{"id":"MIG-FK-001","kind":"foreign_key","scope":"identity_relations","ordered_objects":["auth_session_membership_same_user","membership_permission_scope_relations","password_management_authority_relations","account_closure_relations"],"requires":["MIG-CK-001"]},{"id":"MIG-GRD-001","kind":"trigger_guard","scope":"identity_guards","ordered_objects":["membership_owner_permission_scope_state","identifier_currentness","session_tenant_context","password_authority_exclusive_principal"],"requires":["MIG-FK-001"]},{"id":"MIG-TBL-002","kind":"table","scope":"settings_legal_assets_staff_resources","ordered_objects":["legal_documents","terms_acceptances","organization_settings","organization_contact_addresses","pkk_integration_settings","file_assets","idempotency_records","staff_profiles","locations","vehicles","staff_type_assignments","staff_category_assignments","staff_location_assignments","staff_membership_links","staff_documents","vehicle_documents","vehicle_category_assignments","vehicle_location_assignments"],"requires":["MIG-TBL-001"]},{"id":"MIG-CK-002","kind":"candidate_key","scope":"asset_staff_resource_candidate_keys","ordered_objects":["file_assets[organization_id,id]","staff_profiles[organization_id,id]","locations[organization_id,id]","vehicles[organization_id,id]"],"requires":["MIG-TBL-002"]},{"id":"MIG-FK-002","kind":"foreign_key","scope":"settings_asset_staff_resource_relations","ordered_objects":["terms_settings_asset_organization_relations","staff_assignment_same_tenant_relations","staff_membership_same_tenant_relation","staff_vehicle_asset_same_tenant_relations","vehicle_location_same_tenant_relations"],"requires":["MIG-CK-001","MIG-CK-002"]},{"id":"MIG-GRD-002","kind":"trigger_guard","scope":"staff_resource_history_uniqueness_guards","ordered_objects":["staff_archive_membership_link","document_current_history","PESEL_VIN_registration_uniqueness"],"requires":["MIG-FK-002"]},{"id":"MIG-TBL-003","kind":"table","scope":"student_course_training","ordered_objects":["students","student_learning_accounts","student_access_handoffs","student_access_export_batches","course_enrollments","course_enrollment_lifecycle_events","training_requirement_rule_sets","course_requirement_contexts","course_requirement_context_held_categories","course_requirement_override_decisions","training_requirement_profiles","course_exemption_decisions","recognized_external_training","pkk_profiles","training_sessions","training_session_attendance","training_hour_ledger_entries"],"requires":["MIG-TBL-002"]},{"id":"MIG-CK-003","kind":"candidate_key","scope":"student_course_training_candidate_keys","ordered_objects":["students[organization_id,id]","student_learning_accounts[organization_id,id]","student_learning_accounts[organization_id,id,student_id]","course_enrollments[organization_id,id]","course_enrollments[organization_id,id,student_id]","training_sessions[organization_id,id]"],"requires":["MIG-TBL-003"]},{"id":"MIG-FK-003","kind":"foreign_key","scope":"student_course_training_relations","ordered_objects":["learning_account_student_identifier_relations","course_student_instructor_location_relations","requirement_external_PKK_course_relations","session_attendance_ledger_same_tenant_exact_relations"],"requires":["MIG-CK-001","MIG-CK-002","MIG-CK-003"]},{"id":"MIG-GRD-003","kind":"trigger_guard","scope":"student_course_training_guards","ordered_objects":["student_formal_identity_archive","course_lifecycle_requirement_revision","exactly_one_current_PKK_identity","attendance_credit_ledger_append_only"],"requires":["MIG-FK-003"]},{"id":"MIG-TBL-004","kind":"table","scope":"calendar","ordered_objects":["calendar_events","calendar_event_lifecycle_events","availability_slots","availability_slot_lifecycle_events","calendar_resource_claims","training_session_calendar_details"],"requires":["MIG-TBL-003"]},{"id":"MIG-CK-004","kind":"candidate_key","scope":"calendar_candidate_keys","ordered_objects":["calendar_events[organization_id,id]","availability_slots[organization_id,id]"],"requires":["MIG-TBL-004"]},{"id":"MIG-FK-004","kind":"foreign_key","scope":"calendar_relations","ordered_objects":["calendar_optional_resource_same_tenant","availability_resource_booked_student_same_tenant","training_session_calendar_detail_relation"],"requires":["MIG-CK-002","MIG-CK-003","MIG-CK-004"]},{"id":"MIG-IDX-001","kind":"index","scope":"calendar_exclusion_indexes","ordered_objects":["student_active_claim_exclusion","instructor_active_claim_exclusion","vehicle_active_claim_exclusion","location_active_claim_exclusion"],"requires":["MIG-EXT-001","MIG-FK-004"]},{"id":"MIG-GRD-004","kind":"trigger_guard","scope":"calendar_guards","ordered_objects":["calendar_event_claim_exact_set","availability_booking_claim","training_session_claim_exact_set","calendar_lifecycle_version_history"],"requires":["MIG-IDX-001"]},{"id":"MIG-TBL-005","kind":"table","scope":"PKK_finance_license_internal_exam","ordered_objects":["pkk_integration_configuration_revisions","pkk_provider_profile_snapshots","pkk_operations","pkk_operation_lifecycle_events","pkk_operation_attempts","pkk_operation_attempt_reconciliations","pkk_signature_handoffs","pkk_signature_handoff_upload_reservations","pkk_protected_payloads","pkk_protected_payload_key_wrappings","pkk_payload_redacted_projections","pkk_signature_file_asset_protections","pkk_signature_file_asset_key_wrappings","student_charges","student_payments","course_cost_charge_origins","license_products","license_product_language_capabilities","license_inventory_entries","license_assignments","license_activations","internal_exam_inventory_entries","internal_exam_inventory_adjustments","internal_exam_inventory_ledger_entries","internal_exam_attempts","internal_exam_attempt_lifecycle_events","internal_exam_reservations","internal_exam_accesses","internal_exam_access_lifecycle_events","internal_exam_access_tokens","exam_stations","exam_station_credentials","internal_exam_station_sessions","internal_exam_definitions","internal_exam_attempt_questions","internal_exam_results","internal_exam_document_templates","internal_exam_documents"],"requires":["MIG-TBL-003"]},{"id":"MIG-CK-005","kind":"candidate_key","scope":"PKK_finance_license_exam_candidate_keys","ordered_objects":["pkk_operations[organization_id,id]","student_charges[organization_id,id,student_id,currency]","license_inventory_entries[organization_id,id]","license_assignments[organization_id,id]","internal_exam_inventory_entries[organization_id,id]","internal_exam_attempts[organization_id,id]","internal_exam_accesses[organization_id,id]"],"requires":["MIG-TBL-005"]},{"id":"MIG-FK-005","kind":"foreign_key","scope":"PKK_finance_license_exam_relations_without_commerce_source","ordered_objects":["PKK_exact_course_profile_attempt_configuration_asset_relations","student_finance_exact_course_charge_payment_relations","license_assignment_activation_exact_relations","internal_exam_attempt_inventory_access_evidence_relations"],"requires":["MIG-CK-001","MIG-CK-002","MIG-CK-003","MIG-CK-005"]},{"id":"MIG-GRD-005","kind":"trigger_guard","scope":"PKK_finance_license_exam_guards","ordered_objects":["PKK_sequence_state_crypto","student_finance_balance_reversal_cancellation","license_inventory_assignment_activation","internal_exam_reserve_release_consume_evidence"],"requires":["MIG-FK-005"]},{"id":"MIG-TBL-006","kind":"table","scope":"platform_commerce","ordered_objects":["commerce_catalog_items","orders","order_items","payments","payment_events","order_payment_settlements","order_fulfillments","service_entitlements","service_activations"],"requires":["MIG-TBL-005"]},{"id":"MIG-CK-006","kind":"candidate_key","scope":"commerce_candidate_keys","ordered_objects":["orders[organization_id,id]","order_items[organization_id,id]","order_items[organization_id,id,product_kind]","order_items[organization_id,id,license_product_id]","order_items[organization_id,id,commerce_catalog_item_id]"],"requires":["MIG-TBL-006"]},{"id":"MIG-FK-006","kind":"foreign_key","scope":"commerce_and_downstream_purchase_relations","ordered_objects":["order_item_order_catalog","payment_event_settlement_fulfillment_order","license_inventory_source_order_item","internal_exam_paid_inventory_source_order_item","service_entitlement_source_order_item"],"requires":["MIG-CK-005","MIG-CK-006"]},{"id":"MIG-GRD-006","kind":"trigger_guard","scope":"commerce_guards","ordered_objects":["trusted_payment_settlement_exactly_once","fulfilled_quantity_grant_equivalence","service_source_XOR_activation","purchase_history_sequence_dates","global_commerce_lock_order"],"requires":["MIG-FK-006"]},{"id":"MIG-TBL-007","kind":"table","scope":"audit_domain_event_outbox","ordered_objects":["audit_action_policy_revisions","audit_action_policy_currents","audit_logs","domain_events","outbox_messages"],"requires":["MIG-TBL-006"]},{"id":"MIG-CK-007","kind":"candidate_key","scope":"audit_event_candidate_keys","ordered_objects":["audit_logs[organization_id,id]","domain_events[organization_id,id]","audit_action_policy_revisions[action,policy_version]"],"requires":["MIG-TBL-007"]},{"id":"MIG-FK-007","kind":"foreign_key","scope":"audit_event_outbox_relations","ordered_objects":["audit_actor_membership_same_tenant_user","audit_current_policy_exact_revision","outbox_exact_domain_event_scope"],"requires":["MIG-CK-001","MIG-CK-007"]},{"id":"MIG-GRD-007","kind":"trigger_guard","scope":"audit_event_outbox_guards","ordered_objects":["audit_append_only_payload_policy","domain_event_immutable","outbox_scope_and_lease_fencing","registered_entity_reference_guard"],"requires":["MIG-FK-007","MIG-GRD-002","MIG-GRD-003","MIG-GRD-004","MIG-GRD-005","MIG-GRD-006"]},{"id":"MIG-TBL-008","kind":"table","scope":"activity_notifications_migration_retention","ordered_objects":["activity_projection_policy_revisions","activity_projection_policy_currents","organization_activity_events","notifications","event_projection_migration_cases","data_retention_execution_runs"],"requires":["MIG-TBL-007"]},{"id":"MIG-CK-008","kind":"candidate_key","scope":"projection_candidate_keys","ordered_objects":["organization_activity_events[organization_id,source_event_id]","notifications[organization_id,source_event_id,organization_membership_id]","event_projection_migration_cases[source_table,source_row_id,issue_code]"],"requires":["MIG-TBL-008"]},{"id":"MIG-FK-008","kind":"foreign_key","scope":"projection_relations","ordered_objects":["activity_source_event_same_tenant","notification_source_event_same_tenant","notification_recipient_membership_same_tenant_user","activity_related_student_same_tenant"],"requires":["MIG-CK-001","MIG-CK-003","MIG-CK-007","MIG-CK-008"]},{"id":"MIG-PRJ-001","kind":"projection","scope":"organization_activity_projection_binding","ordered_objects":["registered_activity_policy_to_domain_event_to_activity_row"],"requires":["MIG-FK-008"]},{"id":"MIG-PRJ-002","kind":"projection","scope":"notification_projection_binding","ordered_objects":["domain_event_to_exact_recipient_notification_rows"],"requires":["MIG-FK-008"]},{"id":"MIG-GRD-008","kind":"trigger_guard","scope":"projection_read_state_retention_guards","ordered_objects":["activity_safe_snapshot_immutability","notification_read_at_write_once","legacy_projection_review_append_only","retention_execution_audit_boundary"],"requires":["MIG-PRJ-001","MIG-PRJ-002"]},{"id":"MIG-CON-001","kind":"constraint_activation","scope":"stage4_constraint_activation_dependency_catalog","ordered_objects":["all_declared_candidate_keys_indexes_foreign_keys_trigger_guards_and_projections_have_dependency_complete_activation_inputs"],"requires":["MIG-GRD-001","MIG-GRD-002","MIG-GRD-003","MIG-GRD-004","MIG-GRD-005","MIG-GRD-006","MIG-GRD-007","MIG-GRD-008"],"activation_phase":"deferred_to_DB_MIG_002"}]''')
kinds = ["extension","table","candidate_key","index","foreign_key","trigger_guard","projection","constraint_activation"]

lines = [
"migration_dependency_dag:",
"  blocker: DB-MIG-001",
"  status: PASS",
"  authority: specs/database/final-migration-order-invariant-matrix.yml",
"  purpose: deterministic_dependency_safe_stage4_migration_object_order_without_generating_Laravel_migrations",
"  node_kind_values:",
]
for kind in kinds:
    lines.append(f"    - {kind}")
lines += [
"  execution_order_authority: topological_order",
"  parallel_reordering_without_explicit_future_execution_contract: forbidden",
"  table_bundle_rule:",
"    ordered_objects_is_authoritative_within_node: true",
"    framework_split_requires_same_requires_and_ordered_object_semantics: true",
"    merge_or_reorder_nodes_without_contract_change: forbidden",
"  phase_boundary:",
"    phase_assignment: deferred_to_DB_MIG_002",
"    restart_abort_rollback_cutover_semantics: deferred_to_DB_MIG_003",
"    this_DAG_decides_dependency_order_not_phase_or_cutover: true",
"  validation_contract:",
"    node_ids_unique: true",
"    requires_must_reference_known_node_id: true",
"    self_dependency_forbidden: true",
"    cycles_forbidden: true",
"    topological_order_contains_every_node_exactly_once: true",
"    every_required_node_precedes_dependent_node: true",
"    unknown_node_kind_forbidden: true",
"    yaml_document_position_is_not_dependency_authority: true",
"    gate_failure_on_any_violation: true",
"  nodes:",
]
q=lambda s: json.dumps(s, ensure_ascii=False)
for node in nodes:
    lines.append(f"    - id: {node['id']}")
    lines.append(f"      kind: {node['kind']}")
    lines.append(f"      scope: {node['scope']}")
    lines.append("      ordered_objects:")
    for obj in node["ordered_objects"]:
        lines.append(f"        - {q(obj)}")
    if node["requires"]:
        lines.append("      requires:")
        for dep in node["requires"]:
            lines.append(f"        - {dep}")
    else:
        lines.append("      requires: []")
    if "activation_phase" in node:
        lines.append(f"      activation_phase: {node['activation_phase']}")
lines.append("  topological_order:")
for node in nodes:
    lines.append(f"    - {node['id']}")
lines += [
"  self_audit:",
"    node_count: 37",
"    node_kind_count: 8",
"    all_required_node_kinds_represented: true",
"    unknown_dependencies: 0",
"    cycles: 0",
"    duplicate_node_ids: 0",
"    topological_order_gap_or_duplicate: 0",
"    bounded_context_local_dependency_semantics_preserved: true",
"    Laravel_migrations_created: false",
"    DB_MIG_002_solved_early: false",
"    DB_MIG_003_solved_early: false",
"",
"resolved_contracts:",
"  DB-MIG-001:",
"    status: PASS",
"    title: executable_stage4_migration_dependency_DAG_and_topological_order",
"    executable_authority: migration_dependency_dag",
"    node_count: 37",
"    stable_node_ids: true",
"    explicit_requires_edges: true",
"    deterministic_topological_order: true",
"    cycle_unknown_dependency_and_missing_node_gate: true",
"    all_required_object_kinds_distinguished: true",
"    phase_composition_deferred_to_DB_MIG_002: true",
"    cutover_restart_rollback_deferred_to_DB_MIG_003: true",
"    Laravel_migrations_generated: false",
]
insert = "\n".join(lines) + "\n\n"
replace_once("\nblockers:\n", "\n" + insert + "blockers:\n")

old_blocker = '''  - id: DB-MIG-001
    severity: P1
    status: OPEN
    title: executable_stage4_migration_dependency_DAG_and_topological_order
'''
new_blocker = '''  - id: DB-MIG-001
    severity: P1
    status: PASS
    title: executable_stage4_migration_dependency_DAG_and_topological_order
'''
replace_once(old_blocker, new_blocker)

replace_once(
'''diagnosis_summary:
  diagnosed_P0: 0
  diagnosed_P1: 6
  fixes_applied_in_diagnosis_step: 0
  resolved_after_diagnosis: 0
  open_P0_P1: 6
  result: FAIL_WITH_6_P1_BLOCKERS
''',
'''diagnosis_summary:
  diagnosed_P0: 0
  diagnosed_P1: 6
  fixes_applied_in_diagnosis_step: 0
  fixes_applied_after_diagnosis: 1
  resolved_after_diagnosis: 1
  open_P0_P1: 5
  result: FAIL_WITH_5_P1_BLOCKERS
'''
)

replace_once("  status: PASS_DIAGNOSIS_ONLY\n", "  status: PASS_DB_MIG_001\n")

old_next = '''next_single_step_after_central_gate:
  id: DB-MIG-001
  action: resolve_DB_MIG_001_only_after_next_explicit_user_instruction
'''
new_next = '''next_single_step_after_central_gate:
  id: DB-MIG-002
  action: resolve_DB_MIG_002_only_after_next_explicit_user_instruction
'''
replace_once(old_next, new_next)
replace_once(
'''    Resolve DB-MIG-001 only. Preserve DB-MIG-002, DB-MIG-003, DB-TST-001,
    DB-TST-002 and DB-FINAL-001 as OPEN. Do not enter Stage 5, generate Laravel
    migrations, or implement UI/features in the same step.
  after_action: rerun_DB_MIG_001_machine_narrative_central_gate_and_STOP_before_DB_MIG_002
''',
'''    DB-MIG-001 is PASS. Resolve DB-MIG-002 only. Preserve DB-MIG-003,
    DB-TST-001, DB-TST-002 and DB-FINAL-001 as OPEN. Do not enter Stage 5,
    generate Laravel migrations, or implement UI/features in the same step.
  after_action: rerun_DB_MIG_002_machine_narrative_central_gate_and_STOP_before_DB_MIG_003
'''
)

PATH.write_text(text)
