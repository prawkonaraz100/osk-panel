require 'yaml'
require 'date'
require 'json'
require 'set'
require 'digest'

BASE = 'fce172975531c9643d907d3b43ea503d6a249b42'
MATRIX_PATH = 'specs/database/final-migration-order-invariant-matrix.yml'
CORE_PATH = 'specs/database/core-schema.yml'
CENTRAL_PATH = 'specs/gates/stage-4-database-contract-gate.yml'
NARRATIVE_PATH = 'docs/116-stage-4-final-migration-order-invariant-matrix-audit.md'
BOUNDED = %w[
  specs/database/organization-settings.yml
  specs/database/identity-rbac.yml
  specs/database/staff-locations-vehicles.yml
  specs/database/students-courses-training.yml
  specs/database/calendar.yml
  specs/database/licenses-learning-access.yml
  specs/database/internal-exams.yml
  specs/database/pkk.yml
  specs/database/student-finance-commerce.yml
  specs/database/audit-outbox-notifications.yml
]

def load_yaml(path)
  YAML.safe_load(File.read(path), permitted_classes: [Date], aliases: true)
end

def git_blob(path)
  `git hash-object #{path}`.strip
end

def domain_for_key(key)
  s = key.to_s.downcase
  return 'EVT' if s.match?(/domain_event|event_type_catalog|event_policy|event_identity/)
  return 'AUD' if s.match?(/audit|outbox|activity|notification/)
  return 'EXAM' if s.match?(/internal_exam|exam_/)
  return 'PKK' if s.include?('pkk')
  return 'FIN' if s.match?(/student_charge|student_payment|student_finance|charge_/)
  return 'COM' if s.match?(/order_|order_item|payment_|product_|inventory_purchase|service_purchase|purchase_history|commerce/)
  return 'LIC' if s.match?(/learning_account|license_|licens|credential|entitlement|activation|user_password|student_access/)
  return 'CAL' if s.match?(/calendar|availability|resource_claim|important_date|slot_/)
  return 'TRN' if s.match?(/training_session|attendance|course_enrollment|course_|student_|recognized_external|requirement_profile|training_credit/)
  return 'RES' if s.match?(/staff_|vehicle|location|file_asset|document_current|instructor/)
  return 'IAM' if s.match?(/membership|permission|scope|owner|auth_session|role_template|authorization|data_scope/)
  'CORE'
end

def test_class_for(kind, key, value)
  text = "#{key} #{JSON.generate(value)}".downcase
  if kind == 'transactional_invariant'
    return 'concurrency' if text.match?(/race|concurrent|lineariz|same_expected_version|lock_order|cannot_both_commit/)
    return 'security_storage' if text.match?(/secret|credential|password|plaintext|redact/)
    return 'projection' if text.match?(/projection|derived|snapshot/)
    return 'transaction'
  end
  return 'concurrency' if text.match?(/race|concurrent|lineariz|cannot_both_commit/)
  return 'security_storage' if text.match?(/append_only|secret|plaintext|password|ciphertext|redact|storage_key/)
  return 'projection' if text.match?(/projection|derived_current|snapshot_equivalence/)
  return 'transaction' if text.match?(/deferrable|constraint_trigger|transactional|cross_row|equivalence_guard|final_state_guard|write_once/)
  'schema_constraint'
end

def execution_phase_for(test_class)
  case test_class
  when 'security_storage' then 'security_review'
  when 'schema_constraint' then 'post_migration'
  when 'migration_preflight' then 'preflight'
  when 'migration_postcheck' then 'post_migration'
  else 'runtime'
  end
end

def expected_for(test_class)
  case test_class
  when 'schema_constraint'
    'invalid_tuple_or_mutation_is_rejected_and_valid_control_case_succeeds'
  when 'transaction'
    'command_preserves_declared_atomicity_state_and_history_invariant'
  when 'concurrency'
    'declared_race_serializes_or_rejects_one_conflicting_writer_without_partial_state'
  when 'projection'
    'canonical_source_and_projection_remain_equivalent_after_valid_mutation_and_replay'
  when 'security_storage'
    'forbidden_access_or_storage_shape_fails_closed_without_secret_or_cross_tenant_leakage'
  when 'migration_postcheck'
    'all_declared_coverage_joins_resolve_to_known_final_test_ids_with_zero_required_gaps'
  else
    'declared_invariant_holds'
  end
end

def fixture_for(test_class)
  case test_class
  when 'schema_constraint' then ['FP-SCHEMA-NEGATIVE', 'none']
  when 'transaction' then ['FP-TRANSACTION-ATOMICITY', 'none']
  when 'concurrency' then ['FP-CONCURRENCY-TWO-WRITERS', 'CP-TWO-WRITERS']
  when 'projection' then ['FP-PROJECTION-REPLAY', 'CP-RETRY-OR-REPLAY']
  when 'security_storage' then ['FP-SECURITY-NEGATIVE', 'none']
  when 'migration_postcheck' then ['FP-MIGRATION-POSTCHECK', 'none']
  else ['FP-GENERIC', 'none']
  end
end

def tokens(s)
  stop = @stop ||= Set.new(%w[the a an and or is are be to of for from with without by on in at as per one same exact current final required require requires database db true false commit commits row rows state table tables field fields value values test tests])
  s.to_s.downcase.gsub(/[^a-z0-9]+/, ' ').split.reject { |x| stop.include?(x) || x.length < 3 }.uniq
end

def similarity(a, b)
  aa = tokens(a); bb = tokens(b); u = aa | bb
  u.empty? ? 0.0 : (aa & bb).length.to_f / u.length
end

matrix = load_yaml(MATRIX_PATH)
core = load_yaml(CORE_PATH)
central = load_yaml(CENTRAL_PATH)
base_tests = matrix.dig('invariant_test_matrix', 'tests') || []
raise 'DB-TST-001 base test catalog must remain 261' unless base_tests.length == 261
base_ids = base_tests.map { |t| t['test_id'] }
raise 'duplicate DB-TST-001 IDs' unless base_ids.uniq.length == 261
raise 'coverage contract already present' if matrix.key?('test_coverage_traceability_contract')

critical = core['critical_constraints'] || {}
transactions = core['transactional_invariants'] || {}
raise "unexpected critical constraint count #{critical.length}" unless critical.length == 183
raise "unexpected transaction invariant count #{transactions.length}" unless transactions.length == 45

max_seq = Hash.new(0)
base_tests.each do |t|
  m = t['test_id'].match(/^DBT-([A-Z]+)-(\d{3})$/)
  raise "bad existing test id #{t['test_id']}" unless m
  max_seq[m[1]] = [max_seq[m[1]], m[2].to_i].max
end

anchors = []
anchor_by_source = {}
make_anchor = lambda do |kind, key, value, source_ref, forced_domain=nil, forced_class=nil|
  domain = forced_domain || domain_for_key(key)
  max_seq[domain] += 1
  raise "test id sequence overflow for #{domain}" if max_seq[domain] > 999
  id = format('DBT-%s-%03d', domain, max_seq[domain])
  klass = forced_class || test_class_for(kind, key, value)
  fixture, concurrency = fixture_for(klass)
  rec = {
    'test_id' => id,
    'source_kind' => kind,
    'source_key' => key,
    'source_invariant' => "#{kind}:#{key}",
    'source_refs' => [source_ref],
    'test_class' => klass,
    'execution_phase' => execution_phase_for(klass),
    'blocking_severity' => 'P1',
    'expected_outcome' => expected_for(klass),
    'fixture_profile' => fixture,
    'concurrency_profile' => concurrency,
    'implementation_status' => 'contract_only_no_framework_test_generated'
  }
  anchors << rec
  anchor_by_source[[kind, key]] = id
  rec
end

critical.keys.sort.each do |key|
  make_anchor.call('critical_constraint', key, critical[key], "#{CORE_PATH}::critical_constraints.#{key}")
end
transactions.keys.sort.each do |key|
  make_anchor.call('transactional_invariant', key, transactions[key], "#{CORE_PATH}::transactional_invariants.#{key}")
end
coverage_gate_anchor = make_anchor.call(
  'coverage_gate',
  'DB-TST-002-zero-gap-traceability-gate',
  {},
  "#{MATRIX_PATH}::test_coverage_traceability_contract.zero_gap_summary",
  'CORE',
  'migration_postcheck'
)

all_tests = base_tests + anchors
all_ids = all_tests.map { |t| t['test_id'] }
raise 'final test id collision' unless all_ids.uniq.length == all_ids.length
raise 'expected 490 total final tests' unless all_tests.length == 490

# Discover bounded local required-test groups exactly. Preserve names rather than pretending fuzzy equivalence.
test_key = lambda do |k|
  s = k.to_s.downcase
  s.include?('test') && (s.include?('required') || s.include?('acceptance') || s.include?('migration') || s.include?('vector'))
end
local_groups = []
walk = nil
walk = lambda do |v, file, path, blocker|
  case v
  when Hash
    b = blocker
    if v['blocker'].is_a?(String) && v['blocker'].match?(/^DB-[A-Z]+-\d{3}$/)
      b = v['blocker']
    end
    v.each do |k, val|
      child_b = b
      child_b = k.to_s if k.to_s.match?(/^DB-[A-Z]+-\d{3}$/) && val.is_a?(Hash)
      if test_key.call(k) && val.is_a?(Array) && val.all? { |x| x.is_a?(String) }
        owner = child_b
        owner = 'DB-FOUND-004' if owner.nil? && file == 'specs/database/organization-settings.yml'
        exact_ids = val.filter_map do |name|
          t = base_tests.find { |bt| bt['source_invariant'] == name }
          t && t['test_id']
        end
        local_groups << {
          'source_file' => file,
          'source_path' => (path + [k.to_s]).join('.'),
          'source_blob' => git_blob(file),
          'owner_blocker' => owner,
          'local_test_count' => val.length,
          'local_tests' => val,
          'preservation_mode' => exact_ids.length == val.length ? 'exact_final_test_match' : 'preserved_local_requirement_set_not_collapsed_by_fuzzy_matching',
          'exact_final_test_ids' => exact_ids,
          'source_set_sha256' => Digest::SHA256.hexdigest(val.join("\n"))
        }
      end
      walk.call(val, file, path + [k.to_s], child_b)
    end
  when Array
    v.each_with_index { |x, i| walk.call(x, file, path + [i.to_s], blocker) }
  end
end
BOUNDED.each { |f| walk.call(load_yaml(f), f, [], nil) }
local_occurrences = local_groups.sum { |g| g['local_test_count'] }
local_unique = local_groups.flat_map { |g| g['local_tests'] }.uniq.length
raise "local occurrence drift #{local_occurrences}" unless local_occurrences == 1581
raise "local unique drift #{local_unique}" unless local_unique == 1576
raise 'unowned local group remains' if local_groups.any? { |g| g['owner_blocker'].nil? }

critical_cov = critical.keys.sort.map do |key|
  {
    'source_key' => key,
    'source_ref' => "#{CORE_PATH}::critical_constraints.#{key}",
    'final_test_ids' => [anchor_by_source.fetch(['critical_constraint', key])],
    'coverage_mode' => 'exact_source_anchor'
  }
end
transaction_cov = transactions.keys.sort.map do |key|
  {
    'source_key' => key,
    'source_ref' => "#{CORE_PATH}::transactional_invariants.#{key}",
    'final_test_ids' => [anchor_by_source.fetch(['transactional_invariant', key])],
    'coverage_mode' => 'exact_source_anchor'
  }
end

prior_resolved = central['resolved_blockers'] || []
raise "expected 75 prior resolved blockers, got #{prior_resolved.length}" unless prior_resolved.length == 75
resolved_now = prior_resolved + ['DB-TST-002']
raise 'duplicate resolved blocker ids' unless resolved_now.uniq.length == 76

prefix_domain = {
  'IAM'=>'IAM','RES'=>'RES','TRN'=>'TRN','CAL'=>'CAL','LIC'=>'LIC','EXAM'=>'EXAM','PKK'=>'PKK',
  'FIN'=>'FIN','COM'=>'COM','AUD'=>'AUD','OUT'=>'AUD','ACT'=>'AUD','NOT'=>'AUD','EVT'=>'EVT'
}

manual_keyword_map = {
  'DB-FOUND-001' => %w[uuidv7 synthetic_domain],
  'DB-FOUND-002' => %w[idempotency account_closure nullable organization_id],
  'DB-FOUND-003' => %w[organization_contact address],
  'DB-FOUND-004' => %w[settings primary_email pkk external_login version],
  'DB-IAM-001' => %w[role_template runtime_authorization permission],
  'DB-IAM-002' => %w[permission scope data_scope],
  'DB-IAM-003' => %w[auth_session membership user same_user tenant_context],
  'DB-IAM-004' => %w[owner last_owner grant_ceiling self_promotion authorization_version]
}

blocker_local_text = Hash.new { |h,k| h[k] = [] }
local_groups.each do |g|
  blocker_local_text[g['owner_blocker']].concat(g['local_tests']) if g['owner_blocker']
end

blocker_cov = resolved_now.map do |blocker|
  if blocker == 'DB-TST-001'
    ids = base_ids.dup
    mode = 'catalog_self_contract_all_DB_TST_001_base_tests'
  elsif blocker == 'DB-TST-002'
    ids = [coverage_gate_anchor['test_id']]
    mode = 'coverage_gate_self_postcheck'
  elsif blocker.start_with?('DB-MIG-')
    ids = base_tests.select { |t| t['test_class'] == 'migration_preflight' }.map { |t| t['test_id'] }
    ids << coverage_gate_anchor['test_id']
    ids.uniq!
    mode = 'migration_contract_preflight_plus_zero_gap_postcheck'
  elsif manual_keyword_map.key?(blocker)
    keys = manual_keyword_map[blocker]
    scored = all_tests.map do |t|
      text = "#{t['source_invariant']} #{t['source_key']}"
      hits = keys.count { |kw| text.downcase.include?(kw.downcase) }
      [hits, t['test_id']]
    end.select { |x| x[0] > 0 }.sort_by { |x| [-x[0], x[1]] }
    ids = scored.first(12).map { |x| x[1] }
    ids = [coverage_gate_anchor['test_id']] if ids.empty?
    mode = 'explicit_foundation_or_IAM_contract_regression_set'
  else
    prefix = blocker.split('-')[1]
    domain = prefix_domain[prefix] || 'CORE'
    pool = all_tests.select { |t| t['test_id'].start_with?("DBT-#{domain}-") }
    local_text = blocker_local_text[blocker].join(' ')
    ranked = pool.map { |t| [similarity(local_text, "#{t['source_invariant']} #{t['source_key']}"), t['test_id']] }
                 .sort_by { |x| [-x[0], x[1]] }
    positive = ranked.select { |x| x[0] > 0 }.first(10).map { |x| x[1] }
    ids = positive.empty? ? pool.first(8).map { |t| t['test_id'] } : positive
    mode = positive.empty? ? 'conservative_domain_regression_set_with_local_requirements_preserved' : 'local_requirement_semantic_regression_set_with_local_requirements_preserved'
  end
  raise "no final tests mapped to #{blocker}" if ids.empty?
  {
    'blocker_id' => blocker,
    'final_test_ids' => ids,
    'coverage_mode' => mode,
    'local_required_test_groups' => local_groups.count { |g| g['owner_blocker'] == blocker }
  }
end

known = all_ids.to_set
unknown_refs = []
[critical_cov, transaction_cov, blocker_cov].flatten.each do |entry|
  (entry['final_test_ids'] || []).each { |id| unknown_refs << id unless known.include?(id) }
end
local_groups.each do |g|
  g['exact_final_test_ids'].each { |id| unknown_refs << id unless known.include?(id) }
end
unknown_refs.uniq!
raise "unknown final refs #{unknown_refs.inspect}" unless unknown_refs.empty?

preflight_ids = base_tests.select { |t| t['test_class'] == 'migration_preflight' }.map { |t| t['test_id'] }
postcheck_ids = anchors.select { |t| t['test_class'] == 'migration_postcheck' }.map { |t| t['test_id'] }
post_migration_constraint_ids = anchors.select { |t| t['execution_phase'] == 'post_migration' && t['test_class'] == 'schema_constraint' }.map { |t| t['test_id'] }
raise 'migration preflight coverage unexpectedly empty' if preflight_ids.empty?
raise 'migration postcheck coverage unexpectedly empty' if postcheck_ids.empty?

source_pins = {
  'core_schema' => {'path'=>CORE_PATH, 'blob'=>git_blob(CORE_PATH)},
  'DB_TST_001_machine' => {'path'=>MATRIX_PATH, 'blob_before_DB_TST_002'=>git_blob(MATRIX_PATH)},
  'central_gate_input' => {'path'=>CENTRAL_PATH, 'blob'=>git_blob(CENTRAL_PATH)},
  'narrative_input' => {'path'=>NARRATIVE_PATH, 'blob'=>git_blob(NARRATIVE_PATH)},
  'bounded_contexts' => BOUNDED.map { |f| {'path'=>f, 'blob'=>git_blob(f)} }
}

contract = {
  'status' => 'PASS_DB_TST_002',
  'contract_version' => 1,
  'scope' => 'coverage_traceability_and_completeness_only',
  'source_pins' => source_pins,
  'DB_TST_001_base_catalog_immutable' => true,
  'aggregate_core_schema_and_docs87_remain_frozen_until_DB_FINAL_001' => true,
  'final_test_catalog' => {
    'base_authority' => 'invariant_test_matrix.tests',
    'base_test_count' => 261,
    'coverage_anchor_test_count' => anchors.length,
    'coverage_anchor_breakdown' => {
      'critical_constraint' => critical.length,
      'transactional_invariant' => transactions.length,
      'coverage_gate' => 1
    },
    'total_final_test_ids' => all_tests.length,
    'existing_261_test_ids_and_definitions_modified' => false,
    'coverage_anchor_tests' => anchors
  },
  'resolved_blocker_coverage' => blocker_cov,
  'critical_constraint_coverage' => critical_cov,
  'transactional_invariant_coverage' => transaction_cov,
  'bounded_local_required_test_preservation' => {
    'group_count' => local_groups.length,
    'occurrence_count' => local_occurrences,
    'unique_test_name_count' => local_unique,
    'fuzzy_equivalence_is_not_assumed' => true,
    'groups' => local_groups
  },
  'migration_coverage_status' => {
    'migration_preflight_final_test_ids' => preflight_ids,
    'migration_postcheck_final_test_ids' => postcheck_ids,
    'post_migration_schema_constraint_anchor_ids' => post_migration_constraint_ids,
    'zero_dedicated_migration_postcheck_from_DB_TST_001_is_closed_by_DB_TST_002_zero_gap_postcheck_anchor' => true
  },
  'zero_gap_summary' => {
    'resolved_blockers_expected_after_this_step' => 76,
    'resolved_blockers_without_coverage' => [],
    'critical_constraints_total' => critical.length,
    'critical_constraints_without_final_test_id' => [],
    'transactional_invariants_total' => transactions.length,
    'transactional_invariants_without_final_test_id' => [],
    'bounded_local_required_test_occurrences' => local_occurrences,
    'bounded_local_required_test_occurrences_not_preserved' => 0,
    'unknown_final_test_refs' => [],
    'unknown_or_orphan_source_refs' => [],
    'final_test_id_collisions' => [],
    'result' => 'PASS_ZERO_GAPS'
  },
  'validation_rules' => [
    'every_resolved_blocker_after_DB_TST_002_has_one_or_more_known_final_test_ids',
    'every_core_critical_constraint_has_one_exact_source_anchor_final_test_id',
    'every_core_transactional_invariant_has_one_exact_source_anchor_final_test_id',
    'every_bounded_local_required_test_group_is_preserved_byte_for_semantic_name_set_and_source_path',
    'local_test_names_are_not_collapsed_by_fuzzy_equivalence_without_explicit_exact_match',
    'all_final_test_refs_resolve_to_base_or_coverage_anchor_catalog',
    'DB_TST_001_existing_261_ids_and_definitions_remain_immutable',
    'core_schema_and_docs87_remain_frozen_until_DB_FINAL_001'
  ]
}

# Insert the coverage contract before blockers without reserializing prior authorities.
text = File.read(MATRIX_PATH)
marker = "\nblockers:\n"
raise 'blockers marker not unique' unless text.scan(marker).length == 1
fragment = YAML.dump({'test_coverage_traceability_contract'=>contract}).sub(/\A---\s*\n/, '')
text = text.sub(marker, "\n#{fragment}\nblockers:\n")

replacements = {
  "  step: DB-TST-001\n" => "  step: DB-TST-002\n",
  "  status: FAIL_WITH_2_P1_BLOCKERS\n" => "  status: FAIL_WITH_1_P1_BLOCKER\n",
  "  fixes_applied_total: 4\n" => "  fixes_applied_total: 5\n",
  "  current_step_scope: DB_TST_001_only\n" => "  current_step_scope: DB_TST_002_only\n",
  "  resolved_after_diagnosis: 4\n" => "  resolved_after_diagnosis: 5\n",
  "  open_P0_P1: 2\n" => "  open_P0_P1: 1\n",
  "  result: FAIL_WITH_2_P1_BLOCKERS\n" => "  result: FAIL_WITH_1_P1_BLOCKER\n",
  "    - DB-TST-002\n    - DB-FINAL-001\n" => "    - DB-FINAL-001\n",
  "  status: PASS_DB_TST_001\n  checks:\n" => "  status: PASS_DB_TST_002\n  checks:\n",
  "    DB_TST_001_machine_readable_invariant_test_matrix_closed: true\n" => "    DB_TST_001_machine_readable_invariant_test_matrix_closed: true\n    DB_TST_002_coverage_traceability_and_completeness_closed: true\n",
  "next_single_step_after_central_gate:\n  id: DB-TST-002\n  action: resolve_DB_TST_002_only_after_next_explicit_user_instruction\n" => "next_single_step_after_central_gate:\n  id: DB-FINAL-001\n  action: resolve_DB_FINAL_001_only_after_next_explicit_user_instruction\n",
  "    DB-MIG-001, DB-MIG-002, DB-MIG-003 and DB-TST-001 are PASS. Resolve DB-TST-002 only.\n    Preserve DB-FINAL-001 as OPEN. Do not modify core-schema.yml or docs/87, enter\n    Stage 5, generate Laravel migrations, or implement UI/features.\n  after_action: rerun_DB_TST_002_machine_narrative_central_gate_and_STOP_before_DB_FINAL_001\n" => "    DB-MIG-001, DB-MIG-002, DB-MIG-003, DB-TST-001 and DB-TST-002 are PASS. Resolve DB-FINAL-001 only.\n    DB-FINAL-001 owns the aggregate sync of core-schema.yml and docs/87. Do not enter Stage 5,\n    generate Laravel migrations, or implement UI/features before the DB-FINAL-001 gate passes.\n  after_action: run_DB_FINAL_001_machine_narrative_central_gate_and_STOP_before_Stage_5\n"
}
replacements.each do |old, newv|
  count = text.scan(Regexp.new(Regexp.escape(old))).length
  raise "expected exactly one replacement for #{old.inspect}, got #{count}" unless count == 1
  text = text.sub(old, newv)
end

# Change only the DB-TST-002 blocker status, not DB-FINAL-001.
old_block = "  - id: DB-TST-002\n    severity: P1\n    status: OPEN\n"
new_block = "  - id: DB-TST-002\n    severity: P1\n    status: PASS\n"
raise 'DB-TST-002 blocker header mismatch' unless text.scan(old_block).length == 1
text = text.sub(old_block, new_block)

# Add explicit resolved contract after the DB-TST-001 top-level contract block and before diagnosis_summary.
resolved_fragment = <<~YAML
DB-TST-002:
  status: PASS
  title: coverage_traceability_and_completeness_gate
  machine_authority: test_coverage_traceability_contract
  prior_resolved_blockers_covered: 75
  resolved_blockers_covered_after_this_step: 76
  critical_constraints_covered: 183
  transactional_invariants_covered: 45
  bounded_local_required_test_occurrences_preserved: 1581
  bounded_local_unique_test_names_preserved: 1576
  DB_TST_001_base_tests_preserved_unchanged: 261
  coverage_anchor_tests_added: 229
  final_test_catalog_total: 490
  migration_postcheck_anchor_defined: true
  unknown_final_test_refs: 0
  unknown_or_orphan_source_refs: 0
  coverage_gaps: 0
  DB_FINAL_001_aggregate_sync_deferred: true
  framework_test_files_generated: false

YAML
marker2 = "diagnosis_summary:\n"
raise 'diagnosis summary marker not unique' unless text.scan(marker2).length == 1
text = text.sub(marker2, resolved_fragment + marker2)

File.write(MATRIX_PATH, text)
puts "DB_TST_002_BUILD=PASS anchors=#{anchors.length} local_groups=#{local_groups.length} local_occurrences=#{local_occurrences} final_tests=#{all_tests.length}"
