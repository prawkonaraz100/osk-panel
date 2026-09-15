#!/usr/bin/env bash
set -euo pipefail

base_url="${PRODUCTION_BASE_URL:-}"
if [[ -z "$base_url" ]]; then
  echo "PRODUCTION_BASE_URL is required." >&2
  exit 2
fi

base_url="${base_url%/}"
if [[ "$base_url" != https://* && "${ALLOW_INSECURE_SMOKE:-0}" != "1" ]]; then
  echo "Production smoke requires HTTPS. Set ALLOW_INSECURE_SMOKE=1 only for isolated non-production validation." >&2
  exit 2
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

check_endpoint() {
  local path="$1"
  local status
  status="$(curl --silent --show-error --location --output "$tmp" --write-out '%{http_code}' "$base_url$path")"
  if [[ "$status" != "200" ]]; then
    echo "$path returned HTTP $status" >&2
    cat "$tmp" >&2
    exit 1
  fi
  if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$tmp"; then
    echo "$path did not return status=ok" >&2
    cat "$tmp" >&2
    exit 1
  fi
}

check_endpoint /health/live
check_endpoint /health/ready

echo "PRODUCTION_SMOKE=PASS"
