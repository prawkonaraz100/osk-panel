#!/usr/bin/env bash
set -euo pipefail
diagnose() {
  docker compose ps -a || true
  docker compose logs --no-color --tail=120 postgres redis moto || true
}
trap 'diagnose' ERR
docker compose up -d --wait --wait-timeout 90 postgres redis moto
./scripts/runtime-health.sh
