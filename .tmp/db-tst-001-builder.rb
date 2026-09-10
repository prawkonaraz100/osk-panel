require 'yaml'
require 'date'
require 'digest'

MACHINE = 'specs/database/final-migration-order-invariant-matrix.yml'
CORE = 'specs/database/core-schema.yml'
NARR = 'docs/116-stage-4-final-migration-order-invariant-matrix-audit.md'
CENTRAL = 'specs/gates/stage-4-database-contract-gate.yml'

EXPECTED = {
  MACHINE => 'df06baca7947d74e04adc5912fb40fdb972fa38e',
  CORE => '39958721c99550cfc27c3774e8dfa1af0d0637e6',
  NARR => '5668ed5d3e555953e8dafa3b37ad68ddd447089a',
  CENTRAL => 'bcf645967d19a0c02a4db4cd13c8fa046d36a954',
  'docs/87-physical-database-schema.md' => '6c090b082604b6b42c283667fcf08e9033a5b523'
}.freeze

EXPECTED.each do |path, sha|
  actual = `git hash-object #{path}`.strip
  raise "blob drift #{path}: #{actual} != #{sha}" unless actual == sha
end

core = YAML.safe_load(File.read(CORE), permitted_classes: [Date], aliases: true)
occurrences = []
walk = lambda do |obj, path|
  case obj
  when Hash
    obj.each do |k, v|
      p2 = path + [k.to_s]
      if k.to_s == 'migration_tests_required'
        raise "migration_tests_required is not an array at #{p2.join('.')}" unless v.is_a?(Array)
        v.each_with_index do |item, idx|
          raise "non-string migration test at #{p2.join('.')}[#{idx}]" unless item.is_a?(String) && !item.strip.empty?
          occurrences << { text: item.strip, path: p2.join('.'), index: idx }
        end
      end
      walk.call(v, p2)
    end
  when Array
    obj.each_with_index { |v, i| walk.call(v, path + [i.to_s]) }
  end
end
walk.call(core, [])
raise 'no migration_tests_required found' if occurrences.empty?

unique = []
by_text = {}
occurrences.each do |o|
  unless by_text.key?(o[:text])
    by_text[o[:text]] = { text: o[:text], refs: [] }
    unique << by_text[o[:text]]
  end
  by_text[o[:text]][:refs] << "#{CORE}::#{o[:path]}[#{o[:index]}]"
end

def domain_code(text, refs)
  s = ([text] + refs).join(' ').downcase
  return 'PKK' if s.include?('pkk_') || s.include?('.pkk')
  return 'EXAM' if s.include?('internal_exam') || s.include?('exam_station')
  return 'LIC' if s.include?('license_')
  return 'CAL' if s.include?('calendar_') || s.include?('availability_')
  return 'FIN' if s.include?('student_finance') || s.include?('student_charge') || s.include?('student_payment')
  return 'COM' if s.include?('commerce_') || s.include?('order_') || s.include?('payment_event') || s.include?('settlement') || s.include?('fulfillment') || s.include?('service_entitlement') || s.include?('service_activation')
  return 'AUD' if s.include?('audit_')
  return 'EVT' if s.include?('domain_event') || s.include?('outbox') || s.include?('notification') || s.include?('activity_projection') || s.include?('organization_activity')
  return 'TRN' if s.include?('training_') || s.include?('course_') || s.include?('student_learning') || s.include?('student_access')
  return 'RES' if s.include?('staff_') || s.include?('vehicle_') || s.include?('location_')
  return 'IAM' if s.include?('organization_') || s.include?('membership_') || s.include?('permission_') || s.include?('auth_') || s.include?('user_') || s.include?('tenant_')
  'CORE'
end

def test_class(text)
  s = text.downcase
  return 'concurrency' if s.match?(/concurr|\brace\b|serialize|simultaneous|double[_ -](spend|grant|consume|reserve|claim|record)|lost[_ -]update/)
  return 'migration_preflight' if s.match?(/preflight|legacy|backfill|reconcil|unresolved|migration_case|migration_review|exact_evidence|ambiguous/)
  return 'migration_postcheck' if s.match?(/postcheck|post_migration|after_migration|validate_constraint|validated_constraint|migration.*final|final.*migration/)
  return 'security_storage' if s.match?(/cipher|plaintext|secret|credential|encrypt|hmac|hash|redact|sensitive|wrong_tenant|tenant.*reject|authorization|permission|cross_tenant/)
  return 'projection' if s.match?(/projection|outbox|publisher|domain_event|notification|activity_event|dedupe/)
  return 'schema_constraint' if s.match?(/foreign_key|candidate_key|unique|constraint|exclusion|not_null|hard_delete|append_only|write_once|reject_mismatch|same_tenant|check_/)
  'transaction'
end

CLASS_META = {
  'schema_constraint' => ['post_schema', 'FP-SCHEMA-NEGATIVE', 'none', 'invalid_tuple_or_mutation_is_rejected_and_valid_control_case_succeeds'],
  'transaction' => ['runtime_integration', 'FP-TRANSACTION-ATOMICITY', 'none', 'command_preserves_declared_atomicity_state_and_history_invariant'],
  'concurrency' => ['runtime_concurrency', 'FP-CONCURRENCY-RACE', 'CP-TWO-TRANSACTIONS', 'all_interleavings_preserve_one_legal_final_state_without_duplicate_or_lost_effect'],
  'migration_preflight' => ['migration_preflight', 'FP-MIGRATION-LEGACY', 'none', 'legacy_population_is_classified_by_exact_evidence_and_ambiguous_cases_fail_closed'],
  'migration_postcheck' => ['migration_postcheck', 'FP-MIGRATION-POSTCHECK', 'none', 'post_migration_state_satisfies_final_invariant_with_no_invalid_partial_artifact'],
  'projection' => ['projection_validation', 'FP-PROJECTION-REPLAY', 'CP-RETRY-OR-REPLAY', 'canonical_source_projection_and_dedupe_semantics_remain_consistent_under_retry_or_replay'],
  'security_storage' => ['security_storage_validation', 'FP-SECURITY-NEGATIVE', 'none', 'forbidden_access_or_storage_shape_fails_closed_without_secret_or_cross_tenant_leakage']
}.freeze

per_domain = Hash.new(0)
entries = unique.map do |u|
  domain = domain_code(u[:text], u[:refs])
  per_domain[domain] += 1
  id = format('DBT-%s-%03d', domain, per_domain[domain])
  klass = test_class(u[:text])
  execution_phase, fixture, concurrency, expected = CLASS_META.fetch(klass)
  {
    'test_id' => id,
    'source_invariant' => u[:text],
    'source_refs' => u[:refs],
    'test_class' => klass,
    'execution_phase' => execution_phase,
    'blocking_severity' => 'P1',
    'expected_outcome' => expected,
    'fixture_profile' => fixture,
    'concurrency_profile' => concurrency,
    'implementation_status' => 'contract_only_no_framework_test_generated'
  }
end

ids = entries.map { |e| e['test_id'] }
raise 'duplicate test id' unless ids.uniq.length == ids.length
allowed_classes = CLASS_META.keys.sort
raise 'unknown class' unless entries.all? { |e| allowed_classes.include?(e['test_class']) }
raise 'missing source refs' unless entries.all? { |e| e['source_refs'].is_a?(Array) && !e['source_refs'].empty? }
raise 'not every unique source invariant represented exactly once' unless entries.map { |e| e['source_invariant'] }.sort == unique.map { |u| u[:text] }.sort

counts = entries.group_by { |e| e['test_class'] }.transform_values(&:length)
domain_counts = entries.group_by { |e| e['test_id'].split('-')[1] }.transform_values(&:length)

matrix = {
  'invariant_test_matrix' => {
    'authority' => 'DB-TST-001',
    'status' => 'PASS',
    'purpose' => 'stable_machine_readable_stage4_invariant_test_contract',
    'source_boundary' => {
      'aggregate_source' => CORE,
      'source_key' => 'migration_tests_required',
      'source_occurrences' => occurrences.length,
      'unique_source_invariants' => unique.length,
      'duplicate_source_occurrences_collapsed_into_source_refs' => occurrences.length - unique.length,
      'local_bounded_context_completeness_proof_deferred_to_DB_TST_002' => true,
      'resolved_blocker_to_test_coverage_mapping_deferred_to_DB_TST_002' => true,
      'critical_constraint_and_transaction_guard_coverage_join_deferred_to_DB_TST_002' => true
    },
    'stable_id_contract' => {
      'format' => 'DBT-<DOMAIN>-NNN',
      'assigned_in_source_first_occurrence_order' => true,
      'renumber_existing_ids_after_DB_TST_001' => 'forbidden_without_explicit_authority_migration',
      'new_tests_append_next_free_domain_sequence' => true
    },
    'allowed_test_classes' => allowed_classes,
    'blocking_severity_semantics' => {
      'allowed_values' => ['P1'],
      'P1' => 'failure_blocks_stage4_database_contract_acceptance_not_a_production_incident_severity_label'
    },
    'execution_phase_values' => ['post_schema','runtime_integration','runtime_concurrency','migration_preflight','migration_postcheck','projection_validation','security_storage_validation'],
    'fixture_profiles' => {
      'FP-SCHEMA-NEGATIVE' => 'minimal_valid_control_plus_minimal_invalid_tuple_or_mutation_that_targets_exact_named_constraint_or_relation',
      'FP-TRANSACTION-ATOMICITY' => 'valid_prestate_plus_one_business_command_with_success_and_forced_failure_observation_of_all_durable_rows',
      'FP-CONCURRENCY-RACE' => 'same_authoritative_aggregate_or_competing_key_loaded by two independent database transactions with deterministic synchronization barrier',
      'FP-MIGRATION-LEGACY' => 'legacy population containing conforming exact_evidence_resolvable and ambiguous_or_conflicting rows without fabricated lineage',
      'FP-MIGRATION-POSTCHECK' => 'completed write_fence_backfill_reconcile fixture with final constraints guards and projections available for invariant verification',
      'FP-PROJECTION-REPLAY' => 'canonical source rows plus duplicate retry replay or out_of_order delivery where the bounded contract permits that shape',
      'FP-SECURITY-NEGATIVE' => 'authorized control context plus wrong_tenant forbidden_actor forbidden_plaintext_or_sensitive_payload negative context as applicable'
    },
    'concurrency_profiles' => {
      'none' => 'single_transaction_or_non_racing_contract_test',
      'CP-TWO-TRANSACTIONS' => 'two independent database connections synchronized immediately before competing write then both complete or one fails according to invariant',
      'CP-RETRY-OR-REPLAY' => 'same logical event_or_command delivered at least twice and when relevant in alternate ordering without duplicate durable effect'
    },
    'class_counts' => counts.sort.to_h,
    'domain_counts' => domain_counts.sort.to_h,
    'tests' => entries,
    'validation_gate' => {
      'unique_test_ids' => 'PASS',
      'every_test_has_source_invariant_and_nonempty_source_refs' => 'PASS',
      'every_test_has_allowed_test_class' => 'PASS',
      'every_test_has_expected_outcome' => 'PASS',
      'every_test_has_fixture_profile' => 'PASS',
      'every_concurrency_test_uses_non_none_concurrency_profile' => entries.select { |e| e['test_class'] == 'concurrency' }.all? { |e| e['concurrency_profile'] != 'none' } ? 'PASS' : 'FAIL',
      'no_framework_tests_generated' => 'PASS',
      'DB_TST_002_coverage_proof_not_closed_here' => 'PASS',
      'DB_FINAL_001_aggregate_sync_not_closed_here' => 'PASS'
    }
  }
}
raise 'concurrency profile validation failed' if matrix['invariant_test_matrix']['validation_gate']['every_concurrency_test_uses_non_none_concurrency_profile'] != 'PASS'

# YAML fragment without document marker.
fragment = YAML.dump(matrix).sub(/\A---\s*\n/, '')

text = File.read(MACHINE)
original = text.dup

def section(src, start_marker, end_marker)
  s = src.index(start_marker) or raise "missing #{start_marker.inspect}"
  e = src.index(end_marker, s) or raise "missing #{end_marker.inspect}"
  src[s...e]
end

def replace_once(src, old, new, label)
  n = src.scan(Regexp.new(Regexp.escape(old))).length
  raise "#{label}: expected 1 match got #{n}" unless n == 1
  src.sub(old, new)
end

dag_before = section(text, "migration_dependency_dag:\n", "\nmigration_phase_composition:\n")
phase_before = section(text, "migration_phase_composition:\n", "\nmigration_cutover_execution_contract:\n")
cutover_before = section(text, "migration_cutover_execution_contract:\n", "\nblockers:\n")

meta_end = text.index("\ncanonical_sources:\n")
head = text[0...meta_end]
head = replace_once(head, "  step: DB-MIG-003\n", "  step: DB-TST-001\n", 'meta step')
head = replace_once(head, "  status: FAIL_WITH_3_P1_BLOCKERS\n", "  status: FAIL_WITH_2_P1_BLOCKERS\n", 'meta status')
head = replace_once(head, "  fixes_applied_total: 3\n", "  fixes_applied_total: 4\n", 'meta fixes')
head = replace_once(head, "  current_step_scope: DB_MIG_003_only\n", "  current_step_scope: DB_TST_001_only\n", 'scope')
text = head + text[meta_end..]

raise 'matrix already present' if text.include?("\ninvariant_test_matrix:\n")
text = replace_once(text, "\nblockers:\n", "\n#{fragment.rstrip}\n\nblockers:\n", 'insert matrix')

blockers_start = text.index("\nblockers:\n")
resolved_start = text.index("\nresolved_contracts:\n", blockers_start)
blockers = text[blockers_start...resolved_start]
t1s = blockers.index("  - id: DB-TST-001\n")
t1e = blockers.index("\n  - id: DB-TST-002\n", t1s)
t1 = blockers[t1s...t1e]
t1 = replace_once(t1, "    status: OPEN\n", "    status: PASS\n", 'DB-TST-001 blocker')
blockers = blockers[0...t1s] + t1 + blockers[t1e..]
text = text[0...blockers_start] + blockers + text[resolved_start..]

resolved_start = text.index("\nresolved_contracts:\n")
diagnosis_start = text.index("\ndiagnosis_summary:\n", resolved_start)
resolved = text[resolved_start...diagnosis_start]
raise 'DB-TST-001 already resolved' if resolved.include?("  DB-TST-001:\n")
summary = <<~YAML

  DB-TST-001:
    status: PASS
    title: machine_readable_invariant_test_matrix
    machine_authority: invariant_test_matrix
    source_requirement_occurrences: #{occurrences.length}
    unique_final_tests: #{entries.length}
    stable_test_ids_defined: true
    allowed_test_classes: [schema_constraint, transaction, concurrency, migration_preflight, migration_postcheck, projection, security_storage]
    every_test_has_source_invariant_expected_outcome_fixture_and_execution_phase: true
    concurrency_tests_have_explicit_race_shape: true
    blocking_severity_defined: true
    framework_test_files_generated: false
    DB_TST_002_coverage_traceability_deferred: true
    DB_FINAL_001_aggregate_sync_deferred: true
YAML
resolved = resolved.rstrip + "\n" + summary
text = text[0...resolved_start] + resolved + text[diagnosis_start..]

diagnosis_start = text.index("\ndiagnosis_summary:\n")
preservation_start = text.index("\npreservation_gate:\n", diagnosis_start)
diagnosis = text[diagnosis_start...preservation_start]
diagnosis = replace_once(diagnosis, "  resolved_after_diagnosis: 3\n", "  resolved_after_diagnosis: 4\n", 'resolved count')
diagnosis = replace_once(diagnosis, "  open_P0_P1: 3\n", "  open_P0_P1: 2\n", 'open count')
diagnosis = replace_once(diagnosis, "  result: FAIL_WITH_3_P1_BLOCKERS\n", "  result: FAIL_WITH_2_P1_BLOCKERS\n", 'diagnosis result')
diagnosis = replace_once(diagnosis, "    - DB-TST-001\n", '', 'required order')
text = text[0...diagnosis_start] + diagnosis + text[preservation_start..]

preservation_start = text.index("\npreservation_gate:\n")
next_start = text.index("\nnext_single_step_after_central_gate:\n", preservation_start)
preservation = text[preservation_start...next_start]
preservation = replace_once(preservation, "  status: PASS_DB_MIG_003\n", "  status: PASS_DB_TST_001\n", 'pres status')
preservation = replace_once(preservation, "    DB_MIG_003_cutover_restart_failure_rollback_closed: true\n", "    DB_MIG_003_cutover_restart_failure_rollback_closed: true\n    DB_TST_001_machine_readable_invariant_test_matrix_closed: true\n", 'pres flag')
text = text[0...preservation_start] + preservation + text[next_start..]

next_start = text.index("\nnext_single_step_after_central_gate:\n")
nxt = text[next_start..]
nxt = replace_once(nxt, "  id: DB-TST-001\n", "  id: DB-TST-002\n", 'next id')
nxt = replace_once(nxt, "  action: resolve_DB_TST_001_only_after_next_explicit_user_instruction\n", "  action: resolve_DB_TST_002_only_after_next_explicit_user_instruction\n", 'next action')
scope_old = <<~YAML
  scope_lock: >-
    DB-MIG-001, DB-MIG-002 and DB-MIG-003 are PASS. Resolve DB-TST-001 only.
    Preserve DB-TST-002 and DB-FINAL-001 as OPEN. Do not modify core-schema.yml
    or docs/87, enter Stage 5, generate Laravel migrations, or implement UI/features.
  after_action: rerun_DB_TST_001_machine_narrative_central_gate_and_STOP_before_DB_TST_002
YAML
scope_new = <<~YAML
  scope_lock: >-
    DB-MIG-001, DB-MIG-002, DB-MIG-003 and DB-TST-001 are PASS. Resolve DB-TST-002 only.
    Preserve DB-FINAL-001 as OPEN. Do not modify core-schema.yml or docs/87, enter
    Stage 5, generate Laravel migrations, or implement UI/features.
  after_action: rerun_DB_TST_002_machine_narrative_central_gate_and_STOP_before_DB_FINAL_001
YAML
nxt = replace_once(nxt, scope_old, scope_new, 'next scope')
text = text[0...next_start] + nxt

# Preserve prior migration authorities byte-for-byte inside the file.
dag_after = section(text, "migration_dependency_dag:\n", "\nmigration_phase_composition:\n")
phase_after = section(text, "migration_phase_composition:\n", "\nmigration_cutover_execution_contract:\n")
cutover_after = section(text, "migration_cutover_execution_contract:\n", "\ninvariant_test_matrix:\n")
raise 'DB-MIG-001 DAG changed' unless dag_after == dag_before
raise 'DB-MIG-002 phase composition changed' unless phase_after == phase_before
raise 'DB-MIG-003 cutover contract changed' unless cutover_after == cutover_before

raise 'DB-TST-002 closed early' unless text.include?("  - id: DB-TST-002\n    severity: P1\n    status: OPEN\n")
raise 'DB-FINAL-001 closed early' unless text.include?("  - id: DB-FINAL-001\n    severity: P1\n    status: OPEN\n")
raise 'matrix missing' unless text.include?("\ninvariant_test_matrix:\n")
raise 'unexpected no-op' if text == original
File.write(MACHINE, text)

# Parse final YAML with Date allowed and perform structural assertions.
final = YAML.safe_load(File.read(MACHINE), permitted_classes: [Date], aliases: true)
m = final.fetch('invariant_test_matrix')
raise 'matrix status not PASS' unless m['status'] == 'PASS'
raise 'test count mismatch' unless m.fetch('tests').length == entries.length
raise 'test ids mismatch' unless m.fetch('tests').map { |t| t['test_id'] }.uniq.length == entries.length
raise 'DB-TST-001 status not PASS' unless final.fetch('blockers').find { |b| b['id'] == 'DB-TST-001' }['status'] == 'PASS'
raise 'DB-TST-002 not OPEN' unless final.fetch('blockers').find { |b| b['id'] == 'DB-TST-002' }['status'] == 'OPEN'
raise 'DB-FINAL-001 not OPEN' unless final.fetch('blockers').find { |b| b['id'] == 'DB-FINAL-001' }['status'] == 'OPEN'

puts "DB_TST_001_MACHINE_PASS source_occurrences=#{occurrences.length} unique_tests=#{entries.length} duplicates=#{occurrences.length - entries.length}"
puts "CLASS_COUNTS #{counts.sort.to_h}"
puts "DOMAIN_COUNTS #{domain_counts.sort.to_h}"
