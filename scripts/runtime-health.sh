#!/usr/bin/env bash
set -euo pipefail
docker compose exec -T postgres pg_isready -U osk_panel -d osk_panel >/dev/null
db="$(docker compose exec -T postgres psql -U osk_panel -d osk_panel -Atqc "select current_database()")"
test "$(printf '%s' "$db" | tr -d '\r')" = "osk_panel"
test "$(docker compose exec -T redis redis-cli ping | tr -d '\r')" = "PONG"
curl -fsS http://localhost:5000/moto-api/config >/dev/null
echo "S5_ENV_RUNTIME_HEALTH=PASS"
