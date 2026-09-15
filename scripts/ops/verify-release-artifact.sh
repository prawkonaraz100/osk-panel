#!/usr/bin/env bash
set -euo pipefail

expected_sha="${1:-}"
artifact="${2:-}"
checksum="${3:-}"
manifest="${4:-}"

if [[ ! "$expected_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Expected release SHA must be exactly 40 lowercase hex characters." >&2
  exit 2
fi

for path in "$artifact" "$checksum" "$manifest"; do
  if [[ -z "$path" || ! -f "$path" ]]; then
    echo "Release verification input is missing: $path" >&2
    exit 2
  fi
done

expected_name="osk-panel-${expected_sha}.tar.gz"
if [[ "$(basename "$artifact")" != "$expected_name" ]]; then
  echo "Release artifact name does not match the expected Git SHA." >&2
  exit 1
fi

actual_sha="$(sha256sum "$artifact" | awk '{print $1}')"
declared_sha="$(awk 'NR == 1 {print $1}' "$checksum")"

if [[ ! "$declared_sha" =~ ^[0-9a-f]{64}$ || "$actual_sha" != "$declared_sha" ]]; then
  echo "Release artifact checksum mismatch." >&2
  exit 1
fi

php -r '
$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($manifest["release_sha"] ?? null) !== $argv[2]
    || ($manifest["artifact"] ?? null) !== $argv[3]
    || ($manifest["sha256"] ?? null) !== $argv[4]
    || ($manifest["contains_env_file"] ?? null) !== false) {
    fwrite(STDERR, "Release manifest mismatch.\n");
    exit(1);
}
' "$manifest" "$expected_sha" "$expected_name" "$actual_sha"

if tar -tzf "$artifact" | grep -Eq '(^|/)\.env($|\.)'; then
  echo "Release artifact contains an environment file." >&2
  exit 1
fi

if tar -tzf "$artifact" | grep -Eq '^storage/(logs/[^.]|framework/(cache/data|sessions|views)/[^.])'; then
  echo "Release artifact contains runtime state that must not be promoted." >&2
  exit 1
fi

echo "RELEASE_ARTIFACT_VERIFY=PASS"
echo "release_sha=$expected_sha"
echo "sha256=$actual_sha"
