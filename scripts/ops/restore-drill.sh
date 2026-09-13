#!/usr/bin/env bash
set -euo pipefail

source_db="${RESTORE_DRILL_SOURCE_DB:-osk_panel}"
restore_db="${RESTORE_DRILL_TARGET_DB:-osk_panel_restore_drill}"
db_user="${RESTORE_DRILL_DB_USER:-osk_panel}"
report_path="${RESTORE_DRILL_REPORT_PATH:-/tmp/osk-panel-restore-drill-report.json}"
dump_file="$(mktemp /tmp/osk-panel-restore-drill.XXXXXX.dump)"
started_epoch="$(date +%s)"

cleanup() {
  docker compose exec -T postgres dropdb -U "$db_user" --if-exists "$restore_db" >/dev/null 2>&1 || true
  rm -f "$dump_file"
}
trap cleanup EXIT

if [[ "$source_db" == "$restore_db" ]]; then
  echo "Restore drill source and target databases must differ." >&2
  exit 1
fi

docker compose exec -T postgres pg_isready -U "$db_user" -d "$source_db" >/dev/null

source_table_count="$(
  docker compose exec -T postgres psql -U "$db_user" -d "$source_db" -Atqc     "select count(*) from pg_catalog.pg_tables where schemaname = 'public';" | tr -d '\r'
)"
if [[ ! "$source_table_count" =~ ^[0-9]+$ ]] || (( source_table_count < 1 )); then
  echo "Source database has no public tables." >&2
  exit 1
fi

schema_fingerprint() {
  local database="$1"
  docker compose exec -T postgres psql -U "$db_user" -d "$database" -Atqc     "select tablename from pg_catalog.pg_tables where schemaname = 'public' order by tablename;"     | tr -d '\r' | sha256sum | awk '{print $1}'
}

source_fingerprint="$(schema_fingerprint "$source_db")"

critical_tables=(
  organizations
  students
  course_enrollments
  training_hour_ledger_entries
  internal_exam_attempts
  license_inventory_entries
  student_payments
  orders
  audit_logs
  domain_events
)

for table in "${critical_tables[@]}"; do
  exists="$(docker compose exec -T postgres psql -U "$db_user" -d "$source_db" -Atqc     "select to_regclass('public.$table') is not null;" | tr -d '\r')"
  if [[ "$exists" != "t" ]]; then
    echo "Critical source table missing: $table" >&2
    exit 1
  fi
done

docker compose exec -T postgres pg_dump   -U "$db_user" -d "$source_db"   --format=custom --no-owner --no-acl > "$dump_file"

docker compose exec -T postgres dropdb -U "$db_user" --if-exists "$restore_db" >/dev/null
docker compose exec -T postgres createdb -U "$db_user" "$restore_db"
docker compose exec -T postgres pg_restore   -U "$db_user" -d "$restore_db"   --no-owner --no-privileges < "$dump_file"

restored_table_count="$(
  docker compose exec -T postgres psql -U "$db_user" -d "$restore_db" -Atqc     "select count(*) from pg_catalog.pg_tables where schemaname = 'public';" | tr -d '\r'
)"
restored_fingerprint="$(schema_fingerprint "$restore_db")"

if [[ "$source_table_count" != "$restored_table_count" ]]; then
  echo "Restored table count differs from source." >&2
  exit 1
fi
if [[ "$source_fingerprint" != "$restored_fingerprint" ]]; then
  echo "Restored schema fingerprint differs from source." >&2
  exit 1
fi

for table in "${critical_tables[@]}"; do
  source_count="$(docker compose exec -T postgres psql -U "$db_user" -d "$source_db" -Atqc "select count(*) from \"$table\";" | tr -d '\r')"
  restored_count="$(docker compose exec -T postgres psql -U "$db_user" -d "$restore_db" -Atqc "select count(*) from \"$table\";" | tr -d '\r')"
  if [[ "$source_count" != "$restored_count" ]]; then
    echo "Restored row count differs for $table." >&2
    exit 1
  fi
done

object_result="$(php scripts/ops/restore-drill-object.php)"

docker compose exec -T redis redis-cli FLUSHALL >/dev/null
redis_ping="$(docker compose exec -T redis redis-cli PING | tr -d '\r')"
if [[ "$redis_ping" != "PONG" ]]; then
  echo "Redis did not recover empty and healthy." >&2
  exit 1
fi

policy_version="$(
  php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("recovery.policy_version");'
)"

completed_epoch="$(date +%s)"
elapsed_seconds="$((completed_epoch - started_epoch))"
if (( elapsed_seconds > 3600 )); then
  echo "CI restore drill exceeded tier-0 RTO target." >&2
  exit 1
fi

printf '{"policy_version":"%s","environment_kind":"ci_emulated","production_target_evidence":false,"source_database":"%s","restored_database":"%s","source_table_count":%s,"restored_table_count":%s,"schema_fingerprint":"%s","critical_tables_checked":%s,"snapshot_rpo_seconds":0,"elapsed_seconds":%s,"redis_recovered_empty":true,"object_storage":%s}\n'   "$policy_version"   "$source_db"   "$restore_db"   "$source_table_count"   "$restored_table_count"   "$restored_fingerprint"   "${#critical_tables[@]}"   "$elapsed_seconds"   "$object_result"   > "$report_path"

cat "$report_path"
echo "RESTORE_DRILL_HARNESS=PASS"
