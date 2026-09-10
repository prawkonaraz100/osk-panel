from pathlib import Path

p = Path('specs/database/final-migration-order-invariant-matrix.yml')
s = p.read_text()

def rep(old, new):
    global s
    n = s.count(old)
    if n != 1:
        raise SystemExit(f'expected one match, got {n}: {old[:100]!r}')
    s = s.replace(old, new, 1)

rep('  step: DB-MIG-001\n  status: FAIL_WITH_5_P1_BLOCKERS\n', '  step: DB-MIG-002\n  status: FAIL_WITH_4_P1_BLOCKERS\n')
rep('  fixes_applied_total: 1\n', '  fixes_applied_total: 2\n')
rep('  current_step_scope: DB_MIG_001_only\n', '  current_step_scope: DB_MIG_002_only\n')

phase = r'''migration_phase_composition:
  blocker: DB-MIG-002
  status: PASS_DB_MIG_002
  contract_version: 1
  scope: phase_composition_only
  dependency_order_authority: migration_dependency_dag
  cutover_restart_failure_and_rollback: deferred_to_DB_MIG_003
  phase_order:
    - expand
    - preflight
    - write_fence
    - backfill
    - reconcile
    - validate
    - contract
  phase_semantics:
    expand:
      purpose: add_forward_compatible_objects_columns_extensions_and_supporting_structures_without_removing_old_authority
      destructive_change: forbidden
    preflight:
      purpose: inventory_existing_rows_and_prove_prerequisites_before_installing_guards_that_cannot_tolerate_legacy_violations
      guessing_or_auto_reparenting: forbidden
    write_fence:
      purpose: enforce_the_new_write_contract_before_any_legacy_backfill_can_create_or_preserve_new_exceptions
      application_writer_must_obey_new_contract_after_fence: true
    backfill:
      purpose: populate_only_values_proven_by_exact_durable_evidence
      timestamp_name_amount_uuid_same_tenant_similarity_as_lineage_proof: forbidden
    reconcile:
      purpose: classify_unresolved_or_conflicting_legacy_rows_for_review_without_fabricating_business_truth
      unresolved_rows_auto_force_pass: forbidden
    validate:
      purpose: prove_old_population_and_new_writes_satisfy_the_final_invariant_before_authority_cleanup
      required_unresolved_P0_P1_before_validation: 0
    contract:
      purpose: deauthorize_or_remove_superseded_paths_only_after_successful_validation_and_required_evidence
      destructive_history_rewrite_or_delete: forbidden
  node_phase_resolution:
    algorithm: exact_override_else_node_type_default
    every_DAG_node_must_resolve_to_nonempty_phase_path: true
    resolved_path_must_follow_phase_order: true
    defaults_by_node_type:
      extension: [expand]
      table: [expand]
      candidate_key: [preflight, write_fence]
      index: [preflight, write_fence]
      foreign_key: [preflight, write_fence, validate]
      constraint: [preflight, write_fence, validate]
      trigger: [write_fence, validate]
      projection: [write_fence, backfill, reconcile, validate, contract]
    exact_overrides:
      MIG-EXT-BTREE-GIST:
        phases: [expand]
        reason: PostgreSQL_extension_must_exist_before_calendar_GiST_exclusion_objects
      MIG-IDX-CALENDAR_GIST:
        phases: [preflight, write_fence]
        reason: exclusion_conflicts_must_be_zero_before_install_and_exclusion_constraints_do_not_support_NOT_VALID
      MIG-FK-PURCHASE_DOWNSTREAM:
        phases: [preflight, write_fence, backfill, reconcile, validate]
        reason: DB4_9_exact_purchase_lineage_requires_exact_evidence_before_validation
      MIG-FK-EVENTS:
        phases: [preflight, write_fence, backfill, reconcile, validate]
        reason: DB4_10_legacy_source_actor_recipient_lineage_is_fail_closed
      MIG-TRG-EVENTS:
        phases: [write_fence, backfill, reconcile, validate]
        reason: new_audit_event_outbox_projection_rows_must_be_fenced_before_legacy_backfill
  PostgreSQL_activation_strategy:
    foreign_key_and_check_when_legacy_population_may_be_nonconforming:
      write_fence: ADD_CONSTRAINT_NOT_VALID_or_equivalent_when_PostgreSQL_supports_it
      backfill_and_reconcile: exact_evidence_only
      validate: VALIDATE_CONSTRAINT_only_after_zero_unresolved_required_cases
      claim_NOT_VALID_support_for_unique_or_exclusion: false
    candidate_key_or_unique_constraint:
      NOT_VALID_supported: false
      required_sequence:
        - preflight_duplicate_and_nullability_conflicts
        - require_zero_unresolved_conflicts
        - build_unique_index_or_constraint_as_new_write_fence
        - attach_constraint_using_index_when_contract_and_PostgreSQL_allow
      exact_CONCURRENTLY_operational_choice: deferred_to_implementation_and_DB_MIG_003_cutover_contract
    exclusion_constraint:
      NOT_VALID_supported: false
      required_sequence:
        - preflight_existing_conflicting_intervals
        - reconcile_without_auto_shift_cancel_reassign_or_winner_guess
        - require_zero_unresolved_conflicts
        - install_exclusion_constraint_as_write_fence
    trigger_or_deferrable_final_state_guard:
      install_phase: write_fence
      legacy_consistency_proof_phase: validate
      disabling_guard_to_make_backfill_pass: forbidden
    projection:
      required_sequence:
        - install_new_source_and_dedupe_write_fence
        - exact_evidence_backfill
        - reconcile_unresolved_rows
        - validate_source_dedupe_and_safe_snapshot_invariants
        - retire_old_projection_path_only_in_contract
  legacy_safety_cohorts:
    identity_and_tenant_lineage:
      source_contracts: [DB-IAM-001..005, DB-RES-001..006]
      write_fence_before_backfill: true
      exact_evidence_only: true
      unresolved_before_validate: zero
    students_training_calendar:
      source_contracts: [DB-TRN-001..008, DB-CAL-001..007]
      write_fence_before_backfill: true
      exact_evidence_only: true
      calendar_overlap_or_owner_winner_guess: forbidden
      unresolved_before_validate: zero
    PKK_execution_crypto:
      source_contracts: [DB-PKK-001..008]
      write_fence_before_backfill: true
      exact_provider_operation_configuration_crypto_lineage_required: true
      unresolved_before_validate: zero
    license_exam_inventory:
      source_contracts: [DB-LIC-001..007, DB-EXAM-001..008]
      write_fence_before_backfill: true
      inventory_assignment_activation_reservation_consumption_history_rewrite: forbidden
      unresolved_before_validate: zero
    student_finance_commerce:
      source_contracts: [DB-FIN-001..002, DB-COM-001..008]
      write_fence_before_backfill: true
      exact_payment_settlement_fulfillment_purchase_lineage_required: true
      regrant_to_make_quantity_match: forbidden
      unresolved_before_validate: zero
    audit_events_activity_notifications:
      source_contracts: [DB-AUD-001..002, DB-OUT-001..002, DB-ACT-001, DB-NOT-001..002, DB-EVT-001]
      write_fence_before_backfill: true
      exact_event_actor_recipient_policy_read_state_evidence_required: true
      guessed_event_or_recipient_or_read_state: forbidden
      unresolved_before_validate: zero
  node_phase_binding_validation:
    all_DAG_node_types_have_default_or_exact_mapping: true
    unmapped_DAG_nodes: 0
    unknown_phase_names: 0
    phase_order_violations: 0
    exact_override_node_ids_must_exist: true
    DB_MIG_001_topological_order_may_not_be_rewritten_by_phase_binding: true
  global_validation_gate:
    validation_requires_zero_unresolved_required_reconciliation_cases: true
    failed_preflight_or_reconciliation: STOP_not_force_pass
    local_bounded_context_migration_policy_remains_authoritative_for_evidence_quality: true
    old_path_retirement_before_validate: forbidden
    Stage5_or_Laravel_migration_generation_in_this_step: forbidden
  preservation_boundary:
    DB_MIG_001_DAG_unchanged: true
    DB_MIG_003_cutover_restart_failure_rollback_not_solved: true
    DB_TST_001_and_DB_TST_002_not_solved: true
    DB_FINAL_001_aggregate_sync_not_solved: true
    core_schema_and_docs87_remain_frozen: true

'''
rep('\nblockers:\n', '\n' + phase + 'blockers:\n')
rep('  - id: DB-MIG-002\n    severity: P1\n    status: OPEN\n', '  - id: DB-MIG-002\n    severity: P1\n    status: PASS\n')

resolved_add = r'''  DB-MIG-002:
    status: PASS
    title: expand_write_fence_backfill_validate_contract_phase_composition
    machine_authority: migration_phase_composition
    phase_order: [expand, preflight, write_fence, backfill, reconcile, validate, contract]
    every_DAG_node_has_machine_resolved_phase_path: true
    early_new_write_fence_before_legacy_backfill: true
    NOT_VALID_then_VALIDATE_used_only_where_PostgreSQL_supports_and_contract_requires: true
    unique_and_exclusion_NOT_VALID_false_claim_forbidden: true
    zero_unresolved_required_before_validate: true
    exact_evidence_fail_closed_backfill_preserved: true
    non_destructive_expand_contract_preserved: true
    DB_MIG_003_cutover_restart_rollback_deferred: true
    Laravel_migrations_generated: false

'''
rep('\ndiagnosis_summary:\n', '\n' + resolved_add + 'diagnosis_summary:\n')
rep('  resolved_after_diagnosis: 1\n  open_P0_P1: 5\n  result: FAIL_WITH_5_P1_BLOCKERS\n', '  resolved_after_diagnosis: 2\n  open_P0_P1: 4\n  result: FAIL_WITH_4_P1_BLOCKERS\n')
rep('    - DB-MIG-002\n    - DB-MIG-003\n', '    - DB-MIG-003\n')
rep('  status: PASS_DB_MIG_001\n', '  status: PASS_DB_MIG_002\n')
rep('    DB_MIG_001_executable_DAG_closed: true\n', '    DB_MIG_001_executable_DAG_closed: true\n    DB_MIG_002_phase_composition_closed: true\n')
rep('  id: DB-MIG-002\n  action: resolve_DB_MIG_002_only_after_next_explicit_user_instruction\n', '  id: DB-MIG-003\n  action: resolve_DB_MIG_003_only_after_next_explicit_user_instruction\n')
rep('    DB-MIG-001 is PASS. Resolve DB-MIG-002 only. Preserve DB-MIG-003, DB-TST-001,\n    DB-TST-002 and DB-FINAL-001 as OPEN. Do not enter Stage 5, generate Laravel\n    migrations, or implement UI/features in the same step.\n  after_action: rerun_DB_MIG_002_machine_narrative_central_gate_and_STOP_before_DB_MIG_003\n', '    DB-MIG-001 and DB-MIG-002 are PASS. Resolve DB-MIG-003 only. Preserve\n    DB-TST-001, DB-TST-002 and DB-FINAL-001 as OPEN. Do not enter Stage 5,\n    generate Laravel migrations, or implement UI/features in the same step.\n  after_action: rerun_DB_MIG_003_machine_narrative_central_gate_and_STOP_before_DB_TST_001\n')
p.write_text(s)
